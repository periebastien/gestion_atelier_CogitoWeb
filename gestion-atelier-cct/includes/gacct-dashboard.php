<?php
/**
 * Tableau de bord client (/mon-compte/, template Elementor 508).
 *
 * Ce module N'AFFICHE PAS la page : il expose la DONNÉE MÉTIER du tableau de
 * bord (`gacct_dash_data()`) et une poignée de callbacks de rendu appelés
 * depuis Elementor / JetEngine (dynamic field, Dynamic Visibility). La mise en
 * page, elle, vit dans le template 508 en widgets natifs (cf. CDC-dashboard-client.md §5).
 *
 * RÈGLE D'OR (CDC §4) : rien n'est recalculé ici. Les montants viennent de
 * l'API du plugin Kojito, les dates et échéances de `gacct_conf_data()` et de
 * gacct-payments.php, les couleurs de `gacct_extraire_couleurs()`, les URL des
 * onglets de `jwcct_get_compte_subpage_url()`. Ce fichier ne fait qu'assembler.
 *
 * White-label : aucun texte en dur non filtrable (`gacct_dash_texts()` →
 * filtre `gacct_dashboard_texts`), aucune couleur en dur (variables CSS
 * `--gacct-*` dans assets/css/dashboard.css), aucune URL en dur
 * (`gacct_dash_links()` → filtre `gacct_dashboard_links`).
 *
 * ⚠ Les noms de fonctions publiques ci-dessous sont référencés dans les
 * listings / dynamic fields Elementor & JetEngine : NE PAS LES RENOMMER.
 *
 * @package gestion-atelier-cct
 * @since 2026-07-28
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seuil par défaut (en jours) de l'alerte « révision périodique » (CDC §2.5).
 * Réglable dans Gestion Atelier > Configuration (option `gacct_next_revision_days`)
 * puis, en dernier ressort, par le filtre `gacct_dashboard_next_revision_days`.
 */
if ( ! defined( 'GACCT_DASH_NEXT_REVISION_DAYS' ) ) {
	define( 'GACCT_DASH_NEXT_REVISION_DAYS', 60 );
}

/**
 * Nom de l'option du seuil d'alerte (écrite par la page Configuration atelier).
 */
if ( ! defined( 'GACCT_DASH_NEXT_REVISION_OPT' ) ) {
	define( 'GACCT_DASH_NEXT_REVISION_OPT', 'gacct_next_revision_days' );
}

/* =============================================================================
 *  TEXTES & LIENS (white-label)
 * ============================================================================= */

/**
 * Tous les textes du tableau de bord, en un seul endroit.
 *
 * Un site revendu peut les remplacer intégralement via le filtre
 * `gacct_dashboard_texts` sans toucher au code ni au template Elementor.
 *
 * @return array<string,string>
 */
function gacct_dash_texts() {
	static $texts = null;

	if ( null !== $texts ) {
		return $texts;
	}

	$defaults = array(

		// --- Carte « virement à effectuer » (CDC §2.2) ---------------------
		'virement_title' => __( 'Virement à effectuer', 'gestion-atelier-cct' ),
		/* translators: 1: montant, 2: référence de commande */
		'virement_text'  => __( 'Nous attendons votre virement de %1$s pour la commande %2$s. Indiquez impérativement la référence %2$s dans le libellé.', 'gestion-atelier-cct' ),
		/* translators: %s: date limite */
		'virement_note'  => __( 'Sans réception avant le %s, le créneau réservé sera libéré et la commande annulée automatiquement.', 'gestion-atelier-cct' ),
		'virement_cta'   => __( 'Voir les coordonnées bancaires', 'gestion-atelier-cct' ),

		// --- Carte « matériel à expédier » ---------------------------------
		'expedition_title'      => __( 'Matériel à expédier', 'gestion-atelier-cct' ),
		/* translators: 1: date limite d'arrivée du colis, 2: date du créneau atelier */
		'expedition_text'       => __( 'Votre colis doit nous parvenir au plus tard le %1$s, pour une prise en charge à l’atelier le %2$s.', 'gestion-atelier-cct' ),
		/* translators: %s: date limite d'arrivée du colis */
		'expedition_text_solo'  => __( 'Votre colis doit nous parvenir au plus tard le %s.', 'gestion-atelier-cct' ),
		'expedition_text_plain' => __( 'Votre matériel est attendu à l’atelier.', 'gestion-atelier-cct' ),
		'expedition_note'       => __( 'Si le colis n’arrive pas à temps, le créneau est libéré et l’acompte reste acquis à l’atelier. Au moindre imprévu, prévenez-nous : nous trouverons une solution ensemble.', 'gestion-atelier-cct' ),
		'expedition_cta'        => __( 'Voir les instructions d’envoi', 'gestion-atelier-cct' ),

		// --- Carte « créneau libéré » (no-show, CDC §2.4) -------------------
		'noshow_title' => __( 'Créneau libéré', 'gestion-atelier-cct' ),
		/* translators: %s: date du créneau libéré */
		'noshow_text'  => __( 'Votre matériel ne nous est pas parvenu pour le créneau du %s : celui-ci a été libéré.', 'gestion-atelier-cct' ),
		'noshow_note'  => __( 'Votre dossier est conservé : contactez-nous pour choisir une nouvelle date.', 'gestion-atelier-cct' ),
		'noshow_cta'   => __( 'Nous contacter pour replanifier', 'gestion-atelier-cct' ),

		// --- Carte « devis à valider » --------------------------------------
		'devis_title' => __( 'Devis à valider', 'gestion-atelier-cct' ),
		/* translators: %s: matériel */
		'devis_text'  => __( 'Des travaux complémentaires sont nécessaires sur %s. Votre accord est indispensable pour poursuivre l’intervention.', 'gestion-atelier-cct' ),
		/* translators: %s: date du devis */
		'devis_note'  => __( 'Devis établi le %s.', 'gestion-atelier-cct' ),
		'devis_cta'   => __( 'Consulter et valider le devis', 'gestion-atelier-cct' ),

		// --- Carte « solde à payer » ----------------------------------------
		'solde_title' => __( 'Solde à régler', 'gestion-atelier-cct' ),
		/* translators: 1: montant du solde, 2: référence de commande */
		'solde_text'  => __( 'L’intervention est terminée : il reste %1$s à régler pour la commande %2$s.', 'gestion-atelier-cct' ),
		'solde_note'  => __( 'Votre matériel repart dès réception du paiement.', 'gestion-atelier-cct' ),
		'solde_cta'   => __( 'Payer le solde', 'gestion-atelier-cct' ),

		// --- Compteurs du bandeau (CDC §2.1) ---------------------------------
		// Le libellé complet — accordé en nombre — est fabriqué par
		// `gacct_dash_counter_label()` : les widgets du template Elementor ne
		// portent plus que `%s`. `%s` reçoit le nombre déjà enveloppé de <b>.
		/* translators: %s: nombre de révisions, enveloppé de <b> */
		'counter_revisions_one'  => __( '%s révision en cours', 'gestion-atelier-cct' ),
		/* translators: %s: nombre de révisions, enveloppé de <b> */
		'counter_revisions_many' => __( '%s révisions en cours', 'gestion-atelier-cct' ),
		/* translators: %s: nombre d'actions attendues, enveloppé de <b> */
		'counter_actions_one'    => __( '%s action attendue', 'gestion-atelier-cct' ),
		/* translators: %s: nombre d'actions attendues, enveloppé de <b> */
		'counter_actions_many'   => __( '%s actions attendues', 'gestion-atelier-cct' ),
		/* translators: %s: nombre de matériels, enveloppé de <b> */
		'counter_materiels_one'  => __( '%s matériel enregistré', 'gestion-atelier-cct' ),
		/* translators: %s: nombre de matériels, enveloppé de <b> */
		'counter_materiels_many' => __( '%s matériels enregistrés', 'gestion-atelier-cct' ),

		// --- Divers ----------------------------------------------------------
		'no_action'    => __( 'Rien à faire de votre côté', 'gestion-atelier-cct' ),
		/* translators: 1: numéro d'étape, 2: nombre total d'étapes */
		'step'         => __( 'étape %1$d sur %2$d', 'gestion-atelier-cct' ),
		'slot_label'    => __( 'Créneau atelier', 'gestion-atelier-cct' ),
		'parcel_label'  => __( 'Colis attendu le', 'gestion-atelier-cct' ),
		'tracking'      => __( 'Suivi colis', 'gestion-atelier-cct' ),
		'released'      => __( 'Créneau libéré', 'gestion-atelier-cct' ),
		'balance_label' => __( 'Solde restant', 'gestion-atelier-cct' ),
		'sn_label'      => __( 'S/N', 'gestion-atelier-cct' ),
		/* translators: %s: taille du matériel */
		'size_label'    => __( 'taille %s', 'gestion-atelier-cct' ),
		/* translators: 1: matériel, 2: date */
		'document'      => __( 'Rapport — %1$s — %2$s', 'gestion-atelier-cct' ),
		'report'        => __( 'Rapport de révision', 'gestion-atelier-cct' ),
		/* translators: %s: matériel */
		'doc_name'      => __( 'Rapport de révision · %s', 'gestion-atelier-cct' ),
		'doc_format'    => __( 'PDF', 'gestion-atelier-cct' ),
		'doc_download'  => __( 'Télécharger le rapport', 'gestion-atelier-cct' ),

		// --- Alerte révision périodique (CDC §2.5) ---------------------------
		/* translators: %s: matériel */
		'alerte_title'   => __( 'Votre %s arrive en fin de période de révision', 'gestion-atelier-cct' ),
		/* translators: %s: date */
		'alerte_due'     => __( 'Prochaine révision conseillée le %s.', 'gestion-atelier-cct' ),
		/* translators: %s: date */
		'alerte_overdue' => __( 'La révision était conseillée le %s.', 'gestion-atelier-cct' ),
		'alerte_cta'     => __( 'Planifier la révision', 'gestion-atelier-cct' ),

		// Carte d'alerte rendue par `gacct_dash_render_alerte_revision()`.
		'alerte_card_title'   => __( 'Révision périodique à prévoir', 'gestion-atelier-cct' ),
		/* translators: 1: matériel, 2: date d'échéance */
		'alerte_card_text'    => __( 'Votre %1$s arrive en fin de période de révision (échéance : %2$s). Réservez votre créneau dès maintenant pour voler l’esprit tranquille.', 'gestion-atelier-cct' ),
		/* translators: 1: matériel, 2: date d'échéance */
		'alerte_card_overdue' => __( 'Votre %1$s a dépassé sa période de révision (échéance : %2$s). Réservez un créneau dès maintenant pour voler l’esprit tranquille.', 'gestion-atelier-cct' ),

		// --- Libellés des 8 états (identiques au tracker du template 521) -----
		'state_0' => __( 'En attente de paiement', 'gestion-atelier-cct' ),
		'state_1' => __( 'En attente de réception', 'gestion-atelier-cct' ),
		'state_2' => __( 'Voile réceptionnée', 'gestion-atelier-cct' ),
		'state_3' => __( 'Nouveau devis à valider', 'gestion-atelier-cct' ),
		'state_4' => __( 'Devis validé', 'gestion-atelier-cct' ),
		'state_5' => __( 'Paiement final en attente', 'gestion-atelier-cct' ),
		'state_6' => __( 'Paiement validé', 'gestion-atelier-cct' ),
		'state_7' => __( 'Révision terminée', 'gestion-atelier-cct' ),
	);

	$texts = (array) apply_filters( 'gacct_dashboard_texts', $defaults );

	return $texts;
}

/**
 * Un texte du tableau de bord, avec repli sur la chaîne vide.
 *
 * @param string $key Clé de `gacct_dash_texts()`.
 * @return string
 */
function gacct_dash_text( $key ) {
	$texts = gacct_dash_texts();

	return isset( $texts[ $key ] ) ? (string) $texts[ $key ] : '';
}

/**
 * Registre des liens du tableau de bord.
 *
 * Les onglets de l'espace client sont construits depuis la configuration du
 * Profile Builder JetEngine (`jwcct_get_compte_subpage_url()`) : aucune URL en dur.
 *
 * @param int $user_id Utilisateur (non utilisé aujourd'hui, transmis au filtre).
 * @return array<string,string>
 */
function gacct_dash_links( $user_id = 0 ) {
	$links = array(
		'demande'      => home_url( '/demande-intervention/' ),
		'mes_demandes' => function_exists( 'jwcct_get_compte_subpage_url' ) ? jwcct_get_compte_subpage_url( 'mes-demandes-interventions' ) : home_url( '/mon-compte/' ),
		'mon_materiel' => function_exists( 'jwcct_get_compte_subpage_url' ) ? jwcct_get_compte_subpage_url( 'mon-materiel' ) : home_url( '/mon-compte/' ),
		'commandes'    => function_exists( 'jwcct_get_compte_subpage_url' ) ? jwcct_get_compte_subpage_url( 'commandes' ) : home_url( '/mon-compte/' ),
	);

	return (array) apply_filters( 'gacct_dashboard_links', $links, (int) $user_id );
}

/**
 * Seuil (en jours) de l'alerte « révision périodique ».
 *
 * Source : réglage de la page Gestion Atelier > Configuration
 * (option `gacct_next_revision_days`), défaut 60 jours, filtrable.
 *
 * @return int
 */
function gacct_dash_next_revision_threshold() {
	$days = (int) get_option( GACCT_DASH_NEXT_REVISION_OPT, GACCT_DASH_NEXT_REVISION_DAYS );

	if ( $days < 1 ) {
		$days = GACCT_DASH_NEXT_REVISION_DAYS;
	}

	return max( 1, (int) apply_filters( 'gacct_dashboard_next_revision_days', $days ) );
}

/* =============================================================================
 *  AVATAR (CDC §2.1.1)
 * ============================================================================= */

/**
 * URL de la VRAIE photo de profil du client, ou null.
 *
 * ⚠ `get_avatar_url()` ne renvoie jamais vide : sans photo, il retombe sur la
 * silhouette grise « mystery person » de Gravatar. Le seul indice fiable et
 * local d'une vraie photo est la user meta `{préfixe}_user_avatar`, posée par
 * Nextend Social Login quand il rapatrie la photo Google en médiathèque.
 *
 * @param int $user_id Utilisateur (0 = utilisateur courant).
 * @param int $size    Taille demandée, en pixels.
 * @return string|null URL absolue, ou null s'il n'y a pas de vraie photo.
 */
function gacct_dash_avatar_url( $user_id = 0, $size = 96 ) {
	global $wpdb;

	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$url     = null;

	if ( $user_id ) {
		$attachment_id = (int) get_user_meta( $user_id, $wpdb->get_blog_prefix() . 'user_avatar', true );

		if ( $attachment_id && function_exists( 'get_avatar_url' ) ) {
			$resolved = get_avatar_url( $user_id, array( 'size' => absint( $size ) ) );

			if ( ! $resolved ) {
				$resolved = wp_get_attachment_url( $attachment_id );
			}

			$url = $resolved ? (string) $resolved : null;
		}
	}

	$url = apply_filters( 'gacct_dashboard_avatar_url', $url, $user_id, $size );

	return $url ? (string) $url : null;
}

/**
 * Initiales d'un utilisateur (prénom + nom, sinon display_name).
 *
 * @param int $user_id Utilisateur (0 = utilisateur courant).
 * @return string 1 à 2 caractères, en majuscules.
 */
function gacct_dash_initials( $user_id = 0 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$user    = $user_id ? get_userdata( $user_id ) : false;

	if ( ! $user ) {
		return '';
	}

	$prenom = (string) $user->first_name;
	$nom    = (string) $user->last_name;

	if ( '' !== $prenom && '' !== $nom ) {
		$initiales = mb_substr( $prenom, 0, 1 ) . mb_substr( $nom, 0, 1 );
	} else {
		$display = (string) $user->display_name;
		$mots    = preg_split( '/\s+/', trim( $display ) );

		if ( is_array( $mots ) && count( $mots ) >= 2 ) {
			$initiales = mb_substr( $mots[0], 0, 1 ) . mb_substr( (string) end( $mots ), 0, 1 );
		} else {
			$initiales = mb_substr( $display, 0, 2 );
		}
	}

	return mb_strtoupper( $initiales );
}

/* =============================================================================
 *  DONNÉES : POINT D'ENTRÉE UNIQUE
 * ============================================================================= */

/**
 * Toute la donnée métier du tableau de bord client, pour un utilisateur.
 *
 * Mémoïsé par requête (une seule construction même si dix widgets Elementor
 * l'appellent). Contrat de sortie : cf. l'en-tête de fichier et le CDC §2.
 *
 * @param int $user_id Utilisateur (0 = utilisateur courant).
 * @return array<string,mixed>
 */
function gacct_dash_data( $user_id = 0 ) {
	static $cache = array();

	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}

	$texts = gacct_dash_texts();
	$user  = $user_id ? get_userdata( $user_id ) : false;

	$data = array(
		'is_new'          => true,
		'has_actions'     => false,
		'user'            => array(
			'id'           => $user_id,
			'first_name'   => $user ? (string) $user->first_name : '',
			'display_name' => $user ? (string) $user->display_name : '',
			'email'        => $user ? (string) $user->user_email : '',
			'avatar_url'   => gacct_dash_avatar_url( $user_id ),
			'initials'     => gacct_dash_initials( $user_id ),
		),
		'counters'        => array(
			'revisions' => 0,
			'actions'   => 0,
			'materiels' => 0,
		),
		'actions'         => array(),
		'revisions'       => array(),
		'materiels'       => array(),
		'alerte_revision' => null,
		'documents'       => array(),
		'links'           => gacct_dash_links( $user_id ),
	);

	if ( ! $user_id || ! $user ) {
		$cache[ $user_id ] = apply_filters( 'gacct_dashboard_data', $data, $user_id );

		return $cache[ $user_id ];
	}

	// --- Dossiers du client (lecture SQL directe : le cache objet JetEngine
	//     peut resservir un `etat_de_la_commande` périmé dans la même requête).
	$rows = gacct_dash_revision_rows( $user_id );

	// --- Matériel : MÊME logique de regroupement que la query 22 / l'onglet
	//     « Mon Matériel » (un seul décompte, pas deux logiques).
	$materiels = function_exists( 'gacct_demande_materiels_client' )
		? gacct_demande_materiels_client( $user_id )
		: array();

	$data['counters']['materiels'] = count( $materiels );
	$data['materiels']             = gacct_dash_format_materiels( $materiels );

	$has_orders = gacct_dash_user_has_orders( $user_id );
	$data['is_new'] = ( empty( $rows ) && ! $has_orders );

	$revisions_limit = (int) apply_filters( 'gacct_dashboard_revisions_limit', 4 );
	$now             = time();

	$actions   = array();
	$revisions = array();
	$documents = array();
	$alerte    = null;

	foreach ( $rows as $row ) {
		$revision_id = (int) $row['_ID'];
		$order_id    = (int) $row['order_id'];
		$etat        = ( '' === (string) $row['etat_de_la_commande'] ) ? 0 : (int) $row['etat_de_la_commande'];

		$order = ( $order_id && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : false;
		$order = $order instanceof WC_Order ? $order : false;

		// Données déjà calculées ailleurs (dates, échéances, virement, montants).
		$conf = ( $order && function_exists( 'gacct_conf_data' ) ) ? gacct_conf_data( $order ) : array();

		$materiel_label = gacct_dash_materiel_label( $row );
		$couleurs       = function_exists( 'gacct_extraire_couleurs' ) ? gacct_extraire_couleurs( (string) $row['couleur'] ) : array();
		$gradient       = function_exists( 'gacct_degrade_couleurs' ) ? gacct_degrade_couleurs( $couleurs ) : '';

		// --- Compteur « révisions en cours » (états 1 à 6) ------------------
		if ( $etat >= 1 && $etat <= 6 ) {
			$data['counters']['revisions']++;
		}

		// --- Documents (état 7 + rapport PDF) -------------------------------
		if ( 7 === $etat && ! empty( $row['rapport_pdf'] ) ) {
			$pdf_url = wp_get_attachment_url( (int) $row['rapport_pdf'] );

			if ( $pdf_url ) {
				$date_ts     = ! empty( $row['cct_modified'] ) ? (int) strtotime( (string) $row['cct_modified'] ) : 0;
				$documents[] = array(
					'label'      => sprintf(
						$texts['document'],
						$materiel_label ? $materiel_label : $texts['report'],
						$date_ts ? wp_date( get_option( 'date_format' ), $date_ts ) : ''
					),
					'date_texte' => $date_ts ? wp_date( get_option( 'date_format' ), $date_ts ) : '',
					'url'        => $pdf_url,
				);
			}
		}

		// --- Alerte révision périodique (CDC §2.5) ---------------------------
		$prochaine_ts = (int) $row['date_de_prochaine_revision'];

		if ( $prochaine_ts > 0 ) {
			$seuil = gacct_dash_next_revision_threshold() * DAY_IN_SECONDS;

			if ( $prochaine_ts - $now <= $seuil && ( null === $alerte || $prochaine_ts < $alerte['date_ts'] ) ) {
				$alerte = array(
					'materiel'   => $materiel_label,
					'date_texte' => wp_date( get_option( 'date_format' ), $prochaine_ts ),
					'date_ts'    => $prochaine_ts,
					'url'        => add_query_arg( 'remat', $revision_id, $data['links']['demande'] ),
				);
			}
		}

		// --- Cartes d'action (CDC §2.2) --------------------------------------
		// Une commande annulée / remboursée ne génère AUCUNE carte (même esprit
		// que la liste blanche `jwcct_email_ids_with_revision_block()`).
		if ( $order && ! gacct_dash_order_is_dead( $order ) ) {
			$action = gacct_dash_build_action( $etat, $row, $order, $conf, $materiel_label );

			if ( $action ) {
				$actions[] = $action;
			}
		}

		// --- Ligne « mes révisions en cours » (états < 7) ---------------------
		if ( $etat < 7 ) {
			$revisions[] = array(
				'revision_id'    => $revision_id,
				'order_id'       => $order_id,
				'etat'           => $etat,
				'marque_libelle' => function_exists( 'jwcct_render_marque_libelle' ) ? jwcct_render_marque_libelle( (string) $row['marque'] ) : ucfirst( (string) $row['marque'] ),
				'modele'         => (string) $row['modele'],
				'taille'         => (string) $row['taille'],
				'couleurs'       => $couleurs,
				'gradient'       => $gradient,
				'tracker'        => gacct_dash_tracker( $etat ),
				'extra'          => gacct_dash_revision_extra( $row, $order, $conf ),
				'url'            => gacct_dash_revision_url( $revision_id, $order_id ),
			);
		}
	}

	// Les dossiers les plus urgents d'abord (échéance la plus proche).
	usort(
		$actions,
		function ( $a, $b ) {
			if ( $a['sort_ts'] === $b['sort_ts'] ) {
				return 0;
			}

			return ( $a['sort_ts'] < $b['sort_ts'] ) ? -1 : 1;
		}
	);

	$data['actions']         = $actions;
	$data['has_actions']     = ! empty( $actions );
	$data['counters']['actions'] = count( $actions );
	$data['revisions']       = $revisions_limit > 0 ? array_slice( $revisions, 0, $revisions_limit ) : $revisions;
	$data['documents']       = $documents;
	$data['alerte_revision'] = $alerte;

	$cache[ $user_id ] = apply_filters( 'gacct_dashboard_data', $data, $user_id );

	return $cache[ $user_id ];
}

/* =============================================================================
 *  BRIQUES DE CONSTRUCTION
 * ============================================================================= */

/**
 * Lignes CCT `revision` du client, en SQL direct.
 *
 * Rattachement : relation JetEngine 13 (client ↔ révision), avec repli sur les
 * colonnes `client_id` / `cct_author_id` (dossiers créés hors formulaire, ou
 * relation perdue). Les brouillons (commande jamais finalisée) sont exclus.
 *
 * @param int $user_id Utilisateur.
 * @return array<int,array<string,mixed>> Plus récent en premier.
 */
function gacct_dash_revision_rows( $user_id ) {
	global $wpdb;

	$user_id = absint( $user_id );

	if ( ! $user_id || ! function_exists( 'gacct_demande_table_name' ) ) {
		return array();
	}

	$table = gacct_demande_table_name( JWCCT_CCT_REVISION );

	if ( ! gacct_demande_table_exists( $table ) ) {
		return array();
	}

	$rel_table = $wpdb->prefix . 'jet_rel_default';
	$rel_id    = class_exists( 'GACCT_Plugin' ) ? (int) GACCT_Plugin::REL_CLIENT_REV : 13;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->prepare(
			"
			SELECT r.*
			FROM {$table} r
			WHERE r.cct_status = %s
				AND (
					r._ID IN ( SELECT rel.child_object_id FROM {$rel_table} rel WHERE rel.rel_id = %s AND rel.parent_object_id = %d )
					OR r.client_id = %d
					OR r.cct_author_id = %d
				)
			ORDER BY r.cct_created DESC
			",
			'publish',
			(string) $rel_id,
			$user_id,
			$user_id,
			$user_id
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return array();
	}

	// Colonnes attendues par les consommateurs, même si le CCT évolue.
	$defaults = array(
		'_ID'                        => 0,
		'order_id'                   => 0,
		'etat_de_la_commande'        => '',
		'marque'                     => '',
		'modele'                     => '',
		'taille'                     => '',
		'couleur'                    => '',
		'numero_de_serie'            => '',
		'suivi_transporteur'         => '',
		'rapport_pdf'                => 0,
		'date_de_prochaine_revision' => 0,
		'cct_created'                => '',
		'cct_modified'               => '',
	);

	foreach ( $rows as $index => $row ) {
		$rows[ $index ] = array_merge( $defaults, (array) $row );
	}

	return $rows;
}

/**
 * Le client a-t-il au moins une commande WooCommerce ? (état « première visite »)
 *
 * @param int $user_id Utilisateur.
 * @return bool
 */
function gacct_dash_user_has_orders( $user_id ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return false;
	}

	$orders = wc_get_orders(
		array(
			'customer_id' => absint( $user_id ),
			'limit'       => 1,
			'return'      => 'ids',
		)
	);

	return ! empty( $orders );
}

/**
 * Une commande morte (annulée / remboursée / corbeille) ne génère aucune carte.
 *
 * @param WC_Order $order Commande.
 * @return bool
 */
function gacct_dash_order_is_dead( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return true;
	}

	$statuses = (array) apply_filters(
		'gacct_dashboard_dead_order_statuses',
		array( 'cancelled', 'refunded', 'trash' ),
		$order
	);

	return $order->has_status( $statuses );
}

/**
 * Libellé du matériel d'une ligne CCT revision (« Ozone · Alpina 5 · MS »).
 *
 * @param array<string,mixed> $row Ligne CCT.
 * @return string
 */
function gacct_dash_materiel_label( array $row ) {
	$marque = trim( (string) ( $row['marque'] ?? '' ) );

	if ( '' !== $marque && function_exists( 'jwcct_render_marque_libelle' ) ) {
		$marque = jwcct_render_marque_libelle( $marque );
	}

	$parts = array_filter(
		array(
			$marque,
			trim( (string) ( $row['modele'] ?? '' ) ),
			strtoupper( trim( (string) ( $row['taille'] ?? '' ) ) ),
		)
	);

	return implode( ' · ', $parts );
}

/**
 * Mini-tracker compact d'un état (version condensée du tracker 8 états du
 * template 521 : une barre + le libellé de l'état courant).
 *
 * @param int $etat État 0–7.
 * @return array{pct:int,label:string,step:string,is_action:bool}
 */
function gacct_dash_tracker( $etat ) {
	$etat  = max( 0, min( 7, (int) $etat ) );
	$total = 8;

	// Progression = étapes franchies / 8, comme la maquette (état 1 → 25 %,
	// état 3 → 50 %, état 5 → 75 %, état 7 → 100 %).
	$tracker = array(
		'pct'       => (int) round( ( $etat + 1 ) / $total * 100 ),
		'label'     => gacct_dash_text( 'state_' . $etat ),
		'step'      => sprintf( gacct_dash_text( 'step' ), $etat + 1, $total ),
		'is_action' => in_array( $etat, array( 0, 3, 5 ), true ),
	);

	return (array) apply_filters( 'gacct_dashboard_tracker', $tracker, $etat );
}

/**
 * Information secondaire d'une ligne de révision : suivi colis, créneau atelier,
 * ou mention « créneau libéré » (no-show, CDC §2.4).
 *
 * @param array<string,mixed> $row   Ligne CCT revision.
 * @param WC_Order|false      $order Commande liée.
 * @param array<string,mixed> $conf  Sortie de `gacct_conf_data()`.
 * @return array{label:string,value:string,url:string|null}
 */
function gacct_dash_revision_extra( array $row, $order, array $conf ) {
	$extra = array(
		'label' => '',
		'value' => '',
		'url'   => null,
	);

	// 1. Créneau libéré pour no-show : l'information prime sur tout le reste.
	if ( $order instanceof WC_Order && $order->get_meta( '_gacct_noshow_released' ) ) {
		$slot_ts = (int) $order->get_meta( '_gacct_noshow_slot_ts' );

		$extra['label'] = gacct_dash_text( 'released' );
		$extra['value'] = $slot_ts ? wp_date( get_option( 'date_format' ), $slot_ts ) : '';

		return $extra;
	}

	// 2. Solde à régler (état 5) : le montant prime sur tout le reste.
	$etat = ( '' === (string) ( $row['etat_de_la_commande'] ?? '' ) ) ? 0 : (int) $row['etat_de_la_commande'];

	if ( 5 === $etat && $order instanceof WC_Order ) {
		$meta_key = class_exists( 'GACCT_Plugin' ) ? GACCT_Plugin::KOJITO_META_SOLDE_RESTANT : '_kojito_solde_restant';
		$solde    = $order->get_meta( $meta_key );

		if ( '' === (string) $solde ) {
			$solde = isset( $conf['balance'] ) ? (float) $conf['balance'] : 0.0;
		}

		$extra['label'] = gacct_dash_text( 'balance_label' );
		$extra['value'] = gacct_dash_amount( (float) $solde );
		$extra['url']   = $order->get_checkout_payment_url();

		return $extra;
	}

	// 3. Colis en route : le champ `suivi_transporteur` contient tantôt une URL
	//    de suivi, tantôt un simple numéro de colis. On ne fabrique un lien que
	//    dans le premier cas (sinon on obtiendrait un « http://6A212345 » mort).
	$suivi = trim( (string) ( $row['suivi_transporteur'] ?? '' ) );

	if ( '' !== $suivi ) {
		$extra['label'] = gacct_dash_text( 'tracking' );
		$extra['value'] = $suivi;

		if ( preg_match( '#^https?://#i', $suivi ) ) {
			$extra['url'] = esc_url_raw( $suivi );
		}

		return $extra;
	}

	// 4. Créneau atelier (date déjà calculée par `gacct_conf_data()`).
	if ( ! empty( $conf['slot_label'] ) ) {
		$extra['label'] = gacct_dash_text( 'slot_label' );
		$extra['value'] = (string) $conf['slot_label'];
	}

	return $extra;
}

/**
 * URL de la fiche d'une révision (sous-page « Détail de la commande »).
 *
 * @param int $revision_id Révision.
 * @param int $order_id    Commande liée (0 si aucune).
 * @return string
 */
function gacct_dash_revision_url( $revision_id, $order_id = 0 ) {
	$base = function_exists( 'jwcct_get_compte_subpage_url' )
		? jwcct_get_compte_subpage_url( 'detail-commande' )
		: home_url( '/mon-compte/detail-commande' );

	$args = array( 'revision_id' => absint( $revision_id ) );

	if ( absint( $order_id ) ) {
		$args['order_id'] = absint( $order_id );
	}

	return add_query_arg( $args, $base );
}

/**
 * Vignettes « Mon matériel » du tableau de bord (3 dernières voiles).
 *
 * @param array<int,array<string,mixed>> $materiels Sortie de `gacct_demande_materiels_client()`.
 * @return array<int,array<string,mixed>>
 */
function gacct_dash_format_materiels( array $materiels ) {
	$limit  = (int) apply_filters( 'gacct_dashboard_materiels_limit', 3 );
	$sortie = array();
	$demande_url = home_url( '/demande-intervention/' );

	foreach ( array_slice( $materiels, 0, max( 0, $limit ) ) as $materiel ) {
		$couleurs = function_exists( 'gacct_extraire_couleurs' ) ? gacct_extraire_couleurs( (string) $materiel['couleur'] ) : array();

		$nom = gacct_dash_materiel_label(
			array(
				'marque' => (string) $materiel['marque'],
				'modele' => (string) $materiel['modele'],
				'taille' => '',
			)
		);

		$sub = array_filter(
			array(
				strtoupper( trim( (string) $materiel['taille'] ) ),
				'' !== trim( (string) $materiel['numero_serie'] )
					? gacct_dash_text( 'sn_label' ) . ' : ' . trim( (string) $materiel['numero_serie'] )
					: '',
			)
		);

		$sortie[] = array(
			'nom'      => $nom,
			'sub'      => implode( ' · ', $sub ),
			'gradient' => function_exists( 'gacct_degrade_couleurs' ) ? gacct_degrade_couleurs( $couleurs ) : '',
			'remat_id' => (int) $materiel['revision_id'],
			'url'      => add_query_arg( 'remat', (int) $materiel['revision_id'], $demande_url ),
		);
	}

	return $sortie;
}

/* =============================================================================
 *  CARTES D'ACTION (CDC §2.2)
 * ============================================================================= */

/**
 * Une carte d'action doit-elle être surlignée « urgent » (fond orangé) ?
 *
 * ⚠ Sémantique de la maquette validée : `urgent` = ÉCHÉANCE PROCHE, autrement
 * dit la carte qui porte une pastille de compte à rebours (« 3 jours
 * restants »). Les cartes « devis à valider » et « solde à régler » bloquent
 * l'avancement du dossier mais n'ont aucune date couperet : elles restent
 * blanches, sans quoi l'écran surligne en permanence des cartes sans délai et
 * laisse en blanc celles qui en ont un — exactement l'inverse de l'intention.
 *
 * Un site revendu peut rebrancher la règle sans toucher au code via le filtre
 * `gacct_dashboard_action_urgent`.
 *
 * @param string              $type    Type de carte : virement|expedition|devis|solde.
 * @param bool                $default Valeur calculée par la carte elle-même.
 * @param array<string,mixed> $context Éléments de décision (days, deadline_ts…).
 * @return bool
 */
function gacct_dash_action_urgent( $type, $default, array $context = array() ) {
	return (bool) apply_filters( 'gacct_dashboard_action_urgent', (bool) $default, (string) $type, $context );
}

/**
 * Construit, s'il y a lieu, LA carte d'action d'un dossier.
 *
 * Un dossier ne produit jamais deux cartes : son état détermine ce qu'on attend
 * du client. L'ordre des tests suit celui du workflow.
 *
 * @param int                 $etat           État 0–7 de la révision.
 * @param array<string,mixed> $row            Ligne CCT revision.
 * @param WC_Order            $order          Commande liée.
 * @param array<string,mixed> $conf           Sortie de `gacct_conf_data()`.
 * @param string              $materiel_label Libellé du matériel.
 * @return array<string,mixed>|null
 */
function gacct_dash_build_action( $etat, array $row, $order, array $conf, $materiel_label ) {
	$etat = (int) $etat;

	// --- Devis à valider (état 3) ------------------------------------------
	if ( 3 === $etat ) {
		return gacct_dash_action_devis( $row, $order, $conf, $materiel_label );
	}

	// --- Solde à payer (état 5) --------------------------------------------
	if ( 5 === $etat ) {
		return gacct_dash_action_solde( $row, $order, $conf );
	}

	// --- Virement à effectuer (état 0 + commande bacs en attente) ----------
	if ( 0 === $etat && function_exists( 'gacct_pay_order_awaits_transfer' ) && gacct_pay_order_awaits_transfer( $order ) ) {
		return gacct_dash_action_virement( $order, $conf );
	}

	// --- Matériel à expédier (état <= 1, colis pas encore réceptionné) -----
	if ( $etat <= 1 && '' === (string) $order->get_meta( '_gacct_reception_date' ) ) {
		return gacct_dash_action_expedition( $order, $conf );
	}

	return null;
}

/**
 * Carte « virement à effectuer ». Montants, échéance et jours restants viennent
 * tous de `gacct_conf_data()` / gacct-payments.php : rien n'est recalculé ici.
 *
 * @param WC_Order            $order Commande bacs en attente.
 * @param array<string,mixed> $conf  Sortie de `gacct_conf_data()`.
 * @return array<string,mixed>
 */
function gacct_dash_action_virement( $order, array $conf ) {
	$deadlines = function_exists( 'gacct_pay_order_deadlines' ) ? gacct_pay_order_deadlines( $order ) : array( 'cancel' => time() );
	$days      = isset( $conf['days_remaining'] ) ? (int) $conf['days_remaining'] : 0;
	$montant   = isset( $conf['deposit'] ) ? (float) $conf['deposit'] : (float) $order->get_total();

	return array(
		'type'      => 'virement',
		'title'     => gacct_dash_text( 'virement_title' ),
		'text_html' => sprintf(
			gacct_dash_text( 'virement_text' ),
			'<span class="hl">' . esc_html( gacct_dash_amount( $montant ) ) . '</span>',
			'<span class="hl">' . esc_html( $order->get_order_number() ) . '</span>'
		),
		'note'      => sprintf( gacct_dash_text( 'virement_note' ), isset( $conf['deadline_label'] ) ? $conf['deadline_label'] : '' ),
		'chip'      => gacct_dash_chip_days( $days ),
		'url'       => $order->get_checkout_order_received_url(),
		'cta_label' => gacct_dash_text( 'virement_cta' ),
		'cta_style' => 'primary',
		'icon'      => 'card',
		// Seuil aligné sur la maquette validée : la carte passe en orange dès
		// qu'il reste 2 jours ou moins (« plus que 2 jours »), pas seulement la
		// veille de l'annulation automatique.
		'urgent'    => gacct_dash_action_urgent( 'virement', ( $days <= 2 ), array( 'days' => $days, 'deadline_ts' => (int) $deadlines['cancel'] ) ),
		'sort_ts'   => (int) $deadlines['cancel'],
	);
}

/**
 * Carte « matériel à expédier », ou, si le créneau a déjà été libéré faute de
 * colis (meta `_gacct_noshow_released`), sa variante « créneau libéré ».
 *
 * ⚠ Choix documenté : le no-show ne crée PAS un cinquième type de carte (le
 * contrat d'API n'en connaît que quatre). Il reste de type `expedition`, avec
 * un titre, un texte, une note et un CTA adaptés — et le CTA ne mène plus aux
 * instructions d'envoi mais à la page de contact (replanification manuelle,
 * CDC §2.4). La même information est aussi exposée dans `revisions[].extra`.
 *
 * @param WC_Order            $order Commande.
 * @param array<string,mixed> $conf  Sortie de `gacct_conf_data()`.
 * @return array<string,mixed>
 */
function gacct_dash_action_expedition( $order, array $conf ) {
	$links = isset( $conf['links'] ) && is_array( $conf['links'] ) ? $conf['links'] : array();

	// --- Variante no-show : le créneau a été libéré, le colis n'est jamais arrivé.
	if ( $order->get_meta( '_gacct_noshow_released' ) ) {
		$slot_ts = (int) $order->get_meta( '_gacct_noshow_slot_ts' );

		return array(
			'type'      => 'expedition',
			'title'     => gacct_dash_text( 'noshow_title' ),
			'text_html' => sprintf(
				gacct_dash_text( 'noshow_text' ),
				'<span class="hl">' . esc_html( $slot_ts ? wp_date( get_option( 'date_format' ), $slot_ts ) : '' ) . '</span>'
			),
			'note'      => gacct_dash_text( 'noshow_note' ),
			'chip'      => '',
			'url'       => isset( $links['contact'] ) ? $links['contact'] : home_url( '/contact/' ),
			'cta_label' => gacct_dash_text( 'noshow_cta' ),
			'cta_style' => 'ghost',
			'icon'      => 'truck',
			'urgent'    => gacct_dash_action_urgent( 'expedition', false, array( 'noshow' => true, 'slot_ts' => $slot_ts ) ),
			'sort_ts'   => $slot_ts ? $slot_ts : time(),
		);
	}

	$slot_ts   = isset( $conf['slot_ts'] ) ? (int) $conf['slot_ts'] : 0;
	$parcel_ts = $slot_ts ? $slot_ts - (int) apply_filters( 'gacct_conf_parcel_lead_days', 1 ) * DAY_IN_SECONDS : 0;
	$days      = $parcel_ts ? (int) ceil( ( $parcel_ts - time() ) / DAY_IN_SECONDS ) : 0;

	if ( ! empty( $conf['parcel_label'] ) && ! empty( $conf['slot_label'] ) ) {
		$text = sprintf(
			gacct_dash_text( 'expedition_text' ),
			'<span class="hl">' . esc_html( (string) $conf['parcel_label'] ) . '</span>',
			esc_html( (string) $conf['slot_label'] )
		);
	} elseif ( ! empty( $conf['parcel_label'] ) ) {
		$text = sprintf(
			gacct_dash_text( 'expedition_text_solo' ),
			'<span class="hl">' . esc_html( (string) $conf['parcel_label'] ) . '</span>'
		);
	} else {
		$text = gacct_dash_text( 'expedition_text_plain' );
	}

	return array(
		'type'      => 'expedition',
		'title'     => gacct_dash_text( 'expedition_title' ),
		'text_html' => $text,
		'note'      => gacct_dash_text( 'expedition_note' ),
		'chip'      => $parcel_ts ? gacct_dash_chip_days( max( 0, $days ) ) : '',
		'url'       => $order->get_checkout_order_received_url(),
		'cta_label' => gacct_dash_text( 'expedition_cta' ),
		'cta_style' => 'primary',
		'icon'      => 'truck',
		'urgent'    => gacct_dash_action_urgent( 'expedition', ( $parcel_ts && $days <= 2 ), array( 'days' => $days, 'parcel_ts' => $parcel_ts, 'slot_ts' => $slot_ts ) ),
		'sort_ts'   => $parcel_ts ? $parcel_ts : time(),
	);
}

/**
 * Carte « devis à valider » (état 3).
 *
 * @param array<string,mixed> $row            Ligne CCT revision.
 * @param WC_Order            $order          Commande.
 * @param array<string,mixed> $conf           Sortie de `gacct_conf_data()`.
 * @param string              $materiel_label Libellé du matériel.
 * @return array<string,mixed>
 */
function gacct_dash_action_devis( array $row, $order, array $conf, $materiel_label ) {
	$devis_ts = ! empty( $row['cct_modified'] ) ? (int) strtotime( (string) $row['cct_modified'] ) : time();

	return array(
		'type'      => 'devis',
		'title'     => gacct_dash_text( 'devis_title' ),
		'text_html' => sprintf(
			gacct_dash_text( 'devis_text' ),
			'<span class="hl">' . esc_html( $materiel_label ) . '</span>'
		),
		'note'      => sprintf( gacct_dash_text( 'devis_note' ), wp_date( get_option( 'date_format' ), $devis_ts ) ),
		'chip'      => '',
		'url'       => gacct_dash_devis_url( $order ),
		'cta_label' => gacct_dash_text( 'devis_cta' ),
		'cta_style' => 'primary',
		'icon'      => 'file',
		// Pas de date couperet sur un devis : bloquant, mais pas « urgent »
		// au sens de la maquette (cf. gacct_dash_action_urgent()).
		'urgent'    => gacct_dash_action_urgent( 'devis', false, array( 'devis_ts' => $devis_ts ) ),
		'sort_ts'   => $devis_ts,
	);
}

/**
 * Carte « solde à payer » (état 5).
 *
 * Le montant vient de la meta `_kojito_solde_restant` posée par le plugin
 * d'acompte (source de vérité), avec repli sur le solde déjà calculé par
 * `gacct_conf_data()` — jamais de calcul local.
 *
 * @param array<string,mixed> $row   Ligne CCT revision.
 * @param WC_Order            $order Commande.
 * @param array<string,mixed> $conf  Sortie de `gacct_conf_data()`.
 * @return array<string,mixed>
 */
function gacct_dash_action_solde( array $row, $order, array $conf ) {
	$meta_key = class_exists( 'GACCT_Plugin' ) ? GACCT_Plugin::KOJITO_META_SOLDE_RESTANT : '_kojito_solde_restant';
	$solde    = $order->get_meta( $meta_key );

	if ( '' === (string) $solde ) {
		$solde = isset( $conf['balance'] ) ? (float) $conf['balance'] : 0.0;
	}

	$since_ts = ! empty( $row['cct_modified'] ) ? (int) strtotime( (string) $row['cct_modified'] ) : time();

	return array(
		'type'      => 'solde',
		'title'     => gacct_dash_text( 'solde_title' ),
		'text_html' => sprintf(
			gacct_dash_text( 'solde_text' ),
			'<span class="hl">' . esc_html( gacct_dash_amount( (float) $solde ) ) . '</span>',
			'<span class="hl">' . esc_html( $order->get_order_number() ) . '</span>'
		),
		'note'      => gacct_dash_text( 'solde_note' ),
		'chip'      => '',
		'url'       => $order->get_checkout_payment_url(),
		'cta_label' => gacct_dash_text( 'solde_cta' ),
		'cta_style' => 'primary',
		'icon'      => 'check',
		// Idem devis : le solde bloque la restitution, mais sans échéance datée.
		'urgent'    => gacct_dash_action_urgent( 'solde', false, array( 'balance' => (float) $solde, 'since_ts' => $since_ts ) ),
		'sort_ts'   => $since_ts,
	);
}

/**
 * URL de la page de validation de devis.
 *
 * ⚠ Le lien sécurisé à usage unique est généré par gestion-atelier-cct.php au
 * passage en état 3 et envoyé par e-mail : SEUL le HMAC du jeton est conservé
 * en meta de commande, le jeton en clair n'est stocké nulle part. Il est donc
 * impossible de le reconstruire ici — et le régénérer invaliderait le lien déjà
 * reçu par le client. Le tableau de bord renvoie donc vers la page de validation
 * avec le seul `order_id` (le handler `maybe_handle_quote_validation()` exige
 * order_id ET token : sans token il ne se déclenche pas, la page s'affiche
 * normalement). Filtre `gacct_dashboard_devis_url` pour brancher autre chose.
 *
 * @param WC_Order $order Commande.
 * @return string
 */
function gacct_dash_devis_url( $order ) {
	$path = trim( (string) apply_filters( 'gacct_validation_path', 'devis-a-valider' ), '/' );
	$url  = add_query_arg( array( 'order_id' => $order->get_id() ), home_url( '/' . $path . '/' ) );

	return (string) apply_filters( 'gacct_dashboard_devis_url', $url, $order );
}

/**
 * Montant formaté selon les réglages WooCommerce (« 40,00 € »), sans HTML.
 *
 * @param float $amount Montant.
 * @return string
 */
function gacct_dash_amount( $amount ) {
	if ( function_exists( 'wc_price' ) ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	return number_format( (float) $amount, 2, ',', ' ' ) . ' €';
}

/**
 * Pastille de délai (« 3 jours restants »), vide au-delà de l'horizon utile.
 *
 * @param int $days Nombre de jours restants.
 * @return string
 */
function gacct_dash_chip_days( $days ) {
	$days    = max( 0, (int) $days );
	$horizon = (int) apply_filters( 'gacct_dashboard_chip_horizon_days', 15 );

	if ( $days > $horizon ) {
		return '';
	}

	if ( 0 === $days ) {
		$label = __( 'aujourd’hui', 'gestion-atelier-cct' );
	} else {
		/* translators: %d: nombre de jours restants */
		$label = sprintf( _n( '%d jour restant', '%d jours restants', $days, 'gestion-atelier-cct' ), $days );
	}

	return (string) apply_filters( 'gacct_dashboard_chip_label', $label, $days );
}

/* =============================================================================
 *  CALLBACKS DE RENDU (Elementor / JetEngine) — NE PAS RENOMMER
 * ============================================================================= */

add_filter(
	'jet-engine/listings/allowed-callbacks',
	function ( $callbacks ) {
		$callbacks['gacct_dash_render_actions']  = 'GACCT: Tableau de bord — cartes d\'action';
		$callbacks['gacct_dash_has_actions']     = 'GACCT: Tableau de bord — a des actions ?';
		$callbacks['gacct_dash_is_new']          = 'GACCT: Tableau de bord — première visite ?';
		$callbacks['gacct_dash_is_order_detail'] = 'GACCT: Espace client — page de détail d\'une commande ?';
		$callbacks['gacct_dash_count_revisions'] = 'GACCT: Tableau de bord — compteur « révisions en cours » (libellé accordé)';
		$callbacks['gacct_dash_count_actions']   = 'GACCT: Tableau de bord — compteur « actions attendues » (libellé accordé)';
		$callbacks['gacct_dash_count_materiels'] = 'GACCT: Tableau de bord — compteur « matériels » (libellé accordé)';

		return $callbacks;
	}
);

/**
 * Rend les cartes « Actions attendues de vous » (CDC §2.2).
 *
 * Classes conformes à la maquette : `.action-card[.urgent]`,
 * `.action-icon.orange|blue|indigo|teal`, `.action-body`, `.action-title`,
 * `.chip-delay`, `.action-text` (avec `.hl`), `.action-note`,
 * `.action-btn.primary|ghost`. Chaîne vide s'il n'y a rien à faire : c'est au
 * template Elementor d'afficher la ligne « Rien à faire de votre côté ».
 *
 * @param mixed $value Ignoré (le callback est appelé sur un champ quelconque).
 * @return string HTML.
 */
function gacct_dash_render_actions( $value = null ) {
	$data = gacct_dash_data();

	if ( empty( $data['actions'] ) ) {
		return '';
	}

	$couleurs = (array) apply_filters(
		'gacct_dashboard_action_colors',
		array(
			'virement'   => 'orange',
			'expedition' => 'blue',
			'devis'      => 'indigo',
			'solde'      => 'teal',
		)
	);

	$html = '';

	foreach ( $data['actions'] as $action ) {
		$couleur = isset( $couleurs[ $action['type'] ] ) ? $couleurs[ $action['type'] ] : 'blue';

		$chip = '' !== (string) $action['chip']
			? '<span class="chip-delay">' . gacct_dash_icon( 'clock' ) . esc_html( $action['chip'] ) . '</span>'
			: '';

		$note = '' !== (string) $action['note']
			? '<div class="action-note">' . esc_html( $action['note'] ) . '</div>'
			: '';

		$bouton = '';
		if ( ! empty( $action['url'] ) && ! empty( $action['cta_label'] ) ) {
			$bouton = sprintf(
				'<a class="action-btn %1$s" href="%2$s">%3$s%4$s</a>',
				esc_attr( 'ghost' === $action['cta_style'] ? 'ghost' : 'primary' ),
				esc_url( $action['url'] ),
				esc_html( $action['cta_label'] ),
				gacct_dash_icon( 'arrow' )
			);
		}

		// Structure de la maquette : le bouton est FRÈRE de .action-body,
		// pas son enfant (c'est ce qui permet l'alignement à droite en desktop
		// et le passage en pleine largeur sous 780 px).
		$html .= sprintf(
			'<div class="action-card%1$s">'
				. '<div class="action-icon %2$s">%3$s</div>'
				. '<div class="action-body">'
					. '<div class="action-title">%4$s%5$s</div>'
					. '<div class="action-text">%6$s</div>'
					. '%7$s'
				. '</div>'
				. '%8$s'
			. '</div>',
			! empty( $action['urgent'] ) ? ' urgent' : '',
			esc_attr( $couleur ),
			gacct_dash_icon( $action['icon'] ),
			esc_html( $action['title'] ),
			$chip,
			$action['text_html'], // déjà échappé à la construction (seuls des <span class="hl"> sont ajoutés).
			$note,
			$bouton
		);
	}

	return (string) apply_filters( 'gacct_dashboard_actions_html', $html, $data );
}

/**
 * Icônes SVG des cartes d'action (tracés repris de la maquette).
 *
 * Volontairement local plutôt que `gacct_conf_icon()` : les tracés de la page
 * de confirmation ne sont pas ceux de la maquette du tableau de bord. Aucune
 * couleur n'est posée ici, tout vient de `currentColor` / de la CSS.
 *
 * @param string $name card|truck|file|check|clock|arrow.
 * @return string SVG inline.
 */
function gacct_dash_icon( $name ) {
	$icons = (array) apply_filters(
		'gacct_dashboard_icons',
		array(
			'card'  => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
			'truck' => '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7zM7.5 19a2 2 0 100-4 2 2 0 000 4zM17.5 19a2 2 0 100-4 2 2 0 000 4z"/>',
			'file'  => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h4"/>',
			'check' => '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/>',
			'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'arrow' => '<path d="M9 6l6 6-6 6"/>',
			'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 9h18M12 13v3M12 18.5h.01"/>',
		)
	);

	$path = isset( $icons[ $name ] ) ? $icons[ $name ] : $icons['check'];

	return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $path . '</svg>';
}

/**
 * Le client a-t-il au moins une action en attente ? (Dynamic Visibility)
 *
 * @param mixed $value Ignoré.
 * @return bool
 */
function gacct_dash_has_actions( $value = null ) {
	$data = gacct_dash_data();

	return ! empty( $data['has_actions'] );
}

/**
 * Sommes-nous sur la page de DÉTAIL d'une commande WooCommerce ?
 *
 * Sert à la visibilité conditionnelle du widget `jet-myaccount-content` de la
 * page « Mon compte » : ce widget rend le contenu natif de WooCommerce et ne
 * doit apparaître QUE sur `/mon-compte/view-order/{id}/`, jamais sous le
 * tableau de bord sur-mesure (où il affichait « Bonjour … / vos commandes
 * récentes »).
 *
 * ⚠ Pourquoi une macro et pas le tag Elementor `post-url` (version conservée
 * par la révision 618 de la page 14) : `post-url` appelle `get_permalink()`,
 * qui renvoie le permalien de la PAGE — soit `/mon-compte/` sur le tableau de
 * bord ET sur le détail de commande, les endpoints WooCommerce n'étant pas des
 * posts. La condition « contient view-order » n'est donc jamais vraie et le
 * widget disparaît des deux côtés : la page de détail de commande devient
 * blanche (constaté en headless le 28/07/2026).
 *
 * `is_wc_endpoint_url()` interroge le slug d'endpoint RÉGLÉ dans WooCommerce :
 * rien n'est écrit en dur, ni ici ni dans `_elementor_data`.
 *
 * @param mixed $value Ignoré (signature de callback JetEngine).
 * @return bool
 */
function gacct_dash_is_order_detail( $value = null ) {
	$endpoint = (string) apply_filters( 'gacct_dashboard_order_detail_endpoint', 'view-order' );

	$is_detail = function_exists( 'is_wc_endpoint_url' ) ? (bool) is_wc_endpoint_url( $endpoint ) : false;

	return (bool) apply_filters( 'gacct_dashboard_is_order_detail', $is_detail, $endpoint );
}

/**
 * Première visite : aucune commande ni révision (CDC §3).
 *
 * @param mixed $value Ignoré.
 * @return bool
 */
function gacct_dash_is_new( $value = null ) {
	$data = gacct_dash_data();

	return ! empty( $data['is_new'] );
}

/**
 * Valeur brute d'un compteur du bandeau.
 *
 * @param string $key     revisions|actions|materiels.
 * @param int    $user_id Utilisateur (0 = utilisateur courant).
 * @return int
 */
function gacct_dash_counter( $key, $user_id = 0 ) {
	$data = gacct_dash_data( $user_id );

	return isset( $data['counters'][ $key ] ) ? (int) $data['counters'][ $key ] : 0;
}

/**
 * Libellé COMPLET et accordé d'un compteur : « <b>3</b> révisions en cours »,
 * « <b>1</b> révision en cours », « <b>0</b> action attendue ».
 *
 * ⚠ C'est ici, et non dans le `dynamic_field_format` du template Elementor, que
 * vit le libellé : un format figé ne peut pas s'accorder en nombre (on lisait
 * « 1 révisions en cours »). Les widgets du 508 ne portent donc plus que `%s`.
 * Accord français : le singulier couvre 0 et 1 (règle gettext `n > 1`).
 *
 * @param string $key   revisions|actions|materiels.
 * @param int    $count Nombre.
 * @return string HTML.
 */
function gacct_dash_counter_label( $key, $count ) {
	$count  = (int) $count;
	$suffix = $count > 1 ? '_many' : '_one';
	$format = gacct_dash_text( 'counter_' . $key . $suffix );

	if ( '' === $format ) {
		$format = '%s';
	}

	$label = sprintf( $format, '<b>' . esc_html( number_format_i18n( $count ) ) . '</b>' );

	return (string) apply_filters( 'gacct_dashboard_counter_label', $label, (string) $key, $count );
}

/**
 * Compteur « révisions en cours » (états 1 à 6), libellé accordé compris.
 *
 * @param mixed $value Ignoré.
 * @return string HTML.
 */
function gacct_dash_count_revisions( $value = null ) {
	return gacct_dash_counter_label( 'revisions', gacct_dash_counter( 'revisions' ) );
}

/**
 * Compteur « actions attendues », libellé accordé compris.
 *
 * @param mixed $value Ignoré.
 * @return string HTML.
 */
function gacct_dash_count_actions( $value = null ) {
	return gacct_dash_counter_label( 'actions', gacct_dash_counter( 'actions' ) );
}

/**
 * Compteur « matériels enregistrés » (même décompte que l'onglet « Mon
 * Matériel »), libellé accordé compris.
 *
 * @param mixed $value Ignoré.
 * @return string HTML.
 */
function gacct_dash_count_materiels( $value = null ) {
	return gacct_dash_counter_label( 'materiels', gacct_dash_counter( 'materiels' ) );
}

/**
 * Prénom du client, pour « Bonjour {prénom} ». Repli : display_name.
 *
 * @param int $user_id Utilisateur (0 = utilisateur courant).
 * @return string
 */
function gacct_dash_first_name( $user_id = 0 ) {
	$data   = gacct_dash_data( $user_id );
	$prenom = trim( (string) $data['user']['first_name'] );

	if ( '' === $prenom ) {
		$prenom = trim( (string) $data['user']['display_name'] );
	}

	return $prenom;
}

/**
 * Le client a-t-il au moins une révision en cours à afficher ? (état < 7)
 *
 * @param mixed $value Ignoré.
 * @return bool
 */
function gacct_dash_has_revisions( $value = null ) {
	$data = gacct_dash_data();

	return ! empty( $data['revisions'] );
}

/**
 * Le client a-t-il au moins un matériel enregistré ?
 *
 * @param mixed $value Ignoré.
 * @return bool
 */
function gacct_dash_has_materiels( $value = null ) {
	$data = gacct_dash_data();

	return ! empty( $data['materiels'] );
}

/**
 * Le client a-t-il au moins un rapport de révision disponible ?
 *
 * @param mixed $value Ignoré.
 * @return bool
 */
function gacct_dash_has_documents( $value = null ) {
	$data = gacct_dash_data();

	return ! empty( $data['documents'] );
}

/**
 * Une voile du client arrive-t-elle en fin de période de révision ?
 *
 * @param mixed $value Ignoré.
 * @return bool
 */
function gacct_dash_has_alerte_revision( $value = null ) {
	$data = gacct_dash_data();

	return ! empty( $data['alerte_revision'] );
}

/* =============================================================================
 *  CALLBACKS DE LISTING (§2.3, §2.5, §2.6) — NE PAS RENOMMER
 *
 *  Patron du site : le callback reçoit UN identifiant (colonne de la requête)
 *  et va rechercher le reste lui-même, comme `jwcct_render_equipment_info()`.
 *  Chacun renvoie une chaîne vide si la donnée manque.
 * ============================================================================= */

add_filter(
	'jet-engine/listings/allowed-callbacks',
	function ( $callbacks ) {
		$callbacks['gacct_dash_render_voile']           = 'GACCT: Tableau de bord — voile (pastille + marque/modèle)';
		$callbacks['gacct_dash_render_tracker_compact'] = 'GACCT: Tableau de bord — mini-tracker';
		$callbacks['gacct_dash_render_rev_extra']       = 'GACCT: Tableau de bord — info secondaire de révision';
		$callbacks['gacct_dash_render_gear']            = 'GACCT: Tableau de bord — carte matériel';
		$callbacks['gacct_dash_render_document']        = 'GACCT: Tableau de bord — ligne document PDF';
		$callbacks['gacct_dash_render_alerte_revision'] = 'GACCT: Tableau de bord — alerte révision périodique';
		$callbacks['gacct_dash_has_revisions']          = 'GACCT: Tableau de bord — a des révisions ?';
		$callbacks['gacct_dash_has_materiels']          = 'GACCT: Tableau de bord — a du matériel ?';
		$callbacks['gacct_dash_has_documents']          = 'GACCT: Tableau de bord — a des documents ?';
		$callbacks['gacct_dash_has_alerte_revision']    = 'GACCT: Tableau de bord — a une alerte révision ?';

		return $callbacks;
	}
);

/**
 * Une ligne CCT `revision` par son _ID, en SQL direct (le cache objet JetEngine
 * peut resservir un `etat_de_la_commande` périmé). Mémoïsé par requête.
 *
 * @param int $revision_id Identifiant de la révision.
 * @return array<string,mixed>|null
 */
function gacct_dash_revision_row( $revision_id ) {
	static $cache = array();

	$revision_id = absint( $revision_id );

	if ( ! $revision_id ) {
		return null;
	}

	if ( array_key_exists( $revision_id, $cache ) ) {
		return $cache[ $revision_id ];
	}

	$cache[ $revision_id ] = null;

	if ( ! function_exists( 'gacct_demande_table_name' ) ) {
		return null;
	}

	$table = gacct_demande_table_name( JWCCT_CCT_REVISION );

	if ( ! gacct_demande_table_exists( $table ) ) {
		return null;
	}

	global $wpdb;

	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->prepare( "SELECT * FROM {$table} WHERE _ID = %d LIMIT 1", $revision_id ),
		ARRAY_A
	);

	if ( is_array( $row ) ) {
		$cache[ $revision_id ] = $row;
	}

	return $cache[ $revision_id ];
}

/**
 * Contexte complet d'une révision : sa ligne CCT, sa commande et les données
 * déjà calculées par `gacct_conf_data()`.
 *
 * @param int $revision_id Identifiant de la révision.
 * @return array{row:array,order:WC_Order|false,conf:array}|null
 */
function gacct_dash_revision_context( $revision_id ) {
	$row = gacct_dash_revision_row( $revision_id );

	if ( ! $row ) {
		return null;
	}

	$order_id = (int) ( $row['order_id'] ?? 0 );
	$order    = ( $order_id && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : false;
	$order    = $order instanceof WC_Order ? $order : false;

	return array(
		'row'   => $row,
		'order' => $order,
		'conf'  => ( $order && function_exists( 'gacct_conf_data' ) ) ? gacct_conf_data( $order ) : array(),
	);
}

/**
 * Libellé lisible des couleurs d'une voile (« Rouge / Blanc »).
 *
 * Repose sur la palette partagée `gacct_couleurs_voile()` : la saisie brute
 * n'est utilisée qu'en dernier recours (dossiers antérieurs au sélecteur).
 *
 * @param string $saisie Valeur brute de la colonne `couleur`.
 * @return string
 */
function gacct_dash_couleurs_libelle( $saisie ) {
	$saisie = trim( (string) $saisie );

	if ( '' === $saisie || ! function_exists( 'gacct_extraire_couleurs' ) ) {
		return $saisie;
	}

	$palette = gacct_couleurs_voile();
	$noms    = array();

	foreach ( gacct_extraire_couleurs( $saisie ) as $couleur ) {
		foreach ( $palette as $nom => $teintes ) {
			if ( $teintes['base'] === $couleur['base'] ) {
				$noms[] = ucfirst( $nom );
				break;
			}
		}
	}

	return $noms ? implode( ' / ', $noms ) : $saisie;
}

/**
 * Bloc « voile » d'une ligne de révision (§2.3) : pastille dégradée + identité.
 *
 * @param int|string $revision_id Identifiant de la révision (colonne du listing).
 * @return string HTML.
 */
function gacct_dash_render_voile( $revision_id ) {
	$row = gacct_dash_revision_row( $revision_id );

	if ( ! $row ) {
		return '';
	}

	$couleur_brute = (string) ( $row['couleur'] ?? '' );
	$gradient      = function_exists( 'gacct_degrade_couleurs' )
		? gacct_degrade_couleurs( gacct_extraire_couleurs( $couleur_brute ) )
		: '';

	$marque = trim( (string) ( $row['marque'] ?? '' ) );
	$marque = ( '' !== $marque && function_exists( 'jwcct_render_marque_libelle' ) ) ? jwcct_render_marque_libelle( $marque ) : $marque;

	$brand = trim( $marque . ' ' . trim( (string) ( $row['modele'] ?? '' ) ) );

	$sous_titre = array_filter(
		array(
			'' !== trim( (string) ( $row['taille'] ?? '' ) )
				? sprintf( gacct_dash_text( 'size_label' ), strtoupper( trim( (string) $row['taille'] ) ) )
				: '',
			gacct_dash_couleurs_libelle( $couleur_brute ),
		)
	);

	return sprintf(
		'<div class="voile-stack">'
			. '<div class="voile-swatch" style="%1$s" title="%2$s"></div>'
			. '<div class="voile-meta">'
				. '<div class="voile-brand">%3$s</div>'
				. '<div class="voile-model">%4$s</div>'
			. '</div>'
		. '</div>',
		esc_attr( $gradient ),
		esc_attr( $couleur_brute ),
		esc_html( $brand ),
		esc_html( implode( ' · ', $sous_titre ) )
	);
}

/**
 * Mini-tracker compact d'une ligne de révision (§2.3).
 *
 * @param int|string $revision_id Identifiant de la révision.
 * @return string HTML.
 */
function gacct_dash_render_tracker_compact( $revision_id ) {
	$row = gacct_dash_revision_row( $revision_id );

	if ( ! $row ) {
		return '';
	}

	$etat    = ( '' === (string) ( $row['etat_de_la_commande'] ?? '' ) ) ? 0 : (int) $row['etat_de_la_commande'];
	$tracker = gacct_dash_tracker( $etat );
	$action  = ! empty( $tracker['is_action'] ) ? ' action' : '';

	return sprintf(
		'<div class="tracker">'
			. '<div class="tracker-bar"><div class="tracker-fill%1$s" style="width:%2$d%%"></div></div>'
			. '<div class="tracker-label">'
				. '<span class="tracker-state%1$s">%3$s</span>'
				. '<span class="tracker-step">%4$s</span>'
			. '</div>'
		. '</div>',
		$action,
		(int) $tracker['pct'],
		esc_html( $tracker['label'] ),
		esc_html( $tracker['step'] )
	);
}

/**
 * Information secondaire d'une ligne de révision (§2.3) : solde restant, suivi
 * colis cliquable, créneau atelier, ou mention « créneau libéré » (no-show).
 *
 * @param int|string $revision_id Identifiant de la révision.
 * @return string HTML.
 */
function gacct_dash_render_rev_extra( $revision_id ) {
	$contexte = gacct_dash_revision_context( $revision_id );

	if ( ! $contexte ) {
		return '';
	}

	$extra = gacct_dash_revision_extra( $contexte['row'], $contexte['order'], $contexte['conf'] );

	if ( '' === (string) $extra['label'] && '' === (string) $extra['value'] ) {
		return '';
	}

	$valeur = ! empty( $extra['url'] )
		? sprintf(
			'<a class="value" href="%s"%s>%s</a>',
			esc_url( $extra['url'] ),
			// Un suivi transporteur part chez un tiers, le reste reste sur le site.
			0 === strpos( (string) $extra['url'], home_url() ) ? '' : ' target="_blank" rel="noopener"',
			esc_html( $extra['value'] )
		)
		: sprintf( '<span class="value">%s</span>', esc_html( $extra['value'] ) );

	return sprintf(
		'<div class="rev-extra"><span class="label">%s</span>%s</div>',
		esc_html( $extra['label'] ),
		$valeur
	);
}

/**
 * Carte « matériel » du §2.5. Reçoit la colonne `derniere_revision_id` exposée
 * par la query 22 (même regroupement que l'onglet « Mon Matériel »).
 *
 * @param int|string $derniere_revision_id Révision la plus récente de la voile.
 * @return string HTML.
 */
function gacct_dash_render_gear( $derniere_revision_id ) {
	$row = gacct_dash_revision_row( $derniere_revision_id );

	if ( ! $row ) {
		return '';
	}

	$couleur_brute = (string) ( $row['couleur'] ?? '' );
	$gradient      = function_exists( 'gacct_degrade_couleurs' )
		? gacct_degrade_couleurs( gacct_extraire_couleurs( $couleur_brute ) )
		: '';

	$marque = trim( (string) ( $row['marque'] ?? '' ) );
	$marque = ( '' !== $marque && function_exists( 'jwcct_render_marque_libelle' ) ) ? jwcct_render_marque_libelle( $marque ) : $marque;

	$sous_titre = array_filter(
		array(
			'' !== trim( (string) ( $row['taille'] ?? '' ) )
				? sprintf( gacct_dash_text( 'size_label' ), strtoupper( trim( (string) $row['taille'] ) ) )
				: '',
			gacct_dash_couleurs_libelle( $couleur_brute ),
		)
	);

	$url = add_query_arg( 'remat', absint( $derniere_revision_id ), home_url( '/demande-intervention/' ) );

	return sprintf(
		'<a class="gear-card" href="%1$s">'
			. '<div class="gear-swatch" style="%2$s" title="%3$s"></div>'
			. '<div>'
				. '<div class="gear-name">%4$s</div>'
				. '<div class="gear-sub">%5$s</div>'
			. '</div>'
		. '</a>',
		esc_url( $url ),
		esc_attr( $gradient ),
		esc_attr( $couleur_brute ),
		esc_html( trim( $marque . ' ' . trim( (string) ( $row['modele'] ?? '' ) ) ) ),
		esc_html( implode( ' · ', $sous_titre ) )
	);
}

/**
 * Ligne « document » du §2.6 : rapport PDF d'une révision terminée.
 *
 * @param int|string $revision_id Identifiant de la révision.
 * @return string HTML.
 */
function gacct_dash_render_document( $revision_id ) {
	$row = gacct_dash_revision_row( $revision_id );

	if ( ! $row || empty( $row['rapport_pdf'] ) ) {
		return '';
	}

	$url = wp_get_attachment_url( (int) $row['rapport_pdf'] );

	if ( ! $url ) {
		return '';
	}

	$materiel = gacct_dash_materiel_label( $row );
	$date_ts  = ! empty( $row['cct_modified'] ) ? (int) strtotime( (string) $row['cct_modified'] ) : 0;

	$meta = array_filter(
		array(
			$date_ts ? wp_date( get_option( 'date_format' ), $date_ts ) : '',
			gacct_dash_text( 'doc_format' ),
		)
	);

	// Icône PDF : mêmes tracés que `jwcct_get_pdf_icon_link()` / la maquette,
	// mais sans le lien (c'est toute la ligne qui est cliquable ici).
	$icone = '<svg viewBox="0 0 24 24" aria-hidden="true">'
		. '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/>'
		. '<path class="pdf-mark" stroke="none" d="M9 14h2v4H9zM13 14h1.5a1.5 1.5 0 010 3H13v1h-1v-4h1zm0 1v1h1.5a.5.5 0 000-1H13zM16 14h2v1h-1v.7h.8v1H17V18h-1v-4z"/>'
		. '</svg>';

	return sprintf(
		'<a class="doc-row" href="%1$s" target="_blank" rel="noopener" download>'
			. '<div class="doc-icon">%2$s</div>'
			. '<div class="doc-body">'
				. '<div class="doc-name">%3$s</div>'
				. '<div class="doc-date">%4$s</div>'
			. '</div>'
			. '<span class="doc-dl" aria-label="%5$s">%6$s</span>'
		. '</a>',
		esc_url( $url ),
		$icone,
		esc_html( sprintf( gacct_dash_text( 'doc_name' ), $materiel ? $materiel : gacct_dash_text( 'report' ) ) ),
		esc_html( implode( ' · ', $meta ) ),
		esc_attr( gacct_dash_text( 'doc_download' ) ),
		'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/></svg>'
	);
}

/**
 * Carte « alerte révision périodique » (§2.5). Chaîne vide s'il n'y a pas
 * d'échéance dans le seuil configuré.
 *
 * @param mixed $value Ignoré.
 * @return string HTML.
 */
function gacct_dash_render_alerte_revision( $value = null ) {
	$data   = gacct_dash_data();
	$alerte = $data['alerte_revision'];

	if ( empty( $alerte ) ) {
		return '';
	}

	$modele = $alerte['date_ts'] < time() ? 'alerte_card_overdue' : 'alerte_card_text';

	$html = sprintf(
		'<div class="alert-card">'
			. '<div class="action-icon">%1$s</div>'
			. '<div class="alert-body">'
				. '<div class="alert-title">%2$s</div>'
				. '<div class="alert-text">%3$s</div>'
				. '<a class="action-btn primary" href="%4$s">%5$s%6$s</a>'
			. '</div>'
		. '</div>',
		gacct_dash_icon( 'calendar' ),
		esc_html( gacct_dash_text( 'alerte_card_title' ) ),
		sprintf(
			gacct_dash_text( $modele ),
			'<span class="hl">' . esc_html( $alerte['materiel'] ) . '</span>',
			'<span class="hl">' . esc_html( $alerte['date_texte'] ) . '</span>'
		),
		esc_url( $alerte['url'] ),
		esc_html( gacct_dash_text( 'alerte_cta' ) ),
		gacct_dash_icon( 'arrow' )
	);

	return (string) apply_filters( 'gacct_dashboard_alerte_html', $html, $alerte );
}

/* =============================================================================
 *  MACROS JETENGINE (visibilité conditionnelle du template Elementor)
 *
 *  Le module Dynamic Visibility de JetEngine 3.8.13 n'offre ni condition
 *  « Hook » ni « Custom callback » : la seule voie propre est la MACRO, que le
 *  checker résout explicitement (`inc/conditions-checker.php` applique
 *  `do_macros()` sur le champ testé). Usage côté Elementor :
 *      "jedv_field": "%gacct_dash_has_actions%", "jedv_condition": "equal",
 *      "jedv_value": "1"
 * ============================================================================= */

/**
 * Registre des macros du tableau de bord : tag → { label, cb }.
 *
 * Les callbacks réutilisent les fonctions publiques du module — aucune règle
 * métier n'est dupliquée ici. Toutes renvoient une CHAÎNE (les comparaisons de
 * Dynamic Visibility se font sur des chaînes).
 *
 * @return array<string,array{label:string,cb:callable}>
 */
function gacct_dash_macros_map() {
	$bool = function ( $callable ) {
		return function () use ( $callable ) {
			return call_user_func( $callable ) ? '1' : '0';
		};
	};

	// ⚠ Les macros de comptage doivent rester NUMÉRIQUES : elles servent aux
	// comparaisons de Dynamic Visibility. Elles lisent donc `gacct_dash_counter()`
	// et non `gacct_dash_count_*()`, qui renvoient désormais le libellé accordé.
	$int = function ( $key ) {
		return function () use ( $key ) {
			return (string) gacct_dash_counter( $key );
		};
	};

	$map = array(
		'gacct_dash_has_actions'         => array(
			'label' => __( 'GACCT — a des actions attendues (1/0)', 'gestion-atelier-cct' ),
			'cb'    => $bool( 'gacct_dash_has_actions' ),
		),
		'gacct_dash_is_new'              => array(
			'label' => __( 'GACCT — première visite (1/0)', 'gestion-atelier-cct' ),
			'cb'    => $bool( 'gacct_dash_is_new' ),
		),
		'gacct_dash_is_order_detail'     => array(
			'label' => __( 'GACCT — page de détail d\'une commande (1/0)', 'gestion-atelier-cct' ),
			'cb'    => $bool( 'gacct_dash_is_order_detail' ),
		),
		'gacct_dash_has_revisions'       => array(
			'label' => __( 'GACCT — a des révisions en cours (1/0)', 'gestion-atelier-cct' ),
			'cb'    => $bool( 'gacct_dash_has_revisions' ),
		),
		'gacct_dash_has_materiels'       => array(
			'label' => __( 'GACCT — a du matériel enregistré (1/0)', 'gestion-atelier-cct' ),
			'cb'    => $bool( 'gacct_dash_has_materiels' ),
		),
		'gacct_dash_has_documents'       => array(
			'label' => __( 'GACCT — a des documents (1/0)', 'gestion-atelier-cct' ),
			'cb'    => $bool( 'gacct_dash_has_documents' ),
		),
		'gacct_dash_has_alerte_revision' => array(
			'label' => __( 'GACCT — a une alerte révision périodique (1/0)', 'gestion-atelier-cct' ),
			'cb'    => $bool( 'gacct_dash_has_alerte_revision' ),
		),
		'gacct_dash_count_revisions'     => array(
			'label' => __( 'GACCT — nombre de révisions en cours', 'gestion-atelier-cct' ),
			'cb'    => $int( 'revisions' ),
		),
		'gacct_dash_count_actions'       => array(
			'label' => __( 'GACCT — nombre d\'actions attendues', 'gestion-atelier-cct' ),
			'cb'    => $int( 'actions' ),
		),
		'gacct_dash_count_materiels'     => array(
			'label' => __( 'GACCT — nombre de matériels', 'gestion-atelier-cct' ),
			'cb'    => $int( 'materiels' ),
		),
		'gacct_dash_first_name'          => array(
			'label' => __( 'GACCT — prénom du client', 'gestion-atelier-cct' ),
			'cb'    => 'gacct_dash_first_name',
		),
		'gacct_dash_alerte_revision_html' => array(
			'label' => __( 'GACCT — carte d\'alerte révision périodique (HTML)', 'gestion-atelier-cct' ),
			'cb'    => 'gacct_dash_render_alerte_revision',
		),
	);

	return (array) apply_filters( 'gacct_dashboard_macros', $map );
}

add_action( 'jet-engine/register-macros', 'gacct_dash_register_macros' );

/**
 * Déclare les macros auprès de JetEngine.
 *
 * La classe générique est définie ici (et non au chargement du fichier) parce
 * que `Jet_Engine_Base_Macros` n'existe qu'une fois JetEngine initialisé.
 */
function gacct_dash_register_macros() {
	if ( ! class_exists( 'Jet_Engine_Base_Macros' ) ) {
		return;
	}

	if ( ! class_exists( 'GACCT_Dash_Macro' ) ) {

		/**
		 * Macro générique du tableau de bord : un tag, un libellé, un callable.
		 */
		class GACCT_Dash_Macro extends \Jet_Engine_Base_Macros {

			/** @var string */
			private $tag;

			/** @var string */
			private $label;

			/** @var callable */
			private $cb;

			public function __construct( $tag, $label, $cb ) {
				$this->tag   = $tag;
				$this->label = $label;
				$this->cb    = $cb;

				parent::__construct();
			}

			public function macros_tag() {
				return $this->tag;
			}

			public function macros_name() {
				return $this->label;
			}

			public function macros_args() {
				return array();
			}

			public function macros_callback( $args = array() ) {
				return (string) call_user_func( $this->cb );
			}
		}
	}

	foreach ( gacct_dash_macros_map() as $tag => $macro ) {
		new GACCT_Dash_Macro( $tag, $macro['label'], $macro['cb'] );
	}
}

/* =============================================================================
 *  ASSETS
 * ============================================================================= */

add_action( 'wp_enqueue_scripts', 'gacct_dash_enqueue_assets' );

/**
 * Charge la CSS du tableau de bord sur l'espace client uniquement (page compte
 * du Profile Builder, page 14 ici, sous-pages comprises).
 */
function gacct_dash_enqueue_assets() {
	if ( ! gacct_dash_should_enqueue() ) {
		return;
	}

	$base_url = plugins_url( '', dirname( __FILE__ ) );
	$base_dir = dirname( __DIR__ );
	$css_rel  = 'assets/css/dashboard.css';

	if ( ! file_exists( $base_dir . '/' . $css_rel ) ) {
		return;
	}

	wp_enqueue_style(
		'gacct-dashboard',
		$base_url . '/' . $css_rel,
		array(),
		(string) filemtime( $base_dir . '/' . $css_rel )
	);
}

/**
 * Sommes-nous sur l'espace client ? (page compte du Profile Builder)
 *
 * @return bool
 */
function gacct_dash_should_enqueue() {
	$should = false;
	$page_id = 0;

	if ( class_exists( '\Jet_Engine\Modules\Profile_Builder\Settings' ) ) {
		$settings = new \Jet_Engine\Modules\Profile_Builder\Settings();
		$page_id  = absint( $settings->get( 'account_page' ) );
	}

	if ( $page_id && function_exists( 'is_page' ) && is_page( $page_id ) ) {
		$should = true;
	}

	return (bool) apply_filters( 'gacct_dashboard_enqueue', $should );
}
