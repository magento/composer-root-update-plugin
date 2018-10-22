<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Composer\Plugin\RootUpdate;

use Composer\Composer;
use Composer\Command\UpdateCommand;
use Composer\DependencyResolver\Pool;
use Composer\Downloader\FilesystemException;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class RootUpdateCommand
 *
 * @package Magento\Composer\Plugin\RootUpdate
 */
class RootUpdateCommand extends UpdateCommand
{
    /**
     * CLI Options
     */
    const SKIP_OPT = 'skip-magento-root';
    const OVERRIDE_OPT = 'use-magento-values';
    const INTERACTIVE_OPT = 'interactive-magento-conflicts';
    const ROOT_ONLY_OPT = 'magento-root-only';

    /**
     * Types of action to take on individual values when a delta is found; returned by findResolution()
     */
    const ADD_VAL = 'add_value';
    const REMOVE_VAL = 'remove_value';
    const CHANGE_VAL = 'change_value';

    /**
     * @var string $filePath Path to the relevant composer.json file
     */
    private $filePath;

    /**
     * @var bool $interactiveInput Is the current terminal interactive
     */
    private $interactiveInput;

    /**
     * @var bool $override Has OVERRIDE_OPT been passed to the command
     */
    private $override;

    /**
     * @var bool $interactive Has INTERACTIVE_OPT been passed to the command
     */
    private $interactive;

    /**
     * @var string $targetLabel Pretty label for the target Magento edition version
     */
    private $targetLabel;

    /**
     * @var string $baseLabel Pretty label for the current installation's Magento edition version
     */
    private $baseLabel;

    /**
     * @var array $jsonChanges Json-writable sections of composer.json that have been updated
     */
    private $jsonChanges;

    /**
     * @var boolean $fuzzyConstraint
     */
    private $fuzzyConstraint;

    /**
     * @var string $targetProduct
     */
    private $targetProduct;

    /**
     * @var string $targetConstraint
     */
    private $targetConstraint;

    /**
     * RootUpdateCommand constructor
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->filePath = null;
        $this->interactiveInput = false;
        $this->override = false;
        $this->interactive = false;
        $this->baseLabel = null;
        $this->targetLabel = null;
        $this->jsonChanges = [];
        $this->fuzzyConstraint = false;
        $this->targetProduct = null;
        $this->targetConstraint = null;
    }

    /**
     * Call the parent setApplication method but also change the command's name to update
     *
     * @param Application|null $application
     * @return void
     */
    public function setApplication(Application $application = null)
    {
        // In order to trick Composer into overriding its native UpdateCommand with this object, the name needs to be
        // different before Application->add() is called to pass the verification check but changed to update before
        // being added to the command registry
        $this->setName('update');
        parent::setApplication($application);
    }

    /**
     * Use the native UpdateCommand config with options/doc additions for the Magento root composer.json update
     *
     * @return void
     */
    public function configure()
    {
        parent::configure();
        $this->setName('update-magento-root');
        $this->addOption(
            static::SKIP_OPT,
            null,
            null,
            'Skip the Magento root composer.json update.'
        );
        $this->addOption(
            static::OVERRIDE_OPT,
            null,
            null,
            'Override conflicting root composer.json customizations with expected Magento project values.'
        );
        $this->addOption(
            static::INTERACTIVE_OPT,
            null,
            null,
            'Interactive interface to resolve conflicts during the Magento root composer.json update.'
        );
        $this->addOption(
            static::ROOT_ONLY_OPT,
            null,
            null,
            'Update the root composer.json file with Magento changes without running the rest of the update process.'
        );

        $mageHelp = '
<comment>Magento Root Updates:</comment>
  With <info>magento/composer-root-update-plugin</info> installed, <info>update</info> will also check for and
  execute any changes to the root composer.json file that exist between the Magento
  project package corresponding to the currently-installed version and the project
  for the target Magento product version if the package requirement has changed.
  
  By default, any changes that would affect values that have been customized in the
  existing installation will not be applied. Using <info>--' . static::OVERRIDE_OPT . '</info> will instead
  apply all deltas found between the expected base project and the new version,
  overriding any custom values. Use <info>--' . static::INTERACTIVE_OPT . '</info> to interactively
  resolve deltas that conflict with the existing installation.
  
  To skip the Magento root composer.json update, use <info>--' . static::SKIP_OPT . '</info>.
';
        $this->setHelp($this->getHelp() . $mageHelp);

        $mageDesc = ' If a Magento metapackage change is found, also make any associated composer.json changes.';
        $this->setDescription($this->getDescription() . $mageDesc);
    }

    /**
     * Look ahead at the target Magento version for root composer.json changes before running composer's native update
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null null or 0 if everything went fine, or an error code
     * @throws FilesystemException if the write operation failed when ROOT_ONLY_OPT is passed
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->interactiveInput = $input->isInteractive();
        if ($input->getOption('dry-run')) {
            $output->setVerbosity(max(OutputInterface::VERBOSITY_VERBOSE, $output->getVerbosity()));
            $input->setOption('verbose', true);
        }

        $composer = $this->getComposer();

        $this->filePath = $composer->getConfig()->getConfigSource()->getName();

        $updatePrepared = false;
        try {
            $updatePrepared = $this->magentoUpdate($input, $composer);
        } catch (\Exception $e) {
            $this->getIO()->writeError('<error>Magento root update operation failed</error>', true, IOInterface::QUIET);
            $this->getIO()->writeError($e->getMessage());
        }

        $errorCode = null;
        if (!$input->getOption(static::ROOT_ONLY_OPT)) {
            $errorCode = parent::execute($input, $output);

            if ($errorCode && $this->fuzzyConstraint) {
                $this->getIO()->writeError(
                    '<warning>Recommended: Use a specific Magento version constraint instead of "' .
                    $this->targetProduct . ': ' . $this->targetConstraint . '"</warning>',
                    true,
                    IOInterface::QUIET
                );
            }
        } elseif (!$input->getOption('dry-run') && $updatePrepared) {
            // If running a full update, writeUpdatedRoot() is called as a post-update-cmd event
            $this->writeUpdatedRoot();
        }

        return $errorCode;
    }

    /**
     * Look ahead to the target Magento version and execute any changes to the root composer.json file in-memory
     *
     * @param InputInterface $input
     * @param Composer $composer
     * @return boolean Returns true if updates were successfully prepared, false if no updates were necessary
     */
    public function magentoUpdate($input, $composer)
    {
        if ($input->getOption('no-custom-installers')) {
            // --no-custom-installers has been replaced with --no-plugins, which would have skipped this functionality
            return false;
        }

        $io = $this->getIO();
        // Move the native UpdateCommand's deprecation message before the added Magento functionality
        if ($input->getOption('dev')) {
            $io->writeError('<warning>' .
                'You are using the deprecated option "dev". Dev packages are installed by default now.' .
                '</warning>');
            $input->setOption('dev', false);
        };

        $locker = $composer->getLocker();
        $skipped = $input->getOption(static::SKIP_OPT);
        $this->override = $input->getOption(static::OVERRIDE_OPT);
        $this->interactive = $input->getOption(static::INTERACTIVE_OPT);

        if ($locker->isLocked() && !$skipped) {
            $installRoot = $composer->getPackage();
            $targetRoot = null;
            $targetConstraint = null;
            foreach ($installRoot->getRequires() as $link) {
                $packageInfo = static::getMagentoPackageInfo($link->getTarget());
                if ($packageInfo !== null) {
                    $targetConstraint = $link->getPrettyConstraint() ??
                        $link->getConstraint()->getPrettyString() ??
                        $link->getConstraint()->__toString();
                    $edition = $packageInfo['edition'];
                    $this->targetProduct = "magento/product-$edition-edition";
                    $this->targetConstraint = $targetConstraint;
                    $targetRoot = $this->fetchRoot(
                        $edition,
                        $targetConstraint,
                        $composer,
                        $input,
                        true
                    );
                    break;
                }
            }
            if ($targetRoot == null || $targetRoot == false) {
                throw new \RuntimeException('Magento root updates cannot run without a valid target package');
            }

            $targetVersion = $targetRoot->getVersion();
            $prettyTargetVersion = $targetRoot->getPrettyVersion() ?? $targetVersion;
            $targetEd = static::getMagentoPackageInfo($targetRoot->getName())['edition'];

            $baseEd = null;
            $baseVersion = null;
            $prettyBaseVersion = null;
            $lockPackages = $locker->getLockedRepository()->getPackages();
            foreach ($lockPackages as $lockedPackage) {
                $packageInfo = static::getMagentoPackageInfo($lockedPackage->getName());
                if ($packageInfo !== null && $packageInfo['type'] == 'product') {
                    $baseEd = $packageInfo['edition'];
                    $baseVersion = $lockedPackage->getVersion();
                    $prettyBaseVersion = $lockedPackage->getPrettyVersion() ?? $baseVersion;

                    // Both editions exist for enterprise, so stop at enterprise to not overwrite with community
                    if ($baseEd == 'enterprise') {
                        break;
                    }
                }
            }
            $baseRoot = null;
            if ($baseEd != null && $baseVersion != null) {
                $baseRoot = $this->fetchRoot(
                    $baseEd,
                    $prettyBaseVersion,
                    $composer,
                    $input
                );
            }

            if ($baseRoot == null || $baseRoot == false) {
                if ($baseEd == null || $baseVersion == null) {
                    $io->writeError(
                        '<warning>No Magento product package was found in the current installation.</warning>'
                    );
                } else {
                    $io->writeError(
                        '<warning>The Magento project package corresponding to the currently installed ' .
                        "\"magento/product-$baseEd-edition: $prettyBaseVersion\" package is unavailable.</warning>"
                    );
                }

                $overrideRoot = $this->override;
                if (!$overrideRoot) {
                    $question = 'Would you like to update the root composer.json file anyway? This will ' .
                        'override changes you may have made to the default installation if the same values ' .
                        "are different in magento/project-$targetEd-edition $prettyTargetVersion";
                    $overrideRoot = $this->getConfirmation($question);
                }
                if ($overrideRoot) {
                    $baseRoot = $installRoot;
                } else {
                    $io->writeError('Skipping Magento composer.json update.');
                    return false;
                }
            } elseif ($baseEd === $targetEd && $baseVersion === $targetVersion) {
                $this->getIO()->writeError(
                    'The Magento product requirement matched the current installation; no root updates are required',
                    true,
                    IOInterface::VERBOSE
                );
                return false;
            }

            $baseEd = static::getMagentoPackageInfo($baseRoot->getName())['edition'];
            $this->targetLabel = 'Magento ' . ucfirst($targetEd) . " Edition $prettyTargetVersion";
            $this->baseLabel = 'Magento ' . ucfirst($baseEd) . " Edition $prettyBaseVersion";

            $io->writeError(
                "Base Magento project package version: magento/project-$baseEd-edition $prettyBaseVersion",
                true,
                IOInterface::DEBUG
            );

            $changedRoot = $composer->getPackage();
            $this->resolveLinkSection(
                'require',
                $baseRoot->getRequires(),
                $targetRoot->getRequires(),
                $installRoot->getRequires(),
                [$changedRoot, 'setRequires']
            );

            if (!$input->getOption('no-dev')) {
                $this->resolveLinkSection(
                    'require-dev',
                    $baseRoot->getDevRequires(),
                    $targetRoot->getDevRequires(),
                    $installRoot->getDevRequires(),
                    [$changedRoot, 'setDevRequires']
                );
            }

            if (!$input->getOption('no-autoloader')) {
                $this->resolveArraySection(
                    'autoload',
                    $baseRoot->getAutoload(),
                    $targetRoot->getAutoload(),
                    $installRoot->getAutoload(),
                    [$changedRoot, 'setAutoload']
                );

                if (!$input->getOption('no-dev')) {
                    $this->resolveArraySection(
                        'autoload-dev',
                        $baseRoot->getDevAutoload(),
                        $targetRoot->getDevAutoload(),
                        $installRoot->getDevAutoload(),
                        [$changedRoot, 'setDevAutoload']
                    );
                }
            }

            $this->resolveLinkSection(
                'conflict',
                $baseRoot->getConflicts(),
                $targetRoot->getConflicts(),
                $installRoot->getConflicts(),
                [$changedRoot, 'setConflicts']
            );

            $this->resolveArraySection(
                'extra',
                $baseRoot->getExtra(),
                $targetRoot->getExtra(),
                $installRoot->getExtra(),
                [$changedRoot, 'setExtra']
            );

            $this->resolveLinkSection(
                'provides',
                $baseRoot->getProvides(),
                $targetRoot->getProvides(),
                $installRoot->getProvides(),
                [$changedRoot, 'setProvides']
            );

            $this->resolveLinkSection(
                'replaces',
                $baseRoot->getReplaces(),
                $targetRoot->getReplaces(),
                $installRoot->getReplaces(),
                [$changedRoot, 'setReplaces']
            );

            $this->resolveArraySection(
                'suggests',
                $baseRoot->getSuggests(),
                $targetRoot->getSuggests(),
                $installRoot->getSuggests(),
                [$changedRoot, 'setSuggests']
            );

            $composer->setPackage($changedRoot);
            $this->setComposer($composer);

            if (!$input->getOption('dry-run')) {
                // Add composer.json write code at the head of the list of post command script hooks so
                // the file is accurate for any other hooks that may exist in the installation that use it
                $eventDispatcher = $composer->getEventDispatcher();
                $eventDispatcher->addListener(
                    ScriptEvents::POST_UPDATE_CMD,
                    [$this, 'writeUpdatedRoot'],
                    PHP_INT_MAX
                );
            }

            if ($this->jsonChanges !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find value deltas from original->target version and resolve any conflicts with overlapping user changes
     *
     * @param string $field
     * @param array|mixed|null $baseVal
     * @param array|mixed|null $targetVal
     * @param array|mixed|null $installVal
     * @param string|null $prettyBase
     * @param string|null $prettyTarget
     * @param string|null $prettyInstall
     * @return string|null ADD_VAL|REMOVE_VAL|CHANGE_VAL to adjust the existing composer.json file, null for no change
     */
    public function findResolution(
        $field,
        $baseVal,
        $targetVal,
        $installVal,
        $prettyBase = null,
        $prettyTarget = null,
        $prettyInstall = null
    ) {
        $io = $this->getIO();
        if ($prettyBase === null) {
            $prettyBase = json_encode($baseVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $prettyBase = trim($prettyBase, "'\"");
        }
        if ($prettyTarget === null) {
            $prettyTarget = json_encode($targetVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $prettyTarget = trim($prettyTarget, "'\"");
        }
        if ($prettyInstall === null) {
            $prettyInstall = json_encode($installVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $prettyInstall = trim($prettyInstall, "'\"");
        }

        $targetLabel = $this->targetLabel;
        $baseLabel = $this->baseLabel;

        $action = null;
        $conflictDesc = null;

        if ($baseVal == $targetVal || $installVal == $targetVal) {
            $action = null;
        } elseif ($baseVal === null) {
            if ($installVal === null) {
                $action = static::ADD_VAL;
            } else {
                $action = static::CHANGE_VAL;
                $conflictDesc = "add $field=$prettyTarget but it is instead $prettyInstall";
            }
        } elseif ($targetVal === null) {
            $action = static::REMOVE_VAL;
            if ($installVal !== $baseVal) {
                $conflictDesc = "remove the $field=$prettyBase entry in $baseLabel but it is instead $prettyInstall";
            }
        } else {
            $action = static::CHANGE_VAL;
            if ($installVal !== $baseVal) {
                $conflictDesc = "update $field to $prettyTarget from $prettyBase in $baseLabel";
                if ($installVal === null) {
                    $action = static::ADD_VAL;
                    $conflictDesc = "$conflictDesc but the field has been removed";
                } else {
                    $conflictDesc = "$conflictDesc but it is instead $prettyInstall";
                }
            }
        }

        if ($conflictDesc !== null) {
            $conflictDesc = "$targetLabel is trying to $conflictDesc in this installation";

            $shouldOverride = $this->override;
            if ($this->override) {
                $overrideMessage = "$conflictDesc.\n  Overriding local changes due to --" . static::OVERRIDE_OPT . '.';
                $io->writeError($overrideMessage);
            } else {
                $shouldOverride = $this->getConfirmation(
                    "$conflictDesc.\nWould you like to override the local changes?"
                );
            }

            if (!$shouldOverride) {
                $io->writeError("<comment>$conflictDesc and will not be changed.  Re-run using " .
                    '--' . static::OVERRIDE_OPT . ' or --' . static::INTERACTIVE_OPT . ' to override with Magento ' .
                    'values.</comment>');
                $action = null;
            }
        }

        return $action;
    }

    /**
     * Process changes to corresponding sets of package version links
     *
     * @param string $section
     * @param Link[] $baseLinks
     * @param Link[] $targetLinks
     * @param Link[] $installLinks
     * @param callable $setterCallback
     * @return void
     */
    public function resolveLinkSection($section, $baseLinks, $targetLinks, $installLinks, $setterCallback)
    {
        /** @var Link[] $baseMap */
        $baseMap = static::linksToMap($baseLinks);

        /** @var Link[] $targetMap */
        $targetMap = static::linksToMap($targetLinks);

        /** @var Link[] $installMap */
        $installMap = static::linksToMap($installLinks);

        $adds = [];
        $removes = [];
        $changes = [];
        $magePackages = array_unique(array_merge(array_keys($baseMap), array_keys($targetMap)));
        foreach ($magePackages as $package) {
            if ($section === 'require' && static::getMagentoPackageInfo($package)) {
                continue;
            }
            $field = "$section:$package";
            $baseConstraint = key_exists($package, $baseMap) ? $baseMap[$package]->getConstraint() : null;
            $baseVal = ($baseConstraint === null) ? null : $baseConstraint->__toString();
            $prettyBaseVal = ($baseConstraint === null) ? null : $baseConstraint->getPrettyString();
            $targetConstraint = key_exists($package, $targetMap) ? $targetMap[$package]->getConstraint() : null;
            $targetVal = ($targetConstraint === null) ? null : $targetConstraint->__toString();
            $prettyTargetVal = ($targetConstraint === null) ? null : $targetConstraint->getPrettyString();
            $installConstraint = key_exists($package, $installMap) ? $installMap[$package]->getConstraint() : null;
            $installVal = ($installConstraint === null) ? null : $installConstraint->__toString();
            $prettyInstallVal = ($installConstraint === null) ? null : $installConstraint->getPrettyString();

            $action = $this->findResolution(
                $field,
                $baseVal,
                $targetVal,
                $installVal,
                $prettyBaseVal,
                $prettyTargetVal,
                $prettyInstallVal
            );
            if ($action == static::ADD_VAL) {
                $adds[$package] = $targetMap[$package];
            } elseif ($action == static::REMOVE_VAL) {
                $removes[] = $package;
            } elseif ($action == static::CHANGE_VAL) {
                $changes[$package] = $targetMap[$package];
            }
        }

        $changed = false;
        if ($adds !== []) {
            $changed = true;
            $prettyAdds = array_map(function ($package) use ($adds) {
                $newVal = $adds[$package]->getConstraint()->getPrettyString();
                return "$package=$newVal";
            }, array_keys($adds));
            $this->verboseLog("Adding $section constraints: " . implode(', ', $prettyAdds));
        }
        if ($removes !== []) {
            $changed = true;
            $this->verboseLog("Removing $section entries: " . implode(', ', $removes));
        }
        if ($changes !== []) {
            $changed = true;
            $prettyChanges = array_map(function ($package) use ($changes) {
                $newVal = $changes[$package]->getConstraint()->getPrettyString();
                return "$package=$newVal";
            }, array_keys($changes));
            $this->verboseLog("Updating $section constraints: " . implode(', ', $prettyChanges));
        }

        if ($changed) {
            $replacements = array_values($adds);

            /** @var Link $installLink */
            foreach ($installMap as $package => $installLink) {
                if (in_array($package, $removes)) {
                    continue;
                } elseif (key_exists($package, $changes)) {
                    $replacements[] = $changes[$package];
                } else {
                    $replacements[] = $installLink;
                }
            }

            $newJson = [];
            /** @var Link $link */
            foreach ($replacements as $link) {
                $newJson[$link->getTarget()] = $link->getConstraint()->getPrettyString();
            }

            call_user_func($setterCallback, $replacements);
            $this->jsonChanges[$section] = $newJson;
        }
    }

    /**
     * Process changes to an array (non-package link) section
     *
     * @param string $section
     * @param array|mixed|null $baseVal
     * @param array|mixed|null $targetVal
     * @param array|mixed|null $installVal
     * @param callable $setterCallback
     * @return void
     */
    public function resolveArraySection($section, $baseVal, $targetVal, $installVal, $setterCallback)
    {
        $resolution = $this->resolveNestedArray($section, $baseVal, $targetVal, $installVal);
        if ($resolution['changed']) {
            call_user_func($setterCallback, $resolution['value']);
            $this->jsonChanges[$section] = $resolution['value'];
        }
    }

    /**
     * Process changes to arrays that could be nested
     *
     * Associative arrays are resolved recursively and non-associative arrays are treated as unordered sets
     *
     * @param string $field
     * @param array|mixed|null $baseVal
     * @param array|mixed|null $targetVal
     * @param array|mixed|null $installVal
     * @return array Two-element array: ['changed' => boolean, 'value' => updated value], null and empty array values
     * indicate the entry should be removed from the parent
     */
    public function resolveNestedArray($field, $baseVal, $targetVal, $installVal)
    {
        $valChanged = false;
        $result = $installVal ?? [];

        if (is_array($baseVal) && is_array($targetVal) && is_array($installVal)) {
            $baseAssociative = [];
            $baseFlat = [];
            foreach ($baseVal as $key => $value) {
                if (is_string($key)) {
                    $baseAssociative[$key] = $value;
                } else {
                    $baseFlat[] = $value;
                }
            }

            $targetAssociative = [];
            $targetFlat = [];
            foreach ($targetVal as $key => $value) {
                if (is_string($key)) {
                    $targetAssociative[$key] = $value;
                } else {
                    $targetFlat[] = $value;
                }
            }

            $installAssociative = [];
            $installFlat = [];
            foreach ($installVal as $key => $value) {
                if (is_string($key)) {
                    $installAssociative[$key] = $value;
                } else {
                    $installFlat[] = $value;
                }
            }

            $associativeResult = array_filter($result, 'is_string', ARRAY_FILTER_USE_KEY);
            $mageKeys = array_unique(array_merge(array_keys($baseAssociative), array_keys($targetAssociative)));
            foreach ($mageKeys as $key) {
                $baseNestedVal = $baseAssociative[$key] ?? [];
                $targetNestedVal = $targetAssociative[$key] ?? [];
                $installNestedVal = $installAssociative[$key] ?? [];

                $resolution = $this->resolveNestedArray(
                    "$field.$key",
                    $baseNestedVal,
                    $targetNestedVal,
                    $installNestedVal
                );
                if ($resolution['value'] === null || $resolution['value'] === []) {
                    if (key_exists($key, $associativeResult)) {
                        $valChanged = true;
                        unset($associativeResult[$key]);
                    }
                } else {
                    $valChanged = $valChanged || $resolution['changed'];
                    $associativeResult[$key] = $resolution['value'];
                }
            }

            $flatResult = array_filter($result, 'is_int', ARRAY_FILTER_USE_KEY);
            $flatAdds = array_diff(array_diff($targetFlat, $baseFlat), $flatResult);
            if ($flatAdds !== []) {
                $valChanged = true;
                $this->verboseLog("Adding $field entries: " . implode(', ', $flatAdds));
                $flatResult = array_unique(array_merge($flatResult, $flatAdds));
            }

            $flatRemoves = array_intersect(array_diff($baseFlat, $targetFlat), $flatResult);
            if ($flatRemoves !== []) {
                $valChanged = true;
                $this->verboseLog("Removing $field entries: " . implode(', ', $flatRemoves));
                $flatResult = array_diff($flatResult, $flatRemoves);
            }

            $result = array_merge($flatResult, $associativeResult);
        } else {
            // Some or all of the values aren't arrays so they should all be compared as non-array values
            $action = $this->findResolution($field, $baseVal, $targetVal, $installVal);
            $prettyTargetVal = json_encode($targetVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($action == static::ADD_VAL) {
                $valChanged = true;
                $this->verboseLog("Adding $field entry: $prettyTargetVal");
                $result = $targetVal;
            } elseif ($action == static::CHANGE_VAL) {
                $valChanged = true;
                $this->verboseLog("Updating $field entry: $prettyTargetVal");
                $result = $targetVal;
            } elseif ($action == static::REMOVE_VAL) {
                $valChanged = true;
                $this->verboseLog("Removing $field entry");
                $result = null;
            }
        }

        return ['changed' => $valChanged, 'value' => $result];
    }

    /**
     * Write the changed composer.json file
     *
     * Called as a script event on non-dry runs after a successful update before other post-update-cmd scripts
     *
     * @return void
     * @throws FilesystemException if the composer.json read or write failed
     */
    public function writeUpdatedRoot()
    {
        if ($this->jsonChanges === []) {
            return;
        }

        $io = $this->getIO();
        $json = json_decode(file_get_contents($this->filePath), true);
        if ($json === null) {
            throw new FilesystemException('Failed to read ' . $this->filePath);
        }

        foreach ($this->jsonChanges as $section => $newContents) {
            if ($newContents === null || $newContents === []) {
                if (key_exists($section, $json)) {
                    unset($json[$section]);
                }
            } else {
                $json[$section] = $newContents;
            }
        }

        $this->verboseLog('Writing changes to the root composer.json...');

        $retVal = file_put_contents(
            $this->filePath,
            json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        if ($retVal === false) {
            throw new FilesystemException('Failed to write updated Magento root values to ' . $this->filePath);
        }
        $io->writeError('<info>' . $this->filePath . ' has been updated</info>');
    }

    /**
     * Label and log the given message if output is set to verbose
     *
     * @param string $message
     * @return void
     */
    private function verboseLog($message)
    {
        $this->getIO()->writeError($this->targetLabel . ": $message", true, IOInterface::VERBOSE);
    }

    /**
     * Helper function to convert a set of links to an associative array with target package names as keys
     *
     * @param Link[] $links
     * @return array
     */
    private function linksToMap($links)
    {
        $targets = array_map(function ($link) {
            /** @var Link $link */
            return $link->getTarget();
        }, $links);
        return array_combine($targets, $links);
    }

    /**
     * Helper function to extract the edition and package type if it is a Magento package name
     *
     * @param string $packageName
     * @return array|null
     */
    private static function getMagentoPackageInfo($packageName)
    {
        $regex = '/^magento\/(?<type>product|project)-(?<edition>community|enterprise)-edition$/';
        if (preg_match($regex, $packageName, $matches)) {
            return $matches;
        } else {
            return null;
        }
    }

    /**
     * Retrieve the Magento root package for an edition and version constraint from the composer file's repositories
     *
     * @param string $edition
     * @param string $constraint
     * @param Composer $composer
     * @param InputInterface $input
     * @param boolean $isTarget
     * @return \Composer\Package\PackageInterface|bool Best root package candidate or false if no valid packages found
     */
    private function fetchRoot($edition, $constraint, $composer, $input, $isTarget = false)
    {
        $rootName = strtolower("magento/project-$edition-edition");
        $phpVersion = null;
        $prettyPhpVersion = null;
        $versionParser = new VersionParser();
        $parsedConstraint = $versionParser->parseConstraints($constraint);

        $minimumStability = $composer->getPackage()->getMinimumStability() ?? 'stable';
        $stabilityFlags = $this->extractStabilityFlags($rootName, $constraint, $minimumStability);
        $stability = key_exists($rootName, $stabilityFlags)
            ? array_search($stabilityFlags[$rootName], BasePackage::$stabilities)
            : $minimumStability;
        $this->getIO()->writeError(
            "<comment>Minimum stability for \"$rootName: $constraint\": $stability</comment>",
            true,
            IOInterface::DEBUG
        );
        $pool = new Pool(
            $stability,
            $stabilityFlags,
            [$rootName => $parsedConstraint]
        );
        $repos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
        $pool->addRepository($repos);

        if ($isTarget) {
            if (strpbrk($parsedConstraint->__toString(), '[]|<>!') !== false) {
                $this->fuzzyConstraint = true;
                $this->getIO()->writeError(
                    "<warning>The version constraint \"magento/product-$edition-edition: $constraint\" is not exact; " .
                    'the Magento root updater might not accurately determine the version to use according to other ' .
                    'requirements in this installation. It is recommended to use an exact version number.</warning>'
                );
            }
            if (!$input->getOption('ignore-platform-reqs')) {
                $platformOverrides = $composer->getConfig()->get('platform') ?: [];
                $platform = new PlatformRepository([], $platformOverrides);
                $phpPackage = $platform->findPackage('php', '*');
                if ($phpPackage != null) {
                    $phpVersion = $phpPackage->getVersion();
                    $prettyPhpVersion = $phpPackage->getPrettyVersion();
                }
            }
        }

        $versionSelector = new VersionSelector($pool);
        $result = $versionSelector->findBestCandidate($rootName, $constraint, $phpVersion);

        if ($result == false) {
            $err = "Could not find a Magento project package matching \"magento/product-$edition-edition $constraint\"";
            if ($phpVersion) {
                $err = "$err for PHP version $prettyPhpVersion";
            }
            $this->getIO()->writeError("<error>$err</error>", true, IOInterface::QUIET);
        }

        return $result;
    }

    /**
     * Helper method to construct stability flags needed to fetch new root packages
     *
     * @see RootPackageLoader::extractStabilityFlags()
     *
     * @param string $reqName
     * @param string $reqVersion
     * @param string $minimumStability
     * @return array
     */
    private function extractStabilityFlags($reqName, $reqVersion, $minimumStability)
    {
        $stabilityFlags = [];
        $stabilityMap = BasePackage::$stabilities;
        $minimumStability = $stabilityMap[$minimumStability];
        $constraints = [];

        // extract all sub-constraints in case it is an OR/AND multi-constraint
        $orSplit = preg_split('{\s*\|\|?\s*}', trim($reqVersion));
        foreach ($orSplit as $orConstraint) {
            $andSplit = preg_split('{(?<!^|as|[=>< ,]) *(?<!-)[, ](?!-) *(?!,|as|$)}', $orConstraint);
            foreach ($andSplit as $andConstraint) {
                $constraints[] = $andConstraint;
            }
        }

        // parse explicit stability flags to the most unstable
        $match = false;
        foreach ($constraints as $constraint) {
            if (preg_match('{^[^@]*?@('.implode('|', array_keys($stabilityMap)).')$}i', $constraint, $match)) {
                $stability = $stabilityMap[VersionParser::normalizeStability($match[1])];

                if (isset($stabilityFlags[$reqName]) && $stabilityFlags[$reqName] > $stability) {
                    continue;
                }
                $stabilityFlags[$reqName] = $stability;
                $match = true;
            }
        }

        if (!$match) {
            foreach ($constraints as $constraint) {
                // infer flags for requirements that have an explicit -dev or -beta version specified but only
                // for those that are more unstable than the minimumStability or existing flags
                $reqVersion = preg_replace('{^([^,\s@]+) as .+$}', '$1', $constraint);
                if (preg_match('{^[^,\s@]+$}', $reqVersion)
                    && 'stable' !== ($stabilityName = VersionParser::parseStability($reqVersion))) {
                    $stability = $stabilityMap[$stabilityName];
                    if ((isset($stabilityFlags[$reqName]) && $stabilityFlags[$reqName] > $stability)
                        || ($minimumStability > $stability)) {
                        continue;
                    }
                    $stabilityFlags[$reqName] = $stability;
                }
            }
        }

        return $stabilityFlags;
    }

    /**
     * If interactive, ask the given question and return the result, otherwise return the default
     *
     * @param string $question
     * @param bool $default
     * @return bool
     */
    private function getConfirmation($question, $default = false)
    {
        $result = $default;
        if ($this->interactive) {
            if (!$this->interactiveInput) {
                throw new \InvalidArgumentException(
                    '--' . static::INTERACTIVE_OPT . ' cannot be used in non-interactive terminals.'
                );
            }
            $opts = $default ? 'Y,n' : 'y,N';
            $result = $this->getIO()->askConfirmation("<info>$question</info> [<comment>$opts</comment>]? ", $default);
        }
        return $result;
    }

    /**
     * Set the flag for the interactivity of the current environment (used for testing)
     *
     * @param bool $interactiveInput
     * @return void
     */
    public function setInteractiveInput($interactiveInput)
    {
        $this->interactiveInput = $interactiveInput;
    }

    /**
     * Set the flag for whether or not the plugin should override user changes with Magento values
     *
     * @param bool $override
     * @return void
     */
    public function setOverride($override)
    {
        $this->override = $override;
    }

    /**
     * Set the flag to interactively prompt for conflict resolution between Magento deltas and installed values
     *
     * @param bool $interactive
     * @return void
     */
    public function setInteractive($interactive)
    {
        $this->interactive = $interactive;
    }

    /**
     * Get the map of section name -> new contents to use to update the composer.json file after running the update
     *
     * @return array
     */
    public function getJsonChanges()
    {
        return $this->jsonChanges;
    }
}
