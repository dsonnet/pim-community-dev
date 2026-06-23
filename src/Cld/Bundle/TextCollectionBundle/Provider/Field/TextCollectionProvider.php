<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\Provider\Field;

use Akeneo\Pim\Structure\Component\Model\AttributeInterface;
use Akeneo\Platform\Bundle\UIBundle\Provider\Field\FieldProviderInterface;
use Cld\Bundle\TextCollectionBundle\AttributeType\TextCollectionTypes;

/**
 * Tells the product edit form which JS field to use for a text-collection attribute.
 */
class TextCollectionProvider implements FieldProviderInterface
{
    public function getField($element)
    {
        return TextCollectionTypes::TEXT_COLLECTION;
    }

    public function supports($element): bool
    {
        return $element instanceof AttributeInterface
            && TextCollectionTypes::TEXT_COLLECTION === $element->getType();
    }
}
