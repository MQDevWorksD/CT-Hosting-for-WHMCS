<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

add_hook('ClientAreaPageProductDetails', 1, function($vars) {
    $serviceId = $vars['id'];
    
    $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
    if (!$service) return;

    $vmid = trim($service->domain);
    if (!is_numeric(ltrim($vmid, '#'))) return;
    $cleanVmid = intval(ltrim($vmid, '#'));

    $containerMap = [
        1006 => ['ipv4' => '172.70.0.103', 'ipv6' => 'fe80::be24:11ff:feb8:5be1'],
        1007 => ['ipv4' => '172.70.0.104', 'ipv6' => 'fe80::be24:11ff:feb8:5be1'],
        1008 => ['ipv4' => '172.70.0.105', 'ipv6' => 'fe80::be24:11ff:feb8:4c4'],
        1010 => ['ipv4' => '172.70.0.107', 'ipv6' => 'fe80::be24:11ff:feb8:1010']
    ];

    if (isset($containerMap[$cleanVmid])) {
        $ipv4 = $containerMap[$cleanVmid]['ipv4'];
        $ipv6 = $containerMap[$cleanVmid]['ipv6'];
    } else {
        $lastOctet = 103 + ($cleanVmid - 1006);
        $ipv4 = "172.70.0." . $lastOctet;
        $ipv6 = "fe80::be24:11ff:feb8:" . dechex($cleanVmid);
    }

    return [
        'containerIp' => $ipv4,
        'containerIpv6' => $ipv6,
        'dedicatedip' => $ipv4,
        'assignedips' => $ipv4
    ];
});
