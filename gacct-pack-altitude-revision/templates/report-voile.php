<?php
/**
 * PDF — Rapport voile ParachecK® (design v3.2 validé par Bastien le 31/07/2026).
 *
 * Structure des rapports officiels Altitude Révision, finition moderne :
 * matrice d'état général entièrement colorée (code couleur canonique),
 * bandeaux mono-couleur, légendes sans traits, schéma de voile original du
 * classeur V8, logos côte à côte, pied de page = coordonnées boutique Woo,
 * police configurable (Configuration > Rapports), bloc QR optionnel.
 *
 * Deux TYPES sur le même design : « Révision périodique » et « Inspection
 * partielle » — textes, sections et légendes propres à chaque type
 * (gacct_report_voile_texts()), interprétations TOUJOURS recalculées serveur.
 *
 * @var array $context Contexte white-label + identification.
 * @var array $entry   Entrée de rapport.
 * @var array $data    Données saisies.
 * @var array $calc    gacct_report_calc_voile().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once GACCT_REPORT_PARTS;

$config = gacct_report_calc_config();
$type   = $calc['type'];
$texts  = gacct_report_voile_texts( $type );
$accent = $context['accent'];

$img_dir  = dirname( __DIR__ ) . '/assets/img/paracheck';
$ffvl_img = $img_dir . '/ffvl-paracheck.png';
$wing_img = $img_dir . '/voile-points-porosite.png';

$security_labels = gacct_paracheck_security_labels();

$security = isset( $data['securite'] ) && is_array( $data['securite'] ) ? $data['securite'] : array();
$geometry = isset( $data['geometry'] ) && is_array( $data['geometry'] ) ? $data['geometry'] : array();
$comment  = isset( $data['comment'] ) ? trim( (string) $data['comment'] ) : '';

$rupture_clean = str_replace( '*', '', $calc['rupture']['result'] );

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style><?php echo gacct_rp2_styles( $accent ); ?></style>
</head>
<body>
<?php
gacct_rp2_head(
	$context,
	$texts['title'],
	$texts['intro'],
	$ffvl_img,
	__( 'Adhérent à la charte ParachecK®', 'gestion-atelier-cct' )
);

echo '<p class="muted" style="margin-top:6px; text-align:center;">' . esc_html__( 'Votre aile fait l\'objet d\'une grande attention tout au long de son inspection. En voici le compte-rendu détaillé.', 'gestion-atelier-cct' ) . '</p>';

/* ── Matrice état général ───────────────────────────────────────────── */
echo gacct_rp2_section( esc_html__( 'ÉTAT GÉNÉRAL DE LA VOILE', 'gestion-atelier-cct' ), '', $accent );
echo gacct_rp2_matrix( array(
	__( 'ÉTAT GÉNÉRAL DE LA VOILE', 'gestion-atelier-cct' ) => ( 'periodique' === $type ) ? $calc['general'] : '',
	__( 'Inspection visuelle', 'gestion-atelier-cct' )      => $calc['visual_global'],
	__( 'Inspection mécanique', 'gestion-atelier-cct' )     => $calc['mechanical'],
	__( 'Inspection géométrique', 'gestion-atelier-cct' )   => $calc['geometry'],
) );
echo '<p class="muted"' . ( 'partielle' === $type ? ' style="font-weight:bold; color:#334155;"' : '' ) . '>' . esc_html( $texts['general_note'] ) . '</p>';

/* ── Commentaires ───────────────────────────────────────────────────── */
if ( '' !== $comment ) {
	echo gacct_rp2_section( esc_html__( 'COMMENTAIRES ET TRAVAUX EFFECTUÉS', 'gestion-atelier-cct' ), '', $accent );
	echo '<div class="rp2-box" style="min-height:44px;">' . nl2br( esc_html( $comment ) ) . '</div>';
}

/* ── Vérification de sécurité ───────────────────────────────────────── */
echo gacct_rp2_section( esc_html__( 'Une vérification de sécurité a été réalisée sur :', 'gestion-atelier-cct' ), '', $accent );
echo '<table style="width:100%; border-collapse:collapse;"><tr>';
foreach ( $security_labels as $key => $label ) {
	$ok = ! empty( $security[ $key ] );
	echo '<td style="width:33%; border:0.5pt solid #c9d4da; padding:4px 8px; font-size:8.8px;">';
	echo '<span class="sym" style="font-weight:bold; color:' . ( $ok ? '#2d6b1c' : '#8f1d1d' ) . ';">' . ( $ok ? '✔' : '✘' ) . '</span>&nbsp; ' . esc_html( $label );
	echo '</td>';
}
echo '</tr></table>';

/* ── Prochain contrôle (inspection partielle) ───────────────────────── */
if ( 'partielle' === $type ) {
	$next      = isset( $data['next'] ) && is_array( $data['next'] ) ? $data['next'] : array();
	$next_date = ! empty( $next['date'] ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $next['date'] ) ) : '';

	if ( $next_date || ! empty( $next['hours'] ) || ! empty( $next['flights'] ) ) {
		echo gacct_rp2_section( esc_html__( 'PROCHAIN CONTRÔLE', 'gestion-atelier-cct' ), '', $accent );
		echo '<table class="data"><tr>';
		echo '<th style="text-align:center;">' . esc_html__( 'Date', 'gestion-atelier-cct' ) . '</th>';
		echo '<th style="text-align:center;">' . esc_html__( 'Heures de vol', 'gestion-atelier-cct' ) . '</th>';
		echo '<th style="text-align:center;">' . esc_html__( 'Nb de vols', 'gestion-atelier-cct' ) . '</th>';
		echo '</tr><tr>';
		echo '<td style="text-align:center;">' . esc_html( $next_date ? $next_date : '—' ) . '</td>';
		echo '<td style="text-align:center;">' . esc_html( ! empty( $next['hours'] ) ? $next['hours'] : '—' ) . '</td>';
		echo '<td style="text-align:center;">' . esc_html( ! empty( $next['flights'] ) ? $next['flights'] : '—' ) . '</td>';
		echo '</tr></table>';
	}
}

/* ── Conclusion normative ───────────────────────────────────────────── */
echo '<div class="muted" style="margin-top:8px;">';
foreach ( $texts['conclusion'] as $paragraph ) {
	echo '<p>' . nl2br( esc_html( $paragraph ) ) . '</p>';
}
echo '</div>';

/* ── Inspection visuelle (page 1, légende à droite) ─────────────────── */
echo gacct_rp2_section(
	esc_html__( 'INSPECTION VISUELLE PARACHECK®', 'gestion-atelier-cct' ),
	esc_html__( 'Réalisé par :', 'gestion-atelier-cct' ) . ' ' . esc_html( $context['author'] ) . ' &nbsp;&nbsp;' . gacct_rp2_badge( $calc['visual_global'] ),
	$accent
);

echo '<table style="width:100%; border-collapse:collapse;"><tr>';

foreach ( $config['visual_groups'] as $group_key => $group ) {
	$g = $calc['visual'][ $group_key ];

	echo '<td style="width:26%; vertical-align:top; padding-right:7px;">';
	echo '<table class="data" style="margin-bottom:2px;">';
	echo '<tr><th colspan="2" style="background:#eaf7f7;">' . esc_html( $group['label'] )
		. '<span style="float:right; font-weight:normal;" class="muted">' . ( null !== $g['average'] ? 'moy. ' . esc_html( gacct_rp2_num( $g['average'], 1 ) ) : '' ) . '</span></th></tr>';

	foreach ( $group['items'] as $i => $item ) {
		$value = $g['values'][ $i ];
		$label = ( '' !== $value && isset( $config['visual_values'][ $value ] ) ) ? $config['visual_values'][ $value ]['label'] : '—';
		echo '<tr><td style="width:70%; font-size:8.2px;">' . esc_html( $item ) . '</td><td style="text-align:center; font-size:8.2px;">' . esc_html( $label ) . '</td></tr>';
	}

	echo '<tr><td colspan="2" style="text-align:center; padding:3px;">' . gacct_rp2_badge( $g['result'] ) . '</td></tr>';
	echo '</table>';
	echo '</td>';
}

echo '<td style="width:22%; vertical-align:top; padding-left:4px;">';
echo gacct_rp2_scale_legend( $texts['legends']['visual'] );
echo '<p class="muted" style="font-size:7px; margin-top:8px;">' . esc_html__( 'Structure int. = joncs, profils, diag et bandes', 'gestion-atelier-cct' ) . '</p>';
echo '</td>';

echo '</tr></table>';

/* ════════ Page 2 : inspection mécanique + géométrique ════════ */
echo '<div style="page-break-before: always;"></div>';

echo gacct_rp2_section( esc_html__( 'INSPECTION MÉCANIQUE PARACHECK®', 'gestion-atelier-cct' ), gacct_rp2_badge( $calc['mechanical'] ), $accent );

/* — Porosité — */
$poro = $calc['porosity'];
echo gacct_rp2_subsection( esc_html__( 'TEST DE POROSITÉ DES TISSUS', 'gestion-atelier-cct' ), $context['author'], gacct_rp2_badge( $poro['result'] ) );
echo '<p class="muted">' . esc_html__( 'État de vieillissement des tissus mesuré à l\'aide d\'un porosimètre de la marque JDC qui mesure le temps écoulé pour le passage au travers du tissu d\'un certain volume d\'air. Les valeurs sont exprimées en secondes et en l/m2/min.', 'gestion-atelier-cct' ) . '</p>';

$porosity_values = gacct_paracheck_porosity_values( $data );
$avg_rate        = ( null !== $poro['average'] && $poro['average'] > 0 ) ? $config['porosity_factor'] / $poro['average'] : null;

echo '<table style="width:100%; border-collapse:collapse; margin-top:3px;"><tr>';

echo '<td style="width:46%; vertical-align:top; padding-right:10px;">';
echo '<table class="data"><tr><th></th>';
foreach ( $config['porosity_points'] as $point ) {
	echo '<th style="text-align:center;">' . esc_html( $point ) . '</th>';
}
echo '<th style="text-align:center;">' . esc_html__( 'Moy.', 'gestion-atelier-cct' ) . '</th></tr>';
echo '<tr><th>s</th>';
foreach ( $porosity_values as $v ) {
	echo '<td style="text-align:center;">' . esc_html( gacct_paracheck_porosity_display( $v ) ) . '</td>';
}
echo '<td style="text-align:center; font-weight:bold;">' . esc_html( gacct_paracheck_porosity_display( $poro['average'] ) ) . '</td></tr>';
echo '<tr><th>l/m2/min.</th>';
foreach ( $poro['rates'] as $rate ) {
	echo '<td style="text-align:center;">' . esc_html( null !== $rate ? gacct_rp2_num( $rate, 1 ) : '—' ) . '</td>';
}
echo '<td style="text-align:center; font-weight:bold;">' . esc_html( gacct_rp2_num( $avg_rate, 1 ) ) . '</td></tr>';
echo '</table>';
echo '</td>';

echo '<td style="width:32%; vertical-align:top; text-align:center; padding:0 8px;">';
if ( file_exists( $wing_img ) ) {
	echo '<img src="' . esc_attr( $wing_img ) . '" style="width:180px;">';
}
echo '<div class="muted" style="text-align:left; font-size:7.4px; margin-top:3px;">' . esc_html( $texts['porosity_note'] ) . '</div>';
echo '</td>';

echo '<td style="width:22%; vertical-align:top; padding-left:4px;">';
echo gacct_rp2_scale_legend( $texts['legends']['porosity'], 's' );
echo '</td>';

echo '</tr></table>';

/* — Déchirure — */
$tear = $calc['tear'];
echo gacct_rp2_subsection( esc_html__( 'TEST DE RÉSISTANCE À LA DÉCHIRURE DES TISSUS', 'gestion-atelier-cct' ), $context['author'], gacct_rp2_badge( $tear['result'] ) );
echo '<p class="muted">' . esc_html__( 'Test effectué avec instrument Bettsometer comportant une aiguille plantée dans le tissu. La traction appliquée sur l\'instrument indique la force appliquée sans provoquer de déchirure. Valeurs exprimées en daN.', 'gestion-atelier-cct' ) . '</p>';

$tear_values = isset( $data['tear'] ) && is_array( $data['tear'] ) ? $data['tear'] : array();

echo '<table style="width:100%; border-collapse:collapse; margin-top:3px;"><tr>';
echo '<td style="width:46%; vertical-align:top; padding-right:10px;">';
echo '<table class="data"><tr><th></th><th style="text-align:center;">' . esc_html__( 'Min.', 'gestion-atelier-cct' ) . '</th><th style="text-align:center;">' . esc_html__( 'Mesure', 'gestion-atelier-cct' ) . '</th><th>' . esc_html__( 'Interprétation', 'gestion-atelier-cct' ) . '</th></tr>';
foreach ( $config['tear_zones'] as $zone_key => $zone_label ) {
	$v = isset( $tear_values[ $zone_key ] ) ? trim( (string) $tear_values[ $zone_key ] ) : '';
	echo '<tr><th>' . esc_html( $zone_label ) . '</th>';
	echo '<td style="text-align:center;">' . esc_html( gacct_rp2_num( $tear['min'], 2 ) ) . '</td>';
	echo '<td style="text-align:center;">' . esc_html( '' !== $v ? gacct_rp2_num( $v, 2 ) : '—' ) . '</td>';
	echo '<td>' . gacct_rp2_badge( $tear['zones'][ $zone_key ] ) . '</td></tr>';
}
echo '</table>';
echo '</td>';
echo '<td style="width:32%;"></td>';
echo '<td style="width:22%; vertical-align:top; padding-left:4px;">';
echo gacct_rp2_scale_legend( $texts['legends']['tear'], 'mesure' );
echo '</td>';
echo '</tr></table>';

/* — Rupture des suspentes — */
$rupture = $calc['rupture'];
echo gacct_rp2_subsection( esc_html__( 'TEST DE RUPTURE DES SUSPENTES', 'gestion-atelier-cct' ), $context['author'], gacct_rp2_badge( $rupture_clean ) );
echo '<p class="muted">' . esc_html( $texts['rupture_intro'] ) . '</p>';

echo '<table style="width:100%; border-collapse:collapse; margin-top:3px;"><tr>';
echo '<td style="width:46%; vertical-align:top; padding-right:10px;">';

if ( ! empty( $rupture['lines'] ) ) {
	echo '<table class="data"><tr><th style="width:38%;">' . esc_html__( 'Suspente testée', 'gestion-atelier-cct' ) . '</th>';
	foreach ( $rupture['lines'] as $line ) {
		echo '<td style="text-align:center; font-weight:bold;">' . esc_html( '' !== $line['ref'] ? $line['ref'] : '—' ) . '</td>';
	}
	echo '</tr>';

	$rows = array(
		'nominal'  => __( 'Valeur nominale', 'gestion-atelier-cct' ),
		'material' => __( 'Matériau', 'gestion-atelier-cct' ),
		'seuil'    => __( 'Seuil réforme', 'gestion-atelier-cct' ),
		'measure'  => __( 'Mesure de rupture', 'gestion-atelier-cct' ),
		'margin'   => __( 'Marge (%)', 'gestion-atelier-cct' ),
		'result'   => __( 'Interprétation', 'gestion-atelier-cct' ),
	);

	foreach ( $rows as $row_key => $row_label ) {
		echo '<tr><th>' . esc_html( $row_label ) . '</th>';
		foreach ( $rupture['lines'] as $line ) {
			echo '<td style="text-align:center;">';
			switch ( $row_key ) {
				case 'nominal':
					echo esc_html( $line['nominal'] > 0 ? gacct_rp2_num( $line['nominal'], 1 ) : '—' );
					break;
				case 'material':
					echo esc_html( isset( $config['rupture_materials'][ $line['material'] ] ) ? $config['rupture_materials'][ $line['material'] ]['label'] : '—' );
					break;
				case 'seuil':
					echo esc_html( $line['seuil'] > 0 ? gacct_rp2_num( $line['seuil'], 2 ) : '—' ) . ( $line['custom'] ? ' <span class="muted">(VR)</span>' : '' );
					break;
				case 'measure':
					echo esc_html( null !== $line['measure'] ? gacct_rp2_num( $line['measure'], 1 ) : '—' );
					break;
				case 'margin':
					echo esc_html( null !== $line['margin'] ? gacct_rp2_num( $line['margin'], 0 ) : 'NR*' );
					break;
				case 'result':
					echo 'NR*' === $line['result'] ? 'NR*' : gacct_rp2_badge( $line['result'] );
					break;
			}
			echo '</td>';
		}
		echo '</tr>';
	}
	echo '</table>';
} else {
	echo '<p class="muted">' . esc_html__( 'Non réalisé* — * Recommandation du constructeur.', 'gestion-atelier-cct' ) . '</p>';
}

echo '</td>';
echo '<td style="width:32%; vertical-align:bottom;">';
echo '<p class="muted" style="font-size:7.4px;">' . esc_html__( '*NR = Non réalisé — test réalisé sur recommandation du constructeur. Valeur nominale de référence : PMA.', 'gestion-atelier-cct' ) . '</p>';
echo '</td>';
echo '<td style="width:22%; vertical-align:top; padding-left:4px;">';
echo gacct_rp2_scale_legend( $texts['legends']['rupture'], 'marge' );
echo '</td>';
echo '</tr></table>';

/* ── Inspection géométrique ─────────────────────────────────────────── */
echo gacct_rp2_section(
	esc_html__( 'INSPECTION GÉOMÉTRIQUE PARACHECK®', 'gestion-atelier-cct' ),
	esc_html__( 'Réalisé par :', 'gestion-atelier-cct' ) . ' ' . esc_html( $context['author'] ) . ' &nbsp;&nbsp;' . gacct_rp2_badge( $calc['geometry'] ),
	$accent
);
echo '<p class="muted">' . esc_html__( 'Calage de la voile contrôlé avec le système de mesure WOERNER. Le détail des mesures est disponible en annexe.', 'gestion-atelier-cct' ) . '</p>';

$calage_interp = ! empty( $geometry['calage_interp'] ) ? $geometry['calage_interp'] : 'NON RÉALISÉ';
$freins_interp = ! empty( $geometry['freins_interp'] ) ? $geometry['freins_interp'] : 'NON RÉALISÉ';

echo '<table style="width:100%; border-collapse:collapse; margin-top:4px;"><tr>';
echo '<td style="font-weight:bold; font-size:9px; padding:2px 0;">' . esc_html__( 'ÉCARTS CALAGE', 'gestion-atelier-cct' ) . '</td>';
echo '<td style="text-align:right; font-size:8.5px; padding:2px 0;">' . esc_html__( 'Interprétation des résultats :', 'gestion-atelier-cct' ) . ' &nbsp;' . gacct_rp2_badge( $calage_interp ) . '</td>';
echo '</tr></table>';
echo '<div class="rp2-box" style="font-size:8.8px;">';
echo '<span style="color:#0e7490; font-weight:bold;">' . esc_html__( 'Interventions :', 'gestion-atelier-cct' ) . '</span> ' . esc_html( ! empty( $geometry['calage_interventions'] ) ? $geometry['calage_interventions'] : '—' ) . '<br>';
echo '<span style="color:#0e7490; font-weight:bold;">' . esc_html__( 'Final :', 'gestion-atelier-cct' ) . '</span> ' . esc_html( ! empty( $geometry['calage_final'] ) ? $geometry['calage_final'] : '—' );
echo '</div>';

echo '<table style="width:100%; border-collapse:collapse; margin-top:6px;"><tr>';
echo '<td style="font-weight:bold; font-size:9px; padding:2px 0;">' . esc_html__( 'ÉCARTS FREINS', 'gestion-atelier-cct' ) . '</td>';
echo '<td style="text-align:right; font-size:8.5px; padding:2px 0;">' . esc_html__( 'Interprétation des résultats :', 'gestion-atelier-cct' ) . ' &nbsp;' . gacct_rp2_badge( $freins_interp ) . '</td>';
echo '</tr></table>';
echo '<div class="rp2-box" style="font-size:8.8px;">';
echo '<span style="color:#0e7490; font-weight:bold;">' . esc_html__( 'Interventions :', 'gestion-atelier-cct' ) . '</span> ' . esc_html( ! empty( $geometry['freins_interventions'] ) ? $geometry['freins_interventions'] : '—' );
echo '</div>';

echo '<table style="width:100%; border-collapse:collapse; margin-top:6px;"><tr>';
echo '<td style="width:50%; font-size:8.5px;"><span style="color:#0e7490; font-style:italic;">' . esc_html__( 'Calage réalisé par :', 'gestion-atelier-cct' ) . '</span> &nbsp;' . esc_html( $context['author'] ) . '</td>';
echo '<td style="width:50%; font-size:8.5px;"><span style="font-weight:bold;">' . esc_html__( 'Réglage des freins :', 'gestion-atelier-cct' ) . '</span> &nbsp;' . esc_html( ! empty( $geometry['reglage_freins'] ) ? $geometry['reglage_freins'] : '—' ) . '</td>';
echo '</tr></table>';

gacct_rp2_signature_qr( $context );
?>
</body>
</html>
