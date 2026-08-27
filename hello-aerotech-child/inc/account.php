<?php
/**
 * AEROTECH — Espace client (page 289 « Mon compte » et tous ses onglets
 * Profile Builder : /mon-compte/, .../mes-demandes-interventions/, etc.).
 *
 * Porte l'habillage du header simplifié (template Elementor « AEROTECH Header
 * client ») et du tiroir de navigation, ainsi que le JS repris du widget html
 * de la page — qui se faisait désinfecter par kses et s'affichait en clair.
 *
 * Le header lui-même, le footer neutre et les deux gabarits du tiroir sont des
 * templates Elementor conditionnés sur cette page ; ce fichier ne fait que les
 * habiller et les alimenter.
 *
 * @package HelloAerotechChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AT_ACCOUNT_PAGE_ID' ) ) {
	define( 'AT_ACCOUNT_PAGE_ID', 289 );
}

/**
 * Sommes-nous dans l'espace client ?
 *
 * Tous les onglets du Profile Builder JetEngine sont servis par la MÊME page
 * (289) : `is_page()` les couvre tous, y compris les endpoints WooCommerce.
 *
 * @return bool
 */
function at_is_account_area() {
	return is_page( AT_ACCOUNT_PAGE_ID );
}

/**
 * Classe de corps : sert de racine à toutes les règles d'at-account.css.
 */
add_filter(
	'body_class',
	function ( $classes ) {
		if ( at_is_account_area() ) {
			$classes[] = 'at-account';
		}
		return $classes;
	}
);

/**
 * Feuille et script de l'espace client.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! at_is_account_area() ) {
			return;
		}

		$dir = get_stylesheet_directory();
		$uri = get_stylesheet_directory_uri();

		$css = $dir . '/assets/at-account.css';
		wp_enqueue_style(
			'at-account',
			$uri . '/assets/at-account.css',
			array(),
			file_exists( $css ) ? filemtime( $css ) : '1.0.0'
		);

		$js = $dir . '/assets/at-account.js';
		wp_enqueue_script(
			'at-account',
			$uri . '/assets/at-account.js',
			array(),
			file_exists( $js ) ? filemtime( $js ) : '1.0.0',
			true
		);

		// Le nonce de déconnexion est propre à l'utilisateur : il ne peut pas
		// vivre dans le JSON Elementor, qui est mis en cache par Elementor et
		// partagé entre les visiteurs.
		wp_localize_script(
			'at-account',
			'atAccount',
			array( 'logoutUrl' => wp_logout_url( home_url( '/' ) ) )
		);
	},
	25
);
