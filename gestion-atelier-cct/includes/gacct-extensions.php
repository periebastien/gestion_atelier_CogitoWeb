<?php
/**
 * Prises d'extension neutres pour les modules complémentaires (03/10/2026).
 *
 * Elles ont été ajoutées pour le module Clubs (gacct-module-clubs) mais ne
 * connaissent rien des clubs : sans module branché, chaque fonction renvoie
 * sa valeur par défaut et le comportement du socle est strictement inchangé.
 *
 * - gacct_order_skip_automation() : un module peut soustraire une commande à
 *   un automatisme (relances, annulations, bascule sans suite, solde...).
 * - gacct_occupation_counted_sql() / gacct_occupation_counted_hours() :
 *   occupations qui comptent dans la capacité d'un jour.
 * - gacct_order_third_party() : la commande est prise en charge par un tiers
 *   (paiement et expédition), les écrans client adaptent leurs consignes.
 * - gacct_op_console_extra_views() : onglets supplémentaires de la console.
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

/**
 * La commande doit-elle échapper à un automatisme ?
 *
 * Contextes utilisés par le socle : noshow, preslot, balance_request,
 * balance_reminder, auto_cancel.
 *
 * @param WC_Order|mixed $order
 * @param string         $context
 * @return bool
 */
function gacct_order_skip_automation( $order, $context ) {
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	$skip = false;
	// Réglages « Fonctionnement de l'atelier » (14/10/2026), neutres par défaut.
	if ( function_exists( 'gacct_pay_workshop_setting' ) ) {
		// Annulation automatique décochée : ni annulation ni bascule « Sans suite ».
		if ( ! gacct_pay_workshop_setting( 'auto_cancel' ) && in_array( (string) $context, array( 'auto_cancel', 'noshow' ), true ) ) {
			$skip = true;
		}
		// Automatismes réservés à l'atelier : une commande boutique n'est jamais touchée.
		if ( 'atelier' === gacct_pay_workshop_setting( 'automation_scope' ) && ! gacct_order_is_atelier( $order ) ) {
			$skip = true;
		}
	}

	return (bool) apply_filters( 'gacct_order_skip_automation', $skip, $order, (string) $context );
}

/**
 * La commande relève-t-elle de l'atelier (intervention ou vente seule) ?
 *
 * Une commande de la boutique (matériel vendu sur le site, sans dossier de
 * révision) renvoie false. Filtrable pour les modules (commandes de club…).
 *
 * @param WC_Order|mixed $order
 * @return bool
 */
function gacct_order_is_atelier( $order ) {
	global $wpdb;

	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	// Dossier de révision lié (méta posée au checkout, ou CCT quel que soit son statut).
	$is = defined( 'JWCCT_ORDER_REVISION_ID' ) && '' !== (string) $order->get_meta( JWCCT_ORDER_REVISION_ID );
	if ( ! $is ) {
		$is = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}jet_cct_revision WHERE order_id = %d LIMIT 1",
			$order->get_id()
		) );
	}
	// Vente seule (produits commandables sans intervention).
	if ( ! $is ) {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && 'yes' === get_post_meta( $item->get_product_id(), '_gacct_vente_seule', true ) ) {
				$is = true;
				break;
			}
		}
	}

	return (bool) apply_filters( 'gacct_order_is_atelier', $is, $order );
}

/**
 * Fragment SQL « AND (...) » limitant les occupations comptées dans la capacité.
 *
 * @param string $alias Alias de la table occupation dans la requête ('' si aucun).
 * @return string Chaîne vide sans module.
 */
function gacct_occupation_counted_sql( $alias = '' ) {
	$prefix = '' !== $alias ? $alias . '.' : '';
	$sql    = (string) apply_filters( 'gacct_occupation_counted_sql', '', $prefix );

	return '' !== trim( $sql ) ? ' AND ( ' . $sql . ' )' : '';
}

/**
 * Durée (heures) qu'une occupation consomme sur la capacité d'un jour.
 *
 * @param float $hours      Durée réelle.
 * @param array $occupation Ligne CCT occupation.
 * @return float
 */
function gacct_occupation_counted_hours( $hours, $occupation ) {
	return (float) apply_filters( 'gacct_occupation_counted_hours', (float) $hours, (array) $occupation );
}

/**
 * Tiers qui prend en charge la commande (paiement et expédition), ou null.
 *
 * Clés : name (obligatoire), paid_label, ship_title, ship_html, dash_title,
 * dash_text, dash_url, dash_cta, thankyou_html.
 *
 * @param WC_Order|mixed $order
 * @return array|null
 */
function gacct_order_third_party( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return null;
	}

	$tp = apply_filters( 'gacct_order_third_party', null, $order );

	return ( is_array( $tp ) && ! empty( $tp['name'] ) ) ? $tp : null;
}

/**
 * Vues supplémentaires de la console : slug => { label, render (callable) }.
 *
 * @return array
 */
function gacct_op_console_extra_views() {
	$views = apply_filters( 'gacct_op_console_views', array() );
	$out   = array();

	foreach ( (array) $views as $slug => $view ) {
		$slug = sanitize_key( $slug );
		if ( $slug && ! empty( $view['label'] ) && ! empty( $view['render'] ) && is_callable( $view['render'] ) ) {
			$out[ $slug ] = $view;
		}
	}

	return $out;
}
