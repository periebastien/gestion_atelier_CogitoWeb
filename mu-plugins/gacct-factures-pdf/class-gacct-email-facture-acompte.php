<?php
/**
 * E-mail WooCommerce « Facture d'acompte » : envoyé au client quand l'acompte
 * est réglé, avec la facture d'acompte en pièce jointe (PDF Invoices, réglage
 * « Joindre à » du document). Sujet, titre et texte modifiables dans
 * WooCommerce > Réglages > E-mails.
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

class GACCT_Email_Facture_Acompte extends WC_Email {

	public function __construct() {
		$this->id             = GACCT_FA_EMAIL_ID;
		$this->customer_email = true;
		$this->title          = __( 'Facture d’acompte', 'gestion-atelier-cct' );
		$this->description    = __( 'Envoyé au client quand son acompte est réglé (carte, virement encaissé ou passage manuel en « Acompte payé »), avec la facture d’acompte en pièce jointe.', 'gestion-atelier-cct' );
		$this->template_base  = GACCT_FACTURES_DIR . '/templates/';
		$this->template_html  = 'emails/customer-facture-acompte.php';
		$this->template_plain = 'emails/plain/customer-facture-acompte.php';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Votre facture d’acompte {site_title} : commande {order_number}', 'gestion-atelier-cct' );
	}

	public function get_default_heading() {
		return __( 'Votre facture d’acompte', 'gestion-atelier-cct' );
	}

	public function get_default_additional_content() {
		return '';
	}

	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		if ( $order_id && ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof WC_Order ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/** Phrase d'introduction, partagée par les versions HTML et texte. */
	public function intro() {
		$order   = $this->object;
		$acompte = gacct_factures_acompte( $order );
		$date    = gacct_factures_date_fr( $acompte['date'] );
		$montant = html_entity_decode( wp_strip_all_tags( wc_price( $acompte['montant'], array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );

		$texte = '' !== $date
			/* translators: 1: montant, 2: date, 3: numéro de commande */
			? sprintf( __( 'Nous avons bien reçu votre acompte de %1$s le %2$s pour la commande %3$s, merci.', 'gestion-atelier-cct' ), $montant, $date, $order->get_order_number() )
			/* translators: 1: montant, 2: numéro de commande */
			: sprintf( __( 'Nous avons bien reçu votre acompte de %1$s pour la commande %2$s, merci.', 'gestion-atelier-cct' ), $montant, $order->get_order_number() );

		$texte .= ' ' . __( 'Vous trouverez votre facture d’acompte en pièce jointe ; elle reste aussi disponible dans votre espace client. Son montant sera déduit de la facture définitive, émise à la fin de l’intervention.', 'gestion-atelier-cct' );

		return (string) apply_filters( 'gacct_facture_acompte_email_intro', $texte, $order );
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, array(
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => false,
			'email'              => $this,
		), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, array(
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => true,
			'email'              => $this,
		), '', $this->template_base );
	}
}
