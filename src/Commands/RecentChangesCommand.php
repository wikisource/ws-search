<?php

namespace App\Commands;

use App\EditionSaver;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\UsageException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wikisource\Api\Wikisource;
use Wikisource\Api\WikisourceApi;

class RecentChangesCommand extends Command {

	/** @var SymfonyStyle */
	protected $io;

	/** @var EditionSaver */
	private $editionSaver;

	/** @var CacheItemPoolInterface */
	private $cache;

	/** @var LoggerInterface */
	private $logger;

	/**
	 * @param EditionSaver $editionSaver
	 * @param CacheItemPoolInterface $cache
	 * @param LoggerInterface $logger
	 */
	public function __construct( EditionSaver $editionSaver, CacheItemPoolInterface $cache, LoggerInterface $logger ) {
		parent::__construct();
		$this->editionSaver = $editionSaver;
		$this->cache = $cache;
		$this->logger = $logger;
	}

	/**
	 *
	 */
	protected function configure() {
		$this->setName( 'rc' );
		$this->setDescription( 'Import works from Recent Changes feeds.' );
		$desc = 'The language code of the Wikisource to scrape';
		$this->addOption( 'lang', 'l', InputOption::VALUE_OPTIONAL, $desc );
	}

	/**
	 * @param InputInterface $input The input.
	 * @param OutputInterface $output The output.
	 * @return null|int null or 0 if everything went fine, or an error code
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$this->io = new SymfonyStyle( $input, $output );

		// Set up WikisourceApi.
		$wsApi = new WikisourceApi();
		$wsApi->setCache( $this->cache );
		$wsApi->setLogger( $this->logger );

		// Verbose output.
		if ( $input->getOption( 'verbose' ) ) {
			$logger = new Logger( 'WikisourceApi' );
			$logger->pushHandler( new StreamHandler( 'php://stdout', Logger::DEBUG ) );
			$wsApi->setLogger( $logger );
		}

		$lang = $input->getOption( 'lang' );
		if ( $lang ) {
			// Run for just the requested language.
			$wikisource = $wsApi->fetchWikisource( $lang );
			$this->runOneLang( $wikisource );
		} else {
			// Run for all languages.
			foreach ( $wsApi->fetchWikisources() as $wikisource ) {
				$this->runOneLang( $wikisource );
			}
		}
	}

	/**
	 * @param Wikisource $wikisource The wikisource to run against.
	 */
	protected function runOneLang( Wikisource $wikisource ) {
		$this->io->text(
			"---- Getting recent changes from " . $wikisource->getLanguageName()
			. " (" . $wikisource->getLanguageCode() . ") ---- "
		);

		// Get recentchanges from the last 2 days.
		$request = FluentRequest::factory()
			->setAction( 'query' )
			->setParam( 'list', 'recentchanges' )
			->setParam( 'rcdir', 'newer' )
			->setParam( 'rcstart', time() - 2 * 24 * 60 * 60 )
			->setParam( 'rcnamespace', 0 );
		$rc = $wikisource->getMediawikiApi()->getRequest( $request );
		foreach ( $rc['query']['recentchanges'] as $rcItem ) {
			try {
				$edition = $wikisource->getEdition( $rcItem['title'] );
				$this->io->text( $edition->getPageTitle() );
				// Ignore the Main_Page.
				$mainPageId = 'Q5296';
				if ( $edition->getWikidataItemNumber() === $mainPageId ) {
					continue;
				}
				// Ignore too many subpages.
				$subpageCount = count( $edition->getSubpages( 30 ) );
				if ( $subpageCount === 30 ) {
					$this->io->warning( "Too many subpages (more than $subpageCount found)." );
					continue;
				}
				$this->editionSaver->save( $edition );
			} catch ( UsageException $ex ) {
				$this->io->error( $ex->getMessage() );
			}
		}
	}
}
