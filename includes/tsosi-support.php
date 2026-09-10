<?php
/**
 * Blog, donate links, and localized Plugins screen text (WP site locale).
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TSO blog URL.
 *
 * @return string
 */
function tsosi_get_blog_url() {
	$default = 'https://www.tusoporteonline.es/blog';

	/**
	 * Filter the TSO blog URL shown on the Plugins screen.
	 *
	 * @param string $url Blog URL.
	 */
	return (string) apply_filters( 'tsosi_blog_url', $default );
}

/**
 * Ko-fi donation URL.
 *
 * @return string
 */
function tsosi_get_kofi_donate_url() {
	$default = 'https://ko-fi.com/deadko_cat';

	/**
	 * Filter the Ko-fi donation URL for TSO Stack Inspector.
	 *
	 * @param string $url Donation page URL.
	 */
	return (string) apply_filters( 'tsosi_kofi_donate_url', $default );
}

/**
 * Whether a locale string starts with a language code.
 *
 * @param string $locale Full locale (e.g. es_ES).
 * @param string $code   Language code (e.g. es).
 * @return bool
 */
function tsosi_locale_starts_with( $locale, $code ) {
	$locale = strtolower( (string) $locale );
	$code   = strtolower( (string) $code );
	return $code === $locale || 0 === strpos( $locale, $code . '_' ) || 0 === strpos( $locale, $code . '-' );
}

/**
 * Use .mo translation when loaded; otherwise ca/es string fallbacks for WP locale.
 *
 * @param string $english    English msgid.
 * @param string $translated Result of __() with the same literal msgid.
 * @param string $ca         Catalan fallback.
 * @param string $es         Spanish fallback.
 * @return string
 */
function tsosi_gettext_with_locale_fallback( $english, $translated, $ca, $es ) {
	if ( $translated !== $english ) {
		return $translated;
	}

	$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	if ( tsosi_locale_starts_with( $locale, 'ca' ) ) {
		return $ca;
	}
	if ( tsosi_locale_starts_with( $locale, 'es' ) ) {
		return $es;
	}

	return $english;
}

/**
 * Localized description for the Plugins list.
 *
 * @return string
 */
function tsosi_get_plugin_list_description() {
	$english    = 'Find where plugin shortcodes, blocks, and metadata are used before you deactivate or uninstall.';
	$translated = __( 'Find where plugin shortcodes, blocks, and metadata are used before you deactivate or uninstall.', 'tso-stack-inspector' );

	return tsosi_gettext_with_locale_fallback(
		$english,
		$translated,
		'Troba on s\'utilitzen shortcodes, blocs i metadades de plugins abans de desactivar o desinstal·lar.',
		'Encuentra dónde se usan shortcodes, bloques y metadatos de plugins antes de desactivar o desinstalar.'
	);
}

/**
 * @return string
 */
function tsosi_get_open_inspector_link_label() {
	$english    = 'Open inspector';
	$translated = __( 'Open inspector', 'tso-stack-inspector' );

	return tsosi_gettext_with_locale_fallback(
		$english,
		$translated,
		'Obrir inspector',
		'Abrir inspector'
	);
}

/**
 * @return string
 */
function tsosi_get_blog_link_label() {
	$english    = 'Blog';
	$translated = __( 'Blog', 'tso-stack-inspector' );

	return tsosi_gettext_with_locale_fallback( $english, $translated, 'Blog', 'Blog' );
}

/**
 * @return string
 */
function tsosi_get_donate_link_label() {
	$english    = 'Donate';
	$translated = __( 'Donate', 'tso-stack-inspector' );

	return tsosi_gettext_with_locale_fallback( $english, $translated, 'Donar', 'Donar' );
}

/**
 * Replace plugin header description with the translated string.
 *
 * @param array<string, array<string, string>> $plugins Plugins list.
 * @return array<string, array<string, string>>
 */
function tsosi_filter_plugin_list_description( $plugins ) {
	if ( ! is_array( $plugins ) || ! defined( 'TSOSI_FILE' ) ) {
		return $plugins;
	}
	$basename = plugin_basename( TSOSI_FILE );
	if ( isset( $plugins[ $basename ] ) ) {
		$plugins[ $basename ]['Description'] = tsosi_get_plugin_list_description();
	}
	return $plugins;
}
add_filter( 'all_plugins', 'tsosi_filter_plugin_list_description' );

/**
 * Blog and Donate links on the Plugins screen (under the description).
 *
 * @param string[] $links Plugin row meta links.
 * @param string   $file  Plugin basename.
 * @return string[]
 */
function tsosi_filter_plugin_row_meta( $links, $file ) {
	if ( ! defined( 'TSOSI_FILE' ) || plugin_basename( TSOSI_FILE ) !== $file ) {
		return $links;
	}

	$links[] = sprintf(
		'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
		esc_url( tsosi_get_blog_url() ),
		esc_html( tsosi_get_blog_link_label() )
	);
	$links[] = sprintf(
		'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
		esc_url( tsosi_get_kofi_donate_url() ),
		esc_html( tsosi_get_donate_link_label() )
	);

	return $links;
}
add_filter( 'plugin_row_meta', 'tsosi_filter_plugin_row_meta', 10, 2 );
