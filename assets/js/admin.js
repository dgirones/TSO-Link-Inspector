(function () {
	'use strict';

	var cfg = window.tsosiAdminConfig || {};
	var i18n = cfg.i18n || {};
	var scanActive = false;
	var pollTimer = null;

	function qs(sel, root) {
		return (root || document).querySelector(sel);
	}

	function qsa(sel, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(sel));
	}

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('_ajax_nonce', cfg.nonce || '');
		Object.keys(data || {}).forEach(function (key) {
			body.append(key, data[key]);
		});
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		}).then(function (response) {
			return response.json();
		});
	}

	function showPanel() {
		var panel = qs('#tsosi-scan-panel');
		if (panel) {
			panel.hidden = false;
		}
	}

	function setProgress(text) {
		var el = qs('#tsosi-scan-progress');
		if (el) {
			el.textContent = text;
		}
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function escapeAttr(str) {
		return escapeHtml(str).replace(/'/g, '&#39;');
	}

	var lastScanResults = [];
	var allScanResults = [];
	var lastScanMeta = {};
	var lastOrphans = { shortcodes: [], blocks: [] };
	var lastInactiveRows = [];
	var indexAbort = false;
	var indexBusy = false;

	function ignoreTokens() {
		return Array.isArray(cfg.ignoreTokens) ? cfg.ignoreTokens : [];
	}

	function isIgnoredValue(value) {
		var hay = String(value || '').toLowerCase();
		if (!hay) {
			return false;
		}
		return ignoreTokens().some(function (token) {
			var t = String(token || '').toLowerCase();
			return !!t && (hay === t || hay.indexOf(t) === 0);
		});
	}

	function showScanActions(show) {
		var actions = qs('#tsosi-scan-actions');
		if (actions) {
			actions.hidden = !show;
		}
	}

	function showScanToolbar(show) {
		var toolbar = qs('#tsosi-scan-toolbar');
		if (toolbar) {
			toolbar.hidden = !show;
		}
	}

	// A row counts as "content" (currently visible/active use) when it's a shortcode or
	// block still in post content, OR an active widget instance placed in a live sidebar
	// (source_type 'widget' -- see tsosi_scan_active_widget_instances() /
	// tsosi_scan_risk_row_is_active_widget() in tsosi-scan-risk.php). Keep this in sync
	// with assessRiskClient() and the server-side risk assessment: the "Show content
	// matches only" button on a "danger" banner must actually surface the row that
	// caused that banner, not hide it.
	function isContentBucketRow(row) {
		var matchType = String(row.match_type || row.source_type || '');
		return matchType === 'shortcode' || matchType === 'block' || String(row.source_type || '') === 'widget';
	}

	function getFilteredResults() {
		var text = (qs('#tsosi-filter-results') || {}).value || '';
		var type = (qs('#tsosi-filter-type') || {}).value || '';
		var bucket = (qs('#tsosi-filter-bucket') || {}).value || '';
		text = String(text).toLowerCase().trim();
		return allScanResults.filter(function (row) {
			var matchType = String(row.match_type || row.source_type || '');
			if (type && matchType !== type && String(row.source_type || '') !== type) {
				return false;
			}
			if (bucket === 'content' && !isContentBucketRow(row)) {
				return false;
			}
			if (bucket === 'data' && isContentBucketRow(row)) {
				return false;
			}
			if (isIgnoredValue(row.match_value)) {
				return false;
			}
			if (!text) {
				return true;
			}
			var hay = [
				row.object_label,
				row.source_type,
				row.match_type,
				row.match_value,
				row.context,
			].join(' ').toLowerCase();
			return hay.indexOf(text) >= 0;
		});
	}

	function updateFilterCount(shown, total) {
		var el = qs('#tsosi-filter-count');
		if (!el) {
			return;
		}
		var tpl = i18n.filterCount || 'Showing %1$s of %2$s';
		el.textContent = tpl.replace('%1$s', shown).replace('%2$s', total);
	}

	function copyButtonHtml(value) {
		if (!value) {
			return '';
		}
		return '<button type="button" class="button button-small tsosi-copy-tag" data-copy="' + escapeAttr(value) + '">' +
			escapeHtml(i18n.copyTag || 'Copy') + '</button>';
	}

	function locationLabel(row) {
		var location = row.object_label || row.source_type || '';
		if (row.object_subtype) {
			location += ' (' + row.object_subtype + ')';
		}
		return location;
	}

	function groupKey(row) {
		return [row.edit_url || '', row.object_label || '', row.object_subtype || ''].join('\0');
	}

	function groupRows(rows) {
		var map = {};
		var order = [];
		(rows || []).forEach(function (row) {
			var key = groupKey(row);
			if (!map[key]) {
				map[key] = [];
				order.push(key);
			}
			map[key].push(row);
		});
		return order.map(function (key) {
			return map[key];
		});
	}

	function groupResultsEnabled() {
		var box = qs('#tsosi-group-results');
		return !box || box.checked;
	}

	function renderResults(rows) {
		var tbody = qs('#tsosi-results-body');
		if (!tbody) {
			return;
		}
		tbody.innerHTML = '';
		if (!rows || !rows.length) {
			var empty = document.createElement('tr');
			var td = document.createElement('td');
			td.colSpan = 5;
			td.textContent = i18n.noResults || 'No usage found.';
			empty.appendChild(td);
			tbody.appendChild(empty);
			updateFilterCount(0, allScanResults.length);
			return;
		}

		function appendRow(html) {
			var tr = document.createElement('tr');
			tr.innerHTML = html;
			tbody.appendChild(tr);
		}

		if (groupResultsEnabled()) {
			groupRows(rows).forEach(function (group) {
				var first = group[0];
				var matches = group.map(function (row) {
					return '<div class="tsosi-match-line"><span class="tsosi-match-type">' +
						escapeHtml(row.match_type || row.source_type || '') + '</span> <code>' +
						escapeHtml(row.match_value || '') + '</code> ' + copyButtonHtml(row.match_value) +
						(row.context ? '<span class="tsosi-match-context">' + escapeHtml(row.context) + '</span>' : '') +
						'</div>';
				}).join('');
				appendRow(
					'<td>' + escapeHtml(locationLabel(first)) + '</td>' +
					'<td colspan="3">' + matches + '</td>' +
					'<td>' + (first.edit_url ? '<a class="button button-small" href="' + escapeAttr(first.edit_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(i18n.editLabel || 'Edit') + '</a>' : '—') + '</td>'
				);
			});
		} else {
			rows.forEach(function (row) {
				appendRow(
					'<td>' + escapeHtml(locationLabel(row)) + '</td>' +
					'<td>' + escapeHtml(row.match_type || row.source_type || '') + '</td>' +
					'<td><code>' + escapeHtml(row.match_value || '') + '</code> ' + copyButtonHtml(row.match_value) + '</td>' +
					'<td>' + escapeHtml(row.context || '') + '</td>' +
					'<td>' + (row.edit_url ? '<a class="button button-small" href="' + escapeAttr(row.edit_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(i18n.editLabel || 'Edit') + '</a>' : '—') + '</td>'
				);
			});
		}
		updateFilterCount(rows.length, allScanResults.length);
	}

	function applyFilters() {
		lastScanResults = getFilteredResults();
		renderResults(lastScanResults);
	}

	function setAllResults(rows) {
		allScanResults = rows || [];
		applyFilters();
	}

	function collectQuery(mode) {
		var data = { mode: mode };
		if (mode === 'plugin') {
			data.plugin_file = (qs('#tsosi-plugin-file') || {}).value || '';
		} else if (mode === 'shortcode') {
			var shortcodeInput = qs('#tsosi-shortcode');
			var shortcodeSelect = qs('#tsosi-shortcode-select');
			data.shortcode = (shortcodeInput && shortcodeInput.value) || (shortcodeSelect && shortcodeSelect.value) || '';
		} else if (mode === 'block') {
			var blockInput = qs('#tsosi-block');
			var blockSelect = qs('#tsosi-block-select');
			data.block = (blockInput && blockInput.value) || (blockSelect && blockSelect.value) || '';
		} else if (mode === 'theme') {
			data.theme = (qs('#tsosi-theme-stylesheet') || {}).value || '';
		}
		var bg = qs('#tsosi-background-scan');
		if (bg && bg.checked) {
			data.background = '1';
		}
		return data;
	}

	// Cap on how many items of one needle category (shortcodes, blocks, meta/option
	// prefixes) are spelled out inline in the scan-progress summary. A plugin like
	// Jetpack can have 80+ signatures, and dumping all of them into one unbroken line of
	// running text makes that line unreadable without adding any information the
	// "Discovered signatures" table (above the scan panel) doesn't already show in full.
	var TSOSI_NEEDLE_SUMMARY_CAP = 12;

	function formatNeedles(needles) {
		if (!needles) {
			return '—';
		}
		var parts = [];
		['shortcodes', 'blocks', 'meta_prefixes', 'option_prefixes'].forEach(function (key) {
			var list = needles[key];
			if (list && list.length) {
				var shown = list.slice(0, TSOSI_NEEDLE_SUMMARY_CAP).join(', ');
				var remaining = list.length - TSOSI_NEEDLE_SUMMARY_CAP;
				if (remaining > 0) {
					shown += ' ' + (i18n.moreCount || '+%1$s more').replace('%1$s', remaining);
				}
				parts.push(key.replace('_prefixes', '') + ': ' + shown);
			}
		});
		return parts.length ? parts.join(' | ') : '—';
	}

	function csvEscape(value) {
		var str = String(value == null ? '' : value);
		// Guard against CSV/formula injection: a cell starting with =, +, -, or @ can be
		// read as a formula by Excel/Sheets. Content here can include post titles or other
		// text from any author able to publish content, so treat it as untrusted on export.
		if (/^[=+\-@]/.test(str)) {
			str = "'" + str;
		}
		if (/[",\n\r]/.test(str)) {
			return '"' + str.replace(/"/g, '""') + '"';
		}
		return str;
	}

	function downloadCsv(filename, headers, rows) {
		var lines = [headers.map(csvEscape).join(',')];
		(rows || []).forEach(function (row) {
			lines.push(row.map(csvEscape).join(','));
		});
		var blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
		var url = URL.createObjectURL(blob);
		var link = document.createElement('a');
		link.href = url;
		link.download = filename;
		link.click();
		URL.revokeObjectURL(url);
	}

	function exportCsv() {
		var headers = cfg.csvHeaders || ['Location', 'Type', 'Match', 'Context', 'Edit URL'];
		// Export what's currently on screen (active search/type/bucket filters and the
		// ignore list applied), not every raw match -- see lastScanResults above.
		var rows = lastScanResults.map(function (row) {
			return [
				locationLabel(row),
				row.match_type || '',
				row.match_value || '',
				row.context || '',
				row.edit_url || '',
			];
		});
		downloadCsv('stack-inspector-' + new Date().toISOString().slice(0, 10) + '.csv', headers, rows);
	}

	function reportStylesheetHref() {
		var url = String(cfg.reportCssUrl || '');
		var ver = String(cfg.reportCssVer || '');
		if (!url) {
			return '';
		}
		return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'ver=' + encodeURIComponent(ver);
	}

	function buildReportHtml() {
		var headers = cfg.csvHeaders || ['Location', 'Type', 'Match', 'Context', 'Edit URL'];
		var title = (cfg.siteName || '') + ' — ' + (i18n.reportTitle || 'Stack Inspector report');
		var summary = qs('#tsosi-scan-progress');
		var summaryText = summary ? summary.textContent : '';
		var rowsHtml = '';
		var cssHref = reportStylesheetHref();
		var criticalCss =
			'body{margin:0;padding:24px 32px;font-family:system-ui,sans-serif;color:#1d2327;}' +
			'table{width:100%;border-collapse:collapse;font-size:13px;}' +
			'th,td{border:1px solid #dcdcde;padding:8px 10px;text-align:left;}';

		// Match exportCsv(): the printable report should reflect the filtered/ignore-list
		// view the admin is looking at, not the raw unfiltered result set.
		var exportRows = lastScanResults;
		if (!exportRows.length) {
			rowsHtml = '<tr><td colspan="5">' + escapeHtml(i18n.noResults || 'No usage found.') + '</td></tr>';
		} else {
			exportRows.forEach(function (row) {
				var location = row.object_label || row.source_type || '';
				if (row.object_subtype) {
					location += ' (' + row.object_subtype + ')';
				}
				rowsHtml +=
					'<tr><td>' + escapeHtml(location) + '</td><td>' + escapeHtml(row.match_type || '') +
					'</td><td><code>' + escapeHtml(row.match_value || '') + '</code></td><td>' +
					escapeHtml(row.context || '') + '</td><td>' + escapeHtml(row.edit_url || '') + '</td></tr>';
			});
		}

		var headCells = headers.map(function (h) {
			return '<th>' + escapeHtml(h) + '</th>';
		}).join('');

		return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escapeHtml(title) +
			'</title><style>' + criticalCss + '</style>' +
			(cssHref ? '<link rel="stylesheet" href="' + escapeAttr(cssHref) + '">' : '') +
			'</head><body class="tsosi-report"><h1>' + escapeHtml(title) + '</h1><p>' +
			escapeHtml(summaryText) + '</p><table><thead><tr>' + headCells +
			'</tr></thead><tbody>' + rowsHtml + '</tbody></table></body></html>';
	}

	function exportPdf() {
		var html = buildReportHtml();
		var reportWindow = window.open('', '_blank');
		if (!reportWindow) {
			window.alert(i18n.exportPdfBlocked || 'Allow pop-ups.');
			return;
		}
		reportWindow.document.open();
		reportWindow.document.write(html);
		reportWindow.document.close();
		reportWindow.focus();
	}

	function finishProgress(data) {
		scanActive = false;
		showScanToolbar(false);
		var summary = i18n.scanSummary || 'Scanned %1$s posts. Needles: %2$s. Matches: %3$s.';
		var text = summary
			.replace('%1$s', data.total_posts || 0)
			.replace('%2$s', formatNeedles(data.needles))
			.replace('%3$s', data.result_count || 0);
		if (data.needles_empty) {
			text += ' ' + (i18n.needlesEmpty || '');
		}
		if (data.cached) {
			text = (i18n.scanCached || '') + ' ' + text;
		}
		setProgress(text);
		lastScanMeta = data;
		renderRiskBanner(data.risk || null, data.results || allScanResults, data);
		showScanActions(true);
	}

	function renderRiskBanner(risk, results, meta) {
		var el = qs('#tsosi-risk-banner');
		if (!el) {
			return;
		}
		meta = meta || {};
		if (!risk) {
			risk = assessRiskClient(results || []);
		}
		if (!risk || !risk.level) {
			el.hidden = true;
			el.innerHTML = '';
			return;
		}
		el.hidden = false;
		el.className = 'tsosi-risk-banner tsosi-risk-' + String(risk.level);

		// Main column (label/help/CTA) and the "before you uninstall" checklist are built
		// separately and laid out side by side (CSS), so the checklist no longer stacks
		// below the CTA and inflates the banner's height.
		var mainHtml =
			'<strong>' + escapeHtml(risk.label || '') + '</strong>' +
			(risk.help ? '<span class="tsosi-risk-help">' + escapeHtml(risk.help) + '</span>' : '');

		var cleanerUrl = meta.cleaner_url || '';
		var cleanerAvailable = meta.cleaner_available;
		if (cleanerAvailable && cleanerUrl && (risk.level === 'safe' || risk.level === 'review')) {
			mainHtml += '<p class="tsosi-risk-cta"><a class="button button-primary" href="' + escapeAttr(cleanerUrl) + '">' +
				escapeHtml(i18n.cleanerCta || 'Clean leftovers in Options Cleaner') + '</a></p>';
		} else if (!cleanerAvailable && (risk.level === 'safe' || risk.level === 'review')) {
			mainHtml += '<p class="tsosi-risk-help">' + escapeHtml(i18n.cleanerMissing || '') + '</p>';
		}

		if (risk.level === 'danger') {
			mainHtml += '<p class="tsosi-risk-cta"><button type="button" class="button" id="tsosi-risk-filter-content">' +
				escapeHtml(i18n.filterContentOnly || 'Show content matches only') + '</button></p>';
		} else if (risk.level === 'review') {
			mainHtml += '<p class="tsosi-risk-cta"><button type="button" class="button" id="tsosi-risk-filter-data">' +
				escapeHtml(i18n.filterDataOnly || 'Show data matches only') + '</button></p>';
		}

		var html = '<div class="tsosi-risk-main">' + mainHtml + '</div>';

		if (risk.level === 'danger' || risk.level === 'review') {
			html += '<div class="tsosi-checklist"><p><strong>' + escapeHtml(i18n.checklistTitle || 'Before you uninstall') + '</strong></p><ol>';
			html += '<li>' + escapeHtml(i18n.checklist1 || '') + '</li>';
			html += '<li>' + escapeHtml(i18n.checklist2 || '') + '</li>';
			html += '<li>' + escapeHtml(i18n.checklist3 || '') + '</li>';
			html += '<li>' + escapeHtml(i18n.checklist4 || '') + '</li>';
			html += '<li>' + escapeHtml(i18n.checklist5 || '') + '</li>';
			html += '</ol></div>';
		}

		el.innerHTML = html;

		var contentBtn = qs('#tsosi-risk-filter-content', el);
		if (contentBtn) {
			contentBtn.addEventListener('click', function () {
				var bucket = qs('#tsosi-filter-bucket');
				if (bucket) {
					bucket.value = 'content';
					applyFilters();
				}
			});
		}
		var dataBtn = qs('#tsosi-risk-filter-data', el);
		if (dataBtn) {
			dataBtn.addEventListener('click', function () {
				var bucket = qs('#tsosi-filter-bucket');
				if (bucket) {
					bucket.value = 'data';
					applyFilters();
				}
			});
		}

		updateCleanerLink(cleanerUrl, !!cleanerAvailable);
	}

	function updateCleanerLink(url, available) {
		var link = qs('#tsosi-open-cleaner');
		var missing = qs('#tsosi-cleaner-missing');
		if (link) {
			if (available && url) {
				link.href = url;
				link.hidden = false;
			} else if (!available) {
				link.hidden = true;
			}
		}
		if (missing) {
			missing.hidden = !!available;
		}
	}

	function assessRiskClient(results) {
		var content = 0;
		var data = 0;
		// Mirrors the server-side tsosi_scan_risk_row_is_active_widget() check in
		// tsosi-scan-risk.php, and shares isContentBucketRow() with getFilteredResults()
		// so the "Show content matches only" button always surfaces whatever caused the
		// risk banner in the first place.
		(results || []).forEach(function (row) {
			if (isContentBucketRow(row)) {
				content += 1;
			} else {
				data += 1;
			}
		});
		if (!content && !data) {
			return { level: 'safe', label: i18n.riskSafe || 'Safe', help: '' };
		}
		if (content > 0) {
			return { level: 'danger', label: i18n.riskDanger || 'Risk', help: '' };
		}
		return { level: 'review', label: i18n.riskReview || 'Review', help: '' };
	}

	function stopPoll() {
		if (pollTimer) {
			window.clearInterval(pollTimer);
			pollTimer = null;
		}
	}

	function startBackgroundPoll() {
		stopPoll();
		pollTimer = window.setInterval(function () {
			post('tsosi_scan_poll', {}).then(function (payload) {
				if (!payload || !payload.success) {
					return;
				}
				var data = payload.data || {};
				if (data.done) {
					stopPoll();
					setAllResults(data.results || []);
					finishProgress(data);
					return;
				}
				if (data.background && data.progress !== undefined) {
					setProgress((i18n.backgroundScan || 'Background scan') + ': ' + data.progress + '%');
				}
			});
		}, 3000);
	}

	function runSteps() {
		return post('tsosi_scan_step', {}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error((payload && payload.data && payload.data.message) || i18n.scanError);
			}
			var data = payload.data || {};
			var label = (i18n.progress || 'Progress') + ': ' + (data.progress || 0) + '%';
			if (data.total_steps) {
				label += ' (' + (data.processed_steps || 0) + '/' + data.total_steps + ')';
			}
			setProgress(label);
			if (data.results && data.results.length) {
				setAllResults(data.results);
			}
			if (!data.done) {
				return runSteps();
			}
			setAllResults(data.results || []);
			finishProgress(data);
		});
	}

	function startScan(mode) {
		indexAbort = true;
		showPanel();
		showScanActions(false);
		showScanToolbar(true);
		scanActive = true;
		allScanResults = [];
		lastScanResults = [];
		lastScanMeta = {};
		setProgress(i18n.scanning || 'Scanning…');
		renderResults([]);
		var riskEl = qs('#tsosi-risk-banner');
		if (riskEl) {
			riskEl.hidden = true;
			riskEl.innerHTML = '';
		}

		return post('tsosi_scan_start', collectQuery(mode))
			.then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error((payload && payload.data && payload.data.message) || i18n.scanError);
				}
				var data = payload.data || {};
				if (data.background) {
					setProgress(i18n.backgroundScan || 'Background scan started…');
					startBackgroundPoll();
					return;
				}
				if (data.cached && data.done) {
					setAllResults(data.results || []);
					finishProgress(data);
					return;
				}
				return runSteps();
			})
			.catch(function (err) {
				scanActive = false;
				showScanToolbar(false);
				setProgress(err.message || i18n.scanError);
			});
	}

	function cancelScan() {
		if (!scanActive) {
			return;
		}
		post('tsosi_scan_cancel', {}).then(function (payload) {
			stopPoll();
			scanActive = false;
			showScanToolbar(false);
			setProgress((payload && payload.data && payload.data.message) || i18n.scanCancelled || 'Cancelled.');
		});
	}

	function setCacheBarText(text, ready) {
		var bar = qs('#tsosi-cache-bar');
		var el = qs('#tsosi-cache-status-text');
		if (bar) {
			bar.setAttribute('data-ready', ready ? '1' : '0');
		}
		if (el) {
			el.className = ready ? 'tsosi-cache-ready' : 'tsosi-cache-empty';
			el.textContent = text;
		}
	}

	function payloadNeedsIndex(payload) {
		return !!(payload && payload.data && payload.data.code === 'tsosi_need_index');
	}

	function stepIndexLoop() {
		if (indexAbort) {
			indexBusy = false;
			return Promise.resolve(false);
		}
		return post('tsosi_index_step', {}).then(function (payload) {
			if (indexAbort) {
				indexBusy = false;
				return false;
			}
			if (!payload || !payload.success) {
				indexBusy = false;
				throw new Error((payload && payload.data && payload.data.message) || i18n.scanError);
			}
			var data = payload.data || {};
			var pct = data.progress || 0;
			setCacheBarText((i18n.indexBuilding || 'Preparing site index… %s%').replace('%s', String(pct)), false);
			if (data.aborted) {
				indexBusy = false;
				return false;
			}
			if (data.done) {
				cfg.indexReady = true;
				indexBusy = false;
				setCacheBarText(i18n.indexReadyLabel || 'Site index cached', true);
				return true;
			}
			return stepIndexLoop();
		});
	}

	function ensureIndex(opts) {
		opts = opts || {};
		if (scanActive && !opts.force) {
			return Promise.resolve(false);
		}
		if (!cfg.indexStorageReady) {
			return Promise.resolve(false);
		}
		if (cfg.indexReady && !opts.force) {
			return Promise.resolve(true);
		}
		indexAbort = false;
		indexBusy = true;
		setCacheBarText((i18n.indexBuilding || 'Preparing site index… %s%').replace('%s', '0'), false);
		return post('tsosi_index_start', { force: opts.force ? '1' : '0' }).then(function (payload) {
			if (!payload || !payload.success) {
				indexBusy = false;
				throw new Error((payload && payload.data && payload.data.message) || i18n.scanError);
			}
			var data = payload.data || {};
			if (data.done) {
				cfg.indexReady = true;
				indexBusy = false;
				setCacheBarText(i18n.indexReadyLabel || 'Site index cached', true);
				return true;
			}
			return stepIndexLoop();
		});
	}

	function rebuildCache() {
		indexAbort = true;
		cfg.indexReady = false;
		post('tsosi_rebuild_cache', {}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error((payload && payload.data && payload.data.message) || i18n.scanError);
			}
			var data = payload.data || {};
			if (data.done) {
				cfg.indexReady = true;
				setCacheBarText(i18n.indexReadyLabel || 'Site index cached', true);
				window.alert(i18n.indexRebuilt || 'Site index rebuilt.');
				return;
			}
			indexAbort = false;
			indexBusy = true;
			return stepIndexLoop().then(function () {
				window.alert(i18n.indexRebuilt || 'Site index rebuilt.');
			});
		}).catch(function (err) {
			indexBusy = false;
			window.alert(err.message || i18n.scanError);
		});
	}

	function compareFieldLabel(field) {
		var map = {
			shortcodes: i18n.compareFieldShortcodes || 'Shortcodes',
			blocks: i18n.compareFieldBlocks || 'Blocks',
			meta_prefixes: i18n.compareFieldMeta || 'Meta prefixes',
			option_prefixes: i18n.compareFieldOptions || 'Option prefixes',
		};
		return map[field] || field;
	}

	function compareItemsHtml(items) {
		if (!items || !items.length) {
			return '<span class="tsosi-compare-empty">—</span>';
		}
		return '<ul class="tsosi-compare-list">' + items.map(function (item) {
			return '<li><code>' + escapeHtml(item) + '</code></li>';
		}).join('') + '</ul>';
	}

	function runCompare() {
		var a = (qs('#tsosi-compare-a') || {}).value || '';
		var b = (qs('#tsosi-compare-b') || {}).value || '';
		var box = qs('#tsosi-compare-results');
		post('tsosi_compare_plugins', { plugin_a: a, plugin_b: b }).then(function (payload) {
			if (!payload || !payload.success || !box) {
				window.alert(i18n.scanError || 'Error');
				return;
			}
			var d = payload.data || {};
			var nameA = d.plugin_a && d.plugin_a.name ? d.plugin_a.name : 'A';
			var nameB = d.plugin_b && d.plugin_b.name ? d.plugin_b.name : 'B';
			var colShared = i18n.compareShared || 'Shared';
			var colOnlyA = (i18n.compareOnlyA || 'Only in %s').replace('%s', nameA);
			var colOnlyB = (i18n.compareOnlyB || 'Only in %s').replace('%s', nameB);
			var html = '<h3>' + escapeHtml(nameA) + ' vs ' + escapeHtml(nameB) + '</h3>';
			html += '<table class="widefat striped tsosi-compare-table"><thead><tr>';
			html += '<th>' + escapeHtml(i18n.compareColType || 'Type') + '</th>';
			html += '<th>' + escapeHtml(colShared) + '</th>';
			html += '<th>' + escapeHtml(colOnlyA) + '</th>';
			html += '<th>' + escapeHtml(colOnlyB) + '</th>';
			html += '</tr></thead><tbody>';
			['shortcodes', 'blocks', 'meta_prefixes', 'option_prefixes'].forEach(function (field) {
				var shared = (d.shared && d.shared[field]) ? d.shared[field] : [];
				var onlyA = (d.only_a && d.only_a[field]) ? d.only_a[field] : [];
				var onlyB = (d.only_b && d.only_b[field]) ? d.only_b[field] : [];
				html += '<tr>';
				html += '<th scope="row">' + escapeHtml(compareFieldLabel(field)) + '</th>';
				html += '<td>' + compareItemsHtml(shared) + '</td>';
				html += '<td>' + compareItemsHtml(onlyA) + '</td>';
				html += '<td>' + compareItemsHtml(onlyB) + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			box.innerHTML = html;
			box.hidden = false;
		});
	}

	function loadHistory(id) {
		post('tsosi_load_history', { history_id: id }).then(function (payload) {
			if (!payload || !payload.success) {
				return;
			}
			showPanel();
			var data = payload.data || {};
			setAllResults(data.results || []);
			finishProgress({
				total_posts: data.meta && data.meta.total_posts ? data.meta.total_posts : 0,
				result_count: (data.results || []).length,
				needles: data.meta && data.meta.needles ? data.meta.needles : {},
				needles_empty: data.meta && data.meta.needles_empty,
				cached: data.meta && data.meta.cached,
			});
		});
	}

	function deleteHistory(id, row) {
		if (!window.confirm(i18n.historyDeleteConfirm || 'Delete this scan from history?')) {
			return;
		}
		post('tsosi_delete_history', { history_id: id }).then(function (payload) {
			if (!payload || !payload.success) {
				window.alert((payload && payload.data && payload.data.message) || (i18n.scanError || 'Error'));
				return;
			}
			if (row && row.parentNode) {
				row.parentNode.removeChild(row);
			}
			var tbody = qs('#tsosi-history-table tbody');
			if (tbody && !tbody.querySelector('tr')) {
				window.location.reload();
			}
		});
	}

	function clearHistory() {
		if (!window.confirm(i18n.historyClearConfirm || 'Clear all scan history?')) {
			return;
		}
		post('tsosi_clear_history', {}).then(function (payload) {
			if (!payload || !payload.success) {
				window.alert((payload && payload.data && payload.data.message) || (i18n.scanError || 'Error'));
				return;
			}
			window.location.reload();
		});
	}

	var lastReplaceIds = [];

	function replacePreview() {
		var kind = (qs('#tsosi-replace-kind') || {}).value || 'shortcode';
		var from = (qs('#tsosi-replace-from') || {}).value || '';
		var to = (qs('#tsosi-replace-to') || {}).value || '';
		var status = qs('#tsosi-replace-status');
		var box = qs('#tsosi-replace-results');
		var applyBtn = qs('#tsosi-replace-apply');
		if (status) {
			status.textContent = i18n.replacePreviewing || 'Previewing…';
		}
		if (applyBtn) {
			applyBtn.disabled = true;
		}
		lastReplaceIds = [];
		post('tsosi_replace_preview', { kind: kind, from: from, to: to }).then(function (payload) {
			if (!payload || !payload.success) {
				var msg = (payload && payload.data && payload.data.message) || (i18n.scanError || 'Error');
				if (status) {
					status.textContent = msg;
				}
				return;
			}
			var d = payload.data || {};
			lastReplaceIds = d.post_ids || [];
			var count = d.count || 0;
			if (status) {
				var tpl = i18n.replacePreviewCount || '%d post(s) would change.';
				status.textContent = tpl.replace('%d', String(count)) + (d.capped ? ' ' + (i18n.replaceCapped || '') : '');
			}
			if (!box) {
				return;
			}
			if (!count) {
				box.innerHTML = '<p>' + escapeHtml(i18n.replaceNone || 'No matching content found.') + '</p>';
				box.hidden = false;
				return;
			}
			var html = '<table class="widefat striped"><thead><tr>' +
				'<th>' + escapeHtml(i18n.replaceColTitle || 'Title') + '</th>' +
				'<th>' + escapeHtml(i18n.replaceColType || 'Type') + '</th><th></th></tr></thead><tbody>';
			(d.items || []).forEach(function (item) {
				html += '<tr><td>' + escapeHtml(item.title || '') + '</td><td><code>' + escapeHtml(item.type || '') +
					'</code></td><td>' + (item.edit ? '<a class="button button-small" href="' + escapeAttr(item.edit) +
					'" target="_blank" rel="noopener noreferrer">' + escapeHtml(i18n.editLabel || 'Edit') + '</a>' : '—') +
					'</td></tr>';
			});
			html += '</tbody></table>';
			box.innerHTML = html;
			box.hidden = false;
			if (applyBtn) {
				applyBtn.disabled = lastReplaceIds.length === 0;
			}
		});
	}

	function replaceApply() {
		if (!lastReplaceIds.length) {
			return;
		}
		if (!window.confirm(i18n.replaceConfirm || 'Apply replacement to listed posts?')) {
			return;
		}
		var kind = (qs('#tsosi-replace-kind') || {}).value || 'shortcode';
		var from = (qs('#tsosi-replace-from') || {}).value || '';
		var to = (qs('#tsosi-replace-to') || {}).value || '';
		var status = qs('#tsosi-replace-status');
		var applyBtn = qs('#tsosi-replace-apply');
		if (applyBtn) {
			applyBtn.disabled = true;
		}
		if (status) {
			status.textContent = i18n.replaceApplying || 'Applying…';
		}

		var lastBackupId = '';
		var totalUpdated = 0;

		function runBatch(ids) {
			if (!ids.length) {
				if (status) {
					var done = (i18n.replaceDone || 'Replacement finished.') + ' ' +
						(i18n.replaceUpdatedTotal || 'Updated %d.').replace('%d', String(totalUpdated));
					if (lastBackupId) {
						done += ' ' + (i18n.replaceBackupSaved || 'Backup saved for undo.');
					}
					status.textContent = done;
				}
				if (lastBackupId) {
					window.setTimeout(function () {
						window.location.reload();
					}, 900);
				} else {
					replacePreview();
				}
				return;
			}
			var batch = ids.slice(0, 25);
			var rest = ids.slice(25);
			post('tsosi_replace_apply', {
				kind: kind,
				from: from,
				to: to,
				post_ids: batch.join(','),
				backup_id: lastBackupId || '',
			}).then(function (payload) {
				if (!payload || !payload.success) {
					var msg = (payload && payload.data && payload.data.message) || (i18n.scanError || 'Error');
					if (status) {
						status.textContent = msg;
					}
					if (applyBtn) {
						applyBtn.disabled = false;
					}
					return;
				}
				var d = payload.data || {};
				totalUpdated += d.updated || 0;
				if (d.backup_id) {
					lastBackupId = d.backup_id;
				}
				if (status) {
					var tpl = i18n.replaceBatch || 'Updated %1$s (skipped %2$s, errors %3$s). Continuing…';
					status.textContent = tpl
						.replace('%1$s', String(d.updated || 0))
						.replace('%2$s', String(d.skipped || 0))
						.replace('%3$s', String(d.errors || 0));
				}
				runBatch(rest);
			});
		}

		runBatch(lastReplaceIds.slice());
	}

	function replaceUndo(id) {
		if (!id) {
			return;
		}
		if (!window.confirm(i18n.replaceUndoConfirm || 'Restore previous content from this backup?')) {
			return;
		}
		post('tsosi_replace_undo', { backup_id: id }).then(function (payload) {
			if (!payload || !payload.success) {
				window.alert((payload && payload.data && payload.data.message) || (i18n.scanError || 'Error'));
				return;
			}
			var d = payload.data || {};
			window.alert((i18n.replaceUndoDone || 'Restored %d post(s).').replace('%d', String(d.restored || 0)));
			window.location.reload();
		});
	}

	function renderOrphansTables(sc, bl) {
		var box = qs('#tsosi-orphans-results');
		if (!box) {
			return;
		}
		var html = '';
		html += '<h3>' + escapeHtml(i18n.orphansShortcodes || 'Orphan shortcodes') + '</h3>';
		if (!sc.length) {
			html += '<p>—</p>';
		} else {
			html += '<table class="widefat striped"><thead><tr><th>Tag</th><th>' + escapeHtml(i18n.auditColMatches || 'Matches') + '</th><th>' + escapeHtml(i18n.orphansSamples || 'Samples') + '</th><th>' + escapeHtml(i18n.auditColAction || 'Action') + '</th></tr></thead><tbody>';
			sc.forEach(function (row) {
				var samples = (row.samples || []).map(function (s) {
					return s.edit ? '<a href="' + escapeAttr(s.edit) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(s.title || '') + '</a>' : escapeHtml(s.title || '');
				}).join(', ');
				html += '<tr><td><code>[' + escapeHtml(row.tag || '') + ']</code> ' + copyButtonHtml(row.tag || '') + '</td><td>' + escapeHtml(String(row.count || 0)) + '</td><td>' + samples + '</td><td>';
				if (row.replace_url) {
					html += '<a class="button button-small" href="' + escapeAttr(row.replace_url) + '">' + escapeHtml(i18n.replaceTag || 'Replace') + '</a>';
				} else {
					html += '—';
				}
				html += '</td></tr>';
			});
			html += '</tbody></table>';
		}
		html += '<h3>' + escapeHtml(i18n.orphansBlocks || 'Orphan blocks') + '</h3>';
		if (!bl.length) {
			html += '<p>—</p>';
		} else {
			html += '<table class="widefat striped"><thead><tr><th>Block</th><th>' + escapeHtml(i18n.auditColMatches || 'Matches') + '</th><th>' + escapeHtml(i18n.orphansSamples || 'Samples') + '</th><th>' + escapeHtml(i18n.auditColAction || 'Action') + '</th></tr></thead><tbody>';
			bl.forEach(function (row) {
				var samples = (row.samples || []).map(function (s) {
					return s.edit ? '<a href="' + escapeAttr(s.edit) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(s.title || '') + '</a>' : escapeHtml(s.title || '');
				}).join(', ');
				html += '<tr><td><code>' + escapeHtml(row.name || '') + '</code> ' + copyButtonHtml(row.name || '') + '</td><td>' + escapeHtml(String(row.count || 0)) + '</td><td>' + samples + '</td><td>';
				if (row.replace_url) {
					html += '<a class="button button-small" href="' + escapeAttr(row.replace_url) + '">' + escapeHtml(i18n.replaceTag || 'Replace') + '</a>';
				} else {
					html += '—';
				}
				html += '</td></tr>';
			});
			html += '</tbody></table>';
		}
		box.innerHTML = html;
		box.hidden = false;
	}

	function runOrphans() {
		var status = qs('#tsosi-orphans-status');
		var btn = qs('#tsosi-run-orphans');
		var exportBtn = qs('#tsosi-orphans-export');
		if (status) {
			status.textContent = i18n.orphansRunning || 'Searching…';
		}
		if (btn) {
			btn.disabled = true;
		}
		if (exportBtn) {
			exportBtn.hidden = true;
		}

		function finishFind(payload) {
			if (btn) {
				btn.disabled = false;
			}
			if (!payload || !payload.success) {
				var msg = (payload && payload.data && payload.data.message) || (i18n.scanError || 'Error');
				if (status) {
					status.textContent = msg;
				}
				return;
			}
			var d = payload.data || {};
			var sc = d.shortcodes || [];
			var bl = d.blocks || [];
			lastOrphans = { shortcodes: sc, blocks: bl };
			if (status) {
				status.textContent = (i18n.orphansDone || 'Found %1$s orphan shortcodes, %2$s orphan blocks.')
					.replace('%1$s', String(sc.length))
					.replace('%2$s', String(bl.length));
			}
			renderOrphansTables(sc, bl);
			if (exportBtn) {
				exportBtn.hidden = !(sc.length || bl.length);
			}
		}

		function findOnce() {
			return post('tsosi_find_orphans', {});
		}

		ensureIndex().then(function () {
			return findOnce();
		}).then(function (payload) {
			if (payloadNeedsIndex(payload)) {
				cfg.indexReady = false;
				return ensureIndex().then(findOnce);
			}
			return payload;
		}).then(finishFind).catch(function (err) {
			if (btn) {
				btn.disabled = false;
			}
			if (status) {
				status.textContent = err.message || i18n.scanError;
			}
		});
	}

	function exportOrphansCsv() {
		var rows = [];
		(lastOrphans.shortcodes || []).forEach(function (row) {
			rows.push([row.tag || '', 'shortcode', row.count || 0, (row.samples || []).map(function (s) { return s.title || ''; }).join('; ')]);
		});
		(lastOrphans.blocks || []).forEach(function (row) {
			rows.push([row.name || '', 'block', row.count || 0, (row.samples || []).map(function (s) { return s.title || ''; }).join('; ')]);
		});
		downloadCsv('stack-inspector-orphans-' + new Date().toISOString().slice(0, 10) + '.csv', ['Tag', 'Type', 'Count', 'Samples'], rows);
	}

	function runHistoryDiff() {
		var a = (qs('#tsosi-diff-a') || {}).value || '';
		var b = (qs('#tsosi-diff-b') || {}).value || '';
		var box = qs('#tsosi-history-diff-results');
		if (!a || !b || a === b) {
			window.alert(i18n.diffPickTwo || 'Pick two different history entries.');
			return;
		}
		post('tsosi_history_diff', { id_a: a, id_b: b }).then(function (payload) {
			if (!payload || !payload.success || !box) {
				window.alert((payload && payload.data && payload.data.message) || (i18n.scanError || 'Error'));
				return;
			}
			var d = payload.data || {};
			var summary = d.summary || {};
			var html = '<div class="tsosi-history-diff-summary">';
			html += '<div class="tsosi-history-diff-stat"><strong>' + escapeHtml(String(summary.added || 0)) + '</strong>';
			html += '<span>' + escapeHtml(i18n.diffAdded || 'Added in A (not in B)') + '</span></div>';
			html += '<div class="tsosi-history-diff-stat"><strong>' + escapeHtml(String(summary.removed || 0)) + '</strong>';
			html += '<span>' + escapeHtml(i18n.diffRemoved || 'Removed (in B, not in A)') + '</span></div>';
			html += '</div>';

			function rowsTable(title, rows) {
				var out = '<div class="tsosi-history-diff-table-wrap"><h4>' + escapeHtml(title) + '</h4>';
				if (!rows || !rows.length) {
					return out + '<p class="description">—</p></div>';
				}
				out += '<table class="widefat striped tsosi-history-diff-table"><thead><tr>';
				out += '<th>' + escapeHtml(i18n.diffColLocation || i18n.auditColPlugin || 'Location') + '</th>';
				out += '<th>' + escapeHtml(i18n.diffColType || 'Type') + '</th>';
				out += '<th>' + escapeHtml(i18n.diffColMatch || 'Match') + '</th>';
				out += '</tr></thead><tbody>';
				rows.slice(0, 100).forEach(function (row) {
					out += '<tr><td>' + escapeHtml(row.object_label || row.source_type || '') + '</td><td>' +
						escapeHtml(row.match_type || '') + '</td><td><code>' + escapeHtml(row.match_value || '') + '</code></td></tr>';
				});
				out += '</tbody></table>';
				// The stat tile above shows the true, uncapped count (summary.added /
				// summary.removed) -- without this note, a diff with e.g. 150 matches showed
				// "150" in the tile but silently only 100 rows in the table.
				if (rows.length > 100) {
					out += '<p class="description">' + escapeHtml((i18n.diffCapped || '(Showing the first 100 of %1$s.)').replace('%1$s', rows.length)) + '</p>';
				}
				out += '</div>';
				return out;
			}

			html += rowsTable(i18n.diffAddedRows || i18n.diffAdded || 'Added in A (not in B)', d.added || []);
			html += rowsTable(i18n.diffRemovedRows || i18n.diffRemoved || 'Removed (in B, not in A)', d.removed || []);
			box.innerHTML = html;
			box.hidden = false;
		});
	}

	// needlesEmpty distinguishes "scanned and found nothing" (a real "safe" result) from
	// "had no shortcodes/blocks/meta/option prefixes to search for in the first place"
	// (the plugin was never actually checked -- tsosi_audit_inactive_plugins() in
	// tsosi-scan-risk.php sets risk_level to 'safe' in both cases, since there's nothing
	// found either way, but showing the same green "Safe" pill for both would let a user
	// delete an unidentifiable plugin thinking it was verified clean). rowTitle, when
	// given, becomes a title attribute carrying the exact server-computed risk_label.
	function riskBadge(level, needlesEmpty, rowTitle) {
		var label = i18n.riskSafe || 'Safe';
		var cssLevel = level || 'safe';
		if (level === 'danger') {
			label = i18n.riskDanger || 'Risk';
		} else if (level === 'review') {
			label = i18n.riskReview || 'Review';
		} else if (needlesEmpty) {
			label = i18n.riskUnknown || 'Not checked';
			cssLevel = 'unknown';
		}
		var titleAttr = rowTitle ? ' title="' + escapeAttr(rowTitle) + '"' : '';
		return '<span class="tsosi-risk-pill tsosi-risk-' + escapeAttr(cssLevel) + '"' + titleAttr + '>' + escapeHtml(label) + '</span>';
	}

	function runInactiveAudit() {
		var status = qs('#tsosi-inactive-audit-status');
		var box = qs('#tsosi-inactive-audit-results');
		var btn = qs('#tsosi-run-inactive-audit');
		var exportBtn = qs('#tsosi-inactive-export');
		if (status) {
			status.textContent = i18n.auditRunning || 'Auditing…';
		}
		if (btn) {
			btn.disabled = true;
		}
		if (exportBtn) {
			exportBtn.hidden = true;
		}

		function renderAudit(rows, capped) {
			lastInactiveRows = rows || [];
			if (status) {
				// tsosi_audit_inactive_plugins() (tsosi-scan-risk.php) silently stops after
				// the first 60 inactive plugins ($count > 60) and returns that as `capped`.
				// Without surfacing it, "Audit complete." reads as exhaustive on a site with
				// more than 60 inactive plugins, when some were never checked at all.
				status.textContent = (i18n.auditDone || 'Audit complete.') + (capped ? ' ' + (i18n.auditCapped || '') : '');
			}
			if (!box) {
				return;
			}
			if (!rows.length) {
				box.innerHTML = '<p>' + escapeHtml(i18n.auditEmpty || 'No inactive plugins found.') + '</p>';
				box.hidden = false;
				return;
			}
			var html = '<table class="widefat striped"><thead><tr>' +
				'<th>' + escapeHtml(i18n.auditColPlugin || 'Plugin') + '</th>' +
				'<th>' + escapeHtml(i18n.auditColMatches || 'Matches') + '</th>' +
				'<th>' + escapeHtml(i18n.auditColRisk || 'Risk') + '</th>' +
				'<th>' + escapeHtml(i18n.auditColAction || 'Action') + '</th>' +
				'</tr></thead><tbody>';
			rows.forEach(function (row) {
				html += '<tr>' +
					'<td>' + escapeHtml(row.name || row.plugin_file || '') + '</td>' +
					'<td>' + escapeHtml(String(row.match_count || 0)) +
					(row.content || row.data ? ' <span class="description">(' + escapeHtml(String(row.content || 0)) + ' content / ' + escapeHtml(String(row.data || 0)) + ' data)</span>' : '') +
					'</td>' +
					'<td>' + riskBadge(row.risk_level, row.needles_empty, row.risk_label) + '</td>' +
					'<td><a class="button button-small" href="' + escapeAttr(row.scan_url || '#') + '">' +
					escapeHtml(i18n.auditOpenScan || 'Open scan') + '</a></td>' +
					'</tr>';
			});
			html += '</tbody></table>';
			box.innerHTML = html;
			box.hidden = false;
			if (exportBtn) {
				exportBtn.hidden = !rows.length;
			}
		}

		function auditOnce() {
			return post('tsosi_audit_inactive', {});
		}

		ensureIndex().then(function () {
			return auditOnce();
		}).then(function (payload) {
			if (payloadNeedsIndex(payload)) {
				cfg.indexReady = false;
				return ensureIndex().then(auditOnce);
			}
			return payload;
		}).then(function (payload) {
			if (btn) {
				btn.disabled = false;
			}
			if (!payload || !payload.success) {
				var msg = (payload && payload.data && payload.data.message) || (i18n.scanError || 'Error');
				if (status) {
					status.textContent = msg;
				}
				window.alert(msg);
				return;
			}
			renderAudit(
				(payload.data && payload.data.rows) ? payload.data.rows : [],
				!!(payload.data && payload.data.capped)
			);
		}).catch(function (err) {
			if (btn) {
				btn.disabled = false;
			}
			if (status) {
				status.textContent = err.message || i18n.scanError || 'Error';
			}
		});
	}

	function exportInactiveCsv() {
		// risk_label carries the precise server-computed wording (e.g. "No signatures
		// detected" vs "Likely safe to uninstall") -- exporting the coarse risk_level
		// alone would flatten both into the same "safe" text, the same ambiguity fixed
		// in the on-screen badge via riskBadge()'s needlesEmpty/title handling.
		var rows = (lastInactiveRows || []).map(function (row) {
			return [row.name || '', row.plugin_file || '', row.match_count || 0, row.content || 0, row.data || 0, row.risk_level || '', row.risk_label || ''];
		});
		downloadCsv('stack-inspector-inactive-' + new Date().toISOString().slice(0, 10) + '.csv', ['Plugin', 'File', 'Matches', 'Content', 'Data', 'Risk', 'Risk detail'], rows);
	}

	function refreshProfiles() {
		return post('tsosi_refresh_profiles', {}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error((payload && payload.data && payload.data.message) || i18n.refreshFail);
			}
			window.setTimeout(function () {
				window.location.reload();
			}, 600);
		}).catch(function (err) {
			window.alert(err.message || i18n.refreshFail);
		});
	}

	function joinOrDash(items) {
		return items && items.length ? items.join(', ') : '—';
	}

	function renderThemeProfile(profile) {
		var list = qs('#tsosi-theme-profile-list');
		if (!list || !profile) {
			return;
		}
		// tsosi_build_theme_profile() (tsosi-scan-extended.php) always includes an
		// option_prefixes entry for "theme_mods_{stylesheet}" -- the scan searches for it
		// unconditionally, regardless of the "include_theme_mods" setting (see
		// tsosi_scan_needles_want_theme_mods()) -- so omitting it here made a theme with
		// no shortcodes/blocks/meta show an all-dash "nothing to search for" preview even
		// though the scan still checks (and can match) theme_mods.
		list.innerHTML =
			'<li>' + escapeHtml(i18n.themeShortcodes || 'Shortcodes') + ': <code>' + escapeHtml(joinOrDash(profile.shortcodes)) + '</code></li>' +
			'<li>' + escapeHtml(i18n.themeBlocks || 'Blocks') + ': <code>' + escapeHtml(joinOrDash(profile.blocks)) + '</code></li>' +
			'<li>' + escapeHtml(i18n.themeMeta || 'Meta prefixes') + ': <code>' + escapeHtml(joinOrDash(profile.meta_prefixes)) + '</code></li>' +
			'<li>' + escapeHtml(i18n.themeOption || 'Option prefixes') + ': <code>' + escapeHtml(joinOrDash(profile.option_prefixes)) + '</code></li>';
	}

	function loadThemeProfile() {
		var sel = qs('#tsosi-theme-stylesheet');
		if (!sel || !sel.value) {
			return;
		}
		post('tsosi_theme_profile', { theme: sel.value }).then(function (payload) {
			if (payload && payload.success && payload.data) {
				renderThemeProfile(payload.data);
			}
		});
	}

	function copyTagFromButton(btn) {
		var text = btn.getAttribute('data-copy') || '';
		if (!text) {
			return;
		}
		var prev = btn.textContent;
		var done = function () {
			btn.textContent = i18n.copied || 'Copied';
			window.setTimeout(function () {
				btn.textContent = prev;
			}, 1200);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done).catch(function () {
				window.prompt('', text);
			});
			return;
		}
		window.prompt('', text);
	}

	function bind() {
		qsa('.tsosi-start-scan').forEach(function (btn) {
			btn.addEventListener('click', function () {
				startScan(btn.getAttribute('data-mode') || 'plugin');
			});
		});

		var langSelect = qs('#tsosi-set-lang');
		if (langSelect && langSelect.form) {
			langSelect.addEventListener('change', function () {
				langSelect.form.submit();
			});
		}

		['#tsosi-filter-results', '#tsosi-filter-type', '#tsosi-filter-bucket'].forEach(function (sel) {
			var el = qs(sel);
			if (el) {
				el.addEventListener('input', applyFilters);
				el.addEventListener('change', applyFilters);
			}
		});
		var groupBox = qs('#tsosi-group-results');
		if (groupBox) {
			groupBox.addEventListener('change', applyFilters);
		}

		var replacePreviewBtn = qs('#tsosi-replace-preview');
		if (replacePreviewBtn) {
			replacePreviewBtn.addEventListener('click', replacePreview);
		}
		var replaceApplyBtn = qs('#tsosi-replace-apply');
		if (replaceApplyBtn) {
			replaceApplyBtn.addEventListener('click', replaceApply);
		}

		qsa('.tsosi-replace-undo').forEach(function (btn) {
			btn.addEventListener('click', function () {
				replaceUndo(btn.getAttribute('data-id') || '');
			});
		});

		var orphansBtn = qs('#tsosi-run-orphans');
		if (orphansBtn) {
			orphansBtn.addEventListener('click', runOrphans);
		}
		var orphansExport = qs('#tsosi-orphans-export');
		if (orphansExport) {
			orphansExport.addEventListener('click', exportOrphansCsv);
		}

		var diffBtn = qs('#tsosi-run-history-diff');
		if (diffBtn) {
			diffBtn.addEventListener('click', runHistoryDiff);
		}

		var cancelBtn = qs('#tsosi-cancel-scan');
		if (cancelBtn) {
			cancelBtn.addEventListener('click', cancelScan);
		}

		var rebuildBtn = qs('#tsosi-rebuild-cache');
		if (rebuildBtn) {
			rebuildBtn.addEventListener('click', rebuildCache);
		}

		var compareBtn = qs('#tsosi-run-compare');
		if (compareBtn) {
			compareBtn.addEventListener('click', runCompare);
		}

		qsa('.tsosi-load-history').forEach(function (btn) {
			btn.addEventListener('click', function () {
				loadHistory(btn.getAttribute('data-id') || '');
			});
		});

		qsa('.tsosi-delete-history').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var row = btn.closest('tr');
				deleteHistory(btn.getAttribute('data-id') || '', row);
			});
		});

		var clearHistoryBtn = qs('#tsosi-clear-history');
		if (clearHistoryBtn) {
			clearHistoryBtn.addEventListener('click', clearHistory);
		}

		var auditBtn = qs('#tsosi-run-inactive-audit');
		if (auditBtn) {
			auditBtn.addEventListener('click', runInactiveAudit);
		}
		var inactiveExport = qs('#tsosi-inactive-export');
		if (inactiveExport) {
			inactiveExport.addEventListener('click', exportInactiveCsv);
		}

		qsa('.tsosi-refresh-profiles').forEach(function (btn) {
			btn.addEventListener('click', refreshProfiles);
		});

		var csvBtn = qs('#tsosi-export-csv');
		if (csvBtn) {
			csvBtn.addEventListener('click', exportCsv);
		}
		var pdfBtn = qs('#tsosi-export-pdf');
		if (pdfBtn) {
			pdfBtn.addEventListener('click', exportPdf);
		}

		['#tsosi-block-select', '#tsosi-block', '#tsosi-shortcode-select', '#tsosi-shortcode'].forEach(function () {
			// picker sync handled below
		});

		var blockSelect = qs('#tsosi-block-select');
		var blockInput = qs('#tsosi-block');
		if (blockSelect && blockInput) {
			blockSelect.addEventListener('change', function () {
				if (blockSelect.value) {
					blockInput.value = blockSelect.value;
				}
			});
		}
		var shortcodeSelect = qs('#tsosi-shortcode-select');
		var shortcodeInput = qs('#tsosi-shortcode');
		if (shortcodeSelect && shortcodeInput) {
			shortcodeSelect.addEventListener('change', function () {
				if (shortcodeSelect.value) {
					shortcodeInput.value = shortcodeSelect.value;
				}
			});
		}

		var themeSelect = qs('#tsosi-theme-stylesheet');
		if (themeSelect) {
			themeSelect.addEventListener('change', loadThemeProfile);
		}

		document.addEventListener('click', function (event) {
			var copyBtn = event.target && event.target.closest ? event.target.closest('.tsosi-copy-tag') : null;
			if (copyBtn) {
				event.preventDefault();
				copyTagFromButton(copyBtn);
			}
		});

		if (!cfg.indexReady && cfg.indexStorageReady) {
			ensureIndex().catch(function () {
				/* Keep the page usable; scans can still build the index. */
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind);
	} else {
		bind();
	}
})();
