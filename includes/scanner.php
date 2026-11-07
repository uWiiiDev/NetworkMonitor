<?php
/**
 * NetworkScanner - Discovery Engine (Nmap / Parallel Ping / ARP / Async TCP Ports / SNMP / Topologie-Builder)
 * Kompatibel mit PHP 5.5+ (Ubuntu 14.04) bis PHP 8.x
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/oui.php';
require_once __DIR__ . '/snmp_client.php';
require_once __DIR__ . '/mailer.php';

class NetworkScanner
{
    private $db;
    private $pdo;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getPdo();
        $fullConf = $this->db->getConfig();
        $this->config = $fullConf['scanner'];
    }

    /**
     * Scannt einen einzelnen Scope anhand seiner ID
     */
    public function scanScope($scopeId, $triggerType = 'manual')
    {
        $stmt = $this->pdo->prepare("SELECT * FROM scopes WHERE id = :id");
        $stmt->execute(array(':id' => (int)$scopeId));
        $scope = $stmt->fetch();
        if (!$scope) {
            throw new InvalidArgumentException("Scope ID $scopeId nicht gefunden.");
        }

        $scanStartMicro = microtime(true);
        $now = date('Y-m-d H:i:s');
        $logStmt = $this->pdo->prepare("
            INSERT INTO scan_logs (scope_id, trigger_type, started_at, status, summary)
            VALUES (:sid, :trig, :start, 'running', 'Scan gestartet...')
        ");
        $logStmt->execute(array(
            ':sid'   => $scope['id'],
            ':trig'  => $triggerType,
            ':start' => $now,
        ));
        $logId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("UPDATE scopes SET last_scan_status = 'running', last_scan_at = :now WHERE id = :id")
                  ->execute(array(':now' => $now, ':id' => $scope['id']));

        try {
            $ipList = $this->expandCidrOrRange($scope['cidr'], $this->config['max_hosts_per_scan']);
            $rawGw = isset($scope['gateway_ip']) ? trim($scope['gateway_ip']) : '';
            if ($rawGw !== '' && preg_match('/^\.?([0-9]{1,3})$/', $rawGw, $gwMatch) && !empty($ipList)) {
                $parts = explode('.', $ipList[0]);
                if (count($parts) === 4) {
                    $rawGw = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . (int)$gwMatch[1];
                    $this->pdo->prepare("UPDATE scopes SET gateway_ip = :gw WHERE id = :id")
                              ->execute(array(':gw' => $rawGw, ':id' => $scope['id']));
                }
            }
            $gatewayIp = ($rawGw !== '' && filter_var($rawGw, FILTER_VALIDATE_IP))
                ? $rawGw
                : (!empty($ipList) ? $ipList[0] : null);
            $community = !empty($scope['snmp_community']) ? $scope['snmp_community'] : $this->config['default_snmp_community'];

            // 1. Live-Hosts & Latenz ermitteln (Nmap falls vorhanden, sonst Parallel-Ping + ARP-Cache)
            $liveHosts = $this->discoverLiveHosts($scope['cidr'], $ipList);

            // Lokale Server-IP einbeziehen, falls im Subnetz
            $localServerInfo = $this->getLocalServerInterfaces();
            foreach ($localServerInfo as $lip => $lmac) {
                if (in_array($lip, $ipList, true)) {
                    if (!isset($liveHosts[$lip])) {
                        $liveHosts[$lip] = array('latency_ms' => 0.1, 'mac' => $lmac, 'hostname' => gethostname(), 'ttl' => 64);
                    } elseif (empty($liveHosts[$lip]['mac'])) {
                        $liveHosts[$lip]['mac'] = $lmac;
                    }
                }
            }

            // ARP-Tabelle nach dem Ping-Sweep auslesen & Proxy-ARP (z.B. FRITZ!Box VPN-IPs .201/.202/.203) herausfiltern
            $arpMap = $this->readArpTable();
            $gatewayMac = null;
            if ($gatewayIp && isset($arpMap[$gatewayIp])) {
                $gatewayMac = OuiLookup::normalizeMac($arpMap[$gatewayIp]);
            } elseif ($gatewayIp && !empty($liveHosts[$gatewayIp]['mac'])) {
                $gatewayMac = OuiLookup::normalizeMac($liveHosts[$gatewayIp]['mac']);
            }

            // Zusätzlich prüfen, ob eine MAC-Adresse unnatürlich oft (>= 3 verschiedene IPs) im ARP-Cache auftaucht (Proxy-ARP)
            $macUsageCount = array();
            foreach ($arpMap as $aip0 => $amac0) {
                if (in_array($aip0, $ipList, true)) {
                    $nm = OuiLookup::normalizeMac($amac0);
                    if ($nm) {
                        $macUsageCount[$nm] = isset($macUsageCount[$nm]) ? ($macUsageCount[$nm] + 1) : 1;
                    }
                }
            }

            foreach ($arpMap as $aip => $amac) {
                if (in_array($aip, $ipList, true)) {
                    $normMac = OuiLookup::normalizeMac($amac);
                    $isProxyArp = ($aip !== $gatewayIp) && (
                        ($gatewayMac && $normMac === $gatewayMac) ||
                        ($normMac && isset($macUsageCount[$normMac]) && $macUsageCount[$normMac] >= 3)
                    );

                    if ($isProxyArp) {
                        if (!isset($liveHosts[$aip])) {
                            // Inaktive Proxy-ARP / VPN-Reservierung (antwortet nicht auf Ping):
                            // Nicht als falsches Gerät übernehmen und eventuelle Altlasten aus der DB bereinigen!
                            $this->pdo->prepare("DELETE FROM devices WHERE scope_id = :sid AND ip_address = :ip AND mac_address = :mac")
                                      ->execute(array(':sid' => $scope['id'], ':ip' => $aip, ':mac' => $normMac));
                            continue;
                        } else {
                            // Aktiver VPN-Client antwortet auf Ping über das Gateway (Proxy-ARP)
                            $liveHosts[$aip]['is_proxy_arp'] = true;
                            $liveHosts[$aip]['mac'] = null;
                        }
                    } else {
                        if (!isset($liveHosts[$aip])) {
                            $liveHosts[$aip] = array('latency_ms' => 1.0, 'mac' => $amac, 'hostname' => null, 'ttl' => null);
                        } elseif (empty($liveHosts[$aip]['mac'])) {
                            $liveHosts[$aip]['mac'] = $amac;
                        }
                    }
                }
            }

            // Vorherigen Gerätestand dieses Scopes für die Alarm- & Anomalie-Auswertung merken
            $prevStmt = $this->pdo->prepare("SELECT id, ip_address, mac_address, vendor, hostname, custom_name, device_type, status, alert_mode, last_alert_state, switch_snmp_community, snmp_ports_json FROM devices WHERE scope_id = :sid");
            $prevStmt->execute(array(':sid' => $scope['id']));
            $prevDevicesByIp = array();
            $knownMacsBeforeScan = array();
            while ($pr = $prevStmt->fetch()) {
                $prevDevicesByIp[$pr['ip_address']] = $pr;
                if (!empty($pr['mac_address'])) {
                    $knownMacsBeforeScan[strtoupper(trim($pr['mac_address']))] = true;
                }
                // Sicherheits-Nach-Ping für überwachte Geräte, falls ein einzelnes Ping-Paket verloren ging
                $am = isset($pr['alert_mode']) ? $pr['alert_mode'] : 'none';
                if (($am === 'offline' || $am === 'both') && !isset($liveHosts[$pr['ip_address']])) {
                    $reOut = @shell_exec('ping -n -c 2 -W 1 ' . escapeshellarg($pr['ip_address']) . ' 2>/dev/null');
                    if ($reOut && preg_match('/time[=<]([0-9.]+)\s*ms/i', $reOut, $rm)) {
                        $rTtl = null;
                        if (preg_match('/ttl[=<]([0-9]+)/i', $reOut, $rtm)) {
                            $rTtl = (int)$rtm[1];
                        }
                        $liveHosts[$pr['ip_address']] = array(
                            'latency_ms' => (float)$rm[1],
                            'mac'        => isset($arpMap[$pr['ip_address']]) ? $arpMap[$pr['ip_address']] : null,
                            'hostname'   => null,
                            'ttl'        => $rTtl,
                        );
                    }
                }
            }

            // Alle bisher global bekannten MAC-Adressen (aus devices & mac_port_history) vor diesem Scan laden
            $hadBaseline = !empty($prevDevicesByIp);
            try {
                $allMacStmt = $this->pdo->query("SELECT mac_address FROM devices WHERE mac_address IS NOT NULL AND mac_address != ''");
                while ($mr = $allMacStmt->fetch()) {
                    $knownMacsBeforeScan[strtoupper(trim($mr['mac_address']))] = true;
                }
                $histMacStmt = $this->pdo->query("SELECT DISTINCT mac_address FROM mac_port_history");
                while ($hmr = $histMacStmt->fetch()) {
                    $knownMacsBeforeScan[strtoupper(trim($hmr['mac_address']))] = true;
                }
            } catch (Exception $e) {}

            // Alle bisherigen Geräte dieses Scopes zunächst als offline markieren (sofern nicht manuell)
            $this->pdo->prepare("UPDATE devices SET status = 'offline' WHERE scope_id = :sid")
                      ->execute(array(':sid' => $scope['id']));

            $switchesFound = 0;
            $hostsUp = count($liveHosts);
            $liveIpList = array_keys($liveHosts);

            // 2. Multi-Host-Batch: Hostnamen für alle aktiven IPs in einem Rutsch auflösen (UDP DNS PTR)
            $batchHostnames = $this->resolveHostnamesBatch($liveIpList);

            // 3. Multi-Host-Batch: NetBIOS Node Status (UDP 137) für Windows-Namen & Arbeitsgruppen
            $batchNetbios = $this->probeNetbiosBatch($liveIpList, 250);

            // 4. Multi-Host-Batch: TCP-Port-Scan für je 32 Hosts gleichzeitig (statt einzeln nacheinander!)
            $portBatchSize = isset($this->config['port_host_batch_size']) ? (int)$this->config['port_host_batch_size'] : 32;
            $batchOpenPorts = $this->probeTcpPortsMultiHostBatch(
                $liveIpList,
                $this->config['probe_ports'],
                $this->config['port_timeout_ms'],
                $portBatchSize
            );

            // 5. Multi-Host-Batch: HTTP/HTTPS (<title> + Server-Header) & SSH-Banner Grabbing für offene Ports
            $batchBanners = $this->probeServiceBannersBatch($batchOpenPorts, 500);

            // 6. Multi-Host-Batch: SNMP UDP-Vorfilter für je 100 Hosts gleichzeitig
            // Nur Geräte, die auf dieses schnelle UDP-Paket antworten, werden im Detail per SNMP abgefragt!
            $snmpBatchSize = isset($this->config['snmp_batch_size']) ? (int)$this->config['snmp_batch_size'] : 100;
            $responsiveSnmpIps = SnmpClient::discoverResponsiveHostsBatch(
                $liveIpList,
                $community,
                500,
                $snmpBatchSize
            );

            // 7. Alle gefundenen Geräte innerhalb einer schnellen Datenbank-Transaktion speichern
            $this->pdo->beginTransaction();
            try {
                foreach ($liveHosts as $ip => $hostMeta) {
                    $isProxyArp = !empty($hostMeta['is_proxy_arp']);
                    $mac = (!$isProxyArp && !empty($hostMeta['mac'])) ? OuiLookup::normalizeMac($hostMeta['mac']) : null;
                    $vendor = $isProxyArp
                        ? ('VPN-Fernzugang / Proxy-ARP (via ' . $gatewayIp . ')')
                        : ($mac ? OuiLookup::lookupVendor($mac) : null);
                    $ttl = isset($hostMeta['ttl']) ? $hostMeta['ttl'] : null;

                    $hostname = !empty($hostMeta['hostname'])
                        ? $hostMeta['hostname']
                        : (isset($batchHostnames[$ip]) ? $batchHostnames[$ip] : null);

                    // Falls kein DNS-Hostname vorhanden, nutze Windows NetBIOS-Namen als Fallback
                    $nbInfo = isset($batchNetbios[$ip]) ? $batchNetbios[$ip] : null;
                    if (empty($hostname) && !empty($nbInfo['name'])) {
                        $hostname = $nbInfo['name'];
                    }

                    $openPorts = isset($batchOpenPorts[$ip]) ? $batchOpenPorts[$ip] : array();
                    $bannerInfo = isset($batchBanners[$ip]) ? $batchBanners[$ip] : array();

                    $snmpEnabled = 0;
                    $snmpSysName = null;
                    $snmpSysDescr = null;
                    $snmpSysObjectId = null;
                    $snmpLocation = null;
                    $snmpPortsJson = null;
                    $hasBridgeTable = false;

                    // Detail-SNMP-Abfrage (Interfaces, Bridge-MIB MAC-Tabelle, LLDP) NUR für Geräte,
                    // die beim schnellen Multi-Host-UDP-Check geantwortet haben oder eine eigene Switch-SNMP-Community besitzen!
                    $customSnmpComm = (isset($prevDevicesByIp[$ip]) && !empty($prevDevicesByIp[$ip]['switch_snmp_community']))
                        ? trim($prevDevicesByIp[$ip]['switch_snmp_community'])
                        : '';
                    if (isset($responsiveSnmpIps[$ip]) || $customSnmpComm !== '') {
                        $devComm = ($customSnmpComm !== '') ? $customSnmpComm : $community;
                        $snmpClient = new SnmpClient($ip, $devComm, $this->config['snmp_timeout_ms']);
                        $snmpData = $snmpClient->probeDevice();
                        if ($snmpData !== null) {
                            $snmpEnabled = 1;
                            $snmpSysName = $snmpData['sysName'];
                            $snmpSysDescr = $snmpData['sysDescr'];
                            $snmpSysObjectId = $snmpData['sysObjectID'];
                            $snmpLocation = $snmpData['sysLocation'];
                            $snmpPortsJson = json_encode($snmpData);
                            if (!empty($snmpData['mac_table']) || !empty($snmpData['lldp_neighbors'])) {
                                $hasBridgeTable = true;
                            }
                            if (empty($hostname) && !empty($snmpSysName)) {
                                $hostname = $snmpSysName;
                            }
                        }
                    }

                    // Baue kompakten Service-/Fingerprint-String zusammen
                    $infoParts = array();
                    if ($ttl !== null) {
                        $infoParts[] = 'TTL: ' . (int)$ttl;
                    }
                    if (!empty($bannerInfo['http_title'])) {
                        $infoParts[] = 'Web: ' . $bannerInfo['http_title'];
                    }
                    if (!empty($bannerInfo['http_server'])) {
                        $infoParts[] = 'Server: ' . $bannerInfo['http_server'];
                    }
                    if (!empty($bannerInfo['ssh_banner'])) {
                        $infoParts[] = 'SSH: ' . $bannerInfo['ssh_banner'];
                    }
                    if (!empty($nbInfo['workgroup'])) {
                        $infoParts[] = 'Workgroup: ' . $nbInfo['workgroup'];
                    }
                    if ($isProxyArp) {
                        $infoParts[] = 'Proxy-ARP via ' . $gatewayIp;
                    }
                    $serviceInfo = !empty($infoParts) ? implode(' | ', $infoParts) : null;

                    $autoType = $isProxyArp
                        ? 'workstation'
                        : OuiLookup::classifyDevice($ip, $gatewayIp, $vendor, $hostname, $openPorts, $snmpSysDescr, $hasBridgeTable, $serviceInfo);
                    if ($autoType === 'switch') {
                        $switchesFound++;
                    }

                    $osGuess = $this->guessOs($openPorts, $vendor, $snmpSysDescr, $autoType, $ttl, $hostname, $serviceInfo);
                    if ($isProxyArp && ($osGuess === 'Embedded / Unbekannt' || strpos($osGuess, 'FRITZ!OS') !== false)) {
                        $osGuess = 'VPN-Client (WireGuard / IPSec Fernzugang)';
                    }
                    $portsFormatted = $this->formatOpenPorts($openPorts);

                    $this->upsertDevice(array(
                        'scope_id'        => $scope['id'],
                        'ip_address'      => $ip,
                        'mac_address'     => $mac,
                        'vendor'          => $vendor,
                        'hostname'        => $hostname,
                        'device_type'     => $autoType,
                        'os_guess'        => $osGuess,
                        'open_ports'      => $portsFormatted,
                        'service_info'    => $serviceInfo,
                        'snmp_enabled'    => $snmpEnabled,
                        'snmp_sysname'    => $snmpSysName,
                        'snmp_sysdescr'   => $snmpSysDescr,
                        'snmp_sysobjectid'=> $snmpSysObjectId,
                        'snmp_location'   => $snmpLocation,
                        'snmp_ports_json' => $snmpPortsJson,
                        'status'          => 'online',
                        'latency_ms'      => isset($hostMeta['latency_ms']) ? $hostMeta['latency_ms'] : 1.0,
                    ));
                }
                $this->pdo->commit();
            } catch (Exception $txEx) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $txEx;
            }

            // Topologie-Verbindungen (Layer 2 Switch-Ports + Layer 3 Gateway) neu berechnen
            $this->rebuildTopologyLinks($scope['id'], $gatewayIp);

            // 8. Geräte-Alarme (Online / Offline Statuswechsel) & Automatische Netzwerk-Anomalie-Wächter auswerten
            $alertsCount = $this->processDeviceAlerts($scope, $prevDevicesByIp);
            $anomalyCount = $this->processNetworkAnomalies($scope, $prevDevicesByIp, $knownMacsBeforeScan, $hadBaseline, $gatewayIp);
            $alertsCount += $anomalyCount;

            $finishedAt = date('Y-m-d H:i:s');
            $durationSec = round(microtime(true) - $scanStartMicro, 2);
            $alertSuffix = ($alertsCount > 0) ? sprintf(' [%d Alarm(e) ausgelöst!]', $alertsCount) : '';
            $summary = sprintf(
                'Scan abgeschlossen in %.2f s: %d aktive Geräte (%d Managed Switches) in %s gefunden.%s',
                $durationSec,
                $hostsUp,
                $switchesFound,
                $scope['cidr'],
                $alertSuffix
            );

            $this->pdo->prepare("
                UPDATE scopes
                SET last_scan_at = :fin, last_scan_status = 'completed', last_scan_hosts = :cnt
                WHERE id = :id
            ")->execute(array(
                ':fin' => $finishedAt,
                ':cnt' => $hostsUp,
                ':id'  => $scope['id'],
            ));

            $this->pdo->prepare("
                UPDATE scan_logs
                SET finished_at = :fin, status = 'completed', hosts_up = :cnt, switches_found = :sw, summary = :sum
                WHERE id = :lid
            ")->execute(array(
                ':fin' => $finishedAt,
                ':cnt' => $hostsUp,
                ':sw'  => $switchesFound,
                ':sum' => $summary,
                ':lid' => $logId,
            ));

            // 9. Automatische Datenbank-Bereinigung (Retention): Alte Scan-Protokolle, Alarme & verwaiste Offline-Geräte löschen
            $this->cleanupOldScans();

            return array(
                'scope_id'       => (int)$scope['id'],
                'scope_name'     => $scope['name'],
                'cidr'           => $scope['cidr'],
                'hosts_up'       => $hostsUp,
                'switches_found' => $switchesFound,
                'summary'        => $summary,
            );
        } catch (Exception $e) {
            $finishedAt = date('Y-m-d H:i:s');
            $this->pdo->prepare("UPDATE scopes SET last_scan_status = 'error' WHERE id = :id")
                      ->execute(array(':id' => $scope['id']));
            $this->pdo->prepare("UPDATE scan_logs SET finished_at = :fin, status = 'error', summary = :sum WHERE id = :lid")
                      ->execute(array(':fin' => $finishedAt, ':sum' => $e->getMessage(), ':lid' => $logId));
            throw $e;
        }
    }

    /**
     * Scannt alle aktiven Scopes nacheinander
     */
    public function scanAllActiveScopes($triggerType = 'manual')
    {
        $stmt = $this->pdo->query("SELECT id FROM scopes WHERE is_active = 1 ORDER BY id ASC");
        $results = array();
        while ($row = $stmt->fetch()) {
            $results[] = $this->scanScope($row['id'], $triggerType);
        }
        return $results;
    }

    /**
     * Bereinigt automatisch alte Scan-Protokolle (scan_logs), alte Alarme (device_alerts)
     * sowie optional langzeit-inaktive Offline-Geräte, damit die Datenbank bei kurzen Cron-Intervallen
     * (z.B. alle 15 Minuten) niemals vollläuft.
     */
    public function cleanupOldScans($retentionDays = null, $staleOfflineDays = null)
    {
        if ($retentionDays === null) {
            $retentionDays = isset($this->config['retention_days']) ? (int)$this->config['retention_days'] : 14;
        } else {
            $retentionDays = (int)$retentionDays;
        }

        if ($staleOfflineDays === null) {
            $staleOfflineDays = isset($this->config['stale_offline_days']) ? (int)$this->config['stale_offline_days'] : 30;
        } else {
            $staleOfflineDays = (int)$staleOfflineDays;
        }

        $deletedLogs = 0;
        $deletedAlerts = 0;
        $deletedDevices = 0;

        try {
            if ($retentionDays > 0) {
                $cutoffLogs = date('Y-m-d H:i:s', time() - ($retentionDays * 86400));
                $stmtLogs = $this->pdo->prepare("DELETE FROM scan_logs WHERE started_at < :cutoff");
                $stmtLogs->execute(array(':cutoff' => $cutoffLogs));
                $deletedLogs = (int)$stmtLogs->rowCount();

                $stmtAlerts = $this->pdo->prepare("DELETE FROM device_alerts WHERE created_at < :cutoff");
                $stmtAlerts->execute(array(':cutoff' => $cutoffLogs));
                $deletedAlerts = (int)$stmtAlerts->rowCount();
            }

            if ($staleOfflineDays > 0) {
                $cutoffDevs = date('Y-m-d H:i:s', time() - ($staleOfflineDays * 86400));
                // Lösche nur temporäre Offline-Clients ohne manuellen Namen, ohne Notizen, ohne Core-Schutz und ohne aktive Alarmüberwachung
                $stmtDevs = $this->pdo->prepare("
                    DELETE FROM devices
                    WHERE status = 'offline'
                      AND is_core = 0
                      AND device_type NOT IN ('router', 'switch')
                      AND (custom_name IS NULL OR custom_name = '')
                      AND (notes IS NULL OR notes = '')
                      AND (alert_mode IS NULL OR alert_mode = 'none')
                      AND last_seen IS NOT NULL
                      AND last_seen < :cutoff
                ");
                $stmtDevs->execute(array(':cutoff' => $cutoffDevs));
                $deletedDevices = (int)$stmtDevs->rowCount();

                if ($deletedDevices > 0) {
                    $this->pdo->exec("DELETE FROM links WHERE source_device_id NOT IN (SELECT id FROM devices) OR target_device_id NOT IN (SELECT id FROM devices)");
                }
            }

            // Bereinige auch sehr alte MAC-Port-Historien-Einträge (Standard: max(retention_days, stale_offline_days))
            $histRetDays = max($retentionDays, $staleOfflineDays);
            if ($histRetDays > 0) {
                $cutoffHist = date('Y-m-d H:i:s', time() - ($histRetDays * 86400));
                $stmtHist = $this->pdo->prepare("DELETE FROM mac_port_history WHERE last_seen < :cutoff");
                $stmtHist->execute(array(':cutoff' => $cutoffHist));
            }

            // Rotiere / kürze auch die lokale Cron-Logdatei (data/cron_scan.log), falls > 512 KB
            $cronLogFile = dirname(__DIR__) . '/data/cron_scan.log';
            if (is_file($cronLogFile) && @filesize($cronLogFile) > 524288) {
                $tail = @file_get_contents($cronLogFile, false, null, max(0, @filesize($cronLogFile) - 131072));
                if ($tail !== false) {
                    @file_put_contents($cronLogFile, $tail);
                }
            }
        } catch (Exception $e) {
            // Bereinigungsfehler sollen den Scan nicht abbrechen
        }

        return array(
            'retention_days'     => $retentionDays,
            'stale_offline_days' => $staleOfflineDays,
            'deleted_logs'       => $deletedLogs,
            'deleted_alerts'     => $deletedAlerts,
            'deleted_devices'    => $deletedDevices,
        );
    }

    /**
     * Prüft überwachte Geräte (alert_mode != 'none') auf Statuswechsel (online <-> offline),
     * legt Alarme in device_alerts an und versendet bei aktivem SMTP-Server eine E-Mail-Benachrichtigung.
     */
    private function processDeviceAlerts($scope, array $prevDevicesByIp)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM devices WHERE scope_id = :sid AND alert_mode != 'none'");
        $stmt->execute(array(':sid' => $scope['id']));
        $monitored = $stmt->fetchAll();
        if (empty($monitored)) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $newAlerts = array();
        $insAlert = $this->pdo->prepare("
            INSERT INTO device_alerts (device_id, scope_id, ip_address, device_name, alert_type, severity, message, email_sent, is_acknowledged, created_at)
            VALUES (:did, :sid, :ip, :dname, :atype, :sev, :msg, 0, 0, :now)
        ");
        $updState = $this->pdo->prepare("UPDATE devices SET last_alert_state = :st WHERE id = :id");

        foreach ($monitored as $dev) {
            $ip = $dev['ip_address'];
            $mode = $dev['alert_mode']; // 'offline', 'online', 'both'
            $curStatus = $dev['status']; // 'online' oder 'offline'
            $prevStatus = null;
            if (!empty($dev['last_alert_state'])) {
                $prevStatus = $dev['last_alert_state'];
            } elseif (isset($prevDevicesByIp[$ip])) {
                $prevStatus = $prevDevicesByIp[$ip]['status'];
            }

            $devLabel = !empty($dev['custom_name']) ? $dev['custom_name'] : (!empty($dev['hostname']) ? $dev['hostname'] : $ip);
            $alertType = null;
            $severity = 'critical';
            $message = '';

            if ($mode === 'offline' || $mode === 'both') {
                if ($curStatus === 'offline' && $prevStatus !== 'offline') {
                    $alertType = 'offline_alarm';
                    $severity = 'critical';
                    $message = sprintf(
                        'AUSFALL-ALARM: Gerät "%s" (%s) im Scope "%s" ist nicht mehr erreichbar (OFFLINE)!',
                        $devLabel,
                        $ip,
                        $scope['name']
                    );
                } elseif ($curStatus === 'online' && $prevStatus === 'offline') {
                    $alertType = 'online_recovery';
                    $severity = 'recovery';
                    $message = sprintf(
                        'ENTWARNUNG (ONLINE): Gerät "%s" (%s) im Scope "%s" antwortet wieder (Latenz: %s ms).',
                        $devLabel,
                        $ip,
                        $scope['name'],
                        isset($dev['latency_ms']) ? $dev['latency_ms'] : '1.0'
                    );
                }
            }

            if ($alertType === null && ($mode === 'online' || $mode === 'both')) {
                if ($curStatus === 'online' && $prevStatus !== 'online') {
                    $alertType = 'online_alarm';
                    $severity = 'warning';
                    $message = sprintf(
                        'ONLINE-ALARM: Überwachtes Gerät "%s" (%s) ist im Scope "%s" aktiv geworden (ONLINE)!',
                        $devLabel,
                        $ip,
                        $scope['name']
                    );
                }
            }

            // Aktualisiere den gemerkten Status für künftige Vergleiche
            if ($prevStatus !== $curStatus) {
                $updState->execute(array(':st' => $curStatus, ':id' => $dev['id']));
            }

            if ($alertType !== null) {
                $insAlert->execute(array(
                    ':did'   => $dev['id'],
                    ':sid'   => $scope['id'],
                    ':ip'    => $ip,
                    ':dname' => $devLabel,
                    ':atype' => $alertType,
                    ':sev'   => $severity,
                    ':msg'   => $message,
                    ':now'   => $now,
                ));
                $alertId = (int)$this->pdo->lastInsertId();
                $newAlerts[] = array(
                    'id'          => $alertId,
                    'ip_address'  => $ip,
                    'device_name' => $devLabel,
                    'severity'    => $severity,
                    'message'     => $message,
                    'created_at'  => $now,
                );
            }
        }

        if (!empty($newAlerts)) {
            $this->sendAlertEmailsIfConfigured($scope, $newAlerts);
        }

        return count($newAlerts);
    }

    /**
     * Automatischer Anomalie- & Sicherheits-Wächter (Layer 2 / Layer 3 Watchdogs):
     * 1. ARP-Spoofing / IP-Adresskonflikt (arp_conflict): Bekannte IP ändert plötzlich ihre MAC-Adresse
     * 2. Neues unbekanntes Gerät im Netz (new_mac): Noch nie gesehene MAC-Adresse taucht im Scope / am Switch-Port auf
     * 3. Mehrfach-MAC-Warnung pro Port / Rogue-Switch-Erkennung (rogue_switch): > X MACs an einem normalen Access-Port
     * 4. Port-Fehler- & Loop/MAC-Flapping-Alarm (port_errors & mac_flapping): Hohe Paketfehler oder häufiger Port-Wechsel
     */
    private function processNetworkAnomalies($scope, array $prevDevicesByIp, array $knownMacsBeforeScan, $hadBaseline, $gatewayIp = null)
    {
        $enableArpSpoof = !isset($this->config['anomaly_arp_spoofing']) || (int)$this->config['anomaly_arp_spoofing'] === 1;
        $enableNewMac = (!isset($this->config['anomaly_new_mac']) || (int)$this->config['anomaly_new_mac'] === 1) && $hadBaseline;
        $maxMacsPerPort = isset($this->config['anomaly_rogue_switch_max_macs']) ? (int)$this->config['anomaly_rogue_switch_max_macs'] : 3;
        $portErrThresh = isset($this->config['anomaly_port_error_threshold']) ? (int)$this->config['anomaly_port_error_threshold'] : 50;
        $macFlapThresh = isset($this->config['anomaly_mac_flapping_threshold']) ? (int)$this->config['anomaly_mac_flapping_threshold'] : 3;

        $stmt = $this->pdo->prepare("SELECT * FROM devices WHERE scope_id = :sid");
        $stmt->execute(array(':sid' => (int)$scope['id']));
        $currentDevices = $stmt->fetchAll();
        if (empty($currentDevices)) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $newAlerts = array();
        $devByMac = array();
        $infraMacs = array();

        foreach ($currentDevices as $cd) {
            if (!empty($cd['mac_address'])) {
                $mKey = strtoupper(trim($cd['mac_address']));
                $devByMac[$mKey] = $cd;
                if (in_array($cd['device_type'], array('router', 'switch', 'ap'), true) || ($gatewayIp && $cd['ip_address'] === $gatewayIp)) {
                    $infraMacs[$mKey] = true;
                }
            }
        }

        // Aktuellen Switch-Port pro MAC aus mac_port_history nachschlagen (für präzise Ortsangabe in Alarmen)
        $latestPortByMac = array();
        try {
            $histStmt = $this->pdo->query("SELECT mac_address, switch_name, switch_ip, port_name, vlan_id FROM mac_port_history ORDER BY id ASC");
            while ($hr = $histStmt->fetch()) {
                $latestPortByMac[strtoupper(trim($hr['mac_address']))] = $hr;
            }
        } catch (Exception $e) {}

        foreach ($currentDevices as $dev) {
            if ($dev['status'] !== 'online') {
                continue;
            }
            $ip = $dev['ip_address'];
            $devLabel = !empty($dev['custom_name']) ? $dev['custom_name'] : (!empty($dev['hostname']) ? $dev['hostname'] : $ip);
            $curMac = !empty($dev['mac_address']) ? strtoupper(trim($dev['mac_address'])) : '';

            // 1. ⚡ ARP-Spoofing / IP-Adresskonflikt-Erkennung
            if ($enableArpSpoof && $curMac !== '' && isset($prevDevicesByIp[$ip])) {
                $prevMac = !empty($prevDevicesByIp[$ip]['mac_address']) ? strtoupper(trim($prevDevicesByIp[$ip]['mac_address'])) : '';
                if ($prevMac !== '' && $prevMac !== $curMac) {
                    $prevVendor = !empty($prevDevicesByIp[$ip]['vendor']) ? $prevDevicesByIp[$ip]['vendor'] : 'Unbekannt';
                    $curVendor = !empty($dev['vendor']) ? $dev['vendor'] : 'Unbekannt';
                    $portHint = '';
                    if (isset($latestPortByMac[$curMac])) {
                        $lp = $latestPortByMac[$curMac];
                        $portHint = sprintf(' [Aktuell an Switch "%s" Port %s]', $lp['switch_name'], $lp['port_name']);
                    }
                    $msg = sprintf(
                        '⚡ ARP-SPOOFING / IP-KONFLIKT: Die IP-Adresse %s ("%s") hat ihre MAC-Adresse geändert von %s (%s) auf %s (%s)!%s Verdacht auf doppelt vergebene IP oder Man-in-the-Middle-Angriff.',
                        $ip,
                        $devLabel,
                        $prevMac,
                        $prevVendor,
                        $curMac,
                        $curVendor,
                        $portHint
                    );
                    $this->insertAnomalyAlertIfNew($newAlerts, (int)$dev['id'], (int)$scope['id'], $ip, $devLabel, 'arp_conflict', 'critical', $msg, $prevMac . '->' . $curMac, $now);
                }
            }

            // 2. 🚨 „Neues unbekanntes Gerät im Netz“-Alarm
            if ($enableNewMac && $curMac !== '' && !isset($knownMacsBeforeScan[$curMac])) {
                $curVendor = !empty($dev['vendor']) ? $dev['vendor'] : 'Unbekannter Hersteller';
                $portHint = '';
                if (isset($latestPortByMac[$curMac])) {
                    $lp = $latestPortByMac[$curMac];
                    $portHint = sprintf(' [Gesteckt an Switch "%s" (%s) Port %s, VLAN %d]', $lp['switch_name'], $lp['switch_ip'], $lp['port_name'], (int)$lp['vlan_id']);
                }
                $msg = sprintf(
                    '🚨 NEUES GERÄT IM NETZ: Noch nie gesehene MAC-Adresse %s (%s) mit IP %s ("%s", Typ: %s) wurde erstmals im Scope "%s" entdeckt!%s',
                    $curMac,
                    $curVendor,
                    $ip,
                    $devLabel,
                    strtoupper($dev['device_type']),
                    $scope['name'],
                    $portHint
                );
                $this->insertAnomalyAlertIfNew($newAlerts, (int)$dev['id'], (int)$scope['id'], $ip, $devLabel, 'new_mac', 'warning', $msg, $curMac, $now);
                $knownMacsBeforeScan[$curMac] = true;
            }
        }

        // 3. 🔌 Rogue-Switch / Mehrfach-MAC pro Port & 4a. 📉 Port-Fehler-Alarm auf Managed Switches
        foreach ($currentDevices as $sw) {
            if ($sw['device_type'] !== 'switch' && (int)$sw['snmp_enabled'] !== 1) {
                continue;
            }
            if (empty($sw['snmp_ports_json'])) {
                continue;
            }
            $snmp = @json_decode($sw['snmp_ports_json'], true);
            if (!is_array($snmp)) {
                continue;
            }
            $swLabel = !empty($sw['custom_name']) ? $sw['custom_name'] : (!empty($sw['snmp_sysname']) ? $sw['snmp_sysname'] : (!empty($sw['hostname']) ? $sw['hostname'] : $sw['ip_address']));
            $swIp = $sw['ip_address'];

            // Ermittle Uplink-/Trunk-/Inter-Switch-Ports dieses Switches, damit Trunks nicht als Rogue-Switch gemeldet werden
            $uplinkPortNames = array();
            if (!empty($snmp['lldp_neighbors']) && is_array($snmp['lldp_neighbors'])) {
                foreach ($snmp['lldp_neighbors'] as $nbr) {
                    if (!empty($nbr['local_port'])) {
                        $uplinkPortNames[strtolower(trim($nbr['local_port']))] = true;
                    }
                }
            }
            if (!empty($snmp['interfaces']) && is_array($snmp['interfaces'])) {
                foreach ($snmp['interfaces'] as $ifRow) {
                    if (!empty($ifRow['name']) && isset($ifRow['port_mode']) && $ifRow['port_mode'] === 'trunk') {
                        $uplinkPortNames[strtolower(trim($ifRow['name']))] = true;
                    }
                }
            }
            if (!empty($snmp['mac_table']) && is_array($snmp['mac_table'])) {
                foreach ($snmp['mac_table'] as $mAddr => $pInfo) {
                    $mUpper = strtoupper(trim($mAddr));
                    if (isset($infraMacs[$mUpper]) && !empty($pInfo['port_name'])) {
                        $uplinkPortNames[strtolower(trim($pInfo['port_name']))] = true;
                    }
                }
            }

            // 3. Prüfe Access-Ports auf Mehrfach-MACs (Rogue-Switch / unautorisierter Hub / Mini-AP)
            if ($maxMacsPerPort > 0 && !empty($snmp['mac_table']) && is_array($snmp['mac_table'])) {
                $macsByPort = array();
                foreach ($snmp['mac_table'] as $mAddr => $pInfo) {
                    $mUpper = strtoupper(trim($mAddr));
                    if (!empty($sw['mac_address']) && $mUpper === strtoupper(trim($sw['mac_address']))) {
                        continue;
                    }
                    $pName = !empty($pInfo['port_name']) ? trim($pInfo['port_name']) : ('Port ' . (isset($pInfo['bridge_port']) ? $pInfo['bridge_port'] : '?'));
                    $pKeyLow = strtolower($pName);
                    if (isset($uplinkPortNames[$pKeyLow])) {
                        continue; // Uplink-/Trunk-Ports überspringen
                    }
                    if (!isset($macsByPort[$pName])) {
                        $macsByPort[$pName] = array();
                    }
                    $macsByPort[$pName][] = $mUpper;

                    // Prüfe auch reine Layer-2-MACs am Switch-Port auf unbekannte neue MAC
                    if ($enableNewMac && !isset($knownMacsBeforeScan[$mUpper])) {
                        $l2Vend = OuiLookup::lookupVendor($mUpper);
                        $msgNewL2 = sprintf(
                            '🚨 NEUES GERÄT AM SWITCH-PORT: Unbekannte MAC-Adresse %s (%s) wurde erstmals direkt an Switch "%s" (%s) auf Port %s erkannt!',
                            $mUpper,
                            $l2Vend ? $l2Vend : 'Unbekannter Hersteller',
                            $swLabel,
                            $swIp,
                            $pName
                        );
                        $this->insertAnomalyAlertIfNew($newAlerts, (int)$sw['id'], (int)$scope['id'], $swIp, $swLabel, 'new_mac', 'warning', $msgNewL2, $mUpper, $now);
                        $knownMacsBeforeScan[$mUpper] = true;
                    }
                }

                foreach ($macsByPort as $pName => $macList) {
                    $macCount = count($macList);
                    if ($macCount > $maxMacsPerPort) {
                        $sampleLabels = array();
                        foreach (array_slice($macList, 0, 5) as $sm) {
                            if (isset($devByMac[$sm])) {
                                $dRow = $devByMac[$sm];
                                $dTitle = !empty($dRow['custom_name']) ? $dRow['custom_name'] : (!empty($dRow['hostname']) ? $dRow['hostname'] : $dRow['ip_address']);
                                $sampleLabels[] = $sm . ' (' . $dTitle . ')';
                            } else {
                                $sampleLabels[] = $sm;
                            }
                        }
                        if ($macCount > 5) {
                            $sampleLabels[] = '+' . ($macCount - 5) . ' weitere';
                        }
                        $msg = sprintf(
                            '🔌 ROGUE-SWITCH / MEHRFACH-MAC WARNUNG: An Access-Port %s von Switch "%s" (%s) wurden %d verschiedene MAC-Adressen gleichzeitig erkannt (Schwellenwert: max. %d): %s. Verdacht auf unautorisierten Desktop-Switch, Hub oder privaten WLAN-AP!',
                            $pName,
                            $swLabel,
                            $swIp,
                            $macCount,
                            $maxMacsPerPort,
                            implode(', ', $sampleLabels)
                        );
                        $this->insertAnomalyAlertIfNew($newAlerts, (int)$sw['id'], (int)$scope['id'], $swIp, $swLabel, 'rogue_switch', 'warning', $msg, 'port:' . $pName, $now);
                    }
                }
            }

            // 4a. 📉 Switch-Port Paketfehler-Überwachung (ifInErrors)
            if ($portErrThresh > 0 && !empty($snmp['interfaces']) && is_array($snmp['interfaces'])) {
                $prevIfErrors = array();
                if (isset($prevDevicesByIp[$swIp]) && !empty($prevDevicesByIp[$swIp]['snmp_ports_json'])) {
                    $prevSnmp = @json_decode($prevDevicesByIp[$swIp]['snmp_ports_json'], true);
                    if (!empty($prevSnmp['interfaces']) && is_array($prevSnmp['interfaces'])) {
                        foreach ($prevSnmp['interfaces'] as $pIf) {
                            if (!empty($pIf['name'])) {
                                $prevIfErrors[$pIf['name']] = isset($pIf['in_errors']) ? (int)$pIf['in_errors'] : 0;
                            }
                        }
                    }
                }

                foreach ($snmp['interfaces'] as $ifRow) {
                    $ifName = isset($ifRow['name']) ? $ifRow['name'] : '';
                    $curErr = isset($ifRow['in_errors']) ? (int)$ifRow['in_errors'] : 0;
                    if ($ifName === '' || $curErr < $portErrThresh) {
                        continue;
                    }
                    $prevErr = isset($prevIfErrors[$ifName]) ? $prevIfErrors[$ifName] : 0;
                    $deltaErr = max(0, $curErr - $prevErr);
                    if ($prevErr < $portErrThresh || $deltaErr >= $portErrThresh) {
                        $msg = sprintf(
                            '📉 PORT-FEHLER ALARM: Switch "%s" (%s) meldet auf Port %s erhöhte Paketfehler (%d Fehler gesamt%s). Verdacht auf defektes Patchkabel, Duplex-Mismatch oder EMV-Störung!',
                            $swLabel,
                            $swIp,
                            $ifName,
                            $curErr,
                            $deltaErr > 0 ? (', +' . $deltaErr . ' seit letztem Scan') : ''
                        );
                        $this->insertAnomalyAlertIfNew($newAlerts, (int)$sw['id'], (int)$scope['id'], $swIp, $swLabel, 'port_errors', 'warning', $msg, 'errport:' . $ifName, $now);
                    }
                }
            }
        }

        // 4b. 🌪️ MAC-Flapping / Loop-Erkennung (Häufiger Switch-Port-Wechsel derselben MAC innerhalb der letzten 60 Minuten)
        if ($macFlapThresh >= 2) {
            try {
                $since60m = date('Y-m-d H:i:s', time() - 3600);
                $flapStmt = $this->pdo->prepare("
                    SELECT mac_address, COUNT(*) AS station_cnt
                    FROM mac_port_history
                    WHERE first_seen >= :since
                    GROUP BY mac_address
                    HAVING COUNT(*) >= :thresh
                ");
                $flapStmt->execute(array(
                    ':since'  => $since60m,
                    ':thresh' => $macFlapThresh,
                ));
                while ($fRow = $flapStmt->fetch()) {
                    $fMac = strtoupper(trim($fRow['mac_address']));
                    $stCount = (int)$fRow['station_cnt'];
                    $detStmt = $this->pdo->prepare("
                        SELECT switch_id, switch_name, switch_ip, port_name
                        FROM mac_port_history
                        WHERE mac_address = :mac AND first_seen >= :since
                        ORDER BY id DESC LIMIT 5
                    ");
                    $detStmt->execute(array(':mac' => $fMac, ':since' => $since60m));
                    $stations = $detStmt->fetchAll();
                    $distinctPorts = array();
                    $pathParts = array();
                    $lastSwId = 0;
                    $lastSwIp = '';
                    $lastSwName = 'Switch';
                    foreach (array_reverse($stations) as $st) {
                        $pKey = $st['switch_id'] . ':' . $st['port_name'];
                        $distinctPorts[$pKey] = true;
                        $pathParts[] = ($st['switch_name'] ? $st['switch_name'] : 'Switch') . ' [' . $st['port_name'] . ']';
                        $lastSwId = (int)$st['switch_id'];
                        $lastSwIp = $st['switch_ip'] ? $st['switch_ip'] : '';
                        $lastSwName = $st['switch_name'] ? $st['switch_name'] : 'Switch';
                    }
                    if (count($distinctPorts) >= 2) {
                        $knDev = isset($devByMac[$fMac]) ? $devByMac[$fMac] : null;
                        $alertDevId = $knDev ? (int)$knDev['id'] : $lastSwId;
                        $alertIp = $knDev ? $knDev['ip_address'] : ($lastSwIp !== '' ? $lastSwIp : $scope['cidr']);
                        $alertName = $knDev ? (!empty($knDev['custom_name']) ? $knDev['custom_name'] : (!empty($knDev['hostname']) ? $knDev['hostname'] : $knDev['ip_address'])) : $lastSwName;
                        $msg = sprintf(
                            '🌪️ MAC-FLAPPING / LOOP-ALARM: Die MAC-Adresse %s (%s) hat innerhalb der letzten 60 Minuten %d× den Switch-Port gewechselt (%s)! Verdacht auf Netzwerkschleife (Layer-2 Loop), falsch konfigurierten Repeater oder MAC-Spoofing.',
                            $fMac,
                            $alertName,
                            $stCount,
                            implode(' → ', $pathParts)
                        );
                        $this->insertAnomalyAlertIfNew($newAlerts, $alertDevId, (int)$scope['id'], $alertIp, $alertName, 'mac_flapping', 'critical', $msg, 'flap:' . $fMac, $now);
                    }
                }
            } catch (Exception $e) {}
        }

        if (!empty($newAlerts)) {
            $this->sendAlertEmailsIfConfigured($scope, $newAlerts);
        }

        return count($newAlerts);
    }

    /**
     * Legt einen Anomalie-Alarm dedupliziert in device_alerts an (verhindert identische Mehrfach-Alarme bei jedem 15-Min-Cron-Lauf,
     * solange der bestehende Alarm für denselben Vorfall noch unbestätigt ist).
     */
    private function insertAnomalyAlertIfNew(array &$newAlerts, $deviceId, $scopeId, $ip, $deviceName, $alertType, $severity, $message, $dedupToken, $now)
    {
        try {
            $cutoff = date('Y-m-d H:i:s', time() - 43200); // 12h Deduplizierungsfenster für unbestätigte identische Anomalien
            $chk = $this->pdo->prepare("
                SELECT id, message FROM device_alerts
                WHERE alert_type = :atype
                  AND device_id = :did
                  AND is_acknowledged = 0
                  AND created_at >= :cutoff
                ORDER BY id DESC LIMIT 10
            ");
            $chk->execute(array(
                ':atype'  => $alertType,
                ':did'    => (int)$deviceId,
                ':cutoff' => $cutoff,
            ));
            while ($existing = $chk->fetch()) {
                if ($dedupToken === '' || strpos((string)$existing['message'], $dedupToken) !== false) {
                    // Aktualisiere Zeitstempel und Nachrichtentext des offenen Alarms ohne Spam-Duplikat
                    $upd = $this->pdo->prepare("UPDATE device_alerts SET message = :msg, created_at = :now WHERE id = :id");
                    $upd->execute(array(':msg' => $message, ':now' => $now, ':id' => (int)$existing['id']));
                    return;
                }
            }

            $ins = $this->pdo->prepare("
                INSERT INTO device_alerts (device_id, scope_id, ip_address, device_name, alert_type, severity, message, email_sent, is_acknowledged, created_at)
                VALUES (:did, :sid, :ip, :dname, :atype, :sev, :msg, 0, 0, :now)
            ");
            $ins->execute(array(
                ':did'   => (int)$deviceId,
                ':sid'   => (int)$scopeId,
                ':ip'    => (string)$ip,
                ':dname' => (string)$deviceName,
                ':atype' => (string)$alertType,
                ':sev'   => (string)$severity,
                ':msg'   => (string)$message,
                ':now'   => $now,
            ));
            $alertId = (int)$this->pdo->lastInsertId();
            $newAlerts[] = array(
                'id'          => $alertId,
                'ip_address'  => (string)$ip,
                'device_name' => (string)$deviceName,
                'severity'    => (string)$severity,
                'message'     => (string)$message,
                'created_at'  => $now,
            );
        } catch (Exception $e) {}
    }

    private function sendAlertEmailsIfConfigured($scope, array $alerts)
    {
        $fullConf = $this->db->getConfig();
        $smtpConf = isset($fullConf['smtp']) ? $fullConf['smtp'] : array();
        if (empty($smtpConf['enabled']) || empty($smtpConf['host']) || empty($smtpConf['to_email'])) {
            return;
        }

        try {
            $subject = sprintf('[NetworkMonitor] %d Geräte-Alarm(e) in %s (%s)', count($alerts), $scope['name'], $scope['cidr']);
            $rowsHtml = '';
            foreach ($alerts as $a) {
                $badgeCol = ($a['severity'] === 'critical') ? '#ef4444' : (($a['severity'] === 'recovery') ? '#10b981' : '#f59e0b');
                $rowsHtml .= '<tr>'
                    . '<td style="padding:8px 12px;border-bottom:1px solid #334155;font-family:monospace;">' . htmlspecialchars($a['created_at'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:8px 12px;border-bottom:1px solid #334155;font-weight:bold;color:' . $badgeCol . ';">' . htmlspecialchars(strtoupper($a['severity']), ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:8px 12px;border-bottom:1px solid #334155;font-weight:bold;">' . htmlspecialchars($a['device_name'] . ' (' . $a['ip_address'] . ')', ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:8px 12px;border-bottom:1px solid #334155;">' . htmlspecialchars($a['message'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '</tr>';
            }

            $htmlBody = '<div style="font-family:Segoe UI,Arial,sans-serif;background:#0f172a;color:#f8fafc;padding:20px;border-radius:8px;">'
                . '<h2 style="color:#60a5fa;margin-top:0;">NetworkMonitor – Automatische Alarm-Benachrichtigung</h2>'
                . '<p style="color:#cbd5e1;">Beim Netzwerk-Scan des Scopes <b>' . htmlspecialchars($scope['name'] . ' (' . $scope['cidr'] . ')', ENT_QUOTES, 'UTF-8') . '</b> wurden folgende Statuswechsel erkannt:</p>'
                . '<table style="width:100%;border-collapse:collapse;background:#1e293b;color:#f8fafc;font-size:13px;">'
                . '<thead><tr style="background:#334155;text-align:left;">'
                . '<th style="padding:8px 12px;">Zeitpunkt</th><th style="padding:8px 12px;">Typ</th><th style="padding:8px 12px;">Gerät</th><th style="padding:8px 12px;">Meldung</th>'
                . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
                . '</div>';

            $mailer = new SmtpMailer($smtpConf);
            if ($mailer->sendMail($subject, $htmlBody)) {
                $upd = $this->pdo->prepare("UPDATE device_alerts SET email_sent = 1 WHERE id = :id");
                foreach ($alerts as $a) {
                    $upd->execute(array(':id' => $a['id']));
                }
            }
        } catch (Exception $e) {
            // Fehler beim E-Mail-Versand soll den Scan selbst nicht abbrechen
        }
    }

    /**
     * Berechnet die Topologie-Links (Layer 2 SNMP MAC-Table / LLDP & Layer 3 Subnet Gateway)
     */
    public function rebuildTopologyLinks($scopeId, $gatewayIp = null)
    {
        // Lösche nur automatisch generierte Links dieses Scopes (manuelle bleiben erhalten)
        $this->pdo->prepare("DELETE FROM links WHERE scope_id = :sid AND is_manual = 0")
                  ->execute(array(':sid' => (int)$scopeId));

        $stmt = $this->pdo->prepare("SELECT * FROM devices WHERE scope_id = :sid ORDER BY id ASC");
        $stmt->execute(array(':sid' => (int)$scopeId));
        $devices = $stmt->fetchAll();
        if (empty($devices)) {
            return;
        }

        $byId = array();
        $byIp = array();
        $byMac = array();
        $byName = array();
        $switches = array();
        $routerDevice = null;

        foreach ($devices as $dev) {
            $byId[$dev['id']] = $dev;
            $byIp[$dev['ip_address']] = $dev;
            if (!empty($dev['mac_address'])) {
                $byMac[strtoupper($dev['mac_address'])] = $dev;
            }
            if (!empty($dev['hostname'])) {
                $byName[strtolower($dev['hostname'])] = $dev;
            }
            if (!empty($dev['snmp_sysname'])) {
                $byName[strtolower($dev['snmp_sysname'])] = $dev;
            }
            if ($dev['device_type'] === 'switch') {
                $switches[] = $dev;
            }
            if ($dev['device_type'] === 'router' || ($gatewayIp && $dev['ip_address'] === $gatewayIp)) {
                if ($routerDevice === null || $dev['ip_address'] === $gatewayIp) {
                    $routerDevice = $dev;
                }
            }
        }

        // Falls kein expliziter Router erkannt wurde, nutze ersten Switch oder erstes Gerät als Wurzelknoten
        if ($routerDevice === null) {
            $routerDevice = !empty($switches) ? $switches[0] : $devices[0];
        }

        $linkedTargets = array();
        $scanStartMicro = microtime(true);
        $now = date('Y-m-d H:i:s');
        $insertLink = $this->pdo->prepare("
            INSERT INTO links (scope_id, source_device_id, target_device_id, source_port, target_port, link_type, is_manual, updated_at)
            VALUES (:sid, :src, :dst, :sport, :dport, :ltype, 0, :now)
        ");

        // 1. LLDP Switch-zu-Switch / Router Nachbarschaften auswerten
        foreach ($switches as $sw) {
            if (empty($sw['snmp_ports_json'])) {
                continue;
            }
            $snmp = @json_decode($sw['snmp_ports_json'], true);
            if (!empty($snmp['lldp_neighbors'])) {
                foreach ($snmp['lldp_neighbors'] as $nbr) {
                    $remKey = strtolower(trim($nbr['remote_name']));
                    if (isset($byName[$remKey]) && (int)$byName[$remKey]['id'] !== (int)$sw['id']) {
                        $targetDev = $byName[$remKey];
                        $insertLink->execute(array(
                            ':sid'   => $scopeId,
                            ':src'   => $sw['id'],
                            ':dst'   => $targetDev['id'],
                            ':sport' => $nbr['local_port'],
                            ':dport' => isset($nbr['remote_port']) ? $nbr['remote_port'] : 'Uplink',
                            ':ltype' => 'lldp',
                            ':now'   => $now,
                        ));
                        $linkedTargets[$targetDev['id']] = true;
                    }
                }
            }
        }

        // 2. Verbinde Managed Switches untereinander bzw. mit dem Gateway-Router (inkl. Port-Erkennung aus MAC-Tabelle)
        $primarySwitch = !empty($switches) ? $switches[0] : null;
        foreach ($switches as $sw) {
            if ((int)$sw['id'] === (int)$routerDevice['id']) {
                continue;
            }
            if (!isset($linkedTargets[$sw['id']])) {
                $parent = ($primarySwitch && (int)$primarySwitch['id'] !== (int)$sw['id']) ? $primarySwitch : $routerDevice;
                $srcPortName = 'Trunk';
                $dstPortName = 'Uplink';

                // Prüfe ob Parent-Switch die MAC des Ziel-Switches auf einem Port sieht
                if (!empty($parent['snmp_ports_json']) && !empty($sw['mac_address'])) {
                    $pSnmp = @json_decode($parent['snmp_ports_json'], true);
                    $swMacUpper = strtoupper($sw['mac_address']);
                    if (!empty($pSnmp['mac_table'][$swMacUpper]['port_name'])) {
                        $srcPortName = $pSnmp['mac_table'][$swMacUpper]['port_name'];
                    }
                }
                // Prüfe auf welchem Port der Ziel-Switch die MAC des Parent/Gateway sieht (= Uplink-Port)
                if (!empty($sw['snmp_ports_json'])) {
                    $sSnmp = @json_decode($sw['snmp_ports_json'], true);
                    $parentMacUpper = !empty($parent['mac_address']) ? strtoupper($parent['mac_address']) : '';
                    $routerMacUpper = !empty($routerDevice['mac_address']) ? strtoupper($routerDevice['mac_address']) : '';
                    if ($parentMacUpper && !empty($sSnmp['mac_table'][$parentMacUpper]['port_name'])) {
                        $dstPortName = $sSnmp['mac_table'][$parentMacUpper]['port_name'];
                    } elseif ($routerMacUpper && !empty($sSnmp['mac_table'][$routerMacUpper]['port_name'])) {
                        $dstPortName = $sSnmp['mac_table'][$routerMacUpper]['port_name'];
                    }
                }

                $insertLink->execute(array(
                    ':sid'   => $scopeId,
                    ':src'   => $parent['id'],
                    ':dst'   => $sw['id'],
                    ':sport' => $srcPortName,
                    ':dport' => $dstPortName,
                    ':ltype' => 'trunk',
                    ':now'   => $now,
                ));
                $linkedTargets[$sw['id']] = true;
            }
        }

        // 3. SNMP Bridge-MIB MAC-Adresstabelle der Switches auswerten (Endgeräte -> Switch-Port)
        foreach ($switches as $sw) {
            if (empty($sw['snmp_ports_json'])) {
                continue;
            }
            $snmp = @json_decode($sw['snmp_ports_json'], true);
            if (empty($snmp['mac_table']) || !is_array($snmp['mac_table'])) {
                continue;
            }

            // Ermittle den Uplink-Port dieses Switches (Port, auf dem die MAC des Gateway-Routers gesehen wird).
            // Über diesen Uplink-Port sieht der Switch auch alle WLAN-Geräte und anderen FRITZ!Boxen –
            // diese dürfen NICHT als am Switch angeschlossene Endgeräte gewertet werden!
            $uplinkBridgePort = null;
            $uplinkPortName = null;
            if (!empty($routerDevice['mac_address'])) {
                $gwMac = strtoupper($routerDevice['mac_address']);
                if (isset($snmp['mac_table'][$gwMac])) {
                    $uplinkBridgePort = isset($snmp['mac_table'][$gwMac]['bridge_port']) ? (int)$snmp['mac_table'][$gwMac]['bridge_port'] : null;
                    $uplinkPortName = isset($snmp['mac_table'][$gwMac]['port_name']) ? $snmp['mac_table'][$gwMac]['port_name'] : null;
                }
            }

            $swDisplayName = !empty($sw['custom_name']) ? $sw['custom_name'] : (!empty($sw['snmp_sysname']) ? $sw['snmp_sysname'] : (!empty($sw['hostname']) ? $sw['hostname'] : $sw['ip_address']));
            $portVlanByName = array();
            if (!empty($snmp['interfaces']) && is_array($snmp['interfaces'])) {
                foreach ($snmp['interfaces'] as $ifRow) {
                    if (!empty($ifRow['name']) && !empty($ifRow['vlan'])) {
                        $portVlanByName[$ifRow['name']] = (int)$ifRow['vlan'];
                    }
                }
            }

            foreach ($snmp['mac_table'] as $macAddr => $portInfo) {
                $macKey = strtoupper($macAddr);
                // Überspringe MAC-Einträge, die auf dem Uplink-Port Richtung FRITZ!Box / Gateway liegen
                if ($uplinkBridgePort !== null && isset($portInfo['bridge_port']) && (int)$portInfo['bridge_port'] === $uplinkBridgePort) {
                    continue;
                }
                if ($uplinkPortName !== null && isset($portInfo['port_name']) && $portInfo['port_name'] === $uplinkPortName) {
                    continue;
                }

                $portLabel = isset($portInfo['port_name']) ? $portInfo['port_name'] : ('Port ' . $portInfo['bridge_port']);
                $pVlanId = isset($portVlanByName[$portLabel]) ? $portVlanByName[$portLabel] : 1;

                // Protokolliere gelernte MAC-Port-Zuordnung in der historischen Tabelle (mac_port_history)
                $knDev = isset($byMac[$macKey]) ? $byMac[$macKey] : null;
                if (!$knDev || ((int)$knDev['id'] !== (int)$sw['id'] && (int)$knDev['id'] !== (int)$routerDevice['id'] && $knDev['device_type'] !== 'switch')) {
                    $this->recordMacPortSighting(array(
                        'mac_address' => $macKey,
                        'ip_address'  => $knDev ? $knDev['ip_address'] : null,
                        'device_name' => $knDev ? (!empty($knDev['custom_name']) ? $knDev['custom_name'] : (!empty($knDev['hostname']) ? $knDev['hostname'] : $knDev['ip_address'])) : null,
                        'vendor'      => $knDev && !empty($knDev['vendor']) ? $knDev['vendor'] : OuiLookup::lookupVendor($macKey),
                        'switch_id'   => (int)$sw['id'],
                        'switch_name' => $swDisplayName,
                        'switch_ip'   => $sw['ip_address'],
                        'port_name'   => $portLabel,
                        'vlan_id'     => $pVlanId,
                        'seen_at'     => $now,
                    ));
                }

                if (!isset($byMac[$macKey])) {
                    continue;
                }
                $targetDev = $byMac[$macKey];
                if ((int)$targetDev['id'] === (int)$sw['id'] || (int)$targetDev['id'] === (int)$routerDevice['id']) {
                    continue;
                }
                if ($targetDev['device_type'] === 'switch') {
                    continue; // Switch-Links wurden bereits in Schritt 1 & 2 angelegt
                }
                if (isset($linkedTargets[$targetDev['id']])) {
                    continue;
                }
                $insertLink->execute(array(
                    ':sid'   => $scopeId,
                    ':src'   => $sw['id'],
                    ':dst'   => $targetDev['id'],
                    ':sport' => $portLabel,
                    ':dport' => 'eth0',
                    ':ltype' => 'snmp_l2',
                    ':now'   => $now,
                ));
                $linkedTargets[$targetDev['id']] = true;
            }
        }

        // 4. Alle übrigen Geräte im Scope (WLAN-Clients, weitere FRITZ!Boxen/APs, Geräte ohne SNMP-Switch-Zuordnung)
        // hängen am Gateway-Router (Haupt-FRITZ!Box) und NICHT pauschal an einem Switch!
        // Am Switch hängen nur Geräte, die per SNMP Bridge-MIB (Schritt 3), LLDP (Schritt 1) oder manuell (Schritt 0) dort nachgewiesen wurden.
        $defaultParent = $routerDevice ? $routerDevice : ($primarySwitch ? $primarySwitch : $devices[0]);
        foreach ($devices as $dev) {
            if ((int)$dev['id'] === (int)$routerDevice['id'] || isset($linkedTargets[$dev['id']])) {
                continue;
            }
            if ((int)$dev['id'] === (int)$defaultParent['id']) {
                continue;
            }
            $insertLink->execute(array(
                ':sid'   => $scopeId,
                ':src'   => $defaultParent['id'],
                ':dst'   => $dev['id'],
                ':sport' => null,
                ':dport' => null,
                ':ltype' => 'subnet',
                ':now'   => $now,
            ));
            $linkedTargets[$dev['id']] = true;
        }
    }

    /**
     * Speichert oder aktualisiert einen Eintrag in der historischen MAC-Port-Tabelle (mac_port_history).
     * Dedupliziert: Solange eine MAC am selben Switch und selben Port bleibt, wird nur last_seen + sightings_count
     * hochgezählt. Erst wenn die MAC an einem anderen Port oder Switch auftaucht, wird ein neuer Historien-Eintrag angelegt!
     */
    public function recordMacPortSighting(array $entry)
    {
        if (empty($entry['mac_address']) || empty($entry['switch_id']) || empty($entry['port_name'])) {
            return;
        }
        $mac = strtoupper(trim($entry['mac_address']));
        $swId = (int)$entry['switch_id'];
        $portName = trim($entry['port_name']);
        $now = !empty($entry['seen_at']) ? $entry['seen_at'] : date('Y-m-d H:i:s');
        $vlanId = !empty($entry['vlan_id']) ? (int)$entry['vlan_id'] : 1;

        try {
            $stmt = $this->pdo->prepare("
                SELECT id, switch_id, port_name
                FROM mac_port_history
                WHERE mac_address = :mac
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute(array(':mac' => $mac));
            $last = $stmt->fetch();

            if ($last && (int)$last['switch_id'] === $swId && (string)$last['port_name'] === $portName) {
                $upd = $this->pdo->prepare("
                    UPDATE mac_port_history
                    SET last_seen = :now,
                        sightings_count = sightings_count + 1,
                        ip_address = COALESCE(:ip, ip_address),
                        device_name = COALESCE(:dname, device_name),
                        vendor = COALESCE(:vend, vendor),
                        switch_name = COALESCE(:swname, switch_name),
                        switch_ip = COALESCE(:swip, switch_ip),
                        vlan_id = :vlan
                    WHERE id = :id
                ");
                $upd->execute(array(
                    ':now'    => $now,
                    ':ip'     => !empty($entry['ip_address']) ? $entry['ip_address'] : null,
                    ':dname'  => !empty($entry['device_name']) ? $entry['device_name'] : null,
                    ':vend'   => !empty($entry['vendor']) ? $entry['vendor'] : null,
                    ':swname' => !empty($entry['switch_name']) ? $entry['switch_name'] : null,
                    ':swip'   => !empty($entry['switch_ip']) ? $entry['switch_ip'] : null,
                    ':vlan'   => $vlanId,
                    ':id'     => (int)$last['id'],
                ));
            } else {
                $ins = $this->pdo->prepare("
                    INSERT INTO mac_port_history (
                        mac_address, ip_address, device_name, vendor,
                        switch_id, switch_name, switch_ip, port_name, vlan_id,
                        first_seen, last_seen, sightings_count
                    ) VALUES (
                        :mac, :ip, :dname, :vend,
                        :swid, :swname, :swip, :pname, :vlan,
                        :fseen, :lseen, 1
                    )
                ");
                $ins->execute(array(
                    ':mac'    => $mac,
                    ':ip'     => !empty($entry['ip_address']) ? $entry['ip_address'] : null,
                    ':dname'  => !empty($entry['device_name']) ? $entry['device_name'] : null,
                    ':vend'   => !empty($entry['vendor']) ? $entry['vendor'] : null,
                    ':swid'   => $swId,
                    ':swname' => !empty($entry['switch_name']) ? $entry['switch_name'] : null,
                    ':swip'   => !empty($entry['switch_ip']) ? $entry['switch_ip'] : null,
                    ':pname'  => $portName,
                    ':vlan'   => $vlanId,
                    ':fseen'  => $now,
                    ':lseen'  => $now,
                ));
            }
        } catch (Exception $e) {
            // Ignorieren falls Tabelle noch nicht initialisiert
        }
    }

    private function upsertDevice($data)
    {
        $scanStartMicro = microtime(true);
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("SELECT id, is_manual_type, device_type FROM devices WHERE ip_address = :ip");
        $stmt->execute(array(':ip' => $data['ip_address']));
        $existing = $stmt->fetch();

        if ($existing) {
            $deviceType = ((int)$existing['is_manual_type'] === 1) ? $existing['device_type'] : $data['device_type'];
            $upd = $this->pdo->prepare("
                UPDATE devices SET
                    scope_id = :scope_id,
                    mac_address = COALESCE(:mac_address, mac_address),
                    vendor = COALESCE(:vendor, vendor),
                    hostname = COALESCE(:hostname, hostname),
                    device_type = :device_type,
                    os_guess = :os_guess,
                    open_ports = :open_ports,
                    service_info = COALESCE(:service_info, service_info),
                    snmp_enabled = :snmp_enabled,
                    snmp_sysname = COALESCE(:snmp_sysname, snmp_sysname),
                    snmp_sysdescr = COALESCE(:snmp_sysdescr, snmp_sysdescr),
                    snmp_sysobjectid = COALESCE(:snmp_sysobjectid, snmp_sysobjectid),
                    snmp_location = COALESCE(:snmp_location, snmp_location),
                    snmp_ports_json = COALESCE(:snmp_ports_json, snmp_ports_json),
                    status = :status,
                    latency_ms = :latency_ms,
                    last_seen = :now
                WHERE id = :id
            ");
            $upd->execute(array(
                ':scope_id'        => $data['scope_id'],
                ':mac_address'     => $data['mac_address'],
                ':vendor'          => $data['vendor'],
                ':hostname'        => $data['hostname'],
                ':device_type'     => $deviceType,
                ':os_guess'        => $data['os_guess'],
                ':open_ports'      => $data['open_ports'],
                ':service_info'    => isset($data['service_info']) ? $data['service_info'] : null,
                ':snmp_enabled'    => $data['snmp_enabled'],
                ':snmp_sysname'    => $data['snmp_sysname'],
                ':snmp_sysdescr'   => $data['snmp_sysdescr'],
                ':snmp_sysobjectid'=> $data['snmp_sysobjectid'],
                ':snmp_location'   => $data['snmp_location'],
                ':snmp_ports_json' => $data['snmp_ports_json'],
                ':status'          => $data['status'],
                ':latency_ms'      => $data['latency_ms'],
                ':now'             => $now,
                ':id'              => $existing['id'],
            ));
            return (int)$existing['id'];
        } else {
            $autoIsCore = 0;
            if ($data['device_type'] === 'switch' || $data['device_type'] === 'router') {
                $coreHint = strtolower((string)$data['hostname'] . ' ' . (string)$data['snmp_sysname'] . ' ' . (string)$data['snmp_sysdescr']);
                if (preg_match('/\b(core|sw-core|catalyst\s*9[3456]00|c9[3456]00|c65\d\d|c45\d\d|c3850|nexus)\b/i', $coreHint)) {
                    $autoIsCore = 1;
                }
            }
            $ins = $this->pdo->prepare("
                INSERT INTO devices (
                    scope_id, ip_address, mac_address, vendor, hostname, device_type,
                    os_guess, open_ports, service_info, snmp_enabled, snmp_sysname, snmp_sysdescr,
                    snmp_sysobjectid, snmp_location, snmp_ports_json, status, latency_ms, is_core,
                    first_seen, last_seen
                ) VALUES (
                    :scope_id, :ip_address, :mac_address, :vendor, :hostname, :device_type,
                    :os_guess, :open_ports, :service_info, :snmp_enabled, :snmp_sysname, :snmp_sysdescr,
                    :snmp_sysobjectid, :snmp_location, :snmp_ports_json, :status, :latency_ms, :is_core,
                    :first_seen, :last_seen
                )
            ");
            $ins->execute(array(
                ':scope_id'        => $data['scope_id'],
                ':ip_address'      => $data['ip_address'],
                ':mac_address'     => $data['mac_address'],
                ':vendor'          => $data['vendor'],
                ':hostname'        => $data['hostname'],
                ':device_type'     => $data['device_type'],
                ':os_guess'        => $data['os_guess'],
                ':open_ports'      => $data['open_ports'],
                ':service_info'    => isset($data['service_info']) ? $data['service_info'] : null,
                ':snmp_enabled'    => $data['snmp_enabled'],
                ':snmp_sysname'    => $data['snmp_sysname'],
                ':snmp_sysdescr'   => $data['snmp_sysdescr'],
                ':snmp_sysobjectid'=> $data['snmp_sysobjectid'],
                ':snmp_location'   => $data['snmp_location'],
                ':snmp_ports_json' => $data['snmp_ports_json'],
                ':status'          => $data['status'],
                ':latency_ms'      => $data['latency_ms'],
                ':is_core'         => $autoIsCore,
                ':first_seen'      => $now,
                ':last_seen'       => $now,
            ));
            return (int)$this->pdo->lastInsertId();
        }
    }

    /**
     * Erweitert CIDR (z.B. 192.168.1.0/24) oder IP-Range (z.B. 192.168.1.1-50) in ein Array von IP-Strings
     */
    public function expandCidrOrRange($input, $maxHosts = 512)
    {
        $input = trim($input);

        // Fall 1: IP-Bereich wie 192.168.1.10-192.168.1.50 oder 192.168.1.10-50
        if (strpos($input, '-') !== false) {
            list($startPart, $endPart) = array_map('trim', explode('-', $input, 2));
            $startLong = ip2long($startPart);
            if ($startLong === false) {
                throw new InvalidArgumentException("Ungültiger IP-Bereich: $input");
            }
            if (strpos($endPart, '.') === false) {
                $octets = explode('.', $startPart);
                $octets[3] = (int)$endPart;
                $endPart = implode('.', $octets);
            }
            $endLong = ip2long($endPart);
            if ($endLong === false || $endLong < $startLong) {
                throw new InvalidArgumentException("Ungültiges Bereichsende: $input");
            }
            $ips = array();
            for ($ip = $startLong; $ip <= $endLong && count($ips) < $maxHosts; $ip++) {
                $ips[] = long2ip($ip);
            }
            return $ips;
        }

        // Fall 2: CIDR Notation wie 192.168.178.0/24
        if (strpos($input, '/') !== false) {
            list($baseIp, $prefix) = explode('/', $input, 2);
            $prefix = (int)$prefix;
            $ipLong = ip2long(trim($baseIp));
            if ($ipLong === false || $prefix < 16 || $prefix > 32) {
                throw new InvalidArgumentException("Ungültiges CIDR-Subnetz (unterstützt /16 bis /32): $input");
            }
            if ($prefix === 32) {
                return array(long2ip($ipLong));
            }
            $mask = -1 << (32 - $prefix);
            $netLong = $ipLong & $mask;
            $bcastLong = $netLong | (~$mask & 0xFFFFFFFF);

            $ips = array();
            for ($cur = $netLong + 1; $cur < $bcastLong && count($ips) < $maxHosts; $cur++) {
                $ips[] = long2ip($cur);
            }
            return $ips;
        }

        // Fall 3: Einzelne IP
        if (ip2long($input) !== false) {
            return array($input);
        }

        throw new InvalidArgumentException("Ungültiges Netzwerk-Format: $input");
    }

    /**
     * Findet aktive Hosts im Subnetz via Nmap (falls installiert) oder schnellem Parallel-Ping
     */
    private function discoverLiveHosts($cidr, $ipList)
    {
        $live = array();

        // Prüfe ob nmap verfügbar ist
        $nmapPath = trim((string)@shell_exec('which nmap 2>/dev/null'));
        if ($nmapPath !== '' && is_executable($nmapPath) && strpos($cidr, '/') !== false) {
            $cmd = escapeshellcmd($nmapPath) . ' -sn -T4 -oX - ' . escapeshellarg($cidr) . ' 2>/dev/null';
            $xmlOut = @shell_exec($cmd);
            if ($xmlOut && strpos($xmlOut, '<nmaprun') !== false) {
                $xml = @simplexml_load_string($xmlOut);
                if ($xml && isset($xml->host)) {
                    foreach ($xml->host as $h) {
                        if ((string)$h->status['state'] !== 'up') {
                            continue;
                        }
                        $ip = null;
                        $mac = null;
                        foreach ($h->address as $addr) {
                            $type = (string)$addr['addrtype'];
                            if ($type === 'ipv4') {
                                $ip = (string)$addr['addr'];
                            } elseif ($type === 'mac') {
                                $mac = (string)$addr['addr'];
                            }
                        }
                        $hostname = null;
                        if (isset($h->hostnames->hostname[0]['name'])) {
                            $hostname = (string)$h->hostnames->hostname[0]['name'];
                        }
                        $lat = 1.0;
                        if (isset($h->times['srtt'])) {
                            $lat = round(((int)$h->times['srtt']) / 1000, 2);
                        }
                        $ttl = null;
                        if (isset($h->status['reason_ttl']) && (int)$h->status['reason_ttl'] > 0) {
                            $ttl = (int)$h->status['reason_ttl'];
                        }
                        if ($ip) {
                            $live[$ip] = array(
                                'latency_ms' => $lat,
                                'mac'        => $mac,
                                'hostname'   => $hostname,
                                'ttl'        => $ttl,
                            );
                        }
                    }
                    if (!empty($live)) {
                        return $live;
                    }
                }
            }
        }

        // Fallback: Schneller paralleler Ping-Sweep in Batches
        $batchSize = isset($this->config['ping_batch_size']) ? (int)$this->config['ping_batch_size'] : 64;
        $chunks = array_chunk($ipList, $batchSize);

        foreach ($chunks as $chunk) {
            $procs = array();
            foreach ($chunk as $ip) {
                $descriptors = array(
                    0 => array('pipe', 'r'),
                    1 => array('pipe', 'w'),
                    2 => array('pipe', 'w'),
                );
                $cmd = 'ping -n -c 1 -W 1 ' . escapeshellarg($ip);
                $p = @proc_open($cmd, $descriptors, $pipes);
                if (is_resource($p)) {
                    fclose($pipes[0]);
                    $procs[$ip] = array('proc' => $p, 'stdout' => $pipes[1], 'stderr' => $pipes[2]);
                }
            }

            foreach ($procs as $ip => $info) {
                $out = stream_get_contents($info['stdout']);
                fclose($info['stdout']);
                fclose($info['stderr']);
                $exitCode = proc_close($info['proc']);
                if ($exitCode === 0) {
                    $lat = 1.0;
                    if (preg_match('/time[=<]([0-9.]+)\s*ms/i', $out, $m)) {
                        $lat = (float)$m[1];
                    }
                    $ttl = null;
                    if (preg_match('/ttl[=<]([0-9]+)/i', $out, $tm)) {
                        $ttl = (int)$tm[1];
                    }
                    $live[$ip] = array(
                        'latency_ms' => $lat,
                        'mac'        => null,
                        'hostname'   => null,
                        'ttl'        => $ttl,
                    );
                }
            }
        }

        return $live;
    }

    /**
     * Liest den ARP-/Neighbor-Cache des Linux-Kernels (/proc/net/arp & ip neigh)
     */
    private function readArpTable()
    {
        $map = array();
        if (is_readable('/proc/net/arp')) {
            $lines = @file('/proc/net/arp', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $idx => $line) {
                    if ($idx === 0) {
                        continue;
                    }
                    $cols = preg_split('/\s+/', trim($line));
                    if (count($cols) >= 4) {
                        $ip = $cols[0];
                        $mac = $cols[3];
                        if ($mac !== '00:00:00:00:00:00' && strlen($mac) === 17) {
                            $map[$ip] = strtoupper($mac);
                        }
                    }
                }
            }
        }

        $neighOut = @shell_exec('ip -4 neigh show 2>/dev/null');
        if ($neighOut) {
            $lines = explode("\n", trim($neighOut));
            foreach ($lines as $line) {
                if (preg_match('/^([0-9.]+)\s+.*lladdr\s+([0-9a-fA-F:]{17})/', trim($line), $m)) {
                    $map[$m[1]] = strtoupper($m[2]);
                }
            }
        }
        return $map;
    }

    /**
     * Liest IP + MAC der lokalen Netzwerkkarten des Servers aus
     */
    private function getLocalServerInterfaces()
    {
        $res = array();
        $out = @shell_exec('ip -o addr show 2>/dev/null');
        $linkOut = @shell_exec('ip -o link show 2>/dev/null');
        $ifMacs = array();
        if ($linkOut) {
            foreach (explode("\n", trim($linkOut)) as $line) {
                if (preg_match('/^\d+:\s+([^:@]+).*link\/ether\s+([0-9a-fA-F:]{17})/', $line, $m)) {
                    $ifMacs[trim($m[1])] = strtoupper($m[2]);
                }
            }
        }
        if ($out) {
            foreach (explode("\n", trim($out)) as $line) {
                if (preg_match('/^\d+:\s+([^\s]+)\s+inet\s+([0-9.]+)\//', $line, $m)) {
                    $ifName = trim($m[1]);
                    $ip = $m[2];
                    if ($ip !== '127.0.0.1') {
                        $res[$ip] = isset($ifMacs[$ifName]) ? $ifMacs[$ifName] : null;
                    }
                }
            }
        }
        return $res;
    }

    /**
     * Multi-Host-Batch TCP-Port-Check:
     * Prüft $hostBatchSize Hosts × 15 Ports gleichzeitig über einen einzigen asynchronen stream_select()-Loop!
     * Dadurch dauern 32 Hosts zusammen nur ~350ms (statt 32 × 350ms = 11,2 Sekunden).
     */
    private function probeTcpPortsMultiHostBatch(array $ipList, array $portsMap, $timeoutMs = 350, $hostBatchSize = 32)
    {
        $results = array();
        foreach ($ipList as $ip) {
            $results[$ip] = array();
        }
        if (empty($ipList) || empty($portsMap)) {
            return $results;
        }

        $chunks = array_chunk($ipList, max(1, (int)$hostBatchSize));
        $timeoutSec = $timeoutMs / 1000.0;

        foreach ($chunks as $chunk) {
            $sockets = array();
            $metaBySockId = array();

            foreach ($chunk as $ip) {
                foreach ($portsMap as $port => $label) {
                    $errno = 0;
                    $errstr = '';
                    $s = @stream_socket_client(
                        'tcp://' . $ip . ':' . (int)$port,
                        $errno,
                        $errstr,
                        0,
                        STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
                    );
                    if ($s) {
                        stream_set_blocking($s, false);
                        $id = (int)$s;
                        $sockets[$id] = $s;
                        $metaBySockId[$id] = array('ip' => $ip, 'port' => (int)$port);
                    }
                }
            }

            $start = microtime(true);
            while (!empty($sockets)) {
                $rem = $timeoutSec - (microtime(true) - $start);
                if ($rem <= 0) {
                    break;
                }
                $write = array_values($sockets);
                $read = null;
                $except = null;
                $sec = (int)floor($rem);
                $usec = (int)(($rem - $sec) * 1000000);

                $num = @stream_select($read, $write, $except, $sec, $usec);
                if ($num === false || $num === 0) {
                    break;
                }
                foreach ($write as $s) {
                    $id = (int)$s;
                    $meta = $metaBySockId[$id];
                    $peer = @stream_socket_get_name($s, true);
                    if ($peer !== false) {
                        $p = $meta['port'];
                        $results[$meta['ip']][$p] = $portsMap[$p];
                    }
                    @fclose($s);
                    unset($sockets[$id]);
                }
            }

            foreach ($sockets as $s) {
                @fclose($s);
            }
        }

        foreach ($results as $ip => $openMap) {
            ksort($results[$ip]);
        }
        return $results;
    }

    /**
     * Multi-Host-Batch DNS Reverse-Lookup (PTR) über parallele nicht-blockierende UDP-Sockets (Port 53)
     * Löst 64 IPs gleichzeitig in ~250ms auf, ohne durch mDNS/Avahi-Timeouts aufgehalten zu werden.
     */
    private function resolveHostnamesBatch(array $ipList, $timeoutMs = 250)
    {
        $map = array();
        if (empty($ipList)) {
            return $map;
        }

        // Ermittle lokalen DNS-Server aus /etc/resolv.conf (oder nutze .1 Gateway des ersten Hosts)
        $dnsServer = '127.0.0.53';
        if (is_readable('/etc/resolv.conf')) {
            $resolv = @file_get_contents('/etc/resolv.conf');
            if ($resolv && preg_match('/^\s*nameserver\s+([0-9.]+)/m', $resolv, $m)) {
                $dnsServer = $m[1];
            }
        }
        // Falls systemd-resolved (127.0.0.53), prüfe ob ein echter Router-DNS erreichbar ist
        $firstOctets = explode('.', $ipList[0]);
        $routerDns = (count($firstOctets) === 4) ? ($firstOctets[0] . '.' . $firstOctets[1] . '.' . $firstOctets[2] . '.1') : $dnsServer;

        $chunks = array_chunk($ipList, 64);
        $timeoutSec = $timeoutMs / 1000.0;

        foreach ($chunks as $chunk) {
            $sockets = array();
            $ipBySockId = array();

            foreach ($chunk as $ip) {
                $parts = explode('.', $ip);
                if (count($parts) !== 4) {
                    continue;
                }
                $ptrDomain = $parts[3] . '.' . $parts[2] . '.' . $parts[1] . '.' . $parts[0] . '.in-addr.arpa';
                $pkt = $this->buildDnsPtrQuery($ptrDomain);

                $errno = 0;
                $errstr = '';
                $sock = @stream_socket_client('udp://' . $routerDns . ':53', $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
                if ($sock) {
                    stream_set_blocking($sock, false);
                    @fwrite($sock, $pkt);
                    $id = (int)$sock;
                    $sockets[$id] = $sock;
                    $ipBySockId[$id] = $ip;
                }
            }

            $start = microtime(true);
            while (!empty($sockets)) {
                $rem = $timeoutSec - (microtime(true) - $start);
                if ($rem <= 0) {
                    break;
                }
                $read = array_values($sockets);
                $write = null;
                $except = null;
                $sec = (int)floor($rem);
                $usec = (int)(($rem - $sec) * 1000000);

                $num = @stream_select($read, $write, $except, $sec, $usec);
                if ($num === false || $num === 0) {
                    break;
                }
                foreach ($read as $rSock) {
                    $id = (int)$rSock;
                    $resp = @fread($rSock, 512);
                    if ($resp && strlen($resp) > 12) {
                        $host = $this->parseDnsPtrResponse($resp);
                        if ($host) {
                            $map[$ipBySockId[$id]] = $host;
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

        return $map;
    }

    private function buildDnsPtrQuery($domain)
    {
        $txId = mt_rand(1, 65000);
        // Header: ID, Flags (0x0100 Standard Query), QDCOUNT=1, ANCOUNT=0, NSCOUNT=0, ARCOUNT=0
        $pkt = pack('nnnnnn', $txId, 0x0100, 1, 0, 0, 0);
        foreach (explode('.', $domain) as $label) {
            $pkt .= chr(strlen($label)) . $label;
        }
        $pkt .= "\x00"; // Root null label
        $pkt .= pack('nn', 12, 1); // QTYPE = 12 (PTR), QCLASS = 1 (IN)
        return $pkt;
    }

    private function parseDnsPtrResponse($resp)
    {
        $len = strlen($resp);
        if ($len < 12) {
            return null;
        }
        $hdr = unpack('nid/nflags/nqdcount/nancount', substr($resp, 0, 8));
        if (!$hdr || $hdr['ancount'] < 1 || ($hdr['flags'] & 0x000F) !== 0) {
            return null;
        }
        $pos = 12;
        // Skip Question section
        for ($q = 0; $q < $hdr['qdcount']; $q++) {
            $this->readDnsName($resp, $pos);
            $pos += 4;
        }
        // Read first Answer RR
        for ($a = 0; $a < $hdr['ancount']; $a++) {
            if ($pos + 10 > $len) {
                break;
            }
            $this->readDnsName($resp, $pos);
            if ($pos + 10 > $len) {
                break;
            }
            $ans = unpack('ntype/nclass/Nttl/nrdlen', substr($resp, $pos, 10));
            $pos += 10;
            if ($ans['type'] === 12) { // PTR
                $rpos = $pos;
                $name = $this->readDnsName($resp, $rpos);
                return $name !== '' ? rtrim($name, '.') : null;
            }
            $pos += $ans['rdlen'];
        }
        return null;
    }

    private function readDnsName($pkt, &$pos)
    {
        $len = strlen($pkt);
        $labels = array();
        $jumped = false;
        $maxJumps = 10;
        $origPos = $pos;

        while ($pos < $len && $maxJumps-- > 0) {
            $l = ord($pkt[$pos]);
            if ($l === 0) {
                $pos++;
                break;
            }
            if (($l & 0xC0) === 0xC0) {
                if ($pos + 1 >= $len) {
                    break;
                }
                $ptr = (($l & 0x3F) << 8) | ord($pkt[$pos + 1]);
                if (!$jumped) {
                    $origPos = $pos + 2;
                    $jumped = true;
                }
                $pos = $ptr;
                continue;
            }
            $pos++;
            if ($pos + $l > $len) {
                break;
            }
            $labels[] = substr($pkt, $pos, $l);
            $pos += $l;
        }
        if ($jumped) {
            $pos = $origPos;
        }
        return implode('.', $labels);
    }

    /**
     * Asynchroner paralleler TCP-Port-Check für einen einzelnen Host
     */
    private function probeTcpPorts($ip, $portsMap, $timeoutMs = 350)
    {
        $open = array();
        $sockets = array();
        $portBySockId = array();

        foreach ($portsMap as $port => $label) {
            $errno = 0;
            $errstr = '';
            $s = @stream_socket_client(
                'tcp://' . $ip . ':' . (int)$port,
                $errno,
                $errstr,
                0,
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
            );
            if ($s) {
                stream_set_blocking($s, false);
                $id = (int)$s;
                $sockets[$id] = $s;
                $portBySockId[$id] = (int)$port;
            }
        }

        $start = microtime(true);
        $timeoutSec = $timeoutMs / 1000.0;

        while (!empty($sockets)) {
            $elapsed = microtime(true) - $start;
            $rem = $timeoutSec - $elapsed;
            if ($rem <= 0) {
                break;
            }
            $write = array_values($sockets);
            $read = null;
            $except = null;
            $sec = (int)floor($rem);
            $usec = (int)(($rem - $sec) * 1000000);

            $num = @stream_select($read, $write, $except, $sec, $usec);
            if ($num === false || $num === 0) {
                break;
            }
            foreach ($write as $s) {
                $id = (int)$s;
                $port = $portBySockId[$id];
                // Prüfen ob der Socket wirklich verbunden ist (kein Connection Refused)
                $peer = @stream_socket_get_name($s, true);
                if ($peer !== false) {
                    $open[$port] = $portsMap[$port];
                }
                @fclose($s);
                unset($sockets[$id]);
            }
        }

        foreach ($sockets as $s) {
            @fclose($s);
        }
        ksort($open);
        return $open;
    }

    private function resolveHostname($ip)
    {
        $host = @gethostbyaddr($ip);
        if ($host && $host !== $ip) {
            return $host;
        }
        return null;
    }

    private function formatOpenPorts($openPorts)
    {
        if (empty($openPorts)) {
            return '';
        }
        $parts = array();
        foreach ($openPorts as $p => $name) {
            $parts[] = $p . '/' . $name;
        }
        return implode(', ', $parts);
    }

    /**
     * Multi-Host-Batch NetBIOS Node Status (NBSTAT UDP 137):
     * Ermittelt Windows-Rechnernamen und Arbeitsgruppen/Domänen parallel in ~250ms.
     */
    private function probeNetbiosBatch(array $ipList, $timeoutMs = 250)
    {
        $results = array();
        if (empty($ipList)) {
            return $results;
        }

        // Standard NetBIOS Node Status Request für "*" (Wildcard)
        $nbQuery = "\x80\x94\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00"
                 . "\x20\x43\x4b\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41"
                 . "\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x00"
                 . "\x00\x21\x00\x01";

        $chunks = array_chunk($ipList, 64);
        $timeoutSec = $timeoutMs / 1000.0;

        foreach ($chunks as $chunk) {
            $sockets = array();
            $ipBySockId = array();

            foreach ($chunk as $ip) {
                $errno = 0;
                $errstr = '';
                $sock = @stream_socket_client('udp://' . $ip . ':137', $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
                if ($sock) {
                    stream_set_blocking($sock, false);
                    @fwrite($sock, $nbQuery);
                    $id = (int)$sock;
                    $sockets[$id] = $sock;
                    $ipBySockId[$id] = $ip;
                }
            }

            $start = microtime(true);
            while (!empty($sockets)) {
                $rem = $timeoutSec - (microtime(true) - $start);
                if ($rem <= 0) {
                    break;
                }
                $read = array_values($sockets);
                $write = null;
                $except = null;
                $sec = (int)floor($rem);
                $usec = (int)(($rem - $sec) * 1000000);

                $num = @stream_select($read, $write, $except, $sec, $usec);
                if ($num === false || $num === 0) {
                    break;
                }
                foreach ($read as $rSock) {
                    $id = (int)$rSock;
                    $resp = @fread($rSock, 512);
                    if ($resp && strlen($resp) > 57) {
                        $parsed = $this->parseNetbiosResponse($resp);
                        if ($parsed) {
                            $results[$ipBySockId[$id]] = $parsed;
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

        return $results;
    }

    private function parseNetbiosResponse($resp)
    {
        $len = strlen($resp);
        if ($len < 57) {
            return null;
        }
        $numNames = ord($resp[56]);
        if ($numNames < 1 || $numNames > 32) {
            return null;
        }
        $pos = 57;
        $hostName = null;
        $workgroup = null;

        for ($i = 0; $i < $numNames && ($pos + 18) <= $len; $i++) {
            $rawName = substr($resp, $pos, 15);
            $suffix = ord($resp[$pos + 15]);
            $flags = unpack('nf', substr($resp, $pos + 16, 2));
            $isGroup = ($flags && ($flags['f'] & 0x8000) !== 0);
            $clean = trim(preg_replace('/[^\x20-\x7E]/', '', $rawName));

            if ($clean !== '') {
                if (!$isGroup && $suffix === 0x00 && $hostName === null) {
                    $hostName = $clean;
                } elseif ($isGroup && $suffix === 0x00 && $workgroup === null) {
                    $workgroup = $clean;
                }
            }
            $pos += 18;
        }

        if ($hostName === null && $workgroup === null) {
            return null;
        }
        return array('name' => $hostName, 'workgroup' => $workgroup);
    }

    /**
     * Multi-Host-Batch Service-Banner & HTTP-Title Grabber:
     * Liest von bereits als offen erkannten Ports (22 SSH, 80/8080 HTTP) parallel
     * den SSH-Versionsstring, den HTTP Server-Header sowie den HTML <title> aus.
     */
    private function probeServiceBannersBatch(array $batchOpenPorts, $timeoutMs = 550)
    {
        $banners = array();
        $targets = array();

        foreach ($batchOpenPorts as $ip => $ports) {
            $banners[$ip] = array(
                'ssh_banner'  => null,
                'http_server' => null,
                'http_title'  => null,
            );
            if (isset($ports[22])) {
                $targets[] = array('ip' => $ip, 'port' => 22, 'proto' => 'ssh');
            }
            if (isset($ports[80])) {
                $targets[] = array('ip' => $ip, 'port' => 80, 'proto' => 'http');
            } elseif (isset($ports[8080])) {
                $targets[] = array('ip' => $ip, 'port' => 8080, 'proto' => 'http');
            }
        }

        if (empty($targets)) {
            return $banners;
        }

        $chunks = array_chunk($targets, 32);
        $timeoutSec = $timeoutMs / 1000.0;

        foreach ($chunks as $chunk) {
            $sockets = array();
            $meta = array();

            foreach ($chunk as $t) {
                $errno = 0;
                $errstr = '';
                $s = @stream_socket_client(
                    'tcp://' . $t['ip'] . ':' . $t['port'],
                    $errno,
                    $errstr,
                    0,
                    STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
                );
                if ($s) {
                    stream_set_blocking($s, false);
                    $id = (int)$s;
                    $sockets[$id] = $s;
                    $meta[$id] = array(
                        'ip'    => $t['ip'],
                        'port'  => $t['port'],
                        'proto' => $t['proto'],
                        'sent'  => false,
                        'buf'   => '',
                    );
                }
            }

            $start = microtime(true);
            while (!empty($sockets)) {
                $rem = $timeoutSec - (microtime(true) - $start);
                if ($rem <= 0) {
                    break;
                }
                $read = array_values($sockets);
                $write = array();
                foreach ($sockets as $id => $s) {
                    if ($meta[$id]['proto'] === 'http' && !$meta[$id]['sent']) {
                        $write[] = $s;
                    }
                }
                if (empty($write)) {
                    $write = null;
                }
                $except = null;
                $sec = (int)floor($rem);
                $usec = (int)(($rem - $sec) * 1000000);

                $num = @stream_select($read, $write, $except, $sec, $usec);
                if ($num === false || $num === 0) {
                    break;
                }

                if (is_array($write)) {
                    foreach ($write as $wSock) {
                        $id = (int)$wSock;
                        if (isset($meta[$id]) && !$meta[$id]['sent']) {
                            $req = "GET / HTTP/1.0\r\nHost: " . $meta[$id]['ip'] . "\r\nUser-Agent: NetworkMonitor/1.0\r\nConnection: close\r\n\r\n";
                            @fwrite($wSock, $req);
                            $meta[$id]['sent'] = true;
                        }
                    }
                }

                if (is_array($read)) {
                    foreach ($read as $rSock) {
                        $id = (int)$rSock;
                        if (!isset($meta[$id])) {
                            continue;
                        }
                        $chunkData = @fread($rSock, 4096);
                        if ($chunkData === false || $chunkData === '') {
                            $this->extractBannerFromBuffer($banners, $meta[$id]);
                            @fclose($rSock);
                            unset($sockets[$id], $meta[$id]);
                        } else {
                            $meta[$id]['buf'] .= $chunkData;
                            if ($meta[$id]['proto'] === 'ssh' && strpos($meta[$id]['buf'], "\n") !== false) {
                                $this->extractBannerFromBuffer($banners, $meta[$id]);
                                @fclose($rSock);
                                unset($sockets[$id], $meta[$id]);
                            } elseif (strlen($meta[$id]['buf']) >= 4096 || stripos($meta[$id]['buf'], '</title>') !== false) {
                                $this->extractBannerFromBuffer($banners, $meta[$id]);
                                @fclose($rSock);
                                unset($sockets[$id], $meta[$id]);
                            }
                        }
                    }
                }
            }

            foreach ($sockets as $id => $s) {
                if (isset($meta[$id]) && $meta[$id]['buf'] !== '') {
                    $this->extractBannerFromBuffer($banners, $meta[$id]);
                }
                @fclose($s);
            }
        }

        return $banners;
    }

    private function extractBannerFromBuffer(&$banners, $m)
    {
        $ip = $m['ip'];
        $buf = $m['buf'];
        if ($buf === '') {
            return;
        }

        if ($m['proto'] === 'ssh') {
            $firstLine = strtok($buf, "\r\n");
            if ($firstLine && strpos($firstLine, 'SSH-') === 0) {
                $clean = trim(preg_replace('/^SSH-\d+\.\d+-/', '', $firstLine));
                $banners[$ip]['ssh_banner'] = substr($clean, 0, 60);
            }
            return;
        }

        if ($m['proto'] === 'http') {
            if (preg_match('/^Server:\s*([^\r\n]+)/mi', $buf, $sm)) {
                $srv = trim($sm[1]);
                if ($srv !== '') {
                    $banners[$ip]['http_server'] = substr($srv, 0, 50);
                }
            }
            // Cisco Small Business Managed Switch (SG200 / SG300 / SG500 / SF500) Redirect-Erkennung (/csbXXXX/)
            if (preg_match('/Location:\s*(?:https?:\/\/[^\/]+)?(\/csb[0-9a-f]+\/)/i', $buf, $csbMatch)) {
                $csbPath = $csbMatch[1];
                $banners[$ip]['http_title'] = 'Cisco Small Business Managed Switch';
                $ctx = stream_context_create(array('http' => array('timeout' => 0.8, 'ignore_errors' => true)));
                $dictXml = @file_get_contents('http://' . $ip . ':' . $m['port'] . $csbPath . 'device/English/dictionaryLogin.xml', false, $ctx);
                if ($dictXml) {
                    $fwVer = '';
                    if (preg_match('/<version>([^<]+)<\/version>/i', $dictXml, $vm)) {
                        $fwVer = trim($vm[1]);
                    }
                    if (preg_match_all('/<moduleType>([^<]+)<\/moduleType>/i', $dictXml, $mm) && !empty($mm[1])) {
                        $firstMod = trim($mm[1][0]);
                        $series = preg_replace('/-.*$/', '', $firstMod);
                        $banners[$ip]['http_title'] = 'Cisco ' . $series . ' Series Managed Switch' . ($fwVer ? (' (FW ' . $fwVer . ')') : '');
                    }
                }
                return;
            }
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $buf, $tm)) {
                $title = trim(html_entity_decode(strip_tags($tm[1]), ENT_QUOTES, 'UTF-8'));
                $title = preg_replace('/\s+/', ' ', $title);
                if ($title !== '' && stripos($title, '301 Moved') === false && stripos($title, '302 Found') === false && stripos($title, '404 Not Found') === false) {
                    $banners[$ip]['http_title'] = substr($title, 0, 65);
                }
            }
        }
    }

    /**
     * Intelligente Betriebssystem-Erkennung (OS-Fingerprinting):
     * Kombiniert SNMP sysDescr, SSH/HTTP-Banner, Hostnamen, offene Ports, Hersteller-OUI und den ICMP-TTL-Wert!
     * (TTL ~64 = Linux/macOS/iOS/Android | TTL ~128 = Windows | TTL ~255 = Cisco/Switch/Netzwerkgerät)
     */
    private function guessOs($openPorts, $vendor, $snmpSysDescr, $deviceType, $ttl = null, $hostname = null, $serviceInfo = null)
    {
        // Lokales DNS-Suffix (.fritz.box / .local / .lan) vor dem Keyword-Matching entfernen!
        $hostShort = preg_replace('/\.(fritz\.box|local|lan)$/i', '', (string)$hostname);

        $combined = strtolower(
            (string)$snmpSysDescr . ' ' .
            (string)$vendor . ' ' .
            $hostShort . ' ' .
            (string)$serviceInfo
        );

        // 1. Spezifische Netzwerk- & Infrastruktur-Systeme
        if (strpos($combined, 'cisco ios') !== false) {
            return 'Cisco IOS';
        }
        if (strpos($combined, 'cisco sg') !== false || strpos($combined, 'cisco sf') !== false || strpos($combined, 'small business') !== false || strpos($combined, 'goahead-webs') !== false && strpos($combined, 'cisco') !== false) {
            return 'Cisco Small Business Switch OS';
        }
        if (strpos($combined, 'procurve') !== false || strpos($combined, 'arubaos') !== false) {
            return 'HP ArubaOS / ProCurve';
        }
        if (strpos($combined, 'routeros') !== false || strpos($combined, 'mikrotik') !== false) {
            return 'MikroTik RouterOS';
        }
        if (
            strpos($combined, 'fritz!box') !== false ||
            strpos($combined, 'fritzbox') !== false ||
            strtolower($hostShort) === 'fritz.box' ||
            strpos($combined, 'avm gmbh') !== false
        ) {
            return 'AVM FRITZ!OS (Linux)';
        }
        if (strpos($combined, 'ubnt') !== false || strpos($combined, 'ubiquiti') !== false || strpos($combined, 'unifi') !== false) {
            return 'Ubiquiti UniFi OS (Linux)';
        }
        if (strpos($combined, 'synology') !== false || strpos($combined, 'diskstation') !== false) {
            return 'Synology DSM (Linux)';
        }
        if (strpos($combined, 'qnap') !== false) {
            return 'QNAP QTS (Linux)';
        }
        if (strpos($combined, 'proxmox') !== false) {
            return 'Proxmox VE (Debian Linux)';
        }
        if (strpos($combined, 'vmware') !== false || strpos($combined, 'esxi') !== false) {
            return 'VMware ESXi';
        }

        // 2. Exakte Linux-Distributionen aus SSH-Banner oder SNMP
        if (strpos($combined, 'ubuntu') !== false) {
            return 'Ubuntu Linux';
        }
        if (strpos($combined, 'debian') !== false || strpos($combined, 'raspbian') !== false) {
            return 'Debian / Raspberry Pi OS';
        }

        // 3. Apple-Ökosystem (iOS / iPadOS / tvOS / macOS)
        if (strpos($combined, 'iphone') !== false) {
            return 'Apple iOS (iPhone)';
        }
        if (strpos($combined, 'ipad') !== false) {
            return 'Apple iPadOS (iPad)';
        }
        if (strpos($combined, 'apple-tv') !== false || strpos($combined, 'appletv') !== false) {
            return 'Apple tvOS';
        }
        if (strpos($combined, 'macbook') !== false || strpos($combined, 'imac') !== false || strpos($combined, 'mac-mini') !== false) {
            return 'Apple macOS';
        }
        if (strpos($combined, 'apple') !== false) {
            return 'Apple iOS / macOS (Darwin)';
        }

        // 4. Google Android / Samsung / Mobile
        if (
            strpos($combined, 'android') !== false ||
            strpos($combined, 'galaxy') !== false ||
            strpos($combined, 's25') !== false ||
            strpos($combined, 's24') !== false ||
            strpos($combined, 's23') !== false ||
            strpos($combined, 'pixel') !== false ||
            strpos($combined, 'xiaomi') !== false ||
            strpos($combined, 'huawei') !== false
        ) {
            return 'Google Android';
        }

        // 5. Smart-Home & Embedded IoT (Espressif ESP32/ESP8266, Tuya, Amazon Echo, Internet Radio)
        if (strpos($combined, 'espressif') !== false || strpos($combined, 'esp32') !== false || strpos($combined, 'esp8266') !== false || strpos($combined, 'tasmota') !== false) {
            return 'FreeRTOS / ESP-IDF (IoT)';
        }
        if (strpos($combined, 'tuya') !== false) {
            return 'Embedded IoT Linux (Tuya)';
        }
        if (strpos($combined, 'magic iradio') !== false || strpos($combined, 'frontier silicon') !== false || strpos($combined, 'http-ir-') !== false) {
            return 'Frontier Smart Radio OS (Embedded)';
        }
        if (strpos($combined, 'amazon') !== false || strpos($combined, 'echo') !== false || strpos($combined, 'fire') !== false) {
            return 'Amazon Fire OS (Android/Linux)';
        }

        // 6. Drucker-Firmware
        if ($deviceType === 'printer' || strpos($combined, 'jetdirect') !== false || strpos($combined, 'laserjet') !== false || strpos($combined, 'officejet') !== false) {
            return 'HP / Embedded Printer Firmware';
        }

        // 7. Microsoft Windows (Ports 3389 RDP, 445 SMB, 135 RPC, IIS, Workgroup oder ICMP TTL ~128!)
        if (
            strpos($combined, 'windows') !== false ||
            strpos($combined, 'microsoft-iis') !== false ||
            strpos($combined, 'workgroup:') !== false ||
            isset($openPorts[3389]) ||
            (isset($openPorts[445]) && !isset($openPorts[22])) ||
            ($ttl !== null && $ttl > 64 && $ttl <= 128) ||
            (($ttl === null || $ttl > 64) && (strpos($combined, 'desktop-') !== false || strpos($combined, 'laptop-') !== false))
        ) {
            return 'Microsoft Windows 10 / 11';
        }

        // 8. Linux / Unix (Port 22 SSH, Linux-Banner oder PC/Notebook mit TTL 64)
        if (strpos($combined, 'linux') !== false || isset($openPorts[22])) {
            return 'Linux / Unix';
        }
        if ($ttl !== null && $ttl <= 64 && (strpos($combined, 'intel') !== false || strpos($combined, 'asustek') !== false || strpos($combined, 'realtek') !== false || strpos($combined, 'dell') !== false)) {
            return 'Linux Workstation / Desktop (TTL ' . (int)$ttl . ')';
        }

        // 9. ICMP TTL-basierte Klassifizierung, falls keine Ports offen sind (z.B. Firewall aktiv)
        if ($ttl !== null) {
            if ($ttl > 128) {
                return 'Embedded / Network Appliance (TTL ' . (int)$ttl . ')';
            }
            if ($ttl > 64 && $ttl <= 128) {
                return 'Microsoft Windows (TTL ' . (int)$ttl . ')';
            }
            if ($ttl <= 64) {
                if (strpos($combined, 'randomisiert') !== false || strpos($combined, 'private') !== false) {
                    return 'iOS / Android Mobilgerät (TTL ' . (int)$ttl . ')';
                }
                return 'Linux / Embedded / Mobile OS (TTL ' . (int)$ttl . ')';
            }
        }

        if (strpos($combined, 'randomisiert') !== false || strpos($combined, 'private') !== false) {
            return 'iOS / Android Mobilgerät (Sleep)';
        }
        if ($deviceType === 'switch') {
            return 'Managed Switch OS';
        }
        if ($deviceType === 'router') {
            return 'Router / Firewall OS';
        }
        return 'Embedded / Unbekannt';
    }
}
