<?php
/**
 * Pack Altitude Révision — Calculs ParachecK® (miroir exact du JS du pack ; le PDF ne croit que ces fonctions).
 * Extrait du framework gestion-atelier-cct le 31/07/2026 (architecture packs).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
 * Identité Altitude Révision des réglages de rapports : textes par défaut du
 * bloc QR (enquête qualité ParachecK / coupe Icare). Le framework, lui, reste
 * neutre — chaque pack apporte les siens.
 */
function gacct_paracheck_default_report_settings( $defaults ) {
	$defaults['qr_title']   = 'Gagnez votre prochaine révision périodique ParachecK en répondant à l\'enquête qualité !';
	$defaults['qr_subtext'] = 'Tirage au sort lors de la prochaine coupe Icare.';

	return $defaults;
}
add_filter( 'gacct_report_default_settings', 'gacct_paracheck_default_report_settings' );

/**
 * Ce que la commande implique comme rapports : produits → modèles suggérés
 * + type du rapport voile (réunion du 06/08/2026). Basé sur la table
 * `report_hints` de la config, filtrable `gacct_paracheck_report_hints`.
 *
 * @param WC_Order|false $order Commande liée au dossier.
 * @return array { models: string[], voile_type: string|null }
 */
function gacct_paracheck_order_hints( $order ) {
	$result = array( 'models' => array(), 'voile_type' => null );

	if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
		return $result;
	}

	$hints = apply_filters(
		'gacct_paracheck_report_hints',
		gacct_report_calc_config()['report_hints'] ?? array(),
		$order
	);

	$ordered = array();
	foreach ( $order->get_items() as $item ) {
		$ordered[] = (int) $item->get_product_id();
		$ordered[] = (int) $item->get_variation_id();
	}

	$has = static function ( $key ) use ( $hints, $ordered ) {
		return (bool) array_intersect( (array) ( $hints[ $key ] ?? array() ), $ordered );
	};

	if ( $has( 'voile_periodique' ) || $has( 'voile_partielle' ) ) {
		$result['models'][] = 'voile';
		// La révision périodique l'emporte sur les inspections partielles.
		$result['voile_type'] = $has( 'voile_periodique' ) ? 'periodique' : 'partielle';
	}

	foreach ( array( 'suspente', 'equipement' ) as $model ) {
		if ( $has( $model ) ) {
			$result['models'][] = $model;
		}
	}

	return $result;
}

/**
 * Filtre framework : modèles à mettre en avant dans « Nouveau rapport ».
 */
function gacct_paracheck_suggested_models( $models, $order ) {
	$hints = gacct_paracheck_order_hints( $order );

	return $hints['models'] ? $hints['models'] : $models;
}
add_filter( 'gacct_report_suggested_models', 'gacct_paracheck_suggested_models', 10, 2 );

/**
 * Filtre framework : type présélectionné du formulaire voile.
 */
function gacct_paracheck_default_voile_type( $type, $order ) {
	$hints = gacct_paracheck_order_hints( $order );

	return $hints['voile_type'] ? $hints['voile_type'] : $type;
}
add_filter( 'gacct_report_voile_default_type', 'gacct_paracheck_default_voile_type', 10, 2 );

/**
 * Les 3 points de la vérification de sécurité du rapport voile — source
 * unique (formulaire, PDF, validation de clôture).
 *
 * @return array<string,string> clé => libellé.
 */
function gacct_paracheck_security_labels() {
	return array(
		'fluidite' => __( 'Fluidité suspentage', 'gestion-atelier-cct' ),
		'maillons' => __( 'Maillons / connecteurs', 'gestion-atelier-cct' ),
		'drisses'  => __( 'Drisses de frein, nœuds, poulies', 'gestion-atelier-cct' ),
	);
}

/**
 * Clôture d'un rapport voile : les 3 cases de sécurité sont OBLIGATOIRES
 * (réunion du 06/08/2026) — la génération du PDF est bloquée tant que tout
 * n'est pas coché. La sauvegarde du brouillon reste libre.
 *
 * @param true|WP_Error $valid Résultat courant de la validation.
 * @param array         $entry Entrée de rapport ({ model, data… }).
 * @return true|WP_Error
 */
function gacct_paracheck_validate_generate( $valid, $entry ) {
	if ( is_wp_error( $valid ) || 'voile' !== ( $entry['model'] ?? '' ) ) {
		return $valid;
	}

	$checked = isset( $entry['data']['securite'] ) && is_array( $entry['data']['securite'] )
		? $entry['data']['securite']
		: array();

	$missing = array();
	foreach ( gacct_paracheck_security_labels() as $key => $label ) {
		if ( empty( $checked[ $key ] ) ) {
			$missing[] = $label;
		}
	}

	if ( $missing ) {
		return new WP_Error( 'gacct_report_security_required', sprintf(
			/* translators: %s: liste des points non cochés */
			__( 'Impossible de clôturer le rapport : la vérification de sécurité doit être complète. Points non cochés : %s. Cochez les 3 cases (et enregistrez) avant de générer le PDF.', 'gestion-atelier-cct' ),
			implode( ', ', $missing )
		) );
	}

	return $valid;
}
add_filter( 'gacct_report_validate_generate', 'gacct_paracheck_validate_generate', 10, 2 );

/**
 * Liste des mesures de porosité d'un brouillon, calée sur les points du
 * barème courant : les brouillons enregistrés avant la suppression du P5
 * portent 5 valeurs, la 5ᵉ est ignorée (tronquée, jamais moyennée).
 *
 * @return string[] Autant d'entrées que de points configurés.
 */
function gacct_paracheck_porosity_values( array $data ) {
	$count  = count( gacct_report_calc_config()['porosity_points'] );
	$values = isset( $data['porosity'] ) && is_array( $data['porosity'] ) ? array_values( $data['porosity'] ) : array();

	return array_slice( array_pad( $values, $count, '' ), 0, $count );
}

/**
 * Affichage d'une mesure de porosité : le porosimètre plafonne à 600 s,
 * au-delà on écrit « 600+ » (le tissu est au plafond de l'appareil).
 * La valeur numérique reste utilisée telle quelle dans les calculs.
 *
 * @param float|string|null $value    Mesure (ou moyenne) en secondes.
 * @param int               $decimals Décimales du format normal.
 * @return string Valeur formatée, « 600+ », ou « — » si vide.
 */
function gacct_paracheck_porosity_display( $value, $decimals = 1 ) {
	if ( null === $value || '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
		return '—';
	}

	$ceiling = (float) ( gacct_report_calc_config()['porosity_ceiling'] ?? 600 );
	$format  = function_exists( 'gacct_rp2_num' ) ? 'gacct_rp2_num' : 'number_format_i18n';

	if ( (float) $value >= $ceiling ) {
		return $format( $ceiling, 0 ) . '+';
	}

	return $format( $value, $decimals );
}

/**
 * §2.3 — Test de porosité (mesures en secondes, une par point du schéma).
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

	$porosity = gacct_report_calc_porosity( gacct_paracheck_porosity_values( $data ) );

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
