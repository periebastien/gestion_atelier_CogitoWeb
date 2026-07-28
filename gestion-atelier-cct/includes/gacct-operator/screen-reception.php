<?php
/**
 * Console atelier — écran « Réception d'un colis » (CDC §4.4).
 *
 * URL : admin.php?page=gacct-console&view=reception[&ref=…][&rev=…]
 * - sans ref : champ de recherche centré (le scan QR arrivera en V3) ;
 * - avec ref : résolution via gacct_op_query_interventions (0, 1 ou N résultats) ;
 * - avec rev : dossier + check-list du contenu attendu → endpoint gacct_op_receive.
 *
 * La barre de navigation console (champ « Scan ou référence ») est rendue
 * par le routeur, pas ici.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Écran principal.
 */
function gacct_op_render_reception_screen() {
	$ref    = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : '';
	$rev_id = isset( $_GET['rev'] ) ? absint( wp_unslash( $_GET['rev'] ) ) : 0;

	echo '<div class="wrap gacct-op gacct-op-reception">';
	echo '<h1>' . esc_html__( 'Réception d\'un colis', 'gestion-atelier-cct' ) . '</h1>';

	if ( $rev_id ) {
		gacct_op_reception_render_dossier( $rev_id );
	} elseif ( '' !== $ref ) {
		gacct_op_reception_render_results( $ref );
	} else {
		gacct_op_reception_render_search( '' );
	}

	echo '</div>';
}

/**
 * Formulaire de recherche « Scan ou référence » (GET vers cette vue).
 *
 * @param string $ref Valeur pré-remplie.
 */
function gacct_op_reception_render_search( $ref ) {
	echo '<div class="gacct-op-card gacct-op-reception-search">';
	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" role="search">';
	echo '<input type="hidden" name="page" value="' . esc_attr( GACCT_OP_MENU_SLUG ) . '">';
	echo '<input type="hidden" name="view" value="reception">';
	echo '<label class="gacct-op-reception-search-label" for="gacct-op-reception-ref">' . esc_html__( 'Scan ou référence', 'gestion-atelier-cct' ) . '</label>';
	echo '<input type="search" id="gacct-op-reception-ref" name="ref" value="' . esc_attr( $ref ) . '" autofocus placeholder="' . esc_attr__( 'AR-2026-1621, n° de série, marque, nom…', 'gestion-atelier-cct' ) . '">';
	echo '<button type="submit" class="gacct-op-btn gacct-op-reception-search-btn">' . esc_html__( 'Ouvrir le dossier', 'gestion-atelier-cct' ) . '</button>';
	echo '<p class="gacct-op-reception-hint">' . esc_html__( 'Saisissez la référence du colis (ou le nom du client) pour ouvrir le dossier et pointer son contenu.', 'gestion-atelier-cct' ) . '</p>';
	echo '</form>';
	echo '</div>';
}

/**
 * Résultats de la recherche : 0 → message, 1 → dossier direct, N → cartes.
 *
 * @param string $ref Saisie de l'opérateur.
 */
function gacct_op_reception_render_results( $ref ) {
	$result = gacct_op_query_interventions( array(
		'search'   => $ref,
		'per_page' => 10,
	) );

	$items = $result['items'];

	if ( ! $items ) {
		echo '<div class="gacct-op-feedback error gacct-op-reception-noresult">'
			. esc_html( sprintf(
				/* translators: %s: texte recherché */
				__( 'Aucun dossier trouvé pour « %s ».', 'gestion-atelier-cct' ),
				$ref
			) )
			. '</div>';
		gacct_op_reception_render_search( $ref );
		return;
	}

	if ( 1 === count( $items ) ) {
		gacct_op_reception_render_dossier( absint( $items[0]['_ID'] ?? 0 ) );
		return;
	}

	$labels = gacct_op_state_labels();

	echo '<p class="gacct-op-reception-hint">' . esc_html( sprintf(
		/* translators: 1: nombre de résultats, 2: texte recherché */
		__( '%1$d dossiers correspondent à « %2$s » — choisissez le bon :', 'gestion-atelier-cct' ),
		count( $items ),
		$ref
	) ) . '</p>';

	echo '<div class="gacct-op-reception-cards">';

	foreach ( $items as $item ) {
		$rev_id   = absint( $item['_ID'] ?? 0 );
		$order_id = absint( $item['order_id'] ?? 0 );
		$order    = ( $order_id && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : false;
		$state    = absint( $item['etat_de_la_commande'] ?? 0 );
		$url      = gacct_op_console_url( 0, array( 'view' => 'reception', 'rev' => $rev_id ) );

		$reference = $order ? $order->get_order_number() : sprintf( __( 'Dossier #%d', 'gestion-atelier-cct' ), $rev_id );
		$client    = $order ? $order->get_formatted_billing_full_name() : '';
		$materiel  = gacct_op_reception_materiel_label( $item );

		echo '<a class="gacct-op-reception-cardlink" href="' . esc_url( $url ) . '">';
		echo '<span class="gacct-op-reception-cardref">' . esc_html( $reference ) . '</span>';
		if ( '' !== $client ) {
			echo '<span class="gacct-op-reception-cardclient">' . esc_html( $client ) . '</span>';
		}
		if ( '' !== $materiel ) {
			echo '<span class="gacct-op-reception-cardmat">' . esc_html( $materiel ) . '</span>';
		}
		echo '<span class="gacct-op-badge etat-' . esc_attr( $state ) . '">' . esc_html( $labels[ $state ] ?? (string) $state ) . '</span>';
		echo '</a>';
	}

	echo '</div>';
}

/**
 * Libellé « matériel » d'une révision (marque, modèle, taille, couleur, n° de série).
 *
 * @param array $revision Ligne du CCT revision.
 * @return string
 */
function gacct_op_reception_materiel_label( array $revision ) {
	$parts = array_filter( array(
		trim( (string) ( $revision['marque'] ?? '' ) ),
		trim( (string) ( $revision['modele'] ?? '' ) ),
		trim( (string) ( $revision['taille'] ?? '' ) ),
		trim( (string) ( $revision['couleur'] ?? '' ) ),
	) );

	$label = implode( ' · ', $parts );
	$serie = trim( (string) ( $revision['numero_de_serie'] ?? '' ) );

	if ( '' !== $serie ) {
		$label .= ( $label ? ' — ' : '' ) . sprintf( __( 'n° %s', 'gestion-atelier-cct' ), $serie );
	}

	return $label;
}

/**
 * Le dossier : en-tête + check-list de réception selon l'état.
 *
 * @param int $rev_id ID du CCT revision.
 */
function gacct_op_reception_render_dossier( $rev_id ) {
	$rev_id   = absint( $rev_id );
	$revision = $rev_id ? jwcct_get_cct_item( JWCCT_CCT_REVISION, $rev_id ) : false;

	if ( ! $revision ) {
		echo '<div class="gacct-op-feedback error">' . esc_html__( 'Dossier introuvable.', 'gestion-atelier-cct' ) . '</div>';
		gacct_op_reception_render_search( '' );
		return;
	}

	$labels     = gacct_op_state_labels();
	$state      = absint( $revision['etat_de_la_commande'] ?? 0 );
	$incomplete = ! empty( $revision['dossier_incomplet'] );
	$order      = gacct_op_get_order_for_revision( $revision );
	$fiche_url  = gacct_op_console_url( $rev_id );

	$reference = $order ? $order->get_order_number() : sprintf( __( 'Dossier #%d', 'gestion-atelier-cct' ), $rev_id );
	$client    = $order ? $order->get_formatted_billing_full_name() : '';
	$materiel  = gacct_op_reception_materiel_label( $revision );

	echo '<div class="gacct-op-card gacct-op-reception-dossier" data-revision-id="' . esc_attr( $rev_id ) . '" data-fiche-url="' . esc_url( $fiche_url ) . '">';

	// --- En-tête compact.
	echo '<header class="gacct-op-reception-head">';
	echo '<div class="gacct-op-reception-headmain">';
	echo '<span class="gacct-op-reception-ref">' . esc_html( $reference ) . '</span>';
	echo '<span class="gacct-op-badge etat-' . esc_attr( $state ) . '">' . esc_html( $labels[ $state ] ?? (string) $state ) . '</span>';
	echo '</div>';
	if ( '' !== $client ) {
		echo '<p class="gacct-op-reception-client">' . esc_html( $client ) . '</p>';
	}
	if ( '' !== $materiel ) {
		echo '<p class="gacct-op-reception-materiel">' . esc_html( $materiel ) . '</p>';
	}
	echo '<a class="gacct-op-reception-fichelink" href="' . esc_url( $fiche_url ) . '">' . esc_html__( 'Voir la fiche complète', 'gestion-atelier-cct' ) . '</a>';
	echo '</header>';

	// --- Pas de commande liée : impossible de connaître le contenu attendu.
	if ( ! $order ) {
		echo '<div class="gacct-op-feedback error">' . esc_html__( 'Aucune commande liée à ce dossier : impossible d\'établir la check-list du contenu attendu.', 'gestion-atelier-cct' ) . '</div>';
		echo '</div>';
		return;
	}

	if ( $state >= 2 && ! $incomplete ) {
		// --- Déjà réceptionné et complet : rien à pointer.
		$received_on = (string) $order->get_meta( '_gacct_reception_date' );
		$when        = $received_on ? date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $received_on ) ) : '';

		echo '<div class="gacct-op-reception-banner info">';
		echo '<strong>' . esc_html__( 'Déjà réceptionné', 'gestion-atelier-cct' ) . '</strong> ';
		echo esc_html( $when
			? sprintf( __( 'Le colis de ce dossier a été réceptionné le %s. Rien à pointer ici.', 'gestion-atelier-cct' ), $when )
			: __( 'Le colis de ce dossier a déjà été réceptionné. Rien à pointer ici.', 'gestion-atelier-cct' ) );
		echo '</div>';
		echo '</div>';
		return;
	}

	if ( $state >= 2 && $incomplete ) {
		// --- 2ᵉ colis : check-list restreinte aux manquants.
		$missing = gacct_op_missing_items( $revision );

		echo '<div class="gacct-op-reception-banner warn">';
		echo '<strong>' . esc_html__( 'Dossier incomplet', 'gestion-atelier-cct' ) . '</strong> ';
		echo esc_html( sprintf(
			/* translators: %d: nombre d'éléments manquants */
			_n( '%d élément attendu n\'est pas encore arrivé. Pointez ce que contient ce colis.', '%d éléments attendus ne sont pas encore arrivés. Pointez ce que contient ce colis.', count( $missing ), 'gestion-atelier-cct' ),
			count( $missing )
		) );
		echo '</div>';

		gacct_op_reception_render_checklist( $missing, array(
			'mode'          => 'complete',
			'button_full'   => __( 'Compléter la réception', 'gestion-atelier-cct' ),
			'msg_complete'  => __( 'Dossier complet : tous les éléments attendus sont arrivés.', 'gestion-atelier-cct' ),
		) );
		echo '</div>';
		return;
	}

	// --- États 0 et 1 : check-list du contenu attendu de la commande.
	$expected = gacct_op_expected_items( $order );

	if ( 0 === $state ) {
		echo '<div class="gacct-op-reception-banner warn">';
		echo '<strong>' . esc_html__( 'Paiement non encaissé', 'gestion-atelier-cct' ) . '</strong> ';
		echo esc_html__( 'Le client a pu expédier son matériel avant l\'encaissement — c\'est autorisé. Le dossier passera réceptionnable dès l\'encaissement.', 'gestion-atelier-cct' );
		echo '</div>';
	}

	if ( ! $expected ) {
		echo '<div class="gacct-op-feedback error">' . esc_html__( 'La commande liée ne contient aucun élément attendu (hors frais de port).', 'gestion-atelier-cct' ) . '</div>';
		echo '</div>';
		return;
	}

	gacct_op_reception_render_checklist( $expected, array(
		'mode'         => 'receive',
		'disabled'     => ( 1 !== $state ),
		'button_full'  => __( 'Confirmer la réception', 'gestion-atelier-cct' ),
		'msg_complete' => __( 'Réception confirmée : le dossier est passé en « Voile réceptionnée » et le client a été prévenu.', 'gestion-atelier-cct' ),
	) );

	echo '</div>';
}

/**
 * Check-list tactile + bouton d'envoi + zone de feedback.
 *
 * @param string[] $items Libellés à pointer (tous cochés par défaut).
 * @param array    $args  { mode:string, disabled:bool, button_full:string, msg_complete:string }
 */
function gacct_op_reception_render_checklist( array $items, array $args ) {
	$disabled = ! empty( $args['disabled'] );

	echo '<div class="gacct-op-reception-checkwrap"'
		. ' data-op-checklist="' . esc_attr( $args['mode'] ) . '"'
		. ' data-label-full="' . esc_attr( $args['button_full'] ) . '"'
		. ' data-label-partial-one="' . esc_attr( __( 'Confirmer la réception partielle (1 élément manquant)', 'gestion-atelier-cct' ) ) . '"'
		. ' data-label-partial="' . esc_attr(
			/* translators: %d: nombre d'éléments non cochés */
			__( 'Confirmer la réception partielle (%d éléments manquants)', 'gestion-atelier-cct' )
		) . '"'
		. ' data-msg-complete="' . esc_attr( $args['msg_complete'] ) . '"'
		. ' data-msg-partial="' . esc_attr( __( 'Réception partielle enregistrée : un email listant les éléments manquants vient de partir au client. Le passage en intervention restera bloqué tant que tout ne sera pas arrivé.', 'gestion-atelier-cct' ) ) . '"'
		. ' data-label-fiche="' . esc_attr( __( 'Voir la fiche', 'gestion-atelier-cct' ) ) . '"'
		. '>';

	echo '<p class="gacct-op-reception-checkintro">' . esc_html__( 'Décochez ce qui manque dans le colis :', 'gestion-atelier-cct' ) . '</p>';

	echo '<ul class="gacct-op-reception-checklist">';
	foreach ( $items as $label ) {
		echo '<li>';
		echo '<label class="gacct-op-reception-row is-checked">';
		echo '<input type="checkbox" checked value="' . esc_attr( $label ) . '"' . ( $disabled ? ' disabled' : '' ) . '>';
		echo '<span class="gacct-op-reception-rowlabel">' . esc_html( $label ) . '</span>';
		echo '</label>';
		echo '</li>';
	}
	echo '</ul>';

	// Avertissement réception partielle (montré par le JS dès qu'une case est décochée).
	echo '<div class="gacct-op-reception-partialwarn" data-op-partial-warning hidden>';
	echo esc_html__( 'Des éléments sont décochés : le client recevra automatiquement un email listant les éléments manquants, et le passage en intervention sera bloqué tant que tout ne sera pas arrivé.', 'gestion-atelier-cct' );
	echo '</div>';

	echo '<div class="gacct-op-feedback" aria-live="polite"></div>';

	echo '<div class="gacct-op-reception-submitzone">';
	if ( $disabled ) {
		echo '<p class="gacct-op-reception-disabled-note">' . esc_html__( 'Bouton inactif : la réception ne sera possible qu\'une fois le paiement encaissé (le dossier passera alors en « En attente de réception »).', 'gestion-atelier-cct' ) . '</p>';
	}

	echo '<button type="button" class="gacct-op-btn gacct-op-reception-submit" data-op-action="receive"' . ( $disabled ? ' disabled' : '' ) . '>'
		. esc_html( $args['button_full'] ) . '</button>';
	echo '</div>';

	echo '</div>';
}
