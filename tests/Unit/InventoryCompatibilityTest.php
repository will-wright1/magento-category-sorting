<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Willwright\CategorySorting\Model\Config;
use Willwright\CategorySorting\Model\InventoryCompatibility;

final class InventoryCompatibilityTest extends TestCase
{
    #[DataProvider('inventoryModes')]
    public function testDetectsEnabledInventoryModulesRatherThanInstalledClasses(array $enabled, bool $supported): void
    {
        $guard = $this->guard($enabled);
        self::assertSame($supported, $guard->isSupported());
        if ($supported) {
            $guard->assertSupported();
        } else {
            self::assertStringContainsString('incomplete Multi Source Inventory', $guard->getCommentText('1'));
            $this->expectException(LocalizedException::class);
            $this->expectExceptionMessage(InventoryCompatibility::ERROR_MESSAGE);
            $guard->assertSupported();
        }
    }

    public static function inventoryModes(): array
    {
        return [
            'legacy with MSI modules disabled' => [[], true],
            'complete MSI' => [['Magento_Inventory', 'Magento_InventoryCatalog', 'Magento_InventorySales',
                'Magento_InventoryIndexer', 'Magento_InventoryConfiguration', 'Magento_InventoryReservations'], true],
            'MSI inventory' => [['Magento_Inventory'], false],
            'MSI sales' => [['Magento_InventorySales'], false],
            'MSI indexer' => [['Magento_InventoryIndexer'], false],
            'API contracts alone' => [['Magento_InventoryApi', 'Magento_InventorySalesApi'], true],
        ];
    }

    public function testIncompleteImportedMsiConfigurationIsInactiveAtRuntime(): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(true);
        self::assertFalse((new Config($scope, $this->guard(['Magento_InventorySales'])))->isEnabled(3));
        self::assertTrue((new Config($scope, $this->guard([])))->isEnabled(3));
    }

    public function testCompleteMsiUsesMsiAndCanBeEnabled(): void
    {
        $guard = $this->guard(self::inventoryModes()['complete MSI'][0]);
        self::assertTrue($guard->isMsiEnabled());
        self::assertFalse($this->guard([])->isMsiEnabled());
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(true);
        self::assertTrue((new Config($scope, $guard))->isEnabled(3));
        $guard->assertSupported();
    }

    public function testDisabledConfigurationDoesNotCheckInventory(): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(false);
        $guard = $this->createMock(InventoryCompatibility::class);
        $guard->expects(self::never())->method('isSupported');
        self::assertFalse((new Config($scope, $guard))->isEnabled(3));
    }

    private function guard(array $enabled): InventoryCompatibility
    {
        $modules = $this->createStub(Manager::class);
        $modules->method('isEnabled')->willReturnCallback(static fn($name): bool => in_array($name, $enabled, true));
        return new InventoryCompatibility($modules);
    }
}
