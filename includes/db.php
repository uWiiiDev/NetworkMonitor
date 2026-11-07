<?php
/**
 * Datenbank-Abstraktionsschicht (PDO für MySQL 5.5+ & SQLite-Fallback)
 * Unterstützt dynamische Konfiguration über den Adminbereich (data/custom_config.json)
 * Kompatibel mit PHP 5.5+ (Ubuntu 14.04) bis PHP 8.x
 */

class Database
{
    private static $instance = null;
    private $pdo;
    private $activeDriver = 'mysql';
    private $config;
    private $connectionError = null;

    private function __construct()
    {
        $baseConfig = require __DIR__ . '/../config.php';
        $customFile = __DIR__ . '/../data/custom_config.json';
        if (is_readable($customFile)) {
            $customData = @json_decode(file_get_contents($customFile), true);
            if (is_array($customData)) {
                if (isset($customData['db']) && is_array($customData['db'])) {
                    $baseConfig['db'] = array_merge($baseConfig['db'], $customData['db']);
                }
                if (isset($customData['scanner']) && is_array($customData['scanner'])) {
                    $baseConfig['scanner'] = array_merge($baseConfig['scanner'], $customData['scanner']);
                }
                if (isset($customData['smtp']) && is_array($customData['smtp'])) {
                    if (!isset($baseConfig['smtp']) || !is_array($baseConfig['smtp'])) {
                        $baseConfig['smtp'] = array();
                    }
                    $baseConfig['smtp'] = array_merge($baseConfig['smtp'], $customData['smtp']);
                }
            }
        }
        $this->config = $baseConfig;
        $dbConf = $this->config['db'];
        $driver = isset($dbConf['driver']) ? $dbConf['driver'] : 'auto';

        if ($driver === 'mysql' || $driver === 'auto') {
            try {
                $dsnNoDb = sprintf(
                    'mysql:host=%s;port=%d;charset=%s',
                    $dbConf['host'],
                    (int)$dbConf['port'],
                    $dbConf['charset']
                );
                $pdoTmp = new PDO($dsnNoDb, $dbConf['username'], $dbConf['password'], array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 2,
                ));
                $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbConf['dbname']);
                $pdoTmp->exec("CREATE DATABASE IF NOT EXISTS `$dbName` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci");
                $pdoTmp->exec("USE `$dbName`");
                $pdoTmp->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $this->pdo = $pdoTmp;
                $this->activeDriver = 'mysql';
                $this->initMysqlSchema();
                $this->seedInitialDataIfEmpty();
                return;
            } catch (Exception $e) {
                $this->connectionError = $e->getMessage();
                if ($driver === 'mysql') {
                    // Falls explizit 'mysql' gewählt wurde, aber fehlschlägt:
                    // Nutze trotzdem temporär SQLite damit der Admin sich noch einloggen und die MySQL-Daten korrigieren kann!
                }
            }
        }

        // SQLite-Verbindung für lokalen Test / Fallback
        $sqlitePath = $dbConf['sqlite_path'];
        $dataDir = dirname($sqlitePath);
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }
        self::protectDataDirectory($dataDir);
        $this->pdo = new PDO('sqlite:' . $sqlitePath, null, null, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA temp_store = MEMORY');
        $this->pdo->exec('PRAGMA cache_size = -8000');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->activeDriver = 'sqlite';
        $this->initSqliteSchema();
        $this->seedInitialDataIfEmpty();
    }

    public static function protectDataDirectory($dataDir)
    {
        if (!is_dir($dataDir)) {
            return;
        }
        $htFile = rtrim($dataDir, '/\\') . '/.htaccess';
        if (!file_exists($htFile)) {
            @file_put_contents($htFile, "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n");
        }
        $idxFile = rtrim($dataDir, '/\\') . '/index.php';
        if (!file_exists($idxFile)) {
            @file_put_contents($idxFile, "<?php\nhttp_response_code(403);\nexit('403 Forbidden');\n");
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo()
    {
        return $this->pdo;
    }

    public function getActiveDriver()
    {
        return $this->activeDriver;
    }

    public function getConnectionError()
    {
        return $this->connectionError;
    }

    public function getConfig($section = null, $default = array())
    {
        if ($section !== null) {
            return isset($this->config[$section]) ? $this->config[$section] : $default;
        }
        return $this->config;
    }

    /**
     * Liest die aktuelle Software-Versionsnummer aus der Datei VERSION im Projektstamm
     */
    public static function getAppVersion()
    {
        $verFile = __DIR__ . '/../VERSION';
        if (is_readable($verFile)) {
            $v = trim((string)@file_get_contents($verFile));
            if ($v !== '') {
                return $v;
            }
        }
        return '1.8.0';
    }

    /**
     * Liefert den kurzen Git-Commit-Hash des lokalen Projektverzeichnisses (falls Git installiert ist)
     */
    public static function getGitCommitShort()
    {
        $rootDir = realpath(__DIR__ . '/..');
        if ($rootDir && is_dir($rootDir . '/.git')) {
            $hash = trim((string)@shell_exec('git -C ' . escapeshellarg($rootDir) . ' rev-parse --short HEAD 2>/dev/null'));
            if ($hash !== '' && preg_match('/^[0-9a-f]{6,12}$/i', $hash)) {
                return $hash;
            }
        }
        return '';
    }

    /**
     * Testet eine MySQL-Datenbankverbindung ohne die aktuelle Konfiguration zu überschreiben
     */
    public static function testMysqlConnection(array $params)
    {
        $host = isset($params['host']) ? trim($params['host']) : '127.0.0.1';
        $port = isset($params['port']) ? (int)$params['port'] : 3306;
        $dbname = isset($params['dbname']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $params['dbname']) : 'network_monitor';
        $user = isset($params['username']) ? trim($params['username']) : 'root';
        $pass = isset($params['password']) ? $params['password'] : '';

        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8', $host, $port);
        $pdo = new PDO($dsn, $user, $pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ));
        $version = $pdo->query("SELECT VERSION()")->fetchColumn();
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci");
        return array(
            'version' => $version,
            'dbname'  => $dbname,
            'host'    => $host . ':' . $port,
        );
    }

    /**
     * Speichert Datenbank-, Scanner- & SMTP-Einstellungen aus dem Adminbereich in data/custom_config.json
     */
    public static function saveCustomConfig(array $dbSettings, array $scannerSettings = array(), array $smtpSettings = array())
    {
        $customFile = __DIR__ . '/../data/custom_config.json';
        $dataDir = dirname($customFile);
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }
        $existing = array('db' => array(), 'scanner' => array(), 'smtp' => array());
        if (is_readable($customFile)) {
            $loaded = @json_decode(file_get_contents($customFile), true);
            if (is_array($loaded)) {
                $existing = array_merge($existing, $loaded);
            }
        }
        if (!empty($dbSettings)) {
            $existing['db'] = array_merge($existing['db'], $dbSettings);
        }
        if (!empty($scannerSettings)) {
            $existing['scanner'] = array_merge($existing['scanner'], $scannerSettings);
        }
        if (!empty($smtpSettings)) {
            $existing['smtp'] = array_merge(isset($existing['smtp']) && is_array($existing['smtp']) ? $existing['smtp'] : array(), $smtpSettings);
        }
        self::protectDataDirectory($dataDir);
        file_put_contents($customFile, json_encode($existing, JSON_PRETTY_PRINT));
        @chmod($customFile, 0600);
    }

    private function initMysqlSchema()
    {
        $sqlFile = __DIR__ . '/../database.sql';
        if (!file_exists($sqlFile)) {
            return;
        }
        $sql = file_get_contents($sqlFile);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt !== '' && strpos($stmt, '--') !== 0) {
                $this->pdo->exec($stmt);
            }
        }
        $this->ensureColumnExists('devices', 'is_hidden', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->ensureColumnExists('devices', 'custom_group', 'VARCHAR(100) DEFAULT NULL');
        $this->ensureColumnExists('devices', 'service_info', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('devices', 'alert_mode', "VARCHAR(20) NOT NULL DEFAULT 'none'");
        $this->ensureColumnExists('devices', 'last_alert_state', 'VARCHAR(20) DEFAULT NULL');
        $this->ensureColumnExists('devices', 'pos_x', 'INT DEFAULT NULL');
        $this->ensureColumnExists('devices', 'pos_y', 'INT DEFAULT NULL');
        $this->ensureColumnExists('devices', 'switch_ssh_user', 'VARCHAR(100) DEFAULT NULL');
        $this->ensureColumnExists('devices', 'switch_ssh_pass', 'VARCHAR(255) DEFAULT NULL');
        $this->ensureColumnExists('devices', 'switch_snmp_community', 'VARCHAR(100) DEFAULT NULL');
        $this->ensureColumnExists('devices', 'switch_snmp_write_community', 'VARCHAR(100) DEFAULT NULL');
        $this->ensureColumnExists('devices', 'is_core', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->ensureColumnExists('users', 'email', 'VARCHAR(190) DEFAULT NULL');
        $this->ensureColumnExists('users', 'receive_alerts', 'TINYINT(1) NOT NULL DEFAULT 1');
        $this->ensureColumnExists('users', 'two_fa_mode', "VARCHAR(20) NOT NULL DEFAULT 'none'");
        $this->ensureColumnExists('users', 'totp_secret', 'VARCHAR(64) DEFAULT NULL');
        $this->ensureColumnExists('users', 'otp_code_hash', 'VARCHAR(255) DEFAULT NULL');
        $this->ensureColumnExists('users', 'otp_expires_at', 'DATETIME DEFAULT NULL');
        $this->ensureColumnExists('device_alerts', 'ack_comment', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('device_alerts', 'ack_by', 'VARCHAR(64) DEFAULT NULL');
        $this->ensureColumnExists('device_alerts', 'ack_at', 'DATETIME DEFAULT NULL');
    }

    private function initSqliteSchema()
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS scopes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                cidr TEXT NOT NULL,
                gateway_ip TEXT DEFAULT NULL,
                snmp_community TEXT DEFAULT 'public',
                snmp_version TEXT DEFAULT '2c',
                description TEXT DEFAULT NULL,
                vlan_id INTEGER DEFAULT NULL,
                color TEXT DEFAULT '#3b82f6',
                is_active INTEGER NOT NULL DEFAULT 1,
                last_scan_at TEXT DEFAULT NULL,
                last_scan_status TEXT DEFAULT 'never',
                last_scan_hosts INTEGER DEFAULT 0,
                created_at TEXT NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope_id INTEGER NOT NULL,
                ip_address TEXT NOT NULL UNIQUE,
                mac_address TEXT DEFAULT NULL,
                vendor TEXT DEFAULT NULL,
                hostname TEXT DEFAULT NULL,
                custom_name TEXT DEFAULT NULL,
                custom_group TEXT DEFAULT NULL,
                device_type TEXT NOT NULL DEFAULT 'workstation',
                os_guess TEXT DEFAULT NULL,
                service_info TEXT DEFAULT NULL,
                open_ports TEXT DEFAULT NULL,
                snmp_enabled INTEGER NOT NULL DEFAULT 0,
                snmp_sysname TEXT DEFAULT NULL,
                snmp_sysdescr TEXT DEFAULT NULL,
                snmp_sysobjectid TEXT DEFAULT NULL,
                snmp_location TEXT DEFAULT NULL,
                snmp_ports_json TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'online',
                latency_ms REAL DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                is_manual_type INTEGER NOT NULL DEFAULT 0,
                is_hidden INTEGER NOT NULL DEFAULT 0,
                alert_mode TEXT NOT NULL DEFAULT 'none',
                last_alert_state TEXT DEFAULT NULL,
                pos_x INTEGER DEFAULT NULL,
                pos_y INTEGER DEFAULT NULL,
                switch_ssh_user TEXT DEFAULT NULL,
                switch_ssh_pass TEXT DEFAULT NULL,
                switch_snmp_community TEXT DEFAULT NULL,
                switch_snmp_write_community TEXT DEFAULT NULL,
                is_core INTEGER NOT NULL DEFAULT 0,
                first_seen TEXT NOT NULL,
                last_seen TEXT NOT NULL
            )
        ");

        $this->ensureColumnExists('devices', 'is_hidden', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumnExists('devices', 'custom_group', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('devices', 'service_info', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('devices', 'alert_mode', "TEXT NOT NULL DEFAULT 'none'");
        $this->ensureColumnExists('devices', 'last_alert_state', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('devices', 'pos_x', 'INTEGER DEFAULT NULL');
        $this->ensureColumnExists('devices', 'pos_y', 'INTEGER DEFAULT NULL');
        $this->ensureColumnExists('devices', 'switch_ssh_user', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('devices', 'switch_ssh_pass', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('devices', 'switch_snmp_community', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('devices', 'switch_snmp_write_community', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('devices', 'is_core', 'INTEGER NOT NULL DEFAULT 0');

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope_id INTEGER DEFAULT NULL,
                source_device_id INTEGER NOT NULL,
                target_device_id INTEGER NOT NULL,
                source_port TEXT DEFAULT NULL,
                target_port TEXT DEFAULT NULL,
                link_type TEXT NOT NULL DEFAULT 'subnet',
                is_manual INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS scan_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope_id INTEGER DEFAULT NULL,
                trigger_type TEXT NOT NULL DEFAULT 'manual',
                started_at TEXT NOT NULL,
                finished_at TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'running',
                hosts_up INTEGER DEFAULT 0,
                switches_found INTEGER DEFAULT 0,
                summary TEXT DEFAULT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                full_name TEXT DEFAULT NULL,
                email TEXT DEFAULT NULL,
                receive_alerts INTEGER NOT NULL DEFAULT 1,
                two_fa_mode TEXT NOT NULL DEFAULT 'none',
                totp_secret TEXT DEFAULT NULL,
                otp_code_hash TEXT DEFAULT NULL,
                otp_expires_at TEXT DEFAULT NULL,
                role TEXT NOT NULL DEFAULT 'viewer',
                is_active INTEGER NOT NULL DEFAULT 1,
                last_login TEXT DEFAULT NULL,
                created_at TEXT NOT NULL
            )
        ");

        $this->ensureColumnExists('users', 'email', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('users', 'receive_alerts', 'INTEGER NOT NULL DEFAULT 1');
        $this->ensureColumnExists('users', 'two_fa_mode', "TEXT NOT NULL DEFAULT 'none'");
        $this->ensureColumnExists('users', 'totp_secret', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('users', 'otp_code_hash', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('users', 'otp_expires_at', 'TEXT DEFAULT NULL');

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS device_alerts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL,
                scope_id INTEGER DEFAULT NULL,
                ip_address TEXT NOT NULL,
                device_name TEXT DEFAULT NULL,
                alert_type TEXT NOT NULL,
                severity TEXT NOT NULL DEFAULT 'critical',
                message TEXT NOT NULL,
                email_sent INTEGER NOT NULL DEFAULT 0,
                is_acknowledged INTEGER NOT NULL DEFAULT 0,
                ack_comment TEXT DEFAULT NULL,
                ack_by TEXT DEFAULT NULL,
                ack_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL
            )
        ");

        $this->ensureColumnExists('device_alerts', 'ack_comment', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('device_alerts', 'ack_by', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('device_alerts', 'ack_at', 'TEXT DEFAULT NULL');

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS mac_port_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mac_address TEXT NOT NULL,
                ip_address TEXT DEFAULT NULL,
                device_name TEXT DEFAULT NULL,
                vendor TEXT DEFAULT NULL,
                switch_id INTEGER NOT NULL,
                switch_name TEXT DEFAULT NULL,
                switch_ip TEXT DEFAULT NULL,
                port_name TEXT NOT NULL,
                vlan_id INTEGER DEFAULT 1,
                first_seen TEXT NOT NULL,
                last_seen TEXT NOT NULL,
                sightings_count INTEGER NOT NULL DEFAULT 1
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_mph_mac ON mac_port_history(mac_address)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_mph_sw_port ON mac_port_history(switch_id, port_name)");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS mac_watchlist (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mac_address TEXT NOT NULL UNIQUE,
                label TEXT DEFAULT NULL,
                notify_mode TEXT NOT NULL DEFAULT 'always',
                is_active INTEGER NOT NULL DEFAULT 1,
                last_triggered_at TEXT DEFAULT NULL,
                last_seen_ip TEXT DEFAULT NULL,
                last_seen_switch TEXT DEFAULT NULL,
                last_seen_port TEXT DEFAULT NULL,
                created_at TEXT NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS switch_port_outlets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                switch_id INTEGER NOT NULL,
                port_name TEXT NOT NULL,
                outlet_label TEXT DEFAULT NULL,
                patch_panel TEXT DEFAULT NULL,
                room TEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(switch_id, port_name)
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_spo_switch ON switch_port_outlets(switch_id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_spo_outlet ON switch_port_outlets(outlet_label)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_dev_scope_status ON devices(scope_id, status)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_dev_mac ON devices(mac_address)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_dev_core ON devices(is_core)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_links_scope ON links(scope_id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_links_src_dst ON links(source_device_id, target_device_id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_alerts_ack ON device_alerts(is_acknowledged, id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_logs_started ON scan_logs(started_at)");
    }

    private function ensureColumnExists($table, $column, $definition)
    {
        try {
            $this->pdo->query("SELECT `$column` FROM `$table` LIMIT 1");
        } catch (Exception $e) {
            try {
                $this->pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            } catch (Exception $ex) {
                // Ignorieren falls Spalte bereits existiert
            }
        }
    }

    private function seedInitialDataIfEmpty()
    {
        $now = date('Y-m-d H:i:s');

        // 1. Standard-Benutzer für die 3 Berechtigungsstufen anlegen, falls noch keine Benutzer existieren
        $userCount = (int)$this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($userCount === 0) {
            $insUser = $this->pdo->prepare("
                INSERT INTO users (username, password_hash, full_name, role, is_active, created_at)
                VALUES (:u, :p, :n, :r, 1, :now)
            ");
            // Admin-Konto (Vollzugriff inkl. Datenbank & Benutzerverwaltung)
            $insUser->execute(array(
                ':u'   => 'admin',
                ':p'   => password_hash('admin123', PASSWORD_DEFAULT),
                ':n'   => 'System-Administrator',
                ':r'   => 'admin',
                ':now' => $now,
            ));
            // Netzwerkexperte-Konto (Core-Switches, VLANs, Trunks & Switch-Zugangsdaten)
            $insUser->execute(array(
                ':u'   => 'netadmin',
                ':p'   => password_hash('netadmin123', PASSWORD_DEFAULT),
                ':n'   => 'Netzwerkexperte (Core & Switch Admin)',
                ':r'   => 'netadmin',
                ':now' => $now,
            ));
            // Operator-Konto (Scans starten, Scopes & Access-Ports bearbeiten)
            $insUser->execute(array(
                ':u'   => 'operator',
                ':p'   => password_hash('operator123', PASSWORD_DEFAULT),
                ':n'   => 'Netzwerk-Techniker (Operator)',
                ':r'   => 'operator',
                ':now' => $now,
            ));
            // Viewer-Konto (Nur Lesen & Exportieren)
            $insUser->execute(array(
                ':u'   => 'gast',
                ':p'   => password_hash('gast123', PASSWORD_DEFAULT),
                ':n'   => 'Betrachter (Nur Lesen)',
                ':r'   => 'viewer',
                ':now' => $now,
            ));

            // 2. Lokalen Scope beim allerersten Datenbank-Start anlegen
            $count = (int)$this->pdo->query("SELECT COUNT(*) FROM scopes")->fetchColumn();
            if ($count === 0) {
                $localCidr = '192.168.1.0/24';
                $gatewayIp = '192.168.1.1';

                $routeOut = @shell_exec('ip -4 route show default 2>/dev/null');
                if ($routeOut && preg_match('/default via ([0-9.]+)/', $routeOut, $m)) {
                    $gatewayIp = $m[1];
                    $parts = explode('.', $gatewayIp);
                    if (count($parts) === 4) {
                        $localCidr = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
                    }
                } else {
                    $addrOut = @shell_exec('ip -4 -o addr show scope global 2>/dev/null');
                    if ($addrOut && preg_match('/inet ([0-9.]+)\/(\d+)/', $addrOut, $m)) {
                        $ip = $m[1];
                        $parts = explode('.', $ip);
                        if (count($parts) === 4) {
                            $localCidr = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
                            $gatewayIp = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.1';
                        }
                    }
                }

                $stmt = $this->pdo->prepare("
                    INSERT INTO scopes (name, cidr, gateway_ip, snmp_community, snmp_version, description, vlan_id, color, is_active, created_at)
                    VALUES (:name, :cidr, :gw, 'public', '2c', :desc, 1, '#3b82f6', 1, :now)
                ");
                $stmt->execute(array(
                    ':name' => 'Lokales Netzwerk (Auto-Erkannt)',
                    ':cidr' => $localCidr,
                    ':gw'   => $gatewayIp,
                    ':desc' => 'Automatisch erkanntes lokales Subnetz des Servers',
                    ':now'  => $now,
                ));
            }
        } else {
            // Stelle bei bestehenden Datenbanken sicher, dass das Standard-Konto für die neue Rolle 'netadmin' vorhanden ist
            $netAdminCount = (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'netadmin'")->fetchColumn();
            if ($netAdminCount === 0) {
                $chkName = $this->pdo->prepare("SELECT id FROM users WHERE username = 'netadmin' LIMIT 1");
                $chkName->execute();
                if (!$chkName->fetchColumn()) {
                    $insNet = $this->pdo->prepare("
                        INSERT INTO users (username, password_hash, full_name, role, is_active, created_at)
                        VALUES (:u, :p, :n, :r, 1, :now)
                    ");
                    $insNet->execute(array(
                        ':u'   => 'netadmin',
                        ':p'   => password_hash('netadmin123', PASSWORD_DEFAULT),
                        ':n'   => 'Netzwerkexperte (Core & Switch Admin)',
                        ':r'   => 'netadmin',
                        ':now' => $now,
                    ));
                }
            }

            // Einmaliges Backfill für mac_port_history aus bereits vorhandenen Switch-Port-Links, falls Tabelle noch leer ist
            try {
                $histCount = (int)$this->pdo->query("SELECT COUNT(*) FROM mac_port_history")->fetchColumn();
                if ($histCount === 0) {
                    $existingLinks = $this->pdo->query("
                        SELECT l.source_device_id, l.source_port, l.updated_at,
                               d.mac_address, d.ip_address, d.custom_name, d.hostname, d.vendor, d.first_seen, d.last_seen,
                               sw.custom_name AS sw_custom, sw.hostname AS sw_host, sw.snmp_sysname AS sw_sysname, sw.ip_address AS sw_ip
                        FROM links l
                        INNER JOIN devices d ON l.target_device_id = d.id
                        INNER JOIN devices sw ON l.source_device_id = sw.id
                        WHERE l.source_port IS NOT NULL AND l.source_port != '' AND l.source_port != 'Trunk' AND l.source_port != 'Uplink'
                          AND d.mac_address IS NOT NULL AND d.mac_address != ''
                    ")->fetchAll();
                    if (!empty($existingLinks)) {
                        $insH = $this->pdo->prepare("
                            INSERT INTO mac_port_history (mac_address, ip_address, device_name, vendor, switch_id, switch_name, switch_ip, port_name, vlan_id, first_seen, last_seen, sightings_count)
                            VALUES (:mac, :ip, :dname, :vend, :swid, :swname, :swip, :pname, 1, :fseen, :lseen, 1)
                        ");
                        foreach ($existingLinks as $el) {
                            $swLabel = !empty($el['sw_custom']) ? $el['sw_custom'] : (!empty($el['sw_sysname']) ? $el['sw_sysname'] : (!empty($el['sw_host']) ? $el['sw_host'] : $el['sw_ip']));
                            $devLabel = !empty($el['custom_name']) ? $el['custom_name'] : (!empty($el['hostname']) ? $el['hostname'] : $el['ip_address']);
                            $insH->execute(array(
                                ':mac'    => strtoupper($el['mac_address']),
                                ':ip'     => $el['ip_address'],
                                ':dname'  => $devLabel,
                                ':vend'   => $el['vendor'],
                                ':swid'   => (int)$el['source_device_id'],
                                ':swname' => $swLabel,
                                ':swip'   => $el['sw_ip'],
                                ':pname'  => $el['source_port'],
                                ':fseen'  => !empty($el['first_seen']) ? $el['first_seen'] : $now,
                                ':lseen'  => !empty($el['last_seen']) ? $el['last_seen'] : $now,
                            ));
                        }
                    }
                }
            } catch (Exception $e) {}
        }
    }
}
