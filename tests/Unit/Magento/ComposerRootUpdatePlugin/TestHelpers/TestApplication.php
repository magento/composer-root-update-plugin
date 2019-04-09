<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\TestHelpers;

use Composer\Composer;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class TestApplication
 */
class TestApplication extends \Composer\Console\Application
{
    /**
     * @var boolean
     */
    private $shouldRun = false;

    /**
     * @var Command
     */
    private $command = null;

    /**
     * Pass in a mock Composer object for unit testing
     *
     * @param MockObject|Composer $composer
     * @return void
     */
    public function setComposer(Composer $composer)
    {
        $this->composer = $composer;
    }

    /**
     * Set whether or not doRunCommand should actually be run or not
     *
     * @param boolean $shouldRun
     * @return void
     */
    public function setShouldRun($shouldRun)
    {
        $this->shouldRun = $shouldRun;
    }

    /**
     * Captures the called command for testing and executes the command if $shouldRun is true
     *
     * @param Command $command
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Throwable
     */
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output)
    {
        $this->command = $command;
        if ($this->shouldRun) {
            return parent::doRunCommand($command, $input, $output);
        } else {
            return 0;
        }
    }

    /**
     * Get the Command that was passed to doRunCommand
     *
     * @return Command
     */
    public function getCalledCommand()
    {
        return $this->command;
    }
}
