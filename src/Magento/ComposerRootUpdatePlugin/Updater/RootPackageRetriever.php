<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Updater;

use Composer\Composer;
use Composer\DependencyResolver\Pool;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\CompositeRepository;
use Magento\ComposerRootUpdatePlugin\ComposerReimplementation\AccessibleRootPackageLoader;
use Magento\ComposerRootUpdatePlugin\Utils\PackageUtils;
use Magento\ComposerRootUpdatePlugin\Utils\Console;

class RootPackageRetriever
{
    /**
     * Label used by getOriginalLabel() and getTargetLabel() when the package is currently unknown
     */
    const MISSING_ROOT_LABEL = '(unknown Magento root)';

    /**
     * @var Console $console
     */
    protected $console;

    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var PackageInterface $originalRootPackage
     */
    protected $originalRootPackage;

    /**
     * @var boolean $fetchedOriginal
     */
    protected $fetchedOriginal;

    /**
     * @var PackageInterface $targetRootPackage
     */
    protected $targetRootPackage;

    /**
     * @var boolean $fetchedTarget
     */
    protected $fetchedTarget;

    /**
     * @var string $originalEdition
     */
    protected $originalEdition = null;

    /**
     * @var string $originalVersion
     */
    protected $originalVersion = null;

    /**
     * @var string $prettyOriginalVersion
     */
    protected $prettyOriginalVersion = null;

    /**
     * @var string $targetEdition
     */
    protected $targetEdition = null;

    /**
     * @var string $targetConstraint
     */
    protected $targetConstraint = null;

    /**
     * @var string $targetVersion
     */
    protected $targetVersion = null;

    /**
     * @var string $prettyTargetVersion
     */
    protected $prettyTargetVersion = null;

    /**
     * RootPackageRetriever constructor.
     *
     * @param Console $console
     * @param Composer $composer
     * @param string $targetEdition
     * @param string $targetConstraint
     * @param string $overrideOriginalEdition
     * @param string $overrideOriginalVersion
     */
    public function __construct(
        $console,
        $composer,
        $targetEdition,
        $targetConstraint,
        $overrideOriginalEdition = null,
        $overrideOriginalVersion = null
    ) {
        $this->console = $console;
        $this->composer = $composer;

        $this->originalRootPackage = null;
        $this->fetchedOriginal = false;
        $this->targetEdition = $targetEdition;
        $this->targetConstraint = $targetConstraint;
        $this->targetRootPackage = null;
        $this->fetchedTarget = null;
        if (!$overrideOriginalEdition || !$overrideOriginalVersion) {
            $this->parseOriginalVersionAndEditionFromLock();
        } else {
            $this->originalEdition = $overrideOriginalEdition;
            $this->originalVersion = $overrideOriginalVersion;
            $this->prettyOriginalVersion = $overrideOriginalVersion;
        }
    }

    /**
     * Get the project package that should be used as the basis for Magento root comparisons
     *
     * @param boolean $overrideOption
     * @return PackageInterface|boolean
     */
    public function getOriginalRootPackage($overrideOption)
    {
        if ($this->fetchedOriginal) {
            return $this->originalRootPackage;
        }

        $originalRootPackage = null;
        $originalEdition = $this->originalEdition;
        $originalVersion = $this->originalVersion;
        $prettyOriginalVersion = $this->prettyOriginalVersion;
        if ($originalEdition && $originalVersion) {
            $originalRootPackage = $this->fetchMageRootFromRepo($originalEdition, $prettyOriginalVersion);
        }

        if (!$originalRootPackage) {
            if (!$originalEdition || !$originalVersion) {
                $this->console->warning('No Magento product package was found in the current installation.');
            } else {
                $this->console->warning('The Magento project package corresponding to the currently installed ' .
                    "\"magento/product-$originalEdition-edition: $prettyOriginalVersion\" package is unavailable.");
            }

            $overrideRoot = $overrideOption;
            if (!$overrideRoot) {
                $question = 'Would you like to update the root composer.json file anyway? ' .
                    'This will override any changes you have made to the default composer.json file.';
                $overrideRoot = $this->console->ask($question);
            }

            if ($overrideRoot) {
                $originalRootPackage = $this->getUserRootPackage();
            } else {
                $originalRootPackage = null;
            }
        }

        $this->originalRootPackage = $originalRootPackage;
        $this->fetchedOriginal = true;
        return $this->originalRootPackage;
    }

    public function getTargetRootPackage(
        $ignorePlatformReqs = true,
        $phpVersion = null,
        $preferredStability = 'stable'
    ) {
        if ($this->fetchedTarget) {
            return $this->targetRootPackage;
        }

        $targetRoot = $this->fetchMageRootFromRepo(
            $this->targetEdition,
            $this->targetConstraint,
            $ignorePlatformReqs,
            $phpVersion,
            $preferredStability
        );
        if ($targetRoot) {
            $this->targetVersion = $targetRoot->getVersion();
            $this->prettyTargetVersion = $targetRoot->getPrettyVersion();
            if (!$this->prettyTargetVersion) {
                $this->prettyTargetVersion = $this->targetVersion;
            }
        }

        $this->targetRootPackage = $targetRoot;
        $this->fetchedTarget = true;
        return $this->targetRootPackage;
    }

    public function getUserRootPackage()
    {
        return $this->composer->getPackage();
    }

    /**
     * Retrieve the Magento root package for an edition and version constraint from the composer file's repositories
     *
     * @param string $edition
     * @param string $constraint
     * @param boolean $ignorePlatformReqs
     * @param string $phpVersion
     * @param string $preferredStability
     * @return PackageInterface|boolean Best root package candidate or false if no valid packages found
     */
    protected function fetchMageRootFromRepo(
        $edition,
        $constraint,
        $ignorePlatformReqs = true,
        $phpVersion = null,
        $preferredStability = 'stable'
    ) {
        $composer = $this->composer;
        $packageName = strtolower("magento/project-$edition-edition");
        $versionParser = new VersionParser();
        $parsedConstraint = $versionParser->parseConstraints($constraint);

        $minStability = $composer->getPackage()->getMinimumStability();
        if (!$minStability) {
            $minStability = 'stable';
        }
        $stabilityFlags = AccessibleRootPackageLoader::extractStabilityFlags($packageName, $constraint, $minStability);
        $stability = key_exists($packageName, $stabilityFlags)
            ? array_search($stabilityFlags[$packageName], BasePackage::$stabilities)
            : $minStability;
        $this->console->comment("Minimum stability for \"$packageName: $constraint\": $stability", IOInterface::DEBUG);
        $pool = new Pool(
            $stability,
            $stabilityFlags,
            [$packageName => $parsedConstraint]
        );
        $repos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
        $pool->addRepository($repos);

        if (!PackageUtils::isConstraintStrict($constraint)) {
            $this->console->warning(
                "The version constraint \"magento/product-$edition-edition: $constraint\" is not exact; " .
                'the Magento root updater might not accurately determine the version to use according to other ' .
                'requirements in this installation. It is recommended to use an exact version number.'
            );
        }

        $phpVersion = $ignorePlatformReqs ? null : $phpVersion;

        $versionSelector = new VersionSelector($pool);
        $result = $versionSelector->findBestCandidate($packageName, $constraint, $phpVersion, $preferredStability);

        if (!$result) {
            $err = "Could not find a Magento project package matching \"magento/product-$edition-edition $constraint\"";
            if ($phpVersion) {
                $err = "$err for PHP version $phpVersion";
            }
            $this->console->error($err);
        }

        return $result;
    }

    /**
     * Gets the Magento product package in composer.lock and populates the version and edition in CommonUtils
     *
     * @return void
     */
    protected function parseOriginalVersionAndEditionFromLock()
    {
        $locker = $this->getRootLocker();
        if (!$locker || !$locker->isLocked()) {
            $this->console->labeledVerbose(
                'No composer.lock file was found in the root project to check for the installed Magento version'
            );
            return;
        }

        $lockPackages = $locker->getLockedRepository()->getPackages();
        $lockedMageProduct = null;
        foreach ($lockPackages as $lockedPackage) {
            $pkgEdition = PackageUtils::getMagentoProductEdition($lockedPackage->getName());
            if ($pkgEdition) {
                $lockedMageProduct = $lockedPackage;

                // Both editions exist for enterprise, so stop at enterprise to not overwrite with community
                if ($pkgEdition == 'enterprise') {
                    break;
                }
            }
        }

        if ($lockedMageProduct) {
            $this->originalEdition = PackageUtils::getMagentoProductEdition($lockedMageProduct->getName());
            $this->originalVersion = $lockedMageProduct->getVersion();
            $this->prettyOriginalVersion = $lockedMageProduct->getPrettyVersion();
            if (!$this->prettyOriginalVersion) {
                $this->prettyOriginalVersion = $this->originalVersion;
            }
        }
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
        return $locker !== null ? $locker : $composer->getLocker();
    }

    /**
     * Get the pretty label for the target Magento installation version
     *
     * @return string
     */
    public function getTargetLabel()
    {
        if ($this->targetEdition && $this->prettyTargetVersion) {
            return 'Magento ' . ucfirst($this->targetEdition) . " Edition " . $this->prettyTargetVersion;
        } elseif ($this->targetEdition && $this->targetConstraint) {
            return 'Magento ' . ucfirst($this->targetEdition) . " Edition " . $this->targetConstraint;
        }
        return static::MISSING_ROOT_LABEL;
    }

    /**
     * Get the pretty label for the original Magento installation version
     *
     * @return string
     */
    public function getOriginalLabel()
    {
        if ($this->originalEdition && $this->prettyOriginalVersion) {
            return 'Magento ' . ucfirst($this->originalEdition) . " Edition " . $this->prettyOriginalVersion;
        }
        return static::MISSING_ROOT_LABEL;
    }

    /**
     * @return string
     */
    public function getOriginalEdition()
    {
        return $this->originalEdition;
    }

    /**
     * @return string
     */
    public function getOriginalVersion()
    {
        return $this->originalVersion;
    }

    /**
     * @return string
     */
    public function getPrettyOriginalVersion()
    {
        return $this->prettyOriginalVersion;
    }

    /**
     * @return string
     */
    public function getTargetEdition()
    {
        return $this->targetEdition;
    }

    /**
     * @return string
     */
    public function getTargetVersion()
    {
        return $this->targetVersion;
    }

    /**
     * @return string
     */
    public function getPrettyTargetVersion()
    {
        return $this->prettyTargetVersion;
    }
}
