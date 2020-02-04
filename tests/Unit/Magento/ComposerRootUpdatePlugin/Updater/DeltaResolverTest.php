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

class DeltaResolverTest extends UpdatePluginTestCase
{
    /**
     * @var MockObject|BaseIO
     */
    public $io;

    /**
     * @var MockObject|RootPackageRetriever
     */
    public $retriever;

    /**
     * @var Console
     */
    public $console;

    public function testFindResolutionAddElement()
    {
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $resolution = $resolver->findResolution('field', null, 'newVal', null);

        $this->assertEquals(DeltaResolver::ADD_VAL, $resolution);
    }

    public function testFindResolutionRemoveElement()
    {
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $resolution = $resolver->findResolution('field', 'oldVal', null, 'oldVal');

        $this->assertEquals(DeltaResolver::REMOVE_VAL, $resolution);
    }

    public function testFindResolutionChangeElement()
    {
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'oldVal');

        $this->assertEquals(DeltaResolver::CHANGE_VAL, $resolution);
    }

    public function testFindResolutionNoUpdate()
    {
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'newVal');

        $this->assertNull($resolution);
    }

    public function testFindResolutionConflictNoOverride()
    {
        $this->io->expects($this->at(0))->method('writeError')
            ->with($this->stringContains('will not be changed'));
        
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertNull($resolution);
    }

    public function testFindResolutionConflictOverride()
    {
        $resolver = new DeltaResolver($this->console, true, $this->retriever);

        $this->io->expects($this->at(1))->method('writeError')
            ->with($this->stringContains('overriding local changes'));

        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertEquals(DeltaResolver::CHANGE_VAL, $resolution);
    }

    public function testFindResolutionConflictOverrideRestoreRemoved()
    {
        $resolver = new DeltaResolver($this->console, true, $this->retriever);

        $this->io->expects($this->at(1))->method('writeError')
            ->with($this->stringContains('overriding local changes'));

        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', null);

        $this->assertEquals(DeltaResolver::ADD_VAL, $resolution);
    }

    public function testFindResolutionInteractiveConfirm()
    {
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $this->console->setInteractive(true);
        $this->io->method('isInteractive')->willReturn(true);
        $this->io->expects($this->once())->method('askConfirmation')->willReturn(true);

        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertEquals(DeltaResolver::CHANGE_VAL, $resolution);
    }

    public function testFindResolutionInteractiveNoConfirm()
    {
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $this->console->setInteractive(true);
        $this->io->method('isInteractive')->willReturn(true);
        $this->io->expects($this->once())->method('askConfirmation')->willReturn(false);

        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertNull($resolution);
    }

    public function testFindResolutionNonInteractiveEnvironmentError()
    {
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $this->console->setInteractive(true);
        $this->io->method('isInteractive')->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Interactive options cannot be used in non-interactive terminals.');
        $this->io->expects($this->never())->method('askConfirmation');

        $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');
    }

    public function testResolveNestedArrayNonArrayAdd()
    {
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $changed = false;
        $result = $resolver->resolveNestedArray('field', null, 'newVal', null, $changed);

        $this->assertTrue($changed);
        $this->assertEquals('newVal', $result);
    }

    public function testResolveNestedArrayNonArrayRemove()
    {
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $changed = false;
        $result = $resolver->resolveNestedArray('field', 'oldVal', null, 'oldVal', $changed);

        $this->assertTrue($changed);
        $this->assertEquals(null, $result);
    }

    public function testResolveNestedArrayNonArrayChange()
    {
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $changed = false;
        $result = $resolver->resolveNestedArray('field', 'oldVal', 'newVal', 'oldVal', $changed);

        $this->assertTrue($changed);
        $this->assertEquals('newVal', $result);
    }

    public function testResolveArrayMismatchedArray()
    {
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
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
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
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

        $resolver = new DeltaResolver($this->console, false, $this->retriever);
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
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
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
        $resolver = new DeltaResolver($this->console, false, $this->retriever);
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

        $resolver = new DeltaResolver($this->console, false, $this->retriever);
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

        $resolver = new DeltaResolver($this->console, false, $this->retriever);
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

        $resolver = new DeltaResolver($this->console, false, $this->retriever);
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

        $resolver = new DeltaResolver($this->console, false, $this->retriever);
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

        $resolver = new DeltaResolver($this->console, false, $this->retriever);
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

        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $result = $resolver->resolveLinkSection(
            'require',
            $originalMageLinks,
            $targetMageLinks,
            $userLinks,
            false
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

        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $result = $resolver->resolveLinkSection(
            'require',
            $originalMageLinks,
            $targetMageLinks,
            $userLinks,
            false
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

        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $result = $resolver->resolveLinkSection(
            'require',
            $originalMageLinks,
            $targetMageLinks,
            $userLinks,
            false
        );

        $this->assertLinksEqual($expected, $result['require']);
    }

    public function testResolveLinksUpdateOrder()
    {
        $orderedLinks = $this->createLinks(4, 'target/link');
        $reorderedLinks = array_reverse($orderedLinks);
        $targetMageLinks = array_merge($this->createLinks(1), $orderedLinks);
        $originalMageLinks = array_merge($this->createLinks(2), $reorderedLinks);

        $resolver = new DeltaResolver($this->console, true, $this->retriever);
        $result = $resolver->resolveLinkSection(
            'require',
            $originalMageLinks,
            $targetMageLinks,
            $originalMageLinks,
            true
        );

        $this->assertLinksEqual($targetMageLinks, $result['require']);
        $this->assertLinksOrdered($targetMageLinks, $result['require']);
    }

    public function testResolveLinksAddLinkWithOrder()
    {
        $targetMageLinks = array_merge($this->createLinks(3), $this->createLinks(4, 'target/link'));
        $originalMageLinks = array_merge($this->createLinks(2), $this->createLinks(4, 'target/link'));

        $resolver = new DeltaResolver($this->console, true, $this->retriever);
        $result = $resolver->resolveLinkSection(
            'require',
            $originalMageLinks,
            $targetMageLinks,
            $originalMageLinks,
            true
        );

        $this->assertLinksEqual($targetMageLinks, $result['require']);
        $this->assertLinksOrdered($targetMageLinks, $result['require']);
    }

    public function testResolveLinksOrderOverride()
    {
        $orderedLinks = $this->createLinks(4, 'target/link');
        $reorderedLinks = array_reverse($orderedLinks);
        $originalMageLinks = $this->createLinks(2);
        $targetMageLinks = array_merge($originalMageLinks, $orderedLinks);
        $userLinks = array_merge($originalMageLinks, $reorderedLinks);

        $this->io->expects($this->at(1))->method('writeError')
            ->with($this->stringContains('overriding local order'));

        $resolver = new DeltaResolver($this->console, true, $this->retriever);
        $result = $resolver->resolveLinkSection(
            'require',
            $originalMageLinks,
            $targetMageLinks,
            $userLinks,
            true
        );

        $this->assertLinksEqual($targetMageLinks, $result['require']);
        $this->assertLinksOrdered($targetMageLinks, $result['require']);
    }

    public function testResolveLinksOrderNoOverride()
    {
        $orderedLinks = $this->createLinks(4, 'target/link');
        $reorderedLinks = array_reverse($orderedLinks);
        $originalMageLinks = $this->createLinks(2);
        $targetMageLinks = array_merge($originalMageLinks, $orderedLinks);
        $userLinks = array_merge($originalMageLinks, $reorderedLinks);

        $this->io->expects($this->at(0))->method('writeError')
            ->with($this->stringContains('will not be changed'));

        $resolver = new DeltaResolver($this->console, false, $this->retriever);
        $result = $resolver->resolveLinkSection(
            'require',
            $originalMageLinks,
            $targetMageLinks,
            $userLinks,
            true
        );

        $this->assertLinksEqual($userLinks, $result['require']);
        $this->assertLinksOrdered($userLinks, $result['require']);
    }

    public function setUp()
    {
        $this->io = $this->getMockForAbstractClass(IOInterface::class);
        $this->console = new Console($this->io);
        $this->retriever = $this->createPartialMock(
            RootPackageRetriever::class,
            [
                'getOriginalRootPackage',
                'getOriginalLabel',
                'getTargetRootPackage',
                'getTargetLabel',
                'getUserRootPackage'
            ]
        );
        $this->retriever->method('getOriginalRootPackage')->willReturn(null);
        $this->retriever->method('getOriginalLabel')->willReturn('Magento Open Source 1.0.0');
        $this->retriever->method('getTargetRootPackage')->willReturn(null);
        $this->retriever->method('getTargetLabel')->willReturn('Magento Open Source 2.0.0');
        $this->retriever->method('getUserRootPackage')->willReturn(null);
    }
}
