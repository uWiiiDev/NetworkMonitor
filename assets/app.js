/**
 * NetworkMonitor - Frontend Application Logic
 * Unterstützt 6 Ansichten & das Ein-/Ausblenden einzelner Geräte sowie ganzer Gerätegruppen
 */

var AppState = {
    scopes: [],
    devices: [],
    links: [],
    logs: [],
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

// Erzeugt gestochen scharfe SVG-Icons für jeden Gerätetyp im Netzwerkplan
function createDeviceSvgUri(type, status, scopeColor) {
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
        glyph = '<rect x="12" y="22" width="40" height="20" rx="3" fill="none" stroke="' + iconColor + '" stroke-width="2.5"/>' +
                '<circle cx="19" cy="32" r="2" fill="#34d399"/><circle cx="26" cy="32" r="2" fill="#34d399"/>' +
                '<circle cx="33" cy="32" r="2" fill="#34d399"/><circle cx="40" cy="32" r="2" fill="#34d399"/>' +
                '<path d="M45 29l4 3-4 3" fill="none" stroke="' + iconColor + '" stroke-width="2"/>';
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

function showToast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function () {
        t.classList.remove('show');
    }, 4000);
}

function userCanEdit() {
    var u = AppState.currentUser || window.CURRENT_USER || {};
    return (u.role === 'operator' || u.role === 'admin');
}

function userIsAdmin() {
    var u = AppState.currentUser || window.CURRENT_USER || {};
    return (u.role === 'admin');
}

function loadTopologyData(callback) {
    fetch('api.php?action=topology')
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
            AppState.system = data.system || {};
            AppState.admin = data.admin || null;

            // Initialisiere benutzerdefinierte Gruppen
            for (var i = 0; i < AppState.devices.length; i++) {
                var cg = AppState.devices[i].custom_group;
                if (cg && AppState.activeCustomGroups[cg] === undefined) {
                    AppState.activeCustomGroups[cg] = true;
                }
            }

            renderScopesList();
            renderGroupFilterBar();
            renderCurrentView();
            renderInventoryTable();
            renderSystemInfo();
            if (userIsAdmin() && AppState.admin) {
                renderAdminSection();
            }
            if (AppState.selectedDeviceId) {
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

function closeAllDropdowns() {
    var menus = document.querySelectorAll('.dropdown-menu.open');
    for (var i = 0; i < menus.length; i++) {
        menus[i].classList.remove('open');
    }
}

function toggleLeftSidebar() {
    var sb = document.getElementById('sidebar-left');
    if (sb) {
        sb.classList.toggle('collapsed');
        setTimeout(function () { fitNetworkGraph(); }, 100);
    }
}

function toggleRightSidebar(forceOpen) {
    var sb = document.getElementById('sidebar-right');
    if (!sb) return;
    if (forceOpen === true) {
        sb.classList.remove('collapsed');
    } else if (forceOpen === false) {
        sb.classList.add('collapsed');
    } else {
        sb.classList.toggle('collapsed');
    }
}

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

function toggleTypeGroup(typeKey) {
    AppState.activeTypes[typeKey] = !AppState.activeTypes[typeKey];
    renderGroupFilterBar();
    renderCurrentView();
}

function toggleCustomGroup(groupName) {
    if (AppState.activeCustomGroups[groupName] === undefined) {
        AppState.activeCustomGroups[groupName] = false;
    } else {
        AppState.activeCustomGroups[groupName] = !AppState.activeCustomGroups[groupName];
    }
    renderGroupFilterBar();
    renderCurrentView();
}

function changeMapViewMode(mode) {
    AppState.viewMode = mode;
    renderCurrentView();
}

/**
 * Filtert Geräte gemäß aktuellem Scope, Gruppen-Filter, Einzelsichtbarkeit und Suchtext
 */
function getFilteredDevicesAndLinks() {
    var searchVal = (document.getElementById('map-search').value || '').toLowerCase();
    var showHiddenElem = document.getElementById('chk-show-hidden');
    var showHidden = showHiddenElem ? showHiddenElem.checked : false;

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

        if (isHidden && !showHidden) {
            continue;
        }
        if (AppState.activeTypes[d.device_type] === false) {
            continue;
        }
        if (d.custom_group && AppState.activeCustomGroups[d.custom_group] === false) {
            continue;
        }
        if (searchVal !== '') {
            var hay = ((d.ip_address || '') + ' ' + (d.hostname || '') + ' ' + (d.custom_name || '') + ' ' + (d.custom_group || '') + ' ' + (d.mac_address || '') + ' ' + (d.vendor || '')).toLowerCase();
            if (hay.indexOf(searchVal) === -1) {
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
    var btnPng   = document.getElementById('btn-export-png');

    if (AppState.viewMode === 'matrix') {
        canvasEl.style.display = 'none';
        rackEl.style.display = 'none';
        matrixEl.style.display = 'flex';
        if (btnFit) btnFit.style.display = 'none';
        if (btnPng) btnPng.style.display = 'none';
        renderMatrixView();
    } else if (AppState.viewMode === 'rack') {
        canvasEl.style.display = 'none';
        matrixEl.style.display = 'none';
        rackEl.style.display = 'flex';
        if (btnFit) btnFit.style.display = 'none';
        if (btnPng) btnPng.style.display = 'none';
        renderRackPanelView();
    } else {
        matrixEl.style.display = 'none';
        rackEl.style.display = 'none';
        canvasEl.style.display = 'block';
        if (btnFit) btnFit.style.display = 'inline-flex';
        if (btnPng) btnPng.style.display = 'inline-flex';
        renderNetworkGraph();
    }
}

/**
 * Ansichten 1 bis 4: Interaktiver Topologie-Graph (Stern, Baum Oben->Unten, Baum Links->Rechts, Kreis/Radial)
 */
function renderNetworkGraph() {
    var filtered = getFilteredDevicesAndLinks();
    var filteredDevices = filtered.devices;
    var allowedIds = filtered.allowedIds;

    var nodes = [];
    var routersByScope = {};

    // Für Kreis-/Radial-Layout Koordinaten berechnen
    var totalDevs = filteredDevices.length;
    var centerIndex = 0;
    var outerIndex = 0;
    var outerCount = 0;
    for (var m = 0; m < totalDevs; m++) {
        if (filteredDevices[m].device_type !== 'router' && filteredDevices[m].device_type !== 'switch') {
            outerCount++;
        }
    }

    for (var j = 0; j < filteredDevices.length; j++) {
        var dev = filteredDevices[j];
        var devHidden = parseInt(dev.is_hidden || 0, 10) === 1;
        var titleName = dev.custom_name || dev.hostname || dev.snmp_sysname || dev.ip_address;
        if (titleName.length > 24) {
            titleName = titleName.substring(0, 22) + '…';
        }
        var label = titleName + '\n' + dev.ip_address;
        if (dev.custom_group) {
            label += '\n[' + dev.custom_group + ']';
        }
        if (devHidden) {
            label += ' (ausgeblendet)';
        }

        var level = 3;
        if (dev.device_type === 'router') {
            level = 1;
            routersByScope[dev.scope_id] = dev.id;
        } else if (dev.device_type === 'switch') {
            level = 2;
        }

        var nodeObj = {
            id: parseInt(dev.id, 10),
            label: label,
            image: createDeviceSvgUri(dev.device_type, dev.status, dev.scope_color),
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
        }

        nodes.push(nodeObj);
    }

    var edges = [];
    for (var k = 0; k < AppState.links.length; k++) {
        var lnk = AppState.links[k];
        if (!allowedIds[lnk.source_device_id] || !allowedIds[lnk.target_device_id]) {
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

        edges.push({
            id: 'e_' + lnk.id,
            from: parseInt(lnk.source_device_id, 10),
            to: parseInt(lnk.target_device_id, 10),
            label: lnk.source_port || '',
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

    var options = {
        interaction: {
            hover: true,
            keyboard: true
        },
        edges: {
            smooth: isTree ? {
                type: 'cubicBezier',
                forceDirection: (treeDir === 'LR') ? 'horizontal' : 'vertical',
                roundness: 0.45
            } : {
                type: 'continuous',
                roundness: 0.2
            }
        },
        layout: isTree ? {
            hierarchical: {
                enabled: true,
                direction: treeDir,
                sortMethod: 'directed',
                levelSeparation: (treeDir === 'LR') ? 220 : 150,
                nodeSpacing: (treeDir === 'LR') ? 100 : 150
            }
        } : {
            hierarchical: false
        },
        physics: (isTree || AppState.viewMode === 'radial') ? {
            enabled: false
        } : {
            enabled: true,
            barnesHut: {
                gravitationalConstant: -4200,
                centralGravity: 0.25,
                springLength: 145,
                springConstant: 0.04,
                damping: 0.12
            },
            stabilization: { iterations: 120 }
        }
    };

    var container = document.getElementById('network-canvas');
    if (AppState.network) {
        AppState.network.destroy();
    }
    AppState.nodesDataSet = new vis.DataSet(nodes);
    AppState.edgesDataSet = new vis.DataSet(edges);
    AppState.network = new vis.Network(container, {
        nodes: AppState.nodesDataSet,
        edges: AppState.edgesDataSet
    }, options);

    AppState.network.on('click', function (params) {
        if (params.nodes && params.nodes.length > 0) {
            selectDevice(params.nodes[0]);
        }
    });
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

    var html = '';
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
                    '<div style="font-size:12.5px;color:#34d399;font-weight:600;">' +
                        connectedLinks.length + ' belegte Ports' +
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

function selectDevice(deviceId) {
    AppState.selectedDeviceId = deviceId;
    toggleRightSidebar(true);
    var dev = null;
    for (var i = 0; i < AppState.devices.length; i++) {
        if (parseInt(AppState.devices[i].id, 10) === parseInt(deviceId, 10)) {
            dev = AppState.devices[i];
            break;
        }
    }
    var panel = document.getElementById('inspector-content');
    if (!dev) {
        panel.innerHTML = '<p style="color:var(--text-secondary);font-size:13px;">Klicke auf ein Gerät, um Details, offene Ports, SNMP-Switch-Daten, Gruppen und Port-Zuordnungen anzuzeigen.</p>';
        return;
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
    if (parseInt(dev.snmp_enabled, 10) === 1) {
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
            '<div style="display:flex;justify-content:space-between;align-items:center;">' +
                '<span class="badge badge-snmp">SNMP v2c Daten verfügbar</span>' +
                '<button class="btn btn-sm btn-primary" onclick="openSnmpModal(' + dev.id + ')">🔍 Alle SNMP-Daten öffnen</button>' +
            '</div>' +
            '<div class="kv-grid" style="margin-top:2px;">' +
                '<div class="kv-label">sysName</div><div class="kv-val">' + escapeHtml(dev.snmp_sysname || '-') + '</div>' +
                (snmpDetails.deviceModel ? ('<div class="kv-label">Modell</div><div class="kv-val">' + escapeHtml(snmpDetails.deviceModel) + '</div>') : '') +
                (snmpDetails.serialNumber ? ('<div class="kv-label">Seriennummer</div><div class="kv-val" style="font-family:monospace;">' + escapeHtml(snmpDetails.serialNumber) + '</div>') : '') +
                (snmpDetails.sysUpTime ? ('<div class="kv-label">SNMP Uptime</div><div class="kv-val" style="color:#34d399;">' + escapeHtml(snmpDetails.sysUpTime) + '</div>') : '') +
                (dev.snmp_location ? ('<div class="kv-label">Standort</div><div class="kv-val">' + escapeHtml(dev.snmp_location) + '</div>') : '') +
                '<div class="kv-label">Interfaces</div><div class="kv-val">' + ifCount + ' Ports / ' + macCount + ' gelernte MACs</div>' +
                printerMiniHtml +
            '</div>' +
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

    var editFormHtml = '';
    if (userCanEdit()) {
        editFormHtml =
            '<div style="border-top:1px solid var(--border-color);padding-top:12px;display:flex;flex-direction:column;gap:10px;">' +
                '<div class="panel-title">Gerät, Gruppe &amp; Switch-Port anpassen</div>' +
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
                'Angemeldet als <b>Betrachter (Nur Lesen)</b>. Zum Bearbeiten von Geräten ist die Rolle <i>Operator</i> oder <i>Administrator</i> erforderlich.' +
            '</div>';
    }

    panel.innerHTML =
        '<div class="kv-grid">' +
            '<div class="kv-label">Status</div><div class="kv-val"><span class="badge badge-' + dev.status + '">' + dev.status.toUpperCase() + '</span> (' + (dev.latency_ms || '1.0') + ' ms)</div>' +
            '<div class="kv-label">IP-Adresse</div><div class="kv-val">' + escapeHtml(dev.ip_address) + '</div>' +
            '<div class="kv-label">MAC-Adresse</div><div class="kv-val">' + escapeHtml(dev.mac_address || 'Nicht im lokalen ARP') + '</div>' +
            '<div class="kv-label">Hersteller</div><div class="kv-val">' + escapeHtml(dev.vendor || 'Unbekannt') + '</div>' +
            '<div class="kv-label">Hostname</div><div class="kv-val">' + escapeHtml(dev.hostname || '-') + '</div>' +
            '<div class="kv-label">Betriebssystem</div><div class="kv-val" style="color:#a7f3d0;font-weight:600;">' + escapeHtml(dev.os_guess || '-') + '</div>' +
            '<div class="kv-label">Dienste / Banner</div><div class="kv-val" style="font-size:11.5px;color:#93c5fd;">' + escapeHtml(dev.service_info || '-') + '</div>' +
            '<div class="kv-label">Offene Ports</div><div class="kv-val">' + escapeHtml(dev.open_ports || 'Keine Standard-TCP-Ports offen') + '</div>' +
            '<div class="kv-label">Scope</div><div class="kv-val">' + escapeHtml(dev.scope_name || '-') + '</div>' +
        '</div>' +
        snmpHtml +
        editFormHtml;
}

function saveDeviceChanges(devId) {
    var payload = {
        id: devId,
        custom_name: document.getElementById('edit-dev-name').value,
        custom_group: document.getElementById('edit-dev-group').value,
        device_type: document.getElementById('edit-dev-type').value,
        parent_device_id: document.getElementById('edit-dev-parent').value,
        parent_port: document.getElementById('edit-dev-port').value,
        notes: document.getElementById('edit-dev-notes').value
    };
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

function openVisibilityModal() {
    var modal = document.getElementById('visibility-modal');
    document.getElementById('vis-search').value = '';
    renderVisibilityChecklist();
    modal.classList.add('open');
}

function closeVisibilityModal() {
    document.getElementById('visibility-modal').classList.remove('open');
}

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

function filterVisibilityChecklist() {
    var search = (document.getElementById('vis-search').value || '').toLowerCase();
    var rows = document.querySelectorAll('#visibility-checklist .checklist-row');
    for (var i = 0; i < rows.length; i++) {
        var hay = rows[i].getAttribute('data-hay') || '';
        rows[i].style.display = (search === '' || hay.indexOf(search) !== -1) ? 'flex' : 'none';
    }
}

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

function closeScopeModal() {
    document.getElementById('scope-modal').classList.remove('open');
}

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
    { key: 'visible',     label: 'Im Plan',                   defaultShow: true },
    { key: 'status',      label: 'Status',                    defaultShow: true },
    { key: 'ip_address',  label: 'IP-Adresse',                defaultShow: true },
    { key: 'name',        label: 'Hostname / Bezeichnung',    defaultShow: true },
    { key: 'device_type', label: 'Typ',                       defaultShow: true },
    { key: 'os_guess',    label: 'Betriebssystem & Dienste',  defaultShow: true },
    { key: 'mac_address', label: 'MAC-Adresse',               defaultShow: true },
    { key: 'vendor',      label: 'Hersteller (OUI)',          defaultShow: true },
    { key: 'open_ports',  label: 'Offene TCP-Ports',          defaultShow: true },
    { key: 'snmp',        label: 'SNMP Details',              defaultShow: true },
    { key: 'scope_name',  label: 'Netzwerk-Scope',            defaultShow: true },
    { key: 'last_seen',   label: 'Zuletzt gesehen',           defaultShow: false }
];

var InvTableState = {
    sortKey: 'ip_address',
    sortDir: 'asc',
    hiddenCols: {}
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

function ipToNumeric(ipStr) {
    if (!ipStr) return 0;
    var p = String(ipStr).split('.');
    if (p.length !== 4) return 0;
    return ((parseInt(p[0], 10) || 0) * 16777216) +
           ((parseInt(p[1], 10) || 0) * 65536) +
           ((parseInt(p[2], 10) || 0) * 256) +
           (parseInt(p[3], 10) || 0);
}

function sortInventoryBy(colKey) {
    if (InvTableState.sortKey === colKey) {
        InvTableState.sortDir = (InvTableState.sortDir === 'asc') ? 'desc' : 'asc';
    } else {
        InvTableState.sortKey = colKey;
        InvTableState.sortDir = 'asc';
    }
    renderInventoryTable();
}

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

function resetInventoryColumns() {
    InvTableState.hiddenCols = {};
    try {
        localStorage.setItem('nm_inv_hidden_cols', JSON.stringify(InvTableState.hiddenCols));
    } catch (e) {}
    renderInventoryTable();
}

function renderInventoryTable() {
    var thead = document.getElementById('inventory-thead');
    var tbody = document.getElementById('inventory-tbody');
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

    // 2. Sortierbare Tabellen-Kopfzeile (<thead>) rendern
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
        thHtml += '<th style="cursor:pointer;user-select:none;white-space:nowrap;" onclick="sortInventoryBy(\'' + hc.key + '\')" title="Klicken zum Sortieren nach ' + escapeHtml(hc.label) + '">' +
            escapeHtml(hc.label) + arrow +
        '</th>';
    }
    thHtml += '</tr>';
    if (thead) thead.innerHTML = thHtml;

    // 3. Geräte filtern & nach gewählter Spalte sortieren
    var rowsData = [];
    for (var i = 0; i < AppState.devices.length; i++) {
        var d = AppState.devices[i];
        if (AppState.selectedScopeId !== 'all' && String(d.scope_id) !== String(AppState.selectedScopeId)) {
            continue;
        }
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
                (d.custom_group || '')
            ).toLowerCase();
            if (hay.indexOf(search) === -1) continue;
        }
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
        var va = '';
        var vb = '';
        if (sk === 'name') {
            va = (a.custom_name || a.hostname || '').toLowerCase();
            vb = (b.custom_name || b.hostname || '').toLowerCase();
        } else if (sk === 'os_guess') {
            va = ((a.os_guess || '') + ' ' + (a.service_info || '')).toLowerCase();
            vb = ((b.os_guess || '') + ' ' + (b.service_info || '')).toLowerCase();
        } else {
            va = String(a[sk] || '').toLowerCase();
            vb = String(b[sk] || '').toLowerCase();
        }
        if (va < vb) return -1 * dir;
        if (va > vb) return 1 * dir;
        return (ipToNumeric(a.ip_address) - ipToNumeric(b.ip_address));
    });

    // 4. Tabellenzeilen rendern
    var html = '';
    for (var r = 0; r < rowsData.length; r++) {
        var dev = rowsData[r];
        var isVisible = parseInt(dev.is_hidden || 0, 10) === 0;
        var visChk = '<input type="checkbox" ' + (isVisible ? 'checked' : '') + (userCanEdit() ? '' : ' disabled') +
                     ' onchange="toggleSingleDeviceHidden(' + dev.id + ', this.checked ? 0 : 1)" title="Im Netzwerkplan anzeigen">';
        var grpInfo = dev.custom_group ? (' <span class="badge" style="background:rgba(245,158,11,0.2);color:#fcd34d;">' + escapeHtml(dev.custom_group) + '</span>') : '';
        var snmpBadge = parseInt(dev.snmp_enabled, 10) === 1
            ? '<button class="btn btn-sm" style="background:rgba(16,185,129,0.16);border-color:#10b981;color:#34d399;padding:2px 8px;font-size:11px;" onclick="event.stopPropagation(); openSnmpModal(' + dev.id + ');">🔍 SNMP (' + escapeHtml(dev.snmp_sysname || 'Details') + ')</button>'
            : '-';

        var osAndBanner = '<div style="font-weight:600;color:#a7f3d0;">' + escapeHtml(dev.os_guess || '-') + '</div>' +
            (dev.service_info ? ('<div style="font-size:11px;color:var(--text-secondary);margin-top:2px;">' + escapeHtml(dev.service_info) + '</div>') : '');

        html += '<tr style="' + (isVisible ? '' : 'opacity:0.45;') + '" onclick="selectDevice(' + dev.id + ')">';
        for (var colIdx = 0; colIdx < activeCols.length; colIdx++) {
            var ck = activeCols[colIdx].key;
            if (ck === 'visible') {
                html += '<td style="text-align:center;" onclick="event.stopPropagation();">' + visChk + '</td>';
            } else if (ck === 'status') {
                html += '<td><span class="badge badge-' + dev.status + '">' + escapeHtml(dev.status) + '</span></td>';
            } else if (ck === 'ip_address') {
                html += '<td style="font-family:monospace;font-weight:600;color:#93c5fd;">' + escapeHtml(dev.ip_address) + '</td>';
            } else if (ck === 'name') {
                html += '<td>' + escapeHtml(dev.custom_name || dev.hostname || '-') + grpInfo + '</td>';
            } else if (ck === 'device_type') {
                html += '<td>' + escapeHtml((dev.device_type || '').toUpperCase()) + '</td>';
            } else if (ck === 'os_guess') {
                html += '<td>' + osAndBanner + '</td>';
            } else if (ck === 'mac_address') {
                html += '<td style="font-family:monospace;">' + escapeHtml(dev.mac_address || '-') + '</td>';
            } else if (ck === 'vendor') {
                html += '<td>' + escapeHtml(dev.vendor || '-') + '</td>';
            } else if (ck === 'open_ports') {
                html += '<td>' + escapeHtml(dev.open_ports || '-') + '</td>';
            } else if (ck === 'snmp') {
                html += '<td>' + snmpBadge + '</td>';
            } else if (ck === 'scope_name') {
                html += '<td>' + escapeHtml(dev.scope_name || '-') + '</td>';
            } else if (ck === 'last_seen') {
                html += '<td style="font-size:11.5px;color:var(--text-secondary);">' + escapeHtml(dev.last_seen || '-') + '</td>';
            }
        }
        html += '</tr>';
    }

    if (html === '') {
        html = '<tr><td colspan="' + Math.max(1, activeCols.length) + '" style="text-align:center;color:var(--text-secondary);padding:24px;">Keine passenden Geräte für diese Auswahl gefunden.</td></tr>';
    }
    tbody.innerHTML = html;
}

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
    var html = '<div style="background:rgba(15,23,42,0.7);border:1px solid var(--border-color);border-radius:8px;padding:14px;">' +
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

    // 3. Netzwerk-Interfaces & Switch-Ports (IF-MIB)
    var ifs = snmp.interfaces || [];
    html += '<div style="background:rgba(15,23,42,0.7);border:1px solid var(--border-color);border-radius:8px;padding:14px;">' +
        '<div class="panel-title" style="margin-bottom:10px;color:#60a5fa;">Netzwerk-Interfaces &amp; Switch-Ports (IF-MIB – ' + ifs.length + ' Ports)</div>';
    if (ifs.length > 0) {
        html += '<table class="data-table"><thead><tr>' +
            '<th>Index</th><th>Port / Interface</th><th>Beschreibung (Alias)</th><th>Status</th><th>Speed</th><th>Traffic RX</th><th>Traffic TX</th><th>Pakete-Fehler</th>' +
            '</tr></thead><tbody>';
        for (var idx = 0; idx < ifs.length; idx++) {
            var inf = ifs[idx];
            var stBadge = (inf.status === 'up') ? '<span class="badge badge-online">UP</span>' : '<span class="badge badge-offline">DOWN</span>';
            html += '<tr>' +
                '<td style="font-family:monospace;">#' + escapeHtml(String(inf.ifIndex)) + '</td>' +
                '<td style="font-weight:600;font-family:monospace;color:#93c5fd;">' + escapeHtml(inf.name || '-') + '</td>' +
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

function closeSnmpModal() {
    document.getElementById('snmp-modal').classList.remove('open');
}

function exportInventoryCsv() {
    var rows = [['Status', 'IP', 'Name', 'Gruppe', 'Typ', 'Betriebssystem', 'Dienste_Banner_TTL', 'MAC', 'Hersteller', 'Ports', 'SNMP_Name', 'Scope']];
    for (var i = 0; i < AppState.devices.length; i++) {
        var d = AppState.devices[i];
        rows.push([
            d.status,
            d.ip_address,
            d.custom_name || d.hostname || '',
            d.custom_group || '',
            d.device_type,
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

function renderSystemInfo() {
    var phpEl = document.getElementById('sys-php');
    if (!phpEl) return;
    var sys = AppState.system || {};
    phpEl.textContent = sys.php_version || '-';
    document.getElementById('sys-db').textContent = sys.db_driver || '-';
    document.getElementById('sys-nmap').textContent = sys.nmap_path || '-';
    document.getElementById('sys-snmp').textContent = sys.snmp_mode || '-';
    document.getElementById('sys-cron-cmd').textContent = sys.cron_cmd || '';

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

    var tbody = document.getElementById('admin-users-tbody');
    if (!tbody) return;
    var users = adm.users || [];
    var html = '';
    var roleLabels = {
        admin: '<span class="badge" style="background:rgba(16,185,129,0.2);color:#34d399;">ADMIN (Vollzugriff)</span>',
        operator: '<span class="badge" style="background:rgba(59,130,246,0.2);color:#93c5fd;">OPERATOR (Scans &amp; Edit)</span>',
        viewer: '<span class="badge" style="background:rgba(148,163,184,0.2);color:#cbd5e1;">VIEWER (Nur Lesen)</span>'
    };

    for (var i = 0; i < users.length; i++) {
        var u = users[i];
        var isAct = parseInt(u.is_active, 10) === 1;
        var stBadge = isAct ? '<span class="badge badge-online">Aktiv</span>' : '<span class="badge badge-offline">Gesperrt</span>';
        var rBadge = roleLabels[u.role] || escapeHtml(u.role);

        html += '<tr>' +
            '<td>' + stBadge + '</td>' +
            '<td style="font-weight:700;font-family:monospace;">' + escapeHtml(u.username) + '</td>' +
            '<td>' + escapeHtml(u.full_name || '-') + '</td>' +
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

function saveAdminDbAndScannerConfig() {
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
        snmp_batch_size: document.getElementById('adm-sc-snmpbatch').value
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
    document.getElementById('usr-role').value = u ? u.role : 'viewer';
    document.getElementById('usr-password').value = '';
    document.getElementById('usr-active').value = u ? u.is_active : 1;
    modal.classList.add('open');
}

function closeUserModal() {
    document.getElementById('user-modal').classList.remove('open');
}

function saveUserForm() {
    var payload = {
        id: document.getElementById('usr-id').value,
        username: document.getElementById('usr-username').value,
        full_name: document.getElementById('usr-fullname').value,
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
            showToast('Benutzerkonto gespeichert.');
            loadTopologyData();
        } else {
            showToast('Fehler: ' + res.error);
        }
    });
}

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

function openPasswordModal() {
    document.getElementById('own-new-password').value = '';
    document.getElementById('password-modal').classList.add('open');
}

function closePasswordModal() {
    document.getElementById('password-modal').classList.remove('open');
}

function saveOwnPassword() {
    var np = document.getElementById('own-new-password').value;
    fetch('api.php?action=change_own_password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ new_password: np })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.ok) {
            closePasswordModal();
            showToast('Dein Passwort wurde erfolgreich geändert!');
        } else {
            showToast('Fehler: ' + res.error);
        }
    });
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function () {
        closeAllDropdowns();
    });
    loadTopologyData(function () {
        if (AppState.devices.length > 0) {
            fitNetworkGraph();
        }
    });
});
