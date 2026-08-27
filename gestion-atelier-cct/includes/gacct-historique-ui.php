<?php
/**
 * Rendu client de l'historique : page « Mes anciennes révisions ».
 *
 * Shortcode `[gacct_historique]`, posé dans le template Elementor de la
 * sous-page du Profile Builder, sur le modèle de `[gacct_profil]` (template
 * 1899) et de `[gacct_interventions_toolbar]` (template 521).
 *
 * Les données viennent de la table `gacct_historique` (voir gacct-historique.php),
 * le téléchargement passe par l'endpoint authentifié `/?gacct_archive=`.
 * Le filtrage se fait côté navigateur : quelques dizaines de lignes par client
 * au maximum, inutile de recharger la page.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
 *  ASSETS
 * ============================================================================= */

add_action( 'wp_enqueue_scripts', 'gacct_historique_enqueue_assets' );

/**
 * Charge la CSS de l'historique sur l'espace client uniquement.
 */
function gacct_historique_enqueue_assets() {
	if ( ! function_exists( 'gacct_dash_should_enqueue' ) || ! gacct_dash_should_enqueue() ) {
		return;
	}

	$base_url = plugins_url( '', dirname( __FILE__ ) );
	$base_dir = dirname( __DIR__ );
	$rel      = 'assets/css/historique.css';

	if ( file_exists( $base_dir . '/' . $rel ) ) {
		wp_enqueue_style(
			'gacct-historique',
			$base_url . '/' . $rel,
			array(),
			(string) filemtime( $base_dir . '/' . $rel )
		);
	}
}

/* =============================================================================
 *  TEXTES (filtrables : white-label)
 * ============================================================================= */

/**
 * Libellés de la page.
 *
 * @return array<string,string>
 */
function gacct_historique_texts() {
	return apply_filters(
		'gacct_historique_texts',
		array(
			'vide_titre'   => __( 'Aucune révision antérieure', 'gestion-atelier-cct' ),
			'vide_texte'   => __( 'Nous n\'avons pas retrouvé de révision effectuée avant la mise en service de cet espace. Vos révisions en cours et à venir sont dans « Mes demandes d\'intervention ».', 'gestion-atelier-cct' ),
			'intro'        => __( 'Les révisions réalisées dans notre atelier avant la mise en service de cet espace client. Les rapports restent téléchargeables.', 'gestion-atelier-cct' ),
			'recherche'    => __( 'Rechercher une marque, un modèle…', 'gestion-atelier-cct' ),
			'col_date'     => __( 'Date', 'gestion-atelier-cct' ),
			'col_materiel' => __( 'Matériel', 'gestion-atelier-cct' ),
			'col_couleur'  => __( 'Couleur', 'gestion-atelier-cct' ),
			'col_rapport'  => __( 'Rapport', 'gestion-atelier-cct' ),
			'telecharger'  => __( 'Voir le rapport', 'gestion-atelier-cct' ),
			'sans_rapport' => __( 'Non disponible', 'gestion-atelier-cct' ),
			'aucun_result' => __( 'Aucune révision ne correspond à votre recherche.', 'gestion-atelier-cct' ),
			'stat_total'   => __( 'révisions', 'gestion-atelier-cct' ),
			'stat_voiles'  => __( 'matériels', 'gestion-atelier-cct' ),
			'stat_periode' => __( 'période', 'gestion-atelier-cct' ),
		)
	);
}

/* =============================================================================
 *  DONNÉES
 * ============================================================================= */

/**
 * Données de la page, prêtes pour le gabarit.
 *
 * @param int $user_id Client (0 = utilisateur courant).
 * @return array<string,mixed>
 */
function gacct_historique_page_data( $user_id = 0 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$lignes  = gacct_historique_client( $user_id );

	$materiels = array();
	$annees    = array();

	foreach ( $lignes as $l ) {
		$cle = strtolower( trim( $l['marque'] . '|' . $l['modele'] . '|' . $l['taille'] ) );
		$materiels[ $cle ] = true;

		if ( ! empty( $l['date_revision'] ) ) {
			$annees[] = (int) substr( $l['date_revision'], 0, 4 );
		}
	}

	return array(
		'user_id'   => $user_id,
		'lignes'    => $lignes,
		'total'     => count( $lignes ),
		'materiels' => count( $materiels ),
		'annee_min' => $annees ? min( $annees ) : 0,
		'annee_max' => $annees ? max( $annees ) : 0,
	);
}

/**
 * Libellé du matériel d'une ligne (marque + modèle + taille).
 *
 * @param array $l Ligne d'historique.
 * @return string
 */
function gacct_historique_materiel_libelle( array $l ) {
	$marque = trim( (string) $l['marque'] );

	// La marque peut être un slug de glossaire côté plateforme : on réutilise le
	// même affichage que « Mon Matériel » quand la fonction est disponible.
	if ( '' !== $marque && function_exists( 'jwcct_render_marque_libelle' ) ) {
		$libelle = jwcct_render_marque_libelle( $marque );
		if ( '' !== trim( (string) $libelle ) ) {
			$marque = $libelle;
		}
	}

	$morceaux = array_filter(
		array( $marque, trim( (string) $l['modele'] ), trim( (string) $l['taille'] ) ),
		function ( $v ) {
			return '' !== $v;
		}
	);

	return implode( ' ', $morceaux );
}

/* =============================================================================
 *  ENTRÉE DE MENU
 * ============================================================================= */

/**
 * Slug de la sous-page, pour reconnaître l'entrée de menu.
 */
function gacct_historique_slug() {
	return (string) apply_filters( 'gacct_historique_slug', 'mes-anciennes-revisions' );
}

add_filter( 'body_class', 'gacct_historique_body_class' );

/**
 * Marque le <body> quand le visiteur a des anciennes revisions.
 *
 * La liste de liens de l'espace client est un widget Elementor statique
 * (icon-list de la page 14) : elle ne passe pas par wp_nav_menu_objects, donc
 * l'entree s'afficherait pour tout le monde. Cette classe permet de la masquer
 * en CSS pour les clients sans historique, sans toucher au widget.
 *
 * @param array $classes Classes du body.
 * @return array
 */
function gacct_historique_body_class( $classes ) {
	if ( ! is_user_logged_in() || ! gacct_historique_table_exists() ) {
		return $classes;
	}

	$operateur = current_user_can( defined( 'GACCT_OP_CAP' ) ? GACCT_OP_CAP : 'gacct_operate' )
		|| current_user_can( 'manage_woocommerce' );

	if ( $operateur || gacct_historique_compte() > 0 ) {
		$classes[] = 'gacct-a-historique';
	}

	return $classes;
}

add_filter( 'wp_nav_menu_objects', 'gacct_historique_filtrer_menu', 10, 2 );

/**
 * Retire l'entrée « Mes anciennes révisions » du menu quand le client n'a
 * aucune ancienne révision.
 *
 * L'entrée reste dans le menu WordPress (elle est visible en administration),
 * elle n'est simplement pas rendue pour ces visiteurs. Les opérateurs et les
 * administrateurs la gardent, pour pouvoir vérifier la page.
 *
 * @param array $items Entrées du menu.
 * @param object $args Arguments de wp_nav_menu().
 * @return array
 */
function gacct_historique_filtrer_menu( $items, $args ) {
	if ( is_admin() ) {
		return $items;
	}

	$slug = gacct_historique_slug();
	$cle  = false;

	foreach ( $items as $i => $item ) {
		if ( false !== strpos( (string) $item->url, '/' . $slug ) ) {
			$cle = $i;
			break;
		}
	}

	if ( false === $cle ) {
		return $items;
	}

	$operateur = current_user_can( defined( 'GACCT_OP_CAP' ) ? GACCT_OP_CAP : 'gacct_operate' )
		|| current_user_can( 'manage_woocommerce' );

	if ( $operateur || ( is_user_logged_in() && gacct_historique_compte() > 0 ) ) {
		return $items;
	}

	unset( $items[ $cle ] );

	return array_values( $items );
}

/* =============================================================================
 *  SHORTCODE
 * ============================================================================= */

add_shortcode( 'gacct_historique', 'gacct_historique_shortcode' );

/**
 * Shortcode `[gacct_historique]` : la liste des anciennes révisions du client.
 *
 * @return string HTML.
 */
function gacct_historique_shortcode() {
	if ( ! is_user_logged_in() || ! gacct_historique_table_exists() ) {
		return '';
	}

	$data  = gacct_historique_page_data();
	$texts = gacct_historique_texts();

	ob_start();
	include dirname( __DIR__ ) . '/templates/historique.php';

	return (string) ob_get_clean();
}
