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
			DBB_Logger::error( 'restore_file: SQL file not found', array( 'path' => $path ) );
			return 0;
		}

		$offset   = 0;
		$executed = 0;

		do {
			$batch = self::restore_batch( $path, $offset, PHP_INT_MAX, microtime( true ) + 3600 );
			if ( ! empty( $batch['error'] ) ) {
				DBB_Logger::error(
					'restore_file: SQL error',
					array(
						'error'   => $batch['error'],
						'offset'  => $batch['offset'],
						'stmt'    => isset( $batch['stmt'] ) ? $batch['stmt'] : '',
					)
				);
				break;
			}
			$executed += (int) $batch['executed'];
			$offset    = (int) $batch['offset'];
			if ( is_callable( $progress ) ) {
				call_user_func( $progress, $executed );
			}
		} while ( ! $batch['done'] );

		return $executed;
	}

	/**
	 * Restore a bounded slice of a SQL file (resumable).
	 *
	 * Executes at most $max_statements statements or until $deadline, leaves
	 * the file offset in the returned array, and stops at the first SQL error
	 * instead of silently continuing (the old behaviour corrupted half a DB).
	 *
	 * @param string $path           SQL file.
	 * @param int    $offset         Byte offset to start from.
	 * @param int    $max_statements Statement cap for this call.
	 * @param float  $deadline       microtime() deadline for this call.
	 * @return array
	 */
	public static function restore_batch( $path, $offset, $max_statements, $deadline ) {
		global $wpdb;

		$out = array(
			'executed' => 0,
			'offset'   => (int) $offset,
			'done'     => false,
			'table'    => '',
			'error'    => '',
			'stmt'     => '',
		);

		if ( ! is_file( $path ) ) {
			$out['error'] = 'SQL file not found';
			return $out;
		}

		$size   = (int) filesize( $path );
		$offset = max( 0, (int) $offset );
		if ( $offset >= $size ) {
			$out['offset'] = $size;
			$out['done']   = true;
			return $out;
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore
		if ( ! $handle ) {
			$out['error'] = 'Could not open SQL file';
			return $out;
		}
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$buffer      = '';
		$executed    = 0;
		$table       = '';
		$error       = '';
		$error_stmt  = '';
		$interrupted = false;

		while ( true ) {
			// Pull the next complete statement out of the buffer.
			list( $stmt, $consumed ) = self::first_statement( $buffer );

			if ( null === $stmt ) {
				if ( feof( $handle ) ) {
					if ( '' === trim( $buffer ) ) {
						$buffer = '';
					}
					break;
				}
				$chunk = fread( $handle, self::READ_CHUNK ); // phpcs:ignore
				if ( false === $chunk ) {
					$error = 'Read error at offset ' . ftell( $handle );
					break;
				}
				if ( '' === $chunk ) {
					break;
				}
				if ( preg_match( '/^--\s*Table:\s*(\S+)/m', $chunk, $m ) ) {
					$table = $m[1];
				}
				$buffer .= $chunk;
				continue;
			}

			// Consume this statement from the in-memory buffer.
			$buffer = substr( $buffer, $consumed );

			if ( '' === $stmt ) {
				continue;
			}

			$wpdb->last_error = '';
			$result           = $wpdb->query( $stmt ); // phpcs:ignore

			if ( false === $result || '' !== (string) $wpdb->last_error ) {
				$error      = '' !== (string) $wpdb->last_error ? $wpdb->last_error : 'Query returned false';
				$error_stmt = substr( $stmt, 0, 400 );
				break;
			}

			$executed++;

			if ( $executed >= $max_statements || microtime( true ) >= $deadline ) {
				$interrupted = true;
				break;
			}
		}

		// At a clean EOF, run a final statement that had no trailing semicolon.
		if ( '' === $error && ! $interrupted && feof( $handle ) && '' !== trim( $buffer ) ) {
			$parts = self::split_statements( $buffer );
			foreach ( $parts as $stmt ) {
				$stmt = trim( $stmt );
				if ( '' === $stmt ) {
					continue;
				}
				$wpdb->last_error = '';
				$result = $wpdb->query( $stmt ); // phpcs:ignore
				if ( false === $result || '' !== (string) $wpdb->last_error ) {
					$error      = '' !== (string) $wpdb->last_error ? $wpdb->last_error : 'Query returned false';
					$error_stmt = substr( $stmt, 0, 400 );
					break;
				}
				$executed++;
			}
			if ( '' === $error ) {
				$buffer = '';
			}
		}

		$pos = ftell( $handle );
		if ( false === $pos ) {
			$pos = $offset;
		}
		$pos -= strlen( $buffer );
		$pos = max( $offset, min( $pos, $size ) );

		fclose( $handle ); // phpcs:ignore

		$out['executed'] = $executed;
		$out['offset']   = $pos;
		$out['done']     = ( '' === $error && $pos >= $size );
		$out['table']    = $table;
		$out['error']    = $error;
		$out['stmt']     = $error_stmt;

		return $out;
	}

	/**
	 * Extract the first complete statement from a buffer.
	 *
	 * Unlike take_complete(), this also reports how many bytes of the buffer
	 * were consumed, which lets restore_batch() leave an exact resume offset
	 * when it stops in the middle of a file.
	 *
	 * @param string $buffer SQL buffer.
	 * @return array [ string|null $statement, int $consumed_bytes ]
	 */
	public static function first_statement( $buffer ) {
		$len        = strlen( $buffer );
		$in_single  = false;
		$in_double  = false;
		$in_comment = false;

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
				$raw   = substr( $buffer, 0, $i + 1 );
				$parts = self::split_statements( $raw );
				$stmt  = trim( implode( ";\n", array_filter( $parts, 'strlen' ) ) );
				return array( $stmt, $i + 1 );
			}
		}

		return array( null, 0 );
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
