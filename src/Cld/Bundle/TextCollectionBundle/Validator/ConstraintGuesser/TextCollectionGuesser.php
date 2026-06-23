<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\Validator\ConstraintGuesser;

use Akeneo\Pim\Enrichment\Component\Product\Validator\ConstraintGuesserInterface;
use Akeneo\Pim\Structure\Component\Model\AttributeInterface;
use Cld\Bundle\TextCollectionBundle\AttributeType\TextCollectionTypes;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validation guesser for the text-collection attribute type. Each item in the
 * collection must be non-blank, must respect the attribute's max-characters limit,
 * and (optionally) the configured validation rule (url / regexp / email).
 *
 * Ports the intent of the legacy 2.3 AppBundle TextCollectionGuesser/LengthGuesser
 * (which relied on the now-absent ExtendedAttributeTypeBundle).
 */
class TextCollectionGuesser implements ConstraintGuesserInterface
{
    public function supportAttribute(AttributeInterface $attribute)
    {
        return TextCollectionTypes::TEXT_COLLECTION === $attribute->getType();
    }

    public function guessConstraints(AttributeInterface $attribute)
    {
        $itemConstraints = [new Assert\NotBlank()];

        $maxCharacters = $attribute->getMaxCharacters();
        if (null !== $maxCharacters && $maxCharacters > 0) {
            $itemConstraints[] = new Assert\Length(['max' => $maxCharacters]);
        }

        switch ($attribute->getValidationRule()) {
            case 'url':
                $itemConstraints[] = new Assert\Url();
                break;
            case 'email':
                $itemConstraints[] = new Assert\Email();
                break;
            case 'regexp':
                if ($pattern = $attribute->getValidationRegexp()) {
                    $itemConstraints[] = new Assert\Regex(['pattern' => $pattern]);
                }
                break;
        }

        return [new Assert\All(['constraints' => $itemConstraints])];
    }
}
