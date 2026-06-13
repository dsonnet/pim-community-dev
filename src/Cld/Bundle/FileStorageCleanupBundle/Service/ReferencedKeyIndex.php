<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Builds the set of referenced bare file keys into a dedicated work table, so the
 * orphan anti-join never runs correlated LIKE subqueries against 1M+ rows.
 *
 * The work table is a real InnoDB table (not TEMPORARY) so it survives reconnects,
 * can be inspected by the operator, and can be reused by a later purge run.
 */
class ReferencedKeyIndex
{
    public const TABLE = 'cld_fsc_referenced_key';

    private const INSERT_CHUNK = 1000;

    public function __construct(
        private readonly Connection $connection,
        private readonly FileKeyNormalizer $normalizer,
    ) {
    }

    public function recreate(): void
    {
        $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
        $this->connection->executeStatement(sprintf(
            'CREATE TABLE %s (
                bare_key VARBINARY(700) NOT NULL,
                PRIMARY KEY (bare_key)
            ) ENGINE=InnoDB',
            self::TABLE
        ));
    }

    public function exists(): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::TABLE]
        );
    }

    public function count(): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', self::TABLE));
    }

    /**
     * Stream one surface and insert every extracted bare key.
     *
     * @param callable|null $progress called with the number of rows processed so far
     *
     * @return array{rows: int, keys: int, incomplete_rows: array<array{pk: string, loose: int, strict: int, suspicious: int}>}
     *         incomplete_rows lists rows where extraction could not capture every
     *         key-like token — a non-empty list must block any purge.
     */
    public function populateFromSurface(ReferenceSurface $surface, ?callable $progress = null): array
    {
        [$pkColumn, $useKeyset] = $this->resolvePrimaryKey($surface->table);

        $quotedTable = $this->connection->quoteIdentifier($surface->table);
        $quotedColumn = $this->connection->quoteIdentifier($surface->column);
        $quotedPk = $this->connection->quoteIdentifier($pkColumn);

        $rows = 0;
        $keysInserted = 0;
        $incomplete = [];
        $buffer = [];
        $lastPk = null;
        $offset = 0;
        $batchSize = 500;

        while (true) {
            if ($useKeyset) {
                $sql = sprintf(
                    'SELECT %s AS pk, CAST(%s AS CHAR) AS blob_value FROM %s %s ORDER BY %s LIMIT %d',
                    $quotedPk,
                    $quotedColumn,
                    $quotedTable,
                    null === $lastPk ? '' : sprintf('WHERE %s > ?', $quotedPk),
                    $quotedPk,
                    $batchSize
                );
                $batch = $this->connection->fetchAllAssociative($sql, null === $lastPk ? [] : [$lastPk]);
            } else {
                $sql = sprintf(
                    'SELECT %s AS pk, CAST(%s AS CHAR) AS blob_value FROM %s ORDER BY %s LIMIT %d OFFSET %d',
                    $quotedPk,
                    $quotedColumn,
                    $quotedTable,
                    $quotedPk,
                    $batchSize,
                    $offset
                );
                $batch = $this->connection->fetchAllAssociative($sql);
                $offset += $batchSize;
            }

            if ([] === $batch) {
                break;
            }

            foreach ($batch as $row) {
                ++$rows;
                $lastPk = $row['pk'];

                if (null === $row['blob_value'] || '' === $row['blob_value']) {
                    continue;
                }

                $extraction = $this->normalizer->extract($row['blob_value']);

                if ($extraction['loose_count'] > $extraction['strict_count'] || $extraction['suspicious_count'] > 0) {
                    if (count($incomplete) < 20) {
                        $incomplete[] = [
                            'pk' => is_string($row['pk']) ? bin2hex($row['pk']) : (string) $row['pk'],
                            'loose' => $extraction['loose_count'],
                            'strict' => $extraction['strict_count'],
                            'suspicious' => $extraction['suspicious_count'],
                        ];
                    }
                }

                foreach ($extraction['keys'] as $bareKey) {
                    $buffer[$bareKey] = true;
                    if (count($buffer) >= self::INSERT_CHUNK) {
                        $keysInserted += $this->flush($buffer);
                        $buffer = [];
                    }
                }
            }

            if (null !== $progress) {
                $progress($rows);
            }
        }

        $keysInserted += $this->flush($buffer);

        return ['rows' => $rows, 'keys' => $keysInserted, 'incomplete_rows' => $incomplete];
    }

    /**
     * Protect keys that are referenced outside value JSON (e.g. user avatar file keys).
     *
     * @param string[] $keys bare or prefixed keys
     */
    public function addKeys(array $keys): int
    {
        $buffer = [];
        foreach ($keys as $key) {
            $buffer[$this->normalizer->bareKey($key)] = true;
        }

        return $this->flush($buffer);
    }

    /** @param array<string, true> $bareKeys */
    private function flush(array $bareKeys): int
    {
        if ([] === $bareKeys) {
            return 0;
        }

        $inserted = 0;
        foreach (array_chunk(array_keys($bareKeys), self::INSERT_CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?)'));
            $inserted += (int) $this->connection->executeStatement(
                sprintf('INSERT IGNORE INTO %s (bare_key) VALUES %s', self::TABLE, $placeholders),
                $chunk
            );
        }

        return $inserted;
    }

    /**
     * @return array{0: string, 1: bool} primary key column and whether keyset pagination is usable
     */
    private function resolvePrimaryKey(string $table): array
    {
        $pkColumns = $this->connection->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_KEY = 'PRI'
             ORDER BY ORDINAL_POSITION",
            [$table]
        );

        if ([] === $pkColumns) {
            throw new \RuntimeException(sprintf('Table "%s" has no primary key; cannot stream it safely.', $table));
        }

        // Keyset pagination needs a single-column PK; composite PKs fall back to
        // LIMIT/OFFSET which is acceptable for moderately sized tables.
        return [$pkColumns[0], 1 === count($pkColumns)];
    }
}
