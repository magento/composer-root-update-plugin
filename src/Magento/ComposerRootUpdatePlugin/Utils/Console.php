<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Utils;

use Composer\IO\IOInterface;
use Composer\IO\NullIO;

/**
 * Singleton logger with console interaction methods
 */
class Console
{
    /**
     * Log message formatting tags
     */
    const FORMAT_INFO = '<info>';
    const FORMAT_COMMENT = '<comment>';
    const FORMAT_WARN = '<warning>';
    const FORMAT_ERROR = '<error>';

    /**
     * Verbosity levels copied from IOInterface for clarity
     */
    const QUIET = IOInterface::QUIET;
    const NORMAL = IOInterface::NORMAL;
    const VERBOSE = IOInterface::VERBOSE;
    const VERY_VERBOSE = IOInterface::VERY_VERBOSE;
    const DEBUG = IOInterface::DEBUG;

    /**
     * @var IOInterface $io
     */
    protected $io;

    /**
     * @var string $verboseLabel
     */
    protected $verboseLabel;

    /**
     * @var bool $interactive
     */
    protected $interactive;

    /**
     * Console constructor.
     *
     * @param IOInterface $io
     * @param bool $interactive
     * @param string $verboseLabel
     * @return void
     */
    public function __construct($io, $interactive = false, $verboseLabel = null)
    {
        if ($io === null) {
            $this->io = new NullIO();
        } else {
            $this->io = $io;
        }
        $this->verboseLabel = $verboseLabel;
        $this->interactive = $interactive;
    }

    /**
     * Get the Composer IOInterface instance
     *
     * @return IOInterface
     */
    public function getIO()
    {
        return $this->io;
    }

    /**
     * Whether or not ask() should interactively ask the question or just return the default value
     *
     * @param bool $interactive
     * @return void
     */
    public function setInteractive($interactive)
    {
        $this->interactive = $interactive;
    }

    /**
     * Ask the user a yes or no question and return the result
     *
     * If the console is not interactive, instead do not ask and just return the default
     *
     * @param string $question
     * @param bool $default
     * @return bool
     */
    public function ask($question, $default = false)
    {
        $result = $default;
        if ($this->interactive) {
            if (!$this->getIO()->isInteractive()) {
                throw new \InvalidArgumentException(
                    'Interactive options cannot be used in non-interactive terminals.'
                );
            }
            $opts = $default ? 'Y,n' : 'y,N';
            $result = $this->getIO()->askConfirmation("<info>$question</info> [<comment>$opts</comment>]? ", $default);
        }
        return $result;
    }

    /**
     * Log the given message with verbosity and formatting
     *
     * @param $message
     * @param int $verbosity
     * @param string $format
     * @return void
     */
    public function log($message, $verbosity = Console::NORMAL, $format = null)
    {
        if ($format) {
            $formatClose = str_replace('<', '</', $format);
            $message = "$format$message$formatClose";
        }
        $this->getIO()->writeError($message, true, $verbosity);
    }

    /**
     * Helper method to log the given message with <info> formatting
     *
     * @param $message
     * @param int $verbosity
     * @return void
     */
    public function info($message, $verbosity = Console::NORMAL)
    {
        $this->log($message, $verbosity, static::FORMAT_INFO);
    }

    /**
     * Helper method to log the given message with <comment> formatting
     *
     * @param $message
     * @param int $verbosity
     * @return void
     */
    public function comment($message, $verbosity = Console::NORMAL)
    {
        $this->log($message, $verbosity, static::FORMAT_COMMENT);
    }

    /**
     * Helper method to log the given message with <warning> formatting
     *
     * @param $message
     * @param int $verbosity
     * @return void
     */
    public function warning($message, $verbosity = Console::NORMAL)
    {
        $this->log($message, $verbosity, static::FORMAT_WARN);
    }

    /**
     * Label and log the given message if output is set to verbose
     *
     * A null $label will use the globally configured $verboseLabel
     *
     * @param string $message
     * @param null $label
     * @param int $verbosity
     * @param string $format
     * @return void
     */
    public function labeledVerbose(
        $message,
        $label = null,
        $verbosity = Console::VERBOSE,
        $format = null
    ) {
        if ($format) {
            $formatClose = str_replace('<', '</', $format);
            $message = "$format$message$formatClose";
        }
        if ($label === null) {
            $label = $this->verboseLabel;
        }
        if ($label) {
            $message = " <comment>[</comment>$label<comment>]</comment> $message";
        }
        $this->log($message, $verbosity);
    }

    /**
     * Formats with <error> and logs to Console::QUIET followed by the exception's message at Console::NORMAL
     *
     * @param string $message
     * @param \Exception $exception
     * @return void
     */
    public function error($message, $exception = null)
    {
        $this->log($message, static::QUIET, static::FORMAT_ERROR);
        if ($exception) {
            $this->log($exception->getMessage());
        }
    }

    /**
     * Sets the label to apply to labeledVerbose() messages if not overridden
     *
     * @param string $verboseLabel
     * @return void
     */
    public function setVerboseLabel($verboseLabel)
    {
        $this->verboseLabel = $verboseLabel;
    }
}
