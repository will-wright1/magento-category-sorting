<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Plugin;

use DOMXPath;
use Magento\Framework\Indexer\Config\Converter;

/** Add the optional MSI indexer before Magento expands and sorts indexer dependencies. */
class InventoryIndexerDependency
{
    public function beforeConvert(Converter $subject, $source): array
    {
        $xpath = new DOMXPath($source);
        if ($xpath->query('/config/indexer[@id="inventory"]')->length) {
            $ranking = $xpath->query('/config/indexer[@id="willwright_category_sorting"]')->item(0);
            if ($ranking && !$xpath->query('dependencies/indexer[@id="inventory"]', $ranking)->length) {
                $dependencies = $xpath->query('dependencies', $ranking)->item(0)
                    ?? $ranking->appendChild($source->createElement('dependencies'));
                $dependency = $source->createElement('indexer');
                $dependency->setAttribute('id', 'inventory');
                $dependencies->appendChild($dependency);
            }
        }
        return [$source];
    }
}
