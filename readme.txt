=== TSO Stack Inspector ===

Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: shortcode, block, maintenance, gutenberg, uninstall
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find where plugin shortcodes, blocks, and metadata are used before you deactivate or uninstall.

== Description ==

TSO Stack Inspector helps agencies and site owners answer a critical question before removing a plugin: **where is it still used?**

Scan posts, pages, custom post types, reusable blocks, Site Editor templates, widgets, navigation menus, and wp_options for:

* **Shortcodes** registered by a plugin or entered manually
* **Gutenberg blocks** declared by a plugin
* **Post meta prefixes** inferred from plugin code (including page-builder JSON meta)

**Features**

* **By plugin** — pick an installed plugin and run a batched scan before uninstall
* **Risk verdict** — after each scan, a clear safe / review / risk summary for uninstall decisions
* **Replace (dry-run)** — preview and apply shortcode/block renames in post content (batched, with confirmation and undo backups)
* **Orphans** — find shortcodes/blocks still in content but no longer registered
* **History diff** — compare two saved scans
* **Inactive plugins audit** — quickly see which deactivated plugins still leave traces (uses the site index)
* **Shortcode finder** — search any shortcode tag across the site
* **Block finder** — search a block namespace (e.g. `contact-form-7/contact-form-selector`)
* **Theme scan** — active or inactive themes
* **Compare plugins** — shared vs exclusive signatures before migrations
* **Inventory** — list registered shortcodes and discovered plugin signatures
* **Scan history** — limited, auto-pruned, with delete/clear
* **Export CSV / Print PDF** — download scan results, orphans, or inactive-plugin audits as CSV, or open a printable report after a scan
* **Refresh signatures** — rebuild the cached plugin profile without waiting 24 hours
* **Options Cleaner link** — jump to TSO Options & Tables Cleaner when installed to remove leftover options and tables after uninstall
* **Site index** — built in the background when you open the admin screen (or when you scan); reused for faster scans and audits
* **Background scan** — optional checkbox to keep a large scan running via WP-Cron after you close the admin tab; reopen the screen later to see the finished results
* **Ignore list** — hide noisy shortcodes, blocks, or meta/option keys from results
* **Grouped results** — scan hits grouped by location, with copy-tag and a pre-uninstall checklist
* **Orphans → Replace** — jump from an orphan tag to the Replace tab already filled in
* **Admin UI languages** — English, Spanish, and Catalan in the Tools screen; community translations via translate.wordpress.org after publication

**How it works**

The plugin reads your content and widget options locally. It does not send data to external services. Plugin signatures (shortcodes, blocks, meta prefixes) are discovered by scanning plugin PHP sources and cached for 24 hours. The site content index is stored under uploads and rebuilt when content or settings change.

**Multisite**

Works on WordPress Multisite: scans, caches, and settings are per-site. Uninstalling from the network admin cleans up every site in the network, not just the main site.

**Limitations**

* Static code scan may miss dynamically registered shortcodes or blocks
* Meta prefix detection is heuristic; always review results before uninstalling
* Very large sites run scans in batches; tick **Background scan** to let a long scan keep going via WP-Cron after you close the tab, or keep the tab open otherwise

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through the Plugins screen
3. Open **Tools → TSO Stack Inspector** (or click **Open inspector** under the plugin on the Plugins screen)

== Frequently Asked Questions ==

= Where is the admin menu? =

Go to **Tools → TSO Stack Inspector**. Administrators also see **Open inspector** under the plugin name on **Plugins → Installed Plugins**.

= Does this delete anything? =

Scans, orphans, and audits are read-only. The optional **Replace** tab can edit `post_content` (shortcode/block renames) after a dry-run preview, with short-lived undo backups — always keep your own site backup. Stack Inspector does not delete plugins, options, or database tables; use TSO Options & Tables Cleaner for leftover options/tables after uninstall.

= Does it work with Elementor or other page builders? =

Yes, partially. The scanner also inspects common builder meta keys such as `_elementor_data` for embedded shortcodes and blocks.

= Will a scan slow down my site? =

Scans run in the admin in small batches and only when you start them. Front-end visitors are not affected.

== Privacy Policy ==

TSO Stack Inspector stores only:

* Your admin UI language preference in user meta (`tso_stack_inspector_ui_lang`)
* Optional scan settings in `wp_options` (`tso_stack_inspector_scan_settings`), including history size limit
* A short index of recent completed scans in `wp_options` (`tso_stack_inspector_scan_history`)
* Temporary scan job data in transients (auto-expire)
* Optional site-index cache, scan-history JSON, and replace-backup JSON under `wp-content/uploads/tso-stack-inspector/` (local disk only; directory is denied via `.htaccess` / `web.config` + empty `index.html`; removed on uninstall when possible)
* Replace backup index in `wp_options` (`tso_stack_inspector_replace_backups`, ~48h retention)

No data is transmitted to external servers. Scans run only when an administrator starts them from Tools → TSO Stack Inspector.

== Screenshots ==

1. Shortcode scan results grouped by location, ready to review before uninstalling a plugin.
2. Risk verdict banner — a clear safe / review / risk summary shown after a scan.
3. Inactive plugins audit — see at a glance which deactivated plugins still leave shortcodes, blocks, options, or meta behind.
4. Scan settings — background scan, history size limit, and ignore list.

== Changelog ==

= 1.0.0 =
* First version
