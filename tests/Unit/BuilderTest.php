<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Willwright\CategorySorting\Model\Builder;
use Willwright\CategorySorting\Model\EnabledStores;
use Willwright\CategorySorting\Model\Config;
use Willwright\CategorySorting\Api\AvailabilityProviderInterface;
use Willwright\CategorySorting\Model\ResourceModel\IndexStorage;
use Willwright\CategorySorting\Model\ResourceModel\RatingsSource;
use Willwright\CategorySorting\Model\ResourceModel\SalesSource;
use Willwright\CategorySorting\Search\FieldsProvider;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;

final class BuilderTest extends TestCase
{
    public function testNightlyRefreshResetsExpiredSalesAndRemovedRatingsAndQueuesOnlyChanges(): void
    {
        [$builder, $storage, $sales, $availability, $ratings] = $this->builder();
        $sales->expects(self::once())->method('getTotals')->willReturn([1 => 2.0]);
        $availability->method('getRanks')->with([1, 2, 3], 3)->willReturn([1 => 0, 2 => 1, 3 => 2]);
        $ratings->method('getRatings')->with([1, 2, 3], 3)->willReturn([1 => 90]);
        $storage->method('getRows')->willReturn([
            1 => ['availability' => '0', 'units_sold' => '2.0000', 'rating' => '90', 'needs_reindex' => 0],
            2 => ['availability' => '1', 'units_sold' => '5.0000', 'rating' => '80', 'needs_reindex' => 1],
        ]);
        $storage->expects(self::once())->method('saveChanged')->with([
            ['product_id' => 2, 'store_id' => 3, 'availability' => 1, 'units_sold' => '0.0000', 'rating' => 0, 'needs_reindex' => 1],
            ['product_id' => 3, 'store_id' => 3, 'availability' => 2, 'units_sold' => '0.0000', 'rating' => 0, 'needs_reindex' => 1],
        ]);
        $builder->rebuild(true);
    }

    public function testStockRefreshPreservesSalesAndRatings(): void
    {
        [$builder, $storage, $sales, $availability, $ratings] = $this->builder();
        $sales->expects(self::never())->method('getTotals');
        $ratings->expects(self::never())->method('getRatings');
        $availability->expects(self::once())->method('getRanks')->with([1, 2, 3], 3)->willReturn([1 => 1, 2 => 0, 3 => 2]);
        $storage->method('getRows')->willReturn([
            1 => ['availability' => 0, 'units_sold' => '4.5000', 'rating' => 88],
            2 => ['availability' => 0, 'units_sold' => '3.0000', 'rating' => 90],
            3 => ['availability' => 2, 'units_sold' => '0.0000', 'rating' => 0],
        ]);
        $storage->expects(self::once())->method('saveChanged')->with([
            ['product_id' => 1, 'store_id' => 3, 'availability' => 1, 'units_sold' => '4.5000', 'rating' => 88, 'needs_reindex' => 1],
        ]);
        $builder->rebuild(false);
    }

    public function testSearchFieldsUseCurrentStockAndRatingsAndNumericDefaultsForNewProducts(): void
    {
        $storage = $this->createStub(IndexStorage::class);
        $storage->method('getRows')->willReturn([1 => ['units_sold' => '12.5000']]);
        $availability = $this->createStub(AvailabilityProviderInterface::class);
        $availability->method('getRanks')->willReturn([1 => 1]);
        $ratings = $this->createStub(RatingsSource::class);
        $ratings->method('getRatings')->willReturn([1 => 95]);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $provider = new FieldsProvider($storage, $availability, $ratings, $config);
        self::assertSame([
            1 => [Config::AVAILABILITY_FIELD => 1, Config::SALES_FIELD => 12.5, Config::RATING_FIELD => 95, Config::ID_FIELD => 1],
            2 => [Config::AVAILABILITY_FIELD => 2, Config::SALES_FIELD => 0.0, Config::RATING_FIELD => 0, Config::ID_FIELD => 2],
        ], $provider->getFields([1, 2], 3));
        self::assertSame([], $provider->getFields([], 3));
    }

    public function testDisabledStoresDoNotRunAnyRankingReadsOrWrites(): void
    {
        $stores = $this->createStub(EnabledStores::class);
        $stores->method('getStores')->willReturn([]);
        $storage = $this->createMock(IndexStorage::class);
        $storage->expects(self::never())->method('getProductIds');
        $storage->expects(self::never())->method('getRows');
        $storage->expects(self::never())->method('saveChanged');
        $sales = $this->createMock(SalesSource::class);
        $sales->expects(self::never())->method('getTotals');
        $availability = $this->createMock(AvailabilityProviderInterface::class);
        $availability->expects(self::never())->method('getRanks');
        $ratings = $this->createMock(RatingsSource::class);
        $ratings->expects(self::never())->method('getRatings');
        $builder = new Builder($storage, $sales, $availability, $ratings, $stores);
        $builder->rebuild(true);
        $builder->rebuild(false);
    }

    public function testDisabledStoreSearchIndexingDoesNotReadRankingData(): void
    {
        $storage = $this->createMock(IndexStorage::class);
        $storage->expects(self::never())->method('getRows');
        $availability = $this->createMock(AvailabilityProviderInterface::class);
        $availability->expects(self::never())->method('getRanks');
        $ratings = $this->createMock(RatingsSource::class);
        $ratings->expects(self::never())->method('getRatings');
        $config = $this->createMock(Config::class);
        $config->expects(self::once())->method('isEnabled')->with(4)->willReturn(false);
        self::assertSame([], (new FieldsProvider($storage, $availability, $ratings, $config))->getFields([1, 2], 4));
    }

    private function builder(): array
    {
        $storage = $this->createMock(IndexStorage::class);
        $storage->method('getProductIds')->willReturnOnConsecutiveCalls([1, 2, 3], []);
        $sales = $this->createMock(SalesSource::class);
        $availability = $this->createMock(AvailabilityProviderInterface::class);
        $ratings = $this->createMock(RatingsSource::class);
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $store->method('isActive')->willReturn(true);
        $store->method('getWebsiteId')->willReturn(1);
        $stores = $this->createStub(EnabledStores::class);
        $stores->method('getStores')->willReturn([$store]);
        return [new Builder($storage, $sales, $availability, $ratings, $stores), $storage, $sales, $availability, $ratings];
    }
}
