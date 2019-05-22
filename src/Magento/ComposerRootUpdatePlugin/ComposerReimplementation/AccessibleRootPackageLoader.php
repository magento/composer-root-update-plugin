<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

// @codingStandardsIgnoreFile

namespace Magento\ComposerRootUpdatePlugin\ComposerReimplementation;

use Composer\Package\BasePackage;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\Version\VersionParser;

/**
 * Copy and expose necessary private methods of Composer's RootPackageLoader implementation
 *
 * Functions here may need to be updated to match future versions of Composer
 *
 * @see RootPackageLoader
 */
class AccessibleRootPackageLoader
{
    /**
     * Helper method to construct stability flags needed to fetch new root packages
     *
     * @see RootPackageLoader::extractStabilityFlags()
     *
     * @param string $reqName
     * @param string $reqVersion
     * @param string $minimumStability
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function extractStabilityFlags($reqName, $reqVersion, $minimumStability)
    {
        $stabilityFlags = [];
        $stabilityMap = BasePackage::$stabilities;
        $minimumStability = $stabilityMap[$minimumStability];
        $constraints = [];

        // extract all sub-constraints in case it is an OR/AND multi-constraint
        $orSplit = preg_split('{\s*\|\|?\s*}', trim($reqVersion));
        foreach ($orSplit as $orConstraint) {
            $andSplit = preg_split('{(?<!^|as|[=>< ,]) *(?<!-)[, ](?!-) *(?!,|as|$)}', $orConstraint);
            foreach ($andSplit as $andConstraint) {
                $constraints[] = $andConstraint;
            }
        }

        // parse explicit stability flags to the most unstable
        $match = false;
        foreach ($constraints as $constraint) {
            if (preg_match('{^[^@]*?@('.implode('|', array_keys($stabilityMap)).')$}i', $constraint, $match)) {
                $stability = $stabilityMap[VersionParser::normalizeStability($match[1])];

                if (isset($stabilityFlags[$reqName]) && $stabilityFlags[$reqName] > $stability) {
                    continue;
                }
                $stabilityFlags[$reqName] = $stability;
                $match = true;
            }
        }

        if (!$match) {
            foreach ($constraints as $constraint) {
                // infer flags for requirements that have an explicit -dev or -beta version specified but only
                // for those that are more unstable than the minimumStability or existing flags
                $reqVersion = preg_replace('{^([^,\s@]+) as .+$}', '$1', $constraint);
                if (preg_match('{^[^,\s@]+$}', $reqVersion)
                    && 'stable' !== ($stabilityName = VersionParser::parseStability($reqVersion))) {
                    $stability = $stabilityMap[$stabilityName];
                    if ((isset($stabilityFlags[$reqName]) && $stabilityFlags[$reqName] > $stability)
                        || ($minimumStability > $stability)) {
                        continue;
                    }
                    $stabilityFlags[$reqName] = $stability;
                }
            }
        }

        return $stabilityFlags;
    }
}
