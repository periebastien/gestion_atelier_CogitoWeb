<?php
/**
 * Page /devis-a-valider/ — standalone (incluse hors thème, puis exit).
 *
 * $data fourni par gacct_quote_render_page() (includes/gacct-quote.php).
 * Variantes :
 * - quote           : devis à consulter, boutons Accepter / Refuser ;
 * - accepted        : confirmation d'acceptation ;
 * - refused_partial : refus, intervention sur les prestations initiales ;
 * - refused_return  : refus d'une pure demande de devis, retour du matériel ;
 * - used            : lien déjà consommé.
 *
 * White-label : logo + couleur = réglages emails WooCommerce, aucun texte
 * de marque codé en dur.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$q_plugin_file = dirname( __DIR__ ) . '/gestion-atelier-cct.php';
$q_css_url     = plugins_url( 'assets/css/quote.css', $q_plugin_file );
$q_css_file    = dirname( __DIR__ ) . '/assets/css/quote.css';
$q_css_ver     = file_exists( $q_css_file ) ? (string) filemtime( $q_css_file ) : '1';

$q_variant = $data['variant'];
$q_accent  = sanitize_hex_color( $data['accent'] );
$q_accent  = $q_accent ? $q_accent : '#20c4c3';

// URL d'action des formulaires : la page courante, token compris.
$q_action_url = '';

if ( 'quote' === $q_variant ) {
	$q_action_url = add_query_arg(
		array(
			'order_id' => absint( $_GET['order_id'] ?? 0 ),
			'token'    => sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ),
		),
		home_url( '/' . trim( apply_filters( 'gacct_validation_path', 'devis-a-valider' ), '/' ) . '/' )
	);
}

$q_titles = array(
	'quote'           => __( 'Votre devis mis à jour', 'gestion-atelier-cct' ),
	'accepted'        => __( 'Devis accepté — merci !', 'gestion-atelier-cct' ),
	'refused_partial' => __( 'Refus bien enregistré', 'gestion-atelier-cct' ),
	'refused_return'  => __( 'Refus bien enregistré', 'gestion-atelier-cct' ),
	'used'            => __( 'Ce lien a déjà été utilisé', 'gestion-atelier-cct' ),
);
$q_title  = isset( $q_titles[ $q_variant ] ) ? $q_titles[ $q_variant ] : $q_titles['quote'];

$q_fmt = static function ( $amount ) {
	return number_format( (float) $amount, 2, ',', ' ' ) . ' €';
};
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $q_title . ' — ' . $data['site_name'] ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', $q_css_ver, $q_css_url ) ); ?>">
<style>:root{--gq-accent:<?php echo esc_html( $q_accent ); ?>;}</style>
</head>
<body class="gq-body">

<main class="gq-page">

	<header class="gq-brand">
		<?php if ( ! empty( $data['logo_url'] ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img class="gq-logo" src="<?php echo esc_url( $data['logo_url'] ); ?>" alt="<?php echo esc_attr( $data['site_name'] ); ?>"></a>
		<?php else : ?>
			<a class="gq-logo-text" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $data['site_name'] ); ?></a>
		<?php endif; ?>
	</header>

<?php if ( 'quote' === $q_variant ) : ?>

	<section class="gq-card">
		<p class="gq-kicker"><?php esc_html_e( 'Devis complémentaire', 'gestion-atelier-cct' ); ?><?php if ( ! empty( $data['reference'] ) ) : ?> · <?php echo esc_html( $data['reference'] ); ?><?php endif; ?></p>
		<h1 class="gq-title"><?php echo esc_html( $q_title ); ?></h1>

		<p class="gq-intro">
			<?php
			echo esc_html( sprintf(
				/* translators: %s: prénom */
				__( 'Bonjour %s, après inspection de votre matériel à l\'atelier, voici les travaux que nous vous recommandons.', 'gestion-atelier-cct' ),
				$data['first_name'] ? $data['first_name'] : __( 'à vous', 'gestion-atelier-cct' )
			) );
			?>
			<?php if ( ! empty( $data['materiel'] ) ) : ?>
				<span class="gq-materiel"><?php echo esc_html( $data['materiel'] ); ?></span>
			<?php endif; ?>
		</p>

		<?php if ( '' !== trim( (string) $data['comment'] ) ) : ?>
			<blockquote class="gq-comment">
				<span class="gq-comment-label"><?php esc_html_e( 'Le mot de l\'atelier', 'gestion-atelier-cct' ); ?></span>
				<?php echo esc_html( $data['comment'] ); ?>
			</blockquote>
		<?php endif; ?>

		<table class="gq-table">
			<tbody>
				<?php foreach ( $data['initial_items'] as $item ) : ?>
					<tr class="gq-row-initial">
						<td>
							<?php echo esc_html( $item['name'] ); ?>
							<?php if ( $item['qty'] > 1 ) : ?><span class="gq-qty">× <?php echo (int) $item['qty']; ?></span><?php endif; ?>
						</td>
						<td class="gq-amount"><?php echo esc_html( $q_fmt( $item['total'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>

				<?php if ( ! empty( $data['extra_items'] ) ) : ?>
					<tr class="gq-row-sep">
						<td colspan="2"><?php esc_html_e( 'Travaux complémentaires proposés', 'gestion-atelier-cct' ); ?></td>
					</tr>
					<?php foreach ( $data['extra_items'] as $item ) : ?>
						<tr class="gq-row-extra">
							<td>
								<span class="gq-plus" aria-hidden="true">+</span>
								<?php echo esc_html( $item['name'] ); ?>
								<?php if ( $item['qty'] > 1 ) : ?><span class="gq-qty">× <?php echo (int) $item['qty']; ?></span><?php endif; ?>
							</td>
							<td class="gq-amount"><?php echo esc_html( $q_fmt( $item['total'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
			<tfoot>
				<tr class="gq-total">
					<td><?php esc_html_e( 'Nouveau total de la commande', 'gestion-atelier-cct' ); ?></td>
					<td class="gq-amount"><?php echo esc_html( $q_fmt( $data['total_initial'] ) ); ?></td>
				</tr>
				<tr class="gq-paid">
					<td><?php esc_html_e( 'Acompte déjà réglé', 'gestion-atelier-cct' ); ?></td>
					<td class="gq-amount">− <?php echo esc_html( $q_fmt( $data['deposit'] ) ); ?></td>
				</tr>
				<tr class="gq-balance">
					<td><?php esc_html_e( 'Solde à régler à la fin de l\'intervention', 'gestion-atelier-cct' ); ?></td>
					<td class="gq-amount"><?php echo esc_html( $q_fmt( $data['new_balance'] ) ); ?></td>
				</tr>
			</tfoot>
		</table>

		<p class="gq-note">
			<?php esc_html_e( 'Rien à payer aujourd\'hui : le solde ne vous sera demandé qu\'une fois l\'intervention terminée.', 'gestion-atelier-cct' ); ?>
		</p>

		<?php if ( ! empty( $data['is_devis_only'] ) ) : ?>
			<p class="gq-warning">
				<?php esc_html_e( 'Votre commande est une demande de devis : si vous refusez, aucune réparation ne sera engagée et votre matériel vous sera retourné.', 'gestion-atelier-cct' ); ?>
			</p>
		<?php else : ?>
			<p class="gq-note gq-note-refuse">
				<?php esc_html_e( 'Si vous refusez, pas d\'inquiétude : nous réaliserons les prestations initialement commandées, sans changement de prix.', 'gestion-atelier-cct' ); ?>
			</p>
		<?php endif; ?>

		<div class="gq-actions">
			<form method="post" action="<?php echo esc_url( $q_action_url ); ?>">
				<input type="hidden" name="gacct_quote_action" value="accept">
				<button type="submit" class="gq-btn gq-btn-accept"><?php esc_html_e( 'J\'accepte le devis', 'gestion-atelier-cct' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( $q_action_url ); ?>" data-gq-refuse>
				<input type="hidden" name="gacct_quote_action" value="refuse">
				<button type="submit" class="gq-btn gq-btn-refuse"><?php esc_html_e( 'Je refuse le devis', 'gestion-atelier-cct' ); ?></button>
			</form>
		</div>
	</section>

<?php elseif ( 'accepted' === $q_variant ) : ?>

	<section class="gq-card gq-card-confirm">
		<div class="gq-confirm-icon gq-icon-ok" aria-hidden="true">✓</div>
		<h1 class="gq-title"><?php echo esc_html( $q_title ); ?></h1>
		<p class="gq-intro">
			<?php esc_html_e( 'Votre accord est bien enregistré : l\'atelier peut lancer l\'ensemble des travaux. Le solde vous sera demandé une fois l\'intervention terminée.', 'gestion-atelier-cct' ); ?>
		</p>
		<a class="gq-btn gq-btn-accept" href="<?php echo esc_url( $data['account_url'] ); ?>"><?php esc_html_e( 'Suivre mon intervention', 'gestion-atelier-cct' ); ?></a>
	</section>

<?php elseif ( 'refused_partial' === $q_variant ) : ?>

	<section class="gq-card gq-card-confirm">
		<div class="gq-confirm-icon gq-icon-info" aria-hidden="true">✓</div>
		<h1 class="gq-title"><?php echo esc_html( $q_title ); ?></h1>
		<p class="gq-intro">
			<?php esc_html_e( 'Les travaux complémentaires ne seront pas réalisés. Nous effectuons l\'intervention initialement commandée, sans changement de prix. Un email de confirmation vient de vous être envoyé.', 'gestion-atelier-cct' ); ?>
		</p>
		<a class="gq-btn gq-btn-accept" href="<?php echo esc_url( $data['account_url'] ); ?>"><?php esc_html_e( 'Suivre mon intervention', 'gestion-atelier-cct' ); ?></a>
	</section>

<?php elseif ( 'refused_return' === $q_variant ) : ?>

	<section class="gq-card gq-card-confirm">
		<div class="gq-confirm-icon gq-icon-info" aria-hidden="true">✓</div>
		<h1 class="gq-title"><?php echo esc_html( $q_title ); ?></h1>
		<p class="gq-intro">
			<?php esc_html_e( 'Aucune réparation ne sera engagée : nous préparons le retour de votre matériel. Un email de confirmation vient de vous être envoyé — si vous changez d\'avis avant l\'expédition, contactez-nous vite.', 'gestion-atelier-cct' ); ?>
		</p>
		<a class="gq-btn gq-btn-accept" href="<?php echo esc_url( $data['account_url'] ); ?>"><?php esc_html_e( 'Accéder à mon espace client', 'gestion-atelier-cct' ); ?></a>
	</section>

<?php else : /* used */ ?>

	<section class="gq-card gq-card-confirm">
		<div class="gq-confirm-icon gq-icon-info" aria-hidden="true">i</div>
		<h1 class="gq-title"><?php echo esc_html( $q_title ); ?></h1>
		<p class="gq-intro">
			<?php esc_html_e( 'Votre réponse à ce devis a déjà été prise en compte : ce lien ne peut servir qu\'une seule fois. Retrouvez l\'état de votre intervention dans votre espace client.', 'gestion-atelier-cct' ); ?>
		</p>
		<a class="gq-btn gq-btn-accept" href="<?php echo esc_url( $data['account_url'] ); ?>"><?php esc_html_e( 'Accéder à mon espace client', 'gestion-atelier-cct' ); ?></a>
	</section>

<?php endif; ?>

	<footer class="gq-footer">
		<?php
		printf(
			/* translators: 1: téléphone, 2: horaires */
			esc_html__( 'Une question ? Appelez-nous au %1$s (%2$s) ou répondez à l\'email reçu.', 'gestion-atelier-cct' ),
			'<strong>' . esc_html( $data['contact_phone'] ) . '</strong>',
			esc_html( $data['contact_hours'] )
		);
		?>
	</footer>

</main>

<script>
document.querySelectorAll('[data-gq-refuse]').forEach(function (form) {
	form.addEventListener('submit', function (event) {
		if (!window.confirm(<?php echo wp_json_encode( empty( $data['is_devis_only'] )
			? __( 'Confirmer le refus du devis ? Les travaux complémentaires ne seront pas réalisés, seules les prestations initialement commandées le seront.', 'gestion-atelier-cct' )
			: __( 'Confirmer le refus du devis ? Aucune réparation ne sera engagée et votre matériel vous sera retourné.', 'gestion-atelier-cct' ) ); ?>)) {
			event.preventDefault();
		}
	});
});
</script>

</body>
</html>
