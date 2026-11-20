<?php
/**
 * Translations for the JavaScript apps.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds a { English: translation } dictionary from the __() calls in a JS file.
 *
 * The JS files use __( 'Text', 'hilo-chat' ) so `wp i18n make-pot` extracts them
 * into the same POT as the PHP strings; translators work in a single .po file.
 */
class HILO_I18n {

	/**
	 * Dictionary for a plugin JS file in the current locale.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return array
	 */
	public static function js_strings( $relative ) {
		$locale = determine_locale();
		if ( 0 === strpos( $locale, 'en_' ) && ! is_textdomain_loaded( 'hilo-chat' ) ) {
			return array(); // Source strings are already English.
		}

		$file = HILO_PLUGIN_PATH . $relative;
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$key    = 'hilo_i18n_' . md5( $relative . '|' . $locale . '|' . filemtime( $file ) . '|' . HILO_VERSION . '|' . self::mo_mtime( $locale ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out    = array();
		if ( preg_match_all( "/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'hilo-chat'\\s*\\)/", $source, $m ) ) {
			foreach ( array_unique( $m[1] ) as $raw ) {
				$text        = stripcslashes( $raw );
				$translation = translate( $text, 'hilo-chat' ); // phpcs:ignore WordPress.WP.I18n
				if ( $translation !== $text ) {
					$out[ $text ] = $translation;
				}
			}
		}
		set_transient( $key, $out, WEEK_IN_SECONDS );
		return $out;
	}

	/**
	 * BCP 47 tag for Intl date formatting in JS (es_ES → es-ES).
	 *
	 * @return string
	 */
	public static function js_locale() {
		return str_replace( '_', '-', determine_locale() );
	}

	/**
	 * Modification time of the active .mo file (busts the cache after a translation update).
	 *
	 * @param string $locale Locale.
	 * @return int
	 */
	private static function mo_mtime( $locale ) {
		foreach ( array( WP_LANG_DIR . '/plugins/hilo-chat-' . $locale . '.mo', HILO_PLUGIN_PATH . 'languages/hilo-chat-' . $locale . '.mo', HILO_PLUGIN_PATH . 'languages/hilo-chat-es_ES.mo' ) as $mo ) {
			if ( file_exists( $mo ) ) {
				return (int) filemtime( $mo );
			}
		}
		return 0;
	}
}
