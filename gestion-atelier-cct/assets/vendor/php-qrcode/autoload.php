<?php
/**
 * Autoloader PSR-4 manuel pour chillerlan/php-qrcode 5.0.3 +
 * chillerlan/php-settings-container 3.2.1 (vendorés sans composer).
 */
spl_autoload_register( static function ( $class ) {
	$prefixes = array(
		'chillerlan\\QRCode\\'            => __DIR__ . '/qrcode-src/',
		'chillerlan\\Settings\\'          => __DIR__ . '/settings-src/',
	);

	foreach ( $prefixes as $prefix => $dir ) {
		if ( 0 === strpos( $class, $prefix ) ) {
			$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( file_exists( $file ) ) {
				require $file;
			}
			return;
		}
	}
} );
