<?php

namespace App\Controller;

use App\Text;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
// phpcs:ignore
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends ControllerBase {

	/**
	 * phpcs:disable
	 * @Route("/", name="home")
	 * phpcs:enable
	 * @param Request $request
	 * @return Response
	 */
	public function index( Request $request ) {
		// Output format.
		$outputFormats = [
			'list' => 'List',
			'table' => 'Table',
			'csv' => 'CSV',
			'opds' => 'OPDS',
		];
		$defaultOutputFormat = 'table';
		$outputFormat = $request->get( 'output_format', $defaultOutputFormat );
		if ( !array_key_exists( $outputFormat, $outputFormats ) ) {
			$outputFormat = $defaultOutputFormat;
		}

		// Has index page.
		$yesNoOptions = [ 'na' => 'N/A', 'yes' => 'Yes', 'no' => 'No' ];
		$hasIndex = $request->get( 'has_index', 'na' );
		if ( !array_key_exists( $hasIndex, $yesNoOptions ) ) {
			$hasIndex = 'na';
		}
		// Linked to Wikidata.
		$hasWikidata = $request->get( 'has_wikidata', 'na' );
		if ( !array_key_exists( $hasWikidata, $yesNoOptions ) ) {
			$hasWikidata = 'na';
		}

		// Assemble the template.
		$lang = $request->get( 'lang', 'en' );
		$templateParams = [
			'outputFormats' => $outputFormats,
			'yesNoOptions' => $yesNoOptions,
			'form_vals' => [
				'title' => $request->get( 'title' ),
				'author' => $request->get( 'author' ),
				'output_format' => $outputFormat,
				'has_index' => $hasIndex,
				'has_wikidata' => $hasWikidata,
			],
			'lang' => $lang,
			'notices' => [],
			'works' => [],
		];

		$templateParams['langs'] = $this->db->executeQuery(
				"SELECT DISTINCT languages.* FROM `languages` "
				. " JOIN works ON works.language_id = languages.id "
				. " ORDER BY `code`"
			)->fetchAllAssociative();
		$templateParams['totalWorks'] = $this->db->FetchOne( "SELECT COUNT(*) FROM works" );

		$params = [ 'lang' => $lang ];
		$sqlTitleWhere = '';
		$title = $request->get( 'title' );
		if ( $title ) {
			$sqlTitleWhere = 'AND `works`.`pagename` LIKE :title ';
			$params['title'] = '%' . trim( $title ) . '%';
		}

		$sqlAuthorWhere = '';
		$author = $request->get( 'author' );
		if ( $author ) {
			$sqlAuthorWhere = 'AND `authors`.`pagename` LIKE :author ';
			$params['author'] = '%' . trim( $author ) . '%';
		}

		// Has index page.
		$sqlHasIndex = '';
		if ( $hasIndex === 'yes' ) {
			$sqlHasIndex = ' AND index_pages.id IS NOT NULL ';
		} elseif ( $hasIndex === 'no' ) {
			$sqlHasIndex = ' AND index_pages.id IS NULL ';
		}

		// Linked to Wikidata.
		$sqlHasWikidata = '';
		if ( $hasWikidata === 'yes' ) {
			$sqlHasWikidata = ' AND works.wikidata_item IS NOT NULL ';
		} elseif ( $hasWikidata === 'no' ) {
			$sqlHasWikidata = ' AND works.wikidata_item IS NULL ';
		}

		// If nothing has been searched, return the empty template.
		if ( !$sqlTitleWhere && !$sqlAuthorWhere && !$sqlHasIndex ) {
			return $this->render( $outputFormat . '.twig', $templateParams );
		}

		// Otherwise, build the queries.
		$sql = "SELECT DISTINCT "
			. "   works.*,"
			. "   IF (works.title IS NULL OR works.title = '', works.pagename, works.title) AS title,"
			. "   MIN(index_pages.quality) AS quality,"
			. "   p.name AS publisher_name,"
			. "   p.location AS publisher_location "
			. " FROM works "
			. " JOIN languages l ON l.id=works.language_id "
			. " LEFT JOIN authors_works aw ON aw.work_id = works.id "
			. " LEFT JOIN authors ON authors.id = aw.author_id"
			. " LEFT JOIN publishers p ON p.id=works.publisher_id"
			. " LEFT JOIN works_indexes wi ON wi.work_id = works.id "
			. " LEFT JOIN index_pages ON wi.index_page_id = index_pages.id "
			. "WHERE "
			. " l.code = :lang $sqlTitleWhere $sqlAuthorWhere $sqlHasIndex $sqlHasWikidata "
			. "GROUP BY works.id ";
		$works = [];
		foreach ( $this->db->executeQuery( $sql, $params )->fetchAllAssociative() as $work ) {
			// Find authors.
			$sqlAuthors = "SELECT a.* FROM authors a "
				. " JOIN authors_works aw ON aw.author_id = a.id"
				. " WHERE aw.work_id = :wid ";
			$authors = $this->db
				->executeQuery( $sqlAuthors, [ 'wid' => $work['id'] ] )
				->fetchAllAssociative();
			$work['authors'] = $authors;

			// Find index pages.
			$sqlIndexPages = "SELECT ip.* FROM index_pages ip "
				. " JOIN works_indexes wi ON wi.index_page_id = ip.id "
				. " WHERE wi.work_id = :wid ";
			$indexPages = $this->db
				->executeQuery( $sqlIndexPages, [ 'wid' => $work['id'] ] )
				->fetchAllAssociative();
			$work['indexPages'] = $indexPages;

			$works[] = $work;
		}

		$templateParams['works'] = $works;
		if ( $outputFormat == 'csv' ) {
			return $this->outputCsv( $templateParams['lang'], $works );
		} else {
			return $this->render( $outputFormat . '.twig', $templateParams );
		}
	}

	/**
	 * @param string $langCode
	 * @param mixed[] $works
	 * @return Response
	 */
	public function outputCsv( $langCode, $works ) {
		$out = "Wikidata,Page name,Title,Authors,Year,Publisher,Categories,"
			. "Proofreading status,Index pages,Cover images\n";
		foreach ( $works as $work ) {
			$authors = [];
			foreach ( $work['authors'] as $author ) {
				$authors[] = $author['pagename'];
			}
			$indexPages = [];
			$coverUrls = [];
			if ( isset( $work['indexPages'] ) ) {
				foreach ( $work['indexPages'] as $indexPage ) {
					$indexPages[] = $indexPage['pagename'];
					$coverUrls[] = $indexPage['cover_image_url'];
				}
			}
			$out .= $work['wikidata_item'] . ","
				. Text::csvCell( $work['pagename'] ) . ","
				. Text::csvCell( $work['title'] ) . ","
				. Text::csvCell( $authors ) . ","
				. Text::csvCell( $work['year'] ) . ","
				. Text::csvCell( $work['publisher_name'] ) . ","
				. Text::csvCell( $work['publisher_location'] ) . ","
				. Text::csvCell( $work['quality'] ) . ","
				. Text::csvCell( $indexPages ) . ","
				. Text::csvCell( $coverUrls ) . "\n";
		}
		return $this->sendFile( 'csv', 'text/csv', $out, "wikisource_$langCode" );
	}
}
