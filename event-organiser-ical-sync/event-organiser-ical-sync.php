<?php
/*
Plugin Name: Event Organiser ICAL Sync
Plugin URI: http://www.wp-event-organiser.com
Version: 1.4.1
Description: Automatically import ICAL feeds from other sites / Google
Author: Stephen Harris
Author URI: http://www.stephenharris.info
*/
/*  Copyright 2013 Stephen Harris (contact@stephenharris.info)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
*/

//Initiates the plug-in
add_action( 'plugins_loaded', array( 'EO_Sync_Ical', 'init' ) );

/**
 * @ignore
 * @author stephen
 */
class EO_Sync_Ical{

	/**
	 * Instance of the class
	 * @static
	 * @access protected
	 * @var object
	 */
	protected static $instance;
	
	static $version = '1.4.1';
	
	/**
	 * Instantiates the class
	 * @return object $instance
	 */
	public static function init() {
		is_null( self :: $instance ) AND self :: $instance = new self;
		return self :: $instance;
	}
	
	/**
	 * Constructor.
	 * @return \Post_Type_Archive_Links
	 */
	public function __construct() {
		
		if( defined( 'EVENT_ORGANISER_DIR' ) ){
			//Load ical functions
			require_once( plugin_dir_path( __FILE__ ) . 'ical-functions.php' );
			
			//Load hooks
			$this->hooks();
		}
			
	}

	function set_constants(){
		$this->hook = 'edit.php?post_type=tribe_events';
		$this->title = __( 'Import: ICAL', 'tribe-events-calendar' );
		$this->menu = __( 'Import: ICAL', 'tribe-events-calendar' );
		$this->permissions = 'manage_options';
		$this->slug = 'ical-import';
	}

	function add_page(){		
		add_submenu_page($this->hook,$this->title, $this->menu, $this->permissions,$this->slug,  array($this,'display_feeds'));
		// add_action('load-' . $this->page,  array($this,'page_actions'),9);
		// add_action('admin_print_scripts-' . $this->page,  array($this,'page_styles'),10);
		// add_action('admin_print_styles-' . $this->page,  array($this,'page_scripts'),10);
		// add_action("admin_footer-" . $this->page, array($this,'footer_scripts') );
	}

	function hooks(){

		add_action( 'after_setup_theme', array( $this, 'setup_constants' ) );
		
		add_action( 'init', array( $this, 'register_feed_posttype' ) );
		add_action('init', array($this,'set_constants'));
	
		// add_action( 'eventorganiser_event_settings_imexport', array( $this, 'display_feeds' ), 5 );
		add_action('admin_menu', array($this,'add_page'));
	
		add_action( 'wp_ajax_add-eo-feed', array( $this, 'ajax_add_feed' ) );
		add_action( 'wp_ajax_delete-eo-feed', array( $this, 'ajax_delete_feed' ) );
		add_action( 'wp_ajax_fetch-eo-feed', array( $this, 'ajax_fetch_feed' ) );
		
		add_action( 'load-settings_page_event-settings', array( $this, 'update_feed_settings' ) );
		
	}
	
	function register_feed_posttype(){
		
		$labels = array(
			'name'                => _x( 'Feeds', 'Post Type General Name', 'eventorganiserical' ),
			'singular_name'       => _x( 'Feed', 'Post Type Singular Name', 'eventorganiserical' ),
			'view_item'           => __( 'View feeds', 'eventorganiserical' ),
			'add_new_item'        => __( 'Add New Feed', 'eventorganiserical' ),
			'add_new'             => __( 'Add Feed', 'eventorganiserical' ),
			'edit_item'           => __( 'Edit Feed', 'eventorganiserical' ),
			'update_item'         => __( 'Update Feed', 'eventorganiserical' ),
		);

		$args = array(
			'description'         => __( 'ICAL Feed', 'eventorganiserical' ),
			'labels'              => $labels,
			'supports'            => array( 'title' ),
			'hierarchical'        => false,
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'can_export'          => true,
			'has_archive'         => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
		);

		register_post_type( 'eo_icalfeed', $args );
		
	}
	
	function setup_constants(){
		define( 'EVENT_ORGANISER_ICAL_SYNC_URL', plugin_dir_url(__FILE__ ) );
	}
	
	
	function display_feeds(){
		 
		$ext = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_script( 'eo-sync-ical', EVENT_ORGANISER_ICAL_SYNC_URL . "js/eo-sync-ical{$ext}.js", array( 'jquery', 'wp-lists' ), self::$version );
		?>
		<style>
			#feed-list #last-updated{ width: 20% }
			#feed-list #feed-events{ width: 10% }
			#feed-list td .eo-feed-error p {color: #c00;margin:2px;}
			#feed-list .inline-edit-row fieldset label span.title { width: 9em }
			#feed-list .inline-edit-row fieldset label span.input-text-wrap { margin-left: 9em; }
			#feed-list tr.feed-row td{ border-bottom: none; }
			#feed-list tr.feed-errors td{ border-top: none; }
			#feed-list tr.feed-row .row-actions{ padding: 0px; line-height:10px; }
			#feed-list tr.feed-row .row-actions span{ padding: 0px; margin:0px; }
			#feed-list tr .feed-alert{ margin: 5px;padding: 3px 5px; border: 1px solid;-webkit-border-radius: 3px;border-radius: 3px; }
			#feed-list tr .feed-warning{ background-color: #FFFBE4;border-color: #DFDFDF; }
			#feed-list tr .feed-error{ background-color: #ffebe8;border-color: #c00; }

		</style>
		
		<h3><?php esc_html_e( 'ICAL Feeds', 'eventorganiserical' ); ?></h3>
	
		<?php  $feeds = eo_get_feeds(); ?>		
		<div id="col-container">
			<div id="col-right">
					
			<table class="wp-list-table widefat fixed tags" id="feed-list" cellspacing="0" data-wp-lists="list:eo-feed">
				<thead>
					<tr>
						<th scope="col" id="name" class="" style="">
							<?php esc_html_e( 'Name', 'eventorganiserical' ); ?>
						</th>
						
						<th scope="col" id="url" class="" style="">
							<?php esc_html_e( 'Source', 'eventorganiserical' ); ?>
						</th>
						
						<th scope="col" id="last-updated" class="" style="">
							<?php esc_html_e( 'Last Fetched', 'eventorganiserical' ); ?>
						</th>
						<th scope="col" id="feed-events" class="" style="">
							<?php esc_html_e( 'Events', 'eventorganiser' ); ?>
						</th>
					</tr>
				</thead>
							
				<?php  
					if( $feeds ):
						foreach( $feeds as $feed ):
							$class = ( empty( $class ) ? 'class="alternate"' : ''  );
							$this->display_feed_row( $feed );
						endforeach; 
					endif; ?>
					<tr id="eo-feed-no-feeds" <?php if( $feeds ) echo 'style="display:none"';?>>
						<td colspan="4"> <?php esc_html_e( 'No feeds', 'eventorganiserical' ); ?></td>
					</tr>
				
			</table>
			<div class="form-wrap">	
				<form id="eo-feed-settings" method="post" class="validate">
					<input type="hidden" name="action" value="eventorganier-update-feed-settings" />

					<div class="form-required">
						<label for="sync-schedule"> 
						<?php 
							echo esc_html__( 'Sync schedule:', 'eventorganiserical') . ' '; 
							eventorganiser_select_field( array(
								'id' => 'sync-schedule',
								'options' => eo_get_feed_sync_schedules(),
								'selected' => get_option( 'eventorganiser_feed_schedule' ),
								'name' => 'eventorganiser_feed_schedule',								
							));
							
							wp_nonce_field( 'eventorganier-update-feed-settings' );
						
							submit_button( 
								__( 'Update feed settings', 'eventorganiserical' ), 'secondary', 'submit', false, 
								array(
									'id' => 'feed-settings-submit',
								));
						?>
						</label>
						<?php 
						if( $timestamp = wp_next_scheduled( 'eventorganiser_ical_feed_sync' ) ){
							$timestamp = (int) $timestamp;
							$date_obj = new DateTime( '@'.$timestamp );
							$date_obj->setTimezone( eo_get_blog_timezone() );
							printf(
								esc_html__( 'Next feed sync is scheduled for %s', 'eventorganiserical'),
								eo_format_datetime( $date_obj, get_option( 'date_format' ) . ' ' .  get_option( 'time_format' ) )
							);
						}
						?>
					</div>				
				</form>
			</div>
									
			</div>
			
			<div id="col-left">
				<div class="col-wrap">
				
					<div class="form-wrap">
						<h4> <?php esc_html_e( 'Add New Feed', 'eventorganiserical'); ?></h4>
						
						<form id="add-eo-feed" method="post" class="validate">

							<div class="form-field form-required">
								<label for="feed-name"> <?php esc_html_e( 'Name', 'eventorganiserical'); ?> </label>
								<input name="feed-name" id="feed-name" type="text" value="" size="40" aria-required="true">
							</div>

							<div class="form-field form-required">
								<label for="feed-source">Source</label>
								<input name="feed-source" id="feed-source" type="text" value="" size="40" aria-required="true">
							</div>

							<p class="hide-if-no-js">
								<a href="#" class="eo-advanced-feed-options-toggle eo-show-advanced-option">
									<?php _e( 'Show advanced options', 'eventorganiserical' ); ?>
								</a>
								<a href="#" class="eo-advanced-feed-options-toggle eo-hide-advanced-option hide-if-js">
									<?php _e( 'Hide advanced options', 'eventorganiserical' ); ?>
								</a>
							</p>
							
							<div id="eo-advanced-feed-options-wrap" class="hide-if-js">
								<div class="form-field">
									<label for="feed-organiser"><?php _e( 'Assign events to', 'eventorganiserical' ); ?></label>
									<?php wp_dropdown_users( array(
										'id'=> 'feed-organiser',
										'name' => 'feed-organiser',
										'selected' => get_current_user_id(),
									)); ?>
								</div>
							
								<div class="form-field">
									<label for="feed-category"><?php _e( 'Assign events to category', 'eventorganiserical' ); ?></label>
									<?php wp_dropdown_categories( array(
											'show_option_none' => __( 'Use category specified in feed', 'eventorganiserical' ),
											'orderby' => 'name', 
											'hide_empty' => 0, 
											'hierarchical' => 1,
											'name' => 'feed-category',
											'id' => 'feed-category',
											'taxonomy' => 'event-category',
									)); ?>
								</div>
								<div class="form-field">
									<label for="feed-status"><?php _e( 'Event status', 'eventorganiserical' ); ?></label>
									<?php 
									eventorganiser_select_field(array(
										'name' => 'feed-status',
										'id' => 'feed-status',
										'options' => array_merge( 
														array( '0' => __( 'Use status specified in feed', 'eventorganiserical' ) ), 
														get_post_statuses() 
													),
									)); ?>
								</div>
							</div>
							
							<?php 
								$nonce = wp_create_nonce( 'add-eo-feed-0' );
								submit_button( 
									__( 'Add new feed', 'eventorganiserical' ), 'primary', 'submit', true, 
									array(
										'data-wp-lists' => 'add:feed-list:add-eo-feed::_ajax_nonce=' . $nonce,
										'id' => 'add-eo-feed-submit',
									));
							?>
						</form>
					</div>
				</div>
			</div>
			
		</div>
	<?php 
	}
	
	function display_feed_row( $feed ){
		$del_nonce = wp_create_nonce( 'delete-eo-feed-' . $feed->ID );
		$upd_nonce = wp_create_nonce( 'add-eo-feed-' . $feed->ID );
		$fetch_nonce = wp_create_nonce( 'fetch-eo-feed-' . $feed->ID );
		$source = get_post_meta( $feed->ID, '_eventorganiser_feed_source', true );
		$error = maybe_unserialize( get_post_meta( $feed->ID, '_eventorganiser_feed_log', true ) );
		$warnings = get_post_meta( $feed->ID, '_eventorganiser_feed_warnings' );
		
		$user_id = get_post_meta( $feed->ID, '_eventorganiser_feed_organiser', true );
		$status = get_post_meta( $feed->ID, '_eventorganiser_feed_status', true );
		$category = get_post_meta( $feed->ID, '_eventorganiser_feed_category', true );
		
		?>
		<tbody id="eo-feed-<?php echo $feed->ID;?>" <?php //echo $class; ?>>
				
		<tr class="feed-row">		
			<td class="name">
				<strong> <?php echo esc_html( $feed->post_title ); ?></strong>
								
				<div class="row-actions">
					<span class="edit"><a href="#">Edit Feed </a> |</span>
					<span class="delete">
						<a class="delete-feed" data-wp-lists="delete:feed-list:eo-feed-<?php echo $feed->ID;?>::_ajax_nonce=<?php echo $del_nonce; ?>" href="#">
							<?php esc_html_e( 'Delete', 'eventorganiserical' ); ?>
						</a> 
					| </span>
					<span class="fetch"> 
						<a class="fetch-feed" data-wp-lists="dim:feed-list:eo-feed-<?php echo $feed->ID;?>:dimclass:::action=fetch-eo-feed&_ajax_nonce=<?php echo $fetch_nonce; ?>" href="#">
							<?php esc_html_e( 'Fetch now', 'eventorganiserical' ); ?>
						</a>
					</span>
					<span class="spinner" style="float: none;display: inline-block;visibility: hidden;"></span>
				</div>

			</td>
							
			<td class="source">
				<?php echo esc_html( $source ); ?> 
			</td>
						
			<td class="last-updated">
			<?php 
				$m_time = $feed->post_modified;
				$time = mysql2date( 'U', $m_time );
				$time_diff = time() - $time;

				if ( $time_diff > 0 && $time_diff < 60 * 60 )
					echo sprintf( __( '%s ago' ), human_time_diff( $time ) );
				else
					echo mysql2date( get_option( 'date_format' )  . '\<\b\r\/\> ' . get_option( 'time_format' ), $m_time );
			?> 
			</td>
			
			<td class="feed-events">
				<?php if( $events= get_post_meta( $feed->ID, '_eventorganiser_feed_events_parsed', true ) ) echo (int) $events; ?>
			</td>
								
			<td class="edit-column" style="display:none" colspan="3">

				<fieldset>
					<div class="inline-edit-col">
						<h4>Quick Edit</h4>

						<label>
							<span class="title">Name</span>
							<span class="input-text-wrap">
								<input type="text" name="feed-name" class="ptitle" value="<?php echo esc_attr( $feed->post_title ); ?>">
							</span>
						</label>
						<label>
							<span class="title">Slug</span>
							<span class="input-text-wrap">
								<input type="text" name="feed-source" class="ptitle" value="<?php echo esc_attr( $source ); ?>">
							</span>
						</label>
						<label>
							<span class="title"> <?php _e( 'Assign events to', 'eventorganiserical' ); ?></span>
							<span class="input-text-wrap">
								<?php wp_dropdown_users( array(
										'selected' => $user_id,
										'name' => 'feed-organiser',
								)); ?>
							</span>
						</label>
						
						<label>
							<span class="title"> <?php _e( 'Category', 'eventorganiserical' ); ?></span>
							<span class="input-text-wrap">
								<?php wp_dropdown_categories( array(
										'show_option_none' => __( 'Use category specified in feed', 'eventorganiserical' ),
										'orderby' => 'name', 
										'hide_empty' => 0, 
										'hierarchical' => 1,
										'name' => 'feed-category',
										'id' => 'feed-category-' . $feed->ID,
										'taxonomy' => 'event-category',
										'selected' => $category,
								)); ?>
							</span>
						</label>
						
						<label>
							<span class="title"> <?php _e( 'Event Status', 'eventorganiserical' ); ?></span>
							<span class="input-text-wrap">
								<?php 
								eventorganiser_select_field(array(
									'name' => 'feed-status',
									'id' => 'feed-status',
									'selected' => $status,
									'options' => array_merge( 
													array( '0' => __( 'Use status specified in feed', 'eventorganiserical' ) ), 
													get_post_statuses() 
												),
								)); ?>
							</span>
						</label>
					
					</div>		
					
					<input type="hidden" name="id" value="<?php echo esc_attr( $feed->ID ); ?>">
				</fieldset>
	
				<p class="inline-edit-save submit">
					<a accesskey="c" href="#inline-edit" title="Cancel" class="cancel button-secondary alignleft">Cancel</a>
					<a accesskey="s" id="eo-feed-<?php echo $feed->ID;?>-submit" data-wp-lists="add:feed-list:eo-feed-<?php echo $feed->ID;?>::_ajax_nonce=<?php echo $upd_nonce; ?>" href="#" title="Update Feed" class="save button-primary alignright">
						<?php esc_html_e( 'Update Feed', 'eventorganiserical' ); ?>
					</a>
					<span class="spinner"></span>
					<span class="error" style="display:none;"></span>		
					<br class="clear">
				</p>
			</td>
		</tr>
	
		<tr class="feed-errors">
			<td colspan="4">
			<?php if( $error ): ?>
				<div class="feed-error feed-alert"><?php echo esc_html( $error['log'] );?></div>
			<?php endif; ?>
			<?php if( $warnings ): ?> 
				<div class="feed-warning feed-alert">
					<?php 
						$messages = wp_list_pluck( $warnings, 'log' );
						echo implode( '<br/>', $messages );
					?> 
				</div>
			<?php endif; ?>
			</td>
		</tr>
		</tbody>
		<?php 
	}
	
	function ajax_add_feed(){

		$name = $_POST['feed-name'];
		$source = esc_url_raw( $_POST['feed-source'], array( 'http', 'https', 'webcal', 'feed' ) );
		$organiser = isset( $_POST['feed-organiser'] ) ? (int) $_POST['feed-organiser'] : get_current_user_id();
		$status = isset( $_POST['feed-status'] ) ?  $_POST['feed-status'] : false;
		$category = isset( $_POST['feed-category'] ) ? (int) $_POST['feed-category'] : 0;
	
		$old_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		
		if( !current_user_can( 'manage_options' ) ){
			//Respond
			$x = new WP_Ajax_Response( array(
					'what' => 'eo-feed',
					'id' => new WP_Error( 'failed-update', 'Insufficient permissions' ),
					'old_id' => $old_id,
			));
			$x->send();
			exit();
		}
		
		check_ajax_referer( 'add-eo-feed-' . $old_id );
		
		if( !$old_id ){
			$feed_id = eo_insert_feed( $name, $source );
			update_post_meta( $feed_id, '_eventorganiser_feed_organiser', $organiser );
			update_post_meta( $feed_id, '_eventorganiser_feed_category', $category );
			update_post_meta( $feed_id, '_eventorganiser_feed_status', $status );
		}else{
			$feed_id = eo_update_feed( $old_id, compact( 'name', 'source', 'organiser', 'status', 'category' ) );
		}
		$feed = get_post( $feed_id );
		
		ob_start();
		$this->display_feed_row( $feed );
		$markup = ob_get_contents();
		ob_end_clean();
		
		//Respond
		$x = new WP_Ajax_Response( array(
				'what' => 'eo-feed',
				'id' => $feed_id,
				'old_id' => $old_id,
				'data' =>  $markup,
				'position' => 0,
		));
		$x->send();
		exit();
	}
	
	function ajax_fetch_feed(){

		$feed_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		
		if( !current_user_can( 'manage_options' ) )
			wp_die( -1 );
		
		check_ajax_referer( 'fetch-eo-feed-' . $feed_id );
		
		if( !$feed_id ){
			wp_die( -1 );
		}
		
		if( eo_fetch_feed( $feed_id ) ){
			
			$feed = get_post( $feed_id );
			$m_time = $feed->post_date;
			$timestamp = get_post_time( 'G', true, $feed );
			$time_diff = time() - $timestamp;
			$events = get_post_meta( $feed_id, '_eventorganiser_feed_events_parsed', true );
			if ( $time_diff > 0 && $time_diff < DAY_IN_SECONDS )
				$last_updated = sprintf( __( '%s ago' ), human_time_diff( $time ) );
			else
				$last_updated = mysql2date( get_option( 'date_format' )  . '\<\b\r\/\> ' . get_option( 'time_format' ), $m_time );
			
			ob_start();
			$this->display_feed_row( $feed );
			$markup = ob_get_contents();
			ob_end_clean();
			
			$warnings = get_post_meta( $feed_id, '_eventorganiser_feed_warnings' );
		
			if( !empty( $warnings ) ){
				//$warnings = array_map( 'unserialize', $warnings );
				$warnings = wp_list_pluck( $warnings, 'log' );
				$warnings = implode( '<br/>', $warnings );
			}else{
				$warnings = false;
			}
			
			//wp_die( 1 );
			//Respond
			$x = new WP_Ajax_Response( array(
					'what' => 'eo-feed',
					'id' => $feed_id,
					'old_id' => $feed_id,
					'data' => $markup,
					'supplemental' => compact( 'last_updated', 'timestamp', 'events', 'warnings' ),
			));
			$x->send();
			
		}
		
	
		$error = maybe_unserialize( get_post_meta( $feed_id, '_eventorganiser_feed_log', true ) );
				
		//Respond
		$x = new WP_Ajax_Response( array(
				'what' => 'eo-feed',
				'id' => new WP_Error( 'failed-fetch', $error['log'] ),
				'old_id' => $feed_id,
				'data' => $markup,
				'supplemental' => compact( 'last_updated', 'timestamp', 'events' ),
				
		));
		$x->send();
		exit();
	}

	function ajax_delete_feed(){
		$feed_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if( !$feed_id ){
			wp_die( -1 );
		}
		
		if( !current_user_can( 'manage_options' ) ){
			//Respond
			$x = new WP_Ajax_Response( array(
					'what' => 'eo-feed',
					'id' => new WP_Error( 'failed-delete', 'Insufficient permissions' ),
					'old_id' => $feed_id,
			));
			$x->send();
			exit();		
		}
		
		check_ajax_referer( 'delete-eo-feed-' . $feed_id );
		
		eo_delete_feed( $feed_id );
		wp_die( 1 );
	
	}
	
	function update_feed_settings(){

		if( !empty( $_POST['action'] ) && 'eventorganier-update-feed-settings' == $_POST['action'] ){
			
			if( !current_user_can( 'manage_options' ) )
				return;
			
			check_admin_referer( 'eventorganier-update-feed-settings' );			

			$schedule =  $_POST['eventorganiser_feed_schedule'];
			update_option( 'eventorganiser_feed_schedule', $schedule );
		
			wp_clear_scheduled_hook( 'eventorganiser_ical_feed_sync' );
			
			if( $schedule ){
				$schedules = wp_get_schedules();
				$timestamp = time() + $schedules[$schedule]['interval'];		
				wp_schedule_event( $timestamp, $schedule, 'eventorganiser_ical_feed_sync' );
			}
			
			wp_redirect( admin_url( 'options-general.php?page=event-settings&tab=imexport&settings-updated=true' ) );
			exit();
		}
	
	}
		
}


if( !class_exists('EO_Extension') ){

	abstract class EO_Extension{

		public $slug;

		public $label;

		public $public_url;

		public $api_url = 'http://wp-event-organiser.com';

		public $id;

		public $dependencies = false;

		public function __construct(){
			$this->hooks();
		}

		/**
		 * Returns true if event organsier version is $v or higher
		 */
		static function eo_is_after( $v ){
			$installed_plugins = get_plugins();
			$eo_version = isset( $installed_plugins['event-organiser/event-organiser.php'] )  ? $installed_plugins['event-organiser/event-organiser.php']['Version'] : false;
			return ( $eo_version && ( version_compare( $eo_version, $v  ) >= 0 )  );
		}


		/**
		 * Get's current version of installed plug-in.
		 */
		public function get_current_version(){
			$plugins = get_plugins();

			if( !isset( $plugins[$this->slug] ) )
				return false;

			$plugin_data = $plugins[$this->slug];
			return $plugin_data['Version'];
		}


		/* Check that the minimum required dependency is loaded */
		public function check_dependencies() {

			$installed_plugins = get_plugins();

			if( empty( $this->dependencies ) ){
				return;
			}

			foreach ( $this->dependencies as $dep_slug => $dep ) {

				if ( !isset( $installed_plugins[$dep_slug] ) ) {
					$this->not_installed[] = $dep_slug;

				}elseif ( -1 == version_compare( $installed_plugins[$dep_slug]['Version'], $dep['version'] )  ) {
					$this->outdated[] = $dep_slug;

				}elseif ( !is_plugin_active( $dep_slug ) ) {
					$this->not_activated[] = $dep_slug;
				}
			}

			/* If dependency does not exist - uninstall. If the version is incorrect, we'll try to cope */
			if ( !empty( $this->not_installed ) ) {
				deactivate_plugins( $this->slug );
			}

			if ( !empty( $this->not_installed )  || !empty( $this->outdated )  || !empty( $this->not_activated ) ) {
				add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			}
		}


		public function admin_notices() {

			$installed_plugins = get_plugins();

			echo '<div class="updated">';

			//Display warnings for uninstalled dependencies
			if ( !empty( $this->not_installed )  ) {
				foreach (  $this->not_installed as $dep_slug ) {
					printf(
						'<p> <strong>%1$s</strong> has been deactivated as it requires %2$s (version %3$s or higher). Please <a href="%4$s"> install %2$s</a>.</p>',
						$this->label,
						$this->dependencies[$dep_slug]['name'],
						$this->dependencies[$dep_slug]['version'],
						$this->dependencies[$dep_slug]['url']
					);
				}
			}

			//Display warnings for outdated dependencides.
			if ( !empty( $this->outdated ) && 'update-core' != get_current_screen()->id ) {
				foreach (  $this->outdated as $dep_slug ) {
					printf(
						'<p><strong>%1$s</strong> requires version %2$s <strong>%3$s</strong> or higher to function correctly. Please update <strong>%2$s</strong>.</p>',
						$this->label,
						$this->dependencies[$dep_slug]['name'],
						$this->dependencies[$dep_slug]['version']
					);
				}
			}

			//Display notice for activated dependencides
			if ( !empty(  $this->not_activated )  ) {
				foreach (  $this->not_activated as $dep_slug ) {
					printf(
						'<p><strong>%1$s</strong> requires %2$s to function correctly. Click to <a href="%3$s" >activate <strong>%2$s</strong></a>.</p>',
						$this->label,
						$this->dependencies[$dep_slug]['name'],
						wp_nonce_url( 'plugins.php?action=activate&amp;plugin=' . $dep_slug, 'activate-plugin_' . $dep_slug )
					);
				}
			}

			echo '</div>';
		}


		public function hooks(){

			add_action( 'admin_init', array( $this, 'check_dependencies' ) );

			add_action( 'in_plugin_update_message-' . $this->slug, array( $this, 'plugin_update_message' ), 10, 2 );

				
			if( is_multisite() ){
				//add_action( 'network_admin_menu', array( 'EO_Extension', 'setup_ntw_settings' ) );
				add_action( 'network_admin_menu', array( $this, 'add_multisite_field' ) );
				add_action( 'wpmu_options', array( 'EO_Extension', 'do_ntw_settings' ) );
				add_action( 'update_wpmu_options', array( 'EO_Extension', 'save_ntw_settings' ) );
			}else{
				add_action( 'eventorganiser_register_tab_general', array( $this, 'add_field' ) );
			}

			add_filter( 'pre_set_site_transient_update_plugins', array($this,'check_update'));

			add_filter( 'plugins_api', array( $this, 'plugin_info' ), 9999, 3 );
		}


		public function is_valid( $key ){

			$key = strtoupper( str_replace( '-', '', $key ) );

			$local_key = get_site_option($this->id.'_plm_local_key');

			//Token depends on key being checked to instantly invalidate the local period when key is changed.
			$token = wp_hash($key.'|'.$_SERVER['SERVER_NAME'].'|'.$_SERVER['SERVER_ADDR'].'|'.$this->slug);

			if( $local_key ){
				$response = maybe_unserialize( $local_key['response'] );
				$this->key_data = $response;

				if( $token == $response['token'] ){

					$last_checked = isset($response['date_checked']) ?  intval($response['date_checked'] ) : 0;
					$expires = $last_checked + 24 * 24 * 60 * 60;

					if( $response['valid'] == 'TRUE' &&  ( time() < $expires ) ){
						//Local key is still valid
						return true;
					}
				}
			}

			//Check license format
			if( empty( $key ) )
				return new WP_Error( 'no-key-given' );

			if( preg_match('/[^A-Z234567]/i', $key) )
				return new WP_Error( 'invalid-license-format' );

			if( $is_valid = get_transient( $this->id . '_check' ) && false !== get_transient( $this->id . '_check_lock' ) ){
				if( $token === $is_valid )
					return true;
			}

			//Check license remotely
			$resp = wp_remote_post($this->api_url, array(
					'method' => 'POST',
					'timeout' => 45,
					'body' => array(
							'plm-action' => 'check_license',
							'license' => $key,
							'product' => $this->slug,
							'domain' => $_SERVER['SERVER_NAME'],
							'token' => $token,
					),
			));

			$body = (array) json_decode( wp_remote_retrieve_body( $resp ) );

			if( !$body || !isset($body['response']) ){
				//No response or error
				$grace =  $last_checked + 1 * 24 * 60 * 60;

				if(  time() < $grace )
					return true;

				return new WP_Error( 'invalid-response' );
			}

			$response =  maybe_unserialize( $body['response'] );
			$this->key_data = $response;
	
			update_option( $this->id . '_plm_local_key', $body );

			if( $token != $response['token'] )
				return new WP_Error( 'invalid-token' );

			if( $response['valid'] == 'TRUE' )
				$is_valid = true;
			else
				$is_valid = new WP_Error( $response['reason'] );

			set_transient( $this->id . '_check_lock', $key, 15*20 );
			set_transient( $this->id . '_check', $token, 15*20 );

			return $is_valid;
		}


		public function plugin_update_message( $plugin_data, $r  ){

			if( is_wp_error( $this->is_valid( get_site_option( $this->id.'_license' ) ) ) ){
				printf(
				'<br> The license key you have entered is invalid.
				<a href="%s"> Purchase a license key </a> or enter a valid license key <a href="%s">here</a>',
				$this->public_url,
				admin_url('options-general.php?page=event-settings')
				);
			}
		}

		public function add_multisite_field(){
				
			register_setting( 'settings-network', $this->id.'_license' );

			add_settings_section( 'eo-ntw-settings', "Event Organiser Extension Licenses", '__return_false', 'settings-network' );
				
			add_settings_field(
				$this->id.'_license',
				$this->label,
				array( $this, 'field_callback'),
				'settings-network',
				'eo-ntw-settings'
			);
		}

		static function do_ntw_settings(){
			wp_nonce_field("eo-ntw-settings-options", '_eontwnonce');			
			do_settings_sections( 'settings-network' );
		}

		static function save_ntw_settings(){
			
			if( !current_user_can( 'manage_network_options' ) ){
				return false;
			}
	
			if( !isset( $_POST['_eontwnonce'] ) || !wp_verify_nonce( $_POST['_eontwnonce'], 'eo-ntw-settings-options' ) ){
				return false;
			}

			$whitelist_options = apply_filters( 'whitelist_options', array());
			if( isset( $whitelist_options['settings-network'] ) ){
				foreach( $whitelist_options['settings-network'] as $option_name ){
					if ( ! isset($_POST[$option_name]) )
						continue;
					$value = wp_unslash( $_POST[$option_name] );
					update_site_option( $option_name, $value );
				}
			}

		}

		public function add_field(){

			register_setting( 'eventorganiser_general', $this->id.'_license' );

			if( self::eo_is_after( '2.3' ) ){
				$section_id = 'general_licence';
			}else{
				$section_id = 'general';
			}

			add_settings_field(
				$this->id.'_license',
				$this->label,
				array( $this, 'field_callback'),
				'eventorganiser_general',
				$section_id
			);
		}


		public function field_callback(){
			
			if( !defined( 'EVENT_ORGANISER_DIR' ) ){
				return;
			} 

			$key = get_site_option( $this->id.'_license'  );
			$check = $this->is_valid( $key );
			$valid = !is_wp_error( $check );
	
			$message = false;

			if( !$valid ){
				$message =  sprintf(
					'The license key you have entered is invalid. <a href="%s">Purchase a license key</a>.',
					$this->public_url
				);
						
				$message .= eventorganiser_inline_help(
						sprintf( 'Invalid license key (%s)', $check->get_error_code() ),
						sprintf( 
								'<p>%s</p><p> Without a valid license key you will not be eligable for updates or support. You can purchase a
					license key <a href="%s">here</a>.</p> <p> If you have entered a valid license which does not seem to work, please
					<a href="%s">contact suppport</a>.',
								$this->_get_verbose_reason( $check->get_error_code() ),
								$this->public_url,
								'http://wp-event-organiser.com/contact/'
						)
				);
			}elseif( isset( $this->key_data) && !empty( $this->key_data['expires'] ) ){
				
				$now     = new DateTime( 'now' );
				$expires = new DateTime( $this->key_data['expires'] );
				 
				$time_diff = abs( $expires->format('U') - $now->format('U') );
				$days     = floor( $time_diff/86400 );

				if( $days <= 21 ){
				
					$message =  sprintf( 
						'This key expires on %s. <a href="%s">Renew within the next %d days</a> for a 50%% discount',
						$expires->format( get_option( 'date_format' ) ),
						'http://wp-event-organiser.com/my-account',
						$days
					);
					
				}
			}
	
			eventorganiser_text_field( array(
				'label_for' => $this->id.'_license',
				'value' => $key,
				'name' => $this->id.'_license',
				'style' => $valid ? 'background:#D7FFD7' : 'background:#FFEBE8',
				'class' => 'regular-text',
				'help' => $message
			) );
	
		}
	
	
		private function _get_verbose_reason( $code ){
			
			$reasons = array(
				'no-key-given'           => 'No key has been provided.',
				'invalid-license-format' => 'The entered key is incorrect.',
				'invalid-response'       => 'There was an error in authenticating the license key status.',
				'invalid-token'          => 'There was an error in authenticating the license key status.',
				'key-not-found'          => 'No key has been provided.',
				'license-not-found'      => 'The provided license could not be found.',
				'license-suspended'      => 'The license key is no longer valid.',
				'incorrect-product'      => 'The key is not valid for this product.',
				'license-expired'        => 'Your license key has expired.',
				'site-limit-reached'     => 'The key has met the site limit.',
				'unknown'                => 'An unknown error has occurred'
			);
			
			if( isset( $reasons[$code] ) ){
				return $reasons[$code];
			}else{
				return $code;	
			}
		}
		
		public function plugin_info( $check, $action, $args ){
	
			if ( $args->slug == $this->slug ) {
				$obj = $this->get_remote_plugin_info('plugin_info');
				return $obj;
			}
			return $check;
		}

		/**
		 * Fired just before setting the update_plugins site transient. Remotely checks if a new version is available
		 */
		public function check_update($transient){

			/**
			 * wp_update_plugin() triggers this callback twice by saving the transient twice
			 * The repsonse is kept in a transient - so there isn't much of it a hit.
			 */

			//Get remote information
			$plugin_info = $this->get_remote_plugin_info('plugin_info');

			// If a newer version is available, add the update
			if ( $plugin_info && version_compare($this->get_current_version(), $plugin_info->new_version, '<' ) ){

				$obj = new stdClass();
				$obj->slug = $this->slug;
				$obj->new_version = $plugin_info->new_version;
				$obj->package =$plugin_info->download_link;

				if( isset( $plugin_info->sections['upgrade_notice'] ) ){
					$obj->upgrade_notice = $plugin_info->sections['upgrade_notice'];
				}

				//Add plugin to transient.
				$transient->response[$this->slug] = $obj;
			}

			return $transient;
		}


		/**
		 * Return remote data
		 * Store in transient for 12 hours for performance
		 *
		 * @param (string) $action -'info', 'version' or 'license'
		 * @return mixed $remote_version
		 */
		public function get_remote_plugin_info($action='plugin_info'){

			$key = wp_hash( 'plm_'.$this->id . '_' . $action . '_' . $this->slug );
			if( false !== ( $plugin_obj = get_site_transient( $key ) ) && !$this->force_request() ){
				return $plugin_obj;
			}

			$request = wp_remote_post( $this->api_url, array(
					'method' => 'POST',
					'timeout' => 45,
					'body' => array(
							'plm-action' => $action,
							'license'    => get_site_option( $this->id.'_license' ),
							'product'    => $this->slug,
							'domain'     => $_SERVER['SERVER_NAME'],
					)
			));

			if ( !is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) === 200 ) {
				//If its the plug-in object, unserialize and store for 12 hours.
				$plugin_obj = ( 'plugin_info' == $action ? unserialize( $request['body'] ) : $request['body'] );
				set_site_transient( $key, $plugin_obj, 12*60*60 );
				return $plugin_obj;
			}
			//Don't try again for 5 minutes
			set_site_transient( $key, '', 5*60 );
			return false;
		}


		public function force_request(){

			//We don't use get_current_screen() because of conclict with InfiniteWP
			global $current_screen;

			if ( ! isset( $current_screen ) )
				return false;

			return isset( $current_screen->id ) && ( 'plugins' == $current_screen->id || 'update-core' == $current_screen->id );
		}

	}
}

class EO_Extension_Ical extends EO_Extension{

	public $slug 		= 'event-organiser-ical-sync/event-organiser-ical-sync.php';
	public $public_url 	= 'http://wp-event-organiser.com/downloads/event-organiser-ical-sync/';
	public $label 		= 'iCal Sync';
	public $id 			= 'eventorganiser_ical_sync';

	public $dependencies = array(
			'event-organiser/event-organiser.php' => array(
					'name' 			=> 'Event Organiser',
					'version' 		=> '2.1.6',
					'install_slug' 	=> 'event-organiser',
					'url'			=>	'http://wordpress.org/plugins/event-organiser'
			),
	);
}

$ical = new EO_Extension_Ical();
?>