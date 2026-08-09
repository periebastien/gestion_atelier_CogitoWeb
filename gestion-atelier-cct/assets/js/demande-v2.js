/**
 * Parcours multi-étapes « Demande d'intervention » (v2, formulaire JetFormBuilder
 * multi-pages) — reprend la mécanique métier du formulaire historique
 * (demande-intervention.js) : flatpickr alimenté par gacctDemande.dispos, cumul des
 * durées → duree_totale_commande, synchro du radio caché date_disponible, palette de
 * couleurs, sélecteur « Votre matériel », accordéons de prestations, totaux.
 *
 * S'y ajoutent : libellé de progression « Étape X sur 4 — nom », validations par
 * étape (au moins une prestation, date, retour) et récapitulatif de l'étape 4.
 *
 * Dépend de window.gacctDemande (formId = formulaire v2, v2 = config des étapes)
 * et de flatpickr. Défensif : si l'un manque, on ne fait rien.
 */
( function () {
	'use strict';

	/* La capture de l'instance multistep JFB doit être enregistrée AVANT que
	   JetFormBuilder n'initialise le formulaire : on le fait dès l'exécution du
	   script, hors DOMContentLoaded. */
	var multistep = null;

	if ( window.JetPlugins && window.JetPlugins.hooks ) {
		window.JetPlugins.hooks.addAction(
			'jet.fb.multistep.init',
			'gacct/demande-v2',
			function ( ms ) {
				multistep = ms;
			}
		);
	}

	function init() {
		var cfg = window.gacctDemande;
		if ( ! cfg || typeof window.flatpickr !== 'function' ) {
			return;
		}

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
		var v2 = cfg.v2 || {};
		var v2i18n = v2.i18n || {};
		var etapes = v2.etapes || {};
		var devise = cfg.devise || '€';

		var inputDate = form.querySelector( 'input[name="' + dateFieldName + '"]' );
		var inputDuree = form.querySelector( 'input[name="' + dureeFieldName + '"]' );

		var instanceFp = null;
		var heuresRequisesCourant = 0;
		var nbPrestationsCourant = 0;
		var dernierTotalGlobal = 0;
		var derniereSommePrestations = 0;
		var dernierAcompte = 0;

		/* ---------------------------------------------------------------
		 * Utilitaires (repris du formulaire historique)
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
			// Format maquette : virgule décimale, « ,00 » retiré (« 180 € », « 15,90 € »).
			var txt = val.toFixed( 2 ).replace( '.', ',' ).replace( /,00$/, '' );
			return txt + ' ' + devise;
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

		function escapeHtml( str ) {
			var div = document.createElement( 'div' );
			div.textContent = str == null ? '' : String( str );
			return div.innerHTML;
		}

		function sprintf1( template, value ) {
			return String( template || '%s' ).replace( '%s', value );
		}

		/* ---------------------------------------------------------------
		 * Navigation multi-étapes : page courante, goto, libellé de progression
		 * ------------------------------------------------------------- */

		function pages() {
			return Array.prototype.slice.call( form.querySelectorAll( '.jet-form-builder-page' ) );
		}

		function pageCourante() {
			var visible = pages().filter( function ( p ) {
				return ! p.classList.contains( 'jet-form-builder-page--hidden' );
			} )[ 0 ];
			return visible ? parseInt( visible.dataset.page, 10 ) : 1;
		}

		function gotoPage( n ) {
			if ( multistep && multistep.index ) {
				multistep.index.current = n;
				return;
			}
			// Repli sans instance : navigation arrière par les boutons « Retour »
			// (jamais de validation sur un retour, donc toujours permise).
			var current = pageCourante();
			while ( current > n ) {
				var prev = pages()[ current - 1 ].querySelector( '.jet-form-builder__prev-page' );
				if ( ! prev ) {
					return;
				}
				prev.click();
				current--;
			}
		}

		var progressLabel = null;

		function buildProgressLabel() {
			var bar = form.querySelector( '.jet-form-builder-progress-pages' );
			if ( ! bar ) {
				return;
			}
			progressLabel = document.createElement( 'p' );
			progressLabel.className = 'gacct-v2-progress-label';
			bar.parentNode.insertBefore( progressLabel, bar );
			updateProgressLabel();
		}

		function updateProgressLabel() {
			if ( ! progressLabel ) {
				return;
			}
			var n = pageCourante();
			var total = pages().length || 4;
			var etape = ( v2i18n.etapeSur || 'Étape %1$s sur %2$s' )
				.replace( '%1$s', n )
				.replace( '%2$s', total );
			var nom = etapes[ n ] || '';
			progressLabel.innerHTML =
				etape.replace( String( n ), '<strong>' + n + '</strong>' ) +
				( nom ? ' — <strong>' + escapeHtml( nom ) + '</strong>' : '' );
		}

		/* ---------------------------------------------------------------
		 * Erreurs d'étape (composant maquette .gacct-v2-err)
		 * ------------------------------------------------------------- */

		var erreurs = {};

		function erreurEtape( cle, anchorEl, message ) {
			if ( ! erreurs[ cle ] ) {
				var el = document.createElement( 'div' );
				el.className = 'gacct-v2-err';
				el.setAttribute( 'role', 'alert' );
				anchorEl.parentNode.insertBefore( el, anchorEl );
				erreurs[ cle ] = el;
			}
			erreurs[ cle ].textContent = message;
			erreurs[ cle ].classList.add( 'is-on' );
			erreurs[ cle ].scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}

		function effaceErreur( cle ) {
			if ( erreurs[ cle ] ) {
				erreurs[ cle ].classList.remove( 'is-on' );
			}
		}

		/* Validation par étape, en capture AVANT le handler JFB du bouton
		   « Continuer » : si notre règle échoue, on bloque le passage. Les champs
		   requis natifs (taille, P.T.V., couleurs…) restent validés par JFB. */
		form.addEventListener(
			'click',
			function ( e ) {
				var next = e.target.closest( '.jet-form-builder__next-page' );
				if ( ! next ) {
					return;
				}
				var page = e.target.closest( '.jet-form-builder-page' );
				var n = page ? parseInt( page.dataset.page, 10 ) : 0;

				if ( n === 1 && ! voileValide() ) {
					e.preventDefault();
					e.stopImmediatePropagation();
					erreurEtape( 'voile', next.closest( '.jet-form-builder__next-page-wrap' ), v2i18n.erreurVoile || 'Indiquez votre voile pour continuer.' );
					return;
				}

				if ( n === 2 && nbPrestationsCourant === 0 ) {
					e.preventDefault();
					e.stopImmediatePropagation();
					erreurEtape( 'presta', next.closest( '.jet-form-builder__next-page-wrap' ), v2i18n.erreurPresta || 'Choisissez au moins une prestation pour continuer.' );
					return;
				}

				if ( n === 3 ) {
					if ( inputDate && inputDate.value.trim() === '' ) {
						e.preventDefault();
						e.stopImmediatePropagation();
						// L'erreur date s'affiche SOUS le calendrier (maquette),
						// pas en bas de page : ancre = le groupe caché qui suit.
						var ancreDate = form.querySelector( '.jet-form-builder-page[data-page="3"] .champ-date-cache' )
							|| next.closest( '.jet-form-builder__next-page-wrap' );
						erreurEtape( 'date', ancreDate, v2i18n.erreurDate || 'Choisissez un jour disponible dans le calendrier.' );
						return;
					}
					effaceErreur( 'date' );

					var retourChoisi = fieldInputs( portName ).some( function ( el ) {
						return ( el.type === 'radio' || el.type === 'checkbox' ) && el.checked;
					} );
					if ( ! retourChoisi ) {
						e.preventDefault();
						e.stopImmediatePropagation();
						erreurEtape( 'retour', next.closest( '.jet-form-builder__next-page-wrap' ), v2i18n.erreurRetour || 'Choisissez comment récupérer votre voile.' );
					}
				}
			},
			true
		);

		/* ---------------------------------------------------------------
		 * flatpickr (identique au formulaire historique)
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
				// Calendrier affiché en permanence dans la carte (maquette 1918),
				// pas un popup sur un champ ; les inputs sont masqués en CSS.
				inline: true,

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
					effaceErreur( 'date' );
					if ( selectedDates && selectedDates[ 0 ] ) {
						syncDateDisponible( selectedDates[ 0 ] );
					}
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
		 * Recherche de voile (étape 1) — combobox sur le référentiel
		 * (cfg.v2.voilesUrl), repli manuel marque/modèle, carte de
		 * confirmation. Les champs JFB marque/modèle sont cachés et servent
		 * de stockage ; toute l'UI vit ici.
		 * ------------------------------------------------------------- */

		var voile = {
			liste: [],        // [{m, mo, an, rec, k}]
			marques: [],
			choisie: null,    // {m, mo, an} ou null
			manuel: false,
			ui: null,
		};

		function norm( s ) {
			return String( s || '' )
				.toLowerCase()
				.normalize( 'NFD' )
				.replace( /[\u0300-\u036f]/g, '' )
				.replace( /[^a-z0-9]+/g, ' ' )
				.trim();
		}

		function champMarqueCache() {
			return form.querySelector( 'input[name="marque"]' );
		}

		function champModeleCache() {
			return form.querySelector( 'input[name="modele"]' );
		}

		function ecrireVoile( marque, modele ) {
			[ [ champMarqueCache(), marque ], [ champModeleCache(), modele ] ].forEach( function ( paire ) {
				var el = paire[ 0 ];
				if ( ! el ) {
					return;
				}
				el.value = paire[ 1 ] || '';
				el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
		}

		function voileValide() {
			if ( voile.choisie ) {
				return true;
			}
			if ( voile.manuel && voile.ui ) {
				var marque = marqueManuelle();
				var modele = voile.ui.manuModele.value.trim();
				if ( marque && modele ) {
					ecrireVoile( marque, modele );
					return true;
				}
			}
			return false;
		}

		function marqueManuelle() {
			if ( ! voile.ui ) {
				return '';
			}
			var sel = voile.ui.manuMarque.value;
			if ( sel === '__autre__' ) {
				return voile.ui.manuAutre.value.trim();
			}
			return sel;
		}

		/* Algorithme de la maquette : tokens en substring (ordre libre), score
		   0 = le modèle commence par la saisie, 1 = un mot du modèle commence
		   par la saisie, 2 = la marque commence par la saisie, 3 = ailleurs ;
		   tri score → récence → année ; 8 résultats. */
		function chercherVoiles( q ) {
			var nq = norm( q );
			if ( nq.length < 2 ) {
				return [];
			}
			var tokens = nq.split( ' ' );
			var resultats = [];

			voile.liste.forEach( function ( v ) {
				for ( var i = 0; i < tokens.length; i++ ) {
					if ( v.k.indexOf( tokens[ i ] ) === -1 ) {
						return;
					}
				}
				var nm = norm( v.mo );
				var score = 3;
				if ( nm.indexOf( nq ) === 0 ) {
					score = 0;
				} else if ( ( ' ' + nm ).indexOf( ' ' + nq ) > -1 ) {
					score = 1;
				} else if ( norm( v.m ).indexOf( nq ) === 0 ) {
					score = 2;
				}
				resultats.push( { v: v, score: score } );
			} );

			resultats.sort( function ( a, b ) {
				if ( a.score !== b.score ) {
					return a.score - b.score;
				}
				if ( ( b.v.rec || 0 ) !== ( a.v.rec || 0 ) ) {
					return ( b.v.rec || 0 ) - ( a.v.rec || 0 );
				}
				return ( parseInt( b.v.an, 10 ) || 0 ) - ( parseInt( a.v.an, 10 ) || 0 );
			} );

			return resultats.slice( 0, 8 ).map( function ( r ) {
				return r.v;
			} );
		}

		/* Détails de la voile (taille, P.T.V., n° de série, couleurs) : masqués
		   tant qu'aucune voile n'est choisie ni le mode manuel activé (maquette
		   1918, §2.5). */
		var detailEls = [];

		function initDetailsVoile() {
			// ⚠ Le mode manuel utilise AUSSI la classe gacct-v2-grille-2 : viser
			// la grille qui contient le champ taille, pas la première venue.
			var taille = form.querySelector( 'input[name="taille"]' );
			var grille = taille ? taille.closest( '.gacct-v2-grille-2' ) : null;
			var serie = form.querySelector( 'input[name="numero_serie"]' );
			var couleur = form.querySelector( '[name="' + ( champs.couleur || 'couleur_copy' ) + '"]' );

			detailEls = [
				grille,
				serie ? serie.closest( '.jet-form-builder-row' ) : null,
				couleur ? couleur.closest( '.jet-form-builder-row' ) : null,
			].filter( Boolean );

			detailEls.forEach( function ( el, i ) {
				el.classList.add( 'gacct-v2-detail' );
				if ( 0 === i ) {
					el.classList.add( 'gacct-v2-detail--premier' );
				}
			} );
			majDetailsVoile();
		}

		function majDetailsVoile() {
			var visibles = !! ( voile.choisie || voile.manuel );
			detailEls.forEach( function ( el ) {
				el.classList.toggle( 'is-off', ! visibles );
			} );
		}

		function initRechercheVoile() {
			var marqueRow = champMarqueCache() ? champMarqueCache().closest( '.jet-form-builder-row' ) : null;
			if ( ! marqueRow ) {
				return;
			}

			var bloc = document.createElement( 'div' );
			bloc.className = 'gacct-v2-voile';
			bloc.innerHTML =
				'<div class="gacct-v2-combo">' +
				'<label class="gacct-v2-combo-label" for="gacctV2Search">' + escapeHtml( v2i18n.comboLabel || 'Marque et modèle' ) + '</label>' +
				'<input type="text" id="gacctV2Search" autocomplete="off" placeholder="' + escapeHtml( v2i18n.comboPlaceholder || '' ) + '">' +
				'<div class="gacct-v2-sugg" role="listbox"></div>' +
				'<p class="gacct-v2-aide">' + escapeHtml( v2i18n.comboAide || '' ) + '</p>' +
				'</div>' +
				'<div class="gacct-v2-notwrap">' +
				'<button type="button" class="gacct-v2-notfound">' + plusSvg() + escapeHtml( v2i18n.pasDansListe || 'Ma voile n’est pas dans la liste' ) + '</button>' +
				'</div>' +
				'<div class="gacct-v2-chosen">' +
				'<span class="gacct-v2-c-check" aria-hidden="true">✓</span>' +
				'<span class="gacct-v2-c-infos"><strong></strong><small></small></span>' +
				'<button type="button" class="gacct-v2-c-edit">' + escapeHtml( v2i18n.modifier || 'Modifier' ) + '</button>' +
				'</div>' +
				'<div class="gacct-v2-manual">' +
				'<p class="gacct-v2-manual-intro"><strong>' + escapeHtml( ( v2i18n.manuelIntro || '' ).split( ':' )[ 0 ] + ' :' ) + '</strong>' +
				escapeHtml( ( v2i18n.manuelIntro || '' ).split( ':' ).slice( 1 ).join( ':' ) ) + '</p>' +
				'<div class="gacct-v2-grille-2">' +
				'<div class="gacct-v2-champ"><label>' + escapeHtml( v2i18n.manuelMarque || 'Marque' ) + '</label>' +
				'<select class="gacct-v2-manu-marque"><option value="">' + escapeHtml( v2i18n.manuelChoisir || 'Choisir la marque…' ) + '</option></select></div>' +
				'<div class="gacct-v2-champ"><label>' + escapeHtml( v2i18n.manuelModele || 'Modèle' ) + '</label>' +
				'<input type="text" class="gacct-v2-manu-modele" placeholder="' + escapeHtml( v2i18n.manuelModelePh || '' ) + '"></div>' +
				'</div>' +
				'<div class="gacct-v2-champ gacct-v2-manu-autre-wrap" hidden><label>' + escapeHtml( v2i18n.manuelPreciser || 'Précisez la marque' ) + '</label>' +
				'<input type="text" class="gacct-v2-manu-autre" placeholder="' + escapeHtml( v2i18n.manuelPreciserPh || '' ) + '"></div>' +
				'</div>';

			marqueRow.parentNode.insertBefore( bloc, marqueRow );

			var ui = {
				bloc: bloc,
				combo: bloc.querySelector( '.gacct-v2-combo' ),
				input: bloc.querySelector( '#gacctV2Search' ),
				sugg: bloc.querySelector( '.gacct-v2-sugg' ),
				notwrap: bloc.querySelector( '.gacct-v2-notwrap' ),
				chosen: bloc.querySelector( '.gacct-v2-chosen' ),
				chosenNom: bloc.querySelector( '.gacct-v2-c-infos strong' ),
				chosenSub: bloc.querySelector( '.gacct-v2-c-infos small' ),
				manual: bloc.querySelector( '.gacct-v2-manual' ),
				manuMarque: bloc.querySelector( '.gacct-v2-manu-marque' ),
				manuModele: bloc.querySelector( '.gacct-v2-manu-modele' ),
				manuAutre: bloc.querySelector( '.gacct-v2-manu-autre' ),
				manuAutreWrap: bloc.querySelector( '.gacct-v2-manu-autre-wrap' ),
			};
			voile.ui = ui;

			// Chargement du référentiel (statique, mis en cache navigateur).
			if ( v2.voilesUrl ) {
				window.fetch( v2.voilesUrl )
					.then( function ( r ) { return r.json(); } )
					.then( function ( data ) {
						voile.liste = ( data.voiles || [] ).map( function ( t ) {
							return { m: t[ 0 ], mo: t[ 1 ], an: t[ 2 ], rec: t[ 3 ], k: norm( t[ 0 ] + ' ' + t[ 1 ] ) };
						} );
						voile.marques = data.marques || [];
						voile.marques.forEach( function ( m ) {
							var opt = document.createElement( 'option' );
							opt.value = m;
							opt.textContent = m;
							ui.manuMarque.appendChild( opt );
						} );
						var autre = document.createElement( 'option' );
						autre.value = '__autre__';
						autre.textContent = v2i18n.manuelAutre || 'Autre marque…';
						ui.manuMarque.appendChild( autre );
					} )
					.catch( function () {
						// Référentiel indisponible : le mode manuel reste utilisable.
						basculeManuel();
					} );
			}

			function fermerSugg() {
				ui.sugg.classList.remove( 'is-open' );
			}

			function renderSugg( q ) {
				var res = chercherVoiles( q );
				var html = '';

				res.forEach( function ( v ) {
					html +=
						'<button type="button" class="gacct-v2-s-item" data-m="' + escapeHtml( v.m ) + '" data-mo="' + escapeHtml( v.mo ) + '" data-an="' + escapeHtml( v.an ) + '">' +
						'<span class="gacct-v2-s-txt"><strong>' + escapeHtml( v.mo ) + '</strong><span class="gacct-v2-s-brand">' + escapeHtml( v.m ) + '</span></span>' +
						( v.an ? '<span class="gacct-v2-s-year">' + escapeHtml( v.an ) + '</span>' : '' ) +
						'</button>';
				} );

				if ( ! res.length && norm( q ).length >= 2 ) {
					html += '<div class="gacct-v2-s-empty">' +
						escapeHtml( ( v2i18n.comboVide || 'Aucune voile trouvée pour « %s »' ).replace( '%s', q ) ) + '</div>';
				}

				html += '<button type="button" class="gacct-v2-s-other">' + plusSvg() +
					escapeHtml( v2i18n.pasDansListe || 'Ma voile n’est pas dans la liste' ) + '</button>';

				ui.sugg.innerHTML = html;
				ui.sugg.classList.add( 'is-open' );
			}

			function choisir( m, mo, an ) {
				voile.choisie = { m: m, mo: mo, an: an };
				voile.manuel = false;
				ecrireVoile( m, mo );
				ui.chosenNom.textContent = m + ' — ' + mo;
				ui.chosenSub.textContent = an ? ( v2i18n.sortieEn || 'Modèle sorti en %s' ).replace( '%s', an ) : '';
				ui.combo.style.display = 'none';
				ui.notwrap.style.display = 'none';
				ui.manual.classList.remove( 'is-on' );
				ui.chosen.classList.add( 'is-on' );
				fermerSugg();
				effaceErreur( 'voile' );
				majDetailsVoile();
			}
			voile.choisirExterne = choisir;

			function basculeManuel() {
				voile.choisie = null;
				voile.manuel = true;
				ecrireVoile( '', '' );
				ui.combo.style.display = 'none';
				ui.notwrap.style.display = 'none';
				ui.chosen.classList.remove( 'is-on' );
				ui.manual.classList.add( 'is-on' );
				fermerSugg();
				effaceErreur( 'voile' );
				majDetailsVoile();
			}
			voile.basculeManuel = basculeManuel;

			function reafficherRecherche() {
				voile.choisie = null;
				voile.manuel = false;
				ecrireVoile( '', '' );
				ui.chosen.classList.remove( 'is-on' );
				ui.manual.classList.remove( 'is-on' );
				ui.combo.style.display = '';
				ui.notwrap.style.display = '';
				ui.input.value = '';
				ui.input.focus();
				majDetailsVoile();
			}
			voile.reafficher = reafficherRecherche;

			ui.input.addEventListener( 'input', function () {
				renderSugg( ui.input.value );
			} );
			ui.input.addEventListener( 'focus', function () {
				if ( ui.input.value.trim() ) {
					renderSugg( ui.input.value );
				}
			} );
			document.addEventListener( 'click', function ( e ) {
				if ( ! ui.combo.contains( e.target ) ) {
					fermerSugg();
				}
			} );

			ui.sugg.addEventListener( 'click', function ( e ) {
				var item = e.target.closest( '.gacct-v2-s-item' );
				if ( item ) {
					choisir( item.dataset.m, item.dataset.mo, item.dataset.an );
					return;
				}
				if ( e.target.closest( '.gacct-v2-s-other' ) ) {
					basculeManuel();
				}
			} );

			bloc.querySelector( '.gacct-v2-notfound' ).addEventListener( 'click', basculeManuel );
			bloc.querySelector( '.gacct-v2-c-edit' ).addEventListener( 'click', reafficherRecherche );

			ui.manuMarque.addEventListener( 'change', function () {
				ui.manuAutreWrap.hidden = ui.manuMarque.value !== '__autre__';
				syncManuel();
			} );
			[ ui.manuModele, ui.manuAutre ].forEach( function ( el ) {
				el.addEventListener( 'input', syncManuel );
			} );

			function syncManuel() {
				if ( ! voile.manuel ) {
					return;
				}
				var marque = marqueManuelle();
				var modele = ui.manuModele.value.trim();
				ecrireVoile( marque, modele );
				if ( marque && modele ) {
					effaceErreur( 'voile' );
				}
			}
		}

		function plusSvg() {
			return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" ' +
				'stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line>' +
				'<line x1="5" y1="12" x2="19" y2="12"></line></svg>';
		}

		initRechercheVoile();
		initDetailsVoile();

		/* ---------------------------------------------------------------
		 * Sélecteur de couleurs (repris du formulaire historique)
		 * ------------------------------------------------------------- */

		function initSelecteurCouleurs() {
			var couleurs = cfg.couleurs || [];
			var maxi = cfg.couleursMax || 3;
			var input = form.querySelector( '[name="' + ( champs.couleur || 'couleur_copy' ) + '"]' );

			if ( ! input || ! couleurs.length ) {
				return;
			}

			var row = input.closest( '.jet-form-builder-row' );
			var wrap = row ? row.querySelector( '.jet-form-builder__field-wrap' ) : null;
			if ( ! row || ! wrap ) {
				return;
			}

			row.classList.add( 'gacct-couleurs-row' );
			input.readOnly = true;
			input.tabIndex = -1;
			input.setAttribute( 'aria-hidden', 'true' );

			var choisies = valeursInitiales();

			var palette = document.createElement( 'div' );
			palette.className = 'gacct-couleurs';

			var grille = document.createElement( 'div' );
			grille.className = 'gacct-couleurs__grille';
			grille.setAttribute( 'role', 'group' );
			grille.setAttribute( 'aria-label', input.getAttribute( 'aria-label' ) || 'Couleurs de la voile' );

			couleurs.forEach( function ( c ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'gacct-couleur';
				b.dataset.nom = c.nom;
				b.style.setProperty( '--gacct-teinte', c.hex );
				b.setAttribute( 'role', 'checkbox' );
				b.setAttribute( 'aria-checked', 'false' );
				b.innerHTML =
					'<span class="gacct-couleur__pastille" aria-hidden="true"></span>' +
					'<span class="gacct-couleur__nom">' + c.nom + '</span>';
				b.addEventListener( 'click', function () {
					basculer( c.nom );
				} );
				grille.appendChild( b );
			} );

			var pied = document.createElement( 'div' );
			pied.className = 'gacct-couleurs__pied';
			pied.innerHTML =
				'<span class="gacct-couleurs__aide">' +
				escapeHtml( v2i18n.couleurAide || i18n.couleurAide || 'Cliquez sur vos couleurs.' ) +
				'</span><span class="gacct-couleurs__apercu" aria-hidden="true"></span>';

			palette.appendChild( grille );
			palette.appendChild( pied );
			wrap.appendChild( palette );

			var apercu = pied.querySelector( '.gacct-couleurs__apercu' );

			function valeursInitiales() {
				var brut = ( input.value || '' ).toLowerCase();
				var trouvees = [];
				couleurs.forEach( function ( c ) {
					var pos = brut.indexOf( c.nom );
					if ( pos > -1 ) {
						trouvees.push( { nom: c.nom, pos: pos } );
					}
				} );
				return trouvees
					.sort( function ( a, b ) { return a.pos - b.pos; } )
					.slice( 0, maxi )
					.map( function ( t ) { return t.nom; } );
			}

			function basculer( nom ) {
				var i = choisies.indexOf( nom );
				if ( i > -1 ) {
					choisies.splice( i, 1 );
				} else if ( choisies.length < maxi ) {
					choisies.push( nom );
				} else {
					return;
				}
				appliquer();
			}

			function teinte( nom ) {
				for ( var i = 0; i < couleurs.length; i++ ) {
					if ( couleurs[ i ].nom === nom ) {
						return couleurs[ i ].hex;
					}
				}
				return '#e5e7eb';
			}

			function degrade() {
				// Un dégradé (« multicolore ») ne se combine pas dans un
				// linear-gradient : seul, on l'affiche tel quel ; combiné, on le
				// remplace par une teinte de repli.
				var h = choisies.map( teinte );
				if ( h.length === 1 ) {
					return h[ 0 ];
				}
				h = h.map( function ( t ) {
					return t.indexOf( 'gradient' ) > -1 ? '#d33333' : t;
				} );
				if ( h.length === 2 ) {
					return 'linear-gradient(135deg, ' + h[ 0 ] + ' 50%, ' + h[ 1 ] + ' 50%)';
				}
				if ( h.length === 3 ) {
					return 'linear-gradient(135deg, ' + h[ 0 ] + ' 33.33%, ' + h[ 1 ] + ' 33.33% 66.66%, ' + h[ 2 ] + ' 66.66%)';
				}
				if ( h.length >= 4 ) {
					return 'linear-gradient(135deg, ' + h[ 0 ] + ' 25%, ' + h[ 1 ] + ' 25% 50%, ' + h[ 2 ] + ' 50% 75%, ' + h[ 3 ] + ' 75%)';
				}
				return '';
			}

			function appliquer() {
				Array.prototype.forEach.call( grille.children, function ( b ) {
					var actif = choisies.indexOf( b.dataset.nom ) > -1;
					b.classList.toggle( 'is-active', actif );
					b.setAttribute( 'aria-checked', actif ? 'true' : 'false' );
					b.classList.toggle( 'is-muted', ! actif && choisies.length >= maxi );
				} );

				palette.classList.toggle( 'has-selection', choisies.length > 0 );
				apercu.style.background = degrade();

				// Rappel des couleurs en toutes lettres (« Votre choix : … »).
				var aideEl = pied.querySelector( '.gacct-couleurs__aide' );
				if ( aideEl ) {
					aideEl.textContent = choisies.length
						? ( v2i18n.couleurChoix || 'Votre choix : %s' ).replace( '%s', choisies.join( ', ' ) )
						: ( v2i18n.couleurAide || i18n.couleurAide || '' );
				}

				input.value = choisies.join( ', ' );
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}

			appliquer();
		}

		initSelecteurCouleurs();

		/* ---------------------------------------------------------------
		 * Sélecteur « Votre matériel » (repris du formulaire historique)
		 * ------------------------------------------------------------- */

		function initSelecteurMateriel() {
			var materiels = cfg.materiels || [];
			if ( ! materiels.length ) {
				return;
			}

			var champMarque = form.querySelector( '[name="marque"]' );
			var champModele = form.querySelector( '[name="modele"]' );
			var champSerie = form.querySelector( '[name="numero_serie"]' );
			var champTaille = form.querySelector( '[name="taille"]' );
			var champPtv = form.querySelector( '[name="ptv"]' );
			var champCouleur = form.querySelector( '[name="' + ( champs.couleur || 'couleur_copy' ) + '"]' );

			// Ancrage v2 : au-dessus du bloc de recherche de voile (repli : la
			// ligne du champ marque).
			var ancre = form.querySelector( '.gacct-v2-voile' )
				|| ( champMarque ? champMarque.closest( '.jet-form-builder-row' ) : null );
			if ( ! ancre || ! champMarque ) {
				return;
			}

			function remplirChamp( el, valeur ) {
				if ( ! el ) {
					return;
				}
				valeur = valeur || '';

				if ( el.tagName === 'SELECT' ) {
					var match = null;
					Array.prototype.forEach.call( el.options, function ( opt ) {
						if ( match || ! valeur ) {
							return;
						}
						if ( opt.value === valeur || opt.text === valeur ) {
							match = opt;
						} else if (
							opt.value.toLowerCase() === valeur.toLowerCase() ||
							opt.text.toLowerCase() === valeur.toLowerCase()
						) {
							match = opt;
						}
					} );
					el.value = match ? match.value : '';
				} else {
					el.value = valeur;
				}

				el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				if ( window.jQuery ) {
					window.jQuery( el ).trigger( 'change' );
				}
			}

			function appliquerCouleur( texteCouleur ) {
				if ( ! champCouleur ) {
					return;
				}
				var couleursConnues = ( cfg.couleurs || [] ).map( function ( c ) { return c.nom; } );
				var brut = ( texteCouleur || '' ).toLowerCase();
				var trouvees = [];
				couleursConnues.forEach( function ( nom ) {
					if ( brut.indexOf( nom ) > -1 && trouvees.indexOf( nom ) === -1 ) {
						trouvees.push( nom );
					}
				} );

				champCouleur.value = trouvees.join( ', ' );
				champCouleur.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				champCouleur.dispatchEvent( new Event( 'change', { bubbles: true } ) );

				var paletteExistante = champCouleur.parentNode
					? champCouleur.parentNode.querySelector( '.gacct-couleurs' )
					: null;
				if ( paletteExistante ) {
					paletteExistante.parentNode.removeChild( paletteExistante );
					initSelecteurCouleurs();
				}
			}

			function appliquerVoile( materiel ) {
				if ( ! materiel ) {
					// « Nouvelle voile » : on ré-ouvre la recherche.
					if ( voile.reafficher ) {
						voile.reafficher();
					} else {
						remplirChamp( champMarque, '' );
						remplirChamp( champModele, '' );
					}
					remplirChamp( champSerie, '' );
					remplirChamp( champTaille, '' );
					remplirChamp( champPtv, '' );
					appliquerCouleur( '' );
					return;
				}
				// Voile déjà suivie : on la matérialise comme une voile choisie
				// (carte de confirmation, sans année).
				if ( voile.choisirExterne ) {
					voile.choisirExterne( materiel.marque, materiel.modele, '' );
				} else {
					remplirChamp( champMarque, materiel.marque );
					remplirChamp( champModele, materiel.modele );
				}
				remplirChamp( champSerie, materiel.numero_serie );
				remplirChamp( champTaille, materiel.taille );
				remplirChamp( champPtv, materiel.ptv );
				appliquerCouleur( materiel.couleur );
			}

			var bloc = document.createElement( 'div' );
			bloc.className = 'gacct-materiel';

			var aide = document.createElement( 'div' );
			aide.className = 'gacct-materiel__aide';
			aide.textContent = i18n.materielAide || '';
			bloc.appendChild( aide );

			var liste = document.createElement( 'div' );
			liste.className = 'gacct-materiel__liste';
			liste.setAttribute( 'role', 'radiogroup' );
			liste.setAttribute( 'aria-label', i18n.materielTitre || 'Votre matériel' );
			bloc.appendChild( liste );

			function libelleMarque( valeur ) {
				// Le champ marque v2 est un input texte : la valeur EST le libellé.
				// (L'ancien select glossaire stockait un slug, d'où ce mapping.)
				if ( ! valeur || ! champMarque || ! champMarque.options ) {
					return valeur || '';
				}
				var trouve = null;
				Array.prototype.forEach.call( champMarque.options, function ( opt ) {
					if ( trouve ) {
						return;
					}
					if ( opt.value.toLowerCase() === valeur.toLowerCase() ) {
						trouve = opt.text;
					}
				} );
				return trouve || valeur;
			}

			var cartes = [];

			function selectionner( carte ) {
				cartes.forEach( function ( c ) {
					c.el.classList.toggle( 'is-active', c === carte );
					c.el.setAttribute( 'aria-checked', c === carte ? 'true' : 'false' );
				} );
				appliquerVoile( carte.materiel );
			}

			materiels.forEach( function ( materiel ) {
				var carte = document.createElement( 'button' );
				carte.type = 'button';
				carte.className = 'gacct-materiel__carte';
				carte.setAttribute( 'role', 'radio' );
				carte.setAttribute( 'aria-checked', 'false' );
				carte.innerHTML =
					'<span class="gacct-materiel__carte-titre">' +
					escapeHtml( ( libelleMarque( materiel.marque ) || '' ) + ' ' + ( materiel.modele || '' ) ).trim() +
					'</span>' +
					'<span class="gacct-materiel__carte-detail">' +
					escapeHtml( materiel.numero_serie || '' ) +
					'</span>';

				var entree = { el: carte, materiel: materiel };
				carte.addEventListener( 'click', function () {
					selectionner( entree );
				} );
				cartes.push( entree );
				liste.appendChild( carte );
			} );

			var carteNouvelle = document.createElement( 'button' );
			carteNouvelle.type = 'button';
			carteNouvelle.className = 'gacct-materiel__carte gacct-materiel__carte--nouveau';
			carteNouvelle.setAttribute( 'role', 'radio' );
			carteNouvelle.setAttribute( 'aria-checked', 'false' );
			carteNouvelle.innerHTML =
				'<svg class="gacct-materiel__carte-icone" viewBox="0 0 24 24" width="20" height="20" ' +
				'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
				'<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>' +
				'<span class="gacct-materiel__carte-titre">' +
				escapeHtml( i18n.materielNouveau || 'Nouvelle voile' ) +
				'</span>';
			var entreeNouvelle = { el: carteNouvelle, materiel: null };
			carteNouvelle.addEventListener( 'click', function () {
				selectionner( entreeNouvelle );
			} );
			cartes.push( entreeNouvelle );
			liste.appendChild( carteNouvelle );

			ancre.parentNode.insertBefore( bloc, ancre );

			if ( cfg.rematId ) {
				var cible = null;
				cartes.forEach( function ( c ) {
					if ( c.materiel && Number( c.materiel.revision_id ) === Number( cfg.rematId ) ) {
						cible = c;
					}
				} );
				if ( cible ) {
					selectionner( cible );
				}
			}
		}

		initSelecteurMateriel();

		/* ---------------------------------------------------------------
		 * Accordéons de prestations (repris du formulaire historique)
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
				var n = acc.body.querySelectorAll( 'input[data-field-name="' + acc.name + '"]:checked' ).length;
				acc.badge.textContent = String( n );
				acc.badge.hidden = n === 0;
			} );
		}

		prestationNames.forEach( function ( name ) {
			buildAccordion( name, name === cfg.accordeonOuvert );
		} );

		/* ---------------------------------------------------------------
		 * Prestations v2 : choix unique décochable (révision, pliage),
		 * quantités 1–9 sur les suspentes, carte « devis de réparation »
		 * exclusive, bascule Solo/Biplace par groupe.
		 * ------------------------------------------------------------- */

		var devisIds = ( v2.devisIds || [] ).map( String );
		var biplaceCfg = v2.biplace || {};
		var biplaceEtat = { voile: false, secours: false };
		var qtys = {}; // product_id (string) → quantité (suspentes cochées)
		var suspentesName = 'suspentes_travaux';
		var groupesUniques = [ 'revisions_controle', 'pliages_secours' ];

		function estDevis( input ) {
			return devisIds.indexOf( String( input.value ) ) > -1;
		}

		function inputsSuspentes() {
			return fieldInputs( suspentesName ).filter( function ( el ) {
				return el.type === 'checkbox';
			} );
		}

		function champCache( name ) {
			return form.querySelector( 'input[name="' + name + '"]' );
		}

		function ecrireChampCache( name, valeur ) {
			var el = champCache( name );
			if ( ! el ) {
				return;
			}
			el.value = valeur;
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		/* --- Choix unique décochable : une case cochée décoche ses sœurs.
		   (Cases à cocher natives : le re-clic décoche déjà tout seul.) --- */
		groupesUniques.forEach( function ( name ) {
			form.addEventListener( 'change', function ( e ) {
				var t = e.target;
				if ( ! t || t.type !== 'checkbox' || ( t.getAttribute( 'data-field-name' ) || '' ) !== name || ! t.checked ) {
					return;
				}
				fieldInputs( name ).forEach( function ( el ) {
					if ( el !== t && el.checked ) {
						el.checked = false;
						el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					}
				} );
			} );
		} );

		/* --- Quantités sur les suspentes (hors devis) --- */
		function initQuantites() {
			inputsSuspentes().forEach( function ( input ) {
				if ( estDevis( input ) ) {
					return;
				}
				var wrap = input.closest( '.jet-form-builder__field-wrap' );
				if ( ! wrap || wrap.querySelector( '.gacct-v2-qty' ) ) {
					return;
				}
				var qty = document.createElement( 'div' );
				qty.className = 'gacct-v2-qty';
				// Stepper TOUJOURS visible (retour de recette du 09/08) : sur une
				// ligne non cochée, un clic +/− coche d'abord la prestation.
				qty.innerHTML =
					'<button type="button" class="gacct-v2-qty-btn" data-delta="-1" aria-label="' + escapeHtml( v2i18n.qtyMoins || 'Retirer' ) + '">−</button>' +
					'<b>1</b>' +
					'<button type="button" class="gacct-v2-qty-btn" data-delta="1" aria-label="' + escapeHtml( v2i18n.qtyPlus || 'Ajouter' ) + '">+</button>';
				qty.addEventListener( 'click', function ( e ) {
					var btn = e.target.closest( '.gacct-v2-qty-btn' );
					if ( ! btn ) {
						return;
					}
					e.preventDefault();
					e.stopPropagation();
					var id = String( input.value );
					if ( ! input.checked ) {
						input.click();
						qtys[ id ] = 1;
					} else {
						qtys[ id ] = Math.max( 1, Math.min( 9, ( qtys[ id ] || 1 ) + parseInt( btn.dataset.delta, 10 ) ) );
					}
					qty.querySelector( 'b' ).textContent = qtys[ id ];
					syncQuantites();
					toutRecalculer();
				} );
				wrap.appendChild( qty );
				input.setAttribute( 'data-gacct-qty-ready', '1' );
			} );
		}

		function majQuantitesVisibles() {
			inputsSuspentes().forEach( function ( input ) {
				if ( estDevis( input ) ) {
					return;
				}
				var wrap = input.closest( '.jet-form-builder__field-wrap' );
				var qty = wrap ? wrap.querySelector( '.gacct-v2-qty' ) : null;
				if ( ! qty ) {
					return;
				}
				// Le stepper reste visible ; seule la surbrillance et la
				// quantité suivent l'état de la case.
				if ( input.checked ) {
					wrap.classList.add( 'has-qty' );
				} else {
					wrap.classList.remove( 'has-qty' );
					delete qtys[ String( input.value ) ];
					qty.querySelector( 'b' ).textContent = '1';
				}
			} );
			syncQuantites();
		}

		function syncQuantites() {
			var paires = [];
			inputsSuspentes().forEach( function ( input ) {
				if ( estDevis( input ) || ! input.checked ) {
					return;
				}
				paires.push( String( input.value ) + ':' + ( qtys[ String( input.value ) ] || 1 ) );
			} );
			ecrireChampCache( 'suspentes_quantites', paires.join( ',' ) );
		}

		function qtyDe( input ) {
			if ( ( input.getAttribute( 'data-field-name' ) || '' ) !== suspentesName || estDevis( input ) ) {
				return 1;
			}
			return qtys[ String( input.value ) ] || 1;
		}

		/* --- Carte « Votre voile a besoin d'une réparation ? » --- */
		var carteDevis = null;
		var noteDevis = null;

		function initCarteDevis() {
			var inputDevis = inputsSuspentes().filter( estDevis )[ 0 ];
			var accSuspentes = accordions.filter( function ( a ) {
				return a.name === suspentesName;
			} )[ 0 ];
			if ( ! inputDevis || ! accSuspentes ) {
				return;
			}

			var wrapDevis = inputDevis.closest( '.jet-form-builder__field-wrap' );

			carteDevis = document.createElement( 'div' );
			carteDevis.className = 'gacct-v2-repair';
			carteDevis.innerHTML =
				'<div class="gacct-v2-repair-titre">' + escapeHtml( v2i18n.repairTitre || 'Votre voile a besoin d’une réparation ?' ) + '</div>' +
				'<p class="gacct-v2-repair-desc">' + ( v2i18n.repairDesc || '' ) + '</p>';
			carteDevis.appendChild( wrapDevis );

			// La carte vit SOUS les accordéons, avant le total d'étape.
			var apres = totalEtape2 || accSuspentes.row.nextSibling;
			accSuspentes.row.parentNode.insertBefore( carteDevis, apres );

			noteDevis = document.createElement( 'p' );
			noteDevis.className = 'gacct-v2-repair-note';
			noteDevis.textContent = v2i18n.repairNote || '';
			noteDevis.hidden = true;
			accSuspentes.body.insertBefore( noteDevis, accSuspentes.body.firstChild );

			// Sorti de l'accordéon, le wrap perd le comportement de clic du
			// template : la carte entière (re)devient cliquable ici.
			carteDevis.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( 'a' ) || e.target.closest( 'input' ) ) {
					return;
				}
				if ( ! inputDevis.disabled ) {
					inputDevis.click();
				}
			} );
		}

		function majExclusiviteDevis() {
			var inputDevis = inputsSuspentes().filter( estDevis )[ 0 ];
			if ( ! inputDevis ) {
				return;
			}
			var autres = inputsSuspentes().filter( function ( el ) {
				return ! estDevis( el );
			} );
			var devisCoche = inputDevis.checked;
			var autreCochee = autres.some( function ( el ) {
				return el.checked;
			} );

			// Devis coché → options à la carte vidées et verrouillées + note.
			if ( devisCoche ) {
				autres.forEach( function ( el ) {
					if ( el.checked ) {
						el.checked = false;
						el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					}
					el.disabled = true;
				} );
				var accS = accordions.filter( function ( a ) { return a.name === suspentesName; } )[ 0 ];
				if ( accS ) {
					accS.body.classList.add( 'is-locked' );
				}
				if ( noteDevis ) {
					noteDevis.hidden = false;
				}
			} else {
				autres.forEach( function ( el ) {
					el.disabled = false;
				} );
				var accS2 = accordions.filter( function ( a ) { return a.name === suspentesName; } )[ 0 ];
				if ( accS2 ) {
					accS2.body.classList.remove( 'is-locked' );
				}
				if ( noteDevis ) {
					noteDevis.hidden = true;
				}
			}

			// Option à la carte cochée → devis grisé.
			inputDevis.disabled = ! devisCoche && autreCochee;
			if ( carteDevis ) {
				carteDevis.classList.toggle( 'is-dim', inputDevis.disabled );

				// Sorti de l'accordéon, le décorateur du template n'est plus
				// synchronisé par le JS du listing : on aligne sa case visuelle
				// sur l'état réel du champ.
				var deco = carteDevis.querySelector( '.jet-fb-check-mark input' );
				if ( deco && deco.checked !== devisCoche ) {
					deco.checked = devisCoche;
				}
			}
		}

		/* --- Bascule Solo / Biplace par groupe --- */
		function typeBiplaceDuGroupe( name ) {
			var type = '';
			fieldInputs( name ).forEach( function ( input ) {
				if ( type || input.type !== 'checkbox' ) {
					return;
				}
				var info = prestations[ input.value ];
				if ( info && info.biplace && biplaceCfg[ info.biplace ] ) {
					type = info.biplace;
				}
			} );
			return type;
		}

		function initBiplace() {
			accordions.forEach( function ( acc ) {
				if ( groupesUniques.indexOf( acc.name ) === -1 ) {
					return;
				}
				var type = typeBiplaceDuGroupe( acc.name );
				if ( ! type ) {
					return;
				}
				var supp = biplaceCfg[ type ];
				var seg = document.createElement( 'div' );
				seg.className = 'gacct-v2-seg';
				seg.innerHTML =
					'<span class="gacct-v2-seg-label">' +
					escapeHtml( type === 'secours' ? ( v2i18n.segSecours || 'Votre parachute de secours :' ) : ( v2i18n.segVoile || 'Votre voile :' ) ) +
					'</span>' +
					'<span class="gacct-v2-seg-btns">' +
					'<button type="button" class="gacct-v2-seg-btn is-sel" data-bi="0">' + escapeHtml( v2i18n.segSolo || 'Solo' ) + '</button>' +
					'<button type="button" class="gacct-v2-seg-btn" data-bi="1">' +
					escapeHtml( ( v2i18n.segBiplace || 'Biplace (+%s)' ).replace( '%s', formatMoney( supp.prix ) ) ) + '</button>' +
					'</span>';
				seg.addEventListener( 'click', function ( e ) {
					var btn = e.target.closest( '.gacct-v2-seg-btn' );
					if ( ! btn ) {
						return;
					}
					e.preventDefault();
					biplaceEtat[ type ] = btn.dataset.bi === '1';
					seg.querySelectorAll( '.gacct-v2-seg-btn' ).forEach( function ( b ) {
						b.classList.toggle( 'is-sel', b === btn );
					} );
					ecrireChampCache( type === 'secours' ? 'biplace_secours' : 'biplace_voile', biplaceEtat[ type ] ? '1' : '' );
					majPrixAffiches( acc.name, type );
					toutRecalculer();
				} );
				acc.body.insertBefore( seg, acc.body.firstChild );
			} );
		}

		/** Réécrit les prix affichés du groupe, supplément inclus si Biplace. */
		function majPrixAffiches( name, type ) {
			var supp = biplaceCfg[ type ];
			fieldInputs( name ).forEach( function ( input ) {
				if ( input.type !== 'checkbox' ) {
					return;
				}
				var info = prestations[ input.value ];
				if ( ! info || info.biplace !== type ) {
					return;
				}
				var wrap = input.closest( '.jet-form-builder__field-wrap' );
				var prixEl = wrap ? wrap.querySelector( '.service-item-price .elementor-heading-title, .service-item-price' ) : null;
				if ( ! prixEl ) {
					return;
				}
				var prix = parseFloat( info.prix ) || 0;
				prixEl.textContent = formatMoney( biplaceEtat[ type ] ? prix + supp.prix : prix );
			} );
		}

		/** Supplément biplace applicable à une prestation cochée (0 sinon). */
		function suppPour( input, cle ) {
			var info = prestations[ input.value ];
			if ( ! info || ! info.biplace || ! biplaceEtat[ info.biplace ] || ! biplaceCfg[ info.biplace ] ) {
				return 0;
			}
			return parseFloat( biplaceCfg[ info.biplace ][ cle ] ) || 0;
		}

		/* Carte de prestation cliquable (tap n'importe où sur l'item). */
		form.addEventListener( 'click', function ( e ) {
			var wrap = e.target.closest( '.gacct-accordion__body .jet-form-builder__field-wrap' );
			if (
				! wrap
				|| e.target.closest( 'label' )
				|| e.target.closest( 'a' )
				|| e.target.closest( '.jet-form-builder__field-template' )
				// Stepper de quantité et bascule biplace : leurs clics ne
				// doivent pas re-basculer la case de la prestation.
				|| e.target.closest( '.gacct-v2-qty' )
				|| e.target.closest( '.gacct-v2-seg' )
			) {
				return;
			}
			var input = wrap.querySelector( 'input.checkradio-field' );
			if ( input ) {
				input.click();
			}
		}, true );

		/* ---------------------------------------------------------------
		 * Totaux : cumul durées + montants (sans le résumé fixe du v1 —
		 * un total d'étape sous les prestations + le récapitulatif).
		 * ------------------------------------------------------------- */

		var totalEtape2 = null;

		function buildTotalEtape2() {
			if ( ! accordions.length ) {
				return;
			}
			var last = accordions[ accordions.length - 1 ].row;
			totalEtape2 = document.createElement( 'div' );
			totalEtape2.className = 'gacct-v2-total gacct-v2-total--etape2';
			totalEtape2.innerHTML =
				'<span>' + escapeHtml( v2i18n.totalPresta || 'Total des prestations' ) + '</span>' +
				'<strong>0 ' + devise + '</strong>';
			last.parentNode.insertBefore( totalEtape2, last.nextSibling );
		}

		function toutRecalculer() {
			var heuresRequises = 0;
			var nbPrestations = 0;
			var sommePrestations = 0;
			var sommePort = 0;
			var sommeAcompte = 0;

			prestationNames.forEach( function ( name ) {
				fieldInputs( name ).forEach( function ( input ) {
					if ( ! input.checked ) {
						return;
					}
					var info = prestations[ input.value ];
					if ( ! info ) {
						return;
					}
					var qte = qtyDe( input );
					nbPrestations++;
					sommePrestations += ( ( parseFloat( info.prix ) || 0 ) + suppPour( input, 'prix' ) ) * qte;
					sommeAcompte += ( acompteDe( info, parseFloat( info.prix ) || 0 ) + suppPour( input, 'acompte' ) ) * qte;
					heuresRequises += ( parseFloat( info.duree ) || 0 ) * qte;
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
					sommeAcompte += acompteDe( info, prix );
				} );
			}

			if ( inputDuree ) {
				inputDuree.value = decimalToTime( heuresRequises );
			}

			heuresRequisesCourant = heuresRequises;
			nbPrestationsCourant = nbPrestations;
			derniereSommePrestations = sommePrestations;
			dernierTotalGlobal = sommePrestations + sommePort;
			dernierAcompte = sommeAcompte;

			if ( totalEtape2 ) {
				totalEtape2.querySelector( 'strong' ).textContent = formatMoney( sommePrestations );
			}

			if ( nbPrestations > 0 ) {
				effaceErreur( 'presta' );
			}

			majQuantitesVisibles();
			majExclusiviteDevis();
			actualiserCalendrier();
			updateAccordionBadges();
		}

		function acompteDe( info, prix ) {
			var a = parseFloat( info && info.acompte );
			return isNaN( a ) ? prix : a;
		}

		/* ---------------------------------------------------------------
		 * Récapitulatif (étape 4)
		 * ------------------------------------------------------------- */

		var recap = document.getElementById( 'gacct-v2-recap' );

		function valeurChamp( name ) {
			var el = form.querySelector( '[name="' + name + '"]' );
			return el ? String( el.value || '' ).trim() : '';
		}

		function libelleMarqueChoisie() {
			var el = form.querySelector( '[name="marque"]' );
			if ( ! el ) {
				return '';
			}
			if ( el.tagName === 'SELECT' ) {
				var opt = el.options[ el.selectedIndex ];
				return opt && el.value ? opt.text.trim() : '';
			}
			return String( el.value || '' ).trim();
		}

		function dateEnToutesLettres() {
			if ( instanceFp && instanceFp.selectedDates && instanceFp.selectedDates[ 0 ] ) {
				return new Intl.DateTimeFormat( 'fr-FR', { day: 'numeric', month: 'long', year: 'numeric' } )
					.format( instanceFp.selectedDates[ 0 ] );
			}
			return '';
		}

		function recapRow( label, bodyHtml, go ) {
			return (
				'<div class="gacct-v2-recap-row">' +
				'<span class="gacct-v2-r-label">' + escapeHtml( label ) + '</span>' +
				'<span class="gacct-v2-r-body">' + bodyHtml + '</span>' +
				'<button type="button" class="gacct-v2-r-edit" data-go="' + go + '">' +
				escapeHtml( v2i18n.modifier || 'Modifier' ) + '</button>' +
				'</div>'
			);
		}

		function buildRecap() {
			if ( ! recap ) {
				return;
			}

			// Voile.
			var marque = libelleMarqueChoisie();
			var modele = valeurChamp( 'modele' );
			var sousInfos = [];
			if ( valeurChamp( 'taille' ) ) {
				sousInfos.push( sprintf1( v2i18n.tailleAbr || 'Taille %s', valeurChamp( 'taille' ) ) );
			}
			if ( valeurChamp( 'ptv' ) ) {
				sousInfos.push( sprintf1( v2i18n.ptvAbr || 'PTV %s kg', valeurChamp( 'ptv' ) ) );
			}
			if ( valeurChamp( 'numero_serie' ) ) {
				sousInfos.push( sprintf1( v2i18n.serieAbr || 'N° %s', valeurChamp( 'numero_serie' ) ) );
			}
			if ( valeurChamp( champs.couleur || 'couleur_copy' ) ) {
				sousInfos.push( sprintf1( v2i18n.couleursAbr || 'Couleurs : %s', valeurChamp( champs.couleur || 'couleur_copy' ) ) );
			}
			var voileHtml =
				'<strong>' + escapeHtml( ( marque + ' ' + modele ).trim() || '—' ) + '</strong>' +
				( sousInfos.length ? '<small>' + escapeHtml( sousInfos.join( ' · ' ) ) + '</small>' : '' );

			// Prestations : « titre × qty », prix × qty, sous-ligne supplément biplace.
			var prestaHtml = '';
			prestationNames.forEach( function ( name ) {
				fieldInputs( name ).forEach( function ( input ) {
					if ( ! input.checked ) {
						return;
					}
					var info = prestations[ input.value ];
					if ( ! info ) {
						return;
					}
					var qte = qtyDe( input );
					var supp = suppPour( input, 'prix' );
					prestaHtml +=
						'<span style="display:block">' + escapeHtml( info.titre || '' ) +
						( qte > 1 ? ' × ' + qte : '' ) +
						' <small style="display:inline">' + formatMoney( ( parseFloat( info.prix ) || 0 ) * qte ) + '</small></span>';
					if ( supp > 0 ) {
						prestaHtml +=
							'<small style="display:block;padding-left:14px">' +
							escapeHtml( ( v2i18n.suppBiplace || '+ Supplément biplace — %s' ).replace( '%s', formatMoney( supp * qte ) ) ) +
							'</small>';
					}
				} );
			} );
			if ( ! prestaHtml ) {
				prestaHtml = '—';
			}

			// Retour.
			var retourHtml = '—';
			fieldInputs( portName ).forEach( function ( input ) {
				if ( ! input.checked ) {
					return;
				}
				var info = prestations[ input.value ];
				if ( info ) {
					retourHtml = escapeHtml( info.titre || '' ) +
						' <small style="display:inline">' + formatMoney( parseFloat( info.prix ) || 0 ) + '</small>';
				}
			} );

			recap.innerHTML =
				recapRow( v2i18n.recapVoile || 'Votre voile', voileHtml, 1 ) +
				recapRow( v2i18n.recapPrestas || 'Prestations', prestaHtml, 2 ) +
				recapRow( v2i18n.recapDate || 'Date', escapeHtml( dateEnToutesLettres() || '—' ), 3 ) +
				recapRow( v2i18n.recapRetour || 'Retour', retourHtml, 3 ) +
				'<div class="gacct-v2-total"><span>' + escapeHtml( v2i18n.total || 'Total' ) + '</span><strong>' +
				formatMoney( dernierTotalGlobal ) + '</strong></div>' +
				'<div class="gacct-v2-total gacct-v2-total--acompte"><span>' +
				escapeHtml( v2i18n.acompte || 'Acompte à payer aujourd’hui' ) + '</span><strong>' +
				formatMoney( dernierAcompte ) + '</strong></div>' +
				'<p class="gacct-v2-total-note">' + escapeHtml( v2i18n.acompteNote || '' ) + '</p>';

			recap.querySelectorAll( '.gacct-v2-r-edit' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					gotoPage( parseInt( btn.dataset.go, 10 ) );
				} );
			} );
		}

		/* ---------------------------------------------------------------
		 * Écouteurs globaux
		 * ------------------------------------------------------------- */

		form.addEventListener( 'change', function ( e ) {
			var target = e.target;
			if ( ! target || ( target.type !== 'checkbox' && target.type !== 'radio' ) ) {
				return;
			}
			var fieldName = target.getAttribute( 'data-field-name' ) || target.name;
			if ( fieldName === dateDispoName ) {
				return;
			}
			if ( fieldName === portName ) {
				effaceErreur( 'retour' );
			}
			toutRecalculer();
		} );

		// Changement de page JFB (event jQuery émis par multi.step.js).
		if ( window.jQuery ) {
			window.jQuery( document ).on( 'jet-form-builder/switch-page', function () {
				updateProgressLabel();
				if ( pageCourante() === 4 ) {
					buildRecap();
				}
			} );
		}

		buildProgressLabel();
		buildTotalEtape2();
		initQuantites();
		initBiplace();
		initCarteDevis();
		toutRecalculer();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
