<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class IndexStorage
{
    public const TABLE = 'willwright_category_sorting';

    public function __construct(private ResourceConnection $resource)
    {
    }

    public function getProductIds(int $websiteId, int $afterId, int $limit): array
    {
        $db = $this->resource->getConnection();
        $table = $db->quoteIdentifier($this->resource->getTableName('catalog_product_website'));
        return array_map('intval', $db->fetchCol("SELECT product_id FROM $table
            WHERE website_id = :website_id AND product_id > :after_id ORDER BY product_id ASC LIMIT " . (int)$limit,
            ['website_id' => $websiteId, 'after_id' => $afterId]));
    }

    public function getRows(array $productIds, int $storeId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$ids) {
            return [];
        }
        $db = $this->resource->getConnection();
        $table = $db->quoteIdentifier($this->resource->getTableName(self::TABLE));
        $rows = $db->fetchAll("SELECT * FROM $table WHERE store_id = :store_id AND product_id IN ("
            . implode(',', $ids) . ')', ['store_id' => $storeId]);
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['product_id']] = $row;
        }
        return $result;
    }

    public function saveChanged(array $rows): void
    {
        if ($rows) {
            $this->resource->getConnection()->insertOnDuplicate(
                $this->resource->getTableName(self::TABLE), $rows,
                ['availability', 'sales_value', 'rating', 'needs_reindex']
            );
        }
    }

    public function getPendingIds(int $limit, array $storeIds): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (!$storeIds) {
            return [];
        }
        $db = $this->resource->getConnection();
        $table = $db->quoteIdentifier($this->resource->getTableName(self::TABLE));
        return array_map('intval', $db->fetchCol("SELECT DISTINCT product_id FROM $table
            WHERE needs_reindex = 1 AND store_id IN (" . implode(',', $storeIds) . ')
            ORDER BY product_id LIMIT ' . (int)$limit));
    }

    public function markPublished(array $ids, array $storeIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if ($ids && $storeIds) {
            $this->resource->getConnection()->update($this->resource->getTableName(self::TABLE),
                ['needs_reindex' => 0], ['product_id IN (?)' => $ids, 'store_id IN (?)' => $storeIds]);
        }
    }
}
