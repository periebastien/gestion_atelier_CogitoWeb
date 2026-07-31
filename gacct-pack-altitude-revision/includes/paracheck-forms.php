<?php
/**
 * Pack Altitude Révision — Formulaires console des 3 modèles ParachecK® (helpers gacct_rf_* du framework).
 * Extrait du framework gestion-atelier-cct le 31/07/2026 (architecture packs).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formulaire — modèle « Rapport voile ParachecK® ».
 */
function gacct_rf_render_voile_form( array $revision, $order ) {
	$config = gacct_report_calc_config();

	echo '<div class="gacct-rf-form" data-report-form="voile" hidden>';
	echo '<h3 class="gacct-rf-title">' . esc_html__( 'Rapport voile ParachecK®', 'gestion-atelier-cct' ) . '</h3>';

	gacct_rf_common_header( $revision, $order, true );

	// ------------------------------------------------ Pilote & voile.
	gacct_rf_section_open( __( 'Pilote & voile', 'gestion-atelier-cct' ), true );
	gacct_rf_pilot_fields( $order );

	echo '<div class="gacct-rf-grid">';
	gacct_rf_input( 'ident.marque', __( 'Marque', 'gestion-atelier-cct' ), ucfirst( trim( (string) ( $revision['marque'] ?? '' ) ) ), 'text', 'list="gacct-rf-brands"' );
	gacct_rf_input( 'ident.modele', __( 'Modèle', 'gestion-atelier-cct' ), trim( (string) ( $revision['modele'] ?? '' ) ) );
	gacct_rf_input( 'ident.taille', __( 'Taille', 'gestion-atelier-cct' ), trim( (string) ( $revision['taille'] ?? '' ) ) );
	gacct_rf_input( 'ident.couleur', __( 'Couleur', 'gestion-atelier-cct' ), trim( (string) ( $revision['couleur'] ?? '' ) ) );
	gacct_rf_input( 'ident.serie', __( 'N° de série', 'gestion-atelier-cct' ), trim( (string) ( $revision['numero_de_serie'] ?? '' ) ) );
	gacct_rf_input( 'ident.ptv', __( 'PTV', 'gestion-atelier-cct' ), trim( (string) ( $revision['p_t_v'] ?? '' ) ) );
	echo '</div>';

	echo '<datalist id="gacct-rf-brands">';
	foreach ( $config['brands'] as $brand ) {
		echo '<option value="' . esc_attr( $brand ) . '"></option>';
	}
	echo '</datalist>';
	gacct_rf_section_close();

	// ------------------------------------------------ Vérification de sécurité.
	gacct_rf_section_open( __( 'Vérification de sécurité', 'gestion-atelier-cct' ) );
	$security = array(
		'fluidite' => __( 'Fluidité suspentage', 'gestion-atelier-cct' ),
		'maillons' => __( 'Maillons / connecteurs', 'gestion-atelier-cct' ),
		'drisses'  => __( 'Drisses de frein, nœuds, poulies', 'gestion-atelier-cct' ),
	);
	foreach ( $security as $key => $label ) {
		echo '<label class="gacct-op-check"><input type="checkbox" data-rf="securite.' . esc_attr( $key ) . '" value="1"> ' . esc_html( $label ) . '</label>';
	}
	gacct_rf_section_close();

	// ------------------------------------------------ Inspection visuelle.
	gacct_rf_section_open( __( 'Inspection visuelle', 'gestion-atelier-cct' ), false, 'visual_global' );

	$values = array( '' => '—' );
	foreach ( $config['visual_values'] as $key => $def ) {
		$values[ $key ] = $def['label'];
	}

	foreach ( $config['visual_groups'] as $group_key => $group ) {
		echo '<div class="gacct-rf-visual-group">';
		echo '<div class="gacct-rf-visual-head"><strong>' . esc_html( $group['label'] ) . '</strong> <span class="gacct-rf-badge" data-rf-badge="visual_' . esc_attr( $group_key ) . '">—</span></div>';
		echo '<div class="gacct-rf-grid gacct-rf-grid-visual">';
		foreach ( $group['items'] as $i => $item ) {
			gacct_rf_select( 'visual.' . $group_key . '.' . $i, $item, $values, '' );
		}
		echo '</div>';
		if ( $group['note'] ) {
			echo '<p class="gacct-op-muted">' . esc_html( $group['note'] ) . '</p>';
		}
		echo '</div>';
	}
	gacct_rf_section_close();

	// ------------------------------------------------ Porosité.
	gacct_rf_section_open( __( 'Test de porosité des tissus', 'gestion-atelier-cct' ), false, 'porosity' );
	echo '<p class="gacct-op-muted">' . esc_html__( 'Porosimètre JDC — temps en secondes ; le débit (l/m²/min) et l\'interprétation se calculent seuls.', 'gestion-atelier-cct' ) . '</p>';
	echo '<div class="gacct-rf-grid gacct-rf-grid-poro">';
	foreach ( $config['porosity_points'] as $i => $point ) {
		gacct_rf_input( 'porosity.' . $i, $point . ' (s)', '', 'number', 'step="0.1" min="0" inputmode="decimal"' );
	}
	echo '</div>';
	echo '<p class="gacct-rf-computed" data-rf-computed="porosity">—</p>';
	gacct_rf_section_close();

	// ------------------------------------------------ Déchirure.
	gacct_rf_section_open( __( 'Test de résistance à la déchirure', 'gestion-atelier-cct' ), false, 'tear' );
	echo '<p class="gacct-op-muted">' . esc_html__( 'Bettsometer — valeurs en DaN. Le seuil minimal (0,9 ou 1,2 DaN) dépend de la moyenne de porosité.', 'gestion-atelier-cct' ) . '</p>';
	echo '<div class="gacct-rf-grid gacct-rf-grid-poro">';
	foreach ( $config['tear_zones'] as $key => $label ) {
		gacct_rf_input( 'tear.' . $key, $label . ' (DaN)', '', 'number', 'step="0.01" min="0" inputmode="decimal"' );
	}
	echo '</div>';
	echo '<p class="gacct-rf-computed" data-rf-computed="tear">—</p>';
	gacct_rf_section_close();

	// ------------------------------------------------ Rupture des suspentes.
	gacct_rf_section_open( __( 'Test de rupture des suspentes', 'gestion-atelier-cct' ), false, 'rupture' );
	echo '<p class="gacct-op-muted">' . esc_html__( 'Sur recommandation du constructeur (0 à 5 suspentes). Seuil de réforme = valeur nominale × coefficient matériau, ou VR du « Calcul réforme suspente » si renseigné.', 'gestion-atelier-cct' ) . '</p>';
	echo '<p class="gacct-rf-vr-hint" data-rf-vr-hint hidden></p>';
	echo '<div data-rf-rupture-lines data-rf-max="' . esc_attr( $config['rupture_max_lines'] ) . '"></div>';
	echo '<button type="button" class="button button-small" data-rf-action="add-rupture">+ ' . esc_html__( 'Ajouter une suspente testée', 'gestion-atelier-cct' ) . '</button>';
	echo '<p class="gacct-rf-computed" data-rf-computed="rupture">—</p>';

	// Gabarit d'une ligne (cloné par le JS).
	$materials = array( '' => '—' );
	foreach ( $config['rupture_materials'] as $key => $def ) {
		$materials[ $key ] = $def['label'];
	}
	echo '<template data-rf-rupture-template>';
	echo '<div class="gacct-rf-rupture-line">';
	echo '<div class="gacct-rf-grid gacct-rf-grid-rupture">';
	echo '<label class="gacct-rf-field"><span class="gacct-rf-label">' . esc_html__( 'Suspente testée', 'gestion-atelier-cct' ) . '</span><input type="text" data-rl="ref" placeholder="A1G"></label>';
	echo '<label class="gacct-rf-field"><span class="gacct-rf-label">' . esc_html__( 'Valeur nominale (DaN)', 'gestion-atelier-cct' ) . '</span><input type="number" step="0.1" min="0" data-rl="nominal"></label>';
	echo '<label class="gacct-rf-field"><span class="gacct-rf-label">' . esc_html__( 'Matériau', 'gestion-atelier-cct' ) . '</span><select data-rl="material">';
	foreach ( $materials as $key => $label ) {
		echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
	}
	echo '</select></label>';
	echo '<label class="gacct-rf-field"><span class="gacct-rf-label">' . esc_html__( 'Mesure de rupture (DaN)', 'gestion-atelier-cct' ) . '</span><input type="number" step="0.1" min="0" data-rl="measure"></label>';
	echo '<label class="gacct-rf-field"><span class="gacct-rf-label">' . esc_html__( 'Seuil réforme (DaN, vide = auto)', 'gestion-atelier-cct' ) . '</span><span class="gacct-rf-seuil-wrap"><input type="number" step="0.01" min="0" data-rl="seuil"><button type="button" class="button button-small" data-rf-action="apply-vr" hidden>VR</button></span></label>';
	echo '</div>';
	echo '<p class="gacct-rf-rupture-result"><span data-rl-result>—</span> <button type="button" class="gacct-rf-line-del" data-rf-action="del-rupture" aria-label="' . esc_attr__( 'Retirer cette suspente', 'gestion-atelier-cct' ) . '">×</button></p>';
	echo '</div>';
	echo '</template>';
	gacct_rf_section_close();

	// ------------------------------------------------ Calage & freins.
	gacct_rf_section_open( __( 'Inspection géométrique (calage & freins)', 'gestion-atelier-cct' ), false, 'geometry' );
	echo '<p class="gacct-op-muted">' . esc_html__( 'Calage contrôlé avec le système de mesure WOERNER. Le détail des mesures est disponible en annexe.', 'gestion-atelier-cct' ) . '</p>';
	// Design validé 31/07/2026 : le détail des mesures (écarts) reste en annexe
	// WOERNER — le rapport porte Interventions + Final (calage) et Interventions
	// (freins), avec l'interprétation de chaque bloc.
	echo '<div class="gacct-rf-grid">';
	gacct_rf_input( 'geometry.calage_interventions', __( 'Calage — interventions', 'gestion-atelier-cct' ) );
	gacct_rf_input( 'geometry.calage_final', __( 'Calage — final', 'gestion-atelier-cct' ) );
	gacct_rf_select( 'geometry.calage_interp', __( 'Interprétation calage', 'gestion-atelier-cct' ), $config['geometry_interps'], '' );
	gacct_rf_input( 'geometry.freins_interventions', __( 'Freins — interventions', 'gestion-atelier-cct' ) );
	gacct_rf_select( 'geometry.freins_interp', __( 'Interprétation freins', 'gestion-atelier-cct' ), $config['geometry_interps'], '' );
	gacct_rf_select( 'geometry.reglage_freins', __( 'Réglage des freins', 'gestion-atelier-cct' ), $config['brake_settings'], '' );
	echo '</div>';
	gacct_rf_section_close();

	// ------------------------------------------------ Prochain contrôle (partielle).
	gacct_rf_section_open( __( 'Prochain contrôle', 'gestion-atelier-cct' ) );
	echo '<div class="gacct-rf-grid">';
	gacct_rf_input( 'next.date', __( 'Date', 'gestion-atelier-cct' ), '', 'date' );
	gacct_rf_input( 'next.hours', __( 'Heures de vol', 'gestion-atelier-cct' ) );
	gacct_rf_input( 'next.flights', __( 'Nb de vols', 'gestion-atelier-cct' ) );
	echo '</div>';
	gacct_rf_section_close();

	// ------------------------------------------------ Commentaires.
	gacct_rf_section_open( __( 'Commentaires et travaux effectués', 'gestion-atelier-cct' ) );
	echo '<textarea rows="6" data-rf="comment" data-rf-comment-templates="' . esc_attr( wp_json_encode( array(
		'periodique' => gacct_report_voile_texts( 'periodique' )['comment_default'],
		'partielle'  => gacct_report_voile_texts( 'partielle' )['comment_default'],
	) ) ) . '"></textarea>';
	echo '<p class="gacct-op-muted">' . esc_html__( 'Suspentes changées, ripstops posés, drisses changées, calage… Le texte-modèle suit le type de rapport choisi.', 'gestion-atelier-cct' ) . '</p>';
	gacct_rf_section_close();

	// ------------------------------------------------ Récapitulatif.
	echo '<div class="gacct-rf-summary">';
	echo '<span class="gacct-rf-summary-label" data-rf-general-label>' . esc_html__( 'État général de la voile', 'gestion-atelier-cct' ) . '</span>';
	echo '<span class="gacct-rf-badge gacct-rf-badge-big" data-rf-badge="general">—</span>';
	echo '<p class="gacct-op-muted" data-rf-general-note hidden>' . esc_html( gacct_report_voile_texts( 'partielle' )['general_note'] ) . '</p>';
	echo '</div>';

	gacct_rf_footer_buttons();
	echo '</div>';
}

/**
 * Formulaire — modèle « Contrôle équipement ».
 */
function gacct_rf_render_equipement_form( array $revision, $order ) {
	echo '<div class="gacct-rf-form" data-report-form="equipement" hidden>';
	echo '<h3 class="gacct-rf-title">' . esc_html__( 'Contrôle équipement — Secours / Sellette', 'gestion-atelier-cct' ) . '</h3>';

	gacct_rf_common_header( $revision, $order );

	gacct_rf_section_open( __( 'Pilote', 'gestion-atelier-cct' ), true );
	gacct_rf_pilot_fields( $order );
	gacct_rf_section_close();

	gacct_rf_section_open( __( 'Sellette', 'gestion-atelier-cct' ), true );
	echo '<div class="gacct-rf-grid">';
	gacct_rf_input( 'sellette.marque', __( 'Marque', 'gestion-atelier-cct' ) );
	gacct_rf_input( 'sellette.modele', __( 'Modèle', 'gestion-atelier-cct' ) );
	gacct_rf_input( 'sellette.numero', __( 'N°', 'gestion-atelier-cct' ) );
	gacct_rf_input( 'sellette.taille', __( 'Taille', 'gestion-atelier-cct' ) );
	echo '</div>';
	echo '<label class="gacct-rf-field"><span class="gacct-rf-label">' . esc_html__( 'Vérifications', 'gestion-atelier-cct' ) . '</span>';
	echo '<textarea rows="4" data-rf="sellette.verifications" data-rf-default="' . esc_attr( "Vérification : OK\nMontage : OK" ) . '"></textarea></label>';
	echo '<label class="gacct-rf-field"><span class="gacct-rf-label">' . esc_html__( 'Remarque(s)', 'gestion-atelier-cct' ) . '</span>';
	echo '<textarea rows="3" data-rf="sellette.remarques"></textarea></label>';
	gacct_rf_section_close();

	gacct_rf_section_open( __( 'Parachute de secours', 'gestion-atelier-cct' ), true );
	echo '<div class="gacct-rf-grid">';
	gacct_rf_input( 'secours.marque', __( 'Marque', 'gestion-atelier-cct' ) );
	gacct_rf_input( 'secours.modele', __( 'Modèle', 'gestion-atelier-cct' ) );
	gacct_rf_input( 'secours.numero', __( 'N°', 'gestion-atelier-cct' ) );
	gacct_rf_input( 'secours.taille', __( 'Taille', 'gestion-atelier-cct' ) );
	gacct_rf_input( 'secours.date_production', __( 'Date de production', 'gestion-atelier-cct' ) );
	echo '</div>';
	echo '<label class="gacct-op-check"><input type="checkbox" data-rf="secours.aeration" value="1"> ' . esc_html__( 'Aération et pliage du parachute', 'gestion-atelier-cct' ) . '</label>';
	echo '<label class="gacct-rf-field"><span class="gacct-rf-label">' . esc_html__( 'Remarque(s)', 'gestion-atelier-cct' ) . '</span>';
	echo '<textarea rows="3" data-rf="secours.remarques"></textarea></label>';
	gacct_rf_section_close();

	gacct_rf_footer_buttons();
	echo '</div>';
}

/**
 * Formulaire — modèle « Calcul réforme suspente ».
 */
function gacct_rf_render_suspente_form( array $revision, $order ) {
	echo '<div class="gacct-rf-form" data-report-form="suspente" hidden>';
	echo '<h3 class="gacct-rf-title">' . esc_html__( 'Calcul du seuil de réforme pour la résistance des suspentes', 'gestion-atelier-cct' ) . '</h3>';

	gacct_rf_common_header( $revision, $order );

	gacct_rf_section_open( __( 'Données', 'gestion-atelier-cct' ), true );
	echo '<div class="gacct-rf-grid">';
	gacct_rf_input( 'resistance_test', __( 'Résistance suspente test (DaN)', 'gestion-atelier-cct' ), '', 'number', 'step="0.1" min="0" inputmode="decimal"' );
	gacct_rf_input( 'ptv_max', __( 'PTV Max (kg)', 'gestion-atelier-cct' ), '', 'number', 'step="0.1" min="0" inputmode="decimal"' );
	gacct_rf_input( 'coef', __( 'Coefficient', 'gestion-atelier-cct' ), '', 'number', 'step="0.1" min="0" inputmode="decimal"' );
	echo '</div>';

	echo '<table class="gacct-rf-table"><thead><tr><th></th><th>' . esc_html__( 'Nb suspentes', 'gestion-atelier-cct' ) . '</th><th>' . esc_html__( 'Résistance (DaN)', 'gestion-atelier-cct' ) . '</th><th>RESmax</th></tr></thead><tbody>';
	for ( $i = 0; $i < 4; $i++ ) {
		echo '<tr>';
		echo '<th>' . esc_html( sprintf( __( 'Ensemble %d', 'gestion-atelier-cct' ), $i + 1 ) ) . '</th>';
		echo '<td><input type="number" min="0" step="1" data-rf="ensembles.' . $i . '.nb" inputmode="numeric"></td>';
		echo '<td><input type="number" min="0" step="0.1" data-rf="ensembles.' . $i . '.resistance" inputmode="decimal"></td>';
		echo '<td class="gacct-rf-cell-computed" data-rf-computed="resmax_' . $i . '">0</td>';
		echo '</tr>';
	}
	echo '<tr class="gacct-rf-row-total"><th>' . esc_html__( 'Total', 'gestion-atelier-cct' ) . '</th><td data-rf-computed="nb_total">0</td><td></td><td data-rf-computed="resmax_total">0</td></tr>';
	echo '</tbody></table>';
	gacct_rf_section_close();

	echo '<div class="gacct-rf-summary">';
	echo '<span class="gacct-rf-summary-label">' . esc_html__( 'VR — seuil de réforme', 'gestion-atelier-cct' ) . '</span>';
	echo '<span class="gacct-rf-badge gacct-rf-badge-big" data-rf-computed="vr">0 DaN</span>';
	echo '<p class="gacct-op-muted">' . esc_html__( 'VR = (résistance test × PTV max ÷ RESmax total) × coefficient. Reportez ce VR comme seuil de réforme dans le test de rupture du rapport voile (bouton « VR »).', 'gestion-atelier-cct' ) . '</p>';
	echo '</div>';

	gacct_rf_footer_buttons();
	echo '</div>';
}
