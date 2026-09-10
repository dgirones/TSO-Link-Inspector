<?php
/**
 * Admin asset registration for TSO Stack Inspector.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return string
 */
function tsosi_admin_assets_version() {
	return defined( 'TSOSI_VERSION' ) ? TSOSI_VERSION : '1.0.0';
}

/**
 * @param string $relative_path Path relative to plugin root.
 * @return string
 */
function tsosi_admin_asset_file_version( $relative_path ) {
	$relative_path = ltrim( (string) $relative_path, '/' );
	if ( '' === $relative_path || ! defined( 'TSOSI_PATH' ) ) {
		return tsosi_admin_assets_version();
	}
	$path = TSOSI_PATH . $relative_path;
	return is_readable( $path ) ? (string) filemtime( $path ) : tsosi_admin_assets_version();
}

/**
 * Enqueue admin assets on plugin screen.
 *
 * @param string $hook_suffix Current admin page hook.
 * @return void
 */
function tsosi_admin_enqueue_assets( $hook_suffix ) {
	if ( 'tools_page_tso-stack-inspector' !== $hook_suffix ) {
		return;
	}

	$base = defined( 'TSOSI_URL' ) ? trailingslashit( TSOSI_URL ) : '';

	wp_enqueue_style(
		'tso-stack-inspector-admin',
		$base . 'assets/css/admin.css',
		array(),
		tsosi_admin_asset_file_version( 'assets/css/admin.css' )
	);

	wp_enqueue_style(
		'tso-stack-inspector-report',
		$base . 'assets/css/report.css',
		array(),
		tsosi_admin_asset_file_version( 'assets/css/report.css' )
	);

	wp_enqueue_script(
		'tso-stack-inspector-admin',
		$base . 'assets/js/admin.js',
		array(),
		tsosi_admin_asset_file_version( 'assets/js/admin.js' ),
		true
	);

	wp_localize_script(
		'tso-stack-inspector-admin',
		'tsosiAdminConfig',
		tsosi_admin_get_js_config()
	);
}
add_action( 'admin_enqueue_scripts', 'tsosi_admin_enqueue_assets' );

/**
 * Inline report CSS for printable popup (avoids external stylesheet on blob: URLs).
 *
 * @return string
 */
function tsosi_admin_get_report_css_inline() {
	if ( ! defined( 'TSOSI_PATH' ) ) {
		return '';
	}
	$path = TSOSI_PATH . 'assets/css/report.css';
	if ( ! is_readable( $path ) ) {
		return '';
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- static plugin asset for print report.
	$css = file_get_contents( $path );
	return is_string( $css ) ? $css : '';
}

/**
 * Config passed to admin.js.
 *
 * @return array<string,mixed>
 */
function tsosi_admin_get_js_config() {
	$lang     = tsosi_get_ui_lang();
	$base     = defined( 'TSOSI_URL' ) ? trailingslashit( TSOSI_URL ) : '';
	$settings = tsosi_get_scan_settings();
	$cache    = tsosi_scan_get_cache_status();

	return array(
		'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
		'nonce'             => wp_create_nonce( TSOSI_NONCE_AJAX ),
		'lang'              => $lang,
		'batchSize'         => TSOSI_SCAN_BATCH_SIZE,
		'indexReady'        => ! empty( $cache['ready'] ),
		'indexStorageReady' => tsosi_scan_content_cache_storage_ready(),
		'ignoreTokens'      => array_values( (array) ( $settings['ignore_tokens'] ?? array() ) ),
		'csvHeaders'        => tsosi_reports_csv_headers(),
		'reportCssUrl'      => $base . 'assets/css/report.css',
		'reportCssVer'      => tsosi_admin_asset_file_version( 'assets/css/report.css' ),
		'siteName'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'i18n'              => array(
			'scanning'               => tsosi_ui_triple_text( 'Scanning…', 'Escaneando…', 'Escanejant…' ),
			'scanDone'               => tsosi_ui_triple_text( 'Scan complete.', 'Escaneo completado.', 'Escaneig completat.' ),
			'scanCached'             => tsosi_ui_triple_text(
				'Using cached site index (content unchanged).',
				'Usando índice en caché (contenido sin cambios).',
				'Usant índex en memòria cau (contingut sense canvis).'
			),
			'scanError'              => tsosi_ui_triple_text( 'Scan failed.', 'Error en el escaneo.', 'Error en l\'escaneig.' ),
			'noResults'              => tsosi_ui_triple_text( 'No usage found.', 'No se encontró uso.', 'No s\'ha trobat ús.' ),
			'startScan'              => tsosi_ui_triple_text( 'Start scan', 'Iniciar escaneo', 'Iniciar escaneig' ),
			'progress'               => tsosi_ui_triple_text( 'Progress', 'Progreso', 'Progrés' ),
			'scanSummary'            => tsosi_ui_triple_text(
				'Scanned %1$s posts/widgets/options. Needles: %2$s. Matches: %3$s.',
				'Escaneados %1$s posts/widgets/opciones. Firmas: %2$s. Coincidencias: %3$s.',
				'Escanejats %1$s posts/widgets/opcions. Signatures: %2$s. Coincidències: %3$s.'
			),
			'needlesEmpty'           => tsosi_ui_triple_text(
				'No shortcodes, blocks, or meta/option prefixes were detected for this plugin. Try the Shortcode or Block tab.',
				'No se detectaron shortcodes, bloques ni prefijos meta/opción para este plugin. Prueba la pestaña Shortcode o Bloque.',
				'No s\'han detectat shortcodes, blocs ni prefixos meta/opció per a aquest plugin. Prova la pestanya Shortcode o Bloc.'
			),
			'moreCount'              => tsosi_ui_triple_text(
				'+%1$s more (see "Discovered signatures" above)',
				'+%1$s más (ver "Firmas detectadas" arriba)',
				'+%1$s més (mira "Signatures detectades" a dalt)'
			),
			'exportCsv'              => tsosi_ui_triple_text( 'Export CSV', 'Exportar CSV', 'Exportar CSV' ),
			'exportPdf'              => tsosi_ui_triple_text( 'Print / PDF', 'Imprimir / PDF', 'Imprimir / PDF' ),
			'exportPdfBlocked'       => tsosi_ui_triple_text(
				'Allow pop-ups for this site to open the print report.',
				'Permite las ventanas emergentes de este sitio para abrir el informe.',
				'Permet les finestres emergents d\'aquest lloc per obrir l\'informe.'
			),
			'refreshDone'            => tsosi_ui_triple_text( 'Signatures refreshed. Reloading…', 'Firmas actualizadas. Recargando…', 'Signatures actualitzades. Recarregant…' ),
			'refreshFail'            => tsosi_ui_triple_text( 'Could not refresh signatures.', 'No se pudieron actualizar las firmas.', 'No s\'han pogut actualitzar les signatures.' ),
			'reportTitle'            => tsosi_ui_triple_text( 'Stack Inspector report', 'Informe Stack Inspector', 'Informe Stack Inspector' ),
			'editLabel'              => tsosi_ui_triple_text( 'Edit', 'Editar', 'Editar' ),
			'filterCount'            => tsosi_ui_triple_text( 'Showing %1$s of %2$s', 'Mostrando %1$s de %2$s', 'Mostrant %1$s de %2$s' ),
			'scanCancelled'          => tsosi_ui_triple_text( 'Scan cancelled.', 'Escaneo cancelado.', 'Escaneig cancel·lat.' ),
			'backgroundScan'         => tsosi_ui_triple_text( 'Background scan', 'Escaneo en segundo plano', 'Escaneig en segon pla' ),
			'themeShortcodes'        => tsosi_ui_triple_text( 'Shortcodes', 'Shortcodes', 'Shortcodes' ),
			'themeBlocks'            => tsosi_ui_triple_text( 'Blocks', 'Bloques', 'Blocs' ),
			'themeMeta'              => tsosi_ui_triple_text( 'Meta prefixes', 'Prefijos meta', 'Prefixos meta' ),
			'themeOption'            => tsosi_ui_triple_text( 'Option prefixes', 'Prefijos de opciones', 'Prefixos d\'opcions' ),
			'historyDeleteConfirm'   => tsosi_ui_triple_text(
				'Delete this scan from history?',
				'¿Borrar este escaneo del historial?',
				'Esborrar aquest escaneig de l\'historial?'
			),
			'historyClearConfirm'    => tsosi_ui_triple_text(
				'Clear all scan history? This cannot be undone.',
				'¿Vaciar todo el historial de escaneos? No se puede deshacer.',
				'Buidar tot l\'historial d\'escaneigs? No es pot desfer.'
			),
			'historyEmpty'           => tsosi_ui_triple_text(
				'No saved scans yet.',
				'Aún no hay escaneos guardados.',
				'Encara no hi ha escaneigs desats.'
			),
			'compareShared'          => tsosi_ui_triple_text( 'Shared', 'Compartidos', 'Compartits' ),
			'compareOnlyA'           => tsosi_ui_triple_text( 'Only in %s', 'Solo en %s', 'Només a %s' ),
			'compareOnlyB'           => tsosi_ui_triple_text( 'Only in %s', 'Solo en %s', 'Només a %s' ),
			'compareColType'         => tsosi_ui_triple_text( 'Type', 'Tipo', 'Tipus' ),
			'compareFieldShortcodes' => tsosi_ui_triple_text( 'Shortcodes', 'Shortcodes', 'Shortcodes' ),
			'compareFieldBlocks'     => tsosi_ui_triple_text( 'Blocks', 'Bloques', 'Blocs' ),
			'compareFieldMeta'       => tsosi_ui_triple_text( 'Meta prefixes', 'Prefijos meta', 'Prefixos meta' ),
			'compareFieldOptions'    => tsosi_ui_triple_text( 'Option prefixes', 'Prefijos de opciones', 'Prefixos d\'opcions' ),
			'riskSafe'               => tsosi_ui_triple_text( 'Safe', 'Seguro', 'Segur' ),
			'riskReview'             => tsosi_ui_triple_text( 'Review', 'Revisar', 'Revisar' ),
			'riskDanger'             => tsosi_ui_triple_text( 'Risk', 'Riesgo', 'Risc' ),
			'riskUnknown'            => tsosi_ui_triple_text( 'Not checked', 'Sin comprobar', 'Sense comprovar' ),
			'auditRunning'           => tsosi_ui_triple_text( 'Auditing inactive plugins…', 'Auditando plugins inactivos…', 'Auditant plugins inactius…' ),
			'auditDone'              => tsosi_ui_triple_text( 'Audit complete.', 'Auditoría completada.', 'Auditoria completada.' ),
			'auditEmpty'             => tsosi_ui_triple_text( 'No inactive plugins found.', 'No hay plugins inactivos.', 'No hi ha plugins inactius.' ),
			'auditCapped'            => tsosi_ui_triple_text(
				'(Only the first 60 inactive plugins were audited; some were not checked.)',
				'(Solo se han auditado los primeros 60 plugins inactivos; algunos no se han comprobado.)',
				'(Només s\'han auditat els primers 60 plugins inactius; alguns no s\'han comprovat.)'
			),
			'auditColPlugin'         => tsosi_ui_triple_text( 'Plugin', 'Plugin', 'Plugin' ),
			'auditColMatches'        => tsosi_ui_triple_text( 'Matches', 'Coincidencias', 'Coincidències' ),
			'auditColRisk'           => tsosi_ui_triple_text( 'Risk', 'Riesgo', 'Risc' ),
			'auditColAction'         => tsosi_ui_triple_text( 'Action', 'Acción', 'Acció' ),
			'auditOpenScan'          => tsosi_ui_triple_text( 'Open scan', 'Abrir escaneo', 'Obrir escaneig' ),
			'cleanerCta'             => tsosi_ui_triple_text( 'Clean leftovers in Options Cleaner', 'Limpiar restos en Options Cleaner', 'Netejar restes a Options Cleaner' ),
			'cleanerMissing'         => tsosi_ui_triple_text(
				'Install/activate TSO Options & Tables Cleaner to remove leftover options and tables after uninstall.',
				'Instala/activa TSO Options & Tables Cleaner para borrar opciones y tablas sobrantes tras desinstalar.',
				'Instal·la/activa TSO Options & Tables Cleaner per esborrar opcions i taules sobrants després de desinstal·lar.'
			),
			'filterContentOnly'      => tsosi_ui_triple_text( 'Show content matches only', 'Mostrar solo coincidencias de contenido', 'Mostrar només coincidències de contingut' ),
			'filterDataOnly'         => tsosi_ui_triple_text( 'Show data matches only', 'Mostrar solo coincidencias de datos', 'Mostrar només coincidències de dades' ),
			'replacePreviewing'      => tsosi_ui_triple_text( 'Previewing…', 'Previsualizando…', 'Previsualitzant…' ),
			'replacePreviewCount'    => tsosi_ui_triple_text( '%d post(s) would change.', '%d entrada(s) cambiarían.', '%d entrada(es) canviarien.' ),
			'replaceCapped'          => tsosi_ui_triple_text( '(List capped; refine your search if needed.)', '(Lista limitada; afina la búsqueda si hace falta.)', '(Llista limitada; afina la cerca si cal.)' ),
			'replaceNone'            => tsosi_ui_triple_text( 'No matching content found.', 'No se encontró contenido coincidente.', 'No s\'ha trobat contingut coincident.' ),
			'replaceConfirm'         => tsosi_ui_triple_text(
				'Apply replacement to the listed posts? Keep a backup. This edits post content permanently.',
				'¿Aplicar la sustitución a las entradas listadas? Haz una copia de seguridad. Edita el contenido de forma permanente.',
				'Aplicar la substitució a les entrades llistades? Fes una còpia de seguretat. Edita el contingut de forma permanent.'
			),
			'replaceApplying'        => tsosi_ui_triple_text( 'Applying…', 'Aplicando…', 'Aplicant…' ),
			'replaceDone'            => tsosi_ui_triple_text( 'Replacement finished.', 'Sustitución terminada.', 'Substitució acabada.' ),
			'replaceBatch'           => tsosi_ui_triple_text(
				'Updated %1$s (skipped %2$s, errors %3$s). Continuing…',
				'Actualizadas %1$s (omitidas %2$s, errores %3$s). Continuando…',
				'Actualitzades %1$s (ometudes %2$s, errors %3$s). Continuant…'
			),
			'replaceColTitle'        => tsosi_ui_triple_text( 'Title', 'Título', 'Títol' ),
			'replaceColType'         => tsosi_ui_triple_text( 'Type', 'Tipo', 'Tipus' ),
			'replaceBackupSaved'     => tsosi_ui_triple_text( 'Backup saved for undo.', 'Copia guardada para deshacer.', 'Còpia desada per desfer.' ),
			'replaceUpdatedTotal'    => tsosi_ui_triple_text( 'Updated %d.', 'Actualizadas %d.', 'Actualitzades %d.' ),
			'replaceUndoConfirm'     => tsosi_ui_triple_text(
				'Restore previous content from this backup?',
				'¿Restaurar el contenido anterior desde esta copia?',
				'Restaurar el contingut anterior des d\'aquesta còpia?'
			),
			'replaceUndoDone'        => tsosi_ui_triple_text( 'Restored %d post(s).', 'Restauradas %d entrada(s).', 'Restaurades %d entrada(es).' ),
			'orphansRunning'         => tsosi_ui_triple_text( 'Searching for orphans…', 'Buscando huérfanos…', 'Cercant orfes…' ),
			'orphansDone'            => tsosi_ui_triple_text(
				'Found %1$s orphan shortcodes, %2$s orphan blocks.',
				'Encontrados %1$s shortcodes huérfanos, %2$s bloques huérfanos.',
				'Trobats %1$s shortcodes orfes, %2$s blocs orfes.'
			),
			'orphansShortcodes'      => tsosi_ui_triple_text( 'Orphan shortcodes', 'Shortcodes huérfanos', 'Shortcodes orfes' ),
			'orphansBlocks'          => tsosi_ui_triple_text( 'Orphan blocks', 'Bloques huérfanos', 'Blocs orfes' ),
			'orphansSamples'         => tsosi_ui_triple_text( 'Samples', 'Ejemplos', 'Exemples' ),
			'diffPickTwo'            => tsosi_ui_triple_text( 'Pick two different history entries.', 'Elige dos entradas distintas del historial.', 'Tria dues entrades diferents de l\'historial.' ),
			'diffAdded'              => tsosi_ui_triple_text( 'Added in A (not in B)', 'Añadidos en A (no en B)', 'Afegits a A (no a B)' ),
			'diffRemoved'            => tsosi_ui_triple_text( 'Removed (in B, not in A)', 'Eliminados (en B, no en A)', 'Eliminats (a B, no a A)' ),
			'diffCapped'             => tsosi_ui_triple_text(
				'(Showing the first 100 of %1$s.)',
				'(Mostrando los primeros 100 de %1$s.)',
				'(Mostrant els primers 100 de %1$s.)'
			),
			'diffAddedRows'          => tsosi_ui_triple_text( 'New matches in scan A', 'Nuevas coincidencias en el escaneo A', 'Noves coincidències a l\'escaneig A' ),
			'diffRemovedRows'        => tsosi_ui_triple_text( 'Matches only in scan B', 'Coincidencias solo en el escaneo B', 'Coincidències només a l\'escaneig B' ),
			'diffColLocation'        => tsosi_ui_triple_text( 'Location', 'Ubicación', 'Ubicació' ),
			'diffColType'            => tsosi_ui_triple_text( 'Type', 'Tipo', 'Tipus' ),
			'diffColMatch'           => tsosi_ui_triple_text( 'Match', 'Coincidencia', 'Coincidència' ),
			'copyTag'                => tsosi_ui_triple_text( 'Copy', 'Copiar', 'Copia' ),
			'copied'                 => tsosi_ui_triple_text( 'Copied', 'Copiado', 'Copiat' ),
			'replaceTag'             => tsosi_ui_triple_text( 'Replace', 'Sustituir', 'Substitueix' ),
			'indexBuilding'          => tsosi_ui_triple_text( 'Preparing site index… %s%', 'Preparando índice… %s%', 'Preparant índex… %s%' ),
			'indexReadyLabel'        => tsosi_ui_triple_text( 'Site index cached', 'Índice del sitio en caché', 'Índex del lloc en memòria cau' ),
			'indexRebuilt'           => tsosi_ui_triple_text( 'Site index rebuilt.', 'Índice del sitio reconstruido.', 'Índex del lloc reconstruït.' ),
			'checklistTitle'         => tsosi_ui_triple_text( 'Before you uninstall', 'Antes de desinstalar', 'Abans de desinstal·lar' ),
			'checklist1'             => tsosi_ui_triple_text( 'Replace or remove remaining shortcodes and blocks in content.', 'Sustituye o elimina los shortcodes y bloques que queden en el contenido.', 'Substitueix o elimina els shortcodes i blocs que quedin al contingut.' ),
			'checklist2'             => tsosi_ui_triple_text( 'Check widgets, menus, and reusable blocks.', 'Revisa widgets, menús y bloques reutilizables.', 'Revisa widgets, menús i blocs reutilitzables.' ),
			'checklist3'             => tsosi_ui_triple_text( 'Deactivate the plugin and smoke-test the front end.', 'Desactiva el plugin y prueba el sitio.', 'Desactiva el plugin i prova el lloc.' ),
			'checklist4'             => tsosi_ui_triple_text( 'Uninstall only after the site still looks correct.', 'Desinstala solo cuando el sitio siga viéndose bien.', 'Desinstal·la només quan el lloc encara es vegi bé.' ),
			'checklist5'             => tsosi_ui_triple_text( 'Clean leftover options/tables with Options Cleaner if needed.', 'Limpia opciones/tablas sobrantes con Options Cleaner si hace falta.', 'Neteja opcions/taules sobrants amb Options Cleaner si cal.' ),
		),
		'cleanerAvailable'  => tsosi_options_cleaner_is_available(),
	);
}
