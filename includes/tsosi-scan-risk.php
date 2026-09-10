<?php
/**
 * Uninstall risk assessment and inactive-plugin audit helpers.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Match types that mean the plugin is still embedded in visible content.
 *
 * @return string[]
 */
function tsosi_scan_risk_content_match_types() {
	return array( 'shortcode', 'block' );
}

/**
 * Whether a scan row is an active widget instance currently placed in a live sidebar
 * (tsosi_scan_active_widget_instances(), source_type 'widget') rather than a merely
 * leftover wp_options row. Uninstalling the plugin would visibly break the front end
 * right now, the same immediate, user-facing impact as a shortcode or block still left
 * in post content -- so this must not be scored as low-risk "leftover data" alongside an
 * inert option nobody references any more.
 *
 * @param array<string,mixed> $row Scan row.
 * @return bool
 */
function tsosi_scan_risk_row_is_active_widget( $row ) {
	return isset( $row['source_type'] ) && 'widget' === sanitize_key( (string) $row['source_type'] );
}

/**
 * Assess uninstall risk from scan result rows.
 *
 * @param array<int,array<string,mixed>> $results Scan rows.
 * @return array{level:string,label:string,help:string,total:int,content:int,data:int}
 */
function tsosi_scan_assess_risk( $results ) {
	$results = is_array( $results ) ? $results : array();
	$content = 0;
	$data    = 0;
	$types   = tsosi_scan_risk_content_match_types();

	foreach ( $results as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		if ( tsosi_scan_risk_row_is_active_widget( $row ) ) {
			++$content;
			continue;
		}
		$type = isset( $row['match_type'] ) ? sanitize_key( (string) $row['match_type'] ) : '';
		if ( '' === $type && isset( $row['source_type'] ) ) {
			$type = sanitize_key( (string) $row['source_type'] );
		}
		if ( in_array( $type, $types, true ) ) {
			++$content;
		} else {
			++$data;
		}
	}

	$total = $content + $data;

	if ( 0 === $total ) {
		return array(
			'level'   => 'safe',
			'label'   => tsosi_ui_triple_text(
				'Likely safe to uninstall (no usage found in scanned content)',
				'Probablemente seguro desinstalar (sin uso en el contenido escaneado)',
				'Probablement segur desinstal·lar (sense ús al contingut escanejat)'
			),
			'help'    => tsosi_ui_triple_text(
				'Still review the database with Options Cleaner for leftover options/tables.',
				'Aun así revisa la base de datos con Options Cleaner por opciones/tablas sobrantes.',
				'Igualment revisa la base de dades amb Options Cleaner per opcions/taules sobrants.'
			),
			'total'   => 0,
			'content' => 0,
			'data'    => 0,
		);
	}

	if ( $content > 0 ) {
		return array(
			'level'   => 'danger',
			'label'   => tsosi_ui_triple_text(
				'Do not uninstall yet — shortcodes, blocks, or an active widget are still in use',
				'No desinstales aún — aún hay shortcodes, bloques o un widget activo en uso',
				'No desinstal·lis encara — encara hi ha shortcodes, blocs o un widget actiu en ús'
			),
			'help'    => sprintf(
				/* translators: 1: content matches, 2: data matches */
				tsosi_ui_triple_text(
					'%1$d content match(es), %2$d data/meta match(es). Edit or replace them first.',
					'%1$d coincidencia(s) de contenido, %2$d de datos/meta. Edítalas o sustitúyelas antes.',
					'%1$d coincidència(es) de contingut, %2$d de dades/meta. Edita-les o substitueix-les abans.'
				),
				$content,
				$data
			),
			'total'   => $total,
			'content' => $content,
			'data'    => $data,
		);
	}

	return array(
		'level'   => 'review',
		'label'   => tsosi_ui_triple_text(
			'Content looks clear — review meta/options before uninstall',
			'El contenido parece limpio — revisa meta/opciones antes de desinstalar',
			'El contingut sembla net — revisa meta/opcions abans de desinstal·lar'
		),
		'help'    => sprintf(
			/* translators: %d: data matches */
			tsosi_ui_triple_text(
				'%d meta/option/widget match(es). Use Options Cleaner after uninstall if needed.',
				'%d coincidencia(s) meta/opción/widget. Usa Options Cleaner tras desinstalar si hace falta.',
				'%d coincidència(es) meta/opció/widget. Usa Options Cleaner després de desinstal·lar si cal.'
			),
			$data
		),
		'total'   => $total,
		'content' => 0,
		'data'    => $data,
	);
}

/**
 * Whether a scan query is a theme-mode scan targeting the currently ACTIVE theme.
 * tsosi_build_theme_profile( '' ) falls back to the active theme, and the Theme tab
 * itself defaults its selector to the active theme, so an empty selection also counts.
 *
 * @param array<string,mixed> $query Scan query.
 * @return bool
 */
function tsosi_scan_query_is_active_theme( $query ) {
	if ( ! is_array( $query ) || ! isset( $query['mode'] ) || 'theme' !== sanitize_key( (string) $query['mode'] ) ) {
		return false;
	}
	$requested = isset( $query['theme'] ) ? sanitize_text_field( (string) $query['theme'] ) : '';
	if ( '' === $requested ) {
		return true;
	}
	return (string) wp_get_theme()->get_stylesheet() === $requested;
}

/**
 * Whether a scan query only checks one specific shortcode tag or block name, rather than
 * a plugin's full footprint (shortcodes + blocks + meta prefixes + option prefixes,
 * including widget id_bases -- see tsosi_build_plugin_profile()).
 *
 * @param array<string,mixed> $query Scan query.
 * @return bool
 */
function tsosi_scan_query_is_single_tag_scope( $query ) {
	if ( ! is_array( $query ) || ! isset( $query['mode'] ) ) {
		return false;
	}
	return in_array( sanitize_key( (string) $query['mode'] ), array( 'shortcode', 'block' ), true );
}

/**
 * Assess risk for a scan, overriding the uninstall-flavored assessment when the query
 * either (a) is a theme scan of the currently ACTIVE theme, or (b) only checked one
 * specific shortcode tag or block name rather than a plugin's full footprint.
 *
 * (a): "Likely safe to uninstall" / "Do not uninstall yet — ... before uninstall" framing
 * is meaningless for a theme that can't be removed without switching away from it first,
 * and a lone theme_mods_{stylesheet} match there is not a leftover to clean up -- it is
 * simply what WordPress always stores for whichever theme is currently active. A genuine
 * content match (shortcode/block) is still worth flagging even for the active theme, so
 * only a data-only result is overridden.
 *
 * (b): the Shortcode/Block tab needles are exactly one literal tag/name (see
 * tsosi_scan_resolve_needles()) -- no option_prefixes, so tsosi_scan_active_widget_instances()
 * and the non-autoload-options scan never run. A "no usage found" result there only means
 * that one tag/name wasn't found in content; it says nothing about the plugin's widgets,
 * options, or meta, and can look safe to uninstall while e.g. an active widget from the
 * same plugin is plainly in use (the shortcode tab merely lists tags the plugin's code
 * COULD register -- see the tab's own "may be inactive"/"registered = works right now"
 * help text -- not what the site actually uses them for). Without this override, a
 * single-tag scan reused the plugin-wide "Likely safe to uninstall" wording, which
 * overstates what was actually checked.
 *
 * @param array<int,array<string,mixed>> $results Scan rows.
 * @param array<string,mixed>            $query   Scan query.
 * @return array{level:string,label:string,help:string,total:int,content:int,data:int}
 */
function tsosi_scan_assess_query_aware_risk( $results, $query ) {
	$risk = tsosi_scan_assess_risk( $results );

	if ( tsosi_scan_query_is_active_theme( $query ) && 0 === $risk['content'] ) {
		return array(
			'level'   => 'info',
			'label'   => tsosi_ui_triple_text(
				'This is your active theme',
				'Este es tu tema activo',
				'Aquest és el teu tema actiu'
			),
			'help'    => tsosi_ui_triple_text(
				'Matches like its own theme_mods entry are expected while a theme is active and are not something to clean up. This scan is more useful on an inactive theme you\'re considering removing.',
				'Coincidencias como su propia entrada theme_mods son normales mientras el tema está activo y no hay que limpiarlas. Este escaneo es más útil en un tema inactivo que estés valorando eliminar.',
				'Coincidències com la seva pròpia entrada theme_mods són normals mentre el tema és actiu i no cal netejar-les. Aquest escaneig és més útil en un tema inactiu que estiguis valorant eliminar.'
			),
			'total'   => (int) $risk['total'],
			'content' => (int) $risk['content'],
			'data'    => (int) $risk['data'],
		);
	}

	if ( tsosi_scan_query_is_single_tag_scope( $query ) && 0 === $risk['total'] ) {
		return array(
			'level'   => 'info',
			'label'   => tsosi_ui_triple_text(
				'This tag was not found — but this only checks this one tag',
				'No se encontró esta etiqueta — pero esto solo comprueba esta etiqueta',
				'No s\'ha trobat aquesta etiqueta — però això només comprova aquesta etiqueta'
			),
			'help'    => tsosi_ui_triple_text(
				'This does not check the plugin\'s widgets, options, or database footprint. Use the "By plugin" tab for a full check before uninstalling.',
				'Esto no comprueba los widgets, opciones ni huella en la base de datos del plugin. Usa la pestaña "Por plugin" para una comprobación completa antes de desinstalar.',
				'Això no comprova els widgets, opcions ni empremta a la base de dades del plugin. Usa la pestanya "Per plugin" per a una comprovació completa abans de desinstal·lar.'
			),
			'total'   => 0,
			'content' => 0,
			'data'    => 0,
		);
	}

	return $risk;
}

/**
 * Load the current site content index if fingerprint still matches.
 *
 * @return array<string,mixed>|null Index payload or null.
 */
function tsosi_audit_get_ready_index() {
	return tsosi_scan_ensure_content_index();
}

/**
 * Build needles for a plugin file from its profile (same fallback as scanner).
 *
 * @param string              $plugin_file Plugin basename.
 * @param array<string,mixed> $profile     Optional profile.
 * @return array<string,mixed>
 */
function tsosi_audit_needles_for_plugin( $plugin_file, $profile = null ) {
	if ( ! is_array( $profile ) ) {
		$profile = tsosi_build_plugin_profile( $plugin_file );
	}
	$needles = array(
		'shortcodes'      => isset( $profile['shortcodes'] ) ? (array) $profile['shortcodes'] : array(),
		'blocks'          => isset( $profile['blocks'] ) ? (array) $profile['blocks'] : array(),
		'meta_keys'       => array(),
		'meta_prefixes'   => isset( $profile['meta_prefixes'] ) ? (array) $profile['meta_prefixes'] : array(),
		'option_prefixes' => isset( $profile['option_prefixes'] ) ? (array) $profile['option_prefixes'] : array(),
	);
	if ( tsosi_scan_needles_are_empty( $needles ) ) {
		$folder = tsosi_get_plugin_folder_raw( $plugin_file );
		if ( '' !== $folder && strlen( $folder ) >= 3 ) {
			$slug                         = sanitize_key( str_replace( '-', '_', $folder ) );
			$needles['meta_prefixes'][]   = $slug;
			$needles['option_prefixes'][] = $slug;
		}
	}
	$needles['meta_prefixes']   = tsosi_refine_prefix_list( $needles['meta_prefixes'] );
	$needles['option_prefixes'] = tsosi_refine_prefix_list( $needles['option_prefixes'] );
	return $needles;
}

/**
 * Audit inactive plugins against the cached site index (fast, no rebuild).
 *
 * @return array{rows:array<int,array<string,mixed>>,index_ready:bool}|WP_Error
 */
function tsosi_audit_inactive_plugins() {
	if ( ! tsosi_scan_content_cache_storage_ready() ) {
		return tsosi_scan_index_unavailable_error();
	}

	$index = tsosi_audit_get_ready_index();
	if ( null === $index ) {
		return tsosi_scan_need_index_error();
	}

	$plugins  = tsosi_get_installed_plugins();
	$profiles = tsosi_get_all_plugin_profiles();
	$rows     = array();
	$count    = 0;

	foreach ( $plugins as $file => $header ) {
		if ( tsosi_is_plugin_active_file( $file ) ) {
			continue;
		}
		++$count;
		if ( $count > 60 ) {
			break;
		}

		$profile = isset( $profiles[ $file ] ) && is_array( $profiles[ $file ] )
			? $profiles[ $file ]
			: tsosi_build_plugin_profile( $file );
		$name    = isset( $profile['name'] ) ? (string) $profile['name'] : (string) $file;
		$needles = tsosi_audit_needles_for_plugin( $file, $profile );
		$empty   = tsosi_scan_needles_are_empty( $needles );

		if ( $empty ) {
			$risk        = array(
				'level'   => 'safe',
				'label'   => tsosi_ui_triple_text( 'No signatures detected', 'Sin firmas detectadas', 'Sense signatures detectades' ),
				'help'    => '',
				'total'   => 0,
				'content' => 0,
				'data'    => 0,
			);
			$match_count = 0;
		} else {
			$results     = tsosi_scan_filter_ignored_results(
				tsosi_scan_filter_cross_plugin_false_positives(
					tsosi_scan_match_cached_index( $index, $needles ),
					array(
						'mode'        => 'plugin',
						'plugin_file' => $file,
					)
				)
			);
			$risk        = tsosi_scan_assess_risk( $results );
			$match_count = (int) $risk['total'];
		}

		$rows[] = array(
			'plugin_file'   => $file,
			'name'          => $name,
			'match_count'   => $match_count,
			'content'       => (int) $risk['content'],
			'data'          => (int) $risk['data'],
			'risk_level'    => (string) $risk['level'],
			'risk_label'    => (string) $risk['label'],
			'needles_empty' => $empty,
			'scan_url'      => add_query_arg(
				array(
					'page'        => 'tso-stack-inspector',
					'tab'         => 'plugin',
					'plugin_file' => $file,
				),
				admin_url( 'tools.php' )
			),
		);
	}

	usort(
		$rows,
		function ( $a, $b ) {
			$order = array(
				'danger' => 0,
				'review' => 1,
				'safe'   => 2,
			);
			$la    = isset( $order[ $a['risk_level'] ] ) ? $order[ $a['risk_level'] ] : 9;
			$lb    = isset( $order[ $b['risk_level'] ] ) ? $order[ $b['risk_level'] ] : 9;
			if ( $la !== $lb ) {
				return $la - $lb;
			}
			return (int) $b['match_count'] - (int) $a['match_count'];
		}
	);

	return array(
		'rows'        => $rows,
		'index_ready' => true,
		'capped'      => $count > 60,
	);
}
