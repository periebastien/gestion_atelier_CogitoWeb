<?php
/**
 * Capacité : réserve fixe du club, dossiers des pilotes « en plus ».
 *
 * Une occupation de réserve par jour réservé (order_id 0, revision_id 0,
 * club_lot_id = lot) compte dans la capacité. Les occupations des pilotes du
 * club portent hors_capacite = '1' : elles s'affichent au planning mais ne
 * consomment rien, la réserve compte à leur place (décision du 30/09/2026).
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

function gacct_clubs_occ_table() {
	global $wpdb;
	return $wpdb->prefix . 'jet_cct_' . ( defined( 'JWCCT_CCT_OCCUPATION' ) ? JWCCT_CCT_OCCUPATION : 'occupation_atelier' );
}

add_filter( 'gacct_occupation_counted_sql', static function ( $sql, $prefix ) {
	$own = "COALESCE({$prefix}hors_capacite, '') <> '1'";
	return '' !== trim( (string) $sql ) ? '(' . $sql . ') AND ' . $own : $own;
}, 10, 2 );

add_filter( 'gacct_occupation_counted_hours', static function ( $hours, $occupation ) {
	return ( isset( $occupation['hors_capacite'] ) && '1' === (string) $occupation['hors_capacite'] ) ? 0.0 : $hours;
}, 10, 2 );

/** Timestamp de stockage d'un jour (même convention que calendrier_dispo). */
function gacct_clubs_day_ts( $ymd ) {
	$row = function_exists( 'gacct_op_day_capacity_row' ) ? gacct_op_day_capacity_row( $ymd ) : null;
	if ( $row ) {
		return (int) $row['day_ts'];
	}
	$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );
	return $d ? $d->getTimestamp() : 0;
}

/** « 7.5 » → « 07:30 » (format de duree_totale_commande). */
function gacct_clubs_hours_to_hhmm( $hours ) {
	$min = (int) round( (float) $hours * 60 );
	return sprintf( '%02d:%02d', intdiv( $min, 60 ), $min % 60 );
}

/**
 * Heures encore libres d'un jour, en ignorant la réserve du lot lui-même.
 *
 * @return float|null null si le jour n'est pas ouvert.
 */
function gacct_clubs_day_available( $ymd, $lot_id = 0 ) {
	global $wpdb;
	$cap = function_exists( 'gacct_op_day_capacity_row' ) ? gacct_op_day_capacity_row( $ymd ) : null;
	if ( ! $cap ) {
		return null;
	}
	$occ  = gacct_clubs_occ_table();
	$used = (float) $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(SUM(TIME_TO_SEC(duree_totale_commande) / 3600), 0) FROM {$occ}
		 WHERE cct_status = 'publish' AND date_reservee = %d AND COALESCE(hors_capacite, '') <> '1'
		   AND NOT ( club_lot_id = %d AND order_id = 0 AND %d > 0 )",
		(int) $cap['day_ts'],
		$lot_id,
		$lot_id
	) );
	return round( (float) $cap['capacity_hours'] - $used, 2 );
}

/**
 * Pose (ou remplace) les réserves d'un lot.
 *
 * @param array $jours [ 'Y-m-d' => heures ]
 * @return true|WP_Error
 */
function gacct_clubs_set_reserve( array $lot, array $jours ) {
	global $wpdb;

	$clean = array();
	foreach ( $jours as $ymd => $h ) {
		$ymd = substr( sanitize_text_field( (string) $ymd ), 0, 10 );
		$h   = round( (float) str_replace( ',', '.', (string) $h ), 2 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) || $h <= 0 ) {
			continue;
		}
		$avail = gacct_clubs_day_available( $ymd, (int) $lot['id'] );
		if ( null === $avail ) {
			return new WP_Error( 'closed', sprintf( 'Le %s n’est pas un jour ouvert au calendrier de l’atelier.', gacct_clubs_date_label( $ymd ) ) );
		}
		if ( $h > $avail + 0.001 ) {
			return new WP_Error( 'full', sprintf( 'Le %1$s n’a plus que %2$s de libre (vous demandez %3$s).', gacct_clubs_date_label( $ymd ), gacct_clubs_hours_label( max( 0, $avail ) ), gacct_clubs_hours_label( $h ) ) );
		}
		$clean[ $ymd ] = $h;
	}

	if ( ! $clean ) {
		return new WP_Error( 'empty', 'Choisissez au moins un jour avec un nombre d’heures.' );
	}

	ksort( $clean );
	gacct_clubs_delete_reserve( (int) $lot['id'] );

	$occ = gacct_clubs_occ_table();
	$now = current_time( 'mysql' );
	foreach ( $clean as $ymd => $h ) {
		$wpdb->insert( $occ, array(
			'cct_status'            => 'publish',
			'date_reservee'         => gacct_clubs_day_ts( $ymd ),
			'cct_author_id'         => get_current_user_id(),
			'cct_created'           => $now,
			'cct_modified'          => $now,
			'duree_totale_commande' => gacct_clubs_hours_to_hhmm( $h ),
			'revision_id'           => 0,
			'order_id'              => 0,
			'club_lot_id'           => (int) $lot['id'],
			'hors_capacite'         => '',
		) );
	}

	gacct_clubs_update_lot( (int) $lot['id'], array( 'jours' => $clean ) );

	// Les dossiers déjà inscrits suivent le nouveau premier jour s'ils étaient avant.
	gacct_clubs_realign_members( gacct_clubs_get_lot( (int) $lot['id'] ) );

	return true;
}

function gacct_clubs_delete_reserve( $lot_id ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . gacct_clubs_occ_table() . ' WHERE club_lot_id = %d AND order_id = 0 AND revision_id = 0', $lot_id ) );
}

/**
 * Occupations des pilotes hors des jours du lot : ramenées au premier jour.
 */
function gacct_clubs_realign_members( $lot ) {
	global $wpdb;
	if ( ! $lot || ! $lot['jours'] ) {
		return;
	}
	$days = array();
	foreach ( array_keys( $lot['jours'] ) as $ymd ) {
		$days[] = gacct_clubs_day_ts( $ymd );
	}
	$first = $days[0];
	$occ   = gacct_clubs_occ_table();
	$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT _ID, date_reservee FROM {$occ} WHERE club_lot_id = %d AND hors_capacite = '1'", $lot['id'] ), ARRAY_A );
	foreach ( $rows as $r ) {
		if ( ! in_array( (int) $r['date_reservee'], $days, true ) ) {
			$wpdb->update( $occ, array( 'date_reservee' => $first ), array( '_ID' => (int) $r['_ID'] ) );
		}
	}
}

/* -------------------------------------------------- affichage au planning --- */

add_filter( 'gacct_op_planning_event', 'gacct_clubs_planning_event', 10, 2 );

function gacct_clubs_planning_event( $event, $row ) {
	global $wpdb;
	static $occ_cache = array();

	$occ_id = (int) $row['occupation_id'];
	if ( ! isset( $occ_cache[ $occ_id ] ) ) {
		$occ_cache[ $occ_id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT club_lot_id, hors_capacite FROM ' . gacct_clubs_occ_table() . ' WHERE _ID = %d', $occ_id ), ARRAY_A );
	}
	$o = $occ_cache[ $occ_id ];

	if ( ! $o || ! (int) $o['club_lot_id'] ) {
		return $event;
	}

	$lot = gacct_clubs_get_lot( (int) $o['club_lot_id'] );
	if ( ! $lot ) {
		return $event;
	}

	// Réserve du lot : bloc non déplaçable qui ouvre la fiche du lot.
	if ( empty( $row['order_id'] ) && empty( $row['rev_id'] ) ) {
		$event['title']                       = sprintf( 'Réserve club · %s (%s)', $lot['nom'], gacct_clubs_hours_label( (float) gacct_clubs_hhmm_to_hours( $row['duree_totale_commande'] ) ) );
		$event['editable']                    = false;
		$event['classNames']                  = array( 'gacct-op-occ', 'gacct-op-occ-club-reserve' );
		$event['extendedProps']['ref']        = 'Réserve ' . $lot['code'];
		$event['extendedProps']['client']     = $lot['nom'];
		$event['extendedProps']['materiel']   = sprintf( '%d voiles et %d secours annoncés', gacct_clubs_lot_voiles( $lot ), (int) $lot['nb_secours'] );
		$event['extendedProps']['etat_label'] = 'Réserve de commande groupée';
		$event['extendedProps']['fiche_url']  = gacct_clubs_console_url( (int) $lot['id'] );
		return $event;
	}

	// Dossier d'un pilote du club.
	$event['title']      .= ' · ' . $lot['nom'];
	$event['classNames'][] = 'gacct-op-occ-club';
	return $event;
}

function gacct_clubs_hhmm_to_hours( $hhmm ) {
	$p = explode( ':', (string) $hhmm );
	return (int) ( $p[0] ?? 0 ) + (int) ( $p[1] ?? 0 ) / 60;
}

add_action( 'admin_head', static function () {
	echo '<style>.fc .gacct-op-occ-club-reserve{--fc-event-bg-color:#fff6de;--fc-event-border-color:#ffbd20;--fc-event-text-color:#7a5200;font-weight:600}.fc .gacct-op-occ-club .fc-event-main{font-style:italic}</style>';
} );
