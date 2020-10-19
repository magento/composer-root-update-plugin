<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Updater;

use Composer\Composer;
use Composer\Downloader\FilesystemException;
use Magento\ComposerRootUpdatePlugin\Utils\PackageUtils;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\ComposerRootUpdatePlugin\Plugin\PluginDefinition;

/**
 * Handles updates of the Magento root project composer.json file based on necessary changes for the target version
 */
class MagentoRootUpdater
{
    /**
     * @var Console $console
     */
    protected $console;
    
    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var PackageUtils $pkgUtils;
     */
    protected $pkgUtils;

    /**
     * @var array $jsonChanges Json-writable sections of composer.json that have been updated
     */
    protected $jsonChanges;

    /**
     * MagentoRootUpdater constructor.
     *
     * @param Console $console
     * @param Composer $composer
     * @return void
     */
    public function __construct($console, $composer)
    {
        $this->console = $console;
        $this->composer = $composer;
        $this->pkgUtils = new PackageUtils($console, $composer);
        $this->jsonChanges = [];
    }

    /**
     * Look ahead to the target Magento version and execute any changes to the root composer.json file in-memory
     *
     * @param RootPackageRetriever $retriever
     * @param bool $overrideOption
     * @param bool $ignorePlatformReqs
     * @param string $phpVersion
     * @param string $stability
     * @return bool Returns true if updates were necessary and prepared successfully
     */
    public function runUpdate(
        $retriever,
        $overrideOption,
        $ignorePlatformReqs,
        $phpVersion,
        $stability
    ) {
        $composer = $this->composer;

        if (!$this->pkgUtils->findRequire($composer, PluginDefinition::PACKAGE_NAME)) {
            // If the plugin requirement has been removed but we're still trying to run (code still existing in the
            // vendor directory), return without executing.
            return false;
        }

        $origEdition = $retriever->getOriginalEdition();
        $origVersion = $retriever->getOriginalVersion();
        $prettyOrigVersion = $retriever->getPrettyOriginalVersion();

        if (!$retriever->getTargetRootPackage($ignorePlatformReqs, $phpVersion, $stability)) {
            throw new \RuntimeException('Magento root updates cannot run without a valid target package');
        }

        if ($origEdition == $retriever->getTargetEdition() && $origVersion == $retriever->getTargetVersion()) {
            $this->console->labeledVerbose(
                'The Magento metapackage requirement matched the current installation; no root updates are required'
            );
            return false;
        }

        if (!$retriever->getOriginalRootPackage($overrideOption)) {
            $this->console->log('Skipping Magento composer.json update.');
            return false;
        }

        $this->console->setVerboseLabel($retriever->getTargetLabel());
        $project = $this->pkgUtils->getProjectPackageName($origEdition);
        $this->console->labeledVerbose(
            "Base Magento project package version: $project $prettyOrigVersion"
        );

        $resolver = new DeltaResolver($this->console, $overrideOption, $retriever);

        $jsonChanges = $resolver->resolveRootDeltas();

        if ($jsonChanges) {
            $this->jsonChanges = $jsonChanges;
            return true;
        }

        return false;
    }

    /**
     * Write the changed composer.json file
     *
     * @return void
     * @throws FilesystemException if the composer.json read or write failed
     */
    public function writeUpdatedComposerJson()
    {
        if (!$this->jsonChanges) {
            return;
        }
        $filePath = $this->composer->getConfig()->getConfigSource()->getName();
        $json = json_decode(file_get_contents($filePath), true);
        if ($json === null) {
            throw new FilesystemException('Failed to read ' . $filePath);
        }

        foreach ($this->jsonChanges as $section => $newContents) {
            if ($newContents === null || $newContents === []) {
                if (key_exists($section, $json)) {
                    unset($json[$section]);
                }
            } else {
                $json[$section] = $newContents;
            }
        }

        $this->console->labeledVerbose('Writing changes to the root composer.json...');

        $retVal = file_put_contents(
            $filePath,
            json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        if ($retVal === false) {
            throw new FilesystemException('Failed to write updated Magento root values to ' . $filePath);
        }
        $this->console->labeledVerbose("$filePath has been updated");
    }

    /**
     * Return the changes to be made in composer.json
     *
     * @return array
     */
    public function getJsonChanges()
    {
        return $this->jsonChanges;
    }
}
