<?php
/**
 * Référentiel des voiles (marque / modèle / année) pour la recherche du
 * formulaire de demande d'intervention v2.
 *
 * - Table éditable `{prefix}gacct_voiles` (import initial CSV, entretien via
 *   l'onglet « Voiles » de Gestion Atelier > Configuration).
 * - Journal `{prefix}gacct_voiles_journal` des saisies « hors liste » faites par
 *   les clients (mode manuel du formulaire), avec ajout à la liste en un clic.
 * - Export JSON statique dans uploads (servi au front, régénéré à chaque
 *   modification de la liste) : pas de requête PHP par visiteur.
 *
 * White-label : chaque atelier importe / entretient SA liste ; tout est
 * filtrable (`gacct_voiles_*`).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
 *  TABLES
 * ============================================================================= */

/**
 * Version du schéma : incrémentée à chaque évolution des tables.
 */
function gacct_voiles_db_version() {
	return 1;
}

function gacct_voiles_table() {
	global $wpdb;
	return $wpdb->prefix . 'gacct_voiles';
}

function gacct_voiles_journal_table() {
	global $wpdb;
	return $wpdb->prefix . 'gacct_voiles_journal';
}

add_action( 'admin_init', 'gacct_voiles_maybe_install' );

/**
 * Crée / met à niveau les tables (dbDelta), pilotée par l'option de version.
 */
function gacct_voiles_maybe_install() {
	if ( (int) get_option( 'gacct_voiles_db_version', 0 ) >= gacct_voiles_db_version() ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$voiles  = gacct_voiles_table();
	$journal = gacct_voiles_journal_table();

	dbDelta(
		"CREATE TABLE {$voiles} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			marque VARCHAR(100) NOT NULL,
			modele VARCHAR(150) NOT NULL,
			annee SMALLINT UNSIGNED NULL DEFAULT NULL,
			recente TINYINT(1) NOT NULL DEFAULT 0,
			source VARCHAR(20) NOT NULL DEFAULT 'import',
			cree_le DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY marque_modele (marque, modele)
		) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$journal} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			marque VARCHAR(100) NOT NULL,
			modele VARCHAR(150) NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			statut VARCHAR(20) NOT NULL DEFAULT 'nouveau',
			cree_le DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY statut (statut)
		) {$charset};"
	);

	update_option( 'gacct_voiles_db_version', gacct_voiles_db_version() );
}

/* =============================================================================
 *  IMPORT CSV (format : marque;modele;annee;recente;id_source)
 * ============================================================================= */

/**
 * Importe un CSV dans la table des voiles. `$vider` remplace toute la liste.
 *
 * @param string $chemin Chemin absolu du CSV (UTF-8, ; comme séparateur, en-tête).
 * @param bool   $vider  TRUE = TRUNCATE avant import.
 * @return array{importees:int, ignorees:int}|WP_Error
 */
function gacct_voiles_importer_csv( $chemin, $vider = false ) {
	global $wpdb;

	if ( ! is_readable( $chemin ) ) {
		return new WP_Error( 'gacct_voiles_csv', 'Fichier illisible : ' . $chemin );
	}

	gacct_voiles_maybe_install();

	$table = gacct_voiles_table();

	if ( $vider ) {
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$fh = fopen( $chemin, 'r' );
	if ( ! $fh ) {
		return new WP_Error( 'gacct_voiles_csv', 'Ouverture impossible.' );
	}

	$entete    = true;
	$importees = 0;
	$ignorees  = 0;
	$now       = current_time( 'mysql' );

	while ( false !== ( $ligne = fgetcsv( $fh, 0, ';' ) ) ) {
		if ( $entete ) {
			$entete = false;
			continue;
		}
		$marque = trim( (string) ( $ligne[0] ?? '' ) );
		$modele = trim( (string) ( $ligne[1] ?? '' ) );
		$annee  = absint( $ligne[2] ?? 0 );
		$recent = in_array( strtolower( trim( (string) ( $ligne[3] ?? '' ) ) ), array( 'oui', '1', 'true' ), true ) ? 1 : 0;

		if ( '' === $marque || '' === $modele ) {
			$ignorees++;
			continue;
		}

		$wpdb->insert(
			$table,
			array(
				'marque'  => $marque,
				'modele'  => $modele,
				'annee'   => $annee ? $annee : null,
				'recente' => $recent,
				'source'  => 'import',
				'cree_le' => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		$importees++;
	}
	fclose( $fh );

	gacct_voiles_invalider_export();

	return array(
		'importees' => $importees,
		'ignorees'  => $ignorees,
	);
}

/* =============================================================================
 *  EXPORT JSON STATIQUE (servi au formulaire)
 * ============================================================================= */

/**
 * URL du JSON courant des voiles ('' si liste vide / export impossible).
 * Le fichier contient { voiles: [[marque, modele, annee, recente], …],
 * marques: [ … ] } — régénéré quand la liste change (option de révision).
 *
 * @return string
 */
function gacct_voiles_json_url() {
	$rev = (int) get_option( 'gacct_voiles_rev', 0 );

	$stocke = get_option( 'gacct_voiles_json', array() );
	if ( is_array( $stocke ) && ( $stocke['rev'] ?? -1 ) === $rev && ! empty( $stocke['url'] ) ) {
		return (string) $stocke['url'];
	}

	return gacct_voiles_regenerer_export();
}

/**
 * Force la régénération du JSON au prochain appel.
 */
function gacct_voiles_invalider_export() {
	update_option( 'gacct_voiles_rev', (int) get_option( 'gacct_voiles_rev', 0 ) + 1, false );
}

/**
 * (Re)génère le fichier JSON dans uploads/gacct-voiles/ et mémorise son URL.
 *
 * @return string URL ('' si échec).
 */
function gacct_voiles_regenerer_export() {
	global $wpdb;

	$table = gacct_voiles_table();

	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return '';
	}

	$rows = $wpdb->get_results(
		"SELECT marque, modele, annee, recente FROM {$table} ORDER BY marque, modele", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);

	if ( ! $rows ) {
		return '';
	}

	$voiles  = array();
	$marques = array();
	foreach ( $rows as $row ) {
		$voiles[] = array(
			(string) $row['marque'],
			(string) $row['modele'],
			$row['annee'] ? (int) $row['annee'] : '',
			(int) $row['recente'],
		);
		$marques[ $row['marque'] ] = true;
	}
	$marques = array_keys( $marques );
	// Tri insensible aux accents/casse pour le select du mode manuel.
	usort( $marques, function ( $a, $b ) {
		return strcasecmp( remove_accents( $a ), remove_accents( $b ) );
	} );

	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'gacct-voiles';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$json = wp_json_encode( array( 'voiles' => $voiles, 'marques' => $marques ), JSON_UNESCAPED_UNICODE );
	$hash = substr( md5( $json ), 0, 10 );
	$file = $dir . '/voiles-' . $hash . '.json';

	if ( ! file_exists( $file ) && false === file_put_contents( $file, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return '';
	}

	// Purge des anciens exports (on garde le courant).
	foreach ( (array) glob( $dir . '/voiles-*.json' ) as $old ) {
		if ( $old !== $file ) {
			@unlink( $old ); // phpcs:ignore
		}
	}

	$url = trailingslashit( $uploads['baseurl'] ) . 'gacct-voiles/voiles-' . $hash . '.json';

	update_option(
		'gacct_voiles_json',
		array(
			'rev' => (int) get_option( 'gacct_voiles_rev', 0 ),
			'url' => $url,
		),
		false
	);

	return $url;
}

/* =============================================================================
 *  JOURNAL DES SAISIES HORS LISTE
 * ============================================================================= */

/**
 * Journalise une saisie marque/modèle absente du référentiel (appelée à la
 * soumission du formulaire). Dédoublonne : une même paire « nouveau » n'est
 * journalisée qu'une fois.
 *
 * @param string $marque
 * @param string $modele
 */
function gacct_voiles_journaliser_hors_liste( $marque, $modele ) {
	global $wpdb;

	$marque = trim( (string) $marque );
	$modele = trim( (string) $modele );
	if ( '' === $marque || '' === $modele ) {
		return;
	}

	$table   = gacct_voiles_table();
	$journal = gacct_voiles_journal_table();

	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return;
	}

	// Déjà dans la liste (insensible casse/espaces) ? Rien à journaliser.
	$connue = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE UPPER(REPLACE(marque,' ','')) = UPPER(REPLACE(%s,' ','')) AND UPPER(REPLACE(modele,' ','')) = UPPER(REPLACE(%s,' ',''))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$marque,
			$modele
		)
	);
	if ( $connue ) {
		return;
	}

	$deja = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$journal} WHERE statut = 'nouveau' AND UPPER(REPLACE(marque,' ','')) = UPPER(REPLACE(%s,' ','')) AND UPPER(REPLACE(modele,' ','')) = UPPER(REPLACE(%s,' ',''))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$marque,
			$modele
		)
	);
	if ( $deja ) {
		return;
	}

	$wpdb->insert(
		$journal,
		array(
			'marque'  => $marque,
			'modele'  => $modele,
			'user_id' => get_current_user_id(),
			'statut'  => 'nouveau',
			'cree_le' => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%d', '%s', '%s' )
	);
}

/* =============================================================================
 *  ONGLET « VOILES » DE GESTION ATELIER > CONFIGURATION
 * ============================================================================= */

add_filter( 'gacct_config_tabs', 'gacct_voiles_config_tab' );

function gacct_voiles_config_tab( $tabs ) {
	$tabs['voiles'] = array( __( 'Voiles', 'gestion-atelier-cct' ), 'gacct_voiles_render_config_tab' );
	return $tabs;
}

/**
 * Traitement des actions POST de l'onglet (ajout, suppression, journal).
 */
function gacct_voiles_traiter_post() {
	if ( empty( $_POST['gacct_voiles_action'] ) || ! current_user_can( gacct_voiles_admin_cap() ) ) {
		return '';
	}
	check_admin_referer( 'gacct_voiles' );

	global $wpdb;
	$table   = gacct_voiles_table();
	$journal = gacct_voiles_journal_table();
	$action  = sanitize_key( wp_unslash( $_POST['gacct_voiles_action'] ) );
	$notice  = '';

	switch ( $action ) {
		case 'ajouter':
			$marque = sanitize_text_field( wp_unslash( $_POST['marque'] ?? '' ) );
			$modele = sanitize_text_field( wp_unslash( $_POST['modele'] ?? '' ) );
			$annee  = absint( $_POST['annee'] ?? 0 );
			if ( '' === $marque || '' === $modele ) {
				$notice = __( 'Marque et modèle sont obligatoires.', 'gestion-atelier-cct' );
				break;
			}
			$wpdb->insert(
				$table,
				array(
					'marque'  => $marque,
					'modele'  => $modele,
					'annee'   => $annee ? $annee : null,
					'recente' => $annee >= (int) gmdate( 'Y' ) - 8 ? 1 : 0,
					'source'  => 'manuel',
					'cree_le' => current_time( 'mysql' ),
				)
			);
			gacct_voiles_invalider_export();
			$notice = __( 'Voile ajoutée à la liste.', 'gestion-atelier-cct' );
			break;

		case 'supprimer':
			$id = absint( $_POST['voile_id'] ?? 0 );
			if ( $id ) {
				$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
				gacct_voiles_invalider_export();
				$notice = __( 'Voile retirée de la liste.', 'gestion-atelier-cct' );
			}
			break;

		case 'journal_ajouter':
			$id  = absint( $_POST['journal_id'] ?? 0 );
			$row = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$journal} WHERE id = %d", $id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $row && 'nouveau' === $row['statut'] ) {
				$annee = absint( $_POST['annee'] ?? 0 );
				$wpdb->insert(
					$table,
					array(
						'marque'  => sanitize_text_field( wp_unslash( $_POST['marque'] ?? $row['marque'] ) ),
						'modele'  => sanitize_text_field( wp_unslash( $_POST['modele'] ?? $row['modele'] ) ),
						'annee'   => $annee ? $annee : null,
						'recente' => $annee >= (int) gmdate( 'Y' ) - 8 ? 1 : 0,
						'source'  => 'journal',
						'cree_le' => current_time( 'mysql' ),
					)
				);
				$wpdb->update( $journal, array( 'statut' => 'ajoute' ), array( 'id' => $id ) );
				gacct_voiles_invalider_export();
				$notice = __( 'Saisie ajoutée à la liste des voiles.', 'gestion-atelier-cct' );
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

	return $notice;
}

function gacct_voiles_admin_cap() {
	// Même filtre / même défaut que GACCT_Plugin::capability() (méthode privée).
	return apply_filters( 'gacct_admin_capability', 'manage_options' );
}

/**
 * Rendu de l'onglet : journal hors liste + recherche/gestion de la liste.
 * Composants WP natifs uniquement (règle design admin du 29/07/2026).
 */
function gacct_voiles_render_config_tab() {
	global $wpdb;

	gacct_voiles_maybe_install();

	$notice  = gacct_voiles_traiter_post();
	$table   = gacct_voiles_table();
	$journal = gacct_voiles_journal_table();

	$recherche = isset( $_GET['s_voile'] ) ? sanitize_text_field( wp_unslash( $_GET['s_voile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$pagenum   = max( 1, absint( $_GET['pagenum'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$par_page  = 20;

	$where = '1=1';
	$args  = array();
	if ( '' !== $recherche ) {
		$where = '(marque LIKE %s OR modele LIKE %s)';
		$like  = '%' . $wpdb->esc_like( $recherche ) . '%';
		$args  = array( $like, $like );
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) : "SELECT COUNT(*) FROM {$table}" );
	$sql   = "SELECT * FROM {$table} WHERE {$where} ORDER BY marque, modele LIMIT %d OFFSET %d";
	$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $par_page, ( $pagenum - 1 ) * $par_page ) ) ), ARRAY_A );

	$en_attente = $wpdb->get_results( "SELECT * FROM {$journal} WHERE statut = 'nouveau' ORDER BY cree_le DESC LIMIT 50", ARRAY_A );
	// phpcs:enable

	$base_url = add_query_arg( array( 'page' => 'gacct-config', 'tab' => 'voiles' ), admin_url( 'admin.php' ) );
	?>
	<div class="wrap">
		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Saisies hors liste à traiter', 'gestion-atelier-cct' ); ?>
			<?php if ( $en_attente ) : ?><span class="count">(<?php echo count( $en_attente ); ?>)</span><?php endif; ?>
		</h2>
		<?php if ( ! $en_attente ) : ?>
			<p><?php esc_html_e( 'Aucune saisie en attente : les clients ont trouvé leur voile dans la liste.', 'gestion-atelier-cct' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th class="column-primary"><?php esc_html_e( 'Marque', 'gestion-atelier-cct' ); ?></th>
					<th><?php esc_html_e( 'Modèle', 'gestion-atelier-cct' ); ?></th>
					<th><?php esc_html_e( 'Saisie le', 'gestion-atelier-cct' ); ?></th>
					<th><?php esc_html_e( 'Année (facultatif)', 'gestion-atelier-cct' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'gestion-atelier-cct' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $en_attente as $j ) : ?>
					<tr>
						<form method="post">
							<?php wp_nonce_field( 'gacct_voiles' ); ?>
							<input type="hidden" name="journal_id" value="<?php echo (int) $j['id']; ?>">
							<td class="column-primary" data-colname="Marque">
								<input type="text" name="marque" value="<?php echo esc_attr( $j['marque'] ); ?>" class="regular-text" style="max-width:140px">
								<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Détails', 'gestion-atelier-cct' ); ?></span></button>
							</td>
							<td data-colname="Modèle"><input type="text" name="modele" value="<?php echo esc_attr( $j['modele'] ); ?>" class="regular-text" style="max-width:160px"></td>
							<td data-colname="Saisie le"><?php echo esc_html( mysql2date( 'd/m/Y H:i', $j['cree_le'] ) ); ?></td>
							<td data-colname="Année"><input type="number" name="annee" min="1980" max="2100" style="width:90px" placeholder="—"></td>
							<td data-colname="Actions">
								<button class="button button-primary" name="gacct_voiles_action" value="journal_ajouter"><?php esc_html_e( 'Ajouter à la liste', 'gestion-atelier-cct' ); ?></button>
								<button class="button" name="gacct_voiles_action" value="journal_ignorer"><?php esc_html_e( 'Ignorer', 'gestion-atelier-cct' ); ?></button>
							</td>
						</form>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<hr class="wp-header-end" style="margin:24px 0">

		<h2><?php esc_html_e( 'Liste des voiles', 'gestion-atelier-cct' ); ?> <span class="count">(<?php echo (int) $total; ?>)</span></h2>

		<form method="get" class="search-box" style="margin-bottom:12px">
			<input type="hidden" name="page" value="gacct-config">
			<input type="hidden" name="tab" value="voiles">
			<label class="screen-reader-text" for="s_voile"><?php esc_html_e( 'Rechercher une voile', 'gestion-atelier-cct' ); ?></label>
			<input type="search" id="s_voile" name="s_voile" value="<?php echo esc_attr( $recherche ); ?>" placeholder="<?php esc_attr_e( 'Marque ou modèle…', 'gestion-atelier-cct' ); ?>">
			<button class="button"><?php esc_html_e( 'Rechercher', 'gestion-atelier-cct' ); ?></button>
		</form>

		<form method="post" style="margin-bottom:16px">
			<?php wp_nonce_field( 'gacct_voiles' ); ?>
			<input type="text" name="marque" placeholder="<?php esc_attr_e( 'Marque', 'gestion-atelier-cct' ); ?>" required>
			<input type="text" name="modele" placeholder="<?php esc_attr_e( 'Modèle', 'gestion-atelier-cct' ); ?>" required>
			<input type="number" name="annee" min="1980" max="2100" placeholder="<?php esc_attr_e( 'Année', 'gestion-atelier-cct' ); ?>" style="width:90px">
			<button class="button button-primary" name="gacct_voiles_action" value="ajouter"><?php esc_html_e( 'Ajouter une voile', 'gestion-atelier-cct' ); ?></button>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th class="column-primary"><?php esc_html_e( 'Marque', 'gestion-atelier-cct' ); ?></th>
				<th><?php esc_html_e( 'Modèle', 'gestion-atelier-cct' ); ?></th>
				<th style="width:80px"><?php esc_html_e( 'Année', 'gestion-atelier-cct' ); ?></th>
				<th style="width:90px"><?php esc_html_e( 'Récente', 'gestion-atelier-cct' ); ?></th>
				<th style="width:90px"><?php esc_html_e( 'Source', 'gestion-atelier-cct' ); ?></th>
				<th style="width:110px"><?php esc_html_e( 'Action', 'gestion-atelier-cct' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'Aucune voile trouvée.', 'gestion-atelier-cct' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $v ) : ?>
				<tr>
					<td class="column-primary" data-colname="Marque"><strong><?php echo esc_html( $v['marque'] ); ?></strong>
						<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Détails', 'gestion-atelier-cct' ); ?></span></button>
					</td>
					<td data-colname="Modèle"><?php echo esc_html( $v['modele'] ); ?></td>
					<td data-colname="Année"><?php echo $v['annee'] ? (int) $v['annee'] : '—'; ?></td>
					<td data-colname="Récente"><?php echo $v['recente'] ? esc_html__( 'oui', 'gestion-atelier-cct' ) : '—'; ?></td>
					<td data-colname="Source"><?php echo esc_html( $v['source'] ); ?></td>
					<td data-colname="Action">
						<form method="post" style="display:inline">
							<?php wp_nonce_field( 'gacct_voiles' ); ?>
							<input type="hidden" name="voile_id" value="<?php echo (int) $v['id']; ?>">
							<button class="button button-link-delete" name="gacct_voiles_action" value="supprimer"
								onclick="return confirm('<?php echo esc_js( __( 'Retirer cette voile de la liste ?', 'gestion-atelier-cct' ) ); ?>')">
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
				<span class="displaying-num"><?php echo (int) $total; ?> <?php esc_html_e( 'voiles', 'gestion-atelier-cct' ); ?></span>
				<span class="pagination-links">
				<?php for ( $p = 1; $p <= $nb_pages; $p++ ) : ?>
					<?php if ( $p === $pagenum ) : ?>
						<span class="tablenav-pages-navspan button disabled"><?php echo (int) $p; ?></span>
					<?php elseif ( $p <= 2 || $p > $nb_pages - 2 || abs( $p - $pagenum ) <= 2 ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'pagenum' => $p, 's_voile' => $recherche ), $base_url ) ); ?>"><?php echo (int) $p; ?></a>
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
