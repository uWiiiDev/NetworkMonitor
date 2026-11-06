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
        $this->pdo = new PDO('sqlite:' . $sqlitePath, null, null, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->activeDriver = 'sqlite';
        $this->initSqliteSchema();
        $this->seedInitialDataIfEmpty();
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

    public function getConfig()
    {
        return $this->config;
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
     * Speichert Datenbank- & Scanner-Einstellungen aus dem Adminbereich in data/custom_config.json
     */
    public static function saveCustomConfig(array $dbSettings, array $scannerSettings = array())
    {
        $customFile = __DIR__ . '/../data/custom_config.json';
        $dataDir = dirname($customFile);
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }
        $existing = array('db' => array(), 'scanner' => array());
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
        file_put_contents($customFile, json_encode($existing, JSON_PRETTY_PRINT));
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
                first_seen TEXT NOT NULL,
                last_seen TEXT NOT NULL
            )
        ");

        $this->ensureColumnExists('devices', 'is_hidden', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumnExists('devices', 'custom_group', 'TEXT DEFAULT NULL');
        $this->ensureColumnExists('devices', 'service_info', 'TEXT DEFAULT NULL');

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
                role TEXT NOT NULL DEFAULT 'viewer',
                is_active INTEGER NOT NULL DEFAULT 1,
                last_login TEXT DEFAULT NULL,
                created_at TEXT NOT NULL
            )
        ");
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
            // Operator-Konto (Scans starten, Scopes & Geräte bearbeiten)
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
        }
    }
}
