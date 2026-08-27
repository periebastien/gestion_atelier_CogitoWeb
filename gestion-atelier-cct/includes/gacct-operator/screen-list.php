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
		$state = ( $state >= 0 && $state <= 9 ) ? $state : null;
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
		'hold'     => ! empty( $_GET['attente'] ),
		'transit'  => ! empty( $_GET['acheminement'] ),
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
		'attente'   => ! empty( $current['hold'] ) ? 1 : null,
		'acheminement' => ! empty( $current['transit'] ) ? 1 : null,
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
 * Mini-frise 0–8 d'une ligne de la liste : 9 pastilles (faites / courante /
 * à venir), libellé d'étape en infobulle. Même sémantique que la frise
 * « Avancement » de la fiche, format cellule de tableau.
 */
function gacct_op_list_render_steps( $state, array $labels ) {
	// L'état 9 « Sans suite » est terminal HORS frise : un badge, pas des étapes.
	if ( defined( 'GACCT_STATE_SANS_SUITE' ) && GACCT_STATE_SANS_SUITE === (int) $state ) {
		echo '<span class="gacct-op-badge etat-9">' . esc_html__( 'Sans suite', 'gestion-atelier-cct' ) . '</span>';
		return;
	}

	unset( $labels[ defined( 'GACCT_STATE_SANS_SUITE' ) ? GACCT_STATE_SANS_SUITE : 9 ] );

	// Code couleur de l'espace client : teal = la balle est chez le client
	// (paiement, expédition, devis, solde), orange = l'atelier doit agir,
	// vert = dossier bouclé.
	if ( $state >= 8 ) {
		$family = 'is-complete';
	} elseif ( in_array( $state, array( 0, 1, 4, 6 ), true ) ) {
		$family = 'is-client';
	} else {
		$family = 'is-atelier';
	}

	echo '<span class="gacct-op-list-steps ' . esc_attr( $family ) . '" role="img" aria-label="' . esc_attr( sprintf( __( 'Étape %1$d sur %2$d', 'gestion-atelier-cct' ), $state, max( array_keys( $labels ) ) ) ) . '">';
	foreach ( $labels as $i => $step_label ) {
		$class = 'gacct-op-list-step';
		if ( $i < $state ) {
			$class .= ' is-done';
		} elseif ( $i === $state ) {
			$class .= ' is-current';
		}
		echo '<span class="' . esc_attr( $class ) . '" title="' . esc_attr( $i . ' · ' . $step_label ) . '"></span>';
	}
	echo '</span>';
}

/**
 * Cellule « Documents » : badge-compteur dépliable (<details>, zéro JS) —
 * PDF générés (n° + modèle), uploads manuels (titre de la pièce jointe) et
 * brouillons non générés. Données déjà dans la ligne SQL (rapport_pdf +
 * rapports_json) : aucune requête en plus, sauf le titre des uploads manuels.
 */
function gacct_op_list_render_documents( array $item ) {
	$rev_id  = absint( $item['_ID'] ?? 0 );
	$ids     = gacct_report_ids( $item['rapport_pdf'] ?? '' );
	$models  = gacct_report_models();
	$entries = json_decode( (string) ( $item['rapports_json'] ?? '' ), true );
	$entries = is_array( $entries ) ? $entries : array();

	// Libellés des PDF générés (par pièce jointe) + compte des brouillons.
	$generated = array();
	$drafts    = 0;

	foreach ( $entries as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['model'] ) ) {
			continue;
		}

		$attachment_id = absint( $entry['attachment_id'] ?? 0 );

		if ( $attachment_id && in_array( $attachment_id, $ids, true ) ) {
			$generated[ $attachment_id ] = trim( sprintf(
				'%s %s',
				! empty( $entry['number'] ) ? $entry['number'] . ' —' : '',
				isset( $models[ $entry['model'] ] ) ? $models[ $entry['model'] ] : $entry['model']
			) );
		} else {
			$drafts++;
		}
	}

	if ( ! $ids && ! $drafts ) {
		echo '<span class="gacct-op-list-muted">&mdash;</span>';
		return;
	}

	$count_label = sprintf( _n( '%d PDF', '%d PDF', count( $ids ), 'gestion-atelier-cct' ), count( $ids ) );
	if ( $drafts ) {
		$count_label .= ' · ' . sprintf( _n( '%d brouillon', '%d brouillons', $drafts, 'gestion-atelier-cct' ), $drafts );
	}

	// Icônes natives dashicons.
	$icon_doc = '<span class="dashicons dashicons-media-document gacct-op-ico" aria-hidden="true"></span>';
	$icon_dl  = '<span class="dashicons dashicons-download gacct-op-ico" aria-hidden="true"></span>';

	if ( ! $ids ) {
		// Que des brouillons : pas de menu, juste l'info.
		echo '<span class="gacct-op-list-docs-empty">' . $icon_doc . ' ' . esc_html( $count_label ) . '</span>';
		return;
	}

	echo '<details class="gacct-op-list-docs">';
	echo '<summary>' . $icon_doc . ' <span>' . esc_html( $count_label ) . '</span></summary>';
	echo '<ul class="gacct-op-list-docs-menu">';

	foreach ( $ids as $attachment_id ) {
		if ( isset( $generated[ $attachment_id ] ) ) {
			$doc_label = $generated[ $attachment_id ];
		} else {
			$title     = get_the_title( $attachment_id );
			$doc_label = ( $title ? $title : sprintf( __( 'Document %d', 'gestion-atelier-cct' ), $attachment_id ) )
				. ' (' . __( 'upload manuel', 'gestion-atelier-cct' ) . ')';
		}

		echo '<li><a href="' . esc_url( gacct_report_download_url( $rev_id, $attachment_id ) ) . '" target="_blank" rel="noopener">'
			. $icon_dl . ' <span>' . esc_html( $doc_label ) . '</span></a></li>';
	}

	if ( $drafts ) {
		echo '<li class="gacct-op-list-docs-draft">' . esc_html( sprintf( _n( '%d brouillon non généré', '%d brouillons non générés', $drafts, 'gestion-atelier-cct' ), $drafts ) ) . '</li>';
	}

	echo '</ul></details>';
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
		'hold'     => $current['hold'],
		'transit'  => $current['transit'],
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

	// 1. Titre + recherche (composants natifs : wp-heading-inline + search-box).
	echo '<h1 class="wp-heading-inline">' . esc_html__( 'Interventions', 'gestion-atelier-cct' ) . '</h1>';
	echo '<hr class="wp-header-end">';

	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" role="search">';
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
	echo '<p class="search-box">';
	echo '<label class="screen-reader-text" for="gacct-op-search">' . esc_html__( 'Rechercher une intervention', 'gestion-atelier-cct' ) . '</label>';
	echo '<input type="search" id="gacct-op-search" name="s" value="' . esc_attr( $current['search'] ) . '" placeholder="' . esc_attr__( 'Référence, client, marque, n° de série…', 'gestion-atelier-cct' ) . '">';
	echo '<input type="submit" class="button" value="' . esc_attr__( 'Rechercher', 'gestion-atelier-cct' ) . '">';
	echo '</p>';
	echo '</form>';

	// 2. Onglets d'états (liste native subsubsub).
	$total_all = array_sum( $counts );
	$last_state = max( array_keys( $labels ) );

	echo '<ul class="subsubsub">';
	echo '<li class="all"><a href="' . esc_url( gacct_op_list_url( $current, array( 'etat' => null, 'attente' => null, 'acheminement' => null, 'paged' => null ) ) ) . '"'
		. ( null === $current['state'] && ! $current['hold'] && ! $current['transit'] ? ' class="current" aria-current="page"' : '' ) . '>'
		. esc_html__( 'Tous', 'gestion-atelier-cct' ) . ' <span class="count">(' . esc_html( $total_all ) . ')</span></a> |</li>';

	foreach ( $labels as $state => $label ) {
		$n = isset( $counts[ $state ] ) ? (int) $counts[ $state ] : 0;
		echo '<li><a href="' . esc_url( gacct_op_list_url( $current, array( 'etat' => $state, 'attente' => null, 'acheminement' => null, 'paged' => null ) ) ) . '"'
			. ( $current['state'] === $state && ! $current['hold'] && ! $current['transit'] ? ' class="current" aria-current="page"' : '' ) . '>'
			. esc_html( $state . ' · ' . $label ) . ' <span class="count">(' . esc_html( $n ) . ')</span></a> |</li>';

		// L'acheminement est une sous-phase de l'attente de réception : son
		// onglet suit l'état 1 dans l'ordre des stades (demande Bastien).
		if ( 1 === $state ) {
			echo '<li><a href="' . esc_url( gacct_op_list_url( $current, array( 'etat' => null, 'attente' => null, 'acheminement' => 1, 'paged' => null ) ) ) . '"'
				. ( $current['transit'] ? ' class="current" aria-current="page"' : '' ) . '>'
				. esc_html__( 'En acheminement', 'gestion-atelier-cct' ) . ' <span class="count">(' . esc_html( (int) $result['transit_count'] ) . ')</span></a> |</li>';
		}
	}

	// Vue transversale : les dossiers mis en pause, quel que soit leur état.
	echo '<li><a href="' . esc_url( gacct_op_list_url( $current, array( 'etat' => null, 'attente' => 1, 'acheminement' => null, 'paged' => null ) ) ) . '"'
		. ( $current['hold'] ? ' class="current" aria-current="page"' : '' ) . '>'
		. esc_html__( 'En attente', 'gestion-atelier-cct' ) . ' <span class="count">(' . esc_html( (int) $result['hold_count'] ) . ')</span></a></li>';
	echo '</ul>';

	// 3. Barre d'outils native (filtre « Réalisé par »).
	$operators = gacct_op_operator_choices();

	echo '<div class="tablenav top">';
	if ( $operators ) {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="alignleft actions">';
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
		echo '<label class="screen-reader-text" for="gacct-op-operator">' . esc_html__( 'Filtrer par opérateur', 'gestion-atelier-cct' ) . '</label>';
		echo '<select name="operateur" id="gacct-op-operator">';
		echo '<option value="">' . esc_html__( 'Tous les opérateurs', 'gestion-atelier-cct' ) . '</option>';
		foreach ( $operators as $id => $name ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $current['operator'], $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select> ';
		echo '<input type="submit" class="button" value="' . esc_attr__( 'Filtrer', 'gestion-atelier-cct' ) . '">';
		echo '</form>';
	}
	echo '<br class="clear">';
	echo '</div>';

	// 4. Tableau.
	if ( ! $items ) {
		echo '<p class="gacct-op-list-empty">' . esc_html__( 'Aucune intervention ne correspond à ces critères.', 'gestion-atelier-cct' ) . '</p>';
		echo '</div>';
		return;
	}

	// En-tête de colonne triable, markup natif des list tables.
	$sortable_th = function ( $key, $label, $extra_class = '' ) use ( $current ) {
		$is_current = ( $current['orderby'] === $key );
		$next_order = ( $is_current && 'ASC' === $current['order'] ) ? 'DESC' : 'ASC';
		$class      = 'manage-column column-' . $key . ' ' . $extra_class . ' '
			. ( $is_current ? 'sorted ' . strtolower( $current['order'] ) : 'sortable ' . strtolower( $next_order ) );
		$url        = gacct_op_list_url( $current, array( 'orderby' => $key, 'order' => $next_order, 'paged' => null ) );

		return '<th scope="col" class="' . esc_attr( trim( $class ) ) . '">'
			. '<a href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span>'
			. '<span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a>'
			. '</th>';
	};

	echo '<table class="wp-list-table widefat fixed striped gacct-op-list-table">';
	echo '<thead><tr>';
	echo '<th scope="col" class="manage-column column-ref column-primary">' . esc_html__( 'Référence', 'gestion-atelier-cct' ) . '</th>';
	echo '<th scope="col" class="manage-column column-client">' . esc_html__( 'Client', 'gestion-atelier-cct' ) . '</th>';
	echo '<th scope="col" class="manage-column column-materiel">' . esc_html__( 'Matériel', 'gestion-atelier-cct' ) . '</th>';
	echo $sortable_th( 'slot', __( 'Créneau', 'gestion-atelier-cct' ) );
	echo '<th scope="col" class="manage-column column-etat">' . esc_html__( 'État', 'gestion-atelier-cct' ) . '</th>';
	echo '<th scope="col" class="manage-column column-paiement">' . esc_html__( 'Paiement', 'gestion-atelier-cct' ) . '</th>';
	echo '<th scope="col" class="manage-column column-docs">' . esc_html__( 'Documents', 'gestion-atelier-cct' ) . '</th>';
	echo $sortable_th( 'modified', __( 'Dernière activité', 'gestion-atelier-cct' ) );
	echo '</tr></thead><tbody>';

	$date_format = get_option( 'date_format' );
	$now         = current_time( 'timestamp' );

	foreach ( $items as $item ) {
		$rev_id   = absint( $item['_ID'] ?? 0 );
		$order_id = absint( $item['order_id'] ?? 0 );
		$order    = ( $order_id && isset( $orders[ $order_id ] ) ) ? $orders[ $order_id ] : null;
		$fiche    = gacct_op_console_url( $rev_id );

		echo '<tr>';

		// Référence (colonne primaire : actions de ligne + bascule mobile natives).
		echo '<td class="column-ref column-primary" data-colname="' . esc_attr__( 'Référence', 'gestion-atelier-cct' ) . '">';
		if ( $order ) {
			echo '<a href="' . esc_url( $fiche ) . '"><strong>' . esc_html( $order->get_order_number() ) . '</strong></a>';
		} elseif ( $order_id ) {
			echo '<a href="' . esc_url( $fiche ) . '"><strong>#' . esc_html( $order_id ) . '</strong></a>';
		} else {
			echo '<a href="' . esc_url( $fiche ) . '"><strong>' . esc_html( sprintf( __( 'Dossier #%d', 'gestion-atelier-cct' ), $rev_id ) ) . '</strong></a><br><span class="gacct-op-list-muted">' . esc_html__( '(aucune commande)', 'gestion-atelier-cct' ) . '</span>';
		}

		echo '<div class="row-actions">';
		echo '<span class="view"><a href="' . esc_url( $fiche ) . '">' . esc_html__( 'Fiche', 'gestion-atelier-cct' ) . '</a></span>';
		if ( $order && 'bacs' === $order->get_payment_method() && in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
			echo ' | <span class="deposit"><button type="button" class="button-link" data-op-action="confirm-deposit" data-revision-id="' . esc_attr( $rev_id ) . '">' . esc_html__( 'Acompte reçu', 'gestion-atelier-cct' ) . '</button></span>';
		}
		if ( $order && current_user_can( 'manage_woocommerce' ) ) {
			echo ' | <span class="edit"><a href="' . esc_url( $order->get_edit_order_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Commande', 'gestion-atelier-cct' ) . '</a></span>';
		}
		echo '</div>';

		echo '<button type="button" class="toggle-row"><span class="screen-reader-text">' . esc_html__( 'Afficher plus de détails', 'gestion-atelier-cct' ) . '</span></button>';
		echo '</td>';

		// Client.
		echo '<td class="column-client" data-colname="' . esc_attr__( 'Client', 'gestion-atelier-cct' ) . '">';
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

		echo '<td class="column-materiel" data-colname="' . esc_attr__( 'Matériel', 'gestion-atelier-cct' ) . '">';
		echo '' !== $materiel ? esc_html( $materiel ) : '<span class="gacct-op-list-muted">&mdash;</span>';
		if ( '' !== $serie ) {
			echo '<br><span class="gacct-op-list-muted gacct-op-list-serial">' . esc_html( sprintf( __( 'n° %s', 'gestion-atelier-cct' ), $serie ) ) . '</span>';
		}
		echo '</td>';

		// Créneau.
		$slot_ts = isset( $item['date_reservee'] ) && '' !== (string) $item['date_reservee'] ? (int) $item['date_reservee'] : 0;
		echo '<td class="column-slot" data-colname="' . esc_attr__( 'Créneau', 'gestion-atelier-cct' ) . '">';
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
		echo '<td class="column-etat" data-colname="' . esc_attr__( 'État', 'gestion-atelier-cct' ) . '">';
		echo '<span class="gacct-op-badge etat-' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>';
		if ( function_exists( 'gacct_hold_info' ) && gacct_hold_info( $item )['active'] ) {
			echo ' <span class="gacct-op-badge gacct-op-badge-hold" title="' . esc_attr( gacct_hold_info( $item )['motif'] ) . '">' . esc_html__( 'En attente', 'gestion-atelier-cct' ) . '</span>';
		}
		gacct_op_list_render_steps( $state, $labels );
		echo '</td>';

		// Paiement.
		echo '<td class="column-paiement" data-colname="' . esc_attr__( 'Paiement', 'gestion-atelier-cct' ) . '">';
		echo $order ? esc_html( gacct_op_list_payment_label( $order ) ) : '<span class="gacct-op-list-muted">&mdash;</span>';
		echo '</td>';

		// Documents (PDF générés + uploads manuels + brouillons).
		echo '<td class="column-docs gacct-op-list-cell-docs" data-colname="' . esc_attr__( 'Documents', 'gestion-atelier-cct' ) . '">';
		gacct_op_list_render_documents( $item );
		echo '</td>';

		// Dernière activité.
		$modified_ts = ! empty( $item['cct_modified'] ) ? strtotime( $item['cct_modified'] ) : 0;
		echo '<td class="column-modified" data-colname="' . esc_attr__( 'Dernière activité', 'gestion-atelier-cct' ) . '">';
		if ( $modified_ts ) {
			echo '<span title="' . esc_attr( date_i18n( $date_format . ' H:i', $modified_ts ) ) . '">'
				. esc_html( sprintf( __( 'il y a %s', 'gestion-atelier-cct' ), human_time_diff( min( $modified_ts, $now ), $now ) ) ) . '</span>';
		} else {
			echo '<span class="gacct-op-list-muted">&mdash;</span>';
		}
		echo '</td>';

		echo '</tr>';
	}

	echo '</tbody></table>';

	// 5. Pagination (barre native tablenav).
	if ( $result['pages'] > 1 ) {
		echo '<div class="tablenav bottom">';
		echo '<div class="tablenav-pages">';
		echo '<span class="displaying-num">' . esc_html( sprintf(
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
			'prev_text' => __( '&lsaquo;', 'gestion-atelier-cct' ),
			'next_text' => __( '&rsaquo;', 'gestion-atelier-cct' ),
			'type'      => 'plain',
		) );

		if ( $links ) {
			echo '<span class="pagination-links">' . wp_kses_post( $links ) . '</span>';
		}
		echo '</div>';
		echo '<br class="clear">';
		echo '</div>';
	}

	echo '</div>';
}
