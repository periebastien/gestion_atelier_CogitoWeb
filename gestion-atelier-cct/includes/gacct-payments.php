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
		'abandoned_minutes' => 60,  // relance commande non finalisée : X minutes après création.
		'unfinished_hours'  => 6,   // suppression d'une commande non finalisée : X heures d'ancienneté minimum,
		                            // effective au premier passage de minuit qui suit.
		'contact_phone'     => '02 31 69 39 31',
		'contact_hours'     => 'du lundi au vendredi de 9 h 30 à 17 h 30',
		'emails'            => array(
			'bacs_reminder' => array(
				'enabled' => true,
				'label'   => __( 'Relance paiement par virement', 'gestion-atelier-cct' ),
				'subject' => __( 'Votre virement est attendu avant le {deadline_date} - commande {order_number}', 'gestion-atelier-cct' ),
				'body'    => '<p>Bonjour {customer_name},</p>'
					. '<p>Nous n’avons pas encore reçu le virement d’acompte de <strong>{deposit_amount}</strong> pour votre commande <strong>{order_number}</strong>.</p>'
					. '<p><strong>Il vous reste {days_remaining} pour effectuer ce virement.</strong> Votre créneau atelier reste retenu jusqu’au <strong>{deadline_date}</strong>. Sans réception du virement à cette date, le créneau sera libéré et la commande annulée automatiquement.</p>'
					. '<p>Référence à indiquer impérativement dans le libellé du virement : <strong>{order_number}</strong></p>'
					. '{bank_details}'
					. '<p>Vous pouvez retrouver ces coordonnées à tout moment sur <a href="{order_url}">votre page de commande</a>.</p>'
					. '<p>Si votre virement est déjà parti, vous n’avez rien à faire : un délai bancaire de 1 à 3 jours ouvrés est normal.</p>'
					. '<p>À très vite,<br><br>Bastien.</p>',
			),
			'bacs_cancel' => array(
				'enabled' => true,
				'label'   => __( 'Annulation : virement non reçu', 'gestion-atelier-cct' ),
				'subject' => __( 'Votre commande {order_number} a été annulée : virement non reçu', 'gestion-atelier-cct' ),
				'body'    => '<p>Bonjour {customer_name},</p>'
					. '<p>Nous n’avons pas reçu le virement d’acompte pour votre commande <strong>{order_number}</strong> avant l’échéance du <strong>{deadline_date}</strong>.</p>'
					. '<p>Comme prévu, le créneau atelier qui vous était réservé a été libéré et la commande annulée.</p>'
					. '<p>Vous pouvez bien sûr repasser une commande à tout moment sur les dates encore disponibles : <a href="{new_request_url}">déposer une nouvelle demande</a>.</p>'
					. '<p>Si votre virement est parti tardivement et arrive chez nous après cette annulation, contactez-nous : nous trouverons une solution ensemble.</p>'
					. '<p>À bientôt,<br><br>Bastien.</p>',
			),
			'abandoned' => array(
				'enabled' => true,
				'label'   => __( 'Demande non finalisée (aucune commande passée)', 'gestion-atelier-cct' ),
				'subject' => __( 'Votre demande d’intervention n’est pas finalisée', 'gestion-atelier-cct' ),
				'body'    => '<p>Bonjour {customer_name},</p>'
					. '<p>Vous avez préparé une demande d’intervention pour votre matériel, mais la commande n’a pas été finalisée.</p>'
					. '<p>Votre sélection est toujours dans votre panier : <a href="{checkout_url}">finaliser ma commande</a>.</p>'
					. '<p><strong>Il vous reste {time_remaining} pour la finaliser.</strong> Sans validation avant le <strong>{delete_deadline}</strong>, votre demande sera supprimée et il faudra la refaire (le créneau choisi ne sera plus garanti).</p>'
					. '<p>Besoin d’aide ? Répondez simplement à cet e-mail.</p>'
					. '<p>À très vite,<br><br>Bastien.</p>',
			),
			'payment_failed' => array(
				'enabled' => true,
				'label'   => __( 'Paiement non abouti (commande passée mais non payée)', 'gestion-atelier-cct' ),
				'subject' => __( 'Votre paiement n’a pas abouti - commande {order_number}', 'gestion-atelier-cct' ),
				'body'    => '<p>Bonjour {customer_name},</p>'
					. '<p>Votre commande <strong>{order_number}</strong> a bien été enregistrée, mais le paiement de l’acompte de <strong>{deposit_amount}</strong> n’a pas abouti.</p>'
					. '<p>Votre créneau atelier est encore retenu : vous pouvez reprendre le paiement en un clic, sans refaire votre demande. <a href="{payment_url}">Reprendre le paiement</a>.</p>'
					. '<p><strong>Il vous reste {time_remaining}.</strong> Sans paiement avant le <strong>{delete_deadline}</strong>, la commande sera annulée et le créneau libéré pour d’autres clients.</p>'
					. '<p>Si vous rencontrez un souci avec votre moyen de paiement, répondez à cet e-mail ou appelez-nous : nous trouverons une solution.</p>'
					. '<p>À très vite,<br><br>Bastien.</p>',
			),
			'noshow_release' => array(
				'enabled' => true,
				'label'   => __( 'Créneau libéré : matériel jamais reçu (acompte conservé)', 'gestion-atelier-cct' ),
				'subject' => __( 'Votre créneau du {slot_date} a dû être libéré - commande {order_number}', 'gestion-atelier-cct' ),
				'body'    => '<p>Bonjour {customer_name},</p>'
					. '<p>Nous n’avons malheureusement pas reçu votre matériel pour le créneau du <strong>{slot_date}</strong>, réservé pour votre commande <strong>{order_number}</strong>.</p>'
					. '<p>Comme indiqué lors de votre commande, ce créneau a été libéré. L’acompte de <strong>{deposit_amount}</strong> reste acquis à l’atelier : il couvre le créneau qui vous était réservé et qui n’a pas pu être proposé à un autre client.</p>'
					. '<p>Votre dossier n’est pas perdu pour autant : si vous souhaitez replanifier l’intervention, contactez-nous au <strong>{contact_phone}</strong> ({contact_hours}) ou répondez à cet e-mail, nous trouverons une nouvelle date ensemble.</p>'
					. '<p>Et si votre colis est en route avec du retard, faites-nous signe dès maintenant : nous en tiendrons compte.</p>'
					. '<p>À bientôt,<br><br>Bastien.</p>',
			),
			'rescheduled' => array(
				'enabled' => true,
				'label'   => __( 'Créneau replanifié', 'gestion-atelier-cct' ),
				'subject' => __( 'Votre créneau atelier a été déplacé au {new_slot_date} - commande {order_number}', 'gestion-atelier-cct' ),
				'body'    => '<p>Bonjour {customer_name},</p>'
					. '<p>Le créneau atelier de votre commande <strong>{order_number}</strong>, initialement prévu le <strong>{old_slot_date}</strong>, a été déplacé au <strong>{new_slot_date}</strong>.</p>'
					. '<p>Si votre matériel n’est pas encore parti, il doit désormais nous parvenir <strong>avant le {new_slot_date}</strong>.</p>'
					. '<p>Ce nouveau créneau ne vous convient pas ? Répondez à cet e-mail ou appelez-nous au <strong>{contact_phone}</strong> ({contact_hours}) : nous trouverons une autre date ensemble.</p>'
					. '<p>À très vite,<br><br>Bastien.</p>',
			),
			'missing_items' => array(
				'enabled' => true,
				'label'   => __( 'Réception partielle : éléments manquants', 'gestion-atelier-cct' ),
				'subject' => __( 'Votre colis est bien arrivé, mais il manque des éléments - commande {order_number}', 'gestion-atelier-cct' ),
				'body'    => '<p>Bonjour {customer_name},</p>'
					. '<p>Bonne nouvelle : votre colis pour la commande <strong>{order_number}</strong> est bien arrivé à l’atelier.</p>'
					. '<p>En le déballant, nous avons toutefois constaté qu’il manque :</p>'
					. '{missing_items}'
					. '<p>Pour que nous puissions réaliser l’intervention complète, merci de nous faire parvenir ces éléments dès que possible à l’adresse suivante :</p>'
					. '<p><strong>{workshop_address}</strong></p>'
					. '<p>Sans ces éléments, la partie correspondante de l’intervention ne pourra pas être réalisée. Si vous préférez y renoncer ou si vous avez la moindre question, répondez simplement à cet e-mail ou appelez-nous au <strong>{contact_phone}</strong> ({contact_hours}).</p>'
					. '<p>À très vite,<br><br>Bastien.</p>',
			),
			'unfinished_cancel' => array(
				'enabled' => true,
				'label'   => __( 'Annulation : commande non payée', 'gestion-atelier-cct' ),
				'subject' => __( 'Votre commande {order_number} a été annulée : paiement non reçu', 'gestion-atelier-cct' ),
				'body'    => '<p>Bonjour {customer_name},</p>'
					. '<p>Le paiement de votre commande <strong>{order_number}</strong> n’a pas abouti dans le délai imparti.</p>'
					. '<p>Le créneau atelier qui vous était réservé a donc été libéré et la commande annulée.</p>'
					. '<p>Vous pouvez bien sûr en repasser une à tout moment sur les dates encore disponibles : <a href="{new_request_url}">déposer une nouvelle demande</a>.</p>'
					. '<p>À bientôt,<br><br>Bastien.</p>',
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
	$settings['unfinished_hours']  = max( 2, (int) $settings['unfinished_hours'] );

	// La suppression ne doit jamais precéder la relance.
	$settings['unfinished_hours'] = max(
		$settings['unfinished_hours'],
		(int) ceil( $settings['abandoned_minutes'] / 60 ) + 1
	);

	return $settings;
}

/**
 * Moment exact de suppression d'une commande / demande non finalisee.
 *
 * La purge tourne a minuit : l'element est supprime au premier passage de minuit
 * ou il aura depasse l'anciennete minimale configuree. On calcule ce moment pour
 * pouvoir l'annoncer au client dans l'email (pas d'echeance approximative).
 *
 * @param int $created_ts Timestamp de creation (UTC).
 * @return int Timestamp de suppression (UTC).
 */
function gacct_pay_deletion_deadline( $created_ts ) {
	$settings = gacct_pay_settings();
	$eligible = (int) $created_ts + $settings['unfinished_hours'] * HOUR_IN_SECONDS;

	$date     = ( new DateTimeImmutable( '@' . $eligible ) )->setTimezone( wp_timezone() );
	$midnight = $date->setTime( 0, 0, 0 );

	if ( $midnight->getTimestamp() < $eligible ) {
		$midnight = $midnight->modify( '+1 day' );
	}

	return $midnight->getTimestamp();
}

/**
 * Delai restant en clair ("environ 11 heures", "2 jours"), pour les emails.
 */
function gacct_pay_time_remaining( $deadline_ts ) {
	$now = time();

	if ( $deadline_ts <= $now ) {
		return __( 'moins d’une heure', 'gestion-atelier-cct' );
	}

	return human_time_diff( $now, $deadline_ts );
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

/**
 * Libellé de statut lisible par le client, pour UNE commande donnée.
 *
 * WooCommerce affiche « En attente » pour le statut `on-hold`, qui est générique :
 * il sert aussi au chèque, aux mises en attente manuelles, à d'autres passerelles.
 * On ne renomme donc PAS le statut globalement (filtre `wc_order_statuses`), ce qui
 * mentirait sur toutes ces commandes-là : on précise le libellé au cas par cas,
 * uniquement quand il s'agit bien d'un virement en attente.
 *
 * @param WC_Order|mixed $order
 * @return string
 */
function gacct_pay_order_status_label( $order ) {

	if ( ! $order instanceof WC_Order ) {
		return '';
	}

	$label = gacct_pay_order_awaits_transfer( $order )
		? __( 'En attente de virement', 'gestion-atelier-cct' )
		: wc_get_order_status_name( $order->get_status() );

	return apply_filters( 'gacct_pay_order_status_label', $label, $order );
}

/**
 * Colonne « Statut » de la liste des commandes de l'espace client.
 *
 * Le template `myaccount/orders.php` de WooCommerce donne la priorité à cette
 * action sur son propre rendu : on reprend la main sans surcharger de template.
 */
add_action( 'woocommerce_my_account_my_orders_column_order-status', 'gacct_pay_my_orders_status_column' );

function gacct_pay_my_orders_status_column( $order ) {
	echo esc_html( gacct_pay_order_status_label( $order ) );
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
	$settings = gacct_pay_settings();

	$variables = array(
		'{site_name}'       => get_bloginfo( 'name' ),
		'{new_request_url}' => esc_url( home_url( '/demande-intervention/' ) ),
		'{checkout_url}'    => esc_url( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/commander/' ) ),
		'{contact_phone}'   => $settings['contact_phone'],
		'{contact_hours}'   => $settings['contact_hours'],
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
		$variables['{payment_url}']    = esc_url( $order->get_checkout_payment_url() );

		// Delai restant avant annulation (flux virement).
		$days = max( 0, (int) ceil( ( $deadlines['cancel'] - time() ) / DAY_IN_SECONDS ) );
		/* translators: %d: nombre de jours */
		$variables['{days_remaining}'] = sprintf( _n( '%d jour', '%d jours', $days, 'gestion-atelier-cct' ), $days );

		// Delai restant avant suppression (flux commande non finalisee).
		$created = $order->get_date_created();
		if ( $created ) {
			$delete_ts = gacct_pay_deletion_deadline( $created->getTimestamp() );
			$variables['{delete_deadline}'] = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $delete_ts );
			$variables['{time_remaining}']  = gacct_pay_time_remaining( $delete_ts );
		}
	}

	return apply_filters( 'gacct_pay_email_variables', array_merge( $variables, $extra ), $order );
}

/**
 * Habille un contenu avec le gabarit d'email WooCommerce (en-tête + logo,
 * cartouche, pied de page) ET applique ses styles en inline.
 *
 * `wrap_message()` seul ne suffit pas : WooCommerce garde sa CSS dans
 * `emails/email-styles.php` et ne l'applique qu'au moment du `style_inline()`.
 * Sans cette seconde étape, le client reçoit du HTML brut sans mise en forme.
 *
 * Le rendu suit donc les réglages WooCommerce > Réglages > E-mails (logo,
 * couleur de base, texte de pied de page) : rien de codé en dur, chaque site
 * revendu garde sa propre identité.
 *
 * @param string $subject Objet (sert de titre dans l'en-tête coloré).
 * @param string $body    Corps HTML.
 * @return string
 */
function gacct_render_email_html( $subject, $body ) {
	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->mailer() ) {
		return $body;
	}

	$html = WC()->mailer()->wrap_message( wp_strip_all_tags( $subject ), $body );

	// Inlining des styles WooCommerce (nécessite DOMDocument ; sinon on renvoie
	// le contenu habillé mais non inliné plutôt que d'échouer).
	if ( class_exists( 'WC_Email' ) && class_exists( 'DOMDocument' ) ) {
		try {
			$styler = new WC_Email();
			$html   = $styler->style_inline( $html );
		} catch ( \Throwable $e ) {
			jwcct_log( 'gacct_render_email_html : style_inline a echoue — ' . $e->getMessage() );
		}
	}

	return apply_filters( 'gacct_email_html', $html, $subject, $body );
}

function gacct_pay_send_email( $to, $template_key, array $variables, $copy_admin = false ) {
	$settings = gacct_pay_settings();
	$email    = isset( $settings['emails'][ $template_key ] ) ? $settings['emails'][ $template_key ] : null;

	if ( ! $email || empty( $email['enabled'] ) || ! is_email( $to ) ) {
		return false;
	}

	$subject = strtr( (string) $email['subject'], $variables );
	$body    = strtr( (string) $email['body'], $variables );
	$message = gacct_render_email_html( $subject, $body );

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
	gacct_pay_process_unpaid_orders();
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
			gacct_pay_cancel_unpaid_order(
				$order,
				$deadlines['cancel'],
				'bacs_cancel',
				__( 'virement non recu', 'gestion-atelier-cct' )
			);
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
 * Relance puis annulation des commandes passées mais jamais payées
 * (carte refusée, abandon sur la page de paiement…). Même logique que les
 * demandes non finalisées : relance courte, puis suppression à minuit.
 *
 * Le virement est exclu : il a son propre calendrier, en jours.
 */
function gacct_pay_process_unpaid_orders() {
	$settings = gacct_pay_settings();

	$orders = wc_get_orders(
		array(
			'status'  => array( 'failed', 'pending' ),
			'limit'   => 100,
			'orderby' => 'date',
			'order'   => 'ASC',
		)
	);

	$now = time();

	foreach ( $orders as $order ) {
		// Le flux virement gère ses propres commandes (échéance en jours).
		if ( 'bacs' === $order->get_payment_method() ) {
			continue;
		}

		$created = $order->get_date_created();

		if ( ! $created ) {
			continue;
		}

		$created_ts = $created->getTimestamp();
		$delete_ts  = gacct_pay_deletion_deadline( $created_ts );

		// 1. Suppression : échéance dépassée.
		if ( $now >= $delete_ts ) {
			gacct_pay_cancel_unpaid_order(
				$order,
				$delete_ts,
				'unfinished_cancel',
				__( 'paiement non abouti', 'gestion-atelier-cct' )
			);
			continue;
		}

		// 2. Relance : délai atteint, pas encore relancé.
		$reminder_ts = $created_ts + $settings['abandoned_minutes'] * MINUTE_IN_SECONDS;

		if ( $now >= $reminder_ts && ! $order->get_meta( GACCT_PAY_META_REMINDER_SENT ) ) {
			$sent = gacct_pay_send_email(
				$order->get_billing_email(),
				'payment_failed',
				gacct_pay_email_variables( $order )
			);

			$order->update_meta_data( GACCT_PAY_META_REMINDER_SENT, current_time( 'mysql' ) );
			$order->add_order_note(
				$sent
					? sprintf(
						/* translators: 1: email, 2: date limite */
						__( 'Relance paiement non abouti envoyee au client (%1$s), avec lien de reprise du paiement. Suppression prevue le %2$s.', 'gestion-atelier-cct' ),
						$order->get_billing_email(),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $delete_ts )
					)
					: __( 'Echec d’envoi de la relance paiement non abouti (wp_mail a retourne false).', 'gestion-atelier-cct' )
			);
			$order->save();
		}
	}
}

/**
 * Annule une commande non payée : libère le créneau, supprime les CCT,
 * prévient le client (+ copie admin) et journalise tout dans la commande.
 *
 * @param WC_Order $order         Commande.
 * @param int      $deadline_ts   Échéance dépassée (pour la note et l'email).
 * @param string   $template_key  Template d'email à envoyer.
 * @param string   $reason        Motif affiché dans la note de commande.
 */
function gacct_pay_cancel_unpaid_order( $order, $deadline_ts, $template_key = 'bacs_cancel', $reason = '' ) {
	if ( $order->get_meta( GACCT_PAY_META_AUTO_CANCELLED ) ) {
		return;
	}

	$revision_id   = (int) $order->get_meta( JWCCT_ORDER_REVISION_ID );
	$occupation_id = (int) $order->get_meta( JWCCT_ORDER_OCCUPATION_ID );

	// Email AVANT suppression des données (les variables en dépendent).
	$sent = gacct_pay_send_email(
		$order->get_billing_email(),
		$template_key,
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
			/* translators: 1: motif, 2: date limite, 3: liste des CCT supprimes, 4: statut email */
			__( 'Annulation automatique : %1$s avant le %2$s. Creneau libere (%3$s). Email d’annulation au client : %4$s (copie admin).', 'gestion-atelier-cct' ),
			$reason ? $reason : __( 'paiement non recu', 'gestion-atelier-cct' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $deadline_ts ),
			$deleted ? implode( ', ', $deleted ) : __( 'aucun CCT a supprimer', 'gestion-atelier-cct' ),
			$sent ? __( 'envoye', 'gestion-atelier-cct' ) : __( 'ECHEC', 'gestion-atelier-cct' )
		)
	);

	$order->update_status(
		'cancelled',
		sprintf(
			/* translators: %s: motif */
			__( 'Annulation automatique (%s) : delai imparti depasse.', 'gestion-atelier-cct' ),
			$reason ? $reason : __( 'paiement non recu', 'gestion-atelier-cct' )
		)
	);
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
			// Échéance de suppression annoncée au client (calculée sur la même
			// règle que la purge : minuit suivant l'ancienneté minimale).
			$created_ts = (int) get_gmt_from_date( $row['cct_created'], 'U' );
			$delete_ts  = gacct_pay_deletion_deadline( $created_ts );

			gacct_pay_send_email(
				$user->user_email,
				'abandoned',
				gacct_pay_email_variables(
					null,
					array(
						'{customer_name}'   => $user->display_name ? $user->display_name : $user->user_login,
						'{delete_deadline}' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $delete_ts ),
						'{time_remaining}'  => gacct_pay_time_remaining( $delete_ts ),
					)
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

	$settings = gacct_pay_settings();

	// Garde-fou : ne jamais purger un brouillon trop récent
	// (quelqu'un peut être en train de finaliser sa commande).
	$min_age = (int) apply_filters( 'gacct_pay_purge_min_age', $settings['unfinished_hours'] * HOUR_IN_SECONDS );
	$cutoff  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $min_age );

	// FILET DE SÉCURITÉ : un CCT référencé par une commande WooCommerce n'est
	// JAMAIS supprimé, même s'il est resté en draft/order_id 0. Cas réel : la
	// liaison au checkout (jwcct_process_order_link) échoue quand la commande
	// n'a pas de compte client (commande invitée) ou quand les IDs en attente
	// ont expiré — la commande existe pourtant bel et bien.
	$protected = array(
		JWCCT_CCT_REVISION   => gacct_pay_referenced_cct_ids( JWCCT_ORDER_REVISION_ID ),
		JWCCT_CCT_OCCUPATION => gacct_pay_referenced_cct_ids( JWCCT_ORDER_OCCUPATION_ID ),
	);

	$purged  = array();
	$skipped = array();

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
			$id = (int) $id;

			if ( in_array( $id, $protected[ $slug ], true ) ) {
				$skipped[ $slug ][] = $id;
				continue;
			}

			if ( gacct_pay_delete_cct_item( $slug, $id ) ) {
				$purged[ $slug ][] = $id;
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

	if ( ! empty( $skipped ) ) {
		// Anomalie : un CCT rattaché à une commande n'aurait pas dû rester en draft.
		// On le signale à l'admin plutôt que de le supprimer silencieusement.
		jwcct_log( 'midnight_purge SKIPPED (CCT lies a une commande) : ' . wp_json_encode( $skipped ) );
		update_option( 'gacct_pay_purge_skipped', array( 'time' => current_time( 'mysql' ), 'items' => $skipped ), false );
	}
}

/* =============================================================================
 *  NO-SHOW : CRÉNEAU LIBÉRÉ SI LE MATÉRIEL N'EST JAMAIS ARRIVÉ
 *
 *  Règle métier (28/07/2026) : au passage de minuit qui ouvre le jour du créneau
 *  — c'est-à-dire la veille au soir —, si la voile n'est toujours pas à l'atelier
 *  (état de la révision < 2), le créneau est libéré. L'acompte reste acquis :
 *  quoi qu'il arrive, le créneau est perdu pour l'atelier.
 *
 *  Ce qui est fait : occupation supprimée (le jour redevient réservable), relation
 *  11 nettoyée, e-mail au client (template éditable `noshow_release`) + copie
 *  admin, note et metas de traçabilité sur la commande. Ce qui est CONSERVÉ : la
 *  révision, la commande et son paiement — le dossier peut être replanifié à la
 *  main après contact avec le client.
 *
 *  Les commandes non payées ne passent pas par ici : leurs calendriers propres
 *  (virement J+2/J+3, non finalisé H+1/minuit) annulent et suppriment déjà tout.
 * ============================================================================= */

define( 'GACCT_PAY_META_NOSHOW_RELEASED', '_gacct_noshow_released' );
define( 'GACCT_PAY_META_NOSHOW_SLOT', '_gacct_noshow_slot_ts' );

add_action( GACCT_PAY_MIDNIGHT_EVENT, 'gacct_pay_release_noshow_slots', 20 );

function gacct_pay_release_noshow_slots() {
	global $wpdb;

	$occ_table = gacct_pay_cct_table( JWCCT_CCT_OCCUPATION );
	$rev_table = gacct_pay_cct_table( JWCCT_CCT_REVISION );

	if ( ! $occ_table || ! $rev_table ) {
		return;
	}

	// Les dates de créneau sont stockées à minuit UTC du jour calendaire : la
	// borne « aujourd'hui inclus » est donc minuit UTC de demain, calculé depuis
	// la date LOCALE du site (le cron tourne à minuit local, veille du créneau).
	$limit = strtotime( current_time( 'Y-m-d' ) . ' 00:00:00 +0000' ) + DAY_IN_SECONDS;
	$limit = (int) apply_filters( 'gacct_pay_noshow_limit_ts', $limit );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT o._ID AS occupation_id, o.order_id, o.date_reservee, r._ID AS revision_id, r.etat_de_la_commande AS etat
			 FROM {$occ_table} o
			 LEFT JOIN {$rev_table} r ON r.order_id = o.order_id
			 WHERE o.cct_status = 'publish'
			   AND o.order_id > 0
			   AND CAST(o.date_reservee AS UNSIGNED) < %d",
			$limit
		),
		ARRAY_A
	);

	if ( empty( $rows ) ) {
		return;
	}

	foreach ( $rows as $row ) {
		$order_id      = (int) $row['order_id'];
		$occupation_id = (int) $row['occupation_id'];
		$revision_id   = (int) $row['revision_id'];
		$slot_ts       = (int) $row['date_reservee'];
		$etat          = null === $row['etat'] ? null : (int) $row['etat'];

		// Matériel arrivé (état >= 2) : le créneau est honoré, rien à faire.
		if ( null !== $etat && $etat >= 2 ) {
			continue;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			jwcct_log( "noshow_release : occupation $occupation_id sans commande valide ($order_id), laissée telle quelle." );
			continue;
		}

		// Déjà traitée (occupation résiduelle) ou commande morte : on n'insiste pas.
		if ( $order->get_meta( GACCT_PAY_META_NOSHOW_RELEASED ) || $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
			continue;
		}

		// Non payée : les calendriers virement / non finalisé s'en chargent.
		if ( function_exists( 'gacct_order_payment_received' ) && ! gacct_order_payment_received( $order ) ) {
			jwcct_log( "noshow_release : commande $order_id non payée au jour du créneau, laissée au calendrier de paiement." );
			continue;
		}

		// --- Libération -----------------------------------------------------
		if ( ! gacct_pay_delete_cct_item( JWCCT_CCT_OCCUPATION, $occupation_id ) ) {
			jwcct_log( "noshow_release : échec de suppression de l'occupation $occupation_id (commande $order_id)." );
			continue;
		}

		// Relation 11 uniquement : la révision reste liée à la commande (12) et au client (13).
		gacct_pay_delete_cct_relations( 0, $occupation_id, 0 );

		$order->update_meta_data( GACCT_PAY_META_NOSHOW_RELEASED, current_time( 'mysql' ) );
		$order->update_meta_data( GACCT_PAY_META_NOSHOW_SLOT, $slot_ts );
		$order->delete_meta_data( JWCCT_ORDER_OCCUPATION_ID );

		$slot_label = wp_date( get_option( 'date_format' ), $slot_ts );

		$sent = gacct_pay_send_email(
			$order->get_billing_email(),
			'noshow_release',
			gacct_pay_email_variables( $order, array( '{slot_date}' => $slot_label ) ),
			true
		);

		$order->add_order_note(
			sprintf(
				/* translators: 1: date du créneau, 2: id de révision, 3: envoi email */
				__( 'Matériel jamais reçu : créneau du %1$s libéré, acompte conservé. Révision #%2$d conservée pour replanification. %3$s', 'gestion-atelier-cct' ),
				$slot_label,
				$revision_id,
				$sent ? __( 'E-mail envoyé au client (copie admin).', 'gestion-atelier-cct' ) : __( 'ERREUR : e-mail non envoyé.', 'gestion-atelier-cct' )
			)
		);
		$order->save();

		jwcct_log( "noshow_release : créneau du $slot_label libéré (commande $order_id, occupation $occupation_id, révision $revision_id, email " . ( $sent ? 'ok' : 'KO' ) . ')' );
	}
}

/**
 * IDs de CCT référencés par une commande WooCommerce, quelle que soit la meta.
 *
 * Interroge les DEUX stockages (postmeta historique + tables HPOS) : le filet
 * reste valable si le site bascule sur les commandes haute performance.
 *
 * @param string $meta_key Meta de commande (_jwcct_revision_id, _jwcct_occupation_id).
 * @return int[]
 */
function gacct_pay_referenced_cct_ids( $meta_key ) {
	global $wpdb;

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
			$meta_key
		)
	);

	$hpos_table = $wpdb->prefix . 'wc_orders_meta';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
		$hpos_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$hpos_table} WHERE meta_key = %s",
				$meta_key
			)
		);
		$ids = array_merge( $ids, $hpos_ids );
	}

	return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
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
		GACCT_OP_MENU_SLUG,
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
		'unfinished_hours'  => max( 2, absint( $_POST['unfinished_hours'] ?? $defaults['unfinished_hours'] ) ),
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

			<h2><?php esc_html_e( 'Commandes non finalisees (panier abandonne, paiement echoue)', 'gestion-atelier-cct' ); ?></h2>
			<p class="description" style="max-width:46em;">
				<?php esc_html_e( 'Couvre les deux cas : formulaire rempli sans passer commande, et commande passee dont le paiement n a pas abouti (carte refusee, abandon sur la page de paiement). Le paiement par virement n est PAS concerne : il suit son propre calendrier ci-dessus.', 'gestion-atelier-cct' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="gacct_abandoned_minutes"><?php esc_html_e( 'Delai avant relance', 'gestion-atelier-cct' ); ?></label></th>
						<td>
							<input type="number" id="gacct_abandoned_minutes" name="abandoned_minutes" class="small-text" min="15" step="15" value="<?php echo esc_attr( $settings['abandoned_minutes'] ); ?>">
							<?php esc_html_e( 'minutes apres la creation', 'gestion-atelier-cct' ); ?>
							<p class="description"><?php esc_html_e( 'Email de rappel au client, avec un lien pour finaliser sa commande (ou reprendre son paiement) et la date limite avant suppression.', 'gestion-atelier-cct' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gacct_unfinished_hours"><?php esc_html_e( 'Delai avant suppression', 'gestion-atelier-cct' ); ?></label></th>
						<td>
							<input type="number" id="gacct_unfinished_hours" name="unfinished_hours" class="small-text" min="2" value="<?php echo esc_attr( $settings['unfinished_hours'] ); ?>">
							<?php esc_html_e( 'heures d’anciennete minimum', 'gestion-atelier-cct' ); ?>
							<p class="description">
								<?php esc_html_e( 'La suppression a lieu au premier passage de minuit ou la commande depasse cette anciennete : demande supprimee, commande annulee, creneau libere, email au client (copie admin). Cette date exacte est annoncee au client dans l email de relance.', 'gestion-atelier-cct' ); ?>
								<br>
								<?php
								printf(
									/* translators: %s: exemple d'echeance */
									esc_html__( 'Exemple : une commande passee maintenant serait supprimee le %s.', 'gestion-atelier-cct' ),
									'<strong>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), gacct_pay_deletion_deadline( time() ) ) ) . '</strong>'
								);
								?>
							</p>
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
									<code>{bank_details}</code>
									<code>{order_url}</code>
									<code>{payment_url}</code>
									<code>{checkout_url}</code>
									<code>{new_request_url}</code>
									<code>{site_name}</code>
									<br>
									<?php esc_html_e( 'Delais (a garder dans les relances) :', 'gestion-atelier-cct' ); ?>
									<code>{deadline_date}</code> <?php esc_html_e( '(date limite du virement)', 'gestion-atelier-cct' ); ?>
									<code>{days_remaining}</code> <?php esc_html_e( '(« 2 jours »)', 'gestion-atelier-cct' ); ?>
									<code>{delete_deadline}</code> <?php esc_html_e( '(date + heure de suppression)', 'gestion-atelier-cct' ); ?>
									<code>{time_remaining}</code> <?php esc_html_e( '(« environ 11 heures »)', 'gestion-atelier-cct' ); ?>
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
