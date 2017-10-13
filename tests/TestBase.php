<?php


class TestBase extends PHPUnit_Framework_TestCase {

	/** @var \App\DB\Database */
	protected $db;

	public function setUp() {
		// Bootstrap.
		parent::setUp();
		require_once __DIR__.'/../bootstrap.php';

		// Install.
		$upgrade = new \App\Commands\UpgradeCommand();
		$upgrade->run();
		$this->db = new App\Database();
	}

	public function tearDown() {
		// Remove all tables.
		$this->db->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		foreach ( $this->db->getTableNames( false ) as $tbl ) {
			$this->db->query( "DROP TABLE IF EXISTS `$tbl`" );
		}
		$this->db->query( 'SET FOREIGN_KEY_CHECKS = 1' );

		parent::tearDown();
	}
}
