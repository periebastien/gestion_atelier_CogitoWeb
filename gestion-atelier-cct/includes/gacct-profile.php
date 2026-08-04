<?php
/**
 * Page « Mon profil » de l'espace client (/mon-compte/mon-profil/).
 *
 * Onglet du Profile Builder JetEngine (comme le tableau de bord ou « Mon
 * Matériel ») : un template Elementor qui ne contient qu'un widget shortcode
 * `[gacct_profil]`. Le markup vit dans templates/profile.php, le style dans
 * assets/css/profile.css. Cf. CDC-mon-profil.md.
 *
 * Ce que la page permet :
 *   - identité : photo de profil, prénom, nom, téléphone (billing_phone) ;
 *   - adresse e-mail du compte, en DOUBLE CONFIRMATION (lien à usage unique
 *     envoyé à la nouvelle adresse, alerte de sécurité envoyée à l'ancienne) ;
 *   - mot de passe ;
 *   - sécurité : méthode de connexion, déconnexion des autres appareils.
 *
 * Ce que la page ne fait PAS : les adresses postales. L'onglet « Adresse de
 * livraison » (template 518) rend déjà `jet-myaccount-addresses`, qui couvre
 * livraison ET facturation — on ne duplique pas cette source de vérité.
 *
 * Écritures : toujours sur `get_current_user_id()`, jamais sur un id reçu en
 * entrée. Chaque action = nonce + utilisateur connecté, puis PRG (redirection
 * avec `?gacct_profile_notice=`) pour qu'un F5 ne rejoue rien.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** User meta : pièce jointe de la photo déposée par le client lui-même. */
const GACCT_PROFILE_AVATAR_META = 'gacct_avatar_id';

/** User meta : demande de changement d'e-mail en attente (tableau). */
const GACCT_PROFILE_PENDING_EMAIL_META = '_gacct_pending_email';

/** Durée de validité du lien de confirmation d'e-mail, en secondes. */
const GACCT_PROFILE_EMAIL_TTL = DAY_IN_SECONDS;

/** Taille maximale d'une photo de profil, en octets. */
const GACCT_PROFILE_AVATAR_MAX_BYTES = 4194304; // 4 Mo.

/* =============================================================================
 *  IDENTITÉ DE LA PAGE
 * ============================================================================= */

/**
 * Slug de la sous-page « Mon profil » du Profile Builder.
 *
 * @return string
 */
function gacct_profile_slug() {
	return (string) apply_filters( 'gacct_profile_slug', 'mon-profil' );
}

/**
 * URL absolue de la page « Mon profil ».
 *
 * @return string
 */
function gacct_profile_url() {
	$slug = gacct_profile_slug();

	$url = function_exists( 'jwcct_get_compte_subpage_url' )
		? jwcct_get_compte_subpage_url( $slug )
		: home_url( '/mon-compte/' . $slug );

	return trailingslashit( $url );
}

/**
 * Sommes-nous en train d'afficher la page « Mon profil » ?
 *
 * @return bool
 */
function gacct_profile_is_page() {
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	$slug = gacct_profile_slug();

	return (bool) preg_match( '#/' . preg_quote( $slug, '#' ) . '/?$#', $path );
}

/* =============================================================================
 *  TEXTES (white-label)
 * ============================================================================= */

/**
 * Tous les libellés de la page, en un seul endroit.
 *
 * @return array<string,string>
 */
function gacct_profile_texts() {
	static $texts = null;

	if ( null !== $texts ) {
		return $texts;
	}

	$defaults = array(

		// --- Carte identité --------------------------------------------------
		'identity_title'   => __( 'Mes informations', 'gestion-atelier-cct' ),
		'identity_intro'   => __( 'Ces informations apparaissent sur vos documents et permettent à l’atelier de vous joindre.', 'gestion-atelier-cct' ),
		'photo_label'      => __( 'Photo de profil', 'gestion-atelier-cct' ),
		'photo_hint'       => __( 'JPG, PNG ou WebP — 4 Mo maximum.', 'gestion-atelier-cct' ),
		'photo_choose'     => __( 'Choisir une photo', 'gestion-atelier-cct' ),
		'photo_remove'     => __( 'Retirer la photo', 'gestion-atelier-cct' ),
		'first_name'       => __( 'Prénom', 'gestion-atelier-cct' ),
		'last_name'        => __( 'Nom', 'gestion-atelier-cct' ),
		'phone'            => __( 'Téléphone', 'gestion-atelier-cct' ),
		'phone_hint'       => __( 'C’est le numéro que l’atelier compose en cas de question sur votre matériel. Il sert aussi d’adresse de facturation.', 'gestion-atelier-cct' ),
		'identity_save'    => __( 'Enregistrer', 'gestion-atelier-cct' ),

		// --- Carte e-mail ----------------------------------------------------
		'email_title'      => __( 'Adresse e-mail du compte', 'gestion-atelier-cct' ),
		'email_intro'      => __( 'C’est l’adresse qui reçoit vos suivis d’intervention, vos rapports et vos factures. C’est aussi votre identifiant de connexion.', 'gestion-atelier-cct' ),
		'email_current'    => __( 'Adresse actuelle', 'gestion-atelier-cct' ),
		'email_new'        => __( 'Nouvelle adresse e-mail', 'gestion-atelier-cct' ),
		'email_password'   => __( 'Votre mot de passe actuel', 'gestion-atelier-cct' ),
		'email_submit'     => __( 'Demander le changement', 'gestion-atelier-cct' ),
		'email_toggle'     => __( 'Changer d’adresse e-mail', 'gestion-atelier-cct' ),
		'email_note'       => __( 'Par sécurité, un lien de confirmation sera envoyé à la nouvelle adresse : le changement ne prend effet qu’une fois ce lien ouvert.', 'gestion-atelier-cct' ),
		/* translators: %s: adresse e-mail en attente de confirmation */
		'email_pending'    => __( 'Changement en attente vers %s.', 'gestion-atelier-cct' ),
		/* translators: %s: date et heure limite */
		'email_pending_exp' => __( 'Le lien de confirmation envoyé à cette adresse reste valable jusqu’au %s.', 'gestion-atelier-cct' ),
		'email_resend'     => __( 'Renvoyer le lien', 'gestion-atelier-cct' ),
		'email_cancel'     => __( 'Annuler la demande', 'gestion-atelier-cct' ),

		// --- Carte mot de passe ----------------------------------------------
		'password_title'   => __( 'Mot de passe', 'gestion-atelier-cct' ),
		'password_intro'   => __( 'Choisissez un mot de passe d’au moins 8 caractères, différent de ceux que vous utilisez ailleurs.', 'gestion-atelier-cct' ),
		'password_toggle'  => __( 'Changer de mot de passe', 'gestion-atelier-cct' ),
		'password_current' => __( 'Mot de passe actuel', 'gestion-atelier-cct' ),
		'password_new'     => __( 'Nouveau mot de passe', 'gestion-atelier-cct' ),
		'password_repeat'  => __( 'Confirmer le nouveau mot de passe', 'gestion-atelier-cct' ),
		'password_submit'  => __( 'Mettre à jour le mot de passe', 'gestion-atelier-cct' ),
		'password_lost'    => __( 'Mot de passe oublié ?', 'gestion-atelier-cct' ),

		// --- Carte sécurité ---------------------------------------------------
		'security_title'   => __( 'Connexion et sécurité', 'gestion-atelier-cct' ),
		'login_method'     => __( 'Méthode de connexion', 'gestion-atelier-cct' ),
		'login_password'   => __( 'Adresse e-mail et mot de passe', 'gestion-atelier-cct' ),
		'login_social'     => __( 'Compte social lié', 'gestion-atelier-cct' ),
		'social_note'      => __( 'Votre compte a été créé via une connexion sociale. Pour modifier votre adresse e-mail ou votre mot de passe ici, définissez d’abord un mot de passe avec le lien « Mot de passe oublié ».', 'gestion-atelier-cct' ),
		/* translators: %d: nombre de sessions actives */
		'sessions_count'   => __( '%d appareil connecté à votre compte.', 'gestion-atelier-cct' ),
		/* translators: %d: nombre de sessions actives */
		'sessions_count_p' => __( '%d appareils connectés à votre compte.', 'gestion-atelier-cct' ),
		'sessions_submit'  => __( 'Déconnecter les autres appareils', 'gestion-atelier-cct' ),
		'sessions_hint'    => __( 'Vous resterez connecté sur cet appareil.', 'gestion-atelier-cct' ),

		// --- Notices ----------------------------------------------------------
		'ok_identity'      => __( 'Vos informations ont été enregistrées.', 'gestion-atelier-cct' ),
		'ok_avatar_removed' => __( 'Votre photo de profil a été retirée.', 'gestion-atelier-cct' ),
		/* translators: %s: nouvelle adresse e-mail */
		'ok_email_sent'    => __( 'Un lien de confirmation vient d’être envoyé à %s. Ouvrez-le pour valider le changement.', 'gestion-atelier-cct' ),
		'ok_email_cancel'  => __( 'La demande de changement d’adresse a été annulée.', 'gestion-atelier-cct' ),
		'ok_email_changed' => __( 'Votre adresse e-mail a bien été mise à jour.', 'gestion-atelier-cct' ),
		'ok_password'      => __( 'Votre mot de passe a été mis à jour.', 'gestion-atelier-cct' ),
		'ok_sessions'      => __( 'Les autres appareils ont été déconnectés.', 'gestion-atelier-cct' ),
		'err_generic'      => __( 'La modification n’a pas pu être enregistrée. Merci de réessayer.', 'gestion-atelier-cct' ),
		'err_password'     => __( 'Le mot de passe actuel est incorrect.', 'gestion-atelier-cct' ),
		'err_password_weak' => __( 'Le nouveau mot de passe doit comporter au moins 8 caractères.', 'gestion-atelier-cct' ),
		'err_password_match' => __( 'Les deux mots de passe saisis ne sont pas identiques.', 'gestion-atelier-cct' ),
		'err_email_invalid' => __( 'Cette adresse e-mail n’est pas valide.', 'gestion-atelier-cct' ),
		'err_email_same'   => __( 'C’est déjà l’adresse de votre compte.', 'gestion-atelier-cct' ),
		'err_email_used'   => __( 'Cette adresse ne peut pas être utilisée pour ce compte.', 'gestion-atelier-cct' ),
		'err_email_send'   => __( 'L’e-mail de confirmation n’a pas pu être envoyé. Merci de réessayer dans quelques minutes.', 'gestion-atelier-cct' ),
		'err_link_expired' => __( 'Ce lien de confirmation n’est plus valide. Relancez la demande depuis votre profil.', 'gestion-atelier-cct' ),
		'err_avatar_type'  => __( 'Format d’image non accepté : utilisez un JPG, un PNG ou un WebP.', 'gestion-atelier-cct' ),
		'err_avatar_size'  => __( 'L’image dépasse 4 Mo.', 'gestion-atelier-cct' ),
		'err_avatar_upload' => __( 'L’image n’a pas pu être enregistrée.', 'gestion-atelier-cct' ),
	);

	$texts = (array) apply_filters( 'gacct_profile_texts', $defaults );

	return $texts;
}

/**
 * Un texte de la page, avec repli sur la chaîne vide.
 *
 * @param string $key Clé de `gacct_profile_texts()`.
 * @return string
 */
function gacct_profile_text( $key ) {
	$texts = gacct_profile_texts();

	return isset( $texts[ $key ] ) ? (string) $texts[ $key ] : '';
}

/* =============================================================================
 *  DONNÉES
 * ============================================================================= */

/**
 * Toute la donnée de la page profil, pour un utilisateur.
 *
 * @param int $user_id Utilisateur (0 = utilisateur courant).
 * @return array<string,mixed>
 */
function gacct_profile_data( $user_id = 0 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$user    = $user_id ? get_userdata( $user_id ) : false;

	if ( ! $user ) {
		return array( 'user_id' => 0 );
	}

	$pending = gacct_profile_pending_email( $user_id );

	$data = array(
		'user_id'      => $user_id,
		'first_name'   => (string) $user->first_name,
		'last_name'    => (string) $user->last_name,
		'display_name' => (string) $user->display_name,
		'email'        => (string) $user->user_email,
		'phone'        => (string) get_user_meta( $user_id, 'billing_phone', true ),
		'avatar_url'   => function_exists( 'gacct_dash_avatar_url' ) ? gacct_dash_avatar_url( $user_id, 160 ) : null,
		'initials'     => function_exists( 'gacct_dash_initials' ) ? gacct_dash_initials( $user_id ) : '',
		'has_photo'    => (bool) gacct_profile_avatar_id( $user_id ),
		'is_social'    => gacct_profile_is_social( $user_id ),
		'sessions'     => gacct_profile_session_count( $user_id ),
		'pending'      => $pending,
		'lost_password' => gacct_profile_lost_password_url(),
		'action_url'   => gacct_profile_url(),
	);

	return (array) apply_filters( 'gacct_profile_data', $data, $user_id );
}

/**
 * Lien « mot de passe oublié ».
 *
 * ⚠ Pas `wp_lostpassword_url()` : WooCommerce le redirige vers l'endpoint
 * `/mon-compte/lost-password/`, qui est en 404 sur ce site (le Profile Builder
 * JetEngine avale `/mon-compte/{slug}` avec ses propres slugs). On repasse donc
 * par `wp-login.php`, qui répond bien.
 *
 * @return string
 */
function gacct_profile_lost_password_url() {
	$url = add_query_arg(
		array(
			'action'      => 'lostpassword',
			'redirect_to' => rawurlencode( gacct_profile_url() ),
		),
		wp_login_url()
	);

	return (string) apply_filters( 'gacct_profile_lost_password_url', $url );
}

/**
 * Pièce jointe de la photo déposée par le client (0 si aucune).
 *
 * @param int $user_id Utilisateur.
 * @return int
 */
function gacct_profile_avatar_id( $user_id ) {
	return (int) get_user_meta( absint( $user_id ), GACCT_PROFILE_AVATAR_META, true );
}

/**
 * Le compte est-il lié à une connexion sociale (Nextend) ?
 *
 * @param int $user_id Utilisateur.
 * @return bool
 */
function gacct_profile_is_social( $user_id ) {
	$user_id = absint( $user_id );
	$linked  = (bool) get_user_meta( $user_id, 'nsl_user_avatar_md5', true );

	return (bool) apply_filters( 'gacct_profile_is_social', $linked, $user_id );
}

/**
 * Nombre de sessions actives de l'utilisateur.
 *
 * @param int $user_id Utilisateur.
 * @return int
 */
function gacct_profile_session_count( $user_id ) {
	if ( ! class_exists( 'WP_Session_Tokens' ) ) {
		return 1;
	}

	$tokens = WP_Session_Tokens::get_instance( absint( $user_id ) );

	// `get_all()` n'est pas exposée par l'API publique : on passe par la meta.
	$sessions = get_user_meta( absint( $user_id ), 'session_tokens', true );

	unset( $tokens );

	return is_array( $sessions ) ? max( 1, count( $sessions ) ) : 1;
}

/**
 * Demande de changement d'e-mail en attente, si elle est encore valable.
 *
 * @param int $user_id Utilisateur.
 * @return array{email:string,expires:int}|null
 */
function gacct_profile_pending_email( $user_id ) {
	$pending = get_user_meta( absint( $user_id ), GACCT_PROFILE_PENDING_EMAIL_META, true );

	if ( ! is_array( $pending ) || empty( $pending['email'] ) || empty( $pending['created'] ) ) {
		return null;
	}

	$expires = (int) $pending['created'] + GACCT_PROFILE_EMAIL_TTL;

	if ( $expires < time() ) {
		delete_user_meta( absint( $user_id ), GACCT_PROFILE_PENDING_EMAIL_META );
		return null;
	}

	return array(
		'email'   => (string) $pending['email'],
		'expires' => $expires,
	);
}

/* =============================================================================
 *  RENDU
 * ============================================================================= */

add_shortcode( 'gacct_profil', 'gacct_profile_shortcode' );

/**
 * Shortcode `[gacct_profil]` : rend la page profil de l'utilisateur connecté.
 *
 * @return string HTML.
 */
function gacct_profile_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '';
	}

	$data   = gacct_profile_data();
	$texts  = gacct_profile_texts();
	$notice = gacct_profile_current_notice();

	if ( empty( $data['user_id'] ) ) {
		return '';
	}

	ob_start();
	include dirname( __DIR__ ) . '/templates/profile.php';

	return (string) ob_get_clean();
}

/**
 * Notice à afficher, déduite de `?gacct_profile_notice=`.
 *
 * @return array{type:string,message:string}|null
 */
function gacct_profile_current_notice() {
	$code = isset( $_GET['gacct_profile_notice'] ) ? sanitize_key( wp_unslash( $_GET['gacct_profile_notice'] ) ) : '';

	if ( '' === $code ) {
		return null;
	}

	$arg = isset( $_GET['gacct_profile_arg'] ) ? sanitize_text_field( wp_unslash( $_GET['gacct_profile_arg'] ) ) : '';

	$text = gacct_profile_text( $code );

	if ( '' === $text ) {
		return null;
	}

	if ( false !== strpos( $text, '%s' ) ) {
		$text = sprintf( $text, $arg );
	}

	return array(
		'type'    => ( 0 === strpos( $code, 'err_' ) ) ? 'error' : 'success',
		'message' => $text,
	);
}

add_action( 'wp_enqueue_scripts', 'gacct_profile_enqueue_assets' );

/**
 * Feuille et script de la page profil, uniquement sur cette page.
 */
function gacct_profile_enqueue_assets() {
	if ( ! is_user_logged_in() || ! gacct_profile_is_page() ) {
		return;
	}

	$base_url = plugins_url( '', dirname( __FILE__ ) );
	$base_dir = dirname( __DIR__ );
	$css      = $base_dir . '/assets/css/profile.css';
	$js       = $base_dir . '/assets/js/profile.js';

	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'gacct-profile', $base_url . '/assets/css/profile.css', array(), (string) filemtime( $css ) );
	}

	if ( file_exists( $js ) ) {
		wp_enqueue_script( 'gacct-profile', $base_url . '/assets/js/profile.js', array(), (string) filemtime( $js ), true );
	}
}

/* =============================================================================
 *  TRAITEMENT DES FORMULAIRES (PRG)
 * ============================================================================= */

add_action( 'template_redirect', 'gacct_profile_handle_post', 5 );

/**
 * Intercepte les POST de la page profil avant tout rendu.
 *
 * Chaque action : utilisateur connecté + nonce, écriture sur l'utilisateur
 * courant UNIQUEMENT, puis redirection (PRG) avec un code de notice.
 */
function gacct_profile_handle_post() {
	if ( empty( $_POST['gacct_profile_action'] ) ) {
		return;
	}

	$action = sanitize_key( wp_unslash( $_POST['gacct_profile_action'] ) );

	if ( ! is_user_logged_in() ) {
		gacct_profile_redirect( 'err_generic' );
	}

	check_admin_referer( 'gacct_profile', 'gacct_profile_nonce' );

	$user_id = get_current_user_id();

	switch ( $action ) {
		case 'identity':
			gacct_profile_save_identity( $user_id );
			break;

		case 'email':
			gacct_profile_request_email_change( $user_id );
			break;

		case 'email_cancel':
			delete_user_meta( $user_id, GACCT_PROFILE_PENDING_EMAIL_META );
			gacct_profile_redirect( 'ok_email_cancel' );
			break;

		case 'password':
			gacct_profile_change_password( $user_id );
			break;

		case 'sessions':
			wp_destroy_other_sessions();
			gacct_profile_redirect( 'ok_sessions' );
			break;

		default:
			gacct_profile_redirect( 'err_generic' );
	}
}

/**
 * Redirige vers la page profil avec un code de notice, puis sort.
 *
 * @param string $code Clé de `gacct_profile_texts()`.
 * @param string $arg  Valeur injectée dans un éventuel `%s`.
 */
function gacct_profile_redirect( $code, $arg = '' ) {
	$args = array( 'gacct_profile_notice' => sanitize_key( $code ) );

	if ( '' !== $arg ) {
		$args['gacct_profile_arg'] = rawurlencode( $arg );
	}

	wp_safe_redirect( add_query_arg( $args, gacct_profile_url() ) );
	exit;
}

/**
 * Carte « Mes informations » : photo, prénom, nom, téléphone.
 *
 * @param int $user_id Utilisateur courant.
 */
function gacct_profile_save_identity( $user_id ) {
	$first = isset( $_POST['gacct_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['gacct_first_name'] ) ) : '';
	$last  = isset( $_POST['gacct_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['gacct_last_name'] ) ) : '';
	$phone = isset( $_POST['gacct_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['gacct_phone'] ) ) : '';

	// Photo : suppression demandée ?
	if ( ! empty( $_POST['gacct_remove_avatar'] ) ) {
		gacct_profile_delete_avatar( $user_id );
	}

	// Photo : nouveau dépôt ?
	if ( ! empty( $_FILES['gacct_avatar']['name'] ) ) {
		$uploaded = gacct_profile_handle_avatar_upload( $user_id );

		if ( is_wp_error( $uploaded ) ) {
			gacct_profile_redirect( $uploaded->get_error_code() );
		}
	}

	$display = trim( $first . ' ' . $last );

	$result = wp_update_user( array(
		'ID'           => $user_id,
		'first_name'   => $first,
		'last_name'    => $last,
		'display_name' => '' !== $display ? $display : null,
	) );

	if ( is_wp_error( $result ) ) {
		gacct_profile_redirect( 'err_generic' );
	}

	update_user_meta( $user_id, 'billing_phone', $phone );

	do_action( 'gacct_profile_identity_saved', $user_id );

	gacct_profile_redirect( 'ok_identity' );
}

/* =============================================================================
 *  PHOTO DE PROFIL
 * ============================================================================= */

/**
 * Enregistre la photo déposée et la rattache à l'utilisateur.
 *
 * @param int $user_id Utilisateur courant.
 * @return int|WP_Error Id de la pièce jointe, ou une erreur dont le CODE est
 *                      une clé de `gacct_profile_texts()`.
 */
function gacct_profile_handle_avatar_upload( $user_id ) {
	$file = $_FILES['gacct_avatar']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( ! empty( $file['error'] ) ) {
		return new WP_Error( 'err_avatar_upload' );
	}

	if ( (int) $file['size'] > GACCT_PROFILE_AVATAR_MAX_BYTES ) {
		return new WP_Error( 'err_avatar_size' );
	}

	$allowed = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);

	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed );

	if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
		return new WP_Error( 'err_avatar_type' );
	}

	// Ceinture et bretelles : le fichier doit vraiment être une image.
	$size = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	if ( empty( $size[0] ) || empty( $size[1] ) ) {
		return new WP_Error( 'err_avatar_type' );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	add_filter( 'upload_mimes', 'gacct_profile_avatar_mimes' );
	$attachment_id = media_handle_upload( 'gacct_avatar', 0 );
	remove_filter( 'upload_mimes', 'gacct_profile_avatar_mimes' );

	if ( is_wp_error( $attachment_id ) ) {
		return new WP_Error( 'err_avatar_upload' );
	}

	// L'ancienne photo déposée n'a plus de raison d'exister.
	gacct_profile_delete_avatar( $user_id );

	update_post_meta( $attachment_id, '_gacct_avatar_user', $user_id );
	update_user_meta( $user_id, GACCT_PROFILE_AVATAR_META, (int) $attachment_id );

	return (int) $attachment_id;
}

/**
 * Types d'image acceptés le temps du dépôt d'une photo de profil.
 *
 * @param array $mimes Types autorisés.
 * @return array
 */
function gacct_profile_avatar_mimes( $mimes ) {
	return array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);
}

/**
 * Supprime la photo déposée par le client (fichier + meta).
 *
 * @param int $user_id Utilisateur.
 */
function gacct_profile_delete_avatar( $user_id ) {
	$attachment_id = gacct_profile_avatar_id( $user_id );

	if ( $attachment_id && (int) get_post_meta( $attachment_id, '_gacct_avatar_user', true ) === (int) $user_id ) {
		wp_delete_attachment( $attachment_id, true );
	}

	delete_user_meta( $user_id, GACCT_PROFILE_AVATAR_META );
}

add_filter( 'gacct_dashboard_avatar_url', 'gacct_profile_filter_dashboard_avatar', 10, 3 );

/**
 * Cascade d'affichage : photo déposée ici > photo Nextend > initiales.
 *
 * @param string|null $url     URL calculée par le tableau de bord.
 * @param int         $user_id Utilisateur.
 * @param int         $size    Taille demandée.
 * @return string|null
 */
function gacct_profile_filter_dashboard_avatar( $url, $user_id, $size ) {
	$attachment_id = gacct_profile_avatar_id( $user_id );

	if ( ! $attachment_id ) {
		return $url;
	}

	$src = wp_get_attachment_image_src( $attachment_id, array( absint( $size ), absint( $size ) ) );

	return ! empty( $src[0] ) ? (string) $src[0] : $url;
}

add_filter( 'pre_get_avatar_data', 'gacct_profile_filter_avatar_data', 20, 2 );

/**
 * Fait aussi remonter la photo déposée dans `get_avatar()` / `get_avatar_url()`,
 * pour que tout le site (JetEngine, WooCommerce…) l'affiche.
 *
 * @param array $args        Arguments de l'avatar.
 * @param mixed $id_or_email Utilisateur, e-mail ou objet.
 * @return array
 */
function gacct_profile_filter_avatar_data( $args, $id_or_email ) {
	$user_id = 0;

	if ( is_numeric( $id_or_email ) ) {
		$user_id = (int) $id_or_email;
	} elseif ( $id_or_email instanceof WP_User ) {
		$user_id = (int) $id_or_email->ID;
	} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user    = get_user_by( 'email', $id_or_email );
		$user_id = $user ? (int) $user->ID : 0;
	} elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
		$user_id = (int) $id_or_email->user_id;
	}

	if ( ! $user_id ) {
		return $args;
	}

	$attachment_id = gacct_profile_avatar_id( $user_id );

	if ( ! $attachment_id ) {
		return $args;
	}

	$size = ! empty( $args['size'] ) ? absint( $args['size'] ) : 96;
	$src  = wp_get_attachment_image_src( $attachment_id, array( $size, $size ) );

	if ( ! empty( $src[0] ) ) {
		$args['url']          = $src[0];
		$args['found_avatar'] = true;
	}

	return $args;
}

/* =============================================================================
 *  MOT DE PASSE
 * ============================================================================= */

/**
 * Carte « Mot de passe » : vérifie l'actuel, pose le nouveau, garde la session.
 *
 * @param int $user_id Utilisateur courant.
 */
function gacct_profile_change_password( $user_id ) {
	$user = get_userdata( $user_id );

	$current = isset( $_POST['gacct_password_current'] ) ? (string) wp_unslash( $_POST['gacct_password_current'] ) : '';
	$new     = isset( $_POST['gacct_password_new'] ) ? (string) wp_unslash( $_POST['gacct_password_new'] ) : '';
	$repeat  = isset( $_POST['gacct_password_repeat'] ) ? (string) wp_unslash( $_POST['gacct_password_repeat'] ) : '';

	if ( ! $user || ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
		gacct_profile_redirect( 'err_password' );
	}

	if ( strlen( $new ) < 8 ) {
		gacct_profile_redirect( 'err_password_weak' );
	}

	if ( $new !== $repeat ) {
		gacct_profile_redirect( 'err_password_match' );
	}

	wp_set_password( $new, $user_id );

	// `wp_set_password()` détruit toutes les sessions : on remet celle-ci.
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true );

	gacct_profile_send_email(
		$user->user_email,
		gacct_profile_email_subject( 'password_changed' ),
		gacct_profile_email_body( 'password_changed', $user, array() )
	);

	do_action( 'gacct_profile_password_changed', $user_id );

	gacct_profile_redirect( 'ok_password' );
}

/* =============================================================================
 *  CHANGEMENT D'ADRESSE E-MAIL (double confirmation)
 * ============================================================================= */

/**
 * Enregistre la demande et envoie le lien de confirmation à la NOUVELLE adresse,
 * plus une alerte de sécurité à l'ANCIENNE.
 *
 * @param int $user_id Utilisateur courant.
 */
function gacct_profile_request_email_change( $user_id ) {
	$user = get_userdata( $user_id );

	// Renvoi du lien : on reprend l'adresse déjà en attente.
	$resend = ! empty( $_POST['gacct_email_resend'] );

	if ( $resend ) {
		$pending = gacct_profile_pending_email( $user_id );

		if ( ! $pending ) {
			gacct_profile_redirect( 'err_link_expired' );
		}

		$new_email = $pending['email'];
	} else {
		$new_email = isset( $_POST['gacct_email_new'] ) ? sanitize_email( wp_unslash( $_POST['gacct_email_new'] ) ) : '';
		$password  = isset( $_POST['gacct_email_password'] ) ? (string) wp_unslash( $_POST['gacct_email_password'] ) : '';

		if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user_id ) ) {
			gacct_profile_redirect( 'err_password' );
		}

		if ( ! is_email( $new_email ) ) {
			gacct_profile_redirect( 'err_email_invalid' );
		}

		if ( strtolower( $new_email ) === strtolower( (string) $user->user_email ) ) {
			gacct_profile_redirect( 'err_email_same' );
		}

		$existing = get_user_by( 'email', $new_email );

		// Message volontairement générique (anti-énumération de comptes).
		if ( $existing && (int) $existing->ID !== $user_id ) {
			gacct_profile_redirect( 'err_email_used' );
		}
	}

	$token = wp_generate_password( 32, false, false );

	update_user_meta( $user_id, GACCT_PROFILE_PENDING_EMAIL_META, array(
		'email'   => $new_email,
		'hash'    => gacct_profile_hash_token( $token ),
		'created' => time(),
	) );

	$link = add_query_arg(
		array(
			'gacct_confirm_email' => $user_id,
			'token'               => $token,
		),
		home_url( '/' )
	);

	$sent = gacct_profile_send_email(
		$new_email,
		gacct_profile_email_subject( 'email_confirm' ),
		gacct_profile_email_body( 'email_confirm', $user, array(
			'{confirm_link}' => esc_url_raw( $link ),
			'{new_email}'    => $new_email,
		) )
	);

	if ( ! $sent ) {
		delete_user_meta( $user_id, GACCT_PROFILE_PENDING_EMAIL_META );
		gacct_profile_redirect( 'err_email_send' );
	}

	// Alerte à l'ancienne adresse : elle est encore la seule à faire foi.
	gacct_profile_send_email(
		$user->user_email,
		gacct_profile_email_subject( 'email_alert' ),
		gacct_profile_email_body( 'email_alert', $user, array( '{new_email}' => $new_email ) )
	);

	do_action( 'gacct_profile_email_change_requested', $user_id, $new_email );

	gacct_profile_redirect( 'ok_email_sent', $new_email );
}

add_action( 'template_redirect', 'gacct_profile_maybe_confirm_email', 4 );

/**
 * Consomme le lien de confirmation d'adresse e-mail.
 *
 * Le jeton n'est jamais stocké en clair : on compare son HMAC (`hash_equals`).
 */
function gacct_profile_maybe_confirm_email() {
	if ( empty( $_GET['gacct_confirm_email'] ) || empty( $_GET['token'] ) ) {
		return;
	}

	$user_id = absint( wp_unslash( $_GET['gacct_confirm_email'] ) );
	$token   = sanitize_text_field( wp_unslash( $_GET['token'] ) );
	$user    = $user_id ? get_userdata( $user_id ) : false;
	$pending = $user ? gacct_profile_pending_email( $user_id ) : null;

	if ( ! $user || ! $pending ) {
		gacct_profile_redirect( 'err_link_expired' );
	}

	$stored = get_user_meta( $user_id, GACCT_PROFILE_PENDING_EMAIL_META, true );

	if ( empty( $stored['hash'] ) || ! hash_equals( (string) $stored['hash'], gacct_profile_hash_token( $token ) ) ) {
		gacct_profile_redirect( 'err_link_expired' );
	}

	$old_email = (string) $user->user_email;
	$new_email = $pending['email'];

	// Une autre inscription a pu prendre l'adresse entre-temps.
	$existing = get_user_by( 'email', $new_email );

	if ( $existing && (int) $existing->ID !== $user_id ) {
		delete_user_meta( $user_id, GACCT_PROFILE_PENDING_EMAIL_META );
		gacct_profile_redirect( 'err_email_used' );
	}

	// WordPress envoie lui aussi un « votre adresse a changé » à l'ancienne
	// adresse : on le coupe, notre e-mail (habillé WooCommerce) fait le travail.
	add_filter( 'send_email_change_email', '__return_false' );

	$result = wp_update_user( array(
		'ID'         => $user_id,
		'user_email' => $new_email,
	) );

	remove_filter( 'send_email_change_email', '__return_false' );

	if ( is_wp_error( $result ) ) {
		gacct_profile_redirect( 'err_generic' );
	}

	// L'adresse de facturation suit UNIQUEMENT si elle valait celle du compte
	// (un client peut avoir une adresse de facturation distincte, exprès).
	if ( strtolower( (string) get_user_meta( $user_id, 'billing_email', true ) ) === strtolower( $old_email ) ) {
		update_user_meta( $user_id, 'billing_email', $new_email );
	}

	delete_user_meta( $user_id, GACCT_PROFILE_PENDING_EMAIL_META );

	// L'ancienne adresse est prévenue que le changement a bien eu lieu.
	gacct_profile_send_email(
		$old_email,
		gacct_profile_email_subject( 'email_changed' ),
		gacct_profile_email_body( 'email_changed', $user, array( '{new_email}' => $new_email ) )
	);

	do_action( 'gacct_profile_email_changed', $user_id, $old_email, $new_email );

	gacct_profile_redirect( 'ok_email_changed' );
}

/**
 * HMAC d'un jeton de confirmation (rien n'est stocké en clair).
 *
 * @param string $token Jeton.
 * @return string
 */
function gacct_profile_hash_token( $token ) {
	return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
}

/* =============================================================================
 *  E-MAILS
 * ============================================================================= */

/**
 * Envoi habillé du gabarit WooCommerce (logo, couleur, pied de page).
 *
 * @param string $to      Destinataire.
 * @param string $subject Sujet.
 * @param string $body    Corps HTML.
 * @return bool
 */
function gacct_profile_send_email( $to, $subject, $body ) {
	$message = function_exists( 'gacct_render_email_html' ) ? gacct_render_email_html( $subject, $body ) : $body;

	return (bool) wp_mail(
		$to,
		wp_strip_all_tags( $subject ),
		$message,
		array( 'Content-Type: text/html; charset=UTF-8' )
	);
}

/**
 * Sujet d'un e-mail de la page profil.
 *
 * @param string $key Clé d'e-mail.
 * @return string
 */
function gacct_profile_email_subject( $key ) {
	$site = get_bloginfo( 'name' );

	$subjects = array(
		'email_confirm'    => sprintf( __( '%s — confirmez votre nouvelle adresse e-mail', 'gestion-atelier-cct' ), $site ),
		'email_alert'      => sprintf( __( '%s — demande de changement d’adresse e-mail', 'gestion-atelier-cct' ), $site ),
		'email_changed'    => sprintf( __( '%s — votre adresse e-mail a été modifiée', 'gestion-atelier-cct' ), $site ),
		'password_changed' => sprintf( __( '%s — votre mot de passe a été modifié', 'gestion-atelier-cct' ), $site ),
	);

	$subject = isset( $subjects[ $key ] ) ? $subjects[ $key ] : $site;

	return (string) apply_filters( 'gacct_profile_email_subject', $subject, $key );
}

/**
 * Corps d'un e-mail de la page profil.
 *
 * @param string  $key       Clé d'e-mail.
 * @param WP_User $user      Destinataire concerné.
 * @param array   $variables Remplacements supplémentaires.
 * @return string HTML.
 */
function gacct_profile_email_body( $key, $user, array $variables = array() ) {
	$prenom = $user && $user->first_name ? $user->first_name : ( $user ? $user->display_name : '' );

	$bonjour = '<p>' . sprintf(
		/* translators: %s: prénom du client */
		esc_html__( 'Bonjour %s,', 'gestion-atelier-cct' ),
		esc_html( $prenom )
	) . '</p>';

	$signature = '<p>' . esc_html__( 'Si vous n’êtes pas à l’origine de cette demande, contactez-nous sans attendre.', 'gestion-atelier-cct' ) . '</p>';

	switch ( $key ) {
		case 'email_confirm':
			$body = $bonjour
				. '<p>' . esc_html__( 'Vous avez demandé à utiliser cette adresse comme adresse e-mail de votre compte.', 'gestion-atelier-cct' ) . '</p>'
				. '<p><a href="{confirm_link}">' . esc_html__( 'Confirmer ma nouvelle adresse e-mail', 'gestion-atelier-cct' ) . '</a></p>'
				. '<p>' . esc_html__( 'Ce lien est valable 24 heures et ne peut servir qu’une fois. Tant qu’il n’a pas été ouvert, votre ancienne adresse reste active.', 'gestion-atelier-cct' ) . '</p>'
				. $signature;
			break;

		case 'email_alert':
			$body = $bonjour
				. '<p>' . esc_html__( 'Une demande de changement d’adresse e-mail vient d’être enregistrée sur votre compte, vers {new_email}.', 'gestion-atelier-cct' ) . '</p>'
				. '<p>' . esc_html__( 'Le changement ne prendra effet que lorsque le lien de confirmation envoyé à cette nouvelle adresse aura été ouvert. Aucune action n’est nécessaire de votre part si vous êtes à l’origine de la demande.', 'gestion-atelier-cct' ) . '</p>'
				. $signature;
			break;

		case 'email_changed':
			$body = $bonjour
				. '<p>' . esc_html__( 'L’adresse e-mail de votre compte est désormais {new_email}. Vos prochains messages y seront envoyés, et c’est cette adresse qui vous servira à vous connecter.', 'gestion-atelier-cct' ) . '</p>'
				. $signature;
			break;

		case 'password_changed':
			$body = $bonjour
				. '<p>' . esc_html__( 'Le mot de passe de votre compte vient d’être modifié. Les autres appareils connectés ont été déconnectés.', 'gestion-atelier-cct' ) . '</p>'
				. $signature;
			break;

		default:
			$body = $bonjour;
	}

	$body = strtr( $body, $variables );

	return (string) apply_filters( 'gacct_profile_email_body', $body, $key, $user, $variables );
}
