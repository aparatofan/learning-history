<?php

/**
 * @Author: ashokkuikel
 * @Date:   2020-03-05 17:50:06
 * @Last Modified by:   ashokkuikel
 * @Last Modified time: 2020-04-28 12:38:42
 */
    function getprivate_event_callback(){
        global $wpdb;        
        $wpcct_name = $wpdb->prefix . 'wpcc_tracking';
        $results = $wpdb->get_results( 
                $wpdb->prepare("SELECT * FROM $wpcct_name WHERE customerID=%d", get_current_user_id()) 
             );
        $eventArray = array();
        while ($row = mysqli_fetch_assoc($results)) {
            array_push($eventArray, $row);
        }
    	echo json_encode($results);
        wp_die(); 
    }