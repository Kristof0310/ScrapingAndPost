<?php
/*
Plugin Name: ScrapingAndPost
Description: Scrape periodically and post updated data
Version: 1.0
Author: Vasyl Kostyniuk
*/

// Schedule an event to run every hour
register_activation_hook(__FILE__, 'scraping_plugin_activation');
add_action('scraping_hook', 'scraping_and_post');

function add_custom_interval($schedules) {
    $schedules['15min'] = array(
        'interval' => 60*15,
        'display' => __( 'Every 15 minutes' )
    );
    return $schedules;
}

function scraping_plugin_activation() {
    add_filter( 'cron_schedules', 'add_custom_interval');

    // Schedule the event to run every 10 minutes
    if (!wp_next_scheduled('scraping_hook')) {
        wp_schedule_event(time(), '15min', 'scraping_hook');
    }
 }

function scraping_and_post() {
    $currentDate = date('dmY');
    $api_url = 'https://autregweb.sst.dk/AuthorizationSearchResult.aspx?authmin='.$currentDate.'&authmax='.$currentDate;
    $post_api_url = 'https://www.medicinskenyheder.dk/wp-json/wp/v2/posts';
    $response = wp_remote_get($api_url);

    if (is_wp_error($response)) {
        
    } else {
        $body = wp_remote_retrieve_body($response);
        $dom = new DOMDocument;
        // Load the HTML content into the DOMDocument
        $dom->loadHTML($body);

        // Create a new DOMXPath instance
        $xpath = new DOMXPath($dom);

        //get __VIEWSTATE value
        $viewStateXPath = "//input[@name='__VIEWSTATE']/@value";
        $viewStateValue = $xpath->evaluate($viewStateXPath);

        //get __EVENTVALIDATION value
        $eventValidationPath = "//input[@name='__EVENTVALIDATION']/@value";
        $eventValidationValue = $xpath->evaluate($eventValidationPath);

        //get page count
        $spanXPath = "//span[@id='CurrentPageInfo']";
        $spanText = $xpath->evaluate($spanXPath)->item(0)->textContent;

        $pattern = '/i alt (\d+) resultater:/';
        preg_match($pattern, $spanText, $matches);

        //check if the match was found
        if (isset($matches[1])) {
           $resultCount = $matches[1];
          
           $pages = ceil($resultCount / 50);
        }

        //get table data
        $divXPath = "//div[@class='ClientSearchResults']";
        $clientSearchResultsDiv = $xpath->query($divXPath)->item(0);;
       
        if ($clientSearchResultsDiv) {
        
            // Within the selected div, look for the table
            $tableNode = $xpath->query(".//table", $clientSearchResultsDiv)->item(0);
        
            // Check if the table node was found
            if ($tableNode) {

                global $wpdb;

                // Iterate through rows and cells to get the data
                foreach ($xpath->query('.//tr', $table) as $row) {
                    $rowData = array();

                    foreach ($xpath->query('.//td', $row) as $cell) {
                        // Save the cell data to the $rowData array
                        $rowData[] = trim($cell->nodeValue);
                    }

                    // Prepare data for insertion
                    $data = array(
                        'firstname' => $rowData[0], // Replace with your actual column names
                        'lastname' => $rowData[1],
                        'birthday' => $rowData[2],
                        'subject_group' => $rowData[3],
                    );

                    // Check if data with the same values already exists
                    $existingData = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM www_medical_professional WHERE firstname = %s AND lastname = %s AND birthday = %s AND subject_group = %s",
                            $data['firstname'],
                            $data['lastname'],
                            $data['birthday'],
                            $data['subject_group']
                        )
                    );

                    if (!$existingData) {
                        // Insert data into table
                        $wpdb->insert('www_medical_professional', $data);
        
                        //post this data
                        $post_data = array(
                            'post_title' => 'New Professional',
                            'post_content' => $data['firstname'] . ' ' . $data['lastname'] . "\n" . $data['birthday'] . "\n" . $data['subject_group'],
                            'post_status' => 'publish', // You can use 'draft' or 'pending' if you don't want to publish immediately
                        );

                        // Make the request to create a new post
                        $response = wp_remote_post($post_api_url, array(
                            'headers' => array(
                                'Content-Type' => 'application/json',
                            ),
                            'body' => wp_json_encode($post_data),
                        ));

                        // Check for errors
                        if (is_wp_error($response)) {
                            echo 'Error creating post: ' . esc_html($response->get_error_message());
                        } else {
                            // Post created successfully
                            echo 'Post created successfully.';
                        }
                    }
                }
            } else {
                // No table found within the div
                echo "No table found within the div with class ClientSearchResults.";
            }
        } else {
            // No div found with class ClientSearchResults
            echo "No div found with class ClientSearchResults.";
        }
       
        //get data from another pages
        for ($i = 1; $i <$pages; $i++) {
            
            $pageNum = sprintf('%02d', $i);
            $form_data = array(
                '__EVENTTARGET' => 'pager$ctl00$ctl' . $pageNum, 
                '__EVENTARGUMENT' => '', 
                '__VIEWSTATE' => $viewStateValue, 
                '__VIEWSTATEGENERATOR' => '018A931E',
                '__EVENTVALIDATION' => $eventValidationValue
            );

            //send request
            $response = wp_remote_post($api_url, array(
                'body' => $form_data, 
            ));

            // Check for errors
            if (is_wp_error($response)) {
                echo 'Error creating post: ' . esc_html($response->get_error_message());
            } else {

                $body = wp_remote_retrieve_body($response);
                $dom->loadHTML($body);

                //get table data
                $divXPath = "//div[@class='ClientSearchResults']";
                $clientSearchResultsDiv = $xpath->query($divXPath)->item(0);;
            
                if ($clientSearchResultsDiv) {
                
                    // Within the selected div, look for the table
                    $tableNode = $xpath->query(".//table", $clientSearchResultsDiv)->item(0);
                
                    // Check if the table node was found
                    if ($tableNode) {

                        // Iterate through rows and cells to get the data
                        foreach ($xpath->query('.//tr', $table) as $row) {
                            $rowData = array();

                            foreach ($xpath->query('.//td', $row) as $cell) {
                                // Save the cell data to the $rowData array
                                $rowData[] = trim($cell->nodeValue);
                            }

                            // Prepare data for insertion
                            $data = array(
                                'firstname' => $rowData[0], // Replace with your actual column names
                                'lastname' => $rowData[1],
                                'birthday' => $rowData[2],
                                'subject_group' => $rowData[3],
                            );

                            // Check if data with the same values already exists
                            $existingData = $wpdb->get_row(
                                $wpdb->prepare(
                                    "SELECT * FROM www_medical_professional WHERE firstname = %s AND lastname = %s AND birthday = %s AND subject_group = %s",
                                    $data['firstname'],
                                    $data['lastname'],
                                    $data['birthday'],
                                    $data['subject_group']
                                )
                            );

                            if (!$existingData) {
                                // Insert data into table
                                $wpdb->insert('www_medical_professional', $data);
                
                                //post this data
                                $post_data = array(
                                    'post_title' => 'New Professional',
                                    'post_content' => $data['firstname'] . ' ' . $data['lastname'] . "\n" . $data['birthday'] . "\n" . $data['subject_group'],
                                    'post_status' => 'publish', // You can use 'draft' or 'pending' if you don't want to publish immediately
                                );

                                // Make the request to create a new post
                                $response = wp_remote_post($post_api_url, array(
                                    'headers' => array(
                                        'Content-Type' => 'application/json',
                                    ),
                                    'body' => wp_json_encode($post_data),
                                ));

                                // Check for errors
                                if (is_wp_error($response)) {
                                    echo 'Error creating post: ' . esc_html($response->get_error_message());
                                } else {
                                    // Post created successfully
                                    echo 'Post created successfully.';
                                }
                            }
                        }
                    } else {
                        // No table found within the div
                        echo "No table found within the div with class ClientSearchResults.";
                    }
            } else {
                    // No div found with class ClientSearchResults
                    echo "No div found with class ClientSearchResults.";
                }
            }
       }
    }
}

register_deactivation_hook(__FILE__, 'scraping_plugin_deactivation');

function scraping_plugin_deactivation() {
    // Clear the scheduled event and remove the custom interval on deactivation
    wp_clear_scheduled_hook('scraping_hook');
    remove_filter('cron_schedules', 'add_custom_interval');
}



?>