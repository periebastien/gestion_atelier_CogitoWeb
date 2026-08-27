<?php
/**
 * AEROTECH — page catégorie / liste boutique (maquette ProductListing.dc.html).
 */
defined( 'ABSPATH' ) || exit;
get_header();

global $wp_query;
$is_cat  = is_product_category();
$term    = $is_cat ? get_queried_object() : null;
$total   = (int) $wp_query->found_posts;
$paged   = max( 1, (int) get_query_var( 'paged' ) );
$per     = (int) $wp_query->get( 'posts_per_page' );
$shown   = min( $paged * $per, $total );
$title   = $term ? $term->name : 'Le magasin';
$chapo   = "Toutes les marques que nous distribuons, testées sur notre site de Gréolières. Nos conseils sont ceux d'un concepteur de voiles, pas d'un vendeur : dites-nous votre niveau et votre programme de vol, nous vous orientons.";
$orderby = isset( $_GET['orderby'] ) ? wc_clean( wp_unslash( $_GET['orderby'] ) ) : 'menu_order';
$sorts   = array(
	'menu_order' => 'Pertinence',
	'date'       => 'Nouveautés',
	'price'      => 'Prix croissant',
	'price-desc' => 'Prix décroissant',
);
$nav     = at_cat_pills( $term );
?>
<main class="at-shop">

<section class="at-shop-wrap at-cat-head">
	<?php
	at_breadcrumb( array_filter( array(
		array( 'Accueil', home_url( '/' ) ),
		array( 'Boutique', wc_get_page_permalink( 'shop' ) ),
		$term && $term->parent ? array( get_term( $term->parent )->name, get_term_link( (int) $term->parent ) ) : null,
		array( $title, '' ),
	) ) );
	?>
	<h1 class="at-h1"><?php echo at_bicolor( $title ); // phpcs:ignore ?></h1>
	<?php if ( ! $is_cat ) : ?><p class="at-chapo"><?php echo esc_html( $chapo ); ?></p><?php endif; ?>
	<?php if ( $nav['lead'] || $nav['items'] ) : ?>
	<div class="at-cat-pills">
		<?php
		$pill = function ( $t ) use ( $term ) {
			$name = esc_html( html_entity_decode( $t->name ) );
			if ( $term && (int) $term->term_id === (int) $t->term_id ) {
				echo '<span class="at-pill at-pill--on">' . $name . '</span>';
			} else {
				echo '<a class="at-pill" href="' . esc_url( get_term_link( $t ) ) . '">' . $name . '</a>';
			}
		};
		if ( $nav['lead'] ) { $pill( $nav['lead'] ); }
		foreach ( $nav['items'] as $t ) { $pill( $t ); }
		?>
	</div>
	<?php endif; ?>
</section>

<section class="at-shop-wrap at-listing">
	<div class="at-toolbar">
		<span class="at-toolbar-count"><b><?php echo esc_html( min( $per, $total - ( $paged - 1 ) * $per ) ); ?></b> produits affichés sur <?php echo esc_html( $total ); ?></span>
		<?php if ( $total > 1 ) : ?>
		<form class="at-sort" method="get">
			<label>Trier par
				<select name="orderby" onchange="this.form.submit()">
					<?php foreach ( $sorts as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $orderby, $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</form>
		<?php endif; ?>
	</div>

	<?php if ( woocommerce_product_loop() ) : ?>
	<div class="at-grid" data-at-reveal>
		<?php
		while ( have_posts() ) :
			the_post();
			$product = wc_get_product( get_the_ID() );
			if ( $product ) { at_product_card( $product ); }
		endwhile;
		?>
	</div>
	<div class="at-loadmore">
		<span class="at-progress"><span style="width:<?php echo esc_attr( $total ? round( 100 * $shown / $total ) : 100 ); ?>%"></span></span>
		<span class="at-loadmore-count"><?php echo esc_html( $shown ); ?> produits sur <?php echo esc_html( $total ); ?></span>
		<?php if ( $shown < $total ) : ?>
		<a class="at-btn at-btn--outline" href="<?php echo esc_url( next_posts( 0, false ) ); ?>">Voir plus de produits<i class="ri-arrow-down-line"></i></a>
		<?php endif; ?>
	</div>
	<?php else : ?>
	<div class="at-empty">
		<p>Aucun produit ne correspond. Dites-nous votre programme de vol, nous vous orientons.</p>
		<a class="at-btn at-btn--primary" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Nous contacter<i class="ri-arrow-right-line"></i></a>
	</div>
	<?php endif; ?>
</section>

<section class="at-band at-band--subtle">
	<div class="at-shop-wrap">
		<span class="at-eyebrow">Acheter chez AEROTECH</span>
		<h2 class="at-h2">Un magasin adossé à un <em>atelier</em></h2>
		<div class="at-reassure">
			<div class="at-tile"><i class="ri-flask-line"></i><b>Conseil de concepteur</b><span>Vingt ans de conception d'ailes : nous savons ce qui se passe au-dessus de votre tête.</span></div>
			<div class="at-tile"><i class="ri-tools-line"></i><b>Atelier intégré</b><span>Révisions Paracheck, réparations et remise au calage sur place, à Gréolières.</span></div>
			<div class="at-tile"><i class="ri-flight-takeoff-line"></i><b>Essais sur site</b><span>Testez la voile avant de l'acheter sur les crêtes du Cheiron, à 1800 m.</span></div>
			<div class="at-tile"><i class="ri-shield-check-line"></i><b>SAV &amp; pièces</b><span>Suspentes, élévateurs, pièces d'origine : votre matériel reste suivi dans le temps.</span></div>
		</div>
	</div>
</section>

<?php at_conseil_band( 'Hésitant entre deux <em>voiles</em> ?' ); ?>

<?php if ( $is_cat ) : at_cat_seo_section( at_cat_seo( $term ) ); else : ?>
<section class="at-shop-wrap at-seo">
	<div class="at-seo-grid">
		<div>
			<span class="at-eyebrow">Notre expertise</span>
			<h2 class="at-h2">Choisir sa voile <em>sans se tromper</em></h2>
			<p><b>AEROTECH</b> conseille les pilotes du sortant de stage au compétiteur, avec le regard d'un concepteur de voiles et l'exigence d'un atelier de révision.</p>
			<div class="at-tags">
				<span>voile de parapente</span><span>homologation EN</span><span>plage de poids</span><span>aile de cross</span><span>voile d'occasion</span><span>paracheck</span><span>essai de voile</span>
			</div>
		</div>
		<div class="at-seo-col">
			<div><h3>Les catégories d'homologation EN</h3><p>Les ailes <b>EN A</b> conviennent à l'école et aux premières années de vol : passives et très tolérantes, elles <b>pardonnent les erreurs</b>. Les <b>EN B</b> couvrent l'immense majorité des pratiquants. La catégorie est large, une B « haute » n'a rien à voir avec une B « basse ».</p></div>
			<div><h3>EN C et EN D : quand y passer ?</h3><p>Ces ailes supposent une pratique régulière, un <b>pilotage actif acquis</b> et l'expérience de la gestion des incidents de vol. Le bon indicateur n'est pas le nombre d'années, mais les <b>heures de vol annuelles</b> et le temps passé en conditions fortes.</p></div>
			<div><h3>Taille et plage de poids</h3><p>Le <b>poids total volant</b> (pilote, sellette, secours, vêtements) doit tomber dans la plage homologuée, idéalement dans son tiers médian. Chargée haut, la voile devient plus rapide et dynamique ; chargée bas, plus lente et plus sensible à la turbulence.</p></div>
		</div>
		<div class="at-seo-col">
			<div><h3>Essayer avant d'acheter</h3><p>Deux ailes de même catégorie ne se pilotent pas de la même façon. Nous proposons l'<b>essai sur le site de Gréolières</b>, à 1800 m : gonflage, décollage, comportement en thermique. Une demi-journée qui évite bien des regrets.</p></div>
			<div><h3>Voile d'occasion : ce que nous vérifions</h3><p>Chaque occasion passe par notre atelier et repart avec un rapport <b>Paracheck</b> complet : porosité, résistance du tissu, <b>mesure du suspentage</b> et remise au calage. Vous savez exactement ce que vous achetez.</p></div>
			<div><h3>Le suivi après l'achat</h3><p>Une voile neuve se garde longtemps si on l'entretient. Nous assurons la <b>révision périodique</b>, les réparations et les pièces d'origine : la même main qui vous a conseillé suit votre aile année après année.</p></div>
		</div>
	</div>
</section>
<?php endif; ?>

</main>
<?php get_footer(); ?>
