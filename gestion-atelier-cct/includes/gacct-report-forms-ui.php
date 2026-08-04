<?php
/**
 * Rapports de contrôle — carte « Rapports » de la fiche console (états 3 à 6),
 * FRAMEWORK : la carte, la liste, l'upload manuel et les helpers de champs
 * (gacct_rf_*) sont ici ; les FORMULAIRES eux-mêmes sont rendus par le pack
 * actif (callbacks `render_form` du registre gacct_report_models_full()).
 *
 * Rendu serveur pur : formulaires pré-remplis (CCT revision + commande),
 * brouillons poussés au JS en JSON (script data-report-entries).
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
	// Sans pack de rapports (choix assumé de certains ateliers, ex. AEROTECH :
	// le rapport est produit hors plateforme), la carte devient « dépôt de PDF » :
	// message neutre — on ne suggère PAS qu'il manque quelque chose — et
	// formulaire d'upload ouvert d'office puisqu'il est la seule action.
	echo '<div class="gacct-rf-new">';
	if ( ! $models ) {
		echo '<p class="gacct-op-muted">' . esc_html__( 'Cet atelier n’utilise pas la génération de rapports intégrée : déposez vos rapports PDF via le formulaire ci-dessous. Ils suivent le même circuit (coffre sécurisé, envoi au client une fois le solde réglé).', 'gestion-atelier-cct' ) . '</p>';
	}
	echo '<button type="button" class="button button-primary" data-rf-action="toggle-new" aria-expanded="false"' . ( $models ? '' : ' hidden' ) . '>+ ' . esc_html__( 'Nouveau rapport', 'gestion-atelier-cct' ) . '</button>';
	echo '<div class="gacct-rf-model-choice" hidden>';
	foreach ( $models as $model_key => $model_label ) {
		echo '<button type="button" class="button" data-rf-action="open" data-report-id="" data-model="' . esc_attr( $model_key ) . '">' . esc_html( $model_label ) . '</button>';
	}
	echo '</div>';
	echo '</div>';

	// ---- Formulaires (masqués), rendus par le pack actif. ----
	foreach ( gacct_report_models_full() as $model_slug => $model_def ) {
		if ( ! empty( $model_def['render_form'] ) && is_callable( $model_def['render_form'] ) ) {
			call_user_func( $model_def['render_form'], $revision, $order );
		}
	}

	// ---- Upload manuel conservé (ouvert d'office quand c'est la seule voie). ----
	echo '<details class="gacct-rf-section gacct-rf-upload"' . ( $models ? '' : ' open' ) . '>';
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
