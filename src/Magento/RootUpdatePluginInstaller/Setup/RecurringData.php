<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\RootUpdatePluginInstaller\Setup;

use Composer\Factory;
use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Exception;
use Magento\Composer\Plugin\RootUpdate\RootUpdatePlugin;
use Magento\RootUpdatePluginInstaller\WebSetupWizardPluginInstaller;
use Symfony\Component\Console\Helper\DebugFormatterHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Install plugin in var/vendor
 */
class RecurringData implements InstallDataInterface, UpgradeDataInterface
{
    /**
     * Passthrough Magento setup command to check the plugin installation in the var directory
     *
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $this->doVarInstall();
    }

    /**
     * Passthrough Magento upgrade command to check the plugin installation in the var directory
     *
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $this->doVarInstall();
    }

    /**
     * Install magento/composer-root-update-plugin in var/vendor when 'bin/magento setup' commands are called
     *
     * The plugin is needed there for the Web Setup Wizard's dependencies check and var/* gets cleared out
     * when 'bin/magento setup:uninstall' is called, so it needs to be reinstalled
     */
    public function doVarInstall()
    {
        $packageName = RootUpdatePlugin::PACKAGE_NAME;

        $io = new ConsoleIO(new ArrayInput([]), new ConsoleOutput(OutputInterface::VERBOSITY_DEBUG), new HelperSet([
            new FormatterHelper(),
            new DebugFormatterHelper(),
            new ProcessHelper(),
            new QuestionHelper(),
        ]));
        $factory = new Factory();
        $rootDir = preg_split('/vendor/', __DIR__)[0];
        $path = "${rootDir}composer.json";
        $composer = $factory->createComposer($io, $path, true, null, true);
        $locker = $composer->getLocker();
        if ($locker->isLocked()) {
            $pkg = $locker->getLockedRepository()->findPackage(RootUpdatePlugin::PACKAGE_NAME, '*');
            if ($pkg !== null) {
                $version = $pkg->getPrettyVersion();
                try {
                    $io->writeError(
                        "<info>Checking for \"$packageName: $version\" for the Web Setup Wizard...</info>",
                        true,
                        IOInterface::QUIET
                    );
                    WebSetupWizardPluginInstaller::updateSetupWizardPlugin($io, $composer, $path, $version);
                } catch (Exception $e) {
                    $io->writeError(
                        "<error>Web Setup Wizard installation of \"$packageName: $version\" failed.</error>",
                        true,
                        IOInterface::QUIET
                    );
                    $io->writeError($e->getMessage());
                }
            } else {
                $io->writeError(
                    "<error>Web Setup Wizard installation of \"$packageName\" failed; " .
                    "package not found in ${rootDir}composer.lock.</error>",
                    true,
                    IOInterface::QUIET
                );
            }
        } else {
            $io->writeError(
                "<error>Web Setup Wizard installation of \"$packageName\" failed; " .
                "unable to load ${rootDir}composer.lock.</error>",
                true,
                IOInterface::QUIET
            );
        }
    }
}
