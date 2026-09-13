/**
 * Mesure d'audience côté navigateur (complément de gacct-analytics.php).
 *
 *  - email_clic   { campagne }            : arrivée depuis un e-mail (utm_medium transactionnel / relance)
 *  - relance_clic { campagne, order_ref } : arrivée par un lien de relance après annulation (?gacct_r=)
 *  - clic_contact { type, cible }         : téléphone (tel:), e-mail (mailto:), itinéraire (Google Maps)
 *  - contact_envoi { form_id }            : formulaire JetFormBuilder envoyé (hors demande d'intervention)
 *
 * Les événements sont lus par GTM (balise « GA4 - evenements metier »).
 */
( function () {
	'use strict';

	function push( data ) {
		try {
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push( data );
		} catch ( e ) {}
	}

	var cfg = window.gacctAnalytics || {};

	/* --- Arrivée depuis un e-mail ------------------------------------------ */
	try {
		var q = new URLSearchParams( window.location.search );
		var medium = ( q.get( 'utm_medium' ) || '' ).toLowerCase();
		var campagne = q.get( 'utm_campaign' ) || '';
		if ( q.get( 'gacct_r' ) ) {
			push( { event: 'relance_clic', campagne: campagne || 'abandon' } );
		} else if ( medium === 'transactionnel' || medium === 'relance' ) {
			push( { event: 'email_clic', campagne: campagne || 'autre' } );
		}
	} catch ( e ) {}

	/* --- Clics de contact --------------------------------------------------- */
	document.addEventListener( 'click', function ( ev ) {
		var a = ev.target && ev.target.closest ? ev.target.closest( 'a[href]' ) : null;
		if ( ! a ) {
			return;
		}
		var href = a.getAttribute( 'href' ) || '';
		var type = '';
		if ( href.indexOf( 'tel:' ) === 0 ) {
			type = 'telephone';
		} else if ( href.indexOf( 'mailto:' ) === 0 ) {
			type = 'email';
		} else if ( /google\.[a-z.]+\/maps|maps\.app\.goo\.gl|goo\.gl\/maps|waze\.com/i.test( href ) ) {
			type = 'itineraire';
		}
		if ( type ) {
			push( { event: 'clic_contact', type: type, cible: href.replace( /^(tel:|mailto:)/, '' ).split( '?' )[ 0 ].slice( 0, 80 ) } );
		}
	}, true );

	/* --- Formulaires JetFormBuilder (contact, etc.) -------------------------- */
	function watchForm( form ) {
		var id = parseInt( form.getAttribute( 'data-form-id' ) || '0', 10 );
		if ( ! id || id === parseInt( cfg.demandeFormId || 0, 10 ) ) {
			return; // la demande d'intervention a sa propre mesure (demande-v2.js)
		}
		var wrap = form.querySelector( '.jet-form-builder-messages-wrap' ) || form.parentNode;
		if ( ! wrap || ! window.MutationObserver ) {
			return;
		}
		var sent = false;
		new MutationObserver( function () {
			if ( sent ) {
				return;
			}
			if ( wrap.querySelector( '.jet-form-builder-message--success, .jet-form-builder-message.success' ) ) {
				sent = true;
				push( { event: 'contact_envoi', form_id: String( id ) } );
			}
		} ).observe( wrap, { childList: true, subtree: true, attributes: true } );

		// Redirection après envoi : l'événement part juste avant de quitter la page.
		form.addEventListener( 'submit', function () {
			if ( form.classList.contains( 'submit-type-reload' ) && ! sent ) {
				sent = true;
				push( { event: 'contact_envoi', form_id: String( id ) } );
			}
		} );
	}
	Array.prototype.forEach.call( document.querySelectorAll( 'form.jet-form-builder-form[data-form-id]' ), watchForm );
} )();
