<?php
/**
 * Page « détail de commande » de l'espace client (/mon-compte/view-order/{id}/).
 *
 * Remplace le template WooCommerce `myaccount/view-order.php` (filtre
 * `wc_get_template`, comme la page de confirmation) : tracker d'avancement,
 * montants réels (API Kojito : acompte payé / reste à payer), lignes du devis
 * complémentaire, blocs contextuels (virement, solde, rapport…).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wc_get_template', 'gacct_vo_locate_template', 10, 2 );

function gacct_vo_locate_template( $template, $template_name ) {
	if ( 'myaccount/view-order.php' !== $template_name ) {
		return $template;
	}

	$override = dirname( __DIR__ ) . '/templates/view-order.php';

	return file_exists( $override ) ? $override : $template;
}

add_action( 'wp_enqueue_scripts', 'gacct_vo_enqueue_assets' );

function gacct_vo_enqueue_assets() {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'view-order' ) ) {
		return;
	}

	$base_url = plugins_url( '', dirname( __FILE__ ) );
	$base_dir = dirname( __DIR__ );
	$css      = $base_dir . '/assets/css/view-order.css';

	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'gacct-view-order', $base_url . '/assets/css/view-order.css', array(), (string) filemtime( $css ) );
	}
}

/**
 * Données du template view-order : tout vient des sources existantes
 * (gacct_conf_data, API Kojito, CCT revision) — aucun recalcul local.
 *
 * @param WC_Order $order
 * @return array
 */
function gacct_vo_data( $order ) {
	$conf = gacct_conf_data( $order );

	// Révision liée : état + suivi + rapport.
	$etat        = null;
	$revision    = null;
	$revision_id = (int) $order->get_meta( JWCCT_ORDER_REVISION_ID );

	if ( ! $revision_id ) {
		global $wpdb;
		$revision_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT _ID FROM {$wpdb->prefix}jet_cct_revision WHERE order_id = %d AND cct_status = 'publish' LIMIT 1",
			$order->get_id()
		) );
	}

	if ( $revision_id ) {
		$revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

		if ( is_array( $revision ) && '' !== (string) ( $revision['etat_de_la_commande'] ?? '' ) ) {
			$etat = (int) $revision['etat_de_la_commande'];
		} elseif ( is_array( $revision ) ) {
			$etat = 0;
		}
	}

	// Lignes : initiales / devis complémentaire, aux prix catalogue Kojito.
	$initial_items = array();
	$extra_items   = array();

	foreach ( $order->get_items() as $item ) {
		$row = array(
			'name'  => $item->get_name(),
			'qty'   => (int) $item->get_quantity(),
			'total' => gacct_kojito_montant_ligne( $item ),
		);

		if ( function_exists( 'gacct_quote_extra_items' ) && '' !== (string) $item->get_meta( GACCT_QUOTE_ITEM_FLAG ) ) {
			$extra_items[] = $row;
		} else {
			$initial_items[] = $row;
		}
	}

	$status_label = function_exists( 'gacct_pay_order_status_label' )
		? gacct_pay_order_status_label( $order )
		: wc_get_order_status_name( $order->get_status() );

	// Le statut custom Kojito est enregistré sans accent (« Acompte paye »).
	if ( $order->has_status( 'acompte-paye' ) ) {
		$status_label = __( 'Acompte payé', 'gestion-atelier-cct' );
	}

	$rapport_url = '';

	if ( is_array( $revision ) && ! empty( $revision['rapport_pdf'] ) ) {
		$rapport_url = (string) wp_get_attachment_url( absint( $revision['rapport_pdf'] ) );
	}

	$suivi = is_array( $revision ) ? trim( (string) ( $revision['suivi_transporteur'] ?? '' ) ) : '';

	return apply_filters( 'gacct_vo_data', array_merge( $conf, array(
		'etat'          => $etat,
		'revision_id'   => $revision_id,
		'status_label'  => $status_label,
		'initial_items' => $initial_items,
		'extra_items'   => $extra_items,
		'quote'         => function_exists( 'gacct_quote_decision' ) ? gacct_quote_decision( $order ) : array( 'decision' => '', 'decided_at' => '', 'mode' => '' ),
		'quote_sent_at' => (string) $order->get_meta( GACCT_QUOTE_META_SENT_AT ),
		'rapport_url'   => $rapport_url,
		'suivi'         => $suivi,
		'pay_url'       => $order->get_checkout_payment_url(),
		'solde_du'      => (float) $order->get_meta( '_kojito_solde_restant' ),
	) ), $order );
}

/**
 * Libellé court des états côté client (page commande).
 */
function gacct_vo_state_labels() {
	return apply_filters( 'gacct_vo_state_labels', array(
		0 => __( 'En attente de paiement', 'gestion-atelier-cct' ),
		1 => __( 'En attente de réception', 'gestion-atelier-cct' ),
		2 => __( 'Voile réceptionnée', 'gestion-atelier-cct' ),
		3 => __( 'Devis à valider', 'gestion-atelier-cct' ),
		4 => __( 'Intervention en cours', 'gestion-atelier-cct' ),
		5 => __( 'Solde à régler', 'gestion-atelier-cct' ),
		6 => __( 'Paiement validé', 'gestion-atelier-cct' ),
		7 => __( 'Révision terminée', 'gestion-atelier-cct' ),
		8 => __( 'Devis refusé', 'gestion-atelier-cct' ),
	) );
}
