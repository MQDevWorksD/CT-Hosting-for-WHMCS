<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function customproxmox_manager_config() {
    return [
        "name" => "Proxmox LXC Manager",
        "description" => "Zentrales Management für Proxmox LXC Container, IPs und xterm.js Konsolen.",
        "version" => "1.7",
        "author" => "Moritz",
        "fields" => []
    ];
}

function customproxmox_manager_activate() {
    return [
        'status' => 'success',
        'description' => 'Proxmox LXC Manager erfolgreich aktiviert.'
    ];
}

function customproxmox_manager_output($vars) {
    $modulelink = $vars['modulelink'];

    $services = Capsule::table('tblhosting')
        ->join('tblservers', 'tblservers.id', '=', 'tblhosting.server')
        ->join('tblclients', 'tblclients.id', '=', 'tblhosting.userid')
        ->where('tblservers.type', 'customproxmox')
        ->select(
            'tblhosting.id as serviceid',
            'tblhosting.domain as vmid',
            'tblhosting.dedicatedip as ipv4',
            'tblhosting.userid',
            'tblclients.firstname',
            'tblclients.lastname',
            'tblservers.ipaddress as serverip',
            'tblservers.hostname as serverhostname'
        )
        ->get();

    echo '<div class="conitem"><h2>Proxmox LXC Fleet Manager</h2><p>Übersicht aller Container, IP-Zuweisungen und Direktzugriff auf die Konsolen.</p>';
    echo '<table class="table table-striped table-hover"><thead><tr>
        <th>Service ID</th>
        <th>Kunde</th>
        <th>VMID</th>
        <th>Node</th>
        <th>IPv4</th>
        <th>IPv6 (Addon)</th>
        <th>Aktionen</th>
    </tr></thead><tbody>';

    if ($services->isEmpty()) {
        echo '<tr><td colspan="7" class="text-center">Keine Proxmox LXC Services gefunden.</td></tr>';
    }

    foreach ($services as $service) {
        $serviceId = $service->serviceid;
        $vmid = $service->vmid ?: (1000 + $serviceId);
        $node = $service->serverhostname ?: 'pve';
        $ipv4 = $service->ipv4 ?: '-';

        $ipv6 = '-';
        $addons = Capsule::table('tblhostingaddons')->where('hostingid', $serviceId)->get();
        foreach ($addons as $ad) {
            if (filter_var($ad->name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) || preg_match('/([a-fA-F0-9:]+:+[a-fA-F0-9:]+)/', $ad->name)) {
                preg_match('/([a-fA-F0-9:]+:+[a-fA-F0-9:]+)/', $ad->name, $m);
                $ipv6 = $m[1] ?? $ad->name;
                break;
            }
        }

        // Exaktes Konsolen-Schema mit dynamischen Parametern
        $consoleUrl = "https://{$service->serverip}:8006/?console=lxc&xtermjs=1&vmid={$vmid}&vmname=CT{$vmid}&node={$node}&cmd=";

        echo "<tr>";
        echo "<td><a href='clientsservices.php?id={$serviceId}' target='_blank'>#{$serviceId}</a></td>";
        echo "<td>{$service->firstname} {$service->lastname}</td>";
        echo "<td><strong>{$vmid}</strong></td>";
        echo "<td>{$node}</td>";
        echo "<td>{$ipv4}</td>";
        echo "<td><code>{$ipv6}</code></td>";
        echo "<td>
            <a href='{$consoleUrl}' target='_blank' rel='noopener noreferrer' class='btn btn-xs btn-primary'><i class='fas fa-terminal'></i> Konsole</a>
            <a href='clientsservices.php?userid={$service->userid}&id={$serviceId}' target='_blank' class='btn btn-xs btn-default'><i class='fas fa-cog'></i> Service</a>
        </td>";
        echo "</tr>";
    }

    echo '</tbody></table></div>';
}
