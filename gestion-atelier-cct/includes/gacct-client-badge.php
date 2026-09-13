<?php
/**
 * Commandes WooCommerce : « Nouveau client » ou « Client fidèle » (13/09/2026, demande Bastien).
 *
 * Colonne « Client » dans la liste des commandes (posts et HPOS) et encart sur la
 * fiche de commande, à côté de l'origine. Le statut est calculé :
 *  - autres commandes du même client (hors annulées / échouées / brouillons), hors celle-ci ;
 *  - sinon dossiers de l'ancien site rattachés au compte (table gacct_historique) ;
 *  - sinon nouveau client.
 * Le résultat est figé dans la meta `_gacct_client_statut` à la création de la
 * commande (la première commande reste « nouveau client » même après la deuxième).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_CLIENT_STATUT_META', '_gacct_client_statut' );

/**
 * @return array{code:string,label:string,detail:string}
 */
function gacct_client_statut( $order, $freeze = false ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	if ( ! $order ) {
		return array( 'code' => '', 'label' => '', 'detail' => '' );
	}

	$saved = $order->get_meta( GACCT_CLIENT_STATUT_META );
	if ( is_array( $saved ) && ! empty( $saved['code'] ) ) {
		return $saved;
	}

	$customer_id = $order->get_customer_id();
	$email       = $order->get_billing_email();
	$autres      = 0;

	if ( $customer_id || $email ) {
		$args = array(
			'limit'   => 50,
			'return'  => 'ids',
			'exclude' => array( $order->get_id() ),
			'status'  => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-failed', 'wc-checkout-draft', 'wc-trash' ) ),
		);
		if ( $customer_id ) {
			$args['customer_id'] = $customer_id;
		} else {
			$args['billing_email'] = $email;
		}
		$ids    = wc_get_orders( $args );
		$autres = is_array( $ids ) ? count( $ids ) : 0;
	}

	$anciens = 0;
	if ( ! $autres && $customer_id && function_exists( 'gacct_historique_table' ) ) {
		global $wpdb;
		$anciens = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . gacct_historique_table() . ' WHERE user_id = %d',
			$customer_id
		) );
	}

	if ( $autres ) {
		$statut = array(
			'code'   => 'fidele',
			'label'  => __( 'Client fidèle', 'gestion-atelier-cct' ),
			'detail' => sprintf( _n( '%d autre commande', '%d autres commandes', $autres, 'gestion-atelier-cct' ), $autres ),
		);
	} elseif ( $anciens ) {
		$statut = array(
			'code'   => 'ancien',
			'label'  => __( 'Client fidèle', 'gestion-atelier-cct' ),
			'detail' => sprintf( _n( '%d révision sur l\'ancien site', '%d révisions sur l\'ancien site', $anciens, 'gestion-atelier-cct' ), $anciens ),
		);
	} else {
		$statut = array(
			'code'   => 'nouveau',
			'label'  => __( 'Nouveau client', 'gestion-atelier-cct' ),
			'detail' => __( 'première commande', 'gestion-atelier-cct' ),
		);
	}

	if ( $freeze ) {
		$order->update_meta_data( GACCT_CLIENT_STATUT_META, $statut );
		$order->save_meta_data();
	}

	return $statut;
}

// Figé dès la création de la commande (checkout) : la première commande reste « nouveau ».
add_action( 'woocommerce_checkout_order_created', function ( $order ) {
	gacct_client_statut( $order, true );
} );
add_action( 'woocommerce_new_order', function ( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order && ! $order->get_meta( GACCT_CLIENT_STATUT_META ) ) {
		gacct_client_statut( $order, true );
	}
}, 20 );

/* -------------------------------------------------------------------------
 * Liste des commandes : colonne « Client » après « Origine »
 * ---------------------------------------------------------------------- */

function gacct_client_badge_columns( $columns ) {
	$out = array();
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'origin' === $key ) {
			$out['gacct_client'] = __( 'Client', 'gestion-atelier-cct' );
		}
	}
	if ( ! isset( $out['gacct_client'] ) ) {
		$out['gacct_client'] = __( 'Client', 'gestion-atelier-cct' );
	}
	return $out;
}
add_filter( 'manage_edit-shop_order_columns', 'gacct_client_badge_columns', 20 );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'gacct_client_badge_columns', 20 );

function gacct_client_badge_html( $order ) {
	$s = gacct_client_statut( $order );
	if ( empty( $s['code'] ) ) {
		return '';
	}
	$color = 'nouveau' === $s['code'] ? '#0f766e' : '#475569';
	$bg    = 'nouveau' === $s['code'] ? '#ccfbf1' : '#e2e8f0';
	return sprintf(
		'<span class="gacct-client-badge gacct-client-badge--%1$s" style="display:inline-block;padding:2px 8px;border-radius:999px;font-weight:600;font-size:12px;background:%2$s;color:%3$s;white-space:nowrap" title="%5$s">%4$s</span><br><span style="color:#666;font-size:11px">%5$s</span>',
		esc_attr( $s['code'] ),
		$bg,
		$color,
		esc_html( $s['label'] ),
		esc_attr( $s['detail'] )
	);
}

add_action( 'manage_shop_order_posts_custom_column', function ( $column, $post_id ) {
	if ( 'gacct_client' === $column ) {
		echo gacct_client_badge_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}, 10, 2 );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', function ( $column, $order ) {
	if ( 'gacct_client' === $column ) {
		echo gacct_client_badge_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Fiche de commande : sous les détails généraux, à côté de l'origine
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_admin_order_data_after_order_details', function ( $order ) {
	$s = gacct_client_statut( $order );
	if ( empty( $s['code'] ) ) {
		return;
	}
	echo '<p class="form-field form-field-wide gacct-client-statut" style="margin-top:8px"><strong>' . esc_html__( 'Client :', 'gestion-atelier-cct' ) . '</strong> '
		. gacct_client_badge_html( $order ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} );
