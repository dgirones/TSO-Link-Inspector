=== TSO Link Inspector ===
Contributors: deadko
Tags: broken links, link checker, seo, maintenance, links
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.9.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find and fix broken links across your entire WordPress site without opening each post.

== Description ==

**TSO Link Inspector** scans all published posts, pages and custom post types for links, then checks each one via HTTP to detect broken links, redirects, insecure HTTP URLs and connection errors. All results are displayed in a dashboard where you can fix links directly without opening the editor.

= Key Features =

* **Scans** posts, pages, and any custom post type.
* **Detects** HTTP errors: 404, 410, 500, DNS failures, SSL errors, timeouts, and redirects.
* **Edit URLs inline** directly from the admin panel, no need to open the editor.
* **Smart URL Suggester**: automatically tests HTTPS upgrade, follows redirect chains, and tries www/non-www variants.
* **Unlink**: removes the `<a>` tag but keeps the visible text.
* **Bulk actions**: re-check, unlink, mark as OK, or delete multiple links at once.
* **Export CSV**: export any filtered view to a spreadsheet.
* **Per-article view**: click any article to see all its links in one place.
* **HTTP insecure detection**: flags active links still using HTTP instead of HTTPS.
* **Ignore list**: add domains or URL prefixes to never scan or check.
* **Scan images and iframes**: optionally detect broken `<img src>` and embedded videos.
* **Scan user comments**: optionally check links in approved comments.
* **Custom fields (ACF)**: optionally scan URL fields added by plugins like Advanced Custom Fields.
* **Daily automatic scan** and **hourly batch check** via WP-Cron. Can close the browser while checking.
* **Email alerts** for fully broken links (no redirect): send one summary after automated checks, or a periodic digest (7 / 15 / 30 days), with an optional notification address.
* **Nofollow broken links**: automatically adds `rel="nofollow"` to broken links so search engines ignore them.
* **Preserve post dates**: editing a link does not update the post modification date.
* Compatible with LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, SG Optimizer, Breeze, and Cloudflare.
* Includes Catalan and Spanish translations.

= How it works =

1. Click **Scan now** to extract all links from your posts.
2. Click **Check now** to send HTTP requests to every URL (runs server-side, you can close the browser).
3. Review results using the **Broken**, **Redirect**, **HTTP insecure** and other filter tabs.
4. Fix links using **Edit URL**, **Suggestion**, **Unlink** or **Mark as OK** from each row.

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
Yes. Enable **Custom fields (ACF / Meta)** in Settings. The plugin scans URL, HTML and text fields. You can add keys to the exclusion list to skip specific fields.

= What does the HTTP Insecure filter show? =
Links that are working (not broken) but still use `http://` instead of `https://`. Use the Suggestion button to upgrade them with one click.

= What is the Ignore List? =
A list of domains or URL prefixes (one per line) that will never be scanned or checked. Useful for domains that block bots (Amazon, Facebook) or your own site.

= Does it change the post modification date? =
Only if you want it to. Enable **Do not update modification date** in Settings to edit links without changing `post_modified`.

= What does "Mark as OK" do? =
It sets the link status to 200 OK manually without making an HTTP request. The post is not modified. Useful for URLs that are temporarily blocked but you know they work.

== Screenshots ==

1. Main dashboard with statistics and link list.
2. Filter tabs: All, Broken, Redirect, OK, HTTP insecure, Manual locks, Not checked.

== Changelog ==

= 1.9.3 =
* Security: Block SSRF targets in the HTTP checker (private/reserved IPs, unsafe hosts, redirect chains) using `wp_http_validate_url`, DNS resolution checks, and `reject_unsafe_urls`.
* Security: Validate edited URLs with `esc_url_raw()` and the same safety rules (public `http`/`https` only).
* Security: Escape admin JavaScript output for smart suggestions and diagnostics (XSS hardening).
* Fix: Apply the ignore list during scans and HTTP checks (domains/prefixes in Settings are now honored).
* Improvement: Relative and root-relative internal links (e.g. `/other-post/`) are resolved to the full site URL before HTTP checks, so links between articles on the same WordPress site are verified correctly (including deleted targets returning 404).
* Improvement: Edit/Unlink and stale-link cleanup recognise the same URL whether it is stored or written as a relative or absolute href.
* Fix: Redirect chains that end on an error page (for example 301 to www then 404) are stored as broken with the final status; they are no longer treated as a successful redirect with a misleading suggestion target.
* Improvement: Smart suggestions skip instant redirect targets that only toggle www/non-www on HTTPS (or HTTP) when the row is already broken—no “Apply” for a URL that remains a 404.
* Improvement: Smart suggestions no longer offer meaningless HTTP-only www/non-www swaps when no HTTPS exists (avoids a green-looking “fix” that does not improve security).
* Improvement: When there is no useful alternative for an HTTP-only link, the suggestion panel shows a clear warning notice instead of implying a positive change.
* Improvement: The suggestion list only shows a green “OK” style and an Apply button when the suggested URL is considered actionable (not a hard error such as 404).
* Improvement: Broken-link notification emails are sent as HTML with a TSO Link Inspector header, clickable broken URLs, article edit links, and a button to open the inspector.

= 1.9.2 =
* Fix: Plugin **Description** on the Plugins screen now translates to Catalan and Spanish when the site (or bundled language files) uses those locales.
* Fix: Translation files include the English plugin header string as `msgid` (matches the plugin header text).
* Improvement: Load translations when the plugin boots so metadata strings are available before the Plugins list is built.

= 1.9.1 =
* New: Optional email notifications for **hard-broken** links only (broken with **no** redirect destination): immediate mode sends **one summary email** per automated check batch (full background check or hourly cron), never after manual row/bulk rechecks; digest modes send every **7, 15, or 30 days** only when there is something to report.
* New: Configurable **recipient email** for notifications (falls back to the site admin email when left blank).
* New: **Manual locks** filter tab for links marked “Not broken”; manually locked rows are excluded from Broken / Redirect / OK / HTTP insecure / Not checked tabs so the same URL is not counted twice.
* Improvement: A full **Check now** clears `last_checked` for **all** rows (including manual locks) so regressions are detected reliably.
* Fix: PHP deprecation from registering a hidden settings submenu with a null parent slug (now attached to Tools and removed from the menu).
* Fix: **Last checked** falls back to **Date found** when a historic row has no check timestamp but already has status data.
* Fix: Bulk **Unlink** completion message no longer says “rechecked”.
* Fix: Clearing the list search when switching filter tabs (search query no longer sticks across tabs).
* Fix: CSV export encoding (**UTF-8 BOM**) for Excel and other spreadsheets.
* Improvement: Admin list layout for empty filter views, spacing, centered Tools wrapper, and tighter settings layout on small screens.
* Improvement: Immediate notification email wording uses correct singular/plural forms; translations updated (Catalan and Spanish).

= 1.9.0 =
* New: Comment links use an internal `source_key`, so the same URL can appear both in post content and in comments without overwriting each other in the database.
* Improvement: Comment scanning advances via a persistent cursor (`comment_ID`) so large sites eventually scan **all** approved comments; the daily cron also keeps draining comments after the post batch finishes (within the time budget).
* Improvement: After scanning each comment, stale inspector rows for that comment are removed when URLs disappear from comment body or author website fields.
* New: When a comment is permanently deleted in WordPress, matching inspector rows are removed (`deleted_comment` hook).
* Fix: Editing a comment URL from the plugin now fails with an error if the URL cannot be found in that comment (no silent “database-only” update).
* Improvement: More redirects treated as trivial noise — drop **one interior path segment** on the same host (common permalink/category consolidation next to trailing-slash normalization).

= 1.8.4 =
* Fix: Plain `http://` links that return 200 are no longer shown as green “OK”; they use the warning style and an explicit “HTTP — use HTTPS” label. The “OK” filter and OK stat count exclude these rows (they remain under **HTTP insecure**).

= 1.8.3 =
* WordPress.org compliance: internal code prefix renamed from `tso_lc_` / `TSO_LC_` to `tsoliin_` / `TSOLIIN_` (classes, defines, options, AJAX actions, cron hooks, script handles, nonces, and admin CSS/JS hooks). Existing installs migrate stored options and legacy cron events automatically on upgrade.
* Bootstrap entry point is now `tsoliin_link_inspector()`; custom hook after post updates is `tsoliin_after_post_update` (replace `tso_lc_after_post_update` in any custom integrations).

= 1.8.2 =
* Improvement: Treat WordPress login redirects (for example `/wp-admin/` -> `wp-login.php?redirect_to=...`) as authentication walls, not actionable redirect issues.

= 1.8.1 =
* Improvement: "Check now" resumes partial background progress when unchecked links are pending, instead of always restarting from 0%.

= 1.8.0 =
* Rebrand: plugin display name changed to **TSO Link Inspector** and admin page slugs moved to `tso-link-inspector`.
* i18n slug updated to `tso-link-inspector` in plugin headers, text-domain constant, and PHP translation calls.
* Prefix hardening: bootstrap function renamed to `tsoliin_link_inspector()` (superseded by the fuller `tsoliin_` / `TSOLIIN_` prefix in 1.8.3).

= 1.7.11 =
* Improvement: Retry with GET when HEAD returns `401` (some sites, including Facebook, block HEAD but answer GET).

= 1.7.10 =
* Improvement: More “transparent” redirects — `www` canonicalisation compares paths case-insensitively and with decoded segments (fixes edge cases like `publico.es` → `www.publico.es`).
* Improvement: Chrome Web Store migration (`chrome.google.com/webstore/...` → `chromewebstore.google.com/detail/...` + optional `ucbcb`) treated as OK, not as a redirect to “fix”.
* Improvement: LiberKey legacy catalog/browse URLs redirecting to `/{lang}.html` on the same host treated as transparent (marketing consolidation, not a broken link).

= 1.7.9 =
* Improvement: Treat Telegram `/dl/…` “latest installer” redirects to a versioned `.exe` on `*.telegram.org` as transparent — no redirect noise, and no Smart Suggestion to replace the stable URL (which would pin the post to one version).

= 1.7.8 =
* Improvement: Treat common “canonical” redirects as transparent — same hostname with/without `www`, optional `http`→`https`, same path, and query string adds only tracking/consent-style parameters (for example YouTube `ucbcb` / `cbrd`, UTM, `gclid`, `fbclid`).

= 1.7.7 =
* Improvement: URLs with a `#fragment` and a server redirect are treated as OK when the final response is successful (anchors are not sent over HTTP; redirect targets omit them).
* Improvement: "Recheck" (single and bulk) no longer clears "Not broken" — manual OK stays until the URL fails or you edit the URL in the post.
* Improvement: Hide "Suggestion" for manually verified rows; clearer lock icon tooltip.

= 1.7.6 =
* Improvement: Complete language selector flow for `ca`, `es_ES`, and `en`, including runtime fallback parsing from `.po` files when needed.
* Improvement: Unified list-table strings and bulk actions under plugin text domain for consistent translations.
* Improvement: Mobile responsive layout fixes for dashboard cards, filter tabs, list rows, and settings textarea to prevent horizontal overflow.
* Fix: PHPCS warning in bulk actions output (`OutputNotEscaped`) by restructuring option rendering.
* Fix: Readme metadata consistency (`Stable tag` aligned with plugin version).

= 1.4.3 =
* New: HTTP insecure filter tab and stat card for active links using http://.
* New: Per-article view — click the list icon next to any article to see all its links.
* Improvement: search box now searches URL, anchor text, article title and redirect URL simultaneously.

= 1.4.2 =
* New: Ignore list — add domains or URL prefixes to skip during scan and check.
* New: Export CSV — export any filtered view to a CSV file (Excel-compatible with UTF-8 BOM).
* New: Nofollow broken links — automatically adds rel="nofollow" to broken links on the frontend.
* New: Preserve post dates — option to not update post_modified when editing a link.

= 1.4.0 =
* Fix: Redirect false positives — trailing slash, CDN assets, WP attachment pages, download tokens and login walls are now treated as transparent.
* Fix: Fragment URLs (#comment-1898) no longer incorrectly detected as redirects.
* Fix: Smart suggest now shows already-detected redirect URL as first suggestion instantly.
* Fix: Suggest button now also appears for redirect and http:// links.
* Fix: "Data desconeguda" shown instead of "Mai" for records with status but no check date.
* Fix: Timezone double-conversion corrected (dates now store UTC).
* Fix: Next cron run times shown on dashboard.

= 1.3.0 =
* New: Redirect chain follows up to 8 hops manually (captures real final URL).
* New: 303 See Other label added.
* New: Trailing slash redirects suppressed automatically.
* New: Auth/login wall redirects (Facebook, Google) shown as 401 warning, not redirect.
* New: CDN asset and download token redirects suppressed.
* Improvement: URL column wider with full URL on hover tooltip.

= 1.2.0 =
* Rewritten from scratch to eliminate accumulated PHP parse errors.
* New: Scan images (img src), iframes, and user comments.
* New: Smart URL Suggester (HTTPS upgrade, redirect follow, www variant).
* New: Mark as OK button.
* New: Bulk unlink and bulk mark as OK.
* New: link_type column (link, image, iframe, comment).
* Fix: URLs with {} characters no longer corrupted by esc_url_raw.
* Fix: Comment links correctly handled by Unlink and Edit URL.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.9.3 =
Recommended security and reliability update: SSRF/XSS hardening, ignore list on scans, correct internal link checks, redirect-to-404 handling, and smarter URL suggestions (no misleading “Apply” for dead www/HTTP-only variants).

= 1.9.2 =
Recommended update. Fixes the plugin description not appearing in Catalan or Spanish on the Plugins screen; updates bundled translations.

= 1.9.1 =
Recommended update. Adds optional broken-link email alerts (summary/digest), a Manual locks tab with clearer tab counts, full-recheck behavior for locked rows, several admin UI fixes (search tabs, CSV encoding, empty lists), and a submenu registration fix for newer PHP versions.

= 1.9.0 =
Recommended update. Fixes comment scanning/link bookkeeping (`source_key` rows), stale cleanup after edits, cleanup when comments are deleted, stricter inline Edit URL for comments, and broader trivial redirect detection.

= 1.8.4 =
Recommended update. Corrects status display and counts for working HTTP-only links.

= 1.8.3 =
Recommended update. Renames internal identifiers to meet WordPress.org prefix length rules; migrates options and cron automatically.

= 1.8.2 =
Recommended update. Reduces false redirect warnings for protected `/wp-admin/` links that correctly route through WordPress login.

= 1.8.1 =
Recommended update. Background checks now resume from partial progress instead of restarting.

= 1.8.0 =
Recommended update. Includes the repository-required rename to TSO Link Inspector and slug/domain alignment.

= 1.7.11 =
Recommended update. Slightly better HTTP probing when servers return 401 to HEAD requests.

= 1.7.10 =
Recommended update. Fewer false redirect rows for www canonicalisation, Chrome Web Store, and LiberKey catalog URLs.

= 1.7.9 =
Recommended update. Better handling of “always latest” vendor download links (Telegram desktop).

= 1.7.8 =
Recommended update. Fewer false “redirect” rows for bare domain vs `www` plus harmless query parameters.

= 1.7.7 =
Recommended update. Better handling of hash/anchor links with redirects, and more reliable "Not broken" locking.

= 1.7.6 =
Recommended update. Improves multilingual behavior, mobile usability, and admin quality checks.

= 1.4.3 =
Recommended update. Adds HTTP insecure detection and per-article view. No database changes required.

= 1.4.2 =
Recommended update. Adds ignore list, CSV export, nofollow option and preserve dates. No database changes required.

= 1.3.0 =
Recommended update. Significantly improves redirect detection accuracy. Run "Check now" after updating to refresh existing redirect records.
