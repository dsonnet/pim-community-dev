<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Command;

use Cld\Bundle\FileStorageCleanupBundle\Service\OrphanDetector;
use Cld\Bundle\FileStorageCleanupBundle\Service\OrphanPurger;
use Cld\Bundle\FileStorageCleanupBundle\Service\ReferencedKeyIndex;
use Cld\Bundle\FileStorageCleanupBundle\Service\SelfCheck;
use Cld\Bundle\FileStorageCleanupBundle\Service\SurfaceProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Detects record-level orphans in catalogStorage (registered in
 * akeneo_file_storage_file_info but referenced by no product / product model /
 * other surface) and optionally quarantines or deletes them.
 *
 * DRY-RUN BY DEFAULT. Mutations require --force plus an explicit mode
 * (--quarantine=DIR or --delete), and only happen after the built-in
 * self-check gate passes.
 */
class PurgeOrphansCommand extends Command
{
    protected static $defaultName = 'pim:cld:file-storage:purge-orphans';

    private const LOCK_NAME = 'cld_fsc_purge_orphans';

    public function __construct(
        private readonly Connection $connection,
        private readonly SurfaceProvider $surfaceProvider,
        private readonly ReferencedKeyIndex $referencedKeyIndex,
        private readonly OrphanDetector $detector,
        private readonly SelfCheck $selfCheck,
        private readonly OrphanPurger $purger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Detect (and optionally quarantine/delete) catalogStorage files referenced by no catalog record. Dry-run by default.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually mutate. Without it the command only detects and reports.')
            ->addOption('quarantine', null, InputOption::VALUE_REQUIRED, 'Move orphan files to this directory instead of deleting (reversible, recommended first pass).')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Permanently delete orphan files (only after a quarantine cycle has been validated).')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Files handled per batch during the purge phase.', '500')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of orphans to purge this run (0 = no limit).', '0')
            ->addOption('sleep-ms', null, InputOption::VALUE_REQUIRED, 'Pause between purge batches, to throttle I/O on a live system.', '0')
            ->addOption('reuse-snapshot', null, InputOption::VALUE_NONE, 'Reuse the orphan snapshot from a previous run instead of re-detecting.')
            ->addOption('max-snapshot-age', null, InputOption::VALUE_REQUIRED, 'Refuse to purge from a snapshot older than this many hours.', '24')
            ->addOption('protect-versions', null, InputOption::VALUE_NONE, 'Also treat keys present in pim_versioning_version snapshots/changesets as referenced (slow on large history).')
            ->addOption('extra-surface', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional reference surface to scan, as "table.column". Repeatable.', [])
            ->addOption('skip-table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Dismiss a table flagged by the surface audit as holding no live references. Repeatable.', [])
            ->addOption('assert-referenced', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Fixture: a file key that MUST be classified as referenced, else abort. Repeatable.', [])
            ->addOption('assert-orphan', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Fixture: a file key that MUST be classified as orphan, else abort. Repeatable.', [])
            ->addOption('forward-sample', null, InputOption::VALUE_REQUIRED, 'Rows sampled per surface for the forward self-check.', '500')
            ->addOption('spot-check', null, InputOption::VALUE_REQUIRED, 'Random orphans cross-checked with SQL LIKE in the reverse self-check.', '50')
            ->addOption('audit-sample', null, InputOption::VALUE_REQUIRED, 'Rows sampled per candidate column in the unscanned-surface audit.', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $force = (bool) $input->getOption('force');
        $quarantineDir = $input->getOption('quarantine');
        $delete = (bool) $input->getOption('delete');

        if ($force && null === $quarantineDir && !$delete) {
            $io->error('--force requires a mode: --quarantine=<dir> (recommended) or --delete.');

            return Command::FAILURE;
        }
        if (null !== $quarantineDir && $delete) {
            $io->error('--quarantine and --delete are mutually exclusive.');

            return Command::FAILURE;
        }
        if (!$force && (null !== $quarantineDir || $delete)) {
            $io->note('No --force given: running in DRY-RUN mode, nothing will be moved or deleted.');
        }

        if (!$this->acquireLock()) {
            $io->error('Another purge-orphans run holds the lock; aborting.');

            return Command::FAILURE;
        }

        try {
            return $this->doExecute($input, $io, $force, $quarantineDir, $delete);
        } finally {
            $this->releaseLock();
        }
    }

    private function doExecute(InputInterface $input, SymfonyStyle $io, bool $force, ?string $quarantineDir, bool $delete): int
    {
        $surfaces = $this->surfaceProvider->getSurfaces(
            (bool) $input->getOption('protect-versions'),
            (array) $input->getOption('extra-surface')
        );

        $io->title('Akeneo file_storage orphan cleanup (storage: catalogStorage)');
        $io->text('Reference surfaces: ' . implode(', ', array_map(static fn ($s) => $s->id(), $surfaces)));
        if (!$input->getOption('protect-versions')) {
            $io->text('<comment>Note: pim_versioning_version is NOT protected (historical snapshots may reference purged keys as plain text). Use --protect-versions to include it.</comment>');
        }

        $incompleteRows = [];

        if ($input->getOption('reuse-snapshot') && $this->detector->snapshotExists() && $this->referencedKeyIndex->exists()) {
            $age = $this->detector->snapshotAgeHours();
            $io->text(sprintf('Reusing existing orphan snapshot (%s old, %d orphans).', null === $age ? 'unknown age' : sprintf('%.1fh', $age), $this->detector->orphanCount()));
        } else {
            $incompleteRows = $this->buildReferencedIndex($io, $surfaces);
            $this->runDetection($io);
        }

        $orphanCount = $this->detector->orphanCount();
        $orphanBytes = $this->detector->orphanBytes();
        $io->section('Detection result');
        $io->definitionList(
            ['Referenced keys (index)' => number_format($this->referencedKeyIndex->count())],
            ['Orphans' => number_format($orphanCount)],
            ['Orphan bytes' => self::humanBytes($orphanBytes)],
        );

        foreach ($this->detector->sampleOrphans(5) as $sample) {
            $io->text('  sample orphan: ' . $sample['file_key']);
        }

        // ---- Validation gate ------------------------------------------------------
        $io->section('Self-check');

        $audit = $this->surfaceProvider->auditUnscannedSurfaces(
            $surfaces,
            (array) $input->getOption('skip-table'),
            max(1, (int) $input->getOption('audit-sample'))
        );

        $check = $this->selfCheck->run(
            $surfaces,
            (array) $input->getOption('assert-referenced'),
            (array) $input->getOption('assert-orphan'),
            $incompleteRows,
            max(1, (int) $input->getOption('forward-sample')),
            (int) $input->getOption('spot-check')
        );

        foreach ($audit as $finding) {
            $check['failures'][] = sprintf(
                'Surface audit: unscanned column %s.%s contains file-key-like tokens. Add it with --extra-surface=%s.%s or, after verifying it holds no live references, dismiss it with --skip-table=%s.',
                $finding['table'],
                $finding['column'],
                $finding['table'],
                $finding['column'],
                $finding['table']
            );
        }

        foreach ($check['warnings'] as $warning) {
            $io->warning($warning);
        }

        if ([] !== $check['failures']) {
            foreach ($check['failures'] as $failure) {
                $io->error($failure);
            }
            $io->error('Self-check FAILED — nothing was or will be deleted. Resolve the failures above before purging.');

            return Command::FAILURE;
        }

        $io->success('Self-check passed (forward sample, reverse LIKE spot-check, extraction completeness, surface audit, fixtures).');

        // ---- Purge ----------------------------------------------------------------
        if (!$force) {
            $io->note(sprintf(
                'DRY-RUN complete. %s orphans (%s) are ready to purge. Re-run with --force --quarantine=<dir> (recommended) or --force --delete. Snapshot the VM first.',
                number_format($orphanCount),
                self::humanBytes($orphanBytes)
            ));

            return Command::SUCCESS;
        }

        $age = $this->detector->snapshotAgeHours();
        $maxAge = (float) $input->getOption('max-snapshot-age');
        if (null !== $age && $age > $maxAge) {
            $io->error(sprintf('Orphan snapshot is %.1fh old (max %.1fh). Re-run detection (drop --reuse-snapshot) — references may have changed since.', $age, $maxAge));

            return Command::FAILURE;
        }

        $mode = $delete ? OrphanPurger::MODE_DELETE : OrphanPurger::MODE_QUARANTINE;
        $io->section(sprintf('Purging (%s)', $mode));

        $result = $this->purger->purge(
            $mode,
            $quarantineDir,
            max(1, (int) $input->getOption('batch-size')),
            max(0, (int) $input->getOption('limit')),
            max(0, (int) $input->getOption('sleep-ms')),
            function (int $processed, int $total) use ($io) {
                $io->text(sprintf('  %s / %s processed', number_format($processed), number_format($total)));
            }
        );

        $io->definitionList(
            ['Processed' => number_format($result['processed'])],
            ['Files quarantined' => number_format($result['files_quarantined'])],
            ['Files deleted' => number_format($result['files_deleted'])],
            ['Stale rows (file already missing)' => number_format($result['stale_rows'])],
            ['file_info rows deleted' => number_format($result['rows_deleted'])],
            ['Bytes reclaimed' => self::humanBytes($result['bytes_reclaimed'])],
            ['Manifest' => $result['manifest'] ?? 'n/a'],
        );

        if ([] !== $result['failures']) {
            $io->warning(sprintf('%d file(s) failed and were kept (rows preserved, will be re-flagged next run):', count($result['failures'])));
            foreach (array_slice($result['failures'], 0, 10) as $failure) {
                $io->text(sprintf('  %s — %s', $failure['file_key'], $failure['error']));
            }

            return Command::FAILURE;
        }

        $io->success('Purge completed.');

        return Command::SUCCESS;
    }

    /** @return array<array{pk: string, loose: int, strict: int, suspicious: int}> */
    private function buildReferencedIndex(SymfonyStyle $io, array $surfaces): array
    {
        $io->section('Building referenced-key index');
        $this->referencedKeyIndex->recreate();

        $incompleteRows = [];
        foreach ($surfaces as $surface) {
            $stats = $this->referencedKeyIndex->populateFromSurface($surface, function (int $rows) use ($io, $surface) {
                if (0 === $rows % 50000) {
                    $io->text(sprintf('  %s: %s rows scanned...', $surface->id(), number_format($rows)));
                }
            });
            $io->text(sprintf('  %s: %s rows, %s new keys', $surface->id(), number_format($stats['rows']), number_format($stats['keys'])));
            $incompleteRows = array_merge($incompleteRows, $stats['incomplete_rows']);
        }

        $avatarKeys = $this->loadAvatarFileKeys();
        if ([] !== $avatarKeys) {
            $added = $this->referencedKeyIndex->addKeys($avatarKeys);
            $io->text(sprintf('  user avatars: %d keys protected (%d new)', count($avatarKeys), $added));
        }

        return $incompleteRows;
    }

    private function runDetection(SymfonyStyle $io): void
    {
        $io->section('Detecting orphans');
        $stats = $this->detector->detect(function (int $rows) use ($io) {
            if (0 === $rows % 100000) {
                $io->text(sprintf('  %s registered files classified...', number_format($rows)));
            }
        });

        $io->definitionList(
            ['Registered (catalogStorage)' => number_format($stats['registered'])],
            ['Referenced (live)' => number_format($stats['referenced_live'])],
            ['Orphans' => number_format($stats['orphans'])],
            ['Avatar-protected' => number_format($stats['avatar_protected'])],
            ['Non-standard keys (never auto-deleted)' => number_format($stats['non_standard'])],
        );

        foreach ($stats['non_standard_samples'] as $sample) {
            $io->text('  non-standard key sample: ' . $sample);
        }
    }

    /** @return string[] */
    private function loadAvatarFileKeys(): array
    {
        $hasColumn = (bool) $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oro_user' AND COLUMN_NAME = 'file_info_id'"
        );

        if (!$hasColumn) {
            return [];
        }

        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT fi.file_key FROM oro_user u
             JOIN akeneo_file_storage_file_info fi ON fi.id = u.file_info_id'
        ));
    }

    private function acquireLock(): bool
    {
        return '1' === (string) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK_NAME]);
    }

    private function releaseLock(): void
    {
        $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return sprintf('%.2f %s', $value, $units[$unit]);
    }
}
