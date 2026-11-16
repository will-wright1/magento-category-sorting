<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;
use Willwright\CategorySorting\Model\ResourceModel\MsiStockSource;

final class MsiStockSourceTest extends TestCase
{
    public function testEmptyBatchDoesNotResolveOptionalMsiServicesOrQueryInventory(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');
        $objects = $this->createMock(ObjectManagerInterface::class);
        $objects->expects(self::never())->method('get');
        self::assertSame([], (new MsiStockSource($resource, $objects))->getData([], 'uk'));
    }
}
