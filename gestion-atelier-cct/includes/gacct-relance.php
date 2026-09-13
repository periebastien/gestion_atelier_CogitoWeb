<?php
/**
 * Relance des commandes annulées faute de paiement, mesurée de bout en bout.
 *
 * Constat du 13/09/2026 : une commande dont le paiement carte échoue (ou dont le
 * virement n'arrive pas) reçoit une relance courte puis est annulée automatiquement,
 * créneau libéré. Après l'annulation, plus rien : le client qui voulait une révision
 * est perdu, et rien ne dit s'il revient.
 *
 * Ce module ajoute, après l'annulation automatique (meta GACCT_PAY_META_AUTO_CANCELLED) :
 *  - une relance à J+1 (modèle `abandon_relance_1`) puis une à J+4 (`abandon_relance_2`),
 *    délais réglables dans Paiements & relances ;
 *  - un lien de reprise porteur d'un jeton (?gacct_r=…) et de paramètres de campagne
 *    (utm_source=email, utm_medium=relance, utm_campaign=abandon-j1 / abandon-j4) ;
 *  - à l'arrivée sur le site : cookie `gacct_relance` (7 jours), note sur la commande
 *    d'origine, événement `relance_clic` poussé par analytics.js depuis l'URL ;
 *  - à la commande suivante : meta `_gacct_relance_source` (campagne) et
 *    `_gacct_relance_origin` sur la nouvelle commande, `_gacct_relance_recuperee`
 *    sur l'ancienne, notes des deux côtés. L'événement purchase remonte `relance`
 *    dans GA4, ce qui donne le taux de récupération par campagne.
 *
 * Arrêt automatique : dès que le client a repassé une commande (avec ou sans clic),
 * ou s'il n'a pas d'adresse e-mail. Aucune relance au-delà de la seconde.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_RELANCE_COOKIE', 'gacct_relance' );
define( 'GACCT_RELANCE_QUERY', 'gacct_r' );
define( 'GACCT_RELANCE_META_TOKEN', '_gacct_relance_token' );
define( 'GACCT_RELANCE_META_SENT_1', '_gacct_relance_1_sent' );
define( 'GACCT_RELANCE_META_SENT_2', '_gacct_relance_2_sent' );
define( 'GACCT_RELANCE_META_STOP', '_gacct_relance_stop' );
define( 'GACCT_RELANCE_META_CLICKED', '_gacct_relance_clicked' );
define( 'GACCT_RELANCE_META_RECOVERED', '_gacct_relance_recuperee' );
define( 'GACCT_RELANCE_META_SOURCE', '_gacct_relance_source' );
define( 'GACCT_RELANCE_META_ORIGIN', '_gacct_relance_origin' );
define( 'GACCT_RELANCE_WINDOW_DAYS', 21 ); // au-delà, une annulation n'est plus relancée

/* =============================================================================
 *  RÉGLAGES (délais + modèles, greffés sur gacct_pay_default_settings)
 * ============================================================================= */

add_filter( 'gacct_pay_default_settings', 'gacct_relance_default_settings' );

function gacct_relance_default_settings( $defaults ) {
	$defaults['abandon_relance_days_1'] = 1;
	$defaults['abandon_relance_days_2'] = 4;

	$defaults['emails']['abandon_relance_1'] = array(
		'enabled' => true,
		'label'   => __( 'Relance après annulation, 1er message (J+1)', 'gestion-atelier-cct' ),
		'subject' => __( 'Votre révision vous attend toujours', 'gestion-atelier-cct' ),
		'body'    => '<p>Bonjour {customer_name},</p>'
			. '<p>Vous aviez préparé une demande d’intervention pour votre matériel ({order_items}), mais le paiement n’a pas abouti et la commande {order_number} a été annulée.</p>'
			. '<p>Il reste des créneaux à l’atelier : <a href="{relance_url}">refaire ma demande en deux minutes</a>. Vos informations sont déjà connues, il n’y a que la date et le paiement à valider.</p>'
			. '<p>Un souci avec votre carte, une question sur les tarifs ou les délais ? Répondez simplement à cet e-mail ou appelez-nous au <strong>{contact_phone}</strong> ({contact_hours}) : nous trouverons une solution ensemble.</p>'
			. '<p>À très vite,<br><br>' . gacct_team_signature() . '</p>',
	);
	$defaults['emails']['abandon_relance_2'] = array(
		'enabled' => true,
		'label'   => __( 'Relance après annulation, 2e message (J+4, dernier)', 'gestion-atelier-cct' ),
		'subject' => __( 'Dernier rappel : votre créneau atelier', 'gestion-atelier-cct' ),
		'body'    => '<p>Bonjour {customer_name},</p>'
			. '<p>Un dernier mot au sujet de votre demande de révision ({order_items}) restée sans paiement.</p>'
			. '<p>Si votre matériel a toujours besoin de passer à l’atelier, les prochaines dates disponibles sont en ligne : <a href="{relance_url}">choisir mon créneau</a>.</p>'
			. '<p>Si vous avez changé d’avis ou trouvé une autre solution, aucun problème : nous ne vous écrirons plus à ce sujet.</p>'
			. '<p>À bientôt,<br><br>' . gacct_team_signature() . '</p>',
	);
	return $defaults;
}

/** Sauvegarde des deux délais (la page Paiements & relances poste tout d'un bloc). */
add_filter( 'gacct_pay_settings_from_post', function ( $settings, $defaults ) {
	$settings['abandon_relance_days_1'] = max( 1, absint( $_POST['abandon_relance_days_1'] ?? $defaults['abandon_relance_days_1'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$settings['abandon_relance_days_2'] = max( 1, absint( $_POST['abandon_relance_days_2'] ?? $defaults['abandon_relance_days_2'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( $settings['abandon_relance_days_2'] <= $settings['abandon_relance_days_1'] ) {
		$settings['abandon_relance_days_2'] = $settings['abandon_relance_days_1'] + 1;
	}
	return $settings;
}, 10, 2 );

/** Bloc de réglages, affiché avant « Coordonnees affichees au client ». */
add_action( 'gacct_pay_settings_sections', function ( $settings ) {
	?>
	<h2><?php esc_html_e( 'Relance après annulation', 'gestion-atelier-cct' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Quand une commande a été annulée automatiquement faute de paiement (carte refusée, virement non reçu), deux e-mails invitent le client à refaire sa demande. Ils s’arrêtent dès qu’il repasse commande. Le lien de reprise est mesuré dans Google Analytics (campagnes abandon-j1 et abandon-j4, événements relance_clic et purchase).', 'gestion-atelier-cct' ); ?></p>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><label for="gacct_abandon_relance_days_1"><?php esc_html_e( '1er message', 'gestion-atelier-cct' ); ?></label></th>
				<td>
					<input type="number" id="gacct_abandon_relance_days_1" name="abandon_relance_days_1" class="small-text" min="1" value="<?php echo esc_attr( $settings['abandon_relance_days_1'] ); ?>">
					<?php esc_html_e( 'jours apres l’annulation', 'gestion-atelier-cct' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gacct_abandon_relance_days_2"><?php esc_html_e( '2e message (dernier)', 'gestion-atelier-cct' ); ?></label></th>
				<td>
					<input type="number" id="gacct_abandon_relance_days_2" name="abandon_relance_days_2" class="small-text" min="2" value="<?php echo esc_attr( $settings['abandon_relance_days_2'] ); ?>">
					<?php esc_html_e( 'jours apres l’annulation', 'gestion-atelier-cct' ); ?>
					<p class="description"><?php esc_html_e( 'Pour ne garder qu’un seul message, decochez « Envoyer cet email » sur le modele du 2e message.', 'gestion-atelier-cct' ); ?></p>
				</td>
			</tr>
		</tbody>
	</table>
	<?php
} );

/* =============================================================================
 *  CRON : après l'annulation automatique
 * ============================================================================= */

add_action( 'gacct_pay_hourly_tick_after', 'gacct_relance_process' );

/** Horodatage (UTC) de l'annulation automatique, 0 si absent. */
function gacct_relance_cancel_ts( $order ) {
	$raw = (string) $order->get_meta( GACCT_PAY_META_AUTO_CANCELLED );
	if ( '' === $raw ) {
		return 0;
	}
	try {
		return ( new DateTimeImmutable( $raw, wp_timezone() ) )->getTimestamp();
	} catch ( Exception $e ) {
		return 0;
	}
}

/** Le client a-t-il repassé une commande vivante depuis l'annulation ? */
function gacct_relance_customer_reordered( $order, $since_ts ) {
	$args = array(
		'limit'        => 1,
		'return'       => 'ids',
		'exclude'      => array( $order->get_id() ),
		'date_created' => '>' . (int) $since_ts,
		'status'       => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-failed', 'wc-checkout-draft', 'wc-trash' ) ),
	);
	if ( $order->get_customer_id() ) {
		$args['customer_id'] = $order->get_customer_id();
	} elseif ( $order->get_billing_email() ) {
		$args['billing_email'] = $order->get_billing_email();
	} else {
		return false;
	}
	$ids = wc_get_orders( $args );
	return is_array( $ids ) && ! empty( $ids ) ? (int) $ids[0] : false;
}

function gacct_relance_token( $order ) {
	$token = (string) $order->get_meta( GACCT_RELANCE_META_TOKEN );
	if ( '' === $token ) {
		$token = wp_generate_password( 24, false, false );
		$order->update_meta_data( GACCT_RELANCE_META_TOKEN, $token );
		$order->save();
	}
	return $token;
}

/** Lien de reprise : nouvelle demande, jeton + campagne. */
function gacct_relance_url( $order, $campaign ) {
	return add_query_arg( array(
		GACCT_RELANCE_QUERY => gacct_relance_token( $order ),
		'utm_source'        => 'email',
		'utm_medium'        => 'relance',
		'utm_campaign'      => $campaign,
	), home_url( '/demande-intervention/' ) );
}

function gacct_relance_order_items_label( $order ) {
	$names = array();
	foreach ( $order->get_items() as $item ) {
		$names[] = $item->get_name();
	}
	return $names ? implode( ', ', $names ) : __( 'votre matériel', 'gestion-atelier-cct' );
}

function gacct_relance_process() {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return;
	}
	$settings = gacct_pay_settings();
	$now      = time();

	// Date de mise en service : les annulations antérieures ne sont jamais relancées
	// (au déploiement, on ne réveille pas des dossiers vieux de trois semaines).
	$since = (int) get_option( 'gacct_relance_since', 0 );
	if ( ! $since ) {
		$since = $now;
		update_option( 'gacct_relance_since', $since, false );
	}

	$orders = wc_get_orders( array(
		'status'       => 'cancelled',
		'limit'        => 200,
		'orderby'      => 'date',
		'order'        => 'DESC',
		'date_created' => '>' . ( $now - GACCT_RELANCE_WINDOW_DAYS * DAY_IN_SECONDS ),
	) );

	foreach ( $orders as $order ) {
		$cancel_ts = gacct_relance_cancel_ts( $order );
		if ( ! $cancel_ts || $cancel_ts < $since || $order->get_meta( GACCT_RELANCE_META_STOP ) || $order->get_meta( GACCT_RELANCE_META_SENT_2 ) ) {
			continue;
		}
		if ( ! is_email( $order->get_billing_email() ) ) {
			$order->update_meta_data( GACCT_RELANCE_META_STOP, 'sans-email' );
			$order->save();
			continue;
		}
		$reordered = gacct_relance_customer_reordered( $order, $cancel_ts );
		if ( $reordered ) {
			$order->update_meta_data( GACCT_RELANCE_META_STOP, 'commande-' . $reordered );
			$order->save();
			continue;
		}

		$step = 0;
		if ( ! $order->get_meta( GACCT_RELANCE_META_SENT_1 ) ) {
			if ( $now >= $cancel_ts + $settings['abandon_relance_days_1'] * DAY_IN_SECONDS ) {
				$step = 1;
			}
		} elseif ( $now >= $cancel_ts + $settings['abandon_relance_days_2'] * DAY_IN_SECONDS ) {
			$step = 2;
		}
		if ( ! $step ) {
			continue;
		}

		$campaign = 1 === $step ? 'abandon-j' . (int) $settings['abandon_relance_days_1'] : 'abandon-j' . (int) $settings['abandon_relance_days_2'];
		$template = 1 === $step ? 'abandon_relance_1' : 'abandon_relance_2';
		$meta     = 1 === $step ? GACCT_RELANCE_META_SENT_1 : GACCT_RELANCE_META_SENT_2;

		if ( empty( $settings['emails'][ $template ]['enabled'] ) ) {
			$order->update_meta_data( $meta, 'desactive' );
			$order->save();
			continue;
		}

		$sent = gacct_pay_send_email(
			$order->get_billing_email(),
			$template,
			gacct_pay_email_variables( $order, array(
				'{relance_url}' => esc_url( gacct_relance_url( $order, $campaign ) ),
				'{order_items}' => esc_html( gacct_relance_order_items_label( $order ) ),
			) )
		);

		$order->update_meta_data( $meta, $sent ? current_time( 'mysql' ) : 'echec ' . current_time( 'mysql' ) );
		$order->save();
		$order->add_order_note( $sent
			? sprintf(
				/* translators: 1: numéro de relance, 2: campagne, 3: e-mail */
				__( 'Relance après annulation n°%1$d envoyée (campagne %2$s) à %3$s, avec lien de reprise mesuré.', 'gestion-atelier-cct' ),
				$step,
				$campaign,
				$order->get_billing_email()
			)
			: sprintf( __( 'Echec d’envoi de la relance après annulation n°%d (wp_mail a retourne false).', 'gestion-atelier-cct' ), $step )
		);

		if ( $sent && function_exists( 'gacct_ga_order_event' ) ) {
			gacct_ga_order_event( $order, 'relance_envoyee', array( 'campagne' => $campaign ) );
		}
	}
}

/* =============================================================================
 *  ARRIVÉE SUR LE SITE PAR LE LIEN DE RELANCE
 * ============================================================================= */

add_action( 'init', 'gacct_relance_capture', 20 );

function gacct_relance_capture() {
	if ( empty( $_GET[ GACCT_RELANCE_QUERY ] ) || ! function_exists( 'wc_get_orders' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$token = sanitize_text_field( wp_unslash( $_GET[ GACCT_RELANCE_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! preg_match( '/^[A-Za-z0-9]{24}$/', $token ) ) {
		return;
	}
	$ids = wc_get_orders( array(
		'limit'      => 1,
		'return'     => 'ids',
		'meta_key'   => GACCT_RELANCE_META_TOKEN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value' => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	) );
	if ( empty( $ids ) ) {
		return;
	}
	$order    = wc_get_order( (int) $ids[0] );
	$campaign = isset( $_GET['utm_campaign'] ) ? sanitize_title( wp_unslash( $_GET['utm_campaign'] ) ) : 'abandon'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! $order || ! preg_match( '/^abandon(-j\d{1,2})?$/', $campaign ) ) {
		return;
	}

	setcookie( GACCT_RELANCE_COOKIE, $order->get_id() . '|' . $campaign, time() + 7 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
	$_COOKIE[ GACCT_RELANCE_COOKIE ] = $order->get_id() . '|' . $campaign;

	if ( ! $order->get_meta( GACCT_RELANCE_META_CLICKED ) ) {
		$order->update_meta_data( GACCT_RELANCE_META_CLICKED, current_time( 'mysql' ) . ' (' . $campaign . ')' );
		$order->save();
		$order->add_order_note( sprintf(
			/* translators: %s: campagne */
			__( 'Le client a cliqué sur le lien de relance (%s) et est revenu sur le site.', 'gestion-atelier-cct' ),
			$campaign
		) );
	}
}

/* =============================================================================
 *  ATTRIBUTION DE LA COMMANDE SUIVANTE
 * ============================================================================= */

add_action( 'woocommerce_checkout_order_processed', 'gacct_relance_attribute_order', 40 );

function gacct_relance_attribute_order( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$origin   = null;
	$campaign = '';

	if ( ! empty( $_COOKIE[ GACCT_RELANCE_COOKIE ] ) && preg_match( '/^(\d+)\|([a-z0-9-]+)$/', (string) $_COOKIE[ GACCT_RELANCE_COOKIE ], $m ) ) {
		$origin   = wc_get_order( (int) $m[1] );
		$campaign = $m[2];
		setcookie( GACCT_RELANCE_COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
	}

	// Sans clic : le client relancé revient par lui-même (autre appareil, adresse tapée).
	if ( ! $origin && ( $order->get_customer_id() || $order->get_billing_email() ) ) {
		$args = array(
			'limit'        => 1,
			'return'       => 'ids',
			'status'       => 'cancelled',
			'exclude'      => array( $order->get_id() ),
			'date_created' => '>' . ( time() - GACCT_RELANCE_WINDOW_DAYS * DAY_IN_SECONDS ),
			'meta_key'     => GACCT_RELANCE_META_SENT_1, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		);
		if ( $order->get_customer_id() ) {
			$args['customer_id'] = $order->get_customer_id();
		} else {
			$args['billing_email'] = $order->get_billing_email();
		}
		$ids = wc_get_orders( $args );
		if ( ! empty( $ids ) ) {
			$origin = wc_get_order( (int) $ids[0] );
			if ( $origin && ! $origin->get_meta( GACCT_RELANCE_META_RECOVERED ) && 0 === strpos( (string) $origin->get_meta( GACCT_RELANCE_META_SENT_1 ), '20' ) ) {
				$campaign = 'abandon-sans-clic';
			} else {
				$origin = null;
			}
		}
	}

	if ( ! $origin || ! $campaign || $origin->get_id() === $order->get_id() ) {
		return;
	}

	$order->update_meta_data( GACCT_RELANCE_META_SOURCE, $campaign );
	$order->update_meta_data( GACCT_RELANCE_META_ORIGIN, $origin->get_id() );
	$order->save();
	$order->add_order_note( sprintf(
		/* translators: 1: campagne, 2: numéro de la commande annulée */
		__( 'Commande récupérée après relance (%1$s) : suite de la commande annulée %2$s.', 'gestion-atelier-cct' ),
		$campaign,
		$origin->get_order_number()
	) );

	$origin->update_meta_data( GACCT_RELANCE_META_RECOVERED, $order->get_id() );
	$origin->update_meta_data( GACCT_RELANCE_META_STOP, 'commande-' . $order->get_id() );
	$origin->save();
	$origin->add_order_note( sprintf(
		/* translators: 1: numéro de la nouvelle commande, 2: campagne */
		__( 'Le client a repassé commande (%1$s) après la relance %2$s.', 'gestion-atelier-cct' ),
		$order->get_order_number(),
		$campaign
	) );
}

/* =============================================================================
 *  FICHE COMMANDE : origine « relance » visible par l'atelier
 * ============================================================================= */

add_action( 'woocommerce_admin_order_data_after_order_details', function ( $order ) {
	$source = (string) $order->get_meta( GACCT_RELANCE_META_SOURCE );
	$recov  = (int) $order->get_meta( GACCT_RELANCE_META_RECOVERED );
	if ( ! $source && ! $recov ) {
		return;
	}
	echo '<p class="form-field form-field-wide"><strong>' . esc_html__( 'Relance', 'gestion-atelier-cct' ) . '</strong><br>';
	if ( $source ) {
		$origin = (int) $order->get_meta( GACCT_RELANCE_META_ORIGIN );
		printf(
			/* translators: 1: campagne, 2: lien commande d'origine */
			esc_html__( 'Commande récupérée après la relance %1$s (suite de %2$s).', 'gestion-atelier-cct' ),
			'<code>' . esc_html( $source ) . '</code>',
			$origin ? '<a href="' . esc_url( admin_url( 'post.php?post=' . $origin . '&action=edit' ) ) . '">#' . $origin . '</a>' : '?'
		);
	} else {
		printf(
			/* translators: %s: lien nouvelle commande */
			esc_html__( 'Le client a repassé commande après la relance : %s.', 'gestion-atelier-cct' ),
			'<a href="' . esc_url( admin_url( 'post.php?post=' . $recov . '&action=edit' ) ) . '">#' . $recov . '</a>'
		);
	}
	echo '</p>';
} );
