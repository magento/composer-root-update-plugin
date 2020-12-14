# Overview

## Purpose of plugin

The `magento/composer-root-update-plugin` Composer plugin resolves changes that need to be made to the root project `composer.json` file before updating to a new Magento product requirement.

This is accomplished by comparing the root `composer.json` file for the Magento project corresponding to the Magento version and edition in the current installation with the Magento project `composer.json` file for the target Magento product or cloud metapackage when the `composer require` command runs and applying any deltas found between the two files if they do not conflict with the existing `composer.json` file in the Magento root directory.

# Getting Started

## System requirements

The `magento/composer-root-update-plugin` package requires Composer version 1.10.19 or earlier, or version 2.0.0 - 2.0.8. Compatibility with newer Composer versions will be tested and added in future plugin versions. 

## Installation

To install the plugin, run the following commands in the Magento root directory.
    
    composer require magento/composer-root-update-plugin ~1.1 --no-update
    composer update 

# Usage

The plugin adds functionality to the `composer require` command when a new Magento product or cloud metapackage is required, and in most cases will not need additional options or commands run to function.

If the `composer require` command for the target Magento package fails, one of the following may be necessary.

## Installations that started with another Magento product

If the local Magento installation has previously been updated from a previous Magento product version or edition without the plugin installed, the root `composer.json` file may still have values from the earlier package that need to be updated to the current Magento requirement before updating to the target Magento product.

In this case, run the following command with the appropriate values to correct the existing `composer.json` file before proceeding with the expected `composer require` command for the target Magento product.

    composer require <current_Magento_package> <current_version> --base-magento-edition '<Open Source|Commerce>' --base-magento-version <original_Magento_version>

These options are not valid for Magento Cloud installations.

## Conflicting custom values

If the `composer.json` file has custom changes that do not match the values the plugin expects according to the installed Magento metapackage, the entries may need to be corrected to values compatible with the target Magento version.

To resolve these conflicts interactively, re-run the `composer require` command with the `--interactive-magento-conflicts` option.

To override all conflicting custom values with the expected Magento values, re-run the `composer require` command with the `--use-default-magento-values` option.

## Bypassing the plugin

To run the native `composer require` command without the plugin's updates, use the `--skip-magento-root-plugin` option.

## Refreshing the plugin for the Web Setup Wizard

If the `var` directory in the Magento root folder has been cleared, the plugin may need to be re-installed there to function when updating Magento through the Web Setup Wizard.

To reinstall the plugin in `var`, run the following command in the Magento root directory.

    composer magento-update-plugin install

## Example use case: Upgrading from Magento 2.2.8 to Magento 2.3.1

### Without `magento/composer-root-update-plugin`:

In the project directory for a Magento Open Source 2.2.8 installation, a user tries to run the `composer require` and `composer update` commands for Magento Open Source 2.3.1 with these results:

```
$ composer require magento/product-community-edition 2.3.1 --no-update
./composer.json has been updated
$ composer update
Loading composer repositories with package information
Updating dependencies (including require-dev)
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Installation request for magento/product-community-edition 2.3.1 -> satisfiable by magento/product-community-edition[2.3.1].
    - magento/product-community-edition 2.3.1 requires magento/magento2-base 2.3.1 -> satisfiable by magento/magento2-base[2.3.1].
  ...
    - sebastian/phpcpd 2.0.4 requires symfony/console ~2.7|^3.0
  ...
    - magento/magento2-base 2.3.1 requires symfony/console ~4.1.0 -> satisfiable by symfony/console[v4.1.0, v4.1.1, v4.1.10, v4.1.11, v4.1.2, v4.1.3, v4.1.4, v4.1.5, v4.1.6, v4.1.7, v4.1.8, v4.1.9].
    - Conclusion: don't install symfony/console v4.1.11|install symfony/console v2.8.38
    - Installation request for sebastian/phpcpd 2.0.4 -> satisfiable by sebastian/phpcpd[2.0.4].
```

This error occurs because the `"require-dev"` section in the `composer.json` file for `magento/project-community-edition` 2.2.8 conflicts with the dependencies for the new 2.3.1 version of `magento/product-community-edition`. The 2.2.8 `composer.json` file has a `"require-dev"` entry for `sebastian/phpcpd: 2.0.4`, which depends on `symfony/console: ~2.7|^3.0`, but the `magento/magento2-base` package required by `magento/product-community-edition` 2.3.1 depends on `symfony/console: ~4.1.0`, which does not overlap with the versions allowed by the `~2.7|^3.0` constraint.

Because the `sebastian/phpcpd` requirement exists in the root `composer.json` file instead of one of the child dependencies of `magento/product-community-edition` 2.2.8, it does not get updated by Composer when the `magento/product-community-edition` version changes.

In the `composer.json` file for `magento/project-community-edition` 2.3.1, that `sebastian/phpcpd` entry in `"require-dev"` has changed to `~3.0.0`, which is compatible with the `symfony/console` versions allowed by `magento/magento2-base` 2.3.1. However, without this plugin, Composer does not know that the value needs to change because the commands to upgrade Magento use the `magento/product-community-edition` metapackage and not the root `magento/project-community-edition` project package.

This is only one of the changes to the root project `composer.json` file between Magento 2.2.8 and 2.3.1. There are several others, and future Magento versions can (and likely will) require further updates to the file.

The changes to the root project `composer.json` files can be done manually by the user without the plugin, but the values that need to change can differ depending on the Magento versions involved and user-customized values may already override the Magento defaults. This means the exact upgrade steps necessary can be different for every user and determining the correct changes to make manually for a given user's configuration may be error-prone. 

For reference, these are the `"require"` and `"require-dev"` sections for default installations (no user customizations) of Magento Open Source versions 2.2.8 and 2.3.1. It is important to note that these sections of `composer.json` are not the only ones that can change between versions. The `"autoload"` and `"conflict"` sections, for example, can also affect Magento functionality and need to be kept up-to-date with the installed Magento versions.

 - **2.2.8**
   ```
    "require": {
        "magento/product-community-edition": "2.2.8",
        "composer/composer": "@alpha"
    },
    "require-dev": {
        "magento/magento2-functional-testing-framework": "2.3.13",
        "phpunit/phpunit": "~6.2.0",
        "squizlabs/php_codesniffer": "3.2.2",
        "phpmd/phpmd": "@stable",
        "pdepend/pdepend": "2.5.2",
        "friendsofphp/php-cs-fixer": "~2.2.1",
        "lusitanian/oauth": "~0.8.10",
        "sebastian/phpcpd": "2.0.4"
    }
   ```

 - **2.3.1**
   ```
    "require": {
        "magento/product-community-edition": "2.3.1"
    },
    "require-dev": {
        "friendsofphp/php-cs-fixer": "~2.13.0",
        "lusitanian/oauth": "~0.8.10",
        "magento/magento2-functional-testing-framework": "~2.3.13",
        "pdepend/pdepend": "2.5.2",
        "phpmd/phpmd": "@stable",
        "phpunit/phpunit": "~6.5.0",
        "sebastian/phpcpd": "~3.0.0",
        "squizlabs/php_codesniffer": "3.3.1",
        "allure-framework/allure-phpunit": "~1.2.0"
    }
   ```

### With `magento/composer-root-update-plugin`:

In the project directory for a Magento Open Source 2.2.8 installation, a user runs `composer require magento/composer-root-update-plugin ~1.1 --no-update` and `composer update` before the Magento Open Source 2.3.1 upgrade commands. 

```
$ composer require magento/composer-root-update-plugin ~1.1 --no-update
./composer.json has been updated
$ composer update
Loading composer repositories with package information
Updating dependencies (including require-dev)
Package operations: 1 install, 0 updates, 0 removals
  - Installing magento/composer-root-update-plugin (1.1.0): Downloading (100%)         
Installing "magento/composer-root-update-plugin: 1.1.0" for the Web Setup Wizard
Loading composer repositories with package information
Updating dependencies
Package operations: 18 installs, 0 updates, 0 removals
  - Installing ...
  ...
  - Installing magento/composer-root-update-plugin (1.1.0): Downloading (100%)
Writing lock file
Generating autoload files
Writing lock file
Generating autoload files
```

As is normal for `composer require`, `magento/composer-root-update-plugin` is added to the `composer.json` file. The plugin also installs itself in the directory used by the Magento Web Setup Wizard during dependency validation.

With the plugin installed, the user proceeds with the `composer require` command for Magento Open Source 2.3.1 (`--verbose` mode used here for demonstration).

```
$ composer require magento/product-community-edition 2.3.1 --no-update --verbose
 [Magento Open Source 2.3.1] Base Magento project package version: magento/project-community-edition 2.2.8
 [Magento Open Source 2.3.1] Removing require entries: composer/composer
 [Magento Open Source 2.3.1] Adding require-dev constraints: allure-framework/allure-phpunit=~1.2.0
 [Magento Open Source 2.3.1] Updating require-dev constraints: magento/magento2-functional-testing-framework=~2.3.13, phpunit/phpunit=~6.5.0, squizlabs/php_codesniffer=3.3.1, friendsofphp/php-cs-fixer=~2.13.0, sebastian/phpcpd=~3.0.0
 [Magento Open Source 2.3.1] Adding conflict constraints: gene/bluefoot=*
 [Magento Open Source 2.3.1] Updating autoload.psr-4.Zend\Mvc\Controller\ entry: "setup/src/Zend/Mvc/Controller/"
Updating composer.json for Magento Open Source 2.3.1 ...
 [Magento Open Source 2.3.1] Writing changes to the root composer.json...
 [Magento Open Source 2.3.1] <path>/composer.json has been updated
./composer.json has been updated
```

The plugin detects the user's request for the 2.3.1 version of `magento/product-community-edition` and looks up the `composer.json` file for the corresponding `magento/project-community-edition` 2.3.1 root project package. It finds the values that are different between 2.2.8 and 2.3.1 and updates the local `composer.json` file accordingly, then lets Composer proceed with the normal `composer require` functionality.

With the root `composer.json` file updated for Magento Open Source 2.3.1, the user proceeds with the `composer update` command:

```
$ composer update
Loading composer repositories with package information
Updating dependencies (including require-dev)
Package operations: 118 installs, 246 updates, 5 removals
  - Removing symfony/polyfill-php55 (v1.11.0)
  ...
Writing lock file
Generating autoload files
```

With the updated values from Magento Open Source 2.3.1, the `symfony/console` conflict no longer exists and the update occurs as expected.

For reference, these are the `"require"` and `"require-dev"` sections from the `composer.json` file after `composer require magento/product-community-edition 2.3.1 --no-update` runs with the plugin on a Magento Open Source 2.2.8 installation. They contain exactly the same entries as the default Magento Open Source 2.3.1 root `composer.json` file (with the addition of the `magento/composer-root-update-plugin` requirement).

   ```
    "require": {
        "magento/product-community-edition": "2.3.1",
        "magento/composer-root-update-plugin": "~1.1"
    },
    "require-dev": {
        "allure-framework/allure-phpunit": "~1.2.0",
        "magento/magento2-functional-testing-framework": "~2.3.13",
        "phpunit/phpunit": "~6.5.0",
        "squizlabs/php_codesniffer": "3.3.1",
        "phpmd/phpmd": "@stable",
        "pdepend/pdepend": "2.5.2",
        "friendsofphp/php-cs-fixer": "~2.13.0",
        "lusitanian/oauth": "~0.8.10",
        "sebastian/phpcpd": "~3.0.0"
    }
   ```

# License

Each Magento source file included in this distribution is licensed under OSL 3.0.

[Open Software License (OSL 3.0)](https://opensource.org/licenses/osl-3.0.php).

Please see [LICENSE.txt](https://github.com/magento/composer-root-update-plugin/blob/develop/LICENSE.txt) for the full text of the OSL 3.0 license or contact license@magentocommerce.com for a copy.
