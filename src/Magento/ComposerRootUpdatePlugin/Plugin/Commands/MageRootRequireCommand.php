<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Plugin\Commands;

use Magento\ComposerRootUpdatePlugin\ComposerReimplementation\ExtendableRequireCommand;
use Magento\ComposerRootUpdatePlugin\Utils\PackageUtils;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\ComposerRootUpdatePlugin\Plugin\PluginDefinition;
use Magento\ComposerRootUpdatePlugin\Updater\MagentoRootUpdater;
use Magento\ComposerRootUpdatePlugin\Updater\RootPackageRetriever;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Extend composer's native `require` command and attach plugin functionality to the original process
 */
class MageRootRequireCommand extends ExtendableRequireCommand
{
    /**
     * CLI Options
     */
    const SKIP_OPT = 'skip-magento-root-plugin';
    const OVERRIDE_OPT = 'use-default-magento-values';
    const INTERACTIVE_OPT = 'interactive-magento-conflicts';
    const BASE_EDITION_OPT = 'base-magento-edition';
    const BASE_VERSION_OPT = 'base-magento-version';

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
     * Call the parent setApplication method but also change the command's name to update
     *
     * @param Application|null $application
     * @return void
     */
    public function setApplication(Application $application = null)
    {
        // In order to trick Composer into overriding its native RequireCommand with this object, the name needs to be
        // different before Application->add() is called to pass the verification check but changed back before being
        // added to the command registry
        $this->setName($this->commandName);
        parent::setApplication($application);
    }

    /**
     * Use the native UpdateCommand config with options/doc additions for the Magento root composer.json update
     *
     * @return void
     */
    protected function configure()
    {
        parent::configure();

        $origName = $this->getName();
        $this->commandName = $origName;
        $this->setName('require-magento-root')
            ->addOption(
                static::SKIP_OPT,
                null,
                null,
                'Skip the Magento root composer.json update.'
            )
            ->addOption(
                static::OVERRIDE_OPT,
                null,
                null,
                'Override conflicting root composer.json customizations with expected Magento project values.'
            )
            ->addOption(
                static::INTERACTIVE_OPT,
                null,
                null,
                'Interactive interface to resolve conflicts during the Magento root composer.json update.'
            )
            ->addOption(
                static::BASE_EDITION_OPT,
                null,
                InputOption::VALUE_REQUIRED,
                'Edition of the initially-installed Magento product to use as the base for composer.json updates. ' .
                'Valid values: \'Open Source\', \'Commerce\''
            )
            ->addOption(
                static::BASE_VERSION_OPT,
                null,
                InputOption::VALUE_REQUIRED,
                'Version of the initially-installed Magento product to use as the base for composer.json updates.'
            );

        $mageHelp = '

<comment>Magento Root Updates:</comment>
  With <info>' . PluginDefinition::PACKAGE_NAME . "</info> installed, <info>$origName</info> will also check for and
  execute any changes to the root composer.json file that exist between the Magento
  project package corresponding to the currently-installed version and the project
  for the target Magento product version if the package requirement has changed.
  
  By default, any changes that would affect values that have been customized in the
  existing installation will not be applied. Using <info>--" . static::OVERRIDE_OPT . '</info> will instead
  apply all deltas found between the expected base project and the new version,
  overriding any custom values. Use <info>--' . static::INTERACTIVE_OPT . '</info> to interactively
  resolve deltas that conflict with the existing installation.
  
  To skip the Magento root composer.json update, use <info>--' . static::SKIP_OPT . '</info>.
';
        $this->setHelp($this->getHelp() . $mageHelp);

        $mageDesc = ' If a Magento metapackage change is required, also make any associated composer.json changes.';
        $this->setDescription($this->getDescription() . $mageDesc);
    }

    /**
     * Look ahead at the target Magento version for root composer.json changes before running composer's native require
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null null or 0 if everything went fine, or an error code
     *
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->console = new Console($this->getIO(), $input->getOption(static::INTERACTIVE_OPT));
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
        } catch (\Exception $e) {
            $exception = $e;
        }

        if ($didUpdate && $errorCode !== 0) {
            // If the native execute() didn't succeed, revert the Magento changes to the composer.json file
            $this->revertMageComposerFile('The native \'composer ' . $this->commandName . '\' command failed');
            if ($this->constraint && !$this->pkgUtils->isConstraintStrict($this->constraint)) {
                $constraintLabel = $this->package . ': ' . $this->constraint;
                $this->console->comment(
                    "Recommended: Use a specific Magento version constraint instead of \"$constraintLabel\""
                );
            }
        }

        if ($exception) {
            throw $exception;
        }

        return $errorCode;
    }

    /**
     * Checks the package arguments for a Magento product package and run the update if one is found
     *
     * Returns true if an update was attempted successfully
     *
     * @param InputInterface $input
     * @return bool
     */
    protected function runUpdate($input)
    {
        $didUpdate = false;
        $this->parseMageRequirement($input);
        if ($this->package) {
            $edition = $this->pkgUtils->getMagentoProductEdition($this->package);
            $overrideEdition = $input->getOption(static::BASE_EDITION_OPT);
            $overrideVersion = $input->getOption(static::BASE_VERSION_OPT);
            if ($overrideEdition) {
                $overrideEdition = strtolower($overrideEdition);
                if ($overrideEdition !== 'open source' && $overrideEdition !== 'commerce') {
                    $opt = '--' . static::BASE_EDITION_OPT;
                    throw new InvalidOptionException("'$opt' accepts only 'Open Source' or 'Commerce'");
                }
                $overrideEdition = $overrideEdition == 'open source' ?
                    PackageUtils::OPEN_SOURCE_PKG_EDITION : PackageUtils::COMMERCE_PKG_EDITION;
            }

            $updater = new MagentoRootUpdater($this->console, $this->getComposer());
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
                    $input->getOption(static::OVERRIDE_OPT),
                    $input->getOption('ignore-platform-reqs'),
                    $this->phpVersion,
                    $this->preferredStability
                );
            } catch (\Exception $e) {
                $label = $retriever->getTargetLabel();
                $this->revertMageComposerFile("Update of composer.json with $label changes failed");
                $this->console->log($e->getMessage());
                $didUpdate = false;
            }

            if ($didUpdate) {
                $label = $retriever->getTargetLabel();
                try {
                    $this->console->info("Updating composer.json for $label ...");
                    $updater->writeUpdatedComposerJson();
                } catch (\Exception $e) {
                    $this->revertMageComposerFile("Update of composer.json with $label changes failed");
                    $this->console->log($e->getMessage());
                    $didUpdate = false;
                }
            }
        }

        return $didUpdate;
    }

    /**
     * Check if the plugin should run and parses the package arguments for a magento/product requirement if so
     *
     * @param InputInterface $input
     * @return void
     */
    protected function parseMageRequirement(&$input)
    {
        $edition = null;
        if (!$this->mageNewlyCreated &&
            !$input->getOption('dev') &&
            !$input->getOption('no-plugins') &&
            !$input->getOption(static::SKIP_OPT)) {
            $requires = $input->getArgument('packages');
            if (!$requires) {
                $requires = $this->getRequirementsInteractive();
                $input->setArgument('packages', $requires);
            }

            $requires = $this->normalizeRequirements($requires);
            foreach ($requires as $requirement) {
                $edition = $this->pkgUtils->getMagentoProductEdition($requirement['name']);
                if ($edition) {
                    $this->package = "magento/product-$edition-edition";
                    $this->constraint = isset($requirement['version']) ? $requirement['version'] : '*';
                    break;
                }
            }
        }
    }
}
