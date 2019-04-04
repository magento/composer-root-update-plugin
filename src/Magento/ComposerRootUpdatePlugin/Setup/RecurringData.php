<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * Magento module hook to attach plugin installation functionality to `magento setup` operations
 */
class RecurringData implements InstallDataInterface
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
        WebSetupWizardPluginInstaller::doVarInstall();
    }
}
