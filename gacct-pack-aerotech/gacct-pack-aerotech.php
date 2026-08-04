<?php
/**
 * Plugin Name: Pack AEROTECH — configuration site
 * Description: Carte d'IDs du site fly-aerotech.cogitoweb.net pour le framework Gestion Atelier (relations, glossaires, queries, produit devis). Aucune logique métier : uniquement le branchement des identifiants propres à ce site.
 * Version: 1.0.0
 * Author: CogitoWeb
 * Requires Plugins: gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * =====================================================================
 * CARTE D'IDS DU SITE AEROTECH (provisioning du 02/08/2026)
 * =====================================================================
 * Les entités JetEngine (relations, glossaires, queries, tables) ont été
 * recréées avec les MÊMES ids que le site de référence revision.cogitoweb.net
 * (table lDHOLS_jet_post_types vide au moment du provisioning). Les filtres
 * ci-dessous rendent ce branchement EXPLICITE : si une entité doit un jour
 * être recréée avec un autre id, c'est ICI (et uniquement ici) qu'on le change.
 *
 * Seuls les ids WooCommerce (catégories, produits) diffèrent du site référence.
 *
 * RAPPORTS DE CONTRÔLE — CHOIX ASSUMÉ (04/08/2026) : ce pack n'enregistre
 * AUCUN pack de rapports (pas de hook `gacct_report_register_packs`), et
 * c'est voulu — AEROTECH produit ses rapports hors plateforme et les dépose
 * en PDF depuis la fiche console (upload manuel, coffre sécurisé, envoi au
 * client au solde réglé : circuit identique). Conséquences automatiques côté
 * framework : carte console en mode « dépôt de PDF » (formulaire ouvert
 * d'office), onglet Configuration > Rapports masqué, endpoints de génération
 * inertes (« Modèle de rapport inconnu »), assets des formulaires non chargés.
 * NE PAS « corriger » en recopiant le pack Altitude Révision.
 */

/** Relations JetEngine (lDHOLS_jet_post_types, status=relation). */
const GACCT_AERO_RELATIONS = array(
	'revision_to_occupation' => 11, // revision (parent) <-> occupation_atelier (child), 1-1
	'revision_to_order'      => 12, // revision (parent) <-> commande WooCommerce (child), 1-1
	'client_to_revision'     => 13, // utilisateur (parent) <-> revision (child), 1-n
);

/** Glossaires JetEngine (status=glossary). */
const GACCT_AERO_GLOSSARIES = array(
	'marques' => 2,  // « Marque » — 32 marques de parapente
	'etats'   => 18, // « État de la commande » — libellés 0-8
);

/** Queries Query Builder alimentant le formulaire « Demande d'intervention ». */
const GACCT_AERO_DEMANDE_QUERIES = array(
	'revisions_controle' => 3,  // produits catégorie « Révisions & Contrôle » (term 48)
	'pliages_secours'    => 9,  // produits catégorie « Pliages secours » (term 50)
	'suspentes_travaux'  => 10, // produits catégorie « Suspentes & Travaux » (term 51)
	'frais_de_ports'     => 4,  // produits catégorie « Frais de port » (term 49)
);

/** Produit « Devis réparation » (rend le devis complémentaire obligatoire). */
const GACCT_AERO_DEVIS_PRODUCT_IDS = array( 342 );

/**
 * Relations : id par clé stable du framework.
 * Signature : apply_filters( 'gacct_relation_id', $default, $relation_key ).
 */
add_filter( 'gacct_relation_id', function ( $default, $relation_key ) {
	return GACCT_AERO_RELATIONS[ $relation_key ] ?? $default;
}, 10, 2 );

/**
 * Glossaires : id par clé stable.
 * Signature : apply_filters( 'gacct_glossary_id', $default, $glossary_key ).
 */
add_filter( 'gacct_glossary_id', function ( $default, $glossary_key ) {
	return GACCT_AERO_GLOSSARIES[ $glossary_key ] ?? $default;
}, 10, 2 );

/**
 * Queries des prestations du formulaire de demande d'intervention.
 * Structure attendue : array<string,int> (cf. gacct_demande_queries_map()).
 */
add_filter( 'gacct_demande_queries', function ( $map ) {
	return GACCT_AERO_DEMANDE_QUERIES;
} );

/**
 * Produit(s) « demande de devis » : une commande qui en contient un rend
 * le devis complémentaire obligatoire (blocage du passage direct 3 -> 6).
 */
add_filter( 'gacct_quote_devis_product_ids', function ( $ids ) {
	return GACCT_AERO_DEVIS_PRODUCT_IDS;
} );

/**
 * Charte AEROTECH : surcharge des tokens --gacct-* du framework (couleurs,
 * rayons, ombres). Enqueue tardif pour passer après les feuilles du
 * framework, uniquement sur le front.
 */
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'gacct-aerotech-tokens',
		plugin_dir_url( __FILE__ ) . 'assets/css/aerotech-tokens.css',
		array(),
		'1.0.0'
	);
}, 99 );

/*
 * =====================================================================
 * REMPLACEMENT DU WIDGET jet-myaccount-content (JetWooBuilder absent)
 * =====================================================================
 * Sur le site de référence, la page « Mon compte » (289 ici) affiche le
 * détail d'une commande via le widget Elementor `jet-myaccount-content`
 * de JetWooBuilder. AEROTECH n'embarque pas JetWooBuilder : le widget
 * était silencieusement ignoré et /mon-compte/view-order/{id}/ rendait
 * une page vide (constat recette du 02/08/2026).
 *
 * Le shortcode ci-dessous rend la même chose que le widget : le contenu
 * du endpoint Mon compte courant (dont notre template view-order.php via
 * l'override wc_get_template de gacct-vieworder.php). Il est posé dans la
 * page 289 à la place du widget jet-myaccount-content, avec la même
 * condition de visibilité (%gacct_dash_is_order_detail%).
 */
add_shortcode( 'gacct_aero_myaccount_content', function () {
	if ( ! function_exists( 'WC' ) ) {
		return '';
	}

	ob_start();
	echo '<div class="woocommerce">';
	do_action( 'woocommerce_account_content' );
	echo '</div>';

	return ob_get_clean();
} );
