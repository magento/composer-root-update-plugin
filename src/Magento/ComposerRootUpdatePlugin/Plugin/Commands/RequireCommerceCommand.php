<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Plugin\Commands;

use Exception;
use Magento\ComposerRootUpdatePlugin\ComposerReimplementation\ExtendableRequireCommand;
use Magento\ComposerRootUpdatePlugin\Utils\PackageUtils;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\ComposerRootUpdatePlugin\Updater\RootProjectUpdater;
use Magento\ComposerRootUpdatePlugin\Updater\RootPackageRetriever;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Extend composer's native `require` command and attach plugin functionality to the original process
 */
class RequireCommerceCommand extends ExtendableRequireCommand
{
    /**
     * CLI Options
     */
    const OVERRIDE_OPT = 'force-root-updates';
    const INTERACTIVE_OPT = 'interactive-root-conflicts';
    const BASE_EDITION_OPT = 'base-project-edition';
    const BASE_VERSION_OPT = 'base-project-version';
    const COMMAND_NAME = 'require-commerce';

    /**
     * @var PackageUtils $pkgUtils
     */
    protected $pkgUtils;

    /**
     * @var string $package
     */
    protected $package;

    /**
     * @var string $constraint
     */
    protected $constraint;

    /**
     * @var Console $console
     */
    protected $console;

    /**
     * Use the native RequireCommand config with options/doc additions for the root project composer.json update
     *
     * @return void
     */
    protected function configure()
    {
        parent::configure();

        $metaLabels = 'magento/product-community-edition, magento/product-enterprise-edition, or ' .
            'magento/magento-cloud-metapackage';
        $this->setName(self::COMMAND_NAME)
            ->addOption(
                self::OVERRIDE_OPT,
                null,
                null,
                'Override conflicting root composer.json customizations with expected magento/project values.'
            )
            ->addOption(
                self::INTERACTIVE_OPT,
                null,
                null,
                'Interactive interface to resolve conflicts during the magento/project root composer.json update.'
            )
            ->addOption(
                self::BASE_EDITION_OPT,
                null,
                InputOption::VALUE_REQUIRED,
                "Edition of the initially-installed $metaLabels package to use as the base for composer.json updates." .
                "Not valid for magento/magento-cloud-metapackage upgrades. Valid values: \'Open Source\', \'Commerce\'"
            )
            ->addOption(
                self::BASE_VERSION_OPT,
                null,
                InputOption::VALUE_REQUIRED,
                "Version of the initially-installed $metaLabels package to use as the base for composer.json updates."
            );


        $extendedDesc = " If a $metaLabels change is required, also makes any associated composer.json changes.";
        $this->setDescription($this->getDescription() . $extendedDesc);

        $this->setHelp($this->getFormattedHelp());
    }

    /**
     * Combine the native require help message with the plugin-specific text and format it to fit the terminal width
     *
     * @return string
     */
    protected function getFormattedHelp(): string
    {
        $console = new Console();
        $commandName = $console->formatString(self::COMMAND_NAME, Console::FORMAT_INFO);
        $noUpdateOpt = $console->formatString('--no-update', Console::FORMAT_INFO);
        $overrideOpt = $console->formatString('--' . self::OVERRIDE_OPT, Console::FORMAT_INFO);
        $interactiveOpt = $console->formatString('--' . self::INTERACTIVE_OPT, Console::FORMAT_INFO);
        $pluginHeader = $console->formatString(
            "Magento Open Source and Adobe Commerce Root Updates:", Console::FORMAT_COMMENT
        );

        return
"The $commandName command adds required packages to your composer.json and installs them.

If you do not specify a package, composer will prompt you to search for a package, and given
results, provide a list of matches to require.

If you do not specify a version constraint, composer will choose a suitable one based on the
available package versions.

If you do not want to install the new dependencies immediately you can call it with $noUpdateOpt.

Read more at https://getcomposer.org/doc/03-cli.md#require

$pluginHeader
  $commandName will also check for and execute any changes to the root composer.json file
  that exist between the Magento Open Source or Adobe Commerce project package corresponding
  to the currently-installed version and the project for the target
  magento/product-community-edition, magento/product-enterprise-edition, or 
  magento/magento-cloud-metapackage version.
  
  By default, any changes that would affect values that have been customized in the existing
  installation will not be applied. Using $overrideOpt will instead apply
  all deltas found between the expected base project and the new version, overriding any
  custom values. Use $interactiveOpt to interactively resolve deltas that
  conflict with the existing installation.
";
    }

    /**
     * Look ahead at the target project version for root composer.json changes before running composer's native require
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null null or 0 if everything went fine, or an error code
     *
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->console = new Console($this->getIO(), $input->getOption(self::INTERACTIVE_OPT));
        $console = $this->console;
        $this->pkgUtils = new PackageUtils($console);
        $fileParsed = $this->parseComposerJsonFile($input);
        if ($fileParsed !== 0) {
            return $fileParsed;
        }

        $didUpdate = $this->runUpdate($input);

        // Run the native command functionality
        $errorCode = 0;
        $exception = null;
        try {
            $console->info("Running native execute...");
            $errorCode = parent::execute($input, $output);
        } catch (Exception $e) {
            $exception = $e;
        }

        if ($didUpdate && $errorCode !== 0) {
            // If the RequireCommand::execute() didn't succeed, revert the plugin's changes to the composer.json file
            $this->revertRootComposerFile('The native \'composer require\' command failed');
            if ($this->constraint && !$this->pkgUtils->isConstraintStrict($this->constraint)) {
                $constraintLabel = $this->package . ': ' . $this->constraint;
                $console->comment(
                    "Recommended: Use a specific version constraint instead of \"$constraintLabel\""
                );
            }
        }

        if ($exception) {
            throw $exception;
        }

        return $errorCode;
    }

    /**
     * Checks the package arguments for a matching metapackage and run the update if one is found
     *
     * Returns true if an update was attempted and completed successfully
     *
     * @param InputInterface $input
     * @return bool
     * @throws Exception
     */
    protected function runUpdate(InputInterface $input): bool
    {
        $didUpdate = false;
        $this->parseMetapackageRequirement($input);
        if ($this->package) {
            $console = $this->console;
            $edition = $this->pkgUtils->getMetapackageEdition($this->package);
            $overrideEdition = $input->getOption(self::BASE_EDITION_OPT);
            $overrideVersion = $input->getOption(self::BASE_VERSION_OPT);
            if ($overrideEdition) {
                $overrideEdition = $this->convertBaseEditionOption($edition, $overrideEdition);
            }

            $updater = new RootProjectUpdater($console, $this->getComposer());
            $retriever = new RootPackageRetriever(
                $console,
                $this->getComposer(),
                $edition,
                $this->constraint,
                $overrideEdition,
                $overrideVersion
            );

            try {
                $didUpdate = $updater->runUpdate(
                    $retriever,
                    $input->getOption(self::OVERRIDE_OPT),
                    $input->getOption('ignore-platform-reqs'),
                    $this->phpVersion,
                    $this->preferredStability,
                    false
                );
            } catch (Exception $e) {
                $label = $retriever->getTargetLabel();
                $this->revertRootComposerFile("Update of composer.json with $label changes failed");
                $console->log($e->getMessage());
                $didUpdate = false;
            }

            if ($didUpdate) {
                $label = $retriever->getTargetLabel();
                try {
                    $console->info("Updating composer.json for $label ...");
                    $updater->writeUpdatedComposerJson();
                } catch (Exception $e) {
                    $this->revertRootComposerFile("Update of composer.json with $label changes failed");
                    $console->log($e->getMessage());
                    $didUpdate = false;
                }
            }
        }

        return $didUpdate;
    }

    /**
     * Helper function to validate the BASE_EDITION_OPT option value and convert it to the internal edition
     *
     * 'open source' -> community, 'commerce' -> enterprise
     *
     * @param string $currentEdition
     * @param string $overrideEdition
     * @return string
     */
    protected function convertBaseEditionOption(string $currentEdition, string $overrideEdition): string
    {
        if ($currentEdition == PackageUtils::CLOUD_PKG_EDITION) {
            $opt = '--' . self::BASE_EDITION_OPT;
            throw new InvalidOptionException("'$opt' cannot be used when upgrading magento/magento-cloud-metapackage");
        }
        $overrideEdition = strtolower($overrideEdition);
        if ($overrideEdition !== 'open source' && $overrideEdition !== 'commerce') {
            $opt = '--' . self::BASE_EDITION_OPT;
            throw new InvalidOptionException("'$opt' accepts only 'Open Source' or 'Commerce'");
        }
        return $overrideEdition == 'open source' ?
            PackageUtils::OPEN_SOURCE_PKG_EDITION : PackageUtils::COMMERCE_PKG_EDITION;
    }

    /**
     * Check if the plugin should run and parses the package arguments for a valid metapackage requirement if so
     *
     * @param InputInterface $input
     * @return void
     * @throws Exception
     */
    protected function parseMetapackageRequirement(InputInterface $input)
    {
        if (!$this->pluginNewlyCreated &&
            !$input->getOption('dev') &&
            !$input->getOption('no-plugins')) {
            $requires = $input->getArgument('packages');
            if (!$requires) {
                $requires = $this->getRequirementsInteractive();
                $input->setArgument('packages', $requires);
            }

            $requires = $this->normalizeRequirements($requires);
            foreach ($requires as $requirement) {
                $edition = $this->pkgUtils->getMetapackageEdition($requirement['name']);
                if ($edition) {
                    $this->package = $requirement['name'];
                    $this->constraint = $requirement['version'] ?? '*';
                    break;
                }
            }
        }
    }
}
