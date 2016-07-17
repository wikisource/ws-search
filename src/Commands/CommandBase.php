<?php

namespace App\Commands;

use App\Database;
use GetOptionKit\OptionParser;
use GetOptionKit\Exception\InvalidOptionException;
use GetOptionKit\OptionPrinter\ConsoleOptionPrinter;

abstract class CommandBase
{

    /** @var \GetOptionKit\OptionResult */
    protected $cliOptions;

    public function __construct($commandName, $args = [])
    {
        $options = $this->getCliOptions();
        $options->add('h|help', 'Get help about this subcommand.');
        $parser = new OptionParser($options);
        try {
            $this->cliOptions = $parser->parse($args);
            if ($this->cliOptions->help) {
                $printer = new ConsoleOptionPrinter();
                echo "The '$commandName' subcommand takes the following options:\n";
                echo $printer->render($options);
                exit();
            }
        } catch (InvalidOptionException $invalidOption) {
            $this->write($invalidOption->getMessage());
            exit(1);
        }
    }

    abstract protected function getCliOptions();

    /**
     * Write a line to the terminal.
     * @param string $message
     * @param boolean $newline Whether to include a newline at the end.
     * @return void
     */
    protected function write($message, $newline = true)
    {
        if (basename($_SERVER['SCRIPT_NAME']) !== 'cli') {
            // Only produce output when running the CLI tool.
            return;
        }
        echo $message . ($newline ? PHP_EOL : '');
    }

    /**
     * Write a message to the terminal ONLY IF debug mode is enabled.
     *
     * @param string $message
     * @param string $newline
     */
    public function writeDebug($message, $newline = true)
    {
        if (\App\Config::debug()) {
            $this->write($message, $newline);
        }
    }
}
