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

add_shortcode( 'avatar_initiales', 'generer_avatar_initiales_utilisateur' );

function generer_avatar_initiales_utilisateur() {
    if ( ! is_user_logged_in() ) {
        return '';
    }

    $user = wp_get_current_user();
    $prenom = $user->user_firstname;
    $nom = $user->user_lastname;
    $initiales = '';

    if ( ! empty($prenom) && ! empty($nom) ) {
        $initiales = mb_substr($prenom, 0, 1) . mb_substr($nom, 0, 1);
    } else {
        $display_name = $user->display_name;
        $mots = explode(' ', $display_name);
        if ( count($mots) >= 2 ) {
            $initiales = mb_substr($mots[0], 0, 1) . mb_substr(end($mots), 0, 1);
        } else {
            $initiales = mb_substr($display_name, 0, 2);
        }
    }

    $initiales = mb_strtoupper($initiales);

    return '<div class="user-avatar">' . esc_html($initiales) . '</div>';
}

add_shortcode( 'nom_complet_facturation', 'afficher_nom_complet_facturation_utilisateur' );

function afficher_nom_complet_facturation_utilisateur() {
    if ( ! is_user_logged_in() ) {
        return '';
    }

    $user_id = get_current_user_id();
    $prenom = get_user_meta($user_id, 'billing_first_name', true);
    $nom = get_user_meta($user_id, 'billing_last_name', true);

    return esc_html(trim($prenom . ' ' . $nom));
}


/* =============================================================================
 *  CALLBACK : ICÔNE PDF CLIQUABLE (SANS TEXTE)
 * ============================================================================= */

add_filter( 'jet-engine/listings/allowed-callbacks', function( $callbacks ) {
    $callbacks['jwcct_get_pdf_icon_link'] = 'JWCCT: Icône PDF cliquable';
    return $callbacks;
} );

function jwcct_get_pdf_icon_link( $value ) {
    // Si pas d'ID ou pas numérique, on ne retourne rien
    if ( empty( $value ) || ! is_numeric( $value ) ) {
        return '';
    }

    $url = wp_get_attachment_url( $value );

    if ( ! $url ) {
        return '';
    }

    // On retourne uniquement l'icône SVG dans le lien
    return sprintf(
        '<a href="%s" class="icon-btn icon-btn-pdf" download target="_blank" title="Télécharger le rapport">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path>
  <path d="M14 2v6h6"></path>
  <path fill="#e63946" stroke="none" d="M9 14h2v4H9zM13 14h1.5a1.5 1.5 0 010 3H13v1h-1v-4h1zm0 1v1h1.5a.5.5 0 000-1H13zM16 14h2v1h-1v.7h.8v1H17V18h-1v-4z"></path>
</svg>
        </a>',
        esc_url( $url )
    );
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
 *  CALLBACK : TRACKER DE PROGRESSION (8 etats 0-7)
 * ============================================================================= */

add_filter( 'jet-engine/listings/allowed-callbacks', function( $callbacks ) {
    $callbacks['jwcct_render_order_status_tracker'] = 'JWCCT: Tracker de Progression';
    return $callbacks;
} );

function jwcct_render_order_status_tracker( $value ) {
    if ( $value === '' || $value === null ) return '';

    $config = [
        // step 0 : rien n'est engage tant que le paiement n'est pas arrive. Aucune barre
        // n'est remplie (la boucle ci-dessous ne pose ni "done" ni "current"), la classe
        // "zero" se contente d'afficher le point de depart.
        0 => [
            'badge'    => 'warning',
            'progress' => 'action zero',
            'step'     => 0,
            'label'    => 'En attente de paiement',
            'tip'      => '<strong>Action requise :</strong> finalisez le paiement de l\'acompte pour démarrer la procédure.'
        ],
        1 => [
            'badge'    => 'progress',
            'progress' => '',
            'step'     => 1,
            'label'    => 'En attente de réception',
            'tip'      => '<strong>Info :</strong> nous attendons la réception de votre matériel à l\'atelier.'
        ],
        2 => [
            'badge'    => 'progress',
            'progress' => '',
            'step'     => 2,
            'label'    => 'Voile Réceptionnée',
            'tip'      => '<strong>Info :</strong> matériel reçu. L\'intervention est programmée selon le planning.'
        ],
        3 => [
            'badge'    => 'action pulse',
            'progress' => 'action',
            'step'     => 3,
            'label'    => 'Nouveau devis à valider',
            'tip'      => '<strong>Action requise :</strong> des travaux complémentaires sont nécessaires. Merci de valider le devis.'
        ],
        4 => [
            'badge'    => 'progress',
            'progress' => '',
            'step'     => 3,
            'label'    => 'Devis validé',
            'tip'      => '<strong>Info :</strong> devis accepté. Nos techniciens sont en cours d\'intervention.'
        ],
        5 => [
            'badge'    => 'action pulse',
            'progress' => 'action',
            'step'     => 3,
            'label'    => 'Paiement final en attente',
            'tip'      => '<strong>Action requise :</strong> l\'intervention est terminée. Réglez le solde pour récupérer votre voile.'
        ],
        6 => [
            'badge'    => 'done',
            'progress' => '',
            'step'     => 4,
            'label'    => 'Paiement validé',
            'tip'      => '<strong>Info :</strong> paiement reçu. Votre matériel est en cours d\'expédition.'
        ],
        7 => [
            'badge'    => 'done',
            'progress' => 'done-all',
            'step'     => 4,
            'label'    => 'Révision terminée',
            'tip'      => 'Voile révisée et retournée. Rapport disponible au téléchargement.'
        ],
    ];

    if ( ! isset( $config[$value] ) ) return $value;
    $s = $config[$value];

    $steps_html = '';
    for ( $i = 1; $i <= 4; $i++ ) {
        $class = '';
        if ( $s['progress'] === 'done-all' ) {
            $class = 'done';
        } elseif ( $i < $s['step'] ) {
            $class = 'done';
        } elseif ( $i == $s['step'] ) {
            $class = 'current';
        }
        $steps_html .= sprintf( '<div class="progress-step %s"></div>', $class );
    }

    return sprintf(
        '<div class="status-stack">
            <div class="status-tip">%s</div>
            <span class="badge %s">%s</span>
            <div class="progress %s">%s</div>
            <div class="progress-labels">
                <span>Paiement</span><span>Réception</span><span>Intervention</span><span>Retour</span>
            </div>
        </div>',
        $s['tip'],
        esc_attr( $s['badge'] ),
        esc_html( $s['label'] ),
        esc_attr( $s['progress'] ),
        $steps_html
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
 * @param array<int,array{base:string,light:string}> $couleurs
 * @return string Declaration `background: …;`
 */
function gacct_degrade_couleurs( array $couleurs ) {
    switch ( count( $couleurs ) ) {
        case 1:
            return sprintf( 'background: linear-gradient(135deg, %s 0%%, %s 100%%);', $couleurs[0]['base'], $couleurs[0]['light'] );
        case 2:
            return sprintf( 'background: linear-gradient(135deg, %s 50%%, %s 50%%);', $couleurs[0]['base'], $couleurs[1]['base'] );
        case 3:
            return sprintf(
                'background: linear-gradient(135deg, %s 33.33%%, %s 33.33%% 66.66%%, %s 66.66%%);',
                $couleurs[0]['base'], $couleurs[1]['base'], $couleurs[2]['base']
            );
        default:
            return 'background: #e5e7eb;';
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
	return $callbacks;
} );

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
 * série normalisé (colonne `num_serie_norm` de la query 22) qui sert de clé de
 * regroupement des révisions d'une même voile.
 *
 * @param string $num_serie_norm Numéro de série normalisé (UPPER(TRIM(...))).
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

	$base_url = jwcct_get_compte_subpage_url( 'detail-commande' );
	$entrees  = explode( ';;', $historique );
	$liens    = array();

	foreach ( $entrees as $entree ) {
		$parts = explode( '|', $entree );
		if ( count( $parts ) < 3 ) {
			continue;
		}
		list( $revision_id, $timestamp, $order_id ) = $parts;

		$args = array( 'revision_id' => (int) $revision_id );
		if ( ! empty( $order_id ) && (int) $order_id > 0 ) {
			$args['order_id'] = (int) $order_id;
		}

		$url            = add_query_arg( $args, $base_url );
		$formatted_date = is_numeric( $timestamp ) ? date_i18n( 'j F Y', (int) $timestamp ) : '';

		$liens[] = sprintf(
			'<a href="%s" class="cmd-link"><span class="cmd-time">%s</span></a>',
			esc_url( $url ),
			esc_html( $formatted_date )
		);
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
