<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\TestHelper;

use Composer\Package\Link;
use Composer\Semver\Constraint\Constraint;

/**
 * Class UpdatePluginTestCase
 *
 * @package Magento\TestHelper
 */
abstract class UpdatePluginTestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * Data setup helper function to create a number of Link objects
     *
     * @param int $count
     * @param string $target
     *
     * @return Link[]
     */
    public static function createLinks($count, $target = 'package/name')
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
    public static function changeLink($links, $index)
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
    public static function captureArg(&$arg)
    {
        return static::callback(function ($argToMock) use (&$arg) {
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
    public static function assertLinksEqual($expected, $actual)
    {
        static::assertEquals(count($expected), count($actual));
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
            static::assertGreaterThan(-1, $found, "Could not find a link matching $expectedLink");
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
    public static function assertLinksNotEqual($expected, $actual)
    {
        if (count($expected) !== count($actual)) {
            static::assertNotEquals(count($expected), count($actual));
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
                static::assertEquals(-1, $found);
                return;
            }
            unset($actual[$found]);
        }
        static::fail('Expected Link sets to not be equal');
    }
}
