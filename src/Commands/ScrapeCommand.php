<?php

namespace App\Commands;

use App\Config;
use App\Database\Database;
use App\Database\WorkSaver;
use Exception;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\FluentRequest;
use Dflydev\DotAccessData\Data;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Stash\Driver\FileSystem;
use Stash\Pool;
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

	/** @var string[] Page titles of works already saved in the current session. */
	protected $savedWorks;

	/**
	 *
	 */
	protected function configure() {
		$this->setName( 'scrape' );
		$this->setDescription( 'Import all works from Wikisource.' );
		$langDesc = 'The language code of the Wikisource to scrape';
		$this->addOption( 'lang', 'l', InputOption::VALUE_OPTIONAL, $langDesc );
		$titleDesc = 'The title of a single page to scrape, must be combined with --lang';
		$this->addOption( 'title', 't', InputOption::VALUE_OPTIONAL, $titleDesc );
		$langsDesc = 'Retrieve metadata about all language Wikisources';
		$this->addOption( 'langs', null, InputOption::VALUE_NONE, $langsDesc );
	}

	/**
	 * @param InputInterface $input The input.
	 * @param OutputInterface $output The output.
	 * @return null|int null or 0 if everything went fine, or an error code
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$this->io = new SymfonyStyle( $input, $output );
		$startTime = time();

		$this->db = new Database;
		$this->wsApi = new WikisourceApi();

		// Cache.
		$cache = new Pool( new FileSystem( [ 'path' => Config::storageDirTmp( 'cache' ) ] ) );
		$this->wsApi->setCache( $cache );

		// Verbose output.
		if ( $input->getOption( 'verbose' ) ) {
			$logger = new Logger( 'mediawiki-api' );
			$logger->pushHandler( new StreamHandler( 'php://stdout', Logger::DEBUG ) );
			$this->wsApi->setLogger( $logger );
		}

		// Get all languages.
		if ( $input->getOption( 'langs' ) ) {
			$this->getWikisourceLangEditions();
		}

		// All works in one language Wikisource.
		if ( $input->getOption( 'lang' ) && !$input->getOption( 'title' ) ) {
			$langCode = $input->getOption( 'lang' );
			$this->setCurrentLang( $langCode );
			$this->getAllWorks( $langCode );
		}

		// A single work from a single Wikisource.
		if ( $input->getOption( 'title' ) ) {
			$langCode = $input->getOption( 'lang' );
			if ( !$langCode ) {
				$this->io->error( "You must also specify the Wikisource language code, with --lang=xx" );
				return 1;
			}
			$this->setCurrentLang( $langCode );
			$this->getSingleMainspaceWork( trim( $input->getOption( 'title' ) ) );
		}

		// If nothing else is specified, scrape everything.
		if ( count( $input->getOptions() ) === 0 ) {
			$langCodes = $this->getWikisourceLangEditions();
			foreach ( $langCodes as $lc ) {
				$this->setCurrentLang( $lc );
				$this->getAllWorks( $lc );
			}
		}

		$duration = round( ( time() - $startTime ) / 60 / 60, 2 );
		$this->io->success( "Scraping finished in about $duration hours" );
		return 0;
	}

	/**
	 * @param string $code The ISO639 code.
	 * @throws Exception
	 */
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
		$this->savedWorks = [];
		$this->completeQuery( $request, 'query.allpages', [ $this, 'getSingleMainspaceWork' ] );
	}

	/**
	 * @param string $pageName The Wikisource main namespace page title.
	 */
	protected function getSingleMainspaceWork( $pageName ) {
		$ws = $this->wsApi->fetchWikisource( $this->currentLang->code );
		$work = $ws->getWork( $pageName );

		// Make sure we haven't already saved this work in the current session.
		$pageTitle = $work->getPageTitle( false );
		if ( array_key_exists( $pageTitle, $this->savedWorks ) ) {
			$this->io->text( "Skipping $pageName as it's already been done." );
			return;
		}
		$this->savedWorks[ $pageTitle ] = true;
		$this->io->text( "Importing from {$this->currentLang->code}: " . $pageTitle );

		// Ignore too many subpages.
		$subpageCount = count( $work->getSubpages( 30 ) );
		if ( $subpageCount === 30 ) {
			$this->io->warning( "Too many subpages (more than $subpageCount found)." );
			return;
		}

		$workSaver = new WorkSaver();
		$workSaver->save( $work );
	}

	/**
	 * @param FluentRequest $request The request to run.
	 * @param string $resultKey The key that the result is under in the results data.
	 * @param callback|bool $callback A callback to run for each result, or false to not run.
	 * @return array
	 */
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
}
