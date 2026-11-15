<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model;

use Willwright\CategorySorting\Model\ResourceModel\IndexStorage;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Lock\LockManagerInterface;

class Refresh
{
    private const LOCK = 'willwright_category_sorting';

    public function __construct(
        private Builder $builder,
        private IndexStorage $storage,
        private LockManagerInterface $locks,
        private IndexerRegistry $indexers,
        private ManagerInterface $events,
        private RefreshStatus $status,
        private EnabledStores $stores
    ) {
    }

    public function execute(bool $fullMetrics, bool $publish = true): void
    {
        $storeIds = $this->stores->getIds();
        if (!$storeIds) {
            return;
        }
        if (!$this->locks->lock(self::LOCK, 0)) {
            // Do not mark a CLI indexer successful while another build owns the lock.
            throw new \RuntimeException('Category ranking is already refreshing; retry after it finishes.');
        }
        try {
            // A failed/missed nightly job is retried by the five-minute job, including on first install.
            $fullMetrics = $fullMetrics || $this->status->isDue();
            $this->builder->rebuild($fullMetrics);
            if ($fullMetrics) {
                $this->status->markFresh();
            }
            if ($publish) {
                $this->publish($storeIds);
            }
        } finally {
            $this->locks->unlock(self::LOCK);
        }
    }

    private function publish(array $storeIds): void
    {
        $search = $this->indexers->get('catalogsearch_fulltext');
        // A full rebuild owns the search alias/mapping. Keep pending IDs until it finishes.
        if ($search->isWorking() || $search->isInvalid()) {
            return;
        }
        while ($ids = $this->storage->getPendingIds(250, $storeIds)) {
            $search->reindexList($ids);
            // Invalidate categories as well as product pages: an off-page product can move onto page 1.
            $context = new CacheContext();
            $context->registerTags([Category::CACHE_TAG]);
            $context->registerEntities(Product::CACHE_TAG, $ids);
            $this->events->dispatch('clean_cache_by_tags', ['object' => $context]);
            // If indexing or cache invalidation fails, the queue remains available for the next cron run.
            $this->storage->markPublished($ids, $storeIds);
        }
    }
}
