<?php
/**
 * Settings interface.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Interfaces;

interface Settings {

	/**
	 * Get all settings
	 *
	 * @return array
	 */
	public function get_all_settings();

	/**
	 * Get setting
	 *
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public function get_setting( $key, $default = null );

	/**
	 * Update setting
	 *
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	public function update_setting( $key, $value );

	/**
	 * Update all settings
	 *
	 * @param array $settings
	 *
	 * @return bool
	 */
	public function update_all_settings( $settings );

	/**
	 * Delete all settings
	 *
	 * @return bool
	 */
	public function delete_all_settings();
}
