<?php
/**
 * Produits WooCommerce — champ « Supplément biplace » (09/08/2026).
 *
 * Les suppléments biplace sont des produits SÉPARÉS rangés dans une catégorie
 * masquée (non requêtée par le formulaire de demande). Chaque prestation porte
 * une meta `_gacct_supplement_biplace` ('' | 'voile' | 'secours') qui indique
 * quel supplément lui est applicable : le futur formulaire multi-étapes s'en
 * sert pour afficher la bascule Solo/Biplace et ajouter le bon supplément au
 * panier (un supplément PAR prestation concernée — décision du 09/08/2026).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Choix possibles du champ (clé meta => libellé).
 */
function gacct_biplace_options() {
	return array(
		''        => __( 'Aucun', 'gestion-atelier-cct' ),
		'voile'   => __( 'Supplément biplace Voile', 'gestion-atelier-cct' ),
		'secours' => __( 'Supplément biplace Parachute de secours', 'gestion-atelier-cct' ),
	);
}

/**
 * Supplément applicable à un produit ('' si aucun).
 */
function gacct_product_biplace_supplement( $product_id ) {
	$v = get_post_meta( $product_id, '_gacct_supplement_biplace', true );
	return in_array( $v, array( 'voile', 'secours' ), true ) ? $v : '';
}

/**
 * IDs des produits supplément, par type. Filtrable par site (white-label).
 */
function gacct_biplace_supplement_product_ids() {
	return apply_filters(
		'gacct_biplace_supplement_products',
		array(
			'voile'   => (int) get_option( 'gacct_supplement_voile_id', 689 ),
			'secours' => (int) get_option( 'gacct_supplement_secours_id', 1238 ),
		)
	);
}

/**
 * Boutons radio dans l'onglet Général de la fiche produit.
 */
add_action( 'woocommerce_product_options_general_product_data', function () {
	global $post;

	echo '<div class="options_group">';

	// woocommerce_wp_radio() rend le composant NATIF (fieldset + legend +
	// ul.wc-radios). Les labels bricolés a la main se chevauchaient : dans le
	// panneau produit, `.form-field label` est en float:left; width:150px, et
	// des styles inline ne suffisent pas a remettre trois labels en ligne.
	woocommerce_wp_radio(
		array(
			'id'          => '_gacct_supplement_biplace',
			'name'        => '_gacct_supplement_biplace',
			'label'       => __( 'Supplément biplace', 'gestion-atelier-cct' ),
			'options'     => gacct_biplace_options(),
			'value'       => gacct_product_biplace_supplement( $post->ID ),
			'description' => __( 'Si un supplément est choisi, le formulaire de demande proposera « Solo / Biplace » pour cette prestation et ajoutera automatiquement le produit supplément correspondant au panier.', 'gestion-atelier-cct' ),
		)
	);

	echo '</div>';
} );

/**
 * Enregistrement à la sauvegarde du produit.
 */
add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
	$v = isset( $_POST['_gacct_supplement_biplace'] )
		? sanitize_key( wp_unslash( $_POST['_gacct_supplement_biplace'] ) )
		: '';
	if ( ! in_array( $v, array( 'voile', 'secours' ), true ) ) {
		$v = '';
	}
	update_post_meta( $post_id, '_gacct_supplement_biplace', $v );
} );
