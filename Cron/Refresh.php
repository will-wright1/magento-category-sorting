<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Cron;

use Willwright\CategorySorting\Model\Refresh as RankingRefresh;

class Refresh
{
    public function __construct(private RankingRefresh $refresh)
    {
    }

    public function nightly(): void
    {
        $this->refresh->execute(true);
    }

    public function availability(): void
    {
        $this->refresh->execute(false);
    }
}
