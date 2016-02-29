# Events Laundry

Works with The Events Calendar 4.1, allows events to be "laundered". Example:

* Laundry is enabled for an event and set to `same day next week`
* A daily scheduled event looks for expired events
* Once the above event expires, it's date will be moved to today - whatever today is - plus one week
* Times are not touched

This is useful for "recycling" events, particularly in a test or demo environment but possibly for other use cases, too. 
Various other patterns besides `same day next week` are available and custom ones can easily be created using the 
`event_laundry_interval_options` and `event_laundry_do_*` hooks.

### Notes

* It's experimental, hasn't been widely tested and requires PHP 5.4 or greater
* It also needs The Events Calendar 4.1 or greater, earlier versions are no bueno
* Should there be a problem running WP scheduled tasks (wp-cron), it won't work