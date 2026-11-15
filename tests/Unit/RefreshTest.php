<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Willwright\CategorySorting\Model\Builder;
use Willwright\CategorySorting\Model\EnabledStores;
use Willwright\CategorySorting\Model\Refresh;
use Willwright\CategorySorting\Model\RefreshStatus;
use Willwright\CategorySorting\Model\ResourceModel\IndexStorage;
use Magento\Catalog\Model\Category;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RefreshTest extends TestCase
{
    public function testDisabledRefreshDoesNotLockReadFlagsBuildPublishOrMarkMetricsFresh(): void
    {
        $stores = $this->createStub(EnabledStores::class);
        $stores->method('getIds')->willReturn([]);
        $builder = $this->createMock(Builder::class);
        $builder->expects(self::never())->method('rebuild');
        $storage = $this->createMock(IndexStorage::class);
        $storage->expects(self::never())->method('getPendingIds');
        $storage->expects(self::never())->method('markPublished');
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects(self::never())->method('lock');
        $locks->expects(self::never())->method('unlock');
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects(self::never())->method('get');
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::never())->method('dispatch');
        $status = $this->createMock(RefreshStatus::class);
        $status->expects(self::never())->method('isDue');
        $status->expects(self::never())->method('markFresh');
        $refresh = new Refresh($builder, $storage, $locks, $registry, $events, $status, $stores);
        $refresh->execute(true);
        $refresh->execute(false);
    }

    public function testPublishesPendingIdsThenInvalidatesCategoryCacheAndAcknowledges(): void
    {
        [$service, $builder, $storage, $locks, $registry, $events] = $this->service();
        $builder->expects(self::once())->method('rebuild')->with(true);
        $search = $this->createMock(IndexerInterface::class);
        $search->method('isWorking')->willReturn(false);
        $search->method('isInvalid')->willReturn(false);
        $registry->method('get')->with('catalogsearch_fulltext')->willReturn($search);
        $storage->expects(self::exactly(2))->method('getPendingIds')->with(250, [3])->willReturnOnConsecutiveCalls([4, 8], []);
        $steps = [];
        $search->expects(self::once())->method('reindexList')->with([4, 8])->willReturnCallback(function () use (&$steps) { $steps[] = 'index'; });
        $events->expects(self::once())->method('dispatch')->with('clean_cache_by_tags', self::callback(function ($data) use (&$steps) {
            self::assertInstanceOf(CacheContext::class, $data['object']);
            self::assertContains(Category::CACHE_TAG, $data['object']->getIdentities());
            $steps[] = 'cache';
            return true;
        }));
        $storage->expects(self::once())->method('markPublished')->with([4, 8], [3])->willReturnCallback(function () use (&$steps) { $steps[] = 'ack'; });
        $locks->expects(self::once())->method('unlock');
        $service->execute(true);
        self::assertSame(['index', 'cache', 'ack'], $steps);
    }

    public function testFailedSearchKeepsQueueAndReleasesLock(): void
    {
        [$service, $builder, $storage, $locks, $registry, $events] = $this->service();
        $builder->expects(self::once())->method('rebuild')->with(false);
        $search = $this->createMock(IndexerInterface::class);
        $search->method('isWorking')->willReturn(false);
        $search->method('isInvalid')->willReturn(false);
        $search->expects(self::once())->method('reindexList')->willThrowException(new RuntimeException('Search unavailable'));
        $registry->expects(self::once())->method('get')->willReturn($search);
        $storage->method('getPendingIds')->willReturn([4]);
        $storage->expects(self::never())->method('markPublished');
        $events->expects(self::never())->method('dispatch');
        $locks->expects(self::once())->method('unlock');
        $this->expectExceptionMessage('Search unavailable');
        $service->execute(false);
    }

    public function testDefersPublishingWhileFullSearchIndexIsWorking(): void
    {
        [$service, $builder, $storage, $locks, $registry, $events] = $this->service();
        $builder->expects(self::once())->method('rebuild')->with(false);
        $search = $this->createStub(IndexerInterface::class);
        $search->method('isWorking')->willReturn(true);
        $registry->expects(self::once())->method('get')->willReturn($search);
        $events->expects(self::never())->method('dispatch');
        $storage->expects(self::never())->method('getPendingIds');
        $locks->expects(self::once())->method('unlock');
        $service->execute(false);
    }

    public function testIndexerBuildDoesNotRecursivelyCallSearch(): void
    {
        [$service, $builder, $storage, $locks, $registry, $events] = $this->service();
        $builder->expects(self::once())->method('rebuild')->with(true);
        $registry->expects(self::never())->method('get');
        $storage->expects(self::never())->method('getPendingIds');
        $locks->expects(self::once())->method('unlock');
        $events->expects(self::never())->method('dispatch');
        $service->execute(true, false);
    }

    public function testConcurrentBuildCannotBeMarkedSuccessful(): void
    {
        $builder = $this->createMock(Builder::class);
        $builder->expects(self::never())->method('rebuild');
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(false);
        $locks->expects(self::never())->method('unlock');
        $service = new Refresh($builder, $this->createStub(IndexStorage::class), $locks,
            $this->createStub(IndexerRegistry::class), $this->createStub(ManagerInterface::class),
            $this->createStub(RefreshStatus::class), $this->enabledStores());
        $this->expectExceptionMessage('already refreshing');
        $service->execute(true);
    }

    public function testFiveMinuteJobRetriesOverdueDailyMetrics(): void
    {
        [$service, $builder, $storage, $locks, $registry, $events] = $this->service(true);
        $builder->expects(self::once())->method('rebuild')->with(true);
        $storage->expects(self::never())->method('getPendingIds');
        $locks->expects(self::once())->method('unlock');
        $registry->expects(self::never())->method('get');
        $events->expects(self::never())->method('dispatch');
        $service->execute(false, false);
    }

    private function enabledStores(): EnabledStores
    {
        $stores = $this->createStub(EnabledStores::class);
        $stores->method('getIds')->willReturn([3]);
        return $stores;
    }

    private function service(bool $due = false): array
    {
        $builder = $this->createMock(Builder::class);
        $storage = $this->createMock(IndexStorage::class);
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $registry = $this->createMock(IndexerRegistry::class);
        $events = $this->createMock(ManagerInterface::class);
        $status = $this->createStub(RefreshStatus::class);
        $status->method('isDue')->willReturn($due);
        return [new Refresh($builder, $storage, $locks, $registry, $events, $status, $this->enabledStores()), $builder, $storage, $locks, $registry, $events];
    }
}
