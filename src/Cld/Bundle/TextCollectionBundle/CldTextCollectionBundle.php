<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Restores the legacy Akeneo 2.3 "pim_catalog_text_collection" attribute type on v7:
 * a multi-valued free-text attribute (an ordered list of strings per channel/locale),
 * e.g. cast lists, alternate barcodes, connector lists.
 *
 * Modelled on core multiselect (OptionsValue / OptionsValueFactory) — same array-of-
 * strings storage — but without the option-existence constraint, so any free text is
 * accepted.
 */
class CldTextCollectionBundle extends Bundle
{
}
