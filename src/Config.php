<?php

namespace App;

class Config {

	/**
	 * Get the current application version number (compatible with the Semantic Versioning Guidelines).
	 * @return string Application version.
	 */
	public static function version() {
		return '0.4.0';
	}

	public static function configFile() {
		$envConfig = getenv( 'APP_CONFIG_FILE' );
		$configFile = ( $envConfig ) ? $envConfig : __DIR__ . '/../config.php';
		return realpath( $configFile );
	}

	protected static function get( $name, $default = null ) {
		if ( file_exists( self::configFile() ) ) {
			require self::configFile();
			if ( isset( $$name ) ) {
				return $$name;
			}
		}
		return $default;
	}

	public static function debug() {
		return (bool)self::get( 'debug', false );
	}

	/**
	 * Get the Base URL of the application. Never has a trailing slash.
	 * @return string
	 */
	public static function baseUrl() {
		$calculatedBaseUrl = substr( $_SERVER['SCRIPT_NAME'], 0, -( strlen( 'index.php' ) ) );
		$baseUrl = self::get( 'baseUrl', $calculatedBaseUrl );
		return rtrim( $baseUrl, ' /' );
	}

	public static function siteTitle() {
		return self::get( 'siteTitle', 'Wikisource Search' );
	}

	public static function siteEmail() {
		return filter_var( self::get( 'siteEmail' ), FILTER_VALIDATE_EMAIL );
	}

	public static function databaseHost() {
		return self::get( 'databaseHost', 'localhost' );
	}

	public static function databaseName() {
		return self::get( 'databaseName', 'app' );
	}

	public static function databaseUser() {
		return self::get( 'databaseUser', 'app' );
	}

	public static function databasePassword() {
		return self::get( 'databasePassword', '' );
	}

	public static function storageDirData( $subdir = '' ) {
		return self::storageDir( 'storageDirData', 'data', $subdir );
	}

	/**
	 * Get the full path to the directory used to store temporary export files.
	 * This is a separate configuration variable to the storageDirTmp variable
	 * because on some systems MySQL will only be allowed to write to some
	 * preconfigured directories.
	 * @return string The full filesystem path to the export directory.
	 */
	public static function storageDirExport() {
		return self::get( 'storageDirExport', self::storageDirTmp( 'export' ) );
	}

	public static function storageDirTmp( $subdir = '' ) {
		return self::storageDir( 'storageDirTmp', 'tmp', $subdir );
	}

	protected static function storageDir( $configVarName, $dir = '', $subdir = '' ) {
		$dataDir = self::get( $configVarName, false );
		if ( $dataDir === false ) {
			$dataDir = __DIR__ . '/../data';
		}
		$dataDir = $dataDir . '/' . $dir . '/' . $subdir;
		if ( !file_exists( $dataDir ) ) {
			try {
				mkdir( $dataDir, 0755, true );
			} catch ( \Exception $e ) {
				throw new \Exception( "Unable to create directory '$dataDir'", 500 );
			}
		}
		return realpath( $dataDir );
	}

	public static function dotCommand() {
		return self::get( 'dotCommand', 'dot' );
	}
}
