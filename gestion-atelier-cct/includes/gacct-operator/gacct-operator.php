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
define( 'GACCT_OP_SETUP_VERSION', '4' );

require_once __DIR__ . '/gacct-operator-core.php';
require_once __DIR__ . '/gacct-operator-api.php';
require_once __DIR__ . '/screen-today.php';
require_once __DIR__ . '/screen-list.php';
require_once __DIR__ . '/screen-fiche.php';
require_once __DIR__ . '/screen-reception.php';
require_once __DIR__ . '/screen-planning.php';
require_once __DIR__ . '/screen-profil.php';

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

	return in_array( $view, array( 'list', 'reception', 'planning', 'profil' ), true ) ? $view : 'today';
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

	echo '<nav class="nav-tab-wrapper gacct-op-nav">';
	foreach ( $tabs as $key => $tab ) {
		$class = 'nav-tab' . ( $key === $active ? ' nav-tab-active' : '' );
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $tab[0] ) . '">' . esc_html( $tab[1] ) . '</a>';
	}

	// « Mon profil » (08/09/2026) : photo et nom de l'opérateur, comme dans l'espace client.
	$me       = wp_get_current_user();
	$me_photo = function_exists( 'gacct_profile_avatar_id' ) && gacct_profile_avatar_id( $me->ID ) && function_exists( 'gacct_dash_avatar_url' ) ? gacct_dash_avatar_url( $me->ID, 64 ) : '';
	echo '<a class="nav-tab gacct-op-nav-me' . ( 'profil' === $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( gacct_op_console_url( 0, array( 'view' => 'profil' ) ) ) . '" title="' . esc_attr__( 'Mon profil', 'gestion-atelier-cct' ) . '">';
	if ( $me_photo ) {
		echo '<img src="' . esc_url( $me_photo ) . '" alt="" class="gacct-op-nav-avatar">';
	} else {
		echo '<span class="gacct-op-nav-avatar gacct-op-nav-avatar-ini">' . esc_html( function_exists( 'gacct_dash_initials' ) ? gacct_dash_initials( $me->ID ) : mb_strtoupper( mb_substr( $me->display_name, 0, 1 ) ) ) . '</span>';
	}
	echo '<span class="gacct-op-nav-me-name">' . esc_html( $me->display_name ) . '</span></a>';

	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="gacct-op-nav-scan">';
	echo '<input type="hidden" name="page" value="' . esc_attr( GACCT_OP_MENU_SLUG ) . '">';
	echo '<input type="hidden" name="view" value="reception">';
	echo '<input type="search" name="ref" placeholder="' . esc_attr__( 'Scan ou référence…', 'gestion-atelier-cct' ) . '" value="">';
	echo '<button type="submit" class="button">' . esc_html__( 'Ouvrir', 'gestion-atelier-cct' ) . '</button>';
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
		case 'profil':
			gacct_op_render_profil_screen();
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
 * Écrans admin (admin.php?page=…) ouverts aux opérateurs purs : la console, plus
 * ce que d'autres modules déclarent (filtre `gacct_op_allowed_pages`, ex. les
 * révisions importées de gacct-import-historique, 11/09/2026).
 */
function gacct_op_allowed_pages() {
	$pages = apply_filters( 'gacct_op_allowed_pages', array( GACCT_OP_MENU_SLUG ) );
	return array_values( array_unique( array_filter( array_map( 'strval', (array) $pages ) ) ) );
}

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
			if ( $slug && ! in_array( $slug, gacct_op_allowed_pages(), true ) && false === strpos( $slug, 'separator' ) ) {
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
		if ( ! in_array( $page, gacct_op_allowed_pages(), true ) ) {
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

/* =============================================================================
 *  MODE TABLETTE (08/09/2026) : un opérateur pur ne voit QUE la console.
 *  Décision Bastien : les opérateurs travaillent chacun sur une tablette,
 *  rien de WordPress ne doit les distraire (avis des plugins, onglets « Aide »
 *  et « Options de l'écran », pied de page, menu latéral déplié).
 * ============================================================================= */

/**
 * Aucun avis d'administration (mises à jour, plugins, WooCommerce…) pour le
 * rôle atelier : la console a ses propres zones de retour.
 */
function gacct_op_tablet_silence_notices() {
	if ( ! gacct_op_is_pure_operator() ) {
		return;
	}

	foreach ( array( 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' ) as $hook ) {
		remove_all_actions( $hook );
	}
}
add_action( 'admin_head', 'gacct_op_tablet_silence_notices', 0 );
add_action( 'in_admin_header', 'gacct_op_tablet_silence_notices', 0 );

/**
 * Pas d'onglets « Aide » ni « Options de l'écran », pas de pied de page WordPress.
 */
add_filter( 'screen_options_show_screen', function ( $show ) {
	return gacct_op_is_pure_operator() ? false : $show;
} );

add_action( 'current_screen', function ( $screen ) {
	if ( gacct_op_is_pure_operator() && $screen instanceof WP_Screen ) {
		$screen->remove_help_tabs();
	}
}, 999 );

add_filter( 'admin_footer_text', function ( $text ) {
	return gacct_op_is_pure_operator() ? '' : $text;
}, 999 );

add_filter( 'update_footer', function ( $text ) {
	return gacct_op_is_pure_operator() ? '' : $text;
}, 999 );

/**
 * Menu latéral replié (icône seule) : la console occupe toute la largeur de
 * la tablette, la navigation se fait par la barre d'onglets de la console.
 */
add_filter( 'admin_body_class', function ( $classes ) {
	return gacct_op_is_pure_operator() ? $classes . ' folded gacct-op-tablet' : $classes;
} );

/**
 * Barre d'admin réduite au strict nécessaire : nom du site (retour au site)
 * et menu du compte (mot de passe, déconnexion).
 */
add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
	if ( ! gacct_op_is_pure_operator() ) {
		return;
	}
	foreach ( array( 'view-site', 'archive', 'my-sites', 'user-info' ) as $node ) {
		$wp_admin_bar->remove_node( $node );
	}
}, 1000 );

/**
 * Session longue sur la tablette de l'opérateur : 30 jours (au lieu de 2 jours,
 * 14 avec « Se souvenir de moi »), pour ne pas ressaisir le mot de passe chaque
 * matin. La tablette est individuelle (décision du 08/09/2026).
 */
add_filter( 'auth_cookie_expiration', function ( $length, $user_id, $remember ) {
	$user = get_user_by( 'id', $user_id );

	if ( $user && gacct_op_is_pure_operator( $user ) ) {
		return (int) apply_filters( 'gacct_op_session_days', 30 ) * DAY_IN_SECONDS;
	}

	return $length;
}, 10, 3 );

/**
 * Sur la tablette, l'onglet du navigateur porte le nom de la console et la
 * couleur d'interface, pour l'épingler sur l'écran d'accueil.
 */
add_action( 'admin_head', function () {
	if ( ! gacct_op_is_pure_operator() ) {
		return;
	}
	echo '<meta name="theme-color" content="#1d2327">' . "\n";
	echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr__( 'Console atelier', 'gestion-atelier-cct' ) . '">' . "\n";
	echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
} );

add_filter( 'admin_title', function ( $admin_title, $title ) {
	return gacct_op_is_pure_operator() ? $title . ' · ' . __( 'Console atelier', 'gestion-atelier-cct' ) : $admin_title;
}, 10, 2 );

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
		gacct_asset_version( 'assets/css/operator.css' )
	);

	$screen_css_map = array(
		'fiche'     => 'operator-fiche.css',
		'list'      => 'operator-list.css',
		'reception' => 'operator-reception.css',
		'planning'  => 'operator-planning.css',
		'profil'    => 'operator-profil.css',
		'today'     => 'operator-today.css',
	);
	$screen_css = $screen_css_map[ gacct_op_current_view() ];

	wp_enqueue_style(
		'gacct-operator-screen',
		$base . '/assets/css/' . $screen_css,
		array( 'gacct-operator' ),
		gacct_asset_version( 'assets/css/' . $screen_css )
	);

	wp_enqueue_script(
		'gacct-operator',
		$base . '/assets/js/operator.js',
		array(),
		gacct_asset_version( 'assets/js/operator.js' ),
		true
	);

	// Fiche : formulaires de rapports de contrôle (calculs temps réel).
	if ( 'fiche' === gacct_op_current_view() && function_exists( 'gacct_report_calc_config' ) ) {
		wp_enqueue_style(
			'gacct-operator-report',
			$base . '/assets/css/operator-report.css',
			array( 'gacct-operator' ),
			gacct_asset_version( 'assets/css/operator-report.css' )
		);
		wp_enqueue_script(
			'gacct-operator-report',
			$base . '/assets/js/operator-report.js',
			array( 'gacct-operator' ),
			gacct_asset_version( 'assets/js/operator-report.js' ),
			true
		);
		// Source unique PHP des seuils/coefs du PACK, mise en miroir côté JS.
		if ( function_exists( 'gacct_report_calc_config' ) ) {
			wp_localize_script( 'gacct-operator-report', 'gacctReportCfg', gacct_report_calc_config() );
		}

		// JS des modèles du pack actif (calculs temps réel), après le framework.
		$pack_handles = array();
		foreach ( gacct_report_models_full() as $model_def ) {
			foreach ( (array) ( $model_def['js'] ?? array() ) as $handle => $src ) {
				if ( ! isset( $pack_handles[ $handle ] ) ) {
					$pack_handles[ $handle ] = true;
					wp_enqueue_script( $handle, $src, array( 'gacct-operator-report' ), GACCT_Plugin::VERSION, true );
				}
			}
		}
	}

	if ( 'reception' === gacct_op_current_view() ) {
		wp_enqueue_script(
			'gacct-operator-reception',
			$base . '/assets/js/operator-reception.js',
			array( 'gacct-operator' ),
			gacct_asset_version( 'assets/js/operator-reception.js' ),
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
			gacct_asset_version( 'assets/js/operator-planning.js' ),
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
			'canManage'  => current_user_can( gacct_op_reschedule_admin_cap() ),
			'i18n'       => array(
				'confirmCancel'  => __( 'Annuler définitivement ce dossier ? Le créneau sera libéré et le client prévenu par email.', 'gestion-atelier-cct' ),
				'reasonRequired' => __( 'Un motif est obligatoire pour cette action.', 'gestion-atelier-cct' ),
				'genericError'   => __( 'Une erreur est survenue. Réessayez.', 'gestion-atelier-cct' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'gacct_op_enqueue_assets' );
