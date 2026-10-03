<?php
/**
 * E-mails du module, ajoutés aux modèles éditables de la page
 * Gestion Atelier > Configuration > Paiements & relances.
 *
 * Variables communes : {club_name}, {contact_name}, {lot_code}, {member_url},
 * {lot_dates}, {registration_deadline}, {parcel_deadline}, {account_url},
 * {nb_voiles}, {nb_secours}, {nb_inscrits}, {console_url}, plus celles du
 * socle ({site_name}, {contact_phone}, {contact_hours}).
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'gacct_pay_default_settings', 'gacct_clubs_register_emails' );

function gacct_clubs_register_emails( $defaults ) {
	$p = static function ( $t ) {
		return '<p>' . $t . '</p>';
	};

	$emails = array(
		'club_request_received'      => array(
			'enabled'    => true,
			'copy_admin' => false,
			'label'      => 'Clubs : accusé de réception de la demande (au responsable)',
			'subject'    => 'Votre demande de révision groupée pour {club_name} est bien reçue',
			'body'       => $p( 'Bonjour {contact_name},' )
				. $p( 'Nous avons bien reçu la demande de révision groupée de <strong>{club_name}</strong> : {nb_voiles} voiles et {nb_secours} secours, période souhaitée : {periode}.' )
				. $p( 'Nous revenons vers vous très vite avec la date de début d’intervention. Le créneau sera bloqué pour votre club et toutes les voiles seront traitées dans la foulée.' )
				. $p( 'Vous recevrez ensuite un code et un lien à transmettre à vos membres : chacun inscrira sa voile en ligne, sans acompte. Vous suivrez l’ensemble depuis votre espace client, rubrique « Mon club » : {account_url}' )
				. $p( 'L’équipe {site_name}<br>{contact_phone}' ),
		),
		'club_request_admin'         => array(
			'enabled'    => true,
			'copy_admin' => false,
			'label'      => 'Clubs : nouvelle demande (à l’atelier)',
			'subject'    => 'Nouvelle demande groupée : {club_name} ({nb_voiles} voiles, {nb_secours} secours)',
			'body'       => $p( 'Nouvelle demande de commande groupée.' )
				. $p( '<strong>{club_name}</strong><br>Contact : {contact_name}, {contact_phone_club}, {contact_email}<br>Inspections partielles : {nb_ip}<br>Révisions périodiques : {nb_rp}<br>Secours : {nb_secours}<br>Période souhaitée : {periode}<br>Estimation : {heures}' )
				. $p( 'Remarques : {remarques}' )
				. $p( 'Planifier depuis la console : {console_url}' ),
		),
		'club_code'                  => array(
			'enabled'    => true,
			'copy_admin' => false,
			'label'      => 'Clubs : code et lien à transmettre aux membres (au responsable, relu dans la console avant envoi)',
			'subject'    => 'Révision groupée de {club_name} : votre code et le message pour vos membres',
			'body'       => $p( 'Bonjour {contact_name},' )
				. $p( 'L’atelier a réservé pour <strong>{club_name}</strong> les journées {lot_dates}. Toutes les voiles du club seront traitées sur cette période.' )
				. $p( '<strong>Votre code club : {lot_code}</strong><br>Lien d’inscription : {member_url}' )
				. $p( '<strong>Le message à transférer à vos membres</strong>' )
				. '<blockquote style="border-left:3px solid #20c4c3;margin:0 0 16px;padding:8px 14px;color:#444">{member_message}</blockquote>'
				. $p( 'Les inscriptions se ferment le {registration_deadline}. Le colis du club doit nous parvenir avant le {parcel_deadline}. Toutes les voiles arrivent et repartent ensemble, chacune avec sa feuille de révision imprimée : elle conditionne notre intervention.' )
				. $p( 'Vous suivez les inscriptions, imprimez les bons et déclarez l’envoi depuis votre espace client, rubrique « Mon club » : {account_url}' )
				. $p( 'L’équipe {site_name}<br>{contact_phone}' ),
		),
		'club_member_registered'     => array(
			'enabled'    => true,
			'copy_admin' => false,
			'label'      => 'Clubs : inscription d’un pilote confirmée (remplace « Paiement reçu »)',
			'subject'    => 'Votre voile est inscrite à la révision groupée de {club_name}',
			'body'       => $p( 'Bonjour {customer_name},' )
				. $p( 'Votre demande <strong>{order_number}</strong> est enregistrée dans la révision groupée de <strong>{club_name}</strong>, prévue {lot_dates}. Vous n’avez rien à payer : la facture est réglée par le club.' )
				. '{work_order_block}'
				. $p( '<strong>Ce qu’il vous reste à faire</strong>' )
				. '<ol><li>Imprimez votre bon d’intervention. Il est indispensable : il identifie votre voile à l’atelier.</li><li>Remettez votre voile à votre club avec le bon, avant le {member_deadline}. Le club envoie toutes les voiles ensemble.</li></ol>'
				. $p( 'Vous suivrez l’avancement de votre révision et recevrez votre rapport dans votre espace client : {shipping_url}' )
				. $p( 'L’équipe {site_name}<br>{contact_phone}' ),
		),
		'club_registration_reminder' => array(
			'enabled'    => true,
			'copy_admin' => false,
			'label'      => 'Clubs : rappel avant la fermeture des inscriptions (au responsable)',
			'subject'    => '{club_name} : {nb_inscrits} voiles inscrites sur {nb_voiles}, inscriptions jusqu’au {registration_deadline}',
			'body'       => $p( 'Bonjour {contact_name},' )
				. $p( 'Les inscriptions à la révision groupée de <strong>{club_name}</strong> se ferment le <strong>{registration_deadline}</strong>. À ce jour, <strong>{nb_inscrits} voiles</strong> sont inscrites sur les {nb_voiles} annoncées.' )
				. $p( 'Pensez à relancer vos membres. Le lien d’inscription : {member_url} (code {lot_code}).' )
				. $p( 'Votre suivi : {account_url}' )
				. $p( 'L’équipe {site_name}' ),
		),
		'club_invoice'               => array(
			'enabled'    => true,
			'copy_admin' => true,
			'label'      => 'Clubs : facture du club (au responsable)',
			'subject'    => 'Facture de la révision groupée de {club_name} : {invoice_total}',
			'body'       => $p( 'Bonjour {contact_name},' )
				. $p( 'L’intervention sur le matériel de <strong>{club_name}</strong> est terminée. Voici la facture du club, avec le détail par membre.' )
				. '{invoice_lines}'
				. $p( '<strong>Total à régler : {invoice_total}</strong>' )
				. $p( 'Régler la facture : {payment_url}' )
				. $p( 'Le matériel repart dès réception du paiement, en un seul envoi vers le club. Le détail est aussi dans votre espace client : {account_url}' )
				. $p( 'L’équipe {site_name}<br>{contact_phone}' ),
		),
		'club_shipped'               => array(
			'enabled'    => true,
			'copy_admin' => false,
			'label'      => 'Clubs : matériel du club réexpédié (au responsable)',
			'subject'    => 'Le matériel de {club_name} est en route',
			'body'       => $p( 'Bonjour {contact_name},' )
				. $p( 'Le matériel de <strong>{club_name}</strong> a quitté l’atelier : {return_info}.' )
				. $p( 'Chaque pilote a reçu son rapport de contrôle dans son espace client.' )
				. $p( 'Merci pour votre confiance, et bons vols à tout le club !' )
				. $p( 'L’équipe {site_name}' ),
		),
	);

	if ( ! isset( $defaults['emails'] ) || ! is_array( $defaults['emails'] ) ) {
		$defaults['emails'] = array();
	}
	$defaults['emails'] = array_merge( $defaults['emails'], $emails );

	return $defaults;
}

/**
 * Variables communes d'un lot.
 */
function gacct_clubs_email_vars( array $lot, array $extra = array() ) {
	$counts = gacct_clubs_lot_counts( $lot );
	$base   = function_exists( 'gacct_pay_email_variables' ) ? gacct_pay_email_variables( null ) : array();
	$first  = gacct_clubs_first_day( $lot );

	return array_merge( $base, array(
		'{club_name}'             => esc_html( $lot['nom'] ),
		'{contact_name}'          => esc_html( $lot['contact_nom'] ),
		'{contact_email}'         => esc_html( $lot['contact_email'] ),
		'{contact_phone_club}'    => esc_html( $lot['contact_tel'] ),
		'{lot_code}'              => esc_html( $lot['code'] ),
		'{member_url}'            => esc_html( gacct_clubs_member_url_text( $lot ) ),
		'{lot_dates}'             => esc_html( gacct_clubs_period_label( $lot ) ),
		'{registration_deadline}' => esc_html( gacct_clubs_date_label( $lot['limite_inscription'] ) ),
		'{parcel_deadline}'       => esc_html( gacct_clubs_date_label( $lot['limite_arrivee'] ) ),
		'{member_deadline}'       => esc_html( gacct_clubs_date_label( $lot['limite_arrivee'] ? gacct_clubs_shift_date( $lot['limite_arrivee'], -2 ) : '' ) ),
		'{account_url}'           => esc_html( preg_replace( '#^https?://(www\.)?#', '', gacct_clubs_account_url( (int) $lot['id'] ) ) ),
		'{console_url}'           => esc_url( gacct_clubs_console_url( (int) $lot['id'] ) ),
		'{nb_ip}'                 => (string) (int) $lot['nb_ip'],
		'{nb_rp}'                 => (string) (int) $lot['nb_rp'],
		'{nb_voiles}'             => (string) ( (int) $lot['nb_ip'] + (int) $lot['nb_rp'] ),
		'{nb_secours}'            => (string) (int) $lot['nb_secours'],
		'{nb_inscrits}'           => (string) $counts['voiles'],
		'{periode}'               => esc_html( $lot['periode'] ? $lot['periode'] : 'non précisée' ),
		'{remarques}'             => nl2br( esc_html( $lot['remarques'] ? $lot['remarques'] : 'aucune' ) ),
		'{heures}'                => esc_html( gacct_clubs_hours_label( $lot['heures_estimees'] ) ),
		'{member_message}'        => nl2br( esc_html( gacct_clubs_member_message( $lot ) ) ),
		'{first_day}'             => esc_html( gacct_clubs_date_label( $first ) ),
	), $extra );
}

/**
 * Message prêt à transférer aux membres (texte brut).
 */
function gacct_clubs_member_message( array $lot ) {
	$first    = gacct_clubs_first_day( $lot );
	$deadline = $lot['limite_inscription'] ? gacct_clubs_date_label( $lot['limite_inscription'], 'j F' ) : '';
	$remise   = $lot['limite_arrivee'] ? gacct_clubs_date_label( gacct_clubs_shift_date( $lot['limite_arrivee'], -2 ), 'j F' ) : '';

	$lines   = array();
	$lines[] = 'Bonjour à tous,';
	$lines[] = '';
	$lines[] = sprintf( 'Le club organise une révision groupée chez %s %s.', get_bloginfo( 'name' ), gacct_clubs_period_label( $lot ) );
	$lines[] = sprintf( 'Pour en profiter, inscrivez votre voile (et votre secours si besoin)%s sur ce lien :', $deadline ? ' avant le ' . $deadline : '' );
	$lines[] = gacct_clubs_member_url_text( $lot );
	$lines[] = sprintf( '(ou code club %s sur le formulaire de demande)', $lot['code'] );
	$lines[] = '';
	$lines[] = sprintf( 'Rien à payer : la facture est réglée par le club. Imprimez le bon d’intervention que vous recevrez par e-mail et remettez-le avec votre voile au club%s.', $remise ? ' avant le ' . $remise : '' );
	$lines[] = '';
	$lines[] = $lot['contact_nom'] ? explode( ' ', trim( $lot['contact_nom'] ) )[0] : '';

	return (string) apply_filters( 'gacct_clubs_member_message', implode( "\n", $lines ), $lot, $first );
}

/** Envoi d'un modèle à une adresse, avec les variables du lot. */
function gacct_clubs_send( $to, $key, array $lot, array $extra = array() ) {
	if ( ! function_exists( 'gacct_pay_send_email' ) ) {
		return false;
	}
	return gacct_pay_send_email( $to, $key, gacct_clubs_email_vars( $lot, $extra ) );
}

/** Adresse du responsable du lot (contact de la demande, sinon demandeur). */
function gacct_clubs_lot_email( array $lot ) {
	if ( is_email( $lot['contact_email'] ) ) {
		return $lot['contact_email'];
	}
	$u = $lot['demandeur_id'] ? get_userdata( (int) $lot['demandeur_id'] ) : null;
	return $u ? $u->user_email : '';
}
