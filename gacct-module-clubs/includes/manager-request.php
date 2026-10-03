<?php
/**
 * Pré-demande du responsable de club : shortcode [gacct_club_demande].
 *
 * Non connecté : l'écran « l'e-mail d'abord » du socle (adresse inconnue =
 * compte créé sans mot de passe en un clic), retour sur cette page.
 * Connecté : formulaire court (champs fixés par Hervé le 30/09/2026), prérempli
 * si la personne gère déjà un club. POST sur la page, puis PRG vers « Mon club ».
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'gacct_club_demande', 'gacct_clubs_request_shortcode' );

function gacct_clubs_request_shortcode( $atts = array() ) {
	wp_enqueue_style( 'gacct-clubs-front', GACCT_CLUBS_URL . 'assets/clubs-front.css', array(), GACCT_CLUBS_VERSION );

	// entete="non" : la page d'atterrissage porte déjà le titre de la section.
	$atts      = shortcode_atts( array( 'entete' => 'oui' ), (array) $atts, 'gacct_club_demande' );
	$with_head = 'non' !== $atts['entete'];

	if ( ! is_user_logged_in() ) {
		if ( ! function_exists( 'gacct_login_ui_render' ) ) {
			return '<p>Connectez-vous pour envoyer la demande de votre club.</p>';
		}
		// Retour exact sur cette page après identification ou création du compte.
		$_GET['redirect_to'] = home_url( add_query_arg( array() ) ) . '#demande-club';
		return '<div class="gcl gcl--login">'
			. ( $with_head ? '<div class="gcl-head"><div><h1>Révision groupée de votre club</h1><p>Indiquez d’abord votre adresse e-mail : vous suivrez ensuite la commande de votre club depuis votre espace client.</p></div></div>' : '' )
			. gacct_login_ui_render( 'compte' ) . '</div>';
	}

	$user   = wp_get_current_user();
	$clubs  = gacct_clubs_for_user( $user->ID );
	$errors = isset( $GLOBALS['gacct_clubs_request_errors'] ) ? (array) $GLOBALS['gacct_clubs_request_errors'] : array();
	$old    = isset( $GLOBALS['gacct_clubs_request_old'] ) ? (array) $GLOBALS['gacct_clubs_request_old'] : array();

	$v = static function ( $k, $default = '' ) use ( $old ) {
		return esc_attr( isset( $old[ $k ] ) ? (string) $old[ $k ] : (string) $default );
	};

	$first = $clubs ? $clubs[0] : null;
	$tel   = get_user_meta( $user->ID, 'billing_phone', true );
	$name  = trim( $user->first_name . ' ' . $user->last_name );
	$name  = '' !== $name ? $name : $user->display_name;

	ob_start();
	?>
	<div class="gcl">
		<?php if ( $with_head ) : ?>
		<div class="gcl-head">
			<div>
				<h1>Révision groupée de votre club</h1>
				<p>Quelques informations suffisent. Nous revenons vers vous avec la date de début d’intervention et un code à transmettre à vos membres.</p>
			</div>
		</div>
		<?php endif; ?>
		<?php if ( $errors ) : ?>
			<div class="gcl-alert err" role="alert"><?php echo esc_html( implode( ' ', $errors ) ); ?></div>
		<?php endif; ?>
		<form method="post" class="gcl-card" novalidate>
			<?php wp_nonce_field( 'gacct_club_demande', 'gacct_club_demande_nonce' ); ?>
			<div class="gcl-form">
				<?php if ( $clubs ) : ?>
					<div class="full">
						<label for="gcl-club">Club</label>
						<select id="gcl-club" name="club_id">
							<?php foreach ( $clubs as $c ) : ?>
								<option value="<?php echo (int) $c['id']; ?>" <?php selected( (int) ( $old['club_id'] ?? $first['id'] ), (int) $c['id'] ); ?>><?php echo esc_html( $c['nom'] ); ?></option>
							<?php endforeach; ?>
							<option value="0" <?php selected( isset( $old['club_id'] ) && '0' === (string) $old['club_id'] ); ?>>Un autre club</option>
						</select>
					</div>
				<?php endif; ?>
				<div class="full gcl-newclub">
					<label for="gcl-nom">Nom du club, de l’école ou du groupe</label>
					<input id="gcl-nom" name="club_nom" type="text" value="<?php echo $v( 'club_nom', $first ? $first['nom'] : '' ); // phpcs:ignore ?>" <?php echo $clubs ? '' : 'required'; ?>>
				</div>
				<div class="full gcl-newclub">
					<label for="gcl-adresse">Adresse</label>
					<textarea id="gcl-adresse" name="club_adresse" rows="2"><?php echo esc_textarea( $old['club_adresse'] ?? ( $first ? $first['adresse'] : '' ) ); ?></textarea>
				</div>
				<div class="gcl-newclub">
					<label for="gcl-mail">E-mail du club</label>
					<input id="gcl-mail" name="club_email" type="email" value="<?php echo $v( 'club_email', $first ? $first['email'] : '' ); // phpcs:ignore ?>">
				</div>
				<div>
					<label for="gcl-contact">Contact à joindre</label>
					<input id="gcl-contact" name="contact_nom" type="text" value="<?php echo $v( 'contact_nom', $name ); // phpcs:ignore ?>" required>
				</div>
				<div>
					<label for="gcl-tel">Téléphone du contact</label>
					<input id="gcl-tel" name="contact_tel" type="tel" value="<?php echo $v( 'contact_tel', $tel ); // phpcs:ignore ?>" required>
				</div>
				<div>
					<label for="gcl-cmail">E-mail du contact</label>
					<input id="gcl-cmail" name="contact_email" type="email" value="<?php echo $v( 'contact_email', $user->user_email ); // phpcs:ignore ?>" required>
				</div>
				<div class="full gcl-num3">
					<div>
						<label for="gcl-ip">Voiles en inspection partielle</label>
						<input id="gcl-ip" name="nb_ip" type="number" min="0" max="200" inputmode="numeric" value="<?php echo $v( 'nb_ip', '0' ); // phpcs:ignore ?>">
					</div>
					<div>
						<label for="gcl-rp">Voiles en révision périodique</label>
						<input id="gcl-rp" name="nb_rp" type="number" min="0" max="200" inputmode="numeric" value="<?php echo $v( 'nb_rp', '0' ); // phpcs:ignore ?>">
					</div>
					<div>
						<label for="gcl-sec">Secours à plier</label>
						<input id="gcl-sec" name="nb_secours" type="number" min="0" max="200" inputmode="numeric" value="<?php echo $v( 'nb_secours', '0' ); // phpcs:ignore ?>">
					</div>
				</div>
				<div class="full">
					<label for="gcl-periode">Période d’intervention souhaitée</label>
					<input id="gcl-periode" name="periode" type="text" placeholder="Ex. deuxième quinzaine de novembre" value="<?php echo $v( 'periode' ); // phpcs:ignore ?>">
				</div>
				<div class="full">
					<label for="gcl-rem">Remarques</label>
					<textarea id="gcl-rem" name="remarques" rows="3"><?php echo esc_textarea( $old['remarques'] ?? '' ); ?></textarea>
				</div>
			</div>
			<div class="gcl-palier">
				<strong>Remise club</strong> sur les inspections, révisions et pliages : <?php echo esc_html( gacct_clubs_tiers_text() ); ?>. Aucune remise sur les suppléments et réparations. Une facture unique au nom du club, avec le détail par pilote.
			</div>
			<div class="gcl-row">
				<button type="submit" class="gcl-btn">Envoyer la demande</button>
				<span class="gcl-note">Rien n’est réservé ni facturé à ce stade.</span>
			</div>
		</form>
	</div>
	<?php if ( $clubs ) : ?>
	<script>
	( function () {
		var sel = document.getElementById( 'gcl-club' );
		var clubs = <?php echo wp_json_encode( array_map( static function ( $c ) { return array( 'id' => (int) $c['id'], 'nom' => $c['nom'], 'adresse' => $c['adresse'], 'email' => $c['email'] ); }, $clubs ) ); ?>;
		function maj() {
			var c = clubs.filter( function ( x ) { return String( x.id ) === sel.value; } )[0];
			document.getElementById( 'gcl-nom' ).value = c ? c.nom : '';
			document.getElementById( 'gcl-adresse' ).value = c ? ( c.adresse || '' ) : '';
			document.getElementById( 'gcl-mail' ).value = c ? ( c.email || '' ) : '';
		}
		sel.addEventListener( 'change', maj );
	} )();
	</script>
	<?php endif; ?>
	<?php
	return ob_get_clean();
}

/** « 10 % de 5 à 9 voiles, 12 % de 10 à 19, 15 % à partir de 20 » */
function gacct_clubs_tiers_text() {
	$tiers = (array) gacct_clubs_setting( 'tiers' );
	usort( $tiers, static function ( $a, $b ) {
		return (int) $a['min'] - (int) $b['min'];
	} );
	$out = array();
	foreach ( $tiers as $i => $t ) {
		$next = isset( $tiers[ $i + 1 ] ) ? (int) $tiers[ $i + 1 ]['min'] - 1 : 0;
		$rate = rtrim( rtrim( number_format( (float) $t['rate'], 1, ',', '' ), '0' ), ',' ) . ' %';
		$out[] = $next ? sprintf( '%s de %d à %d voiles', $rate, (int) $t['min'], $next ) : sprintf( '%s à partir de %d voiles', $rate, (int) $t['min'] );
	}
	return implode( ', ', $out );
}

add_action( 'template_redirect', 'gacct_clubs_request_handle', 5 );

function gacct_clubs_request_handle() {
	if ( empty( $_POST['gacct_club_demande_nonce'] ) || ! is_user_logged_in() ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gacct_club_demande_nonce'] ) ), 'gacct_club_demande' ) ) {
		$GLOBALS['gacct_clubs_request_errors'] = array( 'La page a expiré, renvoyez le formulaire.' );
		return;
	}

	$in   = wp_unslash( $_POST );
	$user = wp_get_current_user();
	$err  = array();

	$club_id = isset( $in['club_id'] ) ? absint( $in['club_id'] ) : 0;
	if ( $club_id && ! gacct_clubs_user_is_manager( $user->ID, $club_id ) ) {
		$club_id = 0;
	}

	$nom  = trim( sanitize_text_field( $in['club_nom'] ?? '' ) );
	$nbip = absint( $in['nb_ip'] ?? 0 );
	$nbrp = absint( $in['nb_rp'] ?? 0 );
	$nbse = absint( $in['nb_secours'] ?? 0 );

	if ( ! $club_id && '' === $nom ) {
		$err[] = 'Indiquez le nom du club.';
	}
	if ( '' === trim( (string) ( $in['contact_nom'] ?? '' ) ) || '' === trim( (string) ( $in['contact_tel'] ?? '' ) ) ) {
		$err[] = 'Indiquez le nom et le téléphone du contact.';
	}
	if ( ! is_email( $in['contact_email'] ?? '' ) ) {
		$err[] = 'L’adresse e-mail du contact n’est pas valide.';
	}
	if ( 0 === $nbip + $nbrp + $nbse ) {
		$err[] = 'Indiquez au moins une voile ou un secours.';
	}

	if ( $err ) {
		$GLOBALS['gacct_clubs_request_errors'] = $err;
		$GLOBALS['gacct_clubs_request_old']    = $in;
		return;
	}

	if ( $club_id ) {
		gacct_clubs_update_club( $club_id, array(
			'nom'     => '' !== $nom ? $nom : gacct_clubs_get_club( $club_id )['nom'],
			'adresse' => $in['club_adresse'] ?? '',
			'email'   => $in['club_email'] ?? '',
		) );
	} else {
		$club_id = gacct_clubs_create_club( array( 'nom' => $nom, 'adresse' => $in['club_adresse'] ?? '', 'email' => $in['club_email'] ?? '' ) );
	}
	gacct_clubs_add_manager( $club_id, $user->ID );

	if ( ! get_user_meta( $user->ID, 'billing_phone', true ) ) {
		update_user_meta( $user->ID, 'billing_phone', sanitize_text_field( $in['contact_tel'] ) );
	}

	$lot_id = gacct_clubs_create_lot( array(
		'club_id'       => $club_id,
		'demandeur_id'  => $user->ID,
		'contact_nom'   => $in['contact_nom'],
		'contact_tel'   => $in['contact_tel'],
		'contact_email' => $in['contact_email'],
		'nb_ip'         => $nbip,
		'nb_rp'         => $nbrp,
		'nb_secours'    => $nbse,
		'periode'       => $in['periode'] ?? '',
		'remarques'     => $in['remarques'] ?? '',
	) );

	$lot = gacct_clubs_get_lot( $lot_id );
	if ( $lot ) {
		gacct_clubs_send( gacct_clubs_lot_email( $lot ), 'club_request_received', $lot );
		foreach ( function_exists( 'gacct_pay_admin_emails' ) ? (array) gacct_pay_admin_emails() : array( get_option( 'admin_email' ) ) as $admin ) {
			gacct_clubs_send( $admin, 'club_request_admin', $lot );
		}
	}

	wp_safe_redirect( add_query_arg( array( 'lot' => $lot_id, 'gcl' => 'sent' ), gacct_clubs_account_url() ) );
	exit;
}
