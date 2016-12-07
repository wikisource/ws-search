<?php

namespace App\Commands;

use App\Database\Database;
use App\Database\WorkSaver;
use Exception;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\FluentRequest;
use Dflydev\DotAccessData\Data;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wikisource\Api\Wikisource;
use Wikisource\Api\WikisourceApi;

class ScrapeCommand extends Command {

	/** @var Database */
	private $db;

	/** @var WikisourceApi */
	private $wsApi;

	/**
	 * @var \stdClass The database ID ('id'), text code ('code'), and Index NS ID ('index_ns_id')
	 * of the language that is currently being processed.
	 */
	private $currentLang;

	/** @var SymfonyStyle */
	protected $io;

	protected function configure(){
		$this->setName('scrape');
		$this->setDescription('Import all works from Wikisource.');
		$langDesc = 'The language code of the Wikisource to scrape';
		$this->addOption('lang', 'l', InputOption::VALUE_OPTIONAL, $langDesc);
		$titleDesc = 'The title of a single page to scrape, must be combined with --lang';
		$this->addOption('title', 't', InputOption::VALUE_OPTIONAL, $titleDesc);
		$langsDesc = 'Retrieve metadata about all language Wikisources';
		$this->addOption('langs', null, InputOption::VALUE_NONE, $langsDesc);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->io = new SymfonyStyle($input, $output);
		$startTime = time();

		$this->db = new Database;
		$this->wsApi = new WikisourceApi();

		if ( $input->getOption('langs') ) {
			$this->getWikisourceLangEditions();
		}

		// All works in one language Wikisource.
		if ( $input->getOption('lang') && !$input->getOption('title') ) {
			$langCode = $input->getOption('lang');
			$this->setCurrentLang( $langCode );
			$this->getAllWorks( $langCode );
		}

		// A single work from a single Wikisource.
		if ( $input->getOption('title') ) {
			$langCode = $input->getOption('lang');
			if ( !$langCode ) {
				$this->io->error( "You must also specify the Wikisource language code, with --lang=xx" );
				return 1;
			}
			$this->setCurrentLang( $langCode );
			$this->getSingleMainspaceWork( $input->getOption('title') );
		}

		// If nothing else is specified, scrape everything.
		if ( count( $input->getOptions() ) === 0 ) {
			$langCodes = $this->getWikisourceLangEditions();
			foreach ( $langCodes as $lc ) {
				$this->setCurrentLang( $lc );
				$this->getAllWorks( $lc );
			}
		}

		$duration = round( ( time()-$startTime )/60/60, 2 );
		$this->io->success( "Scraping finished in about $duration hours" );
		return 0;
	}

	public function setCurrentLang( $code ) {
		$sql = "SELECT * FROM languages WHERE code=:code";
		$this->currentLang = $this->db->query( $sql, [ 'code' => $code ] )->fetch();
		if ( !$this->currentLang ) {
			$msg = "Unable to load language '$code'.\nDo you need to run this with `scrape --langs`?";
			throw new Exception( $msg );
		}
	}

	private function getAllWorks( $langCode ) {
		$this->io->text( "Getting works from '$langCode' Wikisource." );
		$request = FluentRequest::factory()
			->setAction( 'query' )
			->setParam( 'list', 'allpages' )
			->setParam( 'apnamespace', 0 )
			->setParam( 'apfilterredir', 'nonredirects' )
			->setParam( 'aplimit', 500 );
		$this->completeQuery( $request, 'query.allpages', [ $this, 'getSingleMainspaceWork' ] );
	}

	protected function getSingleMainspaceWork( $pageName ) {
		$this->io->text( "Importing from {$this->currentLang->code}: " . $pageName );
		$ws = $this->wsApi->fetchWikisource( $this->currentLang->code );
		$work = $ws->getWork( $pageName );
		$workSaver = new WorkSaver();
		$workSaver->save( $work );

// // Retrieve the page text, Index links (i.e. templates in the right NS), and categories.
// $requestParse = FluentRequest::factory()
// ->setAction('parse')
// ->setParam('page', $pagename)
// ->setParam('prop', 'text|templates|categories');
// $pageParse = new Data($this->completeQuery($requestParse, 'parse'));
// // Normalize the pagename.
// $pagename = $pageParse->get('title');
//
// // Get the Wikidata Item.
// $requestProps = FluentRequest::factory()
// ->setAction('query')
// ->setParam('titles', $pagename)
// ->setParam('prop', 'pageprops')
// ->setParam('ppprop', 'wikibase_item');
// $pageProps = $this->completeQuery($requestProps, 'query.pages');
// $pagePropsSingle = new Data(array_shift($pageProps));
// $wikibaseItem = $pagePropsSingle->get('pageprops.wikibase_item');
//
// // If this is a subpage, determine the mainpage.
// if (strpos($pagename, '/') !== false) {
// $rootPageName = substr($pagename, 0, strpos($pagename, '/'));
// } else {
// $rootPageName = $pagename;
// }
//
// // Deal with the data from within the page text.
// // Note the slightly odd way of ensuring the HTML content is loaded as UTF8.
// $pageHtml = $pageParse->get('text.*');
// $pageCrawler = new Crawler;
// $pageCrawler->addHTMLContent("<div>$pageHtml</div>", 'UTF-8');
// // Pull the microformatted-defined attributes.
// $microformatIds = ['ws-title', 'ws-author', 'ws-year'];
// $microformatVals = [];
// foreach ($microformatIds as $i) {
// $el = $pageCrawler->filterXPath("//*[@id='$i']");
// $microformatVals[$i] = ($el->count() > 0) ? $el->text() : '';
// }

// // Save basic work data to the database. It might already be there, if this is a subpage, in which case we don't
// // really care that this won't do anything, and this is just as fast as checking whether it exists or not.
// $sql = "INSERT IGNORE INTO `works` SET"
// . " `language_id`=:lid,"
// . " `wikidata_item`=:wikidata_item,"
// . " `pagename`=:pagename,"
// . " `title`=:title,"
// . " `year`=:year";
// $insertParams = [
// 'lid' => $this->,
// 'wikidata_item' => ,
// 'pagename' => $rootPageName,
// 'title' => (!empty($microformatVals['ws-title'])) ? $microformatVals['ws-title'] : $pagename,
// 'year' => $microformatVals['ws-year'],
// ];
// $this->db->query($sql, $insertParams);
// $workId = $this->getWorkId($rootPageName);
// if (!is_numeric($workId)) {
// // Eh? What's going on here then?
// throw new \Exception("Failed to save pagename: $pagename -- params were: ".print_r($insertParams, true));
// }
//
// // Save the authors.
// $authors = explode('/', $microformatVals['ws-author']);
// foreach ($authors as $author) {
// $authorId = $this->getOrCreateRecord('authors', $author);
// $sqlAuthorLink = 'INSERT IGNORE INTO `authors_works` SET author_id=:a, work_id=:w';
// $this->db->query($sqlAuthorLink, ['a' => $authorId, 'w' => $workId]);
// }
//
// // Link the Index pages (i.e. 'templates' that are in the right NS.).
// foreach ($pageParse->get('templates') as $tpl) {
// if ($tpl['ns'] === (int) $this->currentLang->index_ns_id) {
// $this->writeDebug(" -- Linking an index page: " . $tpl['*']);
// $indexPageName = $tpl['*'];
// $indexPageId = $this->getOrCreateRecord('index_pages', $indexPageName);
// $sqlInsertIndexes = 'INSERT IGNORE INTO `works_indexes` SET index_page_id=:ip, work_id=:w';
// $this->db->query($sqlInsertIndexes, ['ip' => $indexPageId, 'w' => $workId]);
// $this->getIndexPageMetadata($indexPageName, $workId);
// }
// }
//
// // Save the categories.
// foreach ($pageParse->get('categories') as $cat) {
// if (isset($cat['hidden'])) {
// continue;
// }
// }
	}

	public function completeQuery( FluentRequest $request, $resultKey, $callback = false ) {
		$api = new MediawikiApi( "https://" . $this->currentLang->code . ".wikisource.org/w/api.php" );
		$data = [];
		$continue = true;
		do {
			// Send request and save data for later returning.
			$result = new Data( $api->getRequest( $request ) );
			$restultingData = $result->get( $resultKey );
			if ( !is_array( $restultingData ) ) {
				$continue = false;
				continue;
			}
			$data = array_merge_recursive( $data, $restultingData );

			// If a callback is specified, call it for each of the current result set.
			if ( is_callable( $callback ) ) {
				foreach ( $restultingData as $datum ) {
					try {
						call_user_func( $callback, $datum['title'] );
					} catch ( \Exception $e ) {
						// If anything goes wrong, report it and carry on.
						$this->io->error( 'Error with '.$datum['title'].' -- '.$e->getMessage() );
					}
				}
			}

			// Whether to continue or not.
			if ( $result->get( 'continue', false ) ) {
				$request->addParams( $result->get( 'continue' ) );
			} else {
				$continue = false;
			}
		} while ( $continue );

		return $data;
	}

	private function getWikisourceLangEditions() {
		$this->io->text( "Getting list of Wikisource languages" );
		$wikisources = $this->wsApi->fetchWikisources();
		foreach ( $wikisources as $wikisource ) {
			$sql = "INSERT IGNORE INTO languages SET code=:code, label=:label, index_ns_id=:ns";
			$params = [
				'code' => $wikisource->getLanguageCode(),
				'label' => $wikisource->getLanguageName(),
				'ns' => $wikisource->getNamespaceId( Wikisource::NS_NAME_INDEX ),
			];
			$this->io->text( " -- Saving " . $params['label'] . ' (' . $params['code'] . ')' );
			$this->db->query( $sql, $params );
		}
	}

	/* private function getWorks($offset)
      {
      $queryWorks = "SELECT
      ?work ?workLabel ?title ?authorLabel ?originalPublicationDate ?about ?indexPage
      WHERE {
      ?type wdt:P279* wd:Q571 . # any subclass of book (Q571).
      ?work wdt:P31 ?type . # where the work is an instance of that subclass.
      OPTIONAL{ ?work wdt:P1476 ?title } .
      OPTIONAL{ ?work wdt:P50 ?author } .
      OPTIONAL{ ?work wdt:P577 ?originalPublicationDate } .
      OPTIONAL{ ?work wdt:P1957 ?indexPage } .
      OPTIONAL{ ?about schema:about ?work } . # Wikisource page name?
      SERVICE wikibase:label { bd:serviceParam wikibase:language 'en' }
      }
      OFFSET $offset LIMIT 20";
      $xml = $this->getXml($queryWorks);

      $works = [];
      foreach ($xml->results->result as $res) {
      $work = $this->getBindings($res);
      $w = $work['work'];

      // Start this work's entry in the master list.
      if (!isset($works[$w])) {
      $works[$w] = array(
      'title' => '',
      'author' => '',
      'year' => '',
      'wikisource' => '',
      'index_page' => '',
      );
      }

      // Check, get, and format the year of publication.
      if (empty($works[$w]['year']) && !empty($work['originalPublicationDate'])) {
      $works[$w]['year'] = date('Y', strtotime($work['originalPublicationDate']));
      }

      // Wikisource "Index:" page.
      if (empty($works[$w]['index_page']) && !empty($work['indexPage'])) {
      $works[$w]['index_page'] = $this->wikisourcePageName($work['indexPage']);
      }

      // Check for and get the work's title.
      if (empty($works[$w]['title'])) {
      if (!empty($work['title'])) {
      $works[$w]['title'] = $work['title'];
      } else {
      $works[$w]['title'] = $work['workLabel'];
      }
      }

      // Check for and get the work's author.
      if (empty($works[$w]['author'])) {
      if (!empty($work['authorLabel'])) {
      $works[$w]['author'] = $work['authorLabel'];
      }
      }

      // Get the Wikisource page name for the work.
      if (empty($works[$w]['wikisource']) && !empty($work['about'])) {
      $works[$w]['wikisource'] = $this->wikisourcePageName($work['about']);
      }

      // Get all editions of this work.
      $editions = $this->getXml("SELECT ?edition ?about ?indexPage
      WHERE {
      ?edition wdt:P629 wd:" . basename($work['work']) . " .
      OPTIONAL{ ?edition wdt:P1957 ?indexPage } .
      OPTIONAL{ ?about schema:about ?edition } .
      SERVICE wikibase:label { bd:serviceParam wikibase:language 'en' }
      }");
      foreach ($editions->results->result as $ed) {
      $edition = getBindings($ed);
      // Get the Wikisource page name for the edition.
      if (empty($works[$w]['wikisource']) && !empty($edition['about'])) {
      $works[$w]['wikisource'] = wikisourcePageName($edition['about']);
      }
      // Wikisource "Index:" page.
      if (empty($works[$w]['index_page']) && !empty($edition['indexPage'])) {
      $works[$w]['index_page'] = wikisourcePageName($edition['indexPage']);
      }
      }
      }
      return $works;

      //        echo "\n\n{| class='wikitable sortable'\n! Year !! Title !! Author !! Transcription project \n|-\n";
      //        foreach ($works as $w) {
      //            $title = (!empty($w['wikisource'])) ? "[[{$w['wikisource']}|{$w['title']}]]" : $w['title'];
      //            $indexPage = (!empty($w['index_page'])) ? "[[" . $w['index_page'] . "|Yes]]" : '';
      //            echo "| {$w['year']} || $title || {$w['author']} || $indexPage \n"
      //            . "|-\n";
      //        }
      //        echo "|}\n";
      } */

// private function getBindings($xml)
// {
// $out = [];
// foreach ($xml->binding as $binding) {
// if (isset($binding->literal)) {
// $out[(string) $binding['name']] = (string) $binding->literal;
// }
// if (isset($binding->uri)) {
// $out[(string) $binding['name']] = (string) $binding->uri;
// }
// }
// return $out;
// }
//
// private function getXml($query)
// {
// //echo "\n$query\n\n";
// $url = "https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=" . urlencode($query);
// $result = file_get_contents($url);
// $xml = new \SimpleXmlElement($result);
// return $xml;
// }
	/**
	 * Get the page name from a Wikisource URL.
	 * @param string $url
	 * @return string
	 */
	/* private function wikisourcePageName($url)
      {
      $wikiLang = 'en';
      if (!empty($url) && strpos($url, "$wikiLang.wikisource") !== false) {
      $strPrefix = strlen("https://$wikiLang.wikisource.org/wiki/");
      return urldecode(substr($url, $strPrefix));
      }
      return '';
      } */
}
