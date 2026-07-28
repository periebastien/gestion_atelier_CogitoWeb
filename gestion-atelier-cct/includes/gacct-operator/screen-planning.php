<?php
/**
 * Console atelier — écran « Planning atelier » (CDC §4.5).
 *
 * Markup minimal : le calendrier (FullCalendar), la zone de feedback globale
 * et le panneau caché de mini-fiche + replanification. Tout le dynamique est
 * dans assets/js/operator-planning.js (endpoints gacct_op_planning_events /
 * gacct_op_reschedule). La barre de nav console est rendue par le routeur.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function gacct_op_render_planning_screen() {
	$today = wp_date( 'Y-m-d' );

	// Raccourci « prochaine occupation » : premier créneau occupé ≥ aujourd'hui.
	global $wpdb;
	$day_start = ( new DateTimeImmutable( $today, wp_timezone() ) )->getTimestamp();
	$next_ts   = $wpdb->get_var( $wpdb->prepare(
		"SELECT MIN(CAST(date_reservee AS UNSIGNED)) FROM {$wpdb->prefix}jet_cct_occupation_atelier
		 WHERE cct_status = 'publish' AND CAST(date_reservee AS UNSIGNED) >= %d",
		$day_start
	) );
	?>
	<div class="wrap gacct-op gacct-op-planning">
		<div class="gacct-op-planning-head">
			<h1><?php esc_html_e( 'Planning atelier', 'gestion-atelier-cct' ); ?></h1>
			<?php if ( $next_ts ) : ?>
				<button type="button" class="gacct-op-btn secondary" data-gacct-goto="<?php echo esc_attr( wp_date( 'Y-m-d', (int) $next_ts ) ); ?>">
					<?php
					/* translators: %s: date de la prochaine occupation */
					echo esc_html( sprintf( __( 'Prochaine occupation : %s', 'gestion-atelier-cct' ), wp_date( get_option( 'date_format' ), (int) $next_ts ) ) );
					?>
				</button>
			<?php endif; ?>
		</div>

		<div id="gacct-op-planning-feedback" class="gacct-op-feedback" role="status" aria-live="polite"></div>

		<div id="gacct-op-calendar" class="gacct-op-card"></div>

		<div id="gacct-op-panel-overlay" class="gacct-op-panel-overlay" hidden></div>

		<div id="gacct-op-panel" class="gacct-op-panel" role="dialog" aria-modal="true" aria-labelledby="gacct-op-panel-ref" hidden>
			<button type="button" class="gacct-op-panel-close" data-op-close aria-label="<?php esc_attr_e( 'Fermer', 'gestion-atelier-cct' ); ?>">&#10005;</button>

			<h2 id="gacct-op-panel-ref" class="gacct-op-panel-ref" data-op-slot="ref"></h2>
			<p class="gacct-op-panel-client" data-op-slot="client"></p>

			<div class="gacct-op-panel-meta">
				<span class="gacct-op-badge" data-op-slot="etat"></span>
				<span class="gacct-op-pill-incomplet" data-op-slot="incomplet" hidden><?php esc_html_e( 'Dossier incomplet', 'gestion-atelier-cct' ); ?></span>
			</div>

			<dl class="gacct-op-panel-rows">
				<div class="gacct-op-panel-row" data-op-row="materiel">
					<dt><?php esc_html_e( 'Matériel', 'gestion-atelier-cct' ); ?></dt>
					<dd data-op-slot="materiel"></dd>
				</div>
				<div class="gacct-op-panel-row" data-op-row="duration">
					<dt><?php esc_html_e( 'Durée', 'gestion-atelier-cct' ); ?></dt>
					<dd data-op-slot="duration"></dd>
				</div>
				<div class="gacct-op-panel-row">
					<dt><?php esc_html_e( 'Créneau actuel', 'gestion-atelier-cct' ); ?></dt>
					<dd data-op-slot="current-date"></dd>
				</div>
			</dl>

			<a class="gacct-op-btn secondary gacct-op-panel-fiche" data-op-slot="fiche" href="#"><?php esc_html_e( 'Ouvrir la fiche', 'gestion-atelier-cct' ); ?></a>

			<div class="gacct-op-panel-resched">
				<h3><?php esc_html_e( 'Replanifier', 'gestion-atelier-cct' ); ?></h3>

				<label class="gacct-op-panel-label" for="gacct-op-resched-date"><?php esc_html_e( 'Nouvelle date', 'gestion-atelier-cct' ); ?></label>
				<input type="date" id="gacct-op-resched-date" data-op-field="date" min="<?php echo esc_attr( $today ); ?>">

				<label class="gacct-op-panel-check">
					<input type="checkbox" data-op-field="notify" checked>
					<span><?php esc_html_e( 'Prévenir le client par email', 'gestion-atelier-cct' ); ?></span>
				</label>

				<div class="gacct-op-panel-reason" data-op-row="reason" hidden>
					<label class="gacct-op-panel-label" for="gacct-op-resched-reason">
						<?php esc_html_e( 'Motif', 'gestion-atelier-cct' ); ?>
						<span class="gacct-op-required">(<?php esc_html_e( 'obligatoire', 'gestion-atelier-cct' ); ?>)</span>
					</label>
					<textarea id="gacct-op-resched-reason" data-op-field="reason" rows="2" placeholder="<?php esc_attr_e( 'Motif de la replanification (journalisé)…', 'gestion-atelier-cct' ); ?>"></textarea>
				</div>

				<div class="gacct-op-feedback" data-op-slot="panel-feedback" role="status"></div>

				<button type="button" class="gacct-op-btn" data-op-move><?php esc_html_e( 'Déplacer', 'gestion-atelier-cct' ); ?></button>
			</div>
		</div>
	</div>
	<?php
}
