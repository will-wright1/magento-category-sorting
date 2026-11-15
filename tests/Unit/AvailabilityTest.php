<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Willwright\CategorySorting\Model\Availability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvailabilityTest extends TestCase
{
    #[DataProvider('stockCases')]
    public function testAvailability(array $overrides, int $expected, array $defaults = []): void
    {
        $row = $overrides + [
            'enabled' => 1, 'assigned' => 1, 'salable' => 1, 'preorder' => 0, 'qty' => 10,
            'manage_stock' => 1, 'use_config_manage_stock' => 0,
            'backorders' => 0, 'use_config_backorders' => 0, 'min_qty' => 0, 'use_config_min_qty' => 0,
        ];
        self::assertSame($expected, (new Availability())->classify($row,
            $defaults + ['manage_stock' => true, 'backorders' => 0, 'min_qty' => 0.0]));
    }

    public static function stockCases(): array
    {
        return [
            'physical stock' => [[], 0],
            'disabled product' => [['enabled' => 0], 2],
            'other website' => [['assigned' => 0], 2],
            'not salable' => [['salable' => 0], 2],
            'Amasty preorder with positive stock' => [['preorder' => 1], 1],
            'unavailable overrides preorder' => [['preorder' => 1, 'salable' => 0], 2],
            'standard backorder' => [['qty' => 0, 'backorders' => 1], 1],
            'notify backorder' => [['qty' => -1, 'backorders' => 2], 1],
            'Amasty backorder mode' => [['qty' => 0, 'backorders' => 101], 1],
            'backorder setting with available stock' => [['backorders' => 101], 0],
            'negative threshold does not make negative stock immediate' => [['qty' => -3, 'min_qty' => -50, 'backorders' => 1], 1],
            'threshold reserves physical stock' => [['qty' => 5, 'min_qty' => 5, 'backorders' => 1], 1],
            'unmanaged stock is immediate' => [['qty' => 0, 'manage_stock' => 0, 'backorders' => 1], 0],
            'inherited backorders' => [['qty' => 0, 'use_config_backorders' => 1], 1, ['backorders' => 2]],
            'inherited unlimited stock' => [['qty' => 0, 'use_config_manage_stock' => 1, 'backorders' => 1], 0, ['manage_stock' => false]],
            'inherited threshold' => [['qty' => 5, 'use_config_min_qty' => 1, 'backorders' => 1], 1, ['min_qty' => 6.0]],
        ];
    }

    #[DataProvider('configurableCases')]
    public function testConfigurableAvailability(int $parent, array $children, int $expected): void
    {
        self::assertSame($expected, (new Availability())->configurable($parent, $children));
    }

    public static function configurableCases(): array
    {
        return [
            'mixed immediate and preorder' => [1, [0, 1], 0],
            'immediate and unavailable' => [0, [0, 2], 0],
            'preorder and unavailable' => [0, [1, 2], 1],
            'all preorder' => [1, [1, 1], 1],
            'all unavailable' => [0, [2, 2], 2],
            'no variants' => [0, [], 2],
            'disabled parent' => [2, [0, 1], 2],
        ];
    }
}
