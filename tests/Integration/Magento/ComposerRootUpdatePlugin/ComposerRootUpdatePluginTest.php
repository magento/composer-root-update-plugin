<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin;

use Composer\Downloader\FilesystemException;
use Magento\ComposerRootUpdatePlugin\Plugin\Commands\UpdatePluginNamespaceCommands;

class ComposerRootUpdatePluginTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var string $workingDir
     */
    private static $workingDir;

    /**
     * @var string $expectedDir
     */
    private static $expectedDir;

    /**
     * @var string $composerCommand
     */
    private static $composerCommand;

    /**
     * @var string $pluginPath
     */
    private static $pluginPath;

    /**
     * @var string $testRepoPath
     */
    private static $testRepoPath;

    /**
     * @var string $testComposerJsonSource
     */
    private static $testComposerJsonSource;

    public function testSetupWizardVarInstall()
    {
        $this->assertFileExists(static::$workingDir . '/var/vendor/magento/composer-root-update-plugin/composer.json');
    }

    public function testSetupWizardInstallCommand()
    {
        static::deletePath(static::$workingDir . '/var/vendor');
        $this->assertFileNotExists(static::$workingDir . '/var/vendor/magento/composer-root-update-plugin/composer.json');

        static::execComposer(UpdatePluginNamespaceCommands::NAME . ' install');

        $this->assertFileExists(static::$workingDir . '/var/vendor/magento/composer-root-update-plugin/composer.json');
    }

    public function testUpdateNoOverride()
    {
        $expectedDir = static::$expectedDir;
        static::configureComposerJson(__DIR__ . '/_files/expected_no_override.composer.json', $expectedDir);

        static::execComposer('require magento/product-community-edition=1000.1000.1000 --no-update');

        $this->assertJsonFileEqualsJsonFile("$expectedDir/composer.json", static::$workingDir . '/composer.json');
    }

    public function testUpdateWithOverride()
    {
        $expectedDir = static::$expectedDir;
        static::configureComposerJson(__DIR__ . '/_files/expected_override.composer.json', $expectedDir);

        static::execComposer(
            'require magento/product-community-edition=1000.1000.1000 --no-update --use-magento-values'
        );

        $this->assertJsonFileEqualsJsonFile("$expectedDir/composer.json", static::$workingDir . '/composer.json');
    }

    /**
     * Set file location variables and create the temporary working directory
     *
     * @throws FilesystemException
     */
    public static function setUpBeforeClass()
    {
        $projectRoot = explode('/tests/', __DIR__);
        array_pop($projectRoot);
        // Just in case the file path contains another 'tests' directory upstream
        $projectRoot = implode('/tests/', $projectRoot);

        static::$workingDir = __DIR__ . '/tmp';
        static::$expectedDir = static::$workingDir . '/expected';
        static::$composerCommand = "$projectRoot/vendor/bin/composer";
        static::$pluginPath = "$projectRoot/src/Magento/ComposerRootUpdatePlugin/";
        static::$testRepoPath = __DIR__ . '/_files/test_repository/*/';
        static::$testComposerJsonSource = __DIR__ . '/_files/test.composer.json';

        static::deletePath(static::$workingDir);
        mkdir(static::$workingDir);
    }

    /**
     * Reset the composer.json and composer.lock files before each test but leave vendor to not add reinstall delays
     *
     * @return void
     * @throws FilesystemException
     */
    protected function setUp()
    {
        chdir(static::$workingDir);
        static::deletePath(static::$workingDir . '/composer.json');
        static::deletePath(static::$workingDir . '/composer.lock');
        static::deletePath(static::$expectedDir);

        static::configureComposerJson(static::$testComposerJsonSource, static::$workingDir);
        static::execComposer('create-project');
    }

    /**
     * Clear the temporary working directory after all tests have finished
     *
     * @return void
     * @throws FilesystemException
     */
    public static function tearDownAfterClass()
    {
        static::deletePath(static::$workingDir);
    }

    /**
     * Recursively deletes a file or directory and all its contents, safely handling symlinks
     *
     * @param string $path
     * @return void
     * @throws FilesystemException
     */
    private static function deletePath($path)
    {
        if (!$path || !file_exists($path)) {
            return;
        }

        if (!is_link($path) && is_dir($path)) {
            $files = array_diff(scandir($path), ['..', '.']);
            foreach ($files as $file) {
                static::deletePath("$path/$file");
            }
            rmdir($path);
        } else {
            unlink($path);
        }

        if (file_exists($path)) {
            throw new FilesystemException("Failed to delete $path");
        }
    }

    /**
     * Configure repositories in the composer.json file for the plugin source and test package repos
     *
     * @param string $sourcePath
     * @param string $targetDir
     * @return void
     */
    private static function configureComposerJson($sourcePath, $targetDir)
    {
        if (!file_exists($targetDir)) {
            mkdir($targetDir);
        }
        copy($sourcePath, "$targetDir/composer.json");
        static::execComposer(
            'config repositories.plugin \'{"type": "path", "url": "' . static::$pluginPath . '"}\'',
            $targetDir
        );
        static::execComposer(
            'config repositories.test \'{"type": "path", "url": "' . static::$testRepoPath . '"}\'',
            $targetDir
        );
    }

    /**
     * Wrapper to run exec() on the given composer command, treating non-zero return codes as runtime exceptions
     *
     * If a $dir is supplied, the command will be run in the supplied directory then cwd will be reset to where it was
     *
     * @param string $command
     * @param string $dir
     * @return string
     */
    private static function execComposer($command, $dir = null)
    {
        $cwd = getcwd();
        if ($dir) {
            chdir($dir);
        }

        $fullCommand = static::$composerCommand . " $command";
        $retVal = exec($fullCommand, $output, $errorCode);
        if ($dir) {
            chdir($cwd);
        }

        if ($errorCode !== 0) {
            $output = is_array($output) ? implode(PHP_EOL, $output) : $output;
            throw new \RuntimeException(
                "Composer command '$fullCommand' failed with error code $errorCode\n$output",
                $errorCode
            );
        }

        return $retVal;
    }
}
