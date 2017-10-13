<?php

namespace App\Controllers;

use App\Config;
use App\Template;

class WikidataController extends ControllerBase {

	/**
	 *
	 */
	public function index() {
		$template = new Template( 'wikidata.twig' );
		$template->title = Config::siteTitle();
		$template->form_vals = $_GET;
		echo $template->render();
	}
}
