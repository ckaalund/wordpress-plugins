<?php
/**
 * @package ical-functions
 */

/**
 * Returns an array of feeds (post objects).
 * 
 * Wrapper for {@see `get_posts()`}. Returns array of feeds matching query.
 *
 * @since 1.0
 * 
 * @link http://codex.wordpress.org/Template_Tags/get_posts
 * 
 * @param array $args Query array to query feeds by. See {@link http://codex.wordpress.org/Template_Tags/get_posts}.
 * @return array Array of matching feeds.
 */
function eo_get_feeds( $args = array() ){

	$args = array_merge( $args, array(
			'post_type' => 'eo_icalfeed',
			'post_status' => 'any',
			'numberposts' => -1,
			'no_found_rows' => true,
			'update_post_term_cache' => false,
			'update_meta_term_cache' => false,
	));

	return get_posts( $args );
}


/**
 * Creates a new feed.
 *
 * @since 1.0
 * @param string $name The name of the feed.
 * @param string $source The url of the feed's soruce.
 * @return int $feed_id The feed's (post) ID.
 */
function eo_insert_feed( $name, $source ){

	$feed_id = wp_insert_post( array(
			'post_type' => 'eo_icalfeed',
			'post_content' => $source,
			'status' => 'active',
			'post_title' => $name,
	));

	if( $feed_id ){
		update_post_meta( $feed_id, '_eventorganiser_feed_source', $source );
	}
	return $feed_id;
}

/**
 * Updates an existing feed
 * 
 * `$args` is an array which can contain:
 *  * name - name of the source
 *  * source - the source url
 *	* organiser - user ID
 *  * status - default status for imported events
 *  * category - default event category (term ID) for imported events.
 *
 * Because events which are added by a feed and then no longer present in that
 * feed are deleted. Its recommended not to update the source, unless you do not
 * mind existing events in that feed being deleted.
 * 
 * Only keys present in `$args` are updated. 
 *
 * @since 1.0
 * 
 * @param int $feed_id The feed's (post) ID.
 * @param array $args Feed values to update.
 * @return int $feed_id The feed's (post) ID.
 */
function eo_update_feed( $feed_id, $args ){

	$update = array(
			'ID' => $feed_id,
			'edit_date' => 1, //Prevents updating of date
	);

	if( isset( $args['name'] ) ){
		$update['post_title'] = $args['name'];
	};

	if( isset( $args['source'] ) ){
		update_post_meta( $feed_id, '_eventorganiser_feed_source', $args['source'] );
		$update['post_content'] = $args['source'];
	};

	if( isset( $args['organiser'] ) ){
		update_post_meta( $feed_id, '_eventorganiser_feed_organiser', $args['organiser'] );
	}

	if( isset( $args['status'] ) ){
		update_post_meta( $feed_id, '_eventorganiser_feed_status', $args['status'] );
	}

	if( isset( $args['category'] ) ){
		update_post_meta( $feed_id, '_eventorganiser_feed_category', $args['category'] );
	}

	return wp_update_post( $update );
}

/**
 * Deletes a feed (permanantly). 
 * 
 * @since 1.0
 * 
 * @param int $feed_id The feed's (post) ID.
 */
function eo_delete_feed( $feed_id ){
	wp_delete_post( $feed_id, true );
}

/**
 * Fetches all registered feeds
 * @since 1.0
 * @uses `eo_fetch_feed()`
 */
function eo_fetch_feeds(){
	$feeds = eo_get_feeds( array( 'fields' => 'ids' ) );

	if( $feeds ){
		foreach( $feeds as $feed_id ){
			eo_fetch_feed( $feed_id );
		}
	}
}
add_action( 'eventorganiser_ical_feed_sync', 'eo_fetch_feeds' );


/**
 * Gets feed sync shedules.
 *
 * Returns an array of schedules, indexed by an identifier (e.g. 'hourly', 'daily' 
 * or 0 for off) with values human-readable labels.
 * 
 * These schedules are just those registered cron schedules sorted by interval, with 'Sync off' prepended.
 * 
 * @ignore
 * 
 * @since 1.0
 * 
 * @return array Available sync schedules of the form `array( identifier => label )`.
*/
function eo_get_feed_sync_schedules(){
	$options = wp_get_schedules();
	uasort( $options, '_eo_sort_cron_by_interval' );
	$options = wp_list_pluck( $options, 'display' );
	$options = array( 0 => __( 'Sync off', 'eventorganiserical' ) ) + $options;
	return $options;
}


/**
 * Helper function (used with `usort()`) to sort arrays by 'interval' key
 *
 * @ignore
 * @package ical-functions
 * @param array $a First array to compare
 * @param array $b Second array to compare
 * @return The difference of the value of the interval key for $a and $b
 */
function _eo_sort_cron_by_interval( $a, $b ) {
	return $a['interval'] - $b['interval'];
}


/**
 * Get an event by its UID (Unique Identifier)
 *
 * @ignore
 * @link http://www.ietf.org/rfc/rfc2445.txt ICAL Specification 4.8.4.7
 * @link http://www.kanzaki.com/docs/ical/uid.html
 * @since 1.0
 * @param string $uid The universal ID.
 * @return boolean|object Event (post) object if an event was found. False otherwise.
 */
function eo_get_event_by_uid( $uid = false ){

	if( !$uid )
		return false;

	//TODO make more effecient - e.g. direct SQL
	$found_event = eo_get_events( array(
			'showpastevents' => true,
			'group_events_by' => 'series',
			'no_found_rows' => true,
			'update_post_term_cache' => false,
			'update_meta_term_cache' => false,
			'post_status' => 'any',
			'numberposts' => 1,
			'meta_query' => array(
					array(
							'key' => '_eventorganiser_uid',
							'value' => $uid
					),
			)
	));

	if( $found_event ){
		return $found_event[0];
	}
	return false;
}


/**
 * Fetches a remote feed, parses the contents and updates the appropriate events.
 * 
 * It adds missing events, updates existing ones, and removes events which belong
 * to this feed, but were not present in the recent fetch.
 * 
 * Errors are added to the feed log.
 *
 * @since 1.0
 * 
 * @uses EO_ICAL_Parser
 * 
 * @param int $feed_id The (post) ID of the feed.
 * @return bool True on success, false on error.
 */
function eo_fetch_feed( $feed_id ){

	//Include ICAL parser
	require_once( EVENT_ORGANISER_DIR . 'includes/class-eo-ical-parser.php' );

	$source = get_post_meta( $feed_id, '_eventorganiser_feed_source', true );
	delete_post_meta( $feed_id, '_eventorganiser_feed_log' );
	delete_post_meta( $feed_id, '_eventorganiser_feed_warnings' );

	if( !$source ){
		$log = array(
				'log' =>  __( 'No source detected', 'eventorganiser' ),
				'log_code' => 'no-source',
				'timestamp' => time(),
		);
		add_post_meta( $feed_id, '_eventorganiser_feed_log', serialize( $log ) );
		return false;
	}

	$ical = new EO_ICAL_Parser();
	$response = $ical->parse( $source );

	if( is_wp_error( $response ) ){
		$code = $response->get_error_code();
		$log = array(
				'log' =>  $response->get_error_message( $code ),
				'log_code' => $code,
				'timestamp' => time(),
		);
		add_post_meta( $feed_id, '_eventorganiser_feed_log', serialize( $log ) );
		return false;
	}

	$parsed_events = array();
	$blog_timezone = eo_get_blog_timezone();

	//Feed settings
	$organiser = get_post_meta( $feed_id, '_eventorganiser_feed_organiser', true );
	$category = (int) get_post_meta( $feed_id, '_eventorganiser_feed_category', true );
	$status = get_post_meta( $feed_id, '_eventorganiser_feed_status', true );
	
	//Log any errors:
	if( !empty( $ical->warnings ) ){
		foreach( $ical->warnings as $warning ){
			$log = array(
				'log' =>  $warning->get_error_message(),
				'log_code' => $warning->get_error_code(),
				'timestamp' => time(),
			);
			add_post_meta( $feed_id, '_eventorganiser_feed_warnings', $log );
		}
	}
	
	if( !empty( $ical->errors ) && is_wp_error( $ical->errors[0] ) ){
		$error = $ical->errors[0];
		$log = array(
				'log' =>  $error->get_error_message(),
				'log_code' => $error->get_error_code(),
				'timestamp' => time(),
		);
		add_post_meta( $feed_id, '_eventorganiser_feed_log', serialize( $log ) );
		return false;
	}
	
	foreach( $ical->events as $event ){
		//TODO refactor eo_insert_event & eo_update_event...

		$uid = !empty( $event['uid'] ) ? $event['uid'] : false;

		$post_keys = array( 'post_title', 'post_status', 'post_content' );
		$event_post =  array_intersect_key( $event, array_flip( $post_keys ) );
		$event_post['post_author'] = $organiser;

		//Maybe over-ride post_status
		if( $status ){
			$event_post['post_status'] = $status;
		}

		$schedule_keys = array( 'start', 'end', 'all_day', 'schedule', 'schedule_meta', 'schedule_last', 'frequency', 'include', 'exclude', 'number_occurrences');
		$event_schedule =  array_intersect_key( $event, array_flip( $schedule_keys ) );
		
		$meta_key_map = array(
			'url' => '_event_ical_url'
		);
		/**
		 * $meta_key_map array indexed by attribute, with values as the meta key
		 */
		$meta_key_map =  apply_filters( 'eventorganiser_ical_sync_meta_key_map', $meta_key_map );

		//Set timezone
		foreach( array( 'start', 'end', 'schedule_last' ) as $key ){
			if( !empty( $event_schedule[$key] ) && $event_schedule[$key] instanceof DateTime ){
				$event_schedule[$key]->setTimezone( $blog_timezone );
			}
		}
		
		if( eventorganiser_get_option( 'deleteexpired' ) ){
			//We delete expired events, so don't add them if the event has finished.
			
			$parsed_schedule = _eventorganiser_generate_occurrences( $event_schedule );
			
			if( $parsed_schedule && !is_wp_error( $parsed_schedule ) ){
				
				$last  = clone $parsed_schedule['schedule_last'];
				$start = clone $parsed_schedule['start'];
				$end   = clone $parsed_schedule['end'];
				$now   = new DateTime( 'now', eo_get_blog_timezone() );
			
				$seconds      = round( abs( $start->format('U') - $end->format('U') ) );
				$days         = floor( $seconds/86400 );// 86400 = 60*60*24 seconds in a normal day
				$sec_diff     = $seconds - $days*86400;
				$duration_str = '+'.$days.'days '.$sec_diff.' seconds';
				
				$last_end = clone $last;
				$last_end->modify( $duration_str );

				if( $last_end < $now ){
					//SKIP!	
					continue;
				}
				
			}
	
		}

		//Try to find event:
		$found_event = eo_get_event_by_uid( $uid );
		
		//Update or create event
		if( $found_event ){
			$event_id = $found_event->ID;
			$event_id = eo_update_event( $event_id, $event_schedule, $event_post );
			if( is_wp_error( $event_id ) ){
				//Log any errors:
				$log = array(
						'log' =>  $event_id->get_error_message(),
						'log_code' => $event_id->get_error_code(),
						'timestamp' => time(),
				);
				add_post_meta( $feed_id, '_eventorganiser_feed_warning', serialize( $log ) );
				continue;
			}else{
				$parsed_events[] = $event_id;
				update_post_meta( $event_id, '_eventorganiser_feed', $feed_id );
				do_action( 'eventorganiser_ical_sync_event_updated', $event_id, $event, $feed_id );
			}
		}else{
			$event_id = eo_insert_event( $event_post, $event_schedule );
			if( is_wp_error( $event_id ) ){

				//Log any errors:
				$log = array(
					'log' =>  $event_id->get_error_message(),
					'log_code' => $event_id->get_error_code(),
					'timestamp' => time(),
				);
				add_post_meta( $feed_id, '_eventorganiser_feed_warning', serialize( $log ) );
					
				continue;
			}else{
				$parsed_events[] = $event_id;
				update_post_meta( $event_id, '_eventorganiser_uid', $uid );
				update_post_meta( $event_id, '_eventorganiser_feed', $feed_id );
				update_post_meta( $event_id, '_eventorganiser_feed_url', $source );
				do_action( 'eventorganiser_ical_sync_event_inserted', $event_id, $event, $feed_id );
			}
		}
		
		//Handle metadata
		$meta_data = eo_ical_array_combine_assoc( $meta_key_map, $event );
		if( $meta_data ){
			foreach( $meta_data as $meta_key => $meta_value ){
				update_post_meta( $event_id, $meta_key, $meta_value );
			}
		}

		//Handle venue
		if( !empty( $event['event-venue'] ) ){
			$venue = $event['event-venue'];
			if( !empty($venue['name']) ) {
				$found_venue = get_page_by_title( $venue['name'], OBJECT, eventorganiser_get_option('new_post_type_venue') );
			} else {
				#ck: Get venue post by street address
				if( !empty($venue['street']) ) {
					$args = array(
						'post_type' => 'tribe_venue',
						'post_status' => 'publish',
						'meta_query' => array(
							array(
								'key' => '_VenueAddress',
								'value' => $venue['street']
							)
						)
					);
					$found_venues = get_posts($args);
					if ( !empty($found_venues) ) {
						$found_venue = $found_venues[0];
					} else {
						$found_venue = NULL;
					}
				} else {
					$found_venue = NULL;
				}
			}
			if( $found_venue ){
				$venue_id = (int) $found_venue->ID;
			}else{
				
				//If lat/lng meta data is set, include that
				$args = array();
				if ( isset( $event['geo']) ) {
					$args['latitude'] = $event['geo']['lat'];
					$args['longtitude'] = $event['geo']['lng'];
				}
				
				$venue_id = eo_insert_tribe_venue( $venue, $args );
			}
				
			if( $venue_id ){
				update_post_meta( $event_id, '_EventVenueID', $venue_id );
			}
		}

		//Handle category
		$cats = array();

		if( $category > 0 ){
			//Category is preset
			$cats = array( $category );

		}elseif( !empty( $event['event-category'] ) ){
			foreach( $event['event-category'] as $category_name ){

				$found_cat = get_term_by( 'name', $category_name, 'event-category' );

				if( $found_cat ){
					$cats[] = (int) $found_cat->term_id;
				}else{
					$new_cat = wp_insert_term( $category_name, 'event-category', array() );

					if( !is_wp_error( $new_cat ) && !$new_cat ){
						$cats[] = (int) $new_cat['term_id'];
					}
				}
			}
		}

		if( $cats ){
			wp_set_object_terms( $event_id, $cats, 'event-category' );
		}

	}

	update_post_meta( $feed_id, '_eventorganiser_feed_events_parsed', count( $ical->events ) );

	$delete_events = eo_get_events( array(
			'numberposts' => -1,
			'fields' => 'ids',
			'post__not_in' => $parsed_events,
			'no_found_rows' => true,
			'showpastevents' => true,
			'group_events_by' => 'series',
			'update_post_term_cache' => false,
			'update_meta_term_cache' => false,
			'meta_query' => array(
					array(
							'key' => '_eventorganiser_feed',
							'value' => $feed_id,
					)
			)
	));
	if( $delete_events ){
		foreach( $delete_events as $delete_event ){
			wp_trash_post( $delete_event );
		}
	}

	//Update last modified
	wp_update_post( array( 'ID' => $feed_id ) );
	return true;
}


/**
 * Combines two arrays by joining them by their key. 
 * 
 * This is similar to array_combine, but rather than combining
 * by index, the array is combined by key. Any keys not found 
 * in both are ignored.
 * 
 * @param array $key_array   Array whose values form the keys of the returned array 
 * @param array $value_array Array whose values form the values of the returned array
 * @return array The combined array
 */
function eo_ical_array_combine_assoc( $key_array, $value_array ) {
	
	$output = array();
	$keys = array_keys( array_intersect_key( $key_array, $value_array ) );
	
	if( $keys ){
		foreach( $keys as $key ){
			$output[$key_array[$key]] = $value_array[$key]; 
		}
	}
	
	return $output;
}