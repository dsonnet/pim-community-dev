<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\Updater\Comparator;

use Akeneo\Pim\Enrichment\Component\Product\Comparator\ComparatorInterface;

/**
 * Detects changes between a stored text-collection value and incoming data.
 * Order-insensitive: a reordering is not considered a change.
 */
class TextCollectionComparator implements ComparatorInterface
{
    /** @var string[] */
    private array $types;

    public function __construct(array $types)
    {
        $this->types = $types;
    }

    public function supports($type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function compare($data, $originals)
    {
        $default = ['locale' => null, 'scope' => null, 'data' => []];
        $originals = array_merge($default, $originals);

        if (!isset($data['data']) || null === $data['data']) {
            $data['data'] = [];
        }

        $new = $data['data'];
        $old = $originals['data'];

        if ([] === array_diff($new, $old) && [] === array_diff($old, $new)) {
            return null;
        }

        return $data;
    }
}
