/**
 * TSO Link Inspector – printable HTML report.
 * Enqueued via wp_enqueue_script / wp_print_scripts (no inline onclick).
 */
( function () {
	'use strict';

	var btn = document.getElementById( 'tsoliin-report-print' );
	if ( ! btn ) {
		return;
	}
	btn.addEventListener( 'click', function () {
		window.print();
	} );
}() );
