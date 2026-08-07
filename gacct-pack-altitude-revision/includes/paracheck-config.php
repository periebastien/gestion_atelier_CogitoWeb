<?php
/**
 * Pack Altitude Révision — Configuration ParachecK® : types, seuils, barèmes, textes (source unique, miroir JS).
 * Extrait du framework gestion-atelier-cct le 31/07/2026 (architecture packs).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		// 4 zones depuis la réunion du 06/08/2026 (le P5 du classeur était une erreur).
		'porosity_points' => array( 'P4', 'P2', 'P1', 'P3' ),
		'porosity_factor' => 5400,
		// Plafond du porosimètre : au-delà, la mesure est affichée « 600+ »
		// (la valeur saisie reste utilisée telle quelle dans les calculs).
		'porosity_ceiling' => 600,
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
