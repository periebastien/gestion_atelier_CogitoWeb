<?php
/**
 * Parachutes de secours du client : objet suivi dans « Mon matériel »
 * (09/09/2026, cahier des charges coffre/projets/idees/suivi-parachutes-de-secours.md).
 *
 * Aucune table nouvelle : un secours est déduit des fiches d'intervention
 * (colonnes secours_* du CCT revision, remplies à la demande depuis le 08/09)
 * et de l'historique de l'ancien site (gacct_historique_secours_client()),
 * regroupé par marque + modèle + taille. Pour chaque secours : dernier pliage
 * (date du créneau de la dernière intervention terminée qui comportait un
 * pliage, ou date de révision de l'ancien site), prochain pliage conseillé
 * (dernier + 12 mois, filtrable), état (à jour / bientôt / à replier /
 * pliage en cours). Base du futur rappel annuel.
 *
 * - Shortcode `[gacct_secours_client]` (page « Mon matériel », gabarit 1623).
 * - Config du formulaire : `secoursClient` = cartes « secours déjà connu ».
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Périodicité du pliage en mois (norme : une fois par an).
 */
function gacct_secours_periodicite_mois() {
	return (int) apply_filters( 'gacct_secours_periodicite_mois', 12 );
}

/**
 * Clé de regroupement d'un secours (marque + modèle + taille normalisés).
 */
function gacct_secours_cle( $marque, $modele, $taille ) {
	$n = static function ( $v ) {
		return preg_replace( '/[^A-Z0-9]/', '', strtoupper( remove_accents( (string) $v ) ) );
	};
	$m = $n( $marque ); $mo = $n( $modele );
	if ( '' === $m && '' === $mo ) {
		return '';
	}
	return $m . '|' . $mo . '|' . $n( $taille );
}

/**
 * Les secours d'un client, du plus récent au plus ancien.
 *
 * @param int $user_id Client (0 = courant).
 * @return array<int,array<string,mixed>> marque, modele, taille, date_production,
 *         dernier_pliage (Y-m-d ou ''), prochain_pliage (Y-m-d ou ''), statut
 *         (a_jour|bientot|a_replier|en_cours|inconnu), source (atelier|ancien),
 *         revision_id, en_cours (bool), historique_id.
 */
function gacct_secours_client( $user_id = 0 ) {
	global $wpdb;

	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	if ( ! $user_id ) {
		return array();
	}

	$out = array();
	$vus = array();

	// --- Nouveau système : fiches d'intervention avec un secours décrit.
	$table = $wpdb->prefix . 'jet_cct_revision';
	$occ   = $wpdb->prefix . 'jet_cct_occupation_atelier';
	$rel   = function_exists( 'gacct_relation_id' ) ? (int) gacct_relation_id( 'client_to_revision', 13 ) : 13;

	if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) )
		&& $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'secours_marque'" ) ) { // phpcs:ignore
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT r._ID, r.order_id, r.etat_de_la_commande, r.pliages_secours, r.secours_marque, r.secours_modele, r.secours_taille, r.secours_date, r.cct_created,
				( SELECT o.date_reservee FROM {$occ} o WHERE o.revision_id = r._ID AND o.cct_status = 'publish' ORDER BY o._ID DESC LIMIT 1 ) AS date_reservee
			 FROM {$table} r
			 WHERE r.cct_status = 'publish'
			   AND ( r.client_id = %d OR EXISTS ( SELECT 1 FROM {$wpdb->prefix}jet_rel_default rl WHERE rl.rel_id = %d AND rl.parent_object_id = %d AND rl.child_object_id = r._ID ) )
			   AND r.secours_marque IS NOT NULL AND TRIM( r.secours_marque ) <> ''
			 ORDER BY r.cct_created DESC",
			$user_id, $rel, $user_id
		), ARRAY_A );

		foreach ( (array) $rows as $r ) {
			$cle = gacct_secours_cle( $r['secours_marque'], $r['secours_modele'], $r['secours_taille'] );
			if ( '' === $cle ) {
				continue;
			}
			$etat    = '' === (string) $r['etat_de_la_commande'] ? 0 : (int) $r['etat_de_la_commande'];
			$pliage  = maybe_unserialize( $r['pliages_secours'] );
			$pliage  = is_array( $pliage ) ? array_filter( $pliage ) : ( '' !== trim( (string) $pliage ) ? array( $pliage ) : array() );
			$a_pliage = ! empty( $pliage );
			$ts      = $r['date_reservee'] ? (int) $r['date_reservee'] : strtotime( (string) $r['cct_created'] );
			$date    = $ts ? wp_date( 'Y-m-d', $ts ) : '';

			if ( isset( $vus[ $cle ] ) ) {
				$i = $vus[ $cle ];
				// Une intervention plus ancienne peut apporter un pliage terminé
				// que la plus récente (en cours) n'a pas encore.
				if ( $a_pliage && $etat >= 7 && $etat <= 8 && '' === $out[ $i ]['dernier_pliage'] ) {
					$out[ $i ]['dernier_pliage'] = $date;
				}
				if ( '' === $out[ $i ]['date_production'] && '' !== trim( (string) $r['secours_date'] ) ) {
					$out[ $i ]['date_production'] = trim( (string) $r['secours_date'] );
				}
				continue;
			}

			$vus[ $cle ] = count( $out );
			$out[]       = array(
				'cle'             => $cle,
				'marque'          => trim( (string) $r['secours_marque'] ),
				'modele'          => trim( (string) $r['secours_modele'] ),
				'taille'          => trim( (string) $r['secours_taille'] ),
				'date_production' => trim( (string) $r['secours_date'] ),
				'dernier_pliage'  => ( $a_pliage && $etat >= 7 && $etat <= 8 ) ? $date : '',
				'en_cours'        => ( $a_pliage && $etat >= 0 && $etat <= 6 ),
				'revision_id'     => (int) $r['_ID'],
				'historique_id'   => 0,
				'source'          => 'atelier',
			);
		}
	}

	// --- Ancien site.
	if ( function_exists( 'gacct_historique_secours_client' ) ) {
		foreach ( gacct_historique_secours_client( $user_id ) as $h ) {
			$cle = gacct_secours_cle( $h['marque'], $h['modele'], $h['taille'] );
			if ( '' === $cle ) {
				// Secours « non précisé » de l'ancien site : une seule ligne par client.
				$cle = 'NP';
			}
			if ( isset( $vus[ $cle ] ) ) {
				$i = $vus[ $cle ];
				if ( '' === $out[ $i ]['dernier_pliage'] || $h['dernier_pliage'] > $out[ $i ]['dernier_pliage'] ) {
					// L'ancien site ne connaît que des pliages faits : la date fait foi
					// si le nouveau système n'en a pas de plus récent.
					if ( '' === $out[ $i ]['dernier_pliage'] ) {
						$out[ $i ]['dernier_pliage'] = (string) $h['dernier_pliage'];
					}
				}
				continue;
			}
			$vus[ $cle ] = count( $out );
			$out[]       = array(
				'cle'             => $cle,
				'marque'          => (string) $h['marque'],
				'modele'          => (string) $h['modele'],
				'taille'          => (string) $h['taille'],
				'date_production' => '',
				'dernier_pliage'  => (string) $h['dernier_pliage'],
				'en_cours'        => false,
				'revision_id'     => 0,
				'historique_id'   => (int) $h['historique_id'],
				'source'          => 'ancien',
			);
		}
	}

	// --- Prochain pliage et statut.
	$mois   = gacct_secours_periodicite_mois();
	$auj    = wp_date( 'Y-m-d' );
	$bientot = wp_date( 'Y-m-d', strtotime( '+2 months' ) );
	foreach ( $out as &$s ) {
		$s['prochain_pliage'] = '' !== $s['dernier_pliage'] ? wp_date( 'Y-m-d', strtotime( $s['dernier_pliage'] . ' +' . $mois . ' months' ) ) : '';
		if ( $s['en_cours'] ) {
			$s['statut'] = 'en_cours';
		} elseif ( '' === $s['prochain_pliage'] ) {
			$s['statut'] = 'inconnu';
		} elseif ( $s['prochain_pliage'] < $auj ) {
			$s['statut'] = 'a_replier';
		} elseif ( $s['prochain_pliage'] <= $bientot ) {
			$s['statut'] = 'bientot';
		} else {
			$s['statut'] = 'a_jour';
		}
	}
	unset( $s );

	usort( $out, static function ( $a, $b ) {
		return strcmp( $b['dernier_pliage'], $a['dernier_pliage'] );
	} );

	return (array) apply_filters( 'gacct_secours_client', $out, $user_id );
}

/**
 * Libellé court d'un secours (« Supair Shine · M »).
 */
function gacct_secours_libelle( array $s ) {
	$parts = array_filter( array( trim( $s['marque'] . ' ' . $s['modele'] ), $s['taille'] ) );
	return implode( ' · ', $parts ) ?: __( 'Parachute de secours (modèle non précisé)', 'gestion-atelier-cct' );
}

/* =============================================================================
 *  PAGE « MON MATÉRIEL »
 * ============================================================================= */

function gacct_secours_client_texts() {
	return apply_filters( 'gacct_secours_client_texts', array(
		'titre'       => __( 'Mes parachutes de secours', 'gestion-atelier-cct' ),
		'intro'       => __( 'Un parachute de secours se replie une fois par an. Nous vous le rappellerons à l’approche de l’échéance.', 'gestion-atelier-cct' ),
		'vide'        => __( 'Aucun parachute de secours enregistré pour l’instant : il apparaîtra ici après un pliage ou un contrôle équipement.', 'gestion-atelier-cct' ),
		'taille'      => __( 'Taille', 'gestion-atelier-cct' ),
		'production'  => __( 'Fabriqué en', 'gestion-atelier-cct' ),
		'dernier'     => __( 'Dernier pliage', 'gestion-atelier-cct' ),
		'prochain'    => __( 'Prochain pliage conseillé', 'gestion-atelier-cct' ),
		'inconnu'     => __( 'non renseigné', 'gestion-atelier-cct' ),
		'ancien'      => __( 'Ancien site', 'gestion-atelier-cct' ),
		'statut'      => array(
			'a_jour'    => __( 'À jour', 'gestion-atelier-cct' ),
			'bientot'   => __( 'À replier bientôt', 'gestion-atelier-cct' ),
			'a_replier' => __( 'Pliage à prévoir', 'gestion-atelier-cct' ),
			'en_cours'  => __( 'Pliage en cours', 'gestion-atelier-cct' ),
			'inconnu'   => __( 'Date de pliage inconnue', 'gestion-atelier-cct' ),
		),
		'demander'    => __( 'Demander un pliage', 'gestion-atelier-cct' ),
		'voir_dossier' => __( 'Voir le dossier', 'gestion-atelier-cct' ),
	) );
}

add_shortcode( 'gacct_secours_client', 'gacct_secours_client_shortcode' );

function gacct_secours_client_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '';
	}
	$secours = gacct_secours_client();
	$texts   = gacct_secours_client_texts();
	$demande = (string) apply_filters( 'gacct_secours_demande_url', home_url( '/demande-intervention/' ) );

	ob_start();
	include dirname( __DIR__ ) . '/templates/secours-client.php';
	return (string) ob_get_clean();
}

/**
 * Titre de section de la page « Mon matériel », même style que « Mes parachutes
 * de secours » : `[gacct_titre_section texte="Mes voiles" compteur="voiles"]`.
 * compteur : voiles (matériels suivis du client) | secours | rien.
 */
add_shortcode( 'gacct_titre_section', function ( $atts ) {
	$a = shortcode_atts( array( 'texte' => '', 'compteur' => '' ), $atts );
	$n = null;
	if ( 'voiles' === $a['compteur'] && function_exists( 'gacct_demande_materiels_client' ) ) {
		$n = count( gacct_demande_materiels_client() );
	} elseif ( 'secours' === $a['compteur'] ) {
		$n = count( gacct_secours_client() );
	}
	return '<h3 class="gacct-sec-titre gacct-sec-titre-seul">' . esc_html( $a['texte'] )
		. ( null !== $n && $n > 0 ? ' <span class="gacct-sec-count">' . (int) $n . '</span>' : '' ) . '</h3>';
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'gacct_dash_should_enqueue' ) || ! gacct_dash_should_enqueue() ) {
		return;
	}
	$rel = 'assets/css/secours-client.css';
	$dir = dirname( __DIR__ );
	if ( file_exists( $dir . '/' . $rel ) ) {
		wp_enqueue_style( 'gacct-secours-client', plugins_url( '', dirname( __FILE__ ) ) . '/' . $rel, array(), (string) filemtime( $dir . '/' . $rel ) );
	}
} );

/* =============================================================================
 *  FORMULAIRE DE DEMANDE : cartes « secours déjà connu »
 * ============================================================================= */

add_filter( 'gacct_demande_v2_config', function ( $cfg ) {
	$cartes = array();
	if ( is_user_logged_in() ) {
		foreach ( gacct_secours_client() as $i => $s ) {
			if ( 'NP' === $s['cle'] ) {
				continue;
			}
			$cartes[] = array(
				'id'      => $i,
				'marque'  => $s['marque'],
				'modele'  => $s['modele'],
				'taille'  => $s['taille'],
				'date'    => $s['date_production'],
				'ancien'  => 'ancien' === $s['source'],
				'libelle' => gacct_secours_libelle( $s ),
			);
		}
	}
	$cfg['secoursClient'] = $cartes;
	// Présélection depuis « Demander un pliage » (?secours=<index>).
	$cfg['secoursPreselect'] = isset( $_GET['secours'] ) ? absint( $_GET['secours'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$cfg['i18n']['secoursConnus']     = __( 'Sélectionnez un parachute déjà connu chez nous, ou recherchez-en un autre.', 'gestion-atelier-cct' );
	$cfg['i18n']['secoursNouveau']    = __( 'Autre secours', 'gestion-atelier-cct' );
	$cfg['i18n']['secoursAncienSite'] = __( 'Ancien site', 'gestion-atelier-cct' );
	return $cfg;
}, 20 );
