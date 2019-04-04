<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Utils;

use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Helper\DebugFormatterHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

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
    static protected $io = null;

    /**
     * @var string $verboseLabel
     */
    static protected $verboseLabel = null;

    /**
     * @var bool $interactive
     */
    static protected $interactive = false;

    /**
     * Get the shared IOInterface instance or a default ConsoleIO if one hasn't been set via setIO()
     *
     * @return IOInterface
     */
    static public function getIO()
    {
        if (static::$io == null) {
            static::$io = new ConsoleIO(new ArrayInput([]),
                new ConsoleOutput(OutputInterface::VERBOSITY_DEBUG),
                new HelperSet([
                    new FormatterHelper(),
                    new DebugFormatterHelper(),
                    new ProcessHelper(),
                    new QuestionHelper()
                ])
            );
        }
        return static::$io;
    }

    /**
     * Set the shared IOInterface instance
     *
     * @param IOInterface $io
     * @return void
     */
    static public function setIO($io)
    {
        static::$io = $io;
    }

    /**
     * Whether or not ask() should interactively ask the question or just return the default value
     *
     * @param bool $interactive
     * @return void
     */
    public static function setInteractive($interactive)
    {
        self::$interactive = $interactive;
    }

    /**
     * Ask the user a yes or no question and return the result
     *
     * If setInteractive(false) has been called, instead do not ask and just return the default
     *
     * @param string $question
     * @param boolean $default
     * @return boolean
     */
    static public function ask($question, $default = false)
    {
        $result = $default;
        if (static::$interactive) {
            if (!static::getIO()->isInteractive()) {
                throw new \InvalidArgumentException(
                    'Interactive options cannot be used in non-interactive terminals.'
                );
            }
            $opts = $default ? 'Y,n' : 'y,N';
            $result = static::getIO()->askConfirmation("<info>$question</info> [<comment>$opts</comment>]? ", $default);
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
    static public function log($message, $verbosity = Console::NORMAL, $format = null)
    {
        if ($format) {
            $formatClose = str_replace('<', '</', $format);
            $message = "${format}${message}${formatClose}";
        }
        static::getIO()->writeError($message, true, $verbosity);
    }

    /**
     * Helper method to log the given message with <info> formatting
     *
     * @param $message
     * @param int $verbosity
     * @return void
     */
    static public function info($message, $verbosity = Console::NORMAL)
    {
        static::log($message, $verbosity, static::FORMAT_INFO);
    }

    /**
     * Helper method to log the given message with <comment> formatting
     *
     * @param $message
     * @param int $verbosity
     * @return void
     */
    static public function comment($message, $verbosity = Console::NORMAL)
    {
        static::log($message, $verbosity, static::FORMAT_COMMENT);
    }

    /**
     * Helper method to log the given message with <warning> formatting
     *
     * @param $message
     * @param int $verbosity
     * @return void
     */
    static public function warning($message, $verbosity = Console::NORMAL)
    {
        static::log($message, $verbosity, static::FORMAT_WARN);
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
    static public function labeledVerbose(
        $message,
        $label = null,
        $verbosity = Console::VERBOSE,
        $format = null
    ) {
        if ($format) {
            $formatClose = str_replace('<', '</', $format);
            $message = "${format}${message}${formatClose}";
        }
        if ($label === null) {
            $label = static::$verboseLabel;
        }
        if ($label) {
            $message = " <comment>[</comment>$label<comment>]</comment> $message";
        }
        static::log($message, $verbosity);
    }

    /**
     * Formats with <error> and logs to Console::QUIET followed by the exception's message at Console::NORMAL
     *
     * @param string $message
     * @param \Exception $exception
     * @return void
     */
    static public function error($message, $exception = null)
    {
        static::log($message, static::QUIET, static::FORMAT_ERROR);
        if ($exception) {
            static::log($exception->getMessage());
        }
    }

    /**
     * Sets the label to apply to logVerbose() messages if not overridden
     *
     * @param string $verboseLabel
     * @return void
     */
    static public function setVerboseLabel($verboseLabel)
    {
        static::$verboseLabel = $verboseLabel;
    }
}
