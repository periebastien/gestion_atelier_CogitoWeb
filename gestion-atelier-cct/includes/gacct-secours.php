<?php
/**
 * Référentiel des parachutes de secours (marque / modèle / forme / tailles)
 * pour la recherche « quelques lettres » du bloc secours du formulaire de
 * demande d'intervention v2 (09/09/2026, demande Bastien).
 *
 * Même mécanique que gacct-voiles.php : table éditable `{prefix}gacct_secours`
 * (import CSV « ; »), export JSON statique dans uploads/gacct-secours/ servi
 * au front sans requête PHP par visiteur, régénéré à chaque import.
 *
 * Source du référentiel initial : rapports d'essai Air Turquoise et bases DHV
 * depuis 2012, complétés par les sites fabricants et l'historique de
 * l'atelier (donnees-claude/secours/referentiel-secours.csv, validé par
 * Bastien le 09/09/2026). Le client qui ne trouve pas son secours garde la
 * saisie libre (« Mon secours n'est pas dans la liste »).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function gacct_secours_db_version() {
	return 1;
}

function gacct_secours_table() {
	global $wpdb;
	return $wpdb->prefix . 'gacct_secours';
}

add_action( 'admin_init', 'gacct_secours_maybe_install' );

/**
 * Crée / met à niveau la table (dbDelta), pilotée par l'option de version.
 */
function gacct_secours_maybe_install() {
	if ( (int) get_option( 'gacct_secours_db_version', 0 ) >= gacct_secours_db_version() ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$table   = gacct_secours_table();

	dbDelta(
		"CREATE TABLE {$table} (
			id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
			marque VARCHAR(100) NOT NULL DEFAULT '',
			modele VARCHAR(150) NOT NULL DEFAULT '',
			forme VARCHAR(30) NOT NULL DEFAULT '',
			tailles VARCHAR(255) NOT NULL DEFAULT '',
			annees VARCHAR(20) NOT NULL DEFAULT '',
			source VARCHAR(120) NOT NULL DEFAULT '',
			remarque TEXT NULL,
			actif TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY marque_modele (marque, modele),
			KEY actif (actif)
		) {$charset};"
	);

	update_option( 'gacct_secours_db_version', gacct_secours_db_version() );
}

/**
 * Import d'un CSV « ; » (colonnes : marque;modele;forme;tailles;annees_homologation;source;vu_historique;remarque).
 * Upsert par (marque, modèle). Relançable.
 *
 * @param string $chemin Fichier CSV (BOM accepté).
 * @param bool   $vider  Vider la table avant import.
 * @return array{lues:int,ecrites:int,erreur:string}
 */
function gacct_secours_importer_csv( $chemin, $vider = false ) {
	global $wpdb;

	$stats = array( 'lues' => 0, 'ecrites' => 0, 'erreur' => '' );

	if ( ! is_readable( $chemin ) ) {
		$stats['erreur'] = 'fichier illisible';
		return $stats;
	}

	gacct_secours_maybe_install();
	$table = gacct_secours_table();

	$f = fopen( $chemin, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( ! $f ) {
		$stats['erreur'] = 'ouverture impossible';
		return $stats;
	}

	$entete = fgetcsv( $f, 0, ';' );
	if ( ! $entete ) {
		fclose( $f ); // phpcs:ignore
		$stats['erreur'] = 'entête absente';
		return $stats;
	}
	$entete = array_map( static function ( $v ) {
		return strtolower( trim( str_replace( "\xEF\xBB\xBF", '', (string) $v ) ) );
	}, $entete );
	$idx = array_flip( $entete );
	if ( ! isset( $idx['marque'], $idx['modele'] ) ) {
		fclose( $f ); // phpcs:ignore
		$stats['erreur'] = 'colonnes marque / modele absentes';
		return $stats;
	}

	if ( $vider ) {
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
	}

	$col = static function ( $l, $nom ) use ( $idx ) {
		return isset( $idx[ $nom ] ) ? trim( (string) ( $l[ $idx[ $nom ] ] ?? '' ) ) : '';
	};

	while ( ( $l = fgetcsv( $f, 0, ';' ) ) !== false ) {
		$marque = $col( $l, 'marque' );
		$modele = $col( $l, 'modele' );
		if ( '' === $marque || '' === $modele ) {
			continue;
		}
		$stats['lues']++;
		$data = array(
			'marque'   => mb_substr( $marque, 0, 100 ),
			'modele'   => mb_substr( $modele, 0, 150 ),
			'forme'    => mb_substr( $col( $l, 'forme' ), 0, 30 ),
			'tailles'  => mb_substr( $col( $l, 'tailles' ), 0, 255 ),
			'annees'   => mb_substr( $col( $l, 'annees_homologation' ) ?: $col( $l, 'annees' ), 0, 20 ),
			'source'   => mb_substr( $col( $l, 'source' ), 0, 120 ),
			'remarque' => $col( $l, 'remarque' ),
			'actif'    => 1,
		);
		$ok = $wpdb->query( $wpdb->prepare( // phpcs:ignore
			"INSERT INTO {$table} (marque, modele, forme, tailles, annees, source, remarque, actif) VALUES (%s,%s,%s,%s,%s,%s,%s,1)
			 ON DUPLICATE KEY UPDATE forme=VALUES(forme), tailles=VALUES(tailles), annees=VALUES(annees), source=VALUES(source), remarque=VALUES(remarque), actif=1",
			$data['marque'], $data['modele'], $data['forme'], $data['tailles'], $data['annees'], $data['source'], $data['remarque']
		) );
		if ( false !== $ok ) {
			$stats['ecrites']++;
		}
	}
	fclose( $f ); // phpcs:ignore

	gacct_secours_invalider_export();

	return $stats;
}

/**
 * Ordre naturel des tailles : nombres croissants, puis XXS < XS < S < M < L < XL < XXL < Bi / Tandem.
 */
function gacct_secours_trier_tailles( $a, $b ) {
	$rang = static function ( $t ) {
		$t = strtoupper( trim( (string) $t ) );
		if ( preg_match( '/^(\d+)/', $t, $m ) ) {
			return array( 0, (int) $m[1], $t );
		}
		$lettres = array( 'XXS' => 1, 'XS' => 2, 'S' => 3, 'SM' => 4, 'M' => 5, 'ML' => 6, 'L' => 7, 'XL' => 8, 'XXL' => 9, 'BI' => 20, 'TANDEM' => 21 );
		$cle     = preg_replace( '/[^A-Z]/', '', explode( ' ', $t )[0] );
		return array( 1, $lettres[ $cle ] ?? 15, $t );
	};
	return $rang( $a ) <=> $rang( $b );
}

/* =============================================================================
 *  EXPORT JSON (front)
 * ============================================================================= */

/**
 * URL du JSON courant ('' si la table est vide ou absente).
 */
function gacct_secours_json_url() {
	$rev    = (int) get_option( 'gacct_secours_rev', 0 );
	$stocke = get_option( 'gacct_secours_json', array() );
	if ( is_array( $stocke ) && ( $stocke['rev'] ?? -1 ) === $rev && ! empty( $stocke['url'] ) ) {
		return (string) $stocke['url'];
	}
	return gacct_secours_regenerer_export();
}

function gacct_secours_invalider_export() {
	update_option( 'gacct_secours_rev', (int) get_option( 'gacct_secours_rev', 0 ) + 1, false );
}

/**
 * (Re)génère le JSON : { secours: [[marque, modele, forme, [tailles]]], marques: [] }.
 *
 * @return string URL ('' si échec).
 */
function gacct_secours_regenerer_export() {
	global $wpdb;

	$table = gacct_secours_table();
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return '';
	}

	$rows = $wpdb->get_results( "SELECT marque, modele, forme, tailles FROM {$table} WHERE actif = 1 ORDER BY marque, modele", ARRAY_A ); // phpcs:ignore
	if ( ! $rows ) {
		return '';
	}

	$secours = array();
	$marques = array();
	foreach ( $rows as $row ) {
		$tailles = array_values( array_filter( array_map( 'trim', explode( ',', (string) $row['tailles'] ) ), 'strlen' ) );
		usort( $tailles, 'gacct_secours_trier_tailles' );
		$secours[] = array( (string) $row['marque'], (string) $row['modele'], (string) $row['forme'], $tailles );
		$marques[ $row['marque'] ] = true;
	}
	$marques = array_keys( $marques );
	usort( $marques, static function ( $a, $b ) {
		return strcasecmp( remove_accents( $a ), remove_accents( $b ) );
	} );

	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'gacct-secours';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$json = wp_json_encode( array( 'secours' => $secours, 'marques' => $marques ), JSON_UNESCAPED_UNICODE );
	$hash = substr( md5( $json ), 0, 10 );
	$file = $dir . '/secours-' . $hash . '.json';

	if ( ! file_exists( $file ) && false === file_put_contents( $file, $json ) ) { // phpcs:ignore
		return '';
	}
	foreach ( (array) glob( $dir . '/secours-*.json' ) as $old ) {
		if ( $old !== $file ) {
			@unlink( $old ); // phpcs:ignore
		}
	}

	$url = trailingslashit( $uploads['baseurl'] ) . 'gacct-secours/secours-' . $hash . '.json';
	update_option( 'gacct_secours_json', array( 'rev' => (int) get_option( 'gacct_secours_rev', 0 ), 'url' => $url ), false );

	return $url;
}
