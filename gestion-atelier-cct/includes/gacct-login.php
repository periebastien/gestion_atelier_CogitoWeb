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
	$args = array( 'show-reset-form' => 'true' );
	if ( ! empty( $_GET['bienvenue'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args['bienvenue'] = '1'; // lien de l'e-mail de bienvenue : titre « Choisissez votre mot de passe »
	}
	wp_safe_redirect( add_query_arg( $args, get_permalink() ) );
	exit;
}

/**
 * Après un mot de passe choisi (ou réinitialisé), le client est connecté dans
 * la foulée : WooCommerce le renvoie vers Mon compte, il y arrive connecté au
 * lieu de retomber sur la page de connexion (11/09/2026).
 */
add_action( 'woocommerce_customer_reset_password', function ( $user ) {
	if ( $user instanceof WP_User ) {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
	}
} );

add_shortcode( 'gacct_lostpassword', 'gacct_lostpassword_shortcode' );

/**
 * Page « Mot de passe » : même design que l'écran de connexion (login.css),
 * deux modes :
 *  - demande d'un lien (formulaire WooCommerce lost_password, champ user_login) ;
 *  - choix du mot de passe (cookie wp-resetpass posé par gacct_lostpassword_reset_cookie,
 *    champs password_1/2 + reset_key/reset_login attendus par WC_Form_Handler).
 * Les traitements restent ceux de WooCommerce (nonces lost_password / reset_password).
 * Refonte du 11/09/2026 (retour Bastien : page « très moche », mots de passe côte à côte).
 */
function gacct_lostpassword_shortcode() {
	if ( ! class_exists( 'WC_Shortcode_My_Account' ) ) {
		return '';
	}

	$show_reset = ! empty( $_GET['show-reset-form'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$welcome    = ! empty( $_GET['bienvenue'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$login_url  = function_exists( 'gacct_login_front_url' ) && gacct_login_front_url() ? gacct_login_front_url() : home_url( '/connexion/' );

	if ( function_exists( 'gacct_login_ui_enqueue_assets' ) ) {
		gacct_login_ui_enqueue_assets();
	}

	if ( is_user_logged_in() && ! $show_reset ) {
		return '<div class="gacct-login gacct-login--lost"><p class="gacct-login-intro">' . esc_html__( 'Vous êtes déjà connecté. Vous pouvez changer votre mot de passe depuis votre profil.', 'gestion-atelier-cct' ) . '</p><a class="gacct-login-btn gacct-login-btn--link" href="' . esc_url( home_url( '/mon-compte/mon-profil/' ) ) . '">' . esc_html__( 'Aller à mon profil', 'gestion-atelier-cct' ) . '</a></div>';
	}

	// Mode « choisir le mot de passe » : clé lue dans le cookie WooCommerce.
	$reset_user = null;
	$reset_key  = '';
	if ( $show_reset && isset( $_COOKIE[ 'wp-resetpass-' . COOKIEHASH ] ) && 0 < strpos( $_COOKIE[ 'wp-resetpass-' . COOKIEHASH ], ':' ) ) { // phpcs:ignore
		list( $rp_id, $rp_key ) = array_map( 'wc_clean', explode( ':', wp_unslash( $_COOKIE[ 'wp-resetpass-' . COOKIEHASH ] ), 2 ) ); // phpcs:ignore
		$rp_user = get_user_by( 'id', absint( $rp_id ) );
		if ( $rp_user ) {
			$checked = WC_Shortcode_My_Account::check_password_reset_key( $rp_key, $rp_user->user_login );
			if ( $checked instanceof WP_User ) {
				$reset_user = $checked;
				$reset_key  = $rp_key;
			}
		}
	}

	ob_start();
	echo '<div class="gacct-login gacct-login--lost">';

	// Notices WooCommerce (erreurs de saisie, confirmation d'envoi) dans le style de l'écran.
	if ( function_exists( 'wc_print_notices' ) ) {
		$notices = wc_print_notices( true );
		if ( $notices ) {
			echo '<div class="gacct-login-notices">' . wp_kses_post( $notices ) . '</div>';
		}
	}

	if ( $show_reset && $reset_user ) {
		$title = $welcome ? __( 'Choisissez votre mot de passe', 'gestion-atelier-cct' ) : __( 'Nouveau mot de passe', 'gestion-atelier-cct' );
		$intro = $welcome
			? __( 'Votre compte est créé. Choisissez le mot de passe qui vous servira à retrouver vos demandes, votre matériel et vos rapports.', 'gestion-atelier-cct' )
			: __( 'Saisissez votre nouveau mot de passe, puis confirmez-le.', 'gestion-atelier-cct' );
		?>
		<h2 class="gacct-login-title"><?php echo esc_html( $title ); ?></h2>
		<p class="gacct-login-intro"><?php echo esc_html( $intro ); ?></p>
		<form method="post" class="gacct-login-step gacct-login-step--reset" novalidate>
			<div class="gacct-login-field gacct-login-pw">
				<input class="gacct-login-input" type="password" id="gacct-pw1" name="password_1" autocomplete="new-password" minlength="8" required placeholder=" ">
				<label class="gacct-login-flabel" for="gacct-pw1"><?php esc_html_e( 'Mot de passe (8 caractères minimum)', 'gestion-atelier-cct' ); ?></label>
				<button type="button" class="gacct-login-eye" aria-label="<?php esc_attr_e( 'Afficher le mot de passe', 'gestion-atelier-cct' ); ?>" data-action="eye"></button>
			</div>
			<div class="gacct-login-field gacct-login-pw gacct-login-field--spaced">
				<input class="gacct-login-input" type="password" id="gacct-pw2" name="password_2" autocomplete="new-password" minlength="8" required placeholder=" ">
				<label class="gacct-login-flabel" for="gacct-pw2"><?php esc_html_e( 'Confirmez le mot de passe', 'gestion-atelier-cct' ); ?></label>
				<button type="button" class="gacct-login-eye" aria-label="<?php esc_attr_e( 'Afficher le mot de passe', 'gestion-atelier-cct' ); ?>" data-action="eye"></button>
			</div>
			<input type="hidden" name="reset_key" value="<?php echo esc_attr( $reset_key ); ?>">
			<input type="hidden" name="reset_login" value="<?php echo esc_attr( $reset_user->user_login ); ?>">
			<input type="hidden" name="wc_reset_password" value="true">
			<?php wp_nonce_field( 'reset_password', 'woocommerce-reset-password-nonce' ); ?>
			<button type="submit" class="gacct-login-btn"><?php echo esc_html( $welcome ? __( 'Enregistrer et accéder à mon espace', 'gestion-atelier-cct' ) : __( 'Enregistrer mon mot de passe', 'gestion-atelier-cct' ) ); ?></button>
		</form>
		<?php
	} elseif ( $show_reset ) {
		?>
		<h2 class="gacct-login-title"><?php esc_html_e( 'Ce lien n\'est plus valable', 'gestion-atelier-cct' ); ?></h2>
		<p class="gacct-login-intro"><?php esc_html_e( 'Il a expiré ou a déjà servi. Demandez un nouveau lien ci-dessous : il arrive en quelques secondes.', 'gestion-atelier-cct' ); ?></p>
		<?php
		gacct_lostpassword_request_form();
	} else {
		?>
		<h2 class="gacct-login-title"><?php esc_html_e( 'Mot de passe oublié', 'gestion-atelier-cct' ); ?></h2>
		<p class="gacct-login-intro"><?php esc_html_e( 'Indiquez votre adresse e-mail : vous recevrez un lien pour choisir un nouveau mot de passe.', 'gestion-atelier-cct' ); ?></p>
		<?php
		gacct_lostpassword_request_form();
	}

	echo '<p class="gacct-login-forgot"><a class="gacct-login-link" href="' . esc_url( $login_url ) . '">' . esc_html__( 'Retour à la connexion', 'gestion-atelier-cct' ) . '</a></p>';
	echo '</div>';
	?>
	<script>
	( function () {
		document.querySelectorAll( '.gacct-login--lost [data-action="eye"]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var input = btn.parentNode.querySelector( 'input' );
				var visible = input.type === 'text';
				input.type = visible ? 'password' : 'text';
				btn.classList.toggle( 'is-on', ! visible );
			} );
		} );
	} )();
	</script>
	<?php
	return ob_get_clean();
}

/**
 * Formulaire « recevoir un lien » (traité par WC_Form_Handler::process_lost_password).
 */
function gacct_lostpassword_request_form() {
	?>
	<form method="post" class="gacct-login-step gacct-login-step--lost" novalidate>
		<div class="gacct-login-field">
			<input class="gacct-login-input" type="email" id="gacct-lost-email" name="user_login" autocomplete="username email" inputmode="email" spellcheck="false" required placeholder=" ">
			<label class="gacct-login-flabel" for="gacct-lost-email"><?php esc_html_e( 'Adresse e-mail', 'gestion-atelier-cct' ); ?></label>
		</div>
		<input type="hidden" name="wc_reset_password" value="true">
		<?php wp_nonce_field( 'lost_password', 'woocommerce-lost-password-nonce' ); ?>
		<button type="submit" class="gacct-login-btn"><?php esc_html_e( 'Recevoir un lien', 'gestion-atelier-cct' ); ?></button>
	</form>
	<?php
}
