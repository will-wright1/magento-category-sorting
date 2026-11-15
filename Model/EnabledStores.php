<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model;

use Magento\Store\Model\StoreManagerInterface;

class EnabledStores
{
    public function __construct(private StoreManagerInterface $stores, private Config $config)
    {
    }

    public function getStores(): array
    {
        $enabled = [];
        foreach ($this->stores->getStores() as $store) {
            if ($store->isActive() && $this->config->isEnabled((int)$store->getId())) {
                $enabled[] = $store;
            }
        }
        return $enabled;
    }

    public function getIds(): array
    {
        return array_map(static fn($store): int => (int)$store->getId(), $this->getStores());
    }
}
