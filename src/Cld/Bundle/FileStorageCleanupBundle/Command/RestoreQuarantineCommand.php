<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reverses a quarantine pass: moves files back into catalogStorage and re-registers
 * their akeneo_file_storage_file_info rows from the JSONL manifest written by
 * pim:cld:file-storage:purge-orphans.
 */
class RestoreQuarantineCommand extends Command
{
    protected static $defaultName = 'pim:cld:file-storage:restore-quarantine';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $catalogStorageRoot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Restore quarantined files and their file_info rows from a quarantine manifest.')
            ->addArgument('manifest', InputArgument::REQUIRED, 'Path to the manifest-*.jsonl file written during quarantine.')
            ->addOption('key-filter', null, InputOption::VALUE_REQUIRED, 'Only restore entries whose file_key contains this substring.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $manifestPath = (string) $input->getArgument('manifest');
        $keyFilter = $input->getOption('key-filter');

        if (!is_file($manifestPath)) {
            $io->error(sprintf('Manifest "%s" not found.', $manifestPath));

            return Command::FAILURE;
        }

        $handle = fopen($manifestPath, 'rb');
        if (false === $handle) {
            $io->error('Cannot open manifest.');

            return Command::FAILURE;
        }

        $restoredFiles = 0;
        $restoredRows = 0;
        $skipped = 0;
        $errors = 0;

        while (false !== ($line = fgets($handle))) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $entry = json_decode($line, true);
            if (!is_array($entry) || !isset($entry['file_key'])) {
                $io->warning('Skipping malformed manifest line.');
                ++$errors;
                continue;
            }

            $fileKey = (string) $entry['file_key'];
            if (null !== $keyFilter && !str_contains($fileKey, (string) $keyFilter)) {
                ++$skipped;
                continue;
            }

            if ('moved' === ($entry['outcome'] ?? null)) {
                $source = rtrim((string) $entry['quarantine_dir'], '/') . '/' . $fileKey;
                $target = rtrim($this->catalogStorageRoot, '/') . '/' . $fileKey;

                if (is_file($target)) {
                    // Already back in place (re-run); fall through to row restore.
                } elseif (!is_file($source)) {
                    $io->warning(sprintf('File missing from quarantine, row restored anyway: %s', $fileKey));
                    ++$errors;
                } else {
                    $targetDir = dirname($target);
                    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                        $io->error(sprintf('Cannot create directory "%s".', $targetDir));
                        ++$errors;
                        continue;
                    }
                    if (!@rename($source, $target)) {
                        $io->error(sprintf('Failed to move back: %s', $fileKey));
                        ++$errors;
                        continue;
                    }
                    ++$restoredFiles;
                }
            }

            // Re-insert without the original id: nothing references file_info by id
            // except user avatars, which are never purged. ON DUPLICATE makes re-runs
            // idempotent (file_key is unique).
            $restoredRows += (int) $this->connection->executeStatement(
                'INSERT INTO akeneo_file_storage_file_info
                    (file_key, original_filename, mime_type, size, extension, hash, storage)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE file_key = file_key',
                [
                    $fileKey,
                    $entry['original_filename'] ?? $fileKey,
                    $entry['mime_type'] ?? 'application/octet-stream',
                    $entry['size'] ?? null,
                    $entry['extension'] ?? null,
                    $entry['hash'] ?? null,
                    $entry['storage'] ?? 'catalogStorage',
                ]
            );
        }

        fclose($handle);

        $io->definitionList(
            ['Files moved back' => number_format($restoredFiles)],
            ['file_info rows restored' => number_format($restoredRows)],
            ['Skipped (filter)' => number_format($skipped)],
            ['Errors' => number_format($errors)],
        );

        if ($errors > 0) {
            $io->warning('Restore finished with errors, see above.');

            return Command::FAILURE;
        }

        $io->success('Restore completed.');

        return Command::SUCCESS;
    }
}
