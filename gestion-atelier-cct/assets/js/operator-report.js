/**
 * Rapports de contrôle — carte de la fiche console.
 *
 * Calculs en temps réel : MIROIR des fonctions PHP de
 * includes/gacct-report-forms.php, à partir de la MÊME configuration
 * (seuils/coefs localisés dans window.gacctReportCfg — source unique PHP).
 * Le PDF recalcule toujours côté serveur.
 */
( function () {
	'use strict';

	if ( typeof window.gacctOp === 'undefined' || typeof window.gacctReportCfg === 'undefined' ) {
		return;
	}

	var cfg  = window.gacctReportCfg;
	var card = document.querySelector( '[data-report-card]' );

	if ( ! card ) {
		return;
	}

	var fiche      = document.querySelector( '.gacct-op-fiche[data-revision-id]' );
	var revisionId = fiche ? fiche.getAttribute( 'data-revision-id' ) : '';
	var feedback   = card.querySelector( '.gacct-rf-feedback' );
	var entries    = [];

	try {
		var entriesScript = card.querySelector( '[data-report-entries]' );
		entries = entriesScript ? JSON.parse( entriesScript.textContent ) : [];
	} catch ( e ) {
		entries = [];
	}

	var current = { form: null, model: '', reportId: '' };

	function say( type, message ) {
		if ( feedback ) {
			feedback.className   = 'gacct-op-feedback gacct-rf-feedback ' + type;
			feedback.textContent = message;
			feedback.scrollIntoView( { block: 'nearest' } );
		} else {
			window.alert( message );
		}
	}

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

	/* ──────────────────────────────────────────────────────────────────
	 *  Calculs (miroir PHP)
	 * ────────────────────────────────────────────────────────────────── */

	function scaleResult( value, scale ) {
		for ( var i = 0; i < scale.length; i++ ) {
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
		var actual = results.filter( function ( r ) {
			return r && 'NON RÉALISÉ' !== r && 'NON RÉALISÉ*' !== r;
		} );
		if ( ! actual.length ) {
			return results.length ? 'NON RÉALISÉ' : '';
		}
		for ( var i = 0; i < cfg.severity.length; i++ ) {
			if ( actual.indexOf( cfg.severity[ i ] ) !== -1 ) {
				return cfg.severity[ i ];
			}
		}
		return '';
	}

	function calcVisualGroup( values ) {
		var filled = values.filter( function ( v ) { return '' !== v; } );

		if ( ! filled.length ) {
			return { average: null, result: 'NON RÉALISÉ' };
		}
		if ( filled.indexOf( 'REF' ) !== -1 ) {
			return { average: 0, result: 'RÉFORME' };
		}

		var sum = 0;
		filled.forEach( function ( v ) {
			sum += cfg.visual_values[ v ] ? cfg.visual_values[ v ].weight : 0;
		} );
		var average = sum / filled.length;

		return { average: average, result: scaleResult( average, cfg.visual_scale ) };
	}

	function calcPorosity( values ) {
		var nums = values.filter( function ( v ) { return '' !== v && ! isNaN( parseFloat( v ) ); } )
			.map( parseFloat );

		if ( ! nums.length ) {
			return { average: null, result: 'NON RÉALISÉ' };
		}

		var average = nums.reduce( function ( a, b ) { return a + b; }, 0 ) / nums.length;

		return { average: average, result: scaleResult( average, cfg.porosity_scale ) };
	}

	function calcTear( values, porosityAverage ) {
		var min = ( null !== porosityAverage && porosityAverage > cfg.tear_min.porosity_gt )
			? cfg.tear_min.high : cfg.tear_min.low;
		var zones = {};

		Object.keys( cfg.tear_zones ).forEach( function ( key ) {
			var v = values[ key ];
			zones[ key ] = ( '' === v || undefined === v || isNaN( parseFloat( v ) ) )
				? 'NON RÉALISÉ'
				: scaleResult( parseFloat( v ), cfg.tear_scale );
		} );

		return { min: min, zones: zones, result: worst( Object.keys( zones ).map( function ( k ) { return zones[ k ]; } ) ) };
	}

	function calcRuptureLine( line ) {
		var nominal = parseFloat( line.nominal ) || 0;
		var measure = ( '' === line.measure || undefined === line.measure ) ? null : parseFloat( line.measure );
		var coef    = cfg.rupture_materials[ line.material ] ? cfg.rupture_materials[ line.material ].coef : 0;
		var custom  = parseFloat( line.seuil ) || 0;
		var seuil   = custom > 0 ? custom : nominal * coef;

		if ( null === measure || isNaN( measure ) || nominal <= 0 || nominal === seuil ) {
			return { seuil: seuil, margin: null, result: 'NR*' };
		}

		var margin = Math.floor( ( measure - seuil ) / ( nominal - seuil ) * 100 );

		return { seuil: seuil, margin: margin, result: scaleResult( margin, cfg.rupture_scale ) };
	}

	function calcGeometry( calage, freins ) {
		if ( ( ! calage && ! freins ) || ( 'NON RÉALISÉ' === calage && 'NON RÉALISÉ' === freins ) ) {
			return 'NON RÉALISÉ';
		}
		if ( 'RÉFORME' === calage || 'RÉFORME' === freins ) {
			return 'RÉFORME';
		}
		if ( 'CALAGE BON' === calage || 'CALAGE BON' === freins ) {
			return 'CALAGE BON';
		}
		return 'NON RÉALISÉ';
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Formulaire : accès générique par data-rf (chemins pointés)
	 * ────────────────────────────────────────────────────────────────── */

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

		// Lignes du test de rupture (voile).
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
			} else if ( ! field.hasAttribute( 'data-rf-keep' ) ) {
				// Les champs d'identification gardent leur pré-remplissage serveur.
				if ( ! /^ident\.|^author_id$|^type$/.test( field.getAttribute( 'data-rf' ) ) ) {
					field.value = field.defaultValue !== undefined ? field.defaultValue : '';
				} else {
					field.value = field.defaultValue !== undefined ? field.defaultValue : field.value;
				}
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
		var type = form.querySelector( '[data-rf="type"]' );
		if ( type ) {
			type.value = 'periodique';
		}

		var linesWrap = form.querySelector( '[data-rf-rupture-lines]' );
		if ( linesWrap ) {
			linesWrap.innerHTML = '';
		}

		var equipDefault = form.querySelector( '[data-rf="sellette.verifications"]' );
		if ( equipDefault && ! equipDefault.value ) {
			equipDefault.value = equipDefault.getAttribute( 'data-rf-default' ) || '';
		}
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Badges + recalcul temps réel
	 * ────────────────────────────────────────────────────────────────── */

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

	function latestVr() {
		var vr = 0;
		entries.forEach( function ( entry ) {
			if ( 'suspente' !== entry.model || ! entry.data ) {
				return;
			}
			var d    = entry.data;
			var test = parseFloat( d.resistance_test ) || 0;
			var ptv  = parseFloat( d.ptv_max ) || 0;
			var coef = parseFloat( d.coef ) || 0;
			var total = 0;
			( d.ensembles || [] ).forEach( function ( e ) {
				total += ( parseInt( e && e.nb, 10 ) || 0 ) * ( parseFloat( e && e.resistance ) || 0 );
			} );
			if ( coef > 0 && total > 0 ) {
				vr = ( ( test * ptv ) / total ) * coef;
			}
		} );
		return vr;
	}

	function recomputeVoile( form ) {
		var data = serializeForm( form );
		var type = data.type || 'periodique';

		// Inspection visuelle.
		var groupResults = [];
		Object.keys( cfg.visual_groups ).forEach( function ( group ) {
			var values = [];
			for ( var i = 0; i < 6; i++ ) {
				values.push( ( data.visual && data.visual[ group ] && data.visual[ group ][ i ] ) || '' );
			}
			var res = calcVisualGroup( values );
			groupResults.push( res.result );
			setBadge( form, 'visual_' + group, res.result + ( null !== res.average ? ' (' + fmt( res.average, 1 ) + ')' : '' ) );
		} );
		var visualGlobal = worst( groupResults );
		setBadge( form, 'visual_global', visualGlobal );

		// Porosité.
		var poroValues = [];
		for ( var p = 0; p < 5; p++ ) {
			poroValues.push( ( data.porosity && data.porosity[ p ] ) || '' );
		}
		var poro = calcPorosity( poroValues );
		setBadge( form, 'porosity', poro.result );
		var poroInfo = form.querySelector( '[data-rf-computed="porosity"]' );
		if ( poroInfo ) {
			poroInfo.textContent = null === poro.average
				? '—'
				: 'Moyenne : ' + fmt( poro.average, 1 ) + ' s — ' + ( poro.average > 0 ? fmt( cfg.porosity_factor / poro.average, 1 ) : '0' ) + ' l/m²/min → ' + poro.result;
		}

		// Déchirure.
		var tear = calcTear( data.tear || {}, poro.average );
		setBadge( form, 'tear', tear.result );
		var tearInfo = form.querySelector( '[data-rf-computed="tear"]' );
		if ( tearInfo ) {
			tearInfo.textContent = 'Seuil minimal : ' + fmt( tear.min, 2 ) + ' DaN → ' + tear.result;
		}

		// Rupture.
		var ruptureResults = [];
		var linesWrap      = form.querySelector( '[data-rf-rupture-lines]' );
		if ( linesWrap ) {
			linesWrap.querySelectorAll( '.gacct-rf-rupture-line' ).forEach( function ( row ) {
				var line = {};
				row.querySelectorAll( '[data-rl]' ).forEach( function ( field ) {
					line[ field.getAttribute( 'data-rl' ) ] = field.value;
				} );
				var res     = calcRuptureLine( line );
				var display = row.querySelector( '[data-rl-result]' );
				if ( display ) {
					display.textContent = 'NR*' === res.result
						? 'Seuil : ' + fmt( res.seuil, 2 ) + ' DaN — en attente de mesure'
						: 'Seuil : ' + fmt( res.seuil, 2 ) + ' DaN · marge ' + res.margin + ' % → ' + res.result;
				}
				if ( 'NR*' !== res.result ) {
					ruptureResults.push( res.result );
				}
			} );
		}
		var rupture = ruptureResults.length ? worst( ruptureResults ) : 'NON RÉALISÉ*';
		setBadge( form, 'rupture', rupture );
		var ruptureInfo = form.querySelector( '[data-rf-computed="rupture"]' );
		if ( ruptureInfo ) {
			ruptureInfo.textContent = 'NON RÉALISÉ*' === rupture
				? '* Test réalisé sur recommandation du constructeur uniquement.'
				: 'Résultat global : ' + rupture;
		}

		// Géométrie.
		var geometry = calcGeometry(
			data.geometry ? data.geometry.calage_interp : '',
			data.geometry ? data.geometry.freins_interp : ''
		);
		setBadge( form, 'geometry', geometry );

		// État général (périodique uniquement).
		var generalBadge = form.querySelector( '[data-rf-badge="general"]' );
		var generalNote  = form.querySelector( '[data-rf-general-note]' );
		var generalLabel = form.querySelector( '[data-rf-general-label]' );

		if ( 'partielle' === type ) {
			if ( generalBadge ) {
				generalBadge.hidden = true;
			}
			if ( generalNote ) {
				generalNote.hidden = false;
			}
		} else {
			var general;
			if ( 'RÉFORME' === geometry ) {
				general = 'RÉFORME';
			} else {
				general = worst( [ visualGlobal, poro.result, tear.result, rupture.replace( '*', '' ) ] );
			}
			if ( generalBadge ) {
				generalBadge.hidden = false;
			}
			if ( generalNote ) {
				generalNote.hidden = true;
			}
			setBadge( form, 'general', general || 'NON RÉALISÉ' );
		}
		if ( generalLabel ) {
			generalLabel.hidden = 'partielle' === type && generalBadge && generalBadge.hidden;
		}

		// VR dispo pour le seuil de rupture.
		var vr     = latestVr();
		var vrHint = form.querySelector( '[data-rf-vr-hint]' );
		if ( vrHint ) {
			vrHint.hidden = ! ( vr > 0 );
			if ( vr > 0 ) {
				vrHint.textContent = 'VR du « Calcul réforme suspente » : ' + fmt( vr, 2 ) + ' DaN — bouton « VR » pour l\'appliquer à une ligne.';
			}
		}
		form.querySelectorAll( '[data-rf-action="apply-vr"]' ).forEach( function ( btn ) {
			btn.hidden = ! ( vr > 0 );
		} );
	}

	function recomputeSuspente( form ) {
		var data  = serializeForm( form );
		var total = 0;
		var nb    = 0;

		for ( var i = 0; i < 4; i++ ) {
			var e      = ( data.ensembles && data.ensembles[ i ] ) || {};
			var n      = parseInt( e.nb, 10 ) || 0;
			var r      = parseFloat( e.resistance ) || 0;
			var resmax = n * r;
			nb    += n;
			total += resmax;
			var cell = form.querySelector( '[data-rf-computed="resmax_' + i + '"]' );
			if ( cell ) {
				cell.textContent = fmt( resmax, 1 );
			}
		}

		var nbCell = form.querySelector( '[data-rf-computed="nb_total"]' );
		if ( nbCell ) {
			nbCell.textContent = nb;
		}
		var totalCell = form.querySelector( '[data-rf-computed="resmax_total"]' );
		if ( totalCell ) {
			totalCell.textContent = fmt( total, 1 );
		}

		var test = parseFloat( data.resistance_test ) || 0;
		var ptv  = parseFloat( data.ptv_max ) || 0;
		var coef = parseFloat( data.coef ) || 0;
		var vr   = ( coef > 0 && total > 0 ) ? ( ( test * ptv ) / total ) * coef : 0;

		var vrCell = form.querySelector( '[data-rf-computed="vr"]' );
		if ( vrCell ) {
			vrCell.textContent = fmt( vr, 2 ) + ' DaN';
		}
	}

	function recompute( form ) {
		var model = form.getAttribute( 'data-report-form' );

		if ( 'voile' === model ) {
			recomputeVoile( form );
		} else if ( 'suspente' === model ) {
			recomputeSuspente( form );
		}
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Lignes du test de rupture
	 * ────────────────────────────────────────────────────────────────── */

	function addRuptureLine( form, prefill ) {
		var template = form.querySelector( '[data-rf-rupture-template]' );
		var wrap     = form.querySelector( '[data-rf-rupture-lines]' );

		if ( ! template || ! wrap ) {
			return;
		}
		if ( wrap.querySelectorAll( '.gacct-rf-rupture-line' ).length >= cfg.rupture_max_lines ) {
			say( 'error', 'Maximum ' + cfg.rupture_max_lines + ' suspentes testées.' );
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

	/* ──────────────────────────────────────────────────────────────────
	 *  Ouverture / fermeture / actions
	 * ────────────────────────────────────────────────────────────────── */

	function findEntry( reportId ) {
		for ( var i = 0; i < entries.length; i++ ) {
			if ( entries[ i ].id === reportId ) {
				return entries[ i ];
			}
		}
		return null;
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
		} else if ( 'voile' === model ) {
			// Texte-modèle des commentaires selon le type.
			applyCommentTemplate( form, form.querySelector( '[data-rf="type"]' ).value, true );
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

	function applyCommentTemplate( form, type, force ) {
		var field = form.querySelector( '[data-rf="comment"]' );

		if ( ! field ) {
			return;
		}

		var templates = {};
		try {
			templates = JSON.parse( field.getAttribute( 'data-rf-comment-templates' ) || '{}' );
		} catch ( e ) {}

		var isTemplate = '' === field.value.trim()
			|| field.value.trim() === ( templates.periodique || '' ).trim()
			|| field.value.trim() === ( templates.partielle || '' ).trim();

		if ( force || isTemplate ) {
			field.value = templates[ type ] || '';
		} else if ( ! window.confirm( 'Remplacer le commentaire actuel par le texte-modèle du type « ' + type + ' » ?' ) ) {
			return;
		} else {
			field.value = templates[ type ] || '';
		}
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

		if ( 'apply-vr' === action ) {
			var vr = latestVr();
			if ( vr > 0 ) {
				var input = button.closest( '.gacct-rf-seuil-wrap' ).querySelector( '[data-rl="seuil"]' );
				if ( input ) {
					input.value = ( Math.round( vr * 100 ) / 100 );
					recompute( current.form );
				}
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
