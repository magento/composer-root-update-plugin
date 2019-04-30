# Class descriptions by namespace

 - [Magento\ComposerRootUpdatePlugin\ComposerReimplementation](#namespace-magentocomposerrootupdateplugincomposerreimplementation)
   - [AccessibleRootPackageLoader](#accessiblerootpackageloader)
   - [ExtendableRequireCommand](#extendablerequirecommand) 
 - [Magento\ComposerRootUpdatePlugin\Plugin](#namespace-magentocomposerrootupdatepluginplugin)
   - [Commands\MageRootRequireCommand](#commandsmagerootrequirecommand)
   - [Commands\UpdatePluginNamespaceCommands](#commandsupdatepluginnamespacecommands)
   - [CommandProvider](#commandprovider)
   - [PluginDefinition](#plugindefinition)
 - [Magento\ComposerRootUpdatePlugin\Setup](#namespace-magentocomposerrootupdatepluginsetup)
   - [InstallData/RecurringData/UpgradeData](#installdatarecurringdataupgradedata)
   - [WebSetupWizardPluginInstaller](#websetupwizardplugininstaller)
 - [Magento\ComposerRootUpdatePlugin\Updater](#namespace-magentocomposerrootupdatepluginupdater)
   - [DeltaResolver](#deltaresolver)
   - [MagentoRootUpdater](#magentorootupdater)
   - [RootPackageRetriever](#rootpackageretriever)
 - [Magento\ComposerRootUpdatePlugin\Utils](#namespace-magentocomposerrootupdatepluginutils)
   - [Console](#console)
   - [PackageUtils](#packageutils)

***

## Namespace: [Magento\ComposerRootUpdatePlugin\ComposerReimplementation](../src/Magento/ComposerRootUpdatePlugin/ComposerReimplementation)

Because the plugin is hooking into the native `composer require` functionality directly rather than adding script hooks or completely new commands, it needs access to some Composer functionality that is not normally extendable. The classes in this namespace copy the relevant sections of Composer library code into functions that are accessible by the plugin. New releases of Composer may change the library code these classes clone, in which case they should be updated to match.

#### [**AccessibleRootPackageLoader**](../src/Magento/ComposerRootUpdatePlugin/ComposerReimplementation/AccessibleRootPackageLoader.php)

**Composer class:** [RootPackageLoader](https://getcomposer.org/apidoc/master/Composer/Package/Loader/RootPackageLoader.htmlp)

 - **`extractStabilityFlags()`** -- see [RootPackageLoader::extractStabilityFlags()](https://github.com/composer/composer/blob/master/src/Composer/Package/Loader/RootPackageLoader.php)
   - Takes a package name, version, and minimum-stability setting and returns the stability level that should be used to find the package on a repository
   - **Reason for cloning:** The original method is private

#### [**ExtendableRequireCommand**](../src/Magento/ComposerRootUpdatePlugin/ComposerReimplementation/ExtendableRequireCommand.php)

**Composer class:** [RequireCommand](https://getcomposer.org/apidoc/master/Composer/Command/RequireCommand.html)

 - **`parseComposerJsonFile()`** -- see [RequireCommand::execute()](https://getcomposer.org/apidoc/master/Composer/Command/RequireCommand.html#method_execute)
   - Checks the accessibility of the `composer.json` file and parses out relevant base information that is needed before starting the plugin's processing
   - **Reason for cloning:** The native code exists directly in `RequireCommand::execute()` instead of its own function, but the base information it parses is required by the plugin before it runs as part of the original `RequireCommand` code
 - **`getRequirementsInteractive()`** -- see [InitCommand::determineRequirements()](https://getcomposer.org/apidoc/master/Composer/Command/InitCommand.html#method_determineRequirements)
   - Interactively asks for the `composer require` arguments if they are not passed to the CLI command call
   - **Reason for cloning:** The native command calls [InitCommand::findBestVersionAndNameForPackage()](https://github.com/composer/composer/blob/master/src/Composer/Command/InitCommand.php), which would try to validate the target Magento package's requirements before the plugin can process the relevant changes to make it compatible. The original `determineRequirements()` call is still made by `RequireCommand::execute()` after the plugin runs, so Composer's validation still happens as normal.
 - **`revertMageComposerFile()`** -- see [RequireCommand::revertComposerFile()](https://getcomposer.org/apidoc/master/Composer/Command/RequireCommand.html#method_revertComposerFile)
   - Reverts the `composer.json` file to its original state from before the plugin's changes if the command fails
   - **Reason for cloning:** The plugin makes changes before `RequireCommand` creates a backup, which means when it runs `revertComposerFile()`, the reverted file from the backup does not match the original state, so this function is needed to also revert the plugin's changes

***

## Namespace: [Magento\ComposerRootUpdatePlugin\Plugin](../src/Magento/ComposerRootUpdatePlugin/Plugin)

Classes in this namespace tie into the Composer library's code that handles plugin registry and functionality hooks.

#### [**Commands\MageRootRequireCommand**](../src/Magento/ComposerRootUpdatePlugin/Plugin/Commands/MageRootRequireCommand.php)

This class is the entrypoint into the plugin's functionality from the `composer require` CLI command.
   
Extends the native [RequireCommand](https://getcomposer.org/apidoc/master/Composer/Command/RequireCommand.html) functionality to add additional processing when run with a Magento product as one of the command's parameters.
   
 - **`configure()`**
   - Add the options and description for the plugin functionality to those already configured in `RequireCommand` and sets the new command's name to a dummy unique value so it passes Composer's command registry check
 - **`setApplication()`**
   - Overrides the command's name to `require` after the command registry is checked but before the command is actually added to the registry. This allows the command to replace the native `RequireCommand` instance that is normally associated with the `composer require` CLI command
 - **`execute()`**
   - Wraps the native `RequireCommand::execute()` function with the Magento project update code if a Magento product package is found in the command's parameters
 - **`runUpdate()`**
   - Calls [MagentoRootUpdater::runUpdate()](#magentorootupdater) after processing CLI options
     
#### [**Commands\UpdatePluginNamespaceCommands**](../src/Magento/ComposerRootUpdatePlugin/Plugin/Commands/UpdatePluginNamespaceCommands.php)

CLI command definition for plugin-specific functionality that is not attached to other native commands, adding them as sub-commands called through `composer magento-update-plugin <command>`.
   
Currently, the only sub-command included is `composer magento-update-plugin install`, which updates the plugin's self-installation inside the project's `var` directory, which is necessary for the Web Setup Wizard (see [WebSetupWizardPluginInstaller](#websetupwizardplugininstaller)).
   
 - **`configure()`**
   - Configure the command definition for Composer's CLI command processing. Sub-command descriptions are included in the command's `help` text
 - **`execute()`**
   - Check the sub-command parameter and call the corresponding function
 - **`describeOperations()`**
   - Format the sub-command definitions into a readable description for the command's `help` text

#### [**CommandProvider**](../src/Magento/ComposerRootUpdatePlugin/Plugin/CommandProvider.php)

This is a Composer boilerplate class to let the Composer plugin library know about the commands implemented by the plugin.
     
 - **`getCommands()`**
     - Passes instances of the commands provided by the plugin to the Composer library
     
#### [**PluginDefinition**](../src/Magento/ComposerRootUpdatePlugin/Plugin/PluginDefinition.php)

This class is Composer's entry point into the plugin's functionality and the definition supplied to the plugin registry.
   
 - **`activate()`**
   - Method must exist in any implementation of [PluginInterface](https://getcomposer.org/apidoc/master/Composer/Plugin/PluginInterface.html)
 - **`getCapabilities()`**
   - Tells Composer that the plugin includes CLI commands and defines the [CommandProvider](#commandprovider) that supplies the command objects
 - **`getSubscribedEvents()`**
   - Subscribes to the `POST_PACKAGE_INSTALL` and `POST_PACKAGE_UPDATE` events, which are triggered whenever a project's dependencies are updated
 - **`packageUpdate()`**
   - When one of the package events subscribed in `getSubscribedEvents()` is triggered, this method forwards the event to [WebSetupWizardPluginInstaller::packageEvent()](#websetupwizardplugininstaller) to update the plugin's self-installation inside the Magento project's `var` directory, which is necessary for the Web Setup Wizard

***

## Namespace: [Magento\ComposerRootUpdatePlugin\Setup](../src/Magento/ComposerRootUpdatePlugin/Setup)

Classes in this namespace deal with installing the plugin inside the project's `var` directory, which is necessary for the Magento Web Setup Wizard to pass its verification check. 
  
When the Web Setup Wizard runs an upgrade operation, it first tries to validate the upgrade by copying the `composer.json` file into the `var` directory and attempting a dry-run upgrade. However, because it only copies the `composer.json` file and not any of the other code in the installation (including the plugin's root installation in `vendor`), the plugin will not function for this dry run. In order to enable the plugin, it needs to already be present in `var/vendor`, where the Wizard's `composer require` for the validation will find it.

#### **[InstallData](../src/Magento/ComposerRootUpdatePlugin/Setup/InstallData.php)/[RecurringData](../src/Magento/ComposerRootUpdatePlugin/Setup/RecurringData.php)/[UpgradeData](../src/Magento/ComposerRootUpdatePlugin/Setup/UpgradeData.php)**

These are Magento module setup classes to trigger [WebSetupWizardPluginInstaller::doVarInstall()](#websetupwizardplugininstaller) on `bin/magento setup` commands. Specifically, this is necessary when the `bin/magento setup:uninstall` and `bin/magento setup:install` commands are run, which would otherwise remove the plugin from the `var` directory without triggering the Composer package events that would normally install the plugin there.
     
#### [**WebSetupWizardPluginInstaller**](../src/Magento/ComposerRootUpdatePlugin/Setup/WebSetupWizardPluginInstaller.php)

This class manages the plugin's self-installation inside the `var` directory to enable it for the Web Setup Wizard.

 - **`packageEvent()`**
   - When Composer installs or updates a required package, this method checks whether it was the plugin package that changed and calls `updateSetupWizardPlugin()` with the new version if so
   - Triggered by the events defined in [PluginDefinition::getSubscribedEvents()](#plugindefinition)
 - **`doVarInstall()`**
   - Checks the `composer.lock` file the plugin and calls `updateSetupWizardPlugin()` with the version found there
   - Called by `composer magento-update-plugin install` and the Magento module setup classes ([InstallData](#installdatarecurringdataupgradedata), [RecurringData](#installdatarecurringdataupgradedata), [UpgradeData](#installdatarecurringdataupgradedata))
 - **`updateSetupWizardPlugin()`**
   - Installs the plugin inside `var/vendor` where it can be found by the `composer require` command run by the Web Setup Wizard's validation check. This is accomplished by creating a dummy project directory with a `composer.json` file that requires only the plugin, installing it, then copying the resulting `vendor` directory to `var/vendor`
 - **`deletePath()`**
   - Recursively deletes a file or directory and all its contents
 - **`copyAndReplace()`**
   - Recursively copies a directory and all its contents to a new location
 - **`createPluginComposer()`**
   - Creates a temporary `composer.json` file requiring only the plugin's Composer package

***

## Namespace: [Magento\ComposerRootUpdatePlugin\Updater](../src/Magento/ComposerRootUpdatePlugin/Updater)

Classes in this namespace do the work of calculating and executing the changes to the root project `composer.json` file that need to be made when updating to a new Magento package version.

#### [**DeltaResolver**](../src/Magento/ComposerRootUpdatePlugin/Updater/DeltaResolver.php)

Given the target Magento root project package, the original (default) Magento root project package for the currently-installed Magento version, and the currently-installed root project package including all user customizations, this class calculates the new values that need to be updated for the target Magento version.
     
This is accomplished by comparing `composer.json` fields between the original Magento root and the target root, and, when a delta is found, checking to see if the user has already made custom changes to that field. If a change has been made, if it does not already match the target Magento value, resolve the conflict according to the strategy passed to the CLI command: use the user's custom value, override with the target Magento value, or interactively ask the user which of the two values should be used on a case-by-case basis.

 - **`resolveRootDeltas()`**
   - Entry point into the resolution functionality
   - Calls the relevant resolve function for each `composer.json` field that can be updated
 - **`findResolution()`**
   - For an individual field value, compare the original Magento value to the target Magento value, and if a delta is found, check if the user's installation has a customized value for the field. If the user has changed the value, resolve the conflict according to the CLI command options: use the user's custom value, override with the target Magento value, or interactively ask the user which of the two values should be used
 - **`resolveLinkSection()`**
   - For a given `composer.json` section that consists of links to package versions/constraints (such as the `require` and `conflict` sections), call `findLinkResolution()` for each package constraint found in either the original Magento root or the target Magento root
 - **`resolveArraySection()`**
   - For a given `composer.json` section that consists of data that is not package links (such as the `"autoload"` or `"extra"` sections), call `resolveNestedArray()` and accept the new values if changes were made
 - **`resolveNestedArray()`**
   - Recursively processes changes to a `composer.json` value that could be a nested array, calling `findResolution()` for each "leaf" value found in either the original Magento root or the target Magento root
 - **`findLinkResolution()`**
   - Helper function to call `findResolution()` for a particular package for use by `resolveLinkSection()`
 - **`getLinkOrderOverride()`**
   - Determine the order to use for a link section when the user's order disagrees with the target Magento section order
 - **`buildLinkOrderComparator()`**
   - Construct the comparator function to use for sorting a set of links according to `getLinkOverride()` results followed by the order in the target Magento version followed by the order of custom values in the user's installation

#### [**MagentoRootUpdater**](../src/Magento/ComposerRootUpdatePlugin/Updater/MagentoRootUpdater.php)

This class runs [DeltaResolver::resolveRootDeltas()](#deltaresolver) if an update is required, tracks the results, and writes the changes out to the `composer.json` file.

 - **`runUpdate()`**
   - Checks if the target Magento package differs from the original package, and if so runs DeltaResolver and tracks the results
 - **`writeUpdatedComposerFile()`**
   - Takes the result values from DeltaResolver and overwrites the corresponding values in the root `composer.json` file

#### [**RootPackageRetriever**](../src/Magento/ComposerRootUpdatePlugin/Updater/RootPackageRetriever.php)

This class contains methods to retrieve Composer [Package](https://getcomposer.org/apidoc/master/Composer/Package/Package.html) objects for the target Magento root project package, the original (default) Magento root project package for the currently-installed Magento version, and the currently-installed root project package (including all user customizations).

 - **`getOriginalRootPackage()`**
   - Fetches the original (default) Magento root project package from the Composer repository
 - **`getTargetRootPackage()`**
   - Fetches the target Magento root project package from the Composer repository
 - **`getUserRootPackage()`**
   - Returns the existing root project package, including all user customizations
 - **`fetchMageRootFromRepo()`**
   - Given a Magento edition and version constraint, fetch the best-fit Magento root project package from the Composer repository
 - **`parseOriginalVersionAndEditionFromLock()`**
   - Inspect the `composer.lock` file for the currently-installed Magento product package and parse out the edition and version for use by `getOriginalRootPackage()`
 - **`getRootLocker()`**
   - Helper function to get the [Locker](https://getcomposer.org/apidoc/master/Composer/Package/Locker.html) object for the `composer.lock` file in the project root directory. If the current working directory is `var` (which is the case for the Web Setup Wizard), instead use the `composer.lock` file in the parent directory

***

## Namespace: [Magento\ComposerRootUpdatePlugin\Utils](../src/Magento/ComposerRootUpdatePlugin/Utils) 

This namespace contains utility classes shared across the rest of the plugin's codebase.

#### [**Console**](../src/Magento/ComposerRootUpdatePlugin/Utils/Console.php)

Command-line logger with interaction methods.
   
 - **`getIO()`**
   - Returns the [IOInterface](https://getcomposer.org/apidoc/master/Composer/IO/IOInterface.html) instance
 - **`ask()`**
   - Asks the user a yes or no question and return the result. If the console interface has been configured as non-interactive, it does not ask and returns the default value
 - **`log()`**
   - Logs the given message if the verbosity level is appropriate
 - **`info()`**/**`comment()`**/**`warning()`**/**`error()`**/**`labeledVerbose()`**
   - Helper methods to format and log messages of different types/verbosity levels
 
#### [**PackageUtils**](../src/Magento/ComposerRootUpdatePlugin/Utils/PackageUtils.php)
   
Common package-related utility functions.
   
 - **`getMagentoPackageType()`**
   - Extracts the package type (`product` or `project`) from a Magento package name
 - **`getMagentoProductEdition()`**
   - Extracts the package edition from a Magento product package name
 - **`findRequire()`**
   - Searches the `"require"` section of a [Composer](https://getcomposer.org/apidoc/master/Composer/Composer.html) object for a package link that fits the supplied name or matcher
 - **`isConstraintStrict()`**
   - Checks if a version constraint is strict or if it allows multiple versions (such as `~1.0` or `>= 1.5.3`)
