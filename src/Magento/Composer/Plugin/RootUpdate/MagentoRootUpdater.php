<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Composer\Plugin\RootUpdate;

use Composer\Composer;
use Composer\DependencyResolver\Pool;
use Composer\Downloader\FilesystemException;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Class MagentoRootUpdater
 *
 * @package Magento\Composer\Plugin\RootUpdate
 */
class MagentoRootUpdater
{
    /**
     * @var IOInterface $io
     */
    private $io;

    /**
     * @var Composer $composer
     */
    private $composer;

    /**
     * @var array $jsonChanges Json-writable sections of composer.json that have been updated
     */
    private $jsonChanges;

    /**
     * @var string $targetLabel Pretty label for the target Magento edition version
     */
    private $targetLabel;

    /**
     * @var string $targetProduct The target product package name
     */
    private $targetProduct;

    /**
     * @var string $targetConstraint The target product package version constraint
     */
    private $targetConstraint;

    /**
     * @var boolean $strictConstraint Is the target Magento product constraint strict
     */
    private $strictConstraint;

    /**
     * @var bool $override Has OVERRIDE_OPT been passed to the command
     */
    private $override;

    /**
     * @var bool $interactive Has INTERACTIVE_OPT been passed to the command
     */
    private $interactive;

    /**
     * @var bool $noDev --no-dev option
     */
    private $noDev;

    /**
     * @var bool $noAutoloader --no-autoloader option
     */
    private $noAutoloader;

    /**
     * @var bool $dryRun --dry-run option
     */
    private $dryRun;

    /**
     * @var bool $ignorePlatformReqs --ignore-platform-reqs option
     */
    private $ignorePlatformReqs;

    /**
     * @var bool $fromRoot FROM_PRODUCT_OPT option
     */
    private $fromRoot;

    /**
     * MagentoRootUpdater constructor.
     *
     * @param IOInterface $io
     * @param Composer $composer
     * @param InputInterface $input
     */
    public function __construct($io, $composer, $input)
    {
        $this->io = $io;
        $this->composer = $composer;
        $this->override = $input->getOption(RootUpdateCommand::OVERRIDE_OPT);
        $this->interactive = $input->getOption(RootUpdateCommand::INTERACTIVE_OPT);
        $this->fromRoot = static::formatRequirements($input->getOption(RootUpdateCommand::FROM_PRODUCT_OPT));
        $this->noDev = $input->getOption('no-dev');
        $this->noAutoloader = $input->getOption('no-autoloader');
        $this->dryRun = $input->getOption('dry-run');
        $this->ignorePlatformReqs = $input->getOption('ignore-platform-reqs');
        $this->jsonChanges = [];
        $this->targetLabel = null;
        $this->targetProduct = null;
        $this->targetConstraint = null;
        $this->strictConstraint = true;
    }

    /**
     * Look ahead to the target Magento version and execute any changes to the root composer.json file in-memory
     *
     * @return boolean Returns true if updates were necessary and prepared successfully
     */
    public function runUpdate()
    {
        $composer = $this->composer;
        $io = $this->io;

        $composerPath = $composer->getConfig()->getConfigSource()->getName();
        $locker = null;
        $fromRoot = $this->fromRoot;
        if (empty($fromRoot)) {
            if (preg_match('/\/var\/composer\.json$/', $composerPath)) {
                $parentDir = preg_replace('/\/var\/composer\.json$/', '', $composerPath);
                if (file_exists("$parentDir/composer.json") && file_exists("$parentDir/composer.lock")) {
                    $locker = new Locker(
                        $io,
                        new JsonFile("$parentDir/composer.lock"),
                        $composer->getRepositoryManager(),
                        $composer->getInstallationManager(),
                        file_get_contents("$parentDir/composer.json")
                    );
                }
            }
            $locker = $locker ?? $composer->getLocker();
        }

        if (!empty($fromRoot) || ($locker !== null && $locker->isLocked())) {
            $installRoot = $composer->getPackage();
            $targetRoot = null;
            $targetConstraint = null;
            $requiresPlugin = false;
            foreach ($installRoot->getRequires() as $link) {
                $packageInfo = static::getMagentoPackageInfo($link->getTarget());
                if ($packageInfo !== null) {
                    $targetConstraint = $link->getPrettyConstraint() ??
                        $link->getConstraint()->getPrettyString() ??
                        $link->getConstraint()->__toString();
                    $edition = $packageInfo['edition'];
                    $this->targetProduct = "magento/product-$edition-edition";
                    $this->targetConstraint = $targetConstraint;
                    $targetRoot = $this->fetchRoot(
                        $edition,
                        $targetConstraint,
                        $composer,
                        true
                    );
                }
                $requiresPlugin = $requiresPlugin || ($link->getTarget() == RootUpdatePlugin::PACKAGE_NAME);
            }
            if (!$requiresPlugin) {
                // If the plugin requirement has been removed but we're still trying to run (code still existing in the
                // vendor directory), return without executing.
                return false;
            }

            if ($targetRoot == null || $targetRoot == false) {
                throw new \RuntimeException('Magento root updates cannot run without a valid target package');
            }

            $targetVersion = $targetRoot->getVersion();
            $prettyTargetVersion = $targetRoot->getPrettyVersion() ?? $targetVersion;
            $targetEd = static::getMagentoPackageInfo($targetRoot->getName())['edition'];

            $baseEd = null;
            $baseVersion = null;
            $prettyBaseVersion = null;
            if (empty($fromRoot)) {
                $lockPackages = $locker->getLockedRepository()->getPackages();
                foreach ($lockPackages as $lockedPackage) {
                    $packageInfo = static::getMagentoPackageInfo($lockedPackage->getName());
                    if ($packageInfo !== null && $packageInfo['type'] == 'product') {
                        $baseEd = $packageInfo['edition'];
                        $baseVersion = $lockedPackage->getVersion();
                        $prettyBaseVersion = $lockedPackage->getPrettyVersion() ?? $baseVersion;

                        // Both editions exist for enterprise, so stop at enterprise to not overwrite with community
                        if ($baseEd == 'enterprise') {
                            break;
                        }
                    }
                }
            } else {
                $baseEd = $fromRoot['edition'];
                $baseVersion = $fromRoot['version'];
                $prettyBaseVersion = $fromRoot['version'];
            }

            $baseRoot = null;
            if ($baseEd != null && $baseVersion != null) {
                $baseRoot = $this->fetchRoot(
                    $baseEd,
                    $prettyBaseVersion,
                    $composer
                );
            }

            if ($baseRoot == null || $baseRoot == false) {
                if ($baseEd == null || $baseVersion == null) {
                    $io->writeError(
                        '<warning>No Magento product package was found in the current installation.</warning>'
                    );
                } else {
                    $io->writeError(
                        '<warning>The Magento project package corresponding to the currently installed ' .
                        "\"magento/product-$baseEd-edition: $prettyBaseVersion\" package is unavailable.</warning>"
                    );
                }

                $overrideRoot = $this->override;
                if (!$overrideRoot) {
                    $question = 'Would you like to update the root composer.json file anyway? This will ' .
                        'override changes you may have made to the default installation if the same values ' .
                        "are different in magento/project-$targetEd-edition $prettyTargetVersion";
                    $overrideRoot = $this->getConfirmation($question);
                }
                if ($overrideRoot) {
                    $baseRoot = $installRoot;
                } else {
                    $io->writeError('Skipping Magento composer.json update.');
                    return false;
                }
            } elseif ($baseEd === $targetEd && $baseVersion === $targetVersion) {
                $io->writeError(
                    'The Magento product requirement matched the current installation; no root updates are required',
                    true,
                    IOInterface::VERBOSE
                );
                return false;
            }

            $baseEd = static::getMagentoPackageInfo($baseRoot->getName())['edition'];
            $this->targetLabel = 'Magento ' . ucfirst($targetEd) . " Edition $prettyTargetVersion";
            $baseLabel = 'Magento ' . ucfirst($baseEd) . " Edition $prettyBaseVersion";

            $io->writeError(
                "Base Magento project package version: magento/project-$baseEd-edition $prettyBaseVersion",
                true,
                IOInterface::DEBUG
            );

            $this->updateComposer($baseLabel, $baseRoot, $targetRoot, $installRoot);

            if (!$this->dryRun) {
                // Add composer.json write code at the head of the list of post command script hooks so
                // the file is accurate for any other hooks that may exist in the installation that use it
                $eventDispatcher = $composer->getEventDispatcher();
                $eventDispatcher->addListener(
                    ScriptEvents::POST_UPDATE_CMD,
                    [$this, 'writeUpdatedRoot'],
                    PHP_INT_MAX
                );
            }

            if ($this->jsonChanges !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update the composer object for each relevant section and track json changes
     *
     * @param string $baseLabel
     * @param PackageInterface $baseRoot
     * @param PackageInterface $targetRoot
     * @param PackageInterface $installRoot
     * @return void
     */
    protected function updateComposer($baseLabel, $baseRoot, $targetRoot, $installRoot)
    {
        $composer = $this->composer;

        $resolver = new ConflictResolver(
            $this->io,
            $this->interactive,
            $this->override,
            $this->targetLabel,
            $baseLabel
        );

        $changedRoot = $composer->getPackage();
        $resolver->resolveLinkSection(
            'require',
            $baseRoot->getRequires(),
            $targetRoot->getRequires(),
            $installRoot->getRequires(),
            [$changedRoot, 'setRequires']
        );

        if (!$this->noDev) {
            $resolver->resolveLinkSection(
                'require-dev',
                $baseRoot->getDevRequires(),
                $targetRoot->getDevRequires(),
                $installRoot->getDevRequires(),
                [$changedRoot, 'setDevRequires']
            );
        }

        if (!$this->noAutoloader) {
            $resolver->resolveArraySection(
                'autoload',
                $baseRoot->getAutoload(),
                $targetRoot->getAutoload(),
                $installRoot->getAutoload(),
                [$changedRoot, 'setAutoload']
            );

            if (!$this->noDev) {
                $resolver->resolveArraySection(
                    'autoload-dev',
                    $baseRoot->getDevAutoload(),
                    $targetRoot->getDevAutoload(),
                    $installRoot->getDevAutoload(),
                    [$changedRoot, 'setDevAutoload']
                );
            }
        }

        $resolver->resolveLinkSection(
            'conflict',
            $baseRoot->getConflicts(),
            $targetRoot->getConflicts(),
            $installRoot->getConflicts(),
            [$changedRoot, 'setConflicts']
        );

        $resolver->resolveArraySection(
            'extra',
            $baseRoot->getExtra(),
            $targetRoot->getExtra(),
            $installRoot->getExtra(),
            [$changedRoot, 'setExtra']
        );

        $resolver->resolveLinkSection(
            'provides',
            $baseRoot->getProvides(),
            $targetRoot->getProvides(),
            $installRoot->getProvides(),
            [$changedRoot, 'setProvides']
        );

        $resolver->resolveLinkSection(
            'replaces',
            $baseRoot->getReplaces(),
            $targetRoot->getReplaces(),
            $installRoot->getReplaces(),
            [$changedRoot, 'setReplaces']
        );

        $resolver->resolveArraySection(
            'suggests',
            $baseRoot->getSuggests(),
            $targetRoot->getSuggests(),
            $installRoot->getSuggests(),
            [$changedRoot, 'setSuggests']
        );

        $composer->setPackage($changedRoot);
        $this->composer = $composer;

        if ($resolver->getJsonChanges() !== []) {
            $this->jsonChanges = $resolver->getJsonChanges();
        }
    }

    /**
     * If interactive, ask the given question and return the result, otherwise return the default
     *
     * @param string $question
     * @param bool $default
     * @return bool
     */
    protected function getConfirmation($question, $default = false)
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
     * Write the changed composer.json file
     *
     * Called as a script event on non-dry runs after a successful update before other post-update-cmd scripts
     *
     * @return void
     * @throws FilesystemException if the composer.json read or write failed
     */
    public function writeUpdatedRoot()
    {
        if ($this->jsonChanges === []) {
            return;
        }
        $filePath = $this->composer->getConfig()->getConfigSource()->getName();
        $io = $this->io;
        $json = json_decode(file_get_contents($filePath), true);
        if ($json === null) {
            throw new FilesystemException('Failed to read ' . $filePath);
        }

        foreach ($this->jsonChanges as $section => $newContents) {
            if ($newContents === null || $newContents === []) {
                if (key_exists($section, $json)) {
                    unset($json[$section]);
                }
            } else {
                $json[$section] = $newContents;
            }
        }

        $this->verboseLog('Writing changes to the root composer.json...');

        $retVal = file_put_contents(
            $filePath,
            json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        if ($retVal === false) {
            throw new FilesystemException('Failed to write updated Magento root values to ' . $filePath);
        }
        $io->writeError('<info>' . $filePath . ' has been updated</info>');
    }

    /**
     * Label and log the given message if output is set to verbose
     *
     * @param string $message
     * @return void
     */
    protected function verboseLog($message)
    {
        $label = $this->targetLabel;
        $this->io->writeError(" <comment>[</comment>$label<comment>]</comment> $message", true, IOInterface::VERBOSE);
    }

    /**
     * Helper function to extract the edition and package type if it is a Magento package name
     *
     * @param string $packageName
     * @return array|null
     */
    public static function getMagentoPackageInfo($packageName)
    {
        $regex = '/^magento\/(?<type>product|project)-(?<edition>community|enterprise)-edition$/';
        if (preg_match($regex, $packageName, $matches)) {
            return $matches;
        } else {
            return null;
        }
    }

    /**
     * Retrieve the Magento root package for an edition and version constraint from the composer file's repositories
     *
     * @param string $edition
     * @param string $constraint
     * @param Composer $composer
     * @param boolean $isTarget
     * @return \Composer\Package\PackageInterface|bool Best root package candidate or false if no valid packages found
     */
    protected function fetchRoot($edition, $constraint, $composer, $isTarget = false)
    {
        $rootName = strtolower("magento/project-$edition-edition");
        $phpVersion = null;
        $prettyPhpVersion = null;
        $versionParser = new VersionParser();
        $parsedConstraint = $versionParser->parseConstraints($constraint);

        $minimumStability = $composer->getPackage()->getMinimumStability() ?? 'stable';
        $stabilityFlags = $this->extractStabilityFlags($rootName, $constraint, $minimumStability);
        $stability = key_exists($rootName, $stabilityFlags)
            ? array_search($stabilityFlags[$rootName], BasePackage::$stabilities)
            : $minimumStability;
        $this->io->writeError(
            "<comment>Minimum stability for \"$rootName: $constraint\": $stability</comment>",
            true,
            IOInterface::DEBUG
        );
        $pool = new Pool(
            $stability,
            $stabilityFlags,
            [$rootName => $parsedConstraint]
        );
        $repos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
        $pool->addRepository($repos);

        if ($isTarget) {
            if (strpbrk($parsedConstraint->__toString(), '[]|<>!') !== false) {
                $this->strictConstraint = false;
                $this->io->writeError(
                    "<warning>The version constraint \"magento/product-$edition-edition: $constraint\" is not exact; " .
                    'the Magento root updater might not accurately determine the version to use according to other ' .
                    'requirements in this installation. It is recommended to use an exact version number.</warning>'
                );
            }
            if (!$this->ignorePlatformReqs) {
                $platformOverrides = $composer->getConfig()->get('platform') ?: [];
                $platform = new PlatformRepository([], $platformOverrides);
                $phpPackage = $platform->findPackage('php', '*');
                if ($phpPackage != null) {
                    $phpVersion = $phpPackage->getVersion();
                    $prettyPhpVersion = $phpPackage->getPrettyVersion();
                }
            }
        }

        $versionSelector = new VersionSelector($pool);
        $result = $versionSelector->findBestCandidate($rootName, $constraint, $phpVersion);

        if ($result == false) {
            $err = "Could not find a Magento project package matching \"magento/product-$edition-edition $constraint\"";
            if ($phpVersion) {
                $err = "$err for PHP version $prettyPhpVersion";
            }
            $this->io->writeError("<error>$err</error>", true, IOInterface::QUIET);
        }

        return $result;
    }

    /**
     * Helper method to construct stability flags needed to fetch new root packages
     *
     * @see RootPackageLoader::extractStabilityFlags()
     *
     * @param string $reqName
     * @param string $reqVersion
     * @param string $minimumStability
     * @return array
     */
    protected function extractStabilityFlags($reqName, $reqVersion, $minimumStability)
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

    /**
     * Parse inputs to the FROM_PRODUCT_OPT option
     *
     * @param string[] $requirements
     * @return array[]
     */
    protected static function formatRequirements($requirements)
    {
        if (empty($requirements)) {
            return null;
        }
        $parser = new VersionParser();
        $requirements = $parser->parseNameVersionPairs($requirements);
        $opt = '--' . RootUpdateCommand::FROM_PRODUCT_OPT;
        if (count($requirements) !== 1) {
            throw new InvalidOptionException("'$opt' accepts only one package requirement");
        } elseif (count($requirements[0]) !== 2) {
            throw new InvalidOptionException("'$opt' requires both a package and version");
        }
        $req = $requirements[0];
        $name = $req['name'];
        $packageInfo = static::getMagentoPackageInfo($name);
        if ($packageInfo == null || $packageInfo['type'] !== 'product') {
            throw new InvalidOptionException("'$opt' accepts only Magento product packages; \"$name\" given");
        }

        return ['edition' => $packageInfo['edition'], 'version' => $req['version']];
    }

    /**
     * Get the Composer object
     *
     * @return Composer
     */
    public function getComposer()
    {
        return $this->composer;
    }

    /**
     * Was a strict constraint used for the target product requirement
     *
     * @return bool
     */
    public function isStrictConstraint()
    {
        return $this->strictConstraint;
    }

    /**
     * Get the constraint used for the target product requirement
     *
     * @return string
     */
    public function getTargetConstraint()
    {
        return $this->targetConstraint;
    }

    /**
     * Get the package name for the target product requirement
     *
     * @return string
     */
    public function getTargetProduct()
    {
        return $this->targetProduct;
    }

    /**
     * Get the json array representation of the root composer updates
     *
     * @return array
     */
    public function getJsonChanges()
    {
        return $this->jsonChanges;
    }
}
