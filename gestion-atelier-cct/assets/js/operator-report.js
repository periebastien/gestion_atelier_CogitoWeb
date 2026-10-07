/**
 * Rapports de contrôle — FRAMEWORK JS de la carte console (architecture packs).
 *
 * Ce fichier est agnostique du pack : ouverture/fermeture des formulaires,
 * sérialisation générique par [data-rf], brouillons, génération, suppression,
 * répéteur de lignes, textes-modèles de commentaire. Les CALCULS temps réel
 * sont fournis par le pack actif, qui s'enregistre via :
 *
 *   window.gacctReportUI.registerCalc( 'voile', function ( form, U ) { … } );
 *   window.gacctReportUI.registerAction( 'apply-vr', function ( button, ctx ) { … } );
 *
 * U = utilitaires exposés (serializeForm, setBadge, fmt, scaleResult, worst,
 * cfg = window.gacctReportCfg (config localisée du pack), entries()…).
 * Les formules PHP du pack restent la source de vérité : le PDF recalcule
 * toujours côté serveur.
 *
 * 28/09/2026, retour Hervé du 15/09 : enregistrement automatique du brouillon
 * (debounce 20 s, sauvegarde immédiate quand la page se cache ou se décharge)
 * et filet local (copie de la saisie dans localStorage, proposée à la
 * réouverture si elle est plus récente que le brouillon serveur).
 */
( function () {
	'use strict';

	if ( typeof window.gacctOp === 'undefined' ) {
		return;
	}

	var cfg  = window.gacctReportCfg || {};
	var card = document.querySelector( '[data-report-card]' );

	var calcRegistry   = {};
	var actionRegistry = {};

	function say( type, message ) {
		var feedback = card ? card.querySelector( '.gacct-rf-feedback' ) : null;
		if ( feedback ) {
			feedback.className   = 'gacct-op-feedback gacct-rf-feedback ' + type;
			feedback.textContent = message;
			feedback.scrollIntoView( { block: 'nearest' } );
		} else {
			window.alert( message );
		}
	}

	/* ── Utilitaires génériques (exposés au pack) ────────────────────── */

	function scaleResult( value, scale ) {
		for ( var i = 0; i < ( scale || [] ).length; i++ ) {
			var band = scale[ i ];
			if ( null === band.max ) {
				return band.result;
			}
			if ( band.eq ? value <= band.max : value < band.max ) {
				return band.result;
			}
		}
		return '';
	}

	function worst( results ) {
		var severity = cfg.severity || [];
		var actual   = results.filter( function ( r ) {
			return r && 'NON RÉALISÉ' !== r && 'NON RÉALISÉ*' !== r;
		} );
		if ( ! actual.length ) {
			return results.length ? 'NON RÉALISÉ' : '';
		}
		for ( var i = 0; i < severity.length; i++ ) {
			if ( actual.indexOf( severity[ i ] ) !== -1 ) {
				return severity[ i ];
			}
		}
		return '';
	}

	var BADGE_CLASS = {
		'RÉFORME':       'is-reforme',
		'LIMITE':        'is-limite',
		'ACCEPTABLE':    'is-acceptable',
		'ASSEZ BON ÉTAT': 'is-assezbon',
		'BON ÉTAT':      'is-bon',
		'TRÈS BON ÉTAT': 'is-tresbon',
		'NEUF':          'is-neuf',
		'CALAGE BON':    'is-bon',
		'NON RÉALISÉ':   'is-na',
		'NON RÉALISÉ*':  'is-na',
		'NR*':           'is-na'
	};

	function setBadge( form, key, text ) {
		var badge = form.querySelector( '[data-rf-badge="' + key + '"]' );
		if ( ! badge ) {
			return;
		}
		badge.textContent = text || '—';
		badge.className   = badge.className.replace( /\bis-[a-z]+\b/g, '' ).trim();
		if ( BADGE_CLASS[ text ] ) {
			badge.classList.add( BADGE_CLASS[ text ] );
		}
	}

	function fmt( n, dec ) {
		return ( Math.round( n * Math.pow( 10, dec ) ) / Math.pow( 10, dec ) ).toString().replace( '.', ',' );
	}

	/* ── Accès générique par data-rf (chemins pointés) ───────────────── */

	function setDeep( obj, path, value ) {
		var keys = path.split( '.' );
		var node = obj;

		for ( var i = 0; i < keys.length - 1; i++ ) {
			var key = keys[ i ];
			if ( ! node[ key ] || 'object' !== typeof node[ key ] ) {
				node[ key ] = /^\d+$/.test( keys[ i + 1 ] ) ? [] : {};
			}
			node = node[ key ];
		}
		node[ keys[ keys.length - 1 ] ] = value;
	}

	function getDeep( obj, path ) {
		var keys = path.split( '.' );
		var node = obj;

		for ( var i = 0; i < keys.length; i++ ) {
			if ( null === node || undefined === node ) {
				return undefined;
			}
			node = node[ keys[ i ] ];
		}
		return node;
	}

	function serializeForm( form ) {
		var data = {};

		form.querySelectorAll( '[data-rf]' ).forEach( function ( field ) {
			var value = ( 'checkbox' === field.type ) ? ( field.checked ? '1' : '' ) : field.value;
			setDeep( data, field.getAttribute( 'data-rf' ), value );
		} );

		// Lignes répétées (ex. test de rupture) : [data-rf-rupture-lines] > lignes [data-rl].
		var linesWrap = form.querySelector( '[data-rf-rupture-lines]' );
		if ( linesWrap ) {
			data.rupture = [];
			linesWrap.querySelectorAll( '.gacct-rf-rupture-line' ).forEach( function ( row ) {
				var line = {};
				row.querySelectorAll( '[data-rl]' ).forEach( function ( field ) {
					line[ field.getAttribute( 'data-rl' ) ] = ( 'checkbox' === field.type ) ? ( field.checked ? '1' : '' ) : field.value;
				} );
				data.rupture.push( line );
			} );
		}

		return data;
	}

	function addRuptureLine( form, prefill ) {
		var template = form.querySelector( '[data-rf-rupture-template]' );
		var wrap     = form.querySelector( '[data-rf-rupture-lines]' );

		if ( ! template || ! wrap ) {
			return;
		}

		var max = parseInt( wrap.getAttribute( 'data-rf-max' ), 10 ) || 99;

		if ( wrap.querySelectorAll( '.gacct-rf-rupture-line' ).length >= max ) {
			say( 'error', 'Maximum ' + max + ' lignes.' );
			return;
		}

		var node = template.content.firstElementChild.cloneNode( true );

		if ( prefill ) {
			node.querySelectorAll( '[data-rl]' ).forEach( function ( field ) {
				var key = field.getAttribute( 'data-rl' );
				if ( undefined !== prefill[ key ] && null !== prefill[ key ] ) {
					if ( 'checkbox' === field.type ) {
						field.checked = !! prefill[ key ] && '0' !== String( prefill[ key ] );
					} else {
						field.value = String( prefill[ key ] );
					}
				}
			} );
		}

		wrap.appendChild( node );
	}

	function fillForm( form, data ) {
		form.querySelectorAll( '[data-rf]' ).forEach( function ( field ) {
			var value = getDeep( data, field.getAttribute( 'data-rf' ) );

			if ( undefined === value ) {
				return; // garder le pré-remplissage serveur (ou la valeur par défaut).
			}
			if ( 'checkbox' === field.type ) {
				field.checked = '1' === String( value );
			} else {
				field.value = String( value );
			}
		} );

		var linesWrap = form.querySelector( '[data-rf-rupture-lines]' );
		if ( linesWrap && data && Array.isArray( data.rupture ) ) {
			linesWrap.innerHTML = '';
			data.rupture.forEach( function ( line ) {
				addRuptureLine( form, line );
			} );
		}
	}

	function resetForm( form ) {
		form.querySelectorAll( '[data-rf]' ).forEach( function ( field ) {
			if ( 'checkbox' === field.type ) {
				// 28/09/2026, retour Hervé du 23/09 : une case peut être cochée par
				// défaut sur un nouveau rapport (data-rf-default-checked).
				field.checked = field.hasAttribute( 'data-rf-default-checked' );
			} else if ( field.hasAttribute( 'data-rf-default' ) ) {
				field.value = field.getAttribute( 'data-rf-default' );
			} else if ( ! /^ident\.|^author_id$|^type$/.test( field.getAttribute( 'data-rf' ) ) ) {
				field.value = field.defaultValue !== undefined ? field.defaultValue : '';
			} else {
				field.value = field.defaultValue !== undefined ? field.defaultValue : field.value;
			}
		} );

		form.querySelectorAll( 'select[data-rf]' ).forEach( function ( select ) {
			var rf = select.getAttribute( 'data-rf' );
			if ( ! /^author_id$|^type$/.test( rf ) ) {
				select.value = '';
			}
		} );

		var author = form.querySelector( '[data-rf="author_id"]' );
		if ( author ) {
			author.value = author.querySelector( 'option[selected]' ) ? author.querySelector( 'option[selected]' ).value : author.value;
		}

		// Le type revient à la valeur présélectionnée côté serveur (déduite de la
		// commande via gacct_report_voile_default_type), pas à la première option.
		var type = form.querySelector( '[data-rf="type"]' );
		if ( type && type.options.length ) {
			var typeDefault = type.querySelector( 'option[selected]' );
			type.value = typeDefault ? typeDefault.value : type.options[ 0 ].value;
		}

		var linesWrap = form.querySelector( '[data-rf-rupture-lines]' );
		if ( linesWrap ) {
			linesWrap.innerHTML = '';
		}

		form.querySelectorAll( '[data-rf-default]' ).forEach( function ( field ) {
			if ( ! field.value ) {
				field.value = field.getAttribute( 'data-rf-default' );
			}
		} );
	}

	/* ── Registre exposé au pack ─────────────────────────────────────── */

	var utils = {
		cfg: cfg,
		say: say,
		fmt: fmt,
		worst: worst,
		scaleResult: scaleResult,
		setBadge: setBadge,
		serializeForm: serializeForm,
		addRuptureLine: addRuptureLine,
		entries: function () { return entries; }
	};

	window.gacctReportUI = {
		registerCalc: function ( model, fn ) { calcRegistry[ model ] = fn; },
		registerAction: function ( name, fn ) { actionRegistry[ name ] = fn; },
		utils: utils
	};

	if ( ! card ) {
		return;
	}

	/* ── État de la carte ────────────────────────────────────────────── */

	var fiche      = document.querySelector( '.gacct-op-fiche[data-revision-id]' );
	var revisionId = fiche ? fiche.getAttribute( 'data-revision-id' ) : '';
	var entries    = [];

	try {
		var entriesScript = card.querySelector( '[data-report-entries]' );
		entries = entriesScript ? JSON.parse( entriesScript.textContent ) : [];
	} catch ( e ) {
		entries = [];
	}

	var current = { form: null, model: '', reportId: '' };

	/**
	 * Requête AJAX console. `keepalive` permet à la requête de survivre au
	 * déchargement de la page (sauvegarde à pagehide / visibilitychange).
	 */
	function post( action, data, keepalive ) {
		var body = new FormData();
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		body.append( 'action', action );
		body.append( 'nonce', window.gacctOp.nonce );

		var options = { method: 'POST', credentials: 'same-origin', body: body };
		if ( keepalive ) {
			options.keepalive = true;
		}

		return fetch( window.gacctOp.ajaxUrl, options )
			.then( function ( r ) { return r.json(); } );
	}

	function recompute( form ) {
		var model = form.getAttribute( 'data-report-form' );

		if ( calcRegistry[ model ] ) {
			calcRegistry[ model ]( form, utils );
		}
	}

	function findEntry( reportId ) {
		for ( var i = 0; i < entries.length; i++ ) {
			if ( entries[ i ].id === reportId ) {
				return entries[ i ];
			}
		}
		return null;
	}

	function applyCommentTemplate( form, type, force ) {
		var field = form.querySelector( '[data-rf="comment"]' );

		if ( ! field || ! field.hasAttribute( 'data-rf-comment-templates' ) ) {
			return;
		}

		var templates = {};
		try {
			templates = JSON.parse( field.getAttribute( 'data-rf-comment-templates' ) || '{}' );
		} catch ( e ) {}

		var current_val = field.value.trim();
		var isTemplate  = '' === current_val || Object.keys( templates ).some( function ( key ) {
			return current_val === String( templates[ key ] || '' ).trim();
		} );

		if ( ! force && ! isTemplate && ! window.confirm( 'Remplacer le commentaire actuel par le texte-modèle du type « ' + type + ' » ?' ) ) {
			return;
		}
		field.value = templates[ type ] || '';
	}

	/* ── Enregistrement automatique + filet local (28/09/2026) ───────── */

	var AUTOSAVE_DELAY = 20000;

	var autosave = {
		timer:    null,   // debounce en attente
		baseline: '',     // JSON de la dernière saisie connue du serveur (ou de l'ouverture)
		inFlight: null,   // promesse de la sauvegarde en cours (auto ou manuelle)
		generating: false // une génération est lancée : plus d'autosave
	};

	function pad2( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	function autosaveNode() {
		return current.form ? current.form.querySelector( '.gacct-rf-autosave' ) : null;
	}

	function setAutosaveNote( text, isError ) {
		var node = autosaveNode();
		if ( ! node ) {
			return;
		}
		node.textContent = text || '';
		node.classList.toggle( 'is-error', !! isError );
	}

	function localKey( reportId ) {
		return 'gacct_rf_draft_' + revisionId + '_' + current.model + '_' + ( reportId || 'new' );
	}

	function localRead( key ) {
		try {
			var raw = window.localStorage.getItem( key );
			var obj = raw ? JSON.parse( raw ) : null;
			return ( obj && obj.data && obj.ts ) ? obj : null;
		} catch ( e ) {
			return null;
		}
	}

	function localWrite( key, data ) {
		try {
			window.localStorage.setItem( key, JSON.stringify( { ts: Date.now(), data: data } ) );
		} catch ( e ) {}
	}

	function localRemove( key ) {
		try {
			window.localStorage.removeItem( key );
		} catch ( e ) {}
	}

	/** Saisie différente de la dernière version connue du serveur ? */
	function isDirty() {
		if ( ! current.form ) {
			return false;
		}
		return JSON.stringify( serializeForm( current.form ) ) !== autosave.baseline;
	}

	function cancelAutosave() {
		if ( autosave.timer ) {
			window.clearTimeout( autosave.timer );
			autosave.timer = null;
		}
	}

	function scheduleAutosave() {
		cancelAutosave();
		if ( ! current.form || autosave.generating ) {
			return;
		}
		autosave.timer = window.setTimeout( function () {
			autosave.timer = null;
			if ( ! current.form || autosave.generating || ! isDirty() ) {
				return;
			}
			saveDraft( null, false, { auto: true } );
		}, AUTOSAVE_DELAY );
	}

	/** À chaque modification : copie locale immédiate + autosave différé. */
	function noteChange() {
		if ( ! current.form || autosave.generating ) {
			return;
		}
		var data = serializeForm( current.form );
		if ( JSON.stringify( data ) === autosave.baseline ) {
			return; // rien de nouveau (ex. valeur retapée à l'identique).
		}
		localWrite( localKey( current.reportId ), data );
		scheduleAutosave();
	}

	/** Sauvegarde immédiate quand la page se cache ou se décharge. */
	function flushAutosave() {
		if ( ! current.form || autosave.generating || autosave.inFlight || ! isDirty() ) {
			return;
		}
		cancelAutosave();
		saveDraft( null, false, { auto: true, keepalive: true } );
	}

	/** Date "JJ/MM à HH:MM" d'un horodatage. */
	function fmtWhen( ts ) {
		var d = new Date( ts );
		return pad2( d.getDate() ) + '/' + pad2( d.getMonth() + 1 ) + ' à ' + pad2( d.getHours() ) + ':' + pad2( d.getMinutes() );
	}

	/** entry.updated (Y-m-d H:i:s, heure du site) → timestamp local approximatif. */
	function entryUpdatedTs( entry ) {
		if ( ! entry || ! entry.updated ) {
			return 0;
		}
		var ts = Date.parse( String( entry.updated ).replace( ' ', 'T' ) );
		return isNaN( ts ) ? 0 : ts;
	}

	/**
	 * Propose de restaurer une copie locale plus récente que l'entrée serveur.
	 * Retourne true si la copie a été appliquée.
	 */
	function maybeRestoreLocal( form, entry ) {
		var key  = localKey( entry ? entry.id : '' );
		var copy = localRead( key );

		if ( ! copy ) {
			return false;
		}

		if ( entry && copy.ts <= entryUpdatedTs( entry ) ) {
			localRemove( key ); // le serveur est au moins aussi récent : copie obsolète.
			return false;
		}

		// Nouveau rapport : si un rapport du même modèle a été enregistré après
		// cette copie (sauvegarde à la volée réussie juste avant un rechargement,
		// tolérance 2 min d'écart d'horloge), la copie est obsolète.
		if ( ! entry ) {
			var superseded = entries.some( function ( e ) {
				return e.model === current.model && entryUpdatedTs( e ) >= copy.ts - 120000;
			} );
			if ( superseded ) {
				localRemove( key );
				return false;
			}
		}

		if ( ! window.confirm( 'Une saisie non enregistrée du ' + fmtWhen( copy.ts ) + ' a été retrouvée sur cet appareil. La restaurer ?' ) ) {
			localRemove( key );
			return false;
		}

		fillForm( form, copy.data );
		return true;
	}

	function openForm( model, reportId ) {
		cancelAutosave();

		card.querySelectorAll( '[data-report-form]' ).forEach( function ( form ) {
			form.hidden = true;
		} );

		var form = card.querySelector( '[data-report-form="' + model + '"]' );

		if ( ! form ) {
			return;
		}

		resetForm( form );

		var entry = reportId ? findEntry( reportId ) : null;

		if ( entry && entry.data ) {
			fillForm( form, entry.data );
			var numberField = form.querySelector( '[data-rf="number"]' );
			if ( numberField && entry.number && ! numberField.value ) {
				numberField.value = entry.number;
			}
		} else {
			var typeField = form.querySelector( '[data-rf="type"]' );
			if ( typeField ) {
				applyCommentTemplate( form, typeField.value, true );
			}
		}

		current = { form: form, model: model, reportId: entry ? entry.id : '' };
		form.hidden = false;
		recompute( form );

		// Référence = état connu du serveur (ou formulaire vierge), calculs
		// compris ; une copie locale restaurée est donc « à enregistrer » et
		// part au prochain autosave.
		autosave.baseline = JSON.stringify( serializeForm( form ) );
		setAutosaveNote( '' );

		if ( maybeRestoreLocal( form, entry ) ) {
			recompute( form );
			setAutosaveNote( 'Saisie restaurée depuis cet appareil, en attente d\'enregistrement.' );
			scheduleAutosave();
		}

		form.scrollIntoView( { block: 'start', behavior: 'smooth' } );
	}

	function closeForm() {
		cancelAutosave();
		if ( current.form ) {
			current.form.hidden = true;
		}
		current = { form: null, model: '', reportId: '' };

		// Un nouveau rapport enregistré n'a pas encore sa ligne dans la liste
		// (rendue côté serveur) : on recharge pour qu'il soit rouvrable.
		if ( listStale ) {
			window.location.reload();
		}
	}

	var listStale = false;

	/** Met à jour (ou ajoute) l'entrée locale d'un rapport après sauvegarde. */
	function rememberEntry( reportId, model, data, status ) {
		if ( ! reportId ) {
			return;
		}
		var now   = new Date();
		var stamp = now.getFullYear() + '-' + pad2( now.getMonth() + 1 ) + '-' + pad2( now.getDate() ) + ' ' +
			pad2( now.getHours() ) + ':' + pad2( now.getMinutes() ) + ':' + pad2( now.getSeconds() );
		var entry = findEntry( reportId );
		if ( ! entry ) {
			entry = { id: reportId, model: model };
			entries.push( entry );
			listStale = true;
		}
		entry.data    = data;
		entry.updated = stamp;
		if ( status ) {
			entry.status = status;
		}
	}

	/**
	 * Sauvegarde du brouillon (ou génération du PDF).
	 *
	 * @param {Element|null} button       Bouton à désactiver pendant l'appel.
	 * @param {boolean}      thenGenerate Générer le PDF au lieu d'enregistrer.
	 * @param {Object}       [opts]       { auto: sauvegarde automatique, keepalive: requête survivant au déchargement }.
	 * @return {Promise}
	 */
	function saveDraft( button, thenGenerate, opts ) {
		opts = opts || {};

		if ( ! current.form ) {
			return Promise.resolve();
		}

		// Une sauvegarde est en vol : on enchaîne après elle (jamais deux requêtes
		// concurrentes, notamment autosave → « Générer le PDF »).
		if ( autosave.inFlight ) {
			if ( opts.auto ) {
				return autosave.inFlight; // l'autosave se reprogrammera s'il reste des modifications.
			}
			var pendingButton = button;
			if ( pendingButton ) {
				pendingButton.disabled = true;
			}
			return autosave.inFlight.then( function () {
				if ( pendingButton ) {
					pendingButton.disabled = false;
				}
				return saveDraft( button, thenGenerate, opts );
			} );
		}

		cancelAutosave();

		var form     = current.form;
		var model    = current.model;
		var sentKey  = localKey( current.reportId );
		var payload  = serializeForm( form );
		var sentJson = JSON.stringify( payload );
		var action   = thenGenerate ? 'gacct_op_report_generate' : 'gacct_op_report_save';

		if ( thenGenerate ) {
			autosave.generating = true;
		}

		if ( button ) {
			button.disabled = true;
		}

		var request = post( action, {
			revision_id: revisionId,
			report_id:   current.reportId,
			model:       model,
			payload:     sentJson
		}, !! opts.keepalive ).then( function ( json ) {
			autosave.inFlight = null;

			if ( button ) {
				button.disabled = false;
			}
			if ( json && json.success ) {
				localRemove( sentKey );

				if ( thenGenerate ) {
					window.location.reload();
					return;
				}

				// Retour Hervé du 06/10/2026 : la liste `entries` (chargée avec la
				// page) n'était jamais mise à jour. Rouvrir un rapport sans
				// recharger affichait l'ancienne saisie, et l'enregistrement
				// suivant écrasait la bonne. On la tient à jour à chaque sauvegarde.
				rememberEntry( json.data.report_id, model, payload, json.data.status );

				if ( current.form === form ) {
					current.reportId  = json.data.report_id;
					autosave.baseline = sentJson;

					if ( opts.auto ) {
						var now = new Date();
						setAutosaveNote( 'Brouillon enregistré automatiquement à ' + pad2( now.getHours() ) + ':' + pad2( now.getMinutes() ) );
					} else {
						say( 'success', 'Brouillon enregistré.' );
						setAutosaveNote( '' );
					}

					// Modifications arrivées pendant l'appel : copie locale sous la
					// bonne clé (nouvel id compris) et nouvel autosave.
					if ( isDirty() ) {
						localWrite( localKey( current.reportId ), serializeForm( form ) );
						scheduleAutosave();
					}
				}
			} else {
				var message = ( json && json.data && json.data.message ) || window.gacctOp.i18n.genericError;
				if ( thenGenerate ) {
					autosave.generating = false;
				}
				if ( opts.auto ) {
					setAutosaveNote( 'Enregistrement automatique impossible : ' + message, true );
				} else {
					say( 'error', message );
				}
			}
		} ).catch( function () {
			autosave.inFlight = null;
			if ( thenGenerate ) {
				autosave.generating = false;
			}
			if ( button ) {
				button.disabled = false;
			}
			if ( opts.auto ) {
				setAutosaveNote( 'Enregistrement automatique impossible (réseau). La saisie reste conservée sur cet appareil.', true );
			} else {
				say( 'error', window.gacctOp.i18n.genericError );
			}
		} );

		autosave.inFlight = request;

		return request;
	}

	card.addEventListener( 'input', function ( event ) {
		if ( current.form && current.form.contains( event.target ) ) {
			recompute( current.form );
			noteChange();
		}
	} );

	card.addEventListener( 'change', function ( event ) {
		if ( ! current.form || ! current.form.contains( event.target ) ) {
			return;
		}
		if ( 'type' === event.target.getAttribute( 'data-rf' ) ) {
			applyCommentTemplate( current.form, event.target.value, false );
		}
		recompute( current.form );
		noteChange();
	} );

	// Tablette qui recharge ou bascule d'application : on sauve tout de suite.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			flushAutosave();
		}
	} );
	window.addEventListener( 'pagehide', flushAutosave );

	card.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-rf-action]' );

		if ( ! button || button.disabled ) {
			return;
		}

		var action = button.getAttribute( 'data-rf-action' );

		// Actions du pack (ex. apply-vr).
		if ( actionRegistry[ action ] ) {
			actionRegistry[ action ]( button, { form: current.form, recompute: recompute, utils: utils } );
			noteChange();
			return;
		}

		if ( 'toggle-new' === action ) {
			var choice = card.querySelector( '.gacct-rf-model-choice' );
			if ( choice ) {
				choice.hidden = ! choice.hidden;
				button.setAttribute( 'aria-expanded', choice.hidden ? 'false' : 'true' );
			}
			return;
		}

		if ( 'open' === action ) {
			openForm( button.getAttribute( 'data-model' ), button.getAttribute( 'data-report-id' ) );
			return;
		}

		if ( 'close-form' === action ) {
			closeForm();
			return;
		}

		if ( 'add-rupture' === action ) {
			addRuptureLine( current.form );
			recompute( current.form );
			noteChange();
			return;
		}

		if ( 'del-rupture' === action ) {
			var line = button.closest( '.gacct-rf-rupture-line' );
			if ( line ) {
				line.remove();
				recompute( current.form );
				noteChange();
			}
			return;
		}

		if ( 'save-draft' === action ) {
			saveDraft( button, false );
			return;
		}

		if ( 'generate' === action ) {
			if ( window.confirm( 'Générer le PDF de ce rapport ? Il sera ajouté au dossier (régénérer remplace son PDF).' ) ) {
				saveDraft( button, true );
			}
			return;
		}

		if ( 'delete' === action ) {
			if ( ! window.confirm( 'Supprimer ce rapport (et son PDF s\'il a été généré) ?' ) ) {
				return;
			}
			var deletedId = button.getAttribute( 'data-report-id' );
			var deleted   = findEntry( deletedId );
			button.disabled = true;
			post( 'gacct_op_report_delete', {
				revision_id: revisionId,
				report_id:   deletedId
			} ).then( function ( json ) {
				if ( json && json.success ) {
					if ( deleted ) {
						localRemove( 'gacct_rf_draft_' + revisionId + '_' + deleted.model + '_' + deleted.id );
					}
					window.location.reload();
				} else {
					button.disabled = false;
					say( 'error', ( json && json.data && json.data.message ) || window.gacctOp.i18n.genericError );
				}
			} ).catch( function () {
				button.disabled = false;
				say( 'error', window.gacctOp.i18n.genericError );
			} );
		}
	} );
} )();
