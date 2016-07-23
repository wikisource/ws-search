<?php

namespace App\Controllers;

class HomeController extends ControllerBase
{

    public function index()
    {
        $outputFormats = [
            'list' => 'List',
            'table' => 'Table',
            'csv' => 'CSV',
        ];
        if (isset($_GET['output_format'])) {
            $outputFormat = $_GET['output_format'];
        }
        if (!in_array($outputFormat, array_keys($outputFormats))) {
            $outputFormat = 'list';
        }
        $template = new \App\Template($outputFormat.'.twig');
        $template->outputFormats = $outputFormats;
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

        // If nothing has been searched, return the empty template.
        if (!$sqlTitleWhere && !$sqlAuthorWhere) {
            echo $template->render();
            return;
        }

        // Otherwise, build the queries.
        $sql = "SELECT DISTINCT "
            . "   works.*,"
            . "   MIN(ip.`quality`) AS `quality`,"
            . "   p.name AS publisher_name,"
            . "   p.location AS publisher_location "
            . " FROM works "
            . " JOIN languages l ON l.id=works.language_id "
            . " JOIN authors_works aw ON aw.work_id = works.id "
            . " JOIN authors ON authors.id = aw.author_id"
            . " LEFT JOIN publishers p ON p.id=works.publisher_id"
            . " LEFT JOIN works_indexes wi ON wi.work_id = works.id "
            . " LEFT JOIN index_pages ip ON wi.index_page_id = ip.id "
            . "WHERE "
            . " l.code = :lang $sqlTitleWhere $sqlAuthorWhere "
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
        //header('content-type:text/plain');print_r($works);exit();

        $template->works = $works;
        echo $template->render();
    }
}
