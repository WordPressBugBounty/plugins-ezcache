=== ezCache ===
Contributors: upress
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.5
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Tags: cache, performance, speed, redis, optimization

Lightning-fast WordPress optimization — page caching, Redis Object Cache, Critical CSS, WebP images, CSS/JS optimization, and 15+ performance tools.

== Description ==

ezCache is the most complete WordPress performance optimization plugin. From page caching to Redis Object Cache, Critical CSS to Speculative Loading — everything you need to make your site blazing fast.

**Free Features:**
* ⚡ Page Caching — serve cached HTML instantly
* 🖼️ Lazy Loading — defer offscreen images and iframes
* 📝 HTML Minification — reduce page size
* 🔮 Speculative Loading — Chrome prerenders pages before click
* ⚡ 103 Early Hints — browser fetches CSS/JS before HTML arrives
* 🩺 Site Diagnostic Tool — one-click performance analysis
* 💾 Settings Backup/Restore — export/import configuration
* 🔧 Development Mode — temporarily disable cache

**Pro Features ($29/year):**
* 🔴 Redis Object Cache — store DB queries in RAM, 90% fewer queries
* 🔴 Redis Full-Page Cache — serve pages from Redis in <1ms
* 🎨 Critical CSS — inline above-the-fold CSS, defer the rest
* 🎨 CSS/JS Minification & Combination
* 🖼️ WebP Image Conversion — save 25-34% bandwidth
* 🚀 Cache Preloading — warm cache automatically
* 🌐 CDN Integration — URL rewriting for static assets
* 🗄️ Database Cleanup — revisions, drafts, transients
* 💓 Heartbeat Control — reduce server load
* 🔗 DNS Prefetch & Preconnect

== Installation ==

1. Upload the plugin to `/wp-content/plugins/ezcache/` or install via WordPress admin
2. Activate the plugin
3. All features are enabled automatically — 7-day Pro trial included!

== Changelog ==

= 2.5 =
* All Pro features unlocked for everyone — no licensing layer
* Removed Freemius SDK and built-in trial system
* Cleaned up trial / upgrade banners and admin notices
* Bumped UI font sizes for readability

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

