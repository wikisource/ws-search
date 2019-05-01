<?php

namespace App\Controllers;

use App\Database\Database;

abstract class ControllerBase {

	/** @var Database */
	protected $db;

	public function __construct() {
		$this->db = new Database();
	}

	/**
	 * @param string $route The URL route to redirect to.
	 */
	protected function redirect( $route ) {
		$url = \App\Config::baseUrl() . '/' . ltrim( $route, '/ ' );
		http_response_code( 303 );
		header( "Location: $url" );
		exit( 0 );
	}

	/**
	 * @param string $ext The file extension, with no leading dot.
	 * @param string $mime The mime type.
	 * @param string $content The file contents.
	 * @param string|bool $downloadName The filename, or false to default to today's date.
	 */
	protected function sendFile( $ext, $mime, $content, $downloadName = false ) {
		$filename = $downloadName ?: date( 'Y-m-d' );
		$downloadName = $filename . '.' . trim( $ext, '.' );
		header( 'Content-Encoding: UTF-8' );
		header( 'Content-type: ' . $mime . '; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $downloadName . '"' );
		echo $content;
		exit( 0 );
	}
}
