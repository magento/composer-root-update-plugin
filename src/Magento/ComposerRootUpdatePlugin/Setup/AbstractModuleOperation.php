<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Setup;

use Composer\IO\ConsoleIO;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Symfony\Component\Console\Helper\DebugFormatterHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides the ability for Magento module operations to trigger WebSetupWizardPluginInstaller::doVarInstall()
 */
abstract class AbstractModuleOperation
{
    /**
     * Helper function to call WebSetupWizardPluginInstaller::doVarInstall() with a default ConsoleIO
     *
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return int
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function doVarInstall(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $io = new ConsoleIO(
            new ArrayInput([]),
            new ConsoleOutput(OutputInterface::VERBOSITY_DEBUG),
            new HelperSet([
                new FormatterHelper(),
                new DebugFormatterHelper(),
                new ProcessHelper()
            ])
        );
        $setupWizardInstaller = new WebSetupWizardPluginInstaller(new Console($io));
        return $setupWizardInstaller->doVarInstall();
    }
}
