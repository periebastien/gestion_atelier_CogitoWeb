<?php
/**
 * Espace client : section « Mes commandes » (08/09/2026).
 *
 * Sous-page `commandes` du Profile Builder (template Elementor 482), rendue par
 * le shortcode `[gacct_commandes]` sur le modèle de `[gacct_profil]` :
 *  - sans paramètre : liste des commandes WooCommerce du client connecté
 *    (templates/orders.php) ;
 *  - avec `?commande=<id>` : détail de la commande, qui réutilise tel quel
 *    templates/view-order.php (tracker, montants Kojito, blocs contextuels).
 *
 * Pourquoi : la section « Commandes » et le détail `/mon-compte/view-order/`
 * reposaient sur deux widgets JetWooBuilder (`jet-myaccount-order` du template
 * 482, `jet-myaccount-content` de la page 14), morts depuis la désactivation
 * de ce plugin le 07/09/2026. Le détail vit désormais ici, et TOUTES les URL
 * « voir la commande » du site (e-mails, confirmation, tableau de bord) y
 * mènent via le filtre `woocommerce_get_view_order_url` ; l'ancienne URL est
 * redirigée.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
 *  IDENTITÉ DE LA PAGE
 * ============================================================================= */

function gacct_orders_slug() {
	return (string) apply_filters( 'gacct_orders_slug', 'commandes' );
}

/**
 * URL de la liste, ou du détail d'une commande.
 *
 * @param int $order_id 0 = liste.
 * @return string
 */
function gacct_orders_url( $order_id = 0 ) {
	$slug = gacct_orders_slug();
	$url  = function_exists( 'jwcct_get_compte_subpage_url' )
		? jwcct_get_compte_subpage_url( $slug )
		: home_url( '/mon-compte/' . $slug );
	$url  = trailingslashit( $url );

	return $order_id ? add_query_arg( 'commande', absint( $order_id ), $url ) : $url;
}

function gacct_orders_is_page() {
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

	return (bool) preg_match( '#/' . preg_quote( gacct_orders_slug(), '#' ) . '/?$#', $path );
}

/**
 * Commande demandée en détail (0 = liste). Propriété vérifiée.
 *
 * @return int
 */
function gacct_orders_requested_id() {
	$id = isset( $_GET['commande'] ) ? absint( $_GET['commande'] ) : 0;

	return $id && gacct_orders_user_can_view( $id ) ? $id : 0;
}

function gacct_orders_user_can_view( $order_id ) {
	if ( ! is_user_logged_in() || ! function_exists( 'wc_get_order' ) ) {
		return false;
	}

	$order = wc_get_order( absint( $order_id ) );

	if ( ! $order ) {
		return false;
	}

	if ( current_user_can( 'manage_woocommerce' ) ) {
		return true;
	}

	return (int) $order->get_customer_id() === get_current_user_id();
}

/* =============================================================================
 *  TOUTES LES URL « VOIR LA COMMANDE » MÈNENT ICI
 * ============================================================================= */

add_filter( 'woocommerce_get_view_order_url', function ( $url, $order ) {
	return $order instanceof WC_Order ? gacct_orders_url( $order->get_id() ) : $url;
}, 10, 2 );

/**
 * Ancienne URL `/mon-compte/view-order/{id}/` (e-mails déjà envoyés) → détail.
 */
add_action( 'template_redirect', function () {
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

	if ( preg_match( '#/view-order/(\d+)/?$#', $path, $m ) ) {
		wp_safe_redirect( gacct_orders_url( (int) $m[1] ), 301 );
		exit;
	}
}, 4 );

/* =============================================================================
 *  TEXTES (white-label)
 * ============================================================================= */

function gacct_orders_texts() {
	return apply_filters( 'gacct_orders_texts', array(
		'intro'        => __( 'Toutes vos commandes passées dans cet espace, de la plus récente à la plus ancienne.', 'gestion-atelier-cct' ),
		'vide_titre'   => __( 'Aucune commande pour le moment', 'gestion-atelier-cct' ),
		'vide_texte'   => __( 'Votre première demande d’intervention créera votre première commande.', 'gestion-atelier-cct' ),
		'vide_cta'     => __( 'Faire une demande d’intervention', 'gestion-atelier-cct' ),
		'col_commande' => __( 'Commande', 'gestion-atelier-cct' ),
		'col_date'     => __( 'Date', 'gestion-atelier-cct' ),
		'col_materiel' => __( 'Matériel', 'gestion-atelier-cct' ),
		'col_etat'     => __( 'Avancement', 'gestion-atelier-cct' ),
		'col_total'    => __( 'Montant', 'gestion-atelier-cct' ),
		'voir'         => __( 'Voir le détail', 'gestion-atelier-cct' ),
		'retour'       => __( 'Toutes mes commandes', 'gestion-atelier-cct' ),
		'introuvable'  => __( 'Cette commande est introuvable ou ne vous appartient pas.', 'gestion-atelier-cct' ),
		'reste'        => __( 'reste à payer', 'gestion-atelier-cct' ),
		'paye'         => __( 'réglée', 'gestion-atelier-cct' ),
		'annulee'      => __( 'Annulée', 'gestion-atelier-cct' ),
		'articles'     => __( 'prestation(s)', 'gestion-atelier-cct' ),
	) );
}

/* =============================================================================
 *  DONNÉES
 * ============================================================================= */

/**
 * Commandes du client, prêtes pour le gabarit de liste.
 *
 * @return array[]
 */
function gacct_orders_rows( $user_id = 0 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

	if ( ! $user_id || ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$orders = wc_get_orders( array(
		'customer_id' => $user_id,
		'limit'       => 100,
		'orderby'     => 'date',
		'order'       => 'DESC',
		'status'      => array_keys( wc_get_order_statuses() ),
	) );

	$labels = function_exists( 'gacct_vo_state_labels' ) ? gacct_vo_state_labels() : array();
	$rows   = array();

	foreach ( $orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}

		$conf   = function_exists( 'gacct_conf_data' ) ? gacct_conf_data( $order ) : array();
		$etat   = isset( $conf['etat'] ) && null !== $conf['etat'] ? (int) $conf['etat'] : null;
		$dead   = $order->has_status( array( 'cancelled', 'refunded', 'failed' ) );
		$items  = 0;

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && ! ( function_exists( 'gacct_biplace_est_produit_supplement' ) && gacct_biplace_est_produit_supplement( $item->get_product_id() ) ) ) {
				$items += (int) $item->get_quantity();
			}
		}

		$balance = isset( $conf['balance'] ) ? (float) $conf['balance'] : 0;
		$total   = isset( $conf['total_initial'] ) ? (float) $conf['total_initial'] : (float) $order->get_total();

		if ( $dead ) {
			$state_txt = gacct_orders_texts()['annulee'];
			$state_key = 'dead';
		} elseif ( null !== $etat && isset( $labels[ $etat ] ) ) {
			$state_txt = $labels[ $etat ];
			if ( 5 === $etat && function_exists( 'gacct_state5_suffix' ) ) {
				$state_txt .= gacct_state5_suffix( $order );
			}
			$state_key = in_array( $etat, array( 0, 4, 6 ), true ) ? 'you' : ( $etat >= 7 ? 'done' : 'shop' );
		} else {
			$state_txt = wc_get_order_status_name( $order->get_status() );
			$state_key = 'shop';
		}

		$rows[] = array(
			'id'        => $order->get_id(),
			'reference' => $order->get_order_number(),
			'date'      => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '',
			'materiel'  => isset( $conf['materiel'] ) ? (string) $conf['materiel'] : '',
			'items'     => $items,
			'etat'      => $etat,
			'state_txt' => $state_txt,
			'state_key' => $state_key,
			'total'     => $total,
			'balance'   => $dead ? 0 : $balance,
			'url'       => gacct_orders_url( $order->get_id() ),
		);
	}

	return apply_filters( 'gacct_orders_rows', $rows, $user_id );
}

function gacct_orders_amount( $amount ) {
	return number_format( (float) $amount, 2, ',', ' ' ) . ' €';
}

/* =============================================================================
 *  SHORTCODE + ASSETS
 * ============================================================================= */

add_shortcode( 'gacct_commandes', 'gacct_orders_shortcode' );

function gacct_orders_shortcode() {
	if ( ! is_user_logged_in() || ! function_exists( 'wc_get_order' ) ) {
		return '';
	}

	$texts = gacct_orders_texts();
	$id    = gacct_orders_requested_id();

	ob_start();

	if ( isset( $_GET['commande'] ) && ! $id ) {
		echo '<div class="gacct-orders-notice gacct-orders-notice--error" role="status">' . esc_html( $texts['introuvable'] ) . '</div>';
	}

	if ( $id ) {
		$order    = wc_get_order( $id );
		$order_id = $id;
		echo '<p class="gacct-orders-back"><a href="' . esc_url( gacct_orders_url() ) . '">&larr; ' . esc_html( $texts['retour'] ) . '</a></p>';
		include dirname( __DIR__ ) . '/templates/view-order.php';
	} else {
		$rows = gacct_orders_rows();
		include dirname( __DIR__ ) . '/templates/orders.php';
	}

	return (string) ob_get_clean();
}

add_action( 'wp_enqueue_scripts', 'gacct_orders_enqueue_assets' );

function gacct_orders_enqueue_assets() {
	if ( ! is_user_logged_in() || ! gacct_orders_is_page() ) {
		return;
	}

	$base_url = plugins_url( '', dirname( __FILE__ ) );
	$base_dir = dirname( __DIR__ );

	foreach ( array( 'gacct-orders' => 'assets/css/orders.css', 'gacct-view-order' => 'assets/css/view-order.css' ) as $handle => $rel ) {
		if ( file_exists( $base_dir . '/' . $rel ) ) {
			wp_enqueue_style( $handle, $base_url . '/' . $rel, array(), (string) filemtime( $base_dir . '/' . $rel ) );
		}
	}
}
