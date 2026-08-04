<?php
/**
 * Page « Mon profil » de l'espace client (/mon-compte/mon-profil/).
 *
 * Inclus par le shortcode `[gacct_profil]` (includes/gacct-profile.php), lui-même
 * posé dans le template Elementor de l'onglet Profile Builder. Rendu DANS la zone
 * de contenu du compte : pas de <html>, uniquement le contenu.
 *
 * Variables disponibles : $data (gacct_profile_data()), $texts, $notice.
 *
 * Piège Elementor : le reset du kit pose `table td { border: 1px solid … }` et
 * enferme le contenu dans `.e-con-inner` — aucune table ici, tout est en grille.
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $data['user_id'] ) ) {
	return;
}

$t = static function ( $key ) use ( $texts ) {
	return isset( $texts[ $key ] ) ? $texts[ $key ] : '';
};

$nonce_field = static function () {
	wp_nonce_field( 'gacct_profile', 'gacct_profile_nonce' );
};

$pending = $data['pending'];
?>
<noscript>
	<style>
		/* Sans JS, les formulaires dépliants restent ouverts et le bouton
		   d'ouverture — qui ne servirait à rien — disparaît. */
		.gacct-profile-toggle > .gacct-profile-form[hidden] { display: block; }
		.gacct-profile-toggle > [data-gacct-toggle-btn] { display: none; }
	</style>
</noscript>

<div class="gacct-profile">

	<?php if ( $notice ) : ?>
		<div class="gacct-profile-notice gacct-profile-notice--<?php echo esc_attr( $notice['type'] ); ?>" role="status">
			<?php echo esc_html( $notice['message'] ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $data['is_social'] ) : ?>
		<div class="gacct-profile-notice gacct-profile-notice--info">
			<?php echo esc_html( $t( 'social_note' ) ); ?>
		</div>
	<?php endif; ?>

	<!-- ── Carte 1 : identité ─────────────────────────────────────────── -->
	<section class="gacct-profile-card">
		<h3 class="gacct-profile-card__title"><?php echo esc_html( $t( 'identity_title' ) ); ?></h3>
		<p class="gacct-profile-card__intro"><?php echo esc_html( $t( 'identity_intro' ) ); ?></p>

		<form class="gacct-profile-form" method="post" action="<?php echo esc_url( $data['action_url'] ); ?>" enctype="multipart/form-data">
			<?php $nonce_field(); ?>
			<input type="hidden" name="gacct_profile_action" value="identity">

			<div class="gacct-profile-avatar">
				<div class="gacct-profile-avatar__preview" data-gacct-avatar-preview>
					<?php if ( $data['avatar_url'] ) : ?>
						<img src="<?php echo esc_url( $data['avatar_url'] ); ?>" alt="">
					<?php else : ?>
						<span class="gacct-profile-avatar__initials"><?php echo esc_html( $data['initials'] ); ?></span>
					<?php endif; ?>
				</div>

				<div class="gacct-profile-avatar__actions">
					<span class="gacct-profile-label"><?php echo esc_html( $t( 'photo_label' ) ); ?></span>

					<label class="gacct-profile-btn gacct-profile-btn--ghost" for="gacct-avatar">
						<?php echo esc_html( $t( 'photo_choose' ) ); ?>
					</label>
					<input type="file" id="gacct-avatar" name="gacct_avatar" accept="image/jpeg,image/png,image/webp" class="gacct-profile-file" data-gacct-avatar-input>

					<p class="gacct-profile-hint"><?php echo esc_html( $t( 'photo_hint' ) ); ?></p>

					<?php if ( $data['has_photo'] ) : ?>
						<label class="gacct-profile-check">
							<input type="checkbox" name="gacct_remove_avatar" value="1">
							<span><?php echo esc_html( $t( 'photo_remove' ) ); ?></span>
						</label>
					<?php endif; ?>
				</div>
			</div>

			<div class="gacct-profile-grid">
				<p class="gacct-profile-field">
					<label class="gacct-profile-label" for="gacct-first-name"><?php echo esc_html( $t( 'first_name' ) ); ?></label>
					<input type="text" id="gacct-first-name" name="gacct_first_name" value="<?php echo esc_attr( $data['first_name'] ); ?>" autocomplete="given-name">
				</p>

				<p class="gacct-profile-field">
					<label class="gacct-profile-label" for="gacct-last-name"><?php echo esc_html( $t( 'last_name' ) ); ?></label>
					<input type="text" id="gacct-last-name" name="gacct_last_name" value="<?php echo esc_attr( $data['last_name'] ); ?>" autocomplete="family-name">
				</p>

				<p class="gacct-profile-field gacct-profile-field--full">
					<label class="gacct-profile-label" for="gacct-phone"><?php echo esc_html( $t( 'phone' ) ); ?></label>
					<input type="tel" id="gacct-phone" name="gacct_phone" value="<?php echo esc_attr( $data['phone'] ); ?>" autocomplete="tel">
					<span class="gacct-profile-hint"><?php echo esc_html( $t( 'phone_hint' ) ); ?></span>
				</p>
			</div>

			<div class="gacct-profile-actions">
				<button type="submit" class="gacct-profile-btn gacct-profile-btn--primary"><?php echo esc_html( $t( 'identity_save' ) ); ?></button>
			</div>
		</form>
	</section>

	<!-- ── Carte 2 : adresse e-mail ───────────────────────────────────── -->
	<section class="gacct-profile-card">
		<h3 class="gacct-profile-card__title"><?php echo esc_html( $t( 'email_title' ) ); ?></h3>
		<p class="gacct-profile-card__intro"><?php echo esc_html( $t( 'email_intro' ) ); ?></p>

		<p class="gacct-profile-readonly">
			<span class="gacct-profile-label"><?php echo esc_html( $t( 'email_current' ) ); ?></span>
			<strong><?php echo esc_html( $data['email'] ); ?></strong>
		</p>

		<?php if ( $pending ) : ?>
			<div class="gacct-profile-pending">
				<p class="gacct-profile-pending__title">
					<?php echo esc_html( sprintf( $t( 'email_pending' ), $pending['email'] ) ); ?>
				</p>
				<p class="gacct-profile-hint">
					<?php
					echo esc_html( sprintf(
						$t( 'email_pending_exp' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pending['expires'] )
					) );
					?>
				</p>

				<div class="gacct-profile-actions">
					<form method="post" action="<?php echo esc_url( $data['action_url'] ); ?>">
						<?php $nonce_field(); ?>
						<input type="hidden" name="gacct_profile_action" value="email">
						<input type="hidden" name="gacct_email_resend" value="1">
						<button type="submit" class="gacct-profile-btn gacct-profile-btn--ghost"><?php echo esc_html( $t( 'email_resend' ) ); ?></button>
					</form>

					<form method="post" action="<?php echo esc_url( $data['action_url'] ); ?>">
						<?php $nonce_field(); ?>
						<input type="hidden" name="gacct_profile_action" value="email_cancel">
						<button type="submit" class="gacct-profile-btn gacct-profile-btn--link"><?php echo esc_html( $t( 'email_cancel' ) ); ?></button>
					</form>
				</div>
			</div>
		<?php endif; ?>

		<div class="gacct-profile-toggle" data-gacct-toggle>
			<button type="button" class="gacct-profile-btn gacct-profile-btn--ghost" data-gacct-toggle-btn aria-expanded="false" aria-controls="gacct-email-form">
				<?php echo esc_html( $t( 'email_toggle' ) ); ?>
			</button>

			<form class="gacct-profile-form" id="gacct-email-form" method="post" action="<?php echo esc_url( $data['action_url'] ); ?>" data-gacct-toggle-panel hidden>
				<?php $nonce_field(); ?>
				<input type="hidden" name="gacct_profile_action" value="email">

				<div class="gacct-profile-grid">
					<p class="gacct-profile-field">
						<label class="gacct-profile-label" for="gacct-email-new"><?php echo esc_html( $t( 'email_new' ) ); ?></label>
						<input type="email" id="gacct-email-new" name="gacct_email_new" autocomplete="email" required>
					</p>

					<p class="gacct-profile-field">
						<label class="gacct-profile-label" for="gacct-email-password"><?php echo esc_html( $t( 'email_password' ) ); ?></label>
						<input type="password" id="gacct-email-password" name="gacct_email_password" autocomplete="current-password" required>
					</p>
				</div>

				<p class="gacct-profile-hint"><?php echo esc_html( $t( 'email_note' ) ); ?></p>

				<div class="gacct-profile-actions">
					<button type="submit" class="gacct-profile-btn gacct-profile-btn--primary"><?php echo esc_html( $t( 'email_submit' ) ); ?></button>
				</div>
			</form>
		</div>
	</section>

	<!-- ── Carte 3 : mot de passe ─────────────────────────────────────── -->
	<section class="gacct-profile-card">
		<h3 class="gacct-profile-card__title"><?php echo esc_html( $t( 'password_title' ) ); ?></h3>
		<p class="gacct-profile-card__intro"><?php echo esc_html( $t( 'password_intro' ) ); ?></p>

		<div class="gacct-profile-toggle" data-gacct-toggle>
			<button type="button" class="gacct-profile-btn gacct-profile-btn--ghost" data-gacct-toggle-btn aria-expanded="false" aria-controls="gacct-password-form">
				<?php echo esc_html( $t( 'password_toggle' ) ); ?>
			</button>

			<form class="gacct-profile-form" id="gacct-password-form" method="post" action="<?php echo esc_url( $data['action_url'] ); ?>" data-gacct-toggle-panel hidden>
				<?php $nonce_field(); ?>
				<input type="hidden" name="gacct_profile_action" value="password">

				<div class="gacct-profile-grid">
					<p class="gacct-profile-field gacct-profile-field--full">
						<label class="gacct-profile-label" for="gacct-password-current"><?php echo esc_html( $t( 'password_current' ) ); ?></label>
						<input type="password" id="gacct-password-current" name="gacct_password_current" autocomplete="current-password" required>
					</p>

					<p class="gacct-profile-field">
						<label class="gacct-profile-label" for="gacct-password-new"><?php echo esc_html( $t( 'password_new' ) ); ?></label>
						<input type="password" id="gacct-password-new" name="gacct_password_new" autocomplete="new-password" minlength="8" required>
					</p>

					<p class="gacct-profile-field">
						<label class="gacct-profile-label" for="gacct-password-repeat"><?php echo esc_html( $t( 'password_repeat' ) ); ?></label>
						<input type="password" id="gacct-password-repeat" name="gacct_password_repeat" autocomplete="new-password" minlength="8" required>
					</p>
				</div>

				<div class="gacct-profile-actions">
					<button type="submit" class="gacct-profile-btn gacct-profile-btn--primary"><?php echo esc_html( $t( 'password_submit' ) ); ?></button>
					<a class="gacct-profile-link" href="<?php echo esc_url( $data['lost_password'] ); ?>"><?php echo esc_html( $t( 'password_lost' ) ); ?></a>
				</div>
			</form>
		</div>
	</section>

	<!-- ── Carte 4 : connexion et sécurité ────────────────────────────── -->
	<section class="gacct-profile-card">
		<h3 class="gacct-profile-card__title"><?php echo esc_html( $t( 'security_title' ) ); ?></h3>

		<p class="gacct-profile-readonly">
			<span class="gacct-profile-label"><?php echo esc_html( $t( 'login_method' ) ); ?></span>
			<strong>
				<?php
				echo esc_html( $data['is_social'] ? $t( 'login_social' ) : $t( 'login_password' ) );
				?>
			</strong>
		</p>

		<p class="gacct-profile-readonly">
			<strong>
				<?php
				$count = (int) $data['sessions'];
				echo esc_html( sprintf( 1 === $count ? $t( 'sessions_count' ) : $t( 'sessions_count_p' ), $count ) );
				?>
			</strong>
			<span class="gacct-profile-hint"><?php echo esc_html( $t( 'sessions_hint' ) ); ?></span>
		</p>

		<form method="post" action="<?php echo esc_url( $data['action_url'] ); ?>">
			<?php $nonce_field(); ?>
			<input type="hidden" name="gacct_profile_action" value="sessions">
			<div class="gacct-profile-actions">
				<button type="submit" class="gacct-profile-btn gacct-profile-btn--ghost"><?php echo esc_html( $t( 'sessions_submit' ) ); ?></button>
			</div>
		</form>
	</section>

</div>
