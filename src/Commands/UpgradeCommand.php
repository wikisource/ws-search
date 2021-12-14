<?php

namespace App\Commands;

use App\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\Connection;

class UpgradeCommand extends Command {

	/** @var OutputInterface */
	protected $out;

	/**
	 * @param string $name
	 * @param Connection $connection
	 */
	public function __construct( $name = 'upgrade', Connection $connection ) {
		parent::__construct( $name );
		$this->db = $connection;
	}

	/**
	 *
	 */
	protected function configure() {
		$this->setName( 'upgrade' );
		$this->setDescription( 'Install or upgrade the WS Search database.' );
		$this->addOption( 'nuke', null, InputOption::VALUE_NONE );
	}

	/**
	 * @param InputInterface $input The input.
	 * @param OutputInterface $output The output.
	 * @return null|int null or 0 if everything went fine, or an error code
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$this->out = $output;
		if ( $input->getOption( 'nuke' ) ) {
			$this->nukeData();
		}
		$this->installStructure();
		$output->writeln( "Upgrade complete; now running version " . Config::version() );
		return Command::SUCCESS;
	}

	private function nukeData() {
		$this->out->writeln( "Deleting all data in the database!" );
		$db = $this->db;
		$db->query( "SET foreign_key_checks = 0" );
		$db->query( "DROP TABLE IF EXISTS `works_indexes`" );
		$db->query( "DROP TABLE IF EXISTS `index_pages`" );
		$db->query( "DROP TABLE IF EXISTS `authors_works`" );
		$db->query( "DROP TABLE IF EXISTS `authors`" );
		$db->query( "DROP TABLE IF EXISTS `works`" );
		$db->query( "DROP TABLE IF EXISTS `languages`" );
		$db->query( "DROP TABLE IF EXISTS `publishers`" );
		$db->query( "SET foreign_key_checks = 1" );
	}

	/**
	 */
	protected function installStructure() {
		$db = $this->db;
		$charset = "CHARACTER SET utf8 COLLATE utf8_unicode_ci";
		if ( !$this->tableExists( 'languages' ) ) {
			$this->out->writeln( "Creating table 'languages'" );
			$db->query( "CREATE TABLE `languages` ("
				. " `id` INT(4) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
				. " `code` VARCHAR(10) $charset NOT NULL UNIQUE, "
				. " `label` VARCHAR(200) $charset NOT NULL, "
				. " `index_ns_id` INT(3) NULL DEFAULT NULL "
				. ");" );
		}
		if ( !$this->tableExists( 'publishers' ) ) {
			$this->out->writeln( "Creating table 'publishers'" );
			$db->query( "CREATE TABLE `publishers` ("
				. " `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
				. " `name` VARCHAR(255) $charset NOT NULL UNIQUE, "
				. " `location` TEXT $charset NULL DEFAULT NULL"
				. ");" );
		}
		if ( !$this->tableExists( 'works' ) ) {
			$this->out->writeln( "Creating table 'works'" );
			$db->query( "CREATE TABLE `works` ("
				. " `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
				. " `language_id` INT(4) UNSIGNED NOT NULL, "
				. " FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE, "
				. " `wikidata_item` VARCHAR(30) NULL DEFAULT NULL UNIQUE, "
				. " `pagename` VARCHAR(255) $charset NOT NULL, "
				. " `title` VARCHAR(1000) $charset NOT NULL, "
				. " `year` VARCHAR(100) $charset NULL DEFAULT NULL, "
				. " UNIQUE KEY (`language_id`, `pagename`),"
				. " `publisher_id` INT(10) UNSIGNED NULL DEFAULT NULL, "
				. " FOREIGN KEY (`publisher_id`) REFERENCES `publishers` (`id`) ON DELETE CASCADE"
				. ");" );
		}
		if ( !$this->tableExists( 'authors' ) ) {
			$this->out->writeln( "Creating table 'authors'" );
			$db->query( "CREATE TABLE `authors` ("
				. " `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
				. " `language_id` INT(4) UNSIGNED NOT NULL, "
				. " FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE, "
				. " `wikidata_item` VARCHAR(30) NULL DEFAULT NULL UNIQUE, "
				. " `pagename` VARCHAR(255) $charset NOT NULL,"
				. " UNIQUE KEY (`language_id`, `pagename`) "
				. ");" );
		}
		if ( !$this->tableExists( 'authors_works' ) ) {
			$this->out->writeln( "Creating table 'authors_works'" );
			$db->query( "CREATE TABLE `authors_works` ("
				. " `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
				. " `author_id` INT(10) UNSIGNED NOT NULL, "
				. " FOREIGN KEY (`author_id`) REFERENCES `authors` (`id`) ON DELETE CASCADE,"
				. " `work_id` INT(10) UNSIGNED NOT NULL, "
				. " FOREIGN KEY (`work_id`) REFERENCES `works` (`id`) ON DELETE CASCADE, "
				. " UNIQUE KEY (`author_id`, `work_id`) "
				. ");" );
		}
		if ( !$this->tableExists( 'index_pages' ) ) {
			$this->out->writeln( "Creating table 'index_pages'" );
			$db->query( "CREATE TABLE `index_pages` ("
				. " `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
				. " `language_id` INT(4) UNSIGNED NOT NULL, "
				. " FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE, "
				. " `pagename` VARCHAR(255) $charset NOT NULL, "
				. " UNIQUE KEY (`language_id`, `pagename`), "
				. " `cover_image_url` TEXT $charset NULL DEFAULT NULL,"
				. " `quality` INT(1) NULL DEFAULT NULL "
				. ");" );
		}
		if ( !$this->tableExists( 'works_indexes' ) ) {
			$this->out->writeln( "Creating table 'works_indexes'" );
			$db->query( "CREATE TABLE `works_indexes` ("
				. " `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
				. " `work_id` INT(10) UNSIGNED NOT NULL, "
				. " FOREIGN KEY (`work_id`) REFERENCES `works` (`id`) ON DELETE CASCADE, "
				. " `index_page_id` INT(10) UNSIGNED NOT NULL, "
				. " FOREIGN KEY (`index_page_id`) REFERENCES `index_pages` (`id`) ON DELETE CASCADE,"
				. " UNIQUE KEY (`index_page_id`, `work_id`)"
				. ");" );
		}
	}

	/**
	 * @param string $tableName The table name to check for.
	 * @return bool
	 */
	protected function tableExists( $tableName ) {
		return $this->db->executeStatement( 'SHOW TABLES LIKE ?', [ $tableName ] ) === 1;
	}
}
