<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Plugin\Commands;

use Composer\Composer;
use Exception;
use Magento\ComposerRootUpdatePlugin\ComposerReimplementation\ExtendableRequireCommand;
use Magento\ComposerRootUpdatePlugin\Utils\PackageUtils;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\ComposerRootUpdatePlugin\Plugin\PluginDefinition;
use Magento\ComposerRootUpdatePlugin\Updater\RootProjectUpdater;
use Magento\ComposerRootUpdatePlugin\Updater\RootPackageRetriever;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Extend composer's native `require` command and attach plugin functionality to the original process
 *
 * Deprecated because Composer 2.1.6+ closed the loophole used to replace the native `require` in the command registry
 *
 * @deprecated 2.0.0
 */
class OverrideRequireCommand extends ExtendableRequireCommand
{
    /**
     * CLI Options
     */
    public const SKIP_OPT = 'skip-magento-root-plugin';
    public const OVERRIDE_OPT = 'use-default-magento-values';
    public const INTERACTIVE_OPT = 'interactive-magento-conflicts';
    public const BASE_EDITION_OPT = 'base-magento-edition';
    public const BASE_VERSION_OPT = 'base-magento-version';

    /**
     * @var string $commandName
     */
    private $commandName;

    /**
     * @var Console $console
     */
    protected $console;

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
     * Call the parent setApplication method but also change the command's name to require
     *
     * @param Application|null $application
     * @return void
     */
    public function setApplication(Application $application = null): void
    {
        // For Composer versions below 2.1.6:
        // In order to trick Composer into overriding its native RequireCommand with this class, the name needs to be
        // different before Application->add() is called to pass the verification check (accomplished in configure())
        // but changed back before being the command is added to the registry
        // For 2.1.6+, this doesn't work for the actual command execution, but the 'composer list' command will still
        // pick it up, so this remains to get the deprecation message in the require command's description
        $this->setName($this->commandName);
        parent::setApplication($application);
    }

    /**
     * Use the native RequireCommand config with options/doc additions for the project root composer.json update
     *
     * @return void
     */
    protected function configure(): void
    {
        parent::configure();

        $origName = $this->getName();
        $this->commandName = $origName;
        $metaLabels = "magento/product-community-edition, magento/product-enterprise-edition, or " .
            "magento/magento-cloud-metapackage";
        $this->setName('require-magento-root')
            ->addOption(
                self::SKIP_OPT,
                null,
                null,
                'DEPRECATED: Skip the root composer.json update.'
            )
            ->addOption(
                self::OVERRIDE_OPT,
                null,
                null,
                'DEPRECATED: Override conflicting root composer.json customizations with expected project values.'
            )
            ->addOption(
                self::INTERACTIVE_OPT,
                null,
                null,
                'DEPRECATED: Interactive interface to resolve conflicts during the root composer.json update.'
            )
            ->addOption(
                self::BASE_EDITION_OPT,
                null,
                InputOption::VALUE_REQUIRED,
                'DEPRECATED: Edition of the initially-installed metapackage to use as the base for composer.json ' .
                'updates. Not valid for Adobe Commerce Cloud upgrades. Valid values: \'Open Source\', \'Commerce\''
            )
            ->addOption(
                self::BASE_VERSION_OPT,
                null,
                InputOption::VALUE_REQUIRED,
                "DEPRECATED: Version of the initially-installed $metaLabels package to use as the base for " .
                "composer.json updates."
            );

        $mageHelp = "

<comment>Magento Open Source and Adobe Commerce Root Updates:</comment>
  <warning>DEPRECATED:</warning> As of Composer version 2.1.6, this functionality no longer works on the native
  <info>$origName</info> command.  Use <info>composer " . RequireCommerceCommand::COMMAND_NAME . '</info> instead.

  With <info>' . PluginDefinition::PACKAGE_NAME . "</info> installed, <info>$origName</info> will also check for and
  execute any changes to the root composer.json file that exist between the Magento
  Open Source or Adobe Commerce project package corresponding to the 
  currently-installed version and the project for the target metapackage version if
  the package requirement has changed.
  
  By default, any changes that would affect values that have been customized in the
  existing installation will not be applied. Using <info>--" . self::OVERRIDE_OPT . '</info> will instead
  apply all deltas found between the expected base project and the new version,
  overriding any custom values. Use <info>--' . self::INTERACTIVE_OPT . '</info> to interactively
  resolve deltas that conflict with the existing installation.
  
  To skip the project root composer.json update, use <info>--' . self::SKIP_OPT . '</info>.
';
        $this->setHelp($this->getHelp() . $mageHelp);

        $newCommandName = RequireCommerceCommand::COMMAND_NAME;
        if (version_compare(Composer::VERSION, '2.1.6', '>=')) {
            // Composer 2.1.6+ still displays this description under require but won't replace the actual command
            $extendedDesc = " <comment>For $metaLabels updates, use $newCommandName.</comment>";
        } else {
            $extendedDesc = " <comment>Deprecated for $metaLabels updates; use $newCommandName instead.</comment>";
        }

        $this->setDescription($this->getDescription() . $extendedDesc);
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->console = new Console($this->getIO(), $input->getOption(self::INTERACTIVE_OPT));
        $this->pkgUtils = new PackageUtils($this->console);
        $fileParsed = $this->parseComposerJsonFile($input);
        if ($fileParsed !== 0) {
            return $fileParsed;
        }

        $didUpdate = $this->runUpdate($input);

        // Run the native command functionality
        $errorCode = 0;
        $exception = null;
        try {
            $errorCode = parent::execute($input, $output);
        } catch (Exception $e) {
            $exception = $e;
        }

        if ($didUpdate && $errorCode !== 0) {
            // If the native execute() didn't succeed, revert the plugin's changes to the composer.json file
            $this->revertRootComposerFile('The native \'composer ' . $this->commandName . '\' command failed');
            if ($this->constraint && !$this->pkgUtils->isConstraintStrict($this->constraint)) {
                $constraintLabel = $this->package . ': ' . $this->constraint;
                $this->console->comment(
                    "Recommended: Use a specific metapackage version constraint instead of \"$constraintLabel\""
                );
            }
        }

        if ($exception) {
            throw $exception;
        }

        return $errorCode;
    }

    /**
     * Checks the package arguments for a magento/product-community-edition, magento/product-enterprise-edition, or
     * magento/magento-cloud-metapackage metapackage and run the update if one is found
     *
     * Returns true if an update was attempted successfully
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
            $edition = $this->pkgUtils->getMetapackageEdition($this->package);
            $overrideEdition = $input->getOption(self::BASE_EDITION_OPT);
            $overrideVersion = $input->getOption(self::BASE_VERSION_OPT);
            if ($overrideEdition) {
                $overrideEdition = $this->convertBaseEditionOption($edition, $overrideEdition);
            }

            $updater = new RootProjectUpdater($this->console, $this->getComposer());
            $retriever = new RootPackageRetriever(
                $this->console,
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
                    true
                );
            } catch (Exception $e) {
                $label = $retriever->getTargetLabel();
                $this->revertRootComposerFile("Update of composer.json with $label changes failed");
                $this->console->log($e->getMessage());
                $didUpdate = false;
            }

            if ($didUpdate) {
                $label = $retriever->getTargetLabel();
                try {
                    $this->console->info("Updating composer.json for $label ...");
                    $updater->writeUpdatedComposerJson();
                } catch (Exception $e) {
                    $this->revertRootComposerFile("Update of composer.json with $label changes failed");
                    $this->console->log($e->getMessage());
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
            throw new InvalidOptionException("'$opt' cannot be used when upgrading Adobe Commerce Cloud");
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
            !$input->getOption('no-plugins') &&
            !$input->getOption(self::SKIP_OPT)) {
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
