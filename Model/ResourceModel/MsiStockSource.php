<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;

/** Optional MSI services are resolved only when MSI is enabled; legacy installs need no MSI packages. */
class MsiStockSource
{
    public function __construct(
        private ResourceConnection $resource,
        private ObjectManagerInterface $objects
    ) {
    }

    /** @return array<string, array{quantity: float, is_salable: bool}> */
    public function getData(array $skus, string $websiteCode): array
    {
        $skus = array_values(array_unique($skus));
        if (!$skus) {
            return [];
        }
        // String service names deliberately avoid a compile-time dependency on optional MSI interfaces.
        $stockId = (int)$this->objects->get('Magento\InventorySalesApi\Api\StockResolverInterface')
            ->execute('website', $websiteCode)->getStockId();
        $items = $this->objects->get('Magento\InventorySalesApi\Model\GetStockItemsDataInterface')
            ->execute($skus, $stockId) ?? [];
        $items = $this->normalizeKeys($items);

        // Bulk equivalent of MSI's GetReservationsQuantityInterface; include order/cancellation compensations.
        $db = $this->resource->getConnection();
        $reservations = $db->fetchPairs($db->select()
            ->from($this->resource->getTableName('inventory_reservation'), ['sku', 'quantity' => new \Zend_Db_Expr('SUM(quantity)')])
            ->where('stock_id = ?', $stockId)->where('sku IN (?)', $skus)->group('sku'));
        $reservations = $this->normalizeKeys($reservations);
        $result = [];
        foreach ($skus as $sku) {
            $key = mb_strtolower((string)$sku, 'UTF-8');
            if (isset($items[$key])) {
                $result[$sku] = [
                    'quantity' => (float)$items[$key]['quantity'] + (float)($reservations[$key] ?? 0),
                    'is_salable' => (bool)$items[$key]['is_salable'],
                ];
            }
        }
        return $result;
    }

    private function normalizeKeys(array $rows): array
    {
        $result = [];
        foreach ($rows as $sku => $row) {
            $result[mb_strtolower((string)$sku, 'UTF-8')] = $row;
        }
        return $result;
    }
}
