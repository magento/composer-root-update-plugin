# Overview

## Purpose of plugin

The `magento/composer-root-update-plugin` Composer plugin resolves changes that need to be made to the root project `composer.json` file before updating to a new Magento metapackage requirement through the `composer require-commerce` command.

To accomplish this, it compares the default project `composer.json` file for the Magento Open Source or Adobe Commerce metapackage version in the current installation with the project `composer.json` file corresponding to the target metapackage. The command then applies any deltas found between the two files if they do not conflict with user customizations.

# Getting Started

For system requirements and installation instructions, see [README.md](src/Magento/ComposerRootUpdatePlugin#getting-started) in the source directory.

# Usage

For a usage overview and example use cases, see [README.md](src/Magento/ComposerRootUpdatePlugin#usage) in the source directory.

# Developer documentation

Class descriptions, process flows, and any other developer documentation can be found in the [docs](docs) directory.

# License

Each Magento source file included in this distribution is licensed under OSL 3.0.

[Open Software License (OSL 3.0)](https://opensource.org/licenses/osl-3.0.php).

Please see [LICENSE.txt](https://github.com/magento/composer-root-update-plugin/blob/develop/LICENSE.txt) for the full text of the OSL 3.0 license or contact license@magentocommerce.com for a copy.
