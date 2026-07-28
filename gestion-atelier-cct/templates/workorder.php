<?php
/**
 * Bon d'intervention — page A4 imprimable, standalone (incluse hors thème,
 * puis exit). $data fourni par gacct_wo_data() (includes/gacct-workorder.php).
 *
 * Le client imprime ce bon et le glisse dans le colis de sa voile ; l'atelier
 * scanne le QR à la réception. Document de marque : sobre, aéré, accent teal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wo_plugin_file = dirname( __DIR__ ) . '/gestion-atelier-cct.php';
$wo_css_url     = plugins_url( 'assets/css/workorder.css', $wo_plugin_file );
$wo_qr_js_url   = plugins_url( 'assets/vendor/qrcodejs/qrcode.min.js', $wo_plugin_file );
$wo_css_file    = dirname( __DIR__ ) . '/assets/css/workorder.css';
$wo_css_ver     = file_exists( $wo_css_file ) ? (string) filemtime( $wo_css_file ) : '1';

$wo_materiel = $data['materiel'];
$wo_client   = $data['client'];
$wo_accent   = sanitize_hex_color( $data['accent'] );
$wo_accent   = $wo_accent ? $wo_accent : '#20c4c3';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( sprintf( __( 'Bon d\'intervention %s — %s', 'gestion-atelier-cct' ), $data['reference'], $data['site_name'] ) ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', $wo_css_ver, $wo_css_url ) ); ?>">
<style>:root{--wo-accent:<?php echo esc_html( $wo_accent ); ?>;}</style>
</head>
<body>

<button type="button" class="wo-print-btn" onclick="window.print()"><?php esc_html_e( 'Imprimer ce bon', 'gestion-atelier-cct' ); ?></button>

<main class="wo-sheet">

	<!-- ── En-tête de marque ─────────────────────────────────────────── -->
	<header class="wo-head">
		<div class="wo-head-brand">
			<?php if ( ! empty( $data['logo_url'] ) ) : ?>
				<img class="wo-logo" src="<?php echo esc_url( $data['logo_url'] ); ?>" alt="<?php echo esc_attr( $data['site_name'] ); ?>">
			<?php else : ?>
				<span class="wo-logo-text"><?php echo esc_html( $data['site_name'] ); ?></span>
			<?php endif; ?>
		</div>
		<div class="wo-head-title">
			<span class="wo-doc-type"><?php esc_html_e( 'Bon d\'intervention', 'gestion-atelier-cct' ); ?></span>
			<span class="wo-ref"><?php echo esc_html( $data['reference'] ); ?></span>
			<?php if ( ! empty( $data['slot_date'] ) ) : ?>
				<span class="wo-slot"><?php echo esc_html( sprintf( __( 'Prise en charge atelier le %s', 'gestion-atelier-cct' ), $data['slot_date'] ) ); ?></span>
			<?php endif; ?>
		</div>
	</header>
	<div class="wo-rule" aria-hidden="true"></div>

	<!-- ── Corps : 2 colonnes ────────────────────────────────────────── -->
	<div class="wo-body">

		<div class="wo-col-main">

			<!-- Matériel : la vedette du document -->
			<section class="wo-card wo-card-materiel">
				<h2 class="wo-card-title"><?php esc_html_e( 'Matériel', 'gestion-atelier-cct' ); ?></h2>
				<p class="wo-materiel-name">
					<?php echo esc_html( trim( $wo_materiel['marque'] . ' ' . $wo_materiel['modele'] ) ); ?>
				</p>
				<dl class="wo-specs">
					<?php if ( '' !== $wo_materiel['serie'] ) : ?>
						<div class="wo-spec wo-spec-serie">
							<dt><?php esc_html_e( 'N° de série', 'gestion-atelier-cct' ); ?></dt>
							<dd class="wo-serie"><?php echo esc_html( $wo_materiel['serie'] ); ?></dd>
						</div>
					<?php endif; ?>
					<?php if ( '' !== $wo_materiel['taille'] ) : ?>
						<div class="wo-spec">
							<dt><?php esc_html_e( 'Taille', 'gestion-atelier-cct' ); ?></dt>
							<dd><?php echo esc_html( $wo_materiel['taille'] ); ?></dd>
						</div>
					<?php endif; ?>
					<?php if ( '' !== $wo_materiel['ptv'] ) : ?>
						<div class="wo-spec">
							<dt><?php esc_html_e( 'PTV', 'gestion-atelier-cct' ); ?></dt>
							<dd><?php echo esc_html( $wo_materiel['ptv'] ); ?></dd>
						</div>
					<?php endif; ?>
					<?php if ( '' !== $wo_materiel['couleurs'] || ! empty( $wo_materiel['swatches'] ) ) : ?>
						<div class="wo-spec">
							<dt><?php esc_html_e( 'Couleurs', 'gestion-atelier-cct' ); ?></dt>
							<dd class="wo-couleurs">
								<span><?php echo esc_html( $wo_materiel['couleurs'] ); ?></span>
								<?php foreach ( (array) $wo_materiel['swatches'] as $wo_swatch ) : ?>
									<span class="wo-swatch" style="background-image:linear-gradient(135deg, <?php echo esc_attr( $wo_swatch['base'] ); ?> 0%, <?php echo esc_attr( $wo_swatch['light'] ); ?> 100%);"></span>
								<?php endforeach; ?>
							</dd>
						</div>
					<?php endif; ?>
				</dl>
			</section>

			<!-- Prestations = check-list du contenu du colis -->
			<section class="wo-card">
				<h2 class="wo-card-title"><?php esc_html_e( 'Prestations commandées', 'gestion-atelier-cct' ); ?></h2>
				<?php if ( ! empty( $data['prestations'] ) ) : ?>
					<ul class="wo-prestations">
						<?php foreach ( $data['prestations'] as $wo_prestation ) : ?>
							<li><span class="wo-check" aria-hidden="true"></span><?php echo esc_html( $wo_prestation ); ?></li>
						<?php endforeach; ?>
					</ul>
					<p class="wo-card-note"><?php esc_html_e( 'Cases pointées par l\'atelier à la réception du colis.', 'gestion-atelier-cct' ); ?></p>
				<?php else : ?>
					<p class="wo-card-note"><?php esc_html_e( 'Voir le détail de la commande.', 'gestion-atelier-cct' ); ?></p>
				<?php endif; ?>
			</section>

			<!-- Client (compact) -->
			<section class="wo-card wo-card-client">
				<h2 class="wo-card-title"><?php esc_html_e( 'Client', 'gestion-atelier-cct' ); ?></h2>
				<p class="wo-client-line">
					<strong><?php echo esc_html( $wo_client['name'] ); ?></strong>
					<?php if ( '' !== (string) $wo_client['email'] ) : ?>
						<span class="wo-client-sep" aria-hidden="true">·</span> <?php echo esc_html( $wo_client['email'] ); ?>
					<?php endif; ?>
					<?php if ( '' !== (string) $wo_client['phone'] ) : ?>
						<span class="wo-client-sep" aria-hidden="true">·</span> <?php echo esc_html( $wo_client['phone'] ); ?>
					<?php endif; ?>
				</p>
			</section>

		</div>

		<aside class="wo-col-side">

			<!-- QR -->
			<section class="wo-qr-box">
				<div id="wo-qr" class="wo-qr"></div>
				<p class="wo-qr-fallback"><?php echo esc_html( $data['reference'] ); ?></p>
				<p class="wo-qr-caption"><?php esc_html_e( 'À scanner à la réception par l\'atelier', 'gestion-atelier-cct' ); ?></p>
			</section>

			<!-- Date limite d'arrivée du colis -->
			<?php if ( ! empty( $data['deadline_date'] ) ) : ?>
				<section class="wo-deadline">
					<span class="wo-deadline-label"><?php esc_html_e( 'Votre colis doit nous parvenir avant le', 'gestion-atelier-cct' ); ?></span>
					<span class="wo-deadline-date"><?php echo esc_html( $data['deadline_date'] ); ?></span>
				</section>
			<?php endif; ?>

			<!-- Instructions client -->
			<section class="wo-steps">
				<h2 class="wo-card-title"><?php esc_html_e( 'Comment procéder', 'gestion-atelier-cct' ); ?></h2>
				<ol class="wo-steps-list">
					<li><?php esc_html_e( 'Imprimez ce bon d\'intervention.', 'gestion-atelier-cct' ); ?></li>
					<li><?php esc_html_e( 'Glissez-le dans le colis avec votre matériel.', 'gestion-atelier-cct' ); ?></li>
					<li><?php esc_html_e( 'Expédiez le colis avant la date limite.', 'gestion-atelier-cct' ); ?></li>
				</ol>
			</section>

		</aside>

	</div>

	<!-- ── Réservé atelier ───────────────────────────────────────────── -->
	<section class="wo-atelier">
		<h2 class="wo-atelier-title"><?php esc_html_e( 'Réservé atelier', 'gestion-atelier-cct' ); ?></h2>
		<div class="wo-atelier-zone">
			<div class="wo-atelier-lines" aria-hidden="true"></div>
			<div class="wo-atelier-fields">
				<span class="wo-atelier-field"><?php esc_html_e( 'Reçu le', 'gestion-atelier-cct' ); ?><span class="wo-blank" aria-hidden="true"></span></span>
				<span class="wo-atelier-field"><?php esc_html_e( 'Par', 'gestion-atelier-cct' ); ?><span class="wo-blank" aria-hidden="true"></span></span>
			</div>
		</div>
	</section>

	<!-- ── Pied de page ──────────────────────────────────────────────── -->
	<footer class="wo-foot">
		<span><?php echo esc_html( $data['site_name'] ); ?></span>
		<span><?php echo esc_html( sprintf( __( 'Document généré automatiquement — référence %s', 'gestion-atelier-cct' ), $data['reference'] ) ); ?></span>
	</footer>

</main>

<script src="<?php echo esc_url( $wo_qr_js_url ); ?>"></script>
<script>
( function () {
	var el = document.getElementById( 'wo-qr' );
	if ( ! el || 'undefined' === typeof QRCode ) {
		return;
	}
	new QRCode( el, {
		text: <?php echo wp_json_encode( $data['scan_url'] ); ?>,
		width: 132,
		height: 132,
		correctLevel: QRCode.CorrectLevel.M
	} );
} )();
</script>

</body>
</html>
