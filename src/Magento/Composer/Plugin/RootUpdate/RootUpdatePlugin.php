<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Composer\Plugin\RootUpdate;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

/**
 * Class RootUpdatePlugin
 *
 * @package Magento\Composer\Plugin\RootUpdate
 */
class RootUpdatePlugin implements PluginInterface, Capable
{
    /**
     * @inheritdoc
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        // Nothing needs to be done when this is installed, it operates at runtime
    }

    /**
     * @inheritdoc
     */
    public function getCapabilities()
    {
        return [CommandProvider::class => PluginCommandProvider::class];
    }
}
