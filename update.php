<?php
require_once('config/config.php');

// Report ALL
error_reporting(E_ALL);

// API authorization
$context = stream_context_create(array(
    'http' => array(
        'header'  => "Authorization: Basic " . base64_encode(API_USER . ':' . API_PASS)
    )
));

// START load data from Adminus CRM
$json = file_get_contents(API_HOST . '/contract-type-state/all', false, $context);
$states = json_decode($json);

$json = file_get_contents(API_HOST . '/contract/all', false, $context);
$contracts = json_decode($json);

$json = file_get_contents(API_HOST . '/ip-address/all', false, $context);
$ips = json_decode($json);


$blockStates = array();
if (is_array($states->data)) foreach($states->data as $state)
{
    if (in_array($state->name, BLOCKED_STATES)) $blockStates[] = $state->id;
}
else die('Failure when loading contract states from Adminus CRM' . "\n");


$blockContracts = array();
if (is_array($contracts->data)) foreach($contracts->data as $contract)
{
    if (in_array($contract->state, $blockStates)) $blockContracts[] = $contract->id;
}
else die('Failure when loading contracts from Adminus CRM' . "\n");

$blockIPs = array();
if (is_array($ips->data)) foreach($ips->data as $ip)
{
    if (in_array($ip->contractId, $blockContracts)) $blockIPs[] = $ip->ip;
}
else die('Failure when loading IPs from Adminus CRM' . "\n");
// END load data from Adminus CRM
//var_dump($blockIPs);

// START update RouterOS address-list
require_once('class/routeros-api.class.php');

$API = new RouterosAPI();
$API->debug = false;

$routers = MT_API_HOSTS;
$table = MT_API_ADDRESS_LIST;

foreach ($routers as $routernumber => $router)
{
	echo 'connecting to ' . $router . '...';

	if ($API->connect($router, MT_API_USER, MT_API_PASS))
	{
		echo 'OK' . "\n";
		$API->write('/ip/firewall/address-list/print', false);
		$API->write('?list=' . $table, false);
		$API->write('=.proplist=.id');
		$ARRAY = $API->read();
		//print_r($ARRAY);

		foreach ($ARRAY as $ITEM)
		{
			$API->write('/ip/firewall/address-list/remove', false);
			$API->write('=.id=' . $ITEM['.id']);
			$ARRAY = $API->read();
			//print_r($ARRAY);
		}

		if (is_array($blockIPs)) foreach ($blockIPs as $blockIP)
		{
			$API->write('/ip/firewall/address-list/add', false);
			$API->write('=address=' . $blockIP, false);
			$API->write('=list=' . $table);
			$ARRAY = $API->read();
			//print_r($ARRAY);
		};

		$API->write('/ip/firewall/address-list/print', false);
		$API->write('?list=' . $table, false);
		$API->write('=.proplist=address');
		$ARRAY = $API->read();
		//print_r($ARRAY);

		$API->disconnect();

		echo 'Blocked IPs:' . "\n";
		foreach ($ARRAY as $ip) echo $ip['address'] . "\n";
	}
	else echo 'FAULT' . "\n";
	echo "\n";
}
// END update RouterOS address-list
?>