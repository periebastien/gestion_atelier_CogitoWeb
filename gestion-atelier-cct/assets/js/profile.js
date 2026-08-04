/**
 * Page « Mon profil » de l'espace client.
 *
 * Enrichissement progressif uniquement : la page fonctionne sans ce script
 * (cf. le <noscript> de templates/profile.php). Trois services rendus :
 *   - dépliage des formulaires « changer d'e-mail » / « changer de mot de passe » ;
 *   - aperçu immédiat de la photo choisie, sans passer par le serveur ;
 *   - contrôle de concordance des deux mots de passe, avant envoi.
 *
 * — gestion-atelier-cct / assets/js/profile.js
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.querySelector( '.gacct-profile' );

		if ( ! root ) {
			return;
		}

		initToggles( root );
		initAvatarPreview( root );
		initPasswordMatch( root );
	} );

	/**
	 * Boutons « Changer… » : ouvrent/ferment le formulaire associé.
	 */
	function initToggles( root ) {
		root.querySelectorAll( '[data-gacct-toggle]' ).forEach( function ( wrap ) {
			var btn   = wrap.querySelector( '[data-gacct-toggle-btn]' );
			var panel = wrap.querySelector( '[data-gacct-toggle-panel]' );

			if ( ! btn || ! panel ) {
				return;
			}

			btn.addEventListener( 'click', function () {
				var open = panel.hasAttribute( 'hidden' );

				if ( open ) {
					panel.removeAttribute( 'hidden' );
					var first = panel.querySelector( 'input:not([type="hidden"])' );
					if ( first ) {
						first.focus();
					}
				} else {
					panel.setAttribute( 'hidden', '' );
				}

				btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
		} );
	}

	/**
	 * Aperçu de la photo choisie (remplace l'avatar ou les initiales).
	 */
	function initAvatarPreview( root ) {
		var input   = root.querySelector( '[data-gacct-avatar-input]' );
		var preview = root.querySelector( '[data-gacct-avatar-preview]' );

		if ( ! input || ! preview || ! window.FileReader ) {
			return;
		}

		input.addEventListener( 'change', function () {
			var file = input.files && input.files[0];

			if ( ! file || file.type.indexOf( 'image/' ) !== 0 ) {
				return;
			}

			var reader = new FileReader();

			reader.onload = function ( e ) {
				preview.innerHTML = '';
				var img = document.createElement( 'img' );
				img.src = e.target.result;
				img.alt = '';
				preview.appendChild( img );
			};

			reader.readAsDataURL( file );

			// Choisir une photo annule une éventuelle demande de suppression.
			var remove = root.querySelector( 'input[name="gacct_remove_avatar"]' );
			if ( remove ) {
				remove.checked = false;
			}
		} );
	}

	/**
	 * Concordance des deux mots de passe (le serveur revérifie de toute façon).
	 */
	function initPasswordMatch( root ) {
		var neuf   = root.querySelector( '#gacct-password-new' );
		var repeat = root.querySelector( '#gacct-password-repeat' );

		if ( ! neuf || ! repeat || ! repeat.setCustomValidity ) {
			return;
		}

		var check = function () {
			repeat.setCustomValidity(
				repeat.value && repeat.value !== neuf.value
					? 'Les deux mots de passe ne sont pas identiques.'
					: ''
			);
		};

		neuf.addEventListener( 'input', check );
		repeat.addEventListener( 'input', check );
	}
} )();
