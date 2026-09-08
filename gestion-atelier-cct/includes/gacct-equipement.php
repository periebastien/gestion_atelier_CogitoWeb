<?php
/**
 * Sellette et parachute de secours décrits par le client à la demande (08/09/2026).
 *
 * Demande de Timothée : pour un « Contrôle complet Équipement », le client doit
 * pouvoir renseigner sa sellette et son secours au moment de la demande, afin que
 * le rapport équipement soit prérempli à l'atelier.
 *
 * Ce fichier installe les 7 champs texte dans le CCT `revision` (définition
 * JetEngine + colonnes de la table, idempotent, option de version), sur le
 * modèle de gacct_report_install_field(). Le formulaire JFB 1920 porte les
 * champs du même nom (classe `gacct-v2-equip`), mappés vers le CCT dans son
 * action « Insert CCT » ; demande-v2.js ne les montre que si un produit
 * équipement (config `equipementIds`, produit 19) ou un pliage de secours est
 * coché ; le pack (paracheck-forms.php) préremplit le rapport équipement.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_EQUIP_SETUP_OPT', 'gacct_equip_setup_version' );
define( 'GACCT_EQUIP_SETUP_VERSION', '1' );

/**
 * Champs CCT ajoutés (nom => titre JetEngine).
 *
 * @return array<string,string>
 */
function gacct_equip_fields() {
	return array(
		'sellette_marque' => 'Sellette : marque',
		'sellette_modele' => 'Sellette : modèle',
		'sellette_taille' => 'Sellette : taille',
		'secours_marque'  => 'Secours : marque',
		'secours_modele'  => 'Secours : modèle',
		'secours_taille'  => 'Secours : taille',
		'secours_date'    => 'Secours : date de production',
	);
}

/**
 * Installation idempotente : colonnes + définition JetEngine.
 */
function gacct_equip_install_fields() {
	global $wpdb;

	if ( ! defined( 'JWCCT_CCT_REVISION' ) ) {
		return;
	}

	$rev_table = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_REVISION;

	foreach ( array_keys( gacct_equip_fields() ) as $name ) {
		$column = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$rev_table} LIKE %s", $name ) );
		if ( ! $column ) {
			$wpdb->query( "ALTER TABLE {$rev_table} ADD COLUMN {$name} TEXT NULL" );
		}
	}

	$cct_row = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, meta_fields FROM {$wpdb->prefix}jet_post_types WHERE slug = %s AND status = 'content-type'",
		JWCCT_CCT_REVISION
	), ARRAY_A );

	if ( ! $cct_row ) {
		return;
	}

	$meta_fields = maybe_unserialize( $cct_row['meta_fields'] );

	if ( ! is_array( $meta_fields ) ) {
		return;
	}

	$existing = wp_list_pluck( $meta_fields, 'name' );
	$changed  = false;

	foreach ( gacct_equip_fields() as $name => $title ) {
		if ( in_array( $name, $existing, true ) ) {
			continue;
		}
		$meta_fields[] = array(
			'type'            => 'text',
			'title'           => $title,
			'name'            => $name,
			'object_type'     => 'field',
			'width'           => '25%',
			'options'         => array(),
			'repeater-fields' => array(),
			'id'              => wp_rand( 100000, 999999 ),
			'isNested'        => false,
			'options_source'  => 'manual',
			'is_required'     => false,
		);
		$changed = true;
	}

	if ( $changed ) {
		$wpdb->update(
			$wpdb->prefix . 'jet_post_types',
			array( 'meta_fields' => serialize( $meta_fields ) ),
			array( 'id' => $cct_row['id'] )
		);

		$cache_table = $wpdb->prefix . 'jet_cache';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache_table ) ) ) {
			$wpdb->query( "DELETE FROM {$cache_table}" );
		}
		wp_cache_flush();
	}
}

add_action( 'init', function () {
	if ( get_option( GACCT_EQUIP_SETUP_OPT ) === GACCT_EQUIP_SETUP_VERSION ) {
		return;
	}
	gacct_equip_install_fields();
	update_option( GACCT_EQUIP_SETUP_OPT, GACCT_EQUIP_SETUP_VERSION );
}, 6 );

/**
 * Libellé lisible d'un équipement décrit (fiche console, récapitulatifs).
 *
 * @param array  $revision Ligne CCT.
 * @param string $kind     'sellette' | 'secours'.
 * @return string '' si rien de renseigné.
 */
function gacct_equip_label( array $revision, $kind ) {
	$parts = array_filter( array(
		trim( (string) ( $revision[ $kind . '_marque' ] ?? '' ) ),
		trim( (string) ( $revision[ $kind . '_modele' ] ?? '' ) ),
		trim( (string) ( $revision[ $kind . '_taille' ] ?? '' ) ),
	) );

	if ( 'secours' === $kind && ! empty( $revision['secours_date'] ) ) {
		$parts[] = sprintf( __( 'fabriqué en %s', 'gestion-atelier-cct' ), trim( (string) $revision['secours_date'] ) );
	}

	return implode( ' · ', $parts );
}

/**
 * Libellé de repli d'un dossier SANS voile (pliage de secours seul, contrôle
 * équipement) : secours puis sellette. '' si rien n'est décrit.
 */
function gacct_equip_materiel_fallback( array $row ) {
	foreach ( array( 'secours' => __( 'Secours', 'gestion-atelier-cct' ), 'sellette' => __( 'Sellette', 'gestion-atelier-cct' ) ) as $kind => $prefix ) {
		$label = gacct_equip_label( $row, $kind );
		if ( '' !== $label ) {
			return $prefix . ' ' . $label;
		}
	}
	return '';
}
