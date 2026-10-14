<?php
/**
 * Champs produit AEROTECH — helpers de lecture.
 * Les meta boxes sont définies dans JetEngine (option jet_engine_meta_boxes,
 * entrées meta-100 « Fiche produit AEROTECH » et meta-101 « Pastille couleur »),
 * visibles et éditables dans JetEngine → Meta Boxes.
 */
defined( 'ABSPATH' ) || exit;

/**
 * Normalise at_documents (repeater : doc_title + doc_url media) → liste [title, url].
 * Le champ media peut stocker une URL ou un ID d'attachement selon le réglage.
 */
function at_get_documents( $pid ) {
	$raw = get_post_meta( $pid, 'at_documents', true );
	$out = array();
	if ( ! is_array( $raw ) ) { return $out; }
	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) || empty( $row['doc_title'] ) ) { continue; }
		$url = $row['doc_url'] ?? '';
		if ( is_array( $url ) ) { $url = $url['url'] ?? ( isset( $url['id'] ) ? wp_get_attachment_url( $url['id'] ) : '' ); }
		if ( is_numeric( $url ) ) { $url = wp_get_attachment_url( (int) $url ); }
		$out[] = array( 'title' => $row['doc_title'], 'url' => $url ?: '' );
	}
	return $out;
}

/**
 * Termes d'un attribut de produit, dans l'ordre défini sur la fiche produit
 * (onglet Attributs). wc_get_product_terms() ignore cet ordre : il trie par
 * la meta order_<taxonomie>, globale au site.
 */
function at_attr_terms( $product, $taxonomy ) {
	$attrs = $product->get_attributes();
	if ( empty( $attrs[ $taxonomy ] ) || ! $attrs[ $taxonomy ]->is_taxonomy() ) {
		return array();
	}
	$terms = array();
	foreach ( $attrs[ $taxonomy ]->get_options() as $tid ) {
		$t = get_term( (int) $tid, $taxonomy );
		if ( $t && ! is_wp_error( $t ) ) { $terms[] = $t; }
	}
	usort(
		$terms,
		function ( $a, $b ) {
			$oa = (int) get_term_meta( $a->term_id, 'order', true );
			$ob = (int) get_term_meta( $b->term_id, 'order', true );
			return $oa === $ob ? strcmp( $a->name, $b->name ) : $oa - $ob;
		}
	);
	return $terms;
}
