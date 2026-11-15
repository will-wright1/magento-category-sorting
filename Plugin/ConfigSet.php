<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Plugin;

use Magento\Config\Console\Command\ConfigSet\ProcessorFacade;
use Willwright\CategorySorting\Model\Config;
use Willwright\CategorySorting\Model\InventoryCompatibility;

class ConfigSet
{
    public function __construct(private InventoryCompatibility $compatibility)
    {
    }

    /** Validate ordinary and locked CLI configuration before any value is written. */
    public function beforeProcessWithLockTarget(ProcessorFacade $subject, $path, $value): void
    {
        if ($path === Config::XML_PATH_ENABLED && (bool)$value) {
            $this->compatibility->assertSupported();
        }
    }
}
