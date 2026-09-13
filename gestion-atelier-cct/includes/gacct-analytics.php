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
 *  - côté JS (demande-v2.js) : demande_etape { step }, demande_envoi { value, prestations },
 *    formulaire_erreur { step, champ, message }
 *  - côté JS (analytics.js) : email_clic { campagne }, relance_clic { campagne, order_id },
 *    clic_contact { type: telephone|email|itineraire }, contact_envoi { form_id }
 *
 * Événements posés côté serveur (13/09/2026), envoyés par le Measurement Protocol
 * quand l'identifiant client GA a été capturé à la commande (meta `_gacct_ga_client_id`)
 * et que l'option `gacct_ga_api_secret` est renseignée ; sinon mis en file pour le
 * client connecté : paiement_echoue, commande_abandonnee, relance_envoyee,
 * devis_envoye, rapport_telecharge.
 *
 * E-mails : tout lien vers le site dans un e-mail sortant reçoit
 * utm_source=email&utm_medium=transactionnel&utm_campaign=<modèle> (filtre wp_mail),
 * sauf e-mails destinés aux seuls administrateurs. Les relances d'abandon posent
 * leurs propres paramètres (utm_medium=relance).
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
	if ( ! gacct_ga_order_paid( $order ) ) {
		return null; // paiement non abouti : rien à compter
	}
	$items = gacct_ga_order_items( $order );
	$order->update_meta_data( GACCT_GA_PURCHASE_META, current_time( 'mysql' ) );
	$order->save();

	return gacct_ga_purchase_payload( $order, $items );
}

/**
 * Commande payée au sens du plugin (inclut le statut Kojito « acompte-paye »,
 * que WooCommerce ne considère pas comme payé : c'est ce qui a fait manquer
 * les achats des 11 et 12 septembre 2026).
 */
function gacct_ga_order_paid( $order ) {
	if ( function_exists( 'gacct_order_payment_received' ) ) {
		return (bool) gacct_order_payment_received( $order );
	}
	return $order->is_paid() || in_array( $order->get_status(), array( 'on-hold', 'processing', 'completed', 'acompte-paye' ), true );
}

function gacct_ga_order_items( $order ) {
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
	return $items;
}

function gacct_ga_purchase_payload( $order, array $items ) {
	return array(
		'event'          => 'purchase',
		'transaction_id' => (string) $order->get_id(),
		'value'          => (float) $order->get_total(),
		'tax'            => (float) $order->get_total_tax(),
		'shipping'       => (float) $order->get_shipping_total(),
		'currency'       => $order->get_currency(),
		'payment_method' => (string) $order->get_payment_method(),
		'client_statut'  => gacct_ga_client_statut( $order ),
		'relance'        => (string) $order->get_meta( '_gacct_relance_source' ),
		'items'          => $items,
	);
}

/** Nouveau / fidèle / ancien (badge posé par gacct-client-badge.php), vide sinon. */
function gacct_ga_client_statut( $order ) {
	$statut = $order->get_meta( '_gacct_client_statut' );
	if ( is_array( $statut ) && ! empty( $statut['code'] ) ) {
		return (string) $statut['code'];
	}
	if ( function_exists( 'gacct_client_statut' ) ) {
		$statut = gacct_client_statut( $order );
		return is_array( $statut ) ? (string) $statut['code'] : '';
	}
	return '';
}

/**
 * Dès que la commande est payée (carte, acompte ou virement confirmé), l'achat est
 * mis en file pour le client connecté : il part à sa prochaine page, même s'il ne
 * repasse pas par la confirmation. Garde : meta GACCT_GA_PURCHASE_META.
 */
function gacct_ga_queue_purchase( $order ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	if ( ! $order || $order->get_meta( GACCT_GA_PURCHASE_META ) || ! gacct_ga_order_paid( $order ) ) {
		return false;
	}
	$customer_id = $order->get_customer_id();
	if ( ! $customer_id ) {
		return false; // invité : la page de confirmation s'en charge
	}
	gacct_ga_queue( $customer_id, 'purchase', gacct_ga_purchase_payload( $order, gacct_ga_order_items( $order ) ) );
	$order->update_meta_data( GACCT_GA_PURCHASE_META, current_time( 'mysql' ) . ' (file)' );
	$order->save();
	return true;
}
add_action( 'woocommerce_order_status_changed', function ( $order_id ) {
	gacct_ga_queue_purchase( $order_id );
}, 30 );
add_action( 'woocommerce_payment_complete', function ( $order_id ) {
	gacct_ga_queue_purchase( $order_id );
}, 30 );

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
	$events = array_merge( gacct_ga_flush_queue(), gacct_ga_page_events() );
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

/* -------------------------------------------------------------------------
 * Événements posés pendant la requête (poussés dans la page en cours)
 * ---------------------------------------------------------------------- */

function gacct_ga_page_events( array $add = null ) {
	static $events = array();
	if ( null !== $add ) {
		$events[] = $add;
	}
	return $events;
}

/* -------------------------------------------------------------------------
 * Identifiants GA du visiteur (cookies _ga et _ga_<flux>)
 * ---------------------------------------------------------------------- */

/** @return array{client_id:string,session_id:string} */
function gacct_ga_cookie_ids() {
	$client  = '';
	$session = '';
	if ( ! empty( $_COOKIE['_ga'] ) && preg_match( '/^GA\d\.\d\.(\d+\.\d+)$/', (string) $_COOKIE['_ga'], $m ) ) {
		$client = $m[1];
	}
	foreach ( $_COOKIE as $name => $value ) {
		if ( 0 !== strpos( (string) $name, '_ga_' ) ) {
			continue;
		}
		$value = (string) $value;
		// GS2.1.s1757000000$o3$g1$t1757000100$j0$l0$h0  ou  GS1.1.1757000000.3.1.1757000100.0.0.0
		if ( preg_match( '/^GS\d\.\d\.s(\d+)/', $value, $m ) || preg_match( '/^GS\d\.\d\.(\d{9,})/', $value, $m ) ) {
			$session = $m[1];
			break;
		}
	}
	return array( 'client_id' => $client, 'session_id' => $session );
}

// À la commande : on retient l'identifiant GA du navigateur pour pouvoir lui
// rattacher, plus tard et sans visite, l'échec de paiement ou l'abandon.
add_action( 'woocommerce_checkout_order_processed', function ( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$ids = gacct_ga_cookie_ids();
	if ( $ids['client_id'] ) {
		$order->update_meta_data( '_gacct_ga_client_id', $ids['client_id'] );
	}
	if ( $ids['session_id'] ) {
		$order->update_meta_data( '_gacct_ga_session_id', $ids['session_id'] );
	}
	$order->save();
}, 25 );

/* -------------------------------------------------------------------------
 * Measurement Protocol (événements serveur, sans visite du client)
 * ---------------------------------------------------------------------- */

function gacct_ga_measurement_id() {
	$id = trim( (string) get_option( 'gacct_ga_measurement_id', '' ) );
	return preg_match( '/^G-[A-Z0-9]{6,12}$/', $id ) ? $id : '';
}

function gacct_ga_mp_ready() {
	if ( '' === gacct_ga_measurement_id() || '' === trim( (string) get_option( 'gacct_ga_api_secret', '' ) ) ) {
		return false;
	}
	return 'prod' === gacct_ga_env() || apply_filters( 'gacct_ga_mp_allow_dev', false );
}

/**
 * Envoie un événement à GA4 par le Measurement Protocol.
 *
 * @param string $client_id  Identifiant client GA (cookie _ga).
 * @param string $event      Nom de l'événement.
 * @param array  $params     Paramètres.
 * @param int    $user_id    Utilisateur WordPress (facultatif).
 * @param string $session_id Session GA (facultatif, améliore le rattachement).
 * @return bool
 */
function gacct_ga_mp_send( $client_id, $event, array $params = array(), $user_id = 0, $session_id = '' ) {
	if ( ! gacct_ga_mp_ready() || '' === $client_id ) {
		return false;
	}
	$params['engagement_time_msec'] = 1;
	$params['site_env']             = gacct_ga_env();
	if ( $session_id ) {
		$params['session_id'] = (string) $session_id;
	}
	$body = array(
		'client_id' => (string) $client_id,
		'events'    => array( array( 'name' => (string) $event, 'params' => $params ) ),
	);
	if ( $user_id ) {
		$body['user_id'] = (string) $user_id;
	}
	$url = add_query_arg( array(
		'measurement_id' => gacct_ga_measurement_id(),
		'api_secret'     => trim( (string) get_option( 'gacct_ga_api_secret', '' ) ),
	), 'https://www.google-analytics.com/mp/collect' );

	$response = wp_remote_post( $url, array(
		'timeout'  => 3,
		'blocking' => false,
		'headers'  => array( 'Content-Type' => 'application/json' ),
		'body'     => wp_json_encode( $body ),
	) );
	return ! is_wp_error( $response );
}

/**
 * Événement lié à une commande : Measurement Protocol si l'identifiant GA a été
 * capturé, sinon file d'attente du client connecté (part à sa prochaine visite).
 */
function gacct_ga_order_event( $order, $event, array $params = array(), $guard_meta = '' ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	if ( ! $order || '' === gacct_ga_gtm_id() ) {
		return false;
	}
	if ( $guard_meta && $order->get_meta( $guard_meta ) ) {
		return false;
	}
	$params = array_merge( array(
		'order_id' => (string) $order->get_id(),
		'value'    => (float) $order->get_total(),
		'currency' => $order->get_currency(),
	), $params );

	$sent = gacct_ga_mp_send(
		(string) $order->get_meta( '_gacct_ga_client_id' ),
		$event,
		$params,
		(int) $order->get_customer_id(),
		(string) $order->get_meta( '_gacct_ga_session_id' )
	);
	if ( ! $sent && $order->get_customer_id() ) {
		gacct_ga_queue( $order->get_customer_id(), $event, $params );
		$sent = true;
	}
	if ( $sent && $guard_meta ) {
		$order->update_meta_data( $guard_meta, current_time( 'mysql' ) );
		$order->save();
	}
	return $sent;
}

// Paiement refusé ou abandonné sur la page de paiement (CAWL passe la commande en « échouée »).
add_action( 'woocommerce_order_status_failed', function ( $order_id, $order = null ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
	if ( $order ) {
		gacct_ga_order_event( $order, 'paiement_echoue', array( 'payment_method' => (string) $order->get_payment_method() ), '_gacct_ga_failed_sent' );
	}
}, 20, 2 );

// Annulation automatique faute de paiement (hook posé dans gacct_pay_cancel_unpaid_order).
add_action( 'gacct_pay_order_auto_cancelled', function ( $order, $template_key, $reason ) {
	gacct_ga_order_event( $order, 'commande_abandonnee', array(
		'motif'          => 'bacs_cancel' === $template_key ? 'virement' : 'carte',
		'payment_method' => (string) $order->get_payment_method(),
	), '_gacct_ga_abandon_sent' );
}, 10, 3 );

// Devis complémentaire envoyé par l'atelier (hook posé dans gacct_quote_send).
add_action( 'gacct_quote_sent', function ( $order, $revision_id, $extras_total ) {
	gacct_ga_order_event( $order, 'devis_envoye', array(
		'revision_id' => (string) $revision_id,
		'value'       => (float) $extras_total,
	) );
}, 10, 3 );

// Rapport d'intervention ouvert ou téléchargé depuis l'espace client.
add_action( 'gacct_report_served', function ( $attachment_id, $row, $user_id ) {
	if ( '' === gacct_ga_gtm_id() || 'client' !== gacct_ga_user_role() ) {
		return; // seuls les clients comptent, pas l'atelier qui relit ses rapports
	}
	$ids    = gacct_ga_cookie_ids();
	$params = array( 'revision_id' => (string) ( $row['_ID'] ?? '' ) );
	if ( ! gacct_ga_mp_send( $ids['client_id'], 'rapport_telecharge', $params, (int) $user_id, $ids['session_id'] ) ) {
		gacct_ga_queue( $user_id, 'rapport_telecharge', $params );
	}
}, 10, 3 );

/* -------------------------------------------------------------------------
 * E-mails : paramètres de campagne sur les liens vers le site
 * ---------------------------------------------------------------------- */

/**
 * Nom de campagne de l'e-mail en préparation (consommé par le filtre wp_mail).
 * gacct_pay_send_email() y pose la clé du modèle, les e-mails d'état « etat-N »,
 * WooCommerce l'identifiant de son e-mail.
 */
function gacct_ga_email_campaign( $set = null ) {
	static $campaign = '';
	if ( null !== $set ) {
		$campaign = (string) $set;
	}
	return $campaign;
}

add_action( 'woocommerce_email_header', function ( $heading, $email = null ) {
	if ( $email instanceof WC_Email ) {
		gacct_ga_email_campaign( 'wc-' . str_replace( '_', '-', $email->id ) );
	}
}, 10, 2 );

/** Ajoute les utm à une URL du site qui n'en a pas encore (null : lien à laisser tel quel). */
function gacct_ga_tag_url( $url, $campaign ) {
	$url  = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( strcasecmp( (string) wp_parse_url( $url, PHP_URL_HOST ), $host ) !== 0 ) {
		return null;
	}
	if ( false !== stripos( $url, 'utm_' ) || preg_match( '#^/(wp-admin|wp-login\.php|wp-content|wp-json)#', $path ) ) {
		return null;
	}
	return add_query_arg( array(
		'utm_source'   => 'email',
		'utm_medium'   => 'transactionnel',
		'utm_campaign' => $campaign,
	), $url );
}

add_filter( 'wp_mail', function ( $args ) {
	$campaign = gacct_ga_email_campaign();
	gacct_ga_email_campaign( '' );
	if ( '' === gacct_ga_gtm_id() || empty( $args['message'] ) || ! is_string( $args['message'] ) ) {
		return $args;
	}
	$campaign = $campaign ? sanitize_title( $campaign ) : 'autre';

	// Destinataires : les e-mails réservés aux administrateurs ne sont pas balisés.
	$to = is_array( $args['to'] ) ? $args['to'] : explode( ',', (string) $args['to'] );
	$to = array_filter( array_map( 'trim', $to ) );
	$admins = array_map( 'strtolower', array_merge(
		function_exists( 'gacct_pay_admin_emails' ) ? gacct_pay_admin_emails() : array(),
		array( (string) get_option( 'admin_email' ) )
	) );
	$externe = false;
	foreach ( $to as $addr ) {
		if ( preg_match( '/<([^>]+)>/', $addr, $m ) ) {
			$addr = $m[1];
		}
		if ( ! in_array( strtolower( $addr ), $admins, true ) ) {
			$externe = true;
		}
	}
	if ( ! $externe ) {
		return $args;
	}

	$headers = is_array( $args['headers'] ) ? implode( "\n", $args['headers'] ) : (string) $args['headers'];
	$html    = false !== stripos( $headers, 'text/html' ) || false !== stripos( $args['message'], '<a ' );

	if ( $html ) {
		$args['message'] = preg_replace_callback( '/(<a\b[^>]*\bhref=)(["\'])([^"\']+)\2/i', function ( $m ) use ( $campaign ) {
			$tagged = gacct_ga_tag_url( $m[3], $campaign );
			return null === $tagged ? $m[0] : $m[1] . $m[2] . esc_url( $tagged ) . $m[2];
		}, $args['message'] );
	} else {
		$args['message'] = preg_replace_callback( '#https?://[^\s<>"\')]+#i', function ( $m ) use ( $campaign ) {
			$url    = rtrim( $m[0], '.,;:!?' );
			$suffix = substr( $m[0], strlen( $url ) );
			$tagged = gacct_ga_tag_url( $url, $campaign );
			return null === $tagged ? $m[0] : $tagged . $suffix;
		}, $args['message'] );
	}
	return $args;
}, 20 );

/* -------------------------------------------------------------------------
 * Script front : clics de contact, formulaires, arrivée depuis un e-mail
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', function () {
	if ( ! gacct_ga_enabled() ) {
		return;
	}
	$rel = 'assets/js/analytics.js';
	wp_enqueue_script(
		'gacct-analytics',
		plugins_url( $rel, GACCT_PLUGIN_FILE ),
		array(),
		gacct_asset_version( $rel ),
		true
	);
	wp_localize_script( 'gacct-analytics', 'gacctAnalytics', array(
		'demandeFormId' => function_exists( 'gacct_demande_v2_form_id' ) ? (int) gacct_demande_v2_form_id() : 0,
	) );
}, 20 );
