<?php
/**
 * PDF — Calcul du seuil de réforme pour la résistance des suspentes,
 * onglet « CALCUL REFORME SUSPENTE » du classeur ParachecK V8.
 *
 * VR = (résistance test × PTV max ÷ RESmax total) × coefficient.
 *
 * @var array $context
 * @var array $entry
 * @var array $data
 * @var array $calc  gacct_report_calc_suspente().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once GACCT_REPORT_PARTS;

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style><?php echo gacct_rpdf_styles( $context['accent'] ); ?></style>
</head>
<body>
<?php
gacct_rpdf_head(
	$context,
	__( 'Calcul du seuil de réforme pour la résistance des suspentes', 'gestion-atelier-cct' ),
	'',
	'',
	true
);

echo '<h2>' . esc_html__( 'Données du calcul', 'gestion-atelier-cct' ) . '</h2>';
echo '<table class="rpdf-data">';
echo '<tr><th style="width:40%;">' . esc_html__( 'Résistance suspente test', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( gacct_rpdf_num( $calc['resistance_test'], 1 ) ) . ' DaN</td></tr>';
echo '<tr><th>' . esc_html__( 'PTV Max', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( gacct_rpdf_num( $calc['ptv_max'], 1 ) ) . ' kg</td></tr>';
echo '<tr><th>' . esc_html__( 'Coefficient', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( gacct_rpdf_num( $calc['coef'], 2 ) ) . '</td></tr>';
echo '</table>';

echo '<h2>' . esc_html__( 'Ensembles de suspentes', 'gestion-atelier-cct' ) . '</h2>';
echo '<table class="rpdf-data">';
echo '<tr><th></th><th style="text-align:center;">' . esc_html__( 'Nb suspentes', 'gestion-atelier-cct' ) . '</th><th style="text-align:center;">' . esc_html__( 'Résistance (DaN)', 'gestion-atelier-cct' ) . '</th><th style="text-align:center;">RESmax</th></tr>';

foreach ( $calc['ensembles'] as $i => $ensemble ) {
	echo '<tr><th>' . esc_html( sprintf( __( 'Ensemble %d', 'gestion-atelier-cct' ), $i + 1 ) ) . '</th>';
	echo '<td style="text-align:center;">' . esc_html( $ensemble['nb'] > 0 ? $ensemble['nb'] : '—' ) . '</td>';
	echo '<td style="text-align:center;">' . esc_html( $ensemble['resistance'] > 0 ? gacct_rpdf_num( $ensemble['resistance'], 1 ) : '—' ) . '</td>';
	echo '<td style="text-align:center;">' . esc_html( gacct_rpdf_num( $ensemble['resmax'], 1 ) ) . '</td></tr>';
}

echo '<tr><th>' . esc_html__( 'Total', 'gestion-atelier-cct' ) . '</th>';
echo '<td style="text-align:center; font-weight:bold;">' . esc_html( $calc['nb_total'] ) . '</td>';
echo '<td></td>';
echo '<td style="text-align:center; font-weight:bold;">' . esc_html( gacct_rpdf_num( $calc['resmax_total'], 1 ) ) . '</td></tr>';
echo '</table>';

echo '<div class="rpdf-general">';
echo '<h3 style="margin-top:0;">' . esc_html__( 'VR — SEUIL DE RÉFORME', 'gestion-atelier-cct' ) . '</h3>';
echo '<span class="rpdf-badge ' . ( $calc['vr'] > 0 ? 'b-bon' : 'b-na' ) . '" style="font-size:13px; padding:5px 16px;">' . esc_html( gacct_rpdf_num( $calc['vr'], 2 ) ) . ' DaN</span>';
echo '<p class="muted">' . esc_html__( 'VR = (résistance suspente test × PTV max ÷ RESmax total) × coefficient. Une suspente testée dont la mesure de rupture est inférieure à ce seuil est à réformer.', 'gestion-atelier-cct' ) . '</p>';
echo '</div>';

gacct_rpdf_signature( $context );
?>
</body>
</html>
