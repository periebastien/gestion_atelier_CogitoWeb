<?php
/**
 * Alimentation en donnees du formulaire front "Demande d'intervention" (JetFormBuilder 127).
 *
 * Le formulaire etait pilote par du JS embarque qui parsait le DOM (prix, durees,
 * disponibilites calendrier) : fragile et bugue des qu'un libelle ou une classe CSS
 * changeait. Ce module remplace ce parsing par des donnees calculees cote serveur
 * (prestations WooCommerce + disponibilites atelier) et localisees en JS via
 * `gacctDemande`, consommees par assets/js/demande-intervention.js.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
 *  DETECTION DE LA PAGE + ENQUEUE
 * ============================================================================= */

add_action( 'wp_enqueue_scripts', 'gacct_demande_enqueue_assets' );

/**
 * Enqueue flatpickr (vendored) + les assets du formulaire de demande d'intervention,
 * uniquement sur les pages qui rendent effectivement le formulaire JetFormBuilder cible.
 */
function gacct_demande_enqueue_assets() {
	if ( ! gacct_demande_should_enqueue() ) {
		return;
	}

	$base_url  = plugins_url( '', dirname( __FILE__ ) );
	$base_path = plugin_dir_path( dirname( __FILE__ ) );

	// --- Flatpickr vendorise (pas de CDN en prod, cf. politique du site) ---
	wp_enqueue_style(
		'gacct-flatpickr',
		$base_url . '/assets/vendor/flatpickr/flatpickr.min.css',
		array(),
		GACCT_Plugin::VERSION
	);

	wp_enqueue_script(
		'gacct-flatpickr',
		$base_url . '/assets/vendor/flatpickr/flatpickr.min.js',
		array(),
		GACCT_Plugin::VERSION,
		true
	);

	wp_enqueue_script(
		'gacct-flatpickr-fr',
		$base_url . '/assets/vendor/flatpickr/l10n/fr.js',
		array( 'gacct-flatpickr' ),
		GACCT_Plugin::VERSION,
		true
	);

	// --- Assets du formulaire (crees par ailleurs, on ne fait que les referencer) ---
	$js_rel_path  = 'assets/js/demande-intervention.js';
	$css_rel_path = 'assets/css/demande-intervention.css';
	$js_path      = $base_path . $js_rel_path;
	$css_path     = $base_path . $css_rel_path;

	wp_enqueue_script(
		'gacct-demande',
		$base_url . '/' . $js_rel_path,
		array( 'gacct-flatpickr-fr' ),
		file_exists( $js_path ) ? filemtime( $js_path ) : GACCT_Plugin::VERSION,
		true
	);

	wp_enqueue_style(
		'gacct-demande',
		$base_url . '/' . $css_rel_path,
		array(),
		file_exists( $css_path ) ? filemtime( $css_path ) : GACCT_Plugin::VERSION
	);

	wp_localize_script( 'gacct-demande', 'gacctDemande', gacct_demande_build_data() );
}

/**
 * Determine si la page couramment affichee rend le formulaire "demande d'intervention".
 * Detection : page singuliere dont l'_elementor_data contient le widget JFB,
 * ou dont le post_content contient un shortcode/bloc JFB.
 * Filtrable via `gacct_demande_enqueue` pour forcer ou empecher l'enqueue.
 */
function gacct_demande_should_enqueue() {
	$should_enqueue = false;

	if ( is_singular() ) {
		$post = get_queried_object();

		if ( $post instanceof WP_Post ) {
			$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );

			if ( is_string( $elementor_data ) && false !== strpos( $elementor_data, 'jet-form-builder-form' ) ) {
				$should_enqueue = true;
			} elseif (
				false !== strpos( (string) $post->post_content, 'jet_fb_form' )
				|| false !== strpos( (string) $post->post_content, 'jet-forms/form-block' )
			) {
				$should_enqueue = true;
			}
		}
	}

	return (bool) apply_filters( 'gacct_demande_enqueue', $should_enqueue );
}

/* =============================================================================
 *  CONSTRUCTION DES DONNEES LOCALISEES
 * ============================================================================= */

/**
 * Construit le tableau de donnees localise `gacctDemande` (formId, prestations, dispos...).
 *
 * @return array<string,mixed>
 */
function gacct_demande_build_data() {
	$data = array(
		'formId' => gacct_demande_form_id(),
		'devise' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES ),
		'champs' => array(
			'date'        => 'date_intervention',
			'duree'       => 'duree_totale_commande',
			'dateDispo'   => 'date_disponible',
			'port'        => 'frais_de_ports',
			'prestations' => array( 'revisions_controle', 'pliages_secours', 'suspentes_travaux' ),
		),
		'accordeonOuvert' => 'revisions_controle',
		'prestations'     => gacct_demande_prestations_map(),
		'dispos'          => gacct_demande_availability_map(),
		'i18n'            => array(
			'aucuneSelection' => __( 'Aucune prestation sélectionnée', 'gestion-atelier-cct' ),
			'erreurDate'      => __( "Vous devez sélectionner une date d'intervention", 'gestion-atelier-cct' ),
			'legendeDispo'    => __( 'Disponible', 'gestion-atelier-cct' ),
			'legendeSelection'=> __( 'Sélectionné', 'gestion-atelier-cct' ),
			'legendeIndispo'  => __( 'Indisponible', 'gestion-atelier-cct' ),
		),
	);

	return apply_filters( 'gacct_demande_data', $data );
}

/**
 * ID du formulaire JetFormBuilder "Demande d'intervention". Filtrable au cas ou
 * le formulaire serait duplique/recree avec un autre ID.
 *
 * @return int
 */
function gacct_demande_form_id() {
	$default = (int) get_option( 'gacct_demande_form_id', 127 );

	return (int) apply_filters( 'gacct_demande_form_id', $default ? $default : 127 );
}

/**
 * Map des queries JetEngine Query Builder alimentant les blocs de prestations
 * (et les frais de port, sans distinction : le JS trie via `champs.prestations`).
 * Filtrable pour ajouter/retirer une query sans toucher au code.
 *
 * @return array<string,int>
 */
function gacct_demande_queries_map() {
	return apply_filters(
		'gacct_demande_queries',
		array(
			'revisions_controle' => 3,
			'pliages_secours'    => 9,
			'suspentes_travaux'  => 10,
			'frais_de_ports'     => 4,
		)
	);
}

/**
 * Construit la map "<product_id>" => {prix, duree, titre} a partir des queries
 * JetEngine Query Builder configurees (prestations ET frais de port confondus).
 *
 * @return array<string,array<string,mixed>>
 */
function gacct_demande_prestations_map() {
	$prestations = array();

	if ( ! class_exists( '\Jet_Engine\Query_Builder\Manager' ) || ! function_exists( 'wc_get_product' ) ) {
		return $prestations;
	}

	$manager = \Jet_Engine\Query_Builder\Manager::instance();

	foreach ( gacct_demande_queries_map() as $query_id ) {
		$query = $manager->get_query_by_id( $query_id );

		if ( ! $query || ! method_exists( $query, 'get_items' ) ) {
			continue;
		}

		$items = $query->get_items();

		if ( empty( $items ) ) {
			continue;
		}

		foreach ( $items as $item ) {
			$product_id = is_object( $item ) && isset( $item->ID ) ? absint( $item->ID ) : absint( $item );

			if ( ! $product_id || isset( $prestations[ $product_id ] ) ) {
				continue;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$prestations[ (string) $product_id ] = array(
				'prix'  => (float) $product->get_price(),
				'duree' => gacct_demande_parse_duree( get_post_meta( $product_id, 'duree_presta', true ) ),
				'titre' => get_the_title( $product_id ),
			);
		}
	}

	return $prestations;
}

/**
 * Convertit la meta `duree_presta` (parfois saisie avec une virgule decimale) en float.
 * 0 si vide (cas des frais de port, qui n'ont pas de duree).
 *
 * @param mixed $raw
 * @return float
 */
function gacct_demande_parse_duree( $raw ) {
	if ( '' === $raw || null === $raw ) {
		return 0.0;
	}

	return (float) str_replace( ',', '.', (string) $raw );
}

/**
 * Calcule les heures restantes par jour ("Y-m-d" => float), uniquement pour les
 * dates a partir de demain, en reutilisant les tables CCT configurees (memes
 * options que le dashboard admin : calendrier_dispo moins occupation_atelier).
 *
 * @return array<string,float>
 */
function gacct_demande_availability_map() {
	global $wpdb;

	$calendar_table   = gacct_demande_table_name( 'calendrier_dispo' );
	$occupation_table = gacct_demande_table_name( 'occupation_atelier' );

	if ( ! gacct_demande_table_exists( $calendar_table ) ) {
		return array();
	}

	$timezone   = wp_timezone();
	$tomorrow   = ( new DateTimeImmutable( 'tomorrow', $timezone ) )->setTime( 0, 0, 0 );
	$from_ts    = $tomorrow->getTimestamp();

	if ( gacct_demande_table_exists( $occupation_table ) ) {
		$sql = $wpdb->prepare(
			"
			SELECT
				c.date_jour AS day_ts,
				CAST(c.heures_totales_dispo AS DECIMAL(10,2)) AS capacity_hours,
				COALESCE( (
					SELECT SUM( TIME_TO_SEC( o.duree_totale_commande ) / 3600 )
					FROM {$occupation_table} o
					WHERE o.cct_status = %s
						AND CAST( o.date_reservee AS UNSIGNED ) = CAST( c.date_jour AS UNSIGNED )
				), 0 ) AS occupied_hours
			FROM {$calendar_table} c
			WHERE c.cct_status = %s
				AND CAST( c.date_jour AS UNSIGNED ) >= %d
			ORDER BY CAST( c.date_jour AS UNSIGNED ) ASC
			",
			'publish',
			'publish',
			$from_ts
		);
	} else {
		$sql = $wpdb->prepare(
			"
			SELECT
				c.date_jour AS day_ts,
				CAST(c.heures_totales_dispo AS DECIMAL(10,2)) AS capacity_hours,
				0 AS occupied_hours
			FROM {$calendar_table} c
			WHERE c.cct_status = %s
				AND CAST( c.date_jour AS UNSIGNED ) >= %d
			ORDER BY CAST( c.date_jour AS UNSIGNED ) ASC
			",
			'publish',
			$from_ts
		);
	}

	$rows  = $wpdb->get_results( $sql, ARRAY_A );
	$dispos = array();

	foreach ( (array) $rows as $row ) {
		$available = max( 0, (float) $row['capacity_hours'] - (float) $row['occupied_hours'] );
		$day_key   = wp_date( 'Y-m-d', (int) $row['day_ts'], $timezone );

		$dispos[ $day_key ] = $available;
	}

	return $dispos;
}

/* =============================================================================
 *  RESOLUTION DES NOMS DE TABLE (memes options que la page Configuration du plugin)
 * ============================================================================= */

/**
 * Resout le nom complet de table CCT pour un slug donne, en reutilisant les
 * memes options de configuration que GACCT_Plugin (page Configuration atelier) :
 * `gacct_table_calendrier_dispo`, `gacct_table_occupation_atelier`, `gacct_table_revision`.
 *
 * @param string $slug
 * @return string
 */
function gacct_demande_table_name( $slug ) {
	global $wpdb;

	$slug   = sanitize_key( $slug );
	$option = gacct_demande_table_option_name( $slug );

	$configured = '' !== $option ? (string) get_option( $option, '' ) : '';

	if ( '' !== $configured ) {
		if ( 0 === strpos( $configured, $wpdb->prefix ) ) {
			return apply_filters( 'gacct_cct_table_name', $configured, $slug );
		}

		if ( 0 === strpos( $configured, 'jet_cct_' ) ) {
			return apply_filters( 'gacct_cct_table_name', $wpdb->prefix . $configured, $slug );
		}

		return apply_filters( 'gacct_cct_table_name', $wpdb->prefix . 'jet_cct_' . $configured, $slug );
	}

	return apply_filters( 'gacct_cct_table_name', $wpdb->prefix . 'jet_cct_' . $slug, $slug );
}

function gacct_demande_table_option_name( $slug ) {
	switch ( $slug ) {
		case 'calendrier_dispo':
			return class_exists( 'GACCT_Plugin' ) ? GACCT_Plugin::TABLE_CALENDAR_OPT : 'gacct_table_calendrier_dispo';
		case 'occupation_atelier':
			return class_exists( 'GACCT_Plugin' ) ? GACCT_Plugin::TABLE_OCCUPATION_OPT : 'gacct_table_occupation_atelier';
		case 'revision':
			return class_exists( 'GACCT_Plugin' ) ? GACCT_Plugin::TABLE_REVISION_OPT : 'gacct_table_revision';
	}

	return '';
}

function gacct_demande_table_exists( $table ) {
	global $wpdb;

	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}
