<?php
/**
 * AEROTECH — panier & commande (tunnel WooCommerce classique).
 * Maquettes : templates/cart/Cart.dc.html et templates/checkout/Checkout.dc.html
 * Handoff   : handoff/prompt-claude-code-contact-panier-checkout.md §5 à §7
 *
 * Les gabarits sont dans woocommerce/cart/ et woocommerce/checkout/ ; ce fichier
 * porte les fonctions de rendu partagées (elles servent au premier rendu ET aux
 * réponses AJAX) et les points d'entrée AJAX.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ---------------------------------------------------------------------------
 * Icônes : sprite unique du design system, aucune police d'icônes.
 * ------------------------------------------------------------------------- */
function at_icon( $name, $size = 18, $class = '' ) {
	printf(
		'<svg class="at-i %s" width="%d" height="%d" aria-hidden="true" focusable="false"><use href="%s#%s"></use></svg>',
		esc_attr( $class ),
		(int) $size,
		(int) $size,
		esc_url( get_theme_file_uri( 'assets/icons.svg' ) ),
		esc_attr( $name )
	);
}
function at_icon_get( $name, $size = 18, $class = '' ) {
	ob_start();
	at_icon( $name, $size, $class );
	return ob_get_clean();
}

/* ---------------------------------------------------------------------------
 * Assets : le panier et la commande n'utilisent que le sprite → on retire
 * Remix Icon (chargé par functions.php pour les autres pages boutique).
 * ------------------------------------------------------------------------- */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'is_cart' ) || ! ( is_cart() || is_checkout() ) ) { return; }

	wp_dequeue_style( 'remixicon' );

	$css = get_stylesheet_directory() . '/assets/at-cart.css';
	$js  = get_stylesheet_directory() . '/assets/at-cart.js';
	wp_enqueue_style( 'at-cart', get_stylesheet_directory_uri() . '/assets/at-cart.css', array( 'at-shop' ), file_exists( $css ) ? filemtime( $css ) : '1' );
	wp_enqueue_script( 'at-cart', get_stylesheet_directory_uri() . '/assets/at-cart.js', array( 'jquery' ), file_exists( $js ) ? filemtime( $js ) : '1', true );
	wp_localize_script( 'at-cart', 'AT_CART', array(
		'ajax'  => admin_url( 'admin-ajax.php' ),
		'nonce'  => wp_create_nonce( 'at-cart' ),
		'sprite' => get_theme_file_uri( 'assets/icons.svg' ),
	) );
}, 25 );

/* ---------------------------------------------------------------------------
 * Franco de port : seuil filtrable côté PHP (jamais en dur dans le JS).
 * ------------------------------------------------------------------------- */
function at_free_shipping_threshold() {
	return (float) apply_filters( 'at_free_shipping_threshold', 500 );
}

/* ---------------------------------------------------------------------------
 * Indicateur d'étapes (panier / commande / confirmation).
 * ------------------------------------------------------------------------- */
function at_steps( $current = 1 ) {
	$steps = array( 'Panier', 'Livraison & paiement', 'Confirmation' );
	echo '<ol class="at-steps">';
	foreach ( $steps as $i => $label ) {
		$n     = $i + 1;
		$state = $n < $current ? 'done' : ( $n === $current ? 'current' : 'todo' );
		if ( $i ) {
			echo '<li class="at-steps-sep" aria-hidden="true">' . at_icon_get( 'chevron-right', 18 ) . '</li>';
		}
		printf(
			'<li class="at-step at-step--%s"%s><span class="at-step-n">%s</span>%s</li>',
			esc_attr( $state ),
			'current' === $state ? ' aria-current="step"' : '',
			'done' === $state ? at_icon_get( 'check', 14 ) : esc_html( $n ),
			esc_html( $label )
		);
	}
	echo '</ol>';
}

/* ---------------------------------------------------------------------------
 * Fil d'ariane simple (le breadcrumb Woo par défaut ne suit pas la maquette).
 * ------------------------------------------------------------------------- */
function at_cart_crumb( $last ) {
	$shop = wc_get_page_permalink( 'shop' );
	echo '<nav class="at-crumb" aria-label="Fil d\'ariane">';
	echo '<a href="' . esc_url( home_url( '/' ) ) . '">Accueil</a>' . at_icon_get( 'chevron-right', 15 );
	echo '<a href="' . esc_url( $shop ) . '">Boutique</a>' . at_icon_get( 'chevron-right', 15 );
	echo '<span>' . esc_html( $last ) . '</span>';
	echo '</nav>';
}

/* ---------------------------------------------------------------------------
 * Disponibilité affichée sur une ligne de panier (pastille olive).
 * ------------------------------------------------------------------------- */
function at_stock_label( $product ) {
	if ( ! $product ) { return ''; }
	if ( $product->is_on_backorder() ) { return 'Sur commande'; }
	if ( ! $product->managing_stock() ) { return 'Expédition sous 48 h'; }
	$qty = $product->get_stock_quantity();
	if ( $qty !== null && $qty > 0 ) { return 'En stock à Vence'; }
	return $product->is_in_stock() ? 'Expédition sous 48 h' : 'Sur commande';
}

/* ---------------------------------------------------------------------------
 * Rendu des lignes du panier (premier rendu + réponses AJAX).
 * ------------------------------------------------------------------------- */
function at_cart_render_lines() {
	ob_start();
	foreach ( WC()->cart->get_cart() as $key => $item ) {
		$product = apply_filters( 'woocommerce_cart_item_product', $item['data'], $item, $key );
		if ( ! $product || ! $product->exists() || $item['quantity'] <= 0 ) { continue; }

		$link  = $product->is_visible() ? $product->get_permalink( $item ) : '';
		// nom du produit parent : les attributs sont affiches sur leur propre ligne
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
		$name   = $parent ? $parent->get_name() : $product->get_name();
		$thumb = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'at-line-img' ) );
		$brand = '';
		$terms = get_the_terms( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(), 'product_brand' );
		if ( $terms && ! is_wp_error( $terms ) ) { $brand = $terms[0]->name; }

		$max = $product->managing_stock() && ! $product->backorders_allowed() ? $product->get_stock_quantity() : 0;
		?>
		<div class="at-line" data-key="<?php echo esc_attr( $key ); ?>" data-max="<?php echo (int) $max; ?>">
			<div class="at-line-media"><?php echo $link ? '<a href="' . esc_url( $link ) . '">' . $thumb . '</a>' : $thumb; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<div class="at-line-body">
				<?php if ( $brand ) : ?><span class="at-line-brand"><?php echo esc_html( $brand ); ?></span><?php endif; ?>
				<?php if ( $link ) : ?>
					<a class="at-line-name" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $name ); ?></a>
				<?php else : ?>
					<span class="at-line-name"><?php echo esc_html( $name ); ?></span>
				<?php endif; ?>
				<?php
				// « Taille ML · Orange / graphite » : attributs de la variation, puis
				// metadonnees de ligne eventuelles (options atelier...).
				$bits = array();
				if ( ! empty( $item['variation'] ) ) {
					foreach ( $item['variation'] as $tax => $value ) {
						if ( '' === $value ) { continue; }
						$taxonomy = str_replace( 'attribute_', '', $tax );
						$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : null;
						$bits[]   = wc_attribute_label( $taxonomy, $product ) . ' ' . ( $term ? $term->name : $value );
					}
				}
				$meta = wc_get_formatted_cart_item_data( $item, true );
				if ( $meta ) { $bits[] = trim( str_replace( "\n", ' · ', $meta ) ); }
				if ( $bits ) :
					?>
					<span class="at-line-variant"><?php echo esc_html( implode( ' · ', $bits ) ); ?></span>
				<?php endif; ?>
				<span class="at-line-stock"><?php at_icon( 'check-circle', 15 ); ?><?php echo esc_html( at_stock_label( $product ) ); ?></span>

				<div class="at-line-actions">
					<span class="at-qty">
						<button type="button" class="at-qty-btn" data-step="-1" aria-label="Diminuer la quantité"<?php disabled( $item['quantity'] <= 1 ); ?>><?php at_icon( 'minus', 17 ); ?></button>
						<span class="at-qty-v"><?php echo esc_html( $item['quantity'] ); ?></span>
						<button type="button" class="at-qty-btn" data-step="1" aria-label="Augmenter la quantité"<?php disabled( $max && $item['quantity'] >= $max ); ?>><?php at_icon( 'plus', 17 ); ?></button>
					</span>
					<button type="button" class="at-line-rm"><?php at_icon( 'trash', 16 ); ?>Retirer</button>
				</div>
			</div>
			<div class="at-line-price">
				<b><?php echo wp_kses_post( apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $product, $item['quantity'] ), $item, $key ) ); ?></b>
				<span><?php echo wp_kses_post( wc_price( wc_get_price_to_display( $product ) ) ); ?> l'unité</span>
			</div>
		</div>
		<?php
	}
	return ob_get_clean();
}

/* ---------------------------------------------------------------------------
 * Rendu du bloc « Total panier » (colonne de droite).
 * ------------------------------------------------------------------------- */
function at_cart_render_totals() {
	$cart      = WC()->cart;
	$subtotal  = (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax();
	$threshold = at_free_shipping_threshold();
	$pct       = $threshold > 0 ? min( 100, ( $subtotal / $threshold ) * 100 ) : 100;
	$remaining = max( 0, $threshold - $subtotal );

	ob_start();
	?>
	<b class="at-sum-t">Total panier</b>

	<div class="at-promo" data-open="<?php echo $cart->get_applied_coupons() ? '1' : '1'; ?>">
		<button type="button" class="at-promo-head" aria-expanded="true" aria-controls="at-promo-body">
			<span><?php at_icon( 'price-tag', 17 ); ?>Ajouter un code promo</span>
			<?php at_icon( 'chevron-down', 19, 'at-promo-chev' ); ?>
		</button>
		<div class="at-promo-body" id="at-promo-body">
			<div class="at-promo-row">
				<input type="text" class="at-promo-input" placeholder="Code promo" aria-label="Code promo" autocomplete="off">
				<button type="button" class="at-promo-apply">Appliquer</button>
			</div>
			<p class="at-promo-msg" role="status"></p>
			<?php if ( $cart->get_applied_coupons() ) : ?>
				<div class="at-promo-tags">
					<?php foreach ( $cart->get_applied_coupons() as $code ) : ?>
						<span class="at-promo-tag"><?php echo esc_html( strtoupper( $code ) ); ?>
							<button type="button" class="at-promo-rm" data-code="<?php echo esc_attr( $code ); ?>" aria-label="Retirer le code <?php echo esc_attr( $code ); ?>"><?php at_icon( 'close', 14 ); ?></button>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div class="at-sum-rows">
		<div class="at-sum-row"><span>Sous-total</span><b><?php wc_cart_totals_subtotal_html(); ?></b></div>
		<?php foreach ( $cart->get_coupons() as $code => $coupon ) : ?>
			<div class="at-sum-row at-sum-row--discount">
				<span>Remise (<?php echo esc_html( strtoupper( $code ) ); ?>)</span>
				<b>−<?php echo wp_kses_post( wc_price( $cart->get_coupon_discount_amount( $code, $cart->display_cart_ex_tax ) ) ); ?></b>
			</div>
		<?php endforeach; ?>
		<div class="at-sum-row at-sum-row--muted"><span>Dont TVA 20 %</span><span><?php echo wp_kses_post( wc_price( $cart->get_total_tax() ) ); ?></span></div>
		<div class="at-sum-row">
			<span>Livraison</span>
			<?php if ( $remaining <= 0 ) : ?>
				<b class="at-free">Offerte</b>
			<?php else : ?>
				<span>Calculée à l'étape suivante</span>
			<?php endif; ?>
		</div>
	</div>

	<div class="at-sum-total">
		<b>Total estimé</b>
		<?php // wc_cart_totals_order_total_html() ajoute « (dont X € TVA) » : la TVA a déjà sa ligne ?>
		<b class="at-sum-total-v"><?php echo wp_kses_post( wc_price( $cart->get_total( 'edit' ) ) ); ?></b>
	</div>

	<a class="at-sum-cta" href="<?php echo esc_url( wc_get_checkout_url() ); ?>">Valider la commande<?php at_icon( 'arrow-right', 19 ); ?></a>

	<div class="at-franco">
		<div class="at-franco-top">
			<span><?php echo $remaining > 0
				? 'Plus que ' . wp_kses_post( wc_price( $remaining ) ) . ' pour la livraison offerte'
				: 'Livraison offerte débloquée'; ?></span>
			<span><?php echo esc_html( round( $pct ) ); ?> %</span>
		</div>
		<div class="at-franco-bar"><span style="width:<?php echo esc_attr( $pct ); ?>%"></span></div>
	</div>

	<ul class="at-reassure-list">
		<li><?php at_icon( 'shield-check', 17 ); ?><span>Paiement sécurisé CB, virement ou 3× sans frais</span></li>
		<li><?php at_icon( 'rotate-left', 17 ); ?><span>Retour sous 14 jours si le matériel n'a pas volé</span></li>
		<li><?php at_icon( 'store', 17 ); ?><span>Retrait gratuit à l'atelier de Vence</span></li>
	</ul>
	<?php
	return ob_get_clean();
}

/* ---------------------------------------------------------------------------
 * Réponse commune aux actions AJAX du panier.
 * ------------------------------------------------------------------------- */
function at_cart_ajax_payload( $extra = array() ) {
	WC()->cart->calculate_totals();
	return array_merge( array(
		'lines'     => at_cart_render_lines(),
		'totals'    => at_cart_render_totals(),
		'count'     => WC()->cart->get_cart_contents_count(),
		'empty'     => WC()->cart->is_empty(),
		'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
	), $extra );
}

function at_cart_guard() {
	check_ajax_referer( 'at-cart', 'nonce' );
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( array( 'message' => 'Panier indisponible.' ) );
	}
}

add_action( 'wp_ajax_at_cart_update', 'at_cart_ajax_update' );
add_action( 'wp_ajax_nopriv_at_cart_update', 'at_cart_ajax_update' );
function at_cart_ajax_update() {
	at_cart_guard();
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	$qty = isset( $_POST['qty'] ) ? absint( $_POST['qty'] ) : 0;
	$item = WC()->cart->get_cart_item( $key );
	if ( ! $item ) { wp_send_json_error( array( 'message' => 'Article introuvable.' ) ); }

	// borne haute : stock disponible
	$product = $item['data'];
	if ( $product && $product->managing_stock() && ! $product->backorders_allowed() ) {
		$qty = min( $qty, (int) $product->get_stock_quantity() );
	}
	$qty = max( 1, $qty );

	WC()->cart->set_quantity( $key, $qty, true );
	wp_send_json_success( at_cart_ajax_payload( array( 'message' => 'Panier mis à jour.' ) ) );
}

add_action( 'wp_ajax_at_cart_remove', 'at_cart_ajax_remove' );
add_action( 'wp_ajax_nopriv_at_cart_remove', 'at_cart_ajax_remove' );
function at_cart_ajax_remove() {
	at_cart_guard();
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	$item = WC()->cart->get_cart_item( $key );
	$name = $item ? $item['data']->get_name() : 'Article';
	WC()->cart->remove_cart_item( $key );
	wp_send_json_success( at_cart_ajax_payload( array(
		'message'  => $name . ' retiré du panier.',
		'restored' => $key,
	) ) );
}

add_action( 'wp_ajax_at_cart_restore', 'at_cart_ajax_restore' );
add_action( 'wp_ajax_nopriv_at_cart_restore', 'at_cart_ajax_restore' );
function at_cart_ajax_restore() {
	at_cart_guard();
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	WC()->cart->restore_cart_item( $key );
	wp_send_json_success( at_cart_ajax_payload( array( 'message' => 'Article restauré.' ) ) );
}

add_action( 'wp_ajax_at_cart_add', 'at_cart_ajax_add' );
add_action( 'wp_ajax_nopriv_at_cart_add', 'at_cart_ajax_add' );
function at_cart_ajax_add() {
	at_cart_guard();
	$id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	if ( ! $id ) { wp_send_json_error( array( 'message' => 'Produit inconnu.' ) ); }
	$product = wc_get_product( $id );
	if ( ! $product ) { wp_send_json_error( array( 'message' => 'Produit inconnu.' ) ); }
	// un produit à variations ne peut pas être ajouté sans choix : on renvoie sa fiche
	if ( $product->is_type( 'variable' ) ) {
		wp_send_json_error( array( 'redirect' => $product->get_permalink() ) );
	}
	if ( ! WC()->cart->add_to_cart( $id ) ) {
		wp_send_json_error( array( 'message' => "Cet article n'a pas pu être ajouté." ) );
	}
	wp_send_json_success( at_cart_ajax_payload( array( 'message' => $product->get_name() . ' ajouté au panier.' ) ) );
}

add_action( 'wp_ajax_at_cart_coupon', 'at_cart_ajax_coupon' );
add_action( 'wp_ajax_nopriv_at_cart_coupon', 'at_cart_ajax_coupon' );
function at_cart_ajax_coupon() {
	at_cart_guard();
	$code = isset( $_POST['code'] ) ? wc_format_coupon_code( wp_unslash( $_POST['code'] ) ) : '';
	$op   = isset( $_POST['op'] ) && 'remove' === $_POST['op'] ? 'remove' : 'apply';

	if ( ! $code ) { wp_send_json_error( array( 'message' => 'Saisissez un code.' ) ); }

	if ( 'remove' === $op ) {
		WC()->cart->remove_coupon( $code );
		wp_send_json_success( at_cart_ajax_payload( array( 'message' => 'Code retiré.' ) ) );
	}

	if ( WC()->cart->has_discount( $code ) ) {
		wp_send_json_error( array( 'message' => 'Ce code est déjà appliqué.' ) );
	}
	$applied = WC()->cart->apply_coupon( $code );
	if ( ! $applied ) {
		$notices = wc_get_notices( 'error' );
		wc_clear_notices();
		$msg = $notices ? wp_strip_all_tags( $notices[0]['notice'] ) : "Ce code n'est pas valable.";
		wp_send_json_error( array( 'message' => $msg ) );
	}
	wc_clear_notices();
	wp_send_json_success( at_cart_ajax_payload( array( 'message' => 'Code appliqué.' ) ) );
}


/* ---------------------------------------------------------------------------
 * Ventes croisées : `woocommerce_cross_sell_display()` ne charge le template
 * que si le panier a des ventes croisées déclarées. Aucun produit n'en a ici :
 * on injecte un repli (meilleures ventes) pour que la section existe, le tri
 * fin étant fait dans woocommerce/cart/cross-sells.php.
 * ------------------------------------------------------------------------- */
add_filter( 'woocommerce_cart_crosssell_ids', function ( $ids ) {
	if ( ! empty( $ids ) || ! function_exists( 'WC' ) || ! WC()->cart ) { return $ids; }

	$in_cart = array();
	foreach ( WC()->cart->get_cart() as $item ) { $in_cart[] = $item['product_id']; }

	return get_posts( array(
		'post_type'      => 'product',
		'posts_per_page' => 4,
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'post__not_in'   => $in_cart,
		'meta_key'       => 'total_sales',
		'orderby'        => 'meta_value_num',
		'order'          => 'DESC',
		'tax_query'      => array(
			array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => 'exclude-from-catalog', 'operator' => 'NOT IN' ),
		),
	) );
} );
