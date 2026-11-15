<?php
declare(strict_types=1);

// A Magento Composer installation supplies the real framework classes, without booting the application.
$moduleRoot = dirname(__DIR__);
$magentoRoot = getenv('MAGENTO_ROOT') ?: $moduleRoot;
$autoload = rtrim($magentoRoot, '/') . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Set MAGENTO_ROOT to a Magento installation with Composer dependencies.');
}
$loader = require $autoload;
$loader->addPsr4('Willwright\\CategorySorting\\', $moduleRoot . '/', true);
require_once $moduleRoot . '/registration.php';
