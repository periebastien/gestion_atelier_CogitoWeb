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
					line[ field.getAttribute( 'data-rl' ) ] = field.value;
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
					field.value = String( prefill[ key ] );
				}
			} );
		}

		wrap.appendChild( node );
	}

	function fillForm( form, data ) {
		form.querySelectorAll( '[data-rf]' ).forEach( function ( field ) {
			var value = getDeep( data, field.getAttribute( 'data-rf' ) );

			if ( undefined === value ) {
				return; // garder le pré-remplissage serveur.
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
				field.checked = false;
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

	function post( action, data ) {
		var body = new FormData();
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		body.append( 'action', action );
		body.append( 'nonce', window.gacctOp.nonce );

		return fetch( window.gacctOp.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
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

	function openForm( model, reportId ) {
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
		form.scrollIntoView( { block: 'start', behavior: 'smooth' } );
	}

	function closeForm() {
		if ( current.form ) {
			current.form.hidden = true;
		}
		current = { form: null, model: '', reportId: '' };
	}

	function saveDraft( button, thenGenerate ) {
		if ( ! current.form ) {
			return;
		}

		var payload = serializeForm( current.form );
		var action  = thenGenerate ? 'gacct_op_report_generate' : 'gacct_op_report_save';

		if ( button ) {
			button.disabled = true;
		}

		post( action, {
			revision_id: revisionId,
			report_id:   current.reportId,
			model:       current.model,
			payload:     JSON.stringify( payload )
		} ).then( function ( json ) {
			if ( button ) {
				button.disabled = false;
			}
			if ( json && json.success ) {
				if ( thenGenerate ) {
					window.location.reload();
				} else {
					current.reportId = json.data.report_id;
					say( 'success', 'Brouillon enregistré.' );
				}
			} else {
				say( 'error', ( json && json.data && json.data.message ) || window.gacctOp.i18n.genericError );
			}
		} ).catch( function () {
			if ( button ) {
				button.disabled = false;
			}
			say( 'error', window.gacctOp.i18n.genericError );
		} );
	}

	card.addEventListener( 'input', function ( event ) {
		if ( current.form && current.form.contains( event.target ) ) {
			recompute( current.form );
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
	} );

	card.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-rf-action]' );

		if ( ! button || button.disabled ) {
			return;
		}

		var action = button.getAttribute( 'data-rf-action' );

		// Actions du pack (ex. apply-vr).
		if ( actionRegistry[ action ] ) {
			actionRegistry[ action ]( button, { form: current.form, recompute: recompute, utils: utils } );
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
			return;
		}

		if ( 'del-rupture' === action ) {
			var line = button.closest( '.gacct-rf-rupture-line' );
			if ( line ) {
				line.remove();
				recompute( current.form );
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
			button.disabled = true;
			post( 'gacct_op_report_delete', {
				revision_id: revisionId,
				report_id:   button.getAttribute( 'data-report-id' )
			} ).then( function ( json ) {
				if ( json && json.success ) {
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
