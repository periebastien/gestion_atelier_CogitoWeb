<?php
/**
 * Rapports de contrôle PDF — briques communes (dompdf).
 *
 * Deux générations cohabitent :
 *  - gacct_rp2_* : design system v2 validé le 31/07/2026 (rapport voile) —
 *    traits fins 0,5 pt, bandeaux teal mono-couleur, code couleur canonique
 *    des états (gacct_report_state_colors), police configurable (onglet
 *    Configuration > Rapports), pied de page = coordonnées boutique Woo.
 *  - gacct_rpdf_* : helpers v1 encore utilisés par report-equipement.php et
 *    report-suspente.php (design à refondre quand leurs références PDF
 *    seront validées) — passés eux aussi à la police configurée.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
 *  V2 — DESIGN SYSTEM VALIDÉ (rapport voile)
 * ========================================================================== */

/**
 * Pied de page white-label : coordonnées de la boutique WooCommerce +
 * téléphone (Paiements & relances) + email d'expéditeur + domaine du site.
 */
function gacct_rp2_footer_line() {
	$parts = array( get_bloginfo( 'name' ) );

	$address = array_filter( array(
		get_option( 'woocommerce_store_address' ),
		get_option( 'woocommerce_store_address_2' ),
		trim( get_option( 'woocommerce_store_postcode', '' ) . ' ' . get_option( 'woocommerce_store_city', '' ) ),
	) );

	if ( $address ) {
		$parts[] = implode( ', ', $address );
	}

	if ( function_exists( 'gacct_pay_settings' ) ) {
		$pay = gacct_pay_settings();
		if ( ! empty( $pay['contact_phone'] ) ) {
			$parts[] = $pay['contact_phone'];
		}
	}

	$email = get_option( 'woocommerce_email_from_address' );
	if ( $email ) {
		$parts[] = $email;
	}

	$parts[] = wp_parse_url( home_url(), PHP_URL_HOST );

	return apply_filters( 'gacct_report_footer_line', implode( ' — ', array_filter( $parts ) ) );
}

/**
 * Feuille de style v2 (police configurée + variables du design).
 */
function gacct_rp2_styles( $accent ) {
	$accent = $accent ? $accent : '#20c4c3';
	$font   = gacct_report_font_css();

	return $font['css'] . '
	@page { margin: 34px 38px 52px 38px; }
	body { font-family: ' . $font['family'] . '; font-size: 9.3px; color: #1e293b; margin: 0; }
	p { margin: 3px 0; }
	.muted { color:#64748b; font-size:8px; }
	.sym { font-family: "DejaVu Sans", sans-serif; }
	table.data { width:100%; border-collapse:collapse; }
	table.data td, table.data th { border:0.5pt solid #c9d4da; padding:3px 6px; font-size:8.8px; }
	table.data th { background:#f4f8f9; text-align:left; font-weight:bold; color:#334155; }
	.rp2-box { border:0.5pt solid #c9d4da; border-radius:4px; background:#fbfdfe; padding:6px 9px; font-size:9px; }
	.rp2-footer { position:fixed; bottom:-38px; left:0; right:0; font-size:7.6px; color:#64748b; border-top:1pt solid ' . $accent . '; padding-top:4px; text-align:center; }
	';
}

/**
 * Badge d'état (code couleur canonique).
 */
function gacct_rp2_badge( $txt, $key = null ) {
	$colors = gacct_report_state_colors();
	$lookup = $key ? $key : $txt;
	$c      = isset( $colors[ $lookup ] ) ? $colors[ $lookup ] : array( '#eef2f4', '#475569', '#cbd5e1' );

	return '<span style="display:inline-block; padding:2px 9px; border-radius:8px; font-weight:bold; font-size:8.5px; background:' . $c[0] . '; color:' . $c[1] . ';">' . esc_html( $txt ) . '</span>';
}

/**
 * Bandeau de section principal : mono-couleur, texte blanc.
 */
function gacct_rp2_section( $title, $right = '', $accent = '#20c4c3' ) {
	return '<table style="width:100%; border-collapse:collapse; margin:12px 0 5px;"><tr>'
		. '<td style="background:' . $accent . '; color:#fff; font-size:10.5px; font-weight:bold; padding:5px 9px; border-radius:4px;">' . $title
		. ( '' !== $right ? '<span style="float:right; font-weight:normal; font-size:8.5px;">' . $right . '</span>' : '' )
		. '</td></tr></table>';
}

/**
 * Sous-titre de test : pleine largeur, fond teal pâle, résultat à droite.
 */
function gacct_rp2_subsection( $title, $author, $result_badge ) {
	$right = '';
	if ( '' !== $author ) {
		$right .= esc_html__( 'Réalisé par :', 'gestion-atelier-cct' ) . ' ' . esc_html( $author ) . ' &nbsp;&nbsp;';
	}
	$right .= $result_badge;

	return '<table style="width:100%; border-collapse:collapse; margin:10px 0 4px;"><tr>'
		. '<td style="background:#e4f6f6; color:#0e7490; font-size:9.5px; font-weight:bold; padding:4px 9px; border-radius:4px;">' . $title
		. '<span style="float:right; font-weight:normal; color:#334155; font-size:8.5px;">' . $right . '</span>'
		. '</td></tr></table>';
}

/**
 * Légende de barème : lignes alignées SANS traits, libellés au code couleur.
 *
 * @param array  $rows   label => plage.
 * @param string $header Unité en italique au-dessus (s / mesure / marge).
 */
function gacct_rp2_scale_legend( array $rows, $header = '' ) {
	$colors = gacct_report_state_colors();
	$html   = '<table style="width:100%; border-collapse:collapse; font-size:7.6px;">';

	if ( '' !== $header ) {
		$html .= '<tr><td colspan="2" style="padding:1px 4px; color:#64748b; font-style:italic; text-align:right;">' . esc_html( $header ) . '</td></tr>';
	}

	foreach ( $rows as $label => $range ) {
		$c     = isset( $colors[ $label ] ) ? $colors[ $label ] : array( '', '#475569', '' );
		$html .= '<tr><td style="padding:1.5px 4px; font-weight:bold; color:' . $c[1] . '; white-space:nowrap;">' . esc_html( $label ) . '</td>'
			. '<td style="padding:1.5px 4px; text-align:right; color:#334155; white-space:nowrap;">' . esc_html( $range ) . '</td></tr>';
	}

	return $html . '</table>';
}

/**
 * Matrice « État général de la voile » : colonnes NEUF→RÉFORME entièrement
 * colorées, X soutenu dans la case du résultat. Géométrique : cellule
 * fusionnée « X — CALAGE BON » ou colonne RÉFORME.
 *
 * @param array $rows   label => résultat ('' = ligne vide, 'CALAGE BON',
 *                      'NON RÉALISÉ' = vide, ou un état de l'échelle).
 */
function gacct_rp2_matrix( array $rows ) {
	$all = gacct_report_state_colors();

	// Les colonnes suivent l'échelle du pack, du meilleur au pire, au lieu
	// d'une liste figée : un atelier qui retire un échelon (Altitude Révision
	// a retiré NEUF le 27/08/2026) voit la colonne disparaître d'elle-même.
	$echelle = function_exists( 'gacct_report_severity' )
		? array_reverse( gacct_report_severity() )
		: array( 'TRÈS BON ÉTAT', 'BON ÉTAT', 'ACCEPTABLE', 'LIMITE', 'RÉFORME' );

	$scale = array_intersect_key( $all, array_flip( $echelle ) );
	$thin   = 'border:0.5pt solid #c9d4da;';

	$html = '<table style="width:100%; border-collapse:collapse;"><tr><td style="width:24%; border:0;"></td>';

	foreach ( $scale as $label => $c ) {
		$html .= '<td style="width:12.6%; ' . $thin . ' background:' . $c[2] . '; color:#173239; font-size:7.4px; font-weight:bold; text-align:center; padding:4px 2px;">' . esc_html( $label ) . '</td>';
	}
	$html .= '</tr>';

	foreach ( $rows as $row_label => $result ) {
		$html .= '<tr><td style="' . $thin . ' background:#f4f8f9; font-size:8.6px; font-weight:bold; padding:5px 6px;">' . esc_html( $row_label ) . '</td>';

		if ( 'CALAGE BON' === $result ) {
			$html .= '<td colspan="' . max( 1, count( $scale ) - 1 ) . '" style="' . $thin . ' background:' . $all['BON ÉTAT'][0] . '; text-align:center; font-weight:bold; color:' . $all['BON ÉTAT'][1] . '; font-size:9px; padding:5px 2px;">X — CALAGE BON</td>'
				. '<td style="' . $thin . ' background:' . $all['RÉFORME'][0] . ';"></td>';
		} else {
			foreach ( $scale as $label => $c ) {
				$checked = ( $label === $result );
				$html   .= '<td style="' . $thin . ' text-align:center; font-weight:bold; font-size:10.5px; padding:5px 2px; background:' . ( $checked ? $c[2] : $c[0] ) . '; color:#173239;">' . ( $checked ? 'X' : '' ) . '</td>';
			}
		}
		$html .= '</tr>';
	}

	return $html . '</table>';
}

/**
 * En-tête v2 : logos côte à côte (site + badge du pack), titre + intro à
 * droite, puis blocs PILOTE / VOILE / RAPPORT.
 *
 * @param array  $context   gacct_report_pdf_context().
 * @param string $title     Titre du document.
 * @param string $intro     Texte normatif.
 * @param string $badge_img Chemin local du badge (FFVL/ParachecK) ou ''.
 * @param string $sub_logo  Mention centrée sous le logo du site.
 * @param bool   $wing      Afficher le bloc VOILE complet.
 */
function gacct_rp2_head( array $context, $title, $intro, $badge_img = '', $sub_logo = '', $wing = true ) {
	$ident  = $context['ident'];
	$accent = $context['accent'];

	// Pied de page fixe.
	echo '<div class="rp2-footer">' . esc_html( gacct_rp2_footer_line() )
		. '<br>' . esc_html( sprintf( __( 'Rapport n° %1$s — édité le %2$s', 'gestion-atelier-cct' ), $context['number'], $context['date'] ) ) . '</div>';

	// Logos + titre.
	echo '<table style="width:100%; border-collapse:collapse;"><tr>';
	echo '<td style="width:44%; vertical-align:middle;">';
	echo '<table style="border-collapse:collapse;"><tr>';
	echo '<td style="vertical-align:middle; padding-right:14px; text-align:center;">';
	if ( $context['logo_path'] ) {
		echo '<img src="' . esc_attr( $context['logo_path'] ) . '" style="height:84px;">';
	} elseif ( $context['logo_url'] ) {
		echo '<img src="' . esc_attr( $context['logo_url'] ) . '" style="height:84px;">';
	} else {
		echo '<strong style="font-size:15px;">' . esc_html( $context['site_name'] ) . '</strong>';
	}
	if ( '' !== $sub_logo ) {
		echo '<div class="muted" style="margin-top:4px; font-size:8.6px; text-align:center;">' . esc_html( $sub_logo ) . '</div>';
	}
	echo '</td>';
	if ( $badge_img && file_exists( $badge_img ) ) {
		echo '<td style="vertical-align:middle;"><img src="' . esc_attr( $badge_img ) . '" style="height:84px;"></td>';
	}
	echo '</tr></table>';
	echo '</td>';
	echo '<td style="width:56%; vertical-align:middle; padding-left:12px;">';
	echo '<div style="font-size:15px; font-weight:bold; color:' . esc_attr( $accent ) . '; margin-bottom:3px;">' . esc_html( $title ) . '</div>';
	if ( $intro ) {
		echo '<div class="muted">' . esc_html( $intro ) . '</div>';
	}
	echo '</td></tr></table>';

	// PILOTE / VOILE / RAPPORT.
	echo '<table style="width:100%; border-collapse:collapse; margin-top:8px;"><tr>';

	echo '<td style="width:29%; vertical-align:top; padding-right:8px;">';
	echo gacct_rp2_section( esc_html__( 'PILOTE', 'gestion-atelier-cct' ), '', $accent );
	echo '<table class="data">';
	echo '<tr><th style="width:36%;">' . esc_html__( 'Nom', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['nom'] ) . '</td></tr>';
	echo '<tr><th>' . esc_html__( 'Prénom', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['prenom'] ) . '</td></tr>';
	echo '<tr><th>' . esc_html__( 'Contact', 'gestion-atelier-cct' ) . '</th><td style="font-size:7px;">' . esc_html( $ident['contact'] ) . '</td></tr>';
	echo '</table></td>';

	echo '<td style="width:45%; vertical-align:top; padding-right:8px;">';
	if ( $wing ) {
		echo gacct_rp2_section( esc_html__( 'VOILE', 'gestion-atelier-cct' ), '', $accent );
		echo '<table class="data">';
		echo '<tr><th style="width:20%;">' . esc_html__( 'Marque', 'gestion-atelier-cct' ) . '</th><td style="width:30%;">' . esc_html( $ident['marque'] ) . '</td>'
			. '<th style="width:20%;">' . esc_html__( 'Modèle', 'gestion-atelier-cct' ) . '</th><td style="width:30%;">' . esc_html( $ident['modele'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Taille', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['taille'] ) . '</td>'
			. '<th>' . esc_html__( 'Couleur', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['couleur'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'N° série', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['serie'] ) . '</td>'
			. '<th>' . esc_html__( 'PTV', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $ident['ptv'] ) . '</td></tr>';
		echo '</table>';
	}
	echo '</td>';

	echo '<td style="width:26%; vertical-align:top;">';
	echo gacct_rp2_section( esc_html__( 'RAPPORT', 'gestion-atelier-cct' ), '', $accent );
	echo '<table class="data">';
	echo '<tr><th style="width:38%;">' . esc_html__( 'N°', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $context['number'] ) . '</td></tr>';
	echo '<tr><th>' . esc_html__( 'Édité le', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $context['date'] ) . '</td></tr>';
	echo '<tr><th>' . esc_html__( 'Auteur', 'gestion-atelier-cct' ) . '</th><td>' . esc_html( $context['author'] ) . '</td></tr>';
	echo '</table></td>';

	echo '</tr></table>';
}

/**
 * Bloc final : QR enquête qualité (si activé en config) + signature.
 */
function gacct_rp2_signature_qr( array $context ) {
	$settings = gacct_report_settings();
	$qr_path  = gacct_report_qr_png_path();

	echo '<table style="width:100%; border-collapse:collapse; margin-top:14px;"><tr>';

	echo '<td style="width:56%; vertical-align:middle;">';
	if ( $qr_path ) {
		echo '<table style="border-collapse:collapse;"><tr>';
		echo '<td style="vertical-align:middle; padding-right:8px;"><img src="' . esc_attr( $qr_path ) . '" style="width:62px;"></td>';
		echo '<td style="vertical-align:middle; font-size:8.6px; font-weight:bold; color:#0e7490;">' . esc_html( $settings['qr_title'] );
		if ( '' !== trim( (string) $settings['qr_subtext'] ) ) {
			echo '<br><span style="font-weight:normal;" class="muted">' . esc_html( $settings['qr_subtext'] ) . '</span>';
		}
		echo '</td></tr></table>';
	}
	echo '</td>';

	echo '<td style="width:44%; text-align:center; vertical-align:middle;">';
	echo '<span class="muted">' . esc_html__( 'Signature', 'gestion-atelier-cct' ) . '</span><br>';
	// Signature scannée du « Réalisé par » (profil WordPress, gacct-signature.php).
	if ( ! empty( $context['signature_path'] ) ) {
		echo '<img src="' . esc_attr( $context['signature_path'] ) . '" style="height:36px; margin:2px 0;"><br>';
	}
	echo '<span style="font-weight:bold; font-size:11px;">' . esc_html( $context['author'] ) . '</span>';
	echo '</td>';

	echo '</tr></table>';
}

/**
 * Nombre affiché à la française.
 */
function gacct_rp2_num( $value, $decimals = 1 ) {
	if ( null === $value || '' === $value ) {
		return '—';
	}

	$formatted = number_format( (float) $value, $decimals, ',', ' ' );

	// Ne dépouiller que la partie décimale : sans ce garde-fou, « 600 »
	// (0 décimale) perdait ses zéros entiers et devenait « 6 ».
	if ( false !== strpos( $formatted, ',' ) ) {
		$formatted = rtrim( rtrim( $formatted, '0' ), ',' );
	}

	return $formatted;
}

/* =============================================================================
 *  V1 — helpers conservés pour report-equipement.php / report-suspente.php
 *  (design à refondre à la validation de leurs références ; police alignée)
 * ========================================================================== */

/**
 * Feuille de style commune v1 (police configurée depuis le 31/07/2026).
 */
function gacct_rpdf_styles( $accent ) {
	$accent = $accent ? $accent : '#20c4c3';
	$font   = gacct_report_font_css();

	return $font['css'] . '
	@page { margin: 90px 40px 60px 40px; }
	* { box-sizing: border-box; }
	body { font-family: ' . $font['family'] . '; font-size: 9.5px; color: #1e293b; margin: 0; }
	.rpdf-header { position: fixed; top: -70px; left: 0; right: 0; height: 60px; }
	.rpdf-footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 8px; color: #64748b; border-top: 1px solid ' . $accent . '; padding-top: 4px; }
	h1 { font-size: 15px; margin: 0 0 2px; color: ' . $accent . '; }
	h2 { font-size: 11px; margin: 14px 0 5px; padding: 4px 8px; background: ' . $accent . '; color: #fff; border-radius: 3px; }
	h3 { font-size: 10px; margin: 8px 0 3px; color: #0f172a; }
	p { margin: 4px 0; }
	.muted { color: #64748b; font-size: 8.5px; }
	.sym { font-family: "DejaVu Sans", sans-serif; }
	table { width: 100%; border-collapse: collapse; margin: 4px 0; }
	table.rpdf-clean td, table.rpdf-clean th { border: 0; padding: 2px 4px; }
	table.rpdf-data td, table.rpdf-data th { border: 0.5pt solid #c9d4da; padding: 3px 6px; font-size: 9px; }
	table.rpdf-data th { background: #f4f8f9; text-align: left; }
	.rpdf-badge { display: inline-block; padding: 2px 8px; border-radius: 8px; font-weight: bold; font-size: 9px; }
	.b-reforme { background: #fddede; color: #8f1d1d; }
	.b-limite { background: #ffe8cf; color: #8d4a12; }
	.b-acceptable { background: #fdf6cf; color: #7d6410; }
	.b-bon { background: #e1f7d9; color: #2d6b1c; }
	.b-tresbon { background: #d3f2e2; color: #0d6b46; }
	.b-neuf { background: #d9f6fb; color: #0e6b75; }
	.b-na { background: #f1f5f9; color: #64748b; }
	.rpdf-ident td { vertical-align: top; }
	.rpdf-legend { font-size: 7.5px; color: #64748b; margin-top: 2px; }
	.rpdf-general { border: 2px solid ' . $accent . '; border-radius: 6px; padding: 8px 10px; margin: 8px 0; text-align: center; }
	.rpdf-general .rpdf-badge { font-size: 12px; padding: 4px 14px; }
	.rpdf-comment { border: 0.5pt solid #c9d4da; border-radius: 4px; padding: 6px 8px; background: #fbfdfe; }
	.rpdf-sign { margin-top: 14px; }
	';
}

/**
 * Classe CSS du badge selon le résultat (v1).
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
 * Badge HTML (v1).
 */
function gacct_rpdf_badge( $result ) {
	$label = ( '' === $result || null === $result ) ? 'NON RÉALISÉ' : $result;

	return '<span class="rpdf-badge ' . gacct_rpdf_badge_class( $label ) . '">' . esc_html( $label ) . '</span>';
}

/**
 * Légende de seuils (v1).
 */
function gacct_rpdf_legend( array $legend ) {
	$parts = array();
	foreach ( $legend as $label => $range ) {
		$parts[] = esc_html( $label . ' : ' . $range );
	}

	return '<p class="rpdf-legend">' . implode( ' &nbsp;·&nbsp; ', $parts ) . '</p>';
}

/**
 * En-tête fixe v1 (equipement / suspente).
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
	echo '<td>' . esc_html( gacct_rp2_footer_line() ) . '</td>';
	echo '<td style="text-align:right; white-space:nowrap;">' . esc_html( sprintf( __( 'Rapport n° %1$s — édité le %2$s', 'gestion-atelier-cct' ), $context['number'], $context['date'] ) ) . '</td>';
	echo '</tr></table></div>';

	if ( $intro ) {
		echo '<p class="muted" style="font-size:8.5px;">' . esc_html( $intro ) . '</p>';
	}

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
 * Bloc signature v1.
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
 * Nombre affiché à la française (v1).
 */
function gacct_rpdf_num( $value, $decimals = 1 ) {
	return gacct_rp2_num( $value, $decimals );
}
