<?php
/**
 * Cogito Feedback (2026-11-10) : corrections cliquées sur la page, projet « altitude-revision » de https://feedback.cogitoweb.net.
 * Inerte tant que le navigateur n'a pas été activé par un lien « ?fb=… » (jeton client ou admin) : peut rester en place.
 * Chargé sur le site ET dans l'admin WordPress (remarques sur l'organisation de l'admin).
 * Désactivation : supprimer ce fichier.
 */
defined( 'ABSPATH' ) || exit;

/** Mode correction actif (lien « ?fb=… » ou cookie « cogito_fb » posé par w.js) : barre d'admin masquée, vue visiteur. */
add_filter( 'show_admin_bar', function ( $show ) {
	return ( isset( $_GET['fb'] ) || ! empty( $_COOKIE['cogito_fb'] ) ) ? false : $show;
}, 100 );

function cogito_feedback_script() {
	if ( wp_doing_ajax() || is_feed() ) {
		return;
	}
	echo '<script src="https://feedback.cogitoweb.net/w.js" defer data-no-optimize="1" data-no-minify="1" data-cfasync="false" data-rocket-defer="0" nowprocket></script>' . "\n";
}
add_action( 'wp_footer', 'cogito_feedback_script', 100 );
add_action( 'admin_footer', 'cogito_feedback_script', 100 );
