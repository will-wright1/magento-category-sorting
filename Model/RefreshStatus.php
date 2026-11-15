<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;

class RefreshStatus
{
    private const FLAG = 'willwright_category_sorting_last_full_refresh';

    public function __construct(private FlagManager $flags, private DateTime $clock)
    {
    }

    public function isDue(): bool
    {
        $last = $this->flags->getFlagData(self::FLAG);
        $now = (int)$this->clock->gmtTimestamp();
        return !is_numeric($last) || (int)$last <= 0 || (int)$last > $now || $now - (int)$last >= 86400;
    }

    public function markFresh(): void
    {
        $this->flags->saveFlag(self::FLAG, (int)$this->clock->gmtTimestamp());
    }
}
