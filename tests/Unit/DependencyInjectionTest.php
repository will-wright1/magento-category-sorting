<?php
declare(strict_types=1);

namespace Willwright\CategorySorting\Test\Unit;

use Magento\Framework\Code\Reader\ClassReader;
use Magento\Framework\Interception\Code\Generator\Interceptor;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Willwright\CategorySorting\Model\Config\Backend\Enabled;

final class DependencyInjectionTest extends TestCase
{
    public function testMagentoCanResolveEveryRuntimeConstructorIncludingOptionalDependencies(): void
    {
        $root = dirname(__DIR__, 2);
        $reader = new ClassReader();
        foreach (['Cron', 'Model', 'Plugin', 'Search'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1, -4);
                $class = 'Willwright\\CategorySorting\\' . str_replace('/', '\\', $relative);
                $parameters = $reader->getConstructor($class);
                $constructor = (new ReflectionClass($class))->getConstructor();
                self::assertCount($constructor ? $constructor->getNumberOfParameters() : 0, $parameters ?? [], $class);
            }
        }
    }

    public function testMagentoCanGenerateTheAdminBackendInterceptor(): void
    {
        // Exercise Magento's real generator without writing into a project's generated directory.
        $generator = new class(Enabled::class, Enabled::class . '\\Interceptor') extends Interceptor {
            public function source(): string
            {
                return $this->_generateCode();
            }
        };
        self::assertNotEmpty(token_get_all("<?php\n" . $generator->source(), TOKEN_PARSE));
    }
}
