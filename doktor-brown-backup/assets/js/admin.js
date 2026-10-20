/* global jQuery, DBB */
( function ( $ ) {
	'use strict';

	function post( action, data ) {
		return $.post( DBB.ajaxUrl, $.extend( { action: action, nonce: DBB.nonce }, data || {} ) );
	}

	/* ------------------------------------------------------------------ */
	/* Client diagnostics                                                  */
	/* ------------------------------------------------------------------ */
	/* Requests blocked by a proxy/WAF (403/413) or killed by a PHP timeout
	   never reach the plugin, so the browser reports them to the server log. */
	var logQueue = [];
	var logTimer = null;

	function dlog( level, message, ctx ) {
		var entry = {
			level: level,
			message: message,
			url: window.location.href,
			ua: navigator.userAgent,
			time: new Date().toISOString()
		};
		if ( ctx ) {
			$.extend( entry, ctx );
		}
		if ( window.console && console[ level === 'error' ? 'error' : 'log' ] ) {
			console[ level === 'error' ? 'error' : 'log' ]( '[DBB]', message, ctx || '' );
		}
		logQueue.push( entry );
		if ( logQueue.length > 100 ) {
			logQueue = logQueue.slice( -100 );
		}
		if ( ! logTimer ) {
			logTimer = window.setTimeout( flushLogs, 1500 );
		}
	}

	function flushLogs() {
		logTimer = null;
		if ( ! logQueue.length ) { return; }
		var batch = logQueue.splice( 0, 20 );
		post( 'dbb_log_client', { payload: JSON.stringify( { events: batch } ) } )
			.fail( function () { /* never recurse into logging */ } );
		if ( logQueue.length ) {
			logTimer = window.setTimeout( flushLogs, 1500 );
		}
	}

	function xhrInfo( xhr, textStatus ) {
		var body = '';
		try { body = ( xhr && xhr.responseText ) ? xhr.responseText.substring( 0, 400 ) : ''; } catch ( e ) {}
		return {
			http: xhr ? xhr.status : 0,
			textStatus: textStatus || '',
			response: body
		};
	}

	function serverHint( code ) {
		if ( code === 403 ) {
			return 'HTTP 403 — güvenlik duvarı/WAF isteği engelledi.';
		}
		if ( code === 413 ) {
			return 'HTTP 413 — istek gövdesi sunucu sınırını aştı.';
		}
		if ( code === 500 || code === 502 || code === 503 || code === 504 ) {
			return 'HTTP ' + code + ' — PHP/sunucu isteği yarıda kesti (zaman aşımı veya DB hatası).';
		}
		if ( ! code ) {
			return 'Ağ hatası — yanıt alınamadı (bağlantı koptu veya istek zaman aşımına uğradı).';
		}
		return 'HTTP ' + code + '.';
	}

	function setProgress( $box, percent, message ) {
		$box.prop( 'hidden', false ).css( 'display', 'block' );
		$box.find( '.dbb-bar > span' ).css( 'width', Math.max( 0, Math.min( 100, percent ) ) + '%' );
		$box.find( '.dbb-status' ).text( message || '' );
	}

	function overlay() {
		if ( ! $( '#dbb-overlay' ).length ) {
			$( 'body' ).append(
				'<div id="dbb-overlay"><div class="dbb-ov-box">' +
					'<h3>Doktor Brown Backup</h3>' +
					'<div class="dbb-bar"><span></span></div>' +
					'<p class="dbb-status"></p>' +
				'</div></div>'
			);
		}
		return $( '#dbb-overlay' );
	}

	function autoDomain() {
		var $c = $( '#dbb-auto-domain' );
		return $c.length ? $c.is( ':checked' ) : true;
	}

	/* ------------------------------------------------------------------ */
	/* Job processor (shared by upload + restore-from-backup)              */
	/* ------------------------------------------------------------------ */

	function processJob( job, target, auto, $box, $btn, attempt ) {
		attempt = attempt || 0;
		post( 'dbb_import_step', { job: job, target_url: target, auto_domain: auto ? 1 : 0 } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					var msg = ( res && res.data && res.data.message ) || DBB.i18n.error;
					dlog( 'error', 'import step rejected', { job: job, message: msg } );
					setProgress( $box, 0, msg + ' — ' + DBB.i18n.seeLogs );
					if ( $btn ) { $btn.prop( 'disabled', false ); }
					return;
				}
				var d = res.data;
				setProgress( $box, d.progress, d.message );
				if ( d.status === 'done' ) {
					setProgress( $box, 100, DBB.i18n.done );
					if ( $btn ) { $btn.prop( 'disabled', false ); }
					window.setTimeout( function () {
						$( '#dbb-overlay' ).fadeOut( 400 );
					}, 1200 );
					return;
				}
				if ( d.status === 'error' ) {
					if ( $btn ) { $btn.prop( 'disabled', false ); }
					return;
				}
				processJob( job, target, auto, $box, $btn, 0 );
			} )
			.fail( function ( xhr, textStatus ) {
				var info = xhrInfo( xhr, textStatus );
				dlog( 'error', 'import step request failed', $.extend( { job: job, attempt: attempt }, info ) );
				if ( attempt < 5 ) {
					var wait = Math.min( 15000, 1000 * Math.pow( 2, attempt ) );
					setProgress( $box, 0, DBB.i18n.error + ' — ' + DBB.i18n.retry + ' (' + serverHint( info.http ) + ') ' + Math.round( wait / 1000 ) + ' sn' );
					window.setTimeout( function () {
						processJob( job, target, auto, $box, $btn, attempt + 1 );
					}, wait );
					return;
				}
				setProgress( $box, 0, DBB.i18n.error + ' — ' + serverHint( info.http ) + ' ' + DBB.i18n.giveUp );
				if ( $btn ) { $btn.prop( 'disabled', false ); }
			} );
	}

	/* ------------------------------------------------------------------ */
	/* Export                                                              */
	/* ------------------------------------------------------------------ */

	$( '#dbb-export-start' ).on( 'click', function () {
		var $btn = $( this );
		var $box = $( '#dbb-export-progress' );
		$btn.prop( 'disabled', true );
		setProgress( $box, 1, DBB.i18n.starting );

		post( 'dbb_export_start' )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					setProgress( $box, 0, ( res && res.data && res.data.message ) || DBB.i18n.error );
					$btn.prop( 'disabled', false );
					return;
				}
				setProgress( $box, 3, DBB.i18n.exporting + ' (' + res.data.totalFiles + ' dosya)' );
				exportStep( res.data.job );
			} )
			.fail( function () {
				setProgress( $box, 0, DBB.i18n.error );
				$btn.prop( 'disabled', false );
			} );

		function exportStep( job, attempt ) {
			attempt = attempt || 0;
			post( 'dbb_export_step', { job: job } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						var msg = ( res && res.data && res.data.message ) || DBB.i18n.error;
						dlog( 'error', 'export step rejected', { job: job, message: msg } );
						setProgress( $box, 0, msg + ' — ' + DBB.i18n.seeLogs );
						$btn.prop( 'disabled', false );
						return;
					}
					var d = res.data;
					setProgress( $box, d.progress, d.message );
					if ( d.status === 'done' ) {
						setProgress( $box, 100, DBB.i18n.done );
						$btn.prop( 'disabled', false );
						return;
					}
					if ( d.status === 'error' ) {
						$btn.prop( 'disabled', false );
						return;
					}
					exportStep( job, 0 );
				} )
				.fail( function ( xhr, textStatus ) {
					var info = xhrInfo( xhr, textStatus );
					dlog( 'error', 'export step request failed', $.extend( { job: job, attempt: attempt }, info ) );
					if ( attempt < 5 ) {
						var wait = Math.min( 15000, 1000 * Math.pow( 2, attempt ) );
						setProgress( $box, 0, DBB.i18n.error + ' — ' + DBB.i18n.retry + ' (' + serverHint( info.http ) + ')' );
						window.setTimeout( function () { exportStep( job, attempt + 1 ); }, wait );
						return;
					}
					setProgress( $box, 0, DBB.i18n.error + ' — ' + serverHint( info.http ) + ' ' + DBB.i18n.giveUp );
					$btn.prop( 'disabled', false );
				} );
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Import (upload a .dbb / .wpress file)                               */
	/* ------------------------------------------------------------------ */

	/* v1.1.0 — sürükle-bırak alanı: bölgeye bırakılan dosya `#dbb-file`
	   input'ına yazılır, seçilen dosyanın adı gösterilir. */
	var $dropzone = $( '#dbb-dropzone' );
	var $fileInput = $( '#dbb-file' );
	var $fileName = $( '#dbb-file-name' );
	var $importBtn = $( '#dbb-import-start' );

	function setSelectedFile( file ) {
		if ( ! file ) {
			return;
		}
		$fileName.text( file.name + ' (' + formatBytes( file.size ) + ')' );
		$dropzone.addClass( 'has-file' );
	}

	function formatBytes( n ) {
		if ( ! n ) { return '0 B'; }
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = 0;
		while ( n >= 1024 && i < units.length - 1 ) { n /= 1024; i++; }
		return n.toFixed( i ? 1 : 0 ) + ' ' + units[ i ];
	}

	if ( $dropzone.length ) {
		$dropzone.on( 'click', function () { $fileInput.trigger( 'click' ); } );
		$fileInput.on( 'change', function () { setSelectedFile( this.files[ 0 ] ); } );

		[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
			$dropzone.on( ev, function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				$dropzone.addClass( 'dragover' );
			} );
		} );
		[ 'dragleave', 'drop' ].forEach( function ( ev ) {
			$dropzone.on( ev, function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				$dropzone.removeClass( 'dragover' );
			} );
		} );
		$dropzone.on( 'drop', function ( e ) {
			var f = e.originalEvent && e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files[ 0 ];
			if ( f ) {
				// DataTransfer dosyayı doğrudan input'a aktaramaz;
				// yükleme akışının seçmesi için işareti saklarız.
				$fileInput.data( 'dropped', f );
				setSelectedFile( f );
			}
		} );
	}

	$( '#dbb-import-start' ).on( 'click', function () {
		var $btn = $( this );
		var $box = $( '#dbb-import-progress' );
		var file = null;
		if ( $fileInput.data( 'dropped' ) ) {
			file = $fileInput.data( 'dropped' );
		} else if ( $fileInput[ 0 ] && $fileInput[ 0 ].files && $fileInput[ 0 ].files[ 0 ] ) {
			file = $fileInput[ 0 ].files[ 0 ];
		}
		if ( ! file ) {
			window.alert( DBB.i18n.pickFile );
			return;
		}
		// Uzantı denetimi — yalnızca .dbb ve .wpress.
		var ext = ( file.name || '' ).split( '.' ).pop().toLowerCase();
		if ( ext !== 'dbb' && ext !== 'wpress' ) {
			window.alert( DBB.i18n.badExt );
			return;
		}
		if ( file.size > 10 * 1024 * 1024 * 1024 ) {
			window.alert( 'Dosya 10 GB sınırını aşıyor.' );
			return;
		}
		var target = $( '#dbb-target' ).val() || '';
		var auto = autoDomain();
		$btn.prop( 'disabled', true );
		setProgress( $box, 1, DBB.i18n.starting );

		/* v1.2.0 — "kaldığı yerden devam": önce sunucuda yarım kalmış bir
		   yükleme var mı bakarız; varsa kullanıcıya sorarız. Onaylarsa
		   aynı job + `bytes` (sunucudaki gerçek bayt) ile devam ederiz;
		   hayır derse `dbb_import_start` ile temiz başlarız. */
		resumeStart( target, auto, $box, $btn );

		function resumeStart( t, a, $bx, $bn ) {
			post( 'dbb_import_resume' )
				.done( function ( r ) {
					var job = r && r.success && r.data && r.data.job ? r.data.job : null;
					if ( job ) {
						var resumeBytes = ( r.data.bytes || 0 );
						var yuzde = Math.round( 3 + 35 * ( resumeBytes / file.size ) );
						var msg = DBB.i18n.resumeAsk + ' (' + formatBytes( resumeBytes ) + ' / %' + yuzde + ')';
						if ( ! window.confirm( msg ) ) {
							freshStart( t, a, $bx, $bn );
							return;
						}
						if ( resumeBytes >= file.size ) {
							// Dosya tamam ama işlem yarıda (upload bitti, step değil):
							// kaldığı yerden devam — bu durumda processJob yeterli.
							setProgress( $bx, 40, DBB.i18n.importing );
							processJob( job, t, a, $bx, $bn );
							return;
						}
						setProgress( $bx, Math.max( 1, yuzde ), DBB.i18n.resuming + ' %' + yuzde );
						upload( job, resumeBytes, initialChunkSize() );
						return;
					}
					freshStart( t, a, $bx, $bn );
				} )
				.fail( function () {
					// Resume sorgusu başarısız → yine de taze başlat.
					freshStart( t, a, $bx, $bn );
				} );
		}

		function freshStart( t, a, $bx, $bn ) {
			post( 'dbb_import_start' )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						setProgress( $bx, 0, ( res && res.data && res.data.message ) || DBB.i18n.error );
						$bn.prop( 'disabled', false );
						return;
					}
					upload( res.data.job, 0, initialChunkSize() );
				} )
				.fail( function () {
					setProgress( $bx, 0, DBB.i18n.error );
					$bn.prop( 'disabled', false );
				} );
		}

		/* v1.1.0 — %4'te takılma düzeltmesi:
		   1. Parça boyutu sunucu limitinden güvenli payla hesaplanır (admin.php),
		      bir de JS tarafında `uploadLimit` ile tavanlanır.
		   2. Yükleme reddedilirse parça yarıya düşürülüp OTOMATİK yeniden
		      denenir (min 256KB) — sabit 2MB'ın sunucu limitini aştığı
		      servislerde ilerleme donup kalmıyor. */
		function initialChunkSize() {
			var sel = parseInt( $( '#dbb-chunk-size' ).val(), 10 );
			if ( sel > 0 ) {
				return sel;
			}
			var limit = Math.min( DBB.uploadLimit || DBB.chunkSize || ( 2 * 1024 * 1024 ), DBB.maxChunk || ( 5 * 1024 * 1024 ) );
			return Math.floor( ( DBB.chunkSize || limit ) * 0.7 );
		}

		function upload( job, offset, chunk, attempt ) {
			attempt = attempt || 0;
			if ( offset >= file.size ) {
				setProgress( $box, 40, DBB.i18n.importing );
				processJob( job, target, auto, $box, $btn );
				return;
			}
			chunk = Math.max( 256 * 1024, Math.floor( chunk || initialChunkSize() ) );
			var slice = file.slice( offset, Math.min( offset + chunk, file.size ) );
			var fd = new FormData();
			fd.append( 'action', 'dbb_import_upload' );
			fd.append( 'nonce', DBB.nonce );
			fd.append( 'job', job );
			fd.append( 'offset', offset );
			fd.append( 'chunk', slice, 'chunk.bin' );

			$.ajax( { url: DBB.ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false, timeout: 180000 } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						// Sunucu parçayı reddetti → daha küçük parçayla yeniden dene.
						var msg = ( res && res.data && res.data.message ) || DBB.i18n.error;
						dlog( 'error', 'upload chunk rejected', { job: job, offset: offset, chunk: chunk, attempt: attempt, message: msg } );
						if ( chunk > 256 * 1024 ) {
							setProgress( $box, Math.max( 1, ( 3 + Math.round( 35 * ( offset / file.size ) ) ) ), DBB.i18n.uploading + ' — parça küçültülüyor…' );
							upload( job, offset, Math.floor( chunk / 2 ), 0 );
						} else {
							setProgress( $box, 0, msg + ' — ' + DBB.i18n.seeLogs );
							$btn.prop( 'disabled', false );
						}
						return;
					}
					// Sunucu kabul etti. `received` = sunucudaki gerçek bayt
					// sayısı (resume'da offset'ten farklı olabilir).
					var received = res.data && typeof res.data.received === 'number' ? res.data.received : ( offset + slice.size );
					var pct = 3 + Math.round( 35 * ( received / file.size ) );
					setProgress( $box, pct, DBB.i18n.uploading + ' ' + pct + '%' );
					upload( job, received, chunk, 0 );
				} )
				.fail( function ( xhr, textStatus ) {
					// Ağ hatası / WAF / sunucu yanıtı yok.
					var info = xhrInfo( xhr, textStatus );
					dlog( 'error', 'upload chunk failed', $.extend( { job: job, offset: offset, chunk: chunk, attempt: attempt }, info ) );

					var minChunk = 256 * 1024;
					var sizeRejected = info.http === 413 || info.http === 403 || info.http === 400 ||
						/too large|sınır|limit|boyut/i.test( info.response || '' );

					/* Doktor Brown yükleme protokolü: 413/403'te parçayı yarıya
					   indir; diğer hatalarda aynı parçayı 30 kez, artan
					   beklemeyle tekrar dene. Her hatada küçültmek yavaş
					   bağlantıda ilerlemeyi durduruyordu. */
					if ( sizeRejected && chunk > minChunk ) {
						setProgress( $box, Math.max( 1, ( 3 + Math.round( 35 * ( offset / file.size ) ) ) ), DBB.i18n.uploading + ' — parça küçültülüyor…' );
						window.setTimeout( function () {
							upload( job, offset, Math.max( minChunk, Math.floor( chunk / 2 ) ), 0 );
						}, 300 );
						return;
					}

					if ( attempt < 30 ) {
						var wait = Math.min( 30000, ( attempt + 1 ) * 1000 );
						setProgress( $box, Math.max( 1, ( 3 + Math.round( 35 * ( offset / file.size ) ) ) ), DBB.i18n.error + ' — ' + DBB.i18n.retry + ' (' + serverHint( info.http ) + ') ' + Math.round( wait / 1000 ) + ' sn' );
						window.setTimeout( function () {
							upload( job, offset, chunk, attempt + 1 );
						}, wait );
						return;
					}

					setProgress( $box, 0, DBB.i18n.error + ' — ' + serverHint( info.http ) + ' ' + DBB.i18n.giveUp );
					$btn.prop( 'disabled', false );
				} );
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Restore directly from a stored backup (list page)                   */
	/* ------------------------------------------------------------------ */

	$( document ).on( 'click', '.dbb-restore', function () {
		var file = $( this ).data( 'file' );
		if ( ! window.confirm( DBB.i18n.confirmRestore ) ) {
			return;
		}
		$( this ).prop( 'disabled', true );
		var $box = overlay();
		setProgress( $box, 1, DBB.i18n.starting );

		post( 'dbb_restore_backup', { file: file, auto_domain: 1 } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					setProgress( $box, 0, ( res && res.data && res.data.message ) || DBB.i18n.error );
					return;
				}
				processJob( res.data.job, '', true, $box, null );
			} )
			.fail( function () {
				setProgress( $box, 0, DBB.i18n.error );
			} );
	} );

	/* ------------------------------------------------------------------ */
	/* Delete                                                              */
	/* ------------------------------------------------------------------ */

	$( document ).on( 'click', '.dbb-delete', function () {
		var file = $( this ).data( 'file' );
		if ( ! window.confirm( DBB.i18n.confirmDel ) ) {
			return;
		}
		var $row = $( this ).closest( 'tr' );
		post( 'dbb_delete', { file: file } ).done( function ( res ) {
			if ( res && res.success ) {
				$row.fadeOut( 200, function () { $row.remove(); } );
			}
		} );
	} );
} )( jQuery );
