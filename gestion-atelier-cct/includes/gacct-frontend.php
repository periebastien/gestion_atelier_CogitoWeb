<?php
/**
 * Affichage front : callbacks JetEngine listing, shortcodes espace client,
 * bloc "date de revision" dans les emails WooCommerce.
 *
 * Code migre depuis functions.php du theme enfant (2026-07).
 * IMPORTANT : les noms de fonctions/callbacks sont references dans les
 * listings Elementor/JetEngine — ne pas les renommer.
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
 *  SHORTCODES UTILITAIRES (utilises dans Elementor)
 * ============================================================================= */

add_shortcode( 'bouton_deconnexion', function() {
    return wp_logout_url( home_url() );
} );

add_shortcode( 'wp_admin_email', function() {
    return get_option( 'admin_email' );
} );

add_shortcode( 'avatar_initiales', 'gacct_avatar_initiales' );

/**
 * Avatar du client : PHOTO du compte si elle existe, INITIALES sinon
 * (cascade du CDC-dashboard-client.md §2.1.1).
 *
 * ⚠ Nom historique conserve : ce shortcode/callback est reference dans
 * Elementor (bandeau du tableau de bord + menu lateral de l'espace client).
 *
 * ⚠ Piege : `get_avatar_url()` ne renvoie JAMAIS vide (silhouette grise
 * "mystery person" de Gravatar). La detection d'une vraie photo est deleguee a
 * `gacct_dash_avatar_url()` (gacct-dashboard.php), qui teste la user meta
 * `{prefixe}_user_avatar` posee par Nextend Social Login.
 *
 * Retrocompatibilite : sans attribut, la sortie sans photo est exactement
 * l'ancienne, `<div class="user-avatar">XY</div>`.
 *
 * Attributs :
 * - `size`    : taille en pixels (pose la variable CSS `--gacct-avatar-size`).
 * - `class`   : classes CSS supplementaires.
 * - `user_id` : cible un autre utilisateur (0 = utilisateur connecte).
 *
 * @param array<string,string>|string $atts Attributs du shortcode.
 * @return string HTML.
 */
function gacct_avatar_initiales( $atts = array() ) {
    $atts = shortcode_atts(
        array(
            'size'    => 0,
            'class'   => '',
            'user_id' => 0,
        ),
        is_array( $atts ) ? $atts : array(),
        'avatar_initiales'
    );

    $user_id = absint( $atts['user_id'] );

    if ( ! $user_id ) {
        if ( ! is_user_logged_in() ) {
            return '';
        }
        $user_id = get_current_user_id();
    }

    $user = get_userdata( $user_id );

    if ( ! $user ) {
        return '';
    }

    // --- Classes et taille -------------------------------------------------
    $classes = array( 'user-avatar' );

    foreach ( preg_split( '/\s+/', trim( (string) $atts['class'] ) ) as $classe ) {
        if ( '' !== $classe ) {
            $classes[] = sanitize_html_class( $classe );
        }
    }

    $size  = absint( $atts['size'] );
    $style = $size ? sprintf( ' style="--gacct-avatar-size:%dpx"', $size ) : '';

    // --- 1. Photo du compte (Nextend / Google, rapatriee en mediatheque) ----
    $photo = function_exists( 'gacct_dash_avatar_url' )
        ? gacct_dash_avatar_url( $user_id, $size ? $size : 96 )
        : null;

    if ( $photo ) {
        $classes[] = 'user-avatar--photo';

        return sprintf(
            '<div class="%1$s"%2$s><img src="%3$s" alt="%4$s" loading="lazy" decoding="async"></div>',
            esc_attr( implode( ' ', $classes ) ),
            $style,
            esc_url( $photo ),
            esc_attr( $user->display_name )
        );
    }

    // --- 2. Repli : initiales ----------------------------------------------
    $initiales = function_exists( 'gacct_dash_initials' )
        ? gacct_dash_initials( $user_id )
        : mb_strtoupper( mb_substr( (string) $user->display_name, 0, 2 ) );

    return sprintf(
        '<div class="%1$s"%2$s>%3$s</div>',
        esc_attr( implode( ' ', $classes ) ),
        $style,
        esc_html( $initiales )
    );
}

add_shortcode( 'nom_complet_facturation', 'gacct_nom_complet_facturation' );

function gacct_nom_complet_facturation() {
    if ( ! is_user_logged_in() ) {
        return '';
    }

    $user_id = get_current_user_id();
    $prenom = get_user_meta($user_id, 'billing_first_name', true);
    $nom = get_user_meta($user_id, 'billing_last_name', true);

    return esc_html(trim($prenom . ' ' . $nom));
}

/*
 * Alias historiques non préfixés (rien ne les référence plus en base, mais un
 * snippet ou un template tiers pourrait encore les appeler). Gardés par
 * function_exists : si un autre plugin déclare le même nom, on s'efface.
 */
if ( ! function_exists( 'generer_avatar_initiales_utilisateur' ) ) {
    function generer_avatar_initiales_utilisateur( $atts = array() ) {
        return gacct_avatar_initiales( $atts );
    }
}

if ( ! function_exists( 'afficher_nom_complet_facturation_utilisateur' ) ) {
    function afficher_nom_complet_facturation_utilisateur() {
        return gacct_nom_complet_facturation();
    }
}


/* =============================================================================
 *  CALLBACK : ICÔNE PDF CLIQUABLE (SANS TEXTE)
 * ============================================================================= */

add_filter( 'jet-engine/listings/allowed-callbacks', function( $callbacks ) {
    $callbacks['jwcct_get_pdf_icon_link'] = 'JWCCT: Icône PDF cliquable';
    return $callbacks;
} );

function jwcct_get_pdf_icon_link( $value ) {
    // Le champ rapport_pdf peut contenir plusieurs pièces jointes (« 558,600 »).
    $ids = function_exists( 'gacct_report_ids' ) ? gacct_report_ids( $value ) : array();

    if ( empty( $ids ) ) {
        return '';
    }

    $icone = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path>
  <path d="M14 2v6h6"></path>
  <path fill="#e63946" stroke="none" d="M9 14h2v4H9zM13 14h1.5a1.5 1.5 0 010 3H13v1h-1v-4h1zm0 1v1h1.5a.5.5 0 000-1H13zM16 14h2v1h-1v.7h.8v1H17V18h-1v-4z"></path>
</svg>';

    // Les PDF vivent dans le coffre-fort : l'URL passe par l'endpoint
    // authentifié (cf. includes/gacct-reports.php), jamais par uploads.
    $html = '';

    foreach ( $ids as $index => $attachment_id ) {
        $url = function_exists( 'gacct_report_url_for_attachment' ) ? gacct_report_url_for_attachment( $attachment_id ) : '';

        if ( ! $url ) {
            continue;
        }

        $html .= sprintf(
            '<a href="%1$s" class="icon-btn icon-btn-pdf" target="_blank" rel="noopener" title="%2$s">%3$s</a>',
            esc_url( $url ),
            esc_attr( $index > 0
                ? sprintf( __( 'Télécharger le rapport (%d)', 'gestion-atelier-cct' ), $index + 1 )
                : __( 'Télécharger le rapport', 'gestion-atelier-cct' ) ),
            $icone
        );
    }

    return $html;
}


/* =============================================================================
 *  CALLBACK : ICÔNE SUIVI TRANSPORTEUR
 * ============================================================================= */

add_filter( 'jet-engine/listings/allowed-callbacks', function( $callbacks ) {
    $callbacks['jwcct_get_tracking_icon_link'] = 'JWCCT: Icône Suivi Transporteur';
    return $callbacks;
} );

function jwcct_get_tracking_icon_link( $value ) {
    // Si l'URL est vide, on ne retourne rien
    if ( empty( $value ) ) {
        return '';
    }

    // On s'assure que c'est une URL valide
    $url = esc_url( $value );

    // Retourne l'icône de livraison dans le lien
    return sprintf(
        '<a href="%s" class="icon-btn" target="_blank" rel="noopener" title="Suivre mon colis">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M3 7h11v9H3zM14 10h4l3 3v3h-7zM7.5 19a2 2 0 100-4 2 2 0 000 4zM17.5 19a2 2 0 100-4 2 2 0 000 4z"></path>
</svg>
        </a>',
        $url
    );
}

/* =============================================================================
 *  CALLBACK : TITRES DES PRESTATIONS WOOCOMMERCE
 * ============================================================================= */

add_filter( 'jet-engine/listings/allowed-callbacks', function( $callbacks ) {
    $callbacks['jwcct_get_wc_product_titles_from_ids'] = 'JWCCT: Titres des Prestations (WC)';
    return $callbacks;
} );

function jwcct_get_wc_product_titles_from_ids( $value ) {
    // 1. On sécurise l'entrée (vide, null, etc.)
    if ( empty( $value ) ) {
        return '';
    }

    // 2. On s'assure d'avoir un tableau d'IDs
    // Si la donnée arrive sous forme de chaîne (ex: "361, 362") ou de sérialisation
    if ( ! is_array( $value ) ) {
        // Tente de désérialiser si c'est le cas, sinon on explose par virgule
        $maybe_array = maybe_unserialize( $value );
        if ( is_array( $maybe_array ) ) {
            $ids = $maybe_array;
        } else {
            $ids = explode( ',', (string) $value );
        }
    } else {
        $ids = $value;
    }

    // On nettoie le tableau pour ne garder que des entiers
    $ids = array_map( 'absint', $ids );
    $ids = array_filter( $ids ); // Supprime les 0 et les vides

    if ( empty( $ids ) ) {
        return '';
    }

    // 3. On récupère les titres des produits WooCommerce correspondants
    $titles = array();
    foreach ( $ids as $product_id ) {
        $product = wc_get_product( $product_id );
        if ( $product ) {
            $titles[] = $product->get_name();
        }
    }

    if ( empty( $titles ) ) {
        return '';
    }

    // 4. On formate la sortie (ici, une liste HTML simple avec des puces)
    $html  = '<ul class="jwcct-prestations-list" style="margin: 0; padding-left: 0px;">';
    foreach ( $titles as $title ) {
        $html .= sprintf( '<li>%s</li>', esc_html( $title ) );
    }
    $html .= '</ul>';

    return $html;
}


/* =============================================================================
 *  EMAILS WOOCOMMERCE : ENCART « VOTRE RÉVISION »
 *
 *  Injecté au-dessus du tableau de commande des e-mails client. Il porte les trois
 *  informations que le client cherche en priorité :
 *    - la date de prise en charge en atelier ;
 *    - la date limite d'arrivée du colis (veille du créneau) ;
 *    - s'il reste un virement à faire : montant, référence et date limite.
 *
 *  Les dates et échéances viennent de `gacct_conf_data()`, qui alimente déjà la page
 *  de confirmation : une seule source de calcul pour les deux supports.
 * ============================================================================= */

add_action( 'woocommerce_email_before_order_table', 'jwcct_add_revision_date_to_email', 10, 4 );

/**
 * E-mails qui reçoivent l'encart.
 *
 * Liste blanche volontaire : sur une commande annulée ou remboursée, annoncer une
 * date d'atelier et réclamer un colis n'a aucun sens.
 *
 * @return string[]
 */
function jwcct_email_ids_with_revision_block() {
    return apply_filters( 'jwcct_email_ids_with_revision_block', [
        'customer_on_hold_order',
        'customer_processing_order',
        'customer_invoice',
        'customer_note',
    ] );
}

function jwcct_add_revision_date_to_email( $order, $sent_to_admin, $plain_text, $email ) {

    // Uniquement les e-mails client, et seulement ceux de la liste blanche.
    if ( $sent_to_admin || ! $order instanceof WC_Order ) {
        return;
    }

    $email_id = $email instanceof WC_Email ? $email->id : '';

    if ( $email_id && ! in_array( $email_id, jwcct_email_ids_with_revision_block(), true ) ) {
        return;
    }

    $occupation_id = absint( $order->get_meta( JWCCT_ORDER_OCCUPATION_ID ) );

    if ( ! $occupation_id ) {
        return;
    }

    $occupation = jwcct_get_cct_item( JWCCT_CCT_OCCUPATION, $occupation_id );

    if ( ! is_array( $occupation ) || empty( $occupation['date_reservee'] ) ) {
        return;
    }

    // Le matériel est-il déjà arrivé ? Au-delà de l'état 1, la date limite du colis
    // n'a plus lieu d'être rappelée.
    $etat        = null;
    $revision_id = absint( $order->get_meta( JWCCT_ORDER_REVISION_ID ) );

    if ( $revision_id ) {
        $revision = jwcct_get_cct_item( JWCCT_CCT_REVISION, $revision_id );

        if ( is_array( $revision ) && isset( $revision['etat_de_la_commande'] ) ) {
            $etat = (int) $revision['etat_de_la_commande'];
        }
    }

    $materiel_attendu = ( null === $etat || $etat <= 1 );

    // Dates et échéances : mêmes calculs que la page de confirmation.
    $data          = function_exists( 'gacct_conf_data' ) ? gacct_conf_data( $order ) : [];
    $date_atelier  = wp_date( 'l j F Y', absint( $occupation['date_reservee'] ) );
    $date_colis    = ! empty( $data['parcel_label'] ) ? $data['parcel_label'] : '';
    $attend_vir    = function_exists( 'gacct_pay_order_awaits_transfer' ) && gacct_pay_order_awaits_transfer( $order );

    if ( $plain_text ) {
        echo "--- VOTRE RÉVISION ---\n";
        echo 'Prise en charge en atelier : ' . esc_html( $date_atelier ) . "\n";

        if ( $date_colis && $materiel_attendu ) {
            echo 'Votre colis doit nous parvenir avant le : ' . esc_html( $date_colis ) . "\n";
        }

        if ( $attend_vir ) {
            echo 'Virement à effectuer : ' . esc_html( wp_strip_all_tags( wc_price( $data['deposit'] ) ) )
                . ' — référence ' . esc_html( $data['reference'] )
                . ' — avant le ' . esc_html( $data['deadline_label'] ) . "\n";
            echo "Passé ce délai, la commande est annulée et le créneau remis en ligne.\n";
        }

        if ( $date_colis && $materiel_attendu ) {
            echo "L'acompte réserve votre créneau : sans réception du matériel la veille au soir, le créneau est libéré et l'acompte reste acquis. Un imprévu ? Prévenez-nous avant la date.\n";
        }

        echo "\n";
        return;
    }

    $lignes = [
        [
            __( 'Prise en charge en atelier', 'gestion-atelier-cct' ),
            esc_html( ucfirst( $date_atelier ) ),
        ],
    ];

    if ( $date_colis && $materiel_attendu ) {
        $lignes[] = [
            __( 'Votre colis doit arriver avant le', 'gestion-atelier-cct' ),
            esc_html( $date_colis ),
        ];
    }

    if ( $attend_vir ) {
        $lignes[] = [
            __( 'Virement à effectuer', 'gestion-atelier-cct' ),
            sprintf(
                /* translators: 1: montant, 2: référence de commande */
                esc_html__( '%1$s, avec la référence %2$s en libellé', 'gestion-atelier-cct' ),
                '<strong>' . esc_html( wp_strip_all_tags( wc_price( $data['deposit'] ) ) ) . '</strong>',
                '<strong>' . esc_html( $data['reference'] ) . '</strong>'
            ),
        ];
        $lignes[] = [
            __( 'Date limite du virement', 'gestion-atelier-cct' ),
            sprintf(
                /* translators: 1: date limite, 2: nombre de jours restants */
                esc_html__( '%1$s (%2$s)', 'gestion-atelier-cct' ),
                '<strong>' . esc_html( $data['deadline_label'] ) . '</strong>',
                esc_html(
                    sprintf(
                        /* translators: %d: nombre de jours */
                        _n( 'il vous reste %d jour', 'il vous reste %d jours', (int) $data['days_remaining'], 'gestion-atelier-cct' ),
                        (int) $data['days_remaining']
                    )
                )
            ),
        ];
    }

    $accent = $attend_vir ? '#c2410c' : '#0056b3';
    $fond   = $attend_vir ? '#fff6ef' : '#f0f8ff';

    echo '<div style="margin-bottom:30px;padding:20px;background-color:' . esc_attr( $fond ) . ';border-left:4px solid ' . esc_attr( $accent ) . ';">';
    echo '<h2 style="color:' . esc_attr( $accent ) . ';margin:0 0 12px;font-size:18px;">'
        . esc_html__( 'Informations sur votre révision', 'gestion-atelier-cct' ) . '</h2>';
    echo '<table cellspacing="0" cellpadding="0" border="0" style="width:100%;border:0;">';

    foreach ( $lignes as $ligne ) {
        echo '<tr>'
            . '<td style="border:0;padding:0 12px 6px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">'
            . esc_html( $ligne[0] ) . '</td>'
            . '<td style="border:0;padding:0 0 6px;font-size:15px;color:#333;vertical-align:top;">'
            . $ligne[1] // phpcs:ignore WordPress.Security.EscapeOutput -- contenu construit et échappé ci-dessus.
            . '</td>'
            . '</tr>';
    }

    echo '</table>';

    if ( $attend_vir ) {
        echo '<p style="margin:12px 0 0;font-size:13px;color:#8a5a3b;">'
            . esc_html__( 'Passé ce délai, la commande est annulée et votre créneau est remis en ligne. Vous pouvez expédier votre matériel sans attendre l’encaissement.', 'gestion-atelier-cct' )
            . '</p>';
    }

    if ( $date_colis && $materiel_attendu ) {
        echo '<p style="margin:12px 0 0;font-size:13px;color:#666;">'
            . esc_html__( 'L’acompte réserve votre créneau : sans réception du matériel la veille au soir, le créneau est libéré et l’acompte reste acquis. Un imprévu d’expédition ? Prévenez-nous avant la date, nous en tiendrons compte.', 'gestion-atelier-cct' )
            . '</p>';
    }

    echo '</div>';
}


/* =============================================================================
 *  CALLBACK : TRACKER DE PROGRESSION (9 etats 0-8)
 *
 *  Une etape de frise par etat. Les etats 4 (devis a valider) et 5 (intervention
 *  a finir) n'existent que si un devis complementaire est entre en jeu : sans
 *  devis, la frise n'affiche que 7 etapes. Aucun numero interne cote client.
 * ============================================================================= */

add_filter( 'jet-engine/listings/allowed-callbacks', function( $callbacks ) {
    $callbacks['jwcct_render_order_status_tracker'] = 'JWCCT: Tracker de Progression';
    return $callbacks;
} );

/**
 * Configuration d'affichage des 9 etats.
 *
 * @return array<int,array{badge:string,progress:string,group:string,label:string,tip:string}>
 */
function jwcct_tracker_config() {
    return apply_filters( 'jwcct_tracker_config', [
        // 0 : rien n'est engage tant que le paiement n'est pas arrive. La classe
        // "zero" affiche le seul point de depart, aucune barre n'est remplie.
        0 => [
            'badge'    => 'action pulse',
            'progress' => 'action zero',
            'group'    => 'paiement',
            'label'    => 'En attente de paiement',
            'tip'      => '<strong>Action requise :</strong> finalisez le paiement de l\'acompte pour démarrer la procédure.'
        ],
        1 => [
            'badge'    => 'progress',
            'progress' => '',
            'group'    => 'reception',
            'label'    => 'En attente de réception',
            'tip'      => '<strong>Info :</strong> nous attendons la réception de votre matériel à l\'atelier.'
        ],
        2 => [
            'badge'    => 'progress',
            'progress' => '',
            'group'    => 'reception',
            'label'    => 'Voile réceptionnée',
            'tip'      => '<strong>Info :</strong> matériel reçu. L\'intervention est programmée selon le planning.'
        ],
        3 => [
            'badge'    => 'progress',
            'progress' => '',
            'group'    => 'intervention',
            'label'    => 'Intervention programmée',
            'tip'      => '<strong>Info :</strong> nos techniciens travaillent sur votre matériel.'
        ],
        4 => [
            'badge'    => 'action pulse',
            'progress' => 'action',
            'group'    => 'intervention',
            'label'    => 'Devis à valider',
            'tip'      => '<strong>Action requise :</strong> des travaux complémentaires sont nécessaires. Merci de valider ou de refuser le devis.'
        ],
        5 => [
            'badge'    => 'progress',
            'progress' => '',
            'group'    => 'intervention',
            'label'    => 'Intervention à finir',
            'tip'      => '<strong>Info :</strong> votre réponse est bien enregistrée, l\'atelier termine l\'intervention.'
        ],
        6 => [
            'badge'    => 'action pulse',
            'progress' => 'action',
            'group'    => 'intervention',
            'label'    => 'Solde à régler',
            'tip'      => '<strong>Action requise :</strong> l\'intervention est terminée. Réglez le solde pour que votre matériel reparte.'
        ],
        7 => [
            'badge'    => 'done',
            'progress' => '',
            'group'    => 'retour',
            'label'    => 'Révision terminée',
            'tip'      => '<strong>Info :</strong> révision terminée, votre rapport est disponible. Nous préparons le retour de votre matériel.'
        ],
        8 => [
            'badge'    => 'done',
            'progress' => 'done-all',
            'group'    => 'retour',
            'label'    => 'Matériel réexpédié',
            'tip'      => 'Votre matériel est reparti vers vous. Rapport disponible au téléchargement.'
        ],
    ] );
}

/**
 * Libelles des 4 groupes de la frise (une etiquette pour plusieurs etapes).
 */
function jwcct_tracker_groups() {
    return apply_filters( 'jwcct_tracker_groups', [
        'paiement'     => __( 'Paiement', 'gestion-atelier-cct' ),
        'reception'    => __( 'Réception', 'gestion-atelier-cct' ),
        'intervention' => __( 'Intervention', 'gestion-atelier-cct' ),
        'retour'       => __( 'Retour', 'gestion-atelier-cct' ),
    ] );
}

/**
 * Commande courante d'un listing JetEngine dont l'objet est une ligne CCT
 * revision (brute ou jointe). Retourne 0 si rien n'est identifiable.
 */
function jwcct_tracker_current_order_id() {
    if ( ! function_exists( 'jet_engine' ) ) {
        return 0;
    }

    $item = jet_engine()->listings->data->get_current_object();

    if ( ! is_object( $item ) ) {
        return 0;
    }

    if ( ! empty( $item->order_id ) ) {
        return absint( $item->order_id );
    }

    $rev_id = absint( $item->revision_id ?? ( $item->_ID ?? 0 ) );

    if ( ! $rev_id ) {
        return 0;
    }

    global $wpdb;

    return absint( $wpdb->get_var( $wpdb->prepare(
        "SELECT order_id FROM {$wpdb->prefix}jet_cct_revision WHERE _ID = %d LIMIT 1",
        $rev_id
    ) ) );
}

/**
 * Tracker de progression du client.
 *
 * @param mixed $value    Valeur du champ etat_de_la_commande (callback JetEngine).
 * @param int   $order_id Commande liee, si l'appelant la connait deja (page
 *                        commande, tableau de bord). Sinon elle est resolue
 *                        depuis l'objet courant du listing.
 */
function jwcct_render_order_status_tracker( $value, $order_id = 0 ) {
    if ( $value === '' || $value === null ) return '';

    $config = jwcct_tracker_config();

    if ( ! isset( $config[ $value ] ) ) return $value;

    $state = absint( $value );
    $s     = $config[ $state ];

    $order_id = absint( $order_id );

    if ( ! $order_id ) {
        $order_id = jwcct_tracker_current_order_id();
    }

    $order = ( $order_id && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : false;

    // Dossier sans devis complementaire : les etapes 4 et 5 ne sont jamais
    // traversees, elles disparaissent de la frise (7 etapes au lieu de 9).
    $with_quote = function_exists( 'gacct_quote_has_quote_context' )
        ? gacct_quote_has_quote_context( $order, $state )
        : true;

    $visible = [];

    foreach ( array_keys( $config ) as $step_state ) {
        if ( ! $with_quote && in_array( $step_state, [ 4, 5 ], true ) ) {
            continue;
        }
        $visible[] = $step_state;
    }

    $position = array_search( $state, $visible, true );

    if ( false === $position ) {
        $position = 0;
    }

    // Frise : une barre par etape visible. L'etat 0 n'en remplit aucune.
    $steps_html = '';

    foreach ( $visible as $i => $step_state ) {
        $class = '';

        if ( 'done-all' === $s['progress'] ) {
            $class = 'done';
        } elseif ( 0 === $state ) {
            $class = '';
        } elseif ( $i < $position ) {
            $class = 'done';
        } elseif ( $i === $position ) {
            $class = 'current';
        }

        $steps_html .= sprintf( '<div class="progress-step %s"></div>', $class );
    }

    // Etiquettes : 4 groupes, chacun large de son nombre d'etapes visibles
    // (le flex inline garde l'alignement quel que soit le nombre d'etapes).
    $labels_html = '';
    $weights     = [];

    foreach ( $visible as $step_state ) {
        $group = $config[ $step_state ]['group'];
        $weights[ $group ] = ( $weights[ $group ] ?? 0 ) + 1;
    }

    foreach ( jwcct_tracker_groups() as $group => $group_label ) {
        if ( empty( $weights[ $group ] ) ) {
            continue;
        }
        $labels_html .= sprintf(
            '<span style="flex:%d">%s</span>',
            (int) $weights[ $group ],
            esc_html( $group_label )
        );
    }

    // Bouton « Imprimer le bon d'intervention » (etats 0-1 : tant que le colis
    // n'est pas receptionne).
    $workorder_html = '';

    if ( $state <= 1 && $order && function_exists( 'gacct_wo_print_url' )
        && ! $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
        $workorder_html = sprintf(
            '<a class="gacct-workorder-print" href="%s" target="_blank" rel="noopener">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><rect x="6" y="14" width="12" height="8"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/></svg>
                <span>%s</span>
            </a>',
            esc_url( gacct_wo_print_url( $order ) ),
            esc_html__( 'Imprimer le bon d\'intervention', 'gestion-atelier-cct' )
        );
    }

    // Etat 5 : le libelle precise la decision rendue sur le devis.
    $label = $s['label'];

    if ( 5 === $state && function_exists( 'gacct_state5_suffix' ) ) {
        $label .= gacct_state5_suffix( $order );
    }

    return sprintf(
        '<div class="status-stack">
            <div class="status-tip">%s</div>
            <span class="badge %s">%s</span>
            <div class="progress %s">%s</div>
            <div class="progress-labels">%s</div>
            %s
        </div>',
        $s['tip'],
        esc_attr( $s['badge'] ),
        esc_html( $label ),
        esc_attr( $s['progress'] ),
        $steps_html,
        $labels_html,
        $workorder_html
    );
}


/* =============================================================================
 *  CALLBACK : LIEN COMMANDE WOOCOMMERCE
 * ============================================================================= */

add_filter( 'jet-engine/listings/allowed-callbacks', function( $callbacks ) {
    $callbacks['jwcct_render_wc_order_link'] = 'JWCCT: Lien Commande WC';
    return $callbacks;
} );

function jwcct_render_wc_order_link( $value ) {
    if ( empty( $value ) || ! function_exists( 'wc_get_order' ) ) {
        return $value;
    }

    $order = wc_get_order( $value );
    if ( ! $order ) {
        return $value;
    }

    $order_number = $order->get_order_number();
    $date_created = $order->get_date_created();

    // Formatage de la date : 3 mai 2026 · 15:43
    $formatted_date = date_i18n( 'j F Y', $date_created->getTimestamp() );
    $formatted_time = $date_created->date( 'H:i' );
    $view_url = $order->get_view_order_url();

    return sprintf(
        '<a href="%s" class="cmd-link">
            <span class="cmd-id">#%s</span>
            <span class="cmd-time">%s · %s</span>
        </a>',
        esc_url( $view_url ),
        esc_html( $order_number ),
        esc_html( $formatted_date ),
        esc_html( $formatted_time )
    );
}


/* =============================================================================
 *  COULEURS DE VOILE : SOURCE UNIQUE
 * ============================================================================= */

/**
 * Palette des couleurs de voile : nom affiché => teintes de la vignette.
 *
 * Source unique, consommee par :
 *  - le selecteur du formulaire de demande d'intervention (localise en JS) ;
 *  - la vignette de l'espace client (jwcct_render_equipment_info).
 * Ajouter une couleur ICI la rend disponible des deux cotes.
 *
 * L'ordre du tableau est l'ordre d'affichage des pastilles.
 * `base` sert aux vignettes 2 et 3 couleurs, `light` au degrade mono-couleur.
 *
 * @return array<string,array{base:string,light:string}>
 */
function gacct_couleurs_voile() {
    return apply_filters( 'gacct_couleurs_voile', array(
        'rouge'      => array( 'base' => '#d33333', 'light' => '#ff5555' ),
        'orange'     => array( 'base' => '#f97316', 'light' => '#fb923c' ),
        'jaune'      => array( 'base' => '#eab308', 'light' => '#fde047' ),
        'jaune fluo' => array( 'base' => '#e9f000', 'light' => '#f7ff5c' ),
        'vert'       => array( 'base' => '#22c55e', 'light' => '#4ade80' ),
        'vert fluo'  => array( 'base' => '#4cf50a', 'light' => '#9dff6b' ),
        'turquoise'  => array( 'base' => '#0d9488', 'light' => '#5eead4' ),
        'cyan'       => array( 'base' => '#06b6d4', 'light' => '#67e8f9' ),
        'bleu'       => array( 'base' => '#2c8be0', 'light' => '#6cc6ff' ),
        'violet'     => array( 'base' => '#a855f7', 'light' => '#c084fc' ),
        'rose'       => array( 'base' => '#ec4899', 'light' => '#f472b6' ),
        'rose fluo'  => array( 'base' => '#ff2d9b', 'light' => '#ff7ac2' ),
        'blanc'      => array( 'base' => '#f5f5f5', 'light' => '#ffffff' ),
        'gris'       => array( 'base' => '#9ca3af', 'light' => '#d1d5db' ),
        'noir'       => array( 'base' => '#1f2937', 'light' => '#4b5563' ),
    ) );
}

/**
 * Extrait jusqu'a 3 couleurs connues d'une saisie libre.
 *
 * Les dossiers anterieurs au selecteur de couleurs contiennent du texte libre
 * ("bleu blanc", "Rouge, vert, bleu,", "rouge/ bleu") : on reste compatible.
 * Les noms composes ("jaune fluo") sont cherches EN PREMIER, sinon le decoupage
 * sur les espaces les reduirait a leur premier mot ("jaune").
 *
 * @param string $saisie Valeur brute de la colonne `couleur`.
 * @return array<int,array{base:string,light:string}> 0 a 3 couleurs, dans l'ordre de saisie.
 */
function gacct_extraire_couleurs( $saisie ) {
    $palette = gacct_couleurs_voile();
    $texte   = ' ' . strtolower( remove_accents( (string) $saisie ) ) . ' ';
    $trouve  = array();

    // 1. Noms composes, du plus long au plus court, retires du texte au passage.
    $composes = array_filter( array_keys( $palette ), function ( $nom ) {
        return false !== strpos( $nom, ' ' );
    } );
    usort( $composes, function ( $a, $b ) {
        return strlen( $b ) - strlen( $a );
    } );

    foreach ( $composes as $nom ) {
        $position = strpos( $texte, $nom );
        if ( false !== $position ) {
            $trouve[ $position ] = $palette[ $nom ];
            $texte = str_replace( $nom, ' ', $texte );
        }
    }

    // 2. Noms simples sur ce qu'il reste, en conservant leur position d'origine.
    $offset = 0;
    foreach ( preg_split( '/[\s,\/\+\-]+/', trim( $texte ) ) as $mot ) {
        if ( '' !== $mot && isset( $palette[ $mot ] ) ) {
            $trouve[ strpos( $texte, $mot, $offset ) ] = $palette[ $mot ];
            $offset = strpos( $texte, $mot, $offset ) + strlen( $mot );
        }
    }

    ksort( $trouve );

    return array_slice( array_values( $trouve ), 0, 3 );
}

/**
 * Degrade CSS correspondant a 0, 1, 2 ou 3 couleurs.
 *
 * `background-image` + `background-clip: padding-box`, PAS le raccourci
 * `background:` : ce style part en inline sur les vignettes `.voile-swatch`
 * (bordure semi-transparente arrondie), et le raccourci reinitialiserait
 * `background-clip` a border-box — le degrade file alors sous la bordure et
 * dessine un halo sombre dans les angles (bug corrige le 28/07/2026).
 *
 * @param array<int,array{base:string,light:string}> $couleurs
 * @return string Declarations CSS `background-…;` pretes pour un style inline.
 */
function gacct_degrade_couleurs( array $couleurs ) {
    $clip = ' background-clip: padding-box;';
    switch ( count( $couleurs ) ) {
        case 1:
            return sprintf( 'background-image: linear-gradient(135deg, %s 0%%, %s 100%%);', $couleurs[0]['base'], $couleurs[0]['light'] ) . $clip;
        case 2:
            return sprintf( 'background-image: linear-gradient(135deg, %s 50%%, %s 50%%);', $couleurs[0]['base'], $couleurs[1]['base'] ) . $clip;
        case 3:
            return sprintf(
                'background-image: linear-gradient(135deg, %s 33.33%%, %s 33.33%% 66.66%%, %s 66.66%%);',
                $couleurs[0]['base'], $couleurs[1]['base'], $couleurs[2]['base']
            ) . $clip;
        default:
            return 'background-color: #e5e7eb;' . $clip;
    }
}


/* =============================================================================
 *  CALLBACK : DÉTAILS MATÉRIEL & SWATCH COULEUR
 * ============================================================================= */

add_filter( 'jet-engine/listings/allowed-callbacks', function( $callbacks ) {
    $callbacks['jwcct_render_equipment_info'] = 'JWCCT: Détails Matériel & Swatch';
    return $callbacks;
} );

function jwcct_render_equipment_info( $cct_id ) {
    if ( empty( $cct_id ) ) return '';

    global $wpdb;
    $table = $wpdb->prefix . 'jet_cct_revision';
    $data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE _ID = %d", $cct_id ), ARRAY_A );

    if ( ! $data ) return '';

    $marque = ! empty( $data['marque'] ) ? $data['marque'] : '';
    $modele = ! empty( $data['modele'] ) ? $data['modele'] : '';
    $couleur_brute = ! empty( $data['couleur'] ) ? $data['couleur'] : '';
    $taille = ! empty( $data['taille'] ) ? $data['taille'] : '';
    $sn = ! empty( $data['numero_de_serie'] ) ? 'S/N : ' . $data['numero_de_serie'] : '';

    // Palette et extraction : voir gacct_couleurs_voile() / gacct_extraire_couleurs().
    $gradient = gacct_degrade_couleurs( gacct_extraire_couleurs( $couleur_brute ) );

    // Suppression de la couleur brute dans les métadonnées affichées
    $meta_parts = array_filter([$taille, $sn]);
    $meta_string = implode(' · ', $meta_parts);

    return sprintf(
        '<div class="voile-stack">
            <div class="voile-swatch" style="%s"></div>
            <div class="voile-meta">
                <div class="voile-brand">%s %s</div>
                <div class="voile-model">%s</div>
            </div>
        </div>',
        $gradient,
        esc_html($marque),
        esc_html($modele),
        esc_html($meta_string)
    );
}

/**
 * Pré-sélection d'une prestation sur la page demande-intervention via ?svc=<ID produit>.
 * Les cases du formulaire JFB (revisions_controle, pliages_secours, suspentes_travaux…)
 * ont pour valeur l'ID du produit WooCommerce : on coche celle(s) qui correspond(ent)
 * et on déclenche l'événement change pour que les calculs JFB se mettent à jour.
 */
add_action( 'wp_footer', 'jwcct_preselect_service_from_url' );

function jwcct_preselect_service_from_url() {
	if ( empty( $_GET['svc'] ) || ! is_page( 'demande-intervention' ) ) {
		return;
	}
	$ids = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['svc'] ) ) ) ) );
	if ( empty( $ids ) ) {
		return;
	}
	?>
	<script>
	document.addEventListener( 'DOMContentLoaded', function () {
		var ids = <?php echo wp_json_encode( array_values( array_map( 'strval', $ids ) ) ); ?>;
		ids.forEach( function ( id ) {
			var input = document.querySelector( '.jet-form-builder input[type="checkbox"][value="' + id + '"], .jet-form-builder input[type="radio"][value="' + id + '"]' );
			if ( input && ! input.checked ) {
				input.checked = true;
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				input.dispatchEvent( new Event( 'input',  { bubbles: true } ) );
			}
		} );
	} );
	</script>
	<?php
}

/**
 * Désactivation de la boutique WooCommerce classique.
 *
 * Les produits ne sont présentés qu'à travers le listing JetEngine de la page /controles/
 * (section « nos prestations de contrôle »). On renvoie donc une vraie 404 sur :
 *   - la page boutique (is_shop),
 *   - les fiches produit individuelles (is_product, base /produit/),
 *   - les archives de catégories / étiquettes de produit (is_product_taxonomy : /c/, /e/).
 *
 * Le tunnel d'achat n'utilise PAS ces vues : le panier est rempli par le formulaire JFB
 * (ajouter_configurateur_panier) et les boutons « Commander » pointent vers
 * /demande-intervention/?svc=<ID>. Panier, checkout, « mon compte » et order-pay ne sont
 * ni is_shop ni is_product ni is_product_taxonomy : ils restent donc intacts.
 *
 * Réversible : supprimer ce hook réactive la boutique standard.
 */
add_action( 'template_redirect', 'jwcct_disable_woocommerce_shop', 0 );

function jwcct_disable_woocommerce_shop() {
	if ( is_admin() || ! function_exists( 'is_shop' ) ) {
		return;
	}
	if ( is_shop() || is_product() || is_product_taxonomy() ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}

/**
 * Exclusion des produits du sitemap natif WordPress (wp-sitemap.xml).
 *
 * La boutique étant désactivée (cf. jwcct_disable_woocommerce_shop), il ne faut pas exposer
 * les produits ni leurs taxonomies dans le sitemap : sinon, une fois le site en production
 * (blog_public = 1, le sitemap natif se réactive tout seul), ces URLs — qui renvoient une 404 —
 * y figureraient. On retire donc le type `product` et TOUTES ses taxonomies (product_cat,
 * product_tag, product_brand, product_shipping_class, attributs pa_*).
 */
add_filter( 'wp_sitemaps_post_types', 'jwcct_sitemap_remove_products' );

function jwcct_sitemap_remove_products( $post_types ) {
	unset( $post_types['product'] );
	return $post_types;
}

add_filter( 'wp_sitemaps_taxonomies', 'jwcct_sitemap_remove_product_taxonomies' );

function jwcct_sitemap_remove_product_taxonomies( $taxonomies ) {
	foreach ( get_object_taxonomies( 'product' ) as $tax ) {
		unset( $taxonomies[ $tax ] );
	}
	return $taxonomies;
}

/**
 * Nettoyage du sitemap natif : retrait des CPT techniques (templates & builders Jet /
 * Elementor). Ils sont enregistrés « public » mais ne sont que des gabarits internes
 * (headers/footers, listings WooCommerce, boutons flottants, menus) — aucune URL de contenu
 * à exposer. Liste explicite (blocklist) : ajouter ici tout nouveau CPT technique repéré.
 */
add_filter( 'wp_sitemaps_post_types', 'jwcct_sitemap_remove_technical_cpts' );

function jwcct_sitemap_remove_technical_cpts( $post_types ) {
	$techniques = array( 'jet-menu', 'jet-woo-builder', 'jet-theme-core', 'e-floating-buttons' );
	foreach ( $techniques as $cpt ) {
		unset( $post_types[ $cpt ] );
	}
	return $post_types;
}


/* =============================================================================
 *  SOUS-PAGE « MON MATÉRIEL » (espace client, Profile Builder JetEngine)
 *  Query 22 / Dynamic Table 23 : une ligne par voile distincte (numéro de série
 *  normalisé), avec historique des révisions et bouton « Recommander ».
 * ============================================================================= */

/**
 * URL de la sous-page « Détail de la commande » de l'espace client (Profile Builder).
 *
 * Reprend exactement le mécanisme JetEngine utilisé pour construire les URLs de
 * sous-pages du compte (cf. `Settings::get_subpage_url()`), avec repli sur l'URL
 * classique si le module Profile Builder n'est pas disponible pour une raison
 * quelconque (sécurité : ne jamais fatal-error dans un callback de listing).
 *
 * @param string $slug Slug de la sous-page cible.
 * @return string URL absolue, sans slash final, prête à recevoir des paramètres GET.
 */
function jwcct_get_compte_subpage_url( $slug ) {
	$url = false;

	if ( class_exists( '\Jet_Engine\Modules\Profile_Builder\Settings' ) ) {
		$settings = new \Jet_Engine\Modules\Profile_Builder\Settings();
		$url      = $settings->get_subpage_url( $slug );
	}

	if ( ! $url ) {
		$url = trailingslashit( home_url( '/mon-compte/' . $slug ) );
	}

	return untrailingslashit( $url );
}

add_filter( 'jet-engine/listings/allowed-callbacks', function( $callbacks ) {
	$callbacks['jwcct_render_materiel_client_info']  = 'JWCCT: Matériel client (marque/modèle/S-N)';
	$callbacks['jwcct_render_materiel_historique']    = 'JWCCT: Historique des révisions (liens)';
	$callbacks['jwcct_render_recommander_bouton']     = 'JWCCT: Bouton Recommander une révision';
	$callbacks['jwcct_render_marque_libelle']         = 'JWCCT: Libellé de la marque (glossaire)';
	$callbacks['jwcct_render_couleur_swatch']         = 'JWCCT: Vignette couleur de voile';
	return $callbacks;
} );

/**
 * Colonne « Couleur » du tableau Mon Matériel : vignette dégradée identique à
 * celle de « Mes demandes d'interventions » (classe `.voile-swatch`, stylée dans
 * le custom CSS des templates Elementor 521 et 1623), à partir de la saisie
 * brute (« rouge, noir »). Le libellé textuel reste accessible en title.
 *
 * @param string $couleur Valeur brute de la colonne `couleur`.
 * @return string HTML.
 */
function jwcct_render_couleur_swatch( $couleur ) {
	$couleur  = (string) $couleur;
	$gradient = gacct_degrade_couleurs( gacct_extraire_couleurs( $couleur ) );

	return sprintf(
		'<div class="voile-swatch" style="%s" title="%s"></div>',
		esc_attr( $gradient ),
		esc_attr( $couleur )
	);
}

/**
 * Colonne « Marque » du tableau Mon Matériel : le CCT stocke le slug du
 * glossaire JetEngine « Marque » (id 2), ex. « gin-gliders » — on affiche le
 * libellé humain (« Gin Gliders »). Repli : slug capitalisé si absent du
 * glossaire (marque saisie avant une mise à jour de la liste, par exemple).
 *
 * @param string $slug Slug de marque tel que stocké dans le CCT revision.
 * @return string Libellé de la marque.
 */
function jwcct_render_marque_libelle( $slug ) {
	static $libelles = null;

	if ( null === $libelles ) {
		$libelles = array();
		$row      = $GLOBALS['wpdb']->get_var(
			"SELECT meta_fields FROM {$GLOBALS['wpdb']->prefix}jet_post_types WHERE id = 2 AND status = 'glossary'"
		);
		foreach ( (array) maybe_unserialize( (string) $row ) as $entree ) {
			if ( isset( $entree['value'], $entree['label'] ) ) {
				$libelles[ $entree['value'] ] = $entree['label'];
			}
		}
	}

	$slug = trim( (string) $slug );

	return esc_html( $libelles[ $slug ] ?? ucfirst( $slug ) );
}

/**
 * Colonne « Numéro de série » du tableau Mon Matériel : affiche le numéro de
 * série BRUT de la révision la plus récente du groupe (colonne
 * `num_serie_norm` de la query 22 — nom conservé pour ne pas casser la config
 * de la Dynamic Table 23, mais la clé de regroupement des révisions d'une même
 * voile n'est plus le numéro de série ; voir la GROUP BY de la query 22 :
 * marque + modèle normalisé + taille normalisée + signature couleurs
 * insensible à l'ordre). Tiret cadratin si la voile n'a pas de numéro de série.
 *
 * @param string $num_serie_norm Numéro de série brut de la révision la plus récente.
 * @return string HTML.
 */
function jwcct_render_materiel_client_info( $num_serie_norm ) {
	return sprintf(
		'<div class="voile-model">S/N : %s</div>',
		esc_html( $num_serie_norm ?: '—' )
	);
}

/**
 * Colonne « Historique » du tableau Mon Matériel.
 *
 * Transforme la chaîne `historique` produite par la query 22
 * (GROUP_CONCAT de "revision_id|timestamp_unix|order_id", séparateur `;;`)
 * en une liste de liens datés vers la sous-page « Détail de la commande »
 * (paramètre GET `order_id`, et `revision_id` en complément pour les dossiers
 * sans commande liée — ex. données de démonstration).
 *
 * @param string $historique Valeur brute de la colonne `historique`.
 * @return string HTML (liste de liens).
 */
function jwcct_render_materiel_historique( $historique ) {
	if ( empty( $historique ) ) {
		return '';
	}

	$entrees = explode( ';;', $historique );
	$liens   = array();

	foreach ( $entrees as $entree ) {
		$parts = explode( '|', $entree );
		if ( count( $parts ) < 3 ) {
			continue;
		}
		list( $revision_id, $timestamp, $order_id ) = $parts;

		$formatted_date = is_numeric( $timestamp ) ? date_i18n( 'j F Y', (int) $timestamp ) : '';

		// Détail = endpoint WooCommerce view-order (l'ancienne sous-page
		// « detail-commande » n'existe plus). Sans commande liée : date seule.
		$order = ( (int) $order_id > 0 && function_exists( 'wc_get_order' ) ) ? wc_get_order( (int) $order_id ) : false;

		if ( $order ) {
			$liens[] = sprintf(
				'<a href="%s" class="cmd-link"><span class="cmd-time">%s</span></a>',
				esc_url( $order->get_view_order_url() ),
				esc_html( $formatted_date )
			);
		} else {
			$liens[] = sprintf(
				'<span class="cmd-link"><span class="cmd-time">%s</span></span>',
				esc_html( $formatted_date )
			);
		}
	}

	if ( empty( $liens ) ) {
		return '';
	}

	return '<div class="historique-revisions">' . implode( '', $liens ) . '</div>';
}

/**
 * Colonne « Recommander » du tableau Mon Matériel : bouton vers le formulaire de
 * demande d'intervention, pré-rempli via le paramètre GET `remat` (identifiant de
 * la révision la plus récente pour cette voile). Nom de paramètre imposé par un
 * autre chantier : NE PAS renommer.
 *
 * @param int|string $derniere_revision_id ID de la révision la plus récente.
 * @return string HTML (bouton).
 */
function jwcct_render_recommander_bouton( $derniere_revision_id ) {
	if ( empty( $derniere_revision_id ) ) {
		return '';
	}

	$url = add_query_arg( 'remat', (int) $derniere_revision_id, home_url( '/demande-intervention/' ) );

	return sprintf(
		'<a href="%s" class="btn-recommander">Recommander une révision</a>',
		esc_url( $url )
	);
}
