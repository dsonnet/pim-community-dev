<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\Updater\Setter;

use Akeneo\Pim\Enrichment\Component\Product\Builder\EntityWithValuesBuilderInterface;
use Akeneo\Pim\Enrichment\Component\Product\Model\EntityWithValuesInterface;
use Akeneo\Pim\Enrichment\Component\Product\Updater\Setter\AbstractAttributeSetter;
use Akeneo\Pim\Structure\Component\Model\AttributeInterface;
use Akeneo\Tool\Component\StorageUtils\Exception\InvalidPropertyTypeException;

/**
 * Sets a text-collection value (an array of free-text strings) on a product / product
 * model. Used by imports and the updater.
 */
class TextCollectionAttributeSetter extends AbstractAttributeSetter
{
    public function __construct(EntityWithValuesBuilderInterface $entityWithValuesBuilder, array $supportedTypes)
    {
        parent::__construct($entityWithValuesBuilder);
        $this->supportedTypes = $supportedTypes;
    }

    /**
     * {@inheritdoc}
     */
    public function setAttributeData(
        EntityWithValuesInterface $entityWithValues,
        AttributeInterface $attribute,
        $data,
        array $options = []
    ) {
        $options = $this->resolver->resolve($options);
        $this->checkData($attribute, $data);

        $this->entityWithValuesBuilder->addOrReplaceValue(
            $entityWithValues,
            $attribute,
            $options['locale'],
            $options['scope'],
            null === $data ? [] : array_values($data)
        );
    }

    private function checkData(AttributeInterface $attribute, $data): void
    {
        if (null === $data) {
            return;
        }

        if (!is_array($data)) {
            throw InvalidPropertyTypeException::arrayExpected($attribute->getCode(), static::class, $data);
        }

        foreach ($data as $value) {
            if (!is_string($value)) {
                throw InvalidPropertyTypeException::validArrayStructureExpected(
                    $attribute->getCode(),
                    'one of the values is not a string',
                    static::class,
                    $data
                );
            }
        }
    }
}
