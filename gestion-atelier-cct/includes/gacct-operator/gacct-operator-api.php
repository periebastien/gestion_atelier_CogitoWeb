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
			'tracking'      => isset( $_POST['tracking'] ) ? wp_unslash( $_POST['tracking'] ) : '',
		)
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_gacct_op_change_state', 'gacct_op_ajax_change_state' );

/**
 * Envoi (ou ré-envoi modifié) du devis complémentaire : lignes JSON
 * [{product_id, qty} | {label, price, qty}] + commentaire. État 3 → 4,
 * ou remplacement du devis courant à l'état 4 (lien régénéré).
 */
function gacct_op_ajax_send_quote() {
	gacct_op_api_guard();

	$raw   = isset( $_POST['lines'] ) ? wp_unslash( $_POST['lines'] ) : '';
	$lines = json_decode( (string) $raw, true );

	if ( ! is_array( $lines ) || empty( $lines ) ) {
		wp_send_json_error( array( 'message' => __( 'Le devis doit contenir au moins une ligne.', 'gestion-atelier-cct' ) ) );
	}

	$result = gacct_quote_send(
		isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0,
		$lines,
		isset( $_POST['comment'] ) ? wp_unslash( $_POST['comment'] ) : ''
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_gacct_op_send_quote', 'gacct_op_ajax_send_quote' );

/**
 * Renvoi de l'email d'état (4 : devis, 6 : solde).
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
 * Upload du rapport PDF (états 3 à 6). Il ne change PLUS l'état : le rapport
 * est exigé à l'ENTRÉE en 6 (demande de solde) et devient visible du client à
 * l'état 7, atteint au paiement du solde.
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

	// Coffre-fort : le fichier ne doit jamais rester servable par Apache.
	if ( ! gacct_report_move_to_vault( $attachment_id ) ) {
		wp_delete_attachment( $attachment_id, true );
		wp_send_json_error( array( 'message' => __( 'Impossible de sécuriser le fichier (coffre-fort inaccessible).', 'gestion-atelier-cct' ) ) );
	}

	$revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

	if ( ! $revision ) {
		wp_delete_attachment( $attachment_id, true );
		wp_send_json_error( array( 'message' => __( 'Dossier introuvable.', 'gestion-atelier-cct' ) ) );
	}

	// Plusieurs rapports possibles : on AJOUTE par défaut, on remplace sur demande.
	$replace  = ! empty( $_POST['replace'] );
	$previous = gacct_report_ids( $revision['rapport_pdf'] ?? '' );
	$ids      = $replace ? array() : $previous;
	$ids[]    = $attachment_id;
	$ids      = array_values( array_unique( array_map( 'absint', $ids ) ) );

	$fields = array( 'rapport_pdf' => implode( ',', $ids ) );

	// « Réalisé par » : renseigné à la première dépose si personne n'est encore
	// enregistré (l'entrée en 6 le repose de toute façon).
	if ( empty( $revision['operateur_id'] ) ) {
		$fields['operateur_id'] = get_current_user_id();
	}

	if ( ! jwcct_update_cct_item( JWCCT_CCT_REVISION, $revision_id, $fields ) ) {
		wp_delete_attachment( $attachment_id, true );
		wp_send_json_error( array( 'message' => __( 'L\'enregistrement du rapport a échoué.', 'gestion-atelier-cct' ) ) );
	}

	$order = gacct_op_get_order_for_revision( $revision );

	if ( $order ) {
		gacct_op_add_signed_note( $order, __( 'Rapport d\'intervention (PDF) déposé', 'gestion-atelier-cct' ) );
	}

	// Remplacement : les anciens fichiers sont retirés du coffre.
	if ( $replace ) {
		foreach ( $previous as $old_id ) {
			if ( $old_id !== $attachment_id ) {
				wp_delete_attachment( $old_id, true );
			}
		}
	}

	wp_send_json_success( array( 'attachment_id' => $attachment_id, 'ids' => $ids ) );
}
add_action( 'wp_ajax_gacct_op_upload_report', 'gacct_op_ajax_upload_report' );

/**
 * Suppression d'un rapport PDF du dossier (fiche console).
 * Le fichier est retiré du coffre-fort et l'ID sort de rapport_pdf.
 */
function gacct_op_ajax_delete_report() {
	gacct_op_api_guard();

	$revision_id   = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;
	$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
	$row           = gacct_report_revision_row( $revision_id );

	if ( ! $row || ! $attachment_id ) {
		wp_send_json_error( array( 'message' => __( 'Dossier introuvable.', 'gestion-atelier-cct' ) ) );
	}

	$ids = gacct_report_ids( $row['rapport_pdf'] );

	if ( ! in_array( $attachment_id, $ids, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Ce document n\'appartient pas à ce dossier.', 'gestion-atelier-cct' ) ) );
	}

	$state = (int) $row['etat_de_la_commande'];

	// Le rapport est exigé à partir de l'état 6 : on n'autorise pas de vider le
	// dossier une fois le solde demandé.
	if ( $state >= 6 && 1 === count( $ids ) ) {
		wp_send_json_error( array( 'message' => __( 'Impossible de supprimer le dernier rapport : il est exigé à partir de la demande de solde.', 'gestion-atelier-cct' ) ) );
	}

	$remaining = array_values( array_diff( $ids, array( $attachment_id ) ) );

	if ( ! gacct_report_set_ids( $revision_id, $remaining ) ) {
		wp_send_json_error( array( 'message' => __( 'La mise à jour du dossier a échoué.', 'gestion-atelier-cct' ) ) );
	}

	wp_delete_attachment( $attachment_id, true );

	// Rapport généré par un formulaire : son entrée redevient un brouillon.
	if ( function_exists( 'gacct_report_entry_detach_attachment' ) ) {
		gacct_report_entry_detach_attachment( $revision_id, $attachment_id );
	}

	$revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );
	$order    = $revision ? gacct_op_get_order_for_revision( $revision ) : false;

	if ( $order ) {
		gacct_op_add_signed_note( $order, __( 'Rapport d\'intervention (PDF) supprimé', 'gestion-atelier-cct' ) );
	}

	wp_send_json_success( array( 'ids' => $remaining ) );
}
add_action( 'wp_ajax_gacct_op_delete_report', 'gacct_op_ajax_delete_report' );

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

/**
 * Événements du planning console (FullCalendar) : fonds de disponibilité
 * par jour + occupations. GET start/end au format Y-m-d (FullCalendar).
 */
function gacct_op_ajax_planning_events() {
	gacct_op_api_guard();

	$tz    = wp_timezone();
	$start = isset( $_REQUEST['start'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['start'] ) ) : '';
	$end   = isset( $_REQUEST['end'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['end'] ) ) : '';

	$start_dt = $start ? date_create( $start, $tz ) : false;
	$end_dt   = $end ? date_create( $end, $tz ) : false;

	if ( ! $start_dt || ! $end_dt ) {
		wp_send_json_error( array( 'message' => __( 'Plage de dates invalide.', 'gestion-atelier-cct' ) ) );
	}

	$start_ts = $start_dt->getTimestamp();
	$end_ts   = $end_dt->getTimestamp();
	$events   = array();
	$labels   = gacct_op_state_labels();

	foreach ( gacct_op_planning_capacities( $start_ts, $end_ts ) as $row ) {
		$available = max( 0, (float) $row['capacity_hours'] - (float) $row['occupied_hours'] );
		$day      = wp_date( 'Y-m-d', (int) $row['day_ts'], $tz );
		$full     = $available < 0.25;

		$events[] = array(
			'id'            => 'cap-' . absint( $row['capacity_id'] ),
			'title'         => sprintf( __( 'Dispo : %s h', 'gestion-atelier-cct' ), rtrim( rtrim( number_format( $available, 2, ',', ' ' ), '0' ), ',' ) ),
			'start'         => $day,
			'allDay'        => true,
			'display'       => 'background',
			'classNames'    => array( $full ? 'gacct-op-day-full' : 'gacct-op-day-open' ),
			'extendedProps' => array( 'type' => 'capacity', 'available' => $available ),
		);
	}

	// Références et clients des commandes de la plage, en un lot.
	$occupations = gacct_op_planning_occupations( $start_ts, $end_ts );
	$orders      = array();

	if ( function_exists( 'wc_get_order' ) ) {
		foreach ( array_unique( array_filter( wp_list_pluck( $occupations, 'order_id' ) ) ) as $oid ) {
			$order = wc_get_order( absint( $oid ) );
			if ( $order ) {
				$orders[ absint( $oid ) ] = $order;
			}
		}
	}

	foreach ( $occupations as $row ) {
		$state    = absint( $row['rev_etat'] ?? 0 );
		$order    = isset( $orders[ absint( $row['order_id'] ) ] ) ? $orders[ absint( $row['order_id'] ) ] : null;
		$ref      = $order ? $order->get_order_number() : ( $row['rev_id'] ? sprintf( __( 'Dossier #%d', 'gestion-atelier-cct' ), $row['rev_id'] ) : __( 'Occupation orpheline', 'gestion-atelier-cct' ) );
		$client   = $order ? trim( $order->get_formatted_billing_full_name() ) : '';
		$materiel = trim( implode( ' ', array_filter( array( $row['rev_marque'], $row['rev_modele'] ) ) ) );
		$free     = ( $state <= 2 );

		$events[] = array(
			'id'            => 'occ-' . absint( $row['occupation_id'] ),
			'title'         => trim( $ref . ( $client ? ' · ' . $client : '' ) ),
			'start'         => wp_date( 'Y-m-d', (int) $row['day_ts'], $tz ),
			'allDay'        => true,
			'editable'      => $free || current_user_can( gacct_op_reschedule_admin_cap() ),
			'classNames'    => array( 'gacct-op-occ', 'gacct-op-occ-etat-' . $state ),
			'extendedProps' => array(
				'type'          => 'occupation',
				'occupation_id' => absint( $row['occupation_id'] ),
				'revision_id'   => absint( $row['rev_id'] ?? 0 ),
				'ref'           => $ref,
				'client'        => $client,
				'materiel'      => $materiel . ( $row['rev_taille'] ? ' · ' . $row['rev_taille'] : '' ),
				'etat'          => $state,
				'etat_label'    => isset( $labels[ $state ] ) ? $labels[ $state ] : (string) $state,
				'incomplet'     => ! empty( $row['rev_incomplet'] ),
				'duration'      => (string) ( $row['duree_totale_commande'] ?? '' ),
				'fiche_url'     => $row['rev_id'] ? gacct_op_console_url( absint( $row['rev_id'] ) ) : '',
				'needs_reason'  => ! $free,
			),
		);
	}

	wp_send_json( $events );
}
add_action( 'wp_ajax_gacct_op_planning_events', 'gacct_op_ajax_planning_events' );

/**
 * Replanification d'une occupation (drag ou sélecteur de date).
 */
function gacct_op_ajax_reschedule() {
	gacct_op_api_guard();

	$result = gacct_op_reschedule(
		isset( $_POST['occupation_id'] ) ? absint( $_POST['occupation_id'] ) : 0,
		isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '',
		array(
			'reason' => isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '',
			'notify' => ! empty( $_POST['notify'] ),
		)
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_gacct_op_reschedule', 'gacct_op_ajax_reschedule' );

/**
 * « Acompte encaissé » : le virement d'acompte est arrivé sur le compte.
 * Passe la commande au statut Kojito `acompte-paye` ; la bascule 0 → 1 de la
 * révision est assurée par gacct_sync_revision_state_on_payment() (hook
 * woocommerce_order_status_changed déjà branché — `acompte-paye` fait partie
 * des statuts « payés » via gacct_paid_order_statuses).
 */
function gacct_op_ajax_confirm_deposit() {
	gacct_op_api_guard();

	$revision_id = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;
	$revision    = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );
	$order       = $revision ? gacct_op_get_order_for_revision( $revision ) : false;

	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Commande liée introuvable.', 'gestion-atelier-cct' ) ) );
	}

	if ( 'bacs' !== $order->get_payment_method() || ! in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Cette commande n\'est pas en attente de virement.', 'gestion-atelier-cct' ) ) );
	}

	gacct_op_add_signed_note( $order, __( 'Acompte encaissé : virement reçu, commande passée en « Acompte payé »', 'gestion-atelier-cct' ) );

	if ( ! $order->get_date_paid() ) {
		$order->set_date_paid( time() );
	}

	$order->update_status( 'acompte-paye', __( 'Virement d\'acompte encaissé (console atelier).', 'gestion-atelier-cct' ) );

	$updated = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

	wp_send_json_success( array(
		'order_status' => $order->get_status(),
		'etat'         => absint( $updated['etat_de_la_commande'] ?? 0 ),
	) );
}
add_action( 'wp_ajax_gacct_op_confirm_deposit', 'gacct_op_ajax_confirm_deposit' );
