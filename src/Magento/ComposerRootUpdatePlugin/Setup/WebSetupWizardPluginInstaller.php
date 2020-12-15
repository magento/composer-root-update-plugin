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
use Composer\Package\PackageInterface;
use Exception;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\ComposerRootUpdatePlugin\Utils\PackageUtils;
use Magento\ComposerRootUpdatePlugin\Plugin\PluginDefinition;

/**
 * Handles plugin installation in the `var` directory, where it needs to be present for the Web Setup Wizard
 */
class WebSetupWizardPluginInstaller
{
    /**
     * @var Console $console
     */
    protected $console;

    /**
     * @var PackageUtils $pkgUtils
     */
    protected $pkgUtils;
    
    /**
     * WebSetupWizardPluginInstaller constructor.
     *
     * @param Console $console
     * @return void
     */
    public function __construct($console)
    {
        $this->console = $console;
        $this->pkgUtils = new PackageUtils($console);
    }

    /**
     * Process a package event and look for changes in the plugin package version
     *
     * @param PackageEvent $event
     * @return void
     */
    public function packageEvent($event)
    {
        $packageName = PluginDefinition::PACKAGE_NAME;
        $composerMajorVersion = explode('.', Composer::VERSION)[0];
        if ($composerMajorVersion == '1') {
            $jobs = $event->getRequest()->getJobs();
            foreach ($jobs as $job) {
                if (key_exists('packageName', $job) && $job['packageName'] === $packageName) {
                    $pkg = $event->getInstalledRepo()->findPackage($packageName, '*');
                    if ($pkg !== null) {
                        $this->processEvent($pkg, $event);
                        break;
                    }
                }
            }
        } elseif ($composerMajorVersion == '2') {
            if (strpos($event->getOperation()->show(false), $packageName) !== false) {
                $pkg = $event->getLocalRepo()->findPackage($packageName, '*');
                if ($pkg !== null) {
                    $this->processEvent($pkg, $event);
                }
            }
        } else {
            $this->console->error(
                "Web Setup Wizard installation of \"$packageName\" failed; unrecognized composer plugin API version"
            );
        }
    }

    /**
     * Helper function to attempt to run updateSetupWizardPlugin when an appropriate PackageEvent is fired
     *
     * @param $pkg PackageInterface
     * @param $event PackageEvent
     */
    public function processEvent($pkg, $event)
    {
        $packageName = PluginDefinition::PACKAGE_NAME;
        $version = $pkg->getPrettyVersion();
        try {
            $composer = $event->getComposer();
            $this->updateSetupWizardPlugin(
                $composer,
                $composer->getConfig()->getConfigSource()->getName(),
                $version
            );
        } catch (Exception $e) {
            $this->console->error("Web Setup Wizard installation of \"$packageName: $version\" failed", $e);
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
    public function doVarInstall()
    {
        $packageName = PluginDefinition::PACKAGE_NAME;
        $rootDir = getcwd();
        $path = "$rootDir/composer.json";
        if (!file_exists($path)) {
            $this->console->error("Web Setup Wizard installation of \"$packageName\" failed; unable to load $path.");
            return 1;
        }

        $composer = (new Factory())->createComposer($this->console->getIO(), $path, true, null, true);
        $locker = $composer->getLocker();
        if ($locker->isLocked()) {
            $pkg = $locker->getLockedRepository()->findPackage(PluginDefinition::PACKAGE_NAME, '*');
            if ($pkg !== null) {
                $version = $pkg->getPrettyVersion();
                try {
                    $this->console->log(
                        "Checking for \"$packageName: $version\" for the Web Setup Wizard...",
                        Console::VERY_VERBOSE
                    );
                    $this->updateSetupWizardPlugin($composer, $path, $version);
                } catch (Exception $e) {
                    $this->console->error("Web Setup Wizard installation of \"$packageName: $version\" failed.", $e);
                    return 1;
                }
            } else {
                $this->console->error("Web Setup Wizard installation of \"$packageName\" failed; " .
                    "package not found in $rootDir/composer.lock.");
                return 1;
            }
        } else {
            $this->console->error("Web Setup Wizard installation of \"$packageName\" failed; " .
                "unable to load $rootDir/composer.lock.");
            return 1;
        }
        return 0;
    }

    /**
     * Update the plugin installation inside the ./var directory used by the Web Setup Wizard
     *
     * Does not install the plugin inside var if on a cloud installation
     *
     * @param Composer $composer
     * @param string $filePath
     * @param string $pluginVersion
     * @return bool
     * @throws Exception
     */
    public function updateSetupWizardPlugin($composer, $filePath, $pluginVersion)
    {
        $packageName = PluginDefinition::PACKAGE_NAME;

        if ($this->pkgUtils->findRequire($composer, PackageUtils::CLOUD_METAPACKAGE) !== false) {
            $this->console->log(
                "Cloud installation detected, Not installing $packageName for the Web Setup Wizard",
                Console::VERBOSE
            );
            return false;
        }

        // If in ./var already or Magento or the plugin is missing from composer.json, do not install in var
        if (!preg_match('/\/composer\.json$/', $filePath) ||
            preg_match('/\/var\/composer\.json$/', $filePath) ||
            !$this->pkgUtils->findRequire(
                $composer,
                '/magento\/product-(' . PackageUtils::OPEN_SOURCE_PKG_EDITION . '|' .
                PackageUtils::COMMERCE_PKG_EDITION . ')-edition/'
            ) ||
            !$this->pkgUtils->findRequire($composer, $packageName)) {
            return false;
        }

        $rootDir = preg_replace('/\/composer\.json$/', '', $filePath);
        $var = "$rootDir/var";
        if (file_exists("$var/vendor/$packageName/composer.json")) {
            $varPluginComposer = (new Factory())->createComposer(
                $this->console->getIO(),
                "$var/vendor/$packageName/composer.json",
                true,
                "$var/vendor/$packageName",
                false
            );

            // If the current version of the plugin is already the version in this update, noop
            if ($varPluginComposer->getPackage()->getPrettyVersion() == $pluginVersion) {
                $this->console->log(
                    "No Web Setup Wizard update needed for $packageName; version $pluginVersion is already in $var.",
                    Console::VERBOSE
                );
                return false;
            }
        }

        $this->console->info("Installing \"$packageName: $pluginVersion\" for the Web Setup Wizard");

        $tmpDir = null;
        try {
            $tmpDir = $this->getTempDir($var, $packageName, $pluginVersion);

            $install = Installer::create(
                $this->console->getIO(),
                $this->createPluginComposer($tmpDir, $pluginVersion, $composer)
            );
            $install
                ->setDumpAutoloader(true)
                ->setRunScripts(false)
                ->setDryRun(false)
                ->disablePlugins();
            $install->run();

            $this->copyAndReplace("$tmpDir/vendor", "$var/vendor");
        } finally {
            $this->deletePath($tmpDir);
        }

        return true;
    }

    /**
     * Creates a temporary directory inside var/ and returns the directory path
     *
     * @param string $varDir
     * @param string $packageName
     * @param string $pluginVersion
     * @return bool|string
     * @throws FilesystemException
     */
    private function getTempDir($varDir, $packageName, $pluginVersion)
    {
        if (!file_exists($varDir)) {
            mkdir($varDir);
        }
        if (!is_writable($varDir)) {
            throw new FilesystemException(
                "Could not install \"$packageName: $pluginVersion\" for the Web Setup Wizard; $varDir is not writable."
            );
        }

        $tmpDir = tempnam($varDir, "composer-plugin_tmp.");
        $madeDir = $tmpDir ? unlink($tmpDir) && mkdir($tmpDir) : false;

        return $madeDir ? $tmpDir : false;
    }

    /**
     * Deletes a file or a directory and all its contents
     *
     * @param string $path
     * @return void
     * @throws FilesystemException
     */
    private function deletePath($path)
    {
        if (!file_exists($path)) {
            return;
        }
        if (!is_link($path) && is_dir($path)) {
            $files = array_diff(scandir($path), ['..', '.']);
            foreach ($files as $file) {
                $this->deletePath("$path/$file");
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
    private function copyAndReplace($source, $target)
    {
        $this->deletePath($target);
        if (is_dir($source)) {
            mkdir($target);
            $files = array_diff(scandir($source), ['..', '.']);
            foreach ($files as $file) {
                $this->copyAndReplace("$source/$file", "$target/$file");
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
    private function createPluginComposer($tmpDir, $pluginVersion, $rootComposer)
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
        $tmpComposer = $factory->createComposer($this->console->getIO(), "$tmpDir/composer.json", true, $tmpDir);
        $tmpConfig = $tmpComposer->getConfig();
        $tmpConfig->setAuthConfigSource($rootComposer->getConfig()->getAuthConfigSource());
        $tmpComposer->setConfig($tmpConfig);

        return $tmpComposer;
    }
}
