<?php
/**
 * Suivi colis client — sens ALLER (client → atelier).
 *
 * Le client déclare l'expédition de son matériel : transporteur (Colissimo,
 * Chronopost…) + numéro de suivi. Facultatif, modifiable tant que la révision
 * est à l'état ≤ 1 (avant réception atelier), lecture seule ensuite.
 *
 * Stockage : colonnes CCT `envoi_transporteur` (varchar 32) et `envoi_suivi`
 * (varchar 64) de la table revision — posées par le setup versionné v4, rien
 * n'est créé ici. Écriture via `jwcct_update_cct_item()` : elle ne déclenche
 * PAS le hook updated-item de JetEngine, donc aucun e-mail d'état ne part.
 *
 * Modèle du handler POST : gacct-profile.php (template_redirect prio 5 + PRG).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
 *  TRANSPORTEURS
 * ============================================================================= */

/**
 * Transporteurs proposés au client. `url` est un motif sprintf : %s reçoit le
 * numéro de suivi (rawurlencodé).
 *
 * @return array<string,array{label:string,url:string}>
 */
function gacct_ship_carriers() {
	return (array) apply_filters(
		'gacct_ship_carriers',
		array(
			'colissimo'  => array(
				'label' => 'Colissimo',
				'url'   => 'https://www.laposte.fr/outils/suivre-vos-envois?code=%s',
			),
			'chronopost' => array(
				'label' => 'Chronopost',
				'url'   => 'https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT=%s',
			),
		)
	);
}

/**
 * URL de suivi d'un colis, ou '' si transporteur inconnu / numéro vide.
 *
 * @param string $carrier Clé de `gacct_ship_carriers()`.
 * @param string $number  Numéro de suivi.
 * @return string
 */
function gacct_ship_tracking_url( $carrier, $number ) {
	$carriers = gacct_ship_carriers();
	$carrier  = sanitize_key( (string) $carrier );
	$number   = trim( (string) $number );

	if ( '' === $number || empty( $carriers[ $carrier ]['url'] ) ) {
		return '';
	}

	return sprintf( $carriers[ $carrier ]['url'], rawurlencode( $number ) );
}

/* =============================================================================
 *  TEXTES (white-label, modèle gacct_profile_texts)
 * ============================================================================= */

/**
 * Tous les textes du bloc suivi colis, filtrables en un seul point.
 *
 * @return array<string,string>
 */
function gacct_ship_texts() {
	$defaults = array(
		'title'          => __( 'Votre numéro de suivi', 'gestion-atelier-cct' ),
		'intro'          => __( 'Dès l’envoi de votre colis, renseignez son numéro de suivi : nous saurons qu’il est en route.', 'gestion-atelier-cct' ),
		'label_carrier'  => __( 'Transporteur', 'gestion-atelier-cct' ),
		'label_number'   => __( 'Numéro de suivi', 'gestion-atelier-cct' ),
		'placeholder'    => __( 'Ex. 6A12345678901', 'gestion-atelier-cct' ),
		'choose'         => __( 'Choisir…', 'gestion-atelier-cct' ),
		'submit'         => __( 'Enregistrer mon numéro de suivi', 'gestion-atelier-cct' ),
		'update'         => __( 'Mettre à jour', 'gestion-atelier-cct' ),
		'edit'           => __( 'Modifier', 'gestion-atelier-cct' ),
		'follow'         => __( 'Suivre mon colis', 'gestion-atelier-cct' ),
		'in_transit'     => __( 'En cours d’acheminement vers l’atelier', 'gestion-atelier-cct' ),
		/* translators: 1: transporteur, 2: numéro de suivi */
		'in_transit_tip' => __( '<strong>Info :</strong> votre colis %1$s n° %2$s est en route vers l’atelier. Nous vous confirmerons sa réception.', 'gestion-atelier-cct' ),
		/* translators: 1: transporteur, 2: numéro de suivi */
		'current'        => __( 'Colis %1$s n° %2$s', 'gestion-atelier-cct' ),
		'ok_saved'       => __( 'Merci, votre numéro de suivi est enregistré.', 'gestion-atelier-cct' ),
		'err_carrier'    => __( 'Choisissez un transporteur dans la liste.', 'gestion-atelier-cct' ),
		'err_number'     => __( 'Le numéro de suivi doit comporter de 4 à 40 caractères (lettres, chiffres, tirets, espaces).', 'gestion-atelier-cct' ),
		'err_state'      => __( 'Votre matériel a déjà été réceptionné à l’atelier : le suivi n’est plus modifiable.', 'gestion-atelier-cct' ),
		'err_unpaid'     => __( 'La déclaration d’expédition sera disponible dès la réception de votre paiement.', 'gestion-atelier-cct' ),
		'err_auth'       => __( 'Vous n’êtes pas autorisé à modifier cette commande.', 'gestion-atelier-cct' ),
		'err_generic'    => __( 'L’enregistrement a échoué. Réessayez, ou contactez-nous.', 'gestion-atelier-cct' ),
	);

	return (array) apply_filters( 'gacct_ship_texts', $defaults );
}

/**
 * @param string $key Clé de `gacct_ship_texts()`.
 * @return string
 */
function gacct_ship_text( $key ) {
	$texts = gacct_ship_texts();

	return isset( $texts[ $key ] ) ? (string) $texts[ $key ] : '';
}

/* =============================================================================
 *  DONNÉES
 * ============================================================================= */

/**
 * Résout la révision liée à une commande et lit son état + ses champs envoi_*
 * en SQL DIRECT (le cache objet JetEngine peut resservir un état périmé au
 * sein d'une même requête — piège documenté, cf. gacct-quote.php).
 *
 * @param WC_Order|mixed $order
 * @return array<string,string>|null Ligne (_ID, etat_de_la_commande, envoi_*) ou null.
 */
function gacct_ship_resolve_revision( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return null;
	}

	global $wpdb;

	$table       = $wpdb->prefix . 'jet_cct_revision';
	$revision_id = absint( $order->get_meta( JWCCT_ORDER_REVISION_ID ) );

	// Repli : meta absente (liaison ratée, commande invitée) → colonne order_id,
	// même logique que gacct_sync_revision_state_on_payment().
	if ( ! $revision_id ) {
		$revision_id = absint( $wpdb->get_var( $wpdb->prepare(
			"SELECT _ID FROM {$table} WHERE order_id = %d AND cct_status = 'publish' LIMIT 1",
			$order->get_id()
		) ) );
	}

	if ( ! $revision_id ) {
		return null;
	}

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT _ID, etat_de_la_commande, envoi_transporteur, envoi_suivi FROM {$table} WHERE _ID = %d LIMIT 1",
		$revision_id
	), ARRAY_A );

	return is_array( $row ) ? $row : null;
}

/**
 * Infos de suivi déclarées sur une révision, ou null si rien de saisi.
 *
 * @param array<string,mixed>|int|null $revision Ligne CCT (avec les colonnes
 *                                               envoi_*) ou id de révision.
 * @return array{carrier:string,carrier_label:string,number:string,url:string}|null
 */
function gacct_ship_info( $revision ) {
	if ( is_numeric( $revision ) && $revision ) {
		global $wpdb;
		$revision = $wpdb->get_row( $wpdb->prepare(
			"SELECT _ID, etat_de_la_commande, envoi_transporteur, envoi_suivi FROM {$wpdb->prefix}jet_cct_revision WHERE _ID = %d LIMIT 1",
			absint( $revision )
		), ARRAY_A );
	}

	if ( ! is_array( $revision ) ) {
		return null;
	}

	$carrier = sanitize_key( (string) ( $revision['envoi_transporteur'] ?? '' ) );
	$number  = trim( (string) ( $revision['envoi_suivi'] ?? '' ) );

	if ( '' === $carrier || '' === $number ) {
		return null;
	}

	$carriers = gacct_ship_carriers();

	return array(
		'carrier'       => $carrier,
		'carrier_label' => isset( $carriers[ $carrier ]['label'] ) ? (string) $carriers[ $carrier ]['label'] : ucfirst( $carrier ),
		'number'        => $number,
		'url'           => gacct_ship_tracking_url( $carrier, $number ),
	);
}

/**
 * Le colis est-il « en cours d'acheminement vers l'atelier » ? (réunion du
 * 06/08/2026, précision Bastien du 07/08) : PAS un état de la machine 0–8,
 * un état d'AFFICHAGE dérivé — suivi déclaré tant que la voile n'est pas
 * réceptionnée. Dès l'état 2, l'affichage normal reprend.
 *
 * @param array|int $revision Ligne CCT (avec etat + envoi_*) ou ID.
 * @return array|null Les infos gacct_ship_info() si en transit, null sinon.
 */
function gacct_ship_in_transit( $revision ) {
	if ( is_numeric( $revision ) && $revision ) {
		global $wpdb;
		$revision = $wpdb->get_row( $wpdb->prepare(
			"SELECT _ID, etat_de_la_commande, envoi_transporteur, envoi_suivi FROM {$wpdb->prefix}jet_cct_revision WHERE _ID = %d LIMIT 1",
			absint( $revision )
		), ARRAY_A );
	}

	if ( ! is_array( $revision ) ) {
		return null;
	}

	$etat = ( '' === (string) ( $revision['etat_de_la_commande'] ?? '' ) ) ? 0 : (int) $revision['etat_de_la_commande'];

	if ( $etat > 1 ) {
		return null;
	}

	return gacct_ship_info( $revision );
}

/**
 * Nettoie un numéro de suivi : lettres, chiffres, tirets, espaces, 4 à 40
 * caractères. Retourne '' si invalide.
 *
 * @param string $number
 * @return string
 */
function gacct_ship_clean_number( $number ) {
	$number = trim( preg_replace( '/\s+/', ' ', (string) $number ) );

	if ( ! preg_match( '/^[A-Za-z0-9 \-]{4,40}$/', $number ) ) {
		return '';
	}

	return $number;
}

/* =============================================================================
 *  ÉCRITURE
 * ============================================================================= */

/**
 * Enregistre (ou met à jour) le suivi colis aller d'une commande.
 *
 * Refuse si transporteur inconnu, numéro invalide, révision introuvable ou
 * état ≥ 2 (matériel déjà réceptionné). Pose une note de commande.
 *
 * @param WC_Order|mixed $order
 * @param string         $carrier Clé de `gacct_ship_carriers()`.
 * @param string         $number  Numéro de suivi brut.
 * @return true|WP_Error Codes : carrier|number|revision|state|generic.
 */
function gacct_ship_save( $order, $carrier, $number ) {
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'generic', gacct_ship_text( 'err_generic' ) );
	}

	$carriers = gacct_ship_carriers();
	$carrier  = sanitize_key( (string) $carrier );

	if ( '' === $carrier || ! isset( $carriers[ $carrier ] ) ) {
		return new WP_Error( 'carrier', gacct_ship_text( 'err_carrier' ) );
	}

	$number = gacct_ship_clean_number( $number );

	if ( '' === $number ) {
		return new WP_Error( 'number', gacct_ship_text( 'err_number' ) );
	}

	$row = gacct_ship_resolve_revision( $order );

	if ( ! $row ) {
		return new WP_Error( 'revision', gacct_ship_text( 'err_generic' ) );
	}

	$etat = ( '' === (string) $row['etat_de_la_commande'] ) ? 0 : (int) $row['etat_de_la_commande'];

	if ( $etat >= 2 ) {
		return new WP_Error( 'state', gacct_ship_text( 'err_state' ) );
	}

	// Paiement non encaissé : l'expédition est verrouillée (décision Bastien du
	// 27/08/2026, qui revient sur celle du 28/07) — pas de déclaration de suivi
	// avant paiement, miroir serveur du verrou d'affichage `shipping_locked`.
	if ( function_exists( 'gacct_order_payment_received' ) && ! gacct_order_payment_received( $order ) ) {
		return new WP_Error( 'unpaid', gacct_ship_text( 'err_unpaid' ) );
	}

	$previous = gacct_ship_info( $row );

	// Rien ne change : succès silencieux, pas de note en double.
	if ( $previous && $previous['carrier'] === $carrier && $previous['number'] === $number ) {
		return true;
	}

	$updated = jwcct_update_cct_item( JWCCT_CCT_REVISION, (int) $row['_ID'], array(
		'envoi_transporteur' => $carrier,
		'envoi_suivi'        => $number,
	) );

	if ( ! $updated ) {
		return new WP_Error( 'generic', gacct_ship_text( 'err_generic' ) );
	}

	$label = (string) $carriers[ $carrier ]['label'];

	if ( $previous ) {
		$order->add_order_note( sprintf(
			/* translators: 1: transporteur, 2: nouveau n°, 3: ancien transporteur, 4: ancien n° */
			__( 'Le client a modifié son n° de suivi : %1$s n° %2$s (précédemment %3$s n° %4$s).', 'gestion-atelier-cct' ),
			$label,
			$number,
			$previous['carrier_label'],
			$previous['number']
		) );
	} else {
		$order->add_order_note( sprintf(
			/* translators: 1: transporteur, 2: n° de suivi */
			__( 'Le client a déclaré l’expédition : %1$s n° %2$s.', 'gestion-atelier-cct' ),
			$label,
			$number
		) );
	}

	return true;
}

/* =============================================================================
 *  HANDLER POST (PRG, modèle gacct-profile.php)
 * ============================================================================= */

add_action( 'template_redirect', 'gacct_ship_handle_post', 5 );

/**
 * Intercepte la soumission du formulaire suivi colis avant tout rendu.
 *
 * Auth : propriétaire connecté de la commande (customer_id + nonce), OU
 * `order_key` valide passé en hidden (cas de la page de confirmation, où le
 * client n'est pas forcément connecté). Puis PRG vers la page d'origine avec
 * `?gacct_ship=saved|error_<code>`.
 */
function gacct_ship_handle_post() {
	if ( empty( $_POST['gacct_ship_submit'] ) ) {
		return;
	}

	$order_id = isset( $_POST['gacct_ship_order'] ) ? absint( wp_unslash( $_POST['gacct_ship_order'] ) ) : 0;
	$order    = ( $order_id && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : false;

	if ( ! $order instanceof WC_Order ) {
		gacct_ship_redirect( 'error_auth', null );
	}

	$authorized = false;

	// Voie 1 : clé de commande (page de confirmation, client pas forcément connecté).
	$key = isset( $_POST['gacct_ship_key'] ) ? sanitize_text_field( wp_unslash( $_POST['gacct_ship_key'] ) ) : '';

	if ( '' !== $key && hash_equals( (string) $order->get_order_key(), $key ) ) {
		$authorized = true;
	}

	// Voie 2 : propriétaire connecté + nonce.
	if ( ! $authorized && is_user_logged_in()
		&& (int) $order->get_customer_id() === get_current_user_id()
		&& isset( $_POST['gacct_ship_nonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gacct_ship_nonce'] ) ), 'gacct_ship' ) ) {
		$authorized = true;
	}

	if ( ! $authorized ) {
		gacct_ship_redirect( 'error_auth', $order );
	}

	$carrier = isset( $_POST['gacct_ship_carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['gacct_ship_carrier'] ) ) : '';
	$number  = isset( $_POST['gacct_ship_number'] ) ? sanitize_text_field( wp_unslash( $_POST['gacct_ship_number'] ) ) : '';

	$result = gacct_ship_save( $order, $carrier, $number );

	if ( is_wp_error( $result ) ) {
		gacct_ship_redirect( 'error_' . $result->get_error_code(), $order );
	}

	gacct_ship_redirect( 'saved', $order );
}

/**
 * PRG : redirige vers la page d'origine (référent nettoyé, repli sur le détail
 * de commande) avec `?gacct_ship=<code>`, puis sort.
 *
 * @param string        $code  saved|error_*.
 * @param WC_Order|null $order Commande, pour l'URL de repli.
 */
function gacct_ship_redirect( $code, $order ) {
	$target = wp_get_referer();

	if ( ! $target ) {
		$target = ( $order instanceof WC_Order ) ? $order->get_view_order_url() : home_url( '/' );
	}

	$target = remove_query_arg( 'gacct_ship', $target );

	wp_safe_redirect( add_query_arg( 'gacct_ship', sanitize_key( $code ), $target ) );
	exit;
}

/* =============================================================================
 *  RENDU
 * ============================================================================= */

/**
 * Notice de succès / d'erreur d'après `?gacct_ship=`.
 *
 * @return string HTML (ou '').
 */
function gacct_ship_notice_html() {
	$code = isset( $_GET['gacct_ship'] ) ? sanitize_key( wp_unslash( $_GET['gacct_ship'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( '' === $code ) {
		return '';
	}

	if ( 'saved' === $code ) {
		return '<p class="gacct-ship-notice is-success">' . esc_html( gacct_ship_text( 'ok_saved' ) ) . '</p>';
	}

	$map = array(
		'error_carrier'  => 'err_carrier',
		'error_number'   => 'err_number',
		'error_state'    => 'err_state',
		'error_auth'     => 'err_auth',
		'error_revision' => 'err_generic',
		'error_generic'  => 'err_generic',
	);

	$key = isset( $map[ $code ] ) ? $map[ $code ] : '';

	if ( '' === $key ) {
		return '';
	}

	return '<p class="gacct-ship-notice is-error">' . esc_html( gacct_ship_text( $key ) ) . '</p>';
}

/**
 * Bloc complet « suivi colis aller » d'une commande.
 *
 * - Aucun suivi + état ≤ 1 : formulaire (select transporteur + n° + bouton).
 * - Suivi déclaré + état ≤ 1 : info + lien de suivi + <details> « Modifier »
 *   qui rouvre le formulaire prérempli (pas de JS).
 * - État ≥ 2 : info seule, lecture seule (rien si aucun suivi saisi).
 *
 * @param WC_Order $order
 * @param array    $args  {intro: bool — afficher la phrase d'intro (défaut true)}.
 * @return string HTML (ou '').
 */
function gacct_ship_render_form( $order, $args = array() ) {
	if ( ! $order instanceof WC_Order ) {
		return '';
	}

	$row = gacct_ship_resolve_revision( $order );

	if ( ! $row ) {
		return '';
	}

	$etat     = ( '' === (string) $row['etat_de_la_commande'] ) ? 0 : (int) $row['etat_de_la_commande'];
	$readonly = ( $etat >= 2 );
	$info     = gacct_ship_info( $row );

	if ( $readonly && ! $info ) {
		return '';
	}

	// Paiement non encaissé : pas de formulaire du tout (décision Bastien du
	// 27/08/2026) — la garde d'écriture gacct_ship_save() refuse de toute façon.
	if ( ! $readonly && ! $info
		&& function_exists( 'gacct_order_payment_received' ) && ! gacct_order_payment_received( $order ) ) {
		return '';
	}

	$show_intro = ! isset( $args['intro'] ) || $args['intro'];

	$html  = '<div class="gacct-ship">';
	$html .= gacct_ship_notice_html();

	if ( $info ) {
		$html .= '<p class="gacct-ship-current">';
		$html .= sprintf(
			esc_html( gacct_ship_text( 'current' ) ),
			'<strong>' . esc_html( $info['carrier_label'] ) . '</strong>',
			'<strong>' . esc_html( $info['number'] ) . '</strong>'
		);

		if ( '' !== $info['url'] ) {
			$html .= ' <a class="gacct-ship-follow" href="' . esc_url( $info['url'] ) . '" target="_blank" rel="noopener">'
				. esc_html( gacct_ship_text( 'follow' ) ) . '</a>';
		}

		$html .= '</p>';

		if ( ! $readonly ) {
			$html .= '<details class="gacct-ship-edit">';
			$html .= '<summary>' . esc_html( gacct_ship_text( 'edit' ) ) . '</summary>';
			$html .= gacct_ship_form_html( $order, $info );
			$html .= '</details>';
		}
	} elseif ( ! $readonly ) {
		if ( $show_intro ) {
			$html .= '<p class="gacct-ship-intro">' . esc_html( gacct_ship_text( 'intro' ) ) . '</p>';
		}

		$html .= gacct_ship_form_html( $order, null );
	}

	$html .= '</div>';

	return $html;
}

/**
 * Le formulaire lui-même (interne à `gacct_ship_render_form()`).
 *
 * @param WC_Order   $order
 * @param array|null $info  Suivi existant (préremplissage) ou null.
 * @return string
 */
function gacct_ship_form_html( $order, $info ) {
	$carriers = gacct_ship_carriers();
	$current  = $info ? $info['carrier'] : '';
	$number   = $info ? $info['number'] : '';
	$uid      = 'gacct-ship-' . (int) $order->get_id();

	$html  = '<form class="gacct-ship-form" method="post" action="">';
	$html .= '<input type="hidden" name="gacct_ship_submit" value="1">';
	$html .= '<input type="hidden" name="gacct_ship_order" value="' . (int) $order->get_id() . '">';
	$html .= '<input type="hidden" name="gacct_ship_key" value="' . esc_attr( $order->get_order_key() ) . '">';
	$html .= wp_nonce_field( 'gacct_ship', 'gacct_ship_nonce', true, false );

	$html .= '<p class="gacct-ship-field">';
	$html .= '<label for="' . esc_attr( $uid . '-carrier' ) . '">' . esc_html( gacct_ship_text( 'label_carrier' ) ) . '</label>';
	$html .= '<select id="' . esc_attr( $uid . '-carrier' ) . '" name="gacct_ship_carrier" required>';
	$html .= '<option value="">' . esc_html( gacct_ship_text( 'choose' ) ) . '</option>';

	foreach ( $carriers as $slug => $carrier ) {
		$html .= '<option value="' . esc_attr( $slug ) . '"' . selected( $current, $slug, false ) . '>'
			. esc_html( $carrier['label'] ) . '</option>';
	}

	$html .= '</select></p>';

	$html .= '<p class="gacct-ship-field">';
	$html .= '<label for="' . esc_attr( $uid . '-number' ) . '">' . esc_html( gacct_ship_text( 'label_number' ) ) . '</label>';
	$html .= '<input type="text" id="' . esc_attr( $uid . '-number' ) . '" name="gacct_ship_number"'
		. ' value="' . esc_attr( $number ) . '"'
		. ' placeholder="' . esc_attr( gacct_ship_text( 'placeholder' ) ) . '"'
		. ' minlength="4" maxlength="40" pattern="[A-Za-z0-9 \-]{4,40}" required>';
	$html .= '</p>';

	$html .= '<p class="gacct-ship-actions"><button type="submit" class="gacct-ship-btn">'
		. esc_html( gacct_ship_text( $info ? 'update' : 'submit' ) ) . '</button></p>';

	$html .= '</form>';

	return $html;
}
