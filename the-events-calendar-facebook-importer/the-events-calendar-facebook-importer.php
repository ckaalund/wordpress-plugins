<?php
/*
Plugin Name: The Events Calendar: Facebook Events
Description: Import events into The Events Calendar from a Facebook organization or page.
Version: 3.7
Author: Modern Tribe, Inc.
Author URI: http://m.tri.be/22
Text Domain: tribe-fb-import
License: GPLv2
*/

/*
Copyright 2012 Modern Tribe Inc. and the Collaborators

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

// Don't load directly
if ( !defined( 'ABSPATH' ) )
	die( '-1' );

/**
 * @see https://developers.facebook.com/docs/reference/api/event/ Facebook Event Documentation
 */
class Tribe_FB_Importer {

	protected static $instance;
	const VERSION = '3.7';
	const REQUIRED_TEC_VERSION = '3.7';
	static $fb_graph_url = 'https://graph.facebook.com/';
	static $default_app_id = '404935409546807';
	static $default_app_secret = 'a1300fded85f77fc64f14fdb69395545';
	public static $origin = 'facebook-importer';
	static $valid_reccurence_patterns = array( 'weekly' => 604800, 'daily' => 86400, 'twicedaily' => 43200, 'hourly' => 3600 );
	public $errors = array();
	public $errors_images = array();
	public $success = false;
	public $imported_total = 0;
	public $empty_key_notice = false;

	/**
	 * Object representing a Facebook entity.
	 *
	 * @var stdClass
	 */
	protected $fb_object;

	/**
	 * Date format used during import of events.
	 *
	 * @var string
	 */
	protected $date_format = '';


	/**
	 * class constructor
	 * run necessary hooks
	 *
	 * @since 1.0
	 * @author jkudish
	 */
	function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'reset_default_app' ) );
		add_action( 'admin_menu', array( $this, 'add_import_page' ) );
		add_action( 'tribe_settings_do_tabs', array( $this, 'add_setting_page' ) );
		add_action( 'tribe_fb_auto_import', array( $this, 'do_auto_import' ) );
		add_action( 'tribe_settings_after_save_fb-import', array( $this, 'manage_import_schedule' ) );
		add_filter( 'cron_schedules', array( $this, 'cron_add_weekly' ) );
		add_action( 'wp_before_admin_bar_render', array( $this, 'addFacebookToolbarItems' ), 30 );
		add_action( 'plugin_action_links_' . plugin_basename(__FILE__), array( $this, 'addLinksToPluginActions' ) );
		add_action( 'before_delete_post', array( $this, 'setDeletedEventArrayOption'), 10, 1 );
		add_filter( 'tribe_help_tab_forums_url', array( $this, 'helpTabForumsLink' ), 100 );
		add_filter( 'tribe_fb_get_app_id', array( $this, 'check_empty_app_keys' ) );
		add_filter( 'tribe_fb_get_app_secret', array( $this, 'check_empty_app_keys' ) );

		$this->date_format = apply_filters( 'tribe_fb_date_format', get_option( 'date_format' ) );
	}

	/**
	 * run hooks on WP init
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return void
	 */
	function init() {
		load_plugin_textdomain( 'tribe-fb-import', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );
	}

	/**
	 * get the url used for the FB graph API
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return string the graph API url
	 */
	function get_graph_url() {
		return apply_filters( 'tribe_fb_get_graph_url', self::$fb_graph_url );
	}

	/**
	 * If in admin check that app id & secret are not default,
	 * remove and notify if they are.
	 * @since  1.0.6
	 * @author tim@imaginesimplicity.com
	 * @return bool
	 */
	function reset_default_app(){
		if( !is_admin() )
			return false;
		$reset_default = false;
		if( $this->get_app_id() == self::$default_app_id ){
			$reset_default = true;
			TribeEvents::setOption( 'fb_api_key', '' );
		}
		if( $this->get_app_secret() == self::$default_app_secret ){
			$reset_default = true;
			TribeEvents::setOption( 'fb_api_secret', '' );
		}
		if( $reset_default ){
			add_action( 'admin_notices', array( $this, 'default_app_notices' ) );
			return true;
		}
		return false;
	}

	/**
	 * Notice for removing default app strings
	 *
	 * @since 1.0.6
	 * @author Reid
	 * @return void
	 */
	function default_app_notices(){
		$tribe_facebook_settings_url = admin_url('edit.php?post_type=tribe_events&page=tribe-events-calendar&tab=fb-import');
		echo '<div class="error"><p>';
		printf( __('As of version 1.0.6, you need to enter your own Facebook App ID and App secret. Visit %s to generate yours. Enter your App ID and App Secret on the <a href=\'%s\'>event\'s settings page</a>. ', 'tribe-fb-import' ), '<a href="https://developers.facebook.com/apps">https://developers.facebook.com/apps</a>', $tribe_facebook_settings_url);
		echo '</p></div>';
	}

	/**
	 * check if Facebook app keys are empty and notify on the admin
	 * @since  1.0.6
	 * @author tim@imaginesimplicity.com
	 * @param  string $key
	 * @return string $key
	 */
	function check_empty_app_keys( $key ){
		if( empty($key) && !$this->empty_key_notice ){
			add_action( 'admin_notices', array( $this, 'empty_app_notices' ) );
			$this->empty_key_notice = true;
		}
		return $key;
	}

	/**
	 * Translation Strings for App & Secret requirement
	 * @since 1.0.6
	 * @author Reid
	 * @return void
	 */
	function empty_app_notices(){
		echo '<div class="updated notice"><p>';
		printf( __( 'Signing up for a Facebook App ID and Secret only takes a second. Visit %s to create yours. ', 'tribe-fb-import' ), '<a href="https://developers.facebook.com/apps">https://developers.facebook.com/apps</a>' );
		printf( __( 'Visit %s to learn more about Facebook App Ids and Secrets. ', 'tribe-fb-import' ), '<a href="https://developers.facebook.com/docs/guides/canvas/">https://developers.facebook.com/docs/guides/canvas/</a>' );
		printf( __( 'You must enter a Facebook App ID and App Secret to continue importing events from Facebook. Visit %s to create yours. ', 'tribe-fb-import' ), '<a href="https://developers.facebook.com/apps">https://developers.facebook.com/apps</a>' );
		echo '</p></div>';
	}

	/**
	 * get the APP ID stored in the database or the default
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return string the APP ID
	 */
	function get_app_id() {
		return apply_filters( 'tribe_fb_get_app_id', TribeEvents::getOption( 'fb_api_key' ) );
	}

	/**
	 * get the APP secret stored in the database or the default
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return string the APP secret
	 */
	function get_app_secret() {
		return apply_filters( 'tribe_fb_get_app_secret', TribeEvents::getOption( 'fb_api_secret' ) );
	}

	/**
	 * get the user or page IDs saved in the database that the user wants to import
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return array the user or page IDs
	 */
	function get_user_or_page_ids() {
		$user_or_page_ids = TribeEvents::getOption( 'fb_user_or_page_ids', array() );
		if ( is_string( $user_or_page_ids ) ) {
			$user_or_page_ids = explode( "\n", $user_or_page_ids );
		}
		return apply_filters( 'tribe_fb_get_user_or_page_ids', $user_or_page_ids );
	}

	/**
	 * build the URL used to get the acccess token
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return string the URL
	 */
	function get_access_token_url() {
		$url = apply_filters( 'tribe_fb_get_access_token_url', add_query_arg( array( 'grant_type' => 'client_credentials', 'client_id' => $this->get_app_id(), 'client_secret' => $this->get_app_secret() ), self::$fb_graph_url . 'oauth/access_token' ) );
		do_action( 'log', 'access token url', 'tribe-events-facebook', $url );
		return $url;
	}

	/**
	 * get the raw response access token
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return string the access token
	 */
	function get_raw_access_token() {
		$response = wp_remote_get( $this->get_access_token_url() );
		return wp_remote_retrieve_body( $response );
	}

	/**
	 * get the parsed access token
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return string the access token
	 */
	function get_access_token() {
		$access_token = preg_replace( '/access_token=([^&]+)/', '$1', $this->get_raw_access_token() );
		return apply_filters( 'tribe_fb_get_access_token', $access_token );
	}

	/**
	 * build out a graph url using query args and the FB access token
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param string $path the path for the url
	 * @param array $query_args the arguments to use in building the query string
	 * @return string the full URL
	 */
	function build_url_with_access_token( $path = '', $query_args = array() ) {
		$query_args = array_merge( $query_args, array( 'access_token' => $this->get_access_token() ) );
		$url = add_query_arg( $query_args, $this->get_graph_url() . $path );
		do_action( 'log', 'url with access token', 'tribe-events-facebook', $url);
		return $url;
	}

	/**
	 * retrive the body of a page using the HTTP API and json decode the result
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param string $url the URL to retrieve
	 * @return string the json string
	 */
	function json_retrieve( $url ) {
		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ) );
		$response = wp_remote_get( $url );
		$response = json_decode( wp_remote_retrieve_body( $response ) );
		remove_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ) );
		return $response;
	}

	/**
	 * increase the HTTP request timeout because sometimes FB is slow
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param int $timeout the original timeout
	 * @return int the filtered timeout
	 */
	function http_request_timeout( $timeout ) {
		return (int) apply_filters( 'tribe_fb_http_request_timeout', 10, $timeout );
	}

	/**
	 * retrive the data object of a json response
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param string $url the URL to retrieve
	 * @return mixed the result of the data object
	 */
	function json_retrieve_data( $url ) {
		$json = $this->json_retrieve( $url );
		return ( !empty( $json->data ) ) ? $json->data : false;
	}


	/**
	 * retrieve a facebook object taht is available at the root of the FB graph API
	 * example: https://graph.facebook.com/12345
	 *
	 * @param string $object_id the object to retrieve
	 * @return array the json data
	 */
	function get_facebook_object( $object_id ) {
		$url = $this->build_url_with_access_token( $object_id );
		$this->fb_object = $this->json_retrieve( $url );
		return $this->fb_object;
	}

	/**
	 * Retrieve a facebook event photo url (after redirect) along with photo data.
	 *
	 * @param string $object_id
	 * @return mixed
	 */
	function get_facebook_photo( $object_id ) {
		$photo_error = false;
		$api_url = $this->build_url_with_access_token( $object_id, array( 'fields' => 'cover' ) );

		do_action( 'log', 'api url', 'tribe-events-facebook', $api_url );
		$response =  $this->json_retrieve( $api_url );

		if ( isset( $response->cover ) && isset( $response->cover->source ) ) {
			$new_path = $response->cover->source;
			$get_photo = wp_remote_get( $response->cover->source );
		} else {
			$photo_error = true;
		}

		// hat tip to @jessebrede (github) for suggesting a tweak to import old fb image formats if erroring
		if( $photo_error || !empty( $get_photo->errors ) ) {
			$api_url = $this->build_url_with_access_token( $object_id . '/', array( 'fields' => 'picture', 'type' => 'large', 'return_ssl_resources' => 1 ) );
			$response =  $this->json_retrieve( $api_url );
			$new_path = str_replace("_q.", "_n.", $response->picture->data->url );
			$get_photo = wp_remote_get( $new_path );
		}

		if ( ! is_wp_error($get_photo) && ! $photo_error ) {
			// setup return object
			$photo['url'] = $new_path;
			$photo['source'] = $get_photo['body'];
			return apply_filters( 'tribe_fb_get_facebook_photo', $photo, $api_url, $response );
		} else {
			if ( is_wp_error( $get_photo ) ) {
				$this->errors_images[] = $get_photo->get_error_message();
			} else {
				$this->errors_images[] = __( 'Could not successfully import the image for unknown reasons.', 'tribe-fb-import' );
			}
		}

		return false;
	}

	/**
	 * Requests for images from Facebook need an explicit width and height in order to get a reasonably sized
	 * non-square image back. This function tries to determine a size that can be scaled/cropped to fit all registered
	 * image sizes even where they each have a different ratio.
	 *
	 * @deprecated 3.4.1
	 * @return array [ width, height ]
	 */
	public function get_desired_img_dimensions() {
		// We need to access default and theme/plugin registered sizes
		$size_list = $GLOBALS['_wp_additional_image_sizes'];
		$size_labels = get_intermediate_image_sizes();

		// Reasonable defaults
		$width = 600;
		$height = 450;

		// We want to determine the biggest width and height we'll require to satisfy all sizes
		foreach ( $size_labels as $size ) {
			// Plugin/theme registered size? Check this first to avoid a db hit
			if ( isset($size_list[$size]['width']) && isset($size_list[$size]['width']) ) {
				$this_width = absint( $size_list[$size]['width'] );
				$this_height = absint( $size_list[$size]['height'] );
			}
			// Default size?
			else {
				$this_width = absint( get_option($size . '_size_w') );
				$this_height = absint( get_option($size . '_size_h') );
			}

			// Compare and keep the biggest
			$height = $this_height > $height ? $this_height : $height;
			$width = $this_width > $width ? $this_width : $width;
		}

		return array( $width, $height );
	}

	/**
	 * retrieve all events for a FB user or page
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param string $user_or_page_id the ID to retrieve events for
	 * @param string $return what would you like to receive ( event IDs or objects )
	 * @return array the events
	 */
	function get_events_for_object( $user_or_page_id, $return = 'id' ) {
		$url = $this->build_url_with_access_token( $user_or_page_id . '/events', array( 'limit' => 9999, 'since' => apply_filters( 'tribe_fb_import_since', date( 'Y-m-d' ), $user_or_page_id ) ) );
		$data = (array) $this->json_retrieve_data( $url );
		$return_array = array();
		if ( false === $data[0] ) {
			return false;
		}
		foreach ( $data as $event ) {
			if ( $return == 'object' ) {
				$return_array[] = $event;
			} elseif ( $return == 'id' ) {
				$return_array[] = $event->id;
			} else {
				_doing_it_wrong( 'get_events_for_object', __( 'The return argument should be object or id', 'tribe-fb-import' ) , '1.0' );
				return false;
			}
		}
		return array_reverse( $return_array );
	}

	/**
	 * get all events for specified user or page ID
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param array $user_or_page_ids the IDs to retrieve events for
	 * @param string $return what would you like to receive ( event IDs or objects )
	 * @return array the events
	 */
	function get_events_for_specfic_user_or_page_ids( $user_or_page_ids = array(), $return = 'id' ) {
		$events = array();
		foreach ( $user_or_page_ids as $user_or_page_id ) {
			$events = array_merge( $events, $this->get_events_for_object( $user_or_page_id, $return ) );
		}
		return $events;
	}

	/**
	 * find a locally stored event/venue/organizer with the specified FB event ID
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param string $facebook_id the Facebook ID of the event
	 * @param string $object_type the type of object we are looking for
	 * @param string $fallback_object_name the post title used as a fallback if $facebook_id is empty for some reason
	 * @return int|null the event ID or null on failure
	 */
	function find_local_object_with_fb_id( $facebook_id, $object_type = 'event', $fallback_object_name = null ) {
		switch ( $object_type ) {
			case 'event' :
				$meta_key = '_FacebookID';
				$post_type = TribeEvents::POSTTYPE;
			break;
			case 'organizer' :
				$meta_key = '_OrganizerFacebookID';
				$post_type = TribeEvents::ORGANIZER_POST_TYPE;
			break;
			case 'venue' :
				$meta_key = '_VenueFacebookID';
				$post_type = TribeEvents::VENUE_POST_TYPE;
			break;
			default :
				return new WP_Error( 'invalid_object_type', __( 'Object type provided is invalid', 'tribe-fb-import' ), $object_type );
		}

		$query = new WP_Query();
		$query_args = array(
			'post_type' => $post_type,
			'post_status' => 'any',
			'posts_per_page' => -1,
			'nopaging' => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		);

		if ( false === $facebook_id ) {
			$query_args['name'] = sanitize_title( $fallback_object_name );
 		} else {
 			$query_args['meta_query'] = array(
				array(
					'key' => $meta_key,
					'value' => $facebook_id,
				),
			);
 		}

 		$query->query( $query_args );

		wp_reset_query();
		$post_id = ( !empty( $query->posts[0] ) ) ? $query->posts[0]->ID : false;

		return apply_filters( 'tribe_fb_find_local_object_with_fb_id', $post_id );
	}

	/**
	 * determine if an event is an all day event or not
	 * Facebook's API has a weird setting for all day events
	 *
	 * @param string $start_time the start time
	 * @param string $end_time the end time
	 * @param int $facebook_id the Facebook ID of the event / used in the filter
	 * @return bool
	 */
	function determine_if_is_all_day( $start_time, $end_time, $facebook_id ) {
		$start_time = date( 'Hi', strtotime( $start_time ) );
		$end_time = date( 'Hi', strtotime( $end_time ) );
		$all_day_start_time = apply_filters( 'tribe_fb_all_day_start_time', '1900' );
		$all_day_end_time = apply_filters( 'tribe_fb_all_day_end_time', '2200' );
		if ( $all_day_start_time == $start_time && $all_day_end_time == $end_time ) {
			$is_all_day = true;
		} else {
			$is_all_day = false;
		}
		return apply_filters( 'tribe_fb_determine_if_is_all_day', $is_all_day, $start_time, $end_time, $facebook_id );
	}

	/**
	 * parse the facebook venue given an object ID
	 * or use the venue property of the event itself
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param object $facebook_event the Facebook event json object
	 * @return object the venue object
	 */
	function parse_facebook_venue( $facebook_event ) {
		$venue = new stdClass;
		if ( !empty( $facebook_event->venue->id ) ) {
			$raw_venue = $this->get_facebook_object( $facebook_event->venue->id );
			$venue->facebook_id = ( !empty( $raw_venue->id ) ) ? trim( $raw_venue->id ) : $facebook_event->venue->id;
			$venue->name = ( !empty( $raw_venue->name ) ) ? trim( $raw_venue->name ) : false;
			$venue->description = ( !empty( $raw_venue->description ) ) ? trim( $raw_venue->description ) : false;
			$venue->address = ( !empty( $raw_venue->location->street ) ) ? trim( $raw_venue->location->street ) : false;
			$venue->city = ( !empty( $raw_venue->location->city ) ) ? trim( $raw_venue->location->city ) : false;
			$venue->state = ( !empty( $raw_venue->location->state ) ) ? trim( $raw_venue->location->state ) : false;
			$venue->country = ( !empty( $raw_venue->location->country ) ) ? trim( $raw_venue->location->country ) : false;
			$venue->zip = ( !empty( $raw_venue->location->zip ) ) ? trim( $raw_venue->location->zip ) : false;
			$venue->phone = ( !empty( $raw_venue->phone ) ) ? trim( $raw_venue->phone ) : false;
		} else {
			$venue->facebook_id = false;
			$venue->name = ( !empty( $facebook_event->location ) ) ? trim( $facebook_event->location ) : false;
			$venue->description = false;
			$venue->address = ( !empty( $facebook_event->venue->street ) ) ? trim( $facebook_event->venue->street ) : false;
			$venue->city = ( !empty( $facebook_event->venue->city ) ) ? trim( $facebook_event->venue->city ) : false;
			$venue->state = ( !empty( $facebook_event->venue->state ) ) ? trim( $raw_venue->venue->state ) : false;
			$venue->country = ( !empty( $facebook_event->venue->country ) ) ? trim( $raw_venue->venue->country ) : false;
			$venue->zip = ( !empty( $facebook_event->venue->zip ) ) ? trim( $facebook_event->venue->zip ) : false;
			$venue->phone = ( !empty( $facebook_event->venue->phone ) ) ? trim( $facebook_event->venue->phone ) : false;
		}
		return $venue;
	}

	/**
	 * parse the facebook organizer given an object ID
	 * or use the owener property of the event itself
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param object $facebook_event the Facebook event json object
	 * @return object the organizer object
	 */
	function parse_facebook_organizer( $facebook_event ) {
		$organizer = new stdClass;
		if ( !empty( $facebook_event->owner->id ) ) {
			$raw_organizer = $this->get_facebook_object( $facebook_event->owner->id );
			$organizer->facebook_id = ( !empty( $raw_organizer->id ) ) ? trim( $raw_organizer->id ) : trim( $facebook_event->owner->id );
			$organizer->name = ( !empty( $raw_organizer->name ) ) ? trim( $raw_organizer->name ) : trim( $facebook_event->owner->name );
			$organizer->phone = ( !empty( $raw_organizer->phone ) ) ? trim( $raw_organizer->phone ) : false;
			$organizer->website = ( !empty( $raw_organizer->link ) ) ? trim( $raw_organizer->link ) : false;
			$organizer->email = ( !empty( $raw_organizer->email ) ) ? trim( $raw_organizer->email ) : false;
		} else {
			$organizer->facebook_id = false;
			$organizer->name = ( !empty( $facebook_event->owner->name ) ) ? trim( $facebook_event->owner->name ) : false;
			$organizer->phone = ( !empty( $facebook_event->owner->phone ) ) ? trim( $facebook_event->owner->phone ) : false;
			$organizer->website = ( !empty( $facebook_event->owner->website ) ) ? trim( $facebook_event->owner->website ) : false;
			$organizer->email = ( !empty( $facebook_event->owner->email ) ) ? trim( $facebook_event->owner->email ) : false;
		}
		return $organizer;
	}

	/**
	 * parse a facebook event to get all the necessary
	 * params to create the local event
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param object $facebook_event the Facebook event json object
	 * @return array the event paramaters
	 */
	function parse_facebook_event( $facebook_event ) {

		// fetch venue/organizer objects and local ID
		$organizer = $this->parse_facebook_organizer( $facebook_event );
		$local_organizer_id = $this->find_local_object_with_fb_id( $organizer->facebook_id, 'organizer', $organizer->name );
		$venue = $this->parse_facebook_venue( $facebook_event );
		$local_venue_id = $this->find_local_object_with_fb_id( $venue->facebook_id, 'venue', $venue->name );

		// setup the base array
		$event_params = array(
			'FacebookID' => $facebook_event->id,
			'post_title' => ( !empty( $facebook_event->name ) ) ? $facebook_event->name : '',
			'post_status' => TribeEvents::getOption( 'fb_default_status', 'publish' ),
			'post_content' => ( !empty( $facebook_event->description ) ) ? make_clickable( $facebook_event->description ) : '',
		);


		// set organizer only if no local organizer exists
		if ( empty( $local_organizer_id ) && !empty( $organizer->name ) ) {
			$event_params['Organizer'] = array(
				'FacebookID' => $organizer->facebook_id,
				'Organizer' => $organizer->name,
				'Phone' => $organizer->phone,
				'Website' => $organizer->website,
				'Email' => $organizer->email,
			);
		}

		// set organizer ID
		if ( !empty( $local_organizer_id ) ) {
			$event_params['EventOrganizerID'] = $local_organizer_id;
		}

		// set venue only if local venue is empty
		if ( empty( $local_venue_id ) && !empty( $venue->name ) ) {
			$event_params['Venue'] = array(
				'FacebookID' => $venue->facebook_id,
				'Venue' => $venue->name,
				'Address' => $venue->address,
				'City' => $venue->city,
				'StateProvince' => $venue->state,
				'Country' => $venue->country,
				'Zip' => $venue->zip,
				'Phone' => $venue->phone,
			);
		}

		// set venue ID
		if ( !empty( $local_venue_id ) ) {
			$event_params['EventVenueID'] = $local_venue_id;
		}

		// set the dates
		$event_params['EventStartDate'] = TribeDateUtils::dateOnly( $facebook_event->start_time );
		$event_params['EventEndDate'] = TribeDateUtils::dateOnly( $facebook_event->end_time );

		// determine all day / set the time
		if ( $this->determine_if_is_all_day( $facebook_event->start_time, $facebook_event->end_time, $facebook_event->id ) ) {
			$event_params['EventAllDay'] = 'yes';
		} else {
			$event_params['EventStartHour'] = TribeDateUtils::hourOnly( $facebook_event->start_time );
			$event_params['EventStartMinute'] = TribeDateUtils::minutesOnly( $facebook_event->start_time );
			$event_params['EventStartMeridian'] = TribeDateUtils::meridianOnly( $facebook_event->start_time );
			$event_params['EventEndHour'] = TribeDateUtils::hourOnly( $facebook_event->end_time );
			$event_params['EventEndMinute'] = TribeDateUtils::minutesOnly( $facebook_event->end_time );
			$event_params['EventEndMeridian'] = TribeDateUtils::meridianOnly( $facebook_event->end_time );
		}

		return apply_filters( 'tribe_fb_parse_facebook_event', $event_params );
	}

	/**
	 * Create or update an event given a Facebook ID
	 *
	 * @param int $facebook_event_id the Facebook ID of the event
	 * @return array|WP_Error
	 * @author jkudish
	 * @since 1.0
	 */
	function create_local_event( $facebook_event_id ) {
		// Get the facebook event
		$facebook_event = $this->get_facebook_object( $facebook_event_id );
		$event_picture = apply_filters( 'tribe_fb_event_img', $this->get_facebook_photo( $facebook_event_id ), $facebook_event_id );

		if ( isset( $facebook_event->id ) ) {
			// fix facebook date offsets -8
			$this->offset_date_to_timezone( $facebook_event->start_time );
			$this->offset_date_to_timezone( $facebook_event->end_time );

			// parse the event
			$args = $this->parse_facebook_event( $facebook_event );

			if ( !$this->find_local_object_with_fb_id( $args['FacebookID'], 'event' ) ) {
				// filter the origin trail
				add_filter( 'tribe-post-origin', array( $this, 'origin_filter' ) );

				// create the event
				$event_id = tribe_create_event( $args );

				// count this as a successful import
				$this->imported_total++;

				if ( ! empty( $event_picture ) ) {

					// setup clean vars to import the photo
					$event_picture['url'] = stripslashes($event_picture['url']);
					$uploads = wp_upload_dir();
					$wp_filetype = wp_check_filetype($event_picture['url'], null );
					$filename = wp_unique_filename( $uploads['path'], basename('facebook_event_' . $facebook_event->id), $unique_filename_callback = null ) . '.' . $wp_filetype['ext'];
					$full_path_filename = $uploads['path'] . "/" . $filename;

					if ( substr_count( $wp_filetype['type'], "image" ) ) {

						// push the actual picture data to the local file system
						$file_saved = file_put_contents($uploads['path'] . "/" . $filename, $event_picture['source']);

						if ( $file_saved ) {

							// setup attachment params
							$attachment = array(
								 'post_mime_type' => $wp_filetype['type'],
								 'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
								 'post_content' => '',
								 'post_status' => 'inherit',
								 'guid' => $uploads['url'] . "/" . $filename
							);

							// attach photo to post obj (event)
							$attach_id = wp_insert_attachment( $attachment, $full_path_filename, $event_id );

							if ( $attach_id ) {
								// set the thumbnail (featured image)
								set_post_thumbnail($event_id, $attach_id);

								// attach metadata for attachment
								require_once(ABSPATH . "wp-admin" . '/includes/image.php');
								$attach_data = wp_generate_attachment_metadata( $attach_id, $full_path_filename );
								wp_update_attachment_metadata( $attach_id,  $attach_data );

							} else {
								$this->errors_images[] = sprintf( __( '%s. Event Image Error: Failed to save record into database.', 'tribe-fb-import' ), $facebook_event->name);
							}
						} else {
							$this->errors_images[] = sprintf( __( '%s. Event Image Error: The file cannot be saved.', 'tribe-fb-import' ), $facebook_event->name);
						}
					} else {
						$this->errors_images[] = sprintf( __( '%s. Event Image Error: "%s" is not a valid image. %s', 'tribe-fb-import' ), $facebook_event->name, basename($event_picture['url']), $wp_filetype['type'] );
					}
				}

				// set the event's Facebook ID meta
				update_post_meta( $event_id, '_FacebookID', $args['FacebookID'] );

				// set the event's map status if global setting is enabled
				if( tribe_get_option('fb_enable_GoogleMaps') ) {
					update_post_meta( $event_id, '_EventShowMap', true );
				}

				// get the created organizer/venue IDs
				$organizer_id = tribe_get_organizer_id( $event_id );
				$venue_id = tribe_get_venue_id( $event_id );

				// Set the post status to publish for the organizer and venue.
				if ( 'publish' !== get_post_status( $organizer_id ) ) $this->publish_post( $organizer_id );
				if ( 'publish' !== get_post_status( $venue_id ) ) $this->publish_post( $venue_id );


				// set organizer Facebook ID
				if ( isset( $args['Organizer']['FacebookID'] ) ) {
					update_post_meta( $organizer_id, '_OrganizerFacebookID', $args['Organizer']['FacebookID'] );
				}

				// set venue Facebook ID
				if ( isset( $args['Venue']['FacebookID'] ) ) {
					update_post_meta( $venue_id, '_VenueFacebookID', $args['Venue']['FacebookID'] );
				}

				// remove filter for the origin trail
				remove_filter( 'tribe-post-origin', array( $this, 'origin_filter' ) );

				return array( 'event' => $event_id, 'organizer' => $organizer_id, 'venue' => $venue_id );
			} else {
				return new WP_Error( 'event_already_exists', sprintf( __( 'The event "%s" was already imported from Facebook.', 'tribe-fb-import' ), $facebook_event->name, $facebook_event ) );
			}
		} else {
			do_action('log', 'Facebook event', 'tribe-events-facebook', $facebook_event);
			return new WP_Error( 'invalid_event', sprintf( __( "Either the event with ID %s does not exist, is marked as private on Facebook or we couldn't reach the Facebook API", 'tribe-fb-import' ), $facebook_event_id ) );
		}
	}

	/**
	 * Used to publish a post type and ensure it has a post name (slug).
	 *
	 * When the importer is set to save imported events as drafts, new venues and organizers will initially be
	 * created as drafts, too - but will not have a post_name. Simply calling wp_publish_post() doesn't cause
	 * this to happen automatically and so this method takes care of that additional step.
	 *
	 * @param int $id post ID (for the venue/organizer, typically)
	 */
	protected function publish_post( $id ) {
		$post = get_post( $id );

		// Ensure the post_name is populated
		if ( empty( $post->post_name ) ) {
			$post_name = sanitize_title( $post->post_title );
			wp_update_post( array( 'ID' => $id, 'post_name' => $post_name ) );
		}

		wp_publish_post( $id );
	}


	/**
	 * origin/trail filter
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return string facebook importer identifier
	 */
	function origin_filter() {
		return self::$origin;
	}

	/**
	 * returns an array of fb_uids given a text blob of them
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param string $fb_uids the raw Facebook identifiers
	 * @return array the Facebook identifiers
	 */
	function parse_fb_uids( $fb_uids ) {
		return array_map( 'trim', (array) explode( "\n", tribe_multi_line_remove_empty_lines( $fb_uids ) ) );
	}

	/**
	 * returns an array of FB event IDs given a text blob of them
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param string $raw_event_ids the raw Facebook event IDs
	 * @return array the parsed Facebook event IDs
	 */
	function parse_events_from_textarea( $raw_event_ids ) {
		if ( !preg_match( '/^[0-9\s]+$/', $raw_event_ids ) ) {
			$this->errors[] = __( 'The Facebook event IDs provided must be numeric and one per line.', 'tribe-fb-import' );
			return array();
		}

		return array_map( 'trim', (array) explode( "\n", tribe_multi_line_remove_empty_lines( $raw_event_ids ) ) );
	}

	/**
	 * add the import page to the Events admin menu
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return void
	 */
	function add_import_page() {
		add_submenu_page( '/edit.php?post_type=' . TribeEvents::POSTTYPE, __( 'Import: Facebook', 'tribe-fb-import' ), __( 'Import: Facebook', 'tribe-fb-import' ), 'edit_posts', 'import-fb-events', array( $this, 'do_import_page' ) );
	}

	/**
	 * offset_date_to_timezone useful for adjusting an imported DateTime to your site GMT/UTC offset
	 *
	 * @link https://gist.github.com/4704496
	 *
	 * @param string $datetime
	 * @param string $timezone default GMT
	 * @param string $return_format default ISO 8601
	 * @return string $datetime as $return_format
	 */
	function offset_date_to_timezone( &$datetime, $timezone = 'GMT', $return_format = 'c' ){

		// get site timezone offset
		$gmt_offset = get_option( 'gmt_offset' );
		$gmt_offset = str_replace( array( '.25', '.5', '.75' ), array( ':15', ':30', ':45' ), $gmt_offset );

		// set DateTime obj
		$datetime_obj = new DateTime( $datetime, new DateTimeZone( $timezone ) );

		// reset date timezone to be neutral for offset
		$datetime_obj->setTimezone(new DateTimeZone('GMT'));

		// modify the timezone with offset
		$datetime_obj->modify( $gmt_offset . ' hours' );

		// return start_time & end_time as ISO 8601 date per https://developers.facebook.com/docs/reference/api/event/
		$datetime = $datetime_obj->format( $return_format );
	}

	/**
	 * add tab to the Events Calendar settings page
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return void
	 */
	function add_setting_page() {
		/**
		 * @var $facebook_tab
		 */
		include( 'admin-views/settings-tab.php' );
		new TribeSettingsTab( 'fb-import', __( 'Facebook', 'tribe-fb-import' ), $facebook_tab );
	}

	/**
	 * generate the import page
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return void
	 */
	function do_import_page() {
		$this->process_import_page();
		include( 'admin-views/import-page.php' );
	}

	/**
	 * process import when submitted
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return void
	 */
	function process_import_page() {
		if ( ! empty( $_POST['tribe-confirm-import'] ) ) {
			// check nonce
			check_admin_referer( 'tribe-fb-import', 'tribe-confirm-import' );

			$events_to_import = array();
			$this->no_events_imported = true;

			// checked events from list
			if ( !empty( $_POST['tribe-fb-import-events'] ) ) {
				$events_to_import = array_merge( $events_to_import, $_POST['tribe-fb-import-events'] );
			}

			// individual events from textarea
			if ( !empty( $_POST['tribe-fb-import-events-by-id'] ) ) {
				$events_to_import = array_merge( $events_to_import, $this->parse_events_from_textarea( $_POST['tribe-fb-import-events-by-id'] ) );
			}
			// loop through events and import them
			if ( !empty( $events_to_import ) && empty( $this->errors ) ) {
				foreach ( $events_to_import as $facebook_event_id ) {
					$local_event = $this->create_local_event( $facebook_event_id );
					do_action('log', 'local event', 'tribe-events-facebook', $local_event);
					if ( is_wp_error( $local_event ) ) {
						$this->errors[] = $local_event->get_error_message();
					} else {
						$this->no_events_imported = false;
					}
				}
			} else {
				$this->errors[] = __( 'No valid events were provided for import. The import failed as a result.', 'tribe-fb-import' );
			}

			// mark it as successful
			if ( empty( $this->errors ) ) {
				$this->success = true;
			}
		}
	}

	/**
	 * generate/display the fields used in the import page
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param string $fb_uids the Facebook identifiers (parsed into an array) to generate the import fields with
	 * @return void
	 */
	function build_import_fields( $fb_uids ) {
		foreach ( $this->parse_fb_uids( $fb_uids ) as $fb_uid ) {
			// Skip if we can't load a valid FB object
			$this->get_facebook_object( $fb_uid );
			if ( ! $this->valid_fb_response() ) continue;

			// If a name is associated with it, then use it as the title
			if ( ! empty( $this->fb_object->name ) )
				echo '<h4>' . sprintf( _x( 'Events from %s:', '%s is the name of the Facebook user or page', 'tribe-fb-import' ), $this->fb_object->name ) . '</h4>';

			// Is this a person/individual? Explain that isn't allowed then skip to the next item
			if ( $this->is_person() ) {
				$name = ! empty( $this->fb_object->name ) ? $this->fb_object->name : $fb_uid;
				echo '<p>' . sprintf( __( '%s appears to be an individual. At this time events can only be fetched from organizations and pages, not individuals. If you would like to import an event from an individual, you can do so by entering the Facebook event ID in the box below.', 'tribe-fb-import' ), $name );
				continue;
			}

			// Print the list of events ready for import
			$this->list_events();
		}
	}

	/**
	 * Did Facebook respond with a valid object?
	 *
	 * If a specific error was returned by Facebook then this will be printed directly.
	 *
	 * @return bool
	 */
	protected function valid_fb_response() {
		$valid = ! empty( $this->fb_object ) && ! is_wp_error( $this->fb_object );

		if( ! empty( $this->fb_object->error ) ) {
			$valid = false;
			$message = sprintf( __( 'Facebook API Error: %s', 'tribe-fb-import' ), $this->fb_object->error->message );
			echo "<p>$message</p>";
		}

		return $valid;
	}

	/**
	 * Tries to determine if the provided Facebook object relates to an individual, rather than an
	 * organization or business, etc.
	 *
	 * @return bool
	 */
	protected function is_person() {
		return isset( $this->fb_object->gender );
	}

	/**
	 * Print a list of all events relating to the current Facebook entity.
	 */
	protected function list_events() {
		$fb_events = $this->get_events_for_object( $this->fb_object->id, 'object' );

		if ( ! empty( $fb_events ) ) {
			echo '<ul>';

			foreach ( $fb_events as $fb_event ) {
				$html_id = esc_attr( 'tribe-fb-import-event-' . $fb_event->id );
				$event_exists_locally = $this->find_local_object_with_fb_id( $fb_event->id );
				$imported = ( $event_exists_locally ) ? ' ' . __( '(previously imported)', 'tribe-fb-import' ) : '';
				$this->offset_date_to_timezone( $fb_event->start_time );
				$start_date = date_i18n( $this->date_format, strtotime( $fb_event->start_time ) );
				$title = $start_date . ' &mdash; ' . $fb_event->name . $imported;
				$title = apply_filters( 'tribe_fb_event_checkbox_label', $title, $start_date, $fb_event, $imported );
				echo '<li><label for="' . $html_id . '"><input class="checkbox" name="tribe-fb-import-events[]" type="checkbox" id="' . $html_id . '" type="tribe-fb-import-event" value="' . esc_attr( $fb_event->id ) . '" ';
				checked( (bool) $event_exists_locally );
				disabled( (bool) $event_exists_locally );
				echo '> ' . $title . '</label> (<a href="https://www.facebook.com/events/' . $fb_event->id . '/" target="_blank">link</a>)</li>';
			}

			echo '</ul>';
		}
		elseif ( ! empty( $this->fb_object->name ) ) {
			echo '<p>' . sprintf( _x( '%s does not have any Facebook events', '%s is the name of the Facebook user or page', 'tribe-fb-import' ), $this->fb_object->name ) . '</p>';
		}
	}

	/**
	 * perform import routine when scheduled event is meant to occur
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return void
	 */
	function do_auto_import() {
		$fb_uids = $this->parse_fb_uids( TribeEvents::getOption( 'fb_uids' ) );
		$deleted_facebook_ids = get_option( 'tribe_facebook_deleted_ids', array() );
		foreach ( $fb_uids as $fb_uid ) {
			$facebook_object = $this->get_facebook_object( $fb_uid );
			if ( !empty( $facebook_object ) && !is_wp_error( $facebook_object ) ) {
				$fb_events = $this->get_events_for_object( $facebook_object->id, 'object' );
				if ( !empty( $fb_events ) ) {
					foreach ( $fb_events as $fb_event ) {
						if ( !in_array( $fb_event->id, $deleted_facebook_ids ) ) {
							$local_event = $this->create_local_event( $fb_event->id );
						}
					}
				}
			}
		}
	}

	/**
	 * create a scheduled event when the facebook settings tab is saved
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return void
	 */
	function manage_import_schedule() {
		wp_clear_scheduled_hook( 'tribe_fb_auto_import' );
		if ( TribeEvents::getOption( 'fb_auto_import' ) ) {
			$recurrence = TribeEvents::getOption( 'fb_auto_frequency' );
			if ( array_key_exists( $recurrence, self::$valid_reccurence_patterns ) )
				wp_schedule_event( $this->get_first_occurence_timestamp( $recurrence ), $recurrence, 'tribe_fb_auto_import' );
		}
	}

	/**
	 * given a recurrence pattern, returns the timestamp of
	 * the first occurrence
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param string $recurrence the recurrence pattern
	 * @return int timestamp
	 */
	function get_first_occurence_timestamp( $recurrence ) {
		if ( array_key_exists( $recurrence, self::$valid_reccurence_patterns ) ) {
			return intval( time() + self::$valid_reccurence_patterns[$recurrence] );
		}
	}

	/**
	 * add a weekly 'schedule' to the WP Cron API
	 *
	 * @since 1.0
	 * @author jkudish
	 * @param array $schedules the existing schedules in the WP Cron API
	 * @return array the schedules in the WP Cron API
	 */
	function cron_add_weekly( $schedules ) {
		if ( !isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 604800,
				'display' => __( 'Once Weekly', 'tribe-fb-import' ),
			);
		}
		return $schedules;
	}

	/**
	 * Add the facebook importer toolbar item.
	 *
	 * @since 1.0
	 * @author PaulHughes01
	 * @return void
	 */
	public function addFacebookToolbarItems() {
		global $wp_admin_bar;

		if ( current_user_can( 'publish_tribe_events' ) ) {
			$import_node = $wp_admin_bar->get_node( 'tribe-events-import' );
			if ( !is_object( $import_node ) ) {
				$wp_admin_bar->add_menu( array(
					'id' => 'tribe-events-import',
					'title' => __( 'Import', 'tribe-fb-import' ),
					'parent' => 'tribe-events-import-group'
				) );
			}
		}

		if ( current_user_can( 'publish_tribe_events' ) ) {
			$wp_admin_bar->add_menu( array(
				'id' => 'tribe-facebook-import',
				'title' => __( 'Facebook', 'tribe-fb-import' ),
				'href' => trailingslashit( get_admin_url() ) . 'edit.php?post_type=' . TribeEvents::POSTTYPE . '&page=import-fb-events',
				'parent' => 'tribe-events-import'
			) );
		}

		if ( current_user_can( 'manage_options' ) ) {
			$wp_admin_bar->add_menu( array(
				'id' => 'tribe-facebook-importer-settings-sub',
				'title' => __( 'Facebook Events', 'tribe-fb-import' ),
				'href' => trailingslashit( get_admin_url() ) . 'edit.php?post_type=' . TribeEvents::POSTTYPE . '&page=tribe-events-calendar&tab=fb-import',
				'parent' => 'tribe-events-settings'
			) );
		}
	}

	/**
	 * Return additional action for the plugin on the plugins page.
	 * @param array $actions
	 * @since 2.0.8
	 * @return array
	 */
	public function addLinksToPluginActions( $actions ) {
		if( class_exists( 'TribeEvents' ) ) {
			$actions['settings'] = '<a href="' . add_query_arg( array( 'post_type' => TribeEvents::POSTTYPE, 'page' => 'tribe-events-calendar', 'tab' => 'fb-import' ), admin_url( 'edit.php' ) ) .'">' . __('Settings', 'tribe-fb-import') . '</a>';
		}
		return $actions;
	}

	/**
	 * Sets deleted events that were imported from Facebook so that it does not re-import them.
	 * @param int $post_id
	 *
	 * @since 1.0.2
	 * @author PaulHughes01
	 * @return void
	 */
	public function setDeletedEventArrayOption( $post_id ) {
		if ( get_post_type( $post_id ) == TribeEvents::POSTTYPE && get_post_meta( $post_id, '_EventOrigin', true ) == self::$origin && get_post_meta( $post_id, '_FacebookID', true ) != '' ) {
			$facebook_event_id = get_post_meta( $post_id, '_FacebookID', true );
			$deleted_ids = get_option( 'tribe_facebook_deleted_ids', array() );
			if ( !in_array( $facebook_event_id, $deleted_ids ) ) {
				$deleted_ids[] = $facebook_event_id;
				update_option( 'tribe_facebook_deleted_ids', $deleted_ids );
			}
		}
	}

	/**
	 * Return the forums link as it should appear in the help tab.
	 * @param string $content
	 *
	 * @return string
	 * @since 1.0.2
	 */
	public function helpTabForumsLink( $content ) {
		$promo_suffix = '?utm_source=helptab&utm_medium=plugin-facebook&utm_campaign=in-app';
		return TribeEvents::$tribeUrl . 'support/forums/' . $promo_suffix;
	}

	/**
	 * display a failure message when TEC is not installed
	 *
	 * @since 1.0
	 * @author jkudish
	 * @return void
	 */
	static function fail_message() {
		if ( current_user_can( 'activate_plugins' ) ) {
			$url = add_query_arg( array( 'tab' => 'plugin-information', 'plugin' => 'the-events-calendar', 'TB_iframe' => 'true' ), admin_url( 'plugin-install.php' ) );
			$title = __( 'The Events Calendar', 'tribe-fb-import' );
			echo '<div class="error"><p>' . sprintf( __( 'To begin using The Events Calendar: Facebook Events, please install the latest version of %s.', 'tribe-fb-import' ), '<a href="' . $url . '" class="thickbox" title="' . $title . '">' . $title . '</a>', $title ) . '</p></div>';
		}
	}

	/**
	 * Add FB Importer to the list of add-ons to check required version.
	 *
	 * @param array $plugins the existing plugins
	 *
	 * @return mixed
	 * @author jkudish
	 * @since 1.0
	 */
	static function init_addon( $plugins ) {
		$plugins['TribeFBImporter'] = array( 'plugin_name' => 'The Events Calendar: Facebook Events', 'required_version' => Tribe_FB_Importer::REQUIRED_TEC_VERSION, 'current_version' => Tribe_FB_Importer::VERSION, 'plugin_dir_file' => basename( dirname( __FILE__ ) ) . '/the-events-calendar-facebook-importer.php' );
		return $plugins;
	}

	/**
	 * static singleton method
	 */
	static function instance() {
		if ( !isset( self::$instance ) ) {
			$className = __CLASS__;
			self::$instance = new $className;
		}
		return self::$instance;
	}


}

/**
 * init the Facebook importer on plugins_loaded
 * ensures that TEC i s loaded first
 *
 * @since 1.0
 * @author jkudish
 * @return void
 */
add_action( 'plugins_loaded', 'tribe_init_facebook_importer', 99 );
function tribe_init_facebook_importer() {
	add_filter( 'tribe_tec_addons', array( 'Tribe_FB_Importer', 'init_addon' ) );
	if ( class_exists( 'TribeEvents' ) && defined( 'TribeEvents::VERSION' ) && version_compare( TribeEvents::VERSION, Tribe_FB_Importer::REQUIRED_TEC_VERSION, '>=' ) ) {
		Tribe_FB_Importer::instance();
	}
	if ( !class_exists( 'TribeEvents' ) ) {
		add_action( 'admin_notices', array( 'Tribe_FB_Importer', 'fail_message' ) );
	}
}

/**
 * clear WP Cron on plugin deactivation
 *
 * @since 1.0
 * @author jkudish
 * @return void
 */
register_deactivation_hook( __FILE__, 'tribe_facebook_clear_schedule' );
function tribe_facebook_clear_schedule() {
	wp_clear_scheduled_hook( 'tribe_fb_auto_import' );
}

require_once( 'lib/tribe-events-facebook-importer-pue.class.php' );
new TribeEventsFacebookImporterPUE( __FILE__ );
