# NetworkMonitor – Automatischer Netzwerkplan, SNMP Switch Discovery, Port-Management & Anomalie-Wächter

**NetworkMonitor** ist eine schnelle, webbasierte Anwendung zur automatischen Netzwerk-Erkennung (Network Discovery), interaktiven Topologie-Visualisierung und grafischen Switch-Port-/VLAN-Verwaltung. Sie unterstützt mehrere Netzwerk-Scopes (CIDR & IP-Bereiche), liest **Managed Switches** sowie **Netzwerkdrucker** per **SNMP (v1/v2c)** aus und läuft sowohl auf modernen Linux-Systemen (PHP 7.x / 8.x) als auch auf älteren Bestandsservern (**Ubuntu 14.04 LTS mit Apache 2.4, PHP 5.5+ und MySQL 5.5+ / SQLite**).

---

## Hauptfunktionen

- **Schneller Multi-Host-Batch-Scanner:**
  - Paralleler Ping-Sweep & Linux Kernel ARP-Cache-Auswertung
  - Paralleler nicht-blockierender UDP DNS-Reverse-Lookup (`PTR`) & Windows NetBIOS (`NBSTAT` UDP 137)
  - Asynchroner Multi-Host TCP-Port-Scanner (32 Hosts × 15 Ports gleichzeitig)
  - Paralleles **HTTP/HTTPS (`<title>` & `Server:`-Header)** sowie **SSH-Banner-Grabbing**
  - **ICMP-TTL Betriebssystem-Fingerprinting** (`TTL 64` Linux/macOS/iOS/Android, `TTL 128` Windows, `TTL 255` Switch/Drucker/IoT)
- **Tiefe SNMP v1/v2c Integration & 19-Zoll Switch-Frontblende:**
  - Automatische Layer-2 Port-Zuordnung über **Bridge-MIB / Q-Bridge-MIB MAC-Adresstabellen** und **LLDP-Nachbarschaft**
  - Originalgetreue **19"-Switch-Frontblende** mit Stacking (Unit #1, Unit #2...), Kaskadierungs-Anzeige und **Live-Port-/VLAN-Konfiguration per SSH/SNMP** (Access/Trunk, VLAN-Neuanlage, Alias, PoE/Shutdown)
  - **🔌 Patchfeld- & Netzwerkdosen-Zuordnung (Wall Jack Mapping):** Verknüpfung von Switch-Ports mit Wanddosen (`Dose E1-04`), Patchfeldern und Räumen inkl. CSV-Import/Export und **🔍 MAC-, Dosen- & Switch-Port-Finder**
  - **Drucker-MIB Unterstützung:** Liest Gesamt-Seitenzähler, Display-Text und **Tinten-/Toner-Füllstände in Prozent (`%`)** aus
- **6 interaktive Ansichten, 5 Farbschemata & Mehrsprachigkeit (DE / EN / FR / ES):**
  - 6 Topologie-Ansichten (Stern, Baum Oben→Unten, Baum Links→Rechts, Radial, Matrix-Kacheln, 19"-Switch-Rack)
  - **5 Farbschemata (`🎨 Design`):** *Midnight Slate*, *Cyber Emerald (NOC Grün)*, *Deep Violet & Cyan*, *Amber Industrial* und *☀️ Daylight Clean (Heller Modus)*
  - **4 Sprachen (`🌐 DE | EN | FR | ES`):** Sofortige Umschaltung im laufenden Betrieb
- **Inventarliste mit Excel-Spaltenfiltern & Gruppensummen:**
  - Excel-ähnliches Filter-Popup (`⧩`) pro Spalte mit Bedingungsfiltern (*Enthält*, *Enthält nicht*, *Beginnt mit*, *Ist nicht leer* etc.) und Checkbox-Wertelisten samt Häufigkeiten
  - Gruppierung (`📊 Gruppieren & Summen`) nach Gerätegruppe, Typ, Hersteller, OS, Switch, Scope oder Status mit auf-/zuklappbaren Zwischensummen und klickbaren Gruppen-Summen-Chips
- **🛡️ Automatischer Anomalie-Wächter, MAC-Fahndungsliste & Alarm-Quittierung mit Kommentar:**
  - Erkennt vollautomatisch **ARP-Spoofing / IP-Konflikte**, **neue unbekannte MAC-Adressen**, **Rogue-Switches (Multi-MACs am Port)**, **MAC-Flapping / Loops** sowie **Port-Paketfehler**
  - **🎯 Gezielte MAC-Überwachungsliste:** Alarm per Web & E-Mail, sobald eine vorab eingetragene, bislang unbekannte MAC-Adresse im Netz auftaucht
  - **Quittierung mit Kommentarfunktion:** Operatoren, Netzwerkexperten und Admins können Alarme mit Freitext-Kommentar quittieren (`ack_comment`, `ack_by`, `ack_at`)
- **4 Rollen (RBAC), Benutzer-E-Mails, Passwort-Reset & Zwei-Faktor-Authentifizierung (2FA):**
  - 4 Rollen: **`ADMIN`** (Vollzugriff + DB + GitHub-Update), **`NETADMIN`** (Netzwerkexperte inkl. geschützter Core-Switches & Trunks), **`OPERATOR`** (Scans, Access-Ports & Alarm-Quittierung ohne Core-Switches), **`VIEWER`** (Nur Lesen & Export)
  - Persönliche E-Mail-Adressen pro Benutzer für automatische Alarm-Benachrichtigungen, **„Passwort vergessen?"** per 6-stelligem E-Mail-Code sowie **2FA per E-Mail-Code oder Authenticator-App (TOTP / RFC 6238)**
- **🔄 Integriertes 1-Klick GitHub-Update:**
  - Prüft im Adminbereich live auf neue Versionen/Commits auf `github.com/uWiiiDev/NetworkMonitor` und installiert Updates per Klick unter Erhalt aller lokalen Daten in `data/`.

---

## Schnellstart & Installation

Eine ausführliche Anleitung ist direkt in der Anwendung über das **`?`**-Symbol oben rechts ([`hilfe.php`](hilfe.php)) sowie in [`INSTALL_HILFE.md`](INSTALL_HILFE.md) enthalten.

```bash
git clone https://github.com/uWiiiDev/NetworkMonitor.git
cd NetworkMonitor
chmod -R 775 data
php -S 0.0.0.0:8080
```

### Standard-Zugangsdaten beim Erststart
- **Administrator:** `admin` / `admin123`
- **Netzwerkexperte (Core-Switches & VLANs):** `netadmin` / `netadmin123`
- **Techniker (Operator):** `operator` / `operator123`
- **Betrachter (Viewer):** `gast` / `gast123`
