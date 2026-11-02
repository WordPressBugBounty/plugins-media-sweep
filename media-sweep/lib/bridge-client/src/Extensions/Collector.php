<?php
/**
 * Installed plugins and themes as list items (11 section 3.1); no mu-plugins, drop-ins, paths or update info.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Extensions;

use WPCreatix\BridgeClient\Config;

/**
 * Reads get_plugins() and wp_get_themes() and cleans every field.
 */
final class Collector {

	/**
	 * Allowed slug characters.
	 */
	const SLUG = '/^[A-Za-z0-9._\-\/]{1,190}$/';

	/**
	 * Every item, sorted by key and cut at the item cap.
	 *
	 * @return array{items:array<int,array<string,mixed>>,truncated:bool} List.
	 */
	public static function collect() {
		return self::finish( array_merge( self::plugins(), self::themes() ) );
	}

	/**
	 * Sort raw items by key and apply the cap.
	 *
	 * @param array<int,array<string,mixed>> $items Clean items.
	 * @return array{items:array<int,array<string,mixed>>,truncated:bool} List.
	 */
	public static function finish( array $items ) {
		$keyed = array();

		foreach ( $items as $item ) {
			$keyed[ Differ::key( $item ) ] = $item;
		}

		ksort( $keyed, SORT_STRING );

		return array(
			'items'     => array_slice( array_values( $keyed ), 0, Config::EXT_MAX_ITEMS ),
			'truncated' => count( $keyed ) > Config::EXT_MAX_ITEMS,
		);
	}

	/**
	 * Plugin items (active here, network-active, or active on the main site).
	 *
	 * @return array<int,array<string,mixed>> Items.
	 */
	public static function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active  = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();

		if ( is_multisite() && ! is_main_site() ) {
			$active = (array) get_blog_option( (int) get_main_site_id(), 'active_plugins', array() );
		}

		$items = array();

		foreach ( (array) get_plugins() as $basename => $data ) {
			$item = self::item( 'plugin', (string) $basename, (array) $data, in_array( $basename, $active, true ) || in_array( $basename, $network, true ), in_array( $basename, $network, true ) );

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Theme items; the active theme and its parent are active.
	 *
	 * @return array<int,array<string,mixed>> Items.
	 */
	public static function themes() {
		$stylesheet = (string) get_stylesheet();
		$template   = (string) get_template();
		$items      = array();

		foreach ( wp_get_themes() as $slug => $theme ) {
			$parent = $theme->get_template() !== $theme->get_stylesheet() ? (string) $theme->get_template() : null;
			$item   = self::item(
				'theme',
				(string) $slug,
				array(
					'Name'    => (string) $theme->get( 'Name' ),
					'Version' => (string) $theme->get( 'Version' ),
					'Author'  => (string) $theme->get( 'Author' ),
				),
				$stylesheet === $slug || $template === $slug,
				false,
				$parent
			);

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * One clean item in wire order; null when the slug is unusable.
	 *
	 * @param string              $type    plugin or theme.
	 * @param string              $slug    Basename or stylesheet.
	 * @param array<string,mixed> $headers Name, Version, Author headers.
	 * @param bool                $active  Active flag.
	 * @param bool                $network Network-active flag.
	 * @param string|null         $template Parent theme (themes only).
	 * @return array<string,mixed>|null Item.
	 */
	public static function item( $type, $slug, array $headers, $active, $network, $template = null ) {
		if ( 1 !== preg_match( self::SLUG, $slug ) || false !== strpos( $slug, '..' ) ) {
			return null;
		}

		$item = array(
			'type'    => $type,
			'slug'    => $slug,
			'name'    => self::text( isset( $headers['Name'] ) ? $headers['Name'] : '' ),
			'version' => self::version( isset( $headers['Version'] ) ? $headers['Version'] : '' ),
			'active'  => (bool) $active,
			'network' => (bool) $network,
			'author'  => self::text( isset( $headers['Author'] ) ? $headers['Author'] : '' ),
		);

		if ( 'theme' === $type ) {
			$item['parent'] = null !== $template && 1 === preg_match( self::SLUG, (string) $template ) && false === strpos( (string) $template, '..' ) ? (string) $template : null;
		}

		return $item;
	}

	/**
	 * Name or author: no tags, emails or URLs, whitespace collapsed, max 120 chars.
	 *
	 * @param mixed $raw Raw header.
	 * @return string Clean text.
	 */
	public static function text( $raw ) {
		$text = wp_strip_all_tags( (string) $raw );
		$text = (string) preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '', $text );
		$text = (string) preg_replace( '#\b(?:https?://|www\.)\S+#i', '', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return function_exists( 'mb_substr' ) ? (string) mb_substr( $text, 0, 120, 'UTF-8' ) : substr( $text, 0, 120 );
	}

	/**
	 * Version with only the allowed characters, max 40.
	 *
	 * @param mixed $raw Raw version.
	 * @return string Clean version.
	 */
	public static function version( $raw ) {
		return substr( (string) preg_replace( '/[^A-Za-z0-9._+\-]/', '', (string) $raw ), 0, 40 );
	}
}
