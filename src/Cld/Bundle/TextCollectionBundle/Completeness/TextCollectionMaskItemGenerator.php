<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\Completeness;

use Akeneo\Pim\Enrichment\Component\Product\Completeness\MaskItemGenerator\MaskItemGeneratorForAttributeType;
use Cld\Bundle\TextCollectionBundle\AttributeType\TextCollectionTypes;

/**
 * Completeness mask generator: a text-collection value counts as complete when it is set.
 */
class TextCollectionMaskItemGenerator implements MaskItemGeneratorForAttributeType
{
    public function forRawValue(string $attributeCode, string $channelCode, string $localeCode, $value): array
    {
        return [sprintf('%s-%s-%s', $attributeCode, $channelCode, $localeCode)];
    }

    public function supportedAttributeTypes(): array
    {
        return [TextCollectionTypes::TEXT_COLLECTION];
    }
}
