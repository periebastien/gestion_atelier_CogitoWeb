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

	// Dossier incomplet : le passage en intervention (2→4 et 3→4) est bloqué
	// tant que tout n'est pas arrivé — déblocage avec motif obligatoire (CDC §4.4).
	if ( 4 === $new_state && ! empty( $prev['dossier_incomplet'] ) ) {
		$unlock = isset( $args['unlock_reason'] ) ? trim( sanitize_textarea_field( $args['unlock_reason'] ) ) : '';

		if ( '' === $unlock ) {
			return new WP_Error(
				'gacct_op_incomplete_locked',
				__( 'Dossier incomplet : des éléments attendus ne sont pas arrivés. Le passage en intervention nécessite un motif de déblocage.', 'gestion-atelier-cct' )
			);
		}

		$extra['dossier_incomplet']  = '';
		$extra['elements_manquants'] = '';
		$args['_unlock_note']        = $unlock;
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
		if ( ! empty( $args['_unlock_note'] ) ) {
			$message .= sprintf( ' — dossier incomplet débloqué, motif : %s', $args['_unlock_note'] );
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
 * Installe les champs de la console dans le CCT revision : colonnes SQL +
 * déclarations JetEngine (meta_fields du content-type), cache JetEngine vidé.
 * Idempotent, appelé par le setup versionné.
 */
function gacct_op_install_operator_field() {
	global $wpdb;

	$fields = array(
		'operateur_id'      => array(
			'sql'   => 'BIGINT(20) NULL',
			'type'  => 'number',
			'title' => 'Réalisé par (ID utilisateur)',
		),
		'dossier_incomplet' => array(
			'sql'   => "VARCHAR(1) NOT NULL DEFAULT ''",
			'type'  => 'text',
			'title' => 'Dossier incomplet (1 = éléments manquants)',
		),
		'elements_manquants' => array(
			'sql'   => 'LONGTEXT NULL',
			'type'  => 'textarea',
			'title' => 'Éléments manquants (JSON)',
		),
	);

	$rev_table = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_REVISION;

	foreach ( $fields as $name => $def ) {
		$column = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$rev_table} LIKE %s", $name ) );
		if ( ! $column ) {
			$wpdb->query( "ALTER TABLE {$rev_table} ADD COLUMN {$name} {$def['sql']}" );
		}
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

	$declared = wp_list_pluck( $meta_fields, 'name' );
	$added    = false;

	foreach ( $fields as $name => $def ) {
		if ( in_array( $name, $declared, true ) ) {
			continue;
		}

		$meta_fields[] = array(
			'type'            => $def['type'],
			'title'           => $def['title'],
			'name'            => $name,
			'object_type'     => 'field',
			'width'           => '25%',
			'options'         => array(),
			'repeater-fields' => array(),
			'id'              => wp_rand( 100000, 999999 ),
			'isNested'        => false,
			'options_source'  => 'manual',
			'is_required'     => false,
		);
		$added = true;
	}

	if ( ! $added ) {
		return;
	}

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

/* =============================================================================
 *  V2 — Réception de colis, dossiers incomplets, relance paiement manuelle
 * ============================================================================= */

/**
 * Check-list du contenu attendu d'un colis : les lignes de la commande
 * (hors frais de port). CDC §4.4.
 *
 * @return string[] Libellés attendus.
 */
function gacct_op_expected_items( $order ) {
	$items = array();

	if ( ! $order ) {
		return $items;
	}

	foreach ( $order->get_items() as $item ) {
		$name = trim( wp_strip_all_tags( $item->get_name() ) );

		if ( '' === $name ) {
			continue;
		}

		// Les frais de retour/port ne sont pas un contenu de colis
		// (produits « Frais de port », « Colis 2 kg »…).
		if ( preg_match( '/frais|port|exp[ée]dition|retour|^colis\b/iu', $name ) ) {
			continue;
		}

		$items[] = $name;
	}

	return apply_filters( 'gacct_op_expected_items', array_values( array_unique( $items ) ), $order );
}

/**
 * Éléments manquants d'une révision (champ JSON `elements_manquants`).
 *
 * @return string[]
 */
function gacct_op_missing_items( array $revision ) {
	if ( empty( $revision['dossier_incomplet'] ) || empty( $revision['elements_manquants'] ) ) {
		return array();
	}

	$missing = json_decode( (string) $revision['elements_manquants'], true );

	return is_array( $missing ) ? array_values( array_filter( array_map( 'strval', $missing ) ) ) : array();
}

/**
 * Réception d'un colis (CDC §4.4) — complète ou partielle.
 *
 * - Première réception (état 1) : passe en état 2 via la machine à états
 *   (email « voile réceptionnée » du workflow). Si des éléments manquent :
 *   dossier marqué incomplet + email « éléments manquants » au client.
 * - Complément (dossier déjà en état ≥ 2 et incomplet) : met à jour la liste ;
 *   quand plus rien ne manque, le dossier redevient complet (pas de nouvel
 *   email d'état). Un 2ᵉ email « éléments manquants » part si la liste change
 *   mais reste non vide.
 * - Dossier déjà réceptionné et complet : erreur « déjà réceptionné le … ».
 *
 * @param int      $revision_id ID du CCT revision.
 * @param string[] $missing     Libellés manquants (vide = tout est arrivé).
 * @return array|WP_Error { complete: bool, missing: string[], first: bool }
 */
function gacct_op_receive( $revision_id, array $missing = array() ) {
	$revision_id = absint( $revision_id );
	$missing     = array_values( array_filter( array_map( static function ( $label ) {
		return trim( sanitize_text_field( (string) $label ) );
	}, $missing ) ) );

	$revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

	if ( ! $revision ) {
		return new WP_Error( 'gacct_op_not_found', __( 'Dossier introuvable.', 'gestion-atelier-cct' ) );
	}

	$state = absint( $revision['etat_de_la_commande'] ?? 0 );
	$order = gacct_op_get_order_for_revision( $revision );

	if ( ! $order ) {
		return new WP_Error( 'gacct_op_no_order', __( 'Commande liée introuvable.', 'gestion-atelier-cct' ) );
	}

	$was_incomplete = ! empty( $revision['dossier_incomplet'] );

	// Re-scan d'un dossier complet déjà réceptionné.
	if ( $state >= 2 && ! $was_incomplete ) {
		$received_on = (string) $order->get_meta( '_gacct_reception_date' );

		return new WP_Error( 'gacct_op_already_received', sprintf(
			/* translators: %s: date de réception */
			__( 'Colis déjà réceptionné le %s.', 'gestion-atelier-cct' ),
			$received_on ? date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $received_on ) ) : __( '(date inconnue)', 'gestion-atelier-cct' )
		) );
	}

	if ( $state > 4 && $was_incomplete ) {
		// Sécurité : au-delà de l'intervention, plus de gestion de check-list.
		return new WP_Error( 'gacct_op_bad_state', __( 'Ce dossier est trop avancé pour une réception.', 'gestion-atelier-cct' ) );
	}

	$flag_fields = array(
		'dossier_incomplet'  => $missing ? '1' : '',
		'elements_manquants' => $missing ? wp_json_encode( $missing ) : '',
	);

	$first = ( $state < 2 );

	if ( $first ) {
		if ( 1 !== $state ) {
			return new WP_Error( 'gacct_op_bad_state', __( 'La réception n\'est possible qu\'à l\'état 1 (en attente de réception).', 'gestion-atelier-cct' ) );
		}

		// Transition 1→2 par la machine à états (email du workflow inclus).
		$result = gacct_op_change_state( $revision_id, 2, array( 'extra_fields' => $flag_fields ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order->update_meta_data( '_gacct_reception_date', current_time( 'mysql' ) );
		$order->save();
	} else {
		// Complément d'un dossier incomplet : pas de changement d'état.
		if ( ! jwcct_update_cct_item( JWCCT_CCT_REVISION, $revision_id, $flag_fields ) ) {
			return new WP_Error( 'gacct_op_update_failed', __( 'La mise à jour du dossier a échoué.', 'gestion-atelier-cct' ) );
		}
	}

	$previous_missing = gacct_op_missing_items( $revision );

	if ( $missing ) {
		gacct_op_add_signed_note( $order, sprintf(
			__( 'Réception partielle — éléments manquants : %s', 'gestion-atelier-cct' ),
			implode( ', ', $missing )
		) );

		// Email « éléments manquants » (au 1er marquage ou si la liste change).
		if ( $first || $missing !== $previous_missing ) {
			gacct_op_send_missing_items_email( $order, $missing );
		}
	} else {
		gacct_op_add_signed_note( $order, $first
			? __( 'Réception complète du colis', 'gestion-atelier-cct' )
			: __( 'Dossier complété : tous les éléments attendus sont arrivés', 'gestion-atelier-cct' ) );
	}

	return array(
		'complete' => empty( $missing ),
		'missing'  => $missing,
		'first'    => $first,
	);
}

/**
 * Email « éléments manquants » (template éditable `missing_items`,
 * page Paiements & relances), avec copie admin.
 */
function gacct_op_send_missing_items_email( $order, array $missing ) {
	$list = '<ul>';
	foreach ( $missing as $label ) {
		$list .= '<li><strong>' . esc_html( $label ) . '</strong></li>';
	}
	$list .= '</ul>';

	$address_parts = array_filter( array(
		get_option( 'woocommerce_store_address' ),
		get_option( 'woocommerce_store_address_2' ),
		trim( get_option( 'woocommerce_store_postcode', '' ) . ' ' . get_option( 'woocommerce_store_city', '' ) ),
	) );

	$sent = gacct_pay_send_email(
		$order->get_billing_email(),
		'missing_items',
		gacct_pay_email_variables( $order, array(
			'{missing_items}'    => $list,
			'{workshop_address}' => esc_html( implode( ', ', $address_parts ) ),
		) ),
		true
	);

	$order->add_order_note( $sent
		? sprintf( __( 'Email « éléments manquants » envoyé au client (%s).', 'gestion-atelier-cct' ), $order->get_billing_email() )
		: __( 'ERREUR : échec de l\'envoi de l\'email « éléments manquants ».', 'gestion-atelier-cct' ) );
	$order->save();

	return $sent;
}

/**
 * Relance de paiement manuelle depuis la fiche (CDC §4.3). Choisit le
 * template selon la situation de la commande ; pas d'anti-doublon (geste
 * volontaire de l'opérateur), toujours journalisée.
 *
 * @return array|WP_Error { template: string, to: string }
 */
function gacct_op_manual_payment_reminder( $order ) {
	if ( ! $order ) {
		return new WP_Error( 'gacct_op_no_order', __( 'Commande liée introuvable.', 'gestion-atelier-cct' ) );
	}

	$status = $order->get_status();

	if ( 'bacs' === $order->get_payment_method() && in_array( $status, array( 'on-hold', 'pending' ), true ) ) {
		$template = 'bacs_reminder';
	} elseif ( in_array( $status, array( 'pending', 'failed' ), true ) ) {
		$template = 'payment_failed';
	} else {
		return new WP_Error( 'gacct_op_nothing_to_remind', __( 'Aucun paiement en attente à relancer sur cette commande.', 'gestion-atelier-cct' ) );
	}

	$sent = gacct_pay_send_email(
		$order->get_billing_email(),
		$template,
		gacct_pay_email_variables( $order )
	);

	if ( ! $sent ) {
		return new WP_Error( 'gacct_op_send_failed', __( 'L\'envoi de la relance a échoué (wp_mail).', 'gestion-atelier-cct' ) );
	}

	gacct_op_add_signed_note( $order, sprintf(
		__( 'Relance de paiement envoyée manuellement (template %1$s) à %2$s', 'gestion-atelier-cct' ),
		$template,
		$order->get_billing_email()
	) );

	return array(
		'template' => $template,
		'to'       => $order->get_billing_email(),
	);
}

/* =============================================================================
 *  V3 — Planning atelier & replanification (CDC §4.5)
 * ============================================================================= */

/**
 * Capacité requise pour replanifier au-delà de l'état 3 (décision Bastien :
 * libre jusqu'à l'état 3 inclus, admins + motif ensuite).
 */
function gacct_op_reschedule_admin_cap() {
	return apply_filters( 'gacct_op_reschedule_admin_cap', 'manage_woocommerce' );
}

/**
 * Jours ouverts (calendrier_dispo) avec heures occupées, sur une plage.
 * Même sémantique que le dashboard : correspondance exacte des timestamps,
 * occupations publish liées à une révision publish (rel 11 → ici simplifié :
 * occupation portant un revision_id ou un order_id d'une révision publish).
 *
 * @return array[] { capacity_id, day_ts, capacity_hours, occupied_hours }
 */
function gacct_op_planning_capacities( $start_ts, $end_ts ) {
	global $wpdb;

	$cal = $wpdb->prefix . 'jet_cct_calendrier_dispo';
	$occ = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_OCCUPATION;

	return (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT c._ID AS capacity_id, CAST(c.date_jour AS UNSIGNED) AS day_ts,
			CAST(c.heures_totales_dispo AS DECIMAL(10,2)) AS capacity_hours,
			COALESCE(SUM(TIME_TO_SEC(o.duree_totale_commande) / 3600), 0) AS occupied_hours
		FROM {$cal} c
		LEFT JOIN {$occ} o
			ON CAST(o.date_reservee AS UNSIGNED) = CAST(c.date_jour AS UNSIGNED)
			AND o.cct_status = 'publish'
		WHERE c.cct_status = 'publish'
			AND CAST(c.date_jour AS UNSIGNED) >= %d
			AND CAST(c.date_jour AS UNSIGNED) < %d
		GROUP BY c._ID, c.date_jour, c.heures_totales_dispo
		ORDER BY day_ts ASC",
		$start_ts,
		$end_ts
	), ARRAY_A );
}

/**
 * Occupations d'une plage, jointes à leur révision (matériel, état).
 *
 * @return array[] Lignes occupation + colonnes révision préfixées rev_.
 */
function gacct_op_planning_occupations( $start_ts, $end_ts ) {
	global $wpdb;

	$occ = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_OCCUPATION;
	$rev = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_REVISION;

	return (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT o._ID AS occupation_id, CAST(o.date_reservee AS UNSIGNED) AS day_ts,
			o.duree_totale_commande, o.order_id,
			r._ID AS rev_id, r.etat_de_la_commande AS rev_etat, r.marque AS rev_marque,
			r.modele AS rev_modele, r.taille AS rev_taille, r.dossier_incomplet AS rev_incomplet
		FROM {$occ} o
		LEFT JOIN {$rev} r
			ON ( r._ID = o.revision_id OR ( o.order_id > 0 AND r.order_id = o.order_id ) )
			AND r.cct_status = 'publish'
		WHERE o.cct_status = 'publish'
			AND CAST(o.date_reservee AS UNSIGNED) >= %d
			AND CAST(o.date_reservee AS UNSIGNED) < %d
		GROUP BY o._ID
		ORDER BY day_ts ASC",
		$start_ts,
		$end_ts
	), ARRAY_A );
}

/**
 * Ligne calendrier_dispo (publish) couvrant un jour local Y-m-d, ou null.
 */
function gacct_op_day_capacity_row( $ymd ) {
	global $wpdb;

	$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );

	if ( ! $day ) {
		return null;
	}

	$start = $day->getTimestamp();
	$end   = $start + DAY_IN_SECONDS;

	$cal = $wpdb->prefix . 'jet_cct_calendrier_dispo';

	return $wpdb->get_row( $wpdb->prepare(
		"SELECT _ID, CAST(date_jour AS UNSIGNED) AS day_ts,
			CAST(heures_totales_dispo AS DECIMAL(10,2)) AS capacity_hours
		FROM {$cal}
		WHERE cct_status = 'publish'
			AND CAST(date_jour AS UNSIGNED) >= %d
			AND CAST(date_jour AS UNSIGNED) < %d
		ORDER BY day_ts ASC LIMIT 1",
		$start,
		$end
	), ARRAY_A );
}

/**
 * Replanification d'une occupation (CDC §4.5).
 *
 * Règle (décision Bastien 28/07/2026) : libre jusqu'à l'état 3 inclus ;
 * à partir de l'état 4, réservée aux admins avec motif obligatoire.
 * Contrôle de capacité : heures dispo du jour cible − occupations déjà
 * posées (hors celle déplacée) ≥ durée de l'occupation.
 *
 * @param int    $occupation_id ID du CCT occupation_atelier.
 * @param string $ymd           Jour cible (Y-m-d, doit être ouvert au calendrier).
 * @param array  $args          { reason: string (motif, obligatoire état ≥ 4),
 *                               notify: bool (email « créneau replanifié ») }
 * @return array|WP_Error { old_ts, new_ts, notified: bool }
 */
function gacct_op_reschedule( $occupation_id, $ymd, array $args = array() ) {
	$occupation_id = absint( $occupation_id );
	$reason        = isset( $args['reason'] ) ? trim( sanitize_textarea_field( $args['reason'] ) ) : '';
	$notify        = ! empty( $args['notify'] );

	$occupation = jwcct_get_cct_item( JWCCT_CCT_OCCUPATION, $occupation_id );

	if ( ! $occupation ) {
		return new WP_Error( 'gacct_op_not_found', __( 'Occupation introuvable.', 'gestion-atelier-cct' ) );
	}

	// Révision liée (par revision_id, sinon par order_id) — lecture SQL directe :
	// l'état doit être frais, pas servi par le cache d'objet JetEngine.
	global $wpdb;
	$rev_table = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_REVISION;
	$revision  = null;

	if ( ! empty( $occupation['revision_id'] ) ) {
		$revision = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$rev_table} WHERE _ID = %d LIMIT 1",
			absint( $occupation['revision_id'] )
		), ARRAY_A );
	}

	if ( ! $revision && ! empty( $occupation['order_id'] ) ) {
		$revision = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$rev_table} WHERE order_id = %d AND cct_status = 'publish' LIMIT 1",
			absint( $occupation['order_id'] )
		), ARRAY_A );
	}

	$state = $revision ? absint( $revision['etat_de_la_commande'] ?? 0 ) : 0;

	if ( $state >= 4 ) {
		if ( ! current_user_can( gacct_op_reschedule_admin_cap() ) ) {
			return new WP_Error( 'gacct_op_reschedule_locked', __( 'À partir de l\'état 4 (intervention en cours), la replanification est réservée aux administrateurs.', 'gestion-atelier-cct' ) );
		}
		if ( '' === $reason ) {
			return new WP_Error( 'gacct_op_reason_required', __( 'Un motif est obligatoire pour replanifier un dossier en intervention (état ≥ 4).', 'gestion-atelier-cct' ) );
		}
	}

	$target = gacct_op_day_capacity_row( $ymd );

	if ( ! $target ) {
		return new WP_Error( 'gacct_op_day_closed', __( 'Ce jour n\'est pas ouvert au calendrier de l\'atelier.', 'gestion-atelier-cct' ) );
	}

	$old_ts = absint( $occupation['date_reservee'] ?? 0 );
	$new_ts = (int) $target['day_ts'];

	if ( $old_ts === $new_ts ) {
		return new WP_Error( 'gacct_op_same_day', __( 'L\'occupation est déjà sur ce jour.', 'gestion-atelier-cct' ) );
	}

	// Contrôle de capacité sur le jour cible (hors l'occupation déplacée).
	$occ_table = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_OCCUPATION;
	$occupied  = (float) $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(SUM(TIME_TO_SEC(duree_totale_commande) / 3600), 0)
		FROM {$occ_table}
		WHERE cct_status = 'publish' AND CAST(date_reservee AS UNSIGNED) = %d AND _ID != %d",
		$new_ts,
		$occupation_id
	) );

	$duration_h = (float) $wpdb->get_var( $wpdb->prepare(
		'SELECT TIME_TO_SEC(%s) / 3600',
		(string) ( $occupation['duree_totale_commande'] ?? '00:00' )
	) );

	$available = (float) $target['capacity_hours'] - $occupied;

	if ( $duration_h > $available + 0.001 ) {
		return new WP_Error( 'gacct_op_no_capacity', sprintf(
			/* translators: 1: heures restantes, 2: durée demandée */
			__( 'Capacité insuffisante ce jour-là : %1$s h restantes pour une intervention de %2$s h.', 'gestion-atelier-cct' ),
			rtrim( rtrim( number_format( max( 0, $available ), 2, ',', ' ' ), '0' ), ',' ),
			rtrim( rtrim( number_format( $duration_h, 2, ',', ' ' ), '0' ), ',' )
		) );
	}

	if ( ! jwcct_update_cct_item( JWCCT_CCT_OCCUPATION, $occupation_id, array( 'date_reservee' => $new_ts ) ) ) {
		return new WP_Error( 'gacct_op_update_failed', __( 'La mise à jour de l\'occupation a échoué.', 'gestion-atelier-cct' ) );
	}

	$order    = ! empty( $occupation['order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( absint( $occupation['order_id'] ) ) : false;
	$date_fmt = get_option( 'date_format' );
	$old_str  = $old_ts ? wp_date( $date_fmt, $old_ts ) : __( '(aucune)', 'gestion-atelier-cct' );
	$new_str  = wp_date( $date_fmt, $new_ts );
	$notified = false;

	if ( $order ) {
		$message = sprintf( __( 'Créneau replanifié : %1$s → %2$s', 'gestion-atelier-cct' ), $old_str, $new_str );
		if ( '' !== $reason ) {
			$message .= sprintf( ' — motif : %s', $reason );
		}
		gacct_op_add_signed_note( $order, $message );

		if ( $notify ) {
			$notified = gacct_pay_send_email(
				$order->get_billing_email(),
				'rescheduled',
				gacct_pay_email_variables( $order, array(
					'{old_slot_date}' => $old_str,
					'{new_slot_date}' => $new_str,
				) )
			);

			$order->add_order_note( $notified
				? sprintf( __( 'Email « créneau replanifié » envoyé au client (%s).', 'gestion-atelier-cct' ), $order->get_billing_email() )
				: __( 'ERREUR : échec de l\'envoi de l\'email « créneau replanifié ».', 'gestion-atelier-cct' ) );
			$order->save();
		}
	}

	return array(
		'old_ts'           => $old_ts,
		'new_ts'           => $new_ts,
		'notified'         => (bool) $notified,
		'notify_requested' => (bool) ( $notify && $order ),
	);
}
