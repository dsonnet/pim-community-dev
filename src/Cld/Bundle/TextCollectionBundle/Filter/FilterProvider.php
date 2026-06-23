<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\Filter;

use Akeneo\Pim\Structure\Component\Model\AttributeInterface;
use Akeneo\Platform\Bundle\UIBundle\Provider\Filter\FilterProviderInterface;
use Cld\Bundle\TextCollectionBundle\AttributeType\TextCollectionTypes;

/**
 * Provides the export-builder filter UI module for text-collection attributes.
 */
class FilterProvider implements FilterProviderInterface
{
    /** @var array<string, array<string, string>> */
    private array $filters = [
        TextCollectionTypes::TEXT_COLLECTION => [
            'product-export-builder' => 'cld-attribute-text-collection-filter',
        ],
    ];

    public function getFilters($attribute)
    {
        return $this->filters[$attribute->getAttributeType()];
    }

    public function supports($element): bool
    {
        return $element instanceof AttributeInterface
            && in_array($element->getType(), array_keys($this->filters), true);
    }
}
