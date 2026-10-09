# Custom Proxmox LXC Module for WHMCS

Ein robustes und leichtgewichtiges WHMCS Server-Modul zur automatisierten Bereitstellung, Verwaltung und Überwachung von Proxmox VE LXC-Containern über die Proxmox REST-API (kompatibel mit Proxmox v8/v9 und WHMCS v9.0+). 

Das Modul integriert sich nahtlos in das WHMCS-Kundencenter (Getestet mit dem *Twenty-One*-Theme), synchronisiert Live-Status sowie IP-Adressen (IPv4 & IPv6-Addon-Erkennung) und bietet einen direkten, token-gesicherten Zugriff auf die native Proxmox xterm.js-Konsole.

---

## Features

- **Automated Provisioning:** Erstellt unprivilegierte LXC-Container auf dem angegebenen Proxmox-Node mit individuell konfigurierbaren CPU-, RAM-, Festplatten- und Netzwerkparametern.
- **Dynamic IP Management:** 
  - **IPv4:** Automatisches Auslesen der primären IP direkt aus der Proxmox-Container-Konfiguration (`net0`).
  - **IPv6:** Automatisches Erkennen und Auslesen von IPv6-Adressen aus gebuchten WHMCS-Produkt-Addons (`tblhostingaddons`).
- **Token-Authenticated xterm.js Console:** Generiert on-the-fly kurzlebige, sichere API-Tickets, sodass Kunden direkt aus dem WHMCS-Kundencenter heraus die native Proxmox-Konsole in einem neuen Tab öffnen können.
- **Full Lifecycle Management:** Unterstützt automatisiertes Starten, Stoppen, Neustarten, Suspendieren, Unsuspendieren und das vollständige Löschen (Termination) der Container.
- **Database Synchronization:** Synchronisiert VMIDs und IP-Adressen automatisch mit der WHMCS-Datenbank (`tblhosting`).

---

## Voraussetzungen

- **WHMCS:** v9.0 oder neuer (getestet mit PHP 8.4)
- **Proxmox VE:** v8.x / v9.x mit aktiviertem API-Zugriff
- **Webserver:** Linux-Umgebung mit aktiviertem PHP cURL und JSON-Erweiterung

---


Kopiere den Ordner `customproxmox` in das Server-Modul-Verzeichnis deiner WHMCS-Installation:
```text
/var/www/html/modules/servers/customproxmox/
