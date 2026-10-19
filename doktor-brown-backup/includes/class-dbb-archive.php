<?php
/**
 * ZIP archive wrapper (.dbb) using ZipArchive.
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Archive helper.
 */
class DBB_Archive {

	/** @var string */
	const MANIFEST = 'dbb-manifest.json';

	/** @var string */
	const DATABASE = 'database.sql';

	/** @var string */
	const FILES_DIR = 'files/';

	/** @var string */
	const EXTENSION = 'dbb';

	/**
	 * Whether the ZipArchive extension is usable.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Open (or create) an archive.
	 *
	 * @param string $path Archive path.
	 * @return ZipArchive|WP_Error
	 */
	public static function open( $path ) {
		if ( ! self::available() ) {
			return new WP_Error( 'dbb_no_zip', __( 'Sunucuda ZipArchive (php-zip) eklentisi yok.', 'doktor-brown-backup' ) );
		}
		$zip = new ZipArchive();
		$ok  = $zip->open( $path, ZipArchive::CREATE );
		if ( true !== $ok ) {
			return new WP_Error( 'dbb_zip_open', sprintf( __( 'Arşiv açılamadı (kod: %s).', 'doktor-brown-backup' ), (string) $ok ) );
		}
		return $zip;
	}

	/**
	 * Append a local file to the archive.
	 *
	 * @param ZipArchive $zip   Archive.
	 * @param string     $abs   Absolute source path.
	 * @param string     $local Local name inside archive.
	 * @return bool
	 */
	public static function add_file( $zip, $abs, $local ) {
		if ( ! is_file( $abs ) || ! is_readable( $abs ) ) {
			return false;
		}
		return (bool) $zip->addFile( $abs, $local );
	}

	/**
	 * Append in-memory content.
	 *
	 * @param ZipArchive $zip     Archive.
	 * @param string     $local   Local name.
	 * @param string     $content Content.
	 * @return bool
	 */
	public static function add_string( $zip, $local, $content ) {
		return (bool) $zip->addFromString( $local, $content );
	}

	/**
	 * Read the manifest from an archive.
	 *
	 * @param string $path Archive path.
	 * @return array|WP_Error
	 */
	public static function read_manifest( $path ) {
		if ( ! self::available() ) {
			return new WP_Error( 'dbb_no_zip', __( 'Sunucuda ZipArchive yok.', 'doktor-brown-backup' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'dbb_zip_open', __( 'Arşiv açılamadı.', 'doktor-brown-backup' ) );
		}
		$json = $zip->getFromName( self::MANIFEST );
		$zip->close();
		if ( false === $json ) {
			return new WP_Error( 'dbb_no_manifest', __( 'Manifest bulunamadı — geçerli bir .dbb dosyası değil.', 'doktor-brown-backup' ) );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'dbb_bad_manifest', __( 'Manifest bozuk.', 'doktor-brown-backup' ) );
		}
		return $data;
	}

	/**
	 * Extract the whole archive into a directory.
	 *
	 * @param string $path Archive path.
	 * @param string $dest Destination directory.
	 * @return true|WP_Error
	 */
	public static function extract_to( $path, $dest ) {
		if ( ! self::available() ) {
			return new WP_Error( 'dbb_no_zip', __( 'Sunucuda ZipArchive yok.', 'doktor-brown-backup' ) );
		}
		if ( ! file_exists( $dest ) ) {
			wp_mkdir_p( $dest );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'dbb_zip_open', __( 'Arşiv açılamadı.', 'doktor-brown-backup' ) );
		}
		$zip->extractTo( $dest );
		$zip->close();
		return true;
	}
}
