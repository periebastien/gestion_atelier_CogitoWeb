<?php
/**
 * Console atelier — cœur métier : machine à états, journalisation,
 * requête de la file des interventions, champ CCT operateur_id.
 *
 * ⚠ Piège documenté (CDC §3) : jwcct_update_cct_item() écrit via
 * $content_type->db->update(), qui ne déclenche PAS le hook JetEngine
 * `updated-item/revision` (il n'est émis que par l'item-handler JE).
 * Tout changement d'état passe donc par gacct_op_change_state(), qui
 * déclenche elle-même le do_action pour que les emails du workflow partent.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Libellés des 8 états (0–7), alignés sur le tracker client.
 */
function gacct_op_state_labels() {
	return apply_filters( 'gacct_op_state_labels', array(
		0 => __( 'En attente de paiement', 'gestion-atelier-cct' ),
		1 => __( 'En attente de réception', 'gestion-atelier-cct' ),
		2 => __( 'Voile réceptionnée', 'gestion-atelier-cct' ),
		3 => __( 'Devis à valider', 'gestion-atelier-cct' ),
		4 => __( 'Intervention en cours', 'gestion-atelier-cct' ),
		5 => __( 'Solde à payer', 'gestion-atelier-cct' ),
		6 => __( 'Paiement validé', 'gestion-atelier-cct' ),
		7 => __( 'Révision terminée', 'gestion-atelier-cct' ),
	) );
}

/**
 * Transitions opérateur autorisées (CDC §5). Clé = état de départ,
 * valeur = [ état d'arrivée => libellé d'action ].
 */
function gacct_op_allowed_transitions() {
	return apply_filters( 'gacct_op_allowed_transitions', array(
		1 => array( 2 => __( 'Confirmer la réception', 'gestion-atelier-cct' ) ),
		2 => array(
			3 => __( 'Envoyer le devis complémentaire', 'gestion-atelier-cct' ),
			4 => __( 'Pas de supplément, lancer l\'intervention', 'gestion-atelier-cct' ),
		),
		4 => array( 5 => __( 'Intervention terminée, demander le solde', 'gestion-atelier-cct' ) ),
		6 => array( 7 => __( 'Déposer le rapport + clôturer', 'gestion-atelier-cct' ) ),
	) );
}

/**
 * Transitions pilotées par le client, forçables par un opérateur avec motif (CDC §5).
 */
function gacct_op_forceable_transitions() {
	return apply_filters( 'gacct_op_forceable_transitions', array(
		3 => array( 4 => __( 'Forcer la validation du devis', 'gestion-atelier-cct' ) ),
		5 => array( 6 => __( 'Forcer le paiement du solde', 'gestion-atelier-cct' ) ),
	) );
}

/**
 * États dont l'email de notification peut être renvoyé (état → état simulé de départ).
 */
function gacct_op_resendable_states() {
	return array( 3 => 2, 5 => 4 );
}

/**
 * Note de commande signée par l'opérateur courant.
 */
function gacct_op_add_signed_note( $order, $message ) {
	$user   = wp_get_current_user();
	$author = $user && $user->exists() ? sprintf( '%s (%s)', $user->display_name, $user->user_login ) : __( 'opérateur inconnu', 'gestion-atelier-cct' );

	$order->add_order_note( sprintf( '[Console atelier] %s — par %s', $message, $author ) );
	$order->save();
}

/**
 * Commande WooCommerce liée à une révision (colonne order_id du CCT).
 */
function gacct_op_get_order_for_revision( array $revision ) {
	$order_id = isset( $revision['order_id'] ) ? absint( $revision['order_id'] ) : 0;

	return ( $order_id && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : false;
}

/**
 * FONCTION SERVEUR CENTRALE de changement d'état (CDC §3).
 *
 * @param int   $revision_id ID du CCT revision.
 * @param int   $new_state   État cible (0–7).
 * @param array $args        {
 *     @type bool   $force        Transition « forcer » (client) — motif obligatoire.
 *     @type string $reason       Motif (obligatoire si force).
 *     @type array  $extra_fields Champs CCT additionnels (rapport_pdf, operateur_id…).
 * }
 * @return array|WP_Error  [ 'old' => int, 'new' => int, 'label' => string ]
 */
function gacct_op_change_state( $revision_id, $new_state, array $args = array() ) {
	$revision_id = absint( $revision_id );
	$new_state   = absint( $new_state );
	$force       = ! empty( $args['force'] );
	$reason      = isset( $args['reason'] ) ? trim( sanitize_textarea_field( $args['reason'] ) ) : '';
	$extra       = isset( $args['extra_fields'] ) ? (array) $args['extra_fields'] : array();

	$prev = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

	if ( ! $prev ) {
		return new WP_Error( 'gacct_op_not_found', __( 'Dossier introuvable.', 'gestion-atelier-cct' ) );
	}

	$old_state = absint( $prev['etat_de_la_commande'] ?? 0 );

	if ( $force ) {
		$map = gacct_op_forceable_transitions();
		if ( empty( $map[ $old_state ][ $new_state ] ) ) {
			return new WP_Error( 'gacct_op_bad_transition', __( 'Cette transition ne peut pas être forcée.', 'gestion-atelier-cct' ) );
		}
		if ( '' === $reason ) {
			return new WP_Error( 'gacct_op_reason_required', __( 'Un motif est obligatoire pour forcer une transition.', 'gestion-atelier-cct' ) );
		}
		$action_label = $map[ $old_state ][ $new_state ];
	} else {
		$map = gacct_op_allowed_transitions();
		if ( empty( $map[ $old_state ][ $new_state ] ) ) {
			return new WP_Error( 'gacct_op_bad_transition', sprintf(
				/* translators: 1: old state, 2: new state */
				__( 'Transition %1$d → %2$d non autorisée depuis la console.', 'gestion-atelier-cct' ),
				$old_state,
				$new_state
			) );
		}
		$action_label = $map[ $old_state ][ $new_state ];
	}

	// Clôture 6→7 : rapport PDF obligatoire, « réalisé par » automatique (CDC §2.1).
	if ( 7 === $new_state ) {
		$rapport = $extra['rapport_pdf'] ?? ( $prev['rapport_pdf'] ?? '' );
		if ( empty( $rapport ) ) {
			return new WP_Error( 'gacct_op_report_required', __( 'Le rapport PDF est obligatoire pour clôturer.', 'gestion-atelier-cct' ) );
		}
		if ( empty( $extra['operateur_id'] ) ) {
			$extra['operateur_id'] = get_current_user_id();
		}
	}

	$fields = array_merge( $extra, array( 'etat_de_la_commande' => (string) $new_state ) );

	if ( ! jwcct_update_cct_item( JWCCT_CCT_REVISION, $revision_id, $fields ) ) {
		return new WP_Error( 'gacct_op_update_failed', __( 'La mise à jour du dossier a échoué.', 'gestion-atelier-cct' ) );
	}

	// Déclenche le workflow (emails, lien devis, kojito, PDF) — voir en-tête du fichier.
	$new_item = array_merge( $prev, $fields, array( '_ID' => $revision_id ) );
	do_action( 'jet-engine/custom-content-types/updated-item/revision', $new_item, $prev, null );

	$order = gacct_op_get_order_for_revision( $prev );

	if ( $order ) {
		$message = sprintf( '%s (état %d → %d)', $action_label, $old_state, $new_state );
		if ( $force ) {
			$message .= sprintf( ' — FORCÉ, motif : %s', $reason );
		}
		gacct_op_add_signed_note( $order, $message );
	}

	return array(
		'old'   => $old_state,
		'new'   => $new_state,
		'label' => $action_label,
	);
}

/**
 * Renvoi de l'email d'un état « en attente du client » (3 ou 5), sans toucher
 * à l'état : on rejoue le hook avec l'état précédent simulé. État 3 : régénère
 * un lien de validation sécurisé. État 5 : rejoue kojito_declencher_paiement_solde
 * (idempotent : repose le total au solde restant).
 */
function gacct_op_resend_state_email( $revision_id ) {
	$revision_id = absint( $revision_id );
	$item        = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

	if ( ! $item ) {
		return new WP_Error( 'gacct_op_not_found', __( 'Dossier introuvable.', 'gestion-atelier-cct' ) );
	}

	$state      = absint( $item['etat_de_la_commande'] ?? 0 );
	$resendable = gacct_op_resendable_states();

	if ( ! isset( $resendable[ $state ] ) ) {
		return new WP_Error( 'gacct_op_not_resendable', __( 'Aucun email à renvoyer pour cet état.', 'gestion-atelier-cct' ) );
	}

	$fake_prev = $item;
	$fake_prev['etat_de_la_commande'] = (string) $resendable[ $state ];

	do_action( 'jet-engine/custom-content-types/updated-item/revision', $item, $fake_prev, null );

	$order = gacct_op_get_order_for_revision( $item );

	if ( $order ) {
		gacct_op_add_signed_note( $order, sprintf( __( 'Renvoi de l\'email de l\'état %d', 'gestion-atelier-cct' ), $state ) );
	}

	return array( 'state' => $state );
}

/**
 * « Réalisé par » : liste des utilisateurs ayant la capacité gacct_operate.
 */
function gacct_op_operator_choices() {
	$users = get_users( array(
		'capability' => GACCT_OP_CAP,
		'fields'     => array( 'ID', 'display_name' ),
		'orderby'    => 'display_name',
	) );

	$choices = array();
	foreach ( $users as $user ) {
		$choices[ (int) $user->ID ] = $user->display_name;
	}

	return $choices;
}

/**
 * Nom affiché d'un opérateur (résolu au rendu, CDC §2.1).
 */
function gacct_op_operator_name( $operator_id ) {
	$operator_id = absint( $operator_id );

	if ( ! $operator_id ) {
		return '';
	}

	$user = get_userdata( $operator_id );
	$name = $user ? $user->display_name : sprintf( __( 'utilisateur #%d supprimé', 'gestion-atelier-cct' ), $operator_id );

	return apply_filters( 'gacct_operator_public_name', $name, $operator_id );
}

/**
 * File des interventions : requête jointe revision + occupation, paginée,
 * filtrable — une seule requête SQL, pas de N+1 (CDC §3).
 *
 * @param array $args { state:int|null, search:string, orderby:string(slot|created|modified),
 *                      order:string(ASC|DESC), paged:int, per_page:int, operator:int }
 * @return array { items: array[], total: int, pages: int, counts: array<int,int> }
 */
function gacct_op_query_interventions( array $args = array() ) {
	global $wpdb;

	$defaults = array(
		'state'    => null,
		'search'   => '',
		'orderby'  => 'slot',
		'order'    => 'ASC',
		'paged'    => 1,
		'per_page' => 20,
		'operator' => 0,
	);
	$args = array_merge( $defaults, $args );

	$rev_table = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_REVISION;
	$occ_table = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_OCCUPATION;

	$where  = array( "r.cct_status = 'publish'" );
	$params = array();

	if ( null !== $args['state'] && '' !== $args['state'] ) {
		$where[]  = 'CAST(r.etat_de_la_commande AS UNSIGNED) = %d';
		$params[] = absint( $args['state'] );
	}

	if ( $args['operator'] ) {
		$where[]  = 'r.operateur_id = %d';
		$params[] = absint( $args['operator'] );
	}

	$search = trim( (string) $args['search'] );

	if ( '' !== $search ) {
		$like       = '%' . $wpdb->esc_like( $search ) . '%';
		$search_sql = '(r.marque LIKE %s OR r.modele LIKE %s OR r.numero_de_serie LIKE %s OR r.couleur LIKE %s';
		$search_params = array( $like, $like, $like, $like );

		// Référence AR-2026-1621, ou juste un numéro de commande.
		if ( preg_match( '/(\d+)\s*$/', $search, $m ) ) {
			$search_sql   .= ' OR r.order_id = %d';
			$search_params[] = absint( $m[1] );
		}

		// Recherche client (nom/email) via WooCommerce → IDs de commandes.
		$order_ids = ( function_exists( 'wc_order_search' ) && strlen( $search ) >= 3 ) ? wc_order_search( $search ) : array();
		$order_ids = array_slice( array_map( 'absint', (array) $order_ids ), 0, 100 );

		if ( $order_ids ) {
			$search_sql .= ' OR r.order_id IN (' . implode( ',', $order_ids ) . ')';
		}

		$where[] = $search_sql . ')';
		$params  = array_merge( $params, $search_params );
	}

	$where_sql = implode( ' AND ', $where );

	$order_dir = ( 'DESC' === strtoupper( $args['order'] ) ) ? 'DESC' : 'ASC';
	$orderby_map = array(
		'slot'     => 'o.date_reservee',
		'created'  => 'r.cct_created',
		'modified' => 'r.cct_modified',
	);
	$orderby_sql = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : $orderby_map['slot'];

	$per_page = max( 1, absint( $args['per_page'] ) );
	$paged    = max( 1, absint( $args['paged'] ) );
	$offset   = ( $paged - 1 ) * $per_page;

	$join = "LEFT JOIN {$occ_table} o ON ( o.revision_id = r._ID OR ( r.order_id > 0 AND o.order_id = r.order_id ) )";

	$sql = "SELECT SQL_CALC_FOUND_ROWS r.*, o.date_reservee, o.duree_totale_commande, o._ID AS occupation_id
		FROM {$rev_table} r
		{$join}
		WHERE {$where_sql}
		GROUP BY r._ID
		ORDER BY {$orderby_sql} {$order_dir}, r._ID {$order_dir}
		LIMIT %d OFFSET %d";

	$params_page = array_merge( $params, array( $per_page, $offset ) );
	$items       = $wpdb->get_results( $wpdb->prepare( $sql, $params_page ), ARRAY_A );
	$total       = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );

	// Compteurs par état (onglets), même périmètre hors filtre d'état.
	$counts_where  = array( "r.cct_status = 'publish'" );
	$counts_params = array();

	if ( $args['operator'] ) {
		$counts_where[]  = 'r.operateur_id = %d';
		$counts_params[] = absint( $args['operator'] );
	}

	$counts_sql = "SELECT CAST(r.etat_de_la_commande AS UNSIGNED) AS etat, COUNT(*) AS n
		FROM {$rev_table} r WHERE " . implode( ' AND ', $counts_where ) . ' GROUP BY etat';
	$counts_sql = $counts_params ? $wpdb->prepare( $counts_sql, $counts_params ) : $counts_sql;

	$counts = array();
	foreach ( (array) $wpdb->get_results( $counts_sql, ARRAY_A ) as $row ) {
		$counts[ (int) $row['etat'] ] = (int) $row['n'];
	}

	return array(
		'items'  => (array) $items,
		'total'  => $total,
		'pages'  => (int) ceil( $total / $per_page ),
		'counts' => $counts,
	);
}

/**
 * Installe le champ operateur_id : colonne SQL + déclaration JetEngine
 * (meta_fields du content-type revision), cache JetEngine vidé.
 */
function gacct_op_install_operator_field() {
	global $wpdb;

	$rev_table = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_REVISION;

	$column = $wpdb->get_results( $wpdb->prepare(
		"SHOW COLUMNS FROM {$rev_table} LIKE %s",
		'operateur_id'
	) );

	if ( ! $column ) {
		$wpdb->query( "ALTER TABLE {$rev_table} ADD COLUMN operateur_id BIGINT(20) NULL" );
	}

	$cct_row = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, meta_fields FROM {$wpdb->prefix}jet_post_types WHERE slug = %s AND status = 'content-type'",
		JWCCT_CCT_REVISION
	), ARRAY_A );

	if ( ! $cct_row ) {
		return;
	}

	$meta_fields = maybe_unserialize( $cct_row['meta_fields'] );

	if ( ! is_array( $meta_fields ) ) {
		return;
	}

	foreach ( $meta_fields as $field ) {
		if ( isset( $field['name'] ) && 'operateur_id' === $field['name'] ) {
			return;
		}
	}

	$meta_fields[] = array(
		'type'            => 'number',
		'title'           => 'Réalisé par (ID utilisateur)',
		'name'            => 'operateur_id',
		'object_type'     => 'field',
		'width'           => '25%',
		'options'         => array(),
		'repeater-fields' => array(),
		'id'              => wp_rand( 100000, 999999 ),
		'isNested'        => false,
		'options_source'  => 'manual',
		'is_required'     => false,
	);

	$wpdb->update(
		$wpdb->prefix . 'jet_post_types',
		array( 'meta_fields' => serialize( $meta_fields ) ),
		array( 'id' => $cct_row['id'] )
	);

	$cache_table = $wpdb->prefix . 'jet_cache';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache_table ) ) ) {
		$wpdb->query( "DELETE FROM {$cache_table}" );
	}
	wp_cache_flush();
}
