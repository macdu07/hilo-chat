<?php
/**
 * Business hours.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates the weekly schedule in the site timezone.
 */
class HILO_Schedule {

	/**
	 * Whether "now" is within business hours.
	 *
	 * @return bool
	 */
	public static function is_open_now() {
		if ( HILO_Settings::get( 'always_open' ) ) {
			return true;
		}
		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$hours = HILO_Settings::get( 'hours', array() );
		$day   = (int) $now->format( 'w' );
		if ( empty( $hours[ $day ]['enabled'] ) ) {
			return false;
		}
		if ( ! empty( $hours[ $day ]['allday'] ) ) {
			return true;
		}
		$current = $now->format( 'H:i' );
		$start   = isset( $hours[ $day ]['start'] ) ? $hours[ $day ]['start'] : '00:00';
		$end     = isset( $hours[ $day ]['end'] ) ? $hours[ $day ]['end'] : '23:59';
		return $current >= $start && $current < $end;
	}

	/**
	 * Sentence announcing the next opening, e.g. "We will be back tomorrow at 09:00".
	 *
	 * @return string
	 */
	public static function next_opening_text() {
		if ( HILO_Settings::get( 'always_open' ) ) {
			return '';
		}
		$hours = HILO_Settings::get( 'hours', array() );
		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		global $wp_locale;

		for ( $i = 0; $i < 8; $i++ ) {
			$date = $now->modify( "+{$i} day" );
			$day  = (int) $date->format( 'w' );
			if ( empty( $hours[ $day ]['enabled'] ) ) {
				continue;
			}
			$start = ! empty( $hours[ $day ]['allday'] ) ? '00:00' : $hours[ $day ]['start'];
			if ( 0 === $i && $now->format( 'H:i' ) >= $start ) {
				continue;
			}
			$weekday = $wp_locale ? $wp_locale->get_weekday( $day ) : $date->format( 'l' );
			if ( ! empty( $hours[ $day ]['allday'] ) ) {
				/* translators: %s: weekday name. */
				return 1 === $i ? __( 'We will be back tomorrow.', 'hilo-chat' ) : sprintf( __( 'We will be back on %s.', 'hilo-chat' ), $weekday );
			}
			if ( 0 === $i ) {
				/* translators: %s: time, e.g. 09:00. */
				return sprintf( __( 'We will be back today at %s.', 'hilo-chat' ), $start );
			}
			if ( 1 === $i ) {
				/* translators: %s: time, e.g. 09:00. */
				return sprintf( __( 'We will be back tomorrow at %s.', 'hilo-chat' ), $start );
			}
			/* translators: 1: weekday name, 2: time, e.g. 09:00. */
			return sprintf( __( 'We will be back on %1$s at %2$s.', 'hilo-chat' ), $weekday, $start );
		}
		return '';
	}
}
