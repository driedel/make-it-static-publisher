<?php
/**
 * HMAC request signer for the Make it Static API.
 *
 * @package MakeItStatic
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the signature headers required by POST /publish.
 */
final class MIS_Signer {

	/**
	 * Returns the signature headers for a raw request body.
	 *
	 * The timestamp is NOT part of the signed message; the server only
	 * verifies it is within a ±300 second window.
	 *
	 * @param string $raw_body Exact request body string that will be sent.
	 * @param string $secret   Shared HMAC secret.
	 * @return array{X-Signature: string, X-Timestamp: string}
	 */
	public static function signature_headers( string $raw_body, string $secret ): array {
		return array(
			'X-Signature' => hash_hmac( 'sha256', $raw_body, $secret ),
			'X-Timestamp' => (string) time(),
		);
	}
}
