/* ============================================================
   DadsFam Cache — admin behaviour (vanilla JS, no jQuery)
   ============================================================ */
( function () {
	'use strict';

	var D = window.dfcData || { ajaxurl: '', nonce: '', isPro: false, buyUrl: '#', strings: {} };
	var S = D.strings || {};

	function $( sel, ctx ) { return ( ctx || document ).querySelector( sel ); }
	function $all( sel, ctx ) { return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) ); }

	/* ---------- AJAX ---------- */
	function dfcPost( task, extra ) {
		var body = new FormData();
		body.append( 'action', 'dfc_admin' );
		body.append( 'nonce', D.nonce );
		body.append( 'task', task );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) { body.append( k, extra[ k ] ); } );
		}
		return fetch( D.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.catch( function () { return { success: false, data: { message: S.error } }; } );
	}

	/* ---------- Toast ---------- */
	var toastTimer = null;
	function toast( msg, ok ) {
		var t = $( '#dfc-toast' );
		if ( ! t ) { return; }
		t.textContent = msg;
		t.className = 'dfc-toast dfc-toast-show ' + ( ok === false ? 'dfc-toast-err' : 'dfc-toast-ok' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () { t.className = 'dfc-toast'; }, 4000 );
	}

	/* ---------- Inline result box ---------- */
	function result( sel, msg, ok, listItems ) {
		var box = $( sel );
		if ( ! box ) { return; }
		box.hidden = false;
		box.className = 'dfc-result ' + ( ok === false ? 'dfc-result-err' : 'dfc-result-ok' );
		var html = msg ? '<span>' + escapeHtml( msg ) + '</span>' : '';
		if ( listItems && listItems.length ) {
			html += '<ul>' + listItems.map( function ( i ) { return '<li>' + escapeHtml( i ) + '</li>'; } ).join( '' ) + '</ul>';
		}
		box.innerHTML = html;
	}

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	/* ---------- Button busy helper ---------- */
	function busy( btn, on ) {
		if ( ! btn ) { return; }
		if ( on ) {
			btn.dataset.label = btn.innerHTML;
			btn.classList.add( 'is-busy' );
			btn.disabled = true;
			btn.innerHTML = '⏳ ' + ( S.working || 'Working…' );
		} else {
			btn.classList.remove( 'is-busy' );
			btn.disabled = false;
			if ( btn.dataset.label ) { btn.innerHTML = btn.dataset.label; }
		}
	}

	/* ---------- Tabs ---------- */
	function showTab( slug ) {
		var found = false;
		$all( '.dfc-nav-item' ).forEach( function ( b ) {
			var on = b.getAttribute( 'data-tab' ) === slug;
			b.classList.toggle( 'dfc-nav-active', on );
			if ( on ) { found = true; }
		} );
		if ( ! found ) { return; }
		$all( '.dfc-panel' ).forEach( function ( p ) {
			p.classList.toggle( 'dfc-active', p.getAttribute( 'data-panel' ) === slug );
		} );
		var field = $( '#dfc-tab-field' );
		if ( field ) { field.value = slug; }
		try {
			var url = new URL( window.location.href );
			url.searchParams.set( 'tab', slug );
			window.history.replaceState( {}, '', url );
		} catch ( e ) {
			try { window.localStorage.setItem( 'dfcTab', slug ); } catch ( e2 ) {}
		}
		if ( slug === 'database' ) { loadDbCounts(); }
		if ( slug === 'images' ) { loadWebpCaps(); }
	}

	function bindTabs() {
		$all( '.dfc-nav-item' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { showTab( b.getAttribute( 'data-tab' ) ); } );
		} );
		$all( '[data-goto]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				closeModal();
				showTab( b.getAttribute( 'data-goto' ) );
				window.scrollTo( { top: 0, behavior: 'smooth' } );
			} );
		} );
	}

	/* ---------- Dashboard stats ---------- */
	function applyStats( stats ) {
		if ( ! stats ) { return; }
		var p = $( '#dfc-stat-pages' ), s = $( '#dfc-stat-size' ), u = $( '#dfc-stat-purge' );
		if ( p && typeof stats.pages !== 'undefined' ) { p.textContent = Number( stats.pages ).toLocaleString(); }
		if ( s && stats.human ) { s.textContent = stats.human; }
		if ( u ) { u.textContent = 'just now'; }
	}

	/* ---------- Speed setup ---------- */
	function bindSpeedSetup() {
		var btn = $( '#dfc-speed-setup' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			busy( btn, true );
			dfcPost( 'speed_setup' ).then( function ( r ) {
				busy( btn, false );
				if ( r && r.success ) {
					result( '#dfc-engine-result', r.data.message, true, r.data.done || [] );
					toast( r.data.message, true );
					setTimeout( function () { window.location.reload(); }, 1400 );
				} else {
					toast( ( r.data && r.data.message ) || S.error, false );
				}
			} );
		} );
	}

	/* ---------- Quick actions ---------- */
	function bindQuickActions() {
		var purge = $( '#dfc-purge-all' );
		if ( purge ) {
			purge.addEventListener( 'click', function () {
				if ( ! window.confirm( S.confirmPurge ) ) { return; }
				busy( purge, true );
				dfcPost( 'purge_all' ).then( function ( r ) {
					busy( purge, false );
					if ( r && r.success ) {
						applyStats( r.data.stats );
						result( '#dfc-dash-result', r.data.message, true );
						toast( r.data.message, true );
					} else { toast( S.error, false ); }
				} );
			} );
		}

		var test = $( '#dfc-cache-test' );
		if ( test ) {
			test.addEventListener( 'click', function () {
				busy( test, true );
				result( '#dfc-dash-result', S.working, true );
				dfcPost( 'cache_test' ).then( function ( r ) {
					busy( test, false );
					var ok = r && r.success && r.data.ok;
					var msg = ( r && r.data && r.data.message ) || S.error;
					result( '#dfc-dash-result', msg, ok );
				} );
			} );
		}
	}

	/* ---------- Drop-in install (dashboard checklist) ---------- */
	function bindInstallDropin() {
		var btn = $( '#dfc-install-dropin' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () { installDropin( btn, false ); } );
	}
	function installDropin( btn, force ) {
		busy( btn, true );
		dfcPost( 'install_dropin', force ? { force: 1 } : {} ).then( function ( r ) {
			busy( btn, false );
			if ( r && r.success ) {
				toast( r.data.message, true );
				setTimeout( function () { window.location.reload(); }, 900 );
			} else if ( r && r.data && r.data.can_force ) {
				if ( window.confirm( r.data.message + '\n\nReplace it with the DadsFam Cache engine?' ) ) {
					installDropin( btn, true );
				}
			} else {
				toast( ( r.data && r.data.message ) || S.error, false );
			}
		} );
	}

	/* ---------- Preload ---------- */
	var preloadPoll = null;
	function renderPreload( st ) {
		if ( ! st ) { return; }
		var wrap = $( '#dfc-preload-progress' ), bar = $( '#dfc-preload-bar' ), txt = $( '#dfc-preload-text' );
		if ( ! wrap ) { return; }
		var total = st.total || 0, done = st.done || 0;
		var pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : ( st.running ? 5 : 0 );
		if ( st.running || total > 0 ) {
			wrap.hidden = false;
			if ( bar ) { bar.style.width = pct + '%'; }
			if ( txt ) {
				txt.textContent = st.running
					? '🔥 ' + done + ' / ' + total + ' pages warmed (' + pct + '%)'
					: '✅ Done — ' + done + ' / ' + total + ' pages cached.';
			}
		}
		if ( ! st.running ) { stopPolling(); }
	}
	function startPolling() {
		stopPolling();
		preloadPoll = setInterval( function () {
			dfcPost( 'preload_status' ).then( function ( r ) {
				if ( r && r.success ) { renderPreload( r.data.status ); }
			} );
		}, 2500 );
	}
	function stopPolling() { if ( preloadPoll ) { clearInterval( preloadPoll ); preloadPoll = null; } }

	function bindPreload() {
		var start = $( '#dfc-preload-start' ), stop = $( '#dfc-preload-stop' );
		if ( start ) {
			start.addEventListener( 'click', function () {
				busy( start, true );
				dfcPost( 'preload_start' ).then( function ( r ) {
					busy( start, false );
					if ( r && r.success ) {
						result( '#dfc-preload-result', r.data.message, true );
						toast( r.data.message, true );
						renderPreload( r.data.status );
						startPolling();
					} else {
						result( '#dfc-preload-result', ( r.data && r.data.message ) || S.error, false );
					}
				} );
			} );
		}
		if ( stop ) {
			stop.addEventListener( 'click', function () {
				dfcPost( 'preload_stop' ).then( function ( r ) {
					stopPolling();
					toast( ( r.data && r.data.message ) || 'Stopped.', true );
					var txt = $( '#dfc-preload-text' );
					if ( txt ) { txt.textContent = 'Stopped.'; }
				} );
			} );
		}
		// Reflect an already-running preload on load.
		dfcPost( 'preload_status' ).then( function ( r ) {
			if ( r && r.success && r.data.status && r.data.status.running ) {
				renderPreload( r.data.status );
				startPolling();
			}
		} );
	}

	/* ---------- License ---------- */
	function bindLicense() {
		var act = $( '#dfc-license-activate' ), deact = $( '#dfc-license-deactivate' );
		if ( act ) {
			act.addEventListener( 'click', function () {
				var key = ( $( '#dfc-license-key' ) || {} ).value || '';
				key = key.trim();
				if ( ! key ) { result( '#dfc-license-result', 'Pop your license key in first.', false ); return; }
				busy( act, true );
				dfcPost( 'license_activate', { key: key } ).then( function ( r ) {
					busy( act, false );
					if ( r && r.success ) {
						result( '#dfc-license-result', r.data.message, true );
						toast( 'PRO unlocked! 🎉', true );
						setTimeout( function () { window.location.reload(); }, 1100 );
					} else {
						result( '#dfc-license-result', ( r.data && r.data.message ) || S.error, false );
					}
				} );
			} );
		}
		if ( deact ) {
			deact.addEventListener( 'click', function () {
				if ( ! window.confirm( 'Remove the license from this site?' ) ) { return; }
				busy( deact, true );
				dfcPost( 'license_deactivate' ).then( function ( r ) {
					busy( deact, false );
					toast( ( r.data && r.data.message ) || 'Removed.', true );
					setTimeout( function () { window.location.reload(); }, 900 );
				} );
			} );
		}
	}

	/* ---------- Database ---------- */
	var dbLoaded = false;
	function loadDbCounts( force ) {
		if ( dbLoaded && ! force ) { return; }
		dbLoaded = true;
		dfcPost( 'db_counts' ).then( function ( r ) {
			if ( r && r.success ) { applyCounts( r.data.counts ); }
		} );
	}
	function applyCounts( counts ) {
		if ( ! counts ) { return; }
		$all( '.dfc-db-count' ).forEach( function ( td ) {
			var job = td.getAttribute( 'data-count' );
			td.textContent = typeof counts[ job ] !== 'undefined' ? Number( counts[ job ] ).toLocaleString() : '0';
		} );
	}
	function bindDatabase() {
		var refresh = $( '#dfc-db-refresh' );
		if ( refresh ) {
			refresh.addEventListener( 'click', function () {
				busy( refresh, true );
				dfcPost( 'db_counts' ).then( function ( r ) {
					busy( refresh, false );
					if ( r && r.success ) { applyCounts( r.data.counts ); toast( 'Counts refreshed.', true ); }
				} );
			} );
		}
		$all( '.dfc-db-clean' ).forEach( function ( btn ) {
			if ( btn.disabled ) { return; }
			btn.addEventListener( 'click', function () {
				if ( ! window.confirm( S.confirmClean ) ) { return; }
				busy( btn, true );
				dfcPost( 'db_clean', { job: btn.getAttribute( 'data-job' ) } ).then( function ( r ) {
					busy( btn, false );
					if ( r && r.success ) {
						applyCounts( r.data.counts );
						result( '#dfc-db-result', r.data.message, true );
						toast( r.data.message, true );
					} else {
						result( '#dfc-db-result', ( r.data && r.data.message ) || S.error, false );
					}
				} );
			} );
		} );
	}

	/* ---------- Tools: export / import / maintenance ---------- */
	function bindTools() {
		var exp = $( '#dfc-export' );
		if ( exp ) {
			exp.addEventListener( 'click', function () {
				busy( exp, true );
				dfcPost( 'export_settings' ).then( function ( r ) {
					busy( exp, false );
					if ( r && r.success && r.data.json ) {
						var blob = new Blob( [ r.data.json ], { type: 'application/json' } );
						var a = document.createElement( 'a' );
						a.href = URL.createObjectURL( blob );
						a.download = 'dadsfam-cache-settings.json';
						document.body.appendChild( a );
						a.click();
						document.body.removeChild( a );
						URL.revokeObjectURL( a.href );
						result( '#dfc-tools-result', 'Settings exported. Check your downloads.', true );
					} else { result( '#dfc-tools-result', S.error, false ); }
				} );
			} );
		}

		var file = $( '#dfc-import-file' );
		if ( file ) {
			file.addEventListener( 'change', function () {
				var f = file.files && file.files[0];
				if ( ! f ) { return; }
				if ( ! window.confirm( S.confirmImport ) ) { file.value = ''; return; }
				var reader = new FileReader();
				reader.onload = function () {
					dfcPost( 'import_settings', { json: String( reader.result ) } ).then( function ( r ) {
						if ( r && r.success ) {
							result( '#dfc-tools-result', r.data.message, true );
							toast( r.data.message, true );
							setTimeout( function () { window.location.reload(); }, 1100 );
						} else {
							result( '#dfc-tools-result', ( r.data && r.data.message ) || S.error, false );
						}
					} );
				};
				reader.readAsText( f );
				file.value = '';
			} );
		}

		bindMaint( '#dfc-reinstall-dropin', 'install_dropin', { force: 1 }, true );
		bindMaint( '#dfc-remove-dropin', 'remove_dropin', {}, true );
		bindMaint( '#dfc-remove-htaccess', 'remove_htaccess', {}, false );
	}
	function bindMaint( sel, task, extra, reload ) {
		var btn = $( sel );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			busy( btn, true );
			dfcPost( task, extra ).then( function ( r ) {
				busy( btn, false );
				var ok = r && r.success;
				result( '#dfc-maint-result', ( r.data && r.data.message ) || ( ok ? S.done : S.error ), ok );
				toast( ( r.data && r.data.message ) || ( ok ? S.done : S.error ), ok );
				if ( ok && reload ) { setTimeout( function () { window.location.reload(); }, 900 ); }
			} );
		} );
	}

	/* ---------- Images / WebP ---------- */
	var webpLoaded = false;
	var webpBusy = false;
	function applyWebpCounts( c ) {
		if ( ! c ) { return; }
		var a = $( '#dfc-webp-sources' ), b = $( '#dfc-webp-done' ), d = $( '#dfc-webp-pending' );
		if ( a ) { a.textContent = Number( c.sources || 0 ).toLocaleString(); }
		if ( b ) { b.textContent = Number( c.converted || 0 ).toLocaleString(); }
		if ( d ) { d.textContent = Number( c.pending || 0 ).toLocaleString(); }
	}
	function renderCaps( caps ) {
		var box = $( '#dfc-webp-caps' );
		if ( ! box || ! caps ) { return; }
		box.hidden = false;
		if ( caps.webp ) {
			box.className = 'dfc-result dfc-result-ok';
			box.textContent = '✅ This server can create WebP images (engine: ' + caps.engine + ').' + ( caps.avif ? ' AVIF is supported too.' : '' );
		} else {
			box.className = 'dfc-result dfc-result-err';
			box.textContent = '⚠️ This server has no WebP support (no Imagick or GD WebP). Ask your host to enable it, then come back.';
		}
	}
	function loadWebpCaps( force ) {
		if ( webpLoaded && ! force ) { return; }
		webpLoaded = true;
		dfcPost( 'image_caps' ).then( function ( r ) {
			if ( r && r.success ) { renderCaps( r.data.caps ); applyWebpCounts( r.data.counts ); }
		} );
	}
	function webpProgress( counts ) {
		var wrap = $( '#dfc-webp-progress' ), bar = $( '#dfc-webp-bar' ), txt = $( '#dfc-webp-text' );
		if ( ! wrap || ! counts ) { return; }
		wrap.hidden = false;
		var total = counts.sources || 0, done = counts.converted || 0;
		var pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 100;
		if ( bar ) { bar.style.width = pct + '%'; }
		if ( txt ) { txt.textContent = done + ' / ' + total + ' converted (' + pct + '%)'; }
	}
	function convertLoop( btn ) {
		dfcPost( 'image_convert' ).then( function ( r ) {
			if ( ! r || ! r.success ) {
				webpBusy = false;
				busy( btn, false );
				result( '#dfc-webp-result', ( r && r.data && r.data.message ) || S.error, false );
				return;
			}
			applyWebpCounts( r.data.counts );
			webpProgress( r.data.counts );
			if ( r.data.remaining > 0 ) {
				convertLoop( btn ); // Keep going.
			} else {
				webpBusy = false;
				busy( btn, false );
				result( '#dfc-webp-result', 'All done — your images now have WebP copies. 🚀', true );
				toast( 'WebP conversion complete.', true );
			}
		} );
	}
	function bindWebp() {
		var conv = $( '#dfc-webp-convert' );
		if ( conv && ! conv.disabled ) {
			conv.addEventListener( 'click', function () {
				if ( webpBusy ) { return; }
				webpBusy = true;
				busy( conv, true );
				result( '#dfc-webp-result', S.working, true );
				webpProgress( { sources: 0, converted: 0 } );
				convertLoop( conv );
			} );
		}
		var refresh = $( '#dfc-webp-refresh' );
		if ( refresh ) {
			refresh.addEventListener( 'click', function () {
				busy( refresh, true );
				dfcPost( 'image_caps' ).then( function ( r ) {
					busy( refresh, false );
					if ( r && r.success ) { renderCaps( r.data.caps ); applyWebpCounts( r.data.counts ); toast( 'Counts refreshed.', true ); }
				} );
			} );
		}
		var clear = $( '#dfc-webp-clear' );
		if ( clear && ! clear.disabled ) {
			clear.addEventListener( 'click', function () {
				if ( ! window.confirm( 'Delete all the WebP copies this plugin made? Your original images are kept.' ) ) { return; }
				busy( clear, true );
				dfcPost( 'image_clear' ).then( function ( r ) {
					busy( clear, false );
					if ( r && r.success ) {
						applyWebpCounts( r.data.counts );
						result( '#dfc-webp-result', r.data.message, true );
						var wrap = $( '#dfc-webp-progress' ); if ( wrap ) { wrap.hidden = true; }
					} else { result( '#dfc-webp-result', ( r.data && r.data.message ) || S.error, false ); }
				} );
			} );
		}
	}

	/* ---------- Copy buttons ---------- */
	function bindCopy() {
		$all( '[data-copy]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var target = $( btn.getAttribute( 'data-copy' ) );
				if ( ! target ) { return; }
				var text = target.value || target.textContent || '';
				var done = function () { toast( 'Copied to clipboard.', true ); };
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( done, function () { legacyCopy( target, done ); } );
				} else { legacyCopy( target, done ); }
			} );
		} );
	}
	function legacyCopy( target, done ) {
		try { target.removeAttribute( 'readonly' ); target.select(); document.execCommand( 'copy' ); target.setAttribute( 'readonly', 'readonly' ); done(); } catch ( e ) {}
	}

	/* ---------- Pro modal on locked fields ---------- */
	function openModal() { var m = $( '#dfc-pro-modal' ); if ( m ) { m.hidden = false; } }
	function closeModal() { var m = $( '#dfc-pro-modal' ); if ( m ) { m.hidden = true; } }
	function bindModal() {
		$all( '.dfc-locked' ).forEach( function ( el ) {
			el.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( 'a, [data-goto]' ) ) { return; }
				openModal();
			} );
		} );
		var close = $( '#dfc-modal-close' );
		if ( close ) { close.addEventListener( 'click', closeModal ); }
		var modal = $( '#dfc-pro-modal' );
		if ( modal ) {
			modal.addEventListener( 'click', function ( e ) { if ( e.target === modal ) { closeModal(); } } );
		}
		document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { closeModal(); } } );
	}

	/* ---------- Init ---------- */
	function init() {
		bindTabs();
		bindSpeedSetup();
		bindQuickActions();
		bindInstallDropin();
		bindPreload();
		bindLicense();
		bindDatabase();
		bindTools();
		bindWebp();
		bindCopy();
		bindModal();

		// If the active panel is the database tab on load, fetch counts.
		var active = $( '.dfc-panel.dfc-active' );
		if ( active && active.getAttribute( 'data-panel' ) === 'database' ) { loadDbCounts(); }
		if ( active && active.getAttribute( 'data-panel' ) === 'images' ) { loadWebpCaps(); }
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
