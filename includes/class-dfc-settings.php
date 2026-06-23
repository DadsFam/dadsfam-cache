<?php
/**
 * DadsFam Cache — Settings.
 *
 * Single option array, sane defaults, hard sanitization.
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DFC_Settings {

	const OPTION = 'dfc_settings';

	/** @var array|null Runtime cache. */
	private static $cache = null;

	/**
	 * Default marketing/tracking params that never bust the cache.
	 *
	 * @return string[]
	 */
	public static function default_ignore_params() {
		return array(
			'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
			'fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'mc_cid', 'mc_eid',
			'ref', '_ga', '_gl', 'srsltid', 'igshid', 'twclid',
		);
	}

	/**
	 * Cookie prefixes that always bypass the cache (logged-in users, carts, etc).
	 *
	 * @return string[]
	 */
	public static function default_exclude_cookies() {
		return array(
			'wordpress_logged_in',
			'wp-postpass_',
			'comment_author_',
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'wp_woocommerce_session_',
			'dfc_nocache',
		);
	}

	/**
	 * All defaults. Free first, Pro after.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// ---- Free: page cache ----
			'enabled'           => 1,
			'lifetime_hours'    => 10,   // 0 = keep until purged.
			'separate_mobile'   => 0,
			'gzip'              => 1,
			'browser_cache'     => 1,    // .htaccess expires headers.
			'htaccess_gzip'     => 1,    // .htaccess deflate rules.
			'smart_purge'       => 1,    // Purge post + home + archives instead of everything.
			'purge_on_update'   => 1,    // Purge all after core/plugin/theme updates.
			// ---- Free: exclusions ----
			'exclude_uris'      => "/cart\n/checkout\n/my-account",
			'exclude_cookies'   => '',
			'exclude_agents'    => '',
			'ignore_params'     => '',
			// ---- Pro: speed ----
			'minify_html'       => 0,
			'minify_inline_css' => 0,
			'minify_css_files'  => 0,
			'css_exclusions'    => '',
			'defer_js'          => 0,
			'defer_exclusions'  => "jquery.min.js\njquery.js",
			'delay_js'          => 0,
			'delay_exclusions'  => "jquery\nwp-includes",
			'lazyload'          => 0,
			'lazyload_skip'     => 2,
			'lazyload_iframes'  => 0,
			'dns_prefetch'      => '',
			'preconnect'        => '',
			'heartbeat'         => 'default', // default | slow | disable_front.
			// ---- Pro: speed (advanced / Core Web Vitals) ----
			'optimize_css_delivery' => 0,  // Inline critical CSS + load the rest async.
			'critical_css'          => '', // Raw above-the-fold CSS.
			'optimize_lcp'          => 0,  // fetchpriority + preload the hero image.
			'lcp_image'             => '', // URL or filename fragment of the LCP image.
			'font_optimize'         => 0,  // font-display:swap on Google Fonts.
			'font_preload'          => '', // Font file URLs to preload (one per line).
			'prefetch_links'        => 0,  // Prefetch internal links on hover.
			// ---- Pro: images ----
			'serve_webp'            => 0,  // Serve .webp twins to capable browsers.
			'webp_quality'          => 82, // WebP encode quality.
			'auto_webp'             => 1,  // Convert new uploads to WebP automatically.
			// ---- Pro: CDN ----
			'cdn_enabled'       => 0,
			'cdn_url'           => '',
			'cdn_exclusions'    => '',
			// ---- Preload ----
			'preload_sitemap'   => '',
			'preload_auto'      => 0,    // Pro: warm cache automatically after a full purge.
			// ---- Pro: database ----
			'db_schedule'       => 'never', // never | weekly.
			'db_tasks'          => array( 'revisions', 'auto_drafts', 'spam_comments', 'trash_comments', 'expired_transients' ),
		);
	}

	/**
	 * Keys that are Pro-only. Forced back to default when no active license.
	 *
	 * @return string[]
	 */
	public static function pro_keys() {
		return array(
			'minify_html', 'minify_inline_css', 'minify_css_files', 'css_exclusions',
			'defer_js', 'delay_js', 'lazyload', 'lazyload_iframes',
			'dns_prefetch', 'preconnect', 'heartbeat',
			'optimize_css_delivery', 'critical_css', 'optimize_lcp', 'lcp_image',
			'font_optimize', 'font_preload', 'prefetch_links',
			'serve_webp', 'webp_quality', 'auto_webp',
			'cdn_enabled', 'cdn_url', 'cdn_exclusions',
			'preload_auto', 'db_schedule',
		);
	}

	/**
	 * Get all settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist settings (already sanitized) and refresh runtime cache.
	 *
	 * @param array $settings Sanitized settings.
	 * @return void
	 */
	public static function update( array $settings ) {
		self::$cache = wp_parse_args( $settings, self::defaults() );
		update_option( self::OPTION, self::$cache, true );
	}

	/**
	 * Sanitize raw input (typically $_POST['dfc']) into a clean settings array.
	 *
	 * @param array $raw    Raw input.
	 * @param bool  $is_pro Whether Pro features may be enabled.
	 * @return array
	 */
	public static function sanitize( $raw, $is_pro ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$defaults = self::defaults();
		$clean    = array();

		$checkboxes = array(
			'enabled', 'separate_mobile', 'gzip', 'browser_cache', 'htaccess_gzip',
			'smart_purge', 'purge_on_update', 'minify_html', 'minify_inline_css',
			'minify_css_files', 'defer_js', 'delay_js', 'lazyload', 'lazyload_iframes',
			'optimize_css_delivery', 'optimize_lcp', 'font_optimize', 'prefetch_links',
			'serve_webp', 'auto_webp',
			'cdn_enabled', 'preload_auto',
		);
		foreach ( $checkboxes as $key ) {
			$clean[ $key ] = empty( $raw[ $key ] ) ? 0 : 1;
		}

		$clean['lifetime_hours'] = isset( $raw['lifetime_hours'] ) ? min( 8760, max( 0, absint( $raw['lifetime_hours'] ) ) ) : $defaults['lifetime_hours'];
		$clean['lazyload_skip']  = isset( $raw['lazyload_skip'] ) ? min( 10, max( 0, absint( $raw['lazyload_skip'] ) ) ) : $defaults['lazyload_skip'];
		$clean['webp_quality']   = isset( $raw['webp_quality'] ) ? min( 100, max( 1, absint( $raw['webp_quality'] ) ) ) : $defaults['webp_quality'];

		$lists = array(
			'exclude_uris', 'exclude_cookies', 'exclude_agents', 'ignore_params',
			'css_exclusions', 'defer_exclusions', 'delay_exclusions',
			'dns_prefetch', 'preconnect', 'cdn_exclusions', 'font_preload',
		);
		foreach ( $lists as $key ) {
			$clean[ $key ] = self::sanitize_lines( isset( $raw[ $key ] ) ? $raw[ $key ] : '' );
		}

		$clean['heartbeat'] = ( isset( $raw['heartbeat'] ) && in_array( $raw['heartbeat'], array( 'default', 'slow', 'disable_front' ), true ) )
			? $raw['heartbeat']
			: 'default';

		$clean['cdn_url'] = isset( $raw['cdn_url'] ) ? esc_url_raw( trim( (string) $raw['cdn_url'] ) ) : '';
		if ( $clean['cdn_url'] && ! preg_match( '#^https?://#i', $clean['cdn_url'] ) ) {
			$clean['cdn_url'] = 'https://' . ltrim( $clean['cdn_url'], '/' );
		}
		$clean['cdn_url'] = untrailingslashit( $clean['cdn_url'] );

		$clean['preload_sitemap'] = isset( $raw['preload_sitemap'] ) ? esc_url_raw( trim( (string) $raw['preload_sitemap'] ) ) : '';

		// LCP image: accept either a full URL or a bare filename fragment.
		$clean['lcp_image'] = isset( $raw['lcp_image'] ) ? sanitize_text_field( trim( (string) $raw['lcp_image'] ) ) : '';

		// Critical CSS: stored close to raw (it is CSS, not HTML) but we strip any
		// closing style tag so it can never break out of the <style> we inject it into.
		$critical = isset( $raw['critical_css'] ) ? (string) wp_unslash( $raw['critical_css'] ) : '';
		$critical = preg_replace( '#</\s*style\s*>#i', '', $critical );
		$clean['critical_css'] = trim( $critical );

		$clean['db_schedule'] = ( isset( $raw['db_schedule'] ) && in_array( $raw['db_schedule'], array( 'never', 'weekly' ), true ) )
			? $raw['db_schedule']
			: 'never';

		$valid_tasks = array( 'revisions', 'auto_drafts', 'trash_posts', 'spam_comments', 'trash_comments', 'expired_transients', 'optimize_tables' );
		$tasks       = isset( $raw['db_tasks'] ) && is_array( $raw['db_tasks'] ) ? $raw['db_tasks'] : array();
		$clean['db_tasks'] = array_values( array_intersect( $valid_tasks, array_map( 'sanitize_key', $tasks ) ) );

		// No license? Pro toggles fall back to defaults so nothing sneaks on.
		if ( ! $is_pro ) {
			foreach ( self::pro_keys() as $key ) {
				$clean[ $key ] = $defaults[ $key ];
			}
		}

		return $clean;
	}

	/**
	 * Normalize a textarea of one-item-per-line values.
	 *
	 * @param string $value Raw textarea value.
	 * @return string
	 */
	private static function sanitize_lines( $value ) {
		$value = is_string( $value ) ? $value : '';
		$lines = preg_split( '/[\r\n]+/', wp_unslash( $value ) );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' !== $line && strlen( $line ) <= 300 ) {
				$out[] = $line;
			}
		}
		return implode( "\n", array_slice( array_unique( $out ), 0, 100 ) );
	}

	/**
	 * Turn a one-per-line setting into an array.
	 *
	 * @param string $key Setting key.
	 * @return string[]
	 */
	public static function lines( $key ) {
		$value = (string) self::get( $key, '' );
		if ( '' === trim( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $value ) ) ) );
	}

	/**
	 * Effective ignore-params list (defaults + user additions), lowercased.
	 *
	 * @return string[]
	 */
	public static function ignore_params() {
		$user = array_map( 'strtolower', self::lines( 'ignore_params' ) );
		return array_values( array_unique( array_merge( self::default_ignore_params(), $user ) ) );
	}

	/**
	 * Effective cookie bypass prefixes (defaults + user additions).
	 *
	 * @return string[]
	 */
	public static function exclude_cookies() {
		return array_values( array_unique( array_merge( self::default_exclude_cookies(), self::lines( 'exclude_cookies' ) ) ) );
	}

	/**
	 * User URI exclusions compiled to regexes (supports * wildcard).
	 *
	 * @return string[] Array of regex strings.
	 */
	public static function exclude_uri_regexes() {
		$out = array();
		foreach ( self::lines( 'exclude_uris' ) as $pattern ) {
			$rx = str_replace( '\*', '.*', preg_quote( $pattern, '#' ) );
			$out[] = '#' . $rx . '#i';
		}
		return $out;
	}

	/**
	 * User-agent exclusions compiled to one regex (or empty string).
	 *
	 * @return string
	 */
	public static function exclude_agents_regex() {
		$agents = self::lines( 'exclude_agents' );
		if ( empty( $agents ) ) {
			return '';
		}
		$quoted = array_map(
			static function ( $a ) {
				return preg_quote( $a, '#' );
			},
			$agents
		);
		return '#(' . implode( '|', $quoted ) . ')#i';
	}
}
