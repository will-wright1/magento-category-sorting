<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model;

use DateTimeImmutable;
use DateTimeZone;
use Willwright\CategorySorting\Api\AvailabilityProviderInterface;
use Willwright\CategorySorting\Model\ResourceModel\IndexStorage;
use Willwright\CategorySorting\Model\ResourceModel\RatingsSource;
use Willwright\CategorySorting\Model\ResourceModel\SalesSource;

class Builder
{
    public function __construct(
        private IndexStorage $storage,
        private SalesSource $sales,
        private AvailabilityProviderInterface $availability,
        private RatingsSource $ratings,
        private EnabledStores $stores
    ) {
    }

    public function rebuild(bool $fullMetrics): void
    {
        $sales = null;
        foreach ($this->stores->getStores() as $store) {
            $storeId = (int)$store->getId();
            // Load shared sales once, only when there is a supported, enabled store to build.
            if ($sales === null) {
                $sales = $fullMetrics ? $this->sales->getTotals(new DateTimeImmutable('now', new DateTimeZone('UTC'))) : [];
            }
            $afterId = 0;
            while ($ids = $this->storage->getProductIds((int)$store->getWebsiteId(), $afterId, 500)) {
                $old = $this->storage->getRows($ids, $storeId);
                $availability = $this->availability->getRanks($ids, $storeId);
                $ratings = $fullMetrics ? $this->ratings->getRatings($ids, $storeId) : [];
                $changes = [];
                foreach ($ids as $id) {
                    $row = [
                        'product_id' => $id, 'store_id' => $storeId,
                        'availability' => $availability[$id] ?? Availability::UNAVAILABLE,
                        'sales_value' => number_format($fullMetrics ? ($sales[$id] ?? 0) : (float)($old[$id]['sales_value'] ?? 0), 4, '.', ''),
                        'rating' => $fullMetrics ? ($ratings[$id] ?? 0) : (int)($old[$id]['rating'] ?? 0),
                        'needs_reindex' => 1,
                    ];
                    if (!isset($old[$id])
                        || (int)$old[$id]['availability'] !== $row['availability']
                        || number_format((float)($old[$id]['sales_value'] ?? 0), 4, '.', '') !== $row['sales_value']
                        || (int)$old[$id]['rating'] !== $row['rating']
                    ) {
                        $changes[] = $row;
                    }
                }
                $this->storage->saveChanged($changes);
                $afterId = max($ids);
            }
        }
    }
}
