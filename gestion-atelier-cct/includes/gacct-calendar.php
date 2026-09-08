<?php
/**
 * Calendrier d'ouverture de l'atelier : convention de dates, jours fériés,
 * fermetures exceptionnelles, ouverture et fermeture de jours (08/09/2026).
 *
 * CONVENTION DE DATE (une seule, partagée par tout le plugin) :
 * un jour du calendrier (`calendrier_dispo.date_jour`, `occupation_atelier.date_reservee`)
 * est stocké comme le timestamp de MINUIT UTC de ce jour. C'est ce qu'écrivent
 * le formulaire client (Date.UTC dans demande-v2.js), JetEngine (strtotime en
 * UTC, affichage par date()) et le cycle de vie (gacct-lifecycle.php, « +0000 »).
 * Le générateur d'ouvertures écrivait minuit heure de Paris jusqu'au 08/09/2026 :
 * les jours apparaissaient la veille dans JetEngine et les occupations ne se
 * retranchaient plus des disponibilités (timestamps différents pour le même jour).
 * Toute écriture passe désormais par gacct_cal_day_ts(), toute lecture par
 * gacct_cal_day_ymd() qui tolère les deux formes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_CAL_HOLIDAYS_OPT', 'gacct_holidays_enabled' );
define( 'GACCT_CAL_CLOSED_OPT', 'gacct_closed_dates' );

/* =============================================================================
 *  CONVENTION DE DATE
 * ============================================================================= */

/**
 * Timestamp canonique d'un jour (minuit UTC). 0 si la date est invalide.
 *
 * @param string $ymd 'AAAA-MM-JJ'
 * @return int
 */
function gacct_cal_day_ts( $ymd ) {
	$day = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, new DateTimeZone( 'UTC' ) );

	return $day ? $day->getTimestamp() : 0;
}

/**
 * Jour 'AAAA-MM-JJ' d'un timestamp de calendrier, quelle que soit sa forme
 * (minuit UTC canonique, ou minuit Paris hérité : 22 h ou 23 h UTC la veille).
 * On ramène le timestamp à midi du jour visé avant de lire la date UTC.
 *
 * @param int $ts
 * @return string
 */
function gacct_cal_day_ymd( $ts ) {
	$ts = (int) $ts;

	return gmdate( 'Y-m-d', $ts + 6 * HOUR_IN_SECONDS );
}

/**
 * Ramène un timestamp de calendrier à sa forme canonique.
 */
function gacct_cal_normalize_ts( $ts ) {
	return gacct_cal_day_ts( gacct_cal_day_ymd( $ts ) );
}

/**
 * Nom complet de la table calendrier_dispo (même option que la Configuration).
 */
function gacct_cal_table() {
	if ( function_exists( 'gacct_demande_table_name' ) ) {
		return gacct_demande_table_name( 'calendrier_dispo' );
	}

	global $wpdb;

	return $wpdb->prefix . 'jet_cct_calendrier_dispo';
}

function gacct_cal_occupation_table() {
	if ( function_exists( 'gacct_demande_table_name' ) ) {
		return gacct_demande_table_name( 'occupation_atelier' );
	}

	global $wpdb;

	return $wpdb->prefix . 'jet_cct_occupation_atelier';
}

/* =============================================================================
 *  JOURS FÉRIÉS ET FERMETURES
 * ============================================================================= */

/**
 * Jours fériés officiels français d'une année ('AAAA-MM-JJ' => libellé).
 * Les onze fêtes légales (code du travail, art. L3133-1). Filtrable, par
 * exemple pour ajouter le Vendredi saint et la Saint-Étienne en Alsace-Moselle.
 *
 * @param int $year
 * @return array<string,string>
 */
function gacct_cal_holidays( $year ) {
	$year = (int) $year;

	// Pâques (algorithme de Meeus/Jones/Butcher, calendrier grégorien),
	// pour ne pas dépendre de l'extension calendar de PHP.
	$a = $year % 19;
	$b = intdiv( $year, 100 );
	$c = $year % 100;
	$d = intdiv( $b, 4 );
	$e = $b % 4;
	$f = intdiv( $b + 8, 25 );
	$g = intdiv( $b - $f + 1, 3 );
	$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
	$i = intdiv( $c, 4 );
	$k = $c % 4;
	$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
	$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
	$month = intdiv( $h + $l - 7 * $m + 114, 31 );
	$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;

	$easter = new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $year, $month, $day ), new DateTimeZone( 'UTC' ) );

	$holidays = array(
		sprintf( '%04d-01-01', $year )               => __( 'Jour de l\'an', 'gestion-atelier-cct' ),
		$easter->modify( '+1 day' )->format( 'Y-m-d' )  => __( 'Lundi de Pâques', 'gestion-atelier-cct' ),
		sprintf( '%04d-05-01', $year )               => __( 'Fête du Travail', 'gestion-atelier-cct' ),
		sprintf( '%04d-05-08', $year )               => __( 'Victoire 1945', 'gestion-atelier-cct' ),
		$easter->modify( '+39 days' )->format( 'Y-m-d' ) => __( 'Ascension', 'gestion-atelier-cct' ),
		$easter->modify( '+50 days' )->format( 'Y-m-d' ) => __( 'Lundi de Pentecôte', 'gestion-atelier-cct' ),
		sprintf( '%04d-07-14', $year )               => __( 'Fête nationale', 'gestion-atelier-cct' ),
		sprintf( '%04d-08-15', $year )               => __( 'Assomption', 'gestion-atelier-cct' ),
		sprintf( '%04d-11-01', $year )               => __( 'Toussaint', 'gestion-atelier-cct' ),
		sprintf( '%04d-11-11', $year )               => __( 'Armistice 1918', 'gestion-atelier-cct' ),
		sprintf( '%04d-12-25', $year )               => __( 'Noël', 'gestion-atelier-cct' ),
	);

	ksort( $holidays );

	return apply_filters( 'gacct_cal_holidays', $holidays, $year );
}

/**
 * Les jours fériés bloquent-ils les ouvertures ? (réglage, oui par défaut)
 */
function gacct_cal_holidays_enabled() {
	return (bool) get_option( GACCT_CAL_HOLIDAYS_OPT, 1 );
}

/**
 * Fermetures exceptionnelles saisies en configuration ('AAAA-MM-JJ' => libellé).
 *
 * @return array<string,string>
 */
function gacct_cal_closed_dates() {
	$raw = get_option( GACCT_CAL_CLOSED_OPT, array() );

	return is_array( $raw ) ? $raw : array();
}

/**
 * Analyse la saisie « une fermeture par ligne : AAAA-MM-JJ [libellé] ».
 * Une plage « AAAA-MM-JJ AAAA-MM-JJ [libellé] » ferme tous les jours entre les deux.
 *
 * @param string $text
 * @return array<string,string>
 */
function gacct_cal_parse_closed_dates( $text ) {
	$closed = array();

	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$line = trim( $line );

		if ( '' === $line ) {
			continue;
		}

		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?:\s+(\d{4}-\d{2}-\d{2}))?\s*(.*)$/u', $line, $m ) ) {
			continue;
		}

		$from  = gacct_cal_day_ts( $m[1] );
		$to    = ! empty( $m[2] ) ? gacct_cal_day_ts( $m[2] ) : $from;
		$label = sanitize_text_field( $m[3] );

		if ( ! $from || ! $to ) {
			continue;
		}

		if ( $to < $from ) {
			list( $from, $to ) = array( $to, $from );
		}

		// Garde-fou : une plage d'un an maximum.
		$to = min( $to, $from + 366 * DAY_IN_SECONDS );

		for ( $ts = $from; $ts <= $to; $ts += DAY_IN_SECONDS ) {
			$closed[ gmdate( 'Y-m-d', $ts ) ] = $label;
		}
	}

	ksort( $closed );

	return $closed;
}

/**
 * Représentation texte des fermetures (pour le textarea), plages regroupées.
 */
function gacct_cal_closed_dates_text( array $closed ) {
	ksort( $closed );

	$lines = array();
	$run   = null;

	foreach ( $closed as $ymd => $label ) {
		$ts = gacct_cal_day_ts( $ymd );

		if ( $run && $run['label'] === $label && $ts === $run['to'] + DAY_IN_SECONDS ) {
			$run['to'] = $ts;
			continue;
		}

		if ( $run ) {
			$lines[] = gacct_cal_closed_run_line( $run );
		}

		$run = array( 'from' => $ts, 'to' => $ts, 'label' => $label );
	}

	if ( $run ) {
		$lines[] = gacct_cal_closed_run_line( $run );
	}

	return implode( "\n", $lines );
}

function gacct_cal_closed_run_line( array $run ) {
	$line = gmdate( 'Y-m-d', $run['from'] );

	if ( $run['to'] !== $run['from'] ) {
		$line .= ' ' . gmdate( 'Y-m-d', $run['to'] );
	}

	if ( '' !== $run['label'] ) {
		$line .= ' ' . $run['label'];
	}

	return $line;
}

/**
 * Jours travaillés (1 = lundi … 7 = dimanche), même option que la Configuration.
 *
 * @return int[]
 */
function gacct_cal_working_days() {
	$days = get_option( 'gacct_working_days', array( 1, 2, 3, 4, 5 ) );
	$days = is_array( $days ) ? array_map( 'absint', $days ) : array();
	$days = array_values( array_unique( array_filter( $days, function ( $d ) {
		return $d >= 1 && $d <= 7;
	} ) ) );

	sort( $days );

	return ! empty( $days ) ? $days : array( 1, 2, 3, 4, 5 );
}

/**
 * Pourquoi un jour est fermé ('' s'il est ouvrable).
 * Motifs : 'weekend' (jour non travaillé), 'holiday' (férié), 'closed' (fermeture saisie).
 *
 * @param string $ymd
 * @return array{reason:string,label:string}
 */
function gacct_cal_closure( $ymd ) {
	$ts = gacct_cal_day_ts( $ymd );

	if ( ! $ts ) {
		return array( 'reason' => 'invalid', 'label' => '' );
	}

	$closed = gacct_cal_closed_dates();
	if ( isset( $closed[ $ymd ] ) ) {
		return array( 'reason' => 'closed', 'label' => '' !== $closed[ $ymd ] ? $closed[ $ymd ] : __( 'Fermeture exceptionnelle', 'gestion-atelier-cct' ) );
	}

	if ( gacct_cal_holidays_enabled() ) {
		$holidays = gacct_cal_holidays( (int) substr( $ymd, 0, 4 ) );
		if ( isset( $holidays[ $ymd ] ) ) {
			return array( 'reason' => 'holiday', 'label' => $holidays[ $ymd ] );
		}
	}

	if ( ! in_array( (int) gmdate( 'N', $ts ), gacct_cal_working_days(), true ) ) {
		return array( 'reason' => 'weekend', 'label' => __( 'Jour non travaillé', 'gestion-atelier-cct' ) );
	}

	return array( 'reason' => '', 'label' => '' );
}

/**
 * Jours fermés d'une plage (pour le planning) : fériés et fermetures saisies.
 * Les jours non travaillés ne sont pas listés (ils sont simplement vides).
 *
 * @return array<string,array{reason:string,label:string}>
 */
function gacct_cal_closures_between( $start_ymd, $end_ymd ) {
	$from = gacct_cal_day_ts( $start_ymd );
	$to   = gacct_cal_day_ts( $end_ymd );
	$out  = array();

	if ( ! $from || ! $to || $to < $from ) {
		return $out;
	}

	$to = min( $to, $from + 400 * DAY_IN_SECONDS );

	for ( $ts = $from; $ts <= $to; $ts += DAY_IN_SECONDS ) {
		$ymd = gmdate( 'Y-m-d', $ts );
		$c   = gacct_cal_closure( $ymd );

		if ( in_array( $c['reason'], array( 'holiday', 'closed' ), true ) ) {
			$out[ $ymd ] = $c;
		}
	}

	return $out;
}

/* =============================================================================
 *  OUVRIR / FERMER DES JOURS
 * ============================================================================= */

/**
 * Le champ heures accepte-t-il les décimales ? (bigint chez JetEngine par défaut)
 */
function gacct_cal_hours_column_is_decimal() {
	global $wpdb;

	$table = gacct_cal_table();
	$type  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s", $table, 'heures_totales_dispo' ) );

	return (bool) preg_match( '/decimal|float|double|text|varchar|char/i', $type );
}

/**
 * Lignes calendrier_dispo publiées d'une plage, indexées par jour 'AAAA-MM-JJ'
 * (les doublons du même jour sont tous listés).
 *
 * @return array<string,array[]>
 */
function gacct_cal_rows_between( $start_ymd, $end_ymd ) {
	global $wpdb;

	$from = gacct_cal_day_ts( $start_ymd ) - DAY_IN_SECONDS; // tolère la forme Paris héritée
	$to   = gacct_cal_day_ts( $end_ymd ) + DAY_IN_SECONDS;
	$rows = (array) $wpdb->get_results( $wpdb->prepare(
		'SELECT _ID, date_jour, heures_totales_dispo FROM ' . gacct_cal_table() . " WHERE cct_status = 'publish' AND date_jour >= %d AND date_jour < %d ORDER BY date_jour, _ID",
		$from,
		$to
	), ARRAY_A );

	$by_day = array();

	foreach ( $rows as $row ) {
		$ymd = gacct_cal_day_ymd( (int) $row['date_jour'] );

		if ( $ymd < $start_ymd || $ymd > $end_ymd ) {
			continue;
		}

		$by_day[ $ymd ][] = $row;
	}

	return $by_day;
}

/**
 * Heures occupées par jour sur une plage ('AAAA-MM-JJ' => float).
 */
function gacct_cal_occupied_between( $start_ymd, $end_ymd ) {
	global $wpdb;

	$from = gacct_cal_day_ts( $start_ymd ) - DAY_IN_SECONDS;
	$to   = gacct_cal_day_ts( $end_ymd ) + DAY_IN_SECONDS;
	$rows = (array) $wpdb->get_results( $wpdb->prepare(
		'SELECT date_reservee, COALESCE(SUM(TIME_TO_SEC(duree_totale_commande) / 3600), 0) AS h FROM ' . gacct_cal_occupation_table() . " WHERE cct_status = 'publish' AND date_reservee >= %d AND date_reservee < %d GROUP BY date_reservee",
		$from,
		$to
	), ARRAY_A );

	$out = array();

	foreach ( $rows as $row ) {
		$ymd = gacct_cal_day_ymd( (int) $row['date_reservee'] );
		$out[ $ymd ] = ( $out[ $ymd ] ?? 0 ) + (float) $row['h'];
	}

	return $out;
}

/**
 * Ouvre (ou modifie) des jours : une ligne calendrier_dispo par jour, à
 * `$hours` heures. Un jour déjà ouvert est mis à jour, jamais dupliqué.
 *
 * @param string $start_ymd
 * @param string $end_ymd
 * @param float  $hours          Heures par jour.
 * @param array  $args           { respect_closures: bool (défaut true) : saute les jours
 *                                 non travaillés, fériés et fermetures saisies. }
 * @return array|WP_Error { inserted, updated, skipped: array<string,string> jour => motif, days: int }
 */
function gacct_cal_open_days( $start_ymd, $end_ymd, $hours, array $args = array() ) {
	global $wpdb;

	$respect = ! isset( $args['respect_closures'] ) || $args['respect_closures'];
	$hours   = round( (float) str_replace( ',', '.', (string) $hours ), 2 );
	$from    = gacct_cal_day_ts( $start_ymd );
	$to      = gacct_cal_day_ts( $end_ymd );

	if ( ! $from || ! $to ) {
		return new WP_Error( 'gacct_bad_date', __( 'Les dates doivent etre valides.', 'gestion-atelier-cct' ) );
	}

	if ( $to < $from ) {
		return new WP_Error( 'gacct_date_order', __( 'La date de fin doit etre posterieure ou egale a la date de debut.', 'gestion-atelier-cct' ) );
	}

	if ( $to - $from > 400 * DAY_IN_SECONDS ) {
		return new WP_Error( 'gacct_range_too_long', __( 'La plage ne peut pas depasser un an.', 'gestion-atelier-cct' ) );
	}

	if ( $hours <= 0 || $hours > 24 ) {
		return new WP_Error( 'gacct_bad_hours', __( 'Le nombre d heures doit etre superieur a 0 et inferieur ou egal a 24.', 'gestion-atelier-cct' ) );
	}

	if ( abs( $hours - round( $hours ) ) > 0.00001 && ! gacct_cal_hours_column_is_decimal() ) {
		return new WP_Error( 'gacct_decimal_column_required', __( 'Le champ heures_totales_dispo du CCT calendrier_dispo doit etre en DECIMAL, FLOAT, DOUBLE ou TEXT pour accepter des heures decimales. Actuellement, la base arrondirait la valeur.', 'gestion-atelier-cct' ) );
	}

	$table    = gacct_cal_table();
	$existing = gacct_cal_rows_between( $start_ymd, $end_ymd );
	$now      = current_time( 'mysql' );
	$inserted = 0;
	$updated  = 0;
	$skipped  = array();

	for ( $ts = $from; $ts <= $to; $ts += DAY_IN_SECONDS ) {
		$ymd = gmdate( 'Y-m-d', $ts );

		if ( $respect ) {
			$closure = gacct_cal_closure( $ymd );
			if ( '' !== $closure['reason'] ) {
				$skipped[ $ymd ] = $closure['label'];
				continue;
			}
		}

		if ( ! empty( $existing[ $ymd ] ) ) {
			// Première ligne conservée et mise à jour (date normalisée), doublons éventuels retirés.
			$keep = array_shift( $existing[ $ymd ] );
			$wpdb->update(
				$table,
				array( 'date_jour' => $ts, 'heures_totales_dispo' => $hours, 'cct_modified' => $now ),
				array( '_ID' => (int) $keep['_ID'] )
			);
			foreach ( $existing[ $ymd ] as $dup ) {
				$wpdb->delete( $table, array( '_ID' => (int) $dup['_ID'] ) );
			}
			$updated++;
			continue;
		}

		$data = array(
			'cct_status'           => 'publish',
			'cct_author_id'        => get_current_user_id(),
			'cct_created'          => $now,
			'cct_modified'         => $now,
			'date_jour'            => $ts,
			'heures_totales_dispo' => $hours,
		);

		$data = apply_filters( 'gacct_generator_availability_insert_data', $data, new DateTimeImmutable( '@' . $ts ), $hours );

		if ( false !== $wpdb->insert( $table, $data ) ) {
			$inserted++;
		} else {
			$skipped[ $ymd ] = __( 'Echec d ecriture', 'gestion-atelier-cct' );
		}
	}

	return array(
		'inserted' => $inserted,
		'updated'  => $updated,
		'skipped'  => $skipped,
		'hours'    => $hours,
	);
}

/**
 * Ferme des jours : retire les lignes calendrier_dispo de la plage. Un jour
 * qui porte une occupation publiée n'est PAS fermé (il faut d'abord
 * replanifier le dossier) et est signalé dans `kept`.
 *
 * @return array|WP_Error { closed: int, kept: array<string,float> jour => heures occupées }
 */
function gacct_cal_close_days( $start_ymd, $end_ymd ) {
	global $wpdb;

	$from = gacct_cal_day_ts( $start_ymd );
	$to   = gacct_cal_day_ts( $end_ymd );

	if ( ! $from || ! $to || $to < $from ) {
		return new WP_Error( 'gacct_bad_date', __( 'Les dates doivent etre valides.', 'gestion-atelier-cct' ) );
	}

	if ( $to - $from > 400 * DAY_IN_SECONDS ) {
		return new WP_Error( 'gacct_range_too_long', __( 'La plage ne peut pas depasser un an.', 'gestion-atelier-cct' ) );
	}

	$table    = gacct_cal_table();
	$rows     = gacct_cal_rows_between( $start_ymd, $end_ymd );
	$occupied = gacct_cal_occupied_between( $start_ymd, $end_ymd );
	$closed   = 0;
	$kept     = array();

	foreach ( $rows as $ymd => $day_rows ) {
		if ( ! empty( $occupied[ $ymd ] ) && $occupied[ $ymd ] > 0.001 ) {
			$kept[ $ymd ] = $occupied[ $ymd ];
			continue;
		}

		foreach ( $day_rows as $row ) {
			$wpdb->delete( $table, array( '_ID' => (int) $row['_ID'] ) );
		}

		$closed++;
	}

	return array( 'closed' => $closed, 'kept' => $kept );
}

/**
 * Ramène toutes les lignes du calendrier et des occupations à la forme
 * canonique (minuit UTC). Les doublons du même jour créés par l'ancienne
 * convention sont fusionnés (heures max conservées). Retourne le nombre de
 * lignes touchées. Appelée une fois au chargement (option de version).
 */
function gacct_cal_normalize_tables() {
	global $wpdb;

	$touched = 0;

	foreach ( array( gacct_cal_table() => 'date_jour', gacct_cal_occupation_table() => 'date_reservee' ) as $table => $col ) {
		$rows = (array) $wpdb->get_results( "SELECT _ID, {$col} AS ts FROM {$table} WHERE {$col} IS NOT NULL AND {$col} > 0 AND MOD({$col}, 86400) <> 0", ARRAY_A );

		foreach ( $rows as $row ) {
			$wpdb->update( $table, array( $col => gacct_cal_normalize_ts( (int) $row['ts'] ) ), array( '_ID' => (int) $row['_ID'] ) );
			$touched++;
		}
	}

	// Doublons du calendrier (même jour, même statut publish).
	$table = gacct_cal_table();
	$dups  = (array) $wpdb->get_results( "SELECT date_jour, GROUP_CONCAT(_ID ORDER BY _ID) AS ids, MAX(CAST(heures_totales_dispo AS DECIMAL(10,2))) AS h FROM {$table} WHERE cct_status = 'publish' GROUP BY date_jour HAVING COUNT(*) > 1", ARRAY_A );

	foreach ( $dups as $dup ) {
		$ids  = array_map( 'absint', explode( ',', $dup['ids'] ) );
		$keep = array_shift( $ids );
		$wpdb->update( $table, array( 'heures_totales_dispo' => $dup['h'] ), array( '_ID' => $keep ) );
		foreach ( $ids as $id ) {
			$wpdb->delete( $table, array( '_ID' => $id ) );
			$touched++;
		}
	}

	return $touched;
}

add_action( 'init', function () {
	if ( '1' !== (string) get_option( 'gacct_cal_normalized', '' ) ) {
		gacct_cal_normalize_tables();
		update_option( 'gacct_cal_normalized', '1', false );
	}
}, 20 );

/* =============================================================================
 *  CONFIGURATION : onglet « Jours fériés & fermetures »
 * ============================================================================= */

add_filter( 'gacct_config_tabs', function ( $tabs ) {
	$out = array();

	foreach ( $tabs as $key => $tab ) {
		$out[ $key ] = $tab;
		if ( 'ouvertures' === $key ) {
			$out['fermetures'] = array( __( 'Jours fériés & fermetures', 'gestion-atelier-cct' ), 'gacct_cal_render_config_tab' );
		}
	}

	if ( ! isset( $out['fermetures'] ) ) {
		$out['fermetures'] = array( __( 'Jours fériés & fermetures', 'gestion-atelier-cct' ), 'gacct_cal_render_config_tab' );
	}

	return $out;
} );

function gacct_cal_render_config_tab() {
	if ( ! current_user_can( apply_filters( 'gacct_admin_capability', 'manage_options' ) ) ) {
		wp_die( esc_html__( 'Acces refuse.', 'gestion-atelier-cct' ) );
	}

	$notice = null;

	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['gacct_cal_submit'] ) ) {
		if ( ! isset( $_POST['_gacct_cal_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gacct_cal_nonce'] ) ), 'gacct_save_closures' ) ) {
			$notice = new WP_Error( 'gacct_bad_nonce', __( 'Verification de securite echouee.', 'gestion-atelier-cct' ) );
		} else {
			update_option( GACCT_CAL_HOLIDAYS_OPT, empty( $_POST['holidays_enabled'] ) ? 0 : 1, false );
			update_option( GACCT_CAL_CLOSED_OPT, gacct_cal_parse_closed_dates( isset( $_POST['closed_dates'] ) ? wp_unslash( $_POST['closed_dates'] ) : '' ), false );
			$notice = __( 'Reglages enregistres.', 'gestion-atelier-cct' );
		}
	}

	$year   = (int) wp_date( 'Y' );
	$closed = gacct_cal_closed_dates();
	$url    = GACCT_Plugin::config_tab_url( 'fermetures' );
	?>
	<div class="wrap gacct-wrap">
		<h2 class="title"><?php esc_html_e( 'Jours fériés et fermetures', 'gestion-atelier-cct' ); ?></h2>

		<?php if ( is_wp_error( $notice ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $notice->get_error_message() ); ?></p></div>
		<?php elseif ( $notice ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'Les jours listés ici ne sont jamais ouverts par le générateur ni par le planning (sauf ouverture forcée), et sont signalés en gris dans le planning de la console.', 'gestion-atelier-cct' ); ?>
		</p>

		<form class="gacct-form" method="post" action="<?php echo esc_url( $url ); ?>">
			<?php wp_nonce_field( 'gacct_save_closures', '_gacct_cal_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Jours fériés', 'gestion-atelier-cct' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="holidays_enabled" value="1" <?php checked( gacct_cal_holidays_enabled() ); ?>>
								<?php esc_html_e( 'Ne pas ouvrir l atelier les jours fériés officiels français', 'gestion-atelier-cct' ); ?>
							</label>
							<div class="gacct-holidays-grid">
								<?php foreach ( array( $year, $year + 1 ) as $y ) : ?>
									<div>
										<strong><?php echo esc_html( $y ); ?></strong>
										<ul>
											<?php foreach ( gacct_cal_holidays( $y ) as $ymd => $label ) : ?>
												<li><code><?php echo esc_html( wp_date( 'D d/m', gacct_cal_day_ts( $ymd ) + 12 * HOUR_IN_SECONDS ) ); ?></code> <?php echo esc_html( $label ); ?></li>
											<?php endforeach; ?>
										</ul>
									</div>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gacct_closed_dates"><?php esc_html_e( 'Fermetures exceptionnelles', 'gestion-atelier-cct' ); ?></label>
						</th>
						<td>
							<textarea id="gacct_closed_dates" name="closed_dates" rows="8" class="large-text code" placeholder="2026-12-24 2027-01-03 Vacances de Noël&#10;2026-06-15 Salon"><?php echo esc_textarea( gacct_cal_closed_dates_text( $closed ) ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Une fermeture par ligne : une date (AAAA-MM-JJ), ou une plage (deux dates), suivie d un libellé facultatif. Ces jours restent fermés même s ils sont travaillés.', 'gestion-atelier-cct' ); ?>
							</p>
							<?php if ( ! empty( $closed ) ) : ?>
								<p class="description">
									<?php
									echo esc_html( sprintf(
										/* translators: %d: nombre de jours */
										_n( '%d jour de fermeture enregistré.', '%d jours de fermeture enregistrés.', count( $closed ), 'gestion-atelier-cct' ),
										count( $closed )
									) );
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Enregistrer', 'gestion-atelier-cct' ), 'primary', 'gacct_cal_submit' ); ?>
		</form>
	</div>
	<style>
		.gacct-holidays-grid { display: flex; flex-wrap: wrap; gap: 24px; margin-top: 12px; }
		.gacct-holidays-grid ul { margin: 6px 0 0; }
		.gacct-holidays-grid li { margin: 2px 0; }
		.gacct-holidays-grid code { min-width: 82px; display: inline-block; }
	</style>
	<?php
}
