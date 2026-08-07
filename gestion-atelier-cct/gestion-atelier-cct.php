<?php
/**
 * Plugin Name: Gestion Atelier CCT
 * Description: Tableau de bord atelier et génération d'ouvertures pour les CCT JetEngine.
 * Version: 1.1.2
 * Author: Atelier
 * Text Domain: gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_PLUGIN_FILE', __FILE__ );

/**
 * Version de cache-busting d'un asset du plugin : filemtime du fichier
 * (toujours juste, même sans bump de version), repli sur la constante.
 *
 * @param string $relative Chemin relatif à la racine du plugin (ex. 'assets/css/operator.css').
 * @return string
 */
function gacct_asset_version( $relative ) {
	$path = plugin_dir_path( GACCT_PLUGIN_FILE ) . ltrim( $relative, '/' );
	$time = file_exists( $path ) ? filemtime( $path ) : false;

	return $time ? (string) $time : GACCT_Plugin::VERSION;
}

require_once __DIR__ . '/includes/gacct-checkout.php';
require_once __DIR__ . '/includes/gacct-payments.php';
require_once __DIR__ . '/includes/gacct-thankyou.php';
require_once __DIR__ . '/includes/gacct-reports.php';
require_once __DIR__ . '/includes/gacct-report-forms.php';
require_once __DIR__ . '/includes/gacct-report-forms-ui.php';
require_once __DIR__ . '/includes/gacct-frontend.php';
require_once __DIR__ . '/includes/gacct-frontend-form.php';
require_once __DIR__ . '/includes/gacct-dashboard.php';
require_once __DIR__ . '/includes/gacct-profile.php';
require_once __DIR__ . '/includes/gacct-debug.php';
require_once __DIR__ . '/includes/gacct-login-gate.php';
require_once __DIR__ . '/includes/gacct-workorder.php';
require_once __DIR__ . '/includes/gacct-quote.php';
require_once __DIR__ . '/includes/gacct-billing.php';
require_once __DIR__ . '/includes/gacct-signature.php';
require_once __DIR__ . '/includes/gacct-vieworder.php';
require_once __DIR__ . '/includes/gacct-shipping.php';
require_once __DIR__ . '/includes/gacct-client-tables.php';
require_once __DIR__ . '/includes/gacct-operator/gacct-operator.php';

final class GACCT_Plugin {

	const VERSION          = '1.1.3';
	const MENU_SLUG        = 'gacct-dashboard';
	const GENERATOR_SLUG   = 'gacct-generator';
	const SETTINGS_SLUG    = 'gacct-settings';
	const NOTIFICATIONS_SLUG  = 'gacct-notifications';
	const CONFIG_SLUG      = 'gacct-config';
	const AJAX_ACTION      = 'gacct_calendar_events';
	const AJAX_NONCE       = 'gacct_calendar_nonce';
	const GENERATOR_NONCE  = 'gacct_generate_openings';
	const SETTINGS_NONCE   = 'gacct_save_settings';
	const NOTIFICATIONS_NONCE = 'gacct_save_notifications';
	const OPENING_TIME_OPT = 'gacct_opening_time';
	const TABLE_CALENDAR_OPT   = 'gacct_table_calendrier_dispo';
	const TABLE_OCCUPATION_OPT = 'gacct_table_occupation_atelier';
	const TABLE_REVISION_OPT   = 'gacct_table_revision';
	const WORKING_DAYS_OPT     = 'gacct_working_days';
	const NOTIFICATION_SETTINGS_OPT = 'gacct_notification_settings';
	// Seuil (en jours) de l'alerte "prochaine revision" du tableau de bord client
	// (cf. includes/gacct-dashboard.php, CDC-dashboard-client.md §2.5).
	const NEXT_REVISION_DAYS_OPT    = 'gacct_next_revision_days';
	const REL_REV_OCC      = 11;
	const REL_REV_ORDER    = 12;
	const REL_CLIENT_REV   = 13;
	const META_VALIDATION_TOKEN_HASH       = '_gacct_validation_token_hash';
	const META_VALIDATION_TOKEN_CREATED_AT = '_gacct_validation_token_created_at';
	const META_VALIDATION_TOKEN_USED_AT    = '_gacct_validation_token_used_at';
	const META_VALIDATION_REVISION_ID      = '_gacct_validation_revision_id';
	const KOJITO_META_SOLDE_RESTANT        = '_kojito_solde_restant';

	/**
	 * Champs possibles dans le CCT revision pour identifier le materiel.
	 * Ajustable via le filtre `gacct_revision_model_fields`.
	 */
	const DEFAULT_REVISION_MODEL_FIELDS = array(
		'modele',
		'model',
		'modele_materiel',
		'materiel_modele',
		'nom_materiel',
		'type_materiel',
		'equipement',
	);

	/**
	 * Champs possibles dans le CCT revision si les infos client y sont dupliquees.
	 * Ajustable via le filtre `gacct_revision_customer_fields`.
	 */
	const DEFAULT_REVISION_CUSTOMER_FIELDS = array(
		'nom_client',
		'client',
		'nom_prenom',
		'prenom_nom',
		'customer_name',
	);

	/**
	 * Champs checkbox du CCT revision affiches dans l'infobulle calendrier.
	 * Ajustable via le filtre `gacct_revision_service_fields`.
	 */
	const DEFAULT_REVISION_SERVICE_FIELDS = array(
		'revisions_controle',
		'pliages_secours',
		'suspentes__travaux',
	);

	private static $instance = null;

	/**
	 * @var array<string,string>
	 */
	private $admin_pages = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_admin_pages' ), 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_calendar_events' ) );
		add_action( 'jet-engine/custom-content-types/updated-item/revision', array( $this, 'handle_revision_updated' ), 10, 3 );
		add_action( 'template_redirect', array( $this, 'maybe_handle_quote_validation' ) );
	}

	public function register_admin_menu() {
		$capability = $this->capability();

		// La console opérateur est l'écran d'accueil du menu (CDC §3) :
		// parent accessible avec gacct_operate, sous-pages historiques réservées aux admins.
		add_menu_page(
			__( 'Gestion Atelier', 'gestion-atelier-cct' ),
			__( 'Gestion Atelier', 'gestion-atelier-cct' ),
			GACCT_OP_CAP,
			GACCT_OP_MENU_SLUG,
			'gacct_op_render_console',
			'dashicons-calendar-alt',
			26
		);

		add_submenu_page(
			GACCT_OP_MENU_SLUG,
			__( 'Aujourd\'hui', 'gestion-atelier-cct' ),
			__( 'Aujourd\'hui', 'gestion-atelier-cct' ),
			GACCT_OP_CAP,
			GACCT_OP_MENU_SLUG,
			'gacct_op_render_console'
		);

		add_submenu_page(
			GACCT_OP_MENU_SLUG,
			__( 'Interventions', 'gestion-atelier-cct' ),
			__( 'Interventions', 'gestion-atelier-cct' ),
			GACCT_OP_CAP,
			'admin.php?page=' . GACCT_OP_MENU_SLUG . '&view=list'
		);

		add_submenu_page(
			GACCT_OP_MENU_SLUG,
			__( 'Réception colis', 'gestion-atelier-cct' ),
			__( 'Réception colis', 'gestion-atelier-cct' ),
			GACCT_OP_CAP,
			'admin.php?page=' . GACCT_OP_MENU_SLUG . '&view=reception'
		);

		add_submenu_page(
			GACCT_OP_MENU_SLUG,
			__( 'Planning', 'gestion-atelier-cct' ),
			__( 'Planning', 'gestion-atelier-cct' ),
			GACCT_OP_CAP,
			'admin.php?page=' . GACCT_OP_MENU_SLUG . '&view=planning'
		);

		// Tout le paramétrage vit dans UN seul écran à onglets (décision 28/07/2026) :
		// Atelier, Ouvertures, Notifications, Paiements & relances. L'ancien
		// « Tableau de bord » (calendrier admin) n'a plus d'entrée : la vue
		// Planning de la console le remplace. Anciennes URL redirigées
		// (redirect_legacy_admin_pages).
		$this->admin_pages['config'] = add_submenu_page(
			GACCT_OP_MENU_SLUG,
			__( 'Configuration', 'gestion-atelier-cct' ),
			__( 'Configuration', 'gestion-atelier-cct' ),
			$capability,
			self::CONFIG_SLUG,
			array( $this, 'render_config_page' )
		);
	}

	/**
	 * Onglets de l'écran Configuration : clé → [ libellé, callback ].
	 *
	 * @return array<string,array{0:string,1:callable}>
	 */
	public function config_tabs() {
		$tabs = array(
			'atelier'       => array( __( 'Atelier', 'gestion-atelier-cct' ), array( $this, 'render_settings_page' ) ),
			'ouvertures'    => array( __( 'Ouvertures', 'gestion-atelier-cct' ), array( $this, 'render_generator_page' ) ),
			'notifications' => array( __( 'Notifications', 'gestion-atelier-cct' ), array( $this, 'render_notifications_page' ) ),
		);

		if ( function_exists( 'gacct_pay_render_admin_page' ) ) {
			$tabs['paiements'] = array( __( 'Paiements & relances', 'gestion-atelier-cct' ), 'gacct_pay_render_admin_page' );
		}

		return apply_filters( 'gacct_config_tabs', $tabs );
	}

	/**
	 * URL d'un onglet de l'écran Configuration.
	 */
	public static function config_tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => self::CONFIG_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	public function render_config_page() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Acces refuse.', 'gestion-atelier-cct' ) );
		}

		$tabs    = $this->config_tabs();
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( ! isset( $tabs[ $current ] ) ) {
			$current = array_key_first( $tabs );
		}

		echo '<div class="wrap gacct-config-tabs-wrap">';
		echo '<h1>' . esc_html__( 'Gestion Atelier — Configuration', 'gestion-atelier-cct' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $key => $tab ) {
			$class = 'nav-tab' . ( $key === $current ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::config_tab_url( $key ) ) . '">' . esc_html( $tab[0] ) . '</a>';
		}

		echo '</nav>';
		echo '</div>';

		call_user_func( $tabs[ $current ][1] );
	}

	/**
	 * Anciennes URL d'admin (pages fusionnées dans Configuration) → nouvel onglet.
	 */
	public function redirect_legacy_admin_pages() {
		if ( wp_doing_ajax() || empty( $_GET['page'] ) ) {
			return;
		}

		$map = array(
			self::MENU_SLUG           => add_query_arg( array( 'page' => GACCT_OP_MENU_SLUG, 'view' => 'planning' ), admin_url( 'admin.php' ) ),
			self::GENERATOR_SLUG      => self::config_tab_url( 'ouvertures' ),
			self::SETTINGS_SLUG       => self::config_tab_url( 'atelier' ),
			self::NOTIFICATIONS_SLUG  => self::config_tab_url( 'notifications' ),
			'gacct-payments'          => self::config_tab_url( 'paiements' ),
		);

		$page = sanitize_key( wp_unslash( $_GET['page'] ) );

		if ( isset( $map[ $page ] ) ) {
			wp_safe_redirect( $map[ $page ] );
			exit;
		}
	}

	public function enqueue_admin_assets( $hook_suffix ) {
		if ( empty( $this->admin_pages ) || ! in_array( $hook_suffix, $this->admin_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'gacct-admin',
			plugins_url( 'assets/admin.css', __FILE__ ),
			array(),
			gacct_asset_version( 'assets/admin.css' )
		);

		// L'ancien « Tableau de bord » (calendrier admin) n'a plus de page dédiée :
		// la vue Planning de la console le remplace. render_dashboard_page(),
		// assets/admin-calendar.js et l'endpoint AJAX restent en place au cas où.
	}

	public function render_dashboard_page() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Acces refuse.', 'gestion-atelier-cct' ) );
		}

		$missing_tables = $this->missing_tables();
		?>
		<div class="wrap gacct-wrap">
			<h1><?php esc_html_e( 'Tableau de bord atelier', 'gestion-atelier-cct' ); ?></h1>

			<?php if ( ! empty( $missing_tables ) ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: comma separated table names */
								__( 'Tables introuvables : %s. Verifiez les slugs CCT ou ajustez les filtres du plugin.', 'gestion-atelier-cct' ),
								implode( ', ', $missing_tables )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="gacct-toolbar">
				<div>
					<strong><?php esc_html_e( 'Disponibilites', 'gestion-atelier-cct' ); ?></strong>
					<span><?php esc_html_e( 'capacite CCT moins occupations reservees', 'gestion-atelier-cct' ); ?></span>
				</div>
				<a class="button button-primary" href="<?php echo esc_url( self::config_tab_url( 'ouvertures' ) ); ?>">
					<?php esc_html_e( 'Generer des ouvertures', 'gestion-atelier-cct' ); ?>
				</a>
			</div>

			<div id="gacct-calendar" class="gacct-calendar"></div>
		</div>
		<?php
	}

	public function render_generator_page() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Acces refuse.', 'gestion-atelier-cct' ) );
		}

		$result = null;

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['gacct_generator_submit'] ) ) {
			$result = $this->handle_generator_submission();
		}

		?>
		<div class="wrap gacct-wrap">
			<h2 class="title"><?php esc_html_e( 'Generer des ouvertures', 'gestion-atelier-cct' ); ?></h2>

			<?php $this->render_generator_notice( $result ); ?>

			<form class="gacct-form" method="post" action="<?php echo esc_url( self::config_tab_url( 'ouvertures' ) ); ?>">
				<?php wp_nonce_field( self::GENERATOR_NONCE, '_gacct_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="gacct_start_date"><?php esc_html_e( 'Date de debut', 'gestion-atelier-cct' ); ?></label>
							</th>
							<td>
								<input type="date" id="gacct_start_date" name="start_date" required>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="gacct_end_date"><?php esc_html_e( 'Date de fin', 'gestion-atelier-cct' ); ?></label>
							</th>
							<td>
								<input type="date" id="gacct_end_date" name="end_date" required>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="gacct_hours"><?php esc_html_e( 'Nombre d heures a ouvrir par jour', 'gestion-atelier-cct' ); ?></label>
							</th>
							<td>
								<input type="number" id="gacct_hours" name="hours_per_day" min="0.25" max="24" step="0.25" required>
								<p class="description"><?php esc_html_e( 'Exemple : 5 ouvre 5 heures de capacite, 7.5 ouvre 7 h 30.', 'gestion-atelier-cct' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Creer les disponibilites', 'gestion-atelier-cct' ), 'primary', 'gacct_generator_submit' ); ?>
			</form>
		</div>
		<?php
	}

	public function render_settings_page() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Acces refuse.', 'gestion-atelier-cct' ) );
		}

		$result = null;

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['gacct_settings_submit'] ) ) {
			$result = $this->handle_settings_submission();
		}

		?>
		<div class="wrap gacct-wrap">
			<h2 class="title"><?php esc_html_e( 'Configuration atelier', 'gestion-atelier-cct' ); ?></h2>

			<?php $this->render_settings_notice( $result ); ?>

			<form class="gacct-form" method="post" action="<?php echo esc_url( self::config_tab_url( 'atelier' ) ); ?>">
				<?php wp_nonce_field( self::SETTINGS_NONCE, '_gacct_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="gacct_opening_time"><?php esc_html_e( 'Heure d ouverture', 'gestion-atelier-cct' ); ?></label>
							</th>
							<td>
								<input type="time" id="gacct_opening_time" name="opening_time" value="<?php echo esc_attr( $this->opening_time() ); ?>" required>
								<p class="description"><?php esc_html_e( 'Les reservations du calendrier semaine commencent a cette heure, puis s empilent dans leur ordre de creation.', 'gestion-atelier-cct' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Jours travailles', 'gestion-atelier-cct' ); ?>
							</th>
							<td>
								<div class="gacct-checkbox-grid">
									<?php foreach ( $this->week_days() as $day_number => $day_label ) : ?>
										<label>
											<input
												type="checkbox"
												name="working_days[]"
												value="<?php echo esc_attr( $day_number ); ?>"
												<?php checked( in_array( (int) $day_number, $this->working_days(), true ) ); ?>
											>
											<?php echo esc_html( $day_label ); ?>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="description"><?php esc_html_e( 'Le generateur d ouvertures ne creera des disponibilites que sur les jours coches.', 'gestion-atelier-cct' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="gacct_next_revision_days"><?php esc_html_e( 'Alerte prochaine revision (jours)', 'gestion-atelier-cct' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									id="gacct_next_revision_days"
									name="next_revision_days"
									min="1"
									max="730"
									step="1"
									value="<?php echo esc_attr( function_exists( 'gacct_dash_next_revision_threshold' ) ? gacct_dash_next_revision_threshold() : 60 ); ?>"
									required
								>
								<p class="description"><?php esc_html_e( 'Tableau de bord client : la voile est signalee "a reviser" quand la date de prochaine revision saisie par l operateur tombe dans ce delai (ou est deja depassee). Defaut : 60 jours.', 'gestion-atelier-cct' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="gacct_table_revision"><?php esc_html_e( 'Table des revisions', 'gestion-atelier-cct' ); ?></label>
							</th>
							<td>
								<input type="text" id="gacct_table_revision" name="table_revision" value="<?php echo esc_attr( $this->configured_table_value( 'revision' ) ); ?>" placeholder="revision">
								<p class="description"><?php echo esc_html( $this->table_field_description( 'revision' ) ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="gacct_table_occupation"><?php esc_html_e( 'Table occupation atelier', 'gestion-atelier-cct' ); ?></label>
							</th>
							<td>
								<input type="text" id="gacct_table_occupation" name="table_occupation_atelier" value="<?php echo esc_attr( $this->configured_table_value( 'occupation_atelier' ) ); ?>" placeholder="occupation_atelier">
								<p class="description"><?php echo esc_html( $this->table_field_description( 'occupation_atelier' ) ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="gacct_table_calendar"><?php esc_html_e( 'Table calendrier disponibilite', 'gestion-atelier-cct' ); ?></label>
							</th>
							<td>
								<input type="text" id="gacct_table_calendar" name="table_calendrier_dispo" value="<?php echo esc_attr( $this->configured_table_value( 'calendrier_dispo' ) ); ?>" placeholder="calendrier_dispo">
								<p class="description"><?php echo esc_html( $this->table_field_description( 'calendrier_dispo' ) ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Enregistrer la configuration', 'gestion-atelier-cct' ), 'primary', 'gacct_settings_submit' ); ?>
			</form>
		</div>
		<?php
	}

	public function render_notifications_page() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Acces refuse.', 'gestion-atelier-cct' ) );
		}

		$result = null;

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['gacct_notifications_submit'] ) ) {
			$result = $this->handle_notifications_submission();
		}

		$settings    = $this->notification_settings();
		$definitions = $this->notification_definitions();
		?>
		<div class="wrap gacct-wrap">
			<h2 class="title"><?php esc_html_e( 'Notifications atelier', 'gestion-atelier-cct' ); ?></h2>

			<?php $this->render_notifications_notice( $result ); ?>

			<form class="gacct-form" method="post" action="<?php echo esc_url( self::config_tab_url( 'notifications' ) ); ?>">
				<?php wp_nonce_field( self::NOTIFICATIONS_NONCE, '_gacct_notifications_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="gacct_admin_email"><?php esc_html_e( 'Email administrateur', 'gestion-atelier-cct' ); ?></label>
							</th>
							<td>
								<input type="email" id="gacct_admin_email" name="admin_email" class="regular-text" value="<?php echo esc_attr( $settings['admin_email'] ); ?>">
								<p class="description"><?php esc_html_e( 'Adresse utilisee pour les alertes et copies admin.', 'gestion-atelier-cct' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php foreach ( $definitions as $state => $definition ) : ?>
					<?php
					$email_settings = isset( $settings['emails'][ $state ] ) ? $settings['emails'][ $state ] : $definition;
					$editor_id      = 'gacct_notification_body_' . absint( $state );
					?>
					<h2><?php echo esc_html( sprintf( 'Etat %d - %s', $state, $definition['label'] ) ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Actif', 'gestion-atelier-cct' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="emails[<?php echo esc_attr( $state ); ?>][enabled]" value="1" <?php checked( ! empty( $email_settings['enabled'] ) ); ?>>
										<?php esc_html_e( 'Envoyer cette notification', 'gestion-atelier-cct' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="gacct_notification_subject_<?php echo esc_attr( $state ); ?>"><?php esc_html_e( 'Objet', 'gestion-atelier-cct' ); ?></label>
								</th>
								<td>
									<input type="text" id="gacct_notification_subject_<?php echo esc_attr( $state ); ?>" name="emails[<?php echo esc_attr( $state ); ?>][subject]" class="large-text" value="<?php echo esc_attr( $email_settings['subject'] ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Contenu', 'gestion-atelier-cct' ); ?></th>
								<td>
									<?php
									wp_editor(
										$email_settings['body'],
										$editor_id,
										array(
											'textarea_name' => 'emails[' . absint( $state ) . '][body]',
											'textarea_rows' => 8,
											'media_buttons' => false,
											'teeny'         => true,
										)
									);
									?>
									<p class="description">
										<?php esc_html_e( 'Variables disponibles :', 'gestion-atelier-cct' ); ?>
										<code>{customer_name}</code>
										<code>{prestations}</code>
										<code>{date_atelier}</code>
										<code>{order_id}</code>
										<code>{balance_amount}</code>
										<code>{payment_url}</code>
										<code>{validation_url}</code>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
				<?php endforeach; ?>

				<?php submit_button( __( 'Enregistrer les notifications', 'gestion-atelier-cct' ), 'primary', 'gacct_notifications_submit' ); ?>
			</form>
		</div>
		<?php
	}

	private function handle_generator_submission() {
		if ( ! isset( $_POST['_gacct_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gacct_nonce'] ) ), self::GENERATOR_NONCE ) ) {
			return new WP_Error( 'gacct_bad_nonce', __( 'Verification de securite echouee.', 'gestion-atelier-cct' ) );
		}

		$start_date    = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date      = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$hours_raw     = isset( $_POST['hours_per_day'] ) ? sanitize_text_field( wp_unslash( $_POST['hours_per_day'] ) ) : '';
		$hours_per_day = $this->normalize_decimal_hours( $hours_raw );

		if ( ! $this->is_ymd_date( $start_date ) || ! $this->is_ymd_date( $end_date ) ) {
			return new WP_Error( 'gacct_bad_date', __( 'Les dates doivent etre valides.', 'gestion-atelier-cct' ) );
		}

		if ( $hours_per_day <= 0 || $hours_per_day > 24 ) {
			return new WP_Error( 'gacct_bad_hours', __( 'Le nombre d heures doit etre superieur a 0 et inferieur ou egal a 24.', 'gestion-atelier-cct' ) );
		}

		$timezone = wp_timezone();

		try {
			$start = new DateTimeImmutable( $start_date . ' 00:00:00', $timezone );
			$end   = new DateTimeImmutable( $end_date . ' 00:00:00', $timezone );
		} catch ( Exception $exception ) {
			return new WP_Error( 'gacct_bad_datetime', __( 'Impossible d interpreter les dates avec la timezone du site.', 'gestion-atelier-cct' ) );
		}

		if ( $end < $start ) {
			return new WP_Error( 'gacct_date_order', __( 'La date de fin doit etre posterieure ou egale a la date de debut.', 'gestion-atelier-cct' ) );
		}

		global $wpdb;

		$table = $this->table_name( 'calendrier_dispo' );

		if ( ! $this->table_exists( $table ) ) {
			return new WP_Error(
				'gacct_missing_table',
				sprintf(
					/* translators: %s: table name */
					__( 'La table %s est introuvable.', 'gestion-atelier-cct' ),
					$table
				)
			);
		}

		if ( ! $this->table_can_store_decimal_hours( $table, 'heures_totales_dispo' ) && ! $this->is_whole_number( $hours_per_day ) ) {
			return new WP_Error(
				'gacct_decimal_column_required',
				__( 'Le champ heures_totales_dispo du CCT calendrier_dispo doit etre en DECIMAL, FLOAT, DOUBLE ou TEXT pour accepter des heures decimales. Actuellement, la base arrondirait la valeur.', 'gestion-atelier-cct' )
			);
		}

		$created  = current_time( 'mysql' );
		$inserted = 0;
		$failed   = 0;
		$skipped  = 0;
		$working_days = $this->working_days();

		for ( $day = $start; $day <= $end; $day = $day->modify( '+1 day' ) ) {
			if ( ! in_array( (int) $day->format( 'N' ), $working_days, true ) ) {
				$skipped++;
				continue;
			}

			$data = array(
				'cct_status'               => 'publish',
				'cct_author_id'            => get_current_user_id(),
				'cct_created'              => $created,
				'cct_modified'             => $created,
				'date_jour'                => $day->getTimestamp(),
				'heures_totales_dispo'     => $hours_per_day,
			);

			$data = apply_filters( 'gacct_generator_availability_insert_data', $data, $day, $hours_per_day );
			$data = $this->filter_data_by_table_columns( $table, $data );

			if ( empty( $data['date_jour'] ) || ! isset( $data['heures_totales_dispo'] ) ) {
				$failed++;
				continue;
			}

			$formats = $this->insert_formats_for_data( $data );

			$result = $wpdb->insert( $table, $data, $formats );

			if ( false === $result ) {
				$failed++;
				continue;
			}

			$inserted++;
		}

		return array(
			'inserted' => $inserted,
			'failed'   => $failed,
			'skipped'  => $skipped,
			'hours'    => $hours_per_day,
		);
	}

	private function render_generator_notice( $result ) {
		if ( null === $result ) {
			return;
		}

		if ( is_wp_error( $result ) ) {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html( $result->get_error_message() ); ?></p>
			</div>
			<?php
			return;
		}

		$type = empty( $result['failed'] ) ? 'success' : 'warning';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?>">
			<p>
				<?php
					echo esc_html(
					sprintf(
						/* translators: 1: inserted count, 2: failed count, 3: skipped count, 4: hours */
						__( '%1$d disponibilite(s) creee(s), %2$d echec(s), %3$d jour(s) non ouvre(s) ignore(s). Heures ouvertes par jour : %4$s.', 'gestion-atelier-cct' ),
						absint( $result['inserted'] ),
						absint( $result['failed'] ),
						absint( $result['skipped'] ),
						$this->format_hours( $result['hours'] )
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	private function handle_settings_submission() {
		if ( ! isset( $_POST['_gacct_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gacct_settings_nonce'] ) ), self::SETTINGS_NONCE ) ) {
			return new WP_Error( 'gacct_bad_nonce', __( 'Verification de securite echouee.', 'gestion-atelier-cct' ) );
		}

		$opening_time = isset( $_POST['opening_time'] ) ? sanitize_text_field( wp_unslash( $_POST['opening_time'] ) ) : '';

		if ( ! $this->is_hm_time( $opening_time ) ) {
			return new WP_Error( 'gacct_bad_opening_time', __( 'L heure d ouverture doit etre au format HH:MM.', 'gestion-atelier-cct' ) );
		}

		$working_days = isset( $_POST['working_days'] ) && is_array( $_POST['working_days'] )
			? array_map( 'absint', wp_unslash( $_POST['working_days'] ) )
			: array();
		$working_days = $this->sanitize_working_days( $working_days );

		if ( empty( $working_days ) ) {
			return new WP_Error( 'gacct_no_working_days', __( 'Selectionnez au moins un jour travaille.', 'gestion-atelier-cct' ) );
		}

		// Seuil de l'alerte "prochaine revision" du tableau de bord client
		// (includes/gacct-dashboard.php) : valide AVANT tout enregistrement.
		$next_revision_days = isset( $_POST['next_revision_days'] ) ? absint( wp_unslash( $_POST['next_revision_days'] ) ) : 0;

		if ( $next_revision_days < 1 || $next_revision_days > 730 ) {
			return new WP_Error( 'gacct_bad_next_revision_days', __( 'Le delai d alerte de prochaine revision doit etre compris entre 1 et 730 jours.', 'gestion-atelier-cct' ) );
		}

		update_option( self::OPENING_TIME_OPT, $opening_time, false );
		update_option( self::WORKING_DAYS_OPT, $working_days, false );
		update_option( self::NEXT_REVISION_DAYS_OPT, $next_revision_days, false );

		$table_inputs = array(
			'revision'           => 'table_revision',
			'occupation_atelier' => 'table_occupation_atelier',
			'calendrier_dispo'   => 'table_calendrier_dispo',
		);

		foreach ( $table_inputs as $slug => $input_name ) {
			$raw_value = isset( $_POST[ $input_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $input_name ] ) ) : '';
			$value     = $this->sanitize_table_config_value( $raw_value );
			$option    = $this->table_option_name( $slug );

			if ( '' === $value ) {
				delete_option( $option );
				continue;
			}

			update_option( $option, $value, false );
		}

		return array(
			'opening_time' => $opening_time,
		);
	}

	private function render_settings_notice( $result ) {
		if ( null === $result ) {
			return;
		}

		if ( is_wp_error( $result ) ) {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html( $result->get_error_message() ); ?></p>
			</div>
			<?php
			return;
		}

		?>
		<div class="notice notice-success">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: opening time */
						__( 'Configuration enregistree. Heure d ouverture : %s.', 'gestion-atelier-cct' ),
						$result['opening_time']
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	private function handle_notifications_submission() {
		if ( ! isset( $_POST['_gacct_notifications_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gacct_notifications_nonce'] ) ), self::NOTIFICATIONS_NONCE ) ) {
			return new WP_Error( 'gacct_bad_nonce', __( 'Verification de securite echouee.', 'gestion-atelier-cct' ) );
		}

		$admin_email = isset( $_POST['admin_email'] ) ? sanitize_email( wp_unslash( $_POST['admin_email'] ) ) : '';

		if ( '' === $admin_email || ! is_email( $admin_email ) ) {
			return new WP_Error( 'gacct_bad_admin_email', __( 'Adresse email administrateur invalide.', 'gestion-atelier-cct' ) );
		}

		$posted_emails = isset( $_POST['emails'] ) && is_array( $_POST['emails'] ) ? wp_unslash( $_POST['emails'] ) : array();
		$definitions   = $this->notification_definitions();
		$emails        = array();

		foreach ( $definitions as $state => $definition ) {
			$posted = isset( $posted_emails[ $state ] ) && is_array( $posted_emails[ $state ] ) ? $posted_emails[ $state ] : array();

			$emails[ $state ] = array(
				'enabled'    => ! empty( $posted['enabled'] ),
				'label'      => $definition['label'],
				'recipients' => $definition['recipients'],
				'subject'    => isset( $posted['subject'] ) && '' !== trim( (string) $posted['subject'] )
					? sanitize_text_field( $posted['subject'] )
					: $definition['subject'],
				'body'       => isset( $posted['body'] ) && '' !== trim( (string) $posted['body'] )
					? wp_kses_post( $posted['body'] )
					: $definition['body'],
			);
		}

		update_option(
			self::NOTIFICATION_SETTINGS_OPT,
			array(
				'admin_email' => $admin_email,
				'emails'      => $emails,
			),
			false
		);

		return true;
	}

	private function render_notifications_notice( $result ) {
		if ( null === $result ) {
			return;
		}

		if ( is_wp_error( $result ) ) {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html( $result->get_error_message() ); ?></p>
			</div>
			<?php
			return;
		}

		?>
		<div class="notice notice-success">
			<p><?php esc_html_e( 'Notifications enregistrees.', 'gestion-atelier-cct' ); ?></p>
		</div>
		<?php
	}

	public function ajax_calendar_events() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Acces refuse.', 'gestion-atelier-cct' ) ), 403 );
		}

		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		$start_raw = isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : '';
		$end_raw   = isset( $_GET['end'] ) ? sanitize_text_field( wp_unslash( $_GET['end'] ) ) : '';

		try {
			$range = $this->parse_calendar_range( $start_raw, $end_raw );
		} catch ( Exception $exception ) {
			wp_send_json_error( array( 'message' => __( 'Periode calendrier invalide.', 'gestion-atelier-cct' ) ), 400 );
		}

		$events = array_merge(
			$this->get_availability_events( $range['start_ts'], $range['end_ts'] ),
			$this->get_occupation_events( $range['start_ts'], $range['end_ts'] )
		);

		wp_send_json( $events );
	}

	public function handle_revision_updated( $item, $prev_item, $handler ) {
		$old_state = isset( $prev_item['etat_de_la_commande'] ) ? (string) $prev_item['etat_de_la_commande'] : '';
		$new_state = isset( $item['etat_de_la_commande'] ) ? (string) $item['etat_de_la_commande'] : '';

		if ( '' === $new_state || $new_state === $old_state ) {
			return;
		}

		$revision_id = isset( $item['_ID'] ) ? absint( $item['_ID'] ) : 0;

		if ( ! $revision_id && isset( $prev_item['_ID'] ) ) {
			$revision_id = absint( $prev_item['_ID'] );
		}

		$this->process_revision_state_transition( $revision_id, absint( $new_state ), (array) $item, (array) $prev_item, 'cct_update' );
	}

	public function maybe_handle_quote_validation() {
		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';

		// Chemin courant + ancien chemin avec la faute de frappe (« devie- ») :
		// des liens déjà envoyés par email doivent rester valides.
		$target_paths = array(
			'/' . trim( apply_filters( 'gacct_validation_path', 'devis-a-valider' ), '/' ),
			'/devie-a-valider',
		);

		if ( ! in_array( untrailingslashit( $request_path ), array_map( 'untrailingslashit', $target_paths ), true ) ) {
			return;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			wp_die( esc_html__( 'WooCommerce est indisponible.', 'gestion-atelier-cct' ) );
		}

		// Écrans de confirmation post-décision (sans token : le lien est déjà consommé).
		if ( isset( $_GET['gacct_quote'] ) ) {
			$variant = sanitize_key( wp_unslash( $_GET['gacct_quote'] ) );

			if ( in_array( $variant, array( 'accepted', 'refused_partial', 'refused_return' ), true ) ) {
				$order = ! empty( $_GET['order_id'] ) ? wc_get_order( absint( wp_unslash( $_GET['order_id'] ) ) ) : false;
				gacct_quote_render_page( $variant, $order ? $order : null );
			}
		}

		if ( empty( $_GET['order_id'] ) || empty( $_GET['token'] ) ) {
			return;
		}

		$order_id = absint( wp_unslash( $_GET['order_id'] ) );
		$token    = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Commande introuvable.', 'gestion-atelier-cct' ) );
		}

		if ( '' !== (string) $order->get_meta( self::META_VALIDATION_TOKEN_USED_AT ) ) {
			// Lien déjà consommé : page douce (le client re-clique souvent le lien de l'email).
			gacct_quote_render_page( 'used', $order );
		}

		$stored_hash = (string) $order->get_meta( self::META_VALIDATION_TOKEN_HASH );
		$token_hash  = $this->hash_validation_token( $token );

		if ( '' === $stored_hash || ! hash_equals( $stored_hash, $token_hash ) ) {
			$order->add_order_note( __( 'ERREUR : tentative de validation devis avec un token invalide.', 'gestion-atelier-cct' ) );
			$order->save();
			wp_die( esc_html__( 'Lien de validation invalide.', 'gestion-atelier-cct' ) );
		}

		$action = isset( $_POST['gacct_quote_action'] ) ? sanitize_key( wp_unslash( $_POST['gacct_quote_action'] ) ) : '';

		// GET (ou action inconnue) : afficher le devis, le token n'est PAS consommé.
		if ( ! in_array( $action, array( 'accept', 'refuse' ), true ) ) {
			gacct_quote_render_page( 'quote', $order );
		}

		$revision_id = absint( $order->get_meta( self::META_VALIDATION_REVISION_ID ) );

		if ( ! $revision_id ) {
			$revision_id = $this->related_object_id( $order_id, $this->relation_id( 'revision_to_order', self::REL_REV_ORDER ) );
		}

		if ( ! $revision_id ) {
			$order->add_order_note( __( 'ERREUR : decision devis impossible, revision introuvable pour cette commande.', 'gestion-atelier-cct' ) );
			$order->save();
			wp_die( esc_html__( 'Revision introuvable pour cette commande.', 'gestion-atelier-cct' ) );
		}

		$prev_revision = $this->get_revision_row( $revision_id );

		if ( empty( $prev_revision ) ) {
			$order->add_order_note( __( 'ERREUR : decision devis impossible, ligne CCT revision introuvable.', 'gestion-atelier-cct' ) );
			$order->save();
			wp_die( esc_html__( 'Revision introuvable.', 'gestion-atelier-cct' ) );
		}

		// ------------------------------------------------------------- REFUS.
		if ( 'refuse' === $action ) {
			$order->update_meta_data( self::META_VALIDATION_TOKEN_USED_AT, current_time( 'mysql' ) );
			$order->delete_meta_data( self::META_VALIDATION_TOKEN_HASH );
			$order->save();

			$result = gacct_quote_refuse( $order, $revision_id );

			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ) );
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'order_id'    => $order_id,
						'gacct_quote' => 'return' === $result['mode'] ? 'refused_return' : 'refused_partial',
					),
					home_url( trailingslashit( $target_paths[0] ) )
				)
			);
			exit;
		}

		// ------------------------------------------------------- ACCEPTATION.
		// L'acceptation comme le refus menent a l'etat 5 « Intervention a finir » :
		// c'est la meta _gacct_quote_decision qui precise le libelle.
		$updated = $this->update_revision_state( $revision_id, GACCT_STATE_QUOTE_DECIDED );

		if ( ! $updated ) {
			$order->add_order_note( __( 'ERREUR : validation devis impossible, echec de la mise a jour de la revision en etat 5.', 'gestion-atelier-cct' ) );
			$order->save();
			wp_die( esc_html__( 'Impossible de valider le devis.', 'gestion-atelier-cct' ) );
		}

		$order->update_meta_data( self::META_VALIDATION_TOKEN_USED_AT, current_time( 'mysql' ) );
		$order->delete_meta_data( self::META_VALIDATION_TOKEN_HASH );
		$order->add_order_note( __( 'Devis valide par le client via lien securise. Revision passee en etat 5 (intervention a finir).', 'gestion-atelier-cct' ) );
		$order->save();

		gacct_quote_mark_decision( $order, 'accepted' );

		$new_revision = $this->get_revision_row( $revision_id );
		$this->process_revision_state_transition( $revision_id, GACCT_STATE_QUOTE_DECIDED, $new_revision, $prev_revision, 'validation_url' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'order_id'    => $order_id,
					'gacct_quote' => 'accepted',
				),
				// Toujours le chemin canonique (index 0), meme si le client est
				// arrive par l'ancien lien « devie- » : cette page-la n'existe pas.
				home_url( trailingslashit( $target_paths[0] ) )
			)
		);
		exit;
	}

	/**
	 * Retourne les evenements verts "Dispo" calcules depuis les CCT.
	 *
	 * @param int $start_ts Timestamp inclusif.
	 * @param int $end_ts   Timestamp exclusif.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_availability_events( $start_ts, $end_ts ) {
		global $wpdb;

		$calendar_table   = $this->table_name( 'calendrier_dispo' );
		$occupation_table = $this->table_name( 'occupation_atelier' );
		$revision_table   = $this->table_name( 'revision' );
		$relation_table   = $this->relation_table_name();

		if ( ! $this->table_exists( $calendar_table ) || ! $this->table_exists( $occupation_table ) || ! $this->table_exists( $revision_table ) || ! $this->table_exists( $relation_table ) ) {
			return array();
		}

		$sql = $wpdb->prepare(
			"
			SELECT
				c._ID AS capacity_id,
				c.date_jour AS day_ts,
				CAST(c.heures_totales_dispo AS DECIMAL(10,2)) AS capacity_hours,
				COALESCE(SUM(TIME_TO_SEC(o.duree_totale_commande) / 3600), 0) AS occupied_hours
			FROM {$calendar_table} c
			LEFT JOIN {$occupation_table} o
				ON CAST(o.date_reservee AS UNSIGNED) = CAST(c.date_jour AS UNSIGNED)
				AND o.cct_status = %s
				AND EXISTS (
					SELECT 1
					FROM {$relation_table} rel_av
					INNER JOIN {$revision_table} r_av
						ON r_av._ID = CASE WHEN rel_av.child_object_id = o._ID THEN rel_av.parent_object_id ELSE rel_av.child_object_id END
						AND r_av.cct_status = %s
					WHERE rel_av.rel_id = %s
						AND (
							rel_av.child_object_id = o._ID
							OR rel_av.parent_object_id = o._ID
						)
				)
			WHERE c.cct_status = %s
				AND CAST(c.date_jour AS UNSIGNED) >= %d
				AND CAST(c.date_jour AS UNSIGNED) < %d
			GROUP BY c._ID, c.date_jour, c.heures_totales_dispo
			ORDER BY CAST(c.date_jour AS UNSIGNED) ASC
			",
			'publish',
			'publish',
			(string) $this->relation_id( 'revision_to_occupation', self::REL_REV_OCC ),
			'publish',
			$start_ts,
			$end_ts
		);

		$rows   = $wpdb->get_results( $sql, ARRAY_A );
		$events = array();

		foreach ( $rows as $row ) {
			$available = max( 0, (float) $row['capacity_hours'] - (float) $row['occupied_hours'] );
			$style     = $this->availability_style( $available );
			$events[]  = array(
				'id'              => 'availability-' . absint( $row['capacity_id'] ),
				'title'           => sprintf( __( 'Dispo : %s h', 'gestion-atelier-cct' ), $this->format_hours( $available ) ),
				'start'           => wp_date( 'Y-m-d', (int) $row['day_ts'], wp_timezone() ),
				'allDay'          => true,
				'display'         => 'background',
				'backgroundColor' => $style['background'],
				'borderColor'     => $style['border'],
				'extendedProps'   => array(
					'type'      => 'availability',
					'available' => $available,
				),
			);

			$events[] = array(
				'id'            => 'availability-label-' . absint( $row['capacity_id'] ),
				'title'         => sprintf( __( 'Dispo : %s h', 'gestion-atelier-cct' ), $this->format_hours( $available ) ),
				'start'         => wp_date( 'Y-m-d', (int) $row['day_ts'], wp_timezone() ),
				'allDay'        => true,
				'display'       => 'block',
				'classNames'    => array( 'gacct-availability-label', 'gacct-availability-label--' . $style['state'] ),
				'editable'      => false,
				'extendedProps' => array(
					'type'      => 'availability_label',
					'available' => $available,
				),
			);
		}

		return $events;
	}

	/**
	 * Retourne les occupations rouges, reliees aux revisions et clients JetEngine.
	 *
	 * Relation 11 attendue : revision -> occupation.
	 * Relation 13 attendue : client WP -> revision.
	 * Le SQL accepte aussi l'orientation inverse pour eviter une rupture si la relation a ete creee dans l'autre sens.
	 *
	 * @param int $start_ts Timestamp inclusif.
	 * @param int $end_ts   Timestamp exclusif.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_occupation_events( $start_ts, $end_ts ) {
		global $wpdb;

		$occupation_table = $this->table_name( 'occupation_atelier' );
		$revision_table   = $this->table_name( 'revision' );
		$relation_table   = $this->relation_table_name();

		if ( ! $this->table_exists( $occupation_table ) || ! $this->table_exists( $revision_table ) || ! $this->table_exists( $relation_table ) ) {
			return array();
		}

		$revision_id_expr = "CASE WHEN rel_rev_occ.child_object_id = o._ID THEN rel_rev_occ.parent_object_id ELSE rel_rev_occ.child_object_id END";
		$client_id_expr   = "CASE WHEN rel_client_rev.child_object_id = {$revision_id_expr} THEN rel_client_rev.parent_object_id ELSE rel_client_rev.child_object_id END";

		$sql = $wpdb->prepare(
			"
			SELECT
				o._ID AS occupation_id,
				o.date_reservee,
				o.duree_totale_commande,
				o.cct_created AS occupation_created,
				{$revision_id_expr} AS revision_id,
				{$client_id_expr} AS client_id
			FROM {$occupation_table} o
			LEFT JOIN {$relation_table} rel_rev_occ
				ON rel_rev_occ.rel_id = %s
				AND (
					rel_rev_occ.child_object_id = o._ID
					OR rel_rev_occ.parent_object_id = o._ID
				)
			LEFT JOIN {$relation_table} rel_client_rev
				ON rel_client_rev.rel_id = %s
				AND (
					rel_client_rev.child_object_id = {$revision_id_expr}
					OR rel_client_rev.parent_object_id = {$revision_id_expr}
				)
			INNER JOIN {$revision_table} r
				ON r._ID = {$revision_id_expr}
				AND r.cct_status = %s
			WHERE o.cct_status = %s
				AND CAST(o.date_reservee AS UNSIGNED) >= %d
				AND CAST(o.date_reservee AS UNSIGNED) < %d
			ORDER BY CAST(o.date_reservee AS UNSIGNED) ASC, o.cct_created ASC, o._ID ASC, revision_id ASC
			",
			(string) $this->relation_id( 'revision_to_occupation', self::REL_REV_OCC ),
			(string) $this->relation_id( 'client_to_revision', self::REL_CLIENT_REV ),
			'publish',
			'publish',
			$start_ts,
			$end_ts
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $rows ) ) {
			return array();
		}

		$revision_rows = $this->get_revision_rows( wp_list_pluck( $rows, 'revision_id' ) );
		$events        = array();
		$timezone      = wp_timezone();
		$day_cursors   = array();
		$sort_index    = 0;

		foreach ( $rows as $row ) {
			$revision_id = absint( $row['revision_id'] );
			$client_id   = absint( $row['client_id'] );
			$revision    = isset( $revision_rows[ $revision_id ] ) ? $revision_rows[ $revision_id ] : array();

			$client_label = $this->client_label( $client_id, $revision );
			$model_label  = $this->revision_model_label( $revision );
			$services     = $this->revision_services( $revision );
			$title_parts  = array_filter( array( $client_label, $model_label ) );
			$duration_label = $this->format_duration_label( $row['duree_totale_commande'] );
			$title        = ! empty( $title_parts ) ? implode( ' - ', $title_parts ) : __( 'Occupation atelier', 'gestion-atelier-cct' );
			$title        = $duration_label ? $duration_label . ' - ' . $title : $title;

			$reserved_at      = ( new DateTimeImmutable( '@' . (int) $row['date_reservee'] ) )->setTimezone( $timezone );
			$day_key          = $reserved_at->format( 'Y-m-d' );
			$duration_seconds = $this->time_to_seconds( $row['duree_totale_commande'] );

			if ( ! isset( $day_cursors[ $day_key ] ) ) {
				$day_cursors[ $day_key ] = new DateTimeImmutable( $day_key . ' ' . $this->opening_time() . ':00', $timezone );
			}

			$start = $day_cursors[ $day_key ];
			$end   = $duration_seconds > 0 ? $start->modify( '+' . $duration_seconds . ' seconds' ) : $start->modify( '+1 minute' );

			$day_cursors[ $day_key ] = $end;
			$sort_index++;

			$events[] = array(
				'id'              => 'occupation-' . absint( $row['occupation_id'] ) . '-revision-' . $revision_id,
				'title'           => $title,
				'start'           => $start->format( 'Y-m-d\TH:i:s' ),
				'end'             => $end->format( 'Y-m-d\TH:i:s' ),
				'allDay'          => false,
				'displayEventTime'=> false,
				'backgroundColor' => '#d92d20',
				'borderColor'     => '#b42318',
				'textColor'       => '#ffffff',
				'url'             => $revision_id ? admin_url( 'admin.php?page=jet-cct-revision&cct_action=edit&item_id=' . $revision_id ) : '',
				'extendedProps'   => array(
					'type'          => 'occupation',
					'occupationId'  => absint( $row['occupation_id'] ),
					'revisionId'    => $revision_id,
					'clientId'      => $client_id,
					'duration'      => $row['duree_totale_commande'],
					'clientLabel'   => $client_label,
					'revisionModel' => $model_label,
					'services'      => $services,
					'sortIndex'     => $sort_index,
					'createdAt'     => $row['occupation_created'],
				),
			);
		}

		return $events;
	}

	/**
	 * @param array<int|string> $revision_ids
	 * @return array<int,array<string,mixed>>
	 */
	private function get_revision_rows( $revision_ids ) {
		global $wpdb;

		$revision_ids = array_values( array_unique( array_filter( array_map( 'absint', $revision_ids ) ) ) );

		if ( empty( $revision_ids ) ) {
			return array();
		}

		$revision_table = $this->table_name( 'revision' );

		if ( ! $this->table_exists( $revision_table ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $revision_ids ), '%d' ) );
		$sql          = $wpdb->prepare(
			"SELECT * FROM {$revision_table} WHERE _ID IN ({$placeholders})",
			$revision_ids
		);
		$rows         = $wpdb->get_results( $sql, ARRAY_A );
		$indexed      = array();

		foreach ( $rows as $row ) {
			$indexed[ absint( $row['_ID'] ) ] = $row;
		}

		return $indexed;
	}

	private function client_label( $client_id, array $revision ) {
		if ( $client_id ) {
			$user = get_userdata( $client_id );

			if ( $user ) {
				$name = trim( $user->first_name . ' ' . $user->last_name );

				if ( '' !== $name ) {
					return $name;
				}

				if ( ! empty( $user->display_name ) ) {
					return $user->display_name;
				}
			}
		}

		$fields = apply_filters( 'gacct_revision_customer_fields', self::DEFAULT_REVISION_CUSTOMER_FIELDS );

		foreach ( $fields as $field ) {
			if ( ! empty( $revision[ $field ] ) ) {
				return wp_strip_all_tags( (string) $revision[ $field ] );
			}
		}

		return '';
	}

	private function revision_model_label( array $revision ) {
		$fields = apply_filters( 'gacct_revision_model_fields', self::DEFAULT_REVISION_MODEL_FIELDS );

		foreach ( $fields as $field ) {
			if ( ! empty( $revision[ $field ] ) ) {
				return wp_strip_all_tags( (string) maybe_unserialize( $revision[ $field ] ) );
			}
		}

		return '';
	}

	private function revision_services( array $revision ) {
		$fields   = apply_filters( 'gacct_revision_service_fields', self::DEFAULT_REVISION_SERVICE_FIELDS );
		$services = array();

		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $revision ) ) {
				continue;
			}

			$services = array_merge( $services, $this->normalize_checkbox_values( $revision[ $field ] ) );
		}

		$services = array_map( array( $this, 'service_value_label' ), $services );
		$services = array_map( 'wp_strip_all_tags', $services );
		$services = array_map( 'trim', $services );
		$services = array_values( array_unique( array_filter( $services, function ( $service ) {
			return '' !== $service;
		} ) ) );

		return $services;
	}

	private function normalize_checkbox_values( $value ) {
		if ( null === $value || '' === $value ) {
			return array();
		}

		$value = maybe_unserialize( $value );

		if ( is_array( $value ) ) {
			$items = array();

			foreach ( $value as $item ) {
				$items = array_merge( $items, $this->normalize_checkbox_values( $item ) );
			}

			return $items;
		}

		if ( is_object( $value ) ) {
			return $this->normalize_checkbox_values( (array) $value );
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $this->normalize_checkbox_values( $decoded );
		}

		if ( false !== strpos( $value, ',' ) ) {
			return array_map( 'trim', explode( ',', $value ) );
		}

		return array( $value );
	}

	private function service_value_label( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || ! ctype_digit( $value ) ) {
			return $value;
		}

		$product_id = absint( $value );

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );

			if ( $product ) {
				return $product->get_name();
			}
		}

		$post_type = get_post_type( $product_id );

		if ( in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
			$title = get_the_title( $product_id );

			if ( '' !== $title ) {
				return $title;
			}
		}

		return $value;
	}

	private function process_revision_state_transition( $revision_id, $state, array $revision, array $prev_revision, $source ) {
		$definitions = $this->notification_definitions();

		if ( ! $revision_id || ! isset( $definitions[ $state ] ) ) {
			return;
		}

		if ( empty( $revision ) ) {
			$revision = $this->get_revision_row( $revision_id );
		}

		if ( empty( $revision ) ) {
			return;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		// Colonne order_id de la révision d'abord (source de vérité du reste du plugin),
		// relation JetEngine 12 en repli : si les deux divergent, les emails partaient dans le vide.
		$order_id = isset( $revision['order_id'] ) ? absint( $revision['order_id'] ) : 0;
		if ( ! $order_id ) {
			$order_id = $this->related_object_id( $revision_id, $this->relation_id( 'revision_to_order', self::REL_REV_ORDER ) );
		}
		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			return;
		}

		$old_state = isset( $prev_revision['etat_de_la_commande'] ) ? (string) $prev_revision['etat_de_la_commande'] : '';
		$label     = $definitions[ $state ]['label'];

		$order->add_order_note(
			sprintf(
				__( 'Etat revision modifie : %1$s -> %2$d (%3$s). Source : %4$s.', 'gestion-atelier-cct' ),
				'' !== $old_state ? $old_state : '-',
				$state,
				$label,
				$source
			)
		);

		$validation_url = '';

		if ( 4 === $state ) {
			$validation_url = $this->create_validation_url( $order, $revision_id );
			$order->add_order_note( __( 'Lien securise de validation devis genere pour le client.', 'gestion-atelier-cct' ) );
			$order->save();
		}

		if ( 6 === $state ) {
			$order->add_order_note( __( 'Etat 6 detecte : intervention finie, preparation du paiement du solde lancee.', 'gestion-atelier-cct' ) );
			$order->save();

			do_action( 'kojito_declencher_paiement_solde', $order->get_id() );

			$order = wc_get_order( $order->get_id() );

			if ( ! $order ) {
				return;
			}

			$balance = $this->revision_balance_due( $order );

			$order->add_order_note(
				sprintf(
					__( 'Preparation du solde terminee. Solde restant courant : %s.', 'gestion-atelier-cct' ),
					function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $balance ) ) : (string) $balance
				)
			);
			$order->save();

			// Rien a encaisser : on n'envoie pas de demande de solde, on enchaine
			// directement sur l'etat 7 (rapport disponible pour le client).
			if ( $balance <= 0.005 ) {
				$order->add_order_note( __( 'Solde nul : passage automatique en etat 7 (revision finie, rapport disponible).', 'gestion-atelier-cct' ) );
				$order->save();

				$this->advance_revision_state( $revision_id, 7, $revision );
				return;
			}
		}

		$config = $this->notification_config_for_state( $state );

		if ( empty( $config['enabled'] ) ) {
			$order->add_order_note( sprintf( __( 'Notification etat %d ignoree : email desactive dans la configuration Atelier.', 'gestion-atelier-cct' ), $state ) );
			$order->save();
			return;
		}

		$attachments = array();

		if ( 7 === $state ) {
			// TOUS les rapports déposés sur le dossier (le champ accepte
			// plusieurs PDF depuis le 28/07/2026).
			$attachments = $this->revision_report_pdf_paths( $revision );

			if ( empty( $attachments ) ) {
				$order->add_order_note( __( 'ERREUR : email etat 7 non envoye, rapport PDF introuvable ou inaccessible.', 'gestion-atelier-cct' ) );
				$order->save();
				return;
			}
		}

		$variables = $this->notification_variables( $revision_id, $revision, $order, $validation_url );
		$subject   = $this->render_notification_template( $config['subject'], $variables );
		$body      = $this->render_notification_template( $config['body'], $variables );

		if ( in_array( 'client', $config['recipients'], true ) ) {
			$client_email = $this->order_customer_email( $order );

			if ( is_email( $client_email ) ) {
				$sent = $this->send_notification_email( $client_email, $subject, $body, $attachments );

				if ( $sent ) {
					$order->add_order_note(
						sprintf(
							__( 'E-mail envoye : %1$s a l adresse %2$s.', 'gestion-atelier-cct' ),
							$label,
							$client_email
						)
					);
				} else {
					$order->add_order_note(
						sprintf(
							__( 'ERREUR : Echec de l envoi de l e-mail %1$s au client %2$s.', 'gestion-atelier-cct' ),
							$label,
							$client_email
						)
					);
				}
			} else {
				$order->add_order_note( sprintf( __( 'ERREUR : E-mail %s non envoye, adresse client invalide.', 'gestion-atelier-cct' ), $label ) );
			}
		}

		if ( in_array( 'admin', $config['recipients'], true ) ) {
			$admin_email = $this->notification_admin_email();

			if ( is_email( $admin_email ) ) {
				$sent = $this->send_notification_email( $admin_email, $subject, $body, $attachments );

				if ( $sent ) {
					$order->add_order_note(
						sprintf(
							__( 'Copie admin envoyee : %1$s a l adresse %2$s.', 'gestion-atelier-cct' ),
							$label,
							$admin_email
						)
					);
				} else {
					$order->add_order_note(
						sprintf(
							__( 'ERREUR : Echec de l envoi de la copie admin %1$s a %2$s.', 'gestion-atelier-cct' ),
							$label,
							$admin_email
						)
					);
				}
			} else {
				$order->add_order_note( sprintf( __( 'ERREUR : Copie admin %s non envoyee, adresse admin invalide.', 'gestion-atelier-cct' ), $label ) );
			}
		}

		$order->save();
	}

	/**
	 * Emails du workflow, indexes par etat d'ARRIVEE. Pas d'entree pour 5
	 * (« Intervention a finir ») : les emails d'acceptation et de refus du devis
	 * sont ceux de gacct-quote.php.
	 */
	private function notification_definitions() {
		return array(
			2 => array(
				'enabled'    => true,
				'label'      => __( 'Voile receptionnee, programmee pour intervention', 'gestion-atelier-cct' ),
				'recipients' => array( 'client', 'admin' ),
				'subject'    => __( 'Votre materiel est bien arrive a l atelier - {order_id}', 'gestion-atelier-cct' ),
				'body'       => '<p>Bonjour {customer_name},</p><p>Nous vous confirmons la reception de votre materiel. L intervention est programmee pour le {date_atelier}. Prestations prevues : {prestations}.</p><p>A tres vite,<br><br>Bastien.</p>',
			),
			3 => array(
				'enabled'    => true,
				'label'      => __( 'Intervention programmee', 'gestion-atelier-cct' ),
				'recipients' => array( 'admin' ),
				'subject'    => __( '[Alerte] Intervention demarree - Commande {order_id}', 'gestion-atelier-cct' ),
				'body'       => '<p>L intervention sur le materiel de {customer_name} vient de demarrer a l atelier. Prestations prevues : {prestations}.</p>',
			),
			4 => array(
				'enabled'    => true,
				'label'      => __( 'Nouveau devis a valider', 'gestion-atelier-cct' ),
				'recipients' => array( 'client', 'admin' ),
				'subject'    => __( 'Action requise : Mise a jour de votre devis - {order_id}', 'gestion-atelier-cct' ),
				'body'       => '<p>Bonjour {customer_name},</p>'
					. '<p>Suite a l inspection de votre materiel, des travaux complementaires sont necessaires :</p>'
					. '{quote_lines}'
					. '{quote_comment}'
					. '<p>Nouveau total de votre commande : <strong>{quote_total}</strong>, soit un solde de <strong>{quote_balance}</strong> a regler a la fin de l intervention (votre acompte deja verse reste inchange).</p>'
					. '<p><a href="{validation_url}">Consulter le devis et donner ma reponse</a> — vous pourrez l accepter ou le refuser en un clic.</p>'
					. '<p>Merci de votre reactivite,<br><br>Bastien.</p>',
			),
			6 => array(
				'enabled'    => true,
				'label'      => __( 'Intervention finie en attente de paiement', 'gestion-atelier-cct' ),
				'recipients' => array( 'client', 'admin' ),
				'subject'    => __( 'C est pret ! Solde a regler pour votre commande {order_id}', 'gestion-atelier-cct' ),
				'body'       => '<p>Bonjour {customer_name},</p><p>L entretien de votre materiel est termine ! Il ne vous reste plus qu a regler le solde de {balance_amount} pour finaliser la commande. <a href="{payment_url}">Regler ma commande</a>.</p><p>A bientot,<br><br>Bastien.</p>',
			),
			7 => array(
				'enabled'    => true,
				'label'      => __( 'Revision finie', 'gestion-atelier-cct' ),
				'recipients' => array( 'client', 'admin' ),
				'subject'    => __( 'Votre revision est terminee ! Retrouvez votre rapport - {order_id}', 'gestion-atelier-cct' ),
				'body'       => '<p>Bonjour {customer_name},</p><p>La revision est officiellement terminee. Vous trouverez votre rapport technique complet en piece jointe de cet e-mail.</p><p>Merci de votre confiance,<br><br>Bastien.</p>',
			),
			8 => array(
				'enabled'    => true,
				'label'      => __( 'Materiel reexpedie', 'gestion-atelier-cct' ),
				'recipients' => array( 'client', 'admin' ),
				'subject'    => __( 'Votre materiel est reparti ! - {order_id}', 'gestion-atelier-cct' ),
				'body'       => '<p>Bonjour {customer_name},</p><p>Votre materiel a quitte l atelier et voyage vers vous.</p><p>Suivi de votre colis : {tracking_link}</p><p>Bons vols,<br><br>Bastien.</p>',
			),
		);
	}

	private function notification_settings() {
		$definitions = $this->notification_definitions();
		$settings    = get_option( self::NOTIFICATION_SETTINGS_OPT, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$emails = isset( $settings['emails'] ) && is_array( $settings['emails'] ) ? $settings['emails'] : array();

		foreach ( $definitions as $state => $definition ) {
			$emails[ $state ] = array_merge(
				$definition,
				isset( $emails[ $state ] ) && is_array( $emails[ $state ] ) ? $emails[ $state ] : array()
			);
			$emails[ $state ]['recipients'] = $definition['recipients'];
			$emails[ $state ]['label']      = $definition['label'];
		}

		return array(
			'admin_email' => ! empty( $settings['admin_email'] ) && is_email( $settings['admin_email'] ) ? $settings['admin_email'] : get_option( 'admin_email' ),
			'emails'      => $emails,
		);
	}

	private function notification_config_for_state( $state ) {
		$settings = $this->notification_settings();
		return isset( $settings['emails'][ $state ] ) ? $settings['emails'][ $state ] : array();
	}

	private function notification_admin_email() {
		$settings = $this->notification_settings();
		return $settings['admin_email'];
	}

	private function notification_variables( $revision_id, array $revision, $order, $validation_url ) {
		$order_id       = $order instanceof WC_Order ? $order->get_id() : 0;
		$balance_amount = $order instanceof WC_Order ? (float) $order->get_meta( self::KOJITO_META_SOLDE_RESTANT ) : 0;
		$payment_url    = $order instanceof WC_Order ? $order->get_checkout_payment_url() : '';

		// Devis complémentaire : lignes ajoutées, commentaire atelier, nouveaux montants.
		$quote_lines   = '';
		$quote_comment = '';
		$quote_total   = 0.0;
		$quote_balance = 0.0;

		if ( $order instanceof WC_Order && function_exists( 'gacct_quote_lines_html' ) ) {
			$quote_lines   = gacct_quote_lines_html( $order );
			$quote_total   = gacct_kojito_total_initial( $order );
			$quote_balance = gacct_quote_new_balance( $order );

			$comment = trim( (string) $order->get_meta( GACCT_QUOTE_META_COMMENT ) );
			if ( '' !== $comment ) {
				$quote_comment = '<p><em>' . esc_html__( 'Le mot de l atelier :', 'gestion-atelier-cct' ) . ' ' . esc_html( $comment ) . '</em></p>';
			}
		}

		// Suivi transporteur (etat 8) : lien cliquable si c'en est un, sinon
		// le numero brut tel que l'operateur l'a saisi.
		$tracking      = trim( (string) ( $revision['suivi_transporteur'] ?? '' ) );
		$tracking_link = '';

		if ( '' !== $tracking ) {
			$tracking_link = preg_match( '#^https?://#i', $tracking )
				? '<a href="' . esc_url( $tracking ) . '">' . esc_html__( 'suivre mon colis', 'gestion-atelier-cct' ) . '</a>'
				: '<strong>' . esc_html( $tracking ) . '</strong>';
		}

		return array(
			'{customer_name}'  => $this->notification_customer_name( $revision_id, $revision, $order ),
			'{tracking_link}'  => $tracking_link,
			'{prestations}'    => $this->revision_prestations_label( $revision ),
			'{date_atelier}'   => $this->revision_workshop_date_label( $revision_id ),
			'{order_id}'       => (string) $order_id,
			'{balance_amount}' => function_exists( 'wc_price' ) ? wc_price( $balance_amount ) : (string) $balance_amount,
			'{payment_url}'    => esc_url( $payment_url ),
			'{validation_url}' => esc_url( $validation_url ),
			'{quote_lines}'    => $quote_lines,
			'{quote_comment}'  => $quote_comment,
			'{quote_total}'    => function_exists( 'wc_price' ) ? wc_price( $quote_total ) : (string) $quote_total,
			'{quote_balance}'  => function_exists( 'wc_price' ) ? wc_price( $quote_balance ) : (string) $quote_balance,
		);
	}

	private function notification_customer_name( $revision_id, array $revision, $order ) {
		if ( $order instanceof WC_Order ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

			if ( '' !== $name ) {
				return $name;
			}
		}

		$client_id = $this->related_object_id( $revision_id, $this->relation_id( 'client_to_revision', self::REL_CLIENT_REV ) );
		$name      = $this->client_label( $client_id, $revision );

		return '' !== $name ? $name : __( 'client', 'gestion-atelier-cct' );
	}

	private function order_customer_email( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$email = $order->get_billing_email();

		if ( is_email( $email ) ) {
			return $email;
		}

		$user_id = $order->get_user_id();

		if ( $user_id ) {
			$user = get_userdata( $user_id );
			return $user ? $user->user_email : '';
		}

		return '';
	}

	private function create_validation_url( $order, $revision_id ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$token = wp_generate_password( 32, false, false );

		$order->update_meta_data( self::META_VALIDATION_TOKEN_HASH, $this->hash_validation_token( $token ) );
		$order->update_meta_data( self::META_VALIDATION_TOKEN_CREATED_AT, current_time( 'mysql' ) );
		$order->delete_meta_data( self::META_VALIDATION_TOKEN_USED_AT );
		$order->update_meta_data( self::META_VALIDATION_REVISION_ID, absint( $revision_id ) );

		return add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'token'    => $token,
			),
			home_url( '/' . trim( apply_filters( 'gacct_validation_path', 'devis-a-valider' ), '/' ) . '/' )
		);
	}

	private function hash_validation_token( $token ) {
		return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
	}

	private function render_notification_template( $template, array $variables ) {
		return strtr( (string) $template, $variables );
	}

	private function send_notification_email( $to, $subject, $body, array $attachments = array() ) {
		// Gabarit WooCommerce complet (en-tete + logo, cartouche, pied de page)
		// avec inlining des styles : cf. gacct_render_email_html() dans
		// includes/gacct-payments.php.
		$message = function_exists( 'gacct_render_email_html' )
			? gacct_render_email_html( $subject, $body )
			: $body;

		return wp_mail(
			$to,
			wp_strip_all_tags( $subject ),
			$message,
			array( 'Content-Type: text/html; charset=UTF-8' ),
			$attachments
		);
	}

	private function related_object_id( $object_id, $relation_id ) {
		global $wpdb;

		$object_id = absint( $object_id );

		if ( ! $object_id ) {
			return 0;
		}

		$table = $this->relation_table_name();

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT parent_object_id, child_object_id FROM {$table} WHERE rel_id = %s AND (parent_object_id = %d OR child_object_id = %d) LIMIT 1",
				(string) $relation_id,
				$object_id,
				$object_id
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return 0;
		}

		$parent_id = absint( $row['parent_object_id'] );
		$child_id  = absint( $row['child_object_id'] );

		return $parent_id === $object_id ? $child_id : $parent_id;
	}

	private function get_revision_row( $revision_id ) {
		$rows = $this->get_revision_rows( array( $revision_id ) );
		return isset( $rows[ absint( $revision_id ) ] ) ? $rows[ absint( $revision_id ) ] : array();
	}

	private function update_revision_state( $revision_id, $state ) {
		global $wpdb;

		$table = $this->table_name( 'revision' );

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$current_state = $wpdb->get_var( $wpdb->prepare( "SELECT etat_de_la_commande FROM {$table} WHERE _ID = %d", absint( $revision_id ) ) );

		if ( (string) $current_state === (string) $state ) {
			return true;
		}

		$data = array(
			'etat_de_la_commande' => (string) absint( $state ),
		);

		if ( in_array( 'cct_modified', $this->table_columns( $table ), true ) ) {
			$data['cct_modified'] = current_time( 'mysql' );
		}

		return false !== $wpdb->update(
			$table,
			$data,
			array( '_ID' => absint( $revision_id ) ),
			$this->insert_formats_for_data( $data ),
			array( '%d' )
		);
	}

	/**
	 * Solde restant du sur une commande. Source unique : la meta posee par
	 * kojito-acompte-produit ; repli sur l'API publique du plugin d'acompte
	 * (total du - acompte deja verse). Aucun calcul maison.
	 */
	private function revision_balance_due( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 0.0;
		}

		$meta = $order->get_meta( self::KOJITO_META_SOLDE_RESTANT );

		if ( '' !== (string) $meta && null !== $meta ) {
			return (float) $meta;
		}

		if ( function_exists( 'gacct_kojito_total_initial' ) && function_exists( 'gacct_quote_deposit_paid' ) ) {
			return round( gacct_kojito_total_initial( $order ) - gacct_quote_deposit_paid( $order ), 2 );
		}

		return 0.0;
	}

	/**
	 * Avancement automatique d'une revision (enchainement 6 -> 7 quand le solde
	 * est nul, bascule au paiement du solde). Meme mecanique que
	 * gacct_op_change_state() : ecriture SQL + hook JetEngine updated-item, pour
	 * que le workflow (emails, PJ rapport) se declenche normalement.
	 *
	 * @return bool
	 */
	public function advance_revision_state( $revision_id, $new_state, array $prev_revision = array() ) {
		$revision_id = absint( $revision_id );

		if ( empty( $prev_revision ) ) {
			$prev_revision = $this->get_revision_row( $revision_id );
		}

		if ( ! $this->update_revision_state( $revision_id, $new_state ) ) {
			return false;
		}

		$new_revision = $this->get_revision_row( $revision_id );

		if ( empty( $new_revision ) ) {
			$new_revision = array_merge( $prev_revision, array( 'etat_de_la_commande' => (string) absint( $new_state ) ) );
		}

		$new_revision['_ID'] = $revision_id;

		do_action( 'jet-engine/custom-content-types/updated-item/revision', $new_revision, $prev_revision, null );

		return true;
	}

	private function revision_workshop_date_label( $revision_id ) {
		global $wpdb;

		$occupation_id = $this->related_object_id( $revision_id, $this->relation_id( 'revision_to_occupation', self::REL_REV_OCC ) );

		if ( ! $occupation_id ) {
			return '';
		}

		$table = $this->table_name( 'occupation_atelier' );

		if ( ! $this->table_exists( $table ) ) {
			return '';
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE _ID = %d", $occupation_id ), ARRAY_A );

		if ( empty( $row ) ) {
			return '';
		}

		foreach ( array( 'date_reservee', 'date_atelier', 'date_jour' ) as $field ) {
			if ( empty( $row[ $field ] ) ) {
				continue;
			}

			return $this->format_date_value( $row[ $field ] );
		}

		return '';
	}

	private function format_date_value( $value ) {
		if ( is_numeric( $value ) ) {
			return wp_date( get_option( 'date_format' ), (int) $value );
		}

		$timestamp = strtotime( (string) $value );

		if ( false === $timestamp ) {
			return wp_strip_all_tags( (string) $value );
		}

		return wp_date( get_option( 'date_format' ), $timestamp );
	}

	private function revision_prestations_label( array $revision ) {
		$product_ids = $this->revision_product_ids( $revision );
		$names       = array();

		foreach ( $product_ids as $product_id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;

			if ( $product ) {
				$names[] = $product->get_name();
				continue;
			}

			$title = get_the_title( $product_id );

			if ( '' !== $title ) {
				$names[] = $title;
			}
		}

		$names = array_values( array_unique( array_filter( array_map( 'wp_strip_all_tags', $names ) ) ) );

		return ! empty( $names ) ? implode( ', ', $names ) : '';
	}

	private function revision_product_ids( array $revision ) {
		$fields      = array( 'revisions_controle', 'suspentes__travaux', 'pliages_secours', 'frais_de_port' );
		$product_ids = array();

		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $revision ) ) {
				$product_ids = array_merge( $product_ids, $this->normalize_product_ids( $revision[ $field ] ) );
			}
		}

		$product_ids = array_map( 'absint', $product_ids );
		$product_ids = array_values( array_unique( array_filter( $product_ids ) ) );

		return $product_ids;
	}

	private function normalize_product_ids( $value ) {
		if ( is_array( $value ) ) {
			$ids = array();

			foreach ( $value as $key => $item ) {
				if ( is_scalar( $key ) && ctype_digit( (string) $key ) && ! empty( $item ) ) {
					$ids[] = absint( $key );
				}

				$ids = array_merge( $ids, $this->normalize_product_ids( $item ) );
			}

			return $ids;
		}

		$values = $this->normalize_checkbox_values( $value );
		$ids    = array();

		foreach ( $values as $item ) {
			$item = trim( (string) $item );

			if ( ctype_digit( $item ) ) {
				$ids[] = absint( $item );
			}
		}

		return $ids;
	}

	private function revision_report_pdf_path( array $revision ) {
		$paths = $this->revision_report_pdf_paths( $revision );

		return $paths ? $paths[0] : '';
	}

	/**
	 * Chemins absolus de TOUS les rapports PDF du dossier (le champ
	 * rapport_pdf accepte plusieurs pièces jointes, cf. gacct-reports.php).
	 *
	 * @param array $revision Ligne CCT.
	 * @return string[]
	 */
	private function revision_report_pdf_paths( array $revision ) {
		if ( empty( $revision['rapport_pdf'] ) ) {
			return array();
		}

		$values = $this->normalize_checkbox_values( $revision['rapport_pdf'] );

		if ( empty( $values ) ) {
			$values = array( $revision['rapport_pdf'] );
		}

		$paths = array();

		foreach ( $values as $value ) {
			$path = $this->resolve_media_path( $value );

			if ( '' !== $path && ! in_array( $path, $paths, true ) ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	private function resolve_media_path( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( ctype_digit( $value ) ) {
			$file = get_attached_file( absint( $value ) );
			return $file && file_exists( $file ) ? $file : '';
		}

		if ( file_exists( $value ) ) {
			return $value;
		}

		$url = strtok( $value, '?' );

		if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$attachment_id = attachment_url_to_postid( $url );

			if ( $attachment_id ) {
				$file = get_attached_file( $attachment_id );

				if ( $file && file_exists( $file ) ) {
					return $file;
				}
			}

			$uploads = wp_upload_dir();
			$baseurl = isset( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '';
			$basedir = isset( $uploads['basedir'] ) ? untrailingslashit( $uploads['basedir'] ) : '';

			if ( $baseurl && $basedir && 0 === strpos( $url, $baseurl ) ) {
				$relative = ltrim( substr( $url, strlen( $baseurl ) ), '/' );
				$file     = $basedir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, rawurldecode( $relative ) );

				if ( file_exists( $file ) ) {
					return $file;
				}
			}

			$path = wp_parse_url( $url, PHP_URL_PATH );

			if ( $path && false !== strpos( $path, '/wp-content/uploads/' ) ) {
				$file = ABSPATH . str_replace( '/', DIRECTORY_SEPARATOR, ltrim( rawurldecode( $path ), '/' ) );

				if ( file_exists( $file ) ) {
					return $file;
				}
			}
		}

		return '';
	}

	private function parse_calendar_range( $start_raw, $end_raw ) {
		$timezone = wp_timezone();
		$start    = new DateTimeImmutable( $start_raw, $timezone );
		$end      = new DateTimeImmutable( $end_raw, $timezone );

		return array(
			'start_ts' => $start->getTimestamp(),
			'end_ts'   => $end->getTimestamp(),
		);
	}

	private function normalize_decimal_hours( $raw ) {
		$raw = str_replace( ',', '.', (string) $raw );

		return round( (float) $raw, 2 );
	}

	private function time_to_seconds( $time ) {
		if ( ! is_string( $time ) || ! preg_match( '/^(\d{1,3}):([0-5]\d)(?::([0-5]\d))?$/', $time, $matches ) ) {
			return 0;
		}

		$seconds = isset( $matches[3] ) ? (int) $matches[3] : 0;

		return ( (int) $matches[1] * HOUR_IN_SECONDS ) + ( (int) $matches[2] * MINUTE_IN_SECONDS ) + $seconds;
	}

	private function format_duration_label( $time ) {
		if ( ! is_string( $time ) || ! preg_match( '/^(\d{1,3}):([0-5]\d)(?::([0-5]\d))?$/', $time, $matches ) ) {
			return '';
		}

		$hours   = (int) $matches[1];
		$minutes = (int) $matches[2];

		if ( $hours > 0 && $minutes > 0 ) {
			return sprintf( '%d h %02d', $hours, $minutes );
		}

		if ( $hours > 0 ) {
			return sprintf( '%d h', $hours );
		}

		return sprintf( '%d min', $minutes );
	}

	private function is_ymd_date( $date ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}

		$parts = array_map( 'absint', explode( '-', $date ) );

		return checkdate( $parts[1], $parts[2], $parts[0] );
	}

	private function is_hm_time( $time ) {
		return is_string( $time ) && preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time );
	}

	private function opening_time() {
		$opening_time = (string) get_option( self::OPENING_TIME_OPT, '09:00' );

		if ( ! $this->is_hm_time( $opening_time ) ) {
			return '09:00';
		}

		return $opening_time;
	}

	private function week_days() {
		return array(
			1 => __( 'Lundi', 'gestion-atelier-cct' ),
			2 => __( 'Mardi', 'gestion-atelier-cct' ),
			3 => __( 'Mercredi', 'gestion-atelier-cct' ),
			4 => __( 'Jeudi', 'gestion-atelier-cct' ),
			5 => __( 'Vendredi', 'gestion-atelier-cct' ),
			6 => __( 'Samedi', 'gestion-atelier-cct' ),
			7 => __( 'Dimanche', 'gestion-atelier-cct' ),
		);
	}

	private function working_days() {
		$working_days = get_option( self::WORKING_DAYS_OPT, array( 1, 2, 3, 4, 5 ) );

		if ( ! is_array( $working_days ) ) {
			return array( 1, 2, 3, 4, 5 );
		}

		$working_days = $this->sanitize_working_days( $working_days );

		return ! empty( $working_days ) ? $working_days : array( 1, 2, 3, 4, 5 );
	}

	private function sanitize_working_days( array $working_days ) {
		$working_days = array_map( 'absint', $working_days );
		$working_days = array_values( array_unique( array_filter( $working_days, function ( $day ) {
			return $day >= 1 && $day <= 7;
		} ) ) );
		sort( $working_days );

		return $working_days;
	}

	private function format_hours( $hours ) {
		$rounded = round( (float) $hours, 2 );

		if ( abs( $rounded - round( $rounded ) ) < 0.01 ) {
			return (string) (int) round( $rounded );
		}

		return rtrim( rtrim( number_format_i18n( $rounded, 2 ), '0' ), ',' );
	}

	private function availability_style( $available ) {
		$available = (float) $available;

		if ( $available <= 0 ) {
			return array(
				'state'      => 'danger',
				'background' => '#f7d9d9',
				'border'     => '#b42318',
			);
		}

		if ( $available < 1 ) {
			return array(
				'state'      => 'warning',
				'background' => '#ffecd1',
				'border'     => '#b54708',
			);
		}

		return array(
			'state'      => 'success',
			'background' => '#d7f4df',
			'border'     => '#2e8540',
		);
	}

	private function insert_formats_for_data( array $data ) {
		$formats = array();

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( '_ID', 'cct_author_id', 'date_reservee', 'date_jour' ), true ) ) {
				$formats[] = '%d';
				continue;
			}

			if ( is_float( $value ) ) {
				$formats[] = '%f';
				continue;
			}

			$formats[] = '%s';
		}

		return $formats;
	}

	private function filter_data_by_table_columns( $table, array $data ) {
		$columns = $this->table_columns( $table );

		if ( empty( $columns ) ) {
			return $data;
		}

		return array_intersect_key( $data, array_flip( $columns ) );
	}

	private function table_columns( $table ) {
		static $cache = array();

		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

		global $wpdb;

		$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 );

		if ( ! is_array( $columns ) ) {
			$columns = array();
		}

		$cache[ $table ] = $columns;

		return $columns;
	}

	private function table_column_type( $table, $column ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ), ARRAY_A );

		if ( empty( $row['Type'] ) ) {
			return '';
		}

		return strtolower( $row['Type'] );
	}

	private function table_can_store_decimal_hours( $table, $column ) {
		$type = $this->table_column_type( $table, $column );

		if ( '' === $type ) {
			return false;
		}

		return (bool) preg_match( '/decimal|float|double|text|varchar|char/', $type );
	}

	private function is_whole_number( $value ) {
		return abs( (float) $value - round( (float) $value ) ) < 0.00001;
	}

	private function table_option_name( $slug ) {
		switch ( $slug ) {
			case 'calendrier_dispo':
				return self::TABLE_CALENDAR_OPT;
			case 'occupation_atelier':
				return self::TABLE_OCCUPATION_OPT;
			case 'revision':
				return self::TABLE_REVISION_OPT;
		}

		return '';
	}

	private function configured_table_value( $slug ) {
		$option = $this->table_option_name( $slug );

		if ( '' === $option ) {
			return '';
		}

		return (string) get_option( $option, '' );
	}

	private function sanitize_table_config_value( $value ) {
		return preg_replace( '/[^A-Za-z0-9_]/', '', (string) $value );
	}

	private function table_field_description( $default_slug ) {
		global $wpdb;

		return sprintf(
			/* translators: 1: cct slug example, 2: table suffix example, 3: full table example */
			__( 'Laisser vide pour %1$s. Accepte un slug CCT (%1$s), un suffixe (%2$s) ou le nom complet (%3$s).', 'gestion-atelier-cct' ),
			$default_slug,
			'jet_cct_' . $default_slug,
			$wpdb->prefix . 'jet_cct_' . $default_slug
		);
	}

	private function table_name( $slug ) {
		global $wpdb;

		$slug = sanitize_key( $slug );
		$configured = $this->configured_table_value( $slug );

		if ( '' !== $configured ) {
			if ( 0 === strpos( $configured, $wpdb->prefix ) ) {
				return apply_filters( 'gacct_cct_table_name', $configured, $slug );
			}

			if ( 0 === strpos( $configured, 'jet_cct_' ) ) {
				return apply_filters( 'gacct_cct_table_name', $wpdb->prefix . $configured, $slug );
			}

			return apply_filters( 'gacct_cct_table_name', $wpdb->prefix . 'jet_cct_' . $configured, $slug );
		}

		$table = $wpdb->prefix . 'jet_cct_' . $slug;

		return apply_filters( 'gacct_cct_table_name', $table, $slug );
	}

	private function relation_table_name() {
		global $wpdb;

		return apply_filters( 'gacct_relation_table_name', $wpdb->prefix . 'jet_rel_default' );
	}

	private function relation_id( $relation_key, $default ) {
		return apply_filters( 'gacct_relation_id', $default, $relation_key );
	}

	private function table_exists( $table ) {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function missing_tables() {
		$tables = array(
			$this->table_name( 'calendrier_dispo' ),
			$this->table_name( 'occupation_atelier' ),
			$this->table_name( 'revision' ),
			$this->relation_table_name(),
		);

		$missing = array();

		foreach ( $tables as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}

	private function capability() {
		return apply_filters( 'gacct_admin_capability', 'manage_options' );
	}
}

GACCT_Plugin::instance();


/* =============================================================================
 *  ETAT 6 -> 7 : LE SOLDE EST PAYE
 *
 *  L'ancien etat « paiement valide » a disparu : des que la commande repasse a
 *  un statut paye apres le lien order-pay du solde, la revision passe a 7 et le
 *  client recoit son rapport. On ne bascule QUE depuis 6 (un dossier n'avance
 *  jamais tout seul depuis un autre etat) et on ignore `acompte-paye`, qui ne
 *  concerne que l'acompte initial (bascule 0 -> 1).
 * ============================================================================= */

add_action( 'woocommerce_order_status_changed', 'gacct_sync_revision_state_on_balance_payment', 25, 4 );

function gacct_sync_revision_state_on_balance_payment( $order_id, $old_status, $new_status, $order ) {

    if ( ! $order instanceof WC_Order || ! $order->has_status( wc_get_is_paid_statuses() ) ) {
        return;
    }

    global $wpdb;

    $revision_id = absint( $order->get_meta( JWCCT_ORDER_REVISION_ID ) );

    // Repli : meta absente (commande invitee, liaison ratee) -> colonne order_id.
    if ( ! $revision_id ) {
        $revision_id = absint( $wpdb->get_var( $wpdb->prepare(
            "SELECT _ID FROM {$wpdb->prefix}jet_cct_revision WHERE order_id = %d AND cct_status = 'publish' LIMIT 1",
            $order_id
        ) ) );
    }

    if ( ! $revision_id ) {
        return;
    }

    // Etat lu en SQL direct : le cache d'objet JetEngine peut resservir une
    // valeur perimee au sein d'une meme requete.
    $state = gacct_op_read_state( $revision_id );

    if ( 6 !== $state ) {
        return;
    }

    $order->add_order_note( __( 'Solde encaisse : dossier atelier passe en « Revision finie, rapport disponible ».', 'gestion-atelier-cct' ) );
    $order->save();

    if ( ! GACCT_Plugin::instance()->advance_revision_state( $revision_id, 7 ) ) {
        jwcct_log( "sync_revision_state_on_balance_payment : echec du passage 6 -> 7 pour la revision $revision_id (order $order_id)." );
    }
}
