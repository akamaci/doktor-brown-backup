<?php
/**
 * Filesystem helpers: safe enumeration and copying.
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recursive filesystem helpers.
 */
class DBB_Filesystem {

	/**
	 * Directories (relative to ABSPATH) always excluded from a file backup.
	 *
	 * @return array
	 */
	public static function default_excludes() {
		$excludes = array(
			'dbb-backups',
			'dbb-tmp',
			'wp-content/dbb-backups',
			'wp-content/dbb-tmp',
			'.git',
			'wp-content/cache',
			'wp-content/upgrade',
			// Never include the destination's DB credentials in a portable archive.
			'wp-config.php',
			'wp-config-local.php',
			'.user.ini',
		);

		/**
		 * Filter the excluded relative paths.
		 *
		 * @param array $excludes Relative paths.
		 */
		return (array) apply_filters( 'dbb_export_excludes', $excludes );
	}

	/**
	 * Normalise a path to forward slashes relative to ABSPATH.
	 *
	 * @param string $abs Absolute path.
	 * @return string
	 */
	public static function relative( $abs ) {
		$root = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/';
		$abs  = str_replace( '\\', '/', $abs );
		if ( 0 === strpos( $abs, $root ) ) {
			return substr( $abs, strlen( $root ) );
		}
		return ltrim( $abs, '/' );
	}

	/**
	 * Is a relative path excluded?
	 *
	 * @param string $relative Relative path.
	 * @return bool
	 */
	public static function is_excluded( $relative ) {
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		if ( '' === $relative ) {
			return false;
		}
		foreach ( self::default_excludes() as $ex ) {
			$ex = trim( str_replace( '\\', '/', $ex ), '/' );
			if ( '' === $ex ) {
				continue;
			}
			if ( 0 === strpos( $relative, $ex . '/' ) || $relative === $ex ) {
				return true;
			}
			// Also match "wp-content/xyz" style even if user typed "xyz".
			if ( '.' === $ex[0] ) {
				continue;
			}
		}
		return false;
	}

	/**
	 * Enumerate files under ABSPATH, applying excludes. Returns relative paths.
	 *
	 * @param int $limit Max entries (0 = all).
	 * @return array Relative file paths.
	 */
	public static function list_files( $limit = 0 ) {
		$root   = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		$result = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				function ( $current ) use ( $root ) {
					if ( $current->isDir() ) {
						$rel = ltrim( substr( str_replace( '\\', '/', $current->getPathname() ), strlen( $root ) ), '/' );
						return ! DBB_Filesystem::is_excluded( $rel );
					}
					return true;
				}
			),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$rel = ltrim( substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $root ) ), '/' );
			if ( self::is_excluded( $rel ) ) {
				continue;
			}
			$result[] = $rel;
			if ( $limit > 0 && count( $result ) >= $limit ) {
				break;
			}
		}

		sort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Count files quickly (bounded walk).
	 *
	 * @return int
	 */
	public static function count_files() {
		return count( self::list_files( 0 ) );
	}

	/**
	 * Total byte size of an array of relative paths.
	 *
	 * @param array $relatives Relative paths.
	 * @return int
	 */
	public static function total_size( array $relatives ) {
		$sum = 0;
		foreach ( $relatives as $rel ) {
			$abs = ABSPATH . $rel;
			if ( is_file( $abs ) ) {
				$sum += (int) filesize( $abs );
			}
		}
		return $sum;
	}

	/**
	 * Resolve a relative path inside ABSPATH, refusing traversal.
	 *
	 * @param string $relative Relative path.
	 * @return string|false Absolute path or false.
	 */
	public static function resolve( $relative ) {
		$relative = (string) $relative;
		if ( false !== strpos( $relative, '..' ) || false !== strpos( $relative, "\0" ) ) {
			return false;
		}
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		if ( '' === $relative ) {
			return false;
		}
		$abs = rtrim( ABSPATH, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		$real_root = realpath( ABSPATH );
		$real_dir  = realpath( dirname( $abs ) );
		if ( false !== $real_root && false !== $real_dir && 0 !== strpos( $real_dir, $real_root ) ) {
			return false;
		}
		return $abs;
	}
}
