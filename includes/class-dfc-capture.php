<?php
/**
 * DadsFam Cache — Page Capture.
 *
 * Buffers eligible frontend requests, runs the Pro optimizer pipeline,
 * and hands the final HTML to the cache manager.
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DFC_Capture {

	/** @var string Normalized request path. */
	private static $path = '';

	/** @var bool */
	private static $https = false;

	/** @var bool */
	private static $mobile = false;

	/** @var float */
	private static $start = 0;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start' ), 0 );
	}

	/**
	 * Start buffering when the request qualifies.
	 *
	 * @return void
	 */
	public static function maybe_start() {
		if ( ! self::should_cache_request() ) {
			return;
		}
		self::$start = microtime( true );
		header( 'X-DadsFam-Cache: MISS' );
		ob_start( array( __CLASS__, 'finish' ) );
	}

	/**
	 * All the reasons not to cache this request.
	 *
	 * @return bool
	 */
	private static function should_cache_request() {
		if ( ! DFC_Settings::get( 'enabled' ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'GET' !== $method ) {
			return false;
		}
		if ( is_user_logged_in() || isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			return false;
		}
		if ( is_404() || is_search() || is_feed() || is_trackback() || is_robots() || is_preview() || is_embed() || is_customize_preview() ) {
			return false;
		}
		if ( post_password_required() ) {
			return false;
		}
		// WooCommerce dynamic pages.
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}

		// Path checks (mirror the drop-in exactly).
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = DFC_Cache_Manager::normalize_path( $uri );
		if ( false === $path ) {
			return false;
		}
		$raw_path = '' === $path ? '/' : $path;
		if ( preg_match( '#(wp\-admin|wp\-login\.php|wp\-cron\.php|xmlrpc\.php|wp\-json|\.well\-known|robots\.txt|favicon\.ico|sitemap[^/]*\.xml|/feed(/|$))#i', $raw_path ) ) {
			return false;
		}
		foreach ( DFC_Settings::exclude_uri_regexes() as $regex ) {
			if ( @preg_match( $regex, $raw_path ) ) {
				return false;
			}
		}

		// Query strings: only ignorable marketing params allowed.
		$query_string = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : '';
		if ( '' !== $query_string ) {
			parse_str( $query_string, $query );
			$ignore = DFC_Settings::ignore_params();
			foreach ( array_keys( $query ) as $key ) {
				if ( ! in_array( strtolower( (string) $key ), $ignore, true ) ) {
					return false;
				}
			}
		}

		// Cookie bypass (carts, commenters, password-protected, custom).
		foreach ( array_keys( (array) $_COOKIE ) as $name ) {
			foreach ( DFC_Settings::exclude_cookies() as $prefix ) {
				if ( '' !== $prefix && 0 === stripos( (string) $name, $prefix ) ) {
					return false;
				}
			}
		}

		// User agent exclusions.
		$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		$ua_rx = DFC_Settings::exclude_agents_regex();
		if ( '' !== $ua_rx && '' !== $ua && @preg_match( $ua_rx, $ua ) ) {
			return false;
		}

		/**
		 * Last-chance veto for developers.
		 *
		 * @param bool $cacheable Whether the request will be cached.
		 */
		if ( ! apply_filters( 'dfc_cache_this_request', true ) ) {
			return false;
		}

		self::$path   = $path;
		self::$https  = is_ssl();
		self::$mobile = DFC_Settings::get( 'separate_mobile' ) && '' !== $ua
			&& (bool) preg_match( '#Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi#i', $ua );

		return true;
	}

	/**
	 * Output buffer callback: optimize, store, return.
	 *
	 * @param string $html Buffered page.
	 * @return string
	 */
	public static function finish( $html ) {
		// Late vetoes set during rendering.
		if ( ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE )
			|| ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() )
		) {
			return $html;
		}
		if ( 200 !== http_response_code() ) {
			return $html;
		}
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html; // Broken or partial output — never cache it.
		}

		$html = DFC_Optimizer::process( $html, self::optimizer_options() );

		$elapsed = self::$start ? sprintf( '%.3f', microtime( true ) - self::$start ) : '?';
		$html   .= "\n<!-- Cached by DadsFam Cache " . DFC_VERSION . ' — ' . gmdate( 'Y-m-d H:i:s' ) . " UTC — generated in {$elapsed}s -->";

		DFC_Cache_Manager::store( self::$path, $html, self::$https, self::$mobile );

		return $html;
	}

	/**
	 * Build the optimizer options from settings + license state.
	 *
	 * @return array
	 */
	private static function optimizer_options() {
		$pro = DFC_License::is_pro();

		$home  = untrailingslashit( home_url() );
		$plain = preg_replace( '#^https://#i', 'http://', $home );
		$tls   = preg_replace( '#^http://#i', 'https://', $home );

		return array(
			'pro'               => $pro,
			'minify_html'       => $pro && DFC_Settings::get( 'minify_html' ),
			'minify_inline_css' => $pro && DFC_Settings::get( 'minify_inline_css' ),
			'minify_css_files'  => $pro && DFC_Settings::get( 'minify_css_files' ),
			'css_exclusions'    => DFC_Settings::lines( 'css_exclusions' ),
			'defer_js'          => $pro && DFC_Settings::get( 'defer_js' ),
			'defer_exclusions'  => DFC_Settings::lines( 'defer_exclusions' ),
			'delay_js'          => $pro && DFC_Settings::get( 'delay_js' ),
			'delay_exclusions'  => DFC_Settings::lines( 'delay_exclusions' ),
			'lazyload'          => $pro && DFC_Settings::get( 'lazyload' ),
			'lazyload_skip'     => (int) DFC_Settings::get( 'lazyload_skip' ),
			'lazyload_iframes'  => $pro && DFC_Settings::get( 'lazyload_iframes' ),
			'dns_prefetch'      => DFC_Settings::lines( 'dns_prefetch' ),
			'preconnect'        => DFC_Settings::lines( 'preconnect' ),
			'cdn_enabled'       => $pro && DFC_Settings::get( 'cdn_enabled' ) && DFC_Settings::get( 'cdn_url' ),
			'cdn_url'           => (string) DFC_Settings::get( 'cdn_url' ),
			'cdn_exclusions'    => DFC_Settings::lines( 'cdn_exclusions' ),
			// Advanced / Core Web Vitals.
			'optimize_css_delivery' => $pro && DFC_Settings::get( 'optimize_css_delivery' ),
			'critical_css'          => (string) DFC_Settings::get( 'critical_css' ),
			'optimize_lcp'          => $pro && DFC_Settings::get( 'optimize_lcp' ),
			'lcp_image'             => (string) DFC_Settings::get( 'lcp_image' ),
			'font_optimize'         => $pro && DFC_Settings::get( 'font_optimize' ),
			'font_preload'          => DFC_Settings::lines( 'font_preload' ),
			'prefetch_links'        => $pro && DFC_Settings::get( 'prefetch_links' ),
			// Environment for the CSS file minifier + CDN rewriter.
			'home_https'        => $tls,
			'home_http'         => $plain,
			'content_url'       => untrailingslashit( content_url() ),
			'content_dir'       => untrailingslashit( WP_CONTENT_DIR ),
			'includes_url'      => untrailingslashit( includes_url() ),
			'includes_dir'      => untrailingslashit( ABSPATH . WPINC ),
			'min_dir'           => DFC_Cache_Manager::root() . '/min',
			'min_url'           => untrailingslashit( content_url() ) . '/cache/dadsfam-cache/min',
		);
	}
}
