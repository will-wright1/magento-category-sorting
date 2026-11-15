<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model\ResourceModel;

use DateTimeImmutable;
use DateTimeZone;
use Willwright\CategorySorting\Model\Config;
use Magento\Framework\App\ResourceConnection;

class SalesSource
{
    public function __construct(private ResourceConnection $resource, private Config $config)
    {
    }

    public function getTotals(DateTimeImmutable $asOf): array
    {
        $db = $this->resource->getConnection();
        $items = $db->quoteIdentifier($this->resource->getTableName('sales_order_item'));
        $orders = $db->quoteIdentifier($this->resource->getTableName('sales_order'));
        $links = $db->quoteIdentifier($this->resource->getTableName('catalog_product_super_link'));
        $asOf = $asOf->setTimezone(new DateTimeZone('UTC'));
        $excluded = $this->config->getExcludedOrderIds();
        $exclusion = $excluded ? ' AND o.entity_id NOT IN (' . implode(',', $excluded) . ')' : '';

        // No channel, customer-group or store restriction: POS, trade and web orders all count.
        // Top-level items avoid counting both configurable/bundle parents and their children.
        // Fully paid POS orders without invoices count too; partial payments and unpaid POs do not.
        $sql = "SELECT i.product_id, SUM(GREATEST(0,
            LEAST(GREATEST(0, COALESCE(i.qty_ordered, 0) - COALESCE(i.qty_canceled, 0)),
                CASE WHEN COALESCE(i.qty_invoiced, 0) > 0 THEN i.qty_invoiced
                    ELSE GREATEST(0, COALESCE(i.qty_ordered, 0) - COALESCE(i.qty_canceled, 0)) END)
                - COALESCE(i.qty_refunded, 0))) AS qty
            FROM $items AS i INNER JOIN $orders AS o ON o.entity_id = i.order_id
            WHERE i.parent_item_id IS NULL AND i.product_id IS NOT NULL
                AND o.created_at >= :since AND o.created_at <= :as_of
                AND o.state IN ('processing', 'complete')
                AND COALESCE(o.base_total_paid, 0) + 0.0001 >= o.base_grand_total
                $exclusion
            GROUP BY i.product_id";
        $totals = array_map('floatval', $db->fetchPairs($sql, [
            'since' => $asOf->modify('-' . Config::SALES_DAYS . ' days')->format('Y-m-d H:i:s'),
            'as_of' => $asOf->format('Y-m-d H:i:s'),
        ]));

        // Standalone sales of a variant also contribute to its configurable parent.
        // Read only the original totals so this cannot recursively count parent sales twice.
        $result = $totals;
        foreach ($db->fetchAll("SELECT product_id, parent_id FROM $links") as $link) {
            $child = (int)$link['product_id'];
            $parent = (int)$link['parent_id'];
            if (isset($totals[$child])) {
                $result[$parent] = ($result[$parent] ?? 0.0) + $totals[$child];
            }
        }
        return $result;
    }
}
