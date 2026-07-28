<?php
/**
 * PDF — Contrôle équipement (sellette + parachute de secours), onglet
 * « Contrôle équipement » du classeur ParachecK V8.
 *
 * @var array $context
 * @var array $entry
 * @var array $data
 * @var array|null $calc (inutilisé : pas de formule dans ce modèle)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/report-parts.php';

$sellette = isset( $data['sellette'] ) && is_array( $data['sellette'] ) ? $data['sellette'] : array();
$secours  = isset( $data['secours'] ) && is_array( $data['secours'] ) ? $data['secours'] : array();

$gacct_rpdf_gear_row = static function ( array $gear ) {
	echo '<table class="rpdf-data"><tr>';
	echo '<th style="width:12%;">' . esc_html__( 'Marque', 'gestion-atelier-cct' ) . '</th><td style="width:21%;">' . esc_html( ! empty( $gear['marque'] ) ? $gear['marque'] : '—' ) . '</td>';
	echo '<th style="width:12%;">' . esc_html__( 'Modèle', 'gestion-atelier-cct' ) . '</th><td style="width:21%;">' . esc_html( ! empty( $gear['modele'] ) ? $gear['modele'] : '—' ) . '</td>';
	echo '<th style="width:8%;">' . esc_html__( 'N°', 'gestion-atelier-cct' ) . '</th><td style="width:13%;">' . esc_html( ! empty( $gear['numero'] ) ? $gear['numero'] : '—' ) . '</td>';
	echo '<th style="width:8%;">' . esc_html__( 'Taille', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( ! empty( $gear['taille'] ) ? $gear['taille'] : '—' ) . '</td>';
	echo '</tr></table>';
};

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
	__( 'Contrôle équipement — Secours / Sellette', 'gestion-atelier-cct' ),
	'La révision périodique ParachecK® permet de répondre aux exigences de la norme EN 926-2 en terme d\'entretien, pour informer le propriétaire ou l\'acheteur de la capacité d\'une aile à voler en sécurité, à un instant donné. Les inspections ParachecK® ne vous renseignent que partiellement sur son état.',
	__( 'Votre équipement a fait l\'objet d\'une grande attention tout au long de son inspection.', 'gestion-atelier-cct' ),
	false
);

/* ── Sellette ───────────────────────────────────────────────────────── */
echo '<h2>' . esc_html__( 'Sellette', 'gestion-atelier-cct' ) . '</h2>';
$gacct_rpdf_gear_row( $sellette );

$verifications = ! empty( $sellette['verifications'] ) ? (string) $sellette['verifications'] : '';
if ( '' !== trim( $verifications ) ) {
	echo '<div class="rpdf-comment">' . nl2br( esc_html( $verifications ) ) . '</div>';
}

if ( ! empty( $sellette['remarques'] ) ) {
	echo '<h3>' . esc_html__( 'Remarque(s) :', 'gestion-atelier-cct' ) . '</h3>';
	echo '<div class="rpdf-comment">' . nl2br( esc_html( $sellette['remarques'] ) ) . '</div>';
}

/* ── Secours ────────────────────────────────────────────────────────── */
echo '<h2>' . esc_html__( 'Secours', 'gestion-atelier-cct' ) . '</h2>';
$gacct_rpdf_gear_row( $secours );

echo '<table class="rpdf-data">';
echo '<tr><th style="width:30%;">' . esc_html__( 'Date de production', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( ! empty( $secours['date_production'] ) ? $secours['date_production'] : '—' ) . '</td></tr>';
echo '<tr><th>' . esc_html__( 'Aération et pliage du parachute', 'gestion-atelier-cct' ) . '</th><td style="font-size:11px;">' . ( ! empty( $secours['aeration'] ) ? '✔' : '✘' ) . '</td></tr>';
echo '</table>';

if ( ! empty( $secours['remarques'] ) ) {
	echo '<h3>' . esc_html__( 'Remarque(s) :', 'gestion-atelier-cct' ) . '</h3>';
	echo '<div class="rpdf-comment">' . nl2br( esc_html( $secours['remarques'] ) ) . '</div>';
}

gacct_rpdf_signature( $context );
?>
</body>
</html>
