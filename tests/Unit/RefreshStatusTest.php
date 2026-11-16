<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Willwright\CategorySorting\Model\RefreshStatus;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefreshStatusTest extends TestCase
{
    #[DataProvider('ageCases')]
    public function testOverdueMetricsAreRetried(mixed $last, bool $expected): void
    {
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturn($last);
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(200000);
        self::assertSame($expected, (new RefreshStatus($flags, $clock))->isDue());
    }

    public static function ageCases(): array
    {
        return [[null, true], [0, true], [113600, true], [113601, false], [199999, false], [200001, true]];
    }

    public function testRecordsSuccessfulMetricsBuildTime(): void
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->expects(self::once())->method('saveFlag')->with('willwright_category_sorting_last_full_sales_value_refresh', 200000);
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(200000);
        (new RefreshStatus($flags, $clock))->markFresh();
    }

    public function testUnitMetricsTimestampCannotSuppressTheFirstSalesValueBuild(): void
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->expects(self::once())->method('getFlagData')
            ->with('willwright_category_sorting_last_full_sales_value_refresh')->willReturn(null);
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(200000);
        self::assertTrue((new RefreshStatus($flags, $clock))->isDue());
    }
}
