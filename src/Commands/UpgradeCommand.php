<?php

namespace App\Commands;

use App\Config;
use App\Database;
use GetOptionKit\OptionCollection;

class UpgradeCommand extends \App\Commands\CommandBase
{

    public function getCliOptions()
    {
        $specs = new OptionCollection;
        $specs->add('nuke', 'Destroy all existing data before upgrading.');
        return $specs;
    }

    public function run()
    {
        $db = new Database();
        if ($this->cliOptions->nuke) {
            $this->nukeData($db);
        }
        $this->installStructure($db);
        $this->write("Upgrade complete; now running version " . Config::version());
    }

    private function nukeData(Database $db)
    {
        $this->write("Deleting all data in the database!");
        $db->query("SET foreign_key_checks = 0");
        $db->query("TRUNCATE `works_indexes`");
        $db->query("TRUNCATE `index_pages`");
        $db->query("TRUNCATE `authors_works`");
        $db->query("TRUNCATE `authors`");
        $db->query("TRUNCATE `works`");
        $db->query("TRUNCATE `languages`");
        $db->query("SET foreign_key_checks = 1");
    }

    protected function installStructure(Database $db)
    {
        $charset = "CHARACTER SET utf8 COLLATE utf8_unicode_ci";
        if (!$this->tableExists($db, 'languages')) {
            $this->write("Creating table 'languages'");
            $db->query("CREATE TABLE `languages` ("
                . " `id` INT(4) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
                . " `code` VARCHAR(10) $charset NOT NULL UNIQUE, "
                . " `label` VARCHAR(200) $charset NOT NULL, "
                . " `index_ns_id` INT(3) NULL DEFAULT NULL "
                . ");");
        }
        if (!$this->tableExists($db, 'works')) {
            $this->write("Creating table 'works'");
            $db->query("CREATE TABLE `works` ("
                . " `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
                . " `language_id` INT(4) UNSIGNED NOT NULL, "
                . " FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`), "
                . " `wikidata_item` VARCHAR(30) NULL DEFAULT NULL UNIQUE, "
                . " `pagename` VARCHAR(255) $charset NOT NULL, "
                . " `title` VARCHAR(255) $charset NOT NULL, "
                . " `year` VARCHAR(100) $charset NULL DEFAULT NULL, "
                . " UNIQUE KEY (`language_id`, `pagename`) "
                . ");");
        }
        if (!$this->tableExists($db, 'authors')) {
            $this->write("Creating table 'authors'");
            $db->query("CREATE TABLE `authors` ("
                . " `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
                . " `language_id` INT(4) UNSIGNED NOT NULL, "
                . " FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`), "
                . " `wikidata_item` VARCHAR(30) NULL DEFAULT NULL UNIQUE, "
                . " `pagename` VARCHAR(255) $charset NOT NULL,"
                . " UNIQUE KEY (`language_id`, `pagename`) "
                . ");");
        }
        if (!$this->tableExists($db, 'authors_works')) {
            $this->write("Creating table 'authors_works'");
            $db->query("CREATE TABLE `authors_works` ("
                . " `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
                . " `author_id` INT(10) UNSIGNED NOT NULL, "
                . " FOREIGN KEY (`author_id`) REFERENCES `authors` (`id`),"
                . " `work_id` INT(10) UNSIGNED NOT NULL, "
                . " FOREIGN KEY (`work_id`) REFERENCES `works` (`id`), "
                . " UNIQUE KEY (`author_id`, `work_id`) "
                . ");");
        }
        if (!$this->tableExists($db, 'index_pages')) {
            $this->write("Creating table 'index_pages'");
            $db->query("CREATE TABLE `index_pages` ("
                . " `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
                . " `language_id` INT(4) UNSIGNED NOT NULL, "
                . " FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`), "
                . " `pagename` VARCHAR(255) $charset NOT NULL, "
                . " UNIQUE KEY (`language_id`, `pagename`) "
                . ");");
        }
        if (!$this->tableExists($db, 'works_indexes')) {
            $this->write("Creating table 'works_indexes'");
            $db->query("CREATE TABLE `works_indexes` ("
                . " `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
                . " `work_id` INT(10) UNSIGNED NOT NULL, "
                . " FOREIGN KEY (`work_id`) REFERENCES `works` (`id`), "
                . " `index_page_id` INT(10) UNSIGNED NOT NULL, "
                . " FOREIGN KEY (`index_page_id`) REFERENCES `index_pages` (`id`),"
                . " UNIQUE KEY (`index_page_id`, `work_id`) "
                . ");");
        }
    }

    protected function tableExists(Database $db, $tableName)
    {
        return $db->query('SHOW TABLES LIKE :t', ['t' => $tableName])->rowCount() === 1;
    }
}
