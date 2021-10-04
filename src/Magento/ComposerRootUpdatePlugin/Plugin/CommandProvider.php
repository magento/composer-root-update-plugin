<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Plugin;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Magento\ComposerRootUpdatePlugin\Plugin\Commands\OverrideRequireCommand;
use Magento\ComposerRootUpdatePlugin\Plugin\Commands\RequireCommerceCommand;
use Magento\ComposerRootUpdatePlugin\Plugin\Commands\UpdatePluginNamespaceCommands;

/**
 * Composer boilerplate to supply the plugin's commands to the command registry
 */
class CommandProvider implements CommandProviderCapability
{
    /**
     * @inheritdoc
     */
    public function getCommands()
    {
        return [
            new OverrideRequireCommand(),
            new RequireCommerceCommand()
        ];
    }
}
