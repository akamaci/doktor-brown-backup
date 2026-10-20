<?php
/**
 * Plugin Name:       Doktor Brown Backup
 * Plugin URI:        https://github.com/akamaci/doktor-brown-backup
 * Description:       Bağımsız WordPress yedekleme eklentisi. Siteyi (dosyalar + veritabanı) tek bir .dbb arşivine aktarır ve geri yükler. Parçalı (chunked) çalışır; telemetri, lisans veya uzak servis içermez.
 * Version:           1.3.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Doktor Brown Backup
 * Author URI:        https://github.com/akamaci/doktor-brown-backup
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       doktor-brown-backup
 * Network:           true
 *
 * @package DoktorBrownBackup
 */

defined( 'ABSPATH' ) || exit;

define( 'DBB_VERSION', '1.3.0' );
define( 'DBB_FILE', __FILE__ );
define( 'DBB_PATH', plugin_dir_path( __FILE__ ) );
define( 'DBB_URL', plugin_dir_url( __FILE__ ) );
define( 'DBB_BASENAME', plugin_basename( __FILE__ ) );

require_once DBB_PATH . 'includes/class-dbb-plugin.php';

/**
 * Boot the plugin.
 *
 * @return DBB_Plugin
 */
function dbb() {
	return DBB_Plugin::instance();
}

dbb();

register_activation_hook( __FILE__, array( 'DBB_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DBB_Plugin', 'deactivate' ) );