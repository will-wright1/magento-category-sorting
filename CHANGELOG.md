# Changelog

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
