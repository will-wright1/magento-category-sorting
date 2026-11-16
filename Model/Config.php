<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const SALES_DAYS = 180;
    public const XML_PATH_ENABLED = 'willwright_category_sorting/general/enabled';
    public const SORT_CODE = 'willwright_recommended';
    public const AVAILABILITY_FIELD = 'willwright_availability';
    public const SALES_FIELD = 'willwright_sales_value';
    public const RATING_FIELD = 'willwright_product_rating';
    public const ID_FIELD = 'willwright_product_id';

    public function __construct(private ScopeConfigInterface $scopeConfig, private InventoryCompatibility $compatibility)
    {
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId
        ) && $this->compatibility->isSupported();
    }

    public function getExcludedOrderIds(): array
    {
        $value = (string)$this->scopeConfig->getValue('willwright_category_sorting/general/excluded_order_ids');
        return array_values(array_unique(array_filter(array_map(
            static fn(string $id): int => ctype_digit(trim($id)) ? (int)trim($id) : 0,
            explode(',', $value)
        ))));
    }

    public function useAmastyPreorder(): bool
    {
        return $this->scopeConfig->isSetFlag('willwright_category_sorting/general/use_amasty_preorder');
    }

    public function getSalesCurrency(): string
    {
        // Default-scope base currency is Magento's global reporting currency.
        return strtoupper(trim((string)$this->scopeConfig->getValue('currency/options/base')));
    }
}
