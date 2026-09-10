<?php
/**
 * Admin UI and AJAX handlers for TSO Stack Inspector.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register admin menu.
 *
 * @return void
 */
function tsosi_admin_register_menu() {
	add_management_page(
		__( 'TSO Stack Inspector', 'tso-stack-inspector' ),
		__( 'TSO Stack Inspector', 'tso-stack-inspector' ),
		'manage_options',
		'tso-stack-inspector',
		'tsosi_admin_render_page'
	);
}
add_action( 'admin_menu', 'tsosi_admin_register_menu' );

/**
 * Admin URL for this plugin screen.
 *
 * @return string
 */
function tsosi_admin_page_url() {
	return admin_url( 'tools.php?page=tso-stack-inspector' );
}

/**
 * "Open inspector" link under the plugin name on Plugins screen.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function tsosi_plugin_action_links( $links ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $links;
	}
	$open = '<a href="' . esc_url( tsosi_admin_page_url() ) . '">' . esc_html( tsosi_get_open_inspector_link_label() ) . '</a>';
	array_unshift( $links, $open );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( TSOSI_FILE ), 'tsosi_plugin_action_links' );

/**
 * Handle language switch POST.
 *
 * @return void
 */
function tsosi_admin_handle_language_switch() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_GET[ TSOSI_ADMIN_QUERY_SET_LANG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence gate only; check_admin_referer runs before update_user_meta.
		return;
	}
	$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence gate only; check_admin_referer runs before update_user_meta.
	if ( 'tso-stack-inspector' !== $page ) {
		return;
	}

	tsosi_require_admin_form_nonce();

	$lang = isset( $_GET[ TSOSI_ADMIN_QUERY_SET_LANG ] ) ? sanitize_key( wp_unslash( $_GET[ TSOSI_ADMIN_QUERY_SET_LANG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified via check_admin_referer() above.
	if ( '' === $lang ) {
		return;
	}
	tsosi_set_ui_lang( $lang );

	$redirect_args = array( 'page' => 'tso-stack-inspector' );
	$tab           = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified via check_admin_referer() above; only used to keep the admin on the same tab.
	if ( '' !== $tab && in_array( $tab, tsosi_admin_tab_slugs(), true ) ) {
		$redirect_args['tab'] = $tab;
	}
	wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'tools.php' ) ) );
	exit;
}
add_action( 'admin_init', 'tsosi_admin_handle_language_switch' );

/**
 * Render admin page.
 *
 * @return void
 */
function tsosi_admin_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	nocache_headers();

	$tab = tsosi_get_admin_query_arg( 'tab', 'plugin' );
	if ( ! in_array( $tab, tsosi_admin_tab_slugs(), true ) ) {
		$tab = 'plugin';
	}

	$preselect_plugin = tsosi_sanitize_plugin_file( tsosi_get_admin_query_arg( 'plugin_file' ) );

	$base_url    = admin_url( 'tools.php?page=tso-stack-inspector' );
	$plugins     = tsosi_get_installed_plugins();
	$profiles    = tsosi_get_all_plugin_profiles();
	$cleaner_cta = tsosi_get_options_cleaner_cta();

	$txt_title = tsosi_ui_triple_text(
		'TSO Stack Inspector',
		'TSO Stack Inspector',
		'TSO Stack Inspector'
	);
	$txt_intro = tsosi_ui_triple_text(
		'Find where plugin shortcodes, blocks, and metadata are used before you deactivate or uninstall.',
		'Encuentra dónde se usan shortcodes, bloques y metadatos de plugins antes de desactivar o desinstalar.',
		'Troba on s\'utilitzen shortcodes, blocs i metadades de plugins abans de desactivar o desinstal·lar.'
	);

	echo '<div class="wrap tsosi-wrap">';
	echo '<h1>' . esc_html( $txt_title );
	$tsosi_plugin_version = tsosi_admin_assets_version();
	if ( '' !== $tsosi_plugin_version ) {
		echo ' <span class="tsosi-plugin-version">v' . esc_html( $tsosi_plugin_version ) . '</span>';
	}
	echo '</h1>';
	echo '<p class="description">' . esc_html( $txt_intro ) . '</p>';

	tsosi_admin_render_language_switcher( $base_url, $tab );

	if ( null !== $cleaner_cta ) {
		echo '<p><a class="button button-secondary" href="' . esc_url( $cleaner_cta['url'] ) . '">' . esc_html( $cleaner_cta['label'] ) . '</a></p>';
	}

	tsosi_admin_render_cache_bar();
	tsosi_admin_render_tabs( $base_url, $tab );

	if ( 'plugin' === $tab ) {
		tsosi_admin_render_plugin_tab( $plugins, $profiles, $preselect_plugin );
	} elseif ( 'shortcode' === $tab ) {
		tsosi_admin_render_shortcode_tab();
	} elseif ( 'block' === $tab ) {
		tsosi_admin_render_block_tab();
	} elseif ( 'replace' === $tab ) {
		tsosi_admin_render_replace_tab();
	} elseif ( 'orphans' === $tab ) {
		tsosi_admin_render_orphans_tab();
	} elseif ( 'theme' === $tab ) {
		tsosi_admin_render_theme_tab();
	} elseif ( 'compare' === $tab ) {
		tsosi_admin_render_compare_tab( $plugins );
	} elseif ( 'inactive' === $tab ) {
		tsosi_admin_render_inactive_tab( $plugins );
	} elseif ( 'history' === $tab ) {
		tsosi_admin_render_history_tab();
	} elseif ( 'settings' === $tab ) {
		tsosi_admin_render_settings_tab();
	} else {
		tsosi_admin_render_inventory_tab( $profiles );
	}

	echo '<div id="tsosi-scan-panel" class="tsosi-scan-panel" hidden>';
	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Scan results', 'Resultados del escaneo', 'Resultats de l\'escaneig' ) ) . '</h2>';
	echo '<div id="tsosi-risk-banner" class="tsosi-risk-banner" hidden></div>';
	echo '<div id="tsosi-scan-progress" class="tsosi-progress" aria-live="polite"></div>';
	echo '<div id="tsosi-scan-toolbar" class="tsosi-scan-toolbar" hidden>';
	echo '<label class="screen-reader-text" for="tsosi-filter-results">' . esc_html( tsosi_ui_triple_text( 'Filter results', 'Filtrar resultados', 'Filtrar resultats' ) ) . '</label>';
	echo '<input type="search" id="tsosi-filter-results" class="tsosi-filter-input" placeholder="' . esc_attr( tsosi_ui_triple_text( 'Filter results…', 'Filtrar resultados…', 'Filtrar resultats…' ) ) . '" />';
	echo '<select id="tsosi-filter-type"><option value="">' . esc_html( tsosi_ui_triple_text( 'All types', 'Todos los tipos', 'Tots els tipus' ) ) . '</option>';
	echo '<option value="shortcode">shortcode</option><option value="block">block</option><option value="meta">meta</option>';
	echo '<option value="widget">widget</option><option value="option">option</option><option value="menu">menu</option></select>';
	echo '<select id="tsosi-filter-bucket" aria-label="' . esc_attr( tsosi_ui_triple_text( 'Content or data', 'Contenido o datos', 'Contingut o dades' ) ) . '">';
	echo '<option value="">' . esc_html( tsosi_ui_triple_text( 'All matches', 'Todas las coincidencias', 'Totes les coincidències' ) ) . '</option>';
	echo '<option value="content">' . esc_html( tsosi_ui_triple_text( 'Content only (shortcode/block)', 'Solo contenido (shortcode/bloque)', 'Només contingut (shortcode/bloc)' ) ) . '</option>';
	echo '<option value="data">' . esc_html( tsosi_ui_triple_text( 'Data only (meta/options/…)', 'Solo datos (meta/opciones/…)', 'Només dades (meta/opcions/…)' ) ) . '</option>';
	echo '</select>';
	echo '<label class="tsosi-check-row tsosi-group-toggle"><input type="checkbox" id="tsosi-group-results" checked /> ';
	echo esc_html( tsosi_ui_triple_text( 'Group by location', 'Agrupar por ubicación', 'Agrupar per ubicació' ) );
	echo '</label>';
	echo '<span id="tsosi-filter-count" class="tsosi-filter-count"></span>';
	echo '<button type="button" class="button" id="tsosi-cancel-scan">' . esc_html( tsosi_ui_triple_text( 'Cancel scan', 'Cancelar escaneo', 'Cancel·lar escaneig' ) ) . '</button>';
	echo '</div>';
	echo '<div id="tsosi-scan-actions" class="tsosi-scan-actions" hidden>';
	echo '<label class="tsosi-check-row tsosi-background-option"><input type="checkbox" id="tsosi-background-scan" /> ';
	echo esc_html( tsosi_ui_triple_text( 'Run in background (close tab OK)', 'Ejecutar en segundo plano', 'Executar en segon pla' ) );
	echo '</label>';
	echo '<button type="button" class="button" id="tsosi-export-csv">' . esc_html( tsosi_ui_triple_text( 'Export CSV', 'Exportar CSV', 'Exportar CSV' ) ) . '</button>';
	echo '<button type="button" class="button" id="tsosi-export-pdf">' . esc_html( tsosi_ui_triple_text( 'Print / PDF', 'Imprimir / PDF', 'Imprimir / PDF' ) ) . '</button>';
	$cleaner_plugin_url = tsosi_get_options_cleaner_scan_url( $preselect_plugin );
	echo '<a class="button button-secondary" id="tsosi-open-cleaner" href="' . esc_url( $cleaner_plugin_url ? $cleaner_plugin_url : '#' ) . '"' . ( $cleaner_plugin_url ? '' : ' hidden' ) . '>';
	echo esc_html( tsosi_ui_triple_text( 'Clean leftovers in Options Cleaner', 'Limpiar restos en Options Cleaner', 'Netejar restes a Options Cleaner' ) );
	echo '</a>';
	if ( ! tsosi_options_cleaner_is_available() ) {
		$missing_cta = tsosi_get_options_cleaner_cta();
		echo '<span id="tsosi-cleaner-missing" class="description">';
		if ( null !== $missing_cta ) {
			echo '<a href="' . esc_url( $missing_cta['url'] ) . '">' . esc_html( $missing_cta['label'] ) . '</a>';
		} else {
			echo esc_html(
				tsosi_ui_triple_text(
					'Install/activate TSO Options & Tables Cleaner to remove leftover options and tables after uninstall.',
					'Instala/activa TSO Options & Tables Cleaner para borrar opciones y tablas sobrantes tras desinstalar.',
					'Instal·la/activa TSO Options & Tables Cleaner per esborrar opcions i taules sobrants després de desinstal·lar.'
				)
			);
		}
		echo '</span>';
	}
	echo '</div>';
	echo '<table class="widefat striped tsosi-results-table"><thead><tr>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Location', 'Ubicación', 'Ubicació' ) ) . '</th>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Type', 'Tipo', 'Tipus' ) ) . '</th>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Match', 'Coincidencia', 'Coincidència' ) ) . '</th>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Context', 'Contexto', 'Context' ) ) . '</th>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Action', 'Acción', 'Acció' ) ) . '</th>';
	echo '</tr></thead><tbody id="tsosi-results-body"></tbody></table>';
	echo '</div>';

	echo '</div>';
}

/**
 * @param string $base_url Base admin URL.
 * @param string $tab      Active tab, preserved through the redirect after switching.
 * @return void
 */
function tsosi_admin_render_language_switcher( $base_url, $tab = '' ) {
	// The dropdown reflects the user's raw pin (or its absence), not the resolved
	// language: an unpinned user on a Spanish WordPress profile must still see
	// "Automatic" selected, not "Español", so switching their WP language later keeps
	// following it without needing to revisit this screen.
	$pin = tsosi_get_ui_lang_pin();

	echo '<form method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '" class="tsosi-lang-form">';
	echo '<input type="hidden" name="page" value="tso-stack-inspector" />';
	if ( '' !== $tab ) {
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
	}
	wp_nonce_field( TSOSI_NONCE_FORM );
	echo '<label for="tsosi-set-lang">' . esc_html( tsosi_ui_triple_text( 'Interface language', 'Idioma de la interfaz', 'Idioma de la interfície' ) ) . '</label> ';
	echo '<select id="tsosi-set-lang" name="' . esc_attr( TSOSI_ADMIN_QUERY_SET_LANG ) . '">';
	$options = array(
		'auto' => tsosi_ui_triple_text( 'Automatic', 'Automático', 'Automàtic' ),
		'en'   => 'English',
		'es'   => 'Español',
		'ca'   => 'Català',
	);
	foreach ( $options as $code => $label ) {
		$value = ( 'auto' === $code ) ? 'auto' : $code;
		echo '<option value="' . esc_attr( $value ) . '"' . selected( '' === $pin ? 'auto' : $pin, $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	echo '</form>';
}

/**
 * @param string $base_url Base URL.
 * @param string $tab      Active tab.
 * @return void
 */
function tsosi_admin_render_tabs( $base_url, $tab ) {
	$tabs = array(
		'plugin'    => tsosi_ui_triple_text( 'By plugin', 'Por plugin', 'Per plugin' ),
		'shortcode' => tsosi_ui_triple_text( 'Shortcode', 'Shortcode', 'Shortcode' ),
		'block'     => tsosi_ui_triple_text( 'Block', 'Bloque', 'Bloc' ),
		'replace'   => tsosi_ui_triple_text( 'Replace', 'Sustituir', 'Substituir' ),
		'orphans'   => tsosi_ui_triple_text( 'Orphans', 'Huérfanos', 'Orfes' ),
		'theme'     => tsosi_ui_triple_text( 'Theme', 'Tema', 'Tema' ),
		'compare'   => tsosi_ui_triple_text( 'Compare', 'Comparar', 'Comparar' ),
		'inactive'  => tsosi_ui_triple_text( 'Inactive', 'Inactivos', 'Inactius' ),
		'inventory' => tsosi_ui_triple_text( 'Inventory', 'Inventario', 'Inventari' ),
		'history'   => tsosi_ui_triple_text( 'History', 'Historial', 'Historial' ),
		'settings'  => tsosi_ui_triple_text( 'Settings', 'Ajustes', 'Opcions' ),
	);
	echo '<nav class="nav-tab-wrapper tsosi-tabs">';
	foreach ( $tabs as $slug => $label ) {
		$url   = add_query_arg( 'tab', $slug, $base_url );
		$class = ( $tab === $slug ) ? ' nav-tab nav-tab-active' : ' nav-tab';
		echo '<a class="' . esc_attr( trim( $class ) ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';
}

/**
 * @param array<string,array<string,string>> $plugins  Plugins.
 * @param array<string,array<string,mixed>>  $profiles Profiles.
 * @param string                             $preselect Optional plugin file to preselect.
 * @return void
 */
function tsosi_admin_render_plugin_tab( $plugins, $profiles, $preselect = '' ) {
	echo '<div class="tsosi-panel">';
	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Before uninstall', 'Antes de desinstalar', 'Abans de desinstal·lar' ) ) . '</h2>';
	echo '<p>' . esc_html(
		tsosi_ui_triple_text(
			'Select a plugin to scan posts, reusable blocks, widgets, and menus for its shortcodes, blocks, and meta prefixes.',
			'Selecciona un plugin para escanear entradas, bloques reutilizables, widgets y menús buscando sus shortcodes, bloques y prefijos meta.',
			'Selecciona un plugin per escanejar entrades, blocs reutilitzables, widgets i menús buscant els seus shortcodes, blocs i prefixos meta.'
		)
	) . '</p>';

	echo '<div class="tsosi-form-row">';
	echo '<label for="tsosi-plugin-file">' . esc_html( tsosi_ui_triple_text( 'Plugin', 'Plugin', 'Plugin' ) ) . '</label>';
	echo '<select id="tsosi-plugin-file" name="plugin_file">';
	echo '<option value="">' . esc_html( tsosi_ui_triple_text( '— Select —', '— Seleccionar —', '— Seleccionar —' ) ) . '</option>';
	foreach ( $plugins as $file => $header ) {
		$name   = isset( $header['Name'] ) ? (string) $header['Name'] : $file;
		$active = tsosi_is_plugin_active_file( $file );
		$label  = $name . ( $active ? '' : ' (' . tsosi_ui_triple_text( 'inactive', 'inactivo', 'inactiu' ) . ')' );
		echo '<option value="' . esc_attr( $file ) . '"' . selected( $preselect, $file, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	echo '<button type="button" class="button button-primary tsosi-start-scan" data-mode="plugin">' . esc_html( tsosi_ui_triple_text( 'Start scan', 'Iniciar escaneo', 'Iniciar escaneig' ) ) . '</button>';
	echo '<button type="button" class="button tsosi-refresh-profiles" id="tsosi-refresh-profiles">' . esc_html( tsosi_ui_triple_text( 'Refresh signatures', 'Actualizar firmas', 'Actualitzar signatures' ) ) . '</button>';
	echo '</div>';
	tsosi_admin_echo_help( tsosi_admin_signatures_help_text() );

	if ( ! empty( $profiles ) ) {
		echo '<details class="tsosi-profile-details"><summary>' . esc_html( tsosi_ui_triple_text( 'Discovered signatures (cached 24h)', 'Firmas detectadas (caché 24h)', 'Signatures detectades (memòria cau 24h)' ) ) . '</summary>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html( tsosi_ui_triple_text( 'Plugin', 'Plugin', 'Plugin' ) ) . '</th>';
		echo '<th>' . esc_html( tsosi_ui_triple_text( 'Shortcodes', 'Shortcodes', 'Shortcodes' ) ) . '</th>';
		echo '<th>' . esc_html( tsosi_ui_triple_text( 'Blocks', 'Bloques', 'Blocs' ) ) . '</th>';
		echo '<th>' . esc_html( tsosi_ui_triple_text( 'Meta prefixes', 'Prefijos meta', 'Prefixos meta' ) ) . '</th>';
		echo '<th>' . esc_html( tsosi_ui_triple_text( 'Option prefixes', 'Prefijos de opciones', 'Prefixos d\'opcions' ) ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $profiles as $profile ) {
			if ( empty( $profile['shortcodes'] ) && empty( $profile['blocks'] ) && empty( $profile['meta_prefixes'] ) && empty( $profile['option_prefixes'] ) ) {
				continue;
			}
			echo '<tr>';
			echo '<td>' . esc_html( (string) $profile['name'] ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', (array) $profile['shortcodes'] ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', (array) $profile['blocks'] ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', (array) $profile['meta_prefixes'] ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', (array) ( $profile['option_prefixes'] ?? array() ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></details>';
	}
	echo '</div>';
}

/**
 * @return void
 */
function tsosi_admin_render_shortcode_tab() {
	$shortcode_inventory = tsosi_get_shortcode_inventory();
	$registered_tags     = isset( $shortcode_inventory['registered_tags'] ) ? (array) $shortcode_inventory['registered_tags'] : array();
	$plugin_code_tags    = isset( $shortcode_inventory['plugin_code_tags'] ) ? (array) $shortcode_inventory['plugin_code_tags'] : array();
	$all_tags            = isset( $shortcode_inventory['all_tags'] ) ? (array) $shortcode_inventory['all_tags'] : array();

	echo '<div class="tsosi-panel">';
	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Shortcode finder', 'Buscador de shortcodes', 'Cercador de shortcodes' ) ) . '</h2>';
	echo '<div class="tsosi-tab-help">';
	echo '<p class="description">' . esc_html(
		tsosi_ui_triple_text(
			'Use this when you want to know where a shortcode is still used before removing a plugin or editing content.',
			'Úsalo cuando quieras saber dónde sigue usándose un shortcode antes de quitar un plugin o editar contenido.',
			'Fes servir això quan vulguis saber on encara s\'utilitza un shortcode abans de treure un plugin o editar contingut.'
		)
	) . '</p>';
	echo '<p class="description">' . esc_html(
		tsosi_ui_triple_text(
			'The dropdown lists real shortcodes registered on this site by active plugins, the theme, and WordPress core. A long list is normal: many plugins register internal or legacy shortcodes even if you never insert them manually.',
			'El desplegable lista shortcodes reales registrados en este sitio por plugins activos, el tema y WordPress. Una lista larga es normal: muchos plugins registran shortcodes internos o heredados aunque no los insertes a mano.',
			'El desplegable llista shortcodes reals registrats en aquest lloc per plugins actius, el tema i el WordPress. Una llista llarga és normal: molts plugins registren shortcodes interns o heretats encara que no els insertis a mà.'
		)
	) . '</p>';
	echo '<p class="description">' . esc_html(
		tsosi_ui_triple_text(
			'Pick one from the dropdown or type the tag without brackets. The scan checks posts, templates, widgets, menus, and wp_options.',
			'Elige uno del desplegable o escribe la etiqueta sin corchetes. El escaneo revisa entradas, plantillas, widgets, menús y wp_options.',
			'Tria’n un del desplegable o escriu l\'etiqueta sense claudàtors. L\'escaneig revisa entrades, plantilles, widgets, menús i wp_options.'
		)
	) . '</p>';
	echo '</div>';
	echo '<div class="tsosi-form-row tsosi-form-row-block">';
	echo '<label for="tsosi-shortcode-select">' . esc_html( tsosi_ui_triple_text( 'Shortcode tag', 'Etiqueta shortcode', 'Etiqueta shortcode' ) ) . '</label>';
	echo '<div class="tsosi-block-picker">';
	echo '<select id="tsosi-shortcode-select" aria-describedby="tsosi-shortcode-help">';
	echo '<option value="">' . esc_html( tsosi_ui_triple_text( '— Select a shortcode —', '— Seleccionar shortcode —', '— Seleccionar shortcode —' ) ) . '</option>';
	if ( ! empty( $registered_tags ) ) {
		echo '<optgroup label="' . esc_attr( tsosi_ui_triple_text( 'Registered on this site', 'Registrados en este sitio', 'Registrats en aquest lloc' ) ) . '">';
		foreach ( $registered_tags as $tag ) {
			echo '<option value="' . esc_attr( $tag ) . '">' . esc_html( $tag ) . '</option>';
		}
		echo '</optgroup>';
	}
	if ( ! empty( $plugin_code_tags ) ) {
		echo '<optgroup label="' . esc_attr( tsosi_ui_triple_text( 'Found in plugin code only (may be inactive)', 'Solo en código de plugin (puede estar inactivo)', 'Només al codi del plugin (pot estar inactiu)' ) ) . '">';
		foreach ( $plugin_code_tags as $tag ) {
			echo '<option value="' . esc_attr( $tag ) . '">' . esc_html( $tag ) . '</option>';
		}
		echo '</optgroup>';
	}
	echo '</select>';
	echo '<input type="text" id="tsosi-shortcode" list="tsosi-shortcode-list" placeholder="contact-form-7" aria-describedby="tsosi-shortcode-help" />';
	echo '<datalist id="tsosi-shortcode-list">';
	foreach ( $all_tags as $tag ) {
		echo '<option value="' . esc_attr( $tag ) . '"></option>';
	}
	echo '</datalist>';
	echo '<p id="tsosi-shortcode-help" class="description tsosi-field-help">' . esc_html(
		tsosi_ui_triple_text(
			'Registered shortcodes work right now. Code-only entries were detected in a plugin file but are not active until that plugin runs.',
			'Los shortcodes registrados funcionan ahora mismo. Las entradas solo en código se detectaron en un plugin pero no están activas hasta que el plugin se ejecute.',
			'Shortcodes registrats funcionen ara mateix. Entrades només al codi s\'han detectat en un plugin però no són actives fins que el plugin s\'executi.'
		)
	) . '</p>';
	echo '</div>';
	echo '<button type="button" class="button button-primary tsosi-start-scan" data-mode="shortcode">' . esc_html( tsosi_ui_triple_text( 'Start scan', 'Iniciar escaneo', 'Iniciar escaneig' ) ) . '</button>';
	echo '</div></div>';
}

/**
 * @return void
 */
function tsosi_admin_render_block_tab() {
	$block_inventory = tsosi_get_block_inventory();
	$plugin_blocks   = isset( $block_inventory['plugin_blocks'] ) ? (array) $block_inventory['plugin_blocks'] : array();
	$all_blocks      = isset( $block_inventory['registered_blocks'] ) ? (array) $block_inventory['registered_blocks'] : array();
	$other_blocks    = array_values( array_diff( $all_blocks, $plugin_blocks ) );

	echo '<div class="tsosi-panel">';
	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Block finder', 'Buscador de bloques', 'Cercador de blocs' ) ) . '</h2>';
	echo '<div class="tsosi-tab-help">';
	echo '<p class="description">' . esc_html(
		tsosi_ui_triple_text(
			'Use this to find where a Gutenberg block is inserted before you deactivate a plugin or clean up the site.',
			'Úsalo para localizar dónde está insertado un bloque de Gutenberg antes de desactivar un plugin o limpiar el sitio.',
			'Fes servir això per localitzar on està inserit un bloc de Gutenberg abans de desactivar un plugin o netejar el lloc.'
		)
	) . '</p>';
	echo '<p class="description">' . esc_html(
		tsosi_ui_triple_text(
			'The scan searches post content, FSE templates, reusable blocks, widget areas, menus, and serialized options.',
			'El escaneo busca en el contenido de entradas, plantillas FSE, bloques reutilizables, widgets, menús y opciones serializadas.',
			'L\'escaneig cerca al contingut de les entrades, plantilles FSE, blocs reutilitzables, widgets, menús i opcions serialitzades.'
		)
	) . '</p>';
	echo '<p class="description">' . esc_html(
		tsosi_ui_triple_text(
			'Pick a block from the dropdown (plugin blocks first) or type a block name manually. Format: namespace/block.',
			'Elige un bloque del desplegable (primero los de plugins) o escribe uno manualmente. Formato: espacio/nombre.',
			'Tria un bloc del desplegable (primer els de plugins) o escriu-ne un manualment. Format: espai/nom.'
		)
	) . '</p>';
	echo '</div>';
	echo '<div class="tsosi-form-row tsosi-form-row-block">';
	echo '<label for="tsosi-block-select">' . esc_html( tsosi_ui_triple_text( 'Block name', 'Nombre del bloque', 'Nom del bloc' ) ) . '</label>';
	echo '<div class="tsosi-block-picker">';
	echo '<select id="tsosi-block-select" aria-describedby="tsosi-block-help">';
	echo '<option value="">' . esc_html( tsosi_ui_triple_text( '— Select a block —', '— Seleccionar bloque —', '— Seleccionar bloc —' ) ) . '</option>';
	if ( ! empty( $plugin_blocks ) ) {
		echo '<optgroup label="' . esc_attr( tsosi_ui_triple_text( 'Discovered from plugins', 'Detectados en plugins', 'Detectats en plugins' ) ) . '">';
		foreach ( $plugin_blocks as $block_name ) {
			echo '<option value="' . esc_attr( $block_name ) . '">' . esc_html( $block_name ) . '</option>';
		}
		echo '</optgroup>';
	}
	if ( ! empty( $other_blocks ) ) {
		echo '<optgroup label="' . esc_attr( tsosi_ui_triple_text( 'All registered blocks', 'Todos los bloques registrados', 'Tots els blocs registrats' ) ) . '">';
		foreach ( $other_blocks as $block_name ) {
			echo '<option value="' . esc_attr( $block_name ) . '">' . esc_html( $block_name ) . '</option>';
		}
		echo '</optgroup>';
	}
	echo '</select>';
	echo '<input type="text" id="tsosi-block" list="tsosi-block-list" placeholder="contact-form-7/contact-form-selector" aria-describedby="tsosi-block-help" />';
	echo '<datalist id="tsosi-block-list">';
	foreach ( $all_blocks as $block_name ) {
		echo '<option value="' . esc_attr( $block_name ) . '"></option>';
	}
	echo '</datalist>';
	echo '<p id="tsosi-block-help" class="description tsosi-field-help">' . esc_html(
		tsosi_ui_triple_text(
			'You can also type any block name if it is not listed.',
			'También puedes escribir un nombre de bloque si no aparece en la lista.',
			'També pots escriure un nom de bloc si no surt a la llista.'
		)
	) . '</p>';
	echo '</div>';
	echo '<button type="button" class="button button-primary tsosi-start-scan" data-mode="block">' . esc_html( tsosi_ui_triple_text( 'Start scan', 'Iniciar escaneo', 'Iniciar escaneig' ) ) . '</button>';
	echo '</div></div>';
}

/**
 * @param array<string,array<string,mixed>> $profiles Profiles.
 * @return void
 */
function tsosi_admin_render_inventory_tab( $profiles ) {
	echo '<div class="tsosi-panel">';
	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Registered shortcodes', 'Shortcodes registrados', 'Shortcodes registrats' ) ) . '</h2>';
	$inventory = tsosi_get_registered_shortcode_inventory();
	echo '<p>' . esc_html(
		tsosi_ui_triple_text(
			'Shortcodes currently registered on this site (active plugins, theme, and WordPress core). Many are internal or legacy tags registered by plugins even if you never insert them in content. Use the Shortcode tab to find where each one is used.',
			'Shortcodes registrados actualmente en este sitio (plugins activos, tema y WordPress). Muchos son etiquetas internas o heredadas aunque no las insertes en el contenido. Usa la pestaña Shortcode para ver dónde se usa cada uno.',
			'Shortcodes registrats actualment en aquest lloc (plugins actius, tema i WordPress). Molts són etiquetes internes o heretades encara que no les insertis al contingut. Usa la pestanya Shortcode per veure on s\'utilitza cadascun.'
		)
	) . '</p>';
	echo '<ul class="tsosi-tag-list">';
	foreach ( $inventory as $row ) {
		echo '<li><code>' . esc_html( (string) $row['tag'] ) . '</code></li>';
	}
	echo '</ul>';

	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Plugin signatures', 'Firmas de plugins', 'Signatures de plugins' ) ) . '</h2>';
	tsosi_admin_echo_help( tsosi_admin_signatures_help_text() );
	echo '<p><button type="button" class="button tsosi-refresh-profiles" id="tsosi-refresh-profiles-inventory">' . esc_html( tsosi_ui_triple_text( 'Refresh signatures', 'Actualizar firmas', 'Actualitzar signatures' ) ) . '</button></p>';
	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Plugin', 'Plugin', 'Plugin' ) ) . '</th>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Status', 'Estado', 'Estat' ) ) . '</th>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Shortcodes', 'Shortcodes', 'Shortcodes' ) ) . '</th>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Blocks', 'Bloques', 'Blocs' ) ) . '</th>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Meta prefixes', 'Prefijos meta', 'Prefixos meta' ) ) . '</th>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Option prefixes', 'Prefijos de opciones', 'Prefixos d\'opcions' ) ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $profiles as $profile ) {
		echo '<tr>';
		echo '<td>' . esc_html( (string) $profile['name'] ) . '</td>';
		echo '<td>' . esc_html( ! empty( $profile['active'] ) ? tsosi_ui_triple_text( 'Active', 'Activo', 'Actiu' ) : tsosi_ui_triple_text( 'Inactive', 'Inactivo', 'Inactiu' ) ) . '</td>';
		echo '<td>' . esc_html( implode( ', ', (array) $profile['shortcodes'] ) ) . '</td>';
		echo '<td>' . esc_html( implode( ', ', (array) $profile['blocks'] ) ) . '</td>';
		echo '<td>' . esc_html( implode( ', ', (array) $profile['meta_prefixes'] ) ) . '</td>';
		echo '<td>' . esc_html( implode( ', ', (array) ( $profile['option_prefixes'] ?? array() ) ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * AJAX: start scan.
 *
 * @return void
 */
function tsosi_ajax_scan_start() {
	tsosi_ajax_require_manage_options();

	$mode  = tsosi_get_ajax_post_text( 'mode', 'plugin' );
	$query = array( 'mode' => sanitize_key( $mode ) );

	if ( 'plugin' === $mode ) {
		$query['plugin_file'] = tsosi_sanitize_plugin_file( tsosi_get_ajax_post_text( 'plugin_file' ) );
		if ( '' === $query['plugin_file'] ) {
			wp_send_json_error( array( 'message' => __( 'Select a plugin.', 'tso-stack-inspector' ) ) );
		}
	} elseif ( 'shortcode' === $mode ) {
		$query['shortcode'] = tsosi_sanitize_shortcode_tag( tsosi_get_ajax_post_text( 'shortcode' ) );
		if ( '' === $query['shortcode'] ) {
			wp_send_json_error( array( 'message' => __( 'Enter a shortcode tag.', 'tso-stack-inspector' ) ) );
		}
	} elseif ( 'block' === $mode ) {
		$query['block'] = tsosi_sanitize_block_name( tsosi_get_ajax_post_text( 'block' ) );
		if ( '' === $query['block'] ) {
			wp_send_json_error( array( 'message' => __( 'Enter a block name.', 'tso-stack-inspector' ) ) );
		}
	} elseif ( 'theme' === $mode ) {
		$query['theme'] = tsosi_sanitize_theme_stylesheet( tsosi_get_ajax_post_text( 'theme', wp_get_theme()->get_stylesheet() ) );
		if ( '' === $query['theme'] ) {
			wp_send_json_error( array( 'message' => __( 'Select a valid theme.', 'tso-stack-inspector' ) ) );
		}
	} else {
		wp_send_json_error( array( 'message' => __( 'Invalid scan mode.', 'tso-stack-inspector' ) ) );
	}

	if ( '1' === tsosi_get_ajax_post_text( 'background' ) ) {
		$query['background'] = true;
	}

	$result = tsosi_scan_job_start( $query );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	if ( ! empty( $query['background'] ) && empty( $result['done'] ) ) {
		tsosi_background_register_job( get_current_user_id() );
		$result['background'] = true;
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_tsosi_scan_start', 'tsosi_ajax_scan_start' );

/**
 * AJAX: scan batch step.
 *
 * @return void
 */
function tsosi_ajax_scan_step() {
	tsosi_ajax_require_manage_options();

	$result = tsosi_scan_job_step();
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_tsosi_scan_step', 'tsosi_ajax_scan_step' );

/**
 * AJAX: refresh plugin profile cache.
 *
 * @return void
 */
function tsosi_ajax_refresh_profiles() {
	tsosi_ajax_require_manage_options();
	tsosi_flush_plugin_profile_cache();
	$profiles = tsosi_get_all_plugin_profiles( true );
	wp_send_json_success(
		array(
			'count'   => count( $profiles ),
			'message' => tsosi_ui_triple_text(
				'Plugin signatures refreshed.',
				'Firmas de plugins actualizadas.',
				'Signatures de plugins actualitzades.'
			),
		)
	);
}
add_action( 'wp_ajax_tsosi_refresh_profiles', 'tsosi_ajax_refresh_profiles' );
