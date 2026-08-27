<?php
/**
 * Landing page régionale. Variables fournies par gacct_region_shortcode() :
 *
 * @var array<string,mixed> $region  Contenu de la région.
 * @var array<string,mixed> $common  Réglages transverses.
 * @var array<string,string> $others Autres régions (slug => nom).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hero_url = $region['hero_img'] ? wp_get_attachment_image_url( (int) $region['hero_img'], 'full' ) : '';
$cta_url  = esc_url( (string) $common['cta_url'] );
?>
<div class="ar-region">

	<!-- HERO -->
	<section class="ar-region-hero"<?php echo $hero_url ? ' style="--ar-hero:url(' . esc_url( $hero_url ) . ')"' : ''; ?>>
		<div class="ar-region-hero-in">
			<p class="ar-region-kicker">
				<span class="ar-region-stars" aria-hidden="true">★★★★★</span>
				<?php echo esc_html( $common['stat_reviews']['value'] . ' ' . $common['stat_reviews']['label'] ); ?>
			</p>
			<h1 class="ar-region-h1"><?php echo esc_html( (string) $region['h1'] ); ?></h1>
			<p class="ar-region-intro"><?php echo esc_html( (string) $region['intro'] ); ?></p>
			<div class="ar-region-cta-wrap">
				<a class="ar-region-cta" href="<?php echo $cta_url; ?>">
					<?php echo esc_html( (string) $common['cta_label'] ); ?>
					<span class="ar-region-cta-arrow" aria-hidden="true">→</span>
				</a>
				<span class="ar-region-cta-sub"><?php echo esc_html( (string) $common['cta_sub'] ); ?></span>
			</div>
		</div>
	</section>

	<!-- BANDEAU CHIFFRES -->
	<section class="ar-region-stats">
		<?php
		$stats = array(
			$region['stat_local'] ?? null,
			$common['stat_volume'],
			$common['stat_reviews'],
			$common['stat_paracheck'],
		);
		foreach ( array_filter( $stats ) as $s ) :
			?>
			<div class="ar-region-stat">
				<span class="ar-region-stat-value"><?php echo esc_html( (string) $s['value'] ); ?></span>
				<span class="ar-region-stat-label"><?php echo esc_html( (string) $s['label'] ); ?></span>
			</div>
		<?php endforeach; ?>
	</section>

	<!-- CONTENU LOCAL -->
	<div class="ar-region-body">
		<?php foreach ( (array) $region['sections'] as $sec ) : ?>
			<section class="ar-region-section">
				<h2><?php echo esc_html( (string) $sec['h2'] ); ?></h2>
				<?php echo wp_kses_post( (string) $sec['html'] ); ?>
			</section>
		<?php endforeach; ?>

		<!-- ACCÈS / EXPÉDITION -->
		<?php if ( ! empty( $region['access'] ) ) : $acc = $region['access']; ?>
			<section class="ar-region-section ar-region-access">
				<h2><?php echo esc_html( (string) $acc['title'] ); ?></h2>
				<p><?php echo esc_html( (string) $acc['intro'] ); ?></p>
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
			</section>
		<?php endif; ?>

		<!-- AVIS -->
		<?php if ( ! empty( $region['reviews'] ) ) : ?>
			<section class="ar-region-reviews">
				<h2>Ce qu’en disent nos clients</h2>
				<div class="ar-region-reviews-grid">
					<?php foreach ( $region['reviews'] as $rev ) : ?>
						<figure class="ar-region-review">
							<span class="ar-region-review-stars" aria-hidden="true">★★★★★</span>
							<blockquote><?php echo esc_html( (string) $rev['quote'] ); ?></blockquote>
							<figcaption><?php echo esc_html( (string) $rev['author'] ); ?> · <?php echo esc_html( (string) $rev['date'] ); ?></figcaption>
						</figure>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<!-- FAQ -->
		<?php if ( ! empty( $region['faq'] ) ) : ?>
			<section class="ar-region-faq">
				<h2>Questions fréquentes</h2>
				<?php foreach ( $region['faq'] as $qa ) : ?>
					<details class="ar-region-faq-item">
						<summary><?php echo esc_html( (string) $qa['q'] ); ?></summary>
						<div class="ar-region-faq-a"><p><?php echo esc_html( (string) $qa['a'] ); ?></p></div>
					</details>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>
	</div>

	<!-- CTA FINAL -->
	<section class="ar-region-cta-band">
		<h2>Faites contrôler votre voile en <?php echo esc_html( (string) $region['name'] ); ?></h2>
		<p>Réservez votre créneau en ligne : vous choisissez la date de prise en charge, vous suivez votre colis, vous recevez un rapport de contrôle détaillé.</p>
		<a class="ar-region-cta ar-region-cta--light" href="<?php echo $cta_url; ?>">
			<?php echo esc_html( (string) $common['cta_label'] ); ?>
			<span class="ar-region-cta-arrow" aria-hidden="true">→</span>
		</a>
	</section>

	<!-- MAILLAGE -->
	<nav class="ar-region-nearby">
		<?php if ( ! empty( $others ) ) : ?>
			<div class="ar-region-nearby-block">
				<span class="ar-region-nearby-title"><?php echo esc_html( (string) $common['nearby_title'] ); ?></span>
				<ul>
					<?php foreach ( $others as $slug => $name ) : ?>
						<li><a href="<?php echo esc_url( gacct_region_url( $slug ) ); ?>"><?php echo esc_html( $name ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<div class="ar-region-nearby-block">
			<span class="ar-region-nearby-title">Nos prestations</span>
			<ul>
				<li><a href="<?php echo esc_url( (string) $common['links']['controles'] ); ?>">Contrôle &amp; révision</a></li>
				<li><a href="<?php echo esc_url( (string) $common['links']['secours'] ); ?>">Pliage de secours</a></li>
				<li><a href="<?php echo esc_url( (string) $common['links']['suspentes'] ); ?>">Suspentes &amp; travaux</a></li>
				<li><a href="<?php echo esc_url( (string) $common['links']['reparation'] ); ?>">Réparation</a></li>
				<li><a href="<?php echo esc_url( (string) $common['links']['tarifs'] ); ?>">Tarifs</a></li>
			</ul>
		</div>
	</nav>

</div>
