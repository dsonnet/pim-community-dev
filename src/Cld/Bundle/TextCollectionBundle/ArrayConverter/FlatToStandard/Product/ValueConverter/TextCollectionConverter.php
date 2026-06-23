<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\ArrayConverter\FlatToStandard\Product\ValueConverter;

use Akeneo\Pim\Enrichment\Component\Product\Connector\ArrayConverter\FlatToStandard\ValueConverter\ValueConverterInterface;
use Cld\Bundle\TextCollectionBundle\AttributeType\TextCollectionType;

/**
 * Converts a text-collection value from flat to standard format.
 *
 * Flat:     'my_attr' => "value1, value2, value3"
 * Standard: ['my_attr' => [['locale' => ..., 'scope' => ..., 'data' => ['value1','value2','value3']]]]
 */
class TextCollectionConverter implements ValueConverterInterface
{
    /** @var string[] */
    private array $supportedFieldTypes;

    public function __construct(array $supportedFieldTypes)
    {
        $this->supportedFieldTypes = $supportedFieldTypes;
    }

    public function supportsField($attributeType): bool
    {
        return in_array($attributeType, $this->supportedFieldTypes, true);
    }

    public function convert(array $attributeFieldInfo, $value): array
    {
        return [
            $attributeFieldInfo['attribute']->getCode() => [[
                'locale' => $attributeFieldInfo['locale_code'],
                'scope' => $attributeFieldInfo['scope_code'],
                'data' => $this->splitFlat((string) $value),
            ]],
        ];
    }

    /**
     * @return string[]
     */
    private function splitFlat(string $value): array
    {
        if ('' === trim($value)) {
            return [];
        }

        $items = array_map('trim', explode(',', $value));

        return array_values(array_filter($items, static fn (string $item): bool => '' !== $item));
    }
}
