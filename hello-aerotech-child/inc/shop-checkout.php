<?php
/**
 * AEROTECH — page Commande (checkout classique).
 * Maquette : templates/checkout/Checkout.dc.html (handoff §7)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ---------------------------------------------------------------------------
 * Icône du sprite selon la passerelle de paiement.
 * ------------------------------------------------------------------------- */
function at_payment_icon( $gateway_id ) {
	$map = array(
		'bacs'   => 'bank',
		'cheque' => 'note',
		'cod'    => 'store',
		'paypal' => 'credit-card',
	);
	foreach ( array( '3x' => 'calendar', 'oney' => 'calendar', 'alma' => 'calendar', 'pledg' => 'calendar' ) as $needle => $icon ) {
		if ( false !== strpos( $gateway_id, $needle ) ) { return $icon; }
	}
	return isset( $map[ $gateway_id ] ) ? $map[ $gateway_id ] : 'credit-card';
}

/* ---------------------------------------------------------------------------
 * Sommes-nous dans le tunnel de l'atelier (page 374 « Validation de la
 * commande ») plutôt que dans celui de la boutique (page 288) ? Les deux
 * passent par les mêmes gabarits : quelques blocs ne valent que pour l'un.
 * ------------------------------------------------------------------------- */
function at_is_atelier_checkout() {
	$page = (int) apply_filters( 'at_atelier_checkout_page_id', 374 );

	return $page && is_page( $page );
}

/* ---------------------------------------------------------------------------
 * Y a-t-il au moins un tarif de livraison proposé ?
 * ------------------------------------------------------------------------- */
function at_checkout_has_shipping_rates() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
		return false;
	}
	foreach ( WC()->shipping()->get_packages() as $package ) {
		if ( ! empty( $package['rates'] ) ) {
			return true;
		}
	}

	return false;
}

/* ---------------------------------------------------------------------------
 * Le client a-t-il un VRAI choix de livraison à faire ?
 *
 * Une méthode gratuite existe dans chaque zone pour la seule raison que
 * WooCommerce ne rend l'adresse de livraison (et la case « Livrer à une
 * adresse différente ») que si au moins une méthode est active. Dans le tunnel
 * de l'atelier, l'aller comme le retour du colis sont facturés par des
 * PRODUITS (« Colis X kg ») : tant qu'il n'y a qu'un seul tarif, il n'y a rien
 * à choisir et on n'encombre le tunnel ni d'une carte « Livraison » ni d'une
 * ligne dans le résumé. Dès qu'une deuxième méthode existe (c'est le cas de la
 * boutique : express, retrait atelier), les deux réapparaissent d'elles-mêmes.
 * ------------------------------------------------------------------------- */
function at_checkout_shipping_is_choice() {
	if ( ! at_checkout_has_shipping_rates() ) {
		return false;
	}
	foreach ( WC()->shipping()->get_packages() as $package ) {
		if ( count( $package['rates'] ) > 1 ) {
			return true;
		}
	}

	return false;
}

/* ---------------------------------------------------------------------------
 * Options de livraison en cartes-radio (handoff §7.2 point 3, §7.3).
 * Rendu séparé du résumé : il est rafraîchi par son propre fragment.
 * ------------------------------------------------------------------------- */
function at_checkout_shipping_options() {
	echo '<div class="at-co-shipwrap">';

	$packages = WC()->shipping()->get_packages();

	if ( ! $packages ) {
		echo '<p class="at-muted">Renseignez votre adresse pour voir les modes de livraison.</p></div>';
		return;
	}

	foreach ( $packages as $i => $package ) {
		$rates  = $package['rates'];
		$chosen = isset( WC()->session->chosen_shipping_methods[ $i ] ) ? WC()->session->chosen_shipping_methods[ $i ] : '';

		if ( ! $rates ) {
			echo '<p class="at-muted">' . wp_kses_post( apply_filters( 'woocommerce_no_shipping_available_html', __( 'There are no shipping options available. Please ensure that your address has been entered correctly, or contact us if you need any help.', 'woocommerce' ) ) ) . '</p>';
			continue;
		}

		echo '<ul class="at-radios at-ship-radios">';
		foreach ( $rates as $id => $rate ) {
			$cost   = (float) $rate->get_cost() + (float) $rate->get_shipping_tax();
			$pickup = 0 === strpos( $rate->get_method_id(), 'local_pickup' );
			$desc   = $pickup
				? 'Atelier de Vence, 489 route de Grasse, du mardi au samedi. Nous vous appelons dès que la commande est prête.'
				: ( 0 === strpos( $rate->get_method_id(), 'free_shipping' )
					? 'Colissimo suivi, 24 à 48 h après le contrôle atelier.'
					: 'Remise contre signature, livraison rapide en France métropolitaine.' );
			?>
			<li class="at-radio<?php echo $pickup ? ' at-radio--pickup' : ''; ?>">
				<input type="radio" name="shipping_method[<?php echo esc_attr( $i ); ?>]" data-index="<?php echo esc_attr( $i ); ?>" id="shipping_method_<?php echo esc_attr( $i . '_' . sanitize_title( $id ) ); ?>" value="<?php echo esc_attr( $id ); ?>" class="shipping_method" <?php checked( $rate->get_id(), $chosen ); ?> data-pickup="<?php echo $pickup ? '1' : '0'; ?>">
				<label class="at-radio-box" for="shipping_method_<?php echo esc_attr( $i . '_' . sanitize_title( $id ) ); ?>">
					<span class="at-radio-dot" aria-hidden="true"></span>
					<span class="at-radio-main">
						<span class="at-radio-title">
							<?php echo esc_html( $rate->get_label() ); ?>
							<b class="at-radio-price"><?php
								echo 0.0 === $cost
									? ( $pickup ? '<span class="at-free">Gratuit</span>' : '<span class="at-free">Offerte</span>' )
									: wp_kses_post( wc_price( $cost ) );
							?></b>
						</span>
						<span class="at-radio-desc at-radio-desc--always"><?php echo esc_html( $desc ); ?></span>
					</span>
				</label>
			</li>
			<?php
		}
		echo '</ul>';
	}
	echo '</div>';
}

/* ---------------------------------------------------------------------------
 * Rafraîchissement AJAX : les blocs sortis de review-order.php (options de
 * livraison, montant du bouton replié) ont besoin de leur propre fragment,
 * checkout.js remplaçant chaque sélecteur retourné ici.
 * ------------------------------------------------------------------------- */
add_filter( 'woocommerce_update_order_review_fragments', function ( $fragments ) {
	ob_start();
	at_checkout_shipping_options();
	$fragments['.at-co-shipwrap'] = ob_get_clean();

	$fragments['.at-co-sumtoggle b'] = '<b>' . wc_price( WC()->cart->get_total( 'edit' ) ) . '</b>';

	return $fragments;
} );

/* ---------------------------------------------------------------------------
 * Retrait à l'atelier : pas d'adresse de livraison à saisir.
 * (Le masquage visuel est fait par le JS ; ici on neutralise la validation.)
 * ------------------------------------------------------------------------- */
add_filter( 'woocommerce_ship_to_different_address_checked', function ( $checked ) {
	if ( at_checkout_is_pickup() ) { return 0; }
	return $checked;
} );

function at_checkout_is_pickup() {
	$chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();
	foreach ( (array) $chosen as $method ) {
		if ( 0 === strpos( (string) $method, 'local_pickup' ) ) { return true; }
	}
	return false;
}

/* Champs d'adresse de livraison non requis en retrait atelier. */
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	if ( ! at_checkout_is_pickup() || empty( $fields['shipping'] ) ) { return $fields; }
	foreach ( $fields['shipping'] as $key => $field ) {
		$fields['shipping'][ $key ]['required'] = false;
	}
	return $fields;
}, 20 );

/* ---------------------------------------------------------------------------
 * Newsletter cochée sur la commande : enregistrée avec la commande.
 * ------------------------------------------------------------------------- */
add_action( 'woocommerce_checkout_update_order_meta', function ( $order_id ) {
	// phpcs:ignore WordPress.Security.NonceVerification
	if ( ! empty( $_POST['at_newsletter'] ) ) {
		update_post_meta( $order_id, '_at_newsletter', 'yes' );
	}
} );

/* ---------------------------------------------------------------------------
 * Bouton « Commander · <montant> » (handoff §7.2 point 5).
 * ⚠️ Priorité 99 : un autre filtre du site (tunnel atelier) reconstruit le
 * bouton et écrasait le nôtre. Le montant suit le mode de livraison choisi,
 * le bloc paiement étant rafraîchi à chaque `update_checkout`.
 * ------------------------------------------------------------------------- */
add_filter( 'woocommerce_order_button_html', function ( $html ) {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! WC()->cart ) { return $html; }

	$label = esc_attr( apply_filters( 'woocommerce_order_button_text', __( 'Place order', 'woocommerce' ) ) );

	return '<button type="submit" class="button alt at-co-submit" name="woocommerce_checkout_place_order" id="place_order" value="' . $label . '" data-value="' . $label . '">'
		. at_icon_get( 'lock', 18 )
		. '<span class="at-co-submit-l">Commander</span>'
		. '<span class="at-co-submit-sep" aria-hidden="true">·</span>'
		. '<span class="at-co-submit-total">' . wc_price( WC()->cart->get_total( 'edit' ) ) . '</span>'
		. '</button>';
}, 99 );

/* ---------------------------------------------------------------------------
 * Mentions du pied de formulaire, en français (le texte WooCommerce par défaut
 * n'est pas traduit sur ce site).
 * ------------------------------------------------------------------------- */
add_filter( 'woocommerce_get_privacy_policy_text', function ( $text, $type ) {
	if ( 'checkout' !== $type ) { return $text; }
	$privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );
	$privacy    = ( $privacy_id && 'publish' === get_post_status( $privacy_id ) ) ? get_permalink( $privacy_id ) : home_url( '/mentions-legales/' );
	$terms   = wc_terms_and_conditions_page_id() ? get_permalink( wc_terms_and_conditions_page_id() ) : '';
	$cgv     = $terms ? ' et à nos <a href="' . esc_url( $terms ) . '">conditions générales de vente</a>' : '';

	return 'En passant commande, vous acceptez que vos données servent à traiter votre commande et votre suivi client, conformément à notre <a href="' . esc_url( $privacy ) . '">politique de confidentialité</a>' . $cgv . '.';
}, 10, 2 );

add_filter( 'woocommerce_get_terms_and_conditions_checkbox_text', function () {
	return 'J\'ai lu et j\'accepte les conditions générales de vente [terms]';
} );

/* ---------------------------------------------------------------------------
 * Accordéon code promo du résumé de commande (handoff §7.4 point 3) :
 * même composant que le panier, mais replié par défaut. L'application passe
 * par l'AJAX du panier (at_cart_coupon) puis un update_checkout.
 * ------------------------------------------------------------------------- */
function at_checkout_coupon_box() {
	$applied = WC()->cart ? WC()->cart->get_applied_coupons() : array();
	?>
	<div class="at-promo at-promo--checkout" data-open="<?php echo $applied ? '1' : '0'; ?>">
		<button type="button" class="at-promo-head" aria-expanded="<?php echo $applied ? 'true' : 'false'; ?>" aria-controls="at-promo-body-co">
			<span><?php at_icon( 'price-tag', 17 ); ?>Ajouter un code promo</span>
			<?php at_icon( 'chevron-down', 19, 'at-promo-chev' ); ?>
		</button>
		<div class="at-promo-body" id="at-promo-body-co">
			<div class="at-promo-row">
				<input type="text" class="at-promo-input" placeholder="Code promo" aria-label="Code promo" autocomplete="off">
				<button type="button" class="at-promo-apply">Appliquer</button>
			</div>
			<p class="at-promo-msg" role="status"></p>
			<?php if ( $applied ) : ?>
				<div class="at-promo-tags">
					<?php foreach ( $applied as $code ) : ?>
						<span class="at-promo-tag"><?php echo esc_html( strtoupper( $code ) ); ?>
							<button type="button" class="at-promo-rm" data-code="<?php echo esc_attr( $code ); ?>" aria-label="Retirer le code <?php echo esc_attr( $code ); ?>"><?php at_icon( 'close', 14 ); ?></button>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/* ---------------------------------------------------------------------------
 * Libellés des champs : le motif de label flottant suppose un label visible
 * pour chaque champ (WooCommerce laisse le complément d'adresse sans label,
 * uniquement avec un placeholder — invisible ici).
 * ------------------------------------------------------------------------- */
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	foreach ( array( 'billing', 'shipping' ) as $group ) {
		if ( isset( $fields[ $group ][ $group . '_address_2' ] ) ) {
			$fields[ $group ][ $group . '_address_2' ]['label']         = 'Complément d\'adresse';
			$fields[ $group ][ $group . '_address_2' ]['label_class']   = array();
			$fields[ $group ][ $group . '_address_2' ]['placeholder']   = '';
		}
		if ( isset( $fields[ $group ][ $group . '_address_1' ] ) ) {
			$fields[ $group ][ $group . '_address_1' ]['placeholder'] = '';
		}
		if ( isset( $fields[ $group ][ $group . '_phone' ] ) ) {
			$fields[ $group ][ $group . '_phone' ]['label'] = 'Téléphone (pour la livraison)';
		}
	}
	return $fields;
}, 30 );

/* ---------------------------------------------------------------------------
 * Normalisation des champs (labels flottants, astérisque, select2 du pays).
 * Fichier séparé pour ne pas toucher functions.php, écrit par ailleurs.
 * ------------------------------------------------------------------------- */
require_once get_stylesheet_directory() . '/inc/checkout-fields.php';
