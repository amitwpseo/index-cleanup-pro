# Hacked Site Index Cleaner – Remove Spam URLs from Google (WordPress Plugin)

![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue) ![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)

**Get hacked-site spam URLs out of Google.** Hacked Site Index Cleaner is a free WordPress plugin that returns **410 Gone** with `noindex` for injected spam pages, serves a temporary dead-URL sitemap so Google recrawls them sooner, and shows you which URLs Google has actually revisited.

> This plugin cleans up **Google's index** after a hack. It does **not** scan for or remove malware. Clean the site first, then use this.

---

## The problem

After a WordPress hack (casino spam, pharma spam, Japanese keyword hack, injected doorway pages), thousands of spam URLs can stay indexed in Google for months, even after the site is clean. They hurt your brand in search results, waste crawl budget, and can keep the "hacked" reputation alive.

Deleting the pages is not enough. Google needs a clear signal that each URL is permanently gone, and it needs to recrawl them to see it.

## What this plugin does

- **Returns `410 Gone` + `X-Robots-Tag: noindex`** for every URL on your list. It never redirects.
- **Serves `/dead-urls.xml`**, a temporary sitemap with a `<lastmod>` date for each URL, to nudge Google into recrawling them.
- **Tracks verified Googlebot visits** per URL (reverse + forward DNS check), so you can see real progress.
- **Tests every URL** from your own server and reports anything that does not return 410.
- **Protects real pages**: URLs that match published content are skipped unless you force them.
- **Removes whole groups with pattern rules**: starts with, contains or regex, with a preview.
- **Finds spam URLs you missed**: a 404 monitor scores missing URLs for spam and adds them in one click.
- **Blocks spam search pages**: `/?s=casino-bonus` style URLs get 410 + noindex.
- **Watches itself**: weekly background re-test with an email alert if a URL stops returning 410.

## Why 410, and not 404, a redirect or robots.txt

| Method | Result |
|---|---|
| **410 Gone** | Clear, permanent "removed" signal. Google usually drops these fastest. |
| 404 Not Found | Works, but Google may keep rechecking for weeks. |
| 301 redirect to the homepage | Bad. Google can treat it as a soft 404 and keep the spam URL alive. |
| Blocking in robots.txt | Bad. Google can't crawl the URL, so it never sees the 410 and keeps it indexed. |

## Features

**URL manager**
- Paste URLs (full URLs or paths) or upload a CSV, TXT or XML sitemap file (up to 20,000 URLs). Google Search Console exports work as-is.
- Preview before import: new URLs, duplicates, protected paths, invalid lines, other-domain warnings.
- Remove one URL, bulk remove, or clear all. Search, filters and notes per URL.

**Safety**
- Real-page guard on import and again at request time: published posts, pages, products and other public content always win.
- Never touches the homepage, wp-admin, login, REST API, AJAX, cron, or WooCommerce cart, checkout, account and shop pages.
- Exact path matching only; query strings are ignored.

**Pattern rules (1.1.0)**
- Rule types: URL starts with, URL contains, regular expression.
- Preview shows what the rule hits in the 404 log, how many listed URLs match, and which real published pages it would leave alone.
- Too-broad patterns are refused (very short, matches the homepage or ordinary pages like `about` or `contact`).
- Real pages and protected paths always win.

**404 monitor (1.1.0)**
- Logs paths that return 404 (path, hit count, visitor agent; no IP address), capped at 5,000 URLs.
- Spam score 0–100 from spam words, Japanese/Chinese/Cyrillic/Thai/Arabic text on sites in another language, hyphen chains and digit runs.
- Filter by score, add selected or everything above a score to the dead list, or hide false alarms.

**Search spam protection (1.1.0)**
- Spam search phrases (`/?s=...`) return 410 + noindex before WordPress runs the search. Normal searches are untouched.
- Optional `noindex` for every search results page. Add your own spam words.

**Monitoring (1.1.0)**
- Weekly background re-test in small WP-Cron batches, plus a "Run now" button.
- Email alert listing URLs that no longer return 410, with a test-email button.

**Sitemap**
- `/dead-urls.xml` with `<lastmod>`; splits automatically above 50,000 URLs.
- "Re-stamp lastmod" bulk action to ask for a fresh recrawl.
- Auto-off end date (30 days by default) so it is not forgotten.

**Search Console tools**
- Dashboard: URLs on the list, 410s served, "Recrawled by verified Googlebot X / Y".
- Status tester with progress bar; flags redirects, soft 404s, plain 404s and host firewall blocks.
- CSV report and a URL list for the Search Console Removals tool.

**Technical**
- Purges WP Fastest Cache, LiteSpeed Cache, W3 Total Cache, WP Rocket and WP Super Cache when the list changes; 410 responses are never cached.
- Nonces, admin-only access, prepared queries. IPs are stored only as a salted hash; the hit log is pruned automatically.
- No external calls, no tracking, optional delete-all-data on uninstall.

## Install

1. Download `hacked-site-index-cleaner-vX.Y.Z.zip` from the [Releases](../../releases) page.
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, install and activate.
3. Open **Tools → Index Cleaner**.

> Use the zip from Releases. The green "Code → Download ZIP" button on GitHub creates a folder with a different name.

## How to clean up Google's index after a hack

1. **Clean the site first** and make sure the spam pages are gone or no longer served as real content.
2. Collect the spam URLs. In Search Console: **Indexing → Pages**, tick *Unsubmitted pages only*, open the *Indexed* list and **Export**. You can also export from **Performance → Pages**.
3. In the plugin: **Add / Import**, upload the file, check the preview, **Import**.
4. **Dashboard → Test all URLs.** Every URL should show 410.
5. Open a few spam URLs and two real pages in a private browser window to confirm.
6. In Search Console: **Sitemaps → add `dead-urls.xml` → Submit.**
7. For the most visible spam URLs, optionally use the **Removals** tool to hide them from results while the 410 takes effect.
8. Watch **Indexing → Pages** and the plugin's Googlebot counter. Expect weeks, not days.
9. After 3–4 weeks, remove `dead-urls.xml` from Search Console. Keep the plugin active as long as you want the URLs to stay gone.

**Do not** block these URLs in `robots.txt`.

## FAQ

**Does it remove malware?**
No. It only handles how Google sees the dead URLs. Use a security scanner and clean the site first.

**How long until the spam disappears from Google?**
Google decides. Small lists can clear in a couple of weeks; thousands of URLs usually take several weeks. The plugin makes sure Google gets a clear answer and a reason to come back.

**Should I use 410 or 404?**
410 is the clearer signal. Search Console reports both under "Not found (404)".

**Will it affect my real pages?**
No. Matching is exact, the homepage and system paths are protected, and published content is skipped by default.

**What happens if I delete the plugin?**
The listed URLs return 404 instead of 410. That still works, only slower. Keep it active until the spam is gone from the index.

**Can I use it for old pages that are not spam?**
Yes. It works for any URL you want Google to treat as permanently removed.

## Requirements

- WordPress 5.8 or later
- PHP 7.4 or later

## Status

Used in a real hacked-site cleanup of about 2,300 spam URLs: every URL returned 410 in the built-in test, and Google's verified crawler started revisiting the URLs within minutes of the sitemap being resubmitted. Version 1.1.0 passes PHP syntax checks and unit tests for URL parsing, spam scoring and rule matching, but has not been run on a wide range of WordPress setups, so try it on staging first. Bug reports and pull requests are welcome.

## Changelog

See [`readme.txt`](readme.txt). Latest: **1.1.0** – pattern rules, 404 monitor with spam score, search spam protection, weekly re-test with email alert. Upgrades from 1.0.x keep all data.

## License

GPL-2.0-or-later

---

Built by [BLSS – Best Local SEO Services & Development](https://bestlocalseoservices.com/).
