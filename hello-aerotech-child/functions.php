<?php
/**
 * Theme functions and definitions.
 *
 * Ce theme enfant ne contient que la partie presentation (enqueue des
 * styles parent puis enfant). Aucune logique metier ici.
 *
 * @package HelloAerotechChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'HELLO_AEROTECH_CHILD_VERSION', '1.0.0' );

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_aerotech_child_scripts_styles() {

	wp_enqueue_style(
		'hello-aerotech-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		[
			'hello-elementor-theme-style',
		],
		HELLO_AEROTECH_CHILD_VERSION
	);

}
add_action( 'wp_enqueue_scripts', 'hello_aerotech_child_scripts_styles', 20 );

/* ==== AEROTECH boutique — fusionné depuis hello-elementor-child (2026-08-02) ==== */

/*
 * Le plugin gestion-atelier-cct désactive la boutique WooCommerce (404 sur
 * is_shop/is_product/is_product_taxonomy) car il a été conçu sans boutique.
 * Ce site A une boutique : on retire ce hook (le tunnel atelier n'en dépend
 * pas — cf. commentaire « Réversible » du plugin).
 */
add_action( 'template_redirect', function () {
	remove_action( 'template_redirect', 'jwcct_disable_woocommerce_shop', 0 );
}, -1 );

add_action( 'after_setup_theme', function () {
	add_theme_support( 'woocommerce' );
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'hello-elementor-child', get_stylesheet_uri(), array(), '1.0.0' );
	if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) ) {
		wp_enqueue_style( 'remixicon', 'https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css', array(), '4.5.0' );
		wp_enqueue_style( 'at-shop', get_stylesheet_directory_uri() . '/assets/at-shop.css', array(), filemtime( get_stylesheet_directory() . '/assets/at-shop.css' ) );
		wp_enqueue_script( 'at-shop', get_stylesheet_directory_uri() . '/assets/at-shop.js', array(), filemtime( get_stylesheet_directory() . '/assets/at-shop.js' ), true );
	}
}, 20 );

/* ---------- Compteur panier : fragment + badge injecté dans le header 205 ---------- */
add_filter( 'woocommerce_add_to_cart_fragments', function ( $fragments ) {
	$n = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	$fragments['.at-cart-badge'] = '<span class="at-cart-badge"' . ( $n ? '' : ' hidden' ) . '>' . esc_html( $n ) . '</span>';
	return $fragments;
} );

add_action( 'wp_footer', function () {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return; }
	$n = WC()->cart->get_cart_contents_count();
	?>
<style>.at-cart-anchor{position:relative;display:inline-flex}.at-cart-badge{position:absolute;top:-6px;right:-7px;min-width:17px;height:17px;padding:0 4px;border-radius:999px;background:#EC672C;color:#fff;font:800 10.5px/17px Rubik,sans-serif;text-align:center;pointer-events:none}.at-cart-badge[hidden]{display:none}@keyframes atCartBump{0%{transform:scale(1) rotate(0)}30%{transform:scale(1.14) rotate(-9deg)}62%{transform:scale(.98) rotate(3deg)}100%{transform:scale(1) rotate(0)}}@keyframes atBadgePop{0%{transform:scale(1)}40%{transform:scale(1.32)}100%{transform:scale(1)}}.at-cart-bump{animation:atCartBump .34s cubic-bezier(.16,1,.3,1)}.at-cart-bump .at-cart-badge{animation:atBadgePop .28s .05s cubic-bezier(.16,1,.3,1)}</style>
<script>(function(){var n=<?php echo (int) $n; ?>;document.querySelectorAll('a[href*="/panier"]').forEach(function(a){if(!a.closest('.at-header-icons,.at-mm-pills'))return;a.classList.add('at-cart-anchor');var b=document.createElement('span');b.className='at-cart-badge';b.textContent=n;if(!n)b.hidden=true;a.appendChild(b);});})();</script>
	<?php
}, 99 );

/* ---------- Aides ---------- */

/** Titre bicolore : dernier mot en orange. */
function at_bicolor( $title ) {
	$title = trim( wp_strip_all_tags( $title ) );
	$pos   = strrpos( $title, ' ' );
	if ( false === $pos ) { return '<em>' . esc_html( $title ) . '</em>'; }
	return esc_html( substr( $title, 0, $pos ) ) . ' <em>' . esc_html( substr( $title, $pos + 1 ) ) . '</em>';
}

/** Badge unique : Promo > Nouveau > Occasion. */
function at_product_badge( $product ) {
	if ( $product->is_on_sale() ) { return '<span class="at-badge at-badge--promo">Promo</span>'; }
	if ( get_post_meta( $product->get_id(), 'at_occasion', true ) ) { return '<span class="at-badge at-badge--used">Occasion</span>'; }
	$created = $product->get_date_created();
	if ( $created && ( time() - $created->getTimestamp() ) < 90 * DAY_IN_SECONDS ) { return '<span class="at-badge at-badge--new">Nouveau</span>'; }
	return '';
}

/** Pastilles coloris d'une carte (term meta at_swatch des pa_couleur). */
function at_card_swatches( $product ) {
	$terms = wc_get_product_terms( $product->get_id(), 'pa_couleur', array( 'fields' => 'all' ) );
	if ( empty( $terms ) || is_wp_error( $terms ) ) { return ''; }
	$dots = '';
	$n    = 0;
	foreach ( $terms as $t ) {
		$css = get_term_meta( $t->term_id, 'at_swatch', true );
		if ( ! $css ) { continue; }
		$n++;
		if ( $n <= 4 ) { $dots .= '<i class="at-dot" style="background:' . esc_attr( $css ) . '"></i>'; }
	}
	if ( ! $n ) { return ''; }
	return '<span class="at-card-colors">' . $dots . '<span>' . $n . ' coloris</span></span>';
}

/** Prix d'une carte. */
function at_card_price( $product ) {
	$price = wc_get_price_to_display( $product, array( 'price' => $product->get_price() ) );
	$html  = '<b class="at-card-price">' . wc_price( $price ) . '</b>';
	if ( $product->is_on_sale() && ! $product->is_type( 'variable' ) ) {
		$html .= '<s class="at-card-old">' . wc_price( wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ) ) . '</s>';
	}
	return $html;
}

/** Carte produit (liste + produits liés). $variant: 'grid' | 'related'. */
function at_product_card( $product, $variant = 'grid' ) {
	?>
	<a class="at-card" href="<?php echo esc_url( $product->get_permalink() ); ?>">
		<span class="at-card-media">
			<?php echo $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'at-card-img', 'loading' => 'lazy' ) ); // phpcs:ignore ?>
			<?php echo at_product_badge( $product ); // phpcs:ignore ?>
		</span>
		<span class="at-card-body">
			<b class="at-card-name"><?php echo esc_html( $product->get_name() ); ?></b>
			<?php if ( 'related' === $variant ) : ?>
				<?php $cats = wc_get_product_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) ); ?>
				<span class="at-card-cat"><?php echo esc_html( implode( ' · ', array_slice( $cats, 0, 2 ) ) ); ?></span>
				<span class="at-card-pricerow at-card-pricerow--end"><?php echo at_card_price( $product ); // phpcs:ignore ?></span>
			<?php else : ?>
				<span class="at-card-pricerow"><?php echo at_card_price( $product ); // phpcs:ignore ?></span>
				<?php echo at_card_swatches( $product ); // phpcs:ignore ?>
			<?php endif; ?>
		</span>
	</a>
	<?php
}

/** Fil d'ariane : rendu partagé (schema.org) dans inc/breadcrumb.php. */
require_once get_stylesheet_directory() . '/inc/breadcrumb.php';

/** Bande conseil sombre. */
function at_conseil_band( $title_html ) {
	$img = wp_get_attachment_image_url( AT_CONSEIL_IMG, 'large' );
	?>
	<section class="at-conseil">
		<div class="at-conseil-grid">
			<div class="at-conseil-txt">
				<span class="at-eyebrow at-eyebrow--dark">Aide au choix</span>
				<h2><?php echo $title_html; // phpcs:ignore ?></h2>
				<p>Dites-nous votre niveau, vos heures de vol et votre programme. Nous vous répondons avec une recommandation argumentée, et l'occasion de venir l'essayer à Gréolières.</p>
				<div class="at-conseil-actions">
					<a class="at-btn at-btn--primary" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Demander conseil<i class="ri-arrow-right-line"></i></a>
					<a class="at-btn at-btn--ghost" href="tel:+33620899131">Nous appeler</a>
				</div>
			</div>
			<?php if ( $img ) : ?><img src="<?php echo esc_url( $img ); ?>" alt="Voile en vol au-dessus des crêtes" loading="lazy" /><?php endif; ?>
		</div>
	</section>
	<?php
}

/* fusion : constantes et helpers boutique */
define( "AT_CONSEIL_IMG", 154 );
require get_stylesheet_directory() . "/inc/product-meta.php";

/* ---------------------------------------------------------------------------
 * Bouton « Tableau » dans TinyMCE (WP n'embarque pas le plugin table).
 * Utilisé pour le champ « Données techniques » (at_tech_html) des produits.
 * ------------------------------------------------------------------------- */
add_filter( 'mce_external_plugins', function( $plugins ) {
	$plugins['table'] = get_stylesheet_directory_uri() . '/assets/tinymce/table/plugin.min.js';
	return $plugins;
} );

add_filter( 'mce_buttons_2', function( $buttons ) {
	if ( ! in_array( 'table', $buttons, true ) ) {
		array_unshift( $buttons, 'table' );
	}
	return $buttons;
} );

add_filter( 'tiny_mce_before_init', function( $init ) {
	// Markup propre : pas d'attributs ni de styles inline, le CSS front s'en charge.
	$init['table_default_attributes'] = '{}';
	$init['table_default_styles']     = '{}';
	$init['table_appearance_options'] = false;
	return $init;
} );

/* ---------- Page Contact (Elementor natif + Elementor Pro Form) ---------- */
require get_stylesheet_directory() . "/inc/contact.php";

/* ---------- Panier & commande (tunnel WooCommerce classique) ---------- */
require get_stylesheet_directory() . "/inc/shop-cart.php";

require get_stylesheet_directory() . "/inc/category-seo.php";
require get_stylesheet_directory() . "/inc/shop-checkout.php";
/* ---------------------------------------------------------------------------
 * Pages de contenu simple (CGV, mentions légales, toute page au bloc Gutenberg
 * qui n'est PAS construite avec Elementor). Le header/footer restent les
 * templates Elementor 205/210 ; seul le corps est mis en forme ici.
 * ------------------------------------------------------------------------- */
function at_is_simple_page() {
	if ( ! is_singular() || is_front_page() ) {
		return false;
	}
	$id = get_queried_object_id();
	if ( ! $id || function_exists( 'is_woocommerce' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
		return false;
	}
	if ( class_exists( '\Elementor\Plugin' ) ) {
		$doc = \Elementor\Plugin::$instance->documents->get( $id );
		if ( $doc && $doc->is_built_with_elementor() ) {
			return false;
		}
	}
	return true;
}

add_filter( 'body_class', function ( $classes ) {
	if ( at_is_simple_page() ) {
		$classes[] = 'at-simple-page';
	}
	return $classes;
} );

// hello-elementor masque le titre globalement (les pages Elementor rendent le leur) :
// on le remet uniquement sur ces pages, sinon elles n'ont aucun <h1>.
add_filter( 'hello_elementor_page_title', function ( $show ) {
	return at_is_simple_page() ? true : $show;
}, 20 );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! at_is_simple_page() ) {
		return;
	}
	$path = get_stylesheet_directory() . '/assets/at-page.css';
	wp_enqueue_style(
		'at-page',
		get_stylesheet_directory_uri() . '/assets/at-page.css',
		array(),
		file_exists( $path ) ? filemtime( $path ) : '1.0.0'
	);
}, 25 );


/* ---------------------------------------------------------------------------
 * AT-ANNOUNCE-HOME — le bandeau d'annonce du header (conteneur 57d7af9 du
 * template « AEROTECH Header » 205) n'est affiché que sur la page d'accueil.
 * On coupe le rendu côté serveur plutôt qu'en CSS : rien dans le DOM ailleurs.
 * ------------------------------------------------------------------------- */
define( 'AT_ANNOUNCE_ID', '57d7af9' );

add_filter(
	'elementor/frontend/container/should_render',
	function ( $should_render, $element ) {
		if ( AT_ANNOUNCE_ID === $element->get_id() && ! is_front_page() ) {
			return false;
		}
		return $should_render;
	},
	10,
	2
);

/** Espace client : header simplifie, tiroir de navigation, JS du menu. */
require get_stylesheet_directory() . "/inc/account.php";

/** Partenariat Ailements : suivi des clics sortants (baptemes, stages). */
require get_stylesheet_directory() . "/inc/partner.php";

/* ==== Design du site — déménagé du plugin gestion-atelier-cct (04/08/2026) ====
 *
 * Règle d'architecture du 03/08/2026 (site de référence) : l'habillage propre au
 * site vit dans le THÈME ENFANT, le plugin partagé reste identique entre les
 * ateliers. `ar-buttons.js` (dédoublement du libellé des CTA .ar-btn-swap) et
 * `menu-mobile.css` (carte profil + icônes des drawers JetMenu) remplacent
 * l'ancien includes/gacct-buttons.php du plugin, supprimé le même jour. */
function hello_aerotech_child_design_assets() {
	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();

	$css = $dir . '/assets/menu-mobile.css';
	wp_enqueue_style(
		'ar-menu-mobile',
		$uri . '/assets/menu-mobile.css',
		array(),
		file_exists( $css ) ? filemtime( $css ) : HELLO_AEROTECH_CHILD_VERSION
	);

	// Dépend de jQuery : le hook `elementor/frontend/init` est émis par
	// jQuery.trigger, un écouteur natif ne le recevrait pas.
	$js = $dir . '/assets/ar-buttons.js';
	wp_enqueue_script(
		'ar-buttons',
		$uri . '/assets/ar-buttons.js',
		array( 'jquery' ),
		file_exists( $js ) ? filemtime( $js ) : HELLO_AEROTECH_CHILD_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'hello_aerotech_child_design_assets' );
