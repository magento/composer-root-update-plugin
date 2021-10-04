<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Utils;

use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Exception;
use InvalidArgumentException;

/**
 * Singleton logger with console interaction methods
 */
class Console
{
    /**
     * Log message formatting tags
     */
    public const FORMAT_INFO = '<info>';
    public const FORMAT_COMMENT = '<comment>';
    public const FORMAT_WARN = '<warning>';
    public const FORMAT_ERROR = '<error>';

    /**
     * Verbosity levels copied from IOInterface for clarity
     */
    public const QUIET = IOInterface::QUIET;
    public const NORMAL = IOInterface::NORMAL;
    public const VERBOSE = IOInterface::VERBOSE;
    public const VERY_VERBOSE = IOInterface::VERY_VERBOSE;
    public const DEBUG = IOInterface::DEBUG;

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
     * @param IOInterface|null $io
     * @param bool $interactive
     * @param string|null $verboseLabel
     * @return void
     */
    public function __construct(?IOInterface $io = null, bool $interactive = false, ?string $verboseLabel = null)
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
    public function getIO(): IOInterface
    {
        return $this->io;
    }

    /**
     * Whether or not ask() should interactively ask the question or just return the default value
     *
     * @param bool $interactive
     * @return void
     */
    public function setInteractive(bool $interactive)
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
    public function ask(string $question, bool $default = false): bool
    {
        $result = $default;
        if ($this->interactive) {
            if (!$this->getIO()->isInteractive()) {
                throw new InvalidArgumentException(
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
     * @param string $message
     * @param int $verbosity
     * @param string|null $format
     * @return void
     */
    public function log(string $message, int $verbosity = Console::NORMAL, ?string $format = null)
    {
        if ($format) {
            $message = $this->formatString($message, $format);
        }
        $this->getIO()->writeError($message, true, $verbosity);
    }

    /**
     * Helper method to log the given message with <info> formatting
     *
     * @param string $message
     * @param int $verbosity
     * @return void
     */
    public function info(string $message, int $verbosity = Console::NORMAL)
    {
        $this->log($message, $verbosity, self::FORMAT_INFO);
    }

    /**
     * Helper method to log the given message with <comment> formatting
     *
     * @param string $message
     * @param int $verbosity
     * @return void
     */
    public function comment(string $message, int $verbosity = Console::NORMAL)
    {
        $this->log($message, $verbosity, self::FORMAT_COMMENT);
    }

    /**
     * Helper method to log the given message with <warning> formatting
     *
     * @param string $message
     * @param int $verbosity
     * @return void
     */
    public function warning(string $message, int $verbosity = Console::NORMAL)
    {
        $this->log($message, $verbosity, self::FORMAT_WARN);
    }

    /**
     * Label and log the given message if output is set to verbose
     *
     * A null $label will use the globally configured $verboseLabel
     *
     * @param string $message
     * @param string|null $label
     * @param int $verbosity
     * @param string|null $format
     * @return void
     */
    public function labeledVerbose(
        string $message,
        ?string $label = null,
        int $verbosity = Console::VERBOSE,
        ?string $format = null
    ) {
        if ($format) {
            $message = $this->formatString($message, $format);
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
     * @param Exception|null $exception
     * @return void
     */
    public function error(string $message, ?Exception $exception = null)
    {
        $this->log($message, self::QUIET, self::FORMAT_ERROR);
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
    public function setVerboseLabel(string $verboseLabel)
    {
        $this->verboseLabel = $verboseLabel;
    }

    /**
     * Helper function to wrap a string in the given format tag
     *
     * @param string $str
     * @param string $formatTag
     * @return string
     */
    public function formatString(string $str, string $formatTag): string
    {
        $formatClose = str_replace('<', '</', $formatTag);
        return $formatTag . $str . $formatClose;
    }
}
