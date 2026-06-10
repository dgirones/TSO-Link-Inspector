<?php
/**
 * Support / donation helpers (TSO brand).
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.9.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Support
 */
class TSOLIIN_Support {

	/**
	 * Ko-fi donation URL shown in the admin UI.
	 *
	 * @return string
	 */
	public static function get_kofi_donate_url() {
		$default = 'https://ko-fi.com/deadko_cat';

		/**
		 * Filter the Ko-fi donation URL for TSO Link Inspector.
		 *
		 * @param string $url Donation page URL.
		 */
		return (string) apply_filters( 'tsoliin_kofi_donate_url', $default );
	}

	/**
	 * Donate button label (translated via plugin text domain).
	 *
	 * @return string
	 */
	public static function get_donate_label() {
		return __( '☕ Support this plugin', 'tso-link-inspector' );
	}

	/**
	 * Donate link label for the Plugins screen row meta.
	 *
	 * @return string
	 */
	public static function get_donate_link_label() {
		return __( 'Donate', 'tso-link-inspector' );
	}

	/**
	 * Echo the donate link markup for admin screens.
	 */
	public static function render_donate_button() {
		?>
		<a class="tsoliin-donate-btn"
		   href="<?php echo esc_url( self::get_kofi_donate_url() ); ?>"
		   target="_blank"
		   rel="noopener noreferrer">
			<?php echo esc_html( self::get_donate_label() ); ?>
		</a>
		<?php
	}
}
