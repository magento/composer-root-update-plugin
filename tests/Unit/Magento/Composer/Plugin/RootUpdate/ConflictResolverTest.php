<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Composer\Plugin\RootUpdate;

use Composer\IO\BaseIO;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Package\RootPackage;
use Magento\TestHelper\UpdatePluginTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Class ConflictResolverTest
 *
 * @package Magento\Composer\Plugin\RootUpdate
 */
class ConflictResolverTest extends UpdatePluginTestCase
{
    /** @var RootPackage */
    public $installRoot;

    /** @var MockObject|BaseIO */
    public $io;

    public function testFindResolutionAddElement()
    {
        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolution = $resolver->findResolution('field', null, 'newVal', null);

        $this->assertEquals(ConflictResolver::ADD_VAL, $resolution);
    }

    public function testFindResolutionRemoveElement()
    {
        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolution = $resolver->findResolution('field', 'oldVal', null, 'oldVal');

        $this->assertEquals(ConflictResolver::REMOVE_VAL, $resolution);
    }

    public function testFindResolutionChangeElement()
    {
        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'oldVal');

        $this->assertEquals(ConflictResolver::CHANGE_VAL, $resolution);
    }

    public function testFindResolutionNoUpdate()
    {
        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'newVal');

        $this->assertNull($resolution);
    }

    public function testFindResolutionConflictNoOverride()
    {
        $this->io->expects($this->at(0))->method('writeError')
            ->with($this->stringContains('will not be changed'));

        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertNull($resolution);
    }

    public function testFindResolutionConflictOverride()
    {
        $resolver = new ConflictResolver($this->io, false, true, '', '');

        $this->io->expects($this->once())->method('writeError')
            ->with($this->stringContains('overriding local changes'));

        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertEquals(ConflictResolver::CHANGE_VAL, $resolution);
    }

    public function testFindResolutionConflictOverrideRestoreRemoved()
    {
        $resolver = new ConflictResolver($this->io, false, true, '', '');

        $this->io->expects($this->once())->method('writeError')
            ->with($this->stringContains('overriding local changes'));

        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', null);

        $this->assertEquals(ConflictResolver::ADD_VAL, $resolution);
    }

    public function testFindResolutionInteractiveConfirm()
    {
        $this->io->method('isInteractive')->willReturn(true);
        $resolver = new ConflictResolver($this->io, true, false, '', '');
        $this->io->expects($this->once())->method('askConfirmation')->willReturn(true);

        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertEquals(ConflictResolver::CHANGE_VAL, $resolution);
    }

    public function testFindResolutionInteractiveNoConfirm()
    {
        $resolver = new ConflictResolver($this->io, true, false, '', '');
        $this->io->method('isInteractive')->willReturn(true);
        $this->io->expects($this->once())->method('askConfirmation')->willReturn(false);

        $resolution = $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');

        $this->assertNull($resolution);
    }

    public function testFindResolutionNonInteractiveEnvironmentError()
    {
        $resolver = new ConflictResolver($this->io, true, false, '', '');
        $this->io->method('isInteractive')->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            '--' . RootUpdateCommand::INTERACTIVE_OPT . ' cannot be used in non-interactive terminals.'
        );
        $this->io->expects($this->never())->method('askConfirmation');

        $resolver->findResolution('field', 'oldVal', 'newVal', 'conflictVal');
    }

    public function testResolveNestedArrayNonArrayAdd()
    {
        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $result = $resolver->resolveNestedArray('field', null, 'newVal', null);

        $this->assertEquals(['changed' => true, 'value' => 'newVal'], $result);
    }

    public function testResolveNestedArrayNonArrayRemove()
    {
        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $result = $resolver->resolveNestedArray('field', 'oldVal', null, 'oldVal');

        $this->assertEquals(['changed' => true, 'value' => null], $result);
    }

    public function testResolveNestedArrayNonArrayChange()
    {
        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $result = $resolver->resolveNestedArray('field', 'oldVal', 'newVal', 'oldVal');

        $this->assertEquals(['changed' => true, 'value' => 'newVal'], $result);
    }

    public function testResolveArrayMismatchedArray()
    {
        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveArraySection(
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
        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveArraySection(
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

        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveArraySection(
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
        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveArraySection(
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
        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveArraySection(
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

        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveArraySection(
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

        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveArraySection(
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

        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveArraySection(
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

        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveArraySection(
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

        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveArraySection(
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

        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $this->assertEmpty($resolver->getJsonChanges());
        $resolver->resolveArraySection(
            'extra',
            ['val1'],
            ['val1', 'val3'],
            ['val2', 'val1'],
            [$this->installRoot, 'setExtra']
        );
        $actual = $resolver->getJsonChanges();

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

        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveLinkSection(
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

        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveLinkSection(
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

        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $resolver->resolveLinkSection(
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

        $resolver = new ConflictResolver($this->io, false, false, '', '');
        $this->assertEmpty($resolver->getJsonChanges());
        $resolver->resolveLinkSection(
            'require',
            $baseLinks,
            $targetLinks,
            $installLinks,
            [$this->installRoot, 'setRequires']
        );

        $changed = $resolver->getJsonChanges();
        $this->assertEquals(['require'], array_keys($changed));
        $actual = $changed['require'];
        $this->assertEquals(2, count($actual));
        $this->assertTrue(key_exists($targetLinks[0]->getTarget(), $actual));
        $this->assertEquals($targetLinks[0]->getConstraint()->getPrettyString(), $actual[$targetLinks[0]->getTarget()]);
        $this->assertTrue(key_exists($installLink->getTarget(), $actual));
        $this->assertEquals($installLinks[1]->getConstraint()->getPrettyString(), $actual[$installLink->getTarget()]);
    }

    public function setUp()
    {
        $this->io = $this->getMockForAbstractClass(IOInterface::class);
        $this->installRoot = new RootPackage('magento/project-community-edition', '1.0.0.0', '1.0.0');
    }
}
