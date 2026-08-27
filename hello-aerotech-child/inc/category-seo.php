<?php
/**
 * AEROTECH — navigation et contenu éditorial des catégories boutique.
 *
 * Champs éditables : meta box JetEngine « Contenu SEO catégorie » (meta-102,
 * taxonomie product_cat) — visible directement sur l'écran d'édition d'une
 * catégorie de produits.
 *   at_seo_title  (text)     titre du bloc, le dernier mot passe en orange
 *   at_seo_intro  (wysiwyg)  paragraphe d'introduction
 *   at_seo_tags   (text)     mots-clés séparés par des virgules
 *   at_seo_blocks (repeater) seo_b_title + seo_b_text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pastilles de navigation de la page catégorie.
 *
 * Magasin          → catégories racines.
 * Catégorie parente → ses sous-catégories (elle-même en tête, active).
 * Catégorie sans sous-catégorie → aucune pastille.
 *
 * @param WP_Term|null $term Catégorie courante.
 * @return array{lead:WP_Term|null,items:WP_Term[]}
 */
function at_cat_pills( $term ) {
	$exclude = array_filter( array( (int) get_option( 'default_product_cat' ) ) );

	// Catégories de service du framework atelier (prestations, frais de port,
	// suppléments biplace, réparation) : elles alimentent les queries du
	// formulaire de demande d'intervention, pas la navigation du magasin.
	$slugs_masques = apply_filters( 'at_cat_pills_slugs_masques', array(
		'marques',
		'revisions-controle',
		'frais-de-port',
		'pliages-secours',
		'suspentes-travaux',
		'reparation',
		'supplements-biplace',
	) );

	$children_of = function ( $parent ) use ( $exclude, $slugs_masques ) {
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'parent'     => (int) $parent,
			'hide_empty' => false,
			'orderby'    => 'id',
			'exclude'    => $exclude,
		) );
		if ( is_wp_error( $terms ) || ! $terms ) { return array(); }
		return array_values( array_filter( $terms, function ( $t ) use ( $slugs_masques ) {
			return ! in_array( $t->slug, $slugs_masques, true );
		} ) );
	};

	if ( ! $term ) {
		return array( 'lead' => null, 'items' => $children_of( 0 ) );
	}

	$children = $children_of( $term->term_id );
	if ( ! $children ) {
		return array( 'lead' => null, 'items' => array() );
	}

	return array( 'lead' => $term, 'items' => $children );
}

/**
 * Contenu SEO d'une catégorie, hérité du parent si la catégorie n'a rien.
 *
 * @param WP_Term|null $term Catégorie courante.
 * @return array|null
 */
function at_cat_seo( $term ) {
	while ( $term instanceof WP_Term ) {
		$title  = (string) get_term_meta( $term->term_id, 'at_seo_title', true );
		$intro  = (string) get_term_meta( $term->term_id, 'at_seo_intro', true );
		$blocks = get_term_meta( $term->term_id, 'at_seo_blocks', true );

		if ( '' !== trim( $title ) || '' !== trim( $intro ) || ( is_array( $blocks ) && $blocks ) ) {
			$out = array(
				'title'  => $title,
				'intro'  => $intro,
				'tags'   => array_values( array_filter( array_map(
					'trim',
					explode( ',', (string) get_term_meta( $term->term_id, 'at_seo_tags', true ) )
				) ) ),
				'blocks' => array(),
			);
			foreach ( (array) $blocks as $b ) {
				if ( ! is_array( $b ) ) { continue; }
				$bt = trim( (string) ( $b['seo_b_title'] ?? '' ) );
				$bx = trim( (string) ( $b['seo_b_text'] ?? '' ) );
				if ( '' === $bt && '' === $bx ) { continue; }
				$out['blocks'][] = array( 'title' => $bt, 'text' => $bx );
			}
			return $out;
		}

		$term = $term->parent ? get_term( (int) $term->parent, 'product_cat' ) : null;
		if ( is_wp_error( $term ) ) { $term = null; }
	}

	return null;
}

/**
 * Titre de bloc : la partie entre astérisques passe en orange.
 * Sans astérisques, on garde le comportement par défaut (dernier mot coloré).
 *
 * @param string $title Titre saisi.
 * @return string HTML.
 */
function at_seo_title_html( $title ) {
	$title = trim( wp_strip_all_tags( (string) $title ) );
	if ( false === strpos( $title, '*' ) ) { return at_bicolor( $title ); }
	$parts = explode( '*', $title );
	$html  = '';
	foreach ( $parts as $i => $part ) {
		$html .= ( $i % 2 ) ? '<em>' . esc_html( $part ) . '</em>' : esc_html( $part );
	}
	return $html;
}

/**
 * Rendu de la bande éditoriale (colonne d'intro + 2 colonnes de blocs).
 *
 * @param array|null $seo Retour de at_cat_seo().
 */
function at_cat_seo_section( $seo ) {
	if ( ! $seo ) { return; }

	$blocks = $seo['blocks'];
	$half   = (int) ceil( count( $blocks ) / 2 );
	$cols   = $blocks ? array( array_slice( $blocks, 0, $half ), array_slice( $blocks, $half ) ) : array();
	?>
	<section class="at-shop-wrap at-seo">
		<div class="at-seo-grid">
			<div>
				<span class="at-eyebrow">Notre expertise</span>
				<?php if ( '' !== trim( (string) $seo['title'] ) ) : ?>
				<h2 class="at-h2"><?php echo at_seo_title_html( $seo['title'] ); // phpcs:ignore ?></h2>
				<?php endif; ?>
				<?php echo wp_kses_post( wpautop( $seo['intro'] ) ); ?>
				<?php if ( $seo['tags'] ) : ?>
				<div class="at-tags">
					<?php foreach ( $seo['tags'] as $tag ) : ?><span><?php echo esc_html( $tag ); ?></span><?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>
			<?php foreach ( $cols as $col ) : ?>
			<div class="at-seo-col">
				<?php foreach ( $col as $b ) : ?>
				<div>
					<?php if ( '' !== $b['title'] ) : ?><h3><?php echo esc_html( $b['title'] ); ?></h3><?php endif; ?>
					<?php echo wp_kses_post( wpautop( $b['text'] ) ); ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</section>
	<?php
}
