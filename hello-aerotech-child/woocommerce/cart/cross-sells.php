<?php
/**
 * Ventes croisées AEROTECH — surcharge de woocommerce/cart/cross-sells.php
 * Maquette : templates/cart/Cart.dc.html (handoff §6.3)
 * 4 cartes maximum ; à défaut de ventes croisées déclarées, les meilleures
 * ventes des catégories présentes dans le panier.
 */

defined( 'ABSPATH' ) || exit;

global $product;

$ids = wp_list_pluck( (array) $cross_sells, 'id' );

if ( count( $ids ) < 4 ) {
	// repli : meilleures ventes des catégories du panier, hors articles déjà présents
	$cats = array();
	$in_cart = array();
	foreach ( WC()->cart->get_cart() as $item ) {
		$pid = $item['product_id'];
		$in_cart[] = $pid;
		$terms = get_the_terms( $pid, 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$cats = array_merge( $cats, wp_list_pluck( $terms, 'term_id' ) );
		}
	}
	$fill = get_posts( array(
		'post_type'      => 'product',
		'posts_per_page' => 8,
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'post__not_in'   => array_merge( $in_cart, $ids ),
		'meta_key'       => 'total_sales',
		'orderby'        => 'meta_value_num',
		'order'          => 'DESC',
		'tax_query'      => array(
			array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => 'exclude-from-catalog', 'operator' => 'NOT IN' ),
		),
	) );
	if ( $cats ) {
		$in_cat = get_posts( array(
			'post_type'      => 'product',
			'posts_per_page' => 8,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'post__not_in'   => array_merge( $in_cart, $ids ),
			'meta_key'       => 'total_sales',
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'tax_query'      => array(
				array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array_unique( $cats ) ),
				array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => 'exclude-from-catalog', 'operator' => 'NOT IN' ),
			),
		) );
		$fill = array_merge( $in_cat, $fill );
	}
	$ids = array_slice( array_unique( array_merge( $ids, $fill ) ), 0, 4 );
} else {
	$ids = array_slice( $ids, 0, 4 );
}

if ( ! $ids ) { return; }
?>
<section class="at-cross" aria-label="Compléter votre équipement">
	<h2 class="at-h2">Complétez votre <em>équipement</em></h2>
	<p class="at-chapo">Ce que nos pilotes ajoutent le plus souvent à une commande de voile.</p>

	<div class="at-cross-grid">
		<?php
		foreach ( $ids as $id ) :
			$p = wc_get_product( $id );
			if ( ! $p || ! $p->is_visible() ) { continue; }

			$brand = '';
			$terms = get_the_terms( $id, 'product_brand' );
			if ( $terms && ! is_wp_error( $terms ) ) { $brand = $terms[0]->name; }

			$cat  = '';
			$cats = get_the_terms( $id, 'product_cat' );
			if ( $cats && ! is_wp_error( $cats ) ) { $cat = $cats[0]->name; }
			?>
			<article class="at-cross-card">
				<a class="at-cross-media" href="<?php echo esc_url( $p->get_permalink() ); ?>">
					<?php echo $p->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</a>
				<?php if ( $brand ) : ?><span class="at-cross-brand"><?php echo esc_html( $brand ); ?></span><?php endif; ?>
				<b class="at-cross-name"><a href="<?php echo esc_url( $p->get_permalink() ); ?>"><?php echo esc_html( $p->get_name() ); ?></a></b>
				<?php if ( $cat ) : ?><span class="at-cross-meta"><?php echo esc_html( $cat ); ?></span><?php endif; ?>
				<div class="at-cross-foot">
					<b class="at-cross-price"><?php echo wp_kses_post( $p->get_price_html() ); ?></b>
					<button type="button" class="at-cross-add" data-product="<?php echo esc_attr( $id ); ?>"><?php at_icon( 'plus', 17 ); ?>Ajouter</button>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
</section>
