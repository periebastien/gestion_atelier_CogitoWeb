/**
 * Page de confirmation de commande (gestion-atelier-cct) :
 * - boutons « Copier » + toast ;
 * - barre d'action collante mobile, pilotée par IntersectionObserver ;
 * - partage du bon d'intervention (Web Share API, repli = copie du lien).
 */
( function () {
	'use strict';

	var toast = document.getElementById( 'gacct-conf-toast' );
	var timer = null;

	function showToast( message ) {
		if ( ! toast ) {
			return;
		}
		toast.querySelector( 'span' ).textContent = message;
		toast.classList.add( 'show' );
		clearTimeout( timer );
		timer = setTimeout( function () {
			toast.classList.remove( 'show' );
		}, 2200 );
	}

	/* ── Partage du bon d'intervention ──
	   Une grande partie des clients n'a pas d'imprimante : on leur permet
	   d'envoyer le lien à quelqu'un qui en a une. Web Share API quand elle
	   existe (mobile), copie du lien sinon. Le lien reste un vrai <a> : sans
	   JS, il ouvre simplement le bon. */
	document.addEventListener( 'click', function ( event ) {
		var share = event.target.closest( '.gacct-conf-share' );
		if ( ! share ) {
			return;
		}

		var url = share.href;

		if ( navigator.share ) {
			event.preventDefault();
			navigator.share( {
				title: share.getAttribute( 'data-share-title' ) || document.title,
				url: url
			} ).catch( function () {} );
			return;
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			event.preventDefault();
			navigator.clipboard.writeText( url ).then( function () {
				showToast( share.getAttribute( 'data-copy-msg' ) || 'Lien copié' );
			} ).catch( function () {
				window.open( url, '_blank', 'noopener' );
			} );
		}
	} );

	/* ── Barre d'action collante (mobile) ──
	   Elle n'apparaît que lorsque le bloc « À faire maintenant » n'est plus
	   visible ET qu'il est sorti PAR LE HAUT : au chargement, le bloc est en
	   dessous du pli sur certains écrans, et une barre qui surgit d'emblée
	   ferait doublon avec les boutons qu'on est en train de faire défiler. */
	( function () {
		var bar  = document.getElementById( 'gacct-conf-mbar' );
		var todo = document.getElementById( 'todo' );

		if ( ! bar || ! todo || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		document.body.classList.add( 'gacct-conf-hasbar' );

		new IntersectionObserver( function ( entries ) {
			var e = entries[ 0 ];
			bar.classList.toggle( 'show', ! e.isIntersecting && e.boundingClientRect.top < 0 );
		}, { threshold: 0 } ).observe( todo );
	} )();

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '[data-copy]' );
		if ( ! btn ) {
			return;
		}
		var value = btn.getAttribute( 'data-copy' );
		var msg   = btn.getAttribute( 'data-copy-msg' ) || 'Copié : ' + value;

		function legacyCopy() {
			// Fallback vieux navigateurs / permission refusée.
			var input = document.createElement( 'textarea' );
			input.value = value;
			document.body.appendChild( input );
			input.select();
			try {
				document.execCommand( 'copy' );
				showToast( msg );
			} catch ( e ) {}
			document.body.removeChild( input );
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then( function () {
				showToast( msg );
			} ).catch( legacyCopy );
		} else {
			legacyCopy();
		}
	} );
} )();
