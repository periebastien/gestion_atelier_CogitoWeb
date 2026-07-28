<?php
/**
 * Console atelier — écran « File des interventions » (liste maître, CDC §4.2).
 *
 * Rendu 100 % serveur : filtres, tri et pagination sont des liens GET vers
 * admin.php?page=gacct-console. Aucun JS requis.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lit et assainit les paramètres GET de la liste.
 */
function gacct_op_list_current_args() {
	$state = null;
	if ( isset( $_GET['etat'] ) && '' !== $_GET['etat'] ) {
		$state = absint( wp_unslash( $_GET['etat'] ) );
		$state = ( $state >= 0 && $state <= 8 ) ? $state : null;
	}

	$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'slot';
	if ( ! in_array( $orderby, array( 'slot', 'created', 'modified' ), true ) ) {
		$orderby = 'slot';
	}

	$order = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'ASC';
	$order = ( 'DESC' === $order ) ? 'DESC' : 'ASC';

	return array(
		'state'    => $state,
		'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		'orderby'  => $orderby,
		'order'    => $order,
		'paged'    => isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1,
		'operator' => isset( $_GET['operateur'] ) ? absint( wp_unslash( $_GET['operateur'] ) ) : 0,
	);
}

/**
 * URL de la liste conservant les args courants, surchargés par $overrides.
 * Une valeur '' ou null retire le paramètre.
 */
function gacct_op_list_url( array $current, array $overrides = array() ) {
	$map = array(
		'view'      => 'list',
		'etat'      => $current['state'],
		's'         => $current['search'],
		'orderby'   => $current['orderby'],
		'order'     => $current['order'],
		'paged'     => ( $current['paged'] > 1 ) ? $current['paged'] : null,
		'operateur' => $current['operator'] ? $current['operator'] : null,
	);

	$args = array();
	foreach ( array_merge( $map, $overrides ) as $key => $value ) {
		if ( null !== $value && '' !== $value ) {
			$args[ $key ] = $value;
		}
	}

	return gacct_op_console_url( 0, $args );
}

/**
 * Libellé de paiement d'une commande (avec cas « En attente de virement »).
 */
function gacct_op_list_payment_label( $order ) {
	$status = $order->get_status();

	if ( 'bacs' === $order->get_payment_method() && in_array( $status, array( 'on-hold', 'pending' ), true ) ) {
		return __( 'En attente de virement', 'gestion-atelier-cct' );
	}

	return wc_get_order_status_name( $status );
}

/**
 * Écran liste : file des interventions.
 */
function gacct_op_render_list_screen() {
	$current = gacct_op_list_current_args();
	$labels  = gacct_op_state_labels();

	$result = gacct_op_query_interventions( array(
		'state'    => $current['state'],
		'search'   => $current['search'],
		'orderby'  => $current['orderby'],
		'order'    => $current['order'],
		'paged'    => $current['paged'],
		'operator' => $current['operator'],
	) );

	$items  = $result['items'];
	$counts = $result['counts'];

	// Commandes de la page courante en un lot (pas de N+1).
	$order_ids = array();
	foreach ( $items as $item ) {
		$oid = absint( $item['order_id'] ?? 0 );
		if ( $oid ) {
			$order_ids[ $oid ] = $oid;
		}
	}

	$orders = array();
	if ( $order_ids && function_exists( 'wc_get_order' ) ) {
		foreach ( array_map( 'wc_get_order', array_values( $order_ids ) ) as $order ) {
			if ( $order ) {
				$orders[ $order->get_id() ] = $order;
			}
		}
	}

	echo '<div class="wrap gacct-op gacct-op-list">';

	// 1. Titre + recherche.
	echo '<div class="gacct-op-list-head">';
	echo '<h1>' . esc_html__( 'Interventions', 'gestion-atelier-cct' ) . '</h1>';

	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="gacct-op-list-search" role="search">';
	echo '<input type="hidden" name="page" value="' . esc_attr( GACCT_OP_MENU_SLUG ) . '">';
	echo '<input type="hidden" name="view" value="list">';
	if ( null !== $current['state'] ) {
		echo '<input type="hidden" name="etat" value="' . esc_attr( $current['state'] ) . '">';
	}
	if ( $current['operator'] ) {
		echo '<input type="hidden" name="operateur" value="' . esc_attr( $current['operator'] ) . '">';
	}
	echo '<input type="hidden" name="orderby" value="' . esc_attr( $current['orderby'] ) . '">';
	echo '<input type="hidden" name="order" value="' . esc_attr( $current['order'] ) . '">';
	echo '<input type="search" name="s" value="' . esc_attr( $current['search'] ) . '" placeholder="' . esc_attr__( 'Référence, client, marque, n° de série…', 'gestion-atelier-cct' ) . '">';
	echo '<button type="submit" class="button gacct-op-btn">' . esc_html__( 'Rechercher', 'gestion-atelier-cct' ) . '</button>';
	echo '</form>';
	echo '</div>';

	// 2. Onglets d'états.
	$total_all = array_sum( $counts );

	echo '<nav class="gacct-op-list-tabs" aria-label="' . esc_attr__( 'Filtrer par état', 'gestion-atelier-cct' ) . '">';

	$tab_class = ( null === $current['state'] ) ? 'gacct-op-list-tab is-active' : 'gacct-op-list-tab';
	echo '<a class="' . esc_attr( $tab_class ) . '" href="' . esc_url( gacct_op_list_url( $current, array( 'etat' => null, 'paged' => null ) ) ) . '">'
		. esc_html__( 'Tous', 'gestion-atelier-cct' ) . ' <span class="gacct-op-list-count">' . esc_html( $total_all ) . '</span></a>';

	foreach ( $labels as $state => $label ) {
		$n     = isset( $counts[ $state ] ) ? (int) $counts[ $state ] : 0;
		$class = 'gacct-op-list-tab';
		if ( $current['state'] === $state ) {
			$class .= ' is-active';
		}
		if ( 0 === $n ) {
			$class .= ' is-empty';
		}
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( gacct_op_list_url( $current, array( 'etat' => $state, 'paged' => null ) ) ) . '">'
			. esc_html( $state . ' · ' . $label ) . ' <span class="gacct-op-list-count">' . esc_html( $n ) . '</span></a>';
	}
	echo '</nav>';

	// 3. Filtre « Réalisé par ».
	$operators = gacct_op_operator_choices();

	if ( $operators ) {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="gacct-op-list-operator">';
		echo '<input type="hidden" name="page" value="' . esc_attr( GACCT_OP_MENU_SLUG ) . '">';
	echo '<input type="hidden" name="view" value="list">';
		if ( null !== $current['state'] ) {
			echo '<input type="hidden" name="etat" value="' . esc_attr( $current['state'] ) . '">';
		}
		if ( '' !== $current['search'] ) {
			echo '<input type="hidden" name="s" value="' . esc_attr( $current['search'] ) . '">';
		}
		echo '<input type="hidden" name="orderby" value="' . esc_attr( $current['orderby'] ) . '">';
		echo '<input type="hidden" name="order" value="' . esc_attr( $current['order'] ) . '">';
		echo '<label for="gacct-op-operator">' . esc_html__( 'Réalisé par', 'gestion-atelier-cct' ) . '</label> ';
		echo '<select name="operateur" id="gacct-op-operator">';
		echo '<option value="">' . esc_html__( 'Tous les opérateurs', 'gestion-atelier-cct' ) . '</option>';
		foreach ( $operators as $id => $name ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $current['operator'], $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select> ';
		echo '<button type="submit" class="button gacct-op-btn">' . esc_html__( 'Filtrer', 'gestion-atelier-cct' ) . '</button>';
		echo '</form>';
	}

	// 4. Tableau.
	if ( ! $items ) {
		echo '<div class="gacct-op-card gacct-op-list-empty"><p>' . esc_html__( 'Aucune intervention ne correspond à ces critères.', 'gestion-atelier-cct' ) . '</p></div>';
		echo '</div>';
		return;
	}

	$sortable = function ( $key, $label ) use ( $current ) {
		$is_current = ( $current['orderby'] === $key );
		$next_order = ( $is_current && 'ASC' === $current['order'] ) ? 'DESC' : 'ASC';
		$arrow      = '';
		if ( $is_current ) {
			$arrow = ' <span class="gacct-op-list-arrow" aria-hidden="true">' . ( 'ASC' === $current['order'] ? '&#9650;' : '&#9660;' ) . '</span>';
		}
		$url = gacct_op_list_url( $current, array( 'orderby' => $key, 'order' => $next_order, 'paged' => null ) );

		return '<a href="' . esc_url( $url ) . '" class="gacct-op-list-sort' . ( $is_current ? ' is-sorted' : '' ) . '">' . esc_html( $label ) . $arrow . '</a>';
	};

	echo '<table class="gacct-op-list-table">';
	echo '<thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Référence', 'gestion-atelier-cct' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Client', 'gestion-atelier-cct' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Matériel', 'gestion-atelier-cct' ) . '</th>';
	echo '<th scope="col">' . $sortable( 'slot', __( 'Créneau', 'gestion-atelier-cct' ) ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'État', 'gestion-atelier-cct' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Paiement', 'gestion-atelier-cct' ) . '</th>';
	echo '<th scope="col">' . $sortable( 'modified', __( 'Dernière activité', 'gestion-atelier-cct' ) ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Actions', 'gestion-atelier-cct' ) . '</th>';
	echo '</tr></thead><tbody>';

	$date_format = get_option( 'date_format' );
	$now         = current_time( 'timestamp' );

	foreach ( $items as $item ) {
		$rev_id   = absint( $item['_ID'] ?? 0 );
		$order_id = absint( $item['order_id'] ?? 0 );
		$order    = ( $order_id && isset( $orders[ $order_id ] ) ) ? $orders[ $order_id ] : null;
		$fiche    = gacct_op_console_url( $rev_id );

		echo '<tr>';

		// Référence.
		echo '<td data-label="' . esc_attr__( 'Référence', 'gestion-atelier-cct' ) . '" class="gacct-op-list-cell-ref">';
		if ( $order ) {
			echo '<a href="' . esc_url( $fiche ) . '"><strong>' . esc_html( $order->get_order_number() ) . '</strong></a>';
		} elseif ( $order_id ) {
			echo '<a href="' . esc_url( $fiche ) . '"><strong>#' . esc_html( $order_id ) . '</strong></a>';
		} else {
			echo '<a href="' . esc_url( $fiche ) . '"><strong>' . esc_html( sprintf( __( 'Dossier #%d', 'gestion-atelier-cct' ), $rev_id ) ) . '</strong></a><br><span class="gacct-op-list-muted">' . esc_html__( '(aucune commande)', 'gestion-atelier-cct' ) . '</span>';
		}
		echo '</td>';

		// Client.
		echo '<td data-label="' . esc_attr__( 'Client', 'gestion-atelier-cct' ) . '">';
		echo $order ? esc_html( $order->get_formatted_billing_full_name() ) : '<span class="gacct-op-list-muted">&mdash;</span>';
		echo '</td>';

		// Matériel.
		$materiel = trim( ( $item['marque'] ?? '' ) . ' ' . ( $item['modele'] ?? '' ) );
		foreach ( array( trim( (string) ( $item['taille'] ?? '' ) ), trim( (string) ( $item['couleur'] ?? '' ) ) ) as $part ) {
			if ( '' !== $part ) {
				$materiel = trim( $materiel . ( $materiel ? ' · ' : '' ) . $part );
			}
		}
		$serie = trim( (string) ( $item['numero_de_serie'] ?? '' ) );

		echo '<td data-label="' . esc_attr__( 'Matériel', 'gestion-atelier-cct' ) . '">';
		echo '' !== $materiel ? esc_html( $materiel ) : '<span class="gacct-op-list-muted">&mdash;</span>';
		if ( '' !== $serie ) {
			echo '<br><span class="gacct-op-list-muted gacct-op-list-serial">' . esc_html( sprintf( __( 'n° %s', 'gestion-atelier-cct' ), $serie ) ) . '</span>';
		}
		echo '</td>';

		// Créneau.
		$slot_ts = isset( $item['date_reservee'] ) && '' !== (string) $item['date_reservee'] ? (int) $item['date_reservee'] : 0;
		echo '<td data-label="' . esc_attr__( 'Créneau', 'gestion-atelier-cct' ) . '">';
		if ( $slot_ts ) {
			echo esc_html( date_i18n( $date_format, $slot_ts ) );
			$duree = trim( (string) ( $item['duree_totale_commande'] ?? '' ) );
			if ( '' !== $duree ) {
				echo '<br><span class="gacct-op-list-muted">' . esc_html( $duree ) . '</span>';
			}
		} else {
			echo '<span class="gacct-op-list-muted">&mdash;</span>';
		}
		echo '</td>';

		// État.
		$state = absint( $item['etat_de_la_commande'] ?? 0 );
		$label = isset( $labels[ $state ] ) ? $labels[ $state ] : (string) $state;
		echo '<td data-label="' . esc_attr__( 'État', 'gestion-atelier-cct' ) . '">';
		echo '<span class="gacct-op-badge etat-' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>';
		echo '</td>';

		// Paiement.
		echo '<td data-label="' . esc_attr__( 'Paiement', 'gestion-atelier-cct' ) . '">';
		echo $order ? esc_html( gacct_op_list_payment_label( $order ) ) : '<span class="gacct-op-list-muted">&mdash;</span>';
		echo '</td>';

		// Dernière activité.
		$modified_ts = ! empty( $item['cct_modified'] ) ? strtotime( $item['cct_modified'] ) : 0;
		echo '<td data-label="' . esc_attr__( 'Dernière activité', 'gestion-atelier-cct' ) . '">';
		if ( $modified_ts ) {
			echo '<span title="' . esc_attr( date_i18n( $date_format . ' H:i', $modified_ts ) ) . '">'
				. esc_html( sprintf( __( 'il y a %s', 'gestion-atelier-cct' ), human_time_diff( min( $modified_ts, $now ), $now ) ) ) . '</span>';
		} else {
			echo '<span class="gacct-op-list-muted">&mdash;</span>';
		}
		echo '</td>';

		// Actions.
		echo '<td data-label="' . esc_attr__( 'Actions', 'gestion-atelier-cct' ) . '" class="gacct-op-list-cell-actions">';
		echo '<a class="gacct-op-list-action" href="' . esc_url( $fiche ) . '">' . esc_html__( 'Fiche', 'gestion-atelier-cct' ) . '</a>';
		if ( $order && 'bacs' === $order->get_payment_method() && in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
			echo '<button type="button" class="gacct-op-btn gacct-op-list-deposit" data-op-action="confirm-deposit" data-revision-id="' . esc_attr( $rev_id ) . '">' . esc_html__( 'Acompte reçu', 'gestion-atelier-cct' ) . '</button>';
		}
		if ( $order && current_user_can( 'manage_woocommerce' ) ) {
			echo '<a class="gacct-op-list-action" href="' . esc_url( $order->get_edit_order_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Commande', 'gestion-atelier-cct' ) . '</a>';
		}
		echo '</td>';

		echo '</tr>';
	}

	echo '</tbody></table>';

	// 5. Pagination.
	if ( $result['pages'] > 1 ) {
		echo '<div class="gacct-op-list-pagination">';
		echo '<span class="gacct-op-list-pageinfo">' . esc_html( sprintf(
			/* translators: 1: current page, 2: total pages */
			__( 'Page %1$d sur %2$d', 'gestion-atelier-cct' ),
			$current['paged'],
			$result['pages']
		) ) . '</span>';

		$links = paginate_links( array(
			'base'      => gacct_op_list_url( $current, array( 'paged' => null ) ) . '%_%',
			'format'    => '&paged=%#%',
			'current'   => $current['paged'],
			'total'     => $result['pages'],
			'prev_text' => __( '&lsaquo; Précédent', 'gestion-atelier-cct' ),
			'next_text' => __( 'Suivant &rsaquo;', 'gestion-atelier-cct' ),
			'type'      => 'plain',
		) );

		if ( $links ) {
			echo '<span class="gacct-op-list-pagelinks">' . wp_kses_post( $links ) . '</span>';
		}
		echo '</div>';
	}

	echo '</div>';
}
