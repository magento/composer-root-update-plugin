<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Composer\Plugin\RootUpdate;

use Composer\Composer;
use Composer\Plugin\Capability\Capability;
use Composer\Plugin\PluginManager;
use Magento\TestHelper\TestApplication;
use Magento\TestHelper\UpdatePluginTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class RootUpdateCommandTest
 *
 * @package Magento\Composer\Plugin\RootUpdate
 */
class RootUpdateCommandTest extends UpdatePluginTestCase
{
    /** @var TestApplication */
    public $application;

    /** @var RootUpdateCommand */
    public $rootUpdateCommand;

    /** @var MockObject|InputInterface */
    public $input;

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

    public function setUp()
    {
        $this->rootUpdateCommand = new RootUpdateCommand();
        $capability = $this->createPartialMock(Capability::class, ['getCommands']);
        $capability->method('getCommands')->willReturn([$this->rootUpdateCommand]);
        $pluginManager = $this->createPartialMock(PluginManager::class, ['getPluginCapabilities']);
        $pluginManager->method('getPluginCapabilities')->willReturn([$capability]);
        $input = $this->getMockForAbstractClass(InputInterface::class);
        $input->method('getFirstArgument')->willReturn('update');
        $input->method('getParameterOption')->with(['--working-dir', '-d'])->willReturn(false);
        $this->input = $input;
        $composer = $this->createPartialMock(Composer::class, ['getPluginManager']);
        $composer->method('getPluginManager')->willReturn($pluginManager);
        $this->application = new TestApplication();
        $this->application->setComposer($composer);
    }
}
