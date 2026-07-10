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
		if ( data.fileName ) {
			[ data.fileName, decodeURIComponent( data.fileName ) ].forEach( function ( value ) {
				if ( value && ! seen[ value ] ) {
					seen[ value ] = true;
					out.push( value );
				}
			} );
		}
		return out;
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

	function highlightElement( el ) {
		if ( ! el ) {
			return false;
		}
		el.classList.add( 'tsoliin-link-focus' );
		el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		focused = true;
		blockFocusPending = false;
		return true;
	}

	function selectInTextarea( textarea, searchVariants ) {
		if ( ! textarea ) {
			return false;
		}
		var hit = findIndexInsensitive( textarea.value || '', searchVariants );
		if ( ! hit ) {
			return false;
		}
		textarea.focus();
		if ( typeof textarea.setSelectionRange === 'function' ) {
			textarea.setSelectionRange( hit.index, hit.index + hit.match.length );
		}
		var content = textarea.value || '';
		textarea.scrollTop = Math.max(
			0,
			( hit.index / Math.max( content.length, 1 ) ) * textarea.scrollHeight - textarea.clientHeight / 2
		);
		textarea.classList.add( 'tsoliin-link-focus' );
		focused = true;
		return true;
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

	function urlMatchesVariants( value, searchVariants ) {
		if ( ! value ) {
			return false;
		}
		return haystackContainsVariant( String( value ), searchVariants );
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
				if ( parseInt( img.getAttribute( 'data-id' ), 10 ) === attachmentId
					|| parseInt( img.getAttribute( 'data-attachment-id' ), 10 ) === attachmentId ) {
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
					return NodeFilter.FILTER_ACCEPT;
				},
			}
		);
		var node;
		while ( ( node = walker.nextNode() ) ) {
			var hit = findIndexInsensitive( node.textContent || '', searchVariants );
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
		var linkType = data && data.linkType ? data.linkType : '';
		if ( linkType === 'image' || linkType === 'iframe' ) {
			var attachmentId = parseInt( data.attachmentId, 10 ) || 0;
			var images       = doc.body.querySelectorAll( 'img' );
			var i;
			if ( attachmentId > 0 ) {
				for ( i = 0; i < images.length; i++ ) {
					var img = images[ i ];
					if ( parseInt( img.getAttribute( 'data-id' ), 10 ) === attachmentId
						|| parseInt( img.getAttribute( 'data-attachment-id' ), 10 ) === attachmentId
						|| parseInt( img.getAttribute( 'data-wp-image' ), 10 ) === attachmentId ) {
						ed.focus();
						return highlightElement( img );
					}
				}
			}
			for ( i = 0; i < images.length; i++ ) {
				var candidate = images[ i ];
				var src       = candidate.getAttribute( 'src' ) || '';
				var srcset    = candidate.getAttribute( 'srcset' ) || '';
				if ( urlMatchesVariants( src, searchVariants ) || urlMatchesVariants( srcset, searchVariants ) ) {
					ed.focus();
					return highlightElement( candidate );
				}
			}
		}
		var el = highlightPlainTextInRoot( doc.body, searchVariants );
		if ( ! el ) {
			return false;
		}
		ed.focus();
		return highlightElement( el );
	}

	function tryClassicEditorFocus( data, searchVariants ) {
		if ( focusMetaField( data, searchVariants ) ) {
			return true;
		}
		if ( focusInTinyMce( searchVariants, data ) ) {
			return true;
		}
		return selectInTextarea( findCodeTextarea() || document.getElementById( 'content' ), searchVariants );
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
		var runFocus = function () {
			if ( tryFocus() ) {
				return;
			}
			var delays = [ 400, 900, 1500, 2500, 4000, 6000 ];
			var i;
			for ( i = 0; i < delays.length; i++ ) {
				window.setTimeout( tryFocus, delays[ i ] );
			}
			if ( blockEditor ) {
				scheduleClassicFallback( data );
			}
		};
		if ( ! blockEditor ) {
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
