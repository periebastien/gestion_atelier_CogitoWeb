<?php
/**
 * Paiement du solde côté client (recette du 09/09/2026).
 *
 * - Remplace le gabarit WooCommerce `checkout/form-pay.php` (page
 *   /commander/order-pay/{id}/) par templates/order-pay.php, habillé comme le
 *   détail de commande (assets/css/view-order.css) : lignes initiales, travaux
 *   du devis, remises, acompte réglé, solde à régler, moyens de paiement.
 * - Garde : dès que le solde est réglé ou forcé (phase Kojito `solde_paye`,
 *   état de révision >= 7) ou qu'un virement de solde est déjà annoncé, le
 *   lien n'est plus payable et renvoie sur le détail de commande avec un
 *   message (« Ce solde est déjà réglé »). Le flux ACOMPTE (nouvelle tentative
 *   de paiement après échec) n'est pas concerné.
 * - Solde par virement : au passage « En attente » de la commande en phase
 *   solde, e-mail `balance_bank_details` (RIB, référence, montant exact) et
 *   meta `_gacct_balance_bacs_pending` ; la page de confirmation affiche les
 *   coordonnées bancaires au lieu du bandeau « matériel bien arrivé ».
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_BAL_META_BACS_PENDING', '_gacct_balance_bacs_pending' );
define( 'GACCT_BAL_META_BACS_SENT', '_gacct_balance_bacs_sent' );

/**
 * La commande est-elle en phase de solde (Kojito) ?
 *
 * @param WC_Order $order
 * @return string '' | 'solde' | 'solde_paye'
 */
function gacct_bal_phase( $order ) {
	$phase = (string) $order->get_meta( '_kojito_phase_paiement' );

	return in_array( $phase, array( 'solde', 'solde_paye' ), true ) ? $phase : '';
}

/**
 * État de la révision liée (SQL direct, pas de cache).
 *
 * @param WC_Order $order
 * @return int|null
 */
function gacct_bal_revision_state( $order ) {
	global $wpdb;

	$revision_id = (int) $order->get_meta( JWCCT_ORDER_REVISION_ID );
	$where       = $revision_id ? $wpdb->prepare( '_ID = %d', $revision_id ) : $wpdb->prepare( 'order_id = %d', $order->get_id() );
	$etat        = $wpdb->get_var( "SELECT etat_de_la_commande FROM {$wpdb->prefix}jet_cct_revision WHERE {$where} AND cct_status = 'publish' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return null === $etat ? null : (int) $etat;
}

/**
 * Pourquoi le solde n'est-il plus payable ? '' si tout est en ordre.
 *
 * @param WC_Order $order
 * @return string '' | 'paid' | 'bacs_pending' | 'not_due'
 */
function gacct_bal_block_reason( $order ) {
	$phase = gacct_bal_phase( $order );

	if ( 'solde_paye' === $phase ) {
		return 'paid';
	}

	if ( 'solde' !== $phase ) {
		return ''; // Flux acompte : hors périmètre.
	}

	$etat = gacct_bal_revision_state( $order );

	if ( null !== $etat && $etat >= 7 ) {
		return 'paid'; // Solde forcé par l'atelier ou dossier déjà clos.
	}

	if ( $order->get_meta( GACCT_BAL_META_BACS_PENDING ) && $order->has_status( 'on-hold' ) ) {
		return 'bacs_pending';
	}

	if ( null !== $etat && $etat < 6 ) {
		return 'not_due';
	}

	return '';
}

/* -----------------------------------------------------------------------------
 *  Gabarit de la page de paiement
 * -------------------------------------------------------------------------- */

add_filter( 'wc_get_template', 'gacct_bal_locate_template', 10, 2 );

function gacct_bal_locate_template( $template, $template_name ) {
	if ( 'checkout/form-pay.php' !== $template_name ) {
		return $template;
	}

	$order_id = absint( get_query_var( 'order-pay' ) );
	$order    = $order_id ? wc_get_order( $order_id ) : false;

	if ( ! $order || 'solde' !== gacct_bal_phase( $order ) ) {
		return $template; // Nouvelle tentative de paiement de l'acompte : gabarit natif.
	}

	$override = dirname( __DIR__ ) . '/templates/order-pay.php';

	return file_exists( $override ) ? $override : $template;
}

add_action( 'wp_enqueue_scripts', 'gacct_bal_enqueue_assets' );

function gacct_bal_enqueue_assets() {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}

	$base_url = plugins_url( '', dirname( __FILE__ ) );
	$base_dir = dirname( __DIR__ );
	$css      = $base_dir . '/assets/css/view-order.css';

	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'gacct-view-order', $base_url . '/assets/css/view-order.css', array(), (string) filemtime( $css ) );
	}

	$inline = '
.gacct-vo.gacct-pay{max-width:760px;margin:24px auto 48px;padding:0 16px}
.gacct-pay .gacct-vo-pay-methods{list-style:none;margin:0 0 16px;padding:0}
.gacct-pay .gacct-vo-pay-methods li{border:1px solid #e3e3e3;border-radius:10px;margin:0 0 10px;padding:12px 14px;background:#fff}
.gacct-pay .gacct-vo-pay-methods li label{font-weight:700;cursor:pointer}
.gacct-pay .gacct-vo-pay-methods .payment_box{margin:8px 0 0;font-size:.92em;color:#555}
.gacct-pay .gacct-vo-pay-methods input[type=radio]{margin-right:8px}
.gacct-pay #place_order{display:block;width:100%;border:0;border-radius:999px;padding:14px 22px;font-weight:800;font-size:1.05em;cursor:pointer;background:#f2b134;color:#1a1a1a}
.gacct-pay .woocommerce-terms-and-conditions-wrapper{margin:12px 0 16px;font-size:.95em}
.gacct-pay .gacct-vo-balance-due td{font-size:1.15em;font-weight:800;color:#1a7f86}
.gacct-pay .gacct-vo-secure{font-size:.85em;color:#777;text-align:center;margin:10px 0 0}
';
	wp_add_inline_style( 'gacct-view-order', $inline );
}

/* -----------------------------------------------------------------------------
 *  Garde : lien de solde déjà réglé / déjà annoncé
 * -------------------------------------------------------------------------- */

add_action( 'template_redirect', 'gacct_bal_guard_order_pay', 5 );

function gacct_bal_guard_order_pay() {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}

	$order_id = absint( get_query_var( 'order-pay' ) );
	$order    = $order_id ? wc_get_order( $order_id ) : false;

	if ( ! $order ) {
		return;
	}

	$reason = gacct_bal_block_reason( $order );

	if ( '' === $reason ) {
		return;
	}

	$target = is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id()
		? $order->get_view_order_url()
		: $order->get_checkout_order_received_url();

	wp_safe_redirect( add_query_arg( 'gacct_solde', $reason, $target ) );
	exit;
}

/**
 * Message affiché sur le détail de commande après redirection.
 *
 * @return array{tone:string,title:string,text:string}|null
 */
function gacct_bal_notice() {
	$key = isset( $_GET['gacct_solde'] ) ? sanitize_key( wp_unslash( $_GET['gacct_solde'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$notices = array(
		'paid'         => array(
			'tone'  => 'is-neutral',
			'title' => __( 'Ce solde est déjà réglé', 'gestion-atelier-cct' ),
			'text'  => __( 'Rien à payer : votre règlement a bien été enregistré. Si vous pensez qu’il s’agit d’une erreur, appelez-nous.', 'gestion-atelier-cct' ),
		),
		'bacs_pending' => array(
			'tone'  => 'is-neutral',
			'title' => __( 'Votre virement de solde est annoncé', 'gestion-atelier-cct' ),
			'text'  => __( 'Nous attendons sa réception ; les coordonnées bancaires vous ont été envoyées par e-mail et figurent ci-dessous. Dès l’encaissement, votre matériel repart.', 'gestion-atelier-cct' ),
		),
		'not_due'      => array(
			'tone'  => 'is-neutral',
			'title' => __( 'Le solde n’est pas encore à régler', 'gestion-atelier-cct' ),
			'text'  => __( 'Nous vous demanderons le solde une fois l’intervention terminée, par e-mail et ici même.', 'gestion-atelier-cct' ),
		),
	);

	return isset( $notices[ $key ] ) ? $notices[ $key ] : null;
}

/* -----------------------------------------------------------------------------
 *  Solde par virement : e-mail avec les coordonnées bancaires
 * -------------------------------------------------------------------------- */

add_action( 'woocommerce_order_status_changed', 'gacct_bal_maybe_send_bacs_details', 30, 4 );

function gacct_bal_maybe_send_bacs_details( $order_id, $from, $to, $order ) {
	if ( 'on-hold' !== $to || ! $order instanceof WC_Order ) {
		return;
	}

	if ( 'bacs' !== $order->get_payment_method() || 'solde' !== gacct_bal_phase( $order ) ) {
		return;
	}

	$order->update_meta_data( GACCT_BAL_META_BACS_PENDING, current_time( 'mysql' ) );

	if ( $order->get_meta( GACCT_BAL_META_BACS_SENT ) ) {
		$order->save_meta_data();
		return;
	}

	$sent = function_exists( 'gacct_pay_send_email' )
		? gacct_pay_send_email( $order->get_billing_email(), 'balance_bank_details', gacct_pay_email_variables( $order ), true )
		: false;

	if ( $sent ) {
		$order->update_meta_data( GACCT_BAL_META_BACS_SENT, current_time( 'mysql' ) );
	}

	$order->save_meta_data();
	$order->add_order_note(
		$sent
			? __( 'Solde par virement : e-mail « coordonnées bancaires pour le solde » envoyé au client (copie admin). Le dossier attend l’encaissement : bouton « Forcer le paiement du solde » de la console une fois le virement reçu.', 'gestion-atelier-cct' )
			: __( 'Solde par virement : ÉCHEC de l’envoi de l’e-mail « coordonnées bancaires pour le solde ».', 'gestion-atelier-cct' )
	);
}

/**
 * Variables propres au solde ({balance_amount}) pour tous les modèles.
 */
add_filter( 'gacct_pay_email_variables', 'gacct_bal_email_variables', 10, 2 );

function gacct_bal_email_variables( $variables, $order ) {
	if ( $order instanceof WC_Order && ! isset( $variables['{balance_amount}'] ) ) {
		$solde = (float) $order->get_meta( '_kojito_solde_restant' );
		$variables['{balance_amount}'] = wp_strip_all_tags( wc_price( $solde > 0 ? $solde : (float) $order->get_total() ) );
	}

	return $variables;
}
