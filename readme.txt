=== DadsFam Cache ===
Contributors: dadsfam
Tags: cache, caching, performance, page cache, optimization
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your WordPress and WooCommerce site lekker fast — friendly page caching with optional Pro speed tools, no rocket science required.

== Description ==

DadsFam Cache is a straightforward, no-nonsense performance plugin. It saves ready-made copies of your pages so repeat visitors skip PHP and the database entirely, and it ships with a one-click setup that applies safe, sensible defaults for you.

It was built to be understandable by normal humans — every setting is written in plain English with a short explanation of what it actually does.

= Free features =

* Disk-based full-page caching (serves cached pages before WordPress even boots)
* Gzip pre-compression of cached pages
* Browser-cache and compression rules for Apache/LiteSpeed (.htaccess), with a copy-paste NGINX snippet
* Smart automatic clearing — edit a post and only the affected pages clear
* Clears the cache automatically after plugin, theme or WordPress updates
* Sensible exclusions out of the box (cart, checkout, my-account, logged-in users)
* Ignores tracking parameters (utm_*, fbclid, gclid…) so shared links still hit cache
* Optional separate cache for mobile
* Manual cache preloading from your sitemap
* Built-in cache test, stats and a copy-ready debug panel

= Pro features =

Unlocked with a DadsFam license key:

* Minify HTML, inline CSS and CSS files
* Eliminate render-blocking CSS — inline critical CSS and load the rest asynchronously
* Optimise the LCP image — fetchpriority + preload for a better Largest Contentful Paint
* Font optimisation — font-display: swap and font preloading
* Prefetch internal links on hover for near-instant navigation
* Next-gen images — convert JPEG/PNG to WebP and serve them automatically (layout-safe)
* Defer JavaScript
* Delay JavaScript until user interaction (great for analytics, chat and ad scripts)
* Lazy-load images and iframes
* DNS-prefetch and preconnect hints
* WordPress Heartbeat control
* CDN URL rewriting (BunnyCDN, KeyCDN and friends)
* Database cleanup (revisions, spam, transients and table optimisation), manual or scheduled
* Automatic cache preloading after every full clear

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin, then activate it.
2. If you already run another caching plugin, deactivate that one first — two cache plugins on one site causes conflicts. DadsFam Cache will warn you if it spots one.
3. Go to **DF Cache** in the admin menu and click **One-Click Speed Setup**.
4. (Optional) To unlock Pro, open the **License** tab and paste your key from My Account → My Licenses on dadsfam.co.za.

== Frequently Asked Questions ==

= Will it work on NGINX? =
The page cache and all optimisation features work everywhere. Browser-cache rules are added to .htaccess only on Apache/LiteSpeed; on NGINX the plugin gives you a snippet to paste into your server config.

= Does it work with WooCommerce? =
Yes. The cart, checkout and account pages are never cached, and the shop/product pages clear automatically when stock changes.

= It says another caching plugin is active. What now? =
Deactivate the other plugin first, then run One-Click Speed Setup. Running two page-cache plugins at once will cause problems.

= How do I unlock Pro features? =
Enter a valid DadsFam license key on the License tab. The plugin checks it against dadsfam.co.za and unlocks the Pro switches.

= Something looks broken after enabling a Pro feature. =
Turn the relevant feature (minify CSS, defer/delay JS) back off, or add the problem file to its exclusion box. Then clear the cache.

== Changelog ==

= 1.2.1 =
* Security fix: earlier versions saved a wp-config.php backup (wp-config.dfc-backup.php) in the web root when enabling caching, which could expose database credentials. That backup is no longer created, any existing copy is deleted automatically on update, and wp-config.php is now edited with a safe atomic write that needs no backup.

= 1.2.0 =
* New Pro: WebP image conversion. Bulk-convert your media library (and auto-convert new uploads) to WebP, served automatically to supported browsers via layout-safe .htaccess / NGINX rules. Uses Imagick or GD, with a clear warning if the host supports neither.
* Added an Images tab with conversion progress, counts and quality control.
* One-Click Speed Setup now enables WebP serving when the server supports it.

= 1.1.0 =
* New Pro: render-blocking CSS elimination (inline critical CSS + async stylesheet loading).
* New Pro: LCP image optimisation (fetchpriority="high" + responsive preload, auto-excluded from lazy-loading).
* New Pro: font optimisation (font-display: swap on Google Fonts + font preloading).
* New Pro: prefetch internal links on hover/tap for near-instant navigation.
* One-Click Speed Setup now enables LCP, font and prefetch optimisations automatically.

= 1.0.0 =
* Initial release: disk page caching, gzip, browser-cache/.htaccess rules, smart purge, exclusions, preloading, stats and cache testing.
* Pro: minification, defer/delay JavaScript, lazy-loading, resource hints, heartbeat control, CDN rewriting, database cleanup and auto-preload.

== Upgrade Notice ==

= 1.2.1 =
Security fix — removes an insecure wp-config backup from the web root. Update right away.

= 1.2.0 =
Adds WebP image conversion. Visit the Images tab and run "Convert images to WebP".

= 1.1.0 =
Adds the Core Web Vitals features (critical CSS, LCP image, fonts, link prefetch).

= 1.0.0 =
First release.
