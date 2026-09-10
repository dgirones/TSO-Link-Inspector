<?php
/**
 * WP-CLI commands for TSO Stack Inspector.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Scan site content from the command line.
 */
class TSOSI_CLI_Scan_Command {

	/**
	 * Scan for plugin usage.
	 *
	 * ## OPTIONS
	 *
	 * [--plugin=<file>]
	 * : Plugin basename (e.g. contact-form-7/wp-contact-form-7.php)
	 *
	 * [--shortcode=<tag>]
	 * : Shortcode tag without brackets
	 *
	 * [--block=<name>]
	 * : Block name namespace/block
	 *
	 * [--theme[=<stylesheet>]]
	 * : Scan theme signatures. Pass a stylesheet slug to scan a specific (including inactive)
	 * theme, e.g. --theme=twentytwentyfour; bare --theme scans the active theme.
	 *
	 * [--format=<format>]
	 * : table, json, csv, count
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$query = array( 'mode' => 'plugin' );
		if ( ! empty( $assoc_args['shortcode'] ) ) {
			$query = array(
				'mode'      => 'shortcode',
				'shortcode' => tsosi_sanitize_shortcode_tag( (string) $assoc_args['shortcode'] ),
			);
		} elseif ( ! empty( $assoc_args['block'] ) ) {
			$query = array(
				'mode'  => 'block',
				'block' => tsosi_sanitize_block_name( (string) $assoc_args['block'] ),
			);
		} elseif ( isset( $assoc_args['theme'] ) ) {
			$stylesheet = is_string( $assoc_args['theme'] ) ? $assoc_args['theme'] : '';
			if ( '' === $stylesheet ) {
				$stylesheet = (string) wp_get_theme()->get_stylesheet();
			}
			$query = array(
				'mode'  => 'theme',
				'theme' => tsosi_sanitize_theme_stylesheet( $stylesheet ),
			);
		} elseif ( ! empty( $assoc_args['plugin'] ) ) {
			$query = array(
				'mode'        => 'plugin',
				'plugin_file' => tsosi_sanitize_plugin_file( (string) $assoc_args['plugin'] ),
			);
		} else {
			WP_CLI::error( 'Provide --plugin, --shortcode, --block, or --theme.' );
		}

		$start = tsosi_scan_job_start( $query );
		if ( is_wp_error( $start ) ) {
			WP_CLI::error( $start->get_error_message() );
		}
		if ( ! empty( $start['done'] ) ) {
			$results = isset( $start['results'] ) && is_array( $start['results'] ) ? $start['results'] : array();
			$this->output_results( $results, $assoc_args );
			return;
		}

		$guard = 0;
		do {
			$step = tsosi_scan_job_step();
			if ( is_wp_error( $step ) ) {
				WP_CLI::error( $step->get_error_message() );
			}
			if ( ! empty( $step['done'] ) ) {
				$results = isset( $step['results'] ) && is_array( $step['results'] ) ? $step['results'] : array();
				$this->output_results( $results, $assoc_args );
				return;
			}
			++$guard;
		} while ( $guard < 5000 );

		WP_CLI::error( 'Scan did not finish in time.' );
	}

	/**
	 * @param array<int,array<string,mixed>> $results Results.
	 * @param array<string,string>           $assoc_args Flags.
	 * @return void
	 */
	private function output_results( $results, $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
		if ( 'count' === $format ) {
			WP_CLI::log( (string) count( $results ) );
			return;
		}

		$rows = array();
		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'location' => isset( $row['object_label'] ) ? (string) $row['object_label'] : '',
				'type'     => isset( $row['match_type'] ) ? (string) $row['match_type'] : '',
				'match'    => isset( $row['match_value'] ) ? (string) $row['match_value'] : '',
				'context'  => isset( $row['context'] ) ? (string) $row['context'] : '',
				'edit'     => isset( $row['edit_url'] ) ? (string) $row['edit_url'] : '',
			);
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $rows ) );
			return;
		}

		if ( 'csv' === $format ) {
			$headers = array( 'location', 'type', 'match', 'context', 'edit' );
			WP_CLI::log( implode( ',', $headers ) );
			foreach ( $rows as $row ) {
				WP_CLI::log( implode( ',', array_map( 'tsosi_cli_csv_escape', $row ) ) );
			}
			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'location', 'type', 'match', 'context', 'edit' ) );
	}
}

/**
 * @param string $value Cell value.
 * @return string
 */
function tsosi_cli_csv_escape( $value ) {
	$value = (string) $value;
	if ( preg_match( '/[",\n\r]/', $value ) ) {
		return '"' . str_replace( '"', '""', $value ) . '"';
	}
	return $value;
}

WP_CLI::add_command( 'tsosi scan', 'TSOSI_CLI_Scan_Command' );

/**
 * Build the site index in CLI (batched).
 *
 * @return void
 */
function tsosi_cli_ensure_index() {
	$start = tsosi_index_job_start( false );
	if ( is_wp_error( $start ) ) {
		WP_CLI::error( $start->get_error_message() );
	}
	if ( ! empty( $start['done'] ) ) {
		return;
	}

	$guard = 0;
	do {
		$step = tsosi_index_job_step();
		if ( is_wp_error( $step ) ) {
			WP_CLI::error( $step->get_error_message() );
		}
		if ( ! empty( $step['done'] ) ) {
			return;
		}
		++$guard;
	} while ( $guard < 5000 );

	WP_CLI::error( 'Index build did not finish in time.' );
}

/**
 * Find orphan shortcodes and blocks.
 *
 * ## OPTIONS
 *
 * [--format=<format>]
 * : table, json, csv, count
 * ---
 * default: table
 * ---
 */
class TSOSI_CLI_Orphans_Command {

	/**
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );
		tsosi_cli_ensure_index();
		$result = tsosi_orphans_find();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$rows = array();
		foreach ( (array) ( $result['shortcodes'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'type'  => 'shortcode',
				'tag'   => isset( $row['tag'] ) ? (string) $row['tag'] : '',
				'count' => isset( $row['count'] ) ? (int) $row['count'] : 0,
			);
		}
		foreach ( (array) ( $result['blocks'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'type'  => 'block',
				'tag'   => isset( $row['name'] ) ? (string) $row['name'] : '',
				'count' => isset( $row['count'] ) ? (int) $row['count'] : 0,
			);
		}

		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
		if ( 'count' === $format ) {
			WP_CLI::log( (string) count( $rows ) );
			return;
		}
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $rows ) );
			return;
		}
		if ( 'csv' === $format ) {
			WP_CLI::log( 'type,tag,count' );
			foreach ( $rows as $row ) {
				WP_CLI::log( implode( ',', array_map( 'tsosi_cli_csv_escape', $row ) ) );
			}
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'type', 'tag', 'count' ) );
	}
}

/**
 * Audit inactive plugins against the site index.
 *
 * ## OPTIONS
 *
 * [--format=<format>]
 * : table, json, csv, count
 * ---
 * default: table
 * ---
 */
class TSOSI_CLI_Audit_Inactive_Command {

	/**
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );
		tsosi_cli_ensure_index();
		$result = tsosi_audit_inactive_plugins();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$rows = array();
		foreach ( (array) ( $result['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'plugin'  => isset( $row['name'] ) ? (string) $row['name'] : '',
				'file'    => isset( $row['plugin_file'] ) ? (string) $row['plugin_file'] : '',
				'matches' => isset( $row['match_count'] ) ? (int) $row['match_count'] : 0,
				'content' => isset( $row['content'] ) ? (int) $row['content'] : 0,
				'data'    => isset( $row['data'] ) ? (int) $row['data'] : 0,
				'risk'    => isset( $row['risk_level'] ) ? (string) $row['risk_level'] : '',
			);
		}

		if ( ! empty( $result['capped'] ) ) {
			WP_CLI::warning( 'Only the first 60 inactive plugins were audited; some were not checked.' );
		}

		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
		if ( 'count' === $format ) {
			WP_CLI::log( (string) count( $rows ) );
			return;
		}
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $rows ) );
			return;
		}
		if ( 'csv' === $format ) {
			WP_CLI::log( 'plugin,file,matches,content,data,risk' );
			foreach ( $rows as $row ) {
				WP_CLI::log( implode( ',', array_map( 'tsosi_cli_csv_escape', $row ) ) );
			}
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'plugin', 'file', 'matches', 'content', 'data', 'risk' ) );
	}
}

WP_CLI::add_command( 'tsosi orphans', 'TSOSI_CLI_Orphans_Command' );
WP_CLI::add_command( 'tsosi audit-inactive', 'TSOSI_CLI_Audit_Inactive_Command' );
