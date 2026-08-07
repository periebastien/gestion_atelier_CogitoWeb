<?php
/**
 * Console atelier — écran « Aujourd'hui » (CDC §4.1).
 *
 * Vue d'ACTION, 100 % rendue côté serveur : 4 cartes (arrivées attendues,
 * à traiter par l'atelier, en attente du client, paiements). Chaque ligne
 * mène à la fiche du dossier ; le seul JS (renvoi d'email) est porté par
 * operator.js via l'attribut data-op-action.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Libellé matériel court d'une révision : marque + modèle (+ taille).
 */
function gacct_op_today_material( array $revision ) {
	$parts = array_filter( array(
		trim( (string) ( $revision['marque'] ?? '' ) ),
		trim( (string) ( $revision['modele'] ?? '' ) ),
		trim( (string) ( $revision['taille'] ?? '' ) ),
	) );

	return $parts ? implode( ' ', $parts ) : __( 'Matériel non renseigné', 'gestion-atelier-cct' );
}

/**
 * Nom du client d'une commande.
 */
function gacct_op_today_client( $order ) {
	if ( ! $order ) {
		return __( 'Client inconnu', 'gestion-atelier-cct' );
	}

	$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

	return '' !== $name ? $name : $order->get_billing_email();
}

/**
 * Charge en lot les commandes WooCommerce des lignes affichées (pas de N+1).
 *
 * @param int[] $order_ids
 * @return array<int, WC_Order> Indexé par ID de commande.
 */
function gacct_op_today_orders( array $order_ids ) {
	$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );

	if ( ! $order_ids || ! function_exists( 'wc_get_order' ) ) {
		return array();
	}

	$orders = array();
	foreach ( array_map( 'wc_get_order', $order_ids ) as $order ) {
		if ( $order ) {
			$orders[ $order->get_id() ] = $order;
		}
	}

	return $orders;
}

/**
 * Référence de commande (AR-…) ou repli sur l'order_id du CCT.
 */
function gacct_op_today_reference( $order, $order_id = 0 ) {
	if ( $order ) {
		return $order->get_order_number();
	}

	return $order_id ? '#' . absint( $order_id ) : __( '(sans commande)', 'gestion-atelier-cct' );
}

/**
 * Ouvre / ferme une ligne : lien plein vers la fiche si $url, sinon div.
 */
function gacct_op_today_row_open( $url, $extra_class = '' ) {
	$class = trim( 'gacct-op-row ' . $extra_class );

	if ( $url ) {
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">';
	} else {
		echo '<div class="' . esc_attr( $class . ' no-link' ) . '">';
	}
}

function gacct_op_today_row_close( $url ) {
	echo $url ? '</a>' : '</div>';
}

/**
 * Phrase de bloc vide.
 */
function gacct_op_today_empty() {
	echo '<p class="gacct-op-empty">' . esc_html__( 'Rien à signaler.', 'gestion-atelier-cct' ) . '</p>';
}

/**
 * Écran « Aujourd'hui ».
 */
function gacct_op_render_today_screen() {
	global $wpdb;

	$labels = gacct_op_state_labels();
	$now    = time();

	/* ---------------------------------------------------------------------
	 * Collecte des données (chaque bloc est borné — pas de N+1 global).
	 * ------------------------------------------------------------------- */

	// Bloc 1 : état 1, créneau ≤ maintenant + 7 jours (créneaux passés inclus).
	$arrivals = array();
	$result   = gacct_op_query_interventions( array(
		'state'    => 1,
		'orderby'  => 'slot',
		'order'    => 'ASC',
		'per_page' => 50,
	) );

	foreach ( $result['items'] as $item ) {
		$slot = absint( $item['date_reservee'] ?? 0 );
		if ( ! $slot || $slot > $now + 7 * DAY_IN_SECONDS ) {
			continue;
		}
		$arrivals[] = $item;
		if ( count( $arrivals ) >= 10 ) {
			break;
		}
	}

	// Bloc 2 : sous-groupes états 2, 3, 5, 7.
	$workshop_groups = array(
		2 => array( 'title' => __( 'À diagnostiquer', 'gestion-atelier-cct' ), 'items' => array() ),
		3 => array( 'title' => __( 'Travaux à faire', 'gestion-atelier-cct' ), 'items' => array() ),
		5 => array( 'title' => __( 'Travaux à finir (décision devis rendue)', 'gestion-atelier-cct' ), 'items' => array() ),
		7 => array( 'title' => __( 'Colis à réexpédier', 'gestion-atelier-cct' ), 'items' => array() ),
	);

	foreach ( array_keys( $workshop_groups ) as $state ) {
		$result = gacct_op_query_interventions( array(
			'state'    => $state,
			'orderby'  => 'slot',
			'order'    => 'ASC',
			'per_page' => 10,
		) );
		$workshop_groups[ $state ]['items'] = $result['items'];
	}

	// Bloc 3 : états 4 et 6, les plus anciens (cct_modified) d'abord.
	$waiting = array();
	foreach ( array( 4, 6 ) as $state ) {
		$result  = gacct_op_query_interventions( array(
			'state'    => $state,
			'orderby'  => 'modified',
			'order'    => 'ASC',
			'per_page' => 10,
		) );
		$waiting = array_merge( $waiting, $result['items'] );
	}

	// Bloc 4a : commandes en attente de virement.
	$bacs_orders = function_exists( 'wc_get_orders' ) ? wc_get_orders( array(
		'status'         => array( 'on-hold', 'pending' ),
		'payment_method' => 'bacs',
		'limit'          => 20,
		'orderby'        => 'date',
		'order'          => 'ASC',
	) ) : array();

	// Révisions liées aux commandes bacs — une seule requête IN.
	$bacs_revisions = array();
	$bacs_ids       = array_map( static function ( $order ) {
		return $order->get_id();
	}, $bacs_orders );

	if ( $bacs_ids ) {
		$rev_table    = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_REVISION;
		$placeholders = implode( ',', array_fill( 0, count( $bacs_ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare(
			"SELECT _ID, order_id, etat_de_la_commande FROM {$rev_table} WHERE order_id IN ({$placeholders})",
			$bacs_ids
		), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$bacs_revisions[ (int) $row['order_id'] ] = $row;
		}
	}

	// Bloc 4b : commandes non finalisées du jour (hors bacs).
	$midnight        = ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
	$unpaid_orders   = array();
	$unpaid_fetched  = function_exists( 'wc_get_orders' ) ? wc_get_orders( array(
		'status'       => array( 'failed', 'pending' ),
		'date_created' => '>=' . $midnight,
		'limit'        => 20,
		'orderby'      => 'date',
		'order'        => 'DESC',
	) ) : array();

	foreach ( $unpaid_fetched as $order ) {
		if ( 'bacs' !== $order->get_payment_method() ) {
			$unpaid_orders[] = $order;
		}
	}

	// Chargement en lot des commandes des blocs 1 à 3.
	$needed_ids = array();
	foreach ( array_merge( $arrivals, $waiting ) as $item ) {
		$needed_ids[] = $item['order_id'] ?? 0;
	}
	foreach ( $workshop_groups as $group ) {
		foreach ( $group['items'] as $item ) {
			$needed_ids[] = $item['order_id'] ?? 0;
		}
	}
	$orders_by_id = gacct_op_today_orders( $needed_ids );

	$date_format = get_option( 'date_format' );
	$time_format = get_option( 'time_format' );
	$today_key   = (int) wp_date( 'Ymd' );

	/* ---------------------------------------------------------------------
	 * Rendu.
	 * ------------------------------------------------------------------- */

	echo '<div class="wrap gacct-op gacct-op-today">';
	echo '<h1>' . esc_html__( 'Aujourd\'hui', 'gestion-atelier-cct' ) . '</h1>';
	echo '<div class="gacct-op-today-grid">';

	/* ---- Bloc 1 : arrivées attendues -------------------------------- */
	echo '<section class="gacct-op-card gacct-op-today-block">';
	echo '<h2>' . esc_html__( 'Arrivées attendues (7 jours)', 'gestion-atelier-cct' ) . '</h2>';

	if ( ! $arrivals ) {
		gacct_op_today_empty();
	} else {
		echo '<div class="gacct-op-rows">';
		foreach ( $arrivals as $item ) {
			$order_id = absint( $item['order_id'] ?? 0 );
			$order    = $orders_by_id[ $order_id ] ?? null;
			$slot     = absint( $item['date_reservee'] ?? 0 );
			$deadline = $slot - DAY_IN_SECONDS;
			$is_late  = ( (int) wp_date( 'Ymd', $slot ) <= $today_key );

			gacct_op_today_row_open( gacct_op_console_url( absint( $item['_ID'] ) ), $is_late ? 'is-late' : '' );
			echo '<span class="gacct-op-row-ref">' . esc_html( gacct_op_today_reference( $order, $order_id ) ) . '</span>';
			echo '<span class="gacct-op-row-main">';
			echo '<strong>' . esc_html( gacct_op_today_client( $order ) ) . '</strong> ';
			echo '<span class="gacct-op-row-muted">' . esc_html( gacct_op_today_material( $item ) ) . '</span>';
			echo '</span>';
			echo '<span class="gacct-op-row-meta">';
			echo esc_html( sprintf(
				/* translators: %s: date limite de réception (veille du créneau) */
				__( 'Limite : %s', 'gestion-atelier-cct' ),
				wp_date( $date_format, $deadline )
			) );
			if ( $is_late ) {
				echo ' <span class="gacct-op-pill danger">' . esc_html__( 'en retard', 'gestion-atelier-cct' ) . '</span>';
			}
			if ( ! empty( $item['dossier_incomplet'] ) ) {
				echo ' <span class="gacct-op-pill danger">' . esc_html__( 'incomplet', 'gestion-atelier-cct' ) . '</span>';
			}
			// Expédition déclarée par le client (le lien de suivi est dans la fiche).
			$ship = function_exists( 'gacct_ship_info' ) ? gacct_ship_info( $item ) : null;
			if ( $ship ) {
				echo ' <span class="gacct-op-pill ok" title="' . esc_attr( trim( $ship['carrier_label'] . ' n° ' . $ship['number'] ) ) . '">'
					. esc_html__( 'colis annoncé', 'gestion-atelier-cct' ) . '</span>';
			}
			echo '</span>';
			gacct_op_today_row_close( true );
		}
		echo '</div>';
	}

	echo '<p class="gacct-op-see-all"><a href="' . esc_url( gacct_op_console_url( 0, array( 'view' => 'list', 'etat' => 1 ) ) ) . '">'
		. esc_html__( 'Tout voir (état 1)', 'gestion-atelier-cct' ) . '</a></p>';
	echo '</section>';

	/* ---- Bloc 2 : à traiter par l'atelier ---------------------------- */
	echo '<section class="gacct-op-card gacct-op-today-block">';
	echo '<h2>' . esc_html__( 'À traiter par l\'atelier', 'gestion-atelier-cct' ) . '</h2>';

	$has_workshop = false;
	foreach ( $workshop_groups as $state => $group ) {
		if ( ! $group['items'] ) {
			continue;
		}
		$has_workshop = true;

		echo '<h3 class="gacct-op-subtitle">' . esc_html( $group['title'] ) . '</h3>';
		echo '<div class="gacct-op-rows">';
		foreach ( $group['items'] as $item ) {
			$order_id = absint( $item['order_id'] ?? 0 );
			$order    = $orders_by_id[ $order_id ] ?? null;
			$slot     = absint( $item['date_reservee'] ?? 0 );

			gacct_op_today_row_open( gacct_op_console_url( absint( $item['_ID'] ) ) );
			echo '<span class="gacct-op-row-ref">' . esc_html( gacct_op_today_reference( $order, $order_id ) ) . '</span>';
			echo '<span class="gacct-op-row-main">';
			echo '<strong>' . esc_html( gacct_op_today_client( $order ) ) . '</strong> ';
			echo '<span class="gacct-op-row-muted">' . esc_html( gacct_op_today_material( $item ) ) . '</span>';
			echo '</span>';
			echo '<span class="gacct-op-row-meta">';
			if ( $slot ) {
				echo esc_html( wp_date( $date_format, $slot ) ) . ' ';
			}
			echo '<span class="gacct-op-badge etat-' . esc_attr( (string) $state ) . '">' . esc_html( $labels[ $state ] ?? (string) $state ) . '</span>';
			if ( gacct_hold_info( $item )['active'] ) {
				echo ' <span class="gacct-op-badge gacct-op-badge-hold" title="' . esc_attr( gacct_hold_info( $item )['motif'] ) . '">' . esc_html__( 'En attente', 'gestion-atelier-cct' ) . '</span>';
			}
			echo '</span>';
			gacct_op_today_row_close( true );
		}
		echo '</div>';
	}

	if ( ! $has_workshop ) {
		gacct_op_today_empty();
	}

	// Dossiers mis en PAUSE par l'atelier (drapeau en_attente) : gardés sous
	// les yeux pour qu'aucun ne soit oublié, avec leur motif.
	$held = gacct_op_query_interventions( array(
		'hold'     => true,
		'orderby'  => 'modified',
		'order'    => 'ASC',
		'per_page' => 10,
	) );

	if ( $held['items'] ) {
		$held_orders = gacct_op_today_orders( wp_list_pluck( $held['items'], 'order_id' ) );

		echo '<h3 class="gacct-op-subtitle">' . esc_html__( 'Dossiers en attente (mis en pause)', 'gestion-atelier-cct' ) . '</h3>';
		echo '<div class="gacct-op-rows">';
		foreach ( $held['items'] as $item ) {
			$order_id = absint( $item['order_id'] ?? 0 );
			$order    = $held_orders[ $order_id ] ?? null;
			$motif    = trim( (string) ( $item['attente_motif'] ?? '' ) );

			gacct_op_today_row_open( gacct_op_console_url( absint( $item['_ID'] ) ) );
			echo '<span class="gacct-op-row-ref">' . esc_html( gacct_op_today_reference( $order, $order_id ) ) . '</span>';
			echo '<span class="gacct-op-row-main">';
			echo '<strong>' . esc_html( gacct_op_today_client( $order ) ) . '</strong> ';
			echo '<span class="gacct-op-row-muted">' . esc_html( $motif ? $motif : gacct_op_today_material( $item ) ) . '</span>';
			echo '</span>';
			echo '<span class="gacct-op-row-meta">';
			echo '<span class="gacct-op-badge etat-' . esc_attr( (string) absint( $item['etat_de_la_commande'] ?? 0 ) ) . '">' . esc_html( $labels[ absint( $item['etat_de_la_commande'] ?? 0 ) ] ?? '' ) . '</span>';
			echo ' <span class="gacct-op-badge gacct-op-badge-hold">' . esc_html__( 'En attente', 'gestion-atelier-cct' ) . '</span>';
			echo '</span>';
			gacct_op_today_row_close( true );
		}
		echo '</div>';
		echo '<p class="gacct-op-see-all"><a href="' . esc_url( gacct_op_console_url( 0, array( 'view' => 'list', 'attente' => 1 ) ) ) . '">'
			. esc_html__( 'Tout voir (dossiers en attente)', 'gestion-atelier-cct' ) . '</a></p>';
	}
	echo '</section>';

	/* ---- Bloc 3 : en attente du client ------------------------------- */
	echo '<section class="gacct-op-card gacct-op-today-block">';
	echo '<h2>' . esc_html__( 'En attente du client', 'gestion-atelier-cct' ) . '</h2>';
	echo '<div class="gacct-op-feedback"></div>';

	if ( ! $waiting ) {
		gacct_op_today_empty();
	} else {
		echo '<div class="gacct-op-rows">';
		foreach ( $waiting as $item ) {
			$order_id = absint( $item['order_id'] ?? 0 );
			$order    = $orders_by_id[ $order_id ] ?? null;
			$state    = absint( $item['etat_de_la_commande'] ?? 0 );
			$modified = strtotime( (string) ( $item['cct_modified'] ?? '' ) );

			echo '<div class="gacct-op-row has-action">';
			echo '<a class="gacct-op-row-link" href="' . esc_url( gacct_op_console_url( absint( $item['_ID'] ) ) ) . '">';
			echo '<span class="gacct-op-row-ref">' . esc_html( gacct_op_today_reference( $order, $order_id ) ) . '</span>';
			echo '<span class="gacct-op-row-main"><strong>' . esc_html( gacct_op_today_client( $order ) ) . '</strong></span>';
			echo '<span class="gacct-op-row-meta">';
			echo '<span class="gacct-op-badge etat-' . esc_attr( (string) $state ) . '">' . esc_html( $labels[ $state ] ?? (string) $state ) . '</span> ';
			if ( gacct_hold_info( $item )['active'] ) {
				echo '<span class="gacct-op-badge gacct-op-badge-hold" title="' . esc_attr( gacct_hold_info( $item )['motif'] ) . '">' . esc_html__( 'En attente', 'gestion-atelier-cct' ) . '</span> ';
			}
			if ( $modified ) {
				echo esc_html( sprintf(
					/* translators: %s: durée (ex. « 3 jours ») */
					__( 'depuis %s', 'gestion-atelier-cct' ),
					human_time_diff( $modified, $now )
				) );
			}
			echo '</span>';
			echo '</a>';
			echo '<button type="button" class="button button-small" data-op-action="resend-email" data-revision-id="' . esc_attr( (string) absint( $item['_ID'] ) ) . '">'
				. esc_html__( 'Renvoyer l\'email', 'gestion-atelier-cct' ) . '</button>';
			echo '</div>';
		}
		echo '</div>';
	}
	echo '</section>';

	/* ---- Bloc 4 : paiements ------------------------------------------ */
	echo '<section class="gacct-op-card gacct-op-today-block">';
	echo '<h2>' . esc_html__( 'Paiements', 'gestion-atelier-cct' ) . '</h2>';

	echo '<h3 class="gacct-op-subtitle">' . esc_html__( 'En attente de virement', 'gestion-atelier-cct' ) . '</h3>';
	if ( ! $bacs_orders ) {
		gacct_op_today_empty();
	} else {
		echo '<div class="gacct-op-rows">';
		foreach ( $bacs_orders as $order ) {
			$revision  = $bacs_revisions[ $order->get_id() ] ?? null;
			$url       = $revision ? gacct_op_console_url( absint( $revision['_ID'] ) ) : '';
			$deadlines = function_exists( 'gacct_pay_order_deadlines' ) ? gacct_pay_order_deadlines( $order ) : array();
			$cancel_ts = absint( $deadlines['cancel'] ?? 0 );
			$days_left = $cancel_ts ? max( 0, (int) ceil( ( $cancel_ts - $now ) / DAY_IN_SECONDS ) ) : null;

			gacct_op_today_row_open( $url );
			echo '<span class="gacct-op-row-ref">' . esc_html( $order->get_order_number() ) . '</span>';
			echo '<span class="gacct-op-row-main"><strong>' . esc_html( gacct_op_today_client( $order ) ) . '</strong></span>';
			echo '<span class="gacct-op-row-meta">';
			if ( null !== $days_left ) {
				$pill_class = ( $days_left <= 1 ) ? 'gacct-op-pill danger' : 'gacct-op-pill';
				echo '<span class="' . esc_attr( $pill_class ) . '">' . esc_html( sprintf(
					/* translators: %d: jours restants avant annulation */
					__( 'J-%d', 'gestion-atelier-cct' ),
					$days_left
				) ) . '</span>';
			}
			echo '</span>';
			gacct_op_today_row_close( $url );
		}
		echo '</div>';
	}

	echo '<h3 class="gacct-op-subtitle">' . esc_html__( 'Commandes non finalisées du jour', 'gestion-atelier-cct' ) . '</h3>';
	if ( ! $unpaid_orders ) {
		gacct_op_today_empty();
	} else {
		echo '<div class="gacct-op-rows">';
		foreach ( $unpaid_orders as $order ) {
			$created = $order->get_date_created();

			echo '<div class="gacct-op-row no-link">';
			echo '<span class="gacct-op-row-ref">' . esc_html( $order->get_order_number() ) . '</span>';
			echo '<span class="gacct-op-row-main"><strong>' . esc_html( gacct_op_today_client( $order ) ) . '</strong> ';
			echo '<span class="gacct-op-row-muted">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span></span>';
			echo '<span class="gacct-op-row-meta">' . esc_html( $created ? $created->date_i18n( $time_format ) : '' ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}
	echo '</section>';

	echo '</div>'; // .gacct-op-today-grid
	echo '</div>'; // .wrap
}
