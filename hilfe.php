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

        <!-- 3. Ansichten, Große Netzwerke & Export -->
        <div id="sec-ansichten" class="help-card">
            <h2>3. Die 6 Ansichten, Werkzeuge für große Netzwerke &amp; Vektor-Exporte</h2>
            <ul>
                <li><strong>Ansicht 1 – Stern-Topologie:</strong> Frei bewegliches Kräfte-Layout rund um Gateway und Switches (friert nach dem Einschwingen automatisch ein, damit verschobene Knoten ruhig stehen bleiben).</li>
                <li><strong>Ansicht 2 – Baum (Oben → Unten, Block-Raster):</strong> Klassisches Organigramm (Router oben → Switches → Endgeräte als kompaktes 5er-Block-Raster direkt unter ihrem Switch).</li>
                <li><strong>Ansicht 3 – Baum (Links → Rechts, Block-Raster):</strong> Ideal für breite Monitore und viele Gerätenamen.</li>
                <li><strong>Ansicht 4 – Kreis / Radial:</strong> Übersichtsring ohne überlappende Knoten.</li>
                <li><strong>Ansicht 5 – Gruppen-Kacheln (Matrix):</strong> Sortierte Gerätekarten nach Kategorien und eigenen Gruppen ganz ohne Linien-Gewirr.</li>
                <li><strong>Ansicht 6 – 19" Switch-Port-Rack:</strong> Zeigt jeden Switch/Router als physisches 19-Zoll-Port-Panel mit grünen/roten Status-LEDs pro Port.</li>
            </ul>
            <h3>Spezial-Funktionen für große Netzwerke (Übersichtlichkeit)</h3>
            <ul>
                <li><strong>📦 Client-Cluster:</strong> Bündelt normale Endgeräte (PCs/Clients) pro Switch bzw. Gateway zu einem Sammel-Knoten (z. B. <code>📦 18 Clients</code>). Ein Klick zeigt alle enthaltenen Geräte rechts in der Seitenleiste; ein <strong>Doppelklick</strong> klappt das Cluster im Plan auf.</li>
                <li><strong>🏢 Nur Infrastruktur:</strong> Blendet mit einem Klick alle normalen Workstations aus, sodass nur das Netzwerk-Rückgrat (Router, Switches, Server, Access Points und Drucker) sichtbar ist.</li>
                <li><strong>🎯 Fokus-Modus (Nachbarschaft isolieren):</strong> Ein Doppelklick auf einen Switch/Router (oder Klick auf <em>„🎯 Fokus: Gerät &amp; Nachbarn isolieren“</em> in den Details rechts) blendet alle anderen Zweige aus und zeigt nur dieses Gerät samt seinen direkt verbundenen Ports.</li>
                <li><strong>📌 Layout &amp; Positionen speichern:</strong> Über das Menü <strong>„📌 Layout ▾“</strong> lassen sich die zurechtgezogenen Knoten-Positionen dauerhaft in der Datenbank speichern sowie die Option <em>„🔍 Text-Details erst beim Reinzoomen“</em> steuern.</li>
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

        <!-- 5. Benutzerrollen & Alarme -->
        <div id="sec-rechte" class="help-card">
            <h2>5. Benutzerrollen, Einstellungen &amp; Geräte-Alarme (SMTP)</h2>
            <ul>
                <li><span class="badge" style="background:rgba(239,68,68,0.2);color:#fca5a5;">ADMIN</span> <strong>Administrator:</strong> Vollzugriff auf alle Funktionen sowie auf den Reiter <strong>⚙ Einstellungen</strong> zur Konfiguration der MySQL-/SQLite-Datenbankverbindung, des SMTP-E-Mail-Servers, der Benutzerverwaltung sowie zum Leeren einzelner Scopes.</li>
                <li><span class="badge" style="background:rgba(16,185,129,0.2);color:#6ee7b7;">OPERATOR</span> <strong>Operator (Techniker):</strong> Darf Netzwerk-Scans starten, Scopes anlegen, Geräte umbenennen, Switch-Ports zuweisen, Geräte-Alarme aktivieren und Geräte/Gruppen ein- oder ausblenden.</li>
                <li><span class="badge" style="background:rgba(59,130,246,0.2);color:#93c5fd;">VIEWER</span> <strong>Betrachter (Nur Lesen):</strong> Darf alle 6 Ansichten, den SNMP-Inspektor und alle Export-Funktionen nutzen, aber keine Änderungen speichern oder Scans auslösen.</li>
            </ul>
            <h3>🔔 Online-/Offline-Geräte-Alarme &amp; E-Mail-Benachrichtigungen</h3>
            <ul>
                <li><strong>Gerät überwachen:</strong> Klicke ein Gerät im Plan an und wähle rechts unter <strong>„🔔 Alarm-Überwachung (Online / Offline)“</strong> den gewünschten Modus (z. B. <em>🔴 Alarm wenn OFFLINE (+ Online-Recovery)</em>). Vor einem Offline-Alarm führt der Scanner automatisch einen Sicherheits-Nachping aus, um Fehlalarme zu vermeiden.</li>
                <li><strong>Web-Alarm-Zentrale:</strong> Über die Glocke <strong>„🔔 Alarme“</strong> oben in der Navigationsleiste siehst du jederzeit alle ausgelösten Statuswechsel und aktuell überwachten Geräte.</li>
                <li><strong>E-Mail-Versand per SMTP:</strong> Unter <strong>⚙ Einstellungen</strong> kannst du einen beliebigen SMTP-Server (STARTTLS Port 587, SSL Port 465 oder unverschlüsselt Port 25) hinterlegen und per <strong>„✉ Test-E-Mail senden“</strong> sofort testen.</li>
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
            <h2>7. Managed Switches vorbereiten: Wie funktioniert die Port-Erkennung &amp; was wird benötigt?</h2>
            <p>
                NetworkMonitor kann vollautomatisch ermitteln, <strong>welches Endgerät an welcher physischen Buchse (Port) eines Switches angeschlossen ist</strong>, und zeichnet diese Port-Bezeichnung (z. B. <code>Gi1/0/8</code> oder <code>Port 12</code>) direkt an die grünen Verbindungslinien im Netzwerkplan sowie in die 19"-Rack-Ansicht.
            </p>

            <h3>A. Wie funktioniert das technisch im Hintergrund?</h3>
            <ol style="font-size:13.5px;color:#cbd5e1;line-height:1.65;padding-left:20px;margin-bottom:12px;">
                <li><strong>IP &harr; MAC-Adresse (Ping &amp; ARP-Cache):</strong> Beim Subnetz-Scan ermittelt der Server für jedes aktive Gerät dessen IP- und Hardware-MAC-Adresse (z. B. <code>192.168.178.34</code> &rarr; <code>48:BA:4E:86:2B:BF</code>).</li>
                <li><strong>SNMP-Abfrage der Switch-MAC-Tabelle (UDP Port 161):</strong> Jeder Managed Switch führt intern eine sogenannte <em>Forwarding Database (MAC-Adresstabelle)</em>, um zu wissen, hinter welchem Port welche MAC-Adresse steckt. NetworkMonitor liest diese Tabelle über die standardisierten MIBs <strong><code>Bridge-MIB</code> (<code>1.3.6.1.2.1.17.4.3.1.2</code>)</strong> sowie für VLANs <strong><code>Q-Bridge-MIB</code> (<code>1.3.6.1.2.1.17.7.1.2.2.1.2</code>)</strong> aus und übersetzt die Port-Nummer über die <strong><code>IF-MIB</code></strong> in den echten Portnamen (z. B. <code>Gi1/0/18</code>).</li>
                <li><strong>Uplink-Filterung &amp; LLDP-Nachbarschaft:</strong> Sieht ein Switch auf einem Port viele verschiedene MAC-Adressen (inkl. der MAC des Routers), erkennt der Algorithmus diesen Port automatisch als <strong>Uplink-/Trunk-Port</strong>. Sieht er auf einem Port nur 1–2 MAC-Adressen, ist das der direkte Anschlussport des Endgeräts. Über <strong><code>LLDP</code> (IEEE 802.1AB)</strong> erkennen sich zudem direkt miteinander verbundene Switches untereinander.</li>
            </ol>

            <h3>B. Was braucht man dafür? (Die 3 Voraussetzungen)</h3>
            <ul>
                <li><strong>1. Einen „Managed Switch“ oder „Smart Managed Switch“:</strong> Einfache, „dumme“ Tischswitches (<em>Unmanaged Switches</em> ohne eigene IP-Adresse und ohne Weboberfläche) besitzen keinen SNMP-Agenten und leiten Kabelsignale nur unsichtbar weiter. Sobald es ein <em>Smart Managed</em> oder <em>Managed Switch</em> mit eigener IP-Adresse im Subnetz ist, kann er abgefragt werden.</li>
                <li><strong>2. SNMP (v1 / v2c) im Switch einschalten:</strong> In der Weboberfläche (oder CLI) des Switches muss <strong>SNMP (Read-Only)</strong> auf UDP-Port <code>161</code> aktiviert und ein sogenannter <strong>Community-String</strong> (Lese-Passwort, Standard: <code>public</code>) eingetragen sein.</li>
                <li><strong>3. Gleiche Community im NetworkMonitor-Scope eintragen:</strong> Beim Anlegen oder Bearbeiten des Netzwerk-Scopes im NetworkMonitor muss im Feld <strong>„SNMP v2c Community“</strong> exakt derselbe Community-String stehen (Standard ist bereits <code>public</code>). Auf dem Linux-Server selbst ist kein Zusatzprogramm nötig, da NetworkMonitor einen eigenen Pure-PHP-SNMP-Client mitbringt.</li>
            </ul>

            <h3>C. Schritt-für-Schritt: SNMP &amp; LLDP bei gängigen Switch-Herstellern aktivieren</h3>
            <ul>
                <li><strong>Cisco IOS / Catalyst / Small Business (CBS / SG-Serie):</strong>
                    Per CLI (SSH/Telnet) oder im Web-GUI unter <em>SNMP &rarr; Communities</em>:
                    <div class="code-pre">configure terminal
snmp-server community public RO
lldp run
end
write memory</div>
                </li>
                <li><strong>HP ProCurve / ArubaOS / HPE Aruba InstantOn / OfficeConnect:</strong>
                    Per CLI oder im Webinterface unter <em>System &rarr; SNMP</em> (Community <code>public</code> mit Lese-Rechten / Read-Only anlegen):
                    <div class="code-pre">configure
snmp-server community "public" Unrestricted
lldp run
write memory</div>
                </li>
                <li><strong>Netgear (ProSAFE / Smart Managed Plus / Insight):</strong>
                    Im Webinterface unter <em>System &rarr; SNMP &rarr; SNMP Configuration</em>: <strong>SNMP aktivieren (Enable)</strong>, danach unter <em>Community Configuration</em> die Community <code>public</code> mit Access Mode <strong>Read Only</strong> und Status <strong>Enable</strong> anlegen. Unter <em>System &rarr; LLDP</em> zusätzlich LLDP aktivieren.
                </li>
                <li><strong>TP-Link (JetStream / Easy Smart / Omada):</strong>
                    Im Switch-Webinterface unter <em>System &rarr; SNMP &rarr; Global Config</em> das Häkchen bei <strong>SNMP Enable</strong> setzen. Anschließend unter <em>SNMP v1/v2c &rarr; Community List</em> einen Eintrag mit Community Name <code>public</code> und Access Right <strong>Read-Only</strong> hinzufügen. (Bei Omada SDN unter <em>Settings &rarr; Services &rarr; SNMP</em>).
                </li>
                <li><strong>Zyxel (GS1900 / GS1920 / XGS-Serie) &amp; D-Link (DGS-Serie):</strong>
                    Im Webinterface unter <em>Management &rarr; SNMP</em> den Dienst aktivieren und die Get-Community (Read-Only) auf <code>public</code> stellen.
                </li>
                <li><strong>Ubiquiti UniFi Switches &amp; Access Points:</strong>
                    Im UniFi Network Controller unter <em>Settings (Einstellungen) &rarr; System &rarr; Advanced / Services &rarr; SNMP</em> die Option <strong>SNMP v1/v2c</strong> aktivieren und als Community String <code>public</code> speichern.
                </li>
                <li><strong>MikroTik (RouterOS &amp; SwOS):</strong>
                    In WinBox / WebFig unter <em>IP &rarr; SNMP</em> das Häkchen bei <strong>Enabled</strong> setzen (Community <code>public</code> ist standardmäßig bereits hinterlegt).
                </li>
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
