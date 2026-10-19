<?php
/**
 * Core bootstrap: constants, storage dirs, includes, hooks, AJAX routing.
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class DBB_Plugin {

	/** @var DBB_Plugin|null */
	private static $instance = null;

	/** @var DBB_Admin */
	public $admin;

	/** @var DBB_Exporter */
	public $exporter;

	/** @var DBB_Importer */
	public $importer;

	/** @var string */
	const CAPABILITY = 'manage_options';

	/**
	 * Singleton.
	 *
	 * @return DBB_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->define_storage();

		require_once DBB_PATH . 'includes/class-dbb-filesystem.php';
		require_once DBB_PATH . 'includes/class-dbb-db.php';
		require_once DBB_PATH . 'includes/class-dbb-replace.php';
		require_once DBB_PATH . 'includes/class-dbb-archive.php';
		require_once DBB_PATH . 'includes/class-dbb-wpress.php';
		require_once DBB_PATH . 'includes/class-dbb-exporter.php';
		require_once DBB_PATH . 'includes/class-dbb-importer.php';
		require_once DBB_PATH . 'includes/class-dbb-admin.php';

		$this->exporter = new DBB_Exporter();
		$this->importer = new DBB_Importer();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( 'DBB_Admin', 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( 'DBB_Admin', 'enqueue' ) );
		add_action( 'admin_post_dbb_download', array( 'DBB_Admin', 'handle_download' ) );

		// Write a local .htaccess guard the first time we run.
		add_action( 'admin_init', array( __CLASS__, 'ensure_guards' ) );

		$this->register_ajax();

		// WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once DBB_PATH . 'includes/class-dbb-cli.php';
			WP_CLI::add_command( 'dbb', 'DBB_CLI' );
		}
	}

	/**
	 * Storage paths.
	 *
	 * @return void
	 */
	private function define_storage() {
		if ( ! defined( 'DBB_BACKUPS_DIR' ) ) {
			define( 'DBB_BACKUPS_DIR', WP_CONTENT_DIR . '/dbb-backups' );
		}
		if ( ! defined( 'DBB_TMP_DIR' ) ) {
			define( 'DBB_TMP_DIR', WP_CONTENT_DIR . '/dbb-tmp' );
		}
	}

	/**
	 * Translation loading.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'doktor-brown-backup', false, dirname( DBB_BASENAME ) . '/languages' );
	}

	/**
	 * Activation: create directories + guards.
	 *
	 * @return void
	 */
	public static function activate() {
		self::define_storage_public();
		foreach ( array( DBB_BACKUPS_DIR, DBB_TMP_DIR ) as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			self::write_guard( $dir );
		}
	}

	/**
	 * Deactivation: nothing destructive.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Intentionally left non-destructive: backups are kept.
	}

	/**
	 * define_storage() must run in a static context too.
	 *
	 * @return void
	 */
	private static function define_storage_public() {
		if ( ! defined( 'DBB_BACKUPS_DIR' ) ) {
			define( 'DBB_BACKUPS_DIR', WP_CONTENT_DIR . '/dbb-backups' );
		}
		if ( ! defined( 'DBB_TMP_DIR' ) ) {
			define( 'DBB_TMP_DIR', WP_CONTENT_DIR . '/dbb-tmp' );
		}
	}

	/**
	 * Ensure guard files exist (idempotent).
	 *
	 * @return void
	 */
	public static function ensure_guards() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		foreach ( array( DBB_BACKUPS_DIR, DBB_TMP_DIR ) as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				self::write_guard( $dir );
			}
		}
	}

	/**
	 * Write protective .htaccess + index.php into a directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	public static function write_guard( $dir ) {
		$htaccess = "# Doktor Brown Backup - deny direct access\n"
			. "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
		@file_put_contents( $dir . '/.htaccess', $htaccess ); // phpcs:ignore
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
	}

	/**
	 * Register authenticated AJAX endpoints.
	 *
	 * @return void
	 */
	private function register_ajax() {
		$actions = array(
			'dbb_export_start'  => array( $this->exporter, 'ajax_start' ),
			'dbb_export_step'   => array( $this->exporter, 'ajax_step' ),
			'dbb_import_start'  => array( $this->importer, 'ajax_start' ),
			'dbb_import_upload' => array( $this->importer, 'ajax_upload' ),
			'dbb_import_step'   => array( $this->importer, 'ajax_step' ),
			'dbb_restore_backup' => array( $this->importer, 'ajax_restore' ),
			'dbb_delete'        => array( 'DBB_Admin', 'ajax_delete' ),
			'dbb_list'          => array( 'DBB_Admin', 'ajax_list' ),
		);

		foreach ( $actions as $action => $callback ) {
			add_action( 'wp_ajax_' . $action, $callback );
		}
	}

	/**
	 * Verify nonce + capability for AJAX.
	 *
	 * @return void
	 */
	public static function verify_request() {
		if ( ! check_ajax_referer( 'dbb_ajax', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Güvenlik doğrulaması başarısız. Sayfayı yenileyin.', 'doktor-brown-backup' ) ), 403 );
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Yetkiniz yok.', 'doktor-brown-backup' ) ), 403 );
		}
	}
}
