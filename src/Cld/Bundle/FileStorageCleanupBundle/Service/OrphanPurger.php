<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Service;

use Akeneo\Tool\Component\FileStorage\FilesystemProvider;
use Doctrine\DBAL\Connection;
use League\Flysystem\UnableToDeleteFile;

/**
 * Consumes the orphan snapshot table: quarantines (moves) or deletes the physical file,
 * then deletes the akeneo_file_storage_file_info row, batched and throttleable.
 *
 * Rows are deleted with direct batched SQL rather than the akeneo_file_storage.remover
 * service: the FileStorageBundle registers no PRE/POST_REMOVE listener for FileInfo,
 * the table is not indexed in Elasticsearch and not cached, and entity-by-entity
 * removal of ~1M rows through the ORM would be prohibitively slow. The only FK into
 * the table (oro_user.file_info_id, avatars) is excluded at detection time and would
 * additionally be enforced by the database.
 *
 * Ordering inside a batch: the file is moved/deleted first, then the row. A failed file
 * operation keeps the row (the orphan is re-flagged on the next run); a row pointing to
 * an already-missing file is a stale registration and is removed and counted as such.
 */
class OrphanPurger
{
    public const MODE_QUARANTINE = 'quarantine';
    public const MODE_DELETE = 'delete';

    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemProvider $filesystemProvider,
        private readonly string $catalogStorageRoot,
    ) {
    }

    /**
     * @param callable|null $progress called with (processed, total)
     *
     * @return array{
     *     processed: int,
     *     files_quarantined: int,
     *     files_deleted: int,
     *     stale_rows: int,
     *     rows_deleted: int,
     *     bytes_reclaimed: int,
     *     failures: array<array{file_key: string, error: string}>,
     *     manifest: ?string
     * }
     */
    public function purge(
        string $mode,
        ?string $quarantineDir,
        int $batchSize = 500,
        int $limit = 0,
        int $sleepMs = 0,
        ?callable $progress = null
    ): array {
        if (!in_array($mode, [self::MODE_QUARANTINE, self::MODE_DELETE], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown purge mode "%s".', $mode));
        }

        $manifestPath = null;
        $manifestHandle = null;
        if (self::MODE_QUARANTINE === $mode) {
            if (null === $quarantineDir || '' === $quarantineDir) {
                throw new \InvalidArgumentException('Quarantine mode requires a target directory.');
            }
            if (!is_dir($this->catalogStorageRoot)) {
                throw new \RuntimeException(sprintf(
                    'Catalog storage root "%s" is not a local directory; quarantine only supports the local filesystem adapter.',
                    $this->catalogStorageRoot
                ));
            }
            if (!is_dir($quarantineDir) && !mkdir($quarantineDir, 0775, true) && !is_dir($quarantineDir)) {
                throw new \RuntimeException(sprintf('Cannot create quarantine directory "%s".', $quarantineDir));
            }

            $manifestPath = rtrim($quarantineDir, '/') . '/manifest-' . date('Ymd-His') . '.jsonl';
            $manifestHandle = fopen($manifestPath, 'ab');
            if (false === $manifestHandle) {
                throw new \RuntimeException(sprintf('Cannot open manifest file "%s".', $manifestPath));
            }
        }

        $filesystem = $this->filesystemProvider->getFilesystem(OrphanDetector::STORAGE);
        $total = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', OrphanDetector::TABLE));

        $result = [
            'processed' => 0,
            'files_quarantined' => 0,
            'files_deleted' => 0,
            'stale_rows' => 0,
            'rows_deleted' => 0,
            'bytes_reclaimed' => 0,
            'failures' => [],
            'manifest' => $manifestPath,
        ];

        try {
            $lastId = 0;
            while (0 === $limit || $result['processed'] < $limit) {
                $currentBatchSize = (0 === $limit) ? $batchSize : min($batchSize, $limit - $result['processed']);
                $batch = $this->connection->fetchAllAssociative(
                    sprintf(
                        'SELECT o.file_info_id, o.file_key, o.size,
                                fi.original_filename, fi.mime_type, fi.extension, fi.hash
                         FROM %s o
                         JOIN akeneo_file_storage_file_info fi ON fi.id = o.file_info_id
                         WHERE o.file_info_id > ? ORDER BY o.file_info_id LIMIT %d',
                        OrphanDetector::TABLE,
                        $currentBatchSize
                    ),
                    [$lastId]
                );

                if ([] === $batch) {
                    break;
                }

                $succeededIds = [];
                foreach ($batch as $row) {
                    $lastId = (int) $row['file_info_id'];
                    ++$result['processed'];
                    $fileKey = (string) $row['file_key'];

                    try {
                        if (self::MODE_QUARANTINE === $mode) {
                            $outcome = $this->quarantineFile($fileKey, (string) $quarantineDir);
                            if ('moved' === $outcome) {
                                ++$result['files_quarantined'];
                            } else {
                                ++$result['stale_rows'];
                            }
                            $this->appendManifestLine($manifestHandle, $row, $outcome, (string) $quarantineDir);
                        } else {
                            if ($filesystem->fileExists($fileKey)) {
                                $filesystem->delete($fileKey);
                                ++$result['files_deleted'];
                            } else {
                                ++$result['stale_rows'];
                            }
                        }

                        $succeededIds[] = (int) $row['file_info_id'];
                        $result['bytes_reclaimed'] += (int) ($row['size'] ?? 0);
                    } catch (UnableToDeleteFile | \RuntimeException $e) {
                        $result['failures'][] = ['file_key' => $fileKey, 'error' => $e->getMessage()];
                    }
                }

                if (null !== $manifestHandle) {
                    fflush($manifestHandle);
                }

                $this->deleteRows($succeededIds);
                $result['rows_deleted'] += count($succeededIds);

                if (null !== $progress) {
                    $progress($result['processed'], $total);
                }

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        } finally {
            if (null !== $manifestHandle) {
                fclose($manifestHandle);
            }
        }

        return $result;
    }

    /**
     * @return string 'moved' or 'missing'
     */
    private function quarantineFile(string $fileKey, string $quarantineDir): string
    {
        $source = rtrim($this->catalogStorageRoot, '/') . '/' . $fileKey;
        if (!is_file($source)) {
            return 'missing';
        }

        $target = rtrim($quarantineDir, '/') . '/' . $fileKey;
        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(sprintf('Cannot create quarantine subdirectory "%s".', $targetDir));
        }

        // rename() is atomic on the same filesystem and falls back to copy+unlink
        // across devices; either way the source no longer exists afterwards.
        if (!@rename($source, $target)) {
            $error = error_get_last();
            throw new \RuntimeException(sprintf(
                'Failed to move "%s" to quarantine: %s',
                $fileKey,
                $error['message'] ?? 'unknown error'
            ));
        }

        return 'moved';
    }

    /**
     * @param resource $handle
     * @param array<string, mixed> $row
     */
    private function appendManifestLine($handle, array $row, string $outcome, string $quarantineDir): void
    {
        $line = json_encode([
            'file_info_id' => (int) $row['file_info_id'],
            'file_key' => (string) $row['file_key'],
            'original_filename' => $row['original_filename'],
            'mime_type' => $row['mime_type'],
            'size' => null === $row['size'] ? null : (int) $row['size'],
            'extension' => $row['extension'],
            'hash' => $row['hash'],
            'storage' => OrphanDetector::STORAGE,
            'outcome' => $outcome,
            'quarantine_dir' => $quarantineDir,
            'quarantined_at' => date(\DateTimeInterface::ATOM),
        ], JSON_UNESCAPED_SLASHES);

        if (false === fwrite($handle, $line . "\n")) {
            throw new \RuntimeException('Failed to write to the quarantine manifest; aborting to keep the manifest complete.');
        }
    }

    /** @param int[] $ids */
    private function deleteRows(array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                sprintf('DELETE FROM akeneo_file_storage_file_info WHERE id IN (%s)', $placeholders),
                $ids
            );
            $this->connection->executeStatement(
                sprintf('DELETE FROM %s WHERE file_info_id IN (%s)', OrphanDetector::TABLE, $placeholders),
                $ids
            );
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
