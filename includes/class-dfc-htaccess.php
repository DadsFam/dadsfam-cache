<?php
/**
 * DadsFam Cache — .htaccess browser caching + compression rules.
 *
 * Uses WordPress' insert_with_markers(), so our block is clearly fenced with
 * "# BEGIN DadsFam Cache" / "# END DadsFam Cache" and removes cleanly.
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DFC_Htaccess {

	const MARKER = 'DadsFam Cache';

	public static function is_apache() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';
		return ( false !== strpos( $software, 'apache' ) || false !== strpos( $software, 'litespeed' ) );
	}

	/** The rules we write. Wrapped in IfModule, so they no-op if absent. */
	public static function rules( $browser_cache = true, $gzip = true, $webp = false ) {
		$lines = array();

		// Serve a .webp twin to browsers that accept it, without changing the
		// HTML at all (so it is completely layout-safe and cache-safe).
		if ( $webp ) {
			$lines = array_merge( $lines, array(
				'<IfModule mod_rewrite.c>',
				'RewriteEngine On',
				'RewriteCond %{HTTP_ACCEPT} image/webp',
				'RewriteCond %{REQUEST_FILENAME} (?i)\.(jpe?g|png)$',
				'RewriteCond %{REQUEST_FILENAME}\.webp -f',
				'RewriteRule (?i)(.+)\.(jpe?g|png)$ $1.$2.webp [T=image/webp,E=DFC_WEBP:1,L]',
				'</IfModule>',
				'<IfModule mod_headers.c>',
				'<FilesMatch "(?i)\.(jpe?g|png)$">',
				'Header append Vary Accept',
				'</FilesMatch>',
				'</IfModule>',
			) );
		}

		if ( $browser_cache ) {
			$lines = array_merge( $lines, array(
				'<IfModule mod_expires.c>',
				'ExpiresActive On',
				'ExpiresByType text/css "access plus 1 month"',
				'ExpiresByType application/javascript "access plus 1 month"',
				'ExpiresByType text/javascript "access plus 1 month"',
				'ExpiresByType image/jpeg "access plus 6 months"',
				'ExpiresByType image/png "access plus 6 months"',
				'ExpiresByType image/gif "access plus 6 months"',
				'ExpiresByType image/webp "access plus 6 months"',
				'ExpiresByType image/avif "access plus 6 months"',
				'ExpiresByType image/svg+xml "access plus 6 months"',
				'ExpiresByType image/x-icon "access plus 6 months"',
				'ExpiresByType font/woff2 "access plus 1 year"',
				'ExpiresByType font/woff "access plus 1 year"',
				'ExpiresByType font/ttf "access plus 1 year"',
				'ExpiresByType application/font-woff2 "access plus 1 year"',
				'</IfModule>',
				'<IfModule mod_headers.c>',
				'<FilesMatch "\.(css|js|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf)$">',
				'Header set Cache-Control "public, immutable"',
				'</FilesMatch>',
				'</IfModule>',
			) );
		}

		if ( $gzip ) {
			$lines = array_merge( $lines, array(
				'<IfModule mod_deflate.c>',
				'AddOutputFilterByType DEFLATE text/html text/css text/plain text/xml',
				'AddOutputFilterByType DEFLATE application/javascript text/javascript application/json',
				'AddOutputFilterByType DEFLATE application/rss+xml application/xml image/svg+xml',
				'AddOutputFilterByType DEFLATE font/ttf application/font-woff',
				'</IfModule>',
			) );
		}

		return $lines;
	}

	public static function path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}

	public static function apply() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$lines = self::rules(
			(bool) DFC_Settings::get( 'browser_cache' ),
			(bool) DFC_Settings::get( 'htaccess_gzip' ),
			(bool) DFC_Settings::get( 'serve_webp' )
		);
		if ( ! $lines ) {
			return self::remove();
		}
		return (bool) insert_with_markers( self::path(), self::MARKER, $lines );
	}

	public static function remove() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		return (bool) insert_with_markers( self::path(), self::MARKER, array() );
	}

	public static function status() {
		$file = self::path();
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return array( 'writable' => is_writable( dirname( $file ) ), 'applied' => false );
		}
		$body = (string) file_get_contents( $file );
		return array(
			'writable' => is_writable( $file ),
			'applied'  => false !== strpos( $body, '# BEGIN ' . self::MARKER ),
		);
	}

	/** Equivalent snippet for NGINX users to paste into their server block. */
	public static function nginx_snippet() {
		return implode( "\n", array(
			'# DadsFam Cache — browser caching (add inside your server { } block)',
			'location ~* \.(css|js)$ { expires 1M; add_header Cache-Control "public, immutable"; }',
			'location ~* \.(jpe?g|png|gif|webp|avif|svg|ico)$ { expires 6M; add_header Cache-Control "public, immutable"; }',
			'location ~* \.(woff2?|ttf)$ { expires 1y; add_header Cache-Control "public, immutable"; }',
			'gzip on;',
			'gzip_types text/css application/javascript text/javascript application/json image/svg+xml;',
			'',
			'# DadsFam Cache — serve WebP twins when the browser accepts them',
			'location ~* ^(?<base>.+)\.(jpe?g|png)$ {',
			'    add_header Vary Accept;',
			'    if ($http_accept ~* "image/webp") { set $webp_suffix ".webp"; }',
			'    try_files $uri$webp_suffix $uri =404;',
			'}',
		) );
	}
}
