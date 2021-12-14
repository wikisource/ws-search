<?php

namespace App\Database;

use Exception;
use Mediawiki\Api\FluentRequest;
use Symfony\Component\DomCrawler\Crawler;
use Wikisource\Api\IndexPage;
use Wikisource\Api\Work;
use Doctrine\DBAL\Connection;

class WorkSaver {

	/** @var Connection */
	protected $db;

	public function __construct( Connection $connection ) {
		$this->db = $connection;
	}

	public function getLangId( $langCode ) {
		$sql = "SELECT id FROM languages WHERE code = :code";
		$langId = $this->db->executeQuery( $sql, [ 'code' => $langCode ] )->fetchOne();
		if ( !$langId ) {
			throw new Exception( "Unable to find ID of $langCode" );
		}
		return $langId;
	}

	public function getWorkId( Work $work ) {
		$params = [
			'pagename' => $work->getPageTitle(),
			'l' => $this->getLangId( $work->getWikisource()->getLanguageCode() ),
		];
		$sqlSelect = 'SELECT `id` FROM `works` WHERE `language_id`=:l AND `pagename`=:pagename';
		return $this->db->executeQuery( $sqlSelect, $params )->fetchOne();
	}

	public function getOrCreateRecord( $table, $pagename, $langId ) {
		$params = [ 'pagename' => $pagename, 'l' => $langId ];
		$sqlInsert = "INSERT IGNORE INTO `$table` SET `language_id`=:l, `pagename`=:pagename";
		$this->db->executeQuery( $sqlInsert, $params );
		$sqlSelect = "SELECT `id` FROM `$table` WHERE `language_id`=:l AND `pagename`=:pagename";
		return $this->db->executeQuery( $sqlSelect, $params )->fetchOne();
	}

	public function save( Work $work ) {
		// Save basic work data to the database. It might already be there, if this is a subpage, in which case we don't
		// really care that this won't do anything, and this is just as fast as checking whether it exists or not.
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
		$langId = $this->getLangId( $work->getWikisource()->getLanguageCode() );
		$insertParams = [
			'lid' => $langId,
			'wikidata_item' => $work->getWikidataItemNumber(),
			'pagename' => $work->getPageTitle(),
			'title' => $work->getWorkTitle(),
			'year' => $work->getYear(),
		];
		$this->db->executeQuery( $sql, $insertParams );
		$workId = $this->getWorkId( $work );
		if ( !is_numeric( $workId ) ) {
			// Eh? What's going on here then?
			throw new Exception(
				"Failed to save pagename: ".$work->getPageTitle()." -- params were: "
				.var_export( $insertParams, true ) );
		}

		// Save the authors.
		foreach ( $work->getAuthors() as $author ) {
			$authorId = $this->getOrCreateRecord( 'authors', $author, $langId );
			$sqlAuthorLink = 'INSERT IGNORE INTO `authors_works` SET author_id=:a, work_id=:w';
			$this->db->executeQuery( $sqlAuthorLink, [ 'a' => $authorId, 'w' => $workId ] );
		}

		// Link the Index pages.
		foreach ( $work->getIndexPages() as $indexPage ) {
			// Link to the work.
			$indexPageId = $this->getOrCreateRecord( 'index_pages', $indexPage->getTitle(), $langId );
			$sqlInsertIndexes = 'INSERT IGNORE INTO `works_indexes` SET index_page_id=:ip, work_id=:w';
			$paramsIndexes = [
				'ip' => $indexPageId,
				'w' => $workId,
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

			// Publisher.
			/*if ( !empty( $microformats['publisher'] ) ) {
				$this->db->query( 'INSERT IGNORE INTO publishers SET name=:publisher, location=:place', $microformats );
				$publisherSql = 'SELECT id FROM publishers WHERE name=:name';
				$publisherId = $this->db->query( $publisherSql, [ 'name'=>$microformats['publisher'] ] )->fetchColumn();
				if ( !$publisherId ) {
					$this->write( "Unable to save publisher for $indexPageName -- publisher: ".$microformats['publisher'] );
				} else {
					$this->db->query( 'UPDATE works SET publisher_id=:p WHERE id=:w', [ 'w'=>$workId, 'p'=>$publisherId ] );
				}
			}*/

		}
	}

	public function getIndexPageMetadata( IndexPage $indexPage, $workId ) {
		$requestProps = FluentRequest::factory()
			->setAction( 'parse' )
			->setParam( 'page', $indexPage->getTitle() )
			->setParam( 'prop', 'text' );
		$pageProps = $this->completeQuery( $requestProps, 'parse' );
		$pageHtml = $pageProps['text']['*'];
		$pageCrawler = new Crawler;
		$pageCrawler->addHtmlContent( $pageHtml, 'UTF-8' );

		// Find a possible cover image.
		$coverRegex = '/\/\/upload\.wikimedia\.org\/(.*?)\.(djvu|pdf)\.jpg/';
		preg_match( $coverRegex, $pageHtml, $matches );
		$coverImage = isset( $matches[0] ) ? $matches[0] : null;

		// Save all the metadata (the record already exists before now).
		if ( !empty( $coverImage ) || !empty( $quality ) ) {
			$sql = "UPDATE index_pages SET cover_image_url=:cover, quality=:quality WHERE pagename=:pagename AND language_id=:lang";
			$params = [ 'cover' => $coverImage, 'quality' => $quality, 'pagename' => $indexPageName, 'lang'=>$this->currentLang->id ];
			$this->db->query( $sql, $params );
		}

		// Get the publisher's name and location.
		$microformats = [ 'publisher'=>null, 'place'=>null ];
		foreach ( array_keys( $microformats ) as $microformat ) {
			$el = $pageCrawler->filterXPath( "//*[@id='ws-$microformat']" );
			if ( $el->count() > 0 ) {
				$microformats[$microformat] = strip_tags( $el->html() );
			}
		}
	}
}
