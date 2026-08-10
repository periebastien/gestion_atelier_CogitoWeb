<?php
/**
 * Bon d'intervention — version PDF (dompdf). Inclus par gacct_wo_render_pdf().
 *
 * ⚠ JUMEAU de templates/workorder.php : même document, même design, deux
 * écritures. Celle-ci est en TABLES parce que dompdf ne connaît ni flexbox ni
 * grid. Toute retouche du bon est à faire dans les DEUX fichiers.
 *
 * Contraintes dompdf respectées ici :
 * - aucune requête réseau (logo et QR = chemins locaux, isRemoteEnabled false) ;
 * - pas de flex/grid, pas de `margin-top: auto` (le pied est en position fixed) ;
 * - pas de compteurs CSS (les numéros de la marche à suivre sont écrits en PHP) ;
 * - marges de page dans @page, pas dans un padding, pour éviter la 2e page.
 *
 * @var array $data Données de gacct_wo_data() + logo_path, qr_path, font.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wo_materiel = $data['materiel'];
$wo_client   = $data['client'];
$wo_accent   = sanitize_hex_color( $data['accent'] );
$wo_accent   = $wo_accent ? $wo_accent : '#20c4c3';

$wo_name = trim( $wo_materiel['marque'] . ' ' . $wo_materiel['modele'] );
$wo_name = '' !== $wo_name ? $wo_name : __( 'Matériel confié', 'gestion-atelier-cct' );

$wo_specs = array_filter(
	array(
		__( 'Taille', 'gestion-atelier-cct' )      => $wo_materiel['taille'],
		__( 'PTV', 'gestion-atelier-cct' )         => $wo_materiel['ptv'],
		__( 'N° de série', 'gestion-atelier-cct' ) => $wo_materiel['serie'],
	),
	function ( $v ) { return '' !== trim( (string) $v ); }
);

$wo_steps = array(
	__( 'Imprimez cette page en A4, sans mise à l\'échelle.', 'gestion-atelier-cct' ),
	__( 'Découpez l\'étiquette du bas et scotchez-la sur votre matériel.', 'gestion-atelier-cct' ),
	__( 'Glissez cette partie haute dans le colis avec votre matériel.', 'gestion-atelier-cct' ),
	__( 'Expédiez avant la date limite ci-dessus.', 'gestion-atelier-cct' ),
);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( sprintf( __( 'Bon d\'intervention %s', 'gestion-atelier-cct' ), $data['reference'] ) ); ?></title>
<style>
<?php echo $data['font']['css']; // phpcs:ignore WordPress.Security.EscapeOutput -- @font-face généré, chemins locaux. ?>
@page { size: A4 portrait; margin: 9mm 8mm 7mm; }
body { font-family: <?php echo $data['font']['family']; // phpcs:ignore WordPress.Security.EscapeOutput ?>; color: #1a1a1a; font-size: 10pt; line-height: 1.35; margin: 0; }
table { border-collapse: collapse; width: 100%; }
td { vertical-align: top; padding: 0; }
.lbl { font-size: 6.6pt; font-weight: bold; letter-spacing: 0.13em; text-transform: uppercase; color: #20a3a2; }

/* ── En-tête ── */
.head td { vertical-align: top; }
.head-logo img { height: 12mm; }
.head-name { font-size: 15pt; font-weight: bold; }
.head-r { text-align: right; }
.head-ref { font-size: 20pt; font-weight: bold; line-height: 1.05; }
.head-sub { font-size: 8pt; font-weight: bold; color: #8a8a8a; margin-top: 1mm; }
.rule { border-bottom: 2px solid <?php echo esc_html( $wo_accent ); ?>; height: 4mm; }

/* ── Partie qui part dans le colis ── */
.body-l { padding-right: 3mm; }
.body-r { width: 62mm; }
.blk { border: 1px solid #dcdcdc; border-radius: 2.5mm; padding: 3.5mm 4mm; margin-bottom: 3.5mm; }
.blk-t { font-size: 12pt; font-weight: bold; margin-top: 1mm; }
.kv { font-size: 9pt; font-weight: bold; margin-top: 1.6mm; }
.kv td { padding: 0; }
.kv-k { width: 23mm; color: #999; }
.dot { display: inline-block; width: 3mm; height: 3mm; border-radius: 1.5mm; margin-left: 1.5mm; }
.presta { margin-top: 1.5mm; font-size: 9.5pt; font-weight: bold; }
.presta td { padding: 0.7mm 0; }
.presta-b { width: 4mm; color: <?php echo esc_html( $wo_accent ); ?>; font-size: 12pt; line-height: 1; }

.deadline { background-color: <?php echo esc_html( $wo_accent ); ?>; color: #fff; border-radius: 2.5mm; padding: 4mm; text-align: center; }
.deadline .lbl { color: #eafafa; }
.deadline b { display: block; font-size: 16pt; margin-top: 1.5mm; }

.howto { margin-top: 3.5mm; background-color: #f6f9f9; border-radius: 2.5mm; padding: 4mm; }
.howto-list { margin-top: 2mm; font-size: 8.5pt; font-weight: bold; }
.howto-list td { padding: 0 0 2mm; }
.howto-n { width: 4.6mm; }
.howto-list .howto-txt { padding-left: 2mm; }
/* Pastille numérotée : une CELLULE, pas un div. dompdf ne centre pas
   verticalement une ligne dont le line-height est en mm (le chiffre tombait
   sous la pastille) ; `vertical-align: middle` sur un td, lui, marche. */
.howto-num { width: 4.6mm; height: 4.6mm; border-radius: 2.3mm; background-color: <?php echo esc_html( $wo_accent ); ?>; color: #fff; font-size: 7pt; font-weight: bold; text-align: center; vertical-align: middle; }
.addr { font-size: 9pt; font-weight: bold; line-height: 1.55; margin-top: 2mm; }
.addr b { color: #189998; }

/* ── Trait de découpe ── */
.cut { margin: 6mm 0 4mm; color: #999; }
.cut-t { font-size: 7pt; font-weight: bold; letter-spacing: 0.11em; text-transform: uppercase; white-space: nowrap; padding-right: 3mm; }
.cut-sym { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; padding-right: 2mm; }
/* Le filet de découpe est un DIV : posé en border sur le td, dompdf le mange
   (border-collapse). */
.cut-line { width: 100%; }
.cut-rule { border-top: 1.2px dashed #737373; height: 0; font-size: 0; line-height: 0; }

/* ── Étiquette à découper ── */
.tag { width: 132mm; margin: 0 auto; border: 1.3px dashed #737373; border-radius: 2mm; padding: 5mm; }
.tag-l { padding-right: 5mm; }
.tag-r { width: 32mm; text-align: center; }
.tag-item { font-size: 13pt; font-weight: bold; line-height: 1.15; }
.tag-specs { margin-top: 1.5mm; font-size: 8pt; font-weight: bold; color: #888; }
.tag-specs b { color: #1a1a1a; }
.tag-sep { border-top: 1px solid #dcdcdc; margin: 3mm 0; font-size: 0; line-height: 0; }
.tag-presta { font-size: 8.5pt; font-weight: bold; line-height: 1.5; }
.tag-cli { font-size: 8.5pt; font-weight: bold; line-height: 1.45; }
.tag-cli b { font-size: 9.5pt; }
.tag-cli span { color: #888; }
.tag-qr img { width: 32mm; height: 32mm; }
.tag-ref { font-size: 11pt; font-weight: bold; line-height: 1.1; margin-top: 2.5mm; }
.tag-kind { font-size: 6.4pt; font-weight: bold; letter-spacing: 0.12em; text-transform: uppercase; color: #8a8a8a; }
.tag-week { background-color: #f0fafa; border-radius: 1.8mm; padding: 2mm; margin-top: 2.5mm; }
.tag-week .lbl { font-size: 6pt; }
.tag-week b { display: block; font-size: 9pt; line-height: 1.2; margin-top: 0.8mm; }

/* Pied fixé au bas de la page : dompdf ne connaît pas `margin-top: auto`. */
.foot { position: fixed; bottom: 0; left: 0; right: 0; font-size: 7pt; font-weight: bold; color: #aaa; }
.foot td { vertical-align: bottom; }
.foot-r { text-align: right; }
</style>
</head>
<body>

<!-- ══ EN-TÊTE ══ -->
<table class="head">
	<tr>
		<td class="head-logo">
			<?php if ( ! empty( $data['logo_path'] ) ) : ?>
				<img src="<?php echo esc_attr( $data['logo_path'] ); ?>" alt="">
			<?php else : ?>
				<span class="head-name"><?php echo esc_html( $data['site_name'] ); ?></span>
			<?php endif; ?>
		</td>
		<td class="head-r">
			<div class="lbl"><?php esc_html_e( 'Bon d\'intervention', 'gestion-atelier-cct' ); ?></div>
			<div class="head-ref"><?php echo esc_html( $data['reference'] ); ?></div>
			<?php if ( ! empty( $data['slot_date'] ) ) : ?>
				<div class="head-sub"><?php echo esc_html( sprintf( __( 'Prise en charge atelier le %s', 'gestion-atelier-cct' ), $data['slot_date'] ) ); ?></div>
			<?php endif; ?>
		</td>
	</tr>
</table>
<div class="rule"></div>

<!-- ══ PARTIE À GLISSER DANS LE COLIS ══ -->
<table>
	<tr>
		<td class="body-l">

			<div class="blk">
				<div class="lbl"><?php esc_html_e( 'Matériel confié', 'gestion-atelier-cct' ); ?></div>
				<div class="blk-t"><?php echo esc_html( $wo_name ); ?></div>
				<?php foreach ( $wo_specs as $wo_label => $wo_value ) : ?>
					<table class="kv"><tr><td class="kv-k"><?php echo esc_html( $wo_label ); ?></td><td><?php echo esc_html( $wo_value ); ?></td></tr></table>
				<?php endforeach; ?>
				<?php if ( '' !== $wo_materiel['couleurs'] || ! empty( $wo_materiel['swatches'] ) ) : ?>
					<table class="kv"><tr>
						<td class="kv-k"><?php esc_html_e( 'Couleurs', 'gestion-atelier-cct' ); ?></td>
						<td><?php echo esc_html( $wo_materiel['couleurs'] ); ?><?php foreach ( (array) $wo_materiel['swatches'] as $wo_swatch ) : ?><span class="dot" style="background-color:<?php echo esc_attr( $wo_swatch['base'] ); ?>;"></span><?php endforeach; ?></td>
					</tr></table>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $data['prestations'] ) ) : ?>
				<div class="blk">
					<div class="lbl"><?php esc_html_e( 'Prestations commandées', 'gestion-atelier-cct' ); ?></div>
					<table class="presta">
						<?php foreach ( $data['prestations'] as $wo_prestation ) : ?>
							<tr><td class="presta-b">&bull;</td><td><?php echo esc_html( $wo_prestation ); ?></td></tr>
						<?php endforeach; ?>
					</table>
				</div>
			<?php endif; ?>

			<div class="blk">
				<div class="lbl"><?php esc_html_e( 'Client', 'gestion-atelier-cct' ); ?></div>
				<table class="kv"><tr><td class="kv-k"><?php esc_html_e( 'Nom', 'gestion-atelier-cct' ); ?></td><td><?php echo esc_html( $wo_client['name'] ); ?></td></tr></table>
				<?php if ( '' !== (string) $wo_client['email'] ) : ?>
					<table class="kv"><tr><td class="kv-k"><?php esc_html_e( 'E-mail', 'gestion-atelier-cct' ); ?></td><td><?php echo esc_html( $wo_client['email'] ); ?></td></tr></table>
				<?php endif; ?>
				<?php if ( '' !== (string) $wo_client['phone'] ) : ?>
					<table class="kv"><tr><td class="kv-k"><?php esc_html_e( 'Téléphone', 'gestion-atelier-cct' ); ?></td><td><?php echo esc_html( $wo_client['phone'] ); ?></td></tr></table>
				<?php endif; ?>
			</div>

		</td>
		<td class="body-r">

			<?php if ( ! empty( $data['deadline_date'] ) ) : ?>
				<div class="deadline">
					<div class="lbl"><?php esc_html_e( 'Colis à nous faire parvenir avant le', 'gestion-atelier-cct' ); ?></div>
					<b><?php echo esc_html( $data['deadline_date'] ); ?></b>
				</div>
			<?php endif; ?>

			<div class="howto">
				<div class="lbl"><?php esc_html_e( 'Comment procéder', 'gestion-atelier-cct' ); ?></div>
				<table class="howto-list">
					<?php foreach ( $wo_steps as $wo_i => $wo_step ) : ?>
						<tr>
							<td class="howto-n"><table><tr><td class="howto-num"><?php echo (int) ( $wo_i + 1 ); ?></td></tr></table></td>
							<td class="howto-txt"><?php echo esc_html( $wo_step ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php if ( ! empty( $data['store_address'] ) ) : ?>
					<div class="lbl" style="margin-top:4mm;"><?php esc_html_e( 'Adresse d\'expédition', 'gestion-atelier-cct' ); ?></div>
					<div class="addr">
						<?php foreach ( $data['store_address'] as $wo_i => $wo_line ) : ?>
							<?php if ( 0 === $wo_i ) : ?><b><?php echo esc_html( $wo_line ); ?></b><?php else : ?><?php echo esc_html( $wo_line ); ?><?php endif; ?><br>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

		</td>
	</tr>
</table>

<!-- ══ TRAIT DE DÉCOUPE ══ -->
<table class="cut">
	<tr>
		<td class="cut-sym">&#9986;</td>
		<td class="cut-t"><?php esc_html_e( 'Découpez l\'étiquette et scotchez-la sur votre matériel', 'gestion-atelier-cct' ); ?></td>
		<td class="cut-line"><div class="cut-rule"></div></td>
	</tr>
</table>

<!-- ══ ÉTIQUETTE À DÉCOUPER (une seule par bon) ══ -->
<div class="tag">
	<table>
		<tr>
			<td class="tag-l">
				<div class="lbl"><?php esc_html_e( 'Matériel confié', 'gestion-atelier-cct' ); ?></div>
				<div class="tag-item"><?php echo esc_html( $wo_name ); ?></div>
				<div class="tag-specs">
					<?php
					$wo_bits = array();
					foreach ( $wo_specs as $wo_label => $wo_value ) {
						$wo_bits[] = esc_html( $wo_label ) . ' <b>' . esc_html( $wo_value ) . '</b>';
					}
					if ( '' !== $wo_materiel['couleurs'] ) {
						$wo_bits[] = esc_html__( 'Couleur', 'gestion-atelier-cct' ) . ' <b>' . esc_html( $wo_materiel['couleurs'] ) . '</b>';
					}
					echo implode( ' &nbsp; ', $wo_bits ); // phpcs:ignore WordPress.Security.EscapeOutput -- morceaux déjà échappés.
					?>
				</div>

				<?php if ( ! empty( $data['prestations'] ) ) : ?>
					<div class="tag-sep">&nbsp;</div>
					<div class="tag-presta">
						<div class="lbl"><?php esc_html_e( 'Prestations commandées', 'gestion-atelier-cct' ); ?></div>
						<?php echo esc_html( implode( ' · ', $data['prestations'] ) ); ?>
					</div>
				<?php endif; ?>

				<div class="tag-sep">&nbsp;</div>
				<div class="tag-cli">
					<div class="lbl"><?php esc_html_e( 'Client', 'gestion-atelier-cct' ); ?></div>
					<b><?php echo esc_html( $wo_client['name'] ); ?></b><br>
					<?php echo esc_html( $wo_client['phone'] ); ?><?php if ( $wo_client['phone'] && $wo_client['email'] ) : ?> · <?php endif; ?><span><?php echo esc_html( $wo_client['email'] ); ?></span>
				</div>
			</td>
			<td class="tag-r">
				<?php if ( ! empty( $data['qr_path'] ) ) : ?>
					<div class="tag-qr"><img src="<?php echo esc_attr( $data['qr_path'] ); ?>" alt=""></div>
				<?php endif; ?>
				<div class="tag-ref"><?php echo esc_html( $data['reference'] ); ?></div>
				<div class="tag-kind"><?php esc_html_e( 'Bon d\'intervention', 'gestion-atelier-cct' ); ?></div>
				<?php if ( ! empty( $data['slot_date'] ) ) : ?>
					<div class="tag-week">
						<div class="lbl"><?php esc_html_e( 'Créneau atelier', 'gestion-atelier-cct' ); ?></div>
						<b><?php echo esc_html( $data['slot_date'] ); ?></b>
					</div>
				<?php endif; ?>
			</td>
		</tr>
	</table>
</div>

<table class="foot">
	<tr>
		<td>
			<?php
			echo esc_html( implode( ' · ', array_filter( array(
				implode( ', ', $data['store_address'] ),
				$data['contact_phone'],
			) ) ) );
			?>
		</td>
		<td class="foot-r"><?php echo esc_html( sprintf( __( 'Document généré automatiquement · référence %s', 'gestion-atelier-cct' ), $data['reference'] ) ); ?></td>
	</tr>
</table>

</body>
</html>
