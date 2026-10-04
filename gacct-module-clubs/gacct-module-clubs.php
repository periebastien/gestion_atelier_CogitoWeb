<?php
/**
 * Plugin Name: Gestion Atelier : commandes groupées des clubs
 * Description: Module complémentaire de Gestion Atelier. Un responsable de club demande une révision groupée, l'atelier réserve les jours et génère un code club, chaque pilote inscrit sa voile sans acompte avec le formulaire habituel, puis l'atelier facture le club en une seule commande et réexpédie le lot.
 * Version: 1.0.3
 * Requires Plugins: gestion-atelier-cct
 * Author: CogitoWeb
 *
 * ARCHITECTURE
 *
 * Le module ne modifie aucun fichier du socle. Il se branche sur les prises
 * neutres du socle (includes/gacct-extensions.php et filtres existants) :
 *
 *   gacct_occupation_counted_sql / _hours  réserve fixe : les occupations des
 *                                          pilotes du club ne comptent pas, la
 *                                          réserve compte à leur place ;
 *   gacct_order_skip_automation            pas de solde, relance, bascule sans
 *                                          suite ni rappel d'expédition pour un
 *                                          dossier de club ; facture club jamais
 *                                          annulée automatiquement ;
 *   gacct_order_third_party                écrans du pilote : « réglé par le club »,
 *                                          « remettez votre voile au club » ;
 *   gacct_op_console_views                 onglet « Clubs » de la console ;
 *   gacct_op_planning_event                réserve affichée sur le planning ;
 *   gacct_order_linked                     rattachement commande / dossier / lot ;
 *   gacct_pay_deposit_received_template    e-mail du pilote sans consignes d'envoi ;
 *   kojito_montant_acompte                 acompte nul pour un pilote du club.
 *
 * Désactiver ce plugin rend le site à son comportement d'origine ; les données
 * (tables gacct_club*, colonnes club_lot_id / hors_capacite) restent en base.
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

define( 'GACCT_CLUBS_VERSION', '1.0.3' );
define( 'GACCT_CLUBS_DB_VERSION', '1' );
define( 'GACCT_CLUBS_DIR', __DIR__ );
define( 'GACCT_CLUBS_URL', plugin_dir_url( __FILE__ ) );
define( 'GACCT_CLUBS_META_LOT', '_gacct_club_lot_id' );
define( 'GACCT_CLUBS_META_INVOICE', '_gacct_club_invoice_lot' );

/**
 * Le module n'a de sens qu'avec le socle et ses prises d'extension.
 */
function gacct_clubs_socle_present() {
	return function_exists( 'gacct_order_skip_automation' )
		&& function_exists( 'gacct_op_console_extra_views' )
		&& function_exists( 'jwcct_get_cct_item' )
		&& defined( 'JWCCT_CCT_REVISION' );
}

add_action( 'plugins_loaded', 'gacct_clubs_boot', 20 );

function gacct_clubs_boot() {
	if ( ! gacct_clubs_socle_present() ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p>Le module Clubs demande le plugin Gestion Atelier à jour (prises d’extension du 03/10/2026).</p></div>';
		} );
		return;
	}

	require_once GACCT_CLUBS_DIR . '/includes/install.php';
	require_once GACCT_CLUBS_DIR . '/includes/settings.php';
	require_once GACCT_CLUBS_DIR . '/includes/model.php';
	require_once GACCT_CLUBS_DIR . '/includes/capacity.php';
	require_once GACCT_CLUBS_DIR . '/includes/emails.php';
	require_once GACCT_CLUBS_DIR . '/includes/member.php';
	require_once GACCT_CLUBS_DIR . '/includes/manager-request.php';
	require_once GACCT_CLUBS_DIR . '/includes/billing.php';
	require_once GACCT_CLUBS_DIR . '/includes/console.php';
	require_once GACCT_CLUBS_DIR . '/includes/account.php';
	require_once GACCT_CLUBS_DIR . '/includes/cron.php';
}
