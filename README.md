# NetworkMonitor – Automatischer Netzwerkplan, SNMP Switch Discovery & OS-Fingerprinting

**NetworkMonitor** ist eine schnelle, webbasierte Anwendung zur automatischen Netzwerk-Erkennung (Network Discovery) und interaktiven Topologie-Visualisierung. Sie unterstützt mehrere Netzwerk-Scopes (CIDR & IP-Bereiche), liest **Managed Switches** sowie **Netzwerkdrucker** per **SNMP (v1/v2c)** aus und läuft sowohl auf modernen Linux-Systemen (PHP 7.x / 8.x) als auch auf älteren Bestandsservern (**Ubuntu 14.04 LTS mit Apache 2.4, PHP 5.5+ und MySQL 5.5+ / SQLite**).

---

## Hauptfunktionen

- **Schneller Multi-Host-Batch-Scanner:**
  - Paralleler Ping-Sweep & Linux Kernel ARP-Cache-Auswertung
  - Paralleler nicht-blockierender UDP DNS-Reverse-Lookup (`PTR`) & Windows NetBIOS (`NBSTAT` UDP 137)
  - Asynchroner Multi-Host TCP-Port-Scanner (32 Hosts × 15 Ports gleichzeitig)
  - Paralleles **HTTP/HTTPS (`<title>` & `Server:`-Header)** sowie **SSH-Banner-Grabbing**
  - **ICMP-TTL Betriebssystem-Fingerprinting** (`TTL 64` Linux/macOS/iOS/Android, `TTL 128` Windows, `TTL 255` Switch/Drucker/IoT)
- **Tiefe SNMP v1/v2c Integration (ohne externe Zusatzpakete dank Pure-PHP ASN.1 BER Client):**
  - Automatische Layer-2 Port-Zuordnung über **Bridge-MIB / Q-Bridge-MIB MAC-Adresstabellen** und **LLDP-Nachbarschaft**
  - Auslesen von `sysName`, `sysDescr`, `sysUpTime`, `sysLocation`, **Gerätemodell**, **Seriennummer**, Port-Geschwindigkeit (`Mbit/s` / `Gbit/s`), **Traffic-Zählern (RX/TX in MB)** und Paketfehlern (`IF-MIB`)
  - **Drucker-MIB Unterstützung:** Liest Gesamt-Seitenzähler, Display-Text und **Tinten-/Toner-Füllstände in Prozent (`%`)** aus
- **6 interaktive Ansichten:**
  1. Dynamische Stern-Topologie
  2. Hierarchischer Baum (Oben → Unten)
  3. Hierarchischer Baum (Links → Rechts)
  4. Kreis- / Radial-Topologie
  5. Gruppen-Kachelansicht (Matrix)
  6. 19-Zoll Switch-Port-Panel (Rack-Ansicht)
- **Vektor- & Ultra-HD-Exporte:**
  - **SVG Vektorgrafik (`.svg`)** – unendlich verlustfrei zoombar & mit `Strg+F` durchsuchbar
  - **Draw.io / Visio XML (`.drawio`)** – direkt in Draw.io oder Microsoft Visio weiterbearbeitbar
  - **PDF / Druck-Report** & **4K Ultra-HD PNG (3840 × 2160 px)**
  - **CSV-Inventarexport** mit sortierbaren und ein-/ausblendbaren Tabellenspalten
- **🔔 Geräte-Alarme (Online/Offline-Überwachung) & SMTP-Benachrichtigungen:**
  - Individuelle Überwachung kritischer Geräte (Router, Switches, Server, Drucker) auf **Offline-Ausfall** (+ automatische Online-Wiederherstellungsmeldung) oder **Online-Erscheinen**
  - Integrierter **Sicherheits-Nachping** vor einem Offline-Alarm zum Schutz vor Fehlalarmen
  - **Web-Alarm-Zentrale (`🔔 Alarme`)** mit Ungelesen-Zähler in der Kopfzeile sowie **automatischer HTML-E-Mail-Versand** über einen frei konfigurierbaren **SMTP-Server** (`STARTTLS` Port 587 / `SSL` Port 465 / Plain Port 25 inkl. Live-Test-Button)
- **Authentifizierung, Rollen & Adminbereich (`⚙ Einstellungen`):**
  - 3 Rollen: **`ADMIN`** (Vollzugriff), **`OPERATOR`** (Scans & Bearbeitung), **`VIEWER`** (Nur Lesen & Export)
  - Konfiguration & Live-Test für **MySQL / MariaDB** oder **SQLite (Zero-Config mit WAL-Modus)** direkt im Webinterface
  - Gezieltes Leeren/Löschen einzelner Scopes und Scan-Ergebnisse im Adminbereich

---

## Schnellstart & Installation

Eine ausführliche deutschsprachige Anleitung ist direkt in der Anwendung über das **`?`**-Symbol oben rechts ([`hilfe.php`](hilfe.php)) sowie in [`INSTALL_HILFE.md`](INSTALL_HILFE.md) enthalten.

```bash
git clone https://github.com/uWiiiDev/NetworkMonitor.git
cd NetworkMonitor
chmod -R 775 data
php -S 0.0.0.0:8080
```

Anschließend im Browser `http://localhost:8080` öffnen.

### Standard-Zugangsdaten beim Erststart
*(Werden beim ersten Start automatisch in einer frischen Datenbank angelegt – bitte danach im Benutzermenü ändern)*
- **Administrator:** `admin` / `admin123`
- **Techniker (Operator):** `operator` / `operator123`
- **Betrachter (Viewer):** `gast` / `gast123`

### Automatischer Hintergrund-Scan per Cronjob
```bash
*/30 * * * * /usr/bin/php /var/www/html/networkmonitor/cli_scan.php --all >> /var/log/networkmonitor_scan.log 2>&1
```
