<?php
/**
 * Modèle : clubs, commandes groupées (« lots »), responsables, dossiers des pilotes.
 *
 * Statuts d'un lot :
 *   demande   demande reçue, rien de réservé ;
 *   planifie  jours réservés, code généré (inscriptions ouvertes ou closes) ;
 *   facture   facture du club émise, en attente de paiement ;
 *   paye      facture réglée, le lot peut repartir ;
 *   expedie   lot réexpédié (ou retiré) ;
 *   annule    demande abandonnée, réserves libérées.
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

function gacct_clubs_statuts() {
	return apply_filters( 'gacct_clubs_statuts', array(
		'demande'  => array( 'Demande reçue', 'b-n' ),
		'planifie' => array( 'Planifiée', 'b-b' ),
		'facture'  => array( 'Commande à régler', 'b-y' ),
		'paye'     => array( 'Commande réglée', 'b-g' ),
		'expedie'  => array( 'Matériel réexpédié', 'b-g' ),
		'annule'   => array( 'Annulée', 'b-r' ),
	) );
}

/* ---------------------------------------------------------------- clubs --- */

function gacct_clubs_get_club( $club_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . gacct_clubs_table( 'clubs' ) . ' WHERE id = %d', $club_id ), ARRAY_A );
}

function gacct_clubs_create_club( array $data ) {
	global $wpdb;
	$wpdb->insert( gacct_clubs_table( 'clubs' ), array(
		'nom'     => sanitize_text_field( $data['nom'] ?? '' ),
		'adresse' => sanitize_textarea_field( $data['adresse'] ?? '' ),
		'email'   => sanitize_email( $data['email'] ?? '' ),
		'notes'   => '',
		'created' => current_time( 'mysql' ),
	) );
	return (int) $wpdb->insert_id;
}

function gacct_clubs_update_club( $club_id, array $data ) {
	global $wpdb;
	$clean = array();
	foreach ( array( 'nom' => 'sanitize_text_field', 'adresse' => 'sanitize_textarea_field', 'email' => 'sanitize_email', 'notes' => 'sanitize_textarea_field' ) as $k => $fn ) {
		if ( array_key_exists( $k, $data ) ) {
			$clean[ $k ] = call_user_func( $fn, $data[ $k ] );
		}
	}
	if ( $clean ) {
		$wpdb->update( gacct_clubs_table( 'clubs' ), $clean, array( 'id' => absint( $club_id ) ) );
	}
}

/** Clubs dont l'utilisateur est responsable. */
function gacct_clubs_for_user( $user_id ) {
	global $wpdb;
	if ( ! $user_id ) {
		return array();
	}
	return (array) $wpdb->get_results( $wpdb->prepare(
		'SELECT c.* FROM ' . gacct_clubs_table( 'clubs' ) . ' c INNER JOIN ' . gacct_clubs_table( 'club_responsables' ) . ' r ON r.club_id = c.id WHERE r.user_id = %d ORDER BY c.nom',
		$user_id
	), ARRAY_A );
}

function gacct_clubs_user_is_manager( $user_id, $club_id = 0 ) {
	global $wpdb;
	if ( ! $user_id ) {
		return false;
	}
	$sql = 'SELECT COUNT(*) FROM ' . gacct_clubs_table( 'club_responsables' ) . ' WHERE user_id = %d';
	$arg = array( $user_id );
	if ( $club_id ) {
		$sql  .= ' AND club_id = %d';
		$arg[] = $club_id;
	}
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $arg ) ) > 0;
}

function gacct_clubs_add_manager( $club_id, $user_id ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare(
		'INSERT IGNORE INTO ' . gacct_clubs_table( 'club_responsables' ) . ' (club_id, user_id, created) VALUES (%d, %d, %s)',
		$club_id,
		$user_id,
		current_time( 'mysql' )
	) );
}

function gacct_clubs_remove_manager( $club_id, $user_id ) {
	global $wpdb;
	$wpdb->delete( gacct_clubs_table( 'club_responsables' ), array( 'club_id' => absint( $club_id ), 'user_id' => absint( $user_id ) ) );
}

/** @return WP_User[] */
function gacct_clubs_managers( $club_id ) {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . gacct_clubs_table( 'club_responsables' ) . ' WHERE club_id = %d ORDER BY created', $club_id ) );
	return array_values( array_filter( array_map( 'get_userdata', array_map( 'absint', $ids ) ) ) );
}

/** Club existant au nom proche (aide à éviter les doublons dans la console). */
function gacct_clubs_similar( $nom, $exclude_id = 0 ) {
	global $wpdb;
	$key = remove_accents( mb_strtolower( trim( (string) $nom ) ) );
	$out = array();
	foreach ( (array) $wpdb->get_results( 'SELECT id, nom FROM ' . gacct_clubs_table( 'clubs' ), ARRAY_A ) as $row ) {
		if ( (int) $row['id'] === (int) $exclude_id ) {
			continue;
		}
		$other = remove_accents( mb_strtolower( $row['nom'] ) );
		similar_text( $key, $other, $pct );
		if ( $pct >= 75 || ( strlen( $key ) > 4 && ( false !== strpos( $other, $key ) || false !== strpos( $key, $other ) ) ) ) {
			$out[] = $row;
		}
	}
	return $out;
}

/* ----------------------------------------------------------------- lots --- */

function gacct_clubs_get_lot( $lot_id ) {
	global $wpdb;
	$lot = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . gacct_clubs_table( 'club_lots' ) . ' WHERE id = %d', $lot_id ), ARRAY_A );
	return $lot ? gacct_clubs_hydrate_lot( $lot ) : null;
}

function gacct_clubs_get_lot_by_code( $code ) {
	global $wpdb;
	$code = gacct_clubs_clean_code( $code );
	if ( '' === $code ) {
		return null;
	}
	$lot = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . gacct_clubs_table( 'club_lots' ) . ' WHERE code = %s', $code ), ARRAY_A );
	return $lot ? gacct_clubs_hydrate_lot( $lot ) : null;
}

function gacct_clubs_clean_code( $code ) {
	return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
}

function gacct_clubs_hydrate_lot( array $lot ) {
	$jours        = json_decode( (string) $lot['jours'], true );
	$lot['jours'] = is_array( $jours ) ? $jours : array(); // [ 'Y-m-d' => heures ]
	ksort( $lot['jours'] );
	$lot['club']  = gacct_clubs_get_club( (int) $lot['club_id'] );
	$lot['nom']   = $lot['club'] ? $lot['club']['nom'] : '';
	return $lot;
}

/**
 * @param array $args { club_id, statut (string|array), user_id (responsable) }
 */
function gacct_clubs_lots( array $args = array() ) {
	global $wpdb;
	$where = array( '1=1' );
	$vals  = array();
	$lots  = gacct_clubs_table( 'club_lots' );

	if ( ! empty( $args['club_id'] ) ) {
		$where[] = 'l.club_id = %d';
		$vals[]  = absint( $args['club_id'] );
	}
	if ( ! empty( $args['statut'] ) ) {
		$st      = (array) $args['statut'];
		$where[] = 'l.statut IN (' . implode( ',', array_fill( 0, count( $st ), '%s' ) ) . ')';
		$vals    = array_merge( $vals, $st );
	}
	if ( ! empty( $args['user_id'] ) ) {
		$where[] = 'l.club_id IN (SELECT club_id FROM ' . gacct_clubs_table( 'club_responsables' ) . ' WHERE user_id = %d)';
		$vals[]  = absint( $args['user_id'] );
	}

	$sql  = "SELECT l.* FROM {$lots} l WHERE " . implode( ' AND ', $where ) . ' ORDER BY l.created DESC';
	$rows = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

	return array_map( 'gacct_clubs_hydrate_lot', (array) $rows );
}

function gacct_clubs_create_lot( array $data ) {
	global $wpdb;
	$now = current_time( 'mysql' );
	$wpdb->insert( gacct_clubs_table( 'club_lots' ), array(
		'club_id'       => absint( $data['club_id'] ),
		'code'          => 'TMP' . wp_generate_password( 10, false ),
		'statut'        => 'demande',
		'demandeur_id'  => absint( $data['demandeur_id'] ?? 0 ),
		'contact_nom'   => sanitize_text_field( $data['contact_nom'] ?? '' ),
		'contact_tel'   => sanitize_text_field( $data['contact_tel'] ?? '' ),
		'contact_email' => sanitize_email( $data['contact_email'] ?? '' ),
		'nb_ip'         => absint( $data['nb_ip'] ?? 0 ),
		'nb_rp'         => absint( $data['nb_rp'] ?? 0 ),
		'nb_secours'    => absint( $data['nb_secours'] ?? 0 ),
		'periode'       => sanitize_text_field( $data['periode'] ?? '' ),
		'remarques'     => sanitize_textarea_field( $data['remarques'] ?? '' ),
		'jours'         => '{}',
		'created'       => $now,
		'updated'       => $now,
	) );
	$lot_id = (int) $wpdb->insert_id;

	if ( $lot_id ) {
		// Code provisoire non diffusable tant que rien n'est planifié.
		$wpdb->update( gacct_clubs_table( 'club_lots' ), array( 'code' => 'D' . $lot_id ), array( 'id' => $lot_id ) );
		gacct_clubs_update_lot( $lot_id, array( 'heures_estimees' => gacct_clubs_estimate_hours( gacct_clubs_get_lot( $lot_id ) ) ) );
	}

	return $lot_id;
}

function gacct_clubs_update_lot( $lot_id, array $fields ) {
	global $wpdb;
	if ( isset( $fields['jours'] ) && is_array( $fields['jours'] ) ) {
		$fields['jours'] = wp_json_encode( $fields['jours'] );
	}
	$fields['updated'] = current_time( 'mysql' );
	return false !== $wpdb->update( gacct_clubs_table( 'club_lots' ), $fields, array( 'id' => absint( $lot_id ) ) );
}

/** Le code est-il diffusé (lot planifié) ? Les codes provisoires « D12 » ne le sont pas. */
function gacct_clubs_lot_has_code( array $lot ) {
	return in_array( $lot['statut'], array( 'planifie', 'facture', 'paye', 'expedie' ), true )
		&& ! preg_match( '/^(D\d+$|TMP)/', (string) $lot['code'] );
}

/**
 * Code club lisible : premier mot significatif du nom + année sur 2 chiffres.
 * « Aéro-club des Falaises » → FALAISES26. Unicité garantie par un suffixe.
 */
function gacct_clubs_generate_code( array $lot ) {
	global $wpdb;

	$stop  = array( 'CLUB', 'AERO', 'AEROCLUB', 'PARAPENTE', 'ECOLE', 'ASSOCIATION', 'DES', 'DU', 'DE', 'LA', 'LE', 'LES', 'ET', 'VOL', 'LIBRE', 'ASSO' );
	$words = preg_split( '/[^A-Z0-9]+/', strtoupper( remove_accents( (string) $lot['nom'] ) ) );
	$base  = '';
	foreach ( array_reverse( (array) $words ) as $w ) {
		if ( strlen( $w ) >= 3 && ! in_array( $w, $stop, true ) ) {
			$base = $w;
			break;
		}
	}
	if ( '' === $base ) {
		$base = 'CLUB';
	}
	$first_day = $lot['jours'] ? array_key_first( $lot['jours'] ) : current_time( 'Y-m-d' );
	$code      = substr( $base, 0, 10 ) . substr( (string) $first_day, 2, 2 );
	$try       = $code;
	$n         = 1;

	// Commandes suivantes de la même année : FALAISES26B, FALAISES26C… (un chiffre collé à l'année se lisait mal).
	while ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . gacct_clubs_table( 'club_lots' ) . ' WHERE code = %s AND id <> %d', $try, $lot['id'] ) ) > 0 ) {
		$try = $code . ( $n < 26 ? chr( 65 + $n ) : $n );
		$n++;
	}

	return $try;
}

/** Lien à transmettre aux membres. */
function gacct_clubs_member_url( array $lot ) {
	return add_query_arg( 'club', rawurlencode( $lot['code'] ), gacct_clubs_page_url( 'demande_page' ) );
}

/** Lien affiché en texte (sans https://, plus lisible dans un message). */
function gacct_clubs_member_url_text( array $lot ) {
	return preg_replace( '#^https?://(www\.)?#', '', gacct_clubs_member_url( $lot ) );
}

/* --------------------------------------------------------------- heures --- */

function gacct_clubs_product_hours( $product_id ) {
	return $product_id ? (float) str_replace( ',', '.', (string) get_post_meta( $product_id, 'duree_presta', true ) ) : 0.0;
}

/** Estimation à partir des nombres annoncés. */
function gacct_clubs_estimate_hours( $lot ) {
	if ( ! $lot ) {
		return 0;
	}
	return round(
		(int) $lot['nb_ip'] * gacct_clubs_product_hours( (int) gacct_clubs_setting( 'product_ip' ) )
		+ (int) $lot['nb_rp'] * gacct_clubs_product_hours( (int) gacct_clubs_setting( 'product_rp' ) )
		+ (int) $lot['nb_secours'] * gacct_clubs_product_hours( (int) gacct_clubs_setting( 'product_secours' ) ),
		2
	);
}

function gacct_clubs_hours_label( $h ) {
	$h   = (float) $h;
	$hh  = (int) floor( $h );
	$min = (int) round( ( $h - $hh ) * 60 );
	return $min ? sprintf( '%d h %02d', $hh, $min ) : sprintf( '%d h', $hh );
}

function gacct_clubs_reserved_hours( array $lot ) {
	return array_sum( array_map( 'floatval', $lot['jours'] ) );
}

/* ---------------------------------------------------------------- dates --- */

function gacct_clubs_first_day( array $lot ) {
	return $lot['jours'] ? (string) array_key_first( $lot['jours'] ) : '';
}

function gacct_clubs_last_day( array $lot ) {
	return $lot['jours'] ? (string) array_key_last( $lot['jours'] ) : '';
}

function gacct_clubs_date_label( $ymd, $format = 'j F Y' ) {
	if ( ! $ymd ) {
		return '';
	}
	$d = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( (string) $ymd, 0, 10 ), wp_timezone() );
	if ( ! $d ) {
		return '';
	}
	// « M » sortait en anglais (« Sep », « Oct ») : abréviations françaises usuelles, échappées pour le format.
	if ( false !== strpos( $format, 'M' ) ) {
		$abbr   = apply_filters( 'gacct_clubs_month_abbr', array( 1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' ) );
		$chars  = preg_split( '//u', $abbr[ (int) $d->format( 'n' ) ], -1, PREG_SPLIT_NO_EMPTY );
		$format = str_replace( 'M', '\\' . implode( '\\', $chars ), $format );
	}
	return wp_date( $format, $d->getTimestamp() );
}

/** « 12 novembre 2026 » ou « du 12 au 14 novembre 2026 ». */
function gacct_clubs_period_label( array $lot ) {
	$a = gacct_clubs_first_day( $lot );
	$b = gacct_clubs_last_day( $lot );
	if ( ! $a ) {
		return '';
	}
	if ( $a === $b ) {
		return 'le ' . gacct_clubs_date_label( $a );
	}
	if ( substr( $a, 0, 7 ) === substr( $b, 0, 7 ) ) {
		return sprintf( 'du %s au %s', gacct_clubs_date_label( $a, 'j' ), gacct_clubs_date_label( $b ) );
	}
	return sprintf( 'du %s au %s', gacct_clubs_date_label( $a, 'j F' ), gacct_clubs_date_label( $b ) );
}

function gacct_clubs_shift_date( $ymd, $days ) {
	$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );
	return $d ? $d->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' )->format( 'Y-m-d' ) : '';
}

/** Inscriptions ouvertes maintenant ? */
function gacct_clubs_registrations_open( array $lot ) {
	if ( 'planifie' !== $lot['statut'] || empty( $lot['inscriptions_ouvertes'] ) || ! $lot['jours'] ) {
		return false;
	}
	$limit = (string) $lot['limite_inscription'];
	return ! $limit || current_time( 'Y-m-d' ) <= $limit;
}

/* ------------------------------------------------------- dossiers pilotes --- */

/**
 * Dossiers rattachés au lot, avec commande et client.
 *
 * @return array[] { revision (array), order (WC_Order|null), pilote, materiel, prestations, etat, hors_lot }
 */
function gacct_clubs_lot_members( $lot_id ) {
	global $wpdb;
	$rev  = $wpdb->prefix . 'jet_cct_' . JWCCT_CCT_REVISION;
	$rows = (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$rev} WHERE club_lot_id = %d AND cct_status = 'publish' ORDER BY _ID ASC",
		$lot_id
	), ARRAY_A );

	$out = array();
	foreach ( $rows as $r ) {
		$order = ! empty( $r['order_id'] ) ? wc_get_order( (int) $r['order_id'] ) : null;
		if ( $order && $order->has_status( array( 'cancelled', 'refunded', 'trash', 'failed' ) ) ) {
			continue;
		}
		$pilote = $order ? trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) : '';
		if ( '' === $pilote && ! empty( $r['client_id'] ) ) {
			$u      = get_userdata( (int) $r['client_id'] );
			$pilote = $u ? $u->display_name : '';
		}
		$out[] = array(
			'revision'    => $r,
			'order'       => $order,
			'pilote'      => $pilote,
			'prenom'      => $order ? $order->get_billing_first_name() : $pilote,
			'materiel'    => gacct_clubs_member_material( $r ),
			'prestations' => $order ? gacct_clubs_order_services( $order ) : array(),
			'etat'        => (int) $r['etat_de_la_commande'],
			'hors_lot'    => '1' === (string) ( $r['en_attente'] ?? '' ),
			'is_voile'    => $order ? gacct_clubs_order_counts_as_voile( $order ) : false,
		);
	}
	return $out;
}

function gacct_clubs_member_material( array $r ) {
	$voile = trim( ( $r['marque'] ?? '' ) . ' ' . ( $r['modele'] ?? '' ) );
	$parts = array();
	if ( '' !== $voile ) {
		$parts[] = $voile . ( ! empty( $r['taille'] ) ? ' ' . $r['taille'] : '' );
	}
	$secours = trim( ( $r['secours_marque'] ?? '' ) . ' ' . ( $r['secours_modele'] ?? '' ) );
	if ( '' !== $secours ) {
		$parts[] = 'secours ' . $secours;
	}
	return implode( ' + ', $parts );
}

/** Libellés des prestations d'une commande (hors frais de port). */
function gacct_clubs_order_services( WC_Order $order ) {
	$retour = (int) gacct_clubs_setting( 'product_retour' );
	$out    = array();
	foreach ( $order->get_items() as $item ) {
		if ( (int) $item->get_product_id() === $retour || has_term( 'frais-de-port', 'product_cat', $item->get_product_id() ) ) {
			continue;
		}
		$out[] = $item->get_name() . ( $item->get_quantity() > 1 ? ' × ' . $item->get_quantity() : '' );
	}
	return $out;
}

function gacct_clubs_product_in_cats( $product_id, array $cats ) {
	return $cats && has_term( $cats, 'product_cat', $product_id );
}

/** La commande compte-t-elle pour une voile (palier de remise) ? */
function gacct_clubs_order_counts_as_voile( WC_Order $order ) {
	$cats = (array) gacct_clubs_setting( 'voile_cats' );
	foreach ( $order->get_items() as $item ) {
		if ( gacct_clubs_product_in_cats( $item->get_product_id(), $cats ) ) {
			return true;
		}
	}
	return false;
}

/** Compteurs : inscrits par type (IP / RP / secours), voiles, heures. */
function gacct_clubs_lot_counts( array $lot, array $members = null ) {
	$members = null === $members ? gacct_clubs_lot_members( $lot['id'] ) : $members;
	$ip      = (int) gacct_clubs_setting( 'product_ip' );
	$rp      = (int) gacct_clubs_setting( 'product_rp' );
	$c       = array( 'ip' => 0, 'rp' => 0, 'secours' => 0, 'autres' => 0, 'voiles' => 0, 'dossiers' => count( $members ), 'recues' => 0, 'heures' => 0.0, 'hors_lot' => 0 );

	foreach ( $members as $m ) {
		if ( $m['is_voile'] ) {
			$c['voiles']++;
		}
		if ( $m['etat'] >= 2 && $m['etat'] <= 8 ) {
			$c['recues']++;
		}
		if ( $m['hors_lot'] ) {
			$c['hors_lot']++;
		}
		if ( ! $m['order'] ) {
			continue;
		}
		foreach ( $m['order']->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			$c['heures'] += gacct_clubs_product_hours( $pid ) * max( 1, (int) $item->get_quantity() );
			if ( $pid === $ip ) {
				$c['ip']++;
			} elseif ( $pid === $rp ) {
				$c['rp']++;
			} elseif ( has_term( 'pliages-secours', 'product_cat', $pid ) && $pid !== (int) gacct_clubs_setting( 'product_retour' ) && ! in_array( $pid, (array) gacct_clubs_setting( 'remise_exclude' ), true ) ) {
				$c['secours']++;
			}
		}
	}
	$c['heures'] = round( $c['heures'], 2 );
	return $c;
}

/** Lot d'une commande pilote (0 sinon). */
function gacct_clubs_order_lot_id( $order ) {
	return $order instanceof WC_Order ? (int) $order->get_meta( GACCT_CLUBS_META_LOT ) : 0;
}

/** Lot dont la commande est la facture (0 sinon). */
function gacct_clubs_invoice_lot_id( $order ) {
	return $order instanceof WC_Order ? (int) $order->get_meta( GACCT_CLUBS_META_INVOICE ) : 0;
}
