<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\DataGrid\Form\Type\Filter;

use Oro\Bundle\FilterBundle\Form\Type\Filter\FilterType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

class TextCollectionFilterType extends AbstractType
{
    public const NAME = 'cld_type_text_collection_filter';

    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getParent(): string
    {
        return FilterType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $choices = [
            FilterType::TYPE_EMPTY => $this->translator->trans('oro.filter.form.label_type_empty'),
            FilterType::TYPE_NOT_EMPTY => $this->translator->trans('oro.filter.form.label_type_not_empty'),
        ];

        $resolver->setDefaults([
            'field_type' => TextType::class,
            'operator_choices' => $choices,
        ]);
    }
}
