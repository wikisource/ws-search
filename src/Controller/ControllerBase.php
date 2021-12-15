<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

abstract class ControllerBase extends AbstractController {

	/** @var Connection */
	protected $db;

	/**
	 * @param Connection $connection
	 */
	public function __construct( Connection $connection ) {
		$this->db = $connection;
	}

	/**
	 * @param string $ext The file extension, with no leading dot.
	 * @param string $mime The mime type.
	 * @param string $content The file contents.
	 * @param string|bool $downloadName The filename, or false to default to today's date.
	 * @return Response
	 */
	protected function sendFile( $ext, $mime, $content, $downloadName = false ) {
		$filename = $downloadName ?: date( 'Y-m-d' );
		$downloadName = $filename . '.' . trim( $ext, '.' );
		$response = new Response( $content );
		$response->headers->set( 'Content-Encoding', 'UTF-8' );
		$response->headers->set( 'Content-type', $mime . '; charset=UTF-8' );
		$dispositionHeader = $response->headers->makeDisposition(
			ResponseHeaderBag::DISPOSITION_ATTACHMENT,
			$downloadName
		);
		$response->headers->set( 'Content-Disposition', $dispositionHeader );
		return $response;
	}
}
