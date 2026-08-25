=== TSO Link Inspector ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: broken links, link checker, seo, maintenance, links
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.3.9
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

= 2.3.9 =
* Fix: Suggested URL Apply no longer says “No changes to save” when the new URL is a shorter path of the old one (e.g. Microsoft `/software-download/windows8` → `/software-download/`).

= 2.3.8 =
* Fix: Do not run `CREATE TABLE` for the History log when `{prefix}tso_link_inspector_history` already exists (stops `Table ... already exists` on admin load).

= 2.3.7 =
* New: History tab (Settings) lists recent URL changes from Edit, Suggest, relative, and HTTPS actions (max 500 rows; oldest auto-pruned; Delete all button).
* New: Continue check / Restart from zero when unchecked links remain after a stopped run.
* Fix: Edit link modal — checkbox labels aligned with the box on multi-line text.
* Improvement: Clearer wording for “save unverified URL” and “ignore domain” checkboxes.
* Fix: Admin dashboard reuses pending-check and cron-queue counts (fewer duplicate SQL queries on page load).
* Dev: CLI unit smoke tests via `php scripts/run-unit-tests.php`.

= 2.3.6 =
* Improvement: Edit link and Suggested URL can save a URL when this server cannot verify it (geo-block, bot wall, or timeout), with an option to ignore that domain.
* Fix: Delete and other row actions no longer error when the list row was already removed after editing the post.
* Fix: Dashboard totals no longer show 0 (`dai.ly` LIKE); Chrome Web Store IPv6 “Cannot connect”; **Go to edit** scroll for Classic `youtu.be` and Gutenberg code view.
* Fix: Scan ACF ID fields (image, file, gallery, page link, relationship) and Options pages; resolve Elementor/ACF dynamic tags via **Go to edit**.
* Fix: **Check now** resumes partial progress; dashboard Broken/OK/Redirect/HTTP counts only use checked links (no stale totals during a run).

= 2.3.5 =
* Fix: Check queue, bulk Mark as OK/unlink, Recheck sync, cron scan coverage, and verified HTTPS Suggest.
* Fix: **Go to edit** for multi-gallery classic posts, YouTube embeds, and Jetpack galleries.
* Improvement: Admin transient lock performance; tested up to WordPress 7.1.
* Fix: WordPress.org review — External services in readme, POST export/reset, report CSS/JS via enqueue (no inline style/onclick).
* Fix: Count published posts without loading every ID; Internal/External tab counts stay in SQL; pin DNS before each HTTP hop.
* Fix: Scoped dashboard/PDF stats, stale reset counts, cron incomplete-scan timestamp, blocked-redirect reporting, ACF Options Go to edit, and front-end focus needle.

= 2.3.3 =
* New: Bulk action **Upgrade selected to HTTPS** — only when the server confirms a working HTTPS URL (same rules as Suggestion → Apply; unverified bot-wall suggestions are skipped).
* New: **Links per page** screen option (default 20, min 10, max 500, filterable via `tsoliin_max_per_page`). Also used by Posts/Products with issues.
* Fix: Spanish/Catalan strings for the new bulk HTTPS and per-page UI; bulk HTTPS skip summary no longer mislabels skips as menu/widget/term.
* Fix: Getting started banner translation casing and consistent secondary buttons.
* Fix: Leftover empty/legacy `{prefix}pc_tso_link_inspector` is dropped on admin load (exact SHOW TABLES match).

= 2.3.2 =
* Fix: Legacy empty `{prefix}pc_tso_link_inspector` is dropped on every admin load until gone (no permanent skip flag), with exact SHOW TABLES matching via esc_like.
* Fix: Spanish/Catalan strings for Links per page, Upgrade selected to HTTPS, and related bulk HTTPS messages.

= 2.3.1 =
* Fix: Remove leftover empty/legacy `{prefix}pc_tso_link_inspector` table on admin load (Tables Cleaner no longer lists two Link Inspector tables after upgrade).
* Fix: Getting started banner uses the translated Scan description (matching msgid casing) and consistent secondary buttons for Help / Settings.

= 2.3.0 =
* New: Optional **WooCommerce** scanning (Settings) for external product URLs, downloadable files, featured image, and gallery — off by default.
* New: **Products with issues** view (when WooCommerce scanning is enabled), same layout as Posts with issues.
* Improvement: Product field links open the product editor via **Go to edit** (not the inline modal).
* Improvement: Unified row action order (Go to edit, Edit link, Recheck, Not broken, Unlink, Delete, Ignore domain). Removed redundant **Open URL** (use the main URL link).
* Fix: Classic Editor **Go to edit** scrolls to the matched link in Visual and Text modes (including shortcode/plain URLs and TinyMCE iframe content).
* Fix: Bare `[gallery]` shortcodes no longer pull every image attached to the post (only explicit `ids=` / `include=`).
* Fix: Image rows require real markup (img / gallery / block); stale ghost rows can be cleared with Unlink or Recheck.
* Fix: HTTP to HTTPS suggestions for same-path URLs that return 401/403 (e.g. `/wp-admin/`) are offered as HTTPS upgrades instead of "HTTP only".
* Improvement: Catalan and Spanish translations updated.

See changelog.txt in the plugin folder for versions before 2.3.0.

== Upgrade Notice ==

= 2.3.9 =
Fix: Suggest Apply no longer reports “no changes” when the destination URL is a shorter path on the same host.

= 2.3.8 =
Recommended. Stops a harmless `Table ... already exists` log when the History table is already present.

= 2.3.7 =
Recommended. History tab for URL edits, Continue check / Restart from zero, clearer Edit link checkboxes, and fewer duplicate admin SQL queries.

= 2.3.6 =
Recommended. Save unverified URLs, ACF/Elementor Go to edit, resume Check now, and consistent dashboard counters.

= 2.3.5 =
Recommended. Check/bulk/cron fixes, classic multi-gallery editor focus, and admin performance.

= 2.3.3 =
Recommended. Adds verified bulk HTTP→HTTPS upgrade and Links per page screen option, plus translation and legacy-table cleanups.

= 2.3.2 =
Recommended if Tables Cleaner still lists a leftover `pc_tso_link_inspector` table after 2.3.1.

= 2.3.1 =
Recommended. Drops leftover legacy `pc_tso_link_inspector` and fixes Getting started translations/buttons.

= 2.3.0 =
Optional WooCommerce product link scanning and Products with issues view. Also improves Classic Editor focus scroll, gallery/image detection, HTTPS suggestions for auth-walled URLs, and row actions. Enable WooCommerce scanning under Settings if you run a store.
