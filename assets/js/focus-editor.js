/**
 * Scroll to and highlight a link in the post editor (block or classic).
 */
( function () {
	'use strict';

	var focused           = false;
	var codeModeTried     = false;
	var visualTried       = false;
	var blockFocusPending = false;
	var visualModeEnsured = false;

	function isClassicEditorDom() {
		if ( document.body.classList.contains( 'block-editor-page' ) ) {
			return false;
		}
		return !! (
			document.getElementById( 'post' ) && (
				document.getElementById( 'content' ) ||
				document.getElementById( 'wp-content-editor-container' ) ||
				document.getElementById( 'postdivrich' )
			)
		);
	}

	function isBlockEditorScreen( data ) {
		if ( isClassicEditorDom() ) {
			return false;
		}
		if ( data && typeof data.isBlockEditor !== 'undefined' ) {
			return !! data.isBlockEditor;
		}
		return document.body.classList.contains( 'block-editor-page' )
			|| !! document.querySelector( '.block-editor-writing-flow, .edit-post-layout' );
	}

	function getFocusData() {
		if ( window.tsoliinFocusLink && window.tsoliinFocusLink.variants && window.tsoliinFocusLink.variants.length ) {
			return window.tsoliinFocusLink;
		}
		try {
			if ( window.wp && wp.data ) {
				var blockSelect = wp.data.select( 'core/block-editor' );
				if ( blockSelect && blockSelect.getSettings ) {
					var settings = blockSelect.getSettings();
					if ( settings && settings.tsoliinFocusLink && settings.tsoliinFocusLink.variants ) {
						return settings.tsoliinFocusLink;
					}
				}
			}
		} catch ( err ) {}
		return null;
	}

	function decodeEntities( text ) {
		var el = document.createElement( 'textarea' );
		el.innerHTML = String( text || '' );
		return el.value;
	}

	function looksLikeUrlNeedle( value ) {
		var v = String( value || '' ).trim();
		if ( ! v || v.length < 8 ) {
			return false;
		}
		// Path basename alone (e.g. "shortcodes") must not match body text.
		if ( /^[a-z0-9._-]+$/i.test( v ) && v.indexOf( '.' ) === -1 ) {
			return false;
		}
		return /^(?:https?:|\/\/|\/|\.\/|\.\.\/|#)/i.test( v )
			|| /\.(?:jpe?g|png|gif|webp|avif|svg|bmp|ico)(?:\?|$)/i.test( v )
			|| v.indexOf( '/' ) !== -1;
	}

	function buildSearchVariants( data ) {
		var out  = [];
		var seen = {};
		var list = ( data.contentNeedle ? [ data.contentNeedle ] : [] ).concat( data.variants || [] );
		var i;
		for ( i = 0; i < list.length; i++ ) {
			var raw     = list[ i ];
			var decoded = decodeEntities( raw );
			[ raw, decoded ].forEach( function ( value ) {
				if ( value && ! seen[ value ] ) {
					seen[ value ] = true;
					out.push( value );
				}
			} );
		}
		// File basename is only useful for media (src/srcset), never as a text/href needle
		// for normal links — e.g. URL …/shortcodes/ must not highlight the word "shortcodes".
		if ( data.fileName && ( data.linkType === 'image' || data.linkType === 'iframe' ) ) {
			var fileNames = [ data.fileName ];
			try {
				fileNames.push( decodeURIComponent( data.fileName ) );
			} catch ( e ) {
				// Malformed % sequences — keep the raw file name only.
			}
			fileNames.forEach( function ( value ) {
				if ( value && ! seen[ value ] ) {
					seen[ value ] = true;
					out.push( value );
				}
			} );
		}
		return out;
	}

	function buildPlainTextVariants( searchVariants ) {
		return ( searchVariants || [] ).filter( looksLikeUrlNeedle );
	}

	function findIndexInsensitive( haystack, needles ) {
		var text  = String( haystack || '' );
		var lower = text.toLowerCase();
		var i;
		for ( i = 0; i < needles.length; i++ ) {
			var needle = needles[ i ];
			if ( ! needle ) {
				continue;
			}
			var idx = lower.indexOf( String( needle ).toLowerCase() );
			if ( idx !== -1 ) {
				return {
					index: idx,
					match: text.substring( idx, idx + needle.length ),
				};
			}
		}
		return null;
	}

	function haystackContainsVariant( haystack, searchVariants ) {
		return !! findIndexInsensitive( haystack, searchVariants );
	}

	function shouldAllowCodeMode( data ) {
		if ( ! data ) {
			return true;
		}
		return data.linkType !== 'image' && data.linkType !== 'iframe';
	}

	function ensureVisualEditorMode( data ) {
		if ( visualModeEnsured || ! isBlockEditorScreen( data ) ) {
			return;
		}
		if ( ! window.wp || ! wp.data ) {
			return;
		}
		var prefSelect = wp.data.select( 'core/preferences' );
		if ( prefSelect && prefSelect.get ) {
			try {
				var current = prefSelect.get( 'core', 'editorMode' );
				if ( 'visual' === current ) {
					visualModeEnsured = true;
					return;
				}
			} catch ( err ) {}
		}
		var prefs = wp.data.dispatch( 'core/preferences' );
		if ( ! prefs || ! prefs.set ) {
			return;
		}
		try {
			prefs.set( 'core', 'editorMode', 'visual' );
		} catch ( err ) {}
		try {
			prefs.set( 'core/edit-post', 'editorMode', 'visual' );
		} catch ( err ) {}
		visualModeEnsured = true;
	}

	function ensureClassicVisualEditorMode() {
		if ( visualModeEnsured ) {
			return;
		}
		if ( typeof window.switchEditors !== 'undefined' && window.switchEditors.go ) {
			try {
				window.switchEditors.go( 'tmce' );
			} catch ( err ) {}
			visualModeEnsured = true;
			return;
		}
		var visualTab = document.getElementById( 'content-tmce' );
		if ( visualTab ) {
			visualTab.click();
			visualModeEnsured = true;
		}
	}

	function isClassicGalleryFocus( data ) {
		return !! ( data && ( data.classicGallery === 1 || data.classicGallery === true ) );
	}

	function imageMatchesAttachmentId( img, attachmentId ) {
		if ( ! img || attachmentId <= 0 ) {
			return false;
		}
		if ( parseInt( img.getAttribute( 'data-id' ), 10 ) === attachmentId ) {
			return true;
		}
		if ( parseInt( img.getAttribute( 'data-attachment-id' ), 10 ) === attachmentId ) {
			return true;
		}
		if ( parseInt( img.getAttribute( 'data-wp-image' ), 10 ) === attachmentId ) {
			return true;
		}
		var className = img.getAttribute( 'class' ) || '';
		var pattern   = new RegExp( '(?:^|\\s)wp-image-' + attachmentId + '(?:\\s|$)' );
		return pattern.test( className );
	}

	function pickImageByAttachment( images, attachmentId, preferGallery ) {
		var galleryMatch = null;
		var anyMatch     = null;
		var i;
		for ( i = 0; i < images.length; i++ ) {
			if ( ! imageMatchesAttachmentId( images[ i ], attachmentId ) ) {
				continue;
			}
			if ( images[ i ].closest( '.gallery, .wp-block-gallery' ) ) {
				galleryMatch = images[ i ];
				if ( preferGallery ) {
					return galleryMatch;
				}
			}
			if ( ! anyMatch ) {
				anyMatch = images[ i ];
			}
		}
		return ( preferGallery && galleryMatch ) ? galleryMatch : anyMatch;
	}

	function pickImageByUrl( images, searchVariants, preferGallery ) {
		var galleryMatch = null;
		var anyMatch     = null;
		var i;
		for ( i = 0; i < images.length; i++ ) {
			var candidate = images[ i ];
			var src       = candidate.getAttribute( 'src' ) || '';
			var srcset    = candidate.getAttribute( 'srcset' ) || '';
			if ( ! urlMatchesVariants( src, searchVariants ) && ! urlMatchesVariants( srcset, searchVariants ) ) {
				continue;
			}
			if ( candidate.closest( '.gallery, .wp-block-gallery' ) ) {
				galleryMatch = candidate;
				if ( preferGallery ) {
					return galleryMatch;
				}
			}
			if ( ! anyMatch ) {
				anyMatch = candidate;
			}
		}
		return ( preferGallery && galleryMatch ) ? galleryMatch : anyMatch;
	}

	function selectImageInTinyMce( ed, img ) {
		if ( ! ed || ! img ) {
			return false;
		}
		try {
			ed.focus( { preventScroll: true } );
		} catch ( err ) {
			try {
				ed.focus();
			} catch ( err2 ) {}
		}
		if ( ed.selection && ed.selection.select ) {
			try {
				ed.selection.select( img );
				if ( ed.selection.scrollIntoView ) {
					ed.selection.scrollIntoView( img );
				}
			} catch ( err3 ) {}
		}
		return highlightElement( img );
	}

	function getDocumentFromRoot( root ) {
		if ( ! root ) {
			return null;
		}
		if ( root.nodeType === 9 ) {
			return root;
		}
		return root.ownerDocument || document;
	}

	function getEditorDocuments() {
		var docs = [ document ];
		document.querySelectorAll( 'iframe' ).forEach( function ( iframe ) {
			try {
				if ( iframe.contentDocument ) {
					docs.push( iframe.contentDocument );
				}
			} catch ( err ) {}
		} );
		return docs;
	}

	/**
	 * Scroll so `el` is visible in the admin window.
	 * TinyMCE Visual mode puts content in an iframe: element.scrollIntoView only
	 * moves the iframe document (often with no scrollbar) and leaves the WP page at the top.
	 *
	 * @param {Element} el Target node (may live inside TinyMCE iframe).
	 */
	function scrollElementIntoAdminView( el ) {
		if ( ! el || ! el.getBoundingClientRect ) {
			return;
		}

		try {
			el.scrollIntoView( { behavior: 'auto', block: 'center', inline: 'nearest' } );
		} catch ( err ) {
			try {
				el.scrollIntoView( true );
			} catch ( err2 ) {}
		}

		var rect = el.getBoundingClientRect();
		var topInParent = rect.top;
		var doc = el.ownerDocument;
		var win = doc && ( doc.defaultView || doc.parentWindow );

		if ( win && win !== window ) {
			var frameEl = null;
			try {
				frameEl = win.frameElement;
			} catch ( err3 ) {
				frameEl = null;
			}
			if ( ! frameEl ) {
				frameEl = document.getElementById( 'content_ifr' );
			}
			if ( frameEl && frameEl.getBoundingClientRect ) {
				var frameRect = frameEl.getBoundingClientRect();
				topInParent = frameRect.top + rect.top;
			}
		}

		var absoluteTop = topInParent + window.pageYOffset;
		var pad = Math.max( 96, Math.floor( window.innerHeight / 4 ) );
		var target = Math.max( 0, absoluteTop - pad );

		window.scrollTo( 0, target );
		if ( document.documentElement ) {
			document.documentElement.scrollTop = target;
		}
		if ( document.body ) {
			document.body.scrollTop = target;
		}

		// Keep the editor chrome on screen too.
		try {
			var box = document.getElementById( 'postdivrich' ) || document.getElementById( 'wp-content-editor-container' );
			if ( box ) {
				var boxRect = box.getBoundingClientRect();
				if ( boxRect.top > window.innerHeight - 80 || boxRect.bottom < 80 ) {
					// Element scroll already set; nothing else.
				}
			}
		} catch ( err4 ) {}
	}

	function highlightElement( el ) {
		if ( ! el ) {
			return false;
		}
		el.classList.add( 'tsoliin-link-focus' );
		scrollElementIntoAdminView( el );
		// TinyMCE / admin layout settle — re-scroll a few times.
		[ 50, 200, 500, 1000 ].forEach( function ( delay ) {
			window.setTimeout( function () {
				scrollElementIntoAdminView( el );
			}, delay );
		} );
		focused = true;
		blockFocusPending = false;
		return true;
	}

	/**
	 * Pixel height of textarea content from start through character `index`.
	 *
	 * @param {HTMLTextAreaElement} textarea Source (styles/width).
	 * @param {number}              index    Character offset.
	 * @return {number}
	 */
	function measureTextareaPrefixHeight( textarea, index ) {
		var value = textarea.value || '';
		index = Math.max( 0, Math.min( index, value.length ) );
		if ( textarea.clientWidth < 40 ) {
			return 0;
		}

		var clone = document.createElement( 'textarea' );
		var style = window.getComputedStyle( textarea );
		var props = [
			'boxSizing', 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle',
			'letterSpacing', 'lineHeight', 'textTransform', 'wordSpacing', 'textIndent',
			'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
			'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
			'borderTopStyle', 'borderRightStyle', 'borderBottomStyle', 'borderLeftStyle',
			'whiteSpace', 'wordWrap', 'overflowWrap', 'tabSize', 'MozTabSize',
		];
		var i;
		for ( i = 0; i < props.length; i++ ) {
			try {
				clone.style[ props[ i ] ] = style[ props[ i ] ];
			} catch ( err ) {}
		}
		clone.setAttribute( 'rows', '1' );
		clone.style.position = 'absolute';
		clone.style.left = '-99999px';
		clone.style.top = '0';
		clone.style.height = '1px';
		clone.style.minHeight = '0';
		clone.style.maxHeight = 'none';
		clone.style.overflow = 'hidden';
		clone.style.visibility = 'hidden';
		clone.style.whiteSpace = style.whiteSpace || 'pre-wrap';
		clone.style.width = textarea.clientWidth + 'px';
		clone.value = value.substring( 0, index );
		document.body.appendChild( clone );
		var h = clone.scrollHeight;
		document.body.removeChild( clone );
		return h;
	}

	/**
	 * Reveal character `index` in a textarea: scroll the textarea when it has an
	 * internal scrollbar, otherwise scroll the admin page (Classic Text mode often
	 * expands #content so scrollTop does nothing).
	 *
	 * @param {HTMLTextAreaElement} textarea Target textarea.
	 * @param {number}              index    Character offset to reveal.
	 * @return {boolean}
	 */
	function scrollTextareaToIndex( textarea, index ) {
		if ( ! textarea ) {
			return false;
		}
		var value = textarea.value || '';
		index = Math.max( 0, Math.min( index, value.length ) );

		if ( textarea.clientWidth < 40 || textarea.clientHeight < 20 ) {
			return false;
		}

		var contentHeight = measureTextareaPrefixHeight( textarea, index );
		if ( contentHeight <= 0 && index > 0 ) {
			return false;
		}

		var pad = Math.max( 48, Math.floor( Math.min( textarea.clientHeight, window.innerHeight ) / 4 ) );
		var canScrollInner = textarea.scrollHeight > textarea.clientHeight + 4;

		if ( canScrollInner ) {
			textarea.scrollTop = Math.max( 0, contentHeight - pad );
		}

		// Caret Y in document coordinates.
		var rect = textarea.getBoundingClientRect();
		var caretFromTextareaTop = canScrollInner
			? ( contentHeight - textarea.scrollTop )
			: contentHeight;
		// Clamp to visible box when inner-scrolled (caret should sit near pad).
		if ( canScrollInner ) {
			caretFromTextareaTop = Math.min( Math.max( caretFromTextareaTop, 0 ), textarea.clientHeight );
		}
		var caretPageY = rect.top + window.pageYOffset + caretFromTextareaTop;
		var target = Math.max( 0, caretPageY - pad );

		window.scrollTo( 0, target );
		if ( document.documentElement ) {
			document.documentElement.scrollTop = target;
		}
		if ( document.body ) {
			document.body.scrollTop = target;
		}

		return true;
	}

	/**
	 * Select URL in textarea and keep retrying scroll until layout is ready (Text tab switch).
	 *
	 * @param {HTMLTextAreaElement} textarea Target.
	 * @param {number}              start    Selection start.
	 * @param {number}              end      Selection end.
	 */
	function selectAndScrollTextarea( textarea, start, end ) {
		if ( ! textarea ) {
			return;
		}
		var apply = function () {
			if ( typeof textarea.focus === 'function' ) {
				try {
					textarea.focus( { preventScroll: true } );
				} catch ( err ) {
					textarea.focus();
				}
			}
			if ( typeof textarea.setSelectionRange === 'function' ) {
				textarea.setSelectionRange( start, end );
			}
			scrollTextareaToIndex( textarea, start );
			if ( typeof textarea.setSelectionRange === 'function' ) {
				textarea.setSelectionRange( start, end );
			}
		};
		apply();
		[ 50, 150, 350, 700, 1200, 2000, 3000 ].forEach( function ( delay ) {
			window.setTimeout( apply, delay );
		} );
	}

	function selectInTextarea( textarea, searchVariants ) {
		if ( ! textarea ) {
			return false;
		}
		var needles = buildPlainTextVariants( searchVariants );
		if ( ! needles.length ) {
			needles = searchVariants;
		}
		var hit = findIndexInsensitive( textarea.value || '', needles );
		if ( ! hit ) {
			return false;
		}
		selectAndScrollTextarea( textarea, hit.index, hit.index + hit.match.length );
		textarea.classList.add( 'tsoliin-link-focus' );
		focused = true;
		return true;
	}

	/**
	 * Select in Classic #content only when the Text tab is active (otherwise the user stays on Visual at the top).
	 */
	function focusInClassicHtmlTextarea( searchVariants ) {
		if ( ! isClassicHtmlMode() ) {
			return false;
		}
		return selectInTextarea( getClassicContentTextarea(), searchVariants );
	}

	function findCodeTextarea() {
		var selectors = [
			'textarea.editor-post-text-editor',
			'.edit-post-text-editor textarea',
			'.block-editor-plain-text',
			'textarea.block-editor-plain-text',
			'.edit-post-text-editor__body textarea',
			'#post-content-0',
			'textarea[name="content"]',
			'#content',
		];
		var i;
		for ( i = 0; i < selectors.length; i++ ) {
			var el = document.querySelector( selectors[ i ] );
			if ( el ) {
				return el;
			}
		}
		return null;
	}

	function switchToCodeEditorMode( callback ) {
		if ( ! window.wp || ! wp.data ) {
			callback();
			return;
		}
		var prefs = wp.data.dispatch( 'core/preferences' );
		if ( prefs && prefs.set ) {
			try {
				prefs.set( 'core', 'editorMode', 'text' );
			} catch ( err ) {}
			try {
				prefs.set( 'core/edit-post', 'editorMode', 'text' );
			} catch ( err ) {}
		}
		window.setTimeout( callback, 900 );
	}

	function focusInCodeEditor( data, searchVariants ) {
		if ( ! shouldAllowCodeMode( data ) ) {
			return false;
		}
		var textarea = findCodeTextarea();
		if ( textarea && selectInTextarea( textarea, searchVariants ) ) {
			return true;
		}
		var content = '';
		if ( window.wp && wp.data ) {
			var editorSelect = wp.data.select( 'core/editor' );
			if ( editorSelect && editorSelect.getEditedPostContent ) {
				content = editorSelect.getEditedPostContent() || '';
			}
		}
		if ( ! haystackContainsVariant( content, searchVariants ) ) {
			return false;
		}
		if ( ! codeModeTried ) {
			codeModeTried = true;
			switchToCodeEditorMode( function () {
				var ta = findCodeTextarea();
				selectInTextarea( ta, searchVariants );
			} );
		}
		return focused;
	}

	function blockAttributes( block ) {
		if ( ! block ) {
			return null;
		}
		if ( block.attributes && typeof block.attributes === 'object' ) {
			return block.attributes;
		}
		if ( block.attrs && typeof block.attrs === 'object' ) {
			return block.attrs;
		}
		return null;
	}

	function blockContainsAttachmentId( block, attachmentId ) {
		if ( ! attachmentId || ! block ) {
			return false;
		}
		var attrs = blockAttributes( block );
		if ( ! attrs ) {
			return false;
		}
		if ( Array.isArray( attrs.ids ) && attrs.ids.indexOf( attachmentId ) !== -1 ) {
			return true;
		}
		if ( parseInt( attrs.id, 10 ) === attachmentId ) {
			return true;
		}
		if ( Array.isArray( attrs.images ) ) {
			var i;
			for ( i = 0; i < attrs.images.length; i++ ) {
				var img = attrs.images[ i ];
				if ( img && parseInt( img.id, 10 ) === attachmentId ) {
					return true;
				}
			}
		}
		return false;
	}

	function blockHaystack( block ) {
		var parts = [];
		if ( window.wp && wp.blocks ) {
			if ( wp.blocks.serialize ) {
				try {
					parts.push( wp.blocks.serialize( [ block ] ) );
				} catch ( err ) {}
			}
			if ( wp.blocks.getBlockContent ) {
				try {
					parts.push( wp.blocks.getBlockContent( block ) );
				} catch ( err ) {}
			}
		}
		var attrs = blockAttributes( block );
		if ( attrs ) {
			try {
				parts.push( JSON.stringify( attrs ) );
			} catch ( err ) {}
		}
		if ( block.innerContent && block.innerContent.length ) {
			parts.push( block.innerContent.filter( Boolean ).join( '' ) );
		}
		if ( block.innerHTML ) {
			parts.push( block.innerHTML );
		}
		if ( block.originalContent ) {
			parts.push( block.originalContent );
		}
		return parts.join( '\n' );
	}

	function findBlockClientIdByAttachment( blocks, attachmentId ) {
		var i;
		for ( i = 0; i < blocks.length; i++ ) {
			var block = blocks[ i ];
			if ( blockContainsAttachmentId( block, attachmentId ) ) {
				return block.clientId;
			}
			if ( block.innerBlocks && block.innerBlocks.length ) {
				var inner = findBlockClientIdByAttachment( block.innerBlocks, attachmentId );
				if ( inner ) {
					return inner;
				}
			}
		}
		return null;
	}

	function findBlockClientId( blocks, searchVariants, attachmentId ) {
		if ( attachmentId ) {
			var byId = findBlockClientIdByAttachment( blocks, attachmentId );
			if ( byId ) {
				return byId;
			}
		}
		var i;
		for ( i = 0; i < blocks.length; i++ ) {
			var block    = blocks[ i ];
			var haystack = blockHaystack( block );
			if ( haystack && haystackContainsVariant( haystack, searchVariants ) ) {
				return block.clientId;
			}
			if ( block.innerBlocks && block.innerBlocks.length ) {
				var inner = findBlockClientId( block.innerBlocks, searchVariants, attachmentId );
				if ( inner ) {
					return inner;
				}
			}
		}
		return null;
	}

	function findBlockClientIdFromContent( searchVariants, attachmentId ) {
		if ( ! window.wp || ! wp.data || ! wp.blocks || ! wp.blocks.parse ) {
			return null;
		}
		var editorSelect = wp.data.select( 'core/editor' );
		var blockSelect  = wp.data.select( 'core/block-editor' );
		if ( ! editorSelect || ! blockSelect ) {
			return null;
		}
		var content = editorSelect.getEditedPostContent ? editorSelect.getEditedPostContent() : '';
		if ( ! content ) {
			return null;
		}
		if ( ! haystackContainsVariant( content, searchVariants ) && ! attachmentId ) {
			return null;
		}
		var parsed = wp.blocks.parse( content );
		var live   = blockSelect.getBlocks();
		return matchParsedToLive( parsed, live, searchVariants, attachmentId );
	}

	function matchParsedToLive( parsedBlocks, liveBlocks, searchVariants, attachmentId ) {
		var i;
		for ( i = 0; i < parsedBlocks.length; i++ ) {
			var parsed = parsedBlocks[ i ];
			var live   = liveBlocks[ i ];
			if ( ! live ) {
				continue;
			}
			var parsedMatch = blockContainsAttachmentId( parsed, attachmentId )
				|| haystackContainsVariant( blockHaystack( parsed ), searchVariants );
			if ( parsedMatch ) {
				return live.clientId;
			}
			if ( parsed.innerBlocks && live.innerBlocks ) {
				var inner = matchParsedToLive( parsed.innerBlocks, live.innerBlocks, searchVariants, attachmentId );
				if ( inner ) {
					return inner;
				}
			}
		}
		return null;
	}

	function findBlockElement( clientId ) {
		var docs = getEditorDocuments();
		var i;
		for ( i = 0; i < docs.length; i++ ) {
			var el = docs[ i ].querySelector( '[data-block="' + clientId + '"]' );
			if ( el ) {
				return el;
			}
		}
		return null;
	}

	function normalizeUrlForMatch( url ) {
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

	function urlValuesEqual( attrValue, variant ) {
		if ( ! attrValue || ! variant ) {
			return false;
		}
		if ( attrValue === variant ) {
			return true;
		}
		if ( normalizeUrlForMatch( attrValue ) === normalizeUrlForMatch( variant ) ) {
			return true;
		}
		try {
			return decodeURIComponent( attrValue ) === decodeURIComponent( variant );
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * Match an attribute value to URL variants (exact / normalized), not raw substring.
	 * Avoids treating path basenames like "shortcodes" as a hit inside any href.
	 */
	function urlMatchesVariants( value, searchVariants ) {
		if ( ! value ) {
			return false;
		}
		var val = String( value );
		var i;
		for ( i = 0; i < searchVariants.length; i++ ) {
			var variant = searchVariants[ i ];
			if ( ! variant || ! looksLikeUrlNeedle( variant ) ) {
				continue;
			}
			if ( urlValuesEqual( val, variant ) ) {
				return true;
			}
			// Allow filename / size variants inside src or srcset only via longer needles.
			if ( variant.length >= 12 && val.toLowerCase().indexOf( String( variant ).toLowerCase() ) !== -1 ) {
				return true;
			}
		}
		return false;
	}

	function findElementByUrlAttrs( root, data, searchVariants ) {
		if ( ! root || ! root.querySelectorAll ) {
			return null;
		}
		var attrs = ( data && Array.isArray( data.attrs ) && data.attrs.length )
			? data.attrs
			: [ 'href', 'src' ];
		var a;
		var j;
		for ( a = 0; a < attrs.length; a++ ) {
			var attr  = attrs[ a ];
			var nodes = root.querySelectorAll( '[' + attr + ']' );
			for ( j = 0; j < nodes.length; j++ ) {
				var node = nodes[ j ];
				var val  = node.getAttribute( attr );
				if ( urlMatchesVariants( val, searchVariants ) ) {
					return node;
				}
			}
		}
		return null;
	}

	function findImageInBlockElement( blockEl, data, searchVariants ) {
		if ( ! blockEl ) {
			return null;
		}
		var attachmentId = parseInt( data.attachmentId, 10 ) || 0;
		var images       = blockEl.querySelectorAll( 'img' );
		var i;

		if ( attachmentId > 0 ) {
			for ( i = 0; i < images.length; i++ ) {
				var img = images[ i ];
				if ( imageMatchesAttachmentId( img, attachmentId ) ) {
					return img;
				}
			}
		}

		for ( i = 0; i < images.length; i++ ) {
			var candidate = images[ i ];
			var src       = candidate.getAttribute( 'src' ) || '';
			var srcset    = candidate.getAttribute( 'srcset' ) || '';
			if ( urlMatchesVariants( src, searchVariants ) || urlMatchesVariants( srcset, searchVariants ) ) {
				return candidate;
			}
		}

		var links = blockEl.querySelectorAll( 'a[href]' );
		for ( i = 0; i < links.length; i++ ) {
			if ( urlMatchesVariants( links[ i ].getAttribute( 'href' ), searchVariants ) ) {
				var linkedImg = links[ i ].querySelector( 'img' );
				return linkedImg || links[ i ];
			}
		}

		return null;
	}

	function highlightPlainTextInRoot( root, searchVariants ) {
		var textVariants = buildPlainTextVariants( searchVariants );
		if ( ! textVariants.length ) {
			return null;
		}
		var doc      = getDocumentFromRoot( root );
		var treeRoot = root && root.nodeType === 9 ? root.body : root;
		if ( ! doc || ! doc.createTreeWalker || ! treeRoot ) {
			return null;
		}
		var walker = doc.createTreeWalker(
			treeRoot,
			NodeFilter.SHOW_TEXT,
			{
				acceptNode: function ( node ) {
					var parent = node.parentElement;
					if ( ! parent || parent.closest( 'script,style,noscript,.tsoliin-link-focus' ) ) {
						return NodeFilter.FILTER_REJECT;
					}
					// Prefer attribute matching for real links; do not mark text inside <a href>.
					if ( parent.closest( 'a[href]' ) ) {
						return NodeFilter.FILTER_REJECT;
					}
					return NodeFilter.FILTER_ACCEPT;
				},
			}
		);
		var node;
		while ( ( node = walker.nextNode() ) ) {
			var hit = findIndexInsensitive( node.textContent || '', textVariants );
			if ( ! hit ) {
				continue;
			}
			var range = doc.createRange();
			range.setStart( node, hit.index );
			range.setEnd( node, hit.index + hit.match.length );
			var mark = doc.createElement( 'mark' );
			mark.className = 'tsoliin-link-focus';
			try {
				range.surroundContents( mark );
				return mark;
			} catch ( err ) {
				return node.parentElement;
			}
		}
		return null;
	}

	function highlightInsideBlock( blockEl, data, searchVariants ) {
		if ( data.linkType === 'image' || data.linkType === 'iframe' ) {
			var mediaEl = findImageInBlockElement( blockEl, data, searchVariants );
			if ( mediaEl ) {
				var tile = mediaEl.closest( '.tiled-gallery__item, .blocks-gallery-item, .wp-block-image, figure' );
				return highlightElement( tile || mediaEl );
			}
		}
		var byAttr = findElementByUrlAttrs( blockEl, data, searchVariants );
		if ( byAttr ) {
			return highlightElement( byAttr );
		}
		var editable = blockEl.querySelector( '[contenteditable="true"], .block-editor-rich-text__editable' );
		var mark     = highlightPlainTextInRoot( editable || blockEl, searchVariants );
		if ( mark ) {
			return highlightElement( mark );
		}
		return highlightElement( blockEl );
	}

	function focusInBlockEditor( data, searchVariants ) {
		if ( ! window.wp || ! wp.data ) {
			return false;
		}
		var select   = wp.data.select( 'core/block-editor' );
		var dispatch = wp.data.dispatch( 'core/block-editor' );
		if ( ! select || ! dispatch || ! select.getBlocks ) {
			return false;
		}
		var blocks = select.getBlocks();
		if ( ! blocks || ! blocks.length ) {
			return false;
		}
		var attachmentId = parseInt( data.attachmentId, 10 ) || 0;
		var clientId     = findBlockClientIdFromContent( searchVariants, attachmentId );
		if ( ! clientId ) {
			clientId = findBlockClientId( blocks, searchVariants, attachmentId );
		}
		if ( ! clientId ) {
			return false;
		}
		blockFocusPending = true;
		ensureVisualEditorMode( data );
		dispatch.selectBlock( clientId );
		if ( dispatch.flashBlock ) {
			dispatch.flashBlock( clientId );
		}
		window.setTimeout( function () {
			var blockEl = findBlockElement( clientId );
			if ( blockEl ) {
				highlightInsideBlock( blockEl, data, searchVariants );
			} else {
				blockFocusPending = false;
			}
		}, data.linkType === 'image' || data.linkType === 'iframe' ? 600 : 350 );
		return true;
	}

	function focusMetaField( data, searchVariants ) {
		if ( ! data.metaKeyHint ) {
			return false;
		}
		var hint = String( data.metaKeyHint );
		var selectors = [
			'[name="' + hint + '"]',
			'[name="' + hint + '[]"]',
			'#' + hint,
			'#_' + hint,
			'[data-name="' + hint + '"]',
			'.acf-field[data-name="' + hint + '"] textarea',
			'.acf-field[data-name="' + hint + '"] input',
		];
		var i;
		for ( i = 0; i < selectors.length; i++ ) {
			var field = document.querySelector( selectors[ i ] );
			if ( ! field ) {
				continue;
			}
			if ( field.tagName === 'TEXTAREA' || field.tagName === 'INPUT' ) {
				selectInTextarea( field, searchVariants );
			} else {
				field.focus();
			}
			return highlightElement( field.closest( '.acf-field, .postbox, tr' ) || field );
		}
		return false;
	}

	function focusInTinyMce( searchVariants, data ) {
		if ( typeof window.tinymce === 'undefined' ) {
			return false;
		}
		var ed = window.tinymce.get( 'content' );
		if ( ! ed || ! ed.getDoc ) {
			return false;
		}
		var doc = ed.getDoc();
		if ( ! doc || ! doc.body ) {
			return false;
		}
		var linkType       = data && data.linkType ? data.linkType : '';
		var preferGallery  = isClassicGalleryFocus( data );
		var focusTiny      = function ( node ) {
			if ( node && node.tagName && node.tagName.toLowerCase() === 'img' ) {
				return selectImageInTinyMce( ed, node );
			}
			try {
				ed.focus( { preventScroll: true } );
			} catch ( errF ) {
				try {
					ed.focus();
				} catch ( errF2 ) {}
			}
			try {
				ed.selection.select( node );
				if ( ed.selection.scrollIntoView ) {
					ed.selection.scrollIntoView( node );
				}
			} catch ( errSel ) {}
			return highlightElement( node );
		};
		if ( linkType === 'image' || linkType === 'iframe' ) {
			var attachmentId = parseInt( data.attachmentId, 10 ) || 0;
			var images       = doc.body.querySelectorAll( 'img' );
			var target       = null;
			if ( attachmentId > 0 ) {
				target = pickImageByAttachment( images, attachmentId, preferGallery );
			}
			if ( ! target ) {
				target = pickImageByUrl( images, searchVariants, preferGallery );
			}
			if ( target ) {
				return focusTiny( target );
			}
			if ( preferGallery ) {
				var galleryRoot = doc.body.querySelector( '.gallery' );
				if ( galleryRoot ) {
					return focusTiny( galleryRoot );
				}
			}
		}

		// Prefer the real <a href> / src node — never the first body word matching a path segment.
		var byAttr = findElementByUrlAttrs( doc.body, data, searchVariants );
		if ( byAttr ) {
			try {
				ed.focus( { preventScroll: true } );
			} catch ( errFocus ) {
				try {
					ed.focus();
				} catch ( errFocus2 ) {}
			}
			try {
				ed.selection.select( byAttr );
				if ( ed.selection.scrollIntoView ) {
					ed.selection.scrollIntoView( byAttr );
				}
			} catch ( err ) {}
			return highlightElement( byAttr );
		}

		if ( linkType === 'plain' || ( data && data.attrs && ! data.attrs.length ) ) {
			var el = highlightPlainTextInRoot( doc.body, searchVariants );
			if ( el ) {
				try {
					ed.focus( { preventScroll: true } );
				} catch ( errFocus3 ) {
					try {
						ed.focus();
					} catch ( errFocus4 ) {}
				}
				return highlightElement( el );
			}
		}
		return false;
	}

	function getClassicContentTextarea() {
		return document.getElementById( 'content' );
	}

	function isClassicHtmlMode() {
		var wrap = document.getElementById( 'wp-content-wrap' );
		return !!( wrap && wrap.classList.contains( 'html-active' ) );
	}

	/**
	 * Switch Classic Editor to the Text tab so shortcode/plain URLs are visible and selectable.
	 *
	 * @param {Function} callback Runs after the tab switch (or immediately if already on Text).
	 */
	function switchClassicToHtmlMode( callback ) {
		if ( typeof callback !== 'function' ) {
			return;
		}
		if ( isClassicHtmlMode() ) {
			callback();
			return;
		}
		// Prefer WP's switchEditors so TinyMCE syncs content into #content first.
		if ( typeof window.switchEditors !== 'undefined' && typeof window.switchEditors.go === 'function' ) {
			try {
				window.switchEditors.go( 'content', 'html' );
			} catch ( err ) {
				var tab = document.getElementById( 'content-html' );
				if ( tab ) {
					tab.click();
				}
			}
		} else {
			var htmlTab = document.getElementById( 'content-html' );
			if ( htmlTab ) {
				htmlTab.click();
			}
		}
		window.setTimeout( callback, 500 );
	}

	/**
	 * Whether #content or TinyMCE currently holds any search needle (URL may only exist as shortcode text).
	 */
	function classicContentHasNeedle( searchVariants ) {
		var ta = getClassicContentTextarea();
		if ( ta && haystackContainsVariant( ta.value || '', searchVariants ) ) {
			return true;
		}
		if ( typeof window.tinymce !== 'undefined' ) {
			var ed = window.tinymce.get( 'content' );
			if ( ed && ed.getContent ) {
				try {
					if ( haystackContainsVariant( ed.getContent( { format: 'raw' } ) || '', searchVariants ) ) {
						return true;
					}
					if ( haystackContainsVariant( ed.getContent() || '', searchVariants ) ) {
						return true;
					}
				} catch ( err ) {}
			}
		}
		return false;
	}

	/**
	 * Classic Editor: switch to Text tab and select the URL (shortcodes / plain text).
	 * Returns true when focused, or when a tab switch was scheduled (retries finish the job).
	 */
	function focusClassicViaHtmlTab( searchVariants ) {
		if ( isClassicHtmlMode() ) {
			return focusInClassicHtmlTextarea( searchVariants );
		}
		if ( ! classicContentHasNeedle( searchVariants ) && codeModeTried ) {
			return false;
		}
		if ( codeModeTried ) {
			// Switch already requested — keep trying select once Text is active.
			return focusInClassicHtmlTextarea( searchVariants );
		}
		codeModeTried = true;
		var attemptSelect = function () {
			if ( focused ) {
				return;
			}
			if ( ! isClassicHtmlMode() ) {
				switchClassicToHtmlMode( attemptSelect );
				return;
			}
			if ( focusInClassicHtmlTextarea( searchVariants ) ) {
				return;
			}
			// Content may sync a moment after the tab switch.
			window.setTimeout( function () {
				focusInClassicHtmlTextarea( searchVariants );
			}, 400 );
			window.setTimeout( function () {
				focusInClassicHtmlTextarea( searchVariants );
			}, 900 );
		};
		switchClassicToHtmlMode( attemptSelect );
		return true;
	}

	function tryClassicEditorFocus( data, searchVariants ) {
		if ( focusMetaField( data, searchVariants ) ) {
			return true;
		}

		var preferText = data && ( data.linkType === 'plain' || data.preferTextMode === 1 || data.preferTextMode === true );
		var isMedia    = data.linkType === 'image' || data.linkType === 'iframe';

		// Plain / shortcode-attribute URLs: always Text tab (Visual cannot select shortcode source).
		if ( preferText ) {
			if ( focusClassicViaHtmlTab( searchVariants ) ) {
				return true;
			}
			return false;
		}

		if ( isMedia ) {
			ensureClassicVisualEditorMode();
		}

		if ( focusInTinyMce( searchVariants, data ) ) {
			return true;
		}

		if ( isMedia ) {
			return false;
		}

		// Hyperlink not found visually (e.g. only inside a shortcode url="…") — Text tab.
		if ( focusClassicViaHtmlTab( searchVariants ) ) {
			return true;
		}

		return false;
	}

	function tryFocus() {
		if ( focused ) {
			return true;
		}
		var data = getFocusData();
		if ( ! data ) {
			return false;
		}
		var searchVariants = buildSearchVariants( data );
		var blockEditor    = isBlockEditorScreen( data );

		if ( ! blockEditor ) {
			return tryClassicEditorFocus( data, searchVariants );
		}

		if ( data.inPostContent === 0 && focusMetaField( data, searchVariants ) ) {
			return true;
		}

		if ( data.linkType === 'plain' ) {
			if ( ! visualTried ) {
				visualTried = true;
				focusInBlockEditor( data, searchVariants );
			}
			if ( blockFocusPending ) {
				return false;
			}
			if ( shouldAllowCodeMode( data ) && focusInCodeEditor( data, searchVariants ) ) {
				return true;
			}
			if ( focusInTinyMce( searchVariants, data ) ) {
				return true;
			}
			return shouldAllowCodeMode( data ) && selectInTextarea( findCodeTextarea(), searchVariants );
		}

		if ( focusInBlockEditor( data, searchVariants ) ) {
			if ( focused ) {
				return true;
			}
			if ( blockFocusPending || ! shouldAllowCodeMode( data ) ) {
				return false;
			}
		}

		if ( shouldAllowCodeMode( data ) && focusInCodeEditor( data, searchVariants ) ) {
			return true;
		}
		if ( focusMetaField( data, searchVariants ) ) {
			return true;
		}
		if ( focusInTinyMce( searchVariants, data ) ) {
			return true;
		}
		if ( shouldAllowCodeMode( data ) ) {
			return selectInTextarea( document.getElementById( 'content' ), searchVariants );
		}
		return false;
	}

	function whenTinyMceReady( callback ) {
		if ( typeof callback !== 'function' ) {
			return;
		}
		if ( typeof window.tinymce === 'undefined' ) {
			callback();
			return;
		}
		var ed = window.tinymce.get( 'content' );
		if ( ed ) {
			if ( ed.initialized ) {
				callback();
			} else {
				ed.on( 'init', callback );
			}
			return;
		}
		window.tinymce.on( 'AddEditor', function ( event ) {
			if ( event.editor && event.editor.id === 'content' ) {
				event.editor.on( 'init', callback );
			}
		} );
	}

	function scheduleClassicFallback( data ) {
		window.setTimeout( function () {
			if ( focused || ! data ) {
				return;
			}
			tryClassicEditorFocus( data, buildSearchVariants( data ) );
		}, 2500 );
	}

	function start() {
		var data = getFocusData();
		if ( ! data ) {
			return;
		}
		var blockEditor = isBlockEditorScreen( data );
		if ( blockEditor ) {
			ensureVisualEditorMode( data );
		}
		var preferText = data.linkType === 'plain' || data.preferTextMode === 1 || data.preferTextMode === true;
		var runFocus = function () {
			if ( tryFocus() ) {
				return;
			}
			var delays = preferText ? [ 200, 500, 900, 1500, 2500, 4000, 6000 ] : [ 400, 900, 1500, 2500, 4000, 6000 ];
			var i;
			for ( i = 0; i < delays.length; i++ ) {
				window.setTimeout( tryFocus, delays[ i ] );
			}
			if ( blockEditor ) {
				scheduleClassicFallback( data );
			}
		};
		if ( ! blockEditor ) {
			if ( data.linkType === 'image' || data.linkType === 'iframe' ) {
				ensureClassicVisualEditorMode();
			} else if ( preferText ) {
				runFocus();
			}
			whenTinyMceReady( runFocus );
		} else {
			runFocus();
		}
	}

	if ( window.wp && wp.domReady ) {
		wp.domReady( start );
	} else if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
