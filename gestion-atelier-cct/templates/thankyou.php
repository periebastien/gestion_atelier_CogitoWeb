<?php
/**
 * Template de la page "Commande reçue" (remplace checkout/thankyou.php).
 *
 * Reçoit $order de WooCommerce (API stable). Deux variantes :
 * - 'paid'  : acompte encaissé (carte…), créneau confirmé ;
 * - 'bacs'  : virement en attente, créneau retenu provisoirement ;
 * - 'failed': retombe sur le comportement WooCommerce (paiement échoué).
 *
 * @package gestion-atelier-cct
 * @var WC_Order|false $order
 */

defined( 'ABSPATH' ) || exit;

if ( ! $order instanceof WC_Order ) : ?>
	<div class="gacct-conf"><div class="gacct-conf-wrap">
		<div class="conf"><h1><?php esc_html_e( 'Merci. Votre commande a bien été reçue.', 'gestion-atelier-cct' ); ?></h1></div>
	</div></div>
	<?php
	return;
endif;

$d = gacct_conf_data( $order );

if ( 'failed' === $d['variant'] ) : ?>
	<div class="gacct-conf"><div class="gacct-conf-wrap">
		<div class="conf conf-failed">
			<h1><?php esc_html_e( 'Le paiement n’a pas abouti', 'gestion-atelier-cct' ); ?></h1>
			<p class="conf-sub">
				<?php esc_html_e( 'Votre commande n’a pas pu être finalisée. Vous pouvez réessayer le paiement ou nous contacter.', 'gestion-atelier-cct' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="btn-primary"><?php esc_html_e( 'Réessayer le paiement', 'gestion-atelier-cct' ); ?></a>
				<a href="<?php echo esc_url( $d['links']['account'] ); ?>" class="btn-secondary"><?php esc_html_e( 'Mon compte', 'gestion-atelier-cct' ); ?></a>
			</p>
		</div>
	</div></div>
	<?php
	return;
endif;

$is_bacs = ( 'bacs' === $d['variant'] );
?>
<div class="gacct-conf">

	<!-- ── EN-TÊTE DE CONFIRMATION ── -->
	<div class="conf<?php echo $is_bacs ? ' wait' : ''; ?>">
		<div class="gacct-conf-wrap">
			<div class="conf-mark"><?php echo gacct_conf_icon( $is_bacs ? 'clock' : 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<div class="conf-eyebrow">
				<?php echo esc_html( $is_bacs ? __( 'En attente de votre virement', 'gestion-atelier-cct' ) : __( 'Commande confirmée', 'gestion-atelier-cct' ) ); ?>
			</div>
			<h1 class="conf-h1">
				<?php
				printf(
					/* translators: 1: prénom */
					esc_html__( 'Merci %s,', 'gestion-atelier-cct' ),
					esc_html( $d['first_name'] )
				);
				?>
				<em><?php echo esc_html( $is_bacs ? __( 'votre créneau est retenu', 'gestion-atelier-cct' ) : __( 'votre créneau est réservé', 'gestion-atelier-cct' ) ); ?></em>
			</h1>
			<p class="conf-sub">
				<?php if ( $is_bacs ) : ?>
					<?php if ( $d['slot_label'] ) : ?>
						<?php
						printf(
							/* translators: 1: date créneau */
							esc_html__( 'Le %s vous est provisoirement gardé à l’atelier.', 'gestion-atelier-cct' ),
							'<strong>' . esc_html( $d['slot_label'] ) . '</strong>'
						);
						?>
					<?php endif; ?>
					<?php
					printf(
						/* translators: 1: email */
						esc_html__( ' Il vous sera définitivement acquis dès la réception de votre virement d’acompte. Les coordonnées bancaires viennent aussi de partir sur %s.', 'gestion-atelier-cct' ),
						'<strong>' . esc_html( $d['email'] ) . '</strong>'
					);
					?>
				<?php else : ?>
					<?php if ( $d['slot_label'] ) : ?>
						<?php
						printf(
							/* translators: 1: date créneau */
							esc_html__( 'Votre acompte est encaissé et le %s vous est réservé à l’atelier.', 'gestion-atelier-cct' ),
							'<strong>' . esc_html( $d['slot_label'] ) . '</strong>'
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'Votre acompte est encaissé et votre créneau atelier est réservé.', 'gestion-atelier-cct' ); ?>
					<?php endif; ?>
					<?php
					printf(
						/* translators: 1: email */
						esc_html__( ' Un récapitulatif vient de partir sur %s.', 'gestion-atelier-cct' ),
						'<strong>' . esc_html( $d['email'] ) . '</strong>'
					);
					?>
				<?php endif; ?>
			</p>

			<?php if ( $is_bacs ) : ?>
				<div class="deadline">
					<span class="deadline-txt">
						<?php
						printf(
							/* translators: 1: date limite */
							esc_html__( 'Virement à recevoir avant le %s', 'gestion-atelier-cct' ),
							esc_html( $d['deadline_label'] )
						);
						?>
					</span>
					<span class="deadline-count"><b><?php echo esc_html( $d['days_remaining'] ); ?></b>
						<?php echo esc_html( _n( 'jour restant', 'jours restants', $d['days_remaining'], 'gestion-atelier-cct' ) ); ?>
					</span>
				</div>
			<?php endif; ?>

			<div class="conf-meta">
				<div class="conf-meta-item">
					<div class="conf-meta-label"><?php esc_html_e( 'Commande', 'gestion-atelier-cct' ); ?></div>
					<div class="conf-meta-val"><?php echo esc_html( $d['reference'] ); ?></div>
				</div>
				<div class="conf-meta-item">
					<div class="conf-meta-label"><?php esc_html_e( 'Date', 'gestion-atelier-cct' ); ?></div>
					<div class="conf-meta-val"><?php echo esc_html( $d['order_date'] ); ?></div>
				</div>
				<?php if ( $d['slot_label'] ) : ?>
					<div class="conf-meta-item">
						<div class="conf-meta-label"><?php echo esc_html( $is_bacs ? __( 'Créneau retenu', 'gestion-atelier-cct' ) : __( 'Créneau réservé', 'gestion-atelier-cct' ) ); ?></div>
						<div class="conf-meta-val"><?php echo esc_html( $d['slot_label'] ); ?></div>
					</div>
				<?php endif; ?>
				<div class="conf-meta-item">
					<div class="conf-meta-label"><?php esc_html_e( 'Moyen de paiement', 'gestion-atelier-cct' ); ?></div>
					<div class="conf-meta-val">
						<?php echo esc_html( $d['payment_title'] ); ?>
						<?php if ( $is_bacs ) : ?><small><?php esc_html_e( 'en attente', 'gestion-atelier-cct' ); ?></small><?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>

	<main class="gacct-conf-main">
		<div class="gacct-conf-wrap">

			<!-- ── PAIEMENT EN DEUX TEMPS ── -->
			<div class="pay<?php echo $is_bacs ? ' wait' : ''; ?>">
				<div class="pay-top">
					<div>
						<div class="pay-title"><?php esc_html_e( 'Votre paiement se fait', 'gestion-atelier-cct' ); ?> <em><?php esc_html_e( 'en deux temps', 'gestion-atelier-cct' ); ?></em></div>
						<p class="pay-desc">
							<?php echo esc_html( $is_bacs
								? __( 'L’acompte confirme votre créneau. Le solde n’est réglé qu’une fois la révision terminée, après validation de votre rapport et avant l’expédition retour.', 'gestion-atelier-cct' )
								: __( 'L’acompte réserve votre créneau. Le solde n’est réglé qu’une fois la révision terminée, après validation de votre rapport et avant l’expédition retour.', 'gestion-atelier-cct' ) ); ?>
						</p>
					</div>
					<div class="pay-total">
						<div class="pay-total-label"><?php esc_html_e( 'Estimation totale', 'gestion-atelier-cct' ); ?></div>
						<div class="pay-total-val"><?php echo gacct_conf_amount( $d['total_initial'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
					</div>
				</div>
				<div class="pay-bar"><div class="pay-bar-paid" style="width:<?php echo esc_attr( min( 100, $d['percent'] ) ); ?>%"></div></div>
				<div class="pay-legend">
					<?php if ( $is_bacs ) : ?>
						<div class="pay-leg now">
							<div class="pay-leg-head">
								<span class="pay-leg-dot"><?php echo gacct_conf_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<span class="pay-leg-name">
									<?php
									printf(
										/* translators: 1: nb de jours */
										esc_html( _n( 'Acompte à virer sous %d jour', 'Acompte à virer sous %d jours', $d['days_remaining'], 'gestion-atelier-cct' ) ),
										(int) $d['days_remaining']
									);
									?>
								</span>
							</div>
							<div class="pay-leg-amount"><?php echo gacct_conf_amount( $d['deposit'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
							<p class="pay-leg-note">
								<?php
								printf(
									/* translators: 1: date limite */
									esc_html__( 'À recevoir avant le %s. Il confirme votre créneau et sera déduit du solde.', 'gestion-atelier-cct' ),
									'<b>' . esc_html( $d['deadline_label'] ) . '</b>'
								);
								?>
							</p>
						</div>
					<?php else : ?>
						<div class="pay-leg done">
							<div class="pay-leg-head">
								<span class="pay-leg-dot"><?php echo gacct_conf_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<span class="pay-leg-name"><?php esc_html_e( 'Acompte réglé aujourd’hui', 'gestion-atelier-cct' ); ?></span>
							</div>
							<div class="pay-leg-amount"><?php echo gacct_conf_amount( $d['deposit'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
							<p class="pay-leg-note">
								<?php
								printf(
									/* translators: 1: date, 2: moyen de paiement */
									esc_html__( 'Encaissé le %1$s (%2$s). Il garantit votre créneau et sera déduit du solde.', 'gestion-atelier-cct' ),
									'<b>' . esc_html( $d['deposit_date'] ) . '</b>',
									esc_html( strtolower( $d['payment_title'] ) )
								);
								?>
							</p>
							<a href="<?php echo esc_url( $d['links']['receipt'] ); ?>" class="pay-receipt"><?php echo gacct_conf_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Télécharger le reçu', 'gestion-atelier-cct' ); ?></a>
						</div>
					<?php endif; ?>
					<div class="pay-leg <?php echo $is_bacs ? 'later' : 'todo'; ?>">
						<div class="pay-leg-head">
							<span class="pay-leg-dot"></span>
							<span class="pay-leg-name"><?php esc_html_e( 'Solde à régler en fin d’intervention', 'gestion-atelier-cct' ); ?></span>
						</div>
						<div class="pay-leg-amount"><?php echo gacct_conf_amount( $d['balance'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
						<p class="pay-leg-note"><?php esc_html_e( 'Montant estimé, ajusté selon le diagnostic réel. Payable en ligne avant l’expédition retour, jamais avant votre accord sur le rapport.', 'gestion-atelier-cct' ); ?></p>
					</div>
				</div>
			</div>

			<div class="cols">
				<div>

					<?php if ( $is_bacs ) : ?>
						<!-- ── COORDONNÉES BANCAIRES ── -->
						<div class="card" id="coord">
							<div class="card-head">
								<h2><?php esc_html_e( 'Effectuez votre virement', 'gestion-atelier-cct' ); ?></h2>
								<span class="card-hint">
									<?php
									printf(
										/* translators: 1: montant, 2: date limite */
										esc_html__( 'Acompte de %1$s · avant le %2$s', 'gestion-atelier-cct' ),
										esc_html( wp_strip_all_tags( wc_price( $d['deposit'] ) ) ),
										esc_html( $d['deadline_label'] )
									);
									?>
								</span>
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
								<span>
									<strong>
										<?php
										printf(
											/* translators: 1: référence */
											esc_html__( 'Indiquez bien la référence %s dans le libellé du virement.', 'gestion-atelier-cct' ),
											esc_html( $d['reference'] )
										);
										?>
									</strong>
									<?php esc_html_e( ' Sans elle, nous ne pouvons pas rattacher votre paiement à votre commande, et le créneau risque d’être libéré alors que vous avez payé.', 'gestion-atelier-cct' ); ?>
								</span>
							</p>
							<div class="card-head bank-next"><h2><?php esc_html_e( 'Ce qui se passe ensuite', 'gestion-atelier-cct' ); ?></h2></div>
							<div class="tl">
								<div class="tl-row">
									<span class="tl-when"><?php esc_html_e( 'Dès réception', 'gestion-atelier-cct' ); ?></span>
									<span class="tl-what"><?php esc_html_e( 'Nous vous confirmons l’encaissement par e-mail.', 'gestion-atelier-cct' ); ?> <strong><?php esc_html_e( 'Votre créneau devient définitif', 'gestion-atelier-cct' ); ?></strong> <?php esc_html_e( 'et le bon d’intervention s’active dans votre espace client.', 'gestion-atelier-cct' ); ?></span>
								</div>
								<div class="tl-row">
									<span class="tl-when"><?php printf( /* translators: date */ esc_html__( 'Le %s', 'gestion-atelier-cct' ), esc_html( $d['reminder_label'] ) ); ?></span>
									<span class="tl-what"><?php esc_html_e( 'Si nous n’avons rien reçu, une', 'gestion-atelier-cct' ); ?> <strong><?php esc_html_e( 'relance automatique', 'gestion-atelier-cct' ); ?></strong> <?php esc_html_e( 'vous est envoyée avant l’échéance.', 'gestion-atelier-cct' ); ?></span>
								</div>
								<div class="tl-row cancel">
									<span class="tl-when"><?php printf( /* translators: date */ esc_html__( 'Le %s', 'gestion-atelier-cct' ), esc_html( $d['deadline_label'] ) ); ?></span>
									<span class="tl-what"><?php esc_html_e( 'Sans virement reçu,', 'gestion-atelier-cct' ); ?> <strong><?php esc_html_e( 'le créneau est libéré et la commande annulée.', 'gestion-atelier-cct' ); ?></strong> <?php esc_html_e( 'Vous pourrez en repasser une, mais sur les dates encore disponibles.', 'gestion-atelier-cct' ); ?></span>
								</div>
							</div>
						</div>
					<?php endif; ?>

					<!-- ── ET MAINTENANT ── -->
					<div class="card">
						<div class="card-head">
							<h2><?php esc_html_e( 'Et maintenant ?', 'gestion-atelier-cct' ); ?></h2>
							<span class="card-hint"><?php esc_html_e( 'Suivi en temps réel dans votre espace client', 'gestion-atelier-cct' ); ?></span>
						</div>
						<div class="steps">
							<div class="step done">
								<div class="step-dot"><?php echo gacct_conf_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
								<div class="step-body">
									<div class="step-title"><?php echo esc_html( $is_bacs ? __( 'Commande enregistrée', 'gestion-atelier-cct' ) : __( 'Commande confirmée', 'gestion-atelier-cct' ) ); ?> <span class="step-flag"><?php esc_html_e( 'Fait', 'gestion-atelier-cct' ); ?></span></div>
									<p class="step-txt">
										<?php if ( $is_bacs ) : ?>
											<?php
											printf(
												/* translators: 1: date créneau */
												esc_html__( 'Créneau du %s retenu provisoirement à votre nom.', 'gestion-atelier-cct' ),
												esc_html( $d['slot_label'] ? $d['slot_label'] : __( 'créneau choisi', 'gestion-atelier-cct' ) )
											);
											?>
										<?php else : ?>
											<?php
											printf(
												/* translators: 1: montant, 2: date créneau */
												esc_html__( 'Acompte de %1$s encaissé, créneau du %2$s bloqué à votre nom.', 'gestion-atelier-cct' ),
												esc_html( wp_strip_all_tags( wc_price( $d['deposit'] ) ) ),
												esc_html( $d['slot_label'] ? $d['slot_label'] : __( 'créneau choisi', 'gestion-atelier-cct' ) )
											);
											?>
										<?php endif; ?>
									</p>
								</div>
							</div>

							<?php if ( $is_bacs ) : ?>
								<div class="step wait">
									<div class="step-dot"><?php echo gacct_conf_icon( 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
									<div class="step-body">
										<div class="step-title"><?php esc_html_e( 'Effectuez votre virement', 'gestion-atelier-cct' ); ?>
											<span class="step-flag wait">
												<?php
												printf(
													/* translators: 1: date limite */
													esc_html__( 'À faire avant le %s', 'gestion-atelier-cct' ),
													esc_html( $d['deadline_label'] )
												);
												?>
											</span>
										</div>
										<p class="step-txt">
											<?php
											printf(
												/* translators: 1: montant, 2: référence */
												esc_html__( 'Acompte de %1$s avec la référence %2$s en libellé. Les coordonnées bancaires sont juste au-dessus.', 'gestion-atelier-cct' ),
												'<strong>' . esc_html( wp_strip_all_tags( wc_price( $d['deposit'] ) ) ) . '</strong>',
												'<strong>' . esc_html( $d['reference'] ) . '</strong>'
											);
											?>
										</p>
										<div class="step-action">
											<a href="#coord" class="btn-primary"><?php esc_html_e( 'Voir les coordonnées bancaires', 'gestion-atelier-cct' ); ?> <?php echo gacct_conf_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
										</div>
									</div>
								</div>
								<div class="step locked">
									<div class="step-dot"><?php echo gacct_conf_icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
									<div class="step-body">
										<div class="step-title"><?php esc_html_e( 'Expédiez votre matériel', 'gestion-atelier-cct' ); ?> <span class="step-flag lock"><?php esc_html_e( 'Après réception du virement', 'gestion-atelier-cct' ); ?></span></div>
										<p class="step-txt">
											<?php esc_html_e( 'Le', 'gestion-atelier-cct' ); ?> <strong><?php esc_html_e( 'bon d’intervention', 'gestion-atelier-cct' ); ?></strong> <?php esc_html_e( 's’active dès l’encaissement.', 'gestion-atelier-cct' ); ?>
											<?php if ( $d['parcel_label'] ) : ?>
												<?php
												printf(
													/* translators: 1: date */
													esc_html__( ' Votre matériel doit ensuite nous parvenir au plus tard le %s, la veille de votre créneau.', 'gestion-atelier-cct' ),
													'<strong>' . esc_html( $d['parcel_label'] ) . '</strong>'
												);
												?>
											<?php endif; ?>
										</p>
									</div>
								</div>
							<?php else : ?>
								<div class="step now">
									<div class="step-dot"><?php echo gacct_conf_icon( 'clipboard' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
									<div class="step-body">
										<div class="step-title"><?php esc_html_e( 'Expédiez votre matériel', 'gestion-atelier-cct' ); ?> <span class="step-flag wait"><?php esc_html_e( 'À faire', 'gestion-atelier-cct' ); ?></span></div>
										<p class="step-txt">
											<?php esc_html_e( 'Glissez le', 'gestion-atelier-cct' ); ?> <strong><?php esc_html_e( 'bon d’intervention', 'gestion-atelier-cct' ); ?></strong> <?php esc_html_e( 'dans le colis', 'gestion-atelier-cct' ); ?><?php if ( $d['parcel_label'] ) : ?><?php printf( /* translators: date */ esc_html__( ' et envoyez-le pour qu’il arrive avant le %s', 'gestion-atelier-cct' ), '<strong>' . esc_html( $d['parcel_label'] ) . '</strong>' ); ?><?php endif; ?>. <?php esc_html_e( 'Sans lui, l’identification prend plusieurs jours de plus.', 'gestion-atelier-cct' ); ?>
										</p>
										<div class="step-action">
											<a href="<?php echo esc_url( $d['links']['work_order'] ); ?>" class="btn-primary"><?php esc_html_e( 'Imprimer le bon', 'gestion-atelier-cct' ); ?> <?php echo gacct_conf_icon( 'printer' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
											<a href="<?php echo esc_url( $d['links']['packing_guide'] ); ?>" class="btn-secondary"><?php echo gacct_conf_icon( 'package' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Consignes d’emballage', 'gestion-atelier-cct' ); ?></a>
										</div>
									</div>
								</div>
							<?php endif; ?>

							<div class="step pending">
								<div class="step-dot"><?php echo gacct_conf_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
								<div class="step-body">
									<div class="step-title"><?php esc_html_e( 'Réception et diagnostic', 'gestion-atelier-cct' ); ?></div>
									<p class="step-txt"><?php esc_html_e( 'Nous confirmons l’arrivée du colis par e-mail dans la journée, puis nous vérifions que l’état du matériel correspond aux prestations commandées.', 'gestion-atelier-cct' ); ?></p>
								</div>
							</div>
							<div class="step pending">
								<div class="step-dot"><?php echo gacct_conf_icon( 'file' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
								<div class="step-body">
									<div class="step-title"><?php esc_html_e( 'Devis complémentaire, si besoin', 'gestion-atelier-cct' ); ?></div>
									<p class="step-txt"><?php esc_html_e( 'Si le diagnostic révèle une réparation ou un supplément, vous recevez un devis à valider.', 'gestion-atelier-cct' ); ?> <strong><?php esc_html_e( 'Rien n’est engagé sans votre accord.', 'gestion-atelier-cct' ); ?></strong></p>
								</div>
							</div>
							<div class="step pending">
								<div class="step-dot"><?php echo gacct_conf_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
								<div class="step-body">
									<div class="step-title"><?php esc_html_e( 'Intervention à l’atelier', 'gestion-atelier-cct' ); ?></div>
									<p class="step-txt">
										<?php if ( $d['slot_label'] ) : ?>
											<?php printf( /* translators: date */ esc_html__( 'Le %s.', 'gestion-atelier-cct' ), esc_html( $d['slot_label'] ) ); ?>
										<?php endif; ?>
										<?php esc_html_e( 'Chaque opération est consignée dans votre rapport, disponible en PDF à la fin.', 'gestion-atelier-cct' ); ?>
									</p>
								</div>
							</div>
							<div class="step pending">
								<div class="step-dot"><?php echo gacct_conf_icon( 'truck' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
								<div class="step-body">
									<div class="step-title"><?php esc_html_e( 'Solde puis expédition retour', 'gestion-atelier-cct' ); ?></div>
									<p class="step-txt">
										<?php
										printf(
											/* translators: 1: montant du solde */
											esc_html__( 'Vous réglez les %s restants en ligne après avoir consulté votre rapport. Le colis part le jour ouvré suivant.', 'gestion-atelier-cct' ),
											esc_html( wp_strip_all_tags( wc_price( $d['balance'] ) ) )
										);
										?>
									</p>
								</div>
							</div>
						</div>
					</div>

					<!-- ── DÉTAIL DE LA COMMANDE ── -->
					<div class="card">
						<div class="card-head">
							<h2><?php esc_html_e( 'Détail de la commande', 'gestion-atelier-cct' ); ?></h2>
							<span class="card-hint"><?php printf( /* translators: référence */ esc_html__( 'Commande %s', 'gestion-atelier-cct' ), esc_html( $d['reference'] ) ); ?></span>
						</div>
						<table class="ord">
							<thead>
								<tr><th><?php esc_html_e( 'Prestation', 'gestion-atelier-cct' ); ?></th><th><?php esc_html_e( 'Qté', 'gestion-atelier-cct' ); ?></th><th><?php esc_html_e( 'Montant', 'gestion-atelier-cct' ); ?></th></tr>
							</thead>
							<tbody>
								<?php foreach ( $order->get_items() as $item ) : ?>
									<?php $line_full = gacct_kojito_montant_ligne( $item ); ?>
									<tr>
										<td><div class="ord-name">
											<?php echo esc_html( $item->get_name() ); ?>
											<?php if ( $d['materiel'] ) : ?><small><?php echo esc_html( $d['materiel'] ); ?></small><?php endif; ?>
										</div></td>
										<td class="ord-qty"><?php echo esc_html( $item->get_quantity() ); ?></td>
										<td class="ord-amt"><?php echo esc_html( wp_strip_all_tags( wc_price( $line_full ) ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<?php if ( (float) $order->get_shipping_total() > 0 ) : ?>
									<tr>
										<td colspan="2" class="ord-lbl"><?php esc_html_e( 'Expédition retour', 'gestion-atelier-cct' ); ?><small><?php echo esc_html( $order->get_shipping_method() ); ?></small></td>
										<td class="ord-amt"><?php echo esc_html( wp_strip_all_tags( wc_price( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax() ) ) ); ?></td>
									</tr>
								<?php endif; ?>
								<tr class="tot">
									<td colspan="2" class="ord-lbl"><?php esc_html_e( 'Estimation totale', 'gestion-atelier-cct' ); ?></td>
									<td class="ord-amt"><?php echo esc_html( wp_strip_all_tags( wc_price( $d['total_initial'] ) ) ); ?></td>
								</tr>
								<?php if ( $is_bacs ) : ?>
									<tr class="due">
										<td colspan="2" class="ord-lbl"><?php esc_html_e( 'Acompte à virer maintenant', 'gestion-atelier-cct' ); ?><small><?php printf( /* translators: 1: date, 2: référence */ esc_html__( 'Avant le %1$s, référence %2$s', 'gestion-atelier-cct' ), esc_html( $d['deadline_label'] ), esc_html( $d['reference'] ) ); ?></small></td>
										<td class="ord-amt"><?php echo esc_html( wp_strip_all_tags( wc_price( $d['deposit'] ) ) ); ?></td>
									</tr>
								<?php else : ?>
									<tr class="paid">
										<td colspan="2" class="ord-lbl"><?php esc_html_e( 'Acompte déjà réglé', 'gestion-atelier-cct' ); ?></td>
										<td class="ord-amt">− <?php echo esc_html( wp_strip_all_tags( wc_price( $d['deposit'] ) ) ); ?></td>
									</tr>
								<?php endif; ?>
								<tr class="due">
									<td colspan="2" class="ord-lbl"><?php esc_html_e( 'Solde estimé, à régler en fin d’intervention', 'gestion-atelier-cct' ); ?></td>
									<td class="ord-amt"><?php echo esc_html( wp_strip_all_tags( wc_price( $d['balance'] ) ) ); ?></td>
								</tr>
							</tfoot>
						</table>

						<?php if ( ! $is_bacs && ! empty( $d['store_address'] ) ) : ?>
							<div class="ship-box">
								<div class="ship-box-label"><?php esc_html_e( 'Expédiez votre matériel à', 'gestion-atelier-cct' ); ?></div>
								<address>
									<?php foreach ( $d['store_address'] as $i => $line ) : ?>
										<?php if ( 0 === $i ) : ?><b><?php echo esc_html( $line ); ?></b><?php else : ?><?php echo esc_html( $line ); ?><?php endif; ?><br>
									<?php endforeach; ?>
								</address>
								<div class="ship-actions">
									<button type="button" class="btn-secondary" data-copy="<?php echo esc_attr( implode( ', ', $d['store_address'] ) ); ?>" data-copy-msg="<?php esc_attr_e( 'Adresse copiée', 'gestion-atelier-cct' ); ?>"><?php echo gacct_conf_icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Copier l’adresse', 'gestion-atelier-cct' ); ?></button>
									<a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo rawurlencode( implode( ' ', array_slice( $d['store_address'], 1 ) ) ); ?>" target="_blank" rel="noopener" class="btn-secondary"><?php echo gacct_conf_icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Itinéraire', 'gestion-atelier-cct' ); ?></a>
								</div>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<!-- ── COLONNE LATÉRALE ── -->
				<aside>
					<div class="side-card">
						<h3><?php esc_html_e( 'Suivez votre intervention', 'gestion-atelier-cct' ); ?></h3>
						<p><?php esc_html_e( 'Chaque étape est mise à jour en direct dans votre espace client, avec le rapport et les devis.', 'gestion-atelier-cct' ); ?></p>
						<div class="side-links">
							<a href="<?php echo esc_url( $d['links']['account'] ); ?>" class="side-link">
								<?php echo gacct_conf_icon( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span><strong><?php esc_html_e( 'Ouvrir mon espace client', 'gestion-atelier-cct' ); ?></strong><small><?php esc_html_e( 'État, devis et rapports', 'gestion-atelier-cct' ); ?></small></span>
							</a>
							<?php if ( $is_bacs ) : ?>
								<a href="<?php echo esc_url( $d['links']['rib_pdf'] ); ?>" class="side-link">
									<?php echo gacct_conf_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<span><strong><?php esc_html_e( 'Télécharger le RIB', 'gestion-atelier-cct' ); ?></strong><small><?php esc_html_e( 'PDF, à donner à votre banque', 'gestion-atelier-cct' ); ?></small></span>
								</a>
							<?php else : ?>
								<a href="<?php echo esc_url( $d['links']['summary_pdf'] ); ?>" class="side-link">
									<?php echo gacct_conf_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<span><strong><?php esc_html_e( 'Télécharger le récapitulatif', 'gestion-atelier-cct' ); ?></strong><small><?php esc_html_e( 'PDF, 1 page', 'gestion-atelier-cct' ); ?></small></span>
								</a>
							<?php endif; ?>
							<a href="<?php echo esc_url( $d['links']['new_request'] ); ?>" class="side-link">
								<?php echo gacct_conf_icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span><strong><?php esc_html_e( 'Ajouter une prestation', 'gestion-atelier-cct' ); ?></strong><small><?php esc_html_e( 'Possible jusqu’à l’expédition de votre matériel', 'gestion-atelier-cct' ); ?></small></span>
							</a>
						</div>
					</div>

					<div class="side-card">
						<h3><?php esc_html_e( 'Récapitulatif', 'gestion-atelier-cct' ); ?></h3>
						<div class="side-rows">
							<div class="side-row"><span><?php esc_html_e( 'Commande', 'gestion-atelier-cct' ); ?></span><span><?php echo esc_html( $d['reference'] ); ?></span></div>
							<?php if ( $d['materiel'] ) : ?>
								<div class="side-row"><span><?php esc_html_e( 'Matériel', 'gestion-atelier-cct' ); ?></span><span><?php echo esc_html( $d['materiel'] ); ?></span></div>
							<?php endif; ?>
							<?php if ( $d['slot_label'] ) : ?>
								<div class="side-row"><span><?php esc_html_e( 'Créneau', 'gestion-atelier-cct' ); ?></span><span><?php echo esc_html( $d['slot_label'] ); ?></span></div>
							<?php endif; ?>
							<?php if ( $d['parcel_label'] ) : ?>
								<div class="side-row"><span><?php esc_html_e( 'Colis attendu avant le', 'gestion-atelier-cct' ); ?></span><span><?php echo esc_html( $d['parcel_label'] ); ?></span></div>
							<?php endif; ?>
							<?php if ( $is_bacs ) : ?>
								<div class="side-row"><span><?php esc_html_e( 'Acompte à virer', 'gestion-atelier-cct' ); ?></span><span class="warn"><?php echo esc_html( wp_strip_all_tags( wc_price( $d['deposit'] ) ) ); ?></span></div>
								<div class="side-row"><span><?php esc_html_e( 'Avant le', 'gestion-atelier-cct' ); ?></span><span class="danger"><?php echo esc_html( $d['deadline_label'] ); ?></span></div>
							<?php else : ?>
								<div class="side-row"><span><?php esc_html_e( 'Acompte réglé', 'gestion-atelier-cct' ); ?></span><span class="ok"><?php echo esc_html( wp_strip_all_tags( wc_price( $d['deposit'] ) ) ); ?></span></div>
							<?php endif; ?>
							<div class="side-row"><span><?php esc_html_e( 'Solde estimé', 'gestion-atelier-cct' ); ?></span><span class="warn"><?php echo esc_html( wp_strip_all_tags( wc_price( $d['balance'] ) ) ); ?></span></div>
						</div>
					</div>

					<div class="side-card">
						<h3><?php esc_html_e( 'Adresse de facturation', 'gestion-atelier-cct' ); ?></h3>
						<p class="side-addr">
							<strong><?php echo esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ); ?></strong>
							<?php echo wp_kses_post( $order->get_billing_address_1() ); ?><br>
							<?php if ( $order->get_billing_address_2() ) : ?><?php echo esc_html( $order->get_billing_address_2() ); ?><br><?php endif; ?>
							<?php echo esc_html( trim( $order->get_billing_postcode() . ' ' . $order->get_billing_city() ) ); ?><br>
							<?php echo esc_html( $d['email'] ); ?><br>
							<?php if ( $order->get_billing_phone() ) : ?><?php echo esc_html( $order->get_billing_phone() ); ?><?php endif; ?>
						</p>
					</div>

					<div class="side-card side-teal">
						<?php if ( $is_bacs ) : ?>
							<h3><?php esc_html_e( 'Virement déjà envoyé ?', 'gestion-atelier-cct' ); ?></h3>
							<p><?php esc_html_e( 'Un virement met un à trois jours ouvrés à nous parvenir. Si vous l’avez fait et que l’échéance approche, appelez-nous : nous gelons votre créneau le temps que le paiement arrive.', 'gestion-atelier-cct' ); ?></p>
						<?php else : ?>
							<h3><?php esc_html_e( 'Une question sur cette commande ?', 'gestion-atelier-cct' ); ?></h3>
							<p>
								<?php
								printf(
									/* translators: 1: horaires */
									esc_html__( 'L’atelier vous répond directement, %s. Munissez-vous de votre numéro de commande.', 'gestion-atelier-cct' ),
									esc_html( $d['contact_hours'] )
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( $d['contact_phone'] ) : ?>
							<a href="tel:<?php echo esc_attr( preg_replace( '/\s+/', '', $d['contact_phone'] ) ); ?>" class="side-tel"><?php echo gacct_conf_icon( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $d['contact_phone'] ); ?></a>
						<?php endif; ?>
					</div>
				</aside>
			</div>
		</div>
	</main>

	<div class="gacct-conf-toast" id="gacct-conf-toast"><?php echo gacct_conf_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span></span></div>
</div>
