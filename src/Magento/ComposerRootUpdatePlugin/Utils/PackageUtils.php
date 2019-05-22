<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Utils;

use Composer\Composer;
use Composer\Json\JsonFile;
use Composer\Package\Link;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;

/**
 * Common package-related utility functions
 */
class PackageUtils
{
    const OPEN_SOURCE_PKG_EDITION = 'community';
    const COMMERCE_PKG_EDITION = 'enterprise';

    /**
     * @var Console $console
     */
    protected $console;

    /**
     * @var Composer $composer
     */
    protected $composer;

    public function __construct($console, $composer = null)
    {
        $this->console = $console;
        $this->composer = $composer;
    }

    /**
     * Helper function to extract the package type from a Magento product or project package name
     *
     * @param string $packageName
     * @return string|null 'product' or 'project' as applicable, null if not matching
     */
    public function getMagentoPackageType($packageName)
    {
        $regex = '/^magento\/(?<type>product|project)-(' . static::OPEN_SOURCE_PKG_EDITION . '|' .
            static::COMMERCE_PKG_EDITION . ')-edition$/';
        if (preg_match($regex, $packageName, $matches)) {
            return $matches['type'];
        } else {
            return null;
        }
    }

    /**
     * Helper function to extract the edition from a package name if it is a Magento product
     *
     * @param string $packageName
     * @return string|null OPEN_SOURCE_PKG_EDITION or COMMERCE_PKG_EDITION as applicable, null if not matching
     */
    public function getMagentoProductEdition($packageName)
    {
        $regex = '/^magento\/product-(?<edition>' . static::OPEN_SOURCE_PKG_EDITION . '|' .
            static::COMMERCE_PKG_EDITION . ')-edition$/';
        if ($packageName && preg_match($regex, $packageName, $matches)) {
            return $matches['edition'];
        } else {
            return null;
        }
    }

    /**
     * Helper function to turn a package edition into the appropriate marketing edition label
     *
     * @param string $packageEdition
     * @return string|null
     */
    public function getEditionLabel($packageEdition)
    {
        if ($packageEdition == static::OPEN_SOURCE_PKG_EDITION) {
            return 'Open Source';
        } elseif ($packageEdition == static::COMMERCE_PKG_EDITION) {
            return 'Commerce';
        }
        return null;
    }

    /**
     * Returns the Link from the Composer require section matching the given package name or regex
     *
     * @param Composer $composer
     * @param string $packageMatcher
     * @return Link|bool
     */
    public function findRequire($composer, $packageMatcher)
    {
        /** @var Link[] $requires */
        $requires = array_values($composer->getPackage()->getRequires());
        if (@preg_match($packageMatcher, null) === false) {
            foreach ($requires as $link) {
                if ($packageMatcher == $link->getTarget()) {
                    return $link;
                }
            }
        } else {
            foreach ($requires as $link) {
                if (preg_match($packageMatcher, $link->getTarget())) {
                    return $link;
                }
            }
        }

        return false;
    }

    /**
     * Is the given constraint strict or does it allow multiple versions
     *
     * @param string $constraint
     * @return bool
     */
    public function isConstraintStrict($constraint)
    {
        $versionParser = new VersionParser();
        $parsedConstraint = $versionParser->parseConstraints($constraint);
        return strpbrk($parsedConstraint->__toString(), '[]|<>!') === false;
    }

    /**
     * Checks the composer.lock for the installed Magento product package
     *
     * @return PackageInterface|null
     */
    public function getLockedProduct()
    {
        $locker = $this->getRootLocker();
        $lockedMageProduct = null;

        if ($locker) {
            $lockPackages = $locker->getLockedRepository()->getPackages();
            $lockedMageProduct = null;
            foreach ($lockPackages as $lockedPackage) {
                $pkgEdition = $this->getMagentoProductEdition($lockedPackage->getName());
                if ($pkgEdition) {
                    $lockedMageProduct = $lockedPackage;

                    // Both editions exist for commerce, so stop at commerce to not overwrite with open source
                    if ($pkgEdition == static::COMMERCE_PKG_EDITION) {
                        break;
                    }
                }
            }
        }

        return $lockedMageProduct;
    }

    /**
     * Get the Locker for the root, using the parent if currently in var
     *
     * @return Locker
     */
    protected function getRootLocker()
    {
        $composer = $this->composer;
        $composerPath = $composer->getConfig()->getConfigSource()->getName();
        $locker = null;
        if (preg_match('/\/var\/composer\.json$/', $composerPath)) {
            $parentDir = preg_replace('/\/var\/composer\.json$/', '', $composerPath);
            if (file_exists("$parentDir/composer.json") && file_exists("$parentDir/composer.lock")) {
                $locker = new Locker(
                    $this->console->getIO(),
                    new JsonFile("$parentDir/composer.lock"),
                    $composer->getRepositoryManager(),
                    $composer->getInstallationManager(),
                    file_get_contents("$parentDir/composer.json")
                );
            }
        }
        $locker = $locker !== null ? $locker : $composer->getLocker();
        if (!$locker || !$locker->isLocked()) {
            $this->console->labeledVerbose(
                'No composer.lock file was found in the root project to check for the installed Magento version'
            );
            $locker = null;
        }
        return $locker;
    }
}
