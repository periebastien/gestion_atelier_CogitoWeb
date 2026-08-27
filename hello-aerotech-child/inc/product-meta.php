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
