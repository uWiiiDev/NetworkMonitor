<?php
/**
 * NetworkMonitor - Hauptanwendung & Interaktiver Netzwerkplan
 * Kompatibel mit Ubuntu 14.04 (Apache + PHP 5.5 + MySQL 5.5) bis PHP 8.x
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$db = Database::getInstance();
$currentUser = Auth::getCurrentUser();
if (!$currentUser) {
    header('Location: login.php');
    exit;
}
$userRole = $currentUser['role']; // 'viewer', 'operator', 'admin'
$canEdit = ($userRole === 'operator' || $userRole === 'admin');
$isAdmin = ($userRole === 'admin');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetworkMonitor - Automatischer Netzwerkplan & SNMP Switch Discovery</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/vis-network.min.js"></script>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="topbar">
        <div class="brand">
            <div class="brand-logo">NM</div>
            <div>
                <div class="brand-title">NetworkMonitor</div>
            </div>
        </div>

        <nav class="nav-tabs">
            <button class="nav-tab active" onclick="switchTab('map', this)">Netzwerkplan</button>
            <button class="nav-tab" onclick="switchTab('inventory', this)">Inventarliste</button>
            <?php if ($isAdmin): ?>
                <button id="nav-tab-admin" class="nav-tab" onclick="switchTab('admin', this)" title="Einstellungen">⚙ Einstellungen</button>
            <?php endif; ?>
        </nav>

        <div class="topbar-actions">
            <?php if ($canEdit): ?>
                <div class="dropdown">
                    <button class="btn btn-sm" onclick="toggleDropdown('dd-scan-actions', event)">Aktionen &amp; Scan ▾</button>
                    <div id="dd-scan-actions" class="dropdown-menu dropdown-right">
                        <div class="dropdown-item" onclick="triggerScan('all', null); closeAllDropdowns();">
                            <span style="color:#10b981;font-weight:700;">▶</span> Alle aktiven Scopes jetzt scannen
                        </div>
                        <div class="dropdown-item" onclick="openScopeModal(0); closeAllDropdowns();">
                            <span style="color:#60a5fa;font-weight:700;">+</span> Neuen Netzwerk-Scope anlegen
                        </div>
                    </div>
                </div>
                <button class="btn btn-sm btn-success" onclick="triggerScan(AppState.selectedScopeId, this)">
                    ▶ Scan starten
                </button>
            <?php endif; ?>

            <!-- Alarm-Glocke (Online/Offline Geräte-Überwachung) -->
            <button id="btn-topbar-alerts" class="btn btn-sm" onclick="openAlertsModal()" title="Geräte-Alarme &amp; Statuswechsel anzeigen">
                🔔 <span id="topbar-alert-label" style="font-size:12px;">Alarme</span>
                <span id="topbar-alert-badge" class="badge badge-offline" style="display:none;margin-left:3px;">0</span>
            </button>

            <!-- Benutzermenü Dropdown -->
            <div class="dropdown">
                <button class="btn btn-sm" onclick="toggleDropdown('dd-user-menu', event)">
                    👤 <?php echo htmlspecialchars($currentUser['username'], ENT_QUOTES, 'UTF-8'); ?>
                    <span class="badge" style="background:rgba(59,130,246,0.2);color:#93c5fd;margin-left:4px;">
                        <?php echo strtoupper(htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8')); ?>
                    </span> ▾
                </button>
                <div id="dd-user-menu" class="dropdown-menu dropdown-right" style="min-width:210px;">
                    <div class="dropdown-header">
                        <?php echo htmlspecialchars($currentUser['full_name'] ?: $currentUser['username'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="dropdown-item" onclick="closeAllDropdowns(); openPasswordModal();">
                        🔑 Eigenes Passwort ändern
                    </div>
                    <?php if ($isAdmin): ?>
                        <div class="dropdown-item" onclick="closeAllDropdowns(); var b = document.getElementById('nav-tab-admin'); if (b) b.click();">
                            ⚙ Einstellungen
                        </div>
                    <?php endif; ?>
                    <div class="dropdown-divider"></div>
                    <a href="login.php?logout=1" class="dropdown-item" style="text-decoration:none;color:#fca5a5;">
                        ⏻ Abmelden (Logout)
                    </a>
                </div>
            </div>

            <!-- Hilfe & Installationsanleitung (?) -->
            <a href="hilfe.php" target="_blank" class="btn btn-sm" style="font-weight:800;font-size:13.5px;width:28px;height:28px;padding:0;justify-content:center;border-radius:50%;background:rgba(59,130,246,0.18);border-color:#3b82f6;color:#93c5fd;text-decoration:none;" title="Hilfe, Benutzerhandbuch &amp; Installationsanleitung öffnen">?</a>
        </div>
    </header>

    <!-- Main Workspace -->
    <div class="workspace">

        <!-- Left Sidebar: Scopes (Ein-/Ausklappbar) -->
        <aside id="sidebar-left" class="sidebar-left">
            <div class="panel-header">
                <span class="panel-title">Netzwerk-Scopes</span>
                <div style="display:flex;gap:4px;">
                    <?php if ($canEdit): ?>
                        <button class="btn btn-sm btn-primary" onclick="openScopeModal(0)">+ Neu</button>
                    <?php endif; ?>
                    <button class="btn btn-sm" onclick="toggleLeftSidebar()" title="Seitenleiste einklappen">◀</button>
                </div>
            </div>
            <div id="scope-list" class="scope-list">
                <!-- Dynamisch per JS gefüllt -->
            </div>
        </aside>

        <!-- Center Stage -->
        <main class="main-stage">

            <!-- TAB 1: Interactive Network Map & Alternative Views -->
            <section id="view-map" class="view-pane active">
                <div class="map-toolbar">
                    <div class="toolbar-group">
                        <button id="btn-toggle-left" class="btn btn-sm" onclick="toggleLeftSidebar()" title="Scope-Seitenleiste ein-/ausblenden">
                            ☰ Scopes
                        </button>

                        <!-- Scope-Schnellauswahl-Dropdown -->
                        <select id="toolbar-scope-select" class="select-input" onchange="filterByScope(this.value)" title="Netzwerk-Scope wechseln">
                            <option value="all">Alle Netzwerk-Scopes</option>
                        </select>

                        <input type="text" id="map-search" class="search-input" placeholder="Suche IP, Name, MAC..." oninput="renderCurrentView()" style="width:175px;">

                        <!-- Gruppen & Geräte-Filter Dropdown -->
                        <div class="dropdown">
                            <button id="btn-device-filter" class="btn btn-sm" onclick="toggleDropdown('dd-filter-menu', event)">
                                Gruppen &amp; Filter ▾
                            </button>
                            <div id="dd-filter-menu" class="dropdown-menu" style="width:290px;" onclick="event.stopPropagation();">
                                <div class="dropdown-header">Gerätegruppen (Ein / Aus)</div>
                                <div id="dd-groups-list">
                                    <!-- Dynamisch per JS gefüllt -->
                                </div>
                                <div class="dropdown-divider"></div>
                                <label class="dropdown-check-item">
                                    <input type="checkbox" id="chk-show-hidden" onchange="renderCurrentView()">
                                    <span>Ausgeblendete Geräte grau zeigen</span>
                                </label>
                                <div class="dropdown-divider"></div>
                                <div class="dropdown-item" style="color:#60a5fa;font-weight:600;" onclick="closeAllDropdowns(); openVisibilityModal();">
                                    ⚙ Einzelne Geräte aus-/abwählen…
                                </div>
                            </div>
                        </div>

                        <!-- Schnell-Umschalter für große Netzwerke: Nur Infrastruktur & Client-Cluster -->
                        <button id="btn-infra-only" class="btn btn-sm" onclick="toggleInfraOnlyMode()" title="Ein-Klick-Umschalter: Nur Infrastruktur (Router, Switches, Server, APs, Drucker) vs. Alle Geräte inkl. Clients">
                            🏢 Nur Infrastruktur
                        </button>
                        <button id="btn-cluster-mode" class="btn btn-sm" onclick="toggleClusterMode()" title="Fasst Endgeräte (Clients) pro Switch/Gateway zu aufklappbaren Sammel-Knoten zusammen">
                            📦 Cluster
                        </button>
                    </div>

                    <div class="toolbar-group">
                        <!-- Ansicht Dropdown -->
                        <select id="view-mode-select" class="select-input" onchange="changeMapViewMode(this.value)" title="Darstellungsart wählen">
                            <option value="physics">Ansicht: 1. Stern-Topologie</option>
                            <option value="tree_ud">Ansicht: 2. Baum (Oben → Unten, Block-Raster)</option>
                            <option value="tree_lr">Ansicht: 3. Baum (Links → Rechts, Block-Raster)</option>
                            <option value="radial">Ansicht: 4. Kreis / Radial</option>
                            <option value="matrix">Ansicht: 5. Gruppen-Kacheln</option>
                            <option value="rack">Ansicht: 6. Switch-Port-Rack</option>
                        </select>

                        <!-- Layout, Zoom-LOD & Positionen Dropdown -->
                        <div class="dropdown">
                            <button id="btn-layout-menu" class="btn btn-sm" onclick="toggleDropdown('dd-layout-menu', event)" title="Knoten-Positionen speichern, Cluster steuern &amp; Zoom-Details">
                                📌 Layout ▾
                            </button>
                            <div id="dd-layout-menu" class="dropdown-menu dropdown-right" style="width:275px;" onclick="event.stopPropagation();">
                                <div class="dropdown-header">Große Netzwerke &amp; Layout</div>
                                <label class="dropdown-check-item" title="Blendet bei weiter Herauszoom-Stufe die Textschilder von normalen Workstations aus, damit Router &amp; Switches übersichtlich bleiben">
                                    <input type="checkbox" id="chk-lod-mode" checked onchange="toggleLodMode(this.checked)">
                                    <span>🔍 Text-Details erst beim Reinzoomen</span>
                                </label>
                                <div class="dropdown-divider"></div>
                                <div class="dropdown-item" onclick="closeAllDropdowns(); expandAllClusters();">
                                    📂 Alle Client-Cluster aufklappen
                                </div>
                                <div class="dropdown-item" onclick="closeAllDropdowns(); collapseAllClusters();">
                                    📁 Alle Client-Cluster bündeln
                                </div>
                                <?php if ($canEdit): ?>
                                <div class="dropdown-divider"></div>
                                <div class="dropdown-item" style="color:#34d399;font-weight:600;" onclick="closeAllDropdowns(); saveGraphPositions();">
                                    📌 Aktuelle Knoten-Positionen speichern
                                </div>
                                <div class="dropdown-item" style="color:#fca5a5;" onclick="closeAllDropdowns(); resetGraphPositions();">
                                    ↺ Gespeicherte Positionen zurücksetzen
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <button id="btn-fit-graph" class="btn btn-sm" onclick="fitNetworkGraph()" title="Netzwerkplan zentrieren">Zentrieren</button>

                        <!-- Export Dropdown -->
                        <div class="dropdown">
                            <button class="btn btn-sm btn-primary" onclick="toggleDropdown('dd-export-menu', event)">
                                Exportieren ▾
                            </button>
                            <div id="dd-export-menu" class="dropdown-menu dropdown-right" style="width:250px;">
                                <div class="dropdown-header">Netzwerkplan exportieren</div>
                                <div class="dropdown-item" onclick="closeAllDropdowns(); exportNetworkSvg();">
                                    <div>
                                        <div style="font-weight:600;">SVG Vektorgrafik (.svg)</div>
                                        <div style="font-size:11px;color:var(--text-secondary);">Verlustfrei zoombar &amp; Strg+F Suche</div>
                                    </div>
                                </div>
                                <div class="dropdown-item" onclick="closeAllDropdowns(); exportDrawIo();">
                                    <div>
                                        <div style="font-weight:600;">Draw.io / Visio (.drawio)</div>
                                        <div style="font-size:11px;color:var(--text-secondary);">Frei bearbeitbare Knoten &amp; Linien</div>
                                    </div>
                                </div>
                                <div class="dropdown-item" onclick="closeAllDropdowns(); exportPdfReport();">
                                    <div>
                                        <div style="font-weight:600;">PDF / Druck-Report</div>
                                        <div style="font-size:11px;color:var(--text-secondary);">Vektor-Plan + Inventartabelle</div>
                                    </div>
                                </div>
                                <div class="dropdown-item" onclick="closeAllDropdowns(); exportNetworkPng4K();">
                                    <div>
                                        <div style="font-weight:600;">4K Ultra-HD Bild (.png)</div>
                                        <div style="font-size:11px;color:var(--text-secondary);">3840 × 2160 Pixel Hochauflösung</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button id="btn-toggle-right" class="btn btn-sm" onclick="toggleRightSidebar()" title="Geräte-Details Seitenleiste ein-/ausblenden">
                            Details ☰
                        </button>
                    </div>
                </div>

                <!-- Fokus-Modus Leiste (wenn ein Switch / Gerät isoliert betrachtet wird) -->
                <div id="focus-mode-banner" style="display:none;background:rgba(59,130,246,0.16);border-bottom:1px solid rgba(59,130,246,0.45);padding:7px 14px;align-items:center;justify-content:space-between;gap:12px;font-size:12.5px;">
                    <div id="focus-mode-text" style="color:#bfdbfe;font-weight:600;"></div>
                    <button class="btn btn-sm" style="border-color:#60a5fa;color:#93c5fd;" onclick="setFocusDevice(null)">✕ Fokus-Modus beenden (Alle Geräte zeigen)</button>
                </div>

                <div id="network-canvas"></div>
                <div id="matrix-view-container" class="alt-view-container" style="display:none;"></div>
                <div id="rack-view-container" class="alt-view-container" style="display:none;"></div>
            </section>

            <!-- TAB 2: Inventory Table -->
            <section id="view-inventory" class="view-pane">
                <div class="map-toolbar">
                    <div class="toolbar-group">
                        <div class="panel-title">Gefundene Hosts &amp; Managed Switches</div>
                        <input type="text" id="inv-search" class="search-input" placeholder="In Tabelle filtern..." oninput="renderInventoryTable()" style="width:190px;">
                    </div>
                    <div class="toolbar-group">
                        <div class="dropdown">
                            <button class="btn btn-sm" onclick="toggleDropdown('dd-inv-cols', event)">
                                ☷ Spalten ein-/ausblenden ▾
                            </button>
                            <div id="dd-inv-cols" class="dropdown-menu dropdown-right" style="width:240px;" onclick="event.stopPropagation();">
                                <div class="dropdown-header">Sichtbare Tabellenspalten</div>
                                <div id="inv-col-toggles"></div>
                                <div class="dropdown-divider"></div>
                                <div class="dropdown-item" style="color:#60a5fa;font-weight:600;" onclick="resetInventoryColumns()">
                                    ↺ Alle Spalten einblenden
                                </div>
                            </div>
                        </div>
                        <button class="btn btn-sm btn-primary" onclick="exportInventoryCsv()">CSV Export</button>
                    </div>
                </div>
                <div class="content-scroll">
                    <table class="data-table">
                        <thead id="inventory-thead"></thead>
                        <tbody id="inventory-tbody"></tbody>
                    </table>
                </div>
            </section>

            <!-- TAB 3: Einstellungen / Adminbereich (Datenbank, Scanner, Benutzer, Cronjob & System) -->
            <?php if ($isAdmin): ?>
            <section id="view-admin" class="view-pane">
                <div class="content-scroll" style="display:flex;flex-direction:column;gap:24px;">

                    <div style="display:grid;grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));gap:20px;">
                        <!-- 1. Datenbank-Verbindung konfigurieren -->
                        <div style="background:var(--bg-panel);border:1px solid var(--border-color);border-radius:10px;padding:18px;display:flex;flex-direction:column;gap:12px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <h3 style="font-size:15px;">1. Datenbank-Verbindung (MySQL / SQLite)</h3>
                                <span id="adm-db-active-badge" class="badge badge-online">Aktiv</span>
                            </div>
                            <p style="font-size:12.5px;color:var(--text-secondary);">
                                Konfiguriere hier die MySQL-Datenbank für den Ubuntu 14.04 Server oder nutze den automatischen SQLite-Fallback für lokale Tests.
                            </p>
                            <div class="form-group">
                                <label>Datenbank-Modus (Driver)</label>
                                <select id="adm-db-driver" class="select-input">
                                    <option value="auto">Automatisch (Zuerst MySQL, sonst lokales SQLite)</option>
                                    <option value="mysql">MySQL / MariaDB Server erzwingen</option>
                                    <option value="sqlite">Lokale SQLite-Datenbank (data/network_monitor.sqlite)</option>
                                </select>
                            </div>
                            <div style="display:grid;grid-template-columns: 2fr 1fr;gap:10px;">
                                <div class="form-group">
                                    <label>MySQL Host / IP</label>
                                    <input type="text" id="adm-db-host" class="form-input" value="127.0.0.1">
                                </div>
                                <div class="form-group">
                                    <label>MySQL Port</label>
                                    <input type="number" id="adm-db-port" class="form-input" value="3306">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>MySQL Datenbank-Name</label>
                                <input type="text" id="adm-db-name" class="form-input" value="network_monitor">
                            </div>
                            <div style="display:grid;grid-template-columns: 1fr 1fr;gap:10px;">
                                <div class="form-group">
                                    <label>MySQL Benutzername</label>
                                    <input type="text" id="adm-db-user" class="form-input" value="root">
                                </div>
                                <div class="form-group">
                                    <label>MySQL Passwort</label>
                                    <input type="password" id="adm-db-pass" class="form-input" value="********" placeholder="Leer lassen oder neu eingeben">
                                </div>
                            </div>
                            <div id="adm-db-test-result" style="font-size:12.5px;display:none;padding:8px 10px;border-radius:6px;"></div>
                            <div style="display:flex;gap:8px;margin-top:4px;">
                                <button class="btn" onclick="testMysqlConnection()">MySQL-Verbindung testen</button>
                                <button class="btn btn-primary" style="flex:1;justify-content:center;" onclick="saveAdminDbAndScannerConfig()">DB &amp; Scanner-Konfiguration speichern</button>
                            </div>
                        </div>

                        <!-- 2. Multi-Host-Batch Scanner-Einstellungen -->
                        <div style="background:var(--bg-panel);border:1px solid var(--border-color);border-radius:10px;padding:18px;display:flex;flex-direction:column;gap:12px;">
                            <h3 style="font-size:15px;">2. Scanner-Performance (Multi-Host-Batch-Modus)</h3>
                            <p style="font-size:12.5px;color:var(--text-secondary);">
                                Feineinstellung der Parallelität für große Netzwerke (z.B. 1.500+ Geräte).
                            </p>
                            <div style="display:grid;grid-template-columns: 1fr 1fr;gap:10px;">
                                <div class="form-group">
                                    <label>Max. IPs pro einzelnem Scope</label>
                                    <input type="number" id="adm-sc-maxhosts" class="form-input" value="4096">
                                </div>
                                <div class="form-group">
                                    <label>Standard SNMP-Community</label>
                                    <input type="text" id="adm-sc-snmpcomm" class="form-input" value="public">
                                </div>
                            </div>
                            <div style="display:grid;grid-template-columns: 1fr 1fr 1fr;gap:10px;">
                                <div class="form-group">
                                    <label>Ping-Batch (parallel)</label>
                                    <input type="number" id="adm-sc-pingbatch" class="form-input" value="128">
                                </div>
                                <div class="form-group">
                                    <label>TCP-Port Host-Batch</label>
                                    <input type="number" id="adm-sc-portbatch" class="form-input" value="32">
                                </div>
                                <div class="form-group">
                                    <label>SNMP UDP-Batch</label>
                                    <input type="number" id="adm-sc-snmpbatch" class="form-input" value="100">
                                </div>
                            </div>
                            <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:8px;padding:12px;font-size:12px;color:var(--text-secondary);margin-top:auto;">
                                <b style="color:var(--text-primary);">Übersicht der 3 Berechtigungs-Rollen:</b><br>
                                • <b>ADMIN (Administrator):</b> Vollzugriff inkl. Datenbank-Konfiguration, Benutzerverwaltung, Scans &amp; Bearbeitung.<br>
                                • <b>OPERATOR (Techniker):</b> Darf Scans starten, Scopes anlegen sowie Geräte, Switch-Ports und Gruppen bearbeiten.<br>
                                • <b>VIEWER (Betrachter):</b> Darf sich anmelden, alle 6 Netzwerkplan-Ansichten nutzen, filtern und Pläne (SVG/Draw.io/PDF/CSV) exportieren, aber nichts verändern.
                            </div>
                        </div>
                    </div>

                    <!-- SMTP E-Mail-Server & Alarm-Benachrichtigungen -->
                    <div style="background:var(--bg-panel);border:1px solid var(--border-color);border-radius:10px;padding:18px;display:flex;flex-direction:column;gap:14px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                            <div>
                                <h3 style="font-size:15px;">🔔 SMTP E-Mail-Server &amp; Geräte-Alarm-Benachrichtigungen</h3>
                                <div style="font-size:12.5px;color:var(--text-secondary);">
                                    Konfiguriere einen SMTP-Server, um bei Statuswechseln überwachter Geräte (Offline-Ausfall / Online-Wiederherstellung) automatisch eine E-Mail zu versenden.
                                </div>
                            </div>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;background:rgba(59,130,246,0.12);border:1px solid rgba(59,130,246,0.35);padding:6px 12px;border-radius:8px;font-size:13px;font-weight:600;color:#93c5fd;">
                                <input type="checkbox" id="adm-smtp-enabled" style="width:16px;height:16px;">
                                <span>E-Mail-Alarme aktivieren</span>
                            </label>
                        </div>

                        <div style="display:grid;grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));gap:12px;">
                            <div class="form-group">
                                <label>SMTP-Server (Host / IP) *</label>
                                <input type="text" id="adm-smtp-host" class="form-input" placeholder="z.B. smtp.office365.com oder mail.firma.de">
                            </div>
                            <div class="form-group">
                                <label>SMTP-Port *</label>
                                <input type="number" id="adm-smtp-port" class="form-input" value="587" placeholder="587 (STARTTLS) oder 465 (SSL)">
                            </div>
                            <div class="form-group">
                                <label>Verschlüsselung</label>
                                <select id="adm-smtp-enc" class="select-input">
                                    <option value="tls">STARTTLS (Port 587 empfohlen)</option>
                                    <option value="ssl">SSL / TLS (Port 465)</option>
                                    <option value="none">Keine Verschlüsselung (Port 25 / Internes Relay)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>SMTP Benutzername (optional bei Relay)</label>
                                <input type="text" id="adm-smtp-user" class="form-input" placeholder="z.B. monitoring@firma.de">
                            </div>
                            <div class="form-group">
                                <label>SMTP Passwort</label>
                                <input type="password" id="adm-smtp-pass" class="form-input" value="********" placeholder="Passwort oder App-Kennwort">
                            </div>
                            <div class="form-group">
                                <label>Absender E-Mail-Adresse (From) *</label>
                                <input type="text" id="adm-smtp-from" class="form-input" placeholder="z.B. monitoring@firma.de">
                            </div>
                            <div class="form-group">
                                <label>Absender-Anzeigename</label>
                                <input type="text" id="adm-smtp-fromname" class="form-input" value="NetworkMonitor Alarm" placeholder="NetworkMonitor Alarm">
                            </div>
                            <div class="form-group">
                                <label>Empfänger E-Mail-Adresse(n) (Kommagetrennt) *</label>
                                <input type="text" id="adm-smtp-to" class="form-input" placeholder="z.B. admin@firma.de, bereitsschaft@firma.de">
                            </div>
                        </div>

                        <div id="adm-smtp-test-result" style="font-size:12.5px;display:none;padding:8px 12px;border-radius:6px;"></div>

                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                            <div style="font-size:12px;color:var(--text-secondary);">
                                💡 <b>Hinweis:</b> Um ein Gerät zu überwachen, klicke es im Netzwerkplan an und wähle rechts unter <b>„🔔 Alarm-Überwachung“</b> den gewünschten Modus (z.B. <i>Alarm wenn OFFLINE</i>). Vor einem Offline-Alarm führt der Scanner automatisch einen Sicherheits-Nachping aus.
                            </div>
                            <div style="display:flex;gap:8px;">
                                <button class="btn" onclick="testSmtpConfig()">✉ Test-E-Mail senden</button>
                                <button class="btn btn-primary" onclick="saveAdminSmtpConfig()">SMTP-Konfiguration speichern</button>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Benutzer- & Rechteverwaltung -->
                    <div style="background:var(--bg-panel);border:1px solid var(--border-color);border-radius:10px;padding:18px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                            <div>
                                <h3 style="font-size:15px;">3. Benutzer- &amp; Rechteverwaltung</h3>
                                <div style="font-size:12.5px;color:var(--text-secondary);">Verwalte Zugänge und weise Berechtigungsstufen (Admin, Operator, Viewer) zu.</div>
                            </div>
                            <button class="btn btn-sm btn-primary" onclick="openUserModal(0)">+ Neuer Benutzer</button>
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Benutzername</th>
                                    <th>Anzeigename / Abteilung</th>
                                    <th>Rolle / Berechtigung</th>
                                    <th>Letzter Login</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody id="admin-users-tbody"></tbody>
                        </table>
                    </div>

                    <!-- 4. Cronjob & System-Status -->
                    <div style="display:grid;grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));gap:20px;">
                        <div style="background:var(--bg-panel);border:1px solid var(--border-color);border-radius:10px;padding:18px;display:flex;flex-direction:column;gap:12px;">
                            <h3 style="font-size:15px;">4. System-Umgebung &amp; Diagnose</h3>
                            <div class="kv-grid" style="grid-template-columns: 200px 1fr;">
                                <div class="kv-label">PHP-Version</div><div class="kv-val" id="sys-php">-</div>
                                <div class="kv-label">Aktive Datenbank-Engine</div><div class="kv-val" id="sys-db">-</div>
                                <div class="kv-label">Netzwerk-Scanner Engine</div><div class="kv-val" id="sys-nmap">-</div>
                                <div class="kv-label">SNMP Switch-Abfrage</div><div class="kv-val" id="sys-snmp">-</div>
                            </div>
                        </div>

                        <div style="background:var(--bg-panel);border:1px solid var(--border-color);border-radius:10px;padding:18px;display:flex;flex-direction:column;gap:10px;">
                            <h3 style="font-size:15px;">5. Automatischer Hintergrund-Scan per Cronjob</h3>
                            <p style="font-size:12.5px;color:var(--text-secondary);">
                                Neben dem manuellen Scan per Klick im Webinterface kann der Scan jederzeit über die Kommandozeile oder als Cronjob ausgeführt werden (z.B. alle 30 Minuten):
                            </p>
                            <div class="code-block" id="sys-cron-cmd"></div>
                        </div>
                    </div>

                    <!-- 6. Letzte Scan-Protokolle -->
                    <div style="background:var(--bg-panel);border:1px solid var(--border-color);border-radius:10px;padding:18px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                            <h3 style="font-size:15px;">6. Letzte Scan-Protokolle <span id="last-scan-duration-info" style="font-weight:400;font-size:13px;color:#34d399;margin-left:8px;"></span></h3>
                            <button class="btn btn-sm btn-danger" onclick="adminClearScanLogs()">Protokolle leeren</button>
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Zeitpunkt</th>
                                    <th>Dauer</th>
                                    <th>Scope</th>
                                    <th>Auslöser</th>
                                    <th>Status</th>
                                    <th>Zusammenfassung</th>
                                </tr>
                            </thead>
                            <tbody id="scan-logs-tbody"></tbody>
                        </table>
                    </div>

                    <!-- 7. Netzwerk-Scopes & Scan-Datenbank bereinigen / löschen -->
                    <div style="background:var(--bg-panel);border:1px solid var(--border-color);border-radius:10px;padding:18px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                            <div>
                                <h3 style="font-size:15px;">7. Scopes &amp; Scan-Datenbank verwalten / leeren</h3>
                                <div style="font-size:12.5px;color:var(--text-secondary);">
                                    Leere die gescannten Geräte einzelner Scopes (Scope bleibt erhalten) oder lösche komplette Scopes samt aller Scans endgültig aus der Datenbank.
                                </div>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <button class="btn btn-sm" style="border-color:#f59e0b;color:#fcd34d;" onclick="adminClearScopeScans('all', 'ALLE Scopes')">
                                    🧹 Alle Scan-Ergebnisse leeren (Scopes behalten)
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="adminDeleteAllScopes()">
                                    ⚠ Komplette Datenbank (alle Scopes &amp; Scans) löschen
                                </button>
                            </div>
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Scope-Name</th>
                                    <th>Subnetz / IP-Bereich</th>
                                    <th>Gespeicherte Geräte</th>
                                    <th>Letzter Scan</th>
                                    <th>Aktionen (Datenbank bereinigen)</th>
                                </tr>
                            </thead>
                            <tbody id="admin-scopes-tbody"></tbody>
                        </table>
                    </div>

                </div>
            </section>
            <?php endif; ?>

        </main>

        <!-- Right Sidebar: Device Inspector (Ein-/Ausklappbar) -->
        <aside id="sidebar-right" class="sidebar-right collapsed">
            <div class="panel-header">
                <span class="panel-title">Geräte-Details &amp; Switch-Port</span>
                <button class="btn btn-sm" onclick="toggleRightSidebar()" title="Seitenleiste schließen">×</button>
            </div>
            <div id="inspector-content" class="inspector-body">
                <p style="color:var(--text-secondary);font-size:13px;">
                    Klicke im Netzwerkplan auf ein beliebiges Gerät oder einen Switch, um Details, offene Ports, SNMP-Switch-Daten und Port-Zuordnungen anzuzeigen oder zu bearbeiten.
                </p>
            </div>
        </aside>

    </div>

    <!-- Modal: Scope anlegen / bearbeiten -->
    <div id="scope-modal" class="modal-backdrop">
        <div class="modal">
            <div class="panel-header">
                <span class="panel-title">Netzwerk-Scope (Subnetz) konfigurieren</span>
                <button class="btn btn-sm" onclick="closeScopeModal()">×</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="scope-id" value="0">
                <div class="form-group">
                    <label>Bezeichnung des Netzwerks *</label>
                    <input type="text" id="scope-name" class="form-input" placeholder="z.B. Standort Hauptgebäude VLAN 10">
                </div>
                <div class="form-group">
                    <label>Netzwerk-Scope (CIDR oder IP-Bereich) *</label>
                    <input type="text" id="scope-cidr" class="form-input" placeholder="z.B. 192.168.178.0/24 oder 10.0.1.1-10.0.1.100">
                </div>
                <div class="form-group">
                    <label>Gateway / Router IP-Adresse (optional, sonst .1)</label>
                    <input type="text" id="scope-gw" class="form-input" placeholder="z.B. 192.168.178.1">
                </div>
                <div style="display:grid;grid-template-columns: 1fr 1fr;gap:10px;">
                    <div class="form-group">
                        <label>SNMP v2c Community (für Switches)</label>
                        <input type="text" id="scope-snmp" class="form-input" value="public" placeholder="public">
                    </div>
                    <div class="form-group">
                        <label>VLAN-ID (optional)</label>
                        <input type="number" id="scope-vlan" class="form-input" placeholder="z.B. 10">
                    </div>
                </div>
                <div class="form-group">
                    <label>Farbe im Netzwerkplan</label>
                    <input type="color" id="scope-color" class="form-input" value="#3b82f6" style="height:36px;padding:3px;">
                </div>
                <div class="form-group">
                    <label>Beschreibung / Standort</label>
                    <input type="text" id="scope-desc" class="form-input" placeholder="Optionale Notiz zum Subnetz">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeScopeModal()">Abbrechen</button>
                <button class="btn btn-primary" onclick="saveScopeForm()">Scope speichern</button>
            </div>
        </div>
    </div>

    <!-- Modal: Ganze Gerätegruppen & einzelne Geräte im Netzwerkplan ein-/ausblenden -->
    <div id="visibility-modal" class="modal-backdrop">
        <div class="modal" style="width:650px;">
            <div class="panel-header">
                <span class="panel-title">Gerätegruppen &amp; einzelne Geräte ein-/ausblenden</span>
                <button class="btn btn-sm" onclick="closeVisibilityModal()">×</button>
            </div>
            <div class="modal-body">
                <div>
                    <div style="font-size:12px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;margin-bottom:6px;">
                        1. Ganze Gerätegruppen / Kategorien an- oder abwählen:
                    </div>
                    <div id="visibility-groups-box" style="display:flex;gap:8px;flex-wrap:wrap;background:var(--bg-card);padding:10px;border-radius:8px;border:1px solid var(--border-color);">
                        <!-- Dynamisch per JS gefüllt -->
                    </div>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-top:4px;">
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <button class="btn btn-sm" onclick="setChecklistPreset('all')">Alle auswählen</button>
                        <button class="btn btn-sm" onclick="setChecklistPreset('infra')">Nur Infrastruktur (Router/Switch/Server)</button>
                        <button class="btn btn-sm" onclick="setChecklistPreset('none')">Alle abwählen</button>
                    </div>
                    <input type="text" id="vis-search" class="search-input" placeholder="Einzelgerät suchen..." oninput="filterVisibilityChecklist()" style="width:190px;">
                </div>
                <div id="visibility-checklist" class="checklist-container">
                    <!-- Dynamisch per JS gefüllt -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeVisibilityModal()">Abbrechen</button>
                <button class="btn btn-primary" onclick="saveVisibilityChecklist()">Auswahl übernehmen</button>
            </div>
        </div>
    </div>

    <!-- Modal: Benutzer anlegen / bearbeiten (Admin) -->
    <div id="user-modal" class="modal-backdrop">
        <div class="modal">
            <div class="panel-header">
                <span class="panel-title">Benutzer &amp; Rolle konfigurieren</span>
                <button class="btn btn-sm" onclick="closeUserModal()">×</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="usr-id" value="0">
                <div class="form-group">
                    <label>Benutzername (Login) *</label>
                    <input type="text" id="usr-username" class="form-input" placeholder="z.B. m.mueller">
                </div>
                <div class="form-group">
                    <label>Anzeigename / Abteilung</label>
                    <input type="text" id="usr-fullname" class="form-input" placeholder="z.B. Max Müller (Netzwerk-Team)">
                </div>
                <div class="form-group">
                    <label>Rolle / Berechtigungsstufe *</label>
                    <select id="usr-role" class="select-input">
                        <option value="viewer">VIEWER – Betrachter (Nur Lesen &amp; Export)</option>
                        <option value="operator">OPERATOR – Techniker (Scans starten &amp; Geräte/Scopes bearbeiten)</option>
                        <option value="admin">ADMIN – Administrator (Vollzugriff inkl. DB &amp; Benutzer)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Passwort (bei Bearbeitung leer lassen, um es beizubehalten)</label>
                    <input type="password" id="usr-password" class="form-input" placeholder="Mindestens 4 Zeichen">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="usr-active" class="select-input">
                        <option value="1">Aktiv (Anmeldung erlaubt)</option>
                        <option value="0">Gesperrt (Anmeldung blockiert)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeUserModal()">Abbrechen</button>
                <button class="btn btn-primary" onclick="saveUserForm()">Benutzer speichern</button>
            </div>
        </div>
    </div>

    <!-- Modal: Eigenes Passwort ändern -->
    <div id="password-modal" class="modal-backdrop">
        <div class="modal" style="width:400px;">
            <div class="panel-header">
                <span class="panel-title">Eigenes Passwort ändern</span>
                <button class="btn btn-sm" onclick="closePasswordModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Neues Passwort *</label>
                    <input type="password" id="own-new-password" class="form-input" placeholder="Neues Passwort eingeben">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closePasswordModal()">Abbrechen</button>
                <button class="btn btn-primary" onclick="saveOwnPassword()">Passwort speichern</button>
            </div>
        </div>
    </div>

    <!-- Modal: Vollständiger SNMP-Inspektor (Switch-Ports, Traffic, MAC-Tabelle, LLDP, Drucker-Füllstände) -->
    <div id="snmp-modal" class="modal-backdrop">
        <div class="modal" style="width:820px;max-width:95vw;max-height:88vh;display:flex;flex-direction:column;">
            <div class="panel-header">
                <span class="panel-title" id="snmp-modal-title">SNMP-Geräte-Inspektor</span>
                <button class="btn btn-sm" onclick="closeSnmpModal()">×</button>
            </div>
            <div id="snmp-modal-body" class="modal-body" style="overflow-y:auto;max-height:74vh;display:flex;flex-direction:column;gap:16px;">
                <!-- Dynamisch per JS gefüllt -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="closeSnmpModal()">Schließen</button>
            </div>
        </div>
    </div>

    <!-- Modal: Alarm-Zentrale (Online/Offline Geräte-Alarme) -->
    <div id="alerts-modal" class="modal-backdrop">
        <div class="modal" style="width:760px;max-width:95vw;max-height:86vh;display:flex;flex-direction:column;">
            <div class="panel-header">
                <span class="panel-title">🔔 Geräte-Alarme &amp; Status-Ereignisse</span>
                <button class="btn btn-sm" onclick="closeAlertsModal()">×</button>
            </div>
            <div id="alerts-modal-body" class="modal-body" style="overflow-y:auto;max-height:70vh;display:flex;flex-direction:column;gap:12px;">
                <!-- Dynamisch per JS gefüllt -->
            </div>
            <div class="modal-footer" style="justify-content:space-between;">
                <div style="display:flex;gap:8px;">
                    <button class="btn btn-sm" onclick="ackAllAlerts()">✓ Alle als gelesen markieren</button>
                    <?php if ($canEdit): ?>
                        <button class="btn btn-sm btn-danger" onclick="clearAllAlerts()">🗑 Alarm-Historie leeren</button>
                    <?php endif; ?>
                </div>
                <button class="btn btn-primary" onclick="closeAlertsModal()">Schließen</button>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
        window.CURRENT_USER = <?php echo json_encode($currentUser); ?>;
    </script>
    <script src="assets/app.js"></script>
</body>
</html>
