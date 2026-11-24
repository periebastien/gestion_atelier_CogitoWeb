<?php
/**
 * Pages club personnalisées /club/<slug>/ (prospection des clubs FFVL, 23/11/2026).
 *
 * Une page par club, jamais indexée : X-Robots-Tag + meta robots noindex,nofollow,
 * pas de canonique, hors sitemap (route maison, aucun post). La route sert la page
 * /commande-groupee/ et lui ajoute en tête un bloc propre au club (héros, chiffres
 * de l'atelier pour son secteur, simulateur de remise, mot d'Hervé) ; le héros
 * générique de la page est masqué.
 *
 * Données : table {prefix}ar_club_pages (une ligne par club, JSON calculé hors
 * WordPress à partir du fichier FFVL et de chiffres AGRÉGÉS de l'historique :
 * aucun nom de pilote, aucune coordonnée de contact).
 * Visites : table {prefix}ar_club_vues, écrite par un beacon JS (les robots qui
 * ouvrent les liens des e-mails sans exécuter de JS ne comptent pas).
 *
 * Import : wp ar-clubs import <fichier.json>   Liste : wp ar-clubs vues
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const AR_CLUBP_VERSION = '1';

function ar_clubp_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . ( 'vues' === $name ? 'ar_club_vues' : 'ar_club_pages' );
}

/* ---- Installation : tables + règle de réécriture ------------------------- */

add_action( 'init', 'ar_clubp_rewrite' );
function ar_clubp_rewrite() {
	add_rewrite_rule( '^club/([a-z0-9-]+)/?$', 'index.php?pagename=commande-groupee&ar_club=$matches[1]', 'top' );
	if ( get_option( 'ar_clubp_version' ) !== AR_CLUBP_VERSION ) {
		ar_clubp_install();
		flush_rewrite_rules( false );
		update_option( 'ar_clubp_version', AR_CLUBP_VERSION, false );
	}
}

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'ar_club';
	return $vars;
} );

function ar_clubp_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$cs = $wpdb->get_charset_collate();
	dbDelta( 'CREATE TABLE ' . ar_clubp_table( 'pages' ) . " (
		slug varchar(80) NOT NULL,
		nom varchar(190) NOT NULL DEFAULT '',
		donnees longtext NOT NULL,
		maj datetime NOT NULL,
		PRIMARY KEY  (slug)
	) $cs;" );
	dbDelta( 'CREATE TABLE ' . ar_clubp_table( 'vues' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		slug varchar(80) NOT NULL,
		evenement varchar(20) NOT NULL DEFAULT 'vue',
		mobile tinyint(1) NOT NULL DEFAULT 0,
		interne tinyint(1) NOT NULL DEFAULT 0,
		cree datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY slug (slug)
	) $cs;" );
}

/* ---- Lecture ------------------------------------------------------------- */

function ar_clubp_get( $slug ) {
	global $wpdb;
	static $cache = array();
	$slug = sanitize_title( $slug );
	if ( ! array_key_exists( $slug, $cache ) ) {
		$json           = $wpdb->get_var( $wpdb->prepare( 'SELECT donnees FROM ' . ar_clubp_table( 'pages' ) . ' WHERE slug = %s', $slug ) );
		$cache[ $slug ] = $json ? json_decode( $json, true ) : null;
	}
	return $cache[ $slug ];
}

function ar_clubp_current() {
	$slug = get_query_var( 'ar_club' );
	return $slug ? ar_clubp_get( $slug ) : null;
}

/** Réglages de la page (filtrables). */
function ar_clubp_reglages() {
	return apply_filters( 'ar_clubp_reglages', array(
		'prix_revision' => 180,          // Révision périodique ParachecK, produit 18.
		'paliers'       => array( 5 => 10, 10 => 12, 20 => 15 ),
		'atelier_ville' => 'Clécy',
		'tel'           => '02 31 69 39 31',
		'tel_lien'      => '+33231693931',
		'mobile'        => '06 30 18 01 79',
		'mobile_lien'   => '+33630180179',
		'signature'     => 'Hervé',
	) );
}

/* ---- Routage : 404 si club inconnu, noindex sinon ------------------------ */

add_action( 'template_redirect', function () {
	$slug = get_query_var( 'ar_club' );
	if ( ! $slug ) {
		return;
	}
	if ( ! ar_clubp_get( $slug ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		return;
	}
	header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
}, 1 );

// L'URL /club/x/ sert la page /commande-groupee/ : WordPress ne doit pas rediriger.
add_filter( 'redirect_canonical', function ( $redirect ) {
	return get_query_var( 'ar_club' ) ? false : $redirect;
} );

add_filter( 'wp_robots', function ( $robots ) {
	if ( ar_clubp_current() ) {
		return array( 'noindex' => true, 'nofollow' => true, 'noarchive' => true );
	}
	return $robots;
}, 99 );

add_filter( 'wpseo_robots', function ( $robots ) {
	return ar_clubp_current() ? 'noindex, nofollow, noarchive' : $robots;
}, 99 );
add_filter( 'wpseo_canonical', function ( $c ) {
	return ar_clubp_current() ? false : $c;
}, 99 );
add_filter( 'wpseo_opengraph_url', function ( $u ) {
	return ar_clubp_current() ? false : $u;
}, 99 );
add_filter( 'wpseo_title', 'ar_clubp_title', 99 );
add_filter( 'wpseo_opengraph_title', 'ar_clubp_title', 99 );
add_filter( 'pre_get_document_title', 'ar_clubp_title', 99 );
function ar_clubp_title( $title ) {
	$c = ar_clubp_current();
	return $c ? 'Révision groupée des voiles ' . ar_clubp_de_nom( $c['nom'], true ) . ar_clubp_de_nom( $c['nom'], false ) : $title;
}

add_filter( 'body_class', function ( $classes ) {
	if ( ar_clubp_current() ) {
		$classes[] = 'ar-clubp';
	}
	return $classes;
} );

add_action( 'wp_enqueue_scripts', function () {
	$c = ar_clubp_current();
	if ( ! $c ) {
		return;
	}
	$css = GACCT_PACK_AR_DIR . '/assets/css/club-page.css';
	$js  = GACCT_PACK_AR_DIR . '/assets/js/club-page.js';
	wp_enqueue_style( 'ar-club-page', GACCT_PACK_AR_URL . 'assets/css/club-page.css', array(), (string) filemtime( $css ) );
	wp_enqueue_script( 'ar-club-page', GACCT_PACK_AR_URL . 'assets/js/club-page.js', array(), (string) filemtime( $js ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	$r = ar_clubp_reglages();
	wp_localize_script( 'ar-club-page', 'arClubPage', array(
		'slug'    => $c['slug'],
		'ajax'    => admin_url( 'admin-ajax.php' ),
		'prix'    => (float) $r['prix_revision'],
		'paliers' => $r['paliers'],
	) );
} );

/* ---- Bloc personnalisé, placé avant le contenu de la page ---------------- */

add_filter( 'the_content', function ( $content ) {
	static $fait = false;
	$c = ar_clubp_current();
	if ( $fait || ! $c || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$fait = true;
	ob_start();
	ar_clubp_render( $c );
	return ob_get_clean() . $content;
}, 999 );

function ar_clubp_nf( $n ) {
	return number_format_i18n( (int) $n );
}

/** Phrases du bloc, selon ce qu'on sait du club. Chaque chiffre affiché est vrai et agrégé. */
function ar_clubp_render( array $c ) {
	$r      = ar_clubp_reglages();
	$s      = isset( $c['stats'] ) ? $c['stats'] : array();
	$nom    = (string) $c['nom'];
	$ville  = (string) $c['ville'];
	$dep    = (string) $c['dep_nom'];
	$ecole  = 'club-ecole' === $c['type'];
	$km     = isset( $c['km_atelier'] ) ? (int) $c['km_atelier'] : 0;
	$proche = $km && $km <= 160;
	$p_dep  = (int) ( $s['pilotes_dep'] ?? 0 );
	$p_30   = (int) ( $s['pilotes_30km'] ?? 0 );
	$p_50   = (int) ( $s['pilotes_50km'] ?? 0 );
	$r_25   = (int) ( $s['revisions_dep_2025'] ?? 0 );
	$marq   = array_values( array_filter( (array) ( $s['marques_dep'] ?? array() ) ) );

	// Cartes chiffrées : seulement celles qui ont du sens (rien sous 5).
	$cartes = array();
	if ( $p_dep >= 5 ) {
		$cartes[] = array( ar_clubp_nf( $p_dep ), sprintf( 'pilotes %s nous ont confié leur voile depuis 2019', ar_clubp_de_dep( $dep ) ) );
	}
	if ( $p_30 >= 5 ) {
		$cartes[] = array( ar_clubp_nf( $p_30 ), sprintf( 'de nos pilotes habitent à moins de 30 km de %s', $ville ) );
	} elseif ( $p_50 >= 5 ) {
		$cartes[] = array( ar_clubp_nf( $p_50 ), sprintf( 'de nos pilotes habitent à moins de 50 km de %s', $ville ) );
	}
	if ( $r_25 >= 5 ) {
		$cartes[] = array( ar_clubp_nf( $r_25 ), sprintf( 'révisions pour des pilotes %s depuis janvier 2025', ar_clubp_de_dep( $dep ) ) );
	}
	if ( $km ) {
		$cartes[] = array( ar_clubp_nf( $km ) . ' km', sprintf( 'à vol d\'oiseau entre %s et l\'atelier de %s', $ville, $r['atelier_ville'] ) );
	}
	$cartes = array_slice( $cartes, 0, 4 );

	$tel = '<a href="tel:' . esc_attr( $r['tel_lien'] ) . '" data-ar-clubp="tel">' . esc_html( $r['tel'] ) . '</a>';
	if ( $ecole ) {
		$mot = 'Avec un parc de voiles d\'école, le plus simple est de tout faire réviser d\'un coup, avant la reprise des stages. On réserve les jours qu\'il faut à l\'atelier rien que pour vous, et chaque voile garde son rapport ParachecK.';
	} elseif ( $p_dep >= 20 ) {
		$mot = 'Plusieurs de vos pilotes nous ont sans doute déjà confié leur voile. Plutôt que chacun de son côté, je vous propose de grouper les révisions du club : un créneau rien que pour vous, une seule commande à régler par le club, et une remise pour tout le monde.';
	} else {
		$mot = 'On ne se connaît pas encore, mais on reçoit des voiles de toute la France. Pour un club, grouper les révisions simplifie tout : un seul envoi, un créneau rien que pour vous, une seule commande à régler par le club, et une remise pour tout le monde.';
	}
	$mot = str_replace( ' :', "\u{00A0}:", $mot );
	?>
	<div class="ar-clubp-wrap">
		<section class="ar-clubp-hero">
			<div class="ar-clubp-in ar-clubp-hero-grid">
				<div class="ar-clubp-hero-txt">
					<p class="ar-clubp-eyebrow"><?php echo esc_html( 'Page préparée pour ' . $nom ); ?></p>
					<h1 class="ar-clubp-h1">
						<?php if ( $ecole ) : ?>
							Le matériel <?php echo esc_html( ar_clubp_de_nom( $nom, true ) ); ?><em><?php echo esc_html( ar_clubp_de_nom( $nom, false ) ); ?></em>, révisé en une seule fois
						<?php else : ?>
							Les voiles <?php echo esc_html( ar_clubp_de_nom( $nom, true ) ); ?><em><?php echo esc_html( ar_clubp_de_nom( $nom, false ) ); ?></em>, révisées en une seule fois
						<?php endif; ?>
					</h1>
					<div class="ar-clubp-mot-carte">
						<p class="ar-clubp-mot-txt">Bonjour à toute l'équipe <?php echo esc_html( ar_clubp_de_nom( $nom, true ) . ar_clubp_de_nom( $nom, false ) ); ?>,</p>
						<p class="ar-clubp-mot-txt"><?php echo esc_html( $mot ); ?></p>
						<p class="ar-clubp-mot-txt">Le plus simple, c'est d'en parler&nbsp;: appelez-moi au <?php echo $tel; // phpcs:ignore -- échappé plus haut. ?>, ou faites votre demande en bas de page et je vous rappelle.</p>
						<p class="ar-clubp-mot-sign"><?php echo esc_html( $r['signature'] ); ?><span>Plaine Altitude et Altitude Révision, atelier de <?php echo esc_html( $r['atelier_ville'] ); ?></span></p>
					</div>
				</div>

				<div class="ar-clubp-sim" aria-labelledby="arClubpSimTitre">
					<p class="ar-clubp-sim-eyebrow">Simulateur de remise</p>
					<h2 class="ar-clubp-sim-titre" id="arClubpSimTitre">Combien de voiles au club cette année&nbsp;?</h2>
					<label class="ar-clubp-sim-label" for="arClubpVoiles">
						<output id="arClubpNb" for="arClubpVoiles">10</output> voiles à réviser
					</label>
					<input type="range" id="arClubpVoiles" min="1" max="40" step="1" value="10" aria-valuetext="10 voiles">
					<div class="ar-clubp-sim-res" aria-live="polite">
						<div class="ar-clubp-sim-pct"><span id="arClubpPct">12</span>&nbsp;%</div>
						<div class="ar-clubp-sim-detail">
							<span id="arClubpLigne1">de remise sur chaque révision</span>
							<strong id="arClubpLigne2">soit environ 216 € d'économie pour le club</strong>
						</div>
					</div>
					<a class="ar-clubp-btn" href="#demande-club" data-ar-clubp="cta">Réserver pour <?php echo esc_html( $nom ); ?></a>
					<p class="ar-clubp-cta-tel">ou appelez Hervé au <?php echo $tel; // phpcs:ignore ?></p>
					<p class="ar-clubp-sim-note">Estimation sur la révision ParachecK à <?php echo esc_html( (string) $r['prix_revision'] ); ?>&nbsp;€, hors suppléments, réparations et port. Les secours ne comptent pas dans le palier.</p>
				</div>
			</div>
		</section>

		<?php if ( $cartes ) : ?>
		<section class="ar-clubp-chiffres">
			<div class="ar-clubp-in">
				<p class="ar-clubp-eyebrow"><?php echo esc_html( $proche ? 'On se connaît déjà un peu' : 'Votre club et l\'atelier' ); ?></p>
				<h2 class="ar-clubp-h2">
					<?php if ( $p_dep >= 20 ) : ?>
						Les pilotes <?php echo esc_html( ar_clubp_de_dep( $dep ) ); ?> <em>connaissent déjà l'atelier</em>
					<?php elseif ( $p_dep >= 5 ) : ?>
						Des pilotes <?php echo esc_html( ar_clubp_de_dep( $dep, false ) ); ?> nous font <em>déjà confiance</em>
					<?php else : ?>
						Loin de Clécy, <em>aussi simple qu'à côté</em>
					<?php endif; ?>
				</h2>
				<div class="ar-clubp-cartes">
					<?php foreach ( $cartes as $carte ) : ?>
						<div class="ar-clubp-carte">
							<span class="ar-clubp-carte-num"><?php echo esc_html( $carte[0] ); ?></span>
							<span class="ar-clubp-carte-lbl"><?php echo esc_html( $carte[1] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( count( $marq ) >= 2 ) : ?>
					<p class="ar-clubp-marques">
						Les voiles <?php echo esc_html( ar_clubp_de_dep( $dep, false ) ); ?> qu'on voit le plus passer à l'atelier :
						<strong><?php echo esc_html( ar_clubp_liste( $marq ) ); ?></strong>. Vos pilotes ont de bonnes chances d'en avoir une.
					</p>
				<?php endif; ?>
				<p class="ar-clubp-acces">
					<?php if ( $km && $km <= 60 ) : ?>
						Vous êtes tout près : vous pouvez déposer les voiles du club à l'atelier de <?php echo esc_html( $r['atelier_ville'] ); ?> et les reprendre ensemble.
					<?php elseif ( $proche ) : ?>
						Déposez les voiles du club à l'atelier en une fois, ou envoyez-les dans un seul colis : on vous les renvoie ensemble.
					<?php else : ?>
						Un seul colis pour toutes les voiles du club au lieu d'un envoi par pilote, et un seul retour groupé.
					<?php endif; ?>
				</p>
			</div>
		</section>
		<?php endif; ?>

	</div>
	<?php
}

/** « de Seine-Maritime », « du Calvados », « de l'Ain », « des Côtes-d'Armor »… */
function ar_clubp_de_dep( $dep, $avec_de = true ) {
	$d = (string) $dep;
	if ( '' === $d ) {
		return $avec_de ? 'de votre département' : 'de votre département';
	}
	$du  = array( 'Calvados', 'Cantal', 'Cher', 'Doubs', 'Finistère', 'Gard', 'Gers', 'Jura', 'Loir-et-Cher', 'Loiret', 'Lot', 'Lot-et-Garonne', 'Morbihan', 'Nord', 'Pas-de-Calais', 'Puy-de-Dôme', 'Rhône', 'Tarn', 'Tarn-et-Garonne', 'Var', 'Val-de-Marne', 'Val-d\'Oise', 'Gard', 'Haut-Rhin', 'Bas-Rhin', 'Haute-Garonne', 'Haute-Loire', 'Haute-Marne', 'Haute-Saône', 'Haute-Savoie', 'Haute-Vienne', 'Haut-Rhin', 'Territoire de Belfort' );
	$des = array( 'Alpes-de-Haute-Provence', 'Hautes-Alpes', 'Alpes-Maritimes', 'Ardennes', 'Bouches-du-Rhône', 'Côtes-d\'Armor', 'Deux-Sèvres', 'Hautes-Pyrénées', 'Pyrénées-Atlantiques', 'Pyrénées-Orientales', 'Landes', 'Vosges', 'Yvelines', 'Hauts-de-Seine' );
	$fem = array( 'Haute-Garonne', 'Haute-Loire', 'Haute-Marne', 'Haute-Saône', 'Haute-Savoie', 'Haute-Vienne', 'Haute-Corse' );
	if ( in_array( $d, array( 'Ille-et-Vilaine', 'Eure-et-Loir', 'Indre-et-Loire' ), true ) ) {
		return "d'" . $d;
	}
	if ( in_array( $d, $fem, true ) ) {
		return 'de ' . $d;
	}
	if ( in_array( $d, $des, true ) ) {
		return 'des ' . $d;
	}
	if ( in_array( $d, $du, true ) || 0 === strpos( $d, 'Val-' ) ) {
		return 'du ' . $d;
	}
	if ( preg_match( '/^[AEIOUYÉÈÎ]/u', $d ) ) {
		return 'de l\'' . $d;
	}
	return 'de ' . $d;
}

/**
 * « de Fécamp Vol Libre », « des Milans… », « du Club… », « d'Ain Portance ».
 * $prefixe = true rend la préposition seule (avec espace si besoin), false le reste du nom.
 */
function ar_clubp_de_nom( $nom, $prefixe ) {
	$nom = (string) $nom;
	$cas = array( 'Les ' => 'des ', 'Le ' => 'du ', 'La ' => 'de la ', "L'" => "de l'" );
	foreach ( $cas as $art => $de ) {
		if ( 0 === strpos( $nom, $art ) ) {
			return $prefixe ? $de : substr( $nom, strlen( $art ) );
		}
	}
	if ( preg_match( '/^[AEIOUYÉÈÊÎÔ]/u', $nom ) ) {
		return $prefixe ? "d'" : $nom;
	}
	return $prefixe ? 'de ' : $nom;
}

function ar_clubp_liste( array $l ) {
	if ( count( $l ) < 2 ) {
		return (string) reset( $l );
	}
	$der = array_pop( $l );
	return implode( ', ', $l ) . ' et ' . $der;
}

/* ---- Suivi des visites (beacon) ------------------------------------------ */

add_action( 'wp_ajax_ar_club_vue', 'ar_clubp_vue' );
add_action( 'wp_ajax_nopriv_ar_club_vue', 'ar_clubp_vue' );
function ar_clubp_vue() {
	global $wpdb;
	$slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
	$ev   = isset( $_POST['ev'] ) ? sanitize_key( wp_unslash( $_POST['ev'] ) ) : 'vue';
	$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
	if ( ! $slug || ! in_array( $ev, array( 'vue', 'cta', 'tel', 'formulaire' ), true ) || ! ar_clubp_get( $slug ) ) {
		wp_send_json_error( null, 400 );
	}
	if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|preview|scanner|headless|python|curl|wget|facebookexternalhit|proofpoint|mimecast|barracuda/i', $ua ) ) {
		wp_send_json_success();
	}
	// 20 évènements par heure et par IP, au plus.
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	$cle = 'ar_clubp_q_' . md5( $ip . wp_salt() );
	$n   = (int) get_transient( $cle );
	if ( $n >= 20 ) {
		wp_send_json_success();
	}
	set_transient( $cle, $n + 1, HOUR_IN_SECONDS );
	$wpdb->insert( ar_clubp_table( 'vues' ), array(
		'slug'      => $slug,
		'evenement' => $ev,
		'mobile'    => wp_is_mobile() ? 1 : 0,
		'interne'   => current_user_can( 'edit_posts' ) || current_user_can( 'manage_woocommerce' ) ? 1 : 0,
		'cree'      => current_time( 'mysql' ),
	) );
	wp_send_json_success();
}

/* ---- WP-CLI : import des clubs, relevé des visites ----------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'ar-clubs import', function ( $args ) {
		global $wpdb;
		ar_clubp_install();
		$data = json_decode( (string) file_get_contents( $args[0] ), true );
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'JSON illisible.' );
		}
		$n = 0;
		foreach ( $data as $c ) {
			if ( empty( $c['slug'] ) || empty( $c['nom'] ) ) {
				continue;
			}
			$wpdb->replace( ar_clubp_table( 'pages' ), array(
				'slug'    => sanitize_title( $c['slug'] ),
				'nom'     => $c['nom'],
				'donnees' => wp_json_encode( $c ),
				'maj'     => current_time( 'mysql' ),
			) );
			$n++;
		}
		WP_CLI::success( "$n clubs importés." );
	} );

	WP_CLI::add_command( 'ar-clubs vues', function ( $args, $assoc ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT slug, SUM(evenement="vue") AS vues, SUM(evenement="cta") AS cta, SUM(evenement="tel") AS tel,
				SUM(evenement="formulaire") AS formulaire, MIN(cree) AS premiere, MAX(cree) AS derniere
			 FROM ' . ar_clubp_table( 'vues' ) . ' WHERE interne = 0 GROUP BY slug ORDER BY derniere DESC',
			ARRAY_A
		);
		WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'slug', 'vues', 'cta', 'tel', 'formulaire', 'premiere', 'derniere' ) );
	} );
}
