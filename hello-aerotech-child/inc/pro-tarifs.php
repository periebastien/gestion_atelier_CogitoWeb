<?php
/**
 * AEROTECH : pages « Tarifs pro » protégées.
 *
 * Shortcode [at_pro_gate lang="fr" pdf="740"] : demande une adresse e-mail et
 * le mot de passe pro, enregistre l'adresse dans le CCT JetEngine « pro_contacts »,
 * pose un cookie d'accès (30 jours) puis affiche la planche PDF.
 *
 * Mot de passe : option `at_pro_password` (défaut ATCPRO2026, comparaison
 * insensible à la casse). Protection volontairement légère (décision Bastien 15/09/2026).
 *
 * @package hello-aerotech-child
 */

defined( 'ABSPATH' ) || exit;

function at_pro_password() {
	$p = get_option( 'at_pro_password', 'ATCPRO2026' );
	return apply_filters( 'at_pro_password', $p );
}

function at_pro_cookie_name() {
	return 'at_pro_access';
}

function at_pro_cookie_value() {
	return hash_hmac( 'sha256', 'at-pro|' . at_pro_password(), wp_salt( 'auth' ) );
}

function at_pro_has_access() {
	$c = at_pro_cookie_name();
	return ! empty( $_COOKIE[ $c ] ) && hash_equals( at_pro_cookie_value(), (string) $_COOKIE[ $c ] );
}

/**
 * Enregistre l'adresse dans le CCT pro_contacts (table jet_cct_pro_contacts).
 */
function at_pro_record( $email, $lang, $page_id ) {
	if ( ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Module' ) ) {
		return false;
	}
	$type = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->get_content_types( 'pro_contacts' );
	if ( ! $type || empty( $type->db ) ) {
		return false;
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$id = $type->db->insert( array(
		'cct_status'    => 'publish',
		'cct_author_id' => 0,
		'email'         => $email,
		'langue'        => $lang,
		'page_id'       => (int) $page_id,
		'ip'            => $ip,
	) );
	at_pro_notify( $email, $lang, $page_id, $ip );
	return $id;
}

/**
 * Notification interne : un professionnel vient de consulter les tarifs pro.
 * Destinataire filtrable via at_pro_notify_to (defaut info@fly-aerotech.com).
 */
function at_pro_notify( $email, $lang, $page_id, $ip ) {
	$to = apply_filters( 'at_pro_notify_to', 'info@fly-aerotech.com' );
	if ( ! $to ) {
		return;
	}
	$page  = get_the_title( (int) $page_id );
	$date  = wp_date( 'd/m/Y H:i' );
	$lines = array(
		'Un professionnel vient de consulter les tarifs pro sur fly-aerotech.com.',
		'',
		'E-mail : ' . $email,
		'Langue : ' . strtoupper( $lang ),
		'Page : ' . $page,
		'Date : ' . $date,
		'IP : ' . $ip,
		'',
		'La liste complete est dans WordPress > JetEngine > Contacts pros (tarifs).',
	);
	wp_mail(
		$to,
		'[AEROTECH] Tarifs pro consultes par ' . $email,
		implode( "
", $lines ),
		array( 'Reply-To: ' . $email )
	);
}

/**
 * Traitement du formulaire (POST → redirection, motif PRG).
 */
add_action( 'template_redirect', function () {
	if ( empty( $_POST['at_pro_submit'] ) || ! is_singular( 'page' ) ) {
		return;
	}
	if ( ! isset( $_POST['at_pro_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_pro_nonce'] ) ), 'at_pro_gate' ) ) {
		return;
	}
	$email = isset( $_POST['at_pro_email'] ) ? sanitize_email( wp_unslash( $_POST['at_pro_email'] ) ) : '';
	$pass  = isset( $_POST['at_pro_pass'] ) ? trim( (string) wp_unslash( $_POST['at_pro_pass'] ) ) : '';
	$lang  = isset( $_POST['at_pro_lang'] ) ? sanitize_key( wp_unslash( $_POST['at_pro_lang'] ) ) : 'fr';
	$url   = get_permalink();

	if ( ! is_email( $email ) ) {
		wp_safe_redirect( add_query_arg( 'pro', 'email', $url ) );
		exit;
	}
	if ( strcasecmp( $pass, at_pro_password() ) !== 0 ) {
		wp_safe_redirect( add_query_arg( 'pro', 'pass', $url ) );
		exit;
	}
	at_pro_record( $email, $lang, get_queried_object_id() );
	setcookie( at_pro_cookie_name(), at_pro_cookie_value(), array(
		'expires'  => time() + 30 * DAY_IN_SECONDS,
		'path'     => '/',
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	) );
	wp_safe_redirect( remove_query_arg( 'pro', $url ) );
	exit;
} );

/**
 * Pages pro : pas d'indexation (via wp_robots, une seule balise).
 */
function at_pro_is_page() {
	return is_singular( 'page' ) && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'at_pro_gate' );
}
add_filter( 'wp_robots', function ( $robots ) {
	if ( at_pro_is_page() ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset( $robots['max-image-preview'] );
	}
	return $robots;
} );

add_shortcode( 'at_pro_gate', function ( $atts ) {
	$a    = shortcode_atts( array( 'lang' => 'fr', 'pdf' => 0, 'img' => 0 ), $atts, 'at_pro_gate' );
	$lang = 'en' === $a['lang'] ? 'en' : 'fr';
	$pdf  = (int) $a['pdf'];
	$url  = $pdf ? wp_get_attachment_url( $pdf ) : '';
	$img  = (int) $a['img'];
	$t    = 'en' === $lang ? array(
		'intro'   => 'This price list is reserved for professionals, schools and clubs. Enter your e-mail address and the access code you received from AEROTECH.',
		'email'   => 'Professional e-mail address',
		'pass'    => 'Access code',
		'submit'  => 'View the price list',
		'e_email' => 'Please enter a valid e-mail address.',
		'e_pass'  => 'Incorrect access code.',
		'open'    => 'Open the PDF in a new tab',
		'dl'      => 'Download the PDF',
		'contact' => 'For any commercial enquiry: order@fly-aerotech.com',
		'nopdf'   => 'The document is not available yet.',
	) : array(
		'intro'   => 'Cette grille est réservée aux professionnels, écoles et clubs. Indiquez votre adresse e-mail et le code d\'accès transmis par AEROTECH.',
		'email'   => 'Adresse e-mail professionnelle',
		'pass'    => 'Code d\'accès',
		'submit'  => 'Voir la grille tarifaire',
		'e_email' => 'Merci d\'indiquer une adresse e-mail valide.',
		'e_pass'  => 'Code d\'accès incorrect.',
		'open'    => 'Ouvrir le PDF dans un nouvel onglet',
		'dl'      => 'Télécharger le PDF',
		'contact' => 'Pour toute question commerciale : order@fly-aerotech.com',
		'nopdf'   => 'Le document n\'est pas encore disponible.',
	);

	$css = '<style>
.at-pro{max-width:820px;margin:0 auto}
.at-pro-form{background:#F5F7F8;border:1px solid #D8D4CD;border-radius:24px;padding:32px 28px;margin:8px 0 32px}
.at-pro-form p.at-pro-intro{margin:0 0 20px;font-size:16px;line-height:1.6;color:#101820}
.at-pro-field{display:flex;flex-direction:column;gap:6px;margin-bottom:16px}
.at-pro-field label{font:600 14px/1.3 "Nunito Sans",sans-serif;color:#101820}
.at-pro-field input{height:54px;border:1.5px solid #D8D4CD;border-radius:14px;padding:0 16px;font:16px "Nunito Sans",sans-serif;background:#fff;color:#101820}
.at-pro-field input:focus{outline:none;border-color:#EC672C;box-shadow:0 0 0 3px rgba(236,103,44,.18)}
.at-pro-btn{display:inline-flex;align-items:center;justify-content:center;height:54px;padding:0 28px;border:0;border-radius:999px;background:#EC672C;color:#fff;font:700 15px "Nunito Sans",sans-serif;cursor:pointer;transition:background .15s}
.at-pro-btn:hover{background:#D9551B}
.at-pro-err{background:#FDECEC;border:1px solid #F5B5B5;color:#8A1F1F;border-radius:12px;padding:12px 16px;margin:0 0 16px;font-size:14px}
.at-pro-doc{border:1px solid #D8D4CD;border-radius:24px;overflow:hidden;background:#fff;margin:8px 0 20px}
.at-pro-doc img{display:block;width:100%;height:auto}
.at-simple-page .at-pro a.at-pro-btn,.at-simple-page .at-pro a.at-pro-btn:hover,.at-simple-page .at-pro a.at-pro-btn:visited{color:#fff!important;text-decoration:none!important}
.at-simple-page .at-pro a.at-pro-btn.at-pro-outline,.at-simple-page .at-pro a.at-pro-btn.at-pro-outline:hover{color:#101820!important}
.at-simple-page .at-pro a.at-pro-doc{display:block;text-decoration:none;line-height:0}
.at-pro-actions{display:flex;flex-wrap:wrap;gap:12px;margin:0 0 12px}
.at-pro-btn.at-pro-outline{background:#fff;color:#101820;border:1.5px solid #D8D4CD}
.at-pro-btn.at-pro-outline:hover{background:#F5F7F8}
.at-pro-note{font-size:14px;color:#5B6670}
@media (max-width:767px){.at-pro-form{padding:22px 16px;border-radius:18px}.at-pro-doc{border-radius:14px}}
</style>';

	if ( ! at_pro_has_access() ) {
		$err = isset( $_GET['pro'] ) ? sanitize_key( wp_unslash( $_GET['pro'] ) ) : '';
		$msg = 'email' === $err ? $t['e_email'] : ( 'pass' === $err ? $t['e_pass'] : '' );
		ob_start();
		echo $css;
		?>
<div class="at-pro"><form class="at-pro-form" method="post" action="<?php echo esc_url( remove_query_arg( 'pro', get_permalink() ) ); ?>">
<p class="at-pro-intro"><?php echo esc_html( $t['intro'] ); ?></p>
<?php if ( $msg ) : ?><p class="at-pro-err" role="alert"><?php echo esc_html( $msg ); ?></p><?php endif; ?>
<div class="at-pro-field"><label for="at_pro_email"><?php echo esc_html( $t['email'] ); ?></label><input type="email" id="at_pro_email" name="at_pro_email" required autocomplete="email"></div>
<div class="at-pro-field"><label for="at_pro_pass"><?php echo esc_html( $t['pass'] ); ?></label><input type="password" id="at_pro_pass" name="at_pro_pass" required autocomplete="off"></div>
<input type="hidden" name="at_pro_lang" value="<?php echo esc_attr( $lang ); ?>">
<?php wp_nonce_field( 'at_pro_gate', 'at_pro_nonce' ); ?>
<button type="submit" class="at-pro-btn" name="at_pro_submit" value="1"><?php echo esc_html( $t['submit'] ); ?></button>
</form></div>
		<?php
		return ob_get_clean();
	}

	ob_start();
	echo $css;
	echo '<div class="at-pro">';
	if ( $url ) {
		echo '<div class="at-pro-actions"><a class="at-pro-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $t['open'] ) . '</a><a class="at-pro-btn at-pro-outline" href="' . esc_url( $url ) . '" download>' . esc_html( $t['dl'] ) . '</a></div>';
		if ( $img ) {
			echo '<a class="at-pro-doc" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . wp_get_attachment_image( $img, 'full', false, array( 'alt' => 'AEROTECH pro price list 2026', 'loading' => 'lazy' ) ) . '</a>';
		}
	} else {
		echo '<p class="at-pro-err">' . esc_html( $t['nopdf'] ) . '</p>';
	}
	echo '<p class="at-pro-note">' . esc_html( $t['contact'] ) . '</p>';
	echo '</div>';
	return ob_get_clean();
} );
