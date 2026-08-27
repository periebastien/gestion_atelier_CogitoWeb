<?php
/**
 * Landing page régionale — calée sur la charte de la page d'accueil (1249) :
 * hero clair deux colonnes, eyebrow teal, mot emphase teal, badge avis Google
 * gmbmanager, CTA pilule (DOM bouton Elementor → la CSS du kit et l'animation
 * ar-buttons.js s'appliquent), sections eyebrow + titre.
 *
 * @var array<string,mixed>  $region  Contenu de la région.
 * @var array<string,mixed>  $common  Réglages transverses.
 * @var array<string,string> $others  Autres régions (slug => nom).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hero_url = $region['hero_img'] ? wp_get_attachment_image_url( (int) $region['hero_img'], 'large' ) : '';
$cta_url  = (string) $common['cta_url'];

/**
 * Émet le DOM d'un bouton Elementor pour que la CSS du kit (ar-btn-*) et
 * l'animation ar-buttons.js (globale) s'appliquent sans Elementor.
 *
 * @param string $url     Lien.
 * @param string $label   Libellé.
 * @param string $classes Classes ar-btn-* du wrapper.
 * @return string
 */
function gacct_region_btn( $url, $label, $classes ) {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M7 7h10v10"></path></svg>';
	return sprintf(
		'<div class="elementor-element %1$s elementor-widget elementor-widget-button">'
		. '<a class="elementor-button elementor-button-link elementor-size-sm" href="%2$s">'
		. '<span class="elementor-button-content-wrapper">'
		. '<span class="elementor-button-icon">%3$s</span>'
		. '<span class="elementor-button-text">%4$s</span>'
		. '</span></a></div>',
		esc_attr( $classes ),
		esc_url( $url ),
		$svg,
		esc_html( $label )
	);
}
?>
<div class="ar-region">

	<!-- HERO -->
	<section class="ar-region-hero">
		<div class="ar-region-hero-in">
			<div class="ar-region-hero-text">
				<p class="ar-region-eyebrow"><?php echo esc_html( 'Révision parapente · ' . strtoupper( (string) $region['name'] ) ); ?></p>
				<h1 class="ar-region-h1"><?php echo wp_kses_post( (string) ( $region['h1_html'] ?? esc_html( $region['h1'] ) ) ); ?></h1>
				<p class="ar-region-intro"><?php echo esc_html( (string) $region['intro'] ); ?></p>

				<?php if ( ! empty( $common['reviews_badge_html'] ) ) : ?>
					<div class="ar-region-badge"><?php echo $common['reviews_badge_html']; // phpcs:ignore — HTML de confiance (réglage). ?></div>
				<?php endif; ?>

				<div class="ar-region-cta-row">
					<?php echo gacct_region_btn( $cta_url, (string) $common['cta_label'], 'ar-btn-pill ar-btn-swap ar-btn-arrow45' ); // phpcs:ignore ?>
					<?php echo gacct_region_btn( (string) $common['links']['controles'], 'Nos prestations', 'ar-btn-icon ar-btn-underline ar-btn-arrow45 ar-btn-swap' ); // phpcs:ignore ?>
				</div>
			</div>

			<div class="ar-region-hero-media">
				<?php if ( $hero_url ) : ?>
					<div class="ar-region-hero-photo" style="background-image:url(<?php echo esc_url( $hero_url ); ?>)"></div>
				<?php endif; ?>
				<?php $sv = $common['stat_volume']; ?>
				<div class="ar-region-hero-card">
					<span class="ar-region-hero-card-num"><?php echo esc_html( (string) $sv['value'] ); ?></span>
					<span class="ar-region-hero-card-lbl"><?php echo esc_html( (string) $sv['label'] ); ?></span>
				</div>
			</div>
		</div>
	</section>

	<!-- BANDEAU AVIS GOOGLE (widget gmbmanager, identique à l'accueil) -->
	<?php if ( ! empty( $common['reviews_band_html'] ) ) : ?>
		<section class="ar-region-gband"><?php echo $common['reviews_band_html']; // phpcs:ignore — HTML de confiance (réglage). ?></section>
	<?php endif; ?>

	<!-- CONTENU LOCAL -->
	<div class="ar-region-body">
		<?php foreach ( (array) $region['sections'] as $sec ) : ?>
			<section class="ar-region-section">
				<?php if ( ! empty( $sec['eyebrow'] ) ) : ?>
					<p class="ar-region-eyebrow"><?php echo esc_html( (string) $sec['eyebrow'] ); ?></p>
				<?php endif; ?>
				<h2 class="ar-region-h2"><?php echo esc_html( (string) $sec['h2'] ); ?></h2>
				<div class="ar-region-prose"><?php echo wp_kses_post( (string) $sec['html'] ); ?></div>
			</section>
		<?php endforeach; ?>

		<!-- ACCÈS / EXPÉDITION -->
		<?php if ( ! empty( $region['access'] ) ) : $acc = $region['access']; ?>
			<section class="ar-region-section ar-region-access">
				<p class="ar-region-eyebrow">Venir ou expédier</p>
				<h2 class="ar-region-h2"><?php echo esc_html( (string) $acc['title'] ); ?></h2>
				<div class="ar-region-access-grid">
					<div class="ar-region-prose"><p><?php echo esc_html( (string) $acc['intro'] ); ?></p></div>
					<?php if ( ! empty( $acc['rows'] ) ) : ?>
						<table class="ar-region-access-table">
							<thead><tr><th>Depuis</th><th>Trajet routier indicatif</th></tr></thead>
							<tbody>
							<?php foreach ( $acc['rows'] as $row ) : ?>
								<tr><td><?php echo esc_html( (string) $row[0] ); ?></td><td><?php echo esc_html( (string) $row[1] ); ?></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>
	</div>

	<!-- CHIFFRES LOCAUX (interventions par département + marques) -->
	<?php if ( ! empty( $region['depts'] ) ) : ?>
		<section class="ar-region-local">
			<div class="ar-region-local-in">
				<p class="ar-region-eyebrow">Nos révisions en <?php echo esc_html( (string) $region['name'] ); ?></p>
				<h2 class="ar-region-h2">Un ancrage réel dans la région</h2>
				<div class="ar-region-prose"><p>Depuis 2019, l’atelier a contrôlé <strong><?php echo esc_html( (string) $region['stat_local']['value'] ); ?></strong> voiles pour des pilotes de <?php echo esc_html( (string) $region['name'] ); ?>. La répartition par département&nbsp;:</p></div>
				<div class="ar-region-depts">
					<?php foreach ( $region['depts'] as $d ) : ?>
						<div class="ar-region-dept">
							<span class="ar-region-dept-num"><?php echo esc_html( (string) $d[1] ); ?></span>
							<span class="ar-region-dept-name"><?php echo esc_html( (string) $d[0] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( ! empty( $region['brands'] ) ) : ?>
					<p class="ar-region-brands"><?php echo wp_kses_post( (string) $region['brands'] ); ?></p>
				<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>

	<!-- FAQ -->
	<?php if ( ! empty( $region['faq'] ) ) : ?>
		<section class="ar-region-faq">
			<div class="ar-region-faq-in">
				<p class="ar-region-eyebrow">Questions fréquentes</p>
				<h2 class="ar-region-h2">Vous vous demandez peut-être…</h2>
				<?php foreach ( $region['faq'] as $qa ) : ?>
					<details class="ar-region-faq-item">
						<summary><?php echo esc_html( (string) $qa['q'] ); ?></summary>
						<div class="ar-region-faq-a"><p><?php echo esc_html( (string) $qa['a'] ); ?></p></div>
					</details>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<!-- CTA FINAL -->
	<section class="ar-region-cta-band">
		<div class="ar-region-cta-band-in">
			<p class="ar-region-eyebrow ar-region-eyebrow--light">Réservation en ligne · Planning en temps réel</p>
			<h2 class="ar-region-cta-title">Faites contrôler votre voile en <?php echo esc_html( (string) $region['name'] ); ?></h2>
			<p class="ar-region-cta-sub">Choisissez votre créneau, suivez votre colis à l’aller comme au retour, recevez un rapport de contrôle détaillé.</p>
			<div class="ar-region-cta-row ar-region-cta-row--center">
				<?php echo gacct_region_btn( $cta_url, (string) $common['cta_label'], 'ar-btn-pill ar-btn-swap ar-btn-arrow45 ar-btn-on-dark' ); // phpcs:ignore ?>
			</div>
		</div>
	</section>


</div>
