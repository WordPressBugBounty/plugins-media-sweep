<?php
/**
 * Plugin Pattern Helper - Handles theme and plugin file pattern detection
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils;

/**
 * Plugin Pattern Helper class
 */
class Plugin_Pattern_Helper {

	/**
	 * Get popular plugin file patterns
	 *
	 * @return array Array of patterns with plugin information
	 */
	public static function get_popular_plugin_patterns() {
		return array(
			// Caching plugins
			'/wp-rocket/'               => array(
				'name' => 'WP Rocket',
				'type' => 'cache',
			),
			'/w3tc/'                    => array(
				'name' => 'W3 Total Cache',
				'type' => 'cache',
			),
			'/wp-super-cache/'          => array(
				'name' => 'WP Super Cache',
				'type' => 'cache',
			),
			'/litespeed-cache/'         => array(
				'name' => 'LiteSpeed Cache',
				'type' => 'cache',
			),
			'/wp-fastest-cache/'        => array(
				'name' => 'WP Fastest Cache',
				'type' => 'cache',
			),
			'/cache-enabler/'           => array(
				'name' => 'Cache Enabler',
				'type' => 'cache',
			),
			'/wp-optimize/'             => array(
				'name' => 'WP-Optimize',
				'type' => 'cache',
			),
			'/autoptimize/'             => array(
				'name' => 'Autoptimize',
				'type' => 'cache',
			),

			// Backup plugins
			'/updraftplus/'             => array(
				'name' => 'UpdraftPlus',
				'type' => 'backup',
			),
			'/backwpup/'                => array(
				'name' => 'BackWPup',
				'type' => 'backup',
			),
			'/wp-migrate-db/'           => array(
				'name' => 'WP Migrate DB',
				'type' => 'backup',
			),
			'/all-in-one-wp-migration/' => array(
				'name' => 'All-in-One WP Migration',
				'type' => 'backup',
			),
			'/duplicator/'              => array(
				'name' => 'Duplicator',
				'type' => 'backup',
			),
			'/backupbuddy/'             => array(
				'name' => 'BackupBuddy',
				'type' => 'backup',
			),
			'/wp-db-backup/'            => array(
				'name' => 'WP-DB-Backup',
				'type' => 'backup',
			),

			// Image optimization plugins
			'/wp-smushit/'              => array(
				'name' => 'Smush',
				'type' => 'optimization',
			),
			'/shortpixel/'              => array(
				'name' => 'ShortPixel',
				'type' => 'optimization',
			),
			'/ewww-image-optimizer/'    => array(
				'name' => 'EWWW Image Optimizer',
				'type' => 'optimization',
			),
			'/imagify/'                 => array(
				'name' => 'Imagify',
				'type' => 'optimization',
			),
			'/wp-optimize/'             => array(
				'name' => 'WP-Optimize',
				'type' => 'optimization',
			),
			'/tinypng/'                 => array(
				'name' => 'TinyPNG',
				'type' => 'optimization',
			),
			'/kraken/'                  => array(
				'name' => 'Kraken.io',
				'type' => 'optimization',
			),

			// SEO plugins
			'/wordpress-seo/'           => array(
				'name' => 'Yoast SEO',
				'type' => 'seo',
			),
			'/all-in-one-seo-pack/'     => array(
				'name' => 'All in One SEO',
				'type' => 'seo',
			),
			'/seo-by-rank-math/'        => array(
				'name' => 'Rank Math',
				'type' => 'seo',
			),

			// Page builders
			'/elementor/'               => array(
				'name' => 'Elementor',
				'type' => 'page_builder',
			),
			'/divi-builder/'            => array(
				'name' => 'Divi Builder',
				'type' => 'page_builder',
			),
			'/beaver-builder/'          => array(
				'name' => 'Beaver Builder',
				'type' => 'page_builder',
			),
			'/wpbakery/'                => array(
				'name' => 'WPBakery',
				'type' => 'page_builder',
			),
			'/gutenberg/'               => array(
				'name' => 'Gutenberg',
				'type' => 'page_builder',
			),

			// Security plugins
			'/wordfence/'               => array(
				'name' => 'Wordfence',
				'type' => 'security',
			),
			'/wp-security/'             => array(
				'name' => 'iThemes Security',
				'type' => 'security',
			),
			'/sucuri-scanner/'          => array(
				'name' => 'Sucuri',
				'type' => 'security',
			),

			// E-commerce
			'/woocommerce/'             => array(
				'name' => 'WooCommerce',
				'type' => 'ecommerce',
			),
			'/easy-digital-downloads/'  => array(
				'name' => 'Easy Digital Downloads',
				'type' => 'ecommerce',
			),

			// Forms
			'/contact-form-7/'          => array(
				'name' => 'Contact Form 7',
				'type' => 'forms',
			),
			'/wpforms/'                 => array(
				'name' => 'WPForms',
				'type' => 'forms',
			),
			'/gravityforms/'            => array(
				'name' => 'Gravity Forms',
				'type' => 'forms',
			),
			'/ninja-forms/'             => array(
				'name' => 'Ninja Forms',
				'type' => 'forms',
			),

			// Social and sharing
			'/jetpack/'                 => array(
				'name' => 'Jetpack',
				'type' => 'social',
			),
			'/social-warfare/'          => array(
				'name' => 'Social Warfare',
				'type' => 'social',
			),

			// Performance and CDN
			'/cloudflare/'              => array(
				'name' => 'Cloudflare',
				'type' => 'cdn',
			),
			'/wp-super-cache/'          => array(
				'name' => 'WP Super Cache',
				'type' => 'performance',
			),
		);
	}

	/**
	 * Get popular theme file patterns
	 *
	 * @return array Array of patterns with theme information
	 */
	public static function get_popular_theme_patterns() {
		return array(
			// Popular themes
			'/twenty-twenty/'     => array(
				'name' => 'Twenty Twenty',
				'type' => 'default_theme',
			),
			'/twentytwentyone/'   => array(
				'name' => 'Twenty Twenty-One',
				'type' => 'default_theme',
			),
			'/twentytwentytwo/'   => array(
				'name' => 'Twenty Twenty-Two',
				'type' => 'default_theme',
			),
			'/twentytwentythree/' => array(
				'name' => 'Twenty Twenty-Three',
				'type' => 'default_theme',
			),
			'/twentynineteen/'    => array(
				'name' => 'Twenty Nineteen',
				'type' => 'default_theme',
			),
			'/twentyseventeen/'   => array(
				'name' => 'Twenty Seventeen',
				'type' => 'default_theme',
			),
			'/twentysixteen/'     => array(
				'name' => 'Twenty Sixteen',
				'type' => 'default_theme',
			),
			'/twentyfifteen/'     => array(
				'name' => 'Twenty Fifteen',
				'type' => 'default_theme',
			),

			// Premium themes
			'/divi/'              => array(
				'name' => 'Divi',
				'type' => 'premium_theme',
			),
			'/avada/'             => array(
				'name' => 'Avada',
				'type' => 'premium_theme',
			),
			'/astra/'             => array(
				'name' => 'Astra',
				'type' => 'premium_theme',
			),
			'/oceanwp/'           => array(
				'name' => 'OceanWP',
				'type' => 'premium_theme',
			),
			'/generatepress/'     => array(
				'name' => 'GeneratePress',
				'type' => 'premium_theme',
			),
			'/kadence/'           => array(
				'name' => 'Kadence',
				'type' => 'premium_theme',
			),
			'/neve/'              => array(
				'name' => 'Neve',
				'type' => 'premium_theme',
			),
			'/hello-elementor/'   => array(
				'name' => 'Hello Elementor',
				'type' => 'premium_theme',
			),
			'/storefront/'        => array(
				'name' => 'Storefront',
				'type' => 'premium_theme',
			),
			'/blocksy/'           => array(
				'name' => 'Blocksy',
				'type' => 'premium_theme',
			),

			// Theme frameworks
			'/genesis/'           => array(
				'name' => 'Genesis Framework',
				'type' => 'framework',
			),
			'/thesis/'            => array(
				'name' => 'Thesis',
				'type' => 'framework',
			),
			'/themify/'           => array(
				'name' => 'Themify',
				'type' => 'framework',
			),
			'/enfold/'            => array(
				'name' => 'Enfold',
				'type' => 'framework',
			),
			'/bridge/'            => array(
				'name' => 'Bridge',
				'type' => 'framework',
			),
			'/x-theme/'           => array(
				'name' => 'X Theme',
				'type' => 'framework',
			),
			'/betheme/'           => array(
				'name' => 'BeTheme',
				'type' => 'framework',
			),

			// WooCommerce themes
			'/shop-isle/'         => array(
				'name' => 'Shop Isle',
				'type' => 'woocommerce_theme',
			),
			'/flatsome/'          => array(
				'name' => 'Flatsome',
				'type' => 'woocommerce_theme',
			),
			'/porto/'             => array(
				'name' => 'Porto',
				'type' => 'woocommerce_theme',
			),
			'/woodmart/'          => array(
				'name' => 'WoodMart',
				'type' => 'woocommerce_theme',
			),
		);
	}

	/**
	 * Check if path matches a specific pattern
	 *
	 * @param string $relative_path The relative file path
	 * @param string $filename      The filename
	 * @param string $pattern       The pattern to match
	 * @return bool True if pattern matches
	 */
	public static function path_matches_pattern( $relative_path, $filename, $pattern ) {
		// Check if pattern starts with '/' (path-based pattern)
		if ( strpos( $pattern, '/' ) === 0 ) {
			return strpos( $relative_path, $pattern ) !== false;
		}

		// Otherwise, check both filename and path for the pattern
		return strpos( $filename, $pattern ) !== false || strpos( $relative_path, $pattern ) !== false;
	}

	/**
	 * Get plugin information by matching file path
	 *
	 * @param string $file_path The file path
	 * @return array|null Plugin information or null if no match
	 */
	public static function get_plugin_info_by_path( $file_path ) {
		$upload_dir    = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'], '', $file_path );
		$filename      = basename( $file_path );

		$plugin_patterns = self::get_popular_plugin_patterns();
		foreach ( $plugin_patterns as $pattern => $plugin_info ) {
			if ( self::path_matches_pattern( $relative_path, $filename, $pattern ) ) {
				return $plugin_info;
			}
		}

		return null;
	}

	/**
	 * Get theme information by matching file path
	 *
	 * @param string $file_path The file path
	 * @return array|null Theme information or null if no match
	 */
	public static function get_theme_info_by_path( $file_path ) {
		$upload_dir    = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'], '', $file_path );
		$filename      = basename( $file_path );

		$theme_patterns = self::get_popular_theme_patterns();
		foreach ( $theme_patterns as $pattern => $theme_info ) {
			if ( self::path_matches_pattern( $relative_path, $filename, $pattern ) ) {
				return $theme_info;
			}
		}

		return null;
	}

	/**
	 * Check if file is used by theme or plugin with detailed notes
	 *
	 * @param string $file_path The file path
	 * @return array Array of notes if used by theme/plugin, empty array otherwise
	 */
	public static function check_theme_plugin_usage( $file_path ) {
		$notes         = array();
		$upload_dir    = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'], '', $file_path );
		$filename      = basename( $file_path );

		// Check for specific popular plugin patterns
		$plugin_info = self::get_plugin_info_by_path( $file_path );
		if ( $plugin_info ) {
			$notes[] = sprintf(
				/* translators: %1$s is the plugin name, %2$s is the plugin type/description */
				__( 'Used by %1$s plugin (%2$s)', 'media-sweep' ),
				$plugin_info['name'],
				$plugin_info['type']
			);
			return $notes;
		}

		// Check for specific popular theme patterns
		$theme_info = self::get_theme_info_by_path( $file_path );
		if ( $theme_info ) {
			$notes[] = sprintf(
				/* translators: %1$s is the theme name, %2$s is the theme type/description */
				__( 'Used by %1$s theme (%2$s)', 'media-sweep' ),
				$theme_info['name'],
				$theme_info['type']
			);
			return $notes;
		}

		// Get active plugins
		$active_plugins = get_option( 'active_plugins', array() );
		$plugin_slugs   = array();

		foreach ( $active_plugins as $plugin ) {
			$plugin_slug = dirname( $plugin );
			if ( $plugin_slug && $plugin_slug !== '.' ) {
				$plugin_slugs[] = $plugin_slug;
			}
		}

		// Get active theme
		$theme = get_stylesheet();

		// Check if filename contains plugin slug
		foreach ( $plugin_slugs as $slug ) {
			if ( strpos( $filename, $slug ) !== false || strpos( $relative_path, $slug ) !== false ) {
				$notes[] = sprintf(
					/* translators: %s is the plugin slug/name */
					__( 'Used by "%s" plugin', 'media-sweep' ),
					$slug
				);
				return $notes;
			}
		}

		// Check if filename contains theme slug
		if ( strpos( $filename, $theme ) !== false || strpos( $relative_path, $theme ) !== false ) {
			$notes[] = sprintf(
				/* translators: %s is the theme name */
				__( 'Used by "%s" theme', 'media-sweep' ),
				$theme
			);
			return $notes;
		}

		// Check common theme/plugin file patterns (fallback)
		$patterns = array(
			'-cache'     => __( 'Cache file', 'media-sweep' ),
			'-backup'    => __( 'Backup file', 'media-sweep' ),
			'-minified'  => __( 'Minified file', 'media-sweep' ),
			'-optimized' => __( 'Optimized file', 'media-sweep' ),
		);

		foreach ( $patterns as $pattern => $description ) {
			if ( strpos( $filename, $pattern ) !== false ) {
				$notes[] = $description;
				return $notes;
			}
		}

		return array();
	}
}
