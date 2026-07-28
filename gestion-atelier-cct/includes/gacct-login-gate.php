<?php
/**
 * Portier de connexion sur la page "demande d'intervention".
 *
 * La page publique demande-intervention (ID 467) accepte des parametres
 * d'URL (ex. ?svc=17) qui preselectionnent des prestations dans le
 * formulaire JFB (voir jwcct_preselect_service_from_url dans
 * gacct-frontend.php). Or le formulaire exige un utilisateur connecte pour
 * enregistrer une revision. On force donc la connexion en amont, tout en
 * conservant l'URL demandee (query string comprise) pour y revenir apres
 * connexion — via un cookie "ticket de retour", plutot que via un parametre
 * ?redirect_to qui serait perdu au fil des redirections internes du
 * formulaire de login JFB 592 (qui redirige lui-meme statiquement vers 467).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nom du cookie "ticket de retour".
 */
define( 'GACCT_RETURN_COOKIE', 'gacct_return_to' );

/**
 * Duree de vie du ticket de retour (1 heure).
 */
define( 'GACCT_RETURN_TTL', HOUR_IN_SECONDS );

/**
 * Slug de la page a proteger (formulaire de demande d'intervention).
 */
define( 'GACCT_GATED_PAGE_SLUG', 'demande-intervention' );

/**
 * Slug de la page de connexion dediee.
 */
define( 'GACCT_LOGIN_PAGE_SLUG', 'login-demande-intervention' );

/**
 * Portier + retour, brancher sur template_redirect (apres que $wp->query_vars
 * et is_page() soient disponibles, avant tout rendu de template).
 *
 * Ordre des verifications :
 *  1. Utilisateur connecte qui arrive sur la page de login -> on l'envoie
 *     directement vers son ticket (ou vers la page protegee a defaut).
 *  2. Utilisateur non connecte qui demande la page protegee -> on memorise
 *     l'URL complete puis on redirige vers la page de login.
 *  3. Utilisateur connecte qui a un ticket en cookie (quelle que soit la
 *     page chargee, au cas ou il navigue ailleurs entre-temps) -> on le
 *     renvoie vers le ticket et on efface le cookie, sauf si le ticket pointe
 *     deja vers la page courante (evite une boucle de redirection).
 */
add_action( 'template_redirect', 'gacct_login_gate_template_redirect' );

function gacct_login_gate_template_redirect() {

	// Etape 1 : connecte + sur la page de login -> jamais y rester.
	if ( is_page( GACCT_LOGIN_PAGE_SLUG ) && is_user_logged_in() ) {
		$ticket = gacct_login_gate_get_ticket();
		gacct_login_gate_clear_cookie();

		$fallback = get_permalink( get_page_by_path( GACCT_GATED_PAGE_SLUG ) );
		$target   = $ticket ? $ticket : $fallback;

		if ( $target ) {
			wp_safe_redirect( $target );
			exit;
		}
		return;
	}

	// Etape 2 : non connecte + page protegee -> memoriser puis rediriger vers le login.
	if ( is_page( GACCT_GATED_PAGE_SLUG ) && ! is_user_logged_in() ) {
		$requested_url = home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ) );

		gacct_login_gate_set_cookie( $requested_url );

		$login_page = get_page_by_path( GACCT_LOGIN_PAGE_SLUG );
		if ( $login_page ) {
			wp_safe_redirect( get_permalink( $login_page ) );
			exit;
		}
		return;
	}

	// Etape 3 : connecte + ticket en cookie, sur n'importe quelle autre page.
	if ( is_user_logged_in() ) {
		$ticket = gacct_login_gate_get_ticket();
		if ( ! $ticket ) {
			return;
		}

		$current_url = home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ) );

		// Deja sur la cible (query string comprise) : le retour est termine,
		// on nettoie sans rediriger pour eviter toute boucle.
		if ( untrailingslashit( $ticket ) === untrailingslashit( $current_url ) ) {
			gacct_login_gate_clear_cookie();
			return;
		}

		gacct_login_gate_clear_cookie();
		wp_safe_redirect( $ticket );
		exit;
	}
}

/**
 * Pose le cookie "ticket de retour".
 *
 * httponly + secure : ce cookie n'a pas vocation a etre lu en JS, uniquement
 * relu par PHP au chargement de page suivant.
 *
 * @param string $url URL complete (avec query string) a memoriser.
 */
function gacct_login_gate_set_cookie( $url ) {
	$validated = wp_validate_redirect( $url, false );
	if ( ! $validated ) {
		return;
	}

	setcookie(
		GACCT_RETURN_COOKIE,
		$validated,
		array(
			'expires'  => time() + GACCT_RETURN_TTL,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);

	// Rendre la valeur disponible immediatement dans la meme requete
	// (utile si un autre hook template_redirect s'execute apres celui-ci).
	$_COOKIE[ GACCT_RETURN_COOKIE ] = $validated;
}

/**
 * Lit et valide le ticket de retour courant.
 *
 * @return string|false URL validee, ou false si absente/invalide.
 */
function gacct_login_gate_get_ticket() {
	if ( empty( $_COOKIE[ GACCT_RETURN_COOKIE ] ) ) {
		return false;
	}

	$raw = wp_unslash( $_COOKIE[ GACCT_RETURN_COOKIE ] );

	// wp_validate_redirect() protege contre les tickets manipules pointant
	// vers un domaine externe (open redirect) ; en repli on ne fait rien.
	return wp_validate_redirect( $raw, false );
}

/**
 * Efface le cookie ticket de retour.
 */
function gacct_login_gate_clear_cookie() {
	if ( isset( $_COOKIE[ GACCT_RETURN_COOKIE ] ) ) {
		unset( $_COOKIE[ GACCT_RETURN_COOKIE ] );
	}

	setcookie(
		GACCT_RETURN_COOKIE,
		'',
		array(
			'expires'  => time() - HOUR_IN_SECONDS,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
}
