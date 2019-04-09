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
 * Class ConflictResolver
 */
class ConflictResolver
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
     * @var boolean $overrideUserValues
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
    protected $originalMageRootPackage;

    /**
     * @var RootPackageInterface $targetMageRootPackage
     */
    protected $targetMageRootPackage;

    /**
     * @var RootPackageInterface $userRootPackage
     */
    protected $userRootPackage;

    /**
     * ConflictResolver constructor.
     *
     * @param Console $console
     * @param boolean $overrideUserValues
     * @param RootPackageRetriever $retriever
     * @return void
     */
    public function __construct($console, $overrideUserValues, $retriever)
    {
        $this->console = $console;
        $this->overrideUserValues = $overrideUserValues;
        $this->retriever = $retriever;
        $this->originalMageRootPackage = $retriever->getOriginalRootPackage($overrideUserValues);
        $this->targetMageRootPackage = $retriever->getTargetRootPackage();
        $this->userRootPackage = $retriever->getUserRootPackage();
        $this->jsonChanges = [];
    }

    /**
     * Run conflict resolution between the three root projects and return the json array of changes that need to be made
     *
     * @return array
     */
    public function resolveConflicts()
    {
        $original = $this->originalMageRootPackage;
        $target = $this->targetMageRootPackage;
        $user = $this->userRootPackage;

        $this->resolveLinkSection('require', $original->getRequires(), $target->getRequires(), $user->getRequires());
        $this->resolveLinkSection('require-dev', $original->getDevRequires(), $target->getDevRequires(), $user->getDevRequires());
        $this->resolveLinkSection('conflict', $original->getConflicts(), $target->getConflicts(), $user->getConflicts());
        $this->resolveLinkSection('provide', $original->getProvides(), $target->getProvides(), $user->getProvides());
        $this->resolveLinkSection('replace', $original->getReplaces(), $target->getReplaces(), $user->getReplaces());

        $this->resolveArraySection('autoload', $original->getAutoload(), $target->getAutoload(), $user->getAutoload());
        $this->resolveArraySection('autoload-dev', $original->getDevAutoload(), $target->getDevAutoload(), $user->getDevAutoload());
        $this->resolveArraySection('extra', $original->getExtra(), $target->getExtra(), $user->getExtra());
        $this->resolveArraySection('suggest', $original->getSuggests(), $target->getSuggests(), $user->getSuggests());

        return $this->jsonChanges;
    }

    /**
     * Find value deltas from base->target version and resolve any conflicts with overlapping user changes
     *
     * @param string $field
     * @param array|mixed|null $originalMageVal
     * @param array|mixed|null $targetMageVal
     * @param array|mixed|null $userVal
     * @param string|null $prettyOriginalMageVal
     * @param string|null $prettyTargetMageVal
     * @param string|null $prettyUserVal
     * @return string|null ADD_VAL|REMOVE_VAL|CHANGE_VAL to adjust the existing composer.json file, null for no change
     */
    public function findResolution(
        $field,
        $originalMageVal,
        $targetMageVal,
        $userVal,
        $prettyOriginalMageVal = null,
        $prettyTargetMageVal = null,
        $prettyUserVal = null
    ) {
        if ($prettyOriginalMageVal === null) {
            $prettyOriginalMageVal = json_encode($originalMageVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $prettyOriginalMageVal = trim($prettyOriginalMageVal, "'\"");
        }
        if ($prettyTargetMageVal === null) {
            $prettyTargetMageVal = json_encode($targetMageVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $prettyTargetMageVal = trim($prettyTargetMageVal, "'\"");
        }
        if ($prettyUserVal === null) {
            $prettyUserVal = json_encode($userVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $prettyUserVal = trim($prettyUserVal, "'\"");
        }

        $targetLabel = $this->retriever->getTargetLabel();
        $originalLabel = $this->retriever->getOriginalLabel();

        $action = null;
        $conflictDesc = null;

        if ($originalMageVal == $targetMageVal || $userVal == $targetMageVal) {
            $action = null;
        } elseif ($originalMageVal === null) {
            if ($userVal === null) {
                $action = static::ADD_VAL;
            } else {
                $action = static::CHANGE_VAL;
                $conflictDesc = "add $field=$prettyTargetMageVal but it is instead $prettyUserVal";
            }
        } elseif ($targetMageVal === null) {
            $action = static::REMOVE_VAL;
            if ($userVal !== $originalMageVal) {
                $conflictDesc = "remove the $field=$prettyOriginalMageVal entry in $originalLabel but it is instead " .
                    $prettyUserVal;
            }
        } else {
            $action = static::CHANGE_VAL;
            if ($userVal !== $originalMageVal) {
                $conflictDesc = "update $field to $prettyTargetMageVal from $prettyOriginalMageVal in $originalLabel";
                if ($userVal === null) {
                    $action = static::ADD_VAL;
                    $conflictDesc = "$conflictDesc but the field has been removed";
                } else {
                    $conflictDesc = "$conflictDesc but it is instead $prettyUserVal";
                }
            }
        }

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
     * @param Link[] $originalMageLinks
     * @param Link[] $targetMageLinks
     * @param Link[] $userLinks
     * @return array
     */
    public function resolveLinkSection($section, $originalMageLinks, $targetMageLinks, $userLinks)
    {
        /** @var Link[] $originalLinkMap */
        $originalLinkMap = static::linksToMap($originalMageLinks);

        /** @var Link[] $targetLinkMap */
        $targetLinkMap = static::linksToMap($targetMageLinks);

        /** @var Link[] $userLinkMap */
        $userLinkMap = static::linksToMap($userLinks);

        $adds = [];
        $removes = [];
        $changes = [];
        $magePackages = array_unique(array_merge(array_keys($originalLinkMap), array_keys($targetLinkMap)));
        foreach ($magePackages as $pkg) {
            if ($section === 'require' && PackageUtils::getMagentoProductEdition($pkg)) {
                continue;
            }
            $field = "$section:$pkg";
            $originalConstraint = key_exists($pkg, $originalLinkMap) ? $originalLinkMap[$pkg]->getConstraint() : null;
            $originalMageVal = ($originalConstraint === null) ? null : $originalConstraint->__toString();
            $prettyOriginalMageVal = ($originalConstraint === null) ? null : $originalConstraint->getPrettyString();
            $targetConstraint = key_exists($pkg, $targetLinkMap) ? $targetLinkMap[$pkg]->getConstraint() : null;
            $targetMageVal = ($targetConstraint === null) ? null : $targetConstraint->__toString();
            $prettyTargetMageVal = ($targetConstraint === null) ? null : $targetConstraint->getPrettyString();
            $userConstraint = key_exists($pkg, $userLinkMap) ? $userLinkMap[$pkg]->getConstraint() : null;
            $userVal = ($userConstraint === null) ? null : $userConstraint->__toString();
            $prettyUserVal = ($userConstraint === null) ? null : $userConstraint->getPrettyString();

            $action = $this->findResolution(
                $field,
                $originalMageVal,
                $targetMageVal,
                $userVal,
                $prettyOriginalMageVal,
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

        if ($changed) {
            $replacements = array_values($adds);

            /** @var Link $userLink */
            foreach ($userLinkMap as $pkg => $userLink) {
                if (in_array($pkg, $removes)) {
                    continue;
                } elseif (key_exists($pkg, $changes)) {
                    $replacements[] = $changes[$pkg];
                } else {
                    $replacements[] = $userLink;
                }
            }

            $newJson = [];
            /** @var Link $link */
            foreach ($replacements as $link) {
                $newJson[$link->getTarget()] = $link->getConstraint()->getPrettyString();
            }

            $this->jsonChanges[$section] = $newJson;
        }

        return $this->jsonChanges;
    }

    /**
     * Process changes to an array (non-package link) section
     *
     * @param string $section
     * @param array|mixed|null $originalMageVal
     * @param array|mixed|null $targetMageVal
     * @param array|mixed|null $userVal
     * @return array
     */
    public function resolveArraySection($section, $originalMageVal, $targetMageVal, $userVal)
    {
        list($changed, $value) = $this->resolveNestedArray($section, $originalMageVal, $targetMageVal, $userVal);
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
     * @param array|mixed|null $originalMageVal
     * @param array|mixed|null $targetMageVal
     * @param array|mixed|null $userVal
     * @return array [<did_change>, <new_value>], value of null/empty array indicates to remove the entry from parent
     */
    public function resolveNestedArray($field, $originalMageVal, $targetMageVal, $userVal)
    {
        $valChanged = false;
        $result = $userVal === null ? [] : $userVal;

        if (is_array($originalMageVal) && is_array($targetMageVal) && is_array($userVal)) {
            $originalMageAssociativePart = [];
            $originalMageFlatPart = [];
            foreach ($originalMageVal as $key => $value) {
                if (is_string($key)) {
                    $originalMageAssociativePart[$key] = $value;
                } else {
                    $originalMageFlatPart[] = $value;
                }
            }

            $targetMageAssociativePart = [];
            $targetMageFlatPart = [];
            foreach ($targetMageVal as $key => $value) {
                if (is_string($key)) {
                    $targetMageAssociativePart[$key] = $value;
                } else {
                    $targetMageFlatPart[] = $value;
                }
            }

            $userAssociativePart = [];
            $userFlatPart = [];
            foreach ($userVal as $key => $value) {
                if (is_string($key)) {
                    $userAssociativePart[$key] = $value;
                } else {
                    $userFlatPart[] = $value;
                }
            }

            $associativeResult = array_filter($result, 'is_string', ARRAY_FILTER_USE_KEY);
            $mageKeys = array_unique(
                array_merge(array_keys($originalMageAssociativePart), array_keys($targetMageAssociativePart))
            );
            foreach ($mageKeys as $key) {
                if (key_exists($key, $originalMageAssociativePart)) {
                    $originalMageNestedVal = $originalMageAssociativePart[$key];
                } else {
                    $originalMageNestedVal = [];
                }
                if (key_exists($key, $targetMageAssociativePart)) {
                    $targetMageNestedVal = $targetMageAssociativePart[$key];
                } else {
                    $targetMageNestedVal = [];
                }
                if (key_exists($key, $userAssociativePart)) {
                    $userNestedVal = $userAssociativePart[$key];
                } else {
                    $userNestedVal = [];
                }

                list($changed, $value) = $this->resolveNestedArray(
                    "$field.$key",
                    $originalMageNestedVal,
                    $targetMageNestedVal,
                    $userNestedVal
                );
                if ($value === null || $value === []) {
                    if (key_exists($key, $associativeResult)) {
                        $valChanged = true;
                        unset($associativeResult[$key]);
                    }
                } else {
                    $valChanged = $valChanged || $changed;
                    $associativeResult[$key] = $value;
                }
            }

            $flatResult = array_filter($result, 'is_int', ARRAY_FILTER_USE_KEY);
            $flatAdds = array_diff(array_diff($targetMageFlatPart, $originalMageFlatPart), $flatResult);
            if ($flatAdds !== []) {
                $valChanged = true;
                $this->console->labeledVerbose("Adding $field entries: " . implode(', ', $flatAdds));
                $flatResult = array_unique(array_merge($flatResult, $flatAdds));
            }

            $flatRemoves = array_intersect(array_diff($originalMageFlatPart, $targetMageFlatPart), $flatResult);
            if ($flatRemoves !== []) {
                $valChanged = true;
                $this->console->labeledVerbose("Removing $field entries: " . implode(', ', $flatRemoves));
                $flatResult = array_diff($flatResult, $flatRemoves);
            }

            $result = array_merge($flatResult, $associativeResult);
        } else {
            // Some or all of the values aren't arrays so they should all be compared as non-array values
            $action = $this->findResolution($field, $originalMageVal, $targetMageVal, $userVal);
            $prettyTargetMageVal = json_encode($targetMageVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($action == static::ADD_VAL) {
                $valChanged = true;
                $this->console->labeledVerbose("Adding $field entry: $prettyTargetMageVal");
                $result = $targetMageVal;
            } elseif ($action == static::CHANGE_VAL) {
                $valChanged = true;
                $this->console->labeledVerbose("Updating $field entry: $prettyTargetMageVal");
                $result = $targetMageVal;
            } elseif ($action == static::REMOVE_VAL) {
                $valChanged = true;
                $this->console->labeledVerbose("Removing $field entry");
                $result = null;
            }
        }

        return [$valChanged, $result];
    }

    /**
     * Helper function to convert a set of links to an associative array with target package names as keys
     *
     * @param Link[] $links
     * @return array
     */
    protected function linksToMap($links)
    {
        $targets = array_map(function ($link) {
            /** @var Link $link */
            return $link->getTarget();
        }, $links);
        return array_combine($targets, $links);
    }
}
