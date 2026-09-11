<?php
/**
 * Mesure d'audience : Google Tag Manager + dataLayer métier.
 *
 * Inerte tant que l'option `gacct_gtm_id` est vide (AEROTECH n'est pas concerné).
 *
 * Ce que le site pousse dans window.dataLayer :
 *  - au chargement : { site_env, page_type, user_status, user_role, user_id }
 *  - événements différés (file d'attente en user meta, vidée à la page suivante) :
 *      sign_up { method: email|google|checkout }, login { method },
 *      devis_decision { decision: accepted|refused, mode, order_id, value }
 *  - purchase (page de confirmation, une seule fois par commande) :
 *      { transaction_id, value, currency, items[] }
 *  - côté JS (demande-v2.js) : demande_etape { step }, demande_envoi { value, prestations }
 *
 * Les balises, déclencheurs et événements clés vivent dans GTM / GA4.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_GA_QUEUE_META', '_gacct_ga_queue' );
define( 'GACCT_GA_PURCHASE_META', '_gacct_ga_purchase_sent' );

/** Identifiant du conteneur GTM (option `gacct_gtm_id`, filtre `gacct_gtm_id`). */
function gacct_ga_gtm_id() {
	$id = trim( (string) get_option( 'gacct_gtm_id', '' ) );
	$id = apply_filters( 'gacct_gtm_id', $id );
	return preg_match( '/^GTM-[A-Z0-9]{4,10}$/', $id ) ? $id : '';
}

function gacct_ga_enabled() {
	return '' !== gacct_ga_gtm_id() && ! is_admin();
}

/** Environnement : prod si l'hôte du site est le domaine public, dev sinon. */
function gacct_ga_env() {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$env  = ( false !== strpos( $host, 'cogitoweb.net' ) ) ? 'dev' : 'prod';
	return apply_filters( 'gacct_ga_env', $env, $host );
}

/** Type de page, lu par les déclencheurs GTM (entonnoir). */
function gacct_ga_page_type() {
	if ( function_exists( 'is_checkout' ) ) {
		if ( is_order_received_page() ) {
			return 'confirmation';
		}
		if ( is_checkout() ) {
			return 'commande';
		}
		if ( is_cart() ) {
			return 'panier';
		}
		if ( is_account_page() ) {
			return 'compte';
		}
	}
	if ( is_front_page() ) {
		return 'accueil';
	}
	if ( is_singular( 'post' ) ) {
		return 'article';
	}
	if ( is_page() ) {
		$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
		$map  = array(
			'demande-intervention'     => 'demande',
			'connexion'                => 'connexion',
			'sinscrire'                => 'inscription',
			'mot-de-passe-oublie'      => 'mot-de-passe',
			'devis-a-valider'          => 'devis',
			'tarifs'                   => 'tarifs',
			'contact'                  => 'contact',
			'controles'                => 'prestation',
			'reparations'              => 'prestation',
			'pliages-secours'          => 'prestation',
			'suspentes'                => 'prestation',
			'consignes-demballage'     => 'aide',
			'actualites'               => 'actualites',
		);
		if ( isset( $map[ $slug ] ) ) {
			return $map[ $slug ];
		}
		if ( 0 === strpos( $slug, 'revision-parapente-' ) ) {
			return 'region';
		}
		return 'page';
	}
	return 'autre';
}

function gacct_ga_user_role() {
	if ( ! is_user_logged_in() ) {
		return 'visiteur';
	}
	$user = wp_get_current_user();
	if ( user_can( $user, 'manage_options' ) ) {
		return 'admin';
	}
	if ( in_array( 'atelier', (array) $user->roles, true ) || user_can( $user, 'manage_woocommerce' ) ) {
		return 'atelier';
	}
	return 'client';
}

/* -------------------------------------------------------------------------
 * File d'attente d'événements (posés côté serveur, poussés à la page suivante)
 * ---------------------------------------------------------------------- */

function gacct_ga_queue( $user_id, $event, array $data = array() ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}
	$queue   = get_user_meta( $user_id, GACCT_GA_QUEUE_META, true );
	$queue   = is_array( $queue ) ? $queue : array();
	$queue[] = array_merge( array( 'event' => (string) $event ), $data );
	update_user_meta( $user_id, GACCT_GA_QUEUE_META, array_slice( $queue, -10 ) );
}

function gacct_ga_flush_queue() {
	if ( ! is_user_logged_in() ) {
		return array();
	}
	$user_id = get_current_user_id();
	$queue   = get_user_meta( $user_id, GACCT_GA_QUEUE_META, true );
	if ( empty( $queue ) || ! is_array( $queue ) ) {
		return array();
	}
	delete_user_meta( $user_id, GACCT_GA_QUEUE_META );
	return $queue;
}

/* -------------------------------------------------------------------------
 * Sources d'événements
 * ---------------------------------------------------------------------- */

// Inscription : formulaire e-mail (login_ui), création au checkout, ou Google (Nextend).
add_action( 'user_register', function ( $user_id ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return; // création par un administrateur dans le back-office
	}
	$method = 'email';
	if ( function_exists( 'is_checkout' ) && function_exists( 'WC' ) && WC()->session && did_action( 'woocommerce_checkout_process' ) ) {
		$method = 'checkout';
	}
	gacct_ga_queue( $user_id, 'sign_up', array( 'method' => $method ) );
}, 99 );

// Nextend Social Login : nouveau compte via Google.
add_action( 'nsl_register_new_user', function ( $user_id, $provider = null ) {
	$slug  = is_object( $provider ) && method_exists( $provider, 'getId' ) ? $provider->getId() : 'social';
	$queue = get_user_meta( $user_id, GACCT_GA_QUEUE_META, true );
	$queue = is_array( $queue ) ? $queue : array();
	foreach ( $queue as $i => $ev ) {
		if ( isset( $ev['event'] ) && 'sign_up' === $ev['event'] ) {
			$queue[ $i ]['method'] = $slug;
		}
	}
	update_user_meta( $user_id, GACCT_GA_QUEUE_META, $queue );
	update_user_meta( $user_id, '_gacct_ga_login_method', $slug );
}, 10, 2 );

// Connexion.
add_action( 'wp_login', function ( $login, $user ) {
	if ( ! $user instanceof WP_User ) {
		return;
	}
	$queue = get_user_meta( $user->ID, GACCT_GA_QUEUE_META, true );
	if ( is_array( $queue ) ) {
		foreach ( $queue as $ev ) {
			if ( isset( $ev['event'] ) && 'sign_up' === $ev['event'] ) {
				return; // la connexion qui suit l'inscription n'est pas un login à part
			}
		}
	}
	$method = did_action( 'nsl_login' ) || ! empty( $_REQUEST['loginSocial'] ) ? 'google' : 'email'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	gacct_ga_queue( $user->ID, 'login', array( 'method' => $method ) );
}, 10, 2 );

// Décision sur un devis (hook posé dans gacct_quote_mark_decision).
add_action( 'gacct_quote_decision_made', function ( $order, $decision, $mode ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	gacct_ga_queue( $order->get_customer_id(), 'devis_decision', array(
		'decision' => (string) $decision,
		'mode'     => (string) $mode,
		'order_id' => (string) $order->get_id(),
		'value'    => (float) $order->get_total(),
		'currency' => $order->get_currency(),
	) );
}, 10, 3 );

/* -------------------------------------------------------------------------
 * Achat : page de confirmation, une seule fois par commande
 * ---------------------------------------------------------------------- */

function gacct_ga_purchase_data() {
	if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
		return null;
	}
	$order_id = absint( get_query_var( 'order-received' ) );
	$order    = $order_id ? wc_get_order( $order_id ) : null;
	if ( ! $order || $order->get_meta( GACCT_GA_PURCHASE_META ) ) {
		return null;
	}
	if ( ! $order->is_paid() && ! in_array( $order->get_status(), array( 'on-hold', 'processing', 'completed' ), true ) ) {
		return null; // paiement non abouti : rien à compter
	}
	$items = array();
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		$items[] = array(
			'item_id'   => (string) $item->get_product_id(),
			'item_name' => $item->get_name(),
			'quantity'  => (int) $item->get_quantity(),
			'price'     => $product ? (float) wc_get_price_including_tax( $product ) : (float) $item->get_total(),
		);
	}
	$order->update_meta_data( GACCT_GA_PURCHASE_META, current_time( 'mysql' ) );
	$order->save();

	return array(
		'event'          => 'purchase',
		'transaction_id' => (string) $order->get_id(),
		'value'          => (float) $order->get_total(),
		'tax'            => (float) $order->get_total_tax(),
		'shipping'       => (float) $order->get_shipping_total(),
		'currency'       => $order->get_currency(),
		'payment_method' => (string) $order->get_payment_method(),
		'items'          => $items,
	);
}

/* -------------------------------------------------------------------------
 * Sortie HTML
 * ---------------------------------------------------------------------- */

add_action( 'wp_head', function () {
	if ( ! gacct_ga_enabled() ) {
		return;
	}
	$gtm  = gacct_ga_gtm_id();
	$init = array(
		'site_env'    => gacct_ga_env(),
		'page_type'   => gacct_ga_page_type(),
		'user_status' => is_user_logged_in() ? 'connecte' : 'anonyme',
		'user_role'   => gacct_ga_user_role(),
		'user_id'     => is_user_logged_in() ? (string) get_current_user_id() : '',
	);
	$events = gacct_ga_flush_queue();
	$purchase = gacct_ga_purchase_data();
	if ( $purchase ) {
		$events[] = $purchase;
	}
	echo "<!-- Google Tag Manager -->\n<script>\nwindow.dataLayer = window.dataLayer || [];\n";
	echo 'window.dataLayer.push(' . wp_json_encode( $init ) . ");\n";
	foreach ( $events as $ev ) {
		echo 'window.dataLayer.push(' . wp_json_encode( $ev ) . ");\n";
	}
	echo "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . esc_js( $gtm ) . "');\n";
	echo "</script>\n<!-- End Google Tag Manager -->\n";
}, 1 );

add_action( 'wp_body_open', function () {
	if ( ! gacct_ga_enabled() ) {
		return;
	}
	echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr( gacct_ga_gtm_id() ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
}, 1 );
