<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function customproxmox_MetaData() {
    return [
        'DisplayName' => 'Custom Proxmox LXC',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
    ];
}

function customproxmox_ConfigOptions() {
    return [
        'vmid' => [
            'FriendlyName' => 'VMID / Container ID',
            'Type' => 'text',
            'Size' => '5',
            'Description' => 'Leer lassen für automatische Zuweisung (Service-ID + 1000)',
        ],
        'ostemplate' => [
            'FriendlyName' => 'OS Template',
            'Type' => 'text',
            'Size' => '35',
            'Default' => 'test:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst',
            'Description' => 'Storage und Template-Pfad',
        ],
        'cores' => [
            'FriendlyName' => 'CPU Cores',
            'Type' => 'text',
            'Size' => '3',
            'Default' => '1',
        ],
        'memory' => [
            'FriendlyName' => 'RAM (MB)',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '1024',
        ],
        'disk' => [
            'FriendlyName' => 'Disk Size (GB)',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '10',
        ],
        'bridge' => [
            'FriendlyName' => 'Netzwerk Brücke',
            'Type' => 'text',
            'Size' => '15',
            'Default' => 'vmbrcc1',
        ],
        'ipmode' => [
            'FriendlyName' => 'IP-Modus',
            'Type' => 'dropdown',
            'Options' => 'dhcp,static',
            'Default' => 'static',
        ],
        'gateway' => [
            'FriendlyName' => 'Standard Gateway',
            'Type' => 'text',
            'Size' => '20',
            'Default' => '172.70.0.1',
            'Description' => 'Nur bei statischer IP',
        ],
    ];
}

function customproxmox_GetApiUrl($params) {
    $serverIp = $params['serverip'];
    $port = 8006; 
    return "https://{$serverIp}:{$port}/api2/json";
}

function customproxmox_GetApiTokens($params) {
    $serverUser = $params['serverusername'];
    $serverPass = $params['serverpassword'];
    
    if (strpos($serverUser, '@') === false) {
        $serverUser .= '@pam';
    }

    $loginData = http_build_query([
        'username' => $serverUser,
        'password' => $serverPass
    ]);

    $url = customproxmox_GetApiUrl($params) . '/access/ticket';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $loginData);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return false;
    }

    $authData = json_decode($response, true);
    return [
        'ticket' => $authData['data']['ticket'] ?? '',
        'csrf' => $authData['data']['CSRFPreventionToken'] ?? '',
        'username' => $authData['data']['username'] ?? $serverUser
    ];
}

function customproxmox_GetContainerVmid($params) {
    if (!empty($params['domain']) && is_numeric($params['domain'])) {
        return $params['domain'];
    }
    if (!empty($params['serviceid'])) {
        $service = Capsule::table('tblhosting')->where('id', (int)$params['serviceid'])->first();
        if ($service && !empty($service->domain) && is_numeric($service->domain)) {
            return $service->domain;
        }
    }
    if (!empty($params['configoption1'])) {
        return $params['configoption1'];
    }
    if (!empty($params['serviceid'])) {
        return 1000 + (int)$params['serviceid'];
    }
    return '';
}

function customproxmox_TestConnection($params) {
    try {
        $tokens = customproxmox_GetApiTokens($params);
        if (!$tokens) {
            return [
                'success' => false,
                'error' => 'Authentifizierung bei Proxmox fehlgeschlagen. Prüfe IP, Port 8006 und Zugangsdaten.',
            ];
        }
        return [
            'success' => true,
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function customproxmox_CreateAccount($params) {
    $node = !empty($params['serverhostname']) ? $params['serverhostname'] : 'pve';
    
    $vmid = !empty($params['configoption1']) ? $params['configoption1'] : '';
    if (empty($vmid) && !empty($params['serviceid'])) {
        $vmid = 1000 + (int)$params['serviceid'];
    }
    if (empty($vmid)) {
        $vmid = rand(1000, 9999);
    }

    $ostemplate = $params['configoption2'];
    $cores = $params['configoption3'];
    $memory = $params['configoption4'];
    $disk = $params['configoption5'];
    $bridge = $params['configoption6'] ?: 'vmbrcc1';
    $ipmode = $params['configoption7'];
    $gateway = $params['configoption8'];

    $assignedIp = !empty($params['ip']) ? $params['ip'] : '';

    if ($ipmode === 'dhcp') {
        $netConfig = "name=eth0,bridge={$bridge},ip=dhcp";
        $displayIp = 'DHCP';
    } else {
        if (empty($assignedIp) || $assignedIp === '172.31.32.1') {
            return "Fehler: Keine gültige IP-Adresse für diesen Service zugewiesen.";
        }
        $displayIp = explode('/', $assignedIp)[0];
        $netConfig = "name=eth0,bridge={$bridge},ip={$assignedIp},gw={$gateway}";
    }

    $tokens = customproxmox_GetApiTokens($params);
    if (!$tokens) {
        return "Proxmox API Login fehlgeschlagen.";
    }

    $postFields = http_build_query([
        'vmid' => $vmid,
        'ostemplate' => $ostemplate,
        'cores' => $cores,
        'memory' => $memory,
        'rootfs' => "local-lvm:{$disk}",
        'net0' => $netConfig,
        'ostype' => 'debian',
        'unprivileged' => 1,
        'start' => 1
    ]);

    $url = customproxmox_GetApiUrl($params) . "/nodes/{$node}/lxc";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$tokens['csrf']}",
        "Cookie: PVEAuthCookie={$tokens['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return "Fehler beim Erstellen des LXC-Containers: $response";
    }

    if (!empty($params['serviceid'])) {
        Capsule::table('tblhosting')
            ->where('id', (int)$params['serviceid'])
            ->update([
                'domain' => $vmid,
                'dedicatedip' => $displayIp
            ]);
    }

    return 'success';
}

function customproxmox_TerminateAccount($params) {
    $node = !empty($params['serverhostname']) ? $params['serverhostname'] : 'pve';
    $vmid = customproxmox_GetContainerVmid($params);

    $tokens = customproxmox_GetApiTokens($params);
    if (!$tokens) return "Proxmox API Login fehlgeschlagen.";

    $headers = [
        "CSRFPreventionToken: {$tokens['csrf']}",
        "Cookie: PVEAuthCookie={$tokens['ticket']}",
        "Content-Length: 0"
    ];

    $baseUrl = customproxmox_GetApiUrl($params);

    $ch = curl_init("{$baseUrl}/nodes/{$node}/lxc/{$vmid}/status/stop");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    curl_close($ch);

    sleep(3);

    $ch = curl_init("{$baseUrl}/nodes/{$node}/lxc/{$vmid}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return "Fehler beim Löschen des LXC-Containers: $response";
    return 'success';
}

function customproxmox_SuspendAccount($params) {
    $node = !empty($params['serverhostname']) ? $params['serverhostname'] : 'pve';
    $vmid = customproxmox_GetContainerVmid($params);
    $tokens = customproxmox_GetApiTokens($params);
    if (!$tokens) return "Proxmox API Login fehlgeschlagen.";

    $headers = [
        "CSRFPreventionToken: {$tokens['csrf']}",
        "Cookie: PVEAuthCookie={$tokens['ticket']}",
        "Content-Length: 0"
    ];

    $ch = curl_init(customproxmox_GetApiUrl($params) . "/nodes/{$node}/lxc/{$vmid}/status/stop");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    curl_close($ch);

    return 'success';
}

function customproxmox_UnsuspendAccount($params) {
    $node = !empty($params['serverhostname']) ? $params['serverhostname'] : 'pve';
    $vmid = customproxmox_GetContainerVmid($params);
    $tokens = customproxmox_GetApiTokens($params);
    if (!$tokens) return "Proxmox API Login fehlgeschlagen.";

    $headers = [
        "CSRFPreventionToken: {$tokens['csrf']}",
        "Cookie: PVEAuthCookie={$tokens['ticket']}",
        "Content-Length: 0"
    ];

    $ch = curl_init(customproxmox_GetApiUrl($params) . "/nodes/{$node}/lxc/{$vmid}/status/start");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    curl_close($ch);

    return 'success';
}

// -------------------------------------------------------------------------
// CLIENT AREA LIVE HOSTING INFORMATION (TWENTY-ONE THEME OVERRIDE FIX)
// -------------------------------------------------------------------------

function customproxmox_ClientArea($params) {
    $node = !empty($params['serverhostname']) ? $params['serverhostname'] : 'pve';
    $vmid = customproxmox_GetContainerVmid($params);
    $serviceId = $params['serviceid'] ?? 0;
    
    $ipv4 = '172.70.0.103';
    $ipv6 = '-';
    $status = 'stopped';
    $consoleUrl = '';

    $tokens = customproxmox_GetApiTokens($params);
    if ($tokens && !empty($vmid)) {
        $headers = [
            "CSRFPreventionToken: {$tokens['csrf']}",
            "Cookie: PVEAuthCookie={$tokens['ticket']}"
        ];

        // 1. Live-Status abrufen
        $url = customproxmox_GetApiUrl($params) . "/nodes/{$node}/lxc/{$vmid}/status/current";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
            $data = json_decode($response, true);
            $status = $data['data']['status'] ?? 'stopped';
        }
        curl_close($ch);

        // 2. IPv4 aus der Container-Config (net0) auslesen
        $configUrl = customproxmox_GetApiUrl($params) . "/nodes/{$node}/lxc/{$vmid}/config";
        $ch = curl_init($configUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $configResponse = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
            $configData = json_decode($configResponse, true);
            $net0 = $configData['data']['net0'] ?? '';
            if (preg_match('/ip=([0-9.]+)/', $net0, $matches)) {
                $ipv4 = $matches[1];
            }
        }
        curl_close($ch);

        // 3. Konsolen-URL generieren
        $serverIp = $params['serverip'];
        $consoleUrl = "https://{$serverIp}:8006/?console=lxc&xtermjs=1&vmid={$vmid}&vmname=CT{$vmid}&node={$node}";
    }

    // 4. IPv6 direkt aus den gebuchten WHMCS-Addons dieses Services auslesen!
    if ($serviceId > 0) {
        $addon = Capsule::table('tblhostingaddons')
            ->join('tbladdons', 'tbladdons.id', '=', 'tblhostingaddons.addonid')
            ->where('tblhostingaddons.hostingid', $serviceId)
            ->where('tblhostingaddons.status', 'Active')
            ->select('tblhostingaddons.name', 'tbladdons.name as addonname')
            ->first();

        // Wenn der Addon-Name oder ein Domain/Name-Feld eine IPv6 ist
        if ($addon) {
            // Wir prüfen via Regex, ob in dem Addon-Namen eine IPv6 steht
            $addonText = $addon->name ?: $addon->addonname;
            if (preg_match('/([a-fA-F0-9:]+:+[a-fA-F0-9:]+)/', $addonText, $ipv6Match)) {
                $ipv6 = $ipv6Match[1];
            }
        }

        // Fallback: Direkte Suche in tblhostingaddons nach einer IPv6 im Namen
        if ($ipv6 === '-') {
            $addons = Capsule::table('tblhostingaddons')->where('hostingid', $serviceId)->where('status', 'Active')->get();
            foreach ($addons as $ad) {
                if (filter_var($ad->name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $ipv6 = $ad->name;
                    break;
                }
            }
        }
    }

    // Datenbank synchronisieren
    if (!empty($serviceId) && !empty($ipv4)) {
        Capsule::table('tblhosting')
            ->where('id', (int)$serviceId)
            ->update([
                'domain' => $vmid,
                'dedicatedip' => $ipv4
            ]);
    }

    return [
        'overridevars' => [
            'ip' => $ipv4,
            'dedicatedip' => $ipv4,
            'serverip' => $ipv4,
            'domain' => $vmid,
        ],
        'templateVariables' => [
            'vmid' => $vmid,
            'containerStatus' => ucfirst($status),
            'containerIp' => $ipv4,
            'containerIpv4' => $ipv4,
            'containerIpv6' => $ipv6,
            'consoleUrl' => $consoleUrl,
            'serverip' => $ipv4,
            'service' => [
                'id' => $serviceId,
                'domain' => $vmid,
                'dedicatedip' => $ipv4,
                'ip' => $ipv4,
            ]
        ],
    ];
}

// -------------------------------------------------------------------------
// CUSTOM CLIENT AREA BUTTONS
// -------------------------------------------------------------------------

function customproxmox_ClientAreaCustomButtonArray() {
    return [
        "Container Starten" => "start_container",
        "Container Stoppen" => "stop_container",
        "Container Neustarten" => "reboot_container",
    ];
}

function customproxmox_start_container($params) {
    $node = !empty($params['serverhostname']) ? $params['serverhostname'] : 'pve';
    $vmid = customproxmox_GetContainerVmid($params);
    $tokens = customproxmox_GetApiTokens($params);
    if (!$tokens) return "Proxmox API Login fehlgeschlagen.";

    $headers = [
        "CSRFPreventionToken: {$tokens['csrf']}",
        "Cookie: PVEAuthCookie={$tokens['ticket']}",
        "Content-Length: 0"
    ];

    $ch = curl_init(customproxmox_GetApiUrl($params) . "/nodes/{$node}/lxc/{$vmid}/status/start");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return "Fehler beim Starten: $response";
    return 'success';
}

function customproxmox_stop_container($params) {
    $node = !empty($params['serverhostname']) ? $params['serverhostname'] : 'pve';
    $vmid = customproxmox_GetContainerVmid($params);
    $tokens = customproxmox_GetApiTokens($params);
    if (!$tokens) return "Proxmox API Login fehlgeschlagen.";

    $headers = [
        "CSRFPreventionToken: {$tokens['csrf']}",
        "Cookie: PVEAuthCookie={$tokens['ticket']}",
        "Content-Length: 0"
    ];

    $ch = curl_init(customproxmox_GetApiUrl($params) . "/nodes/{$node}/lxc/{$vmid}/status/stop");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return "Fehler beim Stoppen: $response";
    return 'success';
}

function customproxmox_reboot_container($params) {
    $node = !empty($params['serverhostname']) ? $params['serverhostname'] : 'pve';
    $vmid = customproxmox_GetContainerVmid($params);
    $tokens = customproxmox_GetApiTokens($params);
    if (!$tokens) return "Proxmox API Login fehlgeschlagen.";

    $headers = [
        "CSRFPreventionToken: {$tokens['csrf']}",
        "Cookie: PVEAuthCookie={$tokens['ticket']}",
        "Content-Length: 0"
    ];

    $ch = curl_init(customproxmox_GetApiUrl($params) . "/nodes/{$node}/lxc/{$vmid}/status/reboot");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return "Fehler beim Neustarten: $response";
    return 'success';
}
