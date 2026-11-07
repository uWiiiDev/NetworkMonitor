<?php
/**
 * NetworkMonitor - Konfigurationsdatei
 * Kompatibel mit PHP 5.5+ (Ubuntu 14.04) bis PHP 8.x
 */

return array(
    // Datenbank-Konfiguration
    // 'driver' kann 'auto', 'mysql' oder 'sqlite' sein.
    // Bei 'auto' wird zuerst MySQL versucht; falls der lokale MySQL-Dienst nicht läuft,
    // nutzt das Tool automatisch SQLite (in data/network_monitor.sqlite), damit lokal
    // sofort getestet werden kann.
    'db' => array(
        'driver'   => 'auto',
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'dbname'   => 'network_monitor',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8',
        'sqlite_path' => __DIR__ . '/data/network_monitor.sqlite',
    ),

    // Scanner-Standardeinstellungen (Multi-Host-Batch-Turbo-Modus)
    'scanner' => array(
        // Maximale Anzahl IP-Adressen pro einzelnem Scope (4096 = bis zu /20 Subnetz pro Scope)
        'max_hosts_per_scan' => 4096,
        // Ping-Timeout in Sekunden
        'ping_timeout'       => 1,
        // Parallelität beim schnellen Ping-Sweep (Anzahl gleichzeitiger Pings)
        'ping_batch_size'    => 128,
        // Anzahl der Hosts, deren TCP-Ports gleichzeitig in einem Batch geprüft werden
        'port_host_batch_size' => 32,
        // Anzahl der Hosts, die gleichzeitig per UDP auf SNMPv2c vorgeprüft werden
        'snmp_batch_size'    => 100,
        // TCP-Ports, die zur Geräte-Erkennung geprüft werden
        'probe_ports'        => array(
            22   => 'SSH',
            23   => 'Telnet',
            53   => 'DNS',
            80   => 'HTTP',
            139  => 'NetBIOS',
            161  => 'SNMP',
            443  => 'HTTPS',
            445  => 'SMB',
            515  => 'LPD-Printer',
            631  => 'IPP-Printer',
            3306 => 'MySQL',
            3389 => 'RDP',
            8080 => 'HTTP-Alt',
            8443 => 'HTTPS-Alt',
            9100 => 'JetDirect-Printer',
        ),
        // Timeout für TCP-Port-Check in Millisekunden
        'port_timeout_ms'    => 350,
        // Standard SNMP-Community für Managed Switches
        'default_snmp_community' => 'public',
        'snmp_timeout_ms'        => 800,
        // Automatische Datenbank-Bereinigung (Retention), damit die DB nicht vollläuft:
        // Scan-Protokolle (scan_logs) und Alarme (device_alerts) älter als X Tage automatisch löschen (0 = nie löschen)
        'retention_days'         => 14,
        // Offline-Geräte (ohne eigenen Namen/Notiz), die seit mehr als X Tagen nicht mehr gesehen wurden, löschen (0 = nie löschen)
        'stale_offline_days'     => 30,
        // Standard-Intervall für den automatischen Hintergrund-Cronjob in Minuten (z.B. 15)
        'cron_interval_minutes'  => 15,
        // Automatische Anomalie- & Sicherheits-Wächter (Layer 2 / Layer 3 Watchdogs):
        // 1. ARP-Spoofing / IP-Adresskonflikt-Alarm (MAC-Adresse einer bekannten IP ändert sich plötzlich)
        'anomaly_arp_spoofing'           => 1,
        // 2. Alarm bei neuen, noch nie gesehenen MAC-Adressen im Netzwerk / an einem Switch-Port
        'anomaly_new_mac'                => 1,
        // 3. Rogue-Switch / Mehrfach-MAC-Warnung: Max. erlaubte Endgeräte-MACs an einem einzelnen Access-Port (0 = deaktiviert)
        'anomaly_rogue_switch_max_macs'  => 3,
        // 4a. Switch-Port Paketfehler-Schwellenwert (ifInErrors pro Port / Anstieg, 0 = deaktiviert)
        'anomaly_port_error_threshold'   => 50,
        // 4b. MAC-Flapping / Loop-Alarm: Anzahl unterschiedlicher Switch-Ports einer MAC innerhalb von 60 Min. (0 = deaktiviert)
        'anomaly_mac_flapping_threshold' => 3,
    ),

    // SMTP-Einstellungen für E-Mail-Alarm-Benachrichtigungen (im Adminbereich konfigurierbar)
    'smtp' => array(
        'enabled'    => 0,
        'host'       => '',
        'port'       => 587,
        'encryption' => 'tls', // 'tls' (STARTTLS Port 587), 'ssl' (Port 465), 'none' (Port 25)
        'username'   => '',
        'password'   => '',
        'from_email' => '',
        'from_name'  => 'NetworkMonitor Alarm',
        'to_email'   => '',
    ),
);
