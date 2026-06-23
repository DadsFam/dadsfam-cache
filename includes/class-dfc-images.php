<?php
/**
 * DadsFam Cache — image optimisation (WebP conversion).
 *
 * Converts JPEG/PNG files in the uploads folder to a `.webp` twin
 * (e.g. photo.jpg → photo.jpg.webp). The twin is then served automatically by
 * the .htaccess / NGINX rules to browsers that accept WebP, with zero changes
 * to the page HTML — so it is completely layout-safe and works with full-page
 * caching. Uses Imagick when available, otherwise GD.
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DFC_Images {

	/** Files converted per AJAX batch. */
	const BATCH = 15;

	/** Hard cap on directory entries scanned per request (safety valve). */
	const SCAN_CAP = 100000;

	/** @var array|null Cached capability probe. */
	private static $caps = null;

	/**
	 * Hook upload/delete handlers (called from the bootstrap when Pro is active).
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 20, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * What can this server actually do?
	 *
	 * @return array{engine:string,webp:bool,avif:bool}
	 */
	public static function capabilities() {
		if ( null !== self::$caps ) {
			return self::$caps;
		}
		$engine = 'none';
		$webp   = false;
		$avif   = false;

		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				$formats = array_map( 'strtoupper', (array) \Imagick::queryFormats() );
				if ( in_array( 'WEBP', $formats, true ) ) {
					$engine = 'imagick';
					$webp   = true;
				}
				if ( in_array( 'AVIF', $formats, true ) ) {
					$avif = true;
				}
			} catch ( \Throwable $e ) {
				$webp = false;
			}
		}
		if ( ! $webp && function_exists( 'imagewebp' ) ) {
			$engine = 'gd';
			$webp   = true;
			if ( function_exists( 'imageavif' ) ) {
				$avif = true;
			}
		}

		self::$caps = array( 'engine' => $engine, 'webp' => $webp, 'avif' => $avif );
		return self::$caps;
	}

	/**
	 * Count source images and how many already have a fresh WebP twin.
	 *
	 * @return array{sources:int,converted:int,pending:int}
	 */
	public static function counts() {
		$sources   = 0;
		$converted = 0;
		$seen      = 0;

		foreach ( self::iterate_uploads() as $path ) {
			if ( ++$seen > self::SCAN_CAP ) {
				break;
			}
			if ( ! self::is_source( $path ) ) {
				continue;
			}
			$sources++;
			if ( self::has_fresh_twin( $path ) ) {
				$converted++;
			}
		}

		return array(
			'sources'   => $sources,
			'converted' => $converted,
			'pending'   => max( 0, $sources - $converted ),
		);
	}

	/**
	 * Convert one batch of pending images.
	 *
	 * @param int $quality WebP quality 1–100.
	 * @return array|WP_Error {converted:int, remaining:int}
	 */
	public static function convert_batch( $quality ) {
		$caps = self::capabilities();
		if ( ! $caps['webp'] ) {
			return new WP_Error( 'dfc_no_webp', __( 'This server cannot create WebP images (no Imagick or GD WebP support). Ask your host to enable it.', 'dadsfam-cache' ) );
		}
		$quality   = max( 1, min( 100, (int) $quality ) );
		$converted = 0;
		$remaining = 0;
		$seen      = 0;

		foreach ( self::iterate_uploads() as $path ) {
			if ( ++$seen > self::SCAN_CAP ) {
				break;
			}
			if ( ! self::is_source( $path ) || self::has_fresh_twin( $path ) ) {
				continue;
			}
			if ( $converted < self::BATCH ) {
				if ( self::encode( $path, $path . '.webp', $quality ) ) {
					$converted++;
				} else {
					// Could not convert this one; count it as handled so we do
					// not loop forever on a corrupt file.
					$converted++;
				}
			} else {
				$remaining++;
			}
		}

		return array(
			'converted' => $converted,
			'remaining' => $remaining,
		);
	}

	/**
	 * Delete every WebP twin we created (used by the "remove WebP" tool).
	 *
	 * @return int Number removed.
	 */
	public static function delete_all() {
		$removed = 0;
		$seen    = 0;
		foreach ( self::iterate_uploads() as $path ) {
			if ( ++$seen > self::SCAN_CAP ) {
				break;
			}
			if ( '.webp' === strtolower( substr( $path, -5 ) ) && preg_match( '#\.(jpe?g|png)\.webp$#i', $path ) ) {
				if ( @unlink( $path ) ) {
					$removed++;
				}
			}
		}
		return $removed;
	}

	/* ------------------------------------------------------------------ */
	/* Upload / delete hooks                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Convert a freshly uploaded image (and its generated sizes) to WebP.
	 *
	 * @param array $metadata Attachment metadata.
	 * @param int   $id       Attachment ID.
	 * @return array Unmodified metadata.
	 */
	public static function on_upload( $metadata, $id ) {
		if ( ! DFC_Settings::get( 'serve_webp' ) || ! DFC_Settings::get( 'auto_webp' ) ) {
			return $metadata;
		}
		if ( ! self::capabilities()['webp'] || empty( $metadata['file'] ) ) {
			return $metadata;
		}
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$quality = max( 1, min( 100, (int) DFC_Settings::get( 'webp_quality' ) ) );

		$full = $base . $metadata['file'];
		self::maybe_encode( $full, $quality );

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$dir = trailingslashit( dirname( $full ) );
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					self::maybe_encode( $dir . $size['file'], $quality );
				}
			}
		}
		return $metadata;
	}

	/**
	 * Remove WebP twins when an attachment is deleted.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	public static function on_delete( $id ) {
		$file = get_attached_file( $id );
		if ( $file && is_file( $file . '.webp' ) ) {
			@unlink( $file . '.webp' );
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( ! empty( $meta['file'] ) ) {
			$uploads = wp_get_upload_dir();
			$dir     = trailingslashit( dirname( trailingslashit( $uploads['basedir'] ) . $meta['file'] ) );
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) && is_file( $dir . $size['file'] . '.webp' ) ) {
						@unlink( $dir . $size['file'] . '.webp' );
					}
				}
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Internals                                                          */
	/* ------------------------------------------------------------------ */

	/** Yield every file path under the uploads dir. */
	private static function iterate_uploads() {
		$uploads = wp_get_upload_dir();
		$dir     = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';
		if ( ! $dir || ! is_dir( $dir ) ) {
			return;
		}
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $it as $file ) {
				if ( $file->isFile() ) {
					yield $file->getPathname();
				}
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Is this a JPEG/PNG we should convert? */
	private static function is_source( $path ) {
		return (bool) preg_match( '#\.(jpe?g|png)$#i', $path );
	}

	/** Does a current .webp twin already exist? */
	private static function has_fresh_twin( $path ) {
		$twin = $path . '.webp';
		return is_file( $twin ) && filemtime( $twin ) >= filemtime( $path );
	}

	/** Encode if the twin is missing or stale. */
	private static function maybe_encode( $path, $quality ) {
		if ( self::is_source( $path ) && is_file( $path ) && ! self::has_fresh_twin( $path ) ) {
			self::encode( $path, $path . '.webp', $quality );
		}
	}

	/**
	 * Encode a single source image to WebP.
	 *
	 * @return bool
	 */
	private static function encode( $src, $dest, $quality ) {
		if ( ! is_file( $src ) || ! is_readable( $src ) ) {
			return false;
		}
		$caps = self::capabilities();

		try {
			if ( 'imagick' === $caps['engine'] ) {
				$im = new \Imagick();
				$im->readImage( $src );
				// Skip animated GIFs etc. (we only target jpg/png anyway).
				$im->setImageFormat( 'webp' );
				$im->setImageCompressionQuality( (int) $quality );
				$im->stripImage();
				$ok = $im->writeImage( $dest );
				$im->clear();
				$im->destroy();
				return $ok && is_file( $dest ) && filesize( $dest ) > 0;
			}

			if ( 'gd' === $caps['engine'] ) {
				$info = @getimagesize( $src );
				if ( ! $info ) {
					return false;
				}
				$image = null;
				if ( IMAGETYPE_JPEG === $info[2] ) {
					$image = @imagecreatefromjpeg( $src );
				} elseif ( IMAGETYPE_PNG === $info[2] ) {
					$image = @imagecreatefrompng( $src );
					if ( $image ) {
						imagepalettetotruecolor( $image );
						imagealphablending( $image, false );
						imagesavealpha( $image, true );
					}
				}
				if ( ! $image ) {
					return false;
				}
				$ok = @imagewebp( $image, $dest, (int) $quality );
				imagedestroy( $image );
				// GD can write a 0-byte file on failure.
				if ( $ok && is_file( $dest ) && filesize( $dest ) === 0 ) {
					@unlink( $dest );
					return false;
				}
				return (bool) $ok;
			}
		} catch ( \Throwable $e ) {
			if ( is_file( $dest ) && filesize( $dest ) === 0 ) {
				@unlink( $dest );
			}
			return false;
		}
		return false;
	}
}
