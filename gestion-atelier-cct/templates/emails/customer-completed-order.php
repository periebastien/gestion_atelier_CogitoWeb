<?php
/**
 * E-mail « Commande terminée » réécrit en envoi de facture (08/10/2026).
 * Remplace emails/customer-completed-order.php de WooCommerce, même structure.
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
<p><?php echo esc_html( gacct_oc_completed_intro( $order ) ); ?></p>
<p><a href="<?php echo esc_url( $order->get_view_order_url() ); ?>"><?php esc_html_e( 'Voir ma commande dans mon espace client', 'gestion-atelier-cct' ); ?></a></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
