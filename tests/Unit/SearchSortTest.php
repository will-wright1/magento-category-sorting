<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Willwright\CategorySorting\Model\Config;
use Willwright\CategorySorting\Plugin\SearchSort;
use Willwright\CategorySorting\Search\FieldMapping;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\AttributeAdapter;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\AttributeProvider;
use Magento\Elasticsearch\SearchAdapter\Query\Builder\Sort;
use Magento\Elasticsearch\SearchAdapter\Query\Builder\Sort\ExpressionBuilder;
use Magento\Framework\Search\Request;
use Magento\Framework\Search\Request\QueryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchSortTest extends TestCase
{
    public function testExpandsRecommendedBeforePaginationAndUsesNativeCustomerPrice(): void
    {
        $request = $this->request('catalog_view_container', Config::SORT_CODE);
        $attribute = $this->createStub(AttributeAdapter::class);
        $attributes = $this->createMock(AttributeProvider::class);
        $attributes->expects(self::once())->method('getByAttributeCode')->with('price')->willReturn($attribute);
        $expressions = $this->createMock(ExpressionBuilder::class);
        $expressions->expects(self::once())->method('build')->with($attribute, 'desc', $request)
            ->willReturn(['price_7_3' => ['order' => 'desc']]);
        $plugin = new SearchSort($attributes, $expressions);
        $result = $plugin->aroundGetSort($this->createStub(Sort::class),
            static function (): never { self::fail('Recommended must not enter the EAV sort builder'); }, $request);

        self::assertSame([
            [Config::AVAILABILITY_FIELD => ['order' => 'asc', 'missing' => 2, 'unmapped_type' => 'integer']],
            [Config::SALES_FIELD => ['order' => 'desc', 'missing' => 0, 'unmapped_type' => 'double']],
            [Config::RATING_FIELD => ['order' => 'desc', 'missing' => 0, 'unmapped_type' => 'integer']],
            ['price_7_3' => ['order' => 'desc']],
            [Config::ID_FIELD => ['order' => 'asc', 'unmapped_type' => 'long']],
        ], $result);
        self::assertSame(24, $request->getFrom());
        self::assertSame(12, $request->getSize());
        self::assertSame('long', (new FieldMapping())->getFields()[Config::ID_FIELD]['type']);
    }

    #[DataProvider('ordinarySorts')]
    public function testLeavesOtherSortsAndSearchAlone(string $name, string $field): void
    {
        $attributes = $this->createMock(AttributeProvider::class);
        $attributes->expects(self::never())->method('getByAttributeCode');
        $plugin = new SearchSort($attributes, $this->createStub(ExpressionBuilder::class));
        $request = $this->request($name, $field);
        self::assertSame(['original'], $plugin->aroundGetSort($this->createStub(Sort::class),
            static function ($actual) use ($request): array {
                self::assertSame($request, $actual);
                return ['original'];
            }, $request));
    }

    public static function ordinarySorts(): array
    {
        return [
            ['catalog_view_container', 'price'], ['catalog_view_container', 'name'],
            ['catalog_view_container', 'position'], ['quick_search_container', 'relevance'],
            ['quick_search_container', Config::SORT_CODE],
        ];
    }

    public function testNativePriceExpressionUsesCurrentCustomerGroupAndWebsite(): void
    {
        $data = (new \ReflectionClass(\Magento\Catalog\Model\ResourceModel\Eav\Attribute::class))->newInstanceWithoutConstructor();
        $data->setData(['attribute_code' => 'price', 'backend_type' => 'decimal', 'frontend_input' => 'price', 'used_for_sort_by' => 1]);
        $attribute = new AttributeAdapter($data, 'price');
        $attributes = $this->createStub(AttributeProvider::class);
        $attributes->method('getByAttributeCode')->willReturn($attribute);
        $customer = $this->createStub(\Magento\Customer\Model\Session::class);
        $customer->method('getCustomerGroupId')->willReturn(7);
        $store = $this->createStub(\Magento\Store\Model\Store::class);
        $store->method('getWebsiteId')->willReturn(3);
        $stores = $this->createStub(\Magento\Store\Model\StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $resolver = new \Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\FieldProvider\FieldName\Resolver\Price($customer, $stores);
        $expressions = new ExpressionBuilder(new \Magento\Elasticsearch\SearchAdapter\Query\Builder\Sort\DefaultExpression($resolver));
        $sort = (new SearchSort($attributes, $expressions))->aroundGetSort($this->createStub(Sort::class),
            static fn() => [], $this->request('catalog_view_container', Config::SORT_CODE));
        self::assertSame(['price_7_3' => ['order' => 'desc']], $sort[3]);
    }

    private function request(string $name, string $field): Request
    {
        return new Request($name, 'catalogsearch_fulltext', $this->createStub(QueryInterface::class), 24, 12, [], [], [
            ['field' => $field, 'direction' => 'asc'], ['field' => 'entity_id', 'direction' => 'desc'],
        ]);
    }
}
