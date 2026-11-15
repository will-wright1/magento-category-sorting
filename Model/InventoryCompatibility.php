<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model;

use Magento\Config\Model\Config\CommentInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager;

class InventoryCompatibility implements CommentInterface
{
    public const ERROR_MESSAGE = 'Category Sorting requires legacy CatalogInventory. Multi Source Inventory (MSI) is enabled, so Recommended sorting is unavailable with the bundled availability provider.';

    public function __construct(private Manager $modules)
    {
    }

    public function isSupported(): bool
    {
        foreach (['Magento_Inventory', 'Magento_InventorySales', 'Magento_InventoryIndexer'] as $module) {
            if ($this->modules->isEnabled($module)) {
                return false;
            }
        }
        return true;
    }

    public function assertSupported(): void
    {
        if (!$this->isSupported()) {
            throw new LocalizedException(__(self::ERROR_MESSAGE));
        }
    }

    public function getCommentText($elementValue): string
    {
        return $this->isSupported()
            ? (string)__('In-stock first, then net paid units over 180 days, approved product rating, and highest selling price. Other customer-selected sorts remain available.')
            : (string)__(self::ERROR_MESSAGE);
    }
}
