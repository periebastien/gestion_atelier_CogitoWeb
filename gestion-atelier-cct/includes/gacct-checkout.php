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
// TTL des IDs CCT en attente : 24 h (aligne sur la purge de minuit de gacct-payments.php,
// qui supprime les brouillons orphelins ET nettoie ces metas ; permet au lien de l'email
// "panier abandonne" de fonctionner jusqu'a la purge). Anciennement 2 h.
define( 'JWCCT_PENDING_TTL',            DAY_IN_SECONDS );
define( 'JWCCT_SHOW_FRONTEND_DEBUG',    false );

// ID de la relation JetEngine "revision_to_order".
define( 'JWCCT_RELATION_REVISION_ORDER', 12 );

/**
 * ID d'une relation JetEngine, filtrable pour les sites ou les IDs different
 * (meme filtre/signature que GACCT_Plugin::relation_id()).
 * Cles : 'revision_to_occupation' (11), 'revision_to_order' (12),
 * 'client_to_revision' (13).
 */
function gacct_relation_id( $relation_key, $default ) {
    return (int) apply_filters( 'gacct_relation_id', $default, $relation_key );
}

/**
 * ID de la relation revision<->commande, filtrable pour les sites ou les IDs
 * JetEngine different (meme filtre/signature que GACCT_Plugin::relation_id()).
 */
function jwcct_relation_revision_order_id() {
    return gacct_relation_id( 'revision_to_order', JWCCT_RELATION_REVISION_ORDER );
}


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
    /* ⚠ Charger la session panier AVANT tout ajout. Dans une soumission JFB, le
       panier est instancié paresseusement et sa session n'est PAS encore chargée :
       le premier add_to_cart() écrit dans un panier vide en mémoire, puis le
       premier get_cart() interne (calculate_totals → is_empty) recharge la
       session et ÉCRASE ce premier article — perdu en silence (vécu le
       09/08/2026 : la Révision périodique disparaissait du panier). */
    if ( function_exists( 'WC' ) && WC()->cart ) {
        WC()->cart->get_cart();
        /* Une soumission = une configuration complète : on repart d'un panier
           vide. Sans ça, une re-soumission (retour arrière, double clic sur
           « Envoyer ») EMPILAIT les produits de la demande précédente. */
        WC()->cart->empty_cart();
    }

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

    /* Quantités (formulaire v2) : champ caché `suspentes_quantites` au format
       "id:qty,id:qty", ne s'applique qu'aux produits effectivement cochés.
       Absent (formulaire historique) : tout part en quantité 1. */
    $quantites = [];
    if ( ! empty( $request['suspentes_quantites'] ) && is_string( $request['suspentes_quantites'] ) ) {
        foreach ( explode( ',', $request['suspentes_quantites'] ) as $paire ) {
            $morceaux = explode( ':', trim( $paire ) );
            $pid      = absint( $morceaux[0] ?? 0 );
            $qty      = max( 1, min( 9, absint( $morceaux[1] ?? 1 ) ) );
            if ( $pid ) {
                $quantites[ $pid ] = $qty;
            }
        }
    }

    foreach ( array_unique( $ids_a_ajouter ) as $product_id ) {
        $clean_id = absint( trim( $product_id ) );
        if ( $clean_id > 0 ) {
            WC()->cart->add_to_cart( $clean_id, $quantites[ $clean_id ] ?? 1 );
        }
    }

    /* Suppléments biplace (formulaire v2) : champs cachés `biplace_voile` /
       `biplace_secours` ('1' si la bascule du groupe est en Biplace). Le produit
       supplément est ajouté en quantité = nombre de prestations concernées
       cochées (un supplément PAR prestation, cf. maquette 1918). L'acompte du
       supplément suit sa propre meta Kojito, comme tout produit du panier. */
    if ( function_exists( 'gacct_biplace_supplement_product_ids' ) && function_exists( 'gacct_product_biplace_supplement' ) ) {
        $supplements = (array) gacct_biplace_supplement_product_ids();

        foreach ( [ 'voile' => 'biplace_voile', 'secours' => 'biplace_secours' ] as $type => $champ ) {
            if ( empty( $request[ $champ ] ) || empty( $supplements[ $type ] ) ) {
                continue;
            }

            $nb = 0;
            foreach ( array_unique( $ids_a_ajouter ) as $product_id ) {
                if ( $type === gacct_product_biplace_supplement( absint( $product_id ) ) ) {
                    $nb++;
                }
            }

            if ( $nb > 0 ) {
                WC()->cart->add_to_cart( absint( $supplements[ $type ] ), $nb );
            }
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
        // On injecte l'Order ID ET on change le statut en "publish" en une seule requête.
        // L'etat de depart depend du paiement : 0 tant que rien n'est encaisse (virement
        // en attente, paiement echoue), 1 des que l'acompte est recu.
        $rev_ok = jwcct_update_cct_item( JWCCT_CCT_REVISION, $revision_id, [
            'order_id'             => $order_id,
            'cct_status'           => 'publish',
            'etat_de_la_commande'  => gacct_initial_revision_state( $order )
        ] );

        // Mise à jour de la Relation JetEngine
        if ( function_exists( 'jet_engine' ) && isset( jet_engine()->relations ) ) {
            $relation = jet_engine()->relations->get_active_relations( jwcct_relation_revision_order_id() );
            if ( $relation ) {
                $relation->update( $revision_id, $order_id );
                $relation_ok = true;
            } else {
                jwcct_log( "process_order_link : la relation " . jwcct_relation_revision_order_id() . " est introuvable." );
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
 *  ETAT 0 / 1 : LE DOSSIER SUIT LE PAIEMENT
 *
 *  Le tracker de l'espace client prevoit un etat 0 « En attente de paiement »
 *  (badge orange, action requise). Il n'etait jamais ecrit : le checkout posait
 *  1 en dur, si bien qu'un virement non recu affichait « nous attendons votre
 *  materiel » alors que rien n'etait encaisse.
 *
 *  Regle : 0 tant que le paiement n'est pas arrive, 1 des qu'il l'est.
 *  L'expedition du materiel n'est pas conditionnee a l'etat : le client peut
 *  envoyer sa voile pendant qu'il fait son virement.
 * ============================================================================= */

/**
 * Le paiement a-t-il ete encaisse ?
 *
 * `wc_get_is_paid_statuses()` ne connait que processing/completed. Le statut
 * custom `acompte-paye` du plugin Kojito vaut paiement pour nous : il est pose
 * precisement au moment ou l'acompte est recu.
 *
 * @param WC_Order|mixed $order
 * @return bool
 */
function gacct_order_payment_received( $order ) {

    if ( ! $order instanceof WC_Order ) {
        return false;
    }

    $paid_statuses = array_merge( wc_get_is_paid_statuses(), [ 'acompte-paye' ] );

    return $order->has_status( apply_filters( 'gacct_paid_order_statuses', $paid_statuses, $order ) );
}

/**
 * Etat a poser sur la revision au moment de la liaison commande <-> CCT.
 *
 * @param WC_Order|mixed $order
 * @return int 0 (attente paiement) ou 1 (attente reception)
 */
function gacct_initial_revision_state( $order ) {
    return gacct_order_payment_received( $order ) ? 1 : 0;
}

/**
 * Revision liee a une commande, meta d'abord, colonne order_id en repli.
 *
 * Le repli couvre les liaisons ratees, les commandes invitees et les dossiers
 * crees a la main : la meta manque, mais la revision porte bien l'order_id.
 *
 * @param WC_Order|mixed $order
 * @return int 0 si aucune revision.
 */
function gacct_revision_id_for_order( $order ) {

    if ( ! $order instanceof WC_Order ) {
        return 0;
    }

    $revision_id = absint( $order->get_meta( JWCCT_ORDER_REVISION_ID ) );

    if ( $revision_id ) {
        return $revision_id;
    }

    global $wpdb;

    return absint( $wpdb->get_var( $wpdb->prepare(
        "SELECT _ID FROM {$wpdb->prefix}jet_cct_revision WHERE order_id = %d AND cct_status = 'publish' LIMIT 1",
        $order->get_id()
    ) ) );
}

/**
 * Bascule 0 -> 1 quand le paiement arrive (encaissement carte, passage manuel
 * de la commande en « En cours » apres reception du virement, acompte Kojito).
 *
 * On ne touche qu'a l'etat 0 : un dossier deja avance (voile receptionnee,
 * devis en cours...) ne doit jamais reculer parce qu'un statut de commande
 * change en cours de route.
 */
add_action( 'woocommerce_order_status_changed', 'gacct_sync_revision_state_on_payment', 20, 4 );

function gacct_sync_revision_state_on_payment( $order_id, $old_status, $new_status, $order ) {

    if ( ! gacct_order_payment_received( $order ) ) {
        return;
    }

    $revision_id = gacct_revision_id_for_order( $order );

    if ( ! $revision_id ) {
        return;
    }

    $revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

    if ( ! is_array( $revision ) || ! isset( $revision['etat_de_la_commande'] ) ) {
        return;
    }

    if ( 0 !== (int) $revision['etat_de_la_commande'] ) {
        return;
    }

    $updated = jwcct_update_cct_item( JWCCT_CCT_REVISION, $revision_id, [
        'etat_de_la_commande' => 1,
    ] );

    if ( $updated ) {
        $order->add_order_note(
            __( 'Paiement recu : dossier atelier passe en « En attente de reception ».', 'gestion-atelier-cct' )
        );
    } else {
        jwcct_log( "sync_revision_state : echec du passage 0 -> 1 pour la revision $revision_id (order $order_id)." );
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
