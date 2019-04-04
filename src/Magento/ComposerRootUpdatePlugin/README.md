# Overview
## Purpose of plugin

The **magento/composer-root-update-plugin** Composer plugin resolves changes that need to be made to the root project `composer.json` file before updating to a new Magento product requirement.

This is accomplished by comparing the root `composer.json` file for the Magento project corresponding to the Magento version and edition in the current installation with the Magento project `composer.json` file for the target Magento product package when the `composer require` command runs and applying any deltas found between the two files if they do not conflict with the existing `composer.json` file in the Magento root directory.

# Getting Started
## System requirements
The **magento/composer-root-update-plugin** package requires Composer version 1.8.0 or earlier.  Compatibility with newer Composer versions will be tested and added in future plugin versions. 

## Installation
To install the plugin, run `composer require magento/composer-root-update-plugin ~1.0` in the Magento root directory.

# Usage
The plugin adds functionality to the `composer require` command when a new Magento product package is required, and in most cases will not need additional options or commands run to function.

If the `composer require` command for the target Magento package fails, one of the following may be necessary.

## Installations that started with another Magento product
If the local Magento installation has previously been updated from a previous Magento product version or edition, the root `composer.json` file may still have values from the earlier package that need to be updated to the current Magento requirement before updating to the target Magento product.

In this case, run the following command with the appropriate values to correct the existing `composer.json` file before proceeding with the expected `composer require` command for the target Magento product.

    composer require <current_Magento_package> <current_version> --base-magento-edition <community|enterprise> --base-magento-version <original_Magento_version>

## Conflicting custom values
If the `composer.json` file has custom changes that do not match the values the plugin expects according to the installed Magento product, the entries may need to be corrected to values compatible with the target Magento package.

To resolve these conflicts interactively, re-run the `composer require` command with the `--interactive-magento-conflicts` option.

To override all conflicting custom values with the expected Magento values, re-run the `composer require` command with the `--use-default-magento-values` option.

## Bypassing the plugin
To run the native `composer require` command without the plugin's updates, use the `--skip-magento-root-plugin` option.

## Refreshing the plugin for the Web Setup Wizard
If the `var` directory in the Magento root folder has been cleared, the plugin may need to be re-installed there to function when updating Magento through the Web Setup Wizard.

To reinstall the plugin in `var`, run the following command in the Magento root directory.

    composer magento-update-plugin install

# License

Each Magento source file included in this distribution is licensed under OSL 3.0 or the Magento Enterprise Edition (MEE) license.

[Open Software License (OSL 3.0)](https://opensource.org/licenses/osl-3.0.php).
Please see [LICENSE.txt](https://github.com/magento/composer-root-update-plugin/blob/develop/LICENSE.txt) for the full text of the OSL 3.0 license or contact license@magentocommerce.com for a copy.

Subject to Licensee's payment of fees and compliance with the terms and conditions of the MEE License, the MEE License supersedes the OSL 3.0 license for each source file.
Please see LICENSE_EE.txt for the full text of the MEE License or visit https://magento.com/legal/terms/enterprise.
