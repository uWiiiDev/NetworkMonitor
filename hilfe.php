<?php
/**
 * NetworkMonitor - Hilfe, Benutzerhandbuch & Installationsanleitung
 * Aufrufbar über das "?"-Symbol oben rechts in der Hauptnavigation
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
$currentUser = Auth::getCurrentUser();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hilfe &amp; Installationsanleitung – NetworkMonitor</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        body {
            overflow-y: auto;
            height: auto;
            min-height: 100vh;
            background: var(--bg-dark);
            color: var(--text-primary);
        }
        .help-wrapper {
            max-width: 1060px;
            margin: 0 auto;
            padding: 28px 24px 60px 24px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .help-card {
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 22px 24px;
        }
        .help-card h2 {
            font-size: 17px;
            color: #60a5fa;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
        }
        .help-card h3 {
            font-size: 14.5px;
            color: #e2e8f0;
            margin: 16px 0 8px 0;
        }
        .help-card p, .help-card li {
            font-size: 13.5px;
            line-height: 1.65;
            color: #cbd5e1;
        }
        .help-card ul, .help-card ol {
            padding-left: 20px;
            margin-bottom: 10px;
        }
        .help-toc {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .help-toc a {
            text-decoration: none;
            padding: 6px 12px;
            background: rgba(59, 130, 246, 0.12);
            border: 1px solid rgba(59, 130, 246, 0.35);
            border-radius: 6px;
            color: #93c5fd;
            font-size: 12.5px;
            font-weight: 600;
        }
        .help-toc a:hover {
            background: rgba(59, 130, 246, 0.25);
        }
        .code-pre {
            background: #090d16;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 12px 14px;
            font-family: monospace;
            font-size: 12.5px;
            color: #34d399;
            overflow-x: auto;
            white-space: pre;
            margin: 8px 0 12px 0;
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="brand">
            <div class="brand-logo">?</div>
            <div>
                <div class="brand-title">NetworkMonitor – Hilfe, Handbuch &amp; Installationsanleitung</div>
            </div>
        </div>
        <div class="topbar-actions">
            <a href="index.php" class="btn btn-sm btn-primary" style="text-decoration:none;">← Zurück zum Netzwerkplan</a>
            <button class="btn btn-sm" onclick="window.print()">🖨 Seite drucken</button>
        </div>
    </header>

    <div class="help-wrapper">

        <!-- Inhaltsverzeichnis -->
        <div class="help-card">
            <h2>Inhaltsverzeichnis</h2>
            <div class="help-toc">
                <a href="#sec-speicherung">1. Werden alte Scans gespeichert?</a>
                <a href="#sec-snmp-daten">2. SNMP-Inspektor &amp; OS-Erkennung</a>
                <a href="#sec-ansichten">3. Die 6 Ansichten &amp; Vektor-Exporte</a>
                <a href="#sec-inventar">4. Inventarliste (Sortieren &amp; Spalten)</a>
                <a href="#sec-rechte">5. Benutzerrollen &amp; Adminbereich</a>
                <a href="#sec-install">6. Installationsanleitung (Ubuntu 14.04 &amp; Linux)</a>
                <a href="#sec-switches">7. SNMP auf Managed Switches aktivieren</a>
                <a href="#sec-cron">8. Automatischer Hintergrund-Scan (Cronjob)</a>
            </div>
        </div>

        <!-- 1. Speicherverhalten -->
        <div id="sec-speicherung" class="help-card">
            <h2>1. Werden alte Scans abgespeichert oder nur temporär angezeigt?</h2>
            <p>
                <strong>Alle Scan-Ergebnisse, Geräte, Verbindungen und manuellen Anpassungen werden dauerhaft in der Datenbank (SQLite bzw. MySQL) gespeichert!</strong>
                Sie bleiben auch nach dem Schließen des Browsers oder nach einem Neustart des Servers vollständig erhalten:
            </p>
            <ul>
                <li><strong>Dauerhafte Geräte-Historie:</strong> Jedes jemals gefundene Gerät erhält in der Datenbank einen Zeitstempel für die erste Entdeckung (<code>first_seen</code>) und die letzte Sichtung (<code>last_seen</code>).</li>
                <li><strong>Was passiert, wenn ein Gerät beim nächsten Scan ausgeschaltet ist?</strong> Das Gerät wird <em>nicht</em> gelöscht! Es behält seinen Namen, seine Gruppe, seine Switch-Port-Zuordnung und seine Position im Plan, wird aber automatisch als <span class="badge badge-offline">OFFLINE</span> (rot umrandet) markiert, bis es wieder eingeschaltet wird.</li>
                <li><strong>Erhalt eigener Anpassungen:</strong> Manuell vergebene Anzeigenamen, eigene Gruppen (z. B. <em>IoT</em>, <em>Büro EG</em>), manuell gezogene Switch-Port-Links und das Ausblenden einzelner Geräte bleiben bei jedem neuen Scan erhalten.</li>
                <li><strong>Scan-Protokolle:</strong> Unter dem Reiter <strong>Cronjob &amp; System</strong> werden die letzten Scan-Durchläufe mit Start-/Endzeit, Auslöser (Web-Klick oder Cronjob) und Anzahl der gefundenen Hosts protokolliert.</li>
            </ul>
        </div>

        <!-- 2. SNMP & OS Fingerprinting -->
        <div id="sec-snmp-daten" class="help-card">
            <h2>2. Was wird per SNMP &amp; OS-Fingerprinting aus den Geräten gelesen?</h2>
            <p>
                Beim Klick auf ein beliebiges Gerät im Netzwerkplan oder in der Inventarliste öffnet sich rechts die Detail-Seitenleiste. Antworten Geräte auf <strong>SNMP (v1/v2c)</strong> (z. B. Managed Switches, Router, NAS oder Netzwerkdrucker), erscheint zusätzlich der grüne Button <strong>„🔍 Alle SNMP-Daten öffnen“</strong>.
            </p>
            <h3>A. Ausgelesene SNMP-Daten (MIB-II, IF-MIB, Bridge-MIB, LLDP, Printer-MIB)</h3>
            <ul>
                <li><strong>System- &amp; Hardware-Daten:</strong> Gerätename (<code>sysName</code>), Standort (<code>sysLocation</code>), Kontakt (<code>sysContact</code>), exaktes Gerätemodell (<code>hrDeviceDescr</code> / <code>entPhysicalModelName</code>), <strong>Seriennummer</strong>, Firmware-Version und die aktuelle <strong>Betriebszeit (Uptime)</strong> auf die Sekunde genau.</li>
                <li><strong>Switch-Ports &amp; Netzwerk-Interfaces (IF-MIB):</strong> Alle Ports inkl. Admin-Beschreibung (<code>ifAlias</code>), Link-Status (<code>UP</code>/<code>DOWN</code>), ausgehandelter Geschwindigkeit (<code>10 Mbit/s</code>, <code>100 Mbit/s</code>, <code>1 Gbit/s</code>, <code>10 Gbit/s</code>), <strong>übertragenem Datenvolumen (RX/TX in MB)</strong> sowie <strong>Paket-Fehlerzählern (CRC-Fehler)</strong> zur Erkennung defekter Netzwerkkabel.</li>
                <li><strong>Layer-2 MAC-Adresstabelle (Bridge-MIB &amp; Q-Bridge-MIB):</strong> Liest aus Managed Switches aus, welche MAC-Adresse an welchem physischen Switch-Port (z. B. <code>Gi1/0/5</code>) angeschlossen ist, und verbindet die Geräte automatisch im Netzwerkplan.</li>
                <li><strong>LLDP-Nachbarschaft (IEEE 802.1AB):</strong> Erkennt direkt verbundene Nachbar-Switches und Access Points über deren Uplink-/Trunk-Ports.</li>
                <li><strong>Netzwerkdrucker-Spezialdaten (Printer-MIB):</strong> Liest bei SNMP-fähigen Druckern (z. B. HP OfficeJet / LaserJet, Brother, Epson, Kyocera) automatisch den <strong>Gesamt-Seitenzähler (Gedruckte Seiten)</strong>, den aktuellen <strong>Display-Text</strong> des Druckers sowie die <strong>exakten Tinten- bzw. Toner-Füllstände in Prozent (%)</strong> mit farbigen Fortschrittsbalken aus.</li>
            </ul>

            <h3>B. Betriebssystem- &amp; Dienst-Erkennung (auch ohne SNMP!)</h3>
            <ul>
                <li><strong>ICMP-TTL-Fingerprinting:</strong> Erkennt anhand des Time-To-Live-Werts der Ping-Antwort das Grundsystem (<code>TTL 64</code> = Linux / macOS / iOS / Android, <code>TTL 128</code> = Microsoft Windows 10/11/Server, <code>TTL 255</code> = Switch / Drucker / IoT-Controller).</li>
                <li><strong>HTTP/HTTPS &amp; SSH Banner-Grabbing:</strong> Liest von offenen Web- und SSH-Ports parallel den HTML-Seitentitel (<code>&lt;title&gt;</code>), den Webserver-Header (<code>Server:</code>) sowie die SSH-Versionskennung (z. B. <code>OpenSSH Ubuntu</code>) aus.</li>
                <li><strong>Windows NetBIOS (UDP Port 137):</strong> Ermittelt Windows-Rechnernamen und die zugehörige Arbeitsgruppe/Domäne (z. B. <code>Workgroup: WORKGROUP</code>).</li>
            </ul>
        </div>

        <!-- 3. Ansichten & Export -->
        <div id="sec-ansichten" class="help-card">
            <h2>3. Die 6 Ansichten &amp; Hochauflösenden Vektor-Exporte</h2>
            <ul>
                <li><strong>Ansicht 1 – Stern-Topologie:</strong> Frei bewegliches Kräfte-Layout rund um Gateway und Switches.</li>
                <li><strong>Ansicht 2 – Baum (Oben → Unten):</strong> Klassisches Organigramm (Router oben → Switches → Endgeräte unten).</li>
                <li><strong>Ansicht 3 – Baum (Links → Rechts):</strong> Ideal für breite Monitore und viele Gerätenamen.</li>
                <li><strong>Ansicht 4 – Kreis / Radial:</strong> Übersichtsring ohne überlappende Knoten.</li>
                <li><strong>Ansicht 5 – Gruppen-Kacheln (Matrix):</strong> Sortierte Gerätekarten nach Kategorien und eigenen Gruppen ganz ohne Linien-Gewirr.</li>
                <li><strong>Ansicht 6 – 19" Switch-Port-Rack:</strong> Zeigt jeden Switch/Router als physisches 19-Zoll-Port-Panel mit grünen/roten Status-LEDs pro Port.</li>
            </ul>
            <p>
                Über das Menü <strong>„Exportieren ▾“</strong> lässt sich der Plan als <strong>verlustfreie SVG-Vektorgrafik (<code>.svg</code>)</strong> (unendlich zoombar + Textsuche mit <code>Strg+F</code>), als <strong>bearbeitbare Draw.io / Visio-Datei (<code>.drawio</code>)</strong>, als <strong>Vektor-PDF-Report</strong> oder als <strong>4K Ultra-HD PNG (3840×2160 px)</strong> speichern.
            </p>
        </div>

        <!-- 4. Inventarliste -->
        <div id="sec-inventar" class="help-card">
            <h2>4. Inventarliste: Sortieren &amp; Spalten ein-/ausblenden</h2>
            <ul>
                <li><strong>Sortieren per Klick:</strong> Ein Klick auf eine beliebige Spaltenüberschrift (z. B. <em>IP-Adresse</em>, <em>Hostname</em>, <em>Typ</em>, <em>Betriebssystem</em> oder <em>SNMP Details</em>) sortiert die Tabelle aufsteigend (<code>▲</code>) bzw. beim zweiten Klick absteigend (<code>▼</code>). IP-Adressen werden dabei echt numerisch sortiert (<code>.2</code> vor <code>.10</code>).</li>
                <li><strong>Spalten ein-/ausblenden:</strong> Über den Button <strong>„☷ Spalten ein-/ausblenden ▾“</strong> oben rechts über der Tabelle kannst du beliebige Spalten per Häkchen ein- oder ausschalten (z. B. auch die Zusatzspalte <em>Zuletzt gesehen</em>). Deine Spaltenauswahl wird automatisch im Browser gespeichert.</li>
            </ul>
        </div>

        <!-- 5. Benutzerrollen -->
        <div id="sec-rechte" class="help-card">
            <h2>5. Benutzerrollen &amp; Adminbereich</h2>
            <ul>
                <li><span class="badge" style="background:rgba(239,68,68,0.2);color:#fca5a5;">ADMIN</span> <strong>Administrator:</strong> Vollzugriff auf alle Funktionen sowie auf den Reiter <strong>⚙ Adminbereich (DB &amp; Rechte)</strong> zur Konfiguration der MySQL-/SQLite-Datenbankverbindung und zur Benutzerverwaltung.</li>
                <li><span class="badge" style="background:rgba(16,185,129,0.2);color:#6ee7b7;">OPERATOR</span> <strong>Operator (Techniker):</strong> Darf Netzwerk-Scans starten, Scopes anlegen, Geräte umbenennen, Switch-Ports zuweisen und Geräte/Gruppen ein- oder ausblenden.</li>
                <li><span class="badge" style="background:rgba(59,130,246,0.2);color:#93c5fd;">VIEWER</span> <strong>Betrachter (Nur Lesen):</strong> Darf alle 6 Ansichten, den SNMP-Inspektor und alle Export-Funktionen nutzen, aber keine Änderungen speichern oder Scans auslösen.</li>
            </ul>
        </div>

        <!-- 6. Installationsanleitung -->
        <div id="sec-install" class="help-card">
            <h2>6. Schritt-für-Schritt-Installationsanleitung (Ubuntu 14.04 LTS &amp; modernes Linux)</h2>
            <p>
                <strong>NetworkMonitor</strong> ist so programmiert, dass es sowohl auf älteren Bestandsservern (<strong>Ubuntu 14.04 LTS mit Apache 2.4, PHP 5.5+ und MySQL 5.5+</strong>) als auch auf aktuellen Linux-Systemen (Ubuntu 20.04 / 22.04 / 24.04, Debian 11/12 mit PHP 7.x / 8.x) ohne zusätzliche Abhängigkeiten läuft.
            </p>

            <h3>Schritt 1: Benötigte Pakete auf dem Ubuntu-Server installieren</h3>
            <div class="code-pre">sudo apt-get update
sudo apt-get install -y apache2 mysql-server php5 php5-mysql php5-sqlite php5-snmp php5-cli php5-json snmp nmap iproute2</div>
            <p style="font-size:12px;color:var(--text-secondary);">
                <em>Hinweis für neuere Ubuntu/Debian-Versionen (ab 18.04+): Ersetze <code>php5-*</code> durch <code>php php-mysql php-sqlite3 php-snmp php-cli php-xml</code>.</em>
            </p>

            <h3>Schritt 2: Programmdateien nach /var/www/html/networkmonitor kopieren</h3>
            <div class="code-pre">sudo mkdir -p /var/www/html/networkmonitor
sudo cp -r * /var/www/html/networkmonitor/
sudo chown -R www-data:www-data /var/www/html/networkmonitor
sudo chmod -R 755 /var/www/html/networkmonitor
sudo chmod -R 775 /var/www/html/networkmonitor/data</div>

            <h3>Schritt 3: Datenbank wählen (SQLite Sofort-Start oder MySQL)</h3>
            <ul>
                <li><strong>Option A – SQLite (Empfohlen bis ~5.000 Geräte, 0 Konfiguration nötig):</strong> Standardmäßig legt NetworkMonitor automatisch die Datei <code>data/network_monitor.sqlite</code> im schnellen WAL-Modus an. Es ist keine manuelle Datenbank-Einrichtung erforderlich!</li>
                <li><strong>Option B – MySQL / MariaDB Server:</strong> Falls du MySQL nutzen möchtest, kannst du die Verbindung direkt im Webinterface unter <strong>⚙ Adminbereich (DB &amp; Rechte)</strong> eintragen und per Klick auf <em>„MySQL-Verbindung testen“</em> prüfen – alle Tabellen werden dabei automatisch angelegt. Alternativ per Kommandozeile:</li>
            </ul>
            <div class="code-pre">mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS network_monitor CHARACTER SET utf8 COLLATE utf8_general_ci;"
mysql -u root -p network_monitor &lt; /var/www/html/networkmonitor/database.sql</div>

            <h3>Schritt 4: Im Browser öffnen &amp; anmelden</h3>
            <p>
                Rufe <code>http://&lt;SERVER-IP&gt;/networkmonitor/</code> im Browser auf. Standard-Zugangsdaten beim Erststart (bitte danach über das Benutzermenü ändern):
            </p>
            <ul>
                <li>Administrator: <code>admin</code> / Passwort: <code>admin123</code></li>
                <li>Techniker: <code>operator</code> / Passwort: <code>operator123</code></li>
                <li>Betrachter: <code>gast</code> / Passwort: <code>gast123</code></li>
            </ul>
        </div>

        <!-- 7. SNMP auf Switches aktivieren -->
        <div id="sec-switches" class="help-card">
            <h2>7. SNMP v2c &amp; LLDP auf Managed Switches aktivieren</h2>
            <p>
                Damit NetworkMonitor die physische Port-Belegung (welches Gerät steckt an welchem Switch-Port) automatisch einzeichnet, muss auf den Managed Switches <strong>SNMP v2c (Read-Only)</strong> aktiviert sein:
            </p>
            <ul>
                <li><strong>Cisco IOS / Catalyst Switches:</strong>
                    <div class="code-pre">configure terminal
snmp-server community public RO
lldp run
end
write memory</div>
                </li>
                <li><strong>HP ProCurve / ArubaOS Switches:</strong>
                    <div class="code-pre">configure
snmp-server community "public" Unrestricted
lldp run
write memory</div>
                </li>
                <li><strong>Ubiquiti UniFi / TP-Link JetStream / Netgear / MikroTik:</strong> Im Switch-Webinterface unter <em>Settings → Services → SNMP</em> die Option <strong>SNMP v1/v2c</strong> aktivieren und die Community (z. B. <code>public</code>) im jeweiligen Netzwerk-Scope hinterlegen.</li>
            </ul>
        </div>

        <!-- 8. Cronjob -->
        <div id="sec-cron" class="help-card">
            <h2>8. Automatischen Hintergrund-Scan per Cronjob einrichten</h2>
            <p>
                Neben dem manuellen Scan per Klick im Webinterface kann der Scanner über das Skript <code>cli_scan.php</code> beliebig oft im Hintergrund laufen (z. B. alle 30 Minuten):
            </p>
            <div class="code-pre">sudo crontab -e

# Alle 30 Minuten alle aktiven Netzwerk-Scopes scannen:
*/30 * * * * /usr/bin/php /var/www/html/networkmonitor/cli_scan.php --all &gt;&gt; /var/log/networkmonitor_scan.log 2&gt;&amp;1</div>
        </div>

    </div>

</body>
</html>
