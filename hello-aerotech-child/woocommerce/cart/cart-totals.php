<?php
/**
 * Totaux du panier — surcharge de woocommerce/cart/cart-totals.php
 *
 * Volontairement vide : le récapitulatif AEROTECH (code promo, totaux, franco
 * de port, CTA, réassurance) est rendu par la colonne de droite de
 * woocommerce/cart/cart.php via at_cart_render_totals(). Sans cette surcharge,
 * le hook `woocommerce_cart_collaterals` ajouterait en plus le tableau de
 * totaux par défaut de WooCommerce sous les ventes croisées.
 */

defined( 'ABSPATH' ) || exit;
