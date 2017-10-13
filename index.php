<?php

require_once __DIR__.'/bootstrap.php';

$dispatcher = FastRoute\simpleDispatcher( function ( FastRoute\RouteCollector $r ) {
	$r->addRoute( 'GET', '/', 'HomeController::index' );
	$r->addRoute( 'GET', '/wikidata', 'WikidataController::index' );
	$r->addRoute( 'GET', '/login', 'UserController::loginForm' );
	$r->addRoute( 'POST', '/login', 'UserController::login' );
	$r->addRoute( 'GET', '/logout', 'UserController::logout' );
	$r->addRoute( 'GET', '/register', 'UserController::registerForm' );
	$r->addRoute( 'POST', '/register', 'UserController::register' );
	$r->addRoute( 'GET', '/remind/{userid}/{token}', 'UserController::remindResetForm' );
	$r->addRoute( 'POST', '/remind/{userid}/{token}', 'UserController::remindReset' );
	$r->addRoute( 'GET', '/remind', 'UserController::remindForm' );
	$r->addRoute( 'POST', '/remind', 'UserController::remind' );
	$r->addRoute( 'GET', '/new', 'BookController::newBook' );
} );

// Fetch method and URI from somewhere
$uri = rawurldecode( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
$baseUrl = substr( $_SERVER['SCRIPT_NAME'], 0, -( strlen( basename( __FILE__ ) ) ) );
$route = substr( $uri, strlen( App\Config::baseUrl() ) );
$routeInfo = $dispatcher->dispatch( $_SERVER['REQUEST_METHOD'], $route );
try {
	switch ( $routeInfo[0] ) {
		case FastRoute\Dispatcher::NOT_FOUND:
			http_response_code( 404 );
			throw new \Exception( "Not found: $route", 404 );
			break;
		case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
			http_response_code( 405 );
			throw new \Exception( "Method not allowed", 405 );
			break;
		case FastRoute\Dispatcher::FOUND:
			$handler = explode( '::', $routeInfo[1] );
			$vars = $routeInfo[2];
			$controllerName = '\\App\\Controllers\\' . $handler[0];
			if ( !class_exists( $controllerName ) ) {
				throw new \Exception( "Class not found: $controllerName" );
			}
			$controller = new $controllerName();
			$action = $handler[1];
			$controller->$action( $vars );
			exit( 0 );
			break;
		default:
			http_response_code( 500 );
			echo "Something odd has happened, sorry. ";
			exit( 1 );
	}
} catch ( \Exception $e ) {
	// var_dump($e);exit();
	$template = new App\Template( 'error_page.twig' );
	$template->title = 'Error';
	$template->e = $e;
	echo $template->render();
	exit( 1 );
}
