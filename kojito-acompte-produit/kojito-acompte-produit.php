<?php
/**
 * Plugin Name: Kojito Acompte Produit
 * Description: Gere les acomptes WooCommerce puis le paiement du solde sur la meme commande.
 * Version: 1.3.1
 * Author: Kojito
 * Text Domain: kojito-acompte
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kojito_Acompte_Produit {

	const META_PRIX_TOTAL_INITIAL   = '_kojito_prix_total_initial';
	const META_PRIX_TOTAL_INITIAL_HT = '_kojito_prix_total_initial_ht';
	const META_PRIX_UNITAIRE_INITIAL = '_kojito_prix_unitaire_initial';
	const META_ACOMPTE_UNITAIRE     = '_kojito_acompte_unitaire';
	const META_TOTAL_INITIAL        = '_kojito_total_initial';
	const META_ACOMPTE_PAYE         = '_kojito_acompte_paye';
	const META_SOLDE_RESTANT        = '_kojito_solde_restant';
	const META_SOLDE_PAYE           = '_kojito_solde_paye';
	const META_PHASE_PAIEMENT       = '_kojito_phase_paiement';
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

		// Affichage des commandes (confirmation, espace client, emails) : montrer
		// les prix reels et non les montants d'acompte.
		add_filter( 'woocommerce_order_formatted_line_subtotal', [ $this, 'afficher_prix_initial_ligne' ], 10, 3 );
		add_filter( 'woocommerce_get_order_item_totals', [ $this, 'detailler_acompte_et_solde' ], 10, 2 );

		// Colonne "Acompte" dans la liste des produits de l'admin.
		add_filter( 'manage_edit-product_columns', [ $this, 'ajouter_colonne_acompte' ], 20 );
		add_action( 'manage_product_posts_custom_column', [ $this, 'afficher_colonne_acompte' ], 10, 2 );
		add_action( 'admin_head', [ $this, 'styler_colonne_acompte' ] );

		// NOTE (27/08/2026) : la page de configuration WooCommerce > Kojito Acompte
		// et son template d'email de solde ont ete RETIRES (code mort : la methode
		// envoyer_email_solde n'etait appelee nulle part). L'email du solde est la
		// notification d'etat 6 du plugin gestion-atelier-cct, qui porte le lien de
		// paiement {payment_url} et reste editable dans Gestion Atelier > Notifications.

		// Action de commande admin "Kojito - Preparer le paiement du solde".
		add_filter( 'woocommerce_order_actions', [ $this, 'ajouter_action_admin_paiement_solde' ] );
		add_action( 'woocommerce_order_action_kojito_declencher_paiement_solde', [ $this, 'action_admin_declencher_paiement_solde' ] );
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
			$acompte = $this->recuperer_acompte_produit(
				$cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'],
				$cart_item['variation_id'] ? $cart_item['product_id'] : 0
			);

			if ( null !== $acompte ) {
				$cart_item['data']->set_price( $acompte );
			}
		}
	}

	/**
	 * Lit le montant d'acompte d'un produit.
	 *
	 * Champ vide = pas de mecanisme d'acompte (le produit se paie a 100% a la commande).
	 * Champ a 0  = acompte nul : rien a payer a la commande, la totalite part dans le solde.
	 *
	 * @param int $product_id ID du produit (ou de la variation).
	 * @param int $parent_id  ID du produit parent, si $product_id est une variation.
	 * @return float|null Montant de l'acompte, ou null si aucun acompte n'est defini.
	 */
	private function recuperer_acompte_produit( $product_id, $parent_id = 0 ) {
		$acompte = get_post_meta( $product_id, '_kojito_montant_acompte', true );

		// Attention : '0' est falsy en PHP, on teste explicitement la chaine vide.
		if ( '' === $acompte && $parent_id ) {
			$acompte = get_post_meta( $parent_id, '_kojito_montant_acompte', true );
		}

		if ( '' === $acompte || ! is_numeric( $acompte ) || (float) $acompte < 0 ) {
			return null;
		}

		return (float) $acompte;
	}

	/**
	 * Insere la colonne "Acompte" apres la colonne "Tarif" de la liste des produits.
	 */
	public function ajouter_colonne_acompte( $columns ) {
		$nouvelles = [];

		foreach ( $columns as $cle => $libelle ) {
			$nouvelles[ $cle ] = $libelle;
			if ( 'price' === $cle ) {
				$nouvelles['kojito_acompte'] = __( 'Acompte', 'kojito-acompte' );
			}
		}

		if ( ! isset( $nouvelles['kojito_acompte'] ) ) {
			$nouvelles['kojito_acompte'] = __( 'Acompte', 'kojito-acompte' );
		}

		return $nouvelles;
	}

	/**
	 * Largeur de la colonne "Acompte" sur la liste des produits.
	 *
	 * La table est en `table-layout: fixed` et WooCommerce declare une largeur en %
	 * pour toutes SES colonnes (miniature, nom, UGS, stock, prix, categories,
	 * etiquettes...) : la somme frole 100 %, donc une colonne ajoutee sans largeur
	 * recupere le reliquat, quelques pixels, et s'imprime lettre par lettre.
	 * Le navigateur renormalise les % quand leur somme depasse 100 : declarer la
	 * notre suffit, les autres se resserrent proportionnellement.
	 */
	public function styler_colonne_acompte() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}

		echo '<style>
			.wp-list-table .column-kojito_acompte { width: 9%; }
			.wp-list-table .column-kojito_acompte small {
				display: block;
				color: #646970;
				font-size: 11px;
				white-space: nowrap;
			}
			@media screen and (max-width: 782px) {
				.wp-list-table .column-kojito_acompte { width: auto; }
			}
		</style>';
	}

	/**
	 * Affiche le montant d'acompte du produit, avec la meme semantique que le panier :
	 * vide = pas d'acompte (prix plein a la commande), 0 = tout dans le solde.
	 */
	public function afficher_colonne_acompte( $column, $post_id ) {
		if ( 'kojito_acompte' !== $column ) {
			return;
		}

		$acompte = $this->recuperer_acompte_produit( $post_id );

		if ( null === $acompte ) {
			echo '<span aria-hidden="true">&mdash;</span>';
			return;
		}

		if ( 0.0 === $acompte ) {
			// Le <small> est passe en display:block par styler_colonne_acompte().
			echo wp_kses_post( wc_price( 0 ) ) . '<small>' . esc_html__( '100 % au solde', 'kojito-acompte' ) . '</small>';
			return;
		}

		echo wp_kses_post( wc_price( $acompte ) );
	}

	public function stocker_prix_initial_commande( $item, $cart_item_key, $values, $order ) {
		$product_id = ! empty( $values['variation_id'] ) ? $values['variation_id'] : $values['product_id'];
		$acompte    = $this->recuperer_acompte_produit(
			$product_id,
			! empty( $values['variation_id'] ) ? $values['product_id'] : 0
		);

		if ( null === $acompte ) {
			return;
		}

		$original_product = wc_get_product( $product_id );
		if ( ! $original_product ) {
			return;
		}

		$quantite = isset( $values['quantity'] ) ? (float) $values['quantity'] : 1;

		// Les montants du catalogue peuvent etre saisis TTC ou HT selon la configuration
		// WooCommerce : on stocke explicitement les deux, car ils n'ont pas le meme usage.
		// Le TTC sert a calculer ce que le client doit au total (acompte + solde) et a
		// l'affichage ; le HT sert a reposer les totaux de lignes de commande, que
		// WooCommerce stocke toujours hors taxe.
		$prix_unitaire_initial = (float) wc_get_price_including_tax( $original_product );
		$prix_total_initial    = (float) wc_get_price_including_tax( $original_product, [ 'qty' => $quantite ] );
		$prix_total_initial_ht = (float) wc_get_price_excluding_tax( $original_product, [ 'qty' => $quantite ] );

		$item->add_meta_data( self::META_PRIX_TOTAL_INITIAL, $prix_total_initial, true );
		$item->add_meta_data( self::META_PRIX_TOTAL_INITIAL_HT, $prix_total_initial_ht, true );
		$item->add_meta_data( self::META_PRIX_UNITAIRE_INITIAL, $prix_unitaire_initial, true );
		$item->add_meta_data( self::META_ACOMPTE_UNITAIRE, $acompte, true );

		if ( $acompte > 0 ) {
			$item->add_meta_data( __( 'Acompte par unite', 'kojito-acompte' ), wc_price( $acompte ), true );
		}
	}

	/**
	 * Prix catalogue d'une ligne de panier, quantite comprise.
	 *
	 * Le prix porte par $cart_item['data'] a ete ramene a l'acompte par
	 * modifier_prix_panier_acompte() : pour afficher ce que la ligne coute
	 * reellement, on repart donc du produit d'origine.
	 *
	 * @param array $cart_item
	 * @param bool  $ht        True pour le montant hors taxe.
	 * @return float
	 */
	public static function prix_catalogue_ligne( $cart_item, $ht = false ) {
		$product_id = ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : ( $cart_item['product_id'] ?? 0 );
		$produit    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $produit ) {
			return 0.0;
		}

		$qty = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 1;

		return $ht
			? (float) wc_get_price_excluding_tax( $produit, [ 'qty' => $qty ] )
			: (float) wc_get_price_including_tax( $produit, [ 'qty' => $qty ] );
	}

	/**
	 * Somme des lignes du panier a leur prix catalogue (hors livraison).
	 *
	 * @param bool $ht
	 * @return float
	 */
	public static function sous_total_catalogue_panier( $ht = false ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		$total = 0.0;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$total += self::prix_catalogue_ligne( $cart_item, $ht );
		}

		return round( $total, wc_get_price_decimals() );
	}

	/**
	 * Total reel du panier : ce que le client devra au bout du compte,
	 * acompte et solde confondus.
	 *
	 * @param bool $ht
	 * @return float
	 */
	public static function total_catalogue_panier( $ht = false ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		$total = self::sous_total_catalogue_panier( $ht );

		$total += (float) WC()->cart->get_shipping_total();
		if ( ! $ht ) {
			$total += (float) WC()->cart->get_shipping_tax();
		}

		// La remise s'applique sur les prix reduits : on la reporte telle quelle,
		// c'est le seul montant que WooCommerce garantit coherent avec le panier.
		$total -= (float) WC()->cart->get_discount_total();
		if ( ! $ht ) {
			$total -= (float) WC()->cart->get_discount_tax();
		}

		return round( max( 0, $total ), wc_get_price_decimals() );
	}

	/**
	 * Montant reellement demande au client a la commande.
	 *
	 * @return float
	 */
	public static function acompte_panier() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		return round( (float) WC()->cart->get_total( 'edit' ), wc_get_price_decimals() );
	}

	/**
	 * Reste a payer apres l'acompte. 0 si la commande se regle en une fois.
	 *
	 * @return float
	 */
	public static function solde_panier() {
		return round( max( 0, self::total_catalogue_panier() - self::acompte_panier() ), wc_get_price_decimals() );
	}
	/**
	 * La commande est-elle encore en paiement fractionne ?
	 *
	 * Une fois le solde regle, WooCommerce porte deja les montants reels :
	 * il ne faut plus rien reecrire, sous peine d'afficher deux fois la meme
	 * chose ou de faire mentir le total.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private function commande_en_deux_temps( $order ) {
		return $order instanceof WC_Order
			&& $this->commande_contient_acompte( $order )
			&& self::PHASE_SOLDE_PAYE !== $order->get_meta( self::META_PHASE_PAIEMENT );
	}

	/**
	 * Total HT reel d'une commande, pendant du get_total_initial().
	 *
	 * @param WC_Order $order
	 * @return float
	 */
	public static function get_total_initial_ht( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 0.0;
		}

		$total = 0;

		foreach ( $order->get_items() as $item ) {
			$ht     = self::prix_initial_ht_ligne( $item );
			$total += null !== $ht ? $ht : (float) $item->get_total();
		}

		foreach ( $order->get_items( [ 'shipping', 'fee' ] ) as $item ) {
			$total += (float) $item->get_total();
		}

		$total -= (float) $order->get_discount_total();

		return round( max( 0, $total ), wc_get_price_decimals() );
	}

	/**
	 * Montant affiche pour une ligne de commande : son prix catalogue.
	 *
	 * @param string        $subtotal HTML calcule par WooCommerce.
	 * @param WC_Order_Item $item
	 * @param WC_Order      $order
	 * @return string
	 */
	public function afficher_prix_initial_ligne( $subtotal, $item, $order ) {
		if ( ! $this->commande_en_deux_temps( $order ) ) {
			return $subtotal;
		}

		$initial = self::prix_initial_ttc_ligne( $item );

		if ( null === $initial ) {
			return $subtotal;
		}

		return wc_price( $initial, [ 'currency' => $order->get_currency() ] );
	}

	/**
	 * Pied du recapitulatif : totaux reels, puis acompte et solde.
	 *
	 * @param array    $rows
	 * @param WC_Order $order
	 * @return array
	 */
	public function detailler_acompte_et_solde( $rows, $order ) {
		if ( ! $this->commande_en_deux_temps( $order ) ) {
			return $rows;
		}

		$devise = [ 'currency' => $order->get_currency() ];
		$total  = self::get_total_initial( $order );

		// 0 est un acompte valide : on ne se rabat sur le total qu'en l'absence de meta.
		$acompte_meta = $order->get_meta( self::META_ACOMPTE_PAYE );
		$acompte      = '' === $acompte_meta ? (float) $order->get_total() : (float) $acompte_meta;
		$solde        = round( max( 0, $total - $acompte ), wc_get_price_decimals() );

		// Sous-total : les lignes a leur prix catalogue, livraison exclue.
		if ( isset( $rows['cart_subtotal'] ) ) {
			$sous_total = 0;

			foreach ( $order->get_items() as $item ) {
				$initial     = self::prix_initial_ttc_ligne( $item );
				$sous_total += null !== $initial
					? $initial
					: (float) $item->get_total() + (float) $item->get_total_tax();
			}

			$rows['cart_subtotal']['value'] = wc_price( $sous_total, $devise );
		}

		// TVA du total reel, et non celle du seul acompte.
		$tva = round( $total - self::get_total_initial_ht( $order ), wc_get_price_decimals() );

		foreach ( $rows as $cle => $ligne ) {
			if ( 'tax' === $cle || 0 === strpos( (string) $cle, 'tax_' ) ) {
				$rows[ $cle ]['value'] = wc_price( $tva, $devise );
			}
		}

		if ( isset( $rows['order_total'] ) ) {
			$rows['order_total']['value'] = wc_price( $total, $devise );
		}

		if ( $solde <= 0 ) {
			return $rows;
		}

		$regle = $order->is_paid() || $order->has_status( 'acompte-paye' );

		$rows['kojito_acompte'] = [
			'label' => $regle
				? __( 'Acompte réglé', 'kojito-acompte' )
				: __( 'Acompte à régler', 'kojito-acompte' ),
			'value' => wc_price( $acompte, $devise ),
		];

		$rows['kojito_solde'] = [
			'label' => __( 'Solde après l’intervention', 'kojito-acompte' ),
			'value' => wc_price( $solde, $devise ),
		];

		return $rows;
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

		$total_initial   = $this->calculer_total_initial_commande( $order );
		$acompte_enregistre = $order->get_meta( self::META_ACOMPTE_PAYE );

		// 0 est une valeur d'acompte valide : on ne se rabat sur le total que si la meta est absente.
		$acompte_paye = '' === $acompte_enregistre
			? (float) $order->get_total()
			: (float) $acompte_enregistre;

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
		if ( ! $order instanceof WC_Order || ! $this->commande_contient_acompte( $order ) ) {
			return array_unique( $statuses );
		}

		$statuses[] = 'acompte-paye';

		/*
		 * Passerelles qui posent 'processing' au retour du navigateur, AVANT que
		 * leur webhook n'appelle payment_complete() : c'est le cas de CAWL /
		 * Worldline (OrderUpdater::adjustWcStatus, statusCode 9 = capture).
		 * Sans 'processing' dans la liste, payment_complete() ne declenche jamais
		 * woocommerce_payment_complete : l'acompte n'est pas enregistre, le statut
		 * acompte-paye n'est jamais pose et le workflow atelier reste bloque.
		 *
		 * La garde sur la phase de paiement limite l'ouverture au SEUL premier
		 * encaissement : des que l'acompte est enregistre la meta est posee, et
		 * une livraison de webhook en double ne rejoue rien.
		 */
		if ( '' === $order->get_meta( self::META_PHASE_PAIEMENT ) ) {
			$statuses[] = 'processing';
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

	/**
	 * Prix catalogue TTC d'une ligne de commande dont le prix a ete remplace par un acompte.
	 *
	 * @param WC_Order_Item $item
	 * @return float|null null si la ligne n'a pas ete facturee sous forme d'acompte.
	 */
	public static function prix_initial_ttc_ligne( $item ) {
		$brut = $item->get_meta( self::META_PRIX_TOTAL_INITIAL );

		if ( '' === $brut ) {
			return null;
		}

		// Commandes anterieures a la separation TTC/HT : la meta contient la valeur brute
		// du catalogue, donc deja TTC si les prix sont saisis TTC. wc_get_price_including_tax
		// n'ajoute la taxe que dans le cas contraire.
		if ( '' === $item->get_meta( self::META_PRIX_TOTAL_INITIAL_HT ) ) {
			$product = $item->get_product();

			if ( $product ) {
				return (float) wc_get_price_including_tax( $product, [ 'price' => (float) $brut, 'qty' => 1 ] );
			}
		}

		return (float) $brut;
	}

	/**
	 * Prix catalogue HT d'une ligne de commande, tel que WooCommerce le stocke.
	 *
	 * @param WC_Order_Item $item
	 * @return float|null null si la ligne n'a pas ete facturee sous forme d'acompte.
	 */
	public static function prix_initial_ht_ligne( $item ) {
		$ht = $item->get_meta( self::META_PRIX_TOTAL_INITIAL_HT );

		if ( '' !== $ht ) {
			return (float) $ht;
		}

		$brut = $item->get_meta( self::META_PRIX_TOTAL_INITIAL );

		if ( '' === $brut ) {
			return null;
		}

		$product = $item->get_product();

		if ( $product ) {
			return (float) wc_get_price_excluding_tax( $product, [ 'price' => (float) $brut, 'qty' => 1 ] );
		}

		return (float) $brut;
	}

	/**
	 * Montant total TTC reellement du par le client sur la commande (acompte + solde),
	 * c'est-a-dire le prix catalogue des prestations et non le montant de l'acompte.
	 *
	 * Source de verite unique : le plugin Gestion Atelier s'appuie sur cette methode
	 * pour la page de confirmation de commande.
	 *
	 * @param WC_Order $order
	 * @return float
	 */
	public static function get_total_initial( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 0.0;
		}

		$total = 0;

		foreach ( $order->get_items() as $item ) {
			$prix_initial_ttc = self::prix_initial_ttc_ligne( $item );

			// Les lignes sans acompte sont deja facturees a leur prix plein : on reconstitue
			// leur TTC a partir du total HT stocke par WooCommerce et de sa taxe.
			$total += null !== $prix_initial_ttc
				? $prix_initial_ttc
				: (float) $item->get_total() + (float) $item->get_total_tax();
		}

		foreach ( $order->get_items( [ 'shipping', 'fee' ] ) as $item ) {
			$total += (float) $item->get_total() + (float) $item->get_total_tax();
		}

		$total -= (float) $order->get_discount_total() + (float) $order->get_discount_tax();

		return round( max( 0, $total ), wc_get_price_decimals() );
	}

	private function calculer_total_initial_commande( $order ) {
		return self::get_total_initial( $order );
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
		$total_cible = self::get_total_initial( $order );

		foreach ( $order->get_items() as $item ) {
			$prix_initial_ht = self::prix_initial_ht_ligne( $item );

			if ( null === $prix_initial_ht ) {
				continue;
			}

			// Les totaux de lignes WooCommerce sont hors taxe : calculate_totals() se charge
			// ensuite de reappliquer la TVA pour retomber sur le prix catalogue TTC.
			$item->set_subtotal( $prix_initial_ht );
			$item->set_total( $prix_initial_ht );
			$item->save();
		}

		$order->calculate_totals( true );

		// L'arrondi ligne a ligne peut faire deriver le total de quelques centimes :
		// on impose le prix catalogue, seul montant annonce au client.
		if ( abs( (float) $order->get_total() - $total_cible ) >= 0.01 ) {
			$order->set_total( $total_cible );
		}

		$order->update_meta_data( self::META_TOTAL_INITIAL, $order->get_total() );
		$order->save();
	}
}

new Kojito_Acompte_Produit();
