/**
 * Pack Altitude Révision — calculs temps réel ParachecK® (miroir exact des
 * fonctions PHP de includes/paracheck-calcs.php, mêmes seuils via la config
 * localisée window.gacctReportCfg). S'enregistre auprès du framework
 * (window.gacctReportUI, assets/js/operator-report.js de gestion-atelier-cct).
 */
( function () {
	'use strict';

	if ( typeof window.gacctReportUI === 'undefined' || typeof window.gacctReportCfg === 'undefined' ) {
		return;
	}

	var UI  = window.gacctReportUI;
	var cfg = window.gacctReportCfg;

	function calcVisualGroup( values, U ) {
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

		return { average: average, result: U.scaleResult( average, cfg.visual_scale ) };
	}

	function calcPorosity( values, U ) {
		var nums = values.filter( function ( v ) { return '' !== v && ! isNaN( parseFloat( v ) ); } )
			.map( parseFloat );

		if ( ! nums.length ) {
			return { average: null, result: 'NON RÉALISÉ' };
		}

		var average = nums.reduce( function ( a, b ) { return a + b; }, 0 ) / nums.length;

		return { average: average, result: U.scaleResult( average, cfg.porosity_scale ) };
	}

	function calcTear( values, porosityAverage, U ) {
		var min = ( null !== porosityAverage && porosityAverage > cfg.tear_min.porosity_gt )
			? cfg.tear_min.high : cfg.tear_min.low;
		var zones = {};

		Object.keys( cfg.tear_zones ).forEach( function ( key ) {
			var v = values[ key ];
			zones[ key ] = ( '' === v || undefined === v || isNaN( parseFloat( v ) ) )
				? 'NON RÉALISÉ'
				: U.scaleResult( parseFloat( v ), cfg.tear_scale );
		} );

		return { min: min, zones: zones, result: U.worst( Object.keys( zones ).map( function ( k ) { return zones[ k ]; } ) ) };
	}

	function calcRuptureLine( line, U ) {
		var nominal = parseFloat( line.nominal ) || 0;
		var measure = ( '' === line.measure || undefined === line.measure ) ? null : parseFloat( line.measure );
		var coef    = cfg.rupture_materials[ line.material ] ? cfg.rupture_materials[ line.material ].coef : 0;
		var custom  = parseFloat( line.seuil ) || 0;
		var seuil   = custom > 0 ? custom : nominal * coef;

		if ( null === measure || isNaN( measure ) || nominal <= 0 || nominal === seuil ) {
			return { seuil: seuil, margin: null, result: 'NR*' };
		}

		var margin = Math.floor( ( measure - seuil ) / ( nominal - seuil ) * 100 );

		return { seuil: seuil, margin: margin, result: U.scaleResult( margin, cfg.rupture_scale ) };
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

	/** VR du dernier « Calcul réforme suspente » du dossier (brouillon ou final). */
	function latestVr( U ) {
		var vr = 0;
		U.entries().forEach( function ( entry ) {
			if ( 'suspente' !== entry.model || ! entry.data ) {
				return;
			}
			var d     = entry.data;
			var test  = parseFloat( d.resistance_test ) || 0;
			var ptv   = parseFloat( d.ptv_max ) || 0;
			var coef  = parseFloat( d.coef ) || 0;
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

	/* ── Rapport voile ───────────────────────────────────────────────── */

	UI.registerCalc( 'voile', function ( form, U ) {
		var data = U.serializeForm( form );
		var type = data.type || 'periodique';

		// Inspection visuelle.
		var groupResults = [];
		Object.keys( cfg.visual_groups ).forEach( function ( group ) {
			var values = [];
			for ( var i = 0; i < 6; i++ ) {
				values.push( ( data.visual && data.visual[ group ] && data.visual[ group ][ i ] ) || '' );
			}
			var res = calcVisualGroup( values, U );
			groupResults.push( res.result );
			U.setBadge( form, 'visual_' + group, res.result + ( null !== res.average ? ' (' + U.fmt( res.average, 1 ) + ')' : '' ) );
		} );
		var visualGlobal = U.worst( groupResults );
		U.setBadge( form, 'visual_global', visualGlobal );

		// Porosité (nombre de points = schéma, plafond appareil = « 600+ »).
		var poroCount  = ( cfg.porosity_points && cfg.porosity_points.length ) ? cfg.porosity_points.length : 4;
		var poroValues = [];
		for ( var p = 0; p < poroCount; p++ ) {
			poroValues.push( ( data.porosity && data.porosity[ p ] ) || '' );
		}
		var poro = calcPorosity( poroValues, U );
		U.setBadge( form, 'porosity', poro.result );
		var poroInfo = form.querySelector( '[data-rf-computed="porosity"]' );
		if ( poroInfo ) {
			var poroCeiling = cfg.porosity_ceiling || 600;
			var poroAvgTxt  = null !== poro.average && poro.average >= poroCeiling
				? poroCeiling + '+'
				: U.fmt( poro.average, 1 );
			poroInfo.textContent = null === poro.average
				? '—'
				: 'Moyenne : ' + poroAvgTxt + ' s — ' + ( poro.average > 0 ? U.fmt( cfg.porosity_factor / poro.average, 1 ) : '0' ) + ' l/m²/min → ' + poro.result;
		}

		// Déchirure.
		var tear = calcTear( data.tear || {}, poro.average, U );
		U.setBadge( form, 'tear', tear.result );
		var tearInfo = form.querySelector( '[data-rf-computed="tear"]' );
		if ( tearInfo ) {
			tearInfo.textContent = 'Seuil minimal : ' + U.fmt( tear.min, 2 ) + ' DaN → ' + tear.result;
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
				var res     = calcRuptureLine( line, U );
				var display = row.querySelector( '[data-rl-result]' );
				if ( display ) {
					display.textContent = 'NR*' === res.result
						? 'Seuil : ' + U.fmt( res.seuil, 2 ) + ' DaN — en attente de mesure'
						: 'Seuil : ' + U.fmt( res.seuil, 2 ) + ' DaN · marge ' + res.margin + ' % → ' + res.result;
				}
				if ( 'NR*' !== res.result ) {
					ruptureResults.push( res.result );
				}
			} );
		}
		var rupture = ruptureResults.length ? U.worst( ruptureResults ) : 'NON RÉALISÉ*';
		U.setBadge( form, 'rupture', rupture );
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
		U.setBadge( form, 'geometry', geometry );

		// État général (périodique uniquement).
		var generalBadge = form.querySelector( '[data-rf-badge="general"]' );
		var generalNote  = form.querySelector( '[data-rf-general-note]' );

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
				general = U.worst( [ visualGlobal, poro.result, tear.result, rupture.replace( '*', '' ) ] );
			}
			if ( generalBadge ) {
				generalBadge.hidden = false;
			}
			if ( generalNote ) {
				generalNote.hidden = true;
			}
			U.setBadge( form, 'general', general || 'NON RÉALISÉ' );
		}

		// VR du calcul réforme suspente, proposé pour le seuil de rupture.
		var vr     = latestVr( U );
		var vrHint = form.querySelector( '[data-rf-vr-hint]' );
		if ( vrHint ) {
			vrHint.hidden = ! ( vr > 0 );
			if ( vr > 0 ) {
				vrHint.textContent = 'VR du « Calcul réforme suspente » : ' + U.fmt( vr, 2 ) + ' DaN — bouton « VR » pour l\'appliquer à une ligne.';
			}
		}
		form.querySelectorAll( '[data-rf-action="apply-vr"]' ).forEach( function ( btn ) {
			btn.hidden = ! ( vr > 0 );
		} );
	} );

	/* ── Calcul réforme suspente ─────────────────────────────────────── */

	UI.registerCalc( 'suspente', function ( form, U ) {
		var data  = U.serializeForm( form );
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
				cell.textContent = U.fmt( resmax, 1 );
			}
		}

		var nbCell = form.querySelector( '[data-rf-computed="nb_total"]' );
		if ( nbCell ) {
			nbCell.textContent = nb;
		}
		var totalCell = form.querySelector( '[data-rf-computed="resmax_total"]' );
		if ( totalCell ) {
			totalCell.textContent = U.fmt( total, 1 );
		}

		var test = parseFloat( data.resistance_test ) || 0;
		var ptv  = parseFloat( data.ptv_max ) || 0;
		var coef = parseFloat( data.coef ) || 0;
		var vr   = ( coef > 0 && total > 0 ) ? ( ( test * ptv ) / total ) * coef : 0;

		var vrCell = form.querySelector( '[data-rf-computed="vr"]' );
		if ( vrCell ) {
			vrCell.textContent = U.fmt( vr, 2 ) + ' DaN';
		}
	} );

	/* ── Action : appliquer le VR à une ligne du test de rupture ─────── */

	UI.registerAction( 'apply-vr', function ( button, ctx ) {
		var vr = latestVr( ctx.utils );
		if ( vr > 0 ) {
			var wrap  = button.closest( '.gacct-rf-seuil-wrap' );
			var input = wrap ? wrap.querySelector( '[data-rl="seuil"]' ) : null;
			if ( input && ctx.form ) {
				input.value = ( Math.round( vr * 100 ) / 100 );
				ctx.recompute( ctx.form );
			}
		}
	} );
} )();
