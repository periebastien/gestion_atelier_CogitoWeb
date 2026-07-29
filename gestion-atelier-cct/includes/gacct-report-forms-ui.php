<?php
/**
 * Rapports de contrôle — carte « Rapports » de la fiche console (états 3 à 6)
 * et formulaires des 3 modèles (mobile-first, sections repliables, calculs en
 * temps réel via assets/js/operator-report.js).
 *
 * Rendu serveur pur, comme le reste de la fiche : les formulaires sont
 * pré-remplis (identification depuis le CCT revision + la commande), les
 * brouillons existants sont poussés au JS en JSON (script data-report-entries).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Petit champ texte du formulaire rapport.
 */
function gacct_rf_input( $path, $label, $value = '', $type = 'text', $attrs = '' ) {
	$id = 'rf-' . sanitize_key( str_replace( '.', '-', $path ) ) . '-' . wp_rand( 100, 999 );

	echo '<label class="gacct-rf-field">';
	echo '<span class="gacct-rf-label">' . esc_html( $label ) . '</span>';
	echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" data-rf="' . esc_attr( $path ) . '" value="' . esc_attr( $value ) . '" ' . $attrs . '>';
	echo '</label>';
}

/**
 * Select générique.
 */
function gacct_rf_select( $path, $label, array $options, $value = '' ) {
	echo '<label class="gacct-rf-field">';
	echo '<span class="gacct-rf-label">' . esc_html( $label ) . '</span>';
	echo '<select data-rf="' . esc_attr( $path ) . '">';
	foreach ( $options as $key => $opt_label ) {
		echo '<option value="' . esc_attr( $key ) . '"' . selected( (string) $value, (string) $key, false ) . '>' . esc_html( $opt_label ) . '</option>';
	}
	echo '</select></label>';
}

/**
 * Section repliable (accordéon <details>).
 */
function gacct_rf_section_open( $title, $open = false, $badge_key = '' ) {
	echo '<details class="gacct-rf-section"' . ( $open ? ' open' : '' ) . '>';
	echo '<summary><span>' . esc_html( $title ) . '</span>';
	if ( $badge_key ) {
		echo '<span class="gacct-rf-badge" data-rf-badge="' . esc_attr( $badge_key ) . '">—</span>';
	}
	echo '</summary><div class="gacct-rf-section-body">';
}

function gacct_rf_section_close() {
	echo '</div></details>';
}

/**
 * En-tête commun des 3 formulaires : numéro, auteur (+ type pour la voile).
 */
function gacct_rf_common_header( array $revision, $order, $with_type = false ) {
	$choices = function_exists( 'gacct_op_operator_choices' ) ? gacct_op_operator_choices() : array();

	echo '<div class="gacct-rf-grid">';

	if ( $with_type ) {
		gacct_rf_select( 'type', __( 'Type de rapport', 'gestion-atelier-cct' ), gacct_report_voile_types(), 'periodique' );
	}

	gacct_rf_input(
		'number',
		__( 'N° de rapport', 'gestion-atelier-cct' ),
		'',
		'text',
		'placeholder="' . esc_attr( sprintf( __( 'auto : %s', 'gestion-atelier-cct' ), gacct_report_peek_number() ) ) . '"'
	);

	$authors = array( '' => '—' );
	foreach ( $choices as $uid => $name ) {
		$authors[ (string) $uid ] = $name;
	}
	gacct_rf_select( 'author_id', __( 'Auteur / réalisé par', 'gestion-atelier-cct' ), $authors, (string) get_current_user_id() );

	echo '</div>';
	echo '<p class="gacct-op-muted">' . esc_html__( 'Numéro laissé vide = numérotation automatique, figée à la première génération du PDF. Édité le : date du jour de génération.', 'gestion-atelier-cct' ) . '</p>';
}

/**
 * Bloc identification pilote (commun voile / équipement).
 */
function gacct_rf_pilot_fields( $order ) {
	echo '<div class="gacct-rf-grid">';
	gacct_rf_input( 'ident.nom', __( 'Nom', 'gestion-atelier-cct' ), $order ? $order->get_billing_last_name() : '' );
	gacct_rf_input( 'ident.prenom', __( 'Prénom', 'gestion-atelier-cct' ), $order ? $order->get_billing_first_name() : '' );
	gacct_rf_input(
		'ident.contact',
		__( 'Contact', 'gestion-atelier-cct' ),
		$order ? implode( ' — ', array_filter( array( $order->get_billing_email(), $order->get_billing_phone() ) ) ) : ''
	);
	echo '</div>';
}

/**
 * Boutons bas de formulaire (brouillon / générer / fermer).
 */
function gacct_rf_footer_buttons( $final = false ) {
	echo '<div class="gacct-rf-actions">';
	echo '<button type="button" class="button" data-rf-action="save-draft">' . esc_html__( 'Enregistrer le brouillon', 'gestion-atelier-cct' ) . '</button>';
	echo '<button type="button" class="button button-primary" data-rf-action="generate">' . esc_html__( 'Générer le PDF', 'gestion-atelier-cct' ) . '</button>';
	echo '<button type="button" class="button" data-rf-action="close-form">' . esc_html__( 'Fermer', 'gestion-atelier-cct' ) . '</button>';
	echo '</div>';
	echo '<p class="gacct-op-muted">' . esc_html__( 'Le PDF est écrit dans le coffre-fort et ajouté au dossier ; régénérer un rapport remplace son PDF sans toucher aux autres.', 'gestion-atelier-cct' ) . '</p>';
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
	echo '<div data-rf-rupture-lines></div>';
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
	echo '<div class="gacct-rf-grid">';
	gacct_rf_input( 'geometry.calage_ecarts', __( 'Écarts calage', 'gestion-atelier-cct' ) );
	gacct_rf_select( 'geometry.calage_interp', __( 'Interprétation calage', 'gestion-atelier-cct' ), $config['geometry_interps'], '' );
	gacct_rf_input( 'geometry.calage_interventions', __( 'Interventions calage', 'gestion-atelier-cct' ) );
	gacct_rf_input( 'geometry.freins_ecarts', __( 'Écarts freins', 'gestion-atelier-cct' ) );
	gacct_rf_select( 'geometry.freins_interp', __( 'Interprétation freins', 'gestion-atelier-cct' ), $config['geometry_interps'], '' );
	gacct_rf_input( 'geometry.freins_interventions', __( 'Interventions freins', 'gestion-atelier-cct' ) );
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

/**
 * Carte « Rapports » de la fiche console (états 3 à 6 : édition ; état ≥ 7 :
 * simple liste en lecture via la carte Actions existante).
 */
function gacct_report_render_card( $revision_id, array $revision, $order, $state ) {
	if ( $state < 3 || $state > 6 ) {
		return;
	}

	$entries     = gacct_report_entries( $revision_id );
	$models      = gacct_report_models();
	$rapport_ids = gacct_report_ids( $revision['rapport_pdf'] ?? '' );

	// PDF uploadés à la main = pièces jointes non référencées par une entrée.
	$generated_ids = array();
	foreach ( $entries as $entry ) {
		if ( ! empty( $entry['attachment_id'] ) ) {
			$generated_ids[] = absint( $entry['attachment_id'] );
		}
	}
	$manual_ids = array_values( array_diff( $rapport_ids, $generated_ids ) );

	echo '<div class="gacct-op-card gacct-op-reports-card" data-report-card>';
	echo '<h2>' . esc_html__( 'Rapports de contrôle', 'gestion-atelier-cct' ) . '</h2>';
	echo '<div class="gacct-op-feedback gacct-rf-feedback" aria-live="polite"></div>';

	// ---- Liste des rapports (générés / brouillons + uploads manuels). ----
	if ( $entries || $manual_ids ) {
		echo '<ul class="gacct-rf-list">';

		foreach ( $entries as $entry ) {
			$is_final = ( 'final' === ( $entry['status'] ?? 'draft' ) ) && ! empty( $entry['attachment_id'] );
			$label    = isset( $models[ $entry['model'] ] ) ? $models[ $entry['model'] ] : $entry['model'];

			echo '<li class="gacct-rf-item' . ( $is_final ? ' is-final' : ' is-draft' ) . '">';
			echo '<span class="gacct-rf-item-main"><strong>' . esc_html( $label ) . '</strong>';
			if ( ! empty( $entry['number'] ) ) {
				echo ' <span class="gacct-rf-item-number">n° ' . esc_html( $entry['number'] ) . '</span>';
			}
			echo ' <span class="gacct-rf-item-status">' . ( $is_final ? esc_html__( 'PDF généré', 'gestion-atelier-cct' ) : esc_html__( 'brouillon', 'gestion-atelier-cct' ) ) . '</span>';
			if ( ! empty( $entry['updated'] ) ) {
				echo ' <span class="gacct-op-muted">' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $entry['updated'] ) ) ) . '</span>';
			}
			echo '</span>';

			echo '<span class="gacct-rf-item-actions">';
			if ( $is_final ) {
				echo '<a class="button button-small" href="' . esc_url( gacct_report_download_url( $revision_id, absint( $entry['attachment_id'] ) ) ) . '" target="_blank" rel="noopener">PDF</a> ';
			}
			echo '<button type="button" class="button button-small" data-rf-action="open" data-report-id="' . esc_attr( $entry['id'] ) . '" data-model="' . esc_attr( $entry['model'] ) . '">'
				. ( $is_final ? esc_html__( 'Modifier / régénérer', 'gestion-atelier-cct' ) : esc_html__( 'Reprendre', 'gestion-atelier-cct' ) ) . '</button> ';
			echo '<button type="button" class="gacct-rf-line-del" data-rf-action="delete" data-report-id="' . esc_attr( $entry['id'] ) . '" aria-label="' . esc_attr__( 'Supprimer ce rapport', 'gestion-atelier-cct' ) . '">×</button>';
			echo '</span>';
			echo '</li>';
		}

		foreach ( $manual_ids as $manual_id ) {
			$nom = get_the_title( $manual_id );
			echo '<li class="gacct-rf-item is-manual">';
			echo '<span class="gacct-rf-item-main"><strong>' . esc_html( $nom ? $nom : __( 'PDF déposé', 'gestion-atelier-cct' ) ) . '</strong> <span class="gacct-rf-item-status">' . esc_html__( 'upload manuel', 'gestion-atelier-cct' ) . '</span></span>';
			echo '<span class="gacct-rf-item-actions">';
			echo '<a class="button button-small" href="' . esc_url( gacct_report_download_url( $revision_id, $manual_id ) ) . '" target="_blank" rel="noopener">PDF</a> ';
			echo '<button type="button" class="gacct-rf-line-del" data-op-action="delete-report" data-attachment="' . esc_attr( $manual_id ) . '" aria-label="' . esc_attr__( 'Supprimer ce rapport', 'gestion-atelier-cct' ) . '">×</button>';
			echo '</span>';
			echo '</li>';
		}

		echo '</ul>';
	} else {
		echo '<p class="gacct-op-muted">' . esc_html__( 'Aucun rapport pour l\'instant. Le rapport est obligatoire avant de demander le solde ; il ne devient visible du client qu\'une fois le solde réglé.', 'gestion-atelier-cct' ) . '</p>';
	}

	// ---- Nouveau rapport : choix du modèle. ----
	echo '<div class="gacct-rf-new">';
	echo '<button type="button" class="button button-primary" data-rf-action="toggle-new" aria-expanded="false">+ ' . esc_html__( 'Nouveau rapport', 'gestion-atelier-cct' ) . '</button>';
	echo '<div class="gacct-rf-model-choice" hidden>';
	foreach ( $models as $model_key => $model_label ) {
		echo '<button type="button" class="button" data-rf-action="open" data-report-id="" data-model="' . esc_attr( $model_key ) . '">' . esc_html( $model_label ) . '</button>';
	}
	echo '</div>';
	echo '</div>';

	// ---- Formulaires (masqués). ----
	gacct_rf_render_voile_form( $revision, $order );
	gacct_rf_render_equipement_form( $revision, $order );
	gacct_rf_render_suspente_form( $revision, $order );

	// ---- Upload manuel conservé. ----
	echo '<details class="gacct-rf-section gacct-rf-upload">';
	echo '<summary><span>' . esc_html__( 'Déposer un PDF externe (upload manuel)', 'gestion-atelier-cct' ) . '</span></summary>';
	echo '<div class="gacct-rf-section-body">';
	echo '<form class="gacct-op-upload-form" data-op-form="upload-report">';
	echo '<label class="gacct-op-label" for="gacct-op-rapport">' . esc_html__( 'Rapport d\'intervention (PDF, 10 Mo max)', 'gestion-atelier-cct' ) . '</label>';
	if ( $rapport_ids ) {
		echo '<label class="gacct-op-check"><input type="checkbox" data-op-field="replace-report"> ' . esc_html__( 'Remplacer les rapports existants (sinon le nouveau s\'ajoute)', 'gestion-atelier-cct' ) . '</label>';
	}
	echo '<input type="file" id="gacct-op-rapport" name="rapport" accept="application/pdf" required>';
	echo '<button type="submit" class="button">' . esc_html__( 'Déposer le rapport', 'gestion-atelier-cct' ) . '</button>';
	echo '</form>';
	echo '</div></details>';

	// ---- Données pour le JS : entrées existantes (brouillons compris). ----
	echo '<script type="application/json" data-report-entries>' . wp_json_encode( $entries ) . '</script>';

	echo '</div>';
}
