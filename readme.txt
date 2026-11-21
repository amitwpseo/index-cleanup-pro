=== Hacked Site Index Cleaner ===
Contributors: blss
Tags: hacked site, 410, spam, seo, search console
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Remove hacked-site spam URLs and dead pages from Google. 410 Gone + noindex, a dead-URL sitemap with lastmod, verified Googlebot recrawl tracking and a status tester.

== Description ==

After a hack, Google can keep thousands of injected spam URLs (casino, pharma, Japanese keyword pages) in its index for months. Hacked Site Index Cleaner tells Google clearly that those pages are gone.

This plugin does not scan for or remove malware. Clean the site first, then use this to clean up Google's index.

* Returns 410 Gone with X-Robots-Tag: noindex for every listed URL. Never redirects.
* Add URLs by pasting, or upload CSV/TXT/XML. Search Console exports work as-is.
* Real-page guard: URLs that match published content are skipped unless you force them.
* Never touches the homepage, wp-admin, REST API or WooCommerce cart/checkout/account/shop.
* Serves /dead-urls.xml with lastmod dates (auto-splits above 50,000 URLs, auto-off date).
* Tracks verified Googlebot visits per URL (reverse + forward DNS, cached).
* Status tester checks every URL really returns 410.
* CSV report and Removals-tool list export.
* Purges WP Fastest Cache, LiteSpeed, W3TC, WP Rocket and WP Super Cache on changes.
* Pattern rules: remove a whole group at once (starts with / contains / regex), with a preview. Real pages are always skipped.
* 404 monitor: logs missing URLs, scores them for spam and adds them to the dead list in one click.
* Search spam protection: spam search phrases (/?s=casino) get 410 + noindex; optional noindex for all search pages.
* Weekly background re-test with an email alert if a URL stops returning 410.
* No external calls, no tracking.

== How to use ==

1. Tools > Index Cleaner > Add / Import. Paste or upload URLs, check the preview, Import.
2. Dashboard > Test all URLs. Everything should show 410.
3. Search Console > Sitemaps > submit dead-urls.xml.
4. Watch "Recrawled by verified Googlebot" and Search Console > Indexing > Pages.
5. After 3-4 weeks: remove the sitemap from Search Console. Keep the plugin active
   as long as you want those URLs to stay gone (deleting it makes them 404 instead).

Do NOT block these URLs in robots.txt.

== Changelog ==

= 1.1.0 =
* New: pattern rules (starts with, contains, regex) with preview and safety checks.
* New: 404 monitor with a spam score and one-click add to the dead list.
* New: search spam protection for internal search URLs.
* New: weekly automatic re-test and email alert; "Run background re-test now" button.
* Database upgrade is automatic; your existing URL list and history are kept.

= 1.0.2 =
* Renamed from "Index Cleanup Pro" to "Hacked Site Index Cleaner". Your URL list and history carry over.
* Menu label is now "Index Cleaner" (Tools > Index Cleaner).
* Notice instead of a fatal error if an older copy of the plugin is still active.

= 1.0.1 =
* URLs pasted without https:// (e.g. example.com/page) are now read correctly.
* Faster on WooCommerce sites: the list is checked first, safety checks only run on a match.
* Real-page guard result is cached in the plugin table instead of WordPress options.

= 1.0.0 =
* First release.
