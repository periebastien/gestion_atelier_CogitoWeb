<?php
/**
 * Résumé de commande AEROTECH — surcharge de woocommerce/checkout/review-order.php
 * Maquette : templates/checkout/Checkout.dc.html (handoff §7.4)
 *
 * Le markup garde la table .woocommerce-checkout-review-order-table : c'est le
 * sélecteur que checkout.js remplace à chaque `update_checkout`. La table est
 * mise en page en grille par at-cart.css (aucun rendu tabulaire visible).
 */

defined( 'ABSPATH' ) || exit;

$lines = count( WC()->cart->get_cart() );
?>
<table class="shop_table woocommerce-checkout-review-order-table at-rev">
	<tbody class="at-rev-items<?php echo $lines > 4 ? ' is-collapsed' : ''; ?>">
		<?php
		do_action( 'woocommerce_review_order_before_cart_contents' );

		$i = 0;
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
			if ( ! $_product || ! $_product->exists() || $cart_item['quantity'] <= 0 || ! apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
				continue;
			}
			$i++;
			$parent = $_product->get_parent_id() ? wc_get_product( $_product->get_parent_id() ) : null;
			$name   = $parent ? $parent->get_name() : $_product->get_name();

			$bits = array();
			if ( ! empty( $cart_item['variation'] ) ) {
				foreach ( $cart_item['variation'] as $tax => $value ) {
					if ( '' === $value ) { continue; }
					$taxonomy = str_replace( 'attribute_', '', $tax );
					$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : null;
					$bits[]   = wc_attribute_label( $taxonomy, $_product ) . ' ' . ( $term ? $term->name : $value );
				}
			}
			?>
			<tr class="at-rev-row<?php echo $i > 4 ? ' at-rev-row--extra' : ''; ?>">
				<?php /* Pas de vignette : les prestations d'atelier n'ont pas d'image
				         produit, la miniature n'affichait qu'un cadre vide. Il ne
				         reste que la pastille de quantité. */ ?>
				<td class="at-rev-media">
					<span class="at-rev-qty"><?php echo esc_html( $cart_item['quantity'] ); ?></span>
				</td>
				<td class="at-rev-body">
					<b><?php echo esc_html( $name ); ?></b>
					<?php if ( $bits ) : ?><span><?php echo esc_html( implode( ' · ', $bits ) ); ?></span><?php endif; ?>
				</td>
				<td class="at-rev-price">
					<?php
					/* Prix REEL de la ligne, pas l'acompte : le plugin d'acompte ramene
					   le prix du panier au montant du premier versement, ce qui donnait
					   un recapitulatif irrealiste (des lignes a 0 EUR, un total sans
					   rapport avec la commande). Ce que le client regle aujourd'hui est
					   detaille dans le pied du tableau. */
					echo class_exists( 'Kojito_Acompte_Produit' )
						? wp_kses_post( wc_price( Kojito_Acompte_Produit::prix_catalogue_ligne( $cart_item ) ) )
						: wp_kses_post( apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ) );
					?>
				</td>
			</tr>
			<?php
		}

		do_action( 'woocommerce_review_order_after_cart_contents' );
		?>
		<?php if ( $lines > 4 ) : ?>
			<tr class="at-rev-more">
				<td colspan="3">
					<button type="button" class="at-rev-morebtn">Voir les <?php echo esc_html( $lines ); ?> articles<?php at_icon( 'chevron-down', 17 ); ?></button>
				</td>
			</tr>
		<?php endif; ?>
	</tbody>

	<tfoot class="at-rev-foot">
		<tr class="at-rev-sub">
			<th colspan="2">Sous-total</th>
			<td><?php
				echo class_exists( 'Kojito_Acompte_Produit' )
					? wp_kses_post( wc_price( Kojito_Acompte_Produit::sous_total_catalogue_panier() ) )
					: wc_cart_totals_subtotal_html();
			?></td>
		</tr>

		<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
			<tr class="cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?> at-rev-discount">
				<th colspan="2"><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
				<td><?php wc_cart_totals_coupon_html( $coupon ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php /* Ligne « Livraison » : masquée tant que la seule méthode est celle,
		         gratuite, qui débloque l'adresse de livraison (cf.
		         at_checkout_shipping_is_choice()). */ ?>
		<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() && at_checkout_shipping_is_choice() ) : ?>
			<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>
			<?php foreach ( WC()->shipping()->get_packages() as $i => $package ) : ?>
				<?php
				$chosen  = isset( WC()->session->chosen_shipping_methods[ $i ] ) ? WC()->session->chosen_shipping_methods[ $i ] : '';
				$rates   = $package['rates'];
				$rate    = isset( $rates[ $chosen ] ) ? $rates[ $chosen ] : null;
				?>
				<tr class="at-rev-ship">
					<th colspan="2"><?php echo $rate ? esc_html( $rate->get_label() ) : 'Livraison'; ?></th>
					<td>
						<?php
						if ( $rate ) {
							$pickup = 0 === strpos( $rate->get_method_id(), 'local_pickup' );
							echo 0.0 === (float) $rate->get_cost()
								? '<b class="at-free">' . ( $pickup ? 'Gratuit' : 'Offerte' ) . '</b>'
								: wp_kses_post( wc_price( (float) $rate->get_cost() + (float) $rate->get_shipping_tax() ) );
						} else {
							echo '<span class="at-muted">À choisir</span>';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>
		<?php endif; ?>

		<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
			<tr class="fee at-rev-fee">
				<th colspan="2"><?php echo esc_html( $fee->name ); ?></th>
				<td><?php wc_cart_totals_fee_html( $fee ); ?></td>
			</tr>
		<?php endforeach; ?>

		<tr class="at-rev-tax">
			<th colspan="2">Dont TVA 20 %</th>
			<td><?php
				/* TVA du total reel, pas celle de l'acompte. */
				echo class_exists( 'Kojito_Acompte_Produit' )
					? wp_kses_post( wc_price( Kojito_Acompte_Produit::total_catalogue_panier() - Kojito_Acompte_Produit::total_catalogue_panier( true ) ) )
					: wp_kses_post( wc_price( WC()->cart->get_total_tax() ) );
			?></td>
		</tr>

		<?php do_action( 'woocommerce_review_order_before_order_total' ); ?>
		<tr class="order-total at-rev-total">
			<th colspan="2">Total</th>
			<td><?php
				echo class_exists( 'Kojito_Acompte_Produit' )
					? wp_kses_post( wc_price( Kojito_Acompte_Produit::total_catalogue_panier() ) )
					: wp_kses_post( wc_price( WC()->cart->get_total( 'edit' ) ) );
			?></td>
		</tr>

		<?php
		/* Paiement en deux temps : on distingue ce qui est du aujourd'hui de ce
		   qui sera facture apres l'intervention. Meme decoupage que la boite de
		   totaux du formulaire de demande, pour que le client retrouve ses
		   reperes d'une etape a l'autre. */
		if ( class_exists( 'Kojito_Acompte_Produit' ) && Kojito_Acompte_Produit::solde_panier() > 0 ) :
			?>
			<tr class="at-rev-acompte">
				<th colspan="2">Acompte à payer aujourd'hui</th>
				<td><?php echo wp_kses_post( wc_price( Kojito_Acompte_Produit::acompte_panier() ) ); ?></td>
			</tr>
			<tr class="at-rev-solde">
				<th colspan="2">Solde après l'intervention</th>
				<td><?php echo wp_kses_post( wc_price( Kojito_Acompte_Produit::solde_panier() ) ); ?></td>
			</tr>
			<tr class="at-rev-note">
				<td colspan="3">Le solde ne vous sera demandé qu'une fois l'intervention terminée.</td>
			</tr>
		<?php endif; ?>
		<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>
	</tfoot>
</table>
