<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Composer\Plugin\RootUpdate;

use Composer\Command\UpdateCommand;
use Composer\Downloader\FilesystemException;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
    const FROM_PRODUCT_OPT = 'original-magento-product';

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
        $this->addOption(
            static::FROM_PRODUCT_OPT,
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Update the current root composer.json file with changes needed from a previously-installed product ' .
            'version, e.g. magento/product-community-edition=2.2.0'
        );

        $mageHelp = '
<comment>Magento Root Updates:</comment>
  With <info>' . RootUpdatePlugin::PACKAGE_NAME . '</info> installed, <info>update</info> will also check for and
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
        if ($input->getOption('dry-run')) {
            $output->setVerbosity(max(OutputInterface::VERBOSITY_VERBOSE, $output->getVerbosity()));
            $input->setOption('verbose', true);
        }

        $composer = $this->getComposer();
        $io = $this->getIO();

        $updatePrepared = false;
        $updater = new MagentoRootUpdater($io, $composer, $input);
        try {
            // Move the native UpdateCommand's deprecation message before the added Magento functionality
            if ($input->getOption('dev')) {
                $io->writeError('<warning>' .
                    'You are using the deprecated option "dev". Dev packages are installed by default now.' .
                    '</warning>');
                $input->setOption('dev', false);
            };

            if (!$input->getOption('no-custom-installers') && !$input->getOption(static::SKIP_OPT)) {
                // --no-custom-installers has been replaced with --no-plugins and should skip this functionality
                $updatePrepared = $updater->runUpdate();
                if ($updatePrepared) {
                    $this->setComposer($updater->getComposer());
                }
            }
        } catch (\Exception $e) {
            $io->writeError('<error>Magento root update operation failed</error>', true, IOInterface::QUIET);
            $io->writeError($e->getMessage());
        }

        $errorCode = null;
        if (!$input->getOption(static::ROOT_ONLY_OPT)) {
            $errorCode = parent::execute($input, $output);

            if ($errorCode && !$updater->isStrictConstraint()) {
                $io->writeError(
                    '<warning>Recommended: Use a specific Magento version constraint instead of "' .
                    $updater->getTargetProduct() . ': ' . $updater->getTargetConstraint() . '"</warning>',
                    true,
                    IOInterface::QUIET
                );
            }
        } elseif (!$input->getOption('dry-run') && $updatePrepared) {
            // If running a full update, writeUpdatedRoot() is called as a post-update-cmd event
            $updater->writeUpdatedRoot();
        }

        return $errorCode;
    }
}
