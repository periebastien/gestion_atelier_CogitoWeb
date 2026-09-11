<?php
/**
 * Titres, descriptions et robots des pages publiques (11/09/2026, demande Bastien :
 * « le title des pages n'est pas du tout bon »). Aucun plugin SEO sur le site :
 * WordPress sortait « Titre d'admin · Altitude Révision » (ex. « Demande
 * intervention Front »).
 *
 * - <title> : libellé par page (filtre `ar_seo_titles`), « · Altitude Révision » en suffixe,
 *   accueil en titre complet.
 * - <meta name="description"> : par page (filtre `ar_seo_descriptions`).
 * - Open Graph minimal (titre, description, url, type, site_name).
 * - noindex sur les pages de compte, de tunnel et techniques (filtre `ar_seo_noindex`).
 *
 * Les pages sont identifiées par leur slug ; une page absente de la liste garde son
 * titre WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ar_seo_brand() {
	return apply_filters( 'ar_seo_brand', 'Altitude Révision' );
}

/** Slug → titre (sans la marque). */
function ar_seo_titles() {
	return apply_filters( 'ar_seo_titles', array(
		'accueil'                              => 'Révision de parapente, pliage de secours et réparation en Normandie',
		'controles'                            => 'Contrôle et révision de parapente selon la norme ParachecK',
		'pliages-secours'                      => 'Pliage de parachute de secours parapente et paramoteur',
		'reparations'                          => 'Réparation de voile de parapente et de paramoteur',
		'suspentes'                            => 'Remplacement et contrôle des suspentes de parapente',
		'tarifs'                               => 'Tarifs révision, pliage de secours et réparation',
		'contact'                              => 'Contact et accès à l\'atelier',
		'actualites'                           => 'Actualités de l\'atelier',
		'consignes-demballage'                 => 'Comment emballer et expédier votre parapente',
		'demande-intervention'                 => 'Demande de révision, pliage ou réparation en ligne',
		'connexion'                            => 'Connexion à votre espace client',
		'mon-compte'                           => 'Mon espace client',
		'mot-de-passe-oublie'                  => 'Mot de passe oublié',
		'sinscrire'                            => 'Créer un compte',
		'panier'                               => 'Votre panier',
		'commander'                            => 'Validation de votre demande',
		'thank-you-page'                       => 'Confirmation de votre demande',
		'devis-a-valider'                      => 'Devis à valider',
		'mentions-legales'                     => 'Mentions légales',
		'conditions-generales-de-vente'        => 'Conditions générales de vente',
		'politique-de-confidentialite'         => 'Politique de confidentialité',
	) );
}

/** Slug → meta description (150 à 160 caractères visés). */
function ar_seo_descriptions() {
	return apply_filters( 'ar_seo_descriptions', array(
		'accueil'                              => 'Atelier certifié F.F.V.L en Normandie : révision de parapente selon la norme ParachecK, pliage de parachute de secours, réparation de voile. Demande et suivi en ligne, rapport PDF.',
		'controles'                            => 'Contrôle complet de votre parapente : porosité, déchirure, suspentes, calage, visuel. Rapport ParachecK détaillé, planning en temps réel et suivi de votre voile en ligne.',
		'pliages-secours'                      => 'Pliage de parachute de secours par un atelier certifié, pour parapente et paramoteur. Rappel annuel, suivi en ligne et rapport après chaque pliage.',
		'reparations'                          => 'Réparation de voile de parapente et de paramoteur : déchirures, panneaux, joncs, suspentes. Diagnostic à l\'atelier puis devis détaillé, rien n\'est réparé sans votre accord.',
		'suspentes'                            => 'Remplacement de suspentes à l\'unité ou par groupe, contrôle de rupture et de calage selon la norme ParachecK. Demande en ligne et suivi de votre voile.',
		'tarifs'                               => 'Tous les tarifs de l\'atelier : révision ParachecK, contrôle complet équipement, pliage de secours, suspentes et réparations. Acompte en ligne, solde après intervention.',
		'contact'                              => 'Contactez l\'atelier Altitude Révision : téléphone, e-mail, adresse et horaires pour déposer ou expédier votre parapente, votre secours ou votre sellette.',
		'actualites'                           => 'Les nouvelles de l\'atelier Altitude Révision : nouveautés de la plateforme, saison, conseils d\'entretien de votre parapente et de votre parachute de secours.',
		'consignes-demballage'                 => 'Comment préparer et expédier votre parapente ou votre secours à l\'atelier : emballage, transporteur, étiquette et suivi de votre colis.',
		'demande-intervention'                 => 'Demandez votre révision, votre pliage de secours ou votre réparation en ligne en quelques minutes : choisissez vos prestations, votre date et suivez votre voile jusqu\'au retour.',
		'connexion'                            => 'Accédez à votre espace client Altitude Révision : vos demandes, votre matériel, vos rapports de révision et vos commandes.',
	) );
}

/** Pages hors index (compte, tunnel, technique). */
function ar_seo_noindex_slugs() {
	return apply_filters( 'ar_seo_noindex', array(
		'connexion', 'mon-compte', 'mot-de-passe-oublie', 'sinscrire', 'panier', 'commander',
		'thank-you-page', 'devis-a-valider', 'info-personnelle', 'demande-intervention-v1',
		'demande-intervention-v2', 'maquette-parcours-v2',
	) );
}

/** Slug de la page courante (front, pages uniquement), '' sinon. */
function ar_seo_current_slug() {
	if ( is_admin() ) {
		return '';
	}
	if ( is_front_page() ) {
		return 'accueil';
	}
	if ( is_page() ) {
		return (string) get_post_field( 'post_name', get_queried_object_id() );
	}
	return '';
}

/** Yoast SEO actif : il porte titres, descriptions, robots et Open Graph (valeurs reprises dans ses metas). */
function ar_seo_yoast_active() {
	return defined( 'WPSEO_VERSION' );
}

add_filter( 'pre_get_document_title', function ( $title ) {
	if ( ar_seo_yoast_active() ) {
		return $title;
	}
	$slug   = ar_seo_current_slug();
	$titles = ar_seo_titles();
	if ( '' === $slug || empty( $titles[ $slug ] ) ) {
		return $title;
	}
	$brand = ar_seo_brand();
	if ( 'accueil' === $slug ) {
		return $brand . ' · ' . $titles[ $slug ];
	}
	return $titles[ $slug ] . ' · ' . $brand;
}, 20 );

add_action( 'wp_head', function () {
	$slug = ar_seo_current_slug();
	if ( '' === $slug || ar_seo_yoast_active() ) {
		return;
	}
	$desc = ar_seo_descriptions();
	$noindex = in_array( $slug, ar_seo_noindex_slugs(), true );

	if ( ! empty( $desc[ $slug ] ) ) {
		echo '<meta name="description" content="' . esc_attr( $desc[ $slug ] ) . '">' . "\n";
	}
	if ( ! $noindex ) {
		$url = 'accueil' === $slug ? home_url( '/' ) : get_permalink( get_queried_object_id() );
		echo '<meta property="og:type" content="website">' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( ar_seo_brand() ) . '">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '">' . "\n";
		if ( ! empty( $desc[ $slug ] ) ) {
			echo '<meta property="og:description" content="' . esc_attr( $desc[ $slug ] ) . '">' . "\n";
		}
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
		echo '<meta property="og:locale" content="fr_FR">' . "\n";
	}
}, 2 );

// noindex via l'API robots de WordPress (une seule balise robots dans la page).
add_filter( 'wp_robots', function ( $robots ) {
	$slug = ar_seo_current_slug();
	if ( ar_seo_yoast_active() ) {
		return $robots;
	}
	if ( '' !== $slug && in_array( $slug, ar_seo_noindex_slugs(), true ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['max-image-preview'] );
	}
	return $robots;
} );
