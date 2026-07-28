/**
 * Console atelier — écran « Réception d'un colis ».
 * Check-list tactile : compteur de manquants, libellé du bouton,
 * envoi AJAX gacct_op_receive. Dépend de operator.js (window.gacctOp).
 */
( function () {
	'use strict';

	if ( typeof window.gacctOp === 'undefined' ) {
		return;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var dossier = document.querySelector( '.gacct-op-reception-dossier[data-revision-id]' );

		if ( ! dossier ) {
			return;
		}

		var wrap = dossier.querySelector( '[data-op-checklist]' );

		if ( ! wrap ) {
			return;
		}

		var revisionId = dossier.getAttribute( 'data-revision-id' );
		var ficheUrl   = dossier.getAttribute( 'data-fiche-url' ) || window.gacctOp.consoleUrl;
		var button     = wrap.querySelector( '.gacct-op-reception-submit' );
		var feedback   = wrap.querySelector( '.gacct-op-feedback' );
		var warning    = wrap.querySelector( '[data-op-partial-warning]' );
		var boxes      = Array.prototype.slice.call( wrap.querySelectorAll( '.gacct-op-reception-checklist input[type="checkbox"]' ) );

		function missingValues() {
			return boxes.filter( function ( box ) {
				return ! box.checked;
			} ).map( function ( box ) {
				return box.value;
			} );
		}

		function refresh() {
			boxes.forEach( function ( box ) {
				var row = box.closest( '.gacct-op-reception-row' );
				if ( row ) {
					row.classList.toggle( 'is-checked', box.checked );
					row.classList.toggle( 'is-missing', ! box.checked );
				}
			} );

			var missing = missingValues().length;

			if ( warning ) {
				warning.hidden = ( 0 === missing );
			}

			if ( button && ! button.dataset.locked ) {
				if ( 0 === missing ) {
					button.textContent = wrap.getAttribute( 'data-label-full' );
				} else if ( 1 === missing ) {
					button.textContent = wrap.getAttribute( 'data-label-partial-one' );
				} else {
					button.textContent = wrap.getAttribute( 'data-label-partial' ).replace( '%d', missing );
				}
				button.classList.toggle( 'is-partial', missing > 0 );
			}
		}

		function showFeedback( type, message, withFicheLink ) {
			if ( ! feedback ) {
				window.alert( message );
				return;
			}

			feedback.className   = 'gacct-op-feedback ' + type;
			feedback.textContent = message;

			if ( withFicheLink ) {
				feedback.appendChild( document.createElement( 'br' ) );
				var link = document.createElement( 'a' );
				link.href        = ficheUrl;
				link.className   = 'gacct-op-btn gacct-op-reception-fichebtn';
				link.textContent = wrap.getAttribute( 'data-label-fiche' );
				feedback.appendChild( link );
			}

			feedback.scrollIntoView( { block: 'nearest' } );
		}

		function lockChecklist() {
			boxes.forEach( function ( box ) {
				box.disabled = true;
			} );
			if ( button ) {
				button.dataset.locked = '1';
				button.disabled       = true;
			}
		}

		function send() {
			var missing = missingValues();
			var body    = new FormData();

			body.append( 'action', 'gacct_op_receive' );
			body.append( 'nonce', window.gacctOp.nonce );
			body.append( 'revision_id', revisionId );

			// Champ omis si tout est coché (= réception complète).
			missing.forEach( function ( label ) {
				body.append( 'missing[]', label );
			} );

			button.disabled = true;

			fetch( window.gacctOp.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( json ) {
				if ( json && json.success ) {
					var data = json.data || {};
					lockChecklist();
					if ( data.complete ) {
						showFeedback( 'success', wrap.getAttribute( 'data-msg-complete' ), true );
					} else {
						showFeedback( 'error', wrap.getAttribute( 'data-msg-partial' ), true );
					}
					return;
				}

				var msg  = ( json && json.data && json.data.message ) ? json.data.message : window.gacctOp.i18n.genericError;
				var code = ( json && json.data && json.data.code ) ? json.data.code : '';

				showFeedback( 'error', msg );

				// Erreurs définitives (déjà réceptionné, mauvais état) : on fige.
				if ( 'gacct_op_already_received' === code || 'gacct_op_bad_state' === code ) {
					lockChecklist();
				} else {
					button.disabled = false;
				}
			} ).catch( function () {
				showFeedback( 'error', window.gacctOp.i18n.genericError );
				button.disabled = false;
			} );
		}

		wrap.addEventListener( 'change', function ( event ) {
			if ( event.target && 'checkbox' === event.target.type ) {
				refresh();
			}
		} );

		if ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! button.disabled ) {
					send();
				}
			} );
		}

		refresh();
	} );
} )();
