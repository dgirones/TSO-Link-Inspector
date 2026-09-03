=== TSO Link Inspector ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: broken links, link checker, seo, maintenance, links
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.4.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find and fix broken links across your entire WordPress site without opening each post.

== Description ==

**TSO Link Inspector** scans all published posts, pages and custom post types for links, then checks each one via HTTP to detect broken links, redirects, insecure HTTP URLs and connection errors. All results are displayed in a dashboard where you can fix links directly without opening the editor.

= Key Features =

* **Scans** posts, pages, and any custom post type.
* **Detects** HTTP errors: 404, 410, 500, DNS failures, SSL errors, timeouts, and redirects.
* **Edit URLs inline** for post content from the admin panel, or **Go to edit** for comments, widgets, menus, and terms in their native WordPress screens.
* **Smart URL Suggester**: automatically tests HTTPS upgrade, follows redirect chains, and tries www/non-www variants.
* **Unlink**: removes the `<a>` tag but keeps the visible text.
* **Bulk actions**: re-check, unlink, mark as OK, or delete multiple links at once.
* **Export CSV**: export any filtered view to a spreadsheet.
* **Per-article view**: click any article to see all its links in one place.
* **Posts with issues**: summary of articles that contain broken, redirected, or unchecked links.
* **Internal / External** scope tabs to separate same-site and outbound links.
* **Quality filters**: empty anchor text, generic anchors (“click here”), and links to unpublished posts.
* **View post at link**: open the front end with the matching link highlighted (post content, plain-text URLs, and comments).
* **Plain-text URLs** in post content are listed separately (not treated as hyperlinks) with **Go to edit** to open the post.
* **Convert to /path**: optional row and bulk action to replace same-site absolute URLs with site-relative paths.
* **Dashboard widget** with broken/unchecked counts and shortcuts.
* **Export CSV and PDF** for any filtered view.
* **Settings Help tab** with full documentation.
* **Configurable automatic checks**: recheck intervals for OK and broken links, plus hourly batch size.
* **HTTP insecure detection**: flags active links still using HTTP instead of HTTPS.
* **Ignore list**: add domains or URL prefixes to never scan or check.
* **Continue check / Restart from zero**: resume a stopped run, or wipe progress and recheck everything.
* **History**: Settings tab listing recent URL changes from Edit, Suggest, Convert to /path, and HTTPS (capped log; oldest rows pruned automatically).
* **Save when unverified**: Edit link / Suggest can still save a URL if this server cannot confirm it (geo-block, bot wall, timeout), with an option to ignore that domain.
* **Scan images and iframes**: optionally detect broken `<img src>` and embedded videos.
* **Scan user comments**: optionally check links in approved comments.
* **Custom fields (ACF)**: optionally scan URL fields added by plugins like Advanced Custom Fields.
* **Daily automatic scan** and **hourly batch check** via WP-Cron. Can close the browser while checking.
* **Email alerts** for fully broken links (no redirect): send one summary after automated checks, or a periodic digest (7 / 15 / 30 days), with an optional notification address.
* **Nofollow broken links**: automatically adds `rel="nofollow"` to broken links so search engines ignore them.
* **Preserve post dates**: editing a link does not update the post modification date.
* Compatible with LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, SG Optimizer, Breeze, and Cloudflare.
* **Extended scanning**: plain-text URLs in posts, Gutenberg block JSON, navigation menus, responsive media (srcset/picture), page-builder `data-*` attributes, widget sidebars, taxonomy descriptions, Site Editor templates/reusable blocks, and plain URLs in custom fields.
* **Third-party sources**: register extra link collectors with `tsoliin_register_link_source()`.
* **Optional WooCommerce scanning**: external product URLs, downloadable files, featured/gallery images, plus a Products with issues view.
* Includes Catalan and Spanish translations.

= How it works =

1. Click **Scan now** to extract all links from your posts.
2. Click **Check now** to send HTTP requests to every URL (runs server-side, you can close the browser). If you stop mid-run, use **Continue check** to resume, or **Restart from zero** to wipe progress and recheck everything.
3. Review results using the **Broken**, **Redirect**, **HTTP insecure** and other filter tabs.
4. Fix links using **Edit URL**, **Suggestion**, **Unlink** or **Mark as OK** from each row. Recent URL changes appear under **Settings → History**.

= Redirect intelligence =

The plugin follows the full redirect chain manually so it captures the real final destination, not just the last HTTP code. It automatically ignores trivial redirects (trailing slashes, CDN tokens, WP attachment pages, login walls) to avoid false positives.

== Installation ==

1. Upload the `tso-link-inspector` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin from the **Plugins** menu in WordPress.
3. Go to **Tools > TSO Link Inspector**.
4. Click **Scan now** to run the first scan, then **Check now** to verify all links.

== Frequently Asked Questions ==

= When does the automatic scan run? =
A full scan runs once daily. The link check runs hourly in small batches. Both use WP-Cron. The next scheduled runs are shown at the top of the dashboard.

= Can I fix links without opening each post? =
Yes. Use **Edit URL** to replace a URL, **Unlink** to remove the anchor tag while keeping the text, or **Suggestion** to automatically find a working alternative.

= Is it compatible with Gutenberg? =
Yes. The plugin processes `post_content` using WordPress standard filters and works with the Block Editor, Classic Editor, and most page builders.

= Will it scan custom fields (ACF)? =
Yes. Enable **Custom fields (ACF / Meta)** in Settings. The plugin scans URL, HTML and text fields, plus ACF fields stored as IDs (image, file, gallery, page link, relationship) and ACF Options pages. You can add keys to the exclusion list to skip specific fields.

= What does the HTTP Insecure filter show? =
Links that are working (not broken) but still use `http://` instead of `https://`. Use the Suggestion button to upgrade them with one click.

= What is the Ignore List? =
A list of domains or URL prefixes (one per line) that will never be scanned or checked. Useful for domains that block bots (Amazon, Facebook) or your own site.

= Does it change the post modification date? =
Only if you want it to. Enable **Do not update modification date** in Settings to edit links without changing `post_modified`.

= What does "Mark as OK" do? =
It sets the link status to 200 OK manually without making an HTTP request. The post is not modified. Useful for URLs that are temporarily blocked but you know they work.

= What is Continue check vs Restart from zero? =
If a check was stopped and unchecked links remain, **Continue check** resumes from where it left off (already-checked links stay as they are). **Restart from zero** clears check progress and rechecks every link from scratch. **Continue check** starts immediately; **Restart from zero** asks for confirmation.

= Where is the URL change History? =
Open **Tools → TSO Link Inspector → Settings → History**. It lists recent changes from Edit link, Suggest apply, Convert to /path, and Upgrade to HTTPS (including bulk actions). The log keeps a limited number of rows (oldest removed automatically). You can delete all history records; this does not change posts or the link list.

= Can I save a URL when the server cannot verify it? =
Yes. In Edit link or Suggest, if this server gets a geo-block, bot wall, or timeout, you can still save the URL. Optionally tick **ignore domain** so that host is added to the Ignore list and skipped in future scans/checks.

== External services ==

This plugin does not send data to servers operated by the plugin author. HTTP and DNS run from your WordPress server only after an administrator clicks **Scan now**, **Check now**, **Suggestion**, **Upgrade to HTTPS**, or when scheduled checks are enabled in Settings.

= HTTP checks of URLs stored on your site =

When a check runs, the plugin sends HTTP HEAD and, if needed, HTTP GET requests to each stored `http://` or `https://` URL. Data sent: the destination URL, a browser-like User-Agent, and standard `Accept` / `Accept-Language` headers. Responses are stored only in your WordPress database (status code, redirect URL, redirect chain).

Those destinations are websites already linked from your content, not a service chosen by the plugin. Terms of use and privacy policy: those of each destination site.

= DNS lookups =

Before requesting a hostname, the plugin may resolve A/AAAA records on the server (`dns_get_record` / `gethostbynamel`) so private, loopback, or reserved addresses are not contacted. This is a DNS lookup from your server; post content and user accounts are not sent.

== Screenshots ==

1. Main dashboard with statistics and link list.
2. Filter tabs: All, Broken, Redirect, OK, HTTP insecure, Manual locks, Not checked.

== Changelog ==

= 2.4.3 =
* Fix: Opening Link Inspector no longer triggers heavy inline scan/check batches on the first progress poll (session fallback only after you click Scan/Continue/Check).
* Fix: Legacy table cleanup and routine schema checks run on Link Inspector screens only, not on every wp-admin page load.
* Fix: Reading background scan/check progress no longer calls `spawn_cron()` (fewer loopback requests on page load).
* Fix: Clearer coming-soon admin notice — site-gate plugin, not WordPress core maintenance mode.
* Fix: Only one `SHOW TABLES` for leftover `{prefix}pc_tso_link_inspector(_history)` per Link Inspector admin load when the cleanup flag is set (not two).
* Improvement: Diagnostics panel reports background scan/check progress, pause/stop reasons, WP-Cron schedule, coming-soon gate, and ACF scan status.
* Fix: Reopened running jobs offer an explicit Continue scan/check here action so WP-Cron fallback resumes without automatic heavy work on page load.
* Fix: Diagnostics preserves stale heartbeats, warns about overdue cron events, and reports custom fields and ACF separately.
* Fix: Cron self-healing also runs outside plugin screens; admin-post actions and the Dashboard widget repair missing schema when needed.
* Improvement: Catalan and Spanish catalogs updated for all current plugin strings.

= 2.4.2 =
* Fix: Leftover `{prefix}pc_tso_link_inspector_history` is renamed or dropped on admin load (Tables Cleaner no longer lists two History tables).
* Fix: Stop `SHOW TABLES` for leftover `{prefix}pc_tso_link_inspector(_history)` on every admin load once they are gone.
* Fix: Leftover `{prefix}pc_tso_link_inspector(_history)` is dropped again when it reappears after manual delete (skip flag no longer blocks cleanup).
* Fix: Late admin pass removes legacy pc_ tables recreated by other plugins/tools on the same request.
* Fix: Background scan poll fallback runs during an active **Scan now** session (not only during Check now).
* Fix: Long scans no longer stop after 30 minutes while posts remain unscanned (reschedule like Check now).
* Fix: Clearer coming-soon notice — Scan now and ACF still work; only internal HTML HTTP status is skipped.

= 2.4.1 =
* Improvement: **Scan now** runs server-side in the background (close the browser; **Continue scan** / **Restart scan** when paused).
* Fix: **Check now** / **Continue check** keep progressing when WP-Cron does not fire (admin poll fallback).
* Fix: Long scan and check runs no longer stop after 30 minutes (heartbeat per batch).
* Fix: Check no longer stalls at ~99% with pending links — polling resumes automatically and the server reschedules instead of marking complete too early.
* Fix: Stale 30-minute timeout no longer stops a check while unchecked links remain.
* Improvement: Queue chip distinguishes unchecked links from scheduled automatic rechecks.
* Fix: Scan error bar visible after reload; uninstall removes background scan cron hook.
* Fix: Admin poll uses a smaller HTTP batch/time budget so progress AJAX does not time out.
* Fix: Nudge/resume only runs once per paused session (no repeated `start_bg_check` on every poll).
* Fix: Paused checks no longer auto-resume on page load — click **Continue check** explicitly.
* Fix: Bulk action dropdown sanitization; removed dead list-table tablenav code.
* Fix: Main link list no longer runs transparent-redirect cleanup on every page load (cron only).
* Fix: Background check/scan poll fallback runs only during an active admin session (avoids server overload).
* Fix: Getting started banner shows once per admin user (not on every visit until dismissed).
* Fix: List scroll helpers load on the dashboard so post-scope navigation keeps the table in view.
* Fix: Dashboard no longer runs `WP_Query` for `page` ID 0 (`url_to_postid` miss) once per internal link.
* Change: Requires WordPress 5.9+.

= 2.4.0 =
* Improvement: Filter tabs, pagination, scope tabs, and stat cards refresh the link list via AJAX (no full page reload; scroll stays on the list).
* Fix: Quality filters (empty anchor, generic anchor, unpublished target) work with live list navigation.
* Fix: Post-scope navigation (list icon, Back, posts summary) scrolls to the link table after load.
* Fix: Single-post view keeps the standard plugin title in the page header (no post title breadcrumb in the H1).
* Fix: Screen Options panel stays aligned with the plugin header instead of appearing orphaned at the top of the admin page.

See changelog.txt in the plugin folder for older versions

== Upgrade Notice ==

= 2.4.3 =
Recommended. Lighter Link Inspector admin load on staging sites; scan/check poll fallback only when you explicitly resume a run.

= 2.4.2 =
Recommended. Legacy History cleanup, scan progress when WP-Cron is delayed, coming-soon scan/ACF clarity, and fewer redundant `SHOW TABLES`.

= 2.4.1 =
Recommended. Background scan, reliable check progress when WP-Cron is delayed, and fixes for long runs timing out.

= 2.4.0 =
Recommended. AJAX filter/pagination navigation, quality-filter fixes, smoother scroll when opening a post’s links, and cleaner admin header/Screen Options layout.

