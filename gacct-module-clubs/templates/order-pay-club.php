<?php
/**
 * Page de paiement de la commande à régler d'un club (/commander/order-pay/{id}/).
 *
 * Remplace `checkout/form-pay.php` natif pour une facture de lot (filtre
 * wc_get_template, cf. includes/billing.php). Même habillage que la page de
 * solde du socle (view-order.css) ; une ligne par pilote, remise, TVA.
 * WooCommerce fournit $order, $available_gateways et $order_button_text.
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

$lot  = gacct_clubs_get_lot( gacct_clubs_invoice_lot_id( $order ) );
$dec  = wc_get_price_decimals();
$fmt  = static function ( $amount ) use ( $dec ) {
	return number_format( round( (float) $amount, $dec ), $dec, ',', ' ' ) . ' €';
};
$club = ! empty( $lot['club']['nom'] ) ? $lot['club']['nom'] : ( $lot['nom'] ?? $order->get_billing_company() );

$lines = array();
$port  = array();
$fees  = array();
foreach ( $order->get_items( 'line_item' ) as $item ) {
	$parts = array_map( 'trim', explode( '·', $item->get_name(), 2 ) );
	$row   = array(
		'titre' => $parts[0],
		'sous'  => $parts[1] ?? '',
		'qty'   => (int) $item->get_quantity(),
		'total' => round( (float) $item->get_total() + (float) $item->get_total_tax(), $dec ),
	);
	if ( $item->get_meta( '_gacct_club_member_order' ) ) {
		$lines[] = $row;
	} else {
		$port[] = $row;
	}
}
foreach ( $order->get_items( 'fee' ) as $fee ) {
	$fees[] = array( 'titre' => $fee->get_name(), 'total' => round( (float) $fee->get_total() + (float) $fee->get_total_tax(), $dec ) );
}
$subtotal = 0;
foreach ( array_merge( $lines, $port ) as $r ) {
	$subtotal += $r['total'];
}
$total = (float) $order->get_total();
$nb    = count( $lines );
?>
<style>
	.gacct-clubpay .gacct-vo-table td { vertical-align: top; padding: 12px 0; }
	.gacct-clubpay .gacct-vo-table tbody tr + tr td { border-top: 1px solid #e6ecee; }
	.gacct-clubpay .gacct-vo-table td + td { padding-left: 16px; text-align: right; }
	.gacct-clubpay .gacct-vo-row-sep td { padding-top: 20px; }
	.gacct-clubpay-titre { display: block; font-weight: 600; }
	.gacct-clubpay-sous { display: block; color: var(--gacct-vo-muted, #5f7078); font-size: 16px; margin-top: 2px; }
	.gacct-clubpay .gacct-vo-amount { white-space: nowrap; }
	.gacct-clubpay .gacct-vo-paid td { font-size: 16px; }
	.gacct-clubpay-remise td { color: #1f7a4d; }
	.gacct-clubpay-tva { display: block; color: var(--gacct-vo-muted, #5f7078); font-size: 16px; font-weight: 400; }
</style>
<div class="gacct-vo gacct-pay gacct-clubpay">

	<div class="gacct-vo-card gacct-vo-head">
		<div class="gacct-vo-head-row">
			<div>
				<p class="gacct-vo-kicker">Commande groupée</p>
				<h2 class="gacct-vo-ref"><?php echo esc_html( $club ); ?></h2>
				<p class="gacct-vo-meta">
					<?php if ( ! empty( $lot['code'] ) ) : ?>Code <?php echo esc_html( $lot['code'] ); ?> · <?php endif; ?>
					<?php echo (int) $nb; ?> prestation<?php echo $nb > 1 ? 's' : ''; ?>
				</p>
			</div>
			<span class="gacct-vo-badge is-action">À régler</span>
		</div>
		<p>Voici le détail de la commande du club, pilote par pilote. Le matériel repart vers le club dès l’encaissement, avec le suivi du colis.</p>
	</div>

	<div class="gacct-vo-card">
		<h3>Détail par pilote</h3>
		<table class="gacct-vo-table">
			<tbody>
				<?php foreach ( $lines as $r ) : ?>
					<tr>
						<td>
							<span class="gacct-clubpay-titre"><?php echo esc_html( $r['titre'] ); ?><?php if ( $r['qty'] > 1 ) : ?> <span class="gacct-vo-qty">× <?php echo (int) $r['qty']; ?></span><?php endif; ?></span>
							<?php if ( $r['sous'] ) : ?><span class="gacct-clubpay-sous"><?php echo esc_html( $r['sous'] ); ?></span><?php endif; ?>
						</td>
						<td class="gacct-vo-amount"><?php echo esc_html( $fmt( $r['total'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( $port ) : ?>
					<tr class="gacct-vo-row-sep"><td colspan="2">Retour au club</td></tr>
					<?php foreach ( $port as $r ) : ?>
						<tr>
							<td>
								<span class="gacct-clubpay-titre"><?php echo esc_html( $r['titre'] ); ?></span>
								<?php if ( $r['sous'] ) : ?><span class="gacct-clubpay-sous"><?php echo esc_html( $r['sous'] ); ?></span><?php endif; ?>
							</td>
							<td class="gacct-vo-amount"><?php echo esc_html( $fmt( $r['total'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
			<tfoot>
				<?php if ( $fees ) : ?>
					<tr class="gacct-vo-paid">
						<td>Sous-total</td>
						<td class="gacct-vo-amount"><?php echo esc_html( $fmt( $subtotal ) ); ?></td>
					</tr>
					<?php foreach ( $fees as $f ) : ?>
						<tr class="gacct-clubpay-remise">
							<td><?php echo esc_html( $f['titre'] ); ?></td>
							<td class="gacct-vo-amount"><?php echo esc_html( ( $f['total'] < 0 ? '− ' : '' ) . $fmt( abs( $f['total'] ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				<tr class="gacct-vo-balance gacct-vo-balance-due">
					<td>Total à régler <span class="gacct-clubpay-tva">dont <?php echo esc_html( $fmt( $order->get_total_tax() ) ); ?> de TVA</span></td>
					<td class="gacct-vo-amount"><?php echo esc_html( $fmt( $total ) ); ?></td>
				</tr>
			</tfoot>
		</table>
	</div>

	<form id="order_review" method="post">
		<div class="gacct-vo-card">
			<h3>Comment souhaitez-vous régler ?</h3>

			<?php do_action( 'woocommerce_pay_order_before_payment' ); ?>

			<div id="payment">
				<?php if ( $order->needs_payment() ) : ?>
					<ul class="wc_payment_methods payment_methods methods gacct-vo-pay-methods" aria-label="Moyens de paiement">
						<?php
						if ( ! empty( $available_gateways ) ) {
							foreach ( $available_gateways as $gateway ) {
								wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
							}
						} else {
							echo '<li>';
							wc_print_notice( apply_filters( 'woocommerce_no_available_payment_methods_message', 'Aucun moyen de paiement n’est disponible pour le moment. Appelez-nous, nous trouverons une solution.' ), 'notice' );
							echo '</li>';
						}
						?>
					</ul>
				<?php endif; ?>

				<div class="form-row">
					<input type="hidden" name="woocommerce_pay" value="1" />
					<?php wc_get_template( 'checkout/terms.php' ); ?>
					<?php do_action( 'woocommerce_pay_order_before_submit' ); ?>
					<?php echo apply_filters( 'woocommerce_pay_order_button_html', '<button type="submit" class="button alt" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . ' · ' . esc_html( $fmt( $total ) ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php do_action( 'woocommerce_pay_order_after_submit' ); ?>
					<?php wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>
				</div>
			</div>
		</div>
	</form>
</div>
