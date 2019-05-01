<?php

namespace App;

class BookQuery {

	private $lang = 'en';
	private $title;
	private $author;
	private $subject;
	private $genre;

	function setLang( $lang ) {
		$this->lang = $lang;
	}

	public function setTitle( $title ) {
		$this->title = $title;
	}

	function setAuthor( $author ) {
		$this->author = $author;
	}

	public function setSubject( $subject ) {
		$this->subject = $subject;
	}

	public function setGenre( $genre ) {
		$this->genre = $genre;
	}

	public function getQuery() {
		$titleFilter = "?work rdfs:label ?workLabel . \n"
			. "OPTIONAL{ ?work wdt:P1476 ?title } . \n"
			. "FILTER( \n"
			. "  REGEX(STR(?title), '.*" . addslashes( $this->title ) . ".*', 'i') \n"
			. "  || REGEX(STR(?workLabel), '.*" . addslashes( $this->title ) . ".*', 'i') \n"
			. ") .\n";

		$authorFilter = "?author rdfs:label ?authorLabel . "
			// . "?author wdt:P735 ?givenName . \n"
			// . "?givenName rdfs:label ?givenNameLabel . \n"
			// . "?author wdt:P734 ?familyName . \n"
			// . "?familyName rdfs:label ?familyNameLabel . \n"
			. "FILTER( "
			// . "  ("
			. "    REGEX(STR(?authorLabel), '.*" . addslashes( $this->author ) . ".*', 'i') "
			// . "  ) || ("
			// . "    LANG(?givenNameLabel) = '".$this->lang."' \n"
			// . "    && REGEX(STR(?givenNameLabel), '.*$this->author.*', 'i') \n"
			// . "  ) || ("
			// . "    LANG(?familyNameLabel) = '".$this->lang."' \n"
			// . "    && REGEX(STR(?familyNameLabel), '.*$this->author.*', 'i') \n"
			// . "  )"
			. ")\n";

		$subjectFilter = "?work wdt:P921 ?mainSubject . \n"
			. "?mainSubject rdfs:label ?mainSubjectLabel . \n"
			. "FILTER( "
			. "    LANG(?mainSubjectLabel) = '" . $this->lang . "'"
			. "    && REGEX( STR(?mainSubjectLabel), '.*" . addslashes( $this->subject ) . ".*' )"
			. ") ";

		$genreFilter = "?work wdt:P136 ?genre . \n"
			. "?genre rdfs:label ?genreLabel . \n"
			. "FILTER( "
			. "    LANG(?genreLabel) = '" . $this->lang . "'"
			. "    && REGEX( STR(?genreLabel), '.*" . addslashes( $this->genre ) . ".*' )"
			. ") ";

		$query = "SELECT
                ?subclass ?subclassLabel ?work ?workLabel ?title ?authorLabel
                ?originalPublicationDate ?article ?indexPage
                ?mainSubjectLabel ?genreLabel
            WHERE {
                ?article schema:isPartOf <https://" . $this->lang . ".wikisource.org/> . # The article is part of WS.
                ?article schema:about ?work . # There's an article about a work.
                ?subclass wdt:P279* wd:Q386724 . # Any subclass of work (Q386724).
                ?work wdt:P31 ?subclass . # The work is an instance of that subclass.
                " . ( !empty( $this->title ) ? $titleFilter : '' ) . "
                ?work wdt:P50 ?author .
                " . ( !empty( $this->author ) ? $authorFilter : '' ) . "
                OPTIONAL{ ?work wdt:P577 ?originalPublicationDate } .
                OPTIONAL{ ?work wdt:P1957 ?indexPage } .
                " . ( !empty( $this->subject ) ? $subjectFilter : 'OPTIONAL{ ?work wdt:P921 ?mainSubject } ' ) . " .
                " . ( !empty( $this->genre ) ? $genreFilter : 'OPTIONAL{ ?work wdt:P921 ?genre } ' ) . " .
                SERVICE wikibase:label { bd:serviceParam wikibase:language '" . $this->lang . "' }
            }";
		return $query;
	}

	public function run() {
		if ( empty( $this->title ) && empty( $this->author ) && empty( $this->subject ) && empty( $this->genre ) ) {
			return [];
		}
		$xml = $this->getXml( $this->getQuery() );
		$books = [];
		foreach ( $xml->results->result as $res ) {
			$bookInfo = $this->getBindings( $res );
			$id = $bookInfo['work'];

			// Start this work's entry in the master list.
			if ( !isset( $books[$id] ) ) {
				$books[$id] = [
					'item' => basename( $id ),
					'subclass' => [],
					'subjects' => [],
					'title' => '',
					'author' => '',
					'year' => '',
					'wikisource' => '',
					'wikisource_url' => '',
					'index_page' => '',
				];
			}
			$this->getBookData( $books, $id, $bookInfo );
		}
		return $books;
	}

	public function getBookData( &$works, $id, $work ) {
		// Subclass.
		if ( empty( $works[$id]['subclass'] ) && !empty( $work['subclassLabel'] ) ) {
			$works[$id]['subclass'][$work['subclass']] = $work['subclassLabel'];
		}

		// Main Subject and Genre.
		if ( !empty( $work['mainSubjectLabel'] ) ) {
			$works[$id]['subjects'][$work['mainSubjectLabel']] = $work['mainSubjectLabel'];
		}
		if ( !empty( $work['genreLabel'] ) ) {
			$works[$id]['subjects'][$work['genreLabel']] = $work['genreLabel'];
		}

		// Check, get, and format the year of publication.
		if ( empty( $works[$id]['year'] ) && !empty( $work['originalPublicationDate'] ) ) {
			$works[$id]['year'] = date( 'Y', strtotime( $work['originalPublicationDate'] ) );
		}

		// Wikisource "Index:" page.
		if ( empty( $works[$id]['index_page'] ) && !empty( $work['indexPage'] ) ) {
			$works[$id]['index_page'] = $this->wikisourcePageName( $work['indexPage'] );
		}

		// Check for and get the work's title.
		if ( empty( $works[$id]['title'] ) ) {
			if ( !empty( $work['title'] ) ) {
				$works[$id]['title'] = $work['title'];
			} else {
				$works[$id]['title'] = $work['workLabel'];
			}
		}

		// Check for and get the work's author.
		if ( empty( $works[$id]['author'] ) ) {
			if ( !empty( $work['authorLabel'] ) ) {
				$works[$id]['author'] = $work['authorLabel'];
			}
		}

		// Get the Wikisource page name for the work.
		if ( empty( $works[$id]['wikisource'] ) && !empty( $work['article'] ) ) {
			$works[$id]['wikisource_url'] = $work['article'];
			$works[$id]['wikisource'] = $this->wikisourcePageName( $work['article'] );
		}
	}

	private function getXml( $query ) {
		$url = "https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=" . urlencode( $query );
		try {
			$result = file_get_contents( $url );
		} catch ( \Exception $e ) {
			throw new \Exception( "Unable to run query: <pre>" . htmlspecialchars( $query ) . "</pre>", 500 );
		}
		if ( empty( $result ) ) {
			// @OTODO: sort out a proper error handler! :(
			header( 'Content-type:text/plain' );
			echo $query;
			exit( 1 );

		}
		$xml = new \SimpleXmlElement( $result );
		return $xml;
	}

	private function getBindings( $xml ) {
		$out = [];
		foreach ( $xml->binding as $binding ) {
			if ( isset( $binding->literal ) ) {
				$out[(string)$binding['name']] = (string)$binding->literal;
			}
			if ( isset( $binding->uri ) ) {
				$out[(string)$binding['name']] = (string)$binding->uri;
			}
		}
		// print_r($out);
		return $out;
	}

	/**
	 * Get the page name from a Wikisource URL.
	 * @param string $url
	 * @return string
	 */
	private function wikisourcePageName( $url ) {
		if ( !empty( $url ) && strpos( $url, "$this->lang.wikisource" ) !== false ) {
			$strPrefix = strlen( "https://" . $this->lang . ".wikisource.org/wiki/" );
			return urldecode( substr( $url, $strPrefix ) );
		}
		return '';
	}
}
