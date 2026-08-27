<?php
/**
 * Panier AEROTECH — surcharge de woocommerce/cart/cart.php
 * Maquette : templates/cart/Cart.dc.html (handoff §6)
 *
 * Pas de bouton « Mettre à jour le panier » : quantités, retrait et code promo
 * passent en AJAX (assets/at-cart.js → inc/shop-cart.php).
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_cart' );
?>
<div class="at-shop at-cart">
	<div class="at-shop-wrap at-cart-wrap">

		<?php at_cart_crumb( 'Panier' ); ?>

		<div class="at-cart-head">
			<h1 class="at-h1">Votre <em>panier</em></h1>
			<?php at_steps( 1 ); ?>
		</div>

		<p class="at-live" role="status" aria-live="polite"></p>

		<div class="at-cart-grid">
			<section class="at-cart-main" aria-label="Articles du panier">
				<div class="at-card at-lines">
					<div class="at-lines-head">
						<b>Produit</b><b>Total</b>
					</div>
					<div class="at-lines-body">
						<?php
						do_action( 'woocommerce_before_cart_contents' );
						echo at_cart_render_lines(); // phpcs:ignore WordPress.Security.EscapeOutput
						do_action( 'woocommerce_cart_contents' );
						do_action( 'woocommerce_after_cart_contents' );
						?>
					</div>
					<div class="at-lines-foot">
						<a class="at-back" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php at_icon( 'arrow-left', 17 ); ?>Continuer mes achats</a>
						<span>Prix TTC · TVA 20 % incluse</span>
					</div>
				</div>

				<div class="at-callout">
					<?php at_icon( 'tools', 22 ); ?>
					<div>
						<b>Contrôle atelier avant expédition</b>
						<p>Chaque voile part de Vence avec une fiche de contrôle : mesure du suspentage, vérification du tissu et réglage de l'accélérateur. Comptez 24 à 48 h de préparation avant l'envoi.</p>
					</div>
				</div>
			</section>

			<aside class="at-card at-sum" aria-label="Total du panier">
				<?php echo at_cart_render_totals(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</aside>
		</div>

		<?php
		do_action( 'woocommerce_cart_collaterals' ); // ventes croisées (cross-sells.php)
		?>

		<section class="at-reassure" aria-label="Nos engagements">
			<div><?php at_icon( 'truck', 21 ); ?><div><b>Expédition 24/48 h</b><span>Offerte dès <?php echo wp_kses_post( wc_price( at_free_shipping_threshold() ) ); ?> en France métropolitaine.</span></div></div>
			<div><?php at_icon( 'shield-check', 21 ); ?><div><b>Paiement en 3×</b><span>Sans frais dès 300 € d'achat, sans dossier.</span></div></div>
			<div><?php at_icon( 'wing', 21 ); ?><div><b>Essai avant achat</b><span>Sur le site de Gréolières, sur rendez-vous.</span></div></div>
			<div><?php at_icon( 'headset', 21 ); ?><div><b>Conseil de pilotes</b><span>Au 06 20 89 91 31, du mardi au samedi.</span></div></div>
		</section>

	</div>
</div>
<?php
do_action( 'woocommerce_after_cart' );
