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
		currentPage : 1,
		polling     : false,
		pollTimer   : null,
		statsTimer  : null,
		completed   : false,   // Guard: prevents reload loop
		editLinkId  : 0,
		editOldUrl  : '',
		editPostId  : 0,

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
			this.$modalSave    = $( '#tsoliin-modal-save' );
			this.$modalCancel  = $( '#tsoliin-modal-cancel' );
			this.$modalSpinner = $( '.tsoliin-modal__spinner' );
			this.$feedback     = $( '#tsoliin-modal-feedback' );

			this.bindEvents();

			// Resume polling if a check was running before page load.
			if ( parseInt( tsoliinData.bgRunning, 10 ) === 1 ) {
				this.$checkProg.show();
				this.$checkBtn.prop( 'disabled', true );
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
				self.startScan();
			} );

			this.$checkBtn.on( 'click', function () {
				if ( $( this ).prop( 'disabled' ) ) { return; }
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

			$( document ).on( 'click', '.tsoliin-edit-link', function ( e ) {
				e.preventDefault();
				var $a = $( this );
				self.openModal( parseInt( $a.data( 'id' ), 10 ), $a.data( 'url' ), parseInt( $a.data( 'post' ), 10 ) );
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
				self.markNotBroken( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
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
		startScan: function () {
			this.scanning    = true;
			this.currentPage = 1;
			this.$startBtn.prop( 'disabled', true ).text( tsoliinData.i18n.scanning );
			this.$progress.show().attr( 'aria-valuenow', 0 );
			this.updateProgress( 0, tsoliinData.i18n.scanning );
			this.scanBatch( 1 );
		},

		scanBatch: function ( pageNum ) {
			var self = this;
			$.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				data   : { action: 'tsoliin_scan_batch', nonce: tsoliinData.nonce, page_num: pageNum },
				success: function ( r ) {
					if ( ! r.success ) { self.scanError( r.data ? r.data.message : tsoliinData.i18n.error ); return; }
					self.updateProgress( r.data.progress, r.data.message );
					if ( r.data.done ) {
						self.scanDone();
					} else {
						self.scanBatch( r.data.next_page );
					}
				},
				error: function () { self.scanError( tsoliinData.i18n.error ); }
			} );
		},

		updateProgress: function ( pct, label ) {
			pct = Math.min( 100, Math.max( 0, pct ) );
			this.$progressBar.css( 'width', pct + '%' );
			this.$progressLbl.text( pct + '% – ' + label );
			this.$progress.attr( 'aria-valuenow', pct );
		},

		scanDone: function () {
			this.scanning = false;
			this.updateProgress( 100, tsoliinData.i18n.scanDone );
			this.$startBtn.prop( 'disabled', false ).html(
				'<span class="dashicons dashicons-search"></span> ' + tsoliinData.i18n.scanDone
			);
			var self = this;
			setTimeout( function () {
				self.$progress.hide();
				// Reset and hide any stale check progress bar before starting new check.
				self.$checkProg.hide();
				self.$checkBar.css( 'width', '0%' );
				self.$checkLbl.text( '' );
				self.startBgCheck();
			}, 800 );
		},

		scanError: function ( msg ) {
			this.scanning = false;
			this.$startBtn.prop( 'disabled', false );
			this.$progressLbl.text( msg );
			this.$progressBar.css( { 'width': '100%', 'background': '#cc1818' } );
		},

		// ---------------------------------------------------------------
		// Background check – server does the work, browser just polls
		// ---------------------------------------------------------------
		startBgCheck: function () {
			var self = this;
			this.completed = false;

			this.$checkBtn.prop( 'disabled', true );
			this.$checkProg.show();
			this.updateCheckProgress( 0, tsoliinData.i18n.checking );

			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : { action: 'tsoliin_start_bg_check', nonce: tsoliinData.nonce, resume: '1' },
				success: function ( r ) {
					if ( r.success ) {
						self.showNotice(
							'✅ ' + tsoliinData.i18n.checkStarted,
							'success'
						);
						self.startPolling();
					} else {
						self.$checkBtn.prop( 'disabled', false );
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
					}
				},
				error: function () {
					self.$checkBtn.prop( 'disabled', false );
					alert( tsoliinData.i18n.error );
				}
			} );
		},

		stopBgCheck: function () {
			var self = this;
			this.stopPolling();
			this.$checkBtn.prop( 'disabled', false );
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
						self.$checkBtn.prop( 'disabled', false );
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
				all            : 'total',
				broken         : 'broken',
				redirect       : 'redirect',
				ok             : 'ok',
				unchecked      : 'unchecked',
				http_insecure  : 'http_insecure',
				manual_locked  : 'manual_locked'
			};
			$.each( tabMap, function ( filterKey, statKey ) {
				if ( undefined === stats[ statKey ] ) {
					return;
				}
				var count = ( display && display[ statKey ] ) ? display[ statKey ] : stats[ statKey ];
				$( '.tsoliin-filter-tabs a' ).each( function () {
					var href = $( this ).attr( 'href' ) || '';
					if ( href.indexOf( 'filter=' + filterKey ) !== -1 ) {
						$( this ).text( $( this ).text().replace( /\([\d,.]+\)/, '(' + count + ')' ) );
					}
				} );
			} );

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
			setTimeout( function () {
				window.location.reload();
			}, delay || 1200 );
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
			return { list_filter: tsoliinData.listFilter || 'all' };
		},

		removeRowIfFilterMismatch: function ( $row, data ) {
			if ( ! $row || ! $row.length || ! data || false !== data.matches_filter ) {
				return false;
			}
			if ( 'all' === ( tsoliinData.listFilter || 'all' ) ) {
				return false;
			}
			var self = this;
			self.removeSuggestPanelsForRow( $row );
			$row.fadeOut( 300, function () {
				$( this ).remove();
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
						return;
					}
					var d = r.data;
					if ( self.removeRowIfFilterMismatch( $row, d ) ) {
						self.refreshStats();
						$trigger.text( tsoliinData.i18n.recheck );
						return;
					}
					if ( d.status_html ) {
						$status.html( d.status_html );
					} else {
						$status.html( '<span class="tsoliin-status ' + d.css_class + '">' + d.status_code + ' ' + d.label + '</span>' );
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
		openModal: function ( linkId, oldUrl, postId ) {
			this.editLinkId = linkId;
			this.editOldUrl = oldUrl;
			this.editPostId = postId;
			this.$modalOldUrl.text( oldUrl );
			this.$newUrlInput.val( oldUrl );
			this.$feedback.text( '' ).removeClass( 'is-error is-success' );
			this.$modal.show();
			this.$newUrlInput.trigger( 'focus' );
		},

		closeModal: function () {
			this.$modal.hide();
			this.editLinkId = 0;
		},

		saveLink: function () {
			var self   = this;
			var newUrl = this.$newUrlInput.val().trim();
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
				data  : $.extend( { action: 'tsoliin_update_link', nonce: tsoliinData.nonce, link_id: self.editLinkId, new_url: newUrl }, self.listFilterParam() ),
				success: function ( r ) {
					self.$modalSave.prop( 'disabled', false ).text( tsoliinData.i18n.save );
					self.$modalSpinner.removeClass( 'is-active' );
					if ( ! r.success ) {
						self.$feedback.text( r.data ? r.data.message : tsoliinData.i18n.error ).addClass( 'is-error' );
						return;
					}
					self.$feedback.text( '✓ ' + tsoliinData.i18n.urlSaved ).addClass( 'is-success' );
					var d   = r.data;
					var $row = $( 'tr' ).filter( function () {
						return $( this ).find( '.tsoliin-edit-link[data-id="' + self.editLinkId + '"]' ).length > 0;
					} );
					if ( $row.length ) {
						if ( self.removeRowIfFilterMismatch( $row, d ) ) {
							setTimeout( function () { self.closeModal(); }, 800 );
							self.refreshStats();
							return;
						}
						$row.find( '.column-status_code' ).html( '<span class="tsoliin-status ' + d.css_class + '">' + parseInt( d.status_code, 10 ) + ' ' + self.escapeHtml( d.label ) + '</span>' );
						$row.find( '.tsoliin-url a' ).attr( 'href', d.new_url ).text( d.new_url.substring( 0, 57 ) );
						$row.find( '.tsoliin-edit-link' ).attr( 'data-url', d.new_url );
						$row.toggleClass( 'tsoliin-row--broken', 1 === d.is_broken );
					}
					setTimeout( function () { self.closeModal(); }, 1200 );
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

			if ( 'recheck' === action || 'unlink' === action ) {
				self.bulkRecheckStep( linkIds, 0, action );
			} else if ( 'not_broken' === action ) {
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
				// Delete: single AJAX call.
				$.ajax( {
					url   : tsoliinData.ajaxUrl,
					method: 'POST',
					data  : { action: 'tsoliin_bulk_action', nonce: tsoliinData.nonce, bulk_action: action, link_ids: linkIds, index: 0 },
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
				self._bulkStats = { unlinked: 0, skipped: 0, failed: 0 };
				var initMsg = 'unlink' === act ? tsoliinData.i18n.unlinking : tsoliinData.i18n.checking;
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
				} else {
					doneMsg = '✅ ' + total + ' ' + tsoliinData.i18n.itemsChecked;
				}
				$( '#tsoliin-bulk-msg' ).text( doneMsg );
				$( '#tsoliin-bulk-bar' ).val( 100 );
				if ( 'unlink' === act ) {
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

			var progressLabel = 'unlink' === act ? tsoliinData.i18n.unlinking : tsoliinData.i18n.checking;
			var pct = Math.round( ( index / total ) * 100 );
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
						if ( 'unlink' === act ) {
							self._bulkStats.failed++;
						}
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
						self.bulkRecheckStep( linkIds, index + 1, act );
						return;
					}
					if ( 'recheck' === act && r.data.row ) {
							// Update row status live.
							var d   = r.data.row;
							var $tr = $( 'tr' ).filter( function () {
								return $( this ).find( 'input[value="' + d.link_id + '"]' ).length > 0;
							} );
							if ( $tr.length ) {
								if ( self.removeRowIfFilterMismatch( $tr, d ) ) {
									self.refreshStats();
								} else {
									if ( d.status_html ) {
										$tr.find( '.column-status_code' ).html( d.status_html );
									} else {
										$tr.find( '.column-status_code' ).html( '<span class="tsoliin-status ' + d.css_class + '">' + parseInt( d.status_code, 10 ) + ' ' + self.escapeHtml( d.label ) + '</span>' );
									}
									$tr.find( '.column-last_checked' ).text( d.last_checked );
									$tr.toggleClass( 'tsoliin-row--broken', 1 === parseInt( d.is_broken, 10 ) );
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
						}
					self.bulkRecheckStep( linkIds, index + 1, act );
				},
				error: function () {
					if ( 'unlink' === act ) {
						self._bulkStats.failed++;
					}
					alert( tsoliinData.i18n.error );
					self.bulkRecheckStep( linkIds, index + 1, act );
				}
			} );
		},

		// ---------------------------------------------------------------
		// Smart URL Suggestion
		// ---------------------------------------------------------------
		smartSuggest: function ( linkId, $trigger ) {
			var self    = this;
			var $row    = $trigger.closest( 'tr' );
			self.removeSuggestPanelsForRow( $row );

			if ( self.suggestXhr && self.suggestXhr.readyState !== 4 ) {
				self.suggestXhr.abort();
			}

			$trigger.text( tsoliinData.i18n.smartChecking );

			self.suggestXhr = $.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				timeout: 60000,
				data   : { action: 'tsoliin_smart_suggest', nonce: tsoliinData.nonce, link_id: linkId },
				success: function ( r ) {
					$trigger.html( '💡 ' + tsoliinData.i18n.smartSuggest );

					var cols = $row.find( 'td' ).length;
					var html = '<tr class="tsoliin-suggest-row" data-link-id="' + linkId + '"><td colspan="' + cols + '" class="tsoliin-suggest-panel">';

					if ( ! r.success ) {
						html += '<span style="color:#b32d2e;">' + self.escapeHtml( ( r.data && r.data.message ) ? r.data.message : tsoliinData.i18n.error ) + '</span>';
					} else if ( ! r.data.suggestions || ! r.data.suggestions.length ) {
						if ( r.data.note ) {
							html += '<div class="notice notice-warning inline" style="margin:0;"><p style="margin:0.5em 0;">' + self.escapeHtml( r.data.note ) + '</p></div>';
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
					$row.after( html );
				},
				error: function ( xhr, status ) {
					if ( 'abort' === status ) {
						return;
					}
					$trigger.html( '💡 ' + tsoliinData.i18n.smartSuggest );
					alert( tsoliinData.i18n.error );
				}
			} );
		},

		applySmartUrl: function ( linkId, newUrl, $btn, $row ) {
			var self = this;
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
							self.showNotice( '✅ ' + tsoliinData.i18n.urlUpdated + ' ' + d.new_url, 'success' );
							self.refreshStats();
							return;
						}
						$row.find( '.column-status_code' ).html( '<span class="tsoliin-status ' + d.css_class + '">' + parseInt( d.status_code, 10 ) + ' ' + self.escapeHtml( d.label ) + '</span>' );
						$row.find( '.tsoliin-url a' ).attr( 'href', d.new_url ).text( d.new_url.substring( 0, 57 ) );
						$row.find( '.tsoliin-edit-link' ).attr( 'data-url', d.new_url );
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

			$btn.prop( 'disabled', true ).text( tsoliinData.i18n.diagChecking );
			$panel.html( '<p><em>' + tsoliinData.i18n.diagChecking + '</em></p>' ).show();

			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : { action: 'tsoliin_diagnose', nonce: tsoliinData.nonce },
				success: function ( r ) {
					$btn.prop( 'disabled', false ).html( '<span class="dashicons dashicons-info"></span> ' + tsoliinData.i18n.diagnosi );
					if ( r.success ) {
						$panel.html( '<strong>' + self.escapeHtml( tsoliinData.i18n.diagResult ) + '</strong><br><code style="display:block;white-space:pre-wrap;margin-top:8px;">' + self.escapeHtml( r.data.lines.join( '\n' ) ) + '</code>' );
					} else {
						$panel.html( '<p style="color:red;">' + ( r.data ? r.data.message : 'Error' ) + '</p>' );
					}
				},
				error: function () {
					$btn.prop( 'disabled', false );
					$panel.html( '<p style="color:red;">' + tsoliinData.i18n.error + '</p>' );
				}
			} );
		}
	};

	$( document ).ready( function () { LC.init(); } );

} )( jQuery );
