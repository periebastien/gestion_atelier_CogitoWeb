<?php
/**
 * Rapports de contrôle — FRAMEWORK (28/07/2026, architecture packs 31/07/2026).
 *
 * Ce fichier est 100 % agnostique de l'atelier : les MODÈLES de rapport
 * (formulaires, seuils, calculs, templates PDF, JS) vivent dans des PACKS —
 * des plugins qui s'enregistrent via `gacct_report_register_packs` (voir le
 * registre ci-dessous). Premier pack : gacct-pack-altitude-revision
 * (ParachecK®, analyse MAQUETTE-rapport-intervention.md). Pack suivant prévu :
 * aerotech (MAQUETTE-pack-aerotech.md).
 *
 * Le framework fournit : carte « Rapports » de la fiche console (états 3–6),
 * brouillons, numérotation (format par pack), coffre, endpoints AJAX, dompdf,
 * polices, QR, couleurs d'états par défaut, primitives PDF (report-parts.php).
 *
 * Les brouillons vivent dans le champ CCT `revision.rapports_json` (liste
 * d'entrées {id, model, status, number, data…}) ; chaque génération écrit un
 * PDF dompdf (vendored, assets/vendor/dompdf) DIRECTEMENT dans le coffre
 * (gacct-reports.php) et ajoute/remplace la pièce jointe dans `rapport_pdf`.
 * La régénération réutilise la même pièce jointe (les listes client/email ne
 * bougent pas). Numérotation : AAAA + compteur commun à tous les modèles
 * (option GACCT_REPORT_COUNTER_OPT, valeur de départ réglable dans
 * Gestion Atelier > Configuration), figée à la première génération.
 *
 * TOUS les seuils/calculs vivent ici (source unique PHP) et sont localisés
 * vers le JS (assets/js/operator-report.js) pour les calculs en temps réel ;
 * le PDF recalcule TOUJOURS côté serveur (le client n'est pas cru).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GACCT_REPORT_COUNTER_OPT' ) ) {
	define( 'GACCT_REPORT_COUNTER_OPT', 'gacct_report_counter' );
}

// Primitives PDF partagées (report-parts.php), consommées par les packs.
if ( ! defined( 'GACCT_REPORT_PARTS' ) ) {
	define( 'GACCT_REPORT_PARTS', dirname( __DIR__ ) . '/templates/report-parts.php' );
}

/* =============================================================================
 *  MODÈLES + CONFIGURATION (source unique des seuils, miroir JS)
 * ========================================================================== */

/**
 * REGISTRE DES PACKS DE RAPPORTS (architecture du 31/07/2026).
 *
 * Un pack = un plugin qui s'enregistre via le filtre
 * `gacct_report_register_packs` :
 *
 *   $packs['mon-pack'] = array(
 *     'label'         => 'Pack Mon Atelier',
 *     'models'        => callable → array( slug => définition de modèle ),
 *     'number_format' => '{year}{seq}',   // optionnel ({year} {yy} {seq} {seq4}…)
 *   );
 *
 * Définition de modèle :
 *   array(
 *     'label'       => 'Rapport voile…',
 *     'render_form' => callable( $revision, $order ),  // formulaire console
 *     'calc'        => callable( $data ) | null,       // interprétations serveur
 *     'template'    => '/chemin/absolu/template.php',  // PDF dompdf
 *     'js'          => array( handle => url ),         // calculs temps réel
 *   )
 *
 * Le framework (ce fichier) reste 100 % agnostique : coffre, brouillons,
 * numérotation, endpoints, dompdf, police, QR, carte console.
 */
function gacct_report_packs() {
	return (array) apply_filters( 'gacct_report_register_packs', array() );
}

/**
 * Identifiant du pack actif : réglage `pack` (Configuration > Rapports),
 * sinon le premier pack installé.
 */
function gacct_report_active_pack() {
	$packs = gacct_report_packs();

	if ( ! $packs ) {
		return '';
	}

	$settings = gacct_report_settings();
	$wanted   = isset( $settings['pack'] ) ? (string) $settings['pack'] : '';

	return isset( $packs[ $wanted ] ) ? $wanted : (string) array_key_first( $packs );
}

/**
 * Définitions complètes des modèles du pack actif (mémoïsé).
 *
 * @return array<string,array> slug → définition.
 */
function gacct_report_models_full() {
	static $models = null;

	if ( null !== $models ) {
		return $models;
	}

	$models = array();
	$packs  = gacct_report_packs();
	$active = gacct_report_active_pack();

	if ( $active && isset( $packs[ $active ]['models'] ) && is_callable( $packs[ $active ]['models'] ) ) {
		$models = (array) call_user_func( $packs[ $active ]['models'] );
	}

	$models = apply_filters( 'gacct_report_models_full', $models, $active );

	return $models;
}

/**
 * Modèles de rapport disponibles (slug → libellé) — rétrocompatible avec tous
 * les consommateurs historiques (carte console, colonne Documents, titres).
 */
function gacct_report_models() {
	$labels = array();

	foreach ( gacct_report_models_full() as $slug => $def ) {
		$labels[ $slug ] = isset( $def['label'] ) ? $def['label'] : $slug;
	}

	return apply_filters( 'gacct_report_models', $labels );
}

/* =============================================================================
 *  CALCULS (miroir exact du JS ; le PDF ne croit que ces fonctions)
 * ========================================================================== */

/**
 * Pire résultat d'une liste (ordre de sévérité du référentiel).
 * Les valeurs vides / NON RÉALISÉ sont ignorées ; si tout est NON RÉALISÉ → NON RÉALISÉ.
 */
function gacct_report_severity() {
	$severity = array( 'RÉFORME', 'LIMITE', 'ACCEPTABLE', 'BON ÉTAT', 'TRÈS BON ÉTAT', 'NEUF' );

	if ( function_exists( 'gacct_report_calc_config' ) ) {
		$config = gacct_report_calc_config();
		if ( ! empty( $config['severity'] ) ) {
			$severity = (array) $config['severity'];
		}
	}

	return apply_filters( 'gacct_report_severity', $severity );
}

function gacct_report_worst( array $results ) {
	$actual = array_filter( $results, static function ( $r ) {
		return '' !== $r && null !== $r && 'NON RÉALISÉ' !== $r && 'NON RÉALISÉ*' !== $r;
	} );

	if ( empty( $actual ) ) {
		return empty( $results ) ? '' : 'NON RÉALISÉ';
	}

	foreach ( gacct_report_severity() as $level ) {
		if ( in_array( $level, $actual, true ) ) {
			return $level;
		}
	}

	return '';
}

/**
 * Interprétation d'une valeur sur un barème [ {max, eq?, result} ].
 */
function gacct_report_scale_result( $value, array $scale ) {
	foreach ( $scale as $band ) {
		if ( null === $band['max'] ) {
			return $band['result'];
		}
		if ( ! empty( $band['eq'] ) ) {
			if ( $value <= $band['max'] ) {
				return $band['result'];
			}
		} elseif ( $value < $band['max'] ) {
			return $band['result'];
		}
	}

	return '';
}

/* =============================================================================
 *  RÉGLAGES DES RAPPORTS (onglet Configuration > Rapports)
 * ========================================================================== */

if ( ! defined( 'GACCT_REPORT_SETTINGS_OPT' ) ) {
	define( 'GACCT_REPORT_SETTINGS_OPT', 'gacct_report_settings' );
}

/**
 * Code couleur CANONIQUE des états — le même dans tous les rapports, tous les
 * modèles, tous les endroits (matrice, badges, légendes). Design validé le
 * 31/07/2026. [pâle (fonds), texte, soutenu (case cochée / en-tête)].
 */
function gacct_report_state_colors() {
	return apply_filters( 'gacct_report_state_colors', array(
		'NEUF'          => array( '#d9f6fb', '#0e6b75', '#67d5e4' ),
		'TRÈS BON ÉTAT' => array( '#d3f2e2', '#0d6b46', '#5fcda0' ),
		'BON ÉTAT'      => array( '#e1f7d9', '#2d6b1c', '#94dd7c' ),
		'CALAGE BON'    => array( '#e1f7d9', '#2d6b1c', '#94dd7c' ),
		'ACCEPTABLE'    => array( '#fdf6cf', '#7d6410', '#f0d264' ),
		'LIMITE'        => array( '#ffe8cf', '#8d4a12', '#f5b56b' ),
		'RÉFORME'       => array( '#fddede', '#8f1d1d', '#f28b8b' ),
	) );
}

/**
 * Polices disponibles pour les PDF (TTF vendorés — dompdf ne sait pas charger
 * autre chose). Pour ajouter une police client : déposer les TTF dans
 * assets/vendor/<slug>/ et l'ajouter ici (ou via le filtre).
 */
function gacct_report_fonts() {
	$base = dirname( __DIR__ ) . '/assets/vendor/nunito/';

	return apply_filters( 'gacct_report_fonts', array(
		'nunito' => array(
			'label'  => 'Nunito (police du site)',
			'family' => 'Nunito',
			'files'  => array(
				'normal'      => $base . 'Nunito-Regular.ttf',
				'bold'        => $base . 'Nunito-Bold.ttf',
				'italic'      => $base . 'Nunito-Italic.ttf',
				'bold_italic' => $base . 'Nunito-BoldItalic.ttf',
			),
		),
		'dejavu' => array(
			'label'  => 'DejaVu Sans (intégrée dompdf)',
			'family' => 'DejaVu Sans',
			'files'  => array(),
		),
	) );
}

/**
 * Réglages des rapports, fusionnés avec les défauts.
 */
function gacct_report_settings() {
	$defaults = array(
		'pack'       => '',
		'font'       => 'nunito',
		'qr_enabled' => 0,
		'qr_url'     => '',
		'qr_title'   => 'Gagnez votre prochaine révision périodique ParachecK en répondant à l\'enquête qualité !',
		'qr_subtext' => 'Tirage au sort lors de la prochaine coupe Icare.',
	);

	$saved = get_option( GACCT_REPORT_SETTINGS_OPT, array() );

	return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
}

/**
 * CSS @font-face + famille de la police configurée (utilisé par les templates).
 *
 * @return array { css: string, family: string }
 */
function gacct_report_font_css() {
	$settings = gacct_report_settings();
	$fonts    = gacct_report_fonts();
	$font     = isset( $fonts[ $settings['font'] ] ) ? $fonts[ $settings['font'] ] : $fonts['dejavu'];

	$css = '';
	$map = array(
		'normal'      => array( 'normal', 'normal' ),
		'bold'        => array( 'normal', 'bold' ),
		'italic'      => array( 'italic', 'normal' ),
		'bold_italic' => array( 'italic', 'bold' ),
	);

	foreach ( $font['files'] as $variant => $path ) {
		if ( isset( $map[ $variant ] ) && file_exists( $path ) ) {
			$css .= "@font-face { font-family: '" . $font['family'] . "'; font-style: " . $map[ $variant ][0]
				. '; font-weight: ' . $map[ $variant ][1] . "; src: url('" . $path . "') format('truetype'); }\n";
		}
	}

	// DejaVu reste en repli (accents + symboles ✔✘ absents de la plupart des polices).
	return array(
		'css'    => $css,
		'family' => "'" . $font['family'] . "', \"DejaVu Sans\", sans-serif",
	);
}

/**
 * Répertoire de cache des rapports (polices dompdf + QR), dans uploads.
 */
function gacct_report_cache_dir() {
	$uploads = wp_upload_dir( null, false );

	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return '';
	}

	$dir = trailingslashit( $uploads['basedir'] ) . 'gacct_report_cache';

	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return '';
	}

	if ( ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", LOCK_EX );
	}

	return $dir;
}

/**
 * PNG du QR de l'enquête qualité (généré localement, chillerlan/php-qrcode
 * vendored, mis en cache par URL). '' si le bloc est désactivé ou sans URL.
 */
function gacct_report_qr_png_path() {
	$settings = gacct_report_settings();
	$url      = trim( (string) $settings['qr_url'] );

	if ( empty( $settings['qr_enabled'] ) || '' === $url ) {
		return '';
	}

	$dir = gacct_report_cache_dir();

	if ( ! $dir ) {
		return '';
	}

	$path = $dir . '/qr-' . md5( $url ) . '.png';

	if ( file_exists( $path ) ) {
		return $path;
	}

	require_once dirname( __DIR__ ) . '/assets/vendor/php-qrcode/autoload.php';

	try {
		$options = new \chillerlan\QRCode\QROptions( array(
			'outputType'   => \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,
			'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::M,
			'scale'        => 8,
			'outputBase64' => false,
		) );

		$png = ( new \chillerlan\QRCode\QRCode( $options ) )->render( $url );
	} catch ( \Throwable $e ) {
		return '';
	}

	if ( ! $png || false === @file_put_contents( $path, $png, LOCK_EX ) ) {
		return '';
	}

	return $path;
}

/**
 * Onglet « Rapports » de l'écran Configuration (compteur de numérotation,
 * police des PDF, bloc QR enquête qualité).
 */
function gacct_report_render_config_tab() {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Acces refuse.', 'gestion-atelier-cct' ) );
	}

	$notice = '';

	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['gacct_report_settings_submit'] ) ) {
		if ( ! isset( $_POST['_gacct_report_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gacct_report_nonce'] ) ), 'gacct_report_settings' ) ) {
			$notice = '<div class="notice notice-error"><p>' . esc_html__( 'Vérification de sécurité échouée.', 'gestion-atelier-cct' ) . '</p></div>';
		} else {
			$fonts   = gacct_report_fonts();
			$font    = isset( $_POST['report_font'] ) ? sanitize_key( wp_unslash( $_POST['report_font'] ) ) : 'nunito';
			$counter = isset( $_POST['report_counter'] ) ? absint( wp_unslash( $_POST['report_counter'] ) ) : 0;

			if ( $counter < 1 || $counter > 999999 ) {
				$notice = '<div class="notice notice-error"><p>' . esc_html__( 'Le compteur doit être compris entre 1 et 999999.', 'gestion-atelier-cct' ) . '</p></div>';
			} else {
				update_option( GACCT_REPORT_COUNTER_OPT, $counter, false );
				$packs = gacct_report_packs();
				$pack  = isset( $_POST['report_pack'] ) ? sanitize_key( wp_unslash( $_POST['report_pack'] ) ) : '';

				update_option( GACCT_REPORT_SETTINGS_OPT, array(
					'pack'       => isset( $packs[ $pack ] ) ? $pack : '',
					'font'       => isset( $fonts[ $font ] ) ? $font : 'nunito',
					'qr_enabled' => empty( $_POST['qr_enabled'] ) ? 0 : 1,
					'qr_url'     => isset( $_POST['qr_url'] ) ? esc_url_raw( wp_unslash( $_POST['qr_url'] ) ) : '',
					'qr_title'   => isset( $_POST['qr_title'] ) ? sanitize_text_field( wp_unslash( $_POST['qr_title'] ) ) : '',
					'qr_subtext' => isset( $_POST['qr_subtext'] ) ? sanitize_text_field( wp_unslash( $_POST['qr_subtext'] ) ) : '',
				), false );
				$notice = '<div class="notice notice-success"><p>' . esc_html__( 'Réglages des rapports enregistrés.', 'gestion-atelier-cct' ) . '</p></div>';
			}
		}
	}

	$settings = gacct_report_settings();
	$fonts    = gacct_report_fonts();

	echo '<div class="wrap gacct-wrap">';
	echo '<h1>' . esc_html__( 'Rapports de contrôle', 'gestion-atelier-cct' ) . '</h1>';
	echo wp_kses_post( $notice );

	echo '<form class="gacct-form" method="post" action="' . esc_url( GACCT_Plugin::config_tab_url( 'rapports' ) ) . '">';
	wp_nonce_field( 'gacct_report_settings', '_gacct_report_nonce' );

	echo '<table class="form-table" role="presentation"><tbody>';

	$packs  = gacct_report_packs();
	$active = gacct_report_active_pack();

	echo '<tr><th scope="row">' . esc_html__( 'Pack de rapports actif', 'gestion-atelier-cct' ) . '</th><td>';
	if ( ! $packs ) {
		echo '<em>' . esc_html__( 'Aucun pack de rapports installé — activez un plugin de pack (ex. « Pack Altitude Révision »).', 'gestion-atelier-cct' ) . '</em>';
	} elseif ( 1 === count( $packs ) ) {
		echo '<strong>' . esc_html( $packs[ $active ]['label'] ) . '</strong>';
		echo '<input type="hidden" name="report_pack" value="' . esc_attr( $active ) . '">';
		echo '<p class="description">' . esc_html__( 'Un seul pack installé : il est sélectionné automatiquement. Les modèles de rapport, seuils, textes et design PDF viennent du pack.', 'gestion-atelier-cct' ) . '</p>';
	} else {
		echo '<select name="report_pack">';
		foreach ( $packs as $pack_id => $pack_def ) {
			echo '<option value="' . esc_attr( $pack_id ) . '"' . selected( $active, $pack_id, false ) . '>' . esc_html( $pack_def['label'] ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Les modèles de rapport, seuils, textes et design PDF viennent du pack sélectionné.', 'gestion-atelier-cct' ) . '</p>';
	}
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="gacct_report_counter_field">' . esc_html__( 'Prochain numéro (compteur)', 'gestion-atelier-cct' ) . '</label></th><td>';
	echo '<input type="number" id="gacct_report_counter_field" name="report_counter" min="1" max="999999" step="1" value="' . esc_attr( gacct_report_counter() ) . '" required>';
	echo '<p class="description">' . esc_html( sprintf(
		__( 'Numérotation : année + compteur, séquence commune à tous les modèles, figée à la première génération du PDF. Prochain numéro : %s.', 'gestion-atelier-cct' ),
		gacct_report_peek_number()
	) ) . '</p></td></tr>';

	echo '<tr><th scope="row"><label for="gacct_report_font">' . esc_html__( 'Police des PDF', 'gestion-atelier-cct' ) . '</label></th><td>';
	echo '<select id="gacct_report_font" name="report_font">';
	foreach ( $fonts as $key => $font ) {
		echo '<option value="' . esc_attr( $key ) . '"' . selected( $settings['font'], $key, false ) . '>' . esc_html( $font['label'] ) . '</option>';
	}
	echo '</select>';
	echo '<p class="description">' . esc_html__( 'Effective à la prochaine génération / régénération. Pour ajouter une police : déposer ses fichiers TTF dans le plugin (filtre gacct_report_fonts).', 'gestion-atelier-cct' ) . '</p></td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Bloc QR « enquête qualité »', 'gestion-atelier-cct' ) . '</th><td>';
	echo '<label><input type="checkbox" name="qr_enabled" value="1"' . checked( $settings['qr_enabled'], 1, false ) . '> ' . esc_html__( 'Afficher le bloc QR en fin de rapport', 'gestion-atelier-cct' ) . '</label>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="gacct_report_qr_url">' . esc_html__( 'Lien du QR code', 'gestion-atelier-cct' ) . '</label></th><td>';
	echo '<input type="url" id="gacct_report_qr_url" name="qr_url" class="regular-text" value="' . esc_attr( $settings['qr_url'] ) . '" placeholder="https://…">';
	echo '<p class="description">' . esc_html__( 'Le QR est généré localement (aucun service externe). Bloc masqué si le lien est vide.', 'gestion-atelier-cct' ) . '</p></td></tr>';

	echo '<tr><th scope="row"><label for="gacct_report_qr_title">' . esc_html__( 'Texte du bloc QR', 'gestion-atelier-cct' ) . '</label></th><td>';
	echo '<input type="text" id="gacct_report_qr_title" name="qr_title" class="large-text" value="' . esc_attr( $settings['qr_title'] ) . '">';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="gacct_report_qr_subtext">' . esc_html__( 'Sous-texte du bloc QR', 'gestion-atelier-cct' ) . '</label></th><td>';
	echo '<input type="text" id="gacct_report_qr_subtext" name="qr_subtext" class="large-text" value="' . esc_attr( $settings['qr_subtext'] ) . '">';
	echo '</td></tr>';

	echo '</tbody></table>';
	submit_button( __( 'Enregistrer les réglages', 'gestion-atelier-cct' ), 'primary', 'gacct_report_settings_submit' );
	echo '</form></div>';
}

/**
 * Enregistre l'onglet dans l'écran Configuration.
 *
 * Uniquement si un pack de rapports est installé : sans pack (atelier en
 * upload manuel, ex. AEROTECH), la numérotation, la police PDF et le bloc QR
 * n'ont aucun objet — l'onglet n'apparaît pas.
 */
function gacct_report_register_config_tab( $tabs ) {
	if ( ! gacct_report_packs() ) {
		return $tabs;
	}

	$tabs['rapports'] = array( __( 'Rapports', 'gestion-atelier-cct' ), 'gacct_report_render_config_tab' );

	return $tabs;
}
add_filter( 'gacct_config_tabs', 'gacct_report_register_config_tab' );

/* =============================================================================
 *  NUMÉROTATION (séquence AAAA + compteur, commune aux 3 modèles)
 * ========================================================================== */

/**
 * Prochain compteur (option, défaut 1). Valeur de départ réglable dans
 * Gestion Atelier > Configuration > Atelier.
 */
function gacct_report_counter() {
	return max( 1, absint( get_option( GACCT_REPORT_COUNTER_OPT, 1 ) ) );
}

/**
 * Formate un numéro selon le gabarit du pack actif (défaut '{year}{seq}' :
 * AAAA + compteur sur 3 chiffres, ex. 2026001). Jetons : {year} {yy}
 * {seq} (3 chiffres) {seq4} {seq5} {raw} (compteur brut).
 */
function gacct_report_format_number( $counter ) {
	$packs  = gacct_report_packs();
	$active = gacct_report_active_pack();
	$format = ( $active && ! empty( $packs[ $active ]['number_format'] ) ) ? $packs[ $active ]['number_format'] : '{year}{seq}';
	$format = apply_filters( 'gacct_report_number_format', $format, $active );

	$now = current_time( 'timestamp' );

	return strtr( $format, array(
		'{year}' => gmdate( 'Y', $now ),
		'{yy}'   => gmdate( 'y', $now ),
		'{seq}'  => str_pad( (string) $counter, 3, '0', STR_PAD_LEFT ),
		'{seq4}' => str_pad( (string) $counter, 4, '0', STR_PAD_LEFT ),
		'{seq5}' => str_pad( (string) $counter, 5, '0', STR_PAD_LEFT ),
		'{raw}'  => (string) $counter,
	) );
}

/**
 * Prochain numéro complet (aperçu, sans consommer).
 */
function gacct_report_peek_number() {
	return gacct_report_format_number( gacct_report_counter() );
}

/**
 * Consomme un numéro : renvoie le numéro courant et incrémente le compteur.
 */
function gacct_report_consume_number() {
	$number = gacct_report_peek_number();
	update_option( GACCT_REPORT_COUNTER_OPT, gacct_report_counter() + 1, false );

	return $number;
}

/* =============================================================================
 *  STOCKAGE DES RAPPORTS (champ CCT revision.rapports_json)
 * ========================================================================== */

/**
 * Entrées de rapport d'une révision (SQL direct — pas de cache JetEngine).
 *
 * @return array[] Chaque entrée : { id, model, status(draft|final), number,
 *                 attachment_id, created, updated, data }.
 */
function gacct_report_entries( $revision_id ) {
	global $wpdb;

	$raw = $wpdb->get_var( $wpdb->prepare(
		"SELECT rapports_json FROM {$wpdb->prefix}jet_cct_revision WHERE _ID = %d LIMIT 1",
		absint( $revision_id )
	) );

	$entries = json_decode( (string) $raw, true );

	if ( ! is_array( $entries ) ) {
		return array();
	}

	return array_values( array_filter( $entries, static function ( $e ) {
		return is_array( $e ) && ! empty( $e['id'] ) && ! empty( $e['model'] );
	} ) );
}

/**
 * Écrit la liste des entrées.
 */
function gacct_report_entries_save( $revision_id, array $entries ) {
	return (bool) jwcct_update_cct_item(
		JWCCT_CCT_REVISION,
		absint( $revision_id ),
		array( 'rapports_json' => wp_json_encode( array_values( $entries ) ) )
	);
}

/**
 * Une entrée par son id.
 */
function gacct_report_entry_get( $revision_id, $report_id ) {
	foreach ( gacct_report_entries( $revision_id ) as $entry ) {
		if ( $entry['id'] === $report_id ) {
			return $entry;
		}
	}

	return null;
}

/**
 * Crée ou met à jour une entrée. $payload = données du formulaire (data),
 * champs number/status/attachment_id gérés ici.
 *
 * @return array|WP_Error L'entrée enregistrée.
 */
function gacct_report_entry_save( $revision_id, $report_id, $model, array $data, array $overrides = array() ) {
	$models = gacct_report_models();

	if ( ! isset( $models[ $model ] ) ) {
		return new WP_Error( 'gacct_report_bad_model', __( 'Modèle de rapport inconnu.', 'gestion-atelier-cct' ) );
	}

	$entries = gacct_report_entries( $revision_id );
	$found   = null;

	foreach ( $entries as $i => $entry ) {
		if ( $entry['id'] === $report_id ) {
			$found = $i;
			break;
		}
	}

	$now = current_time( 'mysql' );

	if ( null === $found ) {
		$entry = array(
			// Minuscules uniquement : les endpoints passent l'id par sanitize_key(),
			// qui minusculise — un id mixte créerait un doublon au lieu d'une mise à jour.
			'id'            => $report_id ? $report_id : 'r' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 10 ),
			'model'         => $model,
			'status'        => 'draft',
			'number'        => '',
			'attachment_id' => 0,
			'author_id'     => get_current_user_id(),
			'created'       => $now,
			'updated'       => $now,
			'data'          => $data,
		);
		$entries[] = $entry;
	} else {
		$entry            = $entries[ $found ];
		$entry['data']    = $data;
		$entry['updated'] = $now;
		$entry['model']   = $model;
	}

	foreach ( $overrides as $key => $value ) {
		$entry[ $key ] = $value;
	}

	if ( null === $found ) {
		$entries[ count( $entries ) - 1 ] = $entry;
	} else {
		$entries[ $found ] = $entry;
	}

	if ( ! gacct_report_entries_save( $revision_id, $entries ) ) {
		return new WP_Error( 'gacct_report_save_failed', __( 'L\'enregistrement du brouillon a échoué.', 'gestion-atelier-cct' ) );
	}

	return $entry;
}

/**
 * Supprime une entrée (et sa pièce jointe si demandé).
 */
function gacct_report_entry_delete( $revision_id, $report_id ) {
	$entries = gacct_report_entries( $revision_id );
	$kept    = array();
	$removed = null;

	foreach ( $entries as $entry ) {
		if ( $entry['id'] === $report_id ) {
			$removed = $entry;
			continue;
		}
		$kept[] = $entry;
	}

	if ( null === $removed ) {
		return new WP_Error( 'gacct_report_not_found', __( 'Rapport introuvable.', 'gestion-atelier-cct' ) );
	}

	if ( ! gacct_report_entries_save( $revision_id, $kept ) ) {
		return new WP_Error( 'gacct_report_save_failed', __( 'La mise à jour du dossier a échoué.', 'gestion-atelier-cct' ) );
	}

	return $removed;
}

/**
 * Quand un PDF est supprimé via l'endpoint existant (gacct_op_delete_report),
 * l'entrée correspondante redevient un brouillon (données conservées).
 */
function gacct_report_entry_detach_attachment( $revision_id, $attachment_id ) {
	$entries = gacct_report_entries( $revision_id );
	$dirty   = false;

	foreach ( $entries as $i => $entry ) {
		if ( absint( $entry['attachment_id'] ?? 0 ) === absint( $attachment_id ) ) {
			$entries[ $i ]['attachment_id'] = 0;
			$entries[ $i ]['status']        = 'draft';
			$dirty = true;
		}
	}

	if ( $dirty ) {
		gacct_report_entries_save( $revision_id, $entries );
	}
}

/* =============================================================================
 *  GÉNÉRATION PDF (dompdf vendored → coffre-fort)
 * ========================================================================== */

/**
 * Charge dompdf (autoloader du release vendored).
 */
function gacct_report_load_dompdf() {
	if ( class_exists( '\\Dompdf\\Dompdf' ) ) {
		return true;
	}

	$autoload = dirname( __DIR__ ) . '/assets/vendor/dompdf/autoload.inc.php';

	if ( ! file_exists( $autoload ) ) {
		return false;
	}

	require_once $autoload;

	return class_exists( '\\Dompdf\\Dompdf' );
}

/**
 * Données d'identification communes aux 3 modèles (white-label : logo et
 * couleur = réglages WooCommerce > E-mails, comme le bon d'intervention).
 *
 * @param array $row   Ligne CCT revision (SQL direct).
 * @param array $entry Entrée de rapport.
 * @return array
 */
function gacct_report_pdf_context( array $row, array $entry ) {
	global $wpdb;

	// gacct_report_revision_row() ne rapporte que quelques colonnes : les
	// champs matériel (taille, couleur, n° série, PTV) viennent de la ligne
	// complète.
	$full = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}jet_cct_revision WHERE _ID = %d LIMIT 1",
		absint( $row['_ID'] ?? 0 )
	), ARRAY_A );

	if ( $full ) {
		$row = array_merge( $full, $row );
	}

	$order  = ! empty( $row['order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( absint( $row['order_id'] ) ) : false;
	$data   = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
	$ident  = isset( $data['ident'] ) && is_array( $data['ident'] ) ? $data['ident'] : array();
	$logo   = get_option( 'woocommerce_email_header_image' );
	$author = ! empty( $data['author_id'] ) ? absint( $data['author_id'] ) : absint( $entry['author_id'] ?? 0 );

	$defaults = array(
		'nom'     => $order ? $order->get_billing_last_name() : '',
		'prenom'  => $order ? $order->get_billing_first_name() : '',
		'contact' => $order ? implode( ' — ', array_filter( array( $order->get_billing_email(), $order->get_billing_phone() ) ) ) : '',
		'marque'  => ucfirst( trim( (string) ( $row['marque'] ?? '' ) ) ),
		'modele'  => trim( (string) ( $row['modele'] ?? '' ) ),
		'taille'  => trim( (string) ( $row['taille'] ?? '' ) ),
		'couleur' => trim( (string) ( $row['couleur'] ?? '' ) ),
		'serie'   => trim( (string) ( $row['numero_de_serie'] ?? '' ) ),
		'ptv'     => trim( (string) ( $row['p_t_v'] ?? '' ) ),
	);

	foreach ( $defaults as $key => $fallback ) {
		if ( ! isset( $ident[ $key ] ) || '' === trim( (string) $ident[ $key ] ) ) {
			$ident[ $key ] = $fallback;
		}
	}

	$accent = sanitize_hex_color( get_option( 'woocommerce_email_base_color', '#20c4c3' ) );

	return array(
		'site_name'   => get_bloginfo( 'name' ),
		'logo_url'    => $logo ? $logo : '',
		'logo_path'   => $logo ? gacct_report_local_image_path( $logo ) : '',
		'accent'      => $accent ? $accent : '#20c4c3',
		'number'      => (string) ( $entry['number'] ?? '' ),
		'date'        => date_i18n( get_option( 'date_format' ), current_time( 'timestamp' ) ),
		'author'      => $author && function_exists( 'gacct_op_operator_name' ) ? gacct_op_operator_name( $author ) : '',
		'signature_path' => $author && function_exists( 'gacct_report_signature_path' ) ? gacct_report_signature_path( $author ) : '',
		'ident'       => $ident,
		'order'       => $order,
		'reference'   => $order ? $order->get_order_number() : sprintf( 'dossier-%d', absint( $row['_ID'] ) ),
	);
}

/**
 * Chemin local d'une image du site (dompdf est plus fiable en accès fichier
 * qu'en HTTP) — '' si l'URL ne pointe pas dans uploads.
 */
function gacct_report_local_image_path( $url ) {
	$uploads = wp_upload_dir( null, false );

	if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
		return '';
	}

	if ( 0 !== strpos( $url, $uploads['baseurl'] ) ) {
		return '';
	}

	$path = $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );

	return file_exists( $path ) ? $path : '';
}

/**
 * HTML complet du PDF d'une entrée (template PHP par modèle).
 *
 * @return string|WP_Error
 */
function gacct_report_render_html( array $row, array $entry ) {
	$model  = (string) $entry['model'];
	$models = gacct_report_models_full();

	if ( ! isset( $models[ $model ] ) ) {
		return new WP_Error( 'gacct_report_bad_model', __( 'Modèle de rapport inconnu (pack de rapports absent ?).', 'gestion-atelier-cct' ) );
	}

	$def  = $models[ $model ];
	$file = isset( $def['template'] ) ? (string) $def['template'] : '';

	if ( '' === $file || ! file_exists( $file ) ) {
		return new WP_Error( 'gacct_report_no_template', __( 'Template PDF introuvable.', 'gestion-atelier-cct' ) );
	}

	$context = gacct_report_pdf_context( $row, $entry );
	$data    = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();

	// Interprétations recalculées côté serveur, par le calcul du pack.
	$calc = null;
	if ( ! empty( $def['calc'] ) && is_callable( $def['calc'] ) ) {
		$calc = call_user_func( $def['calc'], $data );
	}

	ob_start();
	include $file;

	return ob_get_clean();
}

/**
 * Génère (ou régénère) le PDF d'une entrée : numéro figé à la première
 * génération, PDF écrit directement dans le coffre, pièce jointe créée ou
 * remplacée, ID ajouté à rapport_pdf.
 *
 * @return array|WP_Error Entrée mise à jour + { url }.
 */
function gacct_report_generate( $revision_id, $report_id ) {
	$revision_id = absint( $revision_id );
	$row         = gacct_report_revision_row( $revision_id );

	if ( ! $row ) {
		return new WP_Error( 'gacct_report_not_found', __( 'Dossier introuvable.', 'gestion-atelier-cct' ) );
	}

	$entry = gacct_report_entry_get( $revision_id, $report_id );

	if ( ! $entry ) {
		return new WP_Error( 'gacct_report_not_found', __( 'Rapport introuvable.', 'gestion-atelier-cct' ) );
	}

	if ( ! gacct_report_load_dompdf() ) {
		return new WP_Error( 'gacct_report_no_dompdf', __( 'La librairie PDF (dompdf) est introuvable.', 'gestion-atelier-cct' ) );
	}

	// Les packs peuvent bloquer la clôture (WP_Error) : cases de sécurité
	// obligatoires, champs indispensables… La sauvegarde du brouillon, elle,
	// reste toujours libre.
	$valid = apply_filters( 'gacct_report_validate_generate', true, $entry, $row );

	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	// Numéro : saisi manuellement dans le formulaire, sinon séquence auto —
	// figé une fois posé.
	$manual = isset( $entry['data']['number'] ) ? trim( (string) $entry['data']['number'] ) : '';

	if ( empty( $entry['number'] ) ) {
		$entry['number'] = '' !== $manual ? sanitize_text_field( $manual ) : gacct_report_consume_number();
	} elseif ( '' !== $manual && $manual !== $entry['number'] ) {
		// Correction manuelle explicite du numéro (autorisée, maquette §4).
		$entry['number'] = sanitize_text_field( $manual );
	}

	$html = gacct_report_render_html( $row, $entry );

	if ( is_wp_error( $html ) ) {
		return $html;
	}

	$options = new \Dompdf\Options();
	$options->set( 'isRemoteEnabled', false );
	$options->set( 'chroot', array( WP_CONTENT_DIR ) );
	$options->set( 'defaultFont', 'DejaVu Sans' );

	// Police configurée (Configuration > Rapports) : dompdf doit pouvoir écrire
	// ses métriques — cache dans uploads/gacct_report_cache.
	$cache_dir = gacct_report_cache_dir();
	if ( $cache_dir ) {
		$options->set( 'fontDir', $cache_dir );
		$options->set( 'fontCache', $cache_dir );
		$options->set( 'isFontSubsettingEnabled', true );
	}

	$dompdf = new \Dompdf\Dompdf( $options );
	$dompdf->loadHtml( $html, 'UTF-8' );
	$dompdf->setPaper( 'A4' );
	$dompdf->render();
	$binary = $dompdf->output();

	if ( ! $binary || '%PDF' !== substr( $binary, 0, 4 ) ) {
		return new WP_Error( 'gacct_report_render_failed', __( 'La génération du PDF a échoué.', 'gestion-atelier-cct' ) );
	}

	$vault = gacct_report_vault_dir();

	if ( ! $vault ) {
		return new WP_Error( 'gacct_report_no_vault', __( 'Coffre-fort inaccessible.', 'gestion-atelier-cct' ) );
	}

	$models   = gacct_report_models();
	$basename = sanitize_file_name( sprintf( 'rapport-%s-%s.pdf', $entry['number'], $entry['model'] ) );

	$attachment_id = absint( $entry['attachment_id'] ?? 0 );
	$existing_file = $attachment_id ? get_attached_file( $attachment_id, true ) : '';

	if ( $attachment_id && $existing_file && gacct_report_is_in_vault( $existing_file ) ) {
		// Régénération : on remplace le fichier de CETTE pièce jointe
		// (les listes client et l'email d'état 7 ne bougent pas).
		if ( false === @file_put_contents( $existing_file, $binary, LOCK_EX ) ) {
			return new WP_Error( 'gacct_report_write_failed', __( 'Écriture du PDF impossible.', 'gestion-atelier-cct' ) );
		}
		wp_update_post( array(
			'ID'         => $attachment_id,
			'post_title' => sprintf( '%s — %s', $entry['number'], $models[ $entry['model'] ] ),
		) );
	} else {
		$filename = wp_unique_filename( $vault, $basename );
		$filepath = $vault . '/' . $filename;

		if ( false === @file_put_contents( $filepath, $binary, LOCK_EX ) ) {
			return new WP_Error( 'gacct_report_write_failed', __( 'Écriture du PDF impossible.', 'gestion-atelier-cct' ) );
		}

		$attachment_id = wp_insert_attachment( array(
			'post_mime_type' => 'application/pdf',
			'post_title'     => sprintf( '%s — %s', $entry['number'], $models[ $entry['model'] ] ),
			'post_status'    => 'inherit',
		), $filepath );

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			@unlink( $filepath );
			return new WP_Error( 'gacct_report_attach_failed', __( 'Impossible d\'enregistrer la pièce jointe.', 'gestion-atelier-cct' ) );
		}

		update_attached_file( $attachment_id, $filepath );

		$uploads = wp_upload_dir( null, false );
		$basedir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		wp_update_attachment_metadata( $attachment_id, array(
			'file' => ltrim( str_replace( $basedir, '', wp_normalize_path( $filepath ) ), '/' ),
		) );
	}

	// Ajout à rapport_pdf (sans doublon, uploads manuels conservés).
	$ids = gacct_report_ids( $row['rapport_pdf'] );
	if ( ! in_array( $attachment_id, $ids, true ) ) {
		$ids[] = $attachment_id;
	}

	if ( ! gacct_report_set_ids( $revision_id, $ids ) ) {
		return new WP_Error( 'gacct_report_save_failed', __( 'La mise à jour du dossier a échoué.', 'gestion-atelier-cct' ) );
	}

	$entry['attachment_id'] = $attachment_id;
	$entry['status']        = 'final';

	$saved = gacct_report_entry_save( $revision_id, $entry['id'], $entry['model'], $entry['data'], array(
		'number'        => $entry['number'],
		'attachment_id' => $attachment_id,
		'status'        => 'final',
	) );

	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	// « Réalisé par » automatique à la première génération.
	$revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );
	if ( $revision && empty( $revision['operateur_id'] ) ) {
		jwcct_update_cct_item( JWCCT_CCT_REVISION, $revision_id, array( 'operateur_id' => get_current_user_id() ) );
	}

	$order = $revision && function_exists( 'gacct_op_get_order_for_revision' ) ? gacct_op_get_order_for_revision( $revision ) : false;
	if ( $order && function_exists( 'gacct_op_add_signed_note' ) ) {
		gacct_op_add_signed_note( $order, sprintf(
			__( 'Rapport généré : %1$s n°%2$s (PDF)', 'gestion-atelier-cct' ),
			$models[ $entry['model'] ],
			$entry['number']
		) );
	}

	return array(
		'entry' => $saved,
		'url'   => gacct_report_download_url( $revision_id, $attachment_id ),
	);
}

/* =============================================================================
 *  ACCÈS OPÉRATEUR AUX PDF PENDANT L'INTERVENTION
 * ========================================================================== */

/**
 * L'atelier génère désormais les PDF depuis la fiche dès l'état 3 : il doit
 * pouvoir les RELIRE aussitôt (via le même endpoint sécurisé). On étend donc
 * l'accès opérateur de « état ≥ 6 » à « état ≥ 3 » par le filtre officiel de
 * gacct-reports.php — le client, lui, reste verrouillé à l'état ≥ 7.
 */
function gacct_report_operator_early_access( $access, $row, $user_id ) {
	if ( '' !== $access ) {
		return $access;
	}

	$state = (int) ( $row['etat_de_la_commande'] ?? 0 );

	$is_operator = user_can( $user_id, defined( 'GACCT_OP_CAP' ) ? GACCT_OP_CAP : 'gacct_operate' )
		|| user_can( $user_id, 'manage_woocommerce' );

	return ( $is_operator && $state >= 3 ) ? 'operator' : $access;
}
add_filter( 'gacct_report_access', 'gacct_report_operator_early_access', 10, 3 );

/* =============================================================================
 *  CHAMP CCT rapports_json (setup versionné v3)
 * ========================================================================== */

/**
 * Le setup versionné de la console (gacct_op_install_operator_field) ne
 * connaît pas ce champ : on l'ajoute nous-mêmes, idempotent, même mécanique.
 */
function gacct_report_install_field() {
	global $wpdb;

	$rev_table = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_REVISION;
	$column    = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$rev_table} LIKE %s", 'rapports_json' ) );

	if ( ! $column ) {
		$wpdb->query( "ALTER TABLE {$rev_table} ADD COLUMN rapports_json LONGTEXT NULL" );
	}

	$cct_row = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, meta_fields FROM {$wpdb->prefix}jet_post_types WHERE slug = %s AND status = 'content-type'",
		JWCCT_CCT_REVISION
	), ARRAY_A );

	if ( ! $cct_row ) {
		return;
	}

	$meta_fields = maybe_unserialize( $cct_row['meta_fields'] );

	if ( ! is_array( $meta_fields ) || in_array( 'rapports_json', wp_list_pluck( $meta_fields, 'name' ), true ) ) {
		return;
	}

	$meta_fields[] = array(
		'type'            => 'textarea',
		'title'           => 'Rapports de contrôle (JSON)',
		'name'            => 'rapports_json',
		'object_type'     => 'field',
		'width'           => '25%',
		'options'         => array(),
		'repeater-fields' => array(),
		'id'              => wp_rand( 100000, 999999 ),
		'isNested'        => false,
		'options_source'  => 'manual',
		'is_required'     => false,
	);

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

/**
 * Setup versionné dédié au module rapports.
 */
function gacct_report_maybe_setup() {
	if ( '1' === get_option( 'gacct_report_setup_version' ) ) {
		return;
	}

	gacct_report_install_field();
	update_option( 'gacct_report_setup_version', '1' );
}
add_action( 'init', 'gacct_report_maybe_setup', 6 );

/* =============================================================================
 *  ENDPOINTS AJAX (console : cap gacct_operate + nonce gacct_op_nonce)
 * ========================================================================== */

/**
 * Payload JSON commun aux 3 endpoints. Nettoyage récursif de chaînes.
 */
function gacct_report_read_payload() {
	$raw  = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
	$data = json_decode( (string) $raw, true );

	if ( ! is_array( $data ) ) {
		return array();
	}

	$clean = static function ( $value ) use ( &$clean ) {
		if ( is_array( $value ) ) {
			return array_map( $clean, $value );
		}
		if ( is_string( $value ) ) {
			return sanitize_textarea_field( $value );
		}
		return is_scalar( $value ) ? $value : '';
	};

	return array_map( $clean, $data );
}

/**
 * Sauvegarde d'un brouillon de rapport.
 */
function gacct_op_ajax_report_save() {
	gacct_op_api_guard();

	$revision_id = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;
	$report_id   = isset( $_POST['report_id'] ) ? sanitize_key( wp_unslash( $_POST['report_id'] ) ) : '';
	$model       = isset( $_POST['model'] ) ? sanitize_key( wp_unslash( $_POST['model'] ) ) : '';

	if ( ! gacct_report_revision_row( $revision_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Dossier introuvable.', 'gestion-atelier-cct' ) ) );
	}

	$entry = gacct_report_entry_save( $revision_id, $report_id, $model, gacct_report_read_payload() );

	if ( is_wp_error( $entry ) ) {
		wp_send_json_error( array( 'message' => $entry->get_error_message() ) );
	}

	wp_send_json_success( array( 'report_id' => $entry['id'], 'status' => $entry['status'] ) );
}
add_action( 'wp_ajax_gacct_op_report_save', 'gacct_op_ajax_report_save' );

/**
 * Suppression d'un rapport (brouillon, ou finalisé avec son PDF — mêmes
 * gardes que la suppression de PDF : jamais le dernier à partir de l'état 6).
 */
function gacct_op_ajax_report_delete() {
	gacct_op_api_guard();

	$revision_id = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;
	$report_id   = isset( $_POST['report_id'] ) ? sanitize_key( wp_unslash( $_POST['report_id'] ) ) : '';
	$row         = gacct_report_revision_row( $revision_id );
	$entry       = $row ? gacct_report_entry_get( $revision_id, $report_id ) : null;

	if ( ! $entry ) {
		wp_send_json_error( array( 'message' => __( 'Rapport introuvable.', 'gestion-atelier-cct' ) ) );
	}

	$attachment_id = absint( $entry['attachment_id'] ?? 0 );

	if ( $attachment_id ) {
		$ids   = gacct_report_ids( $row['rapport_pdf'] );
		$state = (int) $row['etat_de_la_commande'];

		if ( $state >= 6 && in_array( $attachment_id, $ids, true ) && 1 === count( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Impossible de supprimer le dernier rapport : il est exigé à partir de la demande de solde.', 'gestion-atelier-cct' ) ) );
		}

		gacct_report_set_ids( $revision_id, array_values( array_diff( $ids, array( $attachment_id ) ) ) );
		wp_delete_attachment( $attachment_id, true );
	}

	$removed = gacct_report_entry_delete( $revision_id, $report_id );

	if ( is_wp_error( $removed ) ) {
		wp_send_json_error( array( 'message' => $removed->get_error_message() ) );
	}

	$revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );
	$order    = $revision && function_exists( 'gacct_op_get_order_for_revision' ) ? gacct_op_get_order_for_revision( $revision ) : false;

	if ( $order && function_exists( 'gacct_op_add_signed_note' ) ) {
		gacct_op_add_signed_note( $order, sprintf( __( 'Rapport supprimé (%s)', 'gestion-atelier-cct' ), $entry['model'] ) );
	}

	wp_send_json_success();
}
add_action( 'wp_ajax_gacct_op_report_delete', 'gacct_op_ajax_report_delete' );

/**
 * Génération (ou régénération) du PDF : sauvegarde le payload PUIS génère.
 */
function gacct_op_ajax_report_generate() {
	gacct_op_api_guard();

	$revision_id = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;
	$report_id   = isset( $_POST['report_id'] ) ? sanitize_key( wp_unslash( $_POST['report_id'] ) ) : '';
	$model       = isset( $_POST['model'] ) ? sanitize_key( wp_unslash( $_POST['model'] ) ) : '';

	if ( ! gacct_report_revision_row( $revision_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Dossier introuvable.', 'gestion-atelier-cct' ) ) );
	}

	$entry = gacct_report_entry_save( $revision_id, $report_id, $model, gacct_report_read_payload() );

	if ( is_wp_error( $entry ) ) {
		wp_send_json_error( array( 'message' => $entry->get_error_message() ) );
	}

	$result = gacct_report_generate( $revision_id, $entry['id'] );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array(
		'report_id' => $result['entry']['id'],
		'number'    => $result['entry']['number'],
		'url'       => $result['url'],
	) );
}
add_action( 'wp_ajax_gacct_op_report_generate', 'gacct_op_ajax_report_generate' );
