<?php
/**
 * DadsFam Cache — admin controller.
 *
 * Menu, asset loading, the AJAX router behind every dashboard button, the
 * settings save handler, admin-bar shortcuts, conflict notices and the small
 * field-rendering helpers the settings view is built from.
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DFC_Admin {

	const PAGE = 'dadsfam-cache';

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_dfc_admin', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_dfc_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_dfc_purge', array( __CLASS__, 'purge_link' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DFC_FILE ), array( __CLASS__, 'row_links' ) );
	}

	/** Admin-bar shortcuts (front + back end). */
	public static function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) {
			return;
		}
		$base = wp_nonce_url( admin_url( 'admin-post.php?action=dfc_purge' ), 'dfc_purge' );

		$bar->add_node( array(
			'id'    => 'dfc',
			'title' => '<span class="ab-icon dashicons dashicons-performance" style="top:2px;"></span>DF Cache',
			'href'  => admin_url( 'admin.php?page=' . self::PAGE ),
		) );
		$bar->add_node( array(
			'id'     => 'dfc-purge-all',
			'parent' => 'dfc',
			'title'  => __( 'Clear all cache', 'dadsfam-cache' ),
			'href'   => $base . '&scope=all',
		) );
		if ( ! is_admin() ) {
			$path = isset( $_SERVER['REQUEST_URI'] ) ? rawurlencode( (string) $_SERVER['REQUEST_URI'] ) : '';
			$bar->add_node( array(
				'id'     => 'dfc-purge-page',
				'parent' => 'dfc',
				'title'  => __( 'Clear this page', 'dadsfam-cache' ),
				'href'   => $base . '&scope=page&path=' . $path,
			) );
		}
		$bar->add_node( array(
			'id'     => 'dfc-settings',
			'parent' => 'dfc',
			'title'  => __( 'Settings', 'dadsfam-cache' ),
			'href'   => admin_url( 'admin.php?page=' . self::PAGE ),
		) );
	}

	public static function menu() {
		self::$hook = add_menu_page(
			__( 'DadsFam Cache', 'dadsfam-cache' ),
			__( 'DF Cache', 'dadsfam-cache' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-performance',
			81
		);
	}

	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'dfc-admin', DFC_URL . 'assets/admin.css', array(), DFC_VERSION );
		wp_enqueue_script( 'dfc-admin', DFC_URL . 'assets/admin.js', array(), DFC_VERSION, true );
		wp_localize_script( 'dfc-admin', 'dfcData', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'dfc_admin' ),
			'isPro'   => DFC_License::is_pro(),
			'buyUrl'  => DFC_BUY_URL,
			'strings' => array(
				'working'       => __( 'Working…', 'dadsfam-cache' ),
				'done'          => __( 'Done!', 'dadsfam-cache' ),
				'error'         => __( 'Eish, something went wrong. Try again.', 'dadsfam-cache' ),
				'confirmPurge'  => __( 'Clear the entire cache? Visitors will get fresh pages (slightly slower until it rebuilds).', 'dadsfam-cache' ),
				'confirmClean'  => __( 'This permanently deletes the selected database items. Continue?', 'dadsfam-cache' ),
				'confirmImport' => __( 'Importing will overwrite your current settings. Continue?', 'dadsfam-cache' ),
			),
		) );
	}

	public static function render() {
		require DFC_DIR . 'includes/views/admin-page.php';
	}

	/* ------------------------------------------------------------------ */
	/* Settings save                                                       */
	/* ------------------------------------------------------------------ */

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'dadsfam-cache' ) );
		}
		check_admin_referer( 'dfc_save' );

		$raw   = isset( $_POST['dfc'] ) && is_array( $_POST['dfc'] ) ? wp_unslash( $_POST['dfc'] ) : array(); // phpcs:ignore
		$clean = DFC_Settings::sanitize( $raw, DFC_License::is_pro() );
		DFC_Settings::update( $clean );

		// Keep the drop-in config + .htaccess block in sync with the new settings.
		DFC_Cache_Manager::write_config();
		if ( DFC_Htaccess::is_apache() ) {
			if ( $clean['browser_cache'] || $clean['htaccess_gzip'] ) {
				DFC_Htaccess::apply();
			} else {
				DFC_Htaccess::remove();
			}
		}

		// Cached pages were built with the old settings — bin them.
		DFC_Cache_Manager::purge_all();

		$tab = isset( $_POST['dfc_tab'] ) ? sanitize_key( (string) $_POST['dfc_tab'] ) : 'dashboard';
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $tab . '&updated=1' ) );
		exit;
	}

	/** Admin-bar purge links (GET + nonce). */
	public static function purge_link() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'dadsfam-cache' ) );
		}
		check_admin_referer( 'dfc_purge' );

		$scope = isset( $_GET['scope'] ) ? sanitize_key( (string) $_GET['scope'] ) : 'all';
		if ( 'page' === $scope && ! empty( $_GET['path'] ) ) {
			DFC_Cache_Manager::purge_url( rawurldecode( (string) $_GET['path'] ) ); // phpcs:ignore
		} else {
			DFC_Cache_Manager::purge_all();
		}

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX router                                                         */
	/* ------------------------------------------------------------------ */

	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dadsfam-cache' ) ), 403 );
		}
		check_ajax_referer( 'dfc_admin', 'nonce' );

		$task = isset( $_POST['task'] ) ? sanitize_key( (string) $_POST['task'] ) : '';

		switch ( $task ) {

			case 'purge_all':
				DFC_Cache_Manager::purge_all();
				wp_send_json_success( array(
					'message' => __( 'Cache cleared. Fresh pages coming up.', 'dadsfam-cache' ),
					'stats'   => DFC_Cache_Manager::stats(),
				) );
				break; // Unreachable, kept for readability.

			case 'cache_stats':
				wp_send_json_success( array( 'stats' => DFC_Cache_Manager::stats() ) );
				break;

			case 'cache_test':
				wp_send_json_success( self::cache_test() );
				break;

			case 'speed_setup':
				wp_send_json_success( self::speed_setup() );
				break;

			case 'preload_start':
				$res = DFC_Preload::start();
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ) );
				}
				wp_send_json_success( array(
					/* translators: %d = number of URLs queued */
					'message' => sprintf( __( 'Preload started — %d pages queued.', 'dadsfam-cache' ), $res ),
					'status'  => DFC_Preload::status(),
				) );
				break;

			case 'preload_stop':
				DFC_Preload::stop();
				wp_send_json_success( array( 'message' => __( 'Preload stopped.', 'dadsfam-cache' ) ) );
				break;

			case 'preload_status':
				// Nudge the queue along too, in case WP-Cron is sleepy.
				if ( DFC_Preload::is_running() && ! wp_next_scheduled( DFC_Preload::CRON ) ) {
					wp_schedule_single_event( time() + 1, DFC_Preload::CRON );
				}
				spawn_cron();
				wp_send_json_success( array( 'status' => DFC_Preload::status() ) );
				break;

			case 'license_activate':
				$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : '';
				$res = DFC_License::activate( $key );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ) );
				}
				if ( 'active' !== $res['status'] ) {
					wp_send_json_error( array( 'message' => $res['message'] ) );
				}
				wp_send_json_success( array( 'message' => $res['message'], 'license' => $res ) );
				break;

			case 'license_deactivate':
				DFC_License::deactivate();
				wp_send_json_success( array( 'message' => __( 'License removed from this site.', 'dadsfam-cache' ) ) );
				break;

			case 'db_counts':
				wp_send_json_success( array( 'counts' => DFC_DB_Cleaner::count_all() ) );
				break;

			case 'db_clean':
				if ( ! DFC_License::is_pro() ) {
					wp_send_json_error( array( 'message' => __( 'Database cleanup is a Pro feature.', 'dadsfam-cache' ) ) );
				}
				$job = isset( $_POST['job'] ) ? sanitize_key( (string) $_POST['job'] ) : '';
				if ( ! array_key_exists( $job, DFC_DB_Cleaner::tasks() ) ) {
					wp_send_json_error( array( 'message' => __( 'Unknown cleanup task.', 'dadsfam-cache' ) ) );
				}
				$n = DFC_DB_Cleaner::clean( $job );
				wp_send_json_success( array(
					/* translators: %d = items removed */
					'message' => sprintf( __( 'Sorted — %d items cleaned.', 'dadsfam-cache' ), $n ),
					'counts'  => DFC_DB_Cleaner::count_all(),
				) );
				break;

			case 'install_dropin':
				$force = ! empty( $_POST['force'] );
				$res   = DFC_Dropin::install( $force );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array(
						'message'   => $res->get_error_message(),
						'can_force' => 'dfc_foreign_dropin' === $res->get_error_code(),
					) );
				}
				DFC_Cache_Manager::ensure_dirs();
				DFC_Cache_Manager::write_config();
				wp_send_json_success( array(
					'message' => __( 'Page caching engine installed.', 'dadsfam-cache' ),
					'status'  => DFC_Dropin::status(),
				) );
				break;

			case 'remove_dropin':
				DFC_Dropin::remove();
				DFC_Dropin::remove_wp_cache();
				wp_send_json_success( array(
					'message' => __( 'Caching engine removed.', 'dadsfam-cache' ),
					'status'  => DFC_Dropin::status(),
				) );
				break;

			case 'apply_htaccess':
				$ok = DFC_Htaccess::apply();
				$ok
					? wp_send_json_success( array( 'message' => __( 'Browser caching rules added to .htaccess.', 'dadsfam-cache' ) ) )
					: wp_send_json_error( array( 'message' => __( 'Could not write to .htaccess — check file permissions.', 'dadsfam-cache' ) ) );
				break;

			case 'remove_htaccess':
				DFC_Htaccess::remove();
				wp_send_json_success( array( 'message' => __( 'Rules removed from .htaccess.', 'dadsfam-cache' ) ) );
				break;

			case 'export_settings':
				wp_send_json_success( array(
					'json' => wp_json_encode( array(
						'plugin'   => 'dadsfam-cache',
						'version'  => DFC_VERSION,
						'settings' => DFC_Settings::all(),
					), JSON_PRETTY_PRINT ),
				) );
				break;

			case 'import_settings':
				$json = isset( $_POST['json'] ) ? wp_unslash( (string) $_POST['json'] ) : ''; // phpcs:ignore
				$data = json_decode( $json, true );
				if ( ! is_array( $data ) || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
					wp_send_json_error( array( 'message' => __( 'That file does not look like a DadsFam Cache export.', 'dadsfam-cache' ) ) );
				}
				DFC_Settings::update( DFC_Settings::sanitize( $data['settings'], DFC_License::is_pro() ) );
				DFC_Cache_Manager::write_config();
				DFC_Cache_Manager::purge_all();
				wp_send_json_success( array( 'message' => __( 'Settings imported. Reloading…', 'dadsfam-cache' ) ) );
				break;

			case 'image_caps':
				wp_send_json_success( array(
					'caps'   => DFC_Images::capabilities(),
					'counts' => DFC_Images::counts(),
				) );
				break;

			case 'image_convert':
				if ( ! DFC_License::is_pro() ) {
					wp_send_json_error( array( 'message' => __( 'Image conversion is a Pro feature.', 'dadsfam-cache' ) ) );
				}
				$res = DFC_Images::convert_batch( (int) DFC_Settings::get( 'webp_quality' ) );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ) );
				}
				wp_send_json_success( array(
					'converted' => $res['converted'],
					'remaining' => $res['remaining'],
					'counts'    => DFC_Images::counts(),
				) );
				break;

			case 'image_clear':
				if ( ! DFC_License::is_pro() ) {
					wp_send_json_error( array( 'message' => __( 'Image conversion is a Pro feature.', 'dadsfam-cache' ) ) );
				}
				$n = DFC_Images::delete_all();
				wp_send_json_success( array(
					/* translators: %d = files removed */
					'message' => sprintf( __( 'Removed %d WebP files.', 'dadsfam-cache' ), $n ),
					'counts'  => DFC_Images::counts(),
				) );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Unknown task.', 'dadsfam-cache' ) ) );
		}
	}

	/** Fetch the homepage twice and report the cache header. */
	private static function cache_test() {
		$args = array(
			'timeout'    => 15,
			'sslverify'  => false,
			'user-agent' => 'DadsFamCache/' . DFC_VERSION . ' SelfTest',
		);
		$url = home_url( '/' );

		$first = wp_remote_get( $url, $args );
		if ( is_wp_error( $first ) ) {
			return array( 'ok' => false, 'message' => __( 'Could not reach your homepage from the server (loopback blocked?).', 'dadsfam-cache' ) );
		}
		usleep( 400000 );
		$second = wp_remote_get( $url, $args );
		if ( is_wp_error( $second ) ) {
			return array( 'ok' => false, 'message' => __( 'Second request failed — try again in a moment.', 'dadsfam-cache' ) );
		}

		$h1 = wp_remote_retrieve_header( $first, 'x-dadsfam-cache' );
		$h2 = wp_remote_retrieve_header( $second, 'x-dadsfam-cache' );

		if ( 'HIT' === strtoupper( (string) $h2 ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'Caching is working! Second visit was served straight from cache. Lekker fast. 🚀', 'dadsfam-cache' ),
				'detail'  => sprintf( 'Visit 1: %s → Visit 2: %s', $h1 ?: 'MISS', $h2 ),
			);
		}
		if ( $h1 || $h2 ) {
			return array(
				'ok'      => false,
				'message' => __( 'The plugin responded but no cache HIT yet. Save settings, then test again.', 'dadsfam-cache' ),
				'detail'  => sprintf( 'Visit 1: %s → Visit 2: %s', $h1 ?: '—', $h2 ?: '—' ),
			);
		}
		return array(
			'ok'      => false,
			'message' => __( 'No cache headers found. Run One-Click Speed Setup on the Dashboard tab first.', 'dadsfam-cache' ),
		);
	}

	/** The big friendly button: apply known-safe settings in one go. */
	private static function speed_setup() {
		$done = array();
		$s    = DFC_Settings::all();

		$s['enabled']         = 1;
		$s['gzip']            = 1;
		$s['smart_purge']     = 1;
		$s['purge_on_update'] = 1;
		$done[]               = __( 'Page caching turned on', 'dadsfam-cache' );
		$done[]               = __( 'Gzip pre-compression on', 'dadsfam-cache' );
		$done[]               = __( 'Smart auto-purge on', 'dadsfam-cache' );

		if ( DFC_Htaccess::is_apache() ) {
			$s['browser_cache'] = 1;
			$s['htaccess_gzip'] = 1;
		}

		if ( DFC_License::is_pro() ) {
			$s['minify_html']       = 1;
			$s['minify_inline_css'] = 1;
			$s['lazyload']          = 1;
			$s['lazyload_iframes']  = 1;
			$s['heartbeat']         = 'slow';
			$s['optimize_lcp']      = 1; // Auto-targets the first image — safe.
			$s['font_optimize']     = 1; // display=swap — safe.
			$s['prefetch_links']    = 1; // Hover prefetch — safe.
			$done[]                 = __( 'Pro: HTML + inline CSS minify on', 'dadsfam-cache' );
			$done[]                 = __( 'Pro: lazy-loading images & iframes on', 'dadsfam-cache' );
			$done[]                 = __( 'Pro: LCP image prioritised (better Core Web Vitals)', 'dadsfam-cache' );
			$done[]                 = __( 'Pro: fonts set to swap + links prefetched on hover', 'dadsfam-cache' );
			$done[]                 = __( 'Pro: Heartbeat slowed to save server power', 'dadsfam-cache' );

			if ( DFC_Images::capabilities()['webp'] ) {
				$s['serve_webp'] = 1;
				$s['auto_webp']  = 1;
				$done[]          = __( 'Pro: WebP serving on (run "Convert images" on the Images tab for existing photos)', 'dadsfam-cache' );
			}
		}

		DFC_Settings::update( $s );

		$install = DFC_Dropin::install( false );
		if ( is_wp_error( $install ) ) {
			$done[] = '⚠ ' . $install->get_error_message();
		} else {
			$done[] = __( 'Caching engine (drop-in) installed', 'dadsfam-cache' );
		}

		DFC_Cache_Manager::ensure_dirs();
		DFC_Cache_Manager::write_config();

		if ( DFC_Htaccess::is_apache() && DFC_Htaccess::apply() ) {
			$done[] = __( 'Browser caching rules written to .htaccess', 'dadsfam-cache' );
		}

		DFC_Cache_Manager::purge_all();

		return array(
			'message' => __( 'Speed setup complete! Your site is now caching. 🚀', 'dadsfam-cache' ),
			'done'    => $done,
			'status'  => DFC_Dropin::status(),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Notices + row links                                                 */
	/* ------------------------------------------------------------------ */

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$conflicts = DFC_Dropin::active_conflicts();
		if ( ! $conflicts ) {
			return;
		}
		$hash = md5( implode( '|', $conflicts ) );
		if ( get_option( 'dfc_conflict_dismissed' ) === $hash && empty( $_GET['page'] ) ) {
			return;
		}
		// Only nag site-wide once; always show on our own page.
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_ours = $screen && false !== strpos( (string) $screen->id, self::PAGE );
		if ( ! $on_ours && get_option( 'dfc_conflict_dismissed' ) === $hash ) {
			return;
		}
		if ( isset( $_GET['dfc_dismiss_conflict'] ) && check_admin_referer( 'dfc_dismiss' ) ) {
			update_option( 'dfc_conflict_dismissed', $hash, false );
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <em>%s</em>. %s <a href="%s">%s</a> · <a href="%s">%s</a></p></div>',
			esc_html__( 'DadsFam Cache:', 'dadsfam-cache' ),
			esc_html__( 'another caching plugin is active —', 'dadsfam-cache' ),
			esc_html( implode( ', ', $conflicts ) ),
			esc_html__( 'Two cache plugins fight over the same engine and slow each other down. Please deactivate the other one.', 'dadsfam-cache' ),
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Open Plugins', 'dadsfam-cache' ),
			esc_url( wp_nonce_url( add_query_arg( 'dfc_dismiss_conflict', 1 ), 'dfc_dismiss' ) ),
			esc_html__( 'Dismiss', 'dadsfam-cache' )
		);
	}

	public static function row_links( $links ) {
		$mine = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'dadsfam-cache' ) . '</a>',
		);
		if ( ! DFC_License::is_pro() ) {
			$mine[] = '<a href="' . esc_url( DFC_BUY_URL ) . '" target="_blank" style="color:#1a4fa0;font-weight:600;">' . esc_html__( 'Go Pro ⚡', 'dadsfam-cache' ) . '</a>';
		}
		return array_merge( $mine, $links );
	}

	/* ------------------------------------------------------------------ */
	/* Field renderers (used by the view)                                  */
	/* ------------------------------------------------------------------ */

	public static function locked( $pro_field ) {
		return $pro_field && ! DFC_License::is_pro();
	}

	public static function field_toggle( $key, $label, $desc = '', $pro = false ) {
		$locked = self::locked( $pro );
		$value  = (bool) DFC_Settings::get( $key );
		?>
		<div class="dfc-field dfc-field-toggle <?php echo $locked ? 'dfc-locked' : ''; ?>">
			<?php if ( $locked ) : ?><input type="hidden" name="dfc[<?php echo esc_attr( $key ); ?>]" value="<?php echo $value ? 1 : 0; ?>"><?php endif; ?>
			<label class="dfc-switch">
				<input type="checkbox" name="dfc[<?php echo esc_attr( $key ); ?>]" value="1"
					<?php checked( $value && ! $locked ); ?> <?php disabled( $locked ); ?>>
				<span class="dfc-slider" aria-hidden="true"></span>
			</label>
			<div class="dfc-field-text">
				<span class="dfc-field-label"><?php echo esc_html( $label ); ?>
					<?php if ( $pro ) : ?><span class="dfc-chip <?php echo $locked ? 'dfc-chip-locked' : 'dfc-chip-pro'; ?>">PRO</span><?php endif; ?>
				</span>
				<?php if ( $desc ) : ?><span class="dfc-field-desc"><?php echo esc_html( $desc ); ?></span><?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function field_number( $key, $label, $desc = '', $min = 0, $max = 9999, $pro = false ) {
		$locked = self::locked( $pro );
		?>
		<div class="dfc-field <?php echo $locked ? 'dfc-locked' : ''; ?>">
			<div class="dfc-field-text">
				<label class="dfc-field-label" for="dfc-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?>
					<?php if ( $pro ) : ?><span class="dfc-chip <?php echo $locked ? 'dfc-chip-locked' : 'dfc-chip-pro'; ?>">PRO</span><?php endif; ?>
				</label>
				<?php if ( $desc ) : ?><span class="dfc-field-desc"><?php echo esc_html( $desc ); ?></span><?php endif; ?>
			</div>
			<?php if ( $locked ) : ?><input type="hidden" name="dfc[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( DFC_Settings::get( $key ) ); ?>"><?php endif; ?>
			<input class="dfc-input dfc-input-num" type="number" id="dfc-<?php echo esc_attr( $key ); ?>"
				name="dfc[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( DFC_Settings::get( $key ) ); ?>"
				min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" <?php disabled( $locked ); ?>>
		</div>
		<?php
	}

	public static function field_text( $key, $label, $desc = '', $placeholder = '', $pro = false ) {
		$locked = self::locked( $pro );
		?>
		<div class="dfc-field dfc-field-col <?php echo $locked ? 'dfc-locked' : ''; ?>">
			<label class="dfc-field-label" for="dfc-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?>
				<?php if ( $pro ) : ?><span class="dfc-chip <?php echo $locked ? 'dfc-chip-locked' : 'dfc-chip-pro'; ?>">PRO</span><?php endif; ?>
			</label>
			<?php if ( $desc ) : ?><span class="dfc-field-desc"><?php echo esc_html( $desc ); ?></span><?php endif; ?>
			<?php if ( $locked ) : ?><input type="hidden" name="dfc[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( DFC_Settings::get( $key ) ); ?>"><?php endif; ?>
			<input class="dfc-input" type="text" id="dfc-<?php echo esc_attr( $key ); ?>"
				name="dfc[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( DFC_Settings::get( $key ) ); ?>"
				placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php disabled( $locked ); ?>>
		</div>
		<?php
	}

	public static function field_textarea( $key, $label, $desc = '', $placeholder = '', $pro = false ) {
		$locked = self::locked( $pro );
		?>
		<div class="dfc-field dfc-field-col <?php echo $locked ? 'dfc-locked' : ''; ?>">
			<label class="dfc-field-label" for="dfc-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?>
				<?php if ( $pro ) : ?><span class="dfc-chip <?php echo $locked ? 'dfc-chip-locked' : 'dfc-chip-pro'; ?>">PRO</span><?php endif; ?>
			</label>
			<?php if ( $desc ) : ?><span class="dfc-field-desc"><?php echo esc_html( $desc ); ?></span><?php endif; ?>
			<?php if ( $locked ) : ?><input type="hidden" name="dfc[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( DFC_Settings::get( $key ) ); ?>"><?php endif; ?>
			<textarea class="dfc-input dfc-textarea" rows="4" id="dfc-<?php echo esc_attr( $key ); ?>"
				name="dfc[<?php echo esc_attr( $key ); ?>]" placeholder="<?php echo esc_attr( $placeholder ); ?>"
				<?php disabled( $locked ); ?>><?php echo esc_textarea( DFC_Settings::get( $key ) ); ?></textarea>
		</div>
		<?php
	}

	public static function field_select( $key, $label, $desc, $choices, $pro = false ) {
		$locked  = self::locked( $pro );
		$current = DFC_Settings::get( $key );
		?>
		<div class="dfc-field <?php echo $locked ? 'dfc-locked' : ''; ?>">
			<div class="dfc-field-text">
				<label class="dfc-field-label" for="dfc-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?>
					<?php if ( $pro ) : ?><span class="dfc-chip <?php echo $locked ? 'dfc-chip-locked' : 'dfc-chip-pro'; ?>">PRO</span><?php endif; ?>
				</label>
				<?php if ( $desc ) : ?><span class="dfc-field-desc"><?php echo esc_html( $desc ); ?></span><?php endif; ?>
			</div>
			<?php if ( $locked ) : ?><input type="hidden" name="dfc[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $current ); ?>"><?php endif; ?>
			<select class="dfc-input" id="dfc-<?php echo esc_attr( $key ); ?>" name="dfc[<?php echo esc_attr( $key ); ?>]" <?php disabled( $locked ); ?>>
				<?php foreach ( $choices as $val => $text ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current, $val ); ?>><?php echo esc_html( $text ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}
}
