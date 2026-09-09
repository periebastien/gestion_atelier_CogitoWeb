<?php
/**
 * Référentiel des parachutes de secours (marque / modèle / forme / tailles)
 * pour la recherche « quelques lettres » du bloc secours du formulaire de
 * demande d'intervention v2 (09/09/2026, demande Bastien).
 *
 * Même mécanique que gacct-voiles.php : table éditable `{prefix}gacct_secours`
 * (import CSV « ; »), export JSON statique dans uploads/gacct-secours/ servi
 * au front sans requête PHP par visiteur, régénéré à chaque modification.
 * Onglet « Secours » de Gestion Atelier > Configuration : journal des saisies
 * hors liste (ajout en un clic avec forme et tailles), ajout / modification /
 * suppression, recherche paginée.
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
	return 2;
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

	$journal = gacct_secours_journal_table();
	dbDelta(
		"CREATE TABLE {$journal} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			marque VARCHAR(100) NOT NULL,
			modele VARCHAR(150) NOT NULL,
			taille VARCHAR(50) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			statut VARCHAR(20) NOT NULL DEFAULT 'nouveau',
			cree_le DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY statut (statut)
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

/* =============================================================================
 *  JOURNAL DES SAISIES HORS LISTE
 * ============================================================================= */

function gacct_secours_journal_table() {
	global $wpdb;
	return $wpdb->prefix . 'gacct_secours_journal';
}

/**
 * Journalise un secours saisi hors liste à la soumission de la demande
 * (mode « Mon secours n'est pas dans la liste »). Dédoublonne sur la paire
 * marque + modèle encore « nouveau ». Miroir de gacct_voiles_journaliser_hors_liste().
 *
 * @param string $marque
 * @param string $modele
 * @param string $taille Taille saisie (proposée au moment de l'ajout).
 */
function gacct_secours_journaliser_hors_liste( $marque, $modele, $taille = '' ) {
	global $wpdb;

	$marque = trim( (string) $marque );
	$modele = trim( (string) $modele );
	$taille = trim( (string) $taille );
	if ( '' === $marque || '' === $modele ) {
		return;
	}

	$table   = gacct_secours_table();
	$journal = gacct_secours_journal_table();
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$connue = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE UPPER(REPLACE(marque,' ','')) = UPPER(REPLACE(%s,' ','')) AND UPPER(REPLACE(modele,' ','')) = UPPER(REPLACE(%s,' ',''))",
		$marque, $modele
	) );
	if ( $connue ) {
		return;
	}
	$deja = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$journal} WHERE statut = 'nouveau' AND UPPER(REPLACE(marque,' ','')) = UPPER(REPLACE(%s,' ','')) AND UPPER(REPLACE(modele,' ','')) = UPPER(REPLACE(%s,' ',''))",
		$marque, $modele
	) );
	// phpcs:enable
	if ( $deja ) {
		return;
	}

	$wpdb->insert(
		$journal,
		array(
			'marque'  => mb_substr( $marque, 0, 100 ),
			'modele'  => mb_substr( $modele, 0, 150 ),
			'taille'  => mb_substr( $taille, 0, 50 ),
			'user_id' => get_current_user_id(),
			'statut'  => 'nouveau',
			'cree_le' => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%d', '%s', '%s' )
	);
}

/* =============================================================================
 *  ONGLET « SECOURS » DE GESTION ATELIER > CONFIGURATION
 * ============================================================================= */

add_filter( 'gacct_config_tabs', 'gacct_secours_config_tab' );

function gacct_secours_config_tab( $tabs ) {
	$tabs['secours'] = array( __( 'Secours', 'gestion-atelier-cct' ), 'gacct_secours_render_config_tab' );
	return $tabs;
}

function gacct_secours_admin_cap() {
	return apply_filters( 'gacct_admin_capability', 'manage_options' );
}

/**
 * Formes proposées dans les formulaires d'ajout.
 *
 * @return array<string,string>
 */
function gacct_secours_formes() {
	return array(
		'rond'       => __( 'rond', 'gestion-atelier-cct' ),
		'carré'      => __( 'carré', 'gestion-atelier-cct' ),
		'rogallo'    => __( 'rogallo (dirigeable)', 'gestion-atelier-cct' ),
		'triangle'   => __( 'triangle', 'gestion-atelier-cct' ),
		'pentagonal' => __( 'pentagonal', 'gestion-atelier-cct' ),
		'octogonal'  => __( 'octogonal', 'gestion-atelier-cct' ),
	);
}

/**
 * Nettoie une liste de tailles saisie « S, M, L » ou « 100 120 140 ».
 */
function gacct_secours_nettoyer_tailles( $texte ) {
	$texte = (string) $texte;
	// Virgules, points-virgules ou barres ; sinon (« 100 120 140 ») les espaces.
	$parts = preg_match( '/[,;\/]/', $texte ) ? preg_split( '/[,;\/]+/', $texte ) : preg_split( '/\s+/', $texte );
	$out   = array();
	foreach ( (array) $parts as $t ) {
		$t = trim( $t );
		if ( '' !== $t && ! in_array( $t, $out, true ) ) {
			$out[] = mb_substr( $t, 0, 30 );
		}
	}
	usort( $out, 'gacct_secours_trier_tailles' );
	return implode( ', ', $out );
}

/**
 * Traitement des actions POST de l'onglet (ajout, modification, suppression, journal).
 */
function gacct_secours_traiter_post() {
	if ( empty( $_POST['gacct_secours_action'] ) || ! current_user_can( gacct_secours_admin_cap() ) ) {
		return '';
	}
	check_admin_referer( 'gacct_secours' );

	global $wpdb;
	$table   = gacct_secours_table();
	$journal = gacct_secours_journal_table();
	$action  = sanitize_key( wp_unslash( $_POST['gacct_secours_action'] ) );
	$notice  = '';
	$formes  = gacct_secours_formes();

	$lire = static function () use ( $formes ) {
		$forme = sanitize_text_field( wp_unslash( $_POST['forme'] ?? '' ) );
		return array(
			'marque'  => mb_substr( sanitize_text_field( wp_unslash( $_POST['marque'] ?? '' ) ), 0, 100 ),
			'modele'  => mb_substr( sanitize_text_field( wp_unslash( $_POST['modele'] ?? '' ) ), 0, 150 ),
			'forme'   => isset( $formes[ $forme ] ) ? $forme : '',
			'tailles' => gacct_secours_nettoyer_tailles( sanitize_text_field( wp_unslash( $_POST['tailles'] ?? '' ) ) ),
		);
	};

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	switch ( $action ) {
		case 'ajouter':
		case 'journal_ajouter':
			$d = $lire();
			if ( '' === $d['marque'] || '' === $d['modele'] ) {
				$notice = __( 'Marque et modèle sont obligatoires.', 'gestion-atelier-cct' );
				break;
			}
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$table} (marque, modele, forme, tailles, annees, source, remarque, actif) VALUES (%s,%s,%s,%s,'',%s,'',1)
				 ON DUPLICATE KEY UPDATE forme = IF(VALUES(forme)<>'', VALUES(forme), forme), tailles = IF(VALUES(tailles)<>'', VALUES(tailles), tailles), actif = 1",
				$d['marque'], $d['modele'], $d['forme'], $d['tailles'], 'journal_ajouter' === $action ? 'journal' : 'manuel'
			) );
			if ( 'journal_ajouter' === $action ) {
				$id = absint( $_POST['journal_id'] ?? 0 );
				if ( $id ) {
					$wpdb->update( $journal, array( 'statut' => 'ajoute' ), array( 'id' => $id ) );
				}
				$notice = __( 'Saisie ajoutée à la liste des secours.', 'gestion-atelier-cct' );
			} else {
				$notice = __( 'Secours ajouté à la liste.', 'gestion-atelier-cct' );
			}
			gacct_secours_invalider_export();
			break;

		case 'modifier':
			$id = absint( $_POST['secours_id'] ?? 0 );
			$d  = $lire();
			if ( $id && '' !== $d['marque'] && '' !== $d['modele'] ) {
				$wpdb->update( $table, $d, array( 'id' => $id ) );
				gacct_secours_invalider_export();
				$notice = __( 'Secours mis à jour.', 'gestion-atelier-cct' );
			}
			break;

		case 'supprimer':
			$id = absint( $_POST['secours_id'] ?? 0 );
			if ( $id ) {
				$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
				gacct_secours_invalider_export();
				$notice = __( 'Secours retiré de la liste.', 'gestion-atelier-cct' );
			}
			break;

		case 'journal_ignorer':
			$id = absint( $_POST['journal_id'] ?? 0 );
			if ( $id ) {
				$wpdb->update( $journal, array( 'statut' => 'ignore' ), array( 'id' => $id ) );
				$notice = __( 'Saisie ignorée.', 'gestion-atelier-cct' );
			}
			break;
	}
	// phpcs:enable

	return $notice;
}

/**
 * Rendu de l'onglet : journal hors liste + recherche / gestion de la liste.
 * Composants WP natifs uniquement (règle design admin du 29/07/2026).
 */
function gacct_secours_render_config_tab() {
	global $wpdb;

	gacct_secours_maybe_install();

	$notice  = gacct_secours_traiter_post();
	$table   = gacct_secours_table();
	$journal = gacct_secours_journal_table();
	$formes  = gacct_secours_formes();

	$recherche = isset( $_GET['s_secours'] ) ? sanitize_text_field( wp_unslash( $_GET['s_secours'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$pagenum   = max( 1, absint( $_GET['pagenum'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$edit_id   = absint( $_GET['modifier'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$par_page  = 25;

	$where = '1=1';
	$args  = array();
	if ( '' !== $recherche ) {
		$where = '(marque LIKE %s OR modele LIKE %s)';
		$like  = '%' . $wpdb->esc_like( $recherche ) . '%';
		$args  = array( $like, $like );
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) : "SELECT COUNT(*) FROM {$table}" );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY marque, modele LIMIT %d OFFSET %d", array_merge( $args, array( $par_page, ( $pagenum - 1 ) * $par_page ) ) ), ARRAY_A );
	$en_attente = $wpdb->get_results( "SELECT * FROM {$journal} WHERE statut = 'nouveau' ORDER BY cree_le DESC LIMIT 50", ARRAY_A );
	$edit = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ), ARRAY_A ) : null;
	// phpcs:enable

	$base_url = add_query_arg( array( 'page' => 'gacct-config', 'tab' => 'secours' ), admin_url( 'admin.php' ) );

	$select_forme = static function ( $courante = '' ) use ( $formes ) {
		$html = '<select name="forme"><option value="">' . esc_html__( 'Forme…', 'gestion-atelier-cct' ) . '</option>';
		foreach ( $formes as $k => $lib ) {
			$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $courante, $k, false ) . '>' . esc_html( $lib ) . '</option>';
		}
		return $html . '</select>';
	};
	?>
	<div class="wrap">
		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Saisies hors liste à traiter', 'gestion-atelier-cct' ); ?>
			<?php if ( $en_attente ) : ?><span class="count">(<?php echo count( $en_attente ); ?>)</span><?php endif; ?>
		</h2>
		<p class="description"><?php esc_html_e( 'Les parachutes de secours que les clients ont saisis avec « Mon secours n’est pas dans la liste ». Corrigez l’orthographe si besoin, indiquez la forme et les tailles, puis ajoutez-les : la recherche du formulaire les proposera aussitôt.', 'gestion-atelier-cct' ); ?></p>
		<?php if ( ! $en_attente ) : ?>
			<p><?php esc_html_e( 'Aucune saisie en attente : les clients ont trouvé leur secours dans la liste.', 'gestion-atelier-cct' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th class="column-primary"><?php esc_html_e( 'Marque', 'gestion-atelier-cct' ); ?></th>
					<th><?php esc_html_e( 'Modèle', 'gestion-atelier-cct' ); ?></th>
					<th style="width:130px"><?php esc_html_e( 'Forme', 'gestion-atelier-cct' ); ?></th>
					<th><?php esc_html_e( 'Tailles (séparées par des virgules)', 'gestion-atelier-cct' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'Saisie le', 'gestion-atelier-cct' ); ?></th>
					<th style="width:210px"><?php esc_html_e( 'Actions', 'gestion-atelier-cct' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $en_attente as $j ) : ?>
					<tr>
						<form method="post">
							<?php wp_nonce_field( 'gacct_secours' ); ?>
							<input type="hidden" name="journal_id" value="<?php echo (int) $j['id']; ?>">
							<td class="column-primary" data-colname="Marque">
								<input type="text" name="marque" value="<?php echo esc_attr( $j['marque'] ); ?>" class="regular-text" style="max-width:140px">
								<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Détails', 'gestion-atelier-cct' ); ?></span></button>
							</td>
							<td data-colname="Modèle"><input type="text" name="modele" value="<?php echo esc_attr( $j['modele'] ); ?>" class="regular-text" style="max-width:160px"></td>
							<td data-colname="Forme"><?php echo $select_forme(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td data-colname="Tailles"><input type="text" name="tailles" value="<?php echo esc_attr( $j['taille'] ); ?>" class="regular-text" style="max-width:180px" placeholder="S, M, L"></td>
							<td data-colname="Saisie le"><?php echo esc_html( mysql2date( 'd/m/Y H:i', $j['cree_le'] ) ); ?></td>
							<td data-colname="Actions">
								<button class="button button-primary" name="gacct_secours_action" value="journal_ajouter"><?php esc_html_e( 'Ajouter à la liste', 'gestion-atelier-cct' ); ?></button>
								<button class="button" name="gacct_secours_action" value="journal_ignorer"><?php esc_html_e( 'Ignorer', 'gestion-atelier-cct' ); ?></button>
							</td>
						</form>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<hr class="wp-header-end" style="margin:24px 0">

		<h2><?php esc_html_e( 'Liste des parachutes de secours', 'gestion-atelier-cct' ); ?> <span class="count">(<?php echo (int) $total; ?>)</span></h2>

		<form method="get" class="search-box" style="margin-bottom:12px">
			<input type="hidden" name="page" value="gacct-config">
			<input type="hidden" name="tab" value="secours">
			<label class="screen-reader-text" for="s_secours"><?php esc_html_e( 'Rechercher un secours', 'gestion-atelier-cct' ); ?></label>
			<input type="search" id="s_secours" name="s_secours" value="<?php echo esc_attr( $recherche ); ?>" placeholder="<?php esc_attr_e( 'Marque ou modèle…', 'gestion-atelier-cct' ); ?>">
			<button class="button"><?php esc_html_e( 'Rechercher', 'gestion-atelier-cct' ); ?></button>
		</form>

		<?php if ( $edit ) : ?>
			<form method="post" style="margin-bottom:16px;padding:12px;background:#fff;border:1px solid #c3c4c7">
				<?php wp_nonce_field( 'gacct_secours' ); ?>
				<input type="hidden" name="secours_id" value="<?php echo (int) $edit['id']; ?>">
				<strong><?php esc_html_e( 'Modifier :', 'gestion-atelier-cct' ); ?></strong>
				<input type="text" name="marque" value="<?php echo esc_attr( $edit['marque'] ); ?>" required>
				<input type="text" name="modele" value="<?php echo esc_attr( $edit['modele'] ); ?>" required>
				<?php echo $select_forme( $edit['forme'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="text" name="tailles" value="<?php echo esc_attr( $edit['tailles'] ); ?>" placeholder="S, M, L" style="width:260px">
				<button class="button button-primary" name="gacct_secours_action" value="modifier"><?php esc_html_e( 'Enregistrer', 'gestion-atelier-cct' ); ?></button>
				<a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Annuler', 'gestion-atelier-cct' ); ?></a>
			</form>
		<?php endif; ?>

		<form method="post" style="margin-bottom:16px">
			<?php wp_nonce_field( 'gacct_secours' ); ?>
			<input type="text" name="marque" placeholder="<?php esc_attr_e( 'Marque', 'gestion-atelier-cct' ); ?>" required>
			<input type="text" name="modele" placeholder="<?php esc_attr_e( 'Modèle', 'gestion-atelier-cct' ); ?>" required>
			<?php echo $select_forme(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<input type="text" name="tailles" placeholder="<?php esc_attr_e( 'Tailles : S, M, L', 'gestion-atelier-cct' ); ?>" style="width:200px">
			<button class="button button-primary" name="gacct_secours_action" value="ajouter"><?php esc_html_e( 'Ajouter un secours', 'gestion-atelier-cct' ); ?></button>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th class="column-primary"><?php esc_html_e( 'Marque', 'gestion-atelier-cct' ); ?></th>
				<th><?php esc_html_e( 'Modèle', 'gestion-atelier-cct' ); ?></th>
				<th style="width:100px"><?php esc_html_e( 'Forme', 'gestion-atelier-cct' ); ?></th>
				<th><?php esc_html_e( 'Tailles', 'gestion-atelier-cct' ); ?></th>
				<th style="width:110px"><?php esc_html_e( 'Source', 'gestion-atelier-cct' ); ?></th>
				<th style="width:160px"><?php esc_html_e( 'Actions', 'gestion-atelier-cct' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'Aucun secours trouvé.', 'gestion-atelier-cct' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $s ) : ?>
				<tr>
					<td class="column-primary" data-colname="Marque"><strong><?php echo esc_html( $s['marque'] ); ?></strong>
						<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Détails', 'gestion-atelier-cct' ); ?></span></button>
					</td>
					<td data-colname="Modèle"><?php echo esc_html( $s['modele'] ); ?></td>
					<td data-colname="Forme"><?php echo esc_html( $formes[ $s['forme'] ] ?? $s['forme'] ); ?></td>
					<td data-colname="Tailles"><?php echo '' !== $s['tailles'] ? esc_html( $s['tailles'] ) : '<span style="color:#a7aaad">' . esc_html__( 'à compléter', 'gestion-atelier-cct' ) . '</span>'; ?></td>
					<td data-colname="Source"><?php echo esc_html( $s['source'] ); ?></td>
					<td data-colname="Actions">
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'modifier' => (int) $s['id'], 's_secours' => $recherche, 'pagenum' => $pagenum ), $base_url ) ); ?>"><?php esc_html_e( 'Modifier', 'gestion-atelier-cct' ); ?></a>
						<form method="post" style="display:inline">
							<?php wp_nonce_field( 'gacct_secours' ); ?>
							<input type="hidden" name="secours_id" value="<?php echo (int) $s['id']; ?>">
							<button class="button button-link-delete" name="gacct_secours_action" value="supprimer"
								onclick="return confirm('<?php echo esc_js( __( 'Retirer ce secours de la liste ?', 'gestion-atelier-cct' ) ); ?>')">
								<?php esc_html_e( 'Supprimer', 'gestion-atelier-cct' ); ?>
							</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$nb_pages = (int) ceil( $total / $par_page );
		if ( $nb_pages > 1 ) :
			?>
			<div class="tablenav"><div class="tablenav-pages">
				<span class="displaying-num"><?php echo (int) $total; ?> <?php esc_html_e( 'secours', 'gestion-atelier-cct' ); ?></span>
				<span class="pagination-links">
				<?php for ( $p = 1; $p <= $nb_pages; $p++ ) : ?>
					<?php if ( $p === $pagenum ) : ?>
						<span class="tablenav-pages-navspan button disabled"><?php echo (int) $p; ?></span>
					<?php elseif ( $p <= 2 || $p > $nb_pages - 2 || abs( $p - $pagenum ) <= 2 ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'pagenum' => $p, 's_secours' => $recherche ), $base_url ) ); ?>"><?php echo (int) $p; ?></a>
					<?php elseif ( 3 === $p || $p === $nb_pages - 2 ) : ?>
						<span class="tablenav-pages-navspan">…</span>
					<?php endif; ?>
				<?php endfor; ?>
				</span>
			</div></div>
		<?php endif; ?>
	</div>
	<?php
}
