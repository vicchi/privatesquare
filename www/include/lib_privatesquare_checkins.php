<?php

	loadlib("foursquare_venues");
	loadlib("datetime_when");
	loadlib("reverse_geoplanet");

 	#################################################################

	function privatesquare_checkins_status_map($string_keys=0){

		$map = array(
			'0' => 'i am here',
			'1' => 'i was there',
			'2' => 'i want to go there',
			'3' => 'again',
			'4' => 'again again',
			'5' => 'again maybe',
			'6' => 'again never',
		);

		if ($string_keys){
			$map = array_flip($map);
		}

		return $map;
	}

 	#################################################################

	function privatesquare_checkins_create($checkin){

		$user = users_get_by_id($checkin['user_id']);
		$cluster_id = $user['cluster_id'];

		$checkin['id'] = dbtickets_create(64);

		if (! isset($checkin['created'])){
			$checkin['created'] = time();
		}

		$insert = array();

		foreach ($checkin as $k => $v){
			$insert[$k] = AddSlashes($v);
		}

		$rsp = db_insert_users($cluster_id, 'PrivatesquareCheckins', $insert);

		if ($rsp['ok']){
			$rsp['checkin'] = $checkin;
		}

		return $rsp;
	}

 	#################################################################

	function privatesquare_checkins_update($checkin, $update){

		$user = users_get_by_id($checkin['user_id']);
		$cluster_id = $user['cluster_id'];

		$enc_id = AddSlashes($checkin['id']);
		$where = "id='{$enc_id}'";

		$hash = array();

		foreach ($update as $k => $v){
			$hash[$k] = AddSlashes($v);
		}

		return db_update_users($cluster_id, 'PrivatesquareCheckins', $update, $where);
	}

 	#################################################################

	function privatesquare_checkins_for_user(&$user, $more=array()){

		$cluster_id = $user['cluster_id'];
		$enc_user = AddSlashes($user['id']);

		$sql = "SELECT * FROM PrivatesquareCheckins WHERE user_id='{$enc_user}'";

		if (isset($more['when'])){
			list($start, $stop) = datetime_when_parse($more['when']);
			$enc_start = AddSlashes(strtotime($start));
			$enc_stop = AddSlashes(strtotime($stop));

			$sql .= " AND created BETWEEN '{$enc_start}' AND '{$enc_stop}'";
		}

		else if (isset($more['venue_id'])){
			$enc_venue = AddSlashes($more['venue_id']);
			$sql .= " AND venue_id='{$enc_venue}'";
		}

		else if (isset($more['locality'])){
			$enc_locality = AddSlashes($more['locality']);
			$sql .= " AND locality='{$enc_locality}'";
		}

		$sql .= " ORDER BY created DESC";

		$rsp = db_fetch_paginated_users($cluster_id, $sql, $more);

		if (! $rsp['ok']){
			return $rsp;
		}

		$count = count($rsp['rows']);

		for ($i=0; $i < $count; $i++){
			privatesquare_checkins_inflate_extras($rsp['rows'][$i], $more);
		}

		return $rsp;
	}

 	#################################################################

	function privatesquare_checkins_localities_for_user(&$user, $more=array()){

		$defaults = array(
			'page' => 1,
			'per_page' => 10,
		);

		$more = array_merge($defaults, $more);

		$cluster_id = $user['cluster_id'];
		$enc_user = AddSlashes($user['id']);

		# TO DO: indexes

		# Note: we do pagination in memory because we're going to
		# sort the results by count; this all happens below.

		$sql = "SELECT locality, COUNT(id) AS count FROM PrivatesquareCheckins WHERE user_id='{$enc_user}' GROUP BY locality";
		$rsp = db_fetch_users($cluster_id, $sql);

		if (! $rsp['ok']){
			return $rsp;
		}

		$tmp = array();

		foreach ($rsp['rows'] as $row){

			if (! $row['locality']){
				continue;
			}

			$tmp[$row['locality']] = $row['count'];
		}

		arsort($tmp);

		$woeids = array_keys($tmp);
		$total_count = count($woeids);

		#

		$page_count = ceil($total_count / $more['per_page']);
		$last_page_count = $total_count - (($page_count - 1) * $more['per_page']);

		$pagination = array(
			'total_count' => $total_count,
			'page' => $more['page'],
			'per_page' => $more['per_page'],
			'page_count' => $page_count,
		);

		if ($GLOBALS['cfg']['pagination_assign_smarty_variable']){
			$GLOBALS['smarty']->assign('pagination', $pagination);
		}

		#

		$offset = $more['per_page'] * ($more['page'] - 1);
		$woeids = array_slice($woeids, $offset, $more['per_page']);

		$localities = array();

		foreach ($woeids as $woeid){

			$count = $tmp[$woeid];

			$row = reverse_geoplanet_get_by_woeid($woeid, 'locality');

			# what if ! $row? should never happen but...

			$row['count'] = $count;

			# Maybe always get this? Filtering may just be a
			# pointless optimization (20120229/straup)

			if ($count > 1){

				$venues_more = array(
					'locality' => $woeid,
					'stats_mode' => 1,
				);

				$venues_rsp = privatesquare_checkins_venues_for_user($user, $venues_more);
				$row['venues'] = $venues_rsp['rows'];
			}

			$localities[] = $row;
		}

		return okay(array(
			'rows' => $localities,
			'pagination' => $pagination,
		));
	}

 	#################################################################

	function privatesquare_checkins_venues_for_user(&$user, $more=array()){

		$defaults = array(
			'stats_mode' => 0,
			'per_page' => 10,
			'page' => 1,
		);

		$more = array_merge($defaults, $more);

		# TO DO: date ranges

		$cluster_id = $user['cluster_id'];
		$enc_user = AddSlashes($user['id']);

		$sql = "SELECT venue_id, COUNT(id) AS count FROM PrivatesquareCheckins WHERE user_id='{$enc_user}'";

		if (isset($more['locality'])){
			$enc_loc = AddSlashes($more['locality']);
			$sql .= " AND locality='{$enc_loc}'";
		}

		# TO DO indexes

		if ($more['timeofday']){
			$enc_tod = AddSlashes($more['timeofday']);
			$sql .= " AND timeofday='{$enc_tod}'";
		}

		$sql .= " GROUP BY venue_id";

		$rsp = db_fetch_users($cluster_id, $sql);

		$tmp = array();

		foreach ($rsp['rows'] as $row){
			$tmp[$row['venue_id']] = $row['count'];
		}

		arsort($tmp);

		$venue_ids = array_keys($tmp);
		$total_count = count($venue_ids);

		$rows = array();
		$pagination = array();

		if ($more['stats_mode']){
			# do not paginate
		}

		else {

			$page_count = ceil($total_count / $more['per_page']);
			$last_page_count = $total_count - (($page_count - 1) * $more['per_page']);

			$pagination = array(
				'total_count' => $total_count,
				'page' => $more['page'],
				'per_page' => $more['per_page'],
				'page_count' => $page_count,
			);

			if ($GLOBALS['cfg']['pagination_assign_smarty_variable']){
				$GLOBALS['smarty']->assign('pagination', $pagination);
			}

			$offset = $more['per_page'] * ($more['page'] - 1);
			$venue_ids = array_slice($venue_ids, $offset, $more['per_page']);
		}

		foreach ($venue_ids as $venue_id){

			$count = $tmp[$venue_id];

			if ($more['stats_mode']){

				$venue = array(
					'venue_id' => $venue_id,
				);
			}

			else {
				$venue = foursquare_venues_get_by_venue_id($venue_id);
			}

			$venue['count'] = $count;

			$rows[] = $venue;
		}

		return okay(array(
			'rows' => $rows,
			'pagination' => $pagination
		));
	}

 	#################################################################

	function privatesquare_checkins_timesofday_for_locality(&$user, &$locality){

		$enc_user = AddSlashes($user['id']);
		$enc_locality = AddSlashes($locality['locality']);

		$cluster_id = $user['cluster_id'];

		# TO DO: indexes

		$sql = "SELECT timeofday, COUNT(id) AS count FROM PrivatesquareCheckins WHERE user_id='{$enc_user}'";
		$sql .= " AND locality='{$enc_locality}' AND timeofday IS NOT NULL AND timeofday != ''";

		$sql .= " GROUP BY timeofday";

		$rsp = db_fetch_users($cluster_id, $sql);

		if (! $rsp['ok']){
			return $rsp;
		}

		$times = array();

		foreach ($rsp['rows'] as $row){
			$times[$row['timeofday']] = $row['count'];
		}

		arsort($times);

		return okay(array(
			'times' => $times
		));
	}

 	#################################################################

	function privatesquare_checkins_timesofday_for_user(&$user, &$locality){

		$enc_user = AddSlashes($user['id']);
		$cluster_id = $user['cluster_id'];

		# TO DO: indexes

		$sql = "SELECT timeofday, COUNT(id) AS count FROM PrivatesquareCheckins WHERE user_id='{$enc_user}'";
		$sql .= " AND timeofday IS NOT NULL AND timeofday != ''";

		$sql .= " GROUP BY timeofday";

		$rsp = db_fetch_users($cluster_id, $sql);

		if (! $rsp['ok']){
			return $rsp;
		}

		$times = array();

		foreach ($rsp['rows'] as $row){
			$times[$row['timeofday']] = $row['count'];
		}

		arsort($times);

		return okay(array(
			'times' => $times
		));
	}

 	#################################################################

	function privatesquare_checkins_localities_for_timeofday($user, $timeofday){

		$enc_user = AddSlashes($user['id']);
		$enc_tod = AddSlashes($timeofday);

		$cluster_id = $user['cluster_id'];

		# TO DO: indexes

		$sql = "SELECT locality, COUNT(id) AS count FROM PrivatesquareCheckins";
		$sql .= " WHERE user_id='{$enc_user}' AND timeofday='{$enc_tod}'";
		$sql .= " GROUP BY locality";

		$rsp = db_fetch_users($cluster_id, $sql);

		if (! $rsp['ok']){
			return $rsp;
		}

		$localities = array();

		foreach ($rsp['rows'] as $row){

			if (! $row['locality']){
				continue;
			}

			$localities[$row['locality']] = $row['count'];
		}

		arsort($localities);

		return okay(array(
			'localities' => $localities
		));
	}

 	#################################################################

	function privatesquare_checkins_venues_for_timeofday($user, $timeofday){

		$enc_user = AddSlashes($user['id']);
		$enc_tod = AddSlashes($timeofday);

		$cluster_id = $user['cluster_id'];

		# TO DO: indexes

		$sql = "SELECT venue_id, COUNT(id) AS count FROM PrivatesquareCheckins";
		$sql .= " WHERE user_id='{$enc_user}' AND timeofday='{$enc_tod}'";
		$sql .= " GROUP BY venue_id";

		$rsp = db_fetch_users($cluster_id, $sql);

		if (! $rsp['ok']){
			return $rsp;
		}

		$venues = array();

		foreach ($rsp['rows'] as $row){
			$venues[$row['venue_id']] = $row['count'];
		}

		arsort($venues);

		return okay(array(
			'venues' => $venues
		));
	}

 	#################################################################

	function privatesquare_checkins_inflate_extras(&$row, $more=array()){

		$venue_id = $row['venue_id'];
		$venue = foursquare_venues_get_by_venue_id($venue_id); 
		$row['venue'] = $venue;

		if ($row['weather']){

			if ($weather = json_decode($row['weather'], "as hash")){
				$row['weather'] = $weather;
			}
		}

		# This doesn't make any sense unless you've got something
		# like memcache installed. The volume of DB calls that get
		# made relative to the actual use of any data here is out
		# of control. Leaving it as an FYI / nice to have...
		# (20120301/straup)

		if ((isset($more['inflate_locality'])) && ($woeid = $row['locality'])){
			$loc = reverse_geoplanet_get_by_woeid($woeid, 'locality');
		 	$row['locality'] = $loc;
		}

		# note the pass by ref
	}

 	#################################################################

	# Here's the thing: This will probably need to be cached and added
	# to incrementally at some point in the not too distant future. How
	# that's done remains an open question. MySQL blob? Write to disk?
	# Dunno. On the other hand we're just going to enjoy not having to
	# think about it for the moment. KTHXBYE (20120226/straup)

	function privatesquare_checkins_export_for_user(&$user, $more=array()){

		$rows = array();

		$count_pages = null;

		$args = array(
			'page' => 1,
			'per_page' => 100,
		);

		# Note the order of things here: don't overwrite
		# what we've set in $args above

		if (count($more)){
			$args = array_merge($more, $args);
		}

		while ((! isset($count_pages)) || ($args['page'] <= $count_pages)){

			if (! isset($count_pages)){
				$count_pages = $rsp['pagination']['page_count'];
			}

			# per the above we may need to add a flag to *not* fetch
			# the full venue listing out of the database (20120226/straup)

			$rsp = privatesquare_checkins_for_user($user, $args, $more);
			$rows = array_merge($rows, $rsp['rows']);

			$args['page'] += 1;
		}

		return okay(array('rows' => $rows));
	}

 	#################################################################

	function privatesquare_checkins_for_user_nearby(&$user, $lat, $lon, $more=array()){

		loadlib("geo_utils");
		
		$dist = (isset($more['dist'])) ? floatval($more['dist']) : 0.2;
		$unit = (geo_utils_is_valid_unit($more['unit'])) ? $more['unit'] : 'm';

		# TO DO: sanity check to ensure max $dist

		$bbox = geo_utils_bbox_from_point($lat, $lon, $dist, $unit);

		$cluster_id = $user['cluster_id'];
		$enc_user = AddSlashes($user['id']);

		# TO DO: group by venue_id in memory since the following will always
		# result in a filesort (20120301/straup)

		$sql = "SELECT venue_id, COUNT(id) AS count FROM PrivatesquareCheckins WHERE user_id='{$enc_user}'";
		$sql .= " AND latitude BETWEEN {$bbox[0]} AND {$bbox[2]} AND longitude BETWEEN {$bbox[1]} AND {$bbox[3]}";
		$sql .= " GROUP BY venue_id";

		$rsp = db_fetch_users($cluster_id, $sql, $more);

		if (! $rsp['ok']){
			return $rsp;
		}

		$tmp = array();

		foreach ($rsp['rows'] as $row){
			$tmp[$row['venue_id']] = $row['count'];
		}

		arsort($tmp);

		$venues = array();

		foreach ($tmp as $venue_id => $count){
			$venue = foursquare_venues_get_by_venue_id($venue_id); 
			$venue['count_checkins'] = $count;
			$venues[] = $venue;
		}

		return okay(array('rows' => $venues));
	}

 	#################################################################

	# Note the need to pass $user because we don't have a lookup
	# table for checkin IDs, maybe we should... (20120218/straup)

	function privatesquare_checkins_get_by_id(&$user, $id){

		if (is_numeric($id)){
			$row = privatesquare_checkins_get_by_privatesquare_id($user, $id);
		}

		else {
			$row = privatesquare_checkins_get_by_foursquare_id($user, $id);
		}

		if ($row){
			privatesquare_checkins_inflate_extras($row);
		}

		return $row;
	}

 	#################################################################

	function privatesquare_checkins_get_by_privatesquare_id(&$user, $id){

		$cluster_id = $user['cluster_id'];

		$enc_user = AddSlashes($user['id']);
		$enc_id = AddSlashes($id);

		$sql = "SELECT * FROM PrivatesquareCheckins WHERE user_id='{$enc_user}' AND id='{$enc_id}'";
		return db_single(db_fetch_users($cluster_id, $sql));
	}

 	#################################################################

	function privatesquare_checkins_get_by_foursquare_id(&$user, $id){

		$cluster_id = $user['cluster_id'];

		$enc_user = AddSlashes($user['id']);
		$enc_id = AddSlashes($id);

		$sql = "SELECT * FROM PrivatesquareCheckins WHERE user_id='{$enc_user}' AND checkin_id='{$enc_id}'";
		return db_single(db_fetch_users($cluster_id, $sql));
	}

 	#################################################################
?>
