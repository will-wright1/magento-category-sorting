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

    /** Net paid product revenue, excluding tax/delivery, in the default base currency. */
    public function getTotals(DateTimeImmutable $asOf): array
    {
        $db = $this->resource->getConnection();
        $items = $db->quoteIdentifier($this->resource->getTableName('sales_order_item'));
        $orders = $db->quoteIdentifier($this->resource->getTableName('sales_order'));
        $links = $db->quoteIdentifier($this->resource->getTableName('catalog_product_super_link'));
        $asOf = $asOf->setTimezone(new DateTimeZone('UTC'));
        $excluded = $this->config->getExcludedOrderIds();
        $exclusion = $excluded ? ' AND o.entity_id NOT IN (' . implode(',', $excluded) . ')' : '';
        $currency = $this->config->getSalesCurrency();

        $ordered = 'GREATEST(0, COALESCE(i.qty_ordered, 0))';
        $remaining = "GREATEST(0, $ordered - GREATEST(0, COALESCE(i.qty_canceled, 0)))";
        // Row amounts exclude tax. Compensation restores the tax part of tax-inclusive discounts.
        $invoiced = 'GREATEST(0, COALESCE(i.base_row_invoiced, 0) - COALESCE(i.base_discount_invoiced, 0)
            + COALESCE(i.base_discount_tax_compensation_invoiced, 0))';
        $orderedValue = 'GREATEST(0, COALESCE(i.base_row_total, 0) - COALESCE(i.base_discount_amount, 0)
            + COALESCE(i.base_discount_tax_compensation_amount, 0))';
        $recognized = "CASE WHEN COALESCE(i.qty_invoiced, 0) > 0
            THEN $invoiced * LEAST(1, $remaining / i.qty_invoiced)
            ELSE $orderedValue * COALESCE($remaining / NULLIF($ordered, 0), 0) END";
        $refunded = 'GREATEST(0, COALESCE(i.base_amount_refunded, 0) - COALESCE(i.base_discount_refunded, 0)
            + COALESCE(i.base_discount_tax_compensation_refunded, 0))';
        // Use the order's historical conversion, never today's rate or its display-currency amounts.
        $rate = 'CASE WHEN o.base_currency_code = :base_currency THEN 1
            WHEN o.global_currency_code = :global_currency AND o.base_to_global_rate > 0
                THEN o.base_to_global_rate ELSE NULL END';

        // A configurable/fixed-price bundle owns its revenue. Dynamic bundle child revenue belongs
        // to the purchased bundle, not its component products. Parent and child amounts never add twice.
        $sql = "SELECT COALESCE(p.product_id, i.product_id) AS product_id,
                SUM(GREATEST(0, ($recognized) - $refunded) * o.sales_rate) AS sales_value
            FROM $items AS i INNER JOIN (
                SELECT o.entity_id, $rate AS sales_rate FROM $orders AS o
                WHERE o.created_at >= :since AND o.created_at <= :as_of
                    AND o.state IN ('processing', 'complete')
                    AND COALESCE(o.base_total_paid, 0) + 0.0001 >= o.base_grand_total
                    $exclusion
            ) AS o ON o.entity_id = i.order_id
            LEFT JOIN $items AS p ON p.item_id = i.parent_item_id AND p.order_id = i.order_id
            WHERE (i.parent_item_id IS NULL OR (p.parent_item_id IS NULL AND p.product_type = 'bundle'
                    AND COALESCE(p.base_row_total, 0) = 0 AND COALESCE(p.base_row_invoiced, 0) = 0))
                AND COALESCE(p.product_id, i.product_id) IS NOT NULL
                AND o.sales_rate IS NOT NULL
            GROUP BY COALESCE(p.product_id, i.product_id)";
        $totals = array_map('floatval', $db->fetchPairs($sql, [
            'since' => $asOf->modify('-' . Config::SALES_DAYS . ' days')->format('Y-m-d H:i:s'),
            'as_of' => $asOf->format('Y-m-d H:i:s'),
            'base_currency' => $currency,
            'global_currency' => $currency,
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
