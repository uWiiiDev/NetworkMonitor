<?php
/**
 * Hybrid SNMP Client (PHP-SNMP Extension, snmpwalk CLI & Pure-PHP UDP ASN.1 BER Fallback)
 * Ermöglicht das Auslesen von Managed Switches (System-Infos, Interfaces, MAC-Table / Bridge-MIB, LLDP)
 * Kompatibel mit PHP 5.5+ (Ubuntu 14.04) bis PHP 8.x
 */

require_once __DIR__ . '/oui.php';

class SnmpClient
{
    private $ip;
    private $community;
    private $timeoutMs;

    public function __construct($ip, $community = 'public', $timeoutMs = 800)
    {
        $this->ip = $ip;
        $this->community = $community !== '' ? $community : 'public';
        $this->timeoutMs = (int)$timeoutMs;
    }

    /**
     * Multi-Host-Batch SNMP-Vorfilter:
     * Prüft bis zu $batchSize IPs gleichzeitig per nicht-blockierendem UDP-Socket auf Port 161 (sysDescr).
     * Gibt ein Array aller IPs zurück, die tatsächlich auf SNMP antworten.
     */
    public static function discoverResponsiveHostsBatch(array $ipList, $community = 'public', $timeoutMs = 500, $batchSize = 100)
    {
        if (empty($ipList)) {
            return array();
        }
        $responsiveIps = array();
        $helper = new self('127.0.0.1', $community, $timeoutMs);
        $packet = $helper->buildSnmpGetPacket('1.3.6.1.2.1.1.1.0');

        $chunks = array_chunk($ipList, max(1, (int)$batchSize));
        $timeoutSec = $timeoutMs / 1000.0;

        foreach ($chunks as $chunk) {
            $sockets = array();
            $ipBySockId = array();

            foreach ($chunk as $ip) {
                $errno = 0;
                $errstr = '';
                $sock = @stream_socket_client('udp://' . $ip . ':161', $errno, $errstr, 1, STREAM_CLIENT_CONNECT);
                if ($sock) {
                    stream_set_blocking($sock, false);
                    @fwrite($sock, $packet);
                    $id = (int)$s = $sock;
                    $sockets[$id] = $sock;
                    $ipBySockId[$id] = $ip;
                }
            }

            $start = microtime(true);
            $resent = false;
            while (!empty($sockets)) {
                $elapsed = microtime(true) - $start;
                $rem = $timeoutSec - $elapsed;
                if ($rem <= 0) {
                    break;
                }
                // Nach 180ms zweites UDP-Paket an noch offene Sockets senden (weckt schlafende WLAN-Drucker/Switches)
                if (!$resent && $elapsed >= 0.18) {
                    $resent = true;
                    foreach ($sockets as $s) {
                        @fwrite($s, $packet);
                    }
                }
                $read = array_values($sockets);
                $write = null;
                $except = null;
                $waitSlice = min($rem, 0.18);
                $sec = (int)floor($waitSlice);
                $usec = (int)(($waitSlice - $sec) * 1000000);

                $num = @stream_select($read, $write, $except, $sec, $usec);
                if ($num === false) {
                    break;
                }
                if ($num === 0) {
                    continue;
                }
                foreach ($read as $rSock) {
                    $id = (int)$rSock;
                    $resp = @fread($rSock, 1500);
                    if ($resp && strlen($resp) >= 10) {
                        $decoded = $helper->decodeSnmpResponse($resp);
                        if ($decoded !== null) {
                            $ip = $ipBySockId[$id];
                            $responsiveIps[$ip] = true;
                        }
                    }
                    @fclose($rSock);
                    unset($sockets[$id]);
                }
            }

            foreach ($sockets as $s) {
                @fclose($s);
            }
        }

        return $responsiveIps;
    }

    public function buildSnmpGetPacket($oidStr)
    {
        $reqId = mt_rand(100, 30000);
        $oidBin = $this->encodeOid($oidStr);
        $nullVal = "\x05\x00";
        $varbind = $this->berEncode(0x30, $oidBin . $nullVal);
        $varbindList = $this->berEncode(0x30, $varbind);

        $pduBody = $this->encodeInteger($reqId)
                 . $this->encodeInteger(0)
                 . $this->encodeInteger(0)
                 . $varbindList;
        $pdu = $this->berEncode(0xA0, $pduBody); // 0xA0 = GetRequest

        $msgBody = $this->encodeInteger(1) // SNMPv2c
                 . $this->berEncode(0x04, $this->community)
                 . $pdu;
        return $this->berEncode(0x30, $msgBody);
    }

    /**
     * Prüft, ob das Gerät auf SNMP antwortet und liest Switch-/Systemdaten aus
     */
    public function probeDevice()
    {
        $sysDescr = $this->getOid('1.3.6.1.2.1.1.1.0');
        $sysName  = $this->getOid('1.3.6.1.2.1.1.5.0');
        if ($sysDescr === null && $sysName === null) {
            return null;
        }

        $sysObjectId = $this->getOid('1.3.6.1.2.1.1.2.0');
        $sysUpTimeRaw = $this->getOid('1.3.6.1.2.1.1.3.0');
        $sysContact  = $this->getOid('1.3.6.1.2.1.1.4.0');
        $sysLocation = $this->getOid('1.3.6.1.2.1.1.6.0');

        // Uptime in lesbares Format (Tage, Stunden:Minuten:Sekunden) umrechnen
        $sysUpTimeFormatted = $this->formatTimeticks($sysUpTimeRaw);

        // Erweiterte Hardware-, Modell- & Seriennummer-Abfrage (Host-Resources / Entity-MIB / Printer-MIB)
        $deviceModel = $this->getOid('1.3.6.1.2.1.25.3.2.1.3.1');
        if (!$deviceModel) {
            $deviceModel = $this->getOid('1.3.6.1.2.1.47.1.1.1.1.13.1');
        }
        if (!$deviceModel) {
            $entModWalk = $this->walkOid('1.3.6.1.2.1.47.1.1.1.1.13', 4);
            foreach ($entModWalk as $emVal) {
                $emClean = $this->cleanSnmpValue($emVal);
                if ($emClean !== '') {
                    // Bekannter Firmware-Tippfehler bei Cisco SG500 ("SG5000-28-k9" -> "Cisco SG500-28 (SG5000-28-k9)")
                    if (preg_match('/^SG5000-(.+)$/i', $emClean, $sgm)) {
                        $deviceModel = 'Cisco SG500-' . strtoupper($sgm[1]);
                    } else {
                        $deviceModel = $emClean;
                    }
                    break;
                }
            }
        }
        $serialNumber = $this->getOid('1.3.6.1.2.1.43.5.1.1.17.1');
        if (!$serialNumber) {
            $serialNumber = $this->getOid('1.3.6.1.2.1.47.1.1.1.1.11.1');
        }
        if (!$serialNumber) {
            $entSerWalk = $this->walkOid('1.3.6.1.2.1.47.1.1.1.1.11', 4);
            foreach ($entSerWalk as $esVal) {
                $esClean = $this->cleanSnmpValue($esVal);
                if ($esClean !== '') {
                    $serialNumber = $esClean;
                    break;
                }
            }
        }
        $firmwareRev = $this->getOid('1.3.6.1.2.1.47.1.1.1.1.10.1');
        if (!$firmwareRev) {
            $firmwareRev = $this->getOid('1.3.6.1.2.1.47.1.1.1.1.9.1');
        }
        if (!$firmwareRev) {
            $entFwWalk = $this->walkOid('1.3.6.1.2.1.47.1.1.1.1.10', 4);
            foreach ($entFwWalk as $efVal) {
                $efClean = $this->cleanSnmpValue($efVal);
                if ($efClean !== '') {
                    $firmwareRev = $efClean;
                    break;
                }
            }
        }

        // Interfaces (ifName / ifDescr, ifOperStatus, ifSpeed / ifHighSpeed, ifAlias, Traffic In/Out, Errors)
        $ifNames = $this->walkOid('1.3.6.1.2.1.31.1.1.1.1', 108);
        if (empty($ifNames)) {
            $ifNames = $this->walkOid('1.3.6.1.2.1.2.2.1.2', 108);
        }
        $ifStatus    = $this->walkOid('1.3.6.1.2.1.2.2.1.8', 108);
        $ifAdminWalk = $this->walkOid('1.3.6.1.2.1.2.2.1.7', 108);
        $ifAliasWalk = $this->walkOid('1.3.6.1.2.1.31.1.1.1.18', 108);
        $ifHighSpeed = $this->walkOid('1.3.6.1.2.1.31.1.1.1.15', 108);
        $ifSpeedWalk = empty($ifHighSpeed) ? $this->walkOid('1.3.6.1.2.1.2.2.1.5', 108) : array();
        $ifInOctets  = $this->walkOid('1.3.6.1.2.1.2.2.1.10', 108);
        $ifOutOctets = $this->walkOid('1.3.6.1.2.1.2.2.1.16', 108);
        $ifInErrors  = $this->walkOid('1.3.6.1.2.1.2.2.1.14', 108);

        // Q-BRIDGE-MIB (802.1Q VLANs & Port-PVIDs):
        // dot1qPvid (1.3.6.1.2.1.17.7.1.4.5.1.1), dot1qVlanFdbId (.4.2.1.3), dot1qVlanStaticName (.4.3.1.1)
        $pvidWalk       = $this->walkOid('1.3.6.1.2.1.17.7.1.4.5.1.1', 108);
        $vlanCurWalk    = $this->walkOid('1.3.6.1.2.1.17.7.1.4.2.1.3', 64);
        $vlanStaticName = $this->walkOid('1.3.6.1.2.1.17.7.1.4.3.1.1', 64);

        // Cisco Catalyst / IOS Core-Switch Erweiterung:
        // CISCO-VTP-MIB (vtpVlanName 1.3.6.1.4.1.9.9.46.1.3.1.1.4.1),
        // CISCO-VLAN-MEMBERSHIP-MIB (vmVlan 1.3.6.1.4.1.9.9.68.1.2.2.1.2),
        // Trunk-Status (vlanTrunkPortDynamicStatus 1.3.6.1.4.1.9.9.46.1.6.1.1.14, 1=trunking) & Native-VLAN (.1.1.5)
        $isCiscoDevice = (stripos((string)$sysDescr, 'cisco') !== false || strpos((string)$sysObjectId, '1.3.6.1.4.1.9.') !== false);
        $ciscoVtpNames   = ($isCiscoDevice || empty($vlanStaticName)) ? $this->walkOid('1.3.6.1.4.1.9.9.46.1.3.1.1.4.1', 64) : array();
        $ciscoVmVlan     = ($isCiscoDevice || empty($pvidWalk)) ? $this->walkOid('1.3.6.1.4.1.9.9.68.1.2.2.1.2', 108) : array();
        $ciscoTrunkStat  = $isCiscoDevice ? $this->walkOid('1.3.6.1.4.1.9.9.46.1.6.1.1.14', 108) : array();
        $ciscoNativeVlan = !empty($ciscoTrunkStat) ? $this->walkOid('1.3.6.1.4.1.9.9.46.1.6.1.1.5', 108) : array();

        $vlansById = array(1 => array('id' => 1, 'name' => 'Default'));
        foreach ($vlanCurWalk as $vOid => $vVal) {
            $vParts = explode('.', $vOid);
            $vid = (int)end($vParts);
            if ($vid >= 1 && $vid <= 4094 && !isset($vlansById[$vid])) {
                $vlansById[$vid] = array('id' => $vid, 'name' => ($vid === 1 ? 'Default' : ('VLAN ' . $vid)));
            }
        }
        foreach ($vlanStaticName as $vOid => $vName) {
            $vParts = explode('.', $vOid);
            $vid = (int)end($vParts);
            $cleanVName = $this->cleanSnmpValue($vName);
            if ($vid >= 1 && $vid <= 4094) {
                $vlansById[$vid] = array(
                    'id'   => $vid,
                    'name' => ($cleanVName !== '' && $cleanVName !== (string)$vid) ? $cleanVName : ($vid === 1 ? 'Default' : ('VLAN ' . $vid)),
                );
            }
        }
        foreach ($ciscoVtpNames as $vOid => $vName) {
            $vParts = explode('.', $vOid);
            $vid = (int)end($vParts);
            // Interne Cisco FDDI/TokenRing Default-VLANs 1002..1005 überspringen
            if ($vid >= 1 && $vid <= 4094 && ($vid < 1002 || $vid > 1005)) {
                $cleanVName = $this->cleanSnmpValue($vName);
                $vlansById[$vid] = array(
                    'id'   => $vid,
                    'name' => ($cleanVName !== '' && $cleanVName !== (string)$vid) ? $cleanVName : ($vid === 1 ? 'Default' : ('VLAN ' . $vid)),
                );
            }
        }

        // Erwartete Portanzahl aus sysDescr / Modellnamen erkennen (z.B. "28-Port Gigabit Stackable Managed Switch")
        $expectedPorts = 0;
        if (preg_match('/\b(8|10|12|16|18|20|24|26|28|48|50|52)[-\s]*Port\b/i', (string)$sysDescr . ' ' . (string)$deviceModel, $epm)) {
            $expectedPorts = (int)$epm[1];
        }

        // Aktive Präfixe (z.B. "gi1/1/") bestimmen, die mindestens einen physisch vorhandenen Port (ifOperStatus 1=up oder 2=down) besitzen
        $presentPrefixes = array();
        $hasPresentPorts = false;
        foreach ($ifNames as $oid => $val) {
            $parts = explode('.', $oid);
            $ifIndex = (int)end($parts);
            $stRaw = isset($ifStatus['1.3.6.1.2.1.2.2.1.8.' . $ifIndex]) ? trim((string)$ifStatus['1.3.6.1.2.1.2.2.1.8.' . $ifIndex]) : '1';
            $isNotPresent = ($stRaw === '6' || stripos($stRaw, 'notPresent') !== false);
            if (!$isNotPresent) {
                $hasPresentPorts = true;
                $cleanName = $this->cleanSnmpValue($val);
                if (preg_match('/^([A-Za-z\-]*[1-8]\/[0-9]+\/)[0-9]+$/', $cleanName, $pxm)) {
                    $presentPrefixes[$pxm[1]] = true;
                }
            }
        }

        $interfaces = array();
        foreach ($ifNames as $oid => $val) {
            $parts = explode('.', $oid);
            $ifIndex = (int)end($parts);
            if ($ifIndex > 0) {
                $statusVal = isset($ifStatus['1.3.6.1.2.1.2.2.1.8.' . $ifIndex]) ? trim((string)$ifStatus['1.3.6.1.2.1.2.2.1.8.' . $ifIndex]) : '1';
                $cleanName = $this->cleanSnmpValue($val);
                $isNotPresent = ($statusVal === '6' || stripos($statusVal, 'notPresent') !== false);
                if ($isNotPresent && $hasPresentPorts) {
                    // Nicht bestückte SFP-Ports (status=6) des aktiven Switch-Moduls bis $expectedPorts beibehalten (z.B. gi1/1/27..28 beim 28-Port Switch),
                    // aber virtuelle Platzhalter-Slots/Stack-Units (wie fa1/2/1..48, gi1/1/29..52, fa2/2/*, Po1..32) ausblenden
                    $keepEmptySfp = false;
                    if ($expectedPorts > 0 && preg_match('/^([A-Za-z\-]*[1-8]\/[0-9]+\/)([0-9]+)$/', $cleanName, $pmSfp)) {
                        $pfx = $pmSfp[1];
                        $pNum = (int)$pmSfp[2];
                        if (isset($presentPrefixes[$pfx]) && $pNum >= 1 && $pNum <= $expectedPorts) {
                            $keepEmptySfp = true;
                        }
                    }
                    if (!$keepEmptySfp) {
                        continue;
                    }
                }
                $aliasVal  = isset($ifAliasWalk['1.3.6.1.2.1.31.1.1.1.18.' . $ifIndex]) ? $this->cleanSnmpValue($ifAliasWalk['1.3.6.1.2.1.31.1.1.1.18.' . $ifIndex]) : '';

                // Geschwindigkeit bestimmen (Mbit/s oder Gbit/s)
                $speedStr = '';
                if (isset($ifHighSpeed['1.3.6.1.2.1.31.1.1.1.15.' . $ifIndex])) {
                    $mbps = (int)$this->cleanSnmpValue($ifHighSpeed['1.3.6.1.2.1.31.1.1.1.15.' . $ifIndex]);
                    if ($mbps >= 1000) {
                        $speedStr = round($mbps / 1000, 1) . ' Gbit/s';
                    } elseif ($mbps > 0) {
                        $speedStr = $mbps . ' Mbit/s';
                    }
                } elseif (isset($ifSpeedWalk['1.3.6.1.2.1.2.2.1.5.' . $ifIndex])) {
                    $bps = (float)$this->cleanSnmpValue($ifSpeedWalk['1.3.6.1.2.1.2.2.1.5.' . $ifIndex]);
                    if ($bps >= 1000000000) {
                        $speedStr = round($bps / 1000000000, 1) . ' Gbit/s';
                    } elseif ($bps >= 1000000) {
                        $speedStr = round($bps / 1000000) . ' Mbit/s';
                    }
                }

                $inBytes  = isset($ifInOctets['1.3.6.1.2.1.2.2.1.10.' . $ifIndex]) ? (float)$this->cleanSnmpValue($ifInOctets['1.3.6.1.2.1.2.2.1.10.' . $ifIndex]) : 0;
                $outBytes = isset($ifOutOctets['1.3.6.1.2.1.2.2.1.16.' . $ifIndex]) ? (float)$this->cleanSnmpValue($ifOutOctets['1.3.6.1.2.1.2.2.1.16.' . $ifIndex]) : 0;
                $inErr    = isset($ifInErrors['1.3.6.1.2.1.2.2.1.14.' . $ifIndex]) ? (int)$this->cleanSnmpValue($ifInErrors['1.3.6.1.2.1.2.2.1.14.' . $ifIndex]) : 0;
                $adminVal = isset($ifAdminWalk['1.3.6.1.2.1.2.2.1.7.' . $ifIndex]) ? trim((string)$ifAdminWalk['1.3.6.1.2.1.2.2.1.7.' . $ifIndex]) : '1';
                $pvidVal  = isset($pvidWalk['1.3.6.1.2.1.17.7.1.4.5.1.1.' . $ifIndex]) ? (int)$this->cleanSnmpValue($pvidWalk['1.3.6.1.2.1.17.7.1.4.5.1.1.' . $ifIndex]) : 0;
                $portMode = 'access';

                // Cisco Catalyst: Trunk-Status (1 = trunking) & vmVlan / Native VLAN auslesen
                if (isset($ciscoTrunkStat['1.3.6.1.4.1.9.9.46.1.6.1.1.14.' . $ifIndex])) {
                    $trVal = trim((string)$this->cleanSnmpValue($ciscoTrunkStat['1.3.6.1.4.1.9.9.46.1.6.1.1.14.' . $ifIndex]));
                    if ($trVal === '1' || stripos($trVal, 'trunking') !== false) {
                        $portMode = 'trunk';
                    }
                }
                if ($portMode === 'trunk' && isset($ciscoNativeVlan['1.3.6.1.4.1.9.9.46.1.6.1.1.5.' . $ifIndex])) {
                    $natVlan = (int)$this->cleanSnmpValue($ciscoNativeVlan['1.3.6.1.4.1.9.9.46.1.6.1.1.5.' . $ifIndex]);
                    if ($natVlan >= 1 && $natVlan <= 4094) {
                        $pvidVal = $natVlan;
                    }
                } elseif ($pvidVal <= 0 && isset($ciscoVmVlan['1.3.6.1.4.1.9.9.68.1.2.2.1.2.' . $ifIndex])) {
                    $vmV = (int)$this->cleanSnmpValue($ciscoVmVlan['1.3.6.1.4.1.9.9.68.1.2.2.1.2.' . $ifIndex]);
                    if ($vmV >= 1 && $vmV <= 4094) {
                        $pvidVal = $vmV;
                    }
                }

                if ($pvidVal < 1 || $pvidVal > 4094) {
                    $pvidVal = 1;
                }
                if (!isset($vlansById[$pvidVal])) {
                    $vlansById[$pvidVal] = array('id' => $pvidVal, 'name' => 'VLAN ' . $pvidVal);
                }

                $interfaces[$ifIndex] = array(
                    'ifIndex'      => $ifIndex,
                    'name'         => $cleanName,
                    'alias'        => $aliasVal,
                    'status'       => ($statusVal === '1' || stripos((string)$statusVal, 'up') !== false) ? 'up' : 'down',
                    'admin_status' => ($adminVal === '1' || stripos((string)$adminVal, 'up') !== false) ? 'up' : 'down',
                    'vlan'         => $pvidVal,
                    'port_mode'    => $portMode,
                    'speed'        => $speedStr,
                    'rx_mb'        => $inBytes > 0 ? round($inBytes / 1048576, 1) : 0,
                    'tx_mb'        => $outBytes > 0 ? round($outBytes / 1048576, 1) : 0,
                    'in_errors'    => $inErr,
                );
            }
        }
        ksort($vlansById);

        // Bridge-MIB: dot1dBasePortIfIndex (1.3.6.1.2.1.17.1.4.1.2)
        // Zuordnung BridgePort -> ifIndex
        $bridgePortToIfIndex = array();
        $bpWalk = $this->walkOid('1.3.6.1.2.1.17.1.4.1.2', 108);
        foreach ($bpWalk as $oid => $val) {
            $parts = explode('.', $oid);
            $bridgePort = (int)end($parts);
            $bridgePortToIfIndex[$bridgePort] = (int)$this->cleanSnmpValue($val);
        }

        // Bridge-MIB MAC-Adresstabelle: dot1dTpFdbPort (1.3.6.1.2.1.17.4.3.1.2)
        $fdbWalk = $this->walkOid('1.3.6.1.2.1.17.4.3.1.2', 200);
        if (empty($fdbWalk)) {
            $fdbWalk = $this->walkOid('1.3.6.1.2.1.17.7.1.2.2.1.2', 200);
        }

        $macTable = array();
        foreach ($fdbWalk as $oid => $val) {
            $parts = explode('.', $oid);
            $count = count($parts);
            if ($count >= 6) {
                $macBytes = array_slice($parts, -6);
                $hexParts = array();
                $validMac = true;
                foreach ($macBytes as $b) {
                    $num = (int)$b;
                    if ($num < 0 || $num > 255) {
                        $validMac = false;
                        break;
                    }
                    $hexParts[] = sprintf('%02X', $num);
                }
                if ($validMac) {
                    $mac = implode(':', $hexParts);
                    $bridgePort = (int)$this->cleanSnmpValue($val);
                    $ifIndex = isset($bridgePortToIfIndex[$bridgePort]) ? $bridgePortToIfIndex[$bridgePort] : $bridgePort;
                    $portName = isset($interfaces[$ifIndex]['name']) ? $interfaces[$ifIndex]['name'] : ('Port ' . $bridgePort);
                    $macTable[$mac] = array(
                        'bridge_port' => $bridgePort,
                        'if_index'    => $ifIndex,
                        'port_name'   => $portName,
                    );
                }
            }
        }

        // Cisco Catalyst IOS / IOS-XE: Per-VLAN MAC-Tabellen-Abfrage via Community-Indexing ("public@VLAN")
        if ((stripos((string)$sysDescr, 'cisco ios') !== false || !empty($ciscoVtpNames)) && count($vlansById) > 1) {
            $queriedExtraVlans = 0;
            foreach ($vlansById as $vid => $vMeta) {
                if ($vid <= 1 || $vid >= 1002) {
                    continue;
                }
                if ($queriedExtraVlans >= 10) {
                    break;
                }
                $queriedExtraVlans++;
                $vlanClient = new self($this->ip, $this->community . '@' . $vid, 500);
                $vBpWalk = $vlanClient->walkOid('1.3.6.1.2.1.17.1.4.1.2', 108);
                $vBpMap = $bridgePortToIfIndex;
                foreach ($vBpWalk as $void => $vval) {
                    $vparts = explode('.', $void);
                    $vBpMap[(int)end($vparts)] = (int)$this->cleanSnmpValue($vval);
                }
                $vFdbWalk = $vlanClient->walkOid('1.3.6.1.2.1.17.4.3.1.2', 200);
                foreach ($vFdbWalk as $void => $vval) {
                    $vparts = explode('.', $void);
                    if (count($vparts) >= 6) {
                        $mBytes = array_slice($vparts, -6);
                        $hParts = array();
                        $okM = true;
                        foreach ($mBytes as $mb) {
                            $n = (int)$mb;
                            if ($n < 0 || $n > 255) {
                                $okM = false;
                                break;
                            }
                            $hParts[] = sprintf('%02X', $n);
                        }
                        if ($okM) {
                            $mac = implode(':', $hParts);
                            $bPort = (int)$this->cleanSnmpValue($vval);
                            $ifIdx = isset($vBpMap[$bPort]) ? $vBpMap[$bPort] : $bPort;
                            $pName = isset($interfaces[$ifIdx]['name']) ? $interfaces[$ifIdx]['name'] : ('Port ' . $bPort);
                            $macTable[$mac] = array(
                                'bridge_port' => $bPort,
                                'if_index'    => $ifIdx,
                                'port_name'   => $pName,
                                'vlan'        => $vid,
                            );
                        }
                    }
                }
            }
        }

        // LLDP Nachbarschaftstabelle: lldpRemSysName (1.0.8802.1.1.2.1.4.1.1.9) & lldpRemPortId (.7) / lldpRemPortDesc (.8)
        $lldpNeighbors = array();
        $lldpWalk = $this->walkOid('1.0.8802.1.1.2.1.4.1.1.9', 32);
        $lldpPortWalk = !empty($lldpWalk) ? $this->walkOid('1.0.8802.1.1.2.1.4.1.1.7', 32) : array();
        $lldpPortDescWalk = !empty($lldpWalk) ? $this->walkOid('1.0.8802.1.1.2.1.4.1.1.8', 32) : array();
        foreach ($lldpWalk as $oid => $val) {
            $parts = explode('.', $oid);
            $localPortNum = isset($parts[count($parts) - 2]) ? (int)$parts[count($parts) - 2] : 0;
            $remName = $this->cleanSnmpValue($val);
            if ($remName !== '') {
                $suffix = substr($oid, strlen('1.0.8802.1.1.2.1.4.1.1.9.'));
                $remPort = '';
                $rawDesc = isset($lldpPortDescWalk['1.0.8802.1.1.2.1.4.1.1.8.' . $suffix]) ? $this->cleanSnmpValue($lldpPortDescWalk['1.0.8802.1.1.2.1.4.1.1.8.' . $suffix]) : '';
                $rawPortId = isset($lldpPortWalk['1.0.8802.1.1.2.1.4.1.1.7.' . $suffix]) ? (string)$lldpPortWalk['1.0.8802.1.1.2.1.4.1.1.7.' . $suffix] : '';
                if ($rawDesc !== '' && preg_match('/^[\x20-\x7E]+$/', $rawDesc)) {
                    $remPort = $rawDesc;
                } elseif (strlen($rawPortId) === 6 && !preg_match('/^[\x20-\x7E]+$/', $rawPortId)) {
                    $macHex = array();
                    for ($mi = 0; $mi < 6; $mi++) {
                        $macHex[] = sprintf('%02X', ord($rawPortId[$mi]));
                    }
                    $remPort = implode(':', $macHex);
                } else {
                    $cleanPortId = $this->cleanSnmpValue($rawPortId);
                    $remPort = preg_match('/^[\x20-\x7E]+$/', $cleanPortId) ? $cleanPortId : ($rawDesc !== '' ? $rawDesc : 'Uplink');
                }
                $portName = isset($interfaces[$localPortNum]['name']) ? $interfaces[$localPortNum]['name'] : ('Port ' . $localPortNum);
                $lldpNeighbors[] = array(
                    'local_port'  => $portName,
                    'remote_name' => $remName,
                    'remote_port' => $remPort !== '' ? $remPort : 'Uplink',
                );
            }
        }

        // Cisco CDP Nachbarschaftstabelle (CISCO-CDP-MIB: cdpCacheDeviceId .1.6 & cdpCacheDevicePort .1.7)
        if ($isCiscoDevice) {
            $cdpDevWalk = $this->walkOid('1.3.6.1.4.1.9.9.23.1.2.1.1.6', 32);
            $cdpPortWalk = !empty($cdpDevWalk) ? $this->walkOid('1.3.6.1.4.1.9.9.23.1.2.1.1.7', 32) : array();
            foreach ($cdpDevWalk as $oid => $val) {
                $parts = explode('.', $oid);
                $ifIdx = isset($parts[count($parts) - 2]) ? (int)$parts[count($parts) - 2] : 0;
                $remName = $this->cleanSnmpValue($val);
                if ($remName !== '' && $ifIdx > 0) {
                    $suffix = substr($oid, strlen('1.3.6.1.4.1.9.9.23.1.2.1.1.6.'));
                    $remPort = isset($cdpPortWalk['1.3.6.1.4.1.9.9.23.1.2.1.1.7.' . $suffix])
                        ? $this->cleanSnmpValue($cdpPortWalk['1.3.6.1.4.1.9.9.23.1.2.1.1.7.' . $suffix])
                        : 'CDP-Trunk';
                    $portName = isset($interfaces[$ifIdx]['name']) ? $interfaces[$ifIdx]['name'] : ('Port ' . $ifIdx);
                    $alreadyInLldp = false;
                    foreach ($lldpNeighbors as $ln) {
                        if ($ln['local_port'] === $portName && strcasecmp($ln['remote_name'], $remName) === 0) {
                            $alreadyInLldp = true;
                            break;
                        }
                    }
                    if (!$alreadyInLldp) {
                        $lldpNeighbors[] = array(
                            'local_port'  => $portName,
                            'remote_name' => $remName,
                            'remote_port' => $remPort !== '' ? $remPort : 'CDP-Uplink',
                        );
                    }
                }
            }
        }

        // Switch-Stacking Erkennung (aus Port-Namen wie Gi1/0/1, Gi2/0/1, 1/0/1, 2/0/1 sowie Entity-MIB)
        $detectedUnits = array();
        foreach ($interfaces as $ifIdx => $inf) {
            $pName = $inf['name'];
            // Muster wie Gi1/0/12, Te2/0/1, 1/0/5, 2/1/8, unit1/port3
            if (preg_match('/^(?:[A-Za-z\-]+)?([1-8])\/[0-9]+\/([0-9]+)$/', $pName, $pm)) {
                $unitNum = (int)$pm[1];
                $detectedUnits[$unitNum] = isset($detectedUnits[$unitNum]) ? ($detectedUnits[$unitNum] + 1) : 1;
                $interfaces[$ifIdx]['stack_unit'] = $unitNum;
                $interfaces[$ifIdx]['port_num'] = (int)$pm[2];
            } elseif (preg_match('/^([1-8])\/([0-9]{1,2})$/', $pName, $pm2)) {
                $unitNum = (int)$pm2[1];
                $detectedUnits[$unitNum] = isset($detectedUnits[$unitNum]) ? ($detectedUnits[$unitNum] + 1) : 1;
                $interfaces[$ifIdx]['stack_unit'] = $unitNum;
                $interfaces[$ifIdx]['port_num'] = (int)$pm2[2];
            }
        }

        $stackMembers = array();
        ksort($detectedUnits);
        if (count($detectedUnits) > 1) {
            foreach ($detectedUnits as $uNum => $pCount) {
                $stackMembers[] = array(
                    'unit'   => $uNum,
                    'role'   => ($uNum === 1) ? 'Master / Commander' : 'Stack-Member',
                    'model'  => $this->cleanSnmpValue($deviceModel ? $deviceModel : $sysDescr),
                    'serial' => ($uNum === 1 && $serialNumber) ? $this->cleanSnmpValue($serialNumber) : ('STACK-UNIT-' . $uNum),
                    'ports'  => $pCount,
                );
            }
        }

        // Drucker-MIB (Printer-MIB 1.3.6.1.2.1.43): Seitenzähler, Display-Text & Tinten-/Toner-Füllstände
        $printerInfo = null;
        $pageCountRaw = $this->getOid('1.3.6.1.2.1.43.10.2.1.4.1.1');
        $supNames = $this->walkOid('1.3.6.1.2.1.43.11.1.1.6', 12);
        if ($pageCountRaw !== null || !empty($supNames)) {
            $supMax   = $this->walkOid('1.3.6.1.2.1.43.11.1.1.8', 12);
            $supLevel = $this->walkOid('1.3.6.1.2.1.43.11.1.1.9', 12);
            $dispWalk = $this->walkOid('1.3.6.1.2.1.43.16.5.1.2', 4);
            $dispText = !empty($dispWalk) ? $this->cleanSnmpValue(reset($dispWalk)) : '';

            $supplies = array();
            foreach ($supNames as $oid => $sName) {
                $suffix = substr($oid, strlen('1.3.6.1.2.1.43.11.1.1.6.'));
                $maxVal = isset($supMax['1.3.6.1.2.1.43.11.1.1.8.' . $suffix]) ? (int)$this->cleanSnmpValue($supMax['1.3.6.1.2.1.43.11.1.1.8.' . $suffix]) : 100;
                $curVal = isset($supLevel['1.3.6.1.2.1.43.11.1.1.9.' . $suffix]) ? (int)$this->cleanSnmpValue($supLevel['1.3.6.1.2.1.43.11.1.1.9.' . $suffix]) : 0;
                $pct = ($maxVal > 0 && $curVal >= 0) ? min(100, (int)round(($curVal / $maxVal) * 100)) : null;
                $supplies[] = array(
                    'name'    => $this->cleanSnmpValue($sName),
                    'level'   => $curVal,
                    'max'     => $maxVal,
                    'percent' => $pct,
                );
            }

            $printerInfo = array(
                'page_count'      => $pageCountRaw !== null ? (int)$this->cleanSnmpValue($pageCountRaw) : null,
                'console_display' => $dispText,
                'supplies'        => $supplies,
            );
        }

        $cleanDescr = $this->cleanSnmpValue($sysDescr);
        if ($deviceModel && strpos($cleanDescr, $deviceModel) === false) {
            $cleanDescr = $cleanDescr ? ($deviceModel . ' (' . $cleanDescr . ')') : $deviceModel;
        }

        return array(
            'sysName'        => $this->cleanSnmpValue($sysName),
            'sysDescr'       => $cleanDescr,
            'sysObjectID'    => $this->cleanSnmpValue($sysObjectId),
            'sysUpTime'      => $sysUpTimeFormatted,
            'sysContact'     => $this->cleanSnmpValue($sysContact),
            'sysLocation'    => $this->cleanSnmpValue($sysLocation),
            'deviceModel'    => $this->cleanSnmpValue($deviceModel),
            'serialNumber'   => $this->cleanSnmpValue($serialNumber),
            'firmwareRev'    => $this->cleanSnmpValue($firmwareRev),
            'interfaces'     => array_values($interfaces),
            'vlans'          => array_values($vlansById),
            'mac_table'      => $macTable,
            'lldp_neighbors' => $lldpNeighbors,
            'stack_members'  => $stackMembers,
            'printer'        => $printerInfo,
        );
    }

    private function formatTimeticks($raw)
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        if (preg_match('/^\d+$/', trim((string)$raw))) {
            $secTotal = (int)floor(((float)$raw) / 100);
            $days = (int)floor($secTotal / 86400);
            $hours = (int)floor(($secTotal % 86400) / 3600);
            $mins = (int)floor(($secTotal % 3600) / 60);
            $secs = $secTotal % 60;
            if ($days > 0) {
                return sprintf('%d Tage, %02d:%02d:%02d Std.', $days, $hours, $mins, $secs);
            }
            return sprintf('%02d:%02d:%02d Std.', $hours, $mins, $secs);
        }
        return $this->cleanSnmpValue($raw);
    }

    private function cleanSnmpValue($val)
    {
        if ($val === null || $val === false) {
            return '';
        }
        $val = (string)$val;
        $val = preg_replace('/^(STRING|OID|INTEGER|Gauge32|Counter32|Timeticks|IpAddress|Hex-STRING):\s*/i', '', $val);
        return trim($val, " \t\n\r\0\x0B\"'");
    }

    public function getOid($oid)
    {
        // 1. PHP SNMP Extension
        if (function_exists('snmp2_get')) {
            $res = @snmp2_get($this->ip, $this->community, $oid, $this->timeoutMs * 1000, 1);
            if ($res !== false) {
                return $this->cleanSnmpValue($res);
            }
        } elseif (function_exists('snmpget')) {
            $res = @snmpget($this->ip, $this->community, $oid, $this->timeoutMs * 1000, 1);
            if ($res !== false) {
                return $this->cleanSnmpValue($res);
            }
        }

        // 2. Pure-PHP UDP SNMPv2c GET
        $pdu = $this->udpSnmpRequest(0xA0, $oid); // 0xA0 = GetRequest-PDU
        if ($pdu !== null && isset($pdu['value'])) {
            return (string)$pdu['value'];
        }
        return null;
    }

    public function walkOid($baseOid, $maxRows = 100)
    {
        $baseOid = ltrim($baseOid, '.');

        // 1. PHP SNMP Extension
        if (function_exists('snmp2_real_walk')) {
            $res = @snmp2_real_walk($this->ip, $this->community, '.' . $baseOid, $this->timeoutMs * 1000, 1);
            if (is_array($res) && !empty($res)) {
                $out = array();
                foreach ($res as $k => $v) {
                    $normOid = ltrim(preg_replace('/^iso/i', '1', $k), '.');
                    $out[$normOid] = $this->cleanSnmpValue($v);
                }
                return $out;
            }
        }

        // 2. Pure-PHP UDP SNMPv2c GETNEXT Loop
        $results = array();
        $currentOid = $baseOid;
        for ($i = 0; $i < $maxRows; $i++) {
            $resp = $this->udpSnmpRequest(0xA1, $currentOid); // 0xA1 = GetNextRequest-PDU
            if ($resp === null || empty($resp['oid'])) {
                break;
            }
            $nextOid = $resp['oid'];
            if (strpos($nextOid, $baseOid . '.') !== 0 && $nextOid !== $baseOid) {
                break;
            }
            if ($nextOid === $currentOid) {
                break;
            }
            $results[$nextOid] = (string)$resp['value'];
            $currentOid = $nextOid;
        }
        return $results;
    }

    /**
     * Leichtgewichtiger Pure-PHP ASN.1 BER SNMPv2c Client über UDP Port 161
     * Funktioniert ohne externe Pakete auf jedem PHP 5.5+ bis 8.x Server!
     */
    private function udpSnmpRequest($pduType, $oidStr)
    {
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client('udp://' . $this->ip . ':161', $errno, $errstr, 1);
        if (!$sock) {
            return null;
        }
        $sec = (int)floor($this->timeoutMs / 1000);
        $usec = (int)(($this->timeoutMs % 1000) * 1000);
        stream_set_timeout($sock, $sec, $usec);

        $reqId = mt_rand(100, 30000);
        $oidBin = $this->encodeOid($oidStr);
        $nullVal = "\x05\x00";
        $varbind = $this->berEncode(0x30, $oidBin . $nullVal);
        $varbindList = $this->berEncode(0x30, $varbind);

        $pduBody = $this->encodeInteger($reqId)
                 . $this->encodeInteger(0)
                 . $this->encodeInteger(0)
                 . $varbindList;
        $pdu = $this->berEncode($pduType, $pduBody);

        // SNMPv2c Version = 1
        $msgBody = $this->encodeInteger(1)
                 . $this->berEncode(0x04, $this->community)
                 . $pdu;
        $packet = $this->berEncode(0x30, $msgBody);

        @fwrite($sock, $packet);
        $resp = @fread($sock, 2048);
        @fclose($sock);

        if (!$resp || strlen($resp) < 10) {
            return null;
        }
        return $this->decodeSnmpResponse($resp);
    }

    private function berEncode($tag, $data)
    {
        $len = strlen($data);
        if ($len < 128) {
            $lenBytes = chr($len);
        } elseif ($len < 256) {
            $lenBytes = "\x81" . chr($len);
        } else {
            $lenBytes = "\x82" . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        return chr($tag) . $lenBytes . $data;
    }

    private function encodeInteger($val)
    {
        $val = (int)$val;
        $bytes = array();
        do {
            array_unshift($bytes, $val & 0xFF);
            $val >>= 8;
        } while ($val > 0);
        if ($bytes[0] & 0x80) {
            array_unshift($bytes, 0x00);
        }
        $bin = '';
        foreach ($bytes as $b) {
            $bin .= chr($b);
        }
        return $this->berEncode(0x02, $bin);
    }

    private function encodeOid($oidStr)
    {
        $parts = array_map('intval', explode('.', ltrim($oidStr, '.')));
        if (count($parts) < 2) {
            return "\x06\x01\x00";
        }
        $bin = chr($parts[0] * 40 + $parts[1]);
        $count = count($parts);
        for ($i = 2; $i < $count; $i++) {
            $v = $parts[$i];
            if ($v < 128) {
                $bin .= chr($v);
            } else {
                $sub = array($v & 0x7F);
                $v >>= 7;
                while ($v > 0) {
                    array_unshift($sub, ($v & 0x7F) | 0x80);
                    $v >>= 7;
                }
                foreach ($sub as $sb) {
                    $bin .= chr($sb);
                }
            }
        }
        return $this->berEncode(0x06, $bin);
    }

    private function decodeSnmpResponse($bin)
    {
        $pos = 0;
        $seq = $this->berReadElement($bin, $pos);
        if (!$seq || $seq['tag'] !== 0x30) {
            return null;
        }
        $sub = $seq['value'];
        $spos = 0;
        $ver = $this->berReadElement($sub, $spos);
        $comm = $this->berReadElement($sub, $spos);
        $pdu = $this->berReadElement($sub, $spos);
        if (!$pdu || $pdu['tag'] !== 0xA2) { // GetResponse-PDU
            return null;
        }
        $pbody = $pdu['value'];
        $ppos = 0;
        $reqId = $this->berReadElement($pbody, $ppos);
        $errStatus = $this->berReadElement($pbody, $ppos);
        $errIndex = $this->berReadElement($pbody, $ppos);
        if (!$errStatus || ord($errStatus['value']) !== 0) {
            return null;
        }
        $vbList = $this->berReadElement($pbody, $ppos);
        if (!$vbList || $vbList['tag'] !== 0x30) {
            return null;
        }
        $vpos = 0;
        $vb = $this->berReadElement($vbList['value'], $vpos);
        if (!$vb || $vb['tag'] !== 0x30) {
            return null;
        }
        $ipos = 0;
        $oidElem = $this->berReadElement($vb['value'], $ipos);
        $valElem = $this->berReadElement($vb['value'], $ipos);
        if (!$oidElem || $oidElem['tag'] !== 0x06 || !$valElem) {
            return null;
        }
        // Check NoSuchObject (0x80), NoSuchInstance (0x81), EndOfMibView (0x82)
        if ($valElem['tag'] === 0x80 || $valElem['tag'] === 0x81 || $valElem['tag'] === 0x82) {
            return null;
        }

        $oidStr = $this->decodeOid($oidElem['value']);
        $valStr = $this->decodeValue($valElem['tag'], $valElem['value']);
        return array('oid' => $oidStr, 'value' => $valStr);
    }

    private function berReadElement($bin, &$pos)
    {
        $total = strlen($bin);
        if ($pos + 2 > $total) {
            return null;
        }
        $tag = ord($bin[$pos++]);
        $lenByte = ord($bin[$pos++]);
        if ($lenByte < 128) {
            $len = $lenByte;
        } else {
            $numBytes = $lenByte & 0x7F;
            if ($pos + $numBytes > $total) {
                return null;
            }
            $len = 0;
            for ($i = 0; $i < $numBytes; $i++) {
                $len = ($len << 8) | ord($bin[$pos++]);
            }
        }
        if ($pos + $len > $total) {
            return null;
        }
        $val = substr($bin, $pos, $len);
        $pos += $len;
        return array('tag' => $tag, 'value' => $val);
    }

    private function decodeOid($bin)
    {
        if (strlen($bin) === 0) {
            return '';
        }
        $first = ord($bin[0]);
        $parts = array((int)floor($first / 40), $first % 40);
        $val = 0;
        $len = strlen($bin);
        for ($i = 1; $i < $len; $i++) {
            $b = ord($bin[$i]);
            $val = ($val << 7) | ($b & 0x7F);
            if (($b & 0x80) === 0) {
                $parts[] = $val;
                $val = 0;
            }
        }
        return implode('.', $parts);
    }

    private function decodeValue($tag, $val)
    {
        if ($tag === 0x04) { // OCTET STRING
            return $val;
        }
        if ($tag === 0x02 || $tag === 0x41 || $tag === 0x42 || $tag === 0x43) { // INTEGER / Counter / Gauge / TimeTicks
            $num = 0;
            $len = strlen($val);
            for ($i = 0; $i < $len; $i++) {
                $num = ($num << 8) | ord($val[$i]);
            }
            return (string)$num;
        }
        if ($tag === 0x06) { // OID
            return $this->decodeOid($val);
        }
        if ($tag === 0x40 && strlen($val) === 4) { // IpAddress
            return sprintf('%d.%d.%d.%d', ord($val[0]), ord($val[1]), ord($val[2]), ord($val[3]));
        }
        return $val;
    }

    /**
     * Send an SNMPv2c SetRequest (0xA3) over raw UDP socket.
     * $type: 'i' (INTEGER, 0x02) or 's' (OCTET STRING, 0x04)
     */
    public function setOid($oid, $type, $value)
    {
        $sock = @fsockopen('udp://' . $this->ip, 161, $errno, $errstr, $this->timeoutSec);
        if (!$sock) {
            return false;
        }
        stream_set_timeout($sock, $this->timeoutSec, 0);

        $oidBin = $this->encodeOid($oid);
        if ($type === 'i') {
            $valBin = $this->encodeInteger((int)$value);
        } else {
            $valBin = $this->berEncode(0x04, (string)$value);
        }
        $varBind = $this->berEncode(0x30, $oidBin . $valBin);
        $varBindList = $this->berEncode(0x30, $varBind);

        $reqId = $this->encodeInteger(mt_rand(1000, 999999));
        $errStatus = $this->encodeInteger(0);
        $errIndex = $this->encodeInteger(0);
        // 0xA3 = SetRequest-PDU
        $pdu = $this->berEncode(0xA3, $reqId . $errStatus . $errIndex . $varBindList);

        $version = $this->encodeInteger(1); // SNMPv2c
        $comm = $this->berEncode(0x04, $this->community);
        $packet = $this->berEncode(0x30, $version . $comm . $pdu);

        @fwrite($sock, $packet);
        $resp = @fread($sock, 8192);
        @fclose($sock);

        if ($resp === false || strlen($resp) < 10) {
            return false;
        }
        $decoded = $this->decodeSnmpResponse($resp);
        return $decoded !== null;
    }

    /**
     * Erkennt automatisch das passende Switch-CLI-Herstellerprofil anhand von SNMP sysDescr, sysObjectID, Hersteller & OS.
     *
     * Unterstützte Profile:
     *  - cisco_smb         : Cisco Small Business (SG200/250/300/350/500, SF300/500, CBS250/350)
     *  - cisco_ios         : Cisco Catalyst IOS / IOS-XE, Arista EOS, Dell OS, FS.com, Extreme IS-CLI
     *  - hp_aruba          : HP ProCurve / ArubaOS-Switch / HPE OfficeConnect
     *  - tplink_jetstream  : TP-Link JetStream / Omada Managed Switches
     *  - netgear_fastpath  : Netgear ProSAFE (M4100/M4300/GS7xx), Ubiquiti EdgeSwitch, D-Link FASTPATH
     *  - mikrotik_routeros : MikroTik RouterOS (CRS / CCR / CSS mit RouterOS)
     *  - zyxel_gs          : Zyxel GS1920 / GS2210 / XGS Serie
     *  - snmp_set          : Reines SNMPv2c-SET (Q-BRIDGE-MIB / IF-MIB ohne SSH)
     *
     * @param array $devRow Datenbankzeile aus `devices` (oder Array mit vendor/snmp_sysdescr/snmp_sysobjectid/os_guess)
     * @return array array('profile' => string, 'label' => string)
     */
    public static function detectSwitchCliProfile(array $devRow = array())
    {
        $labels = array(
            'cisco_smb'         => 'Cisco Small Business CLI (SG/SF/CBS Serie)',
            'cisco_ios'         => 'Cisco IOS / Catalyst & kompatible Industry-CLI (FS/Dell/Arista)',
            'hp_aruba'          => 'HP ProCurve / ArubaOS-Switch CLI',
            'tplink_jetstream'  => 'TP-Link JetStream / Omada CLI',
            'netgear_fastpath'  => 'Netgear ProSAFE / Ubiquiti EdgeSwitch (FASTPATH CLI)',
            'mikrotik_routeros' => 'MikroTik RouterOS CLI',
            'zyxel_gs'          => 'Zyxel GS/XGS Enterprise CLI',
            'snmp_set'          => 'Standard SNMPv2c-SET (Q-BRIDGE-MIB / IF-MIB ohne SSH)',
        );

        // Falls in switch_snmp_write_community ein explizites Profil-Präfix gespeichert wurde (z.B. "profile:hp_aruba|private")
        $rawWriteField = isset($devRow['switch_snmp_write_community']) ? (string)$devRow['switch_snmp_write_community'] : '';
        if (preg_match('/^profile:([a-z0-9_]+)\|(.*)$/i', $rawWriteField, $pm)) {
            $forced = strtolower($pm[1]);
            if (isset($labels[$forced])) {
                return array('profile' => $forced, 'label' => $labels[$forced], 'is_manual' => true);
            }
        }

        $haystack = strtolower(
            (isset($devRow['snmp_sysdescr']) ? $devRow['snmp_sysdescr'] : '') . ' ' .
            (isset($devRow['snmp_sysobjectid']) ? $devRow['snmp_sysobjectid'] : '') . ' ' .
            (isset($devRow['vendor']) ? $devRow['vendor'] : '') . ' ' .
            (isset($devRow['os_guess']) ? $devRow['os_guess'] : '') . ' ' .
            (isset($devRow['service_info']) ? $devRow['service_info'] : '') . ' ' .
            (isset($devRow['hostname']) ? $devRow['hostname'] : '')
        );

        // 1. MikroTik RouterOS
        if (strpos($haystack, 'mikrotik') !== false || strpos($haystack, 'routeros') !== false || strpos($haystack, '1.3.6.1.4.1.14988') !== false) {
            return array('profile' => 'mikrotik_routeros', 'label' => $labels['mikrotik_routeros'], 'is_manual' => false);
        }

        // 2. HP ProCurve / ArubaOS-Switch
        if (strpos($haystack, 'procurve') !== false || strpos($haystack, 'aruba') !== false || strpos($haystack, '1.3.6.1.4.1.11.2.3.7.11') !== false || strpos($haystack, 'hewlett') !== false || strpos($haystack, 'hpe ') !== false) {
            return array('profile' => 'hp_aruba', 'label' => $labels['hp_aruba'], 'is_manual' => false);
        }

        // 3. TP-Link JetStream / Omada
        if (strpos($haystack, 'tp-link') !== false || strpos($haystack, 'tplink') !== false || strpos($haystack, 'jetstream') !== false || strpos($haystack, '1.3.6.1.4.1.11863') !== false) {
            return array('profile' => 'tplink_jetstream', 'label' => $labels['tplink_jetstream'], 'is_manual' => false);
        }

        // 4. Netgear / Ubiquiti EdgeSwitch
        if (strpos($haystack, 'netgear') !== false || strpos($haystack, 'prosafe') !== false || strpos($haystack, 'edgeswitch') !== false || strpos($haystack, 'ubiquiti') !== false || strpos($haystack, '1.3.6.1.4.1.4526') !== false) {
            return array('profile' => 'netgear_fastpath', 'label' => $labels['netgear_fastpath'], 'is_manual' => false);
        }

        // 5. Zyxel
        if (strpos($haystack, 'zyxel') !== false || strpos($haystack, '1.3.6.1.4.1.890') !== false) {
            return array('profile' => 'zyxel_gs', 'label' => $labels['zyxel_gs'], 'is_manual' => false);
        }

        // 6. Klassisches Cisco IOS / Catalyst / Dell / Arista
        if (strpos($haystack, 'cisco ios') !== false || strpos($haystack, 'catalyst') !== false || strpos($haystack, 'arista') !== false || strpos($haystack, 'dell') !== false) {
            return array('profile' => 'cisco_ios', 'label' => $labels['cisco_ios'], 'is_manual' => false);
        }

        // 7. Standard: Cisco Small Business (SG/SF/CBS) & kompatible CLI
        return array('profile' => 'cisco_smb', 'label' => $labels['cisco_smb'], 'is_manual' => false);
    }

    /**
     * Erzeugt die herstellerspezifischen SSH-CLI-Befehle für das gewählte Switch-Profil.
     *
     * @param string $profile
     * @param array  $params
     * @return array array('paging_cmd' => string, 'commands' => array)
     */
    public static function buildVendorCliCommands($profile, array $params)
    {
        $portName = isset($params['port_name']) ? trim((string)$params['port_name']) : '';
        $portMode = (isset($params['port_mode']) && strtolower(trim((string)$params['port_mode'])) === 'trunk') ? 'trunk' : 'access';
        $trunkAllowedRaw = isset($params['trunk_allowed_vlans']) ? trim((string)$params['trunk_allowed_vlans']) : 'all';
        $trunkAllowed = preg_replace('/[^0-9,\-a-zA-Z]/', '', $trunkAllowedRaw);
        if ($trunkAllowed === '') {
            $trunkAllowed = 'all';
        }

        $createVlanId = isset($params['create_vlan_id']) ? (int)$params['create_vlan_id'] : 0;
        $createVlanName = isset($params['create_vlan_name']) ? trim((string)$params['create_vlan_name']) : '';
        $vlanId = isset($params['vlan_id']) ? (int)$params['vlan_id'] : 0;
        if ($createVlanId >= 2 && $createVlanId <= 4094) {
            $vlanId = $createVlanId;
        }
        $cleanVlanName = ($createVlanName !== '') ? substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $createVlanName), 0, 32) : '';

        $hasAlias = array_key_exists('alias', $params);
        $cleanAlias = $hasAlias ? substr(preg_replace('/[^a-zA-Z0-9 _.:\/-]/', '', trim((string)$params['alias'])), 0, 64) : '';
        $admStatus = isset($params['admin_status']) ? strtolower(trim((string)$params['admin_status'])) : '';
        $saveConfig = !isset($params['save_config']) || !empty($params['save_config']);

        $commands = array();
        $pagingCmd = 'terminal length 0';

        switch ($profile) {
            case 'hp_aruba':
                // HP ProCurve / ArubaOS-Switch CLI
                $pagingCmd = 'no page';
                $commands[] = 'configure terminal';
                if ($createVlanId >= 2 && $createVlanId <= 4094) {
                    $commands[] = 'vlan ' . $createVlanId;
                    if ($cleanVlanName !== '') {
                        $commands[] = 'name "' . $cleanVlanName . '"';
                    }
                    $commands[] = 'exit';
                }
                if ($vlanId >= 1 && $vlanId <= 4094) {
                    $commands[] = 'vlan ' . $vlanId;
                    $commands[] = 'untagged ' . $portName;
                    $commands[] = 'exit';
                }
                if ($portMode === 'trunk' && strtolower($trunkAllowed) !== 'all') {
                    foreach (explode(',', $trunkAllowed) as $tv) {
                        $tvInt = (int)$tv;
                        if ($tvInt >= 1 && $tvInt <= 4094 && $tvInt !== $vlanId) {
                            $commands[] = 'vlan ' . $tvInt;
                            $commands[] = 'tagged ' . $portName;
                            $commands[] = 'exit';
                        }
                    }
                }
                $commands[] = 'interface ' . $portName;
                if ($hasAlias) {
                    if ($cleanAlias === '') {
                        $commands[] = 'no name';
                    } else {
                        $commands[] = 'name "' . $cleanAlias . '"';
                    }
                }
                if ($admStatus === 'down') {
                    $commands[] = 'disable';
                } elseif ($admStatus === 'up') {
                    $commands[] = 'enable';
                }
                $commands[] = 'exit';
                $commands[] = 'exit';
                if ($saveConfig) {
                    $commands[] = 'write memory';
                }
                break;

            case 'tplink_jetstream':
                // TP-Link JetStream / Omada Managed Switch CLI
                $pagingCmd = 'terminal length 0';
                $commands[] = 'configure';
                if ($createVlanId >= 2 && $createVlanId <= 4094) {
                    $commands[] = 'vlan ' . $createVlanId;
                    if ($cleanVlanName !== '') {
                        $commands[] = 'name ' . $cleanVlanName;
                    }
                    $commands[] = 'exit';
                }
                $commands[] = 'interface ' . $portName;
                if ($portMode === 'trunk') {
                    if (strtolower($trunkAllowed) !== 'all') {
                        $commands[] = 'switchport general allowed vlan ' . $trunkAllowed . ' tagged';
                    }
                    if ($vlanId >= 1 && $vlanId <= 4094) {
                        $commands[] = 'switchport pvid ' . $vlanId;
                    }
                } elseif ($vlanId >= 1 && $vlanId <= 4094) {
                    $commands[] = 'switchport general allowed vlan ' . $vlanId . ' untagged';
                    $commands[] = 'switchport pvid ' . $vlanId;
                }
                if ($hasAlias) {
                    $commands[] = ($cleanAlias === '') ? 'no description' : ('description ' . $cleanAlias);
                }
                if ($admStatus === 'down') {
                    $commands[] = 'shutdown';
                } elseif ($admStatus === 'up') {
                    $commands[] = 'no shutdown';
                }
                $commands[] = 'end';
                if ($saveConfig) {
                    $commands[] = 'copy running-config startup-config';
                }
                break;

            case 'netgear_fastpath':
                // Netgear ProSAFE / Ubiquiti EdgeSwitch (Broadcom FASTPATH CLI)
                $pagingCmd = 'terminal length 0';
                if ($createVlanId >= 2 && $createVlanId <= 4094) {
                    $commands[] = 'vlan database';
                    $commands[] = 'vlan ' . $createVlanId;
                    if ($cleanVlanName !== '') {
                        $commands[] = 'vlan name ' . $createVlanId . ' "' . $cleanVlanName . '"';
                    }
                    $commands[] = 'exit';
                }
                $commands[] = 'configure';
                $commands[] = 'interface ' . $portName;
                if ($portMode === 'trunk') {
                    $commands[] = 'switchport mode trunk';
                    if ($vlanId >= 1 && $vlanId <= 4094) {
                        $commands[] = 'switchport trunk native vlan ' . $vlanId;
                    }
                    if (strtolower($trunkAllowed) !== 'all') {
                        $commands[] = 'switchport trunk allowed vlan ' . $trunkAllowed;
                    }
                } elseif ($vlanId >= 1 && $vlanId <= 4094) {
                    $commands[] = 'vlan pvid ' . $vlanId;
                    $commands[] = 'vlan participation include ' . $vlanId;
                }
                if ($hasAlias) {
                    $commands[] = ($cleanAlias === '') ? 'no description' : ('description "' . $cleanAlias . '"');
                }
                if ($admStatus === 'down') {
                    $commands[] = 'shutdown';
                } elseif ($admStatus === 'up') {
                    $commands[] = 'no shutdown';
                }
                $commands[] = 'exit';
                $commands[] = 'exit';
                if ($saveConfig) {
                    $commands[] = 'write memory';
                }
                break;

            case 'mikrotik_routeros':
                // MikroTik RouterOS CLI
                $pagingCmd = '';
                if ($vlanId >= 1 && $vlanId <= 4094) {
                    $commands[] = '/interface bridge port set [find interface="' . $portName . '"] pvid=' . $vlanId;
                }
                if ($hasAlias) {
                    $commands[] = '/interface ethernet set [find name="' . $portName . '"] comment="' . $cleanAlias . '"';
                }
                if ($admStatus === 'down') {
                    $commands[] = '/interface ethernet disable [find name="' . $portName . '"]';
                } elseif ($admStatus === 'up') {
                    $commands[] = '/interface ethernet enable [find name="' . $portName . '"]';
                }
                break;

            case 'zyxel_gs':
                // Zyxel GS/XGS Enterprise CLI
                $pagingCmd = 'terminal length 0';
                $commands[] = 'configure terminal';
                if ($vlanId >= 1 && $vlanId <= 4094) {
                    $commands[] = 'vlan ' . $vlanId;
                    if ($createVlanId >= 2 && $cleanVlanName !== '') {
                        $commands[] = 'name ' . $cleanVlanName;
                    }
                    $commands[] = 'normal ' . $portName;
                    $commands[] = ($portMode === 'trunk') ? ('fixed ' . $portName) : ('untagged ' . $portName);
                    $commands[] = 'exit';
                }
                $commands[] = 'interface port-channel ' . $portName;
                if ($vlanId >= 1 && $vlanId <= 4094) {
                    $commands[] = 'pvid ' . $vlanId;
                }
                if ($hasAlias && $cleanAlias !== '') {
                    $commands[] = 'name ' . $cleanAlias;
                }
                if ($admStatus === 'down') {
                    $commands[] = 'inactive';
                } elseif ($admStatus === 'up') {
                    $commands[] = 'no inactive';
                }
                $commands[] = 'exit';
                $commands[] = 'exit';
                if ($saveConfig) {
                    $commands[] = 'write memory';
                }
                break;

            case 'cisco_ios':
                // Klassisches Cisco IOS / IOS-XE / Catalyst Core-Switch / Arista / Dell / FS.com
                $pagingCmd = 'terminal length 0';
                $commands[] = 'configure terminal';
                if ($createVlanId >= 2 && $createVlanId <= 4094) {
                    $commands[] = 'vlan ' . $createVlanId;
                    if ($cleanVlanName !== '') {
                        $commands[] = 'name ' . $cleanVlanName;
                    }
                    $commands[] = 'exit';
                }
                $commands[] = 'interface ' . $portName;
                $commands[] = 'switchport'; // Falls Port am L3-Core-Switch zuvor als "no switchport" (Routed Port) konfiguriert war
                if ($portMode === 'trunk') {
                    $commands[] = 'switchport trunk encapsulation dot1q';
                    $commands[] = 'switchport mode trunk';
                    if ($vlanId >= 1 && $vlanId <= 4094) {
                        $commands[] = 'switchport trunk native vlan ' . $vlanId;
                    }
                    $commands[] = 'switchport trunk allowed vlan ' . (strtolower($trunkAllowed) === 'all' ? 'all' : $trunkAllowed);
                } elseif ($vlanId >= 1 && $vlanId <= 4094) {
                    $commands[] = 'switchport mode access';
                    $commands[] = 'switchport access vlan ' . $vlanId;
                }
                if ($hasAlias) {
                    $commands[] = ($cleanAlias === '') ? 'no description' : ('description ' . $cleanAlias);
                }
                if ($admStatus === 'down') {
                    $commands[] = 'shutdown';
                } elseif ($admStatus === 'up') {
                    $commands[] = 'no shutdown';
                }
                $commands[] = 'end';
                if ($saveConfig) {
                    $commands[] = 'write memory';
                }
                break;

            case 'cisco_smb':
            default:
                // Cisco Small Business (SG200/250/300/350/500, SF, CBS)
                $pagingCmd = 'terminal datadump';
                $commands[] = 'configure terminal';
                if ($createVlanId >= 2 && $createVlanId <= 4094) {
                    $commands[] = 'vlan database';
                    $commands[] = 'vlan ' . $createVlanId;
                    $commands[] = 'exit';
                    if ($cleanVlanName !== '') {
                        $commands[] = 'interface vlan ' . $createVlanId;
                        $commands[] = 'name ' . $cleanVlanName;
                        $commands[] = 'exit';
                    }
                }
                $commands[] = 'interface ' . $portName;
                if ($portMode === 'trunk') {
                    $commands[] = 'switchport mode trunk';
                    if ($vlanId >= 1 && $vlanId <= 4094) {
                        $commands[] = 'switchport trunk native vlan ' . $vlanId;
                    }
                    if (strtolower($trunkAllowed) === 'all') {
                        $commands[] = 'switchport trunk allowed vlan add all';
                    } else {
                        $commands[] = 'switchport trunk allowed vlan add ' . $trunkAllowed;
                    }
                } elseif ($vlanId >= 1 && $vlanId <= 4094) {
                    $commands[] = 'switchport mode access';
                    $commands[] = 'switchport access vlan ' . $vlanId;
                }
                if ($hasAlias) {
                    $commands[] = ($cleanAlias === '') ? 'no description' : ('description ' . $cleanAlias);
                }
                if ($admStatus === 'down') {
                    $commands[] = 'shutdown';
                } elseif ($admStatus === 'up') {
                    $commands[] = 'no shutdown';
                }
                $commands[] = 'exit';
                $commands[] = 'exit';
                if ($saveConfig) {
                    $commands[] = 'copy running-config startup-config';
                }
                break;
        }

        return array(
            'paging_cmd' => $pagingCmd,
            'commands'   => $commands,
        );
    }

    /**
     * Herstellerunabhängiger SNMPv2c-SET Fallback (über Q-BRIDGE-MIB dot1qPvid & IF-MIB ifAlias / ifAdminStatus)
     * für Web-Smart-Switche ohne SSH-Server.
     */
    public static function configureSwitchPortViaSnmpSet($ip, $writeCommunity, array $params, array $snmpData = array())
    {
        $portName = isset($params['port_name']) ? trim((string)$params['port_name']) : '';
        $vlanId = isset($params['vlan_id']) ? (int)$params['vlan_id'] : 0;
        if (!empty($params['create_vlan_id']) && (int)$params['create_vlan_id'] >= 2) {
            $vlanId = (int)$params['create_vlan_id'];
        }

        $ifIndex = 0;
        if (!empty($snmpData['interfaces']) && is_array($snmpData['interfaces'])) {
            foreach ($snmpData['interfaces'] as $inf) {
                if (isset($inf['name']) && strcasecmp(trim($inf['name']), $portName) === 0) {
                    $ifIndex = (int)$inf['ifIndex'];
                    break;
                }
            }
        }
        if ($ifIndex <= 0 && preg_match('/(\d+)$/', $portName, $m)) {
            $ifIndex = (int)$m[1];
        }
        if ($ifIndex <= 0) {
            return array('success' => false, 'message' => 'ifIndex für Port ' . $portName . ' konnte nicht ermittelt werden.');
        }

        $client = new self($ip, $writeCommunity !== '' ? $writeCommunity : 'private', 1500);
        $executed = array();
        $okCount = 0;

        // 1. PVID (dot1qPvid = 1.3.6.1.2.1.17.7.1.4.5.1.1.<port>)
        if ($vlanId >= 1 && $vlanId <= 4094) {
            $oidPvid = '1.3.6.1.2.1.17.7.1.4.5.1.1.' . $ifIndex;
            if ($client->setOid($oidPvid, 'i', $vlanId)) {
                $okCount++;
            }
            $executed[] = 'SNMP-SET dot1qPvid.' . $ifIndex . ' = ' . $vlanId;
        }

        // 2. Port-Alias (ifAlias = 1.3.6.1.2.1.31.1.1.1.18.<ifIndex>)
        if (array_key_exists('alias', $params)) {
            $oidAlias = '1.3.6.1.2.1.31.1.1.1.18.' . $ifIndex;
            if ($client->setOid($oidAlias, 's', trim((string)$params['alias']))) {
                $okCount++;
            }
            $executed[] = 'SNMP-SET ifAlias.' . $ifIndex . ' = "' . trim((string)$params['alias']) . '"';
        }

        // 3. Admin-Status (ifAdminStatus = 1.3.6.1.2.1.2.2.1.7.<ifIndex>: 1=up, 2=down)
        if (!empty($params['admin_status'])) {
            $admInt = (strtolower($params['admin_status']) === 'down') ? 2 : 1;
            $oidAdm = '1.3.6.1.2.1.2.2.1.7.' . $ifIndex;
            if ($client->setOid($oidAdm, 'i', $admInt)) {
                $okCount++;
            }
            $executed[] = 'SNMP-SET ifAdminStatus.' . $ifIndex . ' = ' . $admInt;
        }

        if ($okCount === 0) {
            return array(
                'success'  => false,
                'message'  => 'Switch hat das SNMPv2c-SET Paket abgelehnt (bitte prüfen, ob SNMP Read-Write Community auf dem Switch aktiviert ist).',
                'commands' => $executed,
            );
        }

        return array(
            'success'  => true,
            'message'  => 'Port ' . $portName . ' erfolgreich per SNMPv2c-SET umgestellt.',
            'commands' => $executed,
        );
    }

    /**
     * Configure a managed switch (Multi-Vendor: Cisco SMB, Cisco IOS, HP/Aruba, TP-Link, Netgear/Ubiquiti, MikroTik, Zyxel) via SSH.
     * Supports:
     *  - action_type = 'test' (test SSH login and return hostname/prompt)
     *  - action_type = 'configure_port' (set access VLAN, create VLAN if needed, set description, set admin UP/DOWN, save config)
     *
     * @param string $ip
     * @param string $user
     * @param string $pass
     * @param array  $params
     * @return array array('success' => bool, 'message' => string, 'output' => string, 'commands' => array, 'profile' => string)
     */
    public static function configureSwitchViaSsh($ip, $user, $pass, array $params = array())
    {
        $ip = trim((string)$ip);
        $user = trim((string)$user);
        $pass = (string)$pass;

        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return array('success' => false, 'message' => 'Ungültige Switch-IP-Adresse.');
        }
        if ($user === '' || $pass === '') {
            return array('success' => false, 'message' => 'SSH-Benutzername und Passwort sind erforderlich.');
        }

        $actionType = isset($params['action_type']) ? $params['action_type'] : 'configure_port';
        $profile = isset($params['cli_profile']) && $params['cli_profile'] !== '' ? $params['cli_profile'] : 'cisco_smb';
        $commands = array();
        $pagingCmd = 'terminal datadump';

        if ($actionType === 'configure_port') {
            $portName = isset($params['port_name']) ? trim((string)$params['port_name']) : '';
            if ($portName === '' || !preg_match('/^[a-zA-Z0-9\/._-]+$/', $portName)) {
                return array('success' => false, 'message' => 'Ungültiger Port-Name: ' . $portName);
            }
            $built = self::buildVendorCliCommands($profile, $params);
            $pagingCmd = $built['paging_cmd'];
            $commands = $built['commands'];
        }

        $enablePass = isset($params['enable_pass']) && (string)$params['enable_pass'] !== '' ? (string)$params['enable_pass'] : $pass;

        $payload = base64_encode(json_encode(array(
            'ip'          => $ip,
            'user'        => $user,
            'pass'        => $pass,
            'enable_pass' => $enablePass,
            'paging_cmd'  => $pagingCmd,
            'commands'    => $commands
        )));

        $pyScript = <<<'PY'
import sys, os, pty, time, select, json, base64

data = json.loads(base64.b64decode(sys.argv[1]).decode('utf-8'))
ip = data['ip']
user = data['user']
pw = data['pass']
enable_pw = data.get('enable_pass', pw)
paging_cmd = data.get('paging_cmd', '')
commands = data['commands']

pid, fd = pty.fork()
if pid == 0:
    os.execvp('ssh', [
        'ssh',
        '-o', 'StrictHostKeyChecking=no',
        '-o', 'UserKnownHostsFile=/dev/null',
        '-o', 'ConnectTimeout=8',
        '-o', 'KexAlgorithms=+diffie-hellman-group1-sha1,diffie-hellman-group14-sha1',
        '-o', 'HostKeyAlgorithms=+ssh-rsa',
        '-o', 'Ciphers=+aes128-cbc,3des-cbc,aes192-cbc,aes256-cbc',
        user + '@' + ip
    ])

buf = b''
def read_until(patterns, timeout=10.0):
    global buf
    end = time.time() + timeout
    while time.time() < end:
        r, _, _ = select.select([fd], [], [], 0.15)
        if fd in r:
            try:
                chunk = os.read(fd, 4096)
                if not chunk:
                    break
                buf += chunk
                for p in patterns:
                    if p in buf:
                        return p
            except OSError:
                break
    return None

matched = read_until([b'Password:', b'password:', b'User Name:', b'Username:', b'Press any key to continue', b'#', b'>'], 12.0)
if matched in [b'User Name:', b'Username:']:
    os.write(fd, (user + '\r').encode('utf-8'))
    buf = b''
    matched = read_until([b'Password:', b'password:'], 8.0)

if matched in [b'Password:', b'password:']:
    os.write(fd, (pw + '\r').encode('utf-8'))
    buf = b''
    matched = read_until([b'Press any key to continue', b'#', b'>', b'Password:', b'password:', b'Permission denied', b'Authentication failed'], 10.0)

if matched == b'Press any key to continue':
    os.write(fd, b'\r')
    buf = b''
    matched = read_until([b'#', b'>'], 5.0)

if matched not in [b'#', b'>']:
    out_str = buf.decode('utf-8', 'ignore')
    print(json.dumps({'success': False, 'message': 'SSH-Authentifizierung fehlgeschlagen oder Timeout beim Verbindungsaufbau.', 'output': out_str}))
    sys.exit(0)

if matched == b'>' and b'] >' not in buf:
    os.write(fd, b'enable\r')
    buf = b''
    m2 = read_until([b'#', b'Password:', b'password:'], 5.0)
    if m2 in [b'Password:', b'password:']:
        os.write(fd, (enable_pw + '\r').encode('utf-8'))
        buf = b''
        read_until([b'#'], 5.0)

full_log = buf.decode('utf-8', 'ignore')
buf = b''

if paging_cmd:
    os.write(fd, (paging_cmd + '\r').encode('utf-8'))
    read_until([b'#', b'>'], 3.0)
    buf = b''

for cmd in commands:
    os.write(fd, (cmd + '\r').encode('utf-8'))
    m = read_until([b'(Y/N)', b'[Y/N]', b'(y/n)', b'[y/n]', b'Overwrite file', b'[confirm]', b'Are you sure', b'#', b'>'], 12.0)
    full_log += buf.decode('utf-8', 'ignore')
    buf = b''
    if m in [b'(Y/N)', b'[Y/N]', b'(y/n)', b'[y/n]', b'Overwrite file', b'[confirm]', b'Are you sure']:
        os.write(fd, b'Y\r')
        read_until([b'#', b'>'], 20.0)
        full_log += buf.decode('utf-8', 'ignore')
        buf = b''

try:
    os.write(fd, b'exit\r')
    os.close(fd)
except Exception:
    pass

print(json.dumps({'success': True, 'message': 'SSH-Befehle erfolgreich ausgeführt.', 'output': full_log}))
PY;

        $pyBin = 'python3';
        $checkPy = @shell_exec('which python3 2>/dev/null');
        if (!$checkPy || trim($checkPy) === '') {
            $pyBin = 'python';
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'nm_ssh_');
        file_put_contents($tmpFile, $pyScript);
        $cmdLine = $pyBin . ' ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($payload) . ' 2>&1';
        $rawOut = @shell_exec($cmdLine);
        @unlink($tmpFile);

        if (!$rawOut) {
            return array('success' => false, 'message' => 'Fehler beim Starten des SSH-Clients.', 'commands' => $commands, 'profile' => $profile);
        }

        // Find the last JSON line in output
        $lines = explode("\n", trim($rawOut));
        $lastLine = end($lines);
        $res = @json_decode($lastLine, true);
        if (!is_array($res)) {
            return array(
                'success'  => false,
                'message'  => 'Unerwartete SSH-Antwort vom Switch.',
                'output'   => $rawOut,
                'commands' => $commands,
                'profile'  => $profile
            );
        }
        $res['commands'] = $commands;
        $res['profile'] = $profile;
        return $res;
    }
}
