<?php
/**
 * NetworkMonitor - Hilfe, Benutzerhandbuch & Installationsanleitung
 * Aufrufbar über das "?"-Symbol oben rechts in der Hauptnavigation
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
$currentUser = Auth::getCurrentUser();
$appVersion = Database::getAppVersion();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hilfe &amp; Handbuch (v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?>) – NetworkMonitor</title>
    <link rel="stylesheet" href="assets/style.css">
    <script>
        try {
            var savedTheme = localStorage.getItem('nm_theme');
            if (savedTheme) document.documentElement.setAttribute('data-theme', savedTheme);
        } catch (e) {}
    </script>
    <style>
        body {
            overflow-y: auto;
            height: auto;
            min-height: 100vh;
            background: var(--bg-app);
            color: var(--text-primary);
        }
        .help-wrapper {
            max-width: 1080px;
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
            color: var(--text-primary);
            margin: 16px 0 8px 0;
        }
        .help-card p, .help-card li {
            font-size: 13.5px;
            line-height: 1.65;
            color: var(--text-secondary);
        }
        .help-card strong {
            color: var(--text-primary);
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
            color: #60a5fa;
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
            <div style="display:flex;align-items:center;gap:8px;">
                <div class="brand-title">NetworkMonitor – Hilfe, Handbuch &amp; Installationsanleitung</div>
                <span class="badge" style="background:rgba(59,130,246,0.18);border:1px solid rgba(59,130,246,0.45);color:#93c5fd;font-family:monospace;">v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></span>
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
                <a href="#sec-speicherung">1. Dauerhafte Speicherung &amp; Aufbewahrungsfristen</a>
                <a href="#sec-snmp-daten">2. SNMP-Inspektor, 19" Switch-Grafik &amp; Dosen-Mapping</a>
                <a href="#sec-ansichten">3. Die 6 Ansichten, Mehrsprachigkeit &amp; Farbschemata</a>
                <a href="#sec-inventar">4. Inventarliste (Excel-Filter &amp; Gruppensummen)</a>
                <a href="#sec-alarme">5. Anomalie-Wächter, MAC-Fahndung &amp; Alarm-Quittierung</a>
                <a href="#sec-rechte">6. 4 Rollen, Benutzer-E-Mails, Passwort-Reset &amp; 2FA</a>
                <a href="#sec-update">7. GitHub-Online-Update, Sicherheit &amp; Performance</a>
                <a href="#sec-install">8. Installationsanleitung (Ubuntu 14.04 &amp; modernes Linux)</a>
                <a href="#sec-switches">9. SNMP &amp; LLDP auf Managed Switches aktivieren</a>
            </div>
        </div>

        <!-- 1. Speicherverhalten -->
        <div id="sec-speicherung" class="help-card">
            <h2>1. Dauerhafte Speicherung, Geräte-Historie &amp; Aufbewahrungsfristen</h2>
            <p>
                <strong>Alle Scan-Ergebnisse, Geräte, Switch-Port-Historien, Netzwerkdosen-Zuordnungen und Alarme werden dauerhaft in der Datenbank (SQLite im WAL-Modus bzw. MySQL/MariaDB) gespeichert.</strong>
            </p>
            <ul>
                <li><strong>Dauerhafte Geräte- &amp; Port-Historie:</strong> Jedes gefundene Gerät erhält Zeitstempel für die erste Entdeckung (<code>first_seen</code>) und die letzte Sichtung (<code>last_seen</code>). Zudem protokolliert <code>mac_port_history</code>, welche MAC-Adresse wann an welchem Switch-Port gesteckt war.</li>
                <li><strong>Verhalten bei ausgeschalteten Geräten:</strong> Ausgeschaltete Geräte behalten ihren Namen, ihre eigene Gruppe, ihre Switch-Port-Zuordnung und ihre Position im Plan, werden aber als <span class="badge badge-offline">OFFLINE</span> markiert, bis sie wieder antworten.</li>
                <li><strong>Automatische Datenbank-Bereinigung (Retention):</strong> Im Reiter <strong>⚙ Einstellungen</strong> unter Punkt 5 lässt sich festlegen, nach wie vielen Tagen alte Scan-Protokolle/Alarme (Standard: 14 Tage) sowie lange verschwundene Offline-Clients (Standard: 30 Tage) automatisch bereinigt werden.</li>
            </ul>
        </div>

        <!-- 2. SNMP, 19" Switch-Grafik & Dosen-Mapping -->
        <div id="sec-snmp-daten" class="help-card">
            <h2>2. SNMP-Discovery, 19" Switch-Frontblende, Live-SSH &amp; Netzwerkdosen-Zuordnung</h2>
            <h3>A. Ausgelesene SNMP-Daten (MIB-II, IF-MIB, Bridge-MIB, Q-Bridge-MIB, LLDP, Printer-MIB)</h3>
            <ul>
                <li><strong>System- &amp; Hardware-Daten:</strong> <code>sysName</code>, <code>sysLocation</code>, Gerätemodell, Seriennummer, Uptime sowie Switch-Stack-Mitglieder (Unit #1, Unit #2 usw.).</li>
                <li><strong>Layer-2 Port-Zuordnung &amp; Topologie:</strong> Automatische Zuordnung von Endgeräten an den exakten physischen Switch-Port (inkl. Multi-Switch-Kaskaden, Uplink-Erkennung und korrekter Unterscheidung zwischen lokalen Switch-Ports und Geräten hinter dem Router/WLAN).</li>
                <li><strong>Drucker-MIB:</strong> Gesamt-Seitenzähler, Display-Meldung und exakte Toner-/Tinten-Füllstände in Prozent (<code>%</code>).</li>
            </ul>

            <h3>B. Originalgetreue 19-Zoll Switch-Frontblende &amp; Live-Port-Konfiguration (SSH / SNMP)</h3>
            <ul>
                <li>Ein Klick auf <strong>„🎛 19" Switch-Port-Belegung &amp; VLANs“</strong> öffnet das grafische 19"-Switch-Gehäuse mit ungeraden Ports oben, geraden Ports unten (umschaltbar zwischen <em>Standard Untereinander</em> und <em>Linear Nebeneinander</em>) und abgetrenntem SFP+/Uplink-Block.</li>
                <li><strong>Live-Port-Konfiguration:</strong> Nach Klick auf eine Buchse können Administratoren, Netzwerkexperten und Operatoren das <strong>Access-VLAN (PVID)</strong>, den <strong>Port-Alias</strong> sowie den <strong>Admin-Status (PoE / Shutdown)</strong> live per SSH auf den Switch übertragen (Profile für Cisco IOS/SG/CBS, HP Aruba/ProCurve, HPE Comware, Dell, Netgear, Extreme, Allied Telesis, D-Link, Zyxel, MikroTik, Huawei, Juniper).</li>
            </ul>

            <h3>C. 🔌 Patchfeld- &amp; Netzwerkdosen-Zuordnung (Wall Jack Mapping)</h3>
            <ul>
                <li>Da Anwender meist nur die Beschriftung ihrer Wanddose (z. B. <code>Dose E1-04</code>, <code>D-104</code> im <code>Raum 204</code>) kennen, lässt sich jedem Switch-Port direkt im Port-Inspektor oder über den <strong>„🔌 Patchfeld- &amp; Dosen-Editor (Alle Ports + CSV)“</strong> eine <strong>Dosenbezeichnung</strong>, ein <strong>Patchfeld-Port</strong> und ein <strong>Raum</strong> zuweisen (inkl. CSV-Import und CSV-Export der kompletten Patchliste).</li>
                <li>Die Dosenbezeichnung erscheint automatisch auf der 19"-Switch-Buchse, in den Geräte-Details, in der Inventarliste und ist im <strong>„🔍 MAC-, Dosen- &amp; Switch-Port-Finder“</strong> direkt durchsuchbar.</li>
            </ul>
        </div>

        <!-- 3. Ansichten, Sprachen & Farbschemata -->
        <div id="sec-ansichten" class="help-card">
            <h2>3. Die 6 Topologie-Ansichten, Mehrsprachigkeit &amp; 5 Farbschemata</h2>
            <ul>
                <li><strong>6 Ansichten:</strong> (1) Dynamische Stern-Topologie, (2) Baum Oben &rarr; Unten, (3) Baum Links &rarr; Rechts, (4) Kreis / Radial, (5) Gruppen-Kacheln (Matrix), (6) 19"-Switch-Rack-Ansicht.</li>
                <li><strong>🎨 5 Farbschemata (Design-Wähler oben rechts):</strong>
                    <ul>
                        <li><strong>Midnight Slate:</strong> Klassisches dunkles NOC-Blau (Standard).</li>
                        <li><strong>Cyber Emerald:</strong> Dunkles Leitwarten- &amp; Matrix-Grün.</li>
                        <li><strong>Deep Violet &amp; Cyan:</strong> Kontrastreiches Indigo-Violett mit Cyan-Akzenten.</li>
                        <li><strong>Amber Industrial:</strong> Dunkles Anthrazit mit warmen Gold-/Bernstein-Akzenten.</li>
                        <li><strong>☀️ Daylight Clean:</strong> Heller Büro- und Druck-Modus mit weißem Hintergrund.</li>
                    </ul>
                </li>
                <li><strong>🌐 Mehrsprachigkeit (DE / EN / FR / ES):</strong> Über den Sprachwähler <strong>„🌐 DE ▾“</strong> in der oberen Kopfleiste lässt sich die Oberfläche jederzeit verzögerungsfrei zwischen <strong>Deutsch</strong>, <strong>Englisch</strong>, <strong>Französisch</strong> und <strong>Spanisch</strong> umschalten.</li>
            </ul>
        </div>

        <!-- 4. Inventarliste: Excel-Filter & Gruppensummen -->
        <div id="sec-inventar" class="help-card">
            <h2>4. Inventarliste: Excel-Spaltenfilter („Enthält") &amp; Gruppensummen</h2>
            <ul>
                <li><strong>⧩ Excel-Filter in jedem Spaltenkopf:</strong> Ein Klick auf das Filter-Symbol <strong><code>⧩</code></strong> neben einer Spaltenüberschrift öffnet ein Excel-ähnliches Filtermenü mit:
                    <ul>
                        <li><strong>Bedingungsfiltern:</strong> <em>Enthält</em>, <em>Enthält nicht</em>, <em>Beginnt mit</em>, <em>Endet mit</em>, <em>Ist gleich (=)</em>, <em>Ist nicht leer (zugeordnet)</em> und <em>Ist leer (nicht zugeordnet)</em>.</li>
                        <li><strong>Werteliste mit Summen:</strong> Zeigt alle in dieser Spalte vorkommenden Werte per Checkbox samt ihrer exakten Anzahl in Klammern an (z. B. Hersteller- oder Gerätetyp-Summen).</li>
                    </ul>
                </li>
                <li><strong>📊 Gruppieren &amp; Zwischensummen:</strong> Über das Auswahlfeld <strong>„📊 Gruppieren &amp; Summen“</strong> kann die Tabelle nach <em>Eigener Gerätegruppe</em>, <em>Gerätetyp</em>, <em>Hersteller</em>, <em>Betriebssystem</em>, <em>Switch/Verteiler</em>, <em>Scope</em> oder <em>Status</em> gruppiert werden. Jede Gruppe erhält eine auf-/zuklappbare Kopfzeile mit ihrer Zwischensumme (Anzahl Geräte, Online, Offline, SNMP).</li>
                <li><strong>Gruppen-Summen-Chips &amp; CSV-Export:</strong> Oberhalb der Tabelle werden klickbare Summen-Chips aller Gruppen angezeigt; der Button <strong>„CSV Export“</strong> exportiert genau die aktuell gefilterte Auswahl.</li>
            </ul>
        </div>

        <!-- 5. Anomalie-Wächter, MAC-Fahndungsliste & Alarm-Quittierung -->
        <div id="sec-alarme" class="help-card">
            <h2>5. Automatischer Anomalie-Wächter, MAC-Fahndungsliste &amp; Quittierung mit Kommentar</h2>
            <ul>
                <li><strong>🛡️ Automatischer Layer-2/Layer-3 Anomalie-Wächter:</strong> Überwacht bei jedem Scan vollautomatisch auf:
                    <ol>
                        <li><strong>⚡ ARP-Spoofing / IP-Konflikte:</strong> Warnung, wenn eine IP-Adresse plötzlich ihre Hardware-MAC-Adresse wechselt.</li>
                        <li><strong>🚨 Neue unbekannte MAC-Adressen:</strong> Meldung, sobald ein bislang unbekanntes Gerät erstmals im Netzwerk oder an einem Switch-Port auftaucht.</li>
                        <li><strong>🔌 Rogue-Switch / Mini-Hub-Erkennung:</strong> Alarm, wenn an einem einzelnen Access-Port mehr MAC-Adressen als erlaubt (Schwellenwert einstellbar, Standard: &gt; 3 MACs) auftauchen.</li>
                        <li><strong>🌪️ MAC-Flapping / Netzwerkschleifen &amp; Port-Paketfehler:</strong> Erkennt springende MAC-Adressen zwischen Ports sowie defekte Patchkabel anhand steigender CRC-/Input-Errors.</li>
                    </ol>
                </li>
                <li><strong>🎯 Gezielte MAC-Überwachung (MAC-Fahndungsliste):</strong> Unter <strong>„🔔 Alarme“</strong> kann eine noch unbekannte oder gesuchte MAC-Adresse vorab hinterlegt werden. Sobald diese MAC irgendwo im Netz angesteckt wird, erfolgt sofort ein Alarm (inkl. Switch, Port, Dose und IP) per Web &amp; E-Mail.</li>
                <li><strong>✅ Alarme mit Kommentar quittieren:</strong> Benutzer mit den Rollen <code>operator</code>, <code>netadmin</code> oder <code>admin</code> können einzelne oder alle offenen Alarme direkt in der Alarm-Zentrale mit einem **Freitext-Kommentar** (z. B. <em>„Wartung am Switch abgeschlossen"</em>) quittieren. Benutzername, Zeitstempel und Kommentar werden dauerhaft beim Alarm protokolliert.</li>
            </ul>
        </div>

        <!-- 6. 4 Rollen, Benutzer-E-Mails, Passwort-Reset & 2FA -->
        <div id="sec-rechte" class="help-card">
            <h2>6. Die 4 Berechtigungs-Rollen, Benutzer-E-Mails, Passwort-Reset &amp; 2FA</h2>
            <ul>
                <li><span class="badge" style="background:rgba(16,185,129,0.2);color:#34d399;">ADMIN</span> <strong>Administrator:</strong> Vollzugriff inkl. Core-Switches, Datenbank- &amp; SMTP-Konfiguration, GitHub-Update und Benutzerverwaltung.</li>
                <li><span class="badge" style="background:rgba(168,85,247,0.22);border:1px solid #a855f7;color:#e9d5ff;">NETADMIN</span> <strong>Netzwerkexperte:</strong> Vollzugriff auf <strong>alle Switches inkl. geschützter Core-Switches (<code>🔒 CORE</code>)</strong>, 802.1Q Trunk-Ports, VLAN-Neuanlage und Switch-Zugangsdaten.</li>
                <li><span class="badge" style="background:rgba(59,130,246,0.2);color:#93c5fd;">OPERATOR</span> <strong>Operator (Techniker):</strong> Darf Scans starten, Access-Ports patchen, Netzwerkdosen pflegen und Alarme mit Kommentar quittieren – sieht und verändert jedoch <strong>keine Core-Switches</strong>.</li>
                <li><span class="badge" style="background:rgba(148,163,184,0.2);color:#cbd5e1;">VIEWER</span> <strong>Betrachter (Nur Lesen):</strong> Darf alle Ansichten, Filter und Exporte nutzen (ohne Core-Switches, ohne Schreibrechte).</li>
            </ul>
            <h3>📧 Benutzer-E-Mail-Alarme, „Passwort vergessen?" &amp; Zwei-Faktor-Authentifizierung (2FA)</h3>
            <ul>
                <li><strong>Individuelle Alarm-Benachrichtigung pro Benutzer:</strong> Jedem Benutzer kann im Adminbereich (oder über <em>👤 Benutzer ▾ &rarr; 🔑 Eigenes Profil, E-Mail, Passwort &amp; 2FA</em>) eine persönliche E-Mail-Adresse samt Haken <strong>„🔔 Bei Netzwerk- &amp; Geräte-Alarmen per E-Mail benachrichtigen“</strong> zugeordnet werden.</li>
                <li><strong>Passwort vergessen? (Selbstständiger Reset):</strong> Auf der Login-Seite kann jeder Benutzer mit hinterlegter E-Mail-Adresse über <em>„Passwort vergessen?“</em> einen 6-stelligen Sicherheitscode per E-Mail anfordern und sein Passwort selbst zurücksetzen.</li>
                <li><strong>Zwei-Faktor-Authentifizierung (2FA):</strong> Pro Benutzerkonto lässt sich wahlweise **📧 2FA per E-Mail-Sicherheitscode** oder **📱 2FA per Authenticator-App (TOTP / RFC 6238 für Google/Microsoft Authenticator, Bitwarden)** aktivieren.</li>
            </ul>
        </div>

        <!-- 7. GitHub-Update, Sicherheit & Performance -->
        <div id="sec-update" class="help-card">
            <h2>7. 1-Klick GitHub-Update, Sicherheit &amp; Performance-Architektur</h2>
            <ul>
                <li><strong>🔄 Online-Update über GitHub:</strong> Im Reiter <strong>⚙ Einstellungen</strong> unter <em>„4. System-Umgebung, Version &amp; GitHub-Update“</em> prüft der Button <strong>„🔍 Auf GitHub nach Update prüfen“</strong> live gegen das Repository <code>github.com/uWiiiDev/NetworkMonitor</code> (Branch <code>main</code>), zeigt neue Commits als Changelog an und aktualisiert die Installation per 1-Klick unter vollständigem Erhalt aller lokalen Datenbanken und Konfigurationen in <code>data/</code>.</li>
                <li><strong>🔒 Sicherheits-Architektur:</strong>
                    <ul>
                        <li>Passwörter und Einmal-Sicherheitscodes werden ausschließlich als <code>bcrypt</code>-Hashes (<code>password_hash</code>) gespeichert.</li>
                        <li>Automatischer Brute-Force-Schutz (Rate-Limiting) bei Login, 2FA und Passwort-Reset sowie Session-ID-Regenerierung gegen Session-Fixation.</li>
                        <li>Automatischer HTTP-Zugriffsschutz (<code>.htaccess</code> &amp; <code>index.php</code>) für das Verzeichnis <code>data/</code> sowie Stripping sensibler Switch-Credentials aus API-Antworten.</li>
                    </ul>
                </li>
                <li><strong>⚡ Performance-Optimierung:</strong>
                    <ul>
                        <li>Sofortige Freigabe des PHP-Session-Locks (<code>session_write_close()</code>) in der API, damit laufende Hintergrund-Scans niemals parallele Klicks im Browser blockieren.</li>
                        <li>SQLite läuft im hochparallelen <code>WAL</code>-Journal-Modus mit Memory-Temp-Store und optimierten Indizes auf allen Such-, MAC-, Link- und Alarm-Tabellen.</li>
                    </ul>
                </li>
            </ul>
        </div>

        <!-- 8. Installationsanleitung -->
        <div id="sec-install" class="help-card">
            <h2>8. Schritt-für-Schritt-Installationsanleitung (Ubuntu 14.04 LTS &amp; modernes Linux)</h2>
            <p>
                <strong>NetworkMonitor</strong> läuft sowohl auf älteren Bestandsservern (<strong>Ubuntu 14.04 LTS mit Apache 2.4, PHP 5.5+ und MySQL 5.5+</strong>) als auch auf aktuellen Linux-Systemen (Ubuntu 20.04 / 22.04 / 24.04, Debian 11/12 mit PHP 7.x / 8.x) ohne externe Composer-Abhängigkeiten.
            </p>

            <h3>Schritt 1: Pakete installieren</h3>
            <div class="code-pre">sudo apt-get update
sudo apt-get install -y apache2 mysql-server php php-mysql php-sqlite3 php-snmp php-cli snmp nmap iproute2 git</div>
            <p style="font-size:12px;color:var(--text-secondary);">
                <em>Hinweis für Ubuntu 14.04 LTS: Verwende <code>php5 php5-mysql php5-sqlite php5-snmp php5-cli php5-json</code> statt <code>php-*</code>.</em>
            </p>

            <h3>Schritt 2: Repository klonen &amp; Berechtigungen setzen</h3>
            <div class="code-pre">cd /var/www/html
sudo git clone https://github.com/uWiiiDev/NetworkMonitor.git networkmonitor
sudo chown -R www-data:www-data /var/www/html/networkmonitor
sudo chmod -R 755 /var/www/html/networkmonitor
sudo chmod -R 775 /var/www/html/networkmonitor/data</div>

            <h3>Schritt 3: Im Browser öffnen &amp; anmelden</h3>
            <p>
                Rufe <code>http://&lt;SERVER-IP&gt;/networkmonitor/</code> im Browser auf. Vorab angelegte Rollen-Zugänge beim Erststart (bitte danach über das Benutzermenü ändern):
            </p>
            <ul>
                <li>Administrator: <code>admin</code> / <code>admin123</code></li>
                <li>Netzwerkexperte: <code>netadmin</code> / <code>netadmin123</code></li>
                <li>Techniker: <code>operator</code> / <code>operator123</code></li>
                <li>Betrachter: <code>gast</code> / <code>gast123</code></li>
            </ul>
        </div>

        <!-- 9. SNMP auf Switches aktivieren -->
        <div id="sec-switches" class="help-card">
            <h2>9. SNMP &amp; LLDP auf Managed Switches aktivieren</h2>
            <ul>
                <li><strong>Cisco IOS / Catalyst / CBS / SG-Serie:</strong>
                    <div class="code-pre">configure terminal
snmp-server community public RO
lldp run
end
write memory</div>
                </li>
                <li><strong>HP ProCurve / ArubaOS / HPE OfficeConnect:</strong>
                    <div class="code-pre">configure
snmp-server community "public" Unrestricted
lldp run
write memory</div>
                </li>
                <li><strong>Netgear, TP-Link, Zyxel, D-Link, Ubiquiti UniFi &amp; MikroTik:</strong> Im Switch-Webinterface unter <em>System / Management &rarr; SNMP</em> den Dienst <strong>SNMP v1/v2c</strong> aktivieren, die Read-Only Community (z. B. <code>public</code>) eintragen und <strong>LLDP</strong> einschalten.</li>
            </ul>
        </div>

    </div>

</body>
</html>
