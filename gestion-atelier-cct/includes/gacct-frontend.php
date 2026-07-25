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
 *  EMAILS WOOCOMMERCE : DATE DE PRISE EN CHARGE ATELIER
 * ============================================================================= */

add_action( 'woocommerce_email_before_order_table', 'jwcct_add_revision_date_to_email', 10, 4 );

function jwcct_add_revision_date_to_email( $order, $sent_to_admin, $plain_text, $email ) {

    // On ne l'ajoute que pour les e-mails envoyés au client (Commande en cours ou terminée)
    if ( $sent_to_admin || ! $order ) {
        return;
    }

    // Récupération de l'ID de l'occupation lié à la commande
    $occupation_id = $order->get_meta( '_jwcct_occupation_id' );

    if ( ! $occupation_id ) {
        return;
    }

    // On récupère les données du CCT Occupation via notre fonction helper
    $occupation = jwcct_get_cct_item( 'occupation_atelier', $occupation_id );

    if ( ! $occupation || empty( $occupation['date_reservee'] ) ) {
        return;
    }

    // Formatage de la date (ex: mardi 12 mai 2026)
    $timestamp = absint( $occupation['date_reservee'] );
    $date_formatee = wp_date( 'l d F Y', $timestamp );

    // Rendu du bloc dans l'e-mail
    if ( ! $plain_text ) {
        echo '<div style="margin-bottom: 30px; padding: 20px; background-color: #f0f8ff; border-left: 4px solid #0056b3;">';
        echo '<h2 style="color: #0056b3; margin-top: 0; font-size: 18px;">Informations sur votre révision</h2>';
        echo '<p style="margin: 0; font-size: 15px; color: #333;">La date de prise en charge en atelier de votre matériel est confirmée pour le : <strong>' . esc_html( ucfirst( $date_formatee ) ) . '</strong>.</p>';
        echo '</div>';
    } else {
        // Version texte brut pour les vieux clients mail
        echo "--- DATE DE RÉVISION ---\n";
        echo "La date de prise en charge en atelier est confirmée pour le : " . esc_html( $date_formatee ) . "\n\n";
    }
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
        0 => [
            'badge'    => 'warning',
            'progress' => 'action',
            'step'     => 1,
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
