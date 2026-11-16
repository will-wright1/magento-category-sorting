<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Search;

use Willwright\CategorySorting\Model\Availability;
use Willwright\CategorySorting\Model\Config;
use Willwright\CategorySorting\Api\AvailabilityProviderInterface;
use Willwright\CategorySorting\Model\ResourceModel\IndexStorage;
use Willwright\CategorySorting\Model\ResourceModel\RatingsSource;
use Magento\AdvancedSearch\Model\Adapter\DataMapper\AdditionalFieldsProviderInterface;

class FieldsProvider implements AdditionalFieldsProviderInterface
{
    public function __construct(
        private IndexStorage $storage,
        private AvailabilityProviderInterface $availability,
        private RatingsSource $ratings,
        private Config $config
    ) {
    }

    public function getFields(array $productIds, $storeId): array
    {
        if (!$productIds || !$this->config->isEnabled((int)$storeId)) {
            return [];
        }
        $storeId = (int)$storeId;
        $rows = $this->storage->getRows($productIds, $storeId);
        // Ordinary product/stock reindexing also gets current availability and review stars.
        $availability = $this->availability->getRanks($productIds, $storeId);
        $ratings = $this->ratings->getRatings($productIds, $storeId);
        $result = [];
        foreach ($productIds as $id) {
            $result[(int)$id] = [
                Config::AVAILABILITY_FIELD => $availability[$id] ?? Availability::UNAVAILABLE,
                Config::SALES_FIELD => (float)($rows[$id]['sales_value'] ?? 0),
                Config::RATING_FIELD => $ratings[$id] ?? 0,
                Config::ID_FIELD => (int)$id,
            ];
        }
        return $result;
    }
}
