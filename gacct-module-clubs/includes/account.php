<?php
/**
 * Espace client du responsable : onglet « Commandes groupées » (shortcode [gacct_club]).
 *
 * Maquette validée par Bastien le 03/10/2026 : page liste des commandes
 * groupées du club, page détail (frise, partage du lien, compteurs et palier
 * de remise, matériel inscrit, dates, envoi groupé, facture), carte ajoutée
 * au tableau de bord. L'onglet n'est visible que de qui a passé une commande groupée.
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'gacct_club', 'gacct_clubs_account_shortcode' );

function gacct_clubs_account_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '';
	}

	wp_enqueue_style( 'gacct-clubs-front', GACCT_CLUBS_URL . 'assets/clubs-front.css', array(), GACCT_CLUBS_VERSION );

	$user  = wp_get_current_user();
	$clubs = gacct_clubs_for_user( $user->ID );

	if ( ! $clubs ) {
		return '<div class="gcl"><div class="gcl-head"><div><h1>Commandes groupées</h1><p>Vous n’avez passé aucune commande groupée pour l’instant.</p></div></div>'
			. '<div class="gcl-card"><p>Vous organisez la révision du matériel de votre club, de votre école ou d’un groupe ? Envoyez une demande groupée : une seule commande à régler pour le club, un créneau réservé, un suivi individuel pour chaque pilote.</p>'
			. '<p><a class="gcl-btn" href="' . esc_url( gacct_clubs_page_url( 'request_page' ) ) . '">Demander une révision groupée</a></p></div></div>';
	}

	$lot_id = isset( $_GET['lot'] ) ? absint( $_GET['lot'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$lot    = $lot_id ? gacct_clubs_get_lot( $lot_id ) : null;

	if ( $lot && gacct_clubs_user_owns_lot( $user->ID, $lot ) ) {
		return gacct_clubs_account_detail( $lot );
	}

	return gacct_clubs_account_list( $clubs );
}

function gacct_clubs_account_notice() {
	$map = array(
		'sent'    => array( 'ok', 'Votre demande est envoyée. Nous revenons vers vous avec la date d’intervention et le code à transmettre à vos membres.' ),
		'shipped' => array( 'ok', 'Envoi enregistré. Merci, l’atelier est prévenu.' ),
		'ship_err'=> array( 'err', 'Indiquez le transporteur et le numéro de suivi (ou la date du dépôt).' ),
	);
	$k = isset( $_GET['gcl'] ) ? sanitize_key( wp_unslash( $_GET['gcl'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return isset( $map[ $k ] ) ? '<div class="gcl-alert ' . $map[ $k ][0] . '" role="status">' . esc_html( $map[ $k ][1] ) . '</div>' : '';
}

/* ----------------------------------------------------------------- liste --- */

function gacct_clubs_account_list( array $clubs ) {
	$user = wp_get_current_user();
	$lots = gacct_clubs_lots( array( 'user_id' => $user->ID ) );
	$lots = array_values( array_filter( $lots, static function ( $l ) {
		return 'annule' !== $l['statut'];
	} ) );
	// À régler d'abord, puis inscriptions ouvertes, en cours, terminées (Bastien, 05/10/2026).
	// usort n'est pas stable avant PHP 8 : l'index d'origine départage.
	$rank = array( 'regler' => 0, 'ouvert' => 1, 'cours' => 2, 'termine' => 3 );
	foreach ( $lots as $i => &$l ) {
		$l['_ord'] = $rank[ gacct_clubs_lot_family( $l ) ] * 10000 + $i;
	}
	unset( $l );
	usort( $lots, static function ( $a, $b ) {
		return $a['_ord'] - $b['_ord'];
	} );

	$en_cours = array_filter( $lots, static function ( $l ) {
		return 'expedie' !== $l['statut'];
	} );
	$termines = count( $lots ) - count( $en_cours );
	$action   = count( array_filter( $lots, static function ( $l ) {
		return gacct_clubs_registrations_open( $l ) || 'facture' === $l['statut'];
	} ) );
	$voiles   = 0;
	foreach ( $lots as $l ) {
		if ( 'expedie' === $l['statut'] ) {
			$voiles += gacct_clubs_lot_counts( $l )['voiles'];
		}
	}

	$club = $clubs[0];
	ob_start();
	?>
	<div class="gcl">
		<div class="gcl-crumb"><a href="<?php echo esc_url( home_url( '/mon-compte/' ) ); ?>">Espace client</a><span>›</span><span>Commandes groupées</span></div>
		<?php echo gacct_clubs_account_notice(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div class="gcl-head">
			<div>
				<h1>Commandes groupées</h1>
				<p><?php echo esc_html( implode( ', ', wp_list_pluck( $clubs, 'nom' ) ) ); ?> · le suivi des révisions de vos membres</p>
			</div>
			<a class="gcl-btn" href="<?php echo esc_url( gacct_clubs_page_url( 'request_page' ) ); ?>">+ Nouvelle commande groupée</a>
		</div>

		<div class="gcl-kpis">
			<div class="gcl-kpi"><strong><?php echo (int) $action; ?></strong><span>Action requise</span></div>
			<div class="gcl-kpi"><strong><?php echo (int) count( $en_cours ); ?></strong><span>En cours</span></div>
			<div class="gcl-kpi"><strong><?php echo (int) $termines; ?></strong><span>Terminées</span></div>
			<div class="gcl-kpi"><strong><?php echo (int) $voiles; ?></strong><span>Voiles révisées</span></div>
		</div>

		<?php foreach ( $clubs as $c ) : ?>
			<div class="gcl-card">
				<div class="gcl-row" style="justify-content:space-between"><h2><?php echo esc_html( $c['nom'] ); ?></h2><span class="gcl-note">Une information à corriger ? Contactez l’atelier.</span></div>
				<div class="gcl-form gcl-clubinfo">
					<div><label>Adresse</label><?php echo esc_html( $c['adresse'] ? $c['adresse'] : '–' ); ?></div>
					<div><label>E-mail du club</label><?php echo esc_html( $c['email'] ? $c['email'] : '–' ); ?></div>
				</div>
			</div>
		<?php endforeach; ?>

		<div class="gcl-tbl">
			<table class="gcl-cards">
				<thead><tr><th>Commande groupée</th><th>Intervention</th><th>État</th><th>Voiles</th><th>Secours</th><th>Règlement</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $lots ) : ?>
					<tr><td colspan="7">Aucune commande groupée pour l’instant.</td></tr>
				<?php endif; ?>
				<?php foreach ( $lots as $l ) : ?>
					<?php
					$c     = gacct_clubs_lot_counts( $l );
					$st    = gacct_clubs_lot_display_state( $l, $c );
					$order = $l['facture_order_id'] ? wc_get_order( (int) $l['facture_order_id'] ) : null;
					$url   = gacct_clubs_account_url( (int) $l['id'] );
					?>
					<tr class="gcl-f-<?php echo esc_attr( gacct_clubs_lot_family( $l ) ); ?>">
						<td class="first"><a href="<?php echo esc_url( $url ); ?>"><strong><?php echo esc_html( gacct_clubs_lot_has_code( $l ) ? $l['code'] : 'Demande n° ' . $l['id'] ); ?></strong></a><span class="sub"><?php echo esc_html( $l['nom'] . ' · demandée le ' . gacct_clubs_date_label( $l['created'], 'j M Y' ) ); ?></span></td>
						<td data-l="Intervention" class="num"><?php echo esc_html( $l['jours'] ? gacct_clubs_period_label( $l ) : 'À planifier' ); ?></td>
						<td data-l="État"><span class="gcl-badge <?php echo esc_attr( $st[1] ); ?>"><?php echo esc_html( $st[0] ); ?></span></td>
						<td data-l="Voiles" class="num"><?php echo esc_html( $c['voiles'] . ' / ' . gacct_clubs_lot_voiles( $l ) ); ?></td>
						<td data-l="Secours" class="num"><?php echo esc_html( $c['secours'] . ' / ' . (int) $l['nb_secours'] ); ?></td>
						<td data-l="Règlement" class="num"><span class="gcl-val"><?php echo $order ? wp_kses_post( wc_price( $order->get_total() ) ) . '<span class="sub">' . esc_html( $order->is_paid() ? 'Réglée' : 'À régler' ) . '</span>' : '<span class="sub">À la fin de l’intervention</span>'; ?></span></td>
						<td><a class="gcl-btn is-ghost is-sm" href="<?php echo esc_url( $url ); ?>">Voir</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="gcl-note">Vos propres révisions restent dans « Mes interventions ». Ici n’apparaissent que les commandes groupées passées depuis votre compte.</p>
	</div>
	<?php
	return ob_get_clean();
}

/** Libellé et couleur d'état vus par le responsable. */
function gacct_clubs_lot_display_state( array $lot, array $c ) {
	switch ( $lot['statut'] ) {
		case 'demande':
			return array( 'Demande envoyée', 'b-t' );
		case 'planifie':
			if ( gacct_clubs_registrations_open( $lot ) ) {
				return array( 'Inscriptions ouvertes', 'b-g' );
			}
			return $c['recues'] ? array( 'En atelier', 'b-t' ) : array( 'Inscriptions closes, envoi attendu', 'b-t' );
		case 'facture':
			return array( 'Commande à régler', 'b-y' );
		case 'paye':
			return array( 'Retour en préparation', 'b-t' );
		case 'expedie':
			return array( 'Matériel réexpédié', 'b-n' );
	}
	return array( 'Annulée', 'b-r' );
}

/**
 * Famille d'une commande groupée dans la liste du responsable (couleur de la
 * ligne, option A de Bastien du 05/10/2026) : à régler (jaune), inscriptions
 * ouvertes (vert), en cours (turquoise), terminée (gris).
 */
function gacct_clubs_lot_family( array $lot ) {
	if ( 'facture' === $lot['statut'] ) {
		return 'regler';
	}
	if ( in_array( $lot['statut'], array( 'expedie', 'annule' ), true ) ) {
		return 'termine';
	}
	if ( 'planifie' === $lot['statut'] && gacct_clubs_registrations_open( $lot ) ) {
		return 'ouvert';
	}
	return 'cours';
}

/* ---------------------------------------------------------------- détail --- */

function gacct_clubs_frise_index( array $lot, array $c, array $members ) {
	switch ( $lot['statut'] ) {
		case 'demande':
			return 1;
		case 'facture':
			return 6;
		case 'paye':
			return 7;
		case 'expedie':
			return 8;
	}
	if ( gacct_clubs_registrations_open( $lot ) ) {
		return 2;
	}
	if ( ! $c['recues'] ) {
		return 3;
	}
	$todo = array_filter( $members, static function ( $m ) {
		return ! $m['hors_lot'] && $m['etat'] < 6;
	} );
	return $todo ? 5 : 6;
}

function gacct_clubs_account_detail( array $lot ) {
	$members = gacct_clubs_lot_members( (int) $lot['id'] );
	$c       = gacct_clubs_lot_counts( $lot, $members );
	$st      = gacct_clubs_lot_display_state( $lot, $c );
	$open    = gacct_clubs_registrations_open( $lot );
	$order   = $lot['facture_order_id'] ? wc_get_order( (int) $lot['facture_order_id'] ) : null;
	$now     = gacct_clubs_frise_index( $lot, $c, $members );
	$labels  = gacct_clubs_state_labels();
	$code_ok = gacct_clubs_lot_has_code( $lot );
	$first   = gacct_clubs_first_day( $lot );
	$annonc  = gacct_clubs_lot_voiles( $lot );

	$steps = array(
		array( 'Demande envoyée', gacct_clubs_date_label( $lot['created'], 'j M' ) ),
		array( 'Planifiée', $first ? gacct_clubs_date_label( $first, 'j M' ) : '' ),
		// 3e valeur : libellé court du mobile (4 colonnes, Bastien 05/10/2026).
		array( 'Inscriptions ouvertes', $lot['limite_inscription'] ? 'jusqu’au ' . gacct_clubs_date_label( $lot['limite_inscription'], 'j M' ) : '', 'Ouvertes' ),
		array( 'Inscriptions closes', '', 'Closes' ),
		array( 'Colis reçu', $lot['limite_arrivee'] ? 'avant le ' . gacct_clubs_date_label( $lot['limite_arrivee'], 'j M' ) : '' ),
		array( 'En atelier', $lot['jours'] ? gacct_clubs_period_label( $lot ) : '' ),
		array( 'Commande à régler', '', 'À régler' ),
		array( 'Matériel réexpédié', '', 'Renvoyé' ),
	);

	$next   = gacct_clubs_next_tier( $c['voiles'] );
	$rate   = gacct_clubs_rate_for( $c['voiles'] );
	$wo_ok  = array_filter( $members, static function ( $m ) {
		return $m['order'] && function_exists( 'gacct_order_payment_received' ) && gacct_order_payment_received( $m['order'] );
	} );

	ob_start();
	?>
	<div class="gcl">
		<div class="gcl-crumb"><a href="<?php echo esc_url( home_url( '/mon-compte/' ) ); ?>">Espace client</a><span>›</span><a href="<?php echo esc_url( gacct_clubs_account_url() ); ?>">Commandes groupées</a><span>›</span><span><?php echo esc_html( $code_ok ? $lot['code'] : 'Demande n° ' . $lot['id'] ); ?></span></div>
		<?php echo gacct_clubs_account_notice(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div class="gcl-head">
			<div>
				<h1>Commande groupée <?php echo esc_html( $code_ok ? $lot['code'] : 'n° ' . $lot['id'] ); ?></h1>
				<p><?php echo esc_html( $lot['nom'] . ( $lot['jours'] ? ' · intervention ' . gacct_clubs_period_label( $lot ) : ' · période souhaitée : ' . ( $lot['periode'] ? $lot['periode'] : 'non précisée' ) ) ); ?></p>
			</div>
			<span class="gcl-badge <?php echo esc_attr( $st[1] ); ?>"><?php echo esc_html( $st[0] ); ?></span>
		</div>

		<div class="gcl-frise" aria-label="Avancement de la commande groupée">
			<?php foreach ( $steps as $i => $s ) : ?>
				<div class="gcl-st <?php echo esc_attr( $i < $now ? 'done' : ( $i === $now ? 'now' : '' ) ); ?>"><i></i><span><?php echo empty( $s[2] ) ? esc_html( $s[0] ) : '<span class="gcl-lg">' . esc_html( $s[0] ) . '</span><span class="gcl-sh">' . esc_html( $s[2] ) . '</span>'; ?></span><small><?php echo esc_html( $s[1] ); ?></small></div>
			<?php endforeach; ?>
		</div>

		<div class="gcl-cols">
			<div class="gcl-stack">

				<?php if ( 'demande' === $lot['statut'] ) : ?>
					<div class="gcl-card is-hl">
						<div><div class="gcl-eyebrow">Demande reçue</div><h2>L’atelier prépare votre créneau</h2></div>
						<p>Nous revenons vers vous très vite avec la date de début d’intervention et le code à transmettre à vos membres. Rien n’est réservé et rien n’est à payer pour l’instant.</p>
						<p>Annoncé : <?php echo esc_html( sprintf( '%d inspections partielles, %d révisions périodiques, %d contrôles complets, %d secours.', (int) $lot['nb_ip'], (int) $lot['nb_rp'], (int) $lot['nb_cc'], (int) $lot['nb_secours'] ) ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $open ) : ?>
					<div class="gcl-card is-hl">
						<div><div class="gcl-eyebrow">À faire maintenant</div><h2>Partagez le lien avec vos membres</h2></div>
						<p>Chaque membre inscrit lui-même sa voile ou son secours en 3 minutes. Il n’a pas de date à choisir et rien à payer : le créneau est réservé pour le club.</p>
						<div class="gcl-share">
							<div class="gcl-field"><div class="v"><span class="k">Lien du club</span><?php echo esc_html( gacct_clubs_member_url_text( $lot ) ); ?></div><button class="gcl-btn is-teal is-sm" type="button" data-gcl-copy="<?php echo esc_attr( gacct_clubs_member_url( $lot ) ); ?>">Copier</button></div>
							<div class="gcl-field"><div class="v"><span class="k">Code club</span><span class="gcl-code"><?php echo esc_html( $lot['code'] ); ?></span></div><button class="gcl-btn is-teal is-sm" type="button" data-gcl-copy="<?php echo esc_attr( $lot['code'] ); ?>">Copier</button></div>
						</div>
						<div class="gcl-msg" id="gcl-msg"><?php echo esc_html( gacct_clubs_member_message( $lot ) ); ?></div>
						<div class="gcl-row">
							<button class="gcl-btn" type="button" data-gcl-copy-msg>Copier le message</button>
							<a class="gcl-btn is-ghost" href="<?php echo esc_url( 'mailto:?subject=' . rawurlencode( 'Révision groupée du club' ) . '&body=' . rawurlencode( gacct_clubs_member_message( $lot ) ) ); ?>">Envoyer par e-mail</a>
							<a class="gcl-btn is-ghost" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( gacct_clubs_member_message( $lot ) ) ); ?>" target="_blank" rel="noopener">Partager sur WhatsApp</a>
							<span class="gcl-copied" hidden>Copié</span>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $order ) : ?>
					<div class="gcl-card <?php echo $order->is_paid() ? '' : 'is-hy'; ?>">
						<div><?php if ( ! $order->is_paid() ) : ?><div class="gcl-eyebrow" style="color:#a06d00">À faire maintenant</div><?php endif; ?><h2><?php echo esc_html( $order->is_paid() ? 'Commande du club réglée' : 'Commande du club à régler' ); ?> <small>N° <?php echo esc_html( $order->get_order_number() ); ?></small></h2></div>
						<?php if ( ! $order->is_paid() ) : ?><p>Le matériel repart dès réception du paiement, en un seul envoi vers le club.</p><?php endif; ?>
						<?php $payeur = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ); ?>
						<p class="gcl-note">Commande au nom de <?php echo esc_html( $order->get_billing_company() ); ?><?php echo $payeur ? esc_html( ', à l’attention de ' . $payeur ) : ''; ?>.</p>
						<div class="gcl-tbl">
							<table class="gcl-inv">
								<thead><tr><th>Prestation</th><th class="r">Montant</th></tr></thead>
								<tbody>
								<?php foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $it ) : ?>
									<tr class="<?php echo $it instanceof WC_Order_Item_Fee ? 'disc' : ''; ?>"><td><?php echo esc_html( $it->get_name() ); ?></td><td class="r"><?php echo wp_kses_post( wc_price( (float) $it->get_total() + (float) $it->get_total_tax() ) ); ?></td></tr>
								<?php endforeach; ?>
								<tr class="tot"><td>Total TTC</td><td class="r"><?php echo wp_kses_post( wc_price( $order->get_total() ) ); ?></td></tr>
								</tbody>
							</table>
						</div>
						<?php if ( ! $order->is_paid() ) : ?>
							<?php if ( (int) $order->get_customer_id() === get_current_user_id() ) : ?>
								<div class="gcl-row"><a class="gcl-btn" href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>">Régler la commande</a><span class="gcl-note">Paiement par carte ou par virement.</span></div>
							<?php else : // Règle de Bastien (04/10/2026) : seule la personne qui a fait la demande règle la commande. ?>
								<p class="gcl-note">Le règlement se fait depuis le compte de <?php echo esc_html( $payeur ? $payeur : 'la personne qui a fait la demande' ); ?>, qui a reçu la commande à régler par e-mail.</p>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( 'demande' !== $lot['statut'] ) : ?>
					<div class="gcl-card">
						<h2><?php echo esc_html( $order ? 'Bilan du lot' : 'Inscriptions' ); ?></h2>
						<div class="gcl-counts">
							<?php foreach ( array( array( 'Révisions périodiques', $c['rp'], (int) $lot['nb_rp'] ), array( 'Inspections partielles', $c['ip'], (int) $lot['nb_ip'] ), array( 'Contrôles complets', $c['cc'], (int) $lot['nb_cc'] ), array( 'Pliages de secours', $c['secours'], (int) $lot['nb_secours'] ) ) as $k ) : ?>
								<div class="gcl-cnt"><span><?php echo esc_html( $k[0] ); ?></span><strong><?php echo (int) $k[1]; ?> <small><?php echo esc_html( sprintf( '%1$s sur %2$d %3$s', $k[1] > 1 ? 'inscrites' : 'inscrite', $k[2], $k[2] > 1 ? 'annoncées' : 'annoncée' ) ); ?></small></strong><div class="gcl-bar"><b style="width:<?php echo (int) min( 100, $k[2] ? round( $k[1] / $k[2] * 100 ) : ( $k[1] ? 100 : 0 ) ); ?>%"></b></div></div>
							<?php endforeach; ?>
						</div>
						<div class="gcl-palier">
							<?php if ( $rate > 0 ) : ?>
								<strong>Remise club prévue : <?php echo esc_html( rtrim( rtrim( number_format( $rate, 1, ',', '' ), '0' ), ',' ) ); ?> %</strong> avec <?php echo (int) $c['voiles']; ?> voiles.
							<?php else : ?>
								<strong>Pas encore de remise club</strong> (<?php echo (int) $c['voiles']; ?> voiles inscrites).
							<?php endif; ?>
							<?php if ( $next && ! $order ) : ?>
								Encore <?php echo (int) $next['missing']; ?> voile<?php echo $next['missing'] > 1 ? 's' : ''; ?> pour passer à <?php echo esc_html( rtrim( rtrim( number_format( $next['rate'], 1, ',', '' ), '0' ), ',' ) ); ?> %.
							<?php endif; ?>
							<span class="gcl-note" style="display:block;margin-top:4px">Paliers : <?php echo esc_html( gacct_clubs_tiers_text() ); ?>. Les secours ne comptent pas dans le palier mais profitent de la remise.</span>
						</div>
					</div>

					<div class="gcl-card" style="padding:0;gap:0">
						<div class="gcl-row" style="justify-content:space-between;padding:20px 22px 14px"><h2>Matériel inscrit</h2><span class="gcl-note"><?php echo esc_html( sprintf( '%d voiles et %d secours', $c['voiles'], $c['secours'] ) ); ?></span></div>
						<div style="overflow-x:auto;border-top:1px solid var(--gcl-line)">
							<table class="gcl-cards">
								<thead><tr><th>Membre</th><th>Matériel</th><th>Prestation</th><th>État</th></tr></thead>
								<tbody>
								<?php if ( ! $members ) : ?>
									<tr><td colspan="4">Aucune inscription pour l’instant. Partagez le lien avec vos membres.</td></tr>
								<?php endif; ?>
								<?php foreach ( $members as $m ) : ?>
									<?php
									if ( $m['hors_lot'] ) {
										$b = array( 'En attente de pièces, hors lot', 'b-r' );
									} elseif ( $m['etat'] <= 1 ) {
										$b = array( 'Inscrite, bon disponible', 'b-b' );
									} else {
										$b = array( isset( $labels[ $m['etat'] ] ) ? $labels[ $m['etat'] ] : (string) $m['etat'], $m['etat'] >= 7 ? 'b-g' : 'b-b' );
									}
									$me = (int) ( $m['revision']['client_id'] ?? 0 ) === get_current_user_id();
									?>
									<tr>
										<td class="first"><strong><?php echo esc_html( ( $m['prenom'] ? $m['prenom'] : $m['pilote'] ) . ( $me ? ' (vous)' : '' ) ); ?></strong></td>
										<td data-l="Matériel"><?php echo esc_html( $m['materiel'] ? $m['materiel'] : '–' ); ?></td>
										<td data-l="Prestation"><?php echo esc_html( implode( ', ', $m['prestations'] ) ); ?></td>
										<td data-l="État"><span class="gcl-badge <?php echo esc_attr( $b[1] ); ?>"><?php echo esc_html( $b[0] ); ?></span></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<div class="gcl-stack">
				<?php if ( $lot['jours'] ) : ?>
					<div class="gcl-card">
						<h2>Dates clés</h2>
						<div class="gcl-kv">
							<?php if ( $lot['limite_inscription'] ) : ?><div><span>Limite d’inscription</span><b><?php echo esc_html( gacct_clubs_date_label( $lot['limite_inscription'] ) ); ?></b></div><?php endif; ?>
							<?php if ( $lot['limite_arrivee'] ) : ?><div><span>Remise des voiles au club</span><b><?php echo esc_html( gacct_clubs_date_label( gacct_clubs_shift_date( $lot['limite_arrivee'], -2 ) ) ); ?></b></div><?php endif; ?>
							<?php if ( $lot['limite_arrivee'] ) : ?><div><span>Arrivée du colis à l’atelier</span><b><?php echo esc_html( gacct_clubs_date_label( $lot['limite_arrivee'] ) ); ?></b></div><?php endif; ?>
							<div><span>Intervention</span><b><?php echo esc_html( gacct_clubs_period_label( $lot ) ); ?></b></div>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( 'planifie' === $lot['statut'] && $c['recues'] < max( 1, $c['dossiers'] ) ) : ?>
					<div class="gcl-card">
						<h2>Envoi groupé à l’atelier</h2>
						<ul class="gcl-ul">
							<li>Chaque voile dans son sac, avec son bon d’intervention imprimé. Il conditionne notre intervention.</li>
							<li>Un seul colis pour tout le club, ou un dépôt à la boutique.</li>
							<?php if ( $lot['limite_arrivee'] ) : ?><li>Arrivée à l’atelier avant le <?php echo esc_html( gacct_clubs_date_label( $lot['limite_arrivee'] ) ); ?>.</li><?php endif; ?>
						</ul>
						<div class="gcl-row">
							<?php $packing = get_page_by_path( 'consignes-demballage' ); ?>
							<?php if ( $packing ) : ?><a class="gcl-btn is-ghost is-sm" href="<?php echo esc_url( get_permalink( $packing ) ); ?>" target="_blank" rel="noopener">Consignes d’emballage</a><?php endif; ?>
							<?php if ( $wo_ok ) : ?><a class="gcl-btn is-teal is-sm" href="<?php echo esc_url( add_query_arg( 'gacct_club_bons', (int) $lot['id'], home_url( '/' ) ) ); ?>" target="_blank" rel="noopener">Imprimer les <?php echo (int) count( $wo_ok ); ?> bons</a><?php endif; ?>
						</div>
						<?php if ( $lot['envoi_transporteur'] ) : ?>
							<p><strong>Envoi déclaré :</strong> <?php echo esc_html( 'boutique' === $lot['envoi_transporteur'] ? 'dépôt à la boutique' . ( $lot['envoi_suivi'] ? ' le ' . $lot['envoi_suivi'] : '' ) : ucfirst( $lot['envoi_transporteur'] ) . ' n° ' . $lot['envoi_suivi'] ); ?></p>
						<?php endif; ?>
						<form method="post" class="gcl-form" style="grid-template-columns:minmax(0,1fr)">
							<?php wp_nonce_field( 'gacct_club_aller_' . (int) $lot['id'], 'gacct_club_aller_nonce' ); ?>
							<input type="hidden" name="gacct_club_lot" value="<?php echo (int) $lot['id']; ?>">
							<div class="gcl-f is-select">
								<select id="gcl-carrier" name="carrier">
									<?php foreach ( ( function_exists( 'gacct_ship_carriers' ) ? gacct_ship_carriers() : array() ) as $key => $car ) : ?>
										<?php if ( 'depot' === $key ) { continue; } ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $lot['envoi_transporteur'], $key ); ?>><?php echo esc_html( is_array( $car ) ? ( $car['label'] ?? $key ) : $car ); ?></option>
									<?php endforeach; ?>
									<option value="boutique" <?php selected( $lot['envoi_transporteur'], 'boutique' ); ?>>Dépôt à la boutique</option>
								</select>
								<label for="gcl-carrier"><?php echo esc_html( $lot['envoi_transporteur'] ? 'Modifier l’envoi' : 'Déclarer l’envoi du colis' ); ?></label>
							</div>
							<div class="gcl-f"><input placeholder=" " id="gcl-track" name="tracking" type="text" value="<?php echo esc_attr( $lot['envoi_suivi'] ); ?>"><label for="gcl-track">Numéro de suivi (ou date du dépôt)</label></div>
							<div><button type="submit" class="gcl-btn">Enregistrer l’envoi</button></div>
							<p class="gcl-note">Transporteur et numéro de suivi, ou « dépôt à la boutique ». L’atelier est prévenu.</p>
						</form>
					</div>
				<?php endif; ?>

				<?php if ( ! $order && 'demande' !== $lot['statut'] ) : ?>
					<div class="gcl-card">
						<h2>Règlement</h2>
						<p>À la fin de l’intervention, une commande unique au nom du club sera à régler, avec le détail par membre. Le club la règle avant le retour du matériel.</p>
					</div>
				<?php endif; ?>

				<?php if ( 'expedie' === $lot['statut'] ) : ?>
					<div class="gcl-card">
						<h2>Retour du matériel</h2>
						<p><?php echo esc_html( 'boutique' === $lot['retour_transporteur'] ? 'Le matériel est disponible à la boutique.' : sprintf( 'Expédié par %1$s, suivi n° %2$s.', ucfirst( $lot['retour_transporteur'] ), $lot['retour_suivi'] ) ); ?></p>
					</div>
				<?php endif; ?>

				<div class="gcl-card">
					<h2>Une question ?</h2>
					<?php $pay = function_exists( 'gacct_pay_settings' ) ? gacct_pay_settings() : array(); ?>
					<p>L’atelier : <?php echo esc_html( $pay['contact_phone'] ?? '' ); ?><?php echo ! empty( $pay['contact_hours'] ) ? '<br>' . esc_html( $pay['contact_hours'] ) : ''; ?></p>
				</div>
			</div>
		</div>
	</div>
	<script>
	( function () {
		var ok = document.querySelector( '.gcl-copied' );
		function flash() { if ( ok ) { ok.hidden = false; setTimeout( function () { ok.hidden = true; }, 1600 ); } }
		function copy( t, el ) {
			try { navigator.clipboard.writeText( t ).then( flash, function () { sel( el ); } ); } catch ( e ) { sel( el ); }
		}
		function sel( el ) { if ( ! el ) { return; } var r = document.createRange(); r.selectNodeContents( el ); var s = window.getSelection(); s.removeAllRanges(); s.addRange( r ); }
		document.querySelectorAll( '[data-gcl-copy]' ).forEach( function ( b ) { b.addEventListener( 'click', function () { copy( b.getAttribute( 'data-gcl-copy' ), b.parentNode.querySelector( '.v' ) ); } ); } );
		var m = document.querySelector( '[data-gcl-copy-msg]' );
		if ( m ) { m.addEventListener( 'click', function () { var el = document.getElementById( 'gcl-msg' ); copy( el.textContent, el ); } ); }
	} )();
	</script>
	<?php
	return ob_get_clean();
}

/* ------------------------------------------------ déclaration de l'envoi --- */

add_action( 'template_redirect', 'gacct_clubs_account_handle_aller', 5 );

function gacct_clubs_account_handle_aller() {
	if ( empty( $_POST['gacct_club_aller_nonce'] ) || ! is_user_logged_in() ) {
		return;
	}
	$lot_id = absint( $_POST['gacct_club_lot'] ?? 0 );
	$lot    = $lot_id ? gacct_clubs_get_lot( $lot_id ) : null;
	if ( ! $lot || ! gacct_clubs_user_owns_lot( get_current_user_id(), $lot )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gacct_club_aller_nonce'] ) ), 'gacct_club_aller_' . $lot_id ) ) {
		return;
	}
	$carrier  = sanitize_key( wp_unslash( $_POST['carrier'] ?? '' ) );
	$tracking = sanitize_text_field( wp_unslash( $_POST['tracking'] ?? '' ) );
	if ( '' === $carrier || ( 'boutique' !== $carrier && '' === $tracking ) ) {
		wp_safe_redirect( add_query_arg( 'gcl', 'ship_err', gacct_clubs_account_url( $lot_id ) ) );
		exit;
	}
	gacct_clubs_update_lot( $lot_id, array( 'envoi_transporteur' => $carrier, 'envoi_suivi' => $tracking ) );

	$subject = sprintf( 'Commande groupée %1$s (%2$s) : envoi du club déclaré', $lot['code'], $lot['nom'] );
	$body    = '<p>' . esc_html( sprintf( '%1$s a déclaré l’envoi du matériel : %2$s %3$s.', wp_get_current_user()->display_name, 'boutique' === $carrier ? 'dépôt à la boutique' : $carrier, $tracking ) ) . '</p><p>' . esc_url( gacct_clubs_console_url( $lot_id ) ) . '</p>';
	$html    = function_exists( 'gacct_render_email_html' ) ? gacct_render_email_html( $subject, $body ) : $body;
	foreach ( function_exists( 'gacct_pay_admin_emails' ) ? (array) gacct_pay_admin_emails() : array() as $to ) {
		wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	wp_safe_redirect( add_query_arg( 'gcl', 'shipped', gacct_clubs_account_url( $lot_id ) ) );
	exit;
}

/* --------------------------------------------- impression groupée des bons --- */

add_action( 'template_redirect', 'gacct_clubs_print_all_work_orders', 1 );

function gacct_clubs_print_all_work_orders() {
	if ( empty( $_GET['gacct_club_bons'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$lot = gacct_clubs_get_lot( absint( $_GET['gacct_club_bons'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$uid = get_current_user_id();
	if ( ! $lot || ! $uid || ( ! gacct_clubs_user_owns_lot( $uid, $lot ) && ! current_user_can( gacct_clubs_op_cap() ) ) ) {
		auth_redirect();
		exit;
	}
	if ( ! function_exists( 'gacct_wo_data' ) ) {
		wp_die( 'Bons indisponibles.' );
	}

	$template = WP_PLUGIN_DIR . '/gestion-atelier-cct/templates/workorder.php';
	$head     = '';
	$bodies   = array();
	$qrjs     = '';

	foreach ( gacct_clubs_lot_members( (int) $lot['id'] ) as $m ) {
		$order = $m['order'];
		if ( ! $order || ( function_exists( 'gacct_order_payment_received' ) && ! gacct_order_payment_received( $order ) ) || $m['etat'] > 1 ) {
			continue;
		}
		$data = gacct_wo_data( $order );
		ob_start();
		include $template;
		$html = ob_get_clean();

		if ( '' === $head && preg_match( '#<head>(.*?)</head>#s', $html, $mh ) ) {
			$head = $mh[1];
		}
		if ( '' === $qrjs && preg_match( '#<script src="([^"]+qrcode[^"]*)"#', $html, $mq ) ) {
			$qrjs = $mq[1];
		}
		if ( preg_match( '#(<section class="page">.*?</section>)#s', $html, $ms ) ) {
			$bodies[] = str_replace( 'id="wo-qr"', 'data-gcl-qr="' . esc_attr( $data['scan_url'] ) . '"', $ms[1] );
		}
	}

	nocache_headers();
	echo '<!DOCTYPE html><html lang="fr"><head>' . $head . '<style>.page + .page{break-before:page;page-break-before:always}.gcl-print{position:fixed;top:12px;right:12px;z-index:9}@media print{.gcl-print{display:none}}</style></head><body>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<button type="button" class="wo-print-btn gcl-print" onclick="window.print()">Imprimer les ' . (int) count( $bodies ) . ' bons</button>';
	echo $bodies ? implode( "\n", $bodies ) : '<p style="padding:40px">Aucun bon à imprimer pour l’instant.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( $qrjs ) {
		echo '<script src="' . esc_url( $qrjs ) . '"></script><script>document.querySelectorAll("[data-gcl-qr]").forEach(function(el){new QRCode(el,{text:el.getAttribute("data-gcl-qr"),width:384,height:384,correctLevel:QRCode.CorrectLevel.M});});</script>';
	}
	echo '</body></html>';
	exit;
}

/* ------------------------------------------- tableau de bord et menu ------ */

add_filter( 'gacct_dashboard_actions_html', 'gacct_clubs_dashboard_card', 10, 2 );

function gacct_clubs_dashboard_card( $html, $data ) {
	$uid = get_current_user_id();
	if ( ! $uid || ! gacct_clubs_user_is_manager( $uid ) ) {
		return $html;
	}
	$cards = '';
	foreach ( gacct_clubs_lots( array( 'user_id' => $uid, 'statut' => array( 'demande', 'planifie', 'facture' ) ) ) as $lot ) {
		$c = gacct_clubs_lot_counts( $lot );
		if ( 'facture' === $lot['statut'] ) {
			$title = sprintf( 'Commande groupée de %s : commande à régler', $lot['nom'] );
			$text  = 'Le matériel repart dès réception du paiement.';
			$cta   = 'Voir la commande à régler';
		} elseif ( gacct_clubs_registrations_open( $lot ) ) {
			$title = sprintf( 'Commande groupée du club : %1$d voiles inscrites sur %2$d', $c['voiles'], gacct_clubs_lot_voiles( $lot ) );
			$text  = $lot['limite_inscription'] ? sprintf( 'Inscriptions ouvertes jusqu’au %s. Pensez à relancer vos membres.', gacct_clubs_date_label( $lot['limite_inscription'], 'j F' ) ) : '';
			$cta   = 'Suivre la commande';
		} else {
			$title = sprintf( 'Commande groupée de %s', $lot['nom'] );
			$text  = gacct_clubs_lot_display_state( $lot, $c )[0];
			$cta   = 'Suivre la commande';
		}
		$cards .= '<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;border:1px solid #ffe2a0;background:#fff6de;border-radius:14px;padding:16px 20px;margin:0 0 14px">'
			. '<div style="flex:1 1 260px;min-width:0"><strong style="display:block;font-size:16px;color:#1a1a1a">' . esc_html( $title ) . '</strong><span style="font-size:16px;color:#55595e">' . esc_html( $text ) . '</span></div>'
			. '<a href="' . esc_url( gacct_clubs_account_url( (int) $lot['id'] ) ) . '" style="display:inline-flex;align-items:center;border-radius:8px;padding:10px 18px;font-weight:700;background:#ffbd20;color:#1a1a1a;text-decoration:none;white-space:nowrap">' . esc_html( $cta ) . '</a></div>';
	}
	return $cards . $html;
}

/** L'onglet « Commandes groupées » n'est visible que de qui a passé une commande groupée. */
add_filter( 'body_class', static function ( $classes ) {
	if ( is_user_logged_in() && gacct_clubs_user_is_manager( get_current_user_id() ) ) {
		$classes[] = 'gacct-club-manager';
	}
	return $classes;
} );

add_action( 'wp_head', static function () {
	if ( ! is_user_logged_in() ) {
		return;
	}
	$slug = esc_attr( (string) gacct_clubs_setting( 'account_slug' ) );
	echo '<style id="gacct-clubs-menu">body:not(.gacct-club-manager) a[href*="/mon-compte/' . $slug . '"]{display:none!important}body:not(.gacct-club-manager) li:has(> a[href*="/mon-compte/' . $slug . '"]){display:none!important}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
} );
