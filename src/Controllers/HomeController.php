<?php

namespace App\Controllers;

class HomeController extends ControllerBase
{

    public function index()
    {
        $template = new \App\Template('home.twig');
        $template->title = \App\Config::siteTitle();
        $template->user = $this->user;
        $template->form_vals = $_GET;

        $query = new \App\BookQuery();

        $template->lang = (!empty($_GET['lang'])) ? $_GET['lang'] : 'en';
        $query->setLang($template->lang);

        if (!empty($_GET['title'])) {
            $query->setTitle($_GET['title']);
        }
        if (!empty($_GET['author'])) {
            $query->setAuthor($_GET['author']);
        }

        $template->books = $query->run();

        echo $template->render();
    }
}
