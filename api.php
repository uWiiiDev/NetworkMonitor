<?php
/**
 * NetworkMonitor - Zentraler JSON-Backend-Controller (REST/RPC-API)
 *
 * Nimmt sämtliche AJAX-/Fetch-Anfragen des Frontends ('assets/app.js') entgegen,
 * prüft für jede Aktion ('action') die erforderliche Rollenberechtigung
 * ('viewer', 'operator', 'netadmin', 'admin') und liefert ein JSON-Objekt zurück.
 *
 * Wichtiger Architektur-Hinweis:
 * Direkt nach der Authentifizierungsprüfung wird '@session_write_close()' aufgerufen,
 * damit langlaufende Hintergrund-Aktionen (wie Netzwerk-Scans oder SSH-Switch-Befehle)
 * niemals parallele Leseanfragen derselben Browser-Session blockieren.
 *
 * Kompatibel mit PHP 5.5+ (Ubuntu 14.04) bis PHP 8.x.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/scanner.php';

$db = Database::getInstance();
$pdo = $db->getPdo();
$currentUser = Auth::requireRole(array('viewer', 'operator', 'netadmin', 'admin'));
$isNetExpert = in_array($currentUser['role'], array('netadmin', 'admin'), true);
$canEditRole = in_array($currentUser['role'], array('operator', 'netadmin', 'admin'), true);

// Gib das PHP-Session-File-Lock sofort frei, damit parallele Requests (z.B. während eines Scans) niemals blockieren
Auth::closeSession();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : 'topology');

$rawBody = file_get_contents('php://input');
$jsonBody = $rawBody ? @json_decode($rawBody, true) : array();
if (!is_array($jsonBody)) {
    $jsonBody = array();
}
$input = array_merge($_GET, $_POST, $jsonBody);

try {
    switch ($action) {
                // ---------------------------------------------------------------------
        // ENDPOINT: topology / dashboard
        // Liefert alle Scopes, Geräte, Links, Alarme, MAC-Historie, Dosen-Mappings
        // und (für Admins) Benutzer- & Systemeinstellungen. Filtert Core-Switches
        // und sensible Zugangsdaten abhängig von der Benutzerrolle.
        // ---------------------------------------------------------------------
        case 'topology':
            $scopes = $pdo->query("SELECT * FROM scopes ORDER BY id ASC")->fetchAll();
            if (!$canEditRole) {
                for ($si = 0; $si < count($scopes); $si++) {
                    unset($scopes[$si]['snmp_community']);
                }
            }
            $rawDevices = $pdo->query("
                SELECT d.*, s.name AS scope_name, s.cidr AS scope_cidr, s.color AS scope_color, s.vlan_id
                FROM devices d
                LEFT JOIN scopes s ON d.scope_id = s.id
                ORDER BY d.scope_id ASC, d.ip_address ASC
            ")->fetchAll();

            // Ermittle Core-Switches & filtere sie für Nicht-Netzwerkexperten ('viewer' & 'operator') vollständig aus!
            $hiddenCoreIds = array();
            $gatewayIdByScope = array();
            foreach ($rawDevices as $rd) {
                $sid = (int)$rd['scope_id'];
                if ($rd['device_type'] === 'router' && (int)$rd['is_core'] === 0 && !isset($gatewayIdByScope[$sid])) {
                    $gatewayIdByScope[$sid] = (int)$rd['id'];
                }
            }

            $devices = array();
            foreach ($rawDevices as $dRow) {
                $dRow['is_core'] = !empty($dRow['is_core']) ? 1 : 0;
                if ($dRow['is_core'] === 1 && !$isNetExpert) {
                    $hiddenCoreIds[(int)$dRow['id']] = (int)$dRow['scope_id'];
                    continue;
                }
                $profInfo = SnmpClient::detectSwitchCliProfile($dRow);
                $dRow['cli_profile'] = $profInfo['profile'];
                $dRow['cli_profile_label'] = $profInfo['label'];
                $dRow['cli_profile_is_manual'] = !empty($profInfo['is_manual']) ? 1 : 0;
                $rawWrite = isset($dRow['switch_snmp_write_community']) ? (string)$dRow['switch_snmp_write_community'] : '';
                if (preg_match('/^profile:[a-z0-9_]+\|(.*)$/i', $rawWrite, $wm)) {
                    $rawWrite = $wm[1];
                }
                $rawSshPass = isset($dRow['switch_ssh_pass']) ? (string)$dRow['switch_ssh_pass'] : '';
                $dRow['has_ssh_pass'] = ($rawSshPass !== '') ? 1 : 0;
                $dRow['has_enable_pass'] = (strpos($rawSshPass, "\n__EN__:") !== false) ? 1 : 0;
                $dRow['has_snmp_write'] = ($rawWrite !== '') ? 1 : 0;
                unset($dRow['switch_ssh_pass']);
                unset($dRow['switch_snmp_write_community']);
                if (!$canEditRole) {
                    unset($dRow['switch_ssh_user'], $dRow['switch_snmp_community']);
                }
                $devices[] = $dRow;
            }

            $rawLinks = $pdo->query("SELECT * FROM links ORDER BY id ASC")->fetchAll();
            $links = array();
            if (empty($hiddenCoreIds)) {
                $links = $rawLinks;
            } else {
                foreach ($rawLinks as $lnk) {
                    $srcId = (int)$lnk['source_device_id'];
                    $dstId = (int)$lnk['target_device_id'];
                    if (isset($hiddenCoreIds[$dstId])) {
                        continue;
                    }
                    if (isset($hiddenCoreIds[$srcId])) {
                        $scId = $hiddenCoreIds[$srcId];
                        if (isset($gatewayIdByScope[$scId]) && $gatewayIdByScope[$scId] !== $dstId) {
                            $lnk['source_device_id'] = $gatewayIdByScope[$scId];
                            $lnk['source_port'] = 'Uplink';
                            $lnk['link_type'] = 'subnet';
                            $links[] = $lnk;
                        }
                        continue;
                    }
                    $links[] = $lnk;
                }
            }

            $logs = $pdo->query("
                SELECT l.*, s.name AS scope_name, s.cidr AS scope_cidr
                FROM scan_logs l
                LEFT JOIN scopes s ON l.scope_id = s.id
                ORDER BY l.id DESC LIMIT 20
            ")->fetchAll();

            $rawAlerts = $pdo->query("
                SELECT a.*, s.name AS scope_name
                FROM device_alerts a
                LEFT JOIN scopes s ON a.scope_id = s.id
                ORDER BY a.is_acknowledged ASC, a.id DESC LIMIT 100
            ")->fetchAll();
            $alerts = array();
            foreach ($rawAlerts as $alRow) {
                if (!isset($hiddenCoreIds[(int)$alRow['device_id']])) {
                    $alerts[] = $alRow;
                }
            }
            $unackStmt = $pdo->query("SELECT COUNT(*) FROM device_alerts WHERE is_acknowledged = 0");
            $unackCount = $unackStmt ? (int)$unackStmt->fetchColumn() : 0;
            if ($unackStmt) {
                $unackStmt->closeCursor();
            }

            $macHistory = array();
            try {
                $rawHistory = $pdo->query("SELECT * FROM mac_port_history ORDER BY last_seen DESC, id DESC LIMIT 600")->fetchAll();
                foreach ($rawHistory as $mhRow) {
                    if (!isset($hiddenCoreIds[(int)$mhRow['switch_id']])) {
                        $macHistory[] = $mhRow;
                    }
                }
            } catch (Exception $e) {}

            $macWatchlist = array();
            try {
                $macWatchlist = $pdo->query("SELECT * FROM mac_watchlist ORDER BY id DESC")->fetchAll();
            } catch (Exception $e) {}

            $portOutlets = array();
            try {
                $rawOutlets = $pdo->query("SELECT * FROM switch_port_outlets ORDER BY switch_id ASC, id ASC")->fetchAll();
                foreach ($rawOutlets as $poRow) {
                    if (!isset($hiddenCoreIds[(int)$poRow['switch_id']])) {
                        $portOutlets[] = $poRow;
                    }
                }
            } catch (Exception $e) {}

            $nmapPath = trim((string)@shell_exec('which nmap 2>/dev/null'));
            $snmpExt = extension_loaded('snmp');

            $fullConfAll = $db->getConfig();
            $cronIntMin = isset($fullConfAll['scanner']['cron_interval_minutes']) ? (int)$fullConfAll['scanner']['cron_interval_minutes'] : 15;
            if ($cronIntMin <= 0) {
                $cronIntMin = 15;
            }
            $crontabRaw = (string)@shell_exec('crontab -l 2>/dev/null');
            $cronActive = false;
            $detectedCronMin = $cronIntMin;
            if ($crontabRaw !== '' && strpos($crontabRaw, 'cli_scan.php') !== false) {
                $cronActive = true;
                if (preg_match('/^\s*\*\/([0-9]+)\s+\*\s+\*\s+\*\s+\*.*cli_scan\.php/m', $crontabRaw, $cm)) {
                    $detectedCronMin = (int)$cm[1];
                } elseif (preg_match('/^\s*0\s+\*\s+\*\s+\*\s+\*.*cli_scan\.php/m', $crontabRaw)) {
                    $detectedCronMin = 60;
                }
            }
            $cronScheduleExpr = ($detectedCronMin === 60) ? '0 * * * *' : ('*/' . $detectedCronMin . ' * * * *');
            $cliScanReal = realpath(__DIR__ . '/cli_scan.php');
            $cronLogPath = __DIR__ . '/data/cron_scan.log';
            $cronCmdLine = $cronScheduleExpr . ' /usr/bin/php ' . $cliScanReal . ' --all >> ' . $cronLogPath . ' 2>&1 # NetworkMonitor-AutoScan';

            $anomalyConfig = array(
                'anomaly_arp_spoofing'           => !isset($fullConfAll['scanner']['anomaly_arp_spoofing']) || (int)$fullConfAll['scanner']['anomaly_arp_spoofing'] === 1 ? 1 : 0,
                'anomaly_new_mac'                => !isset($fullConfAll['scanner']['anomaly_new_mac']) || (int)$fullConfAll['scanner']['anomaly_new_mac'] === 1 ? 1 : 0,
                'anomaly_rogue_switch_max_macs'  => isset($fullConfAll['scanner']['anomaly_rogue_switch_max_macs']) ? (int)$fullConfAll['scanner']['anomaly_rogue_switch_max_macs'] : 3,
                'anomaly_port_error_threshold'   => isset($fullConfAll['scanner']['anomaly_port_error_threshold']) ? (int)$fullConfAll['scanner']['anomaly_port_error_threshold'] : 50,
                'anomaly_mac_flapping_threshold' => isset($fullConfAll['scanner']['anomaly_mac_flapping_threshold']) ? (int)$fullConfAll['scanner']['anomaly_mac_flapping_threshold'] : 3,
            );

            $adminData = null;
            if ($currentUser['role'] === 'admin') {
                $fullConf = $fullConfAll;
                $smtpConf = isset($fullConf['smtp']) ? $fullConf['smtp'] : array();
                $usersList = $pdo->query("SELECT id, username, full_name, email, receive_alerts, two_fa_mode, totp_secret, role, is_active, last_login, created_at FROM users ORDER BY id ASC")->fetchAll();
                $adminData = array(
                    'db_config' => array(
                        'driver'   => $fullConf['db']['driver'],
                        'host'     => $fullConf['db']['host'],
                        'port'     => $fullConf['db']['port'],
                        'dbname'   => $fullConf['db']['dbname'],
                        'username' => $fullConf['db']['username'],
                        'active_driver' => $db->getActiveDriver(),
                        'conn_error'    => $db->getConnectionError(),
                    ),
                    'scanner_config' => array(
                        'max_hosts_per_scan'             => $fullConf['scanner']['max_hosts_per_scan'],
                        'ping_batch_size'                => $fullConf['scanner']['ping_batch_size'],
                        'port_host_batch_size'           => $fullConf['scanner']['port_host_batch_size'],
                        'snmp_batch_size'                => $fullConf['scanner']['snmp_batch_size'],
                        'default_snmp_community'         => $fullConf['scanner']['default_snmp_community'],
                        'retention_days'                 => isset($fullConf['scanner']['retention_days']) ? (int)$fullConf['scanner']['retention_days'] : 14,
                        'stale_offline_days'             => isset($fullConf['scanner']['stale_offline_days']) ? (int)$fullConf['scanner']['stale_offline_days'] : 30,
                        'cron_interval_minutes'          => $detectedCronMin,
                        'cron_active'                    => $cronActive ? 1 : 0,
                        'anomaly_arp_spoofing'           => $anomalyConfig['anomaly_arp_spoofing'],
                        'anomaly_new_mac'                => $anomalyConfig['anomaly_new_mac'],
                        'anomaly_rogue_switch_max_macs'  => $anomalyConfig['anomaly_rogue_switch_max_macs'],
                        'anomaly_port_error_threshold'   => $anomalyConfig['anomaly_port_error_threshold'],
                        'anomaly_mac_flapping_threshold' => $anomalyConfig['anomaly_mac_flapping_threshold'],
                    ),
                    'smtp_config' => array(
                        'enabled'    => !empty($smtpConf['enabled']) ? 1 : 0,
                        'host'       => isset($smtpConf['host']) ? $smtpConf['host'] : '',
                        'port'       => isset($smtpConf['port']) ? (int)$smtpConf['port'] : 587,
                        'encryption' => isset($smtpConf['encryption']) ? $smtpConf['encryption'] : 'tls',
                        'username'   => isset($smtpConf['username']) ? $smtpConf['username'] : '',
                        'from_email' => isset($smtpConf['from_email']) ? $smtpConf['from_email'] : '',
                        'from_name'  => isset($smtpConf['from_name']) ? $smtpConf['from_name'] : 'NetworkMonitor Alarm',
                        'to_email'   => isset($smtpConf['to_email']) ? $smtpConf['to_email'] : '',
                    ),
                    'users' => $usersList,
                );
            }

            echo json_encode(array(
                'ok'           => true,
                'current_user' => $currentUser,
                'scopes'       => $scopes,
                'devices'      => $devices,
                'links'        => $links,
                'logs'         => $logs,
                'alerts'        => $alerts,
                'unack_alerts'  => $unackCount,
                'mac_history'   => $macHistory,
                'mac_watchlist' => $macWatchlist,
                'port_outlets'  => $portOutlets,
                'admin'         => $adminData,
                'system'       => array(
                    'app_version'    => Database::getAppVersion(),
                    'git_commit'     => Database::getGitCommitShort(),
                    'php_version'    => PHP_VERSION,
                    'db_driver'      => strtoupper($db->getActiveDriver()),
                    'nmap_found'     => ($nmapPath !== ''),
                    'nmap_path'      => $nmapPath !== '' ? $nmapPath : 'Nicht installiert (nutzt schnellen PHP Parallel-Ping & ARP)',
                    'snmp_mode'      => $snmpExt ? 'PHP-SNMP Extension + Native UDP SNMPv2c' : 'Integrierter Pure-PHP UDP SNMPv2c Client (aktiv)',
                    'server_time'    => date('Y-m-d H:i:s'),
                    'cron_active'    => $cronActive ? 1 : 0,
                    'cron_interval'  => $detectedCronMin,
                    'cron_cmd'       => $cronCmdLine,
                    'anomaly_config' => $anomalyConfig,
                ),
            ));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: scan
        // Startet einen manuellen Netzwerk-Scan für einen einzelnen Scope oder alle aktiven Scopes.
        // ---------------------------------------------------------------------
        case 'scan':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            @set_time_limit(300);
            $scanner = new NetworkScanner();
            $scopeId = isset($input['scope_id']) ? $input['scope_id'] : 'all';
            if ($scopeId === 'all' || (int)$scopeId === 0) {
                $results = $scanner->scanAllActiveScopes('manual');
            } else {
                $results = array($scanner->scanScope((int)$scopeId, 'manual'));
            }
            echo json_encode(array(
                'ok'      => true,
                'results' => $results,
            ));
            break;

        // ---------------------------------------------------------------------
        // ENDPOINT: probe_device_snmp
        // Fragt ein einzelnes Gerät (z.B. Netzwerkdrucker für Tintenfüllstände oder Switch) gezielt per SNMP v2c ab.
        // ---------------------------------------------------------------------
        case 'probe_device_snmp':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $devId = isset($input['id']) ? (int)$input['id'] : 0;
            if ($devId <= 0) {
                throw new InvalidArgumentException('Ungültige Geräte-ID.');
            }
            $devStmt = $pdo->prepare("
                SELECT d.*, s.snmp_community AS scope_snmp_community
                FROM devices d
                LEFT JOIN scopes s ON d.scope_id = s.id
                WHERE d.id = :id
            ");
            $devStmt->execute(array(':id' => $devId));
            $devRow = $devStmt->fetch();
            $devStmt->closeCursor();
            if (!$devRow) {
                throw new InvalidArgumentException('Gerät nicht gefunden.');
            }
            $comm = !empty($devRow['switch_snmp_community'])
                ? trim($devRow['switch_snmp_community'])
                : (!empty($devRow['scope_snmp_community']) ? trim($devRow['scope_snmp_community']) : 'public');
            require_once __DIR__ . '/includes/snmp_client.php';
            $client = new SnmpClient($devRow['ip_address'], $comm, 1200);
            $snmpData = $client->probeDevice();
            if ($snmpData === null) {
                throw new RuntimeException('SNMP-Abfrage auf ' . $devRow['ip_address'] . ' (Community "' . $comm . '") lieferte keine Antwort (Gerät evtl. im Energiesparmodus oder SNMP deaktiviert).');
            }
            $pdo->prepare("
                UPDATE devices SET
                    snmp_enabled = 1,
                    snmp_sysname = COALESCE(:sname, snmp_sysname),
                    snmp_sysdescr = COALESCE(:sdescr, snmp_sysdescr),
                    snmp_sysobjectid = COALESCE(:soid, snmp_sysobjectid),
                    snmp_location = COALESCE(:sloc, snmp_location),
                    snmp_ports_json = :sjson,
                    status = 'online',
                    last_seen = :now
                WHERE id = :id
            ")->execute(array(
                ':sname'  => $snmpData['sysName'],
                ':sdescr' => $snmpData['sysDescr'],
                ':soid'   => $snmpData['sysObjectID'],
                ':sloc'   => $snmpData['sysLocation'],
                ':sjson'  => json_encode($snmpData),
                ':now'    => date('Y-m-d H:i:s'),
                ':id'     => $devId,
            ));
            echo json_encode(array(
                'ok'        => true,
                'snmp_data' => $snmpData,
            ));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: save_scope
        // Erstellt einen neuen IP-Scope (Subnetz/VLAN) oder aktualisiert einen bestehenden Scope.
        // ---------------------------------------------------------------------
        case 'save_scope':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            $name = isset($input['name']) ? trim($input['name']) : '';
            $cidr = isset($input['cidr']) ? trim($input['cidr']) : '';
            $gatewayIp = isset($input['gateway_ip']) && trim($input['gateway_ip']) !== '' ? trim($input['gateway_ip']) : null;
            $snmpComm = isset($input['snmp_community']) && trim($input['snmp_community']) !== '' ? trim($input['snmp_community']) : 'public';
            $vlanId = isset($input['vlan_id']) && $input['vlan_id'] !== '' ? (int)$input['vlan_id'] : null;
            $color = isset($input['color']) && trim($input['color']) !== '' ? trim($input['color']) : '#3b82f6';
            $desc = isset($input['description']) ? trim($input['description']) : '';
            $isActive = isset($input['is_active']) ? ((int)$input['is_active'] ? 1 : 0) : 1;

            if ($name === '' || $cidr === '') {
                throw new InvalidArgumentException('Bitte Name und Subnetz (CIDR oder IP-Bereich) angeben.');
            }

            // Validiere CIDR/Range
            $scanner = new NetworkScanner();
            $ips = $scanner->expandCidrOrRange($cidr, 512);
            if ($gatewayIp !== null && preg_match('/^\.?([0-9]{1,3})$/', $gatewayIp, $gwm) && !empty($ips)) {
                $p = explode('.', $ips[0]);
                if (count($p) === 4) {
                    $gatewayIp = $p[0] . '.' . $p[1] . '.' . $p[2] . '.' . (int)$gwm[1];
                }
            }
            if (($gatewayIp === null || !filter_var($gatewayIp, FILTER_VALIDATE_IP)) && !empty($ips)) {
                $gatewayIp = $ips[0];
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE scopes SET
                        name = :name, cidr = :cidr, gateway_ip = :gw, snmp_community = :comm,
                        vlan_id = :vlan, color = :color, description = :desc, is_active = :act
                    WHERE id = :id
                ");
                $stmt->execute(array(
                    ':name'  => $name,
                    ':cidr'  => $cidr,
                    ':gw'    => $gatewayIp,
                    ':comm'  => $snmpComm,
                    ':vlan'  => $vlanId,
                    ':color' => $color,
                    ':desc'  => $desc,
                    ':act'   => $isActive,
                    ':id'    => $id,
                ));
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO scopes (name, cidr, gateway_ip, snmp_community, snmp_version, description, vlan_id, color, is_active, created_at)
                    VALUES (:name, :cidr, :gw, :comm, '2c', :desc, :vlan, :color, :act, :now)
                ");
                $stmt->execute(array(
                    ':name'  => $name,
                    ':cidr'  => $cidr,
                    ':gw'    => $gatewayIp,
                    ':comm'  => $snmpComm,
                    ':desc'  => $desc,
                    ':vlan'  => $vlanId,
                    ':color' => $color,
                    ':act'   => $isActive,
                    ':now'   => date('Y-m-d H:i:s'),
                ));
                $id = (int)$pdo->lastInsertId();
            }

            echo json_encode(array('ok' => true, 'scope_id' => $id));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: delete_scope
        // Löscht einen IP-Scope samt allen zugehörigen Geräten, Links und Scan-Protokollen.
        // ---------------------------------------------------------------------
        case 'delete_scope':
            Auth::requireRole(array('netadmin', 'admin'));
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            if ($id <= 0) {
                throw new InvalidArgumentException('Ungültige Scope-ID.');
            }
            $pdo->prepare("DELETE FROM switch_port_outlets WHERE switch_id IN (SELECT id FROM devices WHERE scope_id = :id)")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM device_alerts WHERE scope_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM links WHERE scope_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM devices WHERE scope_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM scan_logs WHERE scope_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM scopes WHERE id = :id")->execute(array(':id' => $id));
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: save_device
        // Speichert manuelle Geräte-Eigenschaften (Name, Gruppe, Typ, Core-Flag, Uplink, Alarm-Modus, Notizen).
        // ---------------------------------------------------------------------
        case 'save_device':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            if ($id <= 0) {
                throw new InvalidArgumentException('Ungültige Geräte-ID.');
            }
            $chkStmt = $pdo->prepare("SELECT * FROM devices WHERE id = :id");
            $chkStmt->execute(array(':id' => $id));
            $existingDev = $chkStmt->fetch();
            if (!$existingDev) {
                throw new InvalidArgumentException('Gerät nicht gefunden.');
            }
            if (!empty($existingDev['is_core']) && !$isNetExpert) {
                http_response_code(403);
                throw new RuntimeException('Zugriff verweigert: Core-Switches dürfen nur von Netzwerkexperten (netadmin) oder Administratoren bearbeitet werden.');
            }

            $customName = isset($input['custom_name']) ? trim($input['custom_name']) : null;
            $customGroup = isset($input['custom_group']) ? trim($input['custom_group']) : null;
            $deviceType = isset($input['device_type']) ? trim($input['device_type']) : 'workstation';
            $alertMode = isset($input['alert_mode']) && in_array($input['alert_mode'], array('none', 'offline', 'online', 'both'), true)
                ? $input['alert_mode']
                : 'none';
            $notes = isset($input['notes']) ? trim($input['notes']) : null;
            $parentDeviceId = isset($input['parent_device_id']) ? (int)$input['parent_device_id'] : 0;
            $parentPort = isset($input['parent_port']) ? trim($input['parent_port']) : null;
            $isCore = $isNetExpert && isset($input['is_core'])
                ? ((int)$input['is_core'] ? 1 : 0)
                : (!empty($existingDev['is_core']) ? 1 : 0);

            $stmt = $pdo->prepare("
                UPDATE devices
                SET custom_name = :cname, custom_group = :cgroup, device_type = :dtype,
                    alert_mode = :amode, last_alert_state = status, notes = :notes, is_core = :iscore, is_manual_type = 1
                WHERE id = :id
            ");
            $stmt->execute(array(
                ':cname'  => $customName !== '' ? $customName : null,
                ':cgroup' => $customGroup !== '' ? $customGroup : null,
                ':dtype'  => $deviceType,
                ':amode'  => $alertMode,
                ':notes'  => $notes,
                ':iscore' => $isCore,
                ':id'     => $id,
            ));

            // Falls eine manuelle Uplink-/Switch-Port-Zuordnung angegeben wurde
            if ($parentDeviceId > 0 && $parentDeviceId !== $id) {
                $scopeId = (int)$existingDev['scope_id'];
                $portLabelToSave = ($parentPort !== null && $parentPort !== '') ? $parentPort : 'Port';
                $pdo->prepare("DELETE FROM links WHERE target_device_id = :tid")->execute(array(':tid' => $id));
                $ins = $pdo->prepare("
                    INSERT INTO links (scope_id, source_device_id, target_device_id, source_port, target_port, link_type, is_manual, updated_at)
                    VALUES (:sid, :src, :dst, :sport, 'eth0', 'manual', 1, :now)
                ");
                $ins->execute(array(
                    ':sid'   => $scopeId,
                    ':src'   => $parentDeviceId,
                    ':dst'   => $id,
                    ':sport' => $portLabelToSave,
                    ':now'   => date('Y-m-d H:i:s'),
                ));

                if (!empty($existingDev['mac_address']) && $portLabelToSave !== 'Port') {
                    $pDevStmt = $pdo->prepare("SELECT id, ip_address, custom_name, hostname, snmp_sysname FROM devices WHERE id = :pid");
                    $pDevStmt->execute(array(':pid' => $parentDeviceId));
                    $pSw = $pDevStmt->fetch();
                    if ($pSw) {
                        $swLbl = !empty($pSw['custom_name']) ? $pSw['custom_name'] : (!empty($pSw['snmp_sysname']) ? $pSw['snmp_sysname'] : (!empty($pSw['hostname']) ? $pSw['hostname'] : $pSw['ip_address']));
                        $dLbl = ($customName !== null && $customName !== '') ? $customName : (!empty($existingDev['hostname']) ? $existingDev['hostname'] : $existingDev['ip_address']);
                        $scanner = new NetworkScanner();
                        $scanner->recordMacPortSighting(array(
                            'mac_address' => strtoupper($existingDev['mac_address']),
                            'ip_address'  => $existingDev['ip_address'],
                            'device_name' => $dLbl,
                            'vendor'      => $existingDev['vendor'],
                            'switch_id'   => (int)$pSw['id'],
                            'switch_name' => $swLbl,
                            'switch_ip'   => $pSw['ip_address'],
                            'port_name'   => $portLabelToSave,
                            'vlan_id'     => 1,
                        ));
                    }
                }
            }

            echo json_encode(array('ok' => true, 'is_core' => $isCore));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: save_switch_credentials
        // Speichert individuelle SSH- und SNMP-Zugangsdaten für einen Managed Switch und führt optional einen Live-Test durch.
        // ---------------------------------------------------------------------
        case 'save_switch_credentials':
            Auth::requireRole(array('netadmin', 'admin'));
            $id = isset($input['device_id']) ? (int)$input['device_id'] : 0;
            if ($id <= 0) {
                throw new InvalidArgumentException('Ungültige Switch-Geräte-ID.');
            }
            $devStmt = $pdo->prepare("SELECT * FROM devices WHERE id = :id");
            $devStmt->execute(array(':id' => $id));
            $dev = $devStmt->fetch();
            if (!$dev) {
                throw new InvalidArgumentException('Switch nicht in der Datenbank gefunden.');
            }

            $sshUser = isset($input['switch_ssh_user']) ? trim($input['switch_ssh_user']) : '';
            $sshPass = isset($input['switch_ssh_pass']) ? (string)$input['switch_ssh_pass'] : '';
            $enablePassInput = isset($input['switch_enable_pass']) ? (string)$input['switch_enable_pass'] : '';
            $snmpComm = isset($input['switch_snmp_community']) ? trim($input['switch_snmp_community']) : '';
            $snmpWrite = isset($input['switch_snmp_write_community']) ? trim($input['switch_snmp_write_community']) : '';
            $cliProfOverride = isset($input['cli_profile']) ? strtolower(trim($input['cli_profile'])) : 'auto';
            $isCoreVal = isset($input['is_core']) ? ((int)$input['is_core'] ? 1 : 0) : (!empty($dev['is_core']) ? 1 : 0);

            // Bestehendes SSH-Passwort & optionales Enable-Secret auspacken
            $prevCombinedPass = isset($dev['switch_ssh_pass']) ? (string)$dev['switch_ssh_pass'] : '';
            $prevSshPass = $prevCombinedPass;
            $prevEnablePass = '';
            if (strpos($prevCombinedPass, "\n__EN__:") !== false) {
                $partsP = explode("\n__EN__:", $prevCombinedPass, 2);
                $prevSshPass = $partsP[0];
                $prevEnablePass = $partsP[1];
            }

            $cleanSshPass = ($sshPass !== '') ? $sshPass : $prevSshPass;
            $cleanEnablePass = ($enablePassInput !== '') ? $enablePassInput : $prevEnablePass;
            $finalSshPass = $cleanSshPass;
            if ($cleanSshPass !== '' && $cleanEnablePass !== '') {
                $finalSshPass = $cleanSshPass . "\n__EN__:" . $cleanEnablePass;
            } elseif ($cleanSshPass === '') {
                $finalSshPass = null;
            }

            $prevRawWrite = isset($dev['switch_snmp_write_community']) ? (string)$dev['switch_snmp_write_community'] : '';
            if (preg_match('/^profile:[a-z0-9_]+\|(.*)$/i', $prevRawWrite, $pwm)) {
                $prevRawWrite = $pwm[1];
            }
            $cleanWriteComm = ($snmpWrite !== '') ? $snmpWrite : $prevRawWrite;
            if ($cliProfOverride !== '' && $cliProfOverride !== 'auto') {
                $finalSnmpWrite = 'profile:' . $cliProfOverride . '|' . $cleanWriteComm;
            } else {
                $finalSnmpWrite = ($cleanWriteComm !== '') ? $cleanWriteComm : null;
            }

            $upd = $pdo->prepare("
                UPDATE devices
                SET switch_ssh_user = :u,
                    switch_ssh_pass = :p,
                    switch_snmp_community = :sc,
                    switch_snmp_write_community = :sw,
                    is_core = :iscore
                WHERE id = :id
            ");
            $upd->execute(array(
                ':u'      => $sshUser !== '' ? $sshUser : null,
                ':p'      => $finalSshPass,
                ':sc'     => $snmpComm !== '' ? $snmpComm : null,
                ':sw'     => $finalSnmpWrite,
                ':iscore' => $isCoreVal,
                ':id'     => $id,
            ));

            $dev['switch_snmp_write_community'] = $finalSnmpWrite;
            $profInfo = SnmpClient::detectSwitchCliProfile($dev);

            $sshTestResult = null;
            if (!empty($input['test_connection']) && $profInfo['profile'] !== 'snmp_set' && $sshUser !== '' && $cleanSshPass !== '') {
                @set_time_limit(45);
                $sshTestResult = SnmpClient::configureSwitchViaSsh($dev['ip_address'], $sshUser, $cleanSshPass, array(
                    'action_type' => 'test',
                    'cli_profile' => $profInfo['profile'],
                    'enable_pass' => $cleanEnablePass !== '' ? $cleanEnablePass : $cleanSshPass,
                ));
            }

            // Aktualisiere SNMP-Daten des Switches direkt mit der (ggf. neuen) Community
            $commToUse = $snmpComm !== '' ? $snmpComm : 'public';
            $snmpClient = new SnmpClient($dev['ip_address'], $commToUse, 1500);
            $freshSnmp = $snmpClient->probeDevice();
            if ($freshSnmp !== null) {
                $pdo->prepare("UPDATE devices SET snmp_enabled = 1, snmp_ports_json = :j WHERE id = :id")
                    ->execute(array(':j' => json_encode($freshSnmp), ':id' => $id));
            }

            echo json_encode(array(
                'ok'                    => true,
                'is_core'               => $isCoreVal,
                'has_ssh_pass'          => ($cleanSshPass !== '') ? 1 : 0,
                'has_enable_pass'       => ($cleanEnablePass !== '') ? 1 : 0,
                'has_snmp_write'        => ($cleanWriteComm !== '') ? 1 : 0,
                'cli_profile'           => $profInfo['profile'],
                'cli_profile_label'     => $profInfo['label'],
                'cli_profile_is_manual' => !empty($profInfo['is_manual']) ? 1 : 0,
                'ssh_test'              => $sshTestResult,
                'snmp_data'             => $freshSnmp,
            ));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: configure_switch_port
        // Konfiguriert einen Switch-Port (Status, Alias, VLAN/PVID, Modus, PoE, Speed) live per SSH-CLI oder SNMP-SET.
        // ---------------------------------------------------------------------
        case 'configure_switch_port':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            @set_time_limit(60);
            $id = isset($input['device_id']) ? (int)$input['device_id'] : 0;
            if ($id <= 0) {
                throw new InvalidArgumentException('Ungültige Switch-Geräte-ID.');
            }
            $devStmt = $pdo->prepare("SELECT d.*, s.snmp_community AS scope_snmp_comm FROM devices d LEFT JOIN scopes s ON d.scope_id = s.id WHERE d.id = :id");
            $devStmt->execute(array(':id' => $id));
            $dev = $devStmt->fetch();
            if (!$dev) {
                throw new InvalidArgumentException('Switch nicht gefunden.');
            }

            if (!empty($dev['is_core']) && !$isNetExpert) {
                http_response_code(403);
                throw new RuntimeException('Zugriff verweigert: Dieser Core-Switch darf ausschließlich von Netzwerkexperten (netadmin) oder Administratoren konfiguriert werden!');
            }

            $reqPortMode = isset($input['port_mode']) ? trim($input['port_mode']) : 'access';
            $reqCreateVlanId = isset($input['create_vlan_id']) ? (int)$input['create_vlan_id'] : 0;
            if (!$isNetExpert && ($reqPortMode === 'trunk' || $reqCreateVlanId > 0)) {
                http_response_code(403);
                throw new RuntimeException('Zugriff verweigert: Das Konfigurieren von 802.1Q Trunk-Ports sowie das Anlegen neuer VLANs ist Netzwerkexperten (netadmin) und Administratoren vorbehalten.');
            }

            $profInfo = SnmpClient::detectSwitchCliProfile($dev);
            $activeProfile = !empty($input['cli_profile']) && $input['cli_profile'] !== 'auto'
                ? strtolower(trim($input['cli_profile']))
                : $profInfo['profile'];

            $rawWriteComm = isset($dev['switch_snmp_write_community']) ? (string)$dev['switch_snmp_write_community'] : '';
            if (preg_match('/^profile:[a-z0-9_]+\|(.*)$/i', $rawWriteComm, $rwm)) {
                $rawWriteComm = $rwm[1];
            }

            $storedCombinedPass = isset($dev['switch_ssh_pass']) ? (string)$dev['switch_ssh_pass'] : '';
            $storedSshPass = $storedCombinedPass;
            $storedEnablePass = '';
            if (strpos($storedCombinedPass, "\n__EN__:") !== false) {
                $partsP = explode("\n__EN__:", $storedCombinedPass, 2);
                $storedSshPass = $partsP[0];
                $storedEnablePass = $partsP[1];
            }

            $sshUser = !empty($input['switch_ssh_user']) ? trim($input['switch_ssh_user']) : (!empty($dev['switch_ssh_user']) ? $dev['switch_ssh_user'] : '');
            $sshPass = !empty($input['switch_ssh_pass']) ? (string)$input['switch_ssh_pass'] : $storedSshPass;
            $enablePass = !empty($input['switch_enable_pass']) ? (string)$input['switch_enable_pass'] : ($storedEnablePass !== '' ? $storedEnablePass : $sshPass);

            $portName = isset($input['port_name']) ? trim($input['port_name']) : '';
            if ($portName === '') {
                throw new InvalidArgumentException('Bitte einen Switch-Port auswählen.');
            }

            $params = array(
                'action_type'         => 'configure_port',
                'cli_profile'         => $activeProfile,
                'enable_pass'         => $enablePass,
                'port_name'           => $portName,
                'port_mode'           => $reqPortMode,
                'trunk_allowed_vlans' => isset($input['trunk_allowed_vlans']) ? trim($input['trunk_allowed_vlans']) : 'all',
                'vlan_id'             => isset($input['vlan_id']) ? (int)$input['vlan_id'] : 0,
                'create_vlan_id'      => $reqCreateVlanId,
                'create_vlan_name'    => isset($input['create_vlan_name']) ? trim($input['create_vlan_name']) : '',
                'admin_status'        => isset($input['admin_status']) ? trim($input['admin_status']) : '',
                'save_config'         => isset($input['save_config']) ? (bool)$input['save_config'] : true,
            );
            if (array_key_exists('alias', $input)) {
                $params['alias'] = trim((string)$input['alias']);
            }

            // Falls SNMP-SET Modus gewählt wurde oder kein SSH-Passwort vorhanden ist, aber eine SNMP-Write-Community existiert
            if ($activeProfile === 'snmp_set' || (($sshUser === '' || $sshPass === '') && $rawWriteComm !== '')) {
                $curSnmpData = !empty($dev['snmp_ports_json']) ? (@json_decode($dev['snmp_ports_json'], true) ?: array()) : array();
                $execRes = SnmpClient::configureSwitchPortViaSnmpSet($dev['ip_address'], $rawWriteComm !== '' ? $rawWriteComm : 'private', $params, $curSnmpData);
            } else {
                if ($sshUser === '' || $sshPass === '') {
                    throw new InvalidArgumentException('Für diesen Switch sind noch keine SSH-Zugangsdaten hinterlegt. Bitte durch einen Netzwerkexperten (netadmin) über "🔑 Zugangsdaten" hinterlegen lassen.');
                }
                $execRes = SnmpClient::configureSwitchViaSsh($dev['ip_address'], $sshUser, $sshPass, $params);
            }

            if (empty($execRes['success'])) {
                throw new RuntimeException(isset($execRes['message']) ? $execRes['message'] : 'Switch-Konfiguration fehlgeschlagen.');
            }

            // Sofortiger Live-Re-Probe per SNMP, damit VLANs, PVID, Alias und Port-Status in der Oberfläche aktualisiert sind
            $commToUse = !empty($dev['switch_snmp_community'])
                ? $dev['switch_snmp_community']
                : (!empty($dev['scope_snmp_comm']) ? $dev['scope_snmp_comm'] : 'public');
            $snmpClient = new SnmpClient($dev['ip_address'], $commToUse, 1500);
            $freshSnmp = $snmpClient->probeDevice();
            if ($freshSnmp !== null) {
                $pdo->prepare("UPDATE devices SET snmp_enabled = 1, snmp_ports_json = :j WHERE id = :id")
                    ->execute(array(':j' => json_encode($freshSnmp), ':id' => $id));
            }

            echo json_encode(array(
                'ok'          => true,
                'message'     => $execRes['message'],
                'cli_profile' => $activeProfile,
                'commands'    => isset($execRes['commands']) ? $execRes['commands'] : array(),
                'snmp_data'   => $freshSnmp,
            ));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: delete_device
        // Entfernt ein einzelnes Gerät sowie dessen Topologie-Verknüpfungen aus der Datenbank.
        // ---------------------------------------------------------------------
        case 'delete_device':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            $chkStmt = $pdo->prepare("SELECT is_core FROM devices WHERE id = :id");
            $chkStmt->execute(array(':id' => $id));
            $devCore = (int)$chkStmt->fetchColumn();
            if ($devCore === 1 && !$isNetExpert) {
                http_response_code(403);
                throw new RuntimeException('Zugriff verweigert: Core-Switches dürfen nur von Netzwerkexperten oder Administratoren gelöscht werden.');
            }
            $pdo->prepare("DELETE FROM switch_port_outlets WHERE switch_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM device_alerts WHERE device_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM links WHERE source_device_id = :id OR target_device_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM devices WHERE id = :id")->execute(array(':id' => $id));
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: toggle_device_visibility
        // Blendet ein einzelnes Gerät auf der Topologie-Karte ein oder aus ('is_hidden').
        // ---------------------------------------------------------------------
        case 'toggle_device_visibility':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            $isHidden = isset($input['is_hidden']) ? ((int)$input['is_hidden'] ? 1 : 0) : 0;
            if ($id <= 0) {
                throw new InvalidArgumentException('Ungültige Geräte-ID.');
            }
            $chkStmt = $pdo->prepare("SELECT is_core FROM devices WHERE id = :id");
            $chkStmt->execute(array(':id' => $id));
            if ((int)$chkStmt->fetchColumn() === 1 && !$isNetExpert) {
                http_response_code(403);
                throw new RuntimeException('Zugriff verweigert: Core-Switches dürfen nur von Netzwerkexperten verwaltet werden.');
            }
            $stmt = $pdo->prepare("UPDATE devices SET is_hidden = :hid WHERE id = :id");
            $stmt->execute(array(':hid' => $isHidden, ':id' => $id));
            echo json_encode(array('ok' => true, 'id' => $id, 'is_hidden' => $isHidden));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: bulk_device_visibility
        // Aktualisiert die Sichtbarkeit mehrerer Geräte gleichzeitig (Sichtbarkeits-Checkliste).
        // ---------------------------------------------------------------------
        case 'bulk_device_visibility':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $mode = isset($input['mode']) ? $input['mode'] : 'show_all';
            $scopeId = isset($input['scope_id']) && $input['scope_id'] !== 'all' ? (int)$input['scope_id'] : 0;
            $scopeWhere = $scopeId > 0 ? " WHERE scope_id = " . $scopeId : "";

            if ($mode === 'show_all') {
                $pdo->exec("UPDATE devices SET is_hidden = 0" . $scopeWhere);
            } elseif ($mode === 'infra_only') {
                $wherePrefix = $scopeId > 0 ? " WHERE scope_id = $scopeId AND " : " WHERE ";
                $pdo->exec("UPDATE devices SET is_hidden = 0" . $scopeWhere);
                $pdo->exec("UPDATE devices SET is_hidden = 1" . $wherePrefix . "device_type IN ('workstation', 'printer')");
            } elseif ($mode === 'custom_list' && isset($input['hidden_ids']) && is_array($input['hidden_ids'])) {
                $pdo->exec("UPDATE devices SET is_hidden = 0" . $scopeWhere);
                $stmt = $pdo->prepare("UPDATE devices SET is_hidden = 1 WHERE id = :id");
                foreach ($input['hidden_ids'] as $hid) {
                    $stmt->execute(array(':id' => (int)$hid));
                }
            }
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: toggle_group_visibility
        // Blendet alle Geräte einer Gerätekategorie oder benutzerdefinierten Gruppe gesammelt ein oder aus.
        // ---------------------------------------------------------------------
        case 'toggle_group_visibility':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $groupKind = isset($input['group_kind']) ? $input['group_kind'] : 'device_type';
            $groupVal = isset($input['group_val']) ? trim($input['group_val']) : '';
            $isHidden = isset($input['is_hidden']) ? ((int)$input['is_hidden'] ? 1 : 0) : 0;
            $scopeId = isset($input['scope_id']) && $input['scope_id'] !== 'all' ? (int)$input['scope_id'] : 0;

            if ($groupKind === 'custom_group') {
                $sql = "UPDATE devices SET is_hidden = :hid WHERE COALESCE(custom_group, 'Allgemein') = :gval";
            } else {
                $sql = "UPDATE devices SET is_hidden = :hid WHERE device_type = :gval";
            }
            $params = array(':hid' => $isHidden, ':gval' => $groupVal);
            if ($scopeId > 0) {
                $sql .= " AND scope_id = :sid";
                $params[':sid'] = $scopeId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: seed_demo
        // Lädt eine realistische Demo-Netzwerktopologie (inkl. Managed Switch & VLANs) zum Testen.
        // ---------------------------------------------------------------------
        case 'seed_demo':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            seedManagedSwitchDemo($pdo);
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: change_own_password
        // Ermöglicht dem aktuell angemeldeten Benutzer das Ändern von eigenem Passwort, E-Mail-Adresse, Alarm-Abo und 2FA.
        // ---------------------------------------------------------------------
        case 'change_own_password':
            $newPass = isset($input['new_password']) ? $input['new_password'] : '';
            $hasProfileFields = isset($input['email']) || isset($input['receive_alerts']) || isset($input['two_fa_mode']);
            if (!$hasProfileFields && strlen($newPass) < 4) {
                throw new InvalidArgumentException('Das neue Passwort muss mindestens 4 Zeichen lang sein.');
            }
            if ($newPass !== '' && strlen($newPass) < 4) {
                throw new InvalidArgumentException('Das neue Passwort muss mindestens 4 Zeichen lang sein.');
            }

            $email = isset($input['email']) ? trim($input['email']) : (isset($currentUser['email']) ? (string)$currentUser['email'] : '');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse eingeben.');
            }
            $recAlerts = isset($input['receive_alerts']) ? ((int)$input['receive_alerts'] ? 1 : 0) : (int)$currentUser['receive_alerts'];
            $twoFaMode = isset($input['two_fa_mode']) && in_array($input['two_fa_mode'], array('none', 'email', 'totp'), true)
                ? $input['two_fa_mode']
                : (isset($currentUser['two_fa_mode']) ? $currentUser['two_fa_mode'] : 'none');
            $totpSecret = isset($input['totp_secret']) ? strtoupper(preg_replace('/[^A-Z2-7]/i', '', $input['totp_secret'])) : (isset($currentUser['totp_secret']) ? (string)$currentUser['totp_secret'] : '');

            if ($twoFaMode === 'email' && $email === '') {
                throw new InvalidArgumentException('Für 2FA per E-Mail-Code muss eine gültige E-Mail-Adresse hinterlegt sein.');
            }
            if ($twoFaMode === 'totp' && $totpSecret === '') {
                $totpSecret = Auth::generateTotpSecret();
            }

            if ($newPass !== '') {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password_hash = :h, email = :em, receive_alerts = :ra, two_fa_mode = :tf, totp_secret = :ts WHERE id = :id")
                    ->execute(array(
                        ':h'  => $hash,
                        ':em' => $email !== '' ? $email : null,
                        ':ra' => $recAlerts,
                        ':tf' => $twoFaMode,
                        ':ts' => $totpSecret !== '' ? $totpSecret : null,
                        ':id' => $currentUser['id'],
                    ));
            } else {
                $pdo->prepare("UPDATE users SET email = :em, receive_alerts = :ra, two_fa_mode = :tf, totp_secret = :ts WHERE id = :id")
                    ->execute(array(
                        ':em' => $email !== '' ? $email : null,
                        ':ra' => $recAlerts,
                        ':tf' => $twoFaMode,
                        ':ts' => $totpSecret !== '' ? $totpSecret : null,
                        ':id' => $currentUser['id'],
                    ));
            }
            echo json_encode(array(
                'ok'           => true,
                'totp_secret'  => $totpSecret,
                'current_user' => Auth::getCurrentUser(),
            ));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: generate_totp_secret
        // Generiert ein neues zufälliges Base32-TOTP-Secret für Authenticator-Apps (RFC 6238).
        // ---------------------------------------------------------------------
        case 'generate_totp_secret':
            echo json_encode(array(
                'ok'          => true,
                'totp_secret' => Auth::generateTotpSecret(),
            ));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: test_db
        // Prüft im Admin-Bereich eine MySQL/MariaDB-Datenbankverbindung, ohne sie sofort zu aktivieren.
        // ---------------------------------------------------------------------
        case 'test_db':
            Auth::requireRole(array('admin'));
            $info = Database::testMysqlConnection($input);
            echo json_encode(array('ok' => true, 'info' => $info));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: save_db_config
        // Speichert Datenbank-Treiber (MySQL/SQLite) sowie Scanner-Performance-Parameter in data/custom_config.json.
        // ---------------------------------------------------------------------
        case 'save_db_config':
            Auth::requireRole(array('admin'));
            $dbSettings = array(
                'driver'   => isset($input['driver']) ? trim($input['driver']) : 'auto',
                'host'     => isset($input['host']) ? trim($input['host']) : '127.0.0.1',
                'port'     => isset($input['port']) ? (int)$input['port'] : 3306,
                'dbname'   => isset($input['dbname']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $input['dbname']) : 'network_monitor',
                'username' => isset($input['username']) ? trim($input['username']) : 'root',
            );
            if (isset($input['password']) && $input['password'] !== '********') {
                $dbSettings['password'] = $input['password'];
            }
            $scannerSettings = array();
            if (isset($input['max_hosts_per_scan'])) {
                $scannerSettings['max_hosts_per_scan'] = max(16, (int)$input['max_hosts_per_scan']);
            }
            if (isset($input['ping_batch_size'])) {
                $scannerSettings['ping_batch_size'] = max(8, (int)$input['ping_batch_size']);
            }
            if (isset($input['port_host_batch_size'])) {
                $scannerSettings['port_host_batch_size'] = max(4, (int)$input['port_host_batch_size']);
            }
            if (isset($input['snmp_batch_size'])) {
                $scannerSettings['snmp_batch_size'] = max(10, (int)$input['snmp_batch_size']);
            }
            if (isset($input['default_snmp_community'])) {
                $scannerSettings['default_snmp_community'] = trim($input['default_snmp_community']);
            }
            if (isset($input['retention_days'])) {
                $scannerSettings['retention_days'] = max(0, (int)$input['retention_days']);
            }
            if (isset($input['stale_offline_days'])) {
                $scannerSettings['stale_offline_days'] = max(0, (int)$input['stale_offline_days']);
            }
            if (isset($input['cron_interval_minutes'])) {
                $scannerSettings['cron_interval_minutes'] = max(0, (int)$input['cron_interval_minutes']);
            }

            Database::saveCustomConfig($dbSettings, $scannerSettings);
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: admin_save_cron_retention
        // Speichert Aufbewahrungsfristen (Retention) und richtet auf Wunsch den Linux-Hintergrund-Cronjob ein.
        // ---------------------------------------------------------------------
        case 'admin_save_cron_retention':
            Auth::requireRole(array('admin'));
            $cronMin = isset($input['cron_interval_minutes']) ? max(0, (int)$input['cron_interval_minutes']) : 15;
            $retDays = isset($input['retention_days']) ? max(0, (int)$input['retention_days']) : 14;
            $staleDays = isset($input['stale_offline_days']) ? max(0, (int)$input['stale_offline_days']) : 30;

            Database::saveCustomConfig(array(), array(
                'cron_interval_minutes' => $cronMin > 0 ? $cronMin : 15,
                'retention_days'        => $retDays,
                'stale_offline_days'    => $staleDays,
            ));

            // Aktualisiere System-Crontab des Webserver-/CLI-Benutzers
            $phpBin = trim((string)@shell_exec('which php 2>/dev/null'));
            if ($phpBin === '') {
                $phpBin = '/usr/bin/php';
            }
            $cliScanReal = realpath(__DIR__ . '/cli_scan.php');
            $cronLogPath = __DIR__ . '/data/cron_scan.log';

            $existingCrontab = (string)@shell_exec('crontab -l 2>/dev/null');
            $lines = preg_split('/\r?\n/', $existingCrontab);
            $filteredLines = array();
            foreach ($lines as $ln) {
                $trimLn = trim($ln);
                if ($trimLn === '' || strpos($trimLn, 'NetworkMonitor-AutoScan') !== false || strpos($trimLn, 'cli_scan.php') !== false) {
                    continue;
                }
                $filteredLines[] = $ln;
            }

            $cronInstalled = false;
            $newCmdLine = '';
            if ($cronMin > 0) {
                $expr = ($cronMin === 60) ? '0 * * * *' : ('*/' . $cronMin . ' * * * *');
                $newCmdLine = $expr . ' ' . $phpBin . ' ' . $cliScanReal . ' --all >> ' . $cronLogPath . ' 2>&1 # NetworkMonitor-AutoScan';
                $filteredLines[] = $newCmdLine;
            }

            $tmpCronFile = sys_get_temp_dir() . '/nm_crontab_' . getmypid() . '.txt';
            $newCrontabContent = empty($filteredLines) ? "\n" : (implode("\n", $filteredLines) . "\n");
            if (@file_put_contents($tmpCronFile, $newCrontabContent) !== false) {
                if (empty($filteredLines)) {
                    @shell_exec('crontab -r 2>/dev/null');
                    $cronInstalled = false;
                } else {
                    @shell_exec('crontab ' . escapeshellarg($tmpCronFile) . ' 2>/dev/null');
                    $verifyCron = (string)@shell_exec('crontab -l 2>/dev/null');
                    $cronInstalled = ($cronMin > 0 && strpos($verifyCron, 'cli_scan.php') !== false);
                }
                @unlink($tmpCronFile);
            }

            // Führe direkt auch die Aufbewahrungs-Bereinigung mit den neuen Werten aus
            $scanner = new NetworkScanner();
            $cleanupStats = $scanner->cleanupOldScans($retDays, $staleDays);

            echo json_encode(array(
                'ok'             => true,
                'cron_installed' => $cronInstalled ? 1 : 0,
                'cron_interval'  => $cronMin,
                'cron_cmd'       => $newCmdLine,
                'cleanup'        => $cleanupStats,
            ));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: admin_run_retention_cleanup
        // Führt die Datenbank-Bereinigung alter Scan-Protokolle, Alarme und Offline-Leichen sofort manuell aus.
        // ---------------------------------------------------------------------
        case 'admin_run_retention_cleanup':
            Auth::requireRole(array('admin'));
            $retDays = isset($input['retention_days']) ? max(0, (int)$input['retention_days']) : null;
            $staleDays = isset($input['stale_offline_days']) ? max(0, (int)$input['stale_offline_days']) : null;
            $scanner = new NetworkScanner();
            $stats = $scanner->cleanupOldScans($retDays, $staleDays);
            echo json_encode(array('ok' => true, 'cleanup' => $stats));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: admin_save_anomaly_config
        // Speichert die Schwellenwerte für den automatischen Netzwerk-Anomalie-Wächter (ARP-Spoofing, Loops, Port-Fehler).
        // ---------------------------------------------------------------------
        case 'admin_save_anomaly_config':
            Auth::requireRole(array('admin'));
            $anomalySettings = array(
                'anomaly_arp_spoofing'           => !empty($input['anomaly_arp_spoofing']) ? 1 : 0,
                'anomaly_new_mac'                => !empty($input['anomaly_new_mac']) ? 1 : 0,
                'anomaly_rogue_switch_max_macs'  => isset($input['anomaly_rogue_switch_max_macs']) ? max(0, (int)$input['anomaly_rogue_switch_max_macs']) : 3,
                'anomaly_port_error_threshold'   => isset($input['anomaly_port_error_threshold']) ? max(0, (int)$input['anomaly_port_error_threshold']) : 50,
                'anomaly_mac_flapping_threshold' => isset($input['anomaly_mac_flapping_threshold']) ? max(0, (int)$input['anomaly_mac_flapping_threshold']) : 3,
            );
            Database::saveCustomConfig(array(), $anomalySettings);
            echo json_encode(array('ok' => true, 'anomaly_config' => $anomalySettings));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: admin_check_update
        // Prüft über Git bzw. die GitHub-API, ob auf GitHub (origin/main) eine neuere Version verfügbar ist.
        // ---------------------------------------------------------------------
        case 'admin_check_update':
            Auth::requireRole(array('admin'));
            $rootDir = __DIR__;
            $localVersion = Database::getAppVersion();
            $localCommit = Database::getGitCommitShort();
            $remoteVersion = '';
            $remoteCommit = '';
            $commitsBehind = 0;
            $changelog = array();

            if (is_dir($rootDir . '/.git')) {
                @shell_exec('git -C ' . escapeshellarg($rootDir) . ' fetch origin main --quiet 2>&1');
                $remoteCommit = trim((string)@shell_exec('git -C ' . escapeshellarg($rootDir) . ' rev-parse --short origin/main 2>/dev/null'));
                $remoteVersion = trim((string)@shell_exec('git -C ' . escapeshellarg($rootDir) . ' show origin/main:VERSION 2>/dev/null'));
                $behindStr = trim((string)@shell_exec('git -C ' . escapeshellarg($rootDir) . ' rev-list --count HEAD..origin/main 2>/dev/null'));
                if ($behindStr !== '' && is_numeric($behindStr)) {
                    $commitsBehind = (int)$behindStr;
                }
                if ($commitsBehind > 0) {
                    $rawLog = trim((string)@shell_exec('git -C ' . escapeshellarg($rootDir) . ' log HEAD..origin/main --oneline -n 15 2>/dev/null'));
                    if ($rawLog !== '') {
                        $changelog = preg_split('/\r?\n/', $rawLog);
                    }
                }
            }

            if ($remoteVersion === '') {
                $ctx = stream_context_create(array(
                    'http' => array('timeout' => 5, 'user_agent' => 'NetworkMonitor-Updater/' . $localVersion),
                ));
                $rawVer = @file_get_contents('https://raw.githubusercontent.com/uWiiiDev/NetworkMonitor/main/VERSION', false, $ctx);
                if ($rawVer !== false && trim($rawVer) !== '') {
                    $remoteVersion = trim($rawVer);
                } else {
                    $remoteVersion = $localVersion;
                }
            }

            if ($remoteCommit === '') {
                $ctxApi = stream_context_create(array(
                    'http' => array('timeout' => 5, 'user_agent' => 'NetworkMonitor-Updater/' . $localVersion),
                ));
                $apiJson = @file_get_contents('https://api.github.com/repos/uWiiiDev/NetworkMonitor/commits/main', false, $ctxApi);
                if ($apiJson !== false) {
                    $apiArr = @json_decode($apiJson, true);
                    if (!empty($apiArr['sha'])) {
                        $remoteCommit = substr($apiArr['sha'], 0, 7);
                    }
                }
            }

            $updateAvailable = ($commitsBehind > 0)
                || ($remoteVersion !== '' && version_compare($remoteVersion, $localVersion, '>'))
                || ($localCommit !== '' && $remoteCommit !== '' && $localCommit !== $remoteCommit && $commitsBehind > 0);

            echo json_encode(array(
                'ok'               => true,
                'local_version'    => $localVersion,
                'local_commit'     => $localCommit,
                'remote_version'   => $remoteVersion !== '' ? $remoteVersion : $localVersion,
                'remote_commit'    => $remoteCommit,
                'commits_behind'   => $commitsBehind,
                'update_available' => $updateAvailable ? 1 : 0,
                'changelog'        => $changelog,
                'checked_at'       => date('d.m.Y H:i:s'),
            ));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: admin_run_update
        // Führt ein Live-Update der Anwendung direkt von GitHub durch (via 'git pull' oder GitHub-Tarball-Fallback).
        // ---------------------------------------------------------------------
        case 'admin_run_update':
            Auth::requireRole(array('admin'));
            @set_time_limit(180);
            $rootDir = __DIR__;
            $customConfPath = $rootDir . '/data/custom_config.json';
            $backupCustomConf = is_readable($customConfPath) ? @file_get_contents($customConfPath) : null;

            $outputLines = array();
            if (is_dir($rootDir . '/.git')) {
                $fetchOut = trim((string)@shell_exec('git -C ' . escapeshellarg($rootDir) . ' fetch origin main 2>&1'));
                if ($fetchOut !== '') {
                    $outputLines[] = $fetchOut;
                }
                $pullOut = trim((string)@shell_exec('git -C ' . escapeshellarg($rootDir) . ' pull --ff-only origin main 2>&1'));
                if (stripos($pullOut, 'fatal:') !== false || stripos($pullOut, 'error:') !== false) {
                    $resetOut = trim((string)@shell_exec('git -C ' . escapeshellarg($rootDir) . ' reset --hard origin/main 2>&1'));
                    $outputLines[] = $resetOut;
                } else {
                    $outputLines[] = $pullOut !== '' ? $pullOut : 'Already up to date.';
                }
            } else {
                // Fallback für Installationen ohne .git Verzeichnis (Tarball-Update direkt von GitHub)
                $tmpTar = sys_get_temp_dir() . '/nm_update_' . getmypid() . '.tar.gz';
                $tmpExtract = sys_get_temp_dir() . '/nm_extract_' . getmypid();
                $ctxDl = stream_context_create(array(
                    'http' => array('timeout' => 25, 'user_agent' => 'NetworkMonitor-Updater'),
                ));
                $tarBytes = @file_get_contents('https://codeload.github.com/uWiiiDev/NetworkMonitor/tar.gz/refs/heads/main', false, $ctxDl);
                if ($tarBytes === false || strlen($tarBytes) < 1000) {
                    throw new RuntimeException('Konnte Update-Archiv nicht von GitHub herunterladen.');
                }
                @file_put_contents($tmpTar, $tarBytes);
                @mkdir($tmpExtract, 0755, true);
                @shell_exec('tar -xzf ' . escapeshellarg($tmpTar) . ' -C ' . escapeshellarg($tmpExtract) . ' --strip-components=1 2>&1');
                // Wichtig für .deb-Paket (/opt/network-monitor/data Symlink) und bestehende data/-Ordner:
                // data/-Verzeichnis aus dem Update-Archiv vor dem Kopieren entfernen, damit Datenbank & Symlink unberührt bleiben!
                @shell_exec('rm -rf ' . escapeshellarg($tmpExtract . '/data'));
                @shell_exec('cp -rf ' . escapeshellarg($tmpExtract) . '/* ' . escapeshellarg($rootDir) . '/ 2>&1');
                if (is_file($tmpExtract . '/.htaccess')) {
                    @copy($tmpExtract . '/.htaccess', $rootDir . '/.htaccess');
                }
                @shell_exec('rm -rf ' . escapeshellarg($tmpTar) . ' ' . escapeshellarg($tmpExtract));
                $outputLines[] = 'Update-Archiv von GitHub (main) erfolgreich heruntergeladen und entpackt.';
            }

            if ($backupCustomConf !== null && !file_exists($customConfPath)) {
                @file_put_contents($customConfPath, $backupCustomConf);
            }

            // Falls als systemd-Dienst (.deb Paket) installiert: PHP_CLI_SERVER_WORKERS=6 und Sicherheits-Router (router.php) nachrüsten
            $systemdUnit = '/lib/systemd/system/network-monitor.service';
            if (is_file($systemdUnit) && is_writable($systemdUnit)) {
                $unitContent = (string)@file_get_contents($systemdUnit);
                $unitChanged = false;
                if ($unitContent !== '' && strpos($unitContent, 'PHP_CLI_SERVER_WORKERS') === false) {
                    $unitContent = str_replace(
                        "Type=simple\n",
                        "Type=simple\nEnvironment=\"PHP_CLI_SERVER_WORKERS=6\"\n",
                        $unitContent
                    );
                    $unitChanged = true;
                }
                if ($unitContent !== '' && strpos($unitContent, 'router.php') === false && is_file('/opt/network-monitor/router.php')) {
                    $unitContent = preg_replace(
                        '#^ExecStart=.*$#m',
                        'ExecStart=/bin/sh -c \'exec /usr/bin/php -d expose_php=0 -d variables_order=EGPCS -d session.use_strict_mode=1 -d session.cookie_httponly=1 -d session.cookie_samesite=Lax -S ${BIND_ADDR:-0.0.0.0}:${PORT:-8085} -t /opt/network-monitor /opt/network-monitor/router.php\'',
                        $unitContent
                    );
                    $unitChanged = true;
                }
                if ($unitChanged) {
                    @file_put_contents($systemdUnit, $unitContent);
                    @shell_exec('systemctl daemon-reload >/dev/null 2>&1');
                    $outputLines[] = 'Systemd-Unit network-monitor.service (Multi-Worker + Security-Router) aktualisiert.';
                }
            }

            $newVersion = Database::getAppVersion();
            $newCommit = Database::getGitCommitShort();

            echo json_encode(array(
                'ok'          => true,
                'new_version' => $newVersion,
                'new_commit'  => $newCommit,
                'output'      => implode("\n", $outputLines),
                'updated_at'  => date('d.m.Y H:i:s'),
            ));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: save_user
        // Legt ein neues Benutzerkonto an oder aktualisiert Rolle, Passwort, E-Mail und 2FA-Einstellungen.
        // ---------------------------------------------------------------------
        case 'save_user':
            Auth::requireRole(array('admin'));
            $uid = isset($input['id']) ? (int)$input['id'] : 0;
            $uname = isset($input['username']) ? trim($input['username']) : '';
            $fname = isset($input['full_name']) ? trim($input['full_name']) : '';
            $email = isset($input['email']) ? trim($input['email']) : '';
            $recAlerts = !empty($input['receive_alerts']) ? 1 : 0;
            $twoFaMode = isset($input['two_fa_mode']) && in_array($input['two_fa_mode'], array('none', 'email', 'totp'), true) ? $input['two_fa_mode'] : 'none';
            $totpSecret = isset($input['totp_secret']) ? strtoupper(preg_replace('/[^A-Z2-7]/i', '', $input['totp_secret'])) : '';
            $role = isset($input['role']) ? trim($input['role']) : 'viewer';
            $pass = isset($input['password']) ? $input['password'] : '';
            $active = isset($input['is_active']) ? ((int)$input['is_active'] ? 1 : 0) : 1;

            if ($uname === '' || !in_array($role, array('viewer', 'operator', 'netadmin', 'admin'), true)) {
                throw new InvalidArgumentException('Bitte gültigen Benutzernamen und Rolle angeben.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse eingeben.');
            }
            if ($twoFaMode === 'email' && $email === '') {
                throw new InvalidArgumentException('Für 2FA per E-Mail-Code muss eine E-Mail-Adresse beim Benutzer hinterlegt sein.');
            }
            if ($twoFaMode === 'totp' && $totpSecret === '') {
                $totpSecret = Auth::generateTotpSecret();
            }
            if ($uid === (int)$currentUser['id'] && ($active === 0 || $role !== 'admin')) {
                throw new InvalidArgumentException('Du kannst dein eigenes Admin-Konto nicht deaktivieren oder herabstufen.');
            }

            if ($uid > 0) {
                if ($pass !== '') {
                    $stmt = $pdo->prepare("UPDATE users SET username = :u, full_name = :n, email = :em, receive_alerts = :ra, two_fa_mode = :tf, totp_secret = :ts, role = :r, password_hash = :p, is_active = :a WHERE id = :id");
                    $stmt->execute(array(
                        ':u'  => $uname,
                        ':n'  => $fname,
                        ':em' => $email !== '' ? $email : null,
                        ':ra' => $recAlerts,
                        ':tf' => $twoFaMode,
                        ':ts' => $totpSecret !== '' ? $totpSecret : null,
                        ':r'  => $role,
                        ':p'  => password_hash($pass, PASSWORD_DEFAULT),
                        ':a'  => $active,
                        ':id' => $uid,
                    ));
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = :u, full_name = :n, email = :em, receive_alerts = :ra, two_fa_mode = :tf, totp_secret = :ts, role = :r, is_active = :a WHERE id = :id");
                    $stmt->execute(array(
                        ':u'  => $uname,
                        ':n'  => $fname,
                        ':em' => $email !== '' ? $email : null,
                        ':ra' => $recAlerts,
                        ':tf' => $twoFaMode,
                        ':ts' => $totpSecret !== '' ? $totpSecret : null,
                        ':r'  => $role,
                        ':a'  => $active,
                        ':id' => $uid,
                    ));
                }
            } else {
                if ($pass === '') {
                    throw new InvalidArgumentException('Bitte ein Passwort für den neuen Benutzer vergeben.');
                }
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, email, receive_alerts, two_fa_mode, totp_secret, role, is_active, created_at) VALUES (:u, :p, :n, :em, :ra, :tf, :ts, :r, :a, :now)");
                $stmt->execute(array(
                    ':u'   => $uname,
                    ':p'   => password_hash($pass, PASSWORD_DEFAULT),
                    ':n'   => $fname,
                    ':em'  => $email !== '' ? $email : null,
                    ':ra'  => $recAlerts,
                    ':tf'  => $twoFaMode,
                    ':ts'  => $totpSecret !== '' ? $totpSecret : null,
                    ':r'   => $role,
                    ':a'   => $active,
                    ':now' => date('Y-m-d H:i:s'),
                ));
            }
            echo json_encode(array('ok' => true, 'totp_secret' => $totpSecret));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: delete_user
        // Löscht ein Benutzerkonto (das eigene aktuell angemeldete Konto ist vor Selbstlöschung geschützt).
        // ---------------------------------------------------------------------
        case 'delete_user':
            Auth::requireRole(array('admin'));
            $uid = isset($input['id']) ? (int)$input['id'] : 0;
            if ($uid === (int)$currentUser['id']) {
                throw new InvalidArgumentException('Du kannst dein eigenes angemeldetes Konto nicht löschen.');
            }
            $pdo->prepare("DELETE FROM users WHERE id = :id")->execute(array(':id' => $uid));
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: admin_clear_scope_scans
        // Leert die gefundenen Geräte und Scan-Daten eines einzelnen Scopes oder aller Scopes (Scopes selbst bleiben erhalten).
        // ---------------------------------------------------------------------
        case 'admin_clear_scope_scans':
            Auth::requireRole(array('admin'));
            $scopeId = isset($input['scope_id']) ? $input['scope_id'] : 0;
            if ($scopeId === 'all') {
                $pdo->exec("DELETE FROM switch_port_outlets");
                $pdo->exec("DELETE FROM mac_port_history");
                $pdo->exec("DELETE FROM device_alerts");
                $pdo->exec("DELETE FROM links");
                $pdo->exec("DELETE FROM devices");
                $pdo->exec("DELETE FROM scan_logs");
                $pdo->exec("UPDATE scopes SET last_scan_at = NULL, last_scan_status = 'never', last_scan_hosts = 0");
            } else {
                $sid = (int)$scopeId;
                if ($sid <= 0) {
                    throw new InvalidArgumentException('Ungültige Scope-ID.');
                }
                $pdo->prepare("DELETE FROM switch_port_outlets WHERE switch_id IN (SELECT id FROM devices WHERE scope_id = :id)")->execute(array(':id' => $sid));
                $pdo->prepare("DELETE FROM device_alerts WHERE scope_id = :id")->execute(array(':id' => $sid));
                $pdo->prepare("DELETE FROM links WHERE scope_id = :id")->execute(array(':id' => $sid));
                $pdo->prepare("DELETE FROM devices WHERE scope_id = :id")->execute(array(':id' => $sid));
                $pdo->prepare("DELETE FROM scan_logs WHERE scope_id = :id")->execute(array(':id' => $sid));
                $pdo->prepare("UPDATE scopes SET last_scan_at = NULL, last_scan_status = 'never', last_scan_hosts = 0 WHERE id = :id")
                    ->execute(array(':id' => $sid));
            }
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: admin_delete_all_scopes
        // Setzt sämtliche Scopes, Geräte, Links, Alarme und Historien-Einträge vollständig zurück.
        // ---------------------------------------------------------------------
        case 'admin_delete_all_scopes':
            Auth::requireRole(array('admin'));
            $pdo->exec("DELETE FROM switch_port_outlets");
            $pdo->exec("DELETE FROM mac_port_history");
            $pdo->exec("DELETE FROM device_alerts");
            $pdo->exec("DELETE FROM links");
            $pdo->exec("DELETE FROM devices");
            $pdo->exec("DELETE FROM scan_logs");
            $pdo->exec("DELETE FROM scopes");
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: admin_clear_scan_logs
        // Löscht die gesamte Scan-Protokoll-Historie ('scan_logs').
        // ---------------------------------------------------------------------
        case 'admin_clear_scan_logs':
            Auth::requireRole(array('admin'));
            $pdo->exec("DELETE FROM scan_logs");
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: save_mac_watchlist
        // Fügt eine gesuchte MAC-Adresse zur Überwachungs-/Fahndungsliste hinzu oder aktualisiert sie.
        // ---------------------------------------------------------------------
        case 'save_mac_watchlist':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $rawMac = isset($input['mac_address']) ? trim($input['mac_address']) : '';
            $normMac = OuiLookup::normalizeMac($rawMac);
            if (!$normMac) {
                throw new InvalidArgumentException('Bitte eine gültige 12-stellige MAC-Adresse eingeben (z.B. AA:BB:CC:DD:EE:FF oder aabb.ccdd.eeff).');
            }
            $label = isset($input['label']) ? trim($input['label']) : '';
            $mode = (isset($input['notify_mode']) && $input['notify_mode'] === 'once') ? 'once' : 'always';
            $now = date('Y-m-d H:i:s');

            $chkW = $pdo->prepare("SELECT id FROM mac_watchlist WHERE mac_address = :mac");
            $chkW->execute(array(':mac' => $normMac));
            $exW = $chkW->fetch();
            if ($exW) {
                $updW = $pdo->prepare("UPDATE mac_watchlist SET label = :lbl, notify_mode = :mode, is_active = 1 WHERE id = :id");
                $updW->execute(array(':lbl' => $label, ':mode' => $mode, ':id' => (int)$exW['id']));
            } else {
                $insW = $pdo->prepare("INSERT INTO mac_watchlist (mac_address, label, notify_mode, is_active, created_at) VALUES (:mac, :lbl, :mode, 1, :now)");
                $insW->execute(array(':mac' => $normMac, ':lbl' => $label, ':mode' => $mode, ':now' => $now));
            }
            echo json_encode(array('ok' => true, 'mac_address' => $normMac));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: delete_mac_watchlist
        // Entfernt einen Eintrag von der MAC-Überwachungsliste.
        // ---------------------------------------------------------------------
        case 'delete_mac_watchlist':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $wid = isset($input['id']) ? (int)$input['id'] : 0;
            if ($wid <= 0) {
                throw new InvalidArgumentException('Ungültige Watchlist-ID.');
            }
            $pdo->prepare("DELETE FROM mac_watchlist WHERE id = :id")->execute(array(':id' => $wid));
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: save_port_outlet
        // Speichert die Zuordnung eines einzelnen Switch-Ports zu einer Netzwerkdose / Patchfeld / Raum.
        // ---------------------------------------------------------------------
        case 'save_port_outlet':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $switchId = isset($input['switch_id']) ? (int)$input['switch_id'] : 0;
            $portName = isset($input['port_name']) ? trim($input['port_name']) : '';
            $outletLabel = isset($input['outlet_label']) ? trim($input['outlet_label']) : '';
            $patchPanel = isset($input['patch_panel']) ? trim($input['patch_panel']) : '';
            $room = isset($input['room']) ? trim($input['room']) : '';
            $notes = isset($input['notes']) ? trim($input['notes']) : '';
            $now = date('Y-m-d H:i:s');

            if ($switchId <= 0 || $portName === '') {
                throw new InvalidArgumentException('Bitte einen Switch und einen Port angeben.');
            }

            $chkSw = $pdo->prepare("SELECT id, is_core FROM devices WHERE id = :id");
            $chkSw->execute(array(':id' => $switchId));
            $swRow = $chkSw->fetch();
            if (!$swRow) {
                throw new InvalidArgumentException('Switch nicht gefunden.');
            }
            if (!empty($swRow['is_core']) && !$isNetExpert) {
                http_response_code(403);
                throw new RuntimeException('Zugriff verweigert: Core-Switches dürfen nur von Netzwerkexperten oder Administratoren bearbeitet werden.');
            }

            if ($outletLabel === '' && $patchPanel === '' && $room === '' && $notes === '') {
                $pdo->prepare("DELETE FROM switch_port_outlets WHERE switch_id = :sid AND port_name = :pname")
                    ->execute(array(':sid' => $switchId, ':pname' => $portName));
            } else {
                $chkOut = $pdo->prepare("SELECT id FROM switch_port_outlets WHERE switch_id = :sid AND port_name = :pname");
                $chkOut->execute(array(':sid' => $switchId, ':pname' => $portName));
                $exOut = $chkOut->fetch();
                if ($exOut) {
                    $updOut = $pdo->prepare("
                        UPDATE switch_port_outlets
                        SET outlet_label = :ol, patch_panel = :pp, room = :rm, notes = :nt, updated_at = :now
                        WHERE id = :id
                    ");
                    $updOut->execute(array(
                        ':ol'  => $outletLabel !== '' ? $outletLabel : null,
                        ':pp'  => $patchPanel !== '' ? $patchPanel : null,
                        ':rm'  => $room !== '' ? $room : null,
                        ':nt'  => $notes !== '' ? $notes : null,
                        ':now' => $now,
                        ':id'  => (int)$exOut['id'],
                    ));
                } else {
                    $insOut = $pdo->prepare("
                        INSERT INTO switch_port_outlets (switch_id, port_name, outlet_label, patch_panel, room, notes, updated_at)
                        VALUES (:sid, :pname, :ol, :pp, :rm, :nt, :now)
                    ");
                    $insOut->execute(array(
                        ':sid'   => $switchId,
                        ':pname' => $portName,
                        ':ol'    => $outletLabel !== '' ? $outletLabel : null,
                        ':pp'    => $patchPanel !== '' ? $patchPanel : null,
                        ':rm'    => $room !== '' ? $room : null,
                        ':nt'    => $notes !== '' ? $notes : null,
                        ':now'   => $now,
                    ));
                }
            }

            $allOutlets = $pdo->query("SELECT * FROM switch_port_outlets ORDER BY switch_id ASC, id ASC")->fetchAll();
            echo json_encode(array(
                'ok'           => true,
                'port_outlets' => $allOutlets,
            ));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: save_port_outlets_bulk
        // Speichert mehrere Switch-Port-zu-Netzwerkdosen-Zuordnungen (inkl. optionaler Port-Beschreibungen) in einem Rutsch.
        // ---------------------------------------------------------------------
        case 'save_port_outlets_bulk':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $switchId = isset($input['switch_id']) ? (int)$input['switch_id'] : 0;
            $entries = isset($input['entries']) && is_array($input['entries']) ? $input['entries'] : array();
            $now = date('Y-m-d H:i:s');

            if ($switchId <= 0) {
                throw new InvalidArgumentException('Ungültige Switch-Geräte-ID.');
            }
            $chkSw = $pdo->prepare("SELECT id, is_core FROM devices WHERE id = :id");
            $chkSw->execute(array(':id' => $switchId));
            $swRow = $chkSw->fetch();
            if (!$swRow) {
                throw new InvalidArgumentException('Switch nicht gefunden.');
            }
            if (!empty($swRow['is_core']) && !$isNetExpert) {
                http_response_code(403);
                throw new RuntimeException('Zugriff verweigert: Core-Switches dürfen nur von Netzwerkexperten oder Administratoren bearbeitet werden.');
            }

            $savedCount = 0;
            foreach ($entries as $entry) {
                $pName = isset($entry['port_name']) ? trim($entry['port_name']) : '';
                if ($pName === '') {
                    continue;
                }
                $oLbl = isset($entry['outlet_label']) ? trim($entry['outlet_label']) : '';
                $pPan = isset($entry['patch_panel']) ? trim($entry['patch_panel']) : '';
                $rm = isset($entry['room']) ? trim($entry['room']) : '';
                $nt = isset($entry['notes']) ? trim($entry['notes']) : '';

                if ($oLbl === '' && $pPan === '' && $rm === '' && $nt === '') {
                    $pdo->prepare("DELETE FROM switch_port_outlets WHERE switch_id = :sid AND port_name = :pname")
                        ->execute(array(':sid' => $switchId, ':pname' => $pName));
                } else {
                    $chkOut = $pdo->prepare("SELECT id FROM switch_port_outlets WHERE switch_id = :sid AND port_name = :pname");
                    $chkOut->execute(array(':sid' => $switchId, ':pname' => $pName));
                    $exOut = $chkOut->fetch();
                    if ($exOut) {
                        $pdo->prepare("
                            UPDATE switch_port_outlets
                            SET outlet_label = :ol, patch_panel = :pp, room = :rm, notes = :nt, updated_at = :now
                            WHERE id = :id
                        ")->execute(array(
                            ':ol'  => $oLbl !== '' ? $oLbl : null,
                            ':pp'  => $pPan !== '' ? $pPan : null,
                            ':rm'  => $rm !== '' ? $rm : null,
                            ':nt'  => $nt !== '' ? $nt : null,
                            ':now' => $now,
                            ':id'  => (int)$exOut['id'],
                        ));
                    } else {
                        $pdo->prepare("
                            INSERT INTO switch_port_outlets (switch_id, port_name, outlet_label, patch_panel, room, notes, updated_at)
                            VALUES (:sid, :pname, :ol, :pp, :rm, :nt, :now)
                        ")->execute(array(
                            ':sid'   => $switchId,
                            ':pname' => $pName,
                            ':ol'    => $oLbl !== '' ? $oLbl : null,
                            ':pp'    => $pPan !== '' ? $pPan : null,
                            ':rm'    => $rm !== '' ? $rm : null,
                            ':nt'    => $nt !== '' ? $nt : null,
                            ':now'   => $now,
                        ));
                    }
                    $savedCount++;
                }
            }

            $allOutlets = $pdo->query("SELECT * FROM switch_port_outlets ORDER BY switch_id ASC, id ASC")->fetchAll();
            echo json_encode(array(
                'ok'           => true,
                'saved_count'  => $savedCount,
                'port_outlets' => $allOutlets,
            ));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: save_smtp_config
        // Speichert die SMTP-Postausgangsserver-Konfiguration für Alarm- und 2FA-E-Mails.
        // ---------------------------------------------------------------------
        case 'save_smtp_config':
            Auth::requireRole(array('admin'));
            $smtpSettings = array(
                'enabled'    => !empty($input['enabled']),
                'host'       => isset($input['host']) ? trim($input['host']) : '',
                'port'       => isset($input['port']) ? (int)$input['port'] : 587,
                'encryption' => isset($input['encryption']) ? strtolower(trim($input['encryption'])) : 'tls',
                'username'   => isset($input['username']) ? trim($input['username']) : '',
                'from_email' => isset($input['from_email']) ? trim($input['from_email']) : '',
                'from_name'  => isset($input['from_name']) ? trim($input['from_name']) : 'NetworkMonitor Alarm',
                'to_email'   => isset($input['to_email']) ? trim($input['to_email']) : '',
            );
            if (isset($input['password']) && $input['password'] !== '********') {
                $smtpSettings['password'] = $input['password'];
            }
            Database::saveCustomConfig(array(), array(), $smtpSettings);
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: test_smtp
        // Sendet eine Test-E-Mail über die angegebenen SMTP-Serverdaten zur Funktionsprüfung.
        // ---------------------------------------------------------------------
        case 'test_smtp':
            Auth::requireRole(array('admin'));
            $storedSmtp = Database::getInstance()->getConfig('smtp', array());
            $testConf = array(
                'enabled'    => true,
                'host'       => isset($input['host']) ? trim($input['host']) : '',
                'port'       => isset($input['port']) ? (int)$input['port'] : 587,
                'encryption' => isset($input['encryption']) ? strtolower(trim($input['encryption'])) : 'tls',
                'username'   => isset($input['username']) ? trim($input['username']) : '',
                'password'   => (isset($input['password']) && $input['password'] !== '********')
                                ? $input['password']
                                : (isset($storedSmtp['password']) ? $storedSmtp['password'] : ''),
                'from_email' => isset($input['from_email']) ? trim($input['from_email']) : '',
                'from_name'  => isset($input['from_name']) && trim($input['from_name']) !== '' ? trim($input['from_name']) : 'NetworkMonitor Alarm',
                'to_email'   => isset($input['to_email']) ? trim($input['to_email']) : '',
            );

            $mailer = new SmtpMailer($testConf);
            $subject = '[NetworkMonitor] SMTP Test-E-Mail erfolgreich';
            $now = date('d.m.Y H:i:s');
            $body = '<div style="font-family:sans-serif;max-width:580px;margin:0 auto;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">'
                  . '<div style="background:#3b82f6;color:#ffffff;padding:14px 20px;font-size:16px;font-weight:bold;">✉ NetworkMonitor — SMTP Test-Benachrichtigung</div>'
                  . '<div style="padding:20px;background:#ffffff;color:#1e293b;line-height:1.5;">'
                  . '<p style="margin-top:0;">Der SMTP-Versand aus deinem <strong>NetworkMonitor</strong> funktioniert einwandfrei!</p>'
                  . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                  . '<tr><td style="padding:6px 0;color:#64748b;width:150px;">SMTP-Server:</td><td><code>' . htmlspecialchars($testConf['host'] . ':' . $testConf['port'], ENT_QUOTES, 'UTF-8') . '</code> (' . strtoupper(htmlspecialchars($testConf['encryption'], ENT_QUOTES, 'UTF-8')) . ')</td></tr>'
                  . '<tr><td style="padding:6px 0;color:#64748b;">Empfänger:</td><td>' . htmlspecialchars($testConf['to_email'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
                  . '<tr><td style="padding:6px 0;color:#64748b;">Zeitpunkt:</td><td>' . $now . '</td></tr>'
                  . '</table>'
                  . '</div></div>';
            $info = $mailer->send($testConf['to_email'], $subject, $body);
            echo json_encode(array('ok' => true, 'info' => $info));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: ack_alerts
        // Quittiert einen einzelnen Alarm, eine Auswahl von Alarmen ('ids') oder alle offenen Alarme
        // inklusive optionalem Techniker-Kommentar, Benutzername ('ack_by') und Zeitstempel ('ack_at').
        // ---------------------------------------------------------------------
        case 'ack_alerts':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $alertId = isset($input['id']) ? (int)$input['id'] : 0;
            $alertIds = (isset($input['ids']) && is_array($input['ids'])) ? array_values(array_filter(array_map('intval', $input['ids']))) : array();
            $ackComment = isset($input['comment']) ? trim((string)$input['comment']) : '';
            $ackBy = isset($currentUser['username']) ? $currentUser['username'] : 'operator';
            $now = date('Y-m-d H:i:s');

            // Stelle sicher, dass Quittierungsspalten auch auf älteren DB-Ständen existieren
            $db->ensureColumnExists('device_alerts', 'ack_comment', 'TEXT DEFAULT NULL');
            $db->ensureColumnExists('device_alerts', 'ack_by', 'VARCHAR(64) DEFAULT NULL');
            $db->ensureColumnExists('device_alerts', 'ack_at', 'DATETIME DEFAULT NULL');

            if ($alertId > 0) {
                $pdo->prepare("UPDATE device_alerts SET is_acknowledged = 1, ack_comment = :c, ack_by = :u, ack_at = :now WHERE id = :id")
                    ->execute(array(
                        ':c'   => $ackComment !== '' ? $ackComment : null,
                        ':u'   => $ackBy,
                        ':now' => $now,
                        ':id'  => $alertId,
                    ));
            } elseif (!empty($alertIds)) {
                $updSel = $pdo->prepare("UPDATE device_alerts SET is_acknowledged = 1, ack_comment = COALESCE(:c, ack_comment), ack_by = :u, ack_at = :now WHERE id = :id");
                foreach ($alertIds as $aid) {
                    if ($aid > 0) {
                        $updSel->execute(array(
                            ':c'   => $ackComment !== '' ? $ackComment : null,
                            ':u'   => $ackBy,
                            ':now' => $now,
                            ':id'  => $aid,
                        ));
                    }
                }
            } else {
                $pdo->prepare("UPDATE device_alerts SET is_acknowledged = 1, ack_comment = COALESCE(:c, ack_comment), ack_by = :u, ack_at = :now WHERE is_acknowledged = 0")
                    ->execute(array(
                        ':c'   => $ackComment !== '' ? $ackComment : null,
                        ':u'   => $ackBy,
                        ':now' => $now,
                    ));
            }

            $updatedAlerts = $pdo->query("
                SELECT a.*, s.name AS scope_name
                FROM device_alerts a
                LEFT JOIN scopes s ON a.scope_id = s.id
                ORDER BY a.is_acknowledged ASC, a.id DESC LIMIT 100
            ")->fetchAll();
            $unackStmt = $pdo->query("SELECT COUNT(*) FROM device_alerts WHERE is_acknowledged = 0");
            $unackRemaining = $unackStmt ? (int)$unackStmt->fetchColumn() : 0;
            if ($unackStmt) {
                $unackStmt->closeCursor();
            }

            echo json_encode(array(
                'ok'           => true,
                'ack_by'       => $ackBy,
                'ack_at'       => $now,
                'alerts'       => $updatedAlerts,
                'unack_alerts' => $unackRemaining,
            ));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: clear_alerts
        // Löscht die gesamte Alarm-Historie aus der Tabelle 'device_alerts'.
        // ---------------------------------------------------------------------
        case 'clear_alerts':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $pdo->exec("DELETE FROM device_alerts");
            echo json_encode(array('ok' => true));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: save_layout_positions
        // Speichert die per Drag & Drop auf der Topologie-Karte verschobenen Knoten-Koordinaten (pos_x, pos_y) dauerhaft.
        // ---------------------------------------------------------------------
        case 'save_layout_positions':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            if (!isset($input['positions']) || !is_array($input['positions'])) {
                throw new InvalidArgumentException('Keine Positionsdaten übergeben.');
            }
            $stmt = $pdo->prepare("UPDATE devices SET pos_x = :x, pos_y = :y WHERE id = :id");
            $savedCount = 0;
            foreach ($input['positions'] as $pos) {
                $did = isset($pos['id']) ? (int)$pos['id'] : 0;
                if ($did > 0 && isset($pos['x']) && isset($pos['y'])) {
                    $stmt->execute(array(
                        ':x'  => (int)round($pos['x']),
                        ':y'  => (int)round($pos['y']),
                        ':id' => $did,
                    ));
                    $savedCount++;
                }
            }
            echo json_encode(array('ok' => true, 'saved' => $savedCount));
            break;

                // ---------------------------------------------------------------------
        // ENDPOINT: reset_layout_positions
        // Setzt alle manuell gespeicherten Knoten-Koordinaten zurück, damit das automatische Layout neu berechnet wird.
        // ---------------------------------------------------------------------
        case 'reset_layout_positions':
            Auth::requireRole(array('operator', 'netadmin', 'admin'));
            $scopeId = isset($input['scope_id']) && $input['scope_id'] !== 'all' ? (int)$input['scope_id'] : 0;
            if ($scopeId > 0) {
                $pdo->prepare("UPDATE devices SET pos_x = NULL, pos_y = NULL WHERE scope_id = :sid")
                    ->execute(array(':sid' => $scopeId));
            } else {
                $pdo->exec("UPDATE devices SET pos_x = NULL, pos_y = NULL");
            }
            echo json_encode(array('ok' => true));
            break;

        default:
            throw new InvalidArgumentException('Unbekannte API-Aktion: ' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(array(
        'ok'    => false,
        'error' => $e->getMessage(),
    ));
}

/**
 * Erzeugt ein realistisches Demo-Szenario mit 2 Managed Switches (SNMP Bridge-MIB & LLDP)
 * über 2 VLAN-Scopes, damit die SNMP-Switch-Port-Darstellung sofort getestet werden kann.
 */
function seedManagedSwitchDemo(PDO $pdo)
{
    $now = date('Y-m-d H:i:s');

    // Prüfe ob Demo-Scope schon existiert
    $chk = $pdo->prepare("SELECT id FROM scopes WHERE cidr = '10.20.10.0/24'");
    $chk->execute();
    if ($chk->fetchColumn()) {
        return;
    }

    // Scope A: Server & Core-Netz (VLAN 10)
    $insScope = $pdo->prepare("
        INSERT INTO scopes (name, cidr, gateway_ip, snmp_community, snmp_version, description, vlan_id, color, is_active, last_scan_at, last_scan_status, last_scan_hosts, created_at)
        VALUES (:name, :cidr, :gw, 'public', '2c', :desc, :vlan, :color, 1, :now, 'completed', :cnt, :now)
    ");
    $insScope->execute(array(
        ':name'  => 'Server & Core-Netz (SNMP Demo)',
        ':cidr'  => '10.20.10.0/24',
        ':gw'    => '10.20.10.1',
        ':desc'  => 'Rechenzentrum VLAN 10 mit Cisco Catalyst Core-Switch (SNMP v2c)',
        ':vlan'  => 10,
        ':color' => '#10b981',
        ':now'   => $now,
        ':cnt'   => 6,
    ));
    $scope10 = (int)$pdo->lastInsertId();

    // Scope B: Office & Etagen-Switch (VLAN 20)
    $insScope->execute(array(
        ':name'  => 'Office & Clients (SNMP Demo)',
        ':cidr'  => '10.20.20.0/24',
        ':gw'    => '10.20.20.1',
        ':desc'  => 'Büro-Etage VLAN 20 mit HP Aruba Managed Switch & Access Point',
        ':vlan'  => 20,
        ':color' => '#8b5cf6',
        ':now'   => $now,
        ':cnt'   => 6,
    ));
    $scope20 = (int)$pdo->lastInsertId();

    $insDev = $pdo->prepare("
        INSERT INTO devices (
            scope_id, ip_address, mac_address, vendor, hostname, custom_name, device_type,
            os_guess, open_ports, snmp_enabled, snmp_sysname, snmp_sysdescr, snmp_location,
            snmp_ports_json, status, latency_ms, first_seen, last_seen
        ) VALUES (
            :sid, :ip, :mac, :vendor, :host, :cname, :dtype,
            :os, :ports, :snmp, :sname, :sdescr, :sloc,
            :sjson, 'online', :lat, :now, :now
        )
    ");

    // Geräte in VLAN 10
    $coreSnmp = json_encode(array(
        'sysName'     => 'SW-CORE-01.corp.local',
        'sysDescr'    => 'Cisco IOS Software, C2960X Software (C2960X-UNIVERSALK9-M), Version 15.2(7)E',
        'sysLocation' => 'Serverraum Rack A1',
        'interfaces'  => array(
            array('ifIndex' => 1, 'name' => 'Gi1/0/1', 'status' => 'up'),
            array('ifIndex' => 2, 'name' => 'Gi1/0/2', 'status' => 'up'),
            array('ifIndex' => 3, 'name' => 'Gi1/0/3', 'status' => 'up'),
            array('ifIndex' => 4, 'name' => 'Gi1/0/4', 'status' => 'up'),
            array('ifIndex' => 24, 'name' => 'Te1/0/1 (Trunk)', 'status' => 'up'),
        ),
        'mac_table'   => array(
            '00:50:56:AA:10:11' => array('bridge_port' => 2, 'if_index' => 2, 'port_name' => 'Gi1/0/2'),
            '00:50:56:AA:10:12' => array('bridge_port' => 3, 'if_index' => 3, 'port_name' => 'Gi1/0/3'),
            '00:11:32:BB:10:20' => array('bridge_port' => 4, 'if_index' => 4, 'port_name' => 'Gi1/0/4'),
        ),
        'lldp_neighbors' => array(
            array('local_port' => 'Gi1/0/1', 'remote_name' => 'FW-GATEWAY-01'),
        ),
    ));

    $vlan10Devices = array(
        array($scope10, '10.20.10.1', '00:09:0F:AA:00:01', 'Fortinet (FortiGate)', 'FW-GATEWAY-01', 'Core Firewall / Gateway', 'router', 'FortiOS', '22/SSH, 443/HTTPS, 161/SNMP', 1, 'FW-GATEWAY-01', 'FortiGate-100F v7.2', 'Serverraum Rack A1', null, 0.4),
        array($scope10, '10.20.10.2', '00:25:45:10:00:02', 'Cisco Systems', 'SW-CORE-01.corp.local', 'Cisco Catalyst Core-Switch', 'switch', 'Cisco IOS', '22/SSH, 161/SNMP, 443/HTTPS', 1, 'SW-CORE-01.corp.local', 'Cisco IOS C2960X-24TS-L', 'Serverraum Rack A1', $coreSnmp, 0.6),
        array($scope10, '10.20.10.11', '00:50:56:AA:10:11', 'VMware ESXi / vSphere', 'srv-ubuntu-lamp.corp.local', 'Ubuntu 14.04 LAMP Server', 'server', 'Linux (Ubuntu 14.04)', '22/SSH, 80/HTTP, 443/HTTPS, 3306/MySQL', 0, null, null, null, null, 0.3),
        array($scope10, '10.20.10.12', '00:50:56:AA:10:12', 'VMware ESXi / vSphere', 'srv-dc01.corp.local', 'Active Directory / DNS', 'server', 'Windows Server', '53/DNS, 139/NetBIOS, 445/SMB, 3389/RDP', 0, null, null, null, null, 0.5),
        array($scope10, '10.20.10.20', '00:11:32:BB:10:20', 'Synology NAS', 'nas-backup01.corp.local', 'Synology Backup-NAS', 'server', 'Linux (DSM)', '22/SSH, 80/HTTP, 443/HTTPS, 445/SMB, 161/SNMP', 1, 'nas-backup01', 'Linux nas-backup01 4.4.302+ Synology', 'Serverraum Rack B2', null, 0.8),
    );

    foreach ($vlan10Devices as $d) {
        $insDev->execute(array(
            ':sid' => $d[0], ':ip' => $d[1], ':mac' => $d[2], ':vendor' => $d[3],
            ':host' => $d[4], ':cname' => $d[5], ':dtype' => $d[6], ':os' => $d[7],
            ':ports' => $d[8], ':snmp' => $d[9], ':sname' => $d[10], ':sdescr' => $d[11],
            ':sloc' => $d[12], ':sjson' => $d[13], ':lat' => $d[14], ':now' => $now,
        ));
    }
    $pdo->exec("UPDATE devices SET is_core = 1 WHERE ip_address = '10.20.10.2'");

    // Geräte in VLAN 20 (Managed Switch HP Aruba)
    $officeSnmp = json_encode(array(
        'sysName'     => 'SW-OFFICE-2OG',
        'sysDescr'    => 'HP J9773A 2530-24G-PoE+ Switch, revision YA.16.10',
        'sysLocation' => '2. OG Etagenverteiler',
        'interfaces'  => array(
            array('ifIndex' => 1, 'name' => 'Port 1 (Uplink)', 'status' => 'up'),
            array('ifIndex' => 5, 'name' => 'Port 5 (PoE AP)', 'status' => 'up'),
            array('ifIndex' => 8, 'name' => 'Port 8', 'status' => 'up'),
            array('ifIndex' => 12, 'name' => 'Port 12', 'status' => 'up'),
            array('ifIndex' => 18, 'name' => 'Port 18 (Drucker)', 'status' => 'up'),
        ),
        'mac_table'   => array(
            '78:8A:20:CC:20:05' => array('bridge_port' => 5, 'if_index' => 5, 'port_name' => 'Port 5 (PoE)'),
            '3C:97:0E:CC:20:10' => array('bridge_port' => 8, 'if_index' => 8, 'port_name' => 'Port 8'),
            'F8:BC:12:CC:20:11' => array('bridge_port' => 12, 'if_index' => 12, 'port_name' => 'Port 12'),
            '30:05:5C:CC:20:50' => array('bridge_port' => 18, 'if_index' => 18, 'port_name' => 'Port 18'),
        ),
        'lldp_neighbors' => array(),
    ));

    $vlan20Devices = array(
        array($scope20, '10.20.20.1', '00:09:0F:AA:00:02', 'Fortinet (FortiGate)', 'gw-vlan20.corp.local', 'VLAN 20 Gateway Interface', 'router', 'FortiOS', '443/HTTPS', 0, null, null, null, null, 0.5),
        array($scope20, '10.20.20.2', '2C:41:38:20:00:02', 'HP / Aruba Switch', 'SW-OFFICE-2OG', 'HP Aruba 2530-24G Switch', 'switch', 'HP ArubaOS / ProCurve', '22/SSH, 80/HTTP, 161/SNMP', 1, 'SW-OFFICE-2OG', 'HP J9773A 2530-24G-PoE+ Switch', '2. OG Etagenverteiler', $officeSnmp, 0.9),
        array($scope20, '10.20.20.5', '78:8A:20:CC:20:05', 'Ubiquiti Networks', 'ap-office-2og.corp.local', 'UniFi WLAN Access Point', 'ap', 'Linux (UniFi)', '22/SSH, 161/SNMP', 1, 'UAP-AC-Pro-2OG', 'Ubiquiti UniFi UAP-AC-Pro', '2. OG Flur', null, 1.4),
        array($scope20, '10.20.20.101', '3C:97:0E:CC:20:10', 'Intel Corporate', 'pc-buchhaltung01.corp.local', 'Workstation Buchhaltung', 'workstation', 'Windows', '139/NetBIOS, 445/SMB, 3389/RDP', 0, null, null, null, null, 1.2),
        array($scope20, '10.20.20.102', 'F8:BC:12:CC:20:11', 'Dell Inc.', 'pc-vertrieb02.corp.local', 'Workstation Vertrieb', 'workstation', 'Windows', '139/NetBIOS, 445/SMB', 0, null, null, null, null, 1.5),
        array($scope20, '10.20.20.50', '30:05:5C:CC:20:50', 'Brother Industries', 'prn-etage2.corp.local', 'Brother MFC Netzwerkdrucker', 'printer', 'Embedded Printer OS', '80/HTTP, 515/LPD-Printer, 631/IPP-Printer, 9100/JetDirect-Printer', 0, null, null, null, null, 1.8),
    );

    foreach ($vlan20Devices as $d) {
        $insDev->execute(array(
            ':sid' => $d[0], ':ip' => $d[1], ':mac' => $d[2], ':vendor' => $d[3],
            ':host' => $d[4], ':cname' => $d[5], ':dtype' => $d[6], ':os' => $d[7],
            ':ports' => $d[8], ':snmp' => $d[9], ':sname' => $d[10], ':sdescr' => $d[11],
            ':sloc' => $d[12], ':sjson' => $d[13], ':lat' => $d[14], ':now' => $now,
        ));
    }

    $scanner = new NetworkScanner();
    $scanner->rebuildTopologyLinks($scope10, '10.20.10.1');
    $scanner->rebuildTopologyLinks($scope20, '10.20.20.1');
}
