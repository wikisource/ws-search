<?php

namespace App\Commands;

use GetOptionKit\OptionParser;

abstract class CommandBase
{

    /** @var \GetOptionKit\OptionResult */
    protected $options;

//    public function __construct($args = [])
//    {
//        //$printer = new ConsoleOptionPrinter();
//        //echo $printer->render($specs);
//        $specs = $this->getSpecs();
//        $specs->add('l|lang:=string', "Language code of the Wikisource to scrape.")
//            ->defaultValue('en');
//        $specs->add('o|output:=string', "Output path for the generated files. Will be created if it doesn't exist.")
//            ->defaultValue(__DIR__ . '/../library');
//        $specs->add('h|help', "Display command help.");
//        $parser = new OptionParser($specs);
//        try {
//            $result = $parser->parse($args);
//            print_r($result);
//            $lang = $result->lang;
//            $outputDir = $result->output;
//            if ($result->help) {
//                $printer = new ConsoleOptionPrinter();
//                echo $printer->render($specs);
//                exit(0);
//            }
//        } catch (Exception $e) {
//            echo $e->getMessage() . "\nUse --help for details of valid arguments\n";
//            exit();
//        }
//        exit();
//    }
    public function setCliOptions(\GetOptionKit\OptionResult $options)
    {
        $this->options = $options;
    }

    abstract public function getCliOptions();

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
}
