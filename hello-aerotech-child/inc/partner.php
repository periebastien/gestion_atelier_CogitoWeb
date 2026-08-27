<?php
/**
 * AEROTECH — partenariat Ailéments.
 *
 * Suivi des clics sortants vers le site du partenaire. Aucun collecteur n'est
 * installé sur le site à ce jour (ni GA4 ni GTM) : l'événement est poussé dans
 * window.dataLayer, et relayé à gtag() si un jour la balise est branchée.
 *
 * Sections remontées, conformes au handoff :
 *   bapteme_pill · bapteme_card · stages_pill · stages_button
 * (+ nav_link pour les entrées de menu, hors périmètre du handoff)
 *
 * @package hello-aerotech-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Domaine du partenaire, filtrable.
 */
function at_partner_domain() {
	return apply_filters( 'at_partner_domain', 'ailements.fr' );
}

/**
 * Script de suivi, injecté en pied de page.
 *
 * Délégation de clic : aucun attribut à poser sur les widgets Elementor, la
 * section est déduite du DOM (#bapteme / #stages) et le type de lien de la
 * classe .at-pp. Un recâblage dans l'éditeur ne casse donc pas le suivi.
 */
function at_partner_tracking() {
	$domain = esc_js( at_partner_domain() );
	?>
<script id="at-partner-tracking">
(function(){
	var DOMAIN = '<?php echo $domain; ?>';
	document.addEventListener('click', function(e){
		var t = e.target;
		if (!t || typeof t.closest !== 'function') { return; }
		var a = t.closest('a[href*="' + DOMAIN + '"]');
		if (!a) { return; }

		var section = a.closest('#bapteme') ? 'bapteme' : (a.closest('#stages') ? 'stages' : 'nav');
		var kind;
		if (a.classList.contains('at-pp')) {
			kind = 'pill';
		} else if ('bapteme' === section) {
			kind = 'card';
		} else if ('stages' === section) {
			kind = 'button';
		} else {
			kind = 'link';
		}

		var payload = {
			partner:  'ailements',
			section:  section + '_' + kind,
			link_url: a.href
		};
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push(Object.assign({ event: 'partner_click' }, payload));
		if ('function' === typeof window.gtag) {
			window.gtag('event', 'partner_click', payload);
		}
	}, true);
})();
</script>
	<?php
}
add_action( 'wp_footer', 'at_partner_tracking', 99 );
