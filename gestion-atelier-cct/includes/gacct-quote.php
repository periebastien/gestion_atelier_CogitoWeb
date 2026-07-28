<?php
/**
 * Devis complémentaire (état 3) — cœur métier.
 *
 * L'atelier réévalue la commande après inspection : ajout de prestations du
 * catalogue (catégories « réparation » et « suspentes-travaux ») et/ou de
 * lignes libres, avec quantités. Le client reçoit un lien sécurisé vers la
 * page /devis-a-valider/ où il ACCEPTE (état 4) ou REFUSE le devis.
 *
 * Refus (décision Bastien 28/07/2026) :
 * - cas général : les lignes supplémentaires sont retirées, la révision passe
 *   en état 8 « Devis refusé » ; l'atelier relance l'intervention (8→4) sur
 *   les prestations initiales uniquement ;
 * - commande « demande de devis après réception » seule (produit 696) : état 8
 *   terminal, le matériel est retourné au client.
 *
 * Montants : les lignes ajoutées portent la sémantique Kojito « acompte 0 »
 * (total de ligne 0 €, prix réel dans les metas _kojito_prix_total_initial*).
 * Rien n'est encaissé à l'envoi du devis : tout part dans le solde (état 5),
 * calculé par l'API publique de kojito-acompte-produit — aucun recalcul ici.
 *
 * Relance : si le devis reste sans réponse après {quote_reminder_days} jours
 * (défaut 3), un email de relance part avec un lien régénéré (template
 * éditable `quote_reminder`, page Configuration > Paiements & relances).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Metas de ligne (le marqueur identifie les lignes ajoutées par le devis).
define( 'GACCT_QUOTE_ITEM_FLAG', '_gacct_quote_extra' );

// Metas de commande.
define( 'GACCT_QUOTE_META_COMMENT', '_gacct_quote_comment' );
define( 'GACCT_QUOTE_META_SENT_AT', '_gacct_quote_sent_at' );
define( 'GACCT_QUOTE_META_REMINDED_AT', '_gacct_quote_reminded_at' );
define( 'GACCT_QUOTE_META_DECISION', '_gacct_quote_decision' );
define( 'GACCT_QUOTE_META_DECIDED_AT', '_gacct_quote_decided_at' );
define( 'GACCT_QUOTE_META_REFUSAL_MODE', '_gacct_quote_refusal_mode' );

// État CCT terminal/intermédiaire « Devis refusé ».
define( 'GACCT_STATE_QUOTE_REFUSED', 8 );

/* =============================================================================
 *  CATALOGUE PROPOSÉ À L'OPÉRATEUR
 * ============================================================================= */

/**
 * Catégories de produits proposées dans l'éditeur de devis (slugs).
 * Décision Bastien 28/07/2026 : « réparation » + « suspente ».
 */
function gacct_quote_product_category_slugs() {
	return apply_filters( 'gacct_quote_product_cats', array( 'reparation', 'suspentes-travaux' ) );
}

/**
 * Produits sélectionnables dans le devis complémentaire.
 *
 * @return array[] { id, name, price_ttc }
 */
function gacct_quote_products() {
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
				'terms'    => gacct_quote_product_category_slugs(),
			),
		),
	) );

	$products = array();

	foreach ( $query->posts as $post ) {
		$product = wc_get_product( $post->ID );

		if ( ! $product ) {
			continue;
		}

		$products[] = array(
			'id'        => $post->ID,
			'name'      => $product->get_name(),
			'price_ttc' => (float) wc_get_price_including_tax( $product ),
		);
	}

	return apply_filters( 'gacct_quote_products', $products );
}

/**
 * Produits « demande de devis après réception » : une commande qui ne contient
 * QUE cela (hors frais de port) est une pure demande de devis — en cas de
 * refus, il n'y a pas d'intervention de repli, le matériel est retourné.
 */
function gacct_quote_devis_product_ids() {
	return array_map( 'absint', apply_filters( 'gacct_quote_devis_product_ids', array( 696 ) ) );
}

/**
 * La commande est-elle une pure « demande de devis » ?
 * Les lignes de port (mêmes motifs que la check-list de réception) et les
 * lignes ajoutées par le devis sont ignorées.
 */
function gacct_quote_is_devis_only( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	$devis_ids = gacct_quote_devis_product_ids();
	$has_devis = false;

	foreach ( $order->get_items() as $item ) {
		if ( '' !== (string) $item->get_meta( GACCT_QUOTE_ITEM_FLAG ) ) {
			continue;
		}

		$name = trim( wp_strip_all_tags( $item->get_name() ) );

		if ( preg_match( '/frais|port|exp[ée]dition|retour|^colis\b/iu', $name ) ) {
			continue;
		}

		if ( in_array( absint( $item->get_product_id() ), $devis_ids, true ) ) {
			$has_devis = true;
			continue;
		}

		return false; // Une vraie prestation initiale existe.
	}

	return $has_devis;
}

/* =============================================================================
 *  LIGNES SUPPLÉMENTAIRES DU DEVIS
 * ============================================================================= */

/**
 * Lignes de commande ajoutées par le devis complémentaire.
 *
 * @return WC_Order_Item_Product[] Indexées par item_id.
 */
function gacct_quote_extra_items( $order ) {
	$extras = array();

	if ( ! $order instanceof WC_Order ) {
		return $extras;
	}

	foreach ( $order->get_items() as $item_id => $item ) {
		if ( '' !== (string) $item->get_meta( GACCT_QUOTE_ITEM_FLAG ) ) {
			$extras[ $item_id ] = $item;
		}
	}

	return $extras;
}

/**
 * Total TTC des lignes du devis complémentaire (prix catalogue Kojito).
 */
function gacct_quote_extras_total( $order ) {
	$total = 0.0;

	foreach ( gacct_quote_extra_items( $order ) as $item ) {
		$total += gacct_kojito_montant_ligne( $item );
	}

	return round( $total, wc_get_price_decimals() );
}

/**
 * HT d'un montant TTC, selon la classe de taxe du produit (ou la classe
 * standard pour une ligne libre). Prix catalogue saisis TTC sur ce site.
 */
function gacct_quote_ht_from_ttc( $ttc, $product = null ) {
	$ttc = (float) $ttc;

	if ( ! wc_tax_enabled() ) {
		return $ttc;
	}

	$tax_class = $product instanceof WC_Product ? $product->get_tax_class() : '';
	$rates     = WC_Tax::get_base_tax_rates( $tax_class );

	if ( empty( $rates ) ) {
		return $ttc;
	}

	$taxes = WC_Tax::calc_inclusive_tax( $ttc, $rates );

	return $ttc - (float) array_sum( $taxes );
}

/**
 * Ajoute les lignes du devis à la commande, sémantique Kojito « acompte 0 » :
 * total de ligne 0 € (rien à payer maintenant), prix réel en metas — le solde
 * de l'état 5 les intégrera automatiquement via get_total_initial().
 *
 * @param WC_Order $order
 * @param array[]  $lines Chaque ligne : { product_id: int, qty: int } (catalogue)
 *                        ou { label: string, price: float TTC unitaire, qty: int } (libre).
 * @return array|WP_Error Libellés « Nom × qty — 25,00 € » des lignes ajoutées.
 */
function gacct_quote_add_lines( $order, array $lines ) {
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'gacct_quote_no_order', __( 'Commande introuvable.', 'gestion-atelier-cct' ) );
	}

	$prepared = array();

	foreach ( $lines as $line ) {
		$qty = max( 1, min( 99, absint( $line['qty'] ?? 1 ) ) );

		if ( ! empty( $line['product_id'] ) ) {
			$product = wc_get_product( absint( $line['product_id'] ) );

			if ( ! $product || 'publish' !== $product->get_status() ) {
				return new WP_Error( 'gacct_quote_bad_product', __( 'Un des produits sélectionnés n\'existe pas ou n\'est plus publié.', 'gestion-atelier-cct' ) );
			}

			$unit_ttc = (float) wc_get_price_including_tax( $product );
			$name     = $product->get_name();
		} else {
			$product  = null;
			$name     = sanitize_text_field( (string) ( $line['label'] ?? '' ) );
			$unit_ttc = round( (float) ( $line['price'] ?? 0 ), wc_get_price_decimals() );

			if ( '' === $name ) {
				return new WP_Error( 'gacct_quote_bad_label', __( 'Chaque ligne libre doit avoir un libellé.', 'gestion-atelier-cct' ) );
			}
		}

		if ( $unit_ttc <= 0 ) {
			return new WP_Error( 'gacct_quote_bad_price', sprintf( __( 'Prix invalide pour « %s ».', 'gestion-atelier-cct' ), $name ) );
		}

		$prepared[] = array(
			'product'  => $product,
			'name'     => $name,
			'qty'      => $qty,
			'unit_ttc' => $unit_ttc,
		);
	}

	if ( empty( $prepared ) ) {
		return new WP_Error( 'gacct_quote_empty', __( 'Le devis doit contenir au moins une ligne.', 'gestion-atelier-cct' ) );
	}

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
		$item->add_meta_data( GACCT_QUOTE_ITEM_FLAG, '1', true );

		$order->add_item( $item );

		$added[] = sprintf(
			'%s × %d — %s',
			$line['name'],
			$line['qty'],
			wp_strip_all_tags( wc_price( $total_ttc, array( 'currency' => $order->get_currency() ) ) )
		);
	}

	$order->save();

	return $added;
}

/**
 * Retire toutes les lignes du devis complémentaire.
 *
 * @return string[] Libellés des lignes retirées.
 */
function gacct_quote_remove_extras( $order ) {
	$removed = array();

	foreach ( gacct_quote_extra_items( $order ) as $item_id => $item ) {
		$removed[] = sprintf(
			'%s × %d — %s',
			$item->get_name(),
			$item->get_quantity(),
			wp_strip_all_tags( wc_price( gacct_kojito_montant_ligne( $item ), array( 'currency' => $order->get_currency() ) ) )
		);
		$order->remove_item( $item_id );
	}

	if ( $removed ) {
		$order->save();
	}

	return $removed;
}

/* =============================================================================
 *  ENVOI DU DEVIS (action opérateur)
 * ============================================================================= */

/**
 * Construit et envoie le devis complémentaire : pose les lignes, enregistre le
 * commentaire, puis déclenche l'état 3 (email + lien sécurisé du workflow).
 * Rejouable à l'état 3 : les lignes précédentes sont remplacées, le lien
 * régénéré et l'email renvoyé.
 *
 * @param int    $revision_id ID du CCT revision.
 * @param array  $lines       Voir gacct_quote_add_lines().
 * @param string $comment     Commentaire de l'atelier affiché au client.
 * @return array|WP_Error { added: string[], resent: bool }
 */
function gacct_quote_send( $revision_id, array $lines, $comment = '' ) {
	$revision_id = absint( $revision_id );
	$revision    = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

	if ( ! $revision ) {
		return new WP_Error( 'gacct_quote_not_found', __( 'Dossier introuvable.', 'gestion-atelier-cct' ) );
	}

	$state = absint( $revision['etat_de_la_commande'] ?? 0 );

	if ( ! in_array( $state, array( 2, 3 ), true ) ) {
		return new WP_Error( 'gacct_quote_bad_state', __( 'Le devis complémentaire ne peut être envoyé qu\'après réception de la voile (états 2 et 3).', 'gestion-atelier-cct' ) );
	}

	$order = gacct_op_get_order_for_revision( $revision );

	if ( ! $order ) {
		return new WP_Error( 'gacct_quote_no_order', __( 'Commande liée introuvable.', 'gestion-atelier-cct' ) );
	}

	// Ré-édition : on repart des prestations initiales.
	$removed = gacct_quote_remove_extras( $order );
	$added   = gacct_quote_add_lines( $order, $lines );

	if ( is_wp_error( $added ) ) {
		return $added;
	}

	$comment = trim( sanitize_textarea_field( (string) $comment ) );

	$order->update_meta_data( GACCT_QUOTE_META_COMMENT, $comment );
	$order->update_meta_data( GACCT_QUOTE_META_SENT_AT, current_time( 'mysql' ) );
	$order->delete_meta_data( GACCT_QUOTE_META_REMINDED_AT );
	$order->delete_meta_data( GACCT_QUOTE_META_DECISION );
	$order->delete_meta_data( GACCT_QUOTE_META_DECIDED_AT );
	$order->delete_meta_data( GACCT_QUOTE_META_REFUSAL_MODE );
	$order->save();

	if ( 2 === $state ) {
		$result = gacct_op_change_state( $revision_id, 3 );
	} else {
		// Déjà à l'état 3 : nouveau lien + nouvel email, sans transition.
		$result = gacct_op_resend_state_email( $revision_id );
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$message = sprintf(
		__( 'Devis complémentaire envoyé au client — lignes : %s', 'gestion-atelier-cct' ),
		implode( ' ; ', $added )
	);

	if ( $removed ) {
		$message .= sprintf( __( ' (remplace : %s)', 'gestion-atelier-cct' ), implode( ' ; ', $removed ) );
	}

	if ( '' !== $comment ) {
		$message .= sprintf( __( ' — commentaire : %s', 'gestion-atelier-cct' ), $comment );
	}

	gacct_op_add_signed_note( $order, $message );

	return array(
		'added'  => $added,
		'resent' => 3 === $state,
	);
}

/* =============================================================================
 *  DÉCISION DU CLIENT
 * ============================================================================= */

/**
 * Décision enregistrée sur la commande.
 *
 * @return array { decision: ''|'accepted'|'refused', decided_at: string, mode: ''|'partial'|'return' }
 */
function gacct_quote_decision( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return array( 'decision' => '', 'decided_at' => '', 'mode' => '' );
	}

	return array(
		'decision'   => (string) $order->get_meta( GACCT_QUOTE_META_DECISION ),
		'decided_at' => (string) $order->get_meta( GACCT_QUOTE_META_DECIDED_AT ),
		'mode'       => (string) $order->get_meta( GACCT_QUOTE_META_REFUSAL_MODE ),
	);
}

function gacct_quote_mark_decision( $order, $decision, $mode = '' ) {
	$order->update_meta_data( GACCT_QUOTE_META_DECISION, $decision );
	$order->update_meta_data( GACCT_QUOTE_META_DECIDED_AT, current_time( 'mysql' ) );

	if ( '' !== $mode ) {
		$order->update_meta_data( GACCT_QUOTE_META_REFUSAL_MODE, $mode );
	}

	$order->save();
}

/**
 * Refus du devis par le client (lien sécurisé) :
 * - retire les lignes supplémentaires (la commande revient au périmètre initial) ;
 * - révision → état 8 « Devis refusé » ;
 * - email client (template `quote_refused_partial` ou `quote_refused_return`
 *   selon que la commande est une pure demande de devis) + copie admin.
 *
 * L'atelier reprend la main : transition 8→4 depuis la console pour lancer
 * l'intervention sur les prestations initiales (cas général), ou retour du
 * matériel (pure demande de devis).
 *
 * @return array|WP_Error { mode: 'partial'|'return' }
 */
function gacct_quote_refuse( $order, $revision_id ) {
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'gacct_quote_no_order', __( 'Commande introuvable.', 'gestion-atelier-cct' ) );
	}

	$revision_id = absint( $revision_id );
	$prev        = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

	if ( ! $prev ) {
		return new WP_Error( 'gacct_quote_not_found', __( 'Dossier introuvable.', 'gestion-atelier-cct' ) );
	}

	$mode    = gacct_quote_is_devis_only( $order ) ? 'return' : 'partial';
	$removed = gacct_quote_remove_extras( $order );

	gacct_quote_mark_decision( $order, 'refused', $mode );

	// État 8 posé directement : aucune notification d'état n'existe pour 8,
	// les emails de refus (ci-dessous) sont dédiés. Le hook JetEngine est tout
	// de même émis pour les éventuels autres écouteurs.
	$fields = array( 'etat_de_la_commande' => (string) GACCT_STATE_QUOTE_REFUSED );

	if ( ! jwcct_update_cct_item( JWCCT_CCT_REVISION, $revision_id, $fields ) ) {
		return new WP_Error( 'gacct_quote_update_failed', __( 'La mise à jour du dossier a échoué.', 'gestion-atelier-cct' ) );
	}

	$new_item = array_merge( $prev, $fields, array( '_ID' => $revision_id ) );
	do_action( 'jet-engine/custom-content-types/updated-item/revision', $new_item, $prev, null );

	$order->add_order_note( sprintf(
		'return' === $mode
			? __( 'Devis REFUSÉ par le client via lien sécurisé (commande « demande de devis » seule) : le matériel doit lui être retourné. Lignes retirées : %s', 'gestion-atelier-cct' )
			: __( 'Devis REFUSÉ par le client via lien sécurisé : intervention à réaliser sur les prestations initiales uniquement. Lignes retirées : %s', 'gestion-atelier-cct' ),
		$removed ? implode( ' ; ', $removed ) : __( '(aucune)', 'gestion-atelier-cct' )
	) );
	$order->save();

	$template = 'return' === $mode ? 'quote_refused_return' : 'quote_refused_partial';

	$sent = gacct_pay_send_email(
		$order->get_billing_email(),
		$template,
		gacct_pay_email_variables( $order ),
		true
	);

	$order->add_order_note( $sent
		? sprintf( __( 'Email « devis refusé » (%1$s) envoyé au client (%2$s).', 'gestion-atelier-cct' ), $template, $order->get_billing_email() )
		: sprintf( __( 'ERREUR : échec de l\'envoi de l\'email « devis refusé » (%s).', 'gestion-atelier-cct' ), $template ) );
	$order->save();

	return array( 'mode' => $mode );
}

/* =============================================================================
 *  LIEN SÉCURISÉ (régénération pour la relance)
 * ============================================================================= */

/**
 * Régénère un lien de validation (token à usage unique, hash HMAC en meta) —
 * miroir de GACCT_Plugin::create_validation_url(), qui est privée. Utilisé par
 * la relance J+3 : l'ancien lien devient caduc, l'email de relance porte le
 * nouveau.
 */
function gacct_quote_validation_url( $order, $revision_id ) {
	$token = wp_generate_password( 32, false, false );

	$order->update_meta_data( GACCT_Plugin::META_VALIDATION_TOKEN_HASH, hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ) );
	$order->update_meta_data( GACCT_Plugin::META_VALIDATION_TOKEN_CREATED_AT, current_time( 'mysql' ) );
	$order->delete_meta_data( GACCT_Plugin::META_VALIDATION_TOKEN_USED_AT );
	$order->update_meta_data( GACCT_Plugin::META_VALIDATION_REVISION_ID, absint( $revision_id ) );
	$order->save();

	return add_query_arg(
		array(
			'order_id' => $order->get_id(),
			'token'    => $token,
		),
		home_url( '/' . trim( apply_filters( 'gacct_validation_path', 'devis-a-valider' ), '/' ) . '/' )
	);
}

/* =============================================================================
 *  RELANCE J+3 (cron horaire existant)
 * ============================================================================= */

add_action( GACCT_PAY_HOURLY_EVENT, 'gacct_quote_process_reminders', 20 );

/**
 * Relance les devis sans réponse : révisions à l'état 3 dont l'envoi date de
 * plus de {quote_reminder_days} jours, une seule relance par devis envoyé.
 */
function gacct_quote_process_reminders() {
	global $wpdb;

	$settings = gacct_pay_settings();
	$days     = isset( $settings['quote_reminder_days'] ) ? max( 1, (int) $settings['quote_reminder_days'] ) : 3;

	$rev_table = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_REVISION;
	$rows      = $wpdb->get_results(
		"SELECT _ID, order_id FROM {$rev_table}
		 WHERE cct_status = 'publish' AND CAST(etat_de_la_commande AS UNSIGNED) = 3 AND order_id > 0",
		ARRAY_A
	);

	foreach ( (array) $rows as $row ) {
		$order = wc_get_order( absint( $row['order_id'] ) );

		if ( ! $order || $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
			continue;
		}

		if ( '' !== (string) $order->get_meta( GACCT_QUOTE_META_REMINDED_AT ) ) {
			continue;
		}

		// Date d'envoi du devis : meta dédiée, sinon celle du token (anciens dossiers).
		$sent_at = (string) $order->get_meta( GACCT_QUOTE_META_SENT_AT );

		if ( '' === $sent_at ) {
			$sent_at = (string) $order->get_meta( GACCT_Plugin::META_VALIDATION_TOKEN_CREATED_AT );
		}

		$sent_ts = $sent_at ? strtotime( $sent_at ) : 0;

		if ( ! $sent_ts || ( current_time( 'timestamp' ) - $sent_ts ) < $days * DAY_IN_SECONDS ) {
			continue;
		}

		$revision_id = absint( $row['_ID'] );
		$url         = gacct_quote_validation_url( $order, $revision_id );

		$sent = gacct_pay_send_email(
			$order->get_billing_email(),
			'quote_reminder',
			gacct_pay_email_variables( $order, array(
				'{validation_url}' => esc_url( $url ),
				'{quote_lines}'    => gacct_quote_lines_html( $order ),
				'{quote_balance}'  => wp_strip_all_tags( wc_price( gacct_quote_new_balance( $order ) ) ),
			) )
		);

		$order->update_meta_data( GACCT_QUOTE_META_REMINDED_AT, current_time( 'mysql' ) );
		$order->add_order_note( $sent
			? sprintf( __( 'Relance devis sans réponse (J+%d) envoyée au client, lien régénéré.', 'gestion-atelier-cct' ), $days )
			: __( 'ERREUR : échec de l\'envoi de la relance devis.', 'gestion-atelier-cct' ) );
		$order->save();
	}
}

/* =============================================================================
 *  DONNÉES POUR EMAILS ET PAGE DEVIS
 * ============================================================================= */

/**
 * Liste HTML des lignes du devis complémentaire (pour les emails).
 */
function gacct_quote_lines_html( $order ) {
	$extras = gacct_quote_extra_items( $order );

	if ( ! $extras ) {
		return '';
	}

	$html = '<ul>';

	foreach ( $extras as $item ) {
		$html .= sprintf(
			'<li><strong>%s</strong>%s — %s</li>',
			esc_html( $item->get_name() ),
			$item->get_quantity() > 1 ? ' × ' . (int) $item->get_quantity() : '',
			esc_html( wp_strip_all_tags( wc_price( gacct_kojito_montant_ligne( $item ), array( 'currency' => $order->get_currency() ) ) ) )
		);
	}

	return $html . '</ul>';
}

/**
 * Acompte déjà payé (même logique que la page de confirmation : meta Kojito,
 * repli sur le total courant de la commande).
 */
function gacct_quote_deposit_paid( $order ) {
	$deposit = $order->get_meta( '_kojito_acompte_paye' );

	return '' === $deposit ? (float) $order->get_total() : (float) $deposit;
}

/**
 * Nouveau solde estimé si le devis est accepté (total réévalué − acompte payé).
 */
function gacct_quote_new_balance( $order ) {
	return round( max( 0, gacct_kojito_total_initial( $order ) - gacct_quote_deposit_paid( $order ) ), wc_get_price_decimals() );
}

/**
 * Données de la page /devis-a-valider/ (templates/quote.php).
 */
function gacct_quote_page_data( $order ) {
	$initial_items = array();
	$extra_items   = array();

	foreach ( $order->get_items() as $item ) {
		$row = array(
			'name'  => $item->get_name(),
			'qty'   => (int) $item->get_quantity(),
			'total' => gacct_kojito_montant_ligne( $item ),
		);

		if ( '' !== (string) $item->get_meta( GACCT_QUOTE_ITEM_FLAG ) ) {
			$extra_items[] = $row;
		} else {
			$initial_items[] = $row;
		}
	}

	$total_initial = gacct_kojito_total_initial( $order );
	$extras_total  = gacct_quote_extras_total( $order );
	$deposit       = gacct_quote_deposit_paid( $order );

	// Matériel (CCT revision).
	$materiel    = '';
	$revision_id = (int) $order->get_meta( JWCCT_ORDER_REVISION_ID );

	if ( ! $revision_id ) {
		$revision_id = absint( $order->get_meta( GACCT_Plugin::META_VALIDATION_REVISION_ID ) );
	}

	if ( $revision_id ) {
		$revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );
		if ( is_array( $revision ) ) {
			$materiel = implode( ' · ', array_filter( array(
				ucfirst( trim( (string) ( $revision['marque'] ?? '' ) ) ),
				trim( (string) ( $revision['modele'] ?? '' ) ),
				strtoupper( trim( (string) ( $revision['taille'] ?? '' ) ) ),
			) ) );
		}
	}

	return apply_filters( 'gacct_quote_page_data', array(
		'order'          => $order,
		'reference'      => $order->get_order_number(),
		'first_name'     => $order->get_billing_first_name(),
		'materiel'       => $materiel,
		'comment'        => (string) $order->get_meta( GACCT_QUOTE_META_COMMENT ),
		'sent_at'        => (string) $order->get_meta( GACCT_QUOTE_META_SENT_AT ),
		'initial_items'  => $initial_items,
		'extra_items'    => $extra_items,
		'previous_total' => round( $total_initial - $extras_total, wc_get_price_decimals() ),
		'extras_total'   => $extras_total,
		'total_initial'  => $total_initial,
		'deposit'        => $deposit,
		'new_balance'    => gacct_quote_new_balance( $order ),
		'is_devis_only'  => gacct_quote_is_devis_only( $order ),
		'logo_url'       => (string) get_option( 'woocommerce_email_header_image' ),
		'accent'         => (string) get_option( 'woocommerce_email_base_color', '#20c4c3' ),
		'site_name'      => get_bloginfo( 'name' ),
		'account_url'    => wc_get_page_permalink( 'myaccount' ),
		'contact_phone'  => gacct_pay_settings()['contact_phone'],
		'contact_hours'  => gacct_pay_settings()['contact_hours'],
	), $order );
}

/**
 * Rend la page devis (standalone, hors thème) et termine la requête.
 *
 * @param string        $variant quote|accepted|refused_partial|refused_return|used
 * @param WC_Order|null $order
 */
function gacct_quote_render_page( $variant, $order = null ) {
	nocache_headers();

	$data = $order instanceof WC_Order
		? gacct_quote_page_data( $order )
		: array(
			'reference'     => '',
			'first_name'    => '',
			'logo_url'      => (string) get_option( 'woocommerce_email_header_image' ),
			'accent'        => (string) get_option( 'woocommerce_email_base_color', '#20c4c3' ),
			'site_name'     => get_bloginfo( 'name' ),
			'account_url'   => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ),
			'contact_phone' => gacct_pay_settings()['contact_phone'],
			'contact_hours' => gacct_pay_settings()['contact_hours'],
		);

	$data['variant'] = $variant;

	include dirname( __DIR__ ) . '/templates/quote.php';
	exit;
}
