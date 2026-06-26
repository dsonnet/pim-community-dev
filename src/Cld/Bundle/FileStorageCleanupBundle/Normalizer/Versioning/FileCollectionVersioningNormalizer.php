<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Normalizer\Versioning;

use Akeneo\Pim\Enrichment\Component\Product\Model\ValueInterface;
use Akeneo\Tool\Component\StorageUtils\Repository\IdentifiableObjectRepositoryInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Deterministic versioning ('flat'/'csv') normalizer for the Smartoys FileCollection
 * (smartoys_catalog_file_collection) value, fixing the spurious "file_path / ignored changed"
 * entries that appear in product history on every save even when the collection did not change.
 *
 * Root cause (stock Akeneo): the attribute type ships no versioning normalizer, so its value
 * (a generic ScalarValue whose data is an array of items) flows through
 * Akeneo\...\Versioning\Product\ValueNormalizer -> CollectionNormalizer, whose accumulation loop
 * runs `array_replace($result, $normalizedItem)` the first time it sees each key. That merges the
 * WHOLE item rather than the single new key, so every *other* key of that item is immediately
 * "already present" and gains a phantom leading element. Which column is hit depends only on the
 * item array's key order, which flips between "hydrated from raw_values" (ignored,file_key,
 * file_path,…) and "rebuilt by the setter" (file_path,ignored,…). Consecutive saves therefore
 * alternate between two off-by-one states (file_path 13<->14 with a duplicated first item,
 * ignored 14<->13) and the changeset logs a phantom diff every time.
 *
 * Registered above that generic path (priority 200 > 90 on pim_versioning.serializer.normalizer),
 * this emits two stable, self-consistent columns built from the items in a fixed order:
 *   <attr>          = file keys, sort_order-sorted, comma-joined
 *   <attr>-ignored  = matching 0/1 flags, same order
 * Identical data snapshots identically every save -> no phantom diff, while real add/remove/
 * reorder/ignored changes still show. Versioning-only: raw_values, ES indexing and connector
 * CSV/XLSX exports (a different serializer) are untouched.
 *
 * Detection is by ATTRIBUTE TYPE, not value class: the runtime value is the generic ScalarValue
 * (the legacy Smartoys\...\FileCollectionValue model extends a v2.x AbstractValue removed in v7
 * and cannot even be autoloaded). It is deliberately NOT a CacheableSupportsMethod: selection
 * depends on the attribute, not the value class, and being first + non-cacheable means the
 * serializer always consults it first and falls straight through for every other value type.
 */
final class FileCollectionVersioningNormalizer implements NormalizerInterface
{
    private const ATTRIBUTE_TYPE = 'smartoys_catalog_file_collection';

    public function __construct(
        private readonly IdentifiableObjectRepositoryInterface $attributeRepository,
    ) {
    }

    /**
     * @param ValueInterface $object
     *
     * @return array<string, string>
     */
    public function normalize($object, $format = null, array $context = [])
    {
        $items = \is_array($object->getData()) ? $object->getData() : [];

        usort($items, static function (array $a, array $b): int {
            return [(int) ($a['sort_order'] ?? 0), (string) ($a['file_key'] ?? $a['file_path'] ?? '')]
               <=> [(int) ($b['sort_order'] ?? 0), (string) ($b['file_key'] ?? $b['file_path'] ?? '')];
        });

        $field = $this->fieldName($object);

        return [
            $field => implode(',', array_map(
                static fn (array $i): string => (string) ($i['file_path'] ?? $i['file_key'] ?? ''),
                $items
            )),
            $field . '-ignored' => implode(',', array_map(
                static fn (array $i): string => empty($i['ignored']) ? '0' : '1',
                $items
            )),
        ];
    }

    public function supportsNormalization($data, $format = null): bool
    {
        if (!$data instanceof ValueInterface || !\in_array($format, ['flat', 'csv'], true)) {
            return false;
        }

        $attribute = $this->attributeRepository->findOneByIdentifier($data->getAttributeCode());

        return null !== $attribute && self::ATTRIBUTE_TYPE === $attribute->getType();
    }

    private function fieldName(ValueInterface $value): string
    {
        $name = $value->getAttributeCode();
        if ($value->isLocalizable()) {
            $name .= '-' . $value->getLocaleCode();
        }
        if ($value->isScopable()) {
            $name .= '-' . $value->getScopeCode();
        }

        return $name;
    }
}
