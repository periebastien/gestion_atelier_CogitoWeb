<?php
/**
 * Plugin Name: GACCT - Factures PDF (dates de paiement)
 * Description: Ajoute sur les factures PDF Invoices & Packing Slips les lignes « Acompte réglé le … » et « Solde réglé le … » (données Kojito), pour pointer les encaissements.
 * Version: 1.1.1
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'wpo_wcpdf_woocommerce_totals', 'gacct_factures_lignes_paiements', 20, 3 );

/**
 * Lignes de paiement datées sous le total de la facture.
 *
 * Sans meta Kojito (commande sans acompte, autre site), la facture reste inchangée.
 */
function gacct_factures_lignes_paiements( $totals, $order, $document_type ) {
	if ( 'invoice' !== $document_type || ! $order instanceof WC_Order ) {
		return $totals;
	}

	$devise  = array( 'currency' => $order->get_currency() );
	$date_fr = function ( $valeur ) {
		$ts = $valeur ? strtotime( $valeur ) : false;
		return $ts ? wp_date( 'd/m/Y', $ts ) : '';
	};

	$acompte      = (float) $order->get_meta( '_kojito_acompte_paye' );
	$date_acompte = $date_fr( $order->get_meta( '_kojito_date_acompte_paye' ) );
	$solde        = (float) $order->get_meta( '_kojito_solde_paye' );
	$date_solde   = $date_fr( $order->get_meta( '_kojito_date_solde_paye' ) );

	// Mode de chaque paiement : une transaction enregistrée = carte ; sinon virement seulement
	// s'il en reste une trace sur la commande. Dans le doute, aucun mode plutôt qu'un mode faux.
	$mode = function ( $transaction, $virement ) {
		if ( '' !== (string) $transaction ) {
			return __( 'par carte', 'gestion-atelier-cct' );
		}
		return $virement ? __( 'par virement', 'gestion-atelier-cct' ) : '';
	};
	$est_bacs     = 'bacs' === $order->get_payment_method();
	$mode_acompte = $mode( $order->get_meta( '_kojito_transaction_acompte' ), $est_bacs );
	$mode_solde   = $mode( $order->get_meta( '_kojito_transaction_solde' ), $est_bacs || $order->get_meta( '_gacct_balance_bacs_pending' ) );
	// Solde par carte CAWL validé au retour du client (avant le webhook) : Kojito ne
	// voit pas la transaction. Une commande CAWL sans trace de virement = carte (08/10/2026).
	if ( '' === $mode_solde && 0 === strpos( (string) $order->get_payment_method(), 'cawl' ) ) {
		$mode_solde = __( 'par carte', 'gestion-atelier-cct' );
	}

	// Solde forcé depuis la console atelier : le motif saisi (ex. « VRMT / 02.10.2026 ») dit comment
	// et quand l'argent est arrivé, plus fiable pour le pointage que le mode déduit.
	if ( '' === (string) $order->get_meta( '_kojito_transaction_solde' ) ) {
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			if ( preg_match( '/Forcer le paiement du solde.*?motif\s*:\s*(.+?)\s+(?:—|-)\s+par\s/u', wp_strip_all_tags( $note->content ), $m ) ) {
				$mode_solde = '(' . trim( $m[1] ) . ')';
				break;
			}
		}
	}

	if ( $acompte <= 0 || '' === $date_acompte ) {
		return $totals;
	}

	$lignes = array(
		'gacct_acompte' => array(
			'label' => trim( sprintf( __( 'Acompte réglé le %1$s %2$s', 'gestion-atelier-cct' ), $date_acompte, $mode_acompte ) ),
			'value' => wc_price( $acompte, $devise ),
		),
	);
	if ( $solde > 0 && '' !== $date_solde ) {
		$lignes['gacct_solde'] = array(
			'label' => trim( sprintf( __( 'Solde réglé le %1$s %2$s', 'gestion-atelier-cct' ), $date_solde, $mode_solde ) ),
			'value' => wc_price( $solde, $devise ),
		);
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
	if ( ! isset( $resultat['gacct_acompte'] ) ) {
		$resultat += $lignes;
	}

	return $resultat;
}
