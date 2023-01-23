<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Updater;

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\RemoteFilesystem;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\ComposerRootUpdatePlugin\UpdatePluginTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class RootPackageRetrieverTest extends UpdatePluginTestCase
{
    /**
     * @var MockObject|Composer $composer
     */
    public $composer;

    /**
     * @var MockObject|IOInterface $io
     */
    public $io;

    /**
     * @var Console $console
     */
    public $console;

    /**
     * @var MockObject|PackageInterface $originalRoot
     */
    public $originalRoot;

    /**
     * @var MockObject|PackageInterface $targetRoot
     */
    public $targetRoot;

    /**
     * @var MockObject|PackageInterface $userRoot
     */
    public $userRoot;

    /**
     * @var MockObject|ComposerRepository $repo
     */
    public $repo;

    public function testOverrideOriginalRoot()
    {
        $this->composer->expects($this->never())->method('getLocker');

        $retriever = new RootPackageRetriever(
            $this->console,
            $this->composer,
            'enterprise',
            '2.0.0',
            'community',
            '1.0.0'
        );

        $this->assertEquals('community', $retriever->getOriginalEdition());
        $this->assertEquals('1.0.0', $retriever->getOriginalVersion());
        $this->assertEquals('1.0.0', $retriever->getPrettyOriginalVersion());
    }

    public function testOriginalRootFromLocker()
    {
        $this->composer->expects($this->once())->method('getLocker');

        $retriever = new RootPackageRetriever($this->console, $this->composer, 'enterprise', '2.0.0');

        $this->assertEquals('enterprise', $retriever->getOriginalEdition());
        $this->assertEquals('1.1.0.0', $retriever->getOriginalVersion());
        $this->assertEquals('1.1.0', $retriever->getPrettyOriginalVersion());
    }

    public function testGetOriginalRootFromRepo()
    {
        $this->repo->method('loadPackages')->willReturn(
            [
                'namesFound' => [$this->originalRoot->getName()],
                'packages' => [
                    spl_object_hash($this->originalRoot) => $this->originalRoot
                ]
            ]
        );

        $retriever = new RootPackageRetriever($this->console, $this->composer, 'enterprise', '2.0.0');
        $retrievedOriginal = $retriever->getOriginalRootPackage(false);

        $this->assertEquals($this->originalRoot, $retrievedOriginal);
    }

    public function testGetOriginalRootNotOnRepo_Override()
    {
        $this->repo->method('loadPackages')->willReturn(['namesFound' => [], 'packages'=>[]]);

        $retriever = new RootPackageRetriever($this->console, $this->composer, 'enterprise', '2.0.0');
        $retrievedOriginal = $retriever->getOriginalRootPackage(true);

        $this->assertEquals($this->userRoot, $retrievedOriginal);
    }

    public function testGetOriginalRootNotOnRepo_NoOverride()
    {
        $this->repo->method('loadPackages')->willReturn(['namesFound' => [], 'packages'=>[]]);

        $retriever = new RootPackageRetriever($this->console, $this->composer, 'enterprise', '2.0.0');
        $retrievedOriginal = $retriever->getOriginalRootPackage(false);

        $this->assertEquals(null, $retrievedOriginal);
    }

    public function testGetOriginalRootNotOnRepo_Confirm()
    {
        $this->repo->method('loadPackages')->willReturn(['namesFound' => [], 'packages'=>[]]);
        $this->console->setInteractive(true);
        $this->io->method('isInteractive')->willReturn(true);
        $this->io->method('askConfirmation')->willReturn(true);

        $retriever = new RootPackageRetriever($this->console, $this->composer, 'enterprise', '2.0.0');
        $retrievedOriginal = $retriever->getOriginalRootPackage(false);

        $this->assertEquals($this->userRoot, $retrievedOriginal);
    }

    public function testGetOriginalRootNotOnRepo_NoConfirm()
    {
        $this->repo->method('loadPackages')->willReturn(['namesFound' => [], 'packages'=>[]]);
        $this->console->setInteractive(true);
        $this->io->method('isInteractive')->willReturn(true);
        $this->io->method('askConfirmation')->willReturn(false);

        $retriever = new RootPackageRetriever($this->console, $this->composer, 'enterprise', '2.0.0');
        $retrievedOriginal = $retriever->getOriginalRootPackage(false);

        $this->assertEquals(null, $retrievedOriginal);
    }

    public function testGetTargetRootFromRepo()
    {
        $this->repo->expects($this->atLeast(1))->method('loadPackages')->willReturn(
            [
                'namesFound' => [$this->originalRoot->getName()],
                'packages' => [
                    spl_object_hash($this->targetRoot) => $this->targetRoot
                ]
            ]
        );

        $retriever = new RootPackageRetriever($this->console, $this->composer, 'enterprise', '2.0.0');
        $retrievedTarget = $retriever->getTargetRootPackage();

        $this->assertEquals($this->targetRoot, $retrievedTarget);
    }

    public function testGetTargetRootNotOnRepo()
    {
        $this->repo->method('loadPackages')->willReturn(['namesFound' => [], 'packages'=>[]]);

        $retriever = new RootPackageRetriever($this->console, $this->composer, 'enterprise', '2.0.0');
        $retrievedTarget = $retriever->getTargetRootPackage();

        $this->assertEquals(null, $retrievedTarget);
    }

    public function testGetUserRoot()
    {
        $retriever = new RootPackageRetriever($this->console, $this->composer, 'enterprise', '2.0.0');
        $retrievedTarget = $retriever->getUserRootPackage();

        $this->assertEquals($this->userRoot, $retrievedTarget);
    }

    protected function setUp(): void
    {
        $apiMajorVersion = explode('.', PluginInterface::PLUGIN_API_VERSION)[0];
        $this->io = $this->getMockForAbstractClass(IOInterface::class);
        $this->console = new Console($this->io);

        $this->composer = $this->createPartialMock(Composer::class, [
            'getConfig',
            'getLocker',
            'getPackage',
            'getRepositoryManager',
            'getInstallationManager'
        ]);

        $config = $this->createPartialMock(Config::class, ['getConfigSource']);
        $config->method('getConfigSource')->willReturn(
            $this->getMockForAbstractClass(Config\ConfigSourceInterface::class)
        );
        $this->composer->method('getConfig')->willReturn($config);

        $locker = $this->createPartialMock(Locker::class, [
            'isLocked',
            'getLockedRepository'
        ]);
        $lockedRepo = $this->getMockForAbstractClass(
            LockArrayRepository::class,
            [],
            '',
            true,
            true,
            true,
            ['getPackages'],
            false
        );
        $originalProduct = $this->getMockForAbstractClass(PackageInterface::class);
        $originalProduct->method('getName')->willReturn('magento/product-enterprise-edition');
        $originalProduct->method('getVersion')->willReturn('1.1.0.0');
        $originalProduct->method('getPrettyVersion')->willReturn('1.1.0');
        $lockedRepo->method('getPackages')->willReturn([$originalProduct]);
        $locker->method('isLocked')->willReturn(true);
        $locker->method('getLockedRepository')->willReturn($lockedRepo);
        $this->composer->method('getLocker')->willReturn($locker);

        $this->userRoot = $this->getMockForAbstractClass(RootPackageInterface::class);
        $this->composer->method('getPackage')->willReturn($this->userRoot);

        $this->originalRoot = $this->createPartialMock(
            Package::class,
            ['getName', 'getVersion', 'getStabilityPriority']
        );
        $this->originalRoot->id = 1;
        $this->originalRoot->method('getName')->willReturn('magento/project-enterprise-edition');
        $this->originalRoot->method('getVersion')->willReturn('1.1.0.0');
        $this->originalRoot->method('getStabilityPriority')->willReturn(0);

        $this->targetRoot = $this->createPartialMock(
            Package::class,
            ['getName', 'getVersion', 'getStabilityPriority', 'getPrettyVersion']
        );
        $this->targetRoot->id = 2;
        $this->targetRoot->method('getName')->willReturn('magento/project-enterprise-edition');
        $this->targetRoot->method('getVersion')->willReturn('2.0.0.0');
        $this->targetRoot->method('getStabilityPriority')->willReturn(0);
        $this->targetRoot->method('getPrettyVersion')->willReturn('');

        $repoManager = $this->createPartialMock(RepositoryManager::class, ['getRepositories']);
        if ($apiMajorVersion == '1') {
            $this->repo = $this->createPartialMock(ComposerRepository::class, ['hasProviders', 'loadRootServerFile']);
            $this->repo->method('hasProviders')->willReturn(true);
            $this->mockProtectedProperty($this->repo, 'rfs', $this->createPartialMock(RemoteFilesystem::class, []));
            $this->repo->method('loadRootServerFile')->willReturn(true);
        } elseif ($apiMajorVersion == '2') {
            $this->repo = $this->createPartialMock(
                ComposerRepository::class,
                ['hasProviders', 'loadRootServerFile', 'loadPackages']
            );
        }

        $repoManager->method('getRepositories')->willReturn([$this->repo]);
        $this->composer->method('getRepositoryManager')->willReturn($repoManager);
    }
}
