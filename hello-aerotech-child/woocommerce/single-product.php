<?php
/**
 * AEROTECH — fiche produit (maquette ProductPage.dc.html).
 * Pas d'onglets WooCommerce : contenu empilé dans la page.
 */
defined( 'ABSPATH' ) || exit;
get_header();

while ( have_posts() ) :
	the_post();
	$product = wc_get_product( get_the_ID() );
	if ( ! $product ) { break; }

	$pid      = $product->get_id();
	$brand    = wc_get_product_terms( $pid, 'product_brand', array( 'fields' => 'names' ) );
	$brand    = $brand && ! is_wp_error( $brand ) ? $brand[0] : '';
	$cats     = wc_get_product_terms( $pid, 'product_cat', array( 'orderby' => 'parent' ) );
	$cat      = $cats && ! is_wp_error( $cats ) ? end( $cats ) : null;
	$imgs     = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
	$variable = $product->is_type( 'variable' );
	$sizes    = $variable ? wc_get_product_terms( $pid, 'pa_taille', array( 'fields' => 'all' ) ) : array();
	$colors   = $variable ? wc_get_product_terms( $pid, 'pa_couleur', array( 'fields' => 'all' ) ) : array();
	$tech     = get_post_meta( $pid, 'at_tech_html', true );
	$livre    = get_post_meta( $pid, 'at_livre_avec_html', true );
	$docs     = at_get_documents( $pid );
	$in_stock = $product->is_in_stock();

	$vardata = array();
	if ( $variable ) {
		foreach ( $product->get_available_variations( 'objects' ) as $v ) {
			$vardata[] = array(
				'id'    => $v->get_id(),
				'attrs' => $v->get_variation_attributes( false ), // ['pa_taille' => slug|'']
				'price' => (float) wc_get_price_to_display( $v ),
				'reg'   => (float) wc_get_price_to_display( $v, array( 'price' => $v->get_regular_price() ) ),
				'stock' => $v->is_in_stock(),
			);
		}
	}
	$js = array(
		'pid'       => $pid,
		'url'       => $product->get_permalink(),
		'variable'  => $variable,
		'variations'=> $vardata,
	);
?>
<main class="at-shop at-single" data-at-product="<?php echo esc_attr( wp_json_encode( $js ) ); ?>">

<section class="at-shop-wrap at-buy">
	<?php
	at_breadcrumb( array_filter( array(
		array( 'Accueil', home_url( '/' ) ),
		array( 'Boutique', wc_get_page_permalink( 'shop' ) ),
		$cat ? array( html_entity_decode( $cat->name ), get_term_link( $cat ) ) : null,
		array( $product->get_name(), '' ),
	) ) );
	?>
	<div class="at-buy-grid">
		<div class="at-gallery">
			<div class="at-gallery-main">
				<?php foreach ( $imgs as $i => $img_id ) : ?>
					<img class="at-shot" data-shot="<?php echo (int) $i; ?>" <?php echo $i ? 'hidden loading="lazy"' : ''; ?> src="<?php echo esc_url( wp_get_attachment_image_url( $img_id, 'woocommerce_single' ) ); ?>" alt="<?php echo esc_attr( $product->get_name() . ' — photo ' . ( $i + 1 ) ); ?>" />
				<?php endforeach; ?>
				<?php if ( count( $imgs ) > 1 ) : ?>
				<button type="button" class="at-gal-nav at-gal-prev" aria-label="Photo précédente"><i class="ri-arrow-left-s-line"></i></button>
				<button type="button" class="at-gal-nav at-gal-next" aria-label="Photo suivante"><i class="ri-arrow-right-s-line"></i></button>
				<?php endif; ?>
				<button type="button" class="at-gal-zoom" aria-label="Agrandir la photo"><i class="ri-expand-diagonal-line"></i></button>
				<?php echo at_product_badge( $product ); // phpcs:ignore ?>
			</div>
			<?php if ( count( $imgs ) > 1 ) : ?>
			<div class="at-thumbs">
				<?php foreach ( $imgs as $i => $img_id ) : ?>
				<button type="button" class="at-thumb<?php echo $i ? '' : ' is-on'; ?>" data-shot="<?php echo (int) $i; ?>" aria-label="Voir la photo <?php echo (int) ( $i + 1 ); ?>">
					<img src="<?php echo esc_url( wp_get_attachment_image_url( $img_id, 'woocommerce_gallery_thumbnail' ) ); ?>" alt="" loading="lazy" />
				</button>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>

		<div class="at-panel">
			<?php if ( $brand ) : ?><span class="at-eyebrow"><?php echo esc_html( $brand ); ?></span><?php endif; ?>
			<h1 class="at-h1"><?php echo at_bicolor( $product->get_name() ); // phpcs:ignore ?></h1>
			<div class="at-chapo"><?php echo wp_kses_post( $product->get_short_description() ); ?></div>

			<div class="at-more">
				<span class="at-eyebrow">En savoir plus</span>
				<div class="at-more-pills">
					<a href="#description">Description</a>
					<a href="#tech">Données techniques</a>
					<a href="#livre-avec">Livré avec</a>
					<a href="#documents">Documents</a>
				</div>
			</div>

			<?php if ( $sizes ) : ?>
			<div class="at-opt">
				<b class="at-opt-label">Taille</b>
				<div class="at-sizes" role="group" aria-label="Choisir la taille">
					<?php foreach ( $sizes as $i => $t ) : ?>
					<button type="button" class="at-size<?php echo $i ? '' : ' is-on'; ?>" data-value="<?php echo esc_attr( $t->slug ); ?>" data-label="<?php echo esc_attr( $t->name ); ?>" aria-pressed="<?php echo $i ? 'false' : 'true'; ?>"><?php echo esc_html( $t->name ); ?></button>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( $colors ) : ?>
			<div class="at-opt">
				<b class="at-opt-label">Coloris</b>
				<div class="at-swatches" role="group" aria-label="Choisir le coloris">
					<?php foreach ( $colors as $i => $t ) : $sw = get_term_meta( $t->term_id, 'at_swatch', true ); ?>
					<button type="button" class="at-swatch<?php echo $i ? '' : ' is-on'; ?>" data-value="<?php echo esc_attr( $t->slug ); ?>" data-label="<?php echo esc_attr( $t->name ); ?>" aria-pressed="<?php echo $i ? 'false' : 'true'; ?>" aria-label="Coloris <?php echo esc_attr( $t->name ); ?>" style="background:<?php echo esc_attr( $sw ?: '#D8D4CD' ); ?>"></button>
					<?php endforeach; ?>
					<span class="at-swatch-label" aria-live="polite"><?php echo esc_html( $colors[0]->name ); ?></span>
				</div>
			</div>
			<?php endif; ?>

			<div class="at-stockrow">
				<?php if ( $in_stock ) : ?>
				<span class="at-stock at-stock--in"><i class="ri-checkbox-circle-fill"></i>En stock à Vence</span>
				<span class="at-stock-note">Expédition sous 24/48 h · retrait à l'atelier possible</span>
				<?php else : ?>
				<span class="at-stock at-stock--out">Sur commande</span>
				<span class="at-stock-note">Délai communiqué à la commande</span>
				<?php endif; ?>
			</div>

			<div class="at-pricerow">
				<b class="at-price" data-at-price><?php echo wc_price( wc_get_price_to_display( $product, array( 'price' => $product->get_price() ) ) ); // phpcs:ignore ?></b>
				<s class="at-price-old" data-at-old hidden></s>
				<span class="at-price-off" data-at-off hidden></span>
			</div>
			<span class="at-price-note">TTC, TVA incluse · livraison offerte en France métropolitaine</span>

			<div class="at-buyrow">
				<div class="at-qty">
					<button type="button" class="at-qty-dec" aria-label="Diminuer la quantité"><i class="ri-subtract-line"></i></button>
					<span class="at-qty-val">1</span>
					<button type="button" class="at-qty-inc" aria-label="Augmenter la quantité"><i class="ri-add-line"></i></button>
				</div>
				<button type="button" class="at-add" data-at-add>
					<span data-lbl="a"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.4"/><circle cx="18.4" cy="20" r="1.4"/><path d="M2 3h2.6l2.4 11.2a1.8 1.8 0 0 0 1.8 1.4h9.3a1.8 1.8 0 0 0 1.8-1.4L21.5 7H6"/></svg>Ajouter au panier</span>
					<span data-lbl="c" aria-hidden="true"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.4"/><circle cx="18.4" cy="20" r="1.4"/><path d="M2 3h2.6l2.4 11.2a1.8 1.8 0 0 0 1.8 1.4h9.3a1.8 1.8 0 0 0 1.8-1.4L21.5 7H6"/></svg>Ajouter au panier</span>
					<span data-lbl="b" aria-hidden="true"><i class="ri-check-line"></i>Ajouté au panier</span>
				</button>
			</div>
			<p class="at-add-error" data-at-error hidden>Impossible d'ajouter au panier. Réessayez ou appelez-nous.</p>

			<ul class="at-perks">
				<li><i class="ri-flight-takeoff-line"></i><span>Essai possible sur le site de Gréolières avant l'achat</span></li>
				<li><i class="ri-tools-line"></i><span>Réglage et contrôle du suspentage à l'atelier avant expédition</span></li>
				<li><i class="ri-arrow-go-back-line"></i><span>Retour sous 14 jours si la voile n'a pas volé</span></li>
			</ul>

		</div>
	</div>
</section>

<div class="at-anchors" data-at-anchors>
	<nav class="at-shop-wrap" aria-label="Sommaire de la fiche">
		<a href="#description" data-a="description">Description</a>
		<a href="#tech" data-a="tech">Données techniques</a>
		<a href="#livre-avec" data-a="livre-avec">Livré avec</a>
		<a href="#documents" data-a="documents">Documents</a>
	</nav>
</div>

<section id="description" class="at-shop-wrap at-section">
	<div class="at-2col">
		<div>
			<span class="at-eyebrow">Description</span>
			<h2 class="at-h2"><?php echo at_bicolor( $product->get_name() ); // phpcs:ignore ?></h2>
			<div class="at-tags">
				<?php
				$feat = array();
				if ( $cat ) { $feat[] = html_entity_decode( $cat->name ); }
				foreach ( array_slice( $sizes, 0, 1 ) as $t ) { $feat[] = count( $sizes ) . ' tailles'; }
				foreach ( array_slice( $colors, 0, 1 ) as $t ) { $feat[] = count( $colors ) . ' coloris'; }
				foreach ( $feat as $f ) { echo '<span>' . esc_html( $f ) . '</span>'; }
				?>
			</div>
		</div>
		<div class="at-richtext"><?php the_content(); ?></div>
	</div>
</section>

<?php if ( $tech ) : ?>
<section id="tech" class="at-section at-band at-band--soft">
	<div class="at-shop-wrap">
		<span class="at-eyebrow">Données techniques</span>
		<h2 class="at-h2">Tailles &amp; <em>caractéristiques</em></h2>
		<div class="at-tech-table"><?php echo wp_kses_post( $tech ); ?></div>
		<span class="at-note">Valeurs constructeur.</span>
	</div>
</section>
<?php endif; ?>

<?php if ( $livre ) : ?>
<section id="livre-avec" class="at-shop-wrap at-section">
	<div class="at-2col">
		<div>
			<span class="at-eyebrow">Livré avec</span>
			<h2 class="at-h2">Ce que vous recevez <em>dans le carton</em></h2>
		</div>
		<div class="at-included"><?php echo wp_kses_post( $livre ); ?></div>
	</div>
</section>
<?php endif; ?>

<section id="documents" class="at-section at-band at-band--soft">
	<div class="at-shop-wrap">
		<span class="at-eyebrow">Documents &amp; notices</span>
		<h2 class="at-h2">Manuel, homologation et <em>guides</em></h2>
		<div class="at-docs">
			<?php if ( $docs ) : foreach ( $docs as $d ) :
				$ext   = $d['url'] ? strtoupper( pathinfo( wp_parse_url( $d['url'], PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) ) : '';
				$inner = '<i class="ri-file-text-line"></i><span><b>' . esc_html( $d['title'] ) . '</b>' . ( $ext ? '<span>' . esc_html( $ext ) . '</span>' : '' ) . '</span>';
				if ( ! empty( $d['url'] ) ) {
					echo '<a class="at-doc" href="' . esc_url( $d['url'] ) . '" target="_blank" rel="noopener">' . $inner . '</a>'; // phpcs:ignore
				} else {
					echo '<div class="at-doc">' . $inner . '</div>'; // phpcs:ignore
				}
			endforeach; endif; ?>
		</div>
	</div>
</section>

<?php
$related = array_filter( array_map( 'wc_get_product', wc_get_related_products( $pid, 4 ) ) );
if ( $related ) : ?>
<section class="at-shop-wrap at-section">
	<div class="at-related-head">
		<div>
			<span class="at-eyebrow">À comparer</span>
			<h2 class="at-h2">Dans la même <em>catégorie</em></h2>
		</div>
		<?php if ( $cat ) : ?><a class="at-link" href="<?php echo esc_url( get_term_link( $cat ) ); ?>">Tous les produits<i class="ri-arrow-right-line"></i></a><?php endif; ?>
	</div>
	<div class="at-grid at-grid--related">
		<?php foreach ( $related as $r ) { at_product_card( $r, 'related' ); } ?>
	</div>
</section>
<?php endif; ?>

<section class="at-band at-band--subtle">
	<div class="at-shop-wrap">
		<div class="at-reassure at-reassure--3">
			<div class="at-tile"><i class="ri-shield-check-line"></i><b>Contrôlée avant expédition</b><span>Suspentage mesuré, calage vérifié et fiche de contrôle jointe : la voile part réglée de notre atelier.</span></div>
			<div class="at-tile"><i class="ri-tools-line"></i><b>Révision &amp; SAV sur place</b><span>Paracheck, réparations et pièces d'origine : la même main qui vous conseille suit votre aile dans le temps.</span></div>
			<div class="at-tile"><i class="ri-arrow-go-back-line"></i><b>14 jours pour changer d'avis</b><span>Retour accepté si la voile n'a pas volé et revient complète dans son emballage d'origine.</span></div>
		</div>
	</div>
</section>

<?php at_conseil_band( 'Pas sûr que ce soit <em>pour vous</em> ?' ); ?>

<div class="at-mobilebar" data-at-mobilebar>
	<div class="at-mb-row1">
		<?php foreach ( $sizes as $i => $t ) : ?>
		<button type="button" class="at-size at-size--sm<?php echo $i ? '' : ' is-on'; ?>" data-value="<?php echo esc_attr( $t->slug ); ?>" data-label="<?php echo esc_attr( $t->name ); ?>" aria-pressed="<?php echo $i ? 'false' : 'true'; ?>"><?php echo esc_html( $t->name ); ?></button>
		<?php endforeach; ?>
		<?php if ( $sizes && $colors ) : ?><span class="at-mb-sep"></span><?php endif; ?>
		<?php foreach ( $colors as $i => $t ) : $sw = get_term_meta( $t->term_id, 'at_swatch', true ); ?>
		<button type="button" class="at-swatch-hit" data-value="<?php echo esc_attr( $t->slug ); ?>" aria-label="Coloris <?php echo esc_attr( $t->name ); ?>"><span class="at-swatch at-swatch--sm<?php echo $i ? '' : ' is-on'; ?>" data-value="<?php echo esc_attr( $t->slug ); ?>" style="background:<?php echo esc_attr( $sw ?: '#D8D4CD' ); ?>"></span></button>
		<?php endforeach; ?>
		<span class="at-qty at-qty--sm">
			<button type="button" class="at-qty-dec" aria-label="Diminuer la quantité"><i class="ri-subtract-line"></i></button>
			<span class="at-qty-val">1</span>
			<button type="button" class="at-qty-inc" aria-label="Augmenter la quantité"><i class="ri-add-line"></i></button>
		</span>
	</div>
	<div class="at-mb-row2">
		<span class="at-mb-price">
			<b data-at-price><?php echo wc_price( wc_get_price_to_display( $product, array( 'price' => $product->get_price() ) ) ); // phpcs:ignore ?></b>
			<span class="at-mb-range" aria-live="polite"></span>
		</span><?php // .at-mb-range affiche la taille sélectionnée ?>
		<button type="button" class="at-add at-add--sm" data-at-add>
			<span data-lbl="a"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.4"/><circle cx="18.4" cy="20" r="1.4"/><path d="M2 3h2.6l2.4 11.2a1.8 1.8 0 0 0 1.8 1.4h9.3a1.8 1.8 0 0 0 1.8-1.4L21.5 7H6"/></svg>Ajouter au panier</span>
			<span data-lbl="c" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.4"/><circle cx="18.4" cy="20" r="1.4"/><path d="M2 3h2.6l2.4 11.2a1.8 1.8 0 0 0 1.8 1.4h9.3a1.8 1.8 0 0 0 1.8-1.4L21.5 7H6"/></svg>Ajouter au panier</span>
			<span data-lbl="b" aria-hidden="true"><i class="ri-check-line"></i>Ajouté au panier</span>
		</button>
	</div>
</div>

<div class="at-lightbox" data-at-lightbox hidden><button type="button" class="at-lb-close" aria-label="Fermer"><i class="ri-close-line"></i></button><img src="" alt="" /></div>

</main>
<?php
endwhile;
get_footer();
