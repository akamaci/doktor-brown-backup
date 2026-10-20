<?php
/**
 * Logger + environment diagnostics.
 *
 * Writes JSON-lines to wp-content/dbb-logs/dbb-YYYY-MM-DD.log. The directory
 * is web-denied with the same .htaccess guard used for backups. Logging is
 * intentionally dependency-free (no DB writes, no remote calls) so it keeps
 * working when WordPress itself cannot reach the database.
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostics logger.
 */
class DBB_Logger {

	/** @var int Rotate a daily file above this size (bytes). */
	const MAX_BYTES = 5242880;

	/** @var float|null Request start time (microtime). */
	private static $started = null;

	/** @var string Action name for the current request. */
	private static $action = '';

	/** @var bool */
	private static $fatal_registered = false;

	/**
	 * Prepare the log directory and register the request timer.
	 *
	 * @return void
	 */
	public static function init() {
		self::ensure_dir();
		self::$started = microtime( true );

		if ( isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( 0 === strpos( $action, 'dbb_' ) ) {
				self::$action = $action;
				self::register_fatal_handler();
			}
		}
	}

	/**
	 * Create the log dir + guards.
	 *
	 * @return void
	 */
	public static function ensure_dir() {
		if ( ! defined( 'DBB_LOG_DIR' ) || ! DBB_LOG_DIR ) {
			return;
		}
		if ( ! is_dir( DBB_LOG_DIR ) ) {
			wp_mkdir_p( DBB_LOG_DIR );
		}
		if ( ! is_file( DBB_LOG_DIR . '/.htaccess' ) ) {
			$htaccess = "# Doktor Brown Backup logs - deny direct access\n"
				. "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
			@file_put_contents( DBB_LOG_DIR . '/.htaccess', $htaccess ); // phpcs:ignore
			@file_put_contents( DBB_LOG_DIR . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
		}
	}

	/**
	 * Current log file path.
	 *
	 * @return string
	 */
	public static function current_file() {
		return DBB_LOG_DIR . '/dbb-' . gmdate( 'Y-m-d' ) . '.log';
	}

	/**
	 * Write one record.
	 *
	 * @param string $level   info|warn|error|debug.
	 * @param string $message Human message.
	 * @param array  $context Structured context.
	 * @return void
	 */
	public static function log( $level, $message, $context = array() ) {
		if ( ! defined( 'DBB_LOG_DIR' ) || ! DBB_LOG_DIR ) {
			return;
		}
		self::ensure_dir();

		$file = self::current_file();
		if ( is_file( $file ) && filesize( $file ) > self::MAX_BYTES ) {
			@rename( $file, $file . '.1' ); // phpcs:ignore
		}

		$record = array(
			'ts'      => gmdate( 'c' ),
			'level'   => strtoupper( (string) $level ),
			'action'  => self::$action,
			'msg'     => (string) $message,
			'context' => self::redact( is_array( $context ) ? $context : array() ),
		);

		$line = wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $line ) {
			$line = wp_json_encode( array( 'ts' => gmdate( 'c' ), 'level' => 'ERROR', 'msg' => 'log encode failed' ) );
		}

		// Keep only one writer per line; the file is append-only.
		@file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore
	}

	/**
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function info( $message, $context = array() ) {
		self::log( 'info', $message, $context );
	}

	/**
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function warn( $message, $context = array() ) {
		self::log( 'warn', $message, $context );
	}

	/**
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function error( $message, $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Log an exception/throwable with location and truncated stack.
	 *
	 * @param Throwable $e       Error.
	 * @param array     $context Extra context.
	 * @return void
	 */
	public static function exception( $e, $context = array() ) {
		$trace = array();
		foreach ( array_slice( $e->getTrace(), 0, 8 ) as $frame ) {
			$trace[] = ( isset( $frame['file'] ) ? $frame['file'] : '?' )
				. ':' . ( isset( $frame['line'] ) ? $frame['line'] : '?' )
				. ' ' . ( isset( $frame['function'] ) ? $frame['function'] : '?' );
		}
		$context['exception'] = get_class( $e );
		$context['file']      = $e->getFile();
		$context['line']      = $e->getLine();
		$context['trace']     = $trace;
		self::error( $e->getMessage(), $context );
	}

	/**
	 * Log the start of an AJAX request, including server limits.
	 *
	 * @param string $action Action name.
	 * @return void
	 */
	public static function request( $action ) {
		self::$action = (string) $action;
		self::info(
			'request start',
			array(
				'content_length' => isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0,
				'content_type'   => isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '',
				'user_id'        => get_current_user_id(),
				'limits'         => self::limits(),
			)
		);
	}

	/**
	 * Shutdown hook: records fatals and the request duration.
	 *
	 * @return void
	 */
	public static function register_fatal_handler() {
		if ( self::$fatal_registered ) {
			return;
		}
		self::$fatal_registered = true;

		register_shutdown_function(
			function () {
				$err = error_get_last();
				if ( $err && in_array( (int) $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					self::error(
						'FATAL: ' . $err['message'],
						array(
							'file' => $err['file'],
							'line' => $err['line'],
						)
					);
				}
				if ( self::$started ) {
					self::info(
						'request end',
						array(
							'duration_ms' => (int) round( ( microtime( true ) - self::$started ) * 1000 ),
							'peak_mb'     => round( memory_get_peak_usage( true ) / 1048576, 1 ),
						)
					);
				}
			}
		);
	}

	/**
	 * Environment snapshot (logged once per day, shown on the diagnostics page).
	 *
	 * @return array
	 */
	public static function environment() {
		global $wpdb;

		$env = array(
			'plugin_version'      => defined( 'DBB_VERSION' ) ? DBB_VERSION : '',
			'wordpress'           => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '',
			'php'                 => PHP_VERSION,
			'sapi'                => PHP_SAPI,
			'server'              => isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '',
			'os'                  => PHP_OS,
			'abspath'             => ABSPATH,
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'max_input_time'      => ini_get( 'max_input_time' ),
			'max_input_vars'      => ini_get( 'max_input_vars' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'wp_max_upload_size'  => function_exists( 'wp_max_upload_size' ) ? wp_max_upload_size() : 0,
			'ziparchive'          => class_exists( 'ZipArchive' ),
			'multisite'           => is_multisite(),
			'theme'               => function_exists( 'wp_get_theme' ) ? wp_get_theme()->get( 'Name' ) : '',
			'db_server'           => $wpdb ? $wpdb->db_server_info() : '',
			'disk_free_mb'        => function_exists( 'disk_free_space' ) ? (int) round( @disk_free_space( ABSPATH ) / 1048576 ) : -1, // phpcs:ignore
		);

		if ( $wpdb ) {
			$packet = $wpdb->get_var( 'SELECT @@max_allowed_packet' );
			$env['max_allowed_packet'] = $packet ? (int) $packet : 0;
		}

		return $env;
	}

	/**
	 * Runtime limits used by the current request.
	 *
	 * @return array
	 */
	public static function limits() {
		return array(
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'max_input_time'      => ini_get( 'max_input_time' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'content_length'      => isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0,
		);
	}

	/**
	 * Log the environment once per day.
	 *
	 * @return void
	 */
	public static function maybe_log_environment() {
		$stamp = get_option( 'dbb_log_env_stamp', '' );
		$today = gmdate( 'Y-m-d' );
		if ( $stamp === $today ) {
			return;
		}
		update_option( 'dbb_log_env_stamp', $today, false );
		self::info( 'environment', self::environment() );
	}

	/**
	 * Client-reported event (upload failure, WAF response, JS exception).
	 *
	 * @param array $payload Payload.
	 * @return void
	 */
	public static function client( $payload ) {
		$payload = is_array( $payload ) ? $payload : array( 'raw' => (string) $payload );
		self::log( 'CLIENT', isset( $payload['message'] ) ? (string) $payload['message'] : 'client event', $payload );
	}

	/**
	 * Last N lines of the newest log.
	 *
	 * @param int $lines Line count.
	 * @return string
	 */
	public static function tail( $lines = 300 ) {
		$file = self::current_file();
		if ( ! is_file( $file ) ) {
			$candidates = glob( DBB_LOG_DIR . '/dbb-*.log*' );
			if ( ! $candidates ) {
				return '';
			}
			rsort( $candidates );
			$file = $candidates[0];
		}
		$tail = array();
		$fp   = @fopen( $file, 'rb' ); // phpcs:ignore
		if ( ! $fp ) {
			return '';
		}
		fseek( $fp, 0, SEEK_END );
		$size = ftell( $fp );
		$read = min( $size, max( 65536, (int) $lines * 512 ) );
		fseek( $fp, -$read, SEEK_END );
		$data = fread( $fp, $read ); // phpcs:ignore
		fclose( $fp ); // phpcs:ignore

		$tail = explode( "\n", (string) $data );
		if ( count( $tail ) > $lines ) {
			$tail = array_slice( $tail, -$lines );
		}
		return implode( "\n", $tail );
	}

	/**
	 * Redact obvious secrets from context.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	private static function redact( $context ) {
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
				continue;
			}
			if ( preg_match( '/pass|secret|token|nonce|auth|cookie|key/i', (string) $key ) ) {
				$context[ $key ] = '***';
				continue;
			}
			if ( is_string( $value ) && strlen( $value ) > 2000 ) {
				$context[ $key ] = substr( $value, 0, 2000 ) . '…[truncated]';
			}
		}
		return $context;
	}
}
