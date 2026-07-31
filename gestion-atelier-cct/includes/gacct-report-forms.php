<?php
/**
 * Rapports de contrôle — formulaires console + génération PDF serveur (28/07/2026).
 *
 * Trois MODÈLES de rapport, au choix de l'opérateur depuis la fiche console
 * (états 3 à 6), issus du classeur Excel « Rapport-paracheck-V8.xlsx »
 * (analyse : MAQUETTE-rapport-intervention.md à la racine du site) :
 *
 *   1. voile      — rapport voile ParachecK, avec un TYPE : « Révision
 *                   périodique » (onglet RAPPORT PARACHECK) ou « Inspection
 *                   partielle » (onglet RAPPORT). Design unifié, textes,
 *                   sections et légendes de seuils propres à chaque type.
 *   2. equipement — contrôle équipement (sellette + parachute de secours).
 *   3. suspente   — calcul du seuil de réforme pour la résistance des
 *                   suspentes (document client ET outil : son VR pré-remplit
 *                   le seuil du test de rupture du rapport voile).
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

/* =============================================================================
 *  MODÈLES + CONFIGURATION (source unique des seuils, miroir JS)
 * ========================================================================== */

/**
 * Modèles de rapport disponibles.
 *
 * @return array<string,string> slug → libellé.
 */
function gacct_report_models() {
	return apply_filters( 'gacct_report_models', array(
		'voile'      => __( 'Rapport voile ParachecK®', 'gestion-atelier-cct' ),
		'equipement' => __( 'Contrôle équipement (sellette / secours)', 'gestion-atelier-cct' ),
		'suspente'   => __( 'Calcul réforme suspente', 'gestion-atelier-cct' ),
	) );
}

/**
 * Types du rapport voile.
 */
function gacct_report_voile_types() {
	return array(
		'periodique' => __( 'Révision périodique ParachecK®', 'gestion-atelier-cct' ),
		'partielle'  => __( 'Inspection partielle ParachecK®', 'gestion-atelier-cct' ),
	);
}

/**
 * Configuration métier complète (items, seuils, coefficients, textes) —
 * valeurs EXACTES du classeur Excel V8, avec les corrections recommandées
 * par la maquette (§7) : barème de calcul = feuille de saisie, rupture des
 * suspentes complétée du bucket NEUF (marge = 100 %), champs de mesure réels
 * pour le Bettsometer.
 *
 * @return array
 */
function gacct_report_calc_config() {
	$config = array(
		// §1.4 — Inspection visuelle : 3 groupes × 6 items.
		'visual_groups' => array(
			'voile'      => array(
				'label' => __( 'VOILE', 'gestion-atelier-cct' ),
				'items' => array(
					__( 'Bord d\'attaque', 'gestion-atelier-cct' ),
					__( 'Extrados', 'gestion-atelier-cct' ),
					__( 'Intrados', 'gestion-atelier-cct' ),
					__( 'Structure interne', 'gestion-atelier-cct' ),
					__( 'Pattes d\'attache', 'gestion-atelier-cct' ),
					__( 'Propreté intérieure', 'gestion-atelier-cct' ),
				),
				'note'  => __( 'Structure interne = joncs, profils, diagonales et bandes', 'gestion-atelier-cct' ),
			),
			'suspentes'  => array(
				'label' => __( 'SUSPENTES', 'gestion-atelier-cct' ),
				'items' => array(
					__( 'Étage 1 (basses)', 'gestion-atelier-cct' ),
					__( 'Étage 2', 'gestion-atelier-cct' ),
					__( 'Étage 3', 'gestion-atelier-cct' ),
					__( 'Étage 4', 'gestion-atelier-cct' ),
					__( 'Étage 5', 'gestion-atelier-cct' ),
					__( 'Étage 6 (hautes)', 'gestion-atelier-cct' ),
				),
				'note'  => '',
			),
			'elevateurs' => array(
				'label' => __( 'ÉLÉVATEURS', 'gestion-atelier-cct' ),
				'items' => array(
					__( 'Sangles', 'gestion-atelier-cct' ),
					__( 'Connexions', 'gestion-atelier-cct' ),
					__( 'Poulies', 'gestion-atelier-cct' ),
					__( 'Drisses freins', 'gestion-atelier-cct' ),
					__( 'Poignées freins', 'gestion-atelier-cct' ),
					__( 'Connecteurs freins', 'gestion-atelier-cct' ),
				),
				'note'  => '',
			),
		),
		// Valeurs saisissables par item + poids de la moyenne (Excel : ACC.=10, B.E.=44, NEUF=75).
		'visual_values'  => array(
			'REF'  => array( 'label' => 'RÉF.', 'weight' => 0 ),
			'ACC'  => array( 'label' => 'ACC.', 'weight' => 10 ),
			'BE'   => array( 'label' => 'B.E.', 'weight' => 44 ),
			'NEUF' => array( 'label' => 'NEUF', 'weight' => 75 ),
		),
		// Interprétation d'un groupe selon sa moyenne (feuille de saisie B54).
		'visual_scale'   => array(
			array( 'max' => 0,   'eq' => true,  'result' => 'RÉFORME' ),
			array( 'max' => 10,  'eq' => false, 'result' => 'LIMITE' ),
			array( 'max' => 44,  'eq' => false, 'result' => 'ACCEPTABLE' ),
			array( 'max' => 75,  'eq' => false, 'result' => 'BON ÉTAT' ),
			array( 'max' => null, 'eq' => false, 'result' => 'TRÈS BON ÉTAT' ),
		),
		// §2.3 — Porosité (secondes, l/m²/min = 5400 / s), barème de la feuille de saisie.
		'porosity_points' => array( 'P4', 'P2', 'P1', 'P3', 'P5' ),
		'porosity_factor' => 5400,
		'porosity_scale'  => array(
			array( 'max' => 10,   'result' => 'RÉFORME' ),
			array( 'max' => 11,   'result' => 'LIMITE' ),
			array( 'max' => 20,   'result' => 'ACCEPTABLE' ),
			array( 'max' => 200,  'result' => 'BON ÉTAT' ),
			array( 'max' => null, 'result' => 'TRÈS BON ÉTAT' ),
		),
		// §2.4 — Déchirure (Bettsometer). Seuil minimal selon la moyenne de porosité.
		'tear_zones'     => array(
			'extrados' => __( 'Extrados', 'gestion-atelier-cct' ),
			'intrados' => __( 'Intrados', 'gestion-atelier-cct' ),
			'cloison'  => __( 'Cloison', 'gestion-atelier-cct' ),
		),
		'tear_min'       => array( 'porosity_gt' => 100, 'high' => 1.2, 'low' => 0.9 ),
		'tear_scale'     => array(
			array( 'max' => 0.6,  'result' => 'RÉFORME' ),
			array( 'max' => 0.63, 'result' => 'LIMITE' ),
			array( 'max' => 0.9,  'result' => 'ACCEPTABLE' ),
			array( 'max' => 1.17, 'result' => 'BON ÉTAT' ),
			array( 'max' => null, 'result' => 'TRÈS BON ÉTAT' ),
		),
		// §2.5 — Rupture des suspentes. Coefficients matériau (nominal × 0.9 × k)
		// et barème COMPLET (bucket NEUF = marge 100 %, correction maquette §7.2).
		'rupture_max_lines' => 5,
		'rupture_materials' => array(
			'dyneema' => array( 'label' => 'Dyneema', 'coef' => 0.585 ),
			'aramide' => array( 'label' => 'Aramide', 'coef' => 0.405 ),
			'vectran' => array( 'label' => 'Vectran', 'coef' => 0.405 ),
		),
		'rupture_scale'  => array(
			array( 'max' => 0,   'eq' => true,  'result' => 'RÉFORME' ),
			array( 'max' => 10,  'eq' => false, 'result' => 'LIMITE' ),
			array( 'max' => 25,  'eq' => false, 'result' => 'ACCEPTABLE' ),
			array( 'max' => 75,  'eq' => false, 'result' => 'BON ÉTAT' ),
			array( 'max' => 100, 'eq' => false, 'result' => 'TRÈS BON ÉTAT' ),
			array( 'max' => null, 'eq' => false, 'result' => 'NEUF' ),
		),
		// §1.8 — Calage / freins.
		'geometry_interps' => array(
			''            => __( '— à compléter —', 'gestion-atelier-cct' ),
			'CALAGE BON'  => 'CALAGE BON',
			'RÉFORME'     => 'RÉFORME',
			'NON RÉALISÉ' => 'NON RÉALISÉ',
		),
		'brake_settings' => array(
			''                   => __( '— à compléter —', 'gestion-atelier-cct' ),
			'Cotes constructeur' => __( 'Cotes constructeur', 'gestion-atelier-cct' ),
			'Réglage pilote'     => __( 'Réglage pilote', 'gestion-atelier-cct' ),
			'Non réalisé'        => __( 'Non réalisé', 'gestion-atelier-cct' ),
		),
		// §1.2 — Marques (Feuil1!C9:C37) + modèles contraints par marque.
		'brands'         => array(
			'Advance', 'Adventure', 'AirDesign', 'Airwaves', 'Apco', 'Axis', 'BGD',
			'Dudek', 'Flow', 'Gin', 'Gradient', 'Icaro', 'ITV', 'Jojowing',
			'Little Cloud', 'MacPara', 'Nervures', 'Niviuk', 'Nova', 'Ozone',
			'Paramania', 'Phi', 'Skyparagliders', 'Skywalk', 'Supair', 'Swing',
			'Triple Seven', 'Up', 'Windtech',
		),
		// Ordre du pire au meilleur, pour les agrégats « worst-of ».
		'severity'       => array( 'RÉFORME', 'LIMITE', 'ACCEPTABLE', 'BON ÉTAT', 'TRÈS BON ÉTAT', 'NEUF' ),
	);

	return apply_filters( 'gacct_report_calc_config', $config );
}

/**
 * Textes propres à chaque type de rapport voile — reproduits À L'IDENTIQUE
 * depuis leur onglet respectif (périodique = RAPPORT PARACHECK, partielle =
 * RAPPORT), légendes de seuils incluses.
 *
 * @return array
 */
function gacct_report_voile_texts( $type ) {
	$texts = array(
		'periodique' => array(
			'title'        => 'Révision périodique ParachecK®',
			'intro'        => 'La révision périodique ParachecK® permet de répondre aux exigences de la norme EN 926-2 en terme d\'entretien, pour informer le propriétaire ou l\'acheteur de la capacité d\'une aile à voler en sécurité, à un instant donné. Les inspections ParachecK® ne vous renseignent que partiellement sur son état.',
			'general_note' => 'Indice visuel à titre informatif établi selon l\'algorithme ParachecK®. Cet indice ne présage en rien d\'une durée de vie restante.',
			'conclusion'   => array(
				'La voile devrait conserver des caractéristiques conformes aux normes du constructeur pour une durée de plus de 100 heures dans le cadre d\'une utilisation normale. Cette durée tiens compte du fait que les constructeurs préconisent une révision toutes les 100 heures ou tous les ans. Il est possible que la voile présente encore des caractéristiques conformes à l\'issue de ce délai, mais nous ne pouvons engager notre responsabilité au-delà sans la réviser à nouveau.',
				'Un gonflage, voire un vol en pente école s\'impose après toute intervention sur votre voile.' . "\n" . 'Afin de prolonger la vie de votre aéronef, ne l\'exposez par inutilement au soleil et aux intempéries. Évitez de plier votre voile humide et interdisez vous les chocs thermiques extrêmement mauvais pour les matériaux. Ne laissez ni cailloux, ni insectes qui peuvent grignoter, secréter des acides, ou simplement pourrir et endommager gravement les tissus.',
			),
			'porosity_note' => 'Mesures réalisées sur l\'extrados sur l\'alvéole centrale, les dernières alvéoles ouvertes de chaque côté, et les alvéoles médianes entre les précédentes.',
			'rupture_intro' => 'Réalisé avec un dynamomètre DFW-03BT. Les valeurs sont exprimées en DaN. La première lettre indique la ligne d\'élévateur. Le chiffre indique le numéro de suspente en partant du centre de la voile. La troisième lettre indique l\'étage de suspente concerné. La dernière lettre indique le côté de la voile, dans le sens de vol.',
			'comment_default' => '',
			'legends'      => array(
				'visual'   => array( 'RÉFORME' => 'moyenne = 0', 'LIMITE' => '0 – 10', 'ACCEPTABLE' => '10 – 44', 'BON ÉTAT' => '44 – 75', 'TRÈS BON ÉTAT' => '> 75', 'NEUF' => '100' ),
				'porosity' => array( 'RÉFORME' => '< 10 s', 'LIMITE' => '10 – 11 s', 'ACCEPTABLE' => '11 – 20 s', 'BON ÉTAT' => '20 – 200 s', 'TRÈS BON ÉTAT' => '> 200 s' ),
				'tear'     => array( 'RÉFORME' => '< 0,6', 'LIMITE' => '0,6 – 0,63', 'ACCEPTABLE' => '0,63 – 0,9', 'BON ÉTAT' => '0,9 – 1,17', 'TRÈS BON ÉTAT' => '≥ 1,17' ),
				'rupture'  => array( 'RÉFORME' => '≤ 0 %', 'LIMITE' => '< 10 %', 'ACCEPTABLE' => '< 25 %', 'BON ÉTAT' => '< 75 %', 'TRÈS BON ÉTAT' => '≥ 75 %' ),
			),
			'show_general' => true,
			'show_results_summary' => false,
		),
		'partielle' => array(
			'title'        => 'Inspection partielle ParachecK®',
			'intro'        => 'Seule la révision périodique conforme à la norme EN 926-2 informe le propriétaire ou l\'acheteur de la capacité d\'une aile à voler en sécurité, à un instant donné. Les inspections partielles ne vous renseignent que partiellement sur son état.',
			'general_note' => 'Seule une révision périodique portant sur l\'ensemble de la voile permet de définir son état général.',
			'conclusion'   => array(
				'La voile devrait conserver des caractéristiques conformes aux normes du constructeur pour une durée de plus de 100 heures dans le cadre d\'une utilisation normale. Cette durée tiens compte du fait que les constructeurs préconisent une révision toutes les 100 heures ou tous les ans. Il est possible que la voile présente encore des caractéristiques conformes à l\'issue de ce délai, mais nous ne pouvons engager notre responsabilité au-delà sans la réviser à nouveau.',
				'Un gonflage, voire un vol en pente école s\'impose après toute intervention sur votre voile.' . "\n" . 'Afin de prolonger la vie de votre aéronef, ne l\'exposez par inutilement au soleil et aux intempéries. Évitez de plier votre voile humide et interdisez vous les chocs thermiques extrêmement mauvais pour les matériaux. Ne laissez ni cailloux, ni insectes qui peuvent grignoter, secréter des acides, ou simplement pourrir et endommager gravement les tissus.',
			),
			'porosity_note' => 'Mesures réalisées sur l\'alvéole centrale, les dernières alvéoles ouvertes de chaque côté, et les alvéoles médianes entre les précédentes.',
			'rupture_intro' => 'Mesure par étirement d\'une ligne complète de suspente jusqu\'à sa rupture avec enregistrement de la valeur max. Réalisé avec un dynamomètre DFW-03BT. Les valeurs sont exprimées en DaN. La première lettre indique la ligne d\'élévateur. Le chiffre indique le numéro de suspente en partant du centre de la voile. La troisième lettre indique l\'étage de suspente concerné. La dernière lettre indique le côté de la voile, dans le sens de vol.',
			'comment_default' => 'Votre voile a fait l\'objet d\'une inspection partielle portant sur :' . "\n" . '- La porosité des tissus.' . "\n" . '- La résistance des suspentes.' . "\n" . '- Le contrôle du calage.' . "\n" . 'Nous ne pouvons donc pas vous donner un état général de la voile, conformément à la norme ParachecK®.',
			'legends'      => array(
				'visual'   => array( 'RÉFORME' => 'moyenne = 0', 'ACCEPTABLE' => '0 – 25', 'BON ÉTAT' => '25 – 75', 'TRÈS BON ÉTAT' => '75 – 100', 'NEUF' => '100' ),
				'porosity' => array( 'RÉFORME' => '< 10 s', 'LIMITE' => '10 – 12 s', 'ACCEPTABLE' => '12 – 20 s', 'BON ÉTAT' => '20 – 95 s', 'TRÈS BON ÉTAT' => '95 – 300 s', 'NEUF' => '> 300 s' ),
				'tear'     => array( 'RÉFORME' => '< 0,6', 'LIMITE' => '0,6 – 0,7', 'ACCEPTABLE' => '0,7 – 0,9', 'BON ÉTAT' => '0,9 – 1,2', 'TRÈS BON ÉTAT' => '1,2 – 1,5', 'NEUF' => '> 1,5' ),
				'rupture'  => array( 'RÉFORME' => '≤ 0 %', 'LIMITE' => '< 10 %', 'ACCEPTABLE' => '< 25 %', 'BON ÉTAT' => '< 75 %', 'TRÈS BON ÉTAT' => '< 100 %', 'NEUF' => '100 %' ),
			),
			'show_general' => false,
			'show_results_summary' => true,
		),
	);

	$type = isset( $texts[ $type ] ) ? $type : 'periodique';

	return apply_filters( 'gacct_report_voile_texts', $texts[ $type ], $type );
}

/* =============================================================================
 *  CALCULS (miroir exact du JS ; le PDF ne croit que ces fonctions)
 * ========================================================================== */

/**
 * Pire résultat d'une liste (ordre de sévérité du référentiel).
 * Les valeurs vides / NON RÉALISÉ sont ignorées ; si tout est NON RÉALISÉ → NON RÉALISÉ.
 */
function gacct_report_worst( array $results ) {
	$config   = gacct_report_calc_config();
	$actual   = array_filter( $results, static function ( $r ) {
		return '' !== $r && null !== $r && 'NON RÉALISÉ' !== $r && 'NON RÉALISÉ*' !== $r;
	} );

	if ( empty( $actual ) ) {
		return empty( $results ) ? '' : 'NON RÉALISÉ';
	}

	foreach ( $config['severity'] as $level ) {
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

/**
 * §2.2 — Un groupe d'inspection visuelle (6 valeurs '', REF, ACC, BE, NEUF).
 *
 * @return array { average: float|null, result: string }
 */
function gacct_report_calc_visual_group( array $values ) {
	$config = gacct_report_calc_config();
	$values = array_map( 'strval', $values );
	$filled = array_filter( $values, static function ( $v ) {
		return '' !== $v;
	} );

	if ( empty( $filled ) ) {
		return array( 'average' => null, 'result' => 'NON RÉALISÉ' );
	}

	if ( in_array( 'REF', $filled, true ) ) {
		return array( 'average' => 0.0, 'result' => 'RÉFORME' );
	}

	$sum = 0;
	foreach ( $filled as $v ) {
		$sum += isset( $config['visual_values'][ $v ] ) ? $config['visual_values'][ $v ]['weight'] : 0;
	}

	// L'Excel divise par COUNTA (items renseignés).
	$average = $sum / count( $filled );

	return array(
		'average' => $average,
		'result'  => gacct_report_scale_result( $average, $config['visual_scale'] ),
	);
}

/**
 * §2.3 — Test de porosité (5 mesures en secondes).
 *
 * @return array { rates: array<float|null>, average: float|null, result: string }
 */
function gacct_report_calc_porosity( array $values ) {
	$config = gacct_report_calc_config();
	$rates  = array();
	$nums   = array();

	foreach ( $values as $v ) {
		if ( '' === trim( (string) $v ) || ! is_numeric( $v ) ) {
			$rates[] = null;
			continue;
		}
		$v       = (float) $v;
		$nums[]  = $v;
		$rates[] = $v > 0 ? $config['porosity_factor'] / $v : 0.0;
	}

	if ( empty( $nums ) ) {
		return array( 'rates' => $rates, 'average' => null, 'result' => 'NON RÉALISÉ' );
	}

	$average = array_sum( $nums ) / count( $nums );

	return array(
		'rates'   => $rates,
		'average' => $average,
		'result'  => gacct_report_scale_result( $average, $config['porosity_scale'] ),
	);
}

/**
 * §2.4 — Test de déchirure (3 mesures DaN + seuil minimal issu de la porosité).
 *
 * @return array { min: float, zones: array<string,string>, result: string }
 */
function gacct_report_calc_tear( array $values, $porosity_average ) {
	$config = gacct_report_calc_config();
	$min    = ( null !== $porosity_average && $porosity_average > $config['tear_min']['porosity_gt'] )
		? $config['tear_min']['high']
		: $config['tear_min']['low'];

	$zones = array();
	foreach ( $config['tear_zones'] as $key => $label ) {
		$v = isset( $values[ $key ] ) ? trim( (string) $values[ $key ] ) : '';
		if ( '' === $v || ! is_numeric( $v ) ) {
			$zones[ $key ] = 'NON RÉALISÉ';
			continue;
		}
		$zones[ $key ] = gacct_report_scale_result( (float) $v, $config['tear_scale'] );
	}

	return array(
		'min'    => $min,
		'zones'  => $zones,
		'result' => gacct_report_worst( array_values( $zones ) ),
	);
}

/**
 * §2.5 — Test de rupture des suspentes (0 à 5 lignes).
 * Chaque ligne : { ref, nominal, material, measure, seuil (optionnel, VR) }.
 *
 * @return array { lines: array[], result: string }
 */
function gacct_report_calc_rupture( array $lines ) {
	$config = gacct_report_calc_config();
	$out    = array();
	$results = array();

	foreach ( array_slice( $lines, 0, $config['rupture_max_lines'] ) as $line ) {
		$nominal  = isset( $line['nominal'] ) && is_numeric( $line['nominal'] ) ? (float) $line['nominal'] : 0.0;
		$measure  = isset( $line['measure'] ) && '' !== trim( (string) $line['measure'] ) && is_numeric( $line['measure'] ) ? (float) $line['measure'] : null;
		$material = isset( $line['material'] ) ? strtolower( (string) $line['material'] ) : '';
		$coef     = isset( $config['rupture_materials'][ $material ] ) ? $config['rupture_materials'][ $material ]['coef'] : 0.0;

		// Seuil : VR personnalisé (calcul réforme suspente) sinon nominal × coef matériau.
		$custom = isset( $line['seuil'] ) && '' !== trim( (string) $line['seuil'] ) && is_numeric( $line['seuil'] ) ? (float) $line['seuil'] : 0.0;
		$seuil  = $custom > 0 ? $custom : $nominal * $coef;

		$margin = null;
		$result = 'NR*';

		if ( null !== $measure && $nominal > 0 && $nominal !== $seuil ) {
			$margin = floor( ( $measure - $seuil ) / ( $nominal - $seuil ) * 100 );
			$result = gacct_report_scale_result( $margin, $config['rupture_scale'] );
			$results[] = $result;
		}

		$out[] = array(
			'ref'      => isset( $line['ref'] ) ? sanitize_text_field( (string) $line['ref'] ) : '',
			'nominal'  => $nominal,
			'material' => $material,
			'measure'  => $measure,
			'seuil'    => $seuil,
			'custom'   => $custom > 0,
			'margin'   => $margin,
			'result'   => $result,
		);
	}

	if ( empty( $results ) ) {
		return array( 'lines' => $out, 'result' => 'NON RÉALISÉ*' );
	}

	return array( 'lines' => $out, 'result' => gacct_report_worst( $results ) );
}

/**
 * §2.6 — Agrégat calage / freins (le « réglage des freins » reste une
 * métadonnée, non agrégée — recommandation maquette).
 */
function gacct_report_calc_geometry( $calage, $freins ) {
	$calage = (string) $calage;
	$freins = (string) $freins;

	if ( 'NON RÉALISÉ' === $calage && 'NON RÉALISÉ' === $freins ) {
		return 'NON RÉALISÉ';
	}
	if ( '' === $calage && '' === $freins ) {
		return 'NON RÉALISÉ';
	}
	if ( 'RÉFORME' === $calage || 'RÉFORME' === $freins ) {
		return 'RÉFORME';
	}
	if ( 'CALAGE BON' === $calage || 'CALAGE BON' === $freins ) {
		return 'CALAGE BON';
	}

	return 'NON RÉALISÉ';
}

/**
 * Calcul complet du rapport voile — renvoie toutes les interprétations.
 *
 * @param array $data Données saisies (payload du formulaire).
 * @return array
 */
function gacct_report_calc_voile( array $data ) {
	$config = gacct_report_calc_config();
	$type   = ( isset( $data['type'] ) && 'partielle' === $data['type'] ) ? 'partielle' : 'periodique';

	$visual  = array();
	$vresults = array();
	foreach ( array_keys( $config['visual_groups'] ) as $group ) {
		$values = isset( $data['visual'][ $group ] ) && is_array( $data['visual'][ $group ] )
			? array_pad( array_map( 'strval', $data['visual'][ $group ] ), 6, '' )
			: array_fill( 0, 6, '' );
		$visual[ $group ] = gacct_report_calc_visual_group( $values );
		$visual[ $group ]['values'] = $values;
		$vresults[] = $visual[ $group ]['result'];
	}
	$visual_global = gacct_report_worst( $vresults );

	$porosity = gacct_report_calc_porosity(
		isset( $data['porosity'] ) && is_array( $data['porosity'] ) ? array_pad( array_values( $data['porosity'] ), 5, '' ) : array_fill( 0, 5, '' )
	);

	$tear = gacct_report_calc_tear(
		isset( $data['tear'] ) && is_array( $data['tear'] ) ? $data['tear'] : array(),
		$porosity['average']
	);

	$rupture = gacct_report_calc_rupture(
		isset( $data['rupture'] ) && is_array( $data['rupture'] ) ? $data['rupture'] : array()
	);

	// §2.7 — Inspection mécanique = pire de (porosité, déchirure, rupture).
	$mechanical = gacct_report_worst( array( $porosity['result'], $tear['result'], str_replace( '*', '', $rupture['result'] ) ) );

	$geometry = gacct_report_calc_geometry(
		isset( $data['geometry']['calage_interp'] ) ? $data['geometry']['calage_interp'] : '',
		isset( $data['geometry']['freins_interp'] ) ? $data['geometry']['freins_interp'] : ''
	);

	// §2.1 — État général (périodique uniquement) : pire des 4 + réforme calage.
	$general = '';
	if ( 'periodique' === $type ) {
		if ( 'RÉFORME' === $geometry ) {
			$general = 'RÉFORME';
		} else {
			$general = gacct_report_worst( array(
				$visual_global,
				$porosity['result'],
				$tear['result'],
				str_replace( '*', '', $rupture['result'] ),
			) );
			if ( '' === $general || 'NON RÉALISÉ' === $general ) {
				$general = 'NON RÉALISÉ';
			} elseif ( 'TRÈS BON ÉTAT' === $general ) {
				// « NEUF » n'est atteignable que si les 4 résultats sont au max —
				// dans le barème réel, TBE est le plafond des tests : on le garde.
				$general = 'TRÈS BON ÉTAT';
			}
		}
	}

	return array(
		'type'          => $type,
		'visual'        => $visual,
		'visual_global' => $visual_global,
		'porosity'      => $porosity,
		'tear'          => $tear,
		'rupture'       => $rupture,
		'mechanical'    => $mechanical,
		'geometry'      => $geometry,
		'general'       => $general,
	);
}

/**
 * Calcul du modèle « Calcul réforme suspente » (onglet CALCUL REFORME SUSPENTE).
 *
 * VR = (résistance_test × PTV_max / RESmax_total) × coef  (0 si coef = 0).
 *
 * @return array { ensembles: array[], nb_total: int, resmax_total: float, vr: float }
 */
function gacct_report_calc_suspente( array $data ) {
	$ensembles = array();
	$nb_total  = 0;
	$res_total = 0.0;

	for ( $i = 0; $i < 4; $i++ ) {
		$nb  = isset( $data['ensembles'][ $i ]['nb'] ) && is_numeric( $data['ensembles'][ $i ]['nb'] ) ? (int) $data['ensembles'][ $i ]['nb'] : 0;
		$res = isset( $data['ensembles'][ $i ]['resistance'] ) && is_numeric( $data['ensembles'][ $i ]['resistance'] ) ? (float) $data['ensembles'][ $i ]['resistance'] : 0.0;

		$resmax      = $nb * $res;
		$nb_total   += $nb;
		$res_total  += $resmax;
		$ensembles[] = array( 'nb' => $nb, 'resistance' => $res, 'resmax' => $resmax );
	}

	$test = isset( $data['resistance_test'] ) && is_numeric( $data['resistance_test'] ) ? (float) $data['resistance_test'] : 0.0;
	$ptv  = isset( $data['ptv_max'] ) && is_numeric( $data['ptv_max'] ) ? (float) $data['ptv_max'] : 0.0;
	$coef = isset( $data['coef'] ) && is_numeric( $data['coef'] ) ? (float) $data['coef'] : 0.0;

	$vr = ( $coef > 0 && $res_total > 0 ) ? ( ( $test * $ptv ) / $res_total ) * $coef : 0.0;

	return array(
		'ensembles'    => $ensembles,
		'nb_total'     => $nb_total,
		'resmax_total' => $res_total,
		'resistance_test' => $test,
		'ptv_max'      => $ptv,
		'coef'         => $coef,
		'vr'           => $vr,
	);
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
				update_option( GACCT_REPORT_SETTINGS_OPT, array(
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
 */
function gacct_report_register_config_tab( $tabs ) {
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
 * Prochain numéro complet (aperçu, sans consommer) : AAAA + compteur sur 3
 * chiffres minimum (ex. 2026001).
 */
function gacct_report_peek_number() {
	return gmdate( 'Y', current_time( 'timestamp' ) ) . str_pad( (string) gacct_report_counter(), 3, '0', STR_PAD_LEFT );
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
	$model     = (string) $entry['model'];
	$templates = array(
		'voile'      => 'report-voile.php',
		'equipement' => 'report-equipement.php',
		'suspente'   => 'report-suspente.php',
	);

	if ( ! isset( $templates[ $model ] ) ) {
		return new WP_Error( 'gacct_report_bad_model', __( 'Modèle de rapport inconnu.', 'gestion-atelier-cct' ) );
	}

	$file = dirname( __DIR__ ) . '/templates/' . $templates[ $model ];

	if ( ! file_exists( $file ) ) {
		return new WP_Error( 'gacct_report_no_template', __( 'Template PDF introuvable.', 'gestion-atelier-cct' ) );
	}

	$context = gacct_report_pdf_context( $row, $entry );
	$data    = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();

	// Interprétations recalculées côté serveur.
	$calc = null;
	if ( 'voile' === $model ) {
		$calc = gacct_report_calc_voile( $data );
	} elseif ( 'suspente' === $model ) {
		$calc = gacct_report_calc_suspente( $data );
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
