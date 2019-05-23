<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Updater;

use Composer\Package\Link;
use Composer\Package\RootPackageInterface;
use Magento\ComposerRootUpdatePlugin\Utils\PackageUtils;
use Magento\ComposerRootUpdatePlugin\Plugin\Commands\MageRootRequireCommand;
use Magento\ComposerRootUpdatePlugin\Utils\Console;

/**
 * Calculates updated values based on the deltas between original version, target version, and user customizations
 */
class DeltaResolver
{
    /**
     * Types of action to take on individual values when a delta is found; returned by findResolution()
     */
    const ADD_VAL = 'add_value';
    const REMOVE_VAL = 'remove_value';
    const CHANGE_VAL = 'change_value';

    /**
     * @var Console $console
     */
    protected $console;

    /**
     * @var PackageUtils $pkgUtils
     */
    protected $pkgUtils;
    
    /**
     * @var bool $overrideUserValues
     */
    protected $overrideUserValues;

    /**
     * @var array $jsonChanges
     */
    protected $jsonChanges;

    /**
     * @var RootPackageRetriever $retriever
     */
    protected $retriever;

    /**
     * @var RootPackageInterface $originalMageRootPackage
     */
    protected $origMageRootPkg;

    /**
     * @var RootPackageInterface $targetMageRootPackage
     */
    protected $targetMageRootPkg;

    /**
     * @var RootPackageInterface $userRootPackage
     */
    protected $userRootPkg;

    /**
     * DeltaResolver constructor.
     *
     * @param Console $console
     * @param bool $overrideUserValues
     * @param RootPackageRetriever $retriever
     * @return void
     */
    public function __construct($console, $overrideUserValues, $retriever)
    {
        $this->console = $console;
        $this->pkgUtils = new PackageUtils($console);
        $this->overrideUserValues = $overrideUserValues;
        $this->retriever = $retriever;
        $this->origMageRootPkg = $retriever->getOriginalRootPackage($overrideUserValues);
        $this->targetMageRootPkg = $retriever->getTargetRootPackage();
        $this->userRootPkg = $retriever->getUserRootPackage();
        $this->jsonChanges = [];
    }

    /**
     * Run conflict resolution between the three root projects and return the json array of changes that need to be made
     *
     * @return array
     */
    public function resolveRootDeltas()
    {
        $orig = $this->origMageRootPkg;
        $target = $this->targetMageRootPkg;
        $user = $this->userRootPkg;

        $this->resolveLinkSection(
            'require',
            $orig->getRequires(),
            $target->getRequires(),
            $user->getRequires(),
            true
        );
        $this->resolveLinkSection(
            'require-dev',
            $orig->getDevRequires(),
            $target->getDevRequires(),
            $user->getDevRequires(),
            true
        );
        $this->resolveLinkSection(
            'conflict',
            $orig->getConflicts(),
            $target->getConflicts(),
            $user->getConflicts(),
            false
        );
        $this->resolveLinkSection(
            'provide',
            $orig->getProvides(),
            $target->getProvides(),
            $user->getProvides(),
            false
        );
        $this->resolveLinkSection(
            'replace',
            $orig->getReplaces(),
            $target->getReplaces(),
            $user->getReplaces(),
            false
        );

        $this->resolveArraySection('autoload', $orig->getAutoload(), $target->getAutoload(), $user->getAutoload());
        $this->resolveArraySection(
            'autoload-dev',
            $orig->getDevAutoload(),
            $target->getDevAutoload(),
            $user->getDevAutoload()
        );
        $this->resolveArraySection('extra', $orig->getExtra(), $target->getExtra(), $user->getExtra());
        $this->resolveArraySection('suggest', $orig->getSuggests(), $target->getSuggests(), $user->getSuggests());

        return $this->jsonChanges;
    }

    /**
     * Find value deltas from original->target version and resolve any conflicts with overlapping user changes
     *
     * @param string $field
     * @param array|mixed|null $origMageVal
     * @param array|mixed|null $targetMageVal
     * @param array|mixed|null $userVal
     * @param string|null $prettyOrigMageVal
     * @param string|null $prettyTargetMageVal
     * @param string|null $prettyUserVal
     * @return string|null ADD_VAL|REMOVE_VAL|CHANGE_VAL to adjust the existing composer.json file, null for no change
     */
    public function findResolution(
        $field,
        $origMageVal,
        $targetMageVal,
        $userVal,
        $prettyOrigMageVal = null,
        $prettyTargetMageVal = null,
        $prettyUserVal = null
    ) {
        $prettyOrigMageVal = $this->prettify($origMageVal, $prettyOrigMageVal);
        $prettyTargetMageVal = $this->prettify($targetMageVal, $prettyTargetMageVal);
        $prettyUserVal = $this->prettify($userVal, $prettyUserVal);

        $targetLabel = $this->retriever->getTargetLabel();
        $origLabel = $this->retriever->getOriginalLabel();

        $action = null;
        $conflictDesc = null;

        if ($origMageVal == $targetMageVal || $userVal == $targetMageVal) {
            $action = null;
        } elseif ($origMageVal === null) {
            if ($userVal === null) {
                $action = static::ADD_VAL;
            } else {
                $action = static::CHANGE_VAL;
                $conflictDesc = "add $field=$prettyTargetMageVal but it is instead $prettyUserVal";
            }
        } elseif ($targetMageVal === null) {
            $action = static::REMOVE_VAL;
            if ($userVal !== $origMageVal) {
                $conflictDesc = "remove the $field=$prettyOrigMageVal entry in $origLabel but it is instead " .
                    $prettyUserVal;
            }
        } else {
            $action = static::CHANGE_VAL;
            if ($userVal !== $origMageVal) {
                $conflictDesc = "update $field to $prettyTargetMageVal from $prettyOrigMageVal in $origLabel";
                if ($userVal === null) {
                    $action = static::ADD_VAL;
                    $conflictDesc = "$conflictDesc but the field has been removed";
                } else {
                    $conflictDesc = "$conflictDesc but it is instead $prettyUserVal";
                }
            }
        }

        return $this->solveIfConflict($action, $conflictDesc, $targetLabel);
    }

    /**
     * Helper function to make a value human-readable
     *
     * @param string $val
     * @param string $prettyVal
     * @return string
     */
    protected function prettify($val, $prettyVal = null)
    {
        if ($prettyVal === null) {
            $prettyVal = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $prettyVal = trim($prettyVal, "'\"");
        }
        return $prettyVal;
    }

    /**
     * Check if a conflict was found and if so adjust the action according to override rules
     *
     * @param string $action
     * @param string|null $conflictDesc
     * @param string $targetLabel
     * @return string
     */
    protected function solveIfConflict($action, $conflictDesc, $targetLabel)
    {
        if ($conflictDesc !== null) {
            $conflictDesc = "$targetLabel is trying to $conflictDesc in this installation";

            $shouldOverride = $this->overrideUserValues;
            if ($this->overrideUserValues) {
                $this->console->log($conflictDesc);
                $this->console->log("Overriding local changes due to --" . MageRootRequireCommand::OVERRIDE_OPT . '.');
            } else {
                $shouldOverride = $this->console->ask("$conflictDesc.\nWould you like to override the local changes?");
            }

            if (!$shouldOverride) {
                $this->console->comment("$conflictDesc and will not be changed.  Re-run using " .
                    '--' . MageRootRequireCommand::OVERRIDE_OPT . ' or --' . MageRootRequireCommand::INTERACTIVE_OPT .
                    ' to override with Magento values.');
                $action = null;
            }
        }

        return $action;
    }

    /**
     * Process changes to corresponding sets of package version links
     *
     * @param string $section
     * @param Link[] $origMageLinks
     * @param Link[] $targetMageLinks
     * @param Link[] $userLinks
     * @param bool $verifyOrder
     * @return array
     */
    public function resolveLinkSection($section, $origMageLinks, $targetMageLinks, $userLinks, $verifyOrder)
    {
        $adds = [];
        $removes = [];
        $changes = [];
        $magePkgs = array_unique(array_merge(array_keys($origMageLinks), array_keys($targetMageLinks)));
        foreach ($magePkgs as $pkg) {
            if ($section === 'require' && $this->pkgUtils->getMagentoProductEdition($pkg)) {
                continue;
            }
            $this->resolveLink($section, $pkg, $origMageLinks, $targetMageLinks, $userLinks, $adds, $removes, $changes);
        }

        $changed = false;
        if ($adds !== []) {
            $changed = true;
            $prettyAdds = array_map(function ($package) use ($adds) {
                $newVal = $adds[$package]->getConstraint()->getPrettyString();
                return "$package=$newVal";
            }, array_keys($adds));
            $this->console->labeledVerbose("Adding $section constraints: " . implode(', ', $prettyAdds));
        }
        if ($removes !== []) {
            $changed = true;
            $this->console->labeledVerbose("Removing $section entries: " . implode(', ', $removes));
        }
        if ($changes !== []) {
            $changed = true;
            $prettyChanges = array_map(function ($package) use ($changes) {
                $newVal = $changes[$package]->getConstraint()->getPrettyString();
                return "$package=$newVal";
            }, array_keys($changes));
            $this->console->labeledVerbose("Updating $section constraints: " . implode(', ', $prettyChanges));
        }

        $enforcedOrder = [];
        if ($verifyOrder) {
            $enforcedOrder = $this->getLinkOrderOverride(
                $section,
                array_keys($origMageLinks),
                array_keys($targetMageLinks),
                array_keys($userLinks),
                $changed
            );
        }

        if ($changed) {
            $this->applyLinkChanges(
                $section,
                $targetMageLinks,
                $userLinks,
                $enforcedOrder,
                $adds,
                $removes,
                $changes
            );
        }

        return $this->jsonChanges;
    }

    /**
     * Helper function to find the resolution for a package constraint in the Link sections
     *
     * @param string $section
     * @param string $pkg
     * @param Link[] $origLinkMap
     * @param Link[] $targetLinkMap
     * @param Link[] $userLinkMap
     * @param Link[] $adds
     * @param Link[] $removes
     * @param Link[] $changes
     * @return void
     */
    protected function resolveLink(
        $section,
        $pkg,
        $origLinkMap,
        $targetLinkMap,
        $userLinkMap,
        &$adds,
        &$removes,
        &$changes
    ) {
        $field = "$section:$pkg";
        list($origMageVal, $prettyOrigMageVal) = $this->getConstraintValues($origLinkMap, $pkg);
        list($targetMageVal, $prettyTargetMageVal) = $this->getConstraintValues($targetLinkMap, $pkg);
        list($userVal, $prettyUserVal) = $this->getConstraintValues($userLinkMap, $pkg);

        $action = $this->findResolution(
            $field,
            $origMageVal,
            $targetMageVal,
            $userVal,
            $prettyOrigMageVal,
            $prettyTargetMageVal,
            $prettyUserVal
        );

        if ($action == static::ADD_VAL) {
            $adds[$pkg] = $targetLinkMap[$pkg];
        } elseif ($action == static::REMOVE_VAL) {
            $removes[] = $pkg;
        } elseif ($action == static::CHANGE_VAL) {
            $changes[$pkg] = $targetLinkMap[$pkg];
        }
    }

    /**
     * Helper function to get the raw and pretty forms of a constraint value for a package name
     *
     * @param Link[] $linkMap
     * @param string $pkg
     * @return array
     */
    protected function getConstraintValues($linkMap, $pkg)
    {
        $constraint = key_exists($pkg, $linkMap) ? $linkMap[$pkg]->getConstraint() : null;
        $val = null;
        $prettyVal = null;
        if ($constraint) {
            $val = $constraint->__toString();
            $prettyVal = $constraint->getPrettyString();
        }
        return [$val, $prettyVal];
    }

    /**
     * Apply added, removed, and changed links to the stored json changes
     *
     * @param string $section
     * @param Link[] $targetMageLinks
     * @param Link[] $userLinks
     * @param string[] $order
     * @param Link[] $adds
     * @param Link[] $removes
     * @param Link[] $changes
     * @return void
     */
    protected function applyLinkChanges($section, $targetMageLinks, $userLinks, $order, $adds, $removes, $changes)
    {
        $replacements = array_values($adds);

        /** @var Link $userLink */
        foreach ($userLinks as $pkg => $userLink) {
            if (in_array($pkg, $removes)) {
                continue;
            } elseif (key_exists($pkg, $changes)) {
                $replacements[] = $changes[$pkg];
            } else {
                $replacements[] = $userLink;
            }
        }

        usort($replacements, $this->buildLinkOrderComparator(
            $order,
            array_keys($targetMageLinks),
            array_keys($userLinks)
        ));

        $newJson = [];
        /** @var Link $link */
        foreach ($replacements as $link) {
            $newJson[$link->getTarget()] = $link->getConstraint()->getPrettyString();
        }

        $this->jsonChanges[$section] = $newJson;
    }

    /**
     * Process changes to an array (non-package link) section
     *
     * @param string $section
     * @param array|mixed|null $origMageVal
     * @param array|mixed|null $targetMageVal
     * @param array|mixed|null $userVal
     * @return array
     */
    public function resolveArraySection($section, $origMageVal, $targetMageVal, $userVal)
    {
        $changed = false;
        $value = $this->resolveNestedArray($section, $origMageVal, $targetMageVal, $userVal, $changed);
        if ($changed) {
            $this->jsonChanges[$section] = $value;
        }

        return $this->jsonChanges;
    }

    /**
     * Process changes to arrays that could be nested
     *
     * Associative arrays are resolved recursively and non-associative arrays are treated as unordered sets
     *
     * @param string $field
     * @param array|mixed|null $origMageVal
     * @param array|mixed|null $targetMageVal
     * @param array|mixed|null $userVal
     * @param bool $changed
     * @return array|mixed|null null/empty array indicates to remove the entry from parent
     */
    public function resolveNestedArray($field, $origMageVal, $targetMageVal, $userVal, &$changed)
    {
        $result = $userVal === null ? [] : $userVal;

        if (is_array($origMageVal) && is_array($targetMageVal) && is_array($userVal)) {
            $assocResult = $this->resolveAssociativeArray(
                $field,
                $origMageVal,
                $targetMageVal,
                $userVal,
                $changed
            );

            $flatResult = $this->resolveFlatArray(
                $field,
                $origMageVal,
                $targetMageVal,
                $userVal,
                $changed
            );

            $result = array_merge($flatResult, $assocResult);
        } else {
            // Some or all of the values aren't arrays so they should all be compared as non-array values
            $action = $this->findResolution($field, $origMageVal, $targetMageVal, $userVal);
            $prettyTargetMageVal = json_encode($targetMageVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($action == static::ADD_VAL) {
                $changed = true;
                $this->console->labeledVerbose("Adding $field entry: $prettyTargetMageVal");
                $result = $targetMageVal;
            } elseif ($action == static::CHANGE_VAL) {
                $changed = true;
                $this->console->labeledVerbose("Updating $field entry: $prettyTargetMageVal");
                $result = $targetMageVal;
            } elseif ($action == static::REMOVE_VAL) {
                $changed = true;
                $this->console->labeledVerbose("Removing $field entry");
                $result = null;
            }
        }

        return $result;
    }

    /**
     * Process changes to the non-associative portion of an array
     *
     * @param string $field
     * @param array $origMageArray
     * @param array $targetMageArray
     * @param array $userArray
     * @param bool $changed
     * @return array
     */
    protected function resolveFlatArray($field, $origMageArray, $targetMageArray, $userArray, &$changed)
    {
        $origMageFlatPart = array_filter($origMageArray, 'is_int', ARRAY_FILTER_USE_KEY);
        $targetMageFlatPart = array_filter($targetMageArray, 'is_int', ARRAY_FILTER_USE_KEY);

        $result = array_filter($userArray, 'is_int', ARRAY_FILTER_USE_KEY);
        $adds = array_diff(array_diff($targetMageFlatPart, $origMageFlatPart), $result);
        if ($adds !== []) {
            $changed = true;
            $this->console->labeledVerbose("Adding $field entries: " . implode(', ', $adds));
            $result = array_unique(array_merge($result, $adds));
        }

        $removes = array_intersect(array_diff($origMageFlatPart, $targetMageFlatPart), $result);
        if ($removes !== []) {
            $changed = true;
            $this->console->labeledVerbose("Removing $field entries: " . implode(', ', $removes));
            $result = array_diff($result, $removes);
        }

        return $result;
    }

    /**
     * Process changes to the associative portion of an array that could be nested
     *
     * @param string $field
     * @param array $origMageArray
     * @param array $targetMageArray
     * @param array $userArray
     * @param bool $changed
     * @return array
     */
    protected function resolveAssociativeArray($field, $origMageArray, $targetMageArray, $userArray, &$changed)
    {
        $origMageAssocPart = array_filter($origMageArray, 'is_string', ARRAY_FILTER_USE_KEY);
        $targetMageAssocPart = array_filter($targetMageArray, 'is_string', ARRAY_FILTER_USE_KEY);
        $userAssocPart = array_filter($userArray, 'is_string', ARRAY_FILTER_USE_KEY);

        $result = $userAssocPart;
        $mageKeys = array_unique(
            array_merge(array_keys($origMageAssocPart), array_keys($targetMageAssocPart))
        );
        foreach ($mageKeys as $key) {
            if (key_exists($key, $origMageAssocPart)) {
                $origMageNestedVal = $origMageAssocPart[$key];
            } else {
                $origMageNestedVal = [];
            }
            if (key_exists($key, $targetMageAssocPart)) {
                $targetMageNestedVal = $targetMageAssocPart[$key];
            } else {
                $targetMageNestedVal = [];
            }
            if (key_exists($key, $userAssocPart)) {
                $userNestedVal = $userAssocPart[$key];
            } else {
                $userNestedVal = [];
            }

            $value = $this->resolveNestedArray(
                "$field.$key",
                $origMageNestedVal,
                $targetMageNestedVal,
                $userNestedVal,
                $changed
            );
            if ($value === null || $value === []) {
                if (key_exists($key, $result)) {
                    $changed = true;
                    unset($result[$key]);
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get the order to use for a link section if local and target versions disagree
     *
     * @param string $section
     * @param string[] $origMageOrder
     * @param string[] $targetMageOrder
     * @param string[] $userOrder
     * @param bool $changed
     * @return string[]
     */
    protected function getLinkOrderOverride($section, $origMageOrder, $targetMageOrder, $userOrder, &$changed)
    {
        $overrideOrder = [];

        $conflictTargetOrder = array_values(array_intersect($targetMageOrder, $userOrder));
        $conflictUserOrder = array_values(array_intersect($userOrder, $targetMageOrder));

        // Check if the user's link order does not match the target section for links that appear in both
        if ($conflictTargetOrder != $conflictUserOrder) {
            $conflictOrigOrder = array_values(array_intersect($origMageOrder, $targetMageOrder));

            // Check if the user's order is different than the target order because the order has changed between
            // the original and target Magento versions
            if ($conflictOrigOrder !== $conflictUserOrder) {
                $targetLabel = $this->retriever->getTargetLabel();
                $userOrderDesc = "   [\n      " . implode(",\n      ", $conflictUserOrder) . "\n   ]";
                $targetOrderDesc = "   [\n      " . implode(",\n      ", $conflictTargetOrder) . "\n   ]";
                $conflictDesc = "$targetLabel is trying to change the existing order of the $section section.\n" .
                    "Local order:\n$userOrderDesc\n$targetLabel order:\n$targetOrderDesc";
                $shouldOverride = $this->overrideUserValues;
                if ($this->overrideUserValues) {
                    $this->console->log($conflictDesc);
                    $this->console->log(
                        'Overriding local order due to --' . MageRootRequireCommand::OVERRIDE_OPT . '.'
                    );
                } else {
                    $shouldOverride = $this->console->ask(
                        "$conflictDesc\nWould you like to override the local order?"
                    );
                }

                if (!$shouldOverride) {
                    $this->console->comment("$conflictDesc but it will not be changed. Re-run using " .
                        '--' . MageRootRequireCommand::OVERRIDE_OPT . ' or ' .
                        '--' . MageRootRequireCommand::INTERACTIVE_OPT . ' to override with the Magento order.');
                    $overrideOrder = $conflictUserOrder;
                } else {
                    $overrideOrder = $conflictTargetOrder;
                }
            } else {
                $overrideOrder = $conflictTargetOrder;
            }
        }

        if ($overrideOrder !== []) {
            $changed = true;
            $prettyOrder = "   [\n      " . implode(",\n      ", $overrideOrder) . "\n   ]";
            $this->console->labeledVerbose("Updating $section order:\n$prettyOrder");
        }

        return $overrideOrder;
    }

    /**
     * Construct a comparison function to use in sorting an array of links by prioritized order lists
     *
     * @param string[] $overrideOrder
     * @param string[] $targetMageOrder
     * @param string[] $userOrder
     * @return \Closure
     */
    protected function buildLinkOrderComparator($overrideOrder, $targetMageOrder, $userOrder)
    {
        $prioritizedOrderings = [$overrideOrder, $targetMageOrder, $userOrder];

        return function ($link1, $link2) use ($prioritizedOrderings) {
            /**
             * @var Link $link1
             * @var Link $link2
             */
            $package1 = $link1->getTarget();
            $package2 = $link2->getTarget();

            // Check each ordering array to see if it contains both links and if so use their positions to sort
            // If the ordering array does not contain both links, try the next one
            foreach ($prioritizedOrderings as $sortOrder) {
                $index1 = array_search($package1, $sortOrder);
                $index2 = array_search($package2, $sortOrder);
                if ($index1 !== false && $index2 !== false) {
                    if ($index1 == $index2) {
                        return 0;
                    } else {
                        return $index1 < $index2 ? -1 : 1;
                    }
                }
            }

            // None of the ordering arrays contain both elements, so their relative positions in the sorted array
            // do not matter
            return 0;
        };
    }
}
