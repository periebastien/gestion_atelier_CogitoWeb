/**
 * Console atelier — écran Planning (CDC §4.5).
 * FullCalendar (vendored, global) + endpoints gacct_op_planning_events /
 * gacct_op_reschedule. Mini-fiche en panneau (bottom sheet mobile),
 * drag & drop avec confirmation + motif si nécessaire.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var calendarEl = document.getElementById( 'gacct-op-calendar' );

		if ( ! calendarEl || typeof window.FullCalendar === 'undefined' || typeof window.gacctOp === 'undefined' ) {
			return;
		}

		var feedback      = document.getElementById( 'gacct-op-planning-feedback' );
		var panel         = document.getElementById( 'gacct-op-panel' );
		var overlay       = document.getElementById( 'gacct-op-panel-overlay' );
		var panelFeedback = panel ? panel.querySelector( '[data-op-slot="panel-feedback"]' ) : null;
		var i18n          = window.gacctOp.i18n || {};

		/* ------------------------------------------------------------------ */
		/*  Utilitaires                                                        */
		/* ------------------------------------------------------------------ */

		function showFeedback( el, type, message ) {
			if ( ! el ) {
				window.alert( message );
				return;
			}
			el.className   = el.className.replace( /\s*(success|error)\b/g, '' ) + ' ' + type;
			el.textContent = message;
		}

		function clearFeedback( el ) {
			if ( el ) {
				el.className   = el.className.replace( /\s*(success|error)\b/g, '' );
				el.textContent = '';
			}
		}

		/** 'AAAA-MM-JJ' → 'JJ/MM/AAAA' (affichage). */
		function formatFr( ymd ) {
			var parts = String( ymd || '' ).slice( 0, 10 ).split( '-' );
			return 3 === parts.length ? parts[ 2 ] + '/' + parts[ 1 ] + '/' + parts[ 0 ] : ymd;
		}

		function post( action, fields ) {
			var body = new FormData();
			Object.keys( fields || {} ).forEach( function ( key ) {
				body.append( key, fields[ key ] );
			} );
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

		function reschedule( occupationId, date, reason, notify ) {
			return post( 'gacct_op_reschedule', {
				occupation_id: occupationId,
				date: date,
				reason: reason || '',
				notify: notify ? '1' : '0'
			} );
		}

		function successMessage( ref, ymd, data ) {
			var msg = 'Créneau de ' + ref + ' déplacé au ' + formatFr( ymd ) + '.';
			msg += ( data && data.notified ) ? ' Email envoyé au client.' : ' Aucun email envoyé.';
			return msg;
		}

		/* ------------------------------------------------------------------ */
		/*  Mini-fiche (panneau)                                               */
		/* ------------------------------------------------------------------ */

		var panelProps = null;

		function slot( name ) {
			return panel ? panel.querySelector( '[data-op-slot="' + name + '"]' ) : null;
		}

		function field( name ) {
			return panel ? panel.querySelector( '[data-op-field="' + name + '"]' ) : null;
		}

		function closePanel() {
			if ( panel ) {
				panel.hidden = true;
			}
			if ( overlay ) {
				overlay.hidden = true;
			}
			panelProps = null;
		}

		function openPanel( event ) {
			if ( ! panel || ! overlay ) {
				return;
			}

			var props = event.extendedProps || {};
			panelProps = props;

			var refEl = slot( 'ref' );
			if ( refEl ) {
				refEl.textContent = props.ref || '';
			}

			var clientEl = slot( 'client' );
			if ( clientEl ) {
				clientEl.textContent = props.client || '';
				clientEl.hidden      = ! props.client;
			}

			var badge = slot( 'etat' );
			if ( badge ) {
				badge.className   = 'gacct-op-badge etat-' + parseInt( props.etat, 10 );
				badge.textContent = props.etat_label || '';
			}

			var pill = slot( 'incomplet' );
			if ( pill ) {
				pill.hidden = ! props.incomplet;
			}

			var matEl  = slot( 'materiel' );
			var matRow = panel.querySelector( '[data-op-row="materiel"]' );
			if ( matEl ) {
				matEl.textContent = props.materiel || '';
			}
			if ( matRow ) {
				matRow.hidden = ! props.materiel;
			}

			var durEl  = slot( 'duration' );
			var durRow = panel.querySelector( '[data-op-row="duration"]' );
			if ( durEl ) {
				durEl.textContent = props.duration || '';
			}
			if ( durRow ) {
				durRow.hidden = ! props.duration;
			}

			var currentYmd = event.startStr ? event.startStr.slice( 0, 10 ) : '';
			var curEl      = slot( 'current-date' );
			if ( curEl ) {
				curEl.textContent = formatFr( currentYmd );
			}

			var fiche = slot( 'fiche' );
			if ( fiche ) {
				if ( props.fiche_url ) {
					fiche.href   = props.fiche_url;
					fiche.hidden = false;
				} else {
					fiche.hidden = true;
				}
			}

			var reasonRow = panel.querySelector( '[data-op-row="reason"]' );
			if ( reasonRow ) {
				reasonRow.hidden = ! props.needs_reason;
			}

			var dateInput = field( 'date' );
			if ( dateInput ) {
				dateInput.value = '';
			}
			var reasonInput = field( 'reason' );
			if ( reasonInput ) {
				reasonInput.value = '';
			}
			var notifyInput = field( 'notify' );
			if ( notifyInput ) {
				notifyInput.checked = true;
			}

			var moveBtn = panel.querySelector( '[data-op-move]' );
			if ( moveBtn ) {
				moveBtn.disabled = false;
			}

			clearFeedback( panelFeedback );
			overlay.hidden = false;
			panel.hidden   = false;

			if ( dateInput ) {
				dateInput.focus();
			}
		}

		if ( panel && overlay ) {
			overlay.addEventListener( 'click', closePanel );

			var closeBtn = panel.querySelector( '[data-op-close]' );
			if ( closeBtn ) {
				closeBtn.addEventListener( 'click', closePanel );
			}

			document.addEventListener( 'keydown', function ( keyEvent ) {
				if ( 'Escape' === keyEvent.key && ! panel.hidden ) {
					closePanel();
				}
			} );

			var moveButton = panel.querySelector( '[data-op-move]' );

			if ( moveButton ) {
				moveButton.addEventListener( 'click', function () {
					if ( ! panelProps ) {
						return;
					}

					var dateInput = field( 'date' );
					var date      = dateInput ? dateInput.value : '';

					if ( ! date ) {
						showFeedback( panelFeedback, 'error', 'Choisissez une date.' );
						if ( dateInput ) {
							dateInput.focus();
						}
						return;
					}

					var reason = '';
					if ( panelProps.needs_reason ) {
						var reasonInput = field( 'reason' );
						reason = reasonInput ? reasonInput.value.trim() : '';

						if ( '' === reason ) {
							showFeedback( panelFeedback, 'error', i18n.reasonRequired || 'Un motif est obligatoire.' );
							if ( reasonInput ) {
								reasonInput.focus();
							}
							return;
						}
					}

					var notifyInput = field( 'notify' );
					var notify      = ! notifyInput || notifyInput.checked;
					var props       = panelProps;

					moveButton.disabled = true;

					reschedule( props.occupation_id, date, reason, notify )
						.then( function ( json ) {
							moveButton.disabled = false;

							if ( json && json.success ) {
								closePanel();
								calendar.refetchEvents();
								showFeedback( feedback, 'success', successMessage( props.ref, date, json.data ) );
							} else {
								var msg = ( json && json.data && json.data.message ) ? json.data.message : ( i18n.genericError || 'Erreur.' );
								showFeedback( panelFeedback, 'error', msg );
							}
						} )
						.catch( function () {
							moveButton.disabled = false;
							showFeedback( panelFeedback, 'error', i18n.genericError || 'Erreur.' );
						} );
				} );
			}
		}

		/* ------------------------------------------------------------------ */
		/*  Calendrier                                                         */
		/* ------------------------------------------------------------------ */

		var calendar = new FullCalendar.Calendar( calendarEl, {
			initialView: 'dayGridMonth',
			locale: 'fr',
			firstDay: 1,
			height: 'auto',
			nowIndicator: true,
			dayMaxEvents: 4,
			displayEventTime: false,
			editable: true,
			eventDurationEditable: false,
			headerToolbar: {
				left: 'prev,next today',
				center: 'title',
				right: 'dayGridMonth,dayGridWeek'
			},
			events: function ( info, success, failure ) {
				var body = new FormData();
				body.append( 'action', 'gacct_op_planning_events' );
				body.append( 'nonce', window.gacctOp.nonce );
				body.append( 'start', info.startStr.slice( 0, 10 ) );
				body.append( 'end', info.endStr.slice( 0, 10 ) );

				fetch( window.gacctOp.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( json ) {
						if ( Array.isArray( json ) ) {
							success( json );
							return;
						}
						var msg = ( json && json.data && json.data.message ) ? json.data.message : ( i18n.genericError || 'Erreur.' );
						showFeedback( feedback, 'error', msg );
						failure( new Error( msg ) );
					} )
					.catch( function ( err ) {
						showFeedback( feedback, 'error', i18n.genericError || 'Erreur.' );
						failure( err );
					} );
			},
			eventClick: function ( info ) {
				info.jsEvent.preventDefault();

				var props = info.event.extendedProps || {};
				if ( 'occupation' === props.type ) {
					openPanel( info.event );
				}
			},
			eventDrop: function ( info ) {
				var props = info.event.extendedProps || {};

				if ( 'occupation' !== props.type ) {
					info.revert();
					return;
				}

				var ymd   = info.event.startStr.slice( 0, 10 );
				var recap = 'Déplacer ' + props.ref + ( props.client ? ' (' + props.client + ')' : '' ) +
					' au ' + formatFr( ymd ) + ' ?\n\nOK = un email au client vous sera ensuite proposé.';

				if ( ! window.confirm( recap ) ) {
					info.revert();
					return;
				}

				var reason = '';
				if ( props.needs_reason ) {
					reason = window.prompt( 'Dossier en intervention (état ≥ 4) : motif obligatoire, il sera journalisé.', '' );
					if ( null === reason || '' === reason.trim() ) {
						info.revert();
						showFeedback( feedback, 'error', i18n.reasonRequired || 'Un motif est obligatoire.' );
						return;
					}
					reason = reason.trim();
				}

				var notify = window.confirm( 'Prévenir le client par email ?\n\nOK = email « créneau replanifié » envoyé · Annuler = pas d\'email.' );

				reschedule( props.occupation_id, ymd, reason, notify )
					.then( function ( json ) {
						if ( json && json.success ) {
							calendar.refetchEvents();
							showFeedback( feedback, 'success', successMessage( props.ref, ymd, json.data ) );
						} else {
							info.revert();
							var msg = ( json && json.data && json.data.message ) ? json.data.message : ( i18n.genericError || 'Erreur.' );
							showFeedback( feedback, 'error', msg );
						}
					} )
					.catch( function () {
						info.revert();
						showFeedback( feedback, 'error', i18n.genericError || 'Erreur.' );
					} );
			}
		} );

		calendar.render();
	} );
} )();
