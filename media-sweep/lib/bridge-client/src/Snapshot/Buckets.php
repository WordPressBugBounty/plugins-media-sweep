<?php
/**
 * Count buckets: numbers are never sent, only these ranges.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Snapshot;

/**
 * Fixed bucket lists from 02 section 4.3 and the feedback days_installed field.
 */
final class Buckets {

	/**
	 * Count buckets (counts, subsites, features).
	 *
	 * @var array<int,string>
	 */
	const COUNTS = array( '0', '1-9', '10-99', '100-999', '1k-10k', '10k+' );

	/**
	 * Days-installed buckets for feedback.
	 *
	 * @var array<int,string>
	 */
	const DAYS = array( '0-1', '2-7', '8-30', '31-90', '91-365', '365+' );

	/**
	 * Bucket for a count.
	 *
	 * @param int $n Count.
	 * @return string Bucket.
	 */
	public static function count( $n ) {
		$n = max( 0, (int) $n );

		if ( 0 === $n ) {
			return '0';
		}

		if ( $n < 10 ) {
			return '1-9';
		}

		if ( $n < 100 ) {
			return '10-99';
		}

		if ( $n < 1000 ) {
			return '100-999';
		}

		return $n < 10000 ? '1k-10k' : '10k+';
	}

	/**
	 * Bucket for days since install.
	 *
	 * @param int $days Whole days.
	 * @return string Bucket.
	 */
	public static function days( $days ) {
		$days = max( 0, (int) $days );

		if ( $days <= 1 ) {
			return '0-1';
		}

		if ( $days <= 7 ) {
			return '2-7';
		}

		if ( $days <= 30 ) {
			return '8-30';
		}

		if ( $days <= 90 ) {
			return '31-90';
		}

		return $days <= 365 ? '91-365' : '365+';
	}
}
