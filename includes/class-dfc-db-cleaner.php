<?php
/**
 * DadsFam Cache — database cleanup (Pro).
 *
 * Trims the usual WordPress fat: revisions, auto-drafts, binned posts,
 * spam/binned comments, expired transients — and runs OPTIMIZE TABLE.
 * Everything uses $wpdb->prefix, so custom prefixes (like wpum_) just work.
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DFC_DB_Cleaner {

	const CRON = 'dfc_db_cleanup';

	public static function tasks() {
		return array(
			'revisions'          => __( 'Post revisions', 'dadsfam-cache' ),
			'auto_drafts'        => __( 'Auto-drafts', 'dadsfam-cache' ),
			'trash_posts'        => __( 'Binned posts', 'dadsfam-cache' ),
			'spam_comments'      => __( 'Spam comments', 'dadsfam-cache' ),
			'trash_comments'     => __( 'Binned comments', 'dadsfam-cache' ),
			'expired_transients' => __( 'Expired transients', 'dadsfam-cache' ),
			'optimize_tables'    => __( 'Optimise database tables', 'dadsfam-cache' ),
		);
	}

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'run_scheduled' ) );
	}

	/** How many rows each task would remove (for the admin UI). */
	public static function count_all() {
		global $wpdb;
		$counts = array();

		$counts['revisions']      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		$counts['auto_drafts']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
		$counts['trash_posts']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );
		$counts['spam_comments']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
		$counts['trash_comments'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );

		$counts['expired_transients'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);

		$counts['optimize_tables'] = count( self::our_tables() );

		return $counts;
	}

	/** Run one named task. Returns the number of items affected. */
	public static function clean( $task ) {
		global $wpdb;

		switch ( $task ) {
			case 'revisions':
				return self::delete_posts_where( "post_type = 'revision'" );

			case 'auto_drafts':
				return self::delete_posts_where( "post_status = 'auto-draft'" );

			case 'trash_posts':
				return self::delete_posts_where( "post_status = 'trash'" );

			case 'spam_comments':
				return self::delete_comments_where( "comment_approved = 'spam'" );

			case 'trash_comments':
				return self::delete_comments_where( "comment_approved = 'trash'" );

			case 'expired_transients':
				$names = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 2000",
						$wpdb->esc_like( '_transient_timeout_' ) . '%',
						time()
					)
				);
				$n = 0;
				foreach ( (array) $names as $timeout ) {
					$key = substr( $timeout, strlen( '_transient_timeout_' ) );
					delete_option( '_transient_' . $key );
					delete_option( $timeout );
					$n++;
				}
				return $n;

			case 'optimize_tables':
				$n = 0;
				foreach ( self::our_tables() as $table ) {
					$wpdb->query( 'OPTIMIZE TABLE `' . str_replace( '`', '', $table ) . '`' ); // phpcs:ignore
					$n++;
				}
				return $n;
		}
		return 0;
	}

	/** Delete posts via wp_delete_post() so meta/terms/children go too. */
	private static function delete_posts_where( $where ) {
		global $wpdb;
		$total = 0;
		do {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE {$where} LIMIT 200" ); // phpcs:ignore
			foreach ( (array) $ids as $id ) {
				wp_delete_post( (int) $id, true );
				$total++;
			}
		} while ( count( $ids ) === 200 && $total < 5000 );
		return $total;
	}

	private static function delete_comments_where( $where ) {
		global $wpdb;
		$total = 0;
		do {
			$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE {$where} LIMIT 200" ); // phpcs:ignore
			foreach ( (array) $ids as $id ) {
				wp_delete_comment( (int) $id, true );
				$total++;
			}
		} while ( count( $ids ) === 200 && $total < 5000 );
		return $total;
	}

	/** Tables belonging to this install's prefix only. */
	private static function our_tables() {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' )
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** Weekly cron entry point. */
	public static function run_scheduled() {
		if ( ! DFC_License::is_pro() || 'weekly' !== DFC_Settings::get( 'db_schedule' ) ) {
			return;
		}
		$tasks = (array) DFC_Settings::get( 'db_tasks' );
		foreach ( $tasks as $task ) {
			if ( array_key_exists( $task, self::tasks() ) ) {
				self::clean( $task );
			}
		}
		update_option( 'dfc_last_db_cleanup', time(), false );
	}
}
