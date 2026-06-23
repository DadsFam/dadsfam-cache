<?php
/**
 * DadsFam Cache — Optimizer pipeline (Pro features).
 *
 * Every transform here is a pure string-in/string-out operation guarded so a
 * failed regex can never blank the page: if a step returns garbage we keep the
 * HTML from the previous step. Order matters and is documented in process().
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DFC_Optimizer {

	/** Placeholder prefix used while protecting blocks from regex passes. */
	const PH = "\x1ADFC";

	/**
	 * Run the full pipeline over a captured page.
	 *
	 * Order:
	 *  1. CSS file minify (rewrites <link> hrefs → /min/ copies)
	 *  2. CDN rewrite (also catches the freshly rewritten /min/ URLs)
	 *  3. LCP image (fetchpriority=high + <link rel=preload>, kept out of lazyload)
	 *  4. CSS delivery (inline critical CSS + async-load the rest) — needs critical CSS
	 *  5. Font optimize (font-display:swap on Google Fonts + font preload)
	 *  6. Lazyload images & iframes
	 *  7. DNS prefetch / preconnect injection
	 *  8. Delay JS (src → data-dfc-src + loader)
	 *  9. Defer JS (remaining plain scripts)
	 * 10. Prefetch internal links on hover (loader before </body>)
	 * 11. Inline <style> minify
	 * 12. HTML minify (last, so it sees the final markup)
	 *
	 * @param string $html Full page HTML.
	 * @param array  $opts Options from DFC_Capture::optimizer_options().
	 * @return string
	 */
	public static function process( $html, $opts ) {
		if ( ! is_string( $html ) || '' === $html || empty( $opts['pro'] ) ) {
			return $html;
		}

		if ( ! empty( $opts['minify_css_files'] ) ) {
			$html = self::safe( $html, array( __CLASS__, 'minify_css_files' ), $opts );
		}
		if ( ! empty( $opts['cdn_enabled'] ) && ! empty( $opts['cdn_url'] ) ) {
			$html = self::safe( $html, array( __CLASS__, 'cdn_rewrite' ), $opts );
		}
		if ( ! empty( $opts['optimize_lcp'] ) ) {
			$html = self::safe( $html, array( __CLASS__, 'optimize_lcp' ), $opts );
		}
		if ( ! empty( $opts['optimize_css_delivery'] ) && ! empty( $opts['critical_css'] ) ) {
			$html = self::safe( $html, array( __CLASS__, 'optimize_css_delivery' ), $opts );
		}
		if ( ! empty( $opts['font_optimize'] ) || ! empty( $opts['font_preload'] ) ) {
			$html = self::safe( $html, array( __CLASS__, 'optimize_fonts' ), $opts );
		}
		if ( ! empty( $opts['lazyload'] ) ) {
			$html = self::safe( $html, array( __CLASS__, 'lazyload' ), $opts );
		}
		if ( ! empty( $opts['dns_prefetch'] ) || ! empty( $opts['preconnect'] ) ) {
			$html = self::safe( $html, array( __CLASS__, 'resource_hints' ), $opts );
		}
		if ( ! empty( $opts['delay_js'] ) ) {
			$html = self::safe( $html, array( __CLASS__, 'delay_js' ), $opts );
		}
		if ( ! empty( $opts['defer_js'] ) ) {
			$html = self::safe( $html, array( __CLASS__, 'defer_js' ), $opts );
		}
		if ( ! empty( $opts['prefetch_links'] ) ) {
			$html = self::safe( $html, array( __CLASS__, 'prefetch_links' ), $opts );
		}
		if ( ! empty( $opts['minify_inline_css'] ) ) {
			$html = self::safe( $html, array( __CLASS__, 'minify_inline_css' ), $opts );
		}
		if ( ! empty( $opts['minify_html'] ) ) {
			$html = self::safe( $html, array( __CLASS__, 'minify_html' ), $opts );
		}

		return $html;
	}

	/**
	 * Run one transform; fall back to the input if anything goes sideways.
	 */
	private static function safe( $html, $callback, $opts ) {
		try {
			$out = call_user_func( $callback, $html, $opts );
		} catch ( \Throwable $e ) {
			return $html;
		}
		// A transform must return a believable page, never a stub.
		if ( is_string( $out ) && strlen( $out ) > 200 ) {
			return $out;
		}
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * 1. CSS file minification
	 * ------------------------------------------------------------------- */

	/**
	 * Find local stylesheet <link>s, write a minified copy into the /min/
	 * cache dir and point the tag at it. url(...) refs are absolutised so
	 * relative paths keep working from the new location.
	 */
	public static function minify_css_files( $html, $opts ) {
		$min_dir = isset( $opts['min_dir'] ) ? $opts['min_dir'] : '';
		$min_url = isset( $opts['min_url'] ) ? $opts['min_url'] : '';
		if ( ! $min_dir || ! $min_url ) {
			return $html;
		}
		if ( ! is_dir( $min_dir ) && ! @mkdir( $min_dir, 0755, true ) ) {
			return $html;
		}

		$exclusions = isset( $opts['css_exclusions'] ) ? (array) $opts['css_exclusions'] : array();

		return preg_replace_callback(
			'#<link\b[^>]*>#i',
			function ( $m ) use ( $opts, $min_dir, $min_url, $exclusions ) {
				$tag = $m[0];

				// Only rel=stylesheet tags.
				if ( ! preg_match( '#\brel\s*=\s*["\']?stylesheet["\']?#i', $tag ) ) {
					return $tag;
				}
				// Respect alternate stylesheets / print-only? Keep media intact, minify anyway.
				if ( ! preg_match( '#\bhref\s*=\s*("([^"]+)"|\'([^\']+)\'|([^\s>]+))#i', $tag, $hm ) ) {
					return $tag;
				}
				$href = ! empty( $hm[2] ) ? $hm[2] : ( ! empty( $hm[3] ) ? $hm[3] : $hm[4] );
				$href = html_entity_decode( $href, ENT_QUOTES );

				// Skip exclusions and already-minified files.
				foreach ( $exclusions as $needle ) {
					if ( '' !== $needle && false !== stripos( $href, $needle ) ) {
						return $tag;
					}
				}
				if ( false !== stripos( $href, '.min.css' ) ) {
					return $tag;
				}

				$path_url = self::local_url_to_pathinfo( $href, $opts );
				if ( ! $path_url ) {
					return $tag;
				}
				list( $file, $base_url ) = $path_url;

				if ( ! is_file( $file ) || ! is_readable( $file ) ) {
					return $tag;
				}
				$size = filesize( $file );
				if ( ! $size || $size > 1048576 ) { // Skip empty or >1 MB monsters.
					return $tag;
				}

				$mtime = (int) filemtime( $file );
				$hash  = substr( md5( $file . ':' . $mtime . ':' . $size ), 0, 10 );
				$name  = preg_replace( '/\.css$/i', '', basename( parse_url( $href, PHP_URL_PATH ) ) );
				$name  = preg_replace( '/[^A-Za-z0-9_\-]/', '', $name );
				if ( '' === $name ) {
					$name = 'style';
				}
				$out_file = $min_dir . '/' . $name . '-' . $hash . '.min.css';
				$out_url  = $min_url . '/' . $name . '-' . $hash . '.min.css';

				if ( ! is_file( $out_file ) ) {
					$css = @file_get_contents( $file );
					if ( false === $css || '' === $css ) {
						return $tag;
					}
					$css = self::absolutize_css_urls( $css, $base_url );
					$css = self::css_minify( $css );
					if ( '' === $css ) {
						return $tag;
					}
					$tmp = $out_file . '.' . uniqid( '', true ) . '.tmp';
					if ( false === @file_put_contents( $tmp, $css ) ) {
						return $tag;
					}
					@rename( $tmp, $out_file );
				}

				// Swap only the href value inside this tag.
				return str_replace( $hm[0], 'href="' . htmlspecialchars( $out_url, ENT_QUOTES ) . '"', $tag );
			},
			$html
		);
	}

	/**
	 * Map a same-site stylesheet URL to its file on disk + the URL of its
	 * directory (for absolutising relative url() refs).
	 *
	 * @return array|false [ absolute file path, directory URL with trailing slash ]
	 */
	private static function local_url_to_pathinfo( $href, $opts ) {
		$home_https = $opts['home_https'];
		$home_http  = $opts['home_http'];
		$host       = parse_url( $home_https, PHP_URL_HOST );

		// Normalise protocol-relative + root-relative to absolute https.
		if ( 0 === strpos( $href, '//' . $host ) ) {
			$href = 'https:' . $href;
		} elseif ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
			$href = $home_https . $href;
		} elseif ( 0 === strpos( $href, $home_http ) ) {
			$href = $home_https . substr( $href, strlen( $home_http ) );
		}
		if ( 0 !== strpos( $href, $home_https ) ) {
			return false; // External — leave alone.
		}

		$clean = strtok( $href, '?#' );
		$pairs = array(
			array( rtrim( $opts['content_url'], '/' ), rtrim( $opts['content_dir'], '/' ) ),
			array( rtrim( $opts['includes_url'], '/' ), rtrim( $opts['includes_dir'], '/' ) ),
		);
		foreach ( $pairs as $p ) {
			if ( 0 === strpos( $clean, $p[0] . '/' ) ) {
				$rel  = substr( $clean, strlen( $p[0] ) );
				$file = $p[1] . $rel;
				// Hard traversal guard.
				if ( false !== strpos( $rel, '..' ) ) {
					return false;
				}
				$dir_url = preg_replace( '#/[^/]*$#', '/', $clean );
				return array( $file, $dir_url );
			}
		}
		return false;
	}

	/**
	 * Rewrite relative url(...) references to absolute, so the stylesheet
	 * still resolves images/fonts after moving into /min/.
	 */
	public static function absolutize_css_urls( $css, $base_url ) {
		return preg_replace_callback(
			'#url\(\s*([\'"]?)([^\'")\s]+)\1\s*\)#i',
			function ( $m ) use ( $base_url ) {
				$url = $m[2];
				if ( preg_match( '#^(https?:)?//|^data:|^\#|^/#i', $url ) ) {
					return $m[0]; // Already absolute / root-relative / data URI / fragment.
				}
				// Resolve ../ segments against the base URL.
				$abs = $base_url . $url;
				while ( preg_match( '#/[^/]+/\.\./#', $abs ) ) {
					$abs = preg_replace( '#/[^/]+/\.\./#', '/', $abs, 1 );
				}
				return 'url(' . $abs . ')';
			},
			$css
		);
	}

	/**
	 * Conservative CSS minifier. Never touches "+" / "-" spacing, so calc()
	 * keeps working. Strips comments, collapses whitespace, drops spaces
	 * around structural characters and trailing semicolons.
	 */
	public static function css_minify( $css ) {
		$out = preg_replace( '#/\*.*?\*/#s', '', $css );
		$out = preg_replace( '/\s+/', ' ', $out );
		$out = preg_replace( '/\s*([{};:>~,])\s*/', '$1', $out );
		$out = str_replace( ';}', '}', $out );
		return is_string( $out ) ? trim( $out ) : $css;
	}

	/* ---------------------------------------------------------------------
	 * 2. CDN rewrite
	 * ------------------------------------------------------------------- */

	public static function cdn_rewrite( $html, $opts ) {
		$cdn = rtrim( trim( (string) $opts['cdn_url'] ), '/' );
		if ( '' === $cdn ) {
			return $html;
		}
		if ( ! preg_match( '#^https?://#i', $cdn ) ) {
			$cdn = 'https://' . ltrim( $cdn, '/' );
		}

		// Protect excluded substrings before rewriting.
		$exclusions = array_filter( (array) ( isset( $opts['cdn_exclusions'] ) ? $opts['cdn_exclusions'] : array() ) );
		$store      = array();
		foreach ( $exclusions as $i => $needle ) {
			$needle = trim( $needle );
			if ( '' === $needle ) {
				continue;
			}
			$ph           = self::PH . 'CDN' . $i . "\x1A";
			$store[ $ph ] = $needle;
			$html         = str_replace( $needle, $ph, $html );
		}

		$home_https = $opts['home_https'];
		$home_http  = $opts['home_http'];
		$host       = parse_url( $home_https, PHP_URL_HOST );

		foreach ( array( 'wp-content', 'wp-includes' ) as $dir ) {
			$html = str_replace(
				array( $home_https . '/' . $dir . '/', $home_http . '/' . $dir . '/', '//' . $host . '/' . $dir . '/' ),
				$cdn . '/' . $dir . '/',
				$html
			);
		}

		// Root-relative refs in src/href/srcset/css url().
		$html = preg_replace_callback(
			'#(["\'(,=]\s*)/(wp-content|wp-includes)/#',
			function ( $m ) use ( $cdn ) {
				return $m[1] . $cdn . '/' . $m[2] . '/';
			},
			$html
		);

		// Restore protected substrings.
		if ( $store ) {
			$html = str_replace( array_keys( $store ), array_values( $store ), $html );
		}
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * 3. Lazyload
	 * ------------------------------------------------------------------- */

	public static function lazyload( $html, $opts ) {
		// Leave <noscript> fallbacks untouched.
		$stash = array();
		$html  = preg_replace_callback(
			'#<noscript\b[^>]*>.*?</noscript>#is',
			function ( $m ) use ( &$stash ) {
				$ph           = self::PH . 'NS' . count( $stash ) . "\x1A";
				$stash[ $ph ] = $m[0];
				return $ph;
			},
			$html
		);

		$skip_first = max( 0, (int) $opts['lazyload_skip'] );
		$seen       = 0;

		$html = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $m ) use ( &$seen, $skip_first ) {
				$tag = $m[0];
				if ( preg_match( '#\bloading\s*=#i', $tag ) ) {
					return $tag;
				}
				if ( preg_match( '#\b(data-no-lazy|skip-lazy|no-lazy)\b#i', $tag ) ) {
					return $tag;
				}
				$seen++;
				if ( $seen <= $skip_first ) {
					return $tag; // Likely above the fold — load eagerly.
				}
				$inject = ' loading="lazy"';
				if ( ! preg_match( '#\bdecoding\s*=#i', $tag ) ) {
					$inject .= ' decoding="async"';
				}
				return preg_replace( '#\s*/?>$#', $inject . '$0', $tag, 1 );
			},
			$html
		);

		if ( ! empty( $opts['lazyload_iframes'] ) ) {
			$html = preg_replace_callback(
				'#<iframe\b[^>]*>#i',
				function ( $m ) {
					$tag = $m[0];
					if ( preg_match( '#\b(loading\s*=|data-no-lazy|skip-lazy|no-lazy)#i', $tag ) ) {
						return $tag;
					}
					return preg_replace( '#\s*/?>$#', ' loading="lazy"$0', $tag, 1 );
				},
				$html
			);
		}

		if ( $stash ) {
			$html = str_replace( array_keys( $stash ), array_values( $stash ), $html );
		}
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * 4. Resource hints
	 * ------------------------------------------------------------------- */

	public static function resource_hints( $html, $opts ) {
		$links = '';
		foreach ( (array) $opts['dns_prefetch'] as $h ) {
			$h = self::clean_host( $h );
			if ( $h ) {
				$links .= '<link rel="dns-prefetch" href="//' . $h . '">';
			}
		}
		foreach ( (array) $opts['preconnect'] as $h ) {
			$h = self::clean_host( $h );
			if ( $h ) {
				$links .= '<link rel="preconnect" href="https://' . $h . '" crossorigin>';
			}
		}
		if ( '' === $links ) {
			return $html;
		}
		$out = preg_replace( '#(<head\b[^>]*>)#i', '$1' . $links, $html, 1, $count );
		return $count ? $out : $html;
	}

	private static function clean_host( $h ) {
		$h = trim( (string) $h );
		$h = preg_replace( '#^https?://#i', '', $h );
		$h = preg_replace( '#^//#', '', $h );
		$h = rtrim( strtok( $h, '/' ), '/' );
		return preg_match( '/^[a-z0-9.\-]+$/i', $h ) ? strtolower( $h ) : '';
	}

	/* ---------------------------------------------------------------------
	 * 5. Delay JS until first interaction
	 * ------------------------------------------------------------------- */

	public static function delay_js( $html, $opts ) {
		$exclusions = array_filter( array_map( 'trim', (array) $opts['delay_exclusions'] ) );
		$changed    = 0;

		$html = preg_replace_callback(
			'#<script\b[^>]*\bsrc\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)[^>]*>\s*</script>#is',
			function ( $m ) use ( $exclusions, &$changed ) {
				$tag = $m[0];
				$src = trim( $m[1], '"\'' );

				// Only plain JavaScript tags.
				if ( preg_match( '#\btype\s*=\s*["\']?(?!text/javascript)[^"\'>\s]#i', $tag ) ) {
					return $tag;
				}
				foreach ( $exclusions as $needle ) {
					if ( false !== stripos( $src, $needle ) || false !== stripos( $tag, $needle ) ) {
						return $tag;
					}
				}

				$new = preg_replace( '#\bsrc\s*=#i', 'data-dfc-src=', $tag, 1 );
				// Replace existing type or add ours.
				if ( preg_match( '#\btype\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $new ) ) {
					$new = preg_replace( '#\btype\s*=\s*("[^"]*"|\'[^\']*\')#i', 'type="dfc/delay"', $new, 1 );
				} else {
					$new = preg_replace( '#<script\b#i', '<script type="dfc/delay"', $new, 1 );
				}
				$changed++;
				return $new;
			},
			$html
		);

		if ( ! $changed ) {
			return $html;
		}

		$loader = '<script id="dfc-delay-loader">!function(){var d=!1,e=["mousemove","mousedown","keydown","touchstart","scroll","wheel"],t=setTimeout(r,10000);function r(){if(!d){d=!0,clearTimeout(t),e.forEach(function(n){window.removeEventListener(n,r,{passive:!0})});var c=[].slice.call(document.querySelectorAll(\'script[type="dfc/delay"]\'));!function n(){var o=c.shift();if(!o)return void document.dispatchEvent(new Event("dfc:delayed"));var a=document.createElement("script");[].slice.call(o.attributes).forEach(function(e){"type"!==e.name&&"data-dfc-src"!==e.name&&a.setAttribute(e.name,e.value)}),a.src=o.getAttribute("data-dfc-src"),a.onload=a.onerror=n,o.parentNode.replaceChild(a,o)}()}}e.forEach(function(n){window.addEventListener(n,r,{passive:!0})})}();</script>';

		$out = preg_replace( '#</body>#i', $loader . '</body>', $html, 1, $count );
		return $count ? $out : $html . $loader;
	}

	/* ---------------------------------------------------------------------
	 * 6. Defer JS
	 * ------------------------------------------------------------------- */

	public static function defer_js( $html, $opts ) {
		$exclusions = array_filter( array_map( 'trim', (array) $opts['defer_exclusions'] ) );

		return preg_replace_callback(
			'#<script\b[^>]*\bsrc\s*=[^>]*>#i',
			function ( $m ) use ( $exclusions ) {
				$tag = $m[0];
				if ( preg_match( '#\b(defer|async)\b#i', $tag ) ) {
					return $tag;
				}
				// Only plain JavaScript (no type, or type=text/javascript).
				if ( preg_match( '#\btype\s*=#i', $tag ) && ! preg_match( '#\btype\s*=\s*["\']?text/javascript["\']?#i', $tag ) ) {
					return $tag;
				}
				foreach ( $exclusions as $needle ) {
					if ( false !== stripos( $tag, $needle ) ) {
						return $tag;
					}
				}
				return preg_replace( '#<script\b#i', '<script defer', $tag, 1 );
			},
			$html
		);
	}

	/* ---------------------------------------------------------------------
	 * 7. Inline <style> minify
	 * ------------------------------------------------------------------- */

	public static function minify_inline_css( $html, $opts ) {
		return preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style>)#is',
			function ( $m ) {
				return $m[1] . self::css_minify( $m[2] ) . $m[3];
			},
			$html
		);
	}

	/* ---------------------------------------------------------------------
	 * 8. HTML minify
	 * ------------------------------------------------------------------- */

	public static function minify_html( $html, $opts ) {
		$stash = array();

		// Protect whitespace-sensitive / fragile blocks.
		$html = preg_replace_callback(
			'#<(pre|textarea|script|style|noscript)\b[^>]*>.*?</\1>#is',
			function ( $m ) use ( &$stash ) {
				$ph           = self::PH . 'B' . count( $stash ) . "\x1A";
				$stash[ $ph ] = $m[0];
				return $ph;
			},
			$html
		);

		// Strip HTML comments, but keep IE conditionals.
		$html = preg_replace( '#<!--(?!\[if|<!\[)(?!\s*\[endif).*?-->#s', '', $html );

		// Collapse runs of blank lines + indentation.
		$html = preg_replace( '/\v+\h*/u', "\n", $html );
		$html = preg_replace( '/\h{2,}/u', ' ', $html );

		if ( $stash ) {
			$html = str_replace( array_keys( $stash ), array_values( $stash ), $html );
		}
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * LCP image — fetchpriority + preload, kept eager
	 * ------------------------------------------------------------------- */

	/**
	 * Boost the Largest Contentful Paint image: mark it high priority, keep it
	 * out of lazyload, and preload it from the <head>. Targets the image whose
	 * src matches the configured fragment, or the first content image otherwise.
	 */
	public static function optimize_lcp( $html, $opts ) {
		$needle  = trim( (string) $opts['lcp_image'] );
		$done    = false;
		$preload = '';

		$html = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $m ) use ( &$done, &$preload, $needle ) {
				if ( $done ) {
					return $m[0];
				}
				$tag = $m[0];
				if ( ! preg_match( '#\bsrc\s*=\s*("([^"]+)"|\'([^\']+)\'|([^\s>]+))#i', $tag, $s ) ) {
					return $tag;
				}
				$src = '' !== $s[2] ? $s[2] : ( '' !== $s[3] ? $s[3] : $s[4] );
				$src = trim( $src );
				if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
					return $tag;
				}
				if ( '' !== $needle && false === stripos( $src, $needle ) ) {
					return $tag; // Waiting for the specific image the user named.
				}
				$done = true;

				$new = preg_replace( '#\s+loading\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $tag );
				if ( ! preg_match( '#\bfetchpriority\s*=#i', $new ) ) {
					$new = preg_replace( '#\s*/?>$#', ' fetchpriority="high"$0', $new, 1 );
				}
				if ( ! preg_match( '#\b(data-no-lazy|skip-lazy|no-lazy)\b#i', $new ) ) {
					$new = preg_replace( '#\s*/?>$#', ' data-no-lazy="1"$0', $new, 1 );
				}

				$attrs = ' href="' . self::attr( $src ) . '"';
				if ( preg_match( '#\bsrcset\s*=\s*("([^"]+)"|\'([^\']+)\')#i', $tag, $ss ) ) {
					$set    = '' !== $ss[2] ? $ss[2] : $ss[3];
					$attrs .= ' imagesrcset="' . self::attr( $set ) . '"';
					if ( preg_match( '#\bsizes\s*=\s*("([^"]+)"|\'([^\']+)\')#i', $tag, $sz ) ) {
						$attrs .= ' imagesizes="' . self::attr( '' !== $sz[2] ? $sz[2] : $sz[3] ) . '"';
					}
				}
				$preload = '<link rel="preload" as="image"' . $attrs . ' fetchpriority="high">';
				return $new;
			},
			$html
		);

		if ( $done && '' !== $preload ) {
			$html = self::head_inject( $html, $preload );
		}
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * CSS delivery — inline critical CSS, async-load the rest
	 * ------------------------------------------------------------------- */

	/**
	 * Eliminate render-blocking CSS. Inlines the supplied critical CSS in the
	 * <head> and flips remaining stylesheets to load asynchronously (with a
	 * <noscript> fallback so no-JS visitors still get full styling). Only runs
	 * when critical CSS is supplied, so there is never a flash of unstyled text.
	 */
	public static function optimize_css_delivery( $html, $opts ) {
		$critical = trim( (string) $opts['critical_css'] );
		if ( '' === $critical ) {
			return $html;
		}
		$exclusions = isset( $opts['css_exclusions'] ) ? (array) $opts['css_exclusions'] : array();

		$html = preg_replace_callback(
			'#<link\b[^>]*>#i',
			function ( $m ) use ( $exclusions ) {
				$tag = $m[0];
				if ( ! preg_match( '#\brel\s*=\s*["\']?stylesheet["\']?#i', $tag ) ) {
					return $tag;
				}
				if ( preg_match( '#\bmedia\s*=\s*["\']?print#i', $tag ) ) {
					return $tag; // Already non-blocking.
				}
				if ( preg_match( '#\b(onload\s*=|data-no-optimize)\b#i', $tag ) ) {
					return $tag; // Already async or explicitly opted out.
				}
				if ( ! preg_match( '#\bhref\s*=\s*("([^"]+)"|\'([^\']+)\'|([^\s>]+))#i', $tag, $h ) ) {
					return $tag;
				}
				$href = '' !== $h[2] ? $h[2] : ( '' !== $h[3] ? $h[3] : $h[4] );
				foreach ( $exclusions as $needle ) {
					$needle = trim( (string) $needle );
					if ( '' !== $needle && false !== stripos( $href, $needle ) ) {
						return $tag;
					}
				}
				$async = preg_replace( '#\s+media\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $tag );
				$async = preg_replace( '#\s*/?>$#', " media=\"print\" onload=\"this.media='all';this.onload=null;\">", $async, 1 );
				return $async . '<noscript>' . $tag . '</noscript>';
			},
			$html
		);

		return self::head_inject( $html, '<style id="dfc-critical-css">' . $critical . '</style>' );
	}

	/* ---------------------------------------------------------------------
	 * Fonts — display:swap + preload
	 * ------------------------------------------------------------------- */

	/**
	 * Add font-display:swap to Google Fonts requests (kills invisible-text time)
	 * and preload any font files the user lists.
	 */
	public static function optimize_fonts( $html, $opts ) {
		if ( ! empty( $opts['font_optimize'] ) ) {
			$html = preg_replace_callback(
				'#<link\b[^>]*>#i',
				function ( $m ) {
					$tag = $m[0];
					if ( false === stripos( $tag, 'fonts.googleapis.com' ) ) {
						return $tag;
					}
					if ( false !== stripos( $tag, 'display=' ) ) {
						return $tag;
					}
					return preg_replace_callback(
						'#\bhref\s*=\s*("([^"]+)"|\'([^\']+)\')#i',
						function ( $h ) {
							$dq  = '' !== $h[2];
							$url = $dq ? $h[2] : $h[3];
							$url .= ( false !== strpos( $url, '?' ) ? '&' : '?' ) . 'display=swap';
							$q    = $dq ? '"' : "'";
							return 'href=' . $q . $url . $q;
						},
						$tag
					);
				},
				$html
			);
		}

		$preloads = '';
		foreach ( (array) $opts['font_preload'] as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				continue;
			}
			$preloads .= '<link rel="preload" as="font" type="' . self::font_mime( $url ) . '" href="' . self::attr( $url ) . '" crossorigin>';
		}
		if ( '' !== $preloads ) {
			$html = self::head_inject( $html, $preloads );
		}
		return $html;
	}

	/** Best-effort font MIME type from a URL. */
	private static function font_mime( $url ) {
		$u   = strtolower( (string) strtok( $url, '?#' ) );
		$map = array(
			'woff2' => 'font/woff2',
			'woff'  => 'font/woff',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			'eot'   => 'application/vnd.ms-fontobject',
		);
		foreach ( $map as $ext => $mime ) {
			if ( substr( $u, - ( strlen( $ext ) + 1 ) ) === '.' . $ext ) {
				return $mime;
			}
		}
		return 'font/woff2';
	}

	/* ---------------------------------------------------------------------
	 * Prefetch internal links on hover / touch
	 * ------------------------------------------------------------------- */

	/**
	 * Inject a tiny loader that prefetches same-origin links the moment a
	 * visitor hovers or starts a tap, so the next page is often already cached
	 * by the browser. Respects Save-Data and slow connections.
	 */
	public static function prefetch_links( $html, $opts ) {
		$js = <<<'JS'
(function(){
var c=navigator.connection;
if(c&&(c.saveData||/(^|-)2g$/.test(c.effectiveType||"")))return;
var origin=location.origin,seen={};
function pf(u){if(seen[u])return;seen[u]=1;var l=document.createElement("link");l.rel="prefetch";l.href=u;document.head.appendChild(l);}
function pick(a){if(!a||!a.href||a.origin!==origin)return null;if(a.hasAttribute("download"))return null;var u=a.href.split("#")[0];if(u===location.href.split("#")[0])return null;if(/\.(zip|rar|7z|pdf|jpe?g|png|gif|webp|avif|svg|mp4|mp3|wav|docx?|xlsx?|pptx?)$/i.test(u))return null;return u;}
function on(e){var t=e.target;var a=t&&t.closest?t.closest("a"):null;var u=pick(a);if(u)pf(u);}
document.addEventListener("mouseover",on,{passive:true,capture:true});
document.addEventListener("touchstart",on,{passive:true,capture:true});
})();
JS;
		return self::body_end_inject( $html, '<script id="dfc-prefetch">' . $js . '</script>' );
	}

	/* ---------------------------------------------------------------------
	 * Shared helpers
	 * ------------------------------------------------------------------- */

	/** Escape a value for use inside a double-quoted HTML attribute. */
	private static function attr( $s ) {
		return str_replace(
			array( '&', '"', '<', '>' ),
			array( '&amp;', '&quot;', '&lt;', '&gt;' ),
			(string) $s
		);
	}

	/** Insert a snippet immediately after the first <head> tag. */
	private static function head_inject( $html, $snippet ) {
		$count = 0;
		$out   = preg_replace_callback(
			'#<head\b[^>]*>#i',
			function ( $m ) use ( $snippet, &$count ) {
				if ( $count ) {
					return $m[0];
				}
				$count++;
				return $m[0] . $snippet;
			},
			$html
		);
		return $count ? $out : $html;
	}

	/** Insert a snippet just before the last </body> tag. */
	private static function body_end_inject( $html, $snippet ) {
		$pos = strripos( $html, '</body>' );
		if ( false === $pos ) {
			return $html . $snippet;
		}
		return substr( $html, 0, $pos ) . $snippet . substr( $html, $pos );
	}
}
