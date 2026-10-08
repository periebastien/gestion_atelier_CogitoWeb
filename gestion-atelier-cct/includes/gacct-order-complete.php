<?php
/**
 * Commande « Terminée » dès que le solde est réglé, et e-mail « Commande
 * terminée » réécrit en envoi de facture.
 *
 * Décision de Bastien du 08/10/2026 (audit avant mise en production) : le
 * plugin PDF Invoices ne crée la facture qu'au statut Terminée et la joint à
 * l'e-mail WooCommerce « Commande terminée ». Or un solde payé par carte
 * laissait la commande « En cours », et un dossier sans solde restait
 * « Acompte payé » : aucune facture ne partait.
 *
 * - Le passage en Terminée attend que le solde soit réellement marqué payé
 *   (Kojito, phase solde_paye) ou qu'il n'y ait rien à payer : avec CAWL, le
 *   retour navigateur pose « En cours » AVANT le webhook qui confirme le
 *   paiement (Kojito enregistre le solde à ce moment-là).
 * - Le solde forcé depuis la console passe déjà par
 *   gacct_op_mark_balance_paid() (date de réception).
 * - Filtre `gacct_complete_order_when_paid` : un module peut s'y opposer.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Dossier (CCT revision) d'une commande, 0 sinon. */
function gacct_oc_revision_id( WC_Order $order ) {
	global $wpdb;
	$id = defined( 'JWCCT_ORDER_REVISION_ID' ) ? absint( $order->get_meta( JWCCT_ORDER_REVISION_ID ) ) : 0;
	if ( ! $id ) {
		$id = absint( $wpdb->get_var( $wpdb->prepare(
			"SELECT _ID FROM {$wpdb->prefix}jet_cct_revision WHERE order_id = %d AND cct_status = 'publish' LIMIT 1",
			$order->get_id()
		) ) );
	}
	return $id;
}

/**
 * Passe la commande en Terminée si son dossier est soldé (état 7 ou 8) et que
 * plus rien n'est dû.
 */
function gacct_oc_maybe_complete( $order ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

	if ( ! $order instanceof WC_Order || $order->has_status( array( 'completed', 'cancelled', 'refunded', 'failed', 'pending', 'on-hold', 'checkout-draft' ) ) ) {
		return false;
	}

	$revision_id = gacct_oc_revision_id( $order );
	$state       = $revision_id && function_exists( 'gacct_op_read_state' ) ? (int) gacct_op_read_state( $revision_id ) : -1;

	if ( $state < 7 || $state > 8 ) {
		return false;
	}

	$phase   = (string) $order->get_meta( '_kojito_phase_paiement' );
	$restant = $order->get_meta( '_kojito_solde_restant' );
	$solde   = 'solde_paye' === $phase || ( 'acompte' === $phase && '' !== (string) $restant && (float) $restant <= 0 );

	if ( ! $solde || ! apply_filters( 'gacct_complete_order_when_paid', true, $order, $revision_id ) ) {
		return false;
	}

	$order->update_status( 'completed', __( 'Solde réglé : commande passée en Terminée (facture émise et envoyée au client).', 'gestion-atelier-cct' ) );
	return true;
}

// Entrée du dossier en état 7 (solde nul, paiement déjà confirmé…).
add_action( 'jet-engine/custom-content-types/updated-item/revision', 'gacct_oc_on_revision_updated', 40, 2 );

function gacct_oc_on_revision_updated( $item, $prev ) {
	$item = is_object( $item ) ? (array) $item : (array) $item;
	$prev = is_object( $prev ) ? (array) $prev : (array) $prev;
	$new  = (int) ( $item['etat_de_la_commande'] ?? -1 );
	$old  = (int) ( $prev['etat_de_la_commande'] ?? -1 );

	if ( 7 === $new && 7 !== $old && ! empty( $item['order_id'] ) ) {
		gacct_oc_schedule_complete( absint( $item['order_id'] ) );
	}
}

// TOUJOURS en tâche asynchrone, jamais au milieu d'un changement de statut : la
// passerelle (ou l'appelant) enregistre ensuite SA copie de la commande, avec un
// statut périmé qui écrasait le « Terminée » (test du 08/10 : retour en « En
// cours » puis re-passage, e-mail facture envoyé deux fois). Kojito marque le
// solde payé dans woocommerce_pre_payment_complete ou au passage « En cours ».
add_action( 'woocommerce_pre_payment_complete', 'gacct_oc_schedule_complete', 99 );
add_action( 'gacct_oc_complete_async', 'gacct_oc_maybe_complete' );

function gacct_oc_schedule_complete( $order_id ) {
	$order_id = absint( $order_id );
	if ( ! $order_id ) {
		return;
	}
	if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'gacct_oc_complete_async', array( $order_id ), 'gacct' ) ) {
		return;
	}
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action( 'gacct_oc_complete_async', array( $order_id ), 'gacct' );
	} else {
		wp_schedule_single_event( time() + 30, 'gacct_oc_complete_async', array( $order_id ) );
	}
}

// Statut payé posé après coup (validation manuelle, autre passerelle).
add_action( 'woocommerce_order_status_changed', 'gacct_oc_on_status_changed', 40, 4 );

function gacct_oc_on_status_changed( $order_id, $old_status, $new_status, $order ) {
	if ( 'processing' === $new_status || 'acompte-paye' === $new_status ) {
		gacct_oc_schedule_complete( $order_id );
	}
}

/* ------------------------------------------- e-mail « Commande terminée » --- */

add_filter( 'woocommerce_email_subject_customer_completed_order', 'gacct_oc_completed_subject', 20, 2 );
add_filter( 'woocommerce_email_heading_customer_completed_order', 'gacct_oc_completed_heading', 20, 2 );
add_filter( 'wc_get_template', 'gacct_oc_completed_template', 10, 2 );

function gacct_oc_completed_subject( $subject, $order ) {
	if ( ! $order instanceof WC_Order ) {
		return $subject;
	}
	/* translators: 1: nom du site, 2: numéro de commande */
	return (string) apply_filters( 'gacct_completed_email_subject', sprintf( __( 'Votre facture %1$s : commande %2$s réglée', 'gestion-atelier-cct' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() ), $order );
}

function gacct_oc_completed_heading( $heading, $order ) {
	return (string) apply_filters( 'gacct_completed_email_heading', __( 'Votre commande est réglée', 'gestion-atelier-cct' ), $order );
}

function gacct_oc_completed_template( $template, $template_name ) {
	if ( 'emails/customer-completed-order.php' === $template_name ) {
		return dirname( __DIR__ ) . '/templates/emails/customer-completed-order.php';
	}
	if ( 'emails/plain/customer-completed-order.php' === $template_name ) {
		return dirname( __DIR__ ) . '/templates/emails/plain/customer-completed-order.php';
	}
	return $template;
}

/** Phrase d'introduction : la facture est-elle jointe ? */
function gacct_oc_completed_intro( WC_Order $order ) {
	$attached = false;
	if ( function_exists( 'WPO_WCPDF' ) ) {
		$s        = get_option( 'wpo_wcpdf_documents_settings_invoice' );
		$attached = ! empty( $s['enabled'] ) && ! empty( $s['attach_to_email_ids']['customer_completed_order'] );
	}
	$text = $attached
		? __( 'Votre commande %s est entièrement réglée, merci. Vous trouverez votre facture en pièce jointe de cet e-mail ; elle reste aussi disponible dans votre espace client.', 'gestion-atelier-cct' )
		: __( 'Votre commande %s est entièrement réglée, merci. Votre facture est disponible dans votre espace client.', 'gestion-atelier-cct' );
	$text .= ' ' . __( 'Vous recevrez un e-mail séparé avec le suivi dès que votre matériel repartira de l’atelier.', 'gestion-atelier-cct' );
	return (string) apply_filters( 'gacct_completed_email_intro', sprintf( $text, $order->get_order_number() ), $order, $attached );
}
