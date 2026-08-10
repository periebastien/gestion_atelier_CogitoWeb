<?php
/**
 * Bon d'intervention — page A4 imprimable, standalone (incluse hors thème,
 * puis exit). $data fourni par gacct_wo_data() (includes/gacct-workorder.php).
 *
 * DEUX ZONES séparées par un trait de découpe :
 * - HAUT : la partie qui part DANS le colis (matériel, prestations, client,
 *   date limite, marche à suivre, adresse d'expédition) ;
 * - BAS : UNE SEULE étiquette (~132 mm) à découper et à scotcher SUR le
 *   matériel, avec le QR code.
 *
 * ⚠ Un seul QR par bon, quelle que soit la quantité de matériel : un colis
 * correspond à une seule révision. Le QR encode l'URL de scan de la commande
 * (gacct_wo_scan_url), pas la référence en clair — c'est elle qui ouvre la
 * vue réception de la console.
 *
 * ⚠ Le nom du matériel est du TEXTE LIBRE saisi par le client : voile,
 * secours, sellette… Ne rien présumer du type, ne pas découper l'étiquette
 * par catégorie d'élément.
 *
 * ⚠ Impression A4 SANS mise à l'échelle (consigne écrite dans la marche à
 * suivre) : tout autre format ferait redimensionner et déformer le QR.
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

$wo_name = trim( $wo_materiel['marque'] . ' ' . $wo_materiel['modele'] );
$wo_name = '' !== $wo_name ? $wo_name : __( 'Matériel confié', 'gestion-atelier-cct' );

// Specs affichées à l'identique en haut et sur l'étiquette (source unique).
$wo_specs = array_filter(
	array(
		__( 'Taille', 'gestion-atelier-cct' )      => $wo_materiel['taille'],
		__( 'PTV', 'gestion-atelier-cct' )         => $wo_materiel['ptv'],
		__( 'N° de série', 'gestion-atelier-cct' ) => $wo_materiel['serie'],
	),
	function ( $v ) { return '' !== trim( (string) $v ); }
);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( sprintf( __( 'Bon d\'intervention %s : %s', 'gestion-atelier-cct' ), $data['reference'], $data['site_name'] ) ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&display=swap">
<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', $wo_css_ver, $wo_css_url ) ); ?>">
<style>:root{--wo-accent:<?php echo esc_html( $wo_accent ); ?>;}</style>
</head>
<body>

<div class="wo-actions">
	<?php if ( function_exists( 'gacct_wo_pdf_url' ) ) : ?>
		<a class="wo-pdf-btn" href="<?php echo esc_url( gacct_wo_pdf_url( $data['order'] ) ); ?>"><?php esc_html_e( 'Télécharger le PDF', 'gestion-atelier-cct' ); ?></a>
	<?php endif; ?>
	<button type="button" class="wo-print-btn" onclick="window.print()"><?php esc_html_e( 'Imprimer ce bon', 'gestion-atelier-cct' ); ?></button>
</div>

<section class="page">

	<!-- ══ EN-TÊTE ══ -->
	<div class="head">
		<?php if ( ! empty( $data['logo_url'] ) ) : ?>
			<img src="<?php echo esc_url( $data['logo_url'] ); ?>" alt="<?php echo esc_attr( $data['site_name'] ); ?>">
		<?php else : ?>
			<span class="head-name"><?php echo esc_html( $data['site_name'] ); ?></span>
		<?php endif; ?>
		<div class="head-r">
			<div class="lbl"><?php esc_html_e( 'Bon d\'intervention', 'gestion-atelier-cct' ); ?></div>
			<div class="head-ref"><?php echo esc_html( $data['reference'] ); ?></div>
			<?php if ( ! empty( $data['slot_date'] ) ) : ?>
				<div class="head-sub"><?php echo esc_html( sprintf( __( 'Prise en charge atelier le %s', 'gestion-atelier-cct' ), $data['slot_date'] ) ); ?></div>
			<?php endif; ?>
		</div>
	</div>

	<!-- ══ PARTIE À GLISSER DANS LE COLIS ══ -->
	<div class="client">
		<div>
			<div class="blk">
				<div class="lbl"><?php esc_html_e( 'Matériel confié', 'gestion-atelier-cct' ); ?></div>
				<div class="blk-t"><?php echo esc_html( $wo_name ); ?></div>
				<?php foreach ( $wo_specs as $wo_label => $wo_value ) : ?>
					<div class="kv"><span><?php echo esc_html( $wo_label ); ?></span><span><?php echo esc_html( $wo_value ); ?></span></div>
				<?php endforeach; ?>
				<?php if ( '' !== $wo_materiel['couleurs'] || ! empty( $wo_materiel['swatches'] ) ) : ?>
					<div class="kv">
						<span><?php esc_html_e( 'Couleurs', 'gestion-atelier-cct' ); ?></span>
						<span><?php echo esc_html( $wo_materiel['couleurs'] ); ?><?php foreach ( (array) $wo_materiel['swatches'] as $wo_swatch ) : ?><i class="dot" style="background-image:linear-gradient(135deg, <?php echo esc_attr( $wo_swatch['base'] ); ?> 0%, <?php echo esc_attr( $wo_swatch['light'] ); ?> 100%);"></i><?php endforeach; ?></span>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $data['prestations'] ) ) : ?>
				<div class="blk">
					<div class="lbl"><?php esc_html_e( 'Prestations commandées', 'gestion-atelier-cct' ); ?></div>
					<ul class="presta">
						<?php foreach ( $data['prestations'] as $wo_prestation ) : ?>
							<li><?php echo esc_html( $wo_prestation ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="blk">
				<div class="lbl"><?php esc_html_e( 'Client', 'gestion-atelier-cct' ); ?></div>
				<div class="kv kv-first"><span><?php esc_html_e( 'Nom', 'gestion-atelier-cct' ); ?></span><span><?php echo esc_html( $wo_client['name'] ); ?></span></div>
				<?php if ( '' !== (string) $wo_client['email'] ) : ?>
					<div class="kv"><span><?php esc_html_e( 'E-mail', 'gestion-atelier-cct' ); ?></span><span><?php echo esc_html( $wo_client['email'] ); ?></span></div>
				<?php endif; ?>
				<?php if ( '' !== (string) $wo_client['phone'] ) : ?>
					<div class="kv"><span><?php esc_html_e( 'Téléphone', 'gestion-atelier-cct' ); ?></span><span><?php echo esc_html( $wo_client['phone'] ); ?></span></div>
				<?php endif; ?>
			</div>
		</div>

		<div>
			<?php if ( ! empty( $data['deadline_date'] ) ) : ?>
				<div class="deadline">
					<div class="lbl"><?php esc_html_e( 'Colis à nous faire parvenir avant le', 'gestion-atelier-cct' ); ?></div>
					<b><?php echo esc_html( $data['deadline_date'] ); ?></b>
				</div>
			<?php endif; ?>

			<div class="howto">
				<div class="lbl"><?php esc_html_e( 'Comment procéder', 'gestion-atelier-cct' ); ?></div>
				<ol>
					<li><?php esc_html_e( 'Imprimez cette page en A4, sans mise à l\'échelle.', 'gestion-atelier-cct' ); ?></li>
					<li><?php esc_html_e( 'Découpez l\'étiquette du bas et scotchez-la sur votre matériel.', 'gestion-atelier-cct' ); ?></li>
					<li><?php esc_html_e( 'Glissez cette partie haute dans le colis avec votre matériel.', 'gestion-atelier-cct' ); ?></li>
					<li><?php esc_html_e( 'Expédiez avant la date limite ci-dessus.', 'gestion-atelier-cct' ); ?></li>
				</ol>
				<?php if ( ! empty( $data['store_address'] ) ) : ?>
					<div class="lbl howto-addr-lbl"><?php esc_html_e( 'Adresse d\'expédition', 'gestion-atelier-cct' ); ?></div>
					<address class="addr">
						<?php foreach ( $data['store_address'] as $wo_i => $wo_line ) : ?>
							<?php if ( 0 === $wo_i ) : ?><b><?php echo esc_html( $wo_line ); ?></b><?php else : ?><?php echo esc_html( $wo_line ); ?><?php endif; ?><br>
						<?php endforeach; ?>
					</address>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- ══ TRAIT DE DÉCOUPE ══ -->
	<div class="cut">
		<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4L8.12 15.88M14.47 14.48L20 20M8.12 8.12L12 12"/></svg>
		<span class="cut-t"><?php esc_html_e( 'Découpez l\'étiquette et scotchez-la sur votre matériel', 'gestion-atelier-cct' ); ?></span>
	</div>

	<!-- ══ ÉTIQUETTE À DÉCOUPER (une seule, ~132 mm) ══ -->
	<div class="tags">
		<div class="tag">
			<div class="tag-l">
				<div>
					<div class="lbl"><?php esc_html_e( 'Matériel confié', 'gestion-atelier-cct' ); ?></div>
					<div class="tag-item"><?php echo esc_html( $wo_name ); ?></div>
					<div class="tag-specs">
						<?php foreach ( $wo_specs as $wo_label => $wo_value ) : ?>
							<span class="tag-spec"><?php echo esc_html( $wo_label ); ?> <b><?php echo esc_html( $wo_value ); ?></b></span>
						<?php endforeach; ?>
						<?php if ( '' !== $wo_materiel['couleurs'] ) : ?>
							<span class="tag-spec"><?php esc_html_e( 'Couleur', 'gestion-atelier-cct' ); ?> <b><?php echo esc_html( $wo_materiel['couleurs'] ); ?></b></span>
						<?php endif; ?>
					</div>
				</div>
				<?php if ( ! empty( $data['prestations'] ) ) : ?>
					<div class="tag-sep"></div>
					<div class="tag-presta">
						<div class="lbl"><?php esc_html_e( 'Prestations commandées', 'gestion-atelier-cct' ); ?></div>
						<?php echo esc_html( implode( ' · ', $data['prestations'] ) ); ?>
					</div>
				<?php endif; ?>
				<div class="tag-sep"></div>
				<div class="tag-cli">
					<div class="lbl"><?php esc_html_e( 'Client', 'gestion-atelier-cct' ); ?></div>
					<b><?php echo esc_html( $wo_client['name'] ); ?></b><br>
					<?php echo esc_html( $wo_client['phone'] ); ?><?php if ( $wo_client['phone'] && $wo_client['email'] ) : ?> · <?php endif; ?><span><?php echo esc_html( $wo_client['email'] ); ?></span>
				</div>
			</div>
			<div class="tag-r">
				<div id="wo-qr" class="tag-qr"></div>
				<div>
					<div class="tag-ref"><?php echo esc_html( $data['reference'] ); ?></div>
					<div class="tag-kind"><?php esc_html_e( 'Bon d\'intervention', 'gestion-atelier-cct' ); ?></div>
				</div>
				<?php if ( ! empty( $data['slot_date'] ) ) : ?>
					<div class="tag-week">
						<div class="lbl"><?php esc_html_e( 'Créneau atelier', 'gestion-atelier-cct' ); ?></div>
						<b><?php echo esc_html( $data['slot_date'] ); ?></b>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="foot">
		<span>
			<?php
			echo esc_html( implode( ' · ', array_filter( array(
				implode( ', ', $data['store_address'] ),
				$data['contact_phone'],
			) ) ) );
			?>
		</span>
		<span><?php echo esc_html( sprintf( __( 'Document généré automatiquement · référence %s', 'gestion-atelier-cct' ), $data['reference'] ) ); ?></span>
	</div>

</section>

<script src="<?php echo esc_url( $wo_qr_js_url ); ?>"></script>
<script>
( function () {
	var el = document.getElementById( 'wo-qr' );
	if ( ! el || 'undefined' === typeof QRCode ) {
		return;
	}
	// 32 mm à l'impression : on génère large (384 px) et la CSS le contraint,
	// pour que le QR reste net quel que soit le DPI de l'imprimante.
	new QRCode( el, {
		text: <?php echo wp_json_encode( $data['scan_url'] ); ?>,
		width: 384,
		height: 384,
		correctLevel: QRCode.CorrectLevel.M
	} );
} )();
</script>

</body>
</html>
