<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Resolves the list of reference surfaces to scan, adapting to the schema actually
 * present on the install (tables/columns differ across CE versions and extensions).
 */
class SurfaceProvider
{
    /** Surfaces always scanned when the table+column exists. */
    private const DEFAULT_SURFACES = [
        ['pim_catalog_product', 'raw_values', 'product values'],
        ['pim_catalog_product_model', 'raw_values', 'product model values'],
        // categoryStorage keys normally — included defensively, protecting extra keys is harmless
        ['pim_catalog_category', 'value_collection', 'category enrichment values'],
    ];

    /** Versioning snapshots hold historical keys; opt-in because the table can be huge. */
    private const VERSIONING_SURFACES = [
        ['pim_versioning_version', 'snapshot', 'version snapshots'],
        ['pim_versioning_version', 'changeset', 'version changesets'],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param string[] $extraSurfaces additional "table.column" entries requested by the operator
     *
     * @return ReferenceSurface[]
     */
    public function getSurfaces(bool $protectVersions, array $extraSurfaces): array
    {
        $surfaces = [];

        foreach (self::DEFAULT_SURFACES as [$table, $column, $label]) {
            if ($this->columnExists($table, $column)) {
                $surfaces[] = new ReferenceSurface($table, $column, $label);
            }
        }

        if ($protectVersions) {
            foreach (self::VERSIONING_SURFACES as [$table, $column, $label]) {
                if ($this->columnExists($table, $column)) {
                    $surfaces[] = new ReferenceSurface($table, $column, $label);
                }
            }
        }

        foreach ($extraSurfaces as $extra) {
            if (1 !== preg_match('/^(\w+)\.(\w+)$/', $extra, $m)) {
                throw new \InvalidArgumentException(sprintf('Invalid --extra-surface "%s", expected "table.column".', $extra));
            }
            if (!$this->columnExists($m[1], $m[2])) {
                throw new \InvalidArgumentException(sprintf('--extra-surface "%s" does not exist in this database.', $extra));
            }
            $surface = new ReferenceSurface($m[1], $m[2], 'extra surface ' . $extra);
            $surfaces[$surface->id()] = $surface;
        }

        $unique = [];
        foreach ($surfaces as $surface) {
            $unique[$surface->id()] = $surface;
        }

        return array_values($unique);
    }

    public function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    /**
     * Audit the whole schema for text/JSON columns that contain key-like tokens but are
     * not part of the scanned surfaces. Each finding either holds live references (a
     * missed surface → live files would be wrongly purged) or stale ones; the operator
     * must explicitly add it (--extra-surface) or dismiss it (--skip-table).
     *
     * @param ReferenceSurface[] $scanned
     * @param string[]           $skipTables
     *
     * @return array<array{table: string, column: string}>
     */
    public function auditUnscannedSurfaces(array $scanned, array $skipTables, int $sampleRows = 1000): array
    {
        $scannedIds = array_map(static fn (ReferenceSurface $s) => $s->id(), $scanned);

        // Versioning is warn-only by default (historical snapshots), our own work tables
        // and the file registry itself are not references.
        $alwaysSkip = array_merge($skipTables, [
            'akeneo_file_storage_file_info',
            'pim_versioning_version',
        ]);

        $columns = $this->connection->fetchAllAssociative(
            "SELECT col.TABLE_NAME AS t, col.COLUMN_NAME AS c
             FROM information_schema.COLUMNS col
             JOIN information_schema.TABLES tbl
               ON tbl.TABLE_SCHEMA = col.TABLE_SCHEMA AND tbl.TABLE_NAME = col.TABLE_NAME
             WHERE col.TABLE_SCHEMA = DATABASE()
               AND tbl.TABLE_TYPE = 'BASE TABLE'
               AND col.DATA_TYPE IN ('json', 'longtext', 'mediumtext', 'text', 'varchar')
               AND (col.CHARACTER_MAXIMUM_LENGTH IS NULL OR col.CHARACTER_MAXIMUM_LENGTH >= 45)
               AND col.TABLE_NAME NOT LIKE 'cld\\_fsc\\_%'"
        );

        $findings = [];
        foreach ($columns as $col) {
            $id = $col['t'] . '.' . $col['c'];
            if (in_array($id, $scannedIds, true) || in_array($col['t'], $alwaysSkip, true)) {
                continue;
            }

            $sql = sprintf(
                "SELECT EXISTS(
                    SELECT 1 FROM (SELECT %s AS v FROM %s LIMIT %d) sample
                    WHERE CAST(sample.v AS CHAR) REGEXP '[0-9a-f]{40}_'
                )",
                $this->connection->quoteIdentifier($col['c']),
                $this->connection->quoteIdentifier($col['t']),
                $sampleRows
            );

            if ((bool) $this->connection->fetchOne($sql)) {
                $findings[] = ['table' => $col['t'], 'column' => $col['c']];
            }
        }

        return $findings;
    }
}
