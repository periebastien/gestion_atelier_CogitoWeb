<?php
/**
 * Plugin Name: GACCT - Factures PDF (dates de paiement, facture d'acompte)
 * Description: Lignes « Acompte réglé le … » et « Solde réglé le … » sur les factures PDF Invoices & Packing Slips (données Kojito), et facture d'acompte numérotée à part (AC-…), envoyée automatiquement au paiement de l'acompte.
 * Version: 1.2.0
 */

defined( 'ABSPATH' ) || exit;

define( 'GACCT_FACTURES_DIR', __DIR__ . '/gacct-factures-pdf' );

/* ------------------------------------------------- paiements de la commande --- */

/** Date Kojito (Y-m-d H:i:s) en JJ/MM/AAAA, '' si vide. */
function gacct_factures_date_fr( $valeur ) {
	$ts = $valeur ? strtotime( $valeur ) : false;
	return $ts ? wp_date( 'd/m/Y', $ts ) : '';
}

/**
 * Date de paiement de l'acompte. Kojito la pose au paiement par carte et la
 * console au virement encaissé ; une commande passée à la main en « Acompte
 * payé » (paiement au terminal de la boutique…) n'en a pas : on reprend alors
 * le moment de ce passage dans l'historique de la commande.
 */
function gacct_factures_date_acompte( WC_Order $order ) {
	$date = (string) $order->get_meta( '_kojito_date_acompte_paye' );
	if ( '' !== $date ) {
		return $date;
	}
	foreach ( array_reverse( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) as $note ) {
		if ( false !== strpos( wp_strip_all_tags( $note->content ), 'à Acompte payé' ) ) {
			return $note->date_created->date( 'Y-m-d H:i:s' );
		}
	}
	return '';
}

/**
 * Mode de chaque paiement : une transaction enregistrée = carte ; sinon virement
 * seulement s'il en reste une trace sur la commande. Dans le doute, aucun mode
 * plutôt qu'un mode faux.
 */
function gacct_factures_mode( $transaction, $virement ) {
	if ( '' !== (string) $transaction ) {
		return __( 'par carte', 'gestion-atelier-cct' );
	}
	return $virement ? __( 'par virement', 'gestion-atelier-cct' ) : '';
}

/** Acompte réglé : montant, date (Y-m-d H:i:s) et mode (« par carte »…). */
function gacct_factures_acompte( WC_Order $order ) {
	return array(
		'montant' => (float) $order->get_meta( '_kojito_acompte_paye' ),
		'date'    => gacct_factures_date_acompte( $order ),
		'mode'    => gacct_factures_mode( $order->get_meta( '_kojito_transaction_acompte' ), 'bacs' === $order->get_payment_method() ),
	);
}

/** Solde réglé : montant, date et mode (ou motif saisi par l'atelier). */
function gacct_factures_solde( WC_Order $order ) {
	$mode = gacct_factures_mode( $order->get_meta( '_kojito_transaction_solde' ), 'bacs' === $order->get_payment_method() || $order->get_meta( '_gacct_balance_bacs_pending' ) );

	// Solde par carte CAWL validé au retour du client (avant le webhook) : Kojito ne
	// voit pas la transaction. Une commande CAWL sans trace de virement = carte (08/10/2026).
	if ( '' === $mode && 0 === strpos( (string) $order->get_payment_method(), 'cawl' ) ) {
		$mode = __( 'par carte', 'gestion-atelier-cct' );
	}

	// Solde forcé depuis la console atelier : le motif saisi (ex. « VRMT / 02.10.2026 ») dit comment
	// et quand l'argent est arrivé, plus fiable pour le pointage que le mode déduit.
	if ( '' === (string) $order->get_meta( '_kojito_transaction_solde' ) ) {
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			if ( preg_match( '/Forcer le paiement du solde.*?motif\s*:\s*(.+?)\s+(?:—|-)\s+par\s/u', wp_strip_all_tags( $note->content ), $m ) ) {
				$mode = '(' . trim( $m[1] ) . ')';
				break;
			}
		}
	}

	return array(
		'montant' => (float) $order->get_meta( '_kojito_solde_paye' ),
		'date'    => (string) $order->get_meta( '_kojito_date_solde_paye' ),
		'mode'    => $mode,
	);
}

/* ------------------------------------------------------- facture définitive --- */

add_filter( 'wpo_wcpdf_woocommerce_totals', 'gacct_factures_lignes_paiements', 20, 3 );

/**
 * Lignes de paiement datées sous le total de la facture, avec la référence de
 * la facture d'acompte. Sans meta Kojito (commande sans acompte, autre site), la
 * facture reste inchangée.
 */
function gacct_factures_lignes_paiements( $totals, $order, $document_type ) {
	if ( 'invoice' !== $document_type || ! $order instanceof WC_Order ) {
		return $totals;
	}

	$devise  = array( 'currency' => $order->get_currency() );
	$acompte = gacct_factures_acompte( $order );
	$solde   = gacct_factures_solde( $order );
	$lignes  = array();

	if ( $acompte['montant'] > 0 ) {
		$date  = gacct_factures_date_fr( $acompte['date'] );
		$label = '' !== $date
			/* translators: 1: date, 2: mode de paiement */
			? sprintf( __( 'Acompte réglé le %1$s %2$s', 'gestion-atelier-cct' ), $date, $acompte['mode'] )
			: trim( __( 'Acompte réglé', 'gestion-atelier-cct' ) . ' ' . $acompte['mode'] );
		$ref = gacct_fa_reference( $order );
		if ( $ref ) {
			$label = trim( $label ) . '<br><small>' . esc_html( $ref ) . '</small>';
		}
		$lignes['gacct_acompte'] = array(
			'label' => trim( $label ),
			'value' => wc_price( $acompte['montant'], $devise ),
		);
	}
	if ( $solde['montant'] > 0 && '' !== gacct_factures_date_fr( $solde['date'] ) ) {
		$lignes['gacct_solde'] = array(
			'label' => trim( sprintf( __( 'Solde réglé le %1$s %2$s', 'gestion-atelier-cct' ), gacct_factures_date_fr( $solde['date'] ), $solde['mode'] ) ),
			'value' => wc_price( $solde['montant'], $devise ),
		);
	}

	if ( ! $lignes ) {
		return $totals;
	}

	// Commande pas encore soldée : Kojito affiche déjà « Acompte réglé », on le remplace par la ligne datée.
	unset( $totals['kojito_acompte'] );

	$resultat = array();
	foreach ( $totals as $cle => $ligne ) {
		$resultat[ $cle ] = $ligne;
		if ( 'order_total' === $cle ) {
			$resultat += $lignes;
		}
	}
	return $resultat + $lignes;
}

/* ------------------------------------------------------- facture d'acompte --- */

require_once GACCT_FACTURES_DIR . '/facture-acompte.php';
