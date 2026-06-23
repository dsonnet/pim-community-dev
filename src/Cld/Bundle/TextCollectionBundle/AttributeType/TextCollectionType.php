<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\AttributeType;

use Akeneo\Pim\Structure\Component\AttributeType\AbstractAttributeType;

class TextCollectionType extends AbstractAttributeType
{
    /** Separator used in the flat (CSV/XLSX) representation of the value list. */
    public const FLAT_SEPARATOR = ', ';

    public function getName(): string
    {
        return TextCollectionTypes::TEXT_COLLECTION;
    }
}
