<?php
/**
 * Pre-flight checks before a restore overwrites the site.
 *
 * Everything is measured, not assumed: a temporary DB table is created,
 * written and dropped; the target directories are actually written to with a
 * probe file; free disk space is compared with the archive size.
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restore pre-flight.
 */
class DBB_Preflight {

	/**
	 * Run all checks.
	 *
	 * @param string $file Optional stored backup filename (inside dbb-backups).
	 * @param int    $upload_size Optional size of a file that will be uploaded.
	 * @return array
	 */
	public static function run( $file = '', $upload_size = 0 ) {
		global $wpdb;

		$checks = array();
		$add    = function ( $id, $label, $status, $detail ) use ( &$checks ) {
			$checks[] = array(
				'id'     => $id,
				'label'  => $label,
				'status' => $status, // pass | warn | fail | info.
				'detail' => $detail,
			);
		};

		// PHP / runtime.
		$add(
			'php_version',
			__( 'PHP sürümü', 'doktor-brown-backup' ),
			version_compare( PHP_VERSION, '7.4', '>=' ) ? 'pass' : 'fail',
			PHP_VERSION . ' (en az 7.4)'
		);
		$add(
			'php_64bit',
			__( 'PHP 64-bit', 'doktor-brown-backup' ),
			PHP_INT_SIZE >= 8 ? 'pass' : 'warn',
			PHP_INT_SIZE >= 8 ? '64-bit' : '32-bit — 2 GB üzeri arşivlerde sorun çıkar'
		);
		$add(
			'zip',
			__( 'ZipArchive (php-zip)', 'doktor-brown-backup' ),
			class_exists( 'ZipArchive' ) ? 'pass' : 'fail',
			class_exists( 'ZipArchive' ) ? 'Yüklü' : 'Eksik — geri yükleme çalışmaz'
		);
		$memory = self::to_bytes( ini_get( 'memory_limit' ) );
		$add(
			'memory',
			__( 'Bellek limiti', 'doktor-brown-backup' ),
			( $memory <= 0 || $memory >= 128 * 1024 * 1024 ) ? 'pass' : 'warn',
			ini_get( 'memory_limit' ) . ' (önerilen 128M+)'
		);
		$add(
			'time_limit',
			__( 'Azami çalışma süresi', 'doktor-brown-backup' ),
			'info',
			ini_get( 'max_execution_time' ) . ' sn — adımlar parçalı çalışır'
		);
		$add(
			'upload_limits',
			__( 'Yükleme limitleri', 'doktor-brown-backup' ),
			'info',
			sprintf( 'post_max_size %s · upload_max_filesize %s · parça 5 MB', ini_get( 'post_max_size' ), ini_get( 'upload_max_filesize' ) )
		);

		// Database connectivity + write probe.
		$db_ok = ( $wpdb instanceof wpdb );
		$add(
			'db_connect',
			__( 'Veritabanı bağlantısı', 'doktor-brown-backup' ),
			$db_ok ? 'pass' : 'fail',
			$db_ok ? 'Bağlı' : 'Bağlantı yok'
		);

		$db_size = 0;
		$tables  = 0;
		if ( $db_ok ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT COUNT(*) AS t, COALESCE(SUM(data_length + index_length),0) AS s FROM information_schema.TABLES WHERE table_schema = %s',
					defined( 'DB_NAME' ) ? DB_NAME : ''
				),
				ARRAY_A
			);
			$tables  = $row ? (int) $row['t'] : 0;
			$db_size = $row ? (int) $row['s'] : 0;

			$add(
				'db_probe',
				__( 'Veritabanına yazma testi', 'doktor-brown-backup' ),
				self::db_probe( $wpdb ) ? 'pass' : 'fail',
				__( 'Geçici tablo oluşturuldu, yazıldı ve silindi', 'doktor-brown-backup' )
			);
			$add(
				'db_info',
				__( 'Veritabanı bilgisi', 'doktor-brown-backup' ),
				'info',
				sprintf( '%s · %s tablo · %s', $wpdb->db_server_info(), number_format_i18n( $tables ), size_format( $db_size ) )
			);
			$packet = (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' );
			$add(
				'max_packet',
				__( 'max_allowed_packet', 'doktor-brown-backup' ),
				$packet >= 4 * 1024 * 1024 ? 'pass' : 'warn',
				size_format( $packet ) . ( $packet >= 4 * 1024 * 1024 ? '' : ' — büyük INSERT satırlarında sorun çıkabilir' )
			);
			$mode = (string) $wpdb->get_var( 'SELECT @@sql_mode' );
			$add( 'sql_mode', __( 'SQL modu', 'doktor-brown-backup' ), 'info', $mode ? $mode : 'boş' );
		}

		// Filesystem write probes.
		$dirs = array(
			'abspath'    => array( ABSPATH, __( 'WordPress kök dizini', 'doktor-brown-backup' ) ),
			'wp_content' => array( WP_CONTENT_DIR, __( 'wp-content', 'doktor-brown-backup' ) ),
			'dbb_tmp'    => array( DBB_TMP_DIR, __( 'Geçici dizin (dbb-tmp)', 'doktor-brown-backup' ) ),
			'dbb_backups'=> array( DBB_BACKUPS_DIR, __( 'Yedek dizini (dbb-backups)', 'doktor-brown-backup' ) ),
		);
		$probe_fail = array();
		foreach ( $dirs as $id => $pair ) {
			list( $dir, $label ) = $pair;
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			$ok = self::write_probe( $dir );
			if ( ! $ok ) {
				$probe_fail[] = $label;
			}
			$add(
				'write_' . $id,
				$label,
				$ok ? 'pass' : 'fail',
				$ok ? __( 'Yazılabilir', 'doktor-brown-backup' ) : __( 'Yazılamıyor', 'doktor-brown-backup' )
			);
		}

		// Archive + free space.
		$archive_size = max( 0, (int) $upload_size );
		$archive      = null;
		if ( '' !== $file ) {
			$path = DBB_BACKUPS_DIR . '/' . sanitize_file_name( $file );
			$archive_size = is_file( $path ) ? (int) filesize( $path ) : 0;
			if ( is_file( $path ) ) {
				$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
				if ( 'wpress' === $ext ) {
					$pkg = DBB_Wpress::read_package( $path );
					if ( is_wp_error( $pkg ) ) {
						$add( 'archive', __( 'Arşiv okuma', 'doktor-brown-backup' ), 'fail', $pkg->get_error_message() );
					} else {
						$add(
							'archive',
							__( 'Arşiv', 'doktor-brown-backup' ),
							'pass',
							sprintf( '.wpress · %s · %s', isset( $pkg['SiteURL'] ) ? $pkg['SiteURL'] : '—', size_format( $archive_size ) )
						);
					}
				} else {
					$manifest = DBB_Archive::read_manifest( $path );
					if ( is_wp_error( $manifest ) ) {
						$add( 'archive', __( 'Arşiv okuma', 'doktor-brown-backup' ), 'fail', $manifest->get_error_message() );
					} else {
						$archive = $manifest;
						$add(
							'archive',
							__( 'Arşiv', 'doktor-brown-backup' ),
							'pass',
							sprintf(
								'%s · %s dosya · %s tablo · %s',
								isset( $manifest['site_url'] ) ? $manifest['site_url'] : '—',
								number_format_i18n( isset( $manifest['file_count'] ) ? (int) $manifest['file_count'] : 0 ),
								number_format_i18n( isset( $manifest['table_count'] ) ? (int) $manifest['table_count'] : 0 ),
								size_format( $archive_size )
							)
						);
						if ( ! empty( $manifest['php_version'] ) && version_compare( $manifest['php_version'], PHP_VERSION, '>' ) ) {
							$add( 'archive_php', __( 'Kaynak PHP sürümü', 'doktor-brown-backup' ), 'warn', $manifest['php_version'] . ' → ' . PHP_VERSION );
						}
					}
				}
			} else {
				$add( 'archive', __( 'Arşiv', 'doktor-brown-backup' ), 'fail', __( 'Dosya bulunamadı.', 'doktor-brown-backup' ) );
			}
		}

		$free = @disk_free_space( DBB_TMP_DIR ); // phpcs:ignore
		$need = (int) ( $archive_size * 1.1 + $db_size + 100 * 1024 * 1024 );
		if ( false === $free ) {
			$disk_status = 'warn';
			$disk_detail = __( 'Boş alan okunamadı — yükleme sırasında disk kontrolü yapılacak.', 'doktor-brown-backup' );
		} elseif ( $free < $need ) {
			$disk_status = 'fail';
			$disk_detail = sprintf(
				/* translators: 1: free, 2: needed, 3: archive */
				__( 'Disk alanı yetersiz — boş: %1$s, gerekli: %2$s (yedek: %3$s + DB + çıkarma payı).', 'doktor-brown-backup' ),
				size_format( $free ),
				size_format( $need ),
				size_format( $archive_size )
			);
		} else {
			$disk_status = 'pass';
			$disk_detail = sprintf(
				/* translators: 1: free, 2: needed */
				__( 'Yeterli — boş: %1$s, gerekli (yaklaşık): %2$s', 'doktor-brown-backup' ),
				size_format( $free ),
				size_format( $need )
			);
		}
		$add( 'disk_free', __( 'Disk alanı', 'doktor-brown-backup' ), $disk_status, $disk_detail );

		// PHP uploads land in upload_tmp_dir first; a 5 MB chunk must fit there.
		$tmp_free = @disk_free_space( sys_get_temp_dir() ); // phpcs:ignore
		$add(
			'upload_tmp',
			__( 'Yükleme geçici dizini', 'doktor-brown-backup' ),
			( false === $tmp_free || $tmp_free > 64 * 1024 * 1024 ) ? 'pass' : 'fail',
			false === $tmp_free
				? __( 'Boyut okunamadı.', 'doktor-brown-backup' )
				: sprintf(
					/* translators: %s: free space */
					__( 'Boş: %s (en az 64 MB gerekir)', 'doktor-brown-backup' ),
					size_format( $tmp_free )
				)
		);

		// Target state summary.
		$theme = wp_get_theme();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$add(
			'target',
			__( 'Hedef site', 'doktor-brown-backup' ),
			'info',
			sprintf(
				'%s · WordPress %s · tema: %s · %s eklenti',
				home_url(),
				get_bloginfo( 'version' ),
				$theme->get( 'Name' ),
				number_format_i18n( count( $plugins ) )
			)
		);

		$summary = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 );
		foreach ( $checks as $c ) {
			$summary[ $c['status'] ]++;
		}

		$result = array(
			'ok'       => ( 0 === $summary['fail'] ),
			'checks'   => $checks,
			'summary'  => $summary,
			'overwrite'=> array(
				'tables'  => $tables,
				'db_size' => $db_size,
				'plugins' => count( $plugins ),
				'theme'   => $theme->get( 'Name' ),
				'archive' => $archive,
			),
		);

		DBB_Logger::info(
			'preflight',
			array(
				'ok'      => $result['ok'],
				'summary' => $summary,
				'file'    => $file,
				'writable_fail' => $probe_fail,
			)
		);

		return $result;
	}

	/**
	 * Create → insert → select → drop a temporary table.
	 *
	 * @param wpdb $wpdb Database.
	 * @return bool
	 */
	private static function db_probe( $wpdb ) {
		$table = $wpdb->prefix . 'dbb_probe_' . strtolower( wp_generate_password( 6, false, false ) );
		$table = preg_replace( '/[^a-z0-9_]/i', '', $table );

		$wpdb->last_error = '';
		$ok = $wpdb->query( 'CREATE TABLE `' . $table . '` (id INT NOT NULL) ENGINE=InnoDB' ); // phpcs:ignore
		if ( false === $ok || '' !== (string) $wpdb->last_error ) {
			DBB_Logger::error( 'preflight db_probe create failed', array( 'table' => $table, 'error' => $wpdb->last_error ) );
			return false;
		}

		$ok = $wpdb->query( 'INSERT INTO `' . $table . '` (id) VALUES (1)' ); // phpcs:ignore
		if ( false === $ok || '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); // phpcs:ignore
			DBB_Logger::error( 'preflight db_probe insert failed', array( 'table' => $table, 'error' => $wpdb->last_error ) );
			return false;
		}

		$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); // phpcs:ignore
		return true;
	}

	/**
	 * Write and delete a probe file.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	private static function write_probe( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$probe = rtrim( $dir, '/\\' ) . '/.dbb-probe-' . strtolower( wp_generate_password( 8, false, false ) );
		$ok    = @file_put_contents( $probe, 'dbb' ); // phpcs:ignore
		if ( false === $ok ) {
			return false;
		}
		@unlink( $probe ); // phpcs:ignore
		return true;
	}

	/**
	 * php.ini size to bytes.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private static function to_bytes( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		$unit = strtolower( substr( $value, -1 ) );
		$num  = (float) $value;
		if ( 'g' === $unit ) {
			$num *= 1024 * 1024 * 1024;
		} elseif ( 'm' === $unit ) {
			$num *= 1024 * 1024;
		} elseif ( 'k' === $unit ) {
			$num *= 1024;
		}
		return (int) $num;
	}
}
