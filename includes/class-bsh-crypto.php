<?php
/**
 * Encrypts secrets (the Basalam token) at rest.
 *
 * The key is derived from BSH_ENCRYPTION_KEY if defined in wp-config.php, otherwise
 * from the site's AUTH salt. A database dump alone is therefore not enough to read
 * the token. If the salts are rotated the token can no longer be decrypted and the
 * user is asked to enter it again (never a silent failure).
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Crypto {

	const PREFIX_SODIUM  = 'bsh1s:';
	const PREFIX_OPENSSL = 'bsh1o:';

	/** Values written before the rename (SalamHub ≤ 0.6.1); still readable. */
	const LEGACY_SODIUM  = 'slh1s:';
	const LEGACY_OPENSSL = 'slh1o:';

	/**
	 * 32-byte binary key.
	 *
	 * @param bool $legacy Key of the pre-rename versions.
	 * @return string
	 */
	private static function key( $legacy = false ) {
		$secret = defined( 'BSH_ENCRYPTION_KEY' ) && BSH_ENCRYPTION_KEY ? BSH_ENCRYPTION_KEY : ( defined( 'SLH_ENCRYPTION_KEY' ) && SLH_ENCRYPTION_KEY ? SLH_ENCRYPTION_KEY : wp_salt( 'auth' ) );
		return hash( 'sha256', ( $legacy ? 'salamhub|' : 'basalamhub|' ) . $secret, true );
	}

	/**
	 * Whether a stored value uses the pre-rename format (re-encrypted by the migration).
	 *
	 * @param string $encoded Stored value.
	 * @return bool
	 */
	public static function is_legacy( $encoded ) {
		return 0 === strpos( (string) $encoded, self::LEGACY_SODIUM ) || 0 === strpos( (string) $encoded, self::LEGACY_OPENSSL );
	}

	/**
	 * @param string $plain Plain text.
	 * @return string Encoded cipher text, or '' for empty input.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );
			return self::PREFIX_SODIUM . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $cipher ) {
				return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}
		// No crypto available at all (extremely rare). Refuse to store in plain text.
		return '';
	}

	/**
	 * @param string $encoded Value produced by encrypt().
	 * @return string|null Plain text, or null when it cannot be decrypted.
	 */
	public static function decrypt( $encoded ) {
		$encoded = (string) $encoded;
		if ( '' === $encoded ) {
			return '';
		}
		$legacy = self::is_legacy( $encoded );
		if ( $legacy ) {
			$encoded = ( 0 === strpos( $encoded, self::LEGACY_SODIUM ) ? self::PREFIX_SODIUM : self::PREFIX_OPENSSL ) . substr( $encoded, strlen( self::LEGACY_SODIUM ) );
		}
		if ( 0 === strpos( $encoded, self::PREFIX_SODIUM ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $encoded, strlen( self::PREFIX_SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return null;
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::key( $legacy ) );
			return false === $plain ? null : $plain;
		}
		if ( 0 === strpos( $encoded, self::PREFIX_OPENSSL ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $encoded, strlen( self::PREFIX_OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return null;
			}
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key( $legacy ), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? null : $plain;
		}
		return null;
	}

	/**
	 * Whether this server can encrypt at all.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' ) || function_exists( 'openssl_encrypt' );
	}

	/**
	 * Masks a secret for display: only the last 4 characters are shown.
	 *
	 * @param string $plain Secret.
	 * @return string
	 */
	public static function mask( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		return str_repeat( '•', 12 ) . substr( $plain, -4 );
	}
}
