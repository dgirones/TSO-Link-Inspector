<?php
/**
 * Multisite: network admin links to per-site inspector screens.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register network admin menu when multisite is enabled.
 *
 * @return void
 */
function tsosi_multisite_register_menu() {
	if ( ! is_multisite() || ! is_network_admin() ) {
		return;
	}
	add_submenu_page(
		'settings.php',
		__( 'TSO Stack Inspector', 'tso-stack-inspector' ),
		__( 'TSO Stack Inspector', 'tso-stack-inspector' ),
		'manage_network_options',
		'tso-stack-inspector-network',
		'tsosi_multisite_render_page'
	);
}
add_action( 'network_admin_menu', 'tsosi_multisite_register_menu' );

/**
 * @return void
 */
function tsosi_multisite_render_page() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return;
	}

	$per_page = 100;
	$paged    = isset( $_GET['tsosi_paged'] ) ? max( 1, absint( wp_unslash( $_GET['tsosi_paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination offset, no state change.
	$search   = isset( $_GET['tsosi_site_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tsosi_site_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search filter, no state change.

	$query_args = array(
		'number'  => $per_page,
		'offset'  => ( $paged - 1 ) * $per_page,
		'orderby' => 'domain',
	);
	if ( '' !== $search ) {
		$query_args['search']         = '*' . $search . '*';
		$query_args['search_columns'] = array( 'domain', 'path' );
	}

	$total_args           = $query_args;
	$total_args['count']  = true;
	$total_args['number'] = 0;
	unset( $total_args['offset'] );
	$total_sites = (int) get_sites( $total_args );
	$total_pages = max( 1, (int) ceil( $total_sites / $per_page ) );
	if ( $paged > $total_pages ) {
		$paged                = $total_pages;
		$query_args['offset'] = ( $paged - 1 ) * $per_page;
	}

	$sites = get_sites( $query_args );

	echo '<div class="wrap">';
	echo '<h1>' . esc_html(
		tsosi_ui_triple_text(
			'TSO Stack Inspector — Multisite',
			'TSO Stack Inspector — Multisitio',
			'TSO Stack Inspector — Multilloc'
		)
	) . '</h1>';
	echo '<p>' . esc_html(
		tsosi_ui_triple_text(
			'Scans run on each site separately. Open the inspector on the site you want to check.',
			'Los escaneos se ejecutan en cada sitio por separado. Abre el inspector en el sitio que quieras revisar.',
			'L\'escaneig s\'executa a cada lloc per separat. Obre l\'inspector al lloc que vulguis revisar.'
		)
	) . '</p>';

	$base_url = network_admin_url( 'settings.php?page=tso-stack-inspector-network' );

	echo '<form method="get" action="' . esc_url( network_admin_url( 'settings.php' ) ) . '" style="margin-bottom:1em;">';
	echo '<input type="hidden" name="page" value="tso-stack-inspector-network" />';
	echo '<input type="search" name="tsosi_site_search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( tsosi_ui_triple_text( 'Search by domain/path…', 'Buscar por dominio/ruta…', 'Cerca per domini/ruta…' ) ) . '" /> ';
	echo '<button type="submit" class="button">' . esc_html( tsosi_ui_triple_text( 'Search', 'Buscar', 'Cerca' ) ) . '</button>';
	if ( '' !== $search ) {
		echo ' <a href="' . esc_url( $base_url ) . '">' . esc_html( tsosi_ui_triple_text( 'Clear', 'Limpiar', 'Netejar' ) ) . '</a>';
	}
	echo '</form>';

	echo '<p class="description">' . esc_html(
		sprintf(
			/* translators: 1: current page, 2: total pages, 3: total sites */
			tsosi_ui_triple_text(
				'Page %1$d of %2$d — %3$d site(s) total.',
				'Página %1$d de %2$d — %3$d sitio(s) en total.',
				'Pàgina %1$d de %2$d — %3$d lloc(s) en total.'
			),
			$paged,
			$total_pages,
			$total_sites
		)
	) . '</p>';

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Site', 'Sitio', 'Lloc' ) ) . '</th>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Inspector', 'Inspector', 'Inspector' ) ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $sites as $site ) {
		if ( ! $site instanceof WP_Site ) {
			continue;
		}
		$url = get_admin_url( (int) $site->blog_id, 'tools.php?page=tso-stack-inspector' );
		echo '<tr>';
		$details = get_blog_details( (int) $site->blog_id );
		$name    = ( $details && ! empty( $details->blogname ) ) ? (string) $details->blogname : (string) $site->domain;
		echo '<td>' . esc_html( $name ) . ' <code>' . esc_html( (string) $site->domain . $site->path ) . '</code></td>';
		echo '<td><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html( tsosi_ui_triple_text( 'Open', 'Abrir', 'Obrir' ) ) . '</a></td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	if ( $total_pages > 1 ) {
		echo '<p class="tablenav-pages" style="margin-top:1em;">';
		for ( $i = 1; $i <= $total_pages; $i++ ) {
			$page_url = add_query_arg(
				array(
					'tsosi_paged'       => $i,
					'tsosi_site_search' => $search,
				),
				$base_url
			);
			if ( $i === $paged ) {
				echo '<strong style="margin-right:6px;">' . (int) $i . '</strong>';
			} else {
				echo '<a href="' . esc_url( $page_url ) . '" style="margin-right:6px;">' . (int) $i . '</a>';
			}
		}
		echo '</p>';
	}

	echo '</div>';
}
