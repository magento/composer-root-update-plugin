<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Composer\Plugin\RootUpdate;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\BaseIO;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\ScriptEvents;
use Composer\Semver\Constraint\Constraint;
use Magento\TestHelper\UpdatePluginTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Class MagentoRootUpdaterTest
 *
 * @package Magento\Composer\Plugin\RootUpdate
 */
class MagentoRootUpdaterTest extends UpdatePluginTestCase
{
    /** @var MockObject|Composer */
    public $composer;

    /** @var RootPackage */
    public $installRoot;

    /** @var RootPackage */
    public $expectedNoOverride;

    /** @var RootPackage */
    public $expectedWithOverride;

    /** @var MockObject|EventDispatcher */
    public $eventDispatcher;

    /** @var MockObject|InputInterface */
    public $input;

    /** @var MockObject|BaseIO */
    public $io;

    public function testMagentoUpdateNotMagentoRoot()
    {
        $links = [
            new Link('root', 'vndr/package', new Constraint('==', '1.0.0.0')),
            new Link('root', RootUpdatePlugin::PACKAGE_NAME, new Constraint('==', '1.0.0.0'))
        ];
        $this->installRoot->setRequires($links);

        $this->composer->expects($this->never())->method('setPackage');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Magento root updates cannot run without a valid target package');
        $updater = new MagentoRootUpdater($this->io, $this->composer, $this->input);

        $updater->runUpdate();
    }

    public function testMagentoUpdateRegistersPostUpdateWrites()
    {
        $updater = new MagentoRootUpdater($this->io, $this->composer, $this->input);

        $this->eventDispatcher->expects($this->once())->method('addListener')->with(
            ScriptEvents::POST_UPDATE_CMD,
            [$updater, 'writeUpdatedRoot'],
            PHP_INT_MAX
        );

        $updater->runUpdate();
    }

    public function testMagentoUpdateDryRun()
    {
        $this->input->method('getOption')->willReturnMap([['dry-run', true]]);
        $updater = new MagentoRootUpdater($this->io, $this->composer, $this->input);

        $this->eventDispatcher->expects($this->never())->method('addListener');

        $updater->runUpdate();

        $this->assertNotEmpty($updater->getJsonChanges());
    }

    public function testMagentoUpdateSetsFieldsNoOverride()
    {
        /** @var RootPackage $newRoot */
        $this->composer->expects($this->once())->method('setPackage')->with($this->captureArg($newRoot));
        $updater = new MagentoRootUpdater($this->io, $this->composer, $this->input);
        $updater->runUpdate();

        $this->assertLinksEqual($this->expectedNoOverride->getRequires(), $newRoot->getRequires());
        $this->assertLinksEqual($this->expectedNoOverride->getDevRequires(), $newRoot->getDevRequires());
        $this->assertEquals($this->expectedNoOverride->getAutoload(), $newRoot->getAutoload());
        $this->assertEquals($this->expectedNoOverride->getDevAutoload(), $newRoot->getDevAutoload());
        $this->assertLinksEqual($this->expectedNoOverride->getConflicts(), $newRoot->getConflicts());
        $this->assertEquals($this->expectedNoOverride->getExtra(), $newRoot->getExtra());
        $this->assertLinksEqual($this->expectedNoOverride->getProvides(), $newRoot->getProvides());
        $this->assertLinksEqual($this->expectedNoOverride->getReplaces(), $newRoot->getReplaces());
        $this->assertEquals($this->expectedNoOverride->getSuggests(), $newRoot->getSuggests());
    }

    public function testMagentoUpdateSetsFieldsWithOverride()
    {
        $this->input->method('getOption')->willReturnMap([[RootUpdateCommand::OVERRIDE_OPT, true]]);
        $updater = new MagentoRootUpdater($this->io, $this->composer, $this->input);

        /** @var RootPackage $newRoot */
        $this->composer->expects($this->once())->method('setPackage')->with($this->captureArg($newRoot));

        $updater->runUpdate();

        $this->assertLinksEqual($this->expectedWithOverride->getRequires(), $newRoot->getRequires());
        $this->assertLinksEqual($this->expectedWithOverride->getDevRequires(), $newRoot->getDevRequires());
        $this->assertEquals($this->expectedWithOverride->getAutoload(), $newRoot->getAutoload());
        $this->assertEquals($this->expectedWithOverride->getDevAutoload(), $newRoot->getDevAutoload());
        $this->assertLinksEqual($this->expectedWithOverride->getConflicts(), $newRoot->getConflicts());
        $this->assertEquals($this->expectedWithOverride->getExtra(), $newRoot->getExtra());
        $this->assertLinksEqual($this->expectedWithOverride->getProvides(), $newRoot->getProvides());
        $this->assertLinksEqual($this->expectedWithOverride->getReplaces(), $newRoot->getReplaces());
        $this->assertEquals($this->expectedWithOverride->getSuggests(), $newRoot->getSuggests());
    }

    public function testMagentoUpdateNoDev()
    {
        $this->input->method('getOption')->willReturnMap([['no-dev', true]]);
        $updater = new MagentoRootUpdater($this->io, $this->composer, $this->input);

        /** @var RootPackage $newRoot */
        $this->composer->expects($this->once())->method('setPackage')->with($this->captureArg($newRoot));

        $updater->runUpdate();

        $this->assertLinksEqual($this->expectedNoOverride->getRequires(), $newRoot->getRequires());
        $this->assertEquals($this->expectedNoOverride->getAutoload(), $newRoot->getAutoload());
        $this->assertLinksEqual($this->expectedNoOverride->getConflicts(), $newRoot->getConflicts());
        $this->assertEquals($this->expectedNoOverride->getExtra(), $newRoot->getExtra());
        $this->assertLinksEqual($this->expectedNoOverride->getProvides(), $newRoot->getProvides());
        $this->assertLinksEqual($this->expectedNoOverride->getReplaces(), $newRoot->getReplaces());
        $this->assertEquals($this->expectedNoOverride->getSuggests(), $newRoot->getSuggests());

        $this->assertLinksNotEqual($this->expectedNoOverride->getDevRequires(), $newRoot->getDevRequires());
        $this->assertNotEquals($this->expectedNoOverride->getDevAutoload(), $newRoot->getDevAutoload());
    }

    public function testMagentoUpdateNoAutoloader()
    {
        $this->input->method('getOption')->willReturnMap([['no-autoloader', true]]);
        $updater = new MagentoRootUpdater($this->io, $this->composer, $this->input);

        /** @var RootPackage $newRoot */
        $this->composer->expects($this->once())->method('setPackage')->with($this->captureArg($newRoot));

        $updater->runUpdate();

        $this->assertLinksEqual($this->expectedNoOverride->getRequires(), $newRoot->getRequires());
        $this->assertLinksEqual($this->expectedNoOverride->getDevRequires(), $newRoot->getDevRequires());
        $this->assertLinksEqual($this->expectedNoOverride->getConflicts(), $newRoot->getConflicts());
        $this->assertEquals($this->expectedNoOverride->getExtra(), $newRoot->getExtra());
        $this->assertLinksEqual($this->expectedNoOverride->getProvides(), $newRoot->getProvides());
        $this->assertLinksEqual($this->expectedNoOverride->getReplaces(), $newRoot->getReplaces());
        $this->assertEquals($this->expectedNoOverride->getSuggests(), $newRoot->getSuggests());

        $this->assertNotEquals($this->expectedNoOverride->getAutoload(), $newRoot->getAutoload());
        $this->assertNotEquals($this->expectedNoOverride->getDevAutoload(), $newRoot->getDevAutoload());
    }

    public function setUp()
    {
        /**
         * Set up input RootPackage objects for runUpdate()
         */
        $baseRoot = new RootPackage('magento/project-community-edition', '1.0.0.0', '1.0.0');
        $baseRoot->setRequires([
            new Link('root/pkg', 'magento/product-community-edition', new Constraint('==', '1.0.0'), null, '1.0.0'),
            new Link('root/pkg', RootUpdatePlugin::PACKAGE_NAME, new Constraint('==', '1.0.0.0')),
            new Link('root/pkg', 'vendor/package1', new Constraint('==', '1.0.0'), null, '1.0.0')
        ]);
        $baseRoot->setDevRequires($this->createLinks(2, 'vendor/dev-package'));
        $baseRoot->setAutoload(['psr-4' => ['Magento\\' => 'src/Magento/']]);
        $baseRoot->setDevAutoload(['psr-4' => ['Magento\\Tools\\' => 'dev/tools/Magento/Tools/']]);
        $baseRoot->setConflicts($this->createLinks(2, 'vendor/conflicting'));
        $baseRoot->setExtra(['extra-key1' => 'base1', 'extra-key2' => 'base2']);
        $baseRoot->setProvides($this->createLinks(3, 'magento/sub-package'));
        $baseRoot->setReplaces([]);
        $baseRoot->setSuggests(['magento/sample-data' => 'Suggested Sample Data 1.0.0']);

        $targetRoot = new RootPackage('magento/project-community-edition', '2.0.0.0', '2.0.0');
        $targetRoot->setRequires([
            new Link('root/pkg', 'magento/product-community-edition', new Constraint('==', '2.0.0'), null, '2.0.0'),
            new Link('root/pkg', RootUpdatePlugin::PACKAGE_NAME, new Constraint('==', '1.0.0.0')),
            new Link('root/pkg', 'vendor/package1', new Constraint('==', '2.0.0'), null, '2.0.0')
        ]);
        $targetRoot->setDevRequires($this->createLinks(1, 'vendor/dev-package'));
        $targetRoot->setAutoload(['psr-4' => [
            'Magento\\' => 'src/Magento/',
            'Zend\\Mvc\\Controller\\'=> 'setup/src/Zend/Mvc/Controller/'
        ]]);
        $targetRoot->setDevAutoload(['psr-4' => ['Magento\\Sniffs\\' => 'dev/tests/framework/Magento/Sniffs/']]);
        $targetRoot->setConflicts($this->changeLink($this->createLinks(3, 'vendor/conflicting'), 1));
        $targetRoot->setExtra(['extra-key1' => 'target1', 'extra-key2' => 'target2', 'extra-key3' => ['a' => 'b']]);
        $targetRoot->setProvides($this->changeLink($this->createLinks(3, 'magento/sub-package'), 1));
        $targetRoot->setReplaces($this->createLinks(3, 'replaced/package'));
        $targetRoot->setSuggests([]);

        $installRoot = new RootPackage('magento/project-community-edition', '1.0.0.0', '1.0.0');
        $installRoot->setRequires([
            new Link('root/pkg', 'magento/product-community-edition', new Constraint('==', '2.0.0'), null, '2.0.0'),
            new Link('root/pkg', RootUpdatePlugin::PACKAGE_NAME, new Constraint('==', '1.0.0.0')),
            new Link('root/pkg', 'vendor/package1', new Constraint('==', '1.0.0'), null, '1.0.0')
        ]);
        $installRoot->setDevRequires($baseRoot->getDevRequires());
        $installRoot->setAutoload(array_merge($baseRoot->getAutoload(), ['files' => 'app/etc/Register.php']));
        $installRoot->setDevAutoload(['psr-4' => ['Magento\\Tools\\' => 'dev/tools/Magento/Tools2/']]);
        $installRoot->setConflicts(array_merge(
            array_slice($this->changeLink($baseRoot->getConflicts(), 0), 0, 1),
            $this->createLinks(3, 'vendor/different-conflicting')
        ));
        $installRoot->setExtra(['extra-key1' => 'install1', 'extra-key2' => 'base2']);
        $installRoot->setProvides($baseRoot->getProvides());
        $installRoot->setReplaces($baseRoot->getReplaces());
        $installRoot->setSuggests([
            'magento/sample-data' => 'Suggested Sample Data 1.0.0',
            'vendor/suggested' => 'Another Suggested Package'
        ]);
        $this->installRoot = $installRoot;

        /**
         * Set up expected results from runUpdate() with and without overriding conflicting install values
         */
        $expectedNoOverride = new RootPackage('magento/project-community-edition', '1.0.0.0', '1.0.0');
        $expectedNoOverride->setRequires($targetRoot->getRequires());
        $expectedNoOverride->setDevRequires($targetRoot->getDevRequires());
        $expectedNoOverride->setAutoload(
            array_merge($targetRoot->getAutoload(), ['files' => 'app/etc/Register.php'])
        );
        $expectedNoOverride->setDevAutoload(['psr-4' => [
            'Magento\\Sniffs\\' => 'dev/tests/framework/Magento/Sniffs/',
            'Magento\\Tools\\' => 'dev/tools/Magento/Tools2/'
        ]]);
        $expectedNoOverride->setConflicts(
            array_merge($this->installRoot->getConflicts(), [$targetRoot->getConflicts()[2]])
        );
        $noOverrideExtra = $targetRoot->getExtra();
        $noOverrideExtra['extra-key1'] = $this->installRoot->getExtra()['extra-key1'];
        $expectedNoOverride->setExtra($noOverrideExtra);
        $expectedNoOverride->setProvides($targetRoot->getProvides());
        $expectedNoOverride->setReplaces($targetRoot->getReplaces());
        $expectedNoOverride->setSuggests(['vendor/suggested' => 'Another Suggested Package']);
        $this->expectedNoOverride = $expectedNoOverride;

        $expectedWithOverride = new RootPackage('magento/project-community-edition', '1.0.0.0', '1.0.0');
        $expectedWithOverride->setRequires($expectedNoOverride->getRequires());
        $expectedWithOverride->setDevRequires($expectedNoOverride->getDevRequires());
        $expectedWithOverride->setAutoload($expectedNoOverride->getAutoload());
        $expectedWithOverride->setDevAutoload([
            'psr-4' => ['Magento\\Sniffs\\' => 'dev/tests/framework/Magento/Sniffs/']
        ]);
        $expectedWithOverride->setConflicts(array_merge(
            $this->installRoot->getConflicts(),
            array_slice($targetRoot->getConflicts(), 1)
        ));
        $expectedWithOverride->setExtra($targetRoot->getExtra());
        $expectedWithOverride->setProvides($expectedNoOverride->getProvides());
        $expectedWithOverride->setReplaces($expectedNoOverride->getReplaces());
        $expectedWithOverride->setSuggests($expectedNoOverride->getSuggests());
        $this->expectedWithOverride = $expectedWithOverride;

        /**
         * Mock plugin boilerplate
         */
        $this->eventDispatcher = $this->createPartialMock(EventDispatcher::class, ['addListener']);

        /**
         * Mock InputInterface for CLI options and IOInterface for interaction
         */
        /** @var InputInterface|MockObject $input */
        $input = $this->getMockForAbstractClass(InputInterface::class);
        $input->method('isInteractive')->willReturn(false);
        $this->input = $input;
        $this->io = $this->getMockForAbstractClass(IOInterface::class);

        /**
         * Mock package repositories
         */
        $repo = $this->createPartialMock(ComposerRepository::class, ['hasProviders', 'whatProvides']);
        $repo->method('hasProviders')->willReturn(true);
        $repo->method('whatProvides')->willReturn([$targetRoot, $baseRoot]);
        $repoManager = $this->createPartialMock(RepositoryManager::class, ['getRepositories']);
        $repoManager->method('getRepositories')->willReturn([$repo]);
        $lockedRepo = $this->getMockForAbstractClass(RepositoryInterface::class);
        $lockedRepo->method('getPackages')->willReturn([
            new Package('magento/product-community-edition', '1.0.0.0', '1.0.0')
        ]);
        $locker = $this->createPartialMock(Locker::class, ['isLocked', 'getLockedRepository']);
        $locker->method('isLocked')->willReturn(true);
        $locker->method('getLockedRepository')->willReturn($lockedRepo);

        /**
         * Mock local Composer object
         */
        $configSource = $this->getMockForAbstractClass(Config\ConfigSourceInterface::class);
        $config = $this->createPartialMock(Config::class, ['get', 'getConfigSource']);
        $config->method('get')->with('platform')->willReturn([]);
        $config->method('getConfigSource')->willReturn($configSource);
        /** @var Composer|MockObject $composer */
        $composer = $this->createPartialMock(Composer::class, [
            'getLocker',
            'getPackage',
            'getRepositoryManager',
            'getEventDispatcher',
            'getConfig',
            'setPackage'
        ]);
        $composer->method('getLocker')->willReturn($locker);
        $composer->method('getEventDispatcher')->willReturn($this->eventDispatcher);
        $composer->method('getRepositoryManager')->willReturn($repoManager);
        $composer->method('getPackage')->willReturn($installRoot);
        $composer->method('getConfig')->willReturn($config);
        $this->composer = $composer;
    }
}
