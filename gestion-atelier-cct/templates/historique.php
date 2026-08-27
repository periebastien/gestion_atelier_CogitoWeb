<?php
/**
 * Gabarit de la page « Mes anciennes révisions ».
 *
 * Variables fournies par gacct_historique_shortcode() :
 *   $data  array  lignes, total, materiels, annee_min, annee_max
 *   $texts array  libellés
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $data['total'] ) ) : ?>

	<div class="gacct-hist gacct-hist-vide">
		<svg viewBox="0 0 24 24" aria-hidden="true">
			<path d="M9 12h6M9 16h6M9 8h2M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>
		</svg>
		<h3><?php echo esc_html( $texts['vide_titre'] ); ?></h3>
		<p><?php echo esc_html( $texts['vide_texte'] ); ?></p>
	</div>

<?php else : ?>

	<div class="gacct-hist" id="gacct-hist">

		<div class="gacct-hist-stats">
			<div class="gacct-hist-stat">
				<span class="gacct-hist-stat-num"><?php echo esc_html( (string) $data['total'] ); ?></span>
				<span class="gacct-hist-stat-label"><?php echo esc_html( $texts['stat_total'] ); ?></span>
			</div>
			<div class="gacct-hist-stat">
				<span class="gacct-hist-stat-num"><?php echo esc_html( (string) $data['materiels'] ); ?></span>
				<span class="gacct-hist-stat-label"><?php echo esc_html( $texts['stat_voiles'] ); ?></span>
			</div>
			<?php if ( $data['annee_min'] ) : ?>
				<div class="gacct-hist-stat">
					<span class="gacct-hist-stat-num"><?php
						echo esc_html(
							$data['annee_min'] === $data['annee_max']
								? (string) $data['annee_min']
								: $data['annee_min'] . ' – ' . $data['annee_max']
						);
					?></span>
					<span class="gacct-hist-stat-label"><?php echo esc_html( $texts['stat_periode'] ); ?></span>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $data['total'] > 5 ) : ?>
			<div class="gacct-hist-search">
				<svg viewBox="0 0 24 24" aria-hidden="true">
					<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>
				</svg>
				<label class="screen-reader-text" for="gacct-hist-q"><?php echo esc_html( $texts['recherche'] ); ?></label>
				<input type="search" id="gacct-hist-q" placeholder="<?php echo esc_attr( $texts['recherche'] ); ?>" autocomplete="off">
			</div>
		<?php endif; ?>

		<table class="gacct-hist-table">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( $texts['col_date'] ); ?></th>
					<th scope="col"><?php echo esc_html( $texts['col_materiel'] ); ?></th>
					<th scope="col"><?php echo esc_html( $texts['col_couleur'] ); ?></th>
					<th scope="col"><?php echo esc_html( $texts['col_rapport'] ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $data['lignes'] as $l ) :
				$materiel = gacct_historique_materiel_libelle( $l );
				$couleur  = '' !== $l['couleur'] ? $l['couleur'] : $l['couleur_origine'];
				$date_fr  = $l['date_revision']
					? date_i18n( 'j M Y', strtotime( $l['date_revision'] ) )
					: '';
				?>
				<tr data-recherche="<?php echo esc_attr( strtolower( $materiel . ' ' . $couleur . ' ' . $l['numero_serie'] . ' ' . $date_fr ) ); ?>">
					<td data-label="<?php echo esc_attr( $texts['col_date'] ); ?>">
						<time datetime="<?php echo esc_attr( (string) $l['date_revision'] ); ?>"><?php echo esc_html( $date_fr ); ?></time>
					</td>
					<td data-label="<?php echo esc_attr( $texts['col_materiel'] ); ?>">
						<span class="gacct-hist-materiel-bloc">
							<span class="gacct-hist-materiel"><?php echo esc_html( $materiel ); ?></span>
							<?php if ( ! empty( $l['numero_serie'] ) ) : ?>
								<span class="gacct-hist-serie"><?php echo esc_html( $l['numero_serie'] ); ?></span>
							<?php endif; ?>
						</span>
					</td>
					<td data-label="<?php echo esc_attr( $texts['col_couleur'] ); ?>">
						<?php
						if ( '' !== $l['couleur'] && function_exists( 'jwcct_render_couleur_swatch' ) ) {
							// Pastille identique à celle de « Mon Matériel ».
							echo wp_kses(
								jwcct_render_couleur_swatch( $l['couleur'] ),
								array( 'div' => array( 'class' => array(), 'style' => array(), 'title' => array() ) )
							);
						} elseif ( preg_match( '/\p{L}/u', (string) $l['couleur_origine'] ) ) {
							// Coloris que la palette ne sait pas lire mais qui reste lisible
							// (« SANGRIA », « virgo ») : on montre la saisie d'origine.
							// Les saisies sans aucune lettre (« .... », « ? ») sont du bruit.
							echo '<span class="gacct-hist-couleur-texte">' . esc_html( $l['couleur_origine'] ) . '</span>';
						} else {
							echo '<span class="gacct-hist-vide-cell">—</span>';
						}
						?>
					</td>
					<td data-label="<?php echo esc_attr( $texts['col_rapport'] ); ?>">
						<?php if ( ! empty( $l['rapport_fichier'] ) ) : ?>
							<a class="gacct-hist-dl" href="<?php echo esc_url( gacct_historique_download_url( $l['id'] ) ); ?>"
							   target="_blank" rel="noopener">
								<svg viewBox="0 0 24 24" aria-hidden="true">
									<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>
								</svg>
								<?php echo esc_html( $texts['telecharger'] ); ?>
							</a>
						<?php else : ?>
							<span class="gacct-hist-vide-cell"><?php echo esc_html( $texts['sans_rapport'] ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="gacct-hist-no-result" hidden><?php echo esc_html( $texts['aucun_result'] ); ?></p>

	</div>

	<?php if ( $data['total'] > 5 ) : ?>
	<script>
	( function () {
		var racine = document.getElementById( 'gacct-hist' );
		if ( ! racine ) { return; }
		var champ = racine.querySelector( '#gacct-hist-q' );
		if ( ! champ ) { return; }
		var lignes = Array.prototype.slice.call( racine.querySelectorAll( 'tbody tr' ) );
		var vide   = racine.querySelector( '.gacct-hist-no-result' );

		champ.addEventListener( 'input', function () {
			var q = champ.value.trim().toLowerCase();
			var visibles = 0;

			lignes.forEach( function ( tr ) {
				var ok = ! q || tr.dataset.recherche.indexOf( q ) !== -1;
				tr.hidden = ! ok;
				if ( ok ) { visibles++; }
			} );

			if ( vide ) { vide.hidden = visibles > 0; }
		} );
	} )();
	</script>
	<?php endif; ?>

<?php endif; ?>
