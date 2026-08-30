/**
 * Fine-tune list scroll after filter/pagination navigation (no jQuery, before admin.js).
 */
( function () {
	'use strict';

	function scrollToList() {
		if ( window.tsoliinDidScrollToList ) {
			return true;
		}

		var el = document.getElementById( 'tsoliin-list-table-region' );
		if ( ! el ) {
			return false;
		}

		try {
			if ( '1' !== window.sessionStorage.getItem( 'tsoliinScrollToList' ) ) {
				return false;
			}
			window.sessionStorage.removeItem( 'tsoliinScrollToList' );
			window.sessionStorage.removeItem( 'tsoliinScrollY' );
		} catch ( e ) {
			return false;
		}

		el.scrollIntoView( { block: 'start', behavior: 'auto' } );
		window.tsoliinDidScrollToList = true;
		return true;
	}

	if ( ! scrollToList() ) {
		document.addEventListener(
			'DOMContentLoaded',
			function () {
				scrollToList();
			},
			{ once: true }
		);
	}
}() );
