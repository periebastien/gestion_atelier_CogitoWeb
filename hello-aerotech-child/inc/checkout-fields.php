<?php
/**
 * AEROTECH — Commande : normalisation des champs WooCommerce.
 *
 * Objectif : les champs de la page Commande se comportent comme ceux de la page
 * Contact (labels flottants, pas d'astérisque rouge, message sous le champ).
 *
 * Le moteur n'est pas touché : on n'agit que sur les ARGUMENTS de rendu
 * (libellés, placeholders, attributs) et sur la CSS. Les `name`, `id` et les
 * classes dont dépend checkout.js (`address-field`, `update_totals_on_change`,
 * `validate-*`) restent intacts, donc le recalcul de livraison, la validation
 * et la création de commande ne bougent pas.
 *
 * Chargé par inc/shop-checkout.php.
 *
 * @package hello-aerotech-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------------
 * Feuille dédiée, chargée après at-cart.css (dépendance déclarée).
 * ------------------------------------------------------------------------- */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}
		$css = get_stylesheet_directory() . '/assets/at-checkout.css';
		wp_enqueue_style(
			'at-checkout',
			get_stylesheet_directory_uri() . '/assets/at-checkout.css',
			array( 'at-cart' ),
			file_exists( $css ) ? filemtime( $css ) : '1'
		);
	},
	30
);

/* ---------------------------------------------------------------------------
 * Libellés courts : dans un label flottant, « Numéro et nom de rue » ou
 * « Téléphone (pour la livraison) (facultatif) » débordent du champ.
 *
 * Priorité 40 : après les filtres de inc/shop-checkout.php (20 et 30), dont
 * celui qui renomme le téléphone — c'est celui-ci qui doit gagner.
 * ------------------------------------------------------------------------- */
add_filter(
	'woocommerce_checkout_fields',
	function ( $fields ) {
		$labels = array(
			'first_name' => 'Prénom',
			'last_name'  => 'Nom',
			'company'    => 'Société',
			'country'    => 'Pays',
			'address_1'  => 'Adresse',
			'address_2'  => 'Complément d\'adresse',
			'postcode'   => 'Code postal',
			'city'       => 'Ville',
			'state'      => 'Région',
			'phone'      => 'Téléphone',
			'email'      => 'Adresse e-mail',
		);

		foreach ( array( 'billing', 'shipping' ) as $group ) {
			if ( empty( $fields[ $group ] ) ) {
				continue;
			}
			foreach ( array_keys( $fields[ $group ] ) as $key ) {
				$short = substr( $key, strlen( $group ) + 1 );
				if ( isset( $labels[ $short ] ) ) {
					$fields[ $group ][ $key ]['label'] = $labels[ $short ];
				}
			}
		}

		return $fields;
	},
	40
);

/* ---------------------------------------------------------------------------
 * Deux réglages sur chaque champ rendu par woocommerce_form_field() :
 *
 * 1. un placeholder d'UNE ESPACE quand le champ n'en a pas. Indispensable :
 *    `:placeholder-shown` ne matche pas un placeholder vide, or c'est lui qui
 *    fait redescendre le label quand le champ se vide. (Même contrainte que
 *    sur la page Contact, où le placeholder est aussi forcé en PHP.)
 *    Il reste invisible : at-cart.css met ::placeholder en transparent.
 * 2. aria-required, puisque l'astérisque est masquée en CSS.
 *
 * Garde `is_checkout()` : ce filtre sert aussi aux formulaires d'adresse de
 * /mon-compte/, qui n'ont pas cet habillage.
 * ------------------------------------------------------------------------- */
add_filter(
	'woocommerce_form_field_args',
	function ( $args, $key, $value ) {
		unset( $key, $value );

		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return $args;
		}

		$textual = array( 'text', 'email', 'tel', 'password', 'number', 'textarea' );
		$type    = isset( $args['type'] ) ? $args['type'] : 'text';

		if ( in_array( $type, $textual, true ) && '' === trim( (string) ( isset( $args['placeholder'] ) ? $args['placeholder'] : '' ) ) ) {
			$args['placeholder'] = ' ';
		}

		if ( ! empty( $args['required'] ) ) {
			if ( ! isset( $args['custom_attributes'] ) || ! is_array( $args['custom_attributes'] ) ) {
				$args['custom_attributes'] = array();
			}
			$args['custom_attributes']['aria-required'] = 'true';
		}

		return $args;
	},
	10,
	3
);
