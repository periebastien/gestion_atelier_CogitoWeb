<?php
/**
 * Automatismes horaires (accrochés au cron horaire du socle) :
 * - rappel au responsable N jours avant la fermeture des inscriptions ;
 * - fermeture des inscriptions le lendemain de la date limite.
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

add_action( defined( 'GACCT_PAY_HOURLY_EVENT' ) ? GACCT_PAY_HOURLY_EVENT : 'gacct_pay_hourly_tick', 'gacct_clubs_hourly', 70 );

function gacct_clubs_hourly() {
	$today = current_time( 'Y-m-d' );
	$hour  = (int) current_time( 'G' );

	foreach ( gacct_clubs_lots( array( 'statut' => 'planifie' ) ) as $lot ) {
		if ( empty( $lot['inscriptions_ouvertes'] ) || ! $lot['limite_inscription'] ) {
			continue;
		}

		// Fermeture : la date limite est passée.
		if ( $today > $lot['limite_inscription'] ) {
			gacct_clubs_update_lot( (int) $lot['id'], array( 'inscriptions_ouvertes' => 0 ) );
			continue;
		}

		// Rappel : une seule fois, entre 9 h et 19 h.
		$remind_from = gacct_clubs_shift_date( $lot['limite_inscription'], - (int) gacct_clubs_setting( 'reminder_days' ) );
		if ( empty( $lot['rappel_envoye'] ) && $today >= $remind_from && $hour >= 9 && $hour < 19 && ! empty( $lot['code_envoye'] ) ) {
			if ( gacct_clubs_send( gacct_clubs_lot_email( $lot ), 'club_registration_reminder', $lot ) ) {
				gacct_clubs_update_lot( (int) $lot['id'], array( 'rappel_envoye' => 1 ) );
			}
		}
	}
}
