<?php
/**
 * CLI smoke test for Doktor Brown Backup core helpers (no WordPress needed).
 *
 * Usage: php tests/smoke.php
 *
 * @package DoktorBrownBackup
 */

// Bootstrap the plugin classes without WordPress.
define( 'ABSPATH', sys_get_temp_dir() . '/dbb-smoke-' . getmypid() . '/' );
@mkdir( ABSPATH . 'wp-content/uploads', 0777, true );
file_put_contents( ABSPATH . 'wp-config.php', "<?php // test\n" );
file_put_contents( ABSPATH . 'wp-content/uploads/a.jpg', 'aaa' );
file_put_contents( ABSPATH . 'wp-content/uploads/b.jpg', 'bbbb' );
@mkdir( ABSPATH . 'wp-content/cache', 0777, true );
file_put_contents( ABSPATH . 'wp-content/cache/x.tmp', 'x' );

// Minimal stubs the classes may touch.
if ( ! defined( 'DBB_TMP_DIR' ) ) {
	define( 'DBB_TMP_DIR', ABSPATH . 'dbb-tmp' );
}
if ( ! defined( 'DBB_BACKUPS_DIR' ) ) {
	define( 'DBB_BACKUPS_DIR', ABSPATH . 'dbb-backups' );
}

// Minimal WordPress function stubs used by the classes under test.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { // phpcs:ignore
		return $value;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore
		return parse_url( $url, $component );
	}
}

require __DIR__ . '/../doktor-brown-backup/includes/class-dbb-db.php';
require __DIR__ . '/../doktor-brown-backup/includes/class-dbb-filesystem.php';
require __DIR__ . '/../doktor-brown-backup/includes/class-dbb-wpress.php';
require __DIR__ . '/../doktor-brown-backup/includes/class-dbb-replace.php';

$pass = 0;
$fail = 0;

function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "PASS  {$label}\n";
	} else {
		$fail++;
		echo "FAIL  {$label}\n";
	}
}

/* ------------------------------------------------------------------ */
/* SQL splitter                                                        */
/* ------------------------------------------------------------------ */

$sql = "INSERT INTO `wp_options` (`option_name`,`option_value`) VALUES\n"
	. "('a','semi;colon value'),\n"
	. "('b','it''s a quote'),\n"
	. "('c','line1\nline2;still one row');\n"
	. "DROP TABLE IF EXISTS `tmp`;";

$parts = DBB_DB::split_statements( $sql );
check( 'split_statements: 2 statements', 2 === count( $parts ) );
check( 'split_statements: keeps semicolons inside strings', false !== strpos( $parts[0], 'semi;colon value' ) && false !== strpos( $parts[0], "it''s a quote" ) );
check( 'split_statements: second is DROP', 0 === strpos( trim( $parts[1] ), 'DROP TABLE' ) );

/* ------------------------------------------------------------------ */
/* take_complete: incremental buffer                                   */
/* ------------------------------------------------------------------ */

$buffer = "INSERT INTO t VALUES (1,'a;b');\nINSERT INTO t VALUES (2,'incomplet";
list( $done, $rest ) = DBB_DB::take_complete( $buffer );
check( 'take_complete: 1 complete statement', 1 === count( $done ) );
check( 'take_complete: remainder keeps unfinished', false !== strpos( $rest, 'incomplet' ) );

$buffer = $rest . "e');\n";
list( $done2, $rest2 ) = DBB_DB::take_complete( $buffer );
check( 'take_complete: completes after more data', 1 === count( $done2 ) );
check( 'take_complete: empty remainder', '' === trim( $rest2 ) );

/* ------------------------------------------------------------------ */
/* Comments                                                            */
/* ------------------------------------------------------------------ */

list( $c, $r ) = DBB_DB::take_complete( "-- a comment with ; semicolon\nSELECT 1;\n" );
check( 'comments: comment semicolon ignored', 1 === count( $c ) && false !== strpos( $c[0], 'SELECT 1' ) );

/* ------------------------------------------------------------------ */
/* Filesystem                                                          */
/* ------------------------------------------------------------------ */

$files = DBB_Filesystem::list_files();
check( 'list_files: wp-config.php arşive alınmıyor', ! in_array( 'wp-config.php', $files, true ) );
check( 'list_files: finds uploads', in_array( 'wp-content/uploads/a.jpg', $files, true ) );
$has_cache = false;
foreach ( $files as $f ) {
	if ( false !== strpos( $f, 'wp-content/cache' ) ) {
		$has_cache = true;
	}
}
check( 'list_files: excludes cache', ! $has_cache );
check( 'is_excluded: cache true', DBB_Filesystem::is_excluded( 'wp-content/cache/x.tmp' ) );
check( 'is_excluded: uploads false', ! DBB_Filesystem::is_excluded( 'wp-content/uploads/a.jpg' ) );
check( 'is_excluded: wp-config.php true', DBB_Filesystem::is_excluded( 'wp-config.php' ) );
check( 'resolve: blocks traversal', false === DBB_Filesystem::resolve( '../../etc/passwd' ) );
check( 'resolve: normal path', false !== strpos( (string) DBB_Filesystem::resolve( 'wp-config.php' ), 'wp-config.php' ) );

/* ------------------------------------------------------------------ */
/* .wpress header                                                      */
/* ------------------------------------------------------------------ */

$block = pack( 'a255a14a12a4088a8', 'wp-config.php', '1234', '1700000000.5', 'wp-content/uploads', 'deadbeef' );
$h = DBB_Wpress::parse_header( $block );
check( 'wpress: name', 'wp-config.php' === $h['name'] );
check( 'wpress: size', 1234 === $h['size'] );
check( 'wpress: prefix', 'wp-content/uploads' === $h['prefix'] );
check( 'wpress: rel_path', 'wp-content/uploads/wp-config.php' === DBB_Wpress::rel_path( $h ) );

$empty = str_repeat( "\0", DBB_Wpress::HEADER );
$he = DBB_Wpress::parse_header( $empty );
check( 'wpress: empty header => EOF', '' === $he['name'] && 0 === $he['size'] );

/* ------------------------------------------------------------------ */
/* Serialization-safe replace                                          */
/* ------------------------------------------------------------------ */

$old = 'http://localhost/doga';
$new = 'https://www.dogateknoloji.com.tr';
$ser = serialize( array( 'url' => 'http://localhost/doga/wp-content/uploads/x.jpg', 'n' => 5 ) );
$out = DBB_Replace::safe( $ser, $old, $new );
$back = unserialize( $out );
check( 'replace: serialized array decoded', is_array( $back ) );
check( 'replace: url changed', 'https://www.dogateknoloji.com.tr/wp-content/uploads/x.jpg' === $back['url'] );
check( 'replace: serialized length valid', $ser !== $out && strlen( $out ) === strlen( serialize( $back ) ) );

$plain = 'site: http://localhost/doga/page';
check( 'replace: plain string', false !== strpos( DBB_Replace::safe( $plain, $old, $new ), $new ) );
check( 'replace: unchanged when no match', 'nothing here' === DBB_Replace::safe( 'nothing here', $old, $new ) );
$objser = serialize( (object) array( 'u' => $old ) );
check( 'replace: object graphs skipped', $objser === DBB_Replace::safe( $objser, $old, $new ) );

$pairs = DBB_Replace::url_pairs( 'http://localhost/doga', 'https://www.dogateknoloji.com.tr' );
check( 'replace: url_pairs has base', isset( $pairs['http://localhost/doga'] ) );
check( 'replace: url_pairs adds www/https variants', count( $pairs ) >= 2 );

/* ------------------------------------------------------------------ */
/* v1.1.0 — yiyecek kaynak-sözleşmesi (geri alınınca DÜŞER)            */
/* ------------------------------------------------------------------ */

$plugin = __DIR__ . '/../doktor-brown-backup/';
$admin  = file_get_contents( $plugin . 'includes/class-dbb-admin.php' );
$js     = file_get_contents( $plugin . 'assets/js/admin.js' );
$imp    = file_get_contents( $plugin . 'includes/class-dbb-importer.php' );

/* 0) "Eklenti dosyası bulunamadı" (Plugin file does not exist) karşıtı:
   ana dosya + başlık + aktivasyon hook'u doğru olmalı. */
$mainf = $plugin . 'doktor-brown-backup.php';
check( 'activate: ana eklenti dosyası mevcut', is_file( $mainf ) );
$head = file_get_contents( $mainf );
check(
	'activate: Plugin Name başlığı okunabiliyor (WP get_plugins)',
	false !== strpos( $head, 'Plugin Name:       Doktor Brown Backup' )
);
check(
	'activate: Plugin URI gerçek GitHub adresine işaret ediyor (example.com yok)',
	false !== strpos( $head, 'https://github.com/akamaci/doktor-brown-backup' )
			&& false === strpos( $head, 'example.com' )
);
check(
	'activate: başlık sürümü 1.3.0 ile tutarlı',
	false !== strpos( $head, '* Version:           1.3.0' )
			&& false !== strpos( $head, "DBB_VERSION', '1.3.0'" )
);
check(
	'activate: register_activation_hook ana dosyayı kullanıyor',
	false !== strpos( $head, "register_activation_hook( __FILE__, array( 'DBB_Plugin', 'activate' ) )" )
);
check(
	'activate: DBB_Plugin sınıfı require ediliyor',
	false !== strpos( $head, "require_once DBB_PATH . 'includes/class-dbb-plugin.php';" )
);

/* 1) Sürükle-bırak alanı gerçekten düzenlenmiş mi? */
check(
	'ui: geri yükleme sayfasında sürükle-bırak alanı (dbb-dropzone) var',
	false !== strpos( $admin, 'id="dbb-dropzone"' )
);
check(
	'ui: #dbb-file hâlâ dosya girişi olarak mevcut (accessibility/klavye)',
	false !== strpos( $admin, 'type="file" id="dbb-file"' )
);
/* 2) Marka çağrışımlı not kaldırıldı — yalnız uzantı. */
check(
	'ui: not metni markasız (".dbb ve .wpress uzantılı")',
	false !== strpos( $admin, 'Yalnızca .dbb ve .wpress uzantılı dosyalar desteklenir.' )
);
check(
	'ui: eski markalı not tamamen kaldırıldı',
	false === strpos( $admin, 'Desteklenen: .dbb (Doktor Brown)' )
);
/* 3) %4 takılma düzeltmesi: chunk sunucu sınırından hesaplanıyor. */
check(
	'upload: chunkSize wp_max_upload_size tabanlı yardımcıdan geliyor',
	false !== strpos( $admin, 'self::upload_chunk_size()' )
);
check(
	'upload: yardımcı güvenli pay %70 ile sınırıyor',
	false !== strpos( $admin, 'floor( $limit * 0.7 )' )
);
/* 4) JS: hatalarda parça yarıya düşürüp otomatik yeniden deneniyor. */
check(
	'upload: JS reddedilen parçayı küçültüp yeniden deniyor',
	false !== strpos( $js, 'chunk > 256 * 1024' )
			&& false !== strpos( $js, 'Math.floor( chunk / 2 )' )
);
check(
	'upload: JS sürükle-bıraka düşen dosyayı yakalıyor',
	false !== strpos( $js, "'dragover'" )
			&& false !== strpos( $js, 'dataTransfer' )
);
/* 5) Sunucu tarafı: INI_SIZE/limit hatası net JSON hata olarak dönüyor. */
check(
	'upload: PHP parça limit aşımını 413 ile bildiriyor',
	false !== strpos( $imp, 'UPLOAD_ERR_INI_SIZE === $code' )
			&& false !== strpos( $imp, '413' )
);

/* ------------------------------------------------------------------ */
/* v1.2.0 — kaldığı yerden devam (resume) kaynak-sözleşmesi            */
/* ------------------------------------------------------------------ */

$pluginf = file_get_contents( $plugin . 'includes/class-dbb-plugin.php' );

check(
	'resume: dbb_import_resume AJAX eylemi kayıtlı',
	false !== strpos( $pluginf, "'dbb_import_resume' => array( \$this->importer, 'ajax_resume' )" )
);
check(
	'resume: importer ajax_resume tanımlı',
	false !== strpos( $imp, 'public function ajax_resume()' )
			&& false !== strpos( $imp, 'resume_state()' )
);
check(
	'resume: start() yeni işten önce eski yarım işi temizliyor (purge)',
	false !== strpos( $imp, 'purge_upload_jobs()' )
			&& false !== strpos( $imp, 'private function purge_upload_jobs' )
);
check(
	'resume: upload çift-parça bölgesini atlayıp received döndürüyor',
	false !== strpos( $imp, '$offset + $chunk_len <= $disk_ok' )
			&& false !== strpos( $imp, "'received' => \$disk_ok" )
);
check(
	'resume: upload sıra bozulmasını 409 ile reddediyor (boşluk yok)',
	false !== strpos( $imp, '409' )
			&& false !== strpos( $imp, 'Parça sırası bozuk' )
);
check(
	'resume: JS devamı sunucu received değerine göre sürdürüyor',
	false !== strpos( $js, 'res.data.received' )
);
check(
	'resume: JS önce dbb_import_resume çağırıyor (başlat öncesi)',
	false !== strpos( $js, "'dbb_import_resume'" )
			&& false !== strpos( $js, 'DBB.i18n.resumeAsk' )
);
check(
	'resume: i18n resumeAsk + resuming anahtarları ekli',
	false !== strpos( $admin, "'resumeAsk'" )
			&& false !== strpos( $admin, "'resuming'" )
);

/* ------------------------------------------------------------------ */
/* v1.3.0 — tanılama, parçalı DB, güvenlik yedeği                     */
/* ------------------------------------------------------------------ */

$loggerf = file_get_contents( $plugin . 'includes/class-dbb-logger.php' );
$dbf     = file_get_contents( $plugin . 'includes/class-dbb-db.php' );

check(
	'log: DBB_Logger sınıfı ve günlük dosyası var',
	false !== strpos( $loggerf, 'class DBB_Logger' )
			&& false !== strpos( $loggerf, "dbb-' . gmdate( 'Y-m-d' ) . '.log'" )
);
check(
	'log: ortam/limit bilgisi kaydediliyor',
	false !== strpos( $loggerf, "'max_execution_time'" )
			&& false !== strpos( $loggerf, "'post_max_size'" )
			&& false !== strpos( $loggerf, "'max_allowed_packet'" )
);
check(
	'log: fatal/timeout shutdown ile yakalanıyor',
	false !== strpos( $loggerf, 'register_shutdown_function' )
			&& false !== strpos( $loggerf, 'E_ERROR' )
);
check(
	'log: Tanılama sayfası menüye eklendi',
	false !== strpos( $admin, "'dbb-logs'" )
			&& false !== strpos( $admin, 'page_logs' )
);
check(
	'log: istemci olayları sunucuya gönderiliyor',
	false !== strpos( $pluginf, "'dbb_log_client'" )
			&& false !== strpos( $js, "'dbb_log_client'" )
);
check(
	'db: geri yükleme parçalı (restore_batch)',
	false !== strpos( $dbf, 'public static function restore_batch' )
			&& false !== strpos( $imp, 'DBB_DB::restore_batch' )
);
check(
	'db: SQL hatası sessizce yutulmuyor',
	false !== strpos( $dbf, '$wpdb->last_error' )
			&& false !== strpos( $imp, 'Veritabanı geri yüklenirken SQL hatası' )
);
check(
	'db: geri yükleme öncesi güvenlik yedeği adımı var',
	false !== strpos( $imp, "'db-safety'" )
			&& false !== strpos( $imp, 'step_db_safety' )
			&& false !== strpos( $imp, 'pre-restore-' )
);
check(
	'import: wp-config.php asla geri yazılmıyor',
	false !== strpos( $imp, "'wp-config.php' === ltrim" )
);
check(
	'upload: post_max_size aşımı net 413 mesajı veriyor',
	false !== strpos( $imp, 'post_max_size' )
			&& false !== strpos( $imp, 'bytes_from_ini' )
);
check(
	'ui: parça boyutu seçimi (256 KB … 2 MB) var',
	false !== strpos( $admin, 'id="dbb-chunk-size"' )
			&& false !== strpos( $js, "#dbb-chunk-size" )
);
check(
	'js: sonsuz retry yerine sınırlı + geri çekilmeli deneme',
	false !== strpos( $js, 'attempt < 30' )
			&& false !== strpos( $js, '( attempt + 1 ) * 1000' )
);
check(
	'upload: 5 MB parça tavanı (Doktor Brown protokolü)',
	false !== strpos( $admin, 'const MAX_CHUNK = 5242880' )
			&& false !== strpos( $imp, "5 * 1024 * 1024" )
			&& false !== strpos( $js, 'DBB.maxChunk' )
);
check(
	'upload: 413/403 alınca parça yarıya iniyor',
	false !== strpos( $js, 'sizeRejected' )
			&& false !== strpos( $js, 'Math.floor( chunk / 2 )' )
);
check(
	'upload: 10 GB dosya sınırı + disk alanı kontrolü',
	false !== strpos( $js, '10 * 1024 * 1024 * 1024' )
			&& false !== strpos( $imp, 'disk_free_space' )
);

/* ------------------------------------------------------------------ */

echo "\nToplam: {$pass} pass, {$fail} fail\n";
exit( $fail > 0 ? 1 : 0 );
