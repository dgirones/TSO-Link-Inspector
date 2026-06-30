/**
 * Scroll to and highlight a link/image matched from the inspector list.
 */
( function () {
	'use strict';

	var data = window.tsoliinFocusLink;
	if ( ! data || ! data.variants || ! data.variants.length ) {
		return;
	}

	function decodeEntities( text ) {
		var el = document.createElement( 'textarea' );
		el.innerHTML = String( text || '' );
		return el.value;
	}

	function getSearchVariants() {
		var out  = [];
		var seen = {};
		var i;
		for ( i = 0; i < data.variants.length; i++ ) {
			var raw      = data.variants[ i ];
			var decoded  = decodeEntities( raw );
			[ raw, decoded ].forEach( function ( value ) {
				if ( value && ! seen[ value ] ) {
					seen[ value ] = true;
					out.push( value );
				}
			} );
		}
		return out;
	}

	var searchVariants = getSearchVariants();

	function normalizeUrl( url ) {
		var value = String( url || '' ).trim();
		if ( ! value ) {
			return '';
		}
		try {
			var parsed = new URL( value, window.location.href );
			return parsed.href.replace( /\/$/, '' );
		} catch ( e ) {
			return value.replace( /\/$/, '' );
		}
	}

	function valuesMatch( attrValue, variant ) {
		if ( ! attrValue || ! variant ) {
			return false;
		}
		if ( attrValue === variant ) {
			return true;
		}
		if ( normalizeUrl( attrValue ) === normalizeUrl( variant ) ) {
			return true;
		}
		try {
			return decodeURIComponent( attrValue ) === decodeURIComponent( variant );
		} catch ( e ) {
			return false;
		}
	}

	function findElement() {
		var attrs = Array.isArray( data.attrs ) && data.attrs.length ? data.attrs : [ 'href', 'src' ];
		var i;
		var j;
		var k;
		var attr;
		var nodes;
		var node;
		var val;

		for ( i = 0; i < attrs.length; i++ ) {
			attr  = attrs[ i ];
			nodes = document.querySelectorAll( '[' + attr + ']' );
			for ( j = 0; j < nodes.length; j++ ) {
				node = nodes[ j ];
				val  = node.getAttribute( attr );
				if ( ! val ) {
					continue;
				}
				for ( k = 0; k < searchVariants.length; k++ ) {
					if ( valuesMatch( val, searchVariants[ k ] ) ) {
						return node;
					}
				}
			}
		}
		return null;
	}

	function highlightPlainText() {
		var walker = document.createTreeWalker(
			document.body,
			NodeFilter.SHOW_TEXT,
			{
				acceptNode: function ( node ) {
					var parent = node.parentElement;
					if ( ! parent || parent.closest( 'script,style,noscript,.tsoliin-link-focus' ) ) {
						return NodeFilter.FILTER_REJECT;
					}
					return NodeFilter.FILTER_ACCEPT;
				},
			}
		);
		var node;
		while ( ( node = walker.nextNode() ) ) {
			var text = node.textContent || '';
			for ( var k = 0; k < searchVariants.length; k++ ) {
				var variant = searchVariants[ k ];
				var idx     = text.indexOf( variant );
				if ( idx === -1 ) {
					continue;
				}
				var range = document.createRange();
				range.setStart( node, idx );
				range.setEnd( node, idx + variant.length );
				var mark = document.createElement( 'mark' );
				mark.className = 'tsoliin-link-focus';
				try {
					range.surroundContents( mark );
					return mark;
				} catch ( err ) {
					return node.parentElement;
				}
			}
		}
		return null;
	}

	function focusTarget() {
		var el = findElement();
		if ( ! el && ( data.linkType === 'plain' || ! data.attrs || ! data.attrs.length ) ) {
			el = highlightPlainText();
		}
		if ( ! el ) {
			return;
		}
		el.classList.add( 'tsoliin-link-focus' );
		el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		if ( typeof el.focus === 'function' ) {
			try {
				el.focus( { preventScroll: true } );
			} catch ( err ) {
				el.focus();
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', focusTarget );
	} else {
		focusTarget();
	}
}() );
