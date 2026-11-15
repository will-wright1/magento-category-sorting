<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use DOMDocument;
use Willwright\CategorySorting\Model\Indexer\Ranking;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Config\Dom\UrnResolver;
use Magento\Framework\Mview\Config\Converter;
use Magento\Framework\Mview\ConfigInterface;
use Magento\Framework\Mview\View;
use Magento\Indexer\Model\Indexer;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionProperty;

final class WiringTest extends TestCase
{
    public function testAllModuleConfigurationValidatesAgainstInstalledMagentoSchemas(): void
    {
        $resolver = new UrnResolver();
        libxml_set_external_entity_loader([$resolver, 'registerEntityLoader']);
        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->path() . '/etc')) as $file) {
                if ($file->getExtension() !== 'xml') {
                    continue;
                }
                $xml = new DOMDocument();
                $xml->load($file->getPathname());
                $urn = $xml->documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'noNamespaceSchemaLocation');
                self::assertTrue($xml->schemaValidate($resolver->getRealPath($urn)), $file->getPathname());
            }
        } finally {
            libxml_set_external_entity_loader(null);
        }
    }

    public function testNativeIndexerCanLoadItsViewWithoutSubscriptions(): void
    {
        $indexerXml = new DOMDocument();
        $indexerXml->load($this->path() . '/etc/indexer.xml');
        $viewId = $indexerXml->getElementsByTagName('indexer')->item(0)->getAttribute('view_id');
        $mviewXml = new DOMDocument();
        $mviewXml->load($this->path() . '/etc/mview.xml');
        $views = (new Converter())->convert($mviewXml);
        self::assertArrayHasKey($viewId, $views);
        self::assertSame(Ranking::class, $views[$viewId]['action_class']);
        self::assertSame([], $views[$viewId]['subscriptions']);
        $config = $this->createStub(ConfigInterface::class);
        $config->method('getView')->willReturn($views[$viewId]);
        $view = (new ReflectionClass(View::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(View::class, 'config'))->setValue($view, $config);
        $indexer = (new ReflectionClass(Indexer::class))->newInstanceWithoutConstructor();
        $indexer->setData('view_id', $viewId);
        (new ReflectionProperty(Indexer::class, 'view'))->setValue($indexer, $view);
        self::assertSame($viewId, $indexer->getView()->getId());
        self::assertTrue(is_subclass_of(Ranking::class, \Magento\Framework\Mview\ActionInterface::class));
    }

    private function path(): string
    {
        return (new ComponentRegistrar())->getPath(ComponentRegistrar::MODULE, 'Willwright_CategorySorting');
    }
}
