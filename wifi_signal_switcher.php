<?php
	//Remote connection config file
	$config = "config.php";

	//Static Machine List
	$machineList_static = array(
		"Computer_A" => "192.168.1.101",
		"Computer_B" => "192.168.1.102",
		"Computer_C" => "192.168.1.103"
	);
	
	//Dynamic Machine List
	$machineList_dynamic = array(
		"Mobile_A" => "192.168.1.104",
		"Mobile_B" => "192.168.1.105",
		"Mobile_C" => "192.168.1.106"
	);
	
	//Network test ip at initial
	$gateway = "192.168.1.254";
	
	//Define High /low emission
	$high_rate = "15";
	$low_rate  = "1";
	
	//Wi-Fi Device id
	$device_id = "0";
	
	//Offline retry wait
	$offline_retry = "3";
	
	//Online consistent definition in seconds
	$static_consistent_seconds = "30";
	$dynamic_consistent_seconds = "15";
	
	echo "Hold for the network up, program will be start immediately...\n";
	while (true)
	{	
		exec("/bin/ping -c 1 $gateway", $outcome, $status);
		if ($status == 0) {break;} else {continue;}
	}
	echo "Program Started.\n";

	require_once($config);
    $connection = ssh2_connect(IP, PORT);
    ssh2_auth_password($connection, LOGIN, PASSWARD);
	echo "Configuration found and set\n";

	$machine = array_merge($machineList_static, $machineList_dynamic);
	foreach ($machine as $key => $value)
	{echo $value." (".$key.") added into checking list!\n";}

	echo "Checking current emit state...\n";
	$run = ssh2_exec($connection, "uci show wireless.@wifi-device[$device_id].txpower");
    stream_set_blocking($run, true);
    $emit_state = intval(substr(stream_get_contents($run), 24));
	fclose($run);
	echo "Checking completed.\n";

	$low_emit = true;
	echo "Emit state value: ".$emit_state." dBm\n";
	if ($emit_state > 1) {$low_emit = false; echo "Emit now is high\n";} else {echo "Emit now is low\n";}
	$off_count = 0;
	
	function pingAddress($ip) {
		exec("/bin/ping -c 1 $ip", $outcome, $status);
		if ($status == 0) {return true;} 
		else {return false;}
	}

	function detectedOnline($target) {
		foreach ($target as $key => $value)
		{
			if (pingAddress($value)) 
			{
				$GLOBALS['first_detectedOnline_ip'] = $value; 
				$GLOBALS['first_detectedOnline_name'] = $key; 
				return true;
			}
		}
		return false;
	}
	
	while (true)
	{
		echo "Running check loop...\n";
		while (detectedOnline($machine))
		{
			echo "Online host detected!\n";
			if ($low_emit)
			{
				
				if (in_array($first_detectedOnline_ip, $machineList_static)) {$consistent_seconds = $static_consistent_seconds;}
				else if (in_array($first_detectedOnline_ip, $machineList_dynamic)) {$consistent_seconds = $dynamic_consistent_seconds;}
				
				echo "Current state is low emit, re-check if the first detected host $first_detectedOnline_name ($first_detectedOnline_ip) is consistently online, check in $consistent_seconds seconds...\n";
				sleep($consistent_seconds);
				if (pingAddress($first_detectedOnline_ip)) 
				{
					echo $first_detectedOnline_ip." ($first_detectedOnline_name) Confirmed consistent online, changing into high emit...\n";
					$run = ssh2_exec($connection, "uci set wireless.@wifi-device[$device_id].txpower=$high_rate; uci commit wireless; wifi down radio$device_id; wifi up radio$device_id");
       				fclose($run);
       				$low_emit = false;
					echo "High emit is set.\n";
				}
				else {echo "Detected online host seems has been offline, holding state of low emit\n";}
			}
			else {echo "Current state is high emilt, no changes needed.\n";}
			$off_count = 0;
		}
		
		$off_count++;
		echo "No online host detected in this loop. Checking current emit state...\n";
		
		if ($low_emit)
		{echo "Low emit. No changes needed.\n";}
		else
		{echo "High emit. State will be change after ".($offline_retry - $off_count)." more loops\n";}

		if ($off_count >= $offline_retry && $low_emit == false)
		{
			echo "Changing state into low emit...\n";
			$run = ssh2_exec($connection, "uci set wireless.@wifi-device[$device_id].txpower=$low_rate; uci commit wireless; wifi down radio$device_id; wifi up radio$device_id");
       		fclose($run);
			$low_emit = true;
			echo "Low emit is set.\n";
		}
	}
