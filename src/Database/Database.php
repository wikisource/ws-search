<?php

namespace App\Database;

use App\Config;
use Exception;
use PDO;
use PDOException;
use PDOStatement;

class Database {

	/** @var PDO */
	static protected $pdo;

	/** @var array|string */
	static protected $queries;

	public function __construct() {
		if ( self::$pdo ) {
			return;
		}
		$host = Config::databaseHost();
		$dsn = "mysql:host=$host;dbname=" . Config::databaseName() . ";charset=utf8";
		$attr = [ PDO::ATTR_TIMEOUT => 10 ];
		self::$pdo = new PDO( $dsn, Config::databaseUser(), Config::databasePassword(), $attr );
		self::$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->setFetchMode( PDO::FETCH_OBJ );
		self::$pdo->exec( "SET @@session.wait_timeout = 1200" );
	}

	public static function getQueries() {
		return self::$queries;
	}

	public function setFetchMode( $fetchMode ) {
		return self::$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, $fetchMode );
	}

	/**
	 * Get a result statement for a given query. Handles errors.
	 *
	 * @param string $sql The SQL statement to execute.
	 * @param string|bool $params Array of param => value pairs, or false if none are needed.
	 * @return PDOStatement Resulting PDOStatement.
     * @throws Exception If the SQL couldn't be executed.
	 */
	public function query( $sql, $params = false, $class = false, $classArgs = false ) {
		if ( !empty( $class ) && !class_exists( $class ) ) {
			throw new Exception( "Class not found: $class" );
		}
		try {
			if ( is_array( $params ) && count( $params ) > 0 ) {
				$stmt = self::$pdo->prepare( $sql );
				foreach ( $params as $placeholder => $value ) {
					if ( is_bool( $value ) ) {
						$type = PDO::PARAM_BOOL;
					} elseif ( is_null( $value ) ) {
						$type = PDO::PARAM_NULL;
					} elseif ( is_int( $value ) ) {
						$type = PDO::PARAM_INT;
					} else {
						$type = PDO::PARAM_STR;
					}
					// echo '<li>';var_dump($value, $type);
					$stmt->bindValue( $placeholder, $value, $type );
				}
				if ( $class ) {
					$stmt->setFetchMode( PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, $class,
						$classArgs );
				} else {
					$stmt->setFetchMode( PDO::FETCH_OBJ );
				}
				$result = $stmt->execute();
				if ( !$result ) {
					throw new PDOException( 'Unable to execute parameterised SQL: <code>' . $sql .
										   '</code>' );
				} else {
					// echo '<p>Executed: '.$sql.'<br />with '.  print_r($params, true).'</p>';
				}
			} else {
				if ( $class ) {
					$stmt =
						self::$pdo->query( $sql, PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, $class,
							$classArgs );
				} else {
					$stmt = self::$pdo->query( $sql );
				}
			}
		} catch ( PDOException $e ) {
			$msg =
				$e->getMessage() . ' -- Unable to execute SQL: ' . ' <code>' . $sql . '</code> ' .
				' Parameters: ' . var_export( $params, true );
			throw new Exception( $msg );
		}

		self::$queries[] = [ 'sql' => $sql, 'params' => $params ];

		return $stmt;
	}

	/**
	 * Get the most-recently inserted auto_increment ID.
	 * @return integer
	 */
	public static function lastInsertId() {
		return (int)self::$pdo->lastInsertId();
	}
}
