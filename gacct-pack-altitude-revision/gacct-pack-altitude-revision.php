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
		'number_format' => '{year}{seq}', // ex. 2026001 — séquence de l'atelier.
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
