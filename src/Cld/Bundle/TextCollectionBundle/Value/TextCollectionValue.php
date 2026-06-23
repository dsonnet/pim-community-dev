<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\Value;

use Akeneo\Pim\Enrichment\Component\Product\Model\AbstractValue;
use Akeneo\Pim\Enrichment\Component\Product\Model\ValueInterface;

/**
 * Product value for the "pim_catalog_text_collection" attribute type: an ordered list
 * of free-text strings. Structurally identical to core OptionsValue (string[] data),
 * but the strings are arbitrary text rather than option codes.
 */
class TextCollectionValue extends AbstractValue implements ValueInterface
{
    /** @var string[] */
    protected $data;

    protected function __construct(string $attributeCode, ?array $data, ?string $scopeCode, ?string $localeCode)
    {
        parent::__construct($attributeCode, $data ?? [], $scopeCode, $localeCode);
    }

    /**
     * @return string[]
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function __toString(): string
    {
        return implode(', ', $this->data);
    }

    public function isEqual(ValueInterface $value): bool
    {
        if (!$value instanceof self
            || $this->getScopeCode() !== $value->getScopeCode()
            || $this->getLocaleCode() !== $value->getLocaleCode()
        ) {
            return false;
        }

        $other = $value->getData();

        return [] === array_diff($this->data, $other) && [] === array_diff($other, $this->data);
    }
}
