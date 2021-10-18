<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Utils;

use Composer\Composer;
use Composer\Package\Link;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;

/**
 * Common package-related utility functions
 */
class PackageUtils
{
    public const OPEN_SOURCE_PKG_EDITION = 'community';
    public const OPEN_SOURCE_METAPACKAGE = 'magento/product-community-edition';
    public const COMMERCE_PKG_EDITION = 'enterprise';
    public const COMMERCE_METAPACKAGE = 'magento/product-enterprise-edition';
    public const CLOUD_PKG_EDITION = 'cloud';
    public const CLOUD_METAPACKAGE = 'magento/magento-cloud-metapackage';
    public const MAGENTO_CLOUD_DOCKER_PKG = 'magento/magento-cloud-docker';

    /**
     * @var Console $console
     */
    protected $console;

    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @param Console $console
     * @param Composer|null $composer
     */
    public function __construct(Console $console, ?Composer $composer = null)
    {
        $this->console = $console;
        $this->composer = $composer;
    }

    /**
     * Helper function to extract the edition from a package name if it is a magento/product or cloud metapackage
     * For the purposes of this plugin, 'cloud' is treated as an edition
     *
     * @param string $packageName
     * @return string|null CLOUD_PKG_EDITION, OPEN_SOURCE_PKG_EDITION, COMMERCE_PKG_EDITION, or null
     */
    public function getMetapackageEdition(string $packageName): ?string
    {
        $packageName = strtolower($packageName);
        if ($packageName == self::CLOUD_METAPACKAGE) {
            return self::CLOUD_PKG_EDITION;
        }
        $regex = '/^magento\/product-(?<edition>' . self::OPEN_SOURCE_PKG_EDITION . '|' .
            self::COMMERCE_PKG_EDITION . ')-edition$/';
        if ($packageName && preg_match($regex, $packageName, $matches)) {
            return $matches['edition'];
        } else {
            return null;
        }
    }

    /**
     * Helper function to construct the project package name from an edition
     *
     * @param string $edition
     * @return string
     */
    public function getProjectPackageName(string $edition): string
    {
        if (strtolower($edition) == self::CLOUD_PKG_EDITION) {
            return 'magento/magento-cloud-template';
        } else {
            return strtolower("magento/project-$edition-edition");
        }
    }

    /**
     * Helper function to construct the product or cloud metapackage name from an edition
     *
     * @param string $edition
     * @return string
     */
    public function getMetapackageName(string $edition): string
    {
        if (strtolower($edition) == self::CLOUD_PKG_EDITION) {
            return self::CLOUD_METAPACKAGE;
        } else {
            return strtolower("magento/product-$edition-edition");
        }
    }

    /**
     * Helper function to turn a package edition into the appropriate marketing edition label
     *
     * @param string $packageEdition
     * @return string|null
     */
    public function getEditionLabel(string $packageEdition): ?string
    {
        if ($packageEdition == self::OPEN_SOURCE_PKG_EDITION) {
            return 'Magento Open Source';
        } elseif ($packageEdition == self::COMMERCE_PKG_EDITION) {
            return 'Adobe Commerce';
        } elseif ($packageEdition == self::CLOUD_PKG_EDITION) {
            return 'Adobe Commerce Cloud';
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
    public function findRequire(Composer $composer, string $packageMatcher)
    {
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
    public function isConstraintStrict(string $constraint): bool
    {
        $versionParser = new VersionParser();
        $parsedConstraint = $versionParser->parseConstraints($constraint);
        return strpbrk($parsedConstraint->__toString(), '[]|<>!') === false;
    }

    /**
     * Checks the composer.lock for the installed metapackage
     *
     * @return PackageInterface|null
     */
    public function getLockedProduct(): ?PackageInterface
    {
        $locker = $this->getLocker();
        $lockedMetapackage = null;
        $lockedEdition = null;
        if ($locker) {
            $lockPackages = $locker->getLockedRepository()->getPackages();
            foreach ($lockPackages as $lockedPackage) {
                $pkgEdition = $this->getMetapackageEdition($lockedPackage->getName());

                if ($pkgEdition == self::CLOUD_PKG_EDITION ||
                    $pkgEdition == self::COMMERCE_PKG_EDITION && $lockedEdition != self::CLOUD_PKG_EDITION ||
                    $pkgEdition == self::OPEN_SOURCE_PKG_EDITION && $lockedEdition == null
                ) {
                    $lockedMetapackage = $lockedPackage;
                    $lockedEdition = $pkgEdition;
                }
            }
        }

        return $lockedMetapackage;
    }

    /**
     * Get the Locker for the current project
     *
     * @return Locker
     */
    protected function getLocker(): Locker
    {
        $locker = $this->composer->getLocker();
        if (!$locker || !$locker->isLocked()) {
            $this->console->labeledVerbose(
                'Unable to obtain the installed metapackage version: no composer.lock file found'
            );
            $locker = null;
        }
        return $locker;
    }
}
