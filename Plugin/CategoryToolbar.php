<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Plugin;

use Willwright\CategorySorting\Model\Config;
use Magento\Catalog\Block\Product\ProductList\Toolbar;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection;
use Magento\Framework\App\Request\Http;

class CategoryToolbar
{
    public function __construct(private Config $config, private Http $request)
    {
    }

    public function beforeSetCollection(Toolbar $subject, $collection): array
    {
        if ($this->request->getFullActionName() === 'catalog_category_view'
            && $collection instanceof Collection
            && $this->config->isEnabled((int)$collection->getStoreId())
        ) {
            $subject->setAvailableOrders([Config::SORT_CODE => __('Recommended')] + $subject->getAvailableOrders());
            // Set after the theme/category defaults. Magento still honours a valid customer-selected order.
            $subject->setDefaultOrder(Config::SORT_CODE);
            $subject->setDefaultDirection('desc');
            $subject->unsetData('_current_grid_order');
            $subject->unsetData('_current_grid_direction');
        }
        return [$collection];
    }

    public function afterGetCurrentDirection(Toolbar $subject, $result): string
    {
        return $subject->getCurrentOrder() === Config::SORT_CODE ? 'desc' : (string)$result;
    }
}
