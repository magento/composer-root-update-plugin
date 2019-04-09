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
 * Class PackageUtils
 */
class PackageUtils
{
    /**
     * Helper function to extract the package type from a Magento product or project package name
     *
     * @param string $packageName
     * @return string|null 'product' or 'project' as applicable, null if not matching
     */
    static public function getMagentoPackageType($packageName)
    {
        $regex = '/^magento\/(?<type>product|project)-(community|enterprise)-edition$/';
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
     * @return string|null 'community' or 'enterprise' as applicable, null if not matching
     */
    static public function getMagentoProductEdition($packageName)
    {
        $regex = '/^magento\/product-(?<edition>community|enterprise)-edition$/';
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
     * @return Link|boolean
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
     * @return boolean
     */
    static public function isConstraintStrict($constraint)
    {
        $versionParser = new VersionParser();
        $parsedConstraint = $versionParser->parseConstraints($constraint);
        return strpbrk($parsedConstraint->__toString(), '[]|<>!') === false;
    }
}
