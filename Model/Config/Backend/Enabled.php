<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\AbstractResource;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Willwright\CategorySorting\Model\InventoryCompatibility;

class Enabled extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private InventoryCompatibility $compatibility,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        if ((bool)$this->getValue()) {
            $this->compatibility->assertSupported();
        }
        return parent::beforeSave();
    }
}
