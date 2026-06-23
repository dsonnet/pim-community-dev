<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\ArrayConverter\StandardToFlat\Product\ValueConverter;

use Akeneo\Pim\Enrichment\Component\Product\Connector\ArrayConverter\StandardToFlat\Product\ValueConverter\AbstractValueConverter;
use Akeneo\Pim\Enrichment\Component\Product\Connector\ArrayConverter\StandardToFlat\Product\ValueConverter\ValueConverterInterface;
use Cld\Bundle\TextCollectionBundle\AttributeType\TextCollectionType;

/**
 * Converts a text-collection value from standard to flat format.
 *
 * Standard: ['my_attr' => [['locale' => ..., 'scope' => ..., 'data' => ['v1','v2']]]]
 * Flat:     ['my_attr' => "v1, v2"]
 */
class TextCollectionConverter extends AbstractValueConverter implements ValueConverterInterface
{
    public function convert($attributeCode, $data): array
    {
        $convertedItem = [];

        foreach ($data as $value) {
            $flatName = $this->columnsResolver->resolveFlatAttributeName(
                $attributeCode,
                $value['locale'],
                $value['scope']
            );

            $items = !empty($value['data']) ? $value['data'] : [];
            $convertedItem[$flatName] = implode(TextCollectionType::FLAT_SEPARATOR, $items);
        }

        return $convertedItem;
    }
}
