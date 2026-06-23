<?php
/**
 * Plugin Name:       DadsFam Cache
 * Plugin URI:        https://dadsfam.co.za/plugins/
 * Description:        A fast, friendly page-caching and performance plugin for WordPress & WooCommerce. Free disk caching, gzip and browser-cache rules; Pro unlocks minification, lazy-loading, delayed JavaScript, CDN rewriting and database cleanup.
 * Version:           1.2.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            DadsFam
 * Author URI:        https://dadsfam.co.za
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dadsfam-cache
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------------
 * Constants
 * ------------------------------------------------------------------------- */
define( 'DFC_VERSION', '1.2.1' );
define( 'DFC_FILE', __FILE__ );
define( 'DFC_DIR', plugin_dir_path( __FILE__ ) );
define( 'DFC_URL', plugin_dir_url( __FILE__ ) );

// Overridable by wp-config.php if ever needed.
if ( ! defined( 'DFC_LICENSE_API' ) ) {
	define( 'DFC_LICENSE_API', 'https://dadsfam.co.za/wp-json/dfem-licenses/v1/verify' );
}
if ( ! defined( 'DFC_BUY_URL' ) ) {
	define( 'DFC_BUY_URL', 'https://dadsfam.co.za/shop/' );
}
// Developers can drop `define( 'DFC_FORCE_PRO', true );` into wp-config.php on
// their own sites to unlock Pro features without a key.

/* ---------------------------------------------------------------------------
 * Includes
 * ------------------------------------------------------------------------- */
require_once DFC_DIR . 'includes/class-dfc-settings.php';
require_once DFC_DIR . 'includes/class-dfc-cache-manager.php';
require_once DFC_DIR . 'includes/class-dfc-optimizer.php';
require_once DFC_DIR . 'includes/class-dfc-dropin.php';
require_once DFC_DIR . 'includes/class-dfc-capture.php';
require_once DFC_DIR . 'includes/class-dfc-purge-hooks.php';
require_once DFC_DIR . 'includes/class-dfc-preload.php';
require_once DFC_DIR . 'includes/class-dfc-db-cleaner.php';
require_once DFC_DIR . 'includes/class-dfc-htaccess.php';
require_once DFC_DIR . 'includes/class-dfc-images.php';
require_once DFC_DIR . 'includes/class-dfc-license.php';
require_once DFC_DIR . 'includes/class-dfc-admin.php';

/* ---------------------------------------------------------------------------
 * Boot
 * ------------------------------------------------------------------------- */
add_action( 'plugins_loaded', 'dfc_boot' );

/**
 * Wire everything up once WordPress (and pluggable functions) are ready.
 *
 * @return void
 */
function dfc_boot() {
	load_plugin_textdomain( 'dadsfam-cache', false, dirname( plugin_basename( DFC_FILE ) ) . '/languages' );

	DFC_License::init();
	DFC_Capture::init();
	DFC_Purge_Hooks::init();
	DFC_Preload::init();
	DFC_DB_Cleaner::init();

	// Heartbeat control (Pro only).
	dfc_apply_heartbeat();

	// Image WebP conversion on upload/delete (Pro only).
	if ( DFC_License::is_pro() ) {
		DFC_Images::init();
	}

	// Admin screens.
	if ( is_admin() ) {
		DFC_Admin::init();
	}

	// Toolbar "Clear cache" menu — useful on both the front-end and admin.
	add_action( 'admin_bar_menu', array( 'DFC_Admin', 'admin_bar' ), 90 );

	// Hourly housekeeping.
	add_action( 'dfc_gc', array( 'DFC_Cache_Manager', 'garbage_collect' ) );

	// Run a lightweight upgrade routine when the version changes.
	dfc_maybe_upgrade();
}

/**
 * Adjust the WordPress Heartbeat API according to the saved setting.
 *
 * @return void
 */
function dfc_apply_heartbeat() {
	if ( ! DFC_License::is_pro() ) {
		return;
	}
	$mode = DFC_Settings::get( 'heartbeat' );

	if ( 'slow' === $mode ) {
		add_filter(
			'heartbeat_settings',
			function ( $settings ) {
				$settings['interval'] = 60;
				return $settings;
			}
		);
	} elseif ( 'disable_front' === $mode ) {
		add_action(
			'init',
			function () {
				if ( ! is_admin() ) {
					wp_deregister_script( 'heartbeat' );
				}
			},
			1
		);
	}
}

/**
 * Refresh on-disk artefacts after a plugin update.
 *
 * @return void
 */
function dfc_maybe_upgrade() {
	$stored = get_option( 'dfc_version' );
	if ( DFC_VERSION === $stored ) {
		return;
	}
	DFC_Cache_Manager::ensure_dirs();
	DFC_Cache_Manager::write_config();
	// Remove the insecure wp-config backup older versions created in the web root.
	DFC_Dropin::cleanup_legacy_backup();
	// Keep our drop-in template in sync, but never adopt a foreign one.
	if ( DFC_Dropin::is_ours() ) {
		DFC_Dropin::install( true );
	}
	update_option( 'dfc_version', DFC_VERSION, false );
}

/* ---------------------------------------------------------------------------
 * Activation
 * ------------------------------------------------------------------------- */
register_activation_hook( __FILE__, 'dfc_activate' );

/**
 * Seed defaults, build the cache skeleton, and schedule background jobs.
 *
 * @return void
 */
function dfc_activate() {
	// Seed default settings on first install only.
	if ( false === get_option( DFC_Settings::OPTION ) ) {
		update_option( DFC_Settings::OPTION, DFC_Settings::all() );
	}

	DFC_Cache_Manager::ensure_dirs();
	DFC_Cache_Manager::write_config();
	DFC_Dropin::cleanup_legacy_backup();

	// Only auto-install the engine if no other cache plugin is fighting us.
	// (If one is, the admin notice tells the user to deactivate it, then they
	// hit "One-Click Speed Setup" which installs the engine cleanly.)
	if ( ! DFC_Dropin::active_conflicts() ) {
		DFC_Dropin::install( false ); // No-ops safely if a foreign drop-in exists.
	}

	// Schedule recurring jobs.
	if ( ! wp_next_scheduled( 'dfc_gc' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'dfc_gc' );
	}
	if ( ! wp_next_scheduled( DFC_License::CRON ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', DFC_License::CRON );
	}
	if ( ! wp_next_scheduled( DFC_DB_Cleaner::CRON ) ) {
		wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', DFC_DB_Cleaner::CRON );
	}

	update_option( 'dfc_version', DFC_VERSION, false );
}

/* ---------------------------------------------------------------------------
 * Deactivation
 * ------------------------------------------------------------------------- */
register_deactivation_hook( __FILE__, 'dfc_deactivate' );

/**
 * Tidy up: remove our drop-in, the WP_CACHE flag, .htaccess rules, per-host
 * config and all scheduled jobs. User settings are intentionally kept.
 *
 * @return void
 */
function dfc_deactivate() {
	DFC_Dropin::remove();          // Only removes our own drop-in.
	DFC_Dropin::remove_wp_cache(); // Only removes our own WP_CACHE line.
	DFC_Cache_Manager::delete_configs();
	DFC_Cache_Manager::purge_all();

	if ( DFC_Htaccess::is_apache() ) {
		DFC_Htaccess::remove();
	}

	foreach ( array( 'dfc_gc', DFC_License::CRON, DFC_DB_Cleaner::CRON, DFC_Preload::CRON ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}
