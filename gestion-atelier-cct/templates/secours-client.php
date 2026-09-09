<?php
/**
 * Bloc « Mes parachutes de secours » de la page « Mon matériel ».
 * Inclus par le shortcode `[gacct_secours_client]` (includes/gacct-secours-client.php).
 *
 * Variables : $secours (gacct_secours_client()), $texts, $demande (URL de la demande).
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

$fmt = static function ( $ymd ) {
	return $ymd ? date_i18n( 'j M Y', strtotime( $ymd ) ) : '';
};
?>
<section class="gacct-sec" id="gacct-secours">
	<h3 class="gacct-sec-titre"><?php echo esc_html( $texts['titre'] ); ?><?php if ( $secours ) : ?> <span class="gacct-sec-count"><?php echo count( $secours ); ?></span><?php endif; ?></h3>
	<p class="gacct-sec-intro"><?php echo esc_html( $texts['intro'] ); ?></p>

	<?php if ( isset( $_GET['rappel'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<p class="gacct-sec-notice" role="status"><?php echo esc_html( 'off' === $_GET['rappel'] ? $texts['rappel_off'] : $texts['rappel_on'] ); // phpcs:ignore ?></p>
	<?php endif; ?>

	<?php if ( ! $secours ) : ?>
		<p class="gacct-sec-vide"><?php echo esc_html( $texts['vide'] ); ?></p>
	<?php else : ?>
		<div class="gacct-sec-liste">
			<?php foreach ( $secours as $i => $s ) : ?>
				<article class="gacct-sec-card is-<?php echo esc_attr( $s['statut'] ); ?>">
					<div class="gacct-sec-head">
						<div class="gacct-sec-nom">
							<strong><?php echo esc_html( trim( $s['marque'] . ' ' . $s['modele'] ) ?: $texts['inconnu'] ); ?></strong>
							<?php if ( 'ancien' === $s['source'] ) : ?><span class="gacct-sec-tag"><?php echo esc_html( $texts['ancien'] ); ?></span><?php endif; ?>
						</div>
						<span class="gacct-sec-statut"><?php echo esc_html( $texts['statut'][ $s['statut'] ] ?? '' ); ?></span>
					</div>
					<dl class="gacct-sec-infos">
						<?php if ( '' !== $s['taille'] ) : ?>
							<div><dt><?php echo esc_html( $texts['taille'] ); ?></dt><dd><?php echo esc_html( $s['taille'] ); ?></dd></div>
						<?php endif; ?>
						<?php if ( '' !== $s['date_production'] ) : ?>
							<div><dt><?php echo esc_html( $texts['production'] ); ?></dt><dd><?php echo esc_html( $s['date_production'] ); ?></dd></div>
						<?php endif; ?>
						<div><dt><?php echo esc_html( $texts['dernier'] ); ?></dt><dd><?php echo esc_html( $s['dernier_pliage'] ? $fmt( $s['dernier_pliage'] ) : $texts['inconnu'] ); ?></dd></div>
						<div><dt><?php echo esc_html( $texts['prochain'] ); ?></dt><dd class="gacct-sec-prochain"><?php echo esc_html( $s['prochain_pliage'] ? $fmt( $s['prochain_pliage'] ) : $texts['inconnu'] ); ?></dd></div>
					</dl>
					<div class="gacct-sec-actions">
						<?php if ( 'en_cours' !== $s['statut'] ) : ?>
							<a class="gacct-sec-btn" href="<?php echo esc_url( add_query_arg( 'secours', $i, $demande ) ); ?>"><?php echo esc_html( $texts['demander'] ); ?></a>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $secours && function_exists( 'gacct_secours_rappel_actif' ) ) : ?>
		<form method="post" class="gacct-sec-rappel">
			<?php wp_nonce_field( 'gacct_secours_rappel', '_gacct_secours_rappel_nonce' ); ?>
			<input type="hidden" name="gacct_secours_rappel_form" value="1">
			<label><input type="checkbox" name="rappel_on" value="1" <?php checked( gacct_secours_rappel_actif() ); ?>> <?php echo esc_html( $texts['rappel_case'] ); ?></label>
			<button type="submit" class="gacct-sec-btn gacct-sec-btn--ghost"><?php echo esc_html( $texts['enregistrer'] ); ?></button>
		</form>
	<?php endif; ?>
</section>
