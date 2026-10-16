<?php
/**
 * Invitation à laisser un avis Google, 16/10/2026 (demande de Bastien).
 *
 * Encart ajouté sous l'e-mail d'état 7 (révision finie et payée) : une phrase
 * et un bouton vers la page « laisser un avis » de la fiche Google.
 *
 * Liens de pied de page réglables (ex. l'école Plaine Altitude), ajoutés à TOUS
 * les e-mails clients via `woocommerce_email_footer` ; les e-mails internes à
 * l'atelier en sont exclus (gacct_email_internal(), e-mails Woo non client).
 *
 * Le jeu de la roue (Cadeo) qu'Hervé joignait autrefois a été écarté le
 * 16/10/2026 par Bastien et Hervé : un cadeau en échange d'un avis est contraire
 * aux règles de Google et à l'obligation de loyauté des avis en ligne. Ne pas
 * réintroduire de contrepartie ici.
 *
 * Réglages : page Notifications (option `gacct_avis_settings`). Lien vide =
 * encart désactivé (cas d'AEROTECH).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GACCT_AVIS_OPT', 'gacct_avis_settings' );

/**
 * Réglages du module.
 *
 * @return array{enabled:bool,review_url:string,states:int[],links_title:string,links:string}
 */
function gacct_avis_settings() {
	$saved = get_option( GACCT_AVIS_OPT, array() );
	$saved = is_array( $saved ) ? $saved : array();

	$settings = array_merge(
		array(
			'enabled'    => false,
			'review_url' => '',
			'states'     => array( 7 ),
			// Liens de pied de page : un par ligne, « Libellé | https://… ».
			'links_title' => '',
			'links'       => '',
		),
		$saved
	);

	return (array) apply_filters( 'gacct_avis_settings', $settings );
}

/**
 * Le module est-il actif (case cochée et lien renseigné) ?
 */
function gacct_avis_active() {
	$s = gacct_avis_settings();

	return ! empty( $s['enabled'] ) && '' !== trim( (string) $s['review_url'] );
}

/**
 * Textes de l'encart (filtrables, white-label).
 */
function gacct_avis_texts() {
	return (array) apply_filters(
		'gacct_avis_texts',
		array(
			'avis'     => '<strong>Votre avis nous aide beaucoup</strong> : laissez-nous un mot sur Google.',
			'avis_btn' => 'Laisser un avis Google',
		)
	);
}

/**
 * HTML de l'encart pour l'e-mail (bouton en table, lisible par tous les
 * clients mail).
 */
function gacct_avis_email_block() {
	if ( ! gacct_avis_active() ) {
		return '';
	}

	$s = gacct_avis_settings();
	$t = gacct_avis_texts();

	return '<div style="margin:32px 0 8px;padding-top:24px;border-top:1px solid #e5e5e5;">'
		. '<p style="font-size:16px;margin:0 0 4px;">' . wp_kses_post( $t['avis'] ) . '</p>'
		// Étoiles en caractères (pas en image : visibles même images bloquées)
		// puis bouton ; blocs inline-block, ils passent l'un sous l'autre sur
		// un écran étroit.
		. '<div style="margin:12px 0 4px;">'
		. '<a href="' . esc_url( $s['review_url'] ) . '" style="display:inline-block;vertical-align:middle;margin:4px 14px 4px 0;font-size:28px;line-height:1;letter-spacing:2px;color:#fbbc04;text-decoration:none;">&#9733;&#9733;&#9733;&#9733;&#9733;</a>'
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-table;vertical-align:middle;margin:4px 0;"><tr>'
		. '<td style="background:#1a73e8;border-radius:999px;">'
		. '<a href="' . esc_url( $s['review_url'] ) . '" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:999px;">' . esc_html( $t['avis_btn'] ) . '</a>'
		. '</td></tr></table>'
		. '</div>'
		. '</div>';
}

/**
 * Liens de pied de page saisis dans les réglages.
 *
 * @return array<int,array{label:string,url:string}>
 */
function gacct_avis_links() {
	$s     = gacct_avis_settings();
	$links = array();

	foreach ( preg_split( '/
||
/', (string) $s['links'] ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );

		if ( 2 === count( $parts ) && '' !== $parts[0] && preg_match( '#^https?://#i', $parts[1] ) ) {
			$links[] = array( 'label' => $parts[0], 'url' => $parts[1] );
		}
	}

	return $links;
}

/**
 * HTML du pied de page de liens (centré, séparateurs « · »).
 */
function gacct_avis_links_block() {
	$links = gacct_avis_links();

	if ( empty( $links ) ) {
		return '';
	}

	$s     = gacct_avis_settings();
	$items = array();

	foreach ( $links as $link ) {
		$items[] = '<a href="' . esc_url( $link['url'] ) . '" style="color:#20c4c3;font-weight:bold;text-decoration:none;white-space:nowrap;">' . esc_html( $link['label'] ) . '</a>';
	}

	$html = '<div style="margin:32px 0 0;padding-top:20px;border-top:1px solid #e5e5e5;text-align:center;font-size:16px;line-height:1.9;">';

	if ( '' !== trim( (string) $s['links_title'] ) ) {
		$html .= '<p style="margin:0 0 6px;font-size:16px;color:#4b4b4b;">' . esc_html( $s['links_title'] ) . '</p>';
	}

	return $html . '<p style="margin:0;">' . implode( ' <span style="color:#bbbbbb;">&middot;</span> ', $items ) . '</p></div>';
}

/* -----------------------------------------------------------------------------
 *  Ajout à l'e-mail d'état
 * -------------------------------------------------------------------------- */

add_filter( 'gacct_notification_body', 'gacct_avis_append_block', 10, 4 );

function gacct_avis_append_block( $body, $state, $order = null, $revision_id = 0 ) {
	$s = gacct_avis_settings();

	if ( ! in_array( (int) $state, array_map( 'intval', (array) $s['states'] ), true ) ) {
		return $body;
	}

	if ( ! apply_filters( 'gacct_avis_for_order', true, $order, $revision_id ) ) {
		return $body;
	}

	return $body . gacct_avis_email_block();
}

/* -----------------------------------------------------------------------------
 *  Liens en pied de TOUS les e-mails clients (Bastien, 16/10/2026)
 * -------------------------------------------------------------------------- */

// Priorité 5 : avant le gabarit email-footer.php de WooCommerce (10), donc
// dans la carte blanche du message, au-dessus de l'adresse de l'atelier.
add_action( 'woocommerce_email_footer', 'gacct_avis_footer_links', 5 );

function gacct_avis_footer_links( $email = null ) {
	// E-mails WooCommerce natifs : clients seulement (pas « nouvelle commande »
	// à l'atelier) ; e-mails maison : tout sauf ceux marqués internes.
	if ( $email instanceof WC_Email && ! $email->is_customer_email() ) {
		return;
	}

	if ( function_exists( 'gacct_email_internal' ) && gacct_email_internal() ) {
		return;
	}

	echo gacct_avis_links_block(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans gacct_avis_links_block().
}

/* -----------------------------------------------------------------------------
 *  Réglages (page Notifications)
 * -------------------------------------------------------------------------- */

add_action( 'gacct_notifications_form_after', 'gacct_avis_render_settings' );

function gacct_avis_render_settings() {
	$s = gacct_avis_settings();
	?>
	<h2><?php esc_html_e( 'Invitation à laisser un avis Google', 'gestion-atelier-cct' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Encart ajouté sous l e-mail « Révision finie, rapport disponible ». Aucune contrepartie (cadeau, jeu, remise) contre un avis : c est interdit par Google.', 'gestion-atelier-cct' ); ?></p>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Actif', 'gestion-atelier-cct' ); ?></th>
				<td><label><input type="checkbox" name="gacct_avis[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <?php esc_html_e( 'Ajouter l invitation à l e-mail de fin de révision', 'gestion-atelier-cct' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="gacct_avis_review"><?php esc_html_e( 'Lien « laisser un avis » Google', 'gestion-atelier-cct' ); ?></label></th>
				<td><input type="url" id="gacct_avis_review" name="gacct_avis[review_url]" class="large-text" value="<?php echo esc_attr( $s['review_url'] ); ?>">
				<p class="description"><?php esc_html_e( 'Vide : encart désactivé.', 'gestion-atelier-cct' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="gacct_avis_links_title"><?php esc_html_e( 'Pied de page des e-mails clients : titre', 'gestion-atelier-cct' ); ?></label></th>
				<td><input type="text" id="gacct_avis_links_title" name="gacct_avis[links_title]" class="large-text" value="<?php echo esc_attr( $s['links_title'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="gacct_avis_links"><?php esc_html_e( 'Pied de page des e-mails clients : liens', 'gestion-atelier-cct' ); ?></label></th>
				<td><textarea id="gacct_avis_links" name="gacct_avis[links]" class="large-text" rows="5"><?php echo esc_textarea( $s['links'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Un lien par ligne : Libellé | https://adresse. Ajoutés en bas de tous les e-mails envoyés aux clients (pas aux e-mails internes de l atelier). Vide : pas de liens.', 'gestion-atelier-cct' ); ?></p></td>
			</tr>
		</tbody>
	</table>
	<?php
}

add_action( 'gacct_notifications_save', 'gacct_avis_save_settings' );

function gacct_avis_save_settings() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par handle_notifications_submission().
	$posted = isset( $_POST['gacct_avis'] ) && is_array( $_POST['gacct_avis'] ) ? wp_unslash( $_POST['gacct_avis'] ) : array();
	$s      = gacct_avis_settings();

	$s['enabled']    = ! empty( $posted['enabled'] );
	$s['review_url'] = esc_url_raw( trim( (string) ( $posted['review_url'] ?? '' ) ) );
	$s['links_title'] = sanitize_text_field( (string) ( $posted['links_title'] ?? '' ) );
	$s['links']       = sanitize_textarea_field( (string) ( $posted['links'] ?? '' ) );

	update_option( GACCT_AVIS_OPT, $s, false );
}
