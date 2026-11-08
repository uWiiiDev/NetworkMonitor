#!/usr/bin/env bash
# ==============================================================================
# NetworkMonitor - Debian/Ubuntu (.deb) Package Builder
# Erstellt ein installierbares .deb-Paket inkl. Systemd-Service, Scan-Timer,
# CLI-Befehl (/usr/bin/network-monitor) und Desktop-Icon im Anwendungsmenü.
# ==============================================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "${PROJECT_ROOT}/VERSION")"
PKG_NAME="network-monitor"
ARCH="all"
BUILD_ROOT="${PROJECT_ROOT}/packaging/build/${PKG_NAME}_${VERSION}_${ARCH}"
OUTPUT_DEB="${PROJECT_ROOT}/${PKG_NAME}_${VERSION}_${ARCH}.deb"

echo "==> Baue Debian-Paket: ${PKG_NAME}_${VERSION}_${ARCH}.deb ..."

rm -rf "${PROJECT_ROOT}/packaging/build"
rm -f "${PROJECT_ROOT}/${PKG_NAME}_"*_"${ARCH}.deb"
mkdir -p "${BUILD_ROOT}/DEBIAN"
mkdir -p "${BUILD_ROOT}/opt/network-monitor/includes"
mkdir -p "${BUILD_ROOT}/opt/network-monitor/assets"
mkdir -p "${BUILD_ROOT}/var/lib/network-monitor/data"
mkdir -p "${BUILD_ROOT}/etc/default"
mkdir -p "${BUILD_ROOT}/lib/systemd/system"
mkdir -p "${BUILD_ROOT}/usr/bin"
mkdir -p "${BUILD_ROOT}/usr/share/applications"
mkdir -p "${BUILD_ROOT}/usr/share/icons/hicolor/scalable/apps"

# 1. Anwendungsdateien nach /opt/network-monitor kopieren
cp "${PROJECT_ROOT}/index.php"         "${BUILD_ROOT}/opt/network-monitor/"
cp "${PROJECT_ROOT}/api.php"           "${BUILD_ROOT}/opt/network-monitor/"
cp "${PROJECT_ROOT}/login.php"         "${BUILD_ROOT}/opt/network-monitor/"
cp "${PROJECT_ROOT}/hilfe.php"         "${BUILD_ROOT}/opt/network-monitor/"
cp "${PROJECT_ROOT}/cli_scan.php"      "${BUILD_ROOT}/opt/network-monitor/"
cp "${PROJECT_ROOT}/config.php"        "${BUILD_ROOT}/opt/network-monitor/"
cp "${PROJECT_ROOT}/database.sql"      "${BUILD_ROOT}/opt/network-monitor/"
cp "${PROJECT_ROOT}/VERSION"           "${BUILD_ROOT}/opt/network-monitor/"
cp "${PROJECT_ROOT}/README.md"         "${BUILD_ROOT}/opt/network-monitor/"
cp "${PROJECT_ROOT}/INSTALL_HILFE.md"  "${BUILD_ROOT}/opt/network-monitor/"
cp "${PROJECT_ROOT}/router.php"        "${BUILD_ROOT}/opt/network-monitor/"
cp "${PROJECT_ROOT}/.htaccess"         "${BUILD_ROOT}/opt/network-monitor/"
cp "${PROJECT_ROOT}"/includes/*.php    "${BUILD_ROOT}/opt/network-monitor/includes/"
cp "${PROJECT_ROOT}"/assets/*          "${BUILD_ROOT}/opt/network-monitor/assets/"
chmod 0755 "${BUILD_ROOT}/opt/network-monitor/cli_scan.php"

# Symlink /opt/network-monitor/data -> /var/lib/network-monitor/data
ln -s /var/lib/network-monitor/data "${BUILD_ROOT}/opt/network-monitor/data"

# 2. Konfigurationsdatei /etc/default/network-monitor
cat << 'EOF' > "${BUILD_ROOT}/etc/default/network-monitor"
# NetworkMonitor Server-Konfiguration
# Standard-Port für die Weboberfläche (Standard: 8085)
PORT=8085

# Bind-Adresse (127.0.0.1 = nur lokal, 0.0.0.0 = im gesamten LAN erreichbar)
BIND_ADDR=0.0.0.0
EOF
chmod 0644 "${BUILD_ROOT}/etc/default/network-monitor"

# 3. Systemd-Service für den Webserver (/lib/systemd/system/network-monitor.service)
cat << 'EOF' > "${BUILD_ROOT}/lib/systemd/system/network-monitor.service"
[Unit]
Description=NetworkMonitor Web Application Server
After=network.target

[Service]
Type=simple
Environment="PHP_CLI_SERVER_WORKERS=6"
EnvironmentFile=-/etc/default/network-monitor
WorkingDirectory=/opt/network-monitor
ExecStart=/bin/sh -c 'exec /usr/bin/php -d expose_php=0 -d variables_order=EGPCS -d session.use_strict_mode=1 -d session.cookie_httponly=1 -d session.cookie_samesite=Lax -S ${BIND_ADDR:-0.0.0.0}:${PORT:-8085} -t /opt/network-monitor /opt/network-monitor/router.php'
Restart=always
RestartSec=3
User=root
Group=root
PrivateTmp=true
ProtectControlGroups=true
ProtectKernelModules=true
ProtectKernelTunables=true
RestrictSUIDSGID=true
UMask=0027

[Install]
WantedBy=multi-user.target
EOF
chmod 0644 "${BUILD_ROOT}/lib/systemd/system/network-monitor.service"

# 4. Systemd-Service & Timer für den automatischen Hintergrund-Scan
cat << 'EOF' > "${BUILD_ROOT}/lib/systemd/system/network-monitor-scan.service"
[Unit]
Description=NetworkMonitor Background Network Scanner
After=network-online.target

[Service]
Type=oneshot
WorkingDirectory=/opt/network-monitor
ExecStart=/usr/bin/php /opt/network-monitor/cli_scan.php --all
User=root
Group=root
PrivateTmp=true
ProtectControlGroups=true
ProtectKernelModules=true
ProtectKernelTunables=true
RestrictSUIDSGID=true
UMask=0027
EOF
chmod 0644 "${BUILD_ROOT}/lib/systemd/system/network-monitor-scan.service"

cat << 'EOF' > "${BUILD_ROOT}/lib/systemd/system/network-monitor-scan.timer"
[Unit]
Description=Run NetworkMonitor Background Scan periodically

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
Unit=network-monitor-scan.service

[Install]
WantedBy=timers.target
EOF
chmod 0644 "${BUILD_ROOT}/lib/systemd/system/network-monitor-scan.timer"

# 5. CLI- & Desktop-Launcher (/usr/bin/network-monitor)
cat << 'EOF' > "${BUILD_ROOT}/usr/bin/network-monitor"
#!/usr/bin/env bash
PORT=8085
if [ -f /etc/default/network-monitor ]; then
    . /etc/default/network-monitor
fi
URL="http://127.0.0.1:${PORT}"

CMD="${1:-open}"
case "$CMD" in
    open|"")
        if command -v systemctl >/dev/null 2>&1; then
            if ! systemctl is-active --quiet network-monitor.service; then
                pkexec systemctl start network-monitor.service >/dev/null 2>&1 || sudo systemctl start network-monitor.service >/dev/null 2>&1 || true
                sleep 0.5
            fi
        fi
        # Öffne als eigenständiges App-Fenster falls Chromium/Chrome vorhanden, sonst Standard-Browser
        for BROWSER in google-chrome chromium chromium-browser brave-browser microsoft-edge; do
            if command -v "$BROWSER" >/dev/null 2>&1; then
                exec "$BROWSER" --app="$URL" >/dev/null 2>&1 &
                exit 0
            fi
        done
        exec xdg-open "$URL" >/dev/null 2>&1 &
        ;;
    status)
        echo "NetworkMonitor URL: $URL"
        systemctl status network-monitor.service --no-pager || true
        ;;
    scan)
        sudo /usr/bin/php /opt/network-monitor/cli_scan.php --all
        ;;
    restart)
        sudo systemctl restart network-monitor.service
        echo "NetworkMonitor Dienst neu gestartet ($URL)."
        ;;
    *)
        echo "Verwendung: network-monitor [open|status|scan|restart]"
        echo "  open     Öffnet die NetworkMonitor Oberfläche ($URL)"
        echo "  status   Zeigt den Status des Hintergrunddienstes"
        echo "  scan     Führt sofort einen Netzwerk-Scan aller aktiven Scopes aus"
        echo "  restart  Startet den NetworkMonitor Webserver neu"
        ;;
esac
EOF
chmod 0755 "${BUILD_ROOT}/usr/bin/network-monitor"

# 6. SVG-Icon (/usr/share/icons/hicolor/scalable/apps/network-monitor.svg)
cat << 'EOF' > "${BUILD_ROOT}/usr/share/icons/hicolor/scalable/apps/network-monitor.svg"
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#0f172a"/>
      <stop offset="100%" stop-color="#1e293b"/>
    </linearGradient>
    <linearGradient id="accent" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#38bdf8"/>
      <stop offset="100%" stop-color="#10b981"/>
    </linearGradient>
  </defs>
  <rect width="120" height="120" x="4" y="4" rx="26" fill="url(#bg)" stroke="#38bdf8" stroke-width="3"/>
  <circle cx="64" cy="64" r="42" fill="none" stroke="#1e3a5f" stroke-width="2" stroke-dasharray="5,5"/>
  <circle cx="64" cy="64" r="26" fill="none" stroke="#1e3a5f" stroke-width="2"/>
  <line x1="64" y1="64" x2="34" y2="36" stroke="url(#accent)" stroke-width="4" stroke-linecap="round"/>
  <line x1="64" y1="64" x2="96" y2="40" stroke="url(#accent)" stroke-width="4" stroke-linecap="round"/>
  <line x1="64" y1="64" x2="36" y2="92" stroke="url(#accent)" stroke-width="4" stroke-linecap="round"/>
  <line x1="64" y1="64" x2="94" y2="90" stroke="url(#accent)" stroke-width="4" stroke-linecap="round"/>
  <circle cx="64" cy="64" r="12" fill="#38bdf8"/>
  <circle cx="34" cy="36" r="8" fill="#10b981"/>
  <circle cx="96" cy="40" r="8" fill="#10b981"/>
  <circle cx="36" cy="92" r="8" fill="#10b981"/>
  <circle cx="94" cy="90" r="8" fill="#f59e0b"/>
</svg>
EOF
chmod 0644 "${BUILD_ROOT}/usr/share/icons/hicolor/scalable/apps/network-monitor.svg"

# 7. Desktop-Eintrag (/usr/share/applications/network-monitor.desktop)
cat << 'EOF' > "${BUILD_ROOT}/usr/share/applications/network-monitor.desktop"
[Desktop Entry]
Version=1.0
Type=Application
Name=NetworkMonitor
GenericName=LAN & SNMP Topology Monitor
Comment=Automatische Netzwerk-Inventarisierung, Switch-Port-Topologie & Alarm-Monitoring
Exec=/usr/bin/network-monitor open
Icon=network-monitor
Terminal=false
Categories=Network;System;Monitor;
Keywords=network;scanner;snmp;switch;topology;lan;fritzbox;
StartupNotify=true
EOF
chmod 0644 "${BUILD_ROOT}/usr/share/applications/network-monitor.desktop"

# 8. DEBIAN/conffiles
cat << 'EOF' > "${BUILD_ROOT}/DEBIAN/conffiles"
/etc/default/network-monitor
EOF
chmod 0644 "${BUILD_ROOT}/DEBIAN/conffiles"

# 9. DEBIAN/control
INSTALLED_SIZE="$(du -sk "${BUILD_ROOT}" | cut -f1)"
cat << EOF > "${BUILD_ROOT}/DEBIAN/control"
Package: ${PKG_NAME}
Version: ${VERSION}
Section: net
Priority: optional
Architecture: ${ARCH}
Installed-Size: ${INSTALLED_SIZE}
Depends: php-cli | php5-cli, php-sqlite3 | php5-sqlite, iproute2, iputils-ping
Recommends: php-snmp | php5-snmp, php-curl | php5-curl, php-mbstring, php-xml, nmap, git
Maintainer: uWiiiDev <https://github.com/uWiiiDev/NetworkMonitor>
Homepage: https://github.com/uWiiiDev/NetworkMonitor
Description: Agentenloses LAN-Inventar, L2/L3-Topologie & Switch-Port-Monitoring
 NetworkMonitor erkennt automatisch alle Geräte im lokalen Netzwerk per Ping,
 ARP, Nmap, SNMP (Bridge-MIB/LLDP), NetBIOS und FRITZ!Box TR-064, stellt die
 komplette Switch- und WLAN-Topologie interaktiv dar und überwacht Alarme.
EOF
chmod 0644 "${BUILD_ROOT}/DEBIAN/control"

# 10. DEBIAN/postinst
cat << 'EOF' > "${BUILD_ROOT}/DEBIAN/postinst"
#!/bin/sh
set -e

mkdir -p /var/lib/network-monitor/data
chmod 0750 /var/lib/network-monitor/data

if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload >/dev/null 2>&1 || true
    systemctl enable network-monitor.service >/dev/null 2>&1 || true
    systemctl restart network-monitor.service >/dev/null 2>&1 || true
    systemctl enable network-monitor-scan.timer >/dev/null 2>&1 || true
    systemctl start network-monitor-scan.timer >/dev/null 2>&1 || true
fi

if command -v update-desktop-database >/dev/null 2>&1; then
    update-desktop-database -q /usr/share/applications >/dev/null 2>&1 || true
fi
if command -v gtk-update-icon-cache >/dev/null 2>&1; then
    gtk-update-icon-cache -q -t -f /usr/share/icons/hicolor >/dev/null 2>&1 || true
fi

echo "======================================================================"
echo " NetworkMonitor wurde erfolgreich installiert!"
echo " -> Weboberfläche: http://127.0.0.1:8085"
echo " -> Standard-Login bei Neuinstallation: admin / admin123"
echo " -> Start über das Anwendungsmenü ('NetworkMonitor') oder im Terminal"
echo "    mit dem Befehl: network-monitor"
echo "======================================================================"
exit 0
EOF
chmod 0755 "${BUILD_ROOT}/DEBIAN/postinst"

# 11. DEBIAN/prerm & postrm
cat << 'EOF' > "${BUILD_ROOT}/DEBIAN/prerm"
#!/bin/sh
set -e
if command -v systemctl >/dev/null 2>&1; then
    systemctl stop network-monitor.service >/dev/null 2>&1 || true
    systemctl disable network-monitor.service >/dev/null 2>&1 || true
    systemctl stop network-monitor-scan.timer >/dev/null 2>&1 || true
    systemctl disable network-monitor-scan.timer >/dev/null 2>&1 || true
fi
exit 0
EOF
chmod 0755 "${BUILD_ROOT}/DEBIAN/prerm"

cat << 'EOF' > "${BUILD_ROOT}/DEBIAN/postrm"
#!/bin/sh
set -e
if [ "$1" = "purge" ]; then
    rm -rf /var/lib/network-monitor
fi
exit 0
EOF
chmod 0755 "${BUILD_ROOT}/DEBIAN/postrm"

# 12. Paket bauen
dpkg-deb --root-owner-group --build "${BUILD_ROOT}" "${OUTPUT_DEB}"
rm -rf "${PROJECT_ROOT}/packaging/build"

echo "==> Fertig! Paket erstellt unter: ${OUTPUT_DEB}"
ls -lh "${OUTPUT_DEB}"
