<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Composer\Plugin\RootUpdate;

use Composer\Plugin\Capability\CommandProvider;

/**
 * Class PluginCommandProvider
 *
 * @package Magento\Composer\Plugin\RootUpdate
 */
class PluginCommandProvider implements CommandProvider
{
    /**
     * @inheritdoc
     */
    public function getCommands()
    {
        return [new RootUpdateCommand()];
    }
}
