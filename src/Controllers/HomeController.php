<?php

namespace App\Controllers;

use App\Text;

class HomeController extends ControllerBase
{

    public function getTranslations()
    {
        return $t;
    }

    public function index()
    {

        // Output format.
        $outputFormats = [
            'list' => 'List',
            'table' => 'Table',
            'csv' => 'CSV',
        ];
        $outputFormat = 'list';
        if (isset($_GET['output_format'])) {
            $outputFormat = $_GET['output_format'];
        }
        if (!in_array($outputFormat, array_keys($outputFormats))) {
            $outputFormat = 'list';
        }

        // Has index page.
        $hasIndexOptions = ['na' => 'N/A', 'yes' => 'Yes', 'no' => 'No'];
        $hasIndex = 'na';
        if (isset($_GET['has_index']) && in_array($_GET['has_index'], array_keys($hasIndexOptions))) {
            $hasIndex = $_GET['has_index'];
        }

        // Assemble the template.
        $template = new \App\Template($outputFormat . '.twig');
        $template->outputFormats = $outputFormats;
        $template->hasIndexOptions = $hasIndexOptions;
        $template->hasIndex = $hasIndex;
        $template->title = \App\Config::siteTitle();
        $template->user = $this->user;
        $template->form_vals = $_GET;

        $template->langs = $this->db->query("SELECT DISTINCT languages.* FROM `languages` "
                . " JOIN works ON works.language_id = languages.id "
                . " ORDER BY `code`")->fetchAll();
        $template->lang = (!empty($_GET['lang'])) ? $_GET['lang'] : 'en';
        $template->totalWorks = $this->db->query("SELECT COUNT(*) FROM works")->fetchColumn();

        $params = ['lang' => $template->lang];
        $sqlTitleWhere = '';
        if (!empty($_GET['title'])) {
            $sqlTitleWhere = 'AND `works`.`pagename` LIKE :title ';
            $params['title'] = '%' . $_GET['title'] . '%';
        }

        $sqlAuthorWhere = '';
        if (!empty($_GET['author'])) {
            $sqlAuthorWhere = 'AND `authors`.`pagename` LIKE :author ';
            $params['author'] = '%' . $_GET['author'] . '%';
        }

        // Has index page.
        $sqlHasIndex = '';
        if ($hasIndex === 'yes') {
            $sqlHasIndex = ' AND index_pages.id IS NOT NULL ';
        } elseif ($hasIndex === 'no') {
            $sqlHasIndex = ' AND index_pages.id IS NULL ';
        }

        // If nothing has been searched, return the empty template.
        if (!$sqlTitleWhere && !$sqlAuthorWhere && !$sqlHasIndex) {
            echo $template->render();
            return;
        }

        // Otherwise, build the queries.
        $sql = "SELECT DISTINCT "
            . "   works.*,"
            . "   MIN(index_pages.quality) AS quality,"
            . "   p.name AS publisher_name,"
            . "   p.location AS publisher_location "
            . " FROM works "
            . " JOIN languages l ON l.id=works.language_id "
            . " JOIN authors_works aw ON aw.work_id = works.id "
            . " JOIN authors ON authors.id = aw.author_id"
            . " LEFT JOIN publishers p ON p.id=works.publisher_id"
            . " LEFT JOIN works_indexes wi ON wi.work_id = works.id "
            . " LEFT JOIN index_pages ON wi.index_page_id = index_pages.id "
            . "WHERE "
            . " l.code = :lang $sqlTitleWhere $sqlAuthorWhere $sqlHasIndex "
            . "GROUP BY works.id ";
        $works = [];
        foreach ($this->db->query($sql, $params)->fetchAll() as $work) {

            // Find authors.
            $sqlAuthors = "SELECT a.* FROM authors a "
                . " JOIN authors_works aw ON aw.author_id = a.id"
                . " WHERE aw.work_id = :wid ";
            $authors = $this->db->query($sqlAuthors, ['wid' => $work->id])->fetchAll();
            $work->authors = $authors;

            // Find index pages.
            $sqlIndexPages = "SELECT ip.* FROM index_pages ip "
                . " JOIN works_indexes wi ON wi.index_page_id = ip.id "
                . " WHERE wi.work_id = :wid ";
            $indexPages = $this->db->query($sqlIndexPages, ['wid' => $work->id])->fetchAll();
            $work->indexPages = $indexPages;

            $works[] = $work;
        }

        $template->works = $works;
        if ($outputFormat == 'csv') {
            $this->outputCsv($template->lang, $works);
        } else {
            echo $template->render();
        }
    }

    public function outputCsv($langCode, $works)
    {
        $out = "Wikidata,Page name,Title,Authors,Year,Publisher,Categories,Proofreading status,Index pages,Cover images\n";
        foreach ($works as $work) {
            $authors = [];
            foreach ($work->authors as $author) {
                $authors[] = $author->pagename;
            }
            $indexPages = [];
            $coverUrls = [];
            if (isset($work->indexPages)) {
                foreach ($work->indexPages as $indexPage) {
                    $indexPages[] = $indexPage->pagename;
                    $coverUrls[] = $indexPage->cover_image_url;
                }
            }
            $out .= $work->wikidata_item . ","
                . Text::csvCell($work->pagename) . ","
                . Text::csvCell($work->title) . ","
                . Text::csvCell($authors) . ","
                . Text::csvCell($work->year) . ","
                . Text::csvCell($work->publisher_name) . ","
                . Text::csvCell($work->publisher_location) . ","
                . Text::csvCell($work->quality) . ","
                . Text::csvCell($indexPages) . ","
                . Text::csvCell($coverUrls)."\n";
        }
        //header("content-type:text/plain");echo $out;exit();
        $this->sendFile('csv', 'text/csv', $out, "wikisource_$langCode");
    }
}
