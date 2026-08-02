<?php
/**
 * Header public « Altitude Révision » — chargement de son habillage.
 *
 * Le header lui-même est un template Elementor (post 1284) monté en widgets
 * NATIFS : conteneurs, logo du site, nav-menu, icon-list, icon, button. Ce
 * fichier ne charge que ce qu'Elementor ne sait pas exprimer en réglages :
 *
 *  - assets/css/header.css : double barre, grille 3 colonnes du mobile,
 *    pilule givrée, panneaux flottants, paliers 1366/1200 px ;
 *  - assets/js/header.js   : apparition de la barre fixe au scroll, masquage
 *    directionnel et pilule en mobile, ouverture des deux panneaux.
 *
 * Chargé sur tout le front, comme gacct-buttons.php : le header est global.
 * Le JS sort de lui-même si le header public n'est pas rendu (espace client,
 * qui a son propre template).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', 'gacct_header_enqueue_assets' );

/**
 * Enqueue la CSS et le JS du header public.
 */
function gacct_header_enqueue_assets() {
	$base_dir = plugin_dir_path( dirname( __FILE__ ) );
	$base_url = plugins_url( '', dirname( __FILE__ ) ) . '/';

	$css_rel  = 'assets/css/header.css';
	$css_path = $base_dir . $css_rel;

	wp_enqueue_style(
		'gacct-header',
		$base_url . $css_rel,
		array(),
		file_exists( $css_path ) ? filemtime( $css_path ) : GACCT_Plugin::VERSION
	);

	$js_rel  = 'assets/js/header.js';
	$js_path = $base_dir . $js_rel;

	wp_enqueue_script(
		'gacct-header',
		$base_url . $js_rel,
		array(),
		file_exists( $js_path ) ? filemtime( $js_path ) : GACCT_Plugin::VERSION,
		true
	);
}
