/**
 * Console atelier — écran Planning (CDC §4.5).
 * FullCalendar (vendored, global) + endpoints gacct_op_planning_events /
 * gacct_op_reschedule. Mini-fiche en panneau (bottom sheet mobile),
 * drag & drop avec confirmation + motif si nécessaire.
 * Administrateurs (gacctOp.canManage) : sélection d'un jour ou d'une plage →
 * panneau « Heures d'ouverture » (endpoint gacct_op_set_capacity, 08/09/2026).
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
			if ( data && data.notified ) {
				msg += ' Email envoyé au client.';
			} else if ( data && data.notify_requested ) {
				msg += ' ⚠ L\'envoi de l\'email au client a ÉCHOUÉ : prévenez-le par un autre moyen.';
			} else {
				msg += ' Aucun email demandé.';
			}
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
			var capOpen = document.getElementById( 'gacct-op-cap-panel' );
			if ( overlay && ( ! capOpen || capOpen.hidden ) ) {
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
		/*  Heures d'ouverture (administrateurs) — panneau de capacité         */
		/* ------------------------------------------------------------------ */

		var canManage   = !! window.gacctOp.canManage;
		var capPanel    = document.getElementById( 'gacct-op-cap-panel' );
		var capFeedback = capPanel ? capPanel.querySelector( '[data-cap-slot="feedback"]' ) : null;
		var capRange    = null; // { start: 'AAAA-MM-JJ', end: 'AAAA-MM-JJ' } (inclus)

		if ( canManage && calendarEl.parentNode ) {
			calendarEl.parentNode.classList.add( 'gacct-op-can-manage' );
		}

		function capSlot( name ) {
			return capPanel ? capPanel.querySelector( '[data-cap-slot="' + name + '"]' ) : null;
		}

		function capField( name ) {
			return capPanel ? capPanel.querySelector( '[data-cap-field="' + name + '"]' ) : null;
		}

		/** 'AAAA-MM-JJ' + n jours → 'AAAA-MM-JJ' (calcul en UTC, sans dérive de fuseau). */
		function addDays( ymd, n ) {
			var parts = String( ymd ).slice( 0, 10 ).split( '-' );
			var d     = new Date( Date.UTC( +parts[ 0 ], +parts[ 1 ] - 1, +parts[ 2 ] + n ) );
			return d.toISOString().slice( 0, 10 );
		}

		function formatHours( h ) {
			h = Math.round( h * 100 ) / 100;
			return String( h ).replace( '.', ',' );
		}

		function closeCapPanel() {
			if ( capPanel ) {
				capPanel.hidden = true;
			}
			if ( overlay && ( ! panel || panel.hidden ) ) {
				overlay.hidden = true;
			}
			capRange = null;
			if ( calendar ) {
				calendar.unselect();
			}
		}

		/**
		 * Résume l'état des jours de la plage à partir des événements chargés
		 * (fonds de capacité et fermetures), sans nouvel appel serveur.
		 */
		function capSummary( start, end ) {
			var open = 0, closedDays = [], hoursSeen = {}, total = 0, occupied = 0, days = 0;

			for ( var ymd = start; ymd <= end; ymd = addDays( ymd, 1 ) ) {
				days++;
				if ( days > 400 ) {
					break;
				}
			}

			calendar.getEvents().forEach( function ( ev ) {
				var props = ev.extendedProps || {};
				var ymd   = ev.startStr ? ev.startStr.slice( 0, 10 ) : '';
				if ( ! ymd || ymd < start || ymd > end ) {
					return;
				}
				if ( 'capacity' === props.type ) {
					open++;
					total    += props.capacity || 0;
					occupied += props.occupied || 0;
					hoursSeen[ formatHours( props.capacity || 0 ) ] = true;
				} else if ( 'closure' === props.type ) {
					closedDays.push( formatFr( ymd ) + ( props.label ? ' (' + props.label + ')' : '' ) );
				}
			} );

			return { days: days, open: open, closed: closedDays, hours: Object.keys( hoursSeen ), total: total, occupied: occupied };
		}

		function openCapPanel( start, end ) {
			if ( ! capPanel || ! overlay ) {
				return;
			}

			closePanel();
			capRange = { start: start, end: end };

			var rangeEl = capSlot( 'range' );
			if ( rangeEl ) {
				rangeEl.textContent = start === end
					? formatFr( start )
					: 'Du ' + formatFr( start ) + ' au ' + formatFr( end );
			}

			var sum     = capSummary( start, end );
			var stateEl = capSlot( 'state' );
			if ( stateEl ) {
				if ( 0 === sum.open ) {
					stateEl.textContent = sum.days > 1 ? 'Aucun jour ouvert' : 'Fermé';
				} else {
					var txt = sum.days > 1 ? sum.open + ' jour(s) ouvert(s) sur ' + sum.days : 'Ouvert';
					txt += ' · ' + ( sum.hours.length === 1 ? sum.hours[ 0 ] + ' h/jour' : sum.hours.join( ' / ' ) + ' h' );
					if ( sum.occupied > 0 ) {
						txt += ' · ' + formatHours( sum.occupied ) + ' h occupées';
					}
					stateEl.textContent = txt;
				}
			}

			var closureRow = capPanel.querySelector( '[data-cap-row="closure"]' );
			var closureEl  = capSlot( 'closure' );
			if ( closureRow && closureEl ) {
				closureRow.hidden     = 0 === sum.closed.length;
				closureEl.textContent = sum.closed.slice( 0, 4 ).join( ', ' ) + ( sum.closed.length > 4 ? '…' : '' );
			}

			var hoursInput = capField( 'hours' );
			if ( hoursInput ) {
				hoursInput.value = 1 === sum.hours.length ? sum.hours[ 0 ].replace( ',', '.' ) : ( hoursInput.value || '' );
			}
			var forceInput = capField( 'force' );
			if ( forceInput ) {
				forceInput.checked = false;
			}

			var closeBtn = capPanel.querySelector( '[data-cap-closedays]' );
			if ( closeBtn ) {
				closeBtn.disabled = 0 === sum.open;
			}

			clearFeedback( capFeedback );
			overlay.hidden  = false;
			capPanel.hidden = false;

			if ( hoursInput ) {
				hoursInput.focus();
			}
		}

		function setCapacity( mode, hours, force ) {
			return post( 'gacct_op_set_capacity', {
				mode: mode,
				start: capRange.start,
				end: capRange.end,
				hours: hours || '',
				force: force ? '1' : '0'
			} );
		}

		if ( capPanel && overlay ) {
			overlay.addEventListener( 'click', closeCapPanel );

			var capCloseBtn = capPanel.querySelector( '[data-cap-close]' );
			if ( capCloseBtn ) {
				capCloseBtn.addEventListener( 'click', closeCapPanel );
			}

			document.addEventListener( 'keydown', function ( keyEvent ) {
				if ( 'Escape' === keyEvent.key && ! capPanel.hidden ) {
					closeCapPanel();
				}
			} );

			var openBtn  = capPanel.querySelector( '[data-cap-open]' );
			var closeDays = capPanel.querySelector( '[data-cap-closedays]' );

			function runCap( mode, button ) {
				if ( ! capRange ) {
					return;
				}

				var hours = '';
				var force = false;

				if ( 'open' === mode ) {
					var hoursInput = capField( 'hours' );
					hours = hoursInput ? String( hoursInput.value ).trim() : '';
					if ( ! hours || parseFloat( hours.replace( ',', '.' ) ) <= 0 ) {
						showFeedback( capFeedback, 'error', 'Indiquez un nombre d\'heures par jour.' );
						if ( hoursInput ) {
							hoursInput.focus();
						}
						return;
					}
					var forceInput = capField( 'force' );
					force = !! ( forceInput && forceInput.checked );
				} else {
					var sum = capSummary( capRange.start, capRange.end );
					var q   = 'Fermer ' + ( sum.open > 1 ? 'ces ' + sum.open + ' jours ouverts' : 'ce jour' ) + ' ?';
					if ( sum.occupied > 0 ) {
						q += '\n\nLes jours qui portent des interventions seront conservés : replanifiez-les d\'abord.';
					}
					if ( ! window.confirm( q ) ) {
						return;
					}
				}

				button.disabled = true;

				setCapacity( mode, hours, force )
					.then( function ( json ) {
						button.disabled = false;

						if ( json && json.success ) {
							closeCapPanel();
							calendar.refetchEvents();
							showFeedback( feedback, 'success', ( json.data && json.data.message ) ? json.data.message : 'Enregistré.' );
						} else {
							var msg = ( json && json.data && json.data.message ) ? json.data.message : ( i18n.genericError || 'Erreur.' );
							showFeedback( capFeedback, 'error', msg );
						}
					} )
					.catch( function () {
						button.disabled = false;
						showFeedback( capFeedback, 'error', i18n.genericError || 'Erreur.' );
					} );
			}

			if ( openBtn ) {
				openBtn.addEventListener( 'click', function () {
					runCap( 'open', openBtn );
				} );
			}
			if ( closeDays ) {
				closeDays.addEventListener( 'click', function () {
					runCap( 'close', closeDays );
				} );
			}

			var hoursField = capField( 'hours' );
			if ( hoursField && openBtn ) {
				hoursField.addEventListener( 'keydown', function ( keyEvent ) {
					if ( 'Enter' === keyEvent.key ) {
						keyEvent.preventDefault();
						runCap( 'open', openBtn );
					}
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
			selectable: canManage && !! capPanel,
			selectMirror: false,
			unselectAuto: false,
			select: function ( info ) {
				// endStr est exclusif chez FullCalendar : on ramène à la borne incluse.
				openCapPanel( info.startStr.slice( 0, 10 ), addDays( info.endStr.slice( 0, 10 ), -1 ) );
			},
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
				} else if ( canManage && capPanel && info.event.startStr ) {
					var ymd = info.event.startStr.slice( 0, 10 );
					openCapPanel( ymd, ymd );
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

		// Raccourci « Prochaine occupation : … » → saute le calendrier à cette date.
		var gotoButton = document.querySelector( '[data-gacct-goto]' );
		if ( gotoButton ) {
			gotoButton.addEventListener( 'click', function () {
				calendar.gotoDate( gotoButton.getAttribute( 'data-gacct-goto' ) );
			} );
		}
	} );
} )();
