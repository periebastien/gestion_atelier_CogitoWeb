<?php
/**
 * Gabarit PDF de la facture d'acompte (même mise en page que la facture « Simple »).
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

$fa     = gacct_fa_donnees( $this->order );
// Centimes toujours affichés : la boutique arrondit à l'euro, pas une facture (HT et TVA).
$devise = array( 'currency' => $this->order->get_currency(), 'decimals' => 2, 'decimal_separator' => ',', 'thousand_separator' => ' ' );
$prix   = function ( $montant ) use ( $devise ) {
	return wc_price( $montant, $devise );
};
?>
<?php do_action( 'wpo_wcpdf_before_document', $this->get_type(), $this->order ); ?>

<table class="head container">
	<tr>
		<td class="header">
		<?php
		if ( $this->has_header_logo() ) {
			$this->header_logo();
		} else {
			$this->title();
		}
		?>
		</td>
		<td class="shop-info">
			<div class="shop-name"><h3><?php $this->shop_name(); ?></h3></div>
			<div class="shop-address"><?php $this->shop_address(); ?></div>
		</td>
	</tr>
</table>

<?php if ( $this->has_header_logo() ) : ?>
	<h1 class="document-type-label"><?php $this->title(); ?></h1>
<?php endif; ?>

<table class="order-data-addresses">
	<tr>
		<td class="address billing-address">
			<p><?php $this->billing_address(); ?></p>
			<?php if ( isset( $this->settings['display_email'] ) ) : ?>
				<div class="billing-email"><?php $this->billing_email(); ?></div>
			<?php endif; ?>
			<?php if ( isset( $this->settings['display_phone'] ) ) : ?>
				<div class="billing-phone"><?php $this->billing_phone(); ?></div>
			<?php endif; ?>
		</td>
		<td class="address shipping-address"></td>
		<td class="order-data">
			<table>
				<tr class="invoice-number">
					<th><?php echo esc_html( $this->get_number_title() ); ?></th>
					<td><?php echo esc_html( $this->get_number() ? $this->get_number()->get_formatted() : '' ); ?></td>
				</tr>
				<tr class="invoice-date">
					<th><?php echo esc_html( $this->get_date_title() ); ?></th>
					<td><?php echo esc_html( $this->get_date() ? $this->get_date()->date_i18n( 'd/m/Y' ) : '' ); ?></td>
				</tr>
				<tr class="order-number">
					<th><?php esc_html_e( 'Numéro de commande :', 'gestion-atelier-cct' ); ?></th>
					<td><?php echo esc_html( $this->order->get_order_number() ); ?></td>
				</tr>
				<tr class="order-date">
					<th><?php esc_html_e( 'Date de commande :', 'gestion-atelier-cct' ); ?></th>
					<td><?php echo esc_html( $this->order->get_date_created() ? $this->order->get_date_created()->date_i18n( 'd/m/Y' ) : '' ); ?></td>
				</tr>
				<?php if ( '' !== $fa['date'] ) : ?>
					<tr class="payment-date">
						<th><?php esc_html_e( 'Acompte réglé le :', 'gestion-atelier-cct' ); ?></th>
						<td><?php echo esc_html( $fa['date'] ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( '' !== $fa['methode'] ) : ?>
					<tr class="payment-method">
						<th><?php esc_html_e( 'Moyen de paiement :', 'gestion-atelier-cct' ); ?></th>
						<td><?php echo esc_html( $fa['methode'] ); ?></td>
					</tr>
				<?php endif; ?>
			</table>
		</td>
	</tr>
</table>

<table class="order-details">
	<thead>
		<tr>
			<th class="product"><?php esc_html_e( 'Prestation réservée', 'gestion-atelier-cct' ); ?></th>
			<th class="quantity"><?php esc_html_e( 'Qté', 'gestion-atelier-cct' ); ?></th>
			<th class="price"><?php esc_html_e( 'Prix TTC', 'gestion-atelier-cct' ); ?></th>
			<th class="price"><?php esc_html_e( 'Acompte TTC', 'gestion-atelier-cct' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $fa['lignes'] as $ligne ) : ?>
			<tr>
				<td class="product">
					<p class="item-name"><?php echo esc_html( $ligne['nom'] ); ?></p>
					<?php if ( $ligne['meta'] ) : ?>
						<div class="item-meta"><?php echo wp_kses_post( $ligne['meta'] ); ?></div>
					<?php endif; ?>
				</td>
				<td class="quantity"><?php echo esc_html( $ligne['quantite'] ); ?></td>
				<td class="price"><?php echo wp_kses_post( $prix( $ligne['prix'] ) ); ?></td>
				<td class="price"><?php echo wp_kses_post( $prix( $ligne['acompte'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<table class="notes-totals">
	<tbody>
		<tr class="no-borders">
			<td class="no-borders notes-cell">
				<div class="customer-notes">
					<p>
					<?php
					/* translators: %s: numéro de commande */
					printf( esc_html__( 'Facture d’acompte à valoir sur la facture définitive de la commande %s, émise à la fin de l’intervention.', 'gestion-atelier-cct' ), esc_html( $this->order->get_order_number() ) );
					?>
					</p>
				</div>
			</td>
			<td class="no-borders totals-cell">
				<table class="totals">
					<tfoot>
						<tr class="cart_subtotal">
							<th class="description"><?php esc_html_e( 'Acompte HT', 'gestion-atelier-cct' ); ?></th>
							<td class="price"><span class="totals-price"><?php echo wp_kses_post( $prix( $fa['ht'] ) ); ?></span></td>
						</tr>
						<tr class="tax">
							<th class="description">
							<?php
							/* translators: %s: taux de TVA */
							printf( esc_html__( 'TVA %s %%', 'gestion-atelier-cct' ), esc_html( wc_format_localized_decimal( $fa['taux'] + 0 ) ) );
							?>
							</th>
							<td class="price"><span class="totals-price"><?php echo wp_kses_post( $prix( $fa['tva'] ) ); ?></span></td>
						</tr>
						<tr class="order_total">
							<th class="description"><?php esc_html_e( 'Acompte TTC', 'gestion-atelier-cct' ); ?></th>
							<td class="price"><span class="totals-price"><?php echo wp_kses_post( $prix( $fa['ttc'] ) ); ?></span></td>
						</tr>
						<tr class="gacct_acompte">
							<th class="description">
							<?php
							echo esc_html( trim( '' !== $fa['date']
								/* translators: 1: date, 2: mode de paiement */
								? sprintf( __( 'Réglé le %1$s %2$s', 'gestion-atelier-cct' ), $fa['date'], $fa['mode'] )
								: __( 'Réglé', 'gestion-atelier-cct' ) . ' ' . $fa['mode'] ) );
							?>
							</th>
							<td class="price"><span class="totals-price"><?php echo wp_kses_post( $prix( $fa['ttc'] ) ); ?></span></td>
						</tr>
						<?php if ( $fa['prix'] > $fa['ttc'] && 'solde_paye' !== $this->order->get_meta( '_kojito_phase_paiement' ) ) : ?>
							<tr class="gacct_reste">
								<th class="description"><?php esc_html_e( 'Reste à régler à la fin de l’intervention (hors travaux complémentaires)', 'gestion-atelier-cct' ); ?></th>
								<td class="price"><span class="totals-price"><?php echo wp_kses_post( $prix( $fa['prix'] - $fa['ttc'] ) ); ?></span></td>
							</tr>
						<?php endif; ?>
					</tfoot>
				</table>
			</td>
		</tr>
	</tbody>
</table>

<div class="bottom-spacer"></div>

<?php if ( $this->get_footer() ) : ?>
	<htmlpagefooter name="docFooter">
		<div id="footer">
			<?php $this->footer(); ?>
		</div>
	</htmlpagefooter>
<?php endif; ?>

<?php do_action( 'wpo_wcpdf_after_document', $this->get_type(), $this->order ); ?>
