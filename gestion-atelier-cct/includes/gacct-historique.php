<?php
/**
 * Historique des révisions réalisées AVANT la plateforme (ancien site).
 *
 * Volontairement hors CCT JetEngine : un dossier d'archive n'a ni commande
 * WooCommerce, ni créneau atelier, ni rapport au format actuel. Le faire passer
 * pour une révision normale imposerait des conditions « si c'est une archive »
 * dans tout le plugin, alors que la donnée est figée et en lecture seule.
 * Table dédiée à colonnes typées et indexées, sur le modèle de `gacct_voiles`.
 *
 * Les PDF vivent dans le coffre protégé (`atelier_secure_vault_x9f2/archives/`,
 * `Require all denied`) et ne sont servis que par l'endpoint authentifié
 * `/?gacct_archive=<id>`, calqué sur celui des rapports : opérateur, ou client
 * propriétaire du dossier, sinon 403. Changer l'identifiant dans l'URL ne donne
 * rien.
 *
 * White-label : chaque atelier importe SON historique, tout est filtrable
 * (`gacct_historique_*`).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
 *  TABLE
 * ============================================================================= */

/**
 * Version du schéma : incrémentée à chaque évolution de la table.
 */
function gacct_historique_db_version() {
	return 4;
}

function gacct_historique_table() {
	global $wpdb;
	return $wpdb->prefix . 'gacct_historique';
}

/**
 * Crée / met à niveau la table (dbDelta), pilotée par l'option de version.
 */
function gacct_historique_maybe_install() {
	if ( (int) get_option( 'gacct_historique_db_version', 0 ) >= gacct_historique_db_version() ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$table   = gacct_historique_table();

	dbDelta(
		"CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			ancien_id INT(11) NOT NULL DEFAULT 0,
			ancien_client_id INT(11) NOT NULL DEFAULT 0,
			date_revision DATE NULL DEFAULT NULL,
			marque VARCHAR(100) NOT NULL DEFAULT '',
			modele VARCHAR(150) NOT NULL DEFAULT '',
			taille VARCHAR(50) NOT NULL DEFAULT '',
			couleur VARCHAR(120) NOT NULL DEFAULT '',
			couleur_origine VARCHAR(255) NOT NULL DEFAULT '',
			numero_serie VARCHAR(120) NOT NULL DEFAULT '',
			ptv VARCHAR(60) NOT NULL DEFAULT '',
			montant DECIMAL(10,2) NULL DEFAULT NULL,
			rapport_fichier VARCHAR(255) NOT NULL DEFAULT '',
			facture_fichier VARCHAR(255) NOT NULL DEFAULT '',
			commentaire TEXT NULL,
			cree_le DATETIME NOT NULL,
			revision_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			type_materiel VARCHAR(20) NOT NULL DEFAULT '',
			avec_secours TINYINT(1) NOT NULL DEFAULT 0,
			secours_marque VARCHAR(100) NOT NULL DEFAULT '',
			secours_modele VARCHAR(150) NOT NULL DEFAULT '',
			secours_taille VARCHAR(50) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY ancien_id (ancien_id),
			KEY user_id (user_id),
			KEY date_revision (date_revision),
			KEY marque_modele (marque, modele),
			KEY ancien_client_id (ancien_client_id),
			KEY revision_id (revision_id),
			KEY type_materiel (type_materiel),
			KEY avec_secours (avec_secours)
		) {$charset};"
	);

	update_option( 'gacct_historique_db_version', gacct_historique_db_version() );

	// Version 3 (09/09/2026) : classement voile / parachute de secours des lignes
	// existantes (l'import le fait desormais lui-meme pour les nouvelles).
	if ( function_exists( 'gacct_historique_classer' ) ) {
		gacct_historique_classer();
	}
}
add_action( 'admin_init', 'gacct_historique_maybe_install' );

/**
 * La table existe-t-elle ? (l'historique est optionnel : un atelier neuf n'en a pas)
 */
function gacct_historique_table_exists() {
	global $wpdb;
	static $existe = null;

	if ( null === $existe ) {
		$table  = gacct_historique_table();
		$existe = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	return $existe;
}

/* =============================================================================
 *  COFFRE-FORT DES PDF
 * ============================================================================= */

if ( ! defined( 'GACCT_HISTORIQUE_DIRNAME' ) ) {
	define( 'GACCT_HISTORIQUE_DIRNAME', 'archives' );
}

if ( ! defined( 'GACCT_HISTORIQUE_QUERY_VAR' ) ) {
	define( 'GACCT_HISTORIQUE_QUERY_VAR', 'gacct_archive' );
}

/**
 * Dossier des PDF archivés, dans le coffre déjà protégé des rapports.
 *
 * @return string Chemin absolu avec slash final, '' si indisponible.
 */
function gacct_historique_dir() {
	if ( ! function_exists( 'gacct_report_vault_dir' ) ) {
		return '';
	}

	$coffre = gacct_report_vault_dir();

	if ( ! $coffre ) {
		return '';
	}

	$dir = trailingslashit( $coffre ) . GACCT_HISTORIQUE_DIRNAME;

	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return '';
	}

	// Ceinture et bretelles : le coffre parent est déjà en « Require all denied ».
	$htaccess = $dir . '/.htaccess';

	if ( ! file_exists( $htaccess ) ) {
		@file_put_contents(
			$htaccess,
			"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
			LOCK_EX
		);
	}

	return trailingslashit( $dir );
}

/* =============================================================================
 *  LECTURE
 * ============================================================================= */

/**
 * Une ligne d'historique par son identifiant.
 *
 * @param int $id Identifiant.
 * @return array<string,mixed>|null
 */
function gacct_historique_row( $id ) {
	global $wpdb;

	if ( ! gacct_historique_table_exists() ) {
		return null;
	}

	$table = gacct_historique_table();

	return $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $id ) ),
		ARRAY_A
	);
}

/**
 * Les anciennes révisions d'un client, la plus récente d'abord.
 *
 * @param int    $user_id  Client (0 = utilisateur courant).
 * @param string $recherche Filtre libre sur marque / modèle (facultatif).
 * @return array<int,array<string,mixed>>
 */
function gacct_historique_client( $user_id = 0, $recherche = '', $type = '' ) {
	global $wpdb;

	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

	if ( ! $user_id || ! gacct_historique_table_exists() ) {
		return array();
	}

	$table = gacct_historique_table();
	$sql   = "SELECT * FROM {$table} WHERE user_id = %d";
	$vals  = array( $user_id );

	$recherche = trim( (string) $recherche );

	if ( '' !== $recherche ) {
		$like   = '%' . $wpdb->esc_like( $recherche ) . '%';
		$sql   .= ' AND (marque LIKE %s OR modele LIKE %s)';
		$vals[] = $like;
		$vals[] = $like;
	}

	// Type de materiel (09/09/2026) : 'voile' ('' = non classe, traite comme
	// une voile) ou 'secours'.
	if ( 'secours' === $type ) {
		$sql .= " AND (type_materiel = 'secours' OR avec_secours = 1)";
	} elseif ( 'voile' === $type ) {
		$sql .= " AND type_materiel <> 'secours'";
	}

	$sql .= ' ORDER BY date_revision DESC, id DESC';

	return $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );
}

/* =============================================================================
 *  TYPE DE MATERIEL : VOILE OU PARACHUTE DE SECOURS (09/09/2026)
 * ============================================================================= */

/**
 * Referentiel des parachutes de secours rencontres dans l'historique (l'ancien
 * site n'avait qu'un materiel par dossier : pour un pliage, le client saisissait
 * son secours a la place de la voile). Expressions insensibles a la casse,
 * appliquees a « marque modele ». Filtrable pour completer sans toucher au code.
 *
 * ATTENTION aux homonymes : Supair « Start » est aussi un nom de sellette, les
 * motifs restent etroits. Gin « Yeti » : Bastien confirme (09/09/2026) que tous
 * les Yeti passes par l'atelier sont des secours.
 *
 * @return string[] Motifs PCRE sans delimiteurs.
 */
function gacct_historique_motifs_secours() {
	return (array) apply_filters( 'gacct_historique_motifs_secours', array(
		'secour', 'parachute', 'rescue', 'reserve', 'r[eé]serve',
		// Supair.
		'\\bshine\\b', '\\bfluid\\b', 'xtralite', 'x-?tra ?lite', 'secours start',
		// Gin (secours uniquement).
		'y[eé]ti', // tous les Yeti passes par l atelier sont des secours (Bastien, 09/09/2026)
		// Advance, Companion.
		'\\bsqr\\b', 'sqr ?light', 'sqr ?[0-9]{2,3}', '\\bcompanion\\b',
		// Adventure.
		'flex[- ]?one',
		// Apco (la marque fait aussi des voiles : motifs par modele).
		'mayday', '\\bnrg\\b', '\\bmdul\\b', '\\bul[- ]?2[0-9]\\b', 'lift ?ez',
		// Ozone, Niviuk, Nova, BGD, U-Turn, Independence, Charly, High Adventure, Skywalk, Sky, Kortel, Swing.
		'\\bangel\\b', 'octagon', 'pentagon', 'cures\\b', '\\bsecure\\b', 'protect\\b', 'ultra ?cross', 'annular',
		'diamond ?cross', 'revolution', '\\bclou\\b', 'beamer', 'pepper ?cross', 'kuik', 'escape\\b', 'rogallo',
	) );
}

/**
 * Modeles de secours reconnaissables dans un texte libre (commentaire d'un
 * dossier voile + pliage) : motif => [marque, modele]. Filtrable.
 *
 * @return array<string,array{0:string,1:string}>
 */
function gacct_historique_modeles_secours() {
	return (array) apply_filters( 'gacct_historique_modeles_secours', array(
		'yeti ?(ul|cross|light|rescue)?' => array( 'Gin', 'Yeti' ),
		'\\bshine\\b'                   => array( 'Supair', 'Shine' ),
		'\\bfluid\\b'                   => array( 'Supair', 'Fluid' ),
		'x-?tra ?lite'                    => array( 'Supair', 'Xtralite' ),
		'\\bsqr\\b'                     => array( 'Companion', 'SQR' ),
		'companion|compagnon|compagnion'  => array( 'Companion', 'SQR' ),
		'flex[- ]?one'                    => array( 'Adventure', 'Flex-One' ),
		'mayday'                          => array( 'Apco', 'Mayday' ),
		'octagon|octogon'                 => array( 'Niviuk', 'Octagon' ),
		'\\bcires\\b'                   => array( 'Niviuk', 'Cires' ),
		'pentagon'                        => array( 'Nova', 'Pentagon' ),
		'beamer'                          => array( 'High Adventure', 'Beamer' ),
		'pepper ?cross'                   => array( 'Skywalk', 'Pepper Cross' ),
		'annular'                         => array( 'Independence', 'Annular' ),
		'\\bangel\\b'                   => array( 'Ozone', 'Angel' ),
		'diamond ?cross'                  => array( 'Charly', 'Diamond Cross' ),
		'\\bplum'                         => array( 'Nervures', 'Plum' ),
		'sky ?syst[eè]me? ?[0-9]?'        => array( 'Sky Paragliders', 'Sky System' ),
		'\\bkrisis'                       => array( 'Kortel', 'Krisis' ),
		'\\bnrg\\b'                     => array( 'Apco', 'NRG' ),
		'\\bmdul\\b'                    => array( 'Apco', 'MDUL' ),
		'lift ?ez'                        => array( 'Apco', 'Lift EZ' ),
		'protect'                         => array( 'Swing', 'Protect' ),
		'back ?up x'                      => array( 'U-Turn', 'Backup X' ),
		'revolution'                      => array( 'Paramania', 'Revolution' ),
	) );
}

/**
 * Le parachute de secours cite dans un dossier VOILE (voile revisee + secours
 * plie dans la meme commande de l'ancien site). Signal : le commentaire parle de
 * pliage / secours. Le modele est nomme quand un motif connu apparait dans le
 * commentaire, sinon marque et modele restent vides (secours « non precise »).
 *
 * @param array $row Ligne (commentaire).
 * @return array{avec_secours:int,secours_marque:string,secours_modele:string,secours_taille:string}
 */
function gacct_historique_detecter_secours_associe( array $row ) {
	$vide = array( 'avec_secours' => 0, 'secours_marque' => '', 'secours_modele' => '', 'secours_taille' => '' );
	$c    = remove_accents( strtolower( (string) ( $row['commentaire'] ?? '' ) ) );

	if ( '' === $c || ! preg_match( '/\\b(secours?|pliage|repliage|parachute|rescue)\\b/u', $c ) ) {
		return $vide;
	}

	// « pas de secours », « sans secours », « secours neuf, pas besoin » : on ne
	// compte pas ; « pas de pliage » non plus.
	if ( preg_match( '/\\b(pas de|sans|aucun|pas besoin|ne pas plier|pas de pliage)\\b[^.]{0,25}\\b(secours|pliage|parachute)/u', $c ) ) {
		return $vide;
	}

	$out = $vide;
	$out['avec_secours'] = 1;

	foreach ( gacct_historique_modeles_secours() as $motif => $mm ) {
		if ( @preg_match( '/' . $motif . '/u', $c, $m ) ) {
			$out['secours_marque'] = $mm[0];
			$out['secours_modele'] = $mm[1];
			// Complement de modele colle au motif (« Yeti Cross », « SQR 120 », « Shine M »).
			if ( preg_match( '/' . $motif . '[ -]*((?:ul|cross|light|evo|lite|bi|[0-9]{2,3}|[sml]|xs|xl)\\b)?/u', $c, $m2 ) && ! empty( $m2[ count( $m2 ) - 1 ] ) ) {
				$suffixe = strtoupper( trim( $m2[ count( $m2 ) - 1 ] ) );
				if ( false === stripos( $out['secours_modele'], $suffixe ) ) {
					$out['secours_modele'] .= ' ' . $suffixe;
				}
			}
			break;
		}
	}

	return $out;
}

/**
 * Decisions manuelles (Bastien / atelier), fichier CSV « ; » :
 * ancien_id;type;secours_marque;secours_modele;secours_taille
 *  - type : voile | secours | mixte (mixte = voile + secours plie, les colonnes
 *    secours_* decrivent le secours). Une ligne vide dans type est ignoree.
 * Appliquees en dernier par gacct_historique_classer() et par l'import :
 * elles survivent au rejeu. Chemin filtrable.
 *
 * @return array<int,array<string,string>> ancien_id => decision.
 */
function gacct_historique_decisions() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache  = array();
	$chemin = (string) apply_filters( 'gacct_historique_decisions_csv', dirname( ABSPATH ) . '/donnees-claude/analyse-rapports/secours-decisions.csv' );
	if ( ! $chemin || ! is_readable( $chemin ) ) {
		return $cache;
	}
	$f = fopen( $chemin, 'r' );
	if ( ! $f ) {
		return $cache;
	}
	$entete = fgetcsv( $f, 0, ';' );
	if ( ! $entete ) {
		fclose( $f );
		return $cache;
	}
	$entete = array_map( static function ( $v ) { return strtolower( trim( (string) $v ) ); }, $entete );
	$idx    = array_flip( $entete );
	while ( ( $l = fgetcsv( $f, 0, ';' ) ) !== false ) {
		$id   = isset( $idx['ancien_id'] ) ? (int) ( $l[ $idx['ancien_id'] ] ?? 0 ) : 0;
		$type = isset( $idx['type'] ) ? strtolower( trim( (string) ( $l[ $idx['type'] ] ?? '' ) ) ) : '';
		if ( ! $id || ! in_array( $type, array( 'voile', 'secours', 'mixte' ), true ) ) {
			continue;
		}
		$cache[ $id ] = array(
			'type'           => $type,
			'secours_marque' => isset( $idx['secours_marque'] ) ? trim( (string) ( $l[ $idx['secours_marque'] ] ?? '' ) ) : '',
			'secours_modele' => isset( $idx['secours_modele'] ) ? trim( (string) ( $l[ $idx['secours_modele'] ] ?? '' ) ) : '',
			'secours_taille' => isset( $idx['secours_taille'] ) ? trim( (string) ( $l[ $idx['secours_taille'] ] ?? '' ) ) : '',
		);
	}
	fclose( $f );
	return $cache;
}

/**
 * Classement complet d'une ligne : type + secours associe, decisions manuelles
 * appliquees en dernier. Utilise par gacct_historique_classer() et l'import.
 *
 * @param array $row Ligne (ancien_id, marque, modele, commentaire, montant).
 * @return array{type_materiel:string,avec_secours:int,secours_marque:string,secours_modele:string,secours_taille:string}
 */
function gacct_historique_classer_ligne( array $row ) {
	$type = gacct_historique_detecter_type( $row );
	$sec  = 'voile' === $type ? gacct_historique_detecter_secours_associe( $row ) : array( 'avec_secours' => 0, 'secours_marque' => '', 'secours_modele' => '', 'secours_taille' => '' );

	$decisions = gacct_historique_decisions();
	$ancien_id = (int) ( $row['ancien_id'] ?? 0 );
	if ( $ancien_id && isset( $decisions[ $ancien_id ] ) ) {
		$d    = $decisions[ $ancien_id ];
		$type = 'mixte' === $d['type'] ? 'voile' : $d['type'];
		$sec  = array(
			'avec_secours'   => 'mixte' === $d['type'] ? 1 : 0,
			'secours_marque' => 'mixte' === $d['type'] ? $d['secours_marque'] : '',
			'secours_modele' => 'mixte' === $d['type'] ? $d['secours_modele'] : '',
			'secours_taille' => 'mixte' === $d['type'] ? $d['secours_taille'] : '',
		);
		// Une decision « secours » peut aussi corriger marque / modele du secours lui-meme :
		// portee par les colonnes marque / modele de la ligne, hors de ce module (import).
	}

	return array_merge( array( 'type_materiel' => $type ), $sec );
}

/**
 * Classe une ligne d'historique : 'secours' ou 'voile'.
 *
 * Deux signaux : le couple marque + modele (referentiel ci-dessus), sinon un
 * commentaire qui parle de pliage ou de secours sur une petite prestation
 * (montant <= 90 euros, le tarif d'un pliage seul) sans parler de voile.
 *
 * @param array $row Ligne (marque, modele, commentaire, montant).
 * @return string
 */
function gacct_historique_detecter_type( array $row ) {
	$texte = remove_accents( strtolower( trim( (string) ( $row['marque'] ?? '' ) . ' ' . (string) ( $row['modele'] ?? '' ) ) ) );

	foreach ( gacct_historique_motifs_secours() as $motif ) {
		if ( @preg_match( '/' . $motif . '/u', $texte ) ) {
			return 'secours';
		}
	}

	$commentaire = remove_accents( strtolower( (string) ( $row['commentaire'] ?? '' ) ) );
	$montant     = isset( $row['montant'] ) && null !== $row['montant'] ? (float) $row['montant'] : null;

	if ( '' !== $commentaire
		&& preg_match( '/\\b(pliage|repliage|secours)\\b/u', $commentaire )
		&& ! preg_match( '/\b(voil|aile\b|suspent|calage|porosit|contr[oô]le)/u', $commentaire )
		&& null !== $montant && $montant >= 40 && $montant <= 90
	) {
		return 'secours';
	}

	return 'voile';
}

/**
 * Passe de classement sur toute la table : ecrit `type_materiel` pour chaque
 * ligne (toutes si $tout, sinon seulement les lignes non classees).
 * Relancable sans risque.
 *
 * @param bool $tout Reclasser aussi les lignes deja classees.
 * @return array{lues:int,voile:int,secours:int,mixte:int,decisions:int}
 */
function gacct_historique_classer( $tout = true ) {
	global $wpdb;

	$stats = array( 'lues' => 0, 'voile' => 0, 'secours' => 0 );

	if ( ! gacct_historique_table_exists() ) {
		return $stats;
	}

	$table = gacct_historique_table();
	$where = $tout ? '1=1' : "type_materiel = ''";
	$rows  = $wpdb->get_results( "SELECT id, ancien_id, marque, modele, commentaire, montant, type_materiel, avec_secours, secours_marque, secours_modele, secours_taille FROM {$table} WHERE {$where}", ARRAY_A );
	$stats['mixte'] = 0;
	$stats['decisions'] = count( gacct_historique_decisions() );

	foreach ( (array) $rows as $row ) {
		$c = gacct_historique_classer_ligne( $row );
		$stats['lues']++;
		$stats[ $c['type_materiel'] ]++;
		if ( $c['avec_secours'] ) {
			$stats['mixte']++;
		}
		$diff = false;
		foreach ( $c as $k => $v ) {
			if ( (string) $v !== (string) $row[ $k ] ) {
				$diff = true;
			}
		}
		if ( $diff ) {
			$wpdb->update( $table, $c, array( 'id' => (int) $row['id'] ) );
		}
	}

	return $stats;
}

/**
 * Les parachutes de secours distincts de l'historique d'un client (une entree
 * par secours, la ligne la plus recente fait foi). Aucune ecriture.
 *
 * @param int $user_id Client (0 = utilisateur courant).
 * @return array<int,array<string,mixed>> marque, modele, taille, numero_serie, dernier_pliage (Y-m-d), historique_id.
 */
function gacct_historique_secours_client( $user_id = 0 ) {
	$secours = array();
	$vus     = array();

	foreach ( gacct_historique_client( $user_id, '', 'secours' ) as $row ) {
		$mixte = 'secours' !== (string) ( $row['type_materiel'] ?? '' );
		$marque = $mixte ? (string) ( $row['secours_marque'] ?? '' ) : (string) ( $row['marque'] ?? '' );
		$modele = $mixte ? (string) ( $row['secours_modele'] ?? '' ) : (string) ( $row['modele'] ?? '' );
		$taille = $mixte ? (string) ( $row['secours_taille'] ?? '' ) : (string) ( $row['taille'] ?? '' );
		$serie  = $mixte ? '' : (string) ( $row['numero_serie'] ?? '' );
		$cle    = gacct_historique_signature_voile( $marque, $modele, $taille, $serie );
		if ( '' === $cle ) {
			$cle = 'np:' . (int) $row['user_id']; // secours non precise : un seul par client
		}
		if ( isset( $vus[ $cle ] ) ) {
			continue;
		}
		$vus[ $cle ] = true;
		$secours[]   = array(
			'historique_id'  => (int) ( $row['id'] ?? 0 ),
			'marque'         => $marque,
			'modele'         => $modele,
			'taille'         => $taille,
			'numero_serie'   => $serie,
			'dernier_pliage' => (string) ( $row['date_revision'] ?? '' ),
			'precise'        => '' !== $marque || '' !== $modele,
			'avec_voile'     => $mixte,
		);
	}

	return $secours;
}

/**
 * Signature d'identité d'une voile (dédoublonnage inter-tables) : le numéro de
 * série normalisé s'il existe, sinon marque + modèle + taille normalisés. Les
 * clés portent un préfixe pour qu'un n° de série ne puisse jamais entrer en
 * collision avec un triplet.
 *
 * @param string $marque  Marque.
 * @param string $modele  Modèle.
 * @param string $taille  Taille.
 * @param string $serie   Numéro de série.
 * @return string '' si la voile n'est pas identifiable (ni série, ni marque+modèle).
 */
function gacct_historique_signature_voile( $marque, $modele, $taille, $serie ) {
	$norm = static function ( $v ) {
		return preg_replace( '/[^A-Z0-9]/', '', strtoupper( remove_accents( (string) $v ) ) );
	};

	$serie = $norm( $serie );
	if ( '' !== $serie ) {
		return 'sn:' . $serie;
	}

	$marque = $norm( $marque );
	$modele = $norm( $modele );
	if ( '' === $marque || '' === $modele ) {
		return '';
	}

	return 'mm:' . $marque . '|' . $modele . '|' . $norm( $taille );
}

/**
 * Les voiles distinctes de l'historique d'un client, prêtes pour le sélecteur
 * « Votre matériel » du formulaire de demande : une entrée par voile (la
 * révision la plus récente fait foi), même forme que
 * gacct_demande_materiels_client() + `annee` (année de dernière révision) et
 * `signature` (pour l'exclusion des voiles déjà suivies dans le nouveau
 * système, faite par l'appelant).
 *
 * Aucune écriture : la voile n'entre réellement dans le matériel du client
 * qu'à la soumission d'une demande d'intervention, qui crée un dossier normal.
 *
 * @param int $user_id Client (0 = utilisateur courant).
 * @return array<int,array<string,mixed>>
 */
function gacct_historique_materiels_client( $user_id = 0 ) {
	$materiels = array();
	$vus       = array();

	// gacct_historique_client() trie déjà de la plus récente à la plus
	// ancienne : la première occurrence d'une voile est la bonne. Une même
	// voile revient souvent avec le n° de série tantôt rempli, tantôt vide :
	// chaque ligne est donc identifiée par ses DEUX clés (n° de série, et
	// marque + modèle + taille + couleur), une correspondance suffit à
	// l'écarter. La couleur distingue deux ailes identiques d'un même club.
	foreach ( gacct_historique_client( $user_id, '', 'voile' ) as $row ) {
		$cle_sn = gacct_historique_signature_voile( '', '', '', $row['numero_serie'] ?? '' );
		$cle_mm = gacct_historique_signature_voile( $row['marque'] ?? '', $row['modele'] ?? '', $row['taille'] ?? '', '' );
		if ( '' !== $cle_mm ) {
			$cle_mm .= '|' . preg_replace( '/[^A-Z0-9]/', '', strtoupper( remove_accents( (string) ( $row['couleur'] ?? '' ) ) ) );
		}

		$cles = array_filter( array( $cle_sn, $cle_mm ) );
		if ( ! $cles || array_intersect_key( array_flip( $cles ), $vus ) ) {
			continue;
		}
		foreach ( $cles as $cle ) {
			$vus[ $cle ] = true;
		}

		$signature = '' !== $cle_sn ? $cle_sn : gacct_historique_signature_voile( $row['marque'] ?? '', $row['modele'] ?? '', $row['taille'] ?? '', '' );

		$date  = (string) ( $row['date_revision'] ?? '' );
		$annee = preg_match( '/^(\d{4})/', $date, $m ) ? $m[1] : '';

		$materiels[] = array(
			'historique_id' => (int) ( $row['id'] ?? 0 ),
			'marque'        => (string) ( $row['marque'] ?? '' ),
			'modele'        => (string) ( $row['modele'] ?? '' ),
			'numero_serie'  => (string) ( $row['numero_serie'] ?? '' ),
			'taille'        => (string) ( $row['taille'] ?? '' ),
			'couleur'       => (string) ( $row['couleur'] ?? '' ),
			'ptv'           => (string) ( $row['ptv'] ?? '' ),
			'annee'         => $annee,
			'signature'     => $signature,
		);
	}

	return $materiels;
}

/**
 * Nombre d'anciennes révisions d'un client (pour n'afficher l'onglet que s'il y en a).
 *
 * @param int $user_id Client (0 = utilisateur courant).
 * @return int
 */
function gacct_historique_compte( $user_id = 0 ) {
	global $wpdb;

	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

	if ( ! $user_id || ! gacct_historique_table_exists() ) {
		return 0;
	}

	$table = gacct_historique_table();

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id )
	);
}

/* =============================================================================
 *  ACCÈS + ENDPOINT
 * ============================================================================= */

/**
 * Niveau d'accès d'un utilisateur à une archive.
 *
 * Pas de condition d'état, contrairement aux rapports courants : une archive est
 * terminée par définition.
 *
 * @param array $row     Ligne d'historique.
 * @param int   $user_id Utilisateur (0 = courant).
 * @return string 'operator', 'owner' ou '' (refusé).
 */
function gacct_historique_access( array $row, $user_id = 0 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$access  = '';

	$is_operator = user_can( $user_id, defined( 'GACCT_OP_CAP' ) ? GACCT_OP_CAP : 'gacct_operate' )
		|| user_can( $user_id, 'manage_woocommerce' );

	if ( $is_operator ) {
		$access = 'operator';
	} elseif ( $user_id && (int) $row['user_id'] === $user_id ) {
		$access = 'owner';
	}

	return (string) apply_filters( 'gacct_historique_access', $access, $row, $user_id );
}

/**
 * URL de téléchargement authentifiée d'une archive.
 *
 * @param int  $id             Ligne d'historique.
 * @param bool $force_download Disposition « attachment » plutôt qu'« inline ».
 * @return string
 */
function gacct_historique_download_url( $id, $force_download = false ) {
	$id = absint( $id );

	if ( ! $id ) {
		return '';
	}

	$args = array( GACCT_HISTORIQUE_QUERY_VAR => $id );

	if ( $force_download ) {
		$args['dl'] = 1;
	}

	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Nom de fichier proposé au téléchargement.
 *
 * Le nom d'origine porte le patronyme du client (« …-1614-perie-ozone-delta4-ml.pdf ») :
 * on en reconstruit un propre à partir des données du dossier.
 *
 * @param array $row Ligne d'historique.
 * @return string
 */
function gacct_historique_filename( array $row ) {
	$morceaux = array( 'rapport' );

	if ( ! empty( $row['date_revision'] ) ) {
		$morceaux[] = substr( (string) $row['date_revision'], 0, 4 );
	}

	foreach ( array( 'marque', 'modele', 'taille' ) as $champ ) {
		if ( ! empty( $row[ $champ ] ) ) {
			$morceaux[] = $row[ $champ ];
		}
	}

	$nom = sanitize_file_name( implode( '-', $morceaux ) . '.pdf' );

	return apply_filters( 'gacct_historique_filename', $nom, $row );
}

/**
 * Endpoint : sert le PDF archivé après contrôle d'accès.
 */
function gacct_historique_maybe_serve() {
	if ( empty( $_GET[ GACCT_HISTORIQUE_QUERY_VAR ] ) ) {
		return;
	}

	$id = absint( wp_unslash( $_GET[ GACCT_HISTORIQUE_QUERY_VAR ] ) );

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( gacct_historique_download_url( $id ) ) );
		exit;
	}

	$row = gacct_historique_row( $id );

	if ( ! $row ) {
		wp_die( esc_html__( 'Dossier introuvable.', 'gestion-atelier-cct' ), '', array( 'response' => 404 ) );
	}

	if ( '' === gacct_historique_access( $row ) ) {
		wp_die(
			esc_html__( 'Vous n\'avez pas accès à ce rapport.', 'gestion-atelier-cct' ),
			esc_html__( 'Accès refusé', 'gestion-atelier-cct' ),
			array( 'response' => 403 )
		);
	}

	if ( empty( $row['rapport_fichier'] ) ) {
		wp_die( esc_html__( 'Aucun rapport n\'est disponible pour ce dossier.', 'gestion-atelier-cct' ), '', array( 'response' => 404 ) );
	}

	$dir = gacct_historique_dir();

	// basename() : le nom vient de la base, on interdit toute remontée de chemin.
	$fichier = $dir ? $dir . basename( (string) $row['rapport_fichier'] ) : '';

	if ( ! $fichier || ! file_exists( $fichier ) ) {
		wp_die( esc_html__( 'Fichier introuvable.', 'gestion-atelier-cct' ), '', array( 'response' => 404 ) );
	}

	do_action( 'gacct_historique_served', $row, get_current_user_id() );

	nocache_headers();

	$disposition = empty( $_GET['dl'] ) ? 'inline' : 'attachment';

	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: ' . $disposition . '; filename="' . gacct_historique_filename( $row ) . '"' );
	header( 'Content-Length: ' . filesize( $fichier ) );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow' );

	while ( ob_get_level() ) {
		ob_end_clean();
	}

	readfile( $fichier );
	exit;
}
add_action( 'template_redirect', 'gacct_historique_maybe_serve', 5 );
