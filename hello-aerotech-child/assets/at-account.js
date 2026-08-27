/**
 * AEROTECH — Espace client.
 *
 * Remplace le <script> qui vivait dans un widget html de la page 289 : posé en
 * base, il se faisait désinfecter par kses à chaque réécriture de
 * `_elementor_data` sous un utilisateur sans `unfiltered_html` (WP-CLI = user 0)
 * et son code finissait AFFICHÉ EN CLAIR dans la barre latérale. Un fichier du
 * thème enfant n'a pas ce problème.
 *
 * 1. Entrée active de la navigation latérale.
 * 2. Lien de déconnexion : WordPress exige un nonce, sinon il affiche l'écran
 *    « Voulez-vous vraiment vous déconnecter ? ». Le nonce est propre à
 *    l'utilisateur et ne peut donc pas vivre dans le JSON Elementor (qui est en
 *    plus mis en cache par Elementor) : il est calculé en PHP et injecté ici.
 */
( function () {
	'use strict';

	var cfg = window.atAccount || {};

	function norm( url ) {
		return String( url || '' ).replace( /\/$/, '' );
	}

	/* ── 1. Entrée active ────────────────────────────────────────────────── */

	function markActive() {
		var current = norm( window.location.href.split( '#' )[ 0 ].split( '?' )[ 0 ] );
		var items   = document.querySelectorAll( '.nav-item .elementor-icon-list-item' );
		var best    = null;
		var bestLen = -1;

		Array.prototype.forEach.call( items, function ( item ) {
			var link = item.querySelector( 'a' );
			item.classList.remove( 'active' );
			if ( ! link ) {
				return;
			}

			var href = norm( link.href );

			if ( current === href ) {
				best    = item;
				bestLen = Infinity;
				return;
			}

			// Un onglet parent ne doit pas rester actif sur un onglet enfant :
			// on garde la correspondance de préfixe la PLUS longue.
			if ( bestLen !== Infinity && current.indexOf( href + '/' ) === 0 && href.length > bestLen ) {
				best    = item;
				bestLen = href.length;
			}
		} );

		if ( best ) {
			best.classList.add( 'active' );
		}
	}

	/* ── 2. Déconnexion ──────────────────────────────────────────────────── */

	function wireLogout() {
		if ( ! cfg.logoutUrl ) {
			return;
		}

		var links = document.querySelectorAll(
			'.nav-item a[href*="wp-login.php"], .at-cmenu-logout a[href*="wp-login.php"]'
		);

		Array.prototype.forEach.call( links, function ( a ) {
			a.setAttribute( 'href', cfg.logoutUrl );
		} );
	}

	function init() {
		markActive();
		wireLogout();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Le tiroir JetMenu est un composant Vue monté après le chargement : ses
	// liens n'existent pas au DOMContentLoaded.
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest && e.target.closest( '.jet-mobile-menu__toggle' ) ) {
			window.setTimeout( wireLogout, 350 );
		}
	}, true );
}() );
