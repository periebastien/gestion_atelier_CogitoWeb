<?php
/**
 * Plugin Name: GACCT - Factures PDF (dates de paiement)
 * Description: Ajoute sur les factures PDF Invoices & Packing Slips les lignes « Acompte réglé le … » et « Solde réglé le … » (données Kojito), pour pointer les encaissements.
 * Version: 1.0.0
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

	if ( $acompte <= 0 || '' === $date_acompte ) {
		return $totals;
	}

	$lignes = array(
		'gacct_acompte' => array(
			'label' => sprintf( __( 'Acompte réglé le %s', 'gestion-atelier-cct' ), $date_acompte ),
			'value' => wc_price( $acompte, $devise ),
		),
	);
	if ( $solde > 0 && '' !== $date_solde ) {
		$lignes['gacct_solde'] = array(
			'label' => sprintf( __( 'Solde réglé le %s', 'gestion-atelier-cct' ), $date_solde ),
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
