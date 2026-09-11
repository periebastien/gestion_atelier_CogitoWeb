<?php
/**
 * Connexion « l'e-mail d'abord » (piste A du cahier des charges UX, 09/09/2026).
 *
 * Un seul champ à l'entrée : l'adresse e-mail. Selon qu'elle est connue ou non,
 * le même écran se prolonge en connexion (mot de passe, lien de réinitialisation)
 * ou en création de compte SANS mot de passe (11/09/2026 : compte créé sur un
 * clic, mot de passe choisi plus tard via l'e-mail de bienvenue), sans changer de page. Le
 * client fidèle retrouve ainsi son compte et son matériel ; le nouveau client
 * n'a que deux champs à remplir, sur un écran, avant de composer sa demande.
 *
 * Shortcode `[gacct_connexion]` (posé sur la page de connexion GACCT_LOGIN_PAGE_SLUG
 * à la place du formulaire JFB 592, qui reste en secours sur /sinscrire/ et via
 * `?gacct_wp=1`). Endpoints AJAX `gacct_login_*`, nonce `gacct_login`.
 *
 * Après connexion ou création, la destination est celle du portier
 * (gacct-login-gate.php : ticket de retour), sinon `redirect_to`, sinon Mon compte.
 *
 * White-label : filtre `gacct_login_texts` (tous les libellés), `gacct_login_redirect`.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Libellés de l'écran de connexion (filtrables).
 *
 * @return array<string,string>
 */
function gacct_login_texts() {
	$texts = array(
		'email_label'       => 'Adresse e-mail',
		'email_placeholder' => 'vous@exemple.fr',
		'email_hint'        => 'Nous vérifions si vous avez déjà un compte chez nous.',
		'continue'          => 'Continuer',
		'change'            => 'Modifier',
		'hello'             => 'Bonjour %s',
		'known_title'       => 'Content de vous revoir',
		'known_intro'       => 'Saisissez votre mot de passe pour retrouver votre matériel et vos révisions.',
		'password_label'    => 'Mot de passe',
		'remember'          => 'Se souvenir de moi',
		'signin'            => 'Se connecter',
		'forgot'            => 'Mot de passe oublié ?',
		'forgot_send'       => 'Recevoir un lien pour choisir mon mot de passe',
		'imported_intro'    => 'Votre compte a été repris de notre ancien site. Votre ancien mot de passe fonctionne ; sinon, demandez un lien pour en choisir un nouveau.',
		'link_sent'         => 'C\'est envoyé. Ouvrez l\'e-mail reçu et suivez le lien pour choisir votre mot de passe.',
		'new_title'         => 'Bienvenue',
		'new_intro'         => 'Aucun compte avec cette adresse. Continuez : votre compte est créé à l\'instant et vous passez directement à votre demande. Vous recevrez un e-mail pour choisir votre mot de passe, à votre rythme.',
		'new_password'      => 'Choisissez un mot de passe (8 caractères minimum)',
		'register'          => 'Continuer vers ma demande',
		'welcome_subject'   => 'Bienvenue chez %s : choisissez votre mot de passe',
		'welcome_body'      => "Bonjour,\n\nVotre compte %1\$s vient d'être créé avec l'adresse %2\$s.\n\nPour choisir votre mot de passe et retrouver votre espace client (vos demandes, votre matériel, vos rapports), ouvrez ce lien :\n%3\$s\n\nCe lien est valable 24 heures. Passé ce délai, utilisez « Mot de passe oublié » sur la page de connexion.\n\nÀ bientôt,\nL'équipe %1\$s",
		'legal'             => 'En créant votre compte, vous acceptez nos <a href="%1$s">conditions générales</a> et notre <a href="%2$s">politique de confidentialité</a>.',
		'err_email'         => 'Cette adresse e-mail ne semble pas valide.',
		'err_password'      => 'Le mot de passe ne correspond pas. Réessayez, ou demandez un lien pour en choisir un nouveau.',
		'err_short'         => 'Le mot de passe doit contenir au moins 8 caractères.',
		'err_exists'        => 'Un compte existe déjà avec cette adresse. Connectez-vous.',
		'err_generic'       => 'Un problème est survenu. Réessayez dans un instant.',
		'err_ratelimit'     => 'Trop de tentatives. Patientez quelques minutes.',
		'loading'           => 'Un instant…',
	);

	return apply_filters( 'gacct_login_texts', $texts );
}

/* -----------------------------------------------------------------------------
 *  Destination après connexion
 * -------------------------------------------------------------------------- */

/**
 * Où envoyer le client une fois connecté : ticket du portier, puis redirect_to, puis Mon compte.
 *
 * @param string $redirect_to Valeur transmise par le formulaire (paramètre d'URL de la page).
 * @return string
 */
function gacct_login_redirect_url( $redirect_to = '' ) {
	$target = '';

	if ( function_exists( 'gacct_login_gate_get_ticket' ) ) {
		$ticket = gacct_login_gate_get_ticket();
		if ( $ticket ) {
			$target = $ticket;
			gacct_login_gate_clear_cookie();
		}
	}

	if ( ! $target && $redirect_to ) {
		$target = wp_validate_redirect( $redirect_to, '' );
	}

	if ( ! $target ) {
		$target = home_url( '/mon-compte/' );
	}

	return apply_filters( 'gacct_login_redirect', $target, $redirect_to );
}

/* -----------------------------------------------------------------------------
 *  Shortcode
 * -------------------------------------------------------------------------- */

add_shortcode( 'gacct_connexion', 'gacct_login_ui_shortcode' );

function gacct_login_ui_shortcode() {
	if ( is_user_logged_in() ) {
		return '<p class="gacct-login-logged"><a href="' . esc_url( home_url( '/mon-compte/' ) ) . '">Aller à mon espace client</a></p>';
	}

	gacct_login_ui_enqueue_assets();

	$t           = gacct_login_texts();
	$redirect_to = isset( $_GET['redirect_to'] ) ? (string) wp_unslash( $_GET['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$prefill     = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$cgv  = get_page_by_path( 'conditions-generales-de-vente' );
	$conf = get_page_by_path( 'politique-de-confidentialite' );
	$cgv  = $cgv ? get_permalink( $cgv ) : home_url( '/' );
	$conf = $conf ? get_permalink( $conf ) : ( function_exists( 'get_privacy_policy_url' ) && get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/' ) );

	ob_start();
	?>
	<div class="gacct-login" data-redirect="<?php echo esc_attr( $redirect_to ); ?>">

		<form class="gacct-login-step gacct-login-step--email" data-step="email" novalidate>
			<label class="gacct-login-label" for="gacct-login-email"><?php echo esc_html( $t['email_label'] ); ?></label>
			<input class="gacct-login-input" type="email" id="gacct-login-email" name="email" autocomplete="username email" inputmode="email" spellcheck="false" required placeholder="<?php echo esc_attr( $t['email_placeholder'] ); ?>" value="<?php echo esc_attr( $prefill ); ?>">
			<p class="gacct-login-hint"><?php echo esc_html( $t['email_hint'] ); ?></p>
			<button type="submit" class="gacct-login-btn"><?php echo esc_html( $t['continue'] ); ?></button>
		</form>

		<form class="gacct-login-step gacct-login-step--password" data-step="password" hidden novalidate>
			<div class="gacct-login-who">
				<p class="gacct-login-who-title" data-role="hello"><?php echo esc_html( $t['known_title'] ); ?></p>
				<p class="gacct-login-who-mail"><span data-role="email"></span> <button type="button" class="gacct-login-link" data-action="back"><?php echo esc_html( $t['change'] ); ?></button></p>
			</div>
			<p class="gacct-login-intro" data-role="intro"><?php echo esc_html( $t['known_intro'] ); ?></p>
			<label class="gacct-login-label" for="gacct-login-password"><?php echo esc_html( $t['password_label'] ); ?></label>
			<div class="gacct-login-pw">
				<input class="gacct-login-input" type="password" id="gacct-login-password" name="password" autocomplete="current-password" required>
				<button type="button" class="gacct-login-eye" aria-label="Afficher le mot de passe" data-action="eye"></button>
			</div>
			<label class="gacct-login-remember"><input type="checkbox" name="remember" value="1"> <?php echo esc_html( $t['remember'] ); ?></label>
			<button type="submit" class="gacct-login-btn"><?php echo esc_html( $t['signin'] ); ?></button>
			<p class="gacct-login-forgot"><button type="button" class="gacct-login-link" data-action="sendlink" data-label-imported="<?php echo esc_attr( $t['forgot_send'] ); ?>"><?php echo esc_html( $t['forgot'] ); ?></button></p>
		</form>

		<form class="gacct-login-step gacct-login-step--create" data-step="create" hidden novalidate>
			<div class="gacct-login-who">
				<p class="gacct-login-who-title"><?php echo esc_html( $t['new_title'] ); ?></p>
				<p class="gacct-login-who-mail"><span data-role="email"></span> <button type="button" class="gacct-login-link" data-action="back"><?php echo esc_html( $t['change'] ); ?></button></p>
			</div>
			<p class="gacct-login-intro"><?php echo esc_html( $t['new_intro'] ); ?></p>
			<button type="submit" class="gacct-login-btn"><?php echo esc_html( $t['register'] ); ?></button>
			<p class="gacct-login-legal"><?php echo wp_kses( sprintf( $t['legal'], esc_url( $cgv ), esc_url( $conf ) ), array( 'a' => array( 'href' => array() ) ) ); ?></p>
		</form>

		<p class="gacct-login-msg" role="alert" aria-live="polite" hidden></p>
	</div>
	<?php
	return ob_get_clean();
}

/* -----------------------------------------------------------------------------
 *  Assets
 * -------------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', 'gacct_login_ui_maybe_enqueue' );

function gacct_login_ui_maybe_enqueue() {
	if ( defined( 'GACCT_LOGIN_PAGE_SLUG' ) && is_page( GACCT_LOGIN_PAGE_SLUG ) && ! is_user_logged_in() ) {
		gacct_login_ui_enqueue_assets();
	}
}

function gacct_login_ui_enqueue_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	$base_url = plugins_url( '', dirname( __FILE__ ) );
	$base_dir = dirname( __DIR__ );
	$css      = $base_dir . '/assets/css/login.css';
	$js       = $base_dir . '/assets/js/login.js';

	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'gacct-login', $base_url . '/assets/css/login.css', array(), (string) filemtime( $css ) );
	}
	if ( file_exists( $js ) ) {
		wp_enqueue_script( 'gacct-login', $base_url . '/assets/js/login.js', array(), (string) filemtime( $js ), true );
		wp_localize_script( 'gacct-login', 'gacctLogin', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'gacct_login' ),
			'texts' => gacct_login_texts(),
		) );
	}
}

/* -----------------------------------------------------------------------------
 *  AJAX
 * -------------------------------------------------------------------------- */

add_action( 'wp_ajax_nopriv_gacct_login_lookup', 'gacct_login_ajax_lookup' );
add_action( 'wp_ajax_nopriv_gacct_login_signin', 'gacct_login_ajax_signin' );
add_action( 'wp_ajax_nopriv_gacct_login_register', 'gacct_login_ajax_register' );
add_action( 'wp_ajax_nopriv_gacct_login_sendlink', 'gacct_login_ajax_sendlink' );

/**
 * Garde commune : nonce + limitation par adresse IP (30 appels / 15 min).
 *
 * @return array{email:string,texts:array<string,string>}
 */
function gacct_login_ajax_guard() {
	$t = gacct_login_texts();

	if ( ! check_ajax_referer( 'gacct_login', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => $t['err_generic'] ), 403 );
	}

	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
	$key = 'gacct_login_rl_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= (int) apply_filters( 'gacct_login_rate_limit', 30 ) ) {
		wp_send_json_error( array( 'message' => $t['err_ratelimit'] ), 429 );
	}
	set_transient( $key, $n + 1, 15 * MINUTE_IN_SECONDS );

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	if ( ! $email || ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => $t['err_email'] ) );
	}

	return array( 'email' => $email, 'texts' => $t );
}

/**
 * Le compte existe-t-il ? Renvoie aussi le prénom et l'origine (compte repris de l'ancien site).
 */
function gacct_login_ajax_lookup() {
	$g    = gacct_login_ajax_guard();
	$user = get_user_by( 'email', $g['email'] );

	if ( ! $user ) {
		wp_send_json_success( array( 'exists' => false ) );
	}

	$first = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
	$first = $first ? mb_convert_case( mb_strtolower( $first ), MB_CASE_TITLE, 'UTF-8' ) : '';

	wp_send_json_success( array(
		'exists'   => true,
		'first'    => $first,
		'imported' => (bool) get_user_meta( $user->ID, '_gacct_import_source', true ),
	) );
}

/**
 * Connexion par mot de passe.
 */
function gacct_login_ajax_signin() {
	$g        = gacct_login_ajax_guard();
	$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$remember = ! empty( $_POST['remember'] );
	$redirect = isset( $_POST['redirect_to'] ) ? (string) wp_unslash( $_POST['redirect_to'] ) : '';

	if ( '' === $password ) {
		wp_send_json_error( array( 'message' => $g['texts']['err_password'] ) );
	}

	$user = wp_signon( array(
		'user_login'    => $g['email'],
		'user_password' => $password,
		'remember'      => $remember,
	), is_ssl() );

	if ( is_wp_error( $user ) ) {
		wp_send_json_error( array( 'message' => $g['texts']['err_password'] ) );
	}

	wp_set_current_user( $user->ID );

	wp_send_json_success( array( 'redirect' => gacct_login_redirect_url( $redirect ) ) );
}

/**
 * Création de compte (e-mail + mot de passe), connexion immédiate.
 */
function gacct_login_ajax_register() {
	$g        = gacct_login_ajax_guard();
	$redirect = isset( $_POST['redirect_to'] ) ? (string) wp_unslash( $_POST['redirect_to'] ) : '';

	if ( email_exists( $g['email'] ) ) {
		wp_send_json_error( array( 'message' => $g['texts']['err_exists'], 'exists' => true ) );
	}

	// Sans mot de passe (11/09/2026, demande Bastien) : le compte est créé avec un
	// mot de passe aléatoire, le client passe tout de suite à sa demande et reçoit
	// un e-mail avec le lien « choisir mon mot de passe » (valable 24 h).
	$password = wp_generate_password( 24, true, true );

	// L'e-mail WooCommerce « nouveau compte » est remplacé par le nôtre (lien de mot de passe).
	add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );

	if ( function_exists( 'wc_create_new_customer' ) ) {
		$user_id = wc_create_new_customer( $g['email'], '', $password );
	} else {
		$user_id = wp_create_user( $g['email'], $password, $g['email'] );
		if ( ! is_wp_error( $user_id ) ) {
			wp_update_user( array( 'ID' => $user_id, 'role' => 'customer' ) );
		}
	}

	if ( is_wp_error( $user_id ) ) {
		wp_send_json_error( array( 'message' => $g['texts']['err_generic'] ) );
	}

	update_user_meta( $user_id, '_gacct_signup_source', 'login_ui' );

	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true, is_ssl() );
	$user = get_user_by( 'id', $user_id );
	do_action( 'wp_login', $user->user_login, $user );

	gacct_login_send_welcome( $user );

	// Nouveau client : direction la demande d'intervention (sauf ticket du portier ou redirect_to).
	$target = gacct_login_redirect_url( $redirect );
	if ( home_url( '/mon-compte/' ) === $target ) {
		$page   = defined( 'GACCT_GATED_PAGE_SLUG' ) ? get_page_by_path( GACCT_GATED_PAGE_SLUG ) : null;
		$target = $page ? get_permalink( $page ) : $target;
	}

	wp_send_json_success( array( 'redirect' => $target ) );
}

/**
 * E-mail de bienvenue : lien « choisir mon mot de passe » vers la page front
 * (mot-de-passe-oublie?key=…&login=…, cf. gacct_lostpassword_reset_cookie).
 * Texte brut, filtrable (`gacct_login_texts` : welcome_subject / welcome_body).
 *
 * @param WP_User $user
 * @return bool
 */
function gacct_login_send_welcome( $user ) {
	if ( ! $user instanceof WP_User ) {
		return false;
	}

	$key = get_password_reset_key( $user );
	if ( is_wp_error( $key ) ) {
		return false;
	}

	$link = function_exists( 'gacct_lostpassword_page_url' )
		? gacct_lostpassword_page_url( array( 'key' => $key, 'login' => rawurlencode( $user->user_login ) ) )
		: network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );

	$t    = gacct_login_texts();
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

	$subject = sprintf( $t['welcome_subject'], $site );
	$body    = sprintf( $t['welcome_body'], $site, $user->user_email, $link );

	return (bool) wp_mail( $user->user_email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
}

/**
 * Envoi du lien « choisir mon mot de passe » (réinitialisation WordPress, page front via gacct-login.php).
 * Réponse identique que le compte existe ou non.
 */
function gacct_login_ajax_sendlink() {
	$g = gacct_login_ajax_guard();

	if ( function_exists( 'retrieve_password' ) ) {
		retrieve_password( $g['email'] );
	}

	wp_send_json_success( array( 'message' => $g['texts']['link_sent'] ) );
}
