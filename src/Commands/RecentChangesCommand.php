<?php

namespace App\Commands;

use App\Config;
use App\Database\WorkSaver;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\UsageException;
use Stash\Driver\FileSystem;
use Stash\Pool;
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
		$cache = new Pool( new FileSystem( [ 'path' => Config::storageDirTmp( 'cache' ) ] ) );
		$wsApi->setCache( $cache );

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
			"---- Getting recent changes from ".$wikisource->getLanguageName()
			." (" .$wikisource->getLanguageCode().") ---- "
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
			$this->io->text( $rcItem['title'] );
			$work = $wikisource->getWork( $rcItem['title'] );
			// Ignore the Main_Page.
			$mainPageId = 'Q5296';
			if ( $work->getWikidataItemNumber() === $mainPageId ) {
				continue;
			}
			$dbWork = new WorkSaver();
			try {
				$dbWork->save( $work );
			} catch ( UsageException $ex ) {
				$this->io->error( $ex->getMessage() );
			}
		}
	}
}
