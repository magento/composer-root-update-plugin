<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Composer\Plugin\RootUpdate;

use Composer\IO\IOInterface;
use Composer\Package\Link;

/**
 * Class ConflictResolver
 *
 * @package Magento\Composer\Plugin\RootUpdate
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
     * @var IOInterface $io
     */
    private $io;

    /**
     * @var bool $interactive Has INTERACTIVE_OPT been passed to the command
     */
    private $interactive;

    /**
     * @var bool $override Has OVERRIDE_OPT been passed to the command
     */
    private $override;

    /**
     * @var string $targetLabel Pretty label for the target Magento edition version
     */
    private $targetLabel;

    /**
     * @var string $baseLabel Pretty label for the current installation's Magento edition version
     */
    private $baseLabel;

    /**
     * @var array $jsonChanges Json-writable sections of composer.json that have been updated
     */
    private $jsonChanges;

    /**
     * ConflictResolver constructor.
     *
     * @param IOInterface $io
     * @param boolean $interactive
     * @param boolean $override
     * @param string $targetLabel
     * @param string $baseLabel
     */
    public function __construct($io, $interactive, $override, $targetLabel, $baseLabel)
    {
        $this->io = $io;
        $this->interactive = $interactive;
        $this->override = $override;
        $this->targetLabel = $targetLabel;
        $this->baseLabel = $baseLabel;
        $this->jsonChanges = [];
    }

    /**
     * Find value deltas from original->target version and resolve any conflicts with overlapping user changes
     *
     * @param string $field
     * @param array|mixed|null $baseVal
     * @param array|mixed|null $targetVal
     * @param array|mixed|null $installVal
     * @param string|null $prettyBase
     * @param string|null $prettyTarget
     * @param string|null $prettyInstall
     * @return string|null ADD_VAL|REMOVE_VAL|CHANGE_VAL to adjust the existing composer.json file, null for no change
     */
    public function findResolution(
        $field,
        $baseVal,
        $targetVal,
        $installVal,
        $prettyBase = null,
        $prettyTarget = null,
        $prettyInstall = null
    ) {
        $io = $this->io;
        if ($prettyBase === null) {
            $prettyBase = json_encode($baseVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $prettyBase = trim($prettyBase, "'\"");
        }
        if ($prettyTarget === null) {
            $prettyTarget = json_encode($targetVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $prettyTarget = trim($prettyTarget, "'\"");
        }
        if ($prettyInstall === null) {
            $prettyInstall = json_encode($installVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $prettyInstall = trim($prettyInstall, "'\"");
        }

        $targetLabel = $this->targetLabel;
        $baseLabel = $this->baseLabel;

        $action = null;
        $conflictDesc = null;

        if ($baseVal == $targetVal || $installVal == $targetVal) {
            $action = null;
        } elseif ($baseVal === null) {
            if ($installVal === null) {
                $action = static::ADD_VAL;
            } else {
                $action = static::CHANGE_VAL;
                $conflictDesc = "add $field=$prettyTarget but it is instead $prettyInstall";
            }
        } elseif ($targetVal === null) {
            $action = static::REMOVE_VAL;
            if ($installVal !== $baseVal) {
                $conflictDesc = "remove the $field=$prettyBase entry in $baseLabel but it is instead $prettyInstall";
            }
        } else {
            $action = static::CHANGE_VAL;
            if ($installVal !== $baseVal) {
                $conflictDesc = "update $field to $prettyTarget from $prettyBase in $baseLabel";
                if ($installVal === null) {
                    $action = static::ADD_VAL;
                    $conflictDesc = "$conflictDesc but the field has been removed";
                } else {
                    $conflictDesc = "$conflictDesc but it is instead $prettyInstall";
                }
            }
        }

        if ($conflictDesc !== null) {
            $conflictDesc = "$targetLabel is trying to $conflictDesc in this installation";

            $shouldOverride = $this->override;
            if ($this->override) {
                $overrideMessage = "$conflictDesc.\n  Overriding local changes due to --" .
                    RootUpdateCommand::OVERRIDE_OPT . '.';
                $io->writeError($overrideMessage);
            } else {
                $shouldOverride = $this->getConfirmation(
                    "$conflictDesc.\nWould you like to override the local changes?"
                );
            }

            if (!$shouldOverride) {
                $io->writeError("<comment>$conflictDesc and will not be changed.  Re-run using " .
                    '--' . RootUpdateCommand::OVERRIDE_OPT . ' or --' . RootUpdateCommand::INTERACTIVE_OPT .
                    ' to override with Magento values.</comment>');
                $action = null;
            }
        }

        return $action;
    }

    /**
     * Process changes to corresponding sets of package version links
     *
     * @param string $section
     * @param Link[] $baseLinks
     * @param Link[] $targetLinks
     * @param Link[] $installLinks
     * @param callable $setterCallback
     * @return void
     */
    public function resolveLinkSection($section, $baseLinks, $targetLinks, $installLinks, $setterCallback)
    {
        /** @var Link[] $baseMap */
        $baseMap = static::linksToMap($baseLinks);

        /** @var Link[] $targetMap */
        $targetMap = static::linksToMap($targetLinks);

        /** @var Link[] $installMap */
        $installMap = static::linksToMap($installLinks);

        $adds = [];
        $removes = [];
        $changes = [];
        $magePackages = array_unique(array_merge(array_keys($baseMap), array_keys($targetMap)));
        foreach ($magePackages as $package) {
            if ($section === 'require' && MagentoRootUpdater::getMagentoPackageInfo($package)) {
                continue;
            }
            $field = "$section:$package";
            $baseConstraint = key_exists($package, $baseMap) ? $baseMap[$package]->getConstraint() : null;
            $baseVal = ($baseConstraint === null) ? null : $baseConstraint->__toString();
            $prettyBaseVal = ($baseConstraint === null) ? null : $baseConstraint->getPrettyString();
            $targetConstraint = key_exists($package, $targetMap) ? $targetMap[$package]->getConstraint() : null;
            $targetVal = ($targetConstraint === null) ? null : $targetConstraint->__toString();
            $prettyTargetVal = ($targetConstraint === null) ? null : $targetConstraint->getPrettyString();
            $installConstraint = key_exists($package, $installMap) ? $installMap[$package]->getConstraint() : null;
            $installVal = ($installConstraint === null) ? null : $installConstraint->__toString();
            $prettyInstallVal = ($installConstraint === null) ? null : $installConstraint->getPrettyString();

            $action = $this->findResolution(
                $field,
                $baseVal,
                $targetVal,
                $installVal,
                $prettyBaseVal,
                $prettyTargetVal,
                $prettyInstallVal
            );
            if ($action == static::ADD_VAL) {
                $adds[$package] = $targetMap[$package];
            } elseif ($action == static::REMOVE_VAL) {
                $removes[] = $package;
            } elseif ($action == static::CHANGE_VAL) {
                $changes[$package] = $targetMap[$package];
            }
        }

        $changed = false;
        if ($adds !== []) {
            $changed = true;
            $prettyAdds = array_map(function ($package) use ($adds) {
                $newVal = $adds[$package]->getConstraint()->getPrettyString();
                return "$package=$newVal";
            }, array_keys($adds));
            $this->verboseLog("Adding $section constraints: " . implode(', ', $prettyAdds));
        }
        if ($removes !== []) {
            $changed = true;
            $this->verboseLog("Removing $section entries: " . implode(', ', $removes));
        }
        if ($changes !== []) {
            $changed = true;
            $prettyChanges = array_map(function ($package) use ($changes) {
                $newVal = $changes[$package]->getConstraint()->getPrettyString();
                return "$package=$newVal";
            }, array_keys($changes));
            $this->verboseLog("Updating $section constraints: " . implode(', ', $prettyChanges));
        }

        if ($changed) {
            $replacements = array_values($adds);

            /** @var Link $installLink */
            foreach ($installMap as $package => $installLink) {
                if (in_array($package, $removes)) {
                    continue;
                } elseif (key_exists($package, $changes)) {
                    $replacements[] = $changes[$package];
                } else {
                    $replacements[] = $installLink;
                }
            }

            $newJson = [];
            /** @var Link $link */
            foreach ($replacements as $link) {
                $newJson[$link->getTarget()] = $link->getConstraint()->getPrettyString();
            }

            call_user_func($setterCallback, $replacements);
            $this->jsonChanges[$section] = $newJson;
        }
    }

    /**
     * Process changes to an array (non-package link) section
     *
     * @param string $section
     * @param array|mixed|null $baseVal
     * @param array|mixed|null $targetVal
     * @param array|mixed|null $installVal
     * @param callable $setterCallback
     * @return void
     */
    public function resolveArraySection($section, $baseVal, $targetVal, $installVal, $setterCallback)
    {
        $resolution = $this->resolveNestedArray($section, $baseVal, $targetVal, $installVal);
        if ($resolution['changed']) {
            call_user_func($setterCallback, $resolution['value']);
            $this->jsonChanges[$section] = $resolution['value'];
        }
    }

    /**
     * Process changes to arrays that could be nested
     *
     * Associative arrays are resolved recursively and non-associative arrays are treated as unordered sets
     *
     * @param string $field
     * @param array|mixed|null $baseVal
     * @param array|mixed|null $targetVal
     * @param array|mixed|null $installVal
     * @return array Two-element array: ['changed' => boolean, 'value' => updated value], null and empty array values
     * indicate the entry should be removed from the parent
     */
    public function resolveNestedArray($field, $baseVal, $targetVal, $installVal)
    {
        $valChanged = false;
        $result = $installVal ?? [];

        if (is_array($baseVal) && is_array($targetVal) && is_array($installVal)) {
            $baseAssociative = [];
            $baseFlat = [];
            foreach ($baseVal as $key => $value) {
                if (is_string($key)) {
                    $baseAssociative[$key] = $value;
                } else {
                    $baseFlat[] = $value;
                }
            }

            $targetAssociative = [];
            $targetFlat = [];
            foreach ($targetVal as $key => $value) {
                if (is_string($key)) {
                    $targetAssociative[$key] = $value;
                } else {
                    $targetFlat[] = $value;
                }
            }

            $installAssociative = [];
            $installFlat = [];
            foreach ($installVal as $key => $value) {
                if (is_string($key)) {
                    $installAssociative[$key] = $value;
                } else {
                    $installFlat[] = $value;
                }
            }

            $associativeResult = array_filter($result, 'is_string', ARRAY_FILTER_USE_KEY);
            $mageKeys = array_unique(array_merge(array_keys($baseAssociative), array_keys($targetAssociative)));
            foreach ($mageKeys as $key) {
                $baseNestedVal = $baseAssociative[$key] ?? [];
                $targetNestedVal = $targetAssociative[$key] ?? [];
                $installNestedVal = $installAssociative[$key] ?? [];

                $resolution = $this->resolveNestedArray(
                    "$field.$key",
                    $baseNestedVal,
                    $targetNestedVal,
                    $installNestedVal
                );
                if ($resolution['value'] === null || $resolution['value'] === []) {
                    if (key_exists($key, $associativeResult)) {
                        $valChanged = true;
                        unset($associativeResult[$key]);
                    }
                } else {
                    $valChanged = $valChanged || $resolution['changed'];
                    $associativeResult[$key] = $resolution['value'];
                }
            }

            $flatResult = array_filter($result, 'is_int', ARRAY_FILTER_USE_KEY);
            $flatAdds = array_diff(array_diff($targetFlat, $baseFlat), $flatResult);
            if ($flatAdds !== []) {
                $valChanged = true;
                $this->verboseLog("Adding $field entries: " . implode(', ', $flatAdds));
                $flatResult = array_unique(array_merge($flatResult, $flatAdds));
            }

            $flatRemoves = array_intersect(array_diff($baseFlat, $targetFlat), $flatResult);
            if ($flatRemoves !== []) {
                $valChanged = true;
                $this->verboseLog("Removing $field entries: " . implode(', ', $flatRemoves));
                $flatResult = array_diff($flatResult, $flatRemoves);
            }

            $result = array_merge($flatResult, $associativeResult);
        } else {
            // Some or all of the values aren't arrays so they should all be compared as non-array values
            $action = $this->findResolution($field, $baseVal, $targetVal, $installVal);
            $prettyTargetVal = json_encode($targetVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($action == static::ADD_VAL) {
                $valChanged = true;
                $this->verboseLog("Adding $field entry: $prettyTargetVal");
                $result = $targetVal;
            } elseif ($action == static::CHANGE_VAL) {
                $valChanged = true;
                $this->verboseLog("Updating $field entry: $prettyTargetVal");
                $result = $targetVal;
            } elseif ($action == static::REMOVE_VAL) {
                $valChanged = true;
                $this->verboseLog("Removing $field entry");
                $result = null;
            }
        }

        return ['changed' => $valChanged, 'value' => $result];
    }

    /**
     * Get the json array representation of the changed fields
     *
     * @return array
     */
    public function getJsonChanges()
    {
        return $this->jsonChanges;
    }

    /**
     * If interactive, ask the given question and return the result, otherwise return the default
     *
     * @param string $question
     * @param bool $default
     * @return bool
     */
    private function getConfirmation($question, $default = false)
    {
        $result = $default;
        if ($this->interactive) {
            if (!$this->io->isInteractive()) {
                throw new \InvalidArgumentException(
                    '--' . RootUpdateCommand::INTERACTIVE_OPT . ' cannot be used in non-interactive terminals.'
                );
            }
            $opts = $default ? 'Y,n' : 'y,N';
            $result = $this->io->askConfirmation("<info>$question</info> [<comment>$opts</comment>]? ", $default);
        }
        return $result;
    }

    /**
     * Label and log the given message if output is set to verbose
     *
     * @param string $message
     * @return void
     */
    private function verboseLog($message)
    {
        $label = $this->targetLabel;
        $this->io->writeError(" <comment>[</comment>$label<comment>]</comment> $message", true, IOInterface::VERBOSE);
    }

    /**
     * Helper function to convert a set of links to an associative array with target package names as keys
     *
     * @param Link[] $links
     * @return array
     */
    private function linksToMap($links)
    {
        $targets = array_map(function ($link) {
            /** @var Link $link */
            return $link->getTarget();
        }, $links);
        return array_combine($targets, $links);
    }
}
