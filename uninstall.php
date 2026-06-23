<?php
/**
 * DadsFam Cache — uninstall routine.
 *
 * Runs only when the user deletes the plugin from the Plugins screen. Removes
 * every option, scheduled job, on-disk artefact and config edit the plugin made.
 *
 * The plugin classes are NOT loaded during uninstall, so the logic here is
 * deliberately self-contained.
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/* ---------------------------------------------------------------------------
 * 1. Options
 * ------------------------------------------------------------------------- */
$dfc_options = array(
	'dfc_settings',
	'dfc_license',
	'dfc_preload_queue',
	'dfc_version',
	'dfc_last_purge_all',
	'dfc_last_db_cleanup',
	'dfc_conflict_dismissed',
);
foreach ( $dfc_options as $dfc_opt ) {
	delete_option( $dfc_opt );
}

/* ---------------------------------------------------------------------------
 * 2. Scheduled jobs
 * ------------------------------------------------------------------------- */
foreach ( array( 'dfc_gc', 'dfc_license_check', 'dfc_db_cleanup', 'dfc_preload_batch' ) as $dfc_hook ) {
	wp_clear_scheduled_hook( $dfc_hook );
}

/* ---------------------------------------------------------------------------
 * 3. Our advanced-cache.php drop-in (identified by its DFC_DROPIN marker)
 * ------------------------------------------------------------------------- */
$dfc_dropin = WP_CONTENT_DIR . '/advanced-cache.php';
if ( file_exists( $dfc_dropin ) ) {
	$dfc_head = (string) @file_get_contents( $dfc_dropin, false, null, 0, 600 );
	if ( false !== strpos( $dfc_head, 'DFC_DROPIN' ) ) {
		@unlink( $dfc_dropin );
	}
}

/* ---------------------------------------------------------------------------
 * 4. The WP_CACHE line we added to wp-config.php
 * ------------------------------------------------------------------------- */
$dfc_config = '';
if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
	$dfc_config = ABSPATH . 'wp-config.php';
} elseif ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
	$dfc_config = dirname( ABSPATH ) . '/wp-config.php';
}
if ( $dfc_config && is_writable( $dfc_config ) ) {
	$dfc_contents = (string) file_get_contents( $dfc_config );
	$dfc_updated  = preg_replace( '/^.*WP_CACHE.*DadsFam Cache.*$(\r?\n)?/mi', '', $dfc_contents );
	if ( is_string( $dfc_updated ) && $dfc_updated !== $dfc_contents ) {
		@file_put_contents( $dfc_config, $dfc_updated );
	}
}

/* ---------------------------------------------------------------------------
 * 4b. Remove the insecure wp-config backup older versions left in the web root
 * ------------------------------------------------------------------------- */
foreach ( array( ABSPATH, dirname( ABSPATH ) . '/' ) as $dfc_dir ) {
	$dfc_legacy = $dfc_dir . 'wp-config.dfc-backup.php';
	if ( is_file( $dfc_legacy ) ) {
		@unlink( $dfc_legacy );
	}
}

/* ---------------------------------------------------------------------------
 * 5. Our .htaccess block (marker: DadsFam Cache)
 * ------------------------------------------------------------------------- */
if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
if ( ! function_exists( 'insert_with_markers' ) ) {
	require_once ABSPATH . 'wp-admin/includes/misc.php';
}
if ( function_exists( 'get_home_path' ) && function_exists( 'insert_with_markers' ) ) {
	$dfc_htaccess = get_home_path() . '.htaccess';
	if ( file_exists( $dfc_htaccess ) && is_writable( $dfc_htaccess ) ) {
		insert_with_markers( $dfc_htaccess, 'DadsFam Cache', array() );
	}
}

/* ---------------------------------------------------------------------------
 * 6. The cache directory
 * ------------------------------------------------------------------------- */
$dfc_cache_dir = WP_CONTENT_DIR . '/cache/dadsfam-cache';
if ( is_dir( $dfc_cache_dir ) ) {
	dfc_uninstall_rrmdir( $dfc_cache_dir );
}

/**
 * Recursively delete a directory.
 *
 * @param string $dir Absolute path.
 * @return void
 */
function dfc_uninstall_rrmdir( $dir ) {
	$items = @scandir( $dir );
	if ( false === $items ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			dfc_uninstall_rrmdir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}
