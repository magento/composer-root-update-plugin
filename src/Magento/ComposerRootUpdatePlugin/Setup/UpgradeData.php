<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\UpgradeDataInterface;

/**
 * Magento module hook to attach plugin installation functionality to `magento setup` operations
 */
class UpgradeData extends AbstractModuleOperation implements UpgradeDataInterface
{
    /**
     * Passthrough Magento setup command to check the plugin installation in the var directory
     *
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $this->doVarInstall($setup, $context);
    }
}
