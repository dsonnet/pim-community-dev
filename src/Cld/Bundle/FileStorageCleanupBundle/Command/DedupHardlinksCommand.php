<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Collapses byte-identical catalogStorage files registered under different keys into a
 * single inode via hardlinks. Every file_key keeps existing — nothing is deleted and
 * no database row changes; only redundant data blocks are reclaimed.
 *
 * Duplicate candidates are grouped by the content sha1 Akeneo already stores in
 * akeneo_file_storage_file_info.hash (computed by sha1_file at upload time), then
 * re-verified against the actual bytes before linking, unless --trust-db-hash.
 *
 * Run AFTER the orphan purge: linking orphans first would waste effort and hide the
 * real reclaimable number. Local filesystem adapter only; both paths must live on the
 * same filesystem (guaranteed within one storage tree, asserted via st_dev anyway).
 */
class DedupHardlinksCommand extends Command
{
    protected static $defaultName = 'pim:cld:file-storage:dedup-hardlinks';

    private const STORAGE = 'catalogStorage';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $catalogStorageRoot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Hardlink byte-identical catalogStorage files registered under different keys. Dry-run by default.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually create hardlinks; without it, only report.')
            ->addOption('min-size', null, InputOption::VALUE_REQUIRED, 'Ignore files smaller than this many bytes.', '4096')
            ->addOption('limit-groups', null, InputOption::VALUE_REQUIRED, 'Stop after this many duplicate groups (0 = all).', '0')
            ->addOption('trust-db-hash', null, InputOption::VALUE_NONE, 'Skip re-hashing file content and trust the stored sha1 (faster, slightly less safe).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $force = (bool) $input->getOption('force');
        $minSize = max(0, (int) $input->getOption('min-size'));
        $limitGroups = max(0, (int) $input->getOption('limit-groups'));
        $trustDbHash = (bool) $input->getOption('trust-db-hash');

        $root = rtrim($this->catalogStorageRoot, '/');
        if (!is_dir($root)) {
            $io->error(sprintf('Catalog storage root "%s" is not a local directory; hardlink dedup only supports the local adapter.', $root));

            return Command::FAILURE;
        }

        if (!$force) {
            $io->note('DRY-RUN: counting reclaimable bytes, no link will be created.');
        }

        $io->title('Hardlink dedup of byte-identical catalogStorage files');

        $stats = [
            'groups' => 0,
            'linked' => 0,
            'already_linked' => 0,
            'bytes_reclaimable' => 0,
            'bytes_reclaimed' => 0,
            'mismatches' => 0,
            'missing' => 0,
        ];

        $lastHash = '';
        while (true) {
            $groups = $this->connection->fetchAllAssociative(
                'SELECT hash, size, COUNT(*) AS copies
                 FROM akeneo_file_storage_file_info
                 WHERE storage = ? AND hash IS NOT NULL AND hash > ? AND size >= ?
                 GROUP BY hash, size
                 HAVING copies > 1
                 ORDER BY hash
                 LIMIT 500',
                [self::STORAGE, $lastHash, $minSize]
            );

            if ([] === $groups) {
                break;
            }

            foreach ($groups as $group) {
                $lastHash = (string) $group['hash'];

                if ($limitGroups > 0 && $stats['groups'] >= $limitGroups) {
                    break 2;
                }
                ++$stats['groups'];

                $keys = $this->connection->fetchFirstColumn(
                    'SELECT file_key FROM akeneo_file_storage_file_info
                     WHERE storage = ? AND hash = ? AND size = ? ORDER BY id',
                    [self::STORAGE, $group['hash'], $group['size']]
                );

                $this->processGroup($io, $keys, (string) $group['hash'], $root, $force, $trustDbHash, $stats);
            }

            if (0 === $stats['groups'] % 5000) {
                $io->text(sprintf('  %s groups processed...', number_format($stats['groups'])));
            }
        }

        $io->definitionList(
            ['Duplicate groups' => number_format($stats['groups'])],
            ['Files newly hardlinked' => number_format($stats['linked'])],
            ['Already hardlinked' => number_format($stats['already_linked'])],
            ['Missing on disk' => number_format($stats['missing'])],
            ['Content mismatches (skipped)' => number_format($stats['mismatches'])],
            [($force ? 'Bytes reclaimed' : 'Bytes reclaimable') => PurgeOrphansCommand::humanBytes($force ? $stats['bytes_reclaimed'] : $stats['bytes_reclaimable'])],
        );

        if ($stats['mismatches'] > 0) {
            $io->warning('Some files no longer match their registered content hash; they were skipped. Investigate before trusting --trust-db-hash.');
        }

        $io->success($force ? 'Dedup completed.' : 'Dry-run completed.');

        return Command::SUCCESS;
    }

    /**
     * @param string[]             $keys
     * @param array<string, int>   $stats
     */
    private function processGroup(SymfonyStyle $io, array $keys, string $expectedHash, string $root, bool $force, bool $trustDbHash, array &$stats): void
    {
        $canonicalPath = null;
        $canonicalStat = null;

        foreach ($keys as $key) {
            $path = $root . '/' . $key;
            $stat = @lstat($path);

            if (false === $stat || !is_file($path)) {
                ++$stats['missing'];
                continue;
            }

            if (null === $canonicalPath) {
                if (!$trustDbHash && @sha1_file($path) !== $expectedHash) {
                    ++$stats['mismatches'];
                    continue;
                }
                $canonicalPath = $path;
                $canonicalStat = $stat;
                continue;
            }

            if ($stat['dev'] !== $canonicalStat['dev']) {
                // Different filesystem: hardlink impossible.
                ++$stats['mismatches'];
                continue;
            }

            if ($stat['ino'] === $canonicalStat['ino']) {
                ++$stats['already_linked'];
                continue;
            }

            if (!$trustDbHash && @sha1_file($path) !== $expectedHash) {
                ++$stats['mismatches'];
                continue;
            }

            $stats['bytes_reclaimable'] += (int) $stat['size'];

            if (!$force) {
                ++$stats['linked'];
                continue;
            }

            // Atomic swap: link to a temp name in the same directory, then rename over
            // the duplicate. Readers never observe a missing file.
            $tmp = $path . '.cldtmp';
            if (!@link($canonicalPath, $tmp)) {
                @unlink($tmp);
                $io->warning(sprintf('link() failed for %s', $key));
                ++$stats['mismatches'];
                continue;
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                $io->warning(sprintf('rename() failed for %s', $key));
                ++$stats['mismatches'];
                continue;
            }

            ++$stats['linked'];
            $stats['bytes_reclaimed'] += (int) $stat['size'];
        }
    }
}
