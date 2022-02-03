<?php

namespace App;

use Doctrine\DBAL\Connection;
use Exception;
use Wikisource\Api\Edition;

class EditionSaver {

	/** @var Connection */
	protected $db;

	/**
	 * @param Connection $connection
	 */
	public function __construct( Connection $connection ) {
		$this->db = $connection;
	}

	/**
	 * @param string $langCode
	 * @return string
	 */
	public function getLangId( string $langCode ) {
		$sql = "SELECT id FROM languages WHERE code = :code";
		$langId = $this->db->executeQuery( $sql, [ 'code' => $langCode ] )->fetchOne();
		if ( !$langId ) {
			throw new Exception( "Unable to find ID of $langCode" );
		}
		return $langId;
	}

	/**
	 * @param Edition $edition
	 * @return array
	 */
	public function getEditionId( Edition $edition ) {
		$params = [
			'pagename' => $edition->getPageTitle(),
			'l' => $this->getLangId( $edition->getWikisource()->getLanguageCode() ),
		];
		$sqlSelect = 'SELECT `id` FROM `works` WHERE `language_id`=:l AND `pagename`=:pagename';
		return $this->db->executeQuery( $sqlSelect, $params )->fetchOne();
	}

	/**
	 * @param string $table
	 * @param string $pagename
	 * @param string $langId
	 * @return array
	 */
	public function getOrCreateRecord( $table, $pagename, $langId ) {
		$params = [ 'pagename' => $pagename, 'l' => $langId ];
		$sqlInsert = "INSERT IGNORE INTO `$table` SET `language_id`=:l, `pagename`=:pagename";
		$this->db->executeQuery( $sqlInsert, $params );
		$sqlSelect = "SELECT `id` FROM `$table` WHERE `language_id`=:l AND `pagename`=:pagename";
		return $this->db->executeQuery( $sqlSelect, $params )->fetchOne();
	}

	/**
	 * @param Edition $edition
	 */
	public function save( Edition $edition ) {
		// Save basic edition (formerly 'work') data to the database. It might already be there, if this is a subpage,
		// in which case we don't really care that this won't do anything, and this is just as fast as checking whether
		// it exists or not.
		$sql = "INSERT INTO `works` SET"
			. " `language_id`=:lid,"
			. " `wikidata_item`=:wikidata_item,"
			. " `pagename`=:pagename,"
			. " `title`=:title,"
			. " `year`=:year"
			. " ON DUPLICATE KEY UPDATE "
			. " `wikidata_item`=:wikidata_item,"
			. " `title`=:title,"
			. " `year`=:year";
		$langId = $this->getLangId( $edition->getWikisource()->getLanguageCode() );
		$insertParams = [
			'lid' => $langId,
			'wikidata_item' => $edition->getWikidataItemNumber(),
			'pagename' => $edition->getPageTitle(),
			'title' => $edition->getTitle(),
			'year' => $edition->getYear(),
		];
		$this->db->executeQuery( $sql, $insertParams );
		$editionId = $this->getEditionId( $edition );
		if ( !is_numeric( $editionId ) ) {
			// Eh? What's going on here then?
			throw new Exception(
				"Failed to save pagename: " . $edition->getPageTitle() . " -- params were: "
				. var_export( $insertParams, true ) );
		}

		// Save the authors.
		foreach ( $edition->getAuthors() as $author ) {
			$authorId = $this->getOrCreateRecord( 'authors', $author, $langId );
			$sqlAuthorLink = 'INSERT IGNORE INTO `authors_works` SET author_id=:a, work_id=:w';
			$this->db->executeQuery( $sqlAuthorLink, [ 'a' => $authorId, 'w' => $editionId ] );
		}

		// Link the Index pages.
		foreach ( $edition->getIndexPages() as $indexPage ) {
			// Link to the work.
			$indexPageId = $this->getOrCreateRecord( 'index_pages', $indexPage->getTitle(), $langId );
			$sqlInsertIndexes = 'INSERT IGNORE INTO `works_indexes` SET index_page_id=:ip, work_id=:w';
			$paramsIndexes = [
				'ip' => $indexPageId,
				'w' => $editionId,
			];
			$this->db->executeQuery( $sqlInsertIndexes, $paramsIndexes );

			// Quality and image.
			if ( $indexPage->getQuality() ) {
				$sql = "UPDATE index_pages "
					. " SET cover_image_url=:image, quality=:quality "
					. " WHERE id = :id ";
				$params = [
					'image' => $indexPage->getImage(),
					'quality' => $indexPage->getQuality(),
					'id' => $indexPageId,
				];
				$this->db->executeQuery( $sql, $params );
			}
		}
	}
}
