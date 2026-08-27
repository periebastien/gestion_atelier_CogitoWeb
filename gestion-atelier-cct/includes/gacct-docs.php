<?php
/**
 * Documentation interne de l'atelier.
 *
 * Écran « Documentation » du menu Gestion Atelier : liste les documents de
 * référence (cycle de vie, guide de l'atelier...) et les sert en HTML complet,
 * hors thème, via l'endpoint `/?gacct_doc=<slug>`, réservé aux comptes
 * autorisés (opérateurs et administrateurs), sur le modèle des rapports.
 *
 * White-label : le plugin ne porte aucun document. Chaque pack ou site déclare
 * les siens via le filtre `gacct_docs` :
 *
 *   add_filter( 'gacct_docs', function ( $docs ) {
 *       $docs['mon-doc'] = array(
 *           'title' => 'Titre affiché',
 *           'desc'  => 'Une ligne de description.',
 *           'file'  => '/chemin/absolu/vers/le/fichier.html',
 *       );
 *       return $docs;
 *   } );
 *
 * Sans document déclaré (AEROTECH), l'écran et l'endpoint sont inertes.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documents déclarés (slug => title/desc/file). Les fichiers absents sont écartés.
 *
 * @return array<string,array{title:string,desc:string,file:string}>
 */
function gacct_docs_list() {
	$docs = apply_filters( 'gacct_docs', array() );
	$out  = array();

	foreach ( (array) $docs as $slug => $doc ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug || empty( $doc['file'] ) || ! file_exists( $doc['file'] ) ) {
			continue;
		}
		$out[ $slug ] = wp_parse_args( $doc, array( 'title' => $slug, 'desc' => '' ) );
	}

	return $out;
}

/**
 * L'utilisateur courant peut-il consulter la documentation ?
 */
function gacct_docs_user_can() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	return defined( 'GACCT_OP_CAP' ) && current_user_can( GACCT_OP_CAP );
}

/* -----------------------------------------------------------------------------
 * Menu : sous-page « Documentation », seulement s'il y a des documents.
 * -------------------------------------------------------------------------- */

add_action( 'admin_menu', 'gacct_docs_register_menu', 20 );

function gacct_docs_register_menu() {
	if ( ! defined( 'GACCT_OP_MENU_SLUG' ) || ! gacct_docs_list() ) {
		return;
	}

	add_submenu_page(
		GACCT_OP_MENU_SLUG,
		__( 'Documentation', 'gestion-atelier-cct' ),
		__( 'Documentation', 'gestion-atelier-cct' ),
		defined( 'GACCT_OP_CAP' ) ? GACCT_OP_CAP : 'manage_options',
		'gacct-docs',
		'gacct_docs_render_admin_page'
	);
}

function gacct_docs_render_admin_page() {
	if ( ! gacct_docs_user_can() ) {
		wp_die( esc_html__( 'Accès refusé.', 'gestion-atelier-cct' ) );
	}

	echo '<div class="wrap"><h1>' . esc_html__( 'Documentation', 'gestion-atelier-cct' ) . '</h1>';
	echo '<p>' . esc_html__( 'Les documents de référence de la plateforme. Chaque document s’ouvre dans un nouvel onglet.', 'gestion-atelier-cct' ) . '</p>';

	foreach ( gacct_docs_list() as $slug => $doc ) {
		$url = add_query_arg( 'gacct_doc', $slug, home_url( '/' ) );
		echo '<div class="card" style="max-width:640px;padding:16px 20px;margin-top:12px;">';
		echo '<h2 style="margin:0 0 6px;"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $doc['title'] ) . '</a></h2>';
		if ( ! empty( $doc['desc'] ) ) {
			echo '<p style="margin:0;">' . esc_html( $doc['desc'] ) . '</p>';
		}
		echo '</div>';
	}

	echo '</div>';
}

/* -----------------------------------------------------------------------------
 * Endpoint : /?gacct_doc=<slug>. HTML complet, hors thème, jamais indexé.
 * -------------------------------------------------------------------------- */

add_action( 'template_redirect', 'gacct_docs_maybe_serve', 5 );

function gacct_docs_maybe_serve() {
	if ( empty( $_GET['gacct_doc'] ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}

	if ( ! gacct_docs_user_can() ) {
		wp_die( esc_html__( 'Accès refusé.', 'gestion-atelier-cct' ), '', array( 'response' => 403 ) );
	}

	$docs = gacct_docs_list();
	$slug = sanitize_key( wp_unslash( $_GET['gacct_doc'] ) );

	if ( ! isset( $docs[ $slug ] ) ) {
		wp_die( esc_html__( 'Document inconnu.', 'gestion-atelier-cct' ), '', array( 'response' => 404 ) );
	}

	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );

	echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>';
	readfile( $docs[ $slug ]['file'] );
	echo '</body></html>';
	exit;
}
