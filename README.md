# Index Cleanup Pro

A WordPress plugin that gets dead and spam URLs out of Google. It is built for cleaning up after a hack, such as injected casino or pharma pages, and for retiring old content.

For every URL on its list, the plugin returns **410 Gone** with `X-Robots-Tag: noindex`. It never redirects and never touches real pages. It also serves a temporary dead-URL sitemap with `<lastmod>` dates so Google recrawls those URLs sooner, and it tracks when verified Googlebot has been back to each one.

> **Status: 1.0.0.** The code passes PHP syntax checks and unit tests for URL parsing and import. It has not yet been run on a live WordPress install, so test it on a staging site first.

## Features

**Core**
- Returns 410 Gone with `noindex` for listed URLs. Never redirects and never edits robots.txt.
- Matches exact paths and ignores query strings. Handles non-Latin slugs (Cyrillic, Polish and others).
- Never touches the homepage, wp-admin, login, REST API, AJAX, cron, or WooCommerce cart, checkout, account or shop pages.
- Keeps the list in its own database table, so it works on any site.

**URL manager**
- Paste URLs (full URLs or paths, one per line) or upload a CSV, TXT or XML sitemap file of up to 20,000 URLs.
- Google Search Console exports work as-is, because the URL column is detected automatically.
- Shows a preview before import with counts for new URLs, duplicates, protected paths and invalid lines. It warns when URLs belong to another domain.
- Remove one URL, bulk-remove, or clear all. The list has search, filters and a note per URL.

**Safety**
- Real-page guard: URLs that match a published post, page, product or other public content type are skipped by default. A conflict list shows the title, author and date, with a per-URL override.
- The guard also runs when a page is requested, so a page published later wins.

**Sitemap**
- Serves `/dead-urls.xml` with a `<lastmod>` date for each URL. The date is when the URL was added and stays stable.
- A "Re-stamp lastmod" bulk action asks Google for a fresh recrawl.
- Above 50,000 URLs the sitemap becomes an index of `dead-urls-1.xml`, `dead-urls-2.xml` and so on.
- An end date switches the sitemap off automatically (30 days by default).

**Search Console tools**
- Tracks verified Googlebot visits per URL, checked by reverse and forward DNS with results cached for 7 days. The dashboard shows "Recrawled by Google X / Y".
- A status tester requests every URL from the server and reports anything that isn't 410, such as redirects, soft 404s, plain 404s or a host firewall blocking the request.
- Exports a full CSV report and a URL list for the Removals tool.

**Technical**
- Purges the page cache when the list changes (WP Fastest Cache, LiteSpeed Cache, W3 Total Cache, WP Rocket, WP Super Cache). 410 responses are marked non-cacheable.
- Hit counters live in the plugin's own table. IP addresses are stored only as a salted hash, and the log is pruned automatically.
- Uses nonces, admin-only access and prepared queries.
- Makes no external calls and does no tracking.
- An optional setting deletes all plugin data on uninstall.

## Install

1. Download this repo as a ZIP (Code → Download ZIP).
2. In WordPress, go to Plugins → Add New → Upload Plugin, choose the ZIP, then install and activate it.
3. Open **Tools → Index Cleanup Pro**.

## Use

1. **Add / Import:** paste or upload your URLs, check the preview, then click Import.
2. **Dashboard → Test all URLs:** every URL should show 410.
3. **Search Console → Sitemaps:** submit `dead-urls.xml`.
4. Watch the Googlebot counter and Search Console → Indexing → Pages.
5. After 3–4 weeks, remove the sitemap from Search Console. Keep the plugin active for as long as you want those URLs to stay gone.

**Do not** block these URLs in robots.txt. Google has to crawl them to see the 410.

## Requirements

- WordPress 5.8 or later
- PHP 7.4 or later

## License

GPL-2.0-or-later

---

Built by [BLSS – Best Local SEO Services & Development](https://bestlocalseoservices.com/).
