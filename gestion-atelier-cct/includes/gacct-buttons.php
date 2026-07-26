<?php
/**
 * Chargement du script des CTA (dedoublement du libelle des boutons ".ar-btn-swap").
 *
 * Le script est charge sur tout le front : les CTA sont presents sur toutes les
 * pages (hero, bandeaux, en-tete). Il pese quelques centaines d'octets et ne
 * fait rien si aucun bouton ne porte la classe.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', 'gacct_buttons_enqueue_assets' );

/**
 * Enqueue assets/js/ar-buttons.js.
 *
 * Depend de jQuery : le hook `elementor/frontend/init` est emis par jQuery.trigger,
 * un ecouteur natif ne le recevrait pas.
 */
function gacct_buttons_enqueue_assets() {
	$rel_path = 'assets/js/ar-buttons.js';
	$path     = plugin_dir_path( dirname( __FILE__ ) ) . $rel_path;

	wp_enqueue_script(
		'gacct-buttons',
		plugins_url( '', dirname( __FILE__ ) ) . '/' . $rel_path,
		array( 'jquery' ),
		file_exists( $path ) ? filemtime( $path ) : GACCT_Plugin::VERSION,
		true
	);
}
