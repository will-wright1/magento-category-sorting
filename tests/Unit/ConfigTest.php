<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Willwright\CategorySorting\Model\Config;
use Willwright\CategorySorting\Model\InventoryCompatibility;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testExclusionsAcceptOnlyPositiveInternalOrderIds(): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturn(' 123,456,123,0,-1,abc,1 OR 1=1,999-1000, 789 ');
        self::assertSame([123, 456, 789], (new Config($scope, $this->createStub(InventoryCompatibility::class)))->getExcludedOrderIds());
    }

    public function testEnablingRespectsStoreScope(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->expects(self::once())->method('isSetFlag')->with(
            'willwright_category_sorting/general/enabled', ScopeInterface::SCOPE_STORE, 3
        )->willReturn(false);
        self::assertFalse((new Config($scope, $this->createStub(InventoryCompatibility::class)))->isEnabled(3));
    }

    public function testSalesUseTheDefaultBaseCurrencyRatherThanStoreDisplayCurrency(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->expects(self::once())->method('getValue')->with('currency/options/base')->willReturn('gbp');
        self::assertSame('GBP', (new Config($scope, $this->createStub(InventoryCompatibility::class)))->getSalesCurrency());
    }
}
