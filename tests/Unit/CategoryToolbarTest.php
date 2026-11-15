<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Willwright\CategorySorting\Model\Config;
use Willwright\CategorySorting\Plugin\CategoryToolbar;
use Magento\Catalog\Block\Product\ProductList\Toolbar;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
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
    public function testRecommendedDefaultRetainsManualSelections(string $collectionType, ?string $selection, string $expected): void
    {
        $toolbar = $this->toolbar($selection);
        $plugin = $this->plugin('catalog_category_view', true);
        $collection = $this->collection($collectionType);
        self::assertSame([$collection], $plugin->beforeSetCollection($toolbar, $collection));
        self::assertSame($expected, $toolbar->getCurrentOrder());
        self::assertSame(Config::SORT_CODE, array_key_first($toolbar->getAvailableOrders()));
        self::assertSame('desc', $plugin->afterGetCurrentDirection($toolbar, 'desc'));
    }

    public static function choices(): array
    {
        $cases = [];
        foreach (['native', 'amasty'] as $type) {
            foreach ([[null, Config::SORT_CODE], ['invalid', Config::SORT_CODE], ['price', 'price'], ['name', 'name']] as [$selection, $expected]) {
                $cases[$type . ':' . ($selection ?? 'default')] = [$type, $selection, $expected];
            }
        }
        return $cases;
    }

    #[DataProvider('unaffectedRoutes')]
    public function testDoesNotChangeOtherPagesOrDisabledStores(string $collectionType, string $route, bool $enabled): void
    {
        $toolbar = $this->toolbar(null);
        $collection = $this->collection($collectionType);
        $this->plugin($route, $enabled)->beforeSetCollection($toolbar, $collection);
        self::assertSame('price', $toolbar->getCurrentOrder());
        self::assertArrayNotHasKey(Config::SORT_CODE, $toolbar->getAvailableOrders());
    }

    public static function unaffectedRoutes(): array
    {
        $cases = [];
        foreach (['native', 'amasty'] as $type) {
            foreach ([['catalogsearch_result_index', true], ['catalog_product_view', true], ['catalog_category_view', false]] as [$route, $enabled]) {
                $cases[] = [$type, $route, $enabled];
            }
        }
        return $cases;
    }

    public function testDoesNotAddSearchSortingToAnOrdinarySqlProductCollection(): void
    {
        $toolbar = $this->toolbar(null);
        $collection = $this->createStub(ProductCollection::class);
        $this->plugin('catalog_category_view', true)->beforeSetCollection($toolbar, $collection);
        self::assertSame('price', $toolbar->getCurrentOrder());
        self::assertArrayNotHasKey(Config::SORT_CODE, $toolbar->getAvailableOrders());
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

    private function collection(string $type): ProductCollection
    {
        $class = $type === 'amasty' ? 'Amasty\\Shopby\\Model\\ResourceModel\\Fulltext\\Collection' : Collection::class;
        if ($type === 'amasty' && !class_exists($class)) {
            // Public CI has no commercial Amasty dependency. Model its independent inheritance only.
            class_alias(IndependentSearchCollection::class, $class);
        }
        $collection = $this->createStub($class);
        $collection->method('getStoreId')->willReturn(3);
        return $collection;
    }
}

/** Minimal authored test fixture: Amasty's search collection also extends ProductCollection directly. */
class IndependentSearchCollection extends ProductCollection
{
}
