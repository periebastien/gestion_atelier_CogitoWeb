<?php
/**
 * Détail d'une demande de devis de réparation (21/11/2026, correction n°4 de Titi).
 *
 * Quand le client coche la carte « Besoin d'une réparation ? » du formulaire v2,
 * il décrit la réparation (obligatoire) et peut joindre des photos ou des PDF,
 * par glisser-déposer ou sélection de fichiers.
 *
 * - Désactivé par défaut dans le socle : l'atelier l'ouvre par le filtre
 *   `gacct_demande_reparation_actif` (pack Altitude). AEROTECH n'est pas concerné.
 * - Les fichiers partent dès qu'ils sont déposés (AJAX `gacct_rep_upload`), dans
 *   uploads/gacct-reparations/tmp/, sous un nom aléatoire. Le formulaire ne porte
 *   que leurs jetons (champ `gacct_rep_fichiers`, hors champs JetFormBuilder).
 * - À l'envoi : la garde serveur exige le texte et le recopie en tête du
 *   commentaire client ; après la création du dossier (action jwcct_capture_ids),
 *   les fichiers sont rangés dans uploads/gacct-reparations/<id dossier>/ et
 *   listés dans l'option `gacct_rep_<id dossier>`.
 * - Affichage : fiche console du dossier et écran de la commande WooCommerce.
 * - Les fichiers temporaires de plus de 2 jours sont purgés chaque jour.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Le module est-il ouvert sur ce site ? */
function gacct_rep_actif() {
	return (bool) apply_filters( 'gacct_demande_reparation_actif', false );
}

/** Réglages (filtrables) : nombre de fichiers, poids, types acceptés. */
function gacct_rep_reglages() {
	return apply_filters(
		'gacct_demande_reparation_reglages',
		array(
			'max_fichiers' => 5,
			'max_octets'   => min( 8 * MB_IN_BYTES, (int) wp_max_upload_size() ),
			'types'        => array(
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'webp' => 'image/webp',
				'heic' => 'image/heic',
				'heif' => 'image/heif',
				'pdf'  => 'application/pdf',
			),
		)
	);
}

/** Dossier de stockage (chemin et URL), créé et protégé au besoin. */
function gacct_rep_dossier( $sous = '' ) {
	$up   = wp_upload_dir();
	$base = trailingslashit( $up['basedir'] ) . 'gacct-reparations';
	$url  = trailingslashit( $up['baseurl'] ) . 'gacct-reparations';

	if ( ! is_dir( $base ) ) {
		wp_mkdir_p( $base );
		@file_put_contents( $base . '/index.php', "<?php\n// Silence.\n" );
		@file_put_contents( $base . '/.htaccess', "Options -Indexes\n" );
	}

	if ( '' !== $sous ) {
		$base .= '/' . $sous;
		$url  .= '/' . $sous;
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
			@file_put_contents( $base . '/index.php', "<?php\n// Silence.\n" );
		}
	}

	return array( 'path' => $base, 'url' => $url );
}

/** Configuration passée au JS du formulaire (fusionnée dans gacctDemande.v2). */
function gacct_rep_config_js() {
	if ( ! gacct_rep_actif() ) {
		return array( 'actif' => false );
	}
	$r = gacct_rep_reglages();

	return array(
		'actif'       => true,
		'ajax'        => admin_url( 'admin-ajax.php' ),
		'nonce'       => wp_create_nonce( 'gacct_rep' ),
		'maxFichiers' => (int) $r['max_fichiers'],
		'maxOctets'   => (int) $r['max_octets'],
		'accept'      => implode( ',', array_unique( array_merge( array_map( function ( $e ) { return '.' . $e; }, array_keys( $r['types'] ) ), array_values( $r['types'] ) ) ) ),
		'textes'      => apply_filters(
			'gacct_demande_reparation_textes',
			array(
				'titre'        => __( 'Détail de l’intervention', 'gestion-atelier-cct' ),
				'aide'         => __( 'Décrivez la réparation à faire : où se trouve le dommage, sa taille, comment c’est arrivé.', 'gestion-atelier-cct' ),
				'placeholder'  => __( 'Ex. : accroc de 3 cm sur l’extrados, caisson 12 côté droit, après un atterrissage dans les arbres.', 'gestion-atelier-cct' ),
				'photosTitre'  => __( 'Photos ou documents (facultatif)', 'gestion-atelier-cct' ),
				'deposer'      => __( 'Glissez vos photos ici, ou', 'gestion-atelier-cct' ),
				'choisir'      => __( 'choisissez des fichiers', 'gestion-atelier-cct' ),
				'formats'      => __( 'Images ou PDF, %d fichiers au plus.', 'gestion-atelier-cct' ),
				'retirer'      => __( 'Retirer', 'gestion-atelier-cct' ),
				'envoi'        => __( 'Envoi…', 'gestion-atelier-cct' ),
				'erreurTexte'  => __( 'Décrivez la réparation à faire pour continuer.', 'gestion-atelier-cct' ),
				'erreurEnvoi'  => __( 'Patientez, un fichier est encore en cours d’envoi.', 'gestion-atelier-cct' ),
				'erreurType'   => __( '« %s » n’est ni une image ni un PDF.', 'gestion-atelier-cct' ),
				'erreurPoids'  => __( '« %s » est trop lourd (%s au plus).', 'gestion-atelier-cct' ),
				'erreurNombre' => __( '%d fichiers au plus.', 'gestion-atelier-cct' ),
				'erreurServeur' => __( 'L’envoi de « %s » a échoué. Réessayez.', 'gestion-atelier-cct' ),
			)
		),
	);
}

/* ------------------------------------------------------------------ */
/*  Envoi et retrait des fichiers (AJAX)                               */
/* ------------------------------------------------------------------ */

/** Limite d'envois par visiteur : 30 fichiers par heure. */
function gacct_rep_quota_ok() {
	$cle = 'gacct_rep_q_' . md5( is_user_logged_in() ? 'u' . get_current_user_id() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	$n   = (int) get_transient( $cle );
	if ( $n >= 30 ) {
		return false;
	}
	set_transient( $cle, $n + 1, HOUR_IN_SECONDS );
	return true;
}

/** Propriétaire d'un jeton : l'utilisateur connecté, sinon une empreinte de l'IP. */
function gacct_rep_proprio() {
	return is_user_logged_in() ? 'u' . get_current_user_id() : 'ip' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt( 'nonce' ) );
}

function gacct_rep_ajax_upload() {
	if ( ! gacct_rep_actif() || ! check_ajax_referer( 'gacct_rep', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Session expirée : rechargez la page.', 'gestion-atelier-cct' ) ), 403 );
	}
	if ( ! gacct_rep_quota_ok() ) {
		wp_send_json_error( array( 'message' => __( 'Trop de fichiers envoyés, réessayez plus tard.', 'gestion-atelier-cct' ) ), 429 );
	}
	if ( empty( $_FILES['fichier'] ) || ! is_uploaded_file( $_FILES['fichier']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['fichier']['error'] ) {
		wp_send_json_error( array( 'message' => __( 'Fichier non reçu (trop lourd ?).', 'gestion-atelier-cct' ) ), 400 );
	}

	$r      = gacct_rep_reglages();
	$f      = $_FILES['fichier'];
	$nom    = sanitize_file_name( wp_unslash( $f['name'] ) );
	$ext    = strtolower( pathinfo( $nom, PATHINFO_EXTENSION ) );
	$taille = (int) $f['size'];

	if ( $taille <= 0 || $taille > (int) $r['max_octets'] ) {
		wp_send_json_error( array( 'message' => sprintf( __( '« %1$s » est trop lourd (%2$s au plus).', 'gestion-atelier-cct' ), $nom, size_format( $r['max_octets'] ) ) ), 400 );
	}

	$mime = '';
	if ( function_exists( 'finfo_open' ) ) {
		$fi   = finfo_open( FILEINFO_MIME_TYPE );
		$mime = (string) finfo_file( $fi, $f['tmp_name'] );
		finfo_close( $fi );
	}
	$mimes_ok = array_values( $r['types'] );
	if ( ! isset( $r['types'][ $ext ] ) || ! in_array( $mime, $mimes_ok, true ) ) {
		wp_send_json_error( array( 'message' => sprintf( __( '« %s » n’est ni une image ni un PDF.', 'gestion-atelier-cct' ), $nom ) ), 400 );
	}
	// Extension recalée sur le type réel (une photo .png qui est un JPEG garde un nom juste).
	$ext_reelle = array_search( $mime, $r['types'], true );
	if ( $r['types'][ $ext ] !== $mime ) {
		$ext = $ext_reelle;
	}

	$jeton = bin2hex( random_bytes( 16 ) );
	$dir   = gacct_rep_dossier( 'tmp' );
	$dest  = $dir['path'] . '/' . $jeton . '.' . $ext;

	if ( ! @move_uploaded_file( $f['tmp_name'], $dest ) ) {
		wp_send_json_error( array( 'message' => __( 'Enregistrement impossible.', 'gestion-atelier-cct' ) ), 500 );
	}
	@chmod( $dest, 0644 );

	set_transient(
		'gacct_rep_t_' . $jeton,
		array(
			'fichier' => $jeton . '.' . $ext,
			'nom'     => $nom,
			'type'    => $mime,
			'taille'  => $taille,
			'proprio' => gacct_rep_proprio(),
		),
		2 * DAY_IN_SECONDS
	);

	wp_send_json_success( array( 'jeton' => $jeton, 'nom' => $nom, 'taille' => size_format( $taille ) ) );
}
add_action( 'wp_ajax_gacct_rep_upload', 'gacct_rep_ajax_upload' );

function gacct_rep_ajax_retirer() {
	if ( ! check_ajax_referer( 'gacct_rep', 'nonce', false ) ) {
		wp_send_json_error( null, 403 );
	}
	$jeton = preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_POST['jeton'] ?? '' ) );
	$t     = $jeton ? get_transient( 'gacct_rep_t_' . $jeton ) : false;
	if ( is_array( $t ) && $t['proprio'] === gacct_rep_proprio() ) {
		@unlink( gacct_rep_dossier( 'tmp' )['path'] . '/' . basename( $t['fichier'] ) );
		delete_transient( 'gacct_rep_t_' . $jeton );
	}
	wp_send_json_success();
}
add_action( 'wp_ajax_gacct_rep_retirer', 'gacct_rep_ajax_retirer' );

/* ------------------------------------------------------------------ */
/*  Envoi du formulaire                                                */
/* ------------------------------------------------------------------ */

/** La demande contient-elle la carte « demande de devis » ? */
function gacct_rep_demande_devis( $request ) {
	if ( ! function_exists( 'gacct_quote_devis_product_ids' ) ) {
		return false;
	}
	$ids = array();
	foreach ( array( 'revisions_controle', 'pliages_secours', 'suspentes_travaux' ) as $champ ) {
		$ids = array_merge( $ids, array_map( 'absint', (array) ( $request[ $champ ] ?? array() ) ) );
	}
	return (bool) array_intersect( $ids, gacct_quote_devis_product_ids() );
}

/** Texte et fichiers de la demande en cours (mémoire de la requête). */
function gacct_rep_en_cours( $valeur = null ) {
	static $en_cours = null;
	if ( null !== $valeur ) {
		$en_cours = $valeur;
	}
	return $en_cours;
}

/**
 * Garde serveur : texte obligatoire, recopié en tête du commentaire client
 * (il remonte ainsi partout où le commentaire est lu : fiche, commande, e-mails).
 */
function gacct_rep_garde( $erreur, $request, $handler ) {
	if ( $erreur || ! gacct_rep_actif() || ! gacct_rep_demande_devis( $request ) ) {
		return $erreur;
	}

	$detail = trim( sanitize_textarea_field( wp_unslash( $_POST['gacct_rep_detail'] ?? '' ) ) );
	if ( '' === $detail ) {
		return __( 'Décrivez la réparation à faire.', 'gestion-atelier-cct' );
	}

	$jetons = array_filter( array_map(
		function ( $j ) {
			return preg_replace( '/[^a-f0-9]/', '', $j );
		},
		explode( ',', (string) wp_unslash( $_POST['gacct_rep_fichiers'] ?? '' ) )
	) );
	$jetons = array_slice( array_unique( $jetons ), 0, (int) gacct_rep_reglages()['max_fichiers'] );

	gacct_rep_en_cours( array( 'detail' => $detail, 'jetons' => $jetons ) );

	$commentaire = trim( (string) ( $request['commentaire_client'] ?? '' ) );
	$texte       = sprintf( __( 'Réparation à faire : %s', 'gestion-atelier-cct' ), $detail );
	if ( '' !== $commentaire ) {
		$texte .= "\n\n" . $commentaire;
	}
	if ( is_object( $handler ) && isset( $handler->request_data ) && ( is_array( $handler->request_data ) || $handler->request_data instanceof \ArrayAccess ) ) {
		$handler->request_data['commentaire_client'] = $texte;
	}

	return $erreur;
}
add_filter( 'gacct_demande_garde_erreur', 'gacct_rep_garde', 20, 3 );

/** Après la création du dossier : range les fichiers et garde la trace. */
function gacct_rep_apres_creation( $request, $handler ) {
	$en_cours = gacct_rep_en_cours();
	if ( ! $en_cours ) {
		return;
	}
	$revision_id = absint( $request['inserted_cct_revision'] ?? 0 );
	if ( ! $revision_id ) {
		return;
	}

	$tmp      = gacct_rep_dossier( 'tmp' )['path'];
	$dest     = gacct_rep_dossier( (string) $revision_id )['path'];
	$proprio  = gacct_rep_proprio();
	$fichiers = array();

	foreach ( $en_cours['jetons'] as $jeton ) {
		$t = get_transient( 'gacct_rep_t_' . $jeton );
		if ( ! is_array( $t ) || $t['proprio'] !== $proprio ) {
			continue;
		}
		$source = $tmp . '/' . basename( $t['fichier'] );
		$nom    = bin2hex( random_bytes( 6 ) ) . '-' . sanitize_file_name( $t['nom'] );
		$ext    = pathinfo( $t['fichier'], PATHINFO_EXTENSION );
		if ( strtolower( pathinfo( $nom, PATHINFO_EXTENSION ) ) !== $ext ) {
			$nom .= '.' . $ext;
		}
		if ( is_file( $source ) && @rename( $source, $dest . '/' . $nom ) ) {
			$fichiers[] = array(
				'fichier' => $nom,
				'nom'     => $t['nom'],
				'type'    => $t['type'],
				'taille'  => (int) $t['taille'],
			);
		}
		delete_transient( 'gacct_rep_t_' . $jeton );
	}

	update_option(
		'gacct_rep_' . $revision_id,
		array(
			'detail'   => $en_cours['detail'],
			'fichiers' => $fichiers,
			'date'     => current_time( 'mysql' ),
		),
		false
	);
	gacct_rep_en_cours( false );
}
add_action( 'jet-form-builder/custom-action/jwcct_capture_ids', 'gacct_rep_apres_creation', 20, 2 );

/* ------------------------------------------------------------------ */
/*  Affichage atelier                                                  */
/* ------------------------------------------------------------------ */

/** Données d'un dossier, avec l'URL de chaque fichier. */
function gacct_rep_donnees( $revision_id ) {
	$d = get_option( 'gacct_rep_' . absint( $revision_id ) );
	if ( ! is_array( $d ) ) {
		return null;
	}
	$url = gacct_rep_dossier( (string) absint( $revision_id ) )['url'];
	foreach ( $d['fichiers'] as &$f ) {
		$f['url'] = $url . '/' . rawurlencode( $f['fichier'] );
	}
	unset( $f );
	return $d;
}

/** Bloc HTML : vignettes des photos, liens des PDF. */
function gacct_rep_rendu_fichiers( $revision_id ) {
	$d = gacct_rep_donnees( $revision_id );
	if ( ! $d || empty( $d['fichiers'] ) ) {
		return '';
	}
	$html = '<div class="gacct-rep-fichiers"><p><strong>' . esc_html__( 'Photos et documents joints par le client :', 'gestion-atelier-cct' ) . '</strong></p><ul style="list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:10px;">';
	foreach ( $d['fichiers'] as $f ) {
		$apercu = in_array( $f['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true )
			? '<img src="' . esc_url( $f['url'] ) . '" alt="" style="display:block;width:110px;height:110px;object-fit:cover;border-radius:6px;border:1px solid #dcdcde;">'
			: '<span class="dashicons dashicons-media-document" style="font-size:40px;width:40px;height:40px;"></span>';
		$html .= '<li style="width:110px;font-size:12px;word-break:break-word;"><a href="' . esc_url( $f['url'] ) . '" target="_blank" rel="noopener">' . $apercu . esc_html( $f['nom'] ) . '</a></li>';
	}
	return $html . '</ul></div>';
}

/** Fiche console : sous le commentaire client. */
function gacct_rep_fiche( $revision, $revision_id = 0 ) {
	$id = absint( $revision_id );
	if ( $id ) {
		echo gacct_rep_rendu_fichiers( $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans la fonction.
	}
}
add_action( 'gacct_op_fiche_apres_commentaires', 'gacct_rep_fiche', 10, 2 );

/** Écran de la commande WooCommerce : sous l'adresse d'expédition. */
function gacct_rep_commande_admin( $order ) {
	if ( ! function_exists( 'gacct_revision_id_for_order' ) ) {
		return;
	}
	$id = gacct_revision_id_for_order( $order );
	if ( $id ) {
		echo '<div style="clear:both;padding-top:8px;">' . gacct_rep_rendu_fichiers( $id ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
add_action( 'woocommerce_admin_order_data_after_shipping_address', 'gacct_rep_commande_admin' );

/* ------------------------------------------------------------------ */
/*  Purge des fichiers temporaires                                     */
/* ------------------------------------------------------------------ */

function gacct_rep_purge() {
	$tmp = gacct_rep_dossier( 'tmp' )['path'];
	foreach ( (array) glob( $tmp . '/*' ) as $f ) {
		if ( is_file( $f ) && 'index.php' !== basename( $f ) && filemtime( $f ) < time() - 2 * DAY_IN_SECONDS ) {
			@unlink( $f );
		}
	}
}
add_action( 'gacct_rep_purge', 'gacct_rep_purge' );

function gacct_rep_planifier() {
	if ( gacct_rep_actif() && ! wp_next_scheduled( 'gacct_rep_purge' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'gacct_rep_purge' );
	}
}
add_action( 'init', 'gacct_rep_planifier' );
