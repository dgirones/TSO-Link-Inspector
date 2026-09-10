<?php
/**
 * Additional admin tabs and AJAX: settings, theme, compare, history, cache.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Valid admin tab slugs.
 *
 * @return string[]
 */
function tsosi_admin_tab_slugs() {
	return array( 'plugin', 'shortcode', 'block', 'replace', 'orphans', 'theme', 'compare', 'inactive', 'inventory', 'history', 'settings' );
}

/**
 * Save scan settings from POST.
 *
 * @return void
 */
function tsosi_admin_handle_settings_save() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_POST['tsosi_save_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence gate only; check_admin_referer runs before any settings write.
		return;
	}
	$page = tsosi_get_admin_query_arg( 'page' );
	if ( 'tso-stack-inspector' !== $page ) {
		return;
	}

	tsosi_require_admin_form_nonce();

	$statuses = tsosi_get_admin_post_array( 'tsosi_post_statuses' );

	tsosi_update_scan_settings(
		array(
			'post_statuses'                => $statuses,
			'include_reusable_blocks'      => tsosi_get_admin_post_checkbox( 'tsosi_include_reusable_blocks' ),
			'include_widgets'              => tsosi_get_admin_post_checkbox( 'tsosi_include_widgets' ),
			'include_menus'                => tsosi_get_admin_post_checkbox( 'tsosi_include_menus' ),
			'include_non_autoload_options' => tsosi_get_admin_post_checkbox( 'tsosi_include_non_autoload_options' ),
			'include_user_meta'            => tsosi_get_admin_post_checkbox( 'tsosi_include_user_meta' ),
			'include_term_meta'            => tsosi_get_admin_post_checkbox( 'tsosi_include_term_meta' ),
			'include_comment_meta'         => tsosi_get_admin_post_checkbox( 'tsosi_include_comment_meta' ),
			'include_theme_mods'           => tsosi_get_admin_post_checkbox( 'tsosi_include_theme_mods' ),
			'history_max'                  => absint( tsosi_get_admin_post_text( 'tsosi_history_max', (string) TSOSI_SCAN_HISTORY_MAX_DEFAULT ) ),
			'ignore_tokens'                => tsosi_sanitize_ignore_tokens( tsosi_get_admin_post_textarea( 'tsosi_ignore_tokens' ) ),
		)
	);

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'        => 'tso-stack-inspector',
				'tab'         => 'settings',
				'tsosi_saved' => '1',
			),
			admin_url( 'tools.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'tsosi_admin_handle_settings_save' );

/**
 * Shared help text: what plugin signatures are.
 *
 * @return string
 */
function tsosi_admin_signatures_help_text() {
	return tsosi_ui_triple_text(
		'Signatures are patterns read from each plugin\'s PHP files: shortcode tags, Gutenberg block names, and common meta/option prefixes. They are cached for 24 hours. Refresh after installing or updating plugins so scans use up-to-date needles.',
		'Las firmas son patrones leídos del PHP de cada plugin: shortcodes, bloques Gutenberg y prefijos meta/opción habituales. Se guardan en caché 24 h. Actualiza tras instalar o actualizar plugins para escanear con datos recientes.',
		'Les signatures són patrons llegits del PHP de cada plugin: shortcodes, blocs Gutenberg i prefixos meta/opció habituals. Es desen en memòria cau 24 h. Actualitza després d\'instal·lar o actualitzar plugins per escanejar amb dades recents.'
	);
}

/**
 * @param string $text Help paragraph (already translated).
 * @return void
 */
function tsosi_admin_echo_help( $text ) {
	echo '<p class="description tsosi-field-help">' . esc_html( $text ) . '</p>';
}

/**
 * Cache status bar above tabs.
 *
 * @return void
 */
function tsosi_admin_render_cache_bar() {
	$status = tsosi_scan_get_cache_status();
	$ready  = ! empty( $status['ready'] );
	echo '<div class="tsosi-cache-bar" id="tsosi-cache-bar" data-ready="' . ( $ready ? '1' : '0' ) . '">';
	echo '<div class="tsosi-cache-bar-main">';
	if ( $ready ) {
		echo '<span class="tsosi-cache-ready" id="tsosi-cache-status-text">';
		echo esc_html(
			tsosi_ui_triple_text(
				'Site index cached',
				'Índice del sitio en caché',
				'Índex del lloc en memòria cau'
			)
		);
		if ( ! empty( $status['age_text'] ) ) {
			echo ' — ';
			echo esc_html(
				sprintf(
					/* translators: %s: human time diff */
					tsosi_ui_triple_text( '%s ago', 'hace %s', 'fa %s' ),
					$status['age_text']
				)
			);
		}
		echo '</span> ';
	} else {
		echo '<span class="tsosi-cache-empty" id="tsosi-cache-status-text">';
		echo esc_html(
			tsosi_ui_triple_text(
				'Preparing site index in the background…',
				'Preparando el índice del sitio en segundo plano…',
				'Preparant l\'índex del lloc en segon pla…'
			)
		);
		echo '</span> ';
	}
	echo '<button type="button" class="button button-small" id="tsosi-rebuild-cache">';
	echo esc_html( tsosi_ui_triple_text( 'Rebuild index', 'Reconstruir índice', 'Reconstruir índex' ) );
	echo '</button>';
	echo '</div>';
	tsosi_admin_echo_help(
		tsosi_ui_triple_text(
			'The index is a local copy of posts, widgets, menus, and options. It is built in the background when you open this page (or when you scan), then reused until content changes. Rebuild forces a fresh read — useful after bulk imports or if results look outdated.',
			'El índice es una copia local de entradas, widgets, menús y opciones. Se crea en segundo plano al abrir esta página (o al escanear), y se reutiliza hasta que cambie el contenido. Reconstruir obliga a leerlo de nuevo — útil tras importaciones masivas o si los resultados parecen antiguos.',
			'L\'índex és una còpia local d\'entrades, widgets, menús i opcions. Es crea en segon pla en obrir aquesta pàgina (o en escanejar), i es reutilitza fins que canviï el contingut. Reconstruir obliga a llegir-lo de nou — útil després d\'importacions massives o si els resultats semblen antic.'
		)
	);
	echo '</div>';
}

/**
 * @return void
 */
function tsosi_admin_render_settings_tab() {
	$settings = tsosi_get_scan_settings();
	$statuses = array( 'publish', 'draft', 'pending', 'future', 'private', 'inherit' );
	$saved    = '1' === tsosi_get_admin_query_arg( 'tsosi_saved' );

	echo '<div class="tsosi-panel">';
	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Scan settings', 'Ajustes de escaneo', 'Opcions d\'escaneig' ) ) . '</h2>';
	tsosi_admin_echo_help(
		tsosi_ui_triple_text(
			'Choose what content Stack Inspector reads when you run a scan. Only checked items are searched for shortcodes, blocks, and matching meta/option prefixes.',
			'Elige qué contenido lee Stack Inspector al escanear. Solo se buscan shortcodes, bloques y prefijos meta/opción en los elementos marcados.',
			'Tria quin contingut llegeix Stack Inspector en escanejar. Només es busquen shortcodes, blocs i prefixos meta/opció als elements marcats.'
		)
	);
	if ( $saved ) {
		echo '<div class="notice notice-success inline"><p>';
		echo esc_html( tsosi_ui_triple_text( 'Settings saved.', 'Ajustes guardados.', 'Opcions desades.' ) );
		echo '</p></div>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'tools.php?page=tso-stack-inspector&tab=settings' ) ) . '">';
	wp_nonce_field( TSOSI_NONCE_FORM );
	echo '<input type="hidden" name="page" value="tso-stack-inspector" />';
	echo '<input type="hidden" name="tsosi_save_settings" value="1" />';

	echo '<fieldset class="tsosi-settings-group"><legend>' . esc_html( tsosi_ui_triple_text( 'Post statuses', 'Estados de entrada', 'Estats d\'entrada' ) ) . '</legend>';
	tsosi_admin_echo_help(
		tsosi_ui_triple_text(
			'Which post statuses are included when scanning post content, templates, and post meta.',
			'Qué estados de entrada se incluyen al escanear contenido, plantillas y meta de entradas.',
			'Quins estats d\'entrada s\'inclouen en escanejar contingut, plantilles i meta d\'entrades.'
		)
	);
	$status_help = array(
		'publish' => tsosi_ui_triple_text( 'Published posts and pages visible on the site.', 'Entradas y páginas publicadas visibles en el sitio.', 'Entrades i pàgines publicades visibles al lloc.' ),
		'draft'   => tsosi_ui_triple_text( 'Drafts not yet published.', 'Borradores aún no publicados.', 'Esborranys encara no publicats.' ),
		'pending' => tsosi_ui_triple_text( 'Posts waiting for review.', 'Entradas pendientes de revisión.', 'Entrades pendents de revisió.' ),
		'future'  => tsosi_ui_triple_text( 'Scheduled posts with a future date.', 'Entradas programadas con fecha futura.', 'Entrades programades amb data futura.' ),
		'private' => tsosi_ui_triple_text( 'Private posts (admin-only visibility).', 'Entradas privadas (solo visibles para administradores).', 'Entrades privades (només visibles per a administradors).' ),
		'inherit' => tsosi_ui_triple_text( 'FSE templates/parts that use the inherit status.', 'Plantillas/partes FSE con estado inherit.', 'Plantilles/partes FSE amb estat inherit.' ),
	);
	foreach ( $statuses as $st ) {
		$checked = in_array( $st, (array) $settings['post_statuses'], true );
		echo '<div class="tsosi-settings-field">';
		echo '<label class="tsosi-check-row"><input type="checkbox" name="tsosi_post_statuses[]" value="' . esc_attr( $st ) . '"' . checked( $checked, true, false ) . ' /> ';
		echo '<strong>' . esc_html( $st ) . '</strong></label>';
		if ( isset( $status_help[ $st ] ) ) {
			echo '<span class="description tsosi-settings-field-help">' . esc_html( $status_help[ $st ] ) . '</span>';
		}
		echo '</div>';
	}
	echo '</fieldset>';

	$flags = array(
		'include_reusable_blocks'      => array(
			'label' => tsosi_ui_triple_text( 'Reusable blocks (FSE)', 'Bloques reutilizables (FSE)', 'Blocs reutilitzables (FSE)' ),
			'help'  => tsosi_ui_triple_text( 'Search wp_block posts and editor patterns that may embed shortcodes or blocks site-wide.', 'Busca entradas wp_block y patrones del editor que puedan contener shortcodes o bloques en todo el sitio.', 'Cerca entrades wp_block i patrons de l\'editor que puguin contenir shortcodes o blocs a tot el lloc.' ),
		),
		'include_widgets'              => array(
			'label' => tsosi_ui_triple_text( 'Widgets and autoload options', 'Widgets y opciones autoload', 'Widgets i opcions autoload' ),
			'help'  => tsosi_ui_triple_text( 'Search widget settings and small autoloaded wp_options rows (serialized text where shortcodes/blocks may hide).', 'Busca ajustes de widgets y filas autoload de wp_options (texto serializado donde pueden ocultarse shortcodes/bloques).', 'Cerca ajustos de widgets i files autoload de wp_options (text serialitzat on es poden amagar shortcodes/blocs).' ),
		),
		'include_menus'                => array(
			'label' => tsosi_ui_triple_text( 'Navigation menus', 'Menús de navegación', 'Menús de navegació' ),
			'help'  => tsosi_ui_triple_text( 'Search menu item titles and descriptions in every registered nav menu.', 'Busca títulos y descripciones de ítems en cada menú de navegación registrado.', 'Cerca títols i descripcions d\'elements a cada menú de navegació registrat.' ),
		),
		'include_non_autoload_options' => array(
			'label' => tsosi_ui_triple_text( 'Non-autoload wp_options (by prefix)', 'wp_options sin autoload (por prefijo)', 'wp_options sense autoload (per prefix)' ),
			'help'  => tsosi_ui_triple_text( 'When scanning by plugin, also match large option rows whose names start with the plugin prefix (not loaded on every page load).', 'Al escanear por plugin, también busca opciones grandes cuyo nombre empiece por el prefijo del plugin (no se cargan en cada visita).', 'En escanejar per plugin, també cerca opcions grans el nom de les quals comenci pel prefix del plugin (no es carreguen a cada visita).' ),
		),
		'include_user_meta'            => array(
			'label' => tsosi_ui_triple_text( 'User meta (by prefix)', 'Meta de usuario (por prefijo)', 'Meta d\'usuari (per prefix)' ),
			'help'  => tsosi_ui_triple_text( 'Find usermeta keys starting with the plugin/theme prefix (membership, custom profile fields, etc.).', 'Encuentra claves usermeta que empiecen por el prefijo del plugin/tema (membership, campos de perfil, etc.).', 'Troba claus usermeta que comencin pel prefix del plugin/tema (membership, camps de perfil, etc.).' ),
		),
		'include_term_meta'            => array(
			'label' => tsosi_ui_triple_text( 'Term meta (by prefix)', 'Meta de término (por prefijo)', 'Meta de terme (per prefix)' ),
			'help'  => tsosi_ui_triple_text( 'Find termmeta keys on categories, tags, and other taxonomies matching the prefix.', 'Encuentra claves termmeta en categorías, etiquetas y otras taxonomías que coincidan con el prefijo.', 'Troba claus termmeta en categories, etiquetes i altres taxonomies que coincideixin amb el prefix.' ),
		),
		'include_comment_meta'         => array(
			'label' => tsosi_ui_triple_text( 'Comment meta (by prefix)', 'Meta de comentario (por prefijo)', 'Meta de comentari (per prefix)' ),
			'help'  => tsosi_ui_triple_text( 'Find commentmeta keys left by plugins on comments/reviews.', 'Encuentra claves commentmeta dejadas por plugins en comentarios/reseñas.', 'Troba claus commentmeta deixades per plugins en comentaris/ressenyes.' ),
		),
		'include_theme_mods'           => array(
			'label' => tsosi_ui_triple_text( 'Theme mods (Customizer)', 'Ajustes del tema (Personalizador)', 'Ajustos del tema (Personalitzador)' ),
			'help'  => tsosi_ui_triple_text( 'Search Customizer values saved as theme_mods (widgets in customizer, embeds in theme settings). A "Theme" scan always checks that theme\'s own theme_mods regardless of this setting.', 'Busca valores del Personalizador guardados como theme_mods (widgets en personalizador, incrustaciones en ajustes del tema). Un escaneo de "Tema" siempre revisa los theme_mods de ese tema, sin importar este ajuste.', 'Cerca valors del Personalitzador desats com a theme_mods (widgets al personalitzador, incrustacions als ajustos del tema). Un escaneig de "Tema" sempre revisa els theme_mods d\'aquell tema, independentment d\'aquest ajust.' ),
		),
	);
	echo '<fieldset class="tsosi-settings-group"><legend>' . esc_html( tsosi_ui_triple_text( 'Include in scan', 'Incluir en el escaneo', 'Incloure a l\'escaneig' ) ) . '</legend>';
	foreach ( $flags as $key => $item ) {
		echo '<div class="tsosi-settings-field">';
		echo '<label class="tsosi-check-row"><input type="checkbox" name="tsosi_' . esc_attr( $key ) . '" value="1"' . checked( ! empty( $settings[ $key ] ), true, false ) . ' /> ';
		echo esc_html( $item['label'] ) . '</label>';
		echo '<span class="description tsosi-settings-field-help">' . esc_html( $item['help'] ) . '</span>';
		echo '</div>';
	}
	echo '</fieldset>';

	echo '<fieldset class="tsosi-settings-group"><legend>' . esc_html( tsosi_ui_triple_text( 'Scan history', 'Historial de escaneos', 'Historial d\'escaneigs' ) ) . '</legend>';
	echo '<div class="tsosi-settings-field">';
	echo '<label for="tsosi_history_max">' . esc_html( tsosi_ui_triple_text( 'Maximum saved scans', 'Máximo de escaneos guardados', 'Màxim d\'escaneigs desats' ) ) . '</label> ';
	echo '<input type="number" class="small-text" id="tsosi_history_max" name="tsosi_history_max" min="1" max="' . esc_attr( (string) TSOSI_SCAN_HISTORY_MAX_HARD ) . '" value="' . esc_attr( (string) (int) ( $settings['history_max'] ?? TSOSI_SCAN_HISTORY_MAX_DEFAULT ) ) . '" />';
	echo '<span class="description tsosi-settings-field-help">' . esc_html(
		sprintf(
			/* translators: %d: hard maximum */
			tsosi_ui_triple_text(
				'When the limit is reached, the oldest entries (and their files) are deleted automatically. Range: 1–%d. Default: 20.',
				'Al alcanzar el límite, se borran automáticamente las entradas más antiguas (y sus archivos). Rango: 1–%d. Por defecto: 20.',
				'En arribar al límit, s\'esborren automàticament les entrades més antigues (i els seus fitxers). Rang: 1–%d. Per defecte: 20.'
			),
			TSOSI_SCAN_HISTORY_MAX_HARD
		)
	) . '</span>';
	echo '</div></fieldset>';

	$ignore_text = implode( "\n", (array) ( $settings['ignore_tokens'] ?? array() ) );
	echo '<fieldset class="tsosi-settings-group"><legend>' . esc_html( tsosi_ui_triple_text( 'Ignore list', 'Lista de ignorados', 'Llista d\'ignorats' ) ) . '</legend>';
	echo '<div class="tsosi-settings-field">';
	echo '<label for="tsosi_ignore_tokens">' . esc_html( tsosi_ui_triple_text( 'Hide these matches', 'Ocultar estas coincidencias', 'Amaga aquestes coincidències' ) ) . '</label>';
	echo '<textarea class="large-text code" id="tsosi_ignore_tokens" name="tsosi_ignore_tokens" rows="6" cols="50">';
	echo esc_textarea( $ignore_text );
	echo '</textarea>';
	echo '<span class="description tsosi-settings-field-help">' . esc_html(
		tsosi_ui_triple_text(
			'One shortcode tag, block name, or meta/option key per line. Matching scan rows, orphans, and inactive-plugin traces are hidden. Prefixes match from the start (e.g. _tsotab hides _tsotab_view_count). Lines starting with # are comments.',
			'Un shortcode, nombre de bloque o clave meta/opción por línea. Se ocultan coincidencias del escaneo, huérfanos y rastros de plugins inactivos. Los prefijos coinciden desde el inicio (p. ej. _tsotab oculta _tsotab_view_count). Las líneas que empiezan por # son comentarios.',
			'Un shortcode, nom de bloc o clau meta/opció per línia. S\'amaguen coincidències de l\'escaneig, orfes i rastres de plugins inactius. Els prefixos coincideixen des de l\'inici (p. ex. _tsotab amaga _tsotab_view_count). Les línies que comencen per # són comentaris.'
		)
	) . '</span>';
	echo '</div></fieldset>';

	echo '<p><button type="submit" class="button button-primary">';
	echo esc_html( tsosi_ui_triple_text( 'Save settings', 'Guardar ajustes', 'Desar opcions' ) );
	echo '</button></p></form></div>';
}

/**
 * @return void
 */
function tsosi_admin_render_theme_tab() {
	$active      = wp_get_theme();
	$active_slug = (string) $active->get_stylesheet();
	$themes      = wp_get_themes();
	uksort(
		$themes,
		function ( $a, $b ) use ( $active_slug ) {
			if ( $a === $active_slug ) {
				return -1;
			}
			if ( $b === $active_slug ) {
				return 1;
			}
			return strcasecmp( $a, $b );
		}
	);

	$selected = $active_slug;
	$profile  = tsosi_build_theme_profile( $selected );

	echo '<div class="tsosi-panel">';
	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Theme scan', 'Escaneo de tema', 'Escaneig de tema' ) ) . '</h2>';
	tsosi_admin_echo_help(
		tsosi_ui_triple_text(
			'Search the whole site for shortcodes, blocks, and meta/option prefixes associated with a theme. The active theme is selected by default; you can also pick an inactive (installed) theme — for example before switching back to a child theme or removing an old one.',
			'Busca en todo el sitio shortcodes, bloques y prefijos meta/opción de un tema. Por defecto está el tema activo; también puedes elegir un tema instalado pero inactivo — por ejemplo antes de volver a un tema hijo o eliminar uno antiguo.',
			'Cerca a tot el lloc shortcodes, blocs i prefixos meta/opció d\'un tema. Per defecte hi ha el tema actiu; també pots triar un tema instalat però inactiu — per exemple abans de tornar a un tema fill o eliminar-ne un d\'antic.'
		)
	);

	echo '<div class="tsosi-form-row tsosi-form-row-block">';
	echo '<label for="tsosi-theme-stylesheet">' . esc_html( tsosi_ui_triple_text( 'Theme', 'Tema', 'Tema' ) ) . '</label>';
	echo '<select id="tsosi-theme-stylesheet" class="tsosi-theme-select">';
	foreach ( $themes as $stylesheet => $theme ) {
		if ( ! $theme instanceof WP_Theme ) {
			continue;
		}
		$name      = (string) $theme->get( 'Name' );
		$label     = $name;
		$is_active = ( $stylesheet === $active_slug );
		if ( $is_active ) {
			$label .= ' (' . tsosi_ui_triple_text( 'active', 'activo', 'actiu' ) . ')';
		} else {
			$label .= ' (' . tsosi_ui_triple_text( 'inactive', 'inactivo', 'inactiu' ) . ')';
		}
		echo '<option value="' . esc_attr( $stylesheet ) . '"' . selected( $selected, $stylesheet, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></div>';

	echo '<div id="tsosi-theme-profile" class="tsosi-theme-profile" data-active-stylesheet="' . esc_attr( $active_slug ) . '">';
	echo '<p class="description" id="tsosi-theme-profile-intro">' . esc_html(
		tsosi_ui_triple_text(
			'Signatures detected in the selected theme\'s files (used as scan needles):',
			'Firmas detectadas en los archivos del tema seleccionado (usadas como agujas de escaneo):',
			'Signatures detectades als fitxers del tema seleccionat (usades com a agulles d\'escaneig):'
		)
	) . '</p>';
	echo '<ul class="tsosi-tag-list" id="tsosi-theme-profile-list">';
	$theme_profile_join = static function ( $items ) {
		$joined = implode( ', ', (array) $items );
		return '' !== $joined ? $joined : '—';
	};
	echo '<li>' . esc_html( tsosi_ui_triple_text( 'Shortcodes', 'Shortcodes', 'Shortcodes' ) ) . ': <code>' . esc_html( $theme_profile_join( $profile['shortcodes'] ) ) . '</code></li>';
	echo '<li>' . esc_html( tsosi_ui_triple_text( 'Blocks', 'Bloques', 'Blocs' ) ) . ': <code>' . esc_html( $theme_profile_join( $profile['blocks'] ) ) . '</code></li>';
	echo '<li>' . esc_html( tsosi_ui_triple_text( 'Meta prefixes', 'Prefijos meta', 'Prefixos meta' ) ) . ': <code>' . esc_html( $theme_profile_join( $profile['meta_prefixes'] ) ) . '</code></li>';
	// tsosi_build_theme_profile() always sets an option_prefixes entry for
	// "theme_mods_{stylesheet}", searched unconditionally regardless of the
	// "include_theme_mods" setting -- omitting it here would show an all-dash "nothing
	// to search for" preview for a theme with no shortcodes/blocks/meta, even though the
	// scan still checks (and can match) theme_mods.
	echo '<li>' . esc_html( tsosi_ui_triple_text( 'Option prefixes', 'Prefijos de opciones', 'Prefixos d\'opcions' ) ) . ': <code>' . esc_html( $theme_profile_join( $profile['option_prefixes'] ) ) . '</code></li>';
	echo '</ul>';
	echo '<p class="description" id="tsosi-theme-profile-note">' . esc_html(
		tsosi_ui_triple_text(
			'The preview below updates when you pick another theme. The scan searches the whole site for the selected theme\'s signatures.',
			'La vista previa se actualiza al elegir otro tema. El escaneo busca en todo el sitio las firmas del tema seleccionado.',
			'La vista prèvia s\'actualitza en triar un altre tema. L\'escaneig cerca a tot el lloc les signatures del tema seleccionat.'
		)
	) . '</p>';
	echo '</div>';

	echo '<button type="button" class="button button-primary tsosi-start-scan" data-mode="theme">';
	echo esc_html( tsosi_ui_triple_text( 'Start scan', 'Iniciar escaneo', 'Iniciar escaneig' ) ) . '</button>';
	echo '</div>';
}

/**
 * @param array<string,array<string,string>> $plugins Plugins list.
 * @return void
 */
function tsosi_admin_render_compare_tab( $plugins ) {
	echo '<div class="tsosi-panel">';
	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Compare plugins', 'Comparar plugins', 'Comparar plugins' ) ) . '</h2>';
	tsosi_admin_echo_help(
		tsosi_ui_triple_text(
			'Compares the signatures of two plugins side by side and shows which shortcodes, blocks, and prefixes they share. Useful before migrations (e.g. Contact Form 7 → another form plugin), when two plugins overlap, or to see if uninstalling one would break content that still depends on shared tags.',
			'Compara las firmas de dos plugins y muestra qué shortcodes, bloques y prefijos comparten. Útil antes de migraciones (p. ej. Contact Form 7 → otro plugin de formularios), cuando dos plugins se solapan, o para ver si desinstalar uno rompería contenido que aún usa etiquetas comunes.',
			'Compara les signatures de dos plugins i mostra quins shortcodes, blocs i prefixos comparteixen. Útil abans de migracions (p. ex. Contact Form 7 → un altre plugin de formularis), quan dos plugins se solapen, o per veure si desinstal·lar-ne un trencaria contingut que encara usa etiquetes comunes.'
		)
	);
	echo '<div class="tsosi-form-row">';
	echo '<label for="tsosi-compare-a">' . esc_html( tsosi_ui_triple_text( 'Plugin A', 'Plugin A', 'Plugin A' ) ) . '</label>';
	echo '<select id="tsosi-compare-a"><option value="">—</option>';
	foreach ( $plugins as $file => $header ) {
		echo '<option value="' . esc_attr( $file ) . '">' . esc_html( (string) ( $header['Name'] ?? $file ) ) . '</option>';
	}
	echo '</select>';
	echo '<label for="tsosi-compare-b">' . esc_html( tsosi_ui_triple_text( 'Plugin B', 'Plugin B', 'Plugin B' ) ) . '</label>';
	echo '<select id="tsosi-compare-b"><option value="">—</option>';
	foreach ( $plugins as $file => $header ) {
		echo '<option value="' . esc_attr( $file ) . '">' . esc_html( (string) ( $header['Name'] ?? $file ) ) . '</option>';
	}
	echo '</select>';
	echo '<button type="button" class="button button-primary" id="tsosi-run-compare">';
	echo esc_html( tsosi_ui_triple_text( 'Compare', 'Comparar', 'Comparar' ) );
	echo '</button></div>';
	echo '<div id="tsosi-compare-results" class="tsosi-compare-results" hidden></div></div>';
}

/**
 * Replace tab (dry-run + apply for shortcodes/blocks in post content).
 *
 * @return void
 */
function tsosi_admin_render_replace_tab() {
	$kind = tsosi_get_admin_query_arg( TSOSI_ADMIN_QUERY_REPLACE_KIND, 'shortcode' );
	$kind = 'block' === $kind ? 'block' : 'shortcode';
	$from = tsosi_get_admin_query_arg( TSOSI_ADMIN_QUERY_REPLACE_FROM );
	$from = 'block' === $kind ? tsosi_sanitize_block_name( $from ) : tsosi_sanitize_shortcode_tag( $from );

	echo '<div class="tsosi-panel">';
	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Replace in content', 'Sustituir en el contenido', 'Substituir al contingut' ) ) . '</h2>';
	tsosi_admin_echo_help(
		tsosi_ui_triple_text(
			'Dry-run first: preview which posts/pages/templates would change. Then apply in small batches. Only edits post_content (not widgets or options). Always keep a backup.',
			'Primero dry-run: previsualiza qué entradas/páginas/plantillas cambiarían. Luego aplica por lotes. Solo edita post_content (no widgets ni opciones). Haz siempre una copia de seguridad.',
			'Primer dry-run: previsualitza quines entrades/pàgines/plantilles canviarien. Després aplica per lots. Només edita post_content (ni widgets ni opcions). Fes sempre una còpia de seguretat.'
		)
	);

	echo '<div class="tsosi-form-row">';
	echo '<label for="tsosi-replace-kind">' . esc_html( tsosi_ui_triple_text( 'Type', 'Tipo', 'Tipus' ) ) . '</label>';
	echo '<select id="tsosi-replace-kind">';
	echo '<option value="shortcode"' . selected( $kind, 'shortcode', false ) . '>shortcode</option>';
	echo '<option value="block"' . selected( $kind, 'block', false ) . '>block</option>';
	echo '</select></div>';

	echo '<div class="tsosi-form-row">';
	echo '<label for="tsosi-replace-from">' . esc_html( tsosi_ui_triple_text( 'Find', 'Buscar', 'Cercar' ) ) . '</label>';
	echo '<input type="text" id="tsosi-replace-from" class="regular-text" placeholder="contact-form-7" value="' . esc_attr( $from ) . '" />';
	echo '</div>';

	echo '<div class="tsosi-form-row">';
	echo '<label for="tsosi-replace-to">' . esc_html( tsosi_ui_triple_text( 'Replace with', 'Sustituir por', 'Substituir per' ) ) . '</label>';
	echo '<input type="text" id="tsosi-replace-to" class="regular-text" placeholder="new-form" />';
	echo '</div>';

	echo '<div class="tsosi-form-row">';
	echo '<button type="button" class="button button-primary" id="tsosi-replace-preview">';
	echo esc_html( tsosi_ui_triple_text( 'Preview (dry-run)', 'Vista previa (dry-run)', 'Vista prèvia (dry-run)' ) );
	echo '</button> ';
	echo '<button type="button" class="button" id="tsosi-replace-apply" disabled>';
	echo esc_html( tsosi_ui_triple_text( 'Apply to listed posts', 'Aplicar a las entradas listadas', 'Aplicar a les entrades llistades' ) );
	echo '</button></div>';

	echo '<div id="tsosi-replace-status" class="description" aria-live="polite"></div>';
	echo '<div id="tsosi-replace-results" class="tsosi-replace-results" hidden></div>';

	$backups = array_reverse( tsosi_replace_backup_get_index() );
	echo '<h3>' . esc_html( tsosi_ui_triple_text( 'Recent replace backups (undo)', 'Copias recientes (deshacer)', 'Còpies recents (desfer)' ) ) . '</h3>';
	tsosi_admin_echo_help(
		tsosi_ui_triple_text(
			'Each apply stores the previous post_content for ~48 hours under uploads. You can restore those posts with Undo.',
			'Cada aplicación guarda el post_content anterior ~48 h en uploads. Puedes restaurar esas entradas con Deshacer.',
			'Cada aplicació desa el post_content anterior ~48 h a uploads. Pots restaurar aquestes entrades amb Desfer.'
		)
	);
	if ( empty( $backups ) ) {
		echo '<p class="description">' . esc_html( tsosi_ui_triple_text( 'No replace backups yet.', 'Aún no hay copias de sustitución.', 'Encara no hi ha còpies de substitució.' ) ) . '</p>';
	} else {
		echo '<table class="widefat striped" id="tsosi-replace-backups"><thead><tr>';
		echo '<th>' . esc_html( tsosi_ui_triple_text( 'When', 'Cuándo', 'Quan' ) ) . '</th>';
		echo '<th>' . esc_html( tsosi_ui_triple_text( 'Change', 'Cambio', 'Canvi' ) ) . '</th>';
		echo '<th>' . esc_html( tsosi_ui_triple_text( 'Posts', 'Entradas', 'Entrades' ) ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		foreach ( $backups as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			$when = ! empty( $b['saved_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $b['saved_at'] ) : '—';
			$chg  = (string) ( $b['from'] ?? '' ) . ' → ' . (string) ( $b['to'] ?? '' );
			echo '<tr data-backup-id="' . esc_attr( (string) ( $b['id'] ?? '' ) ) . '">';
			echo '<td>' . esc_html( $when ) . '</td>';
			echo '<td><code>' . esc_html( $chg ) . '</code></td>';
			echo '<td>' . esc_html( (string) (int) ( $b['count'] ?? 0 ) ) . '</td>';
			echo '<td><button type="button" class="button button-small tsosi-replace-undo" data-id="' . esc_attr( (string) ( $b['id'] ?? '' ) ) . '">';
			echo esc_html( tsosi_ui_triple_text( 'Undo', 'Deshacer', 'Desfer' ) );
			echo '</button></td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}

/**
 * Orphans tab UI.
 *
 * @return void
 */
function tsosi_admin_render_orphans_tab() {
	echo '<div class="tsosi-panel">';
	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Orphan shortcodes & blocks', 'Shortcodes y bloques huérfanos', 'Shortcodes i blocs orfes' ) ) . '</h2>';
	tsosi_admin_echo_help(
		tsosi_ui_triple_text(
			'Looks for shortcodes and blocks still in editable content (posts, site templates, widgets, and navigation menus) that no currently active plugin registers. Useful after removing a plugin to see if any markup was left behind before you clean up or replace it. "Replace" is only offered when the tag also appears in a post/page/template -- Replace only edits post content, not widgets or menus.',
			'Busca shortcodes y bloques que siguen en el contenido editable (entradas, plantillas del sitio, widgets y menús de navegación) pero que ningún plugin activo registra ahora. Útil tras quitar un plugin para comprobar si quedó markup antes de limpiar o sustituirlo. "Sustituir" solo aparece cuando la etiqueta también está en una entrada/página/plantilla -- Sustituir solo edita el contenido, no widgets ni menús.',
			'Cerca shortcodes i blocs que encara hi ha al contingut editable (entrades, plantilles del lloc, widgets i menús de navegació) però que cap plugin actiu registra ara. Útil després de treure un plugin per comprovar si ha quedat markup abans de netejar-lo o substituir-lo. "Substituir" només apareix quan l\'etiqueta també és en una entrada/pàgina/plantilla -- Substituir només edita el contingut, no widgets ni menús.'
		)
	);
	echo '<p><button type="button" class="button button-primary" id="tsosi-run-orphans">';
	echo esc_html( tsosi_ui_triple_text( 'Find orphans', 'Buscar huérfanos', 'Cercar orfes' ) );
	echo '</button> ';
	echo '<button type="button" class="button" id="tsosi-orphans-export" hidden>';
	echo esc_html( tsosi_ui_triple_text( 'Export CSV', 'Exportar CSV', 'Exportar CSV' ) );
	echo '</button></p>';
	echo '<div id="tsosi-orphans-status" class="description" aria-live="polite"></div>';
	echo '<div id="tsosi-orphans-results" hidden></div>';
	echo '</div>';
}

/**
 * Inactive plugins audit tab.
 *
 * @param array<string,array<string,string>> $plugins Plugins.
 * @return void
 */
function tsosi_admin_render_inactive_tab( $plugins ) {
	$inactive = 0;
	foreach ( $plugins as $file => $header ) {
		if ( ! tsosi_is_plugin_active_file( $file ) ) {
			++$inactive;
		}
	}
	echo '<div class="tsosi-panel">';
	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Inactive plugins audit', 'Auditoría de plugins inactivos', 'Auditoria de plugins inactius' ) ) . '</h2>';
	tsosi_admin_echo_help(
		tsosi_ui_triple_text(
			'Quickly check which deactivated plugins still leave shortcodes, blocks, or meta/option traces in the site. Uses the cached site index (fast). Sorted by risk.',
			'Revisa rápido qué plugins desactivados aún dejan shortcodes, bloques o rastros meta/opción. Usa el índice en caché (rápido). Ordenado por riesgo.',
			'Revisa ràpid quins plugins desactivats encara deixen shortcodes, blocs o rastres meta/opció. Usa l\'índex en memòria cau (ràpid). Ordenat per risc.'
		)
	);

	echo '<p><strong>' . esc_html(
		sprintf(
			/* translators: %d: inactive plugin count */
			tsosi_ui_triple_text( '%d inactive plugin(s) installed.', '%d plugin(s) inactivo(s) instalado(s).', '%d plugin(s) inactiu(s) instal·lat(s).' ),
			$inactive
		)
	) . '</strong></p>';

	echo '<p><button type="button" class="button button-primary" id="tsosi-run-inactive-audit">';
	echo esc_html( tsosi_ui_triple_text( 'Audit inactive plugins', 'Auditar plugins inactivos', 'Auditar plugins inactius' ) );
	echo '</button> ';
	echo '<button type="button" class="button" id="tsosi-inactive-export" hidden>';
	echo esc_html( tsosi_ui_triple_text( 'Export CSV', 'Exportar CSV', 'Exportar CSV' ) );
	echo '</button></p>';
	echo '<div id="tsosi-inactive-audit-status" class="description" aria-live="polite"></div>';
	echo '<div id="tsosi-inactive-audit-results" class="tsosi-inactive-audit-results" hidden></div>';
	echo '</div>';
}

/**
 * Label for a history entry in select lists (date, target, match count).
 *
 * @param array<string,mixed> $entry History index row.
 * @return string
 */
function tsosi_admin_history_entry_option_label( $entry ) {
	if ( ! is_array( $entry ) ) {
		return '';
	}
	$when    = ! empty( $entry['saved_at'] )
		? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['saved_at'] )
		: '—';
	$label   = (string) ( $entry['label'] ?? '' );
	$count   = (int) ( $entry['result_count'] ?? 0 );
	$matches = sprintf(
		/* translators: %d: number of matches */
		tsosi_ui_triple_text( '%d matches', '%d coincidencias', '%d coincidències' ),
		$count
	);
	return trim( $when . ' — ' . $label . ' (' . $matches . ')' );
}

/**
 * @return void
 */
function tsosi_admin_render_history_tab() {
	$index = array_reverse( tsosi_scan_history_get_index() );
	$max   = tsosi_scan_history_get_max();

	echo '<div class="tsosi-panel">';
	echo '<h2>' . esc_html( tsosi_ui_triple_text( 'Scan history', 'Historial de escaneos', 'Historial d\'escaneigs' ) ) . '</h2>';
	echo '<p class="description">' . esc_html(
		sprintf(
			/* translators: %d: max history entries */
			tsosi_ui_triple_text(
				'Recent completed scans (stored under uploads). Up to %d entries are kept; older ones are removed automatically. You can also delete entries here.',
				'Escaneos completados recientes (en uploads). Se guardan hasta %d; los más antiguos se eliminan solos. También puedes borrar entradas aquí.',
				'Escaneigs completats recents (a uploads). Se\'n desen fins a %d; els més antics s\'esborren sols. També pots esborrar entrades aquí.'
			),
			$max
		)
	) . '</p>';

	if ( empty( $index ) ) {
		echo '<p>' . esc_html( tsosi_ui_triple_text( 'No saved scans yet.', 'Aún no hay escaneos guardados.', 'Encara no hi ha escaneigs desats.' ) ) . '</p>';
	} else {
		echo '<p class="tsosi-history-toolbar">';
		echo '<button type="button" class="button" id="tsosi-clear-history">';
		echo esc_html( tsosi_ui_triple_text( 'Clear all history', 'Vaciar historial', 'Buidar historial' ) );
		echo '</button></p>';

		if ( count( $index ) >= 2 ) {
			echo '<div class="tsosi-history-diff-box">';
			echo '<h3>' . esc_html( tsosi_ui_triple_text( 'Compare two scans', 'Comparar dos escaneos', 'Comparar dos escaneigs' ) ) . '</h3>';
			echo '<p class="description">' . esc_html(
				tsosi_ui_triple_text(
					'Pick a newer scan (A) and an older one (B) to see what changed — matches added or removed between the two runs.',
					'Elige un escaneo más reciente (A) y uno más antiguo (B) para ver qué cambió — coincidencias añadidas o eliminadas entre ambos.',
					'Tria un escaneig més recent (A) i un de més antic (B) per veure què ha canviat — coincidències afegides o eliminades entre ambdós.'
				)
			) . '</p>';
			echo '<div class="tsosi-history-diff-form">';
			echo '<div class="tsosi-history-diff-field">';
			echo '<label for="tsosi-diff-a">' . esc_html( tsosi_ui_triple_text( 'Newer scan (A)', 'Escaneo más reciente (A)', 'Escaneig més recent (A)' ) ) . '</label>';
			echo '<select id="tsosi-diff-a" class="regular-text">';
			$i = 0;
			foreach ( $index as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
					continue;
				}
				echo '<option value="' . esc_attr( (string) $entry['id'] ) . '"' . selected( 0 === $i, true, false ) . '>';
				echo esc_html( tsosi_admin_history_entry_option_label( $entry ) );
				echo '</option>';
				++$i;
			}
			echo '</select></div>';
			echo '<div class="tsosi-history-diff-vs" aria-hidden="true">vs</div>';
			echo '<div class="tsosi-history-diff-field">';
			echo '<label for="tsosi-diff-b">' . esc_html( tsosi_ui_triple_text( 'Older scan (B)', 'Escaneo más antiguo (B)', 'Escaneig més antic (B)' ) ) . '</label>';
			echo '<select id="tsosi-diff-b" class="regular-text">';
			$i = 0;
			foreach ( $index as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
					continue;
				}
				echo '<option value="' . esc_attr( (string) $entry['id'] ) . '"' . selected( 1 === $i, true, false ) . '>';
				echo esc_html( tsosi_admin_history_entry_option_label( $entry ) );
				echo '</option>';
				++$i;
			}
			echo '</select></div>';
			echo '<div class="tsosi-history-diff-actions">';
			echo '<button type="button" class="button button-primary" id="tsosi-run-history-diff">';
			echo esc_html( tsosi_ui_triple_text( 'Compare', 'Comparar', 'Comparar' ) );
			echo '</button></div>';
			echo '</div>';
			echo '<div id="tsosi-history-diff-results" class="tsosi-history-diff-results" hidden></div>';
			echo '</div>';
		}

		echo '<table class="widefat striped" id="tsosi-history-table"><thead><tr>';
		echo '<th>' . esc_html( tsosi_ui_triple_text( 'When', 'Cuándo', 'Quan' ) ) . '</th>';
		echo '<th>' . esc_html( tsosi_ui_triple_text( 'Target', 'Objetivo', 'Objectiu' ) ) . '</th>';
		echo '<th>' . esc_html( tsosi_ui_triple_text( 'Matches', 'Coincidencias', 'Coincidències' ) ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $index as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$when = ! empty( $entry['saved_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['saved_at'] ) : '—';
			$id   = (string) ( $entry['id'] ?? '' );
			echo '<tr data-history-id="' . esc_attr( $id ) . '">';
			echo '<td>' . esc_html( $when ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['label'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) ( $entry['result_count'] ?? 0 ) ) . '</td>';
			echo '<td class="tsosi-history-actions">';
			echo '<button type="button" class="button button-small tsosi-load-history" data-id="' . esc_attr( $id ) . '">';
			echo esc_html( tsosi_ui_triple_text( 'View', 'Ver', 'Veure' ) );
			echo '</button> ';
			echo '<button type="button" class="button button-small button-link-delete tsosi-delete-history" data-id="' . esc_attr( $id ) . '">';
			echo esc_html( tsosi_ui_triple_text( 'Delete', 'Borrar', 'Esborrar' ) );
			echo '</button></td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}

/**
 * AJAX: cancel scan.
 *
 * @return void
 */
function tsosi_ajax_scan_cancel() {
	tsosi_ajax_require_manage_options();
	tsosi_scan_job_cancel();
	wp_send_json_success(
		array(
			'message' => tsosi_ui_triple_text(
				'Scan cancelled.',
				'Escaneo cancelado.',
				'Escaneig cancel·lat.'
			),
		)
	);
}
add_action( 'wp_ajax_tsosi_scan_cancel', 'tsosi_ajax_scan_cancel' );

/**
 * AJAX: flush content cache.
 *
 * @return void
 */
function tsosi_ajax_rebuild_cache() {
	tsosi_ajax_require_manage_options();
	tsosi_scan_job_cancel();
	tsosi_index_job_cancel();
	tsosi_scan_rebuild_content_cache();
	$start = tsosi_index_job_start( true );
	if ( is_wp_error( $start ) ) {
		wp_send_json_error(
			array(
				'code'    => $start->get_error_code(),
				'message' => $start->get_error_message(),
			),
			500
		);
	}
	wp_send_json_success( $start );
}
add_action( 'wp_ajax_tsosi_rebuild_cache', 'tsosi_ajax_rebuild_cache' );

/**
 * AJAX: start batched site-index build.
 *
 * @return void
 */
function tsosi_ajax_index_start() {
	tsosi_ajax_require_manage_options();
	$force = '1' === tsosi_get_ajax_post_text( 'force' );
	$start = tsosi_index_job_start( $force );
	if ( is_wp_error( $start ) ) {
		wp_send_json_error(
			array(
				'code'    => $start->get_error_code(),
				'message' => $start->get_error_message(),
			)
		);
	}
	wp_send_json_success( $start );
}
add_action( 'wp_ajax_tsosi_index_start', 'tsosi_ajax_index_start' );

/**
 * AJAX: one site-index batch.
 *
 * @return void
 */
function tsosi_ajax_index_step() {
	tsosi_ajax_require_manage_options();
	$step = tsosi_index_job_step();
	if ( is_wp_error( $step ) ) {
		wp_send_json_error(
			array(
				'code'    => $step->get_error_code(),
				'message' => $step->get_error_message(),
			)
		);
	}
	wp_send_json_success( $step );
}
add_action( 'wp_ajax_tsosi_index_step', 'tsosi_ajax_index_step' );

/**
 * AJAX: theme signature preview for admin tab.
 *
 * @return void
 */
function tsosi_ajax_theme_profile() {
	tsosi_ajax_require_manage_options();

	$stylesheet = tsosi_sanitize_theme_stylesheet( tsosi_get_ajax_post_text( 'theme' ) );
	if ( '' === $stylesheet ) {
		wp_send_json_error( array( 'message' => __( 'Select a valid theme.', 'tso-stack-inspector' ) ) );
	}

	$profile = tsosi_build_theme_profile( $stylesheet );
	wp_send_json_success(
		array(
			'shortcodes'    => array_values( (array) ( $profile['shortcodes'] ?? array() ) ),
			'blocks'        => array_values( (array) ( $profile['blocks'] ?? array() ) ),
			'meta_prefixes' => array_values( (array) ( $profile['meta_prefixes'] ?? array() ) ),
		)
	);
}
add_action( 'wp_ajax_tsosi_theme_profile', 'tsosi_ajax_theme_profile' );

/**
 * AJAX: compare two plugins.
 *
 * @return void
 */
function tsosi_ajax_compare_plugins() {
	tsosi_ajax_require_manage_options();
	$a = tsosi_sanitize_plugin_file( tsosi_get_ajax_post_text( 'plugin_a' ) );
	$b = tsosi_sanitize_plugin_file( tsosi_get_ajax_post_text( 'plugin_b' ) );
	if ( '' === $a || '' === $b ) {
		wp_send_json_error( array( 'message' => __( 'Select two plugins.', 'tso-stack-inspector' ) ) );
	}
	wp_send_json_success( tsosi_compare_plugin_profiles( $a, $b ) );
}
add_action( 'wp_ajax_tsosi_compare_plugins', 'tsosi_ajax_compare_plugins' );

/**
 * AJAX: load history entry.
 *
 * @return void
 */
function tsosi_ajax_load_history() {
	tsosi_ajax_require_manage_options();
	$id   = tsosi_get_ajax_post_text( 'history_id' );
	$data = tsosi_scan_history_load( $id );
	if ( ! is_array( $data ) ) {
		wp_send_json_error( array( 'message' => __( 'History entry not found.', 'tso-stack-inspector' ) ) );
	}
	wp_send_json_success( $data );
}
add_action( 'wp_ajax_tsosi_load_history', 'tsosi_ajax_load_history' );

/**
 * AJAX: delete one history entry.
 *
 * @return void
 */
function tsosi_ajax_delete_history() {
	tsosi_ajax_require_manage_options();
	$id = tsosi_get_ajax_post_text( 'history_id' );
	if ( ! tsosi_scan_history_delete( $id ) ) {
		wp_send_json_error(
			array(
				'message' => tsosi_ui_triple_text(
					'History entry not found.',
					'Entrada de historial no encontrada.',
					'Entrada d\'historial no trobada.'
				),
			)
		);
	}
	wp_send_json_success(
		array(
			'message' => tsosi_ui_triple_text(
				'History entry deleted.',
				'Entrada de historial borrada.',
				'Entrada d\'historial esborrada.'
			),
		)
	);
}
add_action( 'wp_ajax_tsosi_delete_history', 'tsosi_ajax_delete_history' );

/**
 * AJAX: clear all history.
 *
 * @return void
 */
function tsosi_ajax_clear_history() {
	tsosi_ajax_require_manage_options();
	tsosi_scan_history_delete_all();
	wp_send_json_success(
		array(
			'message' => tsosi_ui_triple_text(
				'All scan history cleared.',
				'Historial de escaneos vaciado.',
				'Historial d\'escaneigs buidat.'
			),
		)
	);
}
add_action( 'wp_ajax_tsosi_clear_history', 'tsosi_ajax_clear_history' );

/**
 * AJAX: audit inactive plugins against site index.
 *
 * @return void
 */
function tsosi_ajax_audit_inactive() {
	tsosi_ajax_require_manage_options();

	$result = tsosi_audit_inactive_plugins();
	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			)
		);
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_tsosi_audit_inactive', 'tsosi_ajax_audit_inactive' );

/**
 * AJAX: dry-run replace preview.
 *
 * @return void
 */
function tsosi_ajax_replace_preview() {
	tsosi_ajax_require_manage_options();
	$kind   = tsosi_get_ajax_post_text( 'kind', 'shortcode' );
	$from   = tsosi_get_ajax_post_text( 'from' );
	$to     = tsosi_get_ajax_post_text( 'to' );
	$result = tsosi_replace_preview( $kind, $from, $to );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_tsosi_replace_preview', 'tsosi_ajax_replace_preview' );

/**
 * AJAX: apply replace batch.
 *
 * @return void
 */
function tsosi_ajax_replace_apply() {
	tsosi_ajax_require_manage_options();
	$kind      = tsosi_get_ajax_post_text( 'kind', 'shortcode' );
	$from      = tsosi_get_ajax_post_text( 'from' );
	$to        = tsosi_get_ajax_post_text( 'to' );
	$raw       = tsosi_get_ajax_post_text( 'post_ids' );
	$ids       = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
	$backup_id = tsosi_get_ajax_post_text( 'backup_id' );
	$result    = tsosi_replace_apply( $kind, $from, $to, $ids, $backup_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_tsosi_replace_apply', 'tsosi_ajax_replace_apply' );

/**
 * AJAX: undo a replace backup.
 *
 * @return void
 */
function tsosi_ajax_replace_undo() {
	tsosi_ajax_require_manage_options();
	$id     = tsosi_get_ajax_post_text( 'backup_id' );
	$result = tsosi_replace_backup_undo( $id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_tsosi_replace_undo', 'tsosi_ajax_replace_undo' );

/**
 * AJAX: find orphan shortcodes/blocks.
 *
 * @return void
 */
function tsosi_ajax_find_orphans() {
	tsosi_ajax_require_manage_options();
	$result = tsosi_orphans_find();
	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			)
		);
	}
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_tsosi_find_orphans', 'tsosi_ajax_find_orphans' );

/**
 * AJAX: diff two history entries.
 *
 * @return void
 */
function tsosi_ajax_history_diff() {
	tsosi_ajax_require_manage_options();
	$a      = tsosi_get_ajax_post_text( 'id_a' );
	$b      = tsosi_get_ajax_post_text( 'id_b' );
	$result = tsosi_scan_history_diff( $a, $b );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_tsosi_history_diff', 'tsosi_ajax_history_diff' );

/**
 * AJAX: poll background scan.
 *
 * @return void
 */
function tsosi_ajax_scan_poll() {
	tsosi_ajax_require_manage_options();
	$done = tsosi_background_get_completed_result();
	if ( is_array( $done ) ) {
		wp_send_json_success( $done );
	}
	$progress = tsosi_background_get_progress();
	if ( is_array( $progress ) ) {
		wp_send_json_success( $progress );
	}
	wp_send_json_success(
		array(
			'done'       => false,
			'background' => false,
		)
	);
}
add_action( 'wp_ajax_tsosi_scan_poll', 'tsosi_ajax_scan_poll' );
