<?php

/**
 * @Author: ashokkuikel
 * @Date:   2020-04-28 12:47:09
 * @Last Modified by:   ashokkuikel
 * @Last Modified time: 2020-04-28 12:48:18
 */
function addprivate_event_callback(){
	$user_data = $_POST['data'];
	$event_array = array(
		array("status"=>"true","n_data"=>json_encode($user_data)));
	echo json_encode($event_array);
	wp_die();
}
function modifyprivate_event_callback(){
	$user_data = $_POST['data'];
	$event_array = array(
		array("status"=>"true","n_data"=>json_encode($user_data)));
	echo json_encode($event_array);
	wp_die();
}
function deleteprivate_event_callback(){
	$user_data = $_POST['data'];
	$deleted = 1;
	$event_array = array(
		array("status"=>"true","deleted"=> $user_data['id']));
	echo json_encode($event_array);
	wp_die();
}