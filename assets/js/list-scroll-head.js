/**
 * Runs in document head: disable browser scroll restore and jump near prior position.
 */
( function () {
	'use strict';

	if ( 'scrollRestoration' in window.history ) {
		window.history.scrollRestoration = 'manual';
	}

	try {
		if ( '1' !== window.sessionStorage.getItem( 'tsoliinScrollToList' ) ) {
			return;
		}
		var y = parseInt( window.sessionStorage.getItem( 'tsoliinScrollY' ), 10 );
		if ( ! isNaN( y ) && y >= 0 ) {
			window.scrollTo( 0, y );
		}
	} catch ( e ) {
		// Footer script still fine-tunes scroll when possible.
	}
}() );
