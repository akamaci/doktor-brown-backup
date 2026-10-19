<?php
/**
 * Uninstall: remove options and (optionally) stored backups.
 *
 * Backups are only removed when the site owner opted in by defining
 * DBB_REMOVE_DATA_ON_UNINSTALL before deleting the plugin.
 *
 * @package DoktorBrownBackup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'dbb_index' );

if ( defined( 'DBB_REMOVE_DATA_ON_UNINSTALL' ) && DBB_REMOVE_DATA_ON_UNINSTALL ) {
	$dirs = array(
		WP_CONTENT_DIR . '/dbb-backups',
		WP_CONTENT_DIR . '/dbb-tmp',
	);
	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}
