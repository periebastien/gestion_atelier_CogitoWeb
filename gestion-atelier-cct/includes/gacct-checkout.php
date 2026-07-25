<?php
/**
 * Liaison JetFormBuilder / WooCommerce / JetEngine CCT.
 *
 * Code migre depuis functions.php du theme enfant (2026-07) :
 * capture des IDs CCT crees par le formulaire, liaison a la commande
 * WooCommerce au checkout, API de lecture/ecriture des items CCT.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------- CONFIGURATION ---------- */
define( 'JWCCT_CCT_REVISION',           'revision' );
define( 'JWCCT_CCT_OCCUPATION',         'occupation_atelier' );
define( 'JWCCT_META_REVISION_ID',       '_jwcct_pending_revision_id' );
define( 'JWCCT_META_OCCUPATION_ID',     '_jwcct_pending_occupation_id' );
define( 'JWCCT_META_TIMESTAMP',         '_jwcct_pending_timestamp' );
define( 'JWCCT_ORDER_REVISION_ID',      '_jwcct_revision_id' );
define( 'JWCCT_ORDER_OCCUPATION_ID',    '_jwcct_occupation_id' );
define( 'JWCCT_ORDER_LINKED',           '_jwcct_cct_linked' );
define( 'JWCCT_PENDING_TTL',            HOUR_IN_SECONDS * 2 );
define( 'JWCCT_SHOW_FRONTEND_DEBUG',    false );

// ID de la relation JetEngine "revision_to_order".
define( 'JWCCT_RELATION_REVISION_ORDER', 12 );


/* =============================================================================
 *  HELPERS DEBUG / LOG
 * ============================================================================= */

function jwcct_log( $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( '[JWCCT] ' . $message );
    }
}

function jwcct_push_debug( $event, $data ) {
    $snap = get_option( 'jwcct_debug_snapshot', [] );
    $snap[] = array_merge(
        [ 'time' => current_time( 'mysql' ), 'event' => $event ],
        is_array( $data ) ? $data : [ 'data' => $data ]
    );
    update_option( 'jwcct_debug_snapshot', array_slice( $snap, -25 ) );
}


/* =============================================================================
 *  AJOUT AU PANIER WOOCOMMERCE (action JFB "ajouter_configurateur_panier")
 * ============================================================================= */

add_action( 'jet-form-builder/custom-action/ajouter_configurateur_panier', function( $request, $action_handler ) {
    $champs_produits = ['revisions_controle', 'pliages_secours', 'suspentes_travaux', 'frais_de_ports'];
    $ids_a_ajouter = [];

    foreach ( $champs_produits as $nom_champ ) {
        if ( ! empty( $request[ $nom_champ ] ) ) {
            $valeur = $request[ $nom_champ ];
            if ( is_array( $valeur ) ) {
                $ids_a_ajouter = array_merge( $ids_a_ajouter, $valeur );
            } else {
                $ids_a_ajouter[] = $valeur;
            }
        }
    }

    foreach ( array_unique( $ids_a_ajouter ) as $product_id ) {
        $clean_id = absint( trim( $product_id ) );
        if ( $clean_id > 0 ) {
            WC()->cart->add_to_cart( $clean_id );
        }
    }
}, 10, 2 );


/* =============================================================================
 *  FONCTION A — CAPTURE VIA HOOK CUSTOM JFB
 * ============================================================================= */

add_action( 'jet-form-builder/custom-action/jwcct_capture_ids', 'jwcct_capture_ids', 10, 2 );

function jwcct_capture_ids( $request, $action_handler ) {

    $revision_id   = isset( $request['inserted_cct_revision'] )            ? absint( $request['inserted_cct_revision'] )            : 0;
    $occupation_id = isset( $request['inserted_cct_occupation_atelier'] ) ? absint( $request['inserted_cct_occupation_atelier'] ) : 0;

    jwcct_push_debug( 'capture_ids', [
        'revision_id'   => $revision_id,
        'occupation_id' => $occupation_id,
        'user_id'       => get_current_user_id(),
        'request_keys'  => array_keys( (array) $request ),
    ] );

    if ( ! is_user_logged_in() ) {
        return;
    }
    $user_id = get_current_user_id();

    if ( $revision_id ) {
        update_user_meta( $user_id, JWCCT_META_REVISION_ID, $revision_id );
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( JWCCT_META_REVISION_ID, $revision_id );
        }
    }
    if ( $occupation_id ) {
        update_user_meta( $user_id, JWCCT_META_OCCUPATION_ID, $occupation_id );
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( JWCCT_META_OCCUPATION_ID, $occupation_id );
        }
    }
    if ( $revision_id || $occupation_id ) {
        update_user_meta( $user_id, JWCCT_META_TIMESTAMP, time() );
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( JWCCT_META_TIMESTAMP, time() );
        }
    }

    jwcct_log( "capture_ids : revision=$revision_id occupation=$occupation_id user=$user_id" );
}


/* =============================================================================
 *  RÉCUPÉRATION / NETTOYAGE DES IDS PENDING
 * ============================================================================= */

function jwcct_get_pending_ids( $user_id = 0 ) {

    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return [ 0, 0, 0 ];
    }

    $revision_id   = (int) get_user_meta( $user_id, JWCCT_META_REVISION_ID,   true );
    $occupation_id = (int) get_user_meta( $user_id, JWCCT_META_OCCUPATION_ID, true );
    $timestamp     = (int) get_user_meta( $user_id, JWCCT_META_TIMESTAMP,     true );

    if ( ( ! $revision_id || ! $occupation_id ) && function_exists( 'WC' ) && WC()->session ) {
        if ( ! $revision_id )   { $revision_id   = (int) WC()->session->get( JWCCT_META_REVISION_ID );   }
        if ( ! $occupation_id ) { $occupation_id = (int) WC()->session->get( JWCCT_META_OCCUPATION_ID ); }
        if ( ! $timestamp )     { $timestamp     = (int) WC()->session->get( JWCCT_META_TIMESTAMP );     }
    }

    if ( $timestamp && ( time() - $timestamp ) > JWCCT_PENDING_TTL ) {
        return [ 0, 0, $timestamp ];
    }

    return [ $revision_id, $occupation_id, $timestamp ];
}

function jwcct_clear_pending_ids( $user_id ) {
    delete_user_meta( $user_id, JWCCT_META_REVISION_ID );
    delete_user_meta( $user_id, JWCCT_META_OCCUPATION_ID );
    delete_user_meta( $user_id, JWCCT_META_TIMESTAMP );

    if ( function_exists( 'WC' ) && WC()->session ) {
        WC()->session->__unset( JWCCT_META_REVISION_ID );
        WC()->session->__unset( JWCCT_META_OCCUPATION_ID );
        WC()->session->__unset( JWCCT_META_TIMESTAMP );
    }
}


/* =============================================================================
 *  FONCTIONS B & C — LIAISON COMMANDE ↔ CCT + RELATION JETENGINE
 * ============================================================================= */

add_action( 'woocommerce_checkout_order_processed', 'jwcct_link_order_to_cct', 20, 3 );

function jwcct_link_order_to_cct( $order_id, $posted_data, $order ) {
    jwcct_process_order_link( $order_id );
}

function jwcct_process_order_link( $order_id ) {

    $order = wc_get_order( $order_id );
    if ( ! $order ) { return; }

    $user_id = $order->get_user_id();
    if ( ! $user_id ) { return; }

    list( $revision_id, $occupation_id, $timestamp ) = jwcct_get_pending_ids( $user_id );

    if ( ! $revision_id && ! $occupation_id ) {
        jwcct_log( "process_order_link : aucun ID CCT en attente pour user $user_id (order $order_id)." );
        return;
    }

    if ( $revision_id ) {
        $order->update_meta_data( JWCCT_ORDER_REVISION_ID,   $revision_id );
    }
    if ( $occupation_id ) {
        $order->update_meta_data( JWCCT_ORDER_OCCUPATION_ID, $occupation_id );
    }
    $order->update_meta_data( JWCCT_ORDER_LINKED, current_time( 'mysql' ) );
    $order->save();

    $rev_ok = $occ_ok = $relation_ok = false;

    // --- Mises à jour JetEngine CCT (Order ID + Passage en Publish) ---

    if ( $revision_id ) {
        // On injecte l'Order ID ET on change le statut en "publish" en une seule requête
        $rev_ok = jwcct_update_cct_item( JWCCT_CCT_REVISION, $revision_id, [
            'order_id'             => $order_id,
            'cct_status'           => 'publish',
            'etat_de_la_commande'  => 1
        ] );

        // Mise à jour de la Relation JetEngine
        if ( function_exists( 'jet_engine' ) && isset( jet_engine()->relations ) ) {
            $relation = jet_engine()->relations->get_active_relations( JWCCT_RELATION_REVISION_ORDER );
            if ( $relation ) {
                $relation->update( $revision_id, $order_id );
                $relation_ok = true;
            } else {
                jwcct_log( "process_order_link : la relation " . JWCCT_RELATION_REVISION_ORDER . " est introuvable." );
            }
        }
    }

    if ( $occupation_id ) {
        // On injecte l'Order ID ET on change le statut en "publish" en une seule requête
        $occ_ok = jwcct_update_cct_item( JWCCT_CCT_OCCUPATION, $occupation_id, [
            'order_id'   => $order_id,
            'cct_status' => 'publish'
        ] );
    }

    jwcct_push_debug( 'order_linked', [
        'order_id'      => $order_id,
        'revision_id'   => $revision_id,
        'occupation_id' => $occupation_id,
        'rev_ok'        => $rev_ok,
        'occ_ok'        => $occ_ok,
        'relation_ok'   => $relation_ok,
    ] );

    if ( ( ! $revision_id || $rev_ok ) && ( ! $occupation_id || $occ_ok ) ) {
        jwcct_clear_pending_ids( $user_id );
    }
}


/* =============================================================================
 *  API JETENGINE CCT — UPDATE / READ
 * ============================================================================= */

function jwcct_update_cct_item( $cct_slug, $item_id, array $fields ) {

    $debug = [
        'slug'    => $cct_slug,
        'item_id' => $item_id,
        'fields'  => $fields,
    ];

    $item_id = absint( $item_id );
    if ( ! $item_id || empty( $fields ) ) {
        $debug['error'] = 'invalid params';
        jwcct_push_debug( 'cct_update', $debug );
        return false;
    }

    if ( ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Module' ) ) {
        $debug['error'] = 'JetEngine CCT Module class not found';
        jwcct_push_debug( 'cct_update', $debug );
        return false;
    }

    $content_type = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()
                        ->manager
                        ->get_content_types( $cct_slug );

    if ( ! $content_type ) {
        $debug['error'] = "content type '$cct_slug' not found";
        jwcct_push_debug( 'cct_update', $debug );
        return false;
    }

    $debug['content_type_class'] = get_class( $content_type );

    if ( ! isset( $content_type->db ) || ! is_object( $content_type->db ) ) {
        $debug['error'] = 'no ->db on content type';
        jwcct_push_debug( 'cct_update', $debug );
        return false;
    }

    $where = [ '_ID' => $item_id ];

    try {
        $result = $content_type->db->update( $fields, $where );
        $debug['update_result'] = is_scalar( $result ) ? $result : gettype( $result );
    } catch ( \Throwable $e ) {
        $debug['exception'] = $e->getMessage();
        jwcct_push_debug( 'cct_update', $debug );
        return false;
    }

    $after = method_exists( $content_type->db, 'get_item' )
        ? $content_type->db->get_item( $item_id )
        : null;
    $debug['after_order_id'] = is_array( $after ) ? ( $after['order_id'] ?? '(no col)' ) : null;

    jwcct_push_debug( 'cct_update', $debug );

    return ( false !== $result );
}

function jwcct_get_cct_item( $cct_slug, $item_id ) {

    if ( ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Module' ) ) {
        return null;
    }
    $content_type = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()
                        ->manager
                        ->get_content_types( $cct_slug );
    if ( ! $content_type || ! isset( $content_type->db ) ) {
        return null;
    }
    return $content_type->db->get_item( absint( $item_id ) );
}
