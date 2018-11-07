<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Composer\Plugin\RootUpdate;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Magento\RootUpdatePluginInstaller\WebSetupWizardPluginInstaller;

/**
 * Class RootUpdatePlugin
 *
 * @package Magento\Composer\Plugin\RootUpdate
 */
class RootUpdatePlugin implements PluginInterface, Capable, EventSubscriberInterface
{
    const PACKAGE_NAME = 'magento/composer-root-update-plugin';

    /**
     * @inheritdoc
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        // Method must exist
    }

    /**
     * @inheritdoc
     */
    public function getCapabilities()
    {
        return [CommandProvider::class => PluginCommandProvider::class];
    }

    /**
     * When a package is installed or updated, check if the WebSetupWizard installation needs to be updated
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [Installer\PackageEvents::POST_PACKAGE_INSTALL => 'packageUpdate',
            Installer\PackageEvents::POST_PACKAGE_UPDATE => 'packageUpdate'];
    }

    /**
     * Forward package update events to WebSetupWizardPluginInstaller to update the plugin on install or version change
     *
     * @param PackageEvent $event
     * @return void
     */
    public function packageUpdate(PackageEvent $event)
    {
        // Safeguard against the source file being removed before the event is triggered
        if (class_exists('\Magento\RootUpdatePluginInstaller\WebSetupWizardPluginInstaller')) {
            WebSetupWizardPluginInstaller::packageEvent($event);
        }
    }
}
