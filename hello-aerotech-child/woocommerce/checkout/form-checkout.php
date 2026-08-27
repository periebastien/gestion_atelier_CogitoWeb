<?php
/**
 * Commande AEROTECH — surcharge de woocommerce/checkout/form-checkout.php
 * Maquette : templates/checkout/Checkout.dc.html (handoff §7)
 *
 * Checkout une page, deux colonnes : formulaire à gauche, résumé collant à
 * droite. Sous 1024 px le résumé passe au-dessus, replié en accordéon.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_checkout_form', $checkout );

if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo '<div class="at-shop at-co"><div class="at-shop-wrap">';
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	echo '</div></div>';
	return;
}
?>
<div class="at-shop at-co">
	<div class="at-shop-wrap at-co-wrap">

		<div class="at-co-top">
			<?php if ( at_is_atelier_checkout() ) : ?>
				<a class="at-back" href="<?php echo esc_url( home_url( '/demande-intervention/' ) ); ?>"><?php at_icon( 'arrow-left', 17 ); ?>Modifier ma demande</a>
			<?php else : ?>
				<a class="at-back" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php at_icon( 'arrow-left', 17 ); ?>Retour au panier</a>
			<?php endif; ?>
		</div>

		<div class="at-cart-head">
			<h1 class="at-h1">Votre <em>commande</em></h1>
			<?php at_steps( 2 ); ?>
		</div>

		<p class="at-live" role="status" aria-live="polite"></p>

		<form name="checkout" method="post" class="checkout woocommerce-checkout at-co-form" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data">

			<div class="at-co-grid">

				<div class="at-co-main">

					<?php if ( $checkout->get_checkout_fields() ) : ?>

						<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

						<section class="at-card at-co-card" aria-label="Coordonnées">
							<div class="at-co-card-head">
								<h2>Coordonnées</h2>
								<?php if ( ! is_user_logged_in() && $checkout->is_registration_enabled() ) : ?>
									<span class="at-co-login">Déjà client ? <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">Se connecter</a></span>
								<?php endif; ?>
							</div>

							<div class="at-co-fields">
								<?php
								// l'e-mail vit dans les champs de facturation : on le sort ici
								$billing = $checkout->get_checkout_fields( 'billing' );
								if ( isset( $billing['billing_email'] ) ) {
									woocommerce_form_field( 'billing_email', $billing['billing_email'], $checkout->get_value( 'billing_email' ) );
									unset( $billing['billing_email'] );
								}
								?>
							</div>

							<?php /* Newsletter boutique : hors sujet dans le tunnel de l'atelier. */ ?>
							<?php if ( ! at_is_atelier_checkout() ) : ?>
								<label class="at-check">
									<input type="checkbox" name="at_newsletter" value="1">
									<span>Recevoir nos actualités vol, stages et arrivages boutique</span>
								</label>
							<?php endif; ?>
						</section>

						<section class="at-card at-co-card" aria-label="Adresse de facturation et de livraison">
							<div class="at-co-card-head"><h2>Adresse de facturation et de livraison</h2></div>
							<div class="at-co-fields">
								<?php
								foreach ( $billing as $key => $field ) {
									woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
								}
								?>
							</div>

							<?php if ( WC()->cart->needs_shipping_address() ) : ?>
								<label class="at-check at-co-shipdiff">
									<input id="ship-to-different-address-checkbox" class="woocommerce-form__input-checkbox" type="checkbox" name="ship_to_different_address" value="1" <?php checked( apply_filters( 'woocommerce_ship_to_different_address_checked', 'shipping' === get_option( 'woocommerce_ship_to_destination' ) ? 1 : 0 ), 1 ); ?>>
									<span>Livrer à une adresse différente</span>
								</label>
								<div class="at-co-fields at-co-shipfields">
									<?php
									foreach ( $checkout->get_checkout_fields( 'shipping' ) as $key => $field ) {
										woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
									}
									?>
								</div>
							<?php endif; ?>
						</section>

						<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

					<?php endif; ?>

					<?php /* Une seule méthode active = rien à choisir : la carte n'apparaît
					         pas (cf. at_checkout_shipping_is_choice()). */ ?>
					<?php if ( at_checkout_shipping_is_choice() ) : ?>
						<section class="at-card at-co-card at-co-ship" aria-label="Livraison">
							<div class="at-co-card-head"><h2>Livraison</h2></div>
							<?php at_checkout_shipping_options(); ?>
						</section>
					<?php endif; ?>

					<section class="at-card at-co-card at-co-pay" aria-label="Paiement">
						<div class="at-co-card-head"><h2>Options de paiement</h2></div>
						<?php
						/* Les deux blocs sont rendus séparément (paiement à gauche, résumé à
						   droite) : checkout.js les rafraîchit par leurs sélecteurs
						   (.woocommerce-checkout-review-order-table et .woocommerce-checkout-payment),
						   leur position dans le DOM est libre. */
						woocommerce_checkout_payment();
						?>
					</section>

				</div>

				<aside class="at-co-side" aria-label="Résumé de la commande">
					<button type="button" class="at-co-sumtoggle" aria-expanded="false" aria-controls="at-co-sum">
						<span>Voir le récapitulatif</span>
						<b><?php echo wp_kses_post( wc_price( WC()->cart->get_total( 'edit' ) ) ); ?></b>
						<?php at_icon( 'chevron-down', 19, 'at-co-sumchev' ); ?>
					</button>
					<div class="at-card at-sum at-co-sum" id="at-co-sum">
						<h2 class="at-sum-t">Résumé de la commande</h2>
						<div class="at-co-review"><?php woocommerce_order_review(); ?></div>
						<?php if ( wc_coupons_enabled() ) : ?>
							<?php at_checkout_coupon_box(); ?>
						<?php endif; ?>
					</div>
					<div class="at-callout at-co-callout">
						<?php at_icon( 'tools', 22 ); ?>
						<div>
							<b>Contrôle atelier avant expédition</b>
							<p>Votre matériel est vérifié à Vence avant l'envoi : suspentage, tissu, réglages.</p>
						</div>
					</div>
					<ul class="at-reassure-list at-co-reassure">
						<li><?php at_icon( 'shield-check', 17 ); ?><span>Paiement 3D Secure, aucune donnée bancaire stockée</span></li>
						<li><?php at_icon( 'rotate-left', 17 ); ?><span>Retour sous 14 jours si le matériel n'a pas volé</span></li>
						<li><?php at_icon( 'headset', 17 ); ?><span>Une question ? 06 20 89 91 31, du mardi au samedi</span></li>
					</ul>
				</aside>

			</div>
		</form>
	</div>
</div>
<?php
do_action( 'woocommerce_after_checkout_form', $checkout );
