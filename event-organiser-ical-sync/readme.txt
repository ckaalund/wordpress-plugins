=== Event Organiser ICAL Sync ===
Contributors: stephenharris
Donate link: http://www.wp-event-organiser.com/donate
Requires at least: 3.3
Tested up to: 3.9.2
Stable tag: 1.4.1
License: GPLv3

Automatically import ICAL feeds from other sites / Google Calendar

== Description ==

Adds feed management that allows you to automatically import ICAL feeds from other sites / Google Calendar.

Feeds can, optionally, be assigned to a specific category and/or status (pending, draft,publish). Alternatively these can be determined by the feed itself.

**Requires Event Organiser 2.2+**

== Installation ==

Installation is standard and straight forward. 

1. Upload `event-organiser-ical-sync` folder (and all it's contents!) to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to Settings > Event Organiser > Import/Export (tab)

== Frequently Asked Questions ==

= If an event is removed from a source feed, is it deleted on my site? =

Yes. Next time the feed is fetched events which were previously added by this feed but no longer 
present in the feed are trashed. Not deleted. (New events found are added, and existing events found in the feed 
are updated.

For this reason you *shouldn't edit a feed to change the source url* as when this new url is fetched events added
by the original will be trashed. Instead create a new feed, and delete the original if desired.  


= If I edit an imported event are my changes lost when the feed is refetched? =

Yes. This is not a two way sync, so changes made on your site do not propagate to the source feed. 
When the source is refetched the event is updated according to the source feed.


= How can I fetch my feeds according to a different schedule? =

Feeds are fetched via  WP-Cron. Details on how to add custom schedules can be found here:
http://codex.wordpress.org/Plugin_API/Filter_Reference/cron_schedules

As a simple example, the following adds a 'Once weekly' and 'Every 10 minutes' schedules. The following
can be added to a site utility plug-in or (though not recommended) functions.php.


        function customprefix_add_custom_schedules( $schedules ) {
     
             // add a 'weekly' schedule to the existing set
             $schedules['weekly'] = array(
                  'interval' => 7 * 24 * 60 * 60, //1 week in seconds
                  'display' => 'Once Weekly'
             );
          
             // add a 'tenminutes' schedule to the existing set
             $schedules['tenminutes'] = array(
                  'interval' => 5 * 60, //10 minutes in seconds
                  'display' => 'Every 10 minutes'
             );
             return $schedules;
        }
        add_filter( 'cron_schedules', 'customprefix_add_custom_schedules' );


= Why aren't my feeds being fetched when it says it will? =

Feeds are fetched via WP-Cron which is a "pseudo cron". Cron executes routines at specific times, but is not
available in all hosting environments. WP-Cron is, but cannot gurantee a specific time. Due to the way it works,
the more traffic your site recieves (including bots) will improve the accuracy of WP-Cron.  

== Screenshots ==

== Changelog ==

= 1.4.1 =

* If expired events are set to be automatically deleted, don't add expired events from an iCal feed.

= 1.4 =

* Added `eventorganiser_ical_sync_event_inserted` filter
* Added `eventorganiser_ical_sync_event_updated` filter
* Added support for importing meta data
* Added `eventorganiser_ical_sync_meta_key_map` filter
* Support webcal and feed protocal protocols

= 1.3 =

* Added support for multsites.
* Fixes bug with importing venues with "&"in them.
* Use esc_url_raw for sanitizing source url not esc_url.
* Lint javascript
* Add pot file.

= 1.2.1 =

* Fixed non-php 5.2 code in 1.2


= 1.2 =

* Improved error/warning feedback
* Improved timezone detection


= 1.1 =

* Adds option to assign imported feeds a category
* Adds option to assign imported feeds a status
* Toggle advanced options visibility

== Upgrade Notice ==

= 1.2 =
It's recommended, but not necessary, to upgrade Event Organiser to 2.4 before upgrading the iCal Sync extension to 1.2
