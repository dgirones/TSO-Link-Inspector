<?php
/**
 * Export helpers for scan results (CSV headers, printable report meta).
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV column headers for scan exports.
 *
 * @return string[]
 */
function tsosi_reports_csv_headers() {
	return array(
		tsosi_ui_triple_text( 'Location', 'Ubicación', 'Ubicació' ),
		tsosi_ui_triple_text( 'Type', 'Tipo', 'Tipus' ),
		tsosi_ui_triple_text( 'Match', 'Coincidencia', 'Coincidència' ),
		tsosi_ui_triple_text( 'Context', 'Contexto', 'Context' ),
		tsosi_ui_triple_text( 'Edit URL', 'URL de edición', 'URL d\'edició' ),
	);
}

// Row formatting and report-title text live client-side in assets/js/admin.js
// (exportCsv()/buildReportHtml()), which is the only consumer of CSV/PDF export —
// keep that JS as the single source of truth instead of duplicating it here.
