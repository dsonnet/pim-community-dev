<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\Filter\ProductValue;

use Akeneo\Pim\Enrichment\Component\Product\Query\Filter\Operators;
use Cld\Bundle\TextCollectionBundle\DataGrid\Form\Type\Filter\TextCollectionFilterType;
use Oro\Bundle\FilterBundle\Form\Type\Filter\FilterType;
use Oro\Bundle\PimFilterBundle\Filter\ProductValue\StringFilter;

class TextCollectionFilter extends StringFilter
{
    /** @var array<int, string> */
    protected $operatorTypes = [
        FilterType::TYPE_EMPTY => Operators::IS_EMPTY,
        FilterType::TYPE_NOT_EMPTY => Operators::IS_NOT_EMPTY,
    ];

    protected function getFormType()
    {
        return TextCollectionFilterType::class;
    }
}
