<?php
/**
 * Surveillance des paiements par carte (CAWL / Worldline), 15/10/2026.
 *
 * Incident AR-2026-2553 (13 au 15/10/2026) : un verrou de fichier CAWL créé par
 * le site de dev (même serveur, même /tmp de PHP-FPM, même numéro de commande)
 * empêchait la prod d'enregistrer les paiements. Les trois paiements du solde
 * ont été encaissés par CAWL sans que le site le sache ; personne n'a été
 * prévenu pendant deux jours et le client a payé trois fois.
 *
 * - Alerte immédiate à l'atelier quand CAWL abandonne une notification de
 *   paiement (`wlop.webhook_lock_timeout`) ou qu'un de ses traitements plante
 *   (`wlop.webhook_handler_error`).
 * - Paiement encaissé par CAWL mais non enregistré par le site (notification
 *   abandonnée sur un paiement capturé ou autorisé) : la commande est marquée
 *   (`_gacct_cawl_unrecorded`) et le lien de paiement du solde est fermé
 *   (gacct-balance.php, motif `card_pending`) jusqu'à l'enregistrement du solde
 *   par l'atelier. Une carte refusée ou un paiement abandonné ne bloquent
 *   JAMAIS une nouvelle tentative (retour Bastien du 15/10/2026).
 * - Page de retour : « paiement en cours de confirmation » au lieu de « solde
 *   réglé » tant que le solde n'est pas enregistré (templates/thankyou.php).
 * - Contrôle différé : 15 minutes après un retour de paiement non confirmé,
 *   si le solde n'est toujours pas enregistré, alerte à l'atelier.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_CAWL_META_ATTEMPT', '_gacct_balance_card_attempt' );
define( 'GACCT_CAWL_META_UNRECORDED', '_gacct_cawl_unrecorded' );

/**
 * Destinataires des alertes : adresses admin de la page Notifications + e-mail
 * d'administration du site.
 *
 * @return string[]
 */
function gacct_cawl_alert_recipients() {
	$to = function_exists( 'gacct_pay_admin_emails' ) ? gacct_pay_admin_emails() : array();
	$to[] = (string) get_option( 'admin_email' );

	return array_values( array_unique( array_filter( (array) apply_filters( 'gacct_cawl_alert_recipients', $to ), 'is_email' ) ) );
}

/**
 * Envoie une alerte, au plus une fois par clé et par période.
 *
 * @param string $key     Clé de dédoublonnage.
 * @param string $subject Sujet.
 * @param string $text    Corps en texte brut.
 */
function gacct_cawl_alert( $key, $subject, $text ) {
	$transient = 'gacct_cawl_alert_' . md5( $key );

	if ( get_transient( $transient ) ) {
		return;
	}
	set_transient( $transient, 1, 2 * HOUR_IN_SECONDS );

	foreach ( gacct_cawl_alert_recipients() as $to ) {
		wp_mail( $to, '[Alerte paiement] ' . $subject, $text );
	}
}

/**
 * Lien vers la commande dans l'administration.
 *
 * @param WC_Order $order
 * @return string
 */
function gacct_cawl_admin_link( $order ) {
	return admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
}

/* -----------------------------------------------------------------------------
 *  Alertes immédiates : notification CAWL abandonnée ou en erreur
 * -------------------------------------------------------------------------- */

add_action( 'wlop.webhook_lock_timeout', 'gacct_cawl_on_lock_timeout' );

function gacct_cawl_on_lock_timeout( $data ) {
	$ref   = is_array( $data ) && isset( $data['ref'] ) ? (int) $data['ref'] : 0;
	$type  = is_array( $data ) && isset( $data['type'] ) ? (string) $data['type'] : '';
	$order = $ref ? wc_get_order( $ref ) : false;
	$num   = $order ? $order->get_order_number() : (string) $ref;

	$text = sprintf(
		"CAWL n'a pas pu enregistrer une notification de paiement (%1\$s) sur la commande %2\$s : le site n'est pas au courant de ce paiement.\n\n"
		. "À faire : vérifier le paiement dans le back-office CAWL avant toute relance du client, puis l'enregistrer dans la console si besoin.\n"
		. "Ne pas laisser le client payer une seconde fois.\n\n%3\$s\n\nCause connue (AR-2026-2553, 15/10/2026) : fichier verrou CAWL inaccessible dans le dossier temporaire du serveur.",
		$type,
		$num,
		$order ? gacct_cawl_admin_link( $order ) : ''
	);

	gacct_cawl_alert( 'lock_' . $ref, sprintf( 'paiement carte non enregistré, commande %s', $num ), $text );

	// Argent pris par CAWL mais inconnu du site : plus de second paiement.
	if ( $order && in_array( $type, gacct_cawl_money_types(), true ) && ! $order->get_meta( GACCT_CAWL_META_UNRECORDED ) ) {
		$order->update_meta_data( GACCT_CAWL_META_UNRECORDED, array( 't' => time(), 'type' => $type ) );
		$order->save_meta_data();
	}

	if ( $order && ! get_transient( 'gacct_cawl_note_' . $ref ) ) {
		set_transient( 'gacct_cawl_note_' . $ref, 1, 2 * HOUR_IN_SECONDS );
		$order->add_order_note( sprintf( 'ALERTE : notification CAWL « %s » abandonnée (verrou inaccessible), paiement non enregistré par le site. Vérifier dans CAWL. Atelier prévenu par e-mail.', $type ) );
	}
}

add_action( 'wlop.webhook_handler_error', 'gacct_cawl_on_handler_error' );

function gacct_cawl_on_handler_error( $data ) {
	$e   = is_array( $data ) && isset( $data['exception'] ) ? $data['exception'] : null;
	$msg = $e instanceof Throwable ? $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')' : 'erreur inconnue';

	gacct_cawl_alert(
		'err_' . $msg,
		'erreur de traitement CAWL',
		"Un traitement de notification CAWL a échoué :\n\n" . $msg . "\n\nVérifier les paiements du jour dans le back-office CAWL et le journal WooCommerce > État > Journaux (source cawl)."
	);
}

/* -----------------------------------------------------------------------------
 *  Tentatives de paiement du solde par carte
 * -------------------------------------------------------------------------- */

/**
 * Types de notification CAWL qui signifient que l'argent est pris (capturé ou
 * autorisé).
 */
function gacct_cawl_money_types() {
	return array( 'payment.captured', 'payment.pending_capture', 'payment.authorization_requested', 'payment.pending_completion' );
}

/**
 * Un paiement encaissé par CAWL attend-il d'être enregistré par le site ?
 * Faux dès que le solde est enregistré (phase Kojito « solde_paye »).
 *
 * @param WC_Order $order
 * @return bool
 */
function gacct_cawl_unrecorded_payment( $order ) {
	return function_exists( 'gacct_bal_phase' ) && 'solde' === gacct_bal_phase( $order ) && (bool) $order->get_meta( GACCT_CAWL_META_UNRECORDED );
}

/**
 * Types de notification CAWL qui tranchent une tentative.
 */
function gacct_cawl_terminal_types() {
	return array( 'payment.captured', 'payment.rejected', 'payment.cancelled', 'payment.rejected_capture', 'payment.refunded' );
}

/**
 * Nombre de notifications CAWL définitives déjà traitées sur la commande.
 *
 * @param WC_Order $order
 * @return int
 */
function gacct_cawl_terminal_count( $order ) {
	$n = 0;

	foreach ( (array) $order->get_meta( '_wlop_processed_webhooks', false ) as $meta ) {
		$value = $meta instanceof WC_Meta_Data ? (string) $meta->value : (string) $meta;

		foreach ( gacct_cawl_terminal_types() as $type ) {
			if ( 0 === strpos( $value, $type . '_' ) ) {
				++$n;
				break;
			}
		}
	}

	return $n;
}

add_action( 'woocommerce_before_pay_action', 'gacct_cawl_note_balance_attempt' );

function gacct_cawl_note_balance_attempt( $order ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par WC_Form_Handler::pay_action.
	$method = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';

	if ( ! $order instanceof WC_Order || 0 !== strpos( $method, 'cawl' ) || ! function_exists( 'gacct_bal_phase' ) || 'solde' !== gacct_bal_phase( $order ) ) {
		return;
	}

	$order->update_meta_data(
		GACCT_CAWL_META_ATTEMPT,
		array(
			't'      => time(),
			'n'      => gacct_cawl_terminal_count( $order ),
			'status' => $order->get_status(),
		)
	);
	$order->save_meta_data();
}

/**
 * Une tentative de paiement du solde par carte attend-elle encore la réponse de
 * CAWL ? Renvoie l'horodatage de la tentative, ou 0.
 *
 * @param WC_Order $order
 * @return int
 */
function gacct_cawl_pending_attempt( $order ) {
	if ( ! function_exists( 'gacct_bal_phase' ) || 'solde' !== gacct_bal_phase( $order ) ) {
		return 0;
	}

	$attempt = $order->get_meta( GACCT_CAWL_META_ATTEMPT );

	if ( ! is_array( $attempt ) || empty( $attempt['t'] ) ) {
		return 0;
	}

	if ( gacct_cawl_terminal_count( $order ) > (int) $attempt['n'] ) {
		return 0; // CAWL a tranché (refus, annulation) : nouvelle tentative possible.
	}

	if ( ! empty( $attempt['status'] ) && $order->get_status() !== $attempt['status'] ) {
		return 0; // Statut changé depuis (échec enregistré, etc.).
	}

	return (int) $attempt['t'];
}

/* -----------------------------------------------------------------------------
 *  Contrôle différé après un retour de paiement non confirmé
 * -------------------------------------------------------------------------- */

/**
 * Appelée par la page de retour quand le solde n'est pas encore confirmé.
 *
 * @param WC_Order $order
 */
function gacct_cawl_schedule_check( $order ) {
	if ( ! function_exists( 'as_schedule_single_action' ) || empty( $_GET['hostedCheckoutId'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$args = array( $order->get_id() );

	if ( ! as_has_scheduled_action( 'gacct_cawl_check_balance', $args, 'gacct' ) ) {
		as_schedule_single_action( time() + 15 * MINUTE_IN_SECONDS, 'gacct_cawl_check_balance', $args, 'gacct' );
	}
}

add_action( 'gacct_cawl_check_balance', 'gacct_cawl_check_balance' );

function gacct_cawl_check_balance( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order || ! function_exists( 'gacct_bal_phase' ) || 'solde' !== gacct_bal_phase( $order ) || ! gacct_cawl_pending_attempt( $order ) ) {
		return; // Solde enregistré, ou CAWL a répondu (refus / annulation).
	}

	$text = sprintf(
		"Le client de la commande %1\$s est revenu de la page de paiement CAWL il y a 15 minutes, mais le solde n'est toujours pas enregistré sur le site et CAWL n'a envoyé aucune réponse traitée.\n\n"
		. "À faire : regarder dans le back-office CAWL si un paiement de %2\$s a été encaissé. Si oui, l'enregistrer dans la console (Forcer le paiement du solde) ; sinon, rien à faire (paiement abandonné).\n\n%3\$s",
		$order->get_order_number(),
		html_entity_decode( wp_strip_all_tags( wc_price( (float) $order->get_meta( '_kojito_solde_restant' ) ) ), ENT_QUOTES, 'UTF-8' ),
		gacct_cawl_admin_link( $order )
	);

	gacct_cawl_alert( 'check_' . $order_id, sprintf( 'solde par carte à vérifier, commande %s', $order->get_order_number() ), $text );
	$order->add_order_note( 'Contrôle : retour de paiement carte du solde sans confirmation de CAWL après 15 minutes. Atelier prévenu par e-mail.' );
}
