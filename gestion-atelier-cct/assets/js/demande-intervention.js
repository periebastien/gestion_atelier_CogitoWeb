/**
 * Formulaire "demande-intervention" (JetFormBuilder, formulaire #127).
 *
 * Dépend de window.gacctDemande (localisé par le PHP) et de flatpickr (+ sa
 * locale fr), chargés par le plugin AVANT ce script. Défensif : si l'un des
 * deux manque, on ne fait rien plutôt que de planter.
 *
 * Fonctionnalités :
 *  - flatpickr sur le champ date_intervention, avec dispos calculées depuis
 *    gacctDemande.dispos (jamais depuis le DOM) ;
 *  - recalcul du résumé financier + durée totale à chaque changement d'une
 *    prestation ou du frais de port (jamais date_disponible) ;
 *  - synchronisation du radio caché date_disponible avec la date choisie ;
 *  - validation de la date au submit ;
 *  - accordéons sur les groupes de prestations.
 */
( function () {
	'use strict';

	function init() {
		var cfg = window.gacctDemande;
		if ( ! cfg || typeof window.flatpickr !== 'function' ) {
			return;
		}

		// On ne travaille QUE sur le formulaire de demande d'intervention.
		// Pas de repli sur "le premier formulaire JFB venu" : ce script pose un
		// validateur de date en capture, qui bloquerait tout autre formulaire
		// de la page (ex. le formulaire de contact).
		var form = cfg.formId
			? document.querySelector( '.jet-form-builder[data-form-id="' + cfg.formId + '"]' )
			: null;
		if ( ! form ) {
			return;
		}

		var champs = cfg.champs || {};
		var prestationNames = champs.prestations || [];
		var portName = champs.port || '';
		var dateDispoName = champs.dateDispo || '';
		var dateFieldName = champs.date || 'date_intervention';
		var dureeFieldName = champs.duree || 'duree_totale_commande';

		var prestations = cfg.prestations || {};
		var dispos = cfg.dispos || {};
		var i18n = cfg.i18n || {};
		var devise = cfg.devise || '€';

		var inputDate = form.querySelector( 'input[name="' + dateFieldName + '"]' );
		var inputDuree = form.querySelector( 'input[name="' + dureeFieldName + '"]' );

		var instanceFp = null;
		var heuresRequisesCourant = 0;

		/* ---------------------------------------------------------------
		 * Utilitaires
		 * ------------------------------------------------------------- */

		function fieldInputs( name ) {
			if ( ! name ) {
				return [];
			}
			var nodes = form.querySelectorAll(
				'[data-field-name="' + name + '"], [name="' + name + '"], [name="' + name + '[]"]'
			);
			return Array.prototype.slice.call( nodes );
		}

		function decimalToTime( decimal ) {
			if ( isNaN( decimal ) || decimal <= 0 ) {
				return '00:00';
			}
			var hrs = Math.floor( decimal );
			var mins = Math.round( ( decimal - hrs ) * 60 );
			return String( hrs ).padStart( 2, '0' ) + ':' + String( mins ).padStart( 2, '0' );
		}

		function formatMoney( n ) {
			var val = isNaN( n ) ? 0 : n;
			return val.toFixed( 2 ).replace( '.', ',' ) + ' ' + devise;
		}

		function toDateISO( date ) {
			var y = date.getFullYear();
			var m = String( date.getMonth() + 1 ).padStart( 2, '0' );
			var d = String( date.getDate() ).padStart( 2, '0' );
			return y + '-' + m + '-' + d;
		}

		function heuresDispoPour( date ) {
			var iso = toDateISO( date );
			var h = dispos[ iso ];
			return typeof h === 'number' ? h : null;
		}

		/* ---------------------------------------------------------------
		 * flatpickr
		 * ------------------------------------------------------------- */

		if ( inputDate ) {
			var demain = new Date();
			demain.setDate( demain.getDate() + 1 );

			instanceFp = window.flatpickr( inputDate, {
				locale: 'fr',
				dateFormat: 'Y-m-d',
				altInput: true,
				altFormat: 'd/m/Y',
				allowInput: true,
				disableMobile: true,
				monthSelectorType: 'static',
				minDate: demain,

				onReady: function ( selectedDates, dateStr, instance ) {
					var legend = document.createElement( 'div' );
					legend.className = 'dp-legend';
					legend.innerHTML =
						'<span class="dp-legend-item"><span class="dp-swatch available"></span>' +
						( i18n.legendeDispo || 'Disponible' ) +
						'</span>' +
						'<span class="dp-legend-item"><span class="dp-swatch selected-sw"></span>' +
						( i18n.legendeSelection || 'Sélectionné' ) +
						'</span>' +
						'<span class="dp-legend-item"><span class="dp-swatch unavailable"></span>' +
						( i18n.legendeIndispo || 'Indisponible' ) +
						'</span>';
					instance.calendarContainer.appendChild( legend );
				},

				onDayCreate: function ( dObj, dStr, fp, dayElem ) {
					dayElem.classList.remove( 'date-complete', 'date-dispo' );
					var h = heuresDispoPour( dayElem.dateObj );
					if ( h === null ) {
						return;
					}
					if ( h >= heuresRequisesCourant ) {
						dayElem.classList.add( 'date-dispo' );
					} else {
						dayElem.classList.add( 'date-complete' );
					}
				},

				disable: [
					function ( date ) {
						var h = heuresDispoPour( date );
						return h === null || h < heuresRequisesCourant;
					},
				],

				onChange: function ( selectedDates ) {
					clearDateError();
					if ( selectedDates && selectedDates[ 0 ] ) {
						syncDateDisponible( selectedDates[ 0 ] );
					}
					updateBarre();
				},
			} );
		}

		function syncDateDisponible( date ) {
			if ( ! dateDispoName ) {
				return;
			}
			var ts = Math.floor( Date.UTC( date.getFullYear(), date.getMonth(), date.getDate() ) / 1000 );
			var radios = fieldInputs( dateDispoName );
			var match = null;
			radios.forEach( function ( r ) {
				if ( String( r.value ) === String( ts ) ) {
					match = r;
				}
			} );
			if ( ! match ) {
				return;
			}
			radios.forEach( function ( r ) {
				r.checked = false;
			} );
			match.checked = true;
		}

		function actualiserCalendrier() {
			if ( ! instanceFp ) {
				return;
			}
			if ( instanceFp.selectedDates && instanceFp.selectedDates.length ) {
				var h = heuresDispoPour( instanceFp.selectedDates[ 0 ] );
				if ( h === null || h < heuresRequisesCourant ) {
					instanceFp.clear();
				}
			}
			instanceFp.redraw();
		}

		/* ---------------------------------------------------------------
		 * Validation de la date au submit
		 * ------------------------------------------------------------- */

		function dateRow() {
			return inputDate ? inputDate.closest( '.jet-form-builder-row' ) : null;
		}

		function clearDateError() {
			var row = dateRow();
			if ( ! row ) {
				return;
			}
			row.classList.remove( 'jet-form-builder-row--error' );
			var errorMsg = row.querySelector( '.jet-form-builder__error' );
			if ( errorMsg ) {
				errorMsg.remove();
			}
		}

		function showDateError() {
			var row = dateRow();
			if ( ! row ) {
				return;
			}
			row.classList.add( 'jet-form-builder-row--error' );
			var errorMsg = row.querySelector( '.jet-form-builder__error' );
			if ( ! errorMsg ) {
				errorMsg = document.createElement( 'div' );
				errorMsg.className = 'jet-form-builder-message jet-form-builder__error field-error';
				errorMsg.textContent = i18n.erreurDate || "Vous devez sélectionner une date d'intervention";
				var wrap = row.querySelector( '.jet-form-builder__field-wrap' ) || row;
				wrap.appendChild( errorMsg );
			}
			row.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}

		// Sans champ date dans ce formulaire, il n'y a rien a valider : ne jamais
		// poser le blocage (il rendrait le formulaire impossible a soumettre).
		if ( inputDate ) {
			form.addEventListener(
				'submit',
				function ( e ) {
					if ( inputDate.value.trim() === '' ) {
						e.preventDefault();
						e.stopImmediatePropagation();
						showDateError();
					}
				},
				true
			);
		}

		/* ---------------------------------------------------------------
		 * Accordéons sur les groupes de prestations
		 * ------------------------------------------------------------- */

		var accordions = [];

		function chevronSvg() {
			return (
				'<svg class="gacct-accordion__chevron" viewBox="0 0 24 24" width="18" height="18" ' +
				'fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
				'<polyline points="6 9 12 15 18 9"></polyline></svg>'
			);
		}

		function buildAccordion( name, isOpen ) {
			var input = form.querySelector(
				'[data-field-name="' + name + '"], [name="' + name + '"], [name="' + name + '[]"]'
			);
			if ( ! input ) {
				return;
			}
			var row = input.closest( '.jet-form-builder-row' );
			if ( ! row || row.hasAttribute( 'data-gacct-accordion' ) ) {
				return;
			}
			var body = row.querySelector( '.jet-form-builder__fields-group' );
			if ( ! body ) {
				return;
			}

			row.setAttribute( 'data-gacct-accordion', name );
			row.classList.add( 'gacct-accordion' );

			var legend = row.querySelector( '.jet-form-builder__label' );
			var labelText = legend ? legend.textContent.trim() : name;

			var header = document.createElement( 'div' );
			header.className = 'gacct-accordion__header';
			header.setAttribute( 'role', 'button' );
			header.setAttribute( 'tabindex', '0' );
			header.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );

			var title = document.createElement( 'span' );
			title.className = 'gacct-accordion__title';
			title.textContent = labelText;

			var badge = document.createElement( 'span' );
			badge.className = 'gacct-accordion__badge';
			badge.textContent = '0';
			badge.hidden = true;

			header.appendChild( title );
			header.appendChild( badge );
			header.insertAdjacentHTML( 'beforeend', chevronSvg() );

			if ( legend && legend.parentNode ) {
				legend.parentNode.replaceChild( header, legend );
			} else {
				row.insertBefore( header, row.firstChild );
			}

			var bodyWrap = document.createElement( 'div' );
			bodyWrap.className = 'gacct-accordion__body-wrap';
			body.parentNode.insertBefore( bodyWrap, body );
			bodyWrap.appendChild( body );
			body.classList.add( 'gacct-accordion__body' );

			function setOpen( open ) {
				header.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				row.classList.toggle( 'is-open', open );
			}
			setOpen( isOpen );

			function toggle() {
				var opening = header.getAttribute( 'aria-expanded' ) !== 'true';
				// Accordéon exclusif : ouvrir un groupe referme les autres, la
				// colonne reste courte et l'état coché survit via les badges.
				if ( opening ) {
					accordions.forEach( function ( acc ) {
						acc.setOpen( false );
					} );
				}
				setOpen( opening );
			}

			header.addEventListener( 'click', toggle );
			header.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar' ) {
					e.preventDefault();
					toggle();
				}
			} );

			accordions.push( { name: name, row: row, body: body, badge: badge, setOpen: setOpen } );
		}

		function updateAccordionBadges() {
			accordions.forEach( function ( acc ) {
				// Ne compter que les vraies cases du champ : le template produit contient
				// aussi une case décorative (.check-mark-control) qui suit l'état visuel.
				var n = acc.body.querySelectorAll( 'input[data-field-name="' + acc.name + '"]:checked' ).length;
				acc.badge.textContent = String( n );
				acc.badge.hidden = n === 0;
			} );
		}

		prestationNames.forEach( function ( name ) {
			buildAccordion( name, name === cfg.accordeonOuvert );
		} );

		/* ---------------------------------------------------------------
		 * Carte de prestation cliquable : un tap/clic n'importe où sur
		 * l'item bascule sa case (indispensable en mobile où la petite
		 * case native est masquée ; confort en desktop).
		 * ------------------------------------------------------------- */

		form.addEventListener( 'click', function ( e ) {
			var wrap = e.target.closest( '.gacct-accordion__body .jet-form-builder__field-wrap' );
			if (
				! wrap
				|| e.target.closest( 'label' )
				|| e.target.closest( 'a' )
				// Le template Crocoblock est DÉJÀ cliquable (son propre JS coche
				// l'input) : intervenir ici provoquerait une double bascule.
				|| e.target.closest( '.jet-form-builder__field-template' )
			) {
				return;
			}
			var input = wrap.querySelector( 'input.checkradio-field' );
			if ( input ) {
				input.click();
			}
		}, true ); // capture : un script du template stoppe la propagation en bulle

		/* ---------------------------------------------------------------
		 * Recalcul du résumé financier / durée
		 * ------------------------------------------------------------- */

		var listeContainer = document.getElementById( 'liste_panier' );
		var elTotalPresta = document.getElementById( 'total_prestations' );
		var elTotalPort = document.getElementById( 'total_port' );
		var elTotalGlobal = document.getElementById( 'total_global' );

		function toutRecalculer() {
			var heuresRequises = 0;
			var sommePrestations = 0;
			var sommePort = 0;
			var htmlPanier = '';

			prestationNames.forEach( function ( name ) {
				fieldInputs( name ).forEach( function ( input ) {
					if ( ! input.checked ) {
						return;
					}
					var info = prestations[ input.value ];
					if ( ! info ) {
						return;
					}
					var prix = parseFloat( info.prix ) || 0;
					var duree = parseFloat( info.duree ) || 0;
					var titre = info.titre || 'Prestation';

					sommePrestations += prix;
					heuresRequises += duree;

					htmlPanier +=
						'<div class="gacct-panier-item">' +
						'<span class="gacct-panier-item__label">• ' + titre + '</span>' +
						'<span class="gacct-panier-item__price">' + formatMoney( prix ) + '</span>' +
						'</div>';
				} );
			} );

			if ( portName ) {
				fieldInputs( portName ).forEach( function ( input ) {
					if ( ! input.checked ) {
						return;
					}
					var info = prestations[ input.value ];
					var prix = info ? parseFloat( info.prix ) || 0 : 0;
					sommePort += prix;
				} );
			}

			if ( listeContainer ) {
				listeContainer.innerHTML =
					htmlPanier ||
					'<div class="gacct-panier-empty">' + ( i18n.aucuneSelection || 'Aucune prestation sélectionnée' ) + '</div>';
			}

			if ( inputDuree ) {
				inputDuree.value = decimalToTime( heuresRequises );
			}

			if ( elTotalPresta ) {
				elTotalPresta.textContent = formatMoney( sommePrestations );
			}
			if ( elTotalPort ) {
				elTotalPort.textContent = formatMoney( sommePort );
			}
			if ( elTotalGlobal ) {
				elTotalGlobal.textContent = formatMoney( sommePrestations + sommePort );
			}

			heuresRequisesCourant = heuresRequises;
			dernierTotalGlobal = sommePrestations + sommePort;

			actualiserCalendrier();
			updateAccordionBadges();
			updateBarre();
		}

		/* ---------------------------------------------------------------
		 * Barre panier mobile fixée en bas de fenêtre (compacte, dépliable).
		 * Injectée hors du formulaire ; le CSS ne l'affiche qu'en <= 781px.
		 * ------------------------------------------------------------- */

		var barre = null;
		var barreTotal = null;
		var barreDate = null;
		var barreDetail = null;
		var barreInfos = null;

		function buildBarreMobile() {
			barreDetail = document.createElement( 'div' );
			barreDetail.className = 'gacct-barre-detail';

			barre = document.createElement( 'div' );
			barre.className = 'gacct-barre';

			barreInfos = document.createElement( 'div' );
			barreInfos.className = 'gacct-barre-infos';
			barreInfos.setAttribute( 'role', 'button' );
			barreInfos.setAttribute( 'tabindex', '0' );
			barreInfos.setAttribute( 'aria-expanded', 'false' );

			barreTotal = document.createElement( 'div' );
			barreTotal.className = 'gacct-barre-total';

			var chevron = document.createElement( 'span' );
			chevron.className = 'gacct-barre-chevron';
			chevron.innerHTML = chevronSvg();

			barreDate = document.createElement( 'div' );
			barreDate.className = 'gacct-barre-date';

			barreInfos.appendChild( barreTotal );
			barreTotal.appendChild( chevron );
			barreInfos.appendChild( barreDate );

			var envoyer = document.createElement( 'button' );
			envoyer.type = 'button';
			envoyer.className = 'gacct-barre-envoyer';
			var submitNatif = form.querySelector( '.jet-form-builder__submit' );
			envoyer.textContent = submitNatif ? submitNatif.textContent.trim() : 'Envoyer la demande';
			envoyer.addEventListener( 'click', function () {
				// Passe par la soumission native pour déclencher toutes les
				// validations (la nôtre en capture + celles de JetFormBuilder).
				if ( typeof form.requestSubmit === 'function' ) {
					form.requestSubmit();
				} else if ( submitNatif ) {
					submitNatif.click();
				}
			} );

			function toggleDetail() {
				var ouvre = ! barreDetail.classList.contains( 'est-ouvert' );
				if ( ouvre ) {
					refreshBarreDetail();
					// Colle le panneau à la hauteur réelle de la barre pour
					// qu'ils forment visuellement un seul élément.
					barreDetail.style.bottom = barre.offsetHeight + 'px';
				}
				barreDetail.classList.toggle( 'est-ouvert', ouvre );
				barre.classList.toggle( 'est-ouvert', ouvre );
				barreInfos.setAttribute( 'aria-expanded', ouvre ? 'true' : 'false' );
			}
			barreInfos.addEventListener( 'click', toggleDetail );
			barreInfos.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar' ) {
					e.preventDefault();
					toggleDetail();
				}
			} );

			barre.appendChild( barreInfos );
			barre.appendChild( envoyer );
			document.body.appendChild( barreDetail );
			document.body.appendChild( barre );
		}

		function refreshBarreDetail() {
			var resume = document.getElementById( 'resume_financier' );
			if ( ! barreDetail || ! resume ) {
				return;
			}
			// Copie du résumé en flux (masqué en mobile), sans dupliquer les IDs.
			var clone = resume.cloneNode( true );
			clone.removeAttribute( 'id' );
			// Le bloc d'origine porte un style inline « carte » (fond, bordure,
			// padding) : on le retire pour que le clone fonde dans le panneau.
			clone.removeAttribute( 'style' );
			clone.querySelectorAll( '[id]' ).forEach( function ( el ) {
				el.removeAttribute( 'id' );
			} );
			barreDetail.innerHTML = '';
			barreDetail.appendChild( clone );
		}

		var dernierTotalGlobal = 0;

		function updateBarre() {
			if ( ! barre ) {
				return;
			}
			var chevron = barreTotal.querySelector( '.gacct-barre-chevron' );
			barreTotal.textContent = formatMoney( dernierTotalGlobal );
			if ( chevron ) {
				barreTotal.appendChild( chevron );
			}
			var iso = inputDate ? inputDate.value : '';
			barreDate.textContent = iso
				? iso.split( '-' ).reverse().join( '/' )
				: ( i18n.aucuneDate || 'Aucune date choisie' );
			if ( barreDetail.classList.contains( 'est-ouvert' ) ) {
				refreshBarreDetail();
			}
		}

		buildBarreMobile();

		form.addEventListener( 'change', function ( e ) {
			var target = e.target;
			if ( ! target || ( target.type !== 'checkbox' && target.type !== 'radio' ) ) {
				return;
			}
			var fieldName = target.getAttribute( 'data-field-name' ) || target.name;
			if ( fieldName === dateDispoName ) {
				return;
			}
			toutRecalculer();
		} );

		toutRecalculer();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
