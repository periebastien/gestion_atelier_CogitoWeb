<?php
/**
 * Module Admin Opérateur (console atelier) — bootstrap.
 *
 * Rôle `atelier` + capacité `gacct_operate`, intégration au menu « Gestion
 * Atelier », redirection à la connexion, nettoyage de l'admin pour le rôle,
 * enqueue des assets de la console.
 *
 * Réf : CDC-admin-operateur.md (§2, §3, §7).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_OP_CAP', 'gacct_operate' );
define( 'GACCT_OP_MENU_SLUG', 'gacct-console' );
define( 'GACCT_OP_NONCE', 'gacct_op_nonce' );
define( 'GACCT_OP_ROLE', 'atelier' );
define( 'GACCT_OP_SETUP_OPT', 'gacct_op_setup_version' );
define( 'GACCT_OP_SETUP_VERSION', '2' );

require_once __DIR__ . '/gacct-operator-core.php';
require_once __DIR__ . '/gacct-operator-api.php';
require_once __DIR__ . '/screen-today.php';
require_once __DIR__ . '/screen-list.php';
require_once __DIR__ . '/screen-fiche.php';
require_once __DIR__ . '/screen-reception.php';
require_once __DIR__ . '/screen-planning.php';

/**
 * Création du rôle + distribution de la capacité + champ CCT operateur_id.
 * Idempotent, gardé par une option de version.
 */
function gacct_op_maybe_setup() {
	if ( get_option( GACCT_OP_SETUP_OPT ) === GACCT_OP_SETUP_VERSION ) {
		return;
	}

	if ( ! get_role( GACCT_OP_ROLE ) ) {
		add_role(
			GACCT_OP_ROLE,
			__( 'Opérateur atelier', 'gestion-atelier-cct' ),
			array(
				'read'       => true,
				GACCT_OP_CAP => true,
			)
		);
	}

	foreach ( array( 'administrator', 'shop_manager' ) as $role_slug ) {
		$role = get_role( $role_slug );
		if ( $role && ! $role->has_cap( GACCT_OP_CAP ) ) {
			$role->add_cap( GACCT_OP_CAP );
		}
	}

	gacct_op_install_operator_field();

	update_option( GACCT_OP_SETUP_OPT, GACCT_OP_SETUP_VERSION );
}
add_action( 'init', 'gacct_op_maybe_setup', 5 );

/**
 * Vrai si l'utilisateur est un opérateur « pur » (rôle atelier, pas admin).
 */
function gacct_op_is_pure_operator( $user = null ) {
	$user = $user ? $user : wp_get_current_user();

	if ( ! $user || ! $user->exists() ) {
		return false;
	}

	return in_array( GACCT_OP_ROLE, (array) $user->roles, true )
		&& ! user_can( $user, 'manage_options' )
		&& ! user_can( $user, 'manage_woocommerce' );
}

/**
 * URL de la console (liste) ou d'une fiche.
 */
function gacct_op_console_url( $revision_id = 0, array $extra = array() ) {
	$args = array( 'page' => GACCT_OP_MENU_SLUG );

	if ( $revision_id ) {
		$args['revision'] = absint( $revision_id );
	}

	return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
}

/**
 * Vue console courante : today (défaut) | list | reception | fiche (?revision=).
 */
function gacct_op_current_view() {
	if ( ! empty( $_GET['revision'] ) ) {
		return 'fiche';
	}

	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';

	return in_array( $view, array( 'list', 'reception', 'planning' ), true ) ? $view : 'today';
}

/**
 * Barre de navigation interne de la console, affichée sur toutes les vues :
 * les 3 écrans + le champ « scan ou référence » en accès direct (CDC §4.4).
 */
function gacct_op_render_console_nav( $active ) {
	$tabs = array(
		'today'     => array( gacct_op_console_url(), __( 'Aujourd\'hui', 'gestion-atelier-cct' ) ),
		'list'      => array( gacct_op_console_url( 0, array( 'view' => 'list' ) ), __( 'Interventions', 'gestion-atelier-cct' ) ),
		'reception' => array( gacct_op_console_url( 0, array( 'view' => 'reception' ) ), __( 'Réception colis', 'gestion-atelier-cct' ) ),
		'planning'  => array( gacct_op_console_url( 0, array( 'view' => 'planning' ) ), __( 'Planning', 'gestion-atelier-cct' ) ),
	);

	echo '<nav class="gacct-op-nav">';
	foreach ( $tabs as $key => $tab ) {
		$class = 'gacct-op-nav-link' . ( $key === $active ? ' is-active' : '' );
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $tab[0] ) . '">' . esc_html( $tab[1] ) . '</a>';
	}

	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="gacct-op-nav-scan">';
	echo '<input type="hidden" name="page" value="' . esc_attr( GACCT_OP_MENU_SLUG ) . '">';
	echo '<input type="hidden" name="view" value="reception">';
	echo '<input type="search" name="ref" placeholder="' . esc_attr__( 'Scan ou référence…', 'gestion-atelier-cct' ) . '" value="">';
	echo '<button type="submit" class="gacct-op-btn secondary">' . esc_html__( 'Ouvrir', 'gestion-atelier-cct' ) . '</button>';
	echo '</form>';
	echo '</nav>';
}

/**
 * Routeur de la page console.
 */
function gacct_op_render_console() {
	if ( ! current_user_can( GACCT_OP_CAP ) ) {
		wp_die( esc_html__( 'Acces refuse.', 'gestion-atelier-cct' ) );
	}

	$view = gacct_op_current_view();

	echo '<div class="wrap gacct-op gacct-op-navwrap">';
	// La fiche est rattachée visuellement à la liste des interventions.
	gacct_op_render_console_nav( 'fiche' === $view ? 'list' : $view );
	echo '</div>';

	switch ( $view ) {
		case 'fiche':
			gacct_op_render_fiche_screen( absint( $_GET['revision'] ) );
			break;
		case 'list':
			gacct_op_render_list_screen();
			break;
		case 'reception':
			gacct_op_render_reception_screen();
			break;
		case 'planning':
			gacct_op_render_planning_screen();
			break;
		default:
			gacct_op_render_today_screen();
	}
}

/**
 * Redirection à la connexion : un opérateur pur atterrit sur la console.
 */
function gacct_op_login_redirect( $redirect_to, $requested, $user ) {
	if ( $user instanceof WP_User && gacct_op_is_pure_operator( $user ) ) {
		return gacct_op_console_url();
	}

	return $redirect_to;
}
add_filter( 'login_redirect', 'gacct_op_login_redirect', 20, 3 );

/**
 * WooCommerce expulse de wp-admin les utilisateurs sans edit_posts /
 * manage_woocommerce (WC_Admin::prevent_admin_access) : un opérateur
 * atelier doit pouvoir atteindre la console.
 */
function gacct_op_allow_admin_access( $prevent ) {
	if ( current_user_can( GACCT_OP_CAP ) ) {
		return false;
	}

	return $prevent;
}
add_filter( 'woocommerce_prevent_admin_access', 'gacct_op_allow_admin_access' );

/**
 * Écran admin refusé (ex. edit.php) : retour console plutôt que wp_die.
 */
function gacct_op_access_denied_redirect() {
	if ( gacct_op_is_pure_operator() ) {
		wp_safe_redirect( gacct_op_console_url() );
		exit;
	}
}
add_action( 'admin_page_access_denied', 'gacct_op_access_denied_redirect' );

/**
 * Menus admin réduits pour le rôle atelier : seule la console reste
 * (le menu Profil est retiré mais profile.php reste accessible pour
 * changer son mot de passe).
 */
function gacct_op_trim_admin_menu() {
	if ( ! gacct_op_is_pure_operator() ) {
		return;
	}

	global $menu;

	if ( is_array( $menu ) ) {
		foreach ( $menu as $position => $item ) {
			$slug = isset( $item[2] ) ? $item[2] : '';
			if ( $slug && GACCT_OP_MENU_SLUG !== $slug && false === strpos( $slug, 'separator' ) ) {
				remove_menu_page( $slug );
			}
		}
	}
}
add_action( 'admin_menu', 'gacct_op_trim_admin_menu', 999 );

/**
 * Un opérateur pur qui demande un écran admin hors console est ramené à la console.
 */
function gacct_op_lock_admin_screens() {
	if ( ! gacct_op_is_pure_operator() || wp_doing_ajax() ) {
		return;
	}

	$allowed_files = array( 'admin.php', 'profile.php', 'admin-post.php', 'async-upload.php' );
	$pagenow       = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';

	if ( ! in_array( $pagenow, $allowed_files, true ) ) {
		wp_safe_redirect( gacct_op_console_url() );
		exit;
	}

	if ( 'admin.php' === $pagenow ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( GACCT_OP_MENU_SLUG !== $page ) {
			wp_safe_redirect( gacct_op_console_url() );
			exit;
		}
	}
}
add_action( 'admin_init', 'gacct_op_lock_admin_screens', 1 );

/**
 * Barre d'admin réduite pour le rôle atelier.
 */
function gacct_op_trim_admin_bar( $wp_admin_bar ) {
	if ( ! gacct_op_is_pure_operator() ) {
		return;
	}

	foreach ( array( 'wp-logo', 'comments', 'new-content', 'updates', 'search', 'customize', 'edit' ) as $node ) {
		$wp_admin_bar->remove_node( $node );
	}
}
add_action( 'admin_bar_menu', 'gacct_op_trim_admin_bar', 999 );

/**
 * Assets de la console (uniquement sur son écran).
 */
function gacct_op_enqueue_assets( $hook_suffix ) {
	if ( 'toplevel_page_' . GACCT_OP_MENU_SLUG !== $hook_suffix ) {
		return;
	}

	$base = untrailingslashit( plugin_dir_url( dirname( __DIR__, 2 ) . '/gestion-atelier-cct.php' ) );

	wp_enqueue_style(
		'gacct-operator',
		$base . '/assets/css/operator.css',
		array(),
		GACCT_Plugin::VERSION
	);

	$screen_css_map = array(
		'fiche'     => 'operator-fiche.css',
		'list'      => 'operator-list.css',
		'reception' => 'operator-reception.css',
		'planning'  => 'operator-planning.css',
		'today'     => 'operator-today.css',
	);
	$screen_css = $screen_css_map[ gacct_op_current_view() ];

	wp_enqueue_style(
		'gacct-operator-screen',
		$base . '/assets/css/' . $screen_css,
		array( 'gacct-operator' ),
		GACCT_Plugin::VERSION
	);

	wp_enqueue_script(
		'gacct-operator',
		$base . '/assets/js/operator.js',
		array(),
		GACCT_Plugin::VERSION,
		true
	);

	// Fiche : formulaires de rapports de contrôle (calculs temps réel).
	if ( 'fiche' === gacct_op_current_view() && function_exists( 'gacct_report_calc_config' ) ) {
		wp_enqueue_style(
			'gacct-operator-report',
			$base . '/assets/css/operator-report.css',
			array( 'gacct-operator' ),
			GACCT_Plugin::VERSION
		);
		wp_enqueue_script(
			'gacct-operator-report',
			$base . '/assets/js/operator-report.js',
			array( 'gacct-operator' ),
			GACCT_Plugin::VERSION,
			true
		);
		// Source unique PHP des seuils/coefs, mise en miroir côté JS.
		wp_localize_script( 'gacct-operator-report', 'gacctReportCfg', gacct_report_calc_config() );
	}

	if ( 'reception' === gacct_op_current_view() ) {
		wp_enqueue_script(
			'gacct-operator-reception',
			$base . '/assets/js/operator-reception.js',
			array( 'gacct-operator' ),
			GACCT_Plugin::VERSION,
			true
		);
	}

	if ( 'planning' === gacct_op_current_view() ) {
		wp_enqueue_script(
			'fullcalendar',
			$base . '/assets/vendor/fullcalendar/index.global.min.js',
			array(),
			'6.1.15',
			true
		);
		wp_enqueue_script(
			'fullcalendar-locale-fr',
			$base . '/assets/vendor/fullcalendar/fr.global.min.js',
			array( 'fullcalendar' ),
			'6.1.15',
			true
		);
		wp_enqueue_script(
			'gacct-operator-planning',
			$base . '/assets/js/operator-planning.js',
			array( 'gacct-operator', 'fullcalendar-locale-fr' ),
			GACCT_Plugin::VERSION,
			true
		);
	}

	wp_localize_script(
		'gacct-operator',
		'gacctOp',
		array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( GACCT_OP_NONCE ),
			'consoleUrl' => gacct_op_console_url(),
			'i18n'       => array(
				'confirmCancel'  => __( 'Annuler définitivement ce dossier ? Le créneau sera libéré et le client prévenu par email.', 'gestion-atelier-cct' ),
				'reasonRequired' => __( 'Un motif est obligatoire pour cette action.', 'gestion-atelier-cct' ),
				'genericError'   => __( 'Une erreur est survenue. Réessayez.', 'gestion-atelier-cct' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'gacct_op_enqueue_assets' );
