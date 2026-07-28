<?php
/**
 * Page de confirmation de commande (order-received) sur-mesure.
 *
 * Remplace le template WooCommerce `checkout/thankyou.php` par celui du plugin
 * (via le filtre `wc_get_template` : aucune modification du thème ni de
 * WooCommerce, survit à leurs mises à jour). Deux variantes :
 * - paiement encaissé (carte / commande confirmée) ;
 * - paiement par virement en attente (bacs + statut en attente).
 *
 * White-label : couleurs par variables CSS `--gacct-*`, liens et textes
 * filtrables (`gacct_conf_links`, `gacct_conf_data`).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
 *  MONTANTS : DELEGATION AU PLUGIN KOJITO ACOMPTE PRODUIT
 * ============================================================================= */

/**
 * Total TTC reellement du par le client (acompte + solde), et non le montant encaisse
 * a la commande. Le calcul appartient au plugin d'acompte : on l'appelle plutot que de
 * le recopier. Le repli ne sert qu'au cas ou ce plugin serait desactive.
 *
 * @param WC_Order $order
 * @return float
 */
function gacct_kojito_total_initial( $order ) {
	if ( method_exists( 'Kojito_Acompte_Produit', 'get_total_initial' ) ) {
		return (float) Kojito_Acompte_Produit::get_total_initial( $order );
	}

	$total = 0.0;

	foreach ( $order->get_items() as $item ) {
		$total += (float) $item->get_total() + (float) $item->get_total_tax();
	}

	return round( max( 0, $total ), wc_get_price_decimals() );
}

/**
 * Montant TTC d'une ligne au prix catalogue (et non au montant de l'acompte).
 *
 * @param WC_Order_Item $item
 * @return float
 */
function gacct_kojito_montant_ligne( $item ) {
	if ( method_exists( 'Kojito_Acompte_Produit', 'prix_initial_ttc_ligne' ) ) {
		$initial = Kojito_Acompte_Produit::prix_initial_ttc_ligne( $item );

		if ( null !== $initial ) {
			return (float) $initial;
		}
	}

	return (float) $item->get_total() + (float) $item->get_total_tax();
}

/* =============================================================================
 *  OVERRIDE DU TEMPLATE WOOCOMMERCE
 * ============================================================================= */

add_filter( 'wc_get_template', 'gacct_conf_locate_template', 10, 2 );

function gacct_conf_locate_template( $template, $template_name ) {
	if ( 'checkout/thankyou.php' !== $template_name ) {
		return $template;
	}

	$override = dirname( __DIR__ ) . '/templates/thankyou.php';

	return file_exists( $override ) ? $override : $template;
}

/* =============================================================================
 *  ASSETS
 * ============================================================================= */

add_action( 'wp_enqueue_scripts', 'gacct_conf_enqueue_assets' );

function gacct_conf_enqueue_assets() {
	if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
		return;
	}

	$base_url = plugins_url( '', dirname( __FILE__ ) );
	$base_dir = dirname( __DIR__ );

	wp_enqueue_style(
		'gacct-confirmation',
		$base_url . '/assets/css/confirmation.css',
		array(),
		(string) filemtime( $base_dir . '/assets/css/confirmation.css' )
	);

	wp_enqueue_script(
		'gacct-confirmation',
		$base_url . '/assets/js/confirmation.js',
		array(),
		(string) filemtime( $base_dir . '/assets/js/confirmation.js' ),
		true
	);
}

/* =============================================================================
 *  DONNÉES DU TEMPLATE
 * ============================================================================= */

/**
 * Assemble toutes les données affichées par templates/thankyou.php.
 *
 * @param WC_Order $order Commande.
 * @return array
 */
function gacct_conf_data( $order ) {
	$settings = gacct_pay_settings();

	// --- Variante ---------------------------------------------------------
	$variant = 'paid';
	if ( gacct_pay_order_awaits_transfer( $order ) ) {
		$variant = 'bacs';
	} elseif ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
		$variant = 'failed';
	}

	// --- Montants ---------------------------------------------------------
	// Le calcul (TVA comprise) appartient au plugin Kojito Acompte Produit : on ne le
	// duplique pas ici, sous peine de voir les deux versions diverger.
	$total_initial = gacct_kojito_total_initial( $order );

	$deposit = $order->get_meta( '_kojito_acompte_paye' );
	// 0 est un acompte valide : on ne se rabat sur le total que si la meta est absente.
	$deposit = '' === $deposit ? (float) $order->get_total() : (float) $deposit;
	$balance = round( max( 0, $total_initial - $deposit ), wc_get_price_decimals() );
	$percent = $total_initial > 0 ? round( $deposit / $total_initial * 100, 1 ) : 100;

	// --- Créneau atelier + matériel (CCT) ---------------------------------
	$slot_ts  = 0;
	$materiel = '';

	$occupation_id = (int) $order->get_meta( JWCCT_ORDER_OCCUPATION_ID );
	if ( $occupation_id ) {
		$occupation = jwcct_get_cct_item( JWCCT_CCT_OCCUPATION, $occupation_id );
		if ( is_array( $occupation ) && ! empty( $occupation['date_reservee'] ) ) {
			$slot_ts = (int) $occupation['date_reservee'];
		}
	}

	$revision_id = (int) $order->get_meta( JWCCT_ORDER_REVISION_ID );
	if ( $revision_id ) {
		$revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );
		if ( is_array( $revision ) ) {
			$parts = array_filter(
				array(
					isset( $revision['marque'] ) ? ucfirst( trim( (string) $revision['marque'] ) ) : '',
					isset( $revision['modele'] ) ? ucfirst( trim( (string) $revision['modele'] ) ) : '',
					isset( $revision['taille'] ) ? strtoupper( trim( (string) $revision['taille'] ) ) : '',
				)
			);
			$materiel = implode( ' · ', $parts );
		}
	}

	// Colis attendu : la veille du créneau (filtrable).
	$parcel_ts = $slot_ts ? $slot_ts - (int) apply_filters( 'gacct_conf_parcel_lead_days', 1 ) * DAY_IN_SECONDS : 0;

	// --- Échéances virement ------------------------------------------------
	$deadlines      = gacct_pay_order_deadlines( $order );
	$days_remaining = max( 0, (int) ceil( ( $deadlines['cancel'] - time() ) / DAY_IN_SECONDS ) );

	// --- Adresse de l'atelier (réglages boutique WooCommerce) --------------
	$store_address = array_filter(
		array(
			get_bloginfo( 'name' ),
			get_option( 'woocommerce_store_address' ),
			get_option( 'woocommerce_store_address_2' ),
			trim( get_option( 'woocommerce_store_postcode' ) . ' ' . get_option( 'woocommerce_store_city' ) ),
		)
	);
	$store_address = array_map( function ( $line ) { return trim( $line, " ,\t" ); }, $store_address );

	$data = array(
		'variant'          => $variant,
		'order'            => $order,
		'reference'        => $order->get_order_number(),
		'first_name'       => $order->get_billing_first_name(),
		'email'            => $order->get_billing_email(),
		'order_date'       => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '',
		'payment_title'    => $order->get_payment_method_title(),
		'total_initial'    => $total_initial,
		'deposit'          => $deposit,
		'balance'          => $balance,
		'percent'          => $percent,
		'deposit_date'     => $order->get_meta( '_kojito_date_acompte_paye' ) ? wp_date( get_option( 'date_format' ), strtotime( $order->get_meta( '_kojito_date_acompte_paye' ) ) ) : wp_date( get_option( 'date_format' ) ),
		'slot_ts'          => $slot_ts,
		'slot_label'       => $slot_ts ? wp_date( 'j F Y', $slot_ts ) : '',
		'parcel_label'     => $parcel_ts ? wp_date( 'j F Y', $parcel_ts ) : '',
		'materiel'         => $materiel,
		'reminder_label'   => gacct_pay_format_date( $deadlines['reminder'] ),
		'deadline_label'   => gacct_pay_format_date( $deadlines['cancel'] ),
		'days_remaining'   => $days_remaining,
		'bank_rows'        => gacct_pay_bank_rows( $order ),
		'store_address'    => $store_address,
		'contact_phone'    => $settings['contact_phone'],
		'contact_hours'    => $settings['contact_hours'],
		'links'            => gacct_conf_links( $order ),
	);

	return apply_filters( 'gacct_conf_data', $data, $order );
}

/**
 * Registre central des liens de la page de confirmation.
 *
 * ⚠ Les entrées marquées "todo" pointent vers des fonctionnalités pas encore
 * développées (bon d'intervention, reçu PDF, récap PDF, RIB PDF). Elles seront
 * reliées ici, en un seul endroit, quand elles existeront.
 *
 * @param WC_Order $order Commande.
 * @return array<string,string>
 */
function gacct_conf_links( $order ) {
	$links = array(
		'account'        => wc_get_page_permalink( 'myaccount' ),
		'new_request'    => home_url( '/demande-intervention/' ),
		'packing_guide'  => home_url( '/controles/' ),           // consignes d'emballage (section expédition).
		'contact'        => home_url( '/contact/' ),
		'work_order'     => gacct_wo_print_url( $order ),        // bon d'intervention imprimable (gacct-workorder.php).
		'receipt'        => '#',                                 // TODO : reçu de l'acompte (PDF).
		'summary_pdf'    => '#',                                 // TODO : récapitulatif PDF.
		'rib_pdf'        => '#',                                 // TODO : RIB téléchargeable (PDF).
	);

	return apply_filters( 'gacct_conf_links', $links, $order );
}

/**
 * Fonctionnalités de la page de confirmation, activables une par une.
 *
 * Les blocs correspondants sont écrits et stylés dans le template, mais renvoient
 * vers des fonctionnalités qui n'existent pas encore : on les masque plutôt que de
 * les supprimer, pour n'avoir qu'un booléen à basculer le jour où elles arrivent.
 *
 * Marche à suivre pour en activer une :
 *   1. renseigner son URL réelle dans `gacct_conf_links()` ;
 *   2. passer son drapeau à true ici (ou via le filtre `gacct_conf_features`).
 *
 * `add_service` est un cas à part : le lien fonctionne, mais il mène au formulaire
 * de demande d'intervention, qui crée une commande SÉPARÉE — il n'ajoute rien à la
 * commande en cours. Tant qu'un vrai ajout de prestation n'existe pas, il induit
 * le client en erreur.
 *
 * @return array<string,bool>
 */
function gacct_conf_features() {
	return apply_filters(
		'gacct_conf_features',
		array(
			'work_order'  => true,  // bon d'intervention imprimable (gacct-workorder.php, 28/07/2026).
			'receipt'     => false, // reçu de l'acompte (PDF).
			'summary_pdf' => false, // récapitulatif de commande (PDF).
			'rib_pdf'     => false, // RIB téléchargeable (PDF).
			'add_service' => false, // ajout d'une prestation à une commande existante.
		)
	);
}

/**
 * @param string $name Clé de `gacct_conf_features()`.
 * @return bool
 */
function gacct_conf_feature( $name ) {
	$features = gacct_conf_features();

	return ! empty( $features[ $name ] );
}

/**
 * Petit helper d'icônes SVG inline (trait, currentColor).
 */
function gacct_conf_icon( $name ) {
	$icons = array(
		'check'     => '<path d="M20 6L9 17l-5-5"/>',
		'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
		'download'  => '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
		'printer'   => '<path d="M6 9V2h12v7"/><rect x="6" y="14" width="12" height="8"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/>',
		'package'   => '<path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>',
		'clipboard' => '<path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 13l2 2 4-4"/>',
		'file'      => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/>',
		'truck'     => '<rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
		'user'      => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
		'plus'      => '<path d="M12 5v14M5 12h14"/>',
		'phone'     => '<path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81 19.79 19.79 0 01.01 1.18 2 2 0 012 .01h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 14.92v2z"/>',
		'copy'      => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>',
		'send'      => '<path d="M3 11l19-9-9 19-2-8-8-2z"/>',
		'card'      => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
		'lock'      => '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>',
		'warn'      => '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
		'arrow'     => '<path d="M5 12h14M13 6l6 6-6 6"/>',
	);

	$path = isset( $icons[ $name ] ) ? $icons[ $name ] : $icons['check'];

	return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $path . '</svg>';
}

/**
 * Formatte un montant "254,90 €" avec le symbole en plus petit.
 */
function gacct_conf_amount( $amount ) {
	return esc_html( number_format( (float) $amount, 2, ',', ' ' ) ) . ' <span>€</span>';
}
