<?php
/**
 * URL Normalizer - canonicalizes media references for the scan refs index.
 *
 * Both sides of a usage check (references extracted from content, and the
 * paths an attachment owns) are normalized to the same canonical form:
 * an uploads-relative path with size suffixes stripped ("the stem").
 * That way any referenced size variant, CDN/www/protocol variant, or
 * escaped (Elementor JSON) form of a URL meets the attachment's own files
 * on identical strings, and lookups become indexed hash hits.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils;

/**
 * URL Normalizer class
 */
class Url_Normalizer {

	/**
	 * Cached uploads base URL path (e.g. '/wp-content/uploads/').
	 *
	 * @var string|null
	 */
	protected static $uploads_marker = null;

	/**
	 * Regex matching plain uploads URLs/paths inside arbitrary text.
	 * Captures the part after "uploads/". Extensions are intentionally broad:
	 * anything that looks like a file with a 2-5 char extension.
	 *
	 * @var string
	 */
	const URL_PATTERN = '#uploads/((?:[^"\'\s<>\\\\)\(\?\#]|%20)+\.[a-z0-9]{2,5})#i';

	/**
	 * Regex matching JSON-escaped uploads URLs (Elementor and other builders
	 * store "https:\/\/site\/wp-content\/uploads\/2024\/06\/img.jpg" - plain
	 * LIKE/regex URL patterns can never match those).
	 *
	 * @var string
	 */
	const ESCAPED_URL_PATTERN = '#uploads\\\\/((?:[^"\'\s<>\)\(\?\#]|\\\\/)+\.[a-z0-9]{2,5})#i';

	/**
	 * Regex matching bare filenames (no path) that look like media files.
	 * Kept for parity with the 1.0.x engine, which counted a bare filename
	 * mention anywhere in content as usage of the attachment.
	 *
	 * @var string
	 */
	const FILENAME_PATTERN = '#[\w@%\+\-]+\.(?:jpe?g|png|gif|webp|avif|svg|bmp|ico|pdf|mp3|mp4|m4a|m4v|mov|wmv|avi|mpg|ogv|3gp|3g2|zip|docx?|xlsx?|pptx?|txt|csv|webm|wav|ogg)#i';

	/**
	 * Normalize any uploads URL/path fragment to its canonical stem.
	 *
	 * Input can be a full URL, an uploads-relative path, or a JSON-escaped
	 * URL. Output is the uploads-relative path with size/edit suffixes
	 * removed, e.g. '2026/07/project-photo-0001.jpg'.
	 *
	 * @param string $url Raw reference found in content.
	 * @return string|null Canonical stem, or null when not an uploads path.
	 */
	public static function normalize_to_stem( $url ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return null;
		}

		// Un-escape JSON forms first (\/ -> /), then decode entities and %XX.
		$url = str_replace( '\\/', '/', $url );
		$url = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Strip query string / fragment.
		$url = preg_split( '/[\?\#]/', $url, 2 )[0];

		// Reduce to the part after the last 'uploads/' marker. Host is
		// deliberately ignored: content references uploads through www/non-www
		// variants, CDN aliases and relative src attributes.
		$pos = strripos( $url, 'uploads/' );
		if ( $pos !== false ) {
			$url = substr( $url, $pos + strlen( 'uploads/' ) );
		}

		$url = urldecode( $url );
		$url = ltrim( $url, '/' );

		if ( $url === '' || strpos( $url, '.' ) === false ) {
			return null;
		}

		return self::strip_size_suffix( $url );
	}

	/**
	 * Strip WordPress-generated size/edit suffixes from a filename or path,
	 * collapsing every size variant to its base file:
	 *   image-300x200.jpg -> image.jpg
	 *   image-scaled.jpg  -> image.jpg
	 *   image-rotated.jpg -> image.jpg
	 *   image-e1631299123.jpg -> image.jpg (core's edit marks)
	 *
	 * @param string $path Path or filename.
	 * @return string
	 */
	public static function strip_size_suffix( $path ) {
		return preg_replace( '/-(?:\d+x\d+|scaled|rotated|e\d{8,})(?=\.[a-z0-9]{2,5}$)/i', '', $path );
	}

	/**
	 * Hash a canonical stem for indexed lookup.
	 *
	 * @param string $stem Canonical stem path.
	 * @return string sha256 hex.
	 */
	public static function hash( $stem ) {
		return hash( 'sha256', $stem );
	}

	/**
	 * Extract every uploads reference (canonical stems) and bare filename
	 * mention from a blob of text (HTML, serialized data, JSON, anything).
	 *
	 * @param string $text Raw text.
	 * @return array{stems: string[], filenames: string[]}
	 */
	public static function extract_references( $text ) {
		$stems     = array();
		$filenames = array();

		if ( ! is_string( $text ) || $text === '' ) {
			return array(
				'stems'     => $stems,
				'filenames' => $filenames,
			);
		}

		// Plain and escaped uploads paths.
		foreach ( array( self::URL_PATTERN, self::ESCAPED_URL_PATTERN ) as $pattern ) {
			if ( preg_match_all( $pattern, $text, $matches ) ) {
				foreach ( $matches[1] as $match ) {
					$stem = self::normalize_to_stem( $match );
					if ( $stem ) {
						$stems[ $stem ] = true;
					}
				}
			}
		}

		// Bare filename mentions (1.0.x parity: a filename anywhere counts).
		if ( preg_match_all( self::FILENAME_PATTERN, $text, $matches ) ) {
			foreach ( $matches[0] as $match ) {
				$name = self::strip_size_suffix( $match );
				if ( $name !== '' ) {
					$filenames[ $name ] = true;
				}
			}
		}

		return array(
			'stems'     => array_keys( $stems ),
			'filenames' => array_keys( $filenames ),
		);
	}

	/**
	 * All canonical lookup keys an attachment owns: its main file stem, its
	 * original_image stem (when core generated a -scaled version), and the
	 * bare basename of each. Size variants need no extra keys because both
	 * sides are stem-normalized.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[] Unique lookup keys (stems + basenames).
	 */
	public static function lookup_keys_for_attachment( $attachment_id ) {
		$keys = array();

		$relative = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( is_string( $relative ) && $relative !== '' ) {
			$stem          = self::strip_size_suffix( $relative );
			$keys[ $stem ] = true;
			$keys[ basename( $stem ) ] = true;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) && ! empty( $metadata['file'] ) ) {
			$dir  = dirname( $metadata['file'] );
			$orig = ( $dir && $dir !== '.' ? $dir . '/' : '' ) . $metadata['original_image'];
			$orig = self::strip_size_suffix( $orig );

			$keys[ $orig ]             = true;
			$keys[ basename( $orig ) ] = true;
		}

		return array_keys( $keys );
	}
}
