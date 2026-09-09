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
 * Ce produit est-il l'un des deux produits « supplément biplace » ?
 *
 * À ne pas confondre avec gacct_product_biplace_supplement(), qui dit quel
 * supplément s'applique à une PRESTATION.
 */
function gacct_biplace_est_produit_supplement( $product_id ) {
	$ids = array_map( 'absint', array_values( gacct_biplace_supplement_product_ids() ) );

	return in_array( absint( $product_id ), $ids, true );
}

/**
 * Masque la ligne « Supplément biplace » tant qu'elle est facturée 0 €.
 *
 * Le supplément porte un acompte nul (décision du 27/08/2026) : au moment de
 * l'acompte, sa ligne s'affiche donc à 0,00 €, ce que le client lit comme un
 * bug (remontée Timothée du 18/08/2026). On la cache tant qu'elle ne coûte
 * rien. Aucune valeur n'est perdue : la ligne reste en base, visible en
 * administration, et réapparaît côté client dès que le solde lui rend son
 * montant réel.
 *
 * @param bool          $visible
 * @param WC_Order_Item $item
 * @return bool
 */
function gacct_biplace_ligne_commande_visible( $visible, $item ) {
	if ( ! $visible || is_admin() ) {
		return $visible;
	}

	if ( ! $item instanceof WC_Order_Item_Product ) {
		return $visible;
	}

	if ( ! gacct_biplace_est_produit_supplement( $item->get_product_id() ) ) {
		return $visible;
	}

	return abs( (float) $item->get_total() ) >= 0.005;
}
add_filter( 'woocommerce_order_item_visible', 'gacct_biplace_ligne_commande_visible', 10, 2 );

/**
 * Même règle dans le panier et le mini-panier, où le supplément est déjà
 * ramené à son acompte (donc à 0) par le plugin Kojito.
 *
 * @param bool  $visible
 * @param array $cart_item
 * @return bool
 */
function gacct_biplace_ligne_panier_visible( $visible, $cart_item ) {
	if ( ! $visible || empty( $cart_item['product_id'] ) ) {
		return $visible;
	}

	$id = ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'];

	if ( ! gacct_biplace_est_produit_supplement( $id ) ) {
		return $visible;
	}

	$data = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

	return $data ? (float) $data->get_price() >= 0.005 : $visible;
}
/* Panier et mini-panier : plus de masquage. Depuis le 27/08 le récapitulatif
   affiche les prix réels et non les acomptes, donc le supplément y apparaît
   à son vrai montant. Le filtre reste posé sur les lignes de COMMANDE, dont
   l'affichage n'a pas encore reçu le même traitement. */
/* =============================================================================
 *  Matériel concerné par une prestation (09/09/2026, décision Bastien)
 *  Meta `_gacct_materiels` : liste parmi voile / secours / sellette. Remplace
 *  l'identifiant de produit en dur (19) : chaque atelier coche, sur sa fiche
 *  produit, ce que le client doit décrire et expédier. Sans coche, un défaut
 *  est déduit de la famille de la prestation (gacct_produit_materiels()).
 * ============================================================================= */

/**
 * Types de matériel connus, dans l'ordre des sous-écrans du formulaire.
 *
 * @return array<string,string> slug => libellé.
 */
function gacct_materiel_types() {
	return apply_filters( 'gacct_materiel_types', array(
		'voile'    => __( 'Parapente', 'gestion-atelier-cct' ),
		'secours'  => __( 'Parachute de secours', 'gestion-atelier-cct' ),
		'sellette' => __( 'Sellette', 'gestion-atelier-cct' ),
	) );
}

/**
 * Matériel concerné par un produit. Meta explicite, sinon défaut par famille :
 * révisions / contrôles et travaux de suspentes = parapente, pliages = secours.
 *
 * @param int    $product_id Produit.
 * @param string $champ      Champ du formulaire d'où vient le produit
 *                           (revisions_controle | pliages_secours | suspentes_travaux), pour le défaut.
 * @return string[] Sous-ensemble ordonné de array_keys( gacct_materiel_types() ).
 */
function gacct_produit_materiels( $product_id, $champ = '' ) {
	$types = array_keys( gacct_materiel_types() );
	$meta  = get_post_meta( (int) $product_id, '_gacct_materiels', true );
	$meta  = is_array( $meta ) ? array_values( array_intersect( $types, $meta ) ) : array();

	if ( ! $meta ) {
		$meta = array( 'pliages_secours' === $champ ? 'secours' : 'voile' );
	}

	return (array) apply_filters( 'gacct_produit_materiels', $meta, (int) $product_id, $champ );
}

/**
 * Cases à cocher « Matériel concerné » dans l'onglet Général de la fiche produit.
 */
add_action( 'woocommerce_product_options_general_product_data', function () {
	global $post;

	$coches = get_post_meta( $post->ID, '_gacct_materiels', true );
	$coches = is_array( $coches ) ? $coches : array();

	echo '<div class="options_group">';
	echo '<fieldset class="form-field"><legend style="float:left;width:150px;padding:0;margin:0 0 0 12px">' . esc_html__( 'Matériel concerné', 'gestion-atelier-cct' ) . '</legend><ul class="wc-radios" style="margin-left:162px">';
	foreach ( gacct_materiel_types() as $slug => $label ) {
		echo '<li><label><input type="checkbox" name="_gacct_materiels[]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $coches, true ), true, false ) . '> ' . esc_html( $label ) . '</label></li>';
	}
	echo '</ul><p class="description" style="margin-left:162px">' . esc_html__( 'Ce que le client doit décrire à la demande et expédier à l’atelier pour cette prestation. Sans coche : parapente pour une révision, un contrôle ou des suspentes ; parachute de secours pour un pliage.', 'gestion-atelier-cct' ) . '</p></fieldset>';
	echo '</div>';
}, 5 );

add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
	$types  = array_keys( gacct_materiel_types() );
	$coches = isset( $_POST['_gacct_materiels'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['_gacct_materiels'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$coches = array_values( array_intersect( $types, $coches ) );
	if ( $coches ) {
		update_post_meta( $post_id, '_gacct_materiels', $coches );
	} else {
		delete_post_meta( $post_id, '_gacct_materiels' );
	}
} );

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

/* =============================================================================
 *  Colonne « Durée » dans la liste des produits (08/09/2026)
 *  Lit la meta `duree_presta` (heures décimales, même source que le formulaire
 *  de demande et que la durée totale de l'occupation atelier). Triable.
 * ============================================================================= */

/**
 * 2.5 → « 2 h 30 », 0.25 → « 15 min », 0 ou vide → « — » (tiret court).
 */
function gacct_products_format_duree( $raw ) {
	$hours = function_exists( 'gacct_demande_parse_duree' ) ? gacct_demande_parse_duree( $raw ) : (float) str_replace( ',', '.', (string) $raw );

	if ( $hours <= 0 ) {
		return '–';
	}

	$minutes = (int) round( $hours * 60 );
	$h       = intdiv( $minutes, 60 );
	$m       = $minutes % 60;

	if ( 0 === $h ) {
		return sprintf( '%d min', $m );
	}

	return $m ? sprintf( '%d h %02d', $h, $m ) : sprintf( '%d h', $h );
}

add_filter( 'manage_edit-product_columns', function ( $columns ) {
	$out = array();

	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'price' === $key ) {
			$out['gacct_duree']     = __( 'Durée', 'gestion-atelier-cct' );
			$out['gacct_materiels'] = __( 'Matériel', 'gestion-atelier-cct' );
		}
	}

	if ( ! isset( $out['gacct_duree'] ) ) {
		$out['gacct_duree'] = __( 'Durée', 'gestion-atelier-cct' );
	}

	return $out;
}, 20 );

add_action( 'manage_product_posts_custom_column', function ( $column, $post_id ) {
	if ( 'gacct_materiels' === $column ) {
		$meta   = get_post_meta( $post_id, '_gacct_materiels', true );
		$types  = gacct_materiel_types();
		$coches = is_array( $meta ) ? array_values( array_intersect( array_keys( $types ), $meta ) ) : array();
		if ( $coches ) {
			echo esc_html( implode( ' + ', array_map( static function ( $s ) use ( $types ) { return $types[ $s ]; }, $coches ) ) );
		} else {
			echo '<span style="color:#a7aaad" title="' . esc_attr__( 'Déduit de la famille de la prestation (parapente, ou secours pour un pliage)', 'gestion-atelier-cct' ) . '">' . esc_html__( 'par défaut', 'gestion-atelier-cct' ) . '</span>';
		}
		return;
	}
	if ( 'gacct_duree' !== $column ) {
		return;
	}

	$raw = get_post_meta( $post_id, 'duree_presta', true );

	echo '<span class="gacct-duree" title="' . esc_attr( '' === $raw ? __( 'Durée non renseignée', 'gestion-atelier-cct' ) : sprintf( '%s h', $raw ) ) . '">' . esc_html( gacct_products_format_duree( $raw ) ) . '</span>';
}, 10, 2 );

add_filter( 'manage_edit-product_sortable_columns', function ( $columns ) {
	$columns['gacct_duree'] = 'gacct_duree';
	return $columns;
} );

add_action( 'pre_get_posts', function ( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() || 'gacct_duree' !== $query->get( 'orderby' ) ) {
		return;
	}

	// Produits sans durée (frais de port, suppléments) classés comme 0.
	$query->set( 'meta_query', array(
		'relation' => 'OR',
		array( 'key' => 'duree_presta', 'compare' => 'EXISTS' ),
		array( 'key' => 'duree_presta', 'compare' => 'NOT EXISTS' ),
	) );
	$query->set( 'orderby', 'meta_value_num' );
} );

add_action( 'admin_head-edit.php', function () {
	if ( 'product' === get_current_screen()->post_type ) {
		echo '<style>.column-gacct_duree{width:7%}.gacct-duree{white-space:nowrap}</style>';
	}
} );
