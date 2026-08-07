<?php
/**
 * Console atelier — écran « Fiche intervention 360° » (CDC §4.3).
 *
 * Rendu serveur pur ; toutes les actions passent par les endpoints AJAX
 * de gacct-operator-api.php, pilotés par assets/js/operator.js via des
 * attributs data-op-action.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Créneau (occupation_atelier) lié à une révision — 1 ligne max.
 *
 * @return array|null { date_reservee, duree_totale_commande }
 */
function gacct_op_fiche_get_slot( array $revision ) {
	global $wpdb;

	$table    = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_OCCUPATION;
	$rev_id   = absint( $revision['_ID'] ?? 0 );
	$order_id = absint( $revision['order_id'] ?? 0 );

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT date_reservee, duree_totale_commande FROM {$table}
		 WHERE revision_id = %d OR ( %d > 0 AND order_id = %d )
		 LIMIT 1",
		$rev_id,
		$order_id,
		$order_id
	), ARRAY_A );

	return $row ? $row : null;
}

/**
 * Carte « Devis complémentaire » :
 * - état 3 : formulaire d'envoi (produits réparation/suspentes + lignes libres
 *   avec quantités + commentaire client) — seule porte d'entrée du 3→4 ;
 * - état 4 : récapitulatif du devis en attente + modification/ré-envoi ;
 * - état 5 : décision du client (validé / refusé, mode retour ou prestations
 *   initiales) ;
 * - états ≥ 6 : rappel de la décision si un devis a existé.
 */
function gacct_op_render_quote_card( $revision_id, array $revision, $order, $state ) {
	if ( ! $order || ! function_exists( 'gacct_quote_send' ) ) {
		return;
	}

	$decision = gacct_quote_decision( $order );
	$extras   = gacct_quote_extra_items( $order );
	$comment  = (string) $order->get_meta( GACCT_QUOTE_META_COMMENT );
	$sent_at  = (string) $order->get_meta( GACCT_QUOTE_META_SENT_AT );

	// Rien à afficher hors des états concernés et sans historique de devis.
	$show_form   = in_array( $state, array( 3, 4 ), true );
	$has_history = $extras || '' !== $decision['decision'] || '' !== $sent_at;

	if ( ! $show_form && ! $has_history ) {
		return;
	}

	echo '<div class="gacct-op-card gacct-op-quote-card">';
	echo '<h2>' . esc_html__( 'Devis complémentaire', 'gestion-atelier-cct' ) . '</h2>';
	echo '<div class="gacct-op-feedback gacct-op-quote-feedback" aria-live="polite"></div>';

	// ---- Décision du client / état du devis. ----
	if ( 'accepted' === $decision['decision'] ) {
		echo '<p class="gacct-op-quote-status is-accepted">✓ ' . esc_html( sprintf(
			__( 'Devis accepté par le client le %s.', 'gestion-atelier-cct' ),
			$decision['decided_at'] ? date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $decision['decided_at'] ) ) : '?'
		) ) . '</p>';
	} elseif ( 'refused' === $decision['decision'] ) {
		echo '<p class="gacct-op-quote-status is-refused">✗ ' . esc_html( sprintf(
			'return' === $decision['mode']
				? __( 'Devis REFUSÉ le %s — pure demande de devis : retourner le matériel au client.', 'gestion-atelier-cct' )
				: __( 'Devis REFUSÉ le %s — réaliser uniquement les prestations initiales.', 'gestion-atelier-cct' ),
			$decision['decided_at'] ? date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $decision['decided_at'] ) ) : '?'
		) ) . '</p>';
	} elseif ( 4 === $state && $sent_at ) {
		echo '<p class="gacct-op-quote-status is-pending">⏳ ' . esc_html( sprintf(
			__( 'Devis envoyé le %s — en attente de la réponse du client.', 'gestion-atelier-cct' ),
			date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $sent_at ) )
		) ) . '</p>';
	}

	// ---- Lignes du devis en cours (états 4+ : lecture). ----
	if ( $extras ) {
		echo '<ul class="gacct-op-items gacct-op-quote-lines">';
		foreach ( $extras as $item ) {
			$line = function_exists( 'gacct_kojito_montant_ligne' )
				? gacct_kojito_montant_ligne( $item )
				: (float) $item->get_total() + (float) $item->get_total_tax();
			echo '<li><span class="gacct-op-item-name">' . esc_html( $item->get_name() )
				. ( $item->get_quantity() > 1 ? ' × ' . (int) $item->get_quantity() : '' )
				. '</span><span class="gacct-op-item-total">' . wp_kses_post( wc_price( $line, array( 'currency' => $order->get_currency() ) ) ) . '</span></li>';
		}
		echo '</ul>';

		if ( '' !== trim( $comment ) ) {
			echo '<p class="gacct-op-muted"><strong>' . esc_html__( 'Mot de l\'atelier :', 'gestion-atelier-cct' ) . '</strong> ' . esc_html( $comment ) . '</p>';
		}
	}

	// ---- Formulaire (état 3 : ouvert ; état 4 : replié derrière « Modifier »). ----
	if ( $show_form ) {
		$products = gacct_quote_products();

		// Pré-remplissage à l'état 4 : les lignes du devis en attente.
		$prefill = array();
		foreach ( $extras as $item ) {
			$prefill[] = array(
				'product_id' => (int) $item->get_product_id(),
				'label'      => $item->get_name(),
				'qty'        => (int) $item->get_quantity(),
				'unit'       => (float) $item->get_meta( '_kojito_prix_unitaire_initial' ),
			);
		}

		if ( 4 === $state ) {
			echo '<button type="button" class="button" data-op-action="toggle-quote-form" aria-expanded="false">' . esc_html__( 'Modifier le devis…', 'gestion-atelier-cct' ) . '</button>';
		}

		echo '<div class="gacct-op-quote-form"' . ( 4 === $state ? ' hidden' : '' ) . ' data-quote-prefill="' . esc_attr( wp_json_encode( $prefill ) ) . '">';
		echo '<script type="application/json" data-quote-products>' . wp_json_encode( $products ) . '</script>';

		echo '<p class="gacct-op-muted">' . esc_html__( 'Ajoutez les travaux constatés : prestations du catalogue (Réparation, Suspentes & travaux) ou lignes libres. Rien n\'est facturé maintenant : le montant s\'ajoute au solde de fin d\'intervention.', 'gestion-atelier-cct' ) . '</p>';

		echo '<table class="gacct-op-quote-table"><thead><tr>'
			. '<th>' . esc_html__( 'Prestation', 'gestion-atelier-cct' ) . '</th>'
			. '<th class="col-qty">' . esc_html__( 'Qté', 'gestion-atelier-cct' ) . '</th>'
			. '<th class="col-price">' . esc_html__( 'PU TTC', 'gestion-atelier-cct' ) . '</th>'
			. '<th class="col-total">' . esc_html__( 'Total', 'gestion-atelier-cct' ) . '</th>'
			. '<th class="col-del"></th>'
			. '</tr></thead><tbody data-quote-rows></tbody></table>';

		echo '<div class="gacct-op-quote-add">';
		echo '<button type="button" class="button button-small" data-op-action="quote-add-catalog">+ ' . esc_html__( 'Prestation du catalogue', 'gestion-atelier-cct' ) . '</button> ';
		echo '<button type="button" class="button button-small" data-op-action="quote-add-free">+ ' . esc_html__( 'Ligne libre', 'gestion-atelier-cct' ) . '</button>';
		echo '</div>';

		echo '<p class="gacct-op-quote-total">' . esc_html__( 'Total des travaux supplémentaires :', 'gestion-atelier-cct' ) . ' <strong data-quote-total>0,00 €</strong></p>';

		echo '<label class="gacct-op-label">' . esc_html__( 'Mot pour le client (affiché dans l\'email et sur la page du devis)', 'gestion-atelier-cct' ) . '</label>';
		echo '<textarea rows="3" data-op-field="quote-comment" placeholder="' . esc_attr__( 'Ex. : les suspentes basses présentent une usure avancée, nous recommandons leur remplacement…', 'gestion-atelier-cct' ) . '">' . esc_textarea( $comment ) . '</textarea>';

		echo '<button type="button" class="button button-primary" data-op-action="send-quote">'
			. ( 4 === $state ? esc_html__( 'Remplacer et renvoyer le devis', 'gestion-atelier-cct' ) : esc_html__( 'Envoyer le devis au client', 'gestion-atelier-cct' ) )
			. '</button>';
		echo '<p class="gacct-op-muted">' . esc_html__( 'Le client reçoit un email avec un lien sécurisé pour accepter ou refuser en un clic.', 'gestion-atelier-cct' ) . '</p>';

		echo '</div>'; // .gacct-op-quote-form
	}

	// ---- État 5 : consigne selon la décision du client. ----
	if ( 5 === $state && 'refused' === $decision['decision'] ) {
		if ( 'return' === $decision['mode'] ) {
			echo '<div class="gacct-op-warning">' . esc_html__( 'Aucun travail supplémentaire : préparer le RETOUR du matériel au client (adresse de la commande), puis clore l\'intervention.', 'gestion-atelier-cct' ) . '</div>';
		} else {
			echo '<p class="gacct-op-muted">' . esc_html__( 'Réalisez uniquement les prestations initialement commandées, puis « Intervention terminée, demander le solde ».', 'gestion-atelier-cct' ) . '</p>';
		}
	} elseif ( 5 === $state && 'accepted' === $decision['decision'] ) {
		echo '<p class="gacct-op-muted">' . esc_html__( 'Devis validé : réalisez les travaux complémentaires, puis « Intervention terminée, demander le solde ».', 'gestion-atelier-cct' ) . '</p>';
	}

	echo '</div>'; // .gacct-op-quote-card
}

/**
 * Carte « Facturation & suppléments » (états 2 à 6, commande non annulée) :
 * l'opérateur ajuste la facture sans passer par le devis — produits du
 * catalogue (toutes catégories hors frais de port), lignes libres et REMISES
 * (montants négatifs). Aucun email client, notes de commande signées ; tout
 * s'ajoute (ou se retranche) au solde de fin d'intervention (API Kojito).
 */
function gacct_op_render_billing_card( $revision_id, array $revision, $order, $state ) {
	if ( ! $order || ! function_exists( 'gacct_billing_add_lines' ) ) {
		return;
	}

	if ( $state < 2 || $state > 6 || $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
		return;
	}

	$currency = array( 'currency' => $order->get_currency() );

	// Montants : API Kojito uniquement, aucun recalcul (mêmes sources que view-order).
	$total_due = gacct_kojito_total_initial( $order );
	$deposit   = gacct_quote_deposit_paid( $order );
	$balance   = round( max( 0, $total_due - $deposit ), wc_get_price_decimals() );

	$items = gacct_billing_items( $order );

	echo '<div class="gacct-op-card gacct-op-billing-card">';
	echo '<h2>' . esc_html__( 'Facturation & suppléments', 'gestion-atelier-cct' ) . '</h2>';
	echo '<div class="gacct-op-feedback gacct-op-billing-feedback" aria-live="polite"></div>';

	// ---- Rappel des montants (TTC). ----
	echo '<dl class="gacct-op-facts">';
	echo '<div><dt>' . esc_html__( 'Total dû (TTC)', 'gestion-atelier-cct' ) . '</dt><dd>' . wp_kses_post( wc_price( $total_due, $currency ) ) . '</dd></div>';
	echo '<div><dt>' . esc_html__( 'Acompte réglé', 'gestion-atelier-cct' ) . '</dt><dd>' . wp_kses_post( wc_price( $deposit, $currency ) ) . '</dd></div>';
	echo '<div><dt>' . esc_html__( 'Solde estimé', 'gestion-atelier-cct' ) . '</dt><dd>' . wp_kses_post( wc_price( $balance, $currency ) ) . '</dd></div>';
	echo '</dl>';

	// ---- Lignes de facturation déjà ajoutées. ----
	if ( $items ) {
		echo '<ul class="gacct-op-items gacct-op-billing-lines">';
		foreach ( $items as $item_id => $item ) {
			$line = gacct_kojito_montant_ligne( $item );
			echo '<li' . ( $line < 0 ? ' class="is-discount"' : '' ) . '>';
			echo '<span class="gacct-op-item-name">' . esc_html( $item->get_name() )
				. ( $item->get_quantity() > 1 ? ' × ' . (int) $item->get_quantity() : '' ) . '</span>';
			echo '<span class="gacct-op-item-total">' . wp_kses_post( wc_price( $line, $currency ) )
				. ' <button type="button" class="gacct-op-quote-del" data-op-action="billing-remove" data-item-id="' . esc_attr( $item_id ) . '" aria-label="' . esc_attr__( 'Retirer cette ligne de la facture', 'gestion-atelier-cct' ) . '">×</button></span>';
			echo '</li>';
		}
		echo '</ul>';
	}

	// ---- Éditeur (motif de la carte devis). ----
	$products = gacct_billing_products();

	echo '<div class="gacct-op-billing-form">';
	echo '<script type="application/json" data-bill-products>' . wp_json_encode( $products ) . '</script>';

	echo '<table class="gacct-op-quote-table gacct-op-billing-table"><thead><tr>'
		. '<th>' . esc_html__( 'Prestation', 'gestion-atelier-cct' ) . '</th>'
		. '<th class="col-qty">' . esc_html__( 'Qté', 'gestion-atelier-cct' ) . '</th>'
		. '<th class="col-price">' . esc_html__( 'PU TTC', 'gestion-atelier-cct' ) . '</th>'
		. '<th class="col-total">' . esc_html__( 'Total', 'gestion-atelier-cct' ) . '</th>'
		. '<th class="col-del"></th>'
		. '</tr></thead><tbody data-bill-rows></tbody></table>';

	echo '<div class="gacct-op-quote-add">';
	echo '<button type="button" class="button button-small" data-op-action="bill-add-catalog">+ ' . esc_html__( 'Prestation du catalogue', 'gestion-atelier-cct' ) . '</button> ';
	echo '<button type="button" class="button button-small" data-op-action="bill-add-free">+ ' . esc_html__( 'Ligne libre (ou remise)', 'gestion-atelier-cct' ) . '</button>';
	echo '</div>';

	echo '<p class="gacct-op-quote-total">' . esc_html__( 'Total des lignes à ajouter :', 'gestion-atelier-cct' ) . ' <strong data-bill-total>0,00 €</strong></p>';

	echo '<button type="button" class="button button-primary" data-op-action="billing-add">' . esc_html__( 'Ajouter à la facture', 'gestion-atelier-cct' ) . '</button>';
	echo '<p class="gacct-op-muted">' . esc_html__( 'Remise : ligne libre avec un montant négatif. Aucun email n\'est envoyé au client — chaque mouvement est journalisé en note de commande. Le montant s\'ajoute (ou se retranche) au solde de fin d\'intervention.', 'gestion-atelier-cct' ) . '</p>';

	echo '</div>'; // .gacct-op-billing-form
	echo '</div>'; // .gacct-op-billing-card
}

/**
 * Écran fiche : une colonne mobile, 2 colonnes >= 1024 px.
 *
 * @param int $revision_id ID du CCT revision.
 */
function gacct_op_render_fiche_screen( $revision_id ) {
	$revision_id = absint( $revision_id );
	$revision    = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

	if ( ! $revision ) {
		echo '<div class="wrap gacct-op gacct-op-fiche">';
		echo '<p class="gacct-op-back"><a href="' . esc_url( gacct_op_console_url( 0, array( 'view' => 'list' ) ) ) . '">&larr; ' . esc_html__( 'Interventions', 'gestion-atelier-cct' ) . '</a></p>';
		echo '<div class="gacct-op-card"><h2>' . esc_html__( 'Dossier introuvable', 'gestion-atelier-cct' ) . '</h2>';
		echo '<p>' . esc_html__( 'Aucune fiche ne correspond à cet identifiant.', 'gestion-atelier-cct' ) . '</p></div></div>';
		return;
	}

	$state       = absint( $revision['etat_de_la_commande'] ?? 0 );
	$labels      = gacct_op_state_labels();
	$state_label = isset( $labels[ $state ] ) ? $labels[ $state ] : (string) $state;
	$order       = gacct_op_get_order_for_revision( $revision );
	$slot        = gacct_op_fiche_get_slot( $revision );

	// État 5 : le libellé se précise selon la décision du client sur le devis.
	if ( 5 === $state ) {
		$state_label .= gacct_state5_suffix( $order );
	}

	$is_cancelled = $order && $order->has_status( array( 'cancelled', 'refunded', 'trash' ) );

	// Dossier incomplet (CDC §4.4) : bandeau + verrou du passage en intervention.
	$is_incomplete = ( '1' === (string) ( $revision['dossier_incomplet'] ?? '' ) );
	$reception_url = gacct_op_console_url( 0, array( 'view' => 'reception', 'rev' => $revision_id ) );

	// Mise en attente (drapeau opérateur, pas un état) : badge + bandeau + actions.
	$hold = gacct_hold_info( $revision );

	echo '<div class="wrap gacct-op gacct-op-fiche" data-revision-id="' . esc_attr( $revision_id ) . '">';

	// 1. Lien retour.
	echo '<p class="gacct-op-back"><a href="' . esc_url( gacct_op_console_url( 0, array( 'view' => 'list' ) ) ) . '">&larr; ' . esc_html__( 'Interventions', 'gestion-atelier-cct' ) . '</a></p>';

	echo '<div class="gacct-op-fiche-grid">';
	echo '<div class="gacct-op-fiche-main">';

	// ---------------------------------------------------------------- En-tête.
	echo '<div class="gacct-op-card gacct-op-head">';

	$reference = $order ? $order->get_order_number() : sprintf( __( 'Dossier #%d', 'gestion-atelier-cct' ), $revision_id );
	echo '<div class="gacct-op-head-top">';
	echo '<span class="gacct-op-ref">' . esc_html( $reference ) . '</span> ';
	echo '<span class="gacct-op-badge etat-' . esc_attr( $state ) . '">' . esc_html( $state_label ) . '</span>';
	if ( $hold['active'] ) {
		echo ' <span class="gacct-op-badge gacct-op-badge-hold">' . esc_html__( 'En attente', 'gestion-atelier-cct' ) . '</span>';
	}
	if ( $is_cancelled ) {
		echo ' <span class="gacct-op-badge gacct-op-badge-cancelled">' . esc_html__( 'Commande annulée', 'gestion-atelier-cct' ) . '</span>';
	}
	echo '</div>';

	if ( ! $order ) {
		echo '<div class="gacct-op-warning">' . esc_html__( 'Aucune commande liée à ce dossier : les actions sont désactivées (lecture seule).', 'gestion-atelier-cct' ) . '</div>';
	}

	// Bandeau « dossier incomplet » (CDC §4.4).
	if ( $is_incomplete ) {
		$missing_items = gacct_op_missing_items( $revision );

		echo '<div class="gacct-op-warning gacct-op-incomplete">';
		echo '<strong>' . esc_html__( 'Dossier incomplet', 'gestion-atelier-cct' ) . '</strong> — ' . esc_html__( 'éléments manquants :', 'gestion-atelier-cct' ) . ' ';
		echo esc_html( $missing_items ? implode( ', ', $missing_items ) : __( '(liste non renseignée)', 'gestion-atelier-cct' ) );
		echo ' <a class="gacct-op-incomplete-link" href="' . esc_url( $reception_url ) . '">' . esc_html__( 'Compléter la réception', 'gestion-atelier-cct' ) . '</a>';
		echo '</div>';
	}

	// Bandeau « dossier en attente » (drapeau opérateur).
	if ( $hold['active'] ) {
		echo '<div class="gacct-op-warning gacct-op-hold-banner">';
		echo '<strong>' . esc_html__( 'Dossier en attente', 'gestion-atelier-cct' ) . '</strong> — ' . esc_html__( 'motif :', 'gestion-atelier-cct' ) . ' ';
		echo esc_html( '' !== $hold['motif'] ? $hold['motif'] : __( '(non renseigné)', 'gestion-atelier-cct' ) );
		echo '</div>';
	}

	// État 1 : accès direct à la vue réception du dossier.
	if ( 1 === $state ) {
		echo '<p class="gacct-op-reception-cta"><a href="' . esc_url( $reception_url ) . '">' . esc_html__( 'Réceptionner ce colis', 'gestion-atelier-cct' ) . ' &rarr;</a></p>';
	}

	echo '<dl class="gacct-op-facts">';

	if ( $order ) {
		$client = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$phone  = $order->get_billing_phone();
		$email  = $order->get_billing_email();

		echo '<div><dt>' . esc_html__( 'Client', 'gestion-atelier-cct' ) . '</dt><dd>' . esc_html( $client ? $client : '—' ) . '</dd></div>';

		echo '<div><dt>' . esc_html__( 'Téléphone', 'gestion-atelier-cct' ) . '</dt><dd>';
		echo $phone ? '<a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>' : '—';
		echo '</dd></div>';

		echo '<div><dt>' . esc_html__( 'Email', 'gestion-atelier-cct' ) . '</dt><dd>';
		echo $email ? '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>' : '—';
		echo '</dd></div>';
	}

	$materiel = array_filter( array(
		$revision['marque'] ?? '',
		$revision['modele'] ?? '',
		$revision['taille'] ?? '',
		$revision['couleur'] ?? '',
	) );
	echo '<div><dt>' . esc_html__( 'Matériel', 'gestion-atelier-cct' ) . '</dt><dd>' . esc_html( $materiel ? implode( ' · ', $materiel ) : '—' ) . '</dd></div>';

	$serial = trim( (string) ( $revision['numero_de_serie'] ?? '' ) );
	echo '<div><dt>' . esc_html__( 'N° de série', 'gestion-atelier-cct' ) . '</dt><dd>' . esc_html( $serial ? $serial : '—' ) . '</dd></div>';

	$ptv = trim( (string) ( $revision['p_t_v'] ?? '' ) );
	echo '<div><dt>' . esc_html__( 'PTV', 'gestion-atelier-cct' ) . '</dt><dd>' . esc_html( $ptv ? $ptv : '—' ) . '</dd></div>';

	// Envoi déclaré par le client (transporteur + n° de suivi, gacct-shipping.php).
	$ship = function_exists( 'gacct_ship_info' ) ? gacct_ship_info( $revision ) : null;
	if ( $ship ) {
		echo '<div><dt>' . esc_html__( 'Envoi client', 'gestion-atelier-cct' ) . '</dt><dd>';
		$ship_text = trim( $ship['carrier_label'] . ' ' . sprintf( __( 'n° %s', 'gestion-atelier-cct' ), $ship['number'] ) );
		echo $ship['url']
			? '<a href="' . esc_url( $ship['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $ship_text ) . '</a>'
			: esc_html( $ship_text );
		echo '</dd></div>';
	}

	echo '<div><dt>' . esc_html__( 'Créneau', 'gestion-atelier-cct' ) . '</dt><dd>';
	if ( $slot && ! empty( $slot['date_reservee'] ) ) {
		$ts = is_numeric( $slot['date_reservee'] ) ? (int) $slot['date_reservee'] : strtotime( $slot['date_reservee'] );
		echo esc_html( date_i18n( get_option( 'date_format' ), $ts ) );
		if ( ! empty( $slot['duree_totale_commande'] ) ) {
			echo ' — ' . esc_html( sprintf( __( 'durée %s', 'gestion-atelier-cct' ), $slot['duree_totale_commande'] ) );
		}
	} else {
		echo esc_html__( 'Aucun créneau (libéré ou non planifié)', 'gestion-atelier-cct' );
	}
	echo '</dd></div>';

	// « Réalisé par ».
	$operator_id = absint( $revision['operateur_id'] ?? 0 );
	$choices     = gacct_op_operator_choices();

	echo '<div class="gacct-op-fact-operator"><dt>' . esc_html__( 'Réalisé par', 'gestion-atelier-cct' ) . '</dt><dd>';
	echo '<span class="gacct-op-operator-row">';
	echo '<select data-op-field="operator" aria-label="' . esc_attr__( 'Opérateur ayant réalisé l\'intervention', 'gestion-atelier-cct' ) . '">';
	echo '<option value="0">' . esc_html__( '—', 'gestion-atelier-cct' ) . '</option>';
	foreach ( $choices as $uid => $name ) {
		echo '<option value="' . esc_attr( $uid ) . '"' . selected( $operator_id, $uid, false ) . '>' . esc_html( $name ) . '</option>';
	}
	echo '</select> ';
	echo '<button type="button" class="button button-small" data-op-action="set-operator">' . esc_html__( 'Enregistrer', 'gestion-atelier-cct' ) . '</button>';
	echo '</span>';
	echo '<span class="gacct-op-operator-feedback" aria-live="polite"></span>';
	echo '</dd></div>';

	echo '</dl>';
	echo '</div>'; // .gacct-op-head

	// ---------------------------------------------------------------- Frise 0→8.
	echo '<div class="gacct-op-card gacct-op-steps-card">';
	echo '<h2>' . esc_html__( 'Avancement', 'gestion-atelier-cct' ) . '</h2>';
	echo '<ol class="gacct-op-steps">';
	foreach ( $labels as $i => $label ) {
		$class = 'gacct-op-step';
		if ( $i < $state ) {
			$class .= ' done';
		} elseif ( $i === $state ) {
			$class .= ' current';
		}
		if ( 5 === $i ) {
			$label .= gacct_state5_suffix( $order );
		}
		echo '<li class="' . esc_attr( $class ) . '"><span class="gacct-op-step-dot">' . esc_html( $i ) . '</span><span class="gacct-op-step-label">' . esc_html( $label ) . '</span></li>';
	}
	echo '</ol>';
	echo '</div>';

	// ---------------------------------------------------------------- Actions.
	echo '<div class="gacct-op-card gacct-op-actions-card">';
	echo '<h2>' . esc_html__( 'Actions', 'gestion-atelier-cct' ) . '</h2>';
	echo '<div class="gacct-op-feedback" aria-live="polite"></div>';

	if ( ! $order ) {
		echo '<p class="gacct-op-muted">' . esc_html__( 'Aucune commande liée : les changements d\'état sont désactivés.', 'gestion-atelier-cct' ) . '</p>';
	} else {
		$allowed   = gacct_op_allowed_transitions();
		$forceable = gacct_op_forceable_transitions();
		$has_action = false;

		if ( ! empty( $allowed[ $state ] ) ) {
			foreach ( $allowed[ $state ] as $target => $action_label ) {
				if ( 4 === $target && 3 === $state ) {
					continue; // 3→4 passe par la carte « Devis complémentaire » ci-dessous.
				}
				if ( 8 === $target ) {
					continue; // 7→8 passe par le formulaire « Expédition retour » ci-dessous.
				}
				$has_action = true;

				// Dossier incomplet : le démarrage de l'intervention exige un motif de déblocage (CDC §4.4).
				if ( 3 === $target && $is_incomplete ) {
					echo '<div class="gacct-op-force">';
					echo '<button type="button" class="button button-primary" data-op-action="toggle-force" aria-expanded="false">' . esc_html( $action_label ) . '…</button>';
					echo '<div class="gacct-op-force-form" hidden>';
					echo '<p class="gacct-op-muted">' . esc_html__( 'Dossier incomplet : des éléments attendus ne sont pas arrivés.', 'gestion-atelier-cct' ) . '</p>';
					echo '<label class="gacct-op-label">' . esc_html__( 'Motif de déblocage (obligatoire, journalisé)', 'gestion-atelier-cct' ) . '</label>';
					echo '<textarea rows="2" data-op-field="unlock-reason"></textarea>';
					echo '<button type="button" class="button button-primary" data-op-action="change-state" data-state="' . esc_attr( $target ) . '" data-unlock="1">' . esc_html__( 'Débloquer et lancer l\'intervention', 'gestion-atelier-cct' ) . '</button>';
					echo '</div></div>';
					continue;
				}

				echo '<button type="button" class="button button-primary" data-op-action="change-state" data-state="' . esc_attr( $target ) . '">' . esc_html( $action_label ) . '</button>';
			}
		}

		// Le dépôt / la génération des rapports vit désormais dans la carte
		// « Rapports de contrôle » ci-dessous (états 3 à 6).

		// 7→8 : réexpédition du matériel, suivi transporteur obligatoire.
		if ( 7 === $state ) {
			$has_action = true;
			$suivi_pre  = trim( (string) ( $revision['suivi_transporteur'] ?? '' ) );

			echo '<div class="gacct-op-ship-form">';
			echo '<label class="gacct-op-label" for="gacct-op-tracking">' . esc_html__( 'Suivi transporteur (numéro ou lien, obligatoire)', 'gestion-atelier-cct' ) . '</label>';
			echo '<input type="text" id="gacct-op-tracking" data-op-field="tracking" value="' . esc_attr( $suivi_pre ) . '" placeholder="' . esc_attr__( 'Ex. : 6A12345678901 ou https://…', 'gestion-atelier-cct' ) . '">';
			echo '<button type="button" class="button button-primary" data-op-action="change-state" data-state="8" data-tracking="1">' . esc_html__( 'Matériel réexpédié', 'gestion-atelier-cct' ) . '</button>';
			echo '<p class="gacct-op-muted">' . esc_html__( 'Le client reçoit un email avec le lien de suivi.', 'gestion-atelier-cct' ) . '</p>';
			echo '</div>';
		}

		// Renvoi d'email (états 4 et 6).
		if ( array_key_exists( $state, gacct_op_resendable_states() ) ) {
			$has_action = true;
			echo '<button type="button" class="button" data-op-action="resend-email">' . esc_html__( 'Renvoyer l\'email au client', 'gestion-atelier-cct' ) . '</button>';
		}

		// Transitions forçables : bouton + formulaire inline motif.
		if ( ! empty( $forceable[ $state ] ) ) {
			foreach ( $forceable[ $state ] as $target => $force_label ) {
				$has_action = true;
				echo '<div class="gacct-op-force">';
				echo '<button type="button" class="button" data-op-action="toggle-force" aria-expanded="false">' . esc_html( $force_label ) . '…</button>';
				echo '<div class="gacct-op-force-form" hidden>';
				echo '<label class="gacct-op-label">' . esc_html__( 'Motif (obligatoire, journalisé)', 'gestion-atelier-cct' ) . '</label>';
				echo '<textarea rows="2" data-op-field="force-reason"></textarea>';

				// Forçage vers l'intervention d'un dossier incomplet : motif de déblocage en plus (CDC §4.4).
				$needs_unlock = ( 3 === $target && $is_incomplete );
				if ( $needs_unlock ) {
					echo '<label class="gacct-op-label">' . esc_html__( 'Motif de déblocage du dossier incomplet (obligatoire, journalisé)', 'gestion-atelier-cct' ) . '</label>';
					echo '<textarea rows="2" data-op-field="unlock-reason"></textarea>';
				}

				echo '<button type="button" class="button button-primary" data-op-action="change-state" data-state="' . esc_attr( $target ) . '" data-force="1" data-confirm="1"' . ( $needs_unlock ? ' data-unlock="1"' : '' ) . '>' . esc_html__( 'Confirmer le forçage', 'gestion-atelier-cct' ) . '</button>';
				echo '</div></div>';
			}
		}

		// États 6+ : lien du rapport pour l'atelier (le client ne le voit qu'à 7).
		if ( $state >= 6 ) {
			$rapport_ids = gacct_report_ids( $revision['rapport_pdf'] ?? '' );
			if ( $rapport_ids ) {
				foreach ( $rapport_ids as $rapport_index => $rapport_id ) {
					$libelle = $rapport_index > 0
						? sprintf( __( 'Voir le rapport PDF %d', 'gestion-atelier-cct' ), $rapport_index + 1 )
						: __( 'Voir le rapport PDF', 'gestion-atelier-cct' );
					echo '<p><a class="button" href="' . esc_url( gacct_report_download_url( $revision_id, $rapport_id ) ) . '" target="_blank" rel="noopener">' . esc_html( $libelle ) . '</a></p>';
				}
			} else {
				echo '<p class="gacct-op-muted">' . esc_html__( 'Rapport introuvable.', 'gestion-atelier-cct' ) . '</p>';
			}
		}

		// Aucun bouton : expliquer qui doit agir.
		if ( ! $has_action && 8 !== $state ) {
			$waiting = array(
				0 => __( 'En attente du paiement du client — rien à faire côté atelier.', 'gestion-atelier-cct' ),
				4 => __( 'En attente de la validation du devis par le client.', 'gestion-atelier-cct' ),
				6 => __( 'En attente du paiement du solde par le client.', 'gestion-atelier-cct' ),
			);
			$msg = isset( $waiting[ $state ] ) ? $waiting[ $state ] : __( 'Aucune action disponible pour cet état.', 'gestion-atelier-cct' );
			echo '<p class="gacct-op-muted">' . esc_html( $msg ) . '</p>';
		}

		// Mise en attente / reprise (drapeau, pas un état) — réunion du 06/08/2026.
		if ( ! $is_cancelled ) {
			if ( $hold['active'] ) {
				echo '<div class="gacct-op-force gacct-op-hold-zone">';
				echo '<button type="button" class="button" data-op-action="toggle-force" aria-expanded="false">' . esc_html__( 'Reprendre le dossier', 'gestion-atelier-cct' ) . '…</button>';
				echo '<div class="gacct-op-force-form" hidden>';
				echo '<p class="gacct-op-muted">' . esc_html__( 'Le client reçoit un email l\'informant que son dossier reprend son cours.', 'gestion-atelier-cct' ) . '</p>';
				echo '<label class="gacct-op-label">' . esc_html__( 'Message pour le client (facultatif, envoyé dans l\'email)', 'gestion-atelier-cct' ) . '</label>';
				echo '<textarea rows="3" data-op-field="resume-message" placeholder="' . esc_attr__( 'Ex. : la pièce constructeur est arrivée, votre voile repasse en atelier cette semaine…', 'gestion-atelier-cct' ) . '"></textarea>';
				echo '<button type="button" class="button button-primary" data-op-action="resume">' . esc_html__( 'Reprendre le dossier et prévenir le client', 'gestion-atelier-cct' ) . '</button>';
				echo '</div></div>';
			} elseif ( $state >= 2 && $state <= 6 ) {
				echo '<div class="gacct-op-force gacct-op-hold-zone">';
				echo '<button type="button" class="button" data-op-action="toggle-force" aria-expanded="false">' . esc_html__( 'Mettre en attente', 'gestion-atelier-cct' ) . '…</button>';
				echo '<div class="gacct-op-force-form" hidden>';
				echo '<p class="gacct-op-muted">' . esc_html__( 'Signale au client que son dossier est en pause (ex. attente d\'une pièce constructeur). Le dossier reste dans son état actuel ; vous lèverez l\'attente vous-même.', 'gestion-atelier-cct' ) . '</p>';
				echo '<label class="gacct-op-label">' . esc_html__( 'Motif (obligatoire, envoyé au client dans l\'email)', 'gestion-atelier-cct' ) . '</label>';
				echo '<textarea rows="3" data-op-field="hold-motif" placeholder="' . esc_attr__( 'Ex. : nous attendons une pièce du constructeur, votre voile est en pause quelques jours…', 'gestion-atelier-cct' ) . '"></textarea>';
				echo '<button type="button" class="button button-primary" data-op-action="hold">' . esc_html__( 'Mettre en attente et prévenir le client', 'gestion-atelier-cct' ) . '</button>';
				echo '</div></div>';
			}
		}

		// Annulation du dossier (séparée), masquée si annulée ou dossier clos.
		if ( ! $is_cancelled && $state < 7 ) {
			echo '<div class="gacct-op-cancel-zone">';
			echo '<button type="button" class="button-link button-link-delete" data-op-action="cancel">' . esc_html__( 'Annuler le dossier', 'gestion-atelier-cct' ) . '</button>';
			echo '</div>';
		}
	}

	echo '</div>'; // actions

	// ---------------------------------------------------------------- Devis complémentaire.
	gacct_op_render_quote_card( $revision_id, $revision, $order, $state );

	// ---------------------------------------------------------------- Facturation & suppléments.
	gacct_op_render_billing_card( $revision_id, $revision, $order, $state );

	// ---------------------------------------------------------------- Rapports de contrôle.
	if ( function_exists( 'gacct_report_render_card' ) ) {
		gacct_report_render_card( $revision_id, $revision, $order, $state );
	}

	// ---------------------------------------------------------------- Notes + journal.
	echo '<div class="gacct-op-card gacct-op-notes-card">';
	echo '<h2>' . esc_html__( 'Notes internes atelier', 'gestion-atelier-cct' ) . '</h2>';
	echo '<p class="gacct-op-muted">' . esc_html__( 'Visible de l\'atelier uniquement (sauf notes client).', 'gestion-atelier-cct' ) . '</p>';

	if ( $order ) {
		echo '<div class="gacct-op-note-form">';
		echo '<textarea rows="3" data-op-field="note" placeholder="' . esc_attr__( 'Votre note…', 'gestion-atelier-cct' ) . '"></textarea>';
		echo '<button type="button" class="button button-primary" data-op-action="add-note">' . esc_html__( 'Ajouter la note', 'gestion-atelier-cct' ) . '</button>';
		echo '</div>';

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		if ( $notes ) {
			echo '<ul class="gacct-op-journal">';
			foreach ( $notes as $note ) {
				echo '<li class="gacct-op-journal-item' . ( $note->customer_note ? ' is-customer' : '' ) . '">';
				echo '<div class="gacct-op-journal-meta">';
				echo '<span class="gacct-op-journal-date">' . esc_html( $note->date_created ? $note->date_created->date_i18n( 'd/m/Y H:i' ) : '' ) . '</span>';
				if ( $note->customer_note ) {
					echo ' <span class="gacct-op-badge gacct-op-badge-client">' . esc_html__( 'note client', 'gestion-atelier-cct' ) . '</span>';
				}
				echo '</div>';
				echo '<div class="gacct-op-journal-content">' . wp_kses_post( wpautop( $note->content ) ) . '</div>';
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="gacct-op-muted">' . esc_html__( 'Aucune note pour l\'instant.', 'gestion-atelier-cct' ) . '</p>';
		}
	} else {
		echo '<p class="gacct-op-muted">' . esc_html__( 'Notes indisponibles sans commande liée.', 'gestion-atelier-cct' ) . '</p>';
	}

	echo '</div>'; // notes
	echo '</div>'; // .gacct-op-fiche-main

	// ================================================================ Latérale.
	echo '<div class="gacct-op-fiche-side">';

	// Paiements (lecture seule).
	echo '<div class="gacct-op-card">';
	echo '<h2>' . esc_html__( 'Paiements', 'gestion-atelier-cct' ) . '</h2>';

	if ( $order ) {
		$status_name = wc_get_order_status_name( $order->get_status() );
		if ( 'bacs' === $order->get_payment_method() && $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			$status_name = __( 'En attente de virement', 'gestion-atelier-cct' );
		}

		echo '<dl class="gacct-op-facts">';
		echo '<div><dt>' . esc_html__( 'Total commande', 'gestion-atelier-cct' ) . '</dt><dd>' . wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Statut', 'gestion-atelier-cct' ) . '</dt><dd>' . esc_html( $status_name ) . '</dd></div>';
		if ( $order->get_payment_method_title() ) {
			echo '<div><dt>' . esc_html__( 'Méthode', 'gestion-atelier-cct' ) . '</dt><dd>' . esc_html( $order->get_payment_method_title() ) . '</dd></div>';
		}

		if ( function_exists( 'gacct_kojito_total_initial' ) ) {
			$total_initial = gacct_kojito_total_initial( $order );
			if ( $total_initial > 0 && abs( $total_initial - (float) $order->get_total() ) > 0.005 ) {
				echo '<div><dt>' . esc_html__( 'Total dû (acompte + solde)', 'gestion-atelier-cct' ) . '</dt><dd>' . wp_kses_post( wc_price( $total_initial, array( 'currency' => $order->get_currency() ) ) ) . '</dd></div>';
			}
		}

		$solde = $order->get_meta( '_kojito_solde_restant' );
		if ( '' !== $solde && null !== $solde ) {
			echo '<div><dt>' . esc_html__( 'Solde restant', 'gestion-atelier-cct' ) . '</dt><dd>' . wp_kses_post( wc_price( (float) $solde, array( 'currency' => $order->get_currency() ) ) );
			echo '<br><a href="' . esc_url( $order->get_checkout_payment_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Lien de paiement du solde', 'gestion-atelier-cct' ) . '</a></dd></div>';
		}
		echo '</dl>';

		// Relance de paiement manuelle — mêmes conditions que gacct_op_manual_payment_reminder().
		$can_remind = ( 'bacs' === $order->get_payment_method() && $order->has_status( array( 'on-hold', 'pending' ) ) )
			|| $order->has_status( array( 'pending', 'failed' ) );

		if ( $can_remind ) {
			echo '<div class="gacct-op-feedback gacct-op-pay-feedback" aria-live="polite"></div>';
			if ( 'bacs' === $order->get_payment_method() && in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
				echo '<p><button type="button" class="button button-primary" data-op-action="confirm-deposit">' . esc_html__( 'Acompte encaissé (virement reçu)', 'gestion-atelier-cct' ) . '</button></p>';
			}
			echo '<p><button type="button" class="button" data-op-action="payment-reminder">' . esc_html__( 'Relancer le paiement maintenant', 'gestion-atelier-cct' ) . '</button></p>';
		}

		if ( current_user_can( 'manage_woocommerce' ) ) {
			echo '<p><a class="button" href="' . esc_url( $order->get_edit_order_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ouvrir la commande', 'gestion-atelier-cct' ) . '</a></p>';
		}
	} else {
		echo '<p class="gacct-op-muted">' . esc_html__( 'Aucune commande liée.', 'gestion-atelier-cct' ) . '</p>';
	}
	echo '</div>';

	// Expédition retour.
	echo '<div class="gacct-op-card">';
	echo '<h2>' . esc_html__( 'Expédition retour', 'gestion-atelier-cct' ) . '</h2>';
	$suivi = trim( (string) ( $revision['suivi_transporteur'] ?? '' ) );
	if ( $suivi && preg_match( '#^https?://#i', $suivi ) ) {
		echo '<p><a class="button" href="' . esc_url( $suivi ) . '" target="_blank" rel="noopener">' . esc_html__( 'Suivre le colis', 'gestion-atelier-cct' ) . '</a></p>';
	} elseif ( $suivi ) {
		echo '<p>' . esc_html( $suivi ) . '</p>';
	} else {
		echo '<p class="gacct-op-muted">—</p>';
	}
	echo '</div>';

	// Prestations commandées.
	echo '<div class="gacct-op-card">';
	echo '<h2>' . esc_html__( 'Prestations commandées', 'gestion-atelier-cct' ) . '</h2>';
	if ( $order && $order->get_items() ) {
		echo '<ul class="gacct-op-items">';
		foreach ( $order->get_items() as $item ) {
			$line = ( function_exists( 'gacct_kojito_montant_ligne' ) )
				? gacct_kojito_montant_ligne( $item )
				: (float) $item->get_total() + (float) $item->get_total_tax();
			echo '<li><span class="gacct-op-item-name">' . esc_html( $item->get_name() ) . '</span><span class="gacct-op-item-total">' . wp_kses_post( wc_price( $line, array( 'currency' => $order->get_currency() ) ) ) . '</span></li>';
		}
		echo '</ul>';
	} else {
		echo '<p class="gacct-op-muted">' . esc_html__( 'Aucune ligne de commande.', 'gestion-atelier-cct' ) . '</p>';
	}
	if ( ! empty( $revision['commentaire_commande'] ) ) {
		echo '<p class="gacct-op-muted"><strong>' . esc_html__( 'Commentaire client :', 'gestion-atelier-cct' ) . '</strong> ' . esc_html( $revision['commentaire_commande'] ) . '</p>';
	}
	if ( ! empty( $revision['commentaire_reception'] ) ) {
		echo '<p class="gacct-op-muted gacct-op-reception-note"><strong>' . esc_html__( 'Commentaire de réception :', 'gestion-atelier-cct' ) . '</strong> ' . nl2br( esc_html( $revision['commentaire_reception'] ) ) . '</p>';
	}
	echo '</div>';

	echo '</div>'; // side
	echo '</div>'; // grid
	echo '</div>'; // wrap
}
