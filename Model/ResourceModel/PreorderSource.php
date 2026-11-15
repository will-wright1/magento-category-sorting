<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager;
use Willwright\CategorySorting\Model\Config;

/** Optional Amasty integration; no Amasty classes or tables are required. */
class PreorderSource
{
    public function __construct(
        private ResourceConnection $resource,
        private Manager $modules,
        private Config $config
    ) {
    }

    /** @return array<int, bool> */
    public function getProductIds(array $productIds, int $websiteId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$ids || !$this->config->useAmastyPreorder() || !$this->modules->isEnabled('Amasty_Preorder')) {
            return [];
        }
        $db = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('amasty_preorder_product_index');
        if (!$db->isTableExists($tableName)) {
            return [];
        }
        $table = $db->quoteIdentifier($tableName);
        $preorders = $db->fetchCol("SELECT product_id FROM $table WHERE website_id = :website_id
            AND product_id IN (" . implode(',', $ids) . ')', ['website_id' => $websiteId]);
        return array_fill_keys(array_map('intval', $preorders), true);
    }
}
