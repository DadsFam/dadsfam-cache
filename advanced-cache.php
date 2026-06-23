<?php
/**
 * DadsFam Cache — advanced-cache.php drop-in.
 *
 * Serves cached pages before WordPress boots. Installed automatically by the
 * DadsFam Cache plugin. Marker: DFC_DROPIN (do not remove — the plugin uses it
 * to recognize its own drop-in). Safe to delete if the plugin is gone.
 *
 * @package DadsFam_Cache
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'DFC_DROPIN' ) ) {
	return;
}
define( 'DFC_DROPIN', '1.0.0' );

dfc_dropin_serve();

/**
 * Try to serve the current request straight from disk. Returns silently on any
 * condition that requires WordPress to handle the request instead.
 *
 * @return void
 */
function dfc_dropin_serve() {
	// Never interfere with CLI, cron, or anything that is not a plain GET.
	if ( 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
	if ( 'GET' !== $method ) {
		return;
	}
	if ( ! defined( 'WP_CONTENT_DIR' ) ) {
		return;
	}

	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
	$host = preg_replace( '/:\d+$/', '', $host );
	$host = preg_replace( '/[^a-z0-9\.\-]/', '', (string) $host );
	if ( '' === $host ) {
		return;
	}

	$root        = WP_CONTENT_DIR . '/cache/dadsfam-cache';
	$config_file = $root . '/config/' . $host . '.php';
	if ( ! is_readable( $config_file ) ) {
		return; // Plugin not configured for this host — do nothing.
	}
	$cfg = include $config_file;
	if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) ) {
		return;
	}

	// ---- Path ----
	$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
	$parts = explode( '?', $uri, 2 );
	$path  = rawurldecode( $parts[0] );
	if ( strlen( $path ) > 800 || false !== strpos( $path, '..' ) || false !== strpos( $path, "\0" ) ) {
		return;
	}
	$path = preg_replace( '#/+#', '/', $path );

	// Hard never-cache list (WordPress internals).
	if ( preg_match( '#(wp\-admin|wp\-login\.php|wp\-cron\.php|xmlrpc\.php|wp\-json|\.well\-known|robots\.txt|favicon\.ico|sitemap[^/]*\.xml|/feed(/|$))#i', $path ) ) {
		return;
	}

	// User exclusions (pre-compiled regexes from the plugin config).
	if ( ! empty( $cfg['exclude_uris'] ) ) {
		foreach ( (array) $cfg['exclude_uris'] as $regex ) {
			if ( is_string( $regex ) && '' !== $regex && @preg_match( $regex, $path ) ) {
				return;
			}
		}
	}

	// ---- Query strings: only marketing params may ride on a cached page ----
	if ( isset( $parts[1] ) && '' !== $parts[1] ) {
		parse_str( $parts[1], $query );
		$ignore = isset( $cfg['ignore_params'] ) ? (array) $cfg['ignore_params'] : array();
		foreach ( array_keys( $query ) as $key ) {
			if ( ! in_array( strtolower( (string) $key ), $ignore, true ) ) {
				return;
			}
		}
	}

	// ---- Cookies: logged-in users, carts, commenters bypass the cache ----
	$prefixes = isset( $cfg['exclude_cookies'] ) ? (array) $cfg['exclude_cookies'] : array();
	foreach ( array_keys( (array) $_COOKIE ) as $name ) {
		foreach ( $prefixes as $prefix ) {
			if ( '' !== $prefix && 0 === stripos( (string) $name, (string) $prefix ) ) {
				return;
			}
		}
	}

	// ---- User agent exclusions ----
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
	if ( ! empty( $cfg['exclude_agents'] ) && '' !== $ua && @preg_match( (string) $cfg['exclude_agents'], $ua ) ) {
		return;
	}

	// ---- Variant ----
	$https = ( isset( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) )
		|| ( isset( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && false !== stripos( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'], 'https' ) );

	$mobile = false;
	if ( ! empty( $cfg['separate_mobile'] ) && '' !== $ua ) {
		$mobile = (bool) preg_match( '#Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi#i', $ua );
	}

	$file = $root . '/html/' . $host . rtrim( $path, '/' )
		. '/index' . ( $https ? '-https' : '' ) . ( $mobile ? '-mobile' : '' ) . '.html';

	if ( ! is_readable( $file ) ) {
		return; // MISS — WordPress will render and the plugin will cache it.
	}

	$mtime    = (int) @filemtime( $file );
	$lifetime = isset( $cfg['lifetime'] ) ? (int) $cfg['lifetime'] : 0;
	if ( $lifetime > 0 && ( time() - $mtime ) > $lifetime ) {
		return; // Stale — let WordPress regenerate it.
	}

	$protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) && is_string( $_SERVER['SERVER_PROTOCOL'] )
		? $_SERVER['SERVER_PROTOCOL']
		: 'HTTP/1.1';

	// Conditional GET.
	if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
		$since = strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
		if ( $since && $since >= $mtime ) {
			header( $protocol . ' 304 Not Modified', true, 304 );
			header( 'X-DadsFam-Cache: HIT' );
			exit;
		}
	}

	header( 'X-DadsFam-Cache: HIT' );
	header( 'X-DadsFam-Cache-Age: ' . max( 0, time() - $mtime ) );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
	header( 'Content-Type: text/html; charset=UTF-8' );
	header( 'Vary: Accept-Encoding' );

	$gz       = $file . '.gz';
	$encoding = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
	if ( ! empty( $cfg['gzip'] )
		&& false !== stripos( $encoding, 'gzip' )
		&& is_readable( $gz )
		&& ! ini_get( 'zlib.output_compression' )
	) {
		header( 'Content-Encoding: gzip' );
		header( 'Content-Length: ' . (string) (int) filesize( $gz ) );
		readfile( $gz );
	} else {
		header( 'Content-Length: ' . (string) (int) filesize( $file ) );
		readfile( $file );
	}
	exit;
}
