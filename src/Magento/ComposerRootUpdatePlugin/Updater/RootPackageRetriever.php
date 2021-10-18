<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Updater;

use Composer\Composer;
use Composer\DependencyResolver\Pool;
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositorySet;
use Composer\Repository\VcsRepository;
use Magento\ComposerRootUpdatePlugin\ComposerReimplementation\AccessibleRootPackageLoader;
use Magento\ComposerRootUpdatePlugin\Utils\PackageUtils;
use Magento\ComposerRootUpdatePlugin\Utils\Console;

/**
 * Contains methods to retrieve composer Package objects for the relevant magento/project root packages
 */
class RootPackageRetriever
{
    /**
     * Label used by getOriginalLabel() and getTargetLabel() when the package is currently unknown
     */
    protected const MISSING_ROOT_LABEL = '(unknown magento/project root)';

    /**
     * @var Console $console
     */
    protected $console;

    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var PackageUtils $pkgUtils
     */
    protected $pkgUtils;

    /**
     * @var PackageInterface $origRootPackage
     */
    protected $origRootPackage;

    /**
     * @var bool $fetchedOrig
     */
    protected $fetchedOrig;

    /**
     * @var PackageInterface $targetRootPackage
     */
    protected $targetRootPackage;

    /**
     * @var bool $fetchedTarget
     */
    protected $fetchedTarget;

    /**
     * @var string $origEdition
     */
    protected $origEdition;

    /**
     * @var string $origVersion
     */
    protected $origVersion;

    /**
     * @var string $prettyOrigVersion
     */
    protected $prettyOrigVersion;

    /**
     * @var string $targetEdition
     */
    protected $targetEdition;

    /**
     * @var string $targetConstraint
     */
    protected $targetConstraint;

    /**
     * @var string $targetVersion
     */
    protected $targetVersion;

    /**
     * @var string $prettyTargetVersion
     */
    protected $prettyTargetVersion;

    /**
     * @param Console $console
     * @param Composer $composer
     * @param string $targetEdition
     * @param string $targetConstraint
     * @param string|null $overrideOrigEdition
     * @param string|null $overrideOrigVersion
     */
    public function __construct(
        Console $console,
        Composer $composer,
        string $targetEdition,
        string $targetConstraint,
        ?string $overrideOrigEdition = null,
        ?string $overrideOrigVersion = null
    ) {
        $this->console = $console;
        $this->composer = $composer;
        $this->pkgUtils = new PackageUtils($console, $composer);

        $this->origRootPackage = null;
        $this->fetchedOrig = false;
        $this->targetEdition = $targetEdition;
        $this->targetConstraint = $targetConstraint;
        $this->targetRootPackage = null;
        $this->fetchedTarget = null;
        $this->targetVersion = null;
        $this->prettyTargetVersion = null;
        if (!$overrideOrigEdition || !$overrideOrigVersion) {
            $this->parseVersionAndEditionFromLock($overrideOrigEdition, $overrideOrigVersion);
        } else {
            $this->origEdition = $overrideOrigEdition;
            $this->origVersion = $overrideOrigVersion;
            $this->prettyOrigVersion = $overrideOrigVersion;
        }
    }

    /**
     * Get the magento/project package that should be used as the basis for root comparisons
     *
     * @param bool $overrideOption
     * @return PackageInterface|bool
     */
    public function getOriginalRootPackage(bool $overrideOption)
    {
        if (!$this->fetchedOrig) {
            $originalRootPackage = null;
            $originalEdition = $this->origEdition;
            $originalVersion = $this->origVersion;
            $prettyOrigVersion = $this->prettyOrigVersion;
            if ($originalEdition && $originalVersion) {
                $originalRootPackage = $this->fetchProjectFromRepo($originalEdition, $prettyOrigVersion);
            }

            if (!$originalRootPackage) {
                if (!$originalEdition || !$originalVersion) {
                    $this->console->warning('No magento/product metapackage was found in the current installation.');
                } else {
                    $metapackageName = $this->pkgUtils->getMetapackageName($originalEdition);
                    $this->console->warning('The magento/project package corresponding to the currently installed ' .
                        "\"$metapackageName: $prettyOrigVersion\" package is unavailable.");
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

            $this->origRootPackage = $originalRootPackage;
            $this->fetchedOrig = true;
        }

        return $this->origRootPackage;
    }

    /**
     * Get the magento/project package that should be used as the target for root comparisons
     *
     * @param bool $ignorePlatformReqs
     * @param string|null $phpVersion
     * @param string $preferredStability
     * @return PackageInterface|bool
     */
    public function getTargetRootPackage(
        bool $ignorePlatformReqs = true,
        ?string $phpVersion = null,
        string $preferredStability = 'stable'
    ) {
        if (!$this->fetchedTarget) {
            $targetRoot = $this->fetchProjectFromRepo(
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
        }

        return $this->targetRootPackage;
    }

    /**
     * Get the currently installed root package
     *
     * @return RootPackageInterface|null
     */
    public function getUserRootPackage(): ?RootPackageInterface
    {
        return $this->composer->getPackage();
    }

    /**
     * Retrieve the magento/project package for an edition and version constraint from the composer file's repositories
     *
     * @param string $edition
     * @param string $constraint
     * @param bool $ignorePlatformReqs
     * @param string|null $phpVersion
     * @param string $preferredStability
     * @return PackageInterface|bool Best project package candidate or false if no valid packages found
     */
    protected function fetchProjectFromRepo(
        string $edition,
        string $constraint,
        bool $ignorePlatformReqs = true,
        ?string $phpVersion = null,
        string $preferredStability = 'stable'
    ) {
        $packageName = $this->pkgUtils->getProjectPackageName($edition);
        $phpVersion = $ignorePlatformReqs ? null : $phpVersion;

        $minStability = $this->composer->getPackage()->getMinimumStability();
        if (!$minStability) {
            $minStability = 'stable';
        }
        $rootPackageLoader = new AccessibleRootPackageLoader();
        $stabilityFlags = $rootPackageLoader->extractStabilityFlags($packageName, $constraint, $minStability);

        $metapackageName = $this->pkgUtils->getMetapackageName($edition);
        if ($edition != PackageUtils::CLOUD_PKG_EDITION && !$this->pkgUtils->isConstraintStrict($constraint)) {
            $this->console->warning(
                "The version constraint \"$metapackageName: $constraint\" is not exact; the root updater plugin " .
                "might not accurately determine the version to use according to other requirements in this " .
                "installation. It is recommended to use an exact version number."
            );
        }

        $bestCandidate = $this->findBestCandidate(
            $packageName,
            $edition,
            $constraint,
            $minStability,
            $stabilityFlags,
            $preferredStability,
            $ignorePlatformReqs,
            $phpVersion
        );

        if (!$bestCandidate) {
            $err = "Could not find a magento/project package matching \"$metapackageName $constraint\"";
            if ($phpVersion) {
                $err = "$err for PHP version $phpVersion";
            }
            $this->console->error($err);
        }

        return $bestCandidate;
    }

    /**
     * Wrapper functions around different versions of VersionSelector::findBestCandidate()
     *
     * @param string $packageName
     * @param string $edition
     * @param string $constraint
     * @param string $minStability
     * @param array $stabilityFlags
     * @param string $preferredStability
     * @param bool $ignorePlatformReqs
     * @param string|null $phpVersion
     * @return PackageInterface|false
     *
     * @see VersionSelector::findBestCandidate()
     */
    protected function findBestCandidate(
        string $packageName,
        string $edition,
        string $constraint,
        string $minStability,
        array $stabilityFlags,
        string $preferredStability,
        bool $ignorePlatformReqs,
        ?string $phpVersion
    ) {
        $bestCandidate = null;
        if (version_compare(Composer::VERSION, '2.0.0', '<')) {
            $bestCandidate = $this->findBestCandidateComposer1(
                $packageName,
                $edition,
                $constraint,
                $minStability,
                $stabilityFlags,
                $preferredStability,
                $phpVersion
            );
        } elseif (version_compare(Composer::VERSION, '3.0.0', '<')) {
            $bestCandidate = $this->findBestCandidateComposer2(
                $packageName,
                $edition,
                $constraint,
                $minStability,
                $stabilityFlags,
                $preferredStability,
                $ignorePlatformReqs
            );
        } else {
            $this->console->error(
                "Fetching root magento/project composer failed; unrecognized composer plugin API version" & Composer::VERSION
            );
        }
        return $bestCandidate;
    }

    /**
     * Helper function to run VersionSelector::findBestCandidate() on Composer version 1.x.x
     *
     * @param string $packageName
     * @param string $edition
     * @param string $constraint
     * @param string $minStability
     * @param array $stabilityFlags
     * @param string $preferredStability
     * @param string|null $phpVersion
     * @return PackageInterface|false
     */
    private function findBestCandidateComposer1(
        string $packageName,
        string $edition,
        string $constraint,
        string $minStability,
        array $stabilityFlags,
        string $preferredStability,
        ?string $phpVersion
    ) {
        $parsedConstraint = (new VersionParser())->parseConstraints($constraint);
        $stability = key_exists($packageName, $stabilityFlags)
            ? array_search($stabilityFlags[$packageName], BasePackage::$stabilities)
            : $minStability;

        $pool = new Pool(
            $stability,
            $stabilityFlags,
            [$packageName => $parsedConstraint]
        );

        if ($edition == PackageUtils::CLOUD_PKG_EDITION) {
            // magento/magento-cloud-template exists on github, not the composer repo
            $repoConfig = [
                'url' => 'https://github.com/magento/magento-cloud',
                'type' => 'vcs'
            ];
            $pool->addRepository(new VcsRepository(
                $repoConfig,
                $this->console->getIO(),
                $this->composer->getConfig()
            ));
        } else {
            $pool->addRepository(new CompositeRepository($this->composer->getRepositoryManager()->getRepositories()));
        }

        return (new VersionSelector($pool))->findBestCandidate(
            $packageName,
            $constraint,
            $phpVersion,
            $preferredStability
        );
    }

    /**
     * Helper function to run VersionSelector::findBestCandidate() on Composer version 2.x.x
     *
     * @param string $packageName
     * @param string $edition
     * @param string $constraint
     * @param string $minStability
     * @param array $stabilityFlags
     * @param string $preferredStability
     * @param bool $ignorePlatformReqs
     * @return PackageInterface|false
     */
    private function findBestCandidateComposer2(
        string $packageName,
        string $edition,
        string $constraint,
        string $minStability,
        array $stabilityFlags,
        string $preferredStability,
        bool $ignorePlatformReqs
    ) {
        $platformOverrides = $this->composer->getConfig()->get('platform') ?: array();
        $platformRepo = new PlatformRepository(array(), $platformOverrides);
        $repositorySet = new RepositorySet($minStability, $stabilityFlags);

        if ($edition == PackageUtils::CLOUD_PKG_EDITION) {
            // magento/magento-cloud-template exists on github, not the composer repo
            $repoConfig = [
                'url' => 'https://github.com/magento/magento-cloud',
                'type' => 'vcs'
            ];
            $repositorySet->addRepository(new VcsRepository(
                $repoConfig,
                $this->console->getIO(),
                $this->composer->getConfig(),
                $this->composer->getLoop()->getHttpDownloader()
            ));
        } else {
            $repositorySet->addRepository(
                new CompositeRepository($this->composer->getRepositoryManager()->getRepositories())
            );
        }

        return (new VersionSelector($repositorySet, $platformRepo))->findBestCandidate(
            $packageName,
            $constraint,
            $preferredStability,
            $ignorePlatformReqs
        );
    }

    /**
     * Gets the original magento/product metapackage edition and version from the package in composer.lock
     *
     * @param string|null $overrideEdition
     * @param string|null $overrideVersion
     * @return void
     */
    protected function parseVersionAndEditionFromLock(?string $overrideEdition = null, ?string $overrideVersion = null)
    {
        $lockedProduct = $this->pkgUtils->getLockedProduct();
        if ($lockedProduct) {
            if ($overrideEdition) {
                $this->origEdition = $overrideEdition;
            } else {
                $this->origEdition = $this->pkgUtils->getMetapackageEdition($lockedProduct->getName());
            }

            if ($overrideVersion) {
                $this->origVersion = $overrideVersion;
                $this->prettyOrigVersion = $overrideVersion;
            } else {
                $this->origVersion = $lockedProduct->getVersion();
                $this->prettyOrigVersion = $lockedProduct->getPrettyVersion();
                if (!$this->prettyOrigVersion) {
                    $this->prettyOrigVersion = $this->origVersion;
                }
            }
        }
    }

    /**
     * Get the pretty label for the target installation version
     *
     * @return string
     */
    public function getTargetLabel(): string
    {
        $editionLabel = $this->pkgUtils->getEditionLabel($this->targetEdition);
        if ($editionLabel && $this->prettyTargetVersion) {
            return "$editionLabel " . $this->prettyTargetVersion;
        } elseif ($editionLabel && $this->targetConstraint) {
            return "$editionLabel " . $this->targetConstraint;
        }
        return self::MISSING_ROOT_LABEL;
    }

    /**
     * Get the pretty label for the original project installation version
     *
     * @return string
     */
    public function getOriginalLabel(): string
    {
        $editionLabel = $this->pkgUtils->getEditionLabel($this->origEdition);
        if ($editionLabel && $this->prettyOrigVersion) {
            return "$editionLabel " . $this->prettyOrigVersion;
        }
        return self::MISSING_ROOT_LABEL;
    }

    /**
     * @return string
     */
    public function getOriginalEdition(): string
    {
        return $this->origEdition;
    }

    /**
     * @return string
     */
    public function getOriginalVersion(): string
    {
        return $this->origVersion;
    }

    /**
     * @return string|null
     */
    public function getPrettyOriginalVersion(): ?string
    {
        return $this->prettyOrigVersion;
    }

    /**
     * @return string
     */
    public function getTargetEdition(): string
    {
        return $this->targetEdition;
    }

    /**
     * @return string
     */
    public function getTargetVersion(): string
    {
        return $this->targetVersion;
    }
}
