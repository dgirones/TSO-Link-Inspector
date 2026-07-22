=== TSO Link Inspector ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: broken links, link checker, seo, maintenance, links
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.3.0
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

= 2.2.6 =
* Fix: Link list table no longer outputs a nested `<thead>` on WordPress 6.6+ (duplicate header broke table layout and could hide rows).
* Fix: Empty filter views no longer show a duplicate footer header row in the link list.

= 2.2.5 =
* Fix: Scanner no longer lists WordPress attachment page URLs (`/attachment/slug`, `?attachment_id=`) — only the real media file in `/wp-content/uploads/` is stored.
* Fix: Existing attachment-page rows are removed automatically on upgrade; run **Scan now** to refresh the full list.

= 2.2.0 =
* Fix: Alt text can be saved for Gutenberg gallery and image blocks when the URL is stored in block JSON (sizes, thumbnails like `-150x150`) instead of a plain `<img>` tag.
* Fix: Alt text replacement works when `src` is the first attribute on an `<img>` tag.
* Fix: Alt text updates sync to the media-library attachment when the URL maps to one.
* Fix: **Go to edit** gallery focus opens visual mode; block editor posts avoid duplicate classic focus scripts.
* Fix: Edit modal treats equivalent URL variants as unchanged; partial inline saves when only URL or link text updates, with clear warnings.
* Fix: Edit row button stays in sync after save (jQuery data cache).
* Fix: **Recheck** and hourly cron re-sync WordPress sources when the stored URL is missing.
* Improvement: Catalan and Spanish translations updated.

= 2.1.5 =
* Fix: Edit modal treats equivalent URL variants (trailing slash, www, relative paths) as unchanged — fewer false save errors.
* Fix: Partial inline saves when only URL or only link text can be updated, with clear warnings.
* Fix: **Go to edit** gallery focus opens visual mode; block editor posts no longer load duplicate classic focus scripts.
* Fix: Edit row button stays in sync after save (jQuery data cache).
* Improvement: Catalan and Spanish translations updated.

= 2.1.0 =
* New: **Go to edit** row action for comments, widgets, navigation menus, and taxonomy terms — edit at the source instead of the inline modal.
* Fix: Gallery and image block URLs are classified as **image**, not plain text (Gutenberg JSON is no longer scanned as bare URLs).
* Fix: WordPress media-library images (including `-150x150` thumbnails in galleries) are no longer misclassified as plain-text URLs.
* Fix: Automatic DB cleanup reclassifies existing `plain` rows under `/uploads/` with image extensions to `image`.
* Fix: **Recheck** re-reads WordPress sources (post, comment, menu, widget, term, template) before HTTP — works after editing in native WP screens.
* New: **Go to edit comment** opens the WordPress comment editor (replaces inline Edit link for all comment rows).
* New: **Plain-text URL** link type for bare `http(s)://` strings in post content (distinct icon and list behaviour).
* New: **Go to edit** with deep-link (`tsoliin_link`) for post-stored links and plain-text URLs; front-end highlight on **View post at link**.
* Improvement: Widget edit links include sidebar/widget query args; menu rows open the correct menu screen.
* Improvement: Coloured **type icons** in the link list (post, comment, menu, widget, plain text, etc.).
* Improvement: URLs inside `<a href>` are stored as **links** even when the target is an image file (e.g. `.webp` downloads).
* Improvement: Widget scan removes stale rows when widgets or URLs disappear; widget rows can be rescanned on Recheck.
* Improvement: Hourly cron and background checks re-read a WordPress source only when the stored URL is missing, then check the current URL (or remove the row).
* Improvement: Fewer duplicate database schema checks on admin load.
* Fix: Comment author website URLs support **Unlink** (clears `comment_author_url`) with clearer Delete tooltips.
* Fix: Editor focus assets are not loaded on the block widgets screen (avoids `wp-editor` conflicts).
* Fix: Post title in the link list opens the editor without unwanted deep-link focus; gallery images open in visual mode.
* Fix: Smart Suggest **Apply** works for comment links.
* Improvement: Catalan and Spanish translations updated.

= 2.0.0 =
* New: WordPress **dashboard widget** with broken/unchecked counts and quick links.
* New: **Posts with issues** view — articles grouped by link problems.
* New: **Internal / External** scope tabs and sortable link type column.
* New: **Quality filters** — empty anchor, generic anchor, unpublished target.
* New: Settings **Help** tab with full documentation (Scan, cron, filters, actions, ACF, export).
* New: Dismissible **getting-started** banner on the main screen.
* New: **Open URL** and **Ignore domain** row actions.
* New: **View post at this link** — highlights the matching link on the front end (post content and comments).
* New: Optional **Convert to /path** (row action and bulk) for same-site absolute URLs.
* New: **Export report (PDF)** alongside CSV.
* New: Configurable **automatic HTTP checks** — OK/broken recheck intervals and hourly batch size.
* New: Optional WordPress **revision** before Edit link or Convert to /path.
* Improvement: ACF/Meta scanning — Link fields, relative URLs, recursive meta search, default SEO key exclusions.
* Improvement: Bulk actions with progress, delete confirmation, external-filter pagination, and list-table refresh fixes.
* Improvement: HTTP checks and scans handle www variants, HEAD→GET, `?attachment_id=` URLs, and relative paths without duplicating subfolder paths.
* Fix: Edit link static-method fatal, front-end focus links (`TSOLIIN_Support` bootstrap), and edit-link fixes from 1.9.9 (images, Manual locks UX, version badge).

= 1.9.9 =
* Fix: Edit link on images updates alt text (not anchor text inside `<a>` tags).
* Fix: URL save no longer reports failure when only link text could not be updated; partial success saves the URL and refreshes the modal.
* Fix: Edit link modal keeps the saved URL in the row after save (no stale absolute URL until hard reload).
* Fix: Relative URL edits also update matching URLs inside `srcset` attributes.
* Improvement: Edit modal shows “Alt text” for images and hides link text for iframes.
* Improvement: “Not broken” explains Manual locks (confirmation dialog, tooltips, success notice).
* Improvement: Current plugin version shown next to the admin page title.

= 1.9.8 =
* New: Edit link modal — change URL and anchor text inline; supports site-relative URLs (/path, ./, ../).
* Fix: Search across post titles works with filter tabs and bulk actions (SQL join fix).
* Fix: Filter tabs remove or refresh rows without F5 after edit, recheck, Smart Suggest, mark-as-OK, bulk unlink/recheck, and HTTP insecure filter.
* Fix: Bulk unlink/delete reload list and pagination; accurate unlink progress counts.
* Fix: Export CSV respects the current search query.
* Fix: Check now runs a full recheck instead of resuming unchecked rows only.
* Fix: Comment edit/unlink finds comments by URL equivalence, not exact SQL match.
* Fix: Redirect tab excludes transparent redirects on load; AJAX status matches normalized DB values.
* Fix: PHP 8.5 admin compatibility (nullable HTTP dependency in list table).
* Fix: Stat and tab counts use localized number formatting (no raw `&nbsp;` in UI).
* Fix: Background check progress when nonce fails; recheck button restores label on error.
* Fix: PHP 7.4 compatibility for host suffix matching in HTTP checks.
* Improvement: Catalan and Spanish translations updated (filter tabs, edit link strings).
* Improvement: On-screen help explaining Scan (find links) vs Check (HTTP test); Stop scan/check buttons; confirmation before full-site Check.
* Improvement: Check this post on a single article’s link list (only that post’s URLs).
* Fix: Edit link URL replacement limited to href/src attributes (prevents corrupting closing HTML tags when trailing slash differs).

= 1.9.7 =
* New: Widget sidebar scanning (Text, Custom HTML, and block widgets).
* New: Taxonomy term description scanning (categories, tags, and custom taxonomies).
* New: Site Editor scanning for templates, template parts, and reusable blocks (`wp_block`).
* New: Plain-text URLs inside custom fields when ACF/Meta scanning is enabled.
* New: Third-party link source API — `tsoliin_register_link_source()` for plugins and themes.
* New: Settings toggles under **Extended sources** (enabled by default until you save Settings).
* Improvement: List table shows source type icons and links to Widgets, Menus, terms, or the Site Editor where inline edit is not available.
* Fix: WordPress logout/action URLs detected and labelled separately; confirmation before opening from the admin list.
* Fix: Smart Suggest keeps meaningful redirect destinations even when re-check is bot-blocked; Apply trusts stored 301/302 targets.
* Fix: Query-stripping redirects (e.g. FilmAffinity search URLs) treated as transparent OK instead of false redirect rows.
* Fix: Stale transparent redirect rows cleaned on list load and after background checks; Redirect filter stays in sync without F5.
* Fix: Ignore-list vs server-blocked URLs use distinct statuses (-1 skipped, -7 blocked); smarter host pattern matching.
* Fix: Status badge layout on narrow screens; broken rows highlighted in the list table.
* Fix: Bulk unlink/delete refresh the list and pagination automatically; bulk unlink reports unlinked, skipped, and failed counts.
* Fix: Filter tabs remove rows that no longer match the active view after edit, Smart Suggest Apply, recheck, or mark-as-OK (no manual F5).
* Fix: Background check progress counts pending rows correctly (includes manual locks).
* Fix: `save_post` rescans respect Settings when **Force scan all posts** is disabled.
* Fix: Inline recheck/update passes `post_id` for relative internal URLs.
* Fix: Auth-redirect detection uses stricter path/query rules (fewer false bot-wall positives).
* Fix: Status code 0 on non-HTTP URLs shows as unknown, not broken.
* Improvement: Catalan and Spanish translations updated for new strings.

= 1.9.6 =
* Fix: Transparent redirects (YouTube youtu.be→watch, trailing slash, CDN noise, etc.) are stored as OK and no longer fill the Redirect tab forever.
* Fix: Automatic cleanup of existing transparent redirect rows on upgrade and after a full background check.

= 1.9.5 =
* New: Plain-text http(s) URLs in post content are scanned (same detection as comments — pasted URLs without `<a>` tags).
* New: Gutenberg block attribute scanning via `parse_blocks()` (button, file, and other blocks that store URLs in JSON).
* New: Navigation menu scanning — custom menu item URLs are checked during batch scans.
* New: Responsive media scanning — `srcset`, `<picture>` / `<source>`, `video`, `audio`, `embed`, and `<object data>`.
* New: Page-builder `data-url`, `data-href`, `data-link`, and related attributes in HTML and block markup.
* New: Settings section **Extended scanning** to toggle each source (enabled by default on existing sites until you save Settings).
* Fix: Smart Suggest follows the full redirect chain and proposes the real destination (e.g. `http://twitter.com/…` → `https://x.com/…`) instead of an intermediate HTTPS hop.
* Fix: Smart Suggest **Apply** is shown only when the server confirms HTTP 2xx; 403/401/429 bot-blocks are informational (verify in a browser before editing).
* Fix: Bulk unlink no longer modifies post content for navigation menu rows; menu Edit/Unlink actions are hidden (use **Appearance → Menus**).
* Fix: Unlink removes `<img>` and `<iframe>` elements from post content, not only `<a>` tags.
* Fix: Export CSV respects the per-post filter view; deleting all plugin records clears comment/menu scan cursors.
* Fix: Saving a post rescans all link types in that post even when optional scanners are disabled in Settings.
* Fix: Protocol-relative redirect URLs (`//cdn…`) are resolved correctly during HTTP checks.
* Fix: Ignored URLs show a neutral **Skipped** badge instead of broken styling.
* Fix: Live dashboard counts refresh after row actions; filter tabs keep locale number formatting.
* Fix: Comment plain-text URL unlink; suggestion panel close button; duplicate suggestion panels.
* Fix: YouTube `youtu.be` → `watch` treated as a transparent redirect (same video).
* Fix: Recheck updates redirect sub-line and manual-lock badge without reload; **Mark as OK** keeps row actions.
* Fix: Background check re-enables **Check now** if progress polling fails.
* Improvement: Catalan and Spanish translations updated for new strings.

= 1.9.4 =
* Fix: Comment scan now detects plain-text http(s) URLs in comment bodies (not only `<a href>` links), so broken links like pasted URLs are found and checked.
* Improvement: Relative and root-relative internal links (e.g. `/other-post/`) are resolved to the full site URL before HTTP checks, so links between articles on the same WordPress site are verified correctly (including deleted targets returning 404).
* Improvement: Edit/Unlink and stale-link cleanup recognise the same URL whether it is stored or written as a relative or absolute href.
* Fix: Redirect chains that end on an error page (for example 301 to www then 404) are stored as broken with the final status; they are no longer treated as a successful redirect with a misleading suggestion target.
* Improvement: Smart suggestions skip misleading www-only or HTTP-only “fixes”; Apply is shown only for actionable alternatives.
* Improvement: Broken-link notification emails are HTML with a TSO Link Inspector header, clickable URLs, and a button to open the inspector.
* Improvement: Removed legacy migration code (old checker table and `tso_lc_*` options).
* Fix: Dashboard stats query (`get_stats`) is cached per request (no duplicate SQL on admin load).
* Fix: Frontend `rel="nofollow"` for broken links caches broken URLs per post (no duplicate SQL when `the_content` and `wp_trim_excerpt` run multiple times).
* Fix: PHP 8.1+ deprecation on the hidden Settings screen (`strip_tags(null)` in admin-header).
* Improvement: Tested up to WordPress 7.0.

= 1.9.3 =
* Security: Block SSRF targets in the HTTP checker (private/reserved IPs, unsafe hosts, redirect chains) using `wp_http_validate_url`, DNS resolution checks, and `reject_unsafe_urls`.
* Security: Validate edited URLs with `esc_url_raw()` and the same safety rules (public `http`/`https` only).
* Security: Escape admin JavaScript output for smart suggestions and diagnostics (XSS hardening).
* Fix: Apply the ignore list during scans and HTTP checks (domains/prefixes in Settings are now honored).

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

= 2.3.0 =
Optional WooCommerce product link scanning and Products with issues view. Also improves Classic Editor focus scroll, gallery/image detection, HTTPS suggestions for auth-walled URLs, and row actions. Enable WooCommerce scanning under Settings if you run a store.

= 2.2.6 =
Recommended update. Fixes duplicate/broken link list table headers on WordPress 6.6+.

= 2.2.5 =
Recommended update. Stops duplicate gallery attachment-page URLs; keeps only uploads file links. Run **Scan now** after updating.

= 2.2.0 =
Recommended update. Gallery alt-text fixes (Gutenberg blocks, thumbnails, media library), editor focus, inline edit reliability, and smarter Recheck/cron sync. Run **Scan now** once after updating.

= 2.1.5 =
Recommended update. Editor focus, inline edit reliability, and Recheck/cron sync improvements. Run **Scan now** once after updating.

= 2.1.0 =
Recommended update. External sources use **Go to edit**; gallery images classify correctly; **Recheck** syncs from WordPress before HTTP. Run **Scan now** after updating to refresh link types.

= 2.0.0 =
Major update: dashboard widget, Posts with issues, internal/external and quality filters, Help tab, view-post-at-link highlight, Convert to /path, PDF export, configurable cron schedule, ACF/meta improvements, and many admin fixes. Run **Scan now** after updating, then **Check now** once.

= 1.9.9 =
Recommended update. Edit link fixes for images and relative URLs, clearer Manual locks (“Not broken”) flow, and version badge in the admin header.

= 1.9.8 =
Recommended update. Clearer Scan vs Check UI with Stop buttons, per-post checking, plus anchor editing, search/filter fixes, and PHP 8.5/7.4 compatibility.

= 1.9.7 =
Recommended update. Extended scanning (widgets, terms, FSE, meta plain URLs, third-party API), many admin and redirect fixes, and automatic list refresh after bulk actions. Run **Scan now** after updating, then **Check now** once to normalize existing rows.

= 1.9.6 =
Recommended update. YouTube and other harmless redirects no longer clutter the Redirect filter; run **Check now** once after updating to normalize existing rows.

= 1.9.5 =
Recommended update. Scans many more link sources (plain-text URLs, Gutenberg JSON, menus, srcset/picture, data attributes), safer Smart Suggest (Apply only on confirmed 2xx), and numerous admin/HTTP fixes. Run **Scan now** after updating.

= 1.9.4 =
Recommended update. Internal link checks, redirect-to-404 handling, smarter suggestions, HTML broken-link emails, performance fix, Settings screen PHP 8.1 fix, WordPress 7.0 tested.

= 1.9.3 =
Recommended security update. Hardens HTTP checks against SSRF, validates edited URLs, fixes XSS in admin suggestion UI, and applies the ignore list during scans.

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
