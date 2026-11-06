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
    ),
);
