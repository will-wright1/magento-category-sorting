# GitHub and Packagist releases

The package is `willwright/module-category-sorting`, licensed MIT. The suggested repository is `will-wright1/magento-category-sorting`; substitute your chosen URL if different. There is no hard-coded Composer version: Git tags provide releases.

## First publication

1. Complete the checks in [DEVELOPMENT.md](DEVELOPMENT.md), including staging DI compilation, schema/search indexing and storefront verification. Review the supported inventory scope before calling the release stable.
2. Commit the package, create a public GitHub repository and push `main`. If creating the suggested repository with GitHub CLI, the commands are:

   ```sh
   git add .
   git commit -m "Extract reusable Magento category sorting module"
   gh repo create will-wright1/magento-category-sorting --public --source=. --remote=origin --push
   ```

   If a repository already exists, add its URL as `origin` and push to it instead. These commands are release instructions; package preparation does not execute them.
3. Confirm GitHub Actions passes on the pushed commit. Add the final GitHub `homepage` and `support` URLs to `composer.json` if desired.
4. Tag the reviewed commit and push the tag:

   ```sh
   git tag -a v1.0.0 -m "Initial standalone release"
   git push origin v1.0.0
   ```

5. Sign in to [Packagist](https://packagist.org/packages/submit) using the intended maintainer account and submit the public HTTPS GitHub repository URL. The account must have access to publish under the `willwright` vendor. Packagist reads the package name and metadata from the root `composer.json`.
6. Enable [Packagist's GitHub update integration](https://packagist.org/about#how-to-update-packages) so subsequent pushes/tags are discovered automatically. Keep API tokens in GitHub/Packagist account settings, outside this repository.
7. Confirm `v1.0.0` appears, then verify `composer require willwright/module-category-sorting:^1.0` in a supported Magento project using its normal Magento Composer repository credentials.

Public Packagist makes the package public. For private distribution, use a private VCS repository in the consuming project's Composer configuration, or Private Packagist, instead of submitting to public Packagist.

## Later releases

Update the changelog and tests, run the release checks, then tag and push a semantic version. Use patches for compatible fixes, minor releases for compatible features, and major releases for breaking behaviour or namespace/schema/configuration changes. Magento upgrades also need staging testing; Composer dependency ranges alone do not prove runtime compatibility.

The distribution excludes tests, CI configuration and development-only files. The Magento module, Composer metadata, licence, README and operational documentation remain in the package. No application/vendor files from the source project are included.

References: [Composer version schema](https://getcomposer.org/doc/04-schema.md#version), [Packagist publication and updates](https://packagist.org/about), [Magento component registration](https://developer.adobe.com/commerce/php/development/build/component-registration/).
