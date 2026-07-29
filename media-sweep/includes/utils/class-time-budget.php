<?php
/**
 * Time Budget - per-request wall-clock budget for scan work.
 *
 * Real-world gateway timeouts (mod_fcgid 40s, nginx/Apache/WP Engine 60s,
 * Cloudflare ~100s) kill silent FastCGI requests regardless of PHP's
 * max_execution_time - which itself can lie (WP Engine reports 300 while the
 * platform kills at 60) or be 0/unlimited while a 40s proxy sits in front.
 * Every scan request therefore self-terminates well below the lowest common
 * ceiling and returns partial progress, so no gateway ever produces the HTML
 * error page behind "The response is not a valid JSON response.".
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils;

/**
 * Time Budget class
 */
class Time_Budget {

	/**
	 * Hard default budget in seconds. Chosen to leave margin inside the 40s
	 * mod_fcgid floor after WP bootstrap (1-3s on shared hosting) and
	 * response serialization.
	 */
	const DEFAULT_BUDGET = 15;

	/**
	 * Memory ceiling as a fraction of memory_limit.
	 */
	const MEMORY_CEILING = 0.85;

	/**
	 * Request start (this object's construction).
	 *
	 * @var float
	 */
	protected $start;

	/**
	 * Budget in seconds.
	 *
	 * @var float
	 */
	protected $budget;

	/**
	 * Rolling average seconds per processed item.
	 *
	 * @var float
	 */
	protected $avg_item = 0.0;

	/**
	 * Number of items folded into the rolling average.
	 *
	 * @var int
	 */
	protected $items = 0;

	/**
	 * Memory limit in bytes.
	 *
	 * @var int
	 */
	protected $memory_limit;

	/**
	 * Constructor.
	 *
	 * @param float|null $seconds Optional explicit budget (used by WP-CLI to
	 *                            run longer slices). Defaults to a value that
	 *                            is safe on any host.
	 */
	public function __construct( $seconds = null ) {
		$this->start = microtime( true );

		if ( null === $seconds ) {
			$ini = (int) ini_get( 'max_execution_time' );
			// ini=0 (unlimited) or a large value must NOT raise the budget: an
			// unlimited PHP worker can still sit behind a 40s proxy. The ini
			// value can only LOWER the budget (very constrained hosts).
			$seconds = self::DEFAULT_BUDGET;
			if ( $ini > 0 && ( $ini * 0.8 ) < $seconds ) {
				$seconds = max( 5, $ini * 0.8 );
			}
		}

		/**
		 * Filter the per-request scan time budget in seconds.
		 *
		 * @param float $seconds Budget in seconds.
		 */
		$this->budget = (float) apply_filters( 'media_sweep_time_budget', $seconds );

		$this->memory_limit = $this->parse_memory_limit();
	}

	/**
	 * Seconds elapsed since construction.
	 *
	 * @return float
	 */
	public function elapsed() {
		return microtime( true ) - $this->start;
	}

	/**
	 * Seconds remaining in the budget (never negative).
	 *
	 * @return float
	 */
	public function remaining() {
		return max( 0.0, $this->budget - $this->elapsed() );
	}

	/**
	 * Whether the loop should stop BEFORE processing the next item.
	 *
	 * Predictive: stops when the remaining budget would not fit the next item
	 * (twice the observed average, floor 0.25s), so work never dies mid-item.
	 *
	 * @return bool
	 */
	public function should_stop() {
		if ( $this->remaining() <= max( 0.25, $this->avg_item * 2 ) ) {
			return true;
		}

		if ( $this->memory_limit > 0 && memory_get_usage( true ) >= $this->memory_limit * self::MEMORY_CEILING ) {
			return true;
		}

		return false;
	}

	/**
	 * Record one processed item's duration into the rolling average.
	 *
	 * @param float $seconds Item duration in seconds.
	 */
	public function item_done( $seconds ) {
		++$this->items;
		// Simple cumulative moving average - stable and cheap.
		$this->avg_item += ( $seconds - $this->avg_item ) / $this->items;
	}

	/**
	 * Convenience wrapper: time a callable as one item.
	 *
	 * @param callable $fn Work for a single item.
	 * @return mixed The callable's return value.
	 */
	public function run_item( $fn ) {
		$t0     = microtime( true );
		$result = $fn();
		$this->item_done( microtime( true ) - $t0 );
		return $result;
	}

	/**
	 * Average seconds per item observed so far.
	 *
	 * @return float
	 */
	public function avg_item() {
		return $this->avg_item;
	}

	/**
	 * Parse memory_limit into bytes.
	 *
	 * @return int Bytes, 0 when unlimited/unknown.
	 */
	protected function parse_memory_limit() {
		$value = ini_get( 'memory_limit' );
		if ( $value === false || $value === '' || $value === '-1' ) {
			return 0;
		}

		$unit  = strtolower( substr( trim( $value ), -1 ) );
		$bytes = (int) $value;

		switch ( $unit ) {
			case 'g':
				$bytes *= 1024;
				// Fall through.
			case 'm':
				$bytes *= 1024;
				// Fall through.
			case 'k':
				$bytes *= 1024;
		}

		return $bytes;
	}
}
