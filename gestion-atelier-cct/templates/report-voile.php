<?php
/**
 * PDF — Rapport voile ParachecK® (dompdf).
 *
 * Deux TYPES sur le même design : « Révision périodique » (onglet RAPPORT
 * PARACHECK) et « Inspection partielle » (onglet RAPPORT). Les textes,
 * sections et légendes de seuils de CHAQUE type sont reproduits depuis son
 * onglet (gacct_report_voile_texts()) ; les interprétations affichées sont
 * TOUJOURS recalculées serveur ($calc = gacct_report_calc_voile()).
 *
 * Variables fournies par gacct_report_render_html() :
 * @var array $context Contexte white-label + identification.
 * @var array $entry   Entrée de rapport.
 * @var array $data    Données saisies.
 * @var array $calc    Calculs serveur.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/report-parts.php';

$config = gacct_report_calc_config();
$type   = $calc['type'];
$texts  = gacct_report_voile_texts( $type );

$security_labels = array(
	'fluidite' => __( 'Fluidité suspentage', 'gestion-atelier-cct' ),
	'maillons' => __( 'Maillons / connecteurs', 'gestion-atelier-cct' ),
	'drisses'  => __( 'Drisses de frein, nœuds, poulies', 'gestion-atelier-cct' ),
);

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
	$texts['title'],
	$texts['intro'],
	__( 'Votre aile fait l\'objet d\'une grande attention tout au long de son inspection. En voici le compte-rendu détaillé.', 'gestion-atelier-cct' )
);

/* ── État général (périodique) / note de substitution (partielle) ─── */
if ( $texts['show_general'] ) {
	echo '<div class="rpdf-general">';
	echo '<h3 style="margin-top:0;">' . esc_html__( 'ÉTAT GÉNÉRAL DE LA VOILE', 'gestion-atelier-cct' ) . '</h3>';
	echo gacct_rpdf_badge( $calc['general'] );
	echo '<p class="muted">' . esc_html( $texts['general_note'] ) . '</p>';
	echo '</div>';
} else {
	echo '<div class="rpdf-general">';
	echo '<h3 style="margin-top:0;">' . esc_html__( 'ÉTAT GÉNÉRAL DE LA VOILE', 'gestion-atelier-cct' ) . '</h3>';
	echo '<p style="font-weight:bold;">' . esc_html( $texts['general_note'] ) . '</p>';
	echo '</div>';
}

/* ── Partielle : tableau de synthèse « RÉSULTATS DES INSPECTIONS » ── */
if ( $texts['show_results_summary'] ) {
	echo '<h2>' . esc_html__( 'RÉSULTATS DES INSPECTIONS', 'gestion-atelier-cct' ) . '</h2>';
	echo '<table class="rpdf-data"><tr>';
	echo '<th>' . esc_html__( 'Visuelle', 'gestion-atelier-cct' ) . '</th>';
	echo '<th>' . esc_html__( 'Mécanique', 'gestion-atelier-cct' ) . '</th>';
	echo '<th>' . esc_html__( 'Géométrique', 'gestion-atelier-cct' ) . '</th>';
	echo '</tr><tr>';
	echo '<td>' . gacct_rpdf_badge( $calc['visual_global'] ) . '</td>';
	echo '<td>' . gacct_rpdf_badge( $calc['mechanical'] ) . '</td>';
	echo '<td>' . gacct_rpdf_badge( $calc['geometry'] ) . '</td>';
	echo '</tr></table>';

	$next = isset( $data['next'] ) && is_array( $data['next'] ) ? $data['next'] : array();
	$next_date = ! empty( $next['date'] ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $next['date'] ) ) : '';

	if ( $next_date || ! empty( $next['hours'] ) || ! empty( $next['flights'] ) ) {
		echo '<h2>' . esc_html__( 'PROCHAIN CONTRÔLE', 'gestion-atelier-cct' ) . '</h2>';
		echo '<table class="rpdf-data"><tr>';
		echo '<th>' . esc_html__( 'Date', 'gestion-atelier-cct' ) . '</th>';
		echo '<th>' . esc_html__( 'Heures de vol', 'gestion-atelier-cct' ) . '</th>';
		echo '<th>' . esc_html__( 'Nb de vols', 'gestion-atelier-cct' ) . '</th>';
		echo '</tr><tr>';
		echo '<td>' . esc_html( $next_date ? $next_date : '—' ) . '</td>';
		echo '<td>' . esc_html( ! empty( $next['hours'] ) ? $next['hours'] : '—' ) . '</td>';
		echo '<td>' . esc_html( ! empty( $next['flights'] ) ? $next['flights'] : '—' ) . '</td>';
		echo '</tr></table>';
	}
}

/* ── Commentaires et travaux effectués ──────────────────────────────── */
$comment = isset( $data['comment'] ) ? trim( (string) $data['comment'] ) : '';
if ( '' !== $comment ) {
	echo '<h2>' . esc_html__( 'COMMENTAIRES ET TRAVAUX EFFECTUÉS', 'gestion-atelier-cct' ) . '</h2>';
	echo '<div class="rpdf-comment">' . nl2br( esc_html( $comment ) ) . '</div>';
}

/* ── Vérification de sécurité ───────────────────────────────────────── */
$security = isset( $data['securite'] ) && is_array( $data['securite'] ) ? $data['securite'] : array();
echo '<h2>' . esc_html__( 'Une vérification de sécurité a été réalisée sur :', 'gestion-atelier-cct' ) . '</h2>';
echo '<table class="rpdf-data"><tr>';
foreach ( $security_labels as $key => $label ) {
	echo '<th>' . esc_html( $label ) . '</th>';
}
echo '</tr><tr>';
foreach ( $security_labels as $key => $label ) {
	echo '<td style="text-align:center; font-size:11px;">' . ( ! empty( $security[ $key ] ) ? '✔' : '✘' ) . '</td>';
}
echo '</tr></table>';

/* ── Conclusion normative (textes du type) ──────────────────────────── */
foreach ( $texts['conclusion'] as $paragraph ) {
	echo '<p class="muted">' . nl2br( esc_html( $paragraph ) ) . '</p>';
}

/* ════════ Page 2 : détail des inspections ════════ */
echo '<div style="page-break-before: always;"></div>';

/* ── Inspection visuelle ────────────────────────────────────────────── */
echo '<h2>' . esc_html__( 'INSPECTION VISUELLE PARACHECK®', 'gestion-atelier-cct' )
	. ' &nbsp; ' . gacct_rpdf_badge( $calc['visual_global'] ) . '</h2>';
echo '<table class="rpdf-clean"><tr>';

foreach ( $config['visual_groups'] as $group_key => $group ) {
	$g = $calc['visual'][ $group_key ];

	echo '<td style="width:33%; vertical-align:top; padding-right:8px;">';
	echo '<h3>' . esc_html( $group['label'] ) . ' &nbsp; ' . gacct_rpdf_badge( $g['result'] )
		. ( null !== $g['average'] ? ' <span class="muted">moy. ' . esc_html( gacct_rpdf_num( $g['average'], 1 ) ) . '</span>' : '' ) . '</h3>';
	echo '<table class="rpdf-data">';
	foreach ( $group['items'] as $i => $item ) {
		$value = $g['values'][ $i ];
		$label = ( '' !== $value && isset( $config['visual_values'][ $value ] ) ) ? $config['visual_values'][ $value ]['label'] : '—';
		echo '<tr><th style="width:70%;">' . esc_html( $item ) . '</th><td style="text-align:center;">' . esc_html( $label ) . '</td></tr>';
	}
	echo '</table>';
	if ( $group['note'] ) {
		echo '<p class="rpdf-legend">' . esc_html( $group['note'] ) . '</p>';
	}
	echo '</td>';
}

echo '</tr></table>';
echo gacct_rpdf_legend( $texts['legends']['visual'] );

/* ── Inspection mécanique ───────────────────────────────────────────── */
echo '<h2>' . esc_html__( 'INSPECTION MÉCANIQUE PARACHECK®', 'gestion-atelier-cct' )
	. ' &nbsp; ' . gacct_rpdf_badge( $calc['mechanical'] ) . '</h2>';

/* — Porosité — */
$poro = $calc['porosity'];
echo '<h3>' . esc_html__( 'Test de porosité des tissus', 'gestion-atelier-cct' ) . ' &nbsp; ' . gacct_rpdf_badge( $poro['result'] ) . '</h3>';
echo '<p class="muted">' . esc_html__( 'État de vieillissement des tissus mesuré à l\'aide d\'un porosimètre de la marque JDC qui mesure le temps écoulé pour le passage au travers du tissu d\'un certain volume d\'air. Les valeurs sont exprimées en secondes et en l/m²/min.', 'gestion-atelier-cct' ) . '</p>';

echo '<table class="rpdf-data"><tr><th></th>';
foreach ( $config['porosity_points'] as $point ) {
	echo '<th style="text-align:center;">' . esc_html( $point ) . '</th>';
}
echo '<th style="text-align:center;">' . esc_html__( 'Moyenne', 'gestion-atelier-cct' ) . '</th></tr>';

$porosity_values = isset( $data['porosity'] ) && is_array( $data['porosity'] ) ? array_pad( array_values( $data['porosity'] ), 5, '' ) : array_fill( 0, 5, '' );

echo '<tr><th>s</th>';
foreach ( $porosity_values as $v ) {
	echo '<td style="text-align:center;">' . esc_html( '' !== $v ? gacct_rpdf_num( $v, 1 ) : '—' ) . '</td>';
}
echo '<td style="text-align:center; font-weight:bold;">' . esc_html( gacct_rpdf_num( $poro['average'], 1 ) ) . '</td></tr>';

echo '<tr><th>l/m²/min</th>';
foreach ( $poro['rates'] as $rate ) {
	echo '<td style="text-align:center;">' . esc_html( null !== $rate ? gacct_rpdf_num( $rate, 1 ) : '—' ) . '</td>';
}
$avg_rate = ( null !== $poro['average'] && $poro['average'] > 0 ) ? $config['porosity_factor'] / $poro['average'] : null;
echo '<td style="text-align:center; font-weight:bold;">' . esc_html( gacct_rpdf_num( $avg_rate, 1 ) ) . '</td></tr>';
echo '</table>';
echo '<p class="rpdf-legend">' . esc_html( $texts['porosity_note'] ) . '</p>';
echo gacct_rpdf_legend( $texts['legends']['porosity'] );

/* — Déchirure — */
$tear = $calc['tear'];
echo '<h3>' . esc_html__( 'Test de résistance à la déchirure des tissus', 'gestion-atelier-cct' ) . ' &nbsp; ' . gacct_rpdf_badge( $tear['result'] ) . '</h3>';
echo '<p class="muted">' . esc_html__( 'Test effectué avec instrument Bettsometer comportant une aiguille plantée dans le tissu. La traction appliquée sur l\'instrument indique la force appliquée sans provoquer de déchirure. Valeurs exprimées en daN.', 'gestion-atelier-cct' ) . '</p>';

echo '<table class="rpdf-data"><tr><th></th><th style="text-align:center;">' . esc_html__( 'Min.', 'gestion-atelier-cct' ) . '</th><th style="text-align:center;">' . esc_html__( 'Mesure', 'gestion-atelier-cct' ) . '</th><th>' . esc_html__( 'Interprétation', 'gestion-atelier-cct' ) . '</th></tr>';

$tear_values = isset( $data['tear'] ) && is_array( $data['tear'] ) ? $data['tear'] : array();
foreach ( $config['tear_zones'] as $zone_key => $zone_label ) {
	$v = isset( $tear_values[ $zone_key ] ) ? trim( (string) $tear_values[ $zone_key ] ) : '';
	echo '<tr><th>' . esc_html( $zone_label ) . '</th>';
	echo '<td style="text-align:center;">' . esc_html( gacct_rpdf_num( $tear['min'], 2 ) ) . '</td>';
	echo '<td style="text-align:center;">' . esc_html( '' !== $v ? gacct_rpdf_num( $v, 2 ) : '—' ) . '</td>';
	echo '<td>' . gacct_rpdf_badge( $tear['zones'][ $zone_key ] ) . '</td></tr>';
}
echo '</table>';
echo gacct_rpdf_legend( $texts['legends']['tear'] );

/* — Rupture des suspentes — */
$rupture = $calc['rupture'];
echo '<h3>' . esc_html__( 'Test de rupture des suspentes', 'gestion-atelier-cct' ) . ' &nbsp; ' . gacct_rpdf_badge( str_replace( '*', '', $rupture['result'] ) ) . '</h3>';
echo '<p class="muted">' . esc_html( $texts['rupture_intro'] ) . '</p>';

if ( ! empty( $rupture['lines'] ) ) {
	echo '<table class="rpdf-data"><tr><th></th>';
	foreach ( $rupture['lines'] as $line ) {
		echo '<th style="text-align:center;">' . esc_html( '' !== $line['ref'] ? $line['ref'] : '—' ) . '</th>';
	}
	echo '</tr>';

	$rows = array(
		'nominal' => __( 'Valeur nominale', 'gestion-atelier-cct' ),
		'material' => __( 'Matériau', 'gestion-atelier-cct' ),
		'seuil'   => __( 'Seuil réforme', 'gestion-atelier-cct' ),
		'measure' => __( 'Mesure de rupture', 'gestion-atelier-cct' ),
		'margin'  => __( 'Marge (%)', 'gestion-atelier-cct' ),
		'result'  => __( 'Interprétation', 'gestion-atelier-cct' ),
	);

	foreach ( $rows as $row_key => $row_label ) {
		echo '<tr><th>' . esc_html( $row_label ) . '</th>';
		foreach ( $rupture['lines'] as $line ) {
			echo '<td style="text-align:center;">';
			switch ( $row_key ) {
				case 'nominal':
					echo esc_html( $line['nominal'] > 0 ? gacct_rpdf_num( $line['nominal'], 1 ) : '—' );
					break;
				case 'material':
					echo esc_html( isset( $config['rupture_materials'][ $line['material'] ] ) ? $config['rupture_materials'][ $line['material'] ]['label'] : '—' );
					break;
				case 'seuil':
					echo esc_html( $line['seuil'] > 0 ? gacct_rpdf_num( $line['seuil'], 2 ) : '—' ) . ( $line['custom'] ? ' <span class="muted">(VR)</span>' : '' );
					break;
				case 'measure':
					echo esc_html( null !== $line['measure'] ? gacct_rpdf_num( $line['measure'], 1 ) : '—' );
					break;
				case 'margin':
					echo esc_html( null !== $line['margin'] ? gacct_rpdf_num( $line['margin'], 0 ) : 'NR*' );
					break;
				case 'result':
					echo 'NR*' === $line['result'] ? esc_html( 'NR*' ) : gacct_rpdf_badge( $line['result'] );
					break;
			}
			echo '</td>';
		}
		echo '</tr>';
	}
	echo '</table>';
	echo '<p class="rpdf-legend">' . esc_html__( '*NR = Non réalisé — test réalisé sur recommandation du constructeur.', 'gestion-atelier-cct' ) . '</p>';
} else {
	echo '<p class="muted">' . esc_html__( 'Non réalisé* — * Recommandation du constructeur.', 'gestion-atelier-cct' ) . '</p>';
}
echo gacct_rpdf_legend( $texts['legends']['rupture'] );

/* ── Inspection géométrique ─────────────────────────────────────────── */
$geometry = isset( $data['geometry'] ) && is_array( $data['geometry'] ) ? $data['geometry'] : array();
echo '<h2>' . esc_html__( 'INSPECTION GÉOMÉTRIQUE PARACHECK®', 'gestion-atelier-cct' ) . ' &nbsp; ' . gacct_rpdf_badge( $calc['geometry'] ) . '</h2>';
echo '<p class="muted">' . esc_html__( 'Calage de la voile contrôlé avec le système de mesure WOERNER. Le détail des mesures est disponible en annexe.', 'gestion-atelier-cct' ) . '</p>';

echo '<table class="rpdf-data">';
echo '<tr><th style="width:20%;">' . esc_html__( 'ÉCARTS CALAGE', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( ! empty( $geometry['calage_ecarts'] ) ? $geometry['calage_ecarts'] : '—' ) . '</td>'
	. '<th style="width:22%;">' . esc_html__( 'Interprétation', 'gestion-atelier-cct' ) . '</th><td style="width:18%;">' . gacct_rpdf_badge( ! empty( $geometry['calage_interp'] ) ? $geometry['calage_interp'] : 'NON RÉALISÉ' ) . '</td></tr>';
echo '<tr><th>' . esc_html__( 'Interventions', 'gestion-atelier-cct' ) . '</th><td colspan="3">' . esc_html( ! empty( $geometry['calage_interventions'] ) ? $geometry['calage_interventions'] : '—' ) . '</td></tr>';
echo '<tr><th>' . esc_html__( 'ÉCARTS FREINS', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( ! empty( $geometry['freins_ecarts'] ) ? $geometry['freins_ecarts'] : '—' ) . '</td>'
	. '<th>' . esc_html__( 'Interprétation', 'gestion-atelier-cct' ) . '</th><td>' . gacct_rpdf_badge( ! empty( $geometry['freins_interp'] ) ? $geometry['freins_interp'] : 'NON RÉALISÉ' ) . '</td></tr>';
echo '<tr><th>' . esc_html__( 'Interventions', 'gestion-atelier-cct' ) . '</th><td colspan="3">' . esc_html( ! empty( $geometry['freins_interventions'] ) ? $geometry['freins_interventions'] : '—' ) . '</td></tr>';
echo '<tr><th>' . esc_html__( 'Réglage des freins', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( ! empty( $geometry['reglage_freins'] ) ? $geometry['reglage_freins'] : '—' ) . '</td>'
	. '<th>' . esc_html__( 'Calage réalisé par', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $context['author'] ) . '</td></tr>';
echo '</table>';

gacct_rpdf_signature( $context );
?>
</body>
</html>
