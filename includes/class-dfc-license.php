<?php
/**
 * DadsFam Cache — license client for the DF Licenses system.
 *
 * Talks to: POST {DFC_LICENSE_API}
 *   body: license_key, site_url, plugin_ver, product
 *   response: { "valid": true|false, "message": "...", ... }
 *
 * Design choices that keep paying customers happy:
 *  - A network blip NEVER downgrades a working licence (7-day grace window).
 *  - Re-validation happens quietly on a daily cron.
 *  - Deactivation is local-only (the API has no remote-deactivate endpoint).
 *
 * @package DadsFam_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DFC_License {

	const OPTION = 'dfc_license';
	const CRON   = 'dfc_license_check';
	const GRACE  = 7 * DAY_IN_SECONDS;

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'revalidate' ) );
	}

	public static function data() {
		$d = get_option( self::OPTION );
		return wp_parse_args(
			is_array( $d ) ? $d : array(),
			array(
				'key'          => '',
				'status'       => 'inactive', // inactive | active | invalid | grace
				'message'      => '',
				'expires'      => '',
				'last_check'   => 0,
				'last_success' => 0,
			)
		);
	}

	/** The single question the rest of the plugin asks. */
	public static function is_pro() {
		if ( defined( 'DFC_FORCE_PRO' ) && DFC_FORCE_PRO ) {
			return true;
		}
		$d = self::data();
		if ( 'active' === $d['status'] ) {
			return true;
		}
		// Grace: the key worked recently but the last check couldn't reach the server.
		if ( 'grace' === $d['status'] && $d['last_success'] && ( time() - $d['last_success'] ) < self::GRACE ) {
			return true;
		}
		return false;
	}

	/** Activate (or re-check) a key. Returns the stored data array or WP_Error. */
	public static function activate( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			return new WP_Error( 'dfc_empty_key', __( 'Please paste your license key first.', 'dadsfam-cache' ) );
		}

		$result = self::remote_verify( $key );

		$d        = self::data();
		$d['key'] = $key;

		if ( is_wp_error( $result ) ) {
			// Couldn't reach the server. Don't punish the customer for that.
			if ( 'active' === $d['status'] || 'grace' === $d['status'] ) {
				$d['status'] = 'grace';
			}
			$d['message']    = $result->get_error_message();
			$d['last_check'] = time();
			update_option( self::OPTION, $d, false );
			return $result;
		}

		$d['status']     = $result['valid'] ? 'active' : 'invalid';
		$d['message']    = $result['message'];
		$d['expires']    = $result['expires'];
		$d['last_check'] = time();
		if ( $result['valid'] ) {
			$d['last_success'] = time();
		}
		update_option( self::OPTION, $d, false );

		// Make sure the daily re-check is scheduled.
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON );
		}

		return $d;
	}

	/** Local clear only — wipes the key off this site. */
	public static function deactivate() {
		delete_option( self::OPTION );
		return true;
	}

	/** Daily cron: quietly re-confirm the stored key. */
	public static function revalidate() {
		$d = self::data();
		if ( '' === $d['key'] || 'invalid' === $d['status'] ) {
			return;
		}
		$result = self::remote_verify( $d['key'] );

		if ( is_wp_error( $result ) ) {
			if ( 'active' === $d['status'] ) {
				$d['status'] = 'grace';
			}
			$d['message']    = $result->get_error_message();
			$d['last_check'] = time();
			update_option( self::OPTION, $d, false );
			return;
		}

		$d['status']     = $result['valid'] ? 'active' : 'invalid';
		$d['message']    = $result['message'];
		$d['expires']    = $result['expires'];
		$d['last_check'] = time();
		if ( $result['valid'] ) {
			$d['last_success'] = time();
		}
		update_option( self::OPTION, $d, false );
	}

	/**
	 * Hit the verify endpoint and normalise the answer.
	 *
	 * @return array{valid:bool,message:string,expires:string}|WP_Error
	 */
	private static function remote_verify( $key ) {
		$response = wp_remote_post(
			DFC_LICENSE_API,
			array(
				'timeout' => 15,
				'body'    => array(
					'license_key' => $key,
					'site_url'    => home_url(),
					'plugin_ver'  => DFC_VERSION,
					'product'     => 'dadsfam-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'dfc_network',
				__( 'Could not reach the license server. Your Pro features stay on for now — we will retry automatically.', 'dadsfam-cache' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 500 || ! is_array( $body ) ) {
			return new WP_Error(
				'dfc_server',
				__( 'The license server gave an unexpected answer. We will retry automatically.', 'dadsfam-cache' )
			);
		}

		// Normalise across possible response shapes.
		$valid = false;
		if ( array_key_exists( 'valid', $body ) ) {
			$valid = (bool) $body['valid'];
		} elseif ( array_key_exists( 'success', $body ) ) {
			$valid = (bool) $body['success'];
		} elseif ( isset( $body['status'] ) ) {
			$valid = in_array( strtolower( (string) $body['status'] ), array( 'valid', 'active', 'ok' ), true );
		}

		$message = '';
		foreach ( array( 'message', 'msg', 'error' ) as $k ) {
			if ( ! empty( $body[ $k ] ) && is_string( $body[ $k ] ) ) {
				$message = sanitize_text_field( $body[ $k ] );
				break;
			}
		}
		if ( '' === $message ) {
			$message = $valid
				? __( 'License active — Pro features unlocked. Lekker!', 'dadsfam-cache' )
				: __( 'That key was not accepted.', 'dadsfam-cache' );
		}

		$expires = '';
		foreach ( array( 'expires', 'expiry', 'expires_at', 'expiration' ) as $k ) {
			if ( ! empty( $body[ $k ] ) && is_string( $body[ $k ] ) ) {
				$expires = sanitize_text_field( $body[ $k ] );
				break;
			}
		}

		return array( 'valid' => $valid, 'message' => $message, 'expires' => $expires );
	}
}
