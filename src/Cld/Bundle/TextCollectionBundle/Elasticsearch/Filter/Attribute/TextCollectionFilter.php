<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\Elasticsearch\Filter\Attribute;

use Akeneo\Pim\Enrichment\Bundle\Elasticsearch\Filter\Attribute\AbstractAttributeFilter;
use Akeneo\Pim\Enrichment\Component\Product\Exception\InvalidOperatorException;
use Akeneo\Pim\Enrichment\Component\Product\Query\Filter\AttributeFilterInterface;
use Akeneo\Pim\Enrichment\Component\Product\Query\Filter\Operators;
use Akeneo\Pim\Enrichment\Component\Product\Validator\ElasticsearchFilterValidator;
use Akeneo\Pim\Structure\Component\Model\AttributeInterface;
use Akeneo\Tool\Component\StorageUtils\Exception\InvalidPropertyTypeException;
use LogicException;

/**
 * Elasticsearch filter for the text-collection attribute type.
 * Supports EMPTY / NOT EMPTY (exists) and IN LIST / NOT IN LIST (terms).
 */
class TextCollectionFilter extends AbstractAttributeFilter implements AttributeFilterInterface
{
    public function __construct(
        ElasticsearchFilterValidator $filterValidator,
        array $supportedAttributeTypes = [],
        array $supportedOperators = []
    ) {
        $this->filterValidator = $filterValidator;
        $this->supportedAttributeTypes = $supportedAttributeTypes;
        $this->supportedOperators = $supportedOperators;
    }

    public function addAttributeFilter(
        AttributeInterface $attribute,
        $operator,
        $value,
        $locale = null,
        $channel = null,
        $options = []
    ) {
        if (null === $this->searchQueryBuilder) {
            throw new LogicException('The search query builder is not initialized in the filter.');
        }

        $this->checkLocaleAndChannel($attribute, $locale, $channel);

        if (Operators::IS_EMPTY !== $operator && Operators::IS_NOT_EMPTY !== $operator) {
            $this->checkValue($attribute, $value);
        }

        $attributePath = $this->getAttributePath($attribute, $locale, $channel);

        switch ($operator) {
            case Operators::IS_EMPTY:
                $this->searchQueryBuilder->addMustNot(['exists' => ['field' => $attributePath]]);
                break;

            case Operators::IS_NOT_EMPTY:
                $this->searchQueryBuilder->addFilter(['exists' => ['field' => $attributePath]]);
                break;

            case Operators::IN_LIST:
                $this->searchQueryBuilder->addFilter(['terms' => [$attributePath => array_values($value)]]);
                break;

            case Operators::IS_NOT_IN_LIST:
                $this->searchQueryBuilder->addFilter(['exists' => ['field' => $attributePath]]);
                $this->searchQueryBuilder->addMustNot(['terms' => [$attributePath => array_values($value)]]);
                break;

            default:
                throw InvalidOperatorException::notSupported($operator, static::class);
        }

        return $this;
    }

    protected function checkValue(AttributeInterface $attribute, $value): void
    {
        if (!is_array($value)) {
            throw InvalidPropertyTypeException::arrayExpected($attribute->getCode(), static::class, $value);
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw InvalidPropertyTypeException::validArrayStructureExpected(
                    $attribute->getCode(),
                    'one of the values is not a string',
                    static::class,
                    $value
                );
            }
        }
    }
}
