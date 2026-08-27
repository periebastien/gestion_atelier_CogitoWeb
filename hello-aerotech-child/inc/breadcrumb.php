<?php
/**
 * Fil d'ariane unique du site (boutique, pages Elementor, pages simples).
 *
 * Un seul rendu pour tout le site : même markup, même CSS (bloc AT-CRUMB du
 * kit 6, chargé partout), et des microdonnées schema.org/BreadcrumbList pour
 * que les robots comprennent la hiérarchie.
 *
 * Trois portes d'entrée :
 *   at_breadcrumb( $links )   — echo, utilisée par les gabarits WooCommerce
 *   at_crumb_html( $links )   — retourne le HTML
 *   [at_crumb]                — shortcode (pages Elementor), fil déduit du contexte
 *
 * @package HelloAerotechChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chevron de séparation.
 *
 * SVG inline et non police d'icônes : le fil d'ariane s'affiche sur des pages
 * où Remix Icon n'est pas chargé (panier, commande, pages Elementor).
 *
 * @return string
 */
function at_crumb_sep() {
	return '<svg class="at-crumb-sep" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6"/></svg>';
}

/**
 * Rend un fil d'ariane.
 *
 * @param array $links Liste de couples array( libellé, url ). L'url du dernier
 *                     élément peut être vide : c'est la page courante.
 * @return string
 */
function at_crumb_html( $links ) {
	$links = array_values( array_filter( (array) $links ) );
	if ( count( $links ) < 2 ) {
		return '';
	}

	$out  = '<nav class="at-crumb" aria-label="Fil d\'ariane" itemscope itemtype="https://schema.org/BreadcrumbList">';
	$last = count( $links ) - 1;

	foreach ( $links as $i => $l ) {
		$label = html_entity_decode( (string) $l[0], ENT_QUOTES, 'UTF-8' );
		$url   = isset( $l[1] ) ? (string) $l[1] : '';

		if ( $i ) {
			$out .= at_crumb_sep();
		}

		$out .= '<span class="at-crumb-i" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';

		if ( $url && $i !== $last ) {
			$out .= '<a itemprop="item" href="' . esc_url( $url ) . '"><span itemprop="name">' . esc_html( $label ) . '</span></a>';
		} else {
			// Dernier maillon : pas de lien (page courante). schema.org autorise
			// un ListItem sans « item » en fin de fil.
			$out .= '<span itemprop="name"' . ( $i === $last ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</span>';
		}

		$out .= '<meta itemprop="position" content="' . ( $i + 1 ) . '" />';
		$out .= '</span>';
	}

	return $out . '</nav>';
}

/**
 * Fil d'ariane (echo) — signature historique utilisée par les gabarits WooCommerce.
 *
 * @param array $links Voir at_crumb_html().
 * @return void
 */
function at_breadcrumb( $links ) {
	echo at_crumb_html( $links ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped
}

/**
 * Déduit le fil d'ariane du contexte courant (pages et hiérarchie de pages).
 *
 * @param string $last Libellé du dernier maillon (facultatif).
 * @return array
 */
function at_crumb_context( $last = '' ) {
	$links = array( array( 'Accueil', home_url( '/' ) ) );

	$obj = get_queried_object();
	if ( $obj instanceof WP_Post && ! is_front_page() ) {
		foreach ( array_reverse( get_post_ancestors( $obj ) ) as $ancestor_id ) {
			$links[] = array( get_the_title( $ancestor_id ), get_permalink( $ancestor_id ) );
		}
		$links[] = array( $last ? $last : get_the_title( $obj ), '' );
	} elseif ( $last ) {
		$links[] = array( $last, '' );
	}

	return $links;
}

/**
 * Shortcode [at_crumb] — pour les pages construites avec Elementor.
 *
 * Attributs :
 *   last   remplace le libellé du dernier maillon
 *   before couple « Libellé|/url » à insérer avant le dernier maillon
 *          (plusieurs couples séparés par des virgules)
 *
 * @param array $atts Attributs.
 * @return string
 */
function at_crumb_shortcode( $atts ) {
	$atts  = shortcode_atts( array( 'last' => '', 'before' => '' ), $atts, 'at_crumb' );
	$links = at_crumb_context( $atts['last'] );

	if ( $atts['before'] ) {
		$extra = array();
		foreach ( explode( ',', $atts['before'] ) as $pair ) {
			$parts = array_map( 'trim', explode( '|', $pair, 2 ) );
			if ( '' !== $parts[0] ) {
				$extra[] = array( $parts[0], isset( $parts[1] ) ? $parts[1] : '' );
			}
		}
		if ( $extra ) {
			$tail  = array_pop( $links );
			$links = array_merge( $links, $extra, array( $tail ) );
		}
	}

	return at_crumb_html( $links );
}
add_shortcode( 'at_crumb', 'at_crumb_shortcode' );
