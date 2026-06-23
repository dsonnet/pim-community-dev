<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Command;

use Akeneo\Tool\Component\FileStorage\FilesystemProvider;
use Cld\Bundle\FileStorageCleanupBundle\Service\FileKeyNormalizer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Captures a deterministic, machine-diffable snapshot of the catalogStorage media
 * referenced by one or more products: for every file key embedded in the product's
 * raw_values it records the akeneo_file_storage_file_info row (id, hash, size,
 * original_filename) and the on-disk identity (exists, size, sha1, inode), plus the
 * global catalog file_info row count.
 *
 * Purpose: verify the media-dedup fix on live products. Run it BEFORE updating a product
 * over the API and AFTER, then diff the two JSON outputs. A correctly de-duplicated
 * re-upload leaves every number identical — same key set, same file_info id, same inode,
 * and an unchanged global row count. A regression (image recreated) shows a NEW key for
 * the value, a NEW file_info id, a different inode, and global_file_info_rows + 1.
 *
 * Read-only: it never writes to the database or the filesystem.
 */
class MediaSnapshotCommand extends Command
{
    protected static $defaultName = 'pim:cld:file-storage:media-snapshot';

    private const STORAGE = 'catalogStorage';

    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemProvider $filesystemProvider,
        private readonly FileKeyNormalizer $normalizer,
        private readonly string $catalogStorageRoot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Snapshot the catalogStorage media referenced by given products (read-only, JSON out). Diff before/after an update to verify dedup.')
            ->addArgument('identifiers', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Product identifiers, e.g. DL4718 DL4670')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Write JSON to this file instead of stdout.')
            ->addOption('product-models', null, InputOption::VALUE_NONE, 'Treat the identifiers as product model codes instead of product identifiers.')
            ->addOption('no-sha1', null, InputOption::VALUE_NONE, 'Skip on-disk sha1 (faster; keeps existence, size and inode).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string[] $identifiers */
        $identifiers = $input->getArgument('identifiers');
        $isModel = (bool) $input->getOption('product-models');
        $withSha1 = !$input->getOption('no-sha1');

        $root = rtrim($this->catalogStorageRoot, '/');
        $filesystem = $this->filesystemProvider->getFilesystem(self::STORAGE);

        [$table, $idColumn, $valuesColumn] = $isModel
            ? ['pim_catalog_product_model', 'code', 'raw_values']
            : ['pim_catalog_product', 'identifier', 'raw_values'];

        $snapshot = [
            'storage' => self::STORAGE,
            'global' => [
                'file_info_rows_catalog' => (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM akeneo_file_storage_file_info WHERE storage = ?',
                    [self::STORAGE]
                ),
            ],
            'products' => [],
        ];

        foreach ($identifiers as $identifier) {
            $row = $this->connection->fetchAssociative(
                sprintf('SELECT id, %s AS raw FROM %s WHERE %s = ?', $valuesColumn, $table, $idColumn),
                [$identifier]
            );

            if (false === $row) {
                $snapshot['products'][$identifier] = ['found' => false];
                continue;
            }

            $extraction = $this->normalizer->extract((string) $row['raw']);
            $keys = [];
            foreach ($extraction['keys'] as $bareKey) {
                $keys[] = $this->describeKey($bareKey, $filesystem, $root, $withSha1);
            }

            $snapshot['products'][$identifier] = [
                'found' => true,
                'entity_id' => (int) $row['id'],
                'referenced_key_count' => count($keys),
                'extraction_warnings' => [
                    'loose_count' => $extraction['loose_count'],
                    'strict_count' => $extraction['strict_count'],
                    'suspicious_count' => $extraction['suspicious_count'],
                ],
                'keys' => $keys,
            ];
        }

        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $outPath = $input->getOption('out');
        if (null !== $outPath) {
            file_put_contents($outPath, $json);
            $output->writeln(sprintf('<info>Snapshot written to %s</info>', $outPath));
        } else {
            $output->write($json);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeKey(string $bareKey, object $filesystem, string $root, bool $withSha1): array
    {
        $info = $this->connection->fetchAssociative(
            'SELECT id, file_key, hash, size, original_filename
             FROM akeneo_file_storage_file_info
             WHERE storage = ? AND file_key LIKE ?
             ORDER BY id ASC LIMIT 1',
            [self::STORAGE, '%' . $bareKey]
        );

        $entry = [
            'bare_key' => $bareKey,
            'standard_key' => $this->normalizer->isStandardKey($bareKey),
            'file_info' => false === $info ? null : [
                'id' => (int) $info['id'],
                'file_key' => $info['file_key'],
                'hash' => $info['hash'],
                'size' => null === $info['size'] ? null : (int) $info['size'],
                'original_filename' => $info['original_filename'],
            ],
            'on_disk' => ['exists' => false],
        ];

        if (false === $info) {
            return $entry;
        }

        $fileKey = (string) $info['file_key'];
        $localPath = $root . '/' . $fileKey;

        if (is_file($localPath)) {
            $stat = @stat($localPath);
            $entry['on_disk'] = [
                'exists' => true,
                'size' => false === $stat ? null : (int) $stat['size'],
                'inode' => false === $stat ? null : (int) $stat['ino'],
                'links' => false === $stat ? null : (int) $stat['nlink'],
                'sha1' => $withSha1 ? (@sha1_file($localPath) ?: null) : null,
            ];

            return $entry;
        }

        // Non-local adapter (or root mismatch): fall back to the Flysystem API for
        // existence and size; inode/sha1 are local-only signals.
        try {
            if ($filesystem->fileExists($fileKey)) {
                $entry['on_disk'] = [
                    'exists' => true,
                    'size' => method_exists($filesystem, 'fileSize') ? (int) $filesystem->fileSize($fileKey) : null,
                    'inode' => null,
                    'links' => null,
                    'sha1' => null,
                ];
            }
        } catch (\Throwable $e) {
            $entry['on_disk'] = ['exists' => false, 'error' => $e->getMessage()];
        }

        return $entry;
    }
}
