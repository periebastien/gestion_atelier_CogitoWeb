/**
 * Console atelier — JS commun (surtout la fiche intervention).
 * Vanilla JS, délégation sur data-op-action. Ne fait rien si les
 * éléments de la fiche sont absents (page liste).
 */
( function () {
	'use strict';

	if ( typeof window.gacctOp === 'undefined' ) {
		return;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var fiche = document.querySelector( '.gacct-op-fiche[data-revision-id]' );

		if ( ! fiche ) {
			return;
		}

		var revisionId = fiche.getAttribute( 'data-revision-id' );
		var feedback   = fiche.querySelector( '.gacct-op-actions-card .gacct-op-feedback' ) || fiche.querySelector( '.gacct-op-feedback' );

		function showFeedback( type, message ) {
			if ( ! feedback ) {
				window.alert( message );
				return;
			}
			feedback.className   = 'gacct-op-feedback ' + type;
			feedback.textContent = message;
			feedback.scrollIntoView( { block: 'nearest' } );
		}

		/**
		 * POST AJAX vers admin-ajax. data = objet simple ou FormData ;
		 * action + nonce ajoutés automatiquement.
		 */
		function post( action, data ) {
			var body;

			if ( data instanceof FormData ) {
				body = data;
			} else {
				body = new FormData();
				Object.keys( data || {} ).forEach( function ( key ) {
					body.append( key, data[ key ] );
				} );
			}
			body.append( 'action', action );
			body.append( 'nonce', window.gacctOp.nonce );

			return fetch( window.gacctOp.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( response ) {
				return response.json();
			} );
		}

		function run( button, action, data, onSuccess ) {
			if ( button ) {
				button.disabled = true;
			}
			post( action, data )
				.then( function ( json ) {
					if ( json && json.success ) {
						onSuccess( json.data || {} );
					} else {
						var msg = ( json && json.data && json.data.message ) ? json.data.message : window.gacctOp.i18n.genericError;
						showFeedback( 'error', msg );
						if ( button ) {
							button.disabled = false;
						}
					}
				} )
				.catch( function () {
					showFeedback( 'error', window.gacctOp.i18n.genericError );
					if ( button ) {
						button.disabled = false;
					}
				} );
		}

		// Délégation de clics sur la fiche.
		fiche.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-op-action]' );

			if ( ! button || button.disabled ) {
				return;
			}

			var opAction = button.getAttribute( 'data-op-action' );

			if ( 'toggle-force' === opAction ) {
				var form = button.parentNode.querySelector( '.gacct-op-force-form' );
				if ( form ) {
					form.hidden = ! form.hidden;
					button.setAttribute( 'aria-expanded', form.hidden ? 'false' : 'true' );
				}
				return;
			}

			if ( 'change-state' === opAction ) {
				var force  = '1' === button.getAttribute( 'data-force' ) ? 1 : 0;
				var reason = '';

				if ( force ) {
					var wrap  = button.closest( '.gacct-op-force-form' );
					var field = wrap ? wrap.querySelector( '[data-op-field="force-reason"]' ) : null;
					reason = field ? field.value.trim() : '';

					if ( '' === reason ) {
						showFeedback( 'error', window.gacctOp.i18n.reasonRequired );
						return;
					}
					if ( ! window.confirm( 'Confirmer cette transition forcée ? Le motif sera journalisé.' ) ) {
						return;
					}
				}

				// Dossier incomplet : motif de déblocage obligatoire (data-unlock="1").
				var unlockReason = '';

				if ( '1' === button.getAttribute( 'data-unlock' ) ) {
					var unlockWrap  = button.closest( '.gacct-op-force-form' );
					var unlockField = unlockWrap ? unlockWrap.querySelector( '[data-op-field="unlock-reason"]' ) : null;
					unlockReason = unlockField ? unlockField.value.trim() : '';

					if ( '' === unlockReason ) {
						showFeedback( 'error', window.gacctOp.i18n.reasonRequired );
						return;
					}
				}

				run( button, 'gacct_op_change_state', {
					revision_id: revisionId,
					new_state: button.getAttribute( 'data-state' ),
					force: force,
					reason: reason,
					unlock_reason: unlockReason
				}, function () {
					window.location.reload();
				} );
				return;
			}

			if ( 'resend-email' === opAction ) {
				run( button, 'gacct_op_resend_email', { revision_id: revisionId }, function () {
					showFeedback( 'success', 'Email renvoyé.' );
					button.disabled = false;
				} );
				return;
			}

			if ( 'add-note' === opAction ) {
				var noteField = fiche.querySelector( '[data-op-field="note"]' );
				var note      = noteField ? noteField.value.trim() : '';

				if ( '' === note ) {
					if ( noteField ) {
						noteField.focus();
					}
					return;
				}

				run( button, 'gacct_op_add_note', { revision_id: revisionId, note: note }, function () {
					window.location.reload();
				} );
				return;
			}

			if ( 'set-operator' === opAction ) {
				var select = fiche.querySelector( '[data-op-field="operator"]' );

				if ( ! select ) {
					return;
				}

				run( button, 'gacct_op_set_operator', {
					revision_id: revisionId,
					operator_id: select.value
				}, function ( data ) {
					button.disabled = false;
					var zone = fiche.querySelector( '.gacct-op-operator-feedback' );
					if ( zone ) {
						zone.textContent = data.operator_name ? '✓ ' + data.operator_name : '✓ Enregistré';
					}
				} );
				return;
			}

			if ( 'payment-reminder' === opAction ) {
				if ( ! window.confirm( 'Envoyer une relance de paiement au client maintenant ?' ) ) {
					return;
				}

				var payFeedback = fiche.querySelector( '.gacct-op-pay-feedback' );

				function showPayFeedback( type, message ) {
					if ( payFeedback ) {
						payFeedback.className   = 'gacct-op-feedback gacct-op-pay-feedback ' + type;
						payFeedback.textContent = message;
					} else {
						showFeedback( type, message );
					}
				}

				button.disabled = true;
				post( 'gacct_op_payment_reminder', { revision_id: revisionId } )
					.then( function ( json ) {
						button.disabled = false;
						if ( json && json.success ) {
							showPayFeedback( 'success', 'Relance envoyée à ' + ( ( json.data && json.data.to ) ? json.data.to : '' ) );
						} else {
							var msg = ( json && json.data && json.data.message ) ? json.data.message : window.gacctOp.i18n.genericError;
							showPayFeedback( 'error', msg );
						}
					} )
					.catch( function () {
						button.disabled = false;
						showPayFeedback( 'error', window.gacctOp.i18n.genericError );
					} );
				return;
			}

			if ( 'cancel' === opAction ) {
				if ( ! window.confirm( window.gacctOp.i18n.confirmCancel ) ) {
					return;
				}

				var cancelReason = window.prompt( window.gacctOp.i18n.reasonRequired, '' );

				if ( null === cancelReason ) {
					return;
				}
				cancelReason = cancelReason.trim();

				if ( '' === cancelReason ) {
					showFeedback( 'error', window.gacctOp.i18n.reasonRequired );
					return;
				}

				run( button, 'gacct_op_cancel', { revision_id: revisionId, reason: cancelReason }, function ( data ) {
					window.location.href = data.redirect || window.gacctOp.consoleUrl;
				} );
			}
		} );

		// Upload du rapport (transition 6→7).
		var uploadForm = fiche.querySelector( '[data-op-form="upload-report"]' );

		if ( uploadForm ) {
			uploadForm.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var input  = uploadForm.querySelector( 'input[type="file"]' );
				var submit = uploadForm.querySelector( 'button[type="submit"]' );

				if ( ! input || ! input.files || ! input.files.length ) {
					showFeedback( 'error', window.gacctOp.i18n.genericError );
					return;
				}

				var data = new FormData();
				data.append( 'revision_id', revisionId );
				data.append( 'rapport', input.files[ 0 ] );

				run( submit, 'gacct_op_upload_report', data, function () {
					window.location.reload();
				} );
			} );
		}
	} );
} )();
