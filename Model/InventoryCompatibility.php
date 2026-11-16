<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model;

use Magento\Config\Model\Config\CommentInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager;

class InventoryCompatibility implements CommentInterface
{
    public const ERROR_MESSAGE = 'Category Sorting detected an incomplete Multi Source Inventory (MSI) configuration. Enable the Inventory, InventoryCatalog, InventorySales, InventoryIndexer, InventoryConfiguration and InventoryReservations modules together, or disable MSI to use legacy CatalogInventory.';

    private const MSI_MODULES = [
        'Magento_Inventory', 'Magento_InventoryCatalog', 'Magento_InventorySales',
        'Magento_InventoryIndexer', 'Magento_InventoryConfiguration', 'Magento_InventoryReservations',
    ];

    public function __construct(private Manager $modules)
    {
    }

    public function isSupported(): bool
    {
        $enabled = array_filter(self::MSI_MODULES, fn(string $module): bool => $this->modules->isEnabled($module));
        return !$enabled || count($enabled) === count(self::MSI_MODULES);
    }

    public function isMsiEnabled(): bool
    {
        return $this->modules->isEnabled('Magento_InventorySales') && $this->isSupported();
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
