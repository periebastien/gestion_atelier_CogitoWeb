<?php
/**
 * Rapports de contrôle PDF — briques communes aux 3 modèles (dompdf).
 *
 * Design unifié white-label : logo + couleur d'accent = réglages
 * WooCommerce > E-mails (rien de codé en dur). Mise en page « à l'ancienne »
 * (tables, pas de flexbox/grid : contrainte dompdf).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feuille de style commune.
 */
function gacct_rpdf_styles( $accent ) {
	$accent = $accent ? $accent : '#20c4c3';

	return '
	@page { margin: 90px 40px 60px 40px; }
	* { box-sizing: border-box; }
	body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #1e293b; margin: 0; }
	.rpdf-header { position: fixed; top: -70px; left: 0; right: 0; height: 60px; }
	.rpdf-footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 8px; color: #64748b; border-top: 1px solid ' . $accent . '; padding-top: 4px; }
	h1 { font-size: 15px; margin: 0 0 2px; color: ' . $accent . '; }
	h2 { font-size: 11px; margin: 14px 0 5px; padding: 4px 8px; background: ' . $accent . '; color: #fff; border-radius: 3px; }
	h3 { font-size: 10px; margin: 8px 0 3px; color: #0f172a; }
	p { margin: 4px 0; }
	.muted { color: #64748b; font-size: 8.5px; }
	table { width: 100%; border-collapse: collapse; margin: 4px 0; }
	table.rpdf-clean td, table.rpdf-clean th { border: 0; padding: 2px 4px; }
	table.rpdf-data td, table.rpdf-data th { border: 1px solid #cbd5e1; padding: 3px 6px; font-size: 9px; }
	table.rpdf-data th { background: #f1f5f9; text-align: left; }
	.rpdf-badge { display: inline-block; padding: 2px 8px; border-radius: 8px; font-weight: bold; font-size: 9px; }
	.b-reforme { background: #fee2e2; color: #991b1b; }
	.b-limite { background: #ffedd5; color: #9a3412; }
	.b-acceptable { background: #fef9c3; color: #854d0e; }
	.b-bon { background: #dcfce7; color: #166534; }
	.b-tresbon { background: #d1fae5; color: #065f46; }
	.b-neuf { background: #cffafe; color: #155e75; }
	.b-na { background: #f1f5f9; color: #64748b; }
	.rpdf-ident td { vertical-align: top; }
	.rpdf-legend { font-size: 7.5px; color: #64748b; margin-top: 2px; }
	.rpdf-general { border: 2px solid ' . $accent . '; border-radius: 6px; padding: 8px 10px; margin: 8px 0; text-align: center; }
	.rpdf-general .rpdf-badge { font-size: 12px; padding: 4px 14px; }
	.rpdf-comment { border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 8px; background: #f8fafc; }
	.rpdf-sign { margin-top: 14px; }
	';
}

/**
 * Classe CSS du badge selon le résultat.
 */
function gacct_rpdf_badge_class( $result ) {
	$map = array(
		'RÉFORME'       => 'b-reforme',
		'LIMITE'        => 'b-limite',
		'ACCEPTABLE'    => 'b-acceptable',
		'BON ÉTAT'      => 'b-bon',
		'CALAGE BON'    => 'b-bon',
		'TRÈS BON ÉTAT' => 'b-tresbon',
		'NEUF'          => 'b-neuf',
	);

	return isset( $map[ $result ] ) ? $map[ $result ] : 'b-na';
}

/**
 * Badge HTML.
 */
function gacct_rpdf_badge( $result ) {
	$label = ( '' === $result || null === $result ) ? 'NON RÉALISÉ' : $result;

	return '<span class="rpdf-badge ' . gacct_rpdf_badge_class( $label ) . '">' . esc_html( $label ) . '</span>';
}

/**
 * Légende de seuils (petite ligne sous un titre de section).
 */
function gacct_rpdf_legend( array $legend ) {
	$parts = array();
	foreach ( $legend as $label => $range ) {
		$parts[] = esc_html( $label . ' : ' . $range );
	}

	return '<p class="rpdf-legend">' . implode( ' &nbsp;·&nbsp; ', $parts ) . '</p>';
}

/**
 * En-tête fixe (logo + titre du document) + bloc identification.
 *
 * @param array  $context  gacct_report_pdf_context().
 * @param string $title    Titre du document (propre au type/modèle).
 * @param string $intro    Texte normatif d'introduction (propre au type).
 * @param string $subline  Phrase d'accueil sous l'identification.
 * @param array  $extra_id Lignes supplémentaires du bloc « matériel » (label => valeur).
 * @param bool   $wing     Afficher le bloc voile (marque/modèle/…).
 */
function gacct_rpdf_head( array $context, $title, $intro, $subline = '', $wing = true ) {
	$ident = $context['ident'];

	echo '<div class="rpdf-header"><table class="rpdf-clean"><tr>';
	echo '<td style="width:40%;">';
	if ( $context['logo_path'] ) {
		echo '<img src="' . esc_attr( $context['logo_path'] ) . '" style="max-height:52px; max-width:200px;">';
	} elseif ( $context['logo_url'] ) {
		echo '<img src="' . esc_attr( $context['logo_url'] ) . '" style="max-height:52px; max-width:200px;">';
	} else {
		echo '<strong style="font-size:14px;">' . esc_html( $context['site_name'] ) . '</strong>';
	}
	echo '</td>';
	echo '<td style="width:60%; text-align:right; vertical-align:middle;">';
	echo '<h1>' . esc_html( $title ) . '</h1>';
	echo '<span class="muted">' . esc_html__( 'Adhérent à la charte ParachecK®', 'gestion-atelier-cct' ) . '</span>';
	echo '</td></tr></table></div>';

	echo '<div class="rpdf-footer"><table class="rpdf-clean"><tr>';
	echo '<td>' . esc_html( $context['site_name'] ) . '</td>';
	echo '<td style="text-align:right;">' . esc_html( sprintf( __( 'Rapport n° %1$s — édité le %2$s', 'gestion-atelier-cct' ), $context['number'], $context['date'] ) ) . '</td>';
	echo '</tr></table></div>';

	// Intro normative.
	if ( $intro ) {
		echo '<p class="muted" style="font-size:8.5px;">' . esc_html( $intro ) . '</p>';
	}

	// Identification : pilote | rapport | voile.
	echo '<table class="rpdf-clean rpdf-ident"><tr>';

	echo '<td style="width:50%; padding-right:10px;">';
	echo '<h2>' . esc_html__( 'PILOTE', 'gestion-atelier-cct' ) . '</h2>';
	echo '<table class="rpdf-data">';
	echo '<tr><th style="width:32%;">' . esc_html__( 'Nom', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['nom'] ) . '</td></tr>';
	echo '<tr><th>' . esc_html__( 'Prénom', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['prenom'] ) . '</td></tr>';
	echo '<tr><th>' . esc_html__( 'Contact', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['contact'] ) . '</td></tr>';
	echo '</table>';
	echo '</td>';

	echo '<td style="width:50%;">';
	echo '<h2>' . esc_html__( 'RAPPORT', 'gestion-atelier-cct' ) . '</h2>';
	echo '<table class="rpdf-data">';
	echo '<tr><th style="width:32%;">' . esc_html__( 'Rapport n°', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $context['number'] ) . '</td></tr>';
	echo '<tr><th>' . esc_html__( 'Édité le', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $context['date'] ) . '</td></tr>';
	echo '<tr><th>' . esc_html__( 'Auteur', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $context['author'] ) . '</td></tr>';
	echo '</table>';
	echo '</td>';

	echo '</tr></table>';

	if ( $wing ) {
		echo '<h2>' . esc_html__( 'VOILE', 'gestion-atelier-cct' ) . '</h2>';
		echo '<table class="rpdf-data"><tr>';
		echo '<th style="width:16%;">' . esc_html__( 'Marque', 'gestion-atelier-cct' ) . '</th><td style="width:17%;">' . esc_html( $ident['marque'] ) . '</td>';
		echo '<th style="width:16%;">' . esc_html__( 'Modèle', 'gestion-atelier-cct' ) . '</th><td style="width:17%;">' . esc_html( $ident['modele'] ) . '</td>';
		echo '<th style="width:16%;">' . esc_html__( 'Taille', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['taille'] ) . '</td>';
		echo '</tr><tr>';
		echo '<th>' . esc_html__( 'N° série', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['serie'] ) . '</td>';
		echo '<th>' . esc_html__( 'Couleur', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['couleur'] ) . '</td>';
		echo '<th>' . esc_html__( 'PTV', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['ptv'] ) . '</td>';
		echo '</tr></table>';
	}

	if ( $subline ) {
		echo '<p>' . esc_html( $subline ) . '</p>';
	}
}

/**
 * Bloc signature (nom de l'auteur — signature image reportée, décision client
 * en attente).
 */
function gacct_rpdf_signature( array $context ) {
	echo '<table class="rpdf-clean rpdf-sign"><tr>';
	echo '<td style="width:60%;"></td>';
	echo '<td style="width:40%; text-align:center;">';
	echo '<p class="muted">' . esc_html__( 'Signature', 'gestion-atelier-cct' ) . '</p>';
	echo '<p style="font-weight:bold; font-size:11px;">' . esc_html( $context['author'] ) . '</p>';
	echo '</td>';
	echo '</tr></table>';
}

/**
 * Nombre affiché à la française.
 */
function gacct_rpdf_num( $value, $decimals = 1 ) {
	if ( null === $value || '' === $value ) {
		return '—';
	}

	return rtrim( rtrim( number_format( (float) $value, $decimals, ',', ' ' ), '0' ), ',' );
}
