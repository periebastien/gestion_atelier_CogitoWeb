<?php
/**
 * Plugin Name: Atelier Secure Vault
 * Description: Filet de sécurité : range les PDF du champ JetEngine CCT revision/rapport_pdf dans le coffre-fort uploads protégé.
 * Version: 2.0.0
 *
 * ARCHITECTURE (refonte du 28/07/2026) — tout vit désormais dans le plugin
 * gestion-atelier-cct, fichier `includes/gacct-reports.php` :
 *   - création et protection du coffre `uploads/atelier_secure_vault_x9f2/`
 *     (`gacct_report_vault_dir()`, .htaccess « Require all denied ») ;
 *   - déplacement d'une pièce jointe dans le coffre
 *     (`gacct_report_move_to_vault()`) ;
 *   - PORTE DE SORTIE : endpoint authentifié `/?gacct_report=<revision_id>`
 *     (atelier/admin dès l'état 6, client propriétaire à partir de l'état 7).
 * L'upload par la console atelier écrit directement dans le coffre.
 *
 * Ce mu-plugin ne garde que le filet de sécurité pour un dépôt fait depuis
 * l'admin WordPress classique (édition d'un item CCT « revision ») : après la
 * sauvegarde, la table CCT contient l'ID média exact, on range le fichier.
 * Les anciennes heuristiques (JS injecté dans admin_footer, fouille de
 * $_REQUEST / du referer, logs de debug) ont été supprimées : elles étaient
 * fragiles et n'ont plus lieu d'être.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ATELIER_VAULT_DIRNAME' ) ) {
	define( 'ATELIER_VAULT_DIRNAME', 'atelier_secure_vault_x9f2' );
}

if ( ! defined( 'ATELIER_VAULT_CCT_SLUG' ) ) {
	define( 'ATELIER_VAULT_CCT_SLUG', 'revision' );
}

if ( ! defined( 'ATELIER_VAULT_FIELD' ) ) {
	define( 'ATELIER_VAULT_FIELD', 'rapport_pdf' );
}

/**
 * Repère une sauvegarde d'item CCT « revision » depuis l'admin JetEngine.
 */
add_action(
	'admin_init',
	function () {
		$page       = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$cct_action = isset( $_GET['cct_action'] ) ? sanitize_key( wp_unslash( $_GET['cct_action'] ) ) : '';
		$item_id    = isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : 0;

		if ( 'jet-cct-' . ATELIER_VAULT_CCT_SLUG !== $page || 'jet-cct-save-item' !== $cct_action || ! $item_id ) {
			return;
		}

		$GLOBALS['atelier_vault_pending_cct_revision_items'][] = $item_id;
	},
	1
);

/**
 * En fin de requête, la table CCT est à jour : on range les fichiers.
 * Toute la logique est déléguée au plugin (gacct_report_sync_revision()).
 */
add_action(
	'shutdown',
	function () {
		if ( empty( $GLOBALS['atelier_vault_pending_cct_revision_items'] ) || ! function_exists( 'gacct_report_sync_revision' ) ) {
			return;
		}

		foreach ( array_unique( array_map( 'absint', $GLOBALS['atelier_vault_pending_cct_revision_items'] ) ) as $item_id ) {
			gacct_report_sync_revision( $item_id );
		}
	}
);
