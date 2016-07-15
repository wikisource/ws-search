<?php

namespace App\Commands;

class HelpCommand extends CommandBase
{

    public function getCliOptions()
    {
        return new \GetOptionKit\OptionCollection();
    }

    public function run()
    {
        $it = new \RecursiveDirectoryIterator(__DIR__, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        $commands = [];
        foreach ($files as $file) {
            $suffix = 'Command.php';
            if (substr($file->getBasename(), -strlen($suffix)) === $suffix) {
                $command = substr($file->getBasename(), 0, -strlen($suffix));
                $commands[] = \App\Text::snakecase($command, '-');
            }
        }
        $this->write("The following commands are available:");
        foreach ($commands as $cmd) {
            $this->write("   $cmd");
        }
    }
}
