<?php
/**
 * Admin UI: menus, pages, assets, backup list, download & delete.
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens.
 */
class DBB_Admin {

	/** @var string Option holding the backup index. */
	const INDEX_OPTION = 'dbb_index';

	/** @var string */
	const SLUG = 'dbb-backups';

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Doktor Brown Backup', 'doktor-brown-backup' ),
			__( 'Dr. Brown Backup', 'doktor-brown-backup' ),
			DBB_Plugin::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'page_list' ),
			'dashicons-database-export',
			80
		);

		add_submenu_page( self::SLUG, __( 'Yedekler', 'doktor-brown-backup' ), __( 'Yedekler', 'doktor-brown-backup' ), DBB_Plugin::CAPABILITY, self::SLUG, array( __CLASS__, 'page_list' ) );
		add_submenu_page( self::SLUG, __( 'Yeni Yedek', 'doktor-brown-backup' ), __( 'Yeni Yedek', 'doktor-brown-backup' ), DBB_Plugin::CAPABILITY, 'dbb-new', array( __CLASS__, 'page_export' ) );
		add_submenu_page( self::SLUG, __( 'Geri Yükle', 'doktor-brown-backup' ), __( 'Geri Yükle', 'doktor-brown-backup' ), DBB_Plugin::CAPABILITY, 'dbb-import', array( __CLASS__, 'page_import' ) );
	}

	/**
	 * Safe upload chunk size in bytes.
	 *
	 * Uses the smallest of WordPress' upload limit (upload_max_filesize /
	 * post_max_size) and keeps a 30% safety margin for the multipart
	 * overhead so no chunk ever exceeds a server limit.
	 *
	 * v1.1.0 — the previous fixed 2 MB chunk stalled at ~4% on servers whose
	 * upload limit was ~2 MB: chunk 1 fit, chunk 2 was rejected and the JS
	 * had no retry/shrink path.  Now the chunk is derived from real limits.
	 *
	 * @return int Bytes.
	 */
	private static function upload_chunk_size() {
		$limit = ( 0 < wp_max_upload_size() ) ? wp_max_upload_size() : 2 * 1024 * 1024;
		// Reserve mud for multipart/form-data overhead + base64-less binary head.
		return (int) floor( $limit * 0.7 );
	}

	/**
	 * Enqueue assets on plugin pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( false === strpos( $hook, self::SLUG ) && false === strpos( $hook, 'dbb-new' ) && false === strpos( $hook, 'dbb-import' ) ) {
			return;
		}

		wp_enqueue_style( 'dbb-admin', DBB_URL . 'assets/css/admin.css', array(), DBB_VERSION );
		wp_enqueue_script( 'dbb-admin', DBB_URL . 'assets/js/admin.js', array( 'jquery' ), DBB_VERSION, true );

		wp_localize_script(
			'dbb-admin',
			'DBB',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'dbb_ajax' ),
				'homeUrl'   => home_url(),
				/* v1.1.0 — %4'te takılma düzeltmesi: chunk boyutu sunucunun
				   gerçek yükleme limitinden (upload_max_filesize / post_max_size
				   min.) hesap edilir; multipart üst yükü için %75 güvenli pay
				   bırakılır. Sabit 2MB, 2MB sınırlı sunucularda ikinci parçayı
				   reddediyordu → ilerleme %4'te takılıp kalıyordu. */
				'chunkSize' => self::upload_chunk_size(),
				'uploadLimit' => (int) wp_max_upload_size(),
				'zipOk'     => DBB_Archive::available(),
				'i18n'      => array(
					'starting'  => __( 'Başlatılıyor…', 'doktor-brown-backup' ),
					'exporting' => __( 'Yedekleniyor…', 'doktor-brown-backup' ),
					'uploading' => __( 'Yükleniyor…', 'doktor-brown-backup' ),
					'importing' => __( 'Geri yükleniyor…', 'doktor-brown-backup' ),
					'done'      => __( 'Tamamlandı.', 'doktor-brown-backup' ),
					'error'     => __( 'Hata', 'doktor-brown-backup' ),
					'confirmDel' => __( 'Bu yedeği silmek istediğinize emin misiniz?', 'doktor-brown-backup' ),
					'pickFile'   => __( 'Lütfen bir .dbb veya .wpress dosyası seçin.', 'doktor-brown-backup' ),
					'badExt'     => __( 'Desteklenmeyen dosya türü. Yalnızca .dbb ve .wpress uzantılı dosyalar desteklenir.', 'doktor-brown-backup' ),
					'confirmRestore' => __( 'Bu yedeği geri yüklemek mevcut siteyi tamamen değiştirir. Devam edilsin mi?', 'doktor-brown-backup' ),
				),
			)
		);
	}

	/**
	 * Backups list page.
	 *
	 * @return void
	 */
	public static function page_list() {
		$backups = self::get_backups();
		?>
		<div class="wrap dbb-wrap">
			<h1><?php esc_html_e( 'Doktor Brown Backup — Yedekler', 'doktor-brown-backup' ); ?></h1>

			<?php if ( ! DBB_Archive::available() ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Sunucuda php-zip (ZipArchive) eklentisi yok. Yedekleme çalışmaz.', 'doktor-brown-backup' ); ?></p></div>
			<?php endif; ?>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=dbb-new' ) ); ?>"><?php esc_html_e( 'Yeni Yedek Oluştur', 'doktor-brown-backup' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=dbb-import' ) ); ?>"><?php esc_html_e( 'Yedeği Geri Yükle', 'doktor-brown-backup' ); ?></a>
			</p>

			<table class="widefat striped dbb-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Dosya', 'doktor-brown-backup' ); ?></th>
						<th><?php esc_html_e( 'Boyut', 'doktor-brown-backup' ); ?></th>
						<th><?php esc_html_e( 'Tarih', 'doktor-brown-backup' ); ?></th>
						<th><?php esc_html_e( 'İçerik', 'doktor-brown-backup' ); ?></th>
						<th><?php esc_html_e( 'İşlem', 'doktor-brown-backup' ); ?></th>
					</tr>
				</thead>
				<tbody id="dbb-list-body">
					<?php if ( empty( $backups ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Henüz yedek yok.', 'doktor-brown-backup' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $backups as $b ) : ?>
							<tr data-file="<?php echo esc_attr( $b['file'] ); ?>">
								<td><code><?php echo esc_html( $b['file'] ); ?></code></td>
								<td><?php echo esc_html( size_format( $b['size'] ) ); ?></td>
								<td><?php echo esc_html( wp_date( 'd.m.Y H:i', $b['created'] ) ); ?></td>
								<td><?php echo esc_html( self::describe( $b ) ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( self::download_url( $b['file'] ) ); ?>"><?php esc_html_e( 'İndir', 'doktor-brown-backup' ); ?></a>
									<button type="button" class="button button-small dbb-restore" data-file="<?php echo esc_attr( $b['file'] ); ?>"><?php esc_html_e( 'Geri Yükle', 'doktor-brown-backup' ); ?></button>
									<button type="button" class="button button-small dbb-delete" data-file="<?php echo esc_attr( $b['file'] ); ?>"><?php esc_html_e( 'Sil', 'doktor-brown-backup' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Yedekler wp-content/dbb-backups içinde tutulur ve web\'den erişime kapalıdır.', 'doktor-brown-backup' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Export page.
	 *
	 * @return void
	 */
	public static function page_export() {
		?>
		<div class="wrap dbb-wrap">
			<h1><?php esc_html_e( 'Yeni Yedek Oluştur', 'doktor-brown-backup' ); ?></h1>
			<p><?php esc_html_e( 'Bu işlem dosyaları ve veritabanını tek bir .dbb arşivine paketler. Parçalı çalışır; büyük sitelerde zaman aşımı olmaz.', 'doktor-brown-backup' ); ?></p>
			<p><button type="button" class="button button-primary button-hero" id="dbb-export-start"><?php esc_html_e( 'Yedeği Başlat', 'doktor-brown-backup' ); ?></button></p>

			<div id="dbb-export-progress" class="dbb-progress" hidden>
				<div class="dbb-bar"><span></span></div>
				<p class="dbb-status"></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Import page.
	 *
	 * @return void
	 */
	public static function page_import() {
		?>
		<div class="wrap dbb-wrap">
			<h1><?php esc_html_e( 'Yedeği Geri Yükle', 'doktor-brown-backup' ); ?></h1>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Dikkat: Geri yükleme mevcut dosya ve veritabanının üzerine yazar. Önce mevcut sitenin yedeğini alın.', 'doktor-brown-backup' ); ?></p></div>

			<table class="form-table">
				<tr>
					<th><label for="dbb-file"><?php esc_html_e( 'Yedek Dosyası', 'doktor-brown-backup' ); ?></label></th>
					<td>
						<div id="dbb-dropzone">
							<div class="dbb-dropzone-inner">
								<span class="dbb-dropzone-icon" aria-hidden="true">⬆</span>
								<p class="dbb-dropzone-title"><?php esc_html_e( 'Dosyayı buraya sürükleyip bırakın', 'doktor-brown-backup' ); ?></p>
								<p class="dbb-dropzone-sub"><?php esc_html_e( 'veya seçmek için tıklayın', 'doktor-brown-backup' ); ?></p>
							</div>
							<input type="file" id="dbb-file" accept=".dbb,.wpress" />
						</div>
						<p class="description" id="dbb-file-name"></p>
						<p class="description"><?php esc_html_e( 'Yalnızca .dbb ve .wpress uzantılı dosyalar desteklenir.', 'doktor-brown-backup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="dbb-target"><?php esc_html_e( 'Yeni Site Adresi (opsiyonel)', 'doktor-brown-backup' ); ?></label></th>
					<td>
						<input type="url" id="dbb-target" class="regular-text" placeholder="<?php echo esc_attr( home_url() ); ?>" />
						<p class="description"><?php esc_html_e( 'Boş bırakılırsa bu sitenin adresi kullanılır.', 'doktor-brown-backup' ); ?> <code><?php echo esc_html( home_url() ); ?></code></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Alan Adı', 'doktor-brown-backup' ); ?></th>
					<td>
						<label><input type="checkbox" id="dbb-auto-domain" checked /> <?php esc_html_e( 'Eski site adresini otomatik bul ve yeni adresle değiştir', 'doktor-brown-backup' ); ?></label>
						<p class="description"><?php esc_html_e( 'Ör. localhost/doga → www.dogateknoloji.com.tr. Serileştirilmiş veriler korunarak değiştirilir.', 'doktor-brown-backup' ); ?></p>
					</td>
				</tr>
			</table>

			<p><button type="button" class="button button-primary button-hero" id="dbb-import-start"><?php esc_html_e( 'Geri Yüklemeyi Başlat', 'doktor-brown-backup' ); ?></button></p>

			<div id="dbb-import-progress" class="dbb-progress" hidden>
				<div class="dbb-bar"><span></span></div>
				<p class="dbb-status"></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Download URL (admin-post + nonce).
	 *
	 * @param string $file Backup filename.
	 * @return string
	 */
	public static function download_url( $file ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=dbb_download&file=' . rawurlencode( $file ) ),
			'dbb_download'
		);
	}

	/**
	 * Stream a backup file.
	 *
	 * @return void
	 */
	public static function handle_download() {
		if ( ! current_user_can( DBB_Plugin::CAPABILITY ) || ! check_admin_referer( 'dbb_download' ) ) {
			wp_die( esc_html__( 'Yetkisiz.', 'doktor-brown-backup' ) );
		}

		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$path = DBB_BACKUPS_DIR . '/' . $file;

		if ( '' === $file || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Dosya bulunamadı.', 'doktor-brown-backup' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore
		exit;
	}

	/**
	 * One-line description of a backup for the list table.
	 *
	 * @param array $b Backup meta.
	 * @return string
	 */
	public static function describe( $b ) {
		$type = isset( $b['type'] ) ? $b['type'] : 'dbb';
		if ( 'wpress' === $type ) {
			$label = __( 'wpress arşivi', 'doktor-brown-backup' );
			if ( ! empty( $b['site_url'] ) ) {
				$label .= ' · ' . $b['site_url'];
			}
			return $label;
		}
		return sprintf(
			/* translators: 1: files, 2: tables */
			__( '%1$s dosya · %2$s tablo', 'doktor-brown-backup' ),
			number_format_i18n( $b['files'] ),
			number_format_i18n( $b['tables'] )
		);
	}

	/**
	 * List of backups (index + disk).
	 *
	 * @return array
	 */
	public static function get_backups() {
		$index = self::index_get();
		$out   = array();

		foreach ( $index as $file => $meta ) {
			$path = DBB_BACKUPS_DIR . '/' . $file;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$out[ $file ] = array(
				'file'     => $file,
				'size'     => isset( $meta['size'] ) ? (int) $meta['size'] : (int) filesize( $path ),
				'created'  => isset( $meta['created'] ) ? (int) $meta['created'] : filemtime( $path ),
				'files'    => isset( $meta['files'] ) ? (int) $meta['files'] : 0,
				'tables'   => isset( $meta['tables'] ) ? (int) $meta['tables'] : 0,
				'type'     => 'dbb',
				'site_url' => isset( $meta['site_url'] ) ? $meta['site_url'] : '',
			);
		}

		// Also pick up .dbb and .wpress files not in the index.
		$patterns = array(
			DBB_BACKUPS_DIR . '/*.' . DBB_Archive::EXTENSION,
			DBB_BACKUPS_DIR . '/*.wpress',
		);
		foreach ( $patterns as $pattern ) {
			foreach ( (array) glob( $pattern ) as $path ) {
				$file = basename( $path );
				if ( isset( $out[ $file ] ) ) {
					continue;
				}
				$type  = ( 'wpress' === strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) ? 'wpress' : 'dbb';
				$entry = array(
					'file'     => $file,
					'size'     => (int) filesize( $path ),
					'created'  => (int) filemtime( $path ),
					'files'    => 0,
					'tables'   => 0,
					'type'     => $type,
					'site_url' => '',
				);
				if ( 'wpress' === $type ) {
					$pkg = DBB_Wpress::read_package( $path );
					if ( ! is_wp_error( $pkg ) ) {
						$entry['site_url'] = isset( $pkg['SiteURL'] ) ? $pkg['SiteURL'] : '';
					}
				}
				$out[ $file ] = $entry;
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $b['created'] <=> $a['created'];
			}
		);

		return $out;
	}

	/**
	 * Read the index option.
	 *
	 * @return array
	 */
	public static function index_get() {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}

	/**
	 * Add/replace an index entry.
	 *
	 * @param string $file Filename.
	 * @param array  $meta Meta.
	 * @return void
	 */
	public static function index_add( $file, $meta ) {
		$index          = self::index_get();
		$index[ $file ] = $meta;
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Remove an index entry.
	 *
	 * @param string $file Filename.
	 * @return void
	 */
	public static function index_remove( $file ) {
		$index = self::index_get();
		if ( isset( $index[ $file ] ) ) {
			unset( $index[ $file ] );
			update_option( self::INDEX_OPTION, $index, false );
		}
	}

	/**
	 * AJAX: list backups.
	 *
	 * @return void
	 */
	public static function ajax_list() {
		DBB_Plugin::verify_request();
		wp_send_json_success( array( 'backups' => array_values( self::get_backups() ) ) );
	}

	/**
	 * AJAX: delete a backup.
	 *
	 * @return void
	 */
	public static function ajax_delete() {
		DBB_Plugin::verify_request();

		$file = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
		$path = DBB_BACKUPS_DIR . '/' . $file;

		if ( '' === $file || ! is_file( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'Dosya bulunamadı.', 'doktor-brown-backup' ) ), 404 );
		}

		@unlink( $path ); // phpcs:ignore
		self::index_remove( $file );

		wp_send_json_success( array( 'message' => __( 'Silindi.', 'doktor-brown-backup' ) ) );
	}
}
