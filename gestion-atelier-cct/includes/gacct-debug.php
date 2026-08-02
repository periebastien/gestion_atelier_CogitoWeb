<?php
/**
 * Panneaux de debug frontend (checkout, thank-you, panneau lateral).
 * Actives uniquement si JWCCT_SHOW_FRONTEND_DEBUG (defini dans gacct-checkout.php)
 * est a true ET que l'utilisateur est administrateur.
 *
 * Code migre depuis functions.php du theme enfant (2026-07).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( JWCCT_SHOW_FRONTEND_DEBUG ) {
    add_action( 'woocommerce_before_checkout_form', 'jwcct_debug_checkout' );
    add_action( 'woocommerce_thankyou',             'jwcct_debug_thankyou' );
    add_action( 'wp_footer',                        'jwcct_debug_side_panel' );
}

function jwcct_can_see_debug() {
    return JWCCT_SHOW_FRONTEND_DEBUG && current_user_can( 'manage_options' );
}

function jwcct_debug_checkout() {
    if ( ! jwcct_can_see_debug() ) { return; }
    list( $rev, $occ, $ts ) = jwcct_get_pending_ids();
    $age = $ts ? human_time_diff( $ts ) . ' ago' : 'n/a';

    echo '<div style="background:#fff8e1;border:1px solid #f0c419;padding:12px;margin:12px 0;font-family:monospace;font-size:13px;">';
    echo '<strong>[DEBUG JWCCT — Checkout]</strong><br>';
    echo 'User : ' . esc_html( get_current_user_id() ) . '<br>';
    echo 'Pending revision_id : <strong>'   . (int) $rev . '</strong><br>';
    echo 'Pending occupation_id : <strong>' . (int) $occ . '</strong><br>';
    echo 'Captured : ' . esc_html( $age );
    if ( ! $rev || ! $occ ) {
        echo '<br><span style="color:#c00;">⚠ Au moins un ID est manquant.</span>';
    }
    echo '</div>';
}

function jwcct_debug_thankyou( $order_id ) {
    if ( ! jwcct_can_see_debug() || ! $order_id ) { return; }
    $order = wc_get_order( $order_id );
    if ( ! $order ) { return; }

    $rev = (int) $order->get_meta( JWCCT_ORDER_REVISION_ID );
    $occ = (int) $order->get_meta( JWCCT_ORDER_OCCUPATION_ID );
    $ts  = $order->get_meta( JWCCT_ORDER_LINKED );

    $rev_item = $rev ? jwcct_get_cct_item( JWCCT_CCT_REVISION,   $rev ) : null;
    $occ_item = $occ ? jwcct_get_cct_item( JWCCT_CCT_OCCUPATION, $occ ) : null;
    $rev_in   = is_array( $rev_item ) ? ( $rev_item['order_id'] ?? '' ) : '';
    $occ_in   = is_array( $occ_item ) ? ( $occ_item['order_id'] ?? '' ) : '';

    $ok_rev = ( $rev && (int) $rev_in === (int) $order_id );
    $ok_occ = ( $occ && (int) $occ_in === (int) $order_id );

    // --- Vérification de la Relation JetEngine ---
    $relation_status = '<span style="color:#888;">Non vérifiée</span>';
    if ( function_exists( 'jet_engine' ) && isset( jet_engine()->relations ) ) {
        $rel_obj = jet_engine()->relations->get_active_relations( jwcct_relation_revision_order_id() );
        if ( $rel_obj && $rev ) {
            $children = $rel_obj->get_children( $rev );

            // On force la conversion en tableau simple au cas où JetEngine renvoie un format complexe
            $children_ids = is_array( $children ) ? array_map('intval', $children) : [];

            if ( ! empty( $children_ids ) && in_array( (int) $order_id, $children_ids, true ) ) {
                $relation_status = '<span style="color:#0a0;">✔ Liée avec succès</span>';
            } else {
                // On affiche ce que JetEngine a vraiment répondu pour comprendre
                $dump = is_array($children) ? implode(',', $children) : 'vide/cache';
                $relation_status = '<span style="color:#f90;">⚠ Succès en BDD (Affichage bloqué par le cache. JetEngine voit : ' . esc_html($dump) . ')</span>';
            }
        }
    }

    echo '<div style="background:#e8f5e9;border:1px solid #4caf50;padding:12px;margin:12px 0;font-family:monospace;font-size:13px;">';
    echo '<strong>[DEBUG JWCCT — Thank You]</strong><br>';
    echo 'Order ID : <strong>' . (int) $order_id . '</strong><br>';
    echo 'Liaison effectuée le : ' . esc_html( $ts ?: 'n/a' ) . '<br>';
    echo '<hr style="border:none;border-top:1px dashed #999;margin:6px 0;">';

    echo '<strong>Relation JetEngine (ID ' . (int) jwcct_relation_revision_order_id() . ')</strong> : ' . $relation_status . '<br>';
    echo '<hr style="border:none;border-top:1px dashed #999;margin:6px 0;">';

    echo 'Order meta revision_id : ' . (int) $rev . '<br>';
    echo 'CCT revision.order_id  : ' . esc_html( $rev_in ?: '∅' );
    echo $ok_rev ? ' <span style="color:#0a0;">✔</span>' : ' <span style="color:#c00;">✘</span>';
    echo '<br>';
    echo 'Order meta occupation_id : ' . (int) $occ . '<br>';
    echo 'CCT occupation.order_id  : ' . esc_html( $occ_in ?: '∅' );
    echo $ok_occ ? ' <span style="color:#0a0;">✔</span>' : ' <span style="color:#c00;">✘</span>';
    echo '</div>';
}

function jwcct_debug_side_panel() {
    if ( ! jwcct_can_see_debug() ) { return; }

    if ( isset( $_GET['jwcct_clear'] ) ) {
        delete_option( 'jwcct_debug_snapshot' );
        echo '<script>window.location = window.location.pathname;</script>';
        return;
    }

    $snap      = get_option( 'jwcct_debug_snapshot', [] );
    $clear_url = add_query_arg( 'jwcct_clear', '1' );

    echo '<div id="jwcct-side-panel" style="
        position:fixed;top:80px;right:0;width:380px;max-height:calc(100vh - 100px);
        overflow:auto;background:#1e1e1e;color:#0f0;padding:12px;
        font-family:Consolas,Monaco,monospace;font-size:11px;line-height:1.4;
        z-index:99999;border-left:3px solid #0f0;border-top:1px solid #0f0;
        border-bottom:1px solid #0f0;box-shadow:-4px 0 12px rgba(0,0,0,0.4);">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;border-bottom:1px solid #0f0;padding-bottom:6px;">';
    echo '<strong style="color:#0f0;font-size:12px;">[JWCCT] ' . count( $snap ) . ' events</strong>';
    echo '<a href="' . esc_url( $clear_url ) . '" style="color:#ff0;text-decoration:none;font-weight:bold;">[clear]</a>';
    echo '</div>';

    if ( empty( $snap ) ) {
        echo '<em style="color:#888;">No events captured yet.<br>Submit the form to populate.</em>';
    } else {
        echo '<pre style="white-space:pre-wrap;word-break:break-word;color:#0f0;margin:0;">';
        echo esc_html( print_r( $snap, true ) );
        echo '</pre>';
    }

    echo '</div>';
}
