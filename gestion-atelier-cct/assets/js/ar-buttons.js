/**
 * CTA du site : dedoublement du libelle des boutons ".ar-btn-swap".
 *
 * L'effet recherche (le libelle monte et sa copie prend sa place) demande deux
 * lignes identiques empilees dans une boite en overflow:hidden. Or le widget
 * bouton d'Elementor ne rend qu'un seul noeud de texte
 * (<span class="elementor-button-text">Libelle</span>).
 *
 * On dedouble donc ce noeud ici plutot que d'ecrire le HTML des deux lignes
 * dans le champ "Texte" du widget : le champ resterait lisible dans l'editeur
 * et le libelle ne peut pas se desynchroniser d'une copie a l'autre.
 *
 * Le CSS correspondant vit dans le CSS personnalise du kit Elementor
 * (bloc "CTA : fleche qui se redresse + libelle qui defile").
 *
 * @package gestion-atelier-cct
 */
( function () {
	'use strict';

	// Le libelle de tout bouton .ar-btn-swap, et en plus l'icone des boutons
	// .ar-btn-swap-icon : ceux dont l'icone n'est pas une fleche (casque, etc.)
	// et qui defilent au lieu de pivoter.
	var SELECTOR = '.ar-btn-swap .elementor-button-text, .ar-btn-swap-icon .elementor-button-icon';

	/**
	 * Transforme
	 *   <span class="elementor-button-text">Libelle</span>
	 * en
	 *   <span class="elementor-button-text" data-ar-swap="1">
	 *     <span class="ar-swap-line">Libelle</span>
	 *     <span class="ar-swap-line" aria-hidden="true">Libelle</span>
	 *   </span>
	 *
	 * La copie est masquee aux lecteurs d'ecran : le libelle ne doit etre
	 * annonce qu'une fois.
	 *
	 * La meme transformation sert pour l'icone (.elementor-button-icon) des
	 * boutons .ar-btn-swap-icon : deux copies de l'icone empilees, qui montent
	 * en meme temps que le libelle.
	 *
	 * @param {HTMLElement} label Le noeud a dedoubler.
	 */
	function split( label ) {
		if ( '1' === label.getAttribute( 'data-ar-swap' ) ) {
			return;
		}

		var line = document.createElement( 'span' );
		line.className = 'ar-swap-line';

		// On deplace les noeuds existants plutot que de recopier le texte :
		// le libelle d'un bouton Elementor peut contenir du HTML (<br>, <strong>).
		while ( label.firstChild ) {
			line.appendChild( label.firstChild );
		}

		var copy = line.cloneNode( true );
		copy.setAttribute( 'aria-hidden', 'true' );

		label.appendChild( line );
		label.appendChild( copy );
		label.setAttribute( 'data-ar-swap', '1' );
	}

	/**
	 * @param {ParentNode} [root] Racine a explorer (document par defaut).
	 */
	function scan( root ) {
		var scope = root || document;

		if ( ! scope.querySelectorAll ) {
			return;
		}

		Array.prototype.forEach.call( scope.querySelectorAll( SELECTOR ), split );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			scan();
		} );
	} else {
		scan();
	}

	// Boutons rendus apres coup : apercu de l'editeur Elementor, popups,
	// listings JetEngine charges en ajax.
	// L'evenement est emis par jQuery.trigger : un ecouteur natif ne le verrait pas.
	if ( window.jQuery ) {
		window.jQuery( window ).on( 'elementor/frontend/init', function () {
			if ( ! window.elementorFrontend || ! window.elementorFrontend.hooks ) {
				return;
			}

			window.elementorFrontend.hooks.addAction(
				'frontend/element_ready/button.default',
				function ( $scope ) {
					scan( $scope && $scope[0] );
				}
			);
		} );
	}
}() );
