<?php
/**
 * Facturation & suppléments — ajustement de la facture par l'opérateur
 * (réunion du 06/08/2026), SANS passer par le devis complémentaire.
 *
 * L'atelier ajoute des produits du catalogue, des lignes libres et des REMISES
 * (montants négatifs) sur la commande d'un dossier aux états 2 à 6. Rien n'est
 * encaissé sur le moment : chaque ligne porte la sémantique Kojito « acompte 0 »
 * (total de ligne 0 €, prix réel dans les metas _kojito_prix_total_initial*),
 * donc tout s'ajoute — ou se retranche — au solde de fin d'intervention calculé
 * par l'API publique de kojito-acompte-produit. AUCUN email au client : les
 * mouvements sont journalisés en notes de commande signées ; le client voit le
 * nouveau montant sur sa page de commande (montants déjà servis par Kojito).
 *
 * Marqueur de ligne PROPRE : `_gacct_billing_extra` — surtout pas
 * `_gacct_quote_extra`, sinon le refus d'un devis supprimerait ces lignes.
 *
 * À l'état 6 (solde déjà demandé), chaque écriture re-déclenche
 * `kojito_declencher_paiement_solde` pour recaler le total de la commande sur
 * le nouveau solde ; un solde devenu nul enchaîne sur l'état 7 par la même
 * mécanique que l'entrée en 6 (GACCT_Plugin::advance_revision_state()).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Marqueur des lignes ajoutées par la facturation atelier.
define( 'GACCT_BILLING_ITEM_FLAG', '_gacct_billing_extra' );

/* =============================================================================
 *  CATALOGUE PROPOSÉ À L'OPÉRATEUR
 * ============================================================================= */

/**
 * Motif « frais de port » partagé avec gacct_op_expected_items() : un produit
 * de port n'a pas sa place dans une facture atelier.
 */
function gacct_billing_is_shipping_name( $name ) {
	return (bool) preg_match( '/frais|port|exp[ée]dition|retour|^colis\b/iu', trim( wp_strip_all_tags( (string) $name ) ) );
}

/**
 * Un produit est-il un « frais de port » (nom ou catégorie) ?
 */
function gacct_billing_product_is_shipping( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}

	if ( gacct_billing_is_shipping_name( $product->get_name() ) ) {
		return true;
	}

	foreach ( (array) get_the_terms( $product->get_id(), 'product_cat' ) as $term ) {
		if ( $term instanceof WP_Term && ( gacct_billing_is_shipping_name( $term->name ) || gacct_billing_is_shipping_name( $term->slug ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Catégories de produits proposées dans l'éditeur de facturation (slugs).
 * Par défaut : TOUTES les catégories, sauf celles de frais de port.
 */
function gacct_billing_product_category_slugs() {
	$slugs = array();
	$terms = get_terms( array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
	) );

	foreach ( (array) $terms as $term ) {
		if ( ! $term instanceof WP_Term ) {
			continue;
		}
		if ( gacct_billing_is_shipping_name( $term->name ) || gacct_billing_is_shipping_name( $term->slug ) ) {
			continue;
		}
		$slugs[] = $term->slug;
	}

	return apply_filters( 'gacct_billing_product_cats', $slugs );
}

/**
 * Produits sélectionnables dans la facturation atelier — même format que
 * gacct_quote_products() : { id, name, price_ttc }.
 *
 * @return array[]
 */
function gacct_billing_products() {
	$slugs = gacct_billing_product_category_slugs();

	if ( empty( $slugs ) ) {
		return apply_filters( 'gacct_billing_products', array() );
	}

	$query = new WP_Query( array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'tax_query'      => array(
			array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => $slugs,
			),
		),
	) );

	$products = array();

	foreach ( $query->posts as $post ) {
		$product = wc_get_product( $post->ID );

		if ( ! $product || gacct_billing_product_is_shipping( $product ) ) {
			continue;
		}

		$products[] = array(
			'id'        => $post->ID,
			'name'      => $product->get_name(),
			'price_ttc' => (float) wc_get_price_including_tax( $product ),
		);
	}

	return apply_filters( 'gacct_billing_products', $products );
}

/* =============================================================================
 *  LIGNES DE FACTURATION
 * ============================================================================= */

/**
 * Lignes de commande ajoutées par la facturation atelier.
 *
 * @param WC_Order|mixed $order
 * @return WC_Order_Item_Product[] Indexées par item_id.
 */
function gacct_billing_items( $order ) {
	$extras = array();

	if ( ! $order instanceof WC_Order ) {
		return $extras;
	}

	foreach ( $order->get_items() as $item_id => $item ) {
		if ( '' !== (string) $item->get_meta( GACCT_BILLING_ITEM_FLAG ) ) {
			$extras[ $item_id ] = $item;
		}
	}

	return $extras;
}

/**
 * Garde commune : dossier, état 2–6 (lu en SQL direct — cache JetEngine),
 * commande liée non annulée.
 *
 * @return array|WP_Error { revision: array, state: int, order: WC_Order }
 */
function gacct_billing_guard( $revision_id ) {
	$revision_id = absint( $revision_id );
	$revision    = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

	if ( ! $revision ) {
		return new WP_Error( 'gacct_billing_not_found', __( 'Dossier introuvable.', 'gestion-atelier-cct' ) );
	}

	$state = gacct_op_read_state( $revision_id );
	$state = ( null === $state ) ? absint( $revision['etat_de_la_commande'] ?? 0 ) : $state;

	if ( $state < 2 || $state > 6 ) {
		return new WP_Error( 'gacct_billing_bad_state', sprintf(
			__( 'La facturation atelier n\'est modifiable qu\'entre la réception (état 2) et la demande de solde (état 6) — état actuel : %d.', 'gestion-atelier-cct' ),
			$state
		) );
	}

	$order = gacct_op_get_order_for_revision( $revision );

	if ( ! $order ) {
		return new WP_Error( 'gacct_billing_no_order', __( 'Commande liée introuvable.', 'gestion-atelier-cct' ) );
	}

	if ( $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
		return new WP_Error( 'gacct_billing_cancelled', __( 'La commande liée est annulée : facturation impossible.', 'gestion-atelier-cct' ) );
	}

	return array(
		'revision' => $revision,
		'state'    => $state,
		'order'    => $order,
	);
}

/**
 * À l'état 6 (solde déjà demandé) : recale le total de la commande sur le
 * nouveau solde via le plugin d'acompte, note signée « solde recalculé »,
 * et si le solde devient nul, enchaîne sur l'état 7 par la même mécanique
 * que l'entrée en 6 (GACCT_Plugin::advance_revision_state()).
 */
function gacct_billing_refresh_balance( $order, $revision_id ) {
	do_action( 'kojito_declencher_paiement_solde', $order->get_id() );

	// La commande a été modifiée par l'action : relecture.
	$order = wc_get_order( $order->get_id() );

	if ( ! $order ) {
		return;
	}

	// Solde courant : API Kojito, aucun calcul maison.
	$balance = round( gacct_kojito_total_initial( $order ) - gacct_quote_deposit_paid( $order ), wc_get_price_decimals() );

	gacct_op_add_signed_note( $order, sprintf(
		__( 'Facturation atelier — solde recalculé : %s', 'gestion-atelier-cct' ),
		wp_strip_all_tags( wc_price( max( 0, $balance ), array( 'currency' => $order->get_currency() ) ) )
	) );

	// Solde devenu nul : si Kojito a encaissé un « solde 0 » (payment_complete),
	// gacct_sync_revision_state_on_balance_payment a DÉJÀ fait 6→7 — on ne
	// double pas. On n'avance nous-mêmes que si le dossier est resté à 6
	// (état relu en SQL direct, cache JetEngine).
	if ( $balance <= 0.005 && class_exists( 'GACCT_Plugin' ) && 6 === gacct_op_read_state( absint( $revision_id ) ) ) {
		$order->add_order_note( __( 'Solde nul après facturation atelier : passage automatique en état 7 (révision finie, rapport disponible).', 'gestion-atelier-cct' ) );
		$order->save();
		GACCT_Plugin::instance()->advance_revision_state( absint( $revision_id ), 7 );
	}
}

/**
 * Libellé humain d'une ligne : « Nom × qty — 25,00 € ».
 */
function gacct_billing_line_label( $name, $qty, $total_ttc, $order ) {
	return sprintf(
		'%s × %d — %s',
		$name,
		(int) $qty,
		wp_strip_all_tags( wc_price( (float) $total_ttc, array( 'currency' => $order->get_currency() ) ) )
	);
}

/**
 * Ajoute des lignes de facturation à la commande d'un dossier (états 2 à 6).
 * AJOUTE (ne remplace pas). Sémantique Kojito « acompte 0 » : rien n'est
 * encaissé maintenant, tout part dans le solde de l'état 6.
 *
 * @param int     $revision_id ID du CCT revision.
 * @param array[] $lines       Chaque ligne : { product_id: int, qty: int } (catalogue)
 *                             ou { label: string, price: float TTC unitaire (négatif = remise), qty: int } (libre).
 * @return array|WP_Error { added: string[], total_due: float, state: int }
 */
function gacct_billing_add_lines( $revision_id, array $lines ) {
	$context = gacct_billing_guard( $revision_id );

	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$order = $context['order'];
	$state = $context['state'];

	// ---- Validation des lignes. ----
	$prepared = array();

	foreach ( $lines as $line ) {
		$qty = max( 1, min( 99, absint( $line['qty'] ?? 1 ) ) );

		if ( ! empty( $line['product_id'] ) ) {
			$product = wc_get_product( absint( $line['product_id'] ) );

			if ( ! $product || 'publish' !== $product->get_status() ) {
				return new WP_Error( 'gacct_billing_bad_product', __( 'Un des produits sélectionnés n\'existe pas ou n\'est plus publié.', 'gestion-atelier-cct' ) );
			}

			if ( gacct_billing_product_is_shipping( $product ) ) {
				return new WP_Error( 'gacct_billing_shipping_product', sprintf(
					__( '« %s » est un frais de port : il n\'a pas sa place dans une facture atelier.', 'gestion-atelier-cct' ),
					$product->get_name()
				) );
			}

			$unit_ttc = (float) wc_get_price_including_tax( $product );
			$name     = $product->get_name();

			if ( $unit_ttc <= 0 ) {
				return new WP_Error( 'gacct_billing_bad_price', sprintf( __( 'Prix catalogue invalide pour « %s ».', 'gestion-atelier-cct' ), $name ) );
			}
		} else {
			$product  = null;
			$name     = sanitize_text_field( (string) ( $line['label'] ?? '' ) );
			$unit_ttc = round( (float) ( $line['price'] ?? 0 ), wc_get_price_decimals() );

			if ( '' === $name ) {
				return new WP_Error( 'gacct_billing_bad_label', __( 'Chaque ligne libre doit avoir un libellé.', 'gestion-atelier-cct' ) );
			}

			// Négatif = remise, autorisé. Nul = refusé.
			if ( 0.0 === $unit_ttc ) {
				return new WP_Error( 'gacct_billing_bad_price', sprintf( __( 'Prix nul refusé pour « %s » (montant négatif = remise).', 'gestion-atelier-cct' ), $name ) );
			}
		}

		$prepared[] = array(
			'product'  => $product,
			'name'     => $name,
			'qty'      => $qty,
			'unit_ttc' => $unit_ttc,
		);
	}

	if ( empty( $prepared ) ) {
		return new WP_Error( 'gacct_billing_empty', __( 'Ajoutez au moins une ligne.', 'gestion-atelier-cct' ) );
	}

	// ---- Garde-fou remise : le total dû ne doit jamais devenir négatif. ----
	$current_total = gacct_kojito_total_initial( $order );
	$delta         = 0.0;
	$positives     = 0.0;

	foreach ( $prepared as $line ) {
		$line_total = $line['unit_ttc'] * $line['qty'];
		$delta     += $line_total;
		if ( $line_total > 0 ) {
			$positives += $line_total;
		}
	}

	$projected = round( $current_total + $delta, wc_get_price_decimals() );

	if ( $projected < 0 ) {
		$max_discount = round( $current_total + $positives, wc_get_price_decimals() );

		return new WP_Error( 'gacct_billing_negative_total', sprintf(
			__( 'Remise trop élevée : le total dû deviendrait négatif (%1$s). Remise maximale possible : %2$s.', 'gestion-atelier-cct' ),
			wp_strip_all_tags( wc_price( $projected, array( 'currency' => $order->get_currency() ) ) ),
			wp_strip_all_tags( wc_price( $max_discount, array( 'currency' => $order->get_currency() ) ) )
		) );
	}

	// ---- Écriture des lignes (sémantique Kojito « acompte 0 »). ----
	$added = array();

	foreach ( $prepared as $line ) {
		$total_ttc = round( $line['unit_ttc'] * $line['qty'], wc_get_price_decimals() );
		$total_ht  = gacct_quote_ht_from_ttc( $total_ttc, $line['product'] );

		$item = new WC_Order_Item_Product();
		$item->set_name( $line['name'] );
		$item->set_quantity( $line['qty'] );

		if ( $line['product'] instanceof WC_Product ) {
			$item->set_product_id( $line['product']->get_id() );
			$item->set_tax_class( $line['product']->get_tax_class() );
		}

		// Rien n'est facturé maintenant : mêmes conventions que les lignes
		// « acompte 0 » posées au checkout par kojito-acompte-produit.
		$item->set_subtotal( 0 );
		$item->set_total( 0 );

		$item->add_meta_data( '_kojito_prix_unitaire_initial', wc_format_decimal( $line['unit_ttc'] ), true );
		$item->add_meta_data( '_kojito_prix_total_initial', wc_format_decimal( $total_ttc ), true );
		$item->add_meta_data( '_kojito_prix_total_initial_ht', wc_format_decimal( $total_ht, 6 ), true );
		$item->add_meta_data( '_kojito_acompte_unitaire', '0', true );
		$item->add_meta_data( GACCT_BILLING_ITEM_FLAG, '1', true );

		$order->add_item( $item );

		$added[] = array(
			'label'    => gacct_billing_line_label( $line['name'], $line['qty'], $total_ttc, $order ),
			'discount' => $total_ttc < 0,
		);
	}

	$order->save();

	// Une note signée PAR LIGNE ajoutée.
	foreach ( $added as $entry ) {
		gacct_op_add_signed_note( $order, sprintf(
			$entry['discount']
				? __( 'Facturation atelier — remise : %s', 'gestion-atelier-cct' )
				: __( 'Facturation atelier — ajout : %s', 'gestion-atelier-cct' ),
			$entry['label']
		) );
	}

	// État 6 : le solde a déjà été demandé, on le recale immédiatement.
	if ( 6 === $state ) {
		gacct_billing_refresh_balance( $order, $revision_id );
	}

	return array(
		'added'     => wp_list_pluck( $added, 'label' ),
		'total_due' => gacct_kojito_total_initial( wc_get_order( $order->get_id() ) ),
		'state'     => $state,
	);
}

/**
 * Retire UNE ligne de facturation atelier (états 2 à 6). Anti-IDOR : la ligne
 * doit appartenir à la commande de CE dossier et porter le marqueur
 * `_gacct_billing_extra`.
 *
 * @param int $revision_id ID du CCT revision.
 * @param int $item_id     ID de la ligne de commande.
 * @return array|WP_Error { removed: string, total_due: float }
 */
function gacct_billing_remove_line( $revision_id, $item_id ) {
	$context = gacct_billing_guard( $revision_id );

	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$order   = $context['order'];
	$state   = $context['state'];
	$item_id = absint( $item_id );
	$items   = gacct_billing_items( $order );

	if ( ! isset( $items[ $item_id ] ) ) {
		return new WP_Error( 'gacct_billing_bad_item', __( 'Cette ligne n\'est pas une ligne de facturation atelier de ce dossier.', 'gestion-atelier-cct' ) );
	}

	$item  = $items[ $item_id ];
	$total = gacct_kojito_montant_ligne( $item );
	$label = gacct_billing_line_label( $item->get_name(), $item->get_quantity(), $total, $order );

	$order->remove_item( $item_id );
	$order->save();

	gacct_op_add_signed_note( $order, sprintf(
		$total < 0
			? __( 'Facturation atelier — remise retirée : %s', 'gestion-atelier-cct' )
			: __( 'Facturation atelier — ligne retirée : %s', 'gestion-atelier-cct' ),
		$label
	) );

	if ( 6 === $state ) {
		gacct_billing_refresh_balance( $order, $revision_id );
	}

	return array(
		'removed'   => $label,
		'total_due' => gacct_kojito_total_initial( wc_get_order( $order->get_id() ) ),
	);
}
