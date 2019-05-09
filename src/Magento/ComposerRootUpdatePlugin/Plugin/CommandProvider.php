<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Plugin;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Magento\ComposerRootUpdatePlugin\Plugin\Commands\MageRootRequireCommand;
use Magento\ComposerRootUpdatePlugin\Plugin\Commands\UpdatePluginNamespaceCommands;

class CommandProvider implements CommandProviderCapability
{
    /**
     * @inheritdoc
     */
    public function getCommands()
    {
        return [new MageRootRequireCommand(), new UpdatePluginNamespaceCommands()];
    }
}
