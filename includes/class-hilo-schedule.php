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
		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$hours = HILO_Settings::get( 'hours', array() );
		$day   = (int) $now->format( 'w' );
		if ( empty( $hours[ $day ]['enabled'] ) ) {
			return false;
		}
		$current = $now->format( 'H:i' );
		$start   = isset( $hours[ $day ]['start'] ) ? $hours[ $day ]['start'] : '00:00';
		$end     = isset( $hours[ $day ]['end'] ) ? $hours[ $day ]['end'] : '23:59';
		return $current >= $start && $current < $end;
	}

	/**
	 * Human text for the next opening, e.g. "mañana a las 09:00".
	 *
	 * @return string
	 */
	public static function next_opening_text() {
		$hours = HILO_Settings::get( 'hours', array() );
		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$names = array( 'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado' );

		for ( $i = 0; $i < 8; $i++ ) {
			$date = $now->modify( "+{$i} day" );
			$day  = (int) $date->format( 'w' );
			if ( empty( $hours[ $day ]['enabled'] ) ) {
				continue;
			}
			$start = $hours[ $day ]['start'];
			if ( 0 === $i && $now->format( 'H:i' ) >= $start ) {
				continue;
			}
			if ( 0 === $i ) {
				return 'hoy a las ' . $start;
			}
			if ( 1 === $i ) {
				return 'mañana a las ' . $start;
			}
			return 'el ' . $names[ $day ] . ' a las ' . $start;
		}
		return '';
	}
}
