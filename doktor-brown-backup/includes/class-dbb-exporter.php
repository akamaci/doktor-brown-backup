<?php
/**
 * Exporter: builds a .dbb backup from files + database, in small steps.
 *
 * The engine exposes a transport-free API (start()/step()) so it can run from
 * AJAX, WP-CLI, or a test harness. The ajax_* methods are thin wrappers.
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Export engine.
 */
class DBB_Exporter {

	/** @var int Files processed per step. */
	const FILES_PER_STEP = 250;

	/** @var int Seconds allowed per step. */
	const TIME_BUDGET = 8;

	/** @var int Tables dumped per step. */
	const TABLES_PER_STEP = 4;

	/* ------------------------------------------------------------------ */
	/* AJAX wrappers                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * AJAX: create a new job.
	 *
	 * @return void
	 */
	public function ajax_start() {
		DBB_Plugin::verify_request();
		$res = $this->start();
		if ( isset( $res['error'] ) ) {
			wp_send_json_error( array( 'message' => $res['error'] ) );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: advance a job.
	 *
	 * @return void
	 */
	public function ajax_step() {
		DBB_Plugin::verify_request();
		$job = isset( $_POST['job'] ) ? sanitize_file_name( wp_unslash( $_POST['job'] ) ) : '';
		$res = $this->step( $job );
		if ( isset( $res['error'] ) ) {
			wp_send_json_error( array( 'message' => $res['error'] ), isset( $res['code'] ) ? (int) $res['code'] : 400 );
		}
		wp_send_json_success( $res );
	}

	/* ------------------------------------------------------------------ */
	/* Engine API                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Create a job and return totals.
	 *
	 * @return array
	 */
	public function start() {
		if ( ! DBB_Archive::available() ) {
			return array( 'error' => __( 'Sunucuda ZipArchive (php-zip) eklentisi yok.', 'doktor-brown-backup' ) );
		}

		$job     = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
		$job_dir = DBB_TMP_DIR . '/' . $job;
		wp_mkdir_p( $job_dir );

		$files = DBB_Filesystem::list_files();

		$state = array(
			'job'       => $job,
			'created'   => time(),
			'status'    => 'running',
			'step'      => 'db',
			'files'     => $files,
			'index'     => 0,
			'db_tables' => DBB_DB::tables(),
			'db_index'  => 0,
			'archive'   => $job_dir . '/backup.' . DBB_Archive::EXTENSION,
			'sql'       => $job_dir . '/database.sql',
			'message'   => '',
		);
		$this->save_state( $state );

		return array(
			'job'        => $job,
			'totalFiles' => count( $files ),
			'totalSize'  => DBB_Filesystem::total_size( $files ),
			'tables'     => count( $state['db_tables'] ),
			'progress'   => 0,
			'message'    => __( 'Yedek başlatıldı…', 'doktor-brown-backup' ),
		);
	}

	/**
	 * Advance a job by one step.
	 *
	 * @param string $job Job id.
	 * @return array
	 */
	public function step( $job ) {
		$state = $this->load_state( $job );
		if ( ! $state ) {
			return array( 'error' => __( 'İş bulunamadı.', 'doktor-brown-backup' ), 'code' => 404 );
		}

		$started = microtime( true );

		try {
			if ( 'db' === $state['step'] ) {
				$this->step_db( $state, $started );
			} elseif ( 'files' === $state['step'] ) {
				$this->step_files( $state, $started );
			} elseif ( 'finalize' === $state['step'] ) {
				$this->step_finalize( $state );
			}
		} catch ( Exception $e ) {
			$state['status']  = 'error';
			$state['message'] = $e->getMessage();
			$this->save_state( $state );
			return array( 'error' => $e->getMessage() );
		}

		$this->save_state( $state );

		return array(
			'status'   => $state['status'],
			'step'     => $state['step'],
			'progress' => $this->progress( $state ),
			'message'  => $state['message'],
			'file'     => isset( $state['result'] ) ? $state['result'] : null,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Steps                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Dump a few tables per call.
	 *
	 * @param array $state   State (by reference).
	 * @param float $started Request start time.
	 * @return void
	 */
	private function step_db( &$state, $started ) {
		$first  = ( 0 === (int) $state['db_index'] );
		$handle = fopen( $state['sql'], $first ? 'w' : 'a' ); // phpcs:ignore
		if ( ! $handle ) {
			throw new Exception( __( 'SQL dosyası yazılamadı.', 'doktor-brown-backup' ) );
		}

		if ( $first ) {
			fwrite( $handle, "SET NAMES utf8mb4;\nSET SESSION sql_mode='';\nSET FOREIGN_KEY_CHECKS=0;\n\n" ); // phpcs:ignore
		}

		$tables = $state['db_tables'];
		$total  = count( $tables );
		$done   = 0;

		while ( $state['db_index'] < $total && $done < self::TABLES_PER_STEP && ( microtime( true ) - $started ) < self::TIME_BUDGET ) {
			DBB_DB::dump_table( $tables[ $state['db_index'] ], $handle );
			$state['db_index']++;
			$done++;
		}

		$finished = ( $state['db_index'] >= $total );
		if ( $finished ) {
			fwrite( $handle, "\nSET FOREIGN_KEY_CHECKS=1;\n" ); // phpcs:ignore
		}
		fclose( $handle ); // phpcs:ignore

		if ( ! $finished ) {
			$state['message'] = sprintf(
				/* translators: 1: done tables, 2: total tables */
				__( 'Veritabanı aktarılıyor… (%1$d/%2$d tablo)', 'doktor-brown-backup' ),
				(int) $state['db_index'],
				$total
			);
			return;
		}

		$zip = DBB_Archive::open( $state['archive'] );
		if ( is_wp_error( $zip ) ) {
			throw new Exception( $zip->get_error_message() );
		}
		DBB_Archive::add_file( $zip, $state['sql'], DBB_Archive::DATABASE );
		$zip->close();

		$state['step']    = 'files';
		$state['message'] = __( 'Dosyalar aktarılıyor…', 'doktor-brown-backup' );
	}

	/**
	 * Add a batch of files per call.
	 *
	 * @param array $state   State (by reference).
	 * @param float $started Request start time.
	 * @return void
	 */
	private function step_files( &$state, $started ) {
		$total = count( $state['files'] );
		if ( 0 === $total ) {
			$state['step'] = 'finalize';
			return;
		}

		$zip = DBB_Archive::open( $state['archive'] );
		if ( is_wp_error( $zip ) ) {
			throw new Exception( $zip->get_error_message() );
		}

		$added = 0;
		while ( $state['index'] < $total && $added < self::FILES_PER_STEP && ( microtime( true ) - $started ) < self::TIME_BUDGET ) {
			$rel = $state['files'][ $state['index'] ];
			$abs = ABSPATH . $rel;
			DBB_Archive::add_file( $zip, $abs, DBB_Archive::FILES_DIR . $rel );
			$state['index']++;
			$added++;
		}
		$zip->close();

		if ( $state['index'] >= $total ) {
			$state['step'] = 'finalize';
		} else {
			$state['message'] = sprintf(
				/* translators: 1: done files, 2: total files */
				__( 'Dosyalar aktarılıyor… (%1$d/%2$d)', 'doktor-brown-backup' ),
				(int) $state['index'],
				$total
			);
		}
	}

	/**
	 * Write manifest, move the archive, index it.
	 *
	 * @param array $state State (by reference).
	 * @return void
	 */
	private function step_finalize( &$state ) {
		$manifest = array(
			'format'       => 'dbb',
			'plugin'       => 'doktor-brown-backup',
			'plugin_ver'   => DBB_VERSION,
			'created'      => gmdate( 'c' ),
			'site_url'     => site_url(),
			'home_url'     => home_url(),
			'abspath'      => ABSPATH,
			'table_prefix' => $GLOBALS['wpdb']->prefix,
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
			'file_count'   => count( $state['files'] ),
			'table_count'  => count( $state['db_tables'] ),
			'db_file'      => DBB_Archive::DATABASE,
			'files_dir'    => DBB_Archive::FILES_DIR,
		);

		$zip = DBB_Archive::open( $state['archive'] );
		if ( is_wp_error( $zip ) ) {
			throw new Exception( $zip->get_error_message() );
		}
		DBB_Archive::add_string( $zip, DBB_Archive::MANIFEST, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		$zip->close();

		if ( ! file_exists( DBB_BACKUPS_DIR ) ) {
			wp_mkdir_p( DBB_BACKUPS_DIR );
		}
		if ( ! file_exists( DBB_BACKUPS_DIR . '/.htaccess' ) ) {
			DBB_Plugin::write_guard( DBB_BACKUPS_DIR );
		}

		$name = 'backup-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false, false ) . '.' . DBB_Archive::EXTENSION;
		$dest = DBB_BACKUPS_DIR . '/' . $name;

		if ( ! @rename( $state['archive'], $dest ) ) { // phpcs:ignore
			if ( ! @copy( $state['archive'], $dest ) ) { // phpcs:ignore
				throw new Exception( __( 'Yedek taşınamadı.', 'doktor-brown-backup' ) );
			}
			@unlink( $state['archive'] ); // phpcs:ignore
		}

		$meta = array(
			'file'       => $name,
			'size'       => (int) filesize( $dest ),
			'created'    => time(),
			'files'      => count( $state['files'] ),
			'tables'     => count( $state['db_tables'] ),
			'site_url'   => site_url(),
			'wp_version' => $manifest['wp_version'],
		);
		DBB_Admin::index_add( $name, $meta );

		@unlink( $state['sql'] ); // phpcs:ignore
		$this->rmdir_recursive( dirname( $state['archive'] ) );

		$state['status']  = 'done';
		$state['step']    = 'done';
		$state['result']  = $meta;
		$state['message'] = __( 'Yedek tamamlandı.', 'doktor-brown-backup' );
	}

	/**
	 * Progress 0..100.
	 *
	 * @param array $state State.
	 * @return int
	 */
	private function progress( $state ) {
		$tables = max( 1, count( $state['db_tables'] ) );
		$files  = max( 1, count( $state['files'] ) );

		if ( 'db' === $state['step'] ) {
			return (int) round( 5 + 35 * ( $state['db_index'] / $tables ) );
		}
		if ( 'files' === $state['step'] ) {
			return (int) round( 40 + 55 * ( $state['index'] / $files ) );
		}
		if ( 'done' === $state['step'] ) {
			return 100;
		}
		return 95;
	}

	/**
	 * State file path.
	 *
	 * @param string $job Job id.
	 * @return string
	 */
	private function state_path( $job ) {
		return DBB_TMP_DIR . '/' . $job . '/state.json';
	}

	/**
	 * Persist state.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private function save_state( $state ) {
		wp_mkdir_p( dirname( $this->state_path( $state['job'] ) ) );
		file_put_contents( $this->state_path( $state['job'] ), wp_json_encode( $state ) ); // phpcs:ignore
	}

	/**
	 * Load state.
	 *
	 * @param string $job Job id.
	 * @return array|false
	 */
	private function load_state( $job ) {
		if ( '' === $job ) {
			return false;
		}
		$path = $this->state_path( $job );
		if ( ! is_file( $path ) ) {
			return false;
		}
		$state = json_decode( file_get_contents( $path ), true ); // phpcs:ignore
		return is_array( $state ) ? $state : false;
	}

	/**
	 * Remove a directory tree.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function rmdir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->rmdir_recursive( $path );
			} else {
				@unlink( $path ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}
