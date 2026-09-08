<?php
/**
 * Liste « Mes commandes » de l'espace client (/mon-compte/commandes/).
 *
 * Inclus par le shortcode `[gacct_commandes]` (includes/gacct-orders.php).
 * Rendu DANS la zone de contenu du compte : pas de <html>. Aucune <table>
 * (le reset du kit Elementor pose des bordures sur les cellules) : tout est
 * en grille, comme la page profil.
 *
 * Variables : $rows (gacct_orders_rows()), $texts (gacct_orders_texts()).
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

$t = static function ( $key ) use ( $texts ) {
	return isset( $texts[ $key ] ) ? $texts[ $key ] : '';
};
?>
<div class="gacct-orders">

	<?php if ( empty( $rows ) ) : ?>
		<section class="gacct-orders-card gacct-orders-empty">
			<h3><?php echo esc_html( $t( 'vide_titre' ) ); ?></h3>
			<p><?php echo esc_html( $t( 'vide_texte' ) ); ?></p>
			<a class="gacct-orders-btn" href="<?php echo esc_url( home_url( '/demande-intervention/' ) ); ?>"><?php echo esc_html( $t( 'vide_cta' ) ); ?></a>
		</section>
	<?php else : ?>
		<p class="gacct-orders-intro"><?php echo esc_html( $t( 'intro' ) ); ?></p>

		<div class="gacct-orders-list" role="list">
			<div class="gacct-orders-head" aria-hidden="true">
				<span><?php echo esc_html( $t( 'col_commande' ) ); ?></span>
				<span><?php echo esc_html( $t( 'col_date' ) ); ?></span>
				<span><?php echo esc_html( $t( 'col_materiel' ) ); ?></span>
				<span><?php echo esc_html( $t( 'col_etat' ) ); ?></span>
				<span><?php echo esc_html( $t( 'col_total' ) ); ?></span>
				<span></span>
			</div>

			<?php foreach ( $rows as $row ) : ?>
				<a class="gacct-orders-row gacct-orders-row--<?php echo esc_attr( $row['state_key'] ); ?>" role="listitem" href="<?php echo esc_url( $row['url'] ); ?>">
					<span class="gacct-orders-ref">
						<span class="gacct-orders-label"><?php echo esc_html( $t( 'col_commande' ) ); ?></span>
						<strong><?php echo esc_html( $row['reference'] ); ?></strong>
						<?php if ( $row['items'] ) : ?>
							<small><?php echo esc_html( $row['items'] . ' ' . $t( 'articles' ) ); ?></small>
						<?php endif; ?>
					</span>
					<span class="gacct-orders-date">
						<span class="gacct-orders-label"><?php echo esc_html( $t( 'col_date' ) ); ?></span>
						<?php echo esc_html( $row['date'] ); ?>
					</span>
					<span class="gacct-orders-materiel">
						<span class="gacct-orders-label"><?php echo esc_html( $t( 'col_materiel' ) ); ?></span>
						<?php echo esc_html( $row['materiel'] ? $row['materiel'] : '–' ); ?>
					</span>
					<span class="gacct-orders-state">
						<span class="gacct-orders-label"><?php echo esc_html( $t( 'col_etat' ) ); ?></span>
						<span class="gacct-orders-badge"><?php echo esc_html( $row['state_txt'] ); ?></span>
					</span>
					<span class="gacct-orders-total">
						<span class="gacct-orders-label"><?php echo esc_html( $t( 'col_total' ) ); ?></span>
						<strong><?php echo esc_html( gacct_orders_amount( $row['total'] ) ); ?></strong>
						<?php if ( 'dead' !== $row['state_key'] ) : ?>
							<small>
								<?php
								echo $row['balance'] > 0.005
									? esc_html( gacct_orders_amount( $row['balance'] ) . ' ' . $t( 'reste' ) )
									: esc_html( $t( 'paye' ) );
								?>
							</small>
						<?php endif; ?>
					</span>
					<span class="gacct-orders-go" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
						<span class="gacct-orders-go-txt"><?php echo esc_html( $t( 'voir' ) ); ?></span>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
