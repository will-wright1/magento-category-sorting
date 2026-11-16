<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Magento\Config\Console\Command\ConfigSet\ProcessorFacade;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Module\Manager;
use Magento\Framework\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Willwright\CategorySorting\Model\Config;
use Willwright\CategorySorting\Model\Config\Backend\Enabled;
use Willwright\CategorySorting\Model\InventoryCompatibility;
use Willwright\CategorySorting\Plugin\ConfigSet;

final class EnablementValidationTest extends TestCase
{
    public function testAdminAllowsEnablingSortingOnMsi(): void
    {
        $model = $this->backend(true);
        $model->setValue('1');
        self::assertSame($model, $model->beforeSave());
    }

    public function testAdminStillAllowsDisablingSortingOnMsi(): void
    {
        $model = $this->backend(true);
        $model->setValue('0');
        self::assertSame($model, $model->beforeSave());
    }

    public function testAdminAllowsEnablingLegacyInventory(): void
    {
        $model = $this->backend(false);
        $model->setValue('1');
        self::assertSame($model, $model->beforeSave());
    }

    public function testAdminRejectsIncompleteMsi(): void
    {
        $model = $this->backend(null);
        $model->setValue('1');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(InventoryCompatibility::ERROR_MESSAGE);
        $model->beforeSave();
    }

    #[DataProvider('cliCases')]
    public function testCliValidatesEnablementIncludingLockedAndScopedConfiguration(string $path, string $value, ?bool $msi, bool $reject): void
    {
        $plugin = new ConfigSet($this->guard($msi));
        if ($reject) {
            $this->expectException(LocalizedException::class);
            $this->expectExceptionMessage(InventoryCompatibility::ERROR_MESSAGE);
        }
        $plugin->beforeProcessWithLockTarget($this->createStub(ProcessorFacade::class), $path, $value, 'stores', 'uk', true, 'config.php');
        if (!$reject) {
            $this->addToAssertionCount(1);
        }
    }

    public static function cliCases(): array
    {
        return [
            'MSI enable allowed' => [Config::XML_PATH_ENABLED, '1', true, false],
            'incomplete MSI enable rejected' => [Config::XML_PATH_ENABLED, '1', null, true],
            'incomplete MSI disable allowed' => [Config::XML_PATH_ENABLED, '0', null, false],
            'MSI disable allowed' => [Config::XML_PATH_ENABLED, '0', true, false],
            'legacy enable allowed' => [Config::XML_PATH_ENABLED, '1', false, false],
            'other configuration unaffected' => ['catalog/frontend/list_mode', '1', true, false],
        ];
    }

    private function backend(?bool $msi): Enabled
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));
        return new Enabled($context, $this->createStub(Registry::class), $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class), $this->guard($msi), $this->createStub(AbstractResource::class));
    }

    private function guard(?bool $msi): InventoryCompatibility
    {
        $modules = $this->createStub(Manager::class);
        $modules->method('isEnabled')->willReturnCallback(static fn(string $name): bool => $msi ?? $name === 'Magento_Inventory');
        return new InventoryCompatibility($modules);
    }
}
