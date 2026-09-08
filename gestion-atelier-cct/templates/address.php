<?php
/**
 * Page « Adresse de livraison » de l'espace client (/mon-compte/adresse-de-livraison/).
 *
 * Inclus par le shortcode `[gacct_adresse]` (includes/gacct-address.php).
 * Rendu DANS la zone de contenu du compte, aucune <table> (reset Elementor).
 *
 * Variables : $data (gacct_address_data()), $texts, $notice.
 *
 * @package gestion-atelier-cct
 */

defined( 'ABSPATH' ) || exit;

$t = static function ( $key ) use ( $texts ) {
	return isset( $texts[ $key ] ) ? $texts[ $key ] : '';
};

$fields = gacct_address_fields();

$render_fields = static function ( $prefix, array $values ) use ( $fields, $t, $data ) {
	?>
	<div class="gacct-addr-grid">
		<?php foreach ( $fields as $f => $required ) : ?>
			<?php $id = 'gacct_' . $prefix . $f; ?>
			<div class="gacct-addr-field gacct-addr-field--<?php echo esc_attr( $f ); ?>">
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $t( $f ) ); ?>
					<?php if ( $required ) : ?><span class="gacct-addr-req" aria-hidden="true">*</span><?php endif; ?>
				</label>
				<?php if ( 'country' === $f ) : ?>
					<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $prefix . $f ); ?>" <?php echo $required ? 'required' : ''; ?>>
						<?php foreach ( $data['countries'] as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $values[ $f ], $code ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $prefix . $f ); ?>" value="<?php echo esc_attr( $values[ $f ] ); ?>" <?php echo $required ? 'required' : ''; ?> autocomplete="<?php echo esc_attr( 'shipping_' === $prefix ? 'shipping ' : 'billing ' ); ?><?php echo esc_attr( str_replace( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'postcode', 'city' ), array( 'given-name', 'family-name', 'organization', 'address-line1', 'address-line2', 'postal-code', 'address-level2' ), $f ) ); ?>">
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		<?php if ( 'shipping_' === $prefix ) : ?>
			<div class="gacct-addr-field gacct-addr-field--phone">
				<label for="gacct_shipping_phone"><?php echo esc_html( $t( 'phone' ) ); ?></label>
				<input type="tel" id="gacct_shipping_phone" name="shipping_phone" value="<?php echo esc_attr( $values['phone'] ); ?>" autocomplete="shipping tel">
			</div>
		<?php endif; ?>
	</div>
	<?php
};
?>
<noscript>
	<style>.gacct-addr-billing[hidden] { display: block; }</style>
</noscript>

<div class="gacct-addr">

	<?php if ( $notice ) : ?>
		<div class="gacct-addr-notice gacct-addr-notice--<?php echo esc_attr( $notice['type'] ); ?>" role="status"><?php echo esc_html( $notice['message'] ); ?></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( gacct_address_url() ); ?>" class="gacct-addr-form">
		<?php wp_nonce_field( 'gacct_adresse', 'gacct_adresse_nonce' ); ?>

		<section class="gacct-addr-card">
			<h3 class="gacct-addr-card__title"><?php echo esc_html( $t( 'ship_title' ) ); ?></h3>
			<p class="gacct-addr-card__intro"><?php echo esc_html( $t( 'ship_intro' ) ); ?></p>
			<?php if ( gacct_address_is_empty( $data['shipping'] ) ) : ?>
				<p class="gacct-addr-empty"><?php echo esc_html( $t( 'empty_hint' ) ); ?></p>
			<?php endif; ?>
			<?php $render_fields( 'shipping_', $data['shipping'] ); ?>
		</section>

		<section class="gacct-addr-card">
			<h3 class="gacct-addr-card__title"><?php echo esc_html( $t( 'bill_title' ) ); ?></h3>
			<p class="gacct-addr-card__intro"><?php echo esc_html( $t( 'bill_intro' ) ); ?></p>
			<label class="gacct-addr-check">
				<input type="checkbox" name="billing_same" value="1" <?php checked( $data['same'] ); ?> data-gacct-same>
				<span><?php echo esc_html( $t( 'same' ) ); ?></span>
			</label>
			<div class="gacct-addr-billing" data-gacct-billing <?php echo $data['same'] ? 'hidden' : ''; ?>>
				<?php $render_fields( 'billing_', $data['billing'] ); ?>
			</div>
		</section>

		<div class="gacct-addr-actions">
			<button type="submit" name="gacct_adresse_submit" value="1" class="gacct-addr-btn"><?php echo esc_html( $t( 'save' ) ); ?></button>
		</div>
	</form>
</div>
<script>
( function () {
	var same = document.querySelector( '[data-gacct-same]' );
	var bill = document.querySelector( '[data-gacct-billing]' );
	if ( ! same || ! bill ) { return; }
	function sync() {
		bill.hidden = same.checked;
		bill.querySelectorAll( '[required]' ).forEach( function ( el ) { el.disabled = same.checked; } );
	}
	same.addEventListener( 'change', sync );
	sync();
} )();
</script>
