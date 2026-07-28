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

	// « Acompte encaissé » : disponible sur TOUTES les vues de la console
	// (liste ET fiche), d'où sa délégation globale, hors du garde fiche.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-op-action="confirm-deposit"]' );

		if ( ! button || button.disabled ) {
			return;
		}

		var revId = button.getAttribute( 'data-revision-id' )
			|| ( button.closest( '[data-revision-id]' ) && button.closest( '[data-revision-id]' ).getAttribute( 'data-revision-id' ) );

		if ( ! revId ) {
			return;
		}

		if ( ! window.confirm( 'Confirmer l’encaissement de l’acompte (virement reçu) ? Le dossier passera en « En attente de réception ».' ) ) {
			return;
		}

		button.disabled = true;

		var body = new FormData();
		body.append( 'action', 'gacct_op_confirm_deposit' );
		body.append( 'nonce', window.gacctOp.nonce );
		body.append( 'revision_id', revId );

		fetch( window.gacctOp.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json && json.success ) {
					window.location.reload();
				} else {
					window.alert( ( json && json.data && json.data.message ) || window.gacctOp.i18n.genericError );
					button.disabled = false;
				}
			} )
			.catch( function () {
				window.alert( window.gacctOp.i18n.genericError );
				button.disabled = false;
			} );
	} );

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
						// Erreur affichée à côté du champ, pas seulement en tête de carte.
						if ( unlockWrap ) {
							var inlineError = unlockWrap.querySelector( '.gacct-op-inline-error' );
							if ( ! inlineError ) {
								inlineError = document.createElement( 'p' );
								inlineError.className = 'gacct-op-inline-error';
								inlineError.style.color = 'var(--gacct-danger)';
								inlineError.style.fontWeight = '600';
								unlockWrap.insertBefore( inlineError, button );
							}
							inlineError.textContent = window.gacctOp.i18n.reasonRequired;
						}
						if ( unlockField ) {
							unlockField.focus();
						}
						showFeedback( 'error', window.gacctOp.i18n.reasonRequired );
						return;
					}
				}

				// Réexpédition (7→8) : suivi transporteur obligatoire.
				var tracking = '';

				if ( '1' === button.getAttribute( 'data-tracking' ) ) {
					var trackWrap  = button.closest( '.gacct-op-ship-form' ) || fiche;
					var trackField = trackWrap.querySelector( '[data-op-field="tracking"]' );
					tracking = trackField ? trackField.value.trim() : '';

					if ( '' === tracking ) {
						showFeedback( 'error', 'Le suivi transporteur est obligatoire.' );
						if ( trackField ) {
							trackField.focus();
						}
						return;
					}
				}

				run( button, 'gacct_op_change_state', {
					revision_id: revisionId,
					new_state: button.getAttribute( 'data-state' ),
					force: force,
					reason: reason,
					unlock_reason: unlockReason,
					tracking: tracking
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

		// ─────────────────────────────────────────────────────────────────
		// Devis complémentaire (carte .gacct-op-quote-card).
		// ─────────────────────────────────────────────────────────────────
		var quoteCard = fiche.querySelector( '.gacct-op-quote-card' );

		if ( quoteCard && quoteCard.querySelector( '[data-quote-rows]' ) ) {
			var quoteRows     = quoteCard.querySelector( '[data-quote-rows]' );
			var quoteTotalEl  = quoteCard.querySelector( '[data-quote-total]' );
			var quoteForm     = quoteCard.querySelector( '.gacct-op-quote-form' );
			var quoteFeedback = quoteCard.querySelector( '.gacct-op-quote-feedback' );
			var quoteProducts = [];

			try {
				var productsScript = quoteCard.querySelector( '[data-quote-products]' );
				quoteProducts = productsScript ? JSON.parse( productsScript.textContent ) : [];
			} catch ( e ) {
				quoteProducts = [];
			}

			function quoteShowFeedback( type, message ) {
				if ( quoteFeedback ) {
					quoteFeedback.className   = 'gacct-op-feedback gacct-op-quote-feedback ' + type;
					quoteFeedback.textContent = message;
					quoteFeedback.scrollIntoView( { block: 'nearest' } );
				} else {
					showFeedback( type, message );
				}
			}

			function quoteFormatAmount( n ) {
				return n.toFixed( 2 ).replace( '.', ',' ) + ' €';
			}

			function quoteProductById( id ) {
				for ( var i = 0; i < quoteProducts.length; i++ ) {
					if ( Number( quoteProducts[ i ].id ) === Number( id ) ) {
						return quoteProducts[ i ];
					}
				}
				return null;
			}

			function quoteRecompute() {
				var total = 0;

				quoteRows.querySelectorAll( 'tr' ).forEach( function ( row ) {
					var qty  = Math.max( 1, parseInt( row.querySelector( '[data-q-qty]' ).value, 10 ) || 1 );
					var unit = 0;

					if ( 'catalog' === row.getAttribute( 'data-q-type' ) ) {
						var product = quoteProductById( row.querySelector( '[data-q-product]' ).value );
						unit = product ? Number( product.price_ttc ) : 0;
						row.querySelector( '[data-q-unit-label]' ).textContent = quoteFormatAmount( unit );
					} else {
						unit = parseFloat( String( row.querySelector( '[data-q-price]' ).value ).replace( ',', '.' ) ) || 0;
					}

					var line = unit * qty;
					row.querySelector( '[data-q-line-total]' ).textContent = quoteFormatAmount( line );
					total += line;
				} );

				if ( quoteTotalEl ) {
					quoteTotalEl.textContent = quoteFormatAmount( total );
				}
			}

			function quoteAddRow( type, prefill ) {
				prefill = prefill || {};

				var row = document.createElement( 'tr' );
				row.setAttribute( 'data-q-type', type );

				var cellMain = '';

				if ( 'catalog' === type ) {
					var options = quoteProducts.map( function ( p ) {
						var selected = Number( prefill.product_id ) === Number( p.id ) ? ' selected' : '';
						return '<option value="' + p.id + '"' + selected + '>' + p.name + '</option>';
					} ).join( '' );
					cellMain = '<select data-q-product>' + options + '</select>';
				} else {
					var label = prefill.label ? String( prefill.label ).replace( /"/g, '&quot;' ) : '';
					cellMain = '<input type="text" data-q-label placeholder="Libellé de la prestation" value="' + label + '">';
				}

				row.innerHTML =
					'<td>' + cellMain + '</td>' +
					'<td class="col-qty"><input type="number" data-q-qty min="1" max="99" value="' + ( prefill.qty || 1 ) + '"></td>' +
					'<td class="col-price">' + ( 'catalog' === type
						? '<span data-q-unit-label>—</span>'
						: '<input type="number" data-q-price min="0" step="0.01" placeholder="0,00" value="' + ( prefill.unit ? prefill.unit : '' ) + '">' ) + '</td>' +
					'<td class="col-total"><span data-q-line-total>—</span></td>' +
					'<td class="col-del"><button type="button" class="gacct-op-quote-del" data-op-action="quote-del-row" aria-label="Retirer la ligne">×</button></td>';

				quoteRows.appendChild( row );
				quoteRecompute();
			}

			// Pré-remplissage (état 3 : devis en attente à modifier).
			try {
				var prefillData = JSON.parse( quoteForm.getAttribute( 'data-quote-prefill' ) || '[]' );
				prefillData.forEach( function ( line ) {
					if ( line.product_id && quoteProductById( line.product_id ) ) {
						quoteAddRow( 'catalog', line );
					} else {
						quoteAddRow( 'free', line );
					}
				} );
			} catch ( e ) { /* pas de pré-remplissage */ }

			quoteCard.addEventListener( 'input', quoteRecompute );
			quoteCard.addEventListener( 'change', quoteRecompute );

			quoteCard.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '[data-op-action]' );

				if ( ! button || button.disabled ) {
					return;
				}

				var action = button.getAttribute( 'data-op-action' );

				if ( 'toggle-quote-form' === action ) {
					quoteForm.hidden = ! quoteForm.hidden;
					button.setAttribute( 'aria-expanded', quoteForm.hidden ? 'false' : 'true' );
					return;
				}

				if ( 'quote-add-catalog' === action ) {
					if ( ! quoteProducts.length ) {
						quoteShowFeedback( 'error', 'Aucun produit dans les catégories Réparation / Suspentes & travaux.' );
						return;
					}
					quoteAddRow( 'catalog' );
					return;
				}

				if ( 'quote-add-free' === action ) {
					quoteAddRow( 'free' );
					return;
				}

				if ( 'quote-del-row' === action ) {
					var tr = button.closest( 'tr' );
					if ( tr ) {
						tr.remove();
						quoteRecompute();
					}
					return;
				}

				if ( 'send-quote' === action ) {
					var lines  = [];
					var error  = '';

					quoteRows.querySelectorAll( 'tr' ).forEach( function ( row ) {
						var qty = Math.max( 1, parseInt( row.querySelector( '[data-q-qty]' ).value, 10 ) || 1 );

						if ( 'catalog' === row.getAttribute( 'data-q-type' ) ) {
							lines.push( { product_id: row.querySelector( '[data-q-product]' ).value, qty: qty } );
						} else {
							var label = row.querySelector( '[data-q-label]' ).value.trim();
							var price = parseFloat( String( row.querySelector( '[data-q-price]' ).value ).replace( ',', '.' ) ) || 0;

							if ( '' === label ) {
								error = 'Chaque ligne libre doit avoir un libellé.';
							} else if ( price <= 0 ) {
								error = 'Chaque ligne libre doit avoir un prix TTC supérieur à 0.';
							}
							lines.push( { label: label, price: price, qty: qty } );
						}
					} );

					if ( error ) {
						quoteShowFeedback( 'error', error );
						return;
					}

					if ( ! lines.length ) {
						quoteShowFeedback( 'error', 'Ajoutez au moins une ligne au devis.' );
						return;
					}

					if ( ! window.confirm( 'Envoyer ce devis au client ? Il recevra un email avec un lien pour accepter ou refuser.' ) ) {
						return;
					}

					var commentField = quoteCard.querySelector( '[data-op-field="quote-comment"]' );

					run( button, 'gacct_op_send_quote', {
						revision_id: revisionId,
						lines: JSON.stringify( lines ),
						comment: commentField ? commentField.value.trim() : ''
					}, function () {
						window.location.reload();
					} );
				}
			} );
		}

		// Dépôt du rapport d'intervention (états 3 à 6, sans changement d'état).
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
