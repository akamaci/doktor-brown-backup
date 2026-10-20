<?php
/**
 * Importer: restores a .dbb (ZIP) or .wpress archive.
 *
 * Supports: chunked upload, archive detection, chunked extraction, database
 * restore, serialization-safe domain search/replace, and file restore.
 *
 * The engine exposes start()/step()/prepare_from_backup() so it can run from
 * AJAX, WP-CLI, or a test harness.
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import engine.
 */
class DBB_Importer {

	/** @var int Files/extract entries processed per step. */
	const FILES_PER_STEP = 250;

	/** @var int Seconds allowed per step. */
	const TIME_BUDGET = 8;

	/** @var int SQL statements per DB step. */
	const DB_STATEMENTS_PER_STEP = 600;

	/* ------------------------------------------------------------------ */
	/* AJAX wrappers                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * AJAX: resume an interrupted upload from where it left off.
	 *
	 * Returns the most recent uploaded-but-not-processed job and the bytes
	 * already accepted by the server. The client can then continue sending
	 * chunks from that offset instead of restarting from zero.
	 *
	 * @return void
	 */
	public function ajax_resume() {
		DBB_Plugin::verify_request();
		$res = $this->resume_state();
		if ( ! $res ) {
			wp_send_json_success( array( 'job' => null ) );
			return;
		}
		$bytes = 0;
		if ( ! empty( $res['archive'] ) && is_file( $res['archive'] ) ) {
			$bytes = (int) filesize( $res['archive'] );
		}
		wp_send_json_success(
			array(
				'job'     => isset( $res['job'] ) ? $res['job'] : '',
				'bytes'   => $bytes,
				'step'    => isset( $res['step'] ) ? $res['step'] : '',
			)
		);
	}

	/**
	 * Find the most recent job that is still mid-upload.
	 *
	 * @return array|null
	 */
	private function resume_state() {
		if ( ! is_dir( DBB_TMP_DIR ) ) {
			return null;
		}
		$cands = array();
		foreach ( (array) scandir( DBB_TMP_DIR ) as $d ) {
			if ( 0 !== strpos( $d, 'imp-' ) ) {
				continue;
			}
			$state = $this->load_state( $d );
			if ( ! is_array( $state ) || empty( $state['step'] ) || 'upload' !== $state['step'] ) {
				continue;
			}
			$cands[] = $state;
		}
		if ( empty( $cands ) ) {
			// Fallback: any state that never finished processing.
			foreach ( (array) glob( DBB_TMP_DIR . '/imp-*/state.json' ) as $state_file ) { // phpcs:ignore
				$state = json_decode( file_get_contents( $state_file ), true ); // phpcs:ignore
				if ( is_array( $state ) && isset( $state['status'] ) && 'uploading' === $state['status'] ) {
					$cands[] = $state;
				}
			}
		}
		if ( empty( $cands ) ) {
			return null;
		}
		usort(
			$cands,
			function ( $a, $b ) {
				$a_t = isset( $a['created'] ) ? (int) $a['created'] : 0;
				$b_t = isset( $b['created'] ) ? (int) $b['created'] : 0;
				return $b_t - $a_t;
			}
		);
		return $cands[0];
	}

	/**
	 * Delete leftover upload jobs (disk + state) before a fresh start.
	 *
	 * Used when the user chose "restart from zero" instead of resuming.
	 * Kept resumable until actually replaced so an interrupted upload is
	 * never silently lost by an empty retry.
	 *
	 * @return void
	 */
	private function purge_upload_jobs() {
		if ( ! is_dir( DBB_TMP_DIR ) ) {
			return;
		}
		foreach ( (array) scandir( DBB_TMP_DIR ) as $d ) {
			if ( 0 !== strpos( $d, 'imp-' ) || ! is_dir( DBB_TMP_DIR . '/' . $d ) ) {
				continue;
			}
			$state = $this->load_state( $d );
			if ( is_array( $state ) && isset( $state['step'] ) && 'upload' === $state['step'] ) {
				$this->rmdir_recursive( DBB_TMP_DIR . '/' . $d );
			}
		}
	}

	/**
	 * AJAX: create an import job.
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
	 * AJAX: receive one binary chunk and append it.
	 *
	 * @return void
	 */
	public function ajax_upload() {
		DBB_Plugin::verify_request();

		/* Detect a body that exceeded post_max_size: PHP silently empties both
		   $_POST and $_FILES, which used to surface as "İş bulunamadı" (404). */
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$post_max       = self::bytes_from_ini( ini_get( 'post_max_size' ) );
		if ( $content_length > 0 && $post_max > 0 && $content_length > $post_max ) {
			DBB_Logger::error(
				'upload chunk exceeds post_max_size',
				array( 'content_length' => $content_length, 'post_max_size' => $post_max )
			);
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: request bytes, 2: limit */
						__( 'Parça sunucunun post_max_size sınırını aştı (%1$s > %2$s). Parça küçültülüyor.', 'doktor-brown-backup' ),
						size_format( $content_length ),
						size_format( $post_max )
					),
				),
				413
			);
		}

		$job   = isset( $_POST['job'] ) ? sanitize_file_name( wp_unslash( $_POST['job'] ) ) : '';
		$state = $this->load_state( $job );
		if ( ! $state ) {
			DBB_Logger::error(
				'upload chunk: job not found',
				array(
					'job'            => $job,
					'content_length' => $content_length,
					'post_present'   => ! empty( $_POST ),
					'files_present'  => ! empty( $_FILES ),
				)
			);
			wp_send_json_error( array( 'message' => __( 'İş bulunamadı.', 'doktor-brown-backup' ) ), 404 );
		}

		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;

		if ( empty( $_FILES['chunk'] ) || ! isset( $_FILES['chunk']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Parça alınamadı.', 'doktor-brown-backup' ) ), 400 );
		}

		/* v1.1.0 — %4'te takılma: sunucu limitine takılan parçalarda PHP
		   `tmp_name`'i boş + `error`=UPLOAD_ERR_INI_SIZE bırakır. Bu durumu
		   net bir hata olarak döndür; JS parçayı küçültüp yeniden dener
		   (barsak ilmekte takılıp kalmak yerine). */
		if ( ! empty( $_FILES['chunk']['error'] ) ) {
			$code = (int) $_FILES['chunk']['error'];
			if ( UPLOAD_ERR_INI_SIZE === $code || UPLOAD_ERR_FORM_SIZE === $code ) {
				wp_send_json_error( array( 'message' => __( 'Parça sunucunun yükleme sınırını aştı — parça küçültülüyor.', 'doktor-brown-backup' ) ), 413 );
			}
			wp_send_json_error( array( 'message' => __( 'Parça yükleme hatası.', 'doktor-brown-backup' ) ), 400 );
		}

		$tmp = $_FILES['chunk']['tmp_name']; // phpcs:ignore
		if ( ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'Geçersiz yükleme.', 'doktor-brown-backup' ) ), 400 );
		}
		$chunk_len = (int) filesize( $tmp );
		if ( $chunk_len < 0 ) {
			wp_send_json_error( array( 'message' => __( 'Parça okunamadı.', 'doktor-brown-backup' ) ), 400 );
		}

		$max_chunk = (int) apply_filters( 'dbb_max_chunk_size', 5 * 1024 * 1024 );
		if ( $chunk_len > $max_chunk + 65536 ) {
			DBB_Logger::warn( 'upload chunk above hard limit', array( 'chunk' => $chunk_len, 'max' => $max_chunk ) );
			wp_send_json_error( array( 'message' => __( 'Parça 5 MB sınırını aştı — parça küçültülüyor.', 'doktor-brown-backup' ) ), 413 );
		}

		$free = @disk_free_space( dirname( $state['archive'] ) ); // phpcs:ignore
		if ( false !== $free && $free < ( $chunk_len + 50 * 1024 * 1024 ) ) {
			DBB_Logger::error( 'not enough disk space for upload', array( 'free' => $free, 'need' => $chunk_len ) );
			wp_send_json_error( array( 'message' => __( 'Sunucuda yeterli disk alanı yok.', 'doktor-brown-backup' ) ), 507 );
		}

		/* v1.2.0 — kaldığı yerden devam: parça, sunucuda ZATEN kabul
		   edilmiş baytlara denk geliyorsa (çift gönderim / eski parça)
		   yazmayı atla; gerçek `received` değerini döndür ki istemci
		   doğru noktadan devam etsin. `offset` disk boyutunu AŞIYORSA
		   araya boşluk bırakmamak için parçayı reddet (409). */
		$disk_ok = 0;
		if ( is_file( $state['archive'] ) ) {
			$disk_ok = (int) filesize( $state['archive'] );
		}
		if ( $offset > $disk_ok ) {
			DBB_Logger::warn( 'upload chunk rejected: gap', array( 'offset' => $offset, 'disk' => $disk_ok ) );
			wp_send_json_error( array( 'message' => __( 'Parça sırası bozuk — dosya bozulmasın diye reddedildi.', 'doktor-brown-backup' ) ), 409 );
		}
		if ( $offset + $chunk_len <= $disk_ok ) {
			// Bu bölge zaten yazılmış: atla, ama tmp'yi temizle.
			@unlink( $tmp ); // phpcs:ignore
			$state['bytes'] = max( $state['bytes'], $disk_ok );
			$this->save_state( $state );
			wp_send_json_success( array( 'received' => $disk_ok ) );
		}

		$in  = fopen( $tmp, 'rb' ); // phpcs:ignore
		$out = fopen( $state['archive'], ( 0 === $offset ) ? 'wb' : 'r+b' ); // phpcs:ignore
		if ( ! $in || ! $out ) {
			wp_send_json_error( array( 'message' => __( 'Dosya yazılamadı.', 'doktor-brown-backup' ) ), 500 );
		}
		if ( 0 !== $offset ) {
			fseek( $out, $offset );
		}
		while ( ! feof( $in ) ) {
			$buf = fread( $in, 1048576 ); // phpcs:ignore
			if ( false === $buf || '' === $buf ) {
				break;
			}
			fwrite( $out, $buf ); // phpcs:ignore
		}
		fclose( $in ); // phpcs:ignore
		fclose( $out ); // phpcs:ignore
		@unlink( $tmp ); // phpcs:ignore

		$state['bytes'] = (int) filesize( $state['archive'] );
		$this->save_state( $state );

		DBB_Logger::info(
			'upload chunk accepted',
			array(
				'job'     => $job,
				'offset'  => $offset,
				'chunk'   => $chunk_len,
				'received'=> $state['bytes'],
			)
		);

		wp_send_json_success( array( 'received' => (int) filesize( $state['archive'] ) ) );
	}

	/**
	 * AJAX: advance import processing.
	 *
	 * @return void
	 */
	public function ajax_step() {
		DBB_Plugin::verify_request();
		$job        = isset( $_POST['job'] ) ? sanitize_file_name( wp_unslash( $_POST['job'] ) ) : '';
		$target     = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';
		$auto       = isset( $_POST['auto_domain'] ) ? (bool) $_POST['auto_domain'] : true;
		$res        = $this->step( $job, $target, $auto );
		if ( isset( $res['error'] ) ) {
			wp_send_json_error( array( 'message' => $res['error'] ), isset( $res['code'] ) ? (int) $res['code'] : 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: prepare a restore job from a stored backup.
	 *
	 * @return void
	 */
	public function ajax_restore() {
		DBB_Plugin::verify_request();
		$file   = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
		$target = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';
		$auto   = isset( $_POST['auto_domain'] ) ? (bool) $_POST['auto_domain'] : true;
		$res    = $this->prepare_from_backup( $file, $target, $auto );
		if ( isset( $res['error'] ) ) {
			wp_send_json_error( array( 'message' => $res['error'] ) );
		}
		wp_send_json_success( $res );
	}

	/* ------------------------------------------------------------------ */
	/* Engine API                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Create an upload job.
	 *
	 * @return array
	 */
	public function start() {
		$job     = 'imp-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
		$job_dir = DBB_TMP_DIR . '/' . $job;
		wp_mkdir_p( $job_dir );

		/* v1.2.0 — "kaldığı yerden devam": kullanıcı işi YENİDEN
		   başlatmayı seçtiyse, yarım kalmış upload işlerinin disk
		   bölgesi (import.bin) temizlenir — gerçekten yeni baştan
		   yükleneceği için. (Resume için ayrı `dbb_import_resume`
		   akışı kullanılır; o akışta bu silme çağrılmaz.) */
		$this->purge_upload_jobs();

		$state = array(
			'job'         => $job,
			'created'     => time(),
			'status'      => 'uploading',
			'step'        => 'upload',
			'archive'     => $job_dir . '/import.bin',
			'job_dir'     => $job_dir,
			'cleanup_archive' => true,
			'bytes'       => 0,
			'index'       => 0,
			'files'       => array(),
			'auto_domain' => true,
			'message'     => __( 'Yükleme bekleniyor…', 'doktor-brown-backup' ),
		);
		$this->save_state( $state );
		DBB_Logger::info( 'import job created (upload)', array( 'job' => $job ) );

		return array( 'job' => $job );
	}

	/**
	 * Prepare a job from an existing backup on disk (no upload needed).
	 *
	 * @param string $file        Filename inside DBB_BACKUPS_DIR.
	 * @param string $target_url  Optional new site URL.
	 * @param bool   $auto_domain Auto-detect old URL for replacement.
	 * @return array
	 */
	public function prepare_from_backup( $file, $target_url = '', $auto_domain = true ) {
		$file = sanitize_file_name( $file );
		$src  = DBB_BACKUPS_DIR . '/' . $file;
		if ( '' === $file || ! is_file( $src ) ) {
			return array( 'error' => __( 'Yedek dosyası bulunamadı.', 'doktor-brown-backup' ) );
		}

		$job     = 'imp-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
		$job_dir = DBB_TMP_DIR . '/' . $job;
		wp_mkdir_p( $job_dir );

		/* Use the stored backup in place. Copying a 100+ MB archive inside one
		   PHP request regularly exceeds Plesk's 30–60 s limit and was one of
		   the "stops halfway" causes. */
		$archive = $src;
		$cleanup = false;

		if ( ! is_readable( $archive ) ) {
			$archive = $job_dir . '/import.' . pathinfo( $file, PATHINFO_EXTENSION );
			if ( ! @copy( $src, $archive ) ) { // phpcs:ignore
				DBB_Logger::error( 'prepare_from_backup: copy failed', array( 'src' => $src, 'dst' => $archive ) );
				return array( 'error' => __( 'Yedek kopyalanamadı.', 'doktor-brown-backup' ) );
			}
			$cleanup = true;
		}

		$state = array(
			'job'         => $job,
			'created'     => time(),
			'status'      => 'running',
			'step'        => 'detect',
			'archive'     => $archive,
			'job_dir'     => $job_dir,
			'cleanup_archive' => $cleanup,
			'bytes'       => (int) filesize( $archive ),
			'index'       => 0,
			'files'       => array(),
			'target_url'  => $target_url ? esc_url_raw( $target_url ) : '',
			'auto_domain' => (bool) $auto_domain,
			'message'     => __( 'Geri yükleme hazırlanıyor…', 'doktor-brown-backup' ),
		);
		$this->save_state( $state );
		DBB_Logger::info( 'import job created (stored backup)', array( 'job' => $job, 'file' => $file, 'in_place' => ! $cleanup ) );

		return array( 'job' => $job );
	}

	/**
	 * Advance an import job by one step.
	 *
	 * @param string $job         Job id.
	 * @param string $target_url  Optional new site URL.
	 * @param bool   $auto_domain Auto-detect old URL.
	 * @return array
	 */
	public function step( $job, $target_url = '', $auto_domain = true ) {
		$state = $this->load_state( $job );
		if ( ! $state ) {
			return array( 'error' => __( 'İş bulunamadı.', 'doktor-brown-backup' ), 'code' => 404 );
		}

		if ( '' !== $target_url ) {
			$state['target_url'] = esc_url_raw( $target_url );
		}
		$state['auto_domain'] = (bool) $auto_domain;

		if ( empty( $state['job_dir'] ) ) {
			$state['job_dir'] = DBB_TMP_DIR . '/' . $state['job'];
		}
		if ( ! isset( $state['cleanup_archive'] ) ) {
			$state['cleanup_archive'] = ( 0 === strpos( (string) $state['archive'], $state['job_dir'] ) );
		}

		if ( 'upload' === $state['step'] ) {
			$state['step'] = 'detect';
		}

		$started = microtime( true );

		try {
			switch ( $state['step'] ) {
				case 'detect':
					$this->step_detect( $state );
					break;
				case 'extract':      // .dbb (ZIP)
					$this->step_extract_dbb( $state );
					break;
				case 'wp-extract':   // .wpress
					$this->step_extract_wpress( $state, $started );
					break;
				case 'db-safety':
					$this->step_db_safety( $state, $started );
					break;
				case 'db':
					$this->step_db( $state, $started );
					break;
				case 'replace':
					$this->step_replace( $state, $started );
					break;
				case 'files':
					$this->step_files( $state, $started );
					break;
				case 'finalize':
					$this->step_finalize( $state );
					break;
			}
		} catch ( Exception $e ) {
			$state['status']  = 'error';
			$state['message'] = $e->getMessage();
			$this->save_state( $state );
			DBB_Logger::error( 'import step exception', array( 'job' => $job, 'step' => $state['step'], 'error' => $e->getMessage() ) );
			DBB_Logger::exception( $e, array( 'job' => $job ) );
			return array( 'error' => $e->getMessage() );
		}

		$this->save_state( $state );

		DBB_Logger::info(
			'import step',
			array(
				'job'      => $job,
				'step'     => $state['step'],
				'status'   => $state['status'],
				'duration' => round( microtime( true ) - $started, 2 ),
				'progress' => $this->progress( $state ),
			)
		);

		return array(
			'status'   => $state['status'],
			'step'     => $state['step'],
			'progress' => $this->progress( $state ),
			'message'  => $state['message'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Steps                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Detect archive format and read its manifest.
	 *
	 * @param array $state State (by reference).
	 * @return void
	 */
	private function step_detect( &$state ) {
		$archive = $state['archive'];
		$extract = ( ! empty( $state['job_dir'] ) ? $state['job_dir'] : dirname( $archive ) ) . '/extract';
		if ( ! file_exists( $extract ) ) {
			wp_mkdir_p( $extract );
		}
		$state['extract_dir'] = $extract;

		// Capture the destination URL BEFORE the DB is overwritten.
		$state['resolved_new_url'] = ! empty( $state['target_url'] )
			? rtrim( $state['target_url'], '/' )
			: rtrim( home_url(), '/' );

		$fp = fopen( $archive, 'rb' ); // phpcs:ignore
		$magic = $fp ? fread( $fp, 4 ) : ''; // phpcs:ignore
		if ( $fp ) {
			fclose( $fp ); // phpcs:ignore
		}

		if ( 0 === strpos( $magic, 'PK' ) ) {
			$state['format'] = 'dbb';
			$manifest = DBB_Archive::read_manifest( $archive );
			if ( ! is_wp_error( $manifest ) ) {
				$state['old_site_url'] = isset( $manifest['site_url'] ) ? $manifest['site_url'] : '';
				$state['old_home_url'] = isset( $manifest['home_url'] ) ? $manifest['home_url'] : '';
			}
			$state['step']    = 'extract';
			$state['message'] = __( 'Arşiv çıkartılıyor…', 'doktor-brown-backup' );
			DBB_Logger::info( 'detected .dbb archive', array( 'archive' => $archive, 'size' => filesize( $archive ) ) );
			return;
		}

		// Assume .wpress.
		$state['format'] = 'wpress';
		$pkg = DBB_Wpress::read_package( $archive );
		if ( ! is_wp_error( $pkg ) ) {
			$state['old_site_url'] = isset( $pkg['SiteURL'] ) ? $pkg['SiteURL'] : '';
			$state['old_home_url'] = isset( $pkg['HomeURL'] ) ? $pkg['HomeURL'] : '';
		}
		$entries = DBB_Wpress::index( $archive );
		if ( is_wp_error( $entries ) ) {
			throw new Exception( $entries->get_error_message() );
		}
		$entries_file = dirname( $archive ) . '/entries.json';
		file_put_contents( $entries_file, wp_json_encode( $entries ) ); // phpcs:ignore
		$state['entries_file'] = $entries_file;
		$state['wp_total']     = count( $entries );
		$state['wp_pos']       = 0;
		$state['sql_seen']     = false;
		$state['files_root']   = $extract . '/files';

		if ( empty( $entries ) ) {
			throw new Exception( __( 'Geçersiz .wpress arşivi (boş).', 'doktor-brown-backup' ) );
		}

		$state['step']    = 'wp-extract';
		$state['message'] = sprintf(
			/* translators: %d: entries */
			__( '.wpress çıkartılıyor… (%d öge)', 'doktor-brown-backup' ),
			$state['wp_total']
		);
	}

	/**
	 * Extract a .dbb (ZIP) archive fully.
	 *
	 * @param array $state State (by reference).
	 * @return void
	 */
	private function step_extract_dbb( &$state ) {
		$ok = DBB_Archive::extract_to( $state['archive'], $state['extract_dir'] );
		if ( is_wp_error( $ok ) ) {
			throw new Exception( $ok->get_error_message() );
		}
		$state['step']    = 'db';
		$state['message'] = __( 'Veritabanı geri yükleniyor…', 'doktor-brown-backup' );
	}

	/**
	 * Extract a batch of .wpress entries.
	 *
	 * @param array $state   State (by reference).
	 * @param float $started Request start time.
	 * @return void
	 */
	private function step_extract_wpress( &$state, $started ) {
		$entries = json_decode( file_get_contents( $state['entries_file'] ), true ); // phpcs:ignore
		if ( ! is_array( $entries ) ) {
			throw new Exception( __( '.wpress indeksi okunamadı.', 'doktor-brown-backup' ) );
		}

		$sql_path = $state['extract_dir'] . '/' . DBB_Archive::DATABASE;
		$root     = $state['files_root'];

		$processed = 0;
		$res = DBB_Wpress::extract_range( $state['archive'], $entries, $state['wp_pos'], self::FILES_PER_STEP, $root, $sql_path );
		if ( ! empty( $res['sql'] ) ) {
			$state['sql_seen'] = true;
		}
		$state['wp_pos'] = $res['next'];

		if ( $state['wp_pos'] >= $state['wp_total'] ) {
			if ( ! $state['sql_seen'] && ! is_file( $sql_path ) ) {
				// Some archives name the dump differently; search the extract dir.
				$found = glob( $state['extract_dir'] . '/*.sql' );
				if ( $found ) {
					copy( $found[0], $sql_path );
					$state['sql_seen'] = true;
				}
			}
			$state['step']    = 'db';
			$state['message'] = __( 'Veritabanı geri yükleniyor…', 'doktor-brown-backup' );
		} else {
			$state['message'] = sprintf(
				/* translators: 1: done, 2: total */
				__( '.wpress çıkartılıyor… (%1$d/%2$d)', 'doktor-brown-backup' ),
				(int) $state['wp_pos'],
				(int) $state['wp_total']
			);
		}
	}

	/**
	 * Restore database.sql.
	 *
	 * @param array $state State (by reference).
	 * @return void
	 */
	private function step_db( &$state, $started ) {
		$sql = $state['extract_dir'] . '/' . DBB_Archive::DATABASE;
		if ( ! is_file( $sql ) ) {
			throw new Exception( __( 'Arşivde veritabanı (.sql) bulunamadı.', 'doktor-brown-backup' ) );
		}

		// First restore: take a safety dump of the DB we are about to replace.
		if ( empty( $state['safety_done'] ) && ! isset( $state['db_offset'] ) ) {
			$state['db_offset']    = 0;
			$state['db_size']      = (int) filesize( $sql );
			$state['db_executed']  = 0;
			$state['db_table']     = '';

			/**
			 * Disable the pre-restore safety dump (not recommended).
			 *
			 * @param bool  $enabled Default true.
			 * @param array $state   Import state.
			 */
			if ( apply_filters( 'dbb_safety_backup', true, $state ) ) {
				$state['safety_path']   = ( ! empty( $state['job_dir'] ) ? $state['job_dir'] : dirname( $state['archive'] ) ) . '/pre-restore-' . gmdate( 'Ymd-His' ) . '.sql';
				$state['safety_tables'] = DBB_DB::tables();
				$state['safety_index']  = 0;
				$state['step']          = 'db-safety';
				$state['message']       = __( 'Mevcut veritabanı güvenlik yedeğine alınıyor…', 'doktor-brown-backup' );
				DBB_Logger::info( 'safety dump started', array( 'tables' => count( $state['safety_tables'] ), 'path' => $state['safety_path'] ) );
				return;
			}
			$state['safety_done'] = true;
		}

		$budget = microtime( true ) + self::TIME_BUDGET;
		$batch  = DBB_DB::restore_batch( $sql, (int) $state['db_offset'], self::DB_STATEMENTS_PER_STEP, $budget );

		$state['db_offset']   = (int) $batch['offset'];
		$state['db_executed'] = (int) ( isset( $state['db_executed'] ) ? $state['db_executed'] : 0 ) + (int) $batch['executed'];
		if ( ! empty( $batch['table'] ) ) {
			$state['db_table'] = $batch['table'];
		}

		if ( ! empty( $batch['error'] ) ) {
			$size = isset( $state['db_size'] ) ? (int) $state['db_size'] : max( 1, (int) filesize( $sql ) );
			DBB_Logger::error(
				'DB restore failed',
				array(
					'job'      => $state['job'],
					'table'    => isset( $state['db_table'] ) ? $state['db_table'] : '',
					'offset'   => $state['db_offset'],
					'percent'  => round( 100 * $state['db_offset'] / $size, 1 ),
					'executed' => $state['db_executed'],
					'mysql'    => $batch['error'],
					'sql_head' => isset( $batch['stmt'] ) ? $batch['stmt'] : '',
				)
			);
			throw new Exception(
				sprintf(
					/* translators: 1: table, 2: mysql error */
					__( 'Veritabanı geri yüklenirken SQL hatası (%1$s): %2$s', 'doktor-brown-backup' ),
					$state['db_table'] ? $state['db_table'] : '?',
					$batch['error']
				)
			);
		}

		if ( ! $batch['done'] ) {
			$size = isset( $state['db_size'] ) ? (int) $state['db_size'] : max( 1, (int) filesize( $sql ) );
			$pct  = (int) round( 100 * $state['db_offset'] / $size );
			$state['message'] = sprintf(
				/* translators: 1: percent, 2: table, 3: statements */
				__( 'Veritabanı geri yükleniyor… %% %1$d · %2$s · %3$s sorgu', 'doktor-brown-backup' ),
				$pct,
				$state['db_table'] ? $state['db_table'] : '—',
				number_format_i18n( $state['db_executed'] )
			);
			DBB_Logger::info(
				'db batch',
				array(
					'job'      => $state['job'],
					'offset'   => $state['db_offset'],
					'size'     => $size,
					'percent'  => $pct,
					'table'    => $state['db_table'],
					'executed' => $state['db_executed'],
				)
			);
			return;
		}

		DBB_Logger::info( 'DB restore finished', array( 'job' => $state['job'], 'statements' => $state['db_executed'] ) );

		$state['replace_tables'] = DBB_DB::tables();
		$state['replace_index']  = 0;
		$state['step']           = $this->has_domain_change( $state ) ? 'replace' : 'files';
		$state['message']        = ( 'replace' === $state['step'] )
			? __( 'Site adresi güncelleniyor…', 'doktor-brown-backup' )
			: __( 'Dosyalar geri yükleniyor…', 'doktor-brown-backup' );
	}

	/**
	 * Dump the current database into dbb-tmp before it is replaced.
	 *
	 * Runs one batch of tables per request so Plesk/FPM time limits do not
	 * kill the dump halfway. The file stays on disk if the restore fails and
	 * is removed together with the job directory only after a successful run.
	 *
	 * @param array $state   State (by reference).
	 * @param float $started Request start time.
	 * @return void
	 */
	private function step_db_safety( &$state, $started ) {
		if ( empty( $state['safety_path'] ) ) {
			$state['safety_done'] = true;
			$state['step']        = 'db';
			return;
		}

		$tables = isset( $state['safety_tables'] ) ? (array) $state['safety_tables'] : DBB_DB::tables();
		$total  = count( $tables );
		$first  = ( 0 === (int) $state['safety_index'] );

		$fp = fopen( $state['safety_path'], $first ? 'wb' : 'ab' ); // phpcs:ignore
		if ( ! $fp ) {
			throw new Exception( __( 'Güvenlik yedeği yazılamadı.', 'doktor-brown-backup' ) );
		}
		if ( $first ) {
			fwrite( $fp, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" ); // phpcs:ignore
		}

		$done = 0;
		while ( $state['safety_index'] < $total && $done < 2 && ( microtime( true ) - $started ) < self::TIME_BUDGET ) {
			DBB_DB::dump_table( $tables[ $state['safety_index'] ], $fp );
			$state['safety_index']++;
			$done++;
		}

		$finished = ( $state['safety_index'] >= $total );
		if ( $finished ) {
			fwrite( $fp, "\nSET FOREIGN_KEY_CHECKS=1;\n" ); // phpcs:ignore
		}
		fclose( $fp ); // phpcs:ignore

		if ( ! $finished ) {
			$state['message'] = sprintf(
				/* translators: 1: done tables, 2: total tables */
				__( 'Güvenlik yedeği alınıyor… (%1$d/%2$d tablo)', 'doktor-brown-backup' ),
				(int) $state['safety_index'],
				$total
			);
			return;
		}

		$state['safety_done'] = true;
		$state['step']        = 'db';
		$state['message']     = __( 'Veritabanı geri yükleniyor…', 'doktor-brown-backup' );
		DBB_Logger::info(
			'safety dump finished',
			array(
				'path'   => $state['safety_path'],
				'size'   => is_file( $state['safety_path'] ) ? filesize( $state['safety_path'] ) : 0,
				'tables' => $total,
			)
		);
	}

	/**
	 * Does a domain replacement apply?
	 *
	 * @param array $state State.
	 * @return bool
	 */
	private function has_domain_change( $state ) {
		if ( empty( $state['auto_domain'] ) && empty( $state['target_url'] ) ) {
			return false;
		}
		$old = $this->detect_old_url( $state );
		$new = $this->detect_new_url( $state );
		return ( '' !== $old && '' !== $new && $old !== $new );
	}

	/**
	 * Old site URL (from manifest/package or stored DB option).
	 *
	 * @param array $state State.
	 * @return string
	 */
	private function detect_old_url( $state ) {
		if ( ! empty( $state['old_site_url'] ) ) {
			return rtrim( $state['old_site_url'], '/' );
		}
		$home = get_option( 'home' );
		return is_string( $home ) ? rtrim( $home, '/' ) : '';
	}

	/**
	 * New site URL (explicit target, else current site's own URL).
	 *
	 * @param array $state State.
	 * @return string
	 */
	private function detect_new_url( $state ) {
		if ( ! empty( $state['resolved_new_url'] ) ) {
			return rtrim( $state['resolved_new_url'], '/' );
		}
		if ( ! empty( $state['target_url'] ) ) {
			return rtrim( $state['target_url'], '/' );
		}
		return rtrim( home_url(), '/' );
	}

	/**
	 * Replace old domain with new domain, one table per batch.
	 *
	 * @param array $state   State (by reference).
	 * @param float $started Request start time.
	 * @return void
	 */
	private function step_replace( &$state, $started ) {
		$old = $this->detect_old_url( $state );
		$new = $this->detect_new_url( $state );
		$pairs = DBB_Replace::url_pairs( $old, $new );
		$tables = $state['replace_tables'];
		$total  = count( $tables );

		$done = 0;
		while ( $state['replace_index'] < $total && $done < 3 && ( microtime( true ) - $started ) < self::TIME_BUDGET ) {
			DBB_Replace::table_pairs( $tables[ $state['replace_index'] ], $pairs );
			$state['replace_index']++;
			$done++;
		}

		if ( $state['replace_index'] >= $total ) {
			update_option( 'siteurl', $new );
			update_option( 'home', $new );
			$state['step']    = 'files';
			$state['message'] = __( 'Dosyalar geri yükleniyor…', 'doktor-brown-backup' );
		} else {
			$state['message'] = sprintf(
				/* translators: 1: done, 2: total */
				__( 'Site adresi güncelleniyor… (%1$d/%2$d tablo)', 'doktor-brown-backup' ),
				(int) $state['replace_index'],
				$total
			);
		}
	}

	/**
	 * Restore a batch of files.
	 *
	 * @param array $state   State (by reference).
	 * @param float $started Request start time.
	 * @return void
	 */
	private function step_files( &$state, $started ) {
		$base = $state['extract_dir'] . '/' . DBB_Archive::FILES_DIR;
		if ( empty( $state['files'] ) ) {
			$state['files'] = $this->list_extracted( $base );
		}
		$total = count( $state['files'] );

		$done = 0;
		while ( $state['index'] < $total && $done < self::FILES_PER_STEP && ( microtime( true ) - $started ) < self::TIME_BUDGET ) {
			$rel = $state['files'][ $state['index'] ];
			$src = $base . '/' . $rel;
			$dst = DBB_Filesystem::resolve( $rel );

			/* Never overwrite wp-config.php: the archive's copy points at the
			   source server's database (e.g. root/localhost) and would take the
			   destination site down instantly. */
			if ( 'wp-config.php' === ltrim( str_replace( '\\', '/', $rel ), '/' ) ) {
				DBB_Logger::warn( 'skipped wp-config.php during file restore', array( 'job' => $state['job'] ) );
				$state['index']++;
				$done++;
				continue;
			}

			if ( $dst && is_file( $src ) ) {
				$dir = dirname( $dst );
				if ( ! file_exists( $dir ) ) {
					wp_mkdir_p( $dir );
				}
				if ( ! @copy( $src, $dst ) ) { // phpcs:ignore
					DBB_Logger::warn( 'file copy failed', array( 'src' => $src, 'dst' => $dst ) );
				}
			}
			$state['index']++;
			$done++;
		}

		if ( $state['index'] >= $total ) {
			$state['step'] = 'finalize';
		} else {
			$state['message'] = sprintf(
				/* translators: 1: done, 2: total */
				__( 'Dosyalar geri yükleniyor… (%1$d/%2$d)', 'doktor-brown-backup' ),
				(int) $state['index'],
				$total
			);
		}
	}

	/**
	 * Finalize and clean up.
	 *
	 * @param array $state State (by reference).
	 * @return void
	 */
	private function step_finalize( &$state ) {
		$target = $this->detect_new_url( $state );
		if ( ! empty( $state['target_url'] ) ) {
			update_option( 'siteurl', $target );
			update_option( 'home', $target );
		}

		$job_dir = ! empty( $state['job_dir'] ) ? $state['job_dir'] : dirname( $state['archive'] );
		$this->rmdir_recursive( $job_dir );

		$state['status']  = 'done';
		$state['step']    = 'done';
		$state['message'] = $target
			? sprintf( /* translators: %s: url */ __( 'Geri yükleme tamamlandı. Adres: %s', 'doktor-brown-backup' ), $target )
			: __( 'Geri yükleme tamamlandı.', 'doktor-brown-backup' );
		DBB_Logger::info( 'import finished', array( 'job' => $state['job'], 'target' => $target ) );
	}

	/**
	 * List extracted files (relative to files/).
	 *
	 * @param string $base Base dir.
	 * @return array
	 */
	private function list_extracted( $base ) {
		$out = array();
		if ( ! is_dir( $base ) ) {
			return $out;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		$base = rtrim( str_replace( '\\', '/', $base ), '/' ) . '/';
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$out[] = ltrim( substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $base ) ), '/' );
		}
		return $out;
	}

	/**
	 * Progress 0..100.
	 *
	 * @param array $state State.
	 * @return int
	 */
	private function progress( $state ) {
		switch ( $state['step'] ) {
			case 'upload':
				return 5;
			case 'detect':
				return 8;
			case 'extract':
				return 15;
			case 'wp-extract':
				$total = max( 1, (int) $state['wp_total'] );
				return (int) round( 10 + 25 * ( $state['wp_pos'] / $total ) );
			case 'db':
				$size = isset( $state['db_size'] ) ? max( 1, (int) $state['db_size'] ) : 1;
				$pos  = isset( $state['db_offset'] ) ? (int) $state['db_offset'] : 0;
				return (int) round( 22 + 23 * min( 1, $pos / $size ) );
			case 'db-safety':
				$total = isset( $state['safety_tables'] ) ? max( 1, count( $state['safety_tables'] ) ) : 1;
				$done  = isset( $state['safety_index'] ) ? (int) $state['safety_index'] : 0;
				return (int) round( 15 + 7 * min( 1, $done / $total ) );
			case 'replace':
				$total = max( 1, count( $state['replace_tables'] ) );
				return (int) round( 50 + 10 * ( $state['replace_index'] / $total ) );
			case 'files':
				$total = max( 1, count( $state['files'] ) );
				return (int) round( 60 + 38 * ( $state['index'] / $total ) );
			case 'done':
				return 100;
			default:
				return 20;
		}
	}

	/**
	 * Convert a php.ini size ("64M", "1G", "0") to bytes.
	 *
	 * @param string $value ini value.
	 * @return int
	 */
	private static function bytes_from_ini( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		$unit = strtolower( substr( $value, -1 ) );
		$num  = (float) $value;
		switch ( $unit ) {
			case 'g':
				$num *= 1024;
				// Fall through.
			case 'm':
				$num *= 1024;
				// Fall through.
			case 'k':
				$num *= 1024;
		}
		return (int) $num;
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
