<?php
/**
 * Plugin Name: Kojito Acompte Produit
 * Description: Gere les acomptes WooCommerce puis le paiement du solde sur la meme commande.
 * Version: 1.1.0
 * Author: Kojito
 * Text Domain: kojito-acompte
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kojito_Acompte_Produit {

	const META_PRIX_TOTAL_INITIAL   = '_kojito_prix_total_initial';
	const META_PRIX_UNITAIRE_INITIAL = '_kojito_prix_unitaire_initial';
	const META_ACOMPTE_UNITAIRE     = '_kojito_acompte_unitaire';
	const META_TOTAL_INITIAL        = '_kojito_total_initial';
	const META_ACOMPTE_PAYE         = '_kojito_acompte_paye';
	const META_SOLDE_RESTANT        = '_kojito_solde_restant';
	const META_SOLDE_PAYE           = '_kojito_solde_paye';
	const META_PHASE_PAIEMENT       = '_kojito_phase_paiement';
	const OPTION_EMAIL_SUBJECT      = 'kojito_acompte_email_solde_subject';
	const OPTION_EMAIL_HTML         = 'kojito_acompte_email_solde_html';
	const PHASE_ACOMPTE             = 'acompte';
	const PHASE_SOLDE               = 'solde';
	const PHASE_SOLDE_PAYE          = 'solde_paye';

	public function __construct() {
		add_action( 'woocommerce_product_options_general_product_data', [ $this, 'ajouter_champ_acompte' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'sauvegarder_champ_acompte' ] );

		add_action( 'woocommerce_before_calculate_totals', [ $this, 'modifier_prix_panier_acompte' ], 10, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'stocker_prix_initial_commande' ], 10, 4 );

		add_action( 'init', [ $this, 'enregistrer_statut_acompte_paye' ] );
		add_filter( 'wc_order_statuses', [ $this, 'ajouter_statut_acompte_paye' ] );
		add_action( 'woocommerce_payment_complete', [ $this, 'changer_statut_apres_paiement' ] );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', [ $this, 'autoriser_statut_acompte_pour_paiement_solde' ], 10, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', [ $this, 'autoriser_statut_acompte_pour_validation_solde' ], 10, 2 );
		add_action( 'woocommerce_pre_payment_complete', [ $this, 'restaurer_totaux_avant_validation_solde' ], 10, 2 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'restaurer_totaux_si_solde_valide_manuellement' ], 10, 4 );

		add_action( 'kojito_declencher_paiement_solde', [ $this, 'declencher_paiement_solde' ], 10, 1 );
		add_filter( 'woocommerce_pay_order_button_text', [ $this, 'modifier_texte_bouton_paiement_solde' ] );
	}

	public function ajouter_champ_acompte() {
		echo '<div class="options_group">';

		woocommerce_wp_text_input(
			[
				'id'                => '_kojito_montant_acompte',
				'label'             => __( 'Montant de l\'acompte (EUR)', 'kojito-acompte' ),
				'placeholder'       => 'Ex: 50',
				'desc_tip'          => 'true',
				'description'       => __( 'Entrez le montant de l\'acompte fixe pour ce produit.', 'kojito-acompte' ),
				'type'              => 'number',
				'custom_attributes' => [
					'step' => 'any',
					'min'  => '0',
				],
			]
		);

		echo '</div>';
	}

	public function sauvegarder_champ_acompte( $post_id ) {
		if ( isset( $_POST['_kojito_montant_acompte'] ) ) {
			$montant_acompte = wc_clean( wp_unslash( $_POST['_kojito_montant_acompte'] ) );
			update_post_meta( $post_id, '_kojito_montant_acompte', $montant_acompte );
		}
	}

	public function modifier_prix_panier_acompte( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
			$acompte   = get_post_meta( $product_id, '_kojito_montant_acompte', true );

			if ( ! $acompte && $cart_item['variation_id'] ) {
				$acompte = get_post_meta( $cart_item['product_id'], '_kojito_montant_acompte', true );
			}

			if ( '' !== $acompte && is_numeric( $acompte ) && (float) $acompte > 0 ) {
				$cart_item['data']->set_price( (float) $acompte );
			}
		}
	}

	public function stocker_prix_initial_commande( $item, $cart_item_key, $values, $order ) {
		$product_id = ! empty( $values['variation_id'] ) ? $values['variation_id'] : $values['product_id'];
		$acompte    = get_post_meta( $product_id, '_kojito_montant_acompte', true );

		if ( ! $acompte && ! empty( $values['variation_id'] ) ) {
			$acompte = get_post_meta( $values['product_id'], '_kojito_montant_acompte', true );
		}

		if ( '' === $acompte || ! is_numeric( $acompte ) || (float) $acompte <= 0 ) {
			return;
		}

		$original_product = wc_get_product( $product_id );
		if ( ! $original_product ) {
			return;
		}

		$prix_unitaire_initial = (float) $original_product->get_price();
		$quantite              = isset( $values['quantity'] ) ? (float) $values['quantity'] : 1;
		$prix_total_initial    = $prix_unitaire_initial * $quantite;

		$item->add_meta_data( self::META_PRIX_TOTAL_INITIAL, $prix_total_initial, true );
		$item->add_meta_data( self::META_PRIX_UNITAIRE_INITIAL, $prix_unitaire_initial, true );
		$item->add_meta_data( self::META_ACOMPTE_UNITAIRE, (float) $acompte, true );
		$item->add_meta_data( __( 'Acompte par unite', 'kojito-acompte' ), wc_price( $acompte ), true );
	}

	public function enregistrer_statut_acompte_paye() {
		register_post_status(
			'wc-acompte-paye',
			[
				'label'                     => _x( 'Acompte paye', 'Order status', 'kojito-acompte' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop( 'Acompte paye <span class="count">(%s)</span>', 'Acomptes payes <span class="count">(%s)</span>' ),
			]
		);
	}

	public function ajouter_statut_acompte_paye( $order_statuses ) {
		$nouveaux_statuts = [];

		foreach ( $order_statuses as $key => $status ) {
			$nouveaux_statuts[ $key ] = $status;

			if ( 'wc-processing' === $key ) {
				$nouveaux_statuts['wc-acompte-paye'] = _x( 'Acompte paye', 'Order status', 'kojito-acompte' );
			}
		}

		return $nouveaux_statuts;
	}

	public function changer_statut_apres_paiement( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $this->commande_contient_acompte( $order ) ) {
			return;
		}

		if ( self::PHASE_SOLDE_PAYE === $order->get_meta( self::META_PHASE_PAIEMENT ) ) {
			return;
		}

		$total_initial = $this->calculer_total_initial_commande( $order );
		$acompte_paye  = (float) $order->get_total();

		$order->update_meta_data( self::META_PHASE_PAIEMENT, self::PHASE_ACOMPTE );
		$order->update_meta_data( self::META_TOTAL_INITIAL, $total_initial );
		$order->update_meta_data( self::META_ACOMPTE_PAYE, $acompte_paye );
		$order->update_meta_data( self::META_SOLDE_RESTANT, max( 0, $total_initial - $acompte_paye ) );
		$order->update_meta_data( '_kojito_date_acompte_paye', current_time( 'mysql' ) );
		$order->update_meta_data( '_kojito_transaction_acompte', $order->get_transaction_id() );

		// WooCommerce posera la date de paiement final lors du paiement du solde.
		$order->set_date_paid( null );
		$order->update_status( 'acompte-paye', __( 'Paiement de l\'acompte recu avec succes.', 'kojito-acompte' ) );
	}

	public function declencher_paiement_solde( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $this->commande_contient_acompte( $order ) ) {
			return;
		}

		if ( self::PHASE_SOLDE_PAYE === $order->get_meta( self::META_PHASE_PAIEMENT ) ) {
			$order->add_order_note( __( 'Le solde est deja marque comme paye pour cette commande.', 'kojito-acompte' ) );
			return;
		}

		$total_initial = $this->calculer_total_initial_commande( $order );
		$acompte_paye  = (float) $order->get_meta( self::META_ACOMPTE_PAYE );

		if ( $acompte_paye <= 0 ) {
			$acompte_paye = (float) $order->get_total();
		}

		$reste_a_payer = round( $total_initial - $acompte_paye, wc_get_price_decimals() );

		if ( $reste_a_payer <= 0 ) {
			$this->restaurer_commande_finale( $order );
			$order->payment_complete();
			return;
		}

		$order->update_meta_data( self::META_PHASE_PAIEMENT, self::PHASE_SOLDE );
		$order->update_meta_data( self::META_TOTAL_INITIAL, $total_initial );
		$order->update_meta_data( self::META_ACOMPTE_PAYE, $acompte_paye );
		$order->update_meta_data( self::META_SOLDE_RESTANT, $reste_a_payer );
		$order->set_total( $reste_a_payer );

		if ( ! $order->has_status( 'acompte-paye' ) ) {
			$order->set_status( 'acompte-paye' );
		}

		$order->save();

		$url_paiement = $order->get_checkout_payment_url();

		$order->add_order_note(
			sprintf(
				__( 'Solde restant a payer prepare : %1$s. Lien de paiement du solde (meme commande) : %2$s', 'kojito-acompte' ),
				wc_price( $reste_a_payer ),
				esc_url( $url_paiement )
			)
		);
		$order->add_order_note( __( 'Email de paiement du solde non envoye par Kojito : l envoi est gere par le workflow Atelier.', 'kojito-acompte' ) );
	}

	public function autoriser_statut_acompte_pour_paiement_solde( $statuses, $order ) {
		if ( $order instanceof WC_Order && self::PHASE_SOLDE === $order->get_meta( self::META_PHASE_PAIEMENT ) ) {
			$statuses[] = 'acompte-paye';
		}

		return array_unique( $statuses );
	}

	public function autoriser_statut_acompte_pour_validation_solde( $statuses, $order ) {
		if ( $order instanceof WC_Order && $this->commande_contient_acompte( $order ) ) {
			$statuses[] = 'acompte-paye';
		}

		return array_unique( $statuses );
	}

	public function restaurer_totaux_avant_validation_solde( $order_id, $transaction_id = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || self::PHASE_SOLDE !== $order->get_meta( self::META_PHASE_PAIEMENT ) ) {
			return;
		}

		$this->marquer_solde_paye_et_restaurer( $order, $transaction_id );
	}

	public function restaurer_totaux_si_solde_valide_manuellement( $order_id, $old_status, $new_status, $order ) {
		if ( ! $order instanceof WC_Order || self::PHASE_SOLDE !== $order->get_meta( self::META_PHASE_PAIEMENT ) ) {
			return;
		}

		if ( in_array( $new_status, wc_get_is_paid_statuses(), true ) ) {
			$this->marquer_solde_paye_et_restaurer( $order );
		}
	}

	public function ajouter_action_admin_paiement_solde( $actions ) {
		$actions['kojito_declencher_paiement_solde'] = __( 'Kojito - Preparer le paiement du solde', 'kojito-acompte' );
		return $actions;
	}

	public function action_admin_declencher_paiement_solde( $order ) {
		if ( $order instanceof WC_Order ) {
			$this->declencher_paiement_solde( $order->get_id() );
		}
	}

	public function modifier_texte_bouton_paiement_solde( $text ) {
		$order_id = absint( get_query_var( 'order-pay' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( $order && self::PHASE_SOLDE === $order->get_meta( self::META_PHASE_PAIEMENT ) ) {
			return __( 'Payer le solde', 'kojito-acompte' );
		}

		return $text;
	}

	public function ajouter_page_configuration() {
		add_submenu_page(
			'woocommerce',
			__( 'Kojito Acompte', 'kojito-acompte' ),
			__( 'Kojito Acompte', 'kojito-acompte' ),
			'manage_woocommerce',
			'kojito-acompte',
			[ $this, 'afficher_page_configuration' ]
		);
	}

	public function traiter_sauvegarde_configuration() {
		if ( ! is_admin() || empty( $_POST['kojito_acompte_settings_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Vous n avez pas les droits suffisants.', 'kojito-acompte' ) );
		}

		check_admin_referer( 'kojito_acompte_save_settings' );

		$subject = isset( $_POST['kojito_email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['kojito_email_subject'] ) ) : '';
		$html    = isset( $_POST['kojito_email_html'] ) ? wp_unslash( $_POST['kojito_email_html'] ) : '';

		update_option( self::OPTION_EMAIL_SUBJECT, $subject, false );
		update_option( self::OPTION_EMAIL_HTML, $html, false );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'             => 'kojito-acompte',
					'kojito-settings'  => 'saved',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function afficher_page_configuration() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$subject = $this->get_email_subject_template();
		$html    = $this->get_email_html_template();
		$preview = $this->rendre_template_email(
			$html,
			$this->get_variables_preview()
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kojito Acompte', 'kojito-acompte' ); ?></h1>

			<?php if ( isset( $_GET['kojito-settings'] ) && 'saved' === $_GET['kojito-settings'] ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuration enregistree.', 'kojito-acompte' ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Cet email est envoye au client quand vous lancez l action de commande "Kojito - Preparer le paiement du solde".', 'kojito-acompte' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=kojito-acompte' ) ); ?>">
				<?php wp_nonce_field( 'kojito_acompte_save_settings' ); ?>
				<input type="hidden" name="kojito_acompte_settings_action" value="save">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="kojito_email_subject"><?php esc_html_e( 'Sujet de l email', 'kojito-acompte' ); ?></label></th>
						<td>
							<input id="kojito_email_subject" name="kojito_email_subject" type="text" class="regular-text" value="<?php echo esc_attr( $subject ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kojito_email_html"><?php esc_html_e( 'HTML de l email', 'kojito-acompte' ); ?></label></th>
						<td>
							<textarea id="kojito_email_html" name="kojito_email_html" class="large-text code" rows="18"><?php echo esc_textarea( $html ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Variables disponibles :', 'kojito-acompte' ); ?>
								<code>{site_name}</code>
								<code>{order_id}</code>
								<code>{order_number}</code>
								<code>{customer_first_name}</code>
								<code>{customer_name}</code>
								<code>{payment_url}</code>
								<code>{balance_amount}</code>
								<code>{deposit_amount}</code>
								<code>{order_total}</code>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Enregistrer', 'kojito-acompte' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Previsualisation', 'kojito-acompte' ); ?></h2>
			<p><?php esc_html_e( 'La previsualisation utilise des donnees d exemple.', 'kojito-acompte' ); ?></p>
			<div style="max-width:760px;background:#fff;border:1px solid #c3c4c7;padding:24px;">
				<?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php
	}

	private function envoyer_email_solde( $order, $url_paiement, $reste_a_payer, $acompte_paye, $total_initial ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$email_client = $order->get_billing_email();

		if ( ! is_email( $email_client ) ) {
			$order->add_order_note( __( 'Email de paiement du solde non envoye : aucun email client valide sur la commande.', 'kojito-acompte' ) );
			return false;
		}

		$variables = $this->get_variables_commande( $order, $url_paiement, $reste_a_payer, $acompte_paye, $total_initial );
		$subject   = $this->rendre_template_email( $this->get_email_subject_template(), $variables );
		$message   = $this->rendre_template_email( $this->get_email_html_template(), $variables );
		$headers   = [ 'Content-Type: text/html; charset=UTF-8' ];
		$sent      = wp_mail( $email_client, wp_strip_all_tags( $subject ), $message, $headers );

		if ( $sent ) {
			$order->add_order_note(
				sprintf(
					__( 'Email de paiement du solde envoye a %s.', 'kojito-acompte' ),
					$email_client
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					__( 'Echec de l envoi de l email de paiement du solde a %s.', 'kojito-acompte' ),
					$email_client
				)
			);
		}

		return $sent;
	}

	private function get_email_subject_template() {
		$subject = get_option( self::OPTION_EMAIL_SUBJECT, '' );

		if ( '' === $subject ) {
			$subject = __( 'Paiement du solde de votre commande {order_number}', 'kojito-acompte' );
		}

		return $subject;
	}

	private function get_email_html_template() {
		$html = get_option( self::OPTION_EMAIL_HTML, '' );

		if ( '' !== $html ) {
			return $html;
		}

		return '<p>Bonjour {customer_first_name},</p>
<p>Le solde de votre commande {order_number} est maintenant disponible au paiement.</p>
<p><strong>Montant de l acompte deja regle :</strong> {deposit_amount}<br>
<strong>Solde restant a payer :</strong> {balance_amount}<br>
<strong>Total de la commande :</strong> {order_total}</p>
<p><a href="{payment_url}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:4px;">Payer le solde</a></p>
<p>Merci,<br>{site_name}</p>';
	}

	private function get_variables_commande( $order, $url_paiement, $reste_a_payer, $acompte_paye, $total_initial ) {
		$first_name = $order->get_billing_first_name();
		$last_name  = $order->get_billing_last_name();
		$full_name  = trim( $first_name . ' ' . $last_name );

		if ( '' === $first_name ) {
			$first_name = $full_name ? $full_name : __( 'client', 'kojito-acompte' );
		}

		return [
			'{site_name}'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{order_id}'            => (string) $order->get_id(),
			'{order_number}'        => $order->get_order_number(),
			'{customer_first_name}' => $first_name,
			'{customer_name}'       => $full_name ? $full_name : $first_name,
			'{payment_url}'         => $url_paiement,
			'{balance_amount}'      => wc_price( $reste_a_payer ),
			'{deposit_amount}'      => wc_price( $acompte_paye ),
			'{order_total}'         => wc_price( $total_initial ),
		];
	}

	private function get_variables_preview() {
		return [
			'{site_name}'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{order_id}'            => '1234',
			'{order_number}'        => '#1234',
			'{customer_first_name}' => 'Camille',
			'{customer_name}'       => 'Camille Durand',
			'{payment_url}'         => esc_url( home_url( '/checkout/order-pay/1234/?pay_for_order=true&key=wc_order_example' ) ),
			'{balance_amount}'      => wc_price( 140 ),
			'{deposit_amount}'      => wc_price( 60 ),
			'{order_total}'         => wc_price( 200 ),
		];
	}

	private function rendre_template_email( $template, array $variables ) {
		return strtr( (string) $template, $variables );
	}

	private function commande_contient_acompte( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		foreach ( $order->get_items() as $item ) {
			if ( '' !== $item->get_meta( self::META_PRIX_TOTAL_INITIAL ) ) {
				return true;
			}
		}

		return false;
	}

	private function calculer_total_initial_commande( $order ) {
		$total_lignes = 0;

		foreach ( $order->get_items() as $item ) {
			$prix_total_initial = $item->get_meta( self::META_PRIX_TOTAL_INITIAL );
			$total_lignes      += '' !== $prix_total_initial ? (float) $prix_total_initial : (float) $item->get_total();
		}

		$total = $total_lignes
			+ (float) $order->get_shipping_total()
			+ (float) $order->get_shipping_tax()
			+ (float) $order->get_cart_tax()
			+ (float) $order->get_total_fees()
			- (float) $order->get_discount_total();

		return round( max( 0, $total ), wc_get_price_decimals() );
	}

	private function marquer_solde_paye_et_restaurer( $order, $transaction_id = '' ) {
		$solde = (float) $order->get_meta( self::META_SOLDE_RESTANT );

		$this->restaurer_commande_finale( $order );

		$order->update_meta_data( self::META_SOLDE_PAYE, $solde );
		$order->update_meta_data( self::META_SOLDE_RESTANT, 0 );
		$order->update_meta_data( self::META_PHASE_PAIEMENT, self::PHASE_SOLDE_PAYE );
		$order->update_meta_data( '_kojito_date_solde_paye', current_time( 'mysql' ) );

		if ( $transaction_id ) {
			$order->update_meta_data( '_kojito_transaction_solde', $transaction_id );
		}

		$order->add_order_note(
			sprintf(
				__( 'Paiement du solde recu : %s. La commande a ete restauree au montant total final.', 'kojito-acompte' ),
				wc_price( $solde )
			)
		);
		$order->save();
	}

	private function restaurer_commande_finale( $order ) {
		foreach ( $order->get_items() as $item ) {
			$prix_total_initial = $item->get_meta( self::META_PRIX_TOTAL_INITIAL );

			if ( '' === $prix_total_initial ) {
				continue;
			}

			$prix_total_initial = (float) $prix_total_initial;
			$item->set_subtotal( $prix_total_initial );
			$item->set_total( $prix_total_initial );
			$item->save();
		}

		$order->calculate_totals( true );
		$order->update_meta_data( self::META_TOTAL_INITIAL, $order->get_total() );
		$order->save();
	}
}

new Kojito_Acompte_Produit();
