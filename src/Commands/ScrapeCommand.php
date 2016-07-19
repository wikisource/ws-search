<?php

namespace App\Commands;

use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\FluentRequest;
use GetOptionKit\OptionCollection;
use Dflydev\DotAccessData\Data;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeCommand extends CommandBase
{

    /** @var \App\Database */
    private $db;

    /**
     * @var \stdClass The database ID ('id'), text code ('code'), and Index NS ID ('index_ns_id')
     * of the language that is currently being processed.
     */
    private $currentLang;

    public function getCliOptions()
    {
        $options = new OptionCollection;
        $options->add('l|lang?string', 'The language code of the Wikisource to scrape');
        $options->add('t|title?string', 'The title of a single page to scrape, must be combined with --lang');
        $options->add('langs', 'Retrieve metadata about all language Wikisources');
        return $options;
    }

    public function run()
    {
        $this->db = new \App\Database;
        if ($this->cliOptions->langs) {
            $this->getWikisourceLangEditions();
        }

        // All works in one language Wikisource.
        if ($this->cliOptions->lang && !$this->cliOptions->title) {
            $langCode = $this->cliOptions->lang;
            $this->setCurrentLang($langCode);
            $this->getAllWorks($langCode);
        }

        // A single work from a single Wikisource.
        if ($this->cliOptions->title) {
            $langCode = $this->cliOptions->lang;
            if (!$langCode) {
                $this->write("You must also specify the Wikisource language code, with --lang=xx");
                exit(1);
            }
            $this->setCurrentLang($langCode);
            $this->getSingleMainspaceWork($this->cliOptions->title);
        }

        // If nothing else is specified, scrape everything.
        if (empty($this->cliOptions->toArray())) {
            $langCodes = $this->getWikisourceLangEditions();
            foreach ($langCodes as $lc) {
                $this->setCurrentLang($lc);
                $this->getAllWorks($lc);
            }
        }
    }

    public function setCurrentLang($code)
    {
        $sql = "SELECT * FROM languages WHERE code=:code";
        $this->currentLang = $this->db->query($sql, ['code' => $code])->fetch();
        if (!$this->currentLang) {
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
        $this->completeQuery($request, 'query.allpages', [$this, 'getSingleMainspaceWork']);
    }

    protected function getSingleMainspaceWork($pagename)
    {
        $this->writeDebug("Importing from {$this->currentLang->code}: " . $pagename);

        // Retrieve the page text, Index links (i.e. templates in the right NS), and categories.
        $requestParse = FluentRequest::factory()
            ->setAction('parse')
            ->setParam('page', $pagename)
            ->setParam('prop', 'text|templates|categories');
        $pageParse = new Data($this->completeQuery($requestParse, 'parse'));
        // Normalize the pagename.
        $pagename = $pageParse->get('title');

        // Get the Wikidata Item.
        $requestProps = FluentRequest::factory()
            ->setAction('query')
            ->setParam('titles', $pagename)
            ->setParam('prop', 'pageprops')
            ->setParam('ppprop', 'wikibase_item');
        $pageProps = $this->completeQuery($requestProps, 'query.pages');
        $pagePropsSingle = new Data(array_shift($pageProps));
        $wikibaseItem = $pagePropsSingle->get('pageprops.wikibase_item');

        // If this is a subpage, determine the mainpage.
        if (strpos($pagename, '/') !== false) {
            $rootPageName = substr($pagename, 0, strpos($pagename, '/'));
        } else {
            $rootPageName = $pagename;
        }

        // Deal with the data from within the page text.
        // Note the slightly odd way of ensuring the HTML content is loaded as UTF8.
        $pageHtml = $pageParse->get('text.*');
        $pageCrawler = new Crawler;
        $pageCrawler->addHTMLContent("<div>$pageHtml</div>", 'UTF-8');
        // Pull the microformatted-defined attributes.
        $microformatIds = ['ws-title', 'ws-author', 'ws-year'];
        $microformatVals = [];
        foreach ($microformatIds as $i) {
            $el = $pageCrawler->filterXPath("//*[@id='$i']");
            $microformatVals[$i] = ($el->count() > 0) ? $el->text() : '';
        }

        // Save basic work data to the database. It might already be there, if this is a subpage, in which case we don't
        // really care that this won't do anything, and this is just as fast as checking whether it exists or not.
        $sql = "INSERT IGNORE INTO `works` SET"
            . " `language_id`=:lid,"
            . " `wikidata_item`=:wikidata_item,"
            . " `pagename`=:pagename,"
            . " `title`=:title,"
            . " `year`=:year";
        $insertParams = [
            'lid' => $this->currentLang->id,
            'wikidata_item' => $wikibaseItem,
            'pagename' => $rootPageName,
            'title' => (!empty($microformatVals['ws-title'])) ? $microformatVals['ws-title'] : $pagename,
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

        // Link the Index pages (i.e. 'templates' that are in the right NS.).
        foreach ($pageParse->get('templates') as $tpl) {
            if ($tpl['ns'] === (int) $this->currentLang->index_ns_id) {
                $this->writeDebug(" -- Linking an index page: " . $tpl['*']);
                $indexPageName = $tpl['*'];
                $indexPageId = $this->getOrCreateRecord('index_pages', $indexPageName);
                $sqlAuthorJoin = 'INSERT IGNORE INTO `works_indexes` SET index_page_id=:ip, work_id=:w';
                $this->db->query($sqlAuthorJoin, ['ip' => $indexPageId, 'w' => $workId]);
            }
        }

        // Save the categories.
        foreach ($pageParse->get('categories') as $cat) {
            if (isset($cat['hidden'])) {
                continue;
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
        $params = ['pagename' => $pagename, 'l' => $this->currentLang->id];
        $sqlInsert = "INSERT IGNORE INTO `$table` SET `language_id`=:l, `pagename`=:pagename";
        $this->db->query($sqlInsert, $params);
        $sqlSelect = "SELECT `id` FROM `$table` WHERE `language_id`=:l AND `pagename`=:pagename";
        return $this->db->query($sqlSelect, $params)->fetchColumn();
    }

    public function getWorkId($pagename)
    {
        $params = ['pagename' => $pagename, 'l' => $this->currentLang->id];
        $sqlSelect = 'SELECT `id` FROM `works` WHERE `language_id`=:l AND `pagename`=:pagename';
        return $this->db->query($sqlSelect, $params)->fetchColumn();
    }

    public function completeQuery(FluentRequest $request, $resultKey, $callback = false)
    {
        $api = new MediawikiApi("https://" . $this->currentLang->code . ".wikisource.org/w/api.php");
        $data = [];
        $continue = true;
        do {
            // Send request and save data for later returning.
            $result = new Data($api->getRequest($request));
            $restultingData = $result->get($resultKey);
            if (!is_array($restultingData)) {
                $continue = false;
                continue;
            }
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
        $languageCodes = [];
        foreach ($xml->results->result as $res) {
            $langInfo = $this->getBindings($res);

            // Find the Index NS identifier.
            $req = FluentRequest::factory()
                ->setAction('query')
                ->setParam('meta', 'siteinfo')
                ->setParam('siprop', 'namespaces');
            // This next bit is a bit hacky :(
            $this->currentLang = new \stdClass();
            $this->currentLang->code = $langInfo['langCode'];
            $namespaces = $this->completeQuery($req, 'query.namespaces');
            $indexNsId = null; // Some don't have ProofreadPage extension installed.S
            foreach ($namespaces as $ns) {
                if (isset($ns['canonical']) && $ns['canonical'] === 'Index') {
                    $indexNsId = $ns['id'];
                }
            }

            // Put all of this site's info together.
//            $wikisourceSites[$langInfo['langCode']] = new \stdClass();
//                'code' => $langInfo['langCode'],
//                'id' => $this->db->lastInsertId(),
//                'index_ns' => $indexNsId,
//            ];
            $languageCodes[] = $langInfo['langCode'];

            // Save the language info to the DB.
            $sql = "INSERT IGNORE INTO languages SET code=:code, label=:label, index_ns_id=:ns";
            $params = [
                'code' => $langInfo['langCode'],
                'label' => $langInfo['langName'],
                'ns' => $indexNsId,
            ];
            $this->writeDebug(" -- Saving " . $params['label'] . ' (' . $params['code'] . ')');
            $this->db->query($sql, $params);
        }
        return $languageCodes;
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
