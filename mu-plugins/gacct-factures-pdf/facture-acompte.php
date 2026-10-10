<?php
/**
 * Facture d'acompte (demande de Mika du 09/10/2026, validée par Bastien le 10/10).
 *
 * - Document PDF Invoices « facture-acompte », numérotation à part (AC-00001-2026),
 *   distincte des factures définitives (AR-…).
 * - Créée et envoyée automatiquement par l'e-mail WooCommerce « Facture d'acompte »
 *   dès que la commande passe en « Acompte payé » (carte, virement encaissé dans la
 *   console, ou passage manuel).
 * - La facture définitive cite sa référence sur la ligne « Acompte réglé ».
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

define( 'GACCT_FA_TYPE', 'facture-acompte' );
define( 'GACCT_FA_EMAIL_ID', 'customer_facture_acompte' );

/** Commande dont l'acompte a été réglé (circuit Kojito en deux temps). */
function gacct_fa_eligible( $order ) {
	return $order instanceof WC_Order
		&& (float) $order->get_meta( '_kojito_acompte_paye' ) > 0
		&& in_array( (string) $order->get_meta( '_kojito_phase_paiement' ), array( 'acompte', 'solde', 'solde_paye' ), true );
}

/** Document déjà émis pour la commande, ou null (jamais de création ici). */
function gacct_fa_document( WC_Order $order ) {
	if ( ! function_exists( 'wcpdf_get_document' ) ) {
		return null;
	}
	$doc = wcpdf_get_document( GACCT_FA_TYPE, $order );
	return $doc && $doc->exists() ? $doc : null;
}

/** « Facture d'acompte AC-00001-2026 du 05/10/2026 », '' si aucune. */
function gacct_fa_reference( WC_Order $order ) {
	$doc = gacct_fa_document( $order );
	if ( ! $doc ) {
		return '';
	}
	$date = $doc->get_date();
	/* translators: 1: numéro, 2: date */
	return sprintf( __( 'Facture d’acompte %1$s du %2$s', 'gestion-atelier-cct' ), $doc->get_number()->get_formatted(), $date ? $date->date_i18n( 'd/m/Y' ) : '' );
}

/* --------------------------------------------------------------- réglages --- */

/** Réglages du document à la première activation (modifiables ensuite dans PDF Invoices > Documents). */
add_action( 'init', 'gacct_fa_default_settings', 20 );

function gacct_fa_default_settings() {
	if ( false !== get_option( 'wpo_wcpdf_documents_settings_' . GACCT_FA_TYPE ) ) {
		return;
	}
	$dev = false !== strpos( home_url(), 'cogitoweb.net' );
	add_option( 'wpo_wcpdf_documents_settings_' . GACCT_FA_TYPE, array(
		'enabled'                  => '1',
		'attach_to_email_ids'      => array( GACCT_FA_EMAIL_ID => '1' ),
		'disable_for_statuses'     => array( 'wc-pending', 'wc-on-hold', 'wc-cancelled', 'wc-failed', 'wc-checkout-draft' ),
		'display_shipping_address' => '',
		'display_email'            => '1',
		'display_phone'            => '1',
		'display_number'           => '1',
		'display_date'             => '1',
		'number_format'            => array(
			'prefix'  => $dev ? 'DEV-AC-' : 'AC-',
			'suffix'  => '-[facture_acompte_year]',
			'padding' => '5',
		),
		'my_account_buttons'       => 'available',
	) );
}

/* ---------------------------------------------------- document PDF Invoices --- */

add_filter( 'wpo_wcpdf_document_classes', 'gacct_fa_register_document', 20 );

function gacct_fa_register_document( $documents ) {
	// Classe de base des documents Pro : nouvelle (PDF Invoices 6+) ou héritée (5.x).
	$base = class_exists( '\WPO\IPS\Documents\AbstractProDocument' )
		? '\WPO\IPS\Documents\AbstractProDocument'
		: '\WPO\WC\PDF_Invoices\Documents\Pro_Document';
	if ( ! class_exists( $base ) ) {
		return $documents;
	}
	if ( ! class_exists( 'GACCT_Facture_Acompte_Base', false ) ) {
		class_alias( ltrim( $base, '\\' ), 'GACCT_Facture_Acompte_Base' );
	}
	require_once GACCT_FACTURES_DIR . '/class-gacct-facture-acompte.php';
	$documents['GACCT_Facture_Acompte'] = new GACCT_Facture_Acompte();
	return $documents;
}

// Gabarit du document (le plugin ne connaît pas ce type).
add_filter( 'wpo_wcpdf_template_file', 'gacct_fa_template_file', 20, 2 );

function gacct_fa_template_file( $file, $type ) {
	// Seul le corps du document est propre ; styles et enveloppe restent ceux du gabarit « Simple ».
	return GACCT_FA_TYPE === $type && GACCT_FA_TYPE . '.php' === basename( $file ) ? GACCT_FACTURES_DIR . '/templates/facture-acompte.php' : $file;
}

// Uniquement pour une commande dont l'acompte est réglé.
add_filter( 'wpo_wcpdf_document_is_allowed', 'gacct_fa_is_allowed', 20, 2 );

function gacct_fa_is_allowed( $allowed, $document ) {
	if ( GACCT_FA_TYPE !== $document->get_type() || ! $allowed ) {
		return $allowed;
	}
	return $document->exists() || gacct_fa_eligible( $document->order );
}

/**
 * Données de la facture d'acompte : prestations réservées (prix et acompte de
 * chaque ligne), acompte HT / TVA / TTC, paiement.
 *
 * Seules les lignes portant un acompte Kojito sont listées : les lignes ajoutées
 * à l'atelier (facturation, devis) appartiennent à la facture définitive.
 */
function gacct_fa_donnees( WC_Order $order ) {
	$lignes    = array();
	$somme     = 0.0;
	$prix_tout = 0.0;

	foreach ( $order->get_items() as $item ) {
		$unitaire = $item->get_meta( '_kojito_acompte_unitaire' );
		if ( '' === $unitaire || $item->get_meta( '_gacct_billing_extra' ) ) {
			continue;
		}
		$prix = class_exists( 'Kojito_Acompte_Produit' ) ? Kojito_Acompte_Produit::prix_initial_ttc_ligne( $item ) : null;
		$prix = null !== $prix ? $prix : (float) $item->get_total() + (float) $item->get_total_tax();
		$acpt = round( (float) $unitaire * $item->get_quantity(), 2 );
		if ( $acpt <= 0 && $prix <= 0 ) {
			continue; // Retrait atelier, envoi offert…
		}

		$lignes[]   = array(
			'nom'      => $item->get_name(),
			'meta'     => gacct_fa_item_meta( $item ),
			'quantite' => $item->get_quantity(),
			'prix'     => $prix,
			'acompte'  => $acpt,
		);
		$somme     += $acpt;
		$prix_tout += $prix;
	}

	$acompte = gacct_factures_acompte( $order );
	$ttc     = $acompte['montant'];

	// Montants qui ne se recoupent pas (ancienne commande, ligne sans acompte payée
	// d'avance) : une seule ligne, l'acompte réellement encaissé fait foi.
	if ( ! $lignes || abs( $somme - $ttc ) >= 0.01 ) {
		$lignes    = array(
			array(
				/* translators: %s: numéro de commande */
				'nom'      => sprintf( __( 'Acompte sur la commande %s', 'gestion-atelier-cct' ), $order->get_order_number() ),
				'meta'     => '',
				'quantite' => 1,
				'prix'     => (float) $order->get_meta( '_kojito_total_initial' ),
				'acompte'  => $ttc,
			),
		);
		$prix_tout = (float) $order->get_meta( '_kojito_total_initial' );
	}

	// Taux de TVA de la commande (un seul taux sur l'atelier : 20 %).
	$taux = 20.0;
	$taxes = $order->get_items( 'tax' );
	if ( 1 === count( $taxes ) ) {
		$t = reset( $taxes );
		if ( is_callable( array( $t, 'get_rate_percent' ) ) && '' !== (string) $t->get_rate_percent() ) {
			$taux = (float) $t->get_rate_percent();
		}
	}
	$ht = round( $ttc / ( 1 + $taux / 100 ), 2 );

	return array(
		'lignes'   => $lignes,
		'prix'     => $prix_tout,
		'ttc'      => $ttc,
		'ht'       => $ht,
		'tva'      => round( $ttc - $ht, 2 ),
		'taux'     => $taux,
		'date'     => gacct_factures_date_fr( $acompte['date'] ),
		'mode'     => $acompte['mode'],
		'methode'  => array(
			__( 'par carte', 'gestion-atelier-cct' )    => __( 'Carte bancaire', 'gestion-atelier-cct' ),
			__( 'par virement', 'gestion-atelier-cct' ) => __( 'Virement bancaire', 'gestion-atelier-cct' ),
		)[ $acompte['mode'] ] ?? '',
	);
}

/** Caractéristiques de la ligne (voile, taille…), sans la mention d'acompte déjà en colonne. */
function gacct_fa_item_meta( WC_Order_Item $item ) {
	$parts = array();
	foreach ( $item->get_formatted_meta_data() as $meta ) {
		if ( 0 === stripos( wp_strip_all_tags( $meta->display_key ), 'Acompte' ) ) {
			continue;
		}
		$parts[] = '<strong>' . wp_kses_post( $meta->display_key ) . ' :</strong> ' . wp_kses_post( wp_strip_all_tags( $meta->display_value ) );
	}
	return $parts ? '<ul class="wc-item-meta"><li>' . implode( '</li><li>', $parts ) . '</li></ul>' : '';
}

/* ------------------------------------------------------- e-mail WooCommerce --- */

add_filter( 'woocommerce_email_classes', 'gacct_fa_email_class' );

function gacct_fa_email_class( $emails ) {
	require_once GACCT_FACTURES_DIR . '/class-gacct-email-facture-acompte.php';
	$emails['GACCT_Email_Facture_Acompte'] = new GACCT_Email_Facture_Acompte();
	return $emails;
}

/*
 * Passage en « Acompte payé » : envoi différé (Action Scheduler), pour lire la
 * commande une fois enregistrée par Kojito ou la console.
 */
add_action( 'woocommerce_order_status_acompte-paye', 'gacct_fa_on_acompte_paye', 50, 2 );

function gacct_fa_on_acompte_paye( $order_id, $order = null ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	// Passage manuel (paiement au terminal de la boutique…) : sans date, la
	// facture ne saurait pas quand l'acompte a été réglé.
	if ( '' === (string) $order->get_meta( '_kojito_date_acompte_paye' ) && (float) $order->get_meta( '_kojito_acompte_paye' ) > 0 ) {
		$order->update_meta_data( '_kojito_date_acompte_paye', current_time( 'mysql' ) );
		$order->save_meta_data();
	}
	gacct_fa_schedule( $order_id );
}

function gacct_fa_schedule( $order_id ) {
	$args = array( (int) $order_id );
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		if ( ! as_has_scheduled_action( 'gacct_fa_envoyer', $args ) ) {
			as_enqueue_async_action( 'gacct_fa_envoyer', $args, 'gacct' );
		}
		return;
	}
	gacct_fa_envoyer( $order_id );
}

add_action( 'gacct_fa_envoyer', 'gacct_fa_envoyer' );

/**
 * Crée la facture d'acompte et l'envoie au client (une seule fois par commande).
 *
 * @return string Résultat lisible (journal, reprise des commandes existantes).
 */
function gacct_fa_envoyer( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! gacct_fa_eligible( $order ) ) {
		return 'non concernée';
	}
	if ( $order->get_meta( '_gacct_facture_acompte_envoyee' ) ) {
		return 'déjà envoyée';
	}

	$emails = WC()->mailer()->get_emails();
	$email  = $emails['GACCT_Email_Facture_Acompte'] ?? null;

	if ( $email && $email->is_enabled() ) {
		// La pièce jointe crée le document (numéro, date) au moment de l'envoi.
		$email->trigger( $order->get_id(), $order );
	} elseif ( function_exists( 'wcpdf_get_document' ) ) {
		wcpdf_get_document( GACCT_FA_TYPE, $order, true );
	}

	$order = wc_get_order( $order_id );
	$ref   = gacct_fa_reference( $order );
	$order->update_meta_data( '_gacct_facture_acompte_envoyee', current_time( 'mysql' ) );
	$order->save_meta_data();
	$order->add_order_note( $email && $email->is_enabled()
		/* translators: %s: référence de la facture d'acompte */
		? sprintf( __( '%s envoyée au client par e-mail.', 'gestion-atelier-cct' ), $ref ? $ref : __( 'Facture d’acompte', 'gestion-atelier-cct' ) )
		: sprintf( __( '%s créée (e-mail « Facture d’acompte » désactivé).', 'gestion-atelier-cct' ), $ref ? $ref : __( 'Facture d’acompte', 'gestion-atelier-cct' ) ) );

	return $ref ? $ref : 'document non créé';
}
