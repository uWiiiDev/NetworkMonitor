/**
 * NetworkMonitor - Zentrale Frontend-Applikationslogik (assets/app.js)
 *
 * Steuert die gesamte interaktive Benutzeroberfläche im Browser:
 * - Globaler Anwendungszustand ('AppState') für Scopes, Geräte, Links, Alarme und Filter
 * - 6 Topologie-Ansichten: Freies Physik-Netz, Hierarchie (Oben->Unten / Links->Rechts),
 *   Radial-Ansicht, Verbindungs-Matrix sowie 19-Zoll-Switch-Rack-Panel
 * - Dynamische SVG-Geräte-Icons & automatisches Client-Clustering
 * - Interaktive 19-Zoll-Switch-Frontplatten-Ansicht mit Live-Port-Konfiguration
 *   (VLAN, PoE, Status, Beschreibung) per SSH/SNMP sowie Patchfeld-/Dosen-Dokumentation
 * - Excel-ähnliche Inventartabelle mit Spaltenfiltern, Sortierung, Gruppierung & Export
 * - Mehrsprachigkeit (DE, EN, FR, ES) und 5 Farbschemata (Midnight, Emerald, Cyberpunk, Amber, Daylight)
 */

var AppState = {
    scopes: [],
    devices: [],
    links: [],
    logs: [],
    alerts: [],
    unackAlerts: 0,
    lastToastAlertCount: 0,
    portOutlets: [],
    system: {},
    selectedScopeId: 'all',
    selectedDeviceId: null,
    viewMode: 'physics', // 'physics', 'tree_ud', 'tree_lr', 'radial', 'matrix', 'rack'
    activeTypes: {
        router: true,
        switch: true,
        server: true,
        ap: true,
        printer: true,
        workstation: true
    },
    activeCustomGroups: {}, // name => boolean
    clusterMode: false,
    autoClusterApplied: false,
    expandedClusters: {}, // parentId => boolean
    clusterMap: {},       // 'cluster_<pid>' => { parentId, parentDev, devices }
    focusDeviceId: null,  // ID eines isolierten Geräts (Fokus-Modus)
    lodEnabled: true,     // Zoom-abhängige Details (Level-of-Detail)
    lodLabelsHidden: false,
    network: null,
    nodesDataSet: null,
    edgesDataSet: null
};

var TYPE_META = {
    router:      { label: 'Router / Gateways', color: '#60a5fa', bg: '#1e3a8a' },
    switch:      { label: 'Managed Switches',  color: '#34d399', bg: '#064e3b' },
    server:      { label: 'Server / NAS',      color: '#a78bfa', bg: '#312e81' },
    ap:          { label: 'Access Points',     color: '#2dd4bf', bg: '#0f766e' },
    printer:     { label: 'Netzwerkdrucker',   color: '#fbbf24', bg: '#78350f' },
    workstation: { label: 'Clients / PCs',     color: '#3b82f6', bg: '#1e293b' }
};

// Erzeugt gestochen scharfe SVG-Icons für jeden Gerätetyp im Netzwerkplan (inkl. Switch-Stack-Darstellung)
/**
 * Erzeugt ein dynamisches SVG-Icon (als Data-URI) für einen Gerätetyp auf der Topologie-Karte
 * inklusive Online-/Offline-Statusring, Scope-Farbe und optionalem Stack-Badge.
 */
function createDeviceSvgUri(type, status, scopeColor, stackUnitsCount) {
    var meta = TYPE_META[type] || TYPE_META.workstation;
    var bg = meta.bg;
    var border = (type === 'workstation' && scopeColor) ? scopeColor : meta.color;
    var iconColor = '#f8fafc';

    if (status === 'offline') {
        border = '#ef4444';
        iconColor = '#94a3b8';
    }

    var glyph = '';
    if (type === 'router') {
        glyph = '<circle cx="32" cy="32" r="14" fill="none" stroke="' + iconColor + '" stroke-width="2.5"/>' +
                '<path d="M32 14v8M32 42v8M14 32h8M42 32h8" stroke="' + iconColor + '" stroke-width="2.5" stroke-linecap="round"/>';
    } else if (type === 'switch') {
        if (stackUnitsCount && stackUnitsCount > 1) {
            glyph = '<rect x="11" y="14" width="42" height="15" rx="2.5" fill="none" stroke="' + iconColor + '" stroke-width="2.2"/>' +
                    '<circle cx="17" cy="21.5" r="1.8" fill="#34d399"/><circle cx="24" cy="21.5" r="1.8" fill="#34d399"/><circle cx="31" cy="21.5" r="1.8" fill="#34d399"/>' +
                    '<rect x="11" y="34" width="42" height="15" rx="2.5" fill="none" stroke="' + iconColor + '" stroke-width="2.2"/>' +
                    '<circle cx="17" cy="41.5" r="1.8" fill="#34d399"/><circle cx="24" cy="41.5" r="1.8" fill="#34d399"/><circle cx="31" cy="41.5" r="1.8" fill="#34d399"/>' +
                    '<path d="M47 21h4v20h-4" fill="none" stroke="#c084fc" stroke-width="2.2"/>' +
                    '<rect x="38" y="2" width="24" height="16" rx="8" fill="#7c3aed" stroke="#0f172a" stroke-width="1.5"/>' +
                    '<text x="50" y="13" fill="#ffffff" font-family="sans-serif" font-size="10" font-weight="bold" text-anchor="middle">' + stackUnitsCount + 'x</text>';
        } else {
            glyph = '<rect x="12" y="22" width="40" height="20" rx="3" fill="none" stroke="' + iconColor + '" stroke-width="2.5"/>' +
                    '<circle cx="19" cy="32" r="2" fill="#34d399"/><circle cx="26" cy="32" r="2" fill="#34d399"/>' +
                    '<circle cx="33" cy="32" r="2" fill="#34d399"/><circle cx="40" cy="32" r="2" fill="#34d399"/>' +
                    '<path d="M45 29l4 3-4 3" fill="none" stroke="' + iconColor + '" stroke-width="2"/>';
        }
    } else if (type === 'server') {
        glyph = '<rect x="16" y="14" width="32" height="10" rx="2" fill="none" stroke="' + iconColor + '" stroke-width="2.2"/>' +
                '<rect x="16" y="27" width="32" height="10" rx="2" fill="none" stroke="' + iconColor + '" stroke-width="2.2"/>' +
                '<rect x="16" y="40" width="32" height="10" rx="2" fill="none" stroke="' + iconColor + '" stroke-width="2.2"/>' +
                '<circle cx="22" cy="19" r="1.5" fill="#34d399"/><circle cx="22" cy="32" r="1.5" fill="#34d399"/><circle cx="22" cy="45" r="1.5" fill="#34d399"/>';
    } else if (type === 'ap') {
        glyph = '<path d="M20 26a17 17 0 0 1 24 0M24 31a11 11 0 0 1 16 0" fill="none" stroke="' + iconColor + '" stroke-width="2.5" stroke-linecap="round"/>' +
                '<circle cx="32" cy="38" r="3" fill="' + iconColor + '"/>';
    } else if (type === 'printer') {
        glyph = '<path d="M20 24V16h24v8M16 24h32a4 4 0 0 1 4 4v10H12V28a4 4 0 0 1 4-4z" fill="none" stroke="' + iconColor + '" stroke-width="2.2"/>' +
                '<rect x="20" y="34" width="24" height="14" fill="' + bg + '" stroke="' + iconColor + '" stroke-width="2.2"/>';
    } else {
        glyph = '<rect x="14" y="16" width="36" height="24" rx="3" fill="none" stroke="' + iconColor + '" stroke-width="2.3"/>' +
                '<path d="M24 46h16M32 40v6" stroke="' + iconColor + '" stroke-width="2.3" stroke-linecap="round"/>';
    }

    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">' +
              '<rect x="4" y="4" width="56" height="56" rx="14" fill="' + bg + '" stroke="' + border + '" stroke-width="3.5"/>' +
              glyph + '</svg>';

    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}

// Erzeugt ein Sammel-Icon (Cluster) für gebündelte Clients an einem Switch / Gateway
/**
 * Erzeugt das SVG-Knoten-Icon für einen eingeklappten Client-Cluster (mit Gesamt- und Offline-Zähler).
 */
function createClusterSvgUri(totalCount, offlineCount) {
    var border = (offlineCount > 0) ? '#f59e0b' : '#10b981';
    var bg = '#0f172a';
    var badgeBg = (offlineCount > 0) ? '#d97706' : '#059669';

    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="68" height="68" viewBox="0 0 68 68">' +
        '<rect x="10" y="6" width="48" height="48" rx="12" fill="#1e293b" stroke="#475569" stroke-width="2"/>' +
        '<rect x="6" y="10" width="48" height="48" rx="12" fill="' + bg + '" stroke="' + border + '" stroke-width="3.2"/>' +
        '<rect x="15" y="20" width="22" height="15" rx="2" fill="none" stroke="#93c5fd" stroke-width="2"/>' +
        '<rect x="22" y="26" width="22" height="15" rx="2" fill="#0f172a" stroke="#f8fafc" stroke-width="2.2"/>' +
        '<path d="M27 45h12M33 41v4" stroke="#f8fafc" stroke-width="2" stroke-linecap="round"/>' +
        '<rect x="36" y="4" width="28" height="20" rx="10" fill="' + badgeBg + '" stroke="#0f172a" stroke-width="2"/>' +
        '<text x="50" y="18" fill="#ffffff" font-family="sans-serif" font-size="11" font-weight="bold" text-anchor="middle">' + totalCount + '</text>' +
        '</svg>';

    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}

/**
 * Zeigt eine kurze, automatisch ausblendende Status-/Benachrichtigungsmeldung (Toast) am unteren Bildschirmrand.
 */
function showToast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function () {
        t.classList.remove('show');
    }, 4000);
}

/**
 * Prüft, ob der angemeldete Benutzer Schreibrechte für Scans, Alarme und Access-Ports besitzt (operator, netadmin, admin).
 */
function userCanEdit() {
    var u = AppState.currentUser || window.CURRENT_USER || {};
    return (u.role === 'operator' || u.role === 'netadmin' || u.role === 'admin');
}

/**
 * Prüft, ob der angemeldete Benutzer Core-Switches, Trunks und VLANs verwalten darf (netadmin, admin).
 */
function userIsNetExpert() {
    var u = AppState.currentUser || window.CURRENT_USER || {};
    return (u.role === 'netadmin' || u.role === 'admin');
}

/**
 * Prüft, ob der angemeldete Benutzer voller System-Administrator ist (admin).
 */
function userIsAdmin() {
    var u = AppState.currentUser || window.CURRENT_USER || {};
    return (u.role === 'admin');
}

/**
 * Normalisiert eine MAC-Adresse auf reine Großbuchstaben-Hexadezimalzeichen für fehlertolerante Vergleiche.
 */
function normalizeMacHex(str) {
    if (!str) return '';
    return String(str).replace(/[^0-9a-fA-F]/g, '').toLowerCase();
}

/**
 * Ermittelt alle Upstream-Uplink-Ports eines Switches (Ports Richtung FRITZ!Box / Gateway-Router sowie internen Port 0),
 * über die der Switch die MAC-Adressen sämtlicher WLAN- und Router-Clients sieht.
 */
function getSwitchUpstreamPortsMap(sw, snmp) {
    var upMap = { 'port 0': true, '0': true };
    if (!sw) return upMap;
    var swId = parseInt(sw.id, 10);

    // 1. Aus Topologie-Links, bei denen dieser Switch das Ziel (Child) eines Routers/Parent-Switches ist
    for (var l = 0; l < AppState.links.length; l++) {
        var lnk = AppState.links[l];
        if (parseInt(lnk.target_device_id, 10) === swId && lnk.target_port && lnk.target_port !== 'eth0' && lnk.target_port !== 'Uplink') {
            upMap[String(lnk.target_port).trim().toLowerCase()] = true;
        }
    }

    // 2. Aus der SNMP MAC-Tabelle: Port, auf dem die MAC-Adresse eines Routers (z.B. FRITZ!Box) gesehen wird
    if (snmp && snmp.mac_table) {
        var routerMacHexMap = {};
        for (var d = 0; d < AppState.devices.length; d++) {
            if (AppState.devices[d].device_type === 'router' && AppState.devices[d].mac_address) {
                routerMacHexMap[normalizeMacHex(AppState.devices[d].mac_address)] = true;
            }
        }
        var mKeys = Object.keys(snmp.mac_table);
        for (var mk = 0; mk < mKeys.length; mk++) {
            var mHex = normalizeMacHex(mKeys[mk]);
            if (routerMacHexMap[mHex]) {
                var entry = snmp.mac_table[mKeys[mk]];
                if (entry && entry.port_name) {
                    upMap[String(entry.port_name).trim().toLowerCase()] = true;
                }
            }
        }
    }
    return upMap;
}

/**
 * Extrahiert die laufende Port-Nummer aus Bezeichnungen wie 'gi1/0/12', 'Fa0/3' oder 'Port 5'.
 */
function extractPortNumberFromName(pName) {
    if (!pName) return null;
    var s = String(pName).trim();
    var mStack = s.match(/^(?:Gi|Fa|Te|Tw|Fo|Eth|ge-|xe-|et-|Port)?\s*([1-8])\/(\d+)\/(\d+)$/i);
    if (mStack) {
        return { unit: parseInt(mStack[1], 10), port: parseInt(mStack[3], 10) };
    }
    var mNum = s.match(/(?:^|\D)(\d+)$/);
    if (mNum) {
        return { unit: 1, port: parseInt(mNum[1], 10) };
    }
    return null;
}

/**
 * Ermittelt die hinterlegte Netzwerkdosen- / Patchfeld- / Raum-Zuordnung für einen Switch-Port
 */
function getPortOutletInfo(switchId, portName, fallbackAlias) {
    if (!switchId || !portName) return null;
    var swId = parseInt(switchId, 10);
    var pLow = String(portName).trim().toLowerCase();
    var pNumObj = extractPortNumberFromName(portName);
    var list = AppState.portOutlets || [];
    var matchedRow = null;

    for (var i = 0; i < list.length; i++) {
        var r = list[i];
        if (parseInt(r.switch_id, 10) !== swId) continue;
        if (String(r.port_name || '').trim().toLowerCase() === pLow) {
            matchedRow = r;
            break;
        }
    }
    if (!matchedRow && pNumObj) {
        for (var j = 0; j < list.length; j++) {
            var r2 = list[j];
            if (parseInt(r2.switch_id, 10) !== swId) continue;
            var rNumObj = extractPortNumberFromName(r2.port_name);
            if (rNumObj && rNumObj.unit === pNumObj.unit && rNumObj.port === pNumObj.port) {
                matchedRow = r2;
                break;
            }
        }
    }

    if (matchedRow) {
        var oLbl = matchedRow.outlet_label || '';
        var pPan = matchedRow.patch_panel || '';
        var rm = matchedRow.room || '';
        var nt = matchedRow.notes || '';
        var parts = [];
        if (oLbl) parts.push(oLbl);
        if (pPan) parts.push('PF: ' + pPan);
        if (rm) parts.push(rm);
        return {
            outletLabel: oLbl,
            patchPanel: pPan,
            room: rm,
            notes: nt,
            isFromDb: true,
            badgeText: oLbl || pPan || rm,
            fullText: parts.join(' • '),
            searchText: (oLbl + ' ' + pPan + ' ' + rm + ' ' + nt).toLowerCase()
        };
    }

    var cleanAlias = fallbackAlias ? String(fallbackAlias).trim() : '';
    if (cleanAlias !== '' && cleanAlias.toLowerCase() !== 'freier port' && cleanAlias.toLowerCase() !== 'aktiv') {
        return {
            outletLabel: cleanAlias,
            patchPanel: '',
            room: '',
            notes: '',
            isFromDb: false,
            isFromAlias: true,
            badgeText: cleanAlias,
            fullText: cleanAlias,
            searchText: cleanAlias.toLowerCase()
        };
    }
    return null;
}

/**
 * Ermittelt für ein Gerät (oder eine MAC-Adresse), an welchem Switch und genau welchem Port (+ VLAN + Dose) es gesteckt ist.
 * Kombiniert Topologie-Links und sämtliche gelernten SNMP Bridge-MIB MAC-Tabellen aller Switches.
 */
function getDeviceSwitchPortInfo(dev) {
    if (!dev) return null;
    var devById = {};
    for (var i = 0; i < AppState.devices.length; i++) {
        devById[AppState.devices[i].id] = AppState.devices[i];
    }

    var primarySwitch = null;
    var primaryPort = '';
    var primaryLinkType = '';

    for (var l = 0; l < AppState.links.length; l++) {
        var lnk = AppState.links[l];
        if (parseInt(lnk.target_device_id, 10) === parseInt(dev.id, 10) && devById[lnk.source_device_id]) {
            var pDev = devById[lnk.source_device_id];
            primarySwitch = pDev;
            primaryPort = lnk.source_port || '';
            primaryLinkType = lnk.link_type || 'subnet';
            break;
        }
    }

    // Prüfe zusätzlich in allen Switch-MAC-Tabellen nach der MAC-Adresse des Geräts
    var targetMacHex = normalizeMacHex(dev.mac_address);
    var sightings = [];

    if (targetMacHex.length === 12) {
        for (var s = 0; s < AppState.devices.length; s++) {
            var sw = AppState.devices[s];
            if (!sw.snmp_ports_json || parseInt(sw.id, 10) === parseInt(dev.id, 10)) continue;
            var snmp = null;
            try { snmp = JSON.parse(sw.snmp_ports_json); } catch (e) {}
            if (!snmp || !snmp.mac_table) continue;

            var upstreamPorts = getSwitchUpstreamPortsMap(sw, snmp);
            var mKeys = Object.keys(snmp.mac_table);
            for (var mk = 0; mk < mKeys.length; mk++) {
                if (normalizeMacHex(mKeys[mk]) === targetMacHex) {
                    var mEntry = snmp.mac_table[mKeys[mk]];
                    var pName = mEntry.port_name || ('Port ' + mEntry.bridge_port);
                    // Überspringe MAC-Sichtungen auf dem Upstream-Uplink-Port Richtung FRITZ!Box/Router (sowie internem Port 0)!
                    if (upstreamPorts[String(pName).trim().toLowerCase()] && dev.device_type !== 'router') {
                        continue;
                    }
                    var pVlan = mEntry.vlan ? parseInt(mEntry.vlan, 10) : 1;
                    var pAlias = '';
                    var pMode = 'access';
                    if (snmp.interfaces) {
                        for (var infIdx = 0; infIdx < snmp.interfaces.length; infIdx++) {
                            var inf = snmp.interfaces[infIdx];
                            if (inf.name === pName || parseInt(inf.ifIndex, 10) === parseInt(mEntry.if_index, 10)) {
                                if (inf.vlan) pVlan = parseInt(inf.vlan, 10);
                                if (inf.alias) pAlias = inf.alias;
                                if (inf.port_mode === 'trunk') pMode = 'trunk';
                                break;
                            }
                        }
                    }
                    // Prüfe ob dieser Port ein Uplink zu einem anderen Switch/Router ist
                    var isUplinkPort = (pMode === 'trunk');
                    for (var ul = 0; ul < AppState.links.length; ul++) {
                        var uLnk = AppState.links[ul];
                        if (parseInt(uLnk.source_device_id, 10) === parseInt(sw.id, 10) && uLnk.source_port === pName) {
                            var childD = devById[uLnk.target_device_id];
                            if (childD && (childD.device_type === 'switch' || childD.device_type === 'router')) {
                                isUplinkPort = true;
                            }
                        }
                    }
                    sightings.push({
                        switchId: sw.id,
                        switchName: sw.custom_name || sw.snmp_sysname || sw.hostname || sw.ip_address,
                        switchIp: sw.ip_address,
                        portName: pName,
                        vlan: pVlan,
                        alias: pAlias,
                        isUplink: isUplinkPort
                    });
                    if (!primarySwitch || (primarySwitch.device_type !== 'switch' && !isUplinkPort)) {
                        primarySwitch = sw;
                        primaryPort = pName;
                        primaryLinkType = 'snmp_l2';
                    }
                }
            }
        }
    }

    if (!primarySwitch) {
        return null;
    }

    var swName = primarySwitch.custom_name || primarySwitch.snmp_sysname || primarySwitch.hostname || primarySwitch.ip_address;
    var vlanId = null;
    var portAlias = '';
    if (primarySwitch.snmp_ports_json && primaryPort) {
        try {
            var pSnmp = JSON.parse(primarySwitch.snmp_ports_json);
            if (pSnmp && pSnmp.interfaces) {
                for (var pi = 0; pi < pSnmp.interfaces.length; pi++) {
                    if (pSnmp.interfaces[pi].name === primaryPort) {
                        vlanId = pSnmp.interfaces[pi].vlan ? parseInt(pSnmp.interfaces[pi].vlan, 10) : 1;
                        portAlias = pSnmp.interfaces[pi].alias || '';
                        break;
                    }
                }
            }
        } catch (e) {}
    }

    var isSwitchPort = (primarySwitch.device_type === 'switch' || parseInt(primarySwitch.snmp_enabled || 0, 10) === 1) && primaryPort && primaryPort !== 'Uplink' && primaryLinkType !== 'subnet';
    var resolvedPortName = primaryPort || (primarySwitch.device_type === 'router' ? 'Gateway / WLAN' : '');
    var outletInfo = isSwitchPort ? getPortOutletInfo(primarySwitch.id, resolvedPortName, portAlias) : null;

    return {
        switchId: primarySwitch.id,
        switchName: swName,
        switchIp: primarySwitch.ip_address,
        switchType: primarySwitch.device_type,
        portName: resolvedPortName,
        vlan: vlanId,
        alias: portAlias,
        outletInfo: outletInfo,
        isSwitchPort: isSwitchPort,
        sightings: sightings,
        searchString: (
            swName + ' ' +
            primarySwitch.ip_address + ' ' +
            resolvedPortName +
            (vlanId ? (' vlan' + vlanId + ' vlan ' + vlanId) : '') +
            (portAlias ? (' ' + portAlias) : '') +
            (outletInfo ? (' ' + outletInfo.searchText) : '')
        ).toLowerCase()
    };
}

/**
 * Öffnet den Dialog 'MAC- & Dosen-Suche / Port-Historie' (optional mit vorbelegtem Suchbegriff).
 */
function openMacFinderModal(initialQuery) {
    var modal = document.getElementById('mac-finder-modal');
    var inp = document.getElementById('mac-finder-input');
    if (!modal || !inp) return;
    if (initialQuery !== undefined && initialQuery !== null && String(initialQuery).trim() !== '') {
        inp.value = String(initialQuery).trim();
    }
    renderMacFinderResults();
    modal.classList.add('open');
    setTimeout(function () { inp.focus(); }, 60);
}

/**
 * Schließt den Dialog 'MAC- & Dosen-Suche'.
 */
function closeMacFinderModal() {
    var modal = document.getElementById('mac-finder-modal');
    if (modal) modal.classList.remove('open');
}

/**
 * Durchsucht Geräte, Switch-MAC-Tabellen, historische Port-Sichtungen und Netzwerkdosen nach dem Suchbegriff
 * und rendert die detaillierten Trefferkarten.
 */
function renderMacFinderResults() {
    var container = document.getElementById('mac-finder-results');
    var inp = document.getElementById('mac-finder-input');
    if (!container || !inp) return;

    var qRaw = (inp.value || '').trim().toLowerCase();
    var qHex = normalizeMacHex(qRaw);
    var isMacQuery = (qHex.length >= 3 && /^[0-9a-f:.\-\s]+$/i.test(qRaw));

    var devByMacHex = {};
    var matchedItems = [];

    for (var i = 0; i < AppState.devices.length; i++) {
        var d = AppState.devices[i];
        var dMacHex = normalizeMacHex(d.mac_address);
        if (dMacHex.length === 12) {
            devByMacHex[dMacHex] = d;
        }

        var spInfo = getDeviceSwitchPortInfo(d);
        var hay = (
            (d.ip_address || '') + ' ' +
            (d.custom_name || '') + ' ' +
            (d.hostname || '') + ' ' +
            (d.mac_address || '') + ' ' +
            (d.vendor || '') + ' ' +
            (d.custom_group || '') + ' ' +
            (spInfo ? spInfo.searchString : '')
        ).toLowerCase();

        var match = false;
        if (qRaw === '') {
            // Ohne Suchbegriff: Zeige alle Geräte, die an einem Switch-Port hängen oder eine bekannte MAC haben
            match = !!(spInfo && spInfo.isSwitchPort) || (d.mac_address && d.device_type !== 'router');
        } else if (hay.indexOf(qRaw) !== -1) {
            match = true;
        } else if (isMacQuery && dMacHex.indexOf(qHex) !== -1) {
            match = true;
        }

        if (match) {
            matchedItems.push({
                kind: 'device',
                dev: d,
                mac: d.mac_address || '-',
                spInfo: spInfo
            });
        }
    }

    // Durchsuche zusätzlich alle Switch-MAC-Tabellen nach gelernten MAC-Adressen, die noch keinem IP-Host zugeordnet sind
    for (var s = 0; s < AppState.devices.length; s++) {
        var sw = AppState.devices[s];
        if (!sw.snmp_ports_json) continue;
        var snmp = null;
        try { snmp = JSON.parse(sw.snmp_ports_json); } catch (e) {}
        if (!snmp || !snmp.mac_table) continue;

        var swName = sw.custom_name || sw.snmp_sysname || sw.hostname || sw.ip_address;
        var upstreamPortsRaw = getSwitchUpstreamPortsMap(sw, snmp);
        var mKeys = Object.keys(snmp.mac_table);
        for (var mk = 0; mk < mKeys.length; mk++) {
            var rawMac = mKeys[mk];
            var mHex = normalizeMacHex(rawMac);
            if (devByMacHex[mHex]) continue; // Bereits über das bekannte Gerät erfasst

            var mEntry = snmp.mac_table[rawMac];
            var pName = mEntry.port_name || ('Port ' + mEntry.bridge_port);
            if (upstreamPortsRaw[String(pName).trim().toLowerCase()]) continue; // Uplink Richtung Router/FRITZ!Box überspringen
            var pVlan = mEntry.vlan ? parseInt(mEntry.vlan, 10) : 1;
            var hayRaw = (rawMac + ' ' + swName + ' ' + sw.ip_address + ' ' + pName + ' vlan ' + pVlan).toLowerCase();

            var rawMatch = false;
            if (qRaw === '') {
                rawMatch = true;
            } else if (hayRaw.indexOf(qRaw) !== -1) {
                rawMatch = true;
            } else if (isMacQuery && mHex.indexOf(qHex) !== -1) {
                rawMatch = true;
            }

            if (rawMatch) {
                var rawOutInfo = getPortOutletInfo(sw.id, pName, '');
                matchedItems.push({
                    kind: 'raw_mac',
                    dev: null,
                    mac: rawMac,
                    spInfo: {
                        switchId: sw.id,
                        switchName: swName,
                        switchIp: sw.ip_address,
                        switchType: 'switch',
                        portName: pName,
                        vlan: pVlan,
                        alias: '',
                        outletInfo: rawOutInfo,
                        isSwitchPort: true,
                        sightings: [{
                            switchId: sw.id,
                            switchName: swName,
                            switchIp: sw.ip_address,
                            portName: pName,
                            vlan: pVlan,
                            alias: '',
                            isUplink: false
                        }]
                    }
                });
            }
        }
    }

    // Durchsuche außerdem alle hinterlegten Netzwerkdosen / Patchfeld-Zuordnungen (auch für aktuell freie/unbelegte Dosen!)
    var coveredSwitchPorts = {};
    for (var cp = 0; cp < matchedItems.length; cp++) {
        var csp = matchedItems[cp].spInfo;
        if (csp && csp.switchId && csp.portName) {
            coveredSwitchPorts[csp.switchId + '_' + String(csp.portName).toLowerCase()] = true;
        }
    }
    var swMapById = {};
    for (var sIdx = 0; sIdx < AppState.devices.length; sIdx++) {
        swMapById[AppState.devices[sIdx].id] = AppState.devices[sIdx];
    }
    var outletsList = AppState.portOutlets || [];
    for (var oi = 0; oi < outletsList.length; oi++) {
        var po = outletsList[oi];
        var swDev = swMapById[po.switch_id];
        if (!swDev) continue;
        var poKey = po.switch_id + '_' + String(po.port_name || '').toLowerCase();
        if (coveredSwitchPorts[poKey]) continue;

        var swTitle = swDev.custom_name || swDev.snmp_sysname || swDev.hostname || swDev.ip_address;
        var poHay = (
            (po.outlet_label || '') + ' ' +
            (po.patch_panel || '') + ' ' +
            (po.room || '') + ' ' +
            (po.notes || '') + ' ' +
            (po.port_name || '') + ' ' +
            swTitle + ' ' +
            swDev.ip_address
        ).toLowerCase();

        if (qRaw === '' || poHay.indexOf(qRaw) !== -1) {
            var poVlan = 1;
            var poStatus = 'down';
            if (swDev.snmp_ports_json) {
                try {
                    var poSnmp = JSON.parse(swDev.snmp_ports_json);
                    if (poSnmp && poSnmp.interfaces) {
                        for (var pif = 0; pif < poSnmp.interfaces.length; pif++) {
                            if (String(poSnmp.interfaces[pif].name).toLowerCase() === String(po.port_name).toLowerCase()) {
                                poVlan = poSnmp.interfaces[pif].vlan ? parseInt(poSnmp.interfaces[pif].vlan, 10) : 1;
                                poStatus = poSnmp.interfaces[pif].status || 'down';
                                break;
                            }
                        }
                    }
                } catch (e) {}
            }
            matchedItems.push({
                kind: 'outlet_only',
                dev: null,
                mac: '-',
                outletRow: po,
                portStatus: poStatus,
                spInfo: {
                    switchId: swDev.id,
                    switchName: swTitle,
                    switchIp: swDev.ip_address,
                    switchType: 'switch',
                    portName: po.port_name,
                    vlan: poVlan,
                    alias: '',
                    outletInfo: getPortOutletInfo(swDev.id, po.port_name, ''),
                    isSwitchPort: true,
                    sightings: []
                }
            });
        }
    }

    // Gruppiere historische MAC-Port-Einträge nach normalisierter MAC-Adresse
    var histByMacHex = {};
    var histList = AppState.macHistory || [];
    for (var hi = 0; hi < histList.length; hi++) {
        var hRow = histList[hi];
        var hHex = normalizeMacHex(hRow.mac_address);
        if (!hHex) continue;
        if (!histByMacHex[hHex]) {
            histByMacHex[hHex] = [];
        }
        histByMacHex[hHex].push(hRow);
    }

    // Prüfe auch rein historische MAC-Einträge (Geräte, die früher gesteckt waren und aktuell komplett abgesteckt sind)
    var seenMacHexInMatches = {};
    for (var mi = 0; mi < matchedItems.length; mi++) {
        var mh = normalizeMacHex(matchedItems[mi].mac);
        if (mh) seenMacHexInMatches[mh] = true;
    }
    var hMacKeys = Object.keys(histByMacHex);
    for (var hk = 0; hk < hMacKeys.length; hk++) {
        var hKey = hMacKeys[hk];
        if (seenMacHexInMatches[hKey] || devByMacHex[hKey]) continue;
        var latestH = histByMacHex[hKey][0];
        var hayHist = ((latestH.mac_address || '') + ' ' + (latestH.ip_address || '') + ' ' + (latestH.device_name || '') + ' ' + (latestH.vendor || '') + ' ' + (latestH.switch_name || '') + ' ' + (latestH.port_name || '')).toLowerCase();
        var histMatch = false;
        if (qRaw === '') {
            histMatch = true;
        } else if (hayHist.indexOf(qRaw) !== -1) {
            histMatch = true;
        } else if (isMacQuery && hKey.indexOf(qHex) !== -1) {
            histMatch = true;
        }
        if (histMatch) {
            matchedItems.push({
                kind: 'historical_only',
                dev: null,
                mac: latestH.mac_address,
                histLatest: latestH,
                spInfo: null
            });
        }
    }

    // Sortiere Treffer mit echtem Switch-Port zuerst
    matchedItems.sort(function (a, b) {
        var aSw = (a.spInfo && a.spInfo.isSwitchPort) ? 2 : (a.kind === 'historical_only' ? 1 : 0);
        var bSw = (b.spInfo && b.spInfo.isSwitchPort) ? 2 : (b.kind === 'historical_only' ? 1 : 0);
        if (aSw !== bSw) return bSw - aSw;
        var aIp = a.dev ? ipToNumeric(a.dev.ip_address) : 9999999999;
        var bIp = b.dev ? ipToNumeric(b.dev.ip_address) : 9999999999;
        return aIp - bIp;
    });

    if (matchedItems.length === 0) {
        var watchBtnHtml = '';
        if (userCanEdit() && qHex.length === 12) {
            watchBtnHtml = '<div style="margin-top:12px;">' +
                '<button class="btn btn-sm btn-primary" onclick="closeMacFinderModal(); openAlertsModalWithWatchMac(\'' + escapeHtml(inp.value.trim()) + '\')">🎯 Alarm setzen, sobald diese MAC-Adresse (' + escapeHtml(inp.value.trim()) + ') im Netz auftaucht</button>' +
            '</div>';
        }
        container.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-secondary);background:rgba(15,23,42,0.5);border-radius:8px;border:1px solid var(--border-color);">' +
            'Keine passende MAC-Adresse, IP oder Switch-Port (auch nicht in der Port-Historie) für <b>' + escapeHtml(inp.value) + '</b> gefunden.' +
            watchBtnHtml +
        '</div>';
        return;
    }

    var html = '<div style="font-size:12px;color:var(--text-secondary);font-weight:600;">' + matchedItems.length + ' Treffer gefunden' + (qRaw === '' ? ' (Übersicht aller bekannten MAC-Adressen, aktuellen Steckplätze &amp; Port-Historie)' : '') + ':</div>';

    for (var r = 0; r < matchedItems.length; r++) {
        var item = matchedItems[r];
        var dObj = item.dev;
        var sp = item.spInfo;
        var itemMacHex = normalizeMacHex(item.mac);
        var itemHistory = itemMacHex && histByMacHex[itemMacHex] ? histByMacHex[itemMacHex] : [];

        var dTitle = dObj
            ? (dObj.custom_name || dObj.hostname || dObj.snmp_sysname || dObj.ip_address)
            : (item.histLatest && item.histLatest.device_name ? item.histLatest.device_name : 'Layer-2 Gerät (MAC in Switch-Tabelle)');
        var dIp = dObj ? dObj.ip_address : (item.histLatest && item.histLatest.ip_address ? item.histLatest.ip_address : 'Keine IP im ARP');
        var dVendor = dObj ? (dObj.vendor || 'Unbekannt') : (item.histLatest && item.histLatest.vendor ? item.histLatest.vendor : 'Über Switch Bridge-MIB gelernt');
        var stBadge = dObj
            ? ('<span class="badge badge-' + dObj.status + '">' + escapeHtml(dObj.status.toUpperCase()) + '</span>')
            : (item.kind === 'historical_only'
                ? '<span class="badge" style="background:rgba(168,85,247,0.22);border:1px solid #a855f7;color:#e9d5ff;">🕓 HISTORISCH</span>'
                : '<span class="badge badge-snmp">L2 MAC AKTIV</span>');
        var coreTag = (dObj && parseInt(dObj.is_core || 0, 10) === 1)
            ? ' <span class="badge" style="background:rgba(168,85,247,0.25);border:1px solid #a855f7;color:#e9d5ff;">🔒 CORE-SWITCH</span>'
            : '';

        if (item.kind === 'outlet_only') {
            var outObj = sp.outletInfo || {};
            var isPortUp = (item.portStatus === 'up');
            html += '<div style="background:var(--bg-panel);border:1px solid rgba(56,189,248,0.45);border-radius:8px;padding:12px;">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">' +
                    '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">' +
                        '<span class="badge" style="background:rgba(14,165,233,0.22);border:1px solid #38bdf8;color:#7dd3fc;">🔌 NETZWERKDOSE / PATCHFELD</span>' +
                        '<span style="font-weight:800;font-size:14.5px;color:#f8fafc;">' + escapeHtml(outObj.outletLabel || ('Port ' + sp.portName)) + '</span>' +
                        (outObj.patchPanel ? '<span class="badge" style="background:rgba(148,163,184,0.18);color:#e2e8f0;font-family:monospace;">Patchfeld: ' + escapeHtml(outObj.patchPanel) + '</span>' : '') +
                        (outObj.room ? '<span class="badge" style="background:rgba(245,158,11,0.18);border:1px solid rgba(245,158,11,0.4);color:#fde68a;">📍 Raum: ' + escapeHtml(outObj.room) + '</span>' : '') +
                        (outObj.notes ? '<span style="font-size:12px;color:var(--text-secondary);">(' + escapeHtml(outObj.notes) + ')</span>' : '') +
                        (isPortUp ? '<span class="badge badge-online">LINK UP</span>' : '<span class="badge badge-offline">⚪ FREIE DOSE (LINK DOWN)</span>') +
                    '</div>' +
                    '<button class="btn btn-sm btn-primary" onclick="closeMacFinderModal(); openSwitchGraphicModal(' + sp.switchId + ', false, \'' + escapeHtml(sp.portName) + '\');">🎛 Dose am Switch öffnen / VLAN ändern</button>' +
                '</div>' +
                '<div style="background:rgba(14,165,233,0.1);border:1px solid rgba(56,189,248,0.3);border-radius:6px;padding:7px 11px;margin-top:6px;font-size:12.5px;">' +
                    '<span style="color:#7dd3fc;font-weight:700;">Aufgelegt auf Switch:</span> ' +
                    '<b style="color:#f8fafc;">' + escapeHtml(sp.switchName) + '</b> <span style="font-family:monospace;color:#93c5fd;">(' + escapeHtml(sp.switchIp) + ')</span> ' +
                    '➔ <span class="badge" style="background:#0c4a6e;border:1px solid #38bdf8;color:#bae6fd;font-family:monospace;font-size:12.5px;padding:3px 8px;">Port ' + escapeHtml(sp.portName) + '</span>' +
                    (sp.vlan ? (' • <b style="color:#fde68a;">VLAN ' + sp.vlan + '</b>') : '') +
                '</div>' +
            '</div>';
            continue;
        }

        var portSectionHtml = '';
        if (sp && sp.isSwitchPort) {
            var vlanTxt = sp.vlan ? (' • <b style="color:#fde68a;">VLAN ' + sp.vlan + '</b>') : '';
            var aliasTxt = sp.alias ? (' <span style="color:#cbd5e1;">(' + escapeHtml(sp.alias) + ')</span>') : '';
            var outletBadgeHtml = '';
            if (sp.outletInfo && sp.outletInfo.fullText) {
                outletBadgeHtml = ' • <span class="badge" style="background:rgba(14,165,233,0.22);border:1px solid #38bdf8;color:#7dd3fc;font-size:12px;padding:2px 8px;">🔌 Dose: <b>' + escapeHtml(sp.outletInfo.fullText) + '</b></span>';
            }
            portSectionHtml += '<div style="background:rgba(16,185,129,0.14);border:1px solid rgba(16,185,129,0.45);border-radius:6px;padding:7px 11px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-top:6px;">' +
                '<div style="font-size:12.5px;">' +
                    '<span style="color:#34d399;font-weight:700;">🟢 Aktuell gesteckt auf Switch:</span> ' +
                    '<b style="color:#f8fafc;">' + escapeHtml(sp.switchName) + '</b> <span style="font-family:monospace;color:#93c5fd;">(' + escapeHtml(sp.switchIp) + ')</span> ' +
                    '➔ <span class="badge" style="background:#065f46;border:1px solid #10b981;color:#a7f3d0;font-family:monospace;font-size:12.5px;padding:3px 8px;">Port ' + escapeHtml(sp.portName) + '</span>' +
                    vlanTxt + outletBadgeHtml + aliasTxt +
                '</div>' +
                '<button class="btn btn-sm btn-primary" onclick="closeMacFinderModal(); openSwitchGraphicModal(' + sp.switchId + ', false, \'' + escapeHtml(sp.portName) + '\');">🎛 Port am Switch öffnen / VLAN ändern</button>' +
            '</div>';

            if (sp.sightings && sp.sightings.length > 1) {
                for (var sg = 0; sg < sp.sightings.length; sg++) {
                    var sItem = sp.sightings[sg];
                    if (sItem.switchId === sp.switchId && sItem.portName === sp.portName) continue;
                    portSectionHtml += '<div style="font-size:11.5px;color:var(--text-secondary);margin-top:4px;padding-left:6px;">' +
                        '🔗 Zusätzlich gesehen auf <b>' + escapeHtml(sItem.switchName) + '</b> an Port <code>' + escapeHtml(sItem.portName) + '</code> (VLAN ' + sItem.vlan + (sItem.isUplink ? ' – Uplink/Trunk' : '') + ')' +
                    '</div>';
                }
            }
        } else if (sp) {
            portSectionHtml += '<div style="font-size:12px;color:var(--text-secondary);margin-top:6px;">' +
                '🌐 Verbunden über Gateway / Subnetz: <b>' + escapeHtml(sp.switchName) + '</b> (' + escapeHtml(sp.switchIp) + ')' +
            '</div>';
        } else if (item.kind !== 'historical_only') {
            portSectionHtml += '<div style="font-size:12px;color:var(--text-secondary);margin-top:6px;">Kein untergeordneter Switch-Port (Root-/Infrastruktur-Knoten).</div>';
        }

        // Historische Port-Einträge für diese MAC-Adresse rendern
        if (itemHistory.length > 0) {
            var histRowsHtml = '';
            for (var hIdx = 0; hIdx < itemHistory.length; hIdx++) {
                var he = itemHistory[hIdx];
                var isCurrentSame = (sp && sp.isSwitchPort && parseInt(sp.switchId, 10) === parseInt(he.switch_id, 10) && String(sp.portName) === String(he.port_name) && hIdx === 0);
                var statusPill = isCurrentSame
                    ? '<span class="badge" style="background:rgba(16,185,129,0.2);color:#34d399;font-size:10px;">AKTUELL</span>'
                    : '<span class="badge" style="background:rgba(168,85,247,0.2);color:#e9d5ff;font-size:10px;">FRÜHERER PORT</span>';
                histRowsHtml += '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;padding:4px 8px;background:rgba(15,23,42,0.65);border:1px solid rgba(148,163,184,0.2);border-radius:5px;font-size:11.5px;">' +
                    '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">' +
                        statusPill +
                        '<b style="color:#f8fafc;">' + escapeHtml(he.switch_name || he.switch_ip || ('Switch #' + he.switch_id)) + '</b>' +
                        '<span style="font-family:monospace;color:#93c5fd;">Port <b>' + escapeHtml(he.port_name) + '</b></span>' +
                        '<span style="font-family:monospace;color:#fcd34d;">VLAN ' + (he.vlan_id || 1) + '</span>' +
                    '</div>' +
                    '<div style="display:flex;align-items:center;gap:8px;color:var(--text-secondary);font-size:11px;">' +
                        '<span>🕓 ' + escapeHtml(he.first_seen || '-') + ' bis <b>' + escapeHtml(he.last_seen || '-') + '</b> (' + (he.sightings_count || 1) + '× gesehen)</span>' +
                        '<button type="button" class="btn btn-sm" style="padding:1px 6px;font-size:10.5px;" onclick="closeMacFinderModal(); openSwitchGraphicModal(' + parseInt(he.switch_id, 10) + ', false, \'' + escapeHtml(he.port_name) + '\');">🎛 Port zeigen</button>' +
                    '</div>' +
                '</div>';
            }
            portSectionHtml += '<div style="margin-top:8px;display:flex;flex-direction:column;gap:4px;">' +
                '<div style="font-size:11px;font-weight:700;color:#c084fc;text-transform:uppercase;">🕓 Port-Historie dieser MAC-Adresse (' + itemHistory.length + ' Stationen):</div>' +
                histRowsHtml +
            '</div>';
        }

        html += '<div style="background:var(--bg-panel);border:1px solid var(--border-color);border-radius:8px;padding:12px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">' +
                '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">' +
                    stBadge +
                    '<span style="font-weight:700;font-size:14px;color:#f8fafc;">' + escapeHtml(dTitle) + '</span>' +
                    coreTag +
                    '<span style="font-family:monospace;font-weight:700;color:#93c5fd;">IP: ' + escapeHtml(dIp) + '</span>' +
                    (dIp && dIp !== 'Keine IP im ARP' ? '<button type="button" class="btn btn-sm" style="padding:1px 5px;font-size:10px;" onclick="copyToClipboard(\'' + escapeHtml(dIp) + '\', \'IP-Adresse\')" title="IP kopieren">📋</button>' : '') +
                    '<span class="badge" style="background:rgba(59,130,246,0.18);border:1px solid rgba(59,130,246,0.4);color:#bfdbfe;font-family:monospace;font-size:12px;">MAC: ' + escapeHtml(item.mac) + '</span>' +
                    (item.mac && item.mac !== '-' ? '<button type="button" class="btn btn-sm" style="padding:1px 5px;font-size:10px;" onclick="copyToClipboard(\'' + escapeHtml(item.mac) + '\', \'MAC-Adresse\')" title="MAC kopieren">📋</button>' : '') +
                    '<span style="font-size:12px;color:var(--text-secondary);">(' + escapeHtml(dVendor) + ')</span>' +
                '</div>' +
                (dObj ? ('<button class="btn btn-sm" onclick="closeMacFinderModal(); selectDevice(' + dObj.id + ');">📍 Im Plan zeigen</button>') : '') +
            '</div>' +
            portSectionHtml +
        '</div>';
    }

    container.innerHTML = html;
}

/**
 * Lädt den gesamten aktuellen Netzwerk-, Topologie-, Alarm- und Systemzustand vom Backend ('api.php?action=topology')
 * und aktualisiert alle Ansichten.
 */
function loadTopologyData(callback) {
    fetch('api.php?action=topology&_t=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) {
            if (r.status === 401) {
                window.location.href = 'login.php';
                throw new Error('Bitte anmelden.');
            }
            return r.json();
        })
        .then(function (data) {
            if (!data.ok) {
                showToast('Fehler: ' + (data.error || 'Unbekannt'));
                return;
            }
            AppState.currentUser = data.current_user || window.CURRENT_USER || {};
            AppState.scopes = data.scopes || [];
            AppState.devices = data.devices || [];
            AppState.links = data.links || [];
            AppState.logs = data.logs || [];
            AppState.alerts = data.alerts || [];
            AppState.unackAlerts = parseInt(data.unack_alerts || 0, 10);
            AppState.macHistory = data.mac_history || [];
            AppState.macWatchlist = data.mac_watchlist || [];
            AppState.portOutlets = data.port_outlets || [];
            AppState.system = data.system || {};
            AppState.admin = data.admin || null;

            updateTopbarAlertsBadge();

            // Initialisiere benutzerdefinierte Gruppen
            for (var i = 0; i < AppState.devices.length; i++) {
                var cg = AppState.devices[i].custom_group;
                if (cg && AppState.activeCustomGroups[cg] === undefined) {
                    AppState.activeCustomGroups[cg] = true;
                }
            }

            // Bei großen Netzwerken (>= 25 Geräte) beim ersten Laden automatisch Client-Clustering aktivieren
            if (!AppState.autoClusterApplied && AppState.devices.length >= 25) {
                AppState.clusterMode = true;
                AppState.autoClusterApplied = true;
            }
            updateToolbarQuickButtons();

            renderScopesList();
            renderGroupFilterBar();
            renderCurrentView();
            renderInventoryTable();
            renderSystemInfo();
            if (userIsAdmin() && AppState.admin) {
                renderAdminSection();
            }
            var sbRight = document.getElementById('sidebar-right');
            if (AppState.selectedDeviceId && sbRight && !sbRight.classList.contains('collapsed')) {
                selectDevice(AppState.selectedDeviceId);
            }
            if (typeof callback === 'function') {
                callback();
            }
        })
        .catch(function (err) {
            showToast('Verbindungsfehler: ' + err.message);
        });
}

/**
 * Öffnet oder schließt ein Dropdown-Menü in der oberen Werkzeugleiste.
 */
function toggleDropdown(menuId, ev) {
    if (ev) {
        ev.stopPropagation();
    }
    var menu = document.getElementById(menuId);
    if (!menu) return;
    var isOpen = menu.classList.contains('open');
    closeAllDropdowns();
    if (!isOpen) {
        menu.classList.add('open');
    }
}

/**
 * Schließt alle geöffneten Dropdown-Menüs.
 */
function closeAllDropdowns() {
    var menus = document.querySelectorAll('.dropdown-menu.open');
    for (var i = 0; i < menus.length; i++) {
        menus[i].classList.remove('open');
    }
}

/**
 * Klappt die linke Seitenleiste (Scopes & Scan-Historie) ein oder aus.
 */
function toggleLeftSidebar() {
    var sb = document.getElementById('sidebar-left');
    if (sb) {
        sb.classList.toggle('collapsed');
        setTimeout(function () { fitNetworkGraph(); }, 100);
    }
}

/**
 * Blendet das schwebende Geräte-Details-Fenster (Inspector) ein oder aus.
 */
function toggleRightSidebar(forceOpen) {
    var sb = document.getElementById('sidebar-right');
    if (!sb) return;
    if (forceOpen === true) {
        sb.classList.remove('collapsed');
        sb.classList.remove('minimized');
        var minBtn = document.getElementById('btn-min-inspector');
        if (minBtn) minBtn.textContent = '—';
    } else if (forceOpen === false) {
        sb.classList.add('collapsed');
        AppState.selectedDeviceId = null;
        AppState.selectedClusterId = null;
    } else {
        sb.classList.toggle('collapsed');
    }
}

/**
 * Minimiert oder maximiert das schwebende Geräte-Details-Fenster auf seine Titelleiste.
 */
function toggleMinimizeInspector() {
    var sb = document.getElementById('sidebar-right');
    var minBtn = document.getElementById('btn-min-inspector');
    if (!sb) return;
    var isMin = sb.classList.toggle('minimized');
    if (minBtn) {
        minBtn.textContent = isMin ? '▢' : '—';
        minBtn.title = isMin ? 'Fenster wieder aufklappen' : 'Fenster auf Titelleiste minimieren';
    }
}

/**
 * Macht das schwebende Geräte-Details-Fenster per Maus an seiner Kopfzeile frei verschiebbar.
 */
function initFloatingInspectorDrag() {
    var win = document.getElementById('sidebar-right');
    var handle = document.getElementById('inspector-drag-handle');
    if (!win || !handle) return;

    var isDragging = false;
    var startX = 0;
    var startY = 0;
    var origLeft = 0;
    var origTop = 0;

    function onStart(clientX, clientY) {
        isDragging = true;
        var rect = win.getBoundingClientRect();
        startX = clientX;
        startY = clientY;
        origLeft = rect.left;
        origTop = rect.top;
        win.style.right = 'auto';
        win.style.left = origLeft + 'px';
        win.style.top = origTop + 'px';
    }

    function onMove(clientX, clientY) {
        if (!isDragging) return;
        var dx = clientX - startX;
        var dy = clientY - startY;
        var newLeft = Math.max(8, Math.min(window.innerWidth - 120, origLeft + dx));
        var newTop = Math.max(58, Math.min(window.innerHeight - 60, origTop + dy));
        win.style.left = newLeft + 'px';
        win.style.top = newTop + 'px';
    }

    function onEnd() {
        isDragging = false;
    }

    handle.addEventListener('mousedown', function (e) {
        if (e.button !== 0) return;
        onStart(e.clientX, e.clientY);
        e.preventDefault();
    });
    document.addEventListener('mousemove', function (e) {
        onMove(e.clientX, e.clientY);
    });
    document.addEventListener('mouseup', onEnd);

    handle.addEventListener('touchstart', function (e) {
        if (e.touches && e.touches.length === 1) {
            onStart(e.touches[0].clientX, e.touches[0].clientY);
        }
    }, { passive: true });
    document.addEventListener('touchmove', function (e) {
        if (isDragging && e.touches && e.touches.length === 1) {
            onMove(e.touches[0].clientX, e.touches[0].clientY);
        }
    }, { passive: true });
    document.addEventListener('touchend', onEnd);
}

/**
 * Rendert die Liste der IP-Scopes in der linken Seitenleiste sowie die kurze Scan-Historie.
 */
function renderScopesList() {
    var container = document.getElementById('scope-list');
    var selectEl = document.getElementById('toolbar-scope-select');
    var html = '';
    var selectHtml = '<option value="all"' + (AppState.selectedScopeId === 'all' ? ' selected' : '') + '>Scope: Alle Netze (' + AppState.devices.length + ' Geräte)</option>';

    var allActive = (AppState.selectedScopeId === 'all') ? 'active' : '';
    html += '<div class="scope-card ' + allActive + '" onclick="filterByScope(\'all\')">' +
            '<div class="scope-header">' +
            '<div class="scope-name"><span class="scope-dot" style="background:#3b82f6"></span>Alle Netzwerk-Scopes</div>' +
            '<span class="scope-cidr">' + AppState.scopes.length + ' Netze</span>' +
            '</div>' +
            '<div class="scope-meta"><span>Gesamt: ' + AppState.devices.length + ' Geräte erkannt</span></div>' +
            '</div>';

    for (var i = 0; i < AppState.scopes.length; i++) {
        var s = AppState.scopes[i];
        var isSel = (String(AppState.selectedScopeId) === String(s.id)) ? 'active' : '';
        var isOptSel = (String(AppState.selectedScopeId) === String(s.id)) ? ' selected' : '';
        var lastScan = s.last_scan_at ? s.last_scan_at : 'Noch nie gescannt';
        var vlanBadge = s.vlan_id ? ('VLAN ' + s.vlan_id + ' • ') : '';

        selectHtml += '<option value="' + s.id + '"' + isOptSel + '>' + escapeHtml(s.name) + ' (' + escapeHtml(s.cidr) + ')</option>';

        html += '<div class="scope-card ' + isSel + '" onclick="filterByScope(' + s.id + ')">' +
                '<div class="scope-header">' +
                '<div class="scope-name"><span class="scope-dot" style="background:' + (s.color || '#3b82f6') + '"></span>' + escapeHtml(s.name) + '</div>' +
                '<span class="scope-cidr">' + escapeHtml(s.cidr) + '</span>' +
                '</div>' +
                '<div class="scope-meta">' +
                '<span>' + vlanBadge + 'GW: ' + escapeHtml(s.gateway_ip || '-') + '</span>' +
                '<span>SNMP: ' + escapeHtml(s.snmp_community || 'public') + '</span>' +
                '</div>' +
                '<div class="scope-meta">' +
                '<span>Scan: ' + escapeHtml(lastScan) + '</span>' +
                '</div>' +
                (userCanEdit() ? (
                    '<div class="scope-actions" onclick="event.stopPropagation();">' +
                    '<button class="btn btn-sm btn-primary" onclick="triggerScan(' + s.id + ', this)">Jetzt scannen</button>' +
                    '<button class="btn btn-sm" onclick="openScopeModal(' + s.id + ')">Bearbeiten</button>' +
                    '<button class="btn btn-sm btn-danger" onclick="deleteScope(' + s.id + ')">×</button>' +
                    '</div>'
                ) : '') +
                '</div>';
    }

    if (container) container.innerHTML = html;
    if (selectEl) selectEl.innerHTML = selectHtml;
}

/**
 * Filtert Topologie-Karte und Inventarliste auf einen bestimmten IP-Scope (oder 'all' für alle Scopes).
 */
function filterByScope(scopeId) {
    AppState.selectedScopeId = scopeId;
    renderScopesList();
    renderGroupFilterBar();
    renderCurrentView();
    renderInventoryTable();
}

/**
 * Rendert die Gruppen-Auswahl kompakt in das Dropdown-Menü #dd-groups-list
 */
function renderGroupFilterBar() {
    var listEl = document.getElementById('dd-groups-list');
    if (!listEl) return;

    var countsByType = { router: 0, switch: 0, server: 0, ap: 0, printer: 0, workstation: 0 };
    var countsByCustom = {};

    for (var i = 0; i < AppState.devices.length; i++) {
        var d = AppState.devices[i];
        if (AppState.selectedScopeId !== 'all' && String(d.scope_id) !== String(AppState.selectedScopeId)) {
            continue;
        }
        if (countsByType[d.device_type] !== undefined) {
            countsByType[d.device_type]++;
        }
        if (d.custom_group) {
            countsByCustom[d.custom_group] = (countsByCustom[d.custom_group] || 0) + 1;
        }
    }

    var html = '';
    var typeKeys = Object.keys(TYPE_META);
    for (var t = 0; t < typeKeys.length; t++) {
        var tk = typeKeys[t];
        var meta = TYPE_META[tk];
        var cnt = countsByType[tk] || 0;
        var checked = (AppState.activeTypes[tk] !== false) ? 'checked' : '';
        html += '<label class="dropdown-check-item">' +
                '<div style="display:flex;align-items:center;gap:8px;">' +
                    '<input type="checkbox" ' + checked + ' onchange="toggleTypeGroup(\'' + tk + '\')">' +
                    '<span class="scope-dot" style="background:' + meta.color + '"></span>' +
                    '<span>' + escapeHtml(meta.label) + '</span>' +
                '</div>' +
                '<span class="group-chip-count">' + cnt + '</span>' +
                '</label>';
    }

    var customKeys = Object.keys(countsByCustom);
    if (customKeys.length > 0) {
        html += '<div class="dropdown-divider"></div><div class="dropdown-header">Eigene Gruppen</div>';
        for (var c = 0; c < customKeys.length; c++) {
            var cg = customKeys[c];
            var cgChecked = (AppState.activeCustomGroups[cg] !== false) ? 'checked' : '';
            html += '<label class="dropdown-check-item">' +
                    '<div style="display:flex;align-items:center;gap:8px;">' +
                        '<input type="checkbox" ' + cgChecked + ' onchange="toggleCustomGroup(' + JSON.stringify(cg).replace(/"/g, '&quot;') + ')">' +
                        '<span class="scope-dot" style="background:#f59e0b"></span>' +
                        '<span>' + escapeHtml(cg) + '</span>' +
                    '</div>' +
                    '<span class="group-chip-count">' + countsByCustom[cg] + '</span>' +
                    '</label>';
        }
    }

    listEl.innerHTML = html;
}

/**
 * Schaltet die Sichtbarkeit einer Gerätekategorie (z.B. 'workstation', 'printer') auf der Karte um.
 */
function toggleTypeGroup(typeKey) {
    AppState.activeTypes[typeKey] = !AppState.activeTypes[typeKey];
    updateToolbarQuickButtons();
    renderGroupFilterBar();
    renderCurrentView();
}

/**
 * Schaltet die Sichtbarkeit einer benutzerdefinierten Gerätegruppe um.
 */
function toggleCustomGroup(groupName) {
    if (AppState.activeCustomGroups[groupName] === undefined) {
        AppState.activeCustomGroups[groupName] = false;
    } else {
        AppState.activeCustomGroups[groupName] = !AppState.activeCustomGroups[groupName];
    }
    renderGroupFilterBar();
    renderCurrentView();
}

/**
 * Aktualisiert den aktiven Hervorhebungszustand der Schnellfilter-Buttons ('Nur Infrastruktur', 'Client-Cluster').
 */
function updateToolbarQuickButtons() {
    var infraBtn = document.getElementById('btn-infra-only');
    if (infraBtn) {
        var isInfraOnly = (AppState.activeTypes.workstation === false);
        infraBtn.style.background = isInfraOnly ? 'rgba(59, 130, 246, 0.28)' : '';
        infraBtn.style.borderColor = isInfraOnly ? '#3b82f6' : '';
        infraBtn.style.color = isInfraOnly ? '#93c5fd' : '';
        infraBtn.textContent = isInfraOnly ? '🏢 Nur Infrastruktur (Aktiv)' : '🏢 Nur Infrastruktur';
    }

    var clusterBtn = document.getElementById('btn-cluster-mode');
    if (clusterBtn) {
        var isClust = !!AppState.clusterMode;
        clusterBtn.style.background = isClust ? 'rgba(16, 185, 129, 0.24)' : '';
        clusterBtn.style.borderColor = isClust ? '#10b981' : '';
        clusterBtn.style.color = isClust ? '#6ee7b7' : '';
        clusterBtn.textContent = isClust ? '📦 Cluster (Aktiv)' : '📦 Cluster';
    }
}

/**
 * Schaltet den Modus 'Nur Infrastruktur' (zeigt nur Router, Switches, APs und Server) ein oder aus.
 */
function toggleInfraOnlyMode() {
    if (AppState.activeTypes.workstation !== false) {
        AppState.activeTypes.workstation = false;
        showToast('🏢 Infrastruktur-Fokus aktiv: Normale Clients ausgeblendet (Router, Switches, Server, APs & Drucker sichtbar).');
    } else {
        AppState.activeTypes.workstation = true;
        showToast('💻 Alle Geräte inklusive Workstations/Clients wieder eingeblendet.');
    }
    updateToolbarQuickButtons();
    renderGroupFilterBar();
    renderCurrentView();
    setTimeout(function () { fitNetworkGraph(); }, 80);
}

/**
 * Schaltet die automatische Gruppierung von Endgeräten in übersichtliche Sammel-Cluster um.
 */
function toggleClusterMode() {
    AppState.clusterMode = !AppState.clusterMode;
    updateToolbarQuickButtons();
    if (AppState.clusterMode) {
        showToast('📦 Client-Clustering aktiv: Endgeräte werden pro Switch/Gateway gebündelt (Doppelklick klappt Cluster auf).');
    } else {
        showToast('📦 Client-Clustering deaktiviert: Alle Endgeräte werden einzeln dargestellt.');
    }
    renderCurrentView();
    setTimeout(function () { fitNetworkGraph(); }, 80);
}

/**
 * Klappt alle Client-Cluster auf der Topologie-Karte gleichzeitig auf.
 */
function expandAllClusters() {
    AppState.clusterMode = true;
    for (var i = 0; i < AppState.devices.length; i++) {
        AppState.expandedClusters[AppState.devices[i].id] = true;
    }
    updateToolbarQuickButtons();
    renderCurrentView();
    setTimeout(function () { fitNetworkGraph(); }, 80);
    showToast('📂 Alle Client-Cluster aufgeklappt.');
}

/**
 * Klappt alle geöffneten Client-Cluster wieder kompakt zusammen.
 */
function collapseAllClusters() {
    AppState.clusterMode = true;
    AppState.expandedClusters = {};
    updateToolbarQuickButtons();
    renderCurrentView();
    setTimeout(function () { fitNetworkGraph(); }, 80);
    showToast('📁 Alle Clients pro Switch/Gateway zu Clustern gebündelt.');
}

/**
 * Klappt den Client-Cluster eines einzelnen Routers/Switches/APs gezielt auf oder zu.
 */
function toggleSingleCluster(parentId, expandFlag) {
    if (expandFlag) {
        AppState.expandedClusters[parentId] = true;
    } else {
        delete AppState.expandedClusters[parentId];
    }
    renderCurrentView();
    setTimeout(function () { fitNetworkGraph(); }, 80);
}

/**
 * Aktiviert oder deaktiviert das automatische Ausblenden von Textlabels beim Herauszoomen (Level-of-Detail).
 */
function toggleLodMode(enabled) {
    AppState.lodEnabled = !!enabled;
    applyZoomLod(true);
}

/**
 * Aktiviert den Nachbarschafts-Fokus auf ein einzelnes Gerät (hebt nur direkt verbundene Nachbarn hervor).
 */
function setFocusDevice(devId) {
    AppState.focusDeviceId = devId ? parseInt(devId, 10) : null;
    renderCurrentView();
    if (AppState.selectedDeviceId) {
        selectDevice(AppState.selectedDeviceId);
    }
    setTimeout(function () { fitNetworkGraph(); }, 80);
}

/**
 * Speichert die aktuellen Knoten-Positionen der Topologie-Karte dauerhaft in der Datenbank.
 */
function saveGraphPositions() {
    if (!AppState.network) {
        showToast('Positionen können nur in den Graph-Ansichten (1 bis 4) gespeichert werden.');
        return;
    }
    var rawPos = AppState.network.getPositions();
    var posList = [];
    var byId = {};
    for (var i = 0; i < AppState.devices.length; i++) {
        byId[AppState.devices[i].id] = AppState.devices[i];
    }
    for (var k in rawPos) {
        var numId = parseInt(k, 10);
        if (!isNaN(numId) && numId > 0 && String(k).indexOf('cluster_') === -1) {
            var rx = Math.round(rawPos[k].x);
            var ry = Math.round(rawPos[k].y);
            posList.push({ id: numId, x: rx, y: ry });
            if (byId[numId]) {
                byId[numId].pos_x = rx;
                byId[numId].pos_y = ry;
            }
        }
    }
    if (posList.length === 0) {
        showToast('Keine speicherbaren Geräteknoten im aktuellen Plan.');
        return;
    }
    fetch('api.php?action=save_layout_positions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ positions: posList })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            showToast('📌 Layout fixiert: Positionen von ' + res.saved + ' Geräten dauerhaft gespeichert!');
        } else {
            showToast('Fehler: ' + (res.error || 'Unbekannt'));
        }
    });
}

/**
 * Löscht gespeicherte Knoten-Koordinaten und ordnet den Netzwerkplan wieder automatisch an.
 */
function resetGraphPositions() {
    if (!confirm('Gespeicherte Knoten-Positionen zurücksetzen und Layout neu berechnen?')) return;
    fetch('api.php?action=reset_layout_positions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scope_id: AppState.selectedScopeId })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            for (var i = 0; i < AppState.devices.length; i++) {
                if (AppState.selectedScopeId === 'all' || String(AppState.devices[i].scope_id) === String(AppState.selectedScopeId)) {
                    AppState.devices[i].pos_x = null;
                    AppState.devices[i].pos_y = null;
                }
            }
            showToast('↺ Gespeicherte Positionen zurückgesetzt.');
            renderCurrentView();
            setTimeout(function () { fitNetworkGraph(); }, 100);
        }
    });
}

/**
 * Wechselt zwischen den Darstellungsmodi der Topologie-Ansicht (Freies Physik-Netz, Hierarchie, Matrix, Rack-Panel).
 */
function changeMapViewMode(mode) {
    AppState.viewMode = mode;
    renderCurrentView();
    setTimeout(function () { fitNetworkGraph(); }, 80);
}

/**
 * Filtert Geräte gemäß aktuellem Scope, Gruppen-Filter, Einzelsichtbarkeit, Fokus-Modus und Suchtext
 */
function getFilteredDevicesAndLinks() {
    var searchVal = (document.getElementById('map-search').value || '').toLowerCase();
    var showHiddenElem = document.getElementById('chk-show-hidden');
    var showHidden = showHiddenElem ? showHiddenElem.checked : false;

    // Falls Fokus-Modus aktiv ist: Nachbarschaft (1-Hop + Uplink) bestimmen
    var focusAllowed = null;
    var focusBanner = document.getElementById('focus-mode-banner');
    var focusText = document.getElementById('focus-mode-text');

    if (AppState.focusDeviceId) {
        focusAllowed = {};
        focusAllowed[AppState.focusDeviceId] = true;
        var neighborCount = 0;
        for (var fl = 0; fl < AppState.links.length; fl++) {
            var lnk = AppState.links[fl];
            var sId = parseInt(lnk.source_device_id, 10);
            var tId = parseInt(lnk.target_device_id, 10);
            if (sId === AppState.focusDeviceId) {
                focusAllowed[tId] = true;
                neighborCount++;
            } else if (tId === AppState.focusDeviceId) {
                focusAllowed[sId] = true;
                neighborCount++;
            }
        }
        var fDev = null;
        for (var fd = 0; fd < AppState.devices.length; fd++) {
            if (parseInt(AppState.devices[fd].id, 10) === AppState.focusDeviceId) {
                fDev = AppState.devices[fd];
                break;
            }
        }
        if (fDev && focusBanner && focusText) {
            var fName = fDev.custom_name || fDev.hostname || fDev.snmp_sysname || fDev.ip_address;
            focusBanner.style.display = 'flex';
            focusText.innerHTML = '🎯 <b>Fokus-Modus aktiv:</b> Isolierte Ansicht für <u>' + escapeHtml(fName) + '</u> (' + escapeHtml(fDev.ip_address) + ') und <b>' + neighborCount + '</b> direkt verbundene Nachbargeräte';
        } else {
            AppState.focusDeviceId = null;
            focusAllowed = null;
            if (focusBanner) focusBanner.style.display = 'none';
        }
    } else if (focusBanner) {
        focusBanner.style.display = 'none';
    }

    var filteredDevices = [];
    var allowedIds = {};
    var scopeTotalCount = 0;
    var scopeVisibleCount = 0;

    for (var i = 0; i < AppState.devices.length; i++) {
        var d = AppState.devices[i];
        if (AppState.selectedScopeId !== 'all' && String(d.scope_id) !== String(AppState.selectedScopeId)) {
            continue;
        }
        scopeTotalCount++;
        var isHidden = parseInt(d.is_hidden || 0, 10) === 1;
        if (!isHidden && AppState.activeTypes[d.device_type] !== false && (!d.custom_group || AppState.activeCustomGroups[d.custom_group] !== false)) {
            scopeVisibleCount++;
        }

        if (focusAllowed && !focusAllowed[parseInt(d.id, 10)]) {
            continue;
        }
        if (isHidden && !showHidden && (!focusAllowed || parseInt(d.id, 10) !== AppState.focusDeviceId)) {
            continue;
        }
        if (AppState.activeTypes[d.device_type] === false && (!focusAllowed || parseInt(d.id, 10) !== AppState.focusDeviceId)) {
            continue;
        }
        if (d.custom_group && AppState.activeCustomGroups[d.custom_group] === false) {
            continue;
        }
        if (searchVal !== '') {
            var spInfoMap = getDeviceSwitchPortInfo(d);
            var hay = ((d.ip_address || '') + ' ' + (d.hostname || '') + ' ' + (d.custom_name || '') + ' ' + (d.custom_group || '') + ' ' + (d.mac_address || '') + ' ' + (d.vendor || '') + ' ' + (spInfoMap ? spInfoMap.searchString : '')).toLowerCase();
            var sHex = normalizeMacHex(searchVal);
            var dHex = normalizeMacHex(d.mac_address);
            var macMatch = (sHex.length >= 3 && /^[0-9a-f:.\-\s]+$/i.test(searchVal) && dHex.indexOf(sHex) !== -1);
            if (!macMatch && sHex.length >= 4 && d.snmp_ports_json && normalizeMacHex(d.snmp_ports_json).indexOf(sHex) !== -1) {
                macMatch = true;
            }
            if (hay.indexOf(searchVal) === -1 && !macMatch) {
                continue;
            }
        }
        filteredDevices.push(d);
        allowedIds[d.id] = d;
    }

    var filterBtn = document.getElementById('btn-device-filter');
    if (filterBtn) {
        filterBtn.textContent = 'Gruppen & Filter (' + scopeVisibleCount + '/' + scopeTotalCount + ') ▾';
    }

    return {
        devices: filteredDevices,
        allowedIds: allowedIds
    };
}

/**
 * Haupt-Dispatcher für alle 6 Ansichten
 */
function renderCurrentView() {
    var canvasEl = document.getElementById('network-canvas');
    var matrixEl = document.getElementById('matrix-view-container');
    var rackEl   = document.getElementById('rack-view-container');
    var btnFit   = document.getElementById('btn-fit-graph');

    if (AppState.viewMode === 'matrix') {
        canvasEl.style.display = 'none';
        rackEl.style.display = 'none';
        matrixEl.style.display = 'flex';
        if (btnFit) btnFit.style.display = 'none';
        renderMatrixView();
    } else if (AppState.viewMode === 'rack') {
        canvasEl.style.display = 'none';
        matrixEl.style.display = 'none';
        rackEl.style.display = 'flex';
        if (btnFit) btnFit.style.display = 'none';
        renderRackPanelView();
    } else {
        matrixEl.style.display = 'none';
        rackEl.style.display = 'none';
        canvasEl.style.display = 'block';
        if (btnFit) btnFit.style.display = 'inline-flex';
        renderNetworkGraph();
    }
}

/**
 * Berechnet ein kompaktes, gestaffeltes Block-Raster für die Baum-Ansichten (Oben->Unten & Links->Rechts),
 * damit viele Endgeräte an einem Switch/Router in kompakten 5er-Reihen unter ihrem Switch stehen statt in einer Endlos-Linie.
 */
function computeSmartTreePositions(nodes, edges, direction) {
    var nodeMap = {};
    for (var i = 0; i < nodes.length; i++) {
        nodeMap[nodes[i].id] = nodes[i];
    }

    var childrenMap = {};
    var hasParent = {};
    for (var e = 0; e < edges.length; e++) {
        var ed = edges[e];
        if (String(ed.id).indexOf('backbone_') === 0) continue;
        if (!nodeMap[ed.from] || !nodeMap[ed.to]) continue;
        if (ed.from === ed.to) continue;
        if (!childrenMap[ed.from]) childrenMap[ed.from] = [];
        if (!hasParent[ed.to]) {
            childrenMap[ed.from].push(ed.to);
            hasParent[ed.to] = ed.from;
        }
    }

    // Wurzeln finden (Router / Knoten ohne Parent)
    var roots = [];
    for (var n = 0; n < nodes.length; n++) {
        var nd = nodes[n];
        if (!hasParent[nd.id]) {
            roots.push(nd.id);
        }
    }
    if (roots.length === 0 && nodes.length > 0) {
        roots.push(nodes[0].id);
    }

    var visited = {};
    var MAX_COLS = 5;       // Max. 5 Endgeräte nebeneinander pro Switch-Block
    var COL_STEP = 165;     // Horizontaler Abstand im Block
    var ROW_STEP = 120;     // Vertikaler Abstand zwischen gestaffelten Reihen
    var LEVEL_STEP = 195;   // Abstand zwischen Infrastruktur-Ebenen (Router -> Switch -> Clients)
    var cursorPrimary = 0;

    function layoutSubtree(nodeId, depth) {
        if (visited[nodeId]) return;
        visited[nodeId] = true;
        var kids = childrenMap[nodeId] || [];

        // Trenne Kinder in Infrastruktur-Knoten (die selbst Kinder haben oder Switches sind) und Blatt-Knoten (Endgeräte/Cluster)
        var branchKids = [];
        var leafKids = [];
        for (var k = 0; k < kids.length; k++) {
            var cid = kids[k];
            if (visited[cid]) continue;
            var cNode = nodeMap[cid];
            var cHasKids = childrenMap[cid] && childrenMap[cid].length > 0;
            if (cHasKids || (cNode && (cNode.deviceType === 'switch' || cNode.deviceType === 'router' || cNode.deviceType === 'ap'))) {
                branchKids.push(cid);
            } else {
                leafKids.push(cid);
            }
        }

        var subStart = cursorPrimary;

        // 1. Falls direkte Endgeräte (Blätter) an diesem Switch/Router hängen: als kompakten 5er-Block anordnen
        if (leafKids.length > 0) {
            var cols = Math.min(leafKids.length, MAX_COLS);
            var blockWidth = Math.max(1, cols) * COL_STEP;
            var blockStart = cursorPrimary;

            for (var l = 0; l < leafKids.length; l++) {
                var lid = leafKids[l];
                visited[lid] = true;
                var col = l % MAX_COLS;
                var row = Math.floor(l / MAX_COLS);
                var pCoord = blockStart + col * COL_STEP + Math.round(COL_STEP / 2);
                var sCoord = (depth + 1) * LEVEL_STEP + row * ROW_STEP;
                if (direction === 'LR') {
                    nodeMap[lid].x = sCoord;
                    nodeMap[lid].y = pCoord;
                } else {
                    nodeMap[lid].x = pCoord;
                    nodeMap[lid].y = sCoord;
                }
            }
            cursorPrimary += blockWidth + 70;
        }

        // 2. Darunterliegende Switches / Access Points rekursiv daneben platzieren
        for (var b = 0; b < branchKids.length; b++) {
            layoutSubtree(branchKids[b], depth + 1);
        }

        if (cursorPrimary === subStart) {
            cursorPrimary += COL_STEP;
        }

        var centerPrimary = Math.round((subStart + cursorPrimary - 70) / 2);
        var secondary = depth * LEVEL_STEP;
        if (direction === 'LR') {
            nodeMap[nodeId].x = secondary;
            nodeMap[nodeId].y = centerPrimary;
        } else {
            nodeMap[nodeId].x = centerPrimary;
            nodeMap[nodeId].y = secondary;
        }
    }

    for (var r = 0; r < roots.length; r++) {
        layoutSubtree(roots[r], 0);
        cursorPrimary += 90;
    }

    // Falls noch unverbundene Knoten übrig sind
    for (var u = 0; u < nodes.length; u++) {
        if (!visited[nodes[u].id]) {
            layoutSubtree(nodes[u].id, 0);
        }
    }
}

/**
 * Zoom-abhängiges Level-of-Detail (LOD):
 * Blendet bei weiter Herauszoom-Stufe in großen Netzen die mehrzeiligen Textschilder von normalen Workstations aus,
 * während Router, Switches, Server, APs, Drucker und Cluster immer beschriftet bleiben.
 */
function applyZoomLod(forceUpdate) {
    if (!AppState.network || !AppState.nodesDataSet) return;
    var allNodes = AppState.nodesDataSet.get();
    if (allNodes.length <= 12 && !forceUpdate) return;

    var scale = AppState.network.getScale();
    var shouldHideClientLabels = AppState.lodEnabled && (allNodes.length > 12) && (scale < 0.68);

    if (!forceUpdate && shouldHideClientLabels === AppState.lodLabelsHidden) {
        return;
    }
    AppState.lodLabelsHidden = shouldHideClientLabels;

    var updates = [];
    for (var i = 0; i < allNodes.length; i++) {
        var n = allNodes[i];
        if (n.isClientLeaf) {
            var targetLbl = shouldHideClientLabels ? (n.shortLabel || '') : n.fullLabel;
            if (n.label !== targetLbl) {
                updates.push({ id: n.id, label: targetLbl });
            }
        }
    }
    if (updates.length > 0) {
        AppState.nodesDataSet.update(updates);
    }
}

/**
 * Ansichten 1 bis 4: Interaktiver Topologie-Graph
 * Inklusive Client-Clustering, gestaffeltem Block-Raster im Baum-Modus, Zoom-LOD & gespeicherten Knoten-Positionen
 */
function renderNetworkGraph() {
    var filtered = getFilteredDevicesAndLinks();
    var filteredDevices = filtered.devices;
    var allowedIds = filtered.allowedIds;

    // 1. Ermittle Eltern-Kind-Beziehungen für Client-Clustering
    var parentByChild = {};
    var childrenCountByParent = {};
    for (var l = 0; l < AppState.links.length; l++) {
        var lnk0 = AppState.links[l];
        if (!allowedIds[lnk0.source_device_id] || !allowedIds[lnk0.target_device_id]) continue;
        parentByChild[lnk0.target_device_id] = lnk0.source_device_id;
        childrenCountByParent[lnk0.source_device_id] = (childrenCountByParent[lnk0.source_device_id] || 0) + 1;
    }

    // Prüfe, welche Workstations in ein Switch-/Gateway-Cluster gebündelt werden
    AppState.clusterMap = {};
    var clusteredDeviceToClusterId = {};

    if (AppState.clusterMode && !AppState.focusDeviceId) {
        var candidatesByParent = {};
        for (var c = 0; c < filteredDevices.length; c++) {
            var cd = filteredDevices[c];
            var cid = parseInt(cd.id, 10);
            // Nur Blatt-Workstations ohne eigene Kinder bündeln
            if (cd.device_type === 'workstation' && !childrenCountByParent[cid] && parentByChild[cid]) {
                var pid = parentByChild[cid];
                if (!AppState.expandedClusters[pid]) {
                    if (!candidatesByParent[pid]) candidatesByParent[pid] = [];
                    candidatesByParent[pid].push(cd);
                }
            }
        }

        for (var pk in candidatesByParent) {
            var list = candidatesByParent[pk];
            if (list.length >= 2) {
                var clusterId = 'cluster_' + pk;
                var onlineCnt = 0;
                var offlineCnt = 0;
                for (var m = 0; m < list.length; m++) {
                    if (list[m].status === 'online') onlineCnt++;
                    else offlineCnt++;
                    clusteredDeviceToClusterId[list[m].id] = clusterId;
                }
                AppState.clusterMap[clusterId] = {
                    clusterId: clusterId,
                    parentId: parseInt(pk, 10),
                    parentDev: allowedIds[pk] || null,
                    devices: list,
                    onlineCount: onlineCnt,
                    offlineCount: offlineCnt
                };
            }
        }
    }

    var nodes = [];
    var routersByScope = {};

    // Für Kreis-/Radial-Layout Koordinaten berechnen
    var centerIndex = 0;
    var outerIndex = 0;
    var outerCount = 0;
    for (var m0 = 0; m0 < filteredDevices.length; m0++) {
        if (clusteredDeviceToClusterId[filteredDevices[m0].id]) continue;
        if (filteredDevices[m0].device_type !== 'router' && filteredDevices[m0].device_type !== 'switch') {
            outerCount++;
        }
    }
    outerCount += Object.keys(AppState.clusterMap).length;

    for (var j = 0; j < filteredDevices.length; j++) {
        var dev = filteredDevices[j];
        if (clusteredDeviceToClusterId[dev.id]) {
            continue; // Wird durch Sammel-Knoten (Cluster) repräsentiert
        }

        var devHidden = parseInt(dev.is_hidden || 0, 10) === 1;
        var rawName = dev.custom_name || dev.hostname || dev.snmp_sysname || dev.ip_address;
        var titleName = rawName;
        if (titleName.length > 24) {
            titleName = titleName.substring(0, 22) + '…';
        }
        var hasAlert = (dev.alert_mode && dev.alert_mode !== 'none');
        if (hasAlert) {
            titleName = '🔔 ' + titleName;
        }
        var fullLabel = titleName + '\n' + dev.ip_address;
        if (dev.custom_group) {
            fullLabel += '\n[' + dev.custom_group + ']';
        }
        if (devHidden) {
            fullLabel += ' (ausgeblendet)';
        }

        var stackUnitsCount = 1;
        if (dev.snmp_ports_json) {
            try {
                var parsedSnmp = JSON.parse(dev.snmp_ports_json);
                if (parsedSnmp && parsedSnmp.stack_members && parsedSnmp.stack_members.length > 1) {
                    stackUnitsCount = parsedSnmp.stack_members.length;
                    fullLabel += '\n[📚 Stack: ' + stackUnitsCount + ' Switche]';
                }
            } catch (e) {}
        }

        var level = 3;
        if (dev.device_type === 'router') {
            level = 1;
            routersByScope[dev.scope_id] = dev.id;
        } else if (dev.device_type === 'switch') {
            level = 2;
        }

        var tooltipText = rawName + ' (' + dev.ip_address + ')\n' +
            'Typ: ' + (dev.device_type || '').toUpperCase() + (stackUnitsCount > 1 ? (' (Stack: ' + stackUnitsCount + ' Einheiten)') : '') + ' | Status: ' + (dev.status || '').toUpperCase() + '\n' +
            (dev.vendor ? ('Hersteller: ' + dev.vendor + '\n') : '') +
            (dev.os_guess ? ('OS: ' + dev.os_guess + '\n') : '') +
            (dev.open_ports ? ('Ports: ' + dev.open_ports) : '');

        var isClientLeaf = (dev.device_type === 'workstation' && !childrenCountByParent[dev.id]);

        var nodeObj = {
            id: parseInt(dev.id, 10),
            label: fullLabel,
            fullLabel: fullLabel,
            shortLabel: hasAlert ? '🔔' : '',
            isClientLeaf: isClientLeaf,
            deviceType: dev.device_type,
            title: tooltipText,
            image: createDeviceSvgUri(dev.device_type, dev.status, dev.scope_color, stackUnitsCount),
            shape: 'image',
            opacity: devHidden ? 0.4 : 1.0,
            size: (dev.device_type === 'router' || dev.device_type === 'switch') ? 32 : 25,
            level: level,
            font: {
                color: devHidden ? '#94a3b8' : '#f8fafc',
                size: 12,
                face: 'monospace',
                background: 'rgba(15, 23, 42, 0.78)'
            }
        };

        if (AppState.viewMode === 'radial') {
            if (dev.device_type === 'router' || dev.device_type === 'switch') {
                nodeObj.x = centerIndex * 140;
                nodeObj.y = 0;
                centerIndex++;
            } else {
                var radius = Math.max(240, outerCount * 16);
                var angle = (2 * Math.PI * outerIndex) / Math.max(1, outerCount);
                nodeObj.x = Math.round(radius * Math.cos(angle));
                nodeObj.y = Math.round(radius * Math.sin(angle));
                outerIndex++;
            }
        } else if (AppState.viewMode === 'physics' && dev.pos_x !== null && dev.pos_x !== undefined && dev.pos_x !== '' && dev.pos_y !== null && dev.pos_y !== undefined && dev.pos_y !== '') {
            nodeObj.x = parseInt(dev.pos_x, 10);
            nodeObj.y = parseInt(dev.pos_y, 10);
        }

        nodes.push(nodeObj);
    }

    // Cluster-Knoten hinzufügen
    var clusterKeys = Object.keys(AppState.clusterMap);
    for (var ck = 0; ck < clusterKeys.length; ck++) {
        var cInfo = AppState.clusterMap[clusterKeys[ck]];
        var cLabel = '📦 ' + cInfo.devices.length + ' Clients\n(' + cInfo.onlineCount + ' On' + (cInfo.offlineCount > 0 ? ', ' + cInfo.offlineCount + ' Off' : '') + ')';
        var cNode = {
            id: cInfo.clusterId,
            label: cLabel,
            fullLabel: cLabel,
            shortLabel: cLabel,
            isClientLeaf: false,
            deviceType: 'cluster',
            title: cInfo.devices.length + ' gebündelte Clients – Klick für Liste, Doppelklick zum Aufklappen im Plan',
            image: createClusterSvgUri(cInfo.devices.length, cInfo.offlineCount),
            shape: 'image',
            size: 30,
            level: 3,
            font: {
                color: '#6ee7b7',
                size: 12,
                face: 'monospace',
                background: 'rgba(6, 78, 59, 0.88)'
            }
        };
        if (AppState.viewMode === 'radial') {
            var cRadius = Math.max(240, outerCount * 16);
            var cAngle = (2 * Math.PI * outerIndex) / Math.max(1, outerCount);
            cNode.x = Math.round(cRadius * Math.cos(cAngle));
            cNode.y = Math.round(cRadius * Math.sin(cAngle));
            outerIndex++;
        }
        nodes.push(cNode);
    }

    var edges = [];
    var addedClusterEdges = {};

    for (var k = 0; k < AppState.links.length; k++) {
        var lnk = AppState.links[k];
        if (!allowedIds[lnk.source_device_id] || !allowedIds[lnk.target_device_id]) {
            continue;
        }

        var targetClustId = clusteredDeviceToClusterId[lnk.target_device_id];
        if (targetClustId) {
            if (!addedClusterEdges[targetClustId]) {
                addedClusterEdges[targetClustId] = true;
                var clObj = AppState.clusterMap[targetClustId];
                edges.push({
                    id: 'e_' + targetClustId,
                    from: parseInt(lnk.source_device_id, 10),
                    to: targetClustId,
                    label: clObj ? (clObj.devices.length + ' Ports') : '',
                    color: { color: '#10b981', highlight: '#34d399' },
                    width: 2.8,
                    dashes: [6, 4],
                    font: {
                        color: '#a7f3d0',
                        size: 10,
                        strokeWidth: 3,
                        strokeColor: '#0f172a',
                        align: 'middle'
                    }
                });
            }
            continue;
        }

        var edgeColor = '#475569';
        var dashes = false;
        var width = 2;

        if (lnk.link_type === 'snmp_l2') {
            edgeColor = '#10b981';
            width = 2.5;
        } else if (lnk.link_type === 'lldp' || lnk.link_type === 'trunk') {
            edgeColor = '#3b82f6';
            width = 3;
        } else if (lnk.link_type === 'manual') {
            edgeColor = '#f59e0b';
            width = 2.5;
        } else {
            dashes = [5, 5];
        }

        var edgeLabel = lnk.source_port || '';
        if ((lnk.link_type === 'lldp' || lnk.link_type === 'trunk') && lnk.target_port && lnk.target_port !== 'eth0') {
            edgeLabel = (lnk.source_port ? lnk.source_port : 'Uplink') + ' ⇄ ' + lnk.target_port;
        }

        edges.push({
            id: 'e_' + lnk.id,
            from: parseInt(lnk.source_device_id, 10),
            to: parseInt(lnk.target_device_id, 10),
            label: edgeLabel,
            color: { color: edgeColor, highlight: '#60a5fa' },
            width: width,
            dashes: dashes,
            font: {
                color: '#a7f3d0',
                size: 10,
                strokeWidth: 3,
                strokeColor: '#0f172a',
                align: 'middle'
            }
        });
    }

    var routerScopeKeys = Object.keys(routersByScope);
    if (AppState.selectedScopeId === 'all' && routerScopeKeys.length > 1) {
        var primaryRouterId = routersByScope[routerScopeKeys[0]];
        for (var r = 1; r < routerScopeKeys.length; r++) {
            edges.push({
                id: 'backbone_' + r,
                from: parseInt(primaryRouterId, 10),
                to: parseInt(routersByScope[routerScopeKeys[r]], 10),
                label: 'Inter-VLAN / Routing',
                color: { color: '#8b5cf6', highlight: '#c4b5fd' },
                width: 3,
                dashes: [8, 4],
                font: { color: '#ddd6fe', size: 10, strokeWidth: 3, strokeColor: '#0f172a' }
            });
        }
    }

    var isTree = (AppState.viewMode === 'tree_ud' || AppState.viewMode === 'tree_lr');
    var treeDir = (AppState.viewMode === 'tree_lr') ? 'LR' : 'UD';

    // Für Baum-Ansichten das gestaffelte Block-Raster berechnen (statt einer einzigen Endlos-Reihe)
    if (isTree) {
        computeSmartTreePositions(nodes, edges, treeDir);
    }

    var options = {
        interaction: {
            hover: true,
            keyboard: true,
            tooltipDelay: 120
        },
        edges: {
            smooth: isTree ? {
                type: 'cubicBezier',
                forceDirection: (treeDir === 'LR') ? 'horizontal' : 'vertical',
                roundness: 0.4
            } : {
                type: 'continuous',
                roundness: 0.2
            }
        },
        layout: {
            hierarchical: false
        },
        physics: (isTree || AppState.viewMode === 'radial') ? {
            enabled: false
        } : {
            enabled: true,
            barnesHut: {
                gravitationalConstant: -4600,
                centralGravity: 0.22,
                springLength: 150,
                springConstant: 0.04,
                damping: 0.15,
                avoidOverlap: 0.35
            },
            stabilization: { iterations: 130 }
        }
    };

    var container = document.getElementById('network-canvas');
    if (AppState.network) {
        AppState.network.destroy();
    }
    AppState.lodLabelsHidden = false;
    AppState.nodesDataSet = new vis.DataSet(nodes);
    AppState.edgesDataSet = new vis.DataSet(edges);
    AppState.network = new vis.Network(container, {
        nodes: AppState.nodesDataSet,
        edges: AppState.edgesDataSet
    }, options);

    // Nach dem Einschwingen Physik einfrieren, damit Knoten ruhig stehen bleiben und beim Ziehen fixiert bleiben
    if (AppState.viewMode === 'physics') {
        AppState.network.once('stabilizationIterationsDone', function () {
            AppState.network.setOptions({ physics: { enabled: false } });
            applyZoomLod(true);
        });
    } else {
        setTimeout(function () { applyZoomLod(true); }, 60);
    }

    AppState.network.on('zoom', function () {
        applyZoomLod(false);
    });

    AppState.network.on('click', function (params) {
        if (params.nodes && params.nodes.length > 0) {
            var clickedId = params.nodes[0];
            if (String(clickedId).indexOf('cluster_') === 0) {
                selectCluster(clickedId);
            } else {
                selectDevice(clickedId);
            }
        } else if ((!params.edges || params.edges.length === 0)) {
            // Klick auf freie Fläche im Netzwerkplan blendet das schwebende Detailfenster automatisch aus
            toggleRightSidebar(false);
        }
    });

    AppState.network.on('doubleClick', function (params) {
        if (params.nodes && params.nodes.length > 0) {
            var dblId = params.nodes[0];
            if (String(dblId).indexOf('cluster_') === 0) {
                var cEntry = AppState.clusterMap[dblId];
                if (cEntry) {
                    toggleSingleCluster(cEntry.parentId, true);
                    showToast('📂 Client-Cluster aufgeklappt.');
                }
            } else {
                setFocusDevice(AppState.focusDeviceId === parseInt(dblId, 10) ? null : dblId);
            }
        }
    });
}

/**
 * Zeigt beim Klick auf einen Client-Cluster alle darin enthaltenen Geräte übersichtlich im rechten Detailfenster an.
 */
function selectCluster(clusterId) {
    var cInfo = AppState.clusterMap[clusterId];
    if (!cInfo) return;
    AppState.selectedDeviceId = null;
    AppState.selectedClusterId = clusterId;
    toggleRightSidebar(true);
    var titleEl = document.getElementById('inspector-title');
    var expBtn = document.getElementById('btn-export-device-details');
    if (titleEl) titleEl.textContent = '📦 Cluster (' + cInfo.devices.length + ' Clients)';
    if (expBtn) expBtn.style.display = 'inline-flex';
    var panel = document.getElementById('inspector-content');
    var pName = cInfo.parentDev ? (cInfo.parentDev.custom_name || cInfo.parentDev.hostname || cInfo.parentDev.ip_address) : 'Switch / Gateway';

    var html = '<div style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.3);border-radius:8px;padding:12px;display:flex;flex-direction:column;gap:10px;">' +
        '<div style="font-size:14px;font-weight:700;color:#6ee7b7;">📦 Client-Cluster (' + cInfo.devices.length + ' Geräte)</div>' +
        '<div style="font-size:12px;color:var(--text-secondary);">Gebündelte Endgeräte an <b>' + escapeHtml(pName) + '</b></div>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
            '<span class="badge badge-online">' + cInfo.onlineCount + ' Online</span>' +
            (cInfo.offlineCount > 0 ? ('<span class="badge badge-offline">' + cInfo.offlineCount + ' Offline</span>') : '') +
        '</div>' +
        '<button class="btn btn-primary" onclick="toggleSingleCluster(' + cInfo.parentId + ', true)">📂 Dieses Cluster im Netzwerkplan aufklappen</button>' +
    '</div>' +
    '<div style="font-size:12px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;margin-top:4px;">Enthaltene Geräte (Klick für Details):</div>' +
    '<div style="display:flex;flex-direction:column;gap:6px;">';

    for (var i = 0; i < cInfo.devices.length; i++) {
        var d = cInfo.devices[i];
        var dName = d.custom_name || d.hostname || d.ip_address;
        html += '<div onclick="selectDevice(' + d.id + ')" style="cursor:pointer;background:var(--bg-card);border:1px solid var(--border-color);border-radius:6px;padding:8px 10px;display:flex;justify-content:space-between;align-items:center;">' +
            '<div>' +
                '<div style="font-weight:600;font-size:12.5px;">' + escapeHtml(dName) + '</div>' +
                '<div style="font-family:monospace;font-size:11.5px;color:#93c5fd;">' + escapeHtml(d.ip_address) + (d.vendor ? (' • ' + escapeHtml(d.vendor)) : '') + '</div>' +
            '</div>' +
            '<span class="badge badge-' + d.status + '">' + d.status.toUpperCase() + '</span>' +
        '</div>';
    }
    html += '</div>';
    panel.innerHTML = html;
}

/**
 * Ansicht 5: Gruppen-Kachelansicht (Matrix)
 * Zeigt alle Geräte übersichtlich ohne Linien-Gewirr in Gruppen-Blöcken
 */
function renderMatrixView() {
    var container = document.getElementById('matrix-view-container');
    var filtered = getFilteredDevicesAndLinks();
    var devs = filtered.devices;

    // Map für Port-Bezeichnungen pro Ziel-Gerät
    var uplinkPortByDevId = {};
    for (var l = 0; l < AppState.links.length; l++) {
        var lnk = AppState.links[l];
        if (lnk.source_port) {
            uplinkPortByDevId[lnk.target_device_id] = lnk.source_port;
        }
    }

    // Gruppiere nach custom_group (falls gesetzt) oder nach Gerätetyp
    var groups = {};
    var typeOrder = ['router', 'switch', 'server', 'ap', 'printer', 'workstation'];
    for (var t = 0; t < typeOrder.length; t++) {
        var tk = typeOrder[t];
        groups['type:' + tk] = {
            title: TYPE_META[tk].label,
            color: TYPE_META[tk].color,
            groupKind: 'device_type',
            groupVal: tk,
            items: []
        };
    }

    for (var i = 0; i < devs.length; i++) {
        var d = devs[i];
        if (d.custom_group) {
            var gkey = 'custom:' + d.custom_group;
            if (!groups[gkey]) {
                groups[gkey] = {
                    title: 'Gruppe: ' + d.custom_group,
                    color: '#f59e0b',
                    groupKind: 'custom_group',
                    groupVal: d.custom_group,
                    items: []
                };
            }
            groups[gkey].items.push(d);
        } else {
            var tkey = 'type:' + (d.device_type || 'workstation');
            if (!groups[tkey]) {
                groups[tkey] = { title: d.device_type, color: '#3b82f6', groupKind: 'device_type', groupVal: d.device_type, items: [] };
            }
            groups[tkey].items.push(d);
        }
    }

    var html = '';
    var gkeys = Object.keys(groups);
    for (var g = 0; g < gkeys.length; g++) {
        var grp = groups[gkeys[g]];
        if (grp.items.length === 0) continue;

        html += '<div class="matrix-group-block">' +
                '<div class="matrix-group-header">' +
                    '<div style="display:flex;align-items:center;gap:10px;">' +
                        '<span class="scope-dot" style="background:' + grp.color + ';width:12px;height:12px;"></span>' +
                        '<span>' + escapeHtml(grp.title) + ' (' + grp.items.length + ')</span>' +
                    '</div>' +
                    '<button class="btn btn-sm" onclick="persistGroupVisibility(\'' + grp.groupKind + '\', \'' + escapeHtml(grp.groupVal) + '\', 1)">Ganze Gruppe ausblenden</button>' +
                '</div>' +
                '<div class="matrix-grid">';

        for (var k = 0; k < grp.items.length; k++) {
            var item = grp.items[k];
            var name = item.custom_name || item.hostname || item.snmp_sysname || item.ip_address;
            var portBadge = uplinkPortByDevId[item.id]
                ? '<span class="badge badge-snmp">' + escapeHtml(uplinkPortByDevId[item.id]) + '</span>'
                : '';

            html += '<div class="matrix-card" onclick="selectDevice(' + item.id + ')">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;">' +
                        '<span style="font-family:monospace;font-weight:700;color:#60a5fa;font-size:13.5px;">' + escapeHtml(item.ip_address) + '</span>' +
                        '<span class="badge badge-' + item.status + '">' + escapeHtml(item.status) + '</span>' +
                    '</div>' +
                    '<div style="font-weight:600;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(name) + '</div>' +
                    '<div style="font-size:11.5px;color:#a7f3d0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(item.os_guess || 'System unbekannt') + '</div>' +
                    '<div style="font-size:11px;color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(item.vendor || item.service_info || '-') + '</div>' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;font-size:11.5px;color:var(--text-muted);">' +
                        '<span style="font-family:monospace;">' + escapeHtml(item.mac_address || '-') + '</span>' +
                        portBadge +
                    '</div>' +
                    '</div>';
        }

        html += '</div></div>';
    }

    container.innerHTML = html || '<div style="padding:24px;color:var(--text-secondary);">Keine sichtbaren Geräte für die aktuelle Filterung.</div>';
}

/**
 * Ansicht 6: 19-Zoll Switch-Port-Panel (Rack-Ansicht)
 * Stellt jeden Managed Switch / Router als physisches Switch-Panel mit Port-Belegung dar
 */
function renderRackPanelView() {
    var container = document.getElementById('rack-view-container');
    var filtered = getFilteredDevicesAndLinks();
    var devs = filtered.devices;
    var devById = {};
    for (var i = 0; i < AppState.devices.length; i++) {
        devById[AppState.devices[i].id] = AppState.devices[i];
    }

    // Finde alle Switches & Router als Rack-Einheiten
    var rackUnits = [];
    for (var j = 0; j < devs.length; j++) {
        if (devs[j].device_type === 'switch' || devs[j].device_type === 'router') {
            rackUnits.push(devs[j]);
        }
    }

    if (rackUnits.length === 0 && devs.length > 0) {
        rackUnits.push(devs[0]);
    }

    var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;background:rgba(15,23,42,0.7);border:1px solid var(--border-color);border-radius:8px;padding:10px 14px;flex-wrap:wrap;gap:10px;">' +
        '<div style="font-size:12.5px;color:var(--text-secondary);">Klicke auf einen Switch für die <b>originalgetreue 19-Zoll Frontblenden-Grafik</b> inkl. RJ45/SFP-Matrix, <b>Uplink-Kaskadierung</b> &amp; <b>Switch-Stacking</b>.</div>' +
        '<button class="btn btn-sm" style="border-color:#a855f7;color:#d8b4fe;background:rgba(168,85,247,0.14);" onclick="openSwitchGraphicModal(0, true)">👁 Demo: 2x Switch-Stack &amp; Kaskade zeigen</button>' +
    '</div>';
    for (var u = 0; u < rackUnits.length; u++) {
        var sw = rackUnits[u];
        var swName = sw.custom_name || sw.snmp_sysname || sw.hostname || sw.ip_address;

        // Alle an diesen Switch/Router angeschlossenen Links ermitteln
        var connectedLinks = [];
        for (var l = 0; l < AppState.links.length; l++) {
            var lnk = AppState.links[l];
            if (parseInt(lnk.source_device_id, 10) === parseInt(sw.id, 10) && filtered.allowedIds[lnk.target_device_id]) {
                connectedLinks.push(lnk);
            }
        }

        html += '<div class="rack-chassis">' +
                '<div class="rack-header" onclick="selectDevice(' + sw.id + ')" style="cursor:pointer;">' +
                    '<div>' +
                        '<div style="font-size:15px;font-weight:700;color:#fff;display:flex;align-items:center;gap:10px;">' +
                            '<span class="port-led up"></span>' +
                            '<span>' + escapeHtml(swName) + ' (' + escapeHtml(sw.ip_address) + ')</span>' +
                            '<span class="badge badge-snmp">' + escapeHtml(sw.device_type.toUpperCase()) + '</span>' +
                        '</div>' +
                        '<div style="font-size:12px;color:var(--text-secondary);margin-top:3px;">' +
                            escapeHtml(sw.snmp_sysdescr || sw.vendor || 'Netzwerk-Verteiler') +
                        '</div>' +
                    '</div>' +
                    '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">' +
                        '<span style="font-size:12.5px;color:#34d399;font-weight:600;">' + connectedLinks.length + ' belegte Ports</span>' +
                        '<button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); openSwitchGraphicModal(' + sw.id + ', false);">🎛 19&quot; Switch-Grafik &amp; Kaskade</button>' +
                    '</div>' +
                '</div>' +
                '<div class="rack-ports-grid">';

        for (var p = 0; p < connectedLinks.length; p++) {
            var cl = connectedLinks[p];
            var target = devById[cl.target_device_id];
            if (!target) continue;
            var portTitle = cl.source_port ? cl.source_port : ('Port #' + (p + 1));
            var tName = target.custom_name || target.hostname || target.ip_address;

            html += '<div class="rack-port-box up" onclick="selectDevice(' + target.id + ')">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">' +
                        '<span style="font-family:monospace;font-size:12px;font-weight:700;color:#34d399;">' + escapeHtml(portTitle) + '</span>' +
                        '<span class="port-led ' + (target.status === 'online' ? 'up' : 'down') + '"></span>' +
                    '</div>' +
                    '<div style="font-weight:600;font-size:12.5px;color:#f8fafc;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(tName) + '</div>' +
                    '<div style="font-family:monospace;font-size:11.5px;color:#93c5fd;margin-top:2px;">' + escapeHtml(target.ip_address) + '</div>' +
                    '<div style="font-size:11px;color:var(--text-secondary);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(target.vendor || target.device_type) + '</div>' +
                    '</div>';
        }

        html += '</div></div>';
    }

    container.innerHTML = html || '<div style="padding:24px;color:var(--text-secondary);">Keine Switches oder Gateways in dieser Auswahl.</div>';
}

/**
 * Zentriert und skaliert den Netzwerkgraphen so, dass alle sichtbaren Knoten optimal ins Fenster passen.
 */
function fitNetworkGraph() {
    if (AppState.network) {
        AppState.network.fit({ animation: true });
    }
}

/**
 * Ermittelt für alle aktuell sichtbaren Geräte deren exakte Layout-Koordinaten
 * (aus dem aktuellen vis-network Graphen oder einem automatischen Raster-Fallback)
 */
function getExportLayoutData() {
    var filtered = getFilteredDevicesAndLinks();
    var devs = filtered.devices;
    var allowedIds = filtered.allowedIds;
    var rawPos = (AppState.network && AppState.viewMode !== 'matrix' && AppState.viewMode !== 'rack')
        ? AppState.network.getPositions()
        : {};

    var posMap = {};
    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;

    for (var i = 0; i < devs.length; i++) {
        var d = devs[i];
        var id = parseInt(d.id, 10);
        var x, y;
        if (rawPos[id] && typeof rawPos[id].x === 'number' && typeof rawPos[id].y === 'number') {
            x = Math.round(rawPos[id].x * 1.35);
            y = Math.round(rawPos[id].y * 1.35);
        } else {
            // Sauberes Raster falls aus Matrix-/Rack-Ansicht exportiert wird
            var col = i % 6;
            var row = Math.floor(i / 6);
            x = col * 240;
            y = row * 160;
        }
        posMap[id] = { x: x, y: y };
        if (x < minX) minX = x;
        if (y < minY) minY = y;
        if (x > maxX) maxX = x;
        if (y > maxY) maxY = y;
    }

    if (devs.length === 0) {
        minX = 0; minY = 0; maxX = 800; maxY = 600;
    }

    var padX = 180;
    var padTop = 130;
    var padBottom = 120;
    var width = Math.max(1200, (maxX - minX) + padX * 2);
    var height = Math.max(800, (maxY - minY) + padTop + padBottom);

    // Normalisiere alle Koordinaten in den positiven SVG-Zeichenbereich
    var offsetX = -minX + padX;
    var offsetY = -minY + padTop;
    for (var k in posMap) {
        posMap[k].x = Math.round(posMap[k].x + offsetX);
        posMap[k].y = Math.round(posMap[k].y + offsetY);
    }

    var activeLinks = [];
    for (var l = 0; l < AppState.links.length; l++) {
        var lnk = AppState.links[l];
        if (allowedIds[lnk.source_device_id] && allowedIds[lnk.target_device_id]) {
            activeLinks.push(lnk);
        }
    }

    return {
        devices: devs,
        links: activeLinks,
        posMap: posMap,
        width: width,
        height: height
    };
}

/**
 * Erzeugt ein echtes, verlustfreies Vektor-SVG-Dokument (.svg)
 * Unendlich zoombar & im Browser per Strg+F nach IPs, MACs und Namen durchsuchbar!
 */
function buildVectorSvgDocument(lightTheme) {
    var layout = getExportLayoutData();
    var devs = layout.devices;
    var links = layout.links;
    var pos = layout.posMap;
    var w = layout.width;
    var h = layout.height;

    var bgFill = lightTheme ? '#f8fafc' : '#0f172a';
    var headerFill = lightTheme ? '#e2e8f0' : '#1e293b';
    var textMain = lightTheme ? '#0f172a' : '#f8fafc';
    var textSub = lightTheme ? '#475569' : '#94a3b8';
    var labelBg = lightTheme ? 'rgba(255,255,255,0.92)' : 'rgba(15,23,42,0.88)';
    var borderCol = lightTheme ? '#cbd5e1' : '#334155';

    var scopeTitle = 'Alle Netzwerk-Scopes';
    if (AppState.selectedScopeId !== 'all') {
        for (var s = 0; s < AppState.scopes.length; s++) {
            if (String(AppState.scopes[s].id) === String(AppState.selectedScopeId)) {
                scopeTitle = AppState.scopes[s].name + ' (' + AppState.scopes[s].cidr + ')';
                break;
            }
        }
    }
    var nowStr = new Date().toLocaleString('de-DE');

    var svg = [];
    svg.push('<?xml version="1.0" encoding="UTF-8" standalone="no"?>');
    svg.push('<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" font-family="Segoe UI, Roboto, Helvetica, Arial, sans-serif">');
    svg.push('<rect x="0" y="0" width="' + w + '" height="' + h + '" fill="' + bgFill + '"/>');

    // Titel-Kopfzeile & Legende im Vektorplan
    svg.push('<rect x="0" y="0" width="' + w + '" height="68" fill="' + headerFill + '" stroke="' + borderCol + '" stroke-width="1"/>');
    svg.push('<text x="28" y="30" fill="' + textMain + '" font-size="20" font-weight="bold">NetworkMonitor – Netzwerkplan: ' + escapeHtml(scopeTitle) + '</text>');
    svg.push('<text x="28" y="52" fill="' + textSub + '" font-size="13">Stand: ' + escapeHtml(nowStr) + ' • ' + devs.length + ' sichtbare Geräte • ' + links.length + ' Verbindungen (Vektorgrafik – mit Strg+F durchsuchbar)</text>');

    // 1. Verbindungslinien + Switch-Port-Beschriftungen zeichnen
    for (var i = 0; i < links.length; i++) {
        var lnk = links[i];
        var p1 = pos[lnk.source_device_id];
        var p2 = pos[lnk.target_device_id];
        if (!p1 || !p2) continue;

        var stroke = lightTheme ? '#64748b' : '#475569';
        var strokeW = 2;
        var dash = '';
        if (lnk.link_type === 'snmp_l2') {
            stroke = '#10b981';
            strokeW = 2.8;
        } else if (lnk.link_type === 'lldp' || lnk.link_type === 'trunk') {
            stroke = '#3b82f6';
            strokeW = 3.2;
        } else if (lnk.link_type === 'manual') {
            stroke = '#f59e0b';
            strokeW = 2.5;
        } else {
            dash = ' stroke-dasharray="6,6"';
        }

        svg.push('<line x1="' + p1.x + '" y1="' + p1.y + '" x2="' + p2.x + '" y2="' + p2.y + '" stroke="' + stroke + '" stroke-width="' + strokeW + '"' + dash + '/>');

        if (lnk.source_port) {
            var mx = Math.round((p1.x + p2.x) / 2);
            var my = Math.round((p1.y + p2.y) / 2);
            var portTxt = String(lnk.source_port);
            var boxW = Math.max(46, portTxt.length * 7.5 + 14);
            svg.push('<rect x="' + Math.round(mx - boxW / 2) + '" y="' + (my - 10) + '" width="' + Math.round(boxW) + '" height="19" rx="5" fill="' + headerFill + '" stroke="' + stroke + '" stroke-width="1.2"/>');
            svg.push('<text x="' + mx + '" y="' + (my + 3) + '" fill="' + (lightTheme ? '#047857' : '#a7f3d0') + '" font-size="11" font-family="monospace" font-weight="bold" text-anchor="middle">' + escapeHtml(portTxt) + '</text>');
        }
    }

    // 2. Geräte-Knoten als echte Vektor-Elemente zeichnen
    for (var j = 0; j < devs.length; j++) {
        var d = devs[j];
        var pt = pos[d.id];
        if (!pt) continue;

        var meta = TYPE_META[d.device_type] || TYPE_META.workstation;
        var boxBg = meta.bg;
        var boxBorder = (d.device_type === 'workstation' && d.scope_color) ? d.scope_color : meta.color;
        if (d.status === 'offline') {
            boxBorder = '#ef4444';
        }

        var r = (d.device_type === 'router' || d.device_type === 'switch') ? 28 : 23;
        var bx = pt.x - r;
        var by = pt.y - r;
        var bw = r * 2;

        // Knoten-Icon-Box
        svg.push('<g transform="translate(' + bx + ',' + by + ')">');
        svg.push('<rect x="0" y="0" width="' + bw + '" height="' + bw + '" rx="12" fill="' + boxBg + '" stroke="' + boxBorder + '" stroke-width="3"/>');
        svg.push('<text x="' + r + '" y="' + (r + 5) + '" fill="#ffffff" font-size="11" font-weight="bold" text-anchor="middle">' + escapeHtml(d.device_type.substring(0, 3).toUpperCase()) + '</text>');
        svg.push('</g>');

        // Text-Label-Box unter dem Knoten
        var dName = d.custom_name || d.hostname || d.snmp_sysname || d.ip_address;
        if (dName.length > 28) dName = dName.substring(0, 26) + '…';
        var subLine = d.ip_address + (d.mac_address ? (' • ' + d.mac_address) : '');
        var lblW = Math.max(dName.length * 7.5, subLine.length * 6.5) + 18;
        var lblH = d.custom_group ? 48 : 36;

        svg.push('<rect x="' + Math.round(pt.x - lblW / 2) + '" y="' + (pt.y + r + 5) + '" width="' + Math.round(lblW) + '" height="' + lblH + '" rx="6" fill="' + labelBg + '" stroke="' + borderCol + '" stroke-width="1"/>');
        svg.push('<text x="' + pt.x + '" y="' + (pt.y + r + 20) + '" fill="' + textMain + '" font-size="12" font-weight="bold" text-anchor="middle">' + escapeHtml(dName) + '</text>');
        svg.push('<text x="' + pt.x + '" y="' + (pt.y + r + 35) + '" fill="' + (lightTheme ? '#2563eb' : '#93c5fd') + '" font-size="11" font-family="monospace" text-anchor="middle">' + escapeHtml(subLine) + '</text>');
        if (d.custom_group) {
            svg.push('<text x="' + pt.x + '" y="' + (pt.y + r + 48) + '" fill="#f59e0b" font-size="10" font-weight="bold" text-anchor="middle">[' + escapeHtml(d.custom_group) + ']</text>');
        }
    }

    svg.push('</svg>');
    return {
        svgString: svg.join('\n'),
        width: w,
        height: h
    };
}

/**
 * 1. Verlustfreier SVG-Vektor-Export (.svg)
 */
function exportNetworkSvg() {
    var res = buildVectorSvgDocument(false);
    var blob = new Blob([res.svgString], { type: 'image/svg+xml;charset=utf-8' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'netzwerkplan_vektor_' + new Date().toISOString().slice(0, 10) + '.svg';
    link.click();
    showToast('Vektorgrafik (.svg) gespeichert – unendlich zoombar & im Browser per Strg+F durchsuchbar!');
}

/**
 * 2. Draw.io / Diagrams.net & MS Visio Export (.drawio XML)
 */
function exportDrawIo() {
    var layout = getExportLayoutData();
    var devs = layout.devices;
    var links = layout.links;
    var pos = layout.posMap;

    var xml = [];
    xml.push('<?xml version="1.0" encoding="UTF-8"?>');
    xml.push('<mxfile host="NetworkMonitor" modified="' + new Date().toISOString() + '" agent="NetworkMonitor" version="21.0.0" type="device">');
    xml.push('  <diagram id="netplan" name="Netzwerkplan">');
    xml.push('    <mxGraphModel dx="1422" dy="794" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="' + layout.width + '" pageHeight="' + layout.height + '" math="0" shadow="0">');
    xml.push('      <root>');
    xml.push('        <mxCell id="0" />');
    xml.push('        <mxCell id="1" parent="0" />');

    // Geräte als verschiebbare Draw.io Boxen
    for (var i = 0; i < devs.length; i++) {
        var d = devs[i];
        var pt = pos[d.id] || { x: 100, y: 100 };
        var meta = TYPE_META[d.device_type] || TYPE_META.workstation;
        var name = d.custom_name || d.hostname || d.snmp_sysname || d.ip_address;
        var label = '<b>' + escapeHtml(name) + '</b><br/>' + escapeHtml(d.ip_address) +
                    (d.vendor ? ('<br/><font style="font-size:10px">' + escapeHtml(d.vendor) + '</font>') : '');
        var style = 'rounded=1;whiteSpace=wrap;html=1;fillColor=' + meta.bg + ';strokeColor=' + meta.color + ';strokeWidth=2;fontColor=#ffffff;fontSize=11;';

        xml.push('        <mxCell id="dev_' + d.id + '" value="' + escapeHtml(label) + '" style="' + style + '" vertex="1" parent="1">');
        xml.push('          <mxGeometry x="' + Math.round(pt.x - 65) + '" y="' + Math.round(pt.y - 30) + '" width="130" height="60" as="geometry" />');
        xml.push('        </mxCell>');
    }

    // Verbindungen inkl. Switch-Port Labels
    for (var j = 0; j < links.length; j++) {
        var lnk = links[j];
        var stroke = (lnk.link_type === 'snmp_l2') ? '#10b981' : ((lnk.link_type === 'lldp' || lnk.link_type === 'trunk') ? '#3b82f6' : '#64748b');
        var dashed = (lnk.link_type === 'subnet') ? 'dashed=1;' : '';
        var edgeStyle = 'edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;html=1;endArrow=none;strokeColor=' + stroke + ';strokeWidth=2;' + dashed;
        var portLabel = lnk.source_port ? escapeHtml(lnk.source_port) : '';

        xml.push('        <mxCell id="edge_' + lnk.id + '" value="' + portLabel + '" style="' + edgeStyle + '" edge="1" parent="1" source="dev_' + lnk.source_device_id + '" target="dev_' + lnk.target_device_id + '">');
        xml.push('          <mxGeometry relative="1" as="geometry" />');
        xml.push('        </mxCell>');
    }

    xml.push('      </root>');
    xml.push('    </mxGraphModel>');
    xml.push('  </diagram>');
    xml.push('</mxfile>');

    var blob = new Blob([xml.join('\n')], { type: 'application/xml;charset=utf-8' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'netzwerkplan_' + new Date().toISOString().slice(0, 10) + '.drawio';
    link.click();
    showToast('Draw.io / Visio Datei (.drawio) gespeichert – kann direkt unter app.diagrams.net geöffnet werden!');
}

/**
 * 3. Druckfertiger Vektor-PDF-Report (Vektor-Netzwerkplan + Inventartabelle)
 */
function exportPdfReport() {
    var svgDoc = buildVectorSvgDocument(true); // Helles Druck-Theme für Papier/PDF
    var filtered = getFilteredDevicesAndLinks();
    var devs = filtered.devices;

    var rowsHtml = '';
    for (var i = 0; i < devs.length; i++) {
        var d = devs[i];
        rowsHtml += '<tr>' +
            '<td>' + escapeHtml(d.ip_address) + '</td>' +
            '<td><b>' + escapeHtml(d.custom_name || d.hostname || '-') + '</b></td>' +
            '<td>' + escapeHtml(d.device_type.toUpperCase()) + '</td>' +
            '<td>' + escapeHtml(d.custom_group || '-') + '</td>' +
            '<td>' + escapeHtml(d.mac_address || '-') + '</td>' +
            '<td>' + escapeHtml(d.vendor || '-') + '</td>' +
            '<td>' + escapeHtml(d.open_ports || '-') + '</td>' +
            '</tr>';
    }

    var win = window.open('', '_blank');
    if (!win) {
        showToast('Bitte Popups für diese Seite erlauben, um die PDF-Druckansicht zu öffnen.');
        return;
    }

    win.document.write(
        '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">' +
        '<title>Netzwerkplan Report – ' + new Date().toLocaleDateString('de-DE') + '</title>' +
        '<style>' +
        '@page { size: landscape; margin: 10mm; }' +
        'body { font-family: Arial, sans-serif; color: #0f172a; margin: 0; padding: 15px; }' +
        '.svg-wrap { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; margin-bottom: 20px; }' +
        '.svg-wrap svg { width: 100%; height: auto; display: block; }' +
        'table { width: 100%; border-collapse: collapse; font-size: 11px; page-break-inside: auto; }' +
        'th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }' +
        'th { background: #e2e8f0; }' +
        '.no-print { margin-bottom: 12px; display: flex; gap: 10px; }' +
        '@media print { .no-print { display: none; } }' +
        '</style></head><body>' +
        '<div class="no-print">' +
            '<button onclick="window.print()" style="padding:8px 16px;background:#2563eb;color:#fff;border:none;border-radius:6px;font-weight:bold;cursor:pointer;">Als Vektor-PDF speichern / Drucken</button>' +
            '<button onclick="window.close()" style="padding:8px 16px;border:1px solid #94a3b8;border-radius:6px;cursor:pointer;">Schließen</button>' +
        '</div>' +
        '<div class="svg-wrap">' + svgDoc.svgString + '</div>' +
        '<h3 style="margin:10px 0 8px 0;font-size:14px;">Geräte- und Port-Inventar (' + devs.length + ' Geräte)</h3>' +
        '<table><thead><tr><th>IP-Adresse</th><th>Name / Hostname</th><th>Typ</th><th>Gruppe</th><th>MAC-Adresse</th><th>Hersteller</th><th>Offene Ports</th></tr></thead>' +
        '<tbody>' + rowsHtml + '</tbody></table>' +
        '</body></html>'
    );
    win.document.close();
}

/**
 * 4. 4K Ultra-HD PNG Export (3840 × 2160 Pixel)
 * Rendert das Vektor-SVG auf eine hochauflösende 4K-Canvas, völlig unabhängig von der Browserfenstergröße!
 */
function exportNetworkPng4K() {
    var res = buildVectorSvgDocument(false);
    var img = new Image();
    var svgBlob = new Blob([res.svgString], { type: 'image/svg+xml;charset=utf-8' });
    var url = URL.createObjectURL(svgBlob);

    showToast('Generiere hochauflösendes 4K Ultra-HD PNG...');

    img.onload = function () {
        var targetW = 3840;
        var scale = targetW / res.width;
        var targetH = Math.max(2160, Math.round(res.height * scale));

        var canvas = document.createElement('canvas');
        canvas.width = targetW;
        canvas.height = targetH;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(0, 0, targetW, targetH);
        ctx.drawImage(img, 0, 0, targetW, Math.round(res.height * scale));
        URL.revokeObjectURL(url);

        var link = document.createElement('a');
        link.download = 'netzwerkplan_4K_' + new Date().toISOString().slice(0, 10) + '.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
        showToast('4K Ultra-HD PNG (' + targetW + '×' + targetH + ' px) heruntergeladen!');
    };
    img.src = url;
}

/**
 * Öffnet das Detail- & Bearbeitungsfenster (Inspector) für ein ausgewähltes Gerät.
 */
function selectDevice(deviceId) {
    AppState.selectedDeviceId = deviceId;
    AppState.selectedClusterId = null;
    toggleRightSidebar(true);
    var dev = null;
    for (var i = 0; i < AppState.devices.length; i++) {
        if (parseInt(AppState.devices[i].id, 10) === parseInt(deviceId, 10)) {
            dev = AppState.devices[i];
            break;
        }
    }
    var panel = document.getElementById('inspector-content');
    var titleEl = document.getElementById('inspector-title');
    var expBtn = document.getElementById('btn-export-device-details');
    var copyAllBtn = document.getElementById('btn-copy-device-details');
    if (!dev) {
        if (titleEl) titleEl.textContent = 'Geräte-Details';
        if (expBtn) expBtn.style.display = 'none';
        if (copyAllBtn) copyAllBtn.style.display = 'none';
        panel.innerHTML = '<p style="color:var(--text-secondary);font-size:13px;">Klicke auf ein Gerät, um Details, offene Ports, SNMP-Switch-Daten, Gruppen und Port-Zuordnungen anzuzeigen.</p>';
        return;
    }
    var headerName = dev.custom_name || dev.hostname || dev.snmp_sysname || dev.ip_address;
    if (titleEl) {
        titleEl.textContent = headerName + ' (' + dev.ip_address + ')';
        titleEl.title = headerName + ' (' + dev.ip_address + ') – Gedrückt halten zum Verschieben';
    }
    if (expBtn) {
        expBtn.style.display = 'inline-flex';
    }
    if (copyAllBtn) {
        copyAllBtn.style.display = 'inline-flex';
    }

    var currentParentId = 0;
    var currentParentPort = '';
    for (var j = 0; j < AppState.links.length; j++) {
        if (parseInt(AppState.links[j].target_device_id, 10) === parseInt(dev.id, 10)) {
            currentParentId = parseInt(AppState.links[j].source_device_id, 10);
            currentParentPort = AppState.links[j].source_port || '';
            break;
        }
    }

    var snmpHtml = '';
    if (parseInt(dev.snmp_enabled, 10) === 1 || dev.snmp_ports_json) {
        var snmpDetails = {};
        if (dev.snmp_ports_json) {
            try { snmpDetails = JSON.parse(dev.snmp_ports_json); } catch (e) {}
        }
        var macCount = snmpDetails.mac_table ? Object.keys(snmpDetails.mac_table).length : 0;
        var ifCount = snmpDetails.interfaces ? snmpDetails.interfaces.length : 0;

        var printerMiniHtml = '';
        if (snmpDetails.printer) {
            var pInfo = snmpDetails.printer;
            if (pInfo.page_count !== null && pInfo.page_count !== undefined) {
                printerMiniHtml += '<div class="kv-label">Gedruckte Seiten</div><div class="kv-val" style="font-weight:700;color:#fcd34d;">' + escapeHtml(String(pInfo.page_count)) + ' Seiten</div>';
            }
            if (pInfo.supplies && pInfo.supplies.length > 0) {
                var bars = '';
                for (var sp = 0; sp < pInfo.supplies.length; sp++) {
                    var sup = pInfo.supplies[sp];
                    var pct = (sup.percent !== null && sup.percent !== undefined) ? sup.percent : 0;
                    var barCol = '#3b82f6';
                    var sLower = (sup.name || '').toLowerCase();
                    if (sLower.indexOf('cyan') !== -1) barCol = '#06b6d4';
                    else if (sLower.indexOf('magenta') !== -1) barCol = '#ec4899';
                    else if (sLower.indexOf('yellow') !== -1 || sLower.indexOf('gelb') !== -1) barCol = '#eab308';
                    else if (sLower.indexOf('black') !== -1 || sLower.indexOf('schwarz') !== -1) barCol = '#94a3b8';

                    bars += '<div style="margin-bottom:5px;">' +
                        '<div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px;">' +
                            '<span>' + escapeHtml(sup.name) + '</span>' +
                            '<span style="font-weight:700;">' + pct + '%</span>' +
                        '</div>' +
                        '<div style="height:6px;background:rgba(255,255,255,0.1);border-radius:3px;overflow:hidden;">' +
                            '<div style="width:' + pct + '%;height:100%;background:' + barCol + ';"></div>' +
                        '</div>' +
                    '</div>';
                }
                printerMiniHtml += '<div class="kv-label">Tinte / Toner</div><div class="kv-val">' + bars + '</div>';
            }
        }

        snmpHtml = '<div style="background:rgba(16,185,129,0.06);border:1px solid rgba(16,185,129,0.25);border-radius:8px;padding:10px;display:flex;flex-direction:column;gap:8px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap;">' +
                '<span class="badge badge-snmp">SNMP v2c Daten verfügbar</span>' +
                '<div style="display:flex;gap:4px;">' +
                    (userCanEdit() ? '<button class="btn btn-sm" onclick="probeSingleDeviceSnmp(' + dev.id + ')" title="SNMP-Daten &amp; Drucker-Füllstände jetzt live abfragen">🔄 Aktualisieren</button>' : '') +
                    '<button class="btn btn-sm btn-primary" onclick="openSnmpModal(' + dev.id + ')">🔍 Alle SNMP-Daten öffnen</button>' +
                '</div>' +
            '</div>' +
            '<div class="kv-grid" style="margin-top:2px;">' +
                '<div class="kv-label">sysName</div><div class="kv-val">' + renderCopyableValue(dev.snmp_sysname, 'SNMP sysName', '') + '</div>' +
                (snmpDetails.deviceModel ? ('<div class="kv-label">Modell</div><div class="kv-val">' + renderCopyableValue(snmpDetails.deviceModel, 'Gerätemodell', '') + '</div>') : '') +
                (snmpDetails.serialNumber ? ('<div class="kv-label">Seriennummer</div><div class="kv-val">' + renderCopyableValue(snmpDetails.serialNumber, 'Seriennummer', 'font-family:monospace;color:#fcd34d;') + '</div>') : '') +
                (snmpDetails.sysUpTime ? ('<div class="kv-label">SNMP Uptime</div><div class="kv-val" style="color:#34d399;">' + escapeHtml(snmpDetails.sysUpTime) + '</div>') : '') +
                (dev.snmp_location ? ('<div class="kv-label">Standort</div><div class="kv-val">' + escapeHtml(dev.snmp_location) + '</div>') : '') +
                '<div class="kv-label">Interfaces</div><div class="kv-val">' + ifCount + ' Ports / ' + macCount + ' gelernte MACs</div>' +
                printerMiniHtml +
            '</div>' +
        '</div>';
    } else if (userCanEdit() && (dev.device_type === 'printer' || dev.device_type === 'switch' || dev.device_type === 'router' || dev.device_type === 'ap')) {
        snmpHtml = '<div style="background:rgba(59,130,246,0.06);border:1px solid rgba(59,130,246,0.25);border-radius:8px;padding:10px;display:flex;justify-content:space-between;align-items:center;gap:8px;">' +
            '<span style="font-size:11.5px;color:var(--text-secondary);">SNMP v2c Status / Drucker-Füllstände einzeln prüfen:</span>' +
            '<button class="btn btn-sm btn-primary" onclick="probeSingleDeviceSnmp(' + dev.id + ')">🔄 SNMP jetzt abfragen</button>' +
        '</div>';
    }

    var parentOptions = '<option value="0">-- Automatisch (SNMP / Gateway) --</option>';
    for (var k = 0; k < AppState.devices.length; k++) {
        var cand = AppState.devices[k];
        if (parseInt(cand.id, 10) === parseInt(dev.id, 10)) continue;
        var sel = (parseInt(cand.id, 10) === currentParentId) ? 'selected' : '';
        var cname = cand.custom_name || cand.hostname || cand.ip_address;
        parentOptions += '<option value="' + cand.id + '" ' + sel + '>' + escapeHtml(cname + ' (' + cand.ip_address + ')') + '</option>';
    }

    var types = [
        { k: 'router', l: 'Router / Firewall / Gateway' },
        { k: 'switch', l: 'Managed Switch' },
        { k: 'server', l: 'Server / NAS / VM' },
        { k: 'ap', l: 'WLAN Access Point' },
        { k: 'printer', l: 'Netzwerkdrucker' },
        { k: 'workstation', l: 'Workstation / Client' }
    ];
    var typeOpts = '';
    for (var t = 0; t < types.length; t++) {
        var tsel = (dev.device_type === types[t].k) ? 'selected' : '';
        typeOpts += '<option value="' + types[t].k + '" ' + tsel + '>' + types[t].l + '</option>';
    }

    var alertModes = [
        { k: 'none',    l: 'Keine Überwachung (Standard)' },
        { k: 'offline', l: '🔴 Alarm wenn OFFLINE (+ Online-Recovery)' },
        { k: 'online',  l: '🟢 Alarm wenn ONLINE (z.B. neues/fremdes Gerät)' },
        { k: 'both',    l: '🔄 Bei jedem Statuswechsel (Online ↔ Offline)' }
    ];
    var curAlertMode = dev.alert_mode || 'none';
    var alertOpts = '';
    for (var a = 0; a < alertModes.length; a++) {
        var asel = (curAlertMode === alertModes[a].k) ? 'selected' : '';
        alertOpts += '<option value="' + alertModes[a].k + '" ' + asel + '>' + alertModes[a].l + '</option>';
    }

    var alertBadgeHtml = '<span style="color:var(--text-secondary);">Aus</span>';
    if (curAlertMode === 'offline') {
        alertBadgeHtml = '<span class="badge" style="background:rgba(239,68,68,0.2);color:#fca5a5;">🔔 Bei Offline-Ausfall</span>';
    } else if (curAlertMode === 'online') {
        alertBadgeHtml = '<span class="badge" style="background:rgba(16,185,129,0.2);color:#34d399;">🔔 Bei Online-Status</span>';
    } else if (curAlertMode === 'both') {
        alertBadgeHtml = '<span class="badge" style="background:rgba(59,130,246,0.2);color:#93c5fd;">🔔 Online &amp; Offline</span>';
    }

    var isCoreDev = parseInt(dev.is_core || 0, 10) === 1;
    var coreCheckboxHtml = '';
    if (userIsNetExpert()) {
        coreCheckboxHtml =
            '<label style="display:flex;align-items:center;gap:8px;background:rgba(168,85,247,0.12);border:1px solid rgba(168,85,247,0.45);border-radius:6px;padding:8px 10px;cursor:pointer;font-size:12px;color:#e9d5ff;">' +
                '<input type="checkbox" id="edit-dev-iscore" ' + (isCoreDev ? 'checked' : '') + '>' +
                '<span><b>🔒 Als Core-Switch / Kritische Infrastruktur schützen</b> (Wird vor <i>Viewer</i> &amp; <i>Operator</i> komplett ausgeblendet &amp; gesperrt)</span>' +
            '</label>';
    }

    var spInfo = getDeviceSwitchPortInfo(dev);
    var switchPortInspectorHtml = '<span style="color:var(--text-secondary);">Kein Switch-Port zugeordnet</span>';
    if (spInfo && spInfo.switchId) {
        var spOutletStr = (spInfo.outletInfo && spInfo.outletInfo.fullText) ? (' [Dose: ' + spInfo.outletInfo.fullText + ']') : '';
        var spCopyText = spInfo.switchName + ' (' + spInfo.switchIp + ') - Port ' + (spInfo.portName || '-') + (spInfo.vlan ? (' [VLAN ' + spInfo.vlan + ']') : '') + spOutletStr;
        var spOutletBadge = (spInfo.outletInfo && spInfo.outletInfo.fullText)
            ? '<span class="badge" style="background:rgba(14,165,233,0.22);border:1px solid #38bdf8;color:#7dd3fc;">🔌 ' + escapeHtml(spInfo.outletInfo.fullText) + '</span>'
            : '';
        switchPortInspectorHtml =
            '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">' +
                '<span style="font-weight:700;color:#f8fafc;">' + escapeHtml(spInfo.switchName) + '</span>' +
                '<span class="badge" style="background:rgba(16,185,129,0.2);border:1px solid #10b981;color:#34d399;font-family:monospace;">Port ' + escapeHtml(spInfo.portName || '-') + '</span>' +
                (spInfo.vlan ? '<span class="badge" style="background:rgba(245,158,11,0.22);border:1px solid #f59e0b;color:#fcd34d;font-family:monospace;">VLAN ' + spInfo.vlan + '</span>' : '') +
                spOutletBadge +
                '<button class="btn btn-sm" style="padding:2px 7px;font-size:11px;border-color:#10b981;color:#6ee7b7;" onclick="openSwitchGraphicModal(' + spInfo.switchId + ', false, \'' + escapeHtml(spInfo.portName || '') + '\')">🎛 Port / Dose öffnen</button>' +
                '<button type="button" class="btn btn-sm" style="padding:1px 6px;font-size:10.5px;border-color:rgba(148,163,184,0.35);color:#cbd5e1;" onclick="copyToClipboard(\'' + escapeHtml(spCopyText) + '\', \'Switch-Port &amp; Dose\')" title="Switch, Port &amp; Dose kopieren">📋</button>' +
            '</div>';
    }

    var macHistoryBoxHtml = '';
    var devMacHex = normalizeMacHex(dev.mac_address);
    var allHist = AppState.macHistory || [];
    var devHist = [];
    if (devMacHex && devMacHex.length === 12) {
        for (var mh = 0; mh < allHist.length; mh++) {
            if (normalizeMacHex(allHist[mh].mac_address) === devMacHex) {
                devHist.push(allHist[mh]);
            }
        }
    }
    if (devHist.length > 0) {
        var histRows = '';
        for (var hr = 0; hr < devHist.length; hr++) {
            var he = devHist[hr];
            var isCurPort = (hr === 0 && spInfo && parseInt(he.switch_id, 10) === parseInt(spInfo.switchId, 10) && String(he.port_name).toLowerCase() === String(spInfo.portName).toLowerCase());
            var hTime = (he.first_seen || '-').substring(0, 16);
            if (he.last_seen && he.last_seen !== he.first_seen) {
                hTime += ' bis ' + he.last_seen.substring(0, 16);
            }
            var hBtn = parseInt(he.switch_id, 10) > 0
                ? '<button type="button" class="btn btn-sm" style="padding:1px 6px;font-size:10.5px;border-color:rgba(96,165,250,0.45);color:#93c5fd;" onclick="openSwitchGraphicModal(' + parseInt(he.switch_id, 10) + ', false, \'' + escapeHtml(he.port_name || '') + '\')">🎛 Port</button>'
                : '';
            histRows +=
                '<div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap;padding:5px 8px;border-radius:6px;background:' + (isCurPort ? 'rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.35)' : 'rgba(15,23,42,0.55);border:1px solid var(--border-color)') + ';font-size:11.5px;">' +
                    '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">' +
                        '<span style="font-weight:700;color:' + (isCurPort ? '#34d399' : '#cbd5e1') + ';">' + (isCurPort ? '🟢 ' : '🕓 ') + escapeHtml(he.switch_name || 'Switch') + '</span>' +
                        '<span class="badge" style="font-family:monospace;font-size:10.5px;background:rgba(59,130,246,0.2);color:#93c5fd;">Port ' + escapeHtml(he.port_name || '-') + '</span>' +
                        (parseInt(he.vlan_id, 10) > 0 ? '<span class="badge" style="font-family:monospace;font-size:10px;background:rgba(245,158,11,0.18);color:#fcd34d;">VLAN ' + parseInt(he.vlan_id, 10) + '</span>' : '') +
                        hBtn +
                    '</div>' +
                    '<div style="font-family:monospace;font-size:10.5px;color:var(--text-secondary);">' +
                        escapeHtml(hTime) + ' (' + (parseInt(he.sightings_count, 10) || 1) + '×)' +
                    '</div>' +
                '</div>';
        }
        macHistoryBoxHtml =
            '<div style="border-top:1px solid var(--border-color);padding-top:10px;display:flex;flex-direction:column;gap:6px;">' +
                '<div class="panel-title">🕓 Steckplatz-Historie (' + devHist.length + (devHist.length === 1 ? ' Station' : ' Stationen') + ')</div>' +
                histRows +
            '</div>';
    }

    var editFormHtml = '';
    if (userCanEdit()) {
        editFormHtml =
            '<div style="border-top:1px solid var(--border-color);padding-top:12px;display:flex;flex-direction:column;gap:10px;">' +
                '<div class="panel-title">Gerät, Alarm &amp; Switch-Port anpassen</div>' +
                coreCheckboxHtml +
                '<div class="form-group">' +
                    '<label style="color:#fcd34d;font-weight:700;">🔔 Alarm-Überwachung (Online / Offline)</label>' +
                    '<select id="edit-dev-alert" class="select-input" style="border-color:rgba(245,158,11,0.45);">' + alertOpts + '</select>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label>Anzeigename im Netzwerkplan</label>' +
                    '<input type="text" id="edit-dev-name" class="form-input" value="' + escapeHtml(dev.custom_name || '') + '" placeholder="' + escapeHtml(dev.hostname || dev.ip_address) + '">' +
                '</div>' +
                '<div style="display:grid;grid-template-columns: 1fr 1fr;gap:8px;">' +
                    '<div class="form-group">' +
                        '<label>Gerätetyp (Kategorie)</label>' +
                        '<select id="edit-dev-type" class="select-input">' + typeOpts + '</select>' +
                    '</div>' +
                    '<div class="form-group">' +
                        '<label>Eigene Gerätegruppe</label>' +
                        '<input type="text" id="edit-dev-group" class="form-input" value="' + escapeHtml(dev.custom_group || '') + '" placeholder="z.B. IoT, Büro EG, Gäste">' +
                    '</div>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label>Verbunden mit Switch / Gateway</label>' +
                    '<select id="edit-dev-parent" class="select-input">' + parentOptions + '</select>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label>Switch-Port Bezeichnung (z.B. Gi1/0/5 oder Port 8)</label>' +
                    '<input type="text" id="edit-dev-port" class="form-input" value="' + escapeHtml(currentParentPort) + '" placeholder="z.B. Gi0/4">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label>Notizen / Standort</label>' +
                    '<input type="text" id="edit-dev-notes" class="form-input" value="' + escapeHtml(dev.notes || '') + '" placeholder="z.B. Büro 2.04 Dose 3">' +
                '</div>' +
                '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
                    '<button class="btn btn-primary" style="flex:1;" onclick="saveDeviceChanges(' + dev.id + ')">Speichern</button>' +
                    '<button class="btn" onclick="toggleSingleDeviceHidden(' + dev.id + ', ' + (parseInt(dev.is_hidden || 0, 10) === 1 ? 0 : 1) + ')">' +
                        (parseInt(dev.is_hidden || 0, 10) === 1 ? 'Wieder einblenden' : 'Gerät ausblenden') +
                    '</button>' +
                    '<button class="btn btn-danger" onclick="deleteDevice(' + dev.id + ')" title="Dauerhaft löschen">×</button>' +
                '</div>' +
            '</div>';
    } else {
        editFormHtml =
            '<div style="border-top:1px solid var(--border-color);padding-top:10px;font-size:12px;color:var(--text-secondary);">' +
                'Angemeldet als <b>Betrachter (Nur Lesen)</b>. Zum Bearbeiten von Geräten ist die Rolle <i>Operator</i>, <i>Netzwerkexperte</i> oder <i>Administrator</i> erforderlich.' +
            '</div>';
    }

    var isSwitchOrRouter = (dev.device_type === 'switch' || dev.device_type === 'router' || dev.device_type === 'ap' || parseInt(dev.snmp_enabled, 10) === 1);
    var switchGraphicBtnHtml = isSwitchOrRouter
        ? '<button class="btn btn-sm" style="width:100%;justify-content:center;border-color:#10b981;background:linear-gradient(90deg, rgba(16,185,129,0.22), rgba(59,130,246,0.22));color:#a7f3d0;font-weight:700;padding:8px 10px;margin-bottom:4px;" onclick="openSwitchGraphicModal(' + dev.id + ', false)">🎛 Grafische Switch-Belegung, Uplinks &amp; Stacking öffnen</button>'
        : '';

    var coreBannerHtml = isCoreDev
        ? '<div style="background:rgba(168,85,247,0.18);border:1px solid #a855f7;border-radius:6px;padding:6px 10px;margin-bottom:6px;font-size:11.5px;color:#e9d5ff;font-weight:700;display:flex;align-items:center;justify-content:space-between;">' +
            '<span>🔒 CORE-SWITCH / KRITISCHE INFRASTRUKTUR</span>' +
            '<span class="badge" style="background:#a855f7;color:#0f172a;font-size:10px;">NUR NETADMIN / ADMIN</span>' +
          '</div>'
        : '';

    var isFocused = (AppState.focusDeviceId === parseInt(dev.id, 10));
    var focusBtnHtml = coreBannerHtml + switchGraphicBtnHtml + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px;">' +
        (isFocused
            ? '<button class="btn btn-sm" style="flex:1;justify-content:center;border-color:#60a5fa;background:rgba(59,130,246,0.2);color:#93c5fd;" onclick="setFocusDevice(null)">✕ Fokus-Modus beenden (Alle zeigen)</button>'
            : '<button class="btn btn-sm" style="flex:1;justify-content:center;border-color:rgba(96,165,250,0.45);color:#93c5fd;" onclick="setFocusDevice(' + dev.id + ')">🎯 Fokus: Gerät &amp; Nachbarn isolieren</button>') +
        (AppState.expandedClusters[dev.id]
            ? '<button class="btn btn-sm" style="border-color:#10b981;color:#6ee7b7;" onclick="toggleSingleCluster(' + dev.id + ', false)">📁 Clients bündeln</button>'
            : '') +
    '</div>';

    panel.innerHTML =
        focusBtnHtml +
        '<div class="kv-grid">' +
            '<div class="kv-label">Status</div><div class="kv-val"><span class="badge badge-' + dev.status + '">' + dev.status.toUpperCase() + '</span> (' + (dev.latency_ms || '1.0') + ' ms)</div>' +
            '<div class="kv-label">Steckplatz (Port)</div><div class="kv-val">' + switchPortInspectorHtml + '</div>' +
            '<div class="kv-label">Alarm-Modus</div><div class="kv-val">' + alertBadgeHtml + '</div>' +
            '<div class="kv-label">IP-Adresse</div><div class="kv-val">' + renderCopyableValue(dev.ip_address, 'IP-Adresse', 'font-family:monospace;font-weight:700;color:#93c5fd;') + '</div>' +
            '<div class="kv-label">MAC-Adresse</div><div class="kv-val">' + (dev.mac_address ? renderCopyableValue(dev.mac_address, 'MAC-Adresse', 'font-family:monospace;font-weight:600;') : '<span style="color:var(--text-secondary);">Nicht im lokalen ARP</span>') + '</div>' +
            '<div class="kv-label">Hersteller</div><div class="kv-val">' + renderCopyableValue(dev.vendor || 'Unbekannt', 'Hersteller', '') + '</div>' +
            '<div class="kv-label">Hostname</div><div class="kv-val">' + renderCopyableValue(dev.hostname, 'Hostname', '') + '</div>' +
            '<div class="kv-label">Betriebssystem</div><div class="kv-val">' + renderCopyableValue(dev.os_guess, 'Betriebssystem', 'color:#a7f3d0;font-weight:600;') + '</div>' +
            '<div class="kv-label">Dienste / Banner</div><div class="kv-val">' + renderCopyableValue(dev.service_info, 'Dienste / Banner', 'font-size:11.5px;color:#93c5fd;') + '</div>' +
            '<div class="kv-label">Offene Ports</div><div class="kv-val">' + renderCopyableValue(dev.open_ports, 'Offene Ports', '') + '</div>' +
            '<div class="kv-label">Scope</div><div class="kv-val">' + escapeHtml(dev.scope_name || '-') + '</div>' +
        '</div>' +
        macHistoryBoxHtml +
        snmpHtml +
        editFormHtml;
}

/**
 * Speichert die im Inspector bearbeiteten Gerätedaten (Name, Typ, Gruppe, Uplink, Alarm-Modus, Notizen).
 */
function saveDeviceChanges(devId) {
    var alertEl = document.getElementById('edit-dev-alert');
    var isCoreEl = document.getElementById('edit-dev-iscore');
    var payload = {
        id: devId,
        custom_name: document.getElementById('edit-dev-name').value,
        custom_group: document.getElementById('edit-dev-group').value,
        device_type: document.getElementById('edit-dev-type').value,
        alert_mode: alertEl ? alertEl.value : 'none',
        parent_device_id: document.getElementById('edit-dev-parent').value,
        parent_port: document.getElementById('edit-dev-port').value,
        notes: document.getElementById('edit-dev-notes').value
    };
    if (isCoreEl) {
        payload.is_core = isCoreEl.checked ? 1 : 0;
    }
    fetch('api.php?action=save_device', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            showToast('Gerät & Gruppenzuordnung gespeichert.');
            loadTopologyData();
        } else {
            showToast('Fehler: ' + res.error);
        }
    });
}

/**
 * Blendet ein einzelnes Gerät auf der Topologie-Karte aus oder wieder ein.
 */
function toggleSingleDeviceHidden(devId, hideFlag) {
    fetch('api.php?action=toggle_device_visibility', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: devId, is_hidden: hideFlag })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            showToast(hideFlag ? 'Gerät ausgeblendet.' : 'Gerät wieder eingeblendet.');
            loadTopologyData();
        }
    });
}

/**
 * Speichert das Ein-/Ausblenden einer gesamten Gerätegruppe dauerhaft in der Datenbank.
 */
function persistGroupVisibility(groupKind, groupVal, hideFlag) {
    fetch('api.php?action=toggle_group_visibility', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            group_kind: groupKind,
            group_val: groupVal,
            is_hidden: hideFlag,
            scope_id: AppState.selectedScopeId
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            showToast(hideFlag ? 'Ganze Gruppe ausgeblendet.' : 'Ganze Gruppe eingeblendet.');
            loadTopologyData();
        }
    });
}

/**
 * Öffnet den Dialog zur Verwaltung ein- und ausgeblendeter Geräte.
 */
function openVisibilityModal() {
    var modal = document.getElementById('visibility-modal');
    document.getElementById('vis-search').value = '';
    renderVisibilityChecklist();
    modal.classList.add('open');
}

/**
 * Schließt den Sichtbarkeits-Dialog.
 */
function closeVisibilityModal() {
    document.getElementById('visibility-modal').classList.remove('open');
}

/**
 * Rendert die auswählbare Checkliste aller Geräte im Sichtbarkeits-Dialog.
 */
function renderVisibilityChecklist() {
    var groupsBox = document.getElementById('visibility-groups-box');
    var container = document.getElementById('visibility-checklist');
    var search = (document.getElementById('vis-search').value || '').toLowerCase();

    // 1. Gruppen-Checkboxen rendern
    var ghtml = '';
    var typeKeys = Object.keys(TYPE_META);
    for (var t = 0; t < typeKeys.length; t++) {
        var tk = typeKeys[t];
        ghtml += '<button type="button" class="btn btn-sm" onclick="checkGroupInModal(\'type\', \'' + tk + '\', true)">+ ' + escapeHtml(TYPE_META[tk].label) + '</button>' +
                 '<button type="button" class="btn btn-sm btn-danger" onclick="checkGroupInModal(\'type\', \'' + tk + '\', false)">- ' + escapeHtml(TYPE_META[tk].label) + ' abwählen</button>';
    }
    groupsBox.innerHTML = ghtml;

    // 2. Einzelgeräte-Liste rendern
    var html = '';
    for (var i = 0; i < AppState.devices.length; i++) {
        var d = AppState.devices[i];
        if (AppState.selectedScopeId !== 'all' && String(d.scope_id) !== String(AppState.selectedScopeId)) {
            continue;
        }
        var labelName = d.custom_name || d.hostname || d.snmp_sysname || d.ip_address;
        var grpText = d.custom_group ? (' [' + d.custom_group + ']') : '';
        var hay = (d.ip_address + ' ' + labelName + ' ' + (d.custom_group || '') + ' ' + (d.vendor || '') + ' ' + d.device_type).toLowerCase();
        var hideRow = (search !== '' && hay.indexOf(search) === -1) ? 'style="display:none;"' : '';
        var checked = (parseInt(d.is_hidden || 0, 10) === 0) ? 'checked' : '';

        html += '<label class="checklist-row" data-type="' + escapeHtml(d.device_type) + '" data-group="' + escapeHtml(d.custom_group || '') + '" data-hay="' + escapeHtml(hay) + '" ' + hideRow + '>' +
                '<div style="display:flex;align-items:center;gap:10px;">' +
                    '<input type="checkbox" class="vis-dev-chk" value="' + d.id + '" ' + checked + '>' +
                    '<span style="font-family:monospace;font-weight:600;color:#93c5fd;min-width:115px;">' + escapeHtml(d.ip_address) + '</span>' +
                    '<span>' + escapeHtml(labelName + grpText) + '</span>' +
                '</div>' +
                '<span class="badge" style="background:rgba(255,255,255,0.07);color:var(--text-secondary);">' + escapeHtml(d.device_type.toUpperCase()) + '</span>' +
            '</label>';
    }
    container.innerHTML = html || '<div style="padding:16px;color:var(--text-secondary);">Keine Geräte in diesem Scope vorhanden.</div>';
}

/**
 * Markiert oder demarkiert alle Geräte einer Kategorie innerhalb der Sichtbarkeits-Checkliste.
 */
function checkGroupInModal(kind, val, state) {
    var rows = document.querySelectorAll('#visibility-checklist .checklist-row');
    for (var i = 0; i < rows.length; i++) {
        var chk = rows[i].querySelector('.vis-dev-chk');
        if (kind === 'type' && rows[i].getAttribute('data-type') === val) {
            chk.checked = state;
        } else if (kind === 'group' && rows[i].getAttribute('data-group') === val) {
            chk.checked = state;
        }
    }
}

/**
 * Filtert die Einträge in der Sichtbarkeits-Checkliste anhand des Suchfelds.
 */
function filterVisibilityChecklist() {
    var search = (document.getElementById('vis-search').value || '').toLowerCase();
    var rows = document.querySelectorAll('#visibility-checklist .checklist-row');
    for (var i = 0; i < rows.length; i++) {
        var hay = rows[i].getAttribute('data-hay') || '';
        rows[i].style.display = (search === '' || hay.indexOf(search) !== -1) ? 'flex' : 'none';
    }
}

/**
 * Wendet eine Schnellauswahl ('all', 'none', 'infra') auf die Sichtbarkeits-Checkliste an.
 */
function setChecklistPreset(preset) {
    var rows = document.querySelectorAll('#visibility-checklist .checklist-row');
    for (var i = 0; i < rows.length; i++) {
        var chk = rows[i].querySelector('.vis-dev-chk');
        var dtype = rows[i].getAttribute('data-type');
        if (preset === 'all') {
            chk.checked = true;
        } else if (preset === 'none') {
            chk.checked = false;
        } else if (preset === 'infra') {
            chk.checked = (dtype === 'router' || dtype === 'switch' || dtype === 'server' || dtype === 'ap');
        }
    }
}

/**
 * Überträgt die in der Checkliste gesetzten Sichtbarkeiten per Bulk-Request an das Backend.
 */
function saveVisibilityChecklist() {
    var chks = document.querySelectorAll('#visibility-checklist .vis-dev-chk');
    var hiddenIds = [];
    for (var i = 0; i < chks.length; i++) {
        if (!chks[i].checked) {
            hiddenIds.push(parseInt(chks[i].value, 10));
        }
    }
    fetch('api.php?action=bulk_device_visibility', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            mode: 'custom_list',
            scope_id: AppState.selectedScopeId,
            hidden_ids: hiddenIds
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            closeVisibilityModal();
            showToast('Geräte- & Gruppen-Auswahl übernommen.');
            loadTopologyData();
        }
    });
}

/**
 * Löscht ein Gerät nach Sicherheitsabfrage aus der Datenbank.
 */
function deleteDevice(devId) {
    if (!confirm('Dieses Gerät wirklich dauerhaft aus der Datenbank löschen?')) return;
    fetch('api.php?action=delete_device', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: devId })
    })
    .then(function (r) { return r.json(); })
    .then(function () {
        AppState.selectedDeviceId = null;
        loadTopologyData();
    });
}

/**
 * Startet einen Live-Netzwerkscan für einen Scope (oder alle Scopes) und aktualisiert danach die Ansicht.
 */
function triggerScan(scopeId, btnElem) {
    var origText = btnElem ? btnElem.textContent : '';
    if (btnElem) {
        btnElem.disabled = true;
        btnElem.textContent = 'Scan läuft...';
    }
    showToast('Netzwerk-Scan gestartet (Ping, ARP, TCP-Ports & SNMP)...');

    fetch('api.php?action=scan', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scope_id: scopeId })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (btnElem) {
            btnElem.disabled = false;
            btnElem.textContent = origText;
        }
        if (!data.ok) {
            showToast('Scan-Fehler: ' + (data.error || 'Unbekannt'));
            return;
        }
        var msg = 'Scan abgeschlossen!';
        if (data.results && data.results.length > 0) {
            msg = data.results[0].summary;
        }
        showToast(msg);
        loadTopologyData(function () {
            fitNetworkGraph();
        });
    })
    .catch(function (err) {
        if (btnElem) {
            btnElem.disabled = false;
            btnElem.textContent = origText;
        }
        showToast('Fehler beim Scan: ' + err.message);
    });
}

/**
 * Lädt nach Bestätigung die Demo-Topologie in die Datenbank.
 */
function seedDemoData() {
    fetch('api.php?action=seed_demo', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.ok) {
                showToast('SNMP Managed-Switch Beispiel-Topologie (VLAN 10 & VLAN 20) geladen!');
                AppState.selectedScopeId = 'all';
                loadTopologyData(function () {
                    fitNetworkGraph();
                });
            }
        });
}

/**
 * Öffnet das Formular zum Anlegen oder Bearbeiten eines IP-Scopes.
 */
function openScopeModal(scopeId) {
    var modal = document.getElementById('scope-modal');
    var s = null;
    if (scopeId) {
        for (var i = 0; i < AppState.scopes.length; i++) {
            if (parseInt(AppState.scopes[i].id, 10) === parseInt(scopeId, 10)) {
                s = AppState.scopes[i];
                break;
            }
        }
    }
    document.getElementById('scope-id').value = s ? s.id : 0;
    document.getElementById('scope-name').value = s ? s.name : '';
    document.getElementById('scope-cidr').value = s ? s.cidr : '';
    document.getElementById('scope-gw').value = s ? (s.gateway_ip || '') : '';
    document.getElementById('scope-snmp').value = s ? (s.snmp_community || 'public') : 'public';
    document.getElementById('scope-vlan').value = s && s.vlan_id ? s.vlan_id : '';
    document.getElementById('scope-color').value = s ? (s.color || '#3b82f6') : '#3b82f6';
    document.getElementById('scope-desc').value = s ? (s.description || '') : '';
    modal.classList.add('open');
}

/**
 * Schließt das Scope-Formular.
 */
function closeScopeModal() {
    document.getElementById('scope-modal').classList.remove('open');
}

/**
 * Speichert die eingegebenen Scope-Daten (Name, CIDR, Gateway, SNMP-Community, VLAN, Farbe).
 */
function saveScopeForm() {
    var payload = {
        id: document.getElementById('scope-id').value,
        name: document.getElementById('scope-name').value,
        cidr: document.getElementById('scope-cidr').value,
        gateway_ip: document.getElementById('scope-gw').value,
        snmp_community: document.getElementById('scope-snmp').value,
        vlan_id: document.getElementById('scope-vlan').value,
        color: document.getElementById('scope-color').value,
        description: document.getElementById('scope-desc').value,
        is_active: 1
    };
    fetch('api.php?action=save_scope', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (!res.ok) {
            showToast('Fehler: ' + res.error);
            return;
        }
        closeScopeModal();
        showToast('Netzwerk-Scope gespeichert.');
        loadTopologyData();
    });
}

/**
 * Löscht einen IP-Scope samt allen zugehörigen Geräten nach Bestätigung.
 */
function deleteScope(scopeId) {
    if (!confirm('Diesen Netzwerk-Scope inklusive aller zugehörigen Geräte löschen?')) return;
    fetch('api.php?action=delete_scope', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: scopeId })
    })
    .then(function (r) { return r.json(); })
    .then(function () {
        if (String(AppState.selectedScopeId) === String(scopeId)) {
            AppState.selectedScopeId = 'all';
        }
        loadTopologyData();
    });
}

var INV_COLUMNS = [
    { key: 'visible',      label: 'Im Plan',                     defaultShow: true },
    { key: 'status',       label: 'Status',                      defaultShow: true },
    { key: 'ip_address',   label: 'IP-Adresse',                  defaultShow: true },
    { key: 'name',         label: 'Hostname / Bezeichnung',      defaultShow: true },
    { key: 'custom_group', label: 'Gerätegruppe',                defaultShow: true },
    { key: 'device_type',  label: 'Typ',                         defaultShow: true },
    { key: 'switch_port',  label: 'Switch / Port (Steckplatz)',  defaultShow: true },
    { key: 'mac_address',  label: 'MAC-Adresse',                 defaultShow: true },
    { key: 'vendor',       label: 'Hersteller (OUI)',            defaultShow: true },
    { key: 'os_guess',     label: 'Betriebssystem & Dienste',    defaultShow: true },
    { key: 'open_ports',   label: 'Offene TCP-Ports',            defaultShow: true },
    { key: 'snmp',         label: 'SNMP Details',                defaultShow: true },
    { key: 'scope_name',   label: 'Netzwerk-Scope',              defaultShow: true },
    { key: 'last_seen',    label: 'Zuletzt gesehen',             defaultShow: false }
];

var InvTableState = {
    sortKey: 'ip_address',
    sortDir: 'asc',
    hiddenCols: {},
    colFilters: {},      // { colKey: { op: 'contains'|'not_contains'|'starts'|'ends'|'equals'|'not_empty'|'empty', text: '', allowedValues: null | { val: true } } }
    groupBy: '',         // '' | 'custom_group' | 'device_type' | 'vendor' | 'os_guess' | 'switch_name' | 'scope_name' | 'status'
    collapsedGroups: {}, // { groupKey: true }
    openFilterCol: null,
    lastFilteredRows: []
};

try {
    var savedCols = localStorage.getItem('nm_inv_hidden_cols');
    if (savedCols) {
        InvTableState.hiddenCols = JSON.parse(savedCols) || {};
    } else {
        for (var ci = 0; ci < INV_COLUMNS.length; ci++) {
            if (!INV_COLUMNS[ci].defaultShow) {
                InvTableState.hiddenCols[INV_COLUMNS[ci].key] = true;
            }
        }
    }
} catch (e) {}

/**
 * Wandelt eine IPv4-Adresse ('192.168.178.25') in einen 32-Bit-Zahlenwert für die numerisch korrekte Sortierung um.
 */
function ipToNumeric(ipStr) {
    if (!ipStr) return 0;
    var p = String(ipStr).split('.');
    if (p.length !== 4) return 0;
    return ((parseInt(p[0], 10) || 0) * 16777216) +
           ((parseInt(p[1], 10) || 0) * 65536) +
           ((parseInt(p[2], 10) || 0) * 256) +
           (parseInt(p[3], 10) || 0);
}

/**
 * Liefert den reinen Textwert einer Tabellenzelle eines Geräts für die Volltextsuche und Excel-Spaltenfilterung.
 */
function getInventoryCellText(dev, colKey) {
    if (!dev) return '';
    if (colKey === 'visible') {
        return parseInt(dev.is_hidden || 0, 10) === 0 ? 'Sichtbar' : 'Ausgeblendet';
    }
    if (colKey === 'status') {
        return String(dev.status || '').toUpperCase();
    }
    if (colKey === 'ip_address') {
        return String(dev.ip_address || '');
    }
    if (colKey === 'name') {
        return String(dev.custom_name || dev.hostname || '');
    }
    if (colKey === 'custom_group') {
        return String(dev.custom_group || '');
    }
    if (colKey === 'device_type') {
        return String(dev.device_type || '').toUpperCase();
    }
    if (colKey === 'switch_port') {
        var sp = dev._spInfo || getDeviceSwitchPortInfo(dev);
        return sp ? String(sp.searchString || '') : '';
    }
    if (colKey === 'os_guess') {
        var os = String(dev.os_guess || '');
        var sv = String(dev.service_info || '');
        return (os + (sv ? (' ' + sv) : '')).trim();
    }
    if (colKey === 'snmp') {
        return parseInt(dev.snmp_enabled || 0, 10) === 1 ? ('SNMP ' + (dev.snmp_sysname || 'Aktiv')) : '';
    }
    return String(dev[colKey] || '');
}

/**
 * Liefert den normierten Gruppenwert einer Spalte für die Werteliste im Excel-Spaltenfilter-Popover.
 */
function getInventoryChecklistValue(dev, colKey) {
    if (colKey === 'switch_port') {
        var sp = dev._spInfo || getDeviceSwitchPortInfo(dev);
        if (!sp || !sp.switchId) return '';
        var outStr = (sp.outletInfo && sp.outletInfo.badgeText) ? (' [' + sp.outletInfo.badgeText + ']') : '';
        return sp.switchName + ' • ' + (sp.portName || '-') + outStr;
    }
    if (colKey === 'os_guess') {
        return String(dev.os_guess || '').trim();
    }
    return getInventoryCellText(dev, colKey).trim();
}

/**
 * Prüft, ob auf einer bestimmten Spalte der Inventartabelle aktuell ein Excel-Filter aktiv ist.
 */
function isColumnFilterActive(colKey) {
    var f = InvTableState.colFilters[colKey];
    if (!f) return false;
    if (f.op === 'empty' || f.op === 'not_empty') return true;
    if (f.text && String(f.text).trim() !== '') return true;
    if (f.allowedValues && typeof f.allowedValues === 'object') return true;
    return false;
}

/**
 * Prüft, ob ein Gerät die gesetzten Filterregeln (Enthält, Beginnt mit, Werteliste etc.) einer Spalte erfüllt.
 */
function deviceMatchesColumnFilter(dev, colKey, f) {
    if (!f) return true;
    var rawText = getInventoryCellText(dev, colKey);
    var checkVal = getInventoryChecklistValue(dev, colKey);
    var normVal = checkVal === '' ? '__EMPTY__' : checkVal;

    if (f.allowedValues && typeof f.allowedValues === 'object') {
        if (!f.allowedValues[normVal]) {
            return false;
        }
    }

    var op = f.op || 'contains';
    var q = String(f.text || '').trim().toLowerCase();
    var target = String(rawText || '').toLowerCase();

    if (op === 'empty') {
        return target.trim() === '' || target.trim() === '-';
    }
    if (op === 'not_empty') {
        return target.trim() !== '' && target.trim() !== '-';
    }
    if (q === '') {
        return true;
    }
    if (op === 'contains') {
        return target.indexOf(q) !== -1;
    }
    if (op === 'not_contains') {
        return target.indexOf(q) === -1;
    }
    if (op === 'starts') {
        return target.indexOf(q) === 0;
    }
    if (op === 'ends') {
        return target.length >= q.length && target.lastIndexOf(q) === (target.length - q.length);
    }
    if (op === 'equals') {
        return target === q || String(checkVal || '').toLowerCase() === q;
    }
    return true;
}

/**
 * Sortiert die Inventartabelle nach der angegebenen Spalte (aufsteigend / absteigend).
 */
function sortInventoryBy(colKey, forceDir) {
    if (forceDir) {
        InvTableState.sortKey = colKey;
        InvTableState.sortDir = forceDir;
    } else if (InvTableState.sortKey === colKey) {
        InvTableState.sortDir = (InvTableState.sortDir === 'asc') ? 'desc' : 'asc';
    } else {
        InvTableState.sortKey = colKey;
        InvTableState.sortDir = 'asc';
    }
    renderInventoryTable();
}

/**
 * Gruppiert die Inventartabelle nach einem Merkmal (z.B. Typ, Hersteller, Status, Switch) und berechnet Zwischensummen.
 */
function setInventoryGroupBy(groupField) {
    InvTableState.groupBy = groupField || '';
    InvTableState.collapsedGroups = {};
    var sel = document.getElementById('inv-group-by');
    if (sel && sel.value !== InvTableState.groupBy) {
        sel.value = InvTableState.groupBy;
    }
    renderInventoryTable();
}

/**
 * Klappt eine Gruppen-Sektion in der gruppierten Inventartabelle ein oder aus.
 */
function toggleInventoryGroupCollapse(groupKey) {
    if (InvTableState.collapsedGroups[groupKey]) {
        delete InvTableState.collapsedGroups[groupKey];
    } else {
        InvTableState.collapsedGroups[groupKey] = true;
    }
    renderInventoryTable();
}

/**
 * Setzt per Klick auf ein Gruppen-Badge sofort einen Spaltenfilter auf genau diesen Wert.
 */
function quickFilterInventoryByValue(colKey, exactVal) {
    var map = {};
    map[exactVal === '' ? '__EMPTY__' : exactVal] = true;
    var existing = InvTableState.colFilters[colKey];
    if (existing && existing.allowedValues && Object.keys(existing.allowedValues).length === 1 && existing.allowedValues[exactVal === '' ? '__EMPTY__' : exactVal]) {
        delete InvTableState.colFilters[colKey];
    } else {
        InvTableState.colFilters[colKey] = {
            op: 'contains',
            text: '',
            allowedValues: map
        };
    }
    renderInventoryTable();
}

/**
 * Entfernt den aktiven Spaltenfilter einer einzelnen Spalte.
 */
function clearSingleColumnFilter(colKey) {
    delete InvTableState.colFilters[colKey];
    closeInvColumnFilterPopover();
    renderInventoryTable();
}

/**
 * Setzt sämtliche Spaltenfilter, Suchbegriffe und Gruppierungen der Inventartabelle zurück.
 */
function clearAllInventoryFilters() {
    InvTableState.colFilters = {};
    var searchEl = document.getElementById('inv-search');
    if (searchEl) searchEl.value = '';
    closeInvColumnFilterPopover();
    renderInventoryTable();
}

/**
 * Schließt das geöffnete Excel-Spaltenfilter-Popover.
 */
function closeInvColumnFilterPopover() {
    InvTableState.openFilterCol = null;
    var pop = document.getElementById('inv-excel-filter-popover');
    if (pop) pop.style.display = 'none';
}

/**
 * Öffnet das Excel-ähnliche Filter-Popover für eine Tabellenspalte inkl. Sortierung, Textregel und Werteliste.
 */
function openInvColumnFilterPopover(colKey, ev) {
    if (ev && ev.stopPropagation) ev.stopPropagation();
    var pop = document.getElementById('inv-excel-filter-popover');
    if (!pop) return;

    if (InvTableState.openFilterCol === colKey && pop.style.display === 'block') {
        closeInvColumnFilterPopover();
        return;
    }
    InvTableState.openFilterCol = colKey;

    var colMeta = null;
    for (var c = 0; c < INV_COLUMNS.length; c++) {
        if (INV_COLUMNS[c].key === colKey) {
            colMeta = INV_COLUMNS[c];
            break;
        }
    }
    var colLabel = colMeta ? colMeta.label : colKey;
    var curFilter = InvTableState.colFilters[colKey] || { op: 'contains', text: '', allowedValues: null };

    // Berechne alle vorkommenden Werte & deren Summen (unter Berücksichtigung der anderen Spaltenfilter)
    var valCounts = {};
    var totalRowsForCol = 0;
    for (var i = 0; i < AppState.devices.length; i++) {
        var d = AppState.devices[i];
        if (AppState.selectedScopeId !== 'all' && String(d.scope_id) !== String(AppState.selectedScopeId)) {
            continue;
        }
        d._spInfo = getDeviceSwitchPortInfo(d);
        var otherMatched = true;
        var fKeys = Object.keys(InvTableState.colFilters);
        for (var fk = 0; fk < fKeys.length; fk++) {
            var k = fKeys[fk];
            if (k === colKey) continue;
            if (!deviceMatchesColumnFilter(d, k, InvTableState.colFilters[k])) {
                otherMatched = false;
                break;
            }
        }
        if (!otherMatched) continue;
        totalRowsForCol++;
        var v = getInventoryChecklistValue(d, colKey);
        var key = v === '' ? '__EMPTY__' : v;
        valCounts[key] = (valCounts[key] || 0) + 1;
    }

    var distinctKeys = Object.keys(valCounts).sort(function (a, b) {
        if (a === '__EMPTY__') return 1;
        if (b === '__EMPTY__') return -1;
        if (colKey === 'ip_address') return ipToNumeric(a) - ipToNumeric(b);
        return a.toLowerCase().localeCompare(b.toLowerCase());
    });

    var ops = [
        { k: 'contains',     l: 'Enthält' },
        { k: 'not_contains', l: 'Enthält nicht' },
        { k: 'starts',       l: 'Beginnt mit' },
        { k: 'ends',         l: 'Endet mit' },
        { k: 'equals',       l: 'Ist gleich (=)' },
        { k: 'not_empty',    l: 'Ist nicht leer (zugeordnet)' },
        { k: 'empty',        l: 'Ist leer (nicht zugeordnet)' }
    ];
    var opOpts = '';
    for (var o = 0; o < ops.length; o++) {
        opOpts += '<option value="' + ops[o].k + '" ' + (curFilter.op === ops[o].k ? 'selected' : '') + '>' + escapeHtml(ops[o].l) + '</option>';
    }

    var allChecked = (!curFilter.allowedValues);
    var checkRowsHtml = '';
    for (var dk = 0; dk < distinctKeys.length; dk++) {
        var valKey = distinctKeys[dk];
        var cnt = valCounts[valKey];
        var isChecked = allChecked || !!curFilter.allowedValues[valKey];
        var dispLbl = (valKey === '__EMPTY__') ? '(Leer / Ohne Angabe)' : valKey;
        checkRowsHtml +=
            '<label class="inv-flt-val-item" data-search="' + escapeHtml(dispLbl.toLowerCase()) + '" style="display:flex;justify-content:space-between;align-items:center;gap:6px;padding:3px 6px;border-radius:4px;cursor:pointer;font-size:11.5px;">' +
                '<span style="display:flex;align-items:center;gap:6px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' +
                    '<input type="checkbox" class="inv-flt-chk" value="' + escapeHtml(valKey) + '" ' + (isChecked ? 'checked' : '') + ' onchange="onInvFilterItemCheckChange()">' +
                    '<span style="overflow:hidden;text-overflow:ellipsis;' + (valKey === '__EMPTY__' ? 'font-style:italic;color:var(--text-secondary);' : '') + '" title="' + escapeHtml(dispLbl) + '">' + escapeHtml(dispLbl) + '</span>' +
                '</span>' +
                '<span class="badge" style="background:rgba(59,130,246,0.16);color:#93c5fd;font-family:monospace;font-size:10px;flex-shrink:0;">' + cnt + '</span>' +
            '</label>';
    }

    pop.innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border-color);padding-bottom:8px;margin-bottom:8px;">' +
            '<div style="font-weight:700;color:#93c5fd;">⧩ Excel-Filter: ' + escapeHtml(colLabel) + '</div>' +
            '<button class="btn btn-sm" style="padding:1px 6px;" onclick="closeInvColumnFilterPopover()">×</button>' +
        '</div>' +
        '<div style="display:flex;gap:6px;margin-bottom:10px;">' +
            '<button class="btn btn-sm" style="flex:1;justify-content:center;" onclick="sortInventoryBy(\'' + colKey + '\', \'asc\'); closeInvColumnFilterPopover();">▲ Aufsteigend</button>' +
            '<button class="btn btn-sm" style="flex:1;justify-content:center;" onclick="sortInventoryBy(\'' + colKey + '\', \'desc\'); closeInvColumnFilterPopover();">▼ Absteigend</button>' +
        '</div>' +
        '<div style="background:rgba(15,23,42,0.7);border:1px solid var(--border-color);border-radius:6px;padding:8px;margin-bottom:10px;">' +
            '<div style="font-size:11px;font-weight:600;color:var(--text-secondary);margin-bottom:4px;">Text- / Bedingungsfilter:</div>' +
            '<select id="inv-flt-op" class="select-input" style="margin-bottom:6px;padding:4px 8px;font-size:12px;" onchange="onInvFilterOpChange()">' + opOpts + '</select>' +
            '<input type="text" id="inv-flt-text" class="form-input" style="padding:5px 8px;font-size:12px;display:' + ((curFilter.op === 'empty' || curFilter.op === 'not_empty') ? 'none' : 'block') + ';" placeholder="Suchbegriff (z.B. enthält...)" value="' + escapeHtml(curFilter.text || '') + '" onkeydown="if(event.key===\'Enter\') applyInvColumnFilter(\'' + colKey + '\');">' +
        '</div>' +
        '<div style="background:rgba(15,23,42,0.7);border:1px solid var(--border-color);border-radius:6px;padding:8px;margin-bottom:10px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">' +
                '<span style="font-size:11px;font-weight:600;color:var(--text-secondary);">Werte &amp; Summen (' + distinctKeys.length + ' Gruppen):</span>' +
                '<span style="font-size:10.5px;color:#6ee7b7;">∑ ' + totalRowsForCol + '</span>' +
            '</div>' +
            '<input type="text" id="inv-flt-list-search" class="form-input" style="padding:4px 8px;font-size:11.5px;margin-bottom:6px;" placeholder="Werte in Liste suchen..." oninput="filterInvPopoverValueList(this.value)">' +
            '<label style="display:flex;align-items:center;gap:6px;padding:3px 6px;border-bottom:1px solid var(--border-color);margin-bottom:4px;font-weight:700;cursor:pointer;color:#60a5fa;">' +
                '<input type="checkbox" id="inv-flt-chk-all" ' + (allChecked ? 'checked' : '') + ' onchange="toggleAllInvPopoverValues(this.checked)">' +
                '<span>(Alle auswählen)</span>' +
            '</label>' +
            '<div id="inv-flt-val-list" style="max-height:165px;overflow-y:auto;display:flex;flex-direction:column;gap:1px;">' +
                checkRowsHtml +
            '</div>' +
        '</div>' +
        '<div style="display:flex;justify-content:space-between;gap:8px;">' +
            '<button class="btn btn-sm" onclick="clearSingleColumnFilter(\'' + colKey + '\')">✕ Filter löschen</button>' +
            '<button class="btn btn-sm btn-primary" onclick="applyInvColumnFilter(\'' + colKey + '\')">✓ Filter anwenden</button>' +
        '</div>';

    pop.style.display = 'block';
    var rect = (ev && ev.currentTarget) ? ev.currentTarget.getBoundingClientRect() : { left: 200, bottom: 140 };
    var leftPos = Math.min(window.innerWidth - 325, Math.max(12, rect.left - 120));
    var topPos = Math.min(window.innerHeight - 440, Math.max(60, rect.bottom + 6));
    pop.style.left = leftPos + 'px';
    pop.style.top = topPos + 'px';

    var txtInp = document.getElementById('inv-flt-text');
    if (txtInp && txtInp.style.display !== 'none') {
        txtInp.focus();
    }
}

/**
 * Blendet das Texteingabefeld im Spaltenfilter aus, wenn 'Ist leer' oder 'Ist nicht leer' gewählt wurde.
 */
function onInvFilterOpChange() {
    var opEl = document.getElementById('inv-flt-op');
    var txtEl = document.getElementById('inv-flt-text');
    if (!opEl || !txtEl) return;
    if (opEl.value === 'empty' || opEl.value === 'not_empty') {
        txtEl.style.display = 'none';
        txtEl.value = '';
    } else {
        txtEl.style.display = 'block';
        txtEl.focus();
    }
}

/**
 * Wählt im Spaltenfilter-Popover alle Werte der Checkliste auf einmal aus oder ab.
 */
function toggleAllInvPopoverValues(checked) {
    var boxes = document.querySelectorAll('#inv-flt-val-list .inv-flt-val-item');
    for (var i = 0; i < boxes.length; i++) {
        if (boxes[i].style.display === 'none') continue;
        var chk = boxes[i].querySelector('.inv-flt-chk');
        if (chk) chk.checked = checked;
    }
}

/**
 * Synchronisiert den Status der '(Alle auswählen)'-Checkbox im Spaltenfilter-Popover.
 */
function onInvFilterItemCheckChange() {
    var allBoxes = document.querySelectorAll('#inv-flt-val-list .inv-flt-chk');
    var allOn = true;
    for (var i = 0; i < allBoxes.length; i++) {
        if (!allBoxes[i].checked) {
            allOn = false;
            break;
        }
    }
    var allChk = document.getElementById('inv-flt-chk-all');
    if (allChk) allChk.checked = allOn;
}

/**
 * Filtert die auswählbaren Werte innerhalb des Spaltenfilter-Popovers während der Eingabe.
 */
function filterInvPopoverValueList(q) {
    var search = String(q || '').toLowerCase().trim();
    var items = document.querySelectorAll('#inv-flt-val-list .inv-flt-val-item');
    for (var i = 0; i < items.length; i++) {
        var hay = items[i].getAttribute('data-search') || '';
        items[i].style.display = (search === '' || hay.indexOf(search) !== -1) ? 'flex' : 'none';
    }
}

/**
 * Übernimmt die im Spaltenfilter-Popover eingestellten Filterkriterien und aktualisiert die Inventartabelle.
 */
function applyInvColumnFilter(colKey) {
    var opEl = document.getElementById('inv-flt-op');
    var txtEl = document.getElementById('inv-flt-text');
    var allBoxes = document.querySelectorAll('#inv-flt-val-list .inv-flt-chk');

    var op = opEl ? opEl.value : 'contains';
    var txt = txtEl ? txtEl.value.trim() : '';

    var checkedCount = 0;
    var allowedMap = {};
    for (var i = 0; i < allBoxes.length; i++) {
        if (allBoxes[i].checked) {
            checkedCount++;
            allowedMap[allBoxes[i].value] = true;
        }
    }

    var isAllValuesSelected = (checkedCount === allBoxes.length);
    if (op !== 'empty' && op !== 'not_empty' && txt === '' && isAllValuesSelected) {
        delete InvTableState.colFilters[colKey];
    } else {
        InvTableState.colFilters[colKey] = {
            op: op,
            text: txt,
            allowedValues: isAllValuesSelected ? null : allowedMap
        };
    }

    closeInvColumnFilterPopover();
    renderInventoryTable();
}

/**
 * Blendet eine Spalte der Inventartabelle ein oder aus und speichert die Auswahl im localStorage.
 */
function toggleInventoryColumn(colKey, isVisible) {
    if (isVisible) {
        delete InvTableState.hiddenCols[colKey];
    } else {
        InvTableState.hiddenCols[colKey] = true;
    }
    try {
        localStorage.setItem('nm_inv_hidden_cols', JSON.stringify(InvTableState.hiddenCols));
    } catch (e) {}
    renderInventoryTable();
}

/**
 * Stellt die Standard-Spaltenauswahl der Inventartabelle wieder her.
 */
function resetInventoryColumns() {
    InvTableState.hiddenCols = {};
    try {
        localStorage.setItem('nm_inv_hidden_cols', JSON.stringify(InvTableState.hiddenCols));
    } catch (e) {}
    renderInventoryTable();
}

/**
 * Ermittelt den Gruppenschlüssel und den Anzeigenamen eines Geräts für die gruppierte Inventaransicht.
 */
function getDeviceGroupInfo(dev, groupByField) {
    if (groupByField === 'custom_group') {
        var g = String(dev.custom_group || '').trim();
        return { key: g || '(Keine eigene Gerätegruppe)', label: g ? ('📁 Gruppe: ' + g) : '📁 Ohne eigene Gerätegruppe', filterCol: 'custom_group', rawVal: g };
    }
    if (groupByField === 'device_type') {
        var t = String(dev.device_type || 'workstation').toUpperCase();
        return { key: t, label: '🖥️ Gerätetyp: ' + t, filterCol: 'device_type', rawVal: t };
    }
    if (groupByField === 'vendor') {
        var v = String(dev.vendor || '').trim();
        return { key: v || '(Hersteller unbekannt)', label: '🏭 Hersteller: ' + (v || 'Unbekannt'), filterCol: 'vendor', rawVal: v };
    }
    if (groupByField === 'os_guess') {
        var o = String(dev.os_guess || '').trim();
        return { key: o || '(OS unbekannt)', label: '💿 Betriebssystem: ' + (o || 'Unbekannt'), filterCol: 'os_guess', rawVal: o };
    }
    if (groupByField === 'switch_name') {
        var sp = dev._spInfo || getDeviceSwitchPortInfo(dev);
        var sw = (sp && sp.switchName) ? sp.switchName : '(Kein Switch zugeordnet)';
        return { key: sw, label: '🎛 Switch / Verteiler: ' + sw, filterCol: 'switch_port', rawVal: '' };
    }
    if (groupByField === 'scope_name') {
        var sc = String(dev.scope_name || '').trim() || '(Kein Scope)';
        return { key: sc, label: '🌐 Netzwerk-Scope: ' + sc, filterCol: 'scope_name', rawVal: sc };
    }
    if (groupByField === 'status') {
        var st = String(dev.status || 'online').toUpperCase();
        return { key: st, label: (st === 'ONLINE' ? '🟢 Status: ONLINE' : '🔴 Status: OFFLINE'), filterCol: 'status', rawVal: st };
    }
    return { key: 'Alle', label: 'Alle Geräte', filterCol: '', rawVal: '' };
}

/**
 * Erzeugt das HTML einer einzelnen Gerätezeile innerhalb der Inventartabelle.
 */
function renderSingleInventoryRowHtml(dev, activeCols) {
    var isVisible = parseInt(dev.is_hidden || 0, 10) === 0;
    var visChk = '<input type="checkbox" ' + (isVisible ? 'checked' : '') + (userCanEdit() ? '' : ' disabled') +
                 ' onchange="toggleSingleDeviceHidden(' + dev.id + ', this.checked ? 0 : 1)" title="Im Netzwerkplan anzeigen">';
    var grpBadge = dev.custom_group
        ? '<span class="badge" style="background:rgba(245,158,11,0.2);border:1px solid rgba(245,158,11,0.4);color:#fcd34d;cursor:pointer;" onclick="event.stopPropagation(); quickFilterInventoryByValue(\'custom_group\', \'' + escapeHtml(dev.custom_group) + '\');" title="Klicken, um nach Gruppe ' + escapeHtml(dev.custom_group) + ' zu filtern">📁 ' + escapeHtml(dev.custom_group) + '</span>'
        : '<span style="color:var(--text-secondary);">-</span>';
    var coreTag = (parseInt(dev.is_core || 0, 10) === 1)
        ? ' <span class="badge" style="background:rgba(168,85,247,0.25);border:1px solid #a855f7;color:#e9d5ff;" title="Geschützter Core-Switch (Nur für Netzwerkexperten &amp; Admins sichtbar)">🔒 CORE</span>'
        : '';
    var alertTag = (dev.alert_mode && dev.alert_mode !== 'none')
        ? (' <span class="badge" style="background:rgba(239,68,68,0.18);color:#fca5a5;" title="Alarm-Überwachung aktiv (' + escapeHtml(dev.alert_mode) + ')">🔔</span>')
        : '';
    var snmpBadge = parseInt(dev.snmp_enabled, 10) === 1
        ? '<button class="btn btn-sm" style="background:rgba(16,185,129,0.16);border-color:#10b981;color:#34d399;padding:2px 8px;font-size:11px;" onclick="event.stopPropagation(); openSnmpModal(' + dev.id + ');">🔍 SNMP (' + escapeHtml(dev.snmp_sysname || 'Details') + ')</button>'
        : '-';

    var spCellHtml = '-';
    if (dev._spInfo && dev._spInfo.switchId) {
        var spObj = dev._spInfo;
        var vlanPart = spObj.vlan ? (' <span style="color:#fcd34d;">(V' + spObj.vlan + ')</span>') : '';
        var outletPart = (spObj.outletInfo && spObj.outletInfo.badgeText)
            ? (' <span class="badge" style="background:rgba(14,165,233,0.2);border:1px solid rgba(56,189,248,0.45);color:#7dd3fc;font-size:10.5px;margin-left:4px;" title="' + escapeHtml(spObj.outletInfo.fullText) + '">🔌 ' + escapeHtml(spObj.outletInfo.badgeText) + (spObj.outletInfo.room ? (' (' + escapeHtml(spObj.outletInfo.room) + ')') : '') + '</span>')
            : '';
        spCellHtml = '<div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;">' +
            '<button class="btn btn-sm" style="background:rgba(59,130,246,0.15);border-color:rgba(59,130,246,0.45);color:#93c5fd;padding:2px 8px;font-size:11px;font-family:monospace;" ' +
            'onclick="event.stopPropagation(); openSwitchGraphicModal(' + spObj.switchId + ', false, \'' + escapeHtml(spObj.portName || '') + '\');" ' +
            'title="Klicken, um Switch ' + escapeHtml(spObj.switchName) + ' an Port ' + escapeHtml(spObj.portName || '-') + ' zu öffnen">' +
            '🎛 ' + escapeHtml(spObj.switchName) + ' • <b>' + escapeHtml(spObj.portName || '-') + '</b>' + vlanPart +
            '</button>' +
            outletPart +
        '</div>';
    }

    var osAndBanner = '<div style="font-weight:600;color:#a7f3d0;">' + escapeHtml(dev.os_guess || '-') + '</div>' +
        (dev.service_info ? ('<div style="font-size:11px;color:var(--text-secondary);margin-top:2px;">' + escapeHtml(dev.service_info) + '</div>') : '');

    var rowHtml = '<tr style="' + (isVisible ? '' : 'opacity:0.45;') + '" onclick="selectDevice(' + dev.id + ')">';
    for (var colIdx = 0; colIdx < activeCols.length; colIdx++) {
        var ck = activeCols[colIdx].key;
        if (ck === 'visible') {
            rowHtml += '<td style="text-align:center;" onclick="event.stopPropagation();">' + visChk + '</td>';
        } else if (ck === 'status') {
            rowHtml += '<td><span class="badge badge-' + dev.status + '">' + escapeHtml(dev.status) + '</span>' + alertTag + '</td>';
        } else if (ck === 'ip_address') {
            rowHtml += '<td style="font-family:monospace;font-weight:600;color:#93c5fd;">' + escapeHtml(dev.ip_address) + '</td>';
        } else if (ck === 'name') {
            rowHtml += '<td>' + escapeHtml(dev.custom_name || dev.hostname || '-') + coreTag + '</td>';
        } else if (ck === 'custom_group') {
            rowHtml += '<td>' + grpBadge + '</td>';
        } else if (ck === 'device_type') {
            rowHtml += '<td>' + escapeHtml((dev.device_type || '').toUpperCase()) + '</td>';
        } else if (ck === 'switch_port') {
            rowHtml += '<td>' + spCellHtml + '</td>';
        } else if (ck === 'os_guess') {
            rowHtml += '<td>' + osAndBanner + '</td>';
        } else if (ck === 'mac_address') {
            rowHtml += '<td style="font-family:monospace;">' + escapeHtml(dev.mac_address || '-') + '</td>';
        } else if (ck === 'vendor') {
            rowHtml += '<td>' + escapeHtml(dev.vendor || '-') + '</td>';
        } else if (ck === 'open_ports') {
            rowHtml += '<td>' + escapeHtml(dev.open_ports || '-') + '</td>';
        } else if (ck === 'snmp') {
            rowHtml += '<td>' + snmpBadge + '</td>';
        } else if (ck === 'scope_name') {
            rowHtml += '<td>' + escapeHtml(dev.scope_name || '-') + '</td>';
        } else if (ck === 'last_seen') {
            rowHtml += '<td style="font-size:11.5px;color:var(--text-secondary);">' + escapeHtml(dev.last_seen || '-') + '</td>';
        }
    }
    rowHtml += '</tr>';
    return rowHtml;
}

/**
 * Rendert die gesamte Geräte-Inventartabelle inklusive Excel-Spaltenfiltern, Sortierung, Gruppierung und Summenzeilen.
 */
function renderInventoryTable() {
    var thead = document.getElementById('inventory-thead');
    var tbody = document.getElementById('inventory-tbody');
    var tfoot = document.getElementById('inventory-tfoot');
    var summaryBar = document.getElementById('inv-filter-summary-bar');
    var colTogglesBox = document.getElementById('inv-col-toggles');
    var searchEl = document.getElementById('inv-search');
    var search = searchEl ? (searchEl.value || '').toLowerCase() : '';

    // 1. Spalten-Auswahl-Dropdown aktualisieren
    if (colTogglesBox) {
        var togHtml = '';
        for (var c = 0; c < INV_COLUMNS.length; c++) {
            var col = INV_COLUMNS[c];
            var isShown = !InvTableState.hiddenCols[col.key];
            togHtml += '<label class="dropdown-check-item">' +
                '<input type="checkbox" ' + (isShown ? 'checked' : '') + ' onchange="toggleInventoryColumn(\'' + col.key + '\', this.checked)">' +
                '<span>' + escapeHtml(col.label) + '</span>' +
            '</label>';
        }
        colTogglesBox.innerHTML = togHtml;
    }

    // 2. Sortier- & Filterbare Tabellen-Kopfzeile (<thead>) im Excel-Stil rendern
    var activeCols = [];
    var thHtml = '<tr>';
    for (var h = 0; h < INV_COLUMNS.length; h++) {
        var hc = INV_COLUMNS[h];
        if (InvTableState.hiddenCols[hc.key]) continue;
        activeCols.push(hc);
        var arrow = '';
        if (InvTableState.sortKey === hc.key) {
            arrow = (InvTableState.sortDir === 'asc') ? ' <span style="color:#60a5fa;">▲</span>' : ' <span style="color:#60a5fa;">▼</span>';
        } else {
            arrow = ' <span style="opacity:0.3;font-size:10px;">⇅</span>';
        }
        var hasColFlt = isColumnFilterActive(hc.key);
        var fltBtnStyle = hasColFlt
            ? 'background:rgba(245,158,11,0.28);border:1px solid #f59e0b;color:#fde68a;font-weight:700;'
            : 'background:rgba(255,255,255,0.05);border:1px solid transparent;color:var(--text-secondary);';

        thHtml += '<th style="user-select:none;white-space:nowrap;">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">' +
                '<span style="cursor:pointer;" onclick="sortInventoryBy(\'' + hc.key + '\')" title="Klicken zum Sortieren nach ' + escapeHtml(hc.label) + '">' +
                    escapeHtml(hc.label) + arrow +
                '</span>' +
                '<button type="button" style="cursor:pointer;border-radius:4px;padding:1px 5px;font-size:10.5px;line-height:1.2;' + fltBtnStyle + '" ' +
                    'onclick="openInvColumnFilterPopover(\'' + hc.key + '\', event)" title="Excel-Spaltenfilter für ' + escapeHtml(hc.label) + ' öffnen">⧩</button>' +
            '</div>' +
        '</th>';
    }
    thHtml += '</tr>';
    if (thead) thead.innerHTML = thHtml;

    // 3. Geräte filtern (Scope + Schnellsuche + Excel-Spaltenfilter) & sortieren
    var searchMacHex = normalizeMacHex(search);
    var totalInScope = 0;
    var rowsData = [];
    var activeFilterKeys = Object.keys(InvTableState.colFilters);

    for (var i = 0; i < AppState.devices.length; i++) {
        var d = AppState.devices[i];
        if (AppState.selectedScopeId !== 'all' && String(d.scope_id) !== String(AppState.selectedScopeId)) {
            continue;
        }
        totalInScope++;
        var spInfo = getDeviceSwitchPortInfo(d);
        d._spInfo = spInfo;
        if (search !== '') {
            var hay = (
                (d.ip_address || '') + ' ' +
                (d.custom_name || '') + ' ' +
                (d.hostname || '') + ' ' +
                (d.mac_address || '') + ' ' +
                (d.vendor || '') + ' ' +
                (d.os_guess || '') + ' ' +
                (d.service_info || '') + ' ' +
                (d.open_ports || '') + ' ' +
                (d.custom_group || '') + ' ' +
                (spInfo ? spInfo.searchString : '')
            ).toLowerCase();
            var matched = (hay.indexOf(search) !== -1);
            if (!matched && searchMacHex.length >= 2) {
                var devMacHex = normalizeMacHex(d.mac_address || '');
                if (devMacHex && devMacHex.indexOf(searchMacHex) !== -1) {
                    matched = true;
                }
            }
            if (!matched) continue;
        }

        var colMatched = true;
        for (var fk = 0; fk < activeFilterKeys.length; fk++) {
            var fCol = activeFilterKeys[fk];
            if (!deviceMatchesColumnFilter(d, fCol, InvTableState.colFilters[fCol])) {
                colMatched = false;
                break;
            }
        }
        if (!colMatched) continue;

        rowsData.push(d);
    }

    var sk = InvTableState.sortKey;
    var dir = (InvTableState.sortDir === 'asc') ? 1 : -1;
    rowsData.sort(function (a, b) {
        if (sk === 'ip_address') {
            return (ipToNumeric(a.ip_address) - ipToNumeric(b.ip_address)) * dir;
        }
        if (sk === 'visible') {
            return ((parseInt(a.is_hidden || 0, 10)) - (parseInt(b.is_hidden || 0, 10))) * dir;
        }
        if (sk === 'snmp') {
            return ((parseInt(b.snmp_enabled || 0, 10)) - (parseInt(a.snmp_enabled || 0, 10))) * dir;
        }
        var va = getInventoryCellText(a, sk).toLowerCase();
        var vb = getInventoryCellText(b, sk).toLowerCase();
        if (va < vb) return -1 * dir;
        if (va > vb) return 1 * dir;
        return (ipToNumeric(a.ip_address) - ipToNumeric(b.ip_address));
    });

    InvTableState.lastFilteredRows = rowsData;

    // 4. Summen & Gruppen-Auswertung berechnen
    var onlineTotal = 0;
    var offlineTotal = 0;
    var snmpTotal = 0;
    var chipDimension = InvTableState.groupBy || 'custom_group';
    var groupSummaries = {};
    var hasAnyCustomGroup = false;

    for (var sIdx = 0; sIdx < rowsData.length; sIdx++) {
        var sd = rowsData[sIdx];
        if (sd.status === 'online') onlineTotal++; else offlineTotal++;
        if (parseInt(sd.snmp_enabled || 0, 10) === 1) snmpTotal++;
        if (sd.custom_group && String(sd.custom_group).trim() !== '') {
            hasAnyCustomGroup = true;
        }
    }

    // Falls keine eigenen Gerätegruppen vergeben sind und keine Gruppierung gewählt wurde, zeige in den Summen-Chips standardmäßig die Summen pro Gerätetyp
    if (!InvTableState.groupBy && !hasAnyCustomGroup) {
        chipDimension = 'device_type';
    }

    for (var gIdx = 0; gIdx < rowsData.length; gIdx++) {
        var gd = rowsData[gIdx];
        var gInfo = getDeviceGroupInfo(gd, chipDimension);
        if (!groupSummaries[gInfo.key]) {
            groupSummaries[gInfo.key] = {
                key: gInfo.key,
                label: gInfo.label,
                filterCol: gInfo.filterCol,
                rawVal: gInfo.rawVal,
                items: [],
                online: 0,
                offline: 0,
                snmp: 0
            };
        }
        groupSummaries[gInfo.key].items.push(gd);
        if (gd.status === 'online') groupSummaries[gInfo.key].online++; else groupSummaries[gInfo.key].offline++;
        if (parseInt(gd.snmp_enabled || 0, 10) === 1) groupSummaries[gInfo.key].snmp++;
    }

    // 5. Filter- & Gruppen-Summen-Leiste (#inv-filter-summary-bar) rendern
    if (summaryBar) {
        var activeFltBadges = '';
        for (var af = 0; af < activeFilterKeys.length; af++) {
            var afk = activeFilterKeys[af];
            if (!isColumnFilterActive(afk)) continue;
            var afObj = InvTableState.colFilters[afk];
            var colTitle = afk;
            for (var cm = 0; cm < INV_COLUMNS.length; cm++) {
                if (INV_COLUMNS[cm].key === afk) { colTitle = INV_COLUMNS[cm].label; break; }
            }
            var desc = '';
            if (afObj.op === 'empty') desc = 'ist leer';
            else if (afObj.op === 'not_empty') desc = 'nicht leer';
            else if (afObj.text) desc = afObj.op + ' "' + afObj.text + '"';
            if (afObj.allowedValues) {
                var selKeys = Object.keys(afObj.allowedValues);
                desc += (desc ? ' + ' : '') + selKeys.length + ' Wert(e)';
            }
            activeFltBadges += '<span class="badge" style="background:rgba(245,158,11,0.2);border:1px solid #f59e0b;color:#fde68a;display:inline-flex;align-items:center;gap:6px;cursor:pointer;" onclick="clearSingleColumnFilter(\'' + afk + '\')" title="Klicken zum Entfernen dieses Filters">' +
                '⧩ ' + escapeHtml(colTitle) + ': ' + escapeHtml(desc) + ' <b>✕</b>' +
            '</span>';
        }

        var groupKeysSorted = Object.keys(groupSummaries).sort(function (a, b) {
            return groupSummaries[b].items.length - groupSummaries[a].items.length;
        });
        var groupChipsHtml = '';
        for (var gc = 0; gc < groupKeysSorted.length; gc++) {
            var gItem = groupSummaries[groupKeysSorted[gc]];
            var chipClick = gItem.filterCol
                ? 'onclick="quickFilterInventoryByValue(\'' + gItem.filterCol + '\', \'' + escapeHtml(gItem.rawVal) + '\')" style="cursor:pointer;background:rgba(59,130,246,0.14);border:1px solid rgba(59,130,246,0.38);color:#bfdbfe;padding:2px 8px;border-radius:999px;font-size:11px;display:inline-flex;align-items:center;gap:5px;" title="Klicken, um auf ' + escapeHtml(gItem.key) + ' zu filtern"'
                : 'style="background:rgba(59,130,246,0.14);border:1px solid rgba(59,130,246,0.38);color:#bfdbfe;padding:2px 8px;border-radius:999px;font-size:11px;display:inline-flex;align-items:center;gap:5px;"';
            groupChipsHtml += '<span ' + chipClick + '>' +
                '<span>' + escapeHtml(gItem.key) + ':</span>' +
                '<b style="color:#34d399;font-family:monospace;">' + gItem.items.length + '</b>' +
                (gItem.offline > 0 ? ('<span style="color:#fca5a5;font-size:10px;">(' + gItem.offline + ' Off)</span>') : '') +
            '</span>';
        }

        var hasAnyFilter = (search !== '' || activeFltBadges !== '');
        summaryBar.innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">' +
                '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">' +
                    '<span style="font-weight:700;color:#f8fafc;">∑ Gefiltert: <span style="color:#60a5fa;">' + rowsData.length + '</span> von ' + totalInScope + ' Geräten</span>' +
                    '<span class="badge badge-online">' + onlineTotal + ' Online</span>' +
                    (offlineTotal > 0 ? ('<span class="badge badge-offline">' + offlineTotal + ' Offline</span>') : '') +
                    '<span class="badge badge-snmp">' + snmpTotal + ' SNMP</span>' +
                    activeFltBadges +
                '</div>' +
                (hasAnyFilter ? '<button class="btn btn-sm" style="padding:2px 8px;font-size:11px;border-color:#ef4444;color:#fca5a5;" onclick="clearAllInventoryFilters()">✕ Alle Filter zurücksetzen</button>' : '<span style="font-size:11px;color:var(--text-secondary);">💡 Tipp: Klicke auf <b>⧩</b> im Spaltenkopf für Excel-Filter („Enthält", Werteliste &amp; Summen)</span>') +
            '</div>' +
            (groupChipsHtml ? ('<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;"><span style="font-size:11px;color:var(--text-secondary);font-weight:600;">Gruppen-Summen (' + groupKeysSorted.length + '):</span>' + groupChipsHtml + '</div>') : '');
    }

    // 6. Tabellenzeilen (flach oder gruppiert mit Zwischensummen) rendern
    var html = '';
    var colSpanCount = Math.max(1, activeCols.length);

    if (InvTableState.groupBy && rowsData.length > 0) {
        var grpOrder = Object.keys(groupSummaries).sort(function (a, b) {
            return groupSummaries[b].items.length - groupSummaries[a].items.length;
        });
        for (var gi = 0; gi < grpOrder.length; gi++) {
            var grpObj = groupSummaries[grpOrder[gi]];
            var isCollapsed = !!InvTableState.collapsedGroups[grpObj.key];
            var safeGKey = grpObj.key.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            html +=
                '<tr style="background:rgba(30,41,59,0.92);border-top:2px solid #334155;cursor:pointer;" onclick="toggleInventoryGroupCollapse(\'' + safeGKey + '\')">' +
                    '<td colspan="' + colSpanCount + '" style="padding:8px 12px;">' +
                        '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">' +
                            '<div style="display:flex;align-items:center;gap:8px;font-weight:700;color:#f8fafc;font-size:13px;">' +
                                '<span style="color:#60a5fa;width:14px;display:inline-block;">' + (isCollapsed ? '▶' : '▾') + '</span>' +
                                '<span>' + escapeHtml(grpObj.label) + '</span>' +
                            '</div>' +
                            '<div style="display:flex;align-items:center;gap:8px;font-size:12px;">' +
                                '<span class="badge" style="background:rgba(59,130,246,0.22);border:1px solid #3b82f6;color:#93c5fd;font-family:monospace;">∑ Summe: ' + grpObj.items.length + (grpObj.items.length === 1 ? ' Gerät' : ' Geräte') + '</span>' +
                                '<span class="badge badge-online">' + grpObj.online + ' Online</span>' +
                                (grpObj.offline > 0 ? ('<span class="badge badge-offline">' + grpObj.offline + ' Offline</span>') : '') +
                                (grpObj.snmp > 0 ? ('<span class="badge badge-snmp">' + grpObj.snmp + ' SNMP</span>') : '') +
                            '</div>' +
                        '</div>' +
                    '</td>' +
                '</tr>';

            if (!isCollapsed) {
                for (var itemIdx = 0; itemIdx < grpObj.items.length; itemIdx++) {
                    html += renderSingleInventoryRowHtml(grpObj.items[itemIdx], activeCols);
                }
            }
        }
    } else {
        for (var r = 0; r < rowsData.length; r++) {
            html += renderSingleInventoryRowHtml(rowsData[r], activeCols);
        }
    }

    if (html === '') {
        html = '<tr><td colspan="' + colSpanCount + '" style="text-align:center;color:var(--text-secondary);padding:24px;">Keine passenden Geräte für die aktiven Filter gefunden.</td></tr>';
    }
    tbody.innerHTML = html;

    if (tfoot) {
        if (rowsData.length > 0) {
            tfoot.innerHTML =
                '<tr style="background:rgba(15,23,42,0.95);border-top:2px solid #3b82f6;font-weight:700;">' +
                    '<td colspan="' + colSpanCount + '" style="padding:10px 14px;color:#e2e8f0;font-size:12.5px;">' +
                        '∑ Gesamtsumme (aktuelle Filterauswahl): <span style="color:#60a5fa;">' + rowsData.length + ' Geräte</span> ' +
                        '&nbsp;•&nbsp; <span style="color:#34d399;">' + onlineTotal + ' Online</span> ' +
                        '&nbsp;•&nbsp; <span style="color:#fca5a5;">' + offlineTotal + ' Offline</span> ' +
                        '&nbsp;•&nbsp; <span style="color:#93c5fd;">' + snmpTotal + ' SNMP-Switches/Hosts</span>' +
                    '</td>' +
                '</tr>';
        } else {
            tfoot.innerHTML = '';
        }
    }
}

/**
 * Bereinigt und sortiert die per SNMP ausgelesene VLAN-Liste eines Switches.
 */
function normalizeSwitchVlansMap(rawVlans) {
    var out = {};
    if (Array.isArray(rawVlans)) {
        for (var i = 0; i < rawVlans.length; i++) {
            var item = rawVlans[i];
            if (item && item.id !== undefined) {
                out[String(item.id)] = item.name || ('VLAN ' + item.id);
            }
        }
    } else if (rawVlans && typeof rawVlans === 'object') {
        var keys = Object.keys(rawVlans);
        for (var k = 0; k < keys.length; k++) {
            var key = keys[k];
            var val = rawVlans[key];
            if (val && typeof val === 'object' && val.id !== undefined) {
                out[String(val.id)] = val.name || ('VLAN ' + val.id);
            } else {
                out[String(key)] = String(val || ('VLAN ' + key));
            }
        }
    }
    if (Object.keys(out).length === 0) {
        out['1'] = 'Default';
    }
    return out;
}

/**
 * Fragt ein einzelnes Gerät (z.B. Netzwerkdrucker für Tintenfüllstände oder Switch) gezielt per SNMP v2c ab.
 */
function probeSingleDeviceSnmp(devId) {
    if (!userCanEdit()) {
        showToast('Keine Berechtigung.');
        return;
    }
    showToast('🔄 Frage SNMP-Daten & Drucker-Füllstände live ab...');
    fetch('api.php?action=probe_device_snmp&_t=' + Date.now(), {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: parseInt(devId, 10) })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res && res.ok) {
            showToast('✅ SNMP-Daten erfolgreich aktualisiert!');
            loadTopologyData(function () {
                selectDevice(parseInt(devId, 10));
            });
        } else {
            showToast('SNMP-Abfrage fehlgeschlagen: ' + ((res && res.error) ? res.error : 'Unbekannt'));
        }
    })
    .catch(function (err) {
        showToast('Netzwerkfehler bei SNMP-Abfrage: ' + (err && err.message ? err.message : String(err)));
    });
}

/**
 * Öffnet die tabellarische SNMP-Detailansicht (Schnittstellen, MAC-Tabelle, LLDP-Nachbarn) eines Managed Switches.
 */
function openSnmpModal(devId) {
    var dev = null;
    for (var i = 0; i < AppState.devices.length; i++) {
        if (parseInt(AppState.devices[i].id, 10) === parseInt(devId, 10)) {
            dev = AppState.devices[i];
            break;
        }
    }
    if (!dev) return;

    var snmp = {};
    if (dev.snmp_ports_json) {
        try { snmp = JSON.parse(dev.snmp_ports_json) || {}; } catch (e) {}
    }

    var titleEl = document.getElementById('snmp-modal-title');
    var bodyEl = document.getElementById('snmp-modal-body');
    var devTitle = dev.custom_name || dev.snmp_sysname || dev.hostname || dev.ip_address;
    titleEl.textContent = 'SNMP-Inspektor: ' + devTitle + ' (' + dev.ip_address + ')';

    // Map für bekannte MAC -> Gerät im Netzwerk
    var devByMac = {};
    for (var m = 0; m < AppState.devices.length; m++) {
        if (AppState.devices[m].mac_address) {
            devByMac[AppState.devices[m].mac_address.toUpperCase()] = AppState.devices[m];
        }
    }

    // 1. System-Metadaten (MIB-II & Entity-MIB)
    var html = '<div style="display:flex;justify-content:space-between;align-items:center;background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.35);border-radius:8px;padding:10px 14px;flex-wrap:wrap;gap:10px;">' +
        '<div style="font-size:12.5px;color:#a7f3d0;">Möchtest du die Ports dieses Geräts als <b>grafische 19-Zoll Switch-Frontblende inkl. Kaskadierung &amp; Stacking</b> sehen?</div>' +
        '<button class="btn btn-sm btn-primary" onclick="closeSnmpModal(); openSwitchGraphicModal(' + dev.id + ', false);">🎛 Zur grafischen 19&quot; Switch-Belegung wechseln</button>' +
    '</div>' +
    '<div style="background:rgba(15,23,42,0.7);border:1px solid var(--border-color);border-radius:8px;padding:14px;">' +
        '<div class="panel-title" style="margin-bottom:10px;color:#34d399;">1. SNMP System- &amp; Hardware-Informationen (MIB-II / Entity-MIB)</div>' +
        '<div class="kv-grid" style="grid-template-columns: 180px 1fr;">' +
            '<div class="kv-label">sysName (Hostname)</div><div class="kv-val" style="font-weight:700;">' + escapeHtml(snmp.sysName || dev.snmp_sysname || '-') + '</div>' +
            '<div class="kv-label">Gerätemodell</div><div class="kv-val">' + escapeHtml(snmp.deviceModel || dev.vendor || '-') + '</div>' +
            '<div class="kv-label">Seriennummer</div><div class="kv-val" style="font-family:monospace;color:#fcd34d;">' + escapeHtml(snmp.serialNumber || '-') + '</div>' +
            '<div class="kv-label">SNMP Uptime</div><div class="kv-val" style="color:#34d399;font-weight:600;">' + escapeHtml(snmp.sysUpTime || '-') + '</div>' +
            '<div class="kv-label">sysLocation (Standort)</div><div class="kv-val">' + escapeHtml(snmp.sysLocation || dev.snmp_location || '-') + '</div>' +
            '<div class="kv-label">sysContact (Kontakt)</div><div class="kv-val">' + escapeHtml(snmp.sysContact || '-') + '</div>' +
            '<div class="kv-label">sysDescr (Firmware/OS)</div><div class="kv-val">' + escapeHtml(snmp.sysDescr || dev.snmp_sysdescr || '-') + '</div>' +
            '<div class="kv-label">sysObjectID</div><div class="kv-val" style="font-family:monospace;font-size:11.5px;">' + escapeHtml(snmp.sysObjectID || dev.snmp_sysobjectid || '-') + '</div>' +
        '</div>' +
    '</div>';

    // 2. Drucker-MIB (falls Gerät ein Drucker ist und Printer-MIB Daten liefert)
    if (snmp.printer) {
        var prt = snmp.printer;
        html += '<div style="background:rgba(15,23,42,0.7);border:1px solid var(--border-color);border-radius:8px;padding:14px;">' +
            '<div class="panel-title" style="margin-bottom:10px;color:#fcd34d;">2. Drucker-MIB Status, Seitenzähler &amp; Tinten-/Toner-Füllstände</div>' +
            '<div class="kv-grid" style="grid-template-columns: 180px 1fr;margin-bottom:12px;">' +
                '<div class="kv-label">Gedruckte Seiten gesamt</div><div class="kv-val" style="font-weight:700;font-size:14px;color:#fcd34d;">' + escapeHtml(prt.page_count !== null ? (prt.page_count + ' Seiten') : '-') + '</div>' +
                '<div class="kv-label">Drucker-Display Text</div><div class="kv-val">' + escapeHtml(prt.console_display || '-') + '</div>' +
            '</div>';

        if (prt.supplies && prt.supplies.length > 0) {
            html += '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:10px;">';
            for (var sp = 0; sp < prt.supplies.length; sp++) {
                var s = prt.supplies[sp];
                var pct = (s.percent !== null && s.percent !== undefined) ? s.percent : 0;
                var bCol = '#3b82f6';
                var sl = (s.name || '').toLowerCase();
                if (sl.indexOf('cyan') !== -1) bCol = '#06b6d4';
                else if (sl.indexOf('magenta') !== -1) bCol = '#ec4899';
                else if (sl.indexOf('yellow') !== -1 || sl.indexOf('gelb') !== -1) bCol = '#eab308';
                else if (sl.indexOf('black') !== -1 || sl.indexOf('schwarz') !== -1) bCol = '#94a3b8';

                html += '<div style="background:var(--bg-panel);border:1px solid var(--border-color);border-radius:6px;padding:10px;">' +
                    '<div style="display:flex;justify-content:space-between;font-size:12.5px;font-weight:600;margin-bottom:6px;">' +
                        '<span>' + escapeHtml(s.name) + '</span>' +
                        '<span>' + pct + '%</span>' +
                    '</div>' +
                    '<div style="height:8px;background:rgba(255,255,255,0.08);border-radius:4px;overflow:hidden;">' +
                        '<div style="width:' + pct + '%;height:100%;background:' + bCol + ';"></div>' +
                    '</div>' +
                    '<div style="font-size:11px;color:var(--text-secondary);margin-top:4px;">Füllstand: ' + s.level + ' von ' + s.max + '</div>' +
                '</div>';
            }
            html += '</div>';
        }
        html += '</div>';
    }

    // 2b. Konfigurierte VLANs auf dem Switch (Q-BRIDGE-MIB)
    var normVlans = normalizeSwitchVlansMap(snmp.vlans);
    var vKeys = Object.keys(normVlans);
    if (vKeys.length > 0) {
        var vlanPills = '';
        for (var vk = 0; vk < vKeys.length; vk++) {
            var vid = vKeys[vk];
            var vname = normVlans[vid] || ('VLAN ' + vid);
            vlanPills += '<span class="badge" style="background:rgba(59,130,246,0.18);border:1px solid rgba(59,130,246,0.45);color:#93c5fd;font-family:monospace;padding:4px 10px;font-size:12px;">VLAN ' + escapeHtml(String(vid)) + ': <b style="color:#f8fafc;">' + escapeHtml(String(vname)) + '</b></span>';
        }
        html += '<div style="background:rgba(15,23,42,0.7);border:1px solid var(--border-color);border-radius:8px;padding:14px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px;">' +
                '<div class="panel-title" style="margin-bottom:0;color:#93c5fd;">Eingerichtete VLANs auf diesem Switch (Q-BRIDGE-MIB – ' + vKeys.length + ' VLANs)</div>' +
                '<button class="btn btn-sm" style="border-color:#3b82f6;color:#93c5fd;" onclick="closeSnmpModal(); openSwitchGraphicModal(' + dev.id + ', false);">⚙️ VLANs &amp; Ports per SSH verwalten</button>' +
            '</div>' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap;">' + vlanPills + '</div>' +
        '</div>';
    }

    // 3. Netzwerk-Interfaces & Switch-Ports (IF-MIB)
    var ifs = snmp.interfaces || [];
    html += '<div style="background:rgba(15,23,42,0.7);border:1px solid var(--border-color);border-radius:8px;padding:14px;">' +
        '<div class="panel-title" style="margin-bottom:10px;color:#60a5fa;">Netzwerk-Interfaces &amp; Switch-Ports (IF-MIB – ' + ifs.length + ' Ports)</div>';
    if (ifs.length > 0) {
        html += '<table class="data-table"><thead><tr>' +
            '<th>Index</th><th>Port / Interface</th><th>VLAN (PVID)</th><th>Beschreibung (Alias)</th><th>Status</th><th>Speed</th><th>Traffic RX</th><th>Traffic TX</th><th>Pakete-Fehler</th>' +
            '</tr></thead><tbody>';
        for (var idx = 0; idx < ifs.length; idx++) {
            var inf = ifs[idx];
            var stBadge = (inf.status === 'up') ? '<span class="badge badge-online">UP</span>' : '<span class="badge badge-offline">DOWN</span>';
            if (inf.admin_status === 'down') {
                stBadge = '<span class="badge badge-offline" title="Port administrativ abgeschaltet (shutdown)">ADMIN OFF</span>';
            }
            var pVlan = inf.vlan ? parseInt(inf.vlan, 10) : 1;
            var vlanBadge = '<span class="badge" style="font-family:monospace;background:' + (pVlan !== 1 ? 'rgba(245,158,11,0.22);color:#fcd34d;border:1px solid #f59e0b;' : 'rgba(148,163,184,0.15);color:#cbd5e1;') + '">VLAN ' + pVlan + '</span>';
            html += '<tr>' +
                '<td style="font-family:monospace;">#' + escapeHtml(String(inf.ifIndex)) + '</td>' +
                '<td style="font-weight:600;font-family:monospace;color:#93c5fd;">' + escapeHtml(inf.name || '-') + '</td>' +
                '<td>' + vlanBadge + '</td>' +
                '<td>' + escapeHtml(inf.alias || '-') + '</td>' +
                '<td>' + stBadge + '</td>' +
                '<td>' + escapeHtml(inf.speed || '-') + '</td>' +
                '<td style="font-family:monospace;">' + (inf.rx_mb ? (inf.rx_mb + ' MB') : '-') + '</td>' +
                '<td style="font-family:monospace;">' + (inf.tx_mb ? (inf.tx_mb + ' MB') : '-') + '</td>' +
                '<td style="font-family:monospace;color:' + (inf.in_errors > 0 ? '#fca5a5' : 'var(--text-secondary)') + ';">' + (inf.in_errors || 0) + '</td>' +
            '</tr>';
        }
        html += '</tbody></table>';
    } else {
        html += '<div style="font-size:12.5px;color:var(--text-secondary);">Keine IF-MIB Interface-Einträge gemeldet.</div>';
    }
    html += '</div>';

    // 4. Bridge-MIB MAC-Adresstabelle & LLDP-Nachbarn
    var macTab = snmp.mac_table || {};
    var macKeys = Object.keys(macTab);
    if (macKeys.length > 0) {
        html += '<div style="background:rgba(15,23,42,0.7);border:1px solid var(--border-color);border-radius:8px;padding:14px;">' +
            '<div class="panel-title" style="margin-bottom:10px;color:#a7f3d0;">Gelernte Switch-Port MAC-Adresstabelle (Bridge-MIB – ' + macKeys.length + ' Einträge)</div>' +
            '<table class="data-table"><thead><tr><th>Switch-Port</th><th>MAC-Adresse</th><th>Zugeordnetes Gerät im Netzwerkplan</th></tr></thead><tbody>';
        for (var mk = 0; mk < macKeys.length; mk++) {
            var macAddr = macKeys[mk];
            var mEntry = macTab[macAddr];
            var known = devByMac[macAddr.toUpperCase()];
            var knownTxt = known
                ? ('<span style="color:#60a5fa;font-weight:600;">' + escapeHtml(known.custom_name || known.hostname || known.ip_address) + ' (' + escapeHtml(known.ip_address) + ')</span>')
                : '<span style="color:var(--text-secondary);">Externe / Unbekannte MAC</span>';
            html += '<tr>' +
                '<td style="font-family:monospace;font-weight:700;color:#34d399;">' + escapeHtml(mEntry.port_name || ('Port ' + mEntry.bridge_port)) + '</td>' +
                '<td style="font-family:monospace;">' + escapeHtml(macAddr) + '</td>' +
                '<td>' + knownTxt + '</td>' +
            '</tr>';
        }
        html += '</tbody></table></div>';
    }

    bodyEl.innerHTML = html;
    document.getElementById('snmp-modal').classList.add('open');
}

/**
 * Schließt das tabellarische SNMP-Detailfenster.
 */
function closeSnmpModal() {
    document.getElementById('snmp-modal').classList.remove('open');
}

var SwitchGraphicState = {
    currentDevId: null,
    isDemo: false,
    portsByKey: {},
    selectedPortKey: null
};

/**
 * Schließt die interaktive grafische 19-Zoll-Switch-Port-Ansicht.
 */
function closeSwitchGraphicModal() {
    var m = document.getElementById('switch-graphic-modal');
    if (m) m.classList.remove('open');
}

/**
 * Setzt die Portanzahl (8/16/24/48) für die grafische Darstellung von Switches ohne SNMP-Daten.
 */
function setFallbackSwitchPorts(devId, portCount) {
    try {
        localStorage.setItem('nm_sw_ports_' + devId, String(portCount));
    } catch (e) {}
    openSwitchGraphicModal(devId, false);
}

/**
 * Erzeugt ein realistisches Demo-Modell (2x gestackter 28-Port Core-Switch + Upstream-Firewall + 2 kaskadierte Etagen-Switche),
 * damit man Stacking & Switch-Kaskadierung jederzeit live testen kann.
 */
function buildDemoSwitchGraphicModel() {
    var units = [
        { unit: 1, role: 'Master (Active)', model: 'Cisco Catalyst C9300-24P (Stack Unit #1)', serial: 'FCW2248G0A1', ports: [] },
        { unit: 2, role: 'Stack-Member (Standby)', model: 'Cisco Catalyst C9300-24P (Stack Unit #2)', serial: 'FCW2248G0B9', ports: [] }
    ];

    var demoDevicesUnit1 = {
        1:  { name: 'SRV-PROXMOX-01', ip: '192.168.178.10', mac: '00:25:90:8A:11:01', type: 'server', speed: '1 Gbps', rx_mb: 14820.4, tx_mb: 9410.2 },
        2:  { name: 'SRV-SYNOLOGY-NAS', ip: '192.168.178.11', mac: '00:11:32:9C:44:12', type: 'server', speed: '1 Gbps', rx_mb: 28410.0, tx_mb: 41290.8 },
        3:  { name: 'PC-BUERO-CHEF', ip: '192.168.178.25', mac: '3C:7C:3F:1B:88:01', type: 'workstation', speed: '1 Gbps', rx_mb: 412.5, tx_mb: 128.0 },
        4:  { name: 'PC-BUCHHALTUNG-01', ip: '192.168.178.26', mac: '3C:7C:3F:1B:88:02', type: 'workstation', speed: '1 Gbps', rx_mb: 318.1, tx_mb: 94.6 },
        5:  { name: 'PC-VERTRIEB-01', ip: '192.168.178.27', mac: '3C:7C:3F:1B:88:03', type: 'workstation', speed: '100 Mbps', rx_mb: 85.2, tx_mb: 19.4 },
        7:  { name: 'PRN-KYOCERA-EG', ip: '192.168.178.40', mac: '00:17:C8:55:20:99', type: 'printer', speed: '100 Mbps', rx_mb: 44.0, tx_mb: 12.1 },
        9:  { name: 'AP-EMPFANG-WIFI6', ip: '192.168.178.51', mac: '74:83:C2:AA:10:01', type: 'ap', speed: '1 Gbps', rx_mb: 3120.8, tx_mb: 890.4 },
        10: { name: 'AP-KONFERENZ-WIFI6', ip: '192.168.178.52', mac: '74:83:C2:AA:10:02', type: 'ap', speed: '1 Gbps', rx_mb: 1950.2, tx_mb: 610.0 },
        12: { name: 'PC-EMPFANG-TERMINAL', ip: '192.168.178.31', mac: 'DC:A6:32:71:09:CC', type: 'workstation', speed: '1 Gbps', rx_mb: 215.0, tx_mb: 64.2, in_errors: 14 },
        15: { name: 'PC-ENTWICKLUNG-01', ip: '192.168.178.60', mac: 'F8:75:A4:90:11:22', type: 'workstation', speed: '1 Gbps', rx_mb: 1640.5, tx_mb: 720.1 },
        16: { name: 'PC-ENTWICKLUNG-02', ip: '192.168.178.61', mac: 'F8:75:A4:90:11:23', type: 'workstation', speed: '1 Gbps', rx_mb: 1190.0, tx_mb: 510.8 },
        25: { name: 'FW-FRITZBOX-GATEWAY', ip: '192.168.178.1', mac: '60:B5:8D:83:0C:16', type: 'router', speed: '10 Gbps', rx_mb: 68400.0, tx_mb: 22910.5, is_uplink: true, uplink_role: 'upstream', remote_port: 'LAN 1 (WAN-Gateway)' },
        26: { name: 'SW-ETAGE1-ARUBA', ip: '192.168.178.3', mac: 'B4:B5:2F:44:10:80', type: 'switch', speed: '10 Gbps', rx_mb: 19400.2, tx_mb: 31200.6, is_uplink: true, uplink_role: 'downstream', remote_port: 'Port 25 (SFP+ Uplink)' },
        27: { name: 'StackWise-480 Ring A -> Unit #2', ip: 'Stack-Backplane', mac: '-', type: 'stack', speed: '40 Gbps', rx_mb: 94120.0, tx_mb: 91040.0, is_stack: true, remote_port: 'Stack-Port 2/1' },
        28: { name: 'StackWise-480 Ring B -> Unit #2', ip: 'Stack-Backplane', mac: '-', type: 'stack', speed: '40 Gbps', rx_mb: 88210.0, tx_mb: 89300.0, is_stack: true, remote_port: 'Stack-Port 2/2' }
    };

    var demoDevicesUnit2 = {
        1:  { name: 'SRV-BACKUP-VAULT', ip: '192.168.178.15', mac: '00:25:90:8A:99:05', type: 'server', speed: '1 Gbps', rx_mb: 51200.4, tx_mb: 4120.0 },
        2:  { name: 'PC-DISPO-01', ip: '192.168.178.71', mac: '40:B0:76:12:33:01', type: 'workstation', speed: '1 Gbps', rx_mb: 290.4, tx_mb: 88.2 },
        3:  { name: 'PC-DISPO-02', ip: '192.168.178.72', mac: '40:B0:76:12:33:02', type: 'workstation', speed: '1 Gbps', rx_mb: 310.0, tx_mb: 91.5 },
        6:  { name: 'PRN-BROTHER-VERSAND', ip: '192.168.178.42', mac: '30:05:5C:88:19:44', type: 'printer', speed: '100 Mbps', rx_mb: 18.2, tx_mb: 4.9 },
        8:  { name: 'AP-LAGER-HALLE', ip: '192.168.178.55', mac: '74:83:C2:AA:10:09', type: 'ap', speed: '1 Gbps', rx_mb: 980.1, tx_mb: 340.7 },
        11: { name: 'IOT-ZEITERFASSUNG', ip: '192.168.178.90', mac: 'B8:27:EB:45:12:09', type: 'workstation', speed: '100 Mbps', rx_mb: 12.4, tx_mb: 8.1 },
        26: { name: 'SW-LAGER-NETGEAR', ip: '192.168.178.4', mac: '28:C6:8E:91:00:11', type: 'switch', speed: '10 Gbps', rx_mb: 8420.5, tx_mb: 12940.0, is_uplink: true, uplink_role: 'downstream', remote_port: 'Gi0/24 (Trunk)' },
        27: { name: 'StackWise-480 Ring A -> Unit #1', ip: 'Stack-Backplane', mac: '-', type: 'stack', speed: '40 Gbps', rx_mb: 91040.0, tx_mb: 94120.0, is_stack: true, remote_port: 'Stack-Port 1/1' },
        28: { name: 'StackWise-480 Ring B -> Unit #1', ip: 'Stack-Backplane', mac: '-', type: 'stack', speed: '40 Gbps', rx_mb: 89300.0, tx_mb: 88210.0, is_stack: true, remote_port: 'Stack-Port 1/2' }
    };

    for (var u = 0; u < 2; u++) {
        var unitNo = u + 1;
        var map = (unitNo === 1) ? demoDevicesUnit1 : demoDevicesUnit2;
        for (var p = 1; p <= 28; p++) {
            var isSfp = (p >= 25);
            var prefix = isSfp ? ('Te' + unitNo + '/0/' + p) : ('Gi' + unitNo + '/0/' + p);
            var d = map[p] || null;
            units[u].ports.push({
                key: 'u' + unitNo + '_p' + p,
                unit: unitNo,
                portNum: p,
                name: prefix,
                shortName: String(p),
                isSfp: isSfp,
                status: d ? 'up' : 'down',
                speed: d ? d.speed : 'Down',
                alias: d ? (d.is_uplink ? ('Uplink: ' + d.name) : (d.is_stack ? 'Stack-Backplane' : d.name)) : 'Freier Port',
                rx_mb: d ? d.rx_mb : 0,
                tx_mb: d ? d.tx_mb : 0,
                in_errors: (d && d.in_errors) ? d.in_errors : 0,
                isUplink: !!(d && d.is_uplink),
                uplinkRole: (d && d.uplink_role) ? d.uplink_role : null,
                isStackPort: !!(d && d.is_stack),
                remotePort: (d && d.remote_port) ? d.remote_port : '',
                connectedDevices: d ? [{
                    id: 0,
                    name: d.name,
                    ip: d.ip,
                    mac: d.mac,
                    type: d.type
                }] : []
            });
        }
    }

    return {
        isDemo: true,
        switchName: 'SW-CORE-STACK01 (Demo 2x Switch-Stack)',
        switchIp: '192.168.178.2',
        vendor: 'Cisco Catalyst 9300 Stack (2 Units)',
        model: '2x C9300-24P-A im StackWise-480 Verbund',
        upstreamLinks: [
            {
                localPort: 'Te1/0/25',
                remotePort: 'LAN 1 (WAN-Gateway)',
                remoteName: 'FW-FRITZBOX-GATEWAY',
                remoteIp: '192.168.178.1',
                remoteType: 'router',
                remoteId: 0,
                linkType: 'LLDP / 10G SFP+ Uplink'
            }
        ],
        downstreamSwitches: [
            {
                localPort: 'Te1/0/26',
                remotePort: 'Port 25 (SFP+ Uplink)',
                remoteName: 'SW-ETAGE1-ARUBA',
                remoteIp: '192.168.178.3',
                remoteType: 'switch',
                remoteId: 0,
                linkType: 'LLDP / 10G Trunk (Unit #1)'
            },
            {
                localPort: 'Te2/0/26',
                remotePort: 'Gi0/24 (Trunk)',
                remoteName: 'SW-LAGER-NETGEAR',
                remoteIp: '192.168.178.4',
                remoteType: 'switch',
                remoteId: 0,
                linkType: 'Bridge-MIB / 10G Trunk (Unit #2)'
            }
        ],
        units: units
    };
}

/**
 * Baut das Switch-Grafik-Modell aus den echten Scan- & SNMP-Daten eines Switches/Routers im Netzwerkplan
 */
function buildRealSwitchGraphicModel(dev) {
    var devById = {};
    var devByMac = {};
    for (var i = 0; i < AppState.devices.length; i++) {
        var d = AppState.devices[i];
        devById[d.id] = d;
        if (d.mac_address) {
            devByMac[d.mac_address.toUpperCase()] = d;
        }
    }

    var snmp = {};
    if (dev.snmp_ports_json) {
        try { snmp = JSON.parse(dev.snmp_ports_json) || {}; } catch (e) {}
    }

    var swName = dev.custom_name || dev.snmp_sysname || dev.hostname || dev.ip_address;

    // 1. Upstream-Verbindungen (An welchem Switch/Router hängt dieser Switch?)
    var upstreamLinks = [];
    // 2. Downstream-Switche / APs (Welche weiteren Switche/APs hängen an diesem Switch?)
    var downstreamSwitches = [];
    // 3. Zugeordnete Geräte pro lokalem Portnamen
    var devicesByPortName = {};

    function addDeviceToPortMap(portNameRaw, targetDev, remotePortName, isUplinkFlag, uplinkRoleStr) {
        if (!portNameRaw || !targetDev) return;
        var key = String(portNameRaw).trim();
        if (!devicesByPortName[key]) {
            devicesByPortName[key] = {
                list: [],
                remotePort: remotePortName || '',
                isUplink: !!isUplinkFlag,
                uplinkRole: uplinkRoleStr || null
            };
        }
        var exists = false;
        for (var x = 0; x < devicesByPortName[key].list.length; x++) {
            if (parseInt(devicesByPortName[key].list[x].id, 10) === parseInt(targetDev.id, 10)) {
                exists = true;
                break;
            }
        }
        if (!exists) {
            devicesByPortName[key].list.push({
                id: targetDev.id,
                name: targetDev.custom_name || targetDev.hostname || targetDev.snmp_sysname || targetDev.ip_address,
                ip: targetDev.ip_address,
                mac: targetDev.mac_address || '-',
                type: targetDev.device_type || 'workstation'
            });
        }
        if (isUplinkFlag) {
            devicesByPortName[key].isUplink = true;
            devicesByPortName[key].uplinkRole = uplinkRoleStr;
            if (remotePortName) devicesByPortName[key].remotePort = remotePortName;
        }
    }

    for (var l = 0; l < AppState.links.length; l++) {
        var lnk = AppState.links[l];
        var srcId = parseInt(lnk.source_device_id, 10);
        var dstId = parseInt(lnk.target_device_id, 10);
        var myId = parseInt(dev.id, 10);

        if (dstId === myId && devById[srcId]) {
            // Dieser Switch ist Ziel -> srcId ist der übergeordnete Switch/Router (Upstream)
            var parentDev = devById[srcId];
            var myUplinkPort = (lnk.target_port && lnk.target_port !== 'eth0' && lnk.target_port !== 'Uplink') ? lnk.target_port : 'Port 1 (Uplink)';
            var parentDownPort = lnk.source_port || 'LAN-Port';
            upstreamLinks.push({
                localPort: myUplinkPort,
                remotePort: parentDownPort,
                remoteName: parentDev.custom_name || parentDev.hostname || parentDev.snmp_sysname || parentDev.ip_address,
                remoteIp: parentDev.ip_address,
                remoteType: parentDev.device_type,
                remoteId: parentDev.id,
                linkType: (lnk.link_type || 'uplink').toUpperCase()
            });
            addDeviceToPortMap(myUplinkPort, parentDev, parentDownPort, true, 'upstream');
        } else if (srcId === myId && devById[dstId]) {
            // Dieser Switch ist Quelle -> dstId hängt an diesem Switch
            // Wichtig: Bei einem Switch nur echte Port-Zuordnungen (SNMP Bridge-MIB, LLDP, Trunk oder Manuell) anzeigen,
            // niemals pauschale Subnetz/WLAN-Geräte ohne Port-Nachweis!
            if (dev.device_type === 'switch' && lnk.link_type === 'subnet' && !lnk.source_port) {
                continue;
            }
            var childDev = devById[dstId];
            var localPort = lnk.source_port || ('Port #' + (l + 1));
            var childPort = (lnk.target_port && lnk.target_port !== 'eth0') ? lnk.target_port : '';
            var isChildInfra = (childDev.device_type === 'switch' || childDev.device_type === 'router' || childDev.device_type === 'ap');
            if (isChildInfra) {
                downstreamSwitches.push({
                    localPort: localPort,
                    remotePort: childPort || 'Uplink',
                    remoteName: childDev.custom_name || childDev.hostname || childDev.snmp_sysname || childDev.ip_address,
                    remoteIp: childDev.ip_address,
                    remoteType: childDev.device_type,
                    remoteId: childDev.id,
                    linkType: (lnk.link_type || 'trunk').toUpperCase()
                });
            }
            addDeviceToPortMap(localPort, childDev, childPort, isChildInfra, isChildInfra ? 'downstream' : null);
        }
    }

    // Ergänze außerdem Geräte aus der SNMP Bridge-MIB MAC-Tabelle (ohne Upstream-Uplink-Port Richtung Router/FRITZ!Box)
    if (snmp.mac_table) {
        var swUpstreamPorts = getSwitchUpstreamPortsMap(dev, snmp);
        var mKeys = Object.keys(snmp.mac_table);
        for (var mk = 0; mk < mKeys.length; mk++) {
            var macStr = mKeys[mk];
            var mEntry = snmp.mac_table[macStr];
            var pName = mEntry.port_name || ('Port ' + mEntry.bridge_port);
            var matchedDev = devByMac[macStr.toUpperCase()];
            if (matchedDev && parseInt(matchedDev.id, 10) !== parseInt(dev.id, 10)) {
                if (swUpstreamPorts[String(pName).trim().toLowerCase()] && matchedDev.device_type !== 'router') {
                    continue;
                }
                var isInfra = (matchedDev.device_type === 'switch' || matchedDev.device_type === 'router');
                addDeviceToPortMap(pName, matchedDev, '', isInfra, isInfra ? 'downstream' : null);
            }
        }
    }

    // 4. Physische Switch-Units & Ports aus SNMP IF-MIB aufbauen (oder Fallback-Chassis falls kein SNMP aktiv)
    var rawIfs = snmp.interfaces || [];
    var physicalIfs = [];
    for (var idx = 0; idx < rawIfs.length; idx++) {
        var inf = rawIfs[idx];
        var nLower = String(inf.name || '').toLowerCase();
        if (nLower.indexOf('vlan') === 0 || nLower === 'lo' || nLower.indexOf('loopback') === 0 || nLower.indexOf('null') === 0 || nLower.indexOf('cpu') === 0) {
            continue;
        }
        physicalIfs.push(inf);
    }

    var unitsMap = {};
    if (physicalIfs.length > 0) {
        for (var p = 0; p < physicalIfs.length; p++) {
            var pi = physicalIfs[p];
            var pNameStr = String(pi.name || ('Port ' + (p + 1)));
            var uNo = pi.stack_unit ? parseInt(pi.stack_unit, 10) : 1;
            var pNo = pi.port_num ? parseInt(pi.port_num, 10) : (p + 1);

            // Falls ältere SNMP-JSON noch kein stack_unit Feld hatte, live aus Portnamen wie Gi2/0/15 extrahieren
            var stackMatch = pNameStr.match(/^(?:Gi|Fa|Te|Tw|Fo|Eth|ge-|xe-|et-|Port)?\s*([1-8])\/(\d+)\/(\d+)$/i);
            if (stackMatch) {
                uNo = parseInt(stackMatch[1], 10);
                pNo = parseInt(stackMatch[3], 10);
            } else {
                var numMatch = pNameStr.match(/(\d+)$/);
                if (numMatch) pNo = parseInt(numMatch[1], 10);
            }

            if (!unitsMap[uNo]) {
                unitsMap[uNo] = [];
            }

            var mapped = devicesByPortName[pNameStr] || null;
            var nUp = pNameStr.toLowerCase();
            var isSfpPort = (nUp.indexOf('te') === 0 || nUp.indexOf('sfp') !== -1 || nUp.indexOf('xg') !== -1 || pNo > 24 && pNo <= 28 || pNo > 48);
            var isStack = (nUp.indexOf('stack') !== -1 || (pi.alias && String(pi.alias).toLowerCase().indexOf('stack') !== -1));

            unitsMap[uNo].push({
                key: 'u' + uNo + '_p' + pNo + '_' + p,
                unit: uNo,
                portNum: pNo,
                name: pNameStr,
                shortName: String(pNo),
                isSfp: isSfpPort,
                status: (pi.status === 'up' || (mapped && mapped.list.length > 0)) ? 'up' : 'down',
                admin_status: pi.admin_status || 'up',
                vlan: pi.vlan ? parseInt(pi.vlan, 10) : 1,
                port_mode: pi.port_mode || ((mapped && mapped.isUplink) ? 'trunk' : 'access'),
                speed: pi.speed || ((mapped && mapped.list.length > 0) ? '1 Gbps' : 'Down'),
                alias: pi.alias || (mapped && mapped.list.length > 0 ? mapped.list[0].name : ''),
                rawAlias: pi.alias || '',
                outletInfo: getPortOutletInfo(dev.id, pNameStr, pi.alias || ''),
                rx_mb: pi.rx_mb || 0,
                tx_mb: pi.tx_mb || 0,
                in_errors: pi.in_errors || 0,
                isUplink: !!(mapped && mapped.isUplink),
                uplinkRole: mapped ? mapped.uplinkRole : null,
                isStackPort: isStack,
                remotePort: mapped ? mapped.remotePort : '',
                connectedDevices: mapped ? mapped.list : []
            });
        }
    } else {
        // Fallback für Switches/Router ohne SNMP IF-MIB:
        // Ohne aktiviertes SNMP verrät ein Switch über das Netzwerk seine Portanzahl nicht automatisch.
        // Standardmäßig 28 Ports (24x RJ45 + 4x SFP+) für Managed Switches anzeigen, oder benutzerdefinierte Auswahl aus localStorage nutzen.
        var pKeys = Object.keys(devicesByPortName);
        var savedPortCount = 0;
        try {
            savedPortCount = parseInt(localStorage.getItem('nm_sw_ports_' + dev.id) || '0', 10);
        } catch (e) {}

        var defaultSwitchPorts = (dev.device_type === 'switch') ? 28 : 8;
        var minPorts = savedPortCount > 0 ? savedPortCount : Math.max(defaultSwitchPorts, pKeys.length <= 8 ? 8 : (pKeys.length <= 16 ? 16 : (pKeys.length <= 28 ? 28 : 52)));
        unitsMap[1] = [];
        var usedPortNums = {};

        for (var pk = 0; pk < pKeys.length; pk++) {
            var pLabel = pKeys[pk];
            var pInfo = devicesByPortName[pLabel];
            var pNumber = pk + 1;
            var nm = pLabel.match(/(\d+)$/);
            if (nm) {
                var candNo = parseInt(nm[1], 10);
                if (candNo >= 1 && candNo <= 96 && !usedPortNums[candNo]) {
                    pNumber = candNo;
                }
            }
            while (usedPortNums[pNumber]) pNumber++;
            usedPortNums[pNumber] = true;
            if (pNumber > minPorts) {
                minPorts = (pNumber <= 28) ? 28 : 52;
            }
            var lblLower = pLabel.toLowerCase();
            var isSfpFallback = (lblLower.indexOf('sfp') !== -1 || lblLower.indexOf('te') === 0 || lblLower.indexOf('xg') !== -1 || (minPorts === 28 && pNumber > 24) || (minPorts === 52 && pNumber > 48));

            unitsMap[1].push({
                key: 'u1_p' + pNumber,
                unit: 1,
                portNum: pNumber,
                name: pLabel,
                shortName: String(pNumber),
                isSfp: isSfpFallback,
                status: 'up',
                admin_status: 'up',
                vlan: 1,
                speed: pInfo.isUplink ? '1 Gbps Uplink' : '1 Gbps',
                alias: pInfo.list.length > 0 ? pInfo.list[0].name : pLabel,
                rawAlias: '',
                outletInfo: getPortOutletInfo(dev.id, pLabel, ''),
                rx_mb: 0,
                tx_mb: 0,
                in_errors: 0,
                isUplink: pInfo.isUplink,
                uplinkRole: pInfo.uplinkRole,
                isStackPort: false,
                remotePort: pInfo.remotePort || '',
                connectedDevices: pInfo.list
            });
        }

        for (var fp = 1; fp <= minPorts; fp++) {
            if (!usedPortNums[fp]) {
                var isSfpSlot = ((minPorts === 28 && fp > 24) || (minPorts === 52 && fp > 48));
                var fbName = isSfpSlot ? ('SFP ' + fp) : ('Port ' + fp);
                unitsMap[1].push({
                    key: 'u1_p' + fp,
                    unit: 1,
                    portNum: fp,
                    name: fbName,
                    shortName: String(fp),
                    isSfp: isSfpSlot,
                    status: 'down',
                    admin_status: 'up',
                    vlan: 1,
                    speed: 'Down',
                    alias: 'Freier Port',
                    rawAlias: '',
                    outletInfo: getPortOutletInfo(dev.id, fbName, ''),
                    rx_mb: 0,
                    tx_mb: 0,
                    in_errors: 0,
                    isUplink: false,
                    uplinkRole: null,
                    isStackPort: false,
                    remotePort: '',
                    connectedDevices: []
                });
            }
        }
    }

    var unitKeys = Object.keys(unitsMap).map(function (k) { return parseInt(k, 10); }).sort(function (a, b) { return a - b; });
    var units = [];
    for (var uk = 0; uk < unitKeys.length; uk++) {
        var uNum = unitKeys[uk];
        var sortedPorts = unitsMap[uNum].sort(function (a, b) { return a.portNum - b.portNum; });
        units.push({
            unit: uNum,
            role: (unitKeys.length > 1) ? (uNum === 1 ? 'Master (Stack Unit #1)' : ('Stack-Member (Unit #' + uNum + ')')) : 'Standalone Chassis',
            model: (snmp.deviceModel || dev.vendor || 'Managed Switch') + (unitKeys.length > 1 ? (' – Unit #' + uNum) : ''),
            serial: snmp.serialNumber || '-',
            ports: sortedPorts
        });
    }

    return {
        isDemo: false,
        isCore: parseInt(dev.is_core || 0, 10) === 1,
        snmpEnabled: parseInt(dev.snmp_enabled || 0, 10) === 1,
        deviceType: dev.device_type,
        switchName: swName,
        switchIp: dev.ip_address,
        vendor: dev.vendor || 'Managed Switch',
        model: snmp.deviceModel || dev.snmp_sysdescr || dev.vendor || 'Netzwerk-Switch',
        vlans: normalizeSwitchVlansMap(snmp.vlans),
        sshUser: dev.switch_ssh_user || '',
        hasSshPass: parseInt(dev.has_ssh_pass || 0, 10) === 1,
        hasEnablePass: parseInt(dev.has_enable_pass || 0, 10) === 1,
        snmpCommunity: dev.switch_snmp_community || 'public',
        hasSnmpWrite: parseInt(dev.has_snmp_write || 0, 10) === 1,
        cliProfile: dev.cli_profile || 'cisco_smb',
        cliProfileLabel: dev.cli_profile_label || 'Cisco Small Business CLI (SG/SF/CBS Serie)',
        cliProfileIsManual: parseInt(dev.cli_profile_is_manual || 0, 10) === 1,
        upstreamLinks: upstreamLinks,
        downstreamSwitches: downstreamSwitches,
        units: units
    };
}

/**
 * Öffnet die interaktive grafische 19-Zoll-Switch-Frontplatten-Ansicht inkl. Port-Status, PoE, VLANs und Dosen-Zuordnung.
 */
function openSwitchGraphicModal(devId, isDemoPreview, keepSelectedPortName) {
    var modal = document.getElementById('switch-graphic-modal');
    var titleEl = document.getElementById('switch-graphic-title');
    var bodyEl = document.getElementById('switch-graphic-body');
    if (!modal || !bodyEl) return;

    var model = null;
    if (isDemoPreview) {
        model = buildDemoSwitchGraphicModel();
    } else {
        var dev = null;
        for (var i = 0; i < AppState.devices.length; i++) {
            if (parseInt(AppState.devices[i].id, 10) === parseInt(devId, 10)) {
                dev = AppState.devices[i];
                break;
            }
        }
        if (!dev) {
            model = buildDemoSwitchGraphicModel();
        } else {
            model = buildRealSwitchGraphicModel(dev);
        }
    }

    SwitchGraphicState.currentDevId = devId;
    SwitchGraphicState.isDemo = model.isDemo;
    SwitchGraphicState.currentModel = model;
    SwitchGraphicState.portsByKey = {};
    SwitchGraphicState.selectedPortKey = null;

    var isStacked = model.units.length > 1;
    var coreHeaderBadge = model.isCore
        ? ' <span class="badge" style="background:rgba(168,85,247,0.3);border:1px solid #a855f7;color:#e9d5ff;margin-left:8px;">🔒 CORE-SWITCH (Nur NetAdmin / Admin)</span>'
        : '';
    titleEl.innerHTML = '🎛 ' + escapeHtml(model.switchName) + ' <span style="font-family:monospace;color:#93c5fd;font-size:14px;">(' + escapeHtml(model.switchIp) + ')</span>' +
        coreHeaderBadge +
        (isStacked ? (' <span class="badge" style="background:rgba(168,85,247,0.25);border:1px solid #a855f7;color:#e9d5ff;margin-left:8px;">📚 STACK: ' + model.units.length + ' SWITCHE</span>') : '');

    var html = '';

    // =========================================================================
    // 0. SSH / SNMP ZUGANGSDATEN & MULTI-VENDOR KONFIGURATIONS-LEISTE
    // =========================================================================
    if (!model.isDemo && userCanEdit()) {
        var sshBadge = (model.cliProfile === 'snmp_set')
            ? (model.hasSnmpWrite
                ? '<span class="badge" style="background:rgba(16,185,129,0.22);border:1px solid #10b981;color:#34d399;">🟢 SNMPv2c-Write hinterlegt</span>'
                : '<span class="badge" style="background:rgba(245,158,11,0.22);border:1px solid #f59e0b;color:#fcd34d;">🟡 SNMPv2c-Write Community fehlt</span>')
            : (model.hasSshPass
                ? '<span class="badge" style="background:rgba(16,185,129,0.22);border:1px solid #10b981;color:#34d399;">🟢 SSH-Zugang hinterlegt (' + escapeHtml(model.sshUser || 'admin') + ')</span>'
                : '<span class="badge" style="background:rgba(245,158,11,0.22);border:1px solid #f59e0b;color:#fcd34d;">🟡 Noch kein SSH-Zugang für diesen Switch hinterlegt</span>');

        var profileBadge = '<span class="badge" style="background:rgba(168,85,247,0.18);border:1px solid rgba(168,85,247,0.45);color:#e9d5ff;" title="Steuerungs-Treiber für VLAN- &amp; Port-Befehle">' +
            (model.cliProfileIsManual ? '⚙️ ' : '🤖 ') + escapeHtml(model.cliProfileLabel) +
        '</span>';

        var vlanCount = model.vlans ? Object.keys(model.vlans).length : 1;
        var vlanSummaryPills = '';
        if (model.vlans) {
            var vIds = Object.keys(model.vlans);
            for (var vi = 0; vi < vIds.length; vi++) {
                var vId = vIds[vi];
                vlanSummaryPills += '<span class="badge" style="background:rgba(59,130,246,0.18);border:1px solid rgba(59,130,246,0.45);color:#93c5fd;font-family:monospace;font-size:11px;">VLAN ' + escapeHtml(String(vId)) + ' (' + escapeHtml(String(model.vlans[vId])) + ')</span>';
            }
        }

        var profList = [
            { k: 'auto',              l: '🤖 Automatisch erkennen (' + model.cliProfileLabel + ')' },
            { k: 'cisco_smb',         l: 'Cisco Small Business CLI (SG200/250/300/350/500, SF, CBS)' },
            { k: 'cisco_ios',         l: 'Cisco IOS / Catalyst & kompatible CLI (FS.com, Dell, Arista)' },
            { k: 'hp_aruba',          l: 'HP ProCurve / ArubaOS-Switch CLI' },
            { k: 'tplink_jetstream',  l: 'TP-Link JetStream / Omada CLI' },
            { k: 'netgear_fastpath',  l: 'Netgear ProSAFE / Ubiquiti EdgeSwitch (FASTPATH CLI)' },
            { k: 'mikrotik_routeros', l: 'MikroTik RouterOS CLI' },
            { k: 'zyxel_gs',          l: 'Zyxel GS / XGS Enterprise CLI' },
            { k: 'snmp_set',          l: 'Standard SNMPv2c-SET (Q-BRIDGE-MIB / IF-MIB ohne SSH)' }
        ];
        var curProfSel = model.cliProfileIsManual ? model.cliProfile : 'auto';
        var profOptsHtml = '';
        for (var pr = 0; pr < profList.length; pr++) {
            var psel = (curProfSel === profList[pr].k) ? 'selected' : '';
            profOptsHtml += '<option value="' + profList[pr].k + '" ' + psel + '>' + escapeHtml(profList[pr].l) + '</option>';
        }

        var credsFormBlock = '';
        if (userIsNetExpert()) {
            credsFormBlock =
                '<div id="sw-creds-form-box" style="display:' + (model.hasSshPass ? 'none' : 'block') + ';border-top:1px dashed rgba(148,163,184,0.25);padding-top:10px;">' +
                    '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(195px, 1fr));gap:10px;align-items:end;">' +
                        '<div class="form-group" style="margin:0;">' +
                            '<label>Hersteller / CLI-Befehlsprofil</label>' +
                            '<select id="sw-cred-cli-profile" class="select-input">' + profOptsHtml + '</select>' +
                        '</div>' +
                        '<div class="form-group" style="margin:0;">' +
                            '<label>SSH-Benutzername (Switch CLI)</label>' +
                            '<input type="text" id="sw-cred-ssh-user" class="form-input" value="' + escapeHtml(model.sshUser || 'cisco') + '" placeholder="z.B. cisco oder admin">' +
                        '</div>' +
                        '<div class="form-group" style="margin:0;">' +
                            '<label>SSH-Passwort ' + (model.hasSshPass ? '<span style="color:#34d399;">(gespeichert)</span>' : '') + '</label>' +
                            '<input type="password" id="sw-cred-ssh-pass" class="form-input" value="" placeholder="' + (model.hasSshPass ? '•••••••• (leer = behalten)' : 'SSH-Passwort eingeben') + '">' +
                        '</div>' +
                        '<div class="form-group" style="margin:0;">' +
                            '<label>Enable-Secret (optional für Cisco IOS)</label>' +
                            '<input type="password" id="sw-cred-enable-pass" class="form-input" value="" placeholder="' + (model.hasEnablePass ? '•••••••• (gespeichert)' : 'Leer = wie SSH-Passwort') + '">' +
                        '</div>' +
                        '<div class="form-group" style="margin:0;">' +
                            '<label>SNMPv2c Lese-Community</label>' +
                            '<input type="text" id="sw-cred-snmp-comm" class="form-input" value="' + escapeHtml(model.snmpCommunity || 'public') + '" placeholder="public">' +
                        '</div>' +
                        '<div class="form-group" style="margin:0;">' +
                            '<label>SNMP Write-Community (optional)</label>' +
                            '<input type="password" id="sw-cred-snmp-write" class="form-input" value="" placeholder="' + (model.hasSnmpWrite ? '•••••••• (gespeichert)' : 'Optional für SNMP-SET') + '">' +
                        '</div>' +
                        '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
                            '<button id="btn-save-sw-creds" class="btn btn-primary btn-sm" style="padding:8px 12px;" onclick="saveSwitchCredentials(' + devId + ', true)">🔌 Speichern &amp; testen</button>' +
                            '<button class="btn btn-sm" style="padding:8px 10px;" onclick="saveSwitchCredentials(' + devId + ', false)">💾 Speichern</button>' +
                        '</div>' +
                    '</div>' +
                    '<div style="margin-top:10px;padding-top:8px;border-top:1px solid rgba(148,163,184,0.18);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">' +
                        '<label style="display:flex;align-items:center;gap:8px;font-size:12px;color:#e9d5ff;cursor:pointer;">' +
                            '<input type="checkbox" id="sw-cred-is-core" ' + (model.isCore ? 'checked' : '') + '>' +
                            '<span><b>🔒 Als Core-Switch / Kritische Infrastruktur einstufen</b> (Wird vor Betrachtern &amp; Technikern/Operatoren komplett verborgen &amp; gesperrt)</span>' +
                        '</label>' +
                    '</div>' +
                    '<div id="sw-creds-status-msg" style="margin-top:8px;font-size:12px;display:none;"></div>' +
                '</div>';
        }

        html += '<div style="background:rgba(15,23,42,0.85);border:1px solid rgba(59,130,246,0.38);border-radius:10px;padding:10px 14px;display:flex;flex-direction:column;gap:10px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">' +
                '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">' +
                    '<span style="font-size:12.5px;font-weight:700;color:#f8fafc;">🔑 Multi-Vendor Switch-Steuerung:</span>' +
                    sshBadge +
                    profileBadge +
                    '<span style="font-size:11.5px;color:var(--text-secondary);">VLANs (' + vlanCount + '):</span>' +
                    vlanSummaryPills +
                '</div>' +
                (userIsNetExpert()
                    ? '<button class="btn btn-sm" style="border-color:#60a5fa;color:#93c5fd;" onclick="toggleSwitchCredsForm()">🔑 Zugangsdaten, Core-Schutz &amp; Hersteller-Profil ' + (model.hasSshPass ? 'ändern / testen' : 'einrichten') + '</button>'
                    : '<span class="badge" style="background:rgba(148,163,184,0.18);color:#cbd5e1;">🔒 SSH-Zugangsdaten &amp; Trunks nur für Netzwerkexperten (netadmin)</span>') +
            '</div>' +
            credsFormBlock +
        '</div>';
    }

    if (model.isDemo) {
        html += '<div style="background:rgba(168,85,247,0.12);border:1px solid rgba(168,85,247,0.45);border-radius:8px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">' +
            '<div style="font-size:12.5px;color:#e9d5ff;"><b>👁 Vorschau-Modus (Demo-Stack &amp; Kaskade):</b> Zeigt exemplarisch einen <b>2x gestackten Core-Switch</b> (Unit #1 + Unit #2 über StackWise-Backplane) sowie dessen <b>Upstream-Gateway</b> und <b>2 kaskadierte Unterverteiler-Switche</b>.</div>' +
            (SwitchGraphicState.currentDevId ? '<button class="btn btn-sm" onclick="openSwitchGraphicModal(' + SwitchGraphicState.currentDevId + ', false)">↩ Zurück zum ausgewählten Gerät</button>' : '') +
        '</div>';
    } else if (!model.snmpEnabled) {
        var curPortCnt = (model.units[0] && model.units[0].ports) ? model.units[0].ports.length : 28;
        var sizeBtns = [
            { c: 8,  l: '8 Ports' },
            { c: 16, l: '16 Ports' },
            { c: 24, l: '24 Ports' },
            { c: 28, l: '28 Ports (24+4 SFP)' },
            { c: 48, l: '48 Ports' },
            { c: 52, l: '52 Ports (48+4 SFP)' }
        ];
        var btnsHtml = '';
        for (var sb = 0; sb < sizeBtns.length; sb++) {
            var isActSize = (curPortCnt === sizeBtns[sb].c);
            btnsHtml += '<button class="btn btn-sm" style="' + (isActSize ? 'border-color:#f59e0b;background:rgba(245,158,11,0.28);color:#fff;font-weight:700;' : '') + '" onclick="setFallbackSwitchPorts(' + SwitchGraphicState.currentDevId + ', ' + sizeBtns[sb].c + ')">' + sizeBtns[sb].l + '</button>';
        }
        html += '<div style="background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.45);border-radius:8px;padding:12px 14px;font-size:12.5px;color:#fde68a;line-height:1.55;display:flex;flex-direction:column;gap:8px;">' +
            '<div>💡 <b>Warum sieht die Anwendung die genaue Portanzahl &amp; Belegung bei ' + escapeHtml(model.switchIp) + ' noch nicht automatisch?</b><br>' +
            'Ohne aktiviertes <b>SNMP v2c</b> verrät ein Managed Switch über das Netzwerk aus Sicherheitsgründen nur seine Login-Seite, aber <b>nicht</b> seine interne Port-Tabelle (<code>IF-MIB</code>) und MAC-Adresstabelle (<code>Bridge-MIB</code>). ' +
            'Sobald du im Webinterface des Switches (z. B. beim <b>Cisco SG500/SF500 Switch</b> unter <a href="http://' + escapeHtml(model.switchIp) + '" target="_blank" style="color:#60a5fa;text-decoration:underline;">http://' + escapeHtml(model.switchIp) + '</a> &rarr; <b>SNMP &rarr; System &rarr; Enable</b> &amp; <b>Communities: public</b>) SNMP einschaltest und neu scannst, erkennt NetworkMonitor automatisch die exakte Hardware-Portanzahl (z. B. 28 oder 52 Ports) und alle angeschlossenen Geräte!</div>' +
            '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding-top:4px;border-top:1px dashed rgba(245,158,11,0.3);">' +
                '<span style="font-size:11.5px;color:#fcd34d;font-weight:700;">Bauform bis zur SNMP-Aktivierung manuell wählen:</span>' +
                btnsHtml +
            '</div>' +
        '</div>';
    }

    // =========================================================================
    // 1. GRAFISCHES KASKADEN- & UPLINK-SCHALTBILD (Welcher Switch hängt an welchem?)
    // =========================================================================
    html += '<div style="background:rgba(15,23,42,0.85);border:1px solid var(--border-color);border-radius:10px;padding:14px;">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">' +
            '<div style="font-size:12.5px;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:0.04em;">🔗 1. Switch-Kaskadierung, Uplinks &amp; Stack-Topologie (Wer hängt an wem?)</div>' +
            '<div style="font-size:11.5px;color:var(--text-secondary);">Zeigt Upstream-Port (Richtung Router/Core) und Downstream-Trunks (weiterführende Switche/APs)</div>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:14px;align-items:stretch;">';

    // Spalte A: Upstream (Übergeordneter Router / Core-Switch)
    html += '<div style="background:rgba(30,41,59,0.7);border:1px solid rgba(59,130,246,0.35);border-radius:8px;padding:12px;display:flex;flex-direction:column;justify-content:space-between;">' +
        '<div style="font-size:11px;font-weight:700;color:#93c5fd;text-transform:uppercase;margin-bottom:8px;">⬆ Upstream (Übergeordneter Verteiler)</div>';
    if (model.upstreamLinks.length > 0) {
        for (var u = 0; u < model.upstreamLinks.length; u++) {
            var up = model.upstreamLinks[u];
            var clickUp = up.remoteId ? ('onclick="closeSwitchGraphicModal(); selectDevice(' + up.remoteId + ');" style="cursor:pointer;"') : '';
            html += '<div ' + clickUp + ' style="background:rgba(15,23,42,0.9);border:1px solid #3b82f6;border-radius:6px;padding:9px;margin-bottom:6px;">' +
                '<div style="font-weight:700;font-size:13px;color:#f8fafc;">' + escapeHtml(up.remoteName) + '</div>' +
                '<div style="font-family:monospace;font-size:11.5px;color:#93c5fd;">' + escapeHtml(up.remoteIp) + ' • ' + escapeHtml(up.linkType) + '</div>' +
                '<div style="margin-top:6px;padding-top:6px;border-top:1px dashed rgba(148,163,184,0.25);font-family:monospace;font-size:11.5px;color:#34d399;display:flex;justify-content:space-between;">' +
                    '<span>Gegenstelle: <b>' + escapeHtml(up.remotePort) + '</b></span>' +
                    '<span>⇄</span>' +
                    '<span>Hier: <b>' + escapeHtml(up.localPort) + '</b></span>' +
                '</div>' +
            '</div>';
        }
    } else {
        html += '<div style="font-size:12px;color:var(--text-secondary);padding:12px 0;">Kein übergeordneter Switch ermittelt (dieses Gerät bildet die Core-/Root-Ebene).</div>';
    }
    html += '</div>';

    // Spalte B: Aktueller Switch / Switch-Stack in der Mitte
    html += '<div style="background:linear-gradient(135deg, rgba(16,185,129,0.14), rgba(168,85,247,0.14));border:2px solid ' + (isStacked ? '#a855f7' : '#10b981') + ';border-radius:8px;padding:12px;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;">' +
        '<div style="font-size:11px;font-weight:700;color:' + (isStacked ? '#e9d5ff' : '#6ee7b7') + ';text-transform:uppercase;margin-bottom:4px;">' +
            (isStacked ? ('📚 Gestackter Switch-Verbund (' + model.units.length + ' Einheiten)') : '🎛 Aktuell betrachteter Switch') +
        '</div>' +
        '<div style="font-size:15px;font-weight:800;color:#ffffff;">' + escapeHtml(model.switchName) + '</div>' +
        '<div style="font-family:monospace;font-size:12px;color:#93c5fd;margin-top:2px;">IP: ' + escapeHtml(model.switchIp) + '</div>' +
        '<div style="font-size:11.5px;color:var(--text-secondary);margin-top:4px;">' + escapeHtml(model.model) + '</div>';
    if (isStacked) {
        html += '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;justify-content:center;">';
        for (var su = 0; su < model.units.length; su++) {
            html += '<span class="badge" style="background:rgba(168,85,247,0.28);border:1px solid #c084fc;color:#f3e8ff;font-size:11px;">Unit #' + model.units[su].unit + ' (' + escapeHtml(model.units[su].role) + ')</span>';
        }
        html += '</div>';
    }
    html += '</div>';

    // Spalte C: Downstream (Darunter kaskadierte Switche & Access Points)
    html += '<div style="background:rgba(30,41,59,0.7);border:1px solid rgba(16,185,129,0.35);border-radius:8px;padding:12px;display:flex;flex-direction:column;justify-content:space-between;">' +
        '<div style="font-size:11px;font-weight:700;color:#6ee7b7;text-transform:uppercase;margin-bottom:8px;">⬇ Downstream (Kaskadierte Switche &amp; APs: ' + model.downstreamSwitches.length + ')</div>';
    if (model.downstreamSwitches.length > 0) {
        for (var d = 0; d < model.downstreamSwitches.length; d++) {
            var dw = model.downstreamSwitches[d];
            var clickDw = dw.remoteId ? ('onclick="openSwitchGraphicModal(' + dw.remoteId + ', false)" style="cursor:pointer;" title="Klicken, um die Switch-Grafik von ' + escapeHtml(dw.remoteName) + ' zu öffnen"') : '';
            html += '<div ' + clickDw + ' style="background:rgba(15,23,42,0.9);border:1px solid #10b981;border-radius:6px;padding:9px;margin-bottom:6px;">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;">' +
                    '<span style="font-weight:700;font-size:13px;color:#f8fafc;">' + escapeHtml(dw.remoteName) + '</span>' +
                    '<span class="badge badge-snmp" style="font-size:10px;">' + escapeHtml(dw.remoteType.toUpperCase()) + '</span>' +
                '</div>' +
                '<div style="font-family:monospace;font-size:11.5px;color:#93c5fd;">' + escapeHtml(dw.remoteIp) + ' • ' + escapeHtml(dw.linkType) + '</div>' +
                '<div style="margin-top:6px;padding-top:6px;border-top:1px dashed rgba(148,163,184,0.25);font-family:monospace;font-size:11.5px;color:#fcd34d;display:flex;justify-content:space-between;">' +
                    '<span>Hier: <b>' + escapeHtml(dw.localPort) + '</b></span>' +
                    '<span>➔</span>' +
                    '<span>Ziel: <b>' + escapeHtml(dw.remotePort) + '</b></span>' +
                '</div>' +
            '</div>';
        }
    } else {
        html += '<div style="font-size:12px;color:var(--text-secondary);padding:12px 0;">Keine weiteren nachgeschalteten Switche oder Access Points an diesem Verteiler erkannt (reiner Access-Switch).</div>';
    }
    html += '</div>';

    html += '</div></div>';

    // Ermittle Port-Nummerierungs-Layout der physischen 19"-Frontblende:
    // - 'sequential':  Ports 1–12 oben, Ports 13–24 unten (Standard bei Cisco SG200/300/350/500, SF, CBS, Zyxel, MikroTik, D-Link)
    // - 'interleaved': Ungerade Ports 1,3,5..23 oben, Gerade Ports 2,4,6..24 unten (Standard bei Cisco Catalyst IOS, HP/Aruba, Netgear)
    var defaultNumbering = (model.cliProfile === 'cisco_smb' || model.cliProfile === 'zyxel_gs' || model.cliProfile === 'mikrotik_routeros')
        ? 'sequential'
        : 'interleaved';
    var savedNumbering = null;
    try {
        savedNumbering = localStorage.getItem('nm_sw_numbering_' + (devId || 'demo'));
    } catch (e) {}
    var portNumberingMode = (savedNumbering === 'sequential' || savedNumbering === 'interleaved') ? savedNumbering : defaultNumbering;

    // =========================================================================
    // 2. FARB-LEGENDE & 19-ZOLL FRONTBLENDEN PRO STACK-UNIT
    // =========================================================================
    var allModelPorts = [];
    for (var mu = 0; mu < model.units.length; mu++) {
        for (var mp = 0; mp < model.units[mu].ports.length; mp++) {
            allModelPorts.push(model.units[mu].ports[mp]);
        }
    }
    var mappedOutletsCount = 0;
    for (var moc = 0; moc < allModelPorts.length; moc++) {
        if (allModelPorts[moc].outletInfo && allModelPorts[moc].outletInfo.isFromDb) {
            mappedOutletsCount++;
        }
    }

    var bulkOutletsPanelHtml = '';
    if (!model.isDemo && devId) {
        var bulkRowsHtml = '';
        for (var bp = 0; bp < allModelPorts.length; bp++) {
            var bPort = allModelPorts[bp];
            var bOut = (bPort.outletInfo && bPort.outletInfo.isFromDb) ? bPort.outletInfo : { outletLabel: '', patchPanel: '', room: '', notes: '' };
            var curDevStr = '-';
            if (bPort.connectedDevices && bPort.connectedDevices.length > 0) {
                curDevStr = bPort.connectedDevices[0].name + ' (' + bPort.connectedDevices[0].ip + ')';
            } else if (bPort.status === 'up') {
                curDevStr = 'ONLINE (' + (bPort.speed || 'Link Up') + ')';
            }
            var roAttr = userCanEdit() ? '' : 'readonly';
            bulkRowsHtml += '<tr class="sw-bulk-outlet-row" data-port="' + escapeHtml(bPort.name) + '">' +
                '<td style="font-family:monospace;font-weight:700;color:#93c5fd;white-space:nowrap;">#' + escapeHtml(bPort.shortName) + ' <span style="color:var(--text-secondary);font-weight:400;">(' + escapeHtml(bPort.name) + ')</span></td>' +
                '<td style="font-size:11.5px;color:' + (bPort.status === 'up' ? '#34d399' : 'var(--text-secondary)') + ';">' + escapeHtml(curDevStr) + '</td>' +
                '<td><input type="text" class="form-input sw-bulk-ol" style="padding:4px 7px;font-size:12px;font-family:monospace;" value="' + escapeHtml(bOut.outletLabel || '') + '" placeholder="z.B. Dose 1.12 / E1-D04" ' + roAttr + '></td>' +
                '<td><input type="text" class="form-input sw-bulk-pp" style="padding:4px 7px;font-size:12px;font-family:monospace;" value="' + escapeHtml(bOut.patchPanel || '') + '" placeholder="z.B. PF1-' + escapeHtml(bPort.shortName) + '" ' + roAttr + '></td>' +
                '<td><input type="text" class="form-input sw-bulk-rm" style="padding:4px 7px;font-size:12px;" value="' + escapeHtml(bOut.room || '') + '" placeholder="z.B. Büro EG / Raum 104" ' + roAttr + '></td>' +
                '<td><input type="text" class="form-input sw-bulk-nt" style="padding:4px 7px;font-size:12px;" value="' + escapeHtml(bOut.notes || '') + '" placeholder="Optionaler Hinweis" ' + roAttr + '></td>' +
            '</tr>';
        }

        bulkOutletsPanelHtml =
            '<div id="sw-outlets-bulk-panel" style="display:none;background:rgba(15,23,42,0.92);border:1px solid rgba(56,189,248,0.45);border-radius:10px;padding:14px;margin-bottom:10px;">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px;">' +
                    '<div>' +
                        '<div style="font-size:13px;font-weight:800;color:#7dd3fc;text-transform:uppercase;">🔌 Patchfeld- &amp; Netzwerkdosen-Zuordnung für ' + escapeHtml(model.switchName) + '</div>' +
                        '<div style="font-size:11.5px;color:var(--text-secondary);margin-top:2px;">Ordne den Switch-Ports die physischen <b>Dosen-Bezeichnungen</b>, <b>Patchfeld-Ports</b> und <b>Räume</b> zu. Danach kannst du überall nach der Dosen-Nr. suchen!</div>' +
                    '</div>' +
                    '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
                        '<button type="button" class="btn btn-sm" onclick="exportSwitchOutletsCsv(' + devId + ')">📥 CSV-Patchliste exportieren</button>' +
                        (userCanEdit() ? '<button type="button" class="btn btn-sm" onclick="importSwitchOutletsCsvPrompt(' + devId + ')">📤 CSV importieren</button>' : '') +
                        (userCanEdit() ? '<button type="button" class="btn btn-sm btn-primary" onclick="saveSwitchOutletsBulk(' + devId + ')">💾 Alle Dosen-Zuordnungen speichern</button>' : '') +
                    '</div>' +
                '</div>' +
                '<div style="max-height:320px;overflow-y:auto;border:1px solid var(--border-color);border-radius:8px;">' +
                    '<table class="data-table" style="margin:0;">' +
                        '<thead><tr><th>Switch-Port</th><th>Aktuell gesteckt / Status</th><th>Dosen-Bezeichnung *</th><th>Patchfeld / Port</th><th>Raum / Abteilung</th><th>Notiz</th></tr></thead>' +
                        '<tbody>' + bulkRowsHtml + '</tbody>' +
                    '</table>' +
                '</div>' +
            '</div>';
    }

    html += '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;padding:4px 2px;">' +
        '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">' +
            '<div style="font-size:12.5px;font-weight:700;color:#f8fafc;text-transform:uppercase;">🎛 2. Physische 19-Zoll Switch-Frontblende</div>' +
            '<div style="display:inline-flex;background:#0f172a;border:1px solid var(--border-color);border-radius:6px;padding:2px;gap:2px;" title="Physische Port-Anordnung der 19-Zoll-Frontblende umschalten">' +
                '<button type="button" class="btn btn-sm" style="padding:2px 8px;font-size:11px;border:none;' + (portNumberingMode === 'sequential' ? 'background:#3b82f6;color:#fff;font-weight:700;' : 'background:transparent;color:var(--text-secondary);') + '" onclick="setSwitchPortNumberingMode(' + (devId || 0) + ', \'sequential\')">🔢 1–12 oben / 13–24 unten</button>' +
                '<button type="button" class="btn btn-sm" style="padding:2px 8px;font-size:11px;border:none;' + (portNumberingMode === 'interleaved' ? 'background:#3b82f6;color:#fff;font-weight:700;' : 'background:transparent;color:var(--text-secondary);') + '" onclick="setSwitchPortNumberingMode(' + (devId || 0) + ', \'interleaved\')">🔢 1,3,5 oben / 2,4,6 unten</button>' +
            '</div>' +
            (!model.isDemo && devId ? '<button type="button" class="btn btn-sm" style="border-color:#38bdf8;background:rgba(14,165,233,0.16);color:#7dd3fc;font-weight:700;" onclick="toggleSwitchOutletsBulkPanel()">🔌 Patchfeld- &amp; Dosen-Liste (' + mappedOutletsCount + ' zugeordnet) ▾</button>' : '') +
        '</div>' +
        '<div style="display:flex;gap:12px;flex-wrap:wrap;font-size:11.5px;color:var(--text-secondary);">' +
            '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#10b981;margin-right:4px;"></span>1G / 10G Aktiv</span>' +
            '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#f59e0b;margin-right:4px;"></span>100M / 10M (FastEth/PoE)</span>' +
            '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#3b82f6;margin-right:4px;"></span>Uplink / Kaskaden-Trunk</span>' +
            '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#a855f7;margin-right:4px;"></span>Stack-Backplane</span>' +
            '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#334155;margin-right:4px;"></span>Port Frei (Down)</span>' +
        '</div>' +
    '</div>' +
    bulkOutletsPanelHtml;

    for (var uIdx = 0; uIdx < model.units.length; uIdx++) {
        var unitObj = model.units[uIdx];
        var copperPorts = [];
        var sfpPorts = [];

        for (var pi = 0; pi < unitObj.ports.length; pi++) {
            var prt = unitObj.ports[pi];
            SwitchGraphicState.portsByKey[prt.key] = prt;
            if (keepSelectedPortName && prt.name === keepSelectedPortName) {
                SwitchGraphicState.selectedPortKey = prt.key;
            } else if (!SwitchGraphicState.selectedPortKey && prt.status === 'up') {
                SwitchGraphicState.selectedPortKey = prt.key;
            }
            if (prt.isSfp) {
                sfpPorts.push(prt);
            } else {
                copperPorts.push(prt);
            }
        }
        if (!SwitchGraphicState.selectedPortKey && unitObj.ports.length > 0) {
            SwitchGraphicState.selectedPortKey = unitObj.ports[0].key;
        }

        // Teile RJ45-Ports in 24er-Blöcke (je max. 12 Ports oben + 12 Ports unten),
        // wahlweise 'sequential' (1–12 oben / 13–24 unten, z.B. Cisco SG500) oder 'interleaved' (1,3,5 oben / 2,4,6 unten, z.B. Catalyst)
        var copperBlocks = [];
        for (var bStart = 0; bStart < copperPorts.length; bStart += 24) {
            var slice = copperPorts.slice(bStart, bStart + 24);
            var tRow = [];
            var bRow = [];
            if (portNumberingMode === 'sequential') {
                var half = Math.ceil(slice.length / 2);
                tRow = slice.slice(0, half);
                bRow = slice.slice(half);
            } else {
                for (var cp = 0; cp < slice.length; cp++) {
                    if (cp % 2 === 0) {
                        tRow.push(slice[cp]);
                    } else {
                        bRow.push(slice[cp]);
                    }
                }
            }
            copperBlocks.push({ top: tRow, bottom: bRow });
        }

        var activeCnt = 0;
        for (var ac = 0; ac < unitObj.ports.length; ac++) {
            if (unitObj.ports[ac].status === 'up') activeCnt++;
        }

        // Falls mehrere Stack-Units vorhanden sind, zeichne zwischen den Gehäusen das Stack-Verbindungskabel
        if (uIdx > 0) {
            html += '<div style="display:flex;align-items:center;justify-content:center;gap:12px;margin:4px 0;">' +
                '<div style="height:22px;width:4px;background:linear-gradient(180deg, #a855f7, #ec4899);border-radius:2px;"></div>' +
                '<div style="background:rgba(168,85,247,0.2);border:1px solid #a855f7;color:#e9d5ff;font-size:11px;font-weight:700;padding:2px 12px;border-radius:999px;font-family:monospace;">' +
                    '⚡ High-Speed Stack-Backplane Ring (Unit #' + model.units[uIdx - 1].unit + ' ⇄ Unit #' + unitObj.unit + ')' +
                '</div>' +
                '<div style="height:22px;width:4px;background:linear-gradient(180deg, #a855f7, #ec4899);border-radius:2px;"></div>' +
            '</div>';
        }

        // 19-Zoll Metallgehäuse mit Rack-Ohren links & rechts (ohne overflow:hidden/auto, damit niemals Scrollbalken die Ports verdecken)
        html += '<div style="display:flex;align-items:stretch;gap:0;background:#090d16;border:2px solid ' + (isStacked ? '#7e22ce' : '#475569') + ';border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,0.45);margin-bottom:14px;">' +
            // Linker 19" Rack-Haltewinkel
            '<div style="width:22px;flex-shrink:0;background:linear-gradient(90deg, #1e293b, #334155);border-right:2px solid #0f172a;border-radius:8px 0 0 8px;display:flex;flex-direction:column;justify-content:space-between;align-items:center;padding:10px 0;">' +
                '<span style="width:8px;height:12px;border-radius:4px;background:#090d16;border:1px solid #64748b;"></span>' +
                '<span style="writing-mode:vertical-rl;font-size:9px;font-family:monospace;color:#94a3b8;letter-spacing:1px;">19&quot; RACK</span>' +
                '<span style="width:8px;height:12px;border-radius:4px;background:#090d16;border:1px solid #64748b;"></span>' +
            '</div>' +
            // Haupt-Frontblende
            '<div style="flex:1;min-width:0;padding:12px 14px;display:flex;flex-direction:column;gap:10px;">' +
                // Chassis-Kopfzeile (Modell, Unit-Badge, System-LEDs)
                '<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(148,163,184,0.2);padding-bottom:8px;flex-wrap:wrap;gap:8px;">' +
                    '<div style="display:flex;align-items:center;gap:10px;">' +
                        '<span style="background:' + (uIdx === 0 ? '#10b981' : '#a855f7') + ';color:#0f172a;font-weight:800;font-family:monospace;font-size:12px;padding:3px 9px;border-radius:5px;">UNIT #' + unitObj.unit + '</span>' +
                        '<div>' +
                            '<div style="font-weight:700;font-size:13.5px;color:#f8fafc;">' + escapeHtml(unitObj.model) + ' <span style="color:#93c5fd;font-size:12px;">(' + escapeHtml(unitObj.role) + ')</span></div>' +
                            '<div style="font-size:11px;color:var(--text-secondary);font-family:monospace;">SN: ' + escapeHtml(unitObj.serial) + ' • ' + activeCnt + ' von ' + unitObj.ports.length + ' Ports aktiv</div>' +
                        '</div>' +
                    '</div>' +
                    '<div style="display:flex;align-items:center;gap:12px;font-family:monospace;font-size:11px;color:#94a3b8;">' +
                        '<span><span class="port-led up" style="display:inline-block;margin-right:4px;"></span>PWR</span>' +
                        '<span><span class="port-led up" style="display:inline-block;margin-right:4px;"></span>SYST</span>' +
                        (isStacked ? '<span><span class="port-led up" style="display:inline-block;background:#a855f7;box-shadow:0 0 6px #a855f7;margin-right:4px;"></span>MSTR/STACK</span>' : '') +
                    '</div>' +
                '</div>' +
                // Port-Matrix (Links 2-reihige RJ45-Felder à max. 24 Ports, Rechts abgetrenntes SFP+/Uplink-Modul)
                '<div style="display:flex;align-items:stretch;gap:14px;flex-wrap:wrap;">' +
                    '<div style="flex:1;min-width:280px;display:flex;flex-direction:column;gap:10px;">';

        for (var cb = 0; cb < copperBlocks.length; cb++) {
            var colsCount = Math.max(4, copperBlocks[cb].top.length);
            html += '<div style="display:flex;flex-direction:column;gap:6px;background:#111827;border:1px solid #374151;border-radius:8px;padding:10px;">' +
                '<div style="display:grid;grid-template-columns:repeat(' + colsCount + ', minmax(0, 1fr));gap:5px;">' + renderSwitchPortRowHtml(copperBlocks[cb].top, 'top') + '</div>' +
                '<div style="display:grid;grid-template-columns:repeat(' + colsCount + ', minmax(0, 1fr));gap:5px;">' + renderSwitchPortRowHtml(copperBlocks[cb].bottom, 'bottom') + '</div>' +
            '</div>';
        }

        html += '</div>';

        if (sfpPorts.length > 0) {
            html += '<div style="display:flex;flex-direction:column;justify-content:space-between;background:#172554;border:1px solid #3b82f6;border-radius:8px;padding:10px;min-width:160px;">' +
                '<div style="font-size:10px;font-weight:700;color:#93c5fd;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px;text-align:center;">SFP+ / Uplink / Stack</div>' +
                '<div style="display:grid;grid-template-columns:repeat(' + Math.min(4, sfpPorts.length) + ', minmax(52px, 1fr));gap:6px;align-items:center;flex:1;">' + renderSwitchPortRowHtml(sfpPorts, 'sfp') + '</div>' +
            '</div>';
        }

        html +=     '</div>' +
            '</div>' +
            // Rechter 19" Rack-Haltewinkel
            '<div style="width:22px;flex-shrink:0;background:linear-gradient(90deg, #334155, #1e293b);border-left:2px solid #0f172a;border-radius:0 8px 8px 0;display:flex;flex-direction:column;justify-content:space-between;align-items:center;padding:10px 0;">' +
                '<span style="width:8px;height:12px;border-radius:4px;background:#090d16;border:1px solid #64748b;"></span>' +
                '<span style="writing-mode:vertical-rl;font-size:9px;font-family:monospace;color:#94a3b8;letter-spacing:1px;">19&quot; RACK</span>' +
                '<span style="width:8px;height:12px;border-radius:4px;background:#090d16;border:1px solid #64748b;"></span>' +
            '</div>' +
        '</div>';
    }

    // =========================================================================
    // 3. INTERAKTIVE PORT-INSPEKTOR-BOX (Detailansicht & SSH-Steuerung bei Klick auf einen Port)
    // =========================================================================
    html += '<div id="switch-graphic-port-inspector" style="background:rgba(15,23,42,0.9);border:1px solid var(--border-color);border-radius:10px;padding:14px;"></div>';

    bodyEl.innerHTML = html;
    modal.classList.add('open');

    if (SwitchGraphicState.selectedPortKey) {
        selectSwitchGraphicPort(SwitchGraphicState.selectedPortKey);
    }
}

/**
 * Schaltet das Layout der Switch-Port-Nummerierung zwischen Standard (Ungerade oben / Gerade unten) und Linear um.
 */
function setSwitchPortNumberingMode(devId, mode) {
    try {
        localStorage.setItem('nm_sw_numbering_' + (devId || 'demo'), mode);
    } catch (e) {}
    var curPortName = null;
    if (SwitchGraphicState.selectedPortKey && SwitchGraphicState.portsByKey[SwitchGraphicState.selectedPortKey]) {
        curPortName = SwitchGraphicState.portsByKey[SwitchGraphicState.selectedPortKey].name;
    }
    openSwitchGraphicModal(devId, SwitchGraphicState.isDemo, curPortName);
}

/**
 * Rendert eine Reihe physischer RJ45-/SFP-Buchsen für die grafische Switch-Frontplatte.
 */
function renderSwitchPortRowHtml(portList, rowPosition) {
    var out = '';
    for (var i = 0; i < portList.length; i++) {
        var p = portList[i];
        var isUp = (p.status === 'up');
        var isAdmDown = (p.admin_status === 'down');
        var pVlan = p.vlan ? parseInt(p.vlan, 10) : 1;
        var spLower = String(p.speed || '').toLowerCase();
        var isFastEth = (isUp && (spLower.indexOf('100 ') !== -1 || spLower.indexOf('10 ') === 0));

        var borderCol = '#334155';
        var bgCol = '#1e293b';
        var ledCol = '#475569';
        var badgeCol = '#94a3b8';

        if (isAdmDown) {
            borderCol = '#ef4444';
            bgCol = 'rgba(239, 68, 68, 0.14)';
            ledCol = '#ef4444';
            badgeCol = '#fca5a5';
        } else if (p.isStackPort) {
            borderCol = '#a855f7';
            bgCol = 'rgba(168, 85, 247, 0.22)';
            ledCol = '#c084fc';
            badgeCol = '#e9d5ff';
        } else if (p.isUplink) {
            borderCol = '#3b82f6';
            bgCol = 'rgba(59, 130, 246, 0.24)';
            ledCol = '#60a5fa';
            badgeCol = '#bfdbfe';
        } else if (isUp && isFastEth) {
            borderCol = '#f59e0b';
            bgCol = 'rgba(245, 158, 11, 0.18)';
            ledCol = '#fbbf24';
            badgeCol = '#fde68a';
        } else if (isUp) {
            borderCol = '#10b981';
            bgCol = 'rgba(16, 185, 129, 0.18)';
            ledCol = '#34d399';
            badgeCol = '#a7f3d0';
        }

        var devLabel = isAdmDown ? 'OFF' : 'Frei';
        if (p.isStackPort) {
            devLabel = 'STACK';
        } else if (p.connectedDevices && p.connectedDevices.length > 0) {
            devLabel = p.connectedDevices[0].name;
            if (p.connectedDevices.length > 1) {
                devLabel = '+' + p.connectedDevices.length + ' MACs';
            }
        } else if (isUp) {
            devLabel = p.alias || 'Aktiv';
        } else if (p.rawAlias) {
            devLabel = p.rawAlias;
        }
        if (devLabel.length > 9) {
            devLabel = devLabel.substring(0, 8) + '…';
        }

        var vlanPillStyle = (pVlan !== 1)
            ? 'background:rgba(245,158,11,0.3);color:#fde68a;border:1px solid #f59e0b;'
            : 'background:rgba(15,23,42,0.75);color:#94a3b8;border:1px solid rgba(148,163,184,0.25);';

        var errDot = (p.in_errors && p.in_errors > 0)
            ? '<span title="' + p.in_errors + ' Paketfehler auf diesem Port!" style="width:7px;height:7px;border-radius:50%;background:#ef4444;box-shadow:0 0 6px #ef4444;display:inline-block;"></span>'
            : '<span style="font-family:monospace;font-size:8.5px;font-weight:700;padding:0 3px;border-radius:3px;' + vlanPillStyle + '">V' + pVlan + '</span>';

        var outletShort = (p.outletInfo && p.outletInfo.badgeText) ? p.outletInfo.badgeText : '';
        if (outletShort.length > 9) {
            outletShort = outletShort.substring(0, 8) + '…';
        }
        var outletTileHtml = outletShort
            ? '<div style="width:100%;text-align:center;font-size:8.5px;font-weight:700;color:#7dd3fc;background:rgba(14,165,233,0.18);border-radius:3px;padding:0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;" title="Dose / Patchfeld: ' + escapeHtml(p.outletInfo.fullText) + '">🔌 ' + escapeHtml(outletShort) + '</div>'
            : '';

        var portTooltip = 'Port ' + p.name + ' | VLAN ' + pVlan + ' | ' + p.speed +
            (p.outletInfo && p.outletInfo.fullText ? (' | Dose: ' + p.outletInfo.fullText) : '') +
            ' – ' + (p.alias || 'Klicken zum Konfigurieren');

        out += '<div id="swport_' + p.key + '" class="sw-front-port" onclick="selectSwitchGraphicPort(\'' + p.key + '\')" ' +
            'title="' + escapeHtml(portTooltip) + '" ' +
            'style="min-width:0;background:' + bgCol + ';border:2px solid ' + borderCol + ';border-radius:6px;padding:5px 3px;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:space-between;transition:transform 0.12s, box-shadow 0.12s;user-select:none;">' +
                // Kopfzeile im Port: LED + Portnummer + VLAN-Tag
                '<div style="width:100%;display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;gap:2px;">' +
                    '<span style="width:7px;height:7px;border-radius:50%;flex-shrink:0;background:' + ledCol + ';' + (isUp ? ('box-shadow:0 0 6px ' + ledCol + ';') : '') + '"></span>' +
                    '<span style="font-family:monospace;font-size:10px;font-weight:800;color:#f8fafc;">#' + escapeHtml(p.shortName) + '</span>' +
                    errDot +
                '</div>' +
                // Stilisierte RJ45 / SFP Buchsen-Kontur
                '<div style="width:36px;height:18px;background:#090d16;border:1px solid ' + borderCol + ';border-radius:2px;display:flex;flex-direction:column;align-items:center;justify-content:center;margin:2px 0;">' +
                    (p.isSfp
                        ? '<div style="width:24px;height:6px;border:1px solid ' + badgeCol + ';border-radius:1px;"></div>'
                        : '<div style="width:18px;height:4px;background:' + (isUp ? ledCol : '#334155') + ';margin-bottom:2px;border-radius:1px;"></div><div style="width:10px;height:4px;background:#475569;"></div>') +
                '</div>' +
                // Angeschlossenes Gerät / Kurzlabel direkt auf der Buchse
                '<div style="width:100%;text-align:center;font-size:9.5px;font-weight:600;color:' + badgeCol + ';white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;">' +
                    escapeHtml(devLabel) +
                '</div>' +
                outletTileHtml +
            '</div>';
    }
    return out;
}

/**
 * Zeigt beim Klick auf eine Switch-Buchse alle Port-Details, angeschlossene Geräte, Netzwerkdose und das Konfigurationsformular.
 */
function selectSwitchGraphicPort(portKey) {
    var p = SwitchGraphicState.portsByKey[portKey];
    var box = document.getElementById('switch-graphic-port-inspector');
    if (!p || !box) return;

    SwitchGraphicState.selectedPortKey = portKey;
    var model = SwitchGraphicState.currentModel || {};

    // Optische Hervorhebung des angeklickten Ports in der 19"-Blende
    var allTiles = document.querySelectorAll('#switch-graphic-body .sw-front-port');
    for (var i = 0; i < allTiles.length; i++) {
        allTiles[i].style.boxShadow = 'none';
        allTiles[i].style.transform = 'none';
    }
    var activeTile = document.getElementById('swport_' + portKey);
    if (activeTile) {
        activeTile.style.boxShadow = '0 0 0 3px #f8fafc, 0 0 14px #3b82f6';
        activeTile.style.transform = 'translateY(-2px)';
    }

    var stBadge = (p.status === 'up')
        ? '<span class="badge badge-online">ONLINE / LINK UP (' + escapeHtml(p.speed) + ')</span>'
        : '<span class="badge badge-offline">FREI / LINK DOWN</span>';
    if (p.admin_status === 'down') {
        stBadge = '<span class="badge badge-offline">🔴 ADMINISTRATIV ABGESCHALTET (SHUTDOWN)</span>';
    }

    var curVlan = p.vlan ? parseInt(p.vlan, 10) : 1;
    var vlanMap = model.vlans || { '1': 'default' };
    var curVlanName = vlanMap[curVlan] || ('VLAN ' + curVlan);
    var vlanBadge = '<span class="badge" style="font-family:monospace;font-size:12px;background:' + (curVlan !== 1 ? 'rgba(245,158,11,0.25);border:1px solid #f59e0b;color:#fde68a;' : 'rgba(59,130,246,0.2);border:1px solid #3b82f6;color:#93c5fd;') + '">🏷 VLAN ' + curVlan + ' (' + escapeHtml(String(curVlanName)) + ')</span>';

    var roleBadge = '<span class="badge" style="background:rgba(148,163,184,0.18);color:#cbd5e1;">Access-Port</span>';
    if (p.isStackPort) {
        roleBadge = '<span class="badge" style="background:rgba(168,85,247,0.25);color:#e9d5ff;">📚 Stack-Interconnect (' + escapeHtml(p.remotePort || 'Backplane') + ')</span>';
    } else if (p.isUplink) {
        roleBadge = '<span class="badge" style="background:rgba(59,130,246,0.25);color:#93c5fd;">🔗 ' + (p.uplinkRole === 'upstream' ? 'Upstream-Uplink zum Gateway/Core' : 'Downstream-Trunk zu weiterem Switch/AP') + '</span>';
    }

    var devsHtml = '';
    var currentPortMacHexMap = {};
    if (p.connectedDevices && p.connectedDevices.length > 0) {
        devsHtml = '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:10px;margin-top:8px;">';
        for (var d = 0; d < p.connectedDevices.length; d++) {
            var cd = p.connectedDevices[d];
            if (cd.mac) {
                currentPortMacHexMap[normalizeMacHex(cd.mac)] = true;
            }
            var jumpAttr = cd.id ? ('onclick="closeSwitchGraphicModal(); selectDevice(' + cd.id + ');" style="cursor:pointer;"') : '';
            devsHtml += '<div ' + jumpAttr + ' style="background:var(--bg-panel);border:1px solid #3b82f6;border-radius:8px;padding:10px;display:flex;justify-content:space-between;align-items:center;">' +
                '<div>' +
                    '<div style="font-weight:700;font-size:13px;color:#f8fafc;">' + escapeHtml(cd.name) + '</div>' +
                    '<div style="font-family:monospace;font-size:11.5px;color:#93c5fd;margin-top:2px;">IP: ' + escapeHtml(cd.ip) + ' • MAC: ' + escapeHtml(cd.mac) + '</div>' +
                    (p.remotePort ? ('<div style="font-family:monospace;font-size:11px;color:#34d399;margin-top:2px;">Gegenstellen-Port: <b>' + escapeHtml(p.remotePort) + '</b></div>') : '') +
                '</div>' +
                '<span class="badge badge-snmp">' + escapeHtml((cd.type || 'client').toUpperCase()) + '</span>' +
            '</div>';
        }
        devsHtml += '</div>';
    } else {
        devsHtml = '<div style="font-size:12.5px;color:var(--text-secondary);margin-top:6px;">An diesem Port wurde aktuell kein aktives Endgerät in der MAC-/Topologie-Tabelle gemeldet.</div>';
    }

    // Historische MAC-Belegung an diesem spezifischen Switch-Port (mac_port_history)
    var portHistoryHtml = '';
    if (!SwitchGraphicState.isDemo && SwitchGraphicState.currentDevId) {
        var allMacHist = AppState.macHistory || [];
        var swPortHist = [];
        var pNameLow = String(p.name || '').toLowerCase();
        var pShortLow = String(p.shortName || '').toLowerCase();
        for (var ph = 0; ph < allMacHist.length; ph++) {
            var mhItem = allMacHist[ph];
            if (parseInt(mhItem.switch_id, 10) === parseInt(SwitchGraphicState.currentDevId, 10)) {
                var mhPortLow = String(mhItem.port_name || '').toLowerCase();
                if (mhPortLow === pNameLow || mhPortLow === pShortLow || mhPortLow === ('port ' + pShortLow)) {
                    swPortHist.push(mhItem);
                }
            }
        }
        if (swPortHist.length > 0) {
            var phRows = '';
            for (var pi = 0; pi < swPortHist.length; pi++) {
                var phe = swPortHist[pi];
                var pheHex = normalizeMacHex(phe.mac_address);
                var isNowPlugged = !!currentPortMacHexMap[pheHex];
                var pheTime = (phe.first_seen || '-').substring(0, 16);
                if (phe.last_seen && phe.last_seen !== phe.first_seen) {
                    pheTime += ' – ' + phe.last_seen.substring(0, 16);
                }
                phRows +=
                    '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;padding:6px 10px;border-radius:6px;background:' + (isNowPlugged ? 'rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.4)' : 'rgba(15,23,42,0.55);border:1px solid var(--border-color)') + ';font-size:12px;">' +
                        '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">' +
                            '<span class="badge" style="background:' + (isNowPlugged ? 'rgba(16,185,129,0.22);color:#34d399;border:1px solid #10b981;' : 'rgba(148,163,184,0.18);color:#cbd5e1;') + 'font-size:10.5px;">' + (isNowPlugged ? '🟢 Aktuell gesteckt' : '🕓 Historisch') + '</span>' +
                            renderCopyableValue(phe.mac_address, 'MAC-Adresse', 'font-family:monospace;font-weight:700;color:#fcd34d;') +
                            '<span style="font-weight:700;color:#f8fafc;">' + escapeHtml(phe.device_name || phe.ip_address || 'Unbekannter Host') + '</span>' +
                            (phe.ip_address ? '<span style="font-family:monospace;color:#93c5fd;">(' + escapeHtml(phe.ip_address) + ')</span>' : '') +
                            (phe.vendor ? '<span style="color:var(--text-secondary);font-size:11px;">• ' + escapeHtml(phe.vendor) + '</span>' : '') +
                            (parseInt(phe.vlan_id, 10) > 0 ? '<span class="badge" style="font-family:monospace;font-size:10.5px;background:rgba(245,158,11,0.18);color:#fcd34d;">VLAN ' + parseInt(phe.vlan_id, 10) + '</span>' : '') +
                        '</div>' +
                        '<div style="font-family:monospace;font-size:11px;color:var(--text-secondary);">' +
                            'Zeitraum: <b style="color:#e2e8f0;">' + escapeHtml(pheTime) + '</b> (' + (parseInt(phe.sightings_count, 10) || 1) + '× gesehen)' +
                        '</div>' +
                    '</div>';
            }
            portHistoryHtml =
                '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed var(--border-color);">' +
                    '<div style="font-size:11.5px;font-weight:700;color:#fcd34d;text-transform:uppercase;margin-bottom:6px;">🕓 Historische MAC-Belegung an Buchse #' + escapeHtml(p.shortName) + ' (Port ' + escapeHtml(p.name) + ' • ' + swPortHist.length + (swPortHist.length === 1 ? ' MAC-Eintrag' : ' MAC-Einträge') + '):</div>' +
                    '<div style="display:flex;flex-direction:column;gap:5px;">' + phRows + '</div>' +
                '</div>';
        }
    }

    // Interaktives SSH Port- & VLAN-Konfigurationspanel für Operatoren & Admins
    var sshConfigHtml = '';
    if (!SwitchGraphicState.isDemo && userCanEdit()) {
        var vKeys = Object.keys(vlanMap).map(function (k) { return parseInt(k, 10); }).sort(function (a, b) { return a - b; });
        if (vKeys.indexOf(curVlan) === -1) {
            vKeys.push(curVlan);
            vKeys.sort(function (a, b) { return a - b; });
        }
        var vlanOpts = '';
        for (var vk = 0; vk < vKeys.length; vk++) {
            var vid = vKeys[vk];
            var vlbl = vlanMap[vid] ? ('VLAN ' + vid + ' – ' + vlanMap[vid]) : ('VLAN ' + vid);
            var vsel = (vid === curVlan) ? 'selected' : '';
            vlanOpts += '<option value="' + vid + '" ' + vsel + '>' + escapeHtml(vlbl) + '</option>';
        }
        if (userIsNetExpert()) {
            vlanOpts += '<option value="__new__">➕ Neues VLAN auf Switch anlegen &amp; zuweisen...</option>';
        }

        var uplinkWarnHtml = p.isUplink
            ? '<div style="background:rgba(239,68,68,0.16);border:1px solid #ef4444;border-radius:6px;padding:8px 12px;margin-bottom:10px;font-size:12px;color:#fecaca;">' +
                '⚠️ <b>Achtung – Uplink-Schutz aktiv:</b> Über Port <b>' + escapeHtml(p.name) + '</b> ist dieser Switch mit dem übergeordneten Gateway/Netzwerk verbunden! Eine Änderung des Access-VLANs oder das Abschalten dieses Ports kann die Verbindung zum Switch trennen.' +
              '</div>'
            : '';

        var isTrunkMode = (p.port_mode === 'trunk' || p.isUplink);
        if (!userIsNetExpert()) {
            isTrunkMode = false;
        }

        var modeSelectHtml = userIsNetExpert()
            ? '<select id="sw-port-mode-select" class="select-input" onchange="onSwitchPortModeChange()">' +
                  '<option value="access" ' + (!isTrunkMode ? 'selected' : '') + '>🔌 Access-Port (1 Endgeräte-VLAN)</option>' +
                  '<option value="trunk" ' + (isTrunkMode ? 'selected' : '') + '>🔗 802.1Q Trunk-Port (Uplink / AP / Core)</option>' +
              '</select>'
            : '<select id="sw-port-mode-select" class="select-input" disabled title="802.1Q Trunk-Konfiguration ist Netzwerkexperten (netadmin) vorbehalten">' +
                  '<option value="access" selected>🔌 Access-Port (Operator-Modus)</option>' +
              '</select>';

        sshConfigHtml =
            '<div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(59,130,246,0.35);">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">' +
                    '<div style="font-size:12.5px;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:0.04em;">⚙️ Live Port- &amp; VLAN-Konfiguration per SSH / SNMP (Port ' + escapeHtml(p.name) + ')</div>' +
                    '<span style="font-size:11.5px;color:var(--text-secondary);">Profil: <b style="color:#e9d5ff;">' + escapeHtml(model.cliProfileLabel || 'Cisco CLI') + '</b> auf <b>' + escapeHtml(model.switchIp || '') + '</b></span>' +
                '</div>' +
                uplinkWarnHtml +
                '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(195px, 1fr));gap:10px;align-items:end;">' +
                    '<div class="form-group" style="margin:0;">' +
                        '<label>Switchport-Modus</label>' +
                        modeSelectHtml +
                    '</div>' +
                    '<div class="form-group" style="margin:0;">' +
                        '<label id="sw-port-vlan-label">' + (isTrunkMode ? 'Native VLAN (Ungetaggt auf Trunk)' : ('Access-VLAN (PVID) auf ' + escapeHtml(p.name))) + '</label>' +
                        '<select id="sw-port-vlan-select" class="select-input" onchange="onSwitchVlanSelectChange()">' + vlanOpts + '</select>' +
                    '</div>' +
                    '<div id="sw-trunk-allowed-wrap" class="form-group" style="margin:0;display:' + (isTrunkMode ? 'block' : 'none') + ';">' +
                        '<label style="color:#93c5fd;">Erlaubte Trunk-VLANs (Tagged)</label>' +
                        '<input type="text" id="sw-trunk-allowed-input" class="form-input" value="all" placeholder="all oder z.B. 1,10,20,99">' +
                    '</div>' +
                    '<div id="sw-new-vlan-id-wrap" class="form-group" style="margin:0;display:none;">' +
                        '<label style="color:#fcd34d;">Neue VLAN-ID (2–4094)</label>' +
                        '<input type="number" id="sw-new-vlan-id" class="form-input" min="2" max="4094" placeholder="z.B. 20">' +
                    '</div>' +
                    '<div id="sw-new-vlan-name-wrap" class="form-group" style="margin:0;display:none;">' +
                        '<label style="color:#fcd34d;">Neuer VLAN-Name</label>' +
                        '<input type="text" id="sw-new-vlan-name" class="form-input" placeholder="z.B. Gaeste, IoT, VoIP">' +
                    '</div>' +
                    '<div class="form-group" style="margin:0;">' +
                        '<label>Port-Beschreibung (Switch Alias)</label>' +
                        '<input type="text" id="sw-port-alias-input" class="form-input" value="' + escapeHtml(p.rawAlias || '') + '" placeholder="z.B. Buero-EG, Drucker, AP">' +
                    '</div>' +
                    '<div class="form-group" style="margin:0;">' +
                        '<label>Port Admin-Status (PoE / Link)</label>' +
                        '<select id="sw-port-admin-status" class="select-input">' +
                            '<option value="up" ' + (p.admin_status !== 'down' ? 'selected' : '') + '>🟢 Aktiv (no shutdown)</option>' +
                            '<option value="down" ' + (p.admin_status === 'down' ? 'selected' : '') + '>🔴 Deaktiviert (shutdown)</option>' +
                        '</select>' +
                    '</div>' +
                '</div>' +
                '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;flex-wrap:wrap;gap:10px;">' +
                    '<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#cbd5e1;cursor:pointer;">' +
                        '<input type="checkbox" id="sw-port-save-flash" checked> ' +
                        '<span>Dauerhaft im Switch-Flash sichern (<code>write memory</code> / <code>copy run start</code>)</span>' +
                    '</label>' +
                    '<button id="btn-apply-sw-port" class="btn btn-primary" onclick="applySwitchPortConfig(\'' + p.key + '\')">⚡ Jetzt auf Switch anwenden</button>' +
                '</div>' +
                '<div id="sw-port-config-status" style="margin-top:10px;font-size:12px;display:none;"></div>' +
            '</div>';
    }

    var curOut = (p.outletInfo && p.outletInfo.isFromDb) ? p.outletInfo : { outletLabel: '', patchPanel: '', room: '', notes: '' };
    var outletHeaderBadge = (p.outletInfo && p.outletInfo.fullText)
        ? '<span class="badge" style="background:rgba(14,165,233,0.22);border:1px solid #38bdf8;color:#7dd3fc;font-size:12px;">🔌 Dose: <b>' + escapeHtml(p.outletInfo.fullText) + '</b></span>'
        : '';

    var outletEditorHtml = '';
    if (!SwitchGraphicState.isDemo && SwitchGraphicState.currentDevId) {
        if (userCanEdit()) {
            outletEditorHtml =
                '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed rgba(56,189,248,0.35);">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:6px;">' +
                        '<div style="font-size:12px;font-weight:700;color:#7dd3fc;text-transform:uppercase;">🔌 Netzwerkdose, Patchfeld &amp; Raum für Buchse #' + escapeHtml(p.shortName) + ' (Port ' + escapeHtml(p.name) + ') hinterlegen</div>' +
                        '<span style="font-size:11px;color:var(--text-secondary);">Wird lokal in der Datenbank gespeichert &amp; ist sofort im Dosen-/Port-Finder suchbar (auch ohne SSH!)</span>' +
                    '</div>' +
                    '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)) auto;gap:8px;align-items:end;">' +
                        '<div class="form-group" style="margin:0;">' +
                            '<label style="color:#7dd3fc;">Dosen-Bezeichnung *</label>' +
                            '<input type="text" id="sw-outlet-label-input" class="form-input" style="font-family:monospace;" value="' + escapeHtml(curOut.outletLabel || '') + '" placeholder="z.B. Dose 1.12 / E1-D14">' +
                        '</div>' +
                        '<div class="form-group" style="margin:0;">' +
                            '<label>Patchfeld / Port</label>' +
                            '<input type="text" id="sw-outlet-patch-input" class="form-input" style="font-family:monospace;" value="' + escapeHtml(curOut.patchPanel || '') + '" placeholder="z.B. PF1-' + escapeHtml(p.shortName) + '">' +
                        '</div>' +
                        '<div class="form-group" style="margin:0;">' +
                            '<label>Raum / Abteilung</label>' +
                            '<input type="text" id="sw-outlet-room-input" class="form-input" value="' + escapeHtml(curOut.room || '') + '" placeholder="z.B. Büro EG / Raum 104">' +
                        '</div>' +
                        '<div class="form-group" style="margin:0;">' +
                            '<label>Notiz (optional)</label>' +
                            '<input type="text" id="sw-outlet-notes-input" class="form-input" value="' + escapeHtml(curOut.notes || '') + '" placeholder="z.B. Linke Buchse / Fenster">' +
                        '</div>' +
                        '<button type="button" class="btn btn-sm btn-primary" style="height:35px;padding:0 12px;" onclick="saveSingleSwitchPortOutlet(\'' + p.key + '\')">💾 Dose speichern</button>' +
                    '</div>' +
                '</div>';
        } else if (p.outletInfo && p.outletInfo.fullText) {
            outletEditorHtml =
                '<div style="margin-top:10px;padding:8px 12px;background:rgba(14,165,233,0.1);border:1px solid rgba(56,189,248,0.35);border-radius:6px;font-size:12.5px;color:#bae6fd;">' +
                    '🔌 <b>Zugeordnete Netzwerkdose / Patchfeld:</b> ' + escapeHtml(p.outletInfo.fullText) + (p.outletInfo.notes ? (' (' + escapeHtml(p.outletInfo.notes) + ')') : '') +
                '</div>';
        }
    }

    box.innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;border-bottom:1px solid var(--border-color);padding-bottom:10px;margin-bottom:10px;">' +
            '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">' +
                '<span style="font-family:monospace;font-size:16px;font-weight:800;color:#60a5fa;">Port ' + escapeHtml(p.name) + ' (Unit #' + p.unit + ')</span>' +
                vlanBadge +
                stBadge +
                roleBadge +
                outletHeaderBadge +
            '</div>' +
            '<div style="font-family:monospace;font-size:12px;color:var(--text-secondary);">' +
                'Traffic RX: <b style="color:#f8fafc;">' + (p.rx_mb ? (p.rx_mb + ' MB') : '0 MB') + '</b> • ' +
                'Traffic TX: <b style="color:#f8fafc;">' + (p.tx_mb ? (p.tx_mb + ' MB') : '0 MB') + '</b> • ' +
                'Paketfehler: <b style="color:' + (p.in_errors > 0 ? '#ef4444' : '#34d399') + ';">' + (p.in_errors || 0) + '</b>' +
            '</div>' +
        '</div>' +
        '<div style="font-size:12px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;">Angeschlossene Geräte / Gegenstelle an Buchse #' + escapeHtml(p.shortName) + ':</div>' +
        devsHtml +
        outletEditorHtml +
        portHistoryHtml +
        sshConfigHtml;
}

/**
 * Klappt die Gesamtübersicht aller Netzwerkdosen-/Patchfeld-Zuordnungen im Switch-Dialog auf oder zu.
 */
function toggleSwitchOutletsBulkPanel() {
    var p = document.getElementById('sw-outlets-bulk-panel');
    if (!p) return;
    p.style.display = (p.style.display === 'none' || !p.style.display) ? 'block' : 'none';
}

/**
 * Speichert die Netzwerkdosen-, Patchfeld- und Raum-Zuordnung für den aktuell ausgewählten Switch-Port.
 */
function saveSingleSwitchPortOutlet(portKey) {
    var p = SwitchGraphicState.portsByKey[portKey];
    var devId = SwitchGraphicState.currentDevId;
    if (!p || !devId) return;

    var olEl = document.getElementById('sw-outlet-label-input');
    var ppEl = document.getElementById('sw-outlet-patch-input');
    var rmEl = document.getElementById('sw-outlet-room-input');
    var ntEl = document.getElementById('sw-outlet-notes-input');

    var payload = {
        switch_id: devId,
        port_name: p.name,
        outlet_label: olEl ? olEl.value.trim() : '',
        patch_panel: ppEl ? ppEl.value.trim() : '',
        room: rmEl ? rmEl.value.trim() : '',
        notes: ntEl ? ntEl.value.trim() : ''
    };

    // Wenn im SSH-Formular das Alias-Feld noch leer ist, direkt vorbefüllen
    var aliasInp = document.getElementById('sw-port-alias-input');
    if (aliasInp && !aliasInp.value.trim() && payload.outlet_label) {
        aliasInp.value = payload.outlet_label + (payload.room ? (' ' + payload.room) : '');
    }

    fetch('api.php?action=save_port_outlet', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (!res.ok) {
            showToast('Fehler: ' + (res.error || 'Unbekannt'));
            return;
        }
        AppState.portOutlets = res.port_outlets || [];
        showToast('🔌 Dosen-Zuordnung für Port ' + p.name + ' gespeichert!');
        openSwitchGraphicModal(devId, false, p.name);
        renderInventoryTable();
        if (AppState.selectedDeviceId) {
            selectDevice(AppState.selectedDeviceId);
        }
    });
}

/**
 * Speichert alle in der tabellarischen Gesamtübersicht eingetragenen Dosen-Zuordnungen eines Switches auf einmal.
 */
function saveSwitchOutletsBulk(devId) {
    var rows = document.querySelectorAll('#sw-outlets-bulk-panel .sw-bulk-outlet-row');
    var entries = [];
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var pName = r.getAttribute('data-port') || '';
        if (!pName) continue;
        var ol = r.querySelector('.sw-bulk-ol');
        var pp = r.querySelector('.sw-bulk-pp');
        var rm = r.querySelector('.sw-bulk-rm');
        var nt = r.querySelector('.sw-bulk-nt');
        entries.push({
            port_name: pName,
            outlet_label: ol ? ol.value.trim() : '',
            patch_panel: pp ? pp.value.trim() : '',
            room: rm ? rm.value.trim() : '',
            notes: nt ? nt.value.trim() : ''
        });
    }

    fetch('api.php?action=save_port_outlets_bulk', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            switch_id: devId,
            entries: entries
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (!res.ok) {
            showToast('Fehler: ' + (res.error || 'Unbekannt'));
            return;
        }
        AppState.portOutlets = res.port_outlets || [];
        var curPortName = null;
        if (SwitchGraphicState.selectedPortKey && SwitchGraphicState.portsByKey[SwitchGraphicState.selectedPortKey]) {
            curPortName = SwitchGraphicState.portsByKey[SwitchGraphicState.selectedPortKey].name;
        }
        showToast('🔌 ' + (res.saved_count || 0) + ' Dosen- & Patchfeld-Zuordnungen erfolgreich gespeichert!');
        openSwitchGraphicModal(devId, false, curPortName);
        renderInventoryTable();
        if (AppState.selectedDeviceId) {
            selectDevice(AppState.selectedDeviceId);
        }
    });
}

/**
 * Exportiert den gesamten Patchfeld-/Dosen-Belegungsplan eines Switches als CSV-Datei für Excel.
 */
function exportSwitchOutletsCsv(devId) {
    var model = SwitchGraphicState.currentModel;
    if (!model || !model.units) return;
    var rows = [['Switch_Port', 'Buchse_Nr', 'Dosen_Bezeichnung', 'Patchfeld_Port', 'Raum_Abteilung', 'Notiz', 'VLAN', 'Aktuelles_Geraet']];
    for (var u = 0; u < model.units.length; u++) {
        var ports = model.units[u].ports || [];
        for (var p = 0; p < ports.length; p++) {
            var prt = ports[p];
            var out = (prt.outletInfo && prt.outletInfo.isFromDb) ? prt.outletInfo : { outletLabel: '', patchPanel: '', room: '', notes: '' };
            var devStr = (prt.connectedDevices && prt.connectedDevices.length > 0)
                ? (prt.connectedDevices[0].name + ' (' + prt.connectedDevices[0].ip + ')')
                : '';
            rows.push([
                prt.name,
                prt.shortName,
                out.outletLabel || '',
                out.patchPanel || '',
                out.room || '',
                out.notes || '',
                String(prt.vlan || 1),
                devStr
            ]);
        }
    }
    var csv = rows.map(function (r) {
        return r.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(';');
    }).join('\r\n');

    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'patchliste_' + (model.switchName || ('switch_' + devId)).replace(/[^a-zA-Z0-9_-]/g, '_') + '.csv';
    link.click();
    showToast('📥 Patchfeld- & Dosen-Liste als CSV exportiert.');
}

/**
 * Importiert Dosen- und Patchfeld-Zuordnungen aus einer CSV-Liste oder per Copy & Paste.
 */
function importSwitchOutletsCsvPrompt(devId) {
    var fileInp = document.createElement('input');
    fileInp.type = 'file';
    fileInp.accept = '.csv,.txt';
    fileInp.onchange = function (ev) {
        var f = ev.target.files && ev.target.files[0];
        if (!f) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            var text = String(e.target.result || '');
            var lines = text.split(/\r?\n/);
            var byPortLow = {};
            var byNum = {};
            for (var i = 0; i < lines.length; i++) {
                var ln = lines[i].trim();
                if (!ln) continue;
                var sep = (ln.indexOf(';') !== -1) ? ';' : ',';
                var cols = ln.split(sep).map(function (c) { return c.replace(/^"|"$/g, '').replace(/""/g, '"').trim(); });
                if (i === 0 && (cols[0].toLowerCase().indexOf('port') !== -1 || cols[0].toLowerCase().indexOf('switch') !== -1)) {
                    continue;
                }
                var pCol = cols[0] || '';
                if (!pCol) continue;
                var hasBuchseCol = (cols.length >= 6 && /^\d+$/.test(cols[1]));
                var entry = {
                    outlet_label: hasBuchseCol ? (cols[2] || '') : (cols[1] || ''),
                    patch_panel:  hasBuchseCol ? (cols[3] || '') : (cols[2] || ''),
                    room:         hasBuchseCol ? (cols[4] || '') : (cols[3] || ''),
                    notes:        hasBuchseCol ? (cols[5] || '') : (cols[4] || '')
                };
                byPortLow[pCol.toLowerCase()] = entry;
                var numM = pCol.match(/(\d+)$/);
                if (numM) {
                    byNum[parseInt(numM[1], 10)] = entry;
                }
            }
            var rows = document.querySelectorAll('#sw-outlets-bulk-panel .sw-bulk-outlet-row');
            var matchedCnt = 0;
            for (var r = 0; r < rows.length; r++) {
                var rowEl = rows[r];
                var pName = rowEl.getAttribute('data-port') || '';
                var mObj = byPortLow[pName.toLowerCase()];
                if (!mObj) {
                    var nm = pName.match(/(\d+)$/);
                    if (nm && byNum[parseInt(nm[1], 10)]) {
                        mObj = byNum[parseInt(nm[1], 10)];
                    }
                }
                if (mObj) {
                    rowEl.querySelector('.sw-bulk-ol').value = mObj.outlet_label;
                    rowEl.querySelector('.sw-bulk-pp').value = mObj.patch_panel;
                    rowEl.querySelector('.sw-bulk-rm').value = mObj.room;
                    rowEl.querySelector('.sw-bulk-nt').value = mObj.notes;
                    matchedCnt++;
                }
            }
            if (matchedCnt > 0) {
                saveSwitchOutletsBulk(devId);
            } else {
                showToast('Keine passenden Port-Zeilen in der CSV-Datei gefunden.');
            }
        };
        reader.readAsText(f);
    };
    fileInp.click();
}

/**
 * Blendet das Formular für die SSH-/SNMP-Zugangsdaten des Switches ein oder aus.
 */
function toggleSwitchCredsForm() {
    var box = document.getElementById('sw-creds-form-box');
    if (!box) return;
    box.style.display = (box.style.display === 'none') ? 'block' : 'none';
}

/**
 * Passt die VLAN-Eingabefelder im Port-Konfigurationsformular an den gewählten Modus (Access vs. Trunk) an.
 */
function onSwitchPortModeChange() {
    var modeSel = document.getElementById('sw-port-mode-select');
    var trWrap = document.getElementById('sw-trunk-allowed-wrap');
    var vlanLbl = document.getElementById('sw-port-vlan-label');
    if (!modeSel) return;
    var isTrunk = (modeSel.value === 'trunk');
    if (trWrap) trWrap.style.display = isTrunk ? 'block' : 'none';
    if (vlanLbl) vlanLbl.textContent = isTrunk ? 'Native VLAN (Ungetaggt auf Trunk)' : 'Access-VLAN (PVID)';
}

/**
 * Zeigt bei Auswahl von 'Neues VLAN anlegen' die Zusatzfelder für VLAN-ID und VLAN-Name an.
 */
function onSwitchVlanSelectChange() {
    var sel = document.getElementById('sw-port-vlan-select');
    var idWrap = document.getElementById('sw-new-vlan-id-wrap');
    var nameWrap = document.getElementById('sw-new-vlan-name-wrap');
    if (!sel || !idWrap || !nameWrap) return;
    var isNew = (sel.value === '__new__');
    idWrap.style.display = isNew ? 'block' : 'none';
    nameWrap.style.display = isNew ? 'block' : 'none';
}

/**
 * Speichert die SSH-/SNMP-Zugangsdaten eines Switches und führt optional sofort einen Verbindungstest durch.
 */
function saveSwitchCredentials(devId, testConn) {
    var profEl = document.getElementById('sw-cred-cli-profile');
    var userEl = document.getElementById('sw-cred-ssh-user');
    var passEl = document.getElementById('sw-cred-ssh-pass');
    var enPassEl = document.getElementById('sw-cred-enable-pass');
    var commEl = document.getElementById('sw-cred-snmp-comm');
    var writeEl = document.getElementById('sw-cred-snmp-write');
    var isCoreEl = document.getElementById('sw-cred-is-core');
    var msgEl = document.getElementById('sw-creds-status-msg');
    var btnEl = document.getElementById('btn-save-sw-creds');

    var payload = {
        device_id: devId,
        cli_profile: profEl ? profEl.value : 'auto',
        switch_ssh_user: userEl ? userEl.value : '',
        switch_ssh_pass: passEl ? passEl.value : '',
        switch_enable_pass: enPassEl ? enPassEl.value : '',
        switch_snmp_community: commEl ? commEl.value : 'public',
        switch_snmp_write_community: writeEl ? writeEl.value : '',
        test_connection: testConn ? 1 : 0
    };
    if (isCoreEl) {
        payload.is_core = isCoreEl.checked ? 1 : 0;
    }

    if (msgEl) {
        msgEl.style.display = 'block';
        msgEl.style.color = '#93c5fd';
        msgEl.innerHTML = testConn
            ? '⏳ Speichere Zugangsdaten und teste SSH- &amp; SNMP-Verbindung zum Switch...'
            : '⏳ Speichere Zugangsdaten...';
    }
    if (btnEl) btnEl.disabled = true;

    fetch('api.php?action=save_switch_credentials', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (btnEl) btnEl.disabled = false;
        if (!res.ok) {
            if (msgEl) {
                msgEl.style.color = '#fca5a5';
                msgEl.textContent = '❌ Fehler: ' + (res.error || 'Unbekannter Fehler');
            }
            return;
        }

        // Aktualisiere Gerätedaten im lokalen AppState
        for (var i = 0; i < AppState.devices.length; i++) {
            if (parseInt(AppState.devices[i].id, 10) === parseInt(devId, 10)) {
                AppState.devices[i].switch_ssh_user = payload.switch_ssh_user;
                AppState.devices[i].switch_snmp_community = payload.switch_snmp_community;
                AppState.devices[i].has_ssh_pass = res.has_ssh_pass ? 1 : 0;
                AppState.devices[i].has_enable_pass = res.has_enable_pass ? 1 : 0;
                AppState.devices[i].has_snmp_write = res.has_snmp_write ? 1 : 0;
                if (res.is_core !== undefined) {
                    AppState.devices[i].is_core = res.is_core ? 1 : 0;
                }
                if (res.cli_profile) {
                    AppState.devices[i].cli_profile = res.cli_profile;
                    AppState.devices[i].cli_profile_label = res.cli_profile_label;
                    AppState.devices[i].cli_profile_is_manual = res.cli_profile_is_manual ? 1 : 0;
                }
                if (res.snmp_data) {
                    AppState.devices[i].snmp_enabled = 1;
                    AppState.devices[i].snmp_ports_json = JSON.stringify(res.snmp_data);
                }
                break;
            }
        }

        if (testConn && res.ssh_test) {
            if (res.ssh_test.success) {
                showToast('✅ SSH- & SNMP-Verbindung zum Switch erfolgreich getestet!');
                openSwitchGraphicModal(devId, false);
            } else {
                if (msgEl) {
                    msgEl.style.color = '#fca5a5';
                    msgEl.textContent = '❌ SSH-Test fehlgeschlagen: ' + (res.ssh_test.message || '');
                }
            }
        } else {
            showToast('Switch-Zugangsdaten gespeichert.');
            openSwitchGraphicModal(devId, false);
        }
    })
    .catch(function (err) {
        if (btnEl) btnEl.disabled = false;
        if (msgEl) {
            msgEl.style.color = '#fca5a5';
            msgEl.textContent = '❌ Netzwerkfehler: ' + err.message;
        }
    });
}

/**
 * Überträgt die geänderten Port-Einstellungen (VLAN, Status, Beschreibung, PoE, Speed) live per SSH/SNMP auf den Switch.
 */
function applySwitchPortConfig(portKey) {
    var p = SwitchGraphicState.portsByKey[portKey];
    if (!p || !SwitchGraphicState.currentDevId) return;

    var modeSel = document.getElementById('sw-port-mode-select');
    var trAllowedEl = document.getElementById('sw-trunk-allowed-input');
    var vlanSel = document.getElementById('sw-port-vlan-select');
    var newVlanIdEl = document.getElementById('sw-new-vlan-id');
    var newVlanNameEl = document.getElementById('sw-new-vlan-name');
    var aliasEl = document.getElementById('sw-port-alias-input');
    var admEl = document.getElementById('sw-port-admin-status');
    var saveEl = document.getElementById('sw-port-save-flash');
    var statusBox = document.getElementById('sw-port-config-status');
    var btn = document.getElementById('btn-apply-sw-port');

    var portModeVal = modeSel ? modeSel.value : 'access';
    var trunkAllowedVal = trAllowedEl ? (trAllowedEl.value.trim() || 'all') : 'all';
    var selectedVlanVal = vlanSel ? vlanSel.value : String(p.vlan || 1);
    var createVlanId = 0;
    var createVlanName = '';
    var targetVlanId = parseInt(selectedVlanVal, 10) || 1;

    if (selectedVlanVal === '__new__') {
        createVlanId = newVlanIdEl ? parseInt(newVlanIdEl.value, 10) : 0;
        createVlanName = newVlanNameEl ? newVlanNameEl.value.trim() : '';
        if (!createVlanId || createVlanId < 2 || createVlanId > 4094) {
            showToast('Bitte eine gültige neue VLAN-ID zwischen 2 und 4094 eingeben.');
            return;
        }
        targetVlanId = createVlanId;
    }

    var targetAdm = admEl ? admEl.value : 'up';

    // Sicherheitsabfrage bei Änderungen am Uplink-Port
    if (p.isUplink && (targetVlanId !== (p.vlan || 1) || targetAdm === 'down' || portModeVal === 'access')) {
        var okUplink = confirm(
            'WARNUNG: Port ' + p.name + ' ist der Uplink-Port dieses Switches!\n\n' +
            'Wenn du das VLAN/Modus auf diesem Port änderst oder den Port abschaltest, ist der Switch möglicherweise über das Netzwerk nicht mehr erreichbar.\n\n' +
            'Möchtest du die Änderung trotzdem auf Port ' + p.name + ' anwenden?'
        );
        if (!okUplink) return;
    }

    var payload = {
        device_id: SwitchGraphicState.currentDevId,
        port_name: p.name,
        port_mode: portModeVal,
        trunk_allowed_vlans: trunkAllowedVal,
        vlan_id: targetVlanId,
        create_vlan_id: createVlanId,
        create_vlan_name: createVlanName,
        alias: aliasEl ? aliasEl.value.trim() : '',
        admin_status: targetAdm,
        save_config: saveEl ? (saveEl.checked ? 1 : 0) : 1
    };

    if (statusBox) {
        statusBox.style.display = 'block';
        statusBox.style.padding = '8px 12px';
        statusBox.style.borderRadius = '6px';
        statusBox.style.background = 'rgba(59,130,246,0.16)';
        statusBox.style.border = '1px solid #3b82f6';
        statusBox.style.color = '#93c5fd';
        statusBox.innerHTML = '⏳ Verbinde per SSH mit Switch und übertrage Konfiguration auf Port <b>' + escapeHtml(p.name) + '</b> (inkl. SNMP-Verifikation)...';
    }
    if (btn) btn.disabled = true;

    fetch('api.php?action=configure_switch_port', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (btn) btn.disabled = false;
        if (!res.ok) {
            if (statusBox) {
                statusBox.style.background = 'rgba(239,68,68,0.16)';
                statusBox.style.border = '1px solid #ef4444';
                statusBox.style.color = '#fca5a5';
                statusBox.textContent = '❌ Fehler: ' + (res.error || 'SSH-Befehl fehlgeschlagen');
            }
            return;
        }

        // Aktualisiere SNMP-Daten im lokalen AppState, damit die 19"-Grafik sofort das neue VLAN, den Alias & Status zeigt!
        if (res.snmp_data) {
            for (var i = 0; i < AppState.devices.length; i++) {
                if (parseInt(AppState.devices[i].id, 10) === parseInt(SwitchGraphicState.currentDevId, 10)) {
                    AppState.devices[i].snmp_enabled = 1;
                    AppState.devices[i].snmp_ports_json = JSON.stringify(res.snmp_data);
                    break;
                }
            }
        }

        showToast('✅ Port ' + p.name + ' erfolgreich auf VLAN ' + targetVlanId + ' umgestellt & gespeichert!');
        openSwitchGraphicModal(SwitchGraphicState.currentDevId, false, p.name);
    })
    .catch(function (err) {
        if (btn) btn.disabled = false;
        if (statusBox) {
            statusBox.style.background = 'rgba(239,68,68,0.16)';
            statusBox.style.border = '1px solid #ef4444';
            statusBox.style.color = '#fca5a5';
            statusBox.textContent = '❌ Netzwerkfehler: ' + err.message;
        }
    });
}

/**
 * Exportiert die aktuell gefilterte Inventarliste als UTF-8-CSV-Datei für Excel.
 */
function exportInventoryCsv() {
    var list = (InvTableState.lastFilteredRows && InvTableState.lastFilteredRows.length > 0) ? InvTableState.lastFilteredRows : AppState.devices;
    var rows = [['Status', 'IP', 'Name', 'Gruppe', 'Typ', 'Switch_Port_Dose', 'Betriebssystem', 'Dienste_Banner_TTL', 'MAC', 'Hersteller', 'Ports', 'SNMP_Name', 'Scope']];
    for (var i = 0; i < list.length; i++) {
        var d = list[i];
        var sp = d._spInfo || getDeviceSwitchPortInfo(d);
        var spTxt = (sp && sp.switchId)
            ? (sp.switchName + ' Port ' + (sp.portName || '-') + (sp.outletInfo && sp.outletInfo.fullText ? (' [' + sp.outletInfo.fullText + ']') : ''))
            : '';
        rows.push([
            d.status,
            d.ip_address,
            d.custom_name || d.hostname || '',
            d.custom_group || '',
            d.device_type,
            spTxt,
            d.os_guess || '',
            d.service_info || '',
            d.mac_address || '',
            d.vendor || '',
            d.open_ports || '',
            d.snmp_sysname || '',
            d.scope_name || ''
        ]);
    }
    var csv = rows.map(function (r) {
        return r.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(';');
    }).join('\n');

    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'netzwerk_inventar.csv';
    link.click();
}

/**
 * Berechnet die Dauer eines Scan-Durchlaufs aus Start- und Endzeitpunkt.
 */
function formatScanLogDuration(logEntry) {
    if (!logEntry) return '-';
    var sum = String(logEntry.summary || '');
    var m = sum.match(/in\s+([0-9]+(?:\.[0-9]+)?)\s*s\b/i);
    if (m && m[1]) {
        return m[1].replace('.', ',') + ' s';
    }
    if (logEntry.started_at && logEntry.finished_at) {
        var t1 = new Date(String(logEntry.started_at).replace(' ', 'T')).getTime();
        var t2 = new Date(String(logEntry.finished_at).replace(' ', 'T')).getTime();
        if (!isNaN(t1) && !isNaN(t2) && t2 >= t1) {
            var sec = Math.max(1, Math.round((t2 - t1) / 1000));
            return sec + ' s';
        }
    }
    return '-';
}

/**
 * Rendert den Reiter 'System & Cronjob' inkl. Datenbankstatus, Version und vollständiger Scan-Protokoll-Tabelle.
 */
function renderSystemInfo() {
    var phpEl = document.getElementById('sys-php');
    if (!phpEl) return;
    var sys = AppState.system || {};
    phpEl.textContent = sys.php_version || '-';
    document.getElementById('sys-db').textContent = sys.db_driver || '-';
    document.getElementById('sys-nmap').textContent = sys.nmap_path || '-';
    document.getElementById('sys-snmp').textContent = sys.snmp_mode || '-';
    document.getElementById('sys-cron-cmd').textContent = sys.cron_cmd || '';

    if (sys.app_version) {
        var verStr = 'v' + sys.app_version + (sys.git_commit ? (' (Commit ' + sys.git_commit + ')') : '');
        var appVerEl = document.getElementById('sys-app-version');
        if (appVerEl) appVerEl.textContent = verStr;
        var verPill = document.getElementById('sys-version-pill');
        if (verPill) verPill.textContent = 'v' + sys.app_version + (sys.git_commit ? (' (' + sys.git_commit + ')') : '');
        var topBadge = document.getElementById('topbar-version-badge');
        if (topBadge) topBadge.textContent = 'v' + sys.app_version;
    }

    var cronBadge = document.getElementById('sys-cron-status-badge');
    if (cronBadge) {
        if (parseInt(sys.cron_active || 0, 10) === 1 && parseInt(sys.cron_interval || 0, 10) > 0) {
            cronBadge.className = 'badge badge-online';
            cronBadge.textContent = '🟢 Cronjob aktiv (alle ' + sys.cron_interval + ' Min.)';
        } else {
            cronBadge.className = 'badge badge-offline';
            cronBadge.textContent = '⚪ Cronjob deaktiviert (nur manuell)';
        }
    }

    var durInfoEl = document.getElementById('last-scan-duration-info');
    if (durInfoEl) {
        if (AppState.logs && AppState.logs.length > 0) {
            durInfoEl.textContent = '(Dauer letzter Scan: ' + formatScanLogDuration(AppState.logs[0]) + ')';
        } else {
            durInfoEl.textContent = '';
        }
    }

    var logBody = document.getElementById('scan-logs-tbody');
    var lhtml = '';
    for (var i = 0; i < AppState.logs.length; i++) {
        var l = AppState.logs[i];
        var durStr = formatScanLogDuration(l);
        lhtml += '<tr>' +
            '<td>' + escapeHtml(l.started_at || '-') + '</td>' +
            '<td style="font-family:monospace;font-weight:700;color:#34d399;">' + escapeHtml(durStr) + '</td>' +
            '<td>' + escapeHtml(l.scope_name || 'Alle') + ' (' + escapeHtml(l.scope_cidr || '') + ')</td>' +
            '<td>' + escapeHtml(l.trigger_type) + '</td>' +
            '<td>' + escapeHtml(l.status) + '</td>' +
            '<td>' + escapeHtml(l.summary || '') + '</td>' +
            '</tr>';
    }
    logBody.innerHTML = lhtml || '<tr><td colspan="6">Noch keine Scans durchgeführt.</td></tr>';
}

/**
 * Prüft im Admin-Bereich per API-Aufruf, ob auf GitHub eine neuere Programmversion vorliegt.
 */
function checkGithubUpdate() {
    var box = document.getElementById('adm-update-status-box');
    var btn = document.getElementById('btn-check-gh-update');
    if (box) {
        box.style.display = 'block';
        box.style.background = 'rgba(59,130,246,0.12)';
        box.style.border = '1px solid rgba(59,130,246,0.4)';
        box.style.color = '#93c5fd';
        box.innerHTML = '🔍 Verbinde mit GitHub (<code>uWiiiDev/NetworkMonitor</code>) und prüfe auf neue Versionen / Commits...';
    }
    if (btn) btn.disabled = true;

    fetch('api.php?action=admin_check_update', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (btn) btn.disabled = false;
        if (!res.ok) {
            if (box) {
                box.style.background = 'rgba(239,68,68,0.15)';
                box.style.border = '1px solid rgba(239,68,68,0.45)';
                box.style.color = '#fca5a5';
                box.textContent = '✕ Fehler bei der Update-Prüfung: ' + (res.error || 'Unbekannt');
            }
            return;
        }
        var locTxt = 'v' + escapeHtml(res.local_version) + (res.local_commit ? (' (<code>' + escapeHtml(res.local_commit) + '</code>)') : '');
        var remTxt = 'v' + escapeHtml(res.remote_version) + (res.remote_commit ? (' (<code>' + escapeHtml(res.remote_commit) + '</code>)') : '');

        if (parseInt(res.update_available, 10) === 1) {
            var logHtml = '';
            if (res.changelog && res.changelog.length > 0) {
                logHtml = '<div style="margin-top:8px;padding:8px;background:rgba(15,23,42,0.75);border-radius:6px;font-family:monospace;font-size:11px;color:#cbd5e1;">' +
                    '<div style="color:#fcd34d;font-weight:700;margin-bottom:4px;">Neue Änderungen auf GitHub (' + res.commits_behind + ' Commit(s)):</div>';
                for (var c = 0; c < res.changelog.length; c++) {
                    logHtml += '• ' + escapeHtml(res.changelog[c]) + '<br>';
                }
                logHtml += '</div>';
            }
            if (box) {
                box.style.background = 'rgba(245,158,11,0.15)';
                box.style.border = '1px solid rgba(245,158,11,0.5)';
                box.style.color = '#fde68a';
                box.innerHTML = '🔔 <b>Neues Update auf GitHub verfügbar!</b><br>' +
                    'Installiert: <b>' + locTxt + '</b> &rarr; Verfügbar auf GitHub: <b style="color:#34d399;">' + remTxt + '</b>' +
                    logHtml +
                    '<div style="margin-top:8px;"><button class="btn btn-sm btn-primary" onclick="runGithubUpdate()">⬇️ Update jetzt installieren</button></div>';
            }
        } else {
            if (box) {
                box.style.background = 'rgba(16,185,129,0.14)';
                box.style.border = '1px solid rgba(16,185,129,0.45)';
                box.style.color = '#6ee7b7';
                box.innerHTML = '✅ <b>Deine Installation ist auf dem neuesten Stand!</b><br>' +
                    'Installierte Version: <b>' + locTxt + '</b> • GitHub-Stand (<code>origin/main</code>): <b>' + remTxt + '</b> (Geprüft am ' + escapeHtml(res.checked_at) + ')';
            }
        }
    })
    .catch(function (err) {
        if (btn) btn.disabled = false;
        if (box) {
            box.style.background = 'rgba(239,68,68,0.15)';
            box.style.border = '1px solid rgba(239,68,68,0.45)';
            box.style.color = '#fca5a5';
            box.textContent = '✕ Verbindungsfehler: ' + err.message;
        }
    });
}

/**
 * Startet nach Bestätigung das automatische Live-Update der Anwendung von GitHub.
 */
function runGithubUpdate() {
    if (!confirm('Möchtest du NetworkMonitor jetzt direkt über GitHub (origin/main) auf die neueste Version aktualisieren?\n\nDeine lokalen Datenbanken und Einstellungen in data/ bleiben vollständig erhalten.')) {
        return;
    }
    var box = document.getElementById('adm-update-status-box');
    var btn = document.getElementById('btn-run-gh-update');
    if (box) {
        box.style.display = 'block';
        box.style.background = 'rgba(59,130,246,0.12)';
        box.style.border = '1px solid rgba(59,130,246,0.4)';
        box.style.color = '#93c5fd';
        box.innerHTML = '⏳ Lade neueste Programmdateien von GitHub (<code>origin/main</code>) und aktualisiere Installation...';
    }
    if (btn) btn.disabled = true;

    fetch('api.php?action=admin_run_update', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (btn) btn.disabled = false;
        if (!res.ok) {
            if (box) {
                box.style.background = 'rgba(239,68,68,0.15)';
                box.style.border = '1px solid rgba(239,68,68,0.45)';
                box.style.color = '#fca5a5';
                box.textContent = '✕ Update fehlgeschlagen: ' + (res.error || 'Unbekannt');
            }
            return;
        }
        showToast('✅ Update auf Version v' + res.new_version + ' erfolgreich abgeschlossen!');
        if (box) {
            box.style.background = 'rgba(16,185,129,0.16)';
            box.style.border = '1px solid rgba(16,185,129,0.5)';
            box.style.color = '#6ee7b7';
            box.innerHTML = '✅ <b>Update erfolgreich abgeschlossen!</b><br>' +
                'Aktuelle Version: <b>v' + escapeHtml(res.new_version) + '</b>' + (res.new_commit ? (' (Commit <code>' + escapeHtml(res.new_commit) + '</code>)') : '') + '<br>' +
                '<div style="margin-top:6px;padding:6px 8px;background:rgba(15,23,42,0.75);border-radius:6px;font-family:monospace;font-size:11px;color:#cbd5e1;white-space:pre-wrap;">' + escapeHtml(res.output || 'OK') + '</div>' +
                '<div style="margin-top:8px;"><button class="btn btn-sm btn-success" onclick="window.location.reload()">🔄 Anwendung jetzt neu laden</button></div>';
        }
        loadTopologyData();
    })
    .catch(function (err) {
        if (btn) btn.disabled = false;
        if (box) {
            box.style.background = 'rgba(239,68,68,0.15)';
            box.style.border = '1px solid rgba(239,68,68,0.45)';
            box.style.color = '#fca5a5';
            box.textContent = '✕ Verbindungsfehler beim Update: ' + err.message;
        }
    });
}

/**
 * Wechselt zwischen den Hauptreitern der Anwendung (Topologie-Karte, Inventarliste, System & Cronjob, Admin).
 */
function switchTab(tabName, btn) {
    var tabs = document.querySelectorAll('.nav-tab');
    for (var i = 0; i < tabs.length; i++) tabs[i].classList.remove('active');
    btn.classList.add('active');

    var panes = document.querySelectorAll('.view-pane');
    for (var j = 0; j < panes.length; j++) panes[j].classList.remove('active');
    var targetPane = document.getElementById('view-' + tabName);
    if (targetPane) targetPane.classList.add('active');

    if (tabName === 'map') {
        setTimeout(function () { renderCurrentView(); }, 80);
    }
}

/**
 * Adminbereich: Datenbank-Konfiguration, Scanner-Parameter & Benutzerverwaltung
 */
function renderAdminSection() {
    var adm = AppState.admin;
    if (!adm) return;

    var db = adm.db_config || {};
    var sc = adm.scanner_config || {};

    var drvEl = document.getElementById('adm-db-driver');
    if (drvEl) {
        drvEl.value = db.driver || 'auto';
        document.getElementById('adm-db-host').value = db.host || '127.0.0.1';
        document.getElementById('adm-db-port').value = db.port || 3306;
        document.getElementById('adm-db-name').value = db.dbname || 'network_monitor';
        document.getElementById('adm-db-user').value = db.username || 'root';
        document.getElementById('adm-db-pass').value = '********';

        var badge = document.getElementById('adm-db-active-badge');
        if (badge) {
            badge.textContent = 'Aktuell aktiv: ' + String(db.active_driver || 'sqlite').toUpperCase();
        }
    }

    if (document.getElementById('adm-sc-maxhosts')) {
        document.getElementById('adm-sc-maxhosts').value = sc.max_hosts_per_scan || 4096;
        document.getElementById('adm-sc-snmpcomm').value = sc.default_snmp_community || 'public';
        document.getElementById('adm-sc-pingbatch').value = sc.ping_batch_size || 128;
        document.getElementById('adm-sc-portbatch').value = sc.port_host_batch_size || 32;
        document.getElementById('adm-sc-snmpbatch').value = sc.snmp_batch_size || 100;
    }

    if (document.getElementById('adm-sc-retention-days')) {
        document.getElementById('adm-sc-retention-days').value = (sc.retention_days !== undefined) ? sc.retention_days : 14;
    }
    if (document.getElementById('adm-sc-stale-days')) {
        document.getElementById('adm-sc-stale-days').value = (sc.stale_offline_days !== undefined) ? sc.stale_offline_days : 30;
    }
    if (document.getElementById('adm-cron-interval')) {
        var cVal = (parseInt(sc.cron_active || 0, 10) === 1 && sc.cron_interval_minutes) ? String(sc.cron_interval_minutes) : '0';
        document.getElementById('adm-cron-interval').value = cVal;
    }
    if (document.getElementById('adm-anom-arp')) {
        document.getElementById('adm-anom-arp').checked = (sc.anomaly_arp_spoofing === undefined) ? true : (parseInt(sc.anomaly_arp_spoofing, 10) === 1);
    }
    if (document.getElementById('adm-anom-newmac')) {
        document.getElementById('adm-anom-newmac').checked = (sc.anomaly_new_mac === undefined) ? true : (parseInt(sc.anomaly_new_mac, 10) === 1);
    }
    if (document.getElementById('adm-anom-maxmacs')) {
        document.getElementById('adm-anom-maxmacs').value = (sc.anomaly_rogue_switch_max_macs !== undefined) ? sc.anomaly_rogue_switch_max_macs : 3;
    }
    if (document.getElementById('adm-anom-flap')) {
        document.getElementById('adm-anom-flap').value = (sc.anomaly_mac_flapping_threshold !== undefined) ? sc.anomaly_mac_flapping_threshold : 3;
    }
    if (document.getElementById('adm-anom-porterr')) {
        document.getElementById('adm-anom-porterr').value = (sc.anomaly_port_error_threshold !== undefined) ? sc.anomaly_port_error_threshold : 50;
    }

    var smtp = adm.smtp_config || {};
    if (document.getElementById('adm-smtp-host')) {
        document.getElementById('adm-smtp-enabled').checked = !!smtp.enabled;
        document.getElementById('adm-smtp-host').value = smtp.host || '';
        document.getElementById('adm-smtp-port').value = smtp.port || 587;
        document.getElementById('adm-smtp-enc').value = smtp.encryption || 'tls';
        document.getElementById('adm-smtp-user').value = smtp.username || '';
        document.getElementById('adm-smtp-pass').value = '********';
        document.getElementById('adm-smtp-from').value = smtp.from_email || '';
        document.getElementById('adm-smtp-fromname').value = smtp.from_name || 'NetworkMonitor Alarm';
        document.getElementById('adm-smtp-to').value = smtp.to_email || '';
    }

    var tbody = document.getElementById('admin-users-tbody');
    if (!tbody) return;
    var users = adm.users || [];
    var html = '';
    var roleLabels = {
        admin: '<span class="badge" style="background:rgba(16,185,129,0.2);color:#34d399;">ADMIN (Vollzugriff)</span>',
        netadmin: '<span class="badge" style="background:rgba(168,85,247,0.22);border:1px solid #a855f7;color:#e9d5ff;">NETADMIN (Netzwerkexperte &amp; Core)</span>',
        operator: '<span class="badge" style="background:rgba(59,130,246,0.2);color:#93c5fd;">OPERATOR (Access-Ports &amp; Scans)</span>',
        viewer: '<span class="badge" style="background:rgba(148,163,184,0.2);color:#cbd5e1;">VIEWER (Nur Lesen)</span>'
    };

    for (var i = 0; i < users.length; i++) {
        var u = users[i];
        var isAct = parseInt(u.is_active, 10) === 1;
        var stBadge = isAct ? '<span class="badge badge-online">Aktiv</span>' : '<span class="badge badge-offline">Gesperrt</span>';
        var rBadge = roleLabels[u.role] || escapeHtml(u.role);

        var emailHtml = u.email
            ? '<div style="font-family:monospace;font-size:12px;color:#93c5fd;">' + escapeHtml(u.email) + '</div>' +
              (parseInt(u.receive_alerts, 10) === 1
                  ? '<span class="badge" style="background:rgba(16,185,129,0.18);color:#6ee7b7;font-size:10px;margin-top:2px;">🔔 Alarm-E-Mails aktiv</span>'
                  : '<span class="badge" style="background:rgba(148,163,184,0.15);color:#94a3b8;font-size:10px;margin-top:2px;">🔕 Keine Alarm-Mails</span>')
            : '<span style="color:var(--text-secondary);font-size:12px;">-</span>';

        var twoFaBadge = '<span class="badge" style="background:rgba(148,163,184,0.15);color:#94a3b8;">Aus</span>';
        if (u.two_fa_mode === 'email') {
            twoFaBadge = '<span class="badge" style="background:rgba(59,130,246,0.22);border:1px solid #3b82f6;color:#93c5fd;">📧 E-Mail-2FA</span>';
        } else if (u.two_fa_mode === 'totp') {
            twoFaBadge = '<span class="badge" style="background:rgba(16,185,129,0.22);border:1px solid #10b981;color:#6ee7b7;">📱 Authenticator-App</span>';
        }

        html += '<tr>' +
            '<td>' + stBadge + '</td>' +
            '<td style="font-weight:700;font-family:monospace;">' + escapeHtml(u.username) + '</td>' +
            '<td>' + escapeHtml(u.full_name || '-') + '</td>' +
            '<td>' + emailHtml + '</td>' +
            '<td>' + twoFaBadge + '</td>' +
            '<td>' + rBadge + '</td>' +
            '<td>' + escapeHtml(u.last_login || 'Noch nie') + '</td>' +
            '<td>' +
                '<button class="btn btn-sm" onclick="openUserModal(' + u.id + ')">Bearbeiten</button> ' +
                '<button class="btn btn-sm btn-danger" onclick="deleteUser(' + u.id + ')">Löschen</button>' +
            '</td>' +
            '</tr>';
    }
    tbody.innerHTML = html;

    // 7. Scopes & Scan-Datenbank Tabelle rendern
    var scopesTbody = document.getElementById('admin-scopes-tbody');
    if (scopesTbody) {
        var devCountByScope = {};
        for (var d = 0; d < AppState.devices.length; d++) {
            var sid = AppState.devices[d].scope_id;
            devCountByScope[sid] = (devCountByScope[sid] || 0) + 1;
        }

        var sHtml = '';
        for (var s = 0; s < AppState.scopes.length; s++) {
            var scItem = AppState.scopes[s];
            var dCnt = devCountByScope[scItem.id] || 0;
            sHtml += '<tr>' +
                '<td><div style="display:flex;align-items:center;gap:8px;"><span class="scope-dot" style="background:' + escapeHtml(scItem.color || '#3b82f6') + ';"></span><b>' + escapeHtml(scItem.name) + '</b></div></td>' +
                '<td style="font-family:monospace;color:#93c5fd;">' + escapeHtml(scItem.cidr) + '</td>' +
                '<td><span class="badge" style="background:rgba(59,130,246,0.18);color:#93c5fd;">' + dCnt + ' Geräte</span></td>' +
                '<td style="font-size:12px;color:var(--text-secondary);">' + escapeHtml(scItem.last_scan_at || 'Noch nie') + '</td>' +
                '<td>' +
                    '<div style="display:flex;gap:6px;flex-wrap:wrap;">' +
                        '<button class="btn btn-sm" style="border-color:#f59e0b;color:#fcd34d;" onclick="adminClearScopeScans(' + scItem.id + ', \'' + escapeHtml(scItem.name) + '\')">🧹 Nur Scans leeren</button>' +
                        '<button class="btn btn-sm btn-danger" onclick="adminDeleteScopeFull(' + scItem.id + ', \'' + escapeHtml(scItem.name) + '\')">🗑 Scope + Scans löschen</button>' +
                    '</div>' +
                '</td>' +
            '</tr>';
        }
        scopesTbody.innerHTML = sHtml || '<tr><td colspan="5" style="text-align:center;color:var(--text-secondary);padding:16px;">Keine Scopes in der Datenbank vorhanden.</td></tr>';
    }
}

/**
 * Löscht alle gescannten Geräte eines Scopes (oder aller Scopes), ohne die Scope-Definition selbst zu löschen.
 */
function adminClearScopeScans(scopeId, scopeLabel) {
    var msg = (scopeId === 'all')
        ? 'Möchtest du wirklich ALLE gescannten Geräte, Verbindungen und Scan-Protokolle aus allen Scopes leeren?\n(Die angelegten Netzwerk-Scopes selbst bleiben erhalten.)'
        : 'Möchtest du alle gescannten Geräte, Verbindungen und Protokolle für den Scope "' + scopeLabel + '" leeren?\n(Der Scope selbst bleibt erhalten.)';
    if (!confirm(msg)) return;

    fetch('api.php?action=admin_clear_scope_scans', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scope_id: scopeId })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            AppState.selectedDeviceId = null;
            showToast('Scan-Daten erfolgreich aus der Datenbank geleert.');
            loadTopologyData();
        } else {
            showToast('Fehler: ' + (res.error || 'Unbekannt'));
        }
    });
}

/**
 * Löscht einen kompletten Scope inklusive aller Daten aus dem Admin-Bereich heraus.
 */
function adminDeleteScopeFull(scopeId, scopeLabel) {
    if (!confirm('Möchtest du den kompletten Netzwerk-Scope "' + scopeLabel + '" inklusive ALLER gescannten Geräte, Verbindungen und Scan-Protokolle endgültig löschen?')) {
        return;
    }
    fetch('api.php?action=delete_scope', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: scopeId })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            if (String(AppState.selectedScopeId) === String(scopeId)) {
                AppState.selectedScopeId = 'all';
            }
            AppState.selectedDeviceId = null;
            showToast('Scope "' + scopeLabel + '" samt aller Scans gelöscht.');
            loadTopologyData();
        } else {
            showToast('Fehler: ' + (res.error || 'Unbekannt'));
        }
    });
}

/**
 * Setzt nach zweifacher Sicherheitsabfrage sämtliche Scopes und Gerätedaten der Datenbank zurück.
 */
function adminDeleteAllScopes() {
    if (!confirm('ACHTUNG: Möchtest du wirklich ALLE Netzwerk-Scopes samt sämtlicher Geräte, Verbindungen und Scan-Protokolle vollständig aus der Datenbank löschen?')) {
        return;
    }
    fetch('api.php?action=admin_delete_all_scopes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            AppState.selectedScopeId = 'all';
            AppState.selectedDeviceId = null;
            showToast('Komplette Scan- & Scope-Datenbank wurde geleert.');
            loadTopologyData();
        } else {
            showToast('Fehler: ' + (res.error || 'Unbekannt'));
        }
    });
}

/**
 * Leert die Tabelle der historischen Scan-Protokolle.
 */
function adminClearScanLogs() {
    if (!confirm('Alle Einträge im Scan-Protokoll löschen?')) return;
    fetch('api.php?action=admin_clear_scan_logs', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            showToast('Scan-Protokolle wurden geleert.');
            loadTopologyData();
        }
    });
}

/**
 * Testet die eingegebenen MySQL-/MariaDB-Zugangsdaten im Admin-Bereich.
 */
function testMysqlConnection() {
    var box = document.getElementById('adm-db-test-result');
    box.style.display = 'block';
    box.style.background = 'rgba(59,130,246,0.15)';
    box.style.color = '#93c5fd';
    box.textContent = 'Teste MySQL-Verbindung...';

    var payload = {
        host: document.getElementById('adm-db-host').value,
        port: document.getElementById('adm-db-port').value,
        dbname: document.getElementById('adm-db-name').value,
        username: document.getElementById('adm-db-user').value,
        password: document.getElementById('adm-db-pass').value === '********' ? '' : document.getElementById('adm-db-pass').value
    };

    fetch('api.php?action=test_db', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            box.style.background = 'rgba(16,185,129,0.18)';
            box.style.color = '#34d399';
            box.textContent = '✓ Verbindung erfolgreich! MySQL-Server Version: ' + res.info.version + ' (Datenbank `' + res.info.dbname + '` bereit).';
        } else {
            box.style.background = 'rgba(239,68,68,0.18)';
            box.style.color = '#fca5a5';
            box.textContent = '✕ MySQL-Fehler: ' + (res.error || 'Verbindung fehlgeschlagen');
        }
    });
}

/**
 * Speichert die Datenbank- und Scanner-Performance-Einstellungen im Admin-Bereich.
 */
function saveAdminDbAndScannerConfig() {
    var retEl = document.getElementById('adm-sc-retention-days');
    var staleEl = document.getElementById('adm-sc-stale-days');
    var payload = {
        driver: document.getElementById('adm-db-driver').value,
        host: document.getElementById('adm-db-host').value,
        port: document.getElementById('adm-db-port').value,
        dbname: document.getElementById('adm-db-name').value,
        username: document.getElementById('adm-db-user').value,
        password: document.getElementById('adm-db-pass').value,
        max_hosts_per_scan: document.getElementById('adm-sc-maxhosts').value,
        default_snmp_community: document.getElementById('adm-sc-snmpcomm').value,
        ping_batch_size: document.getElementById('adm-sc-pingbatch').value,
        port_host_batch_size: document.getElementById('adm-sc-portbatch').value,
        snmp_batch_size: document.getElementById('adm-sc-snmpbatch').value,
        retention_days: retEl ? retEl.value : 14,
        stale_offline_days: staleEl ? staleEl.value : 30
    };

    fetch('api.php?action=save_db_config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            showToast('Datenbank- & Scanner-Konfiguration erfolgreich gespeichert!');
            loadTopologyData();
        } else {
            showToast('Fehler: ' + res.error);
        }
    });
}

/**
 * Speichert das automatische Hintergrund-Scan-Intervall sowie die Aufbewahrungsfristen (Retention).
 */
function saveAdminCronAndRetention() {
    var cronIntEl = document.getElementById('adm-cron-interval');
    var retEl = document.getElementById('adm-sc-retention-days');
    var staleEl = document.getElementById('adm-sc-stale-days');
    var payload = {
        cron_interval_minutes: cronIntEl ? parseInt(cronIntEl.value, 10) : 15,
        retention_days: retEl ? parseInt(retEl.value, 10) : 14,
        stale_offline_days: staleEl ? parseInt(staleEl.value, 10) : 30
    };

    fetch('api.php?action=admin_save_cron_retention', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (!res.ok) {
            showToast('Fehler: ' + (res.error || 'Unbekannt'));
            return;
        }
        var msg = (payload.cron_interval_minutes > 0)
            ? ('✅ Cronjob (alle ' + payload.cron_interval_minutes + ' Min.) & Aufbewahrungsfrist (' + payload.retention_days + ' Tage) gespeichert!')
            : ('✅ Cronjob deaktiviert & Aufbewahrungsfrist (' + payload.retention_days + ' Tage) gespeichert!');
        showToast(msg);
        loadTopologyData();
    });
}

/**
 * Stößt die Datenbank-Bereinigung alter Protokolle und Offline-Geräte sofort manuell an.
 */
function runManualRetentionCleanup() {
    var retEl = document.getElementById('adm-sc-retention-days');
    var staleEl = document.getElementById('adm-sc-stale-days');
    var retDays = retEl ? parseInt(retEl.value, 10) : 14;
    var staleDays = staleEl ? parseInt(staleEl.value, 10) : 30;

    fetch('api.php?action=admin_run_retention_cleanup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            retention_days: retDays,
            stale_offline_days: staleDays
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (!res.ok) {
            showToast('Fehler: ' + (res.error || 'Unbekannt'));
            return;
        }
        var c = res.cleanup || {};
        showToast('🧹 Bereinigung abgeschlossen: ' + (c.deleted_logs || 0) + ' alte Scan-Logs, ' + (c.deleted_alerts || 0) + ' Alarme & ' + (c.deleted_devices || 0) + ' veraltete Offline-Clients entfernt.');
        loadTopologyData();
    });
}

/**
 * Kopiert einen Textwert (z.B. IP- oder MAC-Adresse) in die Zwischenablage und zeigt eine kurze Bestätigung.
 */
function copyToClipboard(text, label) {
    var str = (text === null || text === undefined) ? '' : String(text).trim();
    if (!str || str === '-') {
        showToast('Kein Wert zum Kopieren vorhanden.');
        return;
    }
    var lbl = label || 'Wert';
    var preview = str.length > 42 ? (str.substring(0, 42) + '…') : str;

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && window.isSecureContext) {
        navigator.clipboard.writeText(str).then(function () {
            showToast('📋 ' + lbl + ' kopiert: ' + preview);
        }).catch(function () {
            fallbackCopyText(str, lbl, preview);
        });
    } else {
        fallbackCopyText(str, lbl, preview);
    }
}

/**
 * Fallback-Methode zum Kopieren in die Zwischenablage für Browser ohne navigator.clipboard-Unterstützung.
 */
function fallbackCopyText(str, lbl, preview) {
    try {
        var ta = document.createElement('textarea');
        ta.value = str;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-9999px';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('📋 ' + lbl + ' kopiert: ' + preview);
    } catch (e) {
        showToast('Kopieren fehlgeschlagen.');
    }
}

/**
 * Erzeugt ein anklickbares HTML-Element, das seinen Wert beim Klick sofort in die Zwischenablage kopiert.
 */
function renderCopyableValue(val, label, extraStyle) {
    var str = (val === null || val === undefined) ? '' : String(val).trim();
    if (!str || str === '-' || str === 'Keine Standard-TCP-Ports offen') {
        return '<span style="' + (extraStyle || 'color:var(--text-secondary);') + '">' + escapeHtml(str || '-') + '</span>';
    }
    var safeJsVal = str.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\r?\n/g, ' ');
    var safeJsLbl = (label || 'Wert').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    return '<div style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;max-width:100%;">' +
        '<span style="word-break:break-word;' + (extraStyle || '') + '">' + escapeHtml(str) + '</span>' +
        '<button type="button" class="btn btn-sm" style="padding:1px 5px;font-size:10px;line-height:1.2;border-color:rgba(148,163,184,0.3);background:rgba(255,255,255,0.04);color:#cbd5e1;flex-shrink:0;" ' +
            'onclick="event.stopPropagation(); copyToClipboard(\'' + safeJsVal + '\', \'' + safeJsLbl + '\');" ' +
            'title="' + escapeHtml(label || 'Wert') + ' in Zwischenablage kopieren">📋</button>' +
    '</div>';
}

/**
 * Kopiert den kompletten Steckbrief des aktuell ausgewählten Geräts formatiert in die Zwischenablage.
 */
function copySelectedDeviceDetails() {
    if (!AppState.selectedDeviceId) {
        showToast('Kein Gerät ausgewählt.');
        return;
    }
    var dev = null;
    for (var i = 0; i < AppState.devices.length; i++) {
        if (parseInt(AppState.devices[i].id, 10) === parseInt(AppState.selectedDeviceId, 10)) {
            dev = AppState.devices[i];
            break;
        }
    }
    if (!dev) return;

    var displayName = dev.custom_name || dev.hostname || dev.snmp_sysname || dev.ip_address;
    var sp = getDeviceSwitchPortInfo(dev);
    var spStr = (sp && sp.switchId)
        ? (sp.switchName + ' (' + sp.switchIp + ') • Port ' + (sp.portName || '-') + (sp.vlan ? (' [VLAN ' + sp.vlan + ']') : ''))
        : 'Nicht zugeordnet';

    var summaryLines = [
        'Gerät:          ' + displayName,
        'IP-Adresse:     ' + (dev.ip_address || '-'),
        'MAC-Adresse:    ' + (dev.mac_address || '-'),
        'Hersteller:     ' + (dev.vendor || '-'),
        'Hostname:       ' + (dev.hostname || '-'),
        'Switch / Port:  ' + spStr,
        'Status:         ' + String(dev.status || '-').toUpperCase() + ' (' + (dev.latency_ms || '1.0') + ' ms)',
        'Betriebssystem: ' + (dev.os_guess || '-'),
        'Offene Ports:   ' + (dev.open_ports || '-'),
        'Scope:          ' + (dev.scope_name || '-')
    ];
    if (dev.notes) {
        summaryLines.push('Notizen:        ' + dev.notes);
    }
    copyToClipboard(summaryLines.join('\n'), 'Alle Gerätedaten (' + displayName + ')');
}

/**
 * Speichert die SMTP-Server-Einstellungen für Alarm- und 2FA-Benachrichtigungen.
 */
function saveAdminSmtpConfig() {
    var payload = {
        enabled: document.getElementById('adm-smtp-enabled').checked,
        host: document.getElementById('adm-smtp-host').value,
        port: document.getElementById('adm-smtp-port').value,
        encryption: document.getElementById('adm-smtp-enc').value,
        username: document.getElementById('adm-smtp-user').value,
        password: document.getElementById('adm-smtp-pass').value,
        from_email: document.getElementById('adm-smtp-from').value,
        from_name: document.getElementById('adm-smtp-fromname').value,
        to_email: document.getElementById('adm-smtp-to').value
    };

    fetch('api.php?action=save_smtp_config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            showToast('SMTP-Konfiguration für Alarm-Benachrichtigungen gespeichert!');
            loadTopologyData();
        } else {
            showToast('Fehler: ' + (res.error || 'Unbekannt'));
        }
    });
}

/**
 * Versendet eine Test-E-Mail über die eingegebenen SMTP-Zugangsdaten.
 */
function testSmtpConfig() {
    var box = document.getElementById('adm-smtp-test-result');
    box.style.display = 'block';
    box.style.background = 'rgba(59,130,246,0.15)';
    box.style.color = '#93c5fd';
    box.textContent = 'Verbinde mit SMTP-Server und sende Test-E-Mail...';

    var payload = {
        host: document.getElementById('adm-smtp-host').value,
        port: document.getElementById('adm-smtp-port').value,
        encryption: document.getElementById('adm-smtp-enc').value,
        username: document.getElementById('adm-smtp-user').value,
        password: document.getElementById('adm-smtp-pass').value,
        from_email: document.getElementById('adm-smtp-from').value,
        from_name: document.getElementById('adm-smtp-fromname').value,
        to_email: document.getElementById('adm-smtp-to').value
    };

    fetch('api.php?action=test_smtp', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            box.style.background = 'rgba(16,185,129,0.18)';
            box.style.color = '#34d399';
            box.textContent = '✓ Test-E-Mail erfolgreich über ' + res.info.host + ':' + res.info.port + ' versendet!';
        } else {
            box.style.background = 'rgba(239,68,68,0.18)';
            box.style.color = '#fca5a5';
            box.textContent = '✕ SMTP-Fehler: ' + (res.error || 'Versand fehlgeschlagen');
        }
    })
    .catch(function (err) {
        box.style.background = 'rgba(239,68,68,0.18)';
        box.style.color = '#fca5a5';
        box.textContent = '✕ Verbindungsfehler: ' + err.message;
    });
}

/**
 * Aktualisiert den roten Zähler-Badge für unquittierte Alarme in der oberen Navigationsleiste.
 */
function updateTopbarAlertsBadge() {
    var badge = document.getElementById('topbar-alert-badge');
    var btn = document.getElementById('btn-topbar-alerts');
    if (!badge || !btn) return;

    var cnt = AppState.unackAlerts || 0;
    if (cnt > 0) {
        badge.style.display = 'inline-block';
        badge.textContent = cnt;
        btn.style.borderColor = '#ef4444';
        btn.style.background = 'rgba(239,68,68,0.16)';
        if (cnt > AppState.lastToastAlertCount) {
            showToast('🔔 ' + cnt + ' unbestätigte(r) Geräte-Alarm(e) vorhanden!');
        }
    } else {
        badge.style.display = 'none';
        badge.textContent = '0';
        btn.style.borderColor = '';
        btn.style.background = '';
    }
    AppState.lastToastAlertCount = cnt;
}

/**
 * Öffnet das Alarm-Center (Statuswechsel, Sicherheits-Anomalien & MAC-Überwachungsliste).
 */
function openAlertsModal() {
    renderAlertsModal();
    document.getElementById('alerts-modal').classList.add('open');
}

/**
 * Öffnet das Alarm-Center und trägt eine MAC-Adresse direkt in das Formular der MAC-Überwachungsliste ein.
 */
function openAlertsModalWithWatchMac(prefillMac) {
    renderAlertsModal();
    document.getElementById('alerts-modal').classList.add('open');
    setTimeout(function () {
        var inp = document.getElementById('watch-mac-input');
        var lbl = document.getElementById('watch-mac-label');
        if (inp) {
            inp.value = prefillMac || '';
            if (lbl) lbl.focus();
        }
    }, 60);
}

/**
 * Speichert eine zu überwachende MAC-Adresse auf der MAC-Fahndungsliste.
 */
function saveMacWatchlistItem() {
    var macEl = document.getElementById('watch-mac-input');
    var lblEl = document.getElementById('watch-mac-label');
    var modeEl = document.getElementById('watch-mac-mode');
    if (!macEl || !macEl.value.trim()) {
        showToast('Bitte eine gültige MAC-Adresse eingeben (z.B. AA:BB:CC:DD:EE:FF).');
        return;
    }
    fetch('api.php?action=save_mac_watchlist', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            mac_address: macEl.value.trim(),
            label: lblEl ? lblEl.value.trim() : '',
            notify_mode: modeEl ? modeEl.value : 'always'
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            showToast('🎯 MAC-Überwachung für ' + res.mac_address + ' aktiviert!');
            loadTopologyData(function () {
                renderAlertsModal();
            });
        } else {
            showToast('Fehler: ' + (res.error || 'Unbekannt'));
        }
    });
}

/**
 * Löscht einen Eintrag von der MAC-Überwachungsliste.
 */
function deleteMacWatchlistItem(id) {
    fetch('api.php?action=delete_mac_watchlist', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            showToast('Eintrag aus der MAC-Überwachungsliste entfernt.');
            loadTopologyData(function () {
                renderAlertsModal();
            });
        }
    });
}

/**
 * Schließt das Alarm-Center.
 */
function closeAlertsModal() {
    document.getElementById('alerts-modal').classList.remove('open');
}

/**
 * Wechselt aus dem Alarm-Center direkt zu den Schwellenwert-Einstellungen des Anomalie-Wächters im Admin-Reiter.
 */
function jumpToAdminAnomalySettings() {
    closeAlertsModal();
    var admBtn = document.getElementById('nav-tab-admin');
    if (admBtn) {
        switchTab('admin', admBtn);
    }
    setTimeout(function () {
        var card = document.getElementById('admin-anomaly-card');
        if (card) {
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            card.style.boxShadow = '0 0 0 3px #f59e0b';
            setTimeout(function () { card.style.boxShadow = ''; }, 1800);
        }
        var inp = document.getElementById('adm-anom-maxmacs');
        if (inp) inp.focus();
    }, 80);
}

/**
 * Rendert die MAC-Überwachungsliste sowie die Tabelle aller Alarme inklusive Kommentar-Quittierungsfunktion.
 */
function renderAlertsModal() {
    var body = document.getElementById('alerts-modal-body');
    if (!body) return;

    var anomCfg = (AppState.system && AppState.system.anomaly_config) ? AppState.system.anomaly_config : {
        anomaly_arp_spoofing: 1,
        anomaly_new_mac: 1,
        anomaly_rogue_switch_max_macs: 3,
        anomaly_port_error_threshold: 50,
        anomaly_mac_flapping_threshold: 3
    };

    var arpOn = parseInt(anomCfg.anomaly_arp_spoofing, 10) === 1;
    var newMacOn = parseInt(anomCfg.anomaly_new_mac, 10) === 1;
    var maxMacs = parseInt(anomCfg.anomaly_rogue_switch_max_macs, 10) || 0;
    var flapCnt = parseInt(anomCfg.anomaly_mac_flapping_threshold, 10) || 0;
    var pErrCnt = parseInt(anomCfg.anomaly_port_error_threshold, 10) || 0;

    var html =
        '<div style="background:rgba(15,23,42,0.85);border:1px solid rgba(245,158,11,0.4);border-radius:8px;padding:12px;margin-bottom:10px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:8px;">' +
                '<div style="font-size:12px;font-weight:700;color:#fcd34d;text-transform:uppercase;">🛡️ Automatischer Anomalie- &amp; Sicherheits-Wächter (Layer 2 / Layer 3)</div>' +
                (userIsAdmin() ? '<button class="btn btn-sm" style="padding:2px 8px;font-size:11px;border-color:rgba(245,158,11,0.5);color:#fcd34d;" onclick="jumpToAdminAnomalySettings()">⚙️ Schwellenwerte anpassen</button>' : '') +
            '</div>' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap;font-size:11.5px;">' +
                '<span class="badge" style="padding:4px 8px;background:' + (arpOn ? 'rgba(16,185,129,0.18);border:1px solid #10b981;color:#34d399;' : 'rgba(148,163,184,0.15);color:#94a3b8;') + '">⚡ ARP-Spoofing / IP-Konflikt: ' + (arpOn ? 'AKTIV' : 'AUS') + '</span>' +
                '<span class="badge" style="padding:4px 8px;background:' + (newMacOn ? 'rgba(16,185,129,0.18);border:1px solid #10b981;color:#34d399;' : 'rgba(148,163,184,0.15);color:#94a3b8;') + '">🚨 Neue unbekannte MAC-Erkennung: ' + (newMacOn ? 'AKTIV' : 'AUS') + '</span>' +
                '<span class="badge" style="padding:4px 8px;background:' + (maxMacs > 0 ? 'rgba(16,185,129,0.18);border:1px solid #10b981;color:#34d399;' : 'rgba(148,163,184,0.15);color:#94a3b8;') + '">🔌 Rogue-Switch-Schutz: ' + (maxMacs > 0 ? ('>' + maxMacs + ' MACs/Port') : 'AUS') + '</span>' +
                '<span class="badge" style="padding:4px 8px;background:' + (flapCnt > 0 ? 'rgba(16,185,129,0.18);border:1px solid #10b981;color:#34d399;' : 'rgba(148,163,184,0.15);color:#94a3b8;') + '">🌪️ MAC-Flapping / Loop: ' + (flapCnt > 0 ? ('ab ' + flapCnt + '× Wechsel/h') : 'AUS') + '</span>' +
                '<span class="badge" style="padding:4px 8px;background:' + (pErrCnt > 0 ? 'rgba(16,185,129,0.18);border:1px solid #10b981;color:#34d399;' : 'rgba(148,163,184,0.15);color:#94a3b8;') + '">📉 Port-Paketfehler: ' + (pErrCnt > 0 ? ('ab ' + pErrCnt + ' Fehlern') : 'AUS') + '</span>' +
            '</div>' +
        '</div>';

    // Übersicht überwachter Geräte (Online / Offline Watchdog)
    var monitoredDevs = [];
    for (var i = 0; i < AppState.devices.length; i++) {
        var d = AppState.devices[i];
        if (d.alert_mode && d.alert_mode !== 'none') {
            monitoredDevs.push(d);
        }
    }

    html += '<div style="background:rgba(15,23,42,0.7);border:1px solid var(--border-color);border-radius:8px;padding:12px;">' +
        '<div style="font-size:12px;font-weight:700;color:#93c5fd;text-transform:uppercase;margin-bottom:8px;">Überwachte Geräte auf Online/Offline-Ausfall (' + monitoredDevs.length + ')</div>';

    if (monitoredDevs.length > 0) {
        html += '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
        for (var m = 0; m < monitoredDevs.length; m++) {
            var md = monitoredDevs[m];
            var mName = md.custom_name || md.hostname || md.ip_address;
            var stCol = (md.status === 'online') ? '#34d399' : '#ef4444';
            var modeLabel = (md.alert_mode === 'offline') ? 'Offline-Wächter' : ((md.alert_mode === 'online') ? 'Online-Wächter' : 'Beide Richtungen');
            html += '<div onclick="closeAlertsModal(); selectDevice(' + md.id + ');" style="cursor:pointer;background:var(--bg-panel);border:1px solid var(--border-color);border-radius:6px;padding:6px 10px;display:flex;align-items:center;gap:8px;font-size:12px;">' +
                '<span style="width:8px;height:8px;border-radius:50%;background:' + stCol + ';display:inline-block;"></span>' +
                '<b>' + escapeHtml(mName) + '</b>' +
                '<span style="font-family:monospace;color:#93c5fd;">(' + escapeHtml(md.ip_address) + ')</span>' +
                '<span class="badge" style="background:rgba(255,255,255,0.08);color:var(--text-secondary);">' + escapeHtml(modeLabel) + '</span>' +
            '</div>';
        }
        html += '</div>';
    } else {
        html += '<div style="font-size:12.5px;color:var(--text-secondary);">Noch keine Einzelgeräte für Online-/Offline-Ausfallalarme markiert. (Die 4 automatischen Anomalie-Wächter oben überwachen bereits das gesamte Netzwerk).</div>';
    }
    html += '</div>';

    // 🎯 Gezielte MAC-Überwachungsliste (Alarm sobald eine bestimmte, noch unbekannte MAC-Adresse im Netz auftaucht)
    var watchlist = AppState.macWatchlist || [];
    html += '<div style="background:rgba(15,23,42,0.8);border:1px solid rgba(59,130,246,0.45);border-radius:8px;padding:12px;margin-top:8px;">' +
        '<div style="font-size:12px;font-weight:700;color:#60a5fa;text-transform:uppercase;margin-bottom:4px;">🎯 Gezielte MAC-Überwachung / MAC-Fahndungsliste (' + watchlist.length + ')</div>' +
        '<div style="font-size:12px;color:var(--text-secondary);margin-bottom:10px;">' +
            'Hinterlege hier eine <b>bislang unbekannte oder noch nicht angesteckte MAC-Adresse</b>. Sobald diese MAC im ARP-Cache oder an irgendeinem Switch-Port auftaucht, wird sofort ein kritischer Alarm ausgelöst und (bei aktivem SMTP) eine E-Mail mit IP &amp; Switch-Port versendet:' +
        '</div>';

    if (userCanEdit()) {
        html += '<div style="display:grid;grid-template-columns:minmax(170px,1.2fr) minmax(180px,1.5fr) minmax(170px,1fr) auto;gap:8px;align-items:end;margin-bottom:10px;">' +
            '<div class="form-group" style="margin:0;">' +
                '<label>Gesuchte MAC-Adresse *</label>' +
                '<input type="text" id="watch-mac-input" class="form-input" placeholder="z.B. AA:BB:CC:DD:EE:FF" style="font-family:monospace;">' +
            '</div>' +
            '<div class="form-group" style="margin:0;">' +
                '<label>Bezeichnung / Hinweis</label>' +
                '<input type="text" id="watch-mac-label" class="form-input" placeholder="z.B. Vermisstes Notebook / Testgerät">' +
            '</div>' +
            '<div class="form-group" style="margin:0;">' +
                '<label>Alarm-Modus</label>' +
                '<select id="watch-mac-mode" class="select-input">' +
                    '<option value="always">Bei jedem Auftauchen melden</option>' +
                    '<option value="once">Nur 1× beim ersten Auftauchen</option>' +
                '</select>' +
            '</div>' +
            '<button class="btn btn-primary btn-sm" style="height:34px;padding:0 12px;" onclick="saveMacWatchlistItem()">🎯 MAC überwachen</button>' +
        '</div>';
    }

    if (watchlist.length > 0) {
        html += '<div style="display:flex;flex-direction:column;gap:6px;">';
        for (var w = 0; w < watchlist.length; w++) {
            var wi = watchlist[w];
            var wAct = parseInt(wi.is_active, 10) === 1;
            var wStatusBadge = wi.last_triggered_at
                ? '<span class="badge badge-online">🟢 ENTDECKT (' + escapeHtml(wi.last_triggered_at.substring(0, 16)) + ')</span>'
                : (wAct ? '<span class="badge" style="background:rgba(245,158,11,0.2);border:1px solid #f59e0b;color:#fcd34d;">⏳ Lauert auf Auftauchen...</span>' : '<span class="badge" style="background:rgba(148,163,184,0.2);color:#94a3b8;">Erledigt</span>');
            var locInfo = '';
            if (wi.last_seen_switch || wi.last_seen_ip) {
                locInfo = '<span style="font-size:11.5px;color:#34d399;margin-left:6px;">➔ ' +
                    (wi.last_seen_ip ? ('IP: <b>' + escapeHtml(wi.last_seen_ip) + '</b> ') : '') +
                    (wi.last_seen_switch ? ('an Switch <b>' + escapeHtml(wi.last_seen_switch) + '</b> Port <b>' + escapeHtml(wi.last_seen_port || '-') + '</b>') : '') +
                '</span>';
            }
            html += '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;padding:6px 10px;background:var(--bg-panel);border:1px solid var(--border-color);border-radius:6px;font-size:12px;">' +
                '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">' +
                    wStatusBadge +
                    renderCopyableValue(wi.mac_address, 'MAC-Adresse', 'font-family:monospace;font-weight:700;color:#93c5fd;') +
                    '<b>' + escapeHtml(wi.label || 'Gezielter MAC-Alarm') + '</b>' +
                    '<span style="color:var(--text-secondary);font-size:11px;">(' + (wi.notify_mode === 'once' ? 'Einmal-Alarm' : 'Dauer-Überwachung') + ')</span>' +
                    locInfo +
                '</div>' +
                (userCanEdit() ? '<button class="btn btn-sm btn-danger" style="padding:2px 7px;font-size:11px;" onclick="deleteMacWatchlistItem(' + parseInt(wi.id, 10) + ')" title="Aus Überwachung entfernen">✕</button>' : '') +
            '</div>';
        }
        html += '</div>';
    }
    html += '</div>';

    // Liste der ausgelösten Alarme & Anomalien
    var alerts = AppState.alerts || [];
    html += '<div style="font-size:12px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;margin-top:8px;">Ausgelöste Alarm- &amp; Anomalie-Ereignisse (' + alerts.length + ')</div>';

    if (alerts.length === 0) {
        html += '<div style="padding:24px;text-align:center;color:var(--text-secondary);background:rgba(15,23,42,0.4);border-radius:8px;border:1px solid var(--border-color);">Keine Alarme oder Netzwerk-Anomalien protokolliert – alle überwachten Systeme und Switch-Ports verhalten sich unauffällig.</div>';
    } else {
        var canAck = userCanEdit();
        html += '<table class="data-table"><thead><tr>' +
            (canAck ? '<th style="width:32px;text-align:center;"><input type="checkbox" id="alert-sel-all-cb" onclick="toggleSelectAllAlerts(this)" title="Alle offenen Alarme auswählen / abwählen"></th>' : '') +
            '<th>Zeitpunkt</th><th>Kategorie / Typ</th><th>Gerät / Switch</th><th>IP-Adresse</th><th>Details &amp; Steckplatz</th><th>Quittierung &amp; Kommentar</th></tr></thead><tbody>';
        for (var a = 0; a < alerts.length; a++) {
            var al = alerts[a];
            var alId = parseInt(al.id, 10);
            var aType = al.alert_type || al.event_type || 'offline_alarm';
            var isUnack = parseInt(al.is_acknowledged || 0, 10) === 0;
            var evBadge = '<span class="badge badge-offline">🔴 OFFLINE ALARM</span>';
            if (aType === 'online_recovery') {
                evBadge = '<span class="badge badge-online">🟢 WIEDER ONLINE</span>';
            } else if (aType === 'online_alarm') {
                evBadge = '<span class="badge" style="background:rgba(59,130,246,0.2);color:#93c5fd;">🔵 ONLINE ALARM</span>';
            } else if (aType === 'arp_conflict') {
                evBadge = '<span class="badge" style="background:rgba(239,68,68,0.25);border:1px solid #ef4444;color:#fca5a5;">⚡ ARP-SPOOFING / IP-KONFLIKT</span>';
            } else if (aType === 'new_mac') {
                evBadge = '<span class="badge" style="background:rgba(245,158,11,0.22);border:1px solid #f59e0b;color:#fcd34d;">🚨 NEUES GERÄT (MAC)</span>';
            } else if (aType === 'rogue_switch') {
                evBadge = '<span class="badge" style="background:rgba(168,85,247,0.25);border:1px solid #a855f7;color:#e9d5ff;">🔌 ROGUE-SWITCH / MULTI-MAC</span>';
            } else if (aType === 'mac_flapping') {
                evBadge = '<span class="badge" style="background:rgba(239,68,68,0.25);border:1px solid #ef4444;color:#fca5a5;">🌪️ MAC-FLAPPING / LOOP</span>';
            } else if (aType === 'port_errors') {
                evBadge = '<span class="badge" style="background:rgba(245,158,11,0.22);border:1px solid #f59e0b;color:#fcd34d;">📉 PORT-FEHLER</span>';
            } else if (aType === 'mac_watchlist') {
                evBadge = '<span class="badge" style="background:rgba(16,185,129,0.25);border:1px solid #10b981;color:#6ee7b7;">🎯 GESUCHTE MAC ENTDECKT</span>';
            }

            var devClickAttr = parseInt(al.device_id, 10) > 0
                ? 'onclick="closeAlertsModal(); selectDevice(' + parseInt(al.device_id, 10) + ');" style="cursor:pointer;color:#60a5fa;text-decoration:underline;" title="Gerät im Netzwerkplan öffnen"'
                : '';

            var ackCellHtml = '';
            if (!isUnack) {
                var ackMeta = 'Quittiert' + (al.ack_by ? (' von <b>' + escapeHtml(al.ack_by) + '</b>') : '') + (al.ack_at ? (' (' + escapeHtml(String(al.ack_at).substring(0, 16)) + ')') : '');
                ackCellHtml =
                    '<div style="display:flex;flex-direction:column;gap:4px;min-width:210px;">' +
                        '<div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">' +
                            '<span style="font-size:11px;color:#6ee7b7;">✅ ' + ackMeta + '</span>' +
                            (canAck ? '<button type="button" class="btn btn-sm" style="padding:1px 6px;font-size:10.5px;" onclick="toggleAlertCommentBox(' + alId + ')" title="Quittierungs-Kommentar bearbeiten">💬✎</button>' : '') +
                        '</div>' +
                        (al.ack_comment
                            ? '<div style="background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);border-radius:5px;padding:4px 8px;font-size:11.5px;color:#e2e8f0;">💬 ' + escapeHtml(al.ack_comment) + '</div>'
                            : '') +
                        (canAck
                            ? '<div id="alert-ack-box-' + alId + '" style="display:none;gap:4px;margin-top:2px;">' +
                                  '<input type="text" id="alert-ack-inp-' + alId + '" class="form-input" style="padding:4px 7px;font-size:11.5px;" placeholder="Kommentar zur Quittierung..." value="' + escapeHtml(al.ack_comment || '') + '" onkeydown="if(event.key===\'Enter\') ackSingleAlert(' + alId + ');">' +
                                  '<button type="button" class="btn btn-sm btn-primary" style="padding:3px 8px;font-size:11px;" onclick="ackSingleAlert(' + alId + ')">💾</button>' +
                              '</div>'
                            : '') +
                    '</div>';
            } else {
                if (canAck) {
                    ackCellHtml =
                        '<div style="display:flex;gap:4px;align-items:center;min-width:220px;">' +
                            '<input type="text" id="alert-ack-inp-' + alId + '" class="form-input" style="padding:4px 7px;font-size:11.5px;flex:1;" placeholder="Kommentar (optional)..." onkeydown="if(event.key===\'Enter\') ackSingleAlert(' + alId + ');">' +
                            '<button type="button" class="btn btn-sm btn-success" style="padding:4px 9px;font-size:11px;white-space:nowrap;" onclick="ackSingleAlert(' + alId + ')" title="Alarm mit Kommentar quittieren">✓ Quittieren</button>' +
                        '</div>';
                } else {
                    ackCellHtml = '<span class="badge badge-offline" style="font-size:10.5px;">Offen (Unquittiert)</span>';
                }
            }

            html += '<tr style="' + (isUnack ? 'background:rgba(239,68,68,0.08);font-weight:600;' : 'opacity:0.84;') + '">' +
                (canAck ? '<td style="text-align:center;"><input type="checkbox" class="alert-sel-cb" value="' + alId + '" data-unack="' + (isUnack ? '1' : '0') + '"></td>' : '') +
                '<td style="white-space:nowrap;font-size:12px;">' + escapeHtml(al.created_at || '-') + (isUnack ? ' <span class="badge badge-offline" style="font-size:9px;">NEU</span>' : '') + '</td>' +
                '<td style="white-space:nowrap;">' + evBadge + '</td>' +
                '<td><b ' + devClickAttr + '>' + escapeHtml(al.device_name || '-') + '</b></td>' +
                '<td style="font-family:monospace;color:#93c5fd;">' + escapeHtml(al.ip_address || '-') + '</td>' +
                '<td style="font-size:12px;">' + escapeHtml(al.message || '') + '</td>' +
                '<td style="font-weight:400;">' + ackCellHtml + '</td>' +
            '</tr>';
        }
        html += '</tbody></table>';
    }

    body.innerHTML = html;
}

/**
 * Blendet das Eingabefeld zum nachträglichen Bearbeiten eines Quittierungs-Kommentars ein oder aus.
 */
function toggleAlertCommentBox(alertId) {
    var box = document.getElementById('alert-ack-box-' + alertId);
    var inp = document.getElementById('alert-ack-inp-' + alertId);
    if (!box) return;
    box.style.display = (box.style.display === 'none') ? 'flex' : 'none';
    if (box.style.display === 'flex' && inp) {
        inp.focus();
    }
}

/**
 * Übernimmt die vom Backend zurückgelieferte aktualisierte Alarm-Liste sofort in den Frontend-Zustand.
 */
function applyAckAlertsResponse(res) {
    if (res && Array.isArray(res.alerts)) {
        AppState.alerts = res.alerts;
    }
    if (res && res.unack_alerts !== undefined) {
        AppState.unackAlerts = parseInt(res.unack_alerts || 0, 10);
    }
    updateTopbarAlertsBadge();
    renderAlertsModal();
    loadTopologyData(function () {
        renderAlertsModal();
    });
}

/**
 * Wählt alle Alarm-Checkboxen im Modal aus oder ab.
 */
function toggleSelectAllAlerts(masterCb) {
    var checked = !!(masterCb && masterCb.checked);
    var boxes = document.querySelectorAll('.alert-sel-cb');
    for (var i = 0; i < boxes.length; i++) {
        boxes[i].checked = checked;
    }
}

/**
 * Quittiert einen einzelnen Alarm inklusive optionalem Techniker-Kommentar.
 */
function ackSingleAlert(alertId) {
    if (!userCanEdit()) {
        showToast('Keine Berechtigung: Nur Operatoren, Netzwerkexperten und Administratoren dürfen Alarme quittieren.');
        return;
    }
    var inp = document.getElementById('alert-ack-inp-' + alertId);
    var allInp = document.getElementById('alert-ack-all-comment');
    var comment = inp ? inp.value.trim() : '';
    if (!comment && allInp && allInp.value.trim()) {
        comment = allInp.value.trim();
    }
    fetch('api.php?action=ack_alerts&_t=' + Date.now(), {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: parseInt(alertId, 10), comment: comment })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res && res.ok) {
            showToast('✅ Alarm quittiert' + (comment ? (' („' + comment + '“)') : '') + '.');
            applyAckAlertsResponse(res);
        } else {
            showToast('Fehler beim Quittieren: ' + ((res && res.error) ? res.error : 'Unbekannt'));
        }
    })
    .catch(function (err) {
        showToast('Netzwerkfehler beim Quittieren: ' + (err && err.message ? err.message : String(err)));
    });
}

/**
 * Quittiert alle per Checkbox ausgewählten Alarme gleichzeitig.
 */
function ackSelectedAlerts() {
    if (!userCanEdit()) {
        showToast('Keine Berechtigung: Nur Operatoren, Netzwerkexperten und Administratoren dürfen Alarme quittieren.');
        return;
    }
    var boxes = document.querySelectorAll('.alert-sel-cb:checked');
    var ids = [];
    for (var i = 0; i < boxes.length; i++) {
        var idVal = parseInt(boxes[i].value, 10);
        if (idVal > 0) ids.push(idVal);
    }
    if (ids.length === 0) {
        showToast('Bitte markiere zuerst mindestens einen Alarm per Checkbox (oder nutze „Alle offenen Alarme quittieren“).');
        return;
    }
    var allInp = document.getElementById('alert-ack-all-comment');
    var comment = allInp ? allInp.value.trim() : '';
    fetch('api.php?action=ack_alerts&_t=' + Date.now(), {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: ids, comment: comment })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res && res.ok) {
            if (allInp) allInp.value = '';
            showToast('✅ ' + ids.length + ' ausgewählte Alarme quittiert' + (comment ? (' („' + comment + '“)') : '') + '.');
            applyAckAlertsResponse(res);
        } else {
            showToast('Fehler beim Quittieren: ' + ((res && res.error) ? res.error : 'Unbekannt'));
        }
    })
    .catch(function (err) {
        showToast('Netzwerkfehler beim Quittieren: ' + (err && err.message ? err.message : String(err)));
    });
}

/**
 * Speichert die Schwellenwerte für den automatischen Anomalie-Wächter.
 */
function saveAdminAnomalyConfig() {
    var payload = {
        anomaly_arp_spoofing: document.getElementById('adm-anom-arp') && document.getElementById('adm-anom-arp').checked ? 1 : 0,
        anomaly_new_mac: document.getElementById('adm-anom-newmac') && document.getElementById('adm-anom-newmac').checked ? 1 : 0,
        anomaly_rogue_switch_max_macs: document.getElementById('adm-anom-maxmacs') ? parseInt(document.getElementById('adm-anom-maxmacs').value || '0', 10) : 3,
        anomaly_mac_flapping_threshold: document.getElementById('adm-anom-flap') ? parseInt(document.getElementById('adm-anom-flap').value || '0', 10) : 3,
        anomaly_port_error_threshold: document.getElementById('adm-anom-porterr') ? parseInt(document.getElementById('adm-anom-porterr').value || '0', 10) : 50
    };
    fetch('api.php?action=admin_save_anomaly_config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            showToast('🛡️ Einstellungen für den automatischen Anomalie-Wächter gespeichert!');
            loadTopologyData();
        } else {
            showToast('Fehler: ' + (res.error || 'Unbekannt'));
        }
    });
}

/**
 * Quittiert alle offenen Alarme gleichzeitig mit einem optionalen Sammel-Kommentar.
 */
function ackAllAlerts() {
    if (!userCanEdit()) {
        showToast('Keine Berechtigung: Nur Operatoren, Netzwerkexperten und Administratoren dürfen Alarme quittieren.');
        return;
    }
    var allInp = document.getElementById('alert-ack-all-comment');
    var comment = allInp ? allInp.value.trim() : '';
    fetch('api.php?action=ack_alerts&_t=' + Date.now(), {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ comment: comment })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res && res.ok) {
            if (allInp) allInp.value = '';
            showToast('✅ Alle offenen Alarme wurden quittiert' + (comment ? (' („' + comment + '“)') : '') + '.');
            applyAckAlertsResponse(res);
        } else {
            showToast('Fehler beim Quittieren: ' + ((res && res.error) ? res.error : 'Unbekannt'));
        }
    })
    .catch(function (err) {
        showToast('Netzwerkfehler beim Quittieren: ' + (err && err.message ? err.message : String(err)));
    });
}

/**
 * Löscht die gesamte Alarm-Historie nach Rückfrage.
 */
function clearAllAlerts() {
    if (!confirm('Gesamte Alarm-Historie wirklich löschen?')) return;
    fetch('api.php?action=clear_alerts&_t=' + Date.now(), {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res && res.ok) {
            AppState.alerts = [];
            AppState.unackAlerts = 0;
            updateTopbarAlertsBadge();
            renderAlertsModal();
            showToast('Alarm-Historie geleert.');
            loadTopologyData(function () {
                renderAlertsModal();
            });
        } else {
            showToast('Fehler: ' + ((res && res.error) ? res.error : 'Unbekannt'));
        }
    })
    .catch(function (err) {
        showToast('Netzwerkfehler: ' + (err && err.message ? err.message : String(err)));
    });
}

/**
 * Blendet das Feld für den Base32-Authenticator-Schlüssel ein, sobald 'totp' als 2FA-Methode gewählt wird.
 */
function toggleTotpBox(prefix) {
    var sel = document.getElementById(prefix + '-2fa-mode');
    var box = document.getElementById(prefix + '-totp-box');
    var secInp = document.getElementById(prefix + '-totp-secret');
    if (!sel || !box) return;
    if (sel.value === 'totp') {
        box.style.display = 'block';
        if (secInp && !secInp.value.trim()) {
            requestNewTotpSecret(prefix + '-totp-secret');
        }
    } else {
        box.style.display = 'none';
    }
}

/**
 * Fordert vom Server ein neues zufälliges TOTP-Secret an und trägt es in das Formularfeld ein.
 */
function requestNewTotpSecret(inputId) {
    fetch('api.php?action=generate_totp_secret', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok && res.totp_secret) {
            var el = document.getElementById(inputId);
            if (el) el.value = res.totp_secret;
        }
    });
}

/**
 * Öffnet den Dialog zum Anlegen oder Bearbeiten eines Benutzerkontos im Admin-Bereich.
 */
function openUserModal(userId) {
    var modal = document.getElementById('user-modal');
    var u = null;
    if (userId && AppState.admin && AppState.admin.users) {
        for (var i = 0; i < AppState.admin.users.length; i++) {
            if (parseInt(AppState.admin.users[i].id, 10) === parseInt(userId, 10)) {
                u = AppState.admin.users[i];
                break;
            }
        }
    }
    document.getElementById('usr-id').value = u ? u.id : 0;
    document.getElementById('usr-username').value = u ? u.username : '';
    document.getElementById('usr-fullname').value = u ? (u.full_name || '') : '';
    if (document.getElementById('usr-email')) {
        document.getElementById('usr-email').value = u ? (u.email || '') : '';
    }
    if (document.getElementById('usr-receive-alerts')) {
        document.getElementById('usr-receive-alerts').checked = u ? (parseInt(u.receive_alerts, 10) === 1) : true;
    }
    if (document.getElementById('usr-2fa-mode')) {
        document.getElementById('usr-2fa-mode').value = u ? (u.two_fa_mode || 'none') : 'none';
    }
    if (document.getElementById('usr-totp-secret')) {
        document.getElementById('usr-totp-secret').value = u ? (u.totp_secret || '') : '';
    }
    toggleTotpBox('usr');
    document.getElementById('usr-role').value = u ? u.role : 'viewer';
    document.getElementById('usr-password').value = '';
    document.getElementById('usr-active').value = u ? u.is_active : 1;
    modal.classList.add('open');
}

/**
 * Schließt den Benutzer-Dialog.
 */
function closeUserModal() {
    document.getElementById('user-modal').classList.remove('open');
}

/**
 * Speichert ein neues oder bearbeitetes Benutzerkonto (inkl. Rolle, E-Mail, Alarm-Empfang und 2FA-Modus).
 */
function saveUserForm() {
    var payload = {
        id: document.getElementById('usr-id').value,
        username: document.getElementById('usr-username').value,
        full_name: document.getElementById('usr-fullname').value,
        email: document.getElementById('usr-email') ? document.getElementById('usr-email').value.trim() : '',
        receive_alerts: (document.getElementById('usr-receive-alerts') && document.getElementById('usr-receive-alerts').checked) ? 1 : 0,
        two_fa_mode: document.getElementById('usr-2fa-mode') ? document.getElementById('usr-2fa-mode').value : 'none',
        totp_secret: document.getElementById('usr-totp-secret') ? document.getElementById('usr-totp-secret').value.trim() : '',
        role: document.getElementById('usr-role').value,
        password: document.getElementById('usr-password').value,
        is_active: document.getElementById('usr-active').value
    };
    fetch('api.php?action=save_user', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            closeUserModal();
            showToast('Benutzerkonto, E-Mail & 2FA gespeichert.');
            loadTopologyData();
        } else {
            showToast('Fehler: ' + res.error);
        }
    });
}

/**
 * Löscht ein Benutzerkonto nach Sicherheitsabfrage.
 */
function deleteUser(userId) {
    if (!confirm('Diesen Benutzer wirklich löschen?')) return;
    fetch('api.php?action=delete_user', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: userId })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            showToast('Benutzer gelöscht.');
            loadTopologyData();
        } else {
            showToast('Fehler: ' + res.error);
        }
    });
}

/**
 * Öffnet den Dialog 'Mein Konto: Passwort, E-Mail & 2FA' für den aktuell angemeldeten Benutzer.
 */
function openPasswordModal() {
    var cu = AppState.currentUser || {};
    if (document.getElementById('own-email')) {
        document.getElementById('own-email').value = cu.email || '';
    }
    if (document.getElementById('own-receive-alerts')) {
        document.getElementById('own-receive-alerts').checked = (cu.receive_alerts === undefined) ? true : (parseInt(cu.receive_alerts, 10) === 1);
    }
    if (document.getElementById('own-2fa-mode')) {
        document.getElementById('own-2fa-mode').value = cu.two_fa_mode || 'none';
    }
    if (document.getElementById('own-totp-secret')) {
        document.getElementById('own-totp-secret').value = cu.totp_secret || '';
    }
    toggleTotpBox('own');
    document.getElementById('own-new-password').value = '';
    document.getElementById('password-modal').classList.add('open');
}

/**
 * Schließt den 'Mein Konto'-Dialog.
 */
function closePasswordModal() {
    document.getElementById('password-modal').classList.remove('open');
}

/**
 * Speichert die persönlichen Kontoeinstellungen (E-Mail, Alarm-Benachrichtigung, 2FA und optional neues Passwort).
 */
function saveOwnPassword() {
    var payload = {
        email: document.getElementById('own-email') ? document.getElementById('own-email').value.trim() : '',
        receive_alerts: (document.getElementById('own-receive-alerts') && document.getElementById('own-receive-alerts').checked) ? 1 : 0,
        two_fa_mode: document.getElementById('own-2fa-mode') ? document.getElementById('own-2fa-mode').value : 'none',
        totp_secret: document.getElementById('own-totp-secret') ? document.getElementById('own-totp-secret').value.trim() : '',
        new_password: document.getElementById('own-new-password').value
    };
    fetch('api.php?action=change_own_password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            if (res.current_user) {
                AppState.currentUser = res.current_user;
            }
            closePasswordModal();
            showToast('✅ Dein Profil, E-Mail-Alarme & 2FA-Einstellungen wurden gespeichert!');
            loadTopologyData();
        } else {
            showToast('Fehler: ' + res.error);
        }
    });
}

/**
 * Maskiert HTML-Sonderzeichen (&, <, >, ", ') zum sicheren Schutz vor Cross-Site-Scripting (XSS).
 */
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Exportiert sämtliche Gerätedetails (bzw. Cluster-Details) des aktuell ausgewählten Knotens
 * per 1-Klick auf das Download-Symbol im schwebenden Detailfenster als übersichtliches Datenblatt (.txt)
 */
function exportSelectedDeviceDetails() {
    var nowStr = new Date().toLocaleString('de-DE');
    var lines = [];

    // Fall A: Ein Client-Cluster ist ausgewählt
    if (AppState.selectedClusterId && AppState.clusterMap[AppState.selectedClusterId]) {
        var cInfo = AppState.clusterMap[AppState.selectedClusterId];
        var pName = cInfo.parentDev ? (cInfo.parentDev.custom_name || cInfo.parentDev.hostname || cInfo.parentDev.ip_address) : 'Switch / Gateway';
        lines.push('====================================================================');
        lines.push('NETWORKMONITOR – CLIENT-CLUSTER DATENBLATT');
        lines.push('Exportiert am: ' + nowStr);
        lines.push('====================================================================');
        lines.push('Übergeordneter Verteiler: ' + pName + (cInfo.parentDev ? (' (' + cInfo.parentDev.ip_address + ')') : ''));
        lines.push('Gebündelte Endgeräte:     ' + cInfo.devices.length + ' (' + cInfo.onlineCount + ' Online, ' + cInfo.offlineCount + ' Offline)');
        lines.push('');
        lines.push('--- ENTHALTENE GERÄTE ----------------------------------------------');
        for (var ci = 0; ci < cInfo.devices.length; ci++) {
            var cd = cInfo.devices[ci];
            var cdName = cd.custom_name || cd.hostname || cd.ip_address;
            lines.push((ci + 1) + '. ' + cdName + ' | IP: ' + cd.ip_address + ' | MAC: ' + (cd.mac_address || '-') + ' | Status: ' + String(cd.status || '').toUpperCase() + ' | Hersteller: ' + (cd.vendor || '-') + ' | OS: ' + (cd.os_guess || '-'));
        }
        var cBlob = new Blob([lines.join('\r\n')], { type: 'text/plain;charset=utf-8' });
        var cLink = document.createElement('a');
        cLink.href = URL.createObjectURL(cBlob);
        cLink.download = 'cluster_details_' + pName.replace(/[^a-zA-Z0-9_-]/g, '_') + '.txt';
        cLink.click();
        showToast('Cluster-Details heruntergeladen.');
        return;
    }

    // Fall B: Ein einzelnes Gerät ist ausgewählt
    if (!AppState.selectedDeviceId) {
        showToast('Kein Gerät für den Export ausgewählt.');
        return;
    }

    var dev = null;
    var devById = {};
    for (var i = 0; i < AppState.devices.length; i++) {
        var d = AppState.devices[i];
        devById[d.id] = d;
        if (parseInt(d.id, 10) === parseInt(AppState.selectedDeviceId, 10)) {
            dev = d;
        }
    }
    if (!dev) return;

    var displayName = dev.custom_name || dev.hostname || dev.snmp_sysname || dev.ip_address;

    lines.push('====================================================================');
    lines.push('NETWORKMONITOR – GERÄTE-DATENBLATT');
    lines.push('Exportiert am: ' + nowStr);
    lines.push('====================================================================');
    lines.push('');
    lines.push('[1. ALLGEMEINE GERÄTE-INFORMATIONEN]');
    lines.push('Anzeigename:          ' + displayName);
    lines.push('Hostname (DNS/NBNS):  ' + (dev.hostname || '-'));
    lines.push('IP-Adresse:           ' + (dev.ip_address || '-'));
    lines.push('MAC-Adresse:          ' + (dev.mac_address || 'Nicht im lokalen ARP'));
    lines.push('Hersteller (OUI):     ' + (dev.vendor || 'Unbekannt'));
    lines.push('Gerätetyp:            ' + String(dev.device_type || '-').toUpperCase());
    lines.push('Eigene Gerätegruppe:  ' + (dev.custom_group || '-'));
    lines.push('Status:               ' + String(dev.status || '-').toUpperCase() + ' (' + (dev.latency_ms || '1.0') + ' ms)');
    lines.push('Alarm-Überwachung:    ' + (dev.alert_mode || 'none'));
    lines.push('Netzwerk-Scope:       ' + (dev.scope_name || '-'));
    lines.push('Standort / Notizen:   ' + (dev.notes || '-'));
    lines.push('Erstmals gesehen:     ' + (dev.first_seen || '-'));
    lines.push('Zuletzt gesehen:      ' + (dev.last_seen || '-'));
    lines.push('');
    lines.push('[2. BETRIEBSSYSTEM, DIENSTE & OFFENE PORTS]');
    lines.push('Betriebssystem (OS):  ' + (dev.os_guess || '-'));
    lines.push('Dienste / Banner:     ' + (dev.service_info || '-'));
    lines.push('Offene TCP-Ports:     ' + (dev.open_ports || 'Keine Standard-TCP-Ports offen'));
    lines.push('');
    lines.push('[3. TOPOLOGIE-ANBINDUNG & SWITCH-PORTS]');

    var upstreamLines = [];
    var downstreamLines = [];
    for (var l = 0; l < AppState.links.length; l++) {
        var lnk = AppState.links[l];
        if (parseInt(lnk.target_device_id, 10) === parseInt(dev.id, 10) && devById[lnk.source_device_id]) {
            var pDev = devById[lnk.source_device_id];
            var pNameStr = pDev.custom_name || pDev.hostname || pDev.ip_address;
            upstreamLines.push('Übergeordneter Verteiler: ' + pNameStr + ' (' + pDev.ip_address + ') an Port: ' + (lnk.source_port || 'Automatisch') + (lnk.target_port && lnk.target_port !== 'eth0' ? (' ⇄ Lokaler Port: ' + lnk.target_port) : '') + ' [' + (lnk.link_type || 'subnet') + ']');
        } else if (parseInt(lnk.source_device_id, 10) === parseInt(dev.id, 10) && devById[lnk.target_device_id]) {
            var cDev = devById[lnk.target_device_id];
            var cNameStr = cDev.custom_name || cDev.hostname || cDev.ip_address;
            downstreamLines.push('  • ' + (lnk.source_port ? ('[' + lnk.source_port + '] ') : '') + cNameStr + ' (' + cDev.ip_address + ' | MAC: ' + (cDev.mac_address || '-') + ' | Typ: ' + String(cDev.device_type || '').toUpperCase() + ')');
        }
    }

    if (upstreamLines.length > 0) {
        for (var u = 0; u < upstreamLines.length; u++) lines.push(upstreamLines[u]);
    } else {
        lines.push('Übergeordneter Verteiler: Root / Gateway-Ebene (kein Upstream-Verteiler)');
    }

    if (downstreamLines.length > 0) {
        lines.push('Direkt angeschlossene Geräte (' + downstreamLines.length + '):');
        for (var dn = 0; dn < downstreamLines.length; dn++) lines.push(downstreamLines[dn]);
    }

    if (parseInt(dev.snmp_enabled, 10) === 1 && dev.snmp_ports_json) {
        try {
            var snmp = JSON.parse(dev.snmp_ports_json) || {};
            lines.push('');
            lines.push('[4. SNMP v2c HARDWARE- & PORT-DETAILS]');
            lines.push('SNMP sysName:         ' + (snmp.sysName || dev.snmp_sysname || '-'));
            lines.push('Gerätemodell:         ' + (snmp.deviceModel || '-'));
            lines.push('Seriennummer:         ' + (snmp.serialNumber || '-'));
            lines.push('SNMP Uptime:          ' + (snmp.sysUpTime || '-'));
            lines.push('SNMP Standort:        ' + (snmp.sysLocation || dev.snmp_location || '-'));
            lines.push('SNMP sysDescr:        ' + (snmp.sysDescr || dev.snmp_sysdescr || '-'));

            if (snmp.printer) {
                lines.push('');
                lines.push('--- DRUCKER-STATUS (PRINTER-MIB) ---');
                lines.push('Gedruckte Seiten:     ' + (snmp.printer.page_count !== null ? snmp.printer.page_count : '-'));
                lines.push('Display-Meldung:      ' + (snmp.printer.console_display || '-'));
                if (snmp.printer.supplies && snmp.printer.supplies.length > 0) {
                    for (var sp = 0; sp < snmp.printer.supplies.length; sp++) {
                        var s = snmp.printer.supplies[sp];
                        lines.push('  • ' + s.name + ': ' + s.percent + '% (' + s.level + '/' + s.max + ')');
                    }
                }
            }

            if (snmp.interfaces && snmp.interfaces.length > 0) {
                lines.push('');
                lines.push('--- INTERFACES / SWITCH-PORTS (' + snmp.interfaces.length + ') ---');
                for (var idx = 0; idx < snmp.interfaces.length; idx++) {
                    var inf = snmp.interfaces[idx];
                    lines.push('  • #' + inf.ifIndex + ' ' + (inf.name || '-') + ' | Status: ' + String(inf.status || '').toUpperCase() + ' | Speed: ' + (inf.speed || '-') + ' | RX: ' + (inf.rx_mb || 0) + ' MB | TX: ' + (inf.tx_mb || 0) + ' MB | Fehler: ' + (inf.in_errors || 0) + (inf.alias ? (' | Alias: ' + inf.alias) : ''));
                }
            }
        } catch (e) {}
    }

    lines.push('');
    lines.push('====================================================================');

    var safeIp = String(dev.ip_address || 'geraet').replace(/[^0-9a-zA-Z._-]/g, '_');
    var safeName = String(displayName || '').replace(/[^0-9a-zA-Z_-]/g, '_').substring(0, 28);
    var fileName = 'geraetedetails_' + safeIp + (safeName && safeName !== safeIp ? ('_' + safeName) : '') + '.txt';

    var blob = new Blob([lines.join('\r\n')], { type: 'text/plain;charset=utf-8' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = fileName;
    link.click();
    showToast('Gerätedetails als "' + fileName + '" heruntergeladen!');
}

// ============================================================================
// FARBSCHEMA (THEME) & MEHRSPRACHIGKEIT (DE / EN / FR / ES)
// ============================================================================

var I18N_DICT = {
    de: {
        nav_map: 'Netzwerkplan',
        nav_inventory: 'Inventarliste',
        nav_admin: '⚙ Einstellungen',
        btn_actions_scan: 'Aktionen & Scan ▾',
        scan_all_scopes: 'Alle aktiven Scopes jetzt scannen',
        new_scope: 'Neuen Netzwerk-Scope anlegen',
        btn_start_scan: '▶ Scan starten',
        alerts: 'Alarme',
        theme_btn: 'Design',
        theme_header: 'Farbschema wählen',
        own_profile: '🔑 Eigenes Profil, E-Mail, Passwort & 2FA',
        admin_settings: '⚙ Einstellungen',
        logout: '⏻ Abmelden (Logout)',
        inv_search_ph: 'Schnellsuche (IP, MAC, Port, Dose)...',
        col_visible: 'Im Plan',
        col_status: 'Status',
        col_ip_address: 'IP-Adresse',
        col_name: 'Hostname / Bezeichnung',
        col_custom_group: 'Gerätegruppe',
        col_device_type: 'Typ',
        col_switch_port: 'Switch / Port (Steckplatz)',
        col_mac_address: 'MAC-Adresse',
        col_vendor: 'Hersteller (OUI)',
        col_os_guess: 'Betriebssystem & Dienste',
        col_open_ports: 'Offene TCP-Ports',
        col_snmp: 'SNMP Details',
        col_scope_name: 'Netzwerk-Scope',
        col_last_seen: 'Zuletzt gesehen'
    },
    en: {
        nav_map: 'Network Map',
        nav_inventory: 'Inventory List',
        nav_admin: '⚙ Settings',
        btn_actions_scan: 'Actions & Scan ▾',
        scan_all_scopes: 'Scan all active scopes now',
        new_scope: 'Create new network scope',
        btn_start_scan: '▶ Start Scan',
        alerts: 'Alerts',
        theme_btn: 'Theme',
        theme_header: 'Select Color Theme',
        own_profile: '🔑 My Profile, Email, Password & 2FA',
        admin_settings: '⚙ Settings',
        logout: '⏻ Sign Out (Logout)',
        inv_search_ph: 'Quick search (IP, MAC, Port, Jack)...',
        col_visible: 'In Map',
        col_status: 'Status',
        col_ip_address: 'IP Address',
        col_name: 'Hostname / Label',
        col_custom_group: 'Device Group',
        col_device_type: 'Type',
        col_switch_port: 'Switch / Port (Wall Jack)',
        col_mac_address: 'MAC Address',
        col_vendor: 'Vendor (OUI)',
        col_os_guess: 'OS & Services',
        col_open_ports: 'Open TCP Ports',
        col_snmp: 'SNMP Details',
        col_scope_name: 'Network Scope',
        col_last_seen: 'Last Seen'
    },
    fr: {
        nav_map: 'Plan Réseau',
        nav_inventory: 'Inventaire',
        nav_admin: '⚙ Paramètres',
        btn_actions_scan: 'Actions & Scan ▾',
        scan_all_scopes: 'Scanner tous les sous-réseaux actifs',
        new_scope: 'Nouveau sous-réseau (Scope)',
        btn_start_scan: '▶ Lancer Scan',
        alerts: 'Alertes',
        theme_btn: 'Thème',
        theme_header: 'Choisir le thème',
        own_profile: '🔑 Mon Profil, E-mail, Mot de passe & 2FA',
        admin_settings: '⚙ Paramètres',
        logout: '⏻ Déconnexion',
        inv_search_ph: 'Recherche rapide (IP, MAC, Port, Prise)...',
        col_visible: 'Affiché',
        col_status: 'Statut',
        col_ip_address: 'Adresse IP',
        col_name: 'Nom d’hôte / Libellé',
        col_custom_group: 'Groupe',
        col_device_type: 'Type',
        col_switch_port: 'Switch / Port (Prise)',
        col_mac_address: 'Adresse MAC',
        col_vendor: 'Fabricant (OUI)',
        col_os_guess: 'Système & Services',
        col_open_ports: 'Ports TCP ouverts',
        col_snmp: 'Détails SNMP',
        col_scope_name: 'Sous-réseau',
        col_last_seen: 'Vu le'
    },
    es: {
        nav_map: 'Mapa de Red',
        nav_inventory: 'Inventario',
        nav_admin: '⚙ Ajustes',
        btn_actions_scan: 'Acciones y Escaneo ▾',
        scan_all_scopes: 'Escanear todas las subredes activas',
        new_scope: 'Crear nueva subred (Scope)',
        btn_start_scan: '▶ Iniciar Escaneo',
        alerts: 'Alertas',
        theme_btn: 'Tema',
        theme_header: 'Seleccionar esquema de color',
        own_profile: '🔑 Mi Perfil, Correo, Contraseña y 2FA',
        admin_settings: '⚙ Ajustes',
        logout: '⏻ Cerrar sesión',
        inv_search_ph: 'Búsqueda rápida (IP, MAC, Puerto, Roseta)...',
        col_visible: 'En Mapa',
        col_status: 'Estado',
        col_ip_address: 'Dirección IP',
        col_name: 'Nombre de host',
        col_custom_group: 'Grupo',
        col_device_type: 'Tipo',
        col_switch_port: 'Switch / Puerto (Roseta)',
        col_mac_address: 'Dirección MAC',
        col_vendor: 'Fabricante (OUI)',
        col_os_guess: 'Sistema y Servicios',
        col_open_ports: 'Puertos TCP',
        col_snmp: 'Detalles SNMP',
        col_scope_name: 'Subred (Scope)',
        col_last_seen: 'Última vez'
    }
};

/**
 * Wechselt das aktive Farbschema (Midnight, Emerald, Cyberpunk, Amber, Daylight) und speichert es im localStorage.
 */
function setAppTheme(themeName, silent) {
    var t = themeName || 'midnight';
    document.documentElement.setAttribute('data-theme', t);
    try {
        localStorage.setItem('nm_theme', t);
    } catch (e) {}
    if (!silent) {
        var names = {
            midnight: 'Midnight Slate',
            emerald: 'Cyber Emerald',
            cyberpunk: 'Deep Violet & Cyan',
            amber: 'Amber Industrial',
            light: 'Daylight Clean'
        };
        showToast('🎨 Farbschema aktiviert: ' + (names[t] || t));
    }
}

/**
 * Wechselt die Oberflächensprache (DE, EN, FR, ES), übersetzt alle UI-Elemente und speichert die Wahl im localStorage.
 */
function setAppLanguage(langCode, silent) {
    var lang = (I18N_DICT[langCode]) ? langCode : 'de';
    AppState.lang = lang;
    try {
        localStorage.setItem('nm_lang', lang);
    } catch (e) {}

    var d = I18N_DICT[lang];
    var codeEl = document.getElementById('topbar-lang-code');
    if (codeEl) codeEl.textContent = lang.toUpperCase();

    var mapIds = {
        'nav-tab-map': d.nav_map,
        'nav-tab-inventory': d.nav_inventory,
        'nav-tab-admin': d.nav_admin,
        'btn-scan-actions-dd': d.btn_actions_scan,
        'lbl-scan-all-scopes': d.scan_all_scopes,
        'lbl-new-scope': d.new_scope,
        'btn-start-scan': d.btn_start_scan,
        'topbar-alert-label': d.alerts,
        'topbar-theme-label': d.theme_btn,
        'lbl-theme-header': d.theme_header,
        'menu-own-profile': d.own_profile,
        'menu-admin-settings': d.admin_settings,
        'menu-logout': d.logout
    };
    var idKeys = Object.keys(mapIds);
    for (var i = 0; i < idKeys.length; i++) {
        var el = document.getElementById(idKeys[i]);
        if (el) el.textContent = mapIds[idKeys[i]];
    }

    var invSearch = document.getElementById('inv-search');
    if (invSearch) invSearch.placeholder = d.inv_search_ph;

    for (var c = 0; c < INV_COLUMNS.length; c++) {
        var ck = INV_COLUMNS[c].key;
        if (d['col_' + ck]) {
            INV_COLUMNS[c].label = d['col_' + ck];
        }
    }
    if (AppState.devices && AppState.devices.length > 0) {
        renderInventoryTable();
    }
    if (!silent) {
        showToast('🌐 Sprache / Language: ' + lang.toUpperCase());
    }
}

document.addEventListener('DOMContentLoaded', function () {
    initFloatingInspectorDrag();
    try {
        var savedTheme = localStorage.getItem('nm_theme');
        if (savedTheme) setAppTheme(savedTheme, true);
        var savedLang = localStorage.getItem('nm_lang');
        if (savedLang) setAppLanguage(savedLang, true);
    } catch (e) {}
    document.addEventListener('click', function () {
        closeAllDropdowns();
        closeInvColumnFilterPopover();
    });
    loadTopologyData(function () {
        if (AppState.devices.length > 0) {
            fitNetworkGraph();
        }
    });
});
