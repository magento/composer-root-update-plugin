<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Utils;

use Composer\Composer;
use Composer\Package\Link;
use Composer\Package\Version\VersionParser;

/**
 * Common package-related utility functions
 */
class PackageUtils
{
    const OPEN_SOURCE_PKG_EDITION = 'community';
    const COMMERCE_PKG_EDITION = 'enterprise';

    /**
     * Helper function to extract the package type from a Magento product or project package name
     *
     * @param string $packageName
     * @return string|null 'product' or 'project' as applicable, null if not matching
     */
    static public function getMagentoPackageType($packageName)
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
    static public function getMagentoProductEdition($packageName)
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
     * Returns the Link from the Composer require section matching the given package name or regex
     *
     * @param Composer $composer
     * @param string $packageMatcher
     * @return Link|bool
     */
    static public function findRequire($composer, $packageMatcher)
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
    static public function isConstraintStrict($constraint)
    {
        $versionParser = new VersionParser();
        $parsedConstraint = $versionParser->parseConstraints($constraint);
        return strpbrk($parsedConstraint->__toString(), '[]|<>!') === false;
    }
}
