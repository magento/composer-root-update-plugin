<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin;

use Composer\Package\Link;
use Composer\Semver\Constraint\Constraint;
use ReflectionClass;

/**
 * Helper functions for common test data creation and assertion operations
 */
abstract class UpdatePluginTestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * Data setup helper function to create a number of Link objects
     *
     * @param int $count
     * @param string $target
     * @return Link[]
     */
    public static function createLinks($count, $target = 'package/name')
    {
        $links = [];
        for ($i = 1; $i <= $count; $i++) {
            $name = "$target$i";
            $links[$name] = new Link('root/pkg', $name, new Constraint('==', "$i.0.0"), null, "$i.0.0");
        }
        return $links;
    }

    /**
     * Data setup helper function to change the version constraint on one of the links in a list
     *
     * @param Link[] $links
     * @param int $index
     * @return Link[]
     */
    public static function changeLink($links, $index)
    {
        $result = $links;
        /** @var Link $changeLink */
        $changeLink = array_values($links)[$index];
        $version = explode(' ', $changeLink->getConstraint()->getPrettyString())[1];
        $versionParts = array_map('intval', explode('.', $version));
        $versionParts[1] = $versionParts[1] + 1;
        $version = implode('.', $versionParts);
        $result[$changeLink->getTarget()] = new Link(
            $changeLink->getSource(),
            $changeLink->getTarget(),
            new Constraint('==', $version),
            null,
            $version
        );
        return $result;
    }

    /**
     * Assert that two arrays of links are equal without checking order
     *
     * @param Link[] $expected
     * @param array $jsonChanges
     * @return void
     */
    public static function assertLinksEqual($expected, $jsonChanges)
    {
        static::assertEquals(count($expected), count($jsonChanges));
        $remainingJson = $jsonChanges;
        foreach ($expected as $expectedTarget => $expectedLink) {
            $expectedTarget = strtolower($expectedTarget);
            static::assertArrayHasKey($expectedTarget, $remainingJson);
            static::assertEquals($expectedLink->getConstraint()->getPrettyString(), $remainingJson[$expectedTarget]);
            unset($remainingJson[$expectedTarget]);
        }
    }

    /**
     * Assert that the links in the $jsonChanges are ordered as expected

     * @param Link[] $expected
     * @param array $jsonChanges
     * @return void
     */
    public static function assertLinksOrdered($expected, $jsonChanges)
    {
        $expectedOrder = array_map(function ($link) {
            /** @var Link $link */
            return $link->getTarget();
        }, array_values($expected));
        $actualOrder = array_keys($jsonChanges);
        static::assertEquals($expectedOrder, $actualOrder);
    }

    /**
     * Sets a protected property on a given object via reflection
     *
     * @param $object - instance in which protected value is being modified
     * @param $property - property on instance being modified
     * @param $value - new value of the property being modified
     * @return void
     * @throws \ReflectionException
     */
    public static function mockProtectedProperty($object, $property, $value)
    {
        $reflection = new ReflectionClass($object);
        $reflection_property = $reflection->getProperty($property);
        $reflection_property->setAccessible(true);
        $reflection_property->setValue($object, $value);
    }
}
