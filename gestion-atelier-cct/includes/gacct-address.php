<?php
/**
 * Espace client : page « Adresse de livraison » (08/09/2026).
 *
 * Sous-page `adresse-de-livraison` du Profile Builder (template Elementor 518),
 * rendue par le shortcode `[gacct_adresse]` sur le modèle de `[gacct_profil]`.
 * Remplace le widget JetWooBuilder `jet-myaccount-addresses`, mort depuis la
 * désactivation du plugin le 07/09/2026.
 *
 * Deux cartes : l'adresse de livraison (là où l'atelier renvoie le matériel,
 * champs WooCommerce `shipping_*` du client) et l'adresse de facturation
 * (`billing_*`), avec une case « même adresse ». Ce sont les champs que
 * WooCommerce préremplit au tunnel de commande : une seule adresse de chaque
 * type, comme WooCommerce le prévoit.
 *
 * Soumission : POST sur la page elle-même, intercepté sur `template_redirect`
 * (priorité 5), puis PRG (`?gacct_adresse_notice=<clé>`). Nonce + utilisateur
 * connecté, écriture sur `get_current_user_id()` UNIQUEMENT.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function gacct_address_slug() {
	return (string) apply_filters( 'gacct_address_slug', 'adresse-de-livraison' );
}

function gacct_address_url() {
	$slug = gacct_address_slug();
	$url  = function_exists( 'jwcct_get_compte_subpage_url' ) ? jwcct_get_compte_subpage_url( $slug ) : home_url( '/mon-compte/' . $slug );

	return trailingslashit( $url );
}

function gacct_address_is_page() {
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

	return (bool) preg_match( '#/' . preg_quote( gacct_address_slug(), '#' ) . '/?$#', $path );
}

/* =============================================================================
 *  TEXTES (white-label)
 * ============================================================================= */

function gacct_address_texts() {
	return apply_filters( 'gacct_address_texts', array(
		'ship_title'    => __( 'Adresse de livraison', 'gestion-atelier-cct' ),
		'ship_intro'    => __( 'C’est à cette adresse que l’atelier vous renvoie votre matériel après l’intervention. Elle est reprise automatiquement à chaque nouvelle demande.', 'gestion-atelier-cct' ),
		'bill_title'    => __( 'Adresse de facturation', 'gestion-atelier-cct' ),
		'bill_intro'    => __( 'Elle apparaît sur vos factures.', 'gestion-atelier-cct' ),
		'same'          => __( 'Utiliser la même adresse pour la facturation', 'gestion-atelier-cct' ),
		'first_name'    => __( 'Prénom', 'gestion-atelier-cct' ),
		'last_name'     => __( 'Nom', 'gestion-atelier-cct' ),
		'company'       => __( 'Société ou club (facultatif)', 'gestion-atelier-cct' ),
		'address_1'     => __( 'Adresse', 'gestion-atelier-cct' ),
		'address_2'     => __( 'Complément (bâtiment, étage, lieu-dit…)', 'gestion-atelier-cct' ),
		'postcode'      => __( 'Code postal', 'gestion-atelier-cct' ),
		'city'          => __( 'Ville', 'gestion-atelier-cct' ),
		'country'       => __( 'Pays', 'gestion-atelier-cct' ),
		'phone'         => __( 'Téléphone pour le transporteur', 'gestion-atelier-cct' ),
		'save'          => __( 'Enregistrer', 'gestion-atelier-cct' ),
		'empty_hint'    => __( 'Aucune adresse enregistrée pour le moment.', 'gestion-atelier-cct' ),
		'notice_saved'  => __( 'Adresse enregistrée.', 'gestion-atelier-cct' ),
		'notice_error'  => __( 'Impossible d’enregistrer l’adresse : vérifiez les champs obligatoires.', 'gestion-atelier-cct' ),
		'notice_nonce'  => __( 'La session a expiré, réessayez.', 'gestion-atelier-cct' ),
		'required'      => __( 'obligatoire', 'gestion-atelier-cct' ),
	) );
}

/* =============================================================================
 *  DONNÉES
 * ============================================================================= */

/**
 * Champs d'une adresse (clé => obligatoire ?).
 */
function gacct_address_fields() {
	return array(
		'first_name' => true,
		'last_name'  => true,
		'company'    => false,
		'address_1'  => true,
		'address_2'  => false,
		'postcode'   => true,
		'city'       => true,
		'country'    => true,
	);
}

/**
 * Adresses du client courant.
 *
 * @return array{shipping:array,billing:array,same:bool,countries:array}
 */
function gacct_address_data( $user_id = 0 ) {
	$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
	$customer = new WC_Customer( $user_id );
	$out      = array( 'shipping' => array(), 'billing' => array() );

	foreach ( array_keys( gacct_address_fields() ) as $f ) {
		$out['shipping'][ $f ] = (string) call_user_func( array( $customer, 'get_shipping_' . $f ) );
		$out['billing'][ $f ]  = (string) call_user_func( array( $customer, 'get_billing_' . $f ) );
	}

	$out['shipping']['phone'] = (string) $customer->get_shipping_phone();
	$out['billing']['phone']  = (string) $customer->get_billing_phone();

	if ( '' === $out['shipping']['country'] ) {
		$out['shipping']['country'] = 'FR';
	}
	if ( '' === $out['billing']['country'] ) {
		$out['billing']['country'] = 'FR';
	}

	$same = get_user_meta( $user_id, '_gacct_billing_same_as_shipping', true );
	if ( '' === $same ) {
		// Première visite : même adresse si la facturation est vide ou identique.
		$same = ( '' === $out['billing']['address_1'] || $out['billing']['address_1'] === $out['shipping']['address_1'] ) ? '1' : '0';
	}

	$out['same']      = '1' === (string) $same;
	$out['countries'] = function_exists( 'WC' ) ? WC()->countries->get_shipping_countries() : array( 'FR' => 'France' );
	$out['user_id']   = $user_id;

	return $out;
}

function gacct_address_is_empty( array $a ) {
	return '' === trim( $a['address_1'] . $a['postcode'] . $a['city'] );
}

/* =============================================================================
 *  TRAITEMENT (PRG)
 * ============================================================================= */

add_action( 'template_redirect', 'gacct_address_handle_post', 5 );

function gacct_address_handle_post() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['gacct_adresse_submit'] ) || ! gacct_address_is_page() ) {
		return;
	}

	$url = gacct_address_url();

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( $url );
		exit;
	}

	if ( ! isset( $_POST['gacct_adresse_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gacct_adresse_nonce'] ) ), 'gacct_adresse' ) ) {
		wp_safe_redirect( add_query_arg( 'gacct_adresse_notice', 'nonce', $url ) );
		exit;
	}

	$user_id  = get_current_user_id();
	$customer = new WC_Customer( $user_id );
	$fields   = gacct_address_fields();
	$same     = ! empty( $_POST['billing_same'] );
	$ok       = true;

	$read = function ( $prefix ) use ( $fields, &$ok ) {
		$out = array();
		foreach ( $fields as $f => $required ) {
			$v = isset( $_POST[ $prefix . $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $prefix . $f ] ) ) : '';
			if ( 'country' === $f ) {
				$v = strtoupper( substr( $v, 0, 2 ) );
			}
			if ( $required && '' === $v ) {
				$ok = false;
			}
			$out[ $f ] = $v;
		}
		$out['phone'] = isset( $_POST[ $prefix . 'phone' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $prefix . 'phone' ] ) ) : '';
		return $out;
	};

	$shipping = $read( 'shipping_' );
	$billing  = $same ? $shipping : $read( 'billing_' );

	if ( ! $ok ) {
		wp_safe_redirect( add_query_arg( 'gacct_adresse_notice', 'error', $url ) );
		exit;
	}

	foreach ( $shipping as $f => $v ) {
		$customer->{'set_shipping_' . $f}( $v );
	}
	foreach ( $billing as $f => $v ) {
		if ( 'phone' === $f && '' === $v ) {
			continue; // le téléphone de facturation est géré par « Mon profil ».
		}
		$customer->{'set_billing_' . $f}( $v );
	}

	$customer->save();
	update_user_meta( $user_id, '_gacct_billing_same_as_shipping', $same ? '1' : '0' );

	do_action( 'gacct_address_saved', $user_id, $shipping, $billing, $same );

	wp_safe_redirect( add_query_arg( 'gacct_adresse_notice', 'saved', $url ) );
	exit;
}

/* =============================================================================
 *  SHORTCODE + ASSETS
 * ============================================================================= */

add_shortcode( 'gacct_adresse', 'gacct_address_shortcode' );

function gacct_address_shortcode() {
	if ( ! is_user_logged_in() || ! class_exists( 'WC_Customer' ) ) {
		return '';
	}

	$texts  = gacct_address_texts();
	$data   = gacct_address_data();
	$notice = null;

	if ( isset( $_GET['gacct_adresse_notice'] ) ) {
		$key = sanitize_key( wp_unslash( $_GET['gacct_adresse_notice'] ) );
		$map = array( 'saved' => 'success', 'error' => 'error', 'nonce' => 'error' );
		if ( isset( $map[ $key ] ) ) {
			$notice = array( 'type' => $map[ $key ], 'message' => $texts[ 'notice_' . $key ] );
		}
	}

	ob_start();
	include dirname( __DIR__ ) . '/templates/address.php';

	return (string) ob_get_clean();
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_user_logged_in() || ! gacct_address_is_page() ) {
		return;
	}

	$base_url = plugins_url( '', dirname( __FILE__ ) );
	$base_dir = dirname( __DIR__ );
	$rel      = 'assets/css/address.css';

	if ( file_exists( $base_dir . '/' . $rel ) ) {
		wp_enqueue_style( 'gacct-address', $base_url . '/' . $rel, array(), (string) filemtime( $base_dir . '/' . $rel ) );
	}
} );
