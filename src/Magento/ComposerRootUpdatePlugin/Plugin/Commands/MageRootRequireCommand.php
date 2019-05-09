<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Plugin\Commands;

use Composer\Package\Version\VersionParser;
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

class MageRootRequireCommand extends ExtendableRequireCommand
{
    /**
     * CLI Options
     */
    const SKIP_OPT = 'skip-magento-root';
    const OVERRIDE_OPT = 'use-magento-values';
    const INTERACTIVE_OPT = 'interactive-magento-conflicts';
    const PREVIOUS_PACKAGE_OPT = 'previous-magento-package';
    
    const PREV_OPT_HINT = 'magento/product-<enterprise|community>-edition=<version>';

    /**
     * @var string $commandName
     */
    private $commandName;

    /**
     * @var RootPackageRetriever $retriever
     */
    protected $retriever;

    /**
     * @var Console $console
     */
    protected $console;

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
    public function configure()
    {
        parent::configure();

        $origName = $this->getName();
        $this->commandName = $origName;
        $this->retriever = null;
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
                static::PREVIOUS_PACKAGE_OPT,
                null,
                InputOption::VALUE_REQUIRED,
                'Use a previously-installed Magento product version as the base for composer.json updates',
                static::PREV_OPT_HINT
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
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $updater = null;
        $this->console = new Console($this->getIO(), $input->getOption(static::INTERACTIVE_OPT));
        $fileParsed = $this->parseComposerJsonFile($input);
        if ($fileParsed !== 0) {
            return $fileParsed;
        }
        $didUpdate = false;

        $package = null;
        $constraint = null;
        $requires = $input->getArgument('packages');
        if (!$this->mageNewlyCreated &&
            !$input->getOption('no-plugins') &&
            !$input->getOption('dev') &&
            !$input->getOption(static::SKIP_OPT)
        ) {
            if (!$requires) {
                $requires = $this->getRequirementsInteractive();
                $input->setArgument('packages', $requires);
            }

            $requires = $this->normalizeRequirements($requires);
            foreach ($requires as $requirement) {
                $pkgEdition = PackageUtils::getMagentoProductEdition($requirement['name']);
                if ($pkgEdition) {
                    $edition = $pkgEdition;
                    $package = "magento/product-$edition-edition";
                    $constraint = isset($requirement['version']) ? $requirement['version'] : '*';

                    // Found a Magento product in the command arguments; try to run the updater
                    try {
                        $updater = new MagentoRootUpdater($this->console, $this->getComposer());
                        $didUpdate = $this->runUpdate($updater, $input, $edition, $constraint);
                    } catch (\Exception $e) {
                        $label = 'Magento ' . ucfirst($edition) . " Edition $constraint";
                        $this->revertMageComposerFile("Update of composer.json with $label changes failed");
                        $this->console->log($e->getMessage());
                        $didUpdate = false;
                    }

                    break;
                }
            }

            if ($didUpdate) {
                // Update composer.json before the native execute(), as it reads the file instead of an in-memory object
                $label = $this->retriever->getTargetLabel();
                $this->console->info("Updating composer.json for $label ...");
                try {
                    $updater->writeUpdatedComposerJson();
                } catch (\Exception $e) {
                    $this->revertMageComposerFile("Update of composer.json with $label changes failed");
                    $this->console->log($e->getMessage());
                    $didUpdate = false;
                }
            }
        }

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
            if ($constraint && !PackageUtils::isConstraintStrict($constraint)) {
                $this->console->comment(
                    "Recommended: Use a specific Magento version constraint instead of \"$package: $constraint\""
                );
            }
        }

        if ($exception) {
            throw $exception;
        }

        return $errorCode;
    }

    /**
     * Call MagentoRootUpdater::runUpdate() according to CLI options
     *
     * @see MagentoRootUpdater::runUpdate()
     *
     * @param MagentoRootUpdater $updater
     * @param InputInterface $input
     * @param string $targetEdition
     * @param string $targetConstraint
     * @return bool Returns true if updates were necessary and prepared successfully
     */
    protected function runUpdate($updater, $input, $targetEdition, $targetConstraint)
    {
        $overrideOriginal = $input->getOption(static::PREVIOUS_PACKAGE_OPT);
        $overrideOriginalEdition = null;
        $overrideOriginalVersion = null;
        if ($overrideOriginal && $overrideOriginal != static::PREV_OPT_HINT) {
            $parser = new VersionParser();
            $requirement = $parser->parseNameVersionPairs([$overrideOriginal]);
            $opt = '--' . static::PREVIOUS_PACKAGE_OPT;
            if (count($requirement) !== 1) {
                throw new InvalidOptionException("'$opt' accepts exactly one package requirement");
            } elseif (count($requirement[0]) !== 2) {
                throw new InvalidOptionException("'$opt' requires both a package and version");
            }
            $requirement = $requirement[0];
            $name = $requirement['name'];
            $overrideOriginalEdition = PackageUtils::getMagentoProductEdition($name);
            if (!$overrideOriginalEdition) {
                throw new InvalidOptionException("'$opt' accepts only Magento product packages; \"$name\" given");
            }
            $overrideOriginalVersion = $requirement['version'];
            if (!PackageUtils::isConstraintStrict($overrideOriginalVersion)) {
                throw new InvalidOptionException("'$opt' does not accept non-strict version constraints");
            }
        }

        $this->retriever = new RootPackageRetriever(
            $this->console,
            $this->getComposer(),
            $targetEdition,
            $targetConstraint,
            $overrideOriginalEdition,
            $overrideOriginalVersion
        );
        return $updater->runUpdate(
            $this->retriever,
            $input->getOption(static::OVERRIDE_OPT),
            $input->getOption('ignore-platform-reqs'),
            $this->phpVersion,
            $this->preferredStability
        );
    }
}
