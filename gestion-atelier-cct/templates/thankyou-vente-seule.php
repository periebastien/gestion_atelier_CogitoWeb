<?php
/**
 * Page de confirmation d'une VENTE SEULE (gacct-vente-seule.php, 05/10/2026) :
 * suspente sans intervention, payée à 100 %, sans créneau ni matériel. Incluse
 * par templates/thankyou.php, mêmes classes CSS (gacct-conf).
 *
 * Variables : $order (WC_Order), $d (gacct_conf_data()).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$vs_bacs    = ( 'bacs' === $d['variant'] );
$vs_locked  = ! empty( $d['shipping_locked'] );
$vs_total   = wp_strip_all_tags( wc_price( (float) $order->get_total() ) );
$vs_textes  = function_exists( 'gacct_vente_seule_textes' ) ? gacct_vente_seule_textes() : array();
$vs_guide   = $d['links']['packing_guide'];
$vs_notice  = gacct_conf_notice();
?>
<div class="gacct-conf gacct-conf--vente-seule">

	<div class="conf<?php echo $vs_bacs && $vs_locked ? ' wait' : ''; ?>">
		<div class="gacct-conf-wrap">
			<div class="conf-mark"><?php echo gacct_conf_icon( $vs_bacs && $vs_locked ? 'clock' : 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<div class="conf-eyebrow">
				<?php echo esc_html( $vs_bacs && $vs_locked ? __( 'En attente de votre virement', 'gestion-atelier-cct' ) : __( 'Commande confirmée', 'gestion-atelier-cct' ) ); ?>
			</div>
			<h1 class="conf-h1">
				<?php printf( /* translators: 1: prénom */ esc_html__( 'Merci %s,', 'gestion-atelier-cct' ), esc_html( $d['first_name'] ) ); ?>
				<em><?php echo esc_html( $vs_locked ? __( 'votre commande est enregistrée', 'gestion-atelier-cct' ) : __( 'votre commande est réglée', 'gestion-atelier-cct' ) ); ?></em>
			</h1>
			<p class="conf-sub">
				<?php if ( $vs_locked ) : ?>
					<?php
					printf(
						/* translators: 1: montant, 2: e-mail */
						esc_html__( 'Elle sera confirmée dès réception de votre virement de %1$s. Les coordonnées bancaires viennent aussi de partir sur %2$s.', 'gestion-atelier-cct' ),
						'<strong>' . esc_html( $vs_total ) . '</strong>',
						'<strong>' . esc_html( $d['email'] ) . '</strong>'
					);
					?>
				<?php else : ?>
					<?php
					printf(
						/* translators: 1: montant, 2: e-mail */
						esc_html__( 'Vous avez réglé %1$s, la totalité de votre commande. Un récapitulatif vient de partir sur %2$s.', 'gestion-atelier-cct' ),
						'<strong>' . esc_html( $vs_total ) . '</strong>',
						'<strong>' . esc_html( $d['email'] ) . '</strong>'
					);
					?>
				<?php endif; ?>
			</p>
		</div>
	</div>

	<main class="gacct-conf-main">
		<div class="gacct-conf-wrap">

			<?php if ( $vs_notice ) : ?>
				<p class="gacct-conf-notice<?php echo $vs_notice['ok'] ? ' is-ok' : ' is-warn'; ?>" id="gacct-conf-notice">
					<?php echo gacct_conf_icon( $vs_notice['ok'] ? 'check' : 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span><?php echo esc_html( $vs_notice['text'] ); ?></span>
				</p>
			<?php endif; ?>

			<div class="cols">
				<div>

					<?php if ( $vs_bacs && $vs_locked ) : ?>
						<div class="card" id="coord">
							<div class="card-head">
								<h2><?php esc_html_e( 'Effectuez votre virement', 'gestion-atelier-cct' ); ?></h2>
								<span class="card-hint"><?php printf( /* translators: 1: montant, 2: date limite */ esc_html__( '%1$s · avant le %2$s', 'gestion-atelier-cct' ), esc_html( $vs_total ), esc_html( $d['deadline_label'] ) ); ?></span>
							</div>
							<div class="bank">
								<?php foreach ( $d['bank_rows'] as $row ) : ?>
									<div class="bank-row<?php echo $row['highlight'] ? ' ref' : ''; ?>">
										<div>
											<div class="bank-label"><?php echo esc_html( $row['label'] ); ?></div>
											<div class="bank-val"><?php echo esc_html( $row['value'] ); ?></div>
										</div>
										<button type="button" class="bank-copy" data-copy="<?php echo esc_attr( $row['copy'] ); ?>"><?php echo gacct_conf_icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Copier', 'gestion-atelier-cct' ); ?></button>
									</div>
								<?php endforeach; ?>
							</div>
							<p class="bank-warn">
								<?php echo gacct_conf_icon( 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span><strong><?php esc_html_e( 'La référence est obligatoire dans le libellé du virement', 'gestion-atelier-cct' ); ?></strong> : <?php esc_html_e( 'sans elle, nous ne pouvons pas rattacher votre paiement à votre commande.', 'gestion-atelier-cct' ); ?></span>
							</p>
						</div>
					<?php endif; ?>

					<div class="card">
						<div class="card-head">
							<h2><?php esc_html_e( 'Envoyez-nous votre suspente', 'gestion-atelier-cct' ); ?></h2>
						</div>
						<?php if ( $vs_locked ) : ?>
							<p class="step-txt"><?php esc_html_e( 'Cette étape s’ouvrira dès la réception de votre paiement : vous recevrez alors par e-mail les consignes d’envoi et l’adresse de l’atelier.', 'gestion-atelier-cct' ); ?></p>
						<?php else : ?>
							<ol class="step-txt gacct-ship-steps">
								<li><?php echo esc_html( $vs_textes['etape1'] ?? '' ); ?></li>
								<li><?php printf( esc_html( $vs_textes['etape2'] ?? '%s' ), '<strong>' . esc_html( $d['reference'] ) . '</strong>' ); ?></li>
								<li>
									<?php echo esc_html( $vs_textes['etape3'] ?? '' ); ?><?php if ( ! empty( $d['store_address'] ) ) : ?> : <strong><?php echo esc_html( implode( ', ', $d['store_address'] ) ); ?></strong><?php endif; ?>.
								</li>
								<li><?php echo esc_html( $vs_textes['etape4'] ?? '' ); ?></li>
							</ol>
							<?php if ( ! empty( $d['store_address'] ) ) : ?>
								<div class="todo-cta">
									<button type="button" class="btn-secondary" data-copy="<?php echo esc_attr( implode( ', ', $d['store_address'] ) ); ?>" data-copy-msg="<?php esc_attr_e( 'Adresse copiée', 'gestion-atelier-cct' ); ?>"><?php echo gacct_conf_icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Copier l’adresse', 'gestion-atelier-cct' ); ?></button>
									<?php if ( $vs_guide ) : ?>
										<a href="<?php echo esc_url( $vs_guide ); ?>" class="btn-secondary"><?php echo gacct_conf_icon( 'package' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $vs_textes['guide'] ?? '' ); ?></a>
									<?php endif; ?>
								</div>
							<?php endif; ?>
							<?php if ( function_exists( 'gacct_ship_render_form' ) ) : ?>
								<?php echo gacct_ship_render_form( $order, array( 'intro' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML construit et échappé par le module shipping. ?>
							<?php endif; ?>
						<?php endif; ?>
					</div>

					<div class="card">
						<div class="card-head">
							<h2><?php esc_html_e( 'Et ensuite ?', 'gestion-atelier-cct' ); ?></h2>
						</div>
						<p class="step-txt"><?php esc_html_e( 'Nous refaisons votre suspente dès sa réception, puis nous vous la renvoyons selon le mode de retour choisi. Vous suivez chaque étape dans votre espace client.', 'gestion-atelier-cct' ); ?></p>
					</div>
				</div>

				<aside>
					<div class="side-card">
						<h3><?php esc_html_e( 'Suivez votre commande', 'gestion-atelier-cct' ); ?></h3>
						<div class="side-links">
							<a href="<?php echo esc_url( $d['links']['account'] ); ?>" class="side-link">
								<?php echo gacct_conf_icon( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span><strong><?php esc_html_e( 'Ouvrir mon espace client', 'gestion-atelier-cct' ); ?></strong><small><?php esc_html_e( 'État de la commande', 'gestion-atelier-cct' ); ?></small></span>
							</a>
							<?php if ( $vs_guide ) : ?>
								<a href="<?php echo esc_url( $vs_guide ); ?>" class="side-link">
									<?php echo gacct_conf_icon( 'package' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<span><strong><?php echo esc_html( $vs_textes['guide'] ?? '' ); ?></strong><small><?php echo esc_html( $vs_textes['guide_desc'] ?? '' ); ?></small></span>
								</a>
							<?php endif; ?>
							<a href="<?php echo esc_url( $d['links']['contact'] ); ?>" class="side-link">
								<?php echo gacct_conf_icon( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span><strong><?php esc_html_e( 'Nous contacter', 'gestion-atelier-cct' ); ?></strong><small><?php esc_html_e( 'Une question sur votre commande', 'gestion-atelier-cct' ); ?></small></span>
							</a>
						</div>
					</div>
					<div class="side-card">
						<h3><?php esc_html_e( 'Récapitulatif', 'gestion-atelier-cct' ); ?></h3>
						<div class="side-rows">
							<div class="side-row"><span><?php esc_html_e( 'Commande', 'gestion-atelier-cct' ); ?></span><span><?php echo esc_html( $d['reference'] ); ?></span></div>
							<div class="side-row"><span><?php esc_html_e( 'Date', 'gestion-atelier-cct' ); ?></span><span><?php echo esc_html( $d['order_date'] ); ?></span></div>
							<?php foreach ( $order->get_items() as $vs_item ) : ?>
								<div class="side-row"><span><?php echo esc_html( $vs_item->get_name() . ( $vs_item->get_quantity() > 1 ? ' × ' . (int) $vs_item->get_quantity() : '' ) ); ?></span><span><?php echo esc_html( wp_strip_all_tags( wc_price( (float) $vs_item->get_total() + (float) $vs_item->get_total_tax() ) ) ); ?></span></div>
							<?php endforeach; ?>
							<div class="side-row"><span><?php echo esc_html( $vs_locked ? __( 'À virer', 'gestion-atelier-cct' ) : __( 'Réglé', 'gestion-atelier-cct' ) ); ?></span><span class="<?php echo $vs_locked ? 'warn' : 'ok'; ?>"><?php echo esc_html( $vs_total ); ?></span></div>
						</div>
					</div>
				</aside>
			</div>
		</div>
	</main>
</div>
