<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

// @codingStandardsIgnoreFile

namespace Magento\ComposerRootUpdatePlugin\ComposerReimplementation;

use Composer\Command\InitCommand;
use Composer\Command\RequireCommand;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Semver\VersionParser;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Exception;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Necessary functionality from Composer's native RequireCommand class split out of the larger original methods
 *
 * Functions here may need to be updated to match future versions of Composer
 *
 * @see RequireCommand
 */
abstract class ExtendableRequireCommand extends RequireCommand
{
    /**
     * @var string $fileName
     */
    protected $fileName;

    /**
     * @var JsonFile $jsonFile
     */
    protected $jsonFile;

    /**
     * @var bool $pluginNewlyCreated
     */
    protected $pluginNewlyCreated;

    /**
     * @var bool|string $pluginComposerBackup
     */
    protected $pluginComposerBackup;

    /**
     * @var string $preferredStability
     */
    protected $preferredStability;

    /**
     * @var string $phpVersion
     */
    protected $phpVersion;

    /**
     * @inheritdoc
     */
    public function __construct(string $name = null)
    {
        parent::__construct($name);
        $this->fileName = null;
        $this->jsonFile = null;
        $this->pluginNewlyCreated = null;
        $this->pluginComposerBackup = null;
        $this->preferredStability = null;
        $this->phpVersion = null;
    }

    /**
     * Validate composer.json file permissions and extract necessary info before new requirements are determined
     *
     * Copied first half of RequireCommand::execute(), which should run before the plugin's update operation
     *
     * @see RequireCommand::execute()
     *
     * @param InputInterface $input
     * @return int|array
     */
    protected function parseComposerJsonFile(InputInterface $input)
    {
        $file = Factory::getComposerFile();
        $io = $this->getIO();

        $newlyCreated = !file_exists($file);
        if ($newlyCreated && !file_put_contents($file, "{\n}\n")) {
            $io->writeError('<error>'.$file.' could not be created.</error>');

            return 1;
        }
        if (!is_readable($file)) {
            $io->writeError('<error>'.$file.' is not readable.</error>');

            return 1;
        }
        if (!is_writable($file)) {
            $io->writeError('<error>'.$file.' is not writable.</error>');

            return 1;
        }

        if (filesize($file) === 0) {
            file_put_contents($file, "{\n}\n");
        }

        $json = new JsonFile($file);
        $composerBackup = file_get_contents($json->getPath());

        $composer = $this->getComposer(true, $input->getOption('no-plugins'));
        $repos = $composer->getRepositoryManager()->getRepositories();

        $platformOverrides = $composer->getConfig()->get('platform') ?: [];
        // initialize $this->repos as it is used by the parent InitCommand
        $this->repos = new CompositeRepository(array_merge(
            [new PlatformRepository([], $platformOverrides)],
            $repos
        ));

        if ($composer->getPackage()->getPreferStable()) {
            $preferredStability = 'stable';
        } else {
            $preferredStability = $composer->getPackage()->getMinimumStability();
        }

        $phpVersion = $this->repos->findPackage('php', '*')->getPrettyVersion();

        $this->fileName = $file;
        $this->jsonFile = $json;
        $this->pluginNewlyCreated = $newlyCreated;
        $this->pluginComposerBackup = $composerBackup;
        $this->preferredStability = $preferredStability;
        $this->phpVersion = $phpVersion;
        return 0;
    }

    /**
     * Interactively ask for the requirement arguments
     *
     * Copied second half of InitCommand::determineRequirements() without calling findBestVersionAndNameForPackage(),
     * which would try to use existing requirements before the plugin can update new project values
     *
     * @see InitCommand::determineRequirements()
     *
     * @return array
     * @throws Exception
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function getRequirementsInteractive(): array
    {
        $versionParser = new VersionParser();
        $io = $this->getIO();
        $requires = [];
        while (null !== $package = $io->ask('Search for a package: ')) {
            $matches = $this->findPackages($package);

            if (count($matches)) {
                $exactMatch = null;
                $choices = [];
                foreach ($matches as $position => $foundPackage) {
                    $abandoned = '';
                    if (isset($foundPackage['abandoned'])) {
                        if (is_string($foundPackage['abandoned'])) {
                            $replacement = sprintf('Use %s instead', $foundPackage['abandoned']);
                        } else {
                            $replacement = 'No replacement was suggested';
                        }
                        $abandoned = sprintf('<warning>Abandoned. %s.</warning>', $replacement);
                    }

                    $choices[] = sprintf(' <info>%5s</info> %s %s', "[$position]", $foundPackage['name'], $abandoned);
                    if ($foundPackage['name'] === $package) {
                        $exactMatch = true;
                        break;
                    }
                }

                // no match, prompt which to pick
                if (!$exactMatch) {
                    $io->writeError([
                        '',
                        sprintf('Found <info>%s</info> packages matching <info>%s</info>', count($matches), $package),
                        '',
                    ]);

                    $io->writeError($choices);
                    $io->writeError('');

                    $validator = function ($selection) use ($matches, $versionParser) {
                        if ('' === $selection) {
                            return false;
                        }

                        if (is_numeric($selection) && isset($matches[(int) $selection])) {
                            $package = $matches[(int) $selection];

                            return $package['name'];
                        }

                        if (preg_match('{^\s*(?P<name>[\S/]+)(?:\s+(?P<version>\S+))?\s*$}', $selection, $pkgMatches)) {
                            if (isset($pkgMatches['version'])) {
                                // parsing `acme/example ~2.3`

                                // validate version constraint
                                $versionParser->parseConstraints($pkgMatches['version']);

                                return $pkgMatches['name'].' '.$pkgMatches['version'];
                            }

                            // parsing `acme/example`
                            return $pkgMatches['name'];
                        }

                        throw new Exception('Not a valid selection');
                    };

                    $package = $io->askAndValidate(
                        'Enter package # to add, or the complete package name if it is not listed: ',
                        $validator,
                        3,
                        false
                    );
                }

                // no constraint yet, determine the best version automatically
                if (false !== $package && false === strpos($package, ' ')) {
                    $validator = function ($input) {
                        $input = trim($input);

                        return $input ?: false;
                    };

                    $constraint = $io->askAndValidate(
                        'Enter the version constraint to require (or leave blank to use the latest version): ',
                        $validator,
                        3,
                        false
                    );

                    if ($constraint !== false) {
                        $package .= ' '.$constraint;
                    }
                }

                if (false !== $package) {
                    $requires[] = $package;
                }
            }
        }

        return $requires;
    }

    /**
     * Reset the composer.json file after an operation failure
     *
     * Copied from RequireCommand::revertComposerFile() in Composer 1.8.0, it needs to be separate to use the plugin's
     * file backup rather than the one that RequireCommand natively picks up, which will contain the plugin's changes
     *
     * @see RequireCommand::revertComposerFile()
     *
     * @param string $message
     * @return void
     */
    protected function revertRootComposerFile(string $message)
    {
        $file = $this->fileName;
        $io = $this->getIO();
        if ($this->pluginNewlyCreated) {
            if (file_exists($this->jsonFile->getPath())) {
                $io->writeError("\n<error>$message, deleting $file.</error>");
                unlink($this->jsonFile->getPath());
            }
        } else {
            $io->writeError("\n<error>$message, " .
                "reverting $file to its original content from before the magento/project root update.</error>");
            file_put_contents($this->jsonFile->getPath(), $this->pluginComposerBackup);
        }
    }
}
