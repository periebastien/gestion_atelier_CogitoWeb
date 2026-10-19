<?php
/**
 * Connexion par e-mail : code à 6 chiffres ET lien magique (16/10/2026, décision Bastien).
 *
 * Beaucoup de clients passaient par « mot de passe oublié » : les comptes créés
 * par le portier n'ont pas de mot de passe choisi (34 sur 48 comptes créés en
 * 30 jours au 16/10/2026). Pour une adresse connue, l'écran « l'e-mail d'abord »
 * propose désormais en premier « Recevoir un code de connexion » ; le mot de passe
 * et Google restent possibles.
 *
 * - Un seul jeton actif par compte (user meta GACCT_LOGIN_CODE_META) : empreintes
 *   du code et du lien, expiration (12 h, filtre `gacct_login_code_ttl`), essais,
 *   destination après connexion. Un nouvel envoi remplace le précédent.
 * - Code : 5 essais au plus, puis jeton détruit. Envois : 1 par minute et 5 par
 *   heure et par adresse (en plus de la limite par IP de gacct_login_ajax_guard).
 * - Lien : /connexion/?gacct_ml=JETON&u=ID. Le GET ne connecte PAS (les antivirus
 *   de messagerie ouvrent les liens) : il affiche un bouton « Me connecter » qui
 *   poste le jeton. Code ou lien : le premier utilisé détruit le jeton.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_LOGIN_CODE_META', '_gacct_login_code' );

/**
 * Durée de validité du code et du lien (secondes).
 */
function gacct_login_code_ttl() {
	return (int) apply_filters( 'gacct_login_code_ttl', 12 * HOUR_IN_SECONDS );
}

/**
 * Empreinte d'un secret (code ou jeton), liée au compte.
 */
function gacct_login_code_hash( $secret, $user_id ) {
	return hash_hmac( 'sha256', $user_id . '|' . $secret, wp_salt( 'auth' ) );
}

/**
 * Jeton actif d'un compte, ou null s'il n'y en a pas ou s'il a expiré.
 *
 * @return array|null
 */
function gacct_login_code_get( $user_id ) {
	$rec = get_user_meta( $user_id, GACCT_LOGIN_CODE_META, true );

	if ( ! is_array( $rec ) || empty( $rec['exp'] ) ) {
		return null;
	}

	if ( time() > (int) $rec['exp'] ) {
		delete_user_meta( $user_id, GACCT_LOGIN_CODE_META );
		return null;
	}

	return $rec;
}

/**
 * Connecte le compte (session « se souvenir de moi ») et détruit le jeton.
 *
 * $method (code|lien) est lu par la mesure d'audience pendant wp_login
 * (événement GA4 login { method }).
 */
function gacct_login_code_signin( WP_User $user, $method = 'code' ) {
	delete_user_meta( $user->ID, GACCT_LOGIN_CODE_META );

	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, true, is_ssl() );

	$GLOBALS['gacct_login_method'] = $method;
	do_action( 'wp_login', $user->user_login, $user );
	unset( $GLOBALS['gacct_login_method'] );
}

/**
 * URL de la page de connexion portant le lien magique.
 */
function gacct_login_code_link( $user_id, $token ) {
	$page = defined( 'GACCT_LOGIN_PAGE_SLUG' ) ? get_page_by_path( GACCT_LOGIN_PAGE_SLUG ) : null;
	$base = $page ? get_permalink( $page ) : home_url( '/' );

	return add_query_arg( array( 'gacct_ml' => $token, 'u' => (int) $user_id ), $base );
}

/**
 * Crée un jeton (code + lien) et envoie l'e-mail. Remplace le jeton précédent.
 *
 * @param WP_User $user
 * @param string  $redirect Destination après connexion.
 * @return bool
 */
function gacct_login_code_send( WP_User $user, $redirect ) {
	$code  = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	$token = wp_generate_password( 40, false, false );
	$ttl   = gacct_login_code_ttl();

	update_user_meta( $user->ID, GACCT_LOGIN_CODE_META, array(
		'code'     => gacct_login_code_hash( $code, $user->ID ),
		'token'    => gacct_login_code_hash( $token, $user->ID ),
		'exp'      => time() + $ttl,
		'tries'    => 0,
		'redirect' => (string) $redirect,
	) );

	$t       = gacct_login_texts();
	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$link    = gacct_login_code_link( $user->ID, $token );
	$pretty  = substr( $code, 0, 3 ) . ' ' . substr( $code, 3 );
	$hours   = (int) round( $ttl / HOUR_IN_SECONDS );
	$subject = sprintf( $t['code_subject'], $code, $site );

	$body = '<p>' . esc_html__( 'Bonjour,', 'gestion-atelier-cct' ) . '</p>'
		. '<p>' . esc_html( $t['code_mail_intro'] ) . '</p>'
		. '<p style="margin:24px 0;text-align:center;"><span style="display:inline-block;padding:14px 26px;border-radius:10px;background:#f3f8f8;font-size:32px;font-weight:bold;letter-spacing:6px;color:#1a1a1a;">' . esc_html( $pretty ) . '</span></p>'
		. '<p>' . esc_html( $t['code_mail_or'] ) . '</p>'
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:12px 0 20px;"><tr><td style="background:#ffbd20;border-radius:6px;">'
		. '<a href="' . esc_url( $link ) . '" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:bold;color:#1a1a1a;text-decoration:none;">' . esc_html( $t['code_mail_btn'] ) . '</a>'
		. '</td></tr></table>'
		. '<p>' . esc_html( sprintf( $t['code_mail_validity'], $hours ) ) . '</p>'
		. '<p>' . esc_html( $t['code_mail_ignore'] ) . '</p>'
		. '<p>' . esc_html__( 'À bientôt,', 'gestion-atelier-cct' ) . '<br><br>' . ( function_exists( 'gacct_team_signature' ) ? gacct_team_signature() : esc_html( $site ) ) . '</p>';

	$message = function_exists( 'gacct_render_email_html' ) ? gacct_render_email_html( $subject, $body ) : $body;

	if ( function_exists( 'gacct_ga_email_campaign' ) ) {
		gacct_ga_email_campaign( 'code-connexion' );
	}

	return (bool) wp_mail( $user->user_email, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/* -----------------------------------------------------------------------------
 *  AJAX : envoi et vérification du code
 * -------------------------------------------------------------------------- */

add_action( 'wp_ajax_nopriv_gacct_login_sendcode', 'gacct_login_ajax_sendcode' );
add_action( 'wp_ajax_nopriv_gacct_login_verifycode', 'gacct_login_ajax_verifycode' );

function gacct_login_ajax_sendcode() {
	$g    = gacct_login_ajax_guard();
	$t    = $g['texts'];
	$user = get_user_by( 'email', $g['email'] );

	if ( ! $user ) {
		wp_send_json_error( array( 'message' => $t['err_generic'] ) );
	}

	// Limites par adresse : 1 envoi par minute, 5 par heure.
	$key  = 'gacct_login_code_rl_' . $user->ID;
	$sent = array_filter( (array) get_transient( $key ), static function ( $ts ) {
		return $ts > time() - HOUR_IN_SECONDS;
	} );

	if ( $sent && max( $sent ) > time() - MINUTE_IN_SECONDS ) {
		wp_send_json_error( array( 'message' => $t['code_wait'] ) );
	}
	if ( count( $sent ) >= (int) apply_filters( 'gacct_login_code_hourly', 5 ) ) {
		wp_send_json_error( array( 'message' => $t['err_ratelimit'] ) );
	}

	$redirect = isset( $_POST['redirect_to'] ) ? (string) wp_unslash( $_POST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par le garde.
	$redirect = gacct_login_redirect_target( $redirect, $user );

	if ( ! gacct_login_code_send( $user, $redirect ) ) {
		wp_send_json_error( array( 'message' => $t['err_generic'] ) );
	}

	$sent[] = time();
	set_transient( $key, array_values( $sent ), HOUR_IN_SECONDS );

	wp_send_json_success( array( 'message' => sprintf( $t['code_sent'], $user->user_email ) ) );
}

/**
 * Destination à mémoriser avec le jeton : celle que l'écran de connexion
 * calculerait maintenant (ticket du portier, redirect_to, Mon compte). Le ticket
 * du portier n'est pas consommé ici : il l'est à la connexion.
 */
function gacct_login_redirect_target( $redirect, $user = null ) {
	$target = '';

	if ( function_exists( 'gacct_login_gate_get_ticket' ) ) {
		$target = (string) gacct_login_gate_get_ticket();
	}
	if ( ! $target && $redirect ) {
		$target = wp_validate_redirect( $redirect, '' );
	}

	return $target ? $target : home_url( '/mon-compte/' );
}

function gacct_login_ajax_verifycode() {
	$g    = gacct_login_ajax_guard();
	$t    = $g['texts'];
	$user = get_user_by( 'email', $g['email'] );
	$code = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	$rec = $user ? gacct_login_code_get( $user->ID ) : null;

	if ( ! $rec ) {
		wp_send_json_error( array( 'message' => $t['code_expired'], 'expired' => true ) );
	}

	if ( 6 !== strlen( $code ) || ! hash_equals( (string) $rec['code'], gacct_login_code_hash( $code, $user->ID ) ) ) {
		$rec['tries'] = (int) $rec['tries'] + 1;

		if ( $rec['tries'] >= (int) apply_filters( 'gacct_login_code_tries', 5 ) ) {
			delete_user_meta( $user->ID, GACCT_LOGIN_CODE_META );
			wp_send_json_error( array( 'message' => $t['code_expired'], 'expired' => true ) );
		}

		update_user_meta( $user->ID, GACCT_LOGIN_CODE_META, $rec );
		wp_send_json_error( array( 'message' => $t['code_wrong'] ) );
	}

	$redirect = (string) $rec['redirect'];
	gacct_login_code_signin( $user, 'code' );

	if ( function_exists( 'gacct_login_gate_clear_cookie' ) ) {
		gacct_login_gate_clear_cookie();
	}

	wp_send_json_success( array( 'redirect' => $redirect ? $redirect : home_url( '/mon-compte/' ) ) );
}

/* -----------------------------------------------------------------------------
 *  Lien magique
 * -------------------------------------------------------------------------- */

/**
 * Jeton du lien présent dans l'URL et valide : le compte, sinon null.
 *
 * @return WP_User|null
 */
function gacct_login_magic_user( $token, $user_id ) {
	$user_id = (int) $user_id;
	$token   = (string) $token;

	if ( ! $user_id || '' === $token ) {
		return null;
	}

	$rec = gacct_login_code_get( $user_id );

	if ( ! $rec || ! hash_equals( (string) $rec['token'], gacct_login_code_hash( $token, $user_id ) ) ) {
		return null;
	}

	$user = get_user_by( 'id', $user_id );

	return $user instanceof WP_User ? $user : null;
}

// POST du bouton « Me connecter » : connexion puis redirection (avant tout affichage).
add_action( 'template_redirect', 'gacct_login_magic_post', 1 );

function gacct_login_magic_post() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['gacct_ml_go'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- le jeton secret tient lieu de nonce.
		return;
	}

	$token = isset( $_POST['gacct_ml'] ) ? sanitize_text_field( wp_unslash( $_POST['gacct_ml'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$uid   = isset( $_POST['u'] ) ? absint( $_POST['u'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$user  = gacct_login_magic_user( $token, $uid );

	if ( ! $user ) {
		return; // L'écran affichera « lien expiré ».
	}

	$rec      = gacct_login_code_get( $user->ID );
	$redirect = $rec && ! empty( $rec['redirect'] ) ? (string) $rec['redirect'] : home_url( '/mon-compte/' );

	if ( is_user_logged_in() ) {
		wp_logout(); // autre compte ouvert sur ce navigateur : on bascule.
	}

	gacct_login_code_signin( $user, 'lien' );

	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Écran du lien magique (rendu par gacct_login_ui_render quand ?gacct_ml est présent).
 *
 * @return string
 */
function gacct_login_magic_screen() {
	$t     = gacct_login_texts();
	$token = isset( $_GET['gacct_ml'] ) ? sanitize_text_field( wp_unslash( $_GET['gacct_ml'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$uid   = isset( $_GET['u'] ) ? absint( $_GET['u'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$user  = gacct_login_magic_user( $token, $uid );

	gacct_login_ui_enqueue_assets();

	ob_start();
	?>
	<div class="gacct-login gacct-login--magic">
		<?php if ( $user ) : ?>
			<div class="gacct-login-who">
				<p class="gacct-login-who-title"><?php echo esc_html( $t['magic_title'] ); ?></p>
				<p class="gacct-login-who-mail"><?php echo esc_html( $user->user_email ); ?></p>
			</div>
			<form method="post" action="<?php echo esc_url( remove_query_arg( array( 'gacct_ml', 'u' ) ) ); ?>">
				<input type="hidden" name="gacct_ml" value="<?php echo esc_attr( $token ); ?>">
				<input type="hidden" name="u" value="<?php echo esc_attr( (string) $uid ); ?>">
				<button type="submit" name="gacct_ml_go" value="1" class="gacct-login-btn"><?php echo esc_html( $t['magic_btn'] ); ?></button>
			</form>
		<?php else : ?>
			<p class="gacct-login-msg gacct-login-msg--error"><?php echo esc_html( $t['magic_expired'] ); ?></p>
			<p class="gacct-login-forgot"><a class="gacct-login-link" href="<?php echo esc_url( remove_query_arg( array( 'gacct_ml', 'u' ) ) ); ?>"><?php echo esc_html( $t['magic_again'] ); ?></a></p>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
