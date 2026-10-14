<?php
/**
 * AEROTECH — page Contact (Elementor natif + Elementor Pro Form).
 * Maquette : projet Design, templates/contact/Contact.dc.html
 * Handoff  : handoff/prompt-claude-code-contact-panier-checkout.md §1 à §4bis
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** La page Contact (slug « contact »). */
function at_is_contact_page() {
	return is_page( array( 'contact', 'contact-us' ) );
}

/* ---------------------------------------------------------------------------
 * Feuille de style + URL des icônes du sprite utilisées en masques CSS
 * (pastilles de préférence, chevron du select, icône d'erreur…).
 * ------------------------------------------------------------------------- */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! at_is_contact_page() ) { return; }

	$path = get_stylesheet_directory() . '/assets/at-contact.css';
	wp_enqueue_style( 'at-contact', get_stylesheet_directory_uri() . '/assets/at-contact.css', array(), file_exists( $path ) ? filemtime( $path ) : '1' );

	$vars = array();
	foreach ( array( 'chevron-down' => 'chevron', 'mail' => 'mail', 'phone' => 'phone', 'whatsapp' => 'whatsapp', 'map-pin' => 'map-pin', 'alert-circle' => 'alert' ) as $icon => $var ) {
		$p = get_posts( array( 'post_type' => 'attachment', 'name' => 'at-icon-at-' . $icon, 'posts_per_page' => 1, 'post_status' => 'inherit' ) );
		if ( $p ) {
			$vars[] = '--at-' . $var . ':url(' . esc_url( wp_get_attachment_url( $p[0]->ID ) ) . ')';
		}
	}
	if ( $vars ) {
		wp_add_inline_style( 'at-contact', '.at-ct{' . implode( ';', $vars ) . '}' );
	}
}, 21 );

/* ---------------------------------------------------------------------------
 * Labels flottants : le motif CSS repose sur `:not(:placeholder-shown)`, donc
 * sur un placeholder non vide. On le force ici plutôt que de compter sur la
 * valeur « une espace » saisie dans les réglages du champ (que l'éditeur peut
 * retrimer à la prochaine sauvegarde).
 * ------------------------------------------------------------------------- */
add_filter( 'elementor_pro/forms/render/item', function ( $item, $index, $form ) {
	if ( ! at_is_contact_page() ) { return $item; }
	if ( in_array( $item['field_type'], array( 'text', 'email', 'tel', 'url', 'number', 'textarea', 'password' ), true ) ) {
		if ( '' === trim( (string) ( $item['placeholder'] ?? '' ) ) ) {
			$item['placeholder'] = ' ';
		}
	}
	return $item;
}, 10, 3 );

/* ---------------------------------------------------------------------------
 * Anti-spam sans captcha : honeypot (champ natif du formulaire) + time-trap.
 * Rejet si le formulaire est renvoyé moins de 3 s après son affichage.
 * ------------------------------------------------------------------------- */
add_action( 'wp_footer', function () {
	if ( ! at_is_contact_page() ) { return; }
	// Horodatage SERVEUR signé : comparer l'horloge du visiteur à celle du serveur
	// ne marche pas (elles dérivent — constaté : 95 s d'écart en recette).
	$t     = time();
	$token = $t . '.' . wp_hash( $t . '|at_contact_ts' );
	?>
<script>(function(){var f=document.querySelector('.at-ctf form');if(!f)return;var i=document.createElement('input');i.type='hidden';i.name='at_ts';i.value=<?php echo wp_json_encode( $token ); ?>;f.appendChild(i);})();</script>
	<?php
}, 30 );

/* ---------------------------------------------------------------------------
 * Anti-spam renforcé (14/10/2026). Constat : 173 des 179 envois de septembre
 * venaient d'un même robot (80.94.95.173, « Robertriday … your price » en vingt
 * langues) qui postait directement sur admin-ajax, sans page d'origine ni
 * JavaScript : le jeton absent était laissé passer. Désormais, dans l'ordre :
 *  1. jeton signé obligatoire (posé en JS par la page), âgé de 4 s à 12 h ;
 *  2. envoi depuis une page du site (en-tête Referer du même domaine) ;
 *  3. objet choisi dans la liste du formulaire (le robot envoyait des variantes) ;
 *  4. limites par IP : 3 envois / 10 min, 8 / 24 h ;
 *  5. contenu : liens (2 et plus dans le message, ou un seul dans le nom),
 *     écriture majoritairement non latine (site FR/EN), prénom = nom.
 * Chaque rejet est journalisé (option at_contact_spam_log, 300 derniers),
 * consultable avec : wp option get at_contact_spam_log --format=json
 * Messages en français ou en anglais selon la page (formulaire EN : 553).
 * ------------------------------------------------------------------------- */
function at_contact_is_en() {
	$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
	return false !== strpos( $ref, '/en/' );
}

function at_contact_msg( $cle ) {
	$en  = at_contact_is_en();
	$tel = '06 20 89 91 31';
	$m   = array(
		'verif'  => $en ? 'Your message could not be verified. Please reload the page and try again, or call us on ' . $tel . '.' : "Votre message n'a pas pu être vérifié. Rechargez la page et réessayez, ou appelez-nous au " . $tel . '.',
		'vite'   => $en ? 'Your message was sent too quickly to be processed. Please try again in a few seconds.' : 'Votre message est parti trop vite pour être traité. Réessayez dans quelques secondes.',
		'ancien' => $en ? 'This page has been open for too long. Please reload it and send your message again.' : 'Cette page est ouverte depuis trop longtemps. Rechargez-la et renvoyez votre message.',
		'objet'  => $en ? 'Please choose the subject of your request in the list.' : "Choisissez l'objet de votre demande dans la liste.",
		'trop'   => $en ? 'You have sent several messages in a short time. Please wait a little, or call us on ' . $tel . '.' : 'Vous avez envoyé plusieurs messages en peu de temps. Patientez un peu, ou appelez-nous au ' . $tel . '.',
		'liens'  => $en ? 'For security reasons, links are not accepted in this form. Please describe your request without links.' : "Par sécurité, les liens ne sont pas acceptés dans ce formulaire. Décrivez votre demande sans lien.",
		'langue' => $en ? 'Please write your message in English or French.' : 'Merci de rédiger votre message en français ou en anglais.',
	);
	return $m[ $cle ];
}

function at_contact_spam_log( $raison, $champs = array() ) {
	$log   = get_option( 'at_contact_spam_log', array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array(
		'date'   => current_time( 'mysql' ),
		'ip'     => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		'raison' => $raison,
		'email'  => isset( $champs['email'] ) ? sanitize_email( $champs['email'] ) : '',
		'extrait'=> isset( $champs['message'] ) ? mb_substr( sanitize_text_field( $champs['message'] ), 0, 80 ) : '',
	);
	update_option( 'at_contact_spam_log', array_slice( $log, -300 ), false );
}

/** Part de lettres non latines dans un texte (0 à 1). */
function at_contact_part_non_latine( $texte ) {
	$lettres = preg_match_all( '/\p{L}/u', (string) $texte );
	if ( ! $lettres ) {
		return 0.0;
	}
	$latines = preg_match_all( '/\p{Latin}/u', (string) $texte );
	return 1 - ( $latines / $lettres );
}

add_action( 'elementor_pro/forms/validation', function ( $record, $handler ) {
	if ( 'Contact AEROTECH' !== $record->get_form_settings( 'form_name' ) ) { return; }

	$f = array();
	foreach ( (array) $record->get( 'fields' ) as $id => $champ ) {
		$f[ $id ] = is_array( $champ['value'] ?? '' ) ? implode( ' ', $champ['value'] ) : (string) ( $champ['value'] ?? '' );
	}
	$rejet = function ( $raison, $cle, $champ = 'at_ts' ) use ( $handler, $f ) {
		at_contact_spam_log( $raison, $f );
		// même API que le honeypot natif (classes/honeypot-handler.php) : add_error()
		// Sous le champ concerné quand il y en a un (le visiteur sait quoi corriger).
		$handler->add_error( $champ, at_contact_msg( $cle ) );
	};

	// 1. Jeton signé obligatoire, entre 4 s et 12 h.
	// phpcs:ignore WordPress.Security.NonceVerification
	$raw   = isset( $_POST['at_ts'] ) ? sanitize_text_field( wp_unslash( $_POST['at_ts'] ) ) : '';
	$parts = explode( '.', $raw, 2 );
	$t     = isset( $parts[0] ) ? (int) $parts[0] : 0;
	$sig   = $parts[1] ?? '';
	if ( '' === $raw || ! $t || ! hash_equals( wp_hash( $t . '|at_contact_ts' ), $sig ) ) {
		$rejet( '' === $raw ? 'jeton absent' : 'jeton invalide', 'verif' );
		return;
	}
	$age = time() - $t;
	if ( $age < 4 ) {
		$rejet( 'trop rapide (' . $age . ' s)', 'vite' );
		return;
	}
	if ( $age > 12 * HOUR_IN_SECONDS ) {
		$rejet( 'page trop ancienne', 'ancien' );
		return;
	}

	// 2. Envoi depuis une page du site.
	$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
	if ( '' === $ref || wp_parse_url( $ref, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		$rejet( 'origine absente ou externe', 'verif' );
		return;
	}

	// 3. Objet choisi dans la liste du formulaire.
	if ( isset( $f['objet'] ) ) {
		$options = array();
		foreach ( (array) $record->get_form_settings( 'form_fields' ) as $def ) {
			if ( 'objet' === ( $def['custom_id'] ?? '' ) ) {
				foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $def['field_options'] ?? '' ) ) as $ligne ) {
					$ligne = trim( $ligne );
					if ( '' !== $ligne ) {
						$val       = false !== strpos( $ligne, '|' ) ? trim( substr( $ligne, strpos( $ligne, '|' ) + 1 ) ) : $ligne;
						$options[] = html_entity_decode( $val, ENT_QUOTES, 'UTF-8' );
					}
				}
			}
		}
		if ( $options && ! in_array( html_entity_decode( $f['objet'], ENT_QUOTES, 'UTF-8' ), $options, true ) ) {
			$rejet( 'objet hors liste', 'objet', 'objet' );
			return;
		}
	}

	// 4. Limites par IP.
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( $ip ) {
		$k10 = 'at_ct_ip10_' . md5( $ip );
		$k24 = 'at_ct_ip24_' . md5( $ip );
		$n10 = (int) get_transient( $k10 );
		$n24 = (int) get_transient( $k24 );
		if ( $n10 >= 3 || $n24 >= 8 ) {
			$rejet( 'trop d’envois depuis cette IP', 'trop' );
			return;
		}
	}

	// 5. Contenu.
	$message = $f['message'] ?? '';
	$nom     = trim( ( $f['prenom'] ?? '' ) . ' ' . ( $f['nom'] ?? '' ) );
	$re_lien = '~(https?://|www\.|\[url|<a\s)~i';
	if ( preg_match_all( $re_lien, $message ) >= 2 || preg_match( $re_lien, $nom ) ) {
		$rejet( 'liens', 'liens', 'message' );
		return;
	}
	if ( at_contact_part_non_latine( $message . ' ' . $nom ) > 0.4 ) {
		$rejet( 'écriture non latine', 'langue', 'message' );
		return;
	}
	$prenom = mb_strtolower( trim( $f['prenom'] ?? '' ) );
	if ( mb_strlen( $prenom ) >= 4 && $prenom === mb_strtolower( trim( $f['nom'] ?? '' ) ) ) {
		$rejet( 'prénom identique au nom', 'verif' );
		return;
	}

}, 10, 2 );

// Les limites par IP ne comptent que les envois réellement acceptés (après le
// reCAPTCHA et toutes les validations) : new_record ne part qu'en cas de succès.
add_action( 'elementor_pro/forms/new_record', function ( $record ) {
	if ( 'Contact AEROTECH' !== $record->get_form_settings( 'form_name' ) ) { return; }
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( '' === $ip ) { return; }
	$k10 = 'at_ct_ip10_' . md5( $ip );
	$k24 = 'at_ct_ip24_' . md5( $ip );
	set_transient( $k10, (int) get_transient( $k10 ) + 1, 10 * MINUTE_IN_SECONDS );
	set_transient( $k24, (int) get_transient( $k24 ) + 1, DAY_IN_SECONDS );
}, 5 );

/* ---------------------------------------------------------------------------
 * Routage de l'e-mail interne selon l'objet de la demande + objet normalisé
 * « [AEROTECH] <objet> — <prénom nom> ».
 * Les adresses sont filtrables : add_filter( 'at_contact_routing', … ).
 * ------------------------------------------------------------------------- */
function at_contact_routing() {
	// Alias créés par Pierre-Yves (e-mail du 13/09/2026) : info@ (général), atelier@ (révisions), shop@ (boutique).
	$info    = 'info@fly-aerotech.com';
	$atelier = 'atelier@fly-aerotech.com';
	$shop    = 'shop@fly-aerotech.com';
	return apply_filters( 'at_contact_routing', array(
		'Baptême biplace · réserver un vol'       => $info,
		'Stage de pilotage · s\'inscrire'          => $info,
		'Atelier · révision ou réparation'         => $atelier,
		'Magasin · matériel, commande, SAV'        => $shop,
		'Bureau d\'étude · projet R&D'             => $info,
		'Bon cadeau'                               => $info,
		'Autre demande'                            => $info,
		// Objets du formulaire EN (page 553), memes destinataires.
		'Workshop · service or repair'             => $atelier,
		'Shop · equipment, order, after-sales'     => $shop,
	) );
}

/* Consigne de rappel : la préférence de contact et l'indicateur WhatsApp sont
   ajoutés comme champ du formulaire, donc repris par [all-fields] dans l'e-mail
   interne et enregistrés avec la soumission.
   ⚠️ Accroché à `validation`, pas à `new_record` : ce dernier se déclenche APRÈS
   l'exécution des actions (cf. ajax-handler.php) — le champ arriverait trop tard
   pour l'e-mail. */
add_action( 'elementor_pro/forms/validation', function ( $record, $handler ) {
	if ( 'Contact AEROTECH' !== $record->get_form_settings( 'form_name' ) ) { return; }
	$f = array();
	foreach ( $record->get( 'fields' ) as $id => $field ) {
		$f[ $id ] = $field['value'];
	}
	$pref = $f['preference'] ?? 'email';
	$tel  = $f['tel'] ?? '';
	$wa   = ! empty( $f['wa_num'] );

	$consigne = 'Rappel souhaité par e-mail.';
	if ( 'tel' === $pref && $tel ) {
		$consigne = 'Rappel souhaité par téléphone au ' . $tel . '.';
	} elseif ( 'whatsapp' === $pref && $tel ) {
		$consigne = 'Rappel souhaité par WhatsApp au ' . $tel . '.';
	}
	if ( $wa && $tel ) {
		$consigne .= ' Ce numéro est déclaré sur WhatsApp.';
	}
	// Stocké avec la soumission (visible dans Elementor → Envois, et exporté en CSV).
	$fields = (array) $record->get( 'fields' );
	$fields['consigne_rappel'] = array(
		'id'        => 'consigne_rappel',
		'type'      => 'text',
		'title'     => 'Consigne de rappel',
		'value'     => $consigne,
		'raw_value' => $consigne,
		'required'  => false,
	);
	$record->set( 'fields', $fields );

	/* Destinataire et objet de l'e-mail interne, selon l'objet de la demande.
	   ⚠️ L'action « email » lit `$record->get('form_settings')` au moment de son
	   exécution (actions/email.php::run) : il n'existe pas de filtre sur ses
	   réglages, on réécrit donc les réglages du record ici. L'accusé de réception
	   (email2) lit `email_to_2`/`email_subject_2` : il n'est pas affecté. */
	$objet    = $f['objet'] ?? 'Autre demande';
	$nom      = trim( ( $f['prenom'] ?? '' ) . ' ' . ( $f['nom'] ?? '' ) );
	$routes   = at_contact_routing();
	$settings = (array) $record->get( 'form_settings' );

	if ( ! empty( $routes[ $objet ] ) ) {
		$settings['email_to'] = $routes[ $objet ];
	}
	$settings['email_subject'] = '[AEROTECH] ' . $objet . ( $nom ? ' · ' . $nom : '' );
	$record->set( 'form_settings', $settings );
}, 20, 2 );

/* ---------------------------------------------------------------------------
 * Horaires de l'atelier — source unique pour le JSON-LD.
 * L'AFFICHAGE est un widget texte de la page (éditable dans Elementor par
 * l'utilisateur) ; ce tableau alimente `openingHours` et se filtre par code.
 * ------------------------------------------------------------------------- */
function at_contact_hours() {
	return apply_filters( 'at_contact_hours', array(
		array( 'Tu,We,Th,Fr', '09:00', '12:00' ),
		array( 'Tu,We,Th,Fr', '14:00', '18:00' ),
		array( 'Sa', '09:00', '17:00' ),
	) );
}

/* ---------------------------------------------------------------------------
 * SEO : titre + JSON-LD LocalBusiness. (Le FAQPage est produit nativement par
 * le widget accordéon, réglage `faq_schema`.)
 * ------------------------------------------------------------------------- */
add_filter( 'document_title_parts', function ( $parts ) {
	if ( at_is_contact_page() ) {
		$parts['title'] = 'Contact · AEROTECH parapente, Vence & Gréolières';
	}
	return $parts;
} );

add_action( 'wp_head', function () {
	if ( ! at_is_contact_page() ) { return; }

	$spec = array();
	foreach ( at_contact_hours() as $h ) {
		$spec[] = array(
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => array_map(
				function ( $d ) {
					$map = array( 'Mo' => 'Monday', 'Tu' => 'Tuesday', 'We' => 'Wednesday', 'Th' => 'Thursday', 'Fr' => 'Friday', 'Sa' => 'Saturday', 'Su' => 'Sunday' );
					return $map[ $d ] ?? $d;
				},
				explode( ',', $h[0] )
			),
			'opens'     => $h[1],
			'closes'    => $h[2],
		);
	}

	$data = array(
		'@context'  => 'https://schema.org',
		'@type'     => 'LocalBusiness',
		'name'      => 'AEROTECH',
		'legalName' => 'AEROTECH SAS',
		'url'       => home_url( '/' ),
		'telephone' => '+33620899131',
		'email'     => 'info@fly-aerotech.com',
		'address'   => array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => '489 route de Grasse',
			'postalCode'      => '06140',
			'addressLocality' => 'Vence',
			'addressCountry'  => 'FR',
		),
		'geo' => array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => 43.7182,
			'longitude' => 7.1032,
		),
		'openingHoursSpecification' => $spec,
		'sameAs' => array_values( array_filter( (array) apply_filters( 'at_contact_social', array() ) ) ),
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 5 );
