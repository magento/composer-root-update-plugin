# Class descriptions by namespace

 - [Magento\ComposerRootUpdatePlugin\ComposerReimplementation](#namespace-magentocomposerrootupdateplugincomposerreimplementation)
   - [AccessibleRootPackageLoader](#accessiblerootpackageloader)
   - [ExtendableRequireCommand](#extendablerequirecommand) 
 - [Magento\ComposerRootUpdatePlugin\Plugin](#namespace-magentocomposerrootupdatepluginplugin)
   - [Commands\RequireCommerceCommand](#commandsrequirecommercecommand)
   - [Commands\OverrideRequireCommand](#deprecated-commandsoverriderequirecommand)
   - [CommandProvider](#commandprovider)
   - [PluginDefinition](#plugindefinition)
 - [Magento\ComposerRootUpdatePlugin\Updater](#namespace-magentocomposerrootupdatepluginupdater)
   - [DeltaResolver](#deltaresolver)
   - [RootProjectUpdater](#rootprojectupdater)
   - [RootPackageRetriever](#rootpackageretriever)
 - [Magento\ComposerRootUpdatePlugin\Utils](#namespace-magentocomposerrootupdatepluginutils)
   - [Console](#console)
   - [PackageUtils](#packageutils)

***

## Namespace: [Magento\ComposerRootUpdatePlugin\ComposerReimplementation](../src/Magento/ComposerRootUpdatePlugin/ComposerReimplementation)

Because the plugin is hooking into the native `composer require` functionality directly rather than adding script hooks, it needs access to some Composer functionality that is not normally extendable. The classes in this namespace copy the relevant sections of Composer library code into functions that are accessible by the plugin. New releases of Composer may change the library code these classes clone, in which case they must be updated to match.

#### [**AccessibleRootPackageLoader**](../src/Magento/ComposerRootUpdatePlugin/ComposerReimplementation/AccessibleRootPackageLoader.php)

**Composer class:** [RootPackageLoader](https://getcomposer.org/apidoc/master/Composer/Package/Loader/RootPackageLoader.html)

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
   - **Reason for cloning:** The native command calls [InitCommand::findBestVersionAndNameForPackage()](https://github.com/composer/composer/blob/master/src/Composer/Command/InitCommand.php), which would try to validate the target metapackage's requirements before the plugin can process the relevant changes to make it compatible. The original `determineRequirements()` call is still made by `RequireCommand::execute()` after the plugin runs, so Composer's validation still happens as normal.
 - **`revertRootComposerFile()`** -- see [RequireCommand::revertComposerFile()](https://getcomposer.org/apidoc/master/Composer/Command/RequireCommand.html#method_revertComposerFile)
   - Reverts the `composer.json` file to its original state from before the plugin's changes if the command fails
   - **Reason for cloning:** The plugin makes changes before `RequireCommand` creates a backup, which means when it runs `revertComposerFile()`, the reverted file from the backup does not match the original state, so this function is needed to also revert the plugin's changes

***

## Namespace: [Magento\ComposerRootUpdatePlugin\Plugin](../src/Magento/ComposerRootUpdatePlugin/Plugin)

Classes in this namespace tie into the Composer library's code that handles plugin registry and functionality hooks.

#### [**Commands\RequireCommerceCommand**](../src/Magento/ComposerRootUpdatePlugin/Plugin/Commands/RequireCommerceCommand.php)

This class is the entrypoint into the plugin's functionality from the `composer require-commerce` CLI command.
   
Extends the native [RequireCommand](https://getcomposer.org/apidoc/master/Composer/Command/RequireCommand.html) functionality to add additional processing when run with a magento/product or magento/magento-cloud metapackage as one of the command's parameters.
   
 - **`configure()`**
   - Add the options and description for the plugin functionality to those already configured in `RequireCommand` and sets the new command's name to a dummy unique value so it passes Composer's command registry check
 - **`getFormattedHelp()`**
   - Helper function to get the command's help text formatted for the current terminal size
 - **`execute()`**
   - Wraps the native `RequireCommand::execute()` function with the root project update code
 - **`runUpdate()`**
   - Calls [RootProjectUpdater::runUpdate()](#rootprojectupdater) after processing CLI options
 - **`convertBaseEditionOption()`**
   - Validates the base edition option value and convert it to the internal edition designator
 - **`parseMetapackageRequirement()`**
   - Parses the CLI command arguments for a magento/product or magento/magento-cloud metapackage requirement

#### [**DEPRECATED: Commands\OverrideRequireCommand**](../src/Magento/ComposerRootUpdatePlugin/Plugin/Commands/OverrideRequireCommand.php)

- As of Composer 2.1.6, the native `composer require` command cannot be directly extended. This class has been deprecated and replaced with [RequireCommerceCommand](#commandsrequirecommercecommand), which moves the new functionality to `composer require-commerce`.

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

***

## Namespace: [Magento\ComposerRootUpdatePlugin\Updater](../src/Magento/ComposerRootUpdatePlugin/Updater)

Classes in this namespace do the work of calculating and executing the changes to the root project `composer.json` file that need to be made when updating to a new magento/product or magento/magento-cloud metapackage version.

#### [**DeltaResolver**](../src/Magento/ComposerRootUpdatePlugin/Updater/DeltaResolver.php)

Given the target root project package, the original (default) root project package for the currently-installed Magento Open Source or Adobe Commerce version, and the currently-installed root project package including all user customizations, this class calculates the new values that need to be updated for the target metapackage version.
     
This is accomplished by comparing `composer.json` fields between the original project root and the target root, and, when a delta is found, checking to see if the user has already made custom changes to that field. If a change has been made, if it does not already match the target project's value, resolve the conflict according to the strategy passed to the CLI command. If the `--force-root-updates` option was specified, override with the target project's value. If `--interactive-root-conflicts` was specified, the command interactively asks the user which of the two values should be used on a case-by-case basis. Otherwise, the command keeps the user's values that are in conflict and displays a warning message.

 - **`resolveRootDeltas()`**
   - Entry point into the resolution functionality
   - Calls the relevant resolve function for each `composer.json` field that can be updated
 - **`findResolution()`**
   - For an individual field value, compare the original and target values, and if a delta is found, check if the user's installation has a customized value for the field. If yes, then apply the appropriate resolution
 - **`prettify()`**
   - Formats a field value to be human-readable if a preset pretty value is not present
 - **`solveIfConflict()`**
   - If the user has a field value that conflicts with an expected delta, resolve the conflict according to the CLI command options: use the user's custom value, override with the target project's value, or interactively ask the user which of the two values should be used
 - **`resolveLinkSection()`**
   - For a given `composer.json` section that consists of links to package versions/constraints (such as the `require` and `conflict` sections), call `findLinkResolution()` for each package constraint found in either the original or target project's root composer.json
 - **`resolveLink()`**
   - Helper function to call `findResolution()` for a particular package for use by `resolveLinkSection()`
 - **`getConstraintValues()`**
   - Helper function to get the raw and pretty forms of a link for comparison
 - **`applyLinkChanges()`**
   - Adjust the json values for a link section according to the resolutions calculated by `resolveLinkSection()`
 - **`resolveArraySection()`**
   - For a given `composer.json` section that consists of data that is not package links (such as the `"autoload"` or `"extra"` sections), call `resolveNestedArray()` and accept the new values if changes were made
 - **`resolveNestedArray()`**
   - Recursively processes changes to a `composer.json` value that could be a nested array, calling `findResolution()` for each "leaf" value found in either the original or target project's root composer.json
 - **`resolveFlatArray()`**
   - Process changes to the non-associative portion of an array field value, treating it as an unordered set
 - **`resolveAssociativeArray()`**
   - Process changes to the associative portion of an array field value that could contain nested arrays
 - **`getLinkOrderOverride()`**
   - Determine the order to use for a link section when the user's order disagrees with the target project's section order
 - **`buildLinkOrderComparator()`**
   - Construct the comparator function to use for sorting a set of links according to `getLinkOverride()` results followed by the order in the target project's version followed by the order of custom values in the user's installation

#### [**RootProjectUpdater**](../src/Magento/ComposerRootUpdatePlugin/Updater/RootProjectUpdater.php)

This class runs [DeltaResolver::resolveRootDeltas()](#deltaresolver) if an update is required, tracks the results, and writes the changes out to the `composer.json` file.

 - **`runUpdate()`**
   - Checks if the target metapackage differs from the original package, and if so runs DeltaResolver and tracks the results
 - **`writeUpdatedComposerFile()`**
   - Takes the result values from DeltaResolver and overwrites the corresponding values in the root `composer.json` file

#### [**RootPackageRetriever**](../src/Magento/ComposerRootUpdatePlugin/Updater/RootPackageRetriever.php)

This class contains methods to retrieve Composer [Package](https://getcomposer.org/apidoc/master/Composer/Package/Package.html) objects for the target and original (default) Magento Open Source or Adobe Commerce root project packages, and the currently-installed root project package (including all user customizations).

 - **`getOriginalRootPackage()`**
   - Fetches the original (default) root project package from the Composer repository or GitHub (in the case of Adobe Commerce Cloud)
 - **`getTargetRootPackage()`**
   - Fetches the target Magento Open Source or Adobe Commerce root project package from the Composer repository or GitHub (in the case of Adobe Commerce Cloud)
 - **`getUserRootPackage()`**
   - Returns the existing root project package, including all user customizations
 - **`fetchProjectFromRepo()`**
   - Given a metapackage edition and version constraint, fetch the best-fit Magento Open Source or Adobe Commerce root project package from the Composer repository or GitHub (in the case of Adobe Commerce Cloud)
 - **`findBestCandidate()`**
   - Wrapper function around different versions of [VersionSelector::findBestCandidate()](https://getcomposer.org/apidoc/master/Composer/Package/Version/VersionSelector.html)
 - **`findBestCandidateComposer1()`**
   - Helper function to run [VersionSelector::findBestCandidate()](https://getcomposer.org/apidoc/master/Composer/Package/Version/VersionSelector.html) on Composer version 1.x.x
 - **`findBestCandidateComposer2()`**
   - Helper function to run [VersionSelector::findBestCandidate()](https://getcomposer.org/apidoc/master/Composer/Package/Version/VersionSelector.html) on Composer version 2.x.x
 - **`parseVersionAndEditionFromLock()`**
   - Inspect the `composer.lock` file for the currently-installed magento/product or magento/magento-cloud metapackage and parse out the edition and version for use by `getOriginalRootPackage()`
 - **`getTargetLabel()`**
   - Gets the formatted label for the target Magento Open Source or Adobe Commerce version
 - **`getOriginalLabel()`**
   - Gets the formatted label for the originally-installed Magento Open Source or Adobe Commerce version

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
 - **`formatString()`**
   - Wraps the given text in console format tags
 
#### [**PackageUtils**](../src/Magento/ComposerRootUpdatePlugin/Utils/PackageUtils.php)
   
Common package-related utility functions.
   
 - **`getMetapackageEdition()`**
   - Extracts the package edition from a magento/product or magento/magento-cloud metapackage name
   - For the purposes of this plugin, 'cloud' is considered an edition
 - **`getProjectPackageName()`**
   - Constructs the project package name from an edition
 - **`getMetapackageName()`**
   - Constructs the metapackage name from an edition
 - **`getEditionLabel()`**
   - Translates package edition into the marketing edition label
 - **`findRequire()`**
   - Searches the `"require"` section of a [Composer](https://getcomposer.org/apidoc/master/Composer/Composer.html) object for a package link that fits the supplied name or matcher
 - **`isConstraintStrict()`**
   - Checks if a version constraint is strict or if it allows multiple versions (such as `~1.0` or `>= 1.5.3`)
 - **`getLockedProduct()`**
   - Gets the installed magento/product or magento/magento-cloud metapackage from the composer.lock file if it exists
 - **`getRootLocker()`**
   - Helper function to get the [Locker](https://getcomposer.org/apidoc/master/Composer/Package/Locker.html) object for the `composer.lock` file in the project root directory.
