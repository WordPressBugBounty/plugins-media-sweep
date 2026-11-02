<?php
/**
 * Retry delays: 1 min, 5 min, 30 min, 2 h, 12 h, 24 h, then daily, with full jitter.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

/**
 * Pure delay math (02 section 7).
 */
final class Backoff {

	/**
	 * Step caps in seconds by failure count (1-based); later failures use the last step.
	 *
	 * @var array<int,int>
	 */
	const STEPS = array( 60, 300, 1800, 7200, 43200, 86400 );

	/**
	 * Smallest delay, so jitter never retries at once.
	 */
	const FLOOR = 30;

	/**
	 * Step cap for a failure count.
	 *
	 * @param int $failures Consecutive failures (1 = first).
	 * @return int Seconds.
	 */
	public static function cap( $failures ) {
		$index = max( 1, (int) $failures ) - 1;

		return self::STEPS[ min( $index, count( self::STEPS ) - 1 ) ];
	}

	/**
	 * Delay before the next try; Retry-After always wins when given.
	 *
	 * @param int $failures    Consecutive failures (1 = first).
	 * @param int $retry_after Server Retry-After seconds (0 = none).
	 * @return int Seconds.
	 */
	public static function delay( $failures, $retry_after = 0 ) {
		if ( $retry_after > 0 ) {
			return min( Config::WEEK, (int) $retry_after );
		}

		$cap = self::cap( $failures );

		return max( self::FLOOR, random_int( 0, $cap ) );
	}
}
