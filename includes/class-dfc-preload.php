<?php
/**
 * DadsFam Cache — cache preloader.
 *
 * Walks the sitemap and visits each URL so real visitors get warm-cache HITs
 * instead of paying the first-load cost. Runs in small cron batches to stay
 * friendly to shared hosting.
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DFC_Preload {

	const QUEUE   = 'dfc_preload_queue';
	const CRON    = 'dfc_preload_batch';
	const MAX_URL = 500;
	const BATCH   = 5;

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'run_batch' ) );

		// Pro: re-warm the cache automatically after a full purge.
		add_action( 'dfc_purged_all', array( __CLASS__, 'maybe_auto_start' ) );
	}

	public static function maybe_auto_start() {
		if ( DFC_License::is_pro() && DFC_Settings::get( 'preload_auto' ) && ! self::is_running() ) {
			wp_schedule_single_event( time() + 30, self::CRON );
			update_option(
				self::QUEUE,
				array( 'urls' => array(), 'pos' => 0, 'started' => time(), 'pending_collect' => 1 ),
				false
			);
		}
	}

	public static function is_running() {
		$q = get_option( self::QUEUE );
		return is_array( $q ) && ( ! empty( $q['pending_collect'] ) || ( isset( $q['pos'], $q['urls'] ) && $q['pos'] < count( $q['urls'] ) ) );
	}

	/** Kick off a fresh preload (from the admin UI). */
	public static function start() {
		$urls = self::collect_urls();
		if ( ! $urls ) {
			return new WP_Error( 'dfc_no_urls', __( 'No URLs found — check that your sitemap is reachable.', 'dadsfam-cache' ) );
		}
		update_option(
			self::QUEUE,
			array( 'urls' => array_values( $urls ), 'pos' => 0, 'started' => time() ),
			false
		);
		wp_clear_scheduled_hook( self::CRON );
		wp_schedule_single_event( time() + 2, self::CRON );
		return count( $urls );
	}

	public static function stop() {
		wp_clear_scheduled_hook( self::CRON );
		delete_option( self::QUEUE );
	}

	public static function status() {
		$q = get_option( self::QUEUE );
		if ( ! is_array( $q ) ) {
			return array( 'running' => false, 'done' => 0, 'total' => 0 );
		}
		if ( ! empty( $q['pending_collect'] ) ) {
			return array( 'running' => true, 'done' => 0, 'total' => 0 );
		}
		$total = isset( $q['urls'] ) ? count( $q['urls'] ) : 0;
		$done  = isset( $q['pos'] ) ? min( (int) $q['pos'], $total ) : 0;
		return array( 'running' => $done < $total, 'done' => $done, 'total' => $total );
	}

	/** Cron worker: fetch a handful of URLs, then reschedule itself. */
	public static function run_batch() {
		$q = get_option( self::QUEUE );
		if ( ! is_array( $q ) ) {
			return;
		}

		// Deferred collection (auto-preload path) happens here, off-request.
		if ( ! empty( $q['pending_collect'] ) ) {
			$urls = self::collect_urls();
			if ( ! $urls ) {
				delete_option( self::QUEUE );
				return;
			}
			$q = array( 'urls' => array_values( $urls ), 'pos' => 0, 'started' => time() );
			update_option( self::QUEUE, $q, false );
		}

		$total = count( $q['urls'] );
		$pos   = (int) $q['pos'];
		$start = microtime( true );
		$done  = 0;

		while ( $pos < $total && $done < self::BATCH && ( microtime( true ) - $start ) < 20 ) {
			$url = $q['urls'][ $pos ];
			wp_remote_get(
				$url,
				array(
					'timeout'    => 10,
					'sslverify'  => false, // Loopback on shared hosts often has cert-name mismatches.
					'user-agent' => 'DadsFamCache/' . DFC_VERSION . ' Preloader',
					'headers'    => array( 'X-DFC-Preload' => '1' ),
				)
			);
			$pos++;
			$done++;
		}

		$q['pos'] = $pos;
		update_option( self::QUEUE, $q, false );

		if ( $pos < $total ) {
			wp_schedule_single_event( time() + 5, self::CRON );
		}
	}

	/**
	 * Gather URLs: custom sitemap setting → wp-sitemap.xml → sitemap_index.xml
	 * (Yoast & friends) → recent posts/pages as a last resort.
	 */
	public static function collect_urls() {
		$urls   = array( home_url( '/' ) );
		$custom = trim( (string) DFC_Settings::get( 'preload_sitemap' ) );

		$candidates = $custom
			? array( $custom )
			: array( home_url( '/wp-sitemap.xml' ), home_url( '/sitemap_index.xml' ), home_url( '/sitemap.xml' ) );

		foreach ( $candidates as $sitemap ) {
			$found = self::parse_sitemap( $sitemap );
			if ( $found ) {
				$urls = array_merge( $urls, $found );
				break;
			}
		}

		// Fallback: pull URLs straight from the database.
		if ( count( $urls ) < 2 ) {
			$posts = get_posts(
				array(
					'post_type'      => array( 'post', 'page', 'product' ),
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'orderby'        => 'modified',
					'fields'         => 'ids',
				)
			);
			foreach ( $posts as $pid ) {
				$link = get_permalink( $pid );
				if ( $link ) {
					$urls[] = $link;
				}
			}
		}

		$home = home_url();
		$urls = array_filter(
			array_unique( $urls ),
			function ( $u ) use ( $home ) {
				return 0 === strpos( $u, $home );
			}
		);

		return array_slice( array_values( $urls ), 0, self::MAX_URL );
	}

	/** Fetch one sitemap; recurse one level into sitemap indexes. */
	private static function parse_sitemap( $url, $depth = 0 ) {
		$res = wp_remote_get( $url, array( 'timeout' => 10, 'sslverify' => false ) );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return array();
		}
		$body = wp_remote_retrieve_body( $res );
		if ( ! $body || ! preg_match_all( '#<loc>\s*(.*?)\s*</loc>#i', $body, $m ) ) {
			return array();
		}
		$locs = array_map( 'html_entity_decode', $m[1] );

		// Sitemap index → fetch the child sitemaps (one level deep, capped).
		if ( false !== stripos( $body, '<sitemapindex' ) && $depth < 1 ) {
			$urls = array();
			foreach ( array_slice( $locs, 0, 10 ) as $child ) {
				$urls = array_merge( $urls, self::parse_sitemap( $child, $depth + 1 ) );
				if ( count( $urls ) >= self::MAX_URL ) {
					break;
				}
			}
			return $urls;
		}

		return $locs;
	}
}
