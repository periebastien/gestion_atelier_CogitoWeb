<?php
/**
 * Paiement AEROTECH — surcharge de woocommerce/checkout/payment.php
 * Maquette : templates/checkout/Checkout.dc.html (handoff §7.2 point 4, §7.3)
 *
 * Écart principal avec WooCommerce : la description ne s'affiche QUE sur le
 * moyen de paiement sélectionné (les autres sont masquées en CSS, pas cachées
 * du DOM, pour rester accessibles au lecteur d'écran une fois cochées).
 * Le bouton « Commander · montant » et les mentions CGV suivent les options,
 * hors carte, conformément à la maquette.
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_ajax() ) {
	do_action( 'woocommerce_review_order_before_payment' );
}
?>
<div id="payment" class="woocommerce-checkout-payment at-pay">
	<?php if ( WC()->cart->needs_payment() ) : ?>
		<ul class="wc_payment_methods payment_methods methods at-radios">
			<?php
			if ( ! WC()->payment_gateways()->get_available_payment_gateways() ) {
				echo '<li class="woocommerce-notice woocommerce-notice--info woocommerce-info at-pay-none">' . wp_kses_post( apply_filters( 'woocommerce_no_available_payment_methods_message', WC()->customer->get_billing_country() ? esc_html__( 'Sorry, it seems that there are no available payment methods for your location. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce' ) : esc_html__( 'Please fill in your details above to see available payment methods.', 'woocommerce' ) ) ) . '</li>';
			} else {
				foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
					$icon = at_payment_icon( $gateway->id );
					?>
					<li class="wc_payment_method payment_method_<?php echo esc_attr( $gateway->id ); ?> at-radio">
						<input id="payment_method_<?php echo esc_attr( $gateway->id ); ?>" type="radio" class="input-radio" name="payment_method" value="<?php echo esc_attr( $gateway->id ); ?>" <?php checked( $gateway->chosen, true ); ?> data-order_button_text="<?php echo esc_attr( $gateway->order_button_text ); ?>">
						<label class="at-radio-box" for="payment_method_<?php echo esc_attr( $gateway->id ); ?>">
							<span class="at-radio-dot" aria-hidden="true"></span>
							<span class="at-radio-main">
								<span class="at-radio-title">
									<?php at_icon( $icon, 19, 'at-radio-i' ); ?>
									<?php echo wp_kses_post( $gateway->get_title() ); ?>
								</span>
								<?php if ( $gateway->get_description() ) : ?>
									<span class="at-radio-desc"><?php echo wp_kses_post( wpautop( wptexturize( $gateway->get_description() ) ) ); ?></span>
								<?php endif; ?>
							</span>
						</label>
						<?php if ( $gateway->has_fields() ) : ?>
							<div class="payment_box payment_method_<?php echo esc_attr( $gateway->id ); ?> at-radio-fields">
								<?php $gateway->payment_fields(); ?>
							</div>
						<?php endif; ?>
					</li>
					<?php
				}
			}
			?>
		</ul>
	<?php endif; ?>

	<label class="at-check at-co-note-toggle">
		<input type="checkbox" id="at-co-note-check">
		<span><?php at_icon( 'note', 17 ); ?>Ajouter une note à votre commande</span>
	</label>
	<div class="at-co-note" hidden>
		<?php
		$fields = WC()->checkout()->get_checkout_fields( 'order' );
		if ( isset( $fields['order_comments'] ) ) {
			$fields['order_comments']['label']       = 'Note de commande';
			$fields['order_comments']['placeholder'] = 'Instructions de livraison, réglages souhaités à l\'atelier…';
			woocommerce_form_field( 'order_comments', $fields['order_comments'], WC()->checkout()->get_value( 'order_comments' ) );
		}
		?>
	</div>

	<div class="form-row place-order at-co-place">
		<div class="at-co-terms">
			<?php do_action( 'woocommerce_checkout_terms_and_conditions' ); ?>
		</div>

		<noscript>
			<?php esc_html_e( 'Since your browser does not support JavaScript, or it is disabled, please ensure you click the Update Totals button before placing your order.', 'woocommerce' ); ?>
			<br><button type="submit" class="button alt" name="woocommerce_checkout_update_totals" value="<?php esc_attr_e( 'Update totals', 'woocommerce' ); ?>"><?php esc_html_e( 'Update totals', 'woocommerce' ); ?></button>
		</noscript>

		<?php do_action( 'woocommerce_review_order_before_submit' ); ?>

		<?php
		echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput
			'woocommerce_order_button_html',
			'<button type="submit" class="button alt at-co-submit" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">'
				. at_icon_get( 'lock', 18 )
				. '<span class="at-co-submit-l">Commander</span>'
				. '<span class="at-co-submit-sep" aria-hidden="true">·</span>'
				. '<span class="at-co-submit-total">' . wp_kses_post( wc_price( WC()->cart->get_total( 'edit' ) ) ) . '</span>'
			. '</button>'
		);
		?>

		<?php do_action( 'woocommerce_review_order_after_submit' ); ?>

		<span class="at-co-secure">Paiement sécurisé : aucune donnée bancaire n'est stockée par AEROTECH.</span>

		<?php wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce' ); ?>
	</div>
</div>
<?php
if ( ! is_ajax() ) {
	do_action( 'woocommerce_review_order_after_payment' );
}
