<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Plugin\Commands;

use Composer\Command\BaseCommand;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\ComposerRootUpdatePlugin\Setup\WebSetupWizardPluginInstaller;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Namespace for any plugin-specific operations that do not fit under other commands
 *
 * Checks the first argument for the actual function to run
 */
class UpdatePluginNamespaceCommands extends BaseCommand
{
    const NAME = 'magento-update-plugin';

    /**
     * @var Console $console
     */
    protected $console;

    /**
     * Map of operation command to description
     *
     * @var array $operations
     */
    protected static $operations = [
        'list' =>
            "List all operations available in the <comment>%command.name%</comment> namespace. This is equivalent\n".
            'to running <comment>%command.full_name%</comment> without an operation.',
        'install' =>
            "Refresh the plugin's installation for the Magento Web Setup Wizard. This may be \n" .
            "necessary if the <info>var</info> folder has been cleaned, as the plugin needs to exist there\n" .
            'in order to be functional for the Wizard\'s dependency verification check.'
    ];

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $help = "The <info>%command.name% <operation></info> commands are operations specific to the\n" .
            "<info>magento/composer-root-update-plugin</info> functionality that do not belong to any native\n" .
            "composer commands.\n\n" . $this->describeOperations() . "\n";

        $this->setName(static::NAME)
            ->setDescription('Operations specific to magento/composer-root-update-plugin')
            ->setDefinition([new InputArgument('operation', InputArgument::OPTIONAL, 'The operation to execute')])
            ->setHelp($help);
    }

    /**
     * Install the plugin in var to make it available for composer commands run there by the Web Setup Wizard
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->console = new Console($this->getIO());
        $operation = $input->getArgument('operation');
        if (empty($operation) || $operation == 'list') {
            $this->console->log($this->describeOperations() . "\n");
            return 0;
        }
        if ($operation == 'install') {
            $setupWizardInstaller = new WebSetupWizardPluginInstaller($this->console);
            return $setupWizardInstaller->doVarInstall();
        } else {
            $this->console->error("'$operation' is not a supported operation for " . static::NAME);
            return 1;
        }
    }

    /**
     * Formats the operation definitions into the help/list output
     *
     * @return string
     */
    protected function describeOperations()
    {
        $output = '<comment>Available operations:</comment>';
        foreach (static::$operations as $operation => $description) {
            $output = $output . "\n\n  <info>php %command.full_name% $operation</info>\n\n$description";
        }
        $output = str_replace('%command.name%', static::NAME, $output);
        $output = str_replace('%command.full_name%', 'composer ' . static::NAME, $output);
        return $output;
    }
}
