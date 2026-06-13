<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Service;

/**
 * A database column that can embed catalogStorage file keys and therefore counts
 * as a "reference" keeping a file alive.
 */
class ReferenceSurface
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly string $label,
    ) {
    }

    public function id(): string
    {
        return $this->table . '.' . $this->column;
    }
}
