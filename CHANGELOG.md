# Changelog

## 1.1.0 — 2026-11-16

- Automatically support both legacy CatalogInventory and MSI, including default/custom stocks resolved for each website.
- Read MSI aggregated indexed stock in bulk and account for outstanding order/cancellation reservations, native backorders, negative backorder limits, unmanaged stock and configurable variants. Preserve optional Amasty preorder integration.
- Accept complete MSI configurations in Admin, CLI and runtime; retain the compatibility guard for incomplete MSI setups and disabled-store query suppression.
- Resolve MSI services lazily so legacy projects can install and compile without MSI packages. Add the optional inventory indexer dependency only when MSI is registered.
- Validate reservation-aware availability against Magento's native stock reader and compile DI in CI with MSI present and entirely absent.

## 1.0.2 — 2026-11-15

- Add Recommended category sorting to Amasty Shopby's independent fulltext collection, which does not inherit Magento's native fulltext collection.
- Preserve manual sorts and leave search results, disabled stores and ordinary SQL product collections unaffected for both collection implementations.
- Cover Amasty's independent collection inheritance without requiring or redistributing its commercial module in public CI.

## 1.0.1 — 2026-11-15

- Correct the Admin enablement backend's optional resource dependency to `Magento\Framework\Model\ResourceModel\AbstractResource`, fixing `setup:di:compile` and interceptor generation.
- Validate every runtime constructor with Magento's DI class reader and generate the Admin backend interceptor in regression tests, including optional resource dependencies.
- Run full `setup:di:compile` against the packaged module in the clean Magento CI fixture before release.

## 1.0.0 — 2026-11-15

- Reject MSI activation in Admin and CLI, show the compatibility reason in Admin, and keep imported/pre-existing enabled settings inactive on unsupported inventory.
- Skip ranking reads for disabled stores and all refresh/queue/freshness work when no active store has supported, enabled sorting.
- Scope search-publication selection and acknowledgements to enabled stores, retaining disabled stores' queued rows.
- Extract category ranking from the original Magento project into `willwright/module-category-sorting` / `Willwright_CategorySorting`.
- Preserve availability, 180-day paid-sales, review, native customer-price and stable product-ID ranking.
- Remove the required Amasty module/indexer dependency; detect optional preorder integration safely.
- Add a replaceable availability provider for other inventory implementations. The bundled provider supports legacy CatalogInventory with MSI disabled.
- Disable category sorting on initial installation until the project enables it and rebuilds the snapshot/search mapping.
- Include Composer metadata, MIT licence, migration/release documentation, GitHub Actions and portable unit/MySQL/OpenSearch regression tests.
