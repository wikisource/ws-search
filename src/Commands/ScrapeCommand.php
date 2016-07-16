<?php

namespace App\Commands;

use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\FluentRequest;
use GetOptionKit\OptionCollection;
use Dflydev\DotAccessData\Data;

class ScrapeCommand extends CommandBase
{

    /** @var \App\Database */
    private $db;

    /** @var integer The database ID of the language that is currently being processed. */
    private $currentLangId;

    /** @var string The two- or three-lettered language code of the language that is currently being processed. */
    private $currentLangCode;

    public function getCliOptions()
    {
        $options = new OptionCollection;
        $options->add('l|lang?string');
        $options->add('t|title?string');
        $options->add('langs');
        $options->add('nuke');
        return $options;
    }

    public function run()
    {
        $this->db = new \App\Database;
        if ($this->options->get('nuke')) {
            $this->nukeData();
        }
        if ($this->options->get('langs')) {
            $langIds = $this->getWikisourceLangEditions();
            /*
              foreach ($langIds as $langCode => $langId) {
              $this->currentLangId = $langId;
              $this->getAllWorks($langCode);
              } */
        }

        // All works in one language Wikisource.
        if ($this->options->get('lang') && !$this->options->get('title')) {
            $langCode = $this->options->get('lang');
            $this->setCurrentLang($langCode);
            $this->getAllWorks($langCode);
        }

        // A single work from a single Wikisource.
        if ($this->options->get('title')) {
            $langCode = $this->options->get('lang');
            if (!$langCode) {
                $this->write("You must also specify the Wikisource language code, with --lang=xx");
                exit(1);
            }
            $this->setCurrentLang($langCode);
            $this->getSingleMainspaceWork($this->options->get('title'));
        }
    }

    public function setCurrentLang($code)
    {
        $sql = "SELECT id FROM languages WHERE code=:code";
        $this->currentLangId = $this->db->query($sql, ['code' => $code])->fetchColumn();
        $this->currentLangCode = $code;
        if (!$this->currentLangId) {
            throw new \Exception("Unable to load language '$code'");
        }
    }

    private function getAllWorks($langCode)
    {
        $this->write("Getting works from '$langCode' Wikisource.");
        $request = FluentRequest::factory()
            ->setAction('query')
            ->setParam('list', 'allpages')
            ->setParam('apnamespace', 0)
            ->setParam('apfilterredir', 'nonredirects')
            ->setParam('aplimit', 500);
        $allpages = $this->completeQuery($request, 'query.allpages', [$this, 'getSingleMainspaceWork']);
        print_r($allpages);
        exit();
    }

    protected function getSingleMainspaceWork($pagename)
    {
        $this->write("Importing from $this->currentLangCode: " . $pagename);

        // If this is a subpage, determine the mainpage.
        if (strpos($pagename, '/') !== false) {
            $rootPageName = substr($pagename, 0, strpos($pagename, '/'));
        } else {
            $rootPageName = $pagename;
        }

        // Retrieve the page text, Index links (i.e. templates in the right NS), and categories.
        $req = FluentRequest::factory()
            ->setAction('parse')
            ->setParam('page', $pagename)
            ->setParam('prop', 'text|templates|categories');
        $pageInfo = new Data($this->completeQuery($req, 'parse'));

        // Deal with the data from within the page text.
        $pageHtml = $pageInfo->get('text.*');
        $pageXml = new \SimpleXMLElement("<div>$pageHtml</div>");
        // Pull the microformatted-defined attributes.
        $ids = ['ws-title', 'ws-author', 'ws-year'];
        $microformatVals = [];
        foreach ($ids as $i) {
            $titleElements = $pageXml->xpath("//*[@id='$i']/text()");
            $microformatVals[$i] = (string) array_shift($titleElements);
        }

        // Save all to the database.
        $sql = "INSERT IGNORE INTO works SET `language_id`=:lid, `pagename`=:pagename, `title`=:title, `year`=:year";
        $insertParams = [
            'lid' => $this->currentLangId,
            'pagename' => $rootPageName,
            'title' => $microformatVals['ws-title'],
            'year' => $microformatVals['ws-year'],
        ];
        $this->db->query($sql, $insertParams);
        // Save the authors.
        $workId = $this->getWorkId($rootPageName);
        $authors = explode('/', $microformatVals['ws-author']);
        foreach ($authors as $author) {
            $authorId = $this->getOrCreateRecord('authors', $author);
            $sqlAuthorJoin = 'INSERT IGNORE INTO `authors_works` SET author_id=:a, work_id=:w';
            $this->db->query($sqlAuthorJoin, ['a' => $authorId, 'w' => $workId]);
        }

        // Link the Index pages (i.e. templates of NS106 etc.).
        foreach ($pageInfo->get('templates') as $tpl) {
            if ($tpl['ns'] === 106) {
                $this->write(" -- linking an index page: " . $tpl['*']);
                $indexPageName = $tpl['*'];
                $indexPageId = $this->getOrCreateRecord('index_pages', $indexPageName);
                $sqlAuthorJoin = 'INSERT IGNORE INTO `works_indexes` SET index_page_id=:ip, work_id=:w';
                $this->db->query($sqlAuthorJoin, ['ip' => $indexPageId, 'w' => $workId]);
            }
        }
    }

    /**
     * Get the ID of a 'authors' or 'index_pages' record, creating it if it doesn't exist.
     *
     * @param string $table Either 'authors' or 'index_pages'.
     * @param string $pagename The pagename to query and/or save.
     * @return integer The ID of the record.
     */
    public function getOrCreateRecord($table, $pagename)
    {
        $params = ['pagename' => $pagename, 'l' => $this->currentLangId];
        $sqlInsert = "INSERT IGNORE INTO `$table` SET `language_id`=:l, `pagename`=:pagename";
        $this->db->query($sqlInsert, $params);
        $sqlSelect = "SELECT `id` FROM `$table` WHERE `language_id`=:l AND `pagename`=:pagename";
        return $this->db->query($sqlSelect, $params)->fetchColumn();
    }

    public function getAuthorId($name)
    {
        /*
          $params = ['name' => $name, 'l' => $this->currentLangId];
          $sqlInsert = 'INSERT IGNORE INTO `authors` SET `language_id`=:l, `pagename`=:name';
          $this->db->query($sqlInsert, $params);
          $sqlSelect = 'SELECT `id` FROM `authors` WHERE `language_id`=:l AND `pagename`=:name';
          return $this->db->query($sqlSelect, $params)->fetchColumn();
         */
    }

    public function getWorkId($pagename)
    {
        $params = ['pagename' => $pagename, 'l' => $this->currentLangId];
        $sqlSelect = 'SELECT `id` FROM `works` WHERE `language_id`=:l AND `pagename`=:pagename';
        return $this->db->query($sqlSelect, $params)->fetchColumn();
    }

    public function completeQuery(FluentRequest $request, $resultKey, $callback = false)
    {
        $api = new MediawikiApi("https://$this->currentLangCode.wikisource.org/w/api.php");
        $data = [];
        $continue = true;
        do {
            // Send request and save data for later returning.
            $result = new Data($api->getRequest($request));
            $restultingData = $result->get($resultKey);
            $data = array_merge_recursive($data, $restultingData);

            // If a callback is specified, call it for each of the current result set.
            if (is_callable($callback)) {
                foreach ($restultingData as $datum) {
                    call_user_func($callback, $datum['title']);
                }
            }

            // Whether to continue or not.
            if ($result->get('continue', false)) {
                $request->addParams($result->get('continue'));
            } else {
                $continue = false;
            }
        } while ($continue);

        return $data;
    }

    private function nukeData()
    {
        $this->write("Deleting all data in the database!");
        $this->db->query("SET foreign_key_checks = 0");
        $this->db->query("TRUNCATE `works`");
        $this->db->query("TRUNCATE `languages`");
        $this->db->query("SET foreign_key_checks = 1");
    }

    private function getWikisourceLangEditions()
    {
        $this->write("Getting list of Wikisource languages");
        $query = "SELECT ?langCode ?langName WHERE { "
            . "?item wdt:P31 wd:Q15156455 . " // Instance of Wikisource language edition
            . "?item wdt:P424 ?langCode . " // Wikimedia language code
            . "?item wdt:P407 ?lang . " // language of work or name
            . "?lang rdfs:label ?langName . FILTER(LANG(?langName) = ?langCode) . " // RDF label of the language, in the language
            . "}";
        $xml = $this->getXml($query);
        $langIds = [];
        foreach ($xml->results->result as $res) {
            $langInfo = $this->getBindings($res);
            $sql = "INSERT INTO languages SET code=:code, label=:label";
            $this->db->query($sql, ['code' => $langInfo['langCode'], 'label' => $langInfo['langName']]);
            $langIds[$langInfo['langCode']] = $this->db->lastInsertId();
        }
        return $langIds;
    }

    private function getWorks($offset)
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
    }

    private function totalBookCount()
    {
        $countQuery = "SELECT (COUNT(*) AS ?count) 
            WHERE { ?type wdt:P279* wd:Q571 . ?work wdt:P31 ?type }";
        $countXml = $this->getXml($countQuery);
        $count = (int) $countXml->results->result->binding->literal[0];
        $this->write('Wikidata: ' . number_format($count) . " books found");
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

    private function getXml($query)
    {
        //echo "\n$query\n\n";
        $url = "https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=" . urlencode($query);
        $result = file_get_contents($url);
        $xml = new \SimpleXmlElement($result);
        return $xml;
    }

    /**
     * Get the page name from a Wikisource URL.
     * @param string $url
     * @return string
     */
    private function wikisourcePageName($url)
    {
        $wikiLang = 'en';
        if (!empty($url) && strpos($url, "$wikiLang.wikisource") !== false) {
            $strPrefix = strlen("https://$wikiLang.wikisource.org/wiki/");
            return urldecode(substr($url, $strPrefix));
        }
        return '';
    }
}
