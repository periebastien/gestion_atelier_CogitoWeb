<?php
/**
 * Avis Google et jeu de la roue, 16/10/2026 (demande de Bastien).
 *
 * Hervé glissait avec l'e-mail de fin de révision une carte « tournez la roue »
 * (jeu Cadeo, QR code). Le module la remet en place, sans lier le jeu à l'avis
 * (règles Google sur les avis avec contrepartie) :
 *
 * - encart ajouté sous l'e-mail d'état 7 (révision finie et payée) : invitation
 *   à laisser un avis Google, puis invitation à jouer ;
 * - le jeu ne fonctionne que sur téléphone : sur téléphone, bouton « Tourner la
 *   roue » ; sur ordinateur, QR code à scanner (media query, l'encart mobile
 *   porte `-emogrifier-keep` pour survivre au nettoyage de WooCommerce) ;
 * - les liens passent par `/?gacct_roue=1` : téléphone → redirection vers le
 *   jeu, ordinateur → page avec le QR code. Un client mail qui ignore les media
 *   queries reste donc juste.
 *
 * Réglages : page Notifications (option `gacct_avis_settings`). Lien du jeu vide
 * = module inerte (cas d'AEROTECH).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_AVIS_OPT', 'gacct_avis_settings' );

/**
 * Réglages du module.
 *
 * @return array{enabled:bool,game_url:string,review_url:string,image_url:string,states:int[]}
 */
function gacct_avis_settings() {
	$saved = get_option( GACCT_AVIS_OPT, array() );
	$saved = is_array( $saved ) ? $saved : array();

	$settings = array_merge(
		array(
			'enabled'    => false,
			'game_url'   => '',
			'review_url' => '',
			'image_url'  => '',
			'states'     => array( 7 ),
		),
		$saved
	);

	return (array) apply_filters( 'gacct_avis_settings', $settings );
}

/**
 * Le module est-il actif (case cochée et lien du jeu renseigné) ?
 */
function gacct_avis_active() {
	$s = gacct_avis_settings();

	return ! empty( $s['enabled'] ) && '' !== trim( (string) $s['game_url'] );
}

/**
 * Lien envoyé au client : page du site qui aiguille selon l'appareil.
 */
function gacct_avis_redirect_url() {
	return add_query_arg( 'gacct_roue', '1', home_url( '/' ) );
}

/**
 * URL publique du PNG du QR code du jeu (mis en cache dans les uploads).
 *
 * @return string '' en cas d'échec.
 */
function gacct_avis_qr_url() {
	$s   = gacct_avis_settings();
	$url = trim( (string) $s['game_url'] );

	if ( '' === $url ) {
		return '';
	}

	$uploads = wp_upload_dir( null, false );

	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return '';
	}

	$dir  = trailingslashit( $uploads['basedir'] ) . 'gacct-avis';
	$file = 'qr-' . md5( $url ) . '.png';

	if ( ! file_exists( $dir . '/' . $file ) ) {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		require_once dirname( __DIR__ ) . '/assets/vendor/php-qrcode/autoload.php';

		try {
			$options = new \chillerlan\QRCode\QROptions( array(
				'outputType'   => \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,
				'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::M,
				'scale'        => 8,
				'outputBase64' => false,
			) );

			$png = ( new \chillerlan\QRCode\QRCode( $options ) )->render( $url );
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( ! $png || false === @file_put_contents( $dir . '/' . $file, $png, LOCK_EX ) ) {
			return '';
		}
	}

	return trailingslashit( $uploads['baseurl'] ) . 'gacct-avis/' . $file;
}

/**
 * Textes de l'encart (filtrables, white-label).
 */
function gacct_avis_texts() {
	return (array) apply_filters(
		'gacct_avis_texts',
		array(
			'avis'      => '<strong>Votre avis nous aide beaucoup</strong> : laissez-nous un mot sur Google.',
			'avis_btn'  => 'Laisser un avis Google',
			'jeu'       => 'Et pour vous remercier de votre confiance, tentez votre chance à notre roue !',
			'jeu_btn'   => 'Tourner la roue',
			'jeu_pc'    => 'Le jeu s’ouvre sur téléphone : scannez ce QR code avec l’appareil photo de votre téléphone.',
			'image_alt' => 'Tournez la roue et découvrez votre cadeau',
		)
	);
}

/**
 * Bouton « à l'épreuve des clients mail » (table + lien plein).
 */
function gacct_avis_button( $url, $label, $bg ) {
	return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:12px 0 4px;"><tr>'
		. '<td style="background:' . esc_attr( $bg ) . ';border-radius:999px;">'
		. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:999px;">' . esc_html( $label ) . '</a>'
		. '</td></tr></table>';
}

/**
 * HTML de l'encart pour l'e-mail.
 */
function gacct_avis_email_block() {
	if ( ! gacct_avis_active() ) {
		return '';
	}

	$s     = gacct_avis_settings();
	$t     = gacct_avis_texts();
	$link  = gacct_avis_redirect_url();
	$qr    = gacct_avis_qr_url();
	$image = trim( (string) $s['image_url'] );
	$html  = '';

	$html .= '<div style="margin:32px 0 8px;padding-top:24px;border-top:1px solid #e5e5e5;">';

	// 1. Avis Google.
	$html .= '<p style="font-size:16px;margin:0 0 4px;">' . wp_kses_post( $t['avis'] ) . '</p>';
	if ( '' !== trim( (string) $s['review_url'] ) ) {
		$html .= gacct_avis_button( $s['review_url'], $t['avis_btn'], '#1a73e8' );
	}

	// 2. Jeu.
	$html .= '<p style="font-size:16px;margin:20px 0 12px;">' . esc_html( $t['jeu'] ) . '</p>';

	if ( '' !== $image ) {
		$html .= '<a href="' . esc_url( $link ) . '" style="text-decoration:none;">'
			. '<img src="' . esc_url( $image ) . '" width="600" alt="' . esc_attr( $t['image_alt'] ) . '" style="display:block;width:100%;max-width:600px;height:auto;border:0;border-radius:8px;">'
			. '</a>';
	}

	// Téléphone : bouton (masqué par défaut, révélé par la media query).
	$html .= '<div class="gacct-avis-mob -emogrifier-keep" style="display:none;max-height:0;overflow:hidden;mso-hide:all;">'
		. gacct_avis_button( $link, $t['jeu_btn'], '#e8a400' )
		. '</div>';

	// Ordinateur : QR code à scanner.
	if ( '' !== $qr ) {
		$html .= '<div class="gacct-avis-pc">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;"><tr>'
			. '<td style="vertical-align:middle;padding-right:16px;"><img src="' . esc_url( $qr ) . '" width="150" height="150" alt="QR code du jeu" style="display:block;width:150px;height:150px;border:0;"></td>'
			. '<td style="vertical-align:middle;font-size:16px;">' . esc_html( $t['jeu_pc'] ) . '</td>'
			. '</tr></table></div>';
	}

	$html .= '</div>';

	return $html;
}

/**
 * Bascule téléphone / ordinateur. Passée par les styles WooCommerce : une balise
 * <style> dans le corps est retirée par la mise en page de l'e-mail, alors que
 * l'inliner recopie les @media dans l'en-tête (seulement si les classes sont
 * présentes dans l'e-mail : sans effet sur les autres).
 */
add_filter( 'woocommerce_email_styles', 'gacct_avis_email_css' );

function gacct_avis_email_css( $css ) {
	return $css . "
@media only screen and (max-width:620px){"
		. '.gacct-avis-pc{display:none !important;max-height:0 !important;overflow:hidden !important;}'
		. '.gacct-avis-mob{display:block !important;max-height:none !important;overflow:visible !important;}'
		. '}';
}

/* -----------------------------------------------------------------------------
 *  Ajout à l'e-mail d'état
 * -------------------------------------------------------------------------- */

add_filter( 'gacct_notification_body', 'gacct_avis_append_block', 10, 4 );

function gacct_avis_append_block( $body, $state, $order = null, $revision_id = 0 ) {
	$s = gacct_avis_settings();

	if ( ! in_array( (int) $state, array_map( 'intval', (array) $s['states'] ), true ) ) {
		return $body;
	}

	if ( ! apply_filters( 'gacct_avis_for_order', true, $order, $revision_id ) ) {
		return $body;
	}

	return $body . gacct_avis_email_block();
}

/* -----------------------------------------------------------------------------
 *  Aiguillage /?gacct_roue=1
 * -------------------------------------------------------------------------- */

add_action( 'template_redirect', 'gacct_avis_route', 1 );

function gacct_avis_route() {
	if ( empty( $_GET['gacct_roue'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$s = gacct_avis_settings();

	if ( ! gacct_avis_active() ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	if ( wp_is_mobile() ) {
		wp_redirect( esc_url_raw( $s['game_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- domaine du jeu choisi par l'atelier.
		exit;
	}

	$t     = gacct_avis_texts();
	$qr    = gacct_avis_qr_url();
	$image = trim( (string) $s['image_url'] );

	nocache_headers();
	header( 'Content-Type: text/html; charset=UTF-8' );
	?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $t['jeu_btn'] . ' · ' . get_bloginfo( 'name' ) ); ?></title>
<style>
body{margin:0;background:#111;color:#fff;font:18px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center}
.c{max-width:640px;margin:32px 16px;text-align:center}
.c img.v{width:100%;height:auto;border-radius:12px;display:block}
.q{background:#fff;display:inline-block;padding:16px;border-radius:12px;margin:28px 0 12px}
.q img{display:block;width:220px;height:220px}
a{color:#f5c400}
</style>
</head>
<body>
<div class="c">
	<?php if ( '' !== $image ) : ?>
		<img class="v" src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $t['image_alt'] ); ?>">
	<?php endif; ?>
	<?php if ( '' !== $qr ) : ?>
		<div class="q"><img src="<?php echo esc_url( $qr ); ?>" alt="QR code du jeu"></div>
	<?php endif; ?>
	<p><?php echo esc_html( $t['jeu_pc'] ); ?></p>
	<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a></p>
</div>
</body>
</html>
	<?php
	exit;
}

/* -----------------------------------------------------------------------------
 *  Réglages (page Notifications)
 * -------------------------------------------------------------------------- */

add_action( 'gacct_notifications_form_after', 'gacct_avis_render_settings' );

function gacct_avis_render_settings() {
	$s = gacct_avis_settings();
	?>
	<h2><?php esc_html_e( 'Avis Google et jeu de la roue', 'gestion-atelier-cct' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Encart ajouté sous l e-mail « Révision finie, rapport disponible ». Sur téléphone : bouton vers le jeu ; sur ordinateur : QR code à scanner. Lien du jeu vide = encart désactivé.', 'gestion-atelier-cct' ); ?></p>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Actif', 'gestion-atelier-cct' ); ?></th>
				<td><label><input type="checkbox" name="gacct_avis[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <?php esc_html_e( 'Ajouter l encart à l e-mail de fin de révision', 'gestion-atelier-cct' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="gacct_avis_game"><?php esc_html_e( 'Lien du jeu', 'gestion-atelier-cct' ); ?></label></th>
				<td><input type="url" id="gacct_avis_game" name="gacct_avis[game_url]" class="large-text" value="<?php echo esc_attr( $s['game_url'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="gacct_avis_review"><?php esc_html_e( 'Lien « laisser un avis » Google', 'gestion-atelier-cct' ); ?></label></th>
				<td><input type="url" id="gacct_avis_review" name="gacct_avis[review_url]" class="large-text" value="<?php echo esc_attr( $s['review_url'] ); ?>">
				<p class="description"><?php esc_html_e( 'Fiche Google Business > Demander des avis. Vide : la phrase reste, sans bouton.', 'gestion-atelier-cct' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="gacct_avis_image"><?php esc_html_e( 'Visuel (URL)', 'gestion-atelier-cct' ); ?></label></th>
				<td><input type="url" id="gacct_avis_image" name="gacct_avis[image_url]" class="large-text" value="<?php echo esc_attr( $s['image_url'] ); ?>">
				<p class="description"><?php esc_html_e( 'Image de la médiathèque, 1200 px de large conseillés.', 'gestion-atelier-cct' ); ?></p></td>
			</tr>
		</tbody>
	</table>
	<?php
}

add_action( 'gacct_notifications_save', 'gacct_avis_save_settings' );

function gacct_avis_save_settings() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par handle_notifications_submission().
	$posted = isset( $_POST['gacct_avis'] ) && is_array( $_POST['gacct_avis'] ) ? wp_unslash( $_POST['gacct_avis'] ) : array();
	$s      = gacct_avis_settings();

	$s['enabled']    = ! empty( $posted['enabled'] );
	$s['game_url']   = esc_url_raw( trim( (string) ( $posted['game_url'] ?? '' ) ) );
	$s['review_url'] = esc_url_raw( trim( (string) ( $posted['review_url'] ?? '' ) ) );
	$s['image_url']  = esc_url_raw( trim( (string) ( $posted['image_url'] ?? '' ) ) );

	update_option( GACCT_AVIS_OPT, $s, false );
}
