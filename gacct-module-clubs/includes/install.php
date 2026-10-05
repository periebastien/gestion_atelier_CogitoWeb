<?php
/**
 * Installation versionnée : tables du module et colonnes CCT.
 *
 * Tables :
 *   {prefix}gacct_clubs               le club (nom, adresse, e-mail) ;
 *   {prefix}gacct_club_lots           chaque commande groupée ;
 *   {prefix}gacct_club_responsables   (plus utilisée depuis le 05/10/2026 : la commande groupée appartient à son demandeur).
 *
 * Colonnes CCT ajoutées (déclarées aussi dans JetEngine) :
 *   revision.club_lot_id              dossier d'un pilote inscrit via le club ;
 *   occupation_atelier.club_lot_id    réserve du lot OU occupation d'un pilote ;
 *   occupation_atelier.hors_capacite  '1' = ne compte pas dans la capacité
 *                                     (occupations des pilotes : la réserve
 *                                     compte à leur place).
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

function gacct_clubs_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'gacct_' . $name;
}

add_action( 'init', 'gacct_clubs_maybe_install', 6 );

function gacct_clubs_maybe_install() {
	if ( get_option( 'gacct_clubs_db_version' ) === GACCT_CLUBS_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$clubs   = gacct_clubs_table( 'clubs' );
	$lots    = gacct_clubs_table( 'club_lots' );
	$resp    = gacct_clubs_table( 'club_responsables' );

	dbDelta( "CREATE TABLE {$clubs} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		nom VARCHAR(190) NOT NULL DEFAULT '',
		adresse TEXT NULL,
		email VARCHAR(190) NOT NULL DEFAULT '',
		notes TEXT NULL,
		created DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY nom (nom)
	) {$charset};" );

	dbDelta( "CREATE TABLE {$lots} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		club_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		code VARCHAR(32) NOT NULL DEFAULT '',
		statut VARCHAR(20) NOT NULL DEFAULT 'demande',
		demandeur_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		contact_nom VARCHAR(190) NOT NULL DEFAULT '',
		contact_tel VARCHAR(40) NOT NULL DEFAULT '',
		contact_email VARCHAR(190) NOT NULL DEFAULT '',
		nb_ip INT(11) NOT NULL DEFAULT 0,
		nb_rp INT(11) NOT NULL DEFAULT 0,
		nb_secours INT(11) NOT NULL DEFAULT 0,
		periode VARCHAR(190) NOT NULL DEFAULT '',
		remarques TEXT NULL,
		heures_estimees DECIMAL(10,2) NOT NULL DEFAULT 0,
		jours LONGTEXT NULL,
		limite_inscription DATE NULL,
		limite_arrivee DATE NULL,
		inscriptions_ouvertes TINYINT(1) NOT NULL DEFAULT 0,
		rappel_envoye TINYINT(1) NOT NULL DEFAULT 0,
		code_envoye DATETIME NULL,
		envoi_transporteur VARCHAR(32) NOT NULL DEFAULT '',
		envoi_suivi VARCHAR(64) NOT NULL DEFAULT '',
		retour_transporteur VARCHAR(32) NOT NULL DEFAULT '',
		retour_suivi VARCHAR(190) NOT NULL DEFAULT '',
		facture_order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		created DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
		updated DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY code (code),
		KEY club_id (club_id),
		KEY statut (statut)
	) {$charset};" );

	dbDelta( "CREATE TABLE {$resp} (
		club_id BIGINT(20) UNSIGNED NOT NULL,
		user_id BIGINT(20) UNSIGNED NOT NULL,
		created DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (club_id,user_id),
		KEY user_id (user_id)
	) {$charset};" );

	// La clé UNIQUE sur un code vide bloquerait la 2e demande : le code vide
	// est stocké NULL-like via un code provisoire « D{id} » posé à la création.

	gacct_clubs_install_cct_fields( JWCCT_CCT_REVISION, array(
		'club_lot_id' => array( 'sql' => 'BIGINT(20) NOT NULL DEFAULT 0', 'type' => 'number', 'title' => 'Commande groupée (lot club)' ),
	) );
	gacct_clubs_install_cct_fields( defined( 'JWCCT_CCT_OCCUPATION' ) ? JWCCT_CCT_OCCUPATION : 'occupation_atelier', array(
		'club_lot_id'   => array( 'sql' => 'BIGINT(20) NOT NULL DEFAULT 0', 'type' => 'number', 'title' => 'Commande groupée (lot club)' ),
		'hors_capacite' => array( 'sql' => "VARCHAR(1) NOT NULL DEFAULT ''", 'type' => 'text', 'title' => 'Hors capacité (1 = couvert par une réserve)' ),
	) );

	update_option( 'gacct_clubs_db_version', GACCT_CLUBS_DB_VERSION );
}

/**
 * Ajoute des colonnes à une table CCT et les déclare dans JetEngine
 * (même méthode que gacct_op_install_operator_field() du socle).
 */
function gacct_clubs_install_cct_fields( $cct_slug, array $fields ) {
	global $wpdb;

	$table = $wpdb->prefix . 'jet_cct_' . $cct_slug;

	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return;
	}

	foreach ( $fields as $name => $def ) {
		if ( ! $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $name ) ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$def['sql']}" );
		}
	}

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, meta_fields FROM {$wpdb->prefix}jet_post_types WHERE slug = %s AND status = 'content-type'",
		$cct_slug
	), ARRAY_A );

	if ( ! $row ) {
		return;
	}

	$meta_fields = maybe_unserialize( $row['meta_fields'] );

	if ( ! is_array( $meta_fields ) ) {
		return;
	}

	$declared = wp_list_pluck( $meta_fields, 'name' );
	$added    = false;

	foreach ( $fields as $name => $def ) {
		if ( in_array( $name, $declared, true ) ) {
			continue;
		}
		$meta_fields[] = array(
			'type'            => $def['type'],
			'title'           => $def['title'],
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
		$added = true;
	}

	if ( ! $added ) {
		return;
	}

	$wpdb->update( $wpdb->prefix . 'jet_post_types', array( 'meta_fields' => serialize( $meta_fields ) ), array( 'id' => $row['id'] ) );

	$cache = $wpdb->prefix . 'jet_cache';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache ) ) ) {
		$wpdb->query( "DELETE FROM {$cache}" );
	}
}
