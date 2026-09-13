<?php
/**
 * Redirections 301 des URL de l'ancien site (13/09/2026).
 *
 * Constat GA4 (mai à septembre 2026) : les anciennes pages (.html / .php) portaient
 * encore ~900 sessions sur 4 mois, toutes en 404 depuis la bascule du 11/09.
 * Carte exacte + préfixes, puis repli : toute 404 en .html / .php part vers l'accueil.
 * Filtrable : `ar_redirects_exact`, `ar_redirects_prefix`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ar_redirects_exact() {
	return apply_filters( 'ar_redirects_exact', array(
		'/index.php'                                   => '/',
		'/glossaire-0-11.html'                         => '/',
		'/tarifs-0-3.html'                             => '/tarifs/',
		'/contact/index.php'                           => '/contact/',
		'/contact/contact_ok.php'                      => '/contact/',
		'/mentions-legales.php'                        => '/mentions-legales/',
		'/actualites'                                  => '/actualites/',
		'/actualites/3-nouvelle-norme-paracheck.html'  => '/actualites/',
		'/demande-d-intervention.php'                  => '/demande-intervention/',
		'/pliage-parachute-de-secours-c3.html'         => '/pliages-secours/',
		'/checkout'                                    => '/commander/',
		'/glossaire/revision-parapente-bretagne-1-6.html'        => '/revision-parapente-bretagne/',
		'/glossaire/revision-parapente-pays-de-loire-1-8.html'   => '/revision-parapente-pays-de-la-loire/',
		'/glossaire/revision-parapente-haut-france-1-9.html'     => '/revision-parapente-hauts-de-france/',
		'/glossaire/revision-de-parapente-en-normandie-1-5.html' => '/revision-parapente-normandie/',
		'/glossaire/revision-parapente-ile-de-france-1-7.html'   => '/revision-parapente-ile-de-france/',
		'/espace-securise/s-inscrire.php'              => '/demande-intervention/',
		'/espace-securise/mot-de-passe-oublie.php'     => '/mot-de-passe-oublie/',
		'/extranet/mot-de-passe-oublie.php'            => '/mot-de-passe-oublie/',
		'/extranet/reset-mot-de-passe.php'             => '/mot-de-passe-oublie/',
	) );
}

/** Préfixe → destination (le plus long préfixe gagne). */
function ar_redirects_prefix() {
	return apply_filters( 'ar_redirects_prefix', array(
		'/controles/'                                        => '/controles/',
		'/controles-de-parapente/'                           => '/controles/',
		'/reparations/'                                      => '/reparations/',
		'/reparations-de-parapente---solution-de-reparation-/' => '/reparations/',
		'/suspentes/'                                        => '/suspentes/',
		'/entretien-des-suspentes-de-parapentes/'            => '/suspentes/',
		'/pliages-secours/'                                  => '/pliages-secours/',
		'/pliages-secours---entretien-de-parachutes-de-secours/' => '/pliages-secours/',
		'/glossaire/'                                        => '/',
		'/actualites/'                                       => '/actualites/',
		'/espace-securise/mon-compte/'                       => '/mon-compte/',
		'/espace-securise/'                                  => '/connexion/',
		'/extranet/'                                         => '/connexion/',
		'/commande/'                                         => '/demande-intervention/',
		'/contact/'                                          => '/contact/',
	) );
}

add_action( 'template_redirect', function () {
	if ( ! is_404() ) {
		return;
	}
	$path = (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	$path = '/' . ltrim( $path, '/' );
	if ( '/' === $path ) {
		return;
	}
	$target = '';

	$exact = ar_redirects_exact();
	if ( isset( $exact[ $path ] ) ) {
		$target = $exact[ $path ];
	}

	if ( ! $target ) {
		$best = '';
		foreach ( ar_redirects_prefix() as $prefix => $dest ) {
			if ( 0 === strpos( $path, $prefix ) && strlen( $prefix ) > strlen( $best ) ) {
				$best   = $prefix;
				$target = $dest;
			}
		}
	}

	// Repli : toute ancienne page dynamique ou statique (.html / .php) vers l'accueil.
	if ( ! $target && preg_match( '/\.(html?|php)$/i', $path ) ) {
		$target = '/';
	}

	if ( ! $target ) {
		return;
	}
	// Ne jamais rediriger une page vers elle-même (ex. /controles/xxx.html → /controles/ existe).
	if ( untrailingslashit( $target ) === untrailingslashit( $path ) ) {
		return;
	}
	wp_safe_redirect( home_url( $target ), 301 );
	exit;
}, 1 );
