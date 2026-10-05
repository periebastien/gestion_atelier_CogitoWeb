<?php
/**
 * Vente seule (05/10/2026, décision Bastien) : produits commandables SANS
 * intervention (révision, pliage), typiquement une suspente refaite.
 *
 * - Case « Vente seule » sur la fiche produit WooCommerce (meta _gacct_vente_seule).
 * - Une commande « vente seule » = au moins un produit coché, aucune intervention
 *   (produits des groupes révisions / pliages du formulaire, cumulables exclus).
 *   Un frais de port coché (ex. « Suspente ») n'est proposé qu'aux ventes seules,
 *   qui ne voient que ces ports et les retours gratuits (demande-v2.js).
 * - Elle se paie à 100 % : le filtre Kojito renvoie « pas d'acompte » pour TOUTES
 *   ses lignes, frais de port compris.
 * - Le formulaire saute le matériel et la date (date technique = jour de la
 *   demande, occupation de 0 h) ; les automatismes liés au créneau (matériel non
 *   reçu, rappel avant créneau) ne la concernent pas.
 * - Confirmation, e-mail et espace client affichent les consignes d'envoi propres
 *   (meta de commande _gacct_vente_seule).
 *
 * Inactif tant qu'aucun produit n'est coché : rien ne change pour un atelier qui
 * ne s'en sert pas.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_VENTE_SEULE_META', '_gacct_vente_seule' );

/* =============================================================================
 *  FICHE PRODUIT
 * ============================================================================= */

add_action( 'woocommerce_product_options_general_product_data', 'gacct_vente_seule_champ_produit', 20 );

function gacct_vente_seule_champ_produit() {
	echo '<div class="options_group">';
	woocommerce_wp_checkbox( array(
		'id'          => GACCT_VENTE_SEULE_META,
		'label'       => __( 'Vente seule', 'gestion-atelier-cct' ),
		'description' => __( 'Commandable sans révision ni pliage. Commandé ainsi, il se paie à 100 % (frais de port compris) et le client ne déclare ni matériel ni date. Sur un frais de port : retour proposé uniquement aux ventes seules.', 'gestion-atelier-cct' ),
	) );
	echo '</div>';
}

add_action( 'woocommerce_process_product_meta', 'gacct_vente_seule_sauver_produit' );

function gacct_vente_seule_sauver_produit( $post_id ) {
	update_post_meta( $post_id, GACCT_VENTE_SEULE_META, isset( $_POST[ GACCT_VENTE_SEULE_META ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification -- nonce WooCommerce.
}

/**
 * Le produit est-il coché « Vente seule » ?
 */
function gacct_vente_seule_produit( $product_id ) {
	return 'yes' === get_post_meta( absint( $product_id ), GACCT_VENTE_SEULE_META, true );
}

/* =============================================================================
 *  RÈGLE
 * ============================================================================= */

/**
 * Catégories « intervention » : celles des queries révisions et pliages du
 * formulaire (gacct_demande_queries_map). Filtrable.
 *
 * @return int[] term_id product_cat
 */
function gacct_vente_seule_termes_intervention() {
	static $terms = null;

	if ( null !== $terms ) {
		return $terms;
	}

	$terms = array();

	if ( function_exists( 'gacct_demande_queries_map' ) && class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) {
		$map     = gacct_demande_queries_map();
		$manager = \Jet_Engine\Query_Builder\Manager::instance();

		foreach ( array( 'revisions_controle', 'pliages_secours' ) as $champ ) {
			$query = empty( $map[ $champ ] ) ? null : $manager->get_query_by_id( $map[ $champ ] );
			$tax   = $query ? ( $query->query['tax_query'] ?? array() ) : array();

			foreach ( (array) $tax as $clause ) {
				if ( is_array( $clause ) && 'product_cat' === ( $clause['taxonomy'] ?? '' ) && 'term_id' === ( $clause['field'] ?? 'term_id' ) ) {
					foreach ( wp_parse_id_list( $clause['terms'] ?? array() ) as $term_id ) {
						$terms[] = $term_id;
					}
				}
			}
		}
	}

	$terms = array_values( array_unique( array_map( 'absint', (array) apply_filters( 'gacct_vente_seule_termes_intervention', $terms ) ) ) );

	return $terms;
}

/**
 * Le produit est-il une intervention (révision, pliage) ? Les produits
 * cumulables (ex. montage sur sellette) n'en sont pas.
 */
function gacct_vente_seule_est_intervention( $product_id ) {
	$product_id = absint( $product_id );
	$cumulables = array_map( 'absint', (array) apply_filters( 'gacct_demande_cumulables_ids', array() ) );

	if ( in_array( $product_id, $cumulables, true ) ) {
		return false;
	}

	$terms = gacct_vente_seule_termes_intervention();

	return $terms && has_term( $terms, 'product_cat', $product_id );
}

/**
 * Une liste de produits forme-t-elle une vente seule ? Au moins un produit
 * coché « Vente seule », aucune intervention.
 *
 * @param int[] $product_ids
 */
function gacct_vente_seule_ids( array $product_ids ) {
	$coche = false;

	foreach ( array_unique( array_map( 'absint', $product_ids ) ) as $product_id ) {
		if ( ! $product_id ) {
			continue;
		}
		if ( gacct_vente_seule_est_intervention( $product_id ) ) {
			return false;
		}
		if ( gacct_vente_seule_produit( $product_id ) ) {
			$coche = true;
		}
	}

	return $coche;
}

/**
 * Produits cochés d'une soumission du formulaire de demande.
 *
 * @return int[]
 */
function gacct_vente_seule_ids_requete( $request ) {
	$ids = array();

	foreach ( array( 'revisions_controle', 'pliages_secours', 'suspentes_travaux' ) as $champ ) {
		$valeur = $request[ $champ ] ?? array();
		if ( is_string( $valeur ) ) {
			$valeur = '' === trim( $valeur ) ? array() : array( $valeur );
		}
		$ids = array_merge( $ids, wp_parse_id_list( (array) $valeur ) );
	}

	return $ids;
}

/**
 * Le panier courant est-il une vente seule ?
 */
function gacct_vente_seule_panier() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return false;
	}

	$ids = array();
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$ids[] = (int) $cart_item['product_id'];
	}

	return gacct_vente_seule_ids( $ids );
}

/**
 * La commande est-elle une vente seule ? (meta posée à la création)
 */
function gacct_vente_seule_commande( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = $order ? wc_get_order( $order ) : false;
	}

	return $order instanceof WC_Order && '1' === (string) $order->get_meta( GACCT_VENTE_SEULE_META );
}

/* =============================================================================
 *  PAIEMENT À 100 %
 * ============================================================================= */

/**
 * Vente seule : aucune ligne n'a d'acompte, tout se paie à la commande.
 * Priorité 5 : un module qui annule l'acompte (clubs, priorité 10) garde la main.
 */
add_filter( 'kojito_montant_acompte', 'gacct_vente_seule_acompte', 5, 3 );

function gacct_vente_seule_acompte( $acompte, $product_id, $parent_id ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $acompte;
	}

	return gacct_vente_seule_panier() ? null : $acompte;
}

add_action( 'woocommerce_checkout_create_order', 'gacct_vente_seule_marquer_commande', 10, 2 );

function gacct_vente_seule_marquer_commande( $order, $data ) {
	if ( gacct_vente_seule_panier() ) {
		$order->update_meta_data( GACCT_VENTE_SEULE_META, '1' );
	}
}

/* =============================================================================
 *  AUTOMATISMES LIÉS AU CRÉNEAU
 * ============================================================================= */

/**
 * Pas de créneau réel : ni « matériel non reçu le matin du créneau », ni rappel
 * avant le créneau.
 */
add_filter( 'gacct_order_skip_automation', 'gacct_vente_seule_sans_creneau', 10, 3 );

function gacct_vente_seule_sans_creneau( $skip, $order, $context ) {
	if ( in_array( $context, array( 'noshow', 'preslot' ), true ) && gacct_vente_seule_commande( $order ) ) {
		return true;
	}

	return $skip;
}

/* =============================================================================
 *  CONFIRMATION ET ESPACE CLIENT
 * ============================================================================= */

/**
 * Données de la confirmation : pas de créneau ni de date limite de colis, lien
 * vers les consignes d'envoi d'une suspente (templates/thankyou-vente-seule.php).
 */
add_filter( 'gacct_conf_data', 'gacct_vente_seule_conf_data', 10, 2 );

function gacct_vente_seule_conf_data( $data, $order ) {
	if ( ! gacct_vente_seule_commande( $order ) ) {
		return $data;
	}

	$data['vente_seule']  = true;
	$data['slot_ts']      = 0;
	$data['slot_label']   = '';
	$data['parcel_label'] = '';

	$guide = gacct_vente_seule_guide_url();
	if ( '' !== $guide ) {
		$data['links']['packing_guide'] = $guide;
	}

	return $data;
}

/**
 * Formulaire de suivi colis : pas de créneau à garder pour une vente seule.
 */
add_filter( 'gacct_ship_depot_hint', 'gacct_vente_seule_depot_hint', 10, 2 );

function gacct_vente_seule_depot_hint( $hint, $order ) {
	return gacct_vente_seule_commande( $order )
		? __( 'Vous déposez votre suspente vous-même à la boutique ? Choisissez « Dépôt à la boutique » et indiquez la date.', 'gestion-atelier-cct' )
		: $hint;
}

/* =============================================================================
 *  E-MAIL « PAIEMENT REÇU »
 * ============================================================================= */

/**
 * Modèle éditable (Configuration > Paiements & relances) : paiement complet,
 * consignes d'envoi d'une suspente, sans créneau ni bon d'intervention.
 */
add_filter( 'gacct_pay_default_settings', 'gacct_vente_seule_email_defaut' );

function gacct_vente_seule_email_defaut( $defaults ) {
	$defaults['emails']['vente_seule_received'] = array(
		'enabled'    => true,
		'copy_admin' => false,
		'label'      => __( 'Paiement reçu, commande sans intervention (suspente seule) : consignes d’envoi', 'gestion-atelier-cct' ),
		'subject'    => __( 'Paiement reçu ! Voici comment nous envoyer votre suspente - commande {order_number}', 'gestion-atelier-cct' ),
		'body'       => '<p>Bonjour {customer_name},</p>'
			. '<p>Nous avons bien reçu votre paiement pour la commande <strong>{order_number}</strong> : elle est réglée en totalité. Merci de votre confiance !</p>'
			. '<p>Il ne reste plus qu’à nous envoyer votre suspente.</p>'
			. '<p><strong>Comment nous l’envoyer</strong></p>'
			. '<ol>'
			. '<li>Détachez la suspente abîmée, ou sa symétrique si elle est coupée (la même suspente de l’autre côté de la voile).</li>'
			. '<li>Glissez-la dans une enveloppe avec un papier portant votre référence <strong>{order_number}</strong>.</li>'
			. '<li>Envoyez-la à l’adresse suivante (courrier ou lettre suivie) :<br><strong>{workshop_address}</strong></li>'
			. '</ol>'
			. '<p>Si vous l’envoyez avec un suivi, indiquez-nous le numéro depuis <a href="{shipping_url}">votre espace client</a>. Nous refaisons votre suspente dès sa réception.</p>'
			. '<p>Une question ? Appelez-nous au <strong>{contact_phone}</strong> ({contact_hours}).</p>'
			. '<p>À très vite,<br><br>' . ( function_exists( 'gacct_team_signature' ) ? gacct_team_signature() : '' ) . '</p>',
	);

	return $defaults;
}

add_filter( 'gacct_pay_deposit_received_template', 'gacct_vente_seule_email_modele', 10, 2 );

function gacct_vente_seule_email_modele( $template, $order ) {
	return gacct_vente_seule_commande( $order ) ? 'vente_seule_received' : $template;
}

/* =============================================================================
 *  FORMULAIRE
 * ============================================================================= */

/**
 * Produits « Vente seule » transmis au JS du formulaire (miroir de la règle).
 *
 * @return int[]
 */
function gacct_vente_seule_ids_formulaire() {
	$ids = get_posts( array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => GACCT_VENTE_SEULE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
	) );

	return array_map( 'absint', $ids );
}

/**
 * URL des consignes d'envoi d'une vente seule (lien « Comment nous envoyer… »
 * de la confirmation). Filtrable par le pack de l'atelier.
 */
function gacct_vente_seule_guide_url() {
	return (string) apply_filters( 'gacct_vente_seule_guide_url', '' );
}

/**
 * Textes de la vente seule (consignes d'envoi), filtrables.
 *
 * @return array<string,string>
 */
function gacct_vente_seule_textes() {
	return apply_filters( 'gacct_vente_seule_textes', array(
		'etape1'     => __( 'Détachez la suspente abîmée, ou sa symétrique si elle est coupée (la même suspente de l’autre côté de la voile).', 'gestion-atelier-cct' ),
		'etape2'     => __( 'Glissez-la dans une enveloppe avec un papier portant votre référence %s.', 'gestion-atelier-cct' ),
		'etape3'     => __( 'Envoyez-la à l’adresse de l’atelier (courrier ou lettre suivie)', 'gestion-atelier-cct' ),
		'etape4'     => __( 'Renseignez votre numéro de suivi ci-dessous si vous en avez un.', 'gestion-atelier-cct' ),
		'etape1_t'   => __( 'La bonne suspente', 'gestion-atelier-cct' ),
		'etape2_t'   => __( 'Dans une enveloppe', 'gestion-atelier-cct' ),
		'etape3_t'   => __( 'À l’adresse de l’atelier', 'gestion-atelier-cct' ),
		'etape4_t'   => __( 'Le suivi, si vous en avez un', 'gestion-atelier-cct' ),
		'guide'      => __( 'Comment nous envoyer une suspente', 'gestion-atelier-cct' ),
		'guide_desc' => __( 'Quelle suspente envoyer, comment l’emballer', 'gestion-atelier-cct' ),
		// Carte « Expédition » de l'espace client.
		'carte_titre' => __( 'Suspente à nous envoyer', 'gestion-atelier-cct' ),
		'carte_texte' => __( 'Votre commande est réglée : envoyez-nous la suspente, nous la refaisons dès sa réception.', 'gestion-atelier-cct' ),
	) );
}
