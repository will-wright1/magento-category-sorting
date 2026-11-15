<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class RatingsSource
{
    public function __construct(private ResourceConnection $resource)
    {
    }

    public function getRatings(array $productIds, int $storeId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$ids) {
            return [];
        }
        $db = $this->resource->getConnection();
        $summary = $db->quoteIdentifier($this->resource->getTableName('review_entity_summary'));
        $entity = $db->quoteIdentifier($this->resource->getTableName('review_entity'));
        // The same approved-review aggregate and store scope used by Magento's product stars.
        return array_map('intval', $db->fetchPairs("SELECT s.entity_pk_value, MAX(s.rating_summary)
            FROM $summary AS s INNER JOIN $entity AS e ON e.entity_id = s.entity_type
            WHERE e.entity_code = 'product' AND s.store_id = :store_id AND s.reviews_count > 0
                AND s.entity_pk_value IN (" . implode(',', $ids) . ')
            GROUP BY s.entity_pk_value', ['store_id' => $storeId]));
    }
}
