<?php
/**
 * Détail d'une commande dans l'espace client (/mon-compte/view-order/{id}/).
 *
 * Remplace `myaccount/view-order.php` (filtre wc_get_template, cf.
 * includes/gacct-vieworder.php). Rendu DANS la zone de contenu mon-compte :
 * pas de <html>, uniquement le contenu. WooCommerce fournit $order et $order_id.
 *
 * Montants : API Kojito uniquement (prix catalogue, acompte, reste à payer).
 * Pièges Elementor : border:0 posé explicitement sur les tableaux (reset
 * `table td { border:1px solid … }` du kit).
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

$order = isset( $order ) && $order instanceof WC_Order ? $order : wc_get_order( absint( $order_id ?? 0 ) );

if ( ! $order ) {
	return;
}

$d      = gacct_vo_data( $order );
$etat   = $d['etat'];
$labels = gacct_vo_state_labels();

$vo_fmt = static function ( $amount ) {
	return number_format( (float) $amount, 2, ',', ' ' ) . ' €';
};

$is_bacs_waiting = ( 'bacs' === $d['variant'] );
$is_dead         = $order->has_status( array( 'cancelled', 'refunded', 'failed' ) );

// Barre de progression : 9 étapes avec devis, 7 sans (les états 4 et 5 sont masqués).
$has_quote  = function_exists( 'gacct_quote_has_quote_context' ) ? gacct_quote_has_quote_context( $order, $etat ) : true;
$step_total = $has_quote ? 9 : 7;
if ( null === $etat ) {
	$step_pos = 0;
} elseif ( $has_quote || $etat <= 3 ) {
	$step_pos = min( 8, $etat ) + 1;
} else {
	$step_pos = $etat - 1; // 6→5, 7→6, 8→7 sur la frise à 7 étapes.
}
$pct       = null === $etat ? 0 : (int) round( $step_pos / $step_total * 100 );
$state_txt = null === $etat ? '' : ( isset( $labels[ $etat ] ) ? $labels[ $etat ] : '' );
$needs_you = in_array( $etat, array( 0, 4, 6 ), true );

// État 5 : le libellé précise la décision rendue sur le devis.
if ( 5 === $etat && function_exists( 'gacct_state5_suffix' ) ) {
	$state_txt .= gacct_state5_suffix( $order );
}
?>
<div class="gacct-vo">

	<!-- ── En-tête ─────────────────────────────────────────────────── -->
	<div class="gacct-vo-card gacct-vo-head">
		<div class="gacct-vo-head-row">
			<div>
				<p class="gacct-vo-kicker"><?php esc_html_e( 'Commande', 'gestion-atelier-cct' ); ?></p>
				<h2 class="gacct-vo-ref"><?php echo esc_html( $d['reference'] ); ?></h2>
				<p class="gacct-vo-meta">
					<?php
					printf(
						/* translators: 1: date, 2: moyen de paiement */
						esc_html__( 'Passée le %1$s · %2$s', 'gestion-atelier-cct' ),
						esc_html( $d['order_date'] ),
						esc_html( $d['payment_title'] )
					);
					?>
					<?php if ( ! empty( $d['materiel'] ) ) : ?>
						<br><span class="gacct-vo-materiel"><?php echo esc_html( $d['materiel'] ); ?></span>
					<?php endif; ?>
				</p>
			</div>
			<span class="gacct-vo-badge<?php echo $is_dead ? ' is-dead' : ( $needs_you ? ' is-action' : '' ); ?>">
				<?php echo esc_html( $d['status_label'] ); ?>
			</span>
		</div>

		<?php if ( null !== $etat && ! $is_dead ) : ?>
			<div class="gacct-vo-progress" role="img" aria-label="<?php echo esc_attr( $state_txt ); ?>">
				<div class="gacct-vo-progress-bar<?php echo $needs_you ? ' is-warning' : ''; ?>" style="width:<?php echo (int) $pct; ?>%"></div>
			</div>
			<p class="gacct-vo-state">
				<strong><?php echo esc_html( $state_txt ); ?></strong>
				<span><?php echo esc_html( sprintf( __( 'étape %1$d sur %2$d', 'gestion-atelier-cct' ), $step_pos, $step_total ) ); ?></span>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $d['hold']['active'] ) && ! $is_dead ) : ?>
			<div class="gacct-vo-hold">
				<strong><?php esc_html_e( 'Dossier momentanément en attente', 'gestion-atelier-cct' ); ?></strong>
				<?php if ( '' !== $d['hold']['motif'] ) : ?>
					<p><?php echo nl2br( esc_html( $d['hold']['motif'] ) ); ?></p>
				<?php endif; ?>
				<p class="gacct-vo-hold-note"><?php esc_html_e( 'Nous vous préviendrons par email dès que votre dossier reprendra son cours.', 'gestion-atelier-cct' ); ?></p>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( 4 === $etat ) : ?>
		<!-- ── Devis en attente de réponse ─────────────────────────── -->
		<div class="gacct-vo-card gacct-vo-alert">
			<h3><?php esc_html_e( 'Un devis attend votre réponse', 'gestion-atelier-cct' ); ?></h3>
			<p>
				<?php
				echo esc_html( $d['quote_sent_at']
					? sprintf( __( 'Après inspection de votre matériel, nous vous avons envoyé un devis complémentaire par email le %s. Ouvrez cet email pour l\'accepter ou le refuser en un clic — l\'intervention ne peut pas commencer sans votre réponse.', 'gestion-atelier-cct' ), date_i18n( get_option( 'date_format' ), strtotime( $d['quote_sent_at'] ) ) )
					: __( 'Après inspection de votre matériel, nous vous avons envoyé un devis complémentaire par email. Ouvrez cet email pour l\'accepter ou le refuser en un clic — l\'intervention ne peut pas commencer sans votre réponse.', 'gestion-atelier-cct' ) );
				?>
			</p>
			<p class="gacct-vo-muted">
				<?php
				printf(
					esc_html__( 'Email introuvable ? Appelez-nous au %1$s (%2$s), nous vous le renverrons.', 'gestion-atelier-cct' ),
					'<strong>' . esc_html( $d['contact_phone'] ) . '</strong>',
					esc_html( $d['contact_hours'] )
				);
				?>
			</p>
		</div>
	<?php elseif ( 5 === $etat && 'refused' === $d['quote']['decision'] ) : ?>
		<div class="gacct-vo-card gacct-vo-alert is-neutral">
			<h3><?php esc_html_e( 'Devis refusé — c\'est noté', 'gestion-atelier-cct' ); ?></h3>
			<p>
				<?php
				echo esc_html( 'return' === $d['quote']['mode']
					? __( 'Aucune réparation ne sera engagée : nous préparons le retour de votre matériel.', 'gestion-atelier-cct' )
					: __( 'Les travaux complémentaires ne seront pas réalisés : nous effectuons les prestations initialement commandées, sans changement de prix.', 'gestion-atelier-cct' ) );
				?>
			</p>
		</div>
	<?php elseif ( 5 === $etat && 'accepted' === $d['quote']['decision'] ) : ?>
		<div class="gacct-vo-card gacct-vo-alert is-neutral">
			<h3><?php esc_html_e( 'Devis validé — merci !', 'gestion-atelier-cct' ); ?></h3>
			<p><?php esc_html_e( 'Les travaux complémentaires sont lancés. Le solde vous sera demandé à la fin de l\'intervention.', 'gestion-atelier-cct' ); ?></p>
		</div>
	<?php elseif ( 6 === $etat && $d['solde_du'] > 0 ) : ?>
		<div class="gacct-vo-card gacct-vo-alert">
			<h3><?php esc_html_e( 'Votre intervention est terminée !', 'gestion-atelier-cct' ); ?></h3>
			<p><?php echo esc_html( sprintf( __( 'Il ne reste que le solde de %s à régler pour que votre matériel reparte vers vous.', 'gestion-atelier-cct' ), $vo_fmt( $d['solde_du'] ) ) ); ?></p>
			<a class="gacct-vo-btn" href="<?php echo esc_url( $d['pay_url'] ); ?>"><?php esc_html_e( 'Régler le solde', 'gestion-atelier-cct' ); ?></a>
		</div>
	<?php endif; ?>

	<!-- ── Détail & montants ───────────────────────────────────────── -->
	<div class="gacct-vo-card">
		<h3><?php esc_html_e( 'Détail de votre commande', 'gestion-atelier-cct' ); ?></h3>

		<table class="gacct-vo-table">
			<tbody>
				<?php foreach ( $d['initial_items'] as $item ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $item['name'] ); ?>
							<?php if ( $item['qty'] > 1 ) : ?><span class="gacct-vo-qty">× <?php echo (int) $item['qty']; ?></span><?php endif; ?>
						</td>
						<td class="gacct-vo-amount"><?php echo esc_html( $vo_fmt( $item['total'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>

				<?php if ( ! empty( $d['extra_items'] ) ) : ?>
					<tr class="gacct-vo-row-sep">
						<td colspan="2"><?php echo esc_html( 'accepted' === $d['quote']['decision'] ? __( 'Travaux complémentaires (devis accepté)', 'gestion-atelier-cct' ) : __( 'Travaux complémentaires (devis en attente)', 'gestion-atelier-cct' ) ); ?></td>
					</tr>
					<?php foreach ( $d['extra_items'] as $item ) : ?>
						<tr class="gacct-vo-row-extra">
							<td>
								<?php echo esc_html( $item['name'] ); ?>
								<?php if ( $item['qty'] > 1 ) : ?><span class="gacct-vo-qty">× <?php echo (int) $item['qty']; ?></span><?php endif; ?>
							</td>
							<td class="gacct-vo-amount"><?php echo esc_html( $vo_fmt( $item['total'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
			<tfoot>
				<tr class="gacct-vo-total">
					<td><?php esc_html_e( 'Total de la commande', 'gestion-atelier-cct' ); ?></td>
					<td class="gacct-vo-amount"><?php echo esc_html( $vo_fmt( $d['total_initial'] ) ); ?></td>
				</tr>
				<tr class="gacct-vo-paid">
					<td><?php echo esc_html( $is_bacs_waiting ? __( 'Acompte à régler par virement', 'gestion-atelier-cct' ) : __( 'Acompte réglé', 'gestion-atelier-cct' ) ); ?></td>
					<td class="gacct-vo-amount"><?php echo esc_html( $vo_fmt( $d['deposit'] ) ); ?></td>
				</tr>
				<tr class="gacct-vo-balance">
					<td>
						<?php esc_html_e( 'Reste à payer', 'gestion-atelier-cct' ); ?>
						<span class="gacct-vo-balance-hint"><?php echo esc_html( 6 === $etat ? __( '(à régler maintenant)', 'gestion-atelier-cct' ) : __( '(demandé à la fin de l\'intervention)', 'gestion-atelier-cct' ) ); ?></span>
					</td>
					<td class="gacct-vo-amount"><?php echo esc_html( $vo_fmt( $d['balance'] ) ); ?></td>
				</tr>
			</tfoot>
		</table>
	</div>

	<?php if ( $is_bacs_waiting && ! empty( $d['bank_rows'] ) ) : ?>
		<!-- ── Virement attendu ────────────────────────────────────── -->
		<div class="gacct-vo-card gacct-vo-bank">
			<h3><?php esc_html_e( 'Votre virement est attendu', 'gestion-atelier-cct' ); ?></h3>
			<p>
				<?php
				printf(
					esc_html__( 'Merci d\'effectuer le virement de l\'acompte (%1$s) avant le %2$s — il reste %3$s. Passé ce délai, le créneau est libéré et la commande annulée automatiquement.', 'gestion-atelier-cct' ),
					'<strong>' . esc_html( $vo_fmt( $d['deposit'] ) ) . '</strong>',
					'<strong>' . esc_html( $d['deadline_label'] ) . '</strong>',
					'<strong>' . esc_html( sprintf( _n( '%d jour', '%d jours', $d['days_remaining'], 'gestion-atelier-cct' ), $d['days_remaining'] ) ) . '</strong>'
				);
				?>
			</p>
			<table class="gacct-vo-table gacct-vo-bank-table">
				<tbody>
					<?php foreach ( $d['bank_rows'] as $row ) : ?>
						<tr<?php echo ! empty( $row['highlight'] ) ? ' class="is-highlight"' : ''; ?>>
							<td><?php echo esc_html( $row['label'] ); ?></td>
							<td class="gacct-vo-amount"><?php echo esc_html( $row['value'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<?php if ( null !== $etat && $etat <= 1 && ! $is_dead ) : ?>
		<!-- ── Expédition du matériel ──────────────────────────────── -->
		<div class="gacct-vo-card">
			<h3><?php esc_html_e( 'Expédiez votre matériel', 'gestion-atelier-cct' ); ?></h3>
			<p>
				<?php if ( ! empty( $d['parcel_label'] ) ) : ?>
					<?php printf( esc_html__( 'Votre colis doit nous parvenir au plus tard le %1$s (prise en charge atelier le %2$s).', 'gestion-atelier-cct' ), '<strong>' . esc_html( $d['parcel_label'] ) . '</strong>', esc_html( $d['slot_label'] ) ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Envoyez votre matériel à l\'atelier dès que possible.', 'gestion-atelier-cct' ); ?>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $d['store_address'] ) ) : ?>
				<p class="gacct-vo-address"><?php echo esc_html( implode( ' · ', $d['store_address'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( function_exists( 'gacct_conf_feature' ) && gacct_conf_feature( 'work_order' ) && ! empty( $d['links']['work_order'] ) ) : ?>
				<a class="gacct-vo-btn is-secondary" href="<?php echo esc_url( $d['links']['work_order'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Imprimer le bon d\'intervention', 'gestion-atelier-cct' ); ?></a>
			<?php endif; ?>
			<?php if ( function_exists( 'gacct_ship_render_form' ) ) : ?>
				<?php echo gacct_ship_render_form( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML construit et échappé par le module shipping. ?>
			<?php endif; ?>
		</div>
	<?php elseif ( null !== $etat && ! $is_dead && function_exists( 'gacct_ship_info' ) && gacct_ship_info( $d['revision_id'] ) ) : ?>
		<!-- ── Suivi du colis aller (lecture seule, matériel déjà réceptionné) ── -->
		<div class="gacct-vo-card">
			<h3><?php esc_html_e( 'Votre envoi vers l\'atelier', 'gestion-atelier-cct' ); ?></h3>
			<?php echo gacct_ship_render_form( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML construit et échappé par le module shipping. ?>
		</div>
	<?php endif; ?>

	<?php
	// Le rapport n'est visible du client qu'à partir de l'état 7 (solde réglé).
	$vo_show_report = ( '' !== $d['rapport_url'] && null !== $etat && $etat >= 7 );
	?>
	<?php if ( '' !== $d['suivi'] || $vo_show_report ) : ?>
		<!-- ── Retour & documents ──────────────────────────────────── -->
		<div class="gacct-vo-card<?php echo 8 === $etat ? ' is-highlight' : ''; ?>">
			<h3><?php esc_html_e( 'Retour & documents', 'gestion-atelier-cct' ); ?></h3>
			<?php if ( 8 === $etat ) : ?>
				<p><?php esc_html_e( 'Votre matériel est reparti vers vous — suivez son acheminement ci-dessous.', 'gestion-atelier-cct' ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $d['suivi'] ) : ?>
				<?php if ( preg_match( '#^https?://#i', $d['suivi'] ) ) : ?>
					<p><a class="gacct-vo-btn is-secondary" href="<?php echo esc_url( $d['suivi'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Suivre mon colis retour', 'gestion-atelier-cct' ); ?></a></p>
				<?php else : ?>
					<p><?php printf( esc_html__( 'Suivi transporteur : %s', 'gestion-atelier-cct' ), '<strong>' . esc_html( $d['suivi'] ) . '</strong>' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $vo_show_report ) : ?>
				<?php foreach ( ( ! empty( $d['rapport_liens'] ) ? $d['rapport_liens'] : array( array( 'url' => $d['rapport_url'], 'index' => 0 ) ) ) as $vo_rapport ) : ?>
					<p><a class="gacct-vo-btn is-secondary" href="<?php echo esc_url( $vo_rapport['url'] ); ?>" target="_blank" rel="noopener"><?php
						if ( $vo_rapport['index'] > 0 ) {
							printf( esc_html__( 'Télécharger le rapport d\'intervention %d (PDF)', 'gestion-atelier-cct' ), (int) $vo_rapport['index'] + 1 );
						} else {
							esc_html_e( 'Télécharger le rapport d\'intervention (PDF)', 'gestion-atelier-cct' );
						}
					?></a></p>
					<?php endforeach; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<!-- ── Adresse de facturation ──────────────────────────────────── -->
	<div class="gacct-vo-card gacct-vo-billing">
		<h3><?php esc_html_e( 'Adresse de facturation', 'gestion-atelier-cct' ); ?></h3>
		<address><?php echo wp_kses_post( $order->get_formatted_billing_address( esc_html__( 'Non renseignée', 'gestion-atelier-cct' ) ) ); ?></address>
		<?php if ( $order->get_billing_phone() ) : ?>
			<p class="gacct-vo-muted"><?php echo esc_html( $order->get_billing_phone() ); ?></p>
		<?php endif; ?>
	</div>

	<p class="gacct-vo-help">
		<?php
		printf(
			esc_html__( 'Une question sur cette commande ? Appelez-nous au %1$s (%2$s).', 'gestion-atelier-cct' ),
			'<strong>' . esc_html( $d['contact_phone'] ) . '</strong>',
			esc_html( $d['contact_hours'] )
		);
		?>
	</p>

</div>
