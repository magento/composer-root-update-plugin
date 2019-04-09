<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Setup;

use Composer\IO\ConsoleIO;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Symfony\Component\Console\Helper\DebugFormatterHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Magento module hook to attach plugin installation functionality to `magento setup` operations
 */
class InstallData implements InstallDataInterface
{
    /**
     * Passthrough Magento setup command to check the plugin installation in the var directory
     *
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $io = new ConsoleIO(new ArrayInput([]),
            new ConsoleOutput(OutputInterface::VERBOSITY_DEBUG),
            new HelperSet([
                new FormatterHelper(),
                new DebugFormatterHelper(),
                new ProcessHelper(),
                new QuestionHelper()
            ])
        );
        $setupWizardInstaller = new WebSetupWizardPluginInstaller(new Console($io));
        $setupWizardInstaller->doVarInstall();
    }
}
