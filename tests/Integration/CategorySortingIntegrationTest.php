<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Regression;

use DateTimeImmutable;
use Willwright\CategorySorting\Model\Availability;
use Willwright\CategorySorting\Model\Config;
use Willwright\CategorySorting\Model\InventoryCompatibility;
use Willwright\CategorySorting\Model\ResourceModel\AvailabilitySource;
use Willwright\CategorySorting\Model\ResourceModel\MsiStockSource;
use Willwright\CategorySorting\Model\ResourceModel\PreorderSource;
use Magento\Framework\Module\Manager;
use Magento\Framework\ObjectManagerInterface;
use Willwright\CategorySorting\Model\ResourceModel\IndexStorage;
use Willwright\CategorySorting\Model\ResourceModel\RatingsSource;
use Willwright\CategorySorting\Model\ResourceModel\SalesSource;
use Willwright\CategorySorting\Plugin\SearchSort;
use Willwright\CategorySorting\Search\FieldMapping;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\AttributeAdapter;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\AttributeProvider;
use Magento\Elasticsearch\SearchAdapter\Query\Builder\Sort;
use Magento\Elasticsearch\SearchAdapter\Query\Builder\Sort\ExpressionBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Logger\Quiet;
use Magento\Framework\DB\Platform\Quote;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Search\Request;
use Magento\Framework\Search\Request\QueryInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Setup\Declaration\Schema\Dto\Factories\Table;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;

/**
 * Opt-in: run ONLY against disposable localhost MySQL/OpenSearch containers.
 * CATEGORY_SORTING_TEST_CONFIG points to JSON containing mysql_port,
 * mysql_password and search_port. The database must be named ranking_test.
 */
final class CategorySortingIntegrationTest extends TestCase
{
    private const PREFIX = 'ranking_fixture_';
    private array $services;
    private Mysql $db;
    private ResourceConnection $resource;

    protected function setUp(): void
    {
        $path = getenv('CATEGORY_SORTING_TEST_CONFIG');
        if (!$path) {
            self::markTestSkipped('Requires isolated local MySQL and OpenSearch test services.');
        }
        $this->services = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach (['mysql_port', 'search_port'] as $key) {
            if (!ctype_digit((string)$this->services[$key])) {
                throw new RuntimeException('Only numeric localhost test-service ports are accepted.');
            }
        }
        // Use Magento's real select renderers too: MSI's bulk reader builds Select objects.
        $renderers = [];
        $parts = ['distinct', 'columns', 'union', 'from', 'where', 'group', 'having', 'order', 'limitcount', 'forupdate'];
        foreach (['Distinct', 'Columns', 'Union', 'From', 'Where', 'Group', 'Having', 'Order', 'Limit', 'ForUpdate'] as $i => $name) {
            $class = 'Magento\\Framework\\DB\\Select\\' . $name . 'Renderer';
            $renderer = (new ReflectionClass($class))->getConstructor() ? new $class(new Quote()) : new $class();
            $renderers[] = ['renderer' => $renderer, 'sort' => $i, 'part' => $parts[$i]];
        }
        $this->db = new Mysql(new StringUtils(), new DateTime(), new Quiet(),
            new SelectFactory(new SelectRenderer($renderers)), [
                'host' => '127.0.0.1:' . $this->services['mysql_port'], 'username' => 'root',
                'password' => $this->services['mysql_password'], 'dbname' => 'ranking_test',
                'initStatements' => 'SET NAMES utf8mb4',
            ], new Json(), (new ReflectionClass(Table::class))->newInstanceWithoutConstructor());
        self::assertSame('ranking_test', $this->db->fetchOne('SELECT DATABASE()'));
        $this->resource = $this->createStub(ResourceConnection::class);
        $this->resource->method('getConnection')->willReturn($this->db);
        $this->resource->method('getTableName')->willReturnCallback(static fn($name) => self::PREFIX . $name);
        $this->resetFixtures();
    }

    public function testSalesValueCountsAllChannelsAndPaidPosWithoutInvoicesAndDeduplicatesVariants(): void
    {
        $date = '2026-11-14 12:00:00';
        $orders = [
            [1, $date, 'processing', 100, 100, 1, 0], // web
            [2, $date, 'complete', 100, 100, 2, 0], // POS
            [3, $date, 'processing', 100, 100, 3, 4], // trade
            [4, $date, 'processing', 100, 0, 3, 4], // unpaid trade
            [5, $date, 'processing', 100, 50, 1, 0], // partially paid
            [6, $date, 'canceled', 100, 100, 1, 0],
            [7, '2026-05-18 11:59:59', 'complete', 100, 100, 1, 0], // older than 180 days
            [8, '2026-05-19 12:00:00', 'complete', 100, 100, 1, 0], // boundary
            [9, $date, 'complete', 100, 100, 1, 0], // explicit test order
            [10, '2026-11-15 12:00:01', 'complete', 100, 100, 1, 0], // future
            [11, $date, 'complete', 100, 100, 1, 0],
            [12, $date, 'complete', 100, 100, 1, 0],
            [13, $date, 'complete', 100, 100, 1, 0],
            [14, $date, 'complete', 100, 100, 1, 0],
            [15, $date, 'complete', 100, 100, 1, 0],
            [16, $date, 'processing', 0, null, 3, 4],
            [17, $date, 'complete', 100, 100, 2, 0],
        ];
        foreach ($orders as $row) {
            $this->insert('sales_order', array_combine(
                ['entity_id', 'created_at', 'state', 'base_grand_total', 'base_total_paid', 'store_id', 'customer_group_id'], $row));
        }
        $this->item(1, 1, 101, 4, 4, 0, 1);
        $this->item(2, 2, 101, 2, 0); // POS: fully paid, no invoice
        $this->item(3, 3, 101, 3, 2);
        foreach ([4, 5, 6, 7, 9, 10] as $order) {
            $this->item(100 + $order, $order, 101, 1000, 1000);
        }
        $this->item(8, 8, 101, 1, 1);
        $this->item(11, 11, 200, 2, 2);
        $this->item(12, 11, 201, 2, 2, 0, 0, 11); // configurable child must not count twice
        $this->item(13, 12, 201, 3, 3); // standalone variant sale contributes to parent
        $this->insert('catalog_product_super_link', ['product_id' => 201, 'parent_id' => 200]);
        $this->item(14, 13, 300, 2, 2);
        $this->item(15, 13, 301, 10, 10, 0, 0, 14); // bundle components must not count twice
        $this->item(16, 14, 102, 5, 4, 2, 1); // 5 - 2 canceled - 1 refunded = 2
        $this->item(17, 15, 104, 2, 2, 0, 3); // clamp at zero
        $this->item(18, 16, 103, 1, 0, unitPrice: 0); // free completed/processing order
        $this->insert('sales_order_item', ['item_id' => 19, 'order_id' => 17, 'product_id' => 105,
            'parent_item_id' => null, 'qty_ordered' => 2, 'qty_invoiced' => null,
            'qty_canceled' => null, 'qty_refunded' => null, 'base_row_total' => 20]); // historical nullable quantity fields
        $config = $this->createStub(Config::class);
        $config->method('getExcludedOrderIds')->willReturn([9]);
        $config->method('getSalesCurrency')->willReturn('GBP');
        $totals = (new SalesSource($this->resource, $config))->getTotals(new DateTimeImmutable('2026-11-15T12:00:00Z'));
        self::assertSame(80.0, $totals[101]);
        self::assertSame(50.0, $totals[200]);
        self::assertSame(30.0, $totals[201]);
        self::assertSame(20.0, $totals[300]);
        self::assertArrayNotHasKey(301, $totals);
        self::assertSame(20.0, $totals[102]);
        self::assertSame(0.0, $totals[104]);
        self::assertSame(0.0, $totals[103]);
        self::assertSame(20.0, $totals[105]);
    }

    public function testSalesValueUsesHistoricalNetMoneyAndCreditsRatherThanUnitsOrCurrentPrice(): void
    {
        $this->paidOrder(1);
        $this->item(1, 1, 101, 3, 3, unitPrice: 100); // 300 from three units
        $this->item(2, 1, 102, 10, 10, unitPrice: 20); // 200 from ten units
        $this->item(3, 1, 103, 4, 2, unitPrice: 100);
        $this->db->update(self::PREFIX . 'sales_order_item', [
            'base_discount_amount' => 80, 'base_discount_invoiced' => 40,
            'base_amount_refunded' => 100, 'base_discount_refunded' => 20, 'qty_refunded' => 1,
        ], ['item_id = ?' => 3]); // 200 - 40 - (100 - 20) = 80
        $this->item(4, 1, 104, 2, 0, 1, unitPrice: 100);
        $this->db->update(self::PREFIX . 'sales_order_item', ['base_discount_amount' => 40], ['item_id = ?' => 4]);
        // Paid POS without an invoice: half of the discounted order line survives cancellation = 80.
        $this->item(5, 1, 105, 1, 1, unitPrice: 100);
        $this->db->update(self::PREFIX . 'sales_order_item', ['base_amount_refunded' => 25], ['item_id = ?' => 5]);
        // A monetary credit with zero returned units still reduces value.
        $this->item(6, 1, 106, 2, 2, unitPrice: 100);
        $this->db->update(self::PREFIX . 'sales_order_item', [
            'base_discount_invoiced' => 24, 'base_discount_tax_compensation_invoiced' => 4,
            'base_amount_refunded' => 100, 'base_discount_refunded' => 12,
            'base_discount_tax_compensation_refunded' => 2,
        ], ['item_id = ?' => 6]); // 180 ex-VAT revenue - 90 ex-VAT credit = 90
        $this->item(7, 1, 107, 0.5, 0.5, unitPrice: 19.99);
        $this->item(8, 1, 108, 1, 1, unitPrice: 10);
        $this->db->update(self::PREFIX . 'sales_order_item', ['base_amount_refunded' => 50], ['item_id = ?' => 8]);
        $this->item(9, 1, 109, 2, 0, unitPrice: 100);
        $this->db->update(self::PREFIX . 'sales_order_item', [
            'base_discount_amount' => 24, 'base_discount_tax_compensation_amount' => 4,
        ], ['item_id = ?' => 9]); // POS uses the same tax-exclusive discount calculation.
        self::assertSame([101 => 300.0, 102 => 200.0, 103 => 80.0, 104 => 80.0, 105 => 75.0,
            106 => 90.0, 107 => 9.995, 108 => 0.0, 109 => 180.0], $this->salesTotals());
    }

    public function testSalesValueNormalizesHistoricalBaseCurrenciesAndRejectsUnknownConversions(): void
    {
        foreach (range(1, 5) as $orderId) {
            $this->paidOrder($orderId);
            $this->item($orderId, $orderId, 100 + $orderId, 1, 1, unitPrice: 100);
        }
        $this->db->update(self::PREFIX . 'sales_order', ['base_currency_code' => 'EUR', 'base_to_global_rate' => 0.8], ['entity_id = ?' => 1]);
        $this->db->update(self::PREFIX . 'sales_order', ['base_currency_code' => 'USD', 'base_to_global_rate' => 0.5], ['entity_id = ?' => 2]);
        $this->db->update(self::PREFIX . 'sales_order', ['base_to_global_rate' => null, 'global_currency_code' => 'USD'], ['entity_id = ?' => 3]);
        $this->db->update(self::PREFIX . 'sales_order', ['base_currency_code' => 'EUR', 'base_to_global_rate' => null], ['entity_id = ?' => 4]);
        $this->db->update(self::PREFIX . 'sales_order', ['base_currency_code' => 'USD', 'global_currency_code' => 'EUR'], ['entity_id = ?' => 5]);
        self::assertSame([101 => 80.0, 102 => 50.0, 103 => 100.0], $this->salesTotals());
    }

    public function testDynamicBundleRevenueAndRefundsBelongToBundleWithoutDoubleCounting(): void
    {
        $this->paidOrder(1);
        $this->item(1, 1, 300, 2, 0, unitPrice: 0);
        $this->db->update(self::PREFIX . 'sales_order_item', ['product_type' => 'bundle'], ['item_id = ?' => 1]);
        $this->item(2, 1, 301, 10, 10, parent: 1, unitPrice: 50);
        $this->item(3, 1, 302, 2, 2, parent: 1, unitPrice: 25);
        $this->db->update(self::PREFIX . 'sales_order_item', ['base_amount_refunded' => 50, 'base_discount_refunded' => 5], ['item_id = ?' => 2]);
        $this->item(4, 1, 400, 2, 2, unitPrice: 100);
        $this->db->update(self::PREFIX . 'sales_order_item', ['product_type' => 'bundle'], ['item_id = ?' => 4]);
        $this->item(5, 1, 401, 10, 10, parent: 4, unitPrice: 100);
        self::assertSame([300 => 505.0, 400 => 200.0], $this->salesTotals());
    }

    private function paidOrder(int $id): void
    {
        $this->insert('sales_order', ['entity_id' => $id, 'created_at' => '2026-11-14 12:00:00',
            'state' => 'complete', 'base_grand_total' => 100, 'base_total_paid' => 100, 'store_id' => 3, 'customer_group_id' => 0]);
    }

    private function salesTotals(): array
    {
        $config = $this->createStub(Config::class);
        $config->method('getSalesCurrency')->willReturn('GBP');
        return (new SalesSource($this->resource, $config))->getTotals(new DateTimeImmutable('2026-11-15T12:00:00Z'));
    }

    public function testAvailabilityUsesStoreWebsiteStatusAndPurchasableVariants(): void
    {
        foreach ([1000 => 'configurable', 1001 => 'simple', 1002 => 'simple', 1010 => 'configurable',
            1011 => 'simple', 1012 => 'simple', 1020 => 'configurable', 1021 => 'simple'] as $id => $type) {
            $this->product($id, $type);
        }
        foreach ([[1001, 1000], [1002, 1000], [1011, 1010], [1012, 1010], [1021, 1020]] as [$child, $parent]) {
            $this->insert('catalog_product_super_link', ['product_id' => $child, 'parent_id' => $parent]);
        }
        foreach ([1002, 1011, 1021] as $id) {
            $this->insert('amasty_preorder_product_index', ['product_id' => $id, 'website_id' => 1]);
        }
        // Positive stock belongs to a disabled child and must not keep 1010 in the immediate group.
        $this->db->update(self::PREFIX . 'catalog_product_entity_int', ['value' => 2], ['entity_id = ?' => 1012]);
        // 1021 is unavailable, rather than purchasable on preorder.
        $this->db->update(self::PREFIX . 'cataloginventory_stock_status', ['stock_status' => 0], ['product_id = ?' => 1021]);
        // A preorder designation on another website must not demote this website's immediate variant.
        $this->insert('amasty_preorder_product_index', ['product_id' => 1001, 'website_id' => 2]);
        $source = $this->availabilitySource();
        self::assertSame([1000 => 0, 1010 => 1, 1020 => 2], $source->getRanks([1000, 1010, 1020], 3));
        // Disable the last immediate variant for this store only; parent becomes preorder-only.
        $this->insert('catalog_product_entity_int', ['entity_id' => 1001, 'attribute_id' => 99, 'store_id' => 3, 'value' => 2]);
        self::assertSame([1000 => 1], $source->getRanks([1000], 3));
        self::assertSame([1000 => 0], $source->getRanks([1000], 4));
    }

    public function testRatingsAndSnapshotStorageAreStoreScopedAndQueueSurvivesUntilPublished(): void
    {
        $this->product(101, 'simple');
        $this->insert('review_entity', ['entity_id' => 1, 'entity_code' => 'product']);
        $this->insert('review_entity', ['entity_id' => 2, 'entity_code' => 'customer']);
        foreach ([[101, 1, 3, 2, 90], [101, 1, 4, 5, 100], [102, 1, 3, 0, 100], [103, 2, 3, 2, 100]] as $row) {
            $this->insert('review_entity_summary', array_combine(
                ['entity_pk_value', 'entity_type', 'store_id', 'reviews_count', 'rating_summary'], $row));
        }
        $ratings = new RatingsSource($this->resource);
        self::assertSame([101 => 90], $ratings->getRatings([101, 102, 103], 3));
        self::assertSame([101 => 100], $ratings->getRatings([101], 4));
        $storage = new IndexStorage($this->resource);
        self::assertSame([101], $storage->getProductIds(1, 0, 500));
        self::assertSame([], $storage->getProductIds(1, 101, 500));
        $row = ['product_id' => 101, 'store_id' => 3, 'availability' => 0, 'sales_value' => '12.5000', 'rating' => 90, 'needs_reindex' => 1];
        $storage->saveChanged([$row]);
        $row['store_id'] = 4;
        $storage->saveChanged([$row]);
        self::assertSame([101], $storage->getPendingIds(250, [3, 4]));
        self::assertSame('12.5000', $storage->getRows([101], 3)[101]['sales_value']);
        $storage->markPublished([101], [3, 4]);
        self::assertSame([], $storage->getPendingIds(250, [3, 4]));
        $row['availability'] = 1;
        $storage->saveChanged([$row]);
        self::assertSame([101], $storage->getPendingIds(250, [3, 4]));
        self::assertSame('0', $storage->getRows([101], 3)[101]['availability']);
        self::assertSame('1', $storage->getRows([101], 4)[101]['availability']);
    }

    public function testNativeStockAndBackordersWorkWithoutAnAmastyTableOrModule(): void
    {
        $this->product(101, 'simple');
        $this->product(102, 'simple');
        $this->db->update(self::PREFIX . 'cataloginventory_stock_item', ['qty' => 0, 'backorders' => 1], ['product_id = ?' => 102]);
        $this->db->query('DROP TABLE ' . self::PREFIX . 'amasty_preorder_product_index');
        self::assertSame([101 => 0, 102 => 1], $this->availabilitySource(false)->getRanks([101, 102], 3));
        // An enabled module whose optional table has not been installed is safe too.
        self::assertSame([101 => 0, 102 => 1], $this->availabilitySource(true)->getRanks([101, 102], 3));
    }

    public function testPublicationLeavesDisabledStoreRowsQueuedIncludingSharedProducts(): void
    {
        $this->product(101, 'simple');
        $this->product(102, 'simple');
        $storage = new IndexStorage($this->resource);
        $storage->saveChanged([
            ['product_id' => 101, 'store_id' => 3, 'availability' => 0, 'sales_value' => 2, 'rating' => 90, 'needs_reindex' => 1],
            ['product_id' => 101, 'store_id' => 4, 'availability' => 1, 'sales_value' => 2, 'rating' => 90, 'needs_reindex' => 1],
            ['product_id' => 102, 'store_id' => 4, 'availability' => 0, 'sales_value' => 0, 'rating' => 0, 'needs_reindex' => 1],
        ]);
        self::assertSame([], $storage->getPendingIds(250, []));
        self::assertSame([101], $storage->getPendingIds(250, [3]));
        $storage->markPublished([101], [3]);
        self::assertSame([], $storage->getPendingIds(250, [3]));
        self::assertSame([101, 102], $storage->getPendingIds(250, [4]));
        $storage->markPublished([101, 102], []);
        self::assertSame([101, 102], $storage->getPendingIds(250, [4]));
    }

    public function testDisabledAmastyDoesNotUseItsLeftoverIndexTable(): void
    {
        $this->product(101, 'simple');
        $this->insert('amasty_preorder_product_index', ['product_id' => 101, 'website_id' => 1]);
        self::assertSame([101 => 0], $this->availabilitySource(false)->getRanks([101], 3));
        self::assertSame([101 => 1], $this->availabilitySource(true)->getRanks([101], 3));
    }

    #[DataProvider('msiStocks')]
    public function testMsiUsesWebsiteStockReservationsAndBackorderLimits(int $stockId): void
    {
        $source = $this->availabilitySource(false, $stockId);
        foreach (range(101, 109) as $id) {
            $this->product($id, 'simple');
            $this->insert('inventory_stock_7', ['sku' => 'sku-' . $id, 'quantity' => 10, 'is_salable' => 1]);
        }
        foreach ([[101, -9], [101, -2], [101, 1], [102, -10], [103, -13], [104, -15], [105, -11], [106, -10]] as [$id, $qty]) {
            $this->insert('inventory_reservation', ['stock_id' => $stockId, 'sku' => 'sku-' . $id, 'quantity' => $qty]);
        }
        // Reservations belonging to another stock cannot consume this website's stock.
        $this->insert('inventory_reservation', ['stock_id' => 99, 'sku' => 'sku-107', 'quantity' => -1000]);
        $this->db->update(self::PREFIX . 'inventory_stock_7', ['sku' => 'SKU-101'], ['sku = ?' => 'sku-101']);
        $this->db->update(self::PREFIX . 'inventory_reservation', ['sku' => 'SKU-101'], ['sku = ?' => 'sku-101']);
        $this->db->update(self::PREFIX . 'cataloginventory_stock_item', ['backorders' => 1], ['product_id = ?' => 102]);
        $this->db->update(self::PREFIX . 'cataloginventory_stock_item', ['backorders' => 2, 'min_qty' => -5], ['product_id IN (?)' => [103, 104]]);
        $this->db->update(self::PREFIX . 'cataloginventory_stock_item', ['manage_stock' => 0], ['product_id = ?' => 105]);
        $this->db->update(self::PREFIX . 'cataloginventory_stock_item', ['backorders' => 2, 'min_qty' => -5], ['product_id = ?' => 106]);
        $this->db->update(self::PREFIX . 'catalog_product_entity_int', ['value' => 2], ['entity_id = ?' => 108]);
        $this->db->delete(self::PREFIX . 'catalog_product_website', ['product_id = ?' => 109]);
        self::assertSame([101 => 2, 102 => 1, 103 => 1, 104 => 2, 105 => 0, 106 => 1, 107 => 0, 108 => 2, 109 => 2],
            $source->getRanks(range(101, 109), 3));
        // Cancellation compensation restores immediately available stock on the next read.
        $this->insert('inventory_reservation', ['stock_id' => $stockId, 'sku' => 'sku-101', 'quantity' => 1]);
        self::assertSame([101 => 0], $source->getRanks([101], 3));
    }

    public static function msiStocks(): array
    {
        return ['MSI default stock' => [1], 'MSI custom website stock' => [7]];
    }

    public function testMsiConfigurablesChoosePurchasableChildrenAndRespectWebsiteStockAndPreorder(): void
    {
        $source = $this->availabilitySource(true, 7);
        foreach ([1000 => 'configurable', 1001 => 'simple', 1002 => 'simple', 1010 => 'bundle', 1020 => 'grouped'] as $id => $type) {
            $this->product($id, $type);
            $this->insert('inventory_stock_7', ['sku' => 'sku-' . $id, 'quantity' => 0, 'is_salable' => 1]);
        }
        foreach ([1001, 1002] as $child) {
            $this->insert('catalog_product_super_link', ['parent_id' => 1000, 'product_id' => $child]);
        }
        $this->db->update(self::PREFIX . 'inventory_stock_7', ['quantity' => 5], ['sku = ?' => 'sku-1001']);
        $this->db->update(self::PREFIX . 'cataloginventory_stock_item', ['backorders' => 1], ['product_id = ?' => 1002]);
        self::assertSame([1000 => 0, 1010 => 0, 1020 => 0], $source->getRanks([1000, 1010, 1020], 3));
        $this->insert('inventory_reservation', ['stock_id' => 7, 'sku' => 'sku-1001', 'quantity' => -5]);
        self::assertSame([1000 => 1], $source->getRanks([1000], 3));
        $this->db->update(self::PREFIX . 'catalog_product_entity_int', ['value' => 2], ['entity_id = ?' => 1002]);
        self::assertSame([1000 => 2], $source->getRanks([1000], 3));
        // Other website resolves default stock, where both children have physical stock.
        $this->insert('catalog_product_website', ['product_id' => 1000, 'website_id' => 2]);
        $this->insert('catalog_product_website', ['product_id' => 1001, 'website_id' => 2]);
        self::assertSame([1000 => 0], $this->availabilitySource(false, 1, 2)->getRanks([1000], 4));
        // Legacy stock must never fill missing custom-stock rows.
        $this->db->delete(self::PREFIX . 'inventory_stock_7', ['sku = ?' => 'sku-1010']);
        self::assertSame([1010 => 2], $source->getRanks([1010], 3));
        $this->insert('amasty_preorder_product_index', ['product_id' => 1020, 'website_id' => 1]);
        self::assertSame([1020 => 1], $source->getRanks([1020], 3));
        $this->db->update(self::PREFIX . 'inventory_stock_7', ['is_salable' => 0], ['sku = ?' => 'sku-1020']);
        self::assertSame([1020 => 2], $source->getRanks([1020], 3));
    }

    public function testOpenSearchRanksBeforePagingAndPreservesFiltersAndManualPriceSort(): void
    {
        $index = 'category-sorting-regression';
        $this->http('DELETE', '/' . $index, null, [200, 404]);
        try {
            $mapping = (new FieldMapping())->getFields() + ['category' => ['type' => 'keyword'],
                'willwright_units_sold' => ['type' => 'double'], 'price_0_1' => ['type' => 'double']];
            $this->http('PUT', '/' . $index, ['settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0],
                'mappings' => ['properties' => $mapping]]);
            $rows = [
                [1, 0, 20, 10, 10, 'A'], [2, 0, 10, 100, 100, 'A'],
                [3, 0, 10, 90, 300, 'A'], [4, 0, 10, 90, 200, 'A'], [5, 0, 10, 90, 200, 'A'],
                [6, 0, 0, 0, 5000, 'A'], [7, 1, 10000, 100, 10000, 'A'], [8, 2, 99999, 100, 99999, 'A'],
                [9, 0, 999, 100, 9999, 'B'], [10, 0, 10, 90, 200, 'A'],
            ];
            foreach ($rows as [$id, $availability, $sales, $rating, $price, $category]) {
                $this->http('PUT', "/$index/_doc/$id", [Config::ID_FIELD => $id,
                    Config::AVAILABILITY_FIELD => $availability, Config::SALES_FIELD => $sales,
                    Config::RATING_FIELD => $rating, 'price_0_1' => $price, 'category' => $category,
                    'willwright_units_sold' => 1000000 - $sales]); // Conflicting old unit scores must be ignored.
            }
            $this->http('POST', "/$index/_refresh");
            $sort = $this->recommendedSort();
            $actual = [];
            foreach ([0, 3, 6] as $from) {
                $page = $this->http('POST', "/$index/_search", [
                    'query' => ['term' => ['category' => 'A']], 'sort' => $sort, 'from' => $from, 'size' => 3,
                ]);
                self::assertSame(9, $page['hits']['total']['value']);
                array_push($actual, ...$this->hitIds($page));
            }
            self::assertSame([1, 2, 3, 4, 5, 10, 6, 7, 8], $actual);
            self::assertCount(9, array_unique($actual));
            $filtered = $this->http('POST', "/$index/_search", ['query' => ['bool' => ['filter' => [
                ['term' => ['category' => 'A']], ['term' => [Config::AVAILABILITY_FIELD => 0]],
            ]]], 'sort' => $sort, 'size' => 20]);
            self::assertSame([1, 2, 3, 4, 5, 10, 6], $this->hitIds($filtered));
            $manual = $this->http('POST', "/$index/_search", ['query' => ['term' => ['category' => 'A']],
                'sort' => [['price_0_1' => ['order' => 'asc']]], 'size' => 2]);
            self::assertSame([1, 2], $this->hitIds($manual));
            // New mapping is safe during deployment even if old documents lack ranking fields.
            $this->http('PUT', "/$index/_doc/11?refresh=true", ['category' => 'A', 'price_0_1' => 1]);
            $this->http('POST', "/$index/_search", ['query' => ['term' => ['category' => 'A']], 'sort' => $sort]);
        } finally {
            $this->http('DELETE', '/' . $index, null, [200, 404]);
        }
    }

    private function recommendedSort(): array
    {
        $attribute = $this->createStub(AttributeAdapter::class);
        $attributes = $this->createStub(AttributeProvider::class);
        $attributes->method('getByAttributeCode')->willReturn($attribute);
        $expressions = $this->createStub(ExpressionBuilder::class);
        $expressions->method('build')->willReturn(['price_0_1' => ['order' => 'desc']]);
        $request = new Request('catalog_view_container', 'catalogsearch_fulltext', $this->createStub(QueryInterface::class),
            0, 3, [], [], [['field' => Config::SORT_CODE, 'direction' => 'asc']]);
        return (new SearchSort($attributes, $expressions))->aroundGetSort($this->createStub(Sort::class),
            static fn() => [], $request);
    }

    private function availabilitySource(bool $amastyEnabled = true, ?int $msiStockId = null, int $websiteId = 1): AvailabilitySource
    {
        $stock = $this->createStub(StockConfigurationInterface::class);
        $stock->method('getDefaultScopeId')->willReturn(0);
        $stock->method('getManageStock')->willReturn(1);
        $stock->method('getBackorders')->willReturn(0);
        $stock->method('getMinQty')->willReturn(0);
        $stock->method('getIsQtyTypeIds')->willReturn(['simple' => 1, 'virtual' => 1, 'downloadable' => 1]);
        $store = $this->createStub(Store::class);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $website = $this->createStub(Website::class);
        $website->method('getCode')->willReturn('website-' . $websiteId);
        $store->method('getWebsite')->willReturn($website);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $attribute = (new ReflectionClass(Attribute::class))->newInstanceWithoutConstructor();
        $attribute->setData('attribute_id', 99);
        $eav = $this->createStub(EavConfig::class);
        $eav->method('getAttribute')->willReturn($attribute);
        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $pool = $this->createStub(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);
        $modules = $this->createStub(Manager::class);
        $modules->method('isEnabled')->willReturn($amastyEnabled);
        $config = $this->createStub(Config::class);
        $config->method('useAmastyPreorder')->willReturn(true);
        $preorders = new PreorderSource($this->resource, $modules, $config);
        $inventoryModules = $this->createStub(Manager::class);
        $inventoryModules->method('isEnabled')->willReturn($msiStockId !== null);
        if ($msiStockId !== null) {
            $msi = $this->msiSource($msiStockId, 'website-' . $websiteId);
        } else {
            $msi = $this->createMock(MsiStockSource::class);
            $msi->expects(self::never())->method('getData');
        }
        return new AvailabilitySource($this->resource, $stock, $stores, $eav, $pool, new Availability(), $preorders,
            new InventoryCompatibility($inventoryModules), $msi);
    }

    private function msiSource(int $stockId, string $websiteCode): MsiStockSource
    {
        if (!class_exists(\Magento\InventoryIndexer\Model\ResourceModel\GetStockItemsData::class)) {
            self::markTestSkipped('MSI packages absent; native MSI readers are exercised in the MSI CI job.');
        }
        $stock = $this->createStub(\Magento\InventoryApi\Api\Data\StockInterface::class);
        $stock->method('getStockId')->willReturn($stockId);
        $resolver = $this->createMock(\Magento\InventorySalesApi\Api\StockResolverInterface::class);
        $resolver->expects(self::atLeastOnce())->method('execute')->with('website', $websiteCode)->willReturn($stock);
        $default = $this->createStub(\Magento\InventoryCatalogApi\Api\DefaultStockProviderInterface::class);
        $default->method('getId')->willReturn(1);
        $table = $this->createStub(\Magento\InventoryIndexer\Model\StockIndexTableNameResolverInterface::class);
        $table->method('execute')->willReturn(self::PREFIX . 'inventory_stock_' . $stockId);
        $items = new \Magento\InventoryIndexer\Model\ResourceModel\GetStockItemsData($this->resource, $table, $default,
            $this->createStub(\Magento\InventoryIndexer\Model\ResourceModel\StockItemDataHandler::class));
        $objects = $this->createStub(ObjectManagerInterface::class);
        $objects->method('get')->willReturnCallback(static fn(string $type) => match ($type) {
            \Magento\InventorySalesApi\Api\StockResolverInterface::class => $resolver,
            \Magento\InventorySalesApi\Model\GetStockItemsDataInterface::class => $items,
            default => throw new RuntimeException('Unexpected MSI service: ' . $type),
        });
        return new MsiStockSource($this->resource, $objects);
    }

    private function resetFixtures(): void
    {
        $tables = [
            'willwright_category_sorting' => null,
            'catalog_product_entity' => 'entity_id INT UNSIGNED PRIMARY KEY, sku VARCHAR(64) UNIQUE, type_id VARCHAR(32)',
            'store' => 'store_id SMALLINT UNSIGNED PRIMARY KEY',
            'catalog_product_website' => 'product_id INT, website_id INT, PRIMARY KEY(product_id, website_id)',
            'catalog_product_super_link' => 'product_id INT, parent_id INT, PRIMARY KEY(product_id, parent_id)',
            'catalog_product_entity_int' => 'entity_id INT, attribute_id INT, store_id INT, value INT, PRIMARY KEY(entity_id, attribute_id, store_id)',
            'cataloginventory_stock_status' => 'product_id INT, website_id INT, stock_id INT, qty DECIMAL(12,4), stock_status INT, PRIMARY KEY(product_id, website_id, stock_id)',
            'cataloginventory_stock_item' => 'product_id INT, stock_id INT, qty DECIMAL(12,4), manage_stock INT, use_config_manage_stock INT, backorders INT, use_config_backorders INT, min_qty DECIMAL(12,4), use_config_min_qty INT, PRIMARY KEY(product_id, stock_id)',
            'amasty_preorder_product_index' => 'product_id INT, website_id INT, PRIMARY KEY(product_id, website_id)',
            'inventory_stock_7' => 'sku VARCHAR(64) PRIMARY KEY, quantity DECIMAL(12,4), is_salable INT',
            'inventory_reservation' => 'stock_id INT, sku VARCHAR(64), quantity DECIMAL(12,4)',
            'sales_order' => 'entity_id INT PRIMARY KEY, created_at DATETIME, state VARCHAR(32), base_grand_total DECIMAL(20,4), base_total_paid DECIMAL(20,4) NULL, store_id INT, customer_group_id INT, base_currency_code VARCHAR(3) DEFAULT \'GBP\', global_currency_code VARCHAR(3) DEFAULT \'GBP\', base_to_global_rate DECIMAL(20,4) DEFAULT 1',
            'sales_order_item' => 'item_id INT PRIMARY KEY, order_id INT, product_id INT, product_type VARCHAR(32) DEFAULT \'simple\', parent_item_id INT NULL, qty_ordered DECIMAL(12,4), qty_invoiced DECIMAL(12,4), qty_canceled DECIMAL(12,4), qty_refunded DECIMAL(12,4), base_row_total DECIMAL(20,4) DEFAULT 0, base_row_invoiced DECIMAL(20,4) DEFAULT 0, base_discount_amount DECIMAL(20,4) DEFAULT 0, base_discount_invoiced DECIMAL(20,4) DEFAULT 0, base_amount_refunded DECIMAL(20,4) DEFAULT 0, base_discount_refunded DECIMAL(20,4) DEFAULT 0, base_discount_tax_compensation_amount DECIMAL(20,4) DEFAULT 0, base_discount_tax_compensation_invoiced DECIMAL(20,4) DEFAULT 0, base_discount_tax_compensation_refunded DECIMAL(20,4) DEFAULT 0',
            'review_entity' => 'entity_id INT PRIMARY KEY, entity_code VARCHAR(32)',
            'review_entity_summary' => 'entity_pk_value INT, entity_type INT, store_id INT, reviews_count INT, rating_summary INT',
        ];
        foreach ($tables as $name => $columns) {
            $this->db->query('DROP TABLE IF EXISTS ' . self::PREFIX . $name);
        }
        foreach ($tables as $name => $columns) {
            if ($columns !== null) {
                $this->db->query('CREATE TABLE ' . self::PREFIX . $name . ' (' . $columns . ') ENGINE=InnoDB');
            }
        }
        // Create the new table from the shipped declarative schema, including its keys and types.
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/db_schema.xml');
        $parts = [];
        foreach ($xml->table->column as $column) {
            $attrs = $column->attributes();
            $type = (string)$column->attributes('xsi', true)['type'];
            $sqlType = match ($type) {
                'boolean' => 'TINYINT(1)', 'decimal' => 'DECIMAL(' . $attrs['precision'] . ',' . $attrs['scale'] . ')',
                default => strtoupper($type),
            };
            $parts[] = '`' . $attrs['name'] . '` ' . $sqlType . ((string)$attrs['unsigned'] === 'true' ? ' UNSIGNED' : '')
                . ((string)$attrs['nullable'] === 'false' ? ' NOT NULL' : ' NULL')
                . (isset($attrs['default']) ? ' DEFAULT ' . ((string)$attrs['default'] === 'true' ? '1' : (string)$attrs['default']) : '');
        }
        foreach ($xml->table->constraint as $constraint) {
            $attrs = $constraint->attributes();
            if ((string)$constraint->attributes('xsi', true)['type'] === 'primary') {
                $columns = [];
                foreach ($constraint->column as $column) { $columns[] = '`' . $column['name'] . '`'; }
                $parts[] = 'PRIMARY KEY (' . implode(',', $columns) . ')';
            } else {
                $parts[] = 'FOREIGN KEY (`' . $attrs['column'] . '`) REFERENCES ' . self::PREFIX . $attrs['referenceTable']
                    . ' (`' . $attrs['referenceColumn'] . '`) ON DELETE CASCADE';
            }
        }
        foreach ($xml->table->index as $index) {
            $columns = [];
            foreach ($index->column as $column) { $columns[] = '`' . $column['name'] . '`'; }
            $parts[] = 'INDEX (`' . implode('`,`', array_map(static fn($v) => trim($v, '`'), $columns)) . '`)';
        }
        $this->db->query('CREATE TABLE ' . self::PREFIX . IndexStorage::TABLE . ' (' . implode(',', $parts) . ') ENGINE=InnoDB');
        $this->insert('store', ['store_id' => 3]);
        $this->insert('store', ['store_id' => 4]);
    }

    private function product(int $id, string $type): void
    {
        $this->insert('catalog_product_entity', ['entity_id' => $id, 'sku' => 'sku-' . $id, 'type_id' => $type]);
        $this->insert('catalog_product_website', ['product_id' => $id, 'website_id' => 1]);
        $this->insert('catalog_product_entity_int', ['entity_id' => $id, 'attribute_id' => 99, 'store_id' => 0, 'value' => 1]);
        $this->insert('cataloginventory_stock_status', ['product_id' => $id, 'website_id' => 0, 'stock_id' => 1, 'qty' => 10, 'stock_status' => 1]);
        $this->insert('cataloginventory_stock_item', ['product_id' => $id, 'stock_id' => 1, 'qty' => 10, 'manage_stock' => 1,
            'use_config_manage_stock' => 0, 'backorders' => 0, 'use_config_backorders' => 0, 'min_qty' => 0, 'use_config_min_qty' => 0]);
    }

    private function item(int $id, int $order, int $product, float $qty, float $invoiced, float $canceled = 0, float $refunded = 0, ?int $parent = null, float $unitPrice = 10): void
    {
        $this->insert('sales_order_item', ['item_id' => $id, 'order_id' => $order, 'product_id' => $product,
            'qty_ordered' => $qty, 'qty_invoiced' => $invoiced, 'qty_canceled' => $canceled, 'qty_refunded' => $refunded, 'parent_item_id' => $parent,
            'base_row_total' => $qty * $unitPrice, 'base_row_invoiced' => $invoiced * $unitPrice,
            'base_amount_refunded' => $refunded * $unitPrice]);
    }

    private function insert(string $table, array $row): void
    {
        $this->db->insert(self::PREFIX . $table, $row);
    }

    private function hitIds(array $result): array
    {
        return array_map(static fn($hit) => (int)$hit['_id'], $result['hits']['hits']);
    }

    private function http(string $method, string $path, ?array $data = null, array $expected = [200, 201]): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'timeout' => 15,
            'ignore_errors' => true, 'header' => 'Content-Type: application/json',
            'content' => $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR)]]);
        $body = file_get_contents('http://127.0.0.1:' . $this->services['search_port'] . $path, false, $context);
        $headers = http_get_last_response_headers();
        $code = (int)explode(' ', $headers[0])[1];
        self::assertContains($code, $expected, 'OpenSearch request failed: ' . $body);
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
