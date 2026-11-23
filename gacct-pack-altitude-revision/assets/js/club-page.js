/**
 * Pages club personnalisées : simulateur de remise + suivi discret des visites.
 * Le suivi part en beacon une fois la page réellement affichée (les robots des
 * messageries qui ouvrent les liens sans exécuter de JS ne comptent pas).
 */
( function () {
	'use strict';
	var cfg = window.arClubPage;
	if ( ! cfg ) {
		return;
	}

	/* ---- Simulateur ---- */
	var range = document.getElementById( 'arClubpVoiles' );
	var nb = document.getElementById( 'arClubpNb' );
	var pct = document.getElementById( 'arClubpPct' );
	var l1 = document.getElementById( 'arClubpLigne1' );
	var l2 = document.getElementById( 'arClubpLigne2' );
	var res = document.querySelector( '.ar-clubp-sim-res' );
	var paliers = Object.keys( cfg.paliers || {} ).map( Number ).sort( function ( a, b ) {
		return a - b;
	} );

	function remise( n ) {
		var r = 0;
		paliers.forEach( function ( seuil ) {
			if ( n >= seuil ) {
				r = Number( cfg.paliers[ seuil ] );
			}
		} );
		return r;
	}
	function euros( v ) {
		return Math.round( v ).toLocaleString( 'fr-FR' ) + ' €';
	}
	function maj() {
		var n = Number( range.value );
		var r = remise( n );
		var min = Number( range.min ), max = Number( range.max );
		range.style.setProperty( '--prog', ( ( n - min ) / ( max - min ) ) * 100 + '%' );
		range.setAttribute( 'aria-valuetext', n + ( n > 1 ? ' voiles' : ' voile' ) + ', remise de ' + r + ' %' );
		nb.textContent = n;
		pct.textContent = r;
		res.classList.toggle( 'is-zero', r === 0 );
		if ( r === 0 ) {
			var manque = paliers[ 0 ] - n;
			l1.textContent = 'pas encore de remise';
			l2.textContent = 'plus que ' + manque + ( manque > 1 ? ' voiles' : ' voile' ) + ' pour avoir 10 %';
			return;
		}
		var prochain = paliers.filter( function ( s ) {
			return s > n;
		} )[ 0 ];
		l1.textContent = 'de remise sur chaque révision, soit ' + euros( cfg.prix * ( 1 - r / 100 ) ) + ' la voile';
		l2.textContent = 'environ ' + euros( n * cfg.prix * r / 100 ) + ' d’économie pour le club' +
			( prochain ? ' (' + cfg.paliers[ prochain ] + ' % dès ' + prochain + ' voiles)' : '' );
	}
	if ( range ) {
		range.addEventListener( 'input', maj );
		maj();
	}

	/* ---- Suivi ---- */
	function envoyer( ev ) {
		var fd = new FormData();
		fd.append( 'action', 'ar_club_vue' );
		fd.append( 'slug', cfg.slug );
		fd.append( 'ev', ev );
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.ajax, fd );
		} else {
			fetch( cfg.ajax, { method: 'POST', body: fd, keepalive: true } );
		}
	}
	// Une vue par club et par session d'onglet ; seulement si la page est visible.
	function vue() {
		try {
			if ( sessionStorage.getItem( 'arClubpVu' ) === cfg.slug ) {
				return;
			}
			sessionStorage.setItem( 'arClubpVu', cfg.slug );
		} catch ( e ) {}
		envoyer( 'vue' );
	}
	function quandVisible() {
		if ( document.visibilityState === 'visible' ) {
			window.setTimeout( vue, 1500 );
		} else {
			document.addEventListener( 'visibilitychange', function f() {
				if ( document.visibilityState === 'visible' ) {
					document.removeEventListener( 'visibilitychange', f );
					window.setTimeout( vue, 1500 );
				}
			} );
		}
	}
	quandVisible();

	// Le club reste reconnu 90 jours (attribution d'une pré-demande faite plus tard).
	document.cookie = 'ar_club=' + encodeURIComponent( cfg.slug ) + ';path=/;max-age=' + ( 90 * 86400 ) + ';SameSite=Lax;Secure';

	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest( '[data-ar-clubp], a[href^="tel:"], #demande-club button, #demande-club [type="submit"]' );
		if ( ! a ) {
			return;
		}
		if ( a.matches( 'a[href^="tel:"]' ) ) {
			envoyer( 'tel' );
		} else if ( a.dataset.arClubp === 'cta' ) {
			envoyer( 'cta' );
		} else if ( a.closest( '#demande-club' ) ) {
			envoyer( 'formulaire' );
		}
	} );
}() );
