<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model;

class Availability
{
    public const IN_STOCK = 0;
    public const PREORDER = 1;
    public const UNAVAILABLE = 2;

    public function classify(array $row, array $defaults): int
    {
        if (!(int)$row['enabled'] || !(int)$row['assigned'] || !(int)$row['salable']) {
            return self::UNAVAILABLE;
        }
        if ((int)$row['preorder']) {
            return self::PREORDER;
        }
        $manageStock = (int)$row['use_config_manage_stock'] ? $defaults['manage_stock'] : (bool)$row['manage_stock'];
        $backorders = (int)$row['use_config_backorders'] ? $defaults['backorders'] : (int)$row['backorders'];
        $minQty = (int)$row['use_config_min_qty'] ? $defaults['min_qty'] : (float)$row['min_qty'];
        if ($manageStock && $backorders > 0 && (float)$row['qty'] <= max(0.0, $minQty)) {
            return self::PREORDER;
        }
        return self::IN_STOCK;
    }

    public function configurable(int $parentRank, array $childRanks): int
    {
        // A disabled/unavailable parent cannot be bought, even if a child has stock.
        return $parentRank === self::UNAVAILABLE || !$childRanks
            ? self::UNAVAILABLE : min($childRanks);
    }

    /** MSI quantity is aggregated stock plus outstanding reservations, before subtracting min_qty. */
    public function classifyMsi(array $row, array $defaults, bool $quantityManaged): int
    {
        if (!$quantityManaged) {
            // Composite quantities are not physical stock; use their indexed status and child ranks instead.
            $row['use_config_manage_stock'] = 0;
            $row['manage_stock'] = 0;
        } else {
            $manageStock = (int)$row['use_config_manage_stock'] ? $defaults['manage_stock'] : (bool)$row['manage_stock'];
            $backorders = (int)$row['use_config_backorders'] ? $defaults['backorders'] : (int)$row['backorders'];
            $minQty = (int)$row['use_config_min_qty'] ? $defaults['min_qty'] : (float)$row['min_qty'];
            // Negative min_qty permits limited backorders, not immediately available stock.
            // An explicit purchasable Amasty preorder keeps its own indexed salability rules.
            if ($manageStock && !(int)$row['preorder'] && ($backorders === 0 || $minQty < 0)
                && (float)$row['qty'] <= $minQty) {
                $row['salable'] = 0;
            }
        }
        return $this->classify($row, $defaults);
    }
}
