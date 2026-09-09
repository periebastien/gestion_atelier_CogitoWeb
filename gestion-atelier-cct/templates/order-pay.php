<?php
/**
 * Page de paiement du solde (/commander/order-pay/{id}/), phase « solde » Kojito.
 *
 * Remplace `checkout/form-pay.php` (filtre wc_get_template, cf.
 * includes/gacct-balance.php). WooCommerce fournit $order, $available_gateways
 * et $order_button_text. Habillage = détail de commande (view-order.css).
 * Les hooks WooCommerce du formulaire de paiement sont conservés (passerelles).
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

$d      = gacct_vo_data( $order );
$vo_fmt = static function ( $amount ) {
	return number_format( (float) $amount, 2, ',', ' ' ) . ' €';
};

$solde = (float) $order->get_meta( '_kojito_solde_restant' );
if ( $solde <= 0 ) {
	$solde = (float) $order->get_total();
}
?>
<div class="gacct-vo gacct-pay">

	<div class="gacct-vo-card gacct-vo-head">
		<div class="gacct-vo-head-row">
			<div>
				<p class="gacct-vo-kicker"><?php esc_html_e( 'Règlement du solde', 'gestion-atelier-cct' ); ?></p>
				<h2 class="gacct-vo-ref"><?php echo esc_html( $d['reference'] ); ?></h2>
				<p class="gacct-vo-meta">
					<?php if ( ! empty( $d['materiel'] ) ) : ?>
						<span class="gacct-vo-materiel"><?php echo esc_html( $d['materiel'] ); ?></span><br>
					<?php endif; ?>
					<?php if ( ! empty( $d['slot_label'] ) ) : ?>
						<?php printf( esc_html__( 'Intervention du %s', 'gestion-atelier-cct' ), esc_html( $d['slot_label'] ) ); ?>
					<?php endif; ?>
				</p>
			</div>
			<span class="gacct-vo-badge is-action"><?php esc_html_e( 'Solde à régler', 'gestion-atelier-cct' ); ?></span>
		</div>
		<p><?php esc_html_e( 'Votre intervention est terminée. Réglez le solde ci-dessous : votre matériel repart dès l’encaissement, avec le suivi du colis, et votre rapport de contrôle apparaît dans votre espace client.', 'gestion-atelier-cct' ); ?></p>
	</div>

	<div class="gacct-vo-card">
		<h3><?php esc_html_e( 'Détail de votre commande', 'gestion-atelier-cct' ); ?></h3>
		<table class="gacct-vo-table">
			<tbody>
				<?php foreach ( $d['initial_items'] as $item ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $item['name'] ); ?>
							<?php if ( $item['qty'] > 1 ) : ?><span class="gacct-vo-qty">× <?php echo (int) $item['qty']; ?></span><?php endif; ?>
						</td>
						<td class="gacct-vo-amount"><?php echo esc_html( $vo_fmt( $item['total'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! empty( $d['extra_items'] ) ) : ?>
					<tr class="gacct-vo-row-sep">
						<td colspan="2"><?php esc_html_e( 'Travaux complémentaires (devis accepté)', 'gestion-atelier-cct' ); ?></td>
					</tr>
					<?php foreach ( $d['extra_items'] as $item ) : ?>
						<tr class="gacct-vo-row-extra">
							<td>
								<?php echo esc_html( $item['name'] ); ?>
								<?php if ( $item['qty'] > 1 ) : ?><span class="gacct-vo-qty">× <?php echo (int) $item['qty']; ?></span><?php endif; ?>
							</td>
							<td class="gacct-vo-amount"><?php echo esc_html( $vo_fmt( $item['total'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
			<tfoot>
				<tr class="gacct-vo-total">
					<td><?php esc_html_e( 'Total de la commande', 'gestion-atelier-cct' ); ?></td>
					<td class="gacct-vo-amount"><?php echo esc_html( $vo_fmt( $d['total_initial'] ) ); ?></td>
				</tr>
				<tr class="gacct-vo-paid">
					<td><?php esc_html_e( 'Acompte déjà réglé', 'gestion-atelier-cct' ); ?></td>
					<td class="gacct-vo-amount">− <?php echo esc_html( $vo_fmt( $d['deposit'] ) ); ?></td>
				</tr>
				<tr class="gacct-vo-balance gacct-vo-balance-due">
					<td><?php esc_html_e( 'Solde à régler aujourd’hui', 'gestion-atelier-cct' ); ?></td>
					<td class="gacct-vo-amount"><?php echo esc_html( $vo_fmt( $solde ) ); ?></td>
				</tr>
			</tfoot>
		</table>
	</div>

	<form id="order_review" method="post">
		<div class="gacct-vo-card">
			<h3><?php esc_html_e( 'Comment souhaitez-vous régler ?', 'gestion-atelier-cct' ); ?></h3>

			<?php do_action( 'woocommerce_pay_order_before_payment' ); ?>

			<div id="payment">
				<?php if ( $order->needs_payment() ) : ?>
					<ul class="wc_payment_methods payment_methods methods gacct-vo-pay-methods" aria-label="<?php esc_attr_e( 'Moyens de paiement', 'gestion-atelier-cct' ); ?>">
						<?php
						if ( ! empty( $available_gateways ) ) {
							foreach ( $available_gateways as $gateway ) {
								wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
							}
						} else {
							echo '<li>';
							wc_print_notice( apply_filters( 'woocommerce_no_available_payment_methods_message', esc_html__( 'Aucun moyen de paiement n’est disponible pour le moment. Appelez-nous, nous trouverons une solution.', 'gestion-atelier-cct' ) ), 'notice' );
							echo '</li>';
						}
						?>
					</ul>
					<p class="gacct-vo-muted"><?php esc_html_e( 'Par virement : les coordonnées bancaires vous sont affichées et envoyées par e-mail après validation ; votre matériel repart dès réception.', 'gestion-atelier-cct' ); ?></p>
				<?php endif; ?>

				<div class="form-row">
					<input type="hidden" name="woocommerce_pay" value="1" />
					<?php wc_get_template( 'checkout/terms.php' ); ?>
					<?php do_action( 'woocommerce_pay_order_before_submit' ); ?>
					<?php echo apply_filters( 'woocommerce_pay_order_button_html', '<button type="submit" class="button alt" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . ' · ' . esc_html( $vo_fmt( $solde ) ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php do_action( 'woocommerce_pay_order_after_submit' ); ?>
					<?php wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>
					<p class="gacct-vo-secure"><?php printf( esc_html__( 'Paiement sécurisé, aucune donnée bancaire n’est stockée par %s.', 'gestion-atelier-cct' ), esc_html( get_bloginfo( 'name' ) ) ); ?></p>
				</div>
			</div>
		</div>
	</form>

	<p class="gacct-vo-help">
		<?php
		printf(
			esc_html__( 'Une question sur ce solde ? Appelez-nous au %1$s (%2$s).', 'gestion-atelier-cct' ),
			'<strong>' . esc_html( $d['contact_phone'] ) . '</strong>',
			esc_html( $d['contact_hours'] )
		);
		?>
	</p>
</div>
