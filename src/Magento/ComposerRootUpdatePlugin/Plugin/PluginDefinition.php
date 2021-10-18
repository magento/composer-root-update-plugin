<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreCommandRunEvent;
use Magento\ComposerRootUpdatePlugin\Plugin\Commands\RequireCommerceCommand;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\ComposerRootUpdatePlugin\Utils\PackageUtils;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Composer's entry point for the plugin, defines the command provider
 */
class PluginDefinition implements PluginInterface, Capable, EventSubscriberInterface
{
    public const PACKAGE_NAME = 'magento/composer-root-update-plugin';

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
    public function deactivate(Composer $composer, IOInterface $io)
    {
        // Method must exist
    }

    /**
     * @inheritdoc
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        // Method must exist
    }

    /**
     * @inheritdoc
     */
    public function getCapabilities()
    {
        return [CommandProviderCapability::class => CommandProvider::class];
    }

    /**
     * Subscribe to the PRE_COMMAND_RUN event to check if the require command is being used instead of require-commerce
     *
     * @return string[]
     */
    public static function getSubscribedEvents()
    {
        return [PluginEvents::PRE_COMMAND_RUN => 'checkForDeprecatedRequire'];
    }

    /**
     * If the 'require' command is being run with magento/product-community-edition, magento/product-enterprise-edition,
     * or magento/magento-cloud-metapackage, tell the user to use 'require-commerce' instead
     *
     * @param PreCommandRunEvent $event
     */
    public function checkForDeprecatedRequire(PreCommandRunEvent $event): void
    {
        $command = $event->getCommand();
        $input = $event->getInput();
        if ($command == 'require' && ($requires = $input->getArgument('packages'))) {
            $parser = new VersionParser();
            $requires = $parser->parseNameVersionPairs($requires);
            foreach ($requires as $requirement) {
                $packageName = strtolower($requirement['name']);
                if ($packageName == PackageUtils::OPEN_SOURCE_METAPACKAGE
                    || $packageName == PackageUtils::COMMERCE_METAPACKAGE
                    || $packageName == PackageUtils::CLOUD_METAPACKAGE
                ) {
                    $newCmd = RequireCommerceCommand::COMMAND_NAME;
                    $console = new Console(new ConsoleIO($input, new ConsoleOutput(), new HelperSet()), false);
                    $metaLabels = PackageUtils::OPEN_SOURCE_METAPACKAGE . ', ' . PackageUtils::COMMERCE_METAPACKAGE .
                        ', or ' . PackageUtils::CLOUD_METAPACKAGE;
                    if (version_compare(Composer::VERSION, '2.1.6', '>=')) {
                        $msg = "ERROR: 'composer require' does not function properly for $metaLabels metapackages as " .
                            "of Composer 2.1.6. Use '$newCmd' instead.";
                        $console->error($msg);
                    } else {
                        $msg = "WARNING: Using 'composer require' for $metaLabels metapackages is deprecated and no " .
                            "longer supported. '$newCmd' should be used instead.";
                        $console->comment($msg);
                    }
                }
            }
        }
    }
}
