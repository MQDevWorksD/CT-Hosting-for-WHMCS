import subprocess
import sys

def run_updates():
    print("==================================================")
    print("   Proxmox LXC & Infrastructure Remote Updater    ")
    print("==================================================\n")
    
    host = input("Proxmox Server-IP / Hostname: ").strip()
    if not host:
        print("[!] Keine IP angegeben. Abbruch.")
        input("\nDrücke ENTER zum Beenden...")
        return
        
    user = input("SSH-Benutzer [root]: ").strip()
    if not user:
        user = "root"
        
    print(f"\n[*] Verbinde mit {user}@{host} (nutzt Windows OpenSSH)...")
    print("--------------------------------------------------")

    # 1. Laufende Container abfragen (erste Verbindung zur Erkennung)
    list_cmd = f"ssh -o StrictHostKeyChecking=no {user}@{host} \"pct list\""
    
    result = subprocess.run(list_cmd, shell=True, capture_output=True, text=True)
    
    if result.returncode != 0:
        print(f"[!] Verbindungsfehler oder Authentifizierung fehlgeschlagen:\n{result.stderr.strip()}")
        input("\nDrücke ENTER, um das Fenster zu schließen...")
        return

    ct_list = []
    for line in result.stdout.splitlines():
        if "running" in line:
            parts = line.split()
            if parts and parts[0].isdigit():
                ct_list.append(parts[0])

    if not ct_list:
        print("[-] Keine laufenden Container gefunden.")
        input("\nDrücke ENTER, um das Fenster zu schließen...")
        return

    print(f"[+] Gefundene Container: {', '.join(ct_list)}\n")
    print("--------------------------------------------------")

    # 2. Alle Container-Updates in einen EINZIGEN Befehlsstrang zusammenfassen!
    remote_commands = []
    for vmid in ct_list:
        cmd_block = (
            f"echo '==================================================' && "
            f"echo '>>> Starte Update für Container CT {vmid} <<<' && "
            f"echo '==================================================' && "
            f"pct exec {vmid} -- bash -c '"
            "export DEBIAN_FRONTEND=noninteractive; "
            "apt-get update -y && "
            "apt-get dist-upgrade -y -o Dpkg::Options::=\"--force-confdef\" -o Dpkg::Options::=\"--force-confold\" && "
            "apt-get autoremove -y && "
            "apt-get clean' && "
            f"echo '[+] Container CT {vmid} erfolgreich aktualisiert!'"
        )
        remote_commands.append(cmd_block)

    # Alle Container-Befehle mit ' && ' verketten, damit sie in einer einzigen SSH-Session laufen
    combined_remote_cmd = " && ".join(remote_commands)
    
    full_ssh_cmd = f"ssh -o StrictHostKeyChecking=no {user}@{host} \"{combined_remote_cmd}\""
    
    print("[*] Starte Update-Prozess auf dem Proxmox-Server...")
    print("    (Das Passwort wird hier nur einmal abgefragt)\n")
    
    # Einziger SSH-Aufruf für den gesamten Update-Durchlauf
    process = subprocess.run(full_ssh_cmd, shell=True)
    
    print("\n==================================================")
    if process.returncode == 0:
        print("   Alle Updates erfolgreich abgeschlossen!        ")
    else:
        print("   Update-Prozess beendet (eventuell mit Warnungen).")
    print("==================================================")

if __name__ == "__main__":
    try:
        run_updates()
    except KeyboardInterrupt:
        print("\n[!] Abbruch durch Benutzer.")
    except Exception as e:
        print(f"\n[!] Unerwarteter Fehler: {e}")
    
    input("\nDrücke ENTER, um das Fenster zu schließen...")