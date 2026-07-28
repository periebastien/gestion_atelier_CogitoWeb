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
 *  - validation au submit dans l'ordre du formulaire mobile : matériel (laissé
 *    à JetFormBuilder) → prestations → frais de retour (JFB) → date ;
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

		var materielNames = champs.materiel || [];

		var prestations = cfg.prestations || {};
		var dispos = cfg.dispos || {};
		var i18n = cfg.i18n || {};
		var devise = cfg.devise || '€';

		var inputDate = form.querySelector( 'input[name="' + dateFieldName + '"]' );
		var inputDuree = form.querySelector( 'input[name="' + dureeFieldName + '"]' );

		var instanceFp = null;
		var heuresRequisesCourant = 0;
		var nbPrestationsCourant = 0;

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
		 * Validation au submit
		 *
		 * L'ordre des erreurs suit l'ordre du formulaire en mobile
		 * (cf. `order` CSS <= 781px) : matériel → prestations → frais de
		 * retour → date. Nos deux contrôles maison (prestations, date) ne se
		 * déclenchent donc qu'une fois les champs qui les précèdent remplis ;
		 * pour les autres on laisse la main à JetFormBuilder en ne bloquant
		 * pas, ses propres messages s'affichant alors sous les champs.
		 * ------------------------------------------------------------- */

		/**
		 * Premier champ requis vide parmi une liste de champs texte/select.
		 * On teste la valeur plutôt que checkValidity() : le champ couleur est
		 * en lecture seule (alimenté par la palette), donc exclu de la
		 * validation native du navigateur.
		 */
		function premierChampVide( names ) {
			var trouve = null;
			names.forEach( function ( name ) {
				if ( trouve ) {
					return;
				}
				fieldInputs( name ).forEach( function ( el ) {
					if ( trouve || ! el.required || el.type === 'radio' || el.type === 'checkbox' ) {
						return;
					}
					if ( String( el.value || '' ).trim() === '' ) {
						trouve = el;
					}
				} );
			} );
			return trouve;
		}

		/** Groupe de cases requis (frais de retour) sans aucun choix. */
		function groupeRequisVide( name ) {
			var requis = false;
			var choisi = false;
			fieldInputs( name ).forEach( function ( el ) {
				if ( el.tagName !== 'INPUT' || ( el.type !== 'radio' && el.type !== 'checkbox' ) ) {
					return;
				}
				if ( el.required ) {
					requis = true;
				}
				if ( el.checked ) {
					choisi = true;
				}
			} );
			return requis && ! choisi;
		}

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

		// Message d'erreur des prestations : posé au-dessus du premier accordéon,
		// donc visible quel que soit le groupe déplié (les corps d'accordéon
		// sont longs : sous le dernier groupe, il serait hors écran).
		var erreurPrestations = null;

		function clearPrestationsError() {
			if ( erreurPrestations && erreurPrestations.parentNode ) {
				erreurPrestations.parentNode.removeChild( erreurPrestations );
			}
			erreurPrestations = null;
			accordions.forEach( function ( acc ) {
				acc.row.classList.remove( 'is-error' );
			} );
		}

		function showPrestationsError() {
			if ( ! accordions.length ) {
				return;
			}
			accordions.forEach( function ( acc ) {
				acc.row.classList.add( 'is-error' );
			} );

			if ( ! erreurPrestations ) {
				erreurPrestations = document.createElement( 'div' );
				// Classe maison uniquement : les classes d'erreur JFB sont
				// positionnées en absolu par le CSS de la page, le message
				// partait se coller dans la carte du calendrier.
				erreurPrestations.className = 'gacct-erreur-prestations';
				erreurPrestations.setAttribute( 'role', 'alert' );
				erreurPrestations.textContent =
					i18n.erreurPrestations || 'Vous devez sélectionner au moins une prestation';
				var premiere = accordions[ 0 ].row;
				premiere.parentNode.insertBefore( erreurPrestations, premiere );
			}

			// Aucun groupe ouvert : on déplie le premier pour montrer où agir.
			var unOuvert = accordions.some( function ( acc ) {
				return acc.row.classList.contains( 'is-open' );
			} );
			if ( ! unOuvert ) {
				accordions[ 0 ].setOpen( true );
			}
			erreurPrestations.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}

		// Sans prestation ni champ date dans ce formulaire, il n'y a rien à
		// valider : ne jamais poser de blocage (il rendrait le formulaire
		// impossible à soumettre).
		form.addEventListener(
			'submit',
			function ( e ) {
				// 1. Matériel incomplet : c'est le premier bloc du formulaire,
				//    on laisse JetFormBuilder signaler ses champs.
				if ( premierChampVide( materielNames ) ) {
					return;
				}

				// 2. Prestations.
				if ( accordions.length && nbPrestationsCourant === 0 ) {
					e.preventDefault();
					e.stopImmediatePropagation();
					clearDateError();
					showPrestationsError();
					return;
				}
				// 3. Frais de retour : encore un champ JFB, placé avant la date.
				if ( groupeRequisVide( portName ) ) {
					return;
				}

				// 4. Date d'atelier, dernière étape du parcours.
				if ( inputDate && inputDate.value.trim() === '' ) {
					e.preventDefault();
					e.stopImmediatePropagation();
					showDateError();
				}
			},
			true
		);

		/* ---------------------------------------------------------------
		 * Sélecteur de couleurs de voile
		 *
		 * Le champ texte reste la source de vérité (validation JFB, mapping
		 * vers la colonne `couleur` du CCT) : on le masque et on l'alimente
		 * au format historique « bleu, blanc », l'ordre étant l'ordre de clic.
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
				( i18n.couleurAide || 'Cliquez vos couleurs, de la plus présente à la moins présente (3 maximum).' ) +
				'</span><span class="gacct-couleurs__apercu" aria-hidden="true"></span>';

			palette.appendChild( grille );
			palette.appendChild( pied );
			wrap.appendChild( palette );

			var apercu = pied.querySelector( '.gacct-couleurs__apercu' );

			function valeursInitiales() {
				// Pré-remplissage (retour arrière navigateur, preset JFB…) :
				// on ne garde que des noms connus, dans l'ordre de la chaîne.
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
				var h = choisies.map( teinte );
				if ( h.length === 1 ) {
					return h[ 0 ];
				}
				if ( h.length === 2 ) {
					return 'linear-gradient(135deg, ' + h[ 0 ] + ' 50%, ' + h[ 1 ] + ' 50%)';
				}
				if ( h.length === 3 ) {
					return 'linear-gradient(135deg, ' + h[ 0 ] + ' 33.33%, ' + h[ 1 ] + ' 33.33% 66.66%, ' + h[ 2 ] + ' 66.66%)';
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

				input.value = choisies.join( ', ' );
				// JetFormBuilder écoute input/change pour synchroniser son état
				// interne : sans ces évènements, la valeur n'est pas soumise.
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}

			appliquer();
		}

		initSelecteurCouleurs();

		/* ---------------------------------------------------------------
		 * Sélecteur « Votre matériel »
		 *
		 * Si le client connecté a déjà des voiles suivies (cfg.materiels),
		 * on propose des cartes au-dessus du bloc marque/modèle/n° de série/
		 * taille/ptv/couleur : cliquer une carte préremplit tous ces champs
		 * (y compris la couleur, via le sélecteur custom déjà en place),
		 * « Nouvelle voile » les vide. Ne touche jamais aux champs date/
		 * prestations. `cfg.rematId` (paramètre ?remat=) présélectionne une
		 * voile au chargement — déjà vérifiée côté serveur comme appartenant
		 * au client connecté.
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

			// Ancrage : le groupe grid-3 (marque/modèle/n° série/taille/ptv) est le
			// premier repère fiable du bloc « Votre matériel » dans le DOM.
			var ancre = form.querySelector( '.grid-3' );
			if ( ! ancre || ! champMarque ) {
				return;
			}

			function remplirChamp( el, valeur ) {
				if ( ! el ) {
					return;
				}
				valeur = valeur || '';

				if ( el.tagName === 'SELECT' ) {
					// Champ « Marque » : select-field JetFormBuilder (glossaire), dont les
					// <option value="..."> sont des slugs ("ozone") alors que la valeur
					// enregistrée en base peut être le libellé ("Ozone") selon la saisie
					// d'origine : on cherche d'abord une correspondance exacte, puis
					// insensible à la casse sur la valeur ET sur le texte de l'option.
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
				// Le select « Marque » est un widget select2 : sa vignette visible
				// n'écoute que jQuery('change'), pas l'évènement DOM natif.
				if ( window.jQuery ) {
					window.jQuery( el ).trigger( 'change' );
				}
			}

			function appliquerCouleur( texteCouleur ) {
				if ( ! champCouleur ) {
					return;
				}
				var noms = ( texteCouleur || '' )
					.split( /[\s,\/\+\-]+/ )
					.map( function ( s ) { return s.trim().toLowerCase(); } )
					.filter( function ( s ) { return s.length; } );

				var couleursConnues = ( cfg.couleurs || [] ).map( function ( c ) { return c.nom; } );
				// Reconstitue les noms composés ("jaune fluo") avant la découpe
				// simple, comme côté PHP (gacct_extraire_couleurs) : on cherche
				// d'abord si le texte brut contient un nom composé entier.
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

				// Le sélecteur custom de couleurs lit sa sélection initiale depuis
				// la valeur du champ au montage ; une fois monté, il ne se
				// resynchronise pas tout seul sur un changement externe de valeur.
				// On reconstruit donc sa palette pour refléter la nouvelle sélection.
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
					remplirChamp( champMarque, '' );
					remplirChamp( champModele, '' );
					remplirChamp( champSerie, '' );
					remplirChamp( champTaille, '' );
					remplirChamp( champPtv, '' );
					appliquerCouleur( '' );
					return;
				}
				remplirChamp( champMarque, materiel.marque );
				remplirChamp( champModele, materiel.modele );
				remplirChamp( champSerie, materiel.numero_serie );
				remplirChamp( champTaille, materiel.taille );
				remplirChamp( champPtv, materiel.ptv );
				appliquerCouleur( materiel.couleur );
			}

			var bloc = document.createElement( 'div' );
			bloc.className = 'gacct-materiel';

			// Pas de titre ici : la carte « Votre matériel » du formulaire porte déjà
			// ce libellé (icône étoile) juste au-dessus de ce bloc. Un second titre
			// identique créerait une redite visuelle.
			var aide = document.createElement( 'div' );
			aide.className = 'gacct-materiel__aide';
			aide.textContent = i18n.materielAide || '';
			bloc.appendChild( aide );

			var liste = document.createElement( 'div' );
			liste.className = 'gacct-materiel__liste';
			liste.setAttribute( 'role', 'radiogroup' );
			liste.setAttribute( 'aria-label', i18n.materielTitre || 'Votre matériel' );
			bloc.appendChild( liste );

			/**
			 * Le champ Marque est un select-field JetFormBuilder dont la valeur
			 * enregistrée est le slug de l'<option> ("gin-gliders"), pas son
			 * libellé affiché ("Gin Gliders") : on retrouve ce libellé pour
			 * l'affichage de la carte, avec repli sur la valeur brute si elle ne
			 * correspond à aucune option connue (marque saisie librement par le
			 * passé, avant l'ajout du glossaire).
			 */
			function libelleMarque( valeur ) {
				if ( ! valeur || ! champMarque ) {
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

			// Présélection via ?remat=<id>, déjà validée côté serveur (appartenance
			// au client connecté). Ignoré silencieusement si l'ID n'est plus dans
			// la liste (ex. matériel purgé entre-temps).
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

		function escapeHtml( str ) {
			var div = document.createElement( 'div' );
			div.textContent = str == null ? '' : String( str );
			return div.innerHTML;
		}

		initSelecteurMateriel();

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
		var elTotalAcompte = document.getElementById( 'total_acompte' );

		/**
		 * Montant encaissé à la commande pour une prestation : l'acompte fourni par
		 * PHP (miroir de la règle du plugin Kojito — acompte défini, sinon prix plein).
		 * Fallback sur le prix si la donnée manque (ancien cache localisé).
		 */
		function acompteDe( info, prix ) {
			var a = parseFloat( info && info.acompte );
			return isNaN( a ) ? prix : a;
		}

		function toutRecalculer() {
			var heuresRequises = 0;
			var nbPrestations = 0;
			var sommePrestations = 0;
			var sommePort = 0;
			var sommeAcompte = 0;
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

					nbPrestations++;
					sommePrestations += prix;
					sommeAcompte += acompteDe( info, prix );
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
					sommeAcompte += acompteDe( info, prix );
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
			if ( elTotalAcompte ) {
				elTotalAcompte.textContent = formatMoney( sommeAcompte );
			}

			heuresRequisesCourant = heuresRequises;
			nbPrestationsCourant = nbPrestations;
			dernierTotalGlobal = sommePrestations + sommePort;

			if ( nbPrestations > 0 ) {
				clearPrestationsError();
			}

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
