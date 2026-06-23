<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\AttributeType;

final class TextCollectionTypes
{
    /**
     * Attribute type alias. Kept identical to the legacy Akeneo 2.3 type string so that
     * attribute definitions exported from 2.3 import unchanged on v7.
     */
    public const TEXT_COLLECTION = 'pim_catalog_text_collection';

    /** Custom backend type — values live in raw_values JSON, not a legacy ORM column. */
    public const BACKEND_TYPE_TEXT_COLLECTION = 'textCollection';
}
