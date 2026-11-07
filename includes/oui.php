<?php
/**
 * MAC-Vendor (OUI) Lookup & Intelligente Geräte-Klassifizierung
 * Kompatibel mit PHP 5.5+
 */

class OuiLookup
{
    private static $ouiTable = array(
        // AVM FRITZ!Box / Repeater
        '24:65:11' => 'AVM GmbH (FRITZ!Box)',
        '38:10:D5' => 'AVM GmbH (FRITZ!Box)',
        '44:4E:6D' => 'AVM GmbH (FRITZ!Box)',
        '48:5D:35' => 'AVM GmbH (FRITZ!Box)',
        '5C:49:79' => 'AVM GmbH (FRITZ!Box)',
        '60:B5:8D' => 'AVM GmbH (FRITZ!Box)',
        '9C:C7:A6' => 'AVM GmbH (FRITZ!Box)',
        'C8:0E:14' => 'AVM GmbH (FRITZ!Box)',
        'CC:CE:1E' => 'AVM GmbH (FRITZ!Box)',
        'DC:39:6F' => 'AVM GmbH (FRITZ!Box)',
        'E0:28:6D' => 'AVM GmbH (FRITZ!Box)',
        'F0:B0:E7' => 'AVM GmbH (FRITZ!Box)',

        // Router & Firewalls & Gateways & Cisco Switches
        '00:00:0C' => 'Cisco Systems',
        '00:01:42' => 'Cisco Systems',
        '00:0C:85' => 'Cisco Systems',
        '00:1B:54' => 'Cisco Systems',
        '00:25:45' => 'Cisco Systems',
        '00:26:0A' => 'Cisco Systems',
        '58:AC:78' => 'Cisco Systems',
        'C4:72:95' => 'Cisco Systems (Small Business Switch)',
        'DC:A5:F4' => 'Cisco Systems',
        'F4:CF:E2' => 'Cisco Meraki',
        '00:09:0F' => 'Fortinet (FortiGate)',
        '90:6C:AC' => 'Fortinet (FortiGate)',
        '00:1A:8C' => 'Sophos / Astaro',
        '00:0D:B9' => 'PC Engines (pfSense/OPNsense)',

        // Switches & Access Points
        '24:A4:3C' => 'Ubiquiti Networks',
        '44:D9:E7' => 'Ubiquiti Networks',
        '68:72:51' => 'Ubiquiti Networks',
        '74:83:C2' => 'Ubiquiti Networks',
        '78:8A:20' => 'Ubiquiti Networks',
        '80:2A:A8' => 'Ubiquiti Networks',
        'B4:FB:E4' => 'Ubiquiti Networks',
        'F0:9F:C2' => 'Ubiquiti Networks',
        '00:1F:FE' => 'HP ProCurve / Aruba',
        '00:25:61' => 'HP ProCurve / Aruba',
        '2C:41:38' => 'HP / Aruba Switch',
        '38:EA:A7' => 'Hewlett Packard Enterprise',
        '70:10:6F' => 'Hewlett Packard Enterprise',
        '94:57:A5' => 'Hewlett Packard',
        '00:09:5B' => 'Netgear',
        '00:0F:B5' => 'Netgear',
        '00:14:6C' => 'Netgear',
        '00:18:4D' => 'Netgear',
        '00:1B:2F' => 'Netgear',
        '00:1E:2A' => 'Netgear',
        '00:22:3F' => 'Netgear',
        '00:24:B2' => 'Netgear',
        '00:26:F2' => 'Netgear',
        '08:BD:43' => 'Netgear',
        '10:0C:6B' => 'Netgear',
        '10:DA:43' => 'Netgear',
        '20:0C:C8' => 'Netgear',
        '20:4E:7F' => 'Netgear',
        '20:E5:2A' => 'Netgear',
        '28:80:88' => 'Netgear',
        '28:C6:8E' => 'Netgear',
        '2C:B0:5D' => 'Netgear',
        '30:46:9A' => 'Netgear',
        '38:94:ED' => 'Netgear',
        '44:94:FC' => 'Netgear',
        '4C:60:DE' => 'Netgear (Switch / Network Device)',
        '6C:B0:CE' => 'Netgear',
        '74:44:01' => 'Netgear',
        '84:1B:5E' => 'Netgear',
        '8C:3B:AD' => 'Netgear',
        '9C:3D:CF' => 'Netgear',
        '9C:D3:6D' => 'Netgear',
        'A0:04:60' => 'Netgear',
        'A0:21:B7' => 'Netgear',
        'A0:40:A0' => 'Netgear',
        'A0:63:91' => 'Netgear',
        'B0:39:56' => 'Netgear',
        'B0:7F:B9' => 'Netgear',
        'B0:B9:8A' => 'Netgear',
        'C0:3F:0E' => 'Netgear',
        'C4:04:15' => 'Netgear',
        'C4:3D:C7' => 'Netgear',
        'CC:40:D0' => 'Netgear',
        'DC:EF:09' => 'Netgear',
        'E0:46:9A' => 'Netgear',
        'E0:91:F5' => 'Netgear',
        'E4:F4:C6' => 'Netgear',
        'F8:73:94' => 'Netgear',
        '2C:58:B9' => 'HP Inc.',
        '00:1D:AA' => 'D-Link Corporation',
        '1C:7E:E5' => 'D-Link Corporation',
        '28:10:7B' => 'D-Link Corporation',
        '14:CC:20' => 'TP-Link Technologies',
        '50:C7:BF' => 'TP-Link Technologies',
        '64:70:02' => 'TP-Link Technologies',
        'D8:0D:17' => 'TP-Link Technologies',
        '4C:5E:0C' => 'MikroTik RouterBOARD',
        '64:D1:54' => 'MikroTik RouterBOARD',
        'D4:CA:6D' => 'MikroTik RouterBOARD',
        'E4:8D:8C' => 'MikroTik RouterBOARD',

        // Virtualisierung & Server & NAS
        '00:0C:29' => 'VMware ESXi / vSphere',
        '00:50:56' => 'VMware ESXi / vSphere',
        '00:05:69' => 'VMware',
        '00:15:5D' => 'Microsoft Hyper-V',
        '52:54:00' => 'QEMU / KVM Virtual NIC',
        'BC:24:11' => 'Proxmox VE (QEMU/LXC)',
        '08:00:27' => 'Oracle VirtualBox',
        '00:11:32' => 'Synology NAS',
        '90:09:D0' => 'Synology NAS',
        '00:08:9B' => 'QNAP Systems NAS',
        '24:5E:BE' => 'QNAP Systems NAS',

        // Embedded / IoT / Smart Home
        'B8:27:EB' => 'Raspberry Pi Foundation',
        'DC:A6:32' => 'Raspberry Pi Trading',
        'E4:5F:01' => 'Raspberry Pi Trading',
        '28:CD:C1' => 'Raspberry Pi Trading',
        'BC:FF:4D' => 'Espressif Inc. (ESP8266/ESP32 IoT)',
        'B8:06:0D' => 'Espressif Inc. (ESP8266/ESP32 IoT)',
        '24:6F:28' => 'Espressif Inc. (ESP8266/ESP32 IoT)',
        '30:AE:A4' => 'Espressif Inc. (ESP8266/ESP32 IoT)',
        '84:F3:EB' => 'Espressif Inc. (ESP8266/ESP32 IoT)',
        '48:A4:FD' => 'Tuya Smart Inc. (IoT)',
        'D8:D6:68' => 'Tuya / Smart Home Wi-Fi',
        '20:BE:B8' => 'Amazon Technologies (Echo / Fire)',
        'F0:A3:B2' => 'Amazon / Smart Device',
        '2C:C3:E6' => 'Frontier Silicon (Smart / Internet Radio)',
        '9C:13:9E' => 'Smart Home / Embedded Wi-Fi Module',
        'D4:AD:FC' => 'Shenzhen Bilian (Wi-Fi Smart Device)',
        'C0:95:CF' => 'Embedded Network / Smart Device',

        // PCs, Notebooks & Smartphones
        '00:1B:21' => 'Intel Corporate',
        '3C:97:0E' => 'Intel Corporate',
        '48:51:B7' => 'Intel Corporate',
        '68:05:CA' => 'Intel Corporate',
        '8C:8D:28' => 'Intel Corporate',
        'A4:34:D9' => 'Intel Corporate',
        'E4:A7:A0' => 'Intel Corporate (Wi-Fi)',
        'F8:94:C2' => 'Intel Corporate',
        '18:48:BE' => 'ASUSTek / Realtek PC NIC',
        'BC:6E:E2' => 'Intel / PC Network Adapter',
        '00:1A:A0' => 'Dell Inc.',
        '18:03:73' => 'Dell Inc.',
        'D4:BE:D9' => 'Dell Inc.',
        'F8:BC:12' => 'Dell Inc.',
        '00:23:24' => 'Apple, Inc.',
        '28:CF:E9' => 'Apple, Inc.',
        '3C:15:C2' => 'Apple, Inc.',
        '6C:4A:85' => 'Apple, Inc. (Apple TV / HomePod)',
        '70:56:81' => 'Apple, Inc.',
        'A8:20:66' => 'Apple, Inc.',
        'F0:18:98' => 'Apple, Inc.',

        // Drucker
        '00:00:48' => 'Seiko Epson',
        '00:26:AB' => 'Seiko Epson',
        '00:80:77' => 'Brother Industries',
        '30:05:5C' => 'Brother Industries',
        '00:00:85' => 'Canon Inc.',
        '00:1E:8F' => 'Canon Inc.',
        '00:00:AA' => 'Xerox Corporation',
        '00:17:08' => 'HP Printer / JetDirect',
        '48:BA:4E' => 'HP Inc. (LaserJet / OfficeJet Printer)',
    );

    /**
     * Normalisiert eine beliebige MAC-Adress-Schreibweise (z.B. "00-11-22-aa-bb-cc" oder "001122aabbcc")
     * in das einheitliche Großbuchstaben-Format "00:11:22:AA:BB:CC".
     *
     * @param string $mac Rohe MAC-Adresse
     * @return string|null Normalisierte MAC-Adresse oder null bei ungültiger Länge
     */
    public static function normalizeMac($mac)
    {
        $clean = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac));
        if (strlen($clean) !== 12) {
            return null;
        }
        return implode(':', str_split($clean, 2));
    }

    /**
     * Ermittelt den Hardware-Hersteller (Vendor) anhand der ersten 3 Bytes (OUI) einer MAC-Adresse.
     * Prüft zuerst die integrierte Schnell-Tabelle, danach lokale Linux-OUI-Datenbanken (/usr/share/ieee-data/oui.txt)
     * und erkennt schließlich randomisierte Private-WLAN-MAC-Adressen (Locally Administered Bit).
     *
     * @param string $mac MAC-Adresse
     * @return string|null Herstellername oder null falls unbekannt
     */
    public static function lookupVendor($mac)
    {
        $norm = self::normalizeMac($mac);
        if (!$norm) {
            return null;
        }
        $prefix = substr($norm, 0, 8);
        if (isset(self::$ouiTable[$prefix])) {
            return self::$ouiTable[$prefix];
        }

        // System-OUI-Dateien prüfen, falls vorhanden
        static $systemOuiLoaded = false;
        static $systemOui = array();
        if (!$systemOuiLoaded) {
            $systemOuiLoaded = true;
            $paths = array(
                '/usr/share/nmap/nmap-mac-prefixes',
                '/usr/share/ieee-data/oui.txt',
                '/var/lib/ieee-data/oui.txt',
                '/usr/share/misc/oui.txt',
            );
            foreach ($paths as $p) {
                if (is_readable($p)) {
                    $fh = @fopen($p, 'r');
                    if ($fh) {
                        while (($line = fgets($fh)) !== false) {
                            if (preg_match('/^([0-9A-Fa-f]{6})\s+(.+)$/', trim($line), $m)) {
                                $k = implode(':', str_split(strtoupper($m[1]), 2));
                                $systemOui[$k] = trim($m[2]);
                            }
                        }
                        fclose($fh);
                    }
                    break;
                }
            }
        }
        if (isset($systemOui[$prefix])) {
            return $systemOui[$prefix];
        }

        // Prüfe auf lokal verwaltete / randomisierte Private-WLAN-MAC-Adresse (Bit 1 im ersten Oktett gesetzt)
        $firstByte = hexdec(substr($norm, 0, 2));
        if (($firstByte & 0x02) !== 0) {
            return 'Private / Lokale WLAN-MAC (Randomisiert)';
        }

        return null;
    }

    /**
     * Klassifiziert ein entdecktes Netzwerkgerät automatisch in eine Gerätekategorie:
     * 'router', 'switch', 'ap', 'printer', 'server' oder 'workstation'.
     *
     * @param string      $ip                 IP-Adresse des Geräts
     * @param string|null $gatewayIp          Gateway-IP des aktuellen Scopes
     * @param string|null $vendor             Ermittelter MAC-Hersteller
     * @param string|null $hostname           Aufgelöster DNS-/NetBIOS-Hostname
     * @param array       $openPorts          Offene TCP-Ports als Array [port => label]
     * @param string|null $snmpSysDescr       SNMP sysDescr-String
     * @param bool        $hasSnmpBridgeTable True falls das Gerät eine SNMP Bridge-MIB MAC-Tabelle / LLDP liefert
     * @param string      $serviceInfo        Zusätzliche Banner-/TR-064-/NSDP-Informationen
     * @return string Gerätekategorie
     */
    public static function classifyDevice($ip, $gatewayIp, $vendor, $hostname, $openPorts, $snmpSysDescr, $hasSnmpBridgeTable, $serviceInfo = '')
    {
        if ($ip === $gatewayIp) {
            return 'router';
        }
        if ($hasSnmpBridgeTable) {
            return 'switch';
        }
        // Lokales DNS-Suffix (.fritz.box / .local) beim Hostnamen-Vergleich ignorieren
        $hostShort = preg_replace('/\.(fritz\.box|local|lan)$/i', '', (string)$hostname);
        $descrLower = strtolower((string)$snmpSysDescr . ' ' . (string)$vendor . ' ' . $hostShort . ' ' . (string)$serviceInfo);

        if (
            strpos($descrLower, 'switch') !== false ||
            strpos($descrLower, 'procurve') !== false ||
            strpos($descrLower, 'catalyst') !== false ||
            strpos($descrLower, 'aruba') !== false ||
            strpos($descrLower, 'prosafe') !== false ||
            preg_match('/\b(gs[0-9]{3}|fs[0-9]{3}|xs[0-9]{3}|jgs[0-9]{3}|sg[0-9]{3}|cbs[0-9]{3})\b/i', $descrLower) ||
            (strpos($descrLower, 'netgear') !== false && strpos($descrLower, 'repeater') === false && strpos($descrLower, 'orbi') === false && strpos($descrLower, '802.11') === false && strpos($descrLower, 'wlan') === false)
        ) {
            return 'switch';
        }
        if (strpos($descrLower, 'fortigate') !== false || strpos($descrLower, 'pfsense') !== false || strpos($descrLower, 'opnsense') !== false) {
            return 'router';
        }
        // Weitere FRITZ!Boxen im selben Netz (z.B. FritzBoxEG / FritzBoxOG) als Mesh-AP / Router erkennen
        if (strpos($descrLower, 'fritz!box') !== false || strpos($descrLower, 'fritzbox') !== false) {
            return ($ip === $gatewayIp) ? 'router' : 'ap';
        }
        if (strpos($descrLower, 'unifi ap') !== false || strpos($descrLower, 'access point') !== false || strpos($descrLower, 'repeater') !== false) {
            return 'ap';
        }
        if (isset($openPorts[9100]) || isset($openPorts[515]) || isset($openPorts[631]) || strpos($descrLower, 'jetdirect') !== false || strpos($descrLower, 'drucker') !== false || strpos($descrLower, 'printer') !== false || strpos($descrLower, 'brother') !== false || strpos($descrLower, 'epson') !== false || strpos($descrLower, 'laserjet') !== false || strpos($descrLower, 'officejet') !== false) {
            return 'printer';
        }
        if (strpos($descrLower, 'synology') !== false || strpos($descrLower, 'qnap') !== false || strpos($descrLower, 'vmware') !== false || strpos($descrLower, 'proxmox') !== false) {
            return 'server';
        }
        if (isset($openPorts[22]) || isset($openPorts[3306]) || isset($openPorts[3389])) {
            return 'server';
        }
        return 'workstation';
    }
}
