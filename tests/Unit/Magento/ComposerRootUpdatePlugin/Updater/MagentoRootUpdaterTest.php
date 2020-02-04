<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Updater;

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
use Composer\Semver\Constraint\Constraint;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\ComposerRootUpdatePlugin\Plugin\PluginDefinition;
use Magento\ComposerRootUpdatePlugin\UpdatePluginTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;

class MagentoRootUpdaterTest extends UpdatePluginTestCase
{
    /**
     * @var MockObject|Composer
     */
    public $composer;

    /**
     * @var RootPackage
     */
    public $installRoot;

    /**
     * @var RootPackage
     */
    public $expectedNoOverride;

    /**
     * @var RootPackage
     */
    public $expectedWithOverride;

    /**
     * @var MockObject|EventDispatcher
     */
    public $eventDispatcher;

    /**
     * @var MockObject|InputInterface
     */
    public $input;

    /**
     * @var MockObject|BaseIO
     */
    public $io;

    /**
     * @var Console
     */
    public $console;

    /**
     * @var MockObject|RootPackageRetriever
     */
    public $retriever;

    public function testMagentoUpdateSetsFieldsNoOverride()
    {
        $updater = new MagentoRootUpdater($this->console, $this->composer);
        $updater->runUpdate($this->retriever, false, true, '7.0', 'stable');
        $result = $updater->getJsonChanges();

        $this->assertLinksEqual($this->expectedNoOverride->getRequires(), $result['require']);
        $this->assertLinksOrdered($this->expectedNoOverride->getRequires(), $result['require']);
        $this->assertLinksEqual($this->expectedNoOverride->getDevRequires(), $result['require-dev']);
        $this->assertLinksOrdered($this->expectedNoOverride->getDevRequires(), $result['require-dev']);
        $this->assertEquals($this->expectedNoOverride->getAutoload(), $result['autoload']);
        $this->assertEquals($this->expectedNoOverride->getDevAutoload(), $result['autoload-dev']);
        $this->assertLinksEqual($this->expectedNoOverride->getConflicts(), $result['conflict']);
        $this->assertEquals($this->expectedNoOverride->getExtra(), $result['extra']);
        $this->assertLinksEqual($this->expectedNoOverride->getProvides(), $result['provide']);
        $this->assertLinksEqual($this->expectedNoOverride->getReplaces(), $result['replace']);
        $this->assertEquals($this->expectedNoOverride->getSuggests(), $result['suggest']);
    }

    public function testMagentoUpdateSetsFieldsWithOverride()
    {
        $updater = new MagentoRootUpdater($this->console, $this->composer);
        $updater->runUpdate($this->retriever, true, true, '7.0', 'stable');
        $result = $updater->getJsonChanges();

        $this->assertLinksEqual($this->expectedWithOverride->getRequires(), $result['require']);
        $this->assertLinksOrdered($this->expectedWithOverride->getRequires(), $result['require']);
        $this->assertLinksEqual($this->expectedWithOverride->getDevRequires(), $result['require-dev']);
        $this->assertLinksOrdered($this->expectedWithOverride->getDevRequires(), $result['require-dev']);
        $this->assertEquals($this->expectedWithOverride->getAutoload(), $result['autoload']);
        $this->assertEquals($this->expectedWithOverride->getDevAutoload(), $result['autoload-dev']);
        $this->assertLinksEqual($this->expectedWithOverride->getConflicts(), $result['conflict']);
        $this->assertEquals($this->expectedWithOverride->getExtra(), $result['extra']);
        $this->assertLinksEqual($this->expectedWithOverride->getProvides(), $result['provide']);
        $this->assertLinksEqual($this->expectedWithOverride->getReplaces(), $result['replace']);
        $this->assertEquals($this->expectedWithOverride->getSuggests(), $result['suggest']);
    }

    public function setUp()
    {
        /**
         * Setup input RootPackage objects for runUpdate()
         */
        $baseRoot = new RootPackage('magento/project-community-edition', '1.0.0.0', '1.0.0');
        $baseRoot->setRequires([
            'magento/product-community-edition' => new Link(
                'root/pkg', 'magento/product-community-edition',
                new Constraint('==', '1.0.0'), null, '1.0.0'
            ),
            PluginDefinition::PACKAGE_NAME => new Link(
                'root/pkg',
                PluginDefinition::PACKAGE_NAME,
                new Constraint('==', '1.0.0.0')
            ),
            'vendor/package1' => new Link(
                'root/pkg',
                'vendor/package1',
                new Constraint('==', '1.0.0'),
                null,
                '1.0.0'
            )
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
            'magento/product-community-edition' => new Link(
                'root/pkg',
                'magento/product-community-edition',
                new Constraint('==', '2.0.0'),
                null,
                '2.0.0'
            ),
            PluginDefinition::PACKAGE_NAME => new Link(
                'root/pkg',
                PluginDefinition::PACKAGE_NAME,
                new Constraint('==', '1.0.0.0')
            ),
            'vendor/package1' => new Link(
                'root/pkg',
                'vendor/package1',
                new Constraint('==', '2.0.0'),
                null, '2.0.0'
            )
        ]);
        $targetRoot->setDevRequires(array_merge($this->createLinks(1, 'vendor/dev-package-new'), $this->createLinks(2, 'vendor/dev-package')));
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
            'magento/product-community-edition' => new Link(
                'root/pkg',
                'magento/product-community-edition',
                new Constraint('==', '2.0.0'),
                null,
                '2.0.0'
            ),
            PluginDefinition::PACKAGE_NAME => new Link(
                'root/pkg',
                PluginDefinition::PACKAGE_NAME,
                new Constraint('==', '1.0.0.0')
            ),
            'vendor/package1' => new Link(
                'root/pkg',
                'vendor/package1',
                new Constraint('==', '1.0.0'),
                null, '1.0.0'
            )
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
        /** @var Link $newConflict */
        $newConflict = array_values($targetRoot->getConflicts())[2];
        $expectedNoOverride->setConflicts(
            array_merge($this->installRoot->getConflicts(), [$newConflict->getTarget() => $newConflict])
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
         * Mock IOInterface for interaction
         */
        $this->io = $this->getMockForAbstractClass(IOInterface::class);
        $this->console = new Console($this->io);

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

        $retriever = $this->createPartialMock(RootPackageRetriever::class, [
            'getOriginalRootPackage',
            'getOriginalEdition',
            'getOriginalVersion',
            'getOriginalLabel',
            'getTargetRootPackage',
            'getTargetEdition',
            'getTargetVersion',
            'getTargetLabel',
            'getUserRootPackage'
        ]);
        $retriever->method('getOriginalRootPackage')->willReturn($baseRoot);
        $retriever->method('getOriginalEdition')->willReturn('community');
        $retriever->method('getOriginalVersion')->willReturn('1.0.0.0');
        $retriever->method('getOriginalLabel')->willReturn('Magento Open Source 1.0.0');
        $retriever->method('getTargetRootPackage')->willReturn($targetRoot);
        $retriever->method('getTargetEdition')->willReturn('community');
        $retriever->method('getTargetVersion')->willReturn('2.0.0.0');
        $retriever->method('getTargetLabel')->willReturn('Magento Open Source 2.0.0');
        $retriever->method('getUserRootPackage')->willReturn($installRoot);

        $this->retriever = $retriever;
    }
}
