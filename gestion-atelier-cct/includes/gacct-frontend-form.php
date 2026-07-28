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
 * Detection : page singuliere dont l'_elementor_data (ou le post_content) rend le
 * formulaire JFB **dont l'ID est celui de la demande d'intervention**.
 * Filtrable via `gacct_demande_enqueue` pour forcer ou empecher l'enqueue.
 *
 * ⚠️ Ne PAS elargir a "n'importe quel widget jet-form-builder-form" : le script
 * demande-intervention.js pose un validateur de date en capture sur le formulaire
 * trouve et bloquerait la soumission de tout autre formulaire de la page
 * (ex. le formulaire de contact 1492).
 */
function gacct_demande_should_enqueue() {
	$should_enqueue = false;
	$form_id        = gacct_demande_form_id();

	if ( is_singular() && $form_id ) {
		$post = get_queried_object();

		if ( $post instanceof WP_Post ) {
			$elementor_data = (string) get_post_meta( $post->ID, '_elementor_data', true );
			$content        = (string) $post->post_content;

			// Widget Elementor : {"form_id":"127"} — l'ID peut etre serialise en chaine ou en entier.
			if (
				false !== strpos( $elementor_data, '"form_id":"' . $form_id . '"' )
				|| false !== strpos( $elementor_data, '"form_id":' . $form_id . ',' )
			) {
				$should_enqueue = true;
			} elseif (
				// Shortcode [jet_fb_form form_id="127"] ou bloc Gutenberg {"form_id":127}.
				false !== strpos( $content, 'form_id="' . $form_id . '"' )
				|| false !== strpos( $content, '"form_id":' . $form_id )
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
			'couleur'     => 'couleur_copy',
			'prestations' => array( 'revisions_controle', 'pliages_secours', 'suspentes_travaux' ),
			// Champs de la carte « Votre matériel », dans l'ordre d'affichage :
			// ils sont validés (par JetFormBuilder) AVANT nos propres contrôles.
			'materiel'    => array( 'marque', 'modele', 'numero_serie', 'taille', 'ptv', 'couleur_copy' ),
		),
		'couleurs'        => gacct_demande_couleurs_js(),
		'couleursMax'     => 3,
		'accordeonOuvert' => 'revisions_controle',
		'prestations'     => gacct_demande_prestations_map(),
		'dispos'          => gacct_demande_availability_map(),
		'materiels'       => gacct_demande_materiels_client(),
		'i18n'            => array(
			'aucuneSelection' => __( 'Aucune prestation sélectionnée', 'gestion-atelier-cct' ),
			'erreurDate'      => __( "Vous devez sélectionner une date d'intervention", 'gestion-atelier-cct' ),
			'erreurPrestations' => __( 'Vous devez sélectionner au moins une prestation (révision, pliage secours ou suspentes).', 'gestion-atelier-cct' ),
			'legendeDispo'    => __( 'Disponible', 'gestion-atelier-cct' ),
			'legendeSelection'=> __( 'Sélectionné', 'gestion-atelier-cct' ),
			'legendeIndispo'  => __( 'Indisponible', 'gestion-atelier-cct' ),
			'aucuneDate'      => __( 'Aucune date choisie', 'gestion-atelier-cct' ),
			'couleurAide'     => __( 'Cliquez vos couleurs, de la plus présente à la moins présente (3 maximum).', 'gestion-atelier-cct' ),
			'couleurApercu'   => __( 'Aperçu', 'gestion-atelier-cct' ),
			'acompte'         => __( 'Acompte à payer', 'gestion-atelier-cct' ),
			'acompteNote'     => __( 'Montant réglé à la commande. Le solde sera à régler une fois l\'intervention terminée.', 'gestion-atelier-cct' ),
			'materielTitre'   => __( 'Votre matériel', 'gestion-atelier-cct' ),
			'materielNouveau' => __( 'Nouvelle voile', 'gestion-atelier-cct' ),
			'materielAide'    => __( 'Sélectionnez une voile déjà suivie chez nous pour préremplir sa fiche, ou déclarez une nouvelle voile.', 'gestion-atelier-cct' ),
		),
	);

	$remat_id = gacct_demande_remat_id();
	if ( $remat_id ) {
		$data['rematId'] = $remat_id;
	}

	return apply_filters( 'gacct_demande_data', $data );
}

/**
 * Fragment SQL construisant la signature "couleurs" d'une colonne, insensible
 * a l'ordre de saisie du client (ex. "rouge, noir" et "noir, rouge" produisent
 * la meme signature). La palette est fermee (15 couleurs, source unique
 * gacct_couleurs_voile() dans gacct-frontend.php) : on genere ici un test de
 * presence (FIND_IN_SET) par couleur, dans l'ordre de cette palette, plutot
 * que de trier en SQL (MariaDB n'a pas de split/sort natif sur une liste).
 *
 * Doit produire exactement la meme cle que la GROUP BY de la query JetEngine
 * 22 (Mon Materiel) : si la palette change, les deux se regenerent ensemble.
 *
 * NB : REGEXP_REPLACE (pas REPLACE) - le validateur SQL "advanced mode" de ce
 * JetEngine bloque le token REPLACE (pense a REPLACE INTO) meme utilise comme
 * simple fonction de chaine ; sans objet ici (requete PHP classique, non en
 * advanced_mode), mais on garde la meme fonction que la query 22 par coherence.
 *
 * @param string $colonne Expression SQL de la colonne `couleur` (deja
 *                        qualifiee, ex. "r.couleur").
 * @return string Expression SQL `CONCAT(...)`.
 */
function gacct_demande_couleur_signature_sql( $colonne ) {
	$parts = array();

	foreach ( array_keys( gacct_couleurs_voile() ) as $nom ) {
		$nom_sql = esc_sql( $nom );
		$parts[] = "IF(FIND_IN_SET('{$nom_sql}', REGEXP_REPLACE({$colonne}, ', ', ','))>0,'{$nom_sql};','')";
	}

	return 'CONCAT(' . implode( ',', $parts ) . ')';
}

/**
 * Liste des voiles deja suivies (revisions publiees) pour le client connecte,
 * une entree par voile (dedoublonnage : marque + modele normalise + taille
 * normalisee + signature couleurs insensible a l'ordre — meme cle que le
 * tableau "Mon Materiel", query JetEngine 22 — on garde les valeurs de la
 * revision la plus recente en cas de plusieurs passages en atelier).
 * Vide si l'utilisateur n'est pas connecte ou n'a aucune voile.
 *
 * @param int $user_id Client cible (0 = utilisateur connecte). Ajoute pour le
 *                     tableau de bord client (gacct-dashboard.php), qui doit
 *                     pouvoir interroger un client donne hors contexte de session.
 * @return array<int,array<string,mixed>>
 */
function gacct_demande_materiels_client( $user_id = 0 ) {
	$materiels = array();

	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	if ( ! $user_id ) {
		return $materiels;
	}

	global $wpdb;

	$revision_table = gacct_demande_table_name( 'revision' );

	if ( ! gacct_demande_table_exists( $revision_table ) ) {
		return $materiels;
	}

	if ( ! function_exists( 'gacct_couleurs_voile' ) ) {
		return $materiels;
	}

	$couleur_sig = gacct_demande_couleur_signature_sql( 'r.couleur' );

	// Etape 1 : pour chaque voile (marque + modele normalise + taille normalisee
	// + signature couleurs), l'_ID de la revision la plus recente. GROUP_CONCAT +
	// SUBSTRING_INDEX est evite ici : la colonne `couleur` contient elle-meme des
	// virgules ("bleu, blanc"), ce qui rendrait tout separateur base sur la
	// virgule ambigu.
	$ids_sql = $wpdb->prepare(
		"
		SELECT SUBSTRING_INDEX( GROUP_CONCAT( r._ID ORDER BY r.cct_created DESC ), ',', 1 ) AS latest_id
		FROM {$revision_table} r
		INNER JOIN {$wpdb->prefix}jet_rel_default rel
			ON rel.rel_id = '13' AND rel.parent_object_id = %d AND rel.child_object_id = r._ID
		WHERE r.cct_status = %s
			AND r.marque IS NOT NULL AND TRIM(r.marque) != ''
			AND r.modele IS NOT NULL AND TRIM(r.modele) != ''
		GROUP BY r.marque, UPPER(REPLACE(r.modele,' ','')), REGEXP_REPLACE(UPPER(r.taille),'[^A-Z0-9]',''), {$couleur_sig}
		ORDER BY MAX( r.cct_created ) DESC
		",
		$user_id,
		'publish'
	);

	$latest_ids = array_map( 'absint', (array) $wpdb->get_col( $ids_sql ) );
	$latest_ids = array_filter( $latest_ids );

	if ( ! $latest_ids ) {
		return $materiels;
	}

	// Etape 2 : les valeurs completes de chacune de ces revisions, dans l'ordre
	// deja etabli (plus recente en tete).
	$placeholders = implode( ',', array_fill( 0, count( $latest_ids ), '%d' ) );
	$rows_sql     = $wpdb->prepare(
		"
		SELECT _ID, marque, modele, numero_de_serie, taille, couleur, p_t_v
		FROM {$revision_table}
		WHERE _ID IN ( {$placeholders} )
		",
		$latest_ids
	);

	$rows_by_id = array();
	foreach ( (array) $wpdb->get_results( $rows_sql, ARRAY_A ) as $row ) {
		$rows_by_id[ (int) $row['_ID'] ] = $row;
	}

	foreach ( $latest_ids as $id ) {
		if ( empty( $rows_by_id[ $id ] ) ) {
			continue;
		}
		$row         = $rows_by_id[ $id ];
		$materiels[] = array(
			'revision_id'  => (int) $row['_ID'],
			'marque'       => (string) $row['marque'],
			'modele'       => (string) $row['modele'],
			'numero_serie' => (string) $row['numero_de_serie'],
			'taille'       => (string) $row['taille'],
			'couleur'      => (string) $row['couleur'],
			'ptv'          => (string) $row['p_t_v'],
		);
	}

	return $materiels;
}

/**
 * Valide le parametre d'URL `?remat=<revision_id>` : ne renvoie l'ID que si la
 * revision existe, est publiee et appartient bien au client connecte (relation
 * 13). Silencieux dans tous les autres cas (utilisateur non connecte, revision
 * d'un autre client, ID invalide) : jamais d'erreur affichee au client.
 *
 * @return int 0 si absent/invalide.
 */
function gacct_demande_remat_id() {
	if ( empty( $_GET['remat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 0;
	}

	$remat_id = absint( wp_unslash( $_GET['remat'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$user_id = get_current_user_id();
	if ( ! $remat_id || ! $user_id ) {
		return 0;
	}

	global $wpdb;

	$revision_table = gacct_demande_table_name( 'revision' );

	if ( ! gacct_demande_table_exists( $revision_table ) ) {
		return 0;
	}

	$found = $wpdb->get_var(
		$wpdb->prepare(
			"
			SELECT r._ID
			FROM {$revision_table} r
			INNER JOIN {$wpdb->prefix}jet_rel_default rel
				ON rel.rel_id = '13' AND rel.parent_object_id = %d AND rel.child_object_id = r._ID
			WHERE r._ID = %d AND r.cct_status = %s
			",
			$user_id,
			$remat_id,
			'publish'
		)
	);

	return $found ? (int) $found : 0;
}

/**
 * Palette de couleurs mise en forme pour le selecteur JS : liste ordonnee de
 * { nom, hex }. La source reste gacct_couleurs_voile() (gacct-frontend.php),
 * partagee avec la vignette de l'espace client — une couleur ajoutee la-bas
 * apparait ici sans autre intervention.
 *
 * @return array<int,array{nom:string,hex:string}>
 */
function gacct_demande_couleurs_js() {
	$liste = array();

	if ( ! function_exists( 'gacct_couleurs_voile' ) ) {
		return $liste;
	}

	foreach ( gacct_couleurs_voile() as $nom => $teintes ) {
		$liste[] = array(
			'nom' => $nom,
			'hex' => $teintes['base'],
		);
	}

	return $liste;
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

			$prix = (float) $product->get_price();

			$prestations[ (string) $product_id ] = array(
				'prix'    => $prix,
				'acompte' => gacct_demande_acompte( $product_id, $prix ),
				'duree'   => gacct_demande_parse_duree( get_post_meta( $product_id, 'duree_presta', true ) ),
				'titre'   => get_the_title( $product_id ),
			);
		}
	}

	return $prestations;
}

/**
 * Montant reellement encaisse a la commande pour un produit, en miroir exact de la
 * regle appliquee par le plugin Kojito Acompte Produit dans le panier
 * (`Kojito_Acompte_Produit::modifier_prix_panier_acompte`) :
 *
 *   - meta `_kojito_montant_acompte` numerique et >= 0 -> c'est ce montant qui est facture
 *                                                        (0 = rien a payer a la commande,
 *                                                        la totalite part dans le solde) ;
 *   - meta vide / non numerique / negative             -> le produit est facture au prix plein.
 *
 * Le formulaire affiche donc toujours l'acompte que le client va effectivement
 * payer au checkout, y compris pour les produits dont l'acompte n'est pas renseigne.
 *
 * @param int   $product_id
 * @param float $prix Prix plein du produit (fallback).
 * @return float
 */
function gacct_demande_acompte( $product_id, $prix ) {
	$acompte = get_post_meta( absint( $product_id ), '_kojito_montant_acompte', true );

	if ( '' !== $acompte && is_numeric( $acompte ) && (float) $acompte >= 0 ) {
		return (float) $acompte;
	}

	return (float) $prix;
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
