<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\RootUpdatePluginInstaller;

use Composer\Composer;
use Composer\Downloader\FilesystemException;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Link;
use Exception;
use Magento\Composer\Plugin\RootUpdate\RootUpdatePlugin;

/**
 * Class WebSetupWizardPluginInstaller
 *
 * @package Magento\Composer\Plugin\RootUpdate
 */
class WebSetupWizardPluginInstaller
{
    /**
     * Process a package event and look for changes in the plugin package version
     *
     * @param PackageEvent $event
     * @return void
     */
    public static function packageEvent($event)
    {
        $io = $event->getIO();
        $jobs = $event->getRequest()->getJobs();
        $packageName = RootUpdatePlugin::PACKAGE_NAME;
        foreach ($jobs as $job) {
            if (key_exists('packageName', $job) && $job['packageName'] === $packageName) {
                $pkg = $event->getInstalledRepo()->findPackage($packageName, '*');
                if ($pkg !== null) {
                    $version = $pkg->getPrettyVersion();
                    try {
                        $composer = $event->getComposer();
                        static::updateSetupWizardPlugin(
                            $io,
                            $composer,
                            $composer->getConfig()->getConfigSource()->getName(),
                            $version
                        );
                    } catch (Exception $e) {
                        $io->writeError(
                            "<error>Web Setup Wizard installation of \"$packageName: $version\" failed.</error>",
                            true,
                            IOInterface::QUIET
                        );
                        $io->writeError($e->getMessage());
                    }
                    break;
                }
            }
        }
    }

    /**
     * Update the plugin installation inside the ./var directory used by the Web Setup Wizard
     *
     * @param IOInterface $io
     * @param Composer $composer
     * @param string $path
     * @param string $version
     * @return void
     * @throws Exception
     */
    public static function updateSetupWizardPlugin($io, $composer, $path, $version)
    {
        $productRegex = '/magento\/product-(community|enterprise)-edition/';
        $packageName = RootUpdatePlugin::PACKAGE_NAME;
        $productLinks = array_filter(
            array_values($composer->getPackage()->getRequires()),
            function ($link) use ($productRegex) {
                /** @var Link $link */
                return preg_match($productRegex, $link->getTarget());
            }
        );
        $pluginLinks = array_filter(
            array_values($composer->getPackage()->getRequires()),
            function ($link) {
                /** @var Link $link */
                return $link->getTarget() == RootUpdatePlugin::PACKAGE_NAME;
            }
        );
        if ($productLinks == [] || $pluginLinks == []) {
            return;
        }

        if (!preg_match('/\/var\/composer\.json$/', $path)) {
            $rootDir = preg_replace('/\/composer\.json$/', '', $path);
            $varDir = "$rootDir/var";
            $factory = new Factory();
            if (file_exists("$varDir/vendor/$packageName/composer.json")) {
                $varPluginComposer = $factory->createComposer(
                    $io,
                    "$varDir/vendor/$packageName/composer.json",
                    true,
                    "$varDir/vendor/$packageName",
                    false
                );
                // If the current version of the plugin is already the version in this update, noop
                if ($varPluginComposer->getPackage()->getPrettyVersion() == $version) {
                    $io->writeError(
                        "  No Web Setup Wizard update needed for $packageName; version $version is already in $varDir.",
                        true,
                        IOInterface::VERBOSE
                    );
                    return;
                }
            }

            $io->writeError("<info>Installing \"$packageName: $version\" for the Web Setup Wizard</info>");
            if (!file_exists($varDir)) {
                mkdir($varDir);
            }
            $tmpDir = tempnam($varDir, "composer-plugin_tmp.");
            $exception = null;
            try {
                unlink($tmpDir);
                mkdir($tmpDir);
                if (file_exists("$rootDir/auth.json")) {
                    static::copyAndReplace("$rootDir/auth.json", "$tmpDir/auth.json");
                }
                $tmpConfig = [];
                $tmpConfig['repositories'] = $composer->getPackage()->getRepositories();
                $tmpConfig['require'] = [$packageName => $version];
                if ($composer->getPackage()->getMinimumStability()) {
                    $tmpConfig['minimum-stability'] = $composer->getPackage()->getMinimumStability();
                }
                $tmpJson = new JsonFile("$tmpDir/composer.json");
                $tmpJson->write($tmpConfig);
                $tmpComposer = $factory->createComposer($io, "$tmpDir/composer.json", true, $tmpDir);
                $install = Installer::create($io, $tmpComposer);
                $install
                    ->setDumpAutoloader(true)
                    ->setRunScripts(false)
                    ->setDryRun(false)
                    ->disablePlugins();
                $install->run();

                if (!file_exists($varDir)) {
                    mkdir($varDir);
                }
                static::copyAndReplace("$tmpDir/vendor", "$varDir/vendor");
            } catch (Exception $e) {
                $exception = $e;
            } finally {
                static::deleteFile($tmpDir);
            }
            if ($exception !== null) {
                throw $exception;
            }
        }
    }

    /**
     * Deletes a file or a directory and all its contents
     *
     * @param string $path
     * @return void
     * @throws FilesystemException
     */
    private static function deleteFile($path)
    {
        if (!file_exists($path)) {
            return;
        }
        if (!is_link($path) && is_dir($path)) {
            $files = array_diff(scandir($path), ['..', '.']);
            foreach ($files as $file) {
                static::deleteFile("$path/$file");
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
        static::deleteFile($target);
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
}
