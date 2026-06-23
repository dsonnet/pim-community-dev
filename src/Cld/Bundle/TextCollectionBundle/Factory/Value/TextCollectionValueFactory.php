<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\Factory\Value;

use Akeneo\Pim\Enrichment\Component\Product\Factory\Value\ValueFactory;
use Akeneo\Pim\Enrichment\Component\Product\Model\ValueInterface;
use Akeneo\Pim\Structure\Component\Query\PublicApi\AttributeType\Attribute;
use Akeneo\Tool\Component\StorageUtils\Exception\InvalidPropertyTypeException;
use Cld\Bundle\TextCollectionBundle\AttributeType\TextCollectionTypes;
use Cld\Bundle\TextCollectionBundle\Value\TextCollectionValue;
use Webmozart\Assert\Assert;

/**
 * Builds TextCollectionValue from stored / incoming data. Mirrors core
 * OptionsValueFactory but accepts arbitrary non-empty strings (no option lookup).
 */
final class TextCollectionValueFactory implements ValueFactory
{
    public function createWithoutCheckingData(Attribute $attribute, ?string $channelCode, ?string $localeCode, $data): ValueInterface
    {
        if (null === $data) {
            $data = [];
        }
        $data = array_values($data);

        $attributeCode = $attribute->code();

        if ($attribute->isLocalizableAndScopable()) {
            return TextCollectionValue::scopableLocalizableValue($attributeCode, $data, $channelCode, $localeCode);
        }

        if ($attribute->isScopable()) {
            return TextCollectionValue::scopableValue($attributeCode, $data, $channelCode);
        }

        if ($attribute->isLocalizable()) {
            return TextCollectionValue::localizableValue($attributeCode, $data, $localeCode);
        }

        return TextCollectionValue::value($attributeCode, $data);
    }

    public function createByCheckingData(Attribute $attribute, ?string $channelCode, ?string $localeCode, $data): ValueInterface
    {
        if (!is_array($data)) {
            throw InvalidPropertyTypeException::arrayExpected($attribute->code(), static::class, $data);
        }

        try {
            Assert::allString($data);
        } catch (\Exception $e) {
            throw InvalidPropertyTypeException::validArrayStructureExpected(
                $attribute->code(),
                'one of the values is not a string',
                static::class,
                $data
            );
        }

        try {
            Assert::allStringNotEmpty($data);
        } catch (\Exception $e) {
            throw InvalidPropertyTypeException::validArrayStructureExpected(
                $attribute->code(),
                'one of the values is an empty string',
                static::class,
                $data
            );
        }

        return $this->createWithoutCheckingData($attribute, $channelCode, $localeCode, $data);
    }

    public function supportedAttributeType(): string
    {
        return TextCollectionTypes::TEXT_COLLECTION;
    }
}
