<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Anti-joins the registered set (akeneo_file_storage_file_info, storage=catalogStorage)
 * against the referenced-key index, writing orphans into a snapshot work table that the
 * purge phase consumes. Streaming by id keyset keeps memory flat at ~1M+ rows.
 */
class OrphanDetector
{
    public const TABLE = 'cld_fsc_orphan';

    public const STORAGE = 'catalogStorage';

    private const BATCH_SIZE = 5000;

    public function __construct(
        private readonly Connection $connection,
        private readonly FileKeyNormalizer $normalizer,
    ) {
    }

    public function recreateSnapshotTable(): void
    {
        $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
        $this->connection->executeStatement(sprintf(
            'CREATE TABLE %s (
                file_info_id INT NOT NULL,
                file_key VARBINARY(700) NOT NULL,
                bare_key VARBINARY(700) NOT NULL,
                size BIGINT UNSIGNED NULL,
                PRIMARY KEY (file_info_id),
                KEY idx_bare_key (bare_key)
            ) ENGINE=InnoDB',
            self::TABLE
        ));
    }

    public function snapshotExists(): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::TABLE]
        );
    }

    public function snapshotAgeHours(): ?float
    {
        $createTime = $this->connection->fetchOne(
            'SELECT CREATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::TABLE]
        );

        if (empty($createTime)) {
            return null;
        }

        return (time() - (new \DateTimeImmutable((string) $createTime))->getTimestamp()) / 3600;
    }

    /**
     * @param callable|null $progress called with rows processed so far
     *
     * @return array{
     *     registered: int,
     *     referenced_live: int,
     *     orphans: int,
     *     orphan_bytes: int,
     *     avatar_protected: int,
     *     non_standard: int,
     *     non_standard_samples: string[]
     * }
     */
    public function detect(?callable $progress = null): array
    {
        $this->recreateSnapshotTable();

        $avatarIds = $this->loadAvatarFileInfoIds();

        $stats = [
            'registered' => 0,
            'referenced_live' => 0,
            'orphans' => 0,
            'orphan_bytes' => 0,
            'avatar_protected' => 0,
            'non_standard' => 0,
            'non_standard_samples' => [],
        ];

        $lastId = 0;
        while (true) {
            $batch = $this->connection->fetchAllAssociative(
                'SELECT id, file_key, size FROM akeneo_file_storage_file_info
                 WHERE storage = ? AND id > ? ORDER BY id LIMIT ' . self::BATCH_SIZE,
                [self::STORAGE, $lastId]
            );

            if ([] === $batch) {
                break;
            }

            $candidates = [];
            foreach ($batch as $row) {
                $lastId = (int) $row['id'];
                ++$stats['registered'];

                if (isset($avatarIds[(int) $row['id']])) {
                    ++$stats['avatar_protected'];
                    continue;
                }

                $bareKey = $this->normalizer->bareKey((string) $row['file_key']);

                if (!$this->normalizer->isStandardKey($bareKey)) {
                    // Non-standard keys cannot be matched by the hex-anchored reference
                    // extraction, so classifying them would be guesswork: never delete.
                    ++$stats['non_standard'];
                    if (count($stats['non_standard_samples']) < 10) {
                        $stats['non_standard_samples'][] = (string) $row['file_key'];
                    }
                    continue;
                }

                $candidates[] = [
                    'id' => (int) $row['id'],
                    'file_key' => (string) $row['file_key'],
                    'bare_key' => $bareKey,
                    'size' => null === $row['size'] ? null : (int) $row['size'],
                ];
            }

            $this->classifyBatch($candidates, $stats);

            if (null !== $progress) {
                $progress($stats['registered']);
            }
        }

        return $stats;
    }

    public function orphanCount(): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', self::TABLE));
    }

    public function orphanBytes(): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COALESCE(SUM(size), 0) FROM %s', self::TABLE));
    }

    /** @return array<array{file_info_id: int, file_key: string, bare_key: string, size: int|null}> */
    public function sampleOrphans(int $count): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT file_info_id, file_key, bare_key, size FROM %s ORDER BY RAND() LIMIT %d', self::TABLE, $count)
        );

        return array_map(static fn (array $row) => [
            'file_info_id' => (int) $row['file_info_id'],
            'file_key' => (string) $row['file_key'],
            'bare_key' => (string) $row['bare_key'],
            'size' => null === $row['size'] ? null : (int) $row['size'],
        ], $rows);
    }

    public function isOrphan(string $bareKey): bool
    {
        return (bool) $this->connection->fetchOne(
            sprintf('SELECT 1 FROM %s WHERE bare_key = ?', self::TABLE),
            [$bareKey]
        );
    }

    /**
     * @param array<array{id: int, file_key: string, bare_key: string, size: int|null}> $candidates
     */
    private function classifyBatch(array $candidates, array &$stats): void
    {
        if ([] === $candidates) {
            return;
        }

        $bareKeys = array_column($candidates, 'bare_key');
        $placeholders = implode(',', array_fill(0, count($bareKeys), '?'));
        $referenced = array_flip($this->connection->fetchFirstColumn(
            sprintf('SELECT bare_key FROM %s WHERE bare_key IN (%s)', ReferencedKeyIndex::TABLE, $placeholders),
            $bareKeys
        ));

        $orphanValues = [];
        $orphanParams = [];
        foreach ($candidates as $candidate) {
            if (isset($referenced[$candidate['bare_key']])) {
                ++$stats['referenced_live'];
                continue;
            }

            ++$stats['orphans'];
            $stats['orphan_bytes'] += $candidate['size'] ?? 0;
            $orphanValues[] = '(?, ?, ?, ?)';
            array_push($orphanParams, $candidate['id'], $candidate['file_key'], $candidate['bare_key'], $candidate['size']);
        }

        if ([] !== $orphanValues) {
            $this->connection->executeStatement(
                sprintf(
                    'INSERT INTO %s (file_info_id, file_key, bare_key, size) VALUES %s',
                    self::TABLE,
                    implode(',', $orphanValues)
                ),
                $orphanParams
            );
        }
    }

    /** @return array<int, true> file_info ids referenced by user avatars (FK oro_user.file_info_id) */
    private function loadAvatarFileInfoIds(): array
    {
        $hasColumn = (bool) $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oro_user' AND COLUMN_NAME = 'file_info_id'"
        );

        if (!$hasColumn) {
            return [];
        }

        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT file_info_id FROM oro_user WHERE file_info_id IS NOT NULL'
        );

        return array_fill_keys(array_map('intval', $ids), true);
    }
}
