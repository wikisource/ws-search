<?php

declare( strict_types=1 );

// Make sure Composer has been set up (for installation from Git, mostly).
if ( !file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	echo '<p>Please run <kbd>composer install</kbd> prior to using App.</p>';
	exit( 1 );
}

require __DIR__ . '/vendor/autoload.php';
