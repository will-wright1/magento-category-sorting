<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Willwright\CategorySorting\Model\Config;
use Willwright\CategorySorting\Plugin\CategoryToolbar;
use Magento\Catalog\Block\Product\ProductList\Toolbar;
use Magento\Catalog\Model\Product\ProductList\ToolbarMemorizer;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class CategoryToolbarTest extends TestCase
{
    #[DataProvider('choices')]
    public function testRecommendedDefaultRetainsManualSelections(?string $selection, string $expected): void
    {
        $toolbar = $this->toolbar($selection);
        $plugin = $this->plugin('catalog_category_view', true);
        $collection = $this->createStub(Collection::class);
        $collection->method('getStoreId')->willReturn(3);
        self::assertSame([$collection], $plugin->beforeSetCollection($toolbar, $collection));
        self::assertSame($expected, $toolbar->getCurrentOrder());
        self::assertSame(Config::SORT_CODE, array_key_first($toolbar->getAvailableOrders()));
        self::assertSame('desc', $plugin->afterGetCurrentDirection($toolbar, 'desc'));
    }

    public static function choices(): array
    {
        return [[null, Config::SORT_CODE], ['invalid', Config::SORT_CODE], ['price', 'price'], ['name', 'name']];
    }

    #[DataProvider('unaffectedRoutes')]
    public function testDoesNotChangeOtherPagesOrDisabledStores(string $route, bool $enabled): void
    {
        $toolbar = $this->toolbar(null);
        $collection = $this->createStub(Collection::class);
        $collection->method('getStoreId')->willReturn(3);
        $this->plugin($route, $enabled)->beforeSetCollection($toolbar, $collection);
        self::assertSame('price', $toolbar->getCurrentOrder());
        self::assertArrayNotHasKey(Config::SORT_CODE, $toolbar->getAvailableOrders());
    }

    public static function unaffectedRoutes(): array
    {
        return [['catalogsearch_result_index', true], ['catalog_product_view', true], ['catalog_category_view', false]];
    }

    public function testManualPriceDirectionCanRemainAscending(): void
    {
        $toolbar = $this->toolbar('price');
        self::assertSame('asc', $this->plugin('catalog_category_view', true)->afterGetCurrentDirection($toolbar, 'asc'));
    }

    private function toolbar(?string $selection): Toolbar
    {
        $toolbar = (new ReflectionClass(Toolbar::class))->newInstanceWithoutConstructor();
        $toolbar->setAvailableOrders(['price' => 'Price', 'name' => 'Name']);
        $toolbar->setDefaultOrder('price');
        $memorizer = $this->createStub(ToolbarMemorizer::class);
        $memorizer->method('getOrder')->willReturn($selection);
        $memorizer->method('isMemorizingAllowed')->willReturn(false);
        (new ReflectionProperty(Toolbar::class, 'toolbarMemorizer'))->setValue($toolbar, $memorizer);
        return $toolbar;
    }

    private function plugin(string $route, bool $enabled): CategoryToolbar
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $request = $this->createStub(Http::class);
        $request->method('getFullActionName')->willReturn($route);
        return new CategoryToolbar($config, $request);
    }
}
