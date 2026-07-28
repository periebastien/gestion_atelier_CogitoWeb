<?php
/**
 * Rapports d'intervention (PDF) — coffre-fort + porte de sortie authentifiée
 * (28/07/2026).
 *
 * Les PDF du champ CCT `revision.rapport_pdf` ne sont JAMAIS servis par Apache :
 * ils vivent dans `uploads/<coffre>/` protégé par un .htaccess « Require all
 * denied ». Le seul accès est l'endpoint :
 *
 *     /?gacct_report=<revision_id>[&file=<attachment_id>][&dl=1]
 *
 * Autorisations (état lu en SQL direct : le cache objet JetEngine peut resservir
 * un état périmé dans la même requête) :
 *   - atelier (gacct_operate) ou admin (manage_woocommerce) : dès l'état >= 6 ;
 *   - client propriétaire de la commande liée : à partir de l'état 7.
 * Tout le reste = 403. Non connecté = redirection login avec retour.
 *
 * Le champ `rapport_pdf` accepte PLUSIEURS pièces jointes (liste d'ID séparés
 * par des virgules — `absint()` sur la valeur brute renvoie donc toujours le
 * premier ID, ce qui garde les anciens lecteurs fonctionnels).
 *
 * Le mu-plugin `atelier-secure-vault.php` ne fait plus que déléguer ici
 * (filet de sécurité pour un upload fait depuis l'admin WP classique).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GACCT_REPORT_VAULT_DIRNAME' ) ) {
	define(
		'GACCT_REPORT_VAULT_DIRNAME',
		defined( 'ATELIER_VAULT_DIRNAME' ) ? ATELIER_VAULT_DIRNAME : 'atelier_secure_vault_x9f2'
	);
}

if ( ! defined( 'GACCT_REPORT_QUERY_VAR' ) ) {
	define( 'GACCT_REPORT_QUERY_VAR', 'gacct_report' );
}

/* =============================================================================
 *  COFFRE-FORT
 * ========================================================================== */

/**
 * Chemin absolu du coffre, créé et protégé si besoin.
 *
 * @return string Chemin sans slash final, ou '' en cas d'échec.
 */
function gacct_report_vault_dir() {
	$uploads = wp_upload_dir( null, false );

	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return '';
	}

	$dir = trailingslashit( $uploads['basedir'] ) . GACCT_REPORT_VAULT_DIRNAME;

	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return '';
	}

	$htaccess = $dir . '/.htaccess';

	if ( ! file_exists( $htaccess ) ) {
		@file_put_contents(
			$htaccess,
			"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
			LOCK_EX
		);
	}

	if ( ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", LOCK_EX );
	}

	return $dir;
}

add_action( 'init', 'gacct_report_vault_dir', 5 );

/**
 * Le fichier est-il déjà dans le coffre ?
 *
 * @param string $path Chemin absolu.
 * @return bool
 */
function gacct_report_is_in_vault( $path ) {
	if ( ! is_string( $path ) || '' === $path ) {
		return false;
	}

	return false !== strpos( wp_normalize_path( $path ), '/' . GACCT_REPORT_VAULT_DIRNAME . '/' );
}

/**
 * Déplace le fichier d'un attachement dans le coffre (idempotent).
 *
 * @param int $attachment_id Pièce jointe.
 * @return string Nouveau chemin absolu, ou '' si rien n'a été fait.
 */
function gacct_report_move_to_vault( $attachment_id ) {
	$attachment_id = absint( $attachment_id );
	$old_file      = $attachment_id ? get_attached_file( $attachment_id, true ) : '';

	if ( ! $old_file || ! file_exists( $old_file ) ) {
		return '';
	}

	if ( gacct_report_is_in_vault( $old_file ) ) {
		return $old_file;
	}

	$vault = gacct_report_vault_dir();

	if ( ! $vault ) {
		return '';
	}

	$filename = wp_unique_filename( $vault, sanitize_file_name( wp_basename( $old_file ) ) );
	$new_file = $vault . '/' . $filename;

	if ( ! @rename( $old_file, $new_file ) ) {
		if ( ! @copy( $old_file, $new_file ) ) {
			return '';
		}

		@unlink( $old_file );
	}

	update_attached_file( $attachment_id, $new_file );

	$uploads  = wp_upload_dir( null, false );
	$basedir  = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
	$metadata = wp_get_attachment_metadata( $attachment_id );
	$metadata = is_array( $metadata ) ? $metadata : array();

	$metadata['file'] = ltrim( str_replace( $basedir, '', wp_normalize_path( $new_file ) ), '/' );

	wp_update_attachment_metadata( $attachment_id, $metadata );

	return $new_file;
}

/**
 * Range dans le coffre tous les rapports d'une révision (filet de sécurité :
 * upload fait depuis l'admin WP classique, migration…).
 *
 * @param int $revision_id Révision.
 * @return int Nombre de fichiers déplacés.
 */
function gacct_report_sync_revision( $revision_id ) {
	$row = gacct_report_revision_row( $revision_id );

	if ( ! $row ) {
		return 0;
	}

	$moved = 0;

	foreach ( gacct_report_ids( $row['rapport_pdf'] ) as $attachment_id ) {
		$file = get_attached_file( $attachment_id, true );

		if ( $file && ! gacct_report_is_in_vault( $file ) && gacct_report_move_to_vault( $attachment_id ) ) {
			$moved++;
		}
	}

	return $moved;
}

/* =============================================================================
 *  SCHÉMA : rapport_pdf accepte PLUSIEURS pièces jointes
 * ========================================================================== */

/**
 * Le champ CCT `rapport_pdf` est un champ « media » (value_format = id) :
 * JetEngine en déduit une colonne `bigint(20)`, qui tronquerait notre liste
 * « 558,1785 » au premier ID. On force la colonne en varchar via le filtre
 * officiel de JetEngine — ainsi la définition survit à une ré-écriture du
 * schéma (sauvegarde du CCT dans l'admin).
 *
 * @param array  $schema Colonnes du CCT.
 * @param object $db     Instance DB JetEngine.
 * @return array
 */
function gacct_report_cct_schema( $schema, $db = null ) {
	$table = is_object( $db ) && isset( $db->table ) ? (string) $db->table : '';

	if ( 'revision' === $table && is_array( $schema ) && isset( $schema['rapport_pdf'] ) ) {
		$schema['rapport_pdf'] = 'varchar(255)';
	}

	return $schema;
}
add_filter( 'jet-engine/custom-content-types/table-schema', 'gacct_report_cct_schema', 10, 2 );

/* =============================================================================
 *  DONNÉES
 * ========================================================================== */

/**
 * Liste normalisée d'ID de pièces jointes depuis la valeur brute de rapport_pdf.
 *
 * @param mixed $value Valeur du champ (ID, liste séparée par virgules, JSON,
 *                     tableau, valeur sérialisée…).
 * @return int[] IDs uniques, dans l'ordre.
 */
function gacct_report_ids( $value ) {
	if ( is_array( $value ) && isset( $value['rapport_pdf'] ) ) {
		$value = $value['rapport_pdf'];
	}

	$value = maybe_unserialize( $value );
	$ids   = array();

	if ( is_object( $value ) ) {
		$value = (array) $value;
	}

	if ( is_array( $value ) ) {
		foreach ( $value as $item ) {
			$ids = array_merge( $ids, gacct_report_ids( $item ) );
		}
	} elseif ( is_scalar( $value ) ) {
		$raw = trim( (string) $value );

		if ( '' !== $raw ) {
			$decoded = json_decode( $raw, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$ids = gacct_report_ids( $decoded );
			} else {
				foreach ( explode( ',', $raw ) as $chunk ) {
					$chunk = trim( $chunk );

					if ( ctype_digit( $chunk ) ) {
						$ids[] = (int) $chunk;
					}
				}
			}
		}
	}

	return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
}

/**
 * Ligne CCT d'une révision, lue en SQL direct (pas de cache JetEngine).
 *
 * @param int $revision_id Révision.
 * @return array|null
 */
function gacct_report_revision_row( $revision_id ) {
	global $wpdb;

	$revision_id = absint( $revision_id );

	if ( ! $revision_id ) {
		return null;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT _ID, order_id, etat_de_la_commande, rapport_pdf, marque, modele
			 FROM {$wpdb->prefix}jet_cct_revision WHERE _ID = %d LIMIT 1",
			$revision_id
		),
		ARRAY_A
	);

	return $row ? $row : null;
}

/**
 * Écrit la liste des rapports d'une révision (format « 558,600 »).
 *
 * @param int   $revision_id Révision.
 * @param int[] $ids         Pièces jointes.
 * @return bool
 */
function gacct_report_set_ids( $revision_id, array $ids ) {
	$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

	return (bool) jwcct_update_cct_item(
		JWCCT_CCT_REVISION,
		absint( $revision_id ),
		array( 'rapport_pdf' => implode( ',', $ids ) )
	);
}

/**
 * Chemins absolus existants des rapports d'une révision (emails, pièces jointes).
 *
 * @param array $revision Ligne CCT (ou tableau contenant rapport_pdf).
 * @return string[]
 */
function gacct_report_paths( $revision ) {
	$paths = array();

	foreach ( gacct_report_ids( $revision ) as $attachment_id ) {
		$file = get_attached_file( $attachment_id );

		if ( $file && file_exists( $file ) ) {
			$paths[] = $file;
		}
	}

	return $paths;
}

/* =============================================================================
 *  AUTORISATIONS
 * ========================================================================== */

/**
 * Le client $user_id est-il le propriétaire du dossier ?
 *
 * @param array $row     Ligne CCT de la révision.
 * @param int   $user_id Utilisateur.
 * @return bool
 */
function gacct_report_user_owns_revision( array $row, $user_id ) {
	global $wpdb;

	$user_id  = absint( $user_id );
	$order_id = absint( $row['order_id'] ?? 0 );

	if ( ! $user_id ) {
		return false;
	}

	if ( $order_id && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $order_id );

		if ( $order && absint( $order->get_customer_id() ) === $user_id ) {
			return true;
		}
	}

	// Filet : relation JetEngine 13 (client ↔ révision).
	$linked = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default
			 WHERE rel_id = '13' AND parent_object_id = %d AND child_object_id = %d",
			$user_id,
			absint( $row['_ID'] )
		)
	);

	return $linked > 0;
}

/**
 * Niveau d'accès d'un utilisateur au rapport d'une révision.
 *
 * @param array $row     Ligne CCT de la révision.
 * @param int   $user_id Utilisateur (0 = courant).
 * @return string 'operator', 'owner' ou '' (refusé).
 */
function gacct_report_access( array $row, $user_id = 0 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$state   = (int) ( $row['etat_de_la_commande'] ?? 0 );
	$access  = '';

	$is_operator = user_can( $user_id, defined( 'GACCT_OP_CAP' ) ? GACCT_OP_CAP : 'gacct_operate' )
		|| user_can( $user_id, 'manage_woocommerce' );

	if ( $is_operator && $state >= 6 ) {
		$access = 'operator';
	} elseif ( gacct_report_user_owns_revision( $row, $user_id ) && $state >= 7 ) {
		$access = 'owner';
	}

	return (string) apply_filters( 'gacct_report_access', $access, $row, $user_id );
}

/**
 * Raccourci : l'utilisateur courant peut-il voir le rapport de cette révision ?
 *
 * @param int $revision_id Révision.
 * @param int $user_id     Utilisateur (0 = courant).
 * @return bool
 */
function gacct_report_current_user_can( $revision_id, $user_id = 0 ) {
	$row = gacct_report_revision_row( $revision_id );

	return $row ? '' !== gacct_report_access( $row, $user_id ) : false;
}

/* =============================================================================
 *  URL + ENDPOINT
 * ========================================================================== */

/**
 * URL de téléchargement authentifiée d'un rapport.
 *
 * @param int  $revision_id   Révision.
 * @param int  $attachment_id Pièce jointe (0 = la première du dossier).
 * @param bool $force_download Disposition « attachment » plutôt qu'« inline ».
 * @return string
 */
function gacct_report_download_url( $revision_id, $attachment_id = 0, $force_download = false ) {
	$revision_id = absint( $revision_id );

	if ( ! $revision_id ) {
		return '';
	}

	$args = array( GACCT_REPORT_QUERY_VAR => $revision_id );

	if ( $attachment_id ) {
		$args['file'] = absint( $attachment_id );
	}

	if ( $force_download ) {
		$args['dl'] = 1;
	}

	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Nom de fichier proposé au client : « AR-2026-1621-rapport.pdf ».
 *
 * @param array $row   Ligne CCT.
 * @param int   $index Rang du fichier (0 = premier).
 * @return string
 */
function gacct_report_filename( array $row, $index = 0 ) {
	$reference = '';
	$order_id  = absint( $row['order_id'] ?? 0 );

	if ( $order_id && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			$reference = (string) $order->get_order_number();
		}
	}

	if ( '' === $reference ) {
		$reference = 'revision-' . absint( $row['_ID'] );
	}

	$name = sanitize_file_name( $reference . '-rapport' . ( $index > 0 ? '-' . ( $index + 1 ) : '' ) . '.pdf' );

	return apply_filters( 'gacct_report_filename', $name, $row, $index );
}

/**
 * Endpoint : sert le PDF après contrôle d'accès.
 */
function gacct_report_maybe_serve() {
	if ( empty( $_GET[ GACCT_REPORT_QUERY_VAR ] ) ) {
		return;
	}

	$revision_id = absint( wp_unslash( $_GET[ GACCT_REPORT_QUERY_VAR ] ) );

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect(
			wp_login_url( gacct_report_download_url( $revision_id, isset( $_GET['file'] ) ? absint( wp_unslash( $_GET['file'] ) ) : 0 ) )
		);
		exit;
	}

	$row = gacct_report_revision_row( $revision_id );

	if ( ! $row ) {
		wp_die( esc_html__( 'Rapport introuvable.', 'gestion-atelier-cct' ), '', array( 'response' => 404 ) );
	}

	if ( '' === gacct_report_access( $row ) ) {
		wp_die(
			esc_html__( 'Vous n\'avez pas accès à ce rapport d\'intervention.', 'gestion-atelier-cct' ),
			esc_html__( 'Accès refusé', 'gestion-atelier-cct' ),
			array( 'response' => 403 )
		);
	}

	$ids = gacct_report_ids( $row['rapport_pdf'] );

	if ( empty( $ids ) ) {
		wp_die( esc_html__( 'Aucun rapport n\'est disponible pour ce dossier.', 'gestion-atelier-cct' ), '', array( 'response' => 404 ) );
	}

	$requested = isset( $_GET['file'] ) ? absint( wp_unslash( $_GET['file'] ) ) : 0;

	// Anti-IDOR : la pièce jointe demandée doit appartenir à CETTE révision.
	if ( $requested && ! in_array( $requested, $ids, true ) ) {
		wp_die(
			esc_html__( 'Ce document n\'appartient pas à ce dossier.', 'gestion-atelier-cct' ),
			esc_html__( 'Accès refusé', 'gestion-atelier-cct' ),
			array( 'response' => 403 )
		);
	}

	$attachment_id = $requested ? $requested : $ids[0];
	$index         = (int) array_search( $attachment_id, $ids, true );
	$file          = get_attached_file( $attachment_id );

	if ( ! $file || ! file_exists( $file ) || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
		wp_die( esc_html__( 'Fichier introuvable.', 'gestion-atelier-cct' ), '', array( 'response' => 404 ) );
	}

	do_action( 'gacct_report_served', $attachment_id, $row, get_current_user_id() );

	nocache_headers();

	$disposition = empty( $_GET['dl'] ) ? 'inline' : 'attachment';

	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: ' . $disposition . '; filename="' . gacct_report_filename( $row, $index ) . '"' );
	header( 'Content-Length: ' . filesize( $file ) );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow' );

	while ( ob_get_level() ) {
		ob_end_clean();
	}

	readfile( $file );
	exit;
}
add_action( 'template_redirect', 'gacct_report_maybe_serve', 5 );

/* =============================================================================
 *  RÉSOLUTION DE CONTEXTE (callbacks JetEngine)
 * ========================================================================== */

/**
 * Retrouve la révision à laquelle appartient une pièce jointe rapport.
 * Utilisé par les callbacks de listing JetEngine, qui ne reçoivent que la
 * valeur du champ.
 *
 * @param int $attachment_id Pièce jointe.
 * @return array|null Ligne CCT.
 */
function gacct_report_resolve_revision( $attachment_id ) {
	global $wpdb;

	$attachment_id = absint( $attachment_id );

	if ( ! $attachment_id ) {
		return null;
	}

	// 1) Objet courant du listing JetEngine, si disponible.
	if ( function_exists( 'jet_engine' ) && ! empty( jet_engine()->listings ) && ! empty( jet_engine()->listings->data ) ) {
		$object = jet_engine()->listings->data->get_current_object();
		$object = is_object( $object ) ? (array) $object : $object;

		if ( is_array( $object ) && ! empty( $object['_ID'] ) && isset( $object['rapport_pdf'] ) ) {
			$row = gacct_report_revision_row( $object['_ID'] );

			if ( $row && in_array( $attachment_id, gacct_report_ids( $row['rapport_pdf'] ), true ) ) {
				return $row;
			}
		}
	}

	// 2) Recherche directe : révisions qui référencent cette pièce jointe.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT _ID, order_id, etat_de_la_commande, rapport_pdf
			 FROM {$wpdb->prefix}jet_cct_revision
			 WHERE rapport_pdf = %d OR FIND_IN_SET( %d, REPLACE( rapport_pdf, ' ', '' ) )
			 ORDER BY _ID DESC",
			$attachment_id,
			$attachment_id
		),
		ARRAY_A
	);

	if ( empty( $rows ) ) {
		return null;
	}

	foreach ( $rows as $row ) {
		if ( '' !== gacct_report_access( $row ) ) {
			return $row;
		}
	}

	return null;
}

/**
 * URL du rapport pour l'utilisateur courant, depuis un ID de pièce jointe seul.
 *
 * @param int $attachment_id Pièce jointe.
 * @return string '' si l'utilisateur n'y a pas droit.
 */
function gacct_report_url_for_attachment( $attachment_id ) {
	$row = gacct_report_resolve_revision( $attachment_id );

	if ( ! $row ) {
		return '';
	}

	return gacct_report_download_url( $row['_ID'], $attachment_id );
}
