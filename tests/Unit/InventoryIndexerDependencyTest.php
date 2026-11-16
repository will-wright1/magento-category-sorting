<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use DOMDocument;
use Magento\Framework\Indexer\Config\Converter;
use Magento\Framework\Indexer\Config\Converter\SortingAdjustmentInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Willwright\CategorySorting\Plugin\InventoryIndexerDependency;

final class InventoryIndexerDependencyTest extends TestCase
{
    #[DataProvider('inventoryModes')]
    public function testMagentoOrdersStockBeforeRankingAndSearchWithoutRequiringMsi(bool $msi): void
    {
        $xml = new DOMDocument();
        $xml->load(dirname(__DIR__, 2) . '/etc/indexer.xml');
        $legacy = $xml->createElement('indexer');
        $legacy->setAttribute('id', 'cataloginventory_stock');
        $xml->documentElement->appendChild($legacy);
        if ($msi) {
            $inventory = $xml->createElement('indexer');
            $inventory->setAttribute('id', 'inventory');
            $xml->documentElement->appendChild($inventory);
        }
        $adjustment = $this->createStub(SortingAdjustmentInterface::class);
        $adjustment->method('adjust')->willReturnArgument(0);
        $converter = new Converter($adjustment);
        $plugin = new InventoryIndexerDependency();
        [$xml] = $plugin->beforeConvert($converter, $xml);
        [$xml] = $plugin->beforeConvert($converter, $xml); // Config conversion is idempotent.
        $indexers = $converter->convert($xml);
        self::assertSame($msi ? ['cataloginventory_stock', 'inventory'] : ['cataloginventory_stock'],
            $indexers['willwright_category_sorting']['dependencies']);
        $order = array_keys($indexers);
        self::assertLessThan(array_search('catalogsearch_fulltext', $order), array_search('willwright_category_sorting', $order));
        if ($msi) {
            self::assertLessThan(array_search('willwright_category_sorting', $order), array_search('inventory', $order));
        }
    }

    public static function inventoryModes(): array
    {
        return ['legacy' => [false], 'MSI' => [true]];
    }
}
