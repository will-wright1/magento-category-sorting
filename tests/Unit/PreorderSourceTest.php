<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Willwright\CategorySorting\Model\Config;
use Willwright\CategorySorting\Model\ResourceModel\PreorderSource;

final class PreorderSourceTest extends TestCase
{
    #[DataProvider('unavailableCases')]
    public function testDoesNotConnectToTheDatabaseWhenIntegrationIsUnavailable(bool $configured, bool $enabled, array $ids): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');
        $modules = $this->createStub(Manager::class);
        $modules->method('isEnabled')->willReturn($enabled);
        $config = $this->createStub(Config::class);
        $config->method('useAmastyPreorder')->willReturn($configured);
        self::assertSame([], (new PreorderSource($resource, $modules, $config))->getProductIds($ids, 1));
    }

    public static function unavailableCases(): array
    {
        return [
            'disabled in configuration' => [false, true, [1]],
            'module absent or disabled' => [true, false, [1]],
            'empty product batch' => [true, true, []],
        ];
    }
}
