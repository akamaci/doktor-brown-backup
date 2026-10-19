<?php
/**
 * Reader for ".wpress" archives.
 *
 * Block format (4377-byte header per entry), per ServMask Ai1wm_Archiver:
 *   name    offset    0  length 255   filename (no path)
 *   size    offset  255  length  14   size of file contents
 *   mtime   offset  269  length  12   last modification time
 *   prefix  offset  281  length 4088  path name (no trailing slash)
 *   crc32   offset 4369  length   8   CRC32 (hex, optional)
 *
 * Entry data follows the header; it may be gzip-compressed. A final all-null
 * (or v2 CRC) block terminates the archive.
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * .wpress archive reader.
 */
class DBB_Wpress {

	/** @var int Header size. */
	const HEADER = 4377;

	/**
	 * Is the file plausibly a .wpress archive?
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function is_wpress( $path ) {
		if ( ! is_file( $path ) ) {
			return false;
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'wpress' === $ext ) {
			return true;
		}
		return false;
	}

	/**
	 * Unpack a 4377-byte header block.
	 *
	 * @param string $block Raw header.
	 * @return array {name,size,mtime,prefix,crc32} or empty array for EOF.
	 */
	public static function parse_header( $block ) {
		if ( strlen( $block ) < self::HEADER ) {
			return array();
		}
		$d = unpack( 'a255name/a14size/a12mtime/a4088prefix/a8crc32', $block );
		if ( ! $d ) {
			return array();
		}
		$prefix = trim( str_replace( "\0", '', $d['prefix'] ) );
		$prefix = str_replace( '\\', '/', $prefix );
		$prefix = preg_replace( '#(^|/)\./#', '$1', $prefix ); // strip "./" segments
		$prefix = trim( $prefix, '/' );
		if ( '.' === $prefix ) {
			$prefix = '';
		}

		$entry = array(
			'name'   => trim( str_replace( "\0", '', $d['name'] ) ),
			'size'   => (int) trim( $d['size'] ),
			'mtime'  => trim( str_replace( "\0", '', $d['mtime'] ) ),
			'prefix' => $prefix,
			'crc32'  => trim( str_replace( "\0", '', $d['crc32'] ) ),
		);
		return $entry;
	}

	/**
	 * Build an index of all entries.
	 *
	 * @param string $path     Archive path.
	 * @param int    $maxBytes Optional hard cap (safety).
	 * @return array|WP_Error
	 */
	public static function index( $path ) {
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'dbb_wp_missing', __( '.wpress dosyası bulunamadı.', 'doktor-brown-backup' ) );
		}

		$fp = fopen( $path, 'rb' ); // phpcs:ignore
		if ( ! $fp ) {
			return new WP_Error( 'dbb_wp_open', __( '.wpress açılamadı.', 'doktor-brown-backup' ) );
		}

		$size    = filesize( $path );
		$pos     = 0;
		$entries = array();

		while ( $pos + self::HEADER <= $size ) {
			fseek( $fp, $pos );
			$block = fread( $fp, self::HEADER ); // phpcs:ignore
			if ( false === $block || strlen( $block ) < self::HEADER ) {
				break;
			}
			$h = self::parse_header( $block );
			if ( empty( $h ) ) {
				break;
			}
			// EOF block: empty name and empty size.
			if ( '' === $h['name'] && 0 === $h['size'] ) {
				break;
			}
			$h['offset'] = $pos + self::HEADER;
			$entries[]   = $h;
			$pos         = $h['offset'] + $h['size'];
			if ( count( $entries ) > 500000 ) {
				break; // safety
			}
		}

		fclose( $fp ); // phpcs:ignore

		return $entries;
	}

	/**
	 * Read the raw (possibly gzipped) bytes of one entry.
	 *
	 * @param resource $fp    Open archive handle.
	 * @param array    $entry Entry from index().
	 * @return string
	 */
	public static function read_raw( $fp, $entry ) {
		fseek( $fp, $entry['offset'] );
		$remaining = (int) $entry['size'];
		$data      = '';
		while ( $remaining > 0 ) {
			$chunk = fread( $fp, min( 1048576, $remaining ) ); // phpcs:ignore
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$data      .= $chunk;
			$remaining -= strlen( $chunk );
		}
		return $data;
	}

	/**
	 * Decompress entry data when it is gzip.
	 *
	 * @param string $raw Raw bytes.
	 * @return string
	 */
	public static function decompress( $raw ) {
		if ( strlen( $raw ) >= 2 && "\x1f\x8b" === substr( $raw, 0, 2 ) ) {
			$out = @gzdecode( $raw ); // phpcs:ignore
			if ( false !== $out ) {
				return $out;
			}
		}
		return $raw;
	}

	/**
	 * Full path of an entry relative to the site root.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	public static function rel_path( $entry ) {
		$name = ltrim( str_replace( '\\', '/', $entry['name'] ), '/' );
		$name = str_replace( array( '..', "\0" ), '', $name );
		$prefix = ltrim( str_replace( '\\', '/', $entry['prefix'] ), '/' );
		$prefix = str_replace( array( '..', "\0" ), '', $prefix );
		return ( '' !== $prefix ) ? $prefix . '/' . $name : $name;
	}

	/**
	 * Read and JSON-decode the "package.json" manifest entry.
	 *
	 * @param string $path Archive path.
	 * @return array|WP_Error
	 */
	public static function read_package( $path ) {
		$entries = self::index( $path );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		$fp = fopen( $path, 'rb' ); // phpcs:ignore
		if ( ! $fp ) {
			return new WP_Error( 'dbb_wp_open', __( '.wpress açılamadı.', 'doktor-brown-backup' ) );
		}
		foreach ( $entries as $e ) {
			if ( 'package.json' === $e['name'] && '' === $e['prefix'] ) {
				$raw  = self::decompress( self::read_raw( $fp, $e ) );
				$json = json_decode( $raw, true );
				fclose( $fp ); // phpcs:ignore
				return is_array( $json ) ? $json : new WP_Error( 'dbb_wp_pkg', __( 'package.json okunamadı.', 'doktor-brown-backup' ) );
			}
		}
		fclose( $fp ); // phpcs:ignore
		return new WP_Error( 'dbb_wp_pkg', __( 'package.json bulunamadı.', 'doktor-brown-backup' ) );
	}

	/**
	 * Extract a subset of entries into a destination root.
	 *
	 * @param string $path       Archive path.
	 * @param array  $entries    Full entry index.
	 * @param int    $from       Start index.
	 * @param int    $count      How many entries to process.
	 * @param string $files_root Destination for regular files.
	 * @param string $sql_path   Destination path for the database .sql file.
	 * @return array {written:int, next:int, done:bool, sql:bool}
	 */
	public static function extract_range( $path, $entries, $from, $count, $files_root, $sql_path ) {
		$total   = count( $entries );
		$fp      = fopen( $path, 'rb' ); // phpcs:ignore
		$written = 0;
		$got_sql = false;

		if ( ! $fp ) {
			return array( 'written' => 0, 'next' => $total, 'done' => true, 'sql' => false );
		}

		$i   = $from;
		$end = min( $total, $from + $count );
		for ( ; $i < $end; $i++ ) {
			$e   = $entries[ $i ];
			$rel = self::rel_path( $e );
			if ( '' === $rel ) {
				continue;
			}

			$raw  = self::decompress( self::read_raw( $fp, $e ) );

			// Detect the database dump: a .sql file, or a file literally named database.
			$is_sql = (bool) preg_match( '/\.sql$/i', $rel ) || 'database.sql' === $e['name'];

			if ( $is_sql && ! $got_sql ) {
				if ( ! file_exists( dirname( $sql_path ) ) ) {
					wp_mkdir_p( dirname( $sql_path ) );
				}
				file_put_contents( $sql_path, $raw ); // phpcs:ignore
				$got_sql = true;
				continue;
			}

			$dest = $files_root . '/' . $rel;
			$dir  = dirname( $dest );
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			file_put_contents( $dest, $raw ); // phpcs:ignore
			$written++;
		}

		fclose( $fp ); // phpcs:ignore

		return array(
			'written' => $written,
			'next'    => $i,
			'done'    => ( $i >= $total ),
			'sql'     => $got_sql,
		);
	}
}
