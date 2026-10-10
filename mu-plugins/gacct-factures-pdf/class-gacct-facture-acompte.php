<?php
/**
 * Document PDF Invoices « Facture d'acompte », numérotation propre.
 * Calqué sur le « Reçu » de PDF Invoices Pro. La classe parente est choisie
 * dans facture-acompte.php (alias GACCT_Facture_Acompte_Base).
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

class GACCT_Facture_Acompte extends GACCT_Facture_Acompte_Base {

	public function __construct( $order = 0 ) {
		$this->type  = GACCT_FA_TYPE;
		$this->title = __( 'Facture d’acompte', 'gestion-atelier-cct' );
		$this->icon  = WPO_WCPDF()->plugin_url() . '/assets/images/invoice.svg';

		parent::__construct( $order );

		// Séquence de numéros à part (table wcpdf_facture_acompte_number).
		add_filter( 'wpo_wcpdf_document_sequential_number_store', array( $this, 'get_number_sequence' ), 1, 2 );
	}

	public function get_title(): string {
		return apply_filters( 'wpo_wcpdf_document_title', __( 'Facture d’acompte', 'gestion-atelier-cct' ), $this );
	}

	public function get_number_title(): string {
		return apply_filters( 'wpo_wcpdf_document_number_title', __( 'Numéro de facture d’acompte :', 'gestion-atelier-cct' ), $this );
	}

	public function get_date_title(): string {
		return apply_filters( 'wpo_wcpdf_document_date_title', __( 'Date de facturation :', 'gestion-atelier-cct' ), $this );
	}

	public function get_shipping_address_title(): string {
		return apply_filters( 'wpo_wcpdf_document_shipping_address_title', __( 'Adresse de livraison :', 'gestion-atelier-cct' ), $this );
	}

	public function get_filename( $context = 'download', $args = array() ): string {
		$order_ids = $args['order_ids'] ?? array( $this->order_id );
		if ( 1 === count( $order_ids ) && $this->get_number() ) {
			$suffix = (string) $this->get_number();
		} elseif ( 1 === count( $order_ids ) ) {
			$order  = $this->order ? $this->order : wc_get_order( $order_ids[0] );
			$suffix = $order ? $order->get_order_number() : '';
		} else {
			$suffix = date_i18n( 'Y-m-d' );
		}
		$filename = 'facture-acompte-' . $suffix . '.pdf';
		$filename = apply_filters( 'wpo_wcpdf_filename', $filename, $this->get_type(), $order_ids, $context, $args );
		return sanitize_file_name( $filename );
	}

	public function init_settings(): void {
		$page = $option_group = $option_name = 'wpo_wcpdf_documents_settings_' . GACCT_FA_TYPE;
		$s    = 'facture_acompte';

		$fields = array(
			array( 'type' => 'section', 'id' => $s, 'title' => '', 'callback' => 'section' ),
			array( 'type' => 'setting', 'id' => 'enabled', 'title' => __( 'Activer', 'gestion-atelier-cct' ), 'callback' => 'checkbox', 'section' => $s, 'args' => array( 'option_name' => $option_name, 'id' => 'enabled' ) ),
			array( 'type' => 'setting', 'id' => 'attach_to_email_ids', 'title' => __( 'Joindre à :', 'gestion-atelier-cct' ), 'callback' => 'multiple_checkboxes', 'section' => $s, 'args' => array( 'option_name' => $option_name, 'id' => 'attach_to_email_ids', 'fields_callback' => array( $this, 'get_wc_emails' ) ) ),
			array( 'type' => 'setting', 'id' => 'disable_for_statuses', 'title' => __( 'Désactiver pour :', 'gestion-atelier-cct' ), 'callback' => 'select', 'section' => $s, 'args' => array( 'option_name' => $option_name, 'id' => 'disable_for_statuses', 'options_callback' => 'wc_get_order_statuses', 'multiple' => true, 'enhanced_select' => true ) ),
			array( 'type' => 'setting', 'id' => 'display_email', 'title' => __( 'Afficher l’e-mail', 'gestion-atelier-cct' ), 'callback' => 'checkbox', 'section' => $s, 'args' => array( 'option_name' => $option_name, 'id' => 'display_email' ) ),
			array( 'type' => 'setting', 'id' => 'display_phone', 'title' => __( 'Afficher le téléphone', 'gestion-atelier-cct' ), 'callback' => 'checkbox', 'section' => $s, 'args' => array( 'option_name' => $option_name, 'id' => 'display_phone' ) ),
			array( 'type' => 'setting', 'id' => 'display_number', 'title' => __( 'Afficher le numéro', 'gestion-atelier-cct' ), 'callback' => 'checkbox', 'section' => $s, 'args' => array( 'option_name' => $option_name, 'id' => 'display_number' ) ),
			array( 'type' => 'setting', 'id' => 'next_facture_acompte_number', 'title' => __( 'Prochain numéro (sans préfixe ni suffixe)', 'gestion-atelier-cct' ), 'callback' => 'next_number_edit', 'section' => $s, 'args' => array( 'store_callback' => array( $this, 'get_sequential_number_store' ), 'size' => '10' ) ),
			array( 'type' => 'setting', 'id' => 'number_format', 'title' => __( 'Format du numéro', 'gestion-atelier-cct' ), 'callback' => 'multiple_text_input', 'section' => $s, 'args' => array(
				'option_name' => $option_name,
				'id'          => 'number_format',
				'fields'      => array(
					'prefix'  => array( 'label' => __( 'Préfixe', 'gestion-atelier-cct' ), 'size' => 20 ),
					'suffix'  => array( 'label' => __( 'Suffixe', 'gestion-atelier-cct' ), 'size' => 20 ),
					'padding' => array( 'label' => __( 'Nombre de chiffres', 'gestion-atelier-cct' ), 'size' => 20, 'type' => 'number' ),
				),
			) ),
			array( 'type' => 'setting', 'id' => 'my_account_buttons', 'title' => __( 'Téléchargement dans Mon compte', 'gestion-atelier-cct' ), 'callback' => 'select', 'section' => $s, 'args' => array(
				'option_name' => $option_name,
				'id'          => 'my_account_buttons',
				'options'     => array(
					'available' => __( 'Seulement quand elle a été créée ou envoyée', 'gestion-atelier-cct' ),
					'never'     => __( 'Jamais', 'gestion-atelier-cct' ),
				),
			) ),
		);

		$fields = apply_filters( "wpo_wcpdf_settings_fields_documents_{$this->type}_pdf", $fields, $page, $option_group, $option_name, $this );
		WPO_WCPDF()->settings->add_settings_fields( $fields, $page, $option_group, $option_name );
	}

	public function get_settings_categories( string $output_format ): array {
		if ( 'pdf' !== $output_format ) {
			return array();
		}
		return apply_filters( 'wpo_wcpdf_document_settings_categories', array(
			'general'          => array(
				'title'   => __( 'Général', 'gestion-atelier-cct' ),
				'members' => array( 'enabled', 'attach_to_email_ids', 'disable_for_statuses', 'my_account_buttons' ),
			),
			'document_details' => array(
				'title'   => __( 'Détails du document', 'gestion-atelier-cct' ),
				'members' => array( 'display_email', 'display_phone', 'display_number', 'next_facture_acompte_number', 'number_format' ),
			),
		), $output_format, $this );
	}
}
