<?php
/**
 * Console atelier — endpoints AJAX (wp_ajax_* + nonce GACCT_OP_NONCE).
 * Capacité gacct_operate vérifiée sur chaque action (CDC §7).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Garde commune : capacité + nonce. Renvoie le POST nettoyé de base.
 */
function gacct_op_api_guard() {
	if ( ! current_user_can( GACCT_OP_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'gestion-atelier-cct' ) ), 403 );
	}

	check_ajax_referer( GACCT_OP_NONCE, 'nonce' );
}

/**
 * Changement d'état (transitions §5, « forcer » avec motif).
 */
function gacct_op_ajax_change_state() {
	gacct_op_api_guard();

	$result = gacct_op_change_state(
		isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0,
		isset( $_POST['new_state'] ) ? absint( $_POST['new_state'] ) : 0,
		array(
			'force'         => ! empty( $_POST['force'] ),
			'reason'        => isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '',
			'unlock_reason' => isset( $_POST['unlock_reason'] ) ? wp_unslash( $_POST['unlock_reason'] ) : '',
		)
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_gacct_op_change_state', 'gacct_op_ajax_change_state' );

/**
 * Renvoi de l'email d'état (3 : devis, 5 : solde).
 */
function gacct_op_ajax_resend_email() {
	gacct_op_api_guard();

	$result = gacct_op_resend_state_email( isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_gacct_op_resend_email', 'gacct_op_ajax_resend_email' );

/**
 * Note interne atelier → note de commande privée Woo, signée.
 */
function gacct_op_ajax_add_note() {
	gacct_op_api_guard();

	$revision_id = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;
	$note        = isset( $_POST['note'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) ) : '';

	if ( '' === $note ) {
		wp_send_json_error( array( 'message' => __( 'La note est vide.', 'gestion-atelier-cct' ) ) );
	}

	$revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );
	$order    = $revision ? gacct_op_get_order_for_revision( $revision ) : false;

	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Commande liée introuvable.', 'gestion-atelier-cct' ) ) );
	}

	gacct_op_add_signed_note( $order, sprintf( __( 'Note : %s', 'gestion-atelier-cct' ), $note ) );

	wp_send_json_success();
}
add_action( 'wp_ajax_gacct_op_add_note', 'gacct_op_ajax_add_note' );

/**
 * Upload du rapport PDF + clôture 6→7 (« réalisé par » automatique, CDC §2.1).
 * Le rôle atelier n'a pas upload_files : on gère l'upload nous-mêmes,
 * restreint au PDF, taille max filtrable (défaut 10 Mo).
 */
function gacct_op_ajax_upload_report() {
	gacct_op_api_guard();

	$revision_id = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;

	if ( empty( $_FILES['rapport'] ) || ! isset( $_FILES['rapport']['tmp_name'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Aucun fichier reçu.', 'gestion-atelier-cct' ) ) );
	}

	$max_size = apply_filters( 'gacct_op_report_max_size', 10 * MB_IN_BYTES );

	if ( (int) $_FILES['rapport']['size'] > $max_size ) {
		wp_send_json_error( array( 'message' => sprintf( __( 'Fichier trop lourd (max %s).', 'gestion-atelier-cct' ), size_format( $max_size ) ) ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$upload = wp_handle_upload( $_FILES['rapport'], array(
		'test_form' => false,
		'mimes'     => array( 'pdf' => 'application/pdf' ),
	) );

	if ( isset( $upload['error'] ) ) {
		wp_send_json_error( array( 'message' => $upload['error'] ) );
	}

	$attachment_id = wp_insert_attachment( array(
		'post_mime_type' => $upload['type'],
		'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
		'post_status'    => 'inherit',
	), $upload['file'] );

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		wp_send_json_error( array( 'message' => __( 'Impossible d\'enregistrer le fichier.', 'gestion-atelier-cct' ) ) );
	}

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

	$result = gacct_op_change_state( $revision_id, 7, array(
		'extra_fields' => array( 'rapport_pdf' => $attachment_id ),
	) );

	if ( is_wp_error( $result ) ) {
		wp_delete_attachment( $attachment_id, true );
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array_merge( $result, array( 'attachment_id' => $attachment_id ) ) );
}
add_action( 'wp_ajax_gacct_op_upload_report', 'gacct_op_ajax_upload_report' );

/**
 * Correction manuelle du « réalisé par » (journalisée ancienne → nouvelle valeur).
 */
function gacct_op_ajax_set_operator() {
	gacct_op_api_guard();

	$revision_id = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;
	$operator_id = isset( $_POST['operator_id'] ) ? absint( $_POST['operator_id'] ) : 0;

	if ( $operator_id && ! user_can( $operator_id, GACCT_OP_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'Cet utilisateur n\'est pas un opérateur.', 'gestion-atelier-cct' ) ) );
	}

	$revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

	if ( ! $revision ) {
		wp_send_json_error( array( 'message' => __( 'Dossier introuvable.', 'gestion-atelier-cct' ) ) );
	}

	$old_id = absint( $revision['operateur_id'] ?? 0 );

	if ( ! jwcct_update_cct_item( JWCCT_CCT_REVISION, $revision_id, array( 'operateur_id' => $operator_id ) ) ) {
		wp_send_json_error( array( 'message' => __( 'La mise à jour a échoué.', 'gestion-atelier-cct' ) ) );
	}

	$order = gacct_op_get_order_for_revision( $revision );

	if ( $order ) {
		gacct_op_add_signed_note( $order, sprintf(
			__( '« Réalisé par » modifié : %1$s → %2$s', 'gestion-atelier-cct' ),
			$old_id ? gacct_op_operator_name( $old_id ) : __( '(vide)', 'gestion-atelier-cct' ),
			$operator_id ? gacct_op_operator_name( $operator_id ) : __( '(vide)', 'gestion-atelier-cct' )
		) );
	}

	wp_send_json_success( array( 'operator_name' => gacct_op_operator_name( $operator_id ) ) );
}
add_action( 'wp_ajax_gacct_op_set_operator', 'gacct_op_ajax_set_operator' );

/**
 * Annulation du dossier depuis la console (mêmes effets que l'annulation auto :
 * commande annulée, CCT supprimés, créneau libéré, email client + copie admin).
 * Confirmation côté client obligatoire ; motif journalisé.
 */
function gacct_op_ajax_cancel() {
	gacct_op_api_guard();

	$revision_id = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;
	$reason      = isset( $_POST['reason'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) ) : '';

	if ( '' === $reason ) {
		wp_send_json_error( array( 'message' => __( 'Un motif est obligatoire pour annuler un dossier.', 'gestion-atelier-cct' ) ) );
	}

	$revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );
	$order    = $revision ? gacct_op_get_order_for_revision( $revision ) : false;

	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Commande liée introuvable.', 'gestion-atelier-cct' ) ) );
	}

	gacct_op_add_signed_note( $order, sprintf( __( 'Annulation du dossier — motif : %s', 'gestion-atelier-cct' ), $reason ) );

	$template = ( 'bacs' === $order->get_payment_method() ) ? 'bacs_cancel' : 'unfinished_cancel';

	gacct_pay_cancel_unpaid_order( $order, time(), $template, $reason );

	wp_send_json_success( array( 'redirect' => gacct_op_console_url() ) );
}
add_action( 'wp_ajax_gacct_op_cancel', 'gacct_op_ajax_cancel' );

/**
 * Réception d'un colis (complète ou partielle avec liste des manquants).
 */
function gacct_op_ajax_receive() {
	gacct_op_api_guard();

	$missing = array();

	if ( isset( $_POST['missing'] ) && is_array( $_POST['missing'] ) ) {
		$missing = array_map( 'sanitize_text_field', wp_unslash( $_POST['missing'] ) );
	}

	$result = gacct_op_receive(
		isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0,
		$missing
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_gacct_op_receive', 'gacct_op_ajax_receive' );

/**
 * Relance de paiement manuelle depuis la fiche.
 */
function gacct_op_ajax_payment_reminder() {
	gacct_op_api_guard();

	$revision_id = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;
	$revision    = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );
	$order       = $revision ? gacct_op_get_order_for_revision( $revision ) : false;

	$result = gacct_op_manual_payment_reminder( $order );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_gacct_op_payment_reminder', 'gacct_op_ajax_payment_reminder' );
