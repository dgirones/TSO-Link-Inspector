/**
 * TSO Link Inspector – Admin JS v1.0.6
 *
 * Background check: server-side WP-Cron, browser only polls every 5s.
 * Navigating away does NOT interrupt the check.
 */
/* global tsoliinData */
( function ( $ ) {
	'use strict';

	var LC = {

		// ---------------------------------------------------------------
		// State
		// ---------------------------------------------------------------
		scanning    : false,
		scanAborted : false,
		scanXhr     : null,
		currentPage : 1,
		polling     : false,
		pollTimer   : null,
		statsTimer  : null,
		completed   : false,   // Guard: prevents reload loop
		editLinkId  : 0,
		editOldUrl  : '',
		editPostId  : 0,
		editLinkType: 'link',
		previewTimer: null,
		searchTimer   : null,
		searchXhr     : null,
		lastSearchVal : '',
		suggestXhr      : null,
		suggestTrigger  : null,
		suggestRequestId: 0,
		listReloadTimer : null,

		/**
		 * Escape text for safe HTML insertion.
		 *
		 * @param {string} text Raw text.
		 * @return {string}
		 */
		escapeHtml: function ( text ) {
			return $( '<div/>' ).text( text == null ? '' : String( text ) ).html();
		},

		/**
		 * Remove all suggestion panels stacked after a list row.
		 *
		 * @param {jQuery} $row Data table row.
		 */
		removeSuggestPanelsForRow: function ( $row ) {
			if ( ! $row || ! $row.length ) {
				return;
			}
			var $next = $row.next();
			while ( $next.length && $next.hasClass( 'tsoliin-suggest-row' ) ) {
				var $remove = $next;
				$next = $next.next();
				$remove.remove();
			}
		},

		/**
		 * Find the main list row for a link ID.
		 *
		 * @param {number} linkId Link row ID.
		 * @return {jQuery}
		 */
		findLinkRow: function ( linkId ) {
			return $( 'tr' ).filter( function () {
				return $( this ).find( '.tsoliin-edit-link[data-id="' + linkId + '"], .tsoliin-suggest[data-id="' + linkId + '"]' ).length > 0;
			} ).first();
		},

		// ---------------------------------------------------------------
		// Init
		// ---------------------------------------------------------------
		init: function () {
			this.$form         = $( '#tsoliin-list-form' );
			this.$startBtn     = $( '#tsoliin-start-scan' );
			this.$stopScanBtn  = $( '#tsoliin-stop-scan' );
			this.$checkBtn     = $( '#tsoliin-start-check' );
			this.$stopBtn      = $( '#tsoliin-stop-check' );
			this.$progress     = $( '#tsoliin-scan-progress' );
			this.$progressBar  = this.$progress.find( '.tsoliin-progress__bar' );
			this.$progressLbl  = this.$progress.find( '.tsoliin-progress__label' );
			this.$checkProg    = $( '#tsoliin-check-progress' );
			this.$checkBar     = this.$checkProg.find( '.tsoliin-progress__bar' );
			this.$checkLbl     = this.$checkProg.find( '.tsoliin-progress__label' );
			this.$modal        = $( '#tsoliin-modal' );
			this.$modalOldUrl  = $( '#tsoliin-modal-old-url' );
			this.$newUrlInput  = $( '#tsoliin-new-url' );
			this.$newAnchorInput = $( '#tsoliin-new-anchor' );
			this.$anchorRow      = $( '#tsoliin-modal-anchor-row' );
			this.$anchorLabel    = $( '#tsoliin-modal-anchor-label' );
			this.$anchorNote     = $( '#tsoliin-modal-anchor-note' );
			this.$modalSave    = $( '#tsoliin-modal-save' );
			this.$modalCancel  = $( '#tsoliin-modal-cancel' );
			this.$modalSpinner = $( '.tsoliin-modal__spinner' );
			this.$feedback     = $( '#tsoliin-modal-feedback' );
			this.$previewPanel = $( '#tsoliin-modal-preview' );
			this.$revisionNote = $( '#tsoliin-modal-revision-note' );
			this.$previewBefore = $( '#tsoliin-preview-before' );
			this.$previewAfter  = $( '#tsoliin-preview-after' );

			this.bindEvents();
			this.bindLiveSearch();

			// Resume polling if a check was running before page load.
			if ( parseInt( tsoliinData.bgRunning, 10 ) === 1 ) {
				this.$checkProg.show();
				this.$checkBtn.prop( 'disabled', true );
				this.$startBtn.prop( 'disabled', true );
				this.$stopBtn.show();
				this.startPolling();
			} else {
				// Not running: ensure progress bar is hidden.
				this.$checkProg.hide();
			}

			// Live stat / filter tab counts while editing the list.
			if ( this.$form.length ) {
				this.refreshStats();
				var interval = parseInt( tsoliinData.refreshInterval, 10 ) || 8000;
				if ( interval > 0 ) {
					this.statsTimer = window.setInterval( function () {
						LC.refreshStats();
					}, interval );
				}
			}
		},

		// ---------------------------------------------------------------
		// Event bindings
		// ---------------------------------------------------------------
		bindEvents: function () {
			var self = this;

			this.$startBtn.on( 'click', function () {
				if ( self.scanning ) { return; }
				if ( parseInt( tsoliinData.bgRunning, 10 ) === 1 && ! window.confirm( tsoliinData.i18n.confirmScanWhileCheck ) ) {
					return;
				}
				self.startScan();
			} );

			this.$stopScanBtn.on( 'click', function () {
				self.stopScan();
			} );

			this.$checkBtn.on( 'click', function () {
				if ( $( this ).prop( 'disabled' ) ) { return; }
				if ( self.scanning && ! window.confirm( tsoliinData.i18n.confirmCheckWhileScan ) ) {
					return;
				}
				self.startBgCheck();
			} );

			this.$stopBtn.on( 'click', function () {
				self.stopBgCheck();
			} );

			$( document ).on( 'click', '.tsoliin-url a[data-tsoliin-action-url="1"]', function ( e ) {
				var msg = tsoliinData.i18n.actionUrlWarn || 'This link ends your WordPress session. Open it anyway?';
				if ( ! window.confirm( msg ) ) {
					e.preventDefault();
				}
			} );

			$( document ).on( 'click', '.tsoliin-toggle-chain', function ( e ) {
				e.preventDefault();
				var $btn  = $( this );
				var $list = $btn.siblings( '.tsoliin-redirect-chain' );
				var open  = 'true' === $btn.attr( 'aria-expanded' );
				$btn.attr( 'aria-expanded', open ? 'false' : 'true' );
				$list.prop( 'hidden', open );
			} );

			$( document ).on( 'click', '.tsoliin-edit-link', function ( e ) {
				e.preventDefault();
				var $a = $( this );
				self.openModal(
					parseInt( $a.data( 'id' ), 10 ),
					$a.data( 'url' ),
					parseInt( $a.data( 'post' ), 10 ),
					$a.data( 'anchor' ) || '',
					$a.data( 'type' ) || 'link',
					'1' === String( $a.data( 'anchor-editable' ) || $a.attr( 'data-anchor-editable' ) || '1' )
				);
			} );

			$( document ).on( 'click', '.tsoliin-recheck', function ( e ) {
				e.preventDefault();
				self.recheckLink( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-unlink', function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( tsoliinData.i18n.confirmUnlink ) ) { return; }
				self.unlinkItem( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-not-broken', function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( tsoliinData.i18n.confirmNotBroken ) ) { return; }
				self.markNotBroken( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-add-ignore', function ( e ) {
				e.preventDefault();
				self.addToIgnoreList( $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-onboarding-dismiss', function ( e ) {
				e.preventDefault();
				self.dismissOnboarding( $( '#tsoliin-onboarding' ) );
			} );

			$( document ).on( 'click', '.tsoliin-make-relative', function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( tsoliinData.i18n.confirmMakeRelative ) ) { return; }
				self.makeRelative( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-delete', function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( tsoliinData.i18n.confirmDelete ) ) { return; }
				self.deleteItem( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			// Smart suggest.
			$( document ).on( 'click', '.tsoliin-suggest', function ( e ) {
				e.preventDefault();
				self.smartSuggest( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-suggest-close', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				$( this ).closest( '.tsoliin-suggest-row' ).remove();
			} );

			$( document ).on( 'click', '.tsoliin-apply-suggest', function ( e ) {
				e.preventDefault();
				var $btn   = $( this );
				var linkId = parseInt( $btn.data( 'id' ), 10 );
				var newUrl = $btn.data( 'url' );
				var $row   = self.findLinkRow( linkId );
				if ( ! $row.length ) {
					$row = $btn.closest( '.tsoliin-suggest-row' ).prev( 'tr' );
					while ( $row.length && $row.hasClass( 'tsoliin-suggest-row' ) ) {
						$row = $row.prev( 'tr' );
					}
				}
				self.applySmartUrl( linkId, newUrl, $btn, $row );
			} );

			this.$modalSave.on( 'click', function () { self.saveLink(); } );
			this.$modalCancel.on( 'click', function () { self.closeModal(); } );
			this.$newUrlInput.on( 'input', function () { self.scheduleLinkPreview(); } );
			$( document ).on( 'click', '.tsoliin-modal__overlay', function () { self.closeModal(); } );
			$( document ).on( 'keydown', function ( e ) {
				if ( 27 === e.which && self.$modal.is( ':visible' ) ) { self.closeModal(); }
			} );

			$( '#tsoliin-diagnose' ).on( 'click', function () { self.runDiagnose(); } );

			this.$form.on( 'submit', function ( e ) {
				var action = self.$form.find( 'select[name="action"]' ).val();
				if ( ! action || '-1' === action ) {
					action = self.$form.find( 'select[name="action2"]' ).val();
				}
				var managed = [ 'recheck', 'delete', 'unlink', 'not_broken' ];
				if ( parseInt( tsoliinData.relativeUrlTool, 10 ) === 1 ) {
					managed.push( 'make_relative' );
				}
				if ( -1 !== managed.indexOf( action ) ) {
					e.preventDefault();
					self.doBulkAction( action );
				}
			} );

			// Meta scan row toggle.
			var $cb  = $( '#tsoliin_scan_meta' );
			var $row = $( '#tsoliin-meta-exclude-row' );
			if ( $cb.length && $row.length ) {
				$row.toggle( $cb.is( ':checked' ) );
				$cb.on( 'change', function () { $row.toggle( $( this ).is( ':checked' ) ); } );
			}
		},

		// ---------------------------------------------------------------
		// Scan (browser-driven)
		// ---------------------------------------------------------------
		resetScanButton: function () {
			this.$startBtn.prop( 'disabled', false ).html(
				'<span class="dashicons dashicons-search"></span> ' + tsoliinData.i18n.scanNow
			);
		},

		resetCheckButton: function () {
			var label = parseInt( tsoliinData.viewPostId, 10 ) > 0
				? tsoliinData.i18n.checkThisPost
				: tsoliinData.i18n.checkNow;
			this.$checkBtn.prop( 'disabled', false ).html(
				'<span class="dashicons dashicons-yes-alt"></span> ' + label
			);
		},

		startScan: function () {
			var self = this;
			this.scanning    = true;
			this.scanAborted = false;
			this.currentPage = 1;
			this.$startBtn.hide();
			this.$stopScanBtn.show();
			this.$checkBtn.prop( 'disabled', true );
			this.$progress.show().attr( 'aria-valuenow', 0 );
			this.updateProgress( 0, tsoliinData.i18n.scanning );
			this.scanBatch( 1 );
		},

		stopScan: function () {
			this.scanAborted = true;
			this.scanning    = false;
			if ( this.scanXhr && this.scanXhr.readyState !== 4 ) {
				this.scanXhr.abort();
			}
			this.scanXhr = null;
			this.$stopScanBtn.hide();
			this.resetScanButton();
			this.$startBtn.show();
			if ( ! parseInt( tsoliinData.bgRunning, 10 ) ) {
				this.$checkBtn.prop( 'disabled', false );
			}
			this.updateProgress( 0, tsoliinData.i18n.scanStopped );
			var self = this;
			setTimeout( function () {
				self.$progress.fadeOut( 400 );
			}, 2000 );
		},

		scanBatch: function ( pageNum ) {
			var self = this;
			if ( self.scanAborted ) {
				return;
			}
			self.scanXhr = $.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				data   : { action: 'tsoliin_scan_batch', nonce: tsoliinData.nonce, page_num: pageNum },
				success: function ( r ) {
					if ( self.scanAborted ) {
						return;
					}
					if ( ! r.success ) {
						self.scanError( r.data ? r.data.message : tsoliinData.i18n.error );
						return;
					}
					self.updateProgress( r.data.progress, r.data.message );
					if ( r.data.done ) {
						self.scanDone();
					} else {
						self.scanBatch( r.data.next_page );
					}
				},
				error: function ( xhr, status ) {
					if ( self.scanAborted || 'abort' === status ) {
						return;
					}
					self.scanError( tsoliinData.i18n.error );
				},
				complete: function () {
					self.scanXhr = null;
				}
			} );
		},

		updateProgress: function ( pct, label ) {
			pct = Math.min( 100, Math.max( 0, pct ) );
			this.$progressBar.css( 'width', pct + '%' );
			this.$progressLbl.text( pct + '% – ' + label );
			this.$progress.attr( 'aria-valuenow', pct );
		},

		scanDone: function () {
			if ( this.scanAborted ) {
				return;
			}
			this.scanning = false;
			this.$stopScanBtn.hide();
			this.$startBtn.show();
			this.updateProgress( 100, tsoliinData.i18n.scanDone );
			this.resetScanButton();
			var self = this;
			setTimeout( function () {
				if ( self.scanAborted ) {
					return;
				}
				self.showNotice( '✅ ' + tsoliinData.i18n.scanThenCheck, 'success' );
				self.$checkProg.hide();
				self.$checkBar.css( 'width', '0%' );
				self.$checkLbl.text( '' );
				self.startBgCheck( true );
			}, 800 );
		},

		scanError: function ( msg ) {
			this.scanning = false;
			this.$stopScanBtn.hide();
			this.$startBtn.show();
			this.resetScanButton();
			if ( ! parseInt( tsoliinData.bgRunning, 10 ) ) {
				this.$checkBtn.prop( 'disabled', false );
			}
			this.$progressLbl.text( msg );
			this.$progressBar.css( { 'width': '100%', 'background': '#cc1818' } );
		},

		// ---------------------------------------------------------------
		// Background check – server does the work, browser just polls
		// ---------------------------------------------------------------
		startBgCheck: function ( skipConfirm ) {
			var self = this;
			var postId = parseInt( tsoliinData.viewPostId, 10 ) || 0;

			if ( ! skipConfirm ) {
				if ( postId <= 0 && ! window.confirm( tsoliinData.i18n.confirmFullCheck ) ) {
					return;
				}
			}

			this.completed = false;

			this.$checkBtn.prop( 'disabled', true );
			this.$startBtn.prop( 'disabled', true );
			this.$stopBtn.show();
			this.$checkProg.show();
			this.updateCheckProgress( 0, tsoliinData.i18n.checking );

			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : {
					action   : 'tsoliin_start_bg_check',
					nonce    : tsoliinData.nonce,
					resume   : '0',
					post_id  : postId
				},
				success: function ( r ) {
					if ( r.success ) {
						tsoliinData.bgRunning = 1;
						self.showNotice(
							'✅ ' + ( r.data.message || tsoliinData.i18n.checkStarted ),
							'success'
						);
						self.startPolling();
					} else {
						self.resetCheckButton();
						self.$startBtn.prop( 'disabled', false );
						self.$stopBtn.hide();
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
					}
				},
				error: function () {
					self.resetCheckButton();
					self.$startBtn.prop( 'disabled', false );
					self.$stopBtn.hide();
					alert( tsoliinData.i18n.error );
				}
			} );
		},

		stopBgCheck: function () {
			var self = this;
			this.stopPolling();
			tsoliinData.bgRunning = 0;
			this.resetCheckButton();
			this.$startBtn.prop( 'disabled', false );
			this.$stopBtn.hide();
			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : { action: 'tsoliin_stop_bg_check', nonce: tsoliinData.nonce },
				success: function () {
					self.updateCheckProgress( 0, tsoliinData.i18n.stopped );
					setTimeout( function () {
						self.$checkProg.fadeOut( 400 );
					}, 2500 );
				}
			} );
		},

		startPolling: function () {
			if ( this.polling ) { return; }
			this.polling = true;
			this.pollProgress();
		},

		stopPolling: function () {
			this.polling = false;
			if ( this.pollTimer ) {
				clearTimeout( this.pollTimer );
				this.pollTimer = null;
			}
		},

		pollProgress: function () {
			if ( ! this.polling || this.completed ) { return; }
			var self = this;

			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : { action: 'tsoliin_check_progress', nonce: tsoliinData.nonce },
				success: function ( r ) {
					if ( ! r.success || self.completed ) {
						self.stopPolling();
						if ( ! r.success ) {
							self.$checkBtn.prop( 'disabled', false );
							self.showNotice( tsoliinData.i18n.error, 'error' );
						}
						return;
					}
					var d = r.data;

					self.updateCheckProgress( d.pct, d.message );

					if ( d.done ) {
						// Set completed flag BEFORE stopping — prevents any race condition.
						self.completed = true;
						self.stopPolling();
						self.checkDone();
					} else if ( d.running ) {
						// Still running, poll again in 5s.
						self.pollTimer = setTimeout( function () { self.pollProgress(); }, 5000 );
					} else {
						// Server stopped (manual stop or error), don't reload.
						self.stopPolling();
						tsoliinData.bgRunning = 0;
						self.$stopBtn.hide();
						self.resetCheckButton();
						self.$startBtn.prop( 'disabled', false );
					}
				},
				error: function () {
					// Network error, retry in 10s.
					if ( self.polling && ! self.completed ) {
						self.pollTimer = setTimeout( function () { self.pollProgress(); }, 10000 );
					}
				}
			} );
		},

		updateCheckProgress: function ( pct, label ) {
			pct = Math.min( 100, Math.max( 0, pct ) );
			this.$checkBar.css( 'width', pct + '%' );
			this.$checkLbl.text( pct + '% – ' + label );
			this.$checkProg.attr( 'aria-valuenow', pct );
		},

		checkDone: function () {
			// Guard: only execute once even if called multiple times.
			if ( ! this.completed ) { return; }
			tsoliinData.bgRunning = 0;
			this.$stopBtn.hide();
			this.$startBtn.prop( 'disabled', false );
			this.updateCheckProgress( 100, tsoliinData.i18n.checkDone );
			this.$checkBtn.prop( 'disabled', false ).html(
				'<span class="dashicons dashicons-yes-alt"></span> ' + tsoliinData.i18n.checkDone
			);
			// Reload once to show final stats. completed flag prevents loop.
			setTimeout( function () {
				window.location.reload();
			}, 2000 );
		},

		// ---------------------------------------------------------------
		// Live stat + filter tab counts
		// ---------------------------------------------------------------
		applyStatsToUI: function ( stats, display ) {
			if ( ! stats ) {
				return;
			}
			var tabMap = {
				all                : 'total',
				broken             : 'broken',
				redirect           : 'redirect',
				ok                 : 'ok',
				unchecked          : 'unchecked',
				http_insecure      : 'http_insecure',
				manual_locked      : 'manual_locked',
				empty_anchor       : 'empty_anchor',
				generic_anchor     : 'generic_anchor',
				unpublished_target : 'unpublished_target'
			};
			var qualityKeys = {
				empty_anchor       : true,
				generic_anchor     : true,
				unpublished_target : true
			};
			var getFilterKeyFromHref = function ( href ) {
				if ( ! href || href.indexOf( 'filter=' ) === -1 ) {
					return 'all';
				}
				var match = href.match( /[?&]filter=([^&]+)/ );
				return match ? match[1] : 'all';
			};
			var getQualityKeyFromHref = function ( href ) {
				if ( ! href || href.indexOf( 'quality_filter=' ) === -1 ) {
					return '';
				}
				var match = href.match( /[?&]quality_filter=([^&]+)/ );
				return match ? match[1] : '';
			};
			var updateTabLabels = function ( selector, templateMap, mode ) {
				$.each( tabMap, function ( filterKey, statKey ) {
					if ( undefined === stats[ statKey ] ) {
						return;
					}
					var count = ( display && display[ statKey ] ) ? display[ statKey ] : stats[ statKey ];
					$( selector + ' a' ).each( function () {
						var href = $( this ).attr( 'href' ) || '';
						if ( 'quality' === mode ) {
							if ( getQualityKeyFromHref( href ) !== filterKey ) {
								return;
							}
						} else if ( qualityKeys[ filterKey ] ) {
							return;
						} else if ( getFilterKeyFromHref( href ) !== filterKey ) {
							return;
						}
						var template = templateMap && templateMap[ filterKey ];
						if ( template ) {
							$( this ).text( template.replace( '%s', count ) );
						} else {
							var label = $( this ).text().replace( /\([^)]*\)/, '(' + count + ')' );
							$( this ).text( label );
						}
					} );
				} );
			};
			updateTabLabels( '.tsoliin-filter-tabs', tsoliinData.filterTabs, 'status' );
			updateTabLabels( '.tsoliin-quality-tabs', tsoliinData.qualityFilterTabs, 'quality' );

			var cardMap = {
				total          : '.tsoliin-stats > .tsoliin-stat:first .tsoliin-stat__number',
				broken         : '.tsoliin-stat--broken .tsoliin-stat__number',
				redirect       : '.tsoliin-stat--redirect .tsoliin-stat__number',
				ok             : '.tsoliin-stat--ok .tsoliin-stat__number',
				unchecked      : '.tsoliin-stat--unchecked .tsoliin-stat__number',
				http_insecure  : '.tsoliin-stat--http-insecure .tsoliin-stat__number'
			};
			$.each( cardMap, function ( statKey, selector ) {
				if ( undefined === stats[ statKey ] ) {
					return;
				}
				var $el = $( selector );
				if ( $el.length ) {
					$el.text( display && display[ statKey ] ? display[ statKey ] : stats[ statKey ] );
				}
			} );
		},

		refreshStats: function () {
			if ( ! this.$form.length ) {
				return;
			}
			var self = this;
			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : {
					action  : 'tsoliin_get_stats',
					nonce   : tsoliinData.nonce,
					post_id : parseInt( tsoliinData.viewPostId, 10 ) || 0
				},
				success: function ( r ) {
					if ( ! r.success || ! r.data ) {
						return;
					}
					self.applyStatsToUI( r.data.stats, r.data.display );
				}
			} );
		},

		showNotice: function ( msg, type ) {
			$( '.tsoliin-notice' ).remove();
			var $n = $( '<div class="notice notice-' + ( type || 'info' ) + ' is-dismissible tsoliin-notice"><p>' + msg + '</p><button type="button" class="notice-dismiss"></button></div>' );
			$( '.tsoliin-toolbar' ).after( $n );
			$n.on( 'click', '.notice-dismiss', function ( e ) {
				e.preventDefault();
				$n.fadeOut( 200, function () { $n.remove(); } );
			} );
		},

		/**
		 * Reload the list table so pagination and row counts match the database.
		 * Stat cards alone cannot refresh WP_List_Table tablenav output.
		 *
		 * @param {number} delay Ms before reload (lets the user read a short message).
		 */
		scheduleListReload: function ( delay ) {
			var self = this;
			self.cancelListReload();
			self.listReloadTimer = window.setTimeout( function () {
				self.listReloadTimer = null;
				window.location.reload();
			}, delay || 1200 );
		},

		/**
		 * Cancel a pending full-page list reload (e.g. user starts another action).
		 */
		cancelListReload: function () {
			if ( this.listReloadTimer ) {
				window.clearTimeout( this.listReloadTimer );
				this.listReloadTimer = null;
			}
		},

		countListRows: function () {
			return this.$form.find( 'tbody tr' ).not( '.tsoliin-suggest-row' ).length;
		},

		maybeReloadEmptyList: function () {
			if ( 0 === this.countListRows() ) {
				this.scheduleListReload( 800 );
			}
		},

		listFilterParam: function () {
			var params = { list_filter: tsoliinData.listFilter || 'all' };
			if ( tsoliinData.listQualityFilter ) {
				params.list_quality_filter = tsoliinData.listQualityFilter;
			}
			if ( tsoliinData.listScope && 'all' !== tsoliinData.listScope ) {
				params.list_scope = tsoliinData.listScope;
			}
			return params;
		},

		removeRowIfFilterMismatch: function ( $row, data, skipReload ) {
			if ( ! $row || ! $row.length || ! data || false !== data.matches_filter ) {
				return false;
			}
			if ( 'all' === ( tsoliinData.listFilter || 'all' ) && ! tsoliinData.listQualityFilter ) {
				return false;
			}
			var self = this;
			self.removeSuggestPanelsForRow( $row );
			$row.fadeOut( 300, function () {
				$( this ).remove();
				if ( skipReload ) {
					return;
				}
				// Do not full-reload the page here: it aborts in-flight Suggest/Apply on the next row.
				// Stats tabs refresh via refreshStats(); reload only when the list is empty.
				self.maybeReloadEmptyList();
			} );
			return true;
		},

		// ---------------------------------------------------------------
		// Recheck single row
		// ---------------------------------------------------------------
		recheckLink: function ( linkId, $trigger ) {
			var self    = this;
			var $row    = $trigger.closest( 'tr' );
			var $status = $row.find( '.column-status_code' );
			var $chk    = $row.find( '.column-last_checked' );

			$trigger.text( tsoliinData.i18n.rechecking );
			$status.html( '<em>...</em>' );

			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( { action: 'tsoliin_recheck', nonce: tsoliinData.nonce, link_id: linkId }, self.listFilterParam() ),
				success: function ( r ) {
					if ( ! r.success ) {
						$status.html( '<span class="tsoliin-status tsoliin-status--broken">' + tsoliinData.i18n.error + '</span>' );
						$trigger.text( tsoliinData.i18n.recheck );
						return;
					}
					var d = r.data;
					if ( d.removed ) {
						$row.fadeOut( 300, function () {
							$( this ).remove();
							self.refreshStats();
							self.maybeReloadEmptyList();
						} );
						return;
					}
					if ( self.removeRowIfFilterMismatch( $row, d ) ) {
						self.refreshStats();
						$trigger.text( tsoliinData.i18n.recheck );
						return;
					}
					if ( d.new_url ) {
						self.applyLinkEditToRow( $row, d );
					} else if ( d.status_html ) {
						$status.html( d.status_html );
					} else {
						$status.html( '<span class="tsoliin-status ' + d.css_class + '">' + d.status_code + ' ' + d.label + '</span>' );
					}
					if ( d.link_id && parseInt( d.link_id, 10 ) !== linkId ) {
						$row.find( '[data-id="' + linkId + '"]' ).attr( 'data-id', d.link_id );
					}
					$chk.text( d.last_checked );
					$trigger.text( tsoliinData.i18n.recheck );
					$row.toggleClass( 'tsoliin-row--broken', 1 === d.is_broken );
					self.refreshStats();
				},
				error: function () {
					$status.html( '<span class="tsoliin-status tsoliin-status--broken">' + tsoliinData.i18n.error + '</span>' );
					$trigger.text( tsoliinData.i18n.recheck );
				}
			} );
		},

		// ---------------------------------------------------------------
		// Edit modal
		// ---------------------------------------------------------------
		openModal: function ( linkId, oldUrl, postId, anchorText, linkType, anchorEditable ) {
			this.editLinkId   = linkId;
			this.editOldUrl   = oldUrl;
			this.editPostId   = postId;
			this.editLinkType = linkType || 'link';
			this.editAnchorEditable = false !== anchorEditable;
			this.$modalOldUrl.text( oldUrl );
			this.$newUrlInput.val( oldUrl );
			if ( this.$anchorRow.length ) {
				if ( 'iframe' === this.editLinkType ) {
					this.$anchorRow.hide();
				} else {
					this.$anchorRow.show();
					if ( this.editAnchorEditable ) {
						this.$anchorLabel.text(
							'image' === this.editLinkType
								? ( tsoliinData.i18n.altText || 'Alt text:' )
								: ( tsoliinData.i18n.linkText || 'Link text:' )
						);
						this.$newAnchorInput.prop( 'readonly', false ).removeClass( 'tsoliin-input--readonly' );
						if ( this.$anchorNote.length ) {
							this.$anchorNote.text( '' ).hide();
						}
					} else {
						this.$anchorLabel.text( tsoliinData.i18n.commentLabel || 'Inspector label (read-only):' );
						this.$newAnchorInput.prop( 'readonly', true ).addClass( 'tsoliin-input--readonly' );
						if ( this.$anchorNote.length ) {
							this.$anchorNote.text( tsoliinData.i18n.commentLabelNote || '' ).show();
						}
					}
				}
			}
			if ( this.$newAnchorInput.length ) {
				this.$newAnchorInput.val( anchorText || '' );
			}
			this.$feedback.text( '' ).removeClass( 'is-error is-success is-warning' );
			if ( this.canPreviewLinkEdit() ) {
				this.$previewPanel.show();
				this.scheduleLinkPreview();
			} else if ( this.$previewPanel.length ) {
				this.$previewPanel.hide();
			}
			if ( this.$revisionNote.length ) {
				if ( tsoliinData.createRevision && this.editPostId > 0 && -1 !== [ 'link', 'image', 'iframe' ].indexOf( this.editLinkType ) ) {
					this.$revisionNote.text( tsoliinData.i18n.revisionModalNote || '' ).show();
				} else {
					this.$revisionNote.text( '' ).hide();
				}
			}
			this.$modal.show();
			this.$newUrlInput.trigger( 'focus' );
		},

		closeModal: function () {
			if ( this.previewTimer ) {
				window.clearTimeout( this.previewTimer );
				this.previewTimer = null;
			}
			this.$modal.hide();
			this.editLinkId = 0;
		},

		canPreviewLinkEdit: function () {
			return this.$previewPanel.length
				&& this.editPostId > 0
				&& -1 !== [ 'link', 'image', 'iframe' ].indexOf( this.editLinkType );
		},

		scheduleLinkPreview: function () {
			var self = this;
			if ( ! this.canPreviewLinkEdit() || ! this.editLinkId ) {
				return;
			}
			if ( this.previewTimer ) {
				window.clearTimeout( this.previewTimer );
			}
			this.$previewBefore.text( tsoliinData.i18n.previewLoading || 'Loading preview...' );
			this.$previewAfter.text( '' );
			this.previewTimer = window.setTimeout( function () {
				self.fetchLinkPreview();
			}, 350 );
		},

		fetchLinkPreview: function () {
			var self   = this;
			var newUrl = this.$newUrlInput.val().trim();
			if ( ! newUrl ) {
				return;
			}
			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : {
					action  : 'tsoliin_link_preview',
					nonce   : tsoliinData.nonce,
					link_id : self.editLinkId,
					new_url : newUrl
				},
				success: function ( r ) {
					if ( ! r.success || ! r.data ) {
						self.$previewBefore.text( r.data && r.data.message ? r.data.message : tsoliinData.i18n.error );
						self.$previewAfter.text( '' );
						return;
					}
					if ( ! r.data.found ) {
						self.$previewBefore.text( tsoliinData.i18n.previewNotFound || 'No matching HTML tag found in the post for this URL.' );
						self.$previewAfter.text( '' );
						return;
					}
					self.$previewBefore.text( r.data.before || '' );
					if ( r.data.after ) {
						self.$previewAfter.text( r.data.after );
					} else if ( r.data.before ) {
						self.$previewAfter.text( newUrl );
					} else {
						self.$previewAfter.text( '' );
					}
				},
				error: function () {
					self.$previewBefore.text( tsoliinData.i18n.error );
					self.$previewAfter.text( '' );
				}
			} );
		},

		applyLinkEditToRow: function ( $row, d ) {
			if ( ! $row.length || ! d ) {
				return;
			}
			if ( d.new_url ) {
				var display = d.new_url.length > 110 ? d.new_url.substring( 0, 107 ) + '...' : d.new_url;
				var $link   = $row.find( '.tsoliin-url a' );
				var $icon   = $link.find( '.dashicons' ).detach();
				$link.attr( 'href', d.new_url ).attr( 'title', d.new_url );
				$link.text( display + ' ' );
				$link.append( $icon );
				$row.find( '.tsoliin-edit-link' ).attr( 'data-url', d.new_url ).data( 'url', d.new_url );
				this.editOldUrl = d.new_url;
			}
			if ( undefined !== d.new_anchor && null !== d.new_anchor ) {
				$row.find( '.column-anchor_text' ).text( d.new_anchor );
				$row.find( '.tsoliin-edit-link' ).attr( 'data-anchor', d.new_anchor ).data( 'anchor', d.new_anchor );
			}
			if ( d.status_html ) {
				$row.find( '.column-status_code' ).html( d.status_html );
			} else if ( undefined !== d.status_code && undefined !== d.css_class && undefined !== d.label ) {
				$row.find( '.column-status_code' ).html( '<span class="tsoliin-status ' + d.css_class + '">' + parseInt( d.status_code, 10 ) + ' ' + this.escapeHtml( d.label ) + '</span>' );
			}
			if ( undefined !== d.is_broken ) {
				$row.toggleClass( 'tsoliin-row--broken', 1 === d.is_broken );
			}
		},

		saveLink: function () {
			var self       = this;
			var newUrl     = this.$newUrlInput.val().trim();
			var newAnchor  = this.$newAnchorInput.length ? this.$newAnchorInput.val().trim() : '';
			if ( ! newUrl ) {
				this.$feedback.text( tsoliinData.i18n.urlRequired ).addClass( 'is-error' );
				return;
			}

			this.$modalSave.prop( 'disabled', true ).text( tsoliinData.i18n.saving );
			this.$modalSpinner.addClass( 'is-active' );
			this.$feedback.text( '' ).removeClass( 'is-error is-success' );

			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( {
					action     : 'tsoliin_update_link',
					nonce      : tsoliinData.nonce,
					link_id    : self.editLinkId,
					new_url    : newUrl,
					new_anchor : newAnchor
				}, self.listFilterParam() ),
				success: function ( r ) {
					self.$modalSave.prop( 'disabled', false ).text( tsoliinData.i18n.save );
					self.$modalSpinner.removeClass( 'is-active' );
					if ( ! r.success ) {
						self.$feedback.text( r.data ? r.data.message : tsoliinData.i18n.error ).addClass( 'is-error' );
						return;
					}
					var d    = r.data || {};
					var msg  = d.filter_promotion_message || ( d.warning ? d.warning : ( '✓ ' + tsoliinData.i18n.urlSaved ) );
					if ( ! d.warning && tsoliinData.createRevision && self.editPostId > 0 && -1 !== [ 'link', 'image', 'iframe' ].indexOf( self.editLinkType ) ) {
						msg += ' ' + ( tsoliinData.i18n.revisionSaved || '' );
					}
					var $row = $( 'tr' ).filter( function () {
						return $( this ).find( '.tsoliin-edit-link[data-id="' + self.editLinkId + '"]' ).length > 0;
					} );
					if ( $row.length ) {
						if ( self.removeRowIfFilterMismatch( $row, d ) ) {
							self.$feedback.text( msg ).addClass( d.warning ? 'is-warning' : 'is-success' );
							setTimeout( function () { self.closeModal(); }, 800 );
							self.refreshStats();
							return;
						}
						self.applyLinkEditToRow( $row, d );
					}
					self.$feedback.text( msg ).addClass( d.warning ? 'is-warning' : 'is-success' );
					setTimeout( function () { self.closeModal(); }, d.warning ? 1800 : 1200 );
					self.refreshStats();
				},
				error: function () {
					self.$modalSave.prop( 'disabled', false ).text( tsoliinData.i18n.save );
					self.$modalSpinner.removeClass( 'is-active' );
					self.$feedback.text( tsoliinData.i18n.error ).addClass( 'is-error' );
				}
			} );
		},

		// ---------------------------------------------------------------
		// Unlink / Delete
		// ---------------------------------------------------------------
		unlinkItem: function ( linkId, $trigger ) {
			var self = this;
			var $row = $trigger.closest( 'tr' );
			$trigger.text( tsoliinData.i18n.saving );
			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : { action: 'tsoliin_unlink', nonce: tsoliinData.nonce, link_id: linkId },
				success: function ( r ) {
					if ( r.success ) {
						self.removeSuggestPanelsForRow( $row );
						$row.fadeOut( 400, function () {
							$( this ).remove();
							self.refreshStats();
							self.maybeReloadEmptyList();
						} );
					} else {
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
						$trigger.text( tsoliinData.i18n.unlink );
					}
				},
				error: function () { alert( tsoliinData.i18n.error ); $trigger.text( tsoliinData.i18n.unlink ); }
			} );
		},

		// ---------------------------------------------------------------
		// Ignore list (row action)
		// ---------------------------------------------------------------
		addToIgnoreList: function ( $trigger ) {
			var self     = this;
			var linkId   = parseInt( $trigger.data( 'id' ), 10 );
			var pattern  = $trigger.data( 'pattern' ) || '';
			var $row     = $trigger.closest( 'tr' );
			var confirmT = ( tsoliinData.i18n.confirmAddIgnore || 'Add %s to the ignore list?' ).replace( '%s', pattern );

			if ( ! window.confirm( confirmT ) ) {
				return;
			}

			$trigger.text( tsoliinData.i18n.saving || 'Saving...' );
			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( {
					action  : 'tsoliin_add_ignore',
					nonce   : tsoliinData.nonce,
					link_id : linkId,
					pattern : pattern
				}, self.listFilterParam() ),
				success: function ( r ) {
					if ( ! r.success ) {
						self.showNotice( r.data ? r.data.message : tsoliinData.i18n.error, 'error' );
						$trigger.text( tsoliinData.i18n.addIgnore || 'Ignore domain' );
						return;
					}
					var d = r.data || {};
					if ( self.removeRowIfFilterMismatch( $row, d ) ) {
						self.showNotice( d.message, 'success' );
						self.refreshStats();
						return;
					}
					if ( d.status_html ) {
						$row.find( '.column-status_code' ).html( d.status_html );
					}
					$row.removeClass( 'tsoliin-row--broken' );
					$trigger.closest( 'span' ).remove();
					self.showNotice( d.message, 'success' );
					self.refreshStats();
				},
				error: function () {
					self.showNotice( tsoliinData.i18n.error, 'error' );
					$trigger.text( tsoliinData.i18n.addIgnore || 'Ignore domain' );
				}
			} );
		},

		dismissOnboarding: function ( $banner ) {
			var self = this;
			if ( $banner && $banner.length ) {
				$banner.fadeOut( 200, function () { $banner.remove(); } );
			}
			$.post( tsoliinData.ajaxUrl, {
				action: 'tsoliin_dismiss_onboarding',
				nonce : tsoliinData.nonce
			} );
		},

		makeRelative: function ( linkId, $trigger ) {
			var self = this;
			var $row = $trigger.closest( 'tr' );
			$trigger.text( tsoliinData.i18n.saving );
			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( {
					action  : 'tsoliin_make_relative',
					nonce   : tsoliinData.nonce,
					link_id : linkId
				}, self.listFilterParam() ),
				success: function ( r ) {
					if ( ! r.success ) {
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
						$trigger.text( tsoliinData.i18n.makeRelative || 'Convert to /path' );
						return;
					}
					var d = r.data || {};
					if ( self.removeRowIfFilterMismatch( $row, d ) ) {
						self.showNotice( d.message, 'success' );
						self.refreshStats();
						return;
					}
					self.applyLinkEditToRow( $row, d );
					$trigger.closest( 'span' ).remove();
					self.showNotice( d.message, 'success' );
					self.refreshStats();
				},
				error: function () {
					alert( tsoliinData.i18n.error );
					$trigger.text( tsoliinData.i18n.makeRelative || 'Use relative URL' );
				}
			} );
		},

		// ---------------------------------------------------------------
		// Mark as not broken
		// ---------------------------------------------------------------
		markNotBroken: function ( linkId, $trigger ) {
			var self = this;
			var $row = $trigger.closest( 'tr' );
			$trigger.text( tsoliinData.i18n.saving );
			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( { action: 'tsoliin_not_broken', nonce: tsoliinData.nonce, link_id: linkId }, self.listFilterParam() ),
				success: function ( r ) {
					if ( r.success ) {
						if ( self.removeRowIfFilterMismatch( $row, r.data ) ) {
							$trigger.text( tsoliinData.i18n.notBroken );
							self.refreshStats();
							return;
						}
						if ( r.data.status_html ) {
							$row.find( '.column-status_code' ).html( r.data.status_html );
						} else {
							$row.find( '.column-status_code' ).html( '<span class="tsoliin-status tsoliin-status--ok">' + r.data.status_code + ' ' + r.data.label + '</span>' );
						}
						$row.removeClass( 'tsoliin-row--broken' );
						$row.find( '.tsoliin-not-broken' ).closest( 'span' ).remove();
						$row.find( '.tsoliin-suggest' ).closest( 'span' ).remove();
						if ( r.data && r.data.message ) {
							self.showNotice( r.data.message, 'success' );
						}
						self.refreshStats();
					} else {
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
						$trigger.text( tsoliinData.i18n.notBroken );
					}
				},
				error: function () { alert( tsoliinData.i18n.error ); $trigger.text( tsoliinData.i18n.notBroken ); }
			} );
		},

		deleteItem: function ( linkId, $trigger ) {
			var self = this;
			var $row = $trigger.closest( 'tr' );
			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : { action: 'tsoliin_delete_link', nonce: tsoliinData.nonce, link_id: linkId },
				success: function ( r ) {
					if ( r.success ) {
						self.removeSuggestPanelsForRow( $row );
						$row.fadeOut( 400, function () {
							$( this ).remove();
							self.refreshStats();
							self.maybeReloadEmptyList();
						} );
					} else {
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
					}
				},
				error: function () { alert( tsoliinData.i18n.error ); }
			} );
		},

		// ---------------------------------------------------------------
		// Bulk
		// ---------------------------------------------------------------
		doBulkAction: function ( action ) {
			var self    = this;
			var linkIds = [];
			this.$form.find( 'input[name="link_ids[]"]:checked' ).each( function () {
				linkIds.push( parseInt( $( this ).val(), 10 ) );
			} );
			if ( ! linkIds.length ) { return; }

			if ( 'unlink' === action && ! window.confirm( tsoliinData.i18n.confirmUnlink ) ) {
				return;
			}
			if ( 'delete' === action && ! window.confirm( tsoliinData.i18n.confirmDeleteBulk || tsoliinData.i18n.confirmDelete ) ) {
				return;
			}

			if ( 'recheck' === action || 'unlink' === action || 'make_relative' === action ) {
				if ( 'make_relative' === action && ! window.confirm( tsoliinData.i18n.confirmMakeRelativeBulk ) ) {
					return;
				}
				self.bulkRecheckStep( linkIds, 0, action );
			} else if ( 'not_broken' === action ) {
				if ( ! window.confirm( tsoliinData.i18n.confirmNotBrokenBulk ) ) { return; }
				$.ajax( {
					url   : tsoliinData.ajaxUrl,
					method: 'POST',
					data  : $.extend( { action: 'tsoliin_bulk_action', nonce: tsoliinData.nonce, bulk_action: 'not_broken', link_ids: linkIds, index: 0 }, self.listFilterParam() ),
					success: function ( r ) {
						if ( r.success ) {
							if ( 'all' !== ( tsoliinData.listFilter || 'all' ) ) {
								self.scheduleListReload( 1200 );
							} else {
								self.$form.find( 'input[name="link_ids[]"]:checked' ).closest( 'tr' ).each( function () {
									$( this ).find( '.column-status_code' ).html( '<span class="tsoliin-status tsoliin-status--ok">200 OK (manual)</span>' );
									$( this ).removeClass( 'tsoliin-row--broken' );
								} );
								self.showNotice( r.data.message, 'success' );
								self.refreshStats();
							}
						} else { alert( r.data ? r.data.message : tsoliinData.i18n.error ); }
					},
					error: function () { alert( tsoliinData.i18n.error ); }
				} );
			} else {
				$.ajax( {
					url   : tsoliinData.ajaxUrl,
					method: 'POST',
					data  : $.extend( { action: 'tsoliin_bulk_action', nonce: tsoliinData.nonce, bulk_action: action, link_ids: linkIds, index: 0 }, self.listFilterParam() ),
					success: function ( r ) {
						if ( r.success ) {
							self.$form.find( 'input[name="link_ids[]"]:checked' ).closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } );
							self.showNotice( r.data.message, 'success' );
							self.scheduleListReload( 1200 );
						} else { alert( r.data ? r.data.message : tsoliinData.i18n.error ); }
					},
					error: function () { alert( tsoliinData.i18n.error ); }
				} );
			}
		},

		/**
		 * Process bulk recheck one link at a time with live row updates.
		 */
		bulkRecheckStep: function ( linkIds, index, action ) {
			var self  = this;
			var total = linkIds.length;
			var act   = action || 'recheck';

			// Show progress notice on first call.
			if ( 0 === index ) {
				self._bulkStats = { unlinked: 0, skipped: 0, failed: 0, converted: 0 };
				self._bulkFilterRemoved = 0;
				var initMsg = tsoliinData.i18n.checking;
				if ( 'unlink' === act ) {
					initMsg = tsoliinData.i18n.unlinking;
				} else if ( 'make_relative' === act ) {
					initMsg = tsoliinData.i18n.convertingRelative || 'Converting to /path…';
				}
				self.$bulkProgress = $( '<div class="notice notice-info tsoliin-notice"><p><strong id="tsoliin-bulk-msg">' + initMsg + '</strong> <progress id="tsoliin-bulk-bar" max="100" value="0" style="width:200px;vertical-align:middle;"></progress></p></div>' );
				$( '.tsoliin-toolbar' ).after( self.$bulkProgress );
			}

			if ( index >= total ) {
				var doneMsg;
				if ( 'unlink' === act ) {
					var parts = [];
					if ( self._bulkStats.unlinked > 0 ) {
						parts.push( '✅ ' + self._bulkStats.unlinked + ' ' + tsoliinData.i18n.itemsUnlinked );
					}
					if ( self._bulkStats.skipped > 0 ) {
						parts.push( '⚠ ' + self._bulkStats.skipped + ' ' + tsoliinData.i18n.itemsSkipped );
					}
					if ( self._bulkStats.failed > 0 ) {
						parts.push( '❌ ' + self._bulkStats.failed + ' ' + tsoliinData.i18n.itemsFailed );
					}
					doneMsg = parts.length ? parts.join( ' ' ) : ( '✅ 0 ' + tsoliinData.i18n.itemsUnlinked );
				} else if ( 'make_relative' === act ) {
					var relParts = [];
					if ( self._bulkStats.converted > 0 ) {
						relParts.push( '✅ ' + self._bulkStats.converted + ' ' + ( tsoliinData.i18n.itemsConverted || 'links converted to /path.' ) );
					}
					if ( self._bulkStats.skipped > 0 ) {
						relParts.push( '⚠ ' + self._bulkStats.skipped + ' ' + tsoliinData.i18n.itemsSkipped );
					}
					if ( self._bulkStats.failed > 0 ) {
						relParts.push( '❌ ' + self._bulkStats.failed + ' ' + tsoliinData.i18n.itemsFailed );
					}
					doneMsg = relParts.length ? relParts.join( ' ' ) : ( '✅ 0 ' + ( tsoliinData.i18n.itemsConverted || 'links converted to /path.' ) );
				} else {
					doneMsg = '✅ ' + total + ' ' + tsoliinData.i18n.itemsChecked;
				}
				$( '#tsoliin-bulk-msg' ).text( doneMsg );
				$( '#tsoliin-bulk-bar' ).val( 100 );
				if ( 'unlink' === act || 'make_relative' === act ) {
					self.scheduleListReload( 1500 );
				} else if ( 'recheck' === act && 'all' !== ( tsoliinData.listFilter || 'all' ) && self._bulkFilterRemoved > 0 ) {
					self.scheduleListReload( 1500 );
				} else {
					self.refreshStats();
					setTimeout( function () {
						if ( self.$bulkProgress ) {
							self.$bulkProgress.fadeOut( 300, function () { $( this ).remove(); } );
							self.$bulkProgress = null;
						}
					}, 2500 );
				}
				return;
			}

			var progressLabel = tsoliinData.i18n.checking;
			if ( 'unlink' === act ) {
				progressLabel = tsoliinData.i18n.unlinking;
			} else if ( 'make_relative' === act ) {
				progressLabel = tsoliinData.i18n.convertingRelative || 'Converting to /path…';
			}
			var pct = Math.round( ( ( index + 1 ) / total ) * 100 );
			$( '#tsoliin-bulk-msg' ).text( progressLabel + ' ' + ( index + 1 ) + '/' + total );
			$( '#tsoliin-bulk-bar' ).val( pct );

			$.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				timeout: 30000,
				data   : $.extend( {
					action     : 'tsoliin_bulk_action',
					nonce      : tsoliinData.nonce,
					bulk_action: act,
					link_ids   : linkIds,
					index      : index
				}, self.listFilterParam() ),
				success: function ( r ) {
					if ( ! r.success ) {
						if ( 'unlink' === act || 'make_relative' === act ) {
							self._bulkStats.failed++;
						}
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
						self.bulkRecheckStep( linkIds, index + 1, act );
						return;
					}
					if ( 'recheck' === act && r.data.row ) {
							// Update row status live (use request ID — resync may assign a new DB row).
							var d         = r.data.row;
							var requestId = linkIds[ index ];
							var $tr       = $( 'tr' ).filter( function () {
								return $( this ).find( 'input[value="' + requestId + '"]' ).length > 0;
							} );
							if ( $tr.length ) {
								if ( d.removed ) {
									self.removeSuggestPanelsForRow( $tr );
									$tr.fadeOut( 200, function () { $( this ).remove(); } );
									self._bulkFilterRemoved++;
									self.refreshStats();
								} else if ( self.removeRowIfFilterMismatch( $tr, d, true ) ) {
									self._bulkFilterRemoved++;
									self.refreshStats();
								} else {
									if ( d.new_url ) {
										self.applyLinkEditToRow( $tr, d );
									} else if ( d.status_html ) {
										$tr.find( '.column-status_code' ).html( d.status_html );
									} else if ( undefined !== d.status_code && undefined !== d.css_class && undefined !== d.label ) {
										$tr.find( '.column-status_code' ).html( '<span class="tsoliin-status ' + d.css_class + '">' + parseInt( d.status_code, 10 ) + ' ' + self.escapeHtml( d.label ) + '</span>' );
									}
									$tr.find( '.column-last_checked' ).text( d.last_checked );
									$tr.toggleClass( 'tsoliin-row--broken', 1 === parseInt( d.is_broken, 10 ) );
									if ( d.link_id && parseInt( d.link_id, 10 ) !== parseInt( requestId, 10 ) ) {
										$tr.find( 'input[name="link_ids[]"]' ).val( d.link_id );
										$tr.find( '[data-id="' + requestId + '"]' ).attr( 'data-id', d.link_id );
									}
								}
							}
						} else if ( 'unlink' === act && r.data.link_id ) {
							if ( r.data.skipped ) {
								self._bulkStats.skipped++;
							} else if ( r.data.unlinked ) {
								self._bulkStats.unlinked++;
								var $tr2 = $( 'tr' ).filter( function () {
									return $( this ).find( 'input[value="' + r.data.link_id + '"]' ).length > 0;
								} );
								if ( $tr2.length ) {
									self.removeSuggestPanelsForRow( $tr2 );
									$tr2.fadeOut( 200, function () { $( this ).remove(); } );
								}
							} else {
								self._bulkStats.failed++;
							}
						} else if ( 'make_relative' === act && r.data.link_id ) {
							if ( r.data.skipped ) {
								self._bulkStats.skipped++;
							} else if ( r.data.converted ) {
								self._bulkStats.converted++;
								var $tr3 = $( 'tr' ).filter( function () {
									return $( this ).find( 'input[value="' + r.data.link_id + '"]' ).length > 0;
								} );
								if ( $tr3.length && r.data.row ) {
									if ( self.removeRowIfFilterMismatch( $tr3, r.data.row, true ) ) {
										self._bulkFilterRemoved++;
									} else {
										self.applyLinkEditToRow( $tr3, r.data.row );
									}
								}
							} else {
								self._bulkStats.failed++;
							}
						}
					self.bulkRecheckStep( linkIds, index + 1, act );
				},
				error: function () {
					if ( 'unlink' === act || 'make_relative' === act ) {
						self._bulkStats.failed++;
					}
					alert( tsoliinData.i18n.error );
					self.bulkRecheckStep( linkIds, index + 1, act );
				}
			} );
		},

		// ---------------------------------------------------------------
		// Live search (filter list as you type)
		// ---------------------------------------------------------------
		bindLiveSearch: function () {
			var self   = this;
			var $input = $( '.tsoliin-search-input' );
			var $form  = $( '#tsoliin-search-form' );
			if ( ! $input.length || ! $form.length ) {
				return;
			}

			self.lastSearchVal = $input.val();

			$input.on( 'input', function () {
				var term = $.trim( $( this ).val() );
				window.clearTimeout( self.searchTimer );
				self.searchTimer = window.setTimeout( function () {
					if ( term === self.lastSearchVal ) {
						return;
					}
					self.lastSearchVal = term;
					self.runLiveSearch( term );
				}, 400 );
			} );

			$form.on( 'submit', function ( e ) {
				e.preventDefault();
				var term = $.trim( $input.val() );
				window.clearTimeout( self.searchTimer );
				self.lastSearchVal = term;
				self.runLiveSearch( term );
			} );
		},

		runLiveSearch: function ( searchTerm ) {
			var self    = this;
			var $region = $( '#tsoliin-list-table-region' );
			var $btn    = $( '#tsoliin-search-form button[type="submit"]' );

			if ( ! $region.length ) {
				$( '#tsoliin-search-form' )[0].submit();
				return;
			}

			if ( self.searchXhr && self.searchXhr.readyState !== 4 ) {
				self.searchXhr.abort();
			}

			$region.addClass( 'tsoliin-list-table-region--loading' );
			$region.attr( 'aria-busy', 'true' );
			if ( $btn.length ) {
				$btn.prop( 'disabled', true ).text( tsoliinData.i18n.searching );
			}

			self.searchXhr = $.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				timeout: 60000,
				data   : {
					action         : 'tsoliin_search_list',
					nonce          : tsoliinData.nonce,
					s              : searchTerm,
					filter         : tsoliinData.listFilter || 'all',
					quality_filter : tsoliinData.listQualityFilter || '',
					scope          : tsoliinData.listScope || 'all',
					post_id : tsoliinData.viewPostId || 0,
					paged   : 1,
					orderby : tsoliinData.listOrderby || 'date_found',
					order   : tsoliinData.listOrder || 'DESC'
				},
				success: function ( r ) {
					if ( r.success && r.data && r.data.html ) {
						$region.html( r.data.html );
						self.updateSearchUrl( searchTerm );
					}
				},
				error: function ( xhr, status ) {
					if ( 'abort' !== status ) {
						alert( tsoliinData.i18n.error );
					}
				},
				complete: function () {
					$region.removeClass( 'tsoliin-list-table-region--loading' );
					$region.attr( 'aria-busy', 'false' );
					if ( $btn.length ) {
						$btn.prop( 'disabled', false ).text( tsoliinData.i18n.searchBtn || 'Search' );
					}
				}
			} );
		},

		updateSearchUrl: function ( searchTerm ) {
			if ( ! window.history || ! window.history.replaceState ) {
				return;
			}
			try {
				var url    = new URL( window.location.href );
				if ( searchTerm ) {
					url.searchParams.set( 's', searchTerm );
				} else {
					url.searchParams.delete( 's' );
				}
				url.searchParams.delete( 'paged' );
				window.history.replaceState( null, '', url.toString() );
			} catch ( e ) {
				// Ignore URL API errors on very old browsers.
			}
		},

		// ---------------------------------------------------------------
		// Smart URL Suggestion
		// ---------------------------------------------------------------
		smartSuggest: function ( linkId, $trigger ) {
			var self    = this;
			var $row    = $trigger.closest( 'tr' );
			var linkType = String( $trigger.data( 'link-type' ) || '' );
			self.cancelListReload();
			self.removeSuggestPanelsForRow( $row );

			// Navigation menu items: explain where to edit; do not search alternatives.
			if ( 'menu' === linkType ) {
				var colsMenu = $row.find( 'td' ).length;
				var noteMenu = ( tsoliinData.i18n.menuSuggestNote )
					? tsoliinData.i18n.menuSuggestNote
					: tsoliinData.i18n.noSuggestions;
				var htmlMenu = '<tr class="tsoliin-suggest-row" data-link-id="' + linkId + '"><td colspan="' + colsMenu + '" class="tsoliin-suggest-panel">';
				htmlMenu += '<div class="notice notice-info inline" style="margin:0;"><p style="margin:0.5em 0;">' + self.escapeHtml( noteMenu ) + '</p></div>';
				htmlMenu += '<button type="button" class="tsoliin-suggest-close button-link" aria-label="' + self.escapeHtml( tsoliinData.i18n.closePanel ) + '">✕</button>';
				htmlMenu += '</td></tr>';
				$row.after( htmlMenu );
				return;
			}

			// WooCommerce product fields: same — edit in the product screen only.
			if ( 'woocommerce' === linkType ) {
				var colsWoo = $row.find( 'td' ).length;
				var noteWoo = ( tsoliinData.i18n.wooSuggestNote )
					? tsoliinData.i18n.wooSuggestNote
					: tsoliinData.i18n.noSuggestions;
				var htmlWoo = '<tr class="tsoliin-suggest-row" data-link-id="' + linkId + '"><td colspan="' + colsWoo + '" class="tsoliin-suggest-panel">';
				htmlWoo += '<div class="notice notice-info inline" style="margin:0;"><p style="margin:0.5em 0;">' + self.escapeHtml( noteWoo ) + '</p></div>';
				htmlWoo += '<button type="button" class="tsoliin-suggest-close button-link" aria-label="' + self.escapeHtml( tsoliinData.i18n.closePanel ) + '">✕</button>';
				htmlWoo += '</td></tr>';
				$row.after( htmlWoo );
				return;
			}

			if ( self.suggestXhr && self.suggestXhr.readyState !== 4 ) {
				self.suggestXhr.abort();
				if ( self.suggestTrigger && self.suggestTrigger.length && self.suggestTrigger[0] !== $trigger[0] ) {
					self.suggestTrigger.html( '💡 ' + tsoliinData.i18n.smartSuggest );
				}
			}

			self.suggestTrigger   = $trigger;
			self.suggestRequestId = linkId;
			$trigger.text( tsoliinData.i18n.smartChecking );

			self.suggestXhr = $.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				timeout: 60000,
				data   : { action: 'tsoliin_smart_suggest', nonce: tsoliinData.nonce, link_id: linkId },
				success: function ( r ) {
					if ( self.suggestRequestId !== linkId ) {
						return;
					}
					$trigger.html( '💡 ' + tsoliinData.i18n.smartSuggest );

					var cols = $row.find( 'td' ).length;
					var html = '<tr class="tsoliin-suggest-row" data-link-id="' + linkId + '"><td colspan="' + cols + '" class="tsoliin-suggest-panel">';

					if ( ! r.success ) {
						html += '<span style="color:#b32d2e;">' + self.escapeHtml( ( r.data && r.data.message ) ? r.data.message : tsoliinData.i18n.error ) + '</span>';
					} else if ( ! r.data.suggestions || ! r.data.suggestions.length ) {
						if ( r.data.note ) {
							var noteCls = ( r.data.menu_only || r.data.woo_only ) ? 'notice-info' : 'notice-warning';
							html += '<div class="notice ' + noteCls + ' inline" style="margin:0;"><p style="margin:0.5em 0;">' + self.escapeHtml( r.data.note ) + '</p></div>';
						} else {
							html += '<span style="color:#646970;font-style:italic;">💡 ' + tsoliinData.i18n.noSuggestions + '</span>';
						}
					} else {
						html += '<strong>💡 ' + tsoliinData.i18n.smartSuggest + ':</strong><ul class="tsoliin-suggest-list">';
						$.each( r.data.suggestions, function ( i, s ) {
							var actionable = ( false !== s.actionable );
							var conf = 'high' === s.confidence ? '🟢' : '🟡';
							var isHttps = /^https:\/\//i.test( s.url || '' );
							var code = parseInt( s.status_code, 10 );
							var isBotBlock = ( 401 === code || 403 === code || 429 === code );
							if ( ! actionable || isBotBlock ) {
								conf = '⚠️';
							} else if ( ! isHttps ) {
								conf = '⚠️';
							}
							var urlAttr  = String( s.url ).replace( /"/g, '&quot;' );
							var urlLabel = self.escapeHtml( s.url );
							var statusCls = 'tsoliin-status--broken';
							if ( actionable && ! isBotBlock ) {
								statusCls = isHttps ? 'tsoliin-status--ok' : 'tsoliin-status--warning';
							} else if ( isBotBlock ) {
								statusCls = 'tsoliin-status--warning';
							}
							html += '<li>';
							html += conf + ' <a href="' + urlAttr + '" target="_blank" rel="noopener">' + urlLabel + '</a>';
							html += ' <span class="tsoliin-status ' + statusCls + '" style="font-size:11px;">' + parseInt( s.status_code, 10 ) + ' ' + self.escapeHtml( s.label ) + '</span>';
							html += ' <em style="color:#646970;font-size:12px;">— ' + self.escapeHtml( s.reason ) + '</em>';
							if ( actionable ) {
								html += ' <button type="button" class="button button-small tsoliin-apply-suggest" style="margin-left:8px;"'
									+ ' data-id="' + linkId + '" data-url="' + urlAttr + '">'
									+ self.escapeHtml( tsoliinData.i18n.applyUrl ) + '</button>';
							}
							html += '</li>';
						} );
						html += '</ul>';
						if ( r.data.note ) {
							html += '<div class="notice notice-warning inline" style="margin:0.75em 0 0;"><p style="margin:0.5em 0;">' + self.escapeHtml( r.data.note ) + '</p></div>';
						}
					}

					html += '<button type="button" class="tsoliin-suggest-close button-link" aria-label="' + self.escapeHtml( tsoliinData.i18n.closePanel ) + '">✕</button>';
					html += '</td></tr>';
					if ( $row.closest( 'body' ).length ) {
						$row.after( html );
					}
				},
				error: function ( xhr, status ) {
					if ( 'abort' === status ) {
						return;
					}
					if ( self.suggestRequestId !== linkId ) {
						return;
					}
					$trigger.html( '💡 ' + tsoliinData.i18n.smartSuggest );
					alert( tsoliinData.i18n.error );
				},
				complete: function () {
					if ( self.suggestRequestId === linkId ) {
						self.suggestXhr = null;
					}
				}
			} );
		},

		applySmartUrl: function ( linkId, newUrl, $btn, $row ) {
			var self = this;
			self.cancelListReload();
			$btn.prop( 'disabled', true ).text( tsoliinData.i18n.saving );

			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( { action: 'tsoliin_update_link', nonce: tsoliinData.nonce, link_id: linkId, new_url: newUrl }, self.listFilterParam() ),
				success: function ( r ) {
					if ( r.success ) {
						var d = r.data;
						if ( self.removeRowIfFilterMismatch( $row, d ) ) {
							$btn.closest( '.tsoliin-suggest-row' ).fadeOut( 300, function () { $( this ).remove(); } );
							self.showNotice( d.filter_promotion_message || ( '✅ ' + tsoliinData.i18n.urlUpdated + ' ' + d.new_url ), 'success' );
							self.refreshStats();
							return;
						}
						$row.find( '.column-status_code' ).html( '<span class="tsoliin-status ' + d.css_class + '">' + parseInt( d.status_code, 10 ) + ' ' + self.escapeHtml( d.label ) + '</span>' );
						$row.find( '.tsoliin-url a' ).attr( 'href', d.new_url ).text( d.new_url.substring( 0, 57 ) );
						$row.find( '.tsoliin-edit-link' ).attr( 'data-url', d.new_url ).data( 'url', d.new_url );
						$row.toggleClass( 'tsoliin-row--broken', 1 === d.is_broken );
						$btn.closest( '.tsoliin-suggest-row' ).fadeOut( 300, function () { $( this ).remove(); } );
						self.showNotice( '✅ ' + tsoliinData.i18n.urlUpdated + ' ' + d.new_url, 'success' );
						self.refreshStats();
					} else {
						$btn.prop( 'disabled', false ).text( tsoliinData.i18n.applyUrl );
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
					}
				},
				error: function () {
					$btn.prop( 'disabled', false ).text( tsoliinData.i18n.applyUrl );
					alert( tsoliinData.i18n.error );
				}
			} );
		},

		// ---------------------------------------------------------------
		// Diagnose
		// ---------------------------------------------------------------
		runDiagnose: function () {
			var self   = this;
			var $panel = $( '#tsoliin-diagnose-panel' );
			var $btn   = $( '#tsoliin-diagnose' );
			var btnHtml = '<span class="dashicons dashicons-info"></span> ' + tsoliinData.i18n.diagnosi;

			if ( $panel.is( ':visible' ) ) {
				if ( self._diagXhr ) {
					self._diagXhr.abort();
					self._diagXhr = null;
				}
				$panel.hide().empty();
				$btn.prop( 'disabled', false ).attr( 'aria-expanded', 'false' ).html( btnHtml );
				return;
			}

			$btn.prop( 'disabled', true ).attr( 'aria-expanded', 'true' ).text( tsoliinData.i18n.diagChecking );
			$panel.html( '<p><em>' + tsoliinData.i18n.diagChecking + '</em></p>' ).show();

			self._diagXhr = $.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : { action: 'tsoliin_diagnose', nonce: tsoliinData.nonce },
				success: function ( r ) {
					$btn.prop( 'disabled', false ).html( btnHtml );
					if ( ! $panel.is( ':visible' ) ) {
						return;
					}
					if ( r.success ) {
						$panel.html( '<strong>' + self.escapeHtml( tsoliinData.i18n.diagResult ) + '</strong><br><code style="display:block;white-space:pre-wrap;margin-top:8px;">' + self.escapeHtml( r.data.lines.join( '\n' ) ) + '</code>' );
					} else {
						$panel.html( '<p style="color:red;">' + ( r.data ? r.data.message : 'Error' ) + '</p>' );
					}
				},
				error: function ( _jqXHR, textStatus ) {
					$btn.prop( 'disabled', false ).html( btnHtml );
					if ( 'abort' === textStatus || ! $panel.is( ':visible' ) ) {
						return;
					}
					$panel.html( '<p style="color:red;">' + tsoliinData.i18n.error + '</p>' );
				},
				complete: function () {
					self._diagXhr = null;
				}
			} );
		}
	};

	$( document ).ready( function () { LC.init(); } );

} )( jQuery );
