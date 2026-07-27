<?php
/**
 * Suivi des paiements par virement + paniers abandonnés.
 *
 * - Référence de commande lisible ({prefix}-{année}-{id}) via woocommerce_order_number.
 * - Réglages atelier (délais, textes d'emails) : sous-menu "Paiements & relances".
 * - Cron horaire : relance virement, annulation auto (créneau libéré), relance panier abandonné.
 * - Cron quotidien (minuit, heure du site) : purge des CCT brouillons orphelins.
 *
 * Toutes les valeurs par défaut sont filtrables (white-label) :
 * `gacct_pay_default_settings`, `gacct_pay_bank_details`, `gacct_pay_email_variables`.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_PAY_SETTINGS_OPT', 'gacct_payment_settings' );
define( 'GACCT_PAY_ABANDONED_LOG_OPT', 'gacct_pay_abandoned_log' );
define( 'GACCT_PAY_HOURLY_EVENT', 'gacct_pay_hourly_tick' );
define( 'GACCT_PAY_MIDNIGHT_EVENT', 'gacct_pay_midnight_purge' );
define( 'GACCT_PAY_META_REMINDER_SENT', '_gacct_bacs_reminder_sent' );
define( 'GACCT_PAY_META_AUTO_CANCELLED', '_gacct_bacs_auto_cancelled' );
define( 'GACCT_PAY_NONCE', 'gacct_save_payments' );
define( 'GACCT_PAY_PAGE_SLUG', 'gacct-payments' );

/* =============================================================================
 *  RÉGLAGES
 * ============================================================================= */

function gacct_pay_default_settings() {
	$defaults = array(
		'ref_prefix'        => 'AR',
		'reminder_days'     => 2,   // relance virement : X jours après la commande.
		'cancel_days'       => 3,   // annulation : X jours après la commande.
		'abandoned_minutes' => 60,  // relance panier abandonné : X minutes après la création du CCT.
		'contact_phone'     => '02 31 69 39 31',
		'contact_hours'     => 'du lundi au vendredi de 9 h 30 à 17 h 30',
		'emails'            => array(
			'bacs_reminder' => array(
				'enabled' => true,
				'label'   => __( 'Relance paiement par virement', 'gestion-atelier-cct' ),
				'subject' => __( 'Votre virement est attendu avant le {deadline_date} - commande {order_number}', 'gestion-atelier-cct' ),
				'body'    => '<p>Bonjour {customer_name},</p>'
					. '<p>Nous n’avons pas encore recu le virement d’acompte de <strong>{deposit_amount}</strong> pour votre commande <strong>{order_number}</strong>.</p>'
					. '<p>Votre creneau atelier reste retenu jusqu’au <strong>{deadline_date}</strong>. Sans reception du virement a cette date, le creneau sera libere et la commande annulee automatiquement.</p>'
					. '<p>Reference a indiquer imperativement dans le libelle du virement : <strong>{order_number}</strong></p>'
					. '{bank_details}'
					. '<p>Vous pouvez retrouver ces coordonnees a tout moment sur <a href="{order_url}">votre page de commande</a>.</p>'
					. '<p>Si votre virement est deja parti, vous n’avez rien a faire : un delai bancaire de 1 a 3 jours ouvres est normal.</p>'
					. '<p>A tres vite,<br><br>Bastien.</p>',
			),
			'bacs_cancel' => array(
				'enabled' => true,
				'label'   => __( 'Annulation : virement non recu', 'gestion-atelier-cct' ),
				'subject' => __( 'Votre commande {order_number} a ete annulee : virement non recu', 'gestion-atelier-cct' ),
				'body'    => '<p>Bonjour {customer_name},</p>'
					. '<p>Nous n’avons pas recu le virement d’acompte pour votre commande <strong>{order_number}</strong> avant l’echeance du <strong>{deadline_date}</strong>.</p>'
					. '<p>Comme prevu, le creneau atelier qui vous etait reserve a ete libere et la commande annulee.</p>'
					. '<p>Vous pouvez bien sur repasser une commande a tout moment sur les dates encore disponibles : <a href="{new_request_url}">deposer une nouvelle demande</a>.</p>'
					. '<p>Si votre virement est parti tardivement et arrive chez nous apres cette annulation, contactez-nous : nous trouverons une solution ensemble.</p>'
					. '<p>A bientot,<br><br>Bastien.</p>',
			),
			'abandoned' => array(
				'enabled' => true,
				'label'   => __( 'Panier abandonne (commande non finalisee)', 'gestion-atelier-cct' ),
				'subject' => __( 'Votre demande d’intervention n’est pas finalisee', 'gestion-atelier-cct' ),
				'body'    => '<p>Bonjour {customer_name},</p>'
					. '<p>Vous avez prepare une demande d’intervention pour votre materiel, mais la commande n’a pas ete finalisee.</p>'
					. '<p>Votre selection est toujours dans votre panier : <a href="{checkout_url}">finaliser ma commande</a>.</p>'
					. '<p>Attention : les demandes non finalisees sont supprimees chaque nuit. Passe ce delai, il faudra refaire votre demande (le creneau choisi n’est pas garanti).</p>'
					. '<p>Besoin d’aide ? Repondez simplement a cet e-mail.</p>'
					. '<p>A tres vite,<br><br>Bastien.</p>',
			),
		),
	);

	return apply_filters( 'gacct_pay_default_settings', $defaults );
}

function gacct_pay_settings() {
	$defaults = gacct_pay_default_settings();
	$saved    = get_option( GACCT_PAY_SETTINGS_OPT, array() );

	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	$settings = array_merge( $defaults, $saved );

	// Fusion des emails champ par champ (un template vide retombe sur le défaut).
	$settings['emails'] = array();
	foreach ( $defaults['emails'] as $key => $default_email ) {
		$saved_email = isset( $saved['emails'][ $key ] ) && is_array( $saved['emails'][ $key ] ) ? $saved['emails'][ $key ] : array();
		$settings['emails'][ $key ] = array_merge( $default_email, $saved_email );
		$settings['emails'][ $key ]['label'] = $default_email['label'];
	}

	$settings['reminder_days']     = max( 1, (int) $settings['reminder_days'] );
	$settings['cancel_days']       = max( (int) $settings['reminder_days'], (int) $settings['cancel_days'] );
	$settings['abandoned_minutes'] = max( 15, (int) $settings['abandoned_minutes'] );

	return $settings;
}

/* =============================================================================
 *  RÉFÉRENCE DE COMMANDE {prefix}-{année}-{id}
 * ============================================================================= */

add_filter( 'woocommerce_order_number', 'gacct_pay_order_number', 10, 2 );

function gacct_pay_order_number( $number, $order ) {
	if ( ! $order instanceof WC_Order ) {
		return $number;
	}

	$settings = gacct_pay_settings();
	$prefix   = trim( (string) $settings['ref_prefix'] );

	if ( '' === $prefix ) {
		return $number;
	}

	$created = $order->get_date_created();
	$year    = $created ? $created->date_i18n( 'Y' ) : wp_date( 'Y' );

	return sprintf( '%s-%s-%d', strtoupper( $prefix ), $year, $order->get_id() );
}

/* =============================================================================
 *  ÉCHÉANCES D'UNE COMMANDE VIREMENT
 * ============================================================================= */

/**
 * Timestamps (UTC) de relance et d'annulation pour une commande.
 *
 * @return array{reminder:int,cancel:int}
 */
function gacct_pay_order_deadlines( $order ) {
	$settings = gacct_pay_settings();
	$created  = $order->get_date_created();
	$base     = $created ? $created->getTimestamp() : time();

	return array(
		'reminder' => $base + $settings['reminder_days'] * DAY_IN_SECONDS,
		'cancel'   => $base + $settings['cancel_days'] * DAY_IN_SECONDS,
	);
}

function gacct_pay_format_date( $timestamp ) {
	return wp_date( get_option( 'date_format' ), (int) $timestamp );
}

/**
 * La commande attend-elle un virement ? (bacs + statut en attente)
 */
function gacct_pay_order_awaits_transfer( $order ) {
	return $order instanceof WC_Order
		&& 'bacs' === $order->get_payment_method()
		&& $order->has_status( array( 'on-hold', 'pending' ) );
}

/* =============================================================================
 *  COORDONNÉES BANCAIRES (source : réglages WooCommerce > Virement bancaire)
 * ============================================================================= */

/**
 * Lignes label/valeur des coordonnées bancaires, depuis la passerelle BACS.
 *
 * @return array<int,array{label:string,value:string,copy:string,highlight:bool}>
 */
function gacct_pay_bank_rows( $order = null ) {
	$rows = array();

	if ( $order instanceof WC_Order ) {
		$rows[] = array(
			'label'     => __( 'Reference a indiquer, obligatoire', 'gestion-atelier-cct' ),
			'value'     => $order->get_order_number(),
			'copy'      => $order->get_order_number(),
			'highlight' => true,
		);
	}

	$accounts = get_option( 'woocommerce_bacs_accounts', array() );
	$account  = is_array( $accounts ) && ! empty( $accounts ) ? (array) $accounts[0] : array();

	$map = array(
		'account_name' => __( 'Titulaire du compte', 'gestion-atelier-cct' ),
		'iban'         => __( 'IBAN', 'gestion-atelier-cct' ),
		'bic'          => __( 'BIC', 'gestion-atelier-cct' ),
		'bank_name'    => __( 'Banque', 'gestion-atelier-cct' ),
	);

	foreach ( $map as $key => $label ) {
		$value = isset( $account[ $key ] ) ? trim( (string) $account[ $key ] ) : '';
		if ( '' === $value ) {
			continue;
		}
		$rows[] = array(
			'label'     => $label,
			'value'     => 'iban' === $key ? trim( chunk_split( str_replace( ' ', '', $value ), 4, ' ' ) ) : $value,
			'copy'      => str_replace( ' ', '', $value ),
			'highlight' => false,
		);
	}

	if ( $order instanceof WC_Order ) {
		$rows[] = array(
			'label'     => __( 'Montant exact', 'gestion-atelier-cct' ),
			'value'     => wp_strip_all_tags( wc_price( (float) $order->get_total() ) ),
			'copy'      => number_format( (float) $order->get_total(), 2, ',', '' ),
			'highlight' => false,
		);
	}

	return apply_filters( 'gacct_pay_bank_details', $rows, $order );
}

/**
 * Version HTML (table simple) pour les emails.
 */
function gacct_pay_bank_details_html( $order = null ) {
	$rows = gacct_pay_bank_rows( $order );

	if ( empty( $rows ) ) {
		return '';
	}

	$html = '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:12px 0;">';
	foreach ( $rows as $row ) {
		$html .= '<tr>'
			. '<td style="border:1px solid #ddd;font-size:12px;color:#666;">' . esc_html( $row['label'] ) . '</td>'
			. '<td style="border:1px solid #ddd;font-weight:bold;">' . esc_html( $row['value'] ) . '</td>'
			. '</tr>';
	}
	$html .= '</table>';

	return $html;
}

/* =============================================================================
 *  EMAILS
 * ============================================================================= */

function gacct_pay_email_variables( $order = null, array $extra = array() ) {
	$variables = array(
		'{site_name}'       => get_bloginfo( 'name' ),
		'{new_request_url}' => esc_url( home_url( '/demande-intervention/' ) ),
		'{checkout_url}'    => esc_url( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/commander/' ) ),
	);

	if ( $order instanceof WC_Order ) {
		$deadlines = gacct_pay_order_deadlines( $order );

		$variables['{customer_name}']  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$variables['{order_number}']   = $order->get_order_number();
		$variables['{order_id}']       = (string) $order->get_id();
		$variables['{deposit_amount}'] = wp_strip_all_tags( wc_price( (float) $order->get_total() ) );
		$variables['{deadline_date}']  = gacct_pay_format_date( $deadlines['cancel'] );
		$variables['{bank_details}']   = gacct_pay_bank_details_html( $order );
		$variables['{order_url}']      = esc_url( $order->get_checkout_order_received_url() );
	}

	return apply_filters( 'gacct_pay_email_variables', array_merge( $variables, $extra ), $order );
}

function gacct_pay_send_email( $to, $template_key, array $variables, $copy_admin = false ) {
	$settings = gacct_pay_settings();
	$email    = isset( $settings['emails'][ $template_key ] ) ? $settings['emails'][ $template_key ] : null;

	if ( ! $email || empty( $email['enabled'] ) || ! is_email( $to ) ) {
		return false;
	}

	$subject = strtr( (string) $email['subject'], $variables );
	$body    = strtr( (string) $email['body'], $variables );
	$message = $body;

	if ( function_exists( 'WC' ) && WC() && WC()->mailer() ) {
		$message = WC()->mailer()->wrap_message( wp_strip_all_tags( $subject ), $body );
	}

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	$sent = wp_mail( $to, wp_strip_all_tags( $subject ), $message, $headers );

	if ( $copy_admin ) {
		$admin = gacct_pay_admin_email();
		if ( is_email( $admin ) && $admin !== $to ) {
			wp_mail( $admin, '[Copie admin] ' . wp_strip_all_tags( $subject ), $message, $headers );
		}
	}

	return $sent;
}

/**
 * Email admin : réutilise celui de la page Notifications du plugin si défini.
 */
function gacct_pay_admin_email() {
	$notif = get_option( 'gacct_notification_settings', array() );

	if ( is_array( $notif ) && ! empty( $notif['admin_email'] ) && is_email( $notif['admin_email'] ) ) {
		return $notif['admin_email'];
	}

	return get_option( 'admin_email' );
}

/* =============================================================================
 *  CRON — PLANIFICATION
 * ============================================================================= */

add_action( 'init', 'gacct_pay_schedule_events' );

function gacct_pay_schedule_events() {
	if ( ! wp_next_scheduled( GACCT_PAY_HOURLY_EVENT ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', GACCT_PAY_HOURLY_EVENT );
	}

	if ( ! wp_next_scheduled( GACCT_PAY_MIDNIGHT_EVENT ) ) {
		// Prochain minuit, heure du site.
		$midnight = strtotime( 'tomorrow midnight', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		wp_schedule_event( $midnight, 'daily', GACCT_PAY_MIDNIGHT_EVENT );
	}
}

register_deactivation_hook( dirname( __DIR__ ) . '/gestion-atelier-cct.php', 'gacct_pay_unschedule_events' );

function gacct_pay_unschedule_events() {
	wp_clear_scheduled_hook( GACCT_PAY_HOURLY_EVENT );
	wp_clear_scheduled_hook( GACCT_PAY_MIDNIGHT_EVENT );
}

/* =============================================================================
 *  CRON — TICK HORAIRE
 * ============================================================================= */

add_action( GACCT_PAY_HOURLY_EVENT, 'gacct_pay_hourly_tick' );

function gacct_pay_hourly_tick() {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return;
	}

	gacct_pay_process_bacs_orders();
	gacct_pay_process_abandoned_carts();
}

/**
 * Relance puis annulation automatique des commandes en attente de virement.
 */
function gacct_pay_process_bacs_orders() {
	$orders = wc_get_orders(
		array(
			'status'         => array( 'on-hold', 'pending' ),
			'payment_method' => 'bacs',
			'limit'          => 100,
			'orderby'        => 'date',
			'order'          => 'ASC',
		)
	);

	$now = time();

	foreach ( $orders as $order ) {
		if ( ! gacct_pay_order_awaits_transfer( $order ) ) {
			continue;
		}

		$deadlines = gacct_pay_order_deadlines( $order );

		// 1. Annulation : échéance dépassée.
		if ( $now >= $deadlines['cancel'] ) {
			gacct_pay_cancel_unpaid_order( $order, $deadlines );
			continue;
		}

		// 2. Relance : délai atteint, pas encore relancé.
		if ( $now >= $deadlines['reminder'] && ! $order->get_meta( GACCT_PAY_META_REMINDER_SENT ) ) {
			$sent = gacct_pay_send_email(
				$order->get_billing_email(),
				'bacs_reminder',
				gacct_pay_email_variables( $order )
			);

			$order->update_meta_data( GACCT_PAY_META_REMINDER_SENT, current_time( 'mysql' ) );
			$order->add_order_note(
				$sent
					? sprintf(
						/* translators: 1: email, 2: date limite */
						__( 'Relance paiement par virement envoyee au client (%1$s). Echeance avant annulation : %2$s.', 'gestion-atelier-cct' ),
						$order->get_billing_email(),
						gacct_pay_format_date( $deadlines['cancel'] )
					)
					: __( 'Echec d’envoi de la relance paiement par virement (wp_mail a retourne false).', 'gestion-atelier-cct' )
			);
			$order->save();
		}
	}
}

/**
 * Annule une commande virement non payée : libère le créneau, supprime les CCT,
 * prévient le client (+ copie admin) et journalise tout dans la commande.
 */
function gacct_pay_cancel_unpaid_order( $order, array $deadlines ) {
	if ( $order->get_meta( GACCT_PAY_META_AUTO_CANCELLED ) ) {
		return;
	}

	$revision_id   = (int) $order->get_meta( JWCCT_ORDER_REVISION_ID );
	$occupation_id = (int) $order->get_meta( JWCCT_ORDER_OCCUPATION_ID );

	// Email AVANT suppression des données (les variables en dépendent).
	$sent = gacct_pay_send_email(
		$order->get_billing_email(),
		'bacs_cancel',
		gacct_pay_email_variables( $order ),
		true // copie admin
	);

	$deleted = array();

	if ( $occupation_id && gacct_pay_delete_cct_item( JWCCT_CCT_OCCUPATION, $occupation_id ) ) {
		$deleted[] = 'occupation #' . $occupation_id;
	}
	if ( $revision_id && gacct_pay_delete_cct_item( JWCCT_CCT_REVISION, $revision_id ) ) {
		$deleted[] = 'revision #' . $revision_id;
	}

	gacct_pay_delete_cct_relations( $revision_id, $occupation_id, $order->get_id() );

	$order->update_meta_data( GACCT_PAY_META_AUTO_CANCELLED, current_time( 'mysql' ) );
	$order->save();

	$order->add_order_note(
		sprintf(
			/* translators: 1: date limite, 2: liste des CCT supprimes, 3: statut email */
			__( 'Annulation automatique : virement non recu avant le %1$s. Creneau libere (%2$s). Email d’annulation au client : %3$s (copie admin).', 'gestion-atelier-cct' ),
			gacct_pay_format_date( $deadlines['cancel'] ),
			$deleted ? implode( ', ', $deleted ) : __( 'aucun CCT a supprimer', 'gestion-atelier-cct' ),
			$sent ? __( 'envoye', 'gestion-atelier-cct' ) : __( 'ECHEC', 'gestion-atelier-cct' )
		)
	);

	$order->update_status( 'cancelled', __( 'Virement non recu dans le delai imparti.', 'gestion-atelier-cct' ) );
}

/* =============================================================================
 *  CRON — PANIERS ABANDONNÉS (CCT draft sans commande)
 * ============================================================================= */

function gacct_pay_process_abandoned_carts() {
	global $wpdb;

	$settings = gacct_pay_settings();
	$table    = gacct_pay_cct_table( JWCCT_CCT_REVISION );

	if ( ! $table ) {
		return;
	}

	$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $settings['abandoned_minutes'] * MINUTE_IN_SECONDS );

	// Fenetre de fraicheur : on ne relance que les abandons recents (moins de 24 h).
	// Les brouillons plus vieux partent a la purge de minuit sans relance.
	$oldest = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS );

	// Brouillons sans commande, assez vieux, avec un auteur identifiable.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT _ID, cct_author_id, cct_created FROM {$table}
			 WHERE cct_status = 'draft'
			   AND ( order_id IS NULL OR order_id = 0 )
			   AND cct_author_id > 0
			   AND cct_created < %s
			   AND cct_created > %s",
			$cutoff,
			$oldest
		),
		ARRAY_A
	);

	if ( empty( $rows ) ) {
		return;
	}

	$log     = get_option( GACCT_PAY_ABANDONED_LOG_OPT, array() );
	$log     = is_array( $log ) ? $log : array();
	$changed = false;

	foreach ( $rows as $row ) {
		$cct_id = (int) $row['_ID'];

		if ( isset( $log[ $cct_id ] ) ) {
			continue; // déjà relancé.
		}

		$user = get_userdata( (int) $row['cct_author_id'] );

		if ( $user && is_email( $user->user_email ) ) {
			gacct_pay_send_email(
				$user->user_email,
				'abandoned',
				gacct_pay_email_variables(
					null,
					array( '{customer_name}' => $user->display_name ? $user->display_name : $user->user_login )
				)
			);
		}

		$log[ $cct_id ] = time();
		$changed        = true;
	}

	if ( $changed ) {
		update_option( GACCT_PAY_ABANDONED_LOG_OPT, $log, false );
	}
}

/* =============================================================================
 *  CRON — PURGE DE MINUIT (brouillons orphelins)
 * ============================================================================= */

add_action( GACCT_PAY_MIDNIGHT_EVENT, 'gacct_pay_midnight_purge' );

function gacct_pay_midnight_purge() {
	global $wpdb;

	// Garde-fou : ne jamais purger un brouillon de moins de 2 h
	// (quelqu'un peut être en train de finaliser sa commande).
	$min_age = (int) apply_filters( 'gacct_pay_purge_min_age', 2 * HOUR_IN_SECONDS );
	$cutoff  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $min_age );

	$purged = array();

	foreach ( array( JWCCT_CCT_REVISION, JWCCT_CCT_OCCUPATION ) as $slug ) {
		$table = gacct_pay_cct_table( $slug );

		if ( ! $table ) {
			continue;
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT _ID FROM {$table}
				 WHERE cct_status = 'draft'
				   AND ( order_id IS NULL OR order_id = 0 )
				   AND cct_created < %s",
				$cutoff
			)
		);

		foreach ( $ids as $id ) {
			if ( gacct_pay_delete_cct_item( $slug, (int) $id ) ) {
				$purged[ $slug ][] = (int) $id;
			}
		}
	}

	// Relations orphelines des révisions supprimées.
	if ( ! empty( $purged[ JWCCT_CCT_REVISION ] ) ) {
		foreach ( $purged[ JWCCT_CCT_REVISION ] as $rev_id ) {
			gacct_pay_delete_cct_relations( $rev_id, 0, 0 );
		}
	}

	// Metas "pending" des utilisateurs pointant vers des CCT supprimés.
	$meta_map = array(
		JWCCT_META_REVISION_ID   => isset( $purged[ JWCCT_CCT_REVISION ] ) ? $purged[ JWCCT_CCT_REVISION ] : array(),
		JWCCT_META_OCCUPATION_ID => isset( $purged[ JWCCT_CCT_OCCUPATION ] ) ? $purged[ JWCCT_CCT_OCCUPATION ] : array(),
	);

	foreach ( $meta_map as $meta_key => $ids ) {
		foreach ( $ids as $id ) {
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %d",
					$meta_key,
					$id
				)
			);
			foreach ( $user_ids as $user_id ) {
				jwcct_clear_pending_ids( (int) $user_id );
			}
		}
	}

	// Journal des relances abandonnées : on repart à zéro (les CCT concernés sont purgés).
	update_option( GACCT_PAY_ABANDONED_LOG_OPT, array(), false );

	if ( ! empty( $purged ) ) {
		jwcct_log( 'midnight_purge : ' . wp_json_encode( $purged ) );
	}
}

/* =============================================================================
 *  HELPERS CCT (suppression, table)
 * ============================================================================= */

function gacct_pay_cct_table( $slug ) {
	if ( class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Module' ) ) {
		$content_type = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()
			->manager
			->get_content_types( $slug );

		if ( $content_type && isset( $content_type->db ) && method_exists( $content_type->db, 'table' ) ) {
			return $content_type->db->table();
		}
	}

	global $wpdb;
	return $wpdb->prefix . 'jet_cct_' . $slug;
}

function gacct_pay_delete_cct_item( $slug, $item_id ) {
	$item_id = absint( $item_id );

	if ( ! $item_id ) {
		return false;
	}

	if ( class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Module' ) ) {
		$content_type = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()
			->manager
			->get_content_types( $slug );

		if ( $content_type && isset( $content_type->db ) ) {
			try {
				$content_type->db->delete( array( '_ID' => $item_id ) );
				return true;
			} catch ( \Throwable $e ) {
				jwcct_log( "delete_cct_item($slug, $item_id) exception : " . $e->getMessage() );
			}
		}
	}

	global $wpdb;
	$table = gacct_pay_cct_table( $slug );

	return (bool) $wpdb->delete( $table, array( '_ID' => $item_id ), array( '%d' ) );
}

/**
 * Nettoie les lignes de relations JetEngine (11, 12, 13) liées à une révision /
 * occupation / commande supprimée.
 */
function gacct_pay_delete_cct_relations( $revision_id, $occupation_id, $order_id ) {
	global $wpdb;

	$table = $wpdb->prefix . 'jet_rel_default';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return;
	}

	$revision_id   = absint( $revision_id );
	$occupation_id = absint( $occupation_id );

	if ( $revision_id ) {
		// rel 11 (revision<->occupation), 12 (revision<->commande), 13 (client<->revision).
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE rel_id IN (11, 12, 13) AND ( parent_object_id = %d OR child_object_id = %d )",
				$revision_id,
				$revision_id
			)
		);
	}

	if ( $occupation_id ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE rel_id = 11 AND ( parent_object_id = %d OR child_object_id = %d )",
				$occupation_id,
				$occupation_id
			)
		);
	}
}

/* =============================================================================
 *  PAGE ADMIN "PAIEMENTS & RELANCES"
 * ============================================================================= */

add_action( 'admin_menu', 'gacct_pay_register_admin_page', 20 );

function gacct_pay_register_admin_page() {
	$capability = apply_filters( 'gacct_admin_capability', 'manage_options' );

	add_submenu_page(
		'gacct-dashboard',
		__( 'Paiements & relances', 'gestion-atelier-cct' ),
		__( 'Paiements & relances', 'gestion-atelier-cct' ),
		$capability,
		GACCT_PAY_PAGE_SLUG,
		'gacct_pay_render_admin_page'
	);
}

function gacct_pay_handle_admin_save() {
	if ( ! isset( $_POST['_gacct_payments_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_gacct_payments_nonce'] ), GACCT_PAY_NONCE ) ) {
		return new WP_Error( 'nonce', __( 'Session expiree, merci de reessayer.', 'gestion-atelier-cct' ) );
	}

	$defaults = gacct_pay_default_settings();

	$settings = array(
		'ref_prefix'        => strtoupper( sanitize_text_field( wp_unslash( $_POST['ref_prefix'] ?? $defaults['ref_prefix'] ) ) ),
		'reminder_days'     => max( 1, absint( $_POST['reminder_days'] ?? $defaults['reminder_days'] ) ),
		'cancel_days'       => max( 1, absint( $_POST['cancel_days'] ?? $defaults['cancel_days'] ) ),
		'abandoned_minutes' => max( 15, absint( $_POST['abandoned_minutes'] ?? $defaults['abandoned_minutes'] ) ),
		'contact_phone'     => sanitize_text_field( wp_unslash( $_POST['contact_phone'] ?? $defaults['contact_phone'] ) ),
		'contact_hours'     => sanitize_text_field( wp_unslash( $_POST['contact_hours'] ?? $defaults['contact_hours'] ) ),
		'emails'            => array(),
	);

	if ( $settings['cancel_days'] < $settings['reminder_days'] ) {
		$settings['cancel_days'] = $settings['reminder_days'];
	}

	$posted_emails = isset( $_POST['emails'] ) && is_array( $_POST['emails'] ) ? wp_unslash( $_POST['emails'] ) : array();

	foreach ( $defaults['emails'] as $key => $default_email ) {
		$posted = isset( $posted_emails[ $key ] ) && is_array( $posted_emails[ $key ] ) ? $posted_emails[ $key ] : array();

		$settings['emails'][ $key ] = array(
			'enabled' => ! empty( $posted['enabled'] ),
			'label'   => $default_email['label'],
			'subject' => sanitize_text_field( $posted['subject'] ?? $default_email['subject'] ),
			'body'    => wp_kses_post( $posted['body'] ?? $default_email['body'] ),
		);
	}

	update_option( GACCT_PAY_SETTINGS_OPT, $settings, false );

	return true;
}

function gacct_pay_render_admin_page() {
	if ( ! current_user_can( apply_filters( 'gacct_admin_capability', 'manage_options' ) ) ) {
		wp_die( esc_html__( 'Acces refuse.', 'gestion-atelier-cct' ) );
	}

	$result = null;

	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['gacct_payments_submit'] ) ) {
		$result = gacct_pay_handle_admin_save();
	}

	$settings  = gacct_pay_settings();
	$bank_rows = gacct_pay_bank_rows();
	?>
	<div class="wrap gacct-wrap">
		<h1><?php esc_html_e( 'Paiements & relances', 'gestion-atelier-cct' ); ?></h1>

		<?php if ( true === $result ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Reglages enregistres.', 'gestion-atelier-cct' ); ?></p></div>
		<?php elseif ( is_wp_error( $result ) ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
		<?php endif; ?>

		<?php if ( empty( $bank_rows ) ) : ?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Coordonnees bancaires manquantes.', 'gestion-atelier-cct' ); ?></strong>
					<?php esc_html_e( 'La page de confirmation et les emails de relance affichent les coordonnees saisies dans', 'gestion-atelier-cct' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bacs' ) ); ?>">
						<?php esc_html_e( 'WooCommerce > Reglages > Paiements > Virement bancaire', 'gestion-atelier-cct' ); ?>
					</a>
					(<?php esc_html_e( 'titulaire, IBAN, BIC', 'gestion-atelier-cct' ); ?>).
				</p>
			</div>
		<?php endif; ?>

		<form class="gacct-form" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . GACCT_PAY_PAGE_SLUG ) ); ?>">
			<?php wp_nonce_field( GACCT_PAY_NONCE, '_gacct_payments_nonce' ); ?>

			<h2><?php esc_html_e( 'Reference de commande', 'gestion-atelier-cct' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="gacct_ref_prefix"><?php esc_html_e( 'Prefixe', 'gestion-atelier-cct' ); ?></label></th>
						<td>
							<input type="text" id="gacct_ref_prefix" name="ref_prefix" class="small-text" value="<?php echo esc_attr( $settings['ref_prefix'] ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: exemple de reference */
									esc_html__( 'Les commandes sont affichees partout sous la forme %s (site, emails, libelle du virement). Laisser vide pour garder le numero WooCommerce brut.', 'gestion-atelier-cct' ),
									'<code>' . esc_html( strtoupper( $settings['ref_prefix'] ? $settings['ref_prefix'] : 'AR' ) . '-' . wp_date( 'Y' ) . '-1598' ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Paiement par virement', 'gestion-atelier-cct' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="gacct_reminder_days"><?php esc_html_e( 'Delai avant relance', 'gestion-atelier-cct' ); ?></label></th>
						<td>
							<input type="number" id="gacct_reminder_days" name="reminder_days" class="small-text" min="1" value="<?php echo esc_attr( $settings['reminder_days'] ); ?>">
							<?php esc_html_e( 'jours apres la commande', 'gestion-atelier-cct' ); ?>
							<p class="description"><?php esc_html_e( 'Email de relance envoye si le virement n’est toujours pas recu (commande toujours "En attente"). Une note est ajoutee dans la commande.', 'gestion-atelier-cct' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gacct_cancel_days"><?php esc_html_e( 'Delai avant annulation', 'gestion-atelier-cct' ); ?></label></th>
						<td>
							<input type="number" id="gacct_cancel_days" name="cancel_days" class="small-text" min="1" value="<?php echo esc_attr( $settings['cancel_days'] ); ?>">
							<?php esc_html_e( 'jours apres la commande', 'gestion-atelier-cct' ); ?>
							<p class="description"><?php esc_html_e( 'Passe ce delai : commande annulee, creneau atelier libere (CCT occupation + revision supprimes), email au client avec copie admin. Doit etre superieur ou egal au delai de relance.', 'gestion-atelier-cct' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Commandes non finalisees (paniers abandonnes)', 'gestion-atelier-cct' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="gacct_abandoned_minutes"><?php esc_html_e( 'Delai avant relance', 'gestion-atelier-cct' ); ?></label></th>
						<td>
							<input type="number" id="gacct_abandoned_minutes" name="abandoned_minutes" class="small-text" min="15" step="15" value="<?php echo esc_attr( $settings['abandoned_minutes'] ); ?>">
							<?php esc_html_e( 'minutes apres la creation de la demande', 'gestion-atelier-cct' ); ?>
							<p class="description"><?php esc_html_e( 'Si un client valide le formulaire de demande sans finaliser le paiement, un email de rappel lui est envoye avec un lien vers son panier. Les demandes non finalisees sont ensuite purgees chaque nuit a minuit (jamais avant 2 h d’anciennete).', 'gestion-atelier-cct' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Coordonnees affichees au client', 'gestion-atelier-cct' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="gacct_contact_phone"><?php esc_html_e( 'Telephone atelier', 'gestion-atelier-cct' ); ?></label></th>
						<td><input type="text" id="gacct_contact_phone" name="contact_phone" class="regular-text" value="<?php echo esc_attr( $settings['contact_phone'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gacct_contact_hours"><?php esc_html_e( 'Horaires affiches', 'gestion-atelier-cct' ); ?></label></th>
						<td><input type="text" id="gacct_contact_hours" name="contact_hours" class="regular-text" value="<?php echo esc_attr( $settings['contact_hours'] ); ?>"></td>
					</tr>
				</tbody>
			</table>

			<?php foreach ( $settings['emails'] as $key => $email ) : ?>
				<h2><?php echo esc_html( $email['label'] ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Actif', 'gestion-atelier-cct' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="emails[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( ! empty( $email['enabled'] ) ); ?>>
									<?php esc_html_e( 'Envoyer cet email', 'gestion-atelier-cct' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="gacct_pay_subject_<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Objet', 'gestion-atelier-cct' ); ?></label></th>
							<td><input type="text" id="gacct_pay_subject_<?php echo esc_attr( $key ); ?>" name="emails[<?php echo esc_attr( $key ); ?>][subject]" class="large-text" value="<?php echo esc_attr( $email['subject'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Contenu', 'gestion-atelier-cct' ); ?></th>
							<td>
								<?php
								wp_editor(
									$email['body'],
									'gacct_pay_body_' . $key,
									array(
										'textarea_name' => 'emails[' . $key . '][body]',
										'textarea_rows' => 8,
										'media_buttons' => false,
										'teeny'         => true,
									)
								);
								?>
								<p class="description">
									<?php esc_html_e( 'Variables disponibles :', 'gestion-atelier-cct' ); ?>
									<code>{customer_name}</code>
									<code>{order_number}</code>
									<code>{order_id}</code>
									<code>{deposit_amount}</code>
									<code>{deadline_date}</code>
									<code>{bank_details}</code>
									<code>{order_url}</code>
									<code>{checkout_url}</code>
									<code>{new_request_url}</code>
									<code>{site_name}</code>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			<?php endforeach; ?>

			<?php submit_button( __( 'Enregistrer les reglages', 'gestion-atelier-cct' ), 'primary', 'gacct_payments_submit' ); ?>
		</form>
	</div>
	<?php
}
