<?php

namespace App;

use App\Database\Database;
use Twig_Loader_Filesystem;

class Template {

	/** @var string */
	protected $templateName;

	/** @var string */
	protected $templateString;

	/** @var string[] */
	protected $data;

	/** @var /Twig_Loader_Filesystem */
	protected $loader;

	/** @var string The name of the session variable used to store notices. */
	protected $transientNotices;

	/**
	 * Create a new template either with a file-based Twig template, or a Twig string.
	 * @param string|bool $templateName The template to use. Use this or $templateString.
	 * @param string|bool $templateString The Twig code to use. Use this or $templateName.
	 */
	public function __construct( $templateName = false, $templateString = false ) {
		$this->templateName = $templateName;
		$this->templateString = $templateString;
		$this->transientNotices = 'notices';
		$notices = isset( $_SESSION[$this->transientNotices] ) ? $_SESSION[$this->transientNotices] : [];
		$this->data = [
			'app_version' => Config::version(),
			'notices' => $notices,
			'baseurl' => Config::baseUrl(),
			'debug' => Config::debug(),
			'site_title' => Config::siteTitle(),
		];
		$this->loader = new Twig_Loader_Filesystem( [ __DIR__ . '/../templates' ] );
	}

	/**
	 * @param string $newName The template name.
	 */
	public function setTemplateName( $newName ) {
		$this->templateName = $newName;
	}

	/**
	 * Get the Filesystem template loader.
	 * @return Twig_Loader_Filesystem
	 */
	public function getLoader() {
		return $this->loader;
	}

	/**
	 * Get a list of templates in a given directory, across all registered template paths.
	 * @param string $directory The template directory.
	 * @return string[]
	 */
	public function getTemplates( $directory ) {
		$templates = [];
		foreach ( $this->getLoader()->getPaths() as $path ) {
			$dir = $path . '/' . ltrim( $directory, '/' );
			foreach ( preg_grep( '/^[^\.].*\.(twig|html)$/', scandir( $dir ) ) as $file ) {
				$templates[] = $directory . '/' . $file;
			}
		}
		return $templates;
	}

	/**
	 * @param string $name The data item to set.
	 * @param string $value The data item's value.
	 */
	public function __set( $name, $value ) {
		$this->data[$name] = $value;
	}

	/**
	 * Find out whether a given item of template data is set.
	 *
	 * @param string $name The property name.
	 * @return bool
	 */
	public function __isset( $name ) {
		return isset( $this->data[$name] );
	}

	/**
	 * Get an item from this template's data.
	 *
	 * @param string $name The data item's name.
	 * @return mixed
	 */
	public function __get( $name ) {
		return $this->data[$name];
	}

	/**
	 * Add a notice. All notices are saved to a Transient, which is deleted when
	 * the template is rendered but otherwise available to all subsequent
	 * instances of the Template class.
	 * @param string $type Either 'updated' or 'error'.
	 * @param string $message The message to display.
	 */
	public function addNotice( $type, $message ) {
		$this->data['notices'][] = [
			'type' => $type,
			'message' => $message,
		];
		if ( isset( $_SESSION ) ) {
			$_SESSION[$this->transientNotices] = $this->data['notices'];
		}
	}

	/**
	 * Render the template and return it.
	 * @return string
	 */
	public function __toString() {
		return $this->render();
	}

	/**
	 * Render the template and return the output.
	 * @return string
	 */
	public function render() {
		if ( isset( $_SESSION[$this->transientNotices] ) ) {
			unset( $_SESSION[$this->transientNotices] );
		}
		$twig = new \Twig_Environment( $this->loader );

		// Add titlecase filter.
		$titlecase_filter = new \Twig_SimpleFilter( 'titlecase', '\\App\\Text::titlecase' );
		$twig->addFilter( $titlecase_filter );

		// Add strtolower filter.
		$strtolower_filter = new \Twig_SimpleFilter( 'strtolower', function ( $str ) {
			if ( is_array( $str ) ) {
				return array_map( 'strtolower', $str );
			} else {
				return strtolower( $str );
			}
		} );
		$twig->addFilter( $strtolower_filter );

		// Enable debugging.
		if ( Config::debug() ) {
			$this->queries = Database::getQueries();
			$twig->enableDebug();
			$twig->addExtension( new \Twig_Extension_Debug() );
		}

		// Render the template.
		if ( !empty( $this->templateString ) ) {
			$template = $twig->createTemplate( $this->templateString );
		} else {
			$template = $twig->loadTemplate( $this->templateName );
		}
		return $template->render( $this->data );
	}
}
