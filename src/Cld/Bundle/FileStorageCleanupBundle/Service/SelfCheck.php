<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Pre-purge validation gate. Every check uses a method as independent as possible from
 * the detection pipeline, so a systematic detection bug cannot validate itself.
 * Any failure must abort the purge.
 */
class SelfCheck
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FileKeyNormalizer $normalizer,
        private readonly OrphanDetector $detector,
    ) {
    }

    /**
     * @param ReferenceSurface[] $surfaces
     * @param string[]           $assertReferenced operator-provided keys that MUST be classified live
     * @param string[]           $assertOrphan     operator-provided keys that MUST be classified orphan
     * @param array<array{pk: string, loose: int, strict: int, suspicious: int}> $incompleteRows
     *
     * @return array{failures: string[], warnings: string[]}
     */
    public function run(
        array $surfaces,
        array $assertReferenced,
        array $assertOrphan,
        array $incompleteRows,
        int $forwardSampleSize = 500,
        int $reverseSampleSize = 50
    ): array {
        $failures = [];
        $warnings = [];

        if ([] !== $incompleteRows) {
            $samples = array_map(
                static fn (array $r) => sprintf('pk=%s loose=%d strict=%d suspicious=%d', $r['pk'], $r['loose'], $r['strict'], $r['suspicious']),
                array_slice($incompleteRows, 0, 5)
            );
            $failures[] = sprintf(
                'Extraction completeness: %d row(s) contain key-like tokens the strict pattern could not fully capture (e.g. %s). Widen the pattern before purging.',
                count($incompleteRows),
                implode('; ', $samples)
            );
        }

        $referencedCount = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', ReferencedKeyIndex::TABLE));
        if (0 === $referencedCount) {
            $failures[] = 'Referenced-key index is empty: either this catalog has no media at all, or extraction is broken. Refusing to treat every file as an orphan.';
        }

        foreach ($this->forwardCheck($surfaces, $forwardSampleSize) as $failure) {
            $failures[] = $failure;
        }

        foreach ($this->reverseSpotCheck($surfaces, $reverseSampleSize) as $failure) {
            $failures[] = $failure;
        }

        foreach ($assertReferenced as $key) {
            $bare = $this->normalizer->bareKey($key);
            if ($this->detector->isOrphan($bare)) {
                $failures[] = sprintf('Fixture violation: key "%s" was asserted REFERENCED but is classified orphan.', $key);
            } elseif (!(bool) $this->connection->fetchOne(sprintf('SELECT 1 FROM %s WHERE bare_key = ?', ReferencedKeyIndex::TABLE), [$bare])) {
                $warnings[] = sprintf('Fixture: key "%s" asserted referenced is not orphaned, but also absent from the referenced index (not registered for catalogStorage?).', $key);
            }
        }

        foreach ($assertOrphan as $key) {
            if (!$this->detector->isOrphan($this->normalizer->bareKey($key))) {
                $failures[] = sprintf('Fixture violation: key "%s" was asserted ORPHAN but is not classified orphan.', $key);
            }
        }

        return ['failures' => $failures, 'warnings' => $warnings];
    }

    /**
     * Forward check: sample rows from each surface, re-extract their keys, and verify
     * none of them ended up in the orphan snapshot. Catches index-population bugs
     * (e.g. a surface that was silently skipped or truncated inserts).
     *
     * @param ReferenceSurface[] $surfaces
     *
     * @return string[] failures
     */
    private function forwardCheck(array $surfaces, int $sampleSize): array
    {
        $failures = [];
        $keysChecked = 0;

        foreach ($surfaces as $surface) {
            $rows = $this->connection->fetchFirstColumn(sprintf(
                'SELECT CAST(%s AS CHAR) FROM %s ORDER BY RAND() LIMIT %d',
                $this->connection->quoteIdentifier($surface->column),
                $this->connection->quoteIdentifier($surface->table),
                $sampleSize
            ));

            $bareKeys = [];
            foreach ($rows as $blob) {
                if (null === $blob || '' === $blob) {
                    continue;
                }
                foreach ($this->normalizer->extract($blob)['keys'] as $key) {
                    $bareKeys[$key] = true;
                }
            }

            if ([] === $bareKeys) {
                continue;
            }
            $keysChecked += count($bareKeys);

            foreach (array_chunk(array_keys($bareKeys), 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $hits = $this->connection->fetchFirstColumn(
                    sprintf('SELECT bare_key FROM %s WHERE bare_key IN (%s)', OrphanDetector::TABLE, $placeholders),
                    $chunk
                );
                if ([] !== $hits) {
                    $failures[] = sprintf(
                        'Forward check FAILED on %s: %d live key(s) classified as orphan, e.g. "%s".',
                        $surface->id(),
                        count($hits),
                        (string) $hits[0]
                    );
                }
            }
        }

        if (0 === $keysChecked && [] === $failures) {
            $failures[] = 'Forward check could not find any media key in the sampled surface rows; sample size too small or extraction broken. Refusing to purge without a positive control.';
        }

        return $failures;
    }

    /**
     * Reverse spot-check: take random orphans and search for their keys with plain SQL
     * LIKE — a completely independent mechanism from regex extraction. A single hit
     * means the detection pipeline misses references (e.g. key truncation) and NOTHING
     * may be deleted. One full scan per surface, all sampled keys checked at once.
     *
     * @param ReferenceSurface[] $surfaces
     *
     * @return string[] failures
     */
    private function reverseSpotCheck(array $surfaces, int $sampleSize): array
    {
        if ($sampleSize <= 0) {
            return ['Reverse spot-check is mandatory before a purge (sample size must be > 0).'];
        }

        $orphans = $this->detector->sampleOrphans($sampleSize);
        if ([] === $orphans) {
            return [];
        }

        $failures = [];
        foreach ($surfaces as $surface) {
            $conditions = [];
            $params = [];
            foreach ($orphans as $orphan) {
                $conditions[] = 'CAST(' . $this->connection->quoteIdentifier($surface->column) . " AS CHAR) LIKE ? ESCAPE '\\\\'";
                $params[] = '%' . addcslashes($orphan['bare_key'], '\\%_') . '%';
            }

            $sql = sprintf(
                'SELECT 1 FROM %s WHERE %s LIMIT 1',
                $this->connection->quoteIdentifier($surface->table),
                implode(' OR ', $conditions)
            );

            if ((bool) $this->connection->fetchOne($sql, $params)) {
                $failures[] = sprintf(
                    'Reverse spot-check FAILED: at least one sampled "orphan" key actually appears in %s. The referenced set is incomplete — DO NOT PURGE.',
                    $surface->id()
                );
            }
        }

        return $failures;
    }
}
