/**
 * Apparition douce des blocs au défilement (validé par Bastien le 03/10/2026, en test sur l'accueil).
 *
 * Le script ne fait que repérer les blocs et poser des classes ; tout le mouvement est en CSS
 * (assets/css/apparition.css). Garde-fous :
 *  - sans IntersectionObserver ou avec « réduire les animations », il ne fait rien : tout reste visible ;
 *  - le bandeau d'ouverture (première section) n'est jamais animé ;
 *  - un bloc déjà à l'écran au chargement s'affiche tel quel ;
 *  - une fois apparu, un bloc perd ses classes : rien ne reste en conflit avec les effets de survol.
 */
( function () {
	'use strict';

	if ( ! ( 'IntersectionObserver' in window ) || ! window.matchMedia || window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return;
	}
	var page = document.querySelector( '.elementor[data-elementor-type="wp-page"]' );
	if ( ! page ) {
		return;
	}

	// Cartes : elles apparaissent l'une après l'autre (cascade de 80 ms).
	var CARDS = '.ar-svc-card, .ar-card-iconline, .ar-stat, .ar-trust-row';
	// Blocs simples : têtes de section, textes, boutons, images.
	var SIMPLE = '.elementor-widget-heading, .elementor-widget-text-editor, .elementor-widget-button, .elementor-widget-image, .elementor-widget-html, .elementor-widget-icon-box';
	var STEP_GAP = 250; // ms entre deux étapes numérotées (le trait se dessine pendant ce temps)
	var CARD_GAP = 80;  // ms entre deux cartes ou deux blocs voisins
	var MAX_RANK = 5;

	var units = [];
	var sections = page.children;

	function add( el, kind ) {
		if ( el.classList.contains( 'ar-rv-unit' ) || el.closest( '.ar-rv-unit' ) ) {
			return;
		}
		el.classList.add( 'ar-rv-unit' );
		units.push( { el: el, kind: kind } );
	}

	for ( var s = 1; s < sections.length; s++ ) {
		var sec = sections[ s ];
		// Étapes numérotées : le conteneur de chaque étape (rond, titre, texte) apparaît d'un bloc.
		sec.querySelectorAll( '.ar-step-line' ).forEach( function ( h ) {
			add( h.parentElement, 'step' );
		} );
		// Dernière étape (sans trait) : sœur des précédentes.
		units.slice().forEach( function ( u ) {
			if ( 'step' === u.kind && u.el.parentElement ) {
				Array.prototype.forEach.call( u.el.parentElement.children, function ( c ) {
					add( c, 'step' );
				} );
			}
		} );
		sec.querySelectorAll( CARDS ).forEach( function ( el ) {
			add( el, 'card' );
		} );
		sec.querySelectorAll( SIMPLE ).forEach( function ( el ) {
			add( el, 'simple' );
		} );
	}

	// Rang dans la fratrie (même parent) pour la cascade.
	var ranks = new Map();
	var limit = window.innerHeight * 0.9;
	var hidden = [];
	units.forEach( function ( u ) {
		var parent = u.el.parentElement;
		var rank = ranks.get( parent ) || 0;
		ranks.set( parent, rank + 1 );
		if ( u.el.getBoundingClientRect().top < limit ) {
			u.el.classList.remove( 'ar-rv-unit' );
			return; // déjà visible au chargement : pas d'animation
		}
		var delay = 'step' === u.kind ? rank * STEP_GAP : Math.min( rank, MAX_RANK ) * CARD_GAP;
		u.el.style.setProperty( '--ar-rv-delay', delay + 'ms' );
		u.el.classList.add( 'ar-rv', 'step' === u.kind ? 'ar-rv-step' : 'ar-rv-block' );
		u.delay = delay;
		hidden.push( u );
	} );

	function clean( el ) {
		el.classList.remove( 'ar-rv', 'ar-rv-in', 'ar-rv-step', 'ar-rv-block', 'ar-rv-unit' );
		el.style.removeProperty( '--ar-rv-delay' );
	}

	var byEl = new Map();
	hidden.forEach( function ( u ) {
		byEl.set( u.el, u );
	} );

	var io = new IntersectionObserver( function ( entries ) {
		entries.forEach( function ( e ) {
			if ( ! e.isIntersecting ) {
				return;
			}
			var u = byEl.get( e.target );
			io.unobserve( e.target );
			e.target.classList.add( 'ar-rv-in' );
			window.setTimeout( function () {
				clean( e.target );
			}, ( u ? u.delay : 0 ) + 1200 );
		} );
	}, { rootMargin: '0px 0px -8% 0px' } );

	hidden.forEach( function ( u ) {
		io.observe( u.el );
	} );

	// Rattrapage : un saut de défilement (touche Fin, lien d'ancre) peut faire passer un bloc
	// au-dessus de l'écran sans qu'il ait jamais été visible ; il s'affiche alors sans attendre.
	var ticking = false;
	function catchUp() {
		ticking = false;
		var left = 0;
		hidden.forEach( function ( u ) {
			if ( ! u.el.classList.contains( 'ar-rv' ) || u.el.classList.contains( 'ar-rv-in' ) ) {
				return;
			}
			left++;
			if ( u.el.getBoundingClientRect().bottom < 0 ) {
				io.unobserve( u.el );
				clean( u.el );
			}
		} );
		if ( ! left ) {
			window.removeEventListener( 'scroll', onScroll );
		}
	}
	function onScroll() {
		if ( ! ticking ) {
			ticking = true;
			window.requestAnimationFrame( catchUp );
		}
	}
	window.addEventListener( 'scroll', onScroll, { passive: true } );
}() );
