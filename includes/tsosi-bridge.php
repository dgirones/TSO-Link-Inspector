<?php
/**
 * Deep links and helpers for TSO Options & Tables Cleaner integration.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOSI_OPTIONS_CLEANER_BASENAME' ) ) {
	define( 'TSOSI_OPTIONS_CLEANER_BASENAME', 'tso-options-tables-cleaner/tso-options-tables-cleaner.php' );
}
if ( ! defined( 'TSOSI_OPTIONS_CLEANER_SLUG' ) ) {
	define( 'TSOSI_OPTIONS_CLEANER_SLUG', 'tso-options-tables-cleaner' );
}

/**
 * Whether Options Cleaner is available.
 *
 * @return bool
 */
function tsosi_options_cleaner_is_available() {
	return '' !== tsosi_get_options_cleaner_url();
}

/**
 * Call-to-action for the Options Cleaner promo, covering all three states: active (deep
 * link into its own screen), installed but inactive (an activate link), or not installed
 * at all (a WordPress.org install-search link) -- gated by the current user's own
 * activate_plugins/install_plugins capability so the link is always usable, never a dead
 * end for someone who can't act on it.
 *
 * @return array{state:string,url:string,label:string}|null Null when there's nothing this
 *         user can do about it right now (not installed and can't install plugins).
 */
function tsosi_get_options_cleaner_cta() {
	if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active( TSOSI_OPTIONS_CLEANER_BASENAME ) ) {
		return array(
			'state' => 'active',
			'url'   => admin_url( 'tools.php?page=tso-options-tables-cleaner' ),
			'label' => tsosi_ui_triple_text(
				'After uninstalling, clean leftover options and tables with TSO Options & Tables Cleaner.',
				'Tras desinstalar, limpia opciones y tablas sobrantes con TSO Options & Tables Cleaner.',
				'Després de desinstal·lar, neteja opcions i taules sobrants amb TSO Options & Tables Cleaner.'
			),
		);
	}

	$installed = get_plugins();
	if ( isset( $installed[ TSOSI_OPTIONS_CLEANER_BASENAME ] ) ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return null;
		}
		return array(
			'state' => 'inactive',
			'url'   => wp_nonce_url(
				admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( TSOSI_OPTIONS_CLEANER_BASENAME ) ),
				'activate-plugin_' . TSOSI_OPTIONS_CLEANER_BASENAME
			),
			'label' => tsosi_ui_triple_text(
				'Activate TSO Options & Tables Cleaner to clean leftover options and tables after uninstalling.',
				'Activa TSO Options & Tables Cleaner para limpiar opciones y tablas sobrantes tras desinstalar.',
				'Activa TSO Options & Tables Cleaner per netejar opcions i taules sobrants després de desinstal·lar.'
			),
		);
	}

	if ( ! current_user_can( 'install_plugins' ) ) {
		return null;
	}
	return array(
		'state' => 'not_installed',
		'url'   => admin_url( 'plugin-install.php?s=' . rawurlencode( TSOSI_OPTIONS_CLEANER_SLUG ) . '&tab=search&type=term' ),
		'label' => tsosi_ui_triple_text(
			'Install TSO Options & Tables Cleaner to clean leftover options and tables after uninstalling.',
			'Instala TSO Options & Tables Cleaner para limpiar opciones y tablas sobrantes tras desinstalar.',
			'Instal·la TSO Options & Tables Cleaner per netejar opcions i taules sobrants després de desinstal·lar.'
		),
	);
}

/**
 * Admin URL for Options Cleaner with optional plugin / prefix context.
 *
 * @param string          $plugin_file Optional plugin basename.
 * @param string|string[] $prefixes    Optional option prefixes to hint.
 * @return string
 */
function tsosi_get_options_cleaner_scan_url( $plugin_file = '', $prefixes = array() ) {
	$base = tsosi_get_options_cleaner_url();
	if ( '' === $base ) {
		return '';
	}

	$args = array(
		'tsosi_from' => 'stack-inspector',
	);

	$plugin_file = tsosi_sanitize_plugin_file( (string) $plugin_file );
	if ( '' !== $plugin_file ) {
		$args['tsosi_plugin'] = rawurlencode( $plugin_file );
		$folder               = tsosi_get_plugin_folder_raw( $plugin_file );
		if ( '' !== $folder ) {
			$args['tsosi_prefix'] = sanitize_key( str_replace( '-', '_', $folder ) );
		}
	}

	if ( ! is_array( $prefixes ) ) {
		$prefixes = array( $prefixes );
	}
	$clean_prefixes = array();
	foreach ( $prefixes as $prefix ) {
		$prefix = function_exists( 'tsosi_sanitize_option_prefix' )
			? tsosi_sanitize_option_prefix( (string) $prefix )
			: sanitize_key( str_replace( '-', '_', (string) $prefix ) );
		if ( strlen( $prefix ) >= 3 ) {
			$clean_prefixes[] = $prefix;
		}
	}
	$clean_prefixes = array_values( array_unique( $clean_prefixes ) );
	if ( ! empty( $clean_prefixes ) ) {
		$args['tsosi_prefixes'] = implode( ',', array_slice( $clean_prefixes, 0, 8 ) );
		if ( empty( $args['tsosi_prefix'] ) ) {
			$args['tsosi_prefix'] = $clean_prefixes[0];
		}
	}

	return add_query_arg( $args, $base );
}

/**
 * Build Options Cleaner deep link from scan needles + optional plugin.
 *
 * @param array<string,mixed> $needles     Needles.
 * @param string              $plugin_file Plugin file.
 * @return string
 */
function tsosi_get_options_cleaner_url_from_needles( $needles, $plugin_file = '' ) {
	$prefixes = array();
	if ( is_array( $needles ) && ! empty( $needles['option_prefixes'] ) && is_array( $needles['option_prefixes'] ) ) {
		$prefixes = $needles['option_prefixes'];
	}
	return tsosi_get_options_cleaner_scan_url( $plugin_file, $prefixes );
}

/**
 * Stack Inspector URL with plugin preselected.
 *
 * @param string $plugin_file Plugin basename.
 * @return string
 */
function tsosi_get_inspector_plugin_url( $plugin_file = '' ) {
	$url         = tsosi_admin_page_url();
	$plugin_file = tsosi_sanitize_plugin_file( (string) $plugin_file );
	if ( '' === $plugin_file ) {
		return $url;
	}
	return add_query_arg(
		array(
			'tab'         => 'plugin',
			'plugin_file' => rawurlencode( $plugin_file ),
		),
		$url
	);
}
