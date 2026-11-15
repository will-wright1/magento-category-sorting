<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model\ResourceModel;

use Willwright\CategorySorting\Model\Availability;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\StoreManagerInterface;
use Willwright\CategorySorting\Api\AvailabilityProviderInterface;
use Willwright\CategorySorting\Model\InventoryCompatibility;

/** Bulk reads for Magento's legacy CatalogInventory (MSI is not supported). */
class AvailabilitySource implements AvailabilityProviderInterface
{
    public function __construct(
        private ResourceConnection $resource,
        private StockConfigurationInterface $stockConfig,
        private StoreManagerInterface $stores,
        private EavConfig $eavConfig,
        private MetadataPool $metadataPool,
        private Availability $availability,
        private PreorderSource $preorders,
        private InventoryCompatibility $compatibility
    ) {
    }

    public function getRanks(array $productIds, int $storeId): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }
        $this->compatibility->assertSupported();
        $db = $this->resource->getConnection();
        $table = fn(string $name): string => $db->quoteIdentifier($this->resource->getTableName($name));
        $links = $db->fetchAll('SELECT parent_id, product_id FROM ' . $table('catalog_product_super_link')
            . ' WHERE parent_id IN (' . implode(',', $productIds) . ')');
        $allIds = $productIds;
        foreach ($links as $link) {
            $allIds[] = (int)$link['product_id'];
        }
        $idList = implode(',', array_unique($allIds));
        $websiteId = (int)$this->stores->getStore($storeId)->getWebsiteId();
        $stockScope = (int)$this->stockConfig->getDefaultScopeId();
        $statusId = (int)$this->eavConfig->getAttribute('catalog_product', 'status')->getAttributeId();
        $linkField = $db->quoteIdentifier($this->metadataPool->getMetadata(ProductInterface::class)->getLinkField());

        $sql = 'SELECT p.entity_id, p.type_id, (COALESCE(s.value, d.value, 2) = 1) AS enabled,
                (w.product_id IS NOT NULL) AS assigned, COALESCE(ss.stock_status, 0) AS salable,
                COALESCE(si.qty, 0) AS qty, COALESCE(si.manage_stock, 1) AS manage_stock,
                COALESCE(si.use_config_manage_stock, 1) AS use_config_manage_stock,
                COALESCE(si.backorders, 0) AS backorders,
                COALESCE(si.use_config_backorders, 1) AS use_config_backorders,
                COALESCE(si.min_qty, 0) AS min_qty, COALESCE(si.use_config_min_qty, 1) AS use_config_min_qty
            FROM ' . $table('catalog_product_entity') . ' AS p
            LEFT JOIN ' . $table('catalog_product_website') . " AS w
                ON w.product_id = p.entity_id AND w.website_id = $websiteId
            LEFT JOIN " . $table('catalog_product_entity_int') . " AS d
                ON d.$linkField = p.$linkField AND d.attribute_id = $statusId AND d.store_id = 0
            LEFT JOIN " . $table('catalog_product_entity_int') . " AS s
                ON s.$linkField = p.$linkField AND s.attribute_id = $statusId AND s.store_id = $storeId
            LEFT JOIN " . $table('cataloginventory_stock_status') . " AS ss
                ON ss.product_id = p.entity_id AND ss.stock_id = 1 AND ss.website_id = $stockScope
            LEFT JOIN " . $table('cataloginventory_stock_item') . ' AS si
                ON si.product_id = p.entity_id AND si.stock_id = 1
            WHERE p.entity_id IN (' . $idList . ')';
        $defaults = [
            'manage_stock' => (bool)$this->stockConfig->getManageStock($storeId),
            'backorders' => (int)$this->stockConfig->getBackorders($storeId),
            'min_qty' => (float)$this->stockConfig->getMinQty($storeId),
        ];
        $rows = [];
        $ranks = [];
        $preorders = $this->preorders->getProductIds($allIds, $websiteId);
        foreach ($db->fetchAll($sql) as $row) {
            $id = (int)$row['entity_id'];
            $row['preorder'] = isset($preorders[$id]) ? 1 : 0;
            $rows[$id] = $row;
            $ranks[$id] = $this->availability->classify($row, $defaults);
        }
        $children = [];
        foreach ($links as $link) {
            $children[(int)$link['parent_id']][] = $ranks[(int)$link['product_id']] ?? Availability::UNAVAILABLE;
        }
        $result = [];
        foreach ($productIds as $id) {
            $rank = $ranks[$id] ?? Availability::UNAVAILABLE;
            $result[$id] = ($rows[$id]['type_id'] ?? '') === 'configurable'
                ? $this->availability->configurable($rank, $children[$id] ?? []) : $rank;
        }
        return $result;
    }
}
