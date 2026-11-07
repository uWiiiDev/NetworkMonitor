<?php
/**
 * NetworkMonitor - JSON Backend API
 * Kompatibel mit PHP 5.5+ (Ubuntu 14.04) bis PHP 8.x
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/scanner.php';

$db = Database::getInstance();
$pdo = $db->getPdo();
$currentUser = Auth::requireRole(array('viewer', 'operator', 'admin'));

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : 'topology');

$rawBody = file_get_contents('php://input');
$jsonBody = $rawBody ? @json_decode($rawBody, true) : array();
if (!is_array($jsonBody)) {
    $jsonBody = array();
}
$input = array_merge($_GET, $_POST, $jsonBody);

try {
    switch ($action) {
        case 'topology':
            $scopes = $pdo->query("SELECT * FROM scopes ORDER BY id ASC")->fetchAll();
            $devices = $pdo->query("
                SELECT d.*, s.name AS scope_name, s.cidr AS scope_cidr, s.color AS scope_color, s.vlan_id
                FROM devices d
                LEFT JOIN scopes s ON d.scope_id = s.id
                ORDER BY d.scope_id ASC, d.ip_address ASC
            ")->fetchAll();
            foreach ($devices as &$dRow) {
                $profInfo = SnmpClient::detectSwitchCliProfile($dRow);
                $dRow['cli_profile'] = $profInfo['profile'];
                $dRow['cli_profile_label'] = $profInfo['label'];
                $dRow['cli_profile_is_manual'] = !empty($profInfo['is_manual']) ? 1 : 0;
                $rawWrite = isset($dRow['switch_snmp_write_community']) ? (string)$dRow['switch_snmp_write_community'] : '';
                if (preg_match('/^profile:[a-z0-9_]+\|(.*)$/i', $rawWrite, $wm)) {
                    $rawWrite = $wm[1];
                }
                $dRow['has_ssh_pass'] = !empty($dRow['switch_ssh_pass']) ? 1 : 0;
                $dRow['has_snmp_write'] = ($rawWrite !== '') ? 1 : 0;
                unset($dRow['switch_ssh_pass']);
                unset($dRow['switch_snmp_write_community']);
            }
            unset($dRow);
            $links = $pdo->query("SELECT * FROM links ORDER BY id ASC")->fetchAll();
            $logs = $pdo->query("
                SELECT l.*, s.name AS scope_name, s.cidr AS scope_cidr
                FROM scan_logs l
                LEFT JOIN scopes s ON l.scope_id = s.id
                ORDER BY l.id DESC LIMIT 20
            ")->fetchAll();

            $alerts = $pdo->query("
                SELECT a.*, s.name AS scope_name
                FROM device_alerts a
                LEFT JOIN scopes s ON a.scope_id = s.id
                ORDER BY a.id DESC LIMIT 50
            ")->fetchAll();
            $unackCount = (int)$pdo->query("SELECT COUNT(*) FROM device_alerts WHERE is_acknowledged = 0")->fetchColumn();

            $nmapPath = trim((string)@shell_exec('which nmap 2>/dev/null'));
            $snmpExt = extension_loaded('snmp');

            $adminData = null;
            if ($currentUser['role'] === 'admin') {
                $fullConf = $db->getConfig();
                $smtpConf = isset($fullConf['smtp']) ? $fullConf['smtp'] : array();
                $usersList = $pdo->query("SELECT id, username, full_name, role, is_active, last_login, created_at FROM users ORDER BY id ASC")->fetchAll();
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
                        'max_hosts_per_scan'     => $fullConf['scanner']['max_hosts_per_scan'],
                        'ping_batch_size'        => $fullConf['scanner']['ping_batch_size'],
                        'port_host_batch_size'   => $fullConf['scanner']['port_host_batch_size'],
                        'snmp_batch_size'        => $fullConf['scanner']['snmp_batch_size'],
                        'default_snmp_community' => $fullConf['scanner']['default_snmp_community'],
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
                'alerts'       => $alerts,
                'unack_alerts' => $unackCount,
                'admin'        => $adminData,
                'system'       => array(
                    'php_version' => PHP_VERSION,
                    'db_driver'   => strtoupper($db->getActiveDriver()),
                    'nmap_found'  => ($nmapPath !== ''),
                    'nmap_path'   => $nmapPath !== '' ? $nmapPath : 'Nicht installiert (nutzt schnellen PHP Parallel-Ping & ARP)',
                    'snmp_mode'   => $snmpExt ? 'PHP-SNMP Extension + Native UDP SNMPv2c' : 'Integrierter Pure-PHP UDP SNMPv2c Client (aktiv)',
                    'server_time' => date('Y-m-d H:i:s'),
                    'cron_cmd'    => '*/30 * * * * /usr/bin/php ' . realpath(__DIR__ . '/cli_scan.php') . ' --all >> /var/log/network_monitor.log 2>&1',
                ),
            ));
            break;

        case 'scan':
            Auth::requireRole(array('operator', 'admin'));
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

        case 'save_scope':
            Auth::requireRole(array('operator', 'admin'));
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

        case 'delete_scope':
            Auth::requireRole(array('operator', 'admin'));
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            if ($id <= 0) {
                throw new InvalidArgumentException('Ungültige Scope-ID.');
            }
            $pdo->prepare("DELETE FROM device_alerts WHERE scope_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM links WHERE scope_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM devices WHERE scope_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM scan_logs WHERE scope_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM scopes WHERE id = :id")->execute(array(':id' => $id));
            echo json_encode(array('ok' => true));
            break;

        case 'save_device':
            Auth::requireRole(array('operator', 'admin'));
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            if ($id <= 0) {
                throw new InvalidArgumentException('Ungültige Geräte-ID.');
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

            $stmt = $pdo->prepare("
                UPDATE devices
                SET custom_name = :cname, custom_group = :cgroup, device_type = :dtype,
                    alert_mode = :amode, last_alert_state = status, notes = :notes, is_manual_type = 1
                WHERE id = :id
            ");
            $stmt->execute(array(
                ':cname'  => $customName !== '' ? $customName : null,
                ':cgroup' => $customGroup !== '' ? $customGroup : null,
                ':dtype'  => $deviceType,
                ':amode'  => $alertMode,
                ':notes'  => $notes,
                ':id'     => $id,
            ));

            // Falls eine manuelle Uplink-/Switch-Port-Zuordnung angegeben wurde
            if ($parentDeviceId > 0 && $parentDeviceId !== $id) {
                $devRow = $pdo->query("SELECT scope_id FROM devices WHERE id = " . (int)$id)->fetch();
                $scopeId = $devRow ? (int)$devRow['scope_id'] : null;
                $pdo->prepare("DELETE FROM links WHERE target_device_id = :tid")->execute(array(':tid' => $id));
                $ins = $pdo->prepare("
                    INSERT INTO links (scope_id, source_device_id, target_device_id, source_port, target_port, link_type, is_manual, updated_at)
                    VALUES (:sid, :src, :dst, :sport, 'eth0', 'manual', 1, :now)
                ");
                $ins->execute(array(
                    ':sid'   => $scopeId,
                    ':src'   => $parentDeviceId,
                    ':dst'   => $id,
                    ':sport' => $parentPort !== '' ? $parentPort : 'Port',
                    ':now'   => date('Y-m-d H:i:s'),
                ));
            }

            echo json_encode(array('ok' => true));
            break;

        case 'save_switch_credentials':
            Auth::requireRole(array('operator', 'admin'));
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
            $snmpComm = isset($input['switch_snmp_community']) ? trim($input['switch_snmp_community']) : '';
            $snmpWrite = isset($input['switch_snmp_write_community']) ? trim($input['switch_snmp_write_community']) : '';
            $cliProfOverride = isset($input['cli_profile']) ? strtolower(trim($input['cli_profile'])) : 'auto';

            // Bestehendes Passwort / Write-Community beibehalten, falls leer gelassen
            $finalSshPass = ($sshPass !== '') ? $sshPass : (isset($dev['switch_ssh_pass']) ? $dev['switch_ssh_pass'] : null);
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
                    switch_snmp_write_community = :sw
                WHERE id = :id
            ");
            $upd->execute(array(
                ':u'  => $sshUser !== '' ? $sshUser : null,
                ':p'  => $finalSshPass,
                ':sc' => $snmpComm !== '' ? $snmpComm : null,
                ':sw' => $finalSnmpWrite,
                ':id' => $id,
            ));

            $dev['switch_snmp_write_community'] = $finalSnmpWrite;
            $profInfo = SnmpClient::detectSwitchCliProfile($dev);

            $sshTestResult = null;
            if (!empty($input['test_connection']) && $profInfo['profile'] !== 'snmp_set' && $sshUser !== '' && !empty($finalSshPass)) {
                @set_time_limit(45);
                $sshTestResult = SnmpClient::configureSwitchViaSsh($dev['ip_address'], $sshUser, $finalSshPass, array(
                    'action_type' => 'test',
                    'cli_profile' => $profInfo['profile']
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
                'has_ssh_pass'          => !empty($finalSshPass) ? 1 : 0,
                'has_snmp_write'        => ($cleanWriteComm !== '') ? 1 : 0,
                'cli_profile'           => $profInfo['profile'],
                'cli_profile_label'     => $profInfo['label'],
                'cli_profile_is_manual' => !empty($profInfo['is_manual']) ? 1 : 0,
                'ssh_test'              => $sshTestResult,
                'snmp_data'             => $freshSnmp,
            ));
            break;

        case 'configure_switch_port':
            Auth::requireRole(array('operator', 'admin'));
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

            $profInfo = SnmpClient::detectSwitchCliProfile($dev);
            $activeProfile = !empty($input['cli_profile']) && $input['cli_profile'] !== 'auto'
                ? strtolower(trim($input['cli_profile']))
                : $profInfo['profile'];

            $rawWriteComm = isset($dev['switch_snmp_write_community']) ? (string)$dev['switch_snmp_write_community'] : '';
            if (preg_match('/^profile:[a-z0-9_]+\|(.*)$/i', $rawWriteComm, $rwm)) {
                $rawWriteComm = $rwm[1];
            }

            $sshUser = !empty($input['switch_ssh_user']) ? trim($input['switch_ssh_user']) : (!empty($dev['switch_ssh_user']) ? $dev['switch_ssh_user'] : '');
            $sshPass = !empty($input['switch_ssh_pass']) ? (string)$input['switch_ssh_pass'] : (!empty($dev['switch_ssh_pass']) ? $dev['switch_ssh_pass'] : '');

            $portName = isset($input['port_name']) ? trim($input['port_name']) : '';
            if ($portName === '') {
                throw new InvalidArgumentException('Bitte einen Switch-Port auswählen.');
            }

            $params = array(
                'action_type'      => 'configure_port',
                'cli_profile'      => $activeProfile,
                'port_name'        => $portName,
                'vlan_id'          => isset($input['vlan_id']) ? (int)$input['vlan_id'] : 0,
                'create_vlan_id'   => isset($input['create_vlan_id']) ? (int)$input['create_vlan_id'] : 0,
                'create_vlan_name' => isset($input['create_vlan_name']) ? trim($input['create_vlan_name']) : '',
                'admin_status'     => isset($input['admin_status']) ? trim($input['admin_status']) : '',
                'save_config'      => isset($input['save_config']) ? (bool)$input['save_config'] : true,
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
                    throw new InvalidArgumentException('Für diesen Switch sind noch keine SSH-Zugangsdaten hinterlegt. Bitte zuerst über "🔑 Zugangsdaten" speichern.');
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

        case 'delete_device':
            Auth::requireRole(array('operator', 'admin'));
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            $pdo->prepare("DELETE FROM device_alerts WHERE device_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM links WHERE source_device_id = :id OR target_device_id = :id")->execute(array(':id' => $id));
            $pdo->prepare("DELETE FROM devices WHERE id = :id")->execute(array(':id' => $id));
            echo json_encode(array('ok' => true));
            break;

        case 'toggle_device_visibility':
            Auth::requireRole(array('operator', 'admin'));
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            $isHidden = isset($input['is_hidden']) ? ((int)$input['is_hidden'] ? 1 : 0) : 0;
            if ($id <= 0) {
                throw new InvalidArgumentException('Ungültige Geräte-ID.');
            }
            $stmt = $pdo->prepare("UPDATE devices SET is_hidden = :hid WHERE id = :id");
            $stmt->execute(array(':hid' => $isHidden, ':id' => $id));
            echo json_encode(array('ok' => true, 'id' => $id, 'is_hidden' => $isHidden));
            break;

        case 'bulk_device_visibility':
            Auth::requireRole(array('operator', 'admin'));
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

        case 'toggle_group_visibility':
            Auth::requireRole(array('operator', 'admin'));
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

        case 'seed_demo':
            Auth::requireRole(array('operator', 'admin'));
            seedManagedSwitchDemo($pdo);
            echo json_encode(array('ok' => true));
            break;

        case 'change_own_password':
            $newPass = isset($input['new_password']) ? $input['new_password'] : '';
            if (strlen($newPass) < 4) {
                throw new InvalidArgumentException('Das neue Passwort muss mindestens 4 Zeichen lang sein.');
            }
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :id")
                ->execute(array(':h' => $hash, ':id' => $currentUser['id']));
            echo json_encode(array('ok' => true));
            break;

        case 'test_db':
            Auth::requireRole(array('admin'));
            $info = Database::testMysqlConnection($input);
            echo json_encode(array('ok' => true, 'info' => $info));
            break;

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

            Database::saveCustomConfig($dbSettings, $scannerSettings);
            echo json_encode(array('ok' => true));
            break;

        case 'save_user':
            Auth::requireRole(array('admin'));
            $uid = isset($input['id']) ? (int)$input['id'] : 0;
            $uname = isset($input['username']) ? trim($input['username']) : '';
            $fname = isset($input['full_name']) ? trim($input['full_name']) : '';
            $role = isset($input['role']) ? trim($input['role']) : 'viewer';
            $pass = isset($input['password']) ? $input['password'] : '';
            $active = isset($input['is_active']) ? ((int)$input['is_active'] ? 1 : 0) : 1;

            if ($uname === '' || !in_array($role, array('viewer', 'operator', 'admin'), true)) {
                throw new InvalidArgumentException('Bitte gültigen Benutzernamen und Rolle angeben.');
            }
            if ($uid === (int)$currentUser['id'] && ($active === 0 || $role !== 'admin')) {
                throw new InvalidArgumentException('Du kannst dein eigenes Admin-Konto nicht deaktivieren oder herabstufen.');
            }

            if ($uid > 0) {
                if ($pass !== '') {
                    $stmt = $pdo->prepare("UPDATE users SET username = :u, full_name = :n, role = :r, password_hash = :p, is_active = :a WHERE id = :id");
                    $stmt->execute(array(':u' => $uname, ':n' => $fname, ':r' => $role, ':p' => password_hash($pass, PASSWORD_DEFAULT), ':a' => $active, ':id' => $uid));
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = :u, full_name = :n, role = :r, is_active = :a WHERE id = :id");
                    $stmt->execute(array(':u' => $uname, ':n' => $fname, ':r' => $role, ':a' => $active, ':id' => $uid));
                }
            } else {
                if ($pass === '') {
                    throw new InvalidArgumentException('Bitte ein Passwort für den neuen Benutzer vergeben.');
                }
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, is_active, created_at) VALUES (:u, :p, :n, :r, :a, :now)");
                $stmt->execute(array(
                    ':u'   => $uname,
                    ':p'   => password_hash($pass, PASSWORD_DEFAULT),
                    ':n'   => $fname,
                    ':r'   => $role,
                    ':a'   => $active,
                    ':now' => date('Y-m-d H:i:s'),
                ));
            }
            echo json_encode(array('ok' => true));
            break;

        case 'delete_user':
            Auth::requireRole(array('admin'));
            $uid = isset($input['id']) ? (int)$input['id'] : 0;
            if ($uid === (int)$currentUser['id']) {
                throw new InvalidArgumentException('Du kannst dein eigenes angemeldetes Konto nicht löschen.');
            }
            $pdo->prepare("DELETE FROM users WHERE id = :id")->execute(array(':id' => $uid));
            echo json_encode(array('ok' => true));
            break;

        case 'admin_clear_scope_scans':
            Auth::requireRole(array('admin'));
            $scopeId = isset($input['scope_id']) ? $input['scope_id'] : 0;
            if ($scopeId === 'all') {
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
                $pdo->prepare("DELETE FROM device_alerts WHERE scope_id = :id")->execute(array(':id' => $sid));
                $pdo->prepare("DELETE FROM links WHERE scope_id = :id")->execute(array(':id' => $sid));
                $pdo->prepare("DELETE FROM devices WHERE scope_id = :id")->execute(array(':id' => $sid));
                $pdo->prepare("DELETE FROM scan_logs WHERE scope_id = :id")->execute(array(':id' => $sid));
                $pdo->prepare("UPDATE scopes SET last_scan_at = NULL, last_scan_status = 'never', last_scan_hosts = 0 WHERE id = :id")
                    ->execute(array(':id' => $sid));
            }
            echo json_encode(array('ok' => true));
            break;

        case 'admin_delete_all_scopes':
            Auth::requireRole(array('admin'));
            $pdo->exec("DELETE FROM device_alerts");
            $pdo->exec("DELETE FROM links");
            $pdo->exec("DELETE FROM devices");
            $pdo->exec("DELETE FROM scan_logs");
            $pdo->exec("DELETE FROM scopes");
            echo json_encode(array('ok' => true));
            break;

        case 'admin_clear_scan_logs':
            Auth::requireRole(array('admin'));
            $pdo->exec("DELETE FROM scan_logs");
            echo json_encode(array('ok' => true));
            break;

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

        case 'ack_alerts':
            $alertId = isset($input['id']) ? (int)$input['id'] : 0;
            if ($alertId > 0) {
                $pdo->prepare("UPDATE device_alerts SET is_acknowledged = 1 WHERE id = :id")->execute(array(':id' => $alertId));
            } else {
                $pdo->exec("UPDATE device_alerts SET is_acknowledged = 1 WHERE is_acknowledged = 0");
            }
            echo json_encode(array('ok' => true));
            break;

        case 'clear_alerts':
            Auth::requireRole(array('operator', 'admin'));
            $pdo->exec("DELETE FROM device_alerts");
            echo json_encode(array('ok' => true));
            break;

        case 'save_layout_positions':
            Auth::requireRole(array('operator', 'admin'));
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

        case 'reset_layout_positions':
            Auth::requireRole(array('operator', 'admin'));
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
