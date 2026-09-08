<?php
/**
 * Console atelier — écran « Mon profil » (08/09/2026).
 *
 * Demande de Bastien : les opérateurs (tablette individuelle) doivent pouvoir
 * poser leur photo de profil et leur nom depuis la console, comme les clients
 * dans l'espace client. Réutilise la mécanique de photo de « Mon profil »
 * client (gacct-profile.php : dépôt, contrôle du type, suppression, cascade
 * d'affichage via gacct_dash_avatar_url()). Le mot de passe se change sur
 * profile.php (seul écran WordPress laissé ouvert au rôle atelier).
 *
 * Soumission : POST sur la console, intercepté sur admin_init (PRG,
 * ?gacct_profil_notice=<clé>). Écriture sur get_current_user_id() UNIQUEMENT.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function gacct_op_profil_url( array $extra = array() ) {
	return gacct_op_console_url( 0, array_merge( array( 'view' => 'profil' ), $extra ) );
}

function gacct_op_profil_texts() {
	return apply_filters( 'gacct_op_profil_texts', array(
		'title'        => __( 'Mon profil', 'gestion-atelier-cct' ),
		'intro'        => __( 'Votre nom apparaît sur les fiches, les notes de commande et les rapports que vous réalisez. Votre photo aide l’équipe à vous reconnaître d’un coup d’œil.', 'gestion-atelier-cct' ),
		'photo'        => __( 'Photo de profil', 'gestion-atelier-cct' ),
		'photo_hint'   => __( 'JPG, PNG ou WebP, 4 Mo maximum. Une photo carrée passe mieux.', 'gestion-atelier-cct' ),
		'photo_choose' => __( 'Choisir une photo', 'gestion-atelier-cct' ),
		'photo_remove' => __( 'Retirer la photo', 'gestion-atelier-cct' ),
		'first_name'   => __( 'Prénom', 'gestion-atelier-cct' ),
		'last_name'    => __( 'Nom', 'gestion-atelier-cct' ),
		'display_name' => __( 'Nom affiché', 'gestion-atelier-cct' ),
		'display_hint' => __( 'Tel qu’il apparaît dans « Réalisé par ». Vide = prénom et nom.', 'gestion-atelier-cct' ),
		'email'        => __( 'Adresse e-mail', 'gestion-atelier-cct' ),
		'email_hint'   => __( 'Identifiant de connexion, modifiable par un administrateur.', 'gestion-atelier-cct' ),
		'save'         => __( 'Enregistrer', 'gestion-atelier-cct' ),
		'password'     => __( 'Changer mon mot de passe', 'gestion-atelier-cct' ),
		'ok_saved'     => __( 'Profil enregistré.', 'gestion-atelier-cct' ),
		'ok_removed'   => __( 'Photo retirée.', 'gestion-atelier-cct' ),
		'err_nonce'    => __( 'La session a expiré, réessayez.', 'gestion-atelier-cct' ),
		'err_generic'  => __( 'Impossible d’enregistrer le profil.', 'gestion-atelier-cct' ),
		'err_avatar_type'   => __( 'Format d’image non accepté : JPG, PNG ou WebP.', 'gestion-atelier-cct' ),
		'err_avatar_size'   => __( 'L’image dépasse 4 Mo.', 'gestion-atelier-cct' ),
		'err_avatar_upload' => __( 'L’image n’a pas pu être enregistrée.', 'gestion-atelier-cct' ),
	) );
}

/**
 * Traitement du formulaire (PRG), avant tout rendu de la console.
 */
function gacct_op_profil_handle_post() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['gacct_op_profil_submit'] ) ) {
		return;
	}

	if ( ! current_user_can( GACCT_OP_CAP ) ) {
		return;
	}

	$user_id  = get_current_user_id();
	$redirect = function ( $code ) {
		wp_safe_redirect( gacct_op_profil_url( array( 'gacct_profil_notice' => $code ) ) );
		exit;
	};

	if ( ! isset( $_POST['gacct_op_profil_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gacct_op_profil_nonce'] ) ), 'gacct_op_profil' ) ) {
		$redirect( 'err_nonce' );
	}

	if ( ! empty( $_POST['gacct_remove_avatar'] ) && function_exists( 'gacct_profile_delete_avatar' ) ) {
		gacct_profile_delete_avatar( $user_id );
		$redirect( 'ok_removed' );
	}

	if ( ! empty( $_FILES['gacct_avatar']['name'] ) && function_exists( 'gacct_profile_handle_avatar_upload' ) ) {
		$uploaded = gacct_profile_handle_avatar_upload( $user_id );
		if ( is_wp_error( $uploaded ) ) {
			$redirect( $uploaded->get_error_code() );
		}
	}

	$first   = isset( $_POST['gacct_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['gacct_first_name'] ) ) : '';
	$last    = isset( $_POST['gacct_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['gacct_last_name'] ) ) : '';
	$display = isset( $_POST['gacct_display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['gacct_display_name'] ) ) : '';

	if ( '' === $display ) {
		$display = trim( $first . ' ' . $last );
	}

	$args = array( 'ID' => $user_id, 'first_name' => $first, 'last_name' => $last );
	if ( '' !== $display ) {
		$args['display_name'] = $display;
	}

	$result = wp_update_user( $args );

	if ( is_wp_error( $result ) ) {
		$redirect( 'err_generic' );
	}

	do_action( 'gacct_op_profil_saved', $user_id );

	$redirect( 'ok_saved' );
}
add_action( 'admin_init', 'gacct_op_profil_handle_post', 5 );

/**
 * Rendu de l'écran.
 */
function gacct_op_render_profil_screen() {
	$t       = gacct_op_profil_texts();
	$user    = wp_get_current_user();
	$user_id = $user->ID;
	$notice  = null;

	if ( isset( $_GET['gacct_profil_notice'] ) ) {
		$key = sanitize_key( wp_unslash( $_GET['gacct_profil_notice'] ) );
		if ( isset( $t[ $key ] ) ) {
			$notice = array( 'type' => 0 === strpos( $key, 'ok_' ) ? 'success' : 'error', 'message' => $t[ $key ] );
		}
	}

	$has_photo  = function_exists( 'gacct_profile_avatar_id' ) && gacct_profile_avatar_id( $user_id );
	$avatar_url = function_exists( 'gacct_dash_avatar_url' ) ? gacct_dash_avatar_url( $user_id, 160 ) : get_avatar_url( $user_id, array( 'size' => 160 ) );
	$initials   = function_exists( 'gacct_dash_initials' ) ? gacct_dash_initials( $user_id ) : mb_strtoupper( mb_substr( $user->display_name, 0, 1 ) );
	?>
	<div class="wrap gacct-op gacct-op-profil">
		<h1><?php echo esc_html( $t['title'] ); ?></h1>
		<p class="gacct-op-muted"><?php echo esc_html( $t['intro'] ); ?></p>

		<?php if ( $notice ) : ?>
			<div class="gacct-op-feedback <?php echo esc_attr( $notice['type'] ); ?>" role="status"><?php echo esc_html( $notice['message'] ); ?></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( gacct_op_profil_url() ); ?>" enctype="multipart/form-data" class="gacct-op-card gacct-op-profil-card">
			<?php wp_nonce_field( 'gacct_op_profil', 'gacct_op_profil_nonce' ); ?>

			<div class="gacct-op-profil-photo">
				<div class="gacct-op-profil-avatar" aria-hidden="true">
					<?php if ( $has_photo && $avatar_url ) : ?>
						<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" data-op-avatar-preview>
					<?php else : ?>
						<span data-op-avatar-preview-initials><?php echo esc_html( $initials ); ?></span>
						<img src="" alt="" data-op-avatar-preview hidden>
					<?php endif; ?>
				</div>
				<div class="gacct-op-profil-photo-actions">
					<strong><?php echo esc_html( $t['photo'] ); ?></strong>
					<p class="gacct-op-muted"><?php echo esc_html( $t['photo_hint'] ); ?></p>
					<label class="button button-primary gacct-op-profil-file">
						<?php echo esc_html( $t['photo_choose'] ); ?>
						<input type="file" name="gacct_avatar" accept="image/jpeg,image/png,image/webp" data-op-avatar-input hidden>
					</label>
					<?php if ( $has_photo ) : ?>
						<button type="submit" name="gacct_remove_avatar" value="1" class="button gacct-op-profil-remove"><?php echo esc_html( $t['photo_remove'] ); ?></button>
					<?php endif; ?>
					<span class="gacct-op-muted" data-op-avatar-name></span>
				</div>
			</div>

			<div class="gacct-op-profil-grid">
				<label class="gacct-op-profil-field">
					<span><?php echo esc_html( $t['first_name'] ); ?></span>
					<input type="text" name="gacct_first_name" value="<?php echo esc_attr( $user->first_name ); ?>" autocomplete="given-name">
				</label>
				<label class="gacct-op-profil-field">
					<span><?php echo esc_html( $t['last_name'] ); ?></span>
					<input type="text" name="gacct_last_name" value="<?php echo esc_attr( $user->last_name ); ?>" autocomplete="family-name">
				</label>
				<label class="gacct-op-profil-field gacct-op-profil-field--wide">
					<span><?php echo esc_html( $t['display_name'] ); ?></span>
					<input type="text" name="gacct_display_name" value="<?php echo esc_attr( $user->display_name ); ?>">
					<small class="gacct-op-muted"><?php echo esc_html( $t['display_hint'] ); ?></small>
				</label>
				<label class="gacct-op-profil-field gacct-op-profil-field--wide">
					<span><?php echo esc_html( $t['email'] ); ?></span>
					<input type="email" value="<?php echo esc_attr( $user->user_email ); ?>" disabled>
					<small class="gacct-op-muted"><?php echo esc_html( $t['email_hint'] ); ?></small>
				</label>
			</div>

			<div class="gacct-op-profil-actions">
				<a class="button" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php echo esc_html( $t['password'] ); ?></a>
				<button type="submit" name="gacct_op_profil_submit" value="1" class="button button-primary"><?php echo esc_html( $t['save'] ); ?></button>
			</div>
		</form>
	</div>
	<script>
	( function () {
		var input = document.querySelector( '[data-op-avatar-input]' );
		if ( ! input ) { return; }
		input.addEventListener( 'change', function () {
			var file = input.files && input.files[ 0 ];
			var name = document.querySelector( '[data-op-avatar-name]' );
			if ( name ) { name.textContent = file ? file.name : ''; }
			if ( ! file || ! window.FileReader ) { return; }
			var reader = new FileReader();
			reader.onload = function ( e ) {
				var img = document.querySelector( '[data-op-avatar-preview]' );
				var ini = document.querySelector( '[data-op-avatar-preview-initials]' );
				if ( img ) { img.src = e.target.result; img.hidden = false; }
				if ( ini ) { ini.hidden = true; }
			};
			reader.readAsDataURL( file );
		} );
	} )();
	</script>
	<?php
}
