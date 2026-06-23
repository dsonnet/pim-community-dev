<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\Normalizer\Indexing\Value;

use Akeneo\Pim\Enrichment\Component\Product\Model\ValueInterface;
use Akeneo\Pim\Enrichment\Component\Product\Normalizer\Indexing\Value\AbstractProductValueNormalizer;
use Akeneo\Pim\Enrichment\Component\Product\Normalizer\Indexing\Value\ValueCollectionNormalizer;
use Cld\Bundle\TextCollectionBundle\Value\TextCollectionValue;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a TextCollectionValue for the Elasticsearch product / product-model index.
 */
class TextCollectionNormalizer extends AbstractProductValueNormalizer implements NormalizerInterface
{
    public function supportsNormalization($data, $format = null): bool
    {
        return $data instanceof TextCollectionValue
            && ValueCollectionNormalizer::INDEXING_FORMAT_PRODUCT_AND_MODEL_INDEX === $format;
    }

    protected function getNormalizedData(ValueInterface $value)
    {
        return $value->getData();
    }
}
