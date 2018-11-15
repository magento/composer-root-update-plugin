<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Updater;

use Composer\IO\BaseIO;
use Composer\IO\IOInterface;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\ComposerRootUpdatePlugin\UpdatePluginTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Class ConflictResolverTest
 */
class ConflictResolverTest extends UpdatePluginTestCase
{
    /** @var MockObject|BaseIO */
    public $io;

    /** @var MockObject|RootPackageRetriever */
    public $retriever;

    public function testFindResolutionAddElement()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        $resolution = $resolver->findResolution('field', null, 'newVal', null);

        $this->assertEquals(ConflictResolver::ADD_VAL, $resolution);
    }

    public function testFindResolutionRemoveElement()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        $resolution = $resolver->findResolution('field', 'oldVal', null, 'oldVal');

        $this->assertEquals(ConflictResolver::REMOVE_VAL, $resolution);
    }

    public function testFindResolutionChangeElement()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'oldVal');

        $this->assertEquals(ConflictResolver::CHANGE_VAL, $resolution);
    }

    public function testFindResolutionNoUpdate()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'newVal');

        $this->assertNull($resolution);
    }

    public function testFindResolutionConflictNoOverride()
    {
        $this->io->expects($this->at(0))->method('writeError')
            ->with($this->stringContains('will not be changed'));

        $resolver = new ConflictResolver(false, $this->retriever);
        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertNull($resolution);
    }

    public function testFindResolutionConflictOverride()
    {
        $resolver = new ConflictResolver(true, $this->retriever);

        $this->io->expects($this->at(1))->method('writeError')
            ->with($this->stringContains('overriding local changes'));

        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertEquals(ConflictResolver::CHANGE_VAL, $resolution);
    }

    public function testFindResolutionConflictOverrideRestoreRemoved()
    {
        $resolver = new ConflictResolver(true, $this->retriever);

        $this->io->expects($this->at(1))->method('writeError')
            ->with($this->stringContains('overriding local changes'));

        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', null);

        $this->assertEquals(ConflictResolver::ADD_VAL, $resolution);
    }

    public function testFindResolutionInteractiveConfirm()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        Console::setInteractive(true);
        $this->io->method('isInteractive')->willReturn(true);
        $this->io->expects($this->once())->method('askConfirmation')->willReturn(true);

        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertEquals(ConflictResolver::CHANGE_VAL, $resolution);
    }

    public function testFindResolutionInteractiveNoConfirm()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        Console::setInteractive(true);
        $this->io->method('isInteractive')->willReturn(true);
        $this->io->expects($this->once())->method('askConfirmation')->willReturn(false);

        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertNull($resolution);
    }

    public function testFindResolutionNonInteractiveEnvironmentError()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        Console::setInteractive(true);
        $this->io->method('isInteractive')->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Interactive options cannot be used in non-interactive terminals.');
        $this->io->expects($this->never())->method('askConfirmation');

        $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');
    }

    public function testResolveNestedArrayNonArrayAdd()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveNestedArray('field', null, 'newVal', null);

        $this->assertEquals([true, 'newVal'], $result);
    }

    public function testResolveNestedArrayNonArrayRemove()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveNestedArray('field', 'oldVal', null, 'oldVal');

        $this->assertEquals([true, null], $result);
    }

    public function testResolveNestedArrayNonArrayChange()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveNestedArray('field', 'oldVal', 'newVal', 'oldVal');

        $this->assertEquals([true, 'newVal'], $result);
    }

    public function testResolveArrayMismatchedArray()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveArraySection(
            'extra',
            'oldVal',
            ['newVal'],
            'oldVal'
        )['extra'];

        $this->assertEquals(['newVal'], $result);
    }

    public function testResolveArrayMismatchedMap()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveArraySection(
            'extra',
            ['oldVal'],
            ['key' => 'newVal'],
            ['oldVal']
        )['extra'];

        $this->assertEquals(['key' => 'newVal'], $result);
    }

    public function testResolveArrayFlatArrayAddElement()
    {
        $expected = ['val1', 'val2', 'val3'];

        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveArraySection(
            'extra',
            ['val1'],
            ['val1', 'val3'],
            ['val2', 'val1']
        )['extra'];

        $this->assertEmpty(array_merge(array_diff($expected, $result), array_diff($result, $expected)));
    }

    public function testResolveArrayFlatArrayRemoveElement()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveArraySection(
            'extra',
            ['val1', 'val2', 'val3'],
            ['val2'],
            ['val1', 'val2', 'val3', 'val4']
        )['extra'];

        $this->assertEquals(['val2', 'val4'], array_values($result));
    }

    public function testResolveArrayFlatArrayAddAndRemoveElement()
    {
        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveArraySection(
            'extra',
            ['val1', 'val2', 'val3'],
            ['val2', 'val5'],
            ['val1', 'val2', 'val3', 'val4']
        )['extra'];

        $this->assertEquals(['val2', 'val4', 'val5'], array_values($result));
    }

    public function testResolveArrayAssociativeAddElement()
    {
        $expected = ['key1' => 'val1', 'key2' => 'val2', 'key3' => 'val3'];

        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveArraySection(
            'extra',
            ['key1' => 'val1'],
            ['key1' => 'val1', 'key3' => 'val3'],
            ['key2' => 'val2', 'key1' => 'val1']
        )['extra'];

        $this->assertEmpty(array_merge(array_diff_assoc($expected, $result), array_diff_assoc($result, $expected)));
    }

    public function testResolveArrayAssociativeRemoveElement()
    {
        $expected = ['key2' => 'val2', 'key3' => 'val3'];

        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveArraySection(
            'extra',
            ['key1' => 'val1', 'key2' => 'val2'],
            ['key2' => 'val2'],
            ['key2' => 'val2', 'key1' => 'val1', 'key3' => 'val3']
        )['extra'];

        $this->assertEmpty(array_merge(array_diff_assoc($expected, $result), array_diff_assoc($result, $expected)));
    }

    public function testResolveArrayAssociativeAddAndRemoveElement()
    {
        $expected = ['key3' => 'val3', 'key4' => 'val4'];

        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveArraySection(
            'extra',
            ['key1' => 'val1', 'key2' => 'val2'],
            ['key4' => 'val4'],
            ['key2' => 'val2', 'key1' => 'val1', 'key3' => 'val3']
        )['extra'];

        $this->assertEmpty(array_merge(array_diff_assoc($expected, $result), array_diff_assoc($result, $expected)));
    }

    public function testResolveArrayNestedAdd()
    {
        $expected = ['key1' => ['k1v1', 'k1v2', 'k1v3'], 'key2' => ['k2v1', 'k2v2'], 'key3' => ['k3v1']];

        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveArraySection(
            'extra',
            ['key1' => ['k1v1'], 'key2' => ['k2v1', 'k2v2']],
            ['key1' => ['k1v1', 'k1v2'], 'key2' => ['k2v1', 'k2v2']],
            ['key1' => ['k1v1', 'k1v3'], 'key2' => ['k2v1', 'k2v2'], 'key3' => ['k3v1']]
        )['extra'];

        $expectedKeys = array_keys($expected);
        $actualKeys = array_keys($result);
        $this->assertEmpty(array_merge(array_diff($expectedKeys, $actualKeys), array_diff($actualKeys, $expectedKeys)));
        foreach ($expected as $key => $expectedVal) {
            $actualVal = $result[$key];
            $this->assertEmpty(array_merge(array_diff($expectedVal, $actualVal), array_diff($actualVal, $expectedVal)));
        }
    }

    public function testResolveArrayNestedRemove()
    {
        $expected = ['key1' => ['k1v1', 'k1v3'], 'key2' => ['k2v2'], 'key3' => ['k3v1']];

        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveArraySection(
            'extra',
            ['key1' => ['k1v1', 'k1v2'], 'key2' => ['k2v1', 'k2v2']],
            ['key1' => ['k1v1'], 'key2' => ['k2v2']],
            ['key1' => ['k1v1', 'k1v2', 'k1v3'], 'key2' => ['k2v1', 'k2v2'], 'key3' => ['k3v1']]
        )['extra'];

        $expectedKeys = array_keys($expected);
        $actualKeys = array_keys($result);
        $this->assertEmpty(array_merge(array_diff($expectedKeys, $actualKeys), array_diff($actualKeys, $expectedKeys)));
        foreach ($expected as $key => $expectedVal) {
            $actualVal = $result[$key];
            $this->assertEmpty(array_merge(array_diff($expectedVal, $actualVal), array_diff($actualVal, $expectedVal)));
        }
    }

    public function testResolveLinksAddLink()
    {
        $userLink = $this->createLinks(1, 'user/link');
        $originalMageLinks = $this->createLinks(2);
        $userLinks = array_merge($originalMageLinks, $userLink);
        $targetMageLinks = array_merge($originalMageLinks, $this->createLinks(1, 'targetMage/link'));
        $expected = array_merge($targetMageLinks, $userLink);

        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveLinkSection(
            'require',
            $originalMageLinks,
            $targetMageLinks,
            $userLinks
        );

        $this->assertLinksEqual($expected, $result['require']);
    }

    public function testResolveLinksRemoveLink()
    {
        $userLink = $this->createLinks(1, 'user/link');
        $originalMageLinks = $this->createLinks(2);
        $userLinks = array_merge($originalMageLinks, $userLink);
        $targetMageLinks = array_slice($originalMageLinks, 1);
        $expected = array_merge($targetMageLinks, $userLink);

        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveLinkSection(
            'require',
            $originalMageLinks,
            $targetMageLinks,
            $userLinks
        );

        $this->assertLinksEqual($expected, $result['require']);
    }

    public function testResolveLinksChangeLink()
    {
        $userLink = $this->createLinks(1, 'user/link');
        $originalMageLinks = $this->createLinks(2);
        $userLinks = array_merge($originalMageLinks, $userLink);
        $targetMageLinks = $this->changeLink($originalMageLinks, 1);
        $expected = array_merge($targetMageLinks, $userLink);

        $resolver = new ConflictResolver(false, $this->retriever);
        $result = $resolver->resolveLinkSection(
            'require',
            $originalMageLinks,
            $targetMageLinks,
            $userLinks
        );

        $this->assertLinksEqual($expected, $result['require']);
    }

    public function setUp()
    {
        $this->io = $this->getMockForAbstractClass(IOInterface::class);
        Console::setIO($this->io);
        Console::setInteractive(false);
        $this->retriever = $this->createPartialMock(
            RootPackageRetriever::class,
            ['getOriginalRootPackage', 'getTargetRootPackage', 'getUserRootPackage']
        );
        $this->retriever->method('getOriginalRootPackage')->willReturn(null);
        $this->retriever->method('getTargetRootPackage')->willReturn(null);
        $this->retriever->method('getUserRootPackage')->willReturn(null);
    }
}
