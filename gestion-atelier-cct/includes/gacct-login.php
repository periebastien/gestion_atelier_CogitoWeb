<?php
/**
 * Un client ne voit jamais wp-login.php ni wp-admin (recette du 09/09/2026).
 *
 * - Déconnexion sans jeton (liens du Profile Builder JetEngine) : plus de page
 *   « Vous êtes en train de vous déconnecter », on déconnecte et on renvoie sur
 *   l'accueil.
 * - wp-login.php (formulaire de connexion natif) : renvoyé vers la page de
 *   connexion du site (GACCT_LOGIN_PAGE_SLUG) pour les visiteurs. Exceptions :
 *   soumission POST, session admin expirée (reauth), connexion sociale
 *   (loginSocial), retour vers wp-admin, interim-login, et le paramètre de
 *   secours `?gacct_wp=1` qui affiche toujours le formulaire natif.
 * - Mot de passe oublié / réinitialisation : page front « Mot de passe oublié »
 *   (shortcode [gacct_lostpassword], formulaires WooCommerce), l'endpoint
 *   WooCommerce /mon-compte/lost-password/ étant avalé par le Profile Builder.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_LOSTPASSWORD_PAGE_SLUG', 'mot-de-passe-oublie' );

/**
 * URL de la page front « Mot de passe oublié » (repli : wp-login.php).
 *
 * @param array $args Paramètres à ajouter.
 * @return string
 */
function gacct_lostpassword_page_url( array $args = array() ) {
	$page = get_page_by_path( GACCT_LOSTPASSWORD_PAGE_SLUG );
	$url  = ( $page && 'publish' === $page->post_status ) ? get_permalink( $page ) : wp_login_url() . '?action=lostpassword';

	return $args ? add_query_arg( $args, $url ) : $url;
}

/**
 * URL de la page de connexion du site (repli : wp-login.php natif).
 */
function gacct_login_front_url( $redirect_to = '' ) {
	$page = defined( 'GACCT_LOGIN_PAGE_SLUG' ) ? get_page_by_path( GACCT_LOGIN_PAGE_SLUG ) : null;

	if ( ! $page || 'publish' !== $page->post_status ) {
		return '';
	}

	$url = get_permalink( $page );

	return $redirect_to ? add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url ) : $url;
}

/* -----------------------------------------------------------------------------
 *  Déconnexion sans jeton
 * -------------------------------------------------------------------------- */

add_action( 'login_form_logout', 'gacct_login_logout_without_nonce', 1 );

function gacct_login_logout_without_nonce() {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( $nonce && wp_verify_nonce( $nonce, 'log-out' ) ) {
		return; // Lien correct : WordPress fait le reste.
	}

	// Lien sans jeton (menu de l'espace client) : on déconnecte quand même.
	// Le seul risque serait une déconnexion forcée par un lien tiers : sans
	// conséquence, on ne bascule vers la page de confirmation native que si
	// la requête vient d'un autre site.
	$referer = wp_get_referer();
	if ( $referer && 0 !== strpos( $referer, home_url() ) ) {
		return;
	}

	wp_logout();

	$redirect = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_safe_redirect( $redirect ? $redirect : home_url( '/' ) );
	exit;
}

/* -----------------------------------------------------------------------------
 *  wp-login.php natif : réservé aux cas techniques
 * -------------------------------------------------------------------------- */

add_action( 'login_init', 'gacct_login_redirect_native_form' );

function gacct_login_redirect_native_form() {
	if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
		return;
	}

	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// Secours absolu pour l'équipe : ?gacct_wp=1 affiche toujours le natif.
	if ( ! empty( $_GET['gacct_wp'] ) || ! empty( $_GET['reauth'] ) || ! empty( $_GET['interim-login'] ) || ! empty( $_GET['loginSocial'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$redirect_to = isset( $_GET['redirect_to'] ) ? (string) wp_unslash( $_GET['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// Retour vers l'administration (session expirée, lien admin) : natif.
	if ( $redirect_to && false !== strpos( $redirect_to, '/wp-admin' ) ) {
		return;
	}

	if ( 'login' === $action || '' === $action ) {
		if ( is_user_logged_in() ) {
			wp_safe_redirect( $redirect_to ? $redirect_to : home_url( '/mon-compte/' ) );
			exit;
		}
		$front = gacct_login_front_url( $redirect_to );
		if ( $front ) {
			wp_safe_redirect( $front );
			exit;
		}
		return;
	}

	if ( in_array( $action, array( 'lostpassword', 'retrievepassword' ), true ) ) {
		$page = get_page_by_path( GACCT_LOSTPASSWORD_PAGE_SLUG );
		if ( $page && 'publish' === $page->post_status ) {
			wp_safe_redirect( get_permalink( $page ) );
			exit;
		}
		return;
	}

	if ( in_array( $action, array( 'rp', 'resetpass' ), true ) ) {
		$page = get_page_by_path( GACCT_LOSTPASSWORD_PAGE_SLUG );
		if ( $page && 'publish' === $page->post_status && ! empty( $_GET['key'] ) && ! empty( $_GET['login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( add_query_arg( array(
				'show-reset-form' => 'true',
				'key'             => sanitize_text_field( wp_unslash( $_GET['key'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'login'           => rawurlencode( sanitize_user( wp_unslash( $_GET['login'] ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			), get_permalink( $page ) ) );
			exit;
		}
	}
}

/* -----------------------------------------------------------------------------
 *  Mot de passe oublié : page front, formulaires WooCommerce
 * -------------------------------------------------------------------------- */

// WooCommerce construit ses redirections sur l'endpoint « lost-password » :
// on le fait pointer sur la page front.
add_filter( 'woocommerce_get_endpoint_url', 'gacct_lostpassword_endpoint_url', 10, 2 );

function gacct_lostpassword_endpoint_url( $url, $endpoint ) {
	if ( 'lost-password' !== $endpoint ) {
		return $url;
	}

	$page = get_page_by_path( GACCT_LOSTPASSWORD_PAGE_SLUG );

	return ( $page && 'publish' === $page->post_status ) ? get_permalink( $page ) : $url;
}

add_filter( 'lostpassword_url', 'gacct_lostpassword_url_filter', 20 );
add_filter( 'gacct_profile_lost_password_url', 'gacct_lostpassword_url_filter', 20 );

function gacct_lostpassword_url_filter( $url ) {
	$page = get_page_by_path( GACCT_LOSTPASSWORD_PAGE_SLUG );

	return ( $page && 'publish' === $page->post_status ) ? get_permalink( $page ) : $url;
}

// Lien de réinitialisation reçu par e-mail (key + login) : WooCommerce pose un
// cookie puis affiche le formulaire (WC_Form_Handler::redirect_reset_password_link
// ne le fait que sur la page Mon compte).
add_action( 'template_redirect', 'gacct_lostpassword_reset_cookie' );

function gacct_lostpassword_reset_cookie() {
	if ( ! is_page( GACCT_LOSTPASSWORD_PAGE_SLUG ) || empty( $_GET['key'] ) || empty( $_GET['login'] ) || ! empty( $_GET['show-reset-form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( ! class_exists( 'WC_Shortcode_My_Account' ) ) {
		return;
	}
	$user = get_user_by( 'login', sanitize_user( wp_unslash( $_GET['login'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! $user ) {
		return;
	}
	WC_Shortcode_My_Account::set_reset_password_cookie( sprintf( '%d:%s', $user->ID, wp_unslash( $_GET['key'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_safe_redirect( add_query_arg( 'show-reset-form', 'true', get_permalink() ) );
	exit;
}

add_shortcode( 'gacct_lostpassword', 'gacct_lostpassword_shortcode' );

function gacct_lostpassword_shortcode() {
	if ( ! class_exists( 'WC_Shortcode_My_Account' ) ) {
		return '';
	}

	if ( is_user_logged_in() && empty( $_GET['show-reset-form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '<div class="gacct-lost"><p>' . esc_html__( 'Vous êtes déjà connecté. Vous pouvez changer votre mot de passe depuis votre profil.', 'gestion-atelier-cct' ) . '</p><p><a class="gacct-lost-btn" href="' . esc_url( home_url( '/mon-compte/mon-profil/' ) ) . '">' . esc_html__( 'Aller à mon profil', 'gestion-atelier-cct' ) . '</a></p></div>';
	}

	ob_start();
	echo '<div class="gacct-lost woocommerce">';
	echo '<h1 class="gacct-lost-title">' . esc_html__( 'Mot de passe oublié', 'gestion-atelier-cct' ) . '</h1>';
	wc_print_notices();
	WC_Shortcode_My_Account::lost_password();
	echo '<p class="gacct-lost-back"><a href="' . esc_url( home_url( '/mon-compte/' ) ) . '">' . esc_html__( 'Retour à la connexion', 'gestion-atelier-cct' ) . '</a></p>';
	echo '</div>';

	$css = '<style>
.gacct-lost{max-width:520px;margin:32px auto 56px;padding:32px 28px;background:#fff;border-radius:16px;box-shadow:0 6px 30px rgba(0,0,0,.06)}
.gacct-lost h2,.gacct-lost .woocommerce-ResetPassword p:first-child,.gacct-lost .lost_reset_password p:first-child{font-size:1.05em;color:#444}
.gacct-lost-title{font-size:1.7em;margin:0 0 14px}
.gacct-lost label{display:block;font-weight:700;margin:14px 0 6px}
.gacct-lost input[type=text],.gacct-lost input[type=password]{width:100%;border:1px solid #d8d8d8;border-radius:10px;padding:12px 14px;font:inherit}
.gacct-lost button,.gacct-lost .button{display:inline-block;border:0;border-radius:999px;padding:12px 26px;font-weight:800;background:#f2b134;color:#1a1a1a;cursor:pointer;margin-top:16px}
.gacct-lost .woocommerce-message,.gacct-lost .woocommerce-error,.gacct-lost .woocommerce-info{border-radius:10px;padding:12px 16px;margin:0 0 16px;background:#f3f8f8;list-style:none}
.gacct-lost .woocommerce-error{background:#fdf1f0}
.gacct-lost-back{margin-top:22px;font-size:.95em}
.gacct-lost-btn{display:inline-block;border-radius:999px;padding:12px 26px;font-weight:800;background:#f2b134;color:#1a1a1a;text-decoration:none}
</style>';

	return $css . ob_get_clean();
}
