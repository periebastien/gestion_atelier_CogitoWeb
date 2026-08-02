/**
 * Header public « Altitude Révision » — comportement au scroll.
 *
 * Repris du script de fin de <body> de « Homepage Redesign v2.html » (projet
 * Claude Design) ; seuls les identifiants ont été adaptés à ceux posés sur les
 * conteneurs et widgets du template Elementor 1284.
 *
 * Desktop (≥1025) : la barre fixe descend quand le bas du header 1 sort du
 * viewport. Mobile et tablette (≤1024) : la barre se cache au scroll
 * descendant et revient en pilule givrée au scroll montant.
 */
( function () {
	'use strict';

	var hdr    = document.getElementById( 'arHdr' );
	var hdrIn  = document.getElementById( 'arHdrIn' );
	var fp     = document.getElementById( 'arFpWrap' );
	var tel    = document.getElementById( 'arMobTel' );
	var burger = document.getElementById( 'arBurger' );
	var pTel   = document.getElementById( 'arPanelTel' );
	var pMenu  = document.getElementById( 'arPanelMenu' );

	// Le header public n'est pas rendu partout (l'espace client a le sien).
	if ( ! hdr || ! hdrIn ) {
		return;
	}

	var mq        = window.matchMedia( '(max-width: 1024px)' );
	var lastY     = window.scrollY;
	var panelOpen = false;

	function closeAll() {
		if ( pTel ) {
			pTel.classList.remove( 'ar-open' );
		}
		if ( pMenu ) {
			pMenu.classList.remove( 'ar-open' );
		}
		if ( burger ) {
			burger.setAttribute( 'aria-expanded', 'false' );
		}
		panelOpen = false;
	}

	function toggle( panel, opener ) {
		var was = panel.classList.contains( 'ar-open' );
		closeAll();
		if ( ! was ) {
			panel.classList.add( 'ar-open' );
			panelOpen = true;
			if ( opener ) {
				opener.setAttribute( 'aria-expanded', 'true' );
			}
		}
	}

	// Tap sur le téléphone : ouvre le panneau contact au lieu de composer.
	// En desktop le lien tel: reste un lien normal.
	if ( tel && pTel ) {
		tel.addEventListener( 'click', function ( e ) {
			if ( ! mq.matches ) {
				return;
			}
			e.preventDefault();
			toggle( pTel, null );
		} );
	}

	if ( burger && pMenu ) {
		burger.addEventListener( 'click', function () {
			toggle( pMenu, burger );
		} );
		// Le burger est une icône, pas un <button> : on lui rend le clavier.
		burger.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || ' ' === e.key ) {
				e.preventDefault();
				toggle( pMenu, burger );
			}
		} );
	}

	function onScroll() {
		var y = window.scrollY;

		if ( mq.matches ) {
			// Ouvrir un panneau change la hauteur de page et déclenche un
			// scroll parasite qui refermerait tout : on met lastY à jour et on
			// sort sans rien toucher. Les panneaux ne se ferment jamais seuls.
			if ( panelOpen ) {
				lastY = y;
				return;
			}
			if ( Math.abs( y - lastY ) < 6 ) {
				return;
			}
			var down = y > lastY;
			lastY = y;
			var past = y > 90;
			hdrIn.style.transform = ( past && down ) ? 'translateY(-135%)' : 'translateY(0)';
			hdr.classList.toggle( 'ar-mob-pill', past && ! down );
		} else {
			if ( fp ) {
				fp.classList.toggle( 'ar-on', hdr.getBoundingClientRect().bottom <= 0 );
			}
			lastY = y;
		}
	}

	function reset() {
		closeAll();
		hdrIn.style.transform = '';
		hdr.classList.remove( 'ar-mob-pill' );
		if ( fp ) {
			fp.classList.remove( 'ar-on' );
		}
		lastY = window.scrollY;
		onScroll();
	}

	window.addEventListener( 'scroll', onScroll, { passive: true } );
	if ( mq.addEventListener ) {
		mq.addEventListener( 'change', reset );
	} else if ( mq.addListener ) {
		mq.addListener( reset );
	}
	onScroll();
} )();
