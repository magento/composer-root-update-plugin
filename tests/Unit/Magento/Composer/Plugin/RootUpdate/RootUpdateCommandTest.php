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
use Composer\Plugin\Capability\Capability;
use Composer\Plugin\PluginManager;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\ScriptEvents;
use Composer\Semver\Constraint\Constraint;
use Magento\TestHelper\TestApplication;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class RootUpdateCommandTest
 *
 * @package Magento\Composer\Plugin\RootUpdate
 */
class RootUpdateCommandTest extends \PHPUnit\Framework\TestCase
{
    /** @var TestApplication */
    public $application;

    /** @var RootUpdateCommand */
    public $rootUpdateCommand;

    /** @var MockObject|Composer */
    public $composer;

    /** @var RootPackage */
    public $baseRoot;

    /** @var RootPackage */
    public $targetRoot;

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

    /** @var MockObject|OutputInterface */
    public $output;

    /** @var MockObject|BaseIO */
    public $io;

    public function testOverwriteUpdateCommand()
    {
        /** @var MockObject|OutputInterface $output */
        $output = $this->getMockForAbstractClass(OutputInterface::class);

        $this->application->doRun($this->input, $output);

        $this->assertEquals($this->rootUpdateCommand, $this->application->getCalledCommand());
    }

    public function testUpdateCommandNoPlugins()
    {
        /** @var MockObject|OutputInterface $output */
        $output = $this->getMockForAbstractClass(OutputInterface::class);
        $this->input->method('hasParameterOption')->willReturnMap([['--no-plugins', false, true]]);

        $this->application->doRun($this->input, $output);

        $this->assertNotEquals($this->rootUpdateCommand, $this->application->getCalledCommand());
    }

    public function testFindResolutionAddElement()
    {
        $resolution = $this->rootUpdateCommand->findResolution('field', null, 'newVal', null);

        $this->assertEquals(RootUpdateCommand::ADD_VAL, $resolution);
    }

    public function testFindResolutionRemoveElement()
    {
        $resolution = $this->rootUpdateCommand->findResolution('field', 'oldVal', null, 'oldVal');

        $this->assertEquals(RootUpdateCommand::REMOVE_VAL, $resolution);
    }

    public function testFindResolutionChangeElement()
    {
        $resolution = $this->rootUpdateCommand->findResolution('field', 'oldVal', 'newVal', 'oldVal');

        $this->assertEquals(RootUpdateCommand::CHANGE_VAL, $resolution);
    }

    public function testFindResolutionNoUpdate()
    {
        $resolution = $this->rootUpdateCommand->findResolution('field', 'oldVal', 'newVal', 'newVal');

        $this->assertNull($resolution);
    }

    public function testFindResolutionConflictNoOverride()
    {
        $this->io->expects($this->at(0))->method('writeError')
            ->with($this->stringContains('will not be changed'));

        $resolution = $this->rootUpdateCommand->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertNull($resolution);
    }

    public function testFindResolutionConflictOverride()
    {
        $this->rootUpdateCommand->setOverride(true);

        $this->io->expects($this->once())->method('writeError')
            ->with($this->stringContains('overriding local changes'));

        $resolution = $this->rootUpdateCommand->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertEquals(RootUpdateCommand::CHANGE_VAL, $resolution);
    }

    public function testFindResolutionConflictOverrideRestoreRemoved()
    {
        $this->rootUpdateCommand->setOverride(true);
        $this->io->expects($this->once())->method('writeError')
            ->with($this->stringContains('overriding local changes'));

        $resolution = $this->rootUpdateCommand->findResolution('field', 'oldVal', 'newVal', null);

        $this->assertEquals(RootUpdateCommand::ADD_VAL, $resolution);
    }

    public function testFindResolutionInteractiveConfirm()
    {
        $this->rootUpdateCommand->setInteractiveInput(true);
        $this->rootUpdateCommand->setInteractive(true);

        $this->io->expects($this->once())->method('askConfirmation')->willReturn(true);

        $resolution = $this->rootUpdateCommand->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertEquals(RootUpdateCommand::CHANGE_VAL, $resolution);
    }

    public function testFindResolutionInteractiveNoConfirm()
    {
        $this->rootUpdateCommand->setInteractiveInput(true);
        $this->rootUpdateCommand->setInteractive(true);
        $this->rootUpdateCommand->setOverride(false);

        $this->io->expects($this->once())->method('askConfirmation')->willReturn(false);

        $resolution = $this->rootUpdateCommand->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertNull($resolution);
    }

    public function testFindResolutionNonInteractiveEnvironmentError()
    {
        $this->rootUpdateCommand->setInteractiveInput(false);
        $this->rootUpdateCommand->setInteractive(true);
        $this->rootUpdateCommand->setOverride(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            '--' . RootUpdateCommand::INTERACTIVE_OPT . ' cannot be used in non-interactive terminals.'
        );
        $this->io->expects($this->never())->method('askConfirmation');

        $this->rootUpdateCommand->findResolution('field', 'oldVal', 'newVal', 'conflictVal');
    }

    public function testResolveNestedArrayNonArrayAdd()
    {
        $result = $this->rootUpdateCommand->resolveNestedArray('field', null, 'newVal', null);

        $this->assertEquals(['changed' => true, 'value' => 'newVal'], $result);
    }

    public function testResolveNestedArrayNonArrayRemove()
    {
        $result = $this->rootUpdateCommand->resolveNestedArray('field', 'oldVal', null, 'oldVal');

        $this->assertEquals(['changed' => true, 'value' => null], $result);
    }

    public function testResolveNestedArrayNonArrayChange()
    {
        $result = $this->rootUpdateCommand->resolveNestedArray('field', 'oldVal', 'newVal', 'oldVal');

        $this->assertEquals(['changed' => true, 'value' => 'newVal'], $result);
    }

    public function testResolveArrayMismatchedArray()
    {
        $this->rootUpdateCommand->resolveArraySection(
            'extra',
            'oldVal',
            ['newVal'],
            'oldVal',
            [$this->installRoot, 'setExtra']
        );

        $this->assertEquals(['newVal'], $this->installRoot->getExtra());
    }

    public function testResolveArrayMismatchedMap()
    {
        $this->rootUpdateCommand->resolveArraySection(
            'extra',
            ['oldVal'],
            ['key' => 'newVal'],
            ['oldVal'],
            [$this->installRoot, 'setExtra']
        );

        $this->assertEquals(['key' => 'newVal'], $this->installRoot->getExtra());
    }

    public function testResolveArrayFlatArrayAddElement()
    {
        $expected = ['val1', 'val2', 'val3'];

        $this->rootUpdateCommand->resolveArraySection(
            'extra',
            ['val1'],
            ['val1', 'val3'],
            ['val2', 'val1'],
            [$this->installRoot, 'setExtra']
        );

        $result = $this->installRoot->getExtra();
        $this->assertEmpty(array_merge(array_diff($expected, $result), array_diff($result, $expected)));
    }

    public function testResolveArrayFlatArrayRemoveElement()
    {
        $this->rootUpdateCommand->resolveArraySection(
            'extra',
            ['val1', 'val2', 'val3'],
            ['val2'],
            ['val1', 'val2', 'val3', 'val4'],
            [$this->installRoot, 'setExtra']
        );

        $this->assertEquals(['val2', 'val4'], array_values($this->installRoot->getExtra()));
    }

    public function testResolveArrayFlatArrayAddAndRemoveElement()
    {
        $this->rootUpdateCommand->resolveArraySection(
            'extra',
            ['val1', 'val2', 'val3'],
            ['val2', 'val5'],
            ['val1', 'val2', 'val3', 'val4'],
            [$this->installRoot, 'setExtra']
        );

        $this->assertEquals(['val2', 'val4', 'val5'], array_values($this->installRoot->getExtra()));
    }

    public function testResolveArrayAssociativeAddElement()
    {
        $expected = ['key1' => 'val1', 'key2' => 'val2', 'key3' => 'val3'];

        $this->rootUpdateCommand->resolveArraySection(
            'extra',
            ['key1' => 'val1'],
            ['key1' => 'val1', 'key3' => 'val3'],
            ['key2' => 'val2', 'key1' => 'val1'],
            [$this->installRoot, 'setExtra']
        );

        $result = $this->installRoot->getExtra();
        $this->assertEmpty(array_merge(array_diff_assoc($expected, $result), array_diff_assoc($result, $expected)));
    }

    public function testResolveArrayAssociativeRemoveElement()
    {
        $expected = ['key2' => 'val2', 'key3' => 'val3'];

        $this->rootUpdateCommand->resolveArraySection(
            'extra',
            ['key1' => 'val1', 'key2' => 'val2'],
            ['key2' => 'val2'],
            ['key2' => 'val2', 'key1' => 'val1', 'key3' => 'val3'],
            [$this->installRoot, 'setExtra']
        );

        $result = $this->installRoot->getExtra();
        $this->assertEmpty(array_merge(array_diff_assoc($expected, $result), array_diff_assoc($result, $expected)));
    }

    public function testResolveArrayAssociativeAddAndRemoveElement()
    {
        $expected = ['key3' => 'val3', 'key4' => 'val4'];

        $this->rootUpdateCommand->resolveArraySection(
            'extra',
            ['key1' => 'val1', 'key2' => 'val2'],
            ['key4' => 'val4'],
            ['key2' => 'val2', 'key1' => 'val1', 'key3' => 'val3'],
            [$this->installRoot, 'setExtra']
        );

        $result = $this->installRoot->getExtra();
        $this->assertEmpty(array_merge(array_diff_assoc($expected, $result), array_diff_assoc($result, $expected)));
    }

    public function testResolveArrayNestedAdd()
    {
        $expected = ['key1' => ['k1v1', 'k1v2', 'k1v3'], 'key2' => ['k2v1', 'k2v2'], 'key3' => ['k3v1']];

        $this->rootUpdateCommand->resolveArraySection(
            'extra',
            ['key1' => ['k1v1'], 'key2' => ['k2v1', 'k2v2']],
            ['key1' => ['k1v1', 'k1v2'], 'key2' => ['k2v1', 'k2v2']],
            ['key1' => ['k1v1', 'k1v3'], 'key2' => ['k2v1', 'k2v2'], 'key3' => ['k3v1']],
            [$this->installRoot, 'setExtra']
        );

        $expectedKeys = array_keys($expected);
        $actualKeys = array_keys($this->installRoot->getExtra());
        $this->assertEmpty(array_merge(array_diff($expectedKeys, $actualKeys), array_diff($actualKeys, $expectedKeys)));
        foreach ($expected as $key => $expectedVal) {
            $actualVal = $this->installRoot->getExtra()[$key];
            $this->assertEmpty(array_merge(array_diff($expectedVal, $actualVal), array_diff($actualVal, $expectedVal)));
        }
    }

    public function testResolveArrayNestedRemove()
    {
        $expected = ['key1' => ['k1v1', 'k1v3'], 'key2' => ['k2v2'], 'key3' => ['k3v1']];

        $this->rootUpdateCommand->resolveArraySection(
            'extra',
            ['key1' => ['k1v1', 'k1v2'], 'key2' => ['k2v1', 'k2v2']],
            ['key1' => ['k1v1'], 'key2' => ['k2v2']],
            ['key1' => ['k1v1', 'k1v2', 'k1v3'], 'key2' => ['k2v1', 'k2v2'], 'key3' => ['k3v1']],
            [$this->installRoot, 'setExtra']
        );

        $expectedKeys = array_keys($expected);
        $actualKeys = array_keys($this->installRoot->getExtra());
        $this->assertEmpty(array_merge(array_diff($expectedKeys, $actualKeys), array_diff($actualKeys, $expectedKeys)));
        foreach ($expected as $key => $expectedVal) {
            $actualVal = $this->installRoot->getExtra()[$key];
            $this->assertEmpty(array_merge(array_diff($expectedVal, $actualVal), array_diff($actualVal, $expectedVal)));
        }
    }

    public function testResolveArrayTracksChanges()
    {
        $expected = ['val1', 'val2', 'val3'];

        $this->assertEmpty($this->rootUpdateCommand->getJsonChanges());
        $this->rootUpdateCommand->resolveArraySection(
            'extra',
            ['val1'],
            ['val1', 'val3'],
            ['val2', 'val1'],
            [$this->installRoot, 'setExtra']
        );
        $actual = $this->rootUpdateCommand->getJsonChanges();

        $this->assertEquals(['extra'], array_keys($actual));
        $actual = $actual['extra'];
        $this->assertEmpty(array_merge(array_diff($expected, $actual), array_diff($actual, $expected)));
    }

    public function testResolveLinksAddLink()
    {
        $installLink = $this->createLinks(1, 'install/link');
        $baseLinks = $this->createLinks(2);
        $installLinks = array_merge($baseLinks, $installLink);
        $targetLinks = array_merge($baseLinks, $this->createLinks(1, 'target/link'));
        $expected = array_merge($targetLinks, $installLink);

        $this->rootUpdateCommand->resolveLinkSection(
            'require',
            $baseLinks,
            $targetLinks,
            $installLinks,
            [$this->installRoot, 'setRequires']
        );

        $this->assertLinksEqual($expected, $this->installRoot->getRequires());
    }

    public function testResolveLinksRemoveLink()
    {
        $installLink = $this->createLinks(1, 'install/link');
        $baseLinks = $this->createLinks(2);
        $installLinks = array_merge($baseLinks, $installLink);
        $targetLinks = array_slice($baseLinks, 1);
        $expected = array_merge($targetLinks, $installLink);

        $this->rootUpdateCommand->resolveLinkSection(
            'require',
            $baseLinks,
            $targetLinks,
            $installLinks,
            [$this->installRoot, 'setRequires']
        );

        $this->assertLinksEqual($expected, $this->installRoot->getRequires());
    }

    public function testResolveLinksChangeLink()
    {
        $installLink = $this->createLinks(1, 'install/link');
        $baseLinks = $this->createLinks(2);
        $installLinks = array_merge($baseLinks, $installLink);
        $targetLinks = $this->changeLink($baseLinks, 1);
        $expected = array_merge($targetLinks, $installLink);

        $this->rootUpdateCommand->resolveLinkSection(
            'require',
            $baseLinks,
            $targetLinks,
            $installLinks,
            [$this->installRoot, 'setRequires']
        );

        $this->assertLinksEqual($expected, $this->installRoot->getRequires());
    }

    public function testResolveLinksTracksChanges()
    {
        $installLink = $this->createLinks(1, 'install/link')[0];
        $baseLinks = $this->createLinks(1);
        /** @var Link[] $installLinks */
        $installLinks = array_merge($baseLinks, [$installLink]);
        $targetLinks = $this->changeLink($baseLinks, 0);

        $this->assertEmpty($this->rootUpdateCommand->getJsonChanges());
        $this->rootUpdateCommand->resolveLinkSection(
            'require',
            $baseLinks,
            $targetLinks,
            $installLinks,
            [$this->installRoot, 'setRequires']
        );

        $changed = $this->rootUpdateCommand->getJsonChanges();
        $this->assertEquals(['require'], array_keys($changed));
        $actual = $changed['require'];
        $this->assertEquals(2, count($actual));
        $this->assertTrue(key_exists($targetLinks[0]->getTarget(), $actual));
        $this->assertEquals($targetLinks[0]->getConstraint()->getPrettyString(), $actual[$targetLinks[0]->getTarget()]);
        $this->assertTrue(key_exists($installLink->getTarget(), $actual));
        $this->assertEquals($installLinks[1]->getConstraint()->getPrettyString(), $actual[$installLink->getTarget()]);
    }

    public function testMagentoUpdateSkipOption()
    {
        $this->input->method('getOption')->willReturnMap([[RootUpdateCommand::SKIP_OPT, true]]);

        $this->composer->expects($this->never())->method('setPackage');

        $this->rootUpdateCommand->magentoUpdate($this->input, $this->composer);
    }

    public function testMagentoUpdateNotMagentoRoot()
    {
        $this->installRoot->setRequires($this->createLinks(2, 'vndr/package'));

        $this->composer->expects($this->never())->method('setPackage');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Magento root updates cannot run without a valid target package');

        $this->rootUpdateCommand->magentoUpdate($this->input, $this->composer);
    }

    public function testMagentoUpdateRegistersPostUpdateWrites()
    {
        $this->rootUpdateCommand->setApplication($this->application);

        $this->eventDispatcher->expects($this->once())->method('addListener')->with(
            ScriptEvents::POST_UPDATE_CMD,
            [$this->rootUpdateCommand, 'writeUpdatedRoot'],
            PHP_INT_MAX
        );

        $this->rootUpdateCommand->magentoUpdate($this->input, $this->composer);
    }

    public function testMagentoUpdateDryRun()
    {
        $this->rootUpdateCommand->setApplication($this->application);
        $this->input->method('getOption')->willReturnMap([['dry-run', true]]);

        $this->eventDispatcher->expects($this->never())->method('addListener');

        $this->rootUpdateCommand->magentoUpdate($this->input, $this->composer);

        $this->assertNotEmpty($this->rootUpdateCommand->getJsonChanges());
    }

    public function testMagentoUpdateSetsFieldsNoOverride()
    {
        $this->rootUpdateCommand->setApplication($this->application);

        /** @var RootPackage $newRoot */
        $this->composer->expects($this->once())->method('setPackage')->with($this->captureArg($newRoot));

        $this->rootUpdateCommand->magentoUpdate($this->input, $this->composer);

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
        $this->rootUpdateCommand->setApplication($this->application);
        $this->input->method('getOption')->willReturnMap([[RootUpdateCommand::OVERRIDE_OPT, true]]);

        /** @var RootPackage $newRoot */
        $this->composer->expects($this->once())->method('setPackage')->with($this->captureArg($newRoot));

        $this->rootUpdateCommand->magentoUpdate($this->input, $this->composer);

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
        $this->rootUpdateCommand->setApplication($this->application);
        $this->input->method('getOption')->willReturnMap([['no-dev', true]]);

        /** @var RootPackage $newRoot */
        $this->composer->expects($this->once())->method('setPackage')->with($this->captureArg($newRoot));

        $this->rootUpdateCommand->magentoUpdate($this->input, $this->composer);

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
        $this->rootUpdateCommand->setApplication($this->application);
        $this->input->method('getOption')->willReturnMap([['no-autoloader', true]]);

        /** @var RootPackage $newRoot */
        $this->composer->expects($this->once())->method('setPackage')->with($this->captureArg($newRoot));

        $this->rootUpdateCommand->magentoUpdate($this->input, $this->composer);

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

    /**
     * Setup test data, expected results, and necessary mocked objects
     */
    public function setUp()
    {
        /**
         * Create instance of RootUpdateCommand for testing
         */
        $this->rootUpdateCommand = new RootUpdateCommand();

        /**
         * Set up input RootPackage objects for magentoUpdate()
         */
        $baseRoot = new RootPackage('magento/project-community-edition', '1.0.0.0', '1.0.0');
        $baseRoot->setRequires([
            new Link('root/pkg', 'magento/product-community-edition', new Constraint('==', '1.0.0'), null, '1.0.0'),
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
        $this->baseRoot = $baseRoot;

        $targetRoot = new RootPackage('magento/project-community-edition', '2.0.0.0', '2.0.0');
        $targetRoot->setRequires([
            new Link('root/pkg', 'magento/product-community-edition', new Constraint('==', '2.0.0'), null, '2.0.0'),
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
        $this->targetRoot = $targetRoot;

        $installRoot = new RootPackage('magento/project-community-edition', '1.0.0.0', '1.0.0');
        $installRoot->setRequires([
            new Link('root/pkg', 'magento/product-community-edition', new Constraint('==', '2.0.0'), null, '2.0.0'),
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
         * Set up expected results from magentoUpdate() with and without overriding conflicting install values
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
        $capability = $this->createPartialMock(Capability::class, ['getCommands']);
        $capability->method('getCommands')->willReturn([$this->rootUpdateCommand]);
        $pluginManager = $this->createPartialMock(PluginManager::class, ['getPluginCapabilities']);
        $pluginManager->method('getPluginCapabilities')->willReturn([$capability]);
        $this->eventDispatcher = $this->createPartialMock(EventDispatcher::class, ['addListener']);

        /**
         * Mock InputInterface for CLI options and IOInterface for interaction
         */
        $input = $this->getMockForAbstractClass(InputInterface::class);
        $input->method('getFirstArgument')->willReturn('update');
//        $input->method('hasParameterOption')->willReturnMap([
//            ['--no-plugins', false],
//            ['--profile', false]
//        ]);
        $input->method('isInteractive')->willReturn(false);
        $input->method('getParameterOption')->with(['--working-dir', '-d'])->willReturn(false);
        $this->input = $input;
        $this->io = $this->getMockForAbstractClass(IOInterface::class);
        $this->rootUpdateCommand->setIO($this->io);

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
        $config = $this->createPartialMock(Config::class, ['get']);
        $config->method('get')->with('platform')->willReturn([]);
        $composer = $this->createPartialMock(Composer::class, [
            'getPluginManager',
            'getLocker',
            'getPackage',
            'getRepositoryManager',
            'getEventDispatcher',
            'getConfig',
            'setPackage'
        ]);
        $composer->method('getPluginManager')->willReturn($pluginManager);
        $composer->method('getLocker')->willReturn($locker);
        $composer->method('getEventDispatcher')->willReturn($this->eventDispatcher);
        $composer->method('getRepositoryManager')->willReturn($repoManager);
        $composer->method('getPackage')->willReturn($installRoot);
        $composer->method('getConfig')->willReturn($config);
        $this->composer = $composer;
        $this->application = new TestApplication();
        $this->application->setComposer($composer);
    }

    /**
     * Data setup helper function to create a number of Link objects
     *
     * @param int $count
     * @param string $target
     *
     * @return Link[]
     */
    public function createLinks($count, $target = 'package/name')
    {
        $links = [];
        for ($i = 1; $i <= $count; $i++) {
            $links[] = new Link('root/pkg', "$target$i", new Constraint('==', "$i.0.0"), null, "$i.0.0");
        }
        return $links;
    }

    /**
     * Data setup helper function to change the version constraint on one of the links in a list
     *
     * @param Link[] $links
     * @param int $index
     *
     * @return Link[]
     */
    public function changeLink($links, $index)
    {
        $result = $links;
        $changeLink = $links[$index];
        $version = explode(' ', $changeLink->getConstraint()->getPrettyString())[1];
        $versionParts = array_map('intval', explode('.', $version));
        $versionParts[1] = $versionParts[1] + 1;
        $version = implode('.', $versionParts);
        $result[$index] = new Link(
            $changeLink->getSource(),
            $changeLink->getTarget(),
            new Constraint('==', $version),
            null,
            $version
        );
        return $result;
    }

    /**
     * Callback to capture an argument passed to a mock function in the given variable
     *
     * @param &$arg
     *
     * @return \PHPUnit\Framework\Constraint\Callback
     */
    public function captureArg(&$arg)
    {
        return $this->callback(function ($argToMock) use (&$arg) {
            $arg = $argToMock;
            return true;
        });
    }

    /**
     * Assert that two arrays of links are equal without checking order
     *
     * @param Link[] $expected
     * @param Link[] $actual
     *
     * @return void
     */
    public function assertLinksEqual($expected, $actual)
    {
        $this->assertEquals(count($expected), count($actual));
        while (count($expected) > 0) {
            $expectedLink = array_shift($expected);
            $expectedSource = $expectedLink->getSource();
            $expectedTarget = $expectedLink->getTarget();
            $expectedConstraint = $expectedLink->getConstraint()->getPrettyString();
            $found = -1;
            foreach ($actual as $key => $actualLink) {
                if ($actualLink->getSource() === $expectedSource &&
                    $actualLink->getTarget() === $expectedTarget &&
                    $actualLink->getConstraint()->getPrettyString() === $expectedConstraint) {
                    $found = $key;
                    break;
                }
            }
            $this->assertGreaterThan(-1, $found, "Could not find a link matching $expectedLink");
            unset($actual[$found]);
        }
    }

    /**
     * Assert that two arrays of links are not equal without checking order
     *
     * @param Link[] $expected
     * @param Link[] $actual
     *
     * @return void
     */
    public function assertLinksNotEqual($expected, $actual)
    {
        if (count($expected) !== count($actual)) {
            $this->assertNotEquals(count($expected), count($actual));
            return;
        }

        while (count($expected) > 0) {
            $expectedLink = array_shift($expected);
            $expectedSource = $expectedLink->getSource();
            $expectedTarget = $expectedLink->getTarget();
            $expectedConstraint = $expectedLink->getConstraint()->getPrettyString();
            $found = -1;
            foreach ($actual as $key => $actualLink) {
                if ($actualLink->getSource() === $expectedSource &&
                    $actualLink->getTarget() === $expectedTarget &&
                    $actualLink->getConstraint()->getPrettyString() === $expectedConstraint) {
                    $found = $key;
                    break;
                }
            }
            if ($found === -1) {
                $this->assertEquals(-1, $found);
                return;
            }
            unset($actual[$found]);
        }
        $this->fail('Expected Link sets to not be equal');
    }
}
