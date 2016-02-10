<?php
/**
* Plugin Name: TV Archive Political Ads
* Plugin URI: http://politicaladsarcive.com
* Description: Use data from the Internet Archive's political ad tracker in your wordpress theme
* Version: 1.0
* Author: Daniel Schultz
* Author URI: http://slifty.com
* License:
*/


//////////////
/// Plugin setup methods

/**
 * Create a custom post type for political ads
 * @return [type] [description]
 */
function register_archive_political_ad_type() {
    register_post_type( 'archive_political_ad',
        array(
            'rewrite' => array('with_front' => false, 'slug' => 'ad'),
            'labels' => array(
                'name' => __( 'Political Ads' ),
                'singular_name' => __( 'Political Ad' ),
                'add_new_item' => __( 'Add New Ad' ),
                'edit_item' => __( 'Edit Ad' ),
                'new_item' => __( 'New Ad' ),
                'view_item' => __( 'View Ad' ),
                'search_items' => __( 'Search Ads' ),
                'not_found' => __( 'No ads found' ),
                'not_found_in_trash' => __( 'No ads found in Trash' ),
                'parent_item_colon' => __( 'Parent Ad' ),
            ),
            'capabilities' => array(
                'edit_post'          => 'edit_ad',
                'read_post'          => 'read_ad',
                'delete_post'        => 'delete_ad',
                'delete_posts'       => 'delete_ads',
                'edit_posts'         => 'edit_ads',
                'edit_others_posts'  => 'edit_others_ads',
                'publish_posts'      => 'publish_ads',
                'read_private_posts' => 'read_private_ads',
                'create_posts'       => 'create_ads',
            ),
            'description' => __( 'A political ad identified and tracked by the Internet Archive.' ),
            'public' => true,
            'menu_icon' => 'dashicons-media-video',
            'has_archive' => true,
            'supports' => array( 'title')
        )
    );

    // Set up role capabilities
    $roles = ['author', 'administrator'];

        foreach ( $roles as $roleName ) {

          // Get the author role
          $role = get_role( $roleName );

          // Add full political ad edit capabilities
          $role->add_cap( 'edit_ad' );
          $role->add_cap( 'read_ad' );
          $role->add_cap( 'delete_ad' );
          $role->add_cap( 'edit_ads' );
          $role->add_cap( 'edit_others_ads' );
          $role->add_cap( 'publish_ads' );
          $role->add_cap( 'read_private_ads' );
          $role->add_cap( 'create_ads' );
        }
}
add_action( 'init', 'register_archive_political_ad_type' );

/**
 * Create a location to store all discovered copies of these political ads
 * @return [type] [description]
 */
function create_ad_instances_table() {
    global $wpdb;
    global $ad_db_version;
    // Wordpress doesn't load upgrade.php by default
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    // Create the ad instances data table
    $table_name = $wpdb->prefix . 'ad_instances';

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        wp_identifier mediumint(9) NOT NULL,
        archive_identifier varchar(100) NOT NULL,
        network varchar(20),
        market varchar(20),
        location varchar(128),
        program varchar(128),
        start_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        end_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        date_created datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        UNIQUE KEY instance_key (archive_identifier,network,start_time),
        KEY archive_identifier_key (archive_identifier),
        KEY wp_identifier_key (wp_identifier),
        KEY network_key (network)
    ) $charset_collate;";
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'create_ad_instances_table');


/**
 * A method that will load in all ad data (metadata, instances, etc)
 * @return [type] [description]
 */
function load_ad_data() {
    write_log("Loading Ad Data");

    ///////
    // STEP 0: Prepare lookup tables
    $network_lookup = get_network_metadata();
    $sponsor_lookup = get_sponsor_metadata();

    ///////
    // STEP 1: Load all newly discovered political ads, creating entries for each new ad

    // STEP 1.1: Get a list of new ads
    $new_ads = get_new_ads();

    // STEP 1.2: Go through the list and make sure there is a "post" for each ad
    foreach($new_ads as $new_ad) {

        $ad_identifier = $new_ad->identifier;
        // Does the ad already exist?
        $existing_ad = get_page_by_title( $ad_identifier, OBJECT, 'archive_political_ad');
        if($existing_ad) {
            $wp_identifier = $existing_ad->ID;
            continue;
        }
        else {
            // Create a new post for the ad
            $post = array(
                'post_name'      => $ad_identifier,
                'post_title'     => $ad_identifier,
                'post_status'    => 'draft', // Eventually we may want this to be 'publish'
                'post_type'      => 'archive_political_ad'
            );
            $wp_identifier = wp_insert_post( $post );
        }

        // Load the metadata for this ad
        $metadata = $new_ad->json;

        // Store the basic information
        $ad_embed_url = 'https://archive.org/embed/'.$ad_identifier;
        $ad_id = $ad_identifier;
        $ad_type = "Political Ad";
        $ad_race = ""; // TODO: look this up
        $ad_message = property_exists($metadata, 'message')?$metadata->message:'unknown';
        update_field('field_566e30c856e35', $ad_embed_url , $wp_identifier); // embed_url
        update_field('field_566e328a943a3', $ad_id, $wp_identifier); // archive_id
        update_field('field_566e359261c2e', $ad_type, $wp_identifier); // ad_type
        update_field('field_566e360961c2f', $ad_message, $wp_identifier); // ad_message

        // Store the sponsors
        // TODO: metadata field should be "sponsors" not "sponsor"
        if(property_exists($metadata, 'sponsor')
        && is_array($metadata->sponsor)) {
            $new_sponsors = array();
            foreach($metadata->sponsor as $sponsor) {
                if(array_key_exists($sponsor, $sponsor_lookup)) {
                    $sponsor_metadata = $sponsor_lookup[$sponsor];

                    if(sizeof($sponsor_metadata) > 1) {
                        $sponsor_type = "multiple";
                    } else {
                        $types = array_keys($sponsor_metadata);
                        $sponsor_type = array_pop($types);
                    }
                }
                else {
                    $sponsor_type = "unknown";
                }

                $new_sponsor = array(
                    'field_566e32fb943a5' => $sponsor, // Name
                    'field_566e3353943a6' => $sponsor_type // Type
                );
                $new_sponsors[] = $new_sponsor;
            }
            update_field('field_566e32bd943a4', $new_sponsors, $wp_identifier);
        }

        // Store the candidates
        if(property_exists($metadata, 'candidate')
        && is_array($metadata->candidate)) {
            $new_candidates = array();
            foreach($metadata->candidate as $candidate) {
                $new_candidate = array(
                    'field_566e3573943a8' => $candidate // Name
                );
                $new_candidates[] = $new_candidate;
            }
            update_field('field_566e3533943a7', $new_candidates, $wp_identifier);
        }

        // Store the subjects
        if(property_exists($metadata, 'subject')
        && is_array($metadata->subject)) {
            $new_subjects = array();
            foreach($metadata->subject as $subject) {
                $new_subject = array(
                    'field_569d12ec487ef' => $subject // Name
                );
                $new_subjects[] = $new_subject;
            }
            update_field('field_569d12c8487ee', $new_subjects, $wp_identifier);
        }
    }

    // STEP 2: Load the list of instances and update the metadata
    global $wpdb;
    $existing_ads = get_posts(array(
        'post_type' => 'archive_political_ad',
        'post_status' => 'any',
        'numberposts' => -1
    ));

    foreach($existing_ads as $existing_ad) {
        $wp_identifier = $existing_ad->ID;
        $ad_identifier = $existing_ad->post_title;

        // STEP 2: Get every instance, and create a record for each instance
        // NOTE: it won't double insert when run more than once due to the unique key
        $instances = get_ad_archive_instances($ad_identifier);

        // Collect existing instances;
        $existing_instances = array();
        // Collect the data associated with this ad
        $table_name = $wpdb->prefix . 'ad_instances';

        $query = "SELECT id as id,
                         network as network,
                         start_time as start_time,
                         archive_identifier as archive_identifier,
                         wp_identifier as wp_identifier
                    FROM ".$table_name."
                   WHERE archive_identifier = '".esc_sql($ad_identifier)."'";

        $results = $wpdb->get_results($query);
        $rows = array();
        $metadata_cache = array();
        foreach($results as $result) {
            $network = $result->network;
            $start_time = $result->start_time;
            $archive_identifier = $result->archive_identifier;
            if(!array_key_exists($network, $existing_instances)) {
                $existing_instances[$network] = array();
            }
            $existing_instances[$network][] = $start_time;
        }

        // Iterate through each instance
        foreach($instances as $instance) {
            $network = $instance->chan;
            $market = array_key_exists($network, $network_lookup)?$network_lookup[$network]['market']:'';
            $location = array_key_exists($network, $network_lookup)?$network_lookup[$network]['location']:'';
            $start_time = date("Y-m-d H:i:s", $instance->start);
            $end_time = date("Y-m-d H:i:s", $instance->end);
            $date_created = date("Y-m-d H:i:s");
            $program = $instance->title;

            // Only try to insert if it doesn't exist already
            if(!array_key_exists($network, $existing_instances)
            || !in_array($start_time, $existing_instances[$network])) {
                $table_name = $wpdb->prefix . 'ad_instances';
                $wpdb->insert(
                    $table_name,
                    array(
                        'wp_identifier' => $wp_identifier,
                        'archive_identifier' => $ad_identifier,
                        'network' => $network,
                        'market' => $market,
                        'location' => $location,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                        'program' => $program,
                        'date_created' => $date_created
                    )
                );
            }
        }
    }

    // STEP 3: Update metadata based on the instance list
    foreach($existing_ads as $existing_ad) {
        $wp_identifier = $existing_ad->ID;
        $ad_identifier = $existing_ad->post_title;

        // We have the data, lets update the metadata for the canonical ad itself
        $table_name = $wpdb->prefix . 'ad_instances';

        $query = "SELECT count(*) as air_count,
                         count(DISTINCT network) as network_count,
                         count(DISTINCT market) as market_count,
                         MIN(start_time) as first_seen,
                         MAX(start_time) as last_seen
                    FROM ".$table_name."
                   WHERE archive_identifier = '".esc_sql($ad_identifier)."'
                GROUP BY archive_identifier";

        $results = $wpdb->get_results($query);

        if(sizeof($results) == 0) {
            $ad_air_count = 0;
            $ad_market_count = 0;
            $ad_network_count = 0;
            $ad_first_seen = '';
            $ad_last_seen = '';
        }
        else {
            $results = $results[0];
            $ad_air_count = $results->air_count;
            $ad_market_count = $results->market_count;
            $ad_network_count = $results->network_count;
            $ad_first_seen = date('Ymd', strtotime($results->first_seen));
            $ad_last_seen = date('Ymd', strtotime($results->last_seen));
        }

        // Note: the keys here are defined by the Advanced Custom Fields settings
        update_field('field_566e3659fb227', $ad_air_count, $wp_identifier); // air_count
        update_field('field_566e367e962e2', $ad_market_count, $wp_identifier); // market_count
        update_field('field_566e3697962e3', $ad_network_count, $wp_identifier); // network_count
        update_field('field_566e36b0962e4', $ad_first_seen, $wp_identifier); // first_seen
        update_field('field_566e36d5962e5', $ad_last_seen,  $wp_identifier); // last_seen
    }

    // Clear the transient cache
    $table_name = $wpdb->prefix . 'options';
    $query = "DELETE FROM `".$table_name."` WHERE `option_name` LIKE ('_transient%adcache%')";
    $results = $wpdb->query($query);

}

add_action('archive_sync', 'load_ad_data');

function activate_archive_sync() {
    // Does the scheduled task exist already?
    if(wp_get_schedule('archive_sync') === false) {
        wp_schedule_event(time(), 'hourly', 'archive_sync');
    }
}
register_activation_hook(__FILE__, 'activate_archive_sync');


function deactivate_archive_sync() {
    // Does the scheduled task exist already?
    $schedule = wp_get_schedule('archive_sync') === false;
    if($schedule) {
        wp_unschedule_event(wp_next_scheduled('archive_sync'), 'archive_sync');
    }
}
register_deactivation_hook( __FILE__, 'deactivate_archive_sync' );


//////////////
/// Methods used during data sync to communicate with APIs

/**
* Get a list of new ads from the archive (used in sync)
*/
function get_new_ads() {
    // Get a list of ad instances from the archive
    $url = 'https://archive.org/details/tv?canonical_ads=1&metadata=1&output=json';
    $url_result = file_get_contents($url);

    // Parse the result
    $ads = json_decode($url_result);
    return $ads;
}

/**
* Get a list of ad instances from the archive (used in sync)
*/
function get_ad_archive_instances($ad_identifier) {
    $url = 'https://archive.org/details/tv?ad_instances='.$ad_identifier.'&output=json';
    $url_result = file_get_contents($url);
    $results = json_decode($url_result);
    return $results;
}


/**
* Get the network -> market / location conversion
*/
function get_network_metadata() {
    // Get a list of ad instances from the archive
    $url = 'https://archive.org/tv.php?chan2market=1&output=json';
    $url_result = file_get_contents($url);
    $results = json_decode($url_result);

    // Convert results to an expected format
    $networks = array();
    foreach($results as $network => $values) {
        $networks[$network] = array(
            'market' => $values[0],
            'location' => $values[1]
        );
    }

    return $networks;
}

/**
* Load in the sponsor types and other information from CRP
*/
function get_sponsor_metadata() {
    // Get a list of ad instances from the archive
    // TODO: this API key needs to be put in an options page
    $url = 'https://www.opensecrets.org/api/index.php?method=internetArchive&apikey='.OPENSECRETS_API_KEY.'&output=json';

    // Create the GET
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);

    // Take in the server's response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Run the CURL
    $curl_result = curl_exec($ch);
    curl_close ($ch);

    // Parse the result
    $result = json_decode($curl_result);

    // Process the result
    $sponsors = array();

    foreach($result->response->record as $sponsor) {
        $sponsor = $sponsor->{'@attributes'};
        $sponsor_name = $sponsor->sponsorname;
        if(array_key_exists($sponsor_name, $sponsors)) {
            $sponsors[$sponsor_name][$sponsor->type] = $sponsor;
        } else {
            $sponsors[$sponsor_name] = array(
                $sponsor->type => $sponsor
            );
        }
    }

    return $sponsors;
}


//////////////
/// Methods for use in themes

/**
 * Get a complete list of the candidates with published ads in the system
 * @return [type] [description]
 */
function get_candidates() {
    // TODO: Cache the results of this query
    global $wpdb;
    $table_name = $wpdb->prefix . 'postmeta';
    $query = "SELECT count(*) as ad_count,
                     meta_value as ad_candidate
                FROM ".$table_name."
               WHERE meta_key LIKE 'ad_candidates_%_ad_candidate'
                 AND post_id IN (select ID from wp_posts where post_status = 'publish')
            GROUP BY meta_value
            ORDER BY ad_count desc";

    $results = $wpdb->get_results($query);

    $candidates = array();
    foreach($results as $result) {
        if(trim($result->ad_candidate) == "")
            continue;

        $candidate = array(
            "name" => $result->ad_candidate,
            "count" => $result->ad_count
        );
        array_push($candidates, $candidate);
    }
    return $candidates;
}


/**
 * Get a complete list of the sponsors with published ads in the system
 * @return [type] [description]
 */
function get_sponsors() {
    // TODO: Cache the results of this query
    global $wpdb;
    $table_name = $wpdb->prefix . 'postmeta';
    $query = "SELECT count(*) as ad_count,
                     meta_value as ad_sponsor
                FROM ".$table_name."
               WHERE meta_key LIKE 'ad_sponsors_%_ad_sponsor'
                 AND post_id IN (select ID from wp_posts where post_status = 'publish')
            GROUP BY meta_value
            ORDER BY ad_count desc";

    $results = $wpdb->get_results($query);

    $sponsors = array();
    foreach($results as $result) {
        if(trim($result->ad_sponsor) == "")
            continue;

        $sponsor = array(
            "name" => $result->ad_sponsor,
            "count" => $result->ad_count
        );
        array_push($sponsors, $sponsor);
    }
    return $sponsors;
}


/**
 * Get a complete list of the sponsor types with published ads in the system
 * @return [type] [description]
 */
function get_sponsor_types() {
    // TODO: Cache the results of this query
    global $wpdb;
    $table_name = $wpdb->prefix . 'postmeta';
    $query = "SELECT count(*) as ad_count,
                     meta_value as ad_sponsor_type
                FROM ".$table_name."
               WHERE meta_key LIKE 'ad_sponsors_%_sponsor_type'
                 AND post_id IN (select ID from wp_posts where post_status = 'publish')
            GROUP BY meta_value
            ORDER BY ad_count desc";

    $results = $wpdb->get_results($query);

    $sponsor_types = array();
    foreach($results as $result) {
        if(trim($result->ad_sponsor_type) == "")
            continue;

        $sponsor_type = array(
            "name" => $result->ad_sponsor_type,
            "count" => $result->ad_count
        );
        array_push($sponsor_types, $sponsor_type);
    }
    return $sponsor_types;
}


//////////////
/// Methods for political ad search

/**
 * Get a list of political ads based on input parameters
 * @param  array $query The array of fields we want to search in terms of.
 * @return array        The list of results
 */
function search_political_ads($query, $extra_args = array()) {
    global $wpdb;
    $parsed_query = parse_political_ad_query($query);

    // Candidates, Sponsors, and Sponsor Type are nested meta values
    $or_post_ids = array();
    $not_post_ids = array();
    $and_post_sets = array();
    $general_and_ids = array(); // "and" that was set to general implies ANY category match, not EVERY category match
    $use_matched_post_ids = false;
    $use_general_and = false;

    if(sizeof($parsed_query['candidate'])) {
        $use_matched_post_ids = true;
        foreach($parsed_query['candidate'] as $query_part) {
            if($query_part['value'] == "")
                continue;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *
                   FROM {$wpdb->prefix}postmeta
                  WHERE meta_key LIKE %s
                    AND meta_value LIKE %s
                ",
                'ad_candidates_%_ad_candidate',
                '%'.$query_part['value'].'%'
            ));

            $temp_ids = array();
            foreach($rows as $row) {
                $temp_ids[] = $row->post_id;
            }
            switch($query_part['boolean']) {
                case 'GENERAL_NOT':
                case 'NOT':
                    $not_post_ids = array_merge($not_post_ids, $temp_ids);
                    break;
                case 'GENERAL_AND':
                    $use_general_and = true;
                    $general_and_ids = array_merge($general_and_ids, $temp_ids);
                    break;
                case 'AND':
                    $and_post_sets[] = $temp_ids;
                    break;
                case 'GENERAL_OR':
                case 'OR':
                    $or_post_ids = array_merge($or_post_ids, $temp_ids);
                    break;
            }
        }
    }
    if(sizeof($parsed_query['sponsor'])) {
        $use_matched_post_ids = true;
        foreach($parsed_query['sponsor'] as $query_part) {
            if($query_part['value'] == "")
                continue;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *
                   FROM {$wpdb->prefix}postmeta
                  WHERE meta_key LIKE %s
                    AND meta_value LIKE %s
                ",
                'ad_sponsors_%_ad_sponsor',
                '%'.$query_part['value'].'%'
            ));

            $temp_ids = array();
            foreach($rows as $row) {
                $temp_ids[] = $row->post_id;
            }
            switch($query_part['boolean']) {
                case 'GENERAL_NOT':
                case 'NOT':
                    $not_post_ids = array_merge($not_post_ids, $temp_ids);
                    break;
                case 'GENERAL_AND':
                    $use_general_and = true;
                    $general_and_ids = array_merge($general_and_ids, $temp_ids);
                    break;
                case 'AND':
                    $and_post_sets[] = $temp_ids;
                    break;
                case 'GENERAL_OR':
                case 'OR':
                    $or_post_ids = array_merge($or_post_ids, $temp_ids);
                    break;
            }
        }
    }

    if(sizeof($parsed_query['sponsor_type'])) {
        $use_matched_post_ids = true;
        foreach($parsed_query['sponsor_type'] as $query_part) {
            if($query_part['value'] == "")
                continue;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *
                   FROM {$wpdb->prefix}postmeta
                  WHERE meta_key LIKE %s
                    AND meta_value = %s
                ",
                'ad_sponsors_%_sponsor_type',
                $query_part['value']
            ));

            $temp_ids = array();
            foreach($rows as $row) {
                $temp_ids[] = $row->post_id;
            }
            switch($query_part['boolean']) {
                case 'GENERAL_NOT':
                case 'NOT':
                    $not_post_ids = array_merge($not_post_ids, $temp_ids);
                    break;
                case 'GENERAL_AND':
                    $use_general_and = true;
                    $general_and_ids = array_merge($general_and_ids, $temp_ids);
                    break;
                case 'AND':
                    $and_post_sets[] = $temp_ids;
                    break;
                case 'GENERAL_OR':
                case 'OR':
                    $or_post_ids = array_merge($or_post_ids, $temp_ids);
                    break;
            }
        }
    }

    if(sizeof($parsed_query['subject'])) {
        $use_matched_post_ids = true;
        foreach($parsed_query['subject'] as $query_part) {
            if($query_part['value'] == "")
                continue;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *
                   FROM {$wpdb->prefix}postmeta
                  WHERE meta_key LIKE %s
                    AND meta_value LIKE %s
                ",
                'ad_subjects_%_ad_subject',
                '%'.$query_part['value'].'%'
            ));

            $temp_ids = array();
            foreach($rows as $row) {
                $temp_ids[] = $row->post_id;
            }

            switch($query_part['boolean']) {
                case 'GENERAL_NOT':
                case 'NOT':
                    $not_post_ids = array_merge($not_post_ids, $temp_ids);
                    break;
                case 'GENERAL_AND':
                    $use_general_and = true;
                    $general_and_ids = array_merge($general_and_ids, $temp_ids);
                    break;
                case 'AND':
                    $and_post_sets[] = $temp_ids;
                    break;
                case 'GENERAL_OR':
                case 'OR':
                    $or_post_ids = array_merge($or_post_ids, $temp_ids);
                    break;
            }
        }
    }

    if(sizeof($parsed_query['archive_id'])) {
        $use_matched_post_ids = true;
        foreach($parsed_query['archive_id'] as $query_part) {
            if($query_part['value'] == "")
                continue;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *
                   FROM {$wpdb->prefix}postmeta
                  WHERE meta_key LIKE %s
                    AND meta_value = %s
                ",
                'archive_id',
                $query_part['value']
            ));

            $temp_ids = array();
            foreach($rows as $row) {
                $temp_ids[] = $row->post_id;
            }
            switch($query_part['boolean']) {
                case 'GENERAL_NOT':
                case 'NOT':
                    $not_post_ids = array_merge($not_post_ids, $temp_ids);
                    break;
                case 'GENERAL_AND':
                    $use_general_and = true;
                    $general_and_ids = array_merge($general_and_ids, $temp_ids);
                    break;
                case 'AND':
                    $and_post_sets[] = $temp_ids;
                    break;
                case 'GENERAL_OR':
                case 'OR':
                    $or_post_ids = array_merge($or_post_ids, $temp_ids);
                    break;
            }
        }
    }

    // Market, Channel, and Location are instance values
    if(sizeof($parsed_query['network'])) {
        $use_matched_post_ids = true;
        foreach($parsed_query['network'] as $query_part) {
            if($query_part['value'] == "")
                continue;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *
                   FROM {$wpdb->prefix}ad_instances
                  WHERE network = %s
               GROUP BY wp_identifier
                ",
                $query_part['value']
            ));

            $temp_ids = array();
            foreach($rows as $row) {
                $temp_ids[] = $row->wp_identifier;
            }
            switch($query_part['boolean']) {
                case 'GENERAL_NOT':
                case 'NOT':
                    $not_post_ids = array_merge($not_post_ids, $temp_ids);
                    break;
                case 'GENERAL_AND':
                    $use_general_and = true;
                    $general_and_ids = array_merge($general_and_ids, $temp_ids);
                    break;
                case 'AND':
                    $and_post_sets[] = $temp_ids;
                    break;
                case 'GENERAL_OR':
                case 'OR':
                    $or_post_ids = array_merge($or_post_ids, $temp_ids);
                    break;
            }
        }
    }
    if(sizeof($parsed_query['market'])) {
        $use_matched_post_ids = true;
        foreach($parsed_query['market'] as $query_part) {
            if($query_part['value'] == "")
                continue;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *
                   FROM {$wpdb->prefix}ad_instances
                  WHERE market = %s
               GROUP BY wp_identifier
                ",
                $query_part['value']
            ));

            $temp_ids = array();
            foreach($rows as $row) {
                $temp_ids[] = $row->wp_identifier;
            }
            switch($query_part['boolean']) {
                case 'GENERAL_NOT':
                case 'NOT':
                    $not_post_ids = array_merge($not_post_ids, $temp_ids);
                    break;
                case 'GENERAL_AND':
                    $use_general_and = true;
                    $general_and_ids = array_merge($general_and_ids, $temp_ids);
                    break;
                case 'AND':
                    $and_post_sets[] = $temp_ids;
                    break;
                case 'GENERAL_OR':
                case 'OR':
                    $or_post_ids = array_merge($or_post_ids, $temp_ids);
                    break;
            }
        }
    }

    if(sizeof($parsed_query['location'])) {
        $use_matched_post_ids = true;
        foreach($parsed_query['location'] as $query_part) {
            if($query_part['value'] == "")
                continue;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *
                   FROM {$wpdb->prefix}ad_instances
                  WHERE location LIKE %s
               GROUP BY wp_identifier
                ",
                '%'.$query_part['value'].'%'
            ));

            $temp_ids = array();
            foreach($rows as $row) {
                $temp_ids[] = $row->wp_identifier;
            }
            switch($query_part['boolean']) {
                case 'GENERAL_NOT':
                case 'NOT':
                    $not_post_ids = array_merge($not_post_ids, $temp_ids);
                    break;
                case 'GENERAL_AND':
                    $use_general_and = true;
                    $general_and_ids = array_merge($general_and_ids, $temp_ids);
                    break;
                case 'AND':
                    $and_post_sets[] = $temp_ids;
                    break;
                case 'GENERAL_OR':
                case 'OR':
                    $or_post_ids = array_merge($or_post_ids, $temp_ids);
                    break;
            }
        }
    }

    if(sizeof($parsed_query['after'])) {
        $use_matched_post_ids = true;
        foreach($parsed_query['after'] as $query_part) {
            if($query_part['value'] == "")
                continue;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *
                   FROM {$wpdb->prefix}ad_instances
                  WHERE start_time >= %s
               GROUP BY wp_identifier
                ",
                date('Y-m-d H:i:s', strtotime($query_part['value']))
            ));

            $temp_ids = array();
            foreach($rows as $row) {
                $temp_ids[] = $row->wp_identifier;
            }
            switch($query_part['boolean']) {
                case 'GENERAL_NOT':
                case 'NOT':
                    $not_post_ids = array_merge($not_post_ids, $temp_ids);
                    break;
                case 'GENERAL_AND':
                    $use_general_and = true;
                    $general_and_ids = array_merge($general_and_ids, $temp_ids);
                    break;
                case 'AND':
                    $and_post_sets[] = $temp_ids;
                    break;
                case 'GENERAL_OR':
                case 'OR':
                    $or_post_ids = array_merge($or_post_ids, $temp_ids);
                    break;
            }
        }
    }

    if(sizeof($parsed_query['before'])) {
        $use_matched_post_ids = true;
        foreach($parsed_query['before'] as $query_part) {
            if($query_part['value'] == "")
                continue;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *
                   FROM {$wpdb->prefix}ad_instances
                  WHERE start_time <= %s
               GROUP BY wp_identifier
                ",
                date('Y-m-d H:i:s', strtotime($query_part['value']))
            ));

            $temp_ids = array();
            foreach($rows as $row) {
                $temp_ids[] = $row->wp_identifier;
            }
            switch($query_part['boolean']) {
                case 'GENERAL_NOT':
                case 'NOT':
                    $not_post_ids = array_merge($not_post_ids, $temp_ids);
                    break;
                case 'GENERAL_AND':
                    $use_general_and = true;
                    $general_and_ids = array_merge($general_and_ids, $temp_ids);
                    break;
                case 'AND':
                    $and_post_sets[] = $temp_ids;
                    break;
                case 'GENERAL_OR':
                case 'OR':
                    $or_post_ids = array_merge($or_post_ids, $temp_ids);
                    break;
            }
        }
    }

    if(sizeof($parsed_query['data_since'])) {
        $use_matched_post_ids = true;
        foreach($parsed_query['data_since'] as $query_part) {
            if($query_part['value'] == "")
                continue;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *
                   FROM {$wpdb->prefix}ad_instances
                  WHERE date_created >= %s
               GROUP BY wp_identifier
                ",
                date('Y-m-d H:i:s', strtotime($query_part['value']))
            ));

            $temp_ids = array();
            foreach($rows as $row) {
                $temp_ids[] = $row->wp_identifier;
            }
            switch($query_part['boolean']) {
                case 'GENERAL_NOT':
                case 'NOT':
                    $not_post_ids = array_merge($not_post_ids, $temp_ids);
                    break;
                case 'GENERAL_AND':
                    $use_general_and = true;
                    $general_and_ids = array_merge($general_and_ids, $temp_ids);
                    break;
                case 'AND':
                    $and_post_sets[] = $temp_ids;
                    break;
                case 'GENERAL_OR':
                case 'OR':
                    $or_post_ids = array_merge($or_post_ids, $temp_ids);
                    break;
            }
        }
    }

    // Proccess the booleans
    $matched_post_ids = array();

    // Add the "or" set as a new and set
    $and_post_sets[] = $or_post_ids;

    // Did we have a general AND?
    if($use_general_and)
        $and_post_sets[] = $general_and_ids;

    if(sizeof($and_post_sets) == 1)
        $matched_post_ids = $and_post_sets[0];
    else
        $matched_post_ids = call_user_func_array('array_intersect', $and_post_sets);

    // Remove the "not" set
    $matched_post_ids = array_diff($matched_post_ids, $not_post_ids);

    // Remove dupes because why not
    $matched_post_ids = array_unique($matched_post_ids);

    // We have a list of matched post IDs, lets load up
    $base_args = array(
        'post_type'   => 'archive_political_ad',
        'post_status'   => 'publish',
        'orderby'           => 'post_date',
        'order'             => 'DESC'
    );
    if($use_matched_post_ids) {
        if(sizeof($matched_post_ids) > 0) {
            $base_args['post__in'] = $matched_post_ids;
        } else {
            $base_args['post__in'] = array(-1);
        }
    }

    $args = array_merge($base_args, $extra_args);

    $wp_query = new WP_Query($args);
    return $wp_query;
}

/**
 * Break a string into an array of categorized terms.
 * @param  [type] $query [description]
 * @return [type]        [description]
 */
function parse_political_ad_query($query) {
    // This will be super simple for now -- anything of the form a:b is a facet.
    $parsed_query = array(
        'general' => array(),
        'archive_id' => array(),
        'sponsor' => array(),
        'subject' => array(),
        'candidate' => array(),
        'sponsor_type' => array(),
        'network' => array(),
        'market' => array(),
        'location' => array(),
        'type' => array(),
        'before' => array(),
        'after' => array(),
        'data_since' => array(),
    );

    // Which of these buckets are considered part of "general"
    $general_buckets = array(
        'archive_id',
        'sponsor',
        'subject',
        'candidate',
        'sponsor_type',
        'network',
        'market',
        'location',
        'type'
    );

    // First, break the query into boolean parts
    $query_boolean_parts = preg_split("/(\sAND\s|\sOR\s|\sNOT\s)/", $query, -1, PREG_SPLIT_DELIM_CAPTURE);

    // Start off with a good old fashoned OR
    $active_boolean = 'OR';
    foreach($query_boolean_parts as $query_boolean_part) {

        // Is the part empty?  Skip it if so
        if($query_boolean_part == "")
            continue;

        // If this is a boolean, we aren't including it in the query itself
        $query_boolean_part = trim($query_boolean_part);
        if($query_boolean_part == 'OR'
        || $query_boolean_part == 'AND'
        || $query_boolean_part == 'NOT') {
                $active_boolean = $query_boolean_part;
                continue;
        }

        // We have a piece of a query, run with it
        // First, break the query into parts, keeping quoted sections as one item
        $query_parts = preg_split("/(?:'[^']*'|\"[^\"]*\")(*SKIP)(*F)|\h+/", $query_boolean_part);

        // Split the parts into buckets
        foreach($query_parts as $query_part) {
            $facet_parts = preg_split('/\:/', $query_part);
            if(sizeof($facet_parts) == 1) {
                $bucket = 'general';
                $value = $facet_parts[0];
                $boolean = 'GENERAL_'.$active_boolean;
            } else {
                $bucket = $facet_parts[0];
                $value = $facet_parts[1];
                $boolean = $active_boolean;
                // Note: we're just going to ignore if the user did something like a:b:c
            }

            // 'network' and 'channel' are the same
            if($bucket == 'channel')
                $bucket = 'network';

            // remove quotes from the value
            $value = preg_replace('/\"|\\\\/', '', $value);
            if(!array_key_exists($bucket, $parsed_query))
                continue;

            // We don't want to search for empty strings
            $parsed_query[$bucket][] = array(
                'value' => $value,
                'boolean' => $boolean
            );
        }

        // Add in 'general' to a few specific buckets
        foreach($general_buckets as $bucket) {
            $parsed_query[$bucket] = array_merge($parsed_query['general'], $parsed_query[$bucket]);
            $parsed_query[$bucket] = array_unique($parsed_query[$bucket], SORT_REGULAR);
        }
    }

    return $parsed_query;
}


//////////////
/// Methods for data export

/**
 * Return a list of raw ad data
 * @return [type] [description]
 */
function get_ads() {
    global $wp;
    global $wpdb;
    $args = array(
        'posts_per_page' => -1
    );
    $wp_query = search_political_ads('', $args);
    $ads = $wp_query->posts;
    $rows = array();
    foreach($ads as $ad) {
        $post_metadata = get_fields($ad);
        $wp_identifier = $ad->ID;
        $ad_embed_url = array_key_exists('embed_url', $post_metadata)?$post_metadata['embed_url']:'';
        $ad_notes = array_key_exists('notes', $post_metadata)?$post_metadata['notes']:'';
        $archive_id = array_key_exists('archive_id', $post_metadata)?$post_metadata['archive_id']:'';
        $ad_sponsors = array_key_exists('ad_sponsors', $post_metadata)?$post_metadata['ad_sponsors']:array();
        // Create sponsor links
        $ad_sponsor_names = extract_sponsor_names($ad_sponsors);
        foreach($ad_sponsor_names as $index => $sponsor_name) {
            $ad_sponsor_names[$index] = $sponsor_name;
        }
        // Create sponsor type links
        $ad_sponsor_types = extract_sponsor_types($ad_sponsors);
        foreach($ad_sponsor_types as $index => $sponsor_type) {
            $ad_sponsor_types[$index] = get_sponsor_type_value($sponsor_type);
        }
        $ad_candidate = generate_candidates_string(array_key_exists('ad_candidates', $post_metadata)?$post_metadata['ad_candidates']:'');
        $ad_subject = generate_subjects_string(array_key_exists('ad_subjects', $post_metadata)?$post_metadata['ad_subjects']:array());
        $ad_type = array_key_exists('ad_type', $post_metadata)?$post_metadata['ad_type']:'';
        $ad_message = array_key_exists('ad_message', $post_metadata)?$post_metadata['ad_message']:'';
        $ad_air_count = array_key_exists('air_count', $post_metadata)?$post_metadata['air_count']:'';
        $ad_market_count = array_key_exists('market_count', $post_metadata)?$post_metadata['market_count']:'';
        $ad_first_seen = array_key_exists('first_seen', $post_metadata)?$post_metadata['first_seen'].' UTC':'';
        $ad_last_seen =array_key_exists('last_seen', $post_metadata)? $post_metadata['last_seen'].' UTC':'';
        $ad_ingest_date = $ad->post_date.' UTC';
        $ad_references = $ad->references;

        // Apparently it's possible that references will return an array (if the plugin is updated)
        if(is_array($ad_references))
            $ad_references = sizeof($ad_references);

        $row = [
            "wp_identifier" => $wp_identifier,
            "archive_id" => $archive_id,
            "embed_url" => $ad_embed_url,
            "sponsor" => implode(", ", $ad_sponsor_names),
            "sponsor_type" => implode(", ", $ad_sponsor_types),
            "subject" => $ad_subject,
            "candidate" => $ad_candidate,
            "type" => $ad_type,
            "message" => $ad_message,
            "air_count" => $ad_air_count,
            "reference_count" => $ad_references,
            "market_count" => $ad_market_count,
            "date_ingested" => $ad_ingest_date
        ];

        $rows[] = $row;
    }
    return $rows;
}

/**
 * Return a list of ad instances, either for the entire corpus or a single ad
 * @param  string $ad_identifier (Optional) the archive identifier of a specific ad
 * @return array                 a complex object containing information about ad airings
 */
function get_ad_instances($query = '', $data_since = false){
    global $wp;
    global $wpdb;

    $args = array(
        'posts_per_page' => -1,
        'fields' => 'ids'
    );
    $wp_query = search_political_ads($query, $args);
    $ids = $wp_query->posts;

    // Collect the data associated with this ad
    $table_name = $wpdb->prefix . 'ad_instances';

    $query = "SELECT id as id,
                     network as network,
                     market as market,
                     location as location,
                     start_time as start_time,
                     end_time as end_time,
                     archive_identifier as archive_identifier,
                     wp_identifier as wp_identifier,
                     date_created as date_created
                FROM ".$table_name."
               WHERE wp_identifier IN(".implode(', ', $ids).")";

    // If we have a "data since" clause, only return instances that have been added since that date
    if($data_since)
        $query .= " AND date_created >= '".esc_sql($data_since)."'";

    if(sizeof($ids) == 0)
        return array();

    $results = $wpdb->get_results($query);
    $rows = array();
    $metadata_cache = array();
    foreach($results as $result) {
        $wp_identifier = $result->wp_identifier;
        $network = $result->network;
        $market = $result->market;
        $location = $result->location;
        $start_time = $result->start_time.' UTC';
        $end_time = $result->end_time.' UTC';
        $date_created = $result->date_created;
        $archive_identifier = $result->archive_identifier;

        // Cache the metadata for this identifier
        if(!array_key_exists($archive_identifier, $metadata_cache)) {
            $post_metadata = get_fields($wp_identifier);
            $metadata['ad_embed_url'] = array_key_exists('embed_url', $post_metadata)?$post_metadata['embed_url']:'';
            $metadata['ad_notes'] = array_key_exists('notes', $post_metadata)?$post_metadata['notes']:'';
            $metadata['archive_id'] = array_key_exists('archive_id', $post_metadata)?$post_metadata['archive_id']:'';
            $ad_sponsors = array_key_exists('ad_sponsors', $post_metadata)?$post_metadata['ad_sponsors']:array();
            // Create sponsor links
            $metadata['ad_sponsor_names'] = extract_sponsor_names($ad_sponsors);
            foreach($metadata['ad_sponsor_names'] as $index => $sponsor_name) {
                $metadata['ad_sponsor_names'][$index] = $sponsor_name;
            }
            // Create sponsor type links
            $metadata['ad_sponsor_types'] = extract_sponsor_types($ad_sponsors);
            foreach($metadata['ad_sponsor_types'] as $index => $sponsor_type) {
                $metadata['ad_sponsor_types'][$index] = get_sponsor_type_value($sponsor_type);
            }
            $metadata['ad_candidate'] = generate_candidates_string(array_key_exists('ad_candidates', $post_metadata)?$post_metadata['ad_candidates']:'');
            $metadata['ad_subject'] = generate_subjects_string(array_key_exists('ad_subjects', $post_metadata)?$post_metadata['ad_subjects']:array());
            $metadata['ad_type'] = array_key_exists('ad_type', $post_metadata)?$post_metadata['ad_type']:'';
            $metadata['ad_message'] = array_key_exists('ad_message', $post_metadata)?$post_metadata['ad_message']:'';
            $metadata['ad_air_count'] = array_key_exists('air_count', $post_metadata)?$post_metadata['air_count']:'';
            $metadata['ad_market_count'] = array_key_exists('market_count', $post_metadata)?$post_metadata['market_count']:'';
            $metadata['ad_first_seen'] = array_key_exists('first_seen', $post_metadata)?$post_metadata['first_seen'].' UTC':'';
            $metadata['ad_last_seen'] =array_key_exists('last_seen', $post_metadata)? $post_metadata['last_seen'].' UTC':'';
            $metadata_cache[$archive_identifier] = $metadata;
        }

        // Load the metadata from the cache
        $ad_embed_url = $metadata_cache[$archive_identifier]['ad_embed_url'];
        $ad_notes = $metadata_cache[$archive_identifier]['ad_notes'];
        $archive_id = $metadata_cache[$archive_identifier]['archive_id'];
        $ad_sponsor_names = $metadata_cache[$archive_identifier]['ad_sponsor_names'];
        $ad_sponsor_types = $metadata_cache[$archive_identifier]['ad_sponsor_types'];
        $ad_candidate = $metadata_cache[$archive_identifier]['ad_candidate'];
        $ad_subject = $metadata_cache[$archive_identifier]['ad_subject'];
        $ad_type = $metadata_cache[$archive_identifier]['ad_type'];
        $ad_message = $metadata_cache[$archive_identifier]['ad_message'];
        $ad_air_count = $metadata_cache[$archive_identifier]['ad_air_count'];
        $ad_market_count = $metadata_cache[$archive_identifier]['ad_market_count'];
        $ad_first_seen = $metadata_cache[$archive_identifier]['ad_first_seen'];
        $ad_last_seen = $metadata_cache[$archive_identifier]['ad_last_seen'];

        // Create the row
        $row = [
            "wp_identifier" => $wp_identifier,
            "network" => $network,
            "market" => $market,
            "location" => $location,
            "start_time" => $start_time,
            "end_time" => $end_time,
            "archive_id" => $archive_id,
            "embed_url" => $ad_embed_url,
            "sponsor" => implode(', ', $ad_sponsor_names),
            "sponsor_type" => implode(', ', $ad_sponsor_types),
            "subject" => $ad_subject,
            "candidate" => $ad_candidate,
            "type" => $ad_type,
            "message" => $ad_message,
            "air_count" => $ad_air_count,
            "market_count" => $ad_market_count,
            "date_created" => $date_created
        ];
        array_push($rows, $row);
    }
    return $rows;
}


function extract_sponsor_names($ad_sponsors) {
    // Is this the right type?
    if(!is_array($ad_sponsors))
        return "";

    $ad_sponsor_names = array();
    foreach($ad_sponsors as $ad_sponsor) {
        $sponsor_name = $ad_sponsor['ad_sponsor'];
        $ad_sponsor_names[] = $sponsor_name;
    }
    return array_unique($ad_sponsor_names);
}

function extract_sponsor_types($ad_sponsors) {
    // Is this the right type?
    if(!is_array($ad_sponsors))
        return "";

    $ad_sponsor_types = array();
    foreach($ad_sponsors as $ad_sponsor) {
        $sponsor_type = $ad_sponsor['sponsor_type'];
        $ad_sponsor_types[] = $sponsor_type;
    }
    return array_unique($ad_sponsor_types);
}

function get_sponsor_type_value($sponsor_type) {
    $sponsor_type_field = get_field_object('field_566e3353943a6');
    if(array_key_exists($sponsor_type, $sponsor_type_field['choices']))
        return $sponsor_type_field['choices'][$sponsor_type];
    else
        return "";
}

/**
 * Convert a list of sponsors to a single string
 */
function generate_sponsors_string($ad_sponsors) {

    // Is this the right type?
    if(!is_array($ad_sponsors))
        return "";

    $ad_sponsor_strings = array();
    foreach($ad_sponsors as $ad_sponsor) {
        $sponsor_string = $ad_sponsor['ad_sponsor'];
        if($ad_sponsor['sponsor_type'] != "unknown")
            $sponsor_string .= " (".$ad_sponsor['sponsor_type'].")";
        $ad_sponsor_strings[] = $sponsor_string;
    }
    return implode(", ", $ad_sponsor_strings);
}

/**
 * Convert a list of candidates to a single string
 */
function generate_candidates_string($ad_candidates) {

    // Is this the right type?
    if(!is_array($ad_candidates))
        return "";

    $ad_candidates_strings = array();
    foreach($ad_candidates as $ad_candidate) {
        $ad_candidates_strings[] = $ad_candidate['ad_candidate'];
    }
    return implode(", ", $ad_candidates_strings);
}


/**
 * Convert a list of candidates to a single string
 */
function generate_subjects_string($ad_subjects) {

    // Is this the right type?
    if(!is_array($ad_subjects))
        return "";

    $ad_candidates_strings = array();
    foreach($ad_subjects as $ad_subject) {
        $ad_subjects_strings[] = $ad_subject['ad_subject'];
    }
    return implode(", ", $ad_subjects_strings);
}


function generate_message_string($ad_message) {
    $ad_message_field = get_field_object('field_566e360961c2f');
    return $ad_message_field['choices'][$ad_message];
}


function generate_sponsor_type_string($sponsor_type) {
}

/**
 * Meta tag methods
 */

add_action('wp_head', 'politicalad_meta');
function politicalad_meta() {
    if(get_post_type() == 'archive_political_ad') {
        global $post;
        $post_metadata = get_fields();
        $date_created = get_the_date('n/j/y, g:i A');
        $ad_notes = array_key_exists('ad_notes', $post_metadata)?$post_metadata['ad_notes']:'';
        $archive_id = array_key_exists('archive_id', $post_metadata)?$post_metadata['archive_id']:'';
        $ad_sponsors = (array_key_exists('ad_sponsors', $post_metadata) && $post_metadata['ad_sponsors'])?$post_metadata['ad_sponsors']:array();
        $ad_candidates = (array_key_exists('ad_candidates', $post_metadata) && $post_metadata['ad_candidates'])?$post_metadata['ad_candidates']:array();
        $ad_subjects = (array_key_exists('ad_subjects', $post_metadata) && $post_metadata['ad_subjects'])?$post_metadata['ad_subjects']:array();
        $ad_type = array_key_exists('ad_type', $post_metadata)?$post_metadata['ad_type']:'';
        $ad_message = array_key_exists('ad_message', $post_metadata)?$post_metadata['ad_message']:'';
        $ad_air_count = array_key_exists('air_count', $post_metadata)?$post_metadata['air_count']:0;
        $ad_market_count = array_key_exists('market_count', $post_metadata)?$post_metadata['market_count']:0;
        $ad_network_count = array_key_exists('network_count', $post_metadata)?$post_metadata['network_count']:0;
        $ad_first_seen = (array_key_exists('first_seen', $post_metadata)&&$post_metadata['first_seen'])?$post_metadata['first_seen']:'--';
        $ad_last_seen = (array_key_exists('last_seen', $post_metadata)&&$post_metadata['last_seen'])?$post_metadata['last_seen']:'--';

        // Create sponsor links
        $ad_sponsor_names = extract_sponsor_names($ad_sponsors);
        foreach($ad_sponsor_names as $index => $sponsor_name) {
            $ad_sponsor_names[$index] = $sponsor_name;
        }

        // Create sponsor type links
        $ad_sponsor_types = extract_sponsor_types($ad_sponsors);
        foreach($ad_sponsor_types as $index => $sponsor_type) {
            $ad_sponsor_types[$index] = get_sponsor_type_value($sponsor_type);
        }
        // Create candidate links
        foreach($ad_candidates as $index => $ad_candidate) {
            $ad_candidates[$index] = $ad_candidate['ad_candidate'];
        }

        // Create subject links
        foreach($ad_subjects as $index => $ad_subject) {
            $ad_subjects[$index] = $ad_subject['ad_subject'];
        }

        if(sizeof($ad_candidates) > 1) {
            echo("<meta type=\"description\" content=\"".str_replace('"', '\\"',"Political ad by ".implode(', ', $ad_sponsor_names).". Candidates mentioned include ".implode(', ', $ad_candidates).". Discusses ".implode(', ', $ad_subjects).". Aired between ".$ad_first_seen." and ".$ad_first_seen)."\" />\n");
        } else {
            echo("<meta type=\"description\" content=\"".str_replace('"', '\\"',"Political ad by ".implode(', ', $ad_sponsor_names).". Candidate is ".implode(', ', $ad_candidates).". Discusses ".implode(', ', $ad_subjects).". Aired between ".$ad_first_seen." and ".$ad_first_seen)."\" />\n");
        }
    }

}


/**
 * API methods
 */

/////////////////
// Register the instance export API
// Check out http://coderrr.com/create-an-api-endpoint-in-wordpress/ for approach info

add_filter('query_vars', 'instance_export_add_query_vars');
add_action('parse_request', 'instance_export_sniff_requests');
add_action('init', 'instance_export_add_endpoint');

/** Add public query vars
 * @param array $vars List of current public query vars
 * @return array $vars
 */
function instance_export_add_query_vars($vars){
    $vars[] = '__instance_export';
    $vars[] = 'instance_export_options';
    return $vars;
}

/**
 * Route the export API values to match the query vars specified in export_add_query_vars
 * @return void
 */
function instance_export_add_endpoint() {
    $triggering_endpoint = '^instance_export/?(.*)?/?';
    add_rewrite_rule($triggering_endpoint,'index.php?__instance_export=1&instance_export_options=$matches[1]','top');
}

/**
 * Look to see if export is being requested, if so take over and return the export
 * @return die if API request
 */
function instance_export_sniff_requests() {
    global $wp;

    if(isset($wp->query_vars['__instance_export'])) {
        $filename = time();
        $output = array_key_exists('output', $_GET)?$_GET['output']:'csv';
        $query = array_key_exists('q', $_GET)?$_GET['q']:"";
        $data_since = array_key_exists('data_since', $_GET)?date('Y-m-d H:i:s',strtotime($_GET['data_since'])):false;

        // Nail down the filename
        $filename = preg_replace('/\W+/', '_', $query)."_".$filename;
        $cache_name = "adcache".preg_replace('/\W+/', '_', $query).$output;

        // Check the cache (if appropriate)
        // TODO: the cache name isn't normalized (so "Sponsor:Jeb Candidate:Jeb" and "Candidate:Jeb Sponsor:Jeb" would be two caches despite being the same query)
        // TODO: wordpress transients don't support names longer than 45 characters, we should find a better solution
        // TODO: we're going to only cache when the query is blank for now
        // if($query == '') {
        //     $ad_instances = get_transient($filename);

        //     // There is no cached copy
        //     if($ad_instances === false) {
        //         $ad_instances = get_ad_instances($query, $data_since);
        //         set_transient($cache_name, $ad_instances, 60*60*30); // The cache lasts 30 minutes
        //     }
        // } else {
        //     // We can't cache, the query is too long
            $ad_instances = get_ad_instances($query, $data_since);
        // }

        export_send_response($ad_instances, $output, $filename."_instances");
        exit;
    }
}


add_filter('query_vars', 'ad_export_add_query_vars');
add_action('parse_request', 'ad_export_sniff_requests');
add_action('init', 'ad_export_add_endpoint');

/** Add public query vars
 * @param array $vars List of current public query vars
 * @return array $vars
 */
function ad_export_add_query_vars($vars){
    $vars[] = '__ad_export';
    $vars[] = 'ad_export_options';
    return $vars;
}

/**
 * Route the export API values to match the query vars specified in export_add_query_vars
 * @return void
 */
function ad_export_add_endpoint() {
    $triggering_endpoint = '^ad_export/?(.*)?/?';
    add_rewrite_rule($triggering_endpoint,'index.php?__ad_export=1&ad_export_options=$matches[1]','top');
}

/**
 * Look to see if export is being requested, if so take over and return the export
 * @return die if API request
 */
function ad_export_sniff_requests(){
    global $wp;
    if(isset($wp->query_vars['__ad_export'])) {
        $filename = time();
        $ads = get_ads();

        if(array_key_exists('output', $_GET))
            export_send_response($ads, $_GET['output']);
        else {
            export_send_response($ads, 'csv', $filename."_ads");
        }
        exit;
    }
}


/**
 * Send the output based on the type ('csv' or 'json')
 */
function export_send_response($rows, $output='csv', $filename='data') {
    switch($output) {
        case 'csv':
            // output headers so that the file is downloaded rather than displayed
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename='.$filename.'.csv');
            if(sizeof($rows) == 0)
              exit;

            // Create the header data
            $header = array_keys($rows[0]);

            // create a file pointer connected to the output stream
            $output = fopen('php://output', 'w');

            // output the column headings
            fputcsv($output, $header);

            // loop over the rows, outputting them
            foreach($rows as $row) {
              fputcsv($output, $row);
            }
            exit;
        case 'json':
            header('Content-Type: application/json');
            echo(json_encode($rows));
            exit;
    }
}


// Helper methods used in the plugin
if (!function_exists('write_log')) {
    function write_log ( $log )  {
        if ( true === WP_DEBUG ) {
            if ( is_array( $log ) || is_object( $log ) ) {
                error_log( print_r( $log, true ) );
            } else {
                error_log( $log );
            }
        }
    }
}

?>
