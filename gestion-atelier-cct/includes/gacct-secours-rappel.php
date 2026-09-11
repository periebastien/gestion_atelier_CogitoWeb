<?php
/**
 * Rappel annuel de pliage du parachute de secours (09/09/2026, décision Bastien).
 *
 * S'appuie sur gacct_secours_client() (gacct-secours-client.php) : pour chaque
 * secours d'un client avec un dernier pliage connu, un e-mail part un mois
 * avant l'échéance (dernier pliage + 12 mois), puis une relance un mois après
 * l'échéance si aucun pliage n'a été demandé entre-temps. Un seul rappel et
 * une seule relance par secours et par cycle (mémorisés en meta utilisateur,
 * clé = secours + date du dernier pliage). Rien tant qu'un pliage est en cours.
 * Le client peut couper les rappels (case dans « Mon matériel », lien dans
 * l'e-mail).
 *
 * Modèles d'e-mails éditables dans Gestion Atelier > Paiements & relances
 * (`secours_reminder`, `secours_reminder_2`, via le filtre
 * gacct_pay_default_settings). Envoi par gacct_pay_send_email() : gabarit
 * WooCommerce, copie admin non demandée.
 *
 * Cron quotidien `gacct_secours_rappel_tick` (9 h, heure du site). Passe
 * manuelle / simulation : gacct_secours_rappel_run( $simulation = true ).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_SECOURS_RAPPEL_EVENT', 'gacct_secours_rappel_tick' );
define( 'GACCT_SECOURS_RAPPEL_META', '_gacct_secours_rappels' );
define( 'GACCT_SECOURS_RAPPEL_OFF_META', '_gacct_secours_rappel_off' );
define( 'GACCT_SECOURS_RAPPEL_LOG_OPT', 'gacct_secours_rappel_log' );

/**
 * Délais (mois) : rappel AVANT l'échéance, relance APRÈS. Filtrables.
 */
function gacct_secours_rappel_delais() {
	return (array) apply_filters( 'gacct_secours_rappel_delais', array( 'avant' => 1, 'apres' => 1 ) );
}

/* =============================================================================
 *  MODÈLES D'E-MAILS (page Paiements & relances)
 * ============================================================================= */

add_filter( 'gacct_pay_default_settings', function ( $defaults ) {
	// Inactifs par défaut : à activer sur le site de production au moment voulu
	// (le site de développement porte les vraies adresses des clients importés).
	$defaults['emails']['secours_reminder'] = array(
		'enabled' => false,
		'label'   => __( 'Rappel annuel : pliage du parachute de secours (un mois avant l’échéance)', 'gestion-atelier-cct' ),
		'subject' => __( 'Votre parachute de secours est à replier - {site_name}', 'gestion-atelier-cct' ),
		'body'    => '<p>Bonjour {customer_name},</p>'
			. '<p>Un parachute de secours se replie une fois par an : c’est ce qui garantit une ouverture franche le jour où vous en aurez besoin.</p>'
			. '<p>D’après nos registres, l’échéance approche pour :</p>'
			. '{secours_lines}'
			. '<p><a href="{new_request_url}">Demander un pliage en ligne</a> : choisissez votre date, nous nous occupons du reste. Vous pouvez aussi déposer votre secours directement à la boutique.</p>'
			. '<p>Si votre secours a été replié ailleurs entre-temps, ignorez simplement ce message. Une question ? Répondez à cet e-mail ou appelez-nous au <strong>{contact_phone}</strong> ({contact_hours}).</p>'
			. '<p>À bientôt,<br><br>L’équipe Altitude Révision</p>'
			. '<p style="font-size:12px;color:#777">Vous ne souhaitez plus recevoir ce rappel ? <a href="{optout_url}">Ne plus me rappeler</a>.</p>',
	);
	$defaults['emails']['secours_reminder_2'] = array(
		'enabled' => false,
		'label'   => __( 'Rappel annuel : relance (un mois après l’échéance)', 'gestion-atelier-cct' ),
		'subject' => __( 'Le pliage de votre parachute de secours est dépassé - {site_name}', 'gestion-atelier-cct' ),
		'body'    => '<p>Bonjour {customer_name},</p>'
			. '<p>Petit rappel : le pliage annuel de votre parachute de secours est maintenant dépassé pour :</p>'
			. '{secours_lines}'
			. '<p>Un secours resté plié trop longtemps s’ouvre moins vite. <a href="{new_request_url}">Demander un pliage en ligne</a> ne prend que deux minutes.</p>'
			. '<p>Si c’est déjà fait ailleurs, ignorez ce message : nous ne vous relancerons plus pour ce cycle. Une question ? Répondez à cet e-mail ou appelez-nous au <strong>{contact_phone}</strong> ({contact_hours}).</p>'
			. '<p>À bientôt,<br><br>L’équipe Altitude Révision</p>'
			. '<p style="font-size:12px;color:#777">Vous ne souhaitez plus recevoir ce rappel ? <a href="{optout_url}">Ne plus me rappeler</a>.</p>',
	);
	return $defaults;
} );

/* =============================================================================
 *  CRON
 * ============================================================================= */

add_action( 'init', function () {
	if ( ! wp_next_scheduled( GACCT_SECOURS_RAPPEL_EVENT ) ) {
		// Prochain 9 h, heure du site.
		$local = current_time( 'timestamp' );
		$neuf  = strtotime( 'today 09:00', $local );
		if ( $neuf <= $local ) {
			$neuf = strtotime( 'tomorrow 09:00', $local );
		}
		wp_schedule_event( $neuf - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), 'daily', GACCT_SECOURS_RAPPEL_EVENT );
	}
} );
register_deactivation_hook( dirname( __DIR__ ) . '/gestion-atelier-cct.php', function () {
	wp_clear_scheduled_hook( GACCT_SECOURS_RAPPEL_EVENT );
} );
add_action( GACCT_SECOURS_RAPPEL_EVENT, function () {
	gacct_secours_rappel_run( false );
} );

/**
 * Clients susceptibles d'avoir un secours : fiches d'intervention avec un
 * secours décrit + historique de l'ancien site.
 *
 * @return int[]
 */
function gacct_secours_rappel_clients() {
	global $wpdb;
	$ids = array();

	$table = $wpdb->prefix . 'jet_cct_revision';
	if ( $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'secours_marque'" ) ) { // phpcs:ignore
		$ids = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT client_id FROM {$table} WHERE cct_status = 'publish' AND client_id > 0 AND secours_marque IS NOT NULL AND TRIM(secours_marque) <> ''" ) ) ); // phpcs:ignore
		$ids = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT rl.parent_object_id FROM {$wpdb->prefix}jet_rel_default rl INNER JOIN {$table} r ON r._ID = rl.child_object_id WHERE rl.rel_id = %d AND r.cct_status = 'publish' AND r.secours_marque IS NOT NULL AND TRIM(r.secours_marque) <> ''", function_exists( 'gacct_relation_id' ) ? (int) gacct_relation_id( 'client_to_revision', 13 ) : 13 ) ) ) ); // phpcs:ignore
	}
	if ( function_exists( 'gacct_historique_table_exists' ) && gacct_historique_table_exists() ) {
		$h   = gacct_historique_table();
		$ids = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$h} WHERE user_id > 0 AND (type_materiel = 'secours' OR avec_secours = 1)" ) ) ); // phpcs:ignore
	}

	return (array) apply_filters( 'gacct_secours_rappel_clients', array_values( array_unique( array_filter( $ids ) ) ) );
}

/**
 * Passe de rappel. En simulation, n'envoie rien et ne mémorise rien.
 *
 * @param bool $simulation
 * @return array{clients:int,emails:int,relances:int,detail:array<int,array<string,mixed>>}
 */
function gacct_secours_rappel_run( $simulation = false ) {
	$stats  = array( 'clients' => 0, 'emails' => 0, 'relances' => 0, 'detail' => array() );
	$delais = gacct_secours_rappel_delais();
	$auj    = wp_date( 'Y-m-d' );

	if ( ! function_exists( 'gacct_secours_client' ) || ! function_exists( 'gacct_pay_send_email' ) ) {
		return $stats;
	}

	foreach ( gacct_secours_rappel_clients() as $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) || get_user_meta( $user_id, GACCT_SECOURS_RAPPEL_OFF_META, true ) ) {
			continue;
		}
		$stats['clients']++;

		$memo    = get_user_meta( $user_id, GACCT_SECOURS_RAPPEL_META, true );
		$memo    = is_array( $memo ) ? $memo : array();
		$a_faire = array(); // secours => 'premier' | 'relance'

		foreach ( gacct_secours_client( $user_id ) as $s ) {
			if ( 'NP' === $s['cle'] || '' === $s['prochain_pliage'] || $s['en_cours'] ) {
				continue;
			}
			$seuil_avant = wp_date( 'Y-m-d', strtotime( $s['prochain_pliage'] . ' -' . (int) $delais['avant'] . ' months' ) );
			$seuil_apres = wp_date( 'Y-m-d', strtotime( $s['prochain_pliage'] . ' +' . (int) $delais['apres'] . ' months' ) );
			$m = isset( $memo[ $s['cle'] ] ) && is_array( $memo[ $s['cle'] ] ) ? $memo[ $s['cle'] ] : array();
			$meme_cycle = ( $m['pour'] ?? '' ) === $s['dernier_pliage'];

			if ( $auj >= $seuil_apres && $meme_cycle && ! empty( $m['premier'] ) && empty( $m['relance'] ) ) {
				$a_faire[] = array( 's' => $s, 'type' => 'relance' );
			} elseif ( $auj >= $seuil_avant && $auj <= $seuil_apres && ! $meme_cycle ) {
				// Fenêtre bornée : une échéance dépassée depuis plus de « apres » mois
				// (arriéré de l'ancien site) n'est pas rappelée, elle reste visible
				// dans Mon matériel ; une campagne dédiée vaut mieux qu'un rappel
				// automatique pour un pliage de 2019.
				$a_faire[] = array( 's' => $s, 'type' => 'premier' );
			}
		}

		if ( ! $a_faire ) {
			continue;
		}

		$relance_seule = ! array_filter( $a_faire, static function ( $x ) { return 'premier' === $x['type']; } );
		$template      = $relance_seule ? 'secours_reminder_2' : 'secours_reminder';

		$lignes = '<ul>';
		foreach ( $a_faire as $x ) {
			$s = $x['s'];
			$lignes .= '<li><strong>' . esc_html( gacct_secours_libelle( $s ) ) . '</strong>'
				. ' : ' . sprintf( esc_html__( 'dernier pliage le %1$s, à replier avant le %2$s', 'gestion-atelier-cct' ), date_i18n( get_option( 'date_format' ), strtotime( $s['dernier_pliage'] ) ), date_i18n( get_option( 'date_format' ), strtotime( $s['prochain_pliage'] ) ) )
				. '</li>';
		}
		$lignes .= '</ul>';

		$nom = trim( get_user_meta( $user_id, 'billing_first_name', true ) . ' ' . get_user_meta( $user_id, 'billing_last_name', true ) );
		$nom = $nom ?: ( trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name );

		$variables = gacct_pay_email_variables( null, array(
			'{customer_name}'   => $nom,
			'{secours_lines}'   => $lignes,
			'{secours_count}'   => (string) count( $a_faire ),
			'{materiel_url}'    => esc_url( function_exists( 'jwcct_get_compte_subpage_url' ) ? jwcct_get_compte_subpage_url( 'mon-materiel' ) : home_url( '/mon-compte/mon-materiel/' ) ),
			'{new_request_url}' => esc_url( home_url( '/demande-intervention/' ) ),
			'{optout_url}'      => esc_url( gacct_secours_rappel_optout_url( $user_id ) ),
		) );

		$stats['detail'][] = array( 'user_id' => $user_id, 'email' => $user->user_email, 'template' => $template, 'secours' => array_map( static function ( $x ) { return gacct_secours_libelle( $x['s'] ) . ' (' . $x['type'] . ')'; }, $a_faire ) );

		if ( $simulation ) {
			continue;
		}

		$sent = gacct_pay_send_email( $user->user_email, $template, $variables );
		if ( ! $sent ) {
			continue;
		}
		$stats['emails']++;
		if ( $relance_seule ) {
			$stats['relances']++;
		}
		foreach ( $a_faire as $x ) {
			$cle = $x['s']['cle'];
			if ( 'premier' === $x['type'] ) {
				$memo[ $cle ] = array( 'pour' => $x['s']['dernier_pliage'], 'premier' => $auj, 'relance' => '' );
			} else {
				$memo[ $cle ]['relance'] = $auj;
			}
		}
		update_user_meta( $user_id, GACCT_SECOURS_RAPPEL_META, $memo );
	}

	if ( ! $simulation ) {
		update_option( GACCT_SECOURS_RAPPEL_LOG_OPT, array( 'quand' => current_time( 'mysql' ), 'clients' => $stats['clients'], 'emails' => $stats['emails'], 'relances' => $stats['relances'] ), false );
	}

	return $stats;
}

/* =============================================================================
 *  DÉSABONNEMENT (lien e-mail signé + case dans Mon matériel)
 * ============================================================================= */

function gacct_secours_rappel_optout_url( $user_id ) {
	$user_id = (int) $user_id;
	$token   = substr( hash_hmac( 'sha256', 'secours-optout-' . $user_id, wp_salt( 'auth' ) ), 0, 20 );
	return add_query_arg( array( 'gacct_secours_optout' => $user_id, 't' => $token ), home_url( '/' ) );
}

add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['gacct_secours_optout'], $_GET['t'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$user_id = absint( $_GET['gacct_secours_optout'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$token   = sanitize_text_field( wp_unslash( $_GET['t'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$attendu = substr( hash_hmac( 'sha256', 'secours-optout-' . $user_id, wp_salt( 'auth' ) ), 0, 20 );
	if ( $user_id && hash_equals( $attendu, $token ) ) {
		update_user_meta( $user_id, GACCT_SECOURS_RAPPEL_OFF_META, 1 );
	}
	$dest = function_exists( 'jwcct_get_compte_subpage_url' ) ? jwcct_get_compte_subpage_url( 'mon-materiel' ) : home_url( '/mon-compte/mon-materiel/' );
	wp_safe_redirect( add_query_arg( 'rappel', 'off', $dest ) );
	exit;
}, 5 );

/**
 * Case « Recevoir les rappels annuels » de Mon matériel (POST sur la page).
 */
add_action( 'template_redirect', function () {
	if ( ! is_user_logged_in() || empty( $_POST['gacct_secours_rappel_form'] ) ) {
		return;
	}
	check_admin_referer( 'gacct_secours_rappel', '_gacct_secours_rappel_nonce' );
	$uid = get_current_user_id();
	if ( empty( $_POST['rappel_on'] ) ) {
		update_user_meta( $uid, GACCT_SECOURS_RAPPEL_OFF_META, 1 );
	} else {
		delete_user_meta( $uid, GACCT_SECOURS_RAPPEL_OFF_META );
	}
	wp_safe_redirect( add_query_arg( 'rappel', empty( $_POST['rappel_on'] ) ? 'off' : 'on', remove_query_arg( array( 'rappel' ) ) ) );
	exit;
}, 6 );

function gacct_secours_rappel_actif( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	return ! get_user_meta( $user_id, GACCT_SECOURS_RAPPEL_OFF_META, true );
}
