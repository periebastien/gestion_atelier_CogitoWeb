<?php
/**
 * Plugin Name: Pack AEROTECH — configuration site
 * Description: Carte d'IDs du site fly-aerotech.cogitoweb.net pour le framework Gestion Atelier (relations, glossaires, queries, produit devis). Aucune logique métier : uniquement le branchement des identifiants propres à ce site.
 * Version: 1.0.0
 * Author: CogitoWeb
 * Requires Plugins: gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * =====================================================================
 * CARTE D'IDS DU SITE AEROTECH (provisioning du 02/08/2026)
 * =====================================================================
 * Les entités JetEngine (relations, glossaires, queries, tables) ont été
 * recréées avec les MÊMES ids que le site de référence revision.cogitoweb.net
 * (table lDHOLS_jet_post_types vide au moment du provisioning). Les filtres
 * ci-dessous rendent ce branchement EXPLICITE : si une entité doit un jour
 * être recréée avec un autre id, c'est ICI (et uniquement ici) qu'on le change.
 *
 * Seuls les ids WooCommerce (catégories, produits) diffèrent du site référence.
 *
 * RAPPORTS DE CONTRÔLE — CHOIX ASSUMÉ (04/08/2026) : ce pack n'enregistre
 * AUCUN pack de rapports (pas de hook `gacct_report_register_packs`), et
 * c'est voulu — AEROTECH produit ses rapports hors plateforme et les dépose
 * en PDF depuis la fiche console (upload manuel, coffre sécurisé, envoi au
 * client au solde réglé : circuit identique). Conséquences automatiques côté
 * framework : carte console en mode « dépôt de PDF » (formulaire ouvert
 * d'office), onglet Configuration > Rapports masqué, endpoints de génération
 * inertes (« Modèle de rapport inconnu »), assets des formulaires non chargés.
 * NE PAS « corriger » en recopiant le pack Altitude Révision.
 */

/** Relations JetEngine (lDHOLS_jet_post_types, status=relation). */
const GACCT_AERO_RELATIONS = array(
	'revision_to_occupation' => 11, // revision (parent) <-> occupation_atelier (child), 1-1
	'revision_to_order'      => 12, // revision (parent) <-> commande WooCommerce (child), 1-1
	'client_to_revision'     => 13, // utilisateur (parent) <-> revision (child), 1-n
);

/** Glossaires JetEngine (status=glossary). */
const GACCT_AERO_GLOSSARIES = array(
	'marques' => 2,  // « Marque » — 32 marques de parapente
	'etats'   => 18, // « État de la commande » — libellés 0-8
);

/** Queries Query Builder alimentant le formulaire « Demande d'intervention ». */
const GACCT_AERO_DEMANDE_QUERIES = array(
	'revisions_controle' => 3,  // produits catégorie « Révisions & Contrôle » (term 48)
	'pliages_secours'    => 9,  // produits catégorie « Pliages secours » (term 50)
	'suspentes_travaux'  => 10, // produits catégorie « Suspentes & Travaux » (term 51)
	'frais_de_ports'     => 4,  // produits catégorie « Frais de port » (term 49)
);

/**
 * Produits « Supplément biplace » (catégorie masquée 55, hors queries du
 * formulaire). Créés le 10/08/2026 : les défauts du framework (689 / 1238)
 * sont les identifiants du site de référence et n'existent pas ici.
 */
const GACCT_AERO_BIPLACE_PRODUCTS = array(
	'voile'   => 472, // « Supplément biplace Voile », 10 €
	'secours' => 473, // « Supplément biplace Parachute de secours », 10 €
);

/** Produit « Devis réparation » (rend le devis complémentaire obligatoire). */
const GACCT_AERO_DEVIS_PRODUCT_IDS = array( 342 );

/**
 * Relations : id par clé stable du framework.
 * Signature : apply_filters( 'gacct_relation_id', $default, $relation_key ).
 */
add_filter( 'gacct_relation_id', function ( $default, $relation_key ) {
	return GACCT_AERO_RELATIONS[ $relation_key ] ?? $default;
}, 10, 2 );

/**
 * Glossaires : id par clé stable.
 * Signature : apply_filters( 'gacct_glossary_id', $default, $glossary_key ).
 */
add_filter( 'gacct_glossary_id', function ( $default, $glossary_key ) {
	return GACCT_AERO_GLOSSARIES[ $glossary_key ] ?? $default;
}, 10, 2 );

/**
 * Queries des prestations du formulaire de demande d'intervention.
 * Structure attendue : array<string,int> (cf. gacct_demande_queries_map()).
 */
add_filter( 'gacct_demande_queries', function ( $map ) {
	return GACCT_AERO_DEMANDE_QUERIES;
} );

/**
 * Produit(s) « demande de devis » : une commande qui en contient un rend
 * le devis complémentaire obligatoire (blocage du passage direct 3 -> 6).
 */
add_filter( 'gacct_quote_devis_product_ids', function ( $ids ) {
	return GACCT_AERO_DEVIS_PRODUCT_IDS;
} );

/**
 * Suppléments biplace : produit à ajouter au panier par type de prestation.
 * Structure attendue : array{ voile:int, secours:int } (cf.
 * gacct_biplace_supplement_product_ids()).
 */
add_filter( 'gacct_biplace_supplement_products', function ( $ids ) {
	return GACCT_AERO_BIPLACE_PRODUCTS;
} );

/**
 * Charte AEROTECH : surcharge des tokens --gacct-* du framework (couleurs,
 * rayons, ombres). Enqueue tardif pour passer après les feuilles du
 * framework, uniquement sur le front.
 */
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'gacct-aerotech-tokens',
		plugin_dir_url( __FILE__ ) . 'assets/css/aerotech-tokens.css',
		array(),
		'1.0.0'
	);
}, 99 );

/*
 * =====================================================================
 * E-MAILS DU WORKFLOW : COORDONNÉES ET SIGNATURE AEROTECH
 * =====================================================================
 * Les textes des 14 e-mails vivent dans les défauts du framework
 * (gacct_pay_default_settings()) et portent, faute de réglage en base, le
 * téléphone, les horaires et la signature du site de référence. On les
 * réécrit ici plutôt que de figer les 14 corps dans l'option
 * `gacct_pay_settings` : l'atelier garde la main sur les textes depuis
 * l'admin, et les futures améliorations de rédaction du framework
 * continuent d'arriver toutes seules.
 *
 * ⚠ SIGNATURE : le framework signe « Bastien. » (prénom de l'atelier de
 * référence). Faute de consigne, on signe ici au nom de l'équipe. À
 * remplacer par le prénom qui doit apparaître au bas des e-mails clients.
 */

/** Signature apposée au bas des e-mails clients. */
const GACCT_AERO_MAIL_SIGNATURE = 'L\'équipe AEROTECH.';
/**
 * Signature d'équipe du framework (gacct_team_signature(), 13/09/2026) :
 * « L’équipe Aerotech » d'après le nom du site devient « L'équipe AEROTECH »
 * partout (notifications d'état, paiements, relances, rappels de pliage).
 */
add_filter( 'gacct_team_signature', function () {
	return rtrim( GACCT_AERO_MAIL_SIGNATURE, '.' );
} );

add_filter( 'gacct_pay_default_settings', function ( $defauts ) {
	$defauts['contact_phone'] = '06 20 89 91 31';
	$defauts['contact_hours'] = 'du mardi au vendredi de 9 h à 12 h et de 14 h à 18 h, le samedi de 9 h à 17 h';

	// Depuis le 13/09/2026 le framework signe « L’équipe <nom du site> »
	// (gacct_team_signature()) : on l'aligne sur la casse de la marque.
	$equipe = function_exists( 'gacct_team_signature' ) ? gacct_team_signature() : 'L’équipe Aerotech';

	foreach ( $defauts['emails'] as $cle => $mail ) {
		if ( ! empty( $mail['body'] ) ) {
			$defauts['emails'][ $cle ]['body'] = str_replace(
				array( 'Bastien.', $equipe . '.', $equipe ),
				array( GACCT_AERO_MAIL_SIGNATURE, GACCT_AERO_MAIL_SIGNATURE, rtrim( GACCT_AERO_MAIL_SIGNATURE, '.' ) ),
				$mail['body']
			);
		}
	}

	return $defauts;
}, 99 );

/*
 * =====================================================================
 * ÉCRAN DE CONNEXION (gacct-login-ui.php) : pages légales du site
 * =====================================================================
 * La ligne « En continuant, vous acceptez… » pointe par défaut vers les
 * slugs du site de référence. Ici : CGV sur /cgv/, et faute de page de
 * confidentialité distincte, les mentions légales (à remplacer par une
 * vraie politique de confidentialité quand le client en fournira une).
 */
add_filter( 'gacct_login_legal_pages', function ( $pages ) {
	$pages['cgv']  = 'cgv';
	$pages['conf'] = 'mentions-legales';
	return $pages;
} );
/*
 * =====================================================================
 * LIVRAISON : SÉPARER LE TUNNEL ATELIER DE CELUI DE LA BOUTIQUE
 * =====================================================================
 * Contrairement au site de référence, AEROTECH a DEUX tunnels qui partagent
 * les mêmes réglages WooCommerce : la boutique (page 288) et l'atelier
 * (page 374). Leurs besoins de livraison sont opposés.
 *
 *  - Atelier : le retour du colis est déjà facturé par un PRODUIT
 *    (« Colis X kg », choisi à l'étape 3 du formulaire). Proposer en plus
 *    « Livraison express 24 € » le facturerait deux fois. On ne laisse donc
 *    qu'une seule méthode, gratuite : il n'y a rien à choisir, et
 *    at_checkout_shipping_is_choice() masque d'elle-même la carte
 *    « Livraison » et la ligne du résumé.
 *
 *  - Boutique : elle garde exactement son offre (offerte dès 500 €, express,
 *    retrait à Vence). La méthode gratuite de l'atelier en est retirée, sans
 *    quoi tout achat boutique partirait en livraison offerte.
 *
 * La méthode « Envoi géré par l'atelier » existe dans TOUTES les zones, y
 * compris « Reste du monde » : WooCommerce ne rend les champs d'adresse de
 * livraison que si au moins une méthode est active, et une zone sans méthode
 * empêche purement et simplement ses clients de commander.
 *
 * A TRANCHER AVEC LE CLIENT : la boutique n'a toujours aucune méthode payante
 * hors France. Un client belge, suisse ou monégasque ne peut pas acheter de
 * matériel ; il peut, lui, faire réviser le sien.
 */

/** Titre exact de la méthode réservée au tunnel de l'atelier. */
const GACCT_AERO_SHIPPING_ATELIER = "Envoi géré par l'atelier";

/** Catégories de produits qui signent une commande d'atelier. */
const GACCT_AERO_CATS_ATELIER = array( 48, 49, 50, 51, 52, 55 );

/**
 * Le panier courant est-il une commande d'atelier (prestations) ?
 */
function gacct_aero_panier_est_atelier() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return false;
	}

	foreach ( WC()->cart->get_cart() as $ligne ) {
		$pid = ! empty( $ligne['product_id'] ) ? (int) $ligne['product_id'] : 0;
		if ( $pid && has_term( GACCT_AERO_CATS_ATELIER, 'product_cat', $pid ) ) {
			return true;
		}
	}

	return false;
}

add_filter( 'woocommerce_package_rates', function ( $rates ) {
	$atelier = gacct_aero_panier_est_atelier();
	$garde   = array();

	foreach ( $rates as $cle => $rate ) {
		if ( $atelier === ( GACCT_AERO_SHIPPING_ATELIER === $rate->get_label() ) ) {
			$garde[ $cle ] = $rate;
		}
	}

	/* Filet de sécurité, pour le tunnel de l'atelier UNIQUEMENT : ne jamais le
	   laisser sans tarif, ce serait « Aucun mode d'expédition disponible » et la
	   commande bloquée. Côté boutique on ne rattrape RIEN : hors de France il
	   n'existe aucune méthode payante, et laisser passer la méthode gratuite de
	   l'atelier reviendrait à expédier un parapente à l'autre bout du monde sans
	   frais. Tant que le client n'a pas tranché, WooCommerce affiche « aucun mode
	   d'expédition » et la commande n'est pas prise. */
	if ( ! $garde && $atelier ) {
		return $rates;
	}

	return $garde;
}, 20 );

/*
 * =====================================================================
 * REMPLACEMENT DU WIDGET jet-myaccount-content (JetWooBuilder absent)
 * =====================================================================
 * Sur le site de référence, la page « Mon compte » (289 ici) affiche le
 * détail d'une commande via le widget Elementor `jet-myaccount-content`
 * de JetWooBuilder. AEROTECH n'embarque pas JetWooBuilder : le widget
 * était silencieusement ignoré et /mon-compte/view-order/{id}/ rendait
 * une page vide (constat recette du 02/08/2026).
 *
 * Le shortcode ci-dessous rend la même chose que le widget : le contenu
 * du endpoint Mon compte courant (dont notre template view-order.php via
 * l'override wc_get_template de gacct-vieworder.php). Il est posé dans la
 * page 289 à la place du widget jet-myaccount-content, avec la même
 * condition de visibilité (%gacct_dash_is_order_detail%).
 */
add_shortcode( 'gacct_aero_myaccount_content', function () {
	if ( ! function_exists( 'WC' ) ) {
		return '';
	}

	ob_start();
	echo '<div class="woocommerce">';
	do_action( 'woocommerce_account_content' );
	echo '</div>';

	return ob_get_clean();
} );

/*
 * =====================================================================
 * TUNNEL ATELIER /commander/ (page 374) = un checkout WooCommerce
 * =====================================================================
 * Le formulaire de demande redirige vers la page 374 (Elementor, shortcode
 * [woocommerce_checkout]) et non vers la page « Commande » (288) déclarée
 * dans WooCommerce, réservée à la boutique. Sans ce filtre, is_checkout()
 * est faux sur /commander/ : le thème n'y chargeait ni at-checkout.css ni
 * les scripts du tunnel (constat de la recette du 13/09/2026, page rendue
 * sans habillage).
 */
add_filter( 'woocommerce_is_checkout', function ( $is ) {
	return $is || is_page( 'commander' );
} );

/*
 * =====================================================================
 * FONCTIONNEMENT DE L'ATELIER AEROTECH (14/10/2026)
 * =====================================================================
 * Décisions de PY (via Bastien, 14/10/2026), portées par les réglages
 * « Fonctionnement de l'atelier » du socle :
 *  - aucun acompte : tout est réglé à la fin, par virement exclusivement ;
 *  - aucune annulation automatique : PY annule à la main (récap du matin) ;
 *    constat : la commande boutique 5611 (voile A1, virement) a été annulée
 *    seule le 04/10 et le dossier 5848 classé « Sans suite » seul le 13/10 ;
 *  - les automatismes ne touchent jamais une commande de la boutique ;
 *  - le matériel doit arriver la veille du créneau, avant 18 h (règle
 *    historique d'AEROTECH, le socle est passé au jour même à 12 h le 30/09).
 * Le reste du bloc maintient le comportement d'AEROTECH d'avant la mise à
 * niveau du socle (version du 13/09 vers celle d'octobre 2026).
 */
add_filter( 'gacct_pay_default_settings', function ( $d ) {
	$d['payment_mode']        = 'end';
	$d['auto_cancel']         = false;
	$d['automation_scope']    = 'atelier';
	$d['deadline_day_offset'] = 1;
	$d['noshow_hour']         = 18;

	// Copies à l'atelier exactement comme avant la case « Copie à l'atelier »
	// (30/09/2026) : les modèles que le socle copiait d'office.
	$copiees = array( 'quote_accepted', 'balance_bank_details', 'noshow_release', 'missing_items', 'hold_notice', 'hold_release', 'deposit_received', 'bacs_cancel', 'unfinished_cancel', 'atelier_cancel', 'quote_refused_partial', 'quote_refused_return' );
	foreach ( $d['emails'] as $cle => $mail ) {
		if ( empty( $mail['admin_only'] ) ) {
			$d['emails'][ $cle ]['copy_admin'] = in_array( $cle, $copiees, true );
		}
	}

	// Nouveaux e-mails du socle, absents chez AEROTECH avant la mise à niveau.
	if ( isset( $d['emails']['ready_to_ship'] ) ) {
		$d['emails']['ready_to_ship']['enabled'] = false;
	}
	if ( isset( $d['emails']['pickup_delivered'] ) ) {
		$d['emails']['pickup_delivered']['enabled'] = false;
	}

	return $d;
}, 100 );

// Copie à l'atelier des notifications d'état adressées au client : avant la
// case du 30/09/2026, l'atelier les recevait toujours.
add_filter( 'option_gacct_notification_settings', function ( $s ) {
	if ( is_array( $s ) && ! empty( $s['emails'] ) && is_array( $s['emails'] ) ) {
		foreach ( $s['emails'] as $etat => $mail ) {
			if ( is_array( $mail ) && ! array_key_exists( 'copy_admin', $mail ) ) {
				$s['emails'][ $etat ]['copy_admin'] = true;
			}
		}
	}
	return $s;
} );

// Pas de section « À réexpédier » dans le récapitulatif du matin (nouveauté
// du socle, l'atelier AEROTECH suit ses expéditions autrement).
add_filter( 'gacct_lc_recap_sections', function ( $sections ) {
	return array_values( array_filter( (array) $sections, function ( $s ) {
		return 0 !== strpos( (string) ( $s['title'] ?? '' ), 'À réexpédier' );
	} ) );
} );

// Commandes : ni passage automatique en « Terminée » au règlement, ni e-mail
// « Commande terminée » réécrit en envoi de facture (AEROTECH n'émet pas de
// facture PDF) ; virement uniquement, pas de bascule vers la carte.
add_filter( 'gacct_complete_order_when_paid', '__return_false' );
add_action( 'plugins_loaded', function () {
	remove_filter( 'wc_get_template', 'gacct_oc_completed_template', 10 );
	remove_filter( 'woocommerce_email_subject_customer_completed_order', 'gacct_oc_completed_subject', 20 );
	remove_filter( 'woocommerce_email_heading_customer_completed_order', 'gacct_oc_completed_heading', 20 );
	remove_filter( 'woocommerce_valid_order_statuses_for_payment', 'gacct_pay_bacs_allow_card_switch', 20 );
	remove_filter( 'woocommerce_available_payment_gateways', 'gacct_pay_bacs_switch_gateways', 20 );
}, 20 );
add_action( 'wp_head', function () {
	echo '<style id="gacct-aero-no-card-switch">.gacct-card-switch{display:none!important}</style>';
}, 99 );
