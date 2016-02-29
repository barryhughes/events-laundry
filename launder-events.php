<?php
/**
 * Plugin name: Launder Events (for The Events Calendar)
 * Description: Experimental utility to recycle events by automatically updating the start date at set intervals. Requires at least TEC 4.1.
 * Version:     2016.02.29
 * Author:      Barry Hughes
 * Author URI:  http://codingkills.me
 * License:     GPLv3 <https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 *     Launder Events (for The Events Calendar): a utility to reuse event data.
 *     Copyright (C) 2015 Barry Hughes
 *
 *     This program is free software: you can redistribute it and/or modify
 *     it under the terms of the GNU General Public License as published by
 *     the Free Software Foundation, either version 3 of the License, or
 *     (at your option) any later version.
 *
 *     This program is distributed in the hope that it will be useful,
 *     but WITHOUT ANY WARRANTY; without even the implied warranty of
 *     MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *     GNU General Public License for more details.
 *
 *     You should have received a copy of the GNU General Public License
 *     along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace CodingKillsMe\TEC;

use Tribe__Date_Utils as Date_Utils,
    Tribe__Events__Dates__Known_Range as Known_Range,
    Tribe__Events__Main as TEC,
    Tribe__Timezones as Timezones,
    WP_Post;


class EventsLaunderer {
	const LAUNDRY_ENABLED  = 'tec-laundry-enabled';
	const LAUNDRY_INTERVAL = 'tec-laundry-interval';
	const LAUNDRY_PROCESS  = 'tec-laundry-process';


	public function __construct() {
		add_action( 'init', [ $this, 'launder_setup_task' ] );
		add_action( self::LAUNDRY_PROCESS, [ $this, 'launder_events' ] );
		add_action( 'add_meta_boxes', [ $this, 'meta_box_register' ] );
		add_action( 'save_post', [ $this, 'meta_box_save' ] );
	}

	public function meta_box_register() {
		add_meta_box(
			'tec-launder-event',
			__( 'Laundry Settings', 'launder-events' ),
			[ $this, 'meta_box_display' ],
			TEC::POSTTYPE,
			'side'
		);
	}

	public function meta_box_display() {
		$enabled = checked( true, get_post_meta( get_the_ID(), self::LAUNDRY_ENABLED, true ), false );
		$current = get_post_meta( get_the_ID(), self::LAUNDRY_INTERVAL, true );

		echo '<p> <input type="checkbox" name="tec-laundry-enable" value="1" ' . $enabled . '>'
		   . esc_html__( 'Enable laundering of this event.', 'launder-events' ) . '</p>'
		   . '<p>' . esc_html__( 'Once this event expires, reset the start date to:', 'launder-events' ) . '</p>'
		   . '<p>' . $this->interval_options_selector( $current ) . '</p>';

		wp_nonce_field( 'update-event-laundry-settings', 'event-laundromat-check' );
	}

	public function meta_box_save() {
		if ( ! wp_verify_nonce( @$_POST['event-laundromat-check'], 'update-event-laundry-settings' ) )
			return;

		update_post_meta( get_the_ID(), self::LAUNDRY_ENABLED, ( '1' === @$_POST['tec-laundry-enable'] ) );

		if ( $_POST['tec-laundry-interval'] ) {
			update_post_meta( get_the_ID(), self::LAUNDRY_INTERVAL, filter_var(
				$_POST['tec-laundry-interval'], FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH
			) );
		}
	}

	public function interval_options() {
		/**
		 * The possible laundry options for expired events.
		 *
		 * @param array $interval_options
		 */
		return (array) apply_filters( 'event_laundry_interval_options', [
			'today'           => esc_html__( 'The current day', 'launder-events' ),
			'next_day'        => esc_html__( 'The following day', 'launder-events' ),
			'same_next_week'  => esc_html__( 'Same day next week', 'launder-events' ),
			'random_next_week' => esc_html__( 'Random within the next week', 'launder-events' ),
			'same_next_month' => esc_html__( 'Same day next month', 'launder-events' ),
			'random_next_month' => esc_html__( 'Random within the next month', 'launder-events' ),
			'same_next_year'  => esc_html__( 'Same date next year', 'launder-events' )
		] );
	}

	public function interval_options_selector( $current = false ) {
		$output = '<select name="tec-laundry-interval">';

		foreach ( $this->interval_options() as $key => $text ) {
			$selected = selected( $key, $current, false );
			$output .= '<option value="' . esc_attr( $key ) . '" ' . $selected . '>' . esc_html( $text ) . '</option>';
		}

		$output .= '</select>';

		return apply_filters( 'event_laundry_interval_options_selector', $output, $current );
	}

	public function launder_setup_task() {
		if ( ! wp_next_scheduled( self::LAUNDRY_PROCESS ) )
			wp_schedule_event( strtotime( '+6 hours' ), 'daily', self::LAUNDRY_PROCESS );
	}

	public function launder_events() {
		$this->launder_setup_callbacks();

		foreach ( $this->launder_find_expired() as $expired_event ) {
			$interval = get_post_meta( $expired_event->ID, self::LAUNDRY_INTERVAL, true );

			/**
			 * Triggers the laundry callback for the specified event.
			 *
			 * @param WP_Post $expired_event
			 */
			do_action( "event_laundry_do_$interval", $expired_event );
		}
	}

	public function launder_find_expired() {
		return get_posts( [
			'post_type'    => TEC::POSTTYPE,
			'eventDisplay' => 'custom',
			'meta_query'   => [
				[
					'key'   => self::LAUNDRY_ENABLED,
					'value' => true
				],
				[
					'key'     => '_EventEndDateUTC',
					'value'   => current_time( 'mysql' ),
					'compare' => '<',
					'type'    => 'datetime'
				]
			]
		] );
	}

	/**
	 * Setup of laundry interval callbacks is deferred, keeping things
	 * nice and lazy.
	 */
	protected function launder_setup_callbacks() {
		add_action( 'event_laundry_do_today',             [ $this, 'launder_for_today' ] );
		add_action( 'event_laundry_do_next_day',          [ $this, 'launder_for_next_day' ] );
		add_action( 'event_laundry_do_same_next_week',    [ $this, 'launder_for_same_next_week' ] );
		add_action( 'event_laundry_do_random_next_week',  [ $this, 'launder_for_random_next_week' ] );
		add_action( 'event_laundry_do_same_next_month',   [ $this, 'launder_for_same_next_month' ] );
		add_action( 'event_laundry_do_random_next_month', [ $this, 'launder_for_random_next_month' ] );
		add_action( 'event_laundry_do_same_next_year',    [ $this, 'launder_for_same_next_year' ] );
	}

	public function launder_for_today( WP_Post $event ) {
		$this->launder_event( $event, '+0 hours' );
	}

	public function launder_for_next_day( WP_Post $event ) {
		$this->launder_event( $event, '+1 day' );
	}

	public function launder_for_same_next_week( WP_Post $event ) {
		$this->launder_event( $event, '+1 week' );
	}

	public function launder_for_random_next_week( WP_Post $event ) {
		$num_days  = rand( 7, 13 );
		$num_hours = rand( 0, 24 );
		$this->launder_event( $event, "+$num_days days $num_hours hours" );
	}

	public function launder_for_same_next_month( WP_Post $event ) {
		$this->launder_event( $event, '+1 month' );
	}

	public function launder_for_random_next_month( WP_Post $event ) {
		$num_days  = rand( 30, 60 );
		$num_hours = rand( 0, 24 );
		$this->launder_event( $event, "+$num_days days $num_hours hours" );
	}

	public function launder_for_same_next_year( WP_Post $event ) {
		$this->launder_event( $event, '+1 year' );
	}

	/**
	 * Sets the provided event's start and end datetimes to today plus/minus
	 * the modifier (expected to be in a strtotime() compatible format, ie
	 * "+1 week").
	 *
	 * @param WP_Post $event
	 * @param string  $modifier
	 */
	public function launder_event( WP_Post $event, $modifier ) {
		$today_date  = date( 'd' );
		$today_month = date( 'm' );
		$today_year  = date( 'Y' );

		try {
			// Get the start/end datetimes in UTC
			$start = date_create( $event->{'_EventStartDateUTC'} );
			$end   = date_create( $event->{'_EventEndDateUTC'} );
			$zone  = $event->{'_EventTimezone'};

			// Update to today, leaving the times unchanged
			$start->setDate( $today_year, $today_month, $today_date );
			$end->setDate( $today_year, $today_month, $today_date );

			// Apply the modifier and convert to a datetime string
			$start_utc = $start->modify( $modifier )->format( Date_Utils::DBDATETIMEFORMAT );
			$end_utc   = $end->modify( $modifier )->format( Date_Utils::DBDATETIMEFORMAT );

			// Convert to the appropriate timezone for the event
			$start_local = Timezones::to_tz( $start_utc, $zone );
			$end_local   = Timezones::to_tz( $end_utc, $zone );

		}
		catch ( Exception $e ) {
			return;
		}

		// Update the event start/end times ... we avoid tribe_update_event() because we'd need
		// to do extra work to accommodate its quirks (we can't just pass the new dates in our
		// array, we'd need to break them up into dates, hours, minutes etc)
		update_post_meta( $event->ID, '_EventStartDate', $start_local );
		update_post_meta( $event->ID, '_EventEndDate', $end_local );
		update_post_meta( $event->ID, '_EventStartDateUTC', $start_utc );
		update_post_meta( $event->ID, '_EventEndDateUTC', $end_utc );

		// Tidy up loose ends
		Known_Range::instance()->update_known_range( $event->ID );
	}
}

/**
 * @return EventsLaunderer
 */
function events_launderer() {
	static $object;
	if ( empty( $object ) ) $object = new EventsLaunderer;
	return $object;
}

add_action( 'init', function() {
	if ( class_exists( 'Tribe__Events__Main' ) )
		events_launderer();
} );