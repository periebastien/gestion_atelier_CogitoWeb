<?php
/**
 * Facture du club, paiement, réexpédition du lot.
 *
 * Règles (e-mail d'Hervé « Infos groupes » du 30/09/2026, décisions du 03/10) :
 * - une commande WooCommerce unique au nom du club, une ligne par prestation
 *   avec le nom du pilote et son matériel ;
 * - remise par paliers sur le nombre de VOILES facturées (secours exclus du
 *   compte), appliquée aux seules prestations remisées (catégories réglables),
 *   jamais aux suppléments ni aux réparations ;
 * - le port du retour groupé est ajouté une fois ;
 * - une voile mise en attente (drapeau en_attente du socle) sort du lot ;
 * - le club règle AVANT le retour : le paiement fait passer les dossiers en
 *   état 7 (rapport disponible), puis le lot repart en un envoi.
 *
 * Les commandes des pilotes restent à 0 € (statut « acompte payé ») : elles ne
 * sont jamais soldées, la facture du club porte tout le chiffre.
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

/** Prix TTC / HT d'une ligne de commande pilote (prix catalogue Kojito). */
function gacct_clubs_item_prices( WC_Order_Item_Product $item ) {
	$ttc = class_exists( 'Kojito_Acompte_Produit' ) ? Kojito_Acompte_Produit::prix_initial_ttc_ligne( $item ) : null;
	$ht  = $item->get_meta( '_kojito_prix_total_initial_ht' );

	if ( null === $ttc ) {
		$ttc = (float) $item->get_total() + (float) $item->get_total_tax();
	}
	if ( '' === (string) $ht ) {
		$product = $item->get_product();
		$ht      = $product ? (float) wc_get_price_excluding_tax( $product, array( 'qty' => max( 1, (int) $item->get_quantity() ) ) ) : (float) $ttc;
	}
	return array( (float) $ttc, (float) $ht );
}

/**
 * Lignes de la facture d'un lot.
 *
 * @return array { lines: [ {product_id, name, pilote, materiel, qty, ttc, ht, remise (bool), order_id} ],
 *                 voiles, rate, base_ttc, base_ht, remise_ttc, remise_ht, hors_lot: [noms] }
 */
function gacct_clubs_invoice_preview( array $lot ) {
	$members = gacct_clubs_lot_members( (int) $lot['id'] );
	$retour  = (int) gacct_clubs_setting( 'product_retour' );
	$cats    = (array) gacct_clubs_setting( 'remise_cats' );
	$exclude = array_map( 'absint', (array) gacct_clubs_setting( 'remise_exclude' ) );
	$lines   = array();
	$hors    = array();
	$voiles  = 0;

	foreach ( $members as $m ) {
		if ( ! $m['order'] ) {
			continue;
		}
		if ( $m['hors_lot'] ) {
			$hors[] = $m['pilote'] . ( $m['materiel'] ? ' (' . $m['materiel'] . ')' : '' );
			continue;
		}
		if ( $m['is_voile'] ) {
			$voiles++;
		}
		foreach ( $m['order']->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( $pid === $retour || has_term( 'frais-de-port', 'product_cat', $pid ) ) {
				continue;
			}
			list( $ttc, $ht ) = gacct_clubs_item_prices( $item );
			$is_extra = '' !== (string) $item->get_meta( '_gacct_quote_extra' ) || '' !== (string) $item->get_meta( '_gacct_billing_extra' );
			$lines[]  = array(
				'product_id' => $pid,
				'name'       => preg_replace( '/\s+/', ' ', $item->get_name() ),
				'pilote'     => $m['pilote'],
				'materiel'   => $m['materiel'],
				'qty'        => max( 1, (int) $item->get_quantity() ),
				'ttc'        => round( $ttc, 2 ),
				'ht'         => round( $ht, 4 ),
				'remise'     => ! $is_extra && ! in_array( $pid, $exclude, true ) && gacct_clubs_product_in_cats( $pid, $cats ),
				'order_id'   => $m['order']->get_id(),
			);
		}
	}

	$rate     = gacct_clubs_rate_for( $voiles );
	$base_ttc = 0.0;
	$base_ht  = 0.0;
	foreach ( $lines as $l ) {
		if ( $l['remise'] ) {
			$base_ttc += $l['ttc'];
			$base_ht  += $l['ht'];
		}
	}

	usort( $lines, static function ( $a, $b ) {
		return array( $b['remise'], $a['pilote'] ) <=> array( $a['remise'], $b['pilote'] );
	} );

	return array(
		'lines'      => $lines,
		'voiles'     => $voiles,
		'rate'       => $rate,
		'base_ttc'   => round( $base_ttc, 2 ),
		'base_ht'    => $base_ht,
		'remise_ttc' => round( $base_ttc * $rate / 100, 2 ),
		'remise_ht'  => round( $base_ht * $rate / 100, 4 ),
		'hors_lot'   => $hors,
		'total_ttc'  => round( array_sum( wp_list_pluck( $lines, 'ttc' ) ) - $base_ttc * $rate / 100, 2 ),
	);
}

/**
 * Crée la commande WooCommerce du club.
 *
 * @param array $args { port_product_id, rate (override %), notify (bool) }
 * @return WC_Order|WP_Error
 */
function gacct_clubs_create_invoice( array $lot, array $args = array() ) {
	if ( (int) $lot['facture_order_id'] && wc_get_order( (int) $lot['facture_order_id'] ) ) {
		return new WP_Error( 'exists', 'La commande à régler du club existe déjà.' );
	}

	$prev = gacct_clubs_invoice_preview( $lot );
	if ( ! $prev['lines'] ) {
		return new WP_Error( 'empty', 'Aucune prestation à régler pour ce lot.' );
	}

	$rate = isset( $args['rate'] ) && '' !== (string) $args['rate'] ? max( 0, min( 100, (float) str_replace( ',', '.', (string) $args['rate'] ) ) ) : $prev['rate'];

	$customer = (int) $lot['demandeur_id'];
	$order    = wc_create_order( array( 'customer_id' => $customer, 'created_via' => 'gacct-clubs' ) );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	// Le nom passe par les arguments d'add_product : un set_name() après coup
	// est écrasé à l'enregistrement de la commande (vu en test le 03/10/2026).
	$item_meta = array();
	foreach ( $prev['lines'] as $l ) {
		$product = wc_get_product( $l['product_id'] );
		if ( ! $product ) {
			continue;
		}
		$item_id = $order->add_product( $product, $l['qty'], array(
			'name'     => sprintf( '%1$s · %2$s%3$s', $l['name'], $l['pilote'], $l['materiel'] ? ' · ' . $l['materiel'] : '' ),
			'subtotal' => $l['ht'],
			'total'    => $l['ht'],
		) );
		if ( $item_id ) {
			$item_meta[ $item_id ] = array( '_gacct_club_member_order' => $l['order_id'], '_gacct_club_remise' => $l['remise'] ? '1' : '0' );
		}
	}

	$port = isset( $args['port_product_id'] ) ? absint( $args['port_product_id'] ) : 0;
	if ( $port && ( $pp = wc_get_product( $port ) ) ) {
		$order->add_product( $pp, 1, array( 'name' => 'Retour groupé · ' . $pp->get_name() ) );
	}

	if ( $rate > 0 && $prev['base_ht'] > 0 ) {
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( sprintf( 'Remise club %s %% (%d voiles)', rtrim( rtrim( number_format( $rate, 1, ',', '' ), '0' ), ',' ), $prev['voiles'] ) );
		$fee->set_amount( - round( $prev['base_ht'] * $rate / 100, 4 ) );
		$fee->set_total( - round( $prev['base_ht'] * $rate / 100, 4 ) );
		$fee->set_tax_status( 'taxable' );
		$order->add_item( $fee );
	}

	// Adresse de facturation : le club, contact = responsable.
	$club  = $lot['club'];
	$parts = preg_split( '/\s+/', trim( (string) $lot['contact_nom'] ), 2 );
	$order->set_billing_company( $club ? $club['nom'] : $lot['nom'] );
	$order->set_billing_first_name( $parts[0] ?? '' );
	$order->set_billing_last_name( $parts[1] ?? '' );
	$order->set_billing_email( gacct_clubs_lot_email( $lot ) );
	$order->set_billing_phone( (string) $lot['contact_tel'] );
	if ( $club && $club['adresse'] ) {
		$order->set_billing_address_1( preg_replace( '/\s+/', ' ', (string) $club['adresse'] ) );
	}

	$order->update_meta_data( GACCT_CLUBS_META_INVOICE, (int) $lot['id'] );
	$order->calculate_totals();
	$order->set_status( 'pending' );
	$order->add_order_note( sprintf( 'Commande à régler de la commande groupée %1$s (%2$s) : %3$d prestations, %4$d voiles, remise %5$s %%.', $lot['code'], $lot['nom'], count( $prev['lines'] ), $prev['voiles'], $rate ) );
	$order->save();

	foreach ( $item_meta as $item_id => $metas ) {
		foreach ( $metas as $k => $v ) {
			wc_update_order_item_meta( $item_id, $k, $v );
		}
	}

	gacct_clubs_update_lot( (int) $lot['id'], array( 'facture_order_id' => $order->get_id(), 'statut' => 'facture' ) );

	if ( ! empty( $args['notify'] ) ) {
		gacct_clubs_send_invoice_email( gacct_clubs_get_lot( (int) $lot['id'] ), $order );
	}

	return $order;
}

function gacct_clubs_invoice_lines_html( WC_Order $order ) {
	$rows = '';
	foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
		$total = (float) $item->get_total() + (float) $item->get_total_tax();
		$rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee">' . esc_html( $item->get_name() ) . '</td><td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap">' . wp_strip_all_tags( wc_price( $total ) ) . '</td></tr>';
	}
	return '<table style="width:100%;border-collapse:collapse;font-size:14px;margin:0 0 16px">' . $rows . '</table>';
}

function gacct_clubs_send_invoice_email( array $lot, WC_Order $order ) {
	return gacct_clubs_send( gacct_clubs_lot_email( $lot ), 'club_invoice', $lot, array(
		'{invoice_lines}' => gacct_clubs_invoice_lines_html( $order ),
		'{invoice_total}' => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total() ) ), ENT_QUOTES, 'UTF-8' ),
		'{payment_url}'   => esc_html( $order->get_checkout_payment_url() ),
		'{order_number}'  => $order->get_order_number(),
	) );
}

/* -------------------------------------------------------------- paiement --- */

add_action( 'woocommerce_order_status_changed', 'gacct_clubs_on_invoice_status', 30, 4 );

function gacct_clubs_on_invoice_status( $order_id, $old, $new, $order ) {
	$lot_id = gacct_clubs_invoice_lot_id( $order );
	if ( ! $lot_id || ! in_array( $new, wc_get_is_paid_statuses(), true ) ) {
		return;
	}
	$lot = gacct_clubs_get_lot( $lot_id );
	if ( ! $lot || in_array( $lot['statut'], array( 'paye', 'expedie' ), true ) ) {
		return;
	}

	gacct_clubs_update_lot( $lot_id, array( 'statut' => 'paye' ) );
	gacct_clubs_release_reports( gacct_clubs_get_lot( $lot_id ) );
}

/**
 * Facture réglée : les dossiers terminés (état 6) passent en 7, rapport
 * disponible pour chaque pilote (e-mail d'état 7 du socle).
 *
 * @return int Nombre de dossiers passés en 7.
 */
function gacct_clubs_release_reports( array $lot ) {
	$n = 0;
	foreach ( gacct_clubs_lot_members( (int) $lot['id'] ) as $m ) {
		if ( 6 !== $m['etat'] || $m['hors_lot'] || ! function_exists( 'gacct_op_change_state' ) ) {
			continue;
		}
		$res = gacct_op_change_state( (int) $m['revision']['_ID'], 7, array(
			'force'  => true,
			'reason' => sprintf( 'Commande à régler du club %s réglée (commande groupée %s).', $lot['nom'], $lot['code'] ),
		) );
		if ( ! is_wp_error( $res ) ) {
			$n++;
		}
	}
	return $n;
}

/* --------------------------------------------------------- réexpédition --- */

/**
 * Réexpédie le lot : tous les dossiers en état 7 passent en 8 avec le même suivi.
 *
 * @param string $carrier  Clé transporteur (gacct_ship_carriers) ou 'boutique'.
 * @param string $tracking Numéro de suivi (ignoré pour un retrait boutique).
 * @return int|WP_Error Nombre de dossiers réexpédiés.
 */
function gacct_clubs_ship_lot( array $lot, $carrier, $tracking ) {
	if ( 'paye' !== $lot['statut'] && 'expedie' !== $lot['statut'] ) {
		return new WP_Error( 'unpaid', 'La commande du club doit être réglée avant le retour du matériel.' );
	}

	$carrier  = sanitize_key( $carrier );
	$tracking = trim( sanitize_text_field( (string) $tracking ) );

	if ( 'boutique' === $carrier ) {
		$value = function_exists( 'gacct_op_pickup_marker' ) ? gacct_op_pickup_marker() : 'Remis en main propre à la boutique';
		$info  = 'retrait à la boutique';
	} else {
		if ( '' === $tracking ) {
			return new WP_Error( 'tracking', 'Indiquez le numéro de suivi du colis.' );
		}
		$url   = function_exists( 'gacct_ship_tracking_url' ) ? gacct_ship_tracking_url( $carrier, $tracking ) : '';
		$value = $url ? $url : $tracking;
		$carriers = function_exists( 'gacct_ship_carriers' ) ? gacct_ship_carriers() : array();
		$label    = isset( $carriers[ $carrier ]['label'] ) ? $carriers[ $carrier ]['label'] : ( isset( $carriers[ $carrier ] ) && is_string( $carriers[ $carrier ] ) ? $carriers[ $carrier ] : $carrier );
		$info     = sprintf( '%1$s, suivi n° %2$s', $label, $tracking );
	}

	$n = 0;
	foreach ( gacct_clubs_lot_members( (int) $lot['id'] ) as $m ) {
		if ( 7 !== $m['etat'] || $m['hors_lot'] ) {
			continue;
		}
		$res = gacct_op_change_state( (int) $m['revision']['_ID'], 8, array( 'tracking' => $value ) );
		if ( ! is_wp_error( $res ) ) {
			$n++;
		}
	}

	gacct_clubs_update_lot( (int) $lot['id'], array( 'statut' => 'expedie', 'retour_transporteur' => $carrier, 'retour_suivi' => 'boutique' === $carrier ? 'boutique' : $tracking ) );
	gacct_clubs_send( gacct_clubs_lot_email( $lot ), 'club_shipped', $lot, array( '{return_info}' => esc_html( $info ) ) );

	return $n;
}

/* ------------------------------------- dossier terminé : prêt à facturer ? --- */

add_action( 'gacct_state6_third_party', 'gacct_clubs_on_member_state6', 10, 2 );

function gacct_clubs_on_member_state6( $order, $revision_id ) {
	$lot_id = gacct_clubs_order_lot_id( $order );
	$lot    = $lot_id ? gacct_clubs_get_lot( $lot_id ) : null;
	if ( ! $lot || 'planifie' !== $lot['statut'] ) {
		return;
	}
	foreach ( gacct_clubs_lot_members( $lot_id ) as $m ) {
		if ( ! $m['hors_lot'] && $m['etat'] < 6 ) {
			return; // Encore des voiles en cours.
		}
	}
	$subject = sprintf( 'Commande groupée %1$s (%2$s) : toutes les voiles sont terminées, commande à régler à créer', $lot['code'], $lot['nom'] );
	$body    = '<p>Toutes les voiles de la commande groupée de <strong>' . esc_html( $lot['nom'] ) . '</strong> sont terminées.</p><p>Créer la commande à régler du club depuis la console : ' . esc_url( gacct_clubs_console_url( $lot_id ) ) . '</p>';
	$html    = function_exists( 'gacct_render_email_html' ) ? gacct_render_email_html( $subject, $body ) : $body;
	foreach ( function_exists( 'gacct_pay_admin_emails' ) ? (array) gacct_pay_admin_emails() : array( get_option( 'admin_email' ) ) as $to ) {
		wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}

/**
 * Page de paiement de la commande du club : gabarit maison (détail par pilote)
 * à la place du formulaire natif de WooCommerce.
 */
add_filter( 'wc_get_template', 'gacct_clubs_pay_template', 20, 2 );

function gacct_clubs_pay_template( $template, $template_name ) {
	if ( 'checkout/form-pay.php' !== $template_name ) {
		return $template;
	}
	$order_id = absint( get_query_var( 'order-pay' ) );
	$order    = $order_id ? wc_get_order( $order_id ) : false;
	if ( ! $order || ! gacct_clubs_invoice_lot_id( $order ) ) {
		return $template;
	}
	$file = GACCT_CLUBS_DIR . '/templates/order-pay-club.php';
	return file_exists( $file ) ? $file : $template;
}

/* ------------------------------------------------- chiffrage estimatif --- */

/**
 * Chiffrage d'un lot à partir de quantités par type (annoncées ou inscrites),
 * aux prix catalogue TTC des produits de référence. Demande de Bastien du
 * 08/10/2026 : Hervé et le club voient le budget et la remise dès la demande.
 *
 * @param array $qty { ip, rp, cc, secours }
 */
function gacct_clubs_estimate( array $qty ) {
	$cats    = (array) gacct_clubs_setting( 'remise_cats' );
	$exclude = array_map( 'absint', (array) gacct_clubs_setting( 'remise_exclude' ) );
	$types   = array(
		'ip'      => array( 'product_ip', 'Inspection partielle' ),
		'rp'      => array( 'product_rp', 'Révision périodique' ),
		'cc'      => array( 'product_cc', 'Contrôle complet équipement' ),
		'secours' => array( 'product_secours', 'Pliage de secours' ),
	);
	$lines = array();
	$base  = 0.0;
	$sub   = 0.0;

	foreach ( $types as $k => $t ) {
		$n = (int) ( $qty[ $k ] ?? 0 );
		$p = $n ? wc_get_product( (int) gacct_clubs_setting( $t[0] ) ) : null;
		if ( ! $p ) {
			continue;
		}
		$unit  = (float) wc_get_price_including_tax( $p );
		$total = round( $unit * $n, 2 );
		$sub  += $total;
		if ( ! in_array( $p->get_id(), $exclude, true ) && gacct_clubs_product_in_cats( $p->get_id(), $cats ) ) {
			$base += $total;
		}
		$lines[] = array( 'label' => $t[1], 'qty' => $n, 'unit' => $unit, 'total' => $total );
	}

	// Palier sur les voiles (IP, RP, contrôles complets) ; les secours profitent du taux.
	$voiles = (int) ( $qty['ip'] ?? 0 ) + (int) ( $qty['rp'] ?? 0 ) + (int) ( $qty['cc'] ?? 0 );
	$rate   = (float) gacct_clubs_rate_for( $voiles );
	$remise = round( $base * $rate / 100, 2 );

	return array(
		'lines'    => $lines,
		'subtotal' => round( $sub, 2 ),
		'voiles'   => $voiles,
		'rate'     => $rate,
		'remise'   => $remise,
		'total'    => round( $sub - $remise, 2 ),
	);
}

function gacct_clubs_lot_estimate( array $lot ) {
	return gacct_clubs_estimate( array( 'ip' => $lot['nb_ip'], 'rp' => $lot['nb_rp'], 'cc' => $lot['nb_cc'] ?? 0, 'secours' => $lot['nb_secours'] ) );
}

function gacct_clubs_rate_label( $rate ) {
	return rtrim( rtrim( number_format( (float) $rate, 1, ',', '' ), '0' ), ',' ) . ' %';
}

/** Mention commune : l'estimation n'engage pas. */
function gacct_clubs_estimate_notice() {
	return (string) apply_filters( 'gacct_clubs_estimate_notice', 'Estimation indicative, non définitive, aux tarifs actuels. Le montant final dépend du matériel réellement inscrit par vos membres, des suppléments éventuels (parachute carré ou dirigeable), des réparations éventuelles et du port du retour. La remise est recalculée sur le nombre de voiles réellement inscrites.' );
}

/**
 * Tableau du chiffrage. $mode : 'mail' (styles en ligne, e-mails et console)
 * ou 'club' (classes de l'espace Mon club).
 */
function gacct_clubs_estimate_table( array $est, $mode = 'mail' ) {
	$club = 'club' === $mode;
	$td   = $club ? '' : ' style="padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:left"';
	$tdr  = $club ? ' class="r"' : ' style="padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:right;white-space:nowrap"';
	$span = $club ? 1 : 3;

	// Mon club (mobile d'abord) : deux colonnes, « quantité × prix » sous la prestation.
	if ( $club ) {
		$h = '<div class="gcl-tbl"><table class="gcl-inv"><thead><tr><th>Prestation</th><th class="r">Montant</th></tr></thead><tbody>';
		foreach ( $est['lines'] as $l ) {
			$h .= '<tr><td>' . esc_html( $l['label'] ) . '<br><span class="gcl-note">' . (int) $l['qty'] . ' × ' . wp_kses_post( wc_price( $l['unit'] ) ) . '</span></td><td class="r">' . wp_kses_post( wc_price( $l['total'] ) ) . '</td></tr>';
		}
	} else {
		$h = '<table style="border-collapse:collapse;width:100%;max-width:560px;font-size:15px"><thead><tr><th' . $td . '>Prestation</th><th' . $tdr . '>Qté</th><th' . $tdr . '>Prix unitaire</th><th' . $tdr . '>Total</th></tr></thead><tbody>';
		foreach ( $est['lines'] as $l ) {
			$h .= '<tr><td' . $td . '>' . esc_html( $l['label'] ) . '</td><td' . $tdr . '>' . (int) $l['qty'] . '</td><td' . $tdr . '>' . wp_kses_post( wc_price( $l['unit'] ) ) . '</td><td' . $tdr . '>' . wp_kses_post( wc_price( $l['total'] ) ) . '</td></tr>';
		}
	}

	$tiers = (array) gacct_clubs_setting( 'tiers' );
	$first = $tiers ? (int) min( wp_list_pluck( $tiers, 'min' ) ) : 0;
	$lbl   = $est['rate'] > 0
		? sprintf( 'Remise club %1$s (%2$d voiles, secours compris)', gacct_clubs_rate_label( $est['rate'] ), $est['voiles'] )
		: sprintf( 'Remise club : aucune (%1$d voile%2$s, remise dès %3$d voiles)', $est['voiles'], $est['voiles'] > 1 ? 's' : '', $first );

	$h .= '<tr><td colspan="' . $span . '"' . $td . '>Sous-total</td><td' . $tdr . '>' . wp_kses_post( wc_price( $est['subtotal'] ) ) . '</td></tr>';
	$h .= '<tr' . ( $club ? ' class="disc"' : '' ) . '><td colspan="' . $span . '"' . $td . '>' . esc_html( $lbl ) . '</td><td' . $tdr . '>' . ( $est['remise'] > 0 ? '− ' . wp_kses_post( wc_price( $est['remise'] ) ) : '' ) . '</td></tr>';
	$h .= '<tr' . ( $club ? ' class="tot"' : '' ) . '><td colspan="' . $span . '"' . $td . '><strong>Total estimé TTC</strong></td><td' . $tdr . '><strong>' . wp_kses_post( wc_price( $est['total'] ) ) . '</strong></td></tr>';
	$h .= '</tbody></table>' . ( $club ? '</div>' : '' );

	return $h;
}
