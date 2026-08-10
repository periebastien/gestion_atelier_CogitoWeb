<?php
/**
 * Bon d'intervention imprimable + endpoint de scan QR (CDC du 2026-07-27,
 * précisions Bastien du 2026-07-28 : couleurs en texte + pastilles, logo).
 *
 * - Page A4 : /?gacct_workorder=<order_id>&key=<order_key> — même modèle
 *   d'autorisation que la page order-received invitée (clé de commande).
 * - QR : encode /?gacct_scan=<order_id>&t=<jeton>. Jeton DÉTERMINISTE
 *   (HMAC order_id + order_key + wp_salt, tronqué 32 hex) : rien à stocker,
 *   aucune donnée personnelle encodée, invalidable en régénérant la clé de
 *   commande. Généré/affiché localement (lib qrcodejs vendored).
 * - Scan : opérateur (gacct_operate) → vue réception tant que le colis est
 *   attendu (état ≤ 1 ou dossier incomplet), FICHE du dossier ensuite — le QR
 *   reste scotché sur la voile et sert de raccourci toute la vie du dossier
 *   (réunion du 06/08/2026) ; client connecté → espace client (page neutre,
 *   aucune transition) ; non connecté → login puis retour. Scans journalisés.
 * - Expiration : créneau + 3 mois (à défaut : commande + 6 mois).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jeton de scan d'une commande (déterministe, filtrable).
 */
function gacct_wo_token( $order ) {
	$raw = hash_hmac( 'sha256', 'gacct_workorder|' . $order->get_id() . '|' . $order->get_order_key(), wp_salt( 'auth' ) );

	return substr( $raw, 0, 32 );
}

/**
 * URL de la page imprimable du bon.
 */
function gacct_wo_print_url( $order ) {
	return add_query_arg(
		array(
			'gacct_workorder' => $order->get_id(),
			'key'             => $order->get_order_key(),
		),
		home_url( '/' )
	);
}

/**
 * URL encodée dans le QR (scan à l'atelier).
 */
function gacct_wo_scan_url( $order ) {
	return add_query_arg(
		array(
			'gacct_scan' => $order->get_id(),
			't'          => gacct_wo_token( $order ),
		),
		home_url( '/' )
	);
}

/**
 * Révision + occupation liées à une commande (SQL direct, léger).
 *
 * @return array { revision: array|null, slot_ts: int }
 */
function gacct_wo_order_context( $order ) {
	global $wpdb;

	$revision = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}jet_cct_revision WHERE order_id = %d AND cct_status = 'publish' LIMIT 1",
		$order->get_id()
	), ARRAY_A );

	$slot_ts = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT CAST(date_reservee AS UNSIGNED) FROM {$wpdb->prefix}jet_cct_occupation_atelier
		 WHERE cct_status = 'publish' AND ( order_id = %d OR revision_id = %d ) LIMIT 1",
		$order->get_id(),
		$revision ? absint( $revision['_ID'] ) : 0
	) );

	return array(
		'revision' => $revision,
		'slot_ts'  => $slot_ts,
	);
}

/**
 * Date d'expiration du QR (timestamp) : créneau + 3 mois, sinon commande + 6 mois.
 */
function gacct_wo_expiry_ts( $order, $slot_ts ) {
	if ( $slot_ts ) {
		$expiry = $slot_ts + 3 * MONTH_IN_SECONDS;
	} else {
		$created = $order->get_date_created();
		$expiry  = ( $created ? $created->getTimestamp() : time() ) + 6 * MONTH_IN_SECONDS;
	}

	return apply_filters( 'gacct_wo_expiry_ts', $expiry, $order, $slot_ts );
}

/**
 * Données complètes du bon, consommées par templates/workorder.php.
 */
function gacct_wo_data( $order ) {
	$context  = gacct_wo_order_context( $order );
	$revision = $context['revision'];
	$slot_ts  = $context['slot_ts'];

	$couleur_texte = $revision ? trim( (string) ( $revision['couleur'] ?? '' ) ) : '';
	$swatches      = function_exists( 'gacct_extraire_couleurs' ) ? gacct_extraire_couleurs( $couleur_texte ) : array();

	$logo = get_option( 'woocommerce_email_header_image' );

	// Adresse d'expédition + téléphone : réglages boutique WooCommerce et page
	// « Paiements & relances ». Rien de codé en dur (white-label).
	$store_address = array_filter(
		array(
			get_bloginfo( 'name' ),
			get_option( 'woocommerce_store_address' ),
			get_option( 'woocommerce_store_address_2' ),
			trim( get_option( 'woocommerce_store_postcode' ) . ' ' . get_option( 'woocommerce_store_city' ) ),
		)
	);
	$store_address = array_values( array_map( function ( $line ) { return trim( $line, " ,\t" ); }, $store_address ) );

	$pay_settings = function_exists( 'gacct_pay_settings' ) ? gacct_pay_settings() : array();

	return apply_filters( 'gacct_wo_data', array(
		'order'         => $order,
		'reference'     => $order->get_order_number(),
		'scan_url'      => gacct_wo_scan_url( $order ),
		'logo_url'      => $logo ? $logo : '',
		'accent'        => get_option( 'woocommerce_email_base_color', '#20c4c3' ),
		'site_name'     => get_bloginfo( 'name' ),
		'client'        => array(
			'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email' => $order->get_billing_email(),
			'phone' => $order->get_billing_phone(),
		),
		'materiel'      => array(
			'marque'   => $revision ? ucfirst( trim( (string) ( $revision['marque'] ?? '' ) ) ) : '',
			'modele'   => $revision ? trim( (string) ( $revision['modele'] ?? '' ) ) : '',
			'serie'    => $revision ? trim( (string) ( $revision['numero_de_serie'] ?? '' ) ) : '',
			'taille'   => $revision ? trim( (string) ( $revision['taille'] ?? '' ) ) : '',
			'ptv'      => $revision ? trim( (string) ( $revision['p_t_v'] ?? '' ) ) : '',
			'couleurs' => $couleur_texte,
			'swatches' => $swatches, // 0–3 entrées { base, light } (palette fermée du site).
		),
		'prestations'   => function_exists( 'gacct_op_expected_items' ) ? gacct_op_expected_items( $order ) : array(),
		'store_address' => $store_address,
		'contact_phone' => isset( $pay_settings['contact_phone'] ) ? $pay_settings['contact_phone'] : '',
		'slot_ts'       => $slot_ts,
		'slot_date'     => $slot_ts ? date_i18n( get_option( 'date_format' ), $slot_ts ) : '',
		'deadline_date' => $slot_ts ? date_i18n( get_option( 'date_format' ), $slot_ts - DAY_IN_SECONDS ) : '',
	), $order, $revision );
}

/**
 * Rendu de la page imprimable (standalone, hors thème).
 */
function gacct_wo_maybe_render_print_page() {
	if ( empty( $_GET['gacct_workorder'] ) ) {
		return;
	}

	$order_id = absint( wp_unslash( $_GET['gacct_workorder'] ) );
	$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

	if ( ! $order || ! $key || ! hash_equals( $order->get_order_key(), $key ) ) {
		wp_die( esc_html__( 'Bon d\'intervention introuvable ou lien invalide.', 'gestion-atelier-cct' ) );
	}

	if ( $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
		wp_die( esc_html__( 'Cette commande a été annulée : le bon d\'intervention n\'est plus valable.', 'gestion-atelier-cct' ) );
	}

	/*
	 * Tant que le paiement n'est pas encaissé (virement en attente, paiement
	 * échoué), le bon n'est pas imprimable par le CLIENT : il vaut prise en
	 * charge du matériel et porte le QR de réception. Les boutons sont grisés
	 * côté client (page de confirmation, détail de commande), mais le lien
	 * reste devinable — d'où cette garde, qui est la vraie protection.
	 * L'atelier (gacct_operate) et l'admin gardent l'accès : ils peuvent avoir
	 * à réimprimer un bon, y compris sur un dossier réglé sur place.
	 */
	$acces_atelier = current_user_can( GACCT_OP_CAP ) || current_user_can( 'manage_woocommerce' );

	if (
		! $acces_atelier
		&& function_exists( 'gacct_order_payment_received' )
		&& ! gacct_order_payment_received( $order )
	) {
		wp_die(
			esc_html__( 'Le bon d\'intervention sera disponible dès la réception de votre paiement. Dès qu\'il nous parvient, vous pourrez l\'imprimer depuis votre espace client.', 'gestion-atelier-cct' ),
			esc_html__( 'Bon d\'intervention', 'gestion-atelier-cct' ),
			array( 'response' => 403 )
		);
	}

	nocache_headers();

	$data = gacct_wo_data( $order );

	include dirname( __DIR__ ) . '/templates/workorder.php';
	exit;
}
add_action( 'template_redirect', 'gacct_wo_maybe_render_print_page', 5 );

/**
 * Endpoint de scan du QR.
 */
function gacct_wo_maybe_handle_scan() {
	if ( empty( $_GET['gacct_scan'] ) ) {
		return;
	}

	$order_id = absint( wp_unslash( $_GET['gacct_scan'] ) );
	$token    = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
	$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

	if ( ! $order || ! $token || ! hash_equals( gacct_wo_token( $order ), $token ) ) {
		wp_die( esc_html__( 'QR code invalide.', 'gestion-atelier-cct' ) );
	}

	// Non connecté : login puis retour sur cette URL (opérateur comme client).
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( gacct_wo_scan_url( $order ) ) );
		exit;
	}

	$context = gacct_wo_order_context( $order );

	if ( time() > gacct_wo_expiry_ts( $order, $context['slot_ts'] ) ) {
		wp_die( esc_html__( 'Ce QR code a expiré. Ouvrez le dossier depuis la console atelier.', 'gestion-atelier-cct' ) );
	}

	$user        = wp_get_current_user();
	$is_operator = current_user_can( GACCT_OP_CAP );

	// Journalisation de chaque scan (CDC : qui / quand).
	$order->add_order_note( sprintf(
		'[Scan bon d\'intervention] par %s (%s)',
		$user->display_name,
		$is_operator ? __( 'opérateur', 'gestion-atelier-cct' ) : __( 'client / autre', 'gestion-atelier-cct' )
	) );
	$order->save();

	if ( $is_operator && $context['revision'] ) {
		$rev_id = absint( $context['revision']['_ID'] );
		$etat   = absint( $context['revision']['etat_de_la_commande'] ?? 0 );

		// Le QR reste scotché sur la voile pendant toute sa vie à l'atelier
		// (réunion du 06/08/2026) : tant que la réception est en jeu (état ≤ 1,
		// ou dossier incomplet à compléter), le scan ouvre la vue réception ;
		// ensuite, il ouvre directement la fiche du dossier.
		$needs_reception = ( $etat <= 1 ) || ! empty( $context['revision']['dossier_incomplet'] );

		wp_safe_redirect( $needs_reception
			? gacct_op_console_url( 0, array( 'view' => 'reception', 'rev' => $rev_id ) )
			: gacct_op_console_url( $rev_id ) );
		exit;
	}

	if ( $is_operator ) {
		wp_safe_redirect( gacct_op_console_url() );
		exit;
	}

	// Client (ou tout autre connecté) : page de suivi neutre, aucune transition.
	wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
	exit;
}
add_action( 'template_redirect', 'gacct_wo_maybe_handle_scan', 5 );
