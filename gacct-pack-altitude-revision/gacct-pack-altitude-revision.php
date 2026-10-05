<?php
/**
 * Plugin Name: Pack Altitude Révision (rapports ParachecK®)
 * Description: Pack de modèles de rapports de contrôle pour Gestion Atelier CCT — rapport voile ParachecK® (révision périodique / inspection partielle), contrôle équipement (sellette/secours), calcul réforme suspente. Seuils et textes du classeur ParachecK V8, design validé le 31/07/2026.
 * Version: 1.0.0
 * Requires Plugins: gestion-atelier-cct
 * Author: CogitoWeb
 *
 * Ce plugin ne contient QUE le spécifique Altitude Révision / ParachecK® :
 * formulaires, seuils/formules, textes, templates PDF, JS de calcul, images
 * (badge FFVL, schéma de voile). Tout le circuit (coffre, brouillons,
 * numérotation, endpoints, dompdf, police, QR) vit dans le framework
 * gestion-atelier-cct (includes/gacct-report-forms*.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_PACK_AR_DIR', __DIR__ );
define( 'GACCT_PACK_AR_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/paracheck-config.php';
require_once __DIR__ . '/includes/paracheck-calcs.php';
require_once __DIR__ . '/includes/seo.php'; // titres, descriptions, robots (11/09/2026)
require_once __DIR__ . '/includes/redirects.php'; // 301 des URL de l ancien site (13/09/2026)

/**
 * Les formulaires utilisent les helpers gacct_rf_* du framework : chargés
 * seulement quand le framework est là (admin console).
 */
function gacct_pack_ar_load_forms() {
	if ( function_exists( 'gacct_rf_input' ) ) {
		require_once __DIR__ . '/includes/paracheck-forms.php';
	}
}
add_action( 'init', 'gacct_pack_ar_load_forms', 9 );

/**
 * Enregistrement du pack auprès du framework.
 */
function gacct_pack_ar_register( $packs ) {
	$packs['altitude-revision'] = array(
		'label'         => __( 'Pack Altitude Révision (ParachecK®)', 'gacct-pack-ar' ),
		'models'        => 'gacct_pack_ar_models',
		'number_format' => '{year}{seq4}', // ex. 20260001 — séquence de l'atelier (4 chiffres, décision Hervé du 10/08/2026 : viser le millier de voiles).
	);

	return $packs;
}
add_filter( 'gacct_report_register_packs', 'gacct_pack_ar_register' );

/**
 * Modèles du pack. Slugs STABLES (les brouillons/PDF existants les portent).
 */
function gacct_pack_ar_models() {
	$js = array( 'gacct-pack-ar-calcs' => GACCT_PACK_AR_URL . 'assets/js/paracheck-calcs.js' );

	return array(
		'voile'      => array(
			'label'       => __( 'Rapport voile ParachecK®', 'gacct-pack-ar' ),
			'render_form' => 'gacct_rf_render_voile_form',
			'calc'        => 'gacct_report_calc_voile',
			'template'    => GACCT_PACK_AR_DIR . '/templates/report-voile.php',
			'js'          => $js,
		),
		'equipement' => array(
			'label'       => __( 'Contrôle équipement (sellette / secours)', 'gacct-pack-ar' ),
			'render_form' => 'gacct_rf_render_equipement_form',
			'calc'        => null,
			'template'    => GACCT_PACK_AR_DIR . '/templates/report-equipement.php',
			'js'          => $js,
		),
		'suspente'   => array(
			'label'       => __( 'Calcul réforme suspente', 'gacct-pack-ar' ),
			'render_form' => 'gacct_rf_render_suspente_form',
			'calc'        => 'gacct_report_calc_suspente',
			'template'    => GACCT_PACK_AR_DIR . '/templates/report-suspente.php',
			'js'          => $js,
		),
	);
}

/* -----------------------------------------------------------------------------
 * Documentation interne (module gacct-docs.php). Les fichiers HTML vivent dans
 * docs/ du pack. Le guide atelier est public et listé dans l'écran
 * Documentation ; le cycle de vie est un document de travail : public mais
 * NON listé, accessible uniquement par son lien au slug non devinable
 * (décision Bastien du 28/08/2026).
 * -------------------------------------------------------------------------- */

add_filter( 'gacct_docs', 'gacct_pack_ar_docs' );

function gacct_pack_ar_docs( $docs ) {
	$docs['guide-atelier'] = array(
		'title'  => __( 'Guide de l\'atelier', 'gacct-pack-ar' ),
		'desc'   => __( 'Le mode d\'emploi de la console au quotidien, écran par écran.', 'gacct-pack-ar' ),
		'file'   => GACCT_PACK_AR_DIR . '/docs/guide-atelier.html',
		'public' => true,
		'listed' => true,
	);
	$docs['cycle-vie-rev-7q4hx2kd'] = array(
		'title'  => __( 'Cycle de vie d\'une révision', 'gacct-pack-ar' ),
		'desc'   => __( 'Document de travail : la référence complète du système.', 'gacct-pack-ar' ),
		'file'   => GACCT_PACK_AR_DIR . '/docs/cycle-vie-revision.html',
		'public' => true,
		'listed' => false,
	);

	return $docs;
}

/* -----------------------------------------------------------------------------
 * Formulaire de demande : produits cumulables (28/09/2026, retour Hervé du 16/09).
 * « Vérification et montage sur sellette » (produit 1239, catégorie Pliages
 * secours) s'ajoute à un pliage de secours au lieu de l'exclure.
 * -------------------------------------------------------------------------- */

add_filter( 'gacct_demande_cumulables_ids', 'gacct_pack_ar_cumulables_ids' );

function gacct_pack_ar_cumulables_ids( $ids ) {
	$ids[] = 1239;

	return array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
}

/* -----------------------------------------------------------------------------
 * Formulaire de demande : suspentes commandables seules (05/10/2026, retour
 * Hervé du 30/09). Un pilote qui ne veut qu'une suspente n'a plus à choisir
 * une révision ; le formulaire lui dit d'envoyer la suspente ou sa symétrique.
 * -------------------------------------------------------------------------- */

add_filter( 'gacct_demande_suspentes_seules', '__return_true' );

/* -----------------------------------------------------------------------------
 * 28/09/2026, retour Hervé du 15/09 : les points de porosité passent de l'ordre
 * du classeur (P4, P2, P1, P3) à l'ordre du schéma (P1, P2, P3, P4). Les mesures
 * étant stockées par INDEX dans data.porosity, les entrées enregistrées avant ce
 * changement sont réordonnées une seule fois (option gacct_paracheck_porosity_order_v2),
 * et reçoivent le drapeau data.porosity_order = p1234 que pose désormais le
 * formulaire voile à chaque sauvegarde. Les PDF déjà générés ne sont pas refaits.
 * -------------------------------------------------------------------------- */

/**
 * Réordonne les 4 mesures d'une entrée voile de l'ancien ordre vers P1..P4.
 * Ancien index 0 = P4, 1 = P2, 2 = P1, 3 = P3, d'où [P1, P2, P3, P4] =
 * [ancien[2], ancien[1], ancien[3], ancien[0]].
 *
 * @param array $entry Entrée de rapports_json.
 * @return array|null L'entrée modifiée, ou null si rien à faire.
 */
function gacct_pack_ar_reorder_porosity_entry( array $entry ) {
	if ( 'voile' !== ( $entry['model'] ?? '' ) || empty( $entry['data'] ) || ! is_array( $entry['data'] ) ) {
		return null;
	}

	$data = $entry['data'];

	if ( 'p1234' === ( $data['porosity_order'] ?? '' ) ) {
		return null;
	}

	if ( isset( $data['porosity'] ) && is_array( $data['porosity'] ) ) {
		$old = array_slice( array_pad( array_values( $data['porosity'] ), 4, '' ), 0, 4 );

		$data['porosity'] = array( $old[2], $old[1], $old[3], $old[0] );
	}

	$data['porosity_order'] = 'p1234';
	$entry['data']          = $data;

	return $entry;
}

/**
 * Passe unique et idempotente sur tous les dossiers ayant des rapports.
 */
function gacct_pack_ar_upgrade_porosity_order() {
	global $wpdb;

	if ( '1' === get_option( 'gacct_paracheck_porosity_order_v2' ) ) {
		return;
	}

	if ( ! function_exists( 'gacct_report_entries_save' ) || ! defined( 'JWCCT_CCT_REVISION' ) ) {
		return; // framework absent : on réessaiera au prochain chargement.
	}

	$rows = $wpdb->get_results(
		"SELECT _ID, rapports_json FROM {$wpdb->prefix}jet_cct_revision WHERE rapports_json IS NOT NULL AND rapports_json <> '' AND rapports_json <> '[]'",
		ARRAY_A
	);

	$upgraded = 0;
	$dossiers = 0;
	$failed   = 0;

	foreach ( (array) $rows as $row ) {
		$entries = json_decode( (string) $row['rapports_json'], true );

		if ( ! is_array( $entries ) ) {
			continue;
		}

		$changed = false;

		foreach ( $entries as $i => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$new = gacct_pack_ar_reorder_porosity_entry( $entry );
			if ( null !== $new ) {
				$entries[ $i ] = $new;
				$changed       = true;
				$upgraded++;
			}
		}

		if ( $changed ) {
			if ( gacct_report_entries_save( (int) $row['_ID'], $entries ) ) {
				$dossiers++;
			} else {
				$failed++;
			}
		}
	}

	$message = sprintf(
		'Porosité P1..P4 : %d entrée(s) réordonnée(s) sur %d dossier(s), %d échec(s) d\'écriture.',
		$upgraded,
		$dossiers,
		$failed
	);

	if ( function_exists( 'jwcct_log' ) ) {
		jwcct_log( $message );
	} else {
		error_log( '[gacct-pack-ar] ' . $message );
	}

	if ( 0 === $failed ) {
		update_option( 'gacct_paracheck_porosity_order_v2', '1', false );
	}
}
add_action( 'admin_init', 'gacct_pack_ar_upgrade_porosity_order', 20 );
