<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Updater;

use Closure;
use Composer\Package\Link;
use Composer\Package\RootPackageInterface;
use Magento\ComposerRootUpdatePlugin\Plugin\Commands\RequireCommerceCommand;
use Magento\ComposerRootUpdatePlugin\Utils\PackageUtils;
use Magento\ComposerRootUpdatePlugin\Plugin\Commands\OverrideRequireCommand;
use Magento\ComposerRootUpdatePlugin\Utils\Console;

/**
 * Calculates updated values based on the deltas between original version, target version, and user customizations
 */
class DeltaResolver
{
    /**
     * Types of action to take on individual values when a delta is found; returned by findResolution()
     */
    public const ADD_VAL = 'add_value';
    public const REMOVE_VAL = 'remove_value';
    public const CHANGE_VAL = 'change_value';

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
     * @var RootPackageInterface $originalRootPkg
     */
    protected $originalRootPkg;

    /**
     * @var RootPackageInterface $targetRootPkg
     */
    protected $targetRootPkg;

    /**
     * @var RootPackageInterface $userRootPkg
     */
    protected $userRootPkg;

    /**
     * @var string $overrideOptLabel
     */
    protected $overrideOptLabel;

    /**
     * @var $interactiveOptLabel
     */
    protected $interactiveOptLabel;

    /**
     * @param Console $console
     * @param bool $overrideUserValues
     * @param RootPackageRetriever $retriever
     * @param bool $isOverrideCommand
     */
    public function __construct(
        Console $console,
        bool $overrideUserValues,
        RootPackageRetriever $retriever,
        bool $isOverrideCommand
    ) {
        $this->console = $console;
        $this->pkgUtils = new PackageUtils($console);
        $this->overrideUserValues = $overrideUserValues;
        $this->retriever = $retriever;
        $this->originalRootPkg = $retriever->getOriginalRootPackage($overrideUserValues);
        $this->targetRootPkg = $retriever->getTargetRootPackage();
        $this->userRootPkg = $retriever->getUserRootPackage();
        $this->jsonChanges = [];
        if ($isOverrideCommand) {
            $this->overrideOptLabel = OverrideRequireCommand::OVERRIDE_OPT;
            $this->interactiveOptLabel = OverrideRequireCommand::INTERACTIVE_OPT;
        } else {
            $this->overrideOptLabel = RequireCommerceCommand::OVERRIDE_OPT;
            $this->interactiveOptLabel = RequireCommerceCommand::INTERACTIVE_OPT;
        }
    }

    /**
     * Run conflict resolution between the three root projects and return the json array of changes that need to be made
     *
     * @return array
     */
    public function resolveRootDeltas(): array
    {
        $orig = $this->originalRootPkg;
        $target = $this->targetRootPkg;
        $user = $this->userRootPkg;

        $this->resolveLinkSection(
            'require',
            $orig->getRequires(),
            $target->getRequires(),
            $user->getRequires(),
            false
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
     * @param array|mixed|null $originalVal
     * @param array|mixed|null $targetVal
     * @param array|mixed|null $userVal
     * @param string|null $prettyOriginalVal
     * @param string|null $prettyTargetVal
     * @param string|null $prettyUserVal
     * @return string|null ADD_VAL|REMOVE_VAL|CHANGE_VAL to adjust the existing composer.json file, null for no change
     */
    public function findResolution(
        string $field,
        $originalVal,
        $targetVal,
        $userVal,
        ?string $prettyOriginalVal = null,
        ?string $prettyTargetVal = null,
        ?string $prettyUserVal = null
    ): ?string {
        $prettyOriginalVal = $this->prettify($originalVal, $prettyOriginalVal);
        $prettyTargetVal = $this->prettify($targetVal, $prettyTargetVal);
        $prettyUserVal = $this->prettify($userVal, $prettyUserVal);

        $targetLabel = $this->retriever->getTargetLabel();
        $origLabel = $this->retriever->getOriginalLabel();

        $conflictDesc = null;

        if ($originalVal == $targetVal || $userVal == $targetVal) {
            $action = null;
        } elseif ($originalVal === null) {
            if ($userVal === null) {
                $action = self::ADD_VAL;
            } else {
                $action = self::CHANGE_VAL;
                $conflictDesc = "add $field=$prettyTargetVal but it is instead $prettyUserVal";
            }
        } elseif ($targetVal === null) {
            $action = self::REMOVE_VAL;
            if ($userVal !== $originalVal) {
                $conflictDesc = "remove the $field=$prettyOriginalVal entry in $origLabel but it is instead " .
                    $prettyUserVal;
            }
        } else {
            $action = self::CHANGE_VAL;
            if ($userVal !== $originalVal) {
                $conflictDesc = "update $field to $prettyTargetVal from $prettyOriginalVal in $origLabel";
                if ($userVal === null) {
                    $action = self::ADD_VAL;
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
     * @param $val
     * @param string|null $prettyVal
     * @return string|null
     */
    protected function prettify($val, ?string $prettyVal = null): ?string
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
     * @param string|null $action
     * @param string|null $conflictDesc
     * @param string $targetLabel
     * @return string|null
     */
    protected function solveIfConflict(?string $action, ?string $conflictDesc, string $targetLabel): ?string
    {
        if ($conflictDesc !== null) {
            $conflictDesc = "$targetLabel is trying to $conflictDesc in this installation";

            $shouldOverride = $this->overrideUserValues;
            $overrideOpt = $this->overrideOptLabel;
            $interactiveOpt = $this->interactiveOptLabel;
            if ($this->overrideUserValues) {
                $this->console->log($conflictDesc);
                $this->console->log("Overriding local changes due to --$overrideOpt.");
            } else {
                $shouldOverride = $this->console->ask("$conflictDesc.\nWould you like to override the local changes?");
            }

            if (!$shouldOverride) {
                $this->console->comment("$conflictDesc and will not be changed.  Re-run using " .
                    "--$overrideOpt or --$interactiveOpt to override with suggested values.");
                $action = null;
            }
        }

        return $action;
    }

    /**
     * Process changes to corresponding sets of package version links
     *
     * @param string $section
     * @param Link[] $originalLinks
     * @param Link[] $targetLinks
     * @param Link[] $userLinks
     * @param bool $verifyOrder
     * @return array
     */
    public function resolveLinkSection(
        string $section,
        array $originalLinks,
        array $targetLinks,
        array $userLinks,
        bool $verifyOrder
    ): array {
        $toAdd = [];
        $toRemove = [];
        $toChange = [];
        $pkgsToCompare = array_unique(array_merge(array_keys($originalLinks), array_keys($targetLinks)));
        foreach ($pkgsToCompare as $pkg) {
            if ($section === 'require' && $this->pkgUtils->getMetapackageEdition($pkg)) {
                continue;
            }
            $this->resolveLink($section, $pkg, $originalLinks, $targetLinks, $userLinks, $toAdd, $toRemove, $toChange);
        }

        $changed = false;
        if ($toAdd !== []) {
            $changed = true;
            $prettyAdds = array_map(function ($package) use ($toAdd) {
                $newVal = $toAdd[$package]->getConstraint()->getPrettyString();
                return "$package=$newVal";
            }, array_keys($toAdd));
            $this->console->labeledVerbose("Adding $section constraints: " . implode(', ', $prettyAdds));
        }
        if ($toRemove !== []) {
            $changed = true;
            $this->console->labeledVerbose("Removing $section entries: " . implode(', ', $toRemove));
        }
        if ($toChange !== []) {
            $changed = true;
            $prettyChanges = array_map(function ($package) use ($toChange) {
                $newVal = $toChange[$package]->getConstraint()->getPrettyString();
                return "$package=$newVal";
            }, array_keys($toChange));
            $this->console->labeledVerbose("Updating $section constraints: " . implode(', ', $prettyChanges));
        }

        $enforcedOrder = [];
        if ($verifyOrder) {
            $enforcedOrder = $this->getLinkOrderOverride(
                $section,
                array_keys($originalLinks),
                array_keys($targetLinks),
                array_keys($userLinks),
                $changed
            );
        }

        if ($changed) {
            $this->applyLinkChanges(
                $section,
                $targetLinks,
                $userLinks,
                $enforcedOrder,
                $toAdd,
                $toRemove,
                $toChange
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
     * @param Link[] $toAdd
     * @param Link[] $toRemove
     * @param Link[] $toChange
     * @return void
     */
    protected function resolveLink(
        string $section,
        string $pkg,
        array $origLinkMap,
        array $targetLinkMap,
        array $userLinkMap,
        array &$toAdd,
        array &$toRemove,
        array &$toChange
    ) {
        $field = "$section:$pkg";
        list($originalVal, $prettyOriginalVal) = $this->getConstraintValues($origLinkMap, $pkg);
        list($targetVal, $prettyTargetVal) = $this->getConstraintValues($targetLinkMap, $pkg);
        list($userVal, $prettyUserVal) = $this->getConstraintValues($userLinkMap, $pkg);

        $action = $this->findResolution(
            $field,
            $originalVal,
            $targetVal,
            $userVal,
            $prettyOriginalVal,
            $prettyTargetVal,
            $prettyUserVal
        );

        if ($action == self::ADD_VAL) {
            $toAdd[$pkg] = $targetLinkMap[$pkg];
        } elseif ($action == self::REMOVE_VAL) {
            $toRemove[] = $pkg;
        } elseif ($action == self::CHANGE_VAL) {
            $toChange[$pkg] = $targetLinkMap[$pkg];
        }
    }

    /**
     * Helper function to get the raw and pretty forms of a constraint value for a package name
     *
     * @param Link[] $linkMap
     * @param string $pkg
     * @return string[]
     */
    protected function getConstraintValues(array $linkMap, string $pkg): array
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
     * @param Link[] $targetLinks
     * @param Link[] $userLinks
     * @param string[] $order
     * @param Link[] $toAdd
     * @param Link[] $toRemove
     * @param Link[] $toChange
     * @return void
     */
    protected function applyLinkChanges(
        string $section,
        array $targetLinks,
        array $userLinks,
        array $order,
        array $toAdd,
        array $toRemove,
        array $toChange
    ) {
        $replacements = array_values($toAdd);

        foreach ($userLinks as $pkg => $userLink) {
            if (in_array($pkg, $toRemove)) {
                continue;
            } elseif (key_exists($pkg, $toChange)) {
                $replacements[] = $toChange[$pkg];
            } else {
                $replacements[] = $userLink;
            }
        }

        usort($replacements, $this->buildLinkOrderComparator(
            $order,
            array_keys($targetLinks),
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
     * @param array|mixed|null $originalVal
     * @param array|mixed|null $targetVal
     * @param array|mixed|null $userVal
     * @return array
     */
    public function resolveArraySection(string $section, $originalVal, $targetVal, $userVal): array
    {
        $changed = false;
        $value = $this->resolveNestedArray($section, $originalVal, $targetVal, $userVal, $changed);
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
     * @param array|mixed|null $originalVal
     * @param array|mixed|null $targetVal
     * @param array|mixed|null $userVal
     * @param bool $changed
     * @return array|mixed|null null/empty array indicates to remove the entry from parent
     */
    public function resolveNestedArray(string $field, $originalVal, $targetVal, $userVal, bool &$changed)
    {
        $result = $userVal === null ? [] : $userVal;

        if (is_array($originalVal) && is_array($targetVal) && is_array($userVal)) {
            $assocResult = $this->resolveAssociativeArray(
                $field,
                $originalVal,
                $targetVal,
                $userVal,
                $changed
            );

            $flatResult = $this->resolveFlatArray(
                $field,
                $originalVal,
                $targetVal,
                $userVal,
                $changed
            );

            $result = array_merge($flatResult, $assocResult);
        } else {
            // Some or all of the values aren't arrays so they should all be compared as non-array values
            $action = $this->findResolution($field, $originalVal, $targetVal, $userVal);
            $prettyTargetVal = json_encode($targetVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($action == self::ADD_VAL) {
                $changed = true;
                $this->console->labeledVerbose("Adding $field entry: $prettyTargetVal");
                $result = $targetVal;
            } elseif ($action == self::CHANGE_VAL) {
                $changed = true;
                $this->console->labeledVerbose("Updating $field entry: $prettyTargetVal");
                $result = $targetVal;
            } elseif ($action == self::REMOVE_VAL) {
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
     * @param array $originalArray
     * @param array $targetArray
     * @param array $userArray
     * @param bool $changed
     * @return array
     */
    protected function resolveFlatArray(
        string $field,
        array $originalArray,
        array $targetArray,
        array $userArray,
        bool &$changed
    ): array {
        $originalFlatPart = array_filter($originalArray, 'is_int', ARRAY_FILTER_USE_KEY);
        $targetFlatPart = array_filter($targetArray, 'is_int', ARRAY_FILTER_USE_KEY);

        $result = array_filter($userArray, 'is_int', ARRAY_FILTER_USE_KEY);
        $toAdd = array_diff(array_diff($targetFlatPart, $originalFlatPart), $result);
        if ($toAdd !== []) {
            $changed = true;
            $this->console->labeledVerbose("Adding $field entries: " . implode(', ', $toAdd));
            $result = array_unique(array_merge($result, $toAdd));
        }

        $toRemove = array_intersect(array_diff($originalFlatPart, $targetFlatPart), $result);
        if ($toRemove !== []) {
            $changed = true;
            $this->console->labeledVerbose("Removing $field entries: " . implode(', ', $toRemove));
            $result = array_diff($result, $toRemove);
        }

        return $result;
    }

    /**
     * Process changes to the associative portion of an array that could be nested
     *
     * @param string $field
     * @param array $originalArray
     * @param array $targetArray
     * @param array $userArray
     * @param bool $changed
     * @return array
     */
    protected function resolveAssociativeArray(
        string $field,
        array $originalArray,
        array $targetArray,
        array $userArray,
        bool &$changed
    ): array {
        $originalAssocPart = array_filter($originalArray, 'is_string', ARRAY_FILTER_USE_KEY);
        $targetAssocPart = array_filter($targetArray, 'is_string', ARRAY_FILTER_USE_KEY);
        $userAssocPart = array_filter($userArray, 'is_string', ARRAY_FILTER_USE_KEY);

        $result = $userAssocPart;
        $commerceKeys = array_unique(
            array_merge(array_keys($originalAssocPart), array_keys($targetAssocPart))
        );
        foreach ($commerceKeys as $key) {
            if (key_exists($key, $originalAssocPart)) {
                $originalNestedVal = $originalAssocPart[$key];
            } else {
                $originalNestedVal = [];
            }
            if (key_exists($key, $targetAssocPart)) {
                $targetNestedVal = $targetAssocPart[$key];
            } else {
                $targetNestedVal = [];
            }
            if (key_exists($key, $userAssocPart)) {
                $userNestedVal = $userAssocPart[$key];
            } else {
                $userNestedVal = [];
            }

            $value = $this->resolveNestedArray(
                "$field.$key",
                $originalNestedVal,
                $targetNestedVal,
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
     * @param string[] $originalOrder
     * @param string[] $targetOrder
     * @param string[] $userOrder
     * @param bool $changed
     * @return string[]
     */
    protected function getLinkOrderOverride(
        string $section,
        array $originalOrder,
        array $targetOrder,
        array $userOrder,
        bool &$changed
    ): array {
        $overrideOrder = [];

        $conflictTargetOrder = array_values(array_intersect($targetOrder, $userOrder));
        $conflictUserOrder = array_values(array_intersect($userOrder, $targetOrder));
        $overrideOptLabel = $this->overrideOptLabel;
        $interactiveOptLabel = $this->interactiveOptLabel;

        // Check if the user's link order does not match the target section for links that appear in both
        if ($conflictTargetOrder != $conflictUserOrder) {
            $conflictOrigOrder = array_values(array_intersect($originalOrder, $targetOrder));

            // Check if the user's order is different than the target order because the order has changed between
            // the original and target magento/project versions
            if ($conflictOrigOrder !== $conflictUserOrder) {
                $targetLabel = $this->retriever->getTargetLabel();
                $userOrderDesc = "   [\n      " . implode(",\n      ", $conflictUserOrder) . "\n   ]";
                $targetOrderDesc = "   [\n      " . implode(",\n      ", $conflictTargetOrder) . "\n   ]";
                $conflictDesc = "$targetLabel is trying to change the existing order of the $section section.\n" .
                    "Local order:\n$userOrderDesc\n$targetLabel order:\n$targetOrderDesc\n";
                $shouldOverride = $this->overrideUserValues;
                if ($this->overrideUserValues) {
                    $this->console->log($conflictDesc);
                    $this->console->log("Overriding local order due to --$overrideOptLabel.");
                } else {
                    $shouldOverride = $this->console->ask(
                        "$conflictDesc\nWould you like to override the local order?"
                    );
                }

                if (!$shouldOverride) {
                    $this->console->comment("$conflictDesc but it will not be changed. Re-run using " .
                        "--$overrideOptLabel or --$interactiveOptLabel to override with the suggested order.");
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
     * @param string[] $targetOrder
     * @param string[] $userOrder
     * @return Closure
     */
    protected function buildLinkOrderComparator(array $overrideOrder, array $targetOrder, array $userOrder): Closure
    {
        $prioritizedOrderings = [$overrideOrder, $targetOrder, $userOrder];

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
