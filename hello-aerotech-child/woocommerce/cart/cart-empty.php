<?php
/**
 * Panier vide AEROTECH — surcharge de woocommerce/cart/cart-empty.php
 * Maquette : templates/cart/Cart.dc.html (handoff §6.5)
 * Ni ventes croisées ni bande de réassurance dans cet état.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="at-shop at-cart at-cart--empty">
	<div class="at-shop-wrap at-cart-wrap">

		<?php at_cart_crumb( 'Panier' ); ?>

		<div class="at-cart-head">
			<h1 class="at-h1">Votre <em>panier</em></h1>
			<?php at_steps( 1 ); ?>
		</div>

		<div class="at-card at-empty">
			<?php at_icon( 'shopping-cart', 52, 'at-empty-i' ); ?>
			<h2>Votre panier est vide</h2>
			<p>Voiles, sellettes, secours, casques et accessoires : notre boutique est ouverte toute l'année, et nos conseillers volent tous les jours au-dessus de Gréolières.</p>
			<div class="at-empty-btns">
				<a class="at-btn at-btn--primary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php at_icon( 'shopping-bag', 18 ); ?>Découvrir la boutique</a>
				<a class="at-btn at-btn--outline" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php at_icon( 'headset', 18 ); ?>Être conseillé</a>
			</div>
		</div>

	</div>
</div>
