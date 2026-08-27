<?php
/**
 * Theme functions and definitions.
 *
 * Ce fichier ne contient plus que la partie presentation du theme enfant.
 * Toute la logique metier (liaison JFB/WooCommerce/CCT, callbacks JetEngine,
 * shortcodes, debug) a ete deplacee dans le plugin "Gestion Atelier CCT"
 * (wp-content/plugins/gestion-atelier-cct/includes/) en juillet 2026.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0' );

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles() {

	wp_enqueue_style(
		'hello-elementor-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		[
			'hello-elementor-theme-style',
		],
		HELLO_ELEMENTOR_CHILD_VERSION
	);

}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20 );

/**
 * Header public « Altitude Révision » — habillage propre à CE site.
 *
 * Le header lui-même est un template Elementor (post 1284) monté en widgets
 * natifs. Ces deux fichiers ne portent que ce qu'Elementor ne sait pas
 * exprimer en réglages :
 *
 *  - assets/css/header.css : double barre, grille 3 colonnes du mobile,
 *    pilule givrée, panneaux flottants, paliers 1366/1200 px ;
 *  - assets/js/header.js   : apparition de la barre fixe au scroll, masquage
 *    directionnel et pilule en mobile, ouverture des deux panneaux.
 *
 * Chargés sur tout le front : le header est global. Le JS sort de lui-même si
 * le header public n'est pas rendu. Rangés dans le thème enfant (et pas dans
 * le plugin gestion-atelier-cct) car c'est du DESIGN spécifique à ce site :
 * un autre atelier aura son propre header (décision du 03/08/2026).
 *
 * @return void
 */
function hello_elementor_child_header_assets() {
	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();

	$css_path = $dir . '/assets/css/header.css';
	wp_enqueue_style(
		'ar-header',
		$uri . '/assets/css/header.css',
		array(),
		file_exists( $css_path ) ? filemtime( $css_path ) : HELLO_ELEMENTOR_CHILD_VERSION
	);

	$js_path = $dir . '/assets/js/header.js';
	wp_enqueue_script(
		'ar-header',
		$uri . '/assets/js/header.js',
		array(),
		file_exists( $js_path ) ? filemtime( $js_path ) : HELLO_ELEMENTOR_CHILD_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_header_assets' );

/**
 * Habillage « Altitude Révision » du reste du front — design propre à CE site.
 *
 * Rapatrié du plugin gestion-atelier-cct le 03/08/2026, même motif que le
 * header ci-dessus : un autre atelier aura sa charte, le plugin ne doit
 * porter que du fonctionnel.
 *
 *  - assets/css/tokens.css      : LA palette du site (variables --gacct-*).
 *    Les feuilles du plugin les consomment avec un repli neutre ; c'est ce
 *    fichier, et lui seul, qui donne au site son teal et son jaune. Chargé en
 *    premier et sur tout le front (le plugin peut être appelé sur n'importe
 *    quelle page). Anciennement le bloc :root de
 *    plugins/gestion-atelier-cct/assets/css/demande-intervention.css.
 *  - assets/css/menu-mobile.css : carte profil + icônes des drawers JetMenu
 *    (templates 1881 / 970), dont le markup vit dans Elementor.
 *  - assets/js/ar-buttons.js    : dédoublement du libellé des CTA
 *    « .ar-btn-swap » ; sa CSS est dans le custom CSS du kit Elementor.
 *
 * @return void
 */
function hello_elementor_child_design_assets() {
	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();

	foreach ( array( 'ar-tokens' => 'tokens.css', 'ar-menu-mobile' => 'menu-mobile.css' ) as $handle => $file ) {
		$path = $dir . '/assets/css/' . $file;

		wp_enqueue_style(
			$handle,
			$uri . '/assets/css/' . $file,
			array(),
			file_exists( $path ) ? filemtime( $path ) : HELLO_ELEMENTOR_CHILD_VERSION
		);
	}

	// Dépend de jQuery : le hook `elementor/frontend/init` est émis par
	// jQuery.trigger, un écouteur natif ne le recevrait pas.
	$js_path = $dir . '/assets/js/ar-buttons.js';
	wp_enqueue_script(
		'ar-buttons',
		$uri . '/assets/js/ar-buttons.js',
		array( 'jquery' ),
		file_exists( $js_path ) ? filemtime( $js_path ) : HELLO_ELEMENTOR_CHILD_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_design_assets' );

/**
 * Page de commande (/commander/) — design porté du checkout AEROTECH le
 * 07/08/2026, adapté à la charte Altitude (tokens dans assets/at-shop.css).
 *
 * La page 13 est une simple page [woocommerce_checkout] : le rendu vient des
 * overrides woocommerce/checkout/{form-checkout,review-order,payment}.php et
 * des fichiers inc/{shop-cart,shop-checkout,checkout-fields}.php. Cascade des
 * feuilles : at-shop → at-cart → at-checkout (dépendances d'enqueue déclarées).
 * Même périmètre que le reste de ce fichier : du DESIGN propre à ce site.
 */
add_action( 'after_setup_theme', function () {
	add_theme_support( 'woocommerce' );
} );

// La page 13 n'étant plus construite avec Elementor, hello-elementor rend son
// titre (« Validation de la commande ») au-dessus du template : le checkout
// porte déjà son propre <h1> « Votre commande ».
add_filter( 'hello_elementor_page_title', function ( $show ) {
	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		return false;
	}
	return $show;
}, 20 );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'is_checkout' ) || ! ( is_cart() || is_checkout() ) ) {
		return;
	}
	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();
	wp_enqueue_style( 'at-shop', $uri . '/assets/at-shop.css', array(), filemtime( $dir . '/assets/at-shop.css' ) );
	wp_enqueue_script( 'at-shop', $uri . '/assets/at-shop.js', array(), filemtime( $dir . '/assets/at-shop.js' ), true );
}, 20 );

require get_stylesheet_directory() . '/inc/shop-cart.php';
require get_stylesheet_directory() . '/inc/shop-checkout.php';
