<?php
/**
 * DadsFam Cache — Drop-in & wp-config manager.
 *
 * Installs/removes the advanced-cache.php drop-in, toggles WP_CACHE in
 * wp-config.php (via a safe atomic write), and detects competing cache plugins.
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DFC_Dropin {

	/**
	 * Known cache plugins that conflict with page caching.
	 *
	 * @return array slug-file => label
	 */
	public static function known_conflicts() {
		return array(
			'speedycache/speedycache.php'           => 'SpeedyCache',
			'speedycache-pro/speedycache-pro.php'   => 'SpeedyCache Pro',
			'wp-rocket/wp-rocket.php'               => 'WP Rocket',
			'w3-total-cache/w3-total-cache.php'     => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'           => 'WP Super Cache',
			'litespeed-cache/litespeed-cache.php'   => 'LiteSpeed Cache',
			'wp-fastest-cache/wpFastestCache.php'   => 'WP Fastest Cache',
			'cache-enabler/cache-enabler.php'       => 'Cache Enabler',
			'breeze/breeze.php'                     => 'Breeze',
			'sg-cachepress/sg-cachepress.php'       => 'SiteGround Optimizer',
			'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
			'comet-cache/comet-cache.php'           => 'Comet Cache',
			'swift-performance-lite/performance.php' => 'Swift Performance',
		);
	}

	/**
	 * Active conflicting cache plugins.
	 *
	 * @return string[] Labels.
	 */
	public static function active_conflicts() {
		$active = (array) get_option( 'active_plugins', array() );
		$found  = array();
		foreach ( self::known_conflicts() as $file => $label ) {
			if ( in_array( $file, $active, true ) ) {
				$found[] = $label;
			}
		}
		return $found;
	}

	/**
	 * Path to wp-content/advanced-cache.php.
	 *
	 * @return string
	 */
	public static function dropin_path() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/**
	 * Is the installed drop-in ours?
	 *
	 * @return bool
	 */
	public static function is_ours() {
		$path = self::dropin_path();
		if ( ! file_exists( $path ) ) {
			return false;
		}
		$head = (string) @file_get_contents( $path, false, null, 0, 600 );
		return false !== strpos( $head, 'DFC_DROPIN' );
	}

	/**
	 * Install (or refresh) the drop-in.
	 *
	 * @param bool $force Replace a foreign drop-in too.
	 * @return true|WP_Error
	 */
	public static function install( $force = false ) {
		$target = self::dropin_path();

		if ( file_exists( $target ) && ! self::is_ours() && ! $force ) {
			return new WP_Error(
				'dfc_foreign_dropin',
				__( 'Another plugin already owns advanced-cache.php. Deactivate the other cache plugin first, then click “Install drop-in” again.', 'dadsfam-cache' )
			);
		}

		$source = DFC_DIR . 'advanced-cache.php';
		if ( ! is_readable( $source ) ) {
			return new WP_Error( 'dfc_no_template', __( 'Drop-in template is missing from the plugin folder.', 'dadsfam-cache' ) );
		}

		$contents = (string) file_get_contents( $source );
		if ( false === @file_put_contents( $target, $contents ) ) {
			return new WP_Error( 'dfc_write_failed', __( 'Could not write wp-content/advanced-cache.php — check file permissions.', 'dadsfam-cache' ) );
		}

		$wp_cache = self::ensure_wp_cache();
		if ( is_wp_error( $wp_cache ) ) {
			return $wp_cache; // Drop-in is in place; surfacing the wp-config issue.
		}
		return true;
	}

	/**
	 * Remove our drop-in (never touches a foreign one).
	 *
	 * @return void
	 */
	public static function remove() {
		if ( self::is_ours() ) {
			@unlink( self::dropin_path() );
		}
	}

	/**
	 * Locate wp-config.php (supports the one-directory-up layout).
	 *
	 * @return string|false
	 */
	public static function config_path() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$up = dirname( ABSPATH ) . '/wp-config.php';
		if ( file_exists( $up ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return $up;
		}
		return false;
	}

	/**
	 * Make sure define('WP_CACHE', true) exists in wp-config.php.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure_wp_cache() {
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return true;
		}

		$config = self::config_path();
		if ( ! $config || ! is_writable( $config ) ) {
			return new WP_Error(
				'dfc_wpconfig',
				__( 'Could not edit wp-config.php. Add this line just below the opening <?php tag yourself:', 'dadsfam-cache' )
				. " define( 'WP_CACHE', true );"
			);
		}

		$contents = (string) file_get_contents( $config );

		// Older versions (<= 1.2.0) left a wp-config copy in the web root as a
		// "safety backup". That exposed DB credentials, so we no longer create
		// one and we delete any that exists. The atomic write below is the real
		// safety net — wp-config.php is never left half-written.
		self::cleanup_legacy_backup();

		if ( preg_match( '/define\s*\(\s*([\'"])WP_CACHE\1\s*,/i', $contents ) ) {
			// A define exists but evaluated false — flip its value to true.
			$updated = preg_replace(
				'/define\s*\(\s*([\'"])WP_CACHE\1\s*,\s*[^)]+\)\s*;/i',
				"define( 'WP_CACHE', true ); // Modified by DadsFam Cache",
				$contents,
				1
			);
		} else {
			$updated = preg_replace(
				'/^<\?php/',
				"<?php\ndefine( 'WP_CACHE', true ); // Added by DadsFam Cache",
				$contents,
				1
			);
		}

		if ( ! is_string( $updated ) || $updated === $contents ) {
			return new WP_Error( 'dfc_wpconfig', __( 'Could not update WP_CACHE in wp-config.php.', 'dadsfam-cache' ) );
		}
		// Write atomically: stage to a temp file in the same directory, then
		// rename it over wp-config.php. The original is never left half-written,
		// which is why no public backup copy is required.
		$tmp = dirname( $config ) . '/.wp-config-dfc-tmp.php';
		if ( false === @file_put_contents( $tmp, $updated, LOCK_EX ) ) {
			return new WP_Error( 'dfc_wpconfig', __( 'Could not write to wp-config.php — check file permissions.', 'dadsfam-cache' ) );
		}
		if ( ! @rename( $tmp, $config ) ) {
			@unlink( $tmp );
			return new WP_Error( 'dfc_wpconfig', __( 'Could not update wp-config.php — check file permissions.', 'dadsfam-cache' ) );
		}
		return true;
	}

	/**
	 * Remove only the WP_CACHE line we added/modified (identified by our marker).
	 *
	 * @return void
	 */
	public static function remove_wp_cache() {
		$config = self::config_path();
		if ( ! $config || ! is_writable( $config ) ) {
			return;
		}
		$contents = (string) file_get_contents( $config );
		$updated  = preg_replace( '/^.*WP_CACHE.*DadsFam Cache.*$(\r?\n)?/mi', '', $contents );
		if ( is_string( $updated ) && $updated !== $contents ) {
			@file_put_contents( $config, $updated );
		}
		self::cleanup_legacy_backup();
	}

	/**
	 * Delete the insecure wp-config backup that versions <= 1.2.0 created in the
	 * web root (wp-config.dfc-backup.php). It contained database credentials in a
	 * public location, so we remove it from every place it might exist. Safe to
	 * run any time — it only ever touches that one filename.
	 *
	 * @return bool True if a file was removed.
	 */
	public static function cleanup_legacy_backup() {
		$removed = false;
		$dirs    = array( ABSPATH, dirname( ABSPATH ) . '/' );
		$config  = self::config_path();
		if ( $config ) {
			$dirs[] = trailingslashit( dirname( $config ) );
		}
		foreach ( array_unique( $dirs ) as $dir ) {
			$file = $dir . 'wp-config.dfc-backup.php';
			if ( is_file( $file ) && @unlink( $file ) ) {
				$removed = true;
			}
		}
		return $removed;
	}

	/**
	 * Status summary for the dashboard checklist.
	 *
	 * @return array
	 */
	public static function status() {
		return array(
			'dropin_exists' => file_exists( self::dropin_path() ),
			'dropin_ours'   => self::is_ours(),
			'wp_cache_on'   => defined( 'WP_CACHE' ) && WP_CACHE,
			'conflicts'     => self::active_conflicts(),
		);
	}
}
