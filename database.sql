-- ============================================================================
-- NetworkMonitor - MySQL Datenbank-Schema (kompatibel mit MySQL 5.5 / Ubuntu 14.04)
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `network_monitor`
  DEFAULT CHARACTER SET utf8
  DEFAULT COLLATE utf8_general_ci;

USE `network_monitor`;

-- 1. Netzwerk-Scopes (Subnetze / IP-Bereiche)
CREATE TABLE IF NOT EXISTS `scopes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `cidr` VARCHAR(50) NOT NULL,
  `gateway_ip` VARCHAR(45) DEFAULT NULL,
  `snmp_community` VARCHAR(100) DEFAULT 'public',
  `snmp_version` VARCHAR(10) DEFAULT '2c',
  `description` VARCHAR(255) DEFAULT NULL,
  `vlan_id` INT(11) DEFAULT NULL,
  `color` VARCHAR(20) DEFAULT '#3b82f6',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_scan_at` DATETIME DEFAULT NULL,
  `last_scan_status` VARCHAR(30) DEFAULT 'never',
  `last_scan_hosts` INT(11) DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 2. Erkannte Geräte / Hosts
CREATE TABLE IF NOT EXISTS `devices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `scope_id` INT(11) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `mac_address` VARCHAR(32) DEFAULT NULL,
  `vendor` VARCHAR(150) DEFAULT NULL,
  `hostname` VARCHAR(255) DEFAULT NULL,
  `custom_name` VARCHAR(255) DEFAULT NULL,
  `custom_group` VARCHAR(100) DEFAULT NULL,
  `device_type` VARCHAR(50) NOT NULL DEFAULT 'workstation',
  `os_guess` VARCHAR(150) DEFAULT NULL,
  `service_info` TEXT DEFAULT NULL,
  `open_ports` TEXT DEFAULT NULL,
  `snmp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `snmp_sysname` VARCHAR(255) DEFAULT NULL,
  `snmp_sysdescr` TEXT DEFAULT NULL,
  `snmp_sysobjectid` VARCHAR(255) DEFAULT NULL,
  `snmp_location` VARCHAR(255) DEFAULT NULL,
  `snmp_ports_json` LONGTEXT DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'online',
  `latency_ms` FLOAT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_manual_type` TINYINT(1) NOT NULL DEFAULT 0,
  `is_hidden` TINYINT(1) NOT NULL DEFAULT 0,
  `alert_mode` VARCHAR(20) NOT NULL DEFAULT 'none',
  `last_alert_state` VARCHAR(20) DEFAULT NULL,
  `pos_x` INT(11) DEFAULT NULL,
  `pos_y` INT(11) DEFAULT NULL,
  `switch_ssh_user` VARCHAR(100) DEFAULT NULL,
  `switch_ssh_pass` VARCHAR(255) DEFAULT NULL,
  `switch_snmp_community` VARCHAR(100) DEFAULT NULL,
  `switch_snmp_write_community` VARCHAR(100) DEFAULT NULL,
  `is_core` TINYINT(1) NOT NULL DEFAULT 0,
  `first_seen` DATETIME NOT NULL,
  `last_seen` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_ip_address` (`ip_address`),
  KEY `idx_scope_id` (`scope_id`),
  KEY `idx_mac_address` (`mac_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 3. Topologie-Verbindungen (Layer 2 Switch-Ports / Layer 3 Gateways)
CREATE TABLE IF NOT EXISTS `links` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `scope_id` INT(11) DEFAULT NULL,
  `source_device_id` INT(11) NOT NULL,
  `target_device_id` INT(11) NOT NULL,
  `source_port` VARCHAR(100) DEFAULT NULL,
  `target_port` VARCHAR(100) DEFAULT NULL,
  `link_type` VARCHAR(30) NOT NULL DEFAULT 'subnet',
  `is_manual` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_source` (`source_device_id`),
  KEY `idx_target` (`target_device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 4. Scan-Verlauf / Logs
CREATE TABLE IF NOT EXISTS `scan_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `scope_id` INT(11) DEFAULT NULL,
  `trigger_type` VARCHAR(20) NOT NULL DEFAULT 'manual',
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'running',
  `hosts_up` INT(11) DEFAULT 0,
  `switches_found` INT(11) DEFAULT 0,
  `summary` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 5. Benutzer & Berechtigungen (Rollen: admin, operator, viewer)
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(60) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(120) DEFAULT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'viewer',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 6. Geräte-Alarme (Online / Offline Überwachung & E-Mail-Historie)
CREATE TABLE IF NOT EXISTS `device_alerts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `device_id` INT(11) NOT NULL,
  `scope_id` INT(11) DEFAULT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `device_name` VARCHAR(255) DEFAULT NULL,
  `alert_type` VARCHAR(40) NOT NULL,
  `severity` VARCHAR(20) NOT NULL DEFAULT 'critical',
  `message` TEXT NOT NULL,
  `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `is_acknowledged` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_ack` (`is_acknowledged`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

