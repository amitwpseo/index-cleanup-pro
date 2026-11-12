=== Index Cleanup Pro ===
Contributors: blss
Tags: 410, seo, search console, hacked site, deindex
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Get dead and spam URLs out of Google: 410 Gone + noindex, a temporary dead-URL sitemap with lastmod, Googlebot recrawl tracking and a status tester.

== Description ==

Built for cleaning up after hacks (injected casino/pharma pages) and for retiring old content.

* Returns 410 Gone with X-Robots-Tag: noindex for every listed URL. Never redirects.
* Add URLs by pasting, or upload CSV/TXT/XML. Search Console exports work as-is.
* Real-page guard: URLs that match published content are skipped unless you force them.
* Never touches the homepage, wp-admin, REST API or WooCommerce cart/checkout/account/shop.
* Serves /dead-urls.xml with lastmod dates (auto-splits above 50,000 URLs, auto-off date).
* Tracks verified Googlebot visits per URL (reverse + forward DNS, cached).
* Status tester checks every URL really returns 410.
* CSV report and Removals-tool list export.
* Purges WP Fastest Cache, LiteSpeed, W3TC, WP Rocket and WP Super Cache on changes.
* No external calls, no tracking.

== How to use ==

1. Tools > Index Cleanup Pro > Add / Import. Paste or upload URLs, check the preview, Import.
2. Dashboard > Test all URLs. Everything should show 410.
3. Search Console > Sitemaps > submit dead-urls.xml.
4. Watch "Recrawled by verified Googlebot" and Search Console > Indexing > Pages.
5. After 3-4 weeks: remove the sitemap from Search Console. Keep the plugin active
   as long as you want those URLs to stay gone (deleting it makes them 404 instead).

Do NOT block these URLs in robots.txt.

== Changelog ==

= 1.0.0 =
* First release.
