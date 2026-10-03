/**
 * Formulaire de demande, inscription via un club : l'étape « date et retour »
 * est remplie toute seule (jour réservé du club, retour groupé) et remplacée
 * par une note. La garde serveur impose de toute façon ces deux valeurs.
 */
( function () {
	'use strict';

	var cfg = window.gacctDemande && window.gacctDemande.club;
	if ( ! cfg ) {
		return;
	}

	var essais = 0;

	function rowOf( el ) {
		return el && ( el.closest( '.jet-form-builder-row' ) || el.closest( '.jet-form-builder__field-wrap' ) || el.parentNode );
	}

	function appliquer() {
		var input = document.querySelector( 'input[name="date_intervention"]' );
		var fp    = input && input._flatpickr;

		if ( ! fp ) {
			if ( essais++ < 60 ) {
				window.setTimeout( appliquer, 250 );
			}
			return;
		}

		if ( input.value !== cfg.jour ) {
			fp.setDate( cfg.jour, true );
		}

		var ports = document.querySelectorAll( 'input[name="frais_de_ports"], input[name="frais_de_ports[]"]' );
		var port  = null;
		Array.prototype.forEach.call( ports, function ( el ) {
			if ( String( el.value ) === String( cfg.retour ) ) {
				port = el;
			}
		} );
		if ( port && ! port.checked ) {
			port.click();
		}

		var page = input.closest( '.jet-form-builder-page' ) || ( port && port.closest( '.jet-form-builder-page' ) );
		if ( ! page || page.querySelector( '.gacct-club-step-note' ) ) {
			return;
		}

		var masquer = [ rowOf( input ), port ? rowOf( port ) : null ];
		Array.prototype.forEach.call( page.querySelectorAll( '.flatpickr-calendar, .inputdateinter, .champ-date-cache' ), function ( el ) {
			masquer.push( el );
		} );
		masquer.forEach( function ( el ) {
			if ( el && el !== page ) {
				el.classList.add( 'gacct-club-hidden' );
			}
		} );

		var note = document.createElement( 'div' );
		note.className = 'gacct-club-step-note';
		note.setAttribute( 'role', 'status' );
		note.textContent = cfg.message;
		page.insertBefore( note, page.firstChild );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', appliquer );
	} else {
		appliquer();
	}
} )();
