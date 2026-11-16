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

The suite covers paid sales-value SQL, line discounts, tax compensation, monetary refunds, historical currency conversion, configurable and dynamic/fixed bundle revenue, native stock/backorders, optional Amasty with and without its module/table, store-scoped ratings, snapshot writes, real OpenSearch pagination/filtering, toolbar selection, publication retries, cache invalidation and Magento XML/indexer schemas. It also checks automatic MSI/legacy selection, default/custom website stocks, reservation deductions and compensations, limited backorders, acceptance of complete MSI, rejection of incomplete MSI configurations, disabled-store query suppression and publication acknowledgements scoped to enabled stores. Magento's native DI class reader resolves every runtime constructor, including optional dependencies, and its interceptor generator is exercised against the Admin backend without writing into a project's generated directory.

GitHub Actions checks Composer metadata, PHP syntax and the distribution archive, then tests against the public Magento 2.4.8 source using PHP 8.3/8.4. The isolated MySQL/OpenSearch suite and full `setup:di:compile` run in separate PHP 8.4 jobs with MSI entirely absent and with the official Inventory 1.2.8 source modules enabled. MSI-specific SQL cases execute against Magento's real bulk stock reader in the MSI job; the legacy job skips those optional cases. The public source fixture has its development dependencies reduced to PHPUnit, with Composer plugins/scripts disabled; it requires no project credentials. Installation, compilation with project-specific extensions, and browser/theme checks still need verification in the target project's staging environment.

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

## Sales-value upgrades

The `sales_value` snapshot column and `willwright_sales_value` search field are separate from historical unit counts. The deprecated `units_sold` column remains declared for non-destructive upgrades and rollback, but is no longer read or written by this release. A new full-refresh flag requests a monetary metrics rebuild after upgrade, while disabled stores still skip refresh work. Deploy with `setup:upgrade`, rebuild `willwright_category_sorting`, then fully reindex `catalogsearch_fulltext` to install the new search mapping. Refresh and cache invalidation continue to use the existing publication queue.

## Extension points

`Willwright\CategorySorting\Api\AvailabilityProviderInterface::getRanks(array $productIds, int $storeId): array` returns a map of product IDs to `Availability::IN_STOCK` (0), `PREORDER` (1), or `UNAVAILABLE` (2). A custom provider can replace the DI preference in its own module. It must handle store/website scope, configurable parents and their variants, disabled/unassigned products and the inventory implementation's salability rules. The bundled reader supports legacy inventory and MSI, sharing product/status/configurable handling. `MsiStockSource` lazily resolves MSI's native website stock resolver and bulk stock reader, then adds reservation sums in one stock-scoped query per product batch. It never sums raw source items, assumes a default website stock, or loads MSI services for legacy batches. MSI interfaces deliberately do not appear in runtime constructor signatures, so DI compilation also works when the optional packages are absent.

`Model\InventoryCompatibility` supplies the runtime guard, Admin setting comment and activation validation. It detects active MSI using Magento's module manager, so installed but disabled MSI packages are harmless. A provider for another inventory implementation may also need to replace this service's DI preference, implementing the matching `isSupported()`, `assertSupported()` and `getCommentText()` behaviour. Keep this compatibility policy aligned with the replacement provider. Admin saves and ordinary/locked `config:set` writes reject unsupported enablement, while the runtime check handles imports, environment overrides and later changes in enabled modules.

`Model\EnabledStores` centralises active-store enablement. Refresh exits before ranking work if its list is empty, and the publication queue is selected and acknowledged using those store IDs. Disabled stores' queued rows are retained. Search documents for disabled/unsupported stores receive no additional ranking fields. The lightweight static field mappings remain available so the package can be enabled later through the normal reindex process.

`Model\ResourceModel\PreorderSource` isolates the optional Amasty read. It only uses an enabled module's existing index table and can be disabled globally. No Amasty classes are loaded. The snapshot provider and ranking algorithm otherwise use standard Magento components.

`Plugin\InventoryIndexerDependency` adds the MSI `inventory` dependency to the ranking indexer only when it exists in the merged indexer configuration. Magento expands and sorts it together with native dependencies before ranking and catalog search. The optional indexer is never a static requirement on legacy installations.

## Theme integration

No templates are overridden by this package. Recommended always uses its fixed ordering, including URLs containing `product_list_dir=asc`. If your theme displays a direction switcher, you may hide it while the current order equals `Willwright\CategorySorting\Model\Config::SORT_CODE` (`willwright_recommended`). Keep the theme's existing sorter markup and behaviour for every other sort choice. Themes must render Magento's toolbar available-orders list and use its fulltext category collection for this integration.

Both Magento's native fulltext collection and `Amasty\Shopby\Model\ResourceModel\Fulltext\Collection` are recognised. Shopby's collection inherits the base product collection directly, so a native-fulltext-only type check skips it entirely. The optional class name check adds no Amasty dependency and still excludes ordinary SQL collections. Public CI uses a minimal authored fixture with the same independent inheritance when Amasty is absent; installations with Amasty exercise its actual collection class in the toolbar tests. This support targets Shopby's standard OpenSearch/Elasticsearch pipeline, not Adobe Live Search or a MySQL search adapter.

## Staging release check

Compile DI, apply schema and perform the first full search reindex. Confirm Recommended is the initial choice on a category, check consecutive pages and filters, mixed-stock and preorder-only configurables, and ascending/descending manual price sorting. Check native price tiebreakers using different customer groups and websites. Confirm cron refreshes changed availability and category caches. These application/browser checks are separate from the isolated regression suite.
