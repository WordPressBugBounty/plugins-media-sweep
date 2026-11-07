<?php
/**
 * Consent and UI texts from data/consent-texts-v2.json; older files stay for the texts people agreed to before.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Consent;

use WPCreatix\BridgeClient\Config;

/**
 * Versioned consent texts with their sha256; a text whose hash does not match is never used.
 */
final class Texts {

	/**
	 * Bundled files, newest first, with the usage scopes their usage text covers (same lists as the server's DefaultTexts).
	 *
	 * @var array<string,array<int,string>>
	 */
	const FILES = array(
		'consent-texts-v2.json' => array( 'env', 'our_plugins', 'flags', 'counts', 'environment', 'extensions' ),
		'consent-texts-v1.json' => array( 'env', 'our_plugins', 'flags', 'counts', 'environment' ),
	);

	/**
	 * Usage scope of a text that also shares the clicking admin's name and email.
	 */
	const CONTACT = 'contact';

	/**
	 * Parsed files by name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $data = array();

	/**
	 * Path of a texts file in this copy (default: the current one).
	 *
	 * @param string|null $name File name.
	 * @return string Absolute path.
	 */
	public static function file( $name = null ) {
		$names = array_keys( self::FILES );

		return dirname( __DIR__, 2 ) . '/data/' . ( null === $name ? $names[0] : (string) $name );
	}

	/**
	 * The whole parsed current file (UI strings come from here).
	 *
	 * @param string|null $name File name (default: the current one).
	 * @return array<string,mixed> Texts.
	 */
	public static function all( $name = null ) {
		$path = self::file( $name );

		if ( ! isset( self::$data[ $path ] ) ) {
			$raw                 = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file.
			$data                = json_decode( $raw, true );
			self::$data[ $path ] = is_array( $data ) ? $data : array();
		}

		return self::$data[ $path ];
	}

	/**
	 * Current consent text for a purpose, only when its hash matches its text.
	 *
	 * @param string $purpose usage or marketing.
	 * @return array<string,string>|null Consent row.
	 */
	public static function consent( $purpose ) {
		foreach ( self::rows( self::all() ) as $row ) {
			if ( $purpose === $row['purpose'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Any bundled consent text by version (current or older), only when its hash matches.
	 *
	 * @param string $version Text version.
	 * @return array<string,string>|null Consent row.
	 */
	public static function by_version( $version ) {
		foreach ( array_keys( self::FILES ) as $name ) {
			foreach ( self::rows( self::all( $name ) ) as $row ) {
				if ( (string) $version === (string) $row['version'] ) {
					return $row;
				}
			}
		}

		return null;
	}

	/**
	 * Usage scopes a text version covers (empty for marketing and unknown versions); a row's own covers win over its file's.
	 *
	 * @param string $version Text version.
	 * @return array<int,string> Scopes.
	 */
	public static function covers( $version ) {
		foreach ( self::FILES as $name => $covers ) {
			foreach ( self::rows( self::all( $name ) ) as $row ) {
				if ( 'usage' === $row['purpose'] && (string) $version === (string) $row['version'] ) {
					return isset( $row['covers'] ) && is_array( $row['covers'] ) ? array_values( array_filter( $row['covers'], 'is_string' ) ) : $covers;
				}
			}
		}

		return array();
	}

	/**
	 * Whether Allow on a usage text also shares the admin's name and email (its covers include contact).
	 *
	 * @param string $version Text version.
	 * @return bool True for a text with the contact line.
	 */
	public static function shows_contact( $version ) {
		return in_array( self::CONTACT, self::covers( (string) $version ), true );
	}

	/**
	 * Newest usage text without the contact line (the settings toggle shows no contact line, so it grants this one).
	 *
	 * @return array{text_version:string,text_sha256:string}|null Pair, or null when none is bundled.
	 */
	public static function plain_usage_proof() {
		foreach ( self::rows( self::all() ) as $row ) {
			if ( 'usage' === $row['purpose'] && ! self::shows_contact( (string) $row['version'] ) ) {
				return array(
					'text_version' => (string) $row['version'],
					'text_sha256'  => (string) $row['text_sha256'],
				);
			}
		}

		return null;
	}

	/**
	 * Version and hash pair for a purpose, as sent on the wire.
	 *
	 * @param string      $purpose usage or marketing.
	 * @param string|null $version A bundled version to use instead of the current one (falls back when unknown).
	 * @return array{text_version:string,text_sha256:string}|null Pair, or null when the text is broken.
	 */
	public static function proof( $purpose, $version = null ) {
		$row = null === $version || '' === (string) $version ? null : self::by_version( (string) $version );
		$row = null !== $row && $purpose === $row['purpose'] ? $row : self::consent( $purpose );

		return null === $row ? null : array(
			'text_version' => (string) $row['version'],
			'text_sha256'  => (string) $row['text_sha256'],
		);
	}

	/**
	 * Valid consent rows of a parsed file.
	 *
	 * @param array<string,mixed> $file Parsed file.
	 * @return array<int,array<string,string>> Rows whose hash matches their text.
	 */
	private static function rows( array $file ) {
		$rows = array();

		foreach ( isset( $file['consents'] ) && is_array( $file['consents'] ) ? $file['consents'] : array() as $row ) {
			if ( is_array( $row ) && isset( $row['purpose'], $row['text'], $row['text_sha256'], $row['version'] ) && hash_equals( (string) $row['text_sha256'], hash( 'sha256', (string) $row['text'] ) ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * A UI string by dotted path, with {placeholders} filled.
	 *
	 * @param string               $path Dotted path, e.g. card.title.
	 * @param array<string,string> $vars Placeholder values.
	 * @return string Text, or the path when missing.
	 */
	public static function get( $path, array $vars = array() ) {
		$node = self::all();

		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return $path;
			}

			$node = $node[ $part ];
		}

		return is_string( $node ) ? self::fill( $node, $vars ) : '';
	}

	/**
	 * A raw node (array or string) by dotted path.
	 *
	 * @param string $path Dotted path.
	 * @return mixed Node or null.
	 */
	public static function node( $path ) {
		$node = self::all();

		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return null;
			}

			$node = $node[ $part ];
		}

		return $node;
	}

	/**
	 * Fill {name} placeholders; the fixed contact values are always available.
	 *
	 * @param string               $text Text.
	 * @param array<string,string> $vars Placeholder values.
	 * @return string Filled text.
	 */
	public static function fill( $text, array $vars = array() ) {
		$vars = array_merge(
			array(
				'privacy_url'   => Config::PRIVACY_URL,
				'privacy_email' => Config::PRIVACY_EMAIL,
			),
			$vars
		);

		foreach ( $vars as $name => $value ) {
			$text = str_replace( '{' . $name . '}', (string) $value, $text );
		}

		return $text;
	}

	/**
	 * Lines of a current consent text (empty when the text is broken).
	 *
	 * @param string $purpose usage or marketing.
	 * @return array<int,string> Lines.
	 */
	public static function lines( $purpose ) {
		$row = self::consent( $purpose );

		return null === $row ? array() : explode( "\n", (string) $row['text'] );
	}

	/**
	 * The visible usage sentence (line 1 of the hashed usage text).
	 *
	 * @return string Sentence.
	 */
	public static function usage_sentence() {
		$lines = self::lines( 'usage' );

		return $lines ? $lines[0] : '';
	}

	/**
	 * The contact line (line 2 of the current usage text when it covers contact), shown small under the sentence.
	 *
	 * @return string Line, or '' when the current text has none.
	 */
	public static function contact_line() {
		$row   = self::consent( 'usage' );
		$lines = self::lines( 'usage' );

		if ( null === $row || ! self::shows_contact( (string) $row['version'] ) || ! isset( $lines[1] ) || 0 === strpos( $lines[1], '- ' ) ) {
			return '';
		}

		return $lines[1];
	}

	/**
	 * The "What we collect" bullets (the "- " lines of the hashed usage text, prefix removed).
	 *
	 * @return array<int,string> Bullets.
	 */
	public static function usage_bullets() {
		$out = array();

		foreach ( array_slice( self::lines( 'usage' ), 1 ) as $line ) {
			if ( 0 === strpos( $line, '- ' ) ) {
				$out[] = substr( $line, 2 );
			}
		}

		return $out;
	}

	/**
	 * The newsletter description: marketing lines 1 and 2 joined by one space.
	 *
	 * @return string Description.
	 */
	public static function marketing_description() {
		return implode( ' ', array_slice( self::lines( 'marketing' ), 0, 2 ) );
	}

	/**
	 * The small print text: marketing line 3 (the Privacy policy link follows it).
	 *
	 * @return string Small print.
	 */
	public static function small_print() {
		$lines = self::lines( 'marketing' );

		return isset( $lines[2] ) ? $lines[2] : self::get( 'small_print.text' );
	}
}
