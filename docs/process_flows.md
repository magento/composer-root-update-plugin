# Plugin operation flow explanations and diagrams

## `composer require-commerce <metapackage>`

**Scenario:** The user has an installed Magento Open Source or Adobe Commerce project and wants to upgrade to a new version. They call `composer require-commerce <metapackage>` from the command line

1. Composer boilerplate and plugin setup
   1. Composer sees the `"type": "composer-plugin"` value in the [composer.json](../src/Magento/ComposerRootUpdatePlugin/composer.json) file for the plugin package
   2. Composer reads the `"extra"->"class"` field to find the class that implements [PluginInterface](https://getcomposer.org/apidoc/master/Composer/Plugin/PluginInterface.html) ([PluginDefinition](class_descriptions.md#plugindefinition))
   3. `PluginDefinition` implements [Capable](https://getcomposer.org/apidoc/master/Composer/Plugin/Capable.html), telling Composer that it provides some capability ([CommandProvider](class_descriptions.md#commandprovider)), which is supplied through `PluginDefinition::getCapabilities()`
   4. `CommandProvider::getCommands()` supplies Composer with an instance of [RequireCommerceCommand](class_descriptions.md#commandsrequirecommercecommand)
   5. Composer calls `RequireCommerceCommand::configure()` to obtain the command's name, description, options, and help text
      - `RequireCommerceCommand` extends Composer's native [RequireCommand](https://getcomposer.org/apidoc/master/Composer/Command/RequireCommand.html) and adds its own values to those in the existing implementation
   6. Composer adds `RequireCommerceCommand` to the registry
2. Composer recognizes `require-commerce` as the command passed to the executable and finds `RequireCommerceCommand` as the command object registered under that name
3. Composer calls `RequireCommerceCommand::execute()`
4. `RequireCommerceCommand::execute()` backs up the user's `composer.json` file through [ExtendableRequireCommand::parseComposerJsonFile()](class_descriptions.md#extendablerequirecommand)
5. `RequireCommerceCommand::execute()` calls `RequireCommerceCommand::runUpdate()`
6. `RequireCommerceCommand::runUpdate()` calls `RequireCommerceCommand::parseMetapackageRequirement()` to check the `composer require-commerce` arguments for a `magento/product` or `magento/magento-cloud` metapackage
7. If a `magento/product` or `magento/magento-cloud` metapackage is found in the command arguments, it calls [RootProjectUpdater::runUpdate()](class_descriptions.md#rootprojectupdater)
8. `RootProjectUpdater::runUpdate()` calls [DeltaResolver::resolveRootDeltas()](class_descriptions.md#deltaresolver)
9. `DeltaResolver::resolveRootDeltas()` uses [RootPackageRetriever](class_descriptions.md#rootpackageretriever) to obtain the Composer [Package](https://getcomposer.org/apidoc/master/Composer/Package/Package.html) objects for the root `composer.json` files from the default installation of the existing edition and version, the target edition and version supplied to the `composer require-commerce` call, and the user's current installation including any customizations they have made 
10. `DeltaResolver::resolveRootDeltas()` iterates over the fields in `composer.json` to determine any values that need to be updated to match the root project's `composer.json` file of the new Magento Open Source or Adobe Commerce edition/version
   1. To find these values, it compares the values for each field in the default project for the installed edition/version with the project for the target edition/version (`DeltaResolver::findResolution()`)
   2. If a value has changed in the target, it checks that field in the user's customized root `composer.json` file to see if it has been overwritten with a custom value
   3. If the user customized the value, the conflict will be resolved according to the specified resolution strategy: use the expected project's value, use the user's custom value, or prompt the user to specify which value should be used
11. If `resolveRootDeltas()` found values that need to change, `RequireCommerceCommand::runUpdate()` calls `RootProjectUpdater::writeUpdatedComposerJson()` to apply those changes 
12. `RequireCommerceCommand::execute()` calls the native `RequireCommand::execute()` function, which will now use the updated root `composer.json` file if the plugin made changes
13. If the `RequireCommand::execute()` call fails after the plugin makes changes, `RequireCommerceCommand::execute()` calls `ExtendableRequireCommand::revertRootComposerFile()` to restore the `composer.json` file to its original state
