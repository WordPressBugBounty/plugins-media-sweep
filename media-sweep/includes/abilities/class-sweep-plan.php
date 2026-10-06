<?php
/**
 * Fingerprint-bound sweep plans for Media Sweep.
 *
 * A destructive sweep is gated on a confirmation token that is bound to one
 * exact set of files, one user and one time window. The token is DERIVED from
 * that fingerprint rather than stored, which is what lets the issuing ability
 * (media-sweep/preview-sweep) stay honestly readonly. Single-use enforcement
 * lives on the write side, in consume().
 *
 * @package media-sweep
 */

namespace Media_Sweep\Abilities;

/**
 * Mints and verifies the confirmation token that gates a destructive sweep.
 *
 * Stateless: nothing here holds state between calls except the single-use
 * marker consume() writes, and that is written only by the deleting ability.
 */
class Sweep_Plan {

	/**
	 * Length of one validity bucket, in seconds.
	 *
	 * Verification accepts the current bucket and the previous one, so a plan
	 * is good for between 15 and 30 minutes depending on when it was minted.
	 */
	const BUCKET_SECONDS = 900;

	/**
	 * Transient key prefix for the single-use marker.
	 */
	const USED_PREFIX = 'mswp_sweep_used_';

	/**
	 * Fingerprint one exact set of files.
	 *
	 * Order-independent and count-checked, so adding or removing a single ID
	 * produces a completely different fingerprint.
	 *
	 * @param int   $scan_id       Scan the files belong to.
	 * @param array $file_scan_ids file_scan IDs in the plan.
	 * @return string 40-character hex fingerprint.
	 */
	public static function fingerprint( $scan_id, array $file_scan_ids ) {
		$ids = array_map( 'intval', $file_scan_ids );
		sort( $ids, SORT_NUMERIC );

		return sha1( implode( ',', $ids ) . '|' . count( $ids ) . '|' . (int) $scan_id );
	}

	/**
	 * Mint a confirmation token for a plan.
	 *
	 * Deliberately stores NOTHING. A plan-minting endpoint that wrote a
	 * transient would make its readOnlyHint a lie, and core routes a readonly
	 * ability's /run to GET - so a state-changing call would be served over
	 * GET. Deriving the token instead keeps preview-sweep genuinely read-only.
	 *
	 * @param int   $scan_id       Scan the files belong to.
	 * @param array $file_scan_ids file_scan IDs in the plan.
	 * @return string 32-character token.
	 */
	public static function issue( $scan_id, array $file_scan_ids ) {
		return self::token( self::bucket(), $scan_id, $file_scan_ids );
	}

	/**
	 * Verify a token against the file set presented at execute time.
	 *
	 * The fingerprint is recomputed from the IDs the caller sent in THIS
	 * request, so the token binds this request's own list to the plan the
	 * preview issued: a caller that added or removed an ID is rejected. It
	 * says nothing about the rows themselves - a file_scan that flipped from
	 * unused to in_use since the preview is caught later, by the status
	 * re-read inside Action_Service::delete_file(), which cannot be bypassed
	 * because `force` is hard-coded false.
	 *
	 * @param string $token               Token supplied by the caller.
	 * @param int    $scan_id             Scan the files belong to.
	 * @param array  $live_file_scan_ids  file_scan IDs being acted on now.
	 * @return bool True only when the token matches this exact plan.
	 */
	public static function verify( $token, $scan_id, array $live_file_scan_ids ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}

		$bucket = self::bucket();

		foreach ( array( $bucket, $bucket - 1 ) as $candidate ) {
			if ( hash_equals( self::token( $candidate, $scan_id, $live_file_scan_ids ), $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Claim a token so it can never be replayed.
	 *
	 * The only write in this class, and it happens on the write side only.
	 *
	 * @param string $token Token to claim.
	 * @return bool True when the claim succeeded, false when already used.
	 */
	public static function consume( $token ) {
		$key = self::USED_PREFIX . $token;

		if ( get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, 30 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Seconds a token minted right now still has to live.
	 *
	 * A token minted in bucket B is accepted while the current bucket is B or
	 * B + 1, so it dies at the end of bucket B + 1.
	 *
	 * @return int Remaining lifetime in seconds.
	 */
	public static function expires_in_seconds() {
		return (int) ( ( ( self::bucket() + 2 ) * self::BUCKET_SECONDS ) - time() );
	}

	/**
	 * Build the token for one specific bucket.
	 *
	 * Bound to the current user as well as the file set: one admin's plan is
	 * not another admin's permission slip.
	 *
	 * @param int   $bucket        Bucket number.
	 * @param int   $scan_id       Scan the files belong to.
	 * @param array $file_scan_ids file_scan IDs in the plan.
	 * @return string 32-character token.
	 */
	private static function token( $bucket, $scan_id, array $file_scan_ids ) {
		return substr(
			wp_hash( 'mswp-sweep|' . get_current_user_id() . '|' . (int) $bucket . '|' . self::fingerprint( $scan_id, $file_scan_ids ) ),
			0,
			32
		);
	}

	/**
	 * Current validity bucket.
	 *
	 * @return int Bucket number.
	 */
	private static function bucket() {
		return (int) floor( time() / self::BUCKET_SECONDS );
	}
}
