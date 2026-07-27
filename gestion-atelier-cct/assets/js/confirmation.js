/**
 * Page de confirmation de commande : boutons "Copier" + toast.
 * (gestion-atelier-cct)
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
