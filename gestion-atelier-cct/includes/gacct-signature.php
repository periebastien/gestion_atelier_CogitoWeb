<?php
/**
 * Signature scannée des opérateurs (réunion du 06/08/2026).
 *
 * Chaque personne de l'atelier dépose l'image de sa signature dans SON profil
 * WordPress (wp-admin/profile.php, section « Signature des rapports ») ; les
 * rapports PDF sont ensuite signés automatiquement d'après le « Réalisé par »
 * du rapport (bloc signature de gacct_rp2_signature_qr()).
 *
 * Stockage : pièce jointe marquée `_gacct_signature_user` + user meta
 * `gacct_signature_id`. Le rôle atelier n'a pas `upload_files` : l'upload est
 * géré ici (PNG/JPEG/WebP, 2 Mo max filtrable `gacct_signature_max_size`).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GACCT_SIGNATURE_META = 'gacct_signature_id';

/**
 * L'utilisateur a-t-il droit à une signature ? (opérateurs + admins boutique)
 */
function gacct_signature_user_eligible( $user ) {
	$user = is_numeric( $user ) ? get_userdata( (int) $user ) : $user;

	return $user && ( user_can( $user, 'gacct_operate' ) || user_can( $user, 'manage_woocommerce' ) );
}

/**
 * ID de pièce jointe de la signature d'un utilisateur (0 si aucune).
 */
function gacct_report_signature_id( $user_id ) {
	$attachment_id = absint( get_user_meta( absint( $user_id ), GACCT_SIGNATURE_META, true ) );

	return ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) ? $attachment_id : 0;
}

/**
 * Chemin local du fichier signature (dompdf lit les fichiers, pas les URL).
 *
 * @return string '' si pas de signature.
 */
function gacct_report_signature_path( $user_id ) {
	$attachment_id = gacct_report_signature_id( $user_id );
	$path          = $attachment_id ? get_attached_file( $attachment_id ) : '';

	return ( $path && file_exists( $path ) ) ? $path : '';
}

/* =============================================================================
 *  PROFIL WORDPRESS — section « Signature des rapports »
 * ============================================================================= */

/**
 * Le formulaire du profil doit accepter les fichiers.
 */
function gacct_signature_form_enctype() {
	echo ' enctype="multipart/form-data"';
}
add_action( 'user_edit_form_tag', 'gacct_signature_form_enctype' );

/**
 * Section du profil (soi-même + admins sur les autres profils).
 */
function gacct_signature_profile_section( $user ) {
	if ( ! gacct_signature_user_eligible( $user ) ) {
		return;
	}

	$attachment_id = gacct_report_signature_id( $user->ID );
	$url           = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';

	wp_nonce_field( 'gacct_signature_' . $user->ID, 'gacct_signature_nonce' );

	echo '<h2>' . esc_html__( 'Signature des rapports', 'gestion-atelier-cct' ) . '</h2>';
	echo '<table class="form-table" role="presentation">';

	echo '<tr><th><label for="gacct_signature_file">' . esc_html__( 'Signature scannée', 'gestion-atelier-cct' ) . '</label></th><td>';

	if ( $url ) {
		echo '<p><img src="' . esc_url( $url ) . '" alt="" style="max-height:70px; max-width:260px; background:#fff; border:1px solid #dcdcde; border-radius:4px; padding:6px;"></p>';
		echo '<label><input type="checkbox" name="gacct_signature_delete" value="1"> ' . esc_html__( 'Supprimer la signature actuelle', 'gestion-atelier-cct' ) . '</label><br><br>';
	}

	echo '<input type="file" id="gacct_signature_file" name="gacct_signature_file" accept="image/png,image/jpeg,image/webp">';
	echo '<p class="description">' . esc_html__( 'PNG, JPG ou WebP, 2 Mo max. Idéalement : signature noire sur fond blanc ou transparent, recadrée au plus près. Elle est apposée automatiquement sur les rapports dont vous êtes le « Réalisé par ».', 'gestion-atelier-cct' ) . '</p>';

	echo '</td></tr></table>';
}
add_action( 'show_user_profile', 'gacct_signature_profile_section' );
add_action( 'edit_user_profile', 'gacct_signature_profile_section' );

/**
 * Enregistrement (profil propre + édition par un admin).
 */
function gacct_signature_profile_save( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) || ! gacct_signature_user_eligible( $user_id ) ) {
		return;
	}

	if ( ! isset( $_POST['gacct_signature_nonce'] ) || ! wp_verify_nonce( $_POST['gacct_signature_nonce'], 'gacct_signature_' . $user_id ) ) {
		return;
	}

	$previous = gacct_report_signature_id( $user_id );

	// Suppression demandée (et pas de nouveau fichier qui la remplacerait).
	$has_new = ! empty( $_FILES['gacct_signature_file']['tmp_name'] );

	if ( ! empty( $_POST['gacct_signature_delete'] ) && ! $has_new ) {
		if ( $previous ) {
			wp_delete_attachment( $previous, true );
		}
		delete_user_meta( $user_id, GACCT_SIGNATURE_META );
		return;
	}

	if ( ! $has_new ) {
		return;
	}

	$max_size = apply_filters( 'gacct_signature_max_size', 2 * MB_IN_BYTES );

	if ( (int) $_FILES['gacct_signature_file']['size'] > $max_size ) {
		return; // silencieux : le profil WP n'a pas de canal d'erreur simple ici.
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$upload = wp_handle_upload( $_FILES['gacct_signature_file'], array(
		'test_form' => false,
		'mimes'     => array(
			'png'  => 'image/png',
			'jpg|jpeg' => 'image/jpeg',
			'webp' => 'image/webp',
		),
	) );

	if ( isset( $upload['error'] ) ) {
		return;
	}

	$attachment_id = wp_insert_attachment( array(
		'post_mime_type' => $upload['type'],
		'post_title'     => sprintf( 'signature-%d', $user_id ),
		'post_status'    => 'inherit',
	), $upload['file'] );

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		return;
	}

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
	update_post_meta( $attachment_id, '_gacct_signature_user', $user_id );
	update_user_meta( $user_id, GACCT_SIGNATURE_META, $attachment_id );

	if ( $previous && $previous !== $attachment_id ) {
		wp_delete_attachment( $previous, true );
	}
}
add_action( 'personal_options_update', 'gacct_signature_profile_save' );
add_action( 'edit_user_profile_update', 'gacct_signature_profile_save' );
