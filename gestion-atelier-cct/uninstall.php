<?php
/**
 * Désinstallation du plugin Gestion Atelier CCT.
 *
 * Ne supprime QUE la configuration du plugin : options, crons, rôle et
 * capacité. Les données métier restent en place volontairement — CCT
 * (révisions, occupations, calendrier), coffre des rapports PDF dans
 * uploads/, metas de commandes WooCommerce : elles appartiennent au
 * client de l'atelier, pas au plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Toutes les options du plugin (préfixes gacct_ / jwcct_).
$option_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = %s",
		$wpdb->esc_like( 'gacct_' ) . '%',
		'jwcct_debug_snapshot'
	)
);

foreach ( $option_names as $option_name ) {
	delete_option( $option_name );
}

// Crons du module paiements & relances.
wp_clear_scheduled_hook( 'gacct_pay_hourly_tick' );
wp_clear_scheduled_hook( 'gacct_pay_midnight_purge' );

// Capacité de la console retirée des rôles qui l'avaient reçue.
foreach ( array( 'administrator', 'shop_manager' ) as $role_slug ) {
	$role = get_role( $role_slug );
	if ( $role && $role->has_cap( 'gacct_operate' ) ) {
		$role->remove_cap( 'gacct_operate' );
	}
}

// Rôle opérateur. Les comptes qui le portaient perdent ce rôle mais
// restent des utilisateurs WordPress (à réaffecter manuellement).
remove_role( 'atelier' );
