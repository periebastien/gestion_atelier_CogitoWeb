<?php
/**
 * AEROTECH — page Contact (Elementor natif + Elementor Pro Form).
 * Maquette : projet Design, templates/contact/Contact.dc.html
 * Handoff  : handoff/prompt-claude-code-contact-panier-checkout.md §1 à §4bis
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** La page Contact (slug « contact »). */
function at_is_contact_page() {
	return is_page( 'contact' );
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

add_action( 'elementor_pro/forms/validation', function ( $record, $handler ) {
	if ( 'Contact AEROTECH' !== $record->get_form_settings( 'form_name' ) ) { return; }
	// phpcs:ignore WordPress.Security.NonceVerification
	$raw = isset( $_POST['at_ts'] ) ? sanitize_text_field( wp_unslash( $_POST['at_ts'] ) ) : '';
	if ( '' === $raw ) { return; } // champ absent (JS désactivé) → on laisse passer
	$parts = explode( '.', $raw, 2 );
	$t     = isset( $parts[0] ) ? (int) $parts[0] : 0;
	$sig   = $parts[1] ?? '';
	// jeton illisible ou trafiqué = soumission automatisée
	if ( ! $t || ! hash_equals( wp_hash( $t . '|at_contact_ts' ), $sig ) ) {
		$handler->add_error( 'at_ts', "Votre message n'a pas pu être vérifié. Rechargez la page et réessayez." );
		return;
	}
	if ( ( time() - $t ) < 3 ) {
		// même API que le honeypot natif (classes/honeypot-handler.php) : add_error()
		$handler->add_error( 'at_ts', 'Votre message est parti trop vite pour être traité. Réessayez dans quelques secondes.' );
	}
}, 10, 2 );

/* ---------------------------------------------------------------------------
 * Routage de l'e-mail interne selon l'objet de la demande + objet normalisé
 * « [AEROTECH] <objet> — <prénom nom> ».
 * Les adresses sont filtrables : add_filter( 'at_contact_routing', … ).
 * ------------------------------------------------------------------------- */
function at_contact_routing() {
	$default = 'pierreyves@fly-aerotech.com';
	return apply_filters( 'at_contact_routing', array(
		'Baptême biplace · réserver un vol'       => $default,
		'Stage de pilotage · s\'inscrire'          => $default,
		'Atelier · révision ou réparation'         => $default,
		'Magasin · matériel, commande, SAV'        => $default,
		'Bureau d\'étude · projet R&D'             => $default,
		'Bon cadeau'                               => $default,
		'Autre demande'                            => $default,
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
		'email'     => 'pierreyves@fly-aerotech.com',
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
