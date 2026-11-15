# Magento Category Sorting

A standalone Magento 2 module that adds **Recommended** as the default category order. Install it through Composer on each project and keep updates in one repository.

Package: `willwright/module-category-sorting` · Magento module: `Willwright_CategorySorting` · Licence: MIT.

## Ranking

Recommended applies these rules in order, inside the search engine before pagination:

1. Immediately available stock, then preorder/backorder, then unavailable products.
2. Net paid units sold over the last 180 days, highest first.
3. Approved product review percentage, highest first.
4. Magento's indexed price for the current customer group and website, highest first.
5. Product ID, lowest first, to stabilise page boundaries.

Customers can still choose the existing price, name or position orders. Search results and other product blocks keep their normal sorting. Category filters and Magento's out-of-stock visibility rules still apply.

## Requirements and supported scope

- Magento Open Source 2.4.8 or later in the 2.4 series, PHP 8.3 or later within Magento's supported PHP versions.
- Magento's standard OpenSearch/Elasticsearch category search adapter and fulltext collection. Third-party replacement search engines need separate integration testing.
- **Legacy CatalogInventory, with MSI disabled.** Multi Source Inventory, reservations and multiple inventory stocks are not supported by the bundled availability provider. Activation through Admin or `config:set` is rejected when MSI is active, with an explanation beside the Admin setting. Runtime checks also keep Recommended sorting inactive if an enabled value was imported or MSI was enabled later; Magento's normal category sorting remains available. An `AvailabilityProviderInterface` is available for a project-specific replacement; see [extension points](docs/DEVELOPMENT.md#extension-points).
- Magento cron and the stock/search indexers must be running.

Amasty Preorder is optional. When its module is enabled, its index table exists, and the integration setting is enabled, website-specific preorder designations are included. Native Magento backorders work without Amasty.

The module includes no theme overrides, customer records, order data or project credentials. Recommended has a fixed direction; a theme may still display its normal direction arrow. See [theme integration](docs/DEVELOPMENT.md#theme-integration).

## Install

Run in your Magento project:

```sh
composer require willwright/module-category-sorting:^1.0
php bin/magento module:enable Willwright_CategorySorting
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```

Sorting is disabled by default so you can build the snapshot and search mapping before activating it. Enable snapshot collection, then reindex during your normal maintenance/deployment window:

```sh
php bin/magento maintenance:enable
php bin/magento config:set willwright_category_sorting/general/enabled 1
php bin/magento cache:clean config
php bin/magento indexer:reindex cataloginventory_stock willwright_category_sorting catalogsearch_fulltext
php bin/magento cache:clean block_html full_page
php bin/magento maintenance:disable
```

If Amasty Preorder is installed and used, reindex `amasty_preorder_product_preorder` after stock and before the ranking indexer. The optional indexer is deliberately not a required dependency. A full reindex of `catalogsearch_fulltext` is required on first installation to create the numeric field mappings; a partial product reindex is insufficient.

Retain your project's normal static-content deployment and external cache-purge steps. Avoid running another index rebuild concurrently. If indexing fails, resolve the error and finish rebuilding before leaving maintenance mode.

Settings are under **Stores → Configuration → Catalog → Catalog → Category Sorting**. Enablement can be overridden by website or store view. Test-order exclusions and Amasty integration are global settings. If a store already has an explicit disabled override, change that override as well.

The inventory guard checks enabled `Magento_Inventory`, `Magento_InventorySales` and `Magento_InventoryIndexer` modules. Installed but disabled MSI packages do not block legacy inventory. The guard does not change your inventory configuration.

For an unpublished GitHub package or a local checkout, see [development installation](docs/DEVELOPMENT.md).

## Sales and availability rules

- Sales across all stores and customer groups contribute to each product's total. There is no channel restriction; paid POS, trade and web orders all count.
- The 180-day window uses the order's original creation timestamp in UTC. Refunds reduce the original contribution; future orders are excluded.
- Orders must be in `processing` or `complete` and fully paid. A rounding tolerance of 0.0001 is allowed; qualifying free orders count. Unpaid or partially paid orders do not count.
- Quantities use invoiced units capped at ordered units less cancellations, minus refunds, with a floor of zero. Fully paid orders without invoiced units use ordered units less cancellations.
- Only top-level order items count, avoiding double counting of configurable/bundle child rows. Standalone sales of a variant also contribute to its currently linked configurable parents.
- Known test **order entity IDs**, rather than displayed order numbers, can be excluded in configuration. Exclusion changes take effect at the next full metrics refresh.
- Ratings use Magento's store-specific approved-review aggregate. Unreviewed products have zero rating; configurable parents use their own displayed rating. There is no new-product boost.
- Availability respects enabled status, website assignment, indexed salability and stock settings. Backorders without immediate stock are demoted. An explicit Amasty preorder designation also demotes positive-stock products.
- A configurable parent uses its best purchasable variant, while the parent itself must remain enabled and salable. Bundles and grouped products use their native indexed stock status, plus optional Amasty composite designations.

## Refresh and recovery

`willwright_category_sorting_nightly` refreshes sales, ratings and availability at 02:15 UTC. `willwright_category_sorting_availability` compares availability every five minutes and retries a full build when metrics are missing or at least 24 hours old. Actual refresh timing depends on Magento cron and stock/preorder index freshness.

Changed products enter a persistent search-publication queue. The refresh publishes their search documents, invalidates category/product caches, then acknowledges them. Failures leave IDs queued for retry. Publication defers while catalog search is invalid or rebuilding. A shared lock prevents module refresh jobs from overlapping. The module writes only its snapshot and Magento indexer/refresh bookkeeping.

Disabled stores skip ranking reads during ordinary search indexing. When no active store has supported, enabled sorting, scheduled/manual module refreshes return before taking a refresh lock, reading freshness flags, querying sales or processing the publication queue. In mixed configurations only enabled stores are built and their queued rows acknowledged; disabled stores retain pending rows for a future re-enable. Follow the manual snapshot/search reindex steps after re-enabling a store.

For a manual full refresh:

```sh
php bin/magento indexer:reindex willwright_category_sorting catalogsearch_fulltext
php bin/magento cache:clean block_html full_page
```

Check **Category Sorting** and **Catalog Search** with `indexer:status`. Pending publication is `needs_reindex = 1` in the `willwright_category_sorting` table, including any Magento table prefix. Ordinary search indexing also reads current availability and ratings.

To restore the project's existing category defaults, disable **Use Recommended Category Sorting** at the relevant scope and clean config/full-page caches. The snapshot can remain in place.

## Development and publication

- [Run the tests and integrate a theme](docs/DEVELOPMENT.md).
- [Publish to GitHub and Packagist](docs/RELEASING.md).
- [Replace the original 4x4AT module safely](docs/MIGRATION.md).

Source and releases: [GitHub](https://github.com/will-wright1/magento-category-sorting). Composer package: [Packagist](https://packagist.org/packages/willwright/module-category-sorting).
