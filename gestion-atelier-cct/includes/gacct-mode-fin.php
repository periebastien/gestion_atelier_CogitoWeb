<?php
/**
 * Mode de règlement « tout à la fin » (14/10/2026, AEROTECH).
 *
 * Réglage « Mode de règlement » de la page Paiements & relances. Dans ce mode
 * l'atelier ne prend AUCUN acompte : les prestations ont un acompte à 0, le
 * client confirme sa demande sans payer, et la totalité est demandée en fin
 * d'intervention (par virement chez AEROTECH).
 *
 * Ce fichier ne fait rien tant que le mode n'est pas activé : le comportement
 * historique (acompte + solde) reste strictement inchangé.
 *
 * Il regroupe ce qui ne pouvait pas vivre dans les gabarits :
 * - un dictionnaire de reformulation des phrases (filtre gettext), pour les
 *   écrans client et la console : plus de mention d'acompte ;
 * - le libellé du statut WooCommerce « Acompte payé » (Kojito) ;
 * - les lignes de totaux de Kojito (pas de ligne « Acompte réglé 0 € ») ;
 * - un seul e-mail à la commande : la confirmation de demande du plugin, sans
 *   l'e-mail WooCommerce « Commande en cours » en double.
 *
 * Les e-mails du plugin ont leurs variantes dans gacct_pay_add_mode_end_variants()
 * (gacct-payments.php), les blocs structurels dans les gabarits.
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

/**
 * Phrases reformulées en mode « tout à la fin » : msgid exact => texte.
 *
 * @return array<string,string>
 */
function gacct_mode_fin_dictionnaire() {
	return array(
		// Confirmation de commande (thankyou.php).
		'Votre acompte est encaissé et le %s vous est réservé à l’atelier.' => 'Votre demande est confirmée et le %s vous est réservé à l’atelier.',
		'Votre acompte est encaissé et votre créneau atelier est réservé.' => 'Votre demande est confirmée et votre créneau atelier est réservé.',
		'Solde puis expédition retour' => 'Règlement puis expédition retour',
		'Vous réglez les %s restants en ligne après avoir consulté votre rapport. Le colis part le jour ouvré suivant.' => 'Une fois l’intervention terminée, vous réglez les %s par virement. Le colis part dès réception du virement.',
		'Solde estimé, à régler en fin d’intervention' => 'Montant estimé, à régler en fin d’intervention',
		'Solde estimé' => 'À régler (estimation)',
		'Solde de %1$s pour la commande %2$s. Dès réception du virement, votre matériel repart vers vous et vous recevez le suivi du colis. Les coordonnées ci-dessous viennent aussi de partir par e-mail.' => 'Montant de %1$s pour la commande %2$s. Dès réception du virement, votre matériel repart vers vous et vous recevez le suivi du colis. Les coordonnées ci-dessous viennent aussi de partir par e-mail.',
		'Merci %s, votre solde est réglé' => 'Merci %s, votre intervention est réglée',
		'Votre solde est réglé' => 'Votre intervention est réglée',
		'Nous n’avons jamais reçu votre matériel et le créneau réservé a été libéré ; votre acompte reste acquis, comme indiqué lors de votre commande. Vous souhaitez replanifier l’intervention ? Contactez l’atelier, nous trouverons une nouvelle date ensemble.' => 'Nous n’avons jamais reçu votre matériel et le créneau réservé a été libéré. Vous souhaitez replanifier l’intervention ? Contactez l’atelier, nous trouverons une nouvelle date ensemble.',
		'Cette étape s’ouvrira dès la réception de votre paiement : vous recevrez alors par e-mail les consignes d’expédition, l’adresse de l’atelier et votre bon d’intervention à glisser dans le colis. Vous pourrez aussi déclarer votre numéro de suivi ici même.' => 'Cette étape s’ouvrira dès la confirmation de votre demande : vous recevrez alors par e-mail les consignes d’expédition, l’adresse de l’atelier et votre bon d’intervention à glisser dans le colis. Vous pourrez aussi déclarer votre numéro de suivi ici même.',
		'Dès votre paiement reçu' => 'Dès la confirmation de votre demande',

		// Détail de commande (view-order.php) et espace client.
		'Nous n’avons jamais reçu votre matériel et le créneau qui vous était réservé a été libéré. Votre acompte reste acquis, comme indiqué lors de votre commande. Vous souhaitez replanifier l’intervention ? Contactez l’atelier : nous trouverons une nouvelle date ensemble.' => 'Nous n’avons jamais reçu votre matériel et le créneau qui vous était réservé a été libéré. Vous souhaitez replanifier l’intervention ? Contactez l’atelier : nous trouverons une nouvelle date ensemble.',
		'Nous n’avons jamais reçu votre matériel : le créneau réservé a été libéré et l’acompte reste acquis. Contactez l’atelier pour replanifier l’intervention.' => 'Nous n’avons jamais reçu votre matériel : le créneau réservé a été libéré. Contactez l’atelier pour replanifier l’intervention.',
		'Les travaux complémentaires sont lancés. Le solde vous sera demandé à la fin de l\'intervention.' => 'Les travaux complémentaires sont lancés. Le règlement vous sera demandé à la fin de l’intervention.',
		'Il ne reste que le solde de %s à régler pour que votre matériel reparte vers vous.' => 'Il ne reste que %s à régler pour que votre matériel reparte vers vous.',
		'Régler le solde' => 'Régler mon intervention',
		'Votre virement de solde est attendu' => 'Votre virement est attendu',
		'Solde de %s. Dès réception, votre matériel repart vers vous.' => 'Montant de %s. Dès réception, votre matériel repart vers vous.',
		'Le paiement de votre acompte n’a pas abouti. Réessayez pour confirmer votre créneau.' => 'Le paiement n’a pas abouti. Réessayez ou contactez l’atelier.',
		'Solde à régler' => 'À régler',
		'Payer le solde' => 'Régler mon intervention',
		'Solde restant' => 'Reste à régler',
		'Acompte payé' => 'Demande confirmée',

		// Règlement de fin d'intervention (order-pay.php, gacct-balance.php).
		'Règlement du solde' => 'Règlement de votre intervention',
		'Votre intervention est terminée. Réglez le solde ci-dessous : votre matériel repart dès l’encaissement, avec le suivi du colis, et votre rapport de contrôle apparaît dans votre espace client.' => 'Votre intervention est terminée. Réglez-la ci-dessous : votre matériel repart dès l’encaissement, avec le suivi du colis, et votre rapport de contrôle apparaît dans votre espace client.',
		'Solde à régler aujourd’hui' => 'À régler aujourd’hui',
		'Une question sur ce solde ? Appelez-nous au %1$s (%2$s).' => 'Une question sur ce règlement ? Appelez-nous au %1$s (%2$s).',
		'Ce solde est déjà réglé' => 'Cette intervention est déjà réglée',
		'Votre virement de solde est annoncé' => 'Votre virement est annoncé',
		'Le solde n’est pas encore à régler' => 'Rien à régler pour l’instant',
		'Nous vous demanderons le solde une fois l’intervention terminée, par e-mail et ici même.' => 'Nous vous demanderons le règlement une fois l’intervention terminée, par e-mail et ici même.',

		// Devis complémentaire (quote.php).
		'Solde à régler à la fin de l\'intervention' => 'À régler à la fin de l’intervention',
		'Rien à payer aujourd\'hui : le solde ne vous sera demandé qu\'une fois l\'intervention terminée.' => 'Rien à payer aujourd’hui : le règlement ne vous sera demandé qu’une fois l’intervention terminée.',
		'Votre accord est bien enregistré : l\'atelier peut lancer l\'ensemble des travaux. Le solde vous sera demandé une fois l\'intervention terminée.' => 'Votre accord est bien enregistré : l’atelier peut lancer l’ensemble des travaux. Le règlement vous sera demandé une fois l’intervention terminée.',

		// Formulaire de demande (récapitulatif).
		'Acompte à payer aujourd’hui' => 'À régler aujourd’hui',
		'Acompte à payer' => 'À régler aujourd’hui',
		'Montant réglé à la commande. Le solde sera à régler une fois l\'intervention terminée.' => 'Rien n’est réglé à la commande : l’intervention se règle à la fin, par virement.',

		// Console atelier (libellés de paiement).
		'Intervention finie, solde à payer' => 'Intervention finie, règlement à recevoir',
		'Intervention terminée, demander le solde' => 'Intervention terminée, demander le règlement',
		'Forcer le paiement du solde' => 'Règlement reçu (virement)',
		'Suivi de la demande de solde' => 'Suivi de la demande de règlement',
		'Demande de solde envoyée le %s.' => 'Demande de règlement envoyée le %s.',
		'En attente du paiement du solde par le client.' => 'En attente du règlement par le client.',
		'Lien de paiement du solde' => 'Lien de règlement',
		'Total dû (acompte + solde)' => 'Total dû',
		'Acompte reçu' => 'Demande confirmée',
	);
}

/**
 * Applique le dictionnaire aux chaînes du plugin et de Kojito.
 */
function gacct_mode_fin_gettext( $translation, $text, $domain ) {
	if ( 'gestion-atelier-cct' !== $domain && 'kojito-acompte' !== $domain ) {
		return $translation;
	}
	static $dico = null;
	if ( null === $dico ) {
		$dico = gacct_mode_fin_dictionnaire();
	}
	return isset( $dico[ $text ] ) ? $dico[ $text ] : $translation;
}

function gacct_mode_fin_gettext_context( $translation, $text, $context, $domain ) {
	return gacct_mode_fin_gettext( $translation, $text, $domain );
}

/**
 * Lignes de totaux de Kojito (e-mails WooCommerce, détail de commande) : pas
 * de ligne d'acompte à 0, la ligne de solde devient le montant à régler.
 */
function gacct_mode_fin_totaux( $rows, $order ) {
	if ( isset( $rows['kojito_acompte'] ) ) {
		unset( $rows['kojito_acompte'] );
	}
	if ( isset( $rows['kojito_solde'] ) ) {
		$rows['kojito_solde']['label'] = __( 'À régler après l’intervention', 'gestion-atelier-cct' );
	}
	return $rows;
}

/**
 * Un seul e-mail client à la commande atelier : la confirmation du plugin
 * (« Demande confirmée ! Voici comment nous envoyer votre matériel »).
 * L'e-mail WooCommerce « Commande en cours », envoyé en même temps pour une
 * commande à 0 €, faisait doublon. Les commandes boutique le gardent.
 */
function gacct_mode_fin_processing_email( $enabled, $order ) {
	if ( $enabled && $order instanceof WC_Order && function_exists( 'gacct_order_is_atelier' ) && gacct_order_is_atelier( $order ) && ! gacct_order_has_boutique_items( $order ) ) {
		return false;
	}
	return $enabled;
}

/**
 * La commande contient-elle un article de la boutique (hors prestations,
 * suppléments et port de l'atelier) ? Une commande mixte garde l'e-mail.
 */
function gacct_order_has_boutique_items( WC_Order $order ) {
	$cats_atelier = (array) apply_filters( 'gacct_atelier_product_cats', array( 'revisions-controle', 'pliages-secours', 'suspentes-travaux', 'reparation', 'frais-de-port', 'supplements-biplace' ) );
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$pid = $item->get_product_id();
		if ( ! has_term( $cats_atelier, 'product_cat', $pid ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Le panier en cours est-il une demande d'intervention sans rien à payer
 * aujourd'hui (mode « tout à la fin ») ? Sert au thème pour adapter la page de
 * commande (bouton, résumé, mentions de paiement). Fiable aussi pendant les
 * rafraîchissements AJAX du checkout, où is_page() ne répond plus.
 */
function gacct_cart_is_atelier_reglement_fin() {
	if ( ! function_exists( 'gacct_pay_mode_end' ) || ! gacct_pay_mode_end() || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return false;
	}
	if ( WC()->cart->needs_payment() ) {
		return false;
	}
	$cats = (array) apply_filters( 'gacct_atelier_product_cats', array( 'revisions-controle', 'pliages-secours', 'suspentes-travaux', 'reparation', 'frais-de-port', 'supplements-biplace' ) );
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( has_term( $cats, 'product_cat', (int) $item['product_id'] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Prix catalogue TTC d'une ligne de panier (et non le montant d'acompte que
 * Kojito y place), pour l'affichage en mode « tout à la fin ».
 */
function gacct_cart_item_full_price( array $cart_item ) {
	$id      = ! empty( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : (int) $cart_item['product_id'];
	$product = wc_get_product( $id );
	if ( ! $product ) {
		return 0.0;
	}
	return (float) wc_get_price_to_display( $product, array( 'qty' => (int) $cart_item['quantity'] ) );
}

/**
 * Fin d'intervention (état 6, solde non nul) : la totalité se règle par
 * virement. La commande passe « En attente de virement » comme si le client
 * avait choisi le virement sur la page de règlement : gacct-balance.php envoie
 * alors l'e-mail « coordonnées bancaires » (RIB, montant, référence), l'espace
 * client affiche le RIB et la console attend « Règlement reçu ».
 */
function gacct_mode_fin_demande_reglement( $order, $revision_id, $balance ) {
	if ( ! $order instanceof WC_Order || $order->has_status( array( 'on-hold', 'completed', 'cancelled', 'refunded' ) ) ) {
		return;
	}
	$gateways = function_exists( 'WC' ) ? WC()->payment_gateways()->payment_gateways() : array();
	if ( empty( $gateways['bacs'] ) || 'yes' !== $gateways['bacs']->enabled ) {
		$order->add_order_note( __( 'Fin d’intervention : virement non activé dans WooCommerce, aucune demande de règlement envoyée.', 'gestion-atelier-cct' ) );
		$order->save();
		return;
	}
	$order->set_payment_method( 'bacs' );
	$order->set_payment_method_title( $gateways['bacs']->get_title() );
	$order->save();
	$order->update_status( 'on-hold', sprintf(
		/* translators: %s: montant */
		__( 'Fin d’intervention : règlement de %s demandé par virement (mode « tout à la fin »).', 'gestion-atelier-cct' ),
		wp_strip_all_tags( wc_price( (float) $balance ) )
	) );
}

/**
 * Pas d'e-mail WooCommerce « Commande en attente » pour cette demande : l'e-mail
 * « coordonnées bancaires » du plugin le remplace (montant, RIB, référence).
 */
function gacct_mode_fin_on_hold_email( $enabled, $order ) {
	if ( $enabled && $order instanceof WC_Order && function_exists( 'gacct_bal_phase' ) && 'solde' === gacct_bal_phase( $order ) ) {
		return false;
	}
	return $enabled;
}

add_action( 'init', function () {
	if ( ! function_exists( 'gacct_pay_mode_end' ) || ! gacct_pay_mode_end() ) {
		return;
	}
	add_action( 'gacct_balance_requested', 'gacct_mode_fin_demande_reglement', 10, 3 );
	// Suivi « Mes interventions » : libellés des états 0 et 6 sans acompte ni solde.
	add_filter( 'jwcct_tracker_config', function ( $config ) {
		if ( isset( $config[0] ) ) {
			$config[0]['tip'] = '<strong>Info :</strong> votre demande est en cours d’enregistrement.';
		}
		if ( isset( $config[6] ) ) {
			$config[6]['label'] = 'Intervention à régler';
			$config[6]['tip']   = '<strong>Action requise :</strong> l’intervention est terminée. Réglez-la par virement (coordonnées dans le détail de la commande) pour que votre matériel reparte.';
		}
		return $config;
	}, 20 );
	add_filter( 'woocommerce_email_enabled_customer_on_hold_order', 'gacct_mode_fin_on_hold_email', 20, 2 );
	add_filter( 'gettext', 'gacct_mode_fin_gettext', 20, 3 );
	add_filter( 'gettext_with_context', 'gacct_mode_fin_gettext_context', 20, 4 );
	add_filter( 'woocommerce_get_order_item_totals', 'gacct_mode_fin_totaux', 50, 2 );
	add_filter( 'woocommerce_email_enabled_customer_processing_order', 'gacct_mode_fin_processing_email', 20, 2 );
}, 1 );
