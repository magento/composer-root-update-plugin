<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Setup;

use Composer\Composer;
use Composer\Downloader\FilesystemException;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\PackageEvent;
use Composer\Json\JsonFile;
use Exception;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\ComposerRootUpdatePlugin\Utils\PackageUtils;
use Magento\ComposerRootUpdatePlugin\Plugin\PluginDefinition;

/**
 * Class WebSetupWizardPluginInstaller
 */
abstract class WebSetupWizardPluginInstaller
{
    /**
     * Process a package event and look for changes in the plugin package version
     *
     * @param PackageEvent $event
     * @return void
     */
    public static function packageEvent($event)
    {
        Console::setIO($event->getIO());
        $jobs = $event->getRequest()->getJobs();
        $packageName = PluginDefinition::PACKAGE_NAME;
        foreach ($jobs as $job) {
            if (key_exists('packageName', $job) && $job['packageName'] === $packageName) {
                $pkg = $event->getInstalledRepo()->findPackage($packageName, '*');
                if ($pkg !== null) {
                    $version = $pkg->getPrettyVersion();
                    try {
                        $composer = $event->getComposer();
                        static::updateSetupWizardPlugin(
                            $composer,
                            $composer->getConfig()->getConfigSource()->getName(),
                            $version
                        );
                    } catch (Exception $e) {
                        Console::error("Web Setup Wizard installation of \"$packageName: $version\" failed.", $e);
                    }
                    break;
                }
            }
        }
    }

    /**
     * Install the plugin in var/vendor on 'bin/magento setup' commands or 'composer magento-update-plugin install'
     *
     * The plugin is needed there for the Web Setup Wizard's dependencies check and var/* gets cleared out
     * when 'bin/magento setup:uninstall' is called, so it needs to be reinstalled
     *
     * @return int 0 if successful, 1 if failed
     */
    public static function doVarInstall()
    {
        $packageName = PluginDefinition::PACKAGE_NAME;
        $rootDir = getcwd();
        $path = "$rootDir/composer.json";
        if (!file_exists($path)) {
            Console::error("Web Setup Wizard installation of \"$packageName\" failed; unable to load $path.");
            return 1;
        }

        $factory = new Factory();
        $composer = $factory->createComposer(Console::getIO(), $path, true, null, true);
        $locker = $composer->getLocker();
        if ($locker->isLocked()) {
            $pkg = $locker->getLockedRepository()->findPackage(PluginDefinition::PACKAGE_NAME, '*');
            if ($pkg !== null) {
                $version = $pkg->getPrettyVersion();
                try {
                    Console::log("Checking for \"$packageName: $version\" for the Web Setup Wizard...", Console::VERBOSE);
                    static::updateSetupWizardPlugin($composer, $path, $version);
                } catch (Exception $e) {
                    Console::error("Web Setup Wizard installation of \"$packageName: $version\" failed.", $e);
                    return 1;
                }
            } else {
                Console::error("Web Setup Wizard installation of \"$packageName\" failed; " .
                    "package not found in $rootDir/composer.lock.");
                return 1;
            }
        } else {
            Console::error("Web Setup Wizard installation of \"$packageName\" failed; " .
                "unable to load $rootDir/composer.lock.");
            return 1;
        }
        return 0;
    }

    /**
     * Update the plugin installation inside the ./var directory used by the Web Setup Wizard
     *
     * @param Composer $composer
     * @param string $filePath
     * @param string $pluginVersion
     * @return boolean
     * @throws Exception
     */
    public static function updateSetupWizardPlugin($composer, $filePath, $pluginVersion)
    {
        $packageName = PluginDefinition::PACKAGE_NAME;

        // If in ./var already or Magento or the plugin is missing from composer.json, do not install in var
        if (!preg_match('/\/composer\.json$/', $filePath) ||
            preg_match('/\/var\/composer\.json$/', $filePath) ||
            !PackageUtils::findRequire($composer, '/magento\/product-(community|enterprise)-edition/') ||
            !PackageUtils::findRequire($composer, $packageName)) {
            return false;
        }

        $rootDir = preg_replace('/\/composer\.json$/', '', $filePath);
        $var = "$rootDir/var";
        if (file_exists("$var/vendor/$packageName/composer.json")) {
            $varPluginComposer = (new Factory())->createComposer(
                Console::getIO(),
                "$var/vendor/$packageName/composer.json",
                true,
                "$var/vendor/$packageName",
                false
            );

            // If the current version of the plugin is already the version in this update, noop
            if ($varPluginComposer->getPackage()->getPrettyVersion() == $pluginVersion) {
                Console::log(
                    "No Web Setup Wizard update needed for $packageName; version $pluginVersion is already in $var.",
                    Console::VERBOSE
                );
                return false;
            }
        }

        Console::info("Installing \"$packageName: $pluginVersion\" for the Web Setup Wizard");

        if (!file_exists($var)) {
            mkdir($var);
        }
        if (!is_writable($var)) {
            throw new FilesystemException(
                "Could not install \"$packageName: $pluginVersion\" for the Web Setup Wizard; $var is not writable."
            );
        }

        $tmpDir = tempnam($var, "composer-plugin_tmp.");
        $exception = null;
        try {
            unlink($tmpDir);
            mkdir($tmpDir);

            $tmpComposer = static::createPluginComposer($tmpDir, $pluginVersion, $composer);
            $install = Installer::create(Console::getIO(), $tmpComposer);
            $install
                ->setDumpAutoloader(true)
                ->setRunScripts(false)
                ->setDryRun(false)
                ->disablePlugins();
            $install->run();

            static::copyAndReplace("$tmpDir/vendor", "$var/vendor");
        } catch (Exception $e) {
            $exception = $e;
        }

        static::deletePath($tmpDir);

        if ($exception !== null) {
            throw $exception;
        }

        return true;
    }

    /**
     * Deletes a file or a directory and all its contents
     *
     * @param string $path
     * @return void
     * @throws FilesystemException
     */
    private static function deletePath($path)
    {
        if (!file_exists($path)) {
            return;
        }
        if (!is_link($path) && is_dir($path)) {
            $files = array_diff(scandir($path), ['..', '.']);
            foreach ($files as $file) {
                static::deletePath("$path/$file");
            }
            rmdir($path);
        } else {
            unlink($path);
        }
        if (file_exists($path)) {
            throw new FilesystemException("Failed to delete $path");
        }
    }

    /**
     * Copies a file or directory and all its contents, replacing anything that exists there beforehand
     *
     * @param string $source
     * @param string $target
     * @return void
     * @throws FilesystemException
     */
    private static function copyAndReplace($source, $target)
    {
        static::deletePath($target);
        if (is_dir($source)) {
            mkdir($target);
            $files = array_diff(scandir($source), ['..', '.']);
            foreach ($files as $file) {
                static::copyAndReplace("$source/$file", "$target/$file");
            }
        } else {
            copy($source, $target);
        }
    }

    /**
     * Create a temporary composer.json file and object requiring only the plugin
     *
     * @param string $tmpDir
     * @param string $pluginVersion
     * @param Composer $rootComposer
     * @return Composer
     * @throws Exception
     */
    private static function createPluginComposer($tmpDir, $pluginVersion, $rootComposer)
    {
        $factory = new Factory();
        $tmpConfig = [
            'repositories' => $rootComposer->getPackage()->getRepositories(),
            'require' => [PluginDefinition::PACKAGE_NAME => $pluginVersion],
            'prefer-stable' => $rootComposer->getPackage()->getPreferStable()
        ];
        if ($rootComposer->getPackage()->getMinimumStability()) {
            $tmpConfig['minimum-stability'] = $rootComposer->getPackage()->getMinimumStability();
        }
        $tmpJson = new JsonFile("$tmpDir/composer.json");
        $tmpJson->write($tmpConfig);
        $tmpComposer = $factory->createComposer(Console::getIO(), "$tmpDir/composer.json", true, $tmpDir);
        $tmpConfig = $tmpComposer->getConfig();
        $tmpConfig->setAuthConfigSource($rootComposer->getConfig()->getAuthConfigSource());
        $tmpComposer->setConfig($tmpConfig);

        return $tmpComposer;
    }
}