<?php
/**
 * UI language helpers for TSO Stack Inspector.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supported admin UI languages.
 *
 * @return string[]
 */
function tsosi_get_supported_ui_langs() {
	return array( 'en', 'es', 'ca' );
}

/**
 * Detect a supported UI language from the current user's own WordPress language
 * setting (falls back to the site locale for logged-out/CLI contexts). Used as the
 * "Automatic" default: no explicit choice needed, and it follows the user if they
 * later change their WordPress profile language.
 *
 * @return string en|es|ca
 */
function tsosi_resolve_auto_ui_lang() {
	$locale = get_user_locale();
	if ( '' === $locale ) {
		$locale = get_locale();
	}
	$primary = sanitize_key( strtolower( (string) strtok( (string) $locale, '_-' ) ) );
	if ( in_array( $primary, tsosi_get_supported_ui_langs(), true ) ) {
		return $primary;
	}
	return 'en';
}

/**
 * The user's explicitly pinned UI language, if any (ignores auto-detection).
 *
 * @return string en|es|ca, or '' when the user has no pin (following "Automatic").
 */
function tsosi_get_ui_lang_pin() {
	$uid  = get_current_user_id();
	$lang = $uid ? get_user_meta( $uid, TSOSI_USER_META_UI_LANG, true ) : '';
	$lang = sanitize_key( (string) $lang );
	return in_array( $lang, tsosi_get_supported_ui_langs(), true ) ? $lang : '';
}

/**
 * Current admin UI language for the logged-in user: their explicit pin (en/es/ca) if
 * they set one, otherwise auto-detected from their WordPress language (see
 * tsosi_resolve_auto_ui_lang()).
 *
 * @return string en|es|ca
 */
function tsosi_get_ui_lang() {
	$uid  = get_current_user_id();
	$lang = $uid ? get_user_meta( $uid, TSOSI_USER_META_UI_LANG, true ) : '';
	$lang = sanitize_key( (string) $lang );
	if ( in_array( $lang, tsosi_get_supported_ui_langs(), true ) ) {
		return $lang;
	}
	// Empty (never chosen) or explicit 'auto': detect from the user's WordPress language.
	return tsosi_resolve_auto_ui_lang();
}

/**
 * Persist UI language preference.
 *
 * @param string $lang en|es|ca to pin a language, or 'auto' to follow the user's
 *                      WordPress language automatically.
 * @return void
 */
function tsosi_set_ui_lang( $lang ) {
	$lang = sanitize_key( (string) $lang );
	if ( 'auto' !== $lang && ! in_array( $lang, tsosi_get_supported_ui_langs(), true ) ) {
		return;
	}
	$uid = get_current_user_id();
	if ( ! $uid ) {
		return;
	}
	if ( 'auto' === $lang ) {
		delete_user_meta( $uid, TSOSI_USER_META_UI_LANG );
		return;
	}
	update_user_meta( $uid, TSOSI_USER_META_UI_LANG, $lang );
}

/**
 * Load bundled `.mo` catalogs (and prefer WP language packs when present).
 *
 * Uses load_textdomain() only — never load_plugin_textdomain(). Admin Tools UI
 * still uses tsosi_ui_triple_text() for hard-coded EN/ES/CA strings.
 *
 * @return void
 */
function tsosi_load_textdomain() {
	static $did_load = false;
	if ( $did_load ) {
		return;
	}

	$domain     = 'tso-stack-inspector';
	$locale     = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	$candidates = array( (string) $locale );

	if ( false !== strpos( (string) $locale, '_' ) ) {
		$candidates[] = substr( (string) $locale, 0, strpos( (string) $locale, '_' ) );
	}
	if ( 0 === strpos( (string) $locale, 'es' ) ) {
		$candidates[] = 'es_ES';
	}
	if ( 0 === strpos( (string) $locale, 'ca' ) ) {
		$candidates[] = 'ca';
	}

	foreach ( array_unique( array_filter( $candidates ) ) as $candidate ) {
		$mofile = WP_LANG_DIR . '/plugins/' . $domain . '-' . $candidate . '.mo';
		if ( file_exists( $mofile ) ) {
			load_textdomain( $domain, $mofile );
			$did_load = true;
			return;
		}
		$mofile = TSOSI_PATH . 'languages/' . $domain . '-' . $candidate . '.mo';
		if ( file_exists( $mofile ) ) {
			load_textdomain( $domain, $mofile );
			$did_load = true;
			return;
		}
	}

	$did_load = true;
}
add_action( 'init', 'tsosi_load_textdomain' );

/**
 * Return one of three hard-coded UI strings when MO is missing.
 *
 * @param string $en English.
 * @param string $es Spanish.
 * @param string $ca Catalan.
 * @return string
 */
function tsosi_ui_triple_text( $en, $es, $ca ) {
	$lang = tsosi_get_ui_lang();
	if ( 'es' === $lang ) {
		return $es;
	}
	if ( 'ca' === $lang ) {
		return $ca;
	}
	return $en;
}
