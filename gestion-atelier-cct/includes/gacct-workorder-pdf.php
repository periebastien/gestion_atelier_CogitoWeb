<?php
/**
 * Bon d'intervention en PDF téléchargeable (10/08/2026).
 *
 * Pourquoi un SECOND rendu plutôt que « imprimer en PDF » depuis le
 * navigateur : une grande partie des clients n'a pas d'imprimante chez elle
 * et fait imprimer le bon ailleurs (bureau, voisin, boutique). Il leur faut
 * un fichier, pas une page web.
 *
 * ⚠ La page HTML (templates/workorder.php) est en flexbox, grid et mm :
 * dompdf ne connaît NI flexbox NI grid. Ce module en est donc une seconde
 * écriture, en tables, qui doit rester visuellement identique — toute
 * retouche du design du bon est à répercuter dans les DEUX templates.
 * C'est le prix à payer pour ne pas embarquer un moteur de rendu complet.
 *
 * URL : /?gacct_workorder=<id>&key=<order_key>&pdf=1 — mêmes gardes que la
 * page imprimable (dont le verrou « paiement non encaissé »), la bifurcation
 * se fait APRÈS elles dans gacct_wo_maybe_render_print_page().
 *
 * Le QR est généré côté serveur (chillerlan/php-qrcode vendored, déjà utilisé
 * par les rapports) et mis en cache dans uploads/gacct_report_cache : dompdf
 * n'exécute pas de JS, la version qrcodejs de la page HTML ne sert à rien ici.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL de téléchargement du bon en PDF.
 */
function gacct_wo_pdf_url( $order ) {
	return add_query_arg( 'pdf', '1', gacct_wo_print_url( $order ) );
}

/**
 * PNG du QR de scan d'une commande, mis en cache par URL.
 *
 * @param WC_Order $order Commande.
 * @return string Chemin absolu, '' si la génération échoue.
 */
function gacct_wo_qr_png_path( $order ) {
	$url = gacct_wo_scan_url( $order );
	$dir = function_exists( 'gacct_report_cache_dir' ) ? gacct_report_cache_dir() : '';

	if ( ! $dir ) {
		return '';
	}

	$path = $dir . '/wo-qr-' . md5( $url ) . '.png';

	if ( file_exists( $path ) ) {
		return $path;
	}

	$autoload = dirname( __DIR__ ) . '/assets/vendor/php-qrcode/autoload.php';

	if ( ! file_exists( $autoload ) ) {
		return '';
	}

	require_once $autoload;

	try {
		// scale 12 : ~450 px pour 32 mm à l'impression, soit ~360 dpi. En
		// dessous, les modules du QR bavent sur une laser d'entrée de gamme.
		$options = new \chillerlan\QRCode\QROptions( array(
			'outputType'   => \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,
			'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::M,
			'scale'        => 12,
			'outputBase64' => false,
		) );

		$png = ( new \chillerlan\QRCode\QRCode( $options ) )->render( $url );
	} catch ( \Throwable $e ) {
		jwcct_log( 'gacct_wo_qr_png_path : ' . $e->getMessage() );
		return '';
	}

	if ( ! $png || false === @file_put_contents( $path, $png, LOCK_EX ) ) {
		return '';
	}

	return $path;
}

/**
 * Binaire PDF du bon d'intervention.
 *
 * @param WC_Order $order Commande.
 * @return string|WP_Error
 */
function gacct_wo_render_pdf( $order ) {
	if ( ! function_exists( 'gacct_report_load_dompdf' ) || ! gacct_report_load_dompdf() ) {
		return new WP_Error( 'gacct_wo_no_dompdf', __( 'Le moteur PDF est indisponible.', 'gestion-atelier-cct' ) );
	}

	$data = gacct_wo_data( $order );

	// Le PDF n'a rien à demander au réseau : logo et QR sont des chemins
	// locaux (isRemoteEnabled reste à false, chroot limité à wp-content).
	$data['logo_path'] = ! empty( $data['logo_url'] ) && function_exists( 'gacct_report_local_image_path' )
		? gacct_report_local_image_path( $data['logo_url'] )
		: '';
	$data['qr_path']   = gacct_wo_qr_png_path( $order );
	$data['font']      = function_exists( 'gacct_report_font_css' )
		? gacct_report_font_css()
		: array( 'css' => '', 'family' => '"DejaVu Sans", sans-serif' );

	ob_start();
	include dirname( __DIR__ ) . '/templates/workorder-pdf.php';
	$html = (string) ob_get_clean();

	$options = new \Dompdf\Options();
	$options->set( 'isRemoteEnabled', false );
	$options->set( 'chroot', array( WP_CONTENT_DIR ) );
	$options->set( 'defaultFont', 'DejaVu Sans' );

	$cache_dir = function_exists( 'gacct_report_cache_dir' ) ? gacct_report_cache_dir() : '';

	if ( $cache_dir ) {
		$options->set( 'fontDir', $cache_dir );
		$options->set( 'fontCache', $cache_dir );
		$options->set( 'isFontSubsettingEnabled', true );
	}

	$dompdf = new \Dompdf\Dompdf( $options );
	$dompdf->loadHtml( $html, 'UTF-8' );
	$dompdf->setPaper( 'A4', 'portrait' );
	$dompdf->render();

	$binary = $dompdf->output();

	if ( ! $binary || '%PDF' !== substr( $binary, 0, 4 ) ) {
		return new WP_Error( 'gacct_wo_pdf_failed', __( 'La génération du PDF a échoué.', 'gestion-atelier-cct' ) );
	}

	return $binary;
}

/**
 * Envoi du PDF au navigateur (téléchargement). Ne revient jamais.
 *
 * @param WC_Order $order Commande.
 */
function gacct_wo_stream_pdf( $order ) {
	$binary = gacct_wo_render_pdf( $order );

	if ( is_wp_error( $binary ) ) {
		wp_die(
			esc_html( $binary->get_error_message() ),
			esc_html__( 'Bon d\'intervention', 'gestion-atelier-cct' ),
			array( 'response' => 500 )
		);
	}

	$filename = sanitize_file_name( sprintf(
		/* translators: 1: référence de commande */
		__( 'bon-intervention-%s.pdf', 'gestion-atelier-cct' ),
		$order->get_order_number()
	) );

	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . strlen( $binary ) );

	echo $binary; // phpcs:ignore WordPress.Security.EscapeOutput -- binaire PDF.
	exit;
}
