<?php
/**
 * Côté pilote : inscription via le formulaire de demande habituel.
 *
 * Parcours : lien du club (?club=CODE) ou code saisi → bandeau « commande
 * groupée » au-dessus du formulaire → l'étape 3 (date et retour) est remplie
 * toute seule → garde serveur : date et retour imposés, lot mémorisé en
 * session WooCommerce → acompte nul au panier → à la liaison commande/dossier,
 * rattachement au lot (commande, révision, occupation hors capacité).
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

defined( 'GACCT_CLUBS_COOKIE' ) || define( 'GACCT_CLUBS_COOKIE', 'gacct_club' );
defined( 'GACCT_CLUBS_SESSION' ) || define( 'GACCT_CLUBS_SESSION', 'gacct_club_lot' );

/* ------------------------------------------------------------- contexte --- */

/** Le visiteur est-il sur la page du formulaire de demande ? */
function gacct_clubs_is_demande_page() {
	return is_page( (string) gacct_clubs_setting( 'demande_page' ) );
}

/**
 * Lot du contexte courant (lien, code saisi ou cookie), ouvert ou non.
 *
 * @return array|null
 */
function gacct_clubs_context_lot() {
	static $lot = false;
	if ( false !== $lot ) {
		return $lot;
	}

	$code = '';
	if ( isset( $_GET['club'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = gacct_clubs_clean_code( wp_unslash( $_GET['club'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	} elseif ( isset( $_COOKIE[ GACCT_CLUBS_COOKIE ] ) ) {
		$code = gacct_clubs_clean_code( wp_unslash( $_COOKIE[ GACCT_CLUBS_COOKIE ] ) );
	}

	$lot = ( '' !== $code && '0' !== $code ) ? gacct_clubs_get_lot_by_code( $code ) : null;
	if ( $lot && ! gacct_clubs_lot_has_code( $lot ) ) {
		$lot = null;
	}
	return $lot;
}

/** Pose / retire le cookie selon ?club= (avant toute sortie). */
add_action( 'template_redirect', 'gacct_clubs_handle_club_param', 4 );

function gacct_clubs_handle_club_param() {
	if ( ! isset( $_GET['club'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$raw = gacct_clubs_clean_code( wp_unslash( $_GET['club'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$opt = array( 'expires' => time() + DAY_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' );

	if ( '' === $raw || '0' === $raw ) {
		$opt['expires'] = time() - HOUR_IN_SECONDS;
		setcookie( GACCT_CLUBS_COOKIE, '', $opt );
		unset( $_COOKIE[ GACCT_CLUBS_COOKIE ] );
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( GACCT_CLUBS_SESSION, null );
		}
		return;
	}

	$lot = gacct_clubs_get_lot_by_code( $raw );
	if ( $lot && gacct_clubs_registrations_open( $lot ) ) {
		setcookie( GACCT_CLUBS_COOKIE, $lot['code'], $opt );
		$_COOKIE[ GACCT_CLUBS_COOKIE ] = $lot['code'];
	}
}

/** Lot mémorisé pour le panier en cours (posé par la garde du formulaire). */
function gacct_clubs_session_lot_id() {
	$id = ( function_exists( 'WC' ) && WC()->session ) ? absint( WC()->session->get( GACCT_CLUBS_SESSION ) ) : 0;
	// Filtrable : sert aux tests en ligne de commande (pas de session WooCommerce).
	return absint( apply_filters( 'gacct_clubs_session_lot_id', $id ) );
}

/* -------------------------------------------------- bandeau du formulaire --- */

add_filter( 'elementor/widget/render_content', 'gacct_clubs_banner_on_form', 30, 2 );

function gacct_clubs_banner_on_form( $content, $widget ) {
	if ( is_admin() || ! is_object( $widget ) || 'jet-form-builder-form' !== $widget->get_name() || ! gacct_clubs_is_demande_page() ) {
		return $content;
	}

	wp_enqueue_style( 'gacct-clubs-front', GACCT_CLUBS_URL . 'assets/clubs-front.css', array(), GACCT_CLUBS_VERSION );

	return gacct_clubs_banner_html() . $content;
}

function gacct_clubs_banner_html() {
	$lot   = gacct_clubs_context_lot();
	$asked = isset( $_GET['club'] ) ? gacct_clubs_clean_code( wp_unslash( $_GET['club'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$quit  = esc_url( add_query_arg( 'club', '0' ) );

	if ( $lot && gacct_clubs_registrations_open( $lot ) ) {
		$deadline = $lot['limite_inscription'] ? gacct_clubs_date_label( $lot['limite_inscription'] ) : '';
		return '<div class="gacct-club-banner is-on" role="status">'
			. '<p class="gacct-club-banner-eyebrow">Commande groupée · code ' . esc_html( $lot['code'] ) . '</p>'
			. '<p class="gacct-club-banner-title">Vous inscrivez votre matériel à la révision groupée de ' . esc_html( $lot['nom'] ) . '</p>'
			. '<p>Intervention ' . esc_html( gacct_clubs_period_label( $lot ) ) . '. Rien à payer : c’est le club qui règle. Vous remettrez votre voile au club, qui envoie tout ensemble.'
			. ( $deadline ? ' Inscriptions ouvertes jusqu’au ' . esc_html( $deadline ) . '.' : '' ) . '</p>'
			. '<p class="gacct-club-banner-quit"><a href="' . $quit . '">Ce n’est pas une demande pour le club</a></p>'
			. '</div>';
	}

	if ( '' !== $asked && '0' !== $asked ) {
		$known = gacct_clubs_get_lot_by_code( $asked );
		$msg   = $known && gacct_clubs_lot_has_code( $known )
			? sprintf( 'Les inscriptions à la révision groupée de %s sont closes. Contactez votre club ou l’atelier.', esc_html( $known['nom'] ) )
			: 'Ce code club n’est pas reconnu. Vérifiez-le auprès de votre club.';
		return '<div class="gacct-club-banner is-error" role="alert"><p>' . $msg . '</p>' . gacct_clubs_code_form() . '</div>';
	}

	// Aucune demande groupée ouverte : rien à proposer.
	if ( ! gacct_clubs_lots( array( 'statut' => 'planifie' ) ) ) {
		return '';
	}

	return '<details class="gacct-club-banner is-ask"><summary>Vous avez un code club ?</summary>' . gacct_clubs_code_form() . '</details>';
}

function gacct_clubs_code_form() {
	return '<form method="get" class="gacct-club-code-form" action="' . esc_url( gacct_clubs_page_url( 'demande_page' ) ) . '">'
		. '<label for="gacct-club-code">Code club</label>'
		. '<input id="gacct-club-code" name="club" type="text" autocomplete="off" spellcheck="false" placeholder="Ex. FALAISES26" required>'
		. '<button type="submit">Valider</button></form>';
}

/* ----------------------------------------- données et script du formulaire --- */

add_filter( 'gacct_demande_data', 'gacct_clubs_demande_data' );

function gacct_clubs_demande_data( $data ) {
	$lot = gacct_clubs_context_lot();
	if ( ! $lot || ! gacct_clubs_registrations_open( $lot ) ) {
		return $data;
	}

	$first          = gacct_clubs_first_day( $lot );
	$data['dispos'] = array( $first => 99 );
	// Lu par le formulaire du socle : la ligne d'acompte devient une mention.
	$data['priseEnCharge']     = 'Pris en charge par votre club';
	$data['priseEnChargeNote'] = 'Rien à régler aujourd’hui : votre club reçoit une facture unique pour l’ensemble du lot.';
	$data['club']   = array(
		'code'     => $lot['code'],
		'nom'      => $lot['nom'],
		'jour'     => $first,
		'periode'  => gacct_clubs_period_label( $lot ),
		'retour'   => (int) gacct_clubs_setting( 'product_retour' ),
		'message'  => sprintf( 'Date et retour sont réglés par votre club : intervention %s, retour groupé au club. Cliquez sur « Continuer ».', gacct_clubs_period_label( $lot ) ),
	);

	wp_enqueue_script( 'gacct-clubs-form', GACCT_CLUBS_URL . 'assets/clubs-form.js', array(), GACCT_CLUBS_VERSION, true );

	return $data;
}

/* ------------------------------------------------------------- garde ------ */

add_filter( 'gacct_demande_garde_erreur', 'gacct_clubs_garde', 20, 3 );

function gacct_clubs_garde( $erreur, $request, $handler ) {
	$lot = gacct_clubs_context_lot();

	if ( function_exists( 'WC' ) && WC()->session ) {
		WC()->session->set( GACCT_CLUBS_SESSION, null );
	}

	if ( ! $lot ) {
		return $erreur;
	}

	if ( ! gacct_clubs_registrations_open( $lot ) ) {
		return $erreur ? $erreur : sprintf( 'Les inscriptions à la révision groupée de %s sont closes. Contactez votre club.', $lot['nom'] );
	}

	if ( $erreur ) {
		return $erreur;
	}

	// Date et retour imposés par le lot, quelle que soit la saisie.
	if ( is_object( $handler ) && isset( $handler->request_data ) && ( is_array( $handler->request_data ) || $handler->request_data instanceof \ArrayAccess ) ) {
		$handler->request_data['date_intervention'] = gacct_clubs_first_day( $lot );
		$handler->request_data['frais_de_ports']    = (string) (int) gacct_clubs_setting( 'product_retour' );
	}

	if ( function_exists( 'WC' ) && WC()->session ) {
		WC()->session->set( GACCT_CLUBS_SESSION, (int) $lot['id'] );
	}

	return $erreur;
}

/* ---------------------------------------------------------- acompte nul --- */

add_filter( 'kojito_montant_acompte', 'gacct_clubs_zero_deposit', 10, 3 );

function gacct_clubs_zero_deposit( $acompte, $product_id, $parent_id ) {
	if ( ! gacct_clubs_session_lot_id() ) {
		return $acompte;
	}
	// Pas à la création programmatique de la facture du club (pas de panier).
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $acompte;
	}
	return 0.0;
}

add_action( 'woocommerce_before_checkout_form', static function () {
	$lot_id = gacct_clubs_session_lot_id();
	$lot    = $lot_id ? gacct_clubs_get_lot( $lot_id ) : null;
	if ( $lot ) {
		wc_print_notice( sprintf( 'Commande groupée de %s : rien à payer, c’est le club qui règle. Validez simplement votre demande.', esc_html( $lot['nom'] ) ), 'notice' );
	}
}, 5 );

/* ---------------------------------------------- liaison commande / lot --- */

add_action( 'gacct_order_linked', 'gacct_clubs_on_order_linked', 10, 3 );

function gacct_clubs_on_order_linked( $order, $revision_id, $occupation_id ) {
	global $wpdb;

	$lot_id = gacct_clubs_session_lot_id();
	$lot    = $lot_id ? gacct_clubs_get_lot( $lot_id ) : null;

	if ( ! $lot || ! $order instanceof WC_Order ) {
		return;
	}

	$order->update_meta_data( GACCT_CLUBS_META_LOT, (int) $lot['id'] );
	$order->add_order_note( sprintf( 'Commande groupée %1$s (%2$s) : dossier rattaché au lot, aucun acompte, réglé par le club.', $lot['code'], $lot['nom'] ) );
	$order->save();

	if ( $revision_id ) {
		$wpdb->update( $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_REVISION, array( 'club_lot_id' => (int) $lot['id'] ), array( '_ID' => (int) $revision_id ) );
	}
	if ( $occupation_id ) {
		$wpdb->update( gacct_clubs_occ_table(), array(
			'club_lot_id'   => (int) $lot['id'],
			'hors_capacite' => '1',
			'date_reservee' => gacct_clubs_day_ts( gacct_clubs_first_day( $lot ) ),
		), array( '_ID' => (int) $occupation_id ) );
	}

	if ( function_exists( 'WC' ) && WC()->session ) {
		WC()->session->set( GACCT_CLUBS_SESSION, null );
	}
}

/* ------------------------------------- automatismes et écrans du pilote --- */

add_filter( 'gacct_order_skip_automation', 'gacct_clubs_skip_automation', 10, 3 );

function gacct_clubs_skip_automation( $skip, $order, $context ) {
	if ( gacct_clubs_order_lot_id( $order ) && in_array( $context, array( 'noshow', 'preslot', 'balance_request', 'balance_reminder' ), true ) ) {
		return true;
	}
	if ( gacct_clubs_invoice_lot_id( $order ) && 'auto_cancel' === $context ) {
		return true;
	}
	return $skip;
}

add_filter( 'gacct_pay_deposit_received_template', static function ( $key, $order ) {
	return gacct_clubs_order_lot_id( $order ) ? 'club_member_registered' : $key;
}, 10, 2 );

add_filter( 'gacct_pay_email_variables', static function ( $vars, $order = null ) {
	$lot_id = gacct_clubs_order_lot_id( $order );
	$lot    = $lot_id ? gacct_clubs_get_lot( $lot_id ) : null;
	if ( $lot ) {
		$vars = array_merge( gacct_clubs_email_vars( $lot ), $vars );
	}
	return $vars;
}, 10, 2 );

add_filter( 'gacct_order_third_party', 'gacct_clubs_third_party', 10, 2 );

function gacct_clubs_third_party( $tp, $order ) {
	$lot_id = gacct_clubs_order_lot_id( $order );
	$lot    = $lot_id ? gacct_clubs_get_lot( $lot_id ) : null;
	if ( ! $lot ) {
		return $tp;
	}

	$remise = $lot['limite_arrivee'] ? gacct_clubs_date_label( gacct_clubs_shift_date( $lot['limite_arrivee'], -2 ) ) : '';
	$wo     = function_exists( 'gacct_wo_print_url' ) ? gacct_wo_print_url( $order ) : '';
	$name   = trim( $order->get_billing_first_name() );

	$ship = '<p>Ne postez rien vous-même : remettez votre voile à <strong>' . esc_html( $lot['nom'] ) . '</strong>'
		. ( $remise ? ' avant le <strong>' . esc_html( $remise ) . '</strong>' : '' )
		. ', avec votre bon d’intervention imprimé. Le club envoie toutes les voiles ensemble et le matériel revient au club.</p>';

	$thank = '<div class="conf"><div class="conf-eyebrow">Commande groupée · ' . esc_html( $lot['code'] ) . '</div>'
		. '<h1 class="conf-h1">' . ( $name ? esc_html( sprintf( 'Merci %s, votre voile est inscrite', $name ) ) : 'Votre voile est inscrite' ) . '</h1>'
		. '<p class="conf-sub">Révision groupée de <strong>' . esc_html( $lot['nom'] ) . '</strong>, ' . esc_html( gacct_clubs_period_label( $lot ) ) . '. Rien à payer : c’est le club qui règle.</p>'
		. '<ol class="conf-sub" style="text-align:left;max-width:560px;margin:16px auto">'
		. '<li><strong>Imprimez votre bon d’intervention.</strong> Il est indispensable : il identifie votre voile à l’atelier.</li>'
		. '<li><strong>Remettez votre voile au club</strong>' . ( $remise ? ' avant le ' . esc_html( $remise ) : '' ) . ', avec le bon. Le club envoie tout ensemble.</li>'
		. '<li><strong>Suivez votre révision</strong> dans votre espace client. Votre rapport y sera déposé.</li></ol>'
		. '<p>' . ( $wo ? '<a class="btn-primary" href="' . esc_url( $wo ) . '" target="_blank" rel="noopener">Imprimer mon bon d’intervention</a> ' : '' )
		. '<a class="btn-secondary" href="' . esc_url( $order->get_view_order_url() ) . '">Suivre mon dossier</a></p></div>';

	return array(
		'name'          => $lot['nom'],
		'paid_label'    => sprintf( 'Réglé par %1$s (commande groupée %2$s)', $lot['nom'], $lot['code'] ),
		'ship_title'    => 'Remettez votre voile au club',
		'ship_html'     => $ship,
		'dash_title'    => 'Remettez votre voile au club',
		'dash_text'     => sprintf( 'Révision groupée de <span class="hl">%1$s</span>, %2$s. Imprimez votre bon et remettez-le avec votre voile au club%3$s.', esc_html( $lot['nom'] ), esc_html( gacct_clubs_period_label( $lot ) ), $remise ? ' avant le ' . esc_html( $remise ) : '' ),
		'dash_url'      => $wo ? $wo : $order->get_view_order_url(),
		'dash_cta'      => 'Imprimer mon bon',
		'thankyou_html' => $thank,
	);
}

/* ------------------------------------------------ bon d'intervention ------ */

add_filter( 'gacct_wo_data', 'gacct_clubs_wo_bandeau', 10, 2 );

/** Bon d'un dossier de lot : nom du club en très gros (retour de Timothée, 06/10/2026). */
function gacct_clubs_wo_bandeau( $data, $order ) {
	$lot = gacct_clubs_get_lot( gacct_clubs_order_lot_id( $order ) );
	if ( ! $lot ) {
		return $data;
	}
	$data['bandeau'] = array(
		'label' => 'Commande groupée club',
		'titre' => ! empty( $lot['club']['nom'] ) ? $lot['club']['nom'] : $lot['nom'],
		'sous'  => 'Code ' . $lot['code'],
	);
	return $data;
}
