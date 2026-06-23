<?php
/**
 * DadsFam Cache — Cache Manager.
 *
 * Owns the cache folder: storing pages, purging, stats, garbage collection,
 * and generating the lightweight config file the advanced-cache.php drop-in reads.
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DFC_Cache_Manager {

	/**
	 * Absolute cache root, no trailing slash.
	 *
	 * @return string
	 */
	public static function root() {
		return WP_CONTENT_DIR . '/cache/dadsfam-cache';
	}

	/**
	 * Current host, sanitized for filesystem use.
	 *
	 * @return string
	 */
	public static function host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = strtolower( (string) $host );
		return preg_replace( '/[^a-z0-9\.\-]/', '', $host );
	}

	/**
	 * Make sure the cache skeleton exists (with directory-listing protection).
	 *
	 * @return void
	 */
	public static function ensure_dirs() {
		$root = self::root();
		foreach ( array( $root, $root . '/html', $root . '/min', $root . '/config' ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}
		if ( ! file_exists( $root . '/index.html' ) ) {
			@file_put_contents( $root . '/index.html', '' );
		}
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			@file_put_contents( $root . '/.htaccess', "Options -Indexes\n" );
		}
	}

	/**
	 * Normalize a REQUEST_URI into a safe cache path. Mirrors the drop-in.
	 *
	 * @param string $request_uri Raw request URI.
	 * @return string|false Path like '/blog/post' ('' for home) or false if unsafe.
	 */
	public static function normalize_path( $request_uri ) {
		$parts = explode( '?', (string) $request_uri, 2 );
		$path  = rawurldecode( $parts[0] );
		if ( strlen( $path ) > 800 || false !== strpos( $path, '..' ) || false !== strpos( $path, "\0" ) ) {
			return false;
		}
		$path = preg_replace( '#/+#', '/', $path );
		return rtrim( $path, '/' );
	}

	/**
	 * Directory that holds the cached variants for a path.
	 *
	 * @param string $path Normalized path ('' for home).
	 * @return string
	 */
	public static function dir_for_path( $path ) {
		return self::root() . '/html/' . self::host() . $path;
	}

	/**
	 * Store a rendered page (plus gzip twin) atomically.
	 *
	 * @param string $path      Normalized path.
	 * @param string $html      Final HTML.
	 * @param bool   $is_https  HTTPS variant.
	 * @param bool   $is_mobile Mobile variant.
	 * @return bool
	 */
	public static function store( $path, $html, $is_https, $is_mobile ) {
		$dir = self::dir_for_path( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$file = $dir . '/index' . ( $is_https ? '-https' : '' ) . ( $is_mobile ? '-mobile' : '' ) . '.html';
		$tmp  = $file . '.' . wp_rand( 1000, 9999 ) . '.tmp';

		if ( false === @file_put_contents( $tmp, $html ) ) {
			@unlink( $tmp );
			return false;
		}
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return false;
		}

		if ( DFC_Settings::get( 'gzip' ) && function_exists( 'gzencode' ) ) {
			$gzdata = gzencode( $html, 6 );
			if ( false !== $gzdata ) {
				$gtmp = $file . '.gz.' . wp_rand( 1000, 9999 ) . '.tmp';
				if ( false !== @file_put_contents( $gtmp, $gzdata ) ) {
					@rename( $gtmp, $file . '.gz' );
				} else {
					@unlink( $gtmp );
				}
			}
		} else {
			@unlink( $file . '.gz' );
		}

		return true;
	}

	/**
	 * Purge all cached variants for one URL (this directory only, not children).
	 *
	 * @param string $url Full URL or path.
	 * @return int Files removed.
	 */
	public static function purge_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = self::normalize_path( null === $path ? '/' : $path );
		if ( false === $path ) {
			return 0;
		}
		$dir = self::dir_for_path( $path );
		if ( ! self::path_inside_root( $dir ) || ! is_dir( $dir ) ) {
			return 0;
		}
		$removed = 0;
		foreach ( (array) glob( $dir . '/index*.html*' ) as $file ) {
			if ( is_file( $file ) && @unlink( $file ) ) {
				$removed++;
			}
		}
		return $removed;
	}

	/**
	 * Purge the entire page cache for this site.
	 *
	 * @return void
	 */
	public static function purge_all() {
		self::rrmdir( self::root() . '/html/' . self::host() );
		update_option( 'dfc_last_purge_all', time(), false );

		/**
		 * Fires after the whole page cache has been purged.
		 */
		do_action( 'dfc_purged_all' );
	}

	/**
	 * Purge minified asset copies (forces regeneration).
	 *
	 * @return void
	 */
	public static function purge_min() {
		self::rrmdir( self::root() . '/min' );
		self::ensure_dirs();
	}

	/**
	 * Delete expired cache files based on the configured lifetime.
	 *
	 * @return int Files removed.
	 */
	public static function garbage_collect() {
		$hours = (int) DFC_Settings::get( 'lifetime_hours' );
		if ( $hours <= 0 ) {
			return 0;
		}
		$cutoff = time() - ( $hours * HOUR_IN_SECONDS );
		$base   = self::root() . '/html/' . self::host();
		if ( ! is_dir( $base ) ) {
			return 0;
		}
		$removed = 0;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && $file->getMTime() < $cutoff && @unlink( $file->getPathname() ) ) {
					$removed++;
				}
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// Unreadable tree — skip this run.
		}
		return $removed;
	}

	/**
	 * Cache stats for the dashboard.
	 *
	 * @return array { pages:int, files:int, bytes:int, human:string }
	 */
	public static function stats() {
		$base  = self::root() . '/html/' . self::host();
		$pages = 0;
		$files = 0;
		$bytes = 0;
		if ( is_dir( $base ) ) {
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY
				);
				foreach ( $iterator as $file ) {
					if ( ! $file->isFile() ) {
						continue;
					}
					$files++;
					$bytes += (int) $file->getSize();
					if ( '.html' === substr( $file->getFilename(), -5 ) ) {
						$pages++;
					}
				}
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// Ignore.
			}
		}
		return array(
			'pages' => $pages,
			'files' => $files,
			'bytes' => $bytes,
			'human' => size_format( $bytes ? $bytes : 0, 1 ),
		);
	}

	/**
	 * Write the per-host config file the drop-in reads on every request.
	 *
	 * @return bool
	 */
	public static function write_config() {
		self::ensure_dirs();

		$config = array(
			'version'         => DFC_VERSION,
			'enabled'         => (bool) DFC_Settings::get( 'enabled' ),
			'lifetime'        => (int) DFC_Settings::get( 'lifetime_hours' ) * HOUR_IN_SECONDS,
			'separate_mobile' => (bool) DFC_Settings::get( 'separate_mobile' ),
			'gzip'            => (bool) DFC_Settings::get( 'gzip' ),
			'exclude_uris'    => DFC_Settings::exclude_uri_regexes(),
			'exclude_cookies' => DFC_Settings::exclude_cookies(),
			'exclude_agents'  => DFC_Settings::exclude_agents_regex(),
			'ignore_params'   => DFC_Settings::ignore_params(),
		);

		$php  = "<?php\n// DadsFam Cache runtime config — auto-generated, do not edit.\n";
		$php .= "if ( ! defined( 'ABSPATH' ) && ! defined( 'DFC_DROPIN' ) ) { exit; }\n";
		$php .= 'return ' . var_export( $config, true ) . ";\n";

		$file = self::root() . '/config/' . self::host() . '.php';
		$tmp  = $file . '.tmp';
		if ( false === @file_put_contents( $tmp, $php ) ) {
			@unlink( $tmp );
			return false;
		}
		return (bool) @rename( $tmp, $file );
	}

	/**
	 * Remove all drop-in config files (drop-in then bails on every request).
	 *
	 * @return void
	 */
	public static function delete_configs() {
		foreach ( (array) glob( self::root() . '/config/*.php' ) as $file ) {
			@unlink( $file );
		}
	}

	/**
	 * Whether a path resolves inside the cache root (traversal guard).
	 *
	 * @param string $path Path to check.
	 * @return bool
	 */
	private static function path_inside_root( $path ) {
		$root = realpath( self::root() );
		if ( false === $root ) {
			return false;
		}
		$check = $path;
		while ( $check && ! file_exists( $check ) ) {
			$parent = dirname( $check );
			if ( $parent === $check ) {
				return false;
			}
			$check = $parent;
		}
		$real = realpath( $check );
		return ( false !== $real ) && 0 === strpos( $real . '/', $root . '/' );
	}

	/**
	 * Recursively delete a directory, but only inside our cache root.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) || ! self::path_inside_root( $dir ) ) {
			return;
		}
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $item ) {
				if ( $item->isDir() ) {
					@rmdir( $item->getPathname() );
				} else {
					@unlink( $item->getPathname() );
				}
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// Ignore.
		}
		@rmdir( $dir );
	}
}
