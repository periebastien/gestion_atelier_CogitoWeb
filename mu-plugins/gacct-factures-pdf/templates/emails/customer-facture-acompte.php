<?php
/**
 * E-mail « Facture d'acompte » (HTML).
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
<?php
if ( $order->get_billing_first_name() ) {
	/* translators: %s: prénom */
	printf( esc_html__( 'Bonjour %s,', 'gestion-atelier-cct' ), esc_html( $order->get_billing_first_name() ) );
} else {
	esc_html_e( 'Bonjour,', 'gestion-atelier-cct' );
}
?>
</p>
<p><?php echo esc_html( $email->intro() ); ?></p>
<p><a href="<?php echo esc_url( $order->get_view_order_url() ); ?>"><?php esc_html_e( 'Voir ma commande dans mon espace client', 'gestion-atelier-cct' ); ?></a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
