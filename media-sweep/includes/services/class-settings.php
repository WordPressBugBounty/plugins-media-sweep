<?php
/**
 * Settings service.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Interfaces\Settings as Settings_Interface;

/**
 * Settings service.
 */
class Settings implements Settings_Interface {

	/**
	 * Option name
	 *
	 * @var string
	 */
	const OPTION_NAME = 'media_sweep_settings';

	/**
	 * Get all settings
	 *
	 * @return array
	 */
	public function get_all_settings() {
		return get_option( self::OPTION_NAME );
	}

	/**
	 * Get setting
	 *
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public function get_setting( $key, $default = null ) {
		$settings = $this->get_all_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Update setting
	 *
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	public function update_setting( $key, $value ) {
		$settings         = $this->get_all_settings();
		$settings[ $key ] = $value;
		$updated          = update_option( self::OPTION_NAME, $settings );

		if ( $updated ) {
			// Trigger action hook when settings are updated
			do_action( 'media_sweep_settings_updated', $settings );
		}

		return $updated;
	}

	/**
	 * Update all settings
	 *
	 * @param array $settings
	 *
	 * @return bool
	 */
	public function update_all_settings( $settings ) {
		$updated = update_option( self::OPTION_NAME, $settings );

		if ( $updated ) {
			// Trigger action hook when settings are updated
			do_action( 'media_sweep_settings_updated', $settings );
		}

		return $updated;
	}

	/**
	 * Delete all settings
	 *
	 * @return bool
	 */
	public function delete_all_settings() {
		return delete_option( self::OPTION_NAME );
	}
}
