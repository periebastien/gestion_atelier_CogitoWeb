<?php
/**
 * Tables responsives de l'espace client (Mes demandes d'intervention / Mon Matériel).
 *
 * - Enqueue de assets/css/client-tables.css + assets/js/client-tables.js sur
 *   l'espace client (mêmes conditions que dashboard.css : page compte du
 *   Profile Builder, sous-pages comprises — via gacct_dash_should_enqueue()).
 * - Shortcode [gacct_interventions_toolbar] : barre stats + onglets + recherche
 *   au-dessus du tableau « Mes demandes d'intervention » (template 521).
 *   Le filtrage (onglets, recherche) est fait côté client par client-tables.js.
 *
 * Les compteurs viennent de gacct_dash_data() (source unique de la donnée du
 * tableau de bord) + gacct_dash_revision_rows() pour le nombre de dossiers
 * terminés (état 7), qui n'est pas dans les compteurs du dashboard.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
 *  ASSETS
 * ============================================================================= */

add_action( 'wp_enqueue_scripts', 'gacct_client_tables_enqueue_assets' );

/**
 * Charge CSS + JS des tables client sur l'espace client uniquement.
 */
function gacct_client_tables_enqueue_assets() {
	if ( ! function_exists( 'gacct_dash_should_enqueue' ) || ! gacct_dash_should_enqueue() ) {
		return;
	}

	$base_url = plugins_url( '', dirname( __FILE__ ) );
	$base_dir = dirname( __DIR__ );
	$css_rel  = 'assets/css/client-tables.css';
	$js_rel   = 'assets/js/client-tables.js';

	if ( file_exists( $base_dir . '/' . $css_rel ) ) {
		wp_enqueue_style(
			'gacct-client-tables',
			$base_url . '/' . $css_rel,
			array(),
			(string) filemtime( $base_dir . '/' . $css_rel )
		);
	}

	if ( file_exists( $base_dir . '/' . $js_rel ) ) {
		wp_enqueue_script(
			'gacct-client-tables',
			$base_url . '/' . $js_rel,
			array(),
			(string) filemtime( $base_dir . '/' . $js_rel ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}

/* =============================================================================
 *  SHORTCODE : TOOLBAR « MES DEMANDES D'INTERVENTION »
 * ============================================================================= */

add_shortcode( 'gacct_interventions_toolbar', 'gacct_interventions_toolbar_shortcode' );

/**
 * Barre stats (4 compteurs) + onglets En cours / Terminées / Toutes + recherche.
 *
 * Markup préfixé gacct-tb-, stylé dans client-tables.css, piloté par
 * client-tables.js (filtrage client-side des lignes du #scroll).
 *
 * @return string HTML.
 */
function gacct_interventions_toolbar_shortcode() {
	$user_id = get_current_user_id();

	if ( ! $user_id ) {
		return '';
	}

	$counters = array(
		'actions'   => 0,
		'en_cours'  => 0,
		'terminees' => 0,
		'materiels' => 0,
	);

	if ( function_exists( 'gacct_dash_data' ) ) {
		$dash = gacct_dash_data( $user_id );

		$counters['actions']   = (int) ( $dash['counters']['actions'] ?? 0 );
		$counters['en_cours']  = (int) ( $dash['counters']['revisions'] ?? 0 );
		$counters['materiels'] = (int) ( $dash['counters']['materiels'] ?? 0 );
	}

	if ( function_exists( 'gacct_dash_revision_rows' ) ) {
		foreach ( gacct_dash_revision_rows( $user_id ) as $row ) {
			if ( 7 === (int) $row['etat_de_la_commande'] ) {
				$counters['terminees']++;
			}
		}
	}

	$total = $counters['en_cours'] + $counters['terminees'];

	$stats = array(
		array(
			'tone'  => 'is-orange',
			'icon'  => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
			'num'   => $counters['actions'],
			'label' => __( 'Action requise', 'gestion-atelier-cct' ),
		),
		array(
			'tone'  => 'is-blue',
			'icon'  => '<svg viewBox="0 0 24 24"><path d="M16 3h5v5M21 3l-7 7M4 21l7-7M3 16v5h5"/></svg>',
			'num'   => $counters['en_cours'],
			'label' => __( 'En cours', 'gestion-atelier-cct' ),
		),
		array(
			'tone'  => 'is-green',
			'icon'  => '<svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>',
			'num'   => $counters['terminees'],
			'label' => __( 'Terminées', 'gestion-atelier-cct' ),
		),
		array(
			'tone'  => 'is-teal',
			'icon'  => '<svg viewBox="0 0 24 24"><path d="M12 2l3 6 6 1-4.5 4 1 6L12 16l-5.5 3 1-6L3 9l6-1z"/></svg>',
			'num'   => $counters['materiels'],
			'label' => __( 'Voiles enregistrées', 'gestion-atelier-cct' ),
		),
	);

	$stats_html = '';

	foreach ( $stats as $stat ) {
		$stats_html .= sprintf(
			'<div class="gacct-tb-stat">
				<div class="gacct-tb-stat-icon %1$s">%2$s</div>
				<div>
					<div class="gacct-tb-stat-num">%3$d</div>
					<div class="gacct-tb-stat-label">%4$s</div>
				</div>
			</div>',
			esc_attr( $stat['tone'] ),
			$stat['icon'],
			$stat['num'],
			esc_html( $stat['label'] )
		);
	}

	$tabs = array(
		'encours'   => array( __( 'En cours', 'gestion-atelier-cct' ), $counters['en_cours'] ),
		'terminees' => array( __( 'Terminées', 'gestion-atelier-cct' ), $counters['terminees'] ),
		'toutes'    => array( __( 'Toutes', 'gestion-atelier-cct' ), $total ),
	);

	$tabs_html = '';
	$first     = true;

	foreach ( $tabs as $key => $tab ) {
		$tabs_html .= sprintf(
			'<button type="button" class="gacct-tb-tab%1$s" data-gacct-tab="%2$s" role="tab" aria-selected="%3$s">%4$s <span class="gacct-tb-tab-count">%5$d</span></button>',
			$first ? ' is-active' : '',
			esc_attr( $key ),
			$first ? 'true' : 'false',
			esc_html( $tab[0] ),
			(int) $tab[1]
		);
		$first = false;
	}

	return sprintf(
		'<div class="gacct-tb" data-gacct-toolbar="interventions">
			<div class="gacct-tb-stats">%1$s</div>
			<div class="gacct-tb-toolbar">
				<div class="gacct-tb-tabs" role="tablist">%2$s</div>
				<div class="gacct-tb-search">
					<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
					<input type="search" class="gacct-tb-search-input" placeholder="%3$s" aria-label="%4$s">
				</div>
			</div>
		</div>',
		$stats_html,
		$tabs_html,
		esc_attr__( 'Rechercher (n° commande, voile…)', 'gestion-atelier-cct' ),
		esc_attr__( 'Rechercher dans mes demandes', 'gestion-atelier-cct' )
	);
}
