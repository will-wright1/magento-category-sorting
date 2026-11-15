<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Willwright\CategorySorting\Model\Config;
use Willwright\CategorySorting\Model\EnabledStores;
use Willwright\CategorySorting\Model\InventoryCompatibility;

final class EnabledStoresTest extends TestCase
{
    public function testIncludesOnlyActiveStoresWithSortingEnabledAtTheirScope(): void
    {
        $stores = $this->stores();
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnMap([[3, true], [4, false], [5, true]]);
        $enabled = new EnabledStores($stores, $config);
        self::assertSame([3], $enabled->getIds());
        self::assertCount(1, $enabled->getStores());
    }

    public function testMsiDisablesAllStoresEvenWhenTheirConfigurationIsEnabled(): void
    {
        $modules = $this->createStub(Manager::class);
        $modules->method('isEnabled')->willReturnCallback(static fn($name): bool => $name === 'Magento_InventoryIndexer');
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(true);
        $config = new Config($scope, new InventoryCompatibility($modules));
        self::assertSame([], (new EnabledStores($this->stores(), $config))->getIds());
    }

    private function stores(): StoreManagerInterface
    {
        $list = [];
        foreach ([3 => true, 4 => true, 5 => false] as $id => $active) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn($id);
            $store->method('isActive')->willReturn($active);
            $list[$id] = $store;
        }
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn($list);
        return $manager;
    }
}
