<?php
/**
 * Réglages du module (onglet Gestion Atelier > Configuration > Clubs).
 *
 * Tous les identifiants produits sont ceux du site courant : ils se règlent
 * ici, rien n'est codé en dur ailleurs dans le module.
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

function gacct_clubs_default_settings() {
	return apply_filters( 'gacct_clubs_default_settings', array(
		// Estimation des heures : produits de référence (leur durée_presta).
		'product_ip'      => 17,
		'product_rp'      => 18,
		'product_secours' => 361,
		// Retour : produit « frais de port » imposé aux pilotes (retour groupé).
		'product_retour'  => 20,
		// Inscriptions : fermeture N jours avant le 1er jour réservé.
		'close_days'      => 5,
		// Rappel au responsable N jours avant la fermeture.
		'reminder_days'   => 3,
		// Colis du club : arrivée N jours avant le 1er jour réservé.
		'parcel_days'     => 1,
		// Inscriptions au-delà de l'annoncé : signalées (pas refusées) passé cette marge.
		'quota_margin'    => 3,
		// Remise par paliers sur le nombre de VOILES (les secours ne comptent pas).
		'tiers'           => array(
			array( 'min' => 5, 'rate' => 10 ),
			array( 'min' => 10, 'rate' => 12 ),
			array( 'min' => 20, 'rate' => 15 ),
		),
		// Prestations remisées (catégories) ; produits exclus de la remise.
		'remise_cats'     => array( 'revisions-controle', 'pliages-secours' ),
		'remise_exclude'  => array( 1239 ),
		// Catégorie qui compte comme « une voile » pour le palier.
		'voile_cats'      => array( 'revisions-controle' ),
		// Pages.
		'request_page'    => 'commande-groupee',
		'account_slug'    => 'mon-club',
		'demande_page'    => 'demande-intervention',
	) );
}

function gacct_clubs_settings() {
	static $cache = null;

	if ( null === $cache ) {
		$saved = get_option( 'gacct_clubs_settings', array() );
		$cache = array_merge( gacct_clubs_default_settings(), is_array( $saved ) ? $saved : array() );
	}

	return $cache;
}

function gacct_clubs_setting( $key ) {
	$s = gacct_clubs_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

/**
 * Taux de remise (en %) pour un nombre de voiles.
 */
function gacct_clubs_rate_for( $nb_voiles ) {
	$rate  = 0;
	$tiers = (array) gacct_clubs_setting( 'tiers' );

	usort( $tiers, static function ( $a, $b ) {
		return (int) $a['min'] - (int) $b['min'];
	} );

	foreach ( $tiers as $tier ) {
		if ( (int) $nb_voiles >= (int) $tier['min'] ) {
			$rate = (float) $tier['rate'];
		}
	}

	return $rate;
}

/**
 * Palier suivant (pour l'incitation affichée au responsable), ou null.
 *
 * @return array|null { min, rate, missing }
 */
function gacct_clubs_next_tier( $nb_voiles ) {
	$tiers = (array) gacct_clubs_setting( 'tiers' );

	usort( $tiers, static function ( $a, $b ) {
		return (int) $a['min'] - (int) $b['min'];
	} );

	foreach ( $tiers as $tier ) {
		if ( (int) $nb_voiles < (int) $tier['min'] ) {
			return array( 'min' => (int) $tier['min'], 'rate' => (float) $tier['rate'], 'missing' => (int) $tier['min'] - (int) $nb_voiles );
		}
	}

	return null;
}

function gacct_clubs_page_url( $key ) {
	$slug = (string) gacct_clubs_setting( $key );
	$page = get_page_by_path( $slug );
	return $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
}

function gacct_clubs_account_url( $lot_id = 0 ) {
	$url = trailingslashit( home_url( '/mon-compte/' . gacct_clubs_setting( 'account_slug' ) . '/' ) );
	return $lot_id ? add_query_arg( 'lot', absint( $lot_id ), $url ) : $url;
}

/* -----------------------------------------------------------------------------
 * Onglet de configuration
 * -------------------------------------------------------------------------- */

add_filter( 'gacct_config_tabs', static function ( $tabs ) {
	$tabs['clubs'] = array( __( 'Clubs', 'gacct-module-clubs' ), 'gacct_clubs_render_config_tab' );
	return $tabs;
} );

add_action( 'admin_init', 'gacct_clubs_save_config' );

function gacct_clubs_save_config() {
	if ( empty( $_POST['gacct_clubs_config_nonce'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	check_admin_referer( 'gacct_clubs_config', 'gacct_clubs_config_nonce' );

	$in  = wp_unslash( $_POST );
	$out = array();

	foreach ( array( 'product_ip', 'product_rp', 'product_secours', 'product_retour', 'close_days', 'reminder_days', 'parcel_days', 'quota_margin' ) as $k ) {
		$out[ $k ] = isset( $in[ $k ] ) ? absint( $in[ $k ] ) : 0;
	}

	$tiers = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$min  = isset( $in['tier_min'][ $i ] ) ? absint( $in['tier_min'][ $i ] ) : 0;
		$rate = isset( $in['tier_rate'][ $i ] ) ? (float) str_replace( ',', '.', $in['tier_rate'][ $i ] ) : 0;
		if ( $min > 0 && $rate > 0 ) {
			$tiers[] = array( 'min' => $min, 'rate' => $rate );
		}
	}
	$out['tiers'] = $tiers;

	$split = static function ( $v, $int = false ) {
		$parts = array_filter( array_map( 'trim', explode( ',', (string) $v ) ) );
		return array_values( $int ? array_filter( array_map( 'absint', $parts ) ) : array_map( 'sanitize_title', $parts ) );
	};
	$out['remise_cats']    = $split( $in['remise_cats'] ?? '' );
	$out['voile_cats']     = $split( $in['voile_cats'] ?? '' );
	$out['remise_exclude'] = $split( $in['remise_exclude'] ?? '', true );

	update_option( 'gacct_clubs_settings', $out );

	wp_safe_redirect( add_query_arg( array( 'page' => 'gacct-config', 'tab' => 'clubs', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}

function gacct_clubs_render_config_tab() {
	$s = gacct_clubs_settings();

	if ( ! empty( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success"><p>Réglages des clubs enregistrés.</p></div>';
	}

	$prod = static function ( $id ) {
		$p = $id ? wc_get_product( $id ) : null;
		return $p ? esc_html( $p->get_name() ) . ' · ' . esc_html( (string) get_post_meta( $id, 'duree_presta', true ) ) . ' h' : '<em>produit introuvable</em>';
	};

	echo '<form method="post">';
	wp_nonce_field( 'gacct_clubs_config', 'gacct_clubs_config_nonce' );
	echo '<h2>Estimation des heures</h2><p>La durée de chaque produit de référence (champ « Durée » de la fiche produit) sert à estimer le temps d’une commande groupée.</p><table class="form-table">';
	foreach ( array( 'product_ip' => 'Inspection partielle', 'product_rp' => 'Révision périodique', 'product_secours' => 'Pliage de secours' ) as $k => $label ) {
		printf( '<tr><th><label for="%1$s">%2$s</label></th><td><input type="number" min="0" class="small-text" id="%1$s" name="%1$s" value="%3$d"> <span class="description">%4$s</span></td></tr>', esc_attr( $k ), esc_html( $label ), (int) $s[ $k ], $prod( (int) $s[ $k ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</table>';

	echo '<h2>Retour groupé</h2><table class="form-table">';
	printf( '<tr><th><label for="product_retour">Produit de retour imposé aux pilotes</label></th><td><input type="number" min="0" class="small-text" id="product_retour" name="product_retour" value="%d"> <span class="description">%s. Le port du retour groupé est ajouté une seule fois sur la facture du club.</span></td></tr>', (int) $s['product_retour'], $prod( (int) $s['product_retour'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</table>';

	echo '<h2>Délais</h2><table class="form-table">';
	$delays = array(
		'close_days'    => array( 'Fermeture des inscriptions', 'jours avant le premier jour réservé' ),
		'reminder_days' => array( 'Rappel au responsable', 'jours avant la fermeture des inscriptions' ),
		'parcel_days'   => array( 'Arrivée du colis du club', 'jours avant le premier jour réservé' ),
		'quota_margin'  => array( 'Marge sur le nombre annoncé', 'voiles au-delà de l’annoncé avant de signaler un dépassement' ),
	);
	foreach ( $delays as $k => $txt ) {
		printf( '<tr><th><label for="%1$s">%2$s</label></th><td><input type="number" min="0" class="small-text" id="%1$s" name="%1$s" value="%3$d"> <span class="description">%4$s</span></td></tr>', esc_attr( $k ), esc_html( $txt[0] ), (int) $s[ $k ], esc_html( $txt[1] ) );
	}
	echo '</table>';

	echo '<h2>Remise par paliers</h2><p>Sur le nombre de voiles du lot facturé. Les secours ne comptent pas dans le palier mais profitent du taux.</p><table class="widefat striped" style="max-width:420px"><thead><tr><th>À partir de (voiles)</th><th>Remise (%)</th></tr></thead><tbody>';
	for ( $i = 0; $i < 5; $i++ ) {
		$t = isset( $s['tiers'][ $i ] ) ? $s['tiers'][ $i ] : array( 'min' => '', 'rate' => '' );
		printf( '<tr><td><input type="number" min="0" class="small-text" name="tier_min[%1$d]" value="%2$s"></td><td><input type="text" class="small-text" name="tier_rate[%1$d]" value="%3$s"></td></tr>', $i, esc_attr( (string) $t['min'] ), esc_attr( (string) $t['rate'] ) );
	}
	echo '</tbody></table><table class="form-table">';
	printf( '<tr><th><label for="remise_cats">Catégories remisées</label></th><td><input type="text" class="regular-text" id="remise_cats" name="remise_cats" value="%s"><p class="description">Slugs séparés par des virgules. Suppléments et réparations : aucune remise.</p></td></tr>', esc_attr( implode( ', ', (array) $s['remise_cats'] ) ) );
	printf( '<tr><th><label for="remise_exclude">Produits exclus de la remise</label></th><td><input type="text" class="regular-text" id="remise_exclude" name="remise_exclude" value="%s"><p class="description">Identifiants séparés par des virgules.</p></td></tr>', esc_attr( implode( ', ', (array) $s['remise_exclude'] ) ) );
	printf( '<tr><th><label for="voile_cats">Catégories qui comptent comme une voile</label></th><td><input type="text" class="regular-text" id="voile_cats" name="voile_cats" value="%s"></td></tr>', esc_attr( implode( ', ', (array) $s['voile_cats'] ) ) );
	echo '</table>';

	submit_button( 'Enregistrer les réglages des clubs' );
	echo '</form>';
}
