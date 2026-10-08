<?php
/**
 * Version texte de l'e-mail « Commande terminée » réécrit (08/10/2026).
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: %s: prénom */
echo $order->get_billing_first_name() ? sprintf( esc_html__( 'Bonjour %s,', 'gestion-atelier-cct' ), esc_html( $order->get_billing_first_name() ) ) : esc_html__( 'Bonjour,', 'gestion-atelier-cct' );
echo "\n\n" . esc_html( gacct_oc_completed_intro( $order ) ) . "\n\n";
echo esc_url( $order->get_view_order_url() ) . "\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
