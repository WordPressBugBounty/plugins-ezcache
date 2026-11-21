=== ezCache ===
Contributors: upress
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: cache, performance, speed, redis, optimization

Page caching, Redis, WebP conversion and optional WordPress performance tools. All features are free; no license key or trial is required.

== Description ==

ezCache caches public HTML pages on disk and optionally in Redis. Logged-in visitors, cart/session cookies, authenticated requests and private responses bypass full-page caching. Mobile and WebP variants have separate cache entries.

Optional tools include CSS/JS optimization, image and iframe lazy loading, cache preloading, CDN URL rewriting, database cleanup, heartbeat control and resource preload Link headers. Enable optimizations individually and check your theme, forms and checkout after changing settings. Performance depends on the site and hosting configuration.

Redis object caching requires the PHP Redis extension. Full-page Redis caching also has a bounded socket fallback. Installations have separate Redis namespaces; multisite object groups respect blog and network scope. In a multisite network, cache settings and drop-ins are shared and managed by the network administrator.

WebP conversion uses the local WordPress image editor (GD or Imagick with WebP support). JPEG/PNG inputs are limited to 10 MiB and 16 megapixels. Conversion, retries, library scans and deletion run in bounded batches. GIF animation is not converted. External conversion is disabled by default.

Critical CSS requires generated CSS and an explicitly configured generation service. Stylesheets remain blocking until CSS is available. Resource preload hints are standard Link headers; actual HTTP 103 responses require a supporting reverse proxy.

== Installation ==

1. Install the ZIP through Plugins > Add New > Upload Plugin.
2. Activate ezCache. It installs its own advanced-cache.php and enables WP_CACHE using an atomic wp-config.php update. Existing drop-ins belonging to another plugin are preserved.
3. Open Settings > ezCache and select the optional optimizations you need. All features are free.
4. Ensure WP-Cron runs. On sites with DISABLE_WP_CRON, run due events with a server scheduler (for example wp cron event run --due-now).
5. Deactivation removes ezCache drop-ins and scheduled work while retaining preferences and generated WebP images. WordPress plugin deletion removes settings, tables and generated cache data; original media are preserved.

== External services ==

No license service is required. The optional Site Diagnostic action sends the site's URL to https://api.ezcache-wp.com/analyze only when requested by an administrator. It requires that service to be available.

If an administrator enables External WebP conversion, JPEG/PNG image contents may be sent over verified HTTPS to https://api.ezcache-wp.com/convert when local encoding is unavailable. No image is sent by default. The service's availability and policies are managed separately from this plugin.

Critical CSS generation sends a requested public page URL to the endpoint explicitly configured in the ezcache_diag_api_url WordPress option. HTTPS is required except for an explicitly configured localhost service. No default local service is assumed.

== Operations ==

Settings and backups are stored in PHP-guarded files under wp-content; legacy JSON settings/backups migrate on upgrade. Any leftover wp-config.php.backup is moved to a guarded file under wp-content/ezcache-protected; this recovery copy is preserved on uninstall. Writes are atomic and failed writes return errors. The web process needs write access to wp-content and, when changing WP_CACHE, wp-config.php. Read-only installations report the required manual action.

Redis defaults to 127.0.0.1:6379. Configure EZCACHE_REDIS_HOST, EZCACHE_REDIS_PORT, EZCACHE_REDIS_TIMEOUT, EZCACHE_REDIS_DATABASE and EZCACHE_REDIS_PASSWORD in wp-config.php as needed. The password may be a string or an ACL [username, password] array. EZCACHE_CACHE_SALT can provide a unique namespace; do not reuse it across unrelated installations. Redis memory statistics describe the whole Redis server; key counts describe this installation.

Preload failures retry at most three times and remain visible in the status. WebP retries are delayed and capped at three attempts. Use a real cron scheduler for low-traffic sites. Queue locks use flock on the shared wp-content filesystem; multi-node hosting must provide working shared filesystem locks. Database cleanup uses WordPress deletion APIs in batches. Table optimization is restricted to one small MyISAM table per run; maintain large/InnoDB tables separately.

Statistics scans stop after two seconds and label partial counts. Expiry maintenance is bounded; TTL is also enforced on every cache read. A lifetime of zero means no time-based expiry. Plugin upgrades use a fresh cache namespace and purge obsolete disk caches in background batches.

== Changelog ==

= 2.7.2 =
* Fixed: Pages are cached again on sites where a plugin adds a Vary header to every page. Since 2.7.0 a page was stored only when its Vary header named nothing but Accept-Encoding. Jetpack sends "Vary: accept, content-type" on front-end pages, so on those sites no page was stored at all and every view was rendered by PHP. Now only Vary: Cookie, Vary: Authorization and Vary: * keep a page out of the cache (filter ezcache_private_vary_fields); "Strict privacy" on the Settings page keeps the stricter rule. Nothing has to be done after updating: the cache fills again as pages are visited.

= 2.7.1 =
* Fixed: Updating no longer breaks admin and AJAX requests for up to a minute on servers where PHP keeps compiled files in memory (opcache.revalidate_freq). Right after the files are replaced, PHP can run old and new plugin files together; the upgrade routine then called code that did not exist yet ("Call to undefined method"). The plugin now clears its own files from the opcode cache, waits until every file is older than the re-check window before migrating, and treats an error during the migration as "try again later" instead of failing the request.
* Fixed: Pages are cached again on sites where a plugin opens a PHP session for every visitor or sets a visitor cookie such as "recently viewed products". 2.7.0 treated any session and any cookie as private, which stopped most pages on such sites from being cached or served from the cache. Now only cookies that really mark a private page do: login, WooCommerce session and cart, password-protected posts, comment authors and the site's own rejected cookies, together with an explicit private/no-store header and DONOTCACHEPAGE. The stricter behaviour is available as "Strict privacy" on the Settings page.
* Fixed: Background tasks no longer stall when WP-Cron does not run. When a scheduled event is long overdue, or WordPress cannot spawn WP-Cron itself (DISABLE_WP_CRON without a server cron), ezCache triggers one throttled run of wp-cron.php. On one site this had left 26,000 WebP conversions queued for two weeks while every page view grew the queue. The ezCache screens now warn when a background task has been waiting for over an hour.
* Added: The external WebP conversion service is paused for ten minutes after three failed requests in a row (network error, timeout, HTTP 5xx or 429). When that happens, every image that was charged an attempt for one of those failures gets it back, so an outage of the service no longer turns the queue into "failed" images; an image the service keeps rejecting while it answers for others is still marked failed after three attempts.
* Fixed: A page could be stored, and then served to everyone, as the home page. Addresses that begin with two slashes or contain a colon followed by digits (/tag/x/page:2, filter URLs such as /shop/tax/product_cat:16/) lost their path when the cache key was built. The key is taken from the request path again and is lower-cased as before 2.7.0, so /%D7%A9/ and /%d7%a9/ are one entry and editing a post purges the copy visitors actually received.
* Fixed: Converting images to WebP no longer empties the whole page cache (and Varnish, and Redis) after every batch of five images. The cache is refreshed once when the queue is finished, at most once an hour (filter ezcache_webp_refresh_interval).
* Fixed: Images marked "failed" are no longer lost. A server without a WebP encoder leaves the queue untouched instead of failing every image, failed images return to the queue once on update, when a media scan is started and when an encoder is switched on, and a failed write gets its three attempts.
* Fixed: WebP file names for .PNG, .JPEG, .jpe and .bmp files, a temporary image left in the uploads folder on servers whose Imagick cannot write WebP, and an original without a file extension being overwritten by its WebP version.
* Fixed: "Clear WebP images" also purges Varnish and the Redis page cache, at the start and when the deletion is done, and pages are not pointed at WebP files while they are being deleted.
* Fixed: The Redis object-cache drop-in can no longer take a site down. When the plugin folder is missing, the plugin is switched off, the phpredis extension is not loaded or Redis does not answer, WordPress now uses its own cache and keeps transients in the database. Before, those cases ended in a fatal error on every request or in a cache that forgot everything between requests. A Redis that is down is tried again every 30 seconds instead of in every request, and a write that Redis refuses no longer leaves the previous value in place. After any time without the Redis object cache (Redis was down, the feature was switched off, the drop-in was replaced) the object cache is emptied before it is read again, so it cannot bring back options from before that time.
* Fixed: Clearing the page cache no longer empties the Redis object cache as well, and purging one page is a single Redis command instead of a scan of every key on the Redis server. Sites that do not use the Redis page cache no longer contact Redis when a post is saved. A purge that cannot reach Redis is not lost: pages stored before it are ignored once Redis is back. Hebrew and other non-Latin pages are stored at their own size instead of three times it.
* Fixed: Saving the settings empties the page cache only when a setting that affects cached pages was changed.
* Fixed: Files and cache folders written by a command run as root (WP-CLI) keep the site's owner. A root-owned wp-config.php or settings file could take the site down or make every later save fail.
* Fixed: An update interrupted half-way can no longer install an empty advanced-cache.php, and an empty one is repaired instead of being taken for another plugin's.
* Fixed: The settings file of versions before 2.7.0 is removed only when the update has finished. Removed at the first read, it was missing for code of the previous version that PHP still ran during the update, and that code fell back to default settings for up to a minute and wrote them to a new file.
* Fixed: A page is not stored when the visitor's request already carried a cookie that the page sets again (for example a "recently viewed" list built from that cookie), or when nocache_headers() was called before a PHP session was started. Rejected cookies are read the same way for requests and responses: literal text with * as a wildcard. An entry written as a regular expression, the syntax up to 2.6.9, still matches as one, and one invalid entry no longer switches the whole list off.
* Fixed: An update that cannot finish is reported on the ezCache screens with its reason, and a database user without ALTER no longer blocks the rest of the update. The opcode cache reset is skipped on the command line, where it marked the reset as done without doing it.
* Fixed: An error while a page is being optimized sends the page as WordPress produced it instead of an empty response, and an error in the cache's early code serves the page without the cache instead of an error page.
* Fixed: Cached pages are sent with "Vary: Accept-Encoding, Cookie" again. 2.7.0 added Accept and User-Agent, which makes a proxy in front of the site keep a separate copy of every page per browser version.
* Fixed: The preload checks certificates on requests to the site itself the way WordPress does for loopback requests, as before 2.7.0; with the check always on, the preload stopped working on sites whose own certificate the server cannot verify.
* Fixed: The advanced-cache.php drop-in is brought up to date during the upgrade, not only when an administrator next opens the dashboard.
* Fixed: The release package again contains the data files the JavaScript minifier needs; without them Minify JS failed on every page.

= 2.7.0 =
* Security: Public-response checks on writes, private/session/authentication bypass on reads, safe local asset paths, PHP-guarded configuration/backups, atomic configuration/cache/drop-in writes and bounded Redis protocol parsing.
* Fixed: TTL enforcement, URL invalidation, Redis device/WebP variants, per-installation flushes, multisite object groups and WordPress object-cache semantics including atomic counters.
* Fixed: Truthful REST errors, central settings validation, bounded preload/WebP queues with locking and retries, conservative database cleanup, drop-in ownership and complete deactivation/uninstall handling.
* Changed: Local WebP conversion by default; external conversion requires explicit opt-in. Critical CSS deferral requires generated CSS. Link hints preserve the final HTTP status.
* Updated: npm and Composer dependencies; all features documented as free. Added a reproducible WordPress/PHP compatibility laboratory.


= 2.6.9 =
* Fixed: A cache folder that cannot be deleted (for example files written by WP-CLI running as root) no longer blocks the background cleanup added in 2.6.7. The cleanup stopped at the first such folder, left every cache cleared after it on disk and retried every 30 seconds without making progress. It now skips what it cannot delete, removes everything else, logs what was left behind and tries the leftovers again on the next cache clear.

= 2.6.8 =
* Fixed: "Run preload" no longer stalls after the first batch when the "Enable preload" setting is off. The run was started, processed 5 pages and then stopped silently, because the background processor was only scheduled when the setting was on; the status still showed "running". A run started by hand now always completes, and saving settings no longer cancels a run in progress.
* Added: The preload status detects a stalled run (nothing processed for 10 minutes or no processor scheduled), shows it as "Stalled" with the time of the last activity, and offers a Resume button that continues from where it stopped. Runs stuck from earlier versions resume on the next visit to the admin.

= 2.6.7 =
* Fixed: Clearing the cache no longer blocks the request until every file is gone. The old cache is moved aside instantly and deleted in the background in small batches (WP-Cron event ezcache_purge_trash). On a site with 1.28 million cached pages the previous synchronous delete ran for minutes, and when triggered by a plugin update it re-ran on every admin page load and took the site down.
* Fixed: A plugin update now records the new version before flushing the cache, so a slow or failed flush can never make the upgrade routine repeat itself.
* Fixed: Recursive deletion streams the directory tree instead of loading the whole listing into memory.
* Fixed: WooCommerce account pages and endpoints (My Account, order-pay, order-received, lost-password and similar) are no longer cached. A cached login form carries a nonce that expires, which broke logging in.

= 2.6.6 =
* Added: Filtered product and archive pages (WooCommerce layered navigation, Filter Everything, WOOF, YITH and similar) are no longer cached by default. Every filter combination is a different URL and crawlers walk through all of them; on one site this created 1.27 million cache directories in three days and filled the server's disk. The option can be turned off on the Settings page, and the detection is filterable via ezcache_is_filtered_request and ezcache_filter_query_params.
* Added: Pages with very deep or very long URLs are no longer cached (by default more than 7 path segments or 250 characters). Both limits are configurable on the Settings page; 0 disables a limit.
* Changed: Tracking parameters are now ignored by default when building the cache key, so visitors arriving with ?gclid=..., ?srsltid=... or ?utm_... are served the clean page from cache instead of each click creating a new cache file. The default list now also covers srsltid, gad_source, gad_campaignid, ttclid, igshid, _hsenc, _hsmi and mkt_tok. Sites still on the previous default list are moved to the new one; a customized list is left untouched.
* Changed: With "Don't cache pages where the URLs contain parameters" enabled, a request whose only parameters are ignored tracking parameters now counts as the clean URL and is served from cache.

= 2.6.5 =
* Fixed: The DNS Prefetch and Preconnect fields are no longer replaced with "true"/"false". They are domain-list text fields but were being cast to a boolean when the Performance screen loaded, which overwrote the saved domains.
* Fixed: Combining Google Fonts no longer drops the font-display value. The combined request now uses font-display: swap by default (Google's recommendation), preserving fast text rendering (FCP/LCP). Filterable via ezcache_google_fonts_display.

= 2.6.4 =
* Fixed: URL exclusions (Rejected URIs) with a wildcard (*) at the end or in the middle of the pattern now work correctly. Previously only a wildcard directly after a slash matched, so patterns like *add_to_wishlist* silently failed with no error.
* Added: Requests carrying a nonce or action parameter (_wpnonce, wc-ajax, add-to-cart, action, doing_wp_cron, add_to_wishlist and similar) are no longer cached. This prevents cache bloat from per-request values and avoids serving one visitor's nonce to another. The list is filterable.

= 2.6.3 =
* Fixed: The cache no longer modifies non-HTML responses. Plain-text files (such as IndexNow verification keys and llms.txt), RSS/Atom feeds and other non-HTML output previously received the ezCache footer comment and other HTML optimizations, which could corrupt them and break IndexNow verification, for example. Only real HTML pages are processed and cached now; everything else passes through untouched.

= 2.6.2 =
* Fixed: Inline JavaScript minification no longer corrupts non-JavaScript script blocks such as JSON-LD structured data. The minifier turned true/false into !0/!1 (valid JavaScript but invalid JSON), which could break Google Rich Results with no visible error on the site. Only real JavaScript is minified now; JSON-LD, application/json and template blocks are left untouched. The same protection was added for non-CSS style blocks, and the page cache is flushed on update so previously cached pages are regenerated.

= 2.6.1 =
* Fixed: "Run Database Cleanup Now" now actually cleans the database. It previously reported success without deleting anything (it called a class left over from an internal rename). The button now also reports how many items were removed in each category.

= 2.6.0 =
* Added: Optional "Ignore tracking parameters when caching" setting (off by default, for advanced users). When enabled, pages are served from cache regardless of the value of tracking parameters such as gclid, fbclid or utm_*, so ad-campaign traffic is served from cache instead of creating a new cache entry per click. The ignored parameter list is configurable.

= 2.5.7 =
* Added: The cache preload panel now shows a "Completed" status with the number of pages preloaded and when the last run finished, so you can tell at a glance whether preloading happened.

= 2.5.6 =
* Fixed: Cache preload now shows live progress in the dashboard and performance screens (it previously appeared stuck at 0%), and keeps updating after a page refresh while a run is in progress.
* Fixed: The plugin version shown in the sidebar now matches the installed version.

= 2.5.5 =
* Fixed: WebP "Scan Media Library" now works reliably across hosting setups.
* Fixed: WebP conversion runs in the background with live progress until it completes.

= 2.5.4 =
* Fix: Full cache flush no longer exhausts PHP memory (fatal error / HTTP 500) on large sites. Redis keys are now removed with a non-blocking SCAN cursor in small batches via UNLINK instead of loading every key with KEYS and issuing one bulk DEL.
* Fix: Dashboard Redis key count now uses SCAN instead of KEYS, so loading the status no longer blocks Redis or spikes memory on large datasets.
* Improved: The Redis object-cache drop-in uses the same batched SCAN/UNLINK flush.

= 2.5.3 =
* New: Added a "Send PURGE requests to Varnish on cache clear" setting (enabled by default) so sites without Varnish in their request path can disable it.
* Improved: Varnish PURGE requests are now sent to the local instance (127.0.0.1) while preserving the public Host header, eliminating spurious 403 errors in server logs when the request would otherwise leave and re-enter via the public IP.

= 2.5.2 =
* Fix: Performance settings (lazy load, defer JS, etc.) no longer reset after save — API response flattened to match frontend expectations.
* Fix: Dashboard now displays cache statistics correctly — restructured stats API to nested format.
* Fix: Added missing boolean sanitization for 5 new fields (Redis, Critical CSS, Speculative Loading, Early Hints).
* Fix: SettingsController now uses wp_send_json_success() consistently with all other controllers.
* Security: Added input validation and whitelist sanitization to SettingsController::update().
* Fix: Settings file write errors are now caught and logged instead of failing silently.
* Fix: Added select validation for speculative_mode field.

= 2.5.1 =
* Fix: Settings now persist correctly — useApi unwraps the {success,data} REST envelope so form values hydrate (previously every setting reverted to defaults on reload).
* Fix: "Clear Cache" also flushes Redis (object + full-page keys). Single-post / single-URL invalidation removes the matching Redis page key too.
* Fix: Redis Full-Page Cache actually serves from Redis now — maybe_serve_cached_data checks Redis first; the buffer is mirrored to Redis on write with TTL = cache_lifetime.
* New: Redis section on the Performance page — connection status, Object Cache + Full-Page toggles, drop-in warning, manual Flush button.
* New: Redis widget on the Dashboard — Hit Rate / Memory / Cached Keys live stats, Connected/Disconnected badge, Flush button.
* New: Development Mode UI on the Dashboard — duration picker (1h/2h/4h/8h/24h/Permanent), live "is active" banner with expiry countdown, Disable button.
* UI: Sidebar nav items use the brand accent colour by default for better contrast on the dark theme.

= 2.2.0 =
* New: Redis Object Cache — store WordPress object cache in Redis
* New: Redis Full-Page Cache — serve pages from Redis memory
* New: Critical CSS — inline above-the-fold CSS, defer the rest
* New: Speculative Loading — Chrome Speculation Rules API prerender
* New: 103 Early Hints — HTTP 103 headers for faster asset loading
* New: Site Diagnostic Tool — one-click performance analysis with recommendations
* New: Settings Backup/Restore — export/import configurations
* New: Development Mode — temporary cache bypass
* New: License key activation in plugin UI
* Improved: WebP API with domain-based auth and retry logic
* Improved: PRO badges on all premium features
* Improved: Cache signature shows Redis/Disk type

= 2.0.0 =
* Major: Complete UI rebuild with Vue 3 + Vite
* New: Freemius integration for licensing
* New: Built-in 7-day Pro trial
* Improved: Performance page redesign

= 1.7.2 =
* Fix: Freemius SDK compatibility
* Improved: WebP conversion reliability

