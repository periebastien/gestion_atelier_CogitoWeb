<?php
/**
 * Cycle de vie des dossiers : état 9 « Sans suite », rappels avant créneau,
 * relances de solde, récapitulatif admin quotidien, nettoyage à l'annulation.
 *
 * Décisions Bastien du 27/08/2026 :
 * - une révision dont le matériel n'arrive jamais est MARQUÉE (état terminal 9),
 *   jamais supprimée ni mise en brouillon ;
 * - l'acompte reste acquis, la commande n'est ni annulée ni remboursée ;
 * - le créneau se libère en passant l'occupation en `cct_status = draft`
 *   (les calculs de disponibilité ne comptent que les `publish`), JAMAIS par
 *   suppression : la trace et la relation 11 restent, le retour arrière est
 *   immédiat ;
 * - un dossier dont le client a déclaré un numéro de suivi est EXCLU de la
 *   bascule automatique (colis peut-être en route) : il est signalé dans le
 *   récapitulatif admin et se classe à la main depuis la console ;
 * - un récapitulatif admin part le matin, avant toute bascule du soir.
 *
 * La bascule tourne au tick horaire : dès que l'heure locale atteint
 * `noshow_hour` (défaut 18 h), les créneaux du LENDEMAIN (et les créneaux passés
 * jamais traités, rattrapage) basculent. Les effets de l'entrée en état 9
 * (occupation en draft, e-mail, metas) vivent dans UN SEUL écouteur du hook
 * JetEngine `updated-item` : la bascule automatique, le bouton console et une
 * édition directe de la CCT produisent donc exactement le même résultat.
 *
 * La sortie de l'état 9 passe par la REPLANIFICATION (planning console) :
 * gacct_op_reschedule() republie l'occupation sur la nouvelle date et remet le
 * dossier en état 1 (voir gacct-operator-core.php). Pas de transition 9 → 1
 * nue : elle laisserait une occupation en draft sur une date passée, que la
 * bascule reclasserait le soir même.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GACCT_STATE_SANS_SUITE' ) ) {
	define( 'GACCT_STATE_SANS_SUITE', 9 );
}

define( 'GACCT_LC_META_PRESLOT_J7', '_gacct_preslot_j7_sent' );
define( 'GACCT_LC_META_PRESLOT_J2', '_gacct_preslot_j2_sent' );
define( 'GACCT_LC_META_BALANCE_REF', '_gacct_balance_ref_ts' );
define( 'GACCT_LC_META_BALANCE_REM1', '_gacct_balance_rem1_sent' );
define( 'GACCT_LC_META_BALANCE_REM2', '_gacct_balance_rem2_sent' );
define( 'GACCT_LC_RECAP_LAST_OPT', 'gacct_lc_recap_last' );

/* =============================================================================
 *  GARDE-FOUS TRANSVERSAUX
 * ============================================================================= */

/**
 * L'annulation native de WooCommerce (woocommerce_cancel_unpaid_orders, pilotée
 * par « hold stock » = 60 min) court plus vite que notre calendrier de relances
 * (relance H+1, suppression au premier minuit) : elle annulerait une commande
 * `pending` sans e-mail au client et sans nettoyage des CCT. On la neutralise :
 * le circuit de gacct-payments.php est le seul à annuler les impayés.
 */
add_filter( 'woocommerce_cancel_unpaid_order', '__return_false', 20 );

/**
 * Toute commande annulée libère son créneau, quelle que soit l'origine de
 * l'annulation (admin, client, automatisme) : l'occupation passe en draft.
 *
 * Le circuit d'annulation automatique (gacct_pay_cancel_unpaid_order) supprime
 * les CCT AVANT de poser le statut cancelled : ici l'occupation n'existe alors
 * plus, et on ne fait rien. Idempotent par nature.
 */
add_action( 'woocommerce_order_status_cancelled', 'gacct_lc_release_slot_on_cancel', 10, 2 );

function gacct_lc_release_slot_on_cancel( $order_id, $order = null ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$occupation_id = (int) $order->get_meta( JWCCT_ORDER_OCCUPATION_ID );

	if ( ! $occupation_id ) {
		$occupation_id = gacct_lc_occupation_id_for_order( $order->get_id() );
	}

	if ( ! $occupation_id ) {
		return;
	}

	global $wpdb;
	$status = $wpdb->get_var( $wpdb->prepare(
		"SELECT cct_status FROM {$wpdb->prefix}jet_cct_occupation_atelier WHERE _ID = %d LIMIT 1",
		$occupation_id
	) );

	if ( 'publish' !== $status ) {
		return;
	}

	if ( jwcct_update_cct_item( JWCCT_CCT_OCCUPATION, $occupation_id, array( 'cct_status' => 'draft' ) ) ) {
		$order->add_order_note( sprintf(
			/* translators: %d: id d'occupation */
			__( 'Commande annulée : le créneau atelier a été libéré (occupation #%d passée en brouillon).', 'gestion-atelier-cct' ),
			$occupation_id
		) );
		jwcct_log( "lifecycle : commande {$order_id} annulée, occupation $occupation_id passée en draft." );
	}
}

/**
 * Occupation liée à une commande, par la colonne order_id (repli quand la meta
 * de commande manque).
 */
function gacct_lc_occupation_id_for_order( $order_id ) {
	global $wpdb;

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT _ID FROM {$wpdb->prefix}jet_cct_occupation_atelier WHERE order_id = %d ORDER BY _ID DESC LIMIT 1",
		absint( $order_id )
	) );
}

/* =============================================================================
 *  ENTRÉE EN ÉTAT 9 : LES EFFETS (un seul lieu)
 *
 *  Déclenché par le hook JetEngine, donc par TOUTES les voies : bascule
 *  automatique du soir, bouton « Classer sans suite » de la console (transition
 *  forçable, motif obligatoire), édition directe de la CCT dans l'admin.
 * ============================================================================= */

add_action( 'jet-engine/custom-content-types/updated-item/revision', 'gacct_lc_on_state9_entry', 30, 2 );

function gacct_lc_on_state9_entry( $item, $prev ) {
	$item = is_object( $item ) ? (array) $item : $item;
	$prev = is_object( $prev ) ? (array) $prev : $prev;

	$new_state = isset( $item['etat_de_la_commande'] ) ? (int) $item['etat_de_la_commande'] : -1;

	if ( GACCT_STATE_SANS_SUITE !== $new_state ) {
		return;
	}

	$old_state = ( is_array( $prev ) && isset( $prev['etat_de_la_commande'] ) ) ? (int) $prev['etat_de_la_commande'] : -1;

	if ( GACCT_STATE_SANS_SUITE === $old_state ) {
		return;
	}

	$revision_id = isset( $item['_ID'] ) ? absint( $item['_ID'] ) : 0;
	$order_id    = isset( $item['order_id'] ) ? absint( $item['order_id'] ) : 0;
	$order       = $order_id ? wc_get_order( $order_id ) : false;

	if ( ! $order instanceof WC_Order ) {
		jwcct_log( "lifecycle : révision $revision_id passée en état 9 sans commande valide ($order_id), effets ignorés." );
		return;
	}

	// --- Créneau : occupation en draft (jamais supprimée). --------------------
	$occupation_id = (int) $order->get_meta( JWCCT_ORDER_OCCUPATION_ID );

	if ( ! $occupation_id ) {
		$occupation_id = gacct_lc_occupation_id_for_order( $order_id );
	}

	$slot_ts = 0;

	if ( $occupation_id ) {
		global $wpdb;
		$occ = $wpdb->get_row( $wpdb->prepare(
			"SELECT cct_status, date_reservee FROM {$wpdb->prefix}jet_cct_occupation_atelier WHERE _ID = %d LIMIT 1",
			$occupation_id
		), ARRAY_A );

		if ( is_array( $occ ) ) {
			$slot_ts = (int) $occ['date_reservee'];

			if ( 'publish' === $occ['cct_status'] ) {
				jwcct_update_cct_item( JWCCT_CCT_OCCUPATION, $occupation_id, array( 'cct_status' => 'draft' ) );
			}
		}
	}

	$slot_label = $slot_ts ? wp_date( get_option( 'date_format' ), $slot_ts ) : __( 'la date convenue', 'gestion-atelier-cct' );

	// --- E-mail client (une seule fois par épisode sans suite). ---------------
	$already = (string) $order->get_meta( GACCT_PAY_META_NOSHOW_RELEASED );
	$sent    = null;

	if ( '' === $already ) {
		$sent = gacct_pay_send_email(
			$order->get_billing_email(),
			'noshow_release',
			gacct_pay_email_variables( $order, array( '{slot_date}' => $slot_label ) ),
			true
		);
	}

	$order->update_meta_data( GACCT_PAY_META_NOSHOW_RELEASED, current_time( 'mysql' ) );

	if ( $slot_ts ) {
		$order->update_meta_data( GACCT_PAY_META_NOSHOW_SLOT, $slot_ts );
	}

	$order->add_order_note( sprintf(
		/* translators: 1: date du créneau, 2: id de révision, 3: envoi email */
		__( 'Dossier classé SANS SUITE (matériel jamais reçu) : créneau du %1$s libéré (occupation en brouillon), acompte conservé, révision #%2$d conservée. %3$s Reprise : replanifier depuis le Planning.', 'gestion-atelier-cct' ),
		$slot_label,
		$revision_id,
		null === $sent
			? __( 'E-mail client déjà envoyé lors d’un précédent classement, pas de nouvel envoi.', 'gestion-atelier-cct' )
			: ( $sent ? __( 'E-mail envoyé au client (copie admin).', 'gestion-atelier-cct' ) : __( 'ERREUR : e-mail non envoyé.', 'gestion-atelier-cct' ) )
	) );
	$order->save();

	jwcct_log( "lifecycle : révision $revision_id (commande $order_id) classée sans suite, occupation $occupation_id en draft, email " . ( null === $sent ? 'déjà envoyé' : ( $sent ? 'ok' : 'KO' ) ) . '.' );
}

/* =============================================================================
 *  BASCULE AUTOMATIQUE DU SOIR
 * ============================================================================= */

add_action( GACCT_PAY_HOURLY_EVENT, 'gacct_lc_process_no_show', 30 );

/**
 * La veille du créneau, à partir de `noshow_hour` (heure locale), les dossiers
 * encore en état 0/1 dont la commande est payée basculent en état 9.
 *
 * Exclusions :
 * - suivi colis déclaré (gacct_ship_in_transit) : le colis est peut-être en
 *   route, le dossier a été signalé dans le récap du matin, classement manuel ;
 * - commande non payée : les calendriers de paiement (virement J+2/J+3, non
 *   finalisé H+1/minuit) annulent et nettoient déjà tout ;
 * - dossier déjà traité (état 9, ou occupation déjà en draft).
 */
function gacct_lc_process_no_show() {
	global $wpdb;

	$settings = gacct_pay_settings();

	if ( (int) current_time( 'G' ) < (int) $settings['noshow_hour'] ) {
		return;
	}

	$occ_table = $wpdb->prefix . 'jet_cct_occupation_atelier';
	$rev_table = $wpdb->prefix . 'jet_cct_revision';

	// Créneaux du lendemain inclus (bascule la veille au soir) + rattrapage des
	// créneaux passés jamais traités. Dates stockées à minuit UTC du jour
	// calendaire : borne = minuit UTC d'après-demain, calculé sur la date locale.
	$limit = strtotime( current_time( 'Y-m-d' ) . ' 00:00:00 +0000' ) + 2 * DAY_IN_SECONDS;
	$limit = (int) apply_filters( 'gacct_pay_noshow_limit_ts', $limit );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT o._ID AS occupation_id, o.order_id, o.date_reservee, r._ID AS revision_id, r.etat_de_la_commande AS etat
			 FROM {$occ_table} o
			 LEFT JOIN {$rev_table} r ON r.order_id = o.order_id
			 WHERE o.cct_status = 'publish'
			   AND o.order_id > 0
			   AND CAST(o.date_reservee AS UNSIGNED) < %d",
			$limit
		),
		ARRAY_A
	);

	foreach ( (array) $rows as $row ) {
		$order_id    = (int) $row['order_id'];
		$revision_id = (int) $row['revision_id'];
		$etat        = ( null === $row['etat'] || '' === $row['etat'] ) ? null : (int) $row['etat'];

		// Matériel arrivé (état >= 2) : le créneau est honoré. Pas de révision
		// liée : rien à classer, la purge/l'annulation s'en chargeront.
		if ( ! $revision_id || ( null !== $etat && $etat >= 2 ) ) {
			continue;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
			continue;
		}

		if ( function_exists( 'gacct_order_payment_received' ) && ! gacct_order_payment_received( $order ) ) {
			continue; // Les calendriers de paiement s'en chargent.
		}

		// Suivi colis déclaré : exclu de l'automatique, signalé le matin.
		if ( function_exists( 'gacct_ship_in_transit' ) && gacct_ship_in_transit( $revision_id ) ) {
			jwcct_log( "lifecycle no_show : révision $revision_id exclue de la bascule (suivi colis déclaré), classement manuel possible." );
			continue;
		}

		// Bascule : l'écouteur gacct_lc_on_state9_entry applique tous les effets.
		$prev = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

		if ( ! is_array( $prev ) ) {
			continue;
		}

		$fields = array( 'etat_de_la_commande' => (string) GACCT_STATE_SANS_SUITE );

		if ( ! jwcct_update_cct_item( JWCCT_CCT_REVISION, $revision_id, $fields ) ) {
			jwcct_log( "lifecycle no_show : échec de la bascule en état 9 pour la révision $revision_id." );
			continue;
		}

		$new_item = array_merge( $prev, $fields, array( '_ID' => $revision_id ) );
		do_action( 'jet-engine/custom-content-types/updated-item/revision', $new_item, $prev, null );
	}
}

/* =============================================================================
 *  RAPPELS AVANT CRÉNEAU (J-7 et J-2)
 * ============================================================================= */

add_action( GACCT_PAY_HOURLY_EVENT, 'gacct_lc_process_preslot_reminders', 40 );

/**
 * Rappels « avez-vous expédié votre matériel ? » avant le créneau, aux dossiers
 * payés encore en état 0/1 sans suivi colis déclaré.
 *
 * Deux paliers : J-7 (fenêtre 2 à 7 jours du créneau) et J-2 (moins de 2 jours).
 * Une commande passée tardivement ne reçoit que les paliers de sa fenêtre, et
 * jamais de rappel dans les 24 h qui suivent sa création (l'e-mail « paiement
 * reçu » vient de donner les consignes). Envois entre 8 h et 20 h locales.
 */
function gacct_lc_process_preslot_reminders() {
	global $wpdb;

	$hour = (int) current_time( 'G' );

	if ( $hour < 8 || $hour >= 20 ) {
		return;
	}

	$settings  = gacct_pay_settings();
	$occ_table = $wpdb->prefix . 'jet_cct_occupation_atelier';
	$rev_table = $wpdb->prefix . 'jet_cct_revision';

	$now      = time();
	$horizon  = $now + (int) $settings['preslot_days_1'] * DAY_IN_SECONDS + DAY_IN_SECONDS;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT o.order_id, o.date_reservee, r._ID AS revision_id, r.etat_de_la_commande AS etat
			 FROM {$occ_table} o
			 INNER JOIN {$rev_table} r ON r.order_id = o.order_id
			 WHERE o.cct_status = 'publish'
			   AND o.order_id > 0
			   AND CAST(o.date_reservee AS UNSIGNED) > %d
			   AND CAST(o.date_reservee AS UNSIGNED) < %d
			   AND CAST(r.etat_de_la_commande AS UNSIGNED) <= 1
			   AND r.cct_status = 'publish'",
			$now,
			$horizon
		),
		ARRAY_A
	);

	foreach ( (array) $rows as $row ) {
		$order_id    = (int) $row['order_id'];
		$revision_id = (int) $row['revision_id'];
		$slot_ts     = (int) $row['date_reservee'];
		$delta_days  = ( $slot_ts - $now ) / DAY_IN_SECONDS;

		// Palier concerné : J-2 sous `preslot_days_2` jours, J-7 entre les deux.
		if ( $delta_days <= (int) $settings['preslot_days_2'] ) {
			$meta_key = GACCT_LC_META_PRESLOT_J2;
			$days     = (int) $settings['preslot_days_2'];
		} elseif ( $delta_days <= (int) $settings['preslot_days_1'] ) {
			$meta_key = GACCT_LC_META_PRESLOT_J7;
			$days     = (int) $settings['preslot_days_1'];
		} else {
			continue;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
			continue;
		}

		if ( $order->get_meta( $meta_key ) ) {
			continue; // Palier déjà envoyé.
		}

		if ( function_exists( 'gacct_order_payment_received' ) && ! gacct_order_payment_received( $order ) ) {
			continue; // Non payé : les relances de paiement parlent déjà au client.
		}

		// Suivi déclaré : le colis est annoncé, pas de rappel d'expédition.
		if ( function_exists( 'gacct_ship_in_transit' ) && gacct_ship_in_transit( $revision_id ) ) {
			continue;
		}

		// Commande créée il y a moins de 24 h : les consignes viennent de partir.
		$created = $order->get_date_created();

		if ( $created && ( $now - $created->getTimestamp() ) < DAY_IN_SECONDS ) {
			continue;
		}

		$data = function_exists( 'gacct_conf_data' ) ? gacct_conf_data( $order ) : array();

		$sent = gacct_pay_send_email(
			$order->get_billing_email(),
			'pre_slot_reminder',
			gacct_pay_email_variables( $order, array(
				'{slot_date}'        => wp_date( get_option( 'date_format' ), $slot_ts ),
				'{days_before}'      => (string) max( 1, (int) ceil( $delta_days ) ),
				'{parcel_deadline}'  => ! empty( $data['parcel_label'] ) ? $data['parcel_label'] : __( 'la veille de votre créneau', 'gestion-atelier-cct' ),
				'{workshop_address}' => ! empty( $data['store_address'] ) ? esc_html( implode( ', ', (array) $data['store_address'] ) ) : '',
				'{shipping_url}'     => esc_url( $order->get_view_order_url() ),
			) )
		);

		$order->update_meta_data( $meta_key, current_time( 'mysql' ) );
		$order->add_order_note( sprintf(
			/* translators: 1: nombre de jours, 2: statut d'envoi */
			__( 'Rappel avant créneau (J-%1$d) : e-mail « avez-vous expédié votre matériel ? » %2$s.', 'gestion-atelier-cct' ),
			$days,
			$sent ? __( 'envoyé au client', 'gestion-atelier-cct' ) : __( 'NON envoyé (erreur wp_mail)', 'gestion-atelier-cct' )
		) );
		$order->save();
	}
}

/* =============================================================================
 *  RELANCES DE SOLDE (état 6)
 * ============================================================================= */

/**
 * Chronomètre du solde : posé à l'entrée en état 6 (toutes voies confondues,
 * le hook JetEngine est émis par gacct_op_change_state comme par gacct-billing).
 * Une re-entrée en 6 remet le chronomètre et les relances à zéro.
 */
add_action( 'jet-engine/custom-content-types/updated-item/revision', 'gacct_lc_on_state6_entry', 30, 2 );

function gacct_lc_on_state6_entry( $item, $prev ) {
	$item = is_object( $item ) ? (array) $item : $item;
	$prev = is_object( $prev ) ? (array) $prev : $prev;

	$new_state = isset( $item['etat_de_la_commande'] ) ? (int) $item['etat_de_la_commande'] : -1;

	if ( 6 !== $new_state ) {
		return;
	}

	$old_state = ( is_array( $prev ) && isset( $prev['etat_de_la_commande'] ) ) ? (int) $prev['etat_de_la_commande'] : -1;

	if ( 6 === $old_state ) {
		return;
	}

	$order_id = isset( $item['order_id'] ) ? absint( $item['order_id'] ) : 0;
	$order    = $order_id ? wc_get_order( $order_id ) : false;

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$order->update_meta_data( GACCT_LC_META_BALANCE_REF, time() );
	$order->delete_meta_data( GACCT_LC_META_BALANCE_REM1 );
	$order->delete_meta_data( GACCT_LC_META_BALANCE_REM2 );
	$order->save_meta_data();
}

add_action( GACCT_PAY_HOURLY_EVENT, 'gacct_lc_process_balance_reminders', 50 );

/**
 * Deux relances automatiques du solde (défaut J+3 puis J+10 après la demande),
 * avec lien de paiement. Au-delà, le dossier apparaît dans le récapitulatif
 * admin quotidien (« soldes en souffrance »). Envois entre 8 h et 20 h locales.
 */
function gacct_lc_process_balance_reminders() {
	global $wpdb;

	$hour = (int) current_time( 'G' );

	if ( $hour < 8 || $hour >= 20 ) {
		return;
	}

	$settings  = gacct_pay_settings();
	$rev_table = $wpdb->prefix . 'jet_cct_revision';

	$rows = $wpdb->get_results(
		"SELECT _ID AS revision_id, order_id FROM {$rev_table}
		 WHERE cct_status = 'publish'
		   AND CAST(etat_de_la_commande AS UNSIGNED) = 6
		   AND order_id > 0",
		ARRAY_A
	);

	$now = time();

	foreach ( (array) $rows as $row ) {
		$order = wc_get_order( (int) $row['order_id'] );

		if ( ! $order instanceof WC_Order || $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
			continue;
		}

		$ref = (int) $order->get_meta( GACCT_LC_META_BALANCE_REF );

		if ( ! $ref ) {
			// Dossier entré en état 6 avant ce mécanisme : le chronomètre démarre.
			$ref = $now;
			$order->update_meta_data( GACCT_LC_META_BALANCE_REF, $ref );
			$order->save_meta_data();
			continue;
		}

		$due1 = $ref + (int) $settings['balance_days_1'] * DAY_IN_SECONDS;
		$due2 = $ref + (int) $settings['balance_days_2'] * DAY_IN_SECONDS;

		$meta_key = '';
		$palier   = 0;

		if ( $now >= $due2 && ! $order->get_meta( GACCT_LC_META_BALANCE_REM2 ) ) {
			$meta_key = GACCT_LC_META_BALANCE_REM2;
			$palier   = (int) $settings['balance_days_2'];
		} elseif ( $now >= $due1 && ! $order->get_meta( GACCT_LC_META_BALANCE_REM1 ) ) {
			$meta_key = GACCT_LC_META_BALANCE_REM1;
			$palier   = (int) $settings['balance_days_1'];
		}

		if ( '' === $meta_key ) {
			continue;
		}

		// À l'état 6, le total courant de la commande EST le solde (Kojito l'a
		// ramené au reste à payer) : aucun recalcul ici.
		$sent = gacct_pay_send_email(
			$order->get_billing_email(),
			'balance_reminder',
			gacct_pay_email_variables( $order, array(
				'{balance_amount}' => wp_strip_all_tags( wc_price( (float) $order->get_total() ) ),
			) )
		);

		$order->update_meta_data( $meta_key, current_time( 'mysql' ) );
		$order->add_order_note( sprintf(
			/* translators: 1: nombre de jours, 2: statut d'envoi */
			__( 'Relance de solde (J+%1$d après la demande) : %2$s.', 'gestion-atelier-cct' ),
			$palier,
			$sent ? __( 'e-mail envoyé au client avec le lien de paiement', 'gestion-atelier-cct' ) : __( 'ERREUR, e-mail non envoyé', 'gestion-atelier-cct' )
		) );
		$order->save();
	}
}

/* =============================================================================
 *  RÉCAPITULATIF ADMIN DU MATIN
 * ============================================================================= */

add_action( GACCT_PAY_HOURLY_EVENT, 'gacct_lc_process_daily_recap', 60 );

/**
 * Un e-mail admin par jour, à partir de `recap_hour` (défaut 8 h), qui agrège :
 * - les dossiers qui basculeront « Sans suite » ce soir (garde-fou : une
 *   demi-journée pour pointer une réception oubliée) ;
 * - les dossiers épargnés parce qu'un suivi colis est déclaré (à surveiller,
 *   classement manuel si le colis n'arrive pas) ;
 * - les soldes en souffrance (relances épuisées) ;
 * - les devis sans réponse depuis trop longtemps ;
 * - les virements dont l'échéance d'annulation tombe aujourd'hui.
 *
 * Rien à signaler = pas d'e-mail.
 */
function gacct_lc_process_daily_recap() {
	$settings = gacct_pay_settings();
	$today    = current_time( 'Y-m-d' );

	if ( (int) current_time( 'G' ) < (int) $settings['recap_hour'] ) {
		return;
	}

	if ( get_option( GACCT_LC_RECAP_LAST_OPT ) === $today ) {
		return;
	}

	update_option( GACCT_LC_RECAP_LAST_OPT, $today, false );

	$sections = gacct_lc_recap_sections();
	$total    = 0;

	foreach ( $sections as $section ) {
		$total += count( $section['items'] );
	}

	if ( ! $total ) {
		return;
	}

	$html = '';

	foreach ( $sections as $section ) {
		if ( empty( $section['items'] ) ) {
			continue;
		}

		$html .= '<h3 style="margin:18px 0 6px;">' . esc_html( $section['title'] ) . '</h3>';

		if ( ! empty( $section['hint'] ) ) {
			$html .= '<p style="margin:0 0 8px;color:#666;font-size:13px;">' . esc_html( $section['hint'] ) . '</p>';
		}

		$html .= '<ul style="margin:0 0 12px;padding-left:20px;">';

		foreach ( $section['items'] as $item ) {
			$html .= '<li style="margin-bottom:4px;">' . $item . '</li>';
		}

		$html .= '</ul>';
	}

	/* translators: %d: nombre de dossiers */
	$subject = sprintf( _n( 'Point du matin atelier : %d dossier à surveiller', 'Point du matin atelier : %d dossiers à surveiller', $total, 'gestion-atelier-cct' ), $total );

	$intro = '<p>' . esc_html__( 'Voici les dossiers qui demandent un œil aujourd’hui. Les bascules « Sans suite » du soir peuvent encore être évitées en pointant une réception dans la console.', 'gestion-atelier-cct' ) . '</p>';

	$message = gacct_render_email_html( $subject, $intro . $html );
	$message = apply_filters( 'gacct_lc_recap_html', $message, $sections );

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	foreach ( gacct_pay_admin_emails() as $admin ) {
		wp_mail( $admin, $subject, $message, $headers );
	}

	jwcct_log( "lifecycle recap : point du matin envoyé ($total éléments)." );
}

/**
 * Les sections du récapitulatif. Chaque item est du HTML prêt à afficher.
 *
 * @return array<int,array{title:string,hint:string,items:string[]}>
 */
function gacct_lc_recap_sections() {
	global $wpdb;

	$settings  = gacct_pay_settings();
	$occ_table = $wpdb->prefix . 'jet_cct_occupation_atelier';
	$rev_table = $wpdb->prefix . 'jet_cct_revision';
	$now       = time();

	$noshow_candidates = array();
	$transit_watch     = array();
	$balance_overdue   = array();
	$quote_overdue     = array();
	$bacs_expiring     = array();

	// --- Bascules du soir + dossiers épargnés (suivi déclaré). ----------------
	$limit = strtotime( current_time( 'Y-m-d' ) . ' 00:00:00 +0000' ) + 2 * DAY_IN_SECONDS;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT o.order_id, o.date_reservee, r._ID AS revision_id, r.etat_de_la_commande AS etat
			 FROM {$occ_table} o
			 INNER JOIN {$rev_table} r ON r.order_id = o.order_id
			 WHERE o.cct_status = 'publish'
			   AND o.order_id > 0
			   AND CAST(o.date_reservee AS UNSIGNED) < %d
			   AND CAST(r.etat_de_la_commande AS UNSIGNED) <= 1
			   AND r.cct_status = 'publish'",
			$limit
		),
		ARRAY_A
	);

	foreach ( (array) $rows as $row ) {
		$order = wc_get_order( (int) $row['order_id'] );

		if ( ! $order instanceof WC_Order || $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
			continue;
		}

		if ( function_exists( 'gacct_order_payment_received' ) && ! gacct_order_payment_received( $order ) ) {
			continue;
		}

		$revision_id = (int) $row['revision_id'];
		$label       = gacct_lc_recap_line( $order, $revision_id, sprintf(
			/* translators: %s: date du créneau */
			__( 'créneau du %s', 'gestion-atelier-cct' ),
			wp_date( get_option( 'date_format' ), (int) $row['date_reservee'] )
		) );

		if ( function_exists( 'gacct_ship_in_transit' ) && gacct_ship_in_transit( $revision_id ) ) {
			$transit_watch[] = $label;
		} else {
			$noshow_candidates[] = $label;
		}
	}

	// --- Soldes en souffrance (2e relance passée). ----------------------------
	$rows = $wpdb->get_results(
		"SELECT _ID AS revision_id, order_id FROM {$rev_table}
		 WHERE cct_status = 'publish'
		   AND CAST(etat_de_la_commande AS UNSIGNED) = 6
		   AND order_id > 0",
		ARRAY_A
	);

	foreach ( (array) $rows as $row ) {
		$order = wc_get_order( (int) $row['order_id'] );

		if ( ! $order instanceof WC_Order || $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
			continue;
		}

		$ref = (int) $order->get_meta( GACCT_LC_META_BALANCE_REF );

		if ( $ref && $now >= $ref + (int) $settings['balance_days_2'] * DAY_IN_SECONDS ) {
			$days = (int) floor( ( $now - $ref ) / DAY_IN_SECONDS );
			$balance_overdue[] = gacct_lc_recap_line( $order, (int) $row['revision_id'], sprintf(
				/* translators: 1: montant, 2: jours */
				__( 'solde de %1$s attendu depuis %2$d jours, relances épuisées', 'gestion-atelier-cct' ),
				wp_strip_all_tags( wc_price( (float) $order->get_total() ) ),
				$days
			) );
		}
	}

	// --- Devis sans réponse depuis trop longtemps. ----------------------------
	$rows = $wpdb->get_results(
		"SELECT _ID AS revision_id, order_id FROM {$rev_table}
		 WHERE cct_status = 'publish'
		   AND CAST(etat_de_la_commande AS UNSIGNED) = 4
		   AND order_id > 0",
		ARRAY_A
	);

	foreach ( (array) $rows as $row ) {
		$order = wc_get_order( (int) $row['order_id'] );

		if ( ! $order instanceof WC_Order || $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
			continue;
		}

		$sent_at = strtotime( (string) $order->get_meta( defined( 'GACCT_QUOTE_META_SENT_AT' ) ? GACCT_QUOTE_META_SENT_AT : '_gacct_quote_sent_at' ) );

		if ( $sent_at && $now >= $sent_at + (int) $settings['quote_alert_days'] * DAY_IN_SECONDS ) {
			$days = (int) floor( ( $now - $sent_at ) / DAY_IN_SECONDS );
			$quote_overdue[] = gacct_lc_recap_line( $order, (int) $row['revision_id'], sprintf(
				/* translators: %d: jours */
				__( 'devis sans réponse depuis %d jours, à appeler (décision forçable depuis la fiche)', 'gestion-atelier-cct' ),
				$days
			) );
		}
	}

	// --- Virements dont l'échéance d'annulation tombe aujourd'hui. ------------
	$orders = wc_get_orders( array(
		'status'         => array( 'on-hold', 'pending' ),
		'payment_method' => 'bacs',
		'limit'          => 50,
	) );

	$day_start = strtotime( current_time( 'Y-m-d' ) . ' 00:00:00' ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
	$day_end   = $day_start + DAY_IN_SECONDS;

	foreach ( $orders as $order ) {
		if ( ! gacct_pay_order_awaits_transfer( $order ) ) {
			continue;
		}

		$deadlines = gacct_pay_order_deadlines( $order );

		if ( $deadlines['cancel'] >= $day_start && $deadlines['cancel'] < $day_end ) {
			$revision_id     = (int) $order->get_meta( JWCCT_ORDER_REVISION_ID );
			$bacs_expiring[] = gacct_lc_recap_line( $order, $revision_id, __( 'virement toujours pas reçu, annulation automatique aujourd’hui', 'gestion-atelier-cct' ) );
		}
	}

	return apply_filters( 'gacct_lc_recap_sections', array(
		array(
			'title' => sprintf(
				/* translators: %d: heure de bascule */
				__( 'Basculeront « Sans suite » ce soir à %d h', 'gestion-atelier-cct' ),
				(int) $settings['noshow_hour']
			),
			'hint'  => __( 'Matériel jamais reçu, commande payée. Un colis arrivé mais non pointé ? Confirmez la réception dans la console avant ce soir pour éviter la bascule.', 'gestion-atelier-cct' ),
			'items' => $noshow_candidates,
		),
		array(
			'title' => __( 'Colis annoncé mais pas encore arrivé', 'gestion-atelier-cct' ),
			'hint'  => __( 'Ces dossiers ont un numéro de suivi déclaré : ils ne basculent pas automatiquement. Si le colis n’arrive pas, classez-les sans suite depuis la fiche.', 'gestion-atelier-cct' ),
			'items' => $transit_watch,
		),
		array(
			'title' => __( 'Soldes en souffrance', 'gestion-atelier-cct' ),
			'hint'  => __( 'La demande de solde et les deux relances automatiques sont parties, le solde reste en attente. Un appel est sans doute la prochaine étape.', 'gestion-atelier-cct' ),
			'items' => $balance_overdue,
		),
		array(
			'title' => __( 'Devis sans réponse', 'gestion-atelier-cct' ),
			'hint'  => '',
			'items' => $quote_overdue,
		),
		array(
			'title' => __( 'Virements en échéance aujourd’hui', 'gestion-atelier-cct' ),
			'hint'  => __( 'Sans réception du virement, l’annulation automatique tombera dans la journée.', 'gestion-atelier-cct' ),
			'items' => $bacs_expiring,
		),
	) );
}

/**
 * Une ligne du récap : référence + client + détail + lien fiche console.
 */
function gacct_lc_recap_line( $order, $revision_id, $detail ) {
	$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	$url  = $revision_id ? admin_url( 'admin.php?page=gacct-console&revision=' . $revision_id ) : $order->get_edit_order_url();

	return sprintf(
		'<a href="%s"><strong>%s</strong></a> (%s) : %s',
		esc_url( $url ),
		esc_html( $order->get_order_number() ),
		esc_html( $name ? $name : __( 'client inconnu', 'gestion-atelier-cct' ) ),
		esc_html( $detail )
	);
}
