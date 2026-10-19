<?php
/**
 * Serialization-safe search & replace (for domain changes on restore).
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Safe search/replace.
 */
class DBB_Replace {

	/** @var int Rows per query when scanning a table. */
	const ROWS = 300;

	/**
	 * Is a string serialized data (without touching objects)?
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function looks_serialized( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) < 4 ) {
			return false;
		}
		if ( 'N;' === $value || 'b:0;' === $value ) {
			return true;
		}
		if ( ! preg_match( '/^([adOsbni]):/', $value ) ) {
			return false;
		}
		return false !== @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore
	}

	/**
	 * Recursively replace inside a decoded structure.
	 *
	 * @param mixed  $data Data.
	 * @param string $from Search.
	 * @param string $to   Replace.
	 * @return mixed
	 */
	public static function walk( $data, $from, $to ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$out[ $k ] = self::walk( $v, $from, $to );
			}
			return $out;
		}
		if ( is_string( $data ) ) {
			return str_replace( $from, $to, $data );
		}
		return $data;
	}

	/**
	 * Replace inside a single value, fixing serialization when needed.
	 *
	 * @param string $value Value.
	 * @param string $from  Search.
	 * @param string $to    Replace.
	 * @return string
	 */
	public static function safe( $value, $from, $to ) {
		if ( ! is_string( $value ) || '' === $value || false === strpos( $value, $from ) ) {
			return $value;
		}

		if ( self::looks_serialized( $value ) ) {
			// Never re-encode object graphs (may be incomplete classes): skip.
			if ( false !== strpos( $value, 'O:' ) || false !== strpos( $value, 'C:' ) ) {
				return $value;
			}
			$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore
			if ( false !== $data || 'b:0;' === $value ) {
				return serialize( self::walk( $data, $from, $to ) );
			}
		}

		return str_replace( $from, $to, $value );
	}

	/**
	 * Text-ish columns for a table.
	 *
	 * @param string $table Table.
	 * @return array
	 */
	public static function text_columns( $table ) {
		global $wpdb;
		$cols = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', ARRAY_A );
		$out  = array();
		foreach ( (array) $cols as $c ) {
			$type = strtolower( isset( $c['Type'] ) ? $c['Type'] : '' );
			if ( false !== strpos( $type, 'char' ) || false !== strpos( $type, 'text' ) || false !== strpos( $type, 'blob' ) || false !== strpos( $type, 'json' ) ) {
				$out[] = $c['Field'];
			}
		}
		return $out;
	}

	/**
	 * Replace in one table. Returns number of updated rows.
	 *
	 * @param string $table Table.
	 * @param string $from  Search.
	 * @param string $to    Replace.
	 * @return int
	 */
	public static function table( $table, $from, $to ) {
		global $wpdb;

		$columns = self::text_columns( $table );
		if ( empty( $columns ) ) {
			return 0;
		}

		$pk = 'id';
		$cols = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', ARRAY_A );
		$has_id = false;
		$keyname = null;
		foreach ( (array) $cols as $c ) {
			if ( isset( $c['Key'] ) && 'PRI' === $c['Key'] ) {
				$keyname = $c['Field'];
				break;
			}
		}
		if ( ! $keyname ) {
			// Fall back to a full-table scan without a key (rare).
			$keyname = isset( $cols[0]['Field'] ) ? $cols[0]['Field'] : $pk;
		}
		$keyname = $keyname ? $keyname : $pk;

		$updated = 0;
		$offset  = 0;
		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM `' . esc_sql( $table ) . '` LIMIT %d OFFSET %d',
					self::ROWS,
					$offset
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$changes = array();
				foreach ( $columns as $col ) {
					if ( ! isset( $row[ $col ] ) ) {
						continue;
					}
					$new = self::safe( $row[ $col ], $from, $to );
					if ( $new !== $row[ $col ] ) {
						$changes[ $col ] = $new;
					}
				}
				if ( $changes && isset( $row[ $keyname ] ) ) {
					$wpdb->update( $table, $changes, array( $keyname => $row[ $keyname ] ) ); // phpcs:ignore
					$updated++;
				}
			}
			$offset += self::ROWS;
			if ( count( $rows ) < self::ROWS ) {
				break;
			}
		}

		return $updated;
	}

	/**
	 * Build the list of old->new URL pairs (protocol / www variants).
	 *
	 * @param string $old Old base URL.
	 * @param string $new New base URL.
	 * @return array
	 */
	public static function url_pairs( $old, $new ) {
		$old = rtrim( $old, '/' );
		$new = rtrim( $new, '/' );
		if ( '' === $old || '' === $new || $old === $new ) {
			return array();
		}

		$olds = array( $old );
		// www / non-www and scheme variants.
		$parts = wp_parse_url( $old );
		if ( isset( $parts['host'] ) ) {
			$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
			$host   = $parts['host'];
			$path   = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
			$bare   = preg_replace( '/^www\./i', '', $host );
			foreach ( array( 'http', 'https' ) as $sch ) {
				foreach ( array( $bare, 'www.' . $bare ) as $h ) {
					$olds[] = $sch . '://' . $h . $path;
				}
			}
		}

		$pairs = array();
		foreach ( array_unique( $olds ) as $o ) {
			$pairs[ $o ] = $new;
		}
		return $pairs;
	}

	/**
	 * Apply every pair to one table.
	 *
	 * @param string $table Table.
	 * @param array  $pairs old=>new.
	 * @return int
	 */
	public static function table_pairs( $table, $pairs ) {
		$total = 0;
		foreach ( $pairs as $from => $to ) {
			$total += self::table( $table, $from, $to );
		}
		return $total;
	}
}
