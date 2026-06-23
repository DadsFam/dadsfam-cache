<?php
/**
 * DadsFam Cache — automatic cache purging.
 *
 * Listens to the WordPress events that make cached HTML stale and clears
 * exactly as much as needed: "smart purge" clears the changed post plus the
 * pages that list it; the big hammers (theme switch, updates) clear everything.
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DFC_Purge_Hooks {

	public static function init() {
		// Content changes.
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_post_id' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_id' ) );

		// Comments appearing/disappearing on a post.
		add_action( 'transition_comment_status', array( __CLASS__, 'on_comment_transition' ), 10, 3 );
		add_action( 'comment_post', array( __CLASS__, 'on_comment_post' ), 10, 2 );

		// Site-wide changes → full purge.
		add_action( 'switch_theme', array( 'DFC_Cache_Manager', 'purge_all' ) );
		add_action( 'customize_save_after', array( 'DFC_Cache_Manager', 'purge_all' ) );
		add_action( 'wp_update_nav_menu', array( 'DFC_Cache_Manager', 'purge_all' ) );
		add_action( 'update_option_permalink_structure', array( 'DFC_Cache_Manager', 'purge_all' ) );

		// Core / plugin / theme updates.
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrade' ), 10, 0 );

		// WooCommerce stock changes flip "in stock" badges on shop pages.
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_product' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_product' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_product' ) );
	}

	/** save_post fires for everything — filter the noise first. */
	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post || 'auto-draft' === $post->post_status ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return;
		}
		self::purge_post( $post_id );
	}

	public static function on_post_id( $post_id ) {
		$post = get_post( $post_id );
		if ( $post ) {
			self::on_save_post( $post_id, $post );
		}
	}

	public static function on_comment_transition( $new_status, $old_status, $comment ) {
		if ( $new_status === $old_status || ! $comment ) {
			return;
		}
		if ( 'approved' === $new_status || 'approved' === $old_status ) {
			self::purge_post( (int) $comment->comment_post_ID );
		}
	}

	public static function on_comment_post( $comment_id, $approved ) {
		if ( 1 === (int) $approved ) {
			$comment = get_comment( $comment_id );
			if ( $comment ) {
				self::purge_post( (int) $comment->comment_post_ID );
			}
		}
	}

	public static function on_upgrade() {
		if ( DFC_Settings::get( 'purge_on_update' ) ) {
			DFC_Cache_Manager::purge_all();
		}
	}

	public static function on_product( $product ) {
		$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? $product->get_id() : (int) $product;
		if ( $id ) {
			self::purge_post( $id );
			if ( function_exists( 'wc_get_page_id' ) ) {
				$shop = wc_get_page_id( 'shop' );
				if ( $shop > 0 ) {
					$link = get_permalink( $shop );
					if ( $link ) {
						DFC_Cache_Manager::purge_url( wp_parse_url( $link, PHP_URL_PATH ) );
					}
				}
			}
		}
	}

	/**
	 * Purge a single post — and everywhere it appears — or the whole cache,
	 * depending on the "smart purge" setting.
	 */
	public static function purge_post( $post_id ) {
		if ( ! DFC_Settings::get( 'smart_purge' ) ) {
			DFC_Cache_Manager::purge_all();
			return;
		}

		$paths = array( '/' ); // Home almost always lists recent content.

		$link = get_permalink( $post_id );
		if ( $link ) {
			$paths[] = wp_parse_url( $link, PHP_URL_PATH );
		}

		// Post type archive (e.g. /shop, /blog).
		$type    = get_post_type( $post_id );
		$archive = $type ? get_post_type_archive_link( $type ) : false;
		if ( $archive ) {
			$paths[] = wp_parse_url( $archive, PHP_URL_PATH );
		}

		// Category / tag / custom-taxonomy listings the post sits in.
		foreach ( get_object_taxonomies( $type ?: 'post' ) as $tax ) {
			$terms = get_the_terms( $post_id, $tax );
			if ( is_array( $terms ) ) {
				foreach ( array_slice( $terms, 0, 10 ) as $term ) {
					$tl = get_term_link( $term );
					if ( ! is_wp_error( $tl ) ) {
						$paths[] = wp_parse_url( $tl, PHP_URL_PATH );
					}
				}
			}
		}

		// Posts page if it differs from the front page.
		$blog = (int) get_option( 'page_for_posts' );
		if ( $blog ) {
			$bl = get_permalink( $blog );
			if ( $bl ) {
				$paths[] = wp_parse_url( $bl, PHP_URL_PATH );
			}
		}

		foreach ( array_unique( array_filter( $paths ) ) as $path ) {
			DFC_Cache_Manager::purge_url( $path );
		}

		do_action( 'dfc_purged_post', $post_id, $paths );
	}
}
