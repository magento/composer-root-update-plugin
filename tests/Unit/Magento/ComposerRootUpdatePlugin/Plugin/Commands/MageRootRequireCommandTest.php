<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Plugin\Commands;

use Composer\Composer;
use Composer\Plugin\Capability\Capability;
use Composer\Plugin\PluginManager;
use Magento\ComposerRootUpdatePlugin\TestHelpers\TestApplication;
use Magento\ComposerRootUpdatePlugin\UpdatePluginTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class RootUpdateCommandTest
 */
class MageRootRequireCommandTest extends UpdatePluginTestCase
{
    /** @var TestApplication */
    public $application;

    /** @var MageRootRequireCommand */
    public $command;

    /** @var MockObject|InputInterface */
    public $input;

    public function testOverwriteRequireCommand()
    {
        /** @var MockObject|OutputInterface $output */
        $output = $this->getMockForAbstractClass(OutputInterface::class);
        $this->input->method('getFirstArgument')->willReturn('require');

        $this->application->doRun($this->input, $output);

        $this->assertEquals($this->command, $this->application->getCalledCommand());
    }

    public function testCommandNoPlugins()
    {
        /** @var MockObject|OutputInterface $output */
        $output = $this->getMockForAbstractClass(OutputInterface::class);
        $this->input->method('getFirstArgument')->willReturn('require');
        $this->input->method('hasParameterOption')->willReturnMap([['--no-plugins', false, true]]);

        $this->application->doRun($this->input, $output);

        $this->assertNotEquals($this->command, $this->application->getCalledCommand());
    }

    public function setUp()
    {
        $this->command = new MageRootRequireCommand();
        $capability = $this->createPartialMock(Capability::class, ['getCommands']);
        $capability->method('getCommands')->willReturn([$this->command]);
        $pluginManager = $this->createPartialMock(PluginManager::class, ['getPluginCapabilities']);
        $pluginManager->method('getPluginCapabilities')->willReturn([$capability]);
        $input = $this->getMockForAbstractClass(InputInterface::class);
        $input->method('getParameterOption')->with(['--working-dir', '-d'])->willReturn(false);
        $this->input = $input;
        $composer = $this->createPartialMock(Composer::class, ['getPluginManager']);
        $composer->method('getPluginManager')->willReturn($pluginManager);
        $this->application = new TestApplication();
        $this->application->setComposer($composer);
    }
}
