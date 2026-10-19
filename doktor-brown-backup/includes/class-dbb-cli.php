<?php
/**
 * WP-CLI commands: wp dbb export | list | restore | delete.
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI command handler.
 */
class DBB_CLI {

	/**
	 * Create a full backup (files + database) as a .dbb archive.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Optional output filename (without path). Default: backup-<date>.dbb
	 *
	 * ## EXAMPLES
	 *
	 *     wp dbb export
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		$engine = new DBB_Exporter();
		$res    = $engine->start();
		if ( isset( $res['error'] ) ) {
			WP_CLI::error( $res['error'] );
		}

		WP_CLI::log( sprintf( 'Job %s — %d dosya, %d tablo', $res['job'], $res['totalFiles'], $res['tables'] ) );

		$done = false;
		while ( ! $done ) {
			$step = $engine->step( $res['job'] );
			if ( isset( $step['error'] ) ) {
				WP_CLI::error( $step['error'] );
			}
			WP_CLI::log( sprintf( '  %d%% — %s', $step['progress'], $step['message'] ) );
			$done = ( 'done' === $step['status'] );
		}

		$file = isset( $step['file']['file'] ) ? $step['file']['file'] : '';
		WP_CLI::success( 'Yedek oluşturuldu: ' . $file );
	}

	/**
	 * List stored backups.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dbb backups
	 *
	 * @return void
	 */
	public function backups( $args, $assoc_args ) {
		$rows = array();
		foreach ( DBB_Admin::get_backups() as $b ) {
			$rows[] = array(
				'file'    => $b['file'],
				'size'    => size_format( $b['size'] ),
				'type'    => isset( $b['type'] ) ? $b['type'] : 'dbb',
				'created' => gmdate( 'Y-m-d H:i', $b['created'] ),
			);
		}
		if ( empty( $rows ) ) {
			WP_CLI::log( 'Yedek yok.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'file', 'size', 'type', 'created' ) );
	}

	/**
	 * Restore a backup (.dbb or .wpress) from the backups directory.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup filename inside wp-content/dbb-backups.
	 *
	 * [--url=<url>]
	 * : New site URL (default: this site's URL).
	 *
	 * [--no-domain]
	 * : Skip automatic domain replacement.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dbb restore backup-20261018-144809.dbb
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$file = isset( $args[0] ) ? $args[0] : '';
		$url  = isset( $assoc_args['url'] ) ? $assoc_args['url'] : '';
		$auto = ! isset( $assoc_args['no-domain'] );

		$engine = new DBB_Importer();
		$res    = $engine->prepare_from_backup( $file, $url, $auto );
		if ( isset( $res['error'] ) ) {
			WP_CLI::error( $res['error'] );
		}

		WP_CLI::log( 'Restore job: ' . $res['job'] );
		$done = false;
		while ( ! $done ) {
			$step = $engine->step( $res['job'], $url, $auto );
			if ( isset( $step['error'] ) ) {
				WP_CLI::error( $step['error'] );
			}
			WP_CLI::log( sprintf( '  %d%% — %s', $step['progress'], $step['message'] ) );
			$done = ( 'done' === $step['status'] );
		}

		WP_CLI::success( 'Geri yükleme tamamlandı.' );
	}

	/**
	 * Delete a stored backup.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dbb delete backup-20261018-144809.dbb
	 *
	 * @param array $args Positional args.
	 * @return void
	 */
	public function delete( $args ) {
		$file = isset( $args[0] ) ? $args[0] : '';
		$path = DBB_BACKUPS_DIR . '/' . sanitize_file_name( $file );
		if ( '' === $file || ! is_file( $path ) ) {
			WP_CLI::error( 'Dosya bulunamadı.' );
		}
		@unlink( $path ); // phpcs:ignore
		DBB_Admin::index_remove( $file );
		WP_CLI::success( 'Silindi: ' . $file );
	}
}
