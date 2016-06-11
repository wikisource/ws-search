<?php

namespace App;

class BookQuery
{

    private $lang = 'en';
    private $title;
    private $author;

    function setLang($lang)
    {
        $this->lang = $lang;
    }

    public function setTitle($title)
    {
        $this->title = $title;
    }

    function setAuthor($author)
    {
        $this->author = $author;
    }

    public function getQuery()
    {
        $titleFilter = "?work rdfs:label ?workLabel . \n"
            . "OPTIONAL{ ?work wdt:P1476 ?title } . \n"
            . "FILTER( \n"
            . "  REGEX(STR(?title), '.*" . addslashes($this->title) . ".*', 'i') \n"
            . "  || REGEX(STR(?workLabel), '.*" . addslashes($this->title) . ".*', 'i') \n"
            . ") .\n";

        $authorFilter = "?author rdfs:label ?authorLabel . "
            //. "?author wdt:P735 ?givenName . \n"
            //. "?givenName rdfs:label ?givenNameLabel . \n"
            //. "?author wdt:P734 ?familyName . \n"
            //. "?familyName rdfs:label ?familyNameLabel . \n"
            . "FILTER( "
            //. "  ("
            . "    REGEX(STR(?authorLabel), '.*" . addslashes($this->author) . ".*', 'i') "
            //. "  ) || ("
            //. "    LANG(?givenNameLabel) = '".$this->lang."' \n"
            //. "    && REGEX(STR(?givenNameLabel), '.*$this->author.*', 'i') \n"
            //. "  ) || ("
            //. "    LANG(?familyNameLabel) = '".$this->lang."' \n"
            //. "    && REGEX(STR(?familyNameLabel), '.*$this->author.*', 'i') \n"
            //. "  )"
            . ")\n";

        $query = "SELECT
                ?subclass ?subclassLabel ?work ?workLabel ?title ?authorLabel
                ?originalPublicationDate ?article ?indexPage
            WHERE {
                ?subclass wdt:P279* wd:Q386724 . # any subclass of work (Q386724).
                #?subclass wdt:P279* wd:Q571 . # any subclass of book (Q571).
                ?work wdt:P31 ?subclass . # where the work is an instance of that subclass.
                ?article schema:about ?work . # Where there's an article about this item
                ?article schema:isPartOf <https://" . $this->lang . ".wikisource.org/> . # And the article is part of WS.
                " . (!empty($this->title) ? $titleFilter : '') . "
                ?work wdt:P50 ?author .
                " . (!empty($this->author) ? $authorFilter : '') . "
                OPTIONAL{ ?work wdt:P577 ?originalPublicationDate } .
                OPTIONAL{ ?work wdt:P1957 ?indexPage } .
                SERVICE wikibase:label { bd:serviceParam wikibase:language '" . $this->lang . "' }
            }";
        return $query;
    }

    public function run()
    {
        if (empty($this->title) && empty($this->author)) {
            return [];
        }
        $xml = $this->getXml($this->getQuery());
        $books = [];
        foreach ($xml->results->result as $res) {
            $bookInfo = $this->getBindings($res);
            $id = $bookInfo['work'];

            // Start this work's entry in the master list.
            if (!isset($books[$id])) {
                $books[$id] = array(
                    'item' => basename($id),
                    'subclass' => '',
                    'title' => '',
                    'author' => '',
                    'year' => '',
                    'wikisource' => '',
                    'index_page' => '',
                );
            }
            $this->getBookData($books, $id, $bookInfo);
        }
        return $books;
    }

    public function getBookData(&$works, $id, $work)
    {
        // Subclass.
        if (empty($works[$id]['subclass']) && !empty($work['subclassLabel'])) {
            $works[$id]['subclass'] = $work['subclassLabel'];
        }

        // Check, get, and format the year of publication.
        if (empty($works[$id]['year']) && !empty($work['originalPublicationDate'])) {
            $works[$id]['year'] = date('Y', strtotime($work['originalPublicationDate']));
        }

        // Wikisource "Index:" page.
        if (empty($works[$id]['index_page']) && !empty($work['indexPage'])) {
            $works[$id]['index_page'] = $this->wikisourcePageName($work['indexPage']);
        }

        // Check for and get the work's title.
        if (empty($works[$id]['title'])) {
            if (!empty($work['title'])) {
                $works[$id]['title'] = $work['title'];
            } else {
                $works[$id]['title'] = $work['workLabel'];
            }
        }

        // Check for and get the work's author.
        if (empty($works[$id]['author'])) {
            if (!empty($work['authorLabel'])) {
                $works[$id]['author'] = $work['authorLabel'];
            }
        }

        // Get the Wikisource page name for the work.
        if (empty($works[$id]['wikisource']) && !empty($work['article'])) {
            $works[$id]['wikisource'] = $this->wikisourcePageName($work['article']);
        }

        // Get all editions of this work,
        // to find the Wikisource mainspace page or Index page.
//        $editions = $this->getXml("SELECT ?edition ?about ?indexPage
//                WHERE {
//                    ?edition wdt:P629 wd:" . basename($work['work']) . " . 
//                    OPTIONAL{ ?edition wdt:P1957 ?indexPage } .
//                    OPTIONAL{ ?about schema:about ?edition } .
//                    SERVICE wikibase:label { bd:serviceParam wikibase:language '" . $this->lang . "' }
//                }");
//        foreach ($editions->results->result as $ed) {
//            $edition = $this->getBindings($ed);
//            // Get the Wikisource page name for the edition.
//            if (empty($works[$id]['wikisource']) && !empty($edition['about'])) {
//                $works[$id]['wikisource'] = $this->wikisourcePageName($edition['about']);
//            }
//            // Wikisource "Index:" page.
//            if (empty($works[$id]['index_page']) && !empty($edition['indexPage'])) {
//                $works[$id]['index_page'] = $this->wikisourcePageName($edition['indexPage']);
//            }
//        }
    }

    private function getXml($query)
    {
        $url = "https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=" . urlencode($query);
        $result = file_get_contents($url);
        $xml = new \SimpleXmlElement($result);
        return $xml;
    }

    private function getBindings($xml)
    {
        $out = [];
        foreach ($xml->binding as $binding) {
            if (isset($binding->literal)) {
                $out[(string) $binding['name']] = (string) $binding->literal;
            }
            if (isset($binding->uri)) {
                $out[(string) $binding['name']] = (string) $binding->uri;
            }
        }
        //print_r($out);
        return $out;
    }

    /**
     * Get the page name from a Wikisource URL.
     * @param string $url
     * @return string
     */
    private function wikisourcePageName($url)
    {
        if (!empty($url) && strpos($url, "$this->lang.wikisource") !== false) {
            $strPrefix = strlen("https://" . $this->lang . ".wikisource.org/wiki/");
            return urldecode(substr($url, $strPrefix));
        }
        return '';
    }
}
