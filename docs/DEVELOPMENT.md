# Development

## Test against a Magento installation

The tests use real Magento framework classes without bootstrapping its application or loading its deployment configuration. An existing Magento Composer installation with PHPUnit 11.5 or 12 supplies the dependencies. Run from this repository, using the same PHP version as that Magento installation:

```sh
export MAGENTO_ROOT=/absolute/path/to/magento
php "$MAGENTO_ROOT/vendor/bin/phpunit" -c phpunit.xml.dist --testsuite unit
python3 tests/run-integration.py
composer validate --strict
```

The integration runner creates disposable MySQL 8.4 and OpenSearch 3.2 containers with random localhost-only ports and temporary credentials, then removes them. It only connects to the synthetic `ranking_test` database. It never reads `app/etc/env.php` or uses a project's database/search connection. Docker, Python 3 and PHP with PDO MySQL are required. Set `PHP_BIN` if the CLI binary is named differently.

The suite covers paid-sales SQL, refunds, configurable sales, native stock/backorders, optional Amasty with and without its module/table, store-scoped ratings, snapshot writes, real OpenSearch pagination/filtering, toolbar selection, publication retries, cache invalidation and Magento XML/indexer schemas. It also checks MSI detection, Admin/CLI activation rejection, runtime fallback for imported settings, disabled-store query suppression and publication acknowledgements scoped to enabled stores. Magento's native DI class reader resolves every runtime constructor, including optional dependencies, and its interceptor generator is exercised against the Admin backend without writing into a project's generated directory.

GitHub Actions checks Composer metadata, PHP syntax and the distribution archive, then tests against the public Magento 2.4.8 source using PHP 8.3/8.4. The isolated MySQL/OpenSearch suite and a full `setup:di:compile` with the packaged module run on the 8.4 job. The public source fixture has its development dependencies reduced to PHPUnit, with Composer plugins/scripts disabled; it requires no project credentials. Installation, compilation with project-specific extensions, and browser/theme checks still need verification in the target project's staging environment.

## Install before Packagist publication

Once the repository is public, add its actual URL to the Magento project's root `composer.json` (substitute a different URL if you choose another repository name):

```sh
composer config repositories.category-sorting vcs https://github.com/will-wright1/magento-category-sorting
composer require willwright/module-category-sorting:dev-main
```

For local development, place or symlink the checkout **inside the Magento root**, for example at `packages/category-sorting`, then add a path repository in the Magento project's root `composer.json`:

```json
{
  "repositories": {
    "category-sorting": {
      "type": "path",
      "url": "packages/category-sorting",
      "options": {
        "symlink": true,
        "versions": {"willwright/module-category-sorting": "1.0.x-dev"}
      }
    }
  }
}
```

Require `willwright/module-category-sorting:1.0.x-dev`, then follow the README's Magento enable/upgrade/index steps. Keep this as a Composer-installed module; do not leave a second copy registered in `app/code`.

## Extension points

`Willwright\CategorySorting\Api\AvailabilityProviderInterface::getRanks(array $productIds, int $storeId): array` returns a map of product IDs to `Availability::IN_STOCK` (0), `PREORDER` (1), or `UNAVAILABLE` (2). A custom provider can replace the DI preference in its own module. It must handle store/website scope, configurable parents and their variants, disabled/unassigned products and the inventory implementation's salability rules. The bundled reader supports legacy inventory only; adding an MSI provider requires implementation and tests before enabling this package on an MSI project.

`Model\InventoryCompatibility` supplies the runtime guard, Admin setting comment and activation validation. It detects active MSI using Magento's module manager, so installed but disabled MSI packages are harmless. A tested provider supporting another inventory implementation must also replace this service's DI preference, implementing the matching `isSupported()`, `assertSupported()` and `getCommentText()` behaviour. Keep this compatibility policy aligned with the replacement provider; changing the policy alone does not add MSI support. Admin saves and ordinary/locked `config:set` writes reject unsupported enablement, while the runtime check handles imports, environment overrides and later changes in enabled modules.

`Model\EnabledStores` centralises active-store enablement. Refresh exits before ranking work if its list is empty, and the publication queue is selected and acknowledged using those store IDs. Disabled stores' queued rows are retained. Search documents for disabled/unsupported stores receive no additional ranking fields. The lightweight static field mappings remain available so the package can be enabled later through the normal reindex process.

`Model\ResourceModel\PreorderSource` isolates the optional Amasty read. It only uses an enabled module's existing index table and can be disabled globally. No Amasty classes are loaded. The snapshot provider and ranking algorithm otherwise use standard Magento components.

## Theme integration

No templates are overridden by this package. Recommended always uses its fixed ordering, including URLs containing `product_list_dir=asc`. If your theme displays a direction switcher, you may hide it while the current order equals `Willwright\CategorySorting\Model\Config::SORT_CODE` (`willwright_recommended`). Keep the theme's existing sorter markup and behaviour for every other sort choice. Themes must render Magento's toolbar available-orders list and use its fulltext category collection for this integration.

Both Magento's native fulltext collection and `Amasty\Shopby\Model\ResourceModel\Fulltext\Collection` are recognised. Shopby's collection inherits the base product collection directly, so a native-fulltext-only type check skips it entirely. The optional class name check adds no Amasty dependency and still excludes ordinary SQL collections. Public CI uses a minimal authored fixture with the same independent inheritance when Amasty is absent; installations with Amasty exercise its actual collection class in the toolbar tests. This support targets Shopby's standard OpenSearch/Elasticsearch pipeline, not Adobe Live Search or a MySQL search adapter.

## Staging release check

Compile DI, apply schema and perform the first full search reindex. Confirm Recommended is the initial choice on a category, check consecutive pages and filters, mixed-stock and preorder-only configurables, and ascending/descending manual price sorting. Check native price tiebreakers using different customer groups and websites. Confirm cron refreshes changed availability and category caches. These application/browser checks are separate from the isolated regression suite.
