<?php
/**
 * Database dump and restore (through wpdb).
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database helper.
 */
class DBB_DB {

	/** @var int Rows per INSERT batch. */
	const BATCH = 400;

	/** @var int Read buffer size for restore. */
	const READ_CHUNK = 262144;

	/**
	 * All tables in the current database.
	 *
	 * @return array
	 */
	public static function tables() {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		return is_array( $tables ) ? $tables : array();
	}

	/**
	 * Dump the whole database into an open file handle.
	 *
	 * @param resource $fp Write handle.
	 * @return int Tables dumped.
	 */
	public static function dump_all( $fp ) {
		$count = 0;
		fwrite( $fp, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" ); // phpcs:ignore
		foreach ( self::tables() as $table ) {
			self::dump_table( $table, $fp );
			$count++;
		}
		fwrite( $fp, "\nSET FOREIGN_KEY_CHECKS=1;\n" ); // phpcs:ignore
		return $count;
	}

	/**
	 * Dump a single table as SQL.
	 *
	 * @param string   $table Table name.
	 * @param resource $fp    Write handle.
	 * @return void
	 */
	public static function dump_table( $table, $fp ) {
		global $wpdb;

		$safe   = esc_sql( $table );
		$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . $safe . '`', ARRAY_N );
		$ddl    = isset( $create[1] ) ? $create[1] : '';

		fwrite( $fp, "-- Table: {$table}\n" ); // phpcs:ignore
		fwrite( $fp, 'DROP TABLE IF EXISTS `' . $table . "`;\n" ); // phpcs:ignore
		fwrite( $fp, $ddl . ";\n\n" ); // phpcs:ignore

		$columns  = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $safe . '`', ARRAY_A );
		$colnames = array();
		foreach ( (array) $columns as $col ) {
			$colnames[] = '`' . $col['Field'] . '`';
		}
		if ( empty( $colnames ) ) {
			return;
		}
		$collist = implode( ', ', $colnames );

		$offset = 0;
		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM `' . $safe . '` LIMIT %d OFFSET %d',
					self::BATCH,
					$offset
				),
				ARRAY_N
			);
			if ( empty( $rows ) ) {
				break;
			}
			$values = array();
			foreach ( $rows as $row ) {
				$vals = array();
				foreach ( $row as $value ) {
					$vals[] = ( null === $value ) ? 'NULL' : "'" . esc_sql( $value ) . "'";
				}
				$values[] = '(' . implode( ', ', $vals ) . ')';
			}
			fwrite( $fp, 'INSERT INTO `' . $table . '` (' . $collist . ") VALUES\n" . implode( ",\n", $values ) . ";\n" ); // phpcs:ignore
			$offset += self::BATCH;
			if ( count( $rows ) < self::BATCH ) {
				break;
			}
		}
	}

	/**
	 * Execute a SQL file against the database.
	 *
	 * @param string   $path     SQL file path.
	 * @param callable $progress Optional callback( int $executed ).
	 * @return int Executed statements.
	 */
	public static function restore_file( $path, $progress = null ) {
		global $wpdb;

		if ( ! is_file( $path ) ) {
			return 0;
		}

		$handle = fopen( $path, 'r' ); // phpcs:ignore
		if ( ! $handle ) {
			return 0;
		}

		$executed = 0;
		$buffer   = '';

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::READ_CHUNK ); // phpcs:ignore
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$buffer .= $chunk;

			list( $statements, $buffer ) = self::take_complete( $buffer );
			foreach ( $statements as $stmt ) {
				$stmt = trim( $stmt );
				if ( '' === $stmt ) {
					continue;
				}
				$wpdb->query( $stmt ); // phpcs:ignore
				$executed++;
				if ( is_callable( $progress ) && 0 === $executed % 50 ) {
					call_user_func( $progress, $executed );
				}
			}
		}

		$tail = trim( $buffer );
		if ( '' !== $tail ) {
			$wpdb->query( $tail ); // phpcs:ignore
			$executed++;
		}

		fclose( $handle ); // phpcs:ignore
		return $executed;
	}

	/**
	 * Split off every complete (top-level ";") statement from the buffer.
	 *
	 * @param string $buffer SQL text, possibly ending mid-statement.
	 * @return array [ statements[], remainder ]
	 */
	public static function take_complete( $buffer ) {
		$len        = strlen( $buffer );
		$in_single  = false;
		$in_double  = false;
		$in_comment = false;
		$last_semi  = -1;

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $buffer[ $i ];

			if ( $in_comment ) {
				if ( "\n" === $ch ) {
					$in_comment = false;
				}
				continue;
			}

			$prev = ( $i > 0 ) ? $buffer[ $i - 1 ] : '';

			if ( ! $in_single && ! $in_double && '-' === $ch && ( $i + 1 < $len ) && '-' === $buffer[ $i + 1 ] ) {
				$in_comment = true;
				$i++;
				continue;
			}

			if ( "'" === $ch && '\\' !== $prev && ! $in_double ) {
				$in_single = ! $in_single;
			} elseif ( '"' === $ch && '\\' !== $prev && ! $in_single ) {
				$in_double = ! $in_double;
			}

			if ( ';' === $ch && ! $in_single && ! $in_double ) {
				$last_semi = $i;
			}
		}

		if ( -1 === $last_semi ) {
			return array( array(), $buffer );
		}

		$complete  = substr( $buffer, 0, $last_semi + 1 );
		$remainder = substr( $buffer, $last_semi + 1 );

		return array( self::split_statements( $complete ), $remainder );
	}

	/**
	 * Split a SQL block into individual statements (trailing ";" removed).
	 *
	 * @param string $sql SQL text.
	 * @return array
	 */
	public static function split_statements( $sql ) {
		$statements = array();
		$current    = '';
		$in_single  = false;
		$in_double  = false;
		$in_comment = false;
		$len        = strlen( $sql );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $sql[ $i ];

			if ( $in_comment ) {
				if ( "\n" === $ch ) {
					$in_comment = false;
				}
				continue;
			}

			$prev = ( $i > 0 ) ? $sql[ $i - 1 ] : '';

			if ( ! $in_single && ! $in_double && '-' === $ch && ( $i + 1 < $len ) && '-' === $sql[ $i + 1 ] ) {
				$in_comment = true;
				$i++;
				continue;
			}

			if ( "'" === $ch && '\\' !== $prev && ! $in_double ) {
				$in_single = ! $in_single;
			} elseif ( '"' === $ch && '\\' !== $prev && ! $in_single ) {
				$in_double = ! $in_double;
			}

			if ( ';' === $ch && ! $in_single && ! $in_double ) {
				$statements[] = $current;
				$current      = '';
				continue;
			}

			$current .= $ch;
		}

		if ( '' !== trim( $current ) ) {
			$statements[] = $current;
		}

		return $statements;
	}
}
