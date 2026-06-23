<?php
/**
 * DadsFam Cache — settings page view.
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfc_is_pro   = DFC_License::is_pro();
$dfc_license  = DFC_License::data();
$dfc_stats    = DFC_Cache_Manager::stats();
$dfc_engine   = DFC_Dropin::status();
$dfc_htstatus = DFC_Htaccess::status();
$dfc_apache   = DFC_Htaccess::is_apache();
$dfc_settings = DFC_Settings::all();
$dfc_tab      = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'dashboard'; // phpcs:ignore
$dfc_caching  = $dfc_settings['enabled'] && $dfc_engine['dropin_ours'] && $dfc_engine['wp_cache_on'];

$dfc_tabs = array(
	'dashboard'  => array( '🏠', __( 'Dashboard', 'dadsfam-cache' ), false ),
	'caching'    => array( '⚡', __( 'Caching', 'dadsfam-cache' ), false ),
	'exclusions' => array( '🚫', __( 'Exclusions', 'dadsfam-cache' ), false ),
	'speed'      => array( '🚀', __( 'Speed', 'dadsfam-cache' ), true ),
	'cdn'        => array( '🌍', __( 'CDN', 'dadsfam-cache' ), true ),
	'database'   => array( '🗄️', __( 'Database', 'dadsfam-cache' ), true ),
	'images'     => array( '🖼️', __( 'Images', 'dadsfam-cache' ), true ),
	'preload'    => array( '🔥', __( 'Preload', 'dadsfam-cache' ), false ),
	'license'    => array( '🔑', __( 'License', 'dadsfam-cache' ), false ),
	'tools'      => array( '🧰', __( 'Tools', 'dadsfam-cache' ), false ),
);
if ( ! isset( $dfc_tabs[ $dfc_tab ] ) ) {
	$dfc_tab = 'dashboard';
}

/** Small status pill helper. */
$dfc_pill = function ( $ok, $on_text, $off_text ) {
	printf(
		'<span class="dfc-pill %s"><span class="dfc-dot"></span>%s</span>',
		$ok ? 'dfc-pill-on' : 'dfc-pill-off',
		esc_html( $ok ? $on_text : $off_text )
	);
};
?>
<div class="wrap dfc-wrap" id="dfc-app">

	<?php if ( ! empty( $_GET['updated'] ) ) : // phpcs:ignore ?>
		<div class="dfc-toast dfc-toast-show dfc-toast-ok" id="dfc-saved-toast">✅ <?php esc_html_e( 'Settings saved. Cache cleared so changes show immediately.', 'dadsfam-cache' ); ?></div>
	<?php endif; ?>

	<!-- ============ Gradient header / pre-flight checklist ============ -->
	<div class="dfc-hero">
		<div class="dfc-hero-left">
			<h1 class="dfc-logo">⚡ DadsFam <span>Cache</span></h1>
			<p class="dfc-tagline"><?php esc_html_e( 'Make your site lekker fast — no rocket science required.', 'dadsfam-cache' ); ?></p>
			<div class="dfc-pills">
				<?php
				$dfc_pill( $dfc_caching, __( 'Caching: ON', 'dadsfam-cache' ), __( 'Caching: OFF', 'dadsfam-cache' ) );
				$dfc_pill( $dfc_engine['dropin_ours'], __( 'Engine: installed', 'dadsfam-cache' ), __( 'Engine: not installed', 'dadsfam-cache' ) );
				if ( $dfc_apache ) {
					$dfc_pill( $dfc_htstatus['applied'], __( 'Browser cache: ON', 'dadsfam-cache' ), __( 'Browser cache: OFF', 'dadsfam-cache' ) );
				}
				$dfc_pill( $dfc_is_pro, __( 'PRO: active', 'dadsfam-cache' ), __( 'Free version', 'dadsfam-cache' ) );
				?>
			</div>
		</div>
		<div class="dfc-hero-right">
			<button type="button" class="dfc-btn dfc-btn-hero" id="dfc-speed-setup">
				🚀 <?php esc_html_e( 'One-Click Speed Setup', 'dadsfam-cache' ); ?>
			</button>
			<span class="dfc-hero-hint"><?php esc_html_e( 'Applies safe, recommended settings in one go.', 'dadsfam-cache' ); ?></span>
		</div>
	</div>

	<?php if ( $dfc_engine['conflicts'] ) : ?>
		<div class="dfc-card dfc-card-warn">
			<strong>⚠️ <?php esc_html_e( 'Heads up:', 'dadsfam-cache' ); ?></strong>
			<?php
			printf(
				/* translators: %s = plugin names */
				esc_html__( 'Another caching plugin is running (%s). Deactivate it first — two cache plugins on one site is like two hands on one steering wheel.', 'dadsfam-cache' ),
				'<em>' . esc_html( implode( ', ', $dfc_engine['conflicts'] ) ) . '</em>'
			);
			?>
			<a class="dfc-btn dfc-btn-small" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Open Plugins', 'dadsfam-cache' ); ?></a>
		</div>
	<?php endif; ?>

	<div class="dfc-layout">

		<!-- ============ Tab rail ============ -->
		<nav class="dfc-nav" role="tablist">
			<?php foreach ( $dfc_tabs as $slug => $t ) : ?>
				<button type="button" class="dfc-nav-item <?php echo $dfc_tab === $slug ? 'dfc-nav-active' : ''; ?>"
					data-tab="<?php echo esc_attr( $slug ); ?>" role="tab">
					<span class="dfc-nav-emoji"><?php echo esc_html( $t[0] ); ?></span>
					<span><?php echo esc_html( $t[1] ); ?></span>
					<?php if ( $t[2] && ! $dfc_is_pro ) : ?><span class="dfc-chip dfc-chip-locked">PRO</span><?php endif; ?>
				</button>
			<?php endforeach; ?>
		</nav>

		<!-- ============ Panels ============ -->
		<div class="dfc-main">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="dfc-form">
			<?php wp_nonce_field( 'dfc_save' ); ?>
			<input type="hidden" name="action" value="dfc_save">
			<input type="hidden" name="dfc_tab" id="dfc-tab-field" value="<?php echo esc_attr( $dfc_tab ); ?>">

			<!-- ===== Dashboard ===== -->
			<section class="dfc-panel <?php echo 'dashboard' === $dfc_tab ? 'dfc-active' : ''; ?>" data-panel="dashboard">
				<div class="dfc-grid-3">
					<div class="dfc-stat"><span class="dfc-stat-num" id="dfc-stat-pages"><?php echo esc_html( number_format_i18n( $dfc_stats['pages'] ) ); ?></span><span class="dfc-stat-label"><?php esc_html_e( 'Pages cached', 'dadsfam-cache' ); ?></span></div>
					<div class="dfc-stat"><span class="dfc-stat-num" id="dfc-stat-size"><?php echo esc_html( $dfc_stats['human'] ); ?></span><span class="dfc-stat-label"><?php esc_html_e( 'Cache size', 'dadsfam-cache' ); ?></span></div>
					<div class="dfc-stat"><span class="dfc-stat-num" id="dfc-stat-purge"><?php
						$last = (int) get_option( 'dfc_last_purge_all' );
						echo $last ? esc_html( human_time_diff( $last ) . ' ' . __( 'ago', 'dadsfam-cache' ) ) : '—';
					?></span><span class="dfc-stat-label"><?php esc_html_e( 'Last full clear', 'dadsfam-cache' ); ?></span></div>
				</div>

				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Quick actions', 'dadsfam-cache' ); ?></h2>
					<div class="dfc-actions">
						<button type="button" class="dfc-btn dfc-btn-primary" id="dfc-purge-all">🧹 <?php esc_html_e( 'Clear All Cache', 'dadsfam-cache' ); ?></button>
						<button type="button" class="dfc-btn" id="dfc-cache-test">🩺 <?php esc_html_e( 'Test My Cache', 'dadsfam-cache' ); ?></button>
						<button type="button" class="dfc-btn" data-goto="preload">🔥 <?php esc_html_e( 'Preload Cache', 'dadsfam-cache' ); ?></button>
					</div>
					<div class="dfc-result" id="dfc-dash-result" hidden></div>
				</div>

				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Engine checklist', 'dadsfam-cache' ); ?></h2>
					<p class="dfc-muted"><?php esc_html_e( 'These three things make page caching work. Green = good.', 'dadsfam-cache' ); ?></p>
					<ul class="dfc-checklist">
						<li class="<?php echo $dfc_engine['dropin_ours'] ? 'dfc-ok' : 'dfc-bad'; ?>">
							<?php esc_html_e( 'Caching engine (advanced-cache.php) installed', 'dadsfam-cache' ); ?>
							<?php if ( ! $dfc_engine['dropin_ours'] ) : ?>
								<button type="button" class="dfc-btn dfc-btn-small" id="dfc-install-dropin"><?php esc_html_e( 'Install now', 'dadsfam-cache' ); ?></button>
							<?php endif; ?>
						</li>
						<li class="<?php echo $dfc_engine['wp_cache_on'] ? 'dfc-ok' : 'dfc-bad'; ?>">
							<?php esc_html_e( 'WP_CACHE switched on in wp-config.php', 'dadsfam-cache' ); ?>
						</li>
						<li class="<?php echo $dfc_settings['enabled'] ? 'dfc-ok' : 'dfc-bad'; ?>">
							<?php esc_html_e( 'Page caching enabled in settings', 'dadsfam-cache' ); ?>
						</li>
						<?php if ( $dfc_apache ) : ?>
							<li class="<?php echo $dfc_htstatus['applied'] ? 'dfc-ok' : 'dfc-mid'; ?>">
								<?php esc_html_e( 'Browser caching rules in .htaccess (optional but recommended)', 'dadsfam-cache' ); ?>
							</li>
						<?php endif; ?>
					</ul>
					<div class="dfc-result" id="dfc-engine-result" hidden></div>
				</div>

				<?php if ( ! $dfc_is_pro ) : ?>
					<div class="dfc-card dfc-card-upsell">
						<h2 class="dfc-card-title">⚡ <?php esc_html_e( 'Unlock DadsFam Cache PRO', 'dadsfam-cache' ); ?></h2>
						<p><?php esc_html_e( 'Minification, lazy-loading, delayed JavaScript, CDN, database cleanup and auto-preload — the stuff that turns "fast" into "stupid fast".', 'dadsfam-cache' ); ?></p>
						<div class="dfc-actions">
							<a class="dfc-btn dfc-btn-primary" href="<?php echo esc_url( DFC_BUY_URL ); ?>" target="_blank"><?php esc_html_e( 'Get a license', 'dadsfam-cache' ); ?></a>
							<button type="button" class="dfc-btn" data-goto="license"><?php esc_html_e( 'I already have a key', 'dadsfam-cache' ); ?></button>
						</div>
					</div>
				<?php endif; ?>
			</section>

			<!-- ===== Caching ===== -->
			<section class="dfc-panel <?php echo 'caching' === $dfc_tab ? 'dfc-active' : ''; ?>" data-panel="caching">
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Page caching', 'dadsfam-cache' ); ?></h2>
					<?php
					DFC_Admin::field_toggle( 'enabled', __( 'Enable page caching', 'dadsfam-cache' ), __( 'Saves a ready-made copy of every page so repeat visits skip PHP and the database entirely.', 'dadsfam-cache' ) );
					DFC_Admin::field_number( 'lifetime_hours', __( 'Cache lifetime (hours)', 'dadsfam-cache' ), __( 'How long a cached page stays fresh. 0 = keep until something changes.', 'dadsfam-cache' ), 0, 8760 );
					DFC_Admin::field_toggle( 'gzip', __( 'Pre-compress pages (gzip)', 'dadsfam-cache' ), __( 'Stores a squashed copy too, so the server can send pages ~70% smaller without thinking.', 'dadsfam-cache' ) );
					DFC_Admin::field_toggle( 'separate_mobile', __( 'Separate mobile cache', 'dadsfam-cache' ), __( 'Only turn this on if your theme shows genuinely different HTML to phones. Most modern themes do not need it.', 'dadsfam-cache' ) );
					?>
				</div>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Automatic clearing', 'dadsfam-cache' ); ?></h2>
					<?php
					DFC_Admin::field_toggle( 'smart_purge', __( 'Smart purge', 'dadsfam-cache' ), __( 'When you edit a post, only that post, the homepage and its category pages are cleared — not the whole cache.', 'dadsfam-cache' ) );
					DFC_Admin::field_toggle( 'purge_on_update', __( 'Clear cache after updates', 'dadsfam-cache' ), __( 'Plugin, theme or WordPress update? The cache clears itself so nothing looks broken.', 'dadsfam-cache' ) );
					?>
				</div>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Browser caching', 'dadsfam-cache' ); ?></h2>
					<?php if ( $dfc_apache ) : ?>
						<?php
						DFC_Admin::field_toggle( 'browser_cache', __( 'Browser caching rules (.htaccess)', 'dadsfam-cache' ), __( 'Tells visitors’ browsers to keep your images, CSS and JS for months instead of re-downloading them.', 'dadsfam-cache' ) );
						DFC_Admin::field_toggle( 'htaccess_gzip', __( 'Server compression rules (.htaccess)', 'dadsfam-cache' ), __( 'Asks Apache to gzip everything it sends. Free speed.', 'dadsfam-cache' ) );
						?>
					<?php else : ?>
						<p class="dfc-muted"><?php esc_html_e( 'You appear to be on NGINX, so .htaccess rules will not apply. Paste this into your server config instead (or send it to your host):', 'dadsfam-cache' ); ?></p>
						<textarea class="dfc-input dfc-textarea dfc-code" rows="7" readonly id="dfc-nginx"><?php echo esc_textarea( DFC_Htaccess::nginx_snippet() ); ?></textarea>
						<button type="button" class="dfc-btn dfc-btn-small" data-copy="#dfc-nginx"><?php esc_html_e( 'Copy snippet', 'dadsfam-cache' ); ?></button>
					<?php endif; ?>
				</div>
			</section>

			<!-- ===== Exclusions ===== -->
			<section class="dfc-panel <?php echo 'exclusions' === $dfc_tab ? 'dfc-active' : ''; ?>" data-panel="exclusions">
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Never cache these', 'dadsfam-cache' ); ?></h2>
					<p class="dfc-muted"><?php esc_html_e( 'One entry per line. Logged-in users, the cart, checkout and wp-admin are already excluded automatically — you can leave all of this alone.', 'dadsfam-cache' ); ?></p>
					<?php
					DFC_Admin::field_textarea( 'exclude_uris', __( 'URLs', 'dadsfam-cache' ), __( 'Use * as a wildcard, e.g. /my-account/*', 'dadsfam-cache' ), "/cart\n/checkout" );
					DFC_Admin::field_textarea( 'exclude_cookies', __( 'Cookies', 'dadsfam-cache' ), __( 'Visitors holding a cookie that starts with any of these get fresh pages.', 'dadsfam-cache' ), 'my_special_cookie' );
					DFC_Admin::field_textarea( 'exclude_agents', __( 'User agents', 'dadsfam-cache' ), __( 'Bots or apps that should always see live pages.', 'dadsfam-cache' ), 'SomeBot' );
					DFC_Admin::field_textarea( 'ignore_params', __( 'Extra tracking parameters to ignore', 'dadsfam-cache' ), __( 'utm_*, fbclid, gclid and friends are ignored already. Add your own here.', 'dadsfam-cache' ), 'my_tracker' );
					?>
				</div>
			</section>

			<!-- ===== Speed (PRO) ===== -->
			<section class="dfc-panel <?php echo 'speed' === $dfc_tab ? 'dfc-active' : ''; ?>" data-panel="speed">
				<?php if ( ! $dfc_is_pro ) : ?>
					<div class="dfc-card dfc-card-upsell"><strong>⚡ <?php esc_html_e( 'These are Pro features.', 'dadsfam-cache' ); ?></strong> <?php esc_html_e( 'You can see everything, but the switches unlock with a license key.', 'dadsfam-cache' ); ?> <button type="button" class="dfc-btn dfc-btn-small" data-goto="license"><?php esc_html_e( 'Enter key', 'dadsfam-cache' ); ?></button></div>
				<?php endif; ?>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Shrink your pages', 'dadsfam-cache' ); ?></h2>
					<?php
					DFC_Admin::field_toggle( 'minify_html', __( 'Minify HTML', 'dadsfam-cache' ), __( 'Strips comments and pointless spaces from cached pages. Invisible to humans, lovely for speed.', 'dadsfam-cache' ), true );
					DFC_Admin::field_toggle( 'minify_inline_css', __( 'Minify inline CSS', 'dadsfam-cache' ), __( 'Squashes the <style> blocks inside your pages.', 'dadsfam-cache' ), true );
					DFC_Admin::field_toggle( 'minify_css_files', __( 'Minify CSS files', 'dadsfam-cache' ), __( 'Serves slimmed-down copies of your theme and plugin stylesheets.', 'dadsfam-cache' ), true );
					DFC_Admin::field_textarea( 'css_exclusions', __( 'CSS files to skip', 'dadsfam-cache' ), __( 'If a stylesheet misbehaves after minifying, drop part of its filename here.', 'dadsfam-cache' ), 'problem-style.css', true );
					?>
				</div>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Smarter JavaScript', 'dadsfam-cache' ); ?></h2>
					<?php
					DFC_Admin::field_toggle( 'defer_js', __( 'Defer JavaScript', 'dadsfam-cache' ), __( 'Lets the page paint first, then runs scripts. Big PageSpeed win.', 'dadsfam-cache' ), true );
					DFC_Admin::field_textarea( 'defer_exclusions', __( 'Never defer these', 'dadsfam-cache' ), __( 'jQuery is protected by default.', 'dadsfam-cache' ), 'jquery.min.js', true );
					DFC_Admin::field_toggle( 'delay_js', __( 'Delay JavaScript until interaction', 'dadsfam-cache' ), __( 'Scripts wait until the visitor moves, scrolls or taps (max 10s). Brutal-but-brilliant for analytics, chat widgets and ad scripts.', 'dadsfam-cache' ), true );
					DFC_Admin::field_textarea( 'delay_exclusions', __( 'Never delay these', 'dadsfam-cache' ), __( 'Anything your page needs immediately. jQuery and WordPress core are protected by default.', 'dadsfam-cache' ), 'jquery', true );
					?>
				</div>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Media & connections', 'dadsfam-cache' ); ?></h2>
					<?php
					DFC_Admin::field_toggle( 'lazyload', __( 'Lazy-load images', 'dadsfam-cache' ), __( 'Images below the fold only load when the visitor scrolls near them.', 'dadsfam-cache' ), true );
					DFC_Admin::field_number( 'lazyload_skip', __( 'Skip the first … images', 'dadsfam-cache' ), __( 'Top-of-page images (logo, hero) should load instantly. 2 is a good default.', 'dadsfam-cache' ), 0, 10 );
					DFC_Admin::field_toggle( 'lazyload_iframes', __( 'Lazy-load iframes & videos', 'dadsfam-cache' ), __( 'YouTube embeds and maps wait until needed.', 'dadsfam-cache' ), true );
					DFC_Admin::field_textarea( 'dns_prefetch', __( 'DNS prefetch domains', 'dadsfam-cache' ), __( 'Warm up DNS for external services, one domain per line, e.g. fonts.googleapis.com', 'dadsfam-cache' ), 'fonts.googleapis.com', true );
					DFC_Admin::field_textarea( 'preconnect', __( 'Preconnect domains', 'dadsfam-cache' ), __( 'Full connection warm-up for the 1–2 domains you definitely use (fonts, CDN).', 'dadsfam-cache' ), 'fonts.gstatic.com', true );
					DFC_Admin::field_select( 'heartbeat', __( 'WordPress Heartbeat', 'dadsfam-cache' ), __( 'Heartbeat pings your server constantly. Slowing it saves real server resources.', 'dadsfam-cache' ), array(
						'default'       => __( 'Default (no change)', 'dadsfam-cache' ),
						'slow'          => __( 'Slow down (every 60s) — recommended', 'dadsfam-cache' ),
						'disable_front' => __( 'Disable on the front-end', 'dadsfam-cache' ),
					), true );
					?>
				</div>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Core Web Vitals', 'dadsfam-cache' ); ?></h2>
					<p class="dfc-muted"><?php esc_html_e( 'The settings Google actually scores you on. These are the difference between "fast" and a green PageSpeed report.', 'dadsfam-cache' ); ?></p>
					<?php
					DFC_Admin::field_toggle( 'optimize_lcp', __( 'Optimise the LCP image', 'dadsfam-cache' ), __( 'Marks your biggest above-the-fold image as high priority and preloads it — the single biggest lever for Largest Contentful Paint.', 'dadsfam-cache' ), true );
					DFC_Admin::field_text( 'lcp_image', __( 'LCP image (optional)', 'dadsfam-cache' ), __( 'Leave blank to auto-target the first image on the page, or paste part of your hero image filename, e.g. hero.jpg', 'dadsfam-cache' ), 'hero.jpg', true );
					DFC_Admin::field_toggle( 'optimize_css_delivery', __( 'Eliminate render-blocking CSS', 'dadsfam-cache' ), __( 'Loads stylesheets asynchronously and paints instantly using the critical CSS below. Stays off until you add critical CSS, so it can never cause a flash of unstyled text.', 'dadsfam-cache' ), true );
					DFC_Admin::field_textarea( 'critical_css', __( 'Critical CSS (above-the-fold)', 'dadsfam-cache' ), __( 'Paste the minimal CSS needed to render the top of your pages. Generate it with a tool like criticalcss.com. Required for the option above to do anything.', 'dadsfam-cache' ), '/* paste critical CSS here */', true );
					?>
				</div>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Fonts & navigation', 'dadsfam-cache' ); ?></h2>
					<?php
					DFC_Admin::field_toggle( 'font_optimize', __( 'Optimise fonts (font-display: swap)', 'dadsfam-cache' ), __( 'Stops invisible text while web fonts load by adding display=swap to Google Fonts.', 'dadsfam-cache' ), true );
					DFC_Admin::field_textarea( 'font_preload', __( 'Preload these font files', 'dadsfam-cache' ), __( 'Full URLs to your most important font files, one per line. Preloading above-the-fold fonts cuts layout shift.', 'dadsfam-cache' ), 'https://yoursite.com/wp-content/uploads/fonts/main.woff2', true );
					DFC_Admin::field_toggle( 'prefetch_links', __( 'Prefetch links on hover', 'dadsfam-cache' ), __( 'When a visitor hovers or starts tapping a link, the next page is fetched in the background so it opens almost instantly.', 'dadsfam-cache' ), true );
					?>
				</div>
			</section>

			<!-- ===== CDN (PRO) ===== -->
			<section class="dfc-panel <?php echo 'cdn' === $dfc_tab ? 'dfc-active' : ''; ?>" data-panel="cdn">
				<?php if ( ! $dfc_is_pro ) : ?>
					<div class="dfc-card dfc-card-upsell"><strong>🌍 <?php esc_html_e( 'CDN rewriting is a Pro feature.', 'dadsfam-cache' ); ?></strong> <button type="button" class="dfc-btn dfc-btn-small" data-goto="license"><?php esc_html_e( 'Enter key', 'dadsfam-cache' ); ?></button></div>
				<?php endif; ?>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Content Delivery Network', 'dadsfam-cache' ); ?></h2>
					<p class="dfc-muted"><?php esc_html_e( 'If you use BunnyCDN, KeyCDN or similar, your images, CSS and JS get served from their fast worldwide servers instead of yours.', 'dadsfam-cache' ); ?></p>
					<?php
					DFC_Admin::field_toggle( 'cdn_enabled', __( 'Enable CDN rewriting', 'dadsfam-cache' ), __( 'Rewrites wp-content and wp-includes URLs in cached pages to your CDN.', 'dadsfam-cache' ), true );
					DFC_Admin::field_text( 'cdn_url', __( 'CDN URL', 'dadsfam-cache' ), __( 'The hostname your CDN gave you.', 'dadsfam-cache' ), 'https://cdn.dadsfam.co.za', true );
					DFC_Admin::field_textarea( 'cdn_exclusions', __( 'Never rewrite these', 'dadsfam-cache' ), __( 'Any file or path that must stay on your own domain.', 'dadsfam-cache' ), '.php', true );
					?>
				</div>
			</section>

			<!-- ===== Database (PRO) ===== -->
			<section class="dfc-panel <?php echo 'database' === $dfc_tab ? 'dfc-active' : ''; ?>" data-panel="database">
				<?php if ( ! $dfc_is_pro ) : ?>
					<div class="dfc-card dfc-card-upsell"><strong>🗄️ <?php esc_html_e( 'Database cleanup is a Pro feature.', 'dadsfam-cache' ); ?></strong> <button type="button" class="dfc-btn dfc-btn-small" data-goto="license"><?php esc_html_e( 'Enter key', 'dadsfam-cache' ); ?></button></div>
				<?php endif; ?>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Spring-clean the database', 'dadsfam-cache' ); ?></h2>
					<p class="dfc-muted"><?php esc_html_e( 'Old revisions and spam quietly bloat your database. Cleaning is safe — but a backup before the first run is never a bad idea.', 'dadsfam-cache' ); ?></p>
					<table class="dfc-table" id="dfc-db-table">
						<tbody>
						<?php foreach ( DFC_DB_Cleaner::tasks() as $job => $label ) : ?>
							<tr>
								<td><?php echo esc_html( $label ); ?></td>
								<td class="dfc-db-count" data-count="<?php echo esc_attr( $job ); ?>">…</td>
								<td><button type="button" class="dfc-btn dfc-btn-small dfc-db-clean" data-job="<?php echo esc_attr( $job ); ?>" <?php disabled( ! $dfc_is_pro ); ?>><?php esc_html_e( 'Clean', 'dadsfam-cache' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<div class="dfc-actions">
						<button type="button" class="dfc-btn" id="dfc-db-refresh"><?php esc_html_e( 'Refresh counts', 'dadsfam-cache' ); ?></button>
					</div>
					<div class="dfc-result" id="dfc-db-result" hidden></div>
				</div>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Automatic cleanup', 'dadsfam-cache' ); ?></h2>
					<?php
					DFC_Admin::field_select( 'db_schedule', __( 'Run automatically', 'dadsfam-cache' ), __( 'Set and forget.', 'dadsfam-cache' ), array(
						'never'  => __( 'Never (manual only)', 'dadsfam-cache' ),
						'weekly' => __( 'Once a week', 'dadsfam-cache' ),
					), true );
					?>
					<div class="dfc-field dfc-field-col <?php echo DFC_Admin::locked( true ) ? 'dfc-locked' : ''; ?>">
						<span class="dfc-field-label"><?php esc_html_e( 'Tasks included in the weekly run', 'dadsfam-cache' ); ?></span>
						<div class="dfc-checks">
							<?php
							$dfc_chosen = (array) $dfc_settings['db_tasks'];
							foreach ( DFC_DB_Cleaner::tasks() as $job => $label ) :
								if ( 'trash_posts' === $job ) {
									continue; // Too risky for unattended runs.
								}
								?>
								<label class="dfc-check">
									<input type="checkbox" name="dfc[db_tasks][]" value="<?php echo esc_attr( $job ); ?>"
										<?php checked( in_array( $job, $dfc_chosen, true ) ); ?> <?php disabled( ! $dfc_is_pro ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
								<?php if ( ! $dfc_is_pro && in_array( $job, $dfc_chosen, true ) ) : ?>
									<input type="hidden" name="dfc[db_tasks][]" value="<?php echo esc_attr( $job ); ?>">
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</section>

			<!-- ===== Images (PRO) ===== -->
			<section class="dfc-panel <?php echo 'images' === $dfc_tab ? 'dfc-active' : ''; ?>" data-panel="images">
				<?php if ( ! $dfc_is_pro ) : ?>
					<div class="dfc-card dfc-card-upsell"><strong>🖼️ <?php esc_html_e( 'WebP image conversion is a Pro feature.', 'dadsfam-cache' ); ?></strong> <button type="button" class="dfc-btn dfc-btn-small" data-goto="license"><?php esc_html_e( 'Enter key', 'dadsfam-cache' ); ?></button></div>
				<?php endif; ?>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Next-gen images (WebP)', 'dadsfam-cache' ); ?></h2>
					<p class="dfc-muted"><?php esc_html_e( 'Creates a smaller WebP copy of every JPEG and PNG and serves it automatically to browsers that support it — with no change to your pages, so it is completely layout-safe.', 'dadsfam-cache' ); ?></p>
					<div class="dfc-result" id="dfc-webp-caps" hidden></div>
					<?php
					DFC_Admin::field_toggle( 'serve_webp', __( 'Serve WebP images', 'dadsfam-cache' ), __( 'Adds the server rules that hand out .webp copies to supported browsers. On Apache/LiteSpeed this is automatic; on NGINX use the snippet on the Caching tab.', 'dadsfam-cache' ), true );
					DFC_Admin::field_toggle( 'auto_webp', __( 'Auto-convert new uploads', 'dadsfam-cache' ), __( 'Every image you upload from now on gets a WebP copy made straightaway.', 'dadsfam-cache' ), true );
					DFC_Admin::field_number( 'webp_quality', __( 'WebP quality', 'dadsfam-cache' ), __( '82 is a great balance of size and looks. Lower = smaller files.', 'dadsfam-cache' ), 1, 100, true );
					?>
				</div>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Convert existing images', 'dadsfam-cache' ); ?></h2>
					<p class="dfc-muted"><?php esc_html_e( 'Run this once to create WebP copies of the images already in your media library. Keep the page open until it finishes.', 'dadsfam-cache' ); ?></p>
					<table class="dfc-table">
						<tbody>
							<tr><td><?php esc_html_e( 'Source images (JPEG/PNG)', 'dadsfam-cache' ); ?></td><td class="dfc-db-count" id="dfc-webp-sources">…</td></tr>
							<tr><td><?php esc_html_e( 'Already converted', 'dadsfam-cache' ); ?></td><td class="dfc-db-count" id="dfc-webp-done">…</td></tr>
							<tr><td><?php esc_html_e( 'Still to do', 'dadsfam-cache' ); ?></td><td class="dfc-db-count" id="dfc-webp-pending">…</td></tr>
						</tbody>
					</table>
					<div class="dfc-progress" id="dfc-webp-progress" hidden>
						<div class="dfc-progress-bar"><span id="dfc-webp-bar" style="width:0%"></span></div>
						<span class="dfc-progress-text" id="dfc-webp-text"></span>
					</div>
					<div class="dfc-actions">
						<button type="button" class="dfc-btn dfc-btn-primary" id="dfc-webp-convert" <?php disabled( ! $dfc_is_pro ); ?>>🖼️ <?php esc_html_e( 'Convert images to WebP', 'dadsfam-cache' ); ?></button>
						<button type="button" class="dfc-btn" id="dfc-webp-refresh"><?php esc_html_e( 'Refresh counts', 'dadsfam-cache' ); ?></button>
						<button type="button" class="dfc-btn" id="dfc-webp-clear" <?php disabled( ! $dfc_is_pro ); ?>><?php esc_html_e( 'Delete WebP copies', 'dadsfam-cache' ); ?></button>
					</div>
					<div class="dfc-result" id="dfc-webp-result" hidden></div>
				</div>
			</section>

			<!-- ===== Preload ===== -->
			<section class="dfc-panel <?php echo 'preload' === $dfc_tab ? 'dfc-active' : ''; ?>" data-panel="preload">
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Warm up the cache', 'dadsfam-cache' ); ?></h2>
					<p class="dfc-muted"><?php esc_html_e( 'Preloading visits your pages in the background so the first real visitor already gets the fast, cached version.', 'dadsfam-cache' ); ?></p>
					<div class="dfc-actions">
						<button type="button" class="dfc-btn dfc-btn-primary" id="dfc-preload-start">🔥 <?php esc_html_e( 'Start preloading', 'dadsfam-cache' ); ?></button>
						<button type="button" class="dfc-btn" id="dfc-preload-stop"><?php esc_html_e( 'Stop', 'dadsfam-cache' ); ?></button>
					</div>
					<div class="dfc-progress" id="dfc-preload-progress" hidden>
						<div class="dfc-progress-bar"><span id="dfc-preload-bar" style="width:0%"></span></div>
						<span class="dfc-progress-text" id="dfc-preload-text"></span>
					</div>
					<div class="dfc-result" id="dfc-preload-result" hidden></div>
				</div>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Preload settings', 'dadsfam-cache' ); ?></h2>
					<?php
					DFC_Admin::field_text( 'preload_sitemap', __( 'Sitemap URL (optional)', 'dadsfam-cache' ), __( 'Leave empty and we will find wp-sitemap.xml or your SEO plugin sitemap automatically.', 'dadsfam-cache' ), home_url( '/wp-sitemap.xml' ) );
					DFC_Admin::field_toggle( 'preload_auto', __( 'Auto-preload after full clear', 'dadsfam-cache' ), __( 'Whenever the whole cache is cleared, quietly rebuild it in the background.', 'dadsfam-cache' ), true );
					?>
				</div>
			</section>

			<!-- ===== License ===== -->
			<section class="dfc-panel <?php echo 'license' === $dfc_tab ? 'dfc-active' : ''; ?>" data-panel="license">
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Your license', 'dadsfam-cache' ); ?></h2>
					<div class="dfc-license-status">
						<?php
						$badges = array(
							'active'   => array( 'dfc-pill-on', __( 'ACTIVE', 'dadsfam-cache' ) ),
							'grace'    => array( 'dfc-pill-mid', __( 'ACTIVE (re-checking)', 'dadsfam-cache' ) ),
							'invalid'  => array( 'dfc-pill-off', __( 'INVALID', 'dadsfam-cache' ) ),
							'inactive' => array( 'dfc-pill-off', __( 'NOT ACTIVATED', 'dadsfam-cache' ) ),
						);
						$b = isset( $badges[ $dfc_license['status'] ] ) ? $badges[ $dfc_license['status'] ] : $badges['inactive'];
						?>
						<span class="dfc-pill <?php echo esc_attr( $b[0] ); ?>"><span class="dfc-dot"></span><?php echo esc_html( $b[1] ); ?></span>
						<?php if ( $dfc_license['expires'] ) : ?>
							<span class="dfc-muted"><?php
								/* translators: %s = expiry date */
								printf( esc_html__( 'Expires: %s', 'dadsfam-cache' ), esc_html( $dfc_license['expires'] ) );
							?></span>
						<?php endif; ?>
					</div>
					<?php if ( $dfc_license['message'] ) : ?>
						<p class="dfc-muted">💬 <?php echo esc_html( $dfc_license['message'] ); ?></p>
					<?php endif; ?>

					<div class="dfc-field dfc-field-col">
						<label class="dfc-field-label" for="dfc-license-key"><?php esc_html_e( 'License key', 'dadsfam-cache' ); ?></label>
						<span class="dfc-field-desc"><?php esc_html_e( 'Find it in My Account → My Licenses on dadsfam.co.za.', 'dadsfam-cache' ); ?></span>
						<input class="dfc-input" type="text" id="dfc-license-key" autocomplete="off"
							value="<?php echo esc_attr( $dfc_license['key'] ); ?>" placeholder="DFEM-XXXX-XXXX-XXXX">
					</div>
					<div class="dfc-actions">
						<button type="button" class="dfc-btn dfc-btn-primary" id="dfc-license-activate">🔑 <?php esc_html_e( 'Activate', 'dadsfam-cache' ); ?></button>
						<?php if ( 'inactive' !== $dfc_license['status'] ) : ?>
							<button type="button" class="dfc-btn" id="dfc-license-deactivate"><?php esc_html_e( 'Remove from this site', 'dadsfam-cache' ); ?></button>
						<?php endif; ?>
						<a class="dfc-btn dfc-btn-ghost" href="<?php echo esc_url( DFC_BUY_URL ); ?>" target="_blank"><?php esc_html_e( 'Get a key', 'dadsfam-cache' ); ?></a>
					</div>
					<div class="dfc-result" id="dfc-license-result" hidden></div>
				</div>
			</section>

			<!-- ===== Tools ===== -->
			<section class="dfc-panel <?php echo 'tools' === $dfc_tab ? 'dfc-active' : ''; ?>" data-panel="tools">
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Settings backup', 'dadsfam-cache' ); ?></h2>
					<div class="dfc-actions">
						<button type="button" class="dfc-btn" id="dfc-export">⬇️ <?php esc_html_e( 'Export settings', 'dadsfam-cache' ); ?></button>
						<label class="dfc-btn" for="dfc-import-file">⬆️ <?php esc_html_e( 'Import settings', 'dadsfam-cache' ); ?></label>
						<input type="file" id="dfc-import-file" accept="application/json,.json" hidden>
					</div>
					<div class="dfc-result" id="dfc-tools-result" hidden></div>
				</div>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Engine maintenance', 'dadsfam-cache' ); ?></h2>
					<p class="dfc-muted"><?php esc_html_e( 'For when something is acting up and support says "reinstall the engine".', 'dadsfam-cache' ); ?></p>
					<div class="dfc-actions">
						<button type="button" class="dfc-btn" id="dfc-reinstall-dropin"><?php esc_html_e( 'Reinstall caching engine', 'dadsfam-cache' ); ?></button>
						<button type="button" class="dfc-btn" id="dfc-remove-dropin"><?php esc_html_e( 'Remove caching engine', 'dadsfam-cache' ); ?></button>
						<?php if ( $dfc_apache ) : ?>
							<button type="button" class="dfc-btn" id="dfc-remove-htaccess"><?php esc_html_e( 'Remove .htaccess rules', 'dadsfam-cache' ); ?></button>
						<?php endif; ?>
					</div>
					<div class="dfc-result" id="dfc-maint-result" hidden></div>
				</div>
				<div class="dfc-card">
					<h2 class="dfc-card-title"><?php esc_html_e( 'Debug info', 'dadsfam-cache' ); ?></h2>
					<p class="dfc-muted"><?php esc_html_e( 'Copy this when asking for help — it answers the first ten questions support would ask.', 'dadsfam-cache' ); ?></p>
					<?php
					global $wp_version;
					$dfc_debug = array(
						'Plugin'         => 'DadsFam Cache ' . DFC_VERSION . ( $dfc_is_pro ? ' (PRO)' : ' (Free)' ),
						'WordPress'      => $wp_version,
						'PHP'            => PHP_VERSION,
						'Server'         => isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : 'unknown',
						'Home URL'       => home_url(),
						'Drop-in ours'   => $dfc_engine['dropin_ours'] ? 'yes' : 'no',
						'WP_CACHE'       => $dfc_engine['wp_cache_on'] ? 'on' : 'off',
						'.htaccess'      => $dfc_apache ? ( $dfc_htstatus['applied'] ? 'applied' : 'not applied' ) : 'n/a (nginx)',
						'Conflicts'      => $dfc_engine['conflicts'] ? implode( ', ', $dfc_engine['conflicts'] ) : 'none',
						'Cached pages'   => $dfc_stats['pages'] . ' (' . $dfc_stats['human'] . ')',
						'License status' => $dfc_license['status'],
					);
					$dfc_debug_text = '';
					foreach ( $dfc_debug as $k => $v ) {
						$dfc_debug_text .= str_pad( $k . ':', 16 ) . $v . "\n";
					}
					?>
					<textarea class="dfc-input dfc-textarea dfc-code" rows="12" readonly id="dfc-debug"><?php echo esc_textarea( $dfc_debug_text ); ?></textarea>
					<button type="button" class="dfc-btn dfc-btn-small" data-copy="#dfc-debug"><?php esc_html_e( 'Copy debug info', 'dadsfam-cache' ); ?></button>
				</div>
			</section>

			<!-- ===== Sticky save bar ===== -->
			<div class="dfc-savebar" id="dfc-savebar">
				<span class="dfc-savebar-text"><?php esc_html_e( 'Saving also clears the cache, so changes show immediately.', 'dadsfam-cache' ); ?></span>
				<button type="submit" class="dfc-btn dfc-btn-primary dfc-btn-save">💾 <?php esc_html_e( 'Save Settings', 'dadsfam-cache' ); ?></button>
			</div>
		</form>
		</div>
	</div>

	<!-- ===== Pro modal ===== -->
	<div class="dfc-modal" id="dfc-pro-modal" hidden>
		<div class="dfc-modal-box">
			<button type="button" class="dfc-modal-close" id="dfc-modal-close" aria-label="Close">✕</button>
			<h2>⚡ <?php esc_html_e( 'That one is a Pro feature', 'dadsfam-cache' ); ?></h2>
			<p><?php esc_html_e( 'Unlock minification, lazy-loading, delayed JS, CDN and database cleanup with a DadsFam license key.', 'dadsfam-cache' ); ?></p>
			<div class="dfc-actions">
				<a class="dfc-btn dfc-btn-primary" href="<?php echo esc_url( DFC_BUY_URL ); ?>" target="_blank"><?php esc_html_e( 'Get a license', 'dadsfam-cache' ); ?></a>
				<button type="button" class="dfc-btn" data-goto="license"><?php esc_html_e( 'I have a key', 'dadsfam-cache' ); ?></button>
			</div>
		</div>
	</div>

	<div class="dfc-toast" id="dfc-toast" role="status" aria-live="polite"></div>
</div>
