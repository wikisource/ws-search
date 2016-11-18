<?php

namespace App\Commands;

use App\Config;
use App\Database;
use App\Database\WorkSaver;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\FluentRequest;
use GetOptionKit\OptionCollection;
use Mediawiki\Api\UsageException;
use Stash\Driver\FileSystem;
use Stash\Pool;
use Wikisource\Api\Wikisource;
use Wikisource\Api\WikisourceApi;

class RecentChangesCommand extends CommandBase {

	public function getCliOptions() {
		$options = new OptionCollection;
		$options->add( 'l|lang?string', 'The language code of the Wikisource to scrape' );
		return $options;
	}

	public function run() {

		// Set up WikisourceApi.
		$wsApi = new WikisourceApi();
		$cache = new Pool( new FileSystem( [ 'path' => Config::storageDirTmp( 'cache' ) ] ) );
		$wsApi->setCache( $cache );

		$lang = $this->cliOptions->lang;
		if ( $lang ) {
			// Run for just the requested language.
			$wikisource = $wsApi->fetchWikisource( $lang );
			$this->runOneLang( $wikisource );
		} else {
			// Run for all languages.
			foreach ($wsApi->fetchWikisources() as $wikisource) {
				$this->runOneLang( $wikisource );
			}
		}
	}

	public function runOneLang( Wikisource $wikisource ) {
		$this->write(
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
			$this->write( $rcItem['title'] );
			$work = $wikisource->getWork( $rcItem['title'] );
			$dbWork = new WorkSaver();
			try {
				$dbWork->save( $work );
			} catch ( UsageException $ex ) {
				$this->write( 'ERROR: ' . $ex->getMessage() );
			}
		}
	}
}
