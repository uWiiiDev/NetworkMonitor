# NetworkMonitor – Installations- und Bedienungsanleitung

Dieses Dokument beschreibt, wie **NetworkMonitor** lokal getestet und anschließend auf einem **Ubuntu 14.04 Server (Apache, PHP, MySQL)** installiert und betrieben wird.

---

## 1. Funktionsumfang

* **Mehrere Netzwerk-Scopes (Subnetze / VLANs):** Beliebige IP-Bereiche im CIDR-Format (z. B. `192.168.178.0/24`, `10.10.20.0/24`) oder als IP-Spanne (`192.168.1.1-192.168.1.100`) anlegen und einzeln oder gemeinsam im Netzwerkplan darstellen.
* **Managed Switches mit SNMP (v1/v2c):**
  * Liest `sysName`, `sysDescr`, `sysLocation` und alle Switch-Interfaces (`ifName` / `ifDescr`) aus.
  * Liest die **Bridge-MIB MAC-Adresstabelle** (`dot1dTpFdbPort` / `dot1qTpFdbPort`) aus und ordnet gefundene Endgeräte automatisch dem jeweiligen **Switch-Port** (z. B. `Gi1/0/2`, `Port 8`) im grafischen Netzwerkplan zu.
  * Liest **LLDP-Nachbarschaften** (`lldpRemSysName`) zwischen Switches und Routern aus.
  * **Besonderheit:** Besitzt sowohl Unterstützung für `php5-snmp` als auch einen **integrierten Pure-PHP UDP SNMPv2c Client**, sodass SNMP-Abfragen selbst dann funktionieren, wenn auf dem Server keine SNMP-Erweiterung installiert ist.
* **Host- & Port-Scanner:**
  * Nutzt `nmap` (falls installiert) oder einen schnellen **parallelen Ping-Sweep + Linux ARP-Cache (`/proc/net/arp` / `ip neigh`) + asynchronen TCP-Port-Scanner** (Ports 22, 23, 53, 80, 139, 161, 443, 445, 515, 631, 3306, 3389, 8080, 8443, 9100).
* **Scan per Klick & per Cronjob:**
  * Direkt im Webinterface per Button **„Jetzt scannen“** oder automatisiert über `cli_scan.php`.

---

## 2. Lokaler Sofort-Test & Login-Zugangsdaten

Für den lokalen Test ist bereits ein Webserver vorbereitet und dein lokales Subnetz (`192.168.178.0/24`) automatisch eingetragen.

1. Öffne im Browser: **`http://localhost:8080`**
2. Du wirst automatisch zur geschützten **Login-Seite (`login.php`)** geleitet. Zum direkten Testen der **3 Berechtigungs-Rollen** sind folgende Standard-Konten vorinstalliert (auf der Login-Seite auch per Schnellklick auswählbar):
   * **Administrator (Vollzugriff inkl. Datenbank-Konfiguration & Benutzerverwaltung):**
     * Benutzername: **`admin`** | Passwort: **`admin123`**
   * **Operator / Techniker (Scans starten, Scopes & Geräte bearbeiten):**
     * Benutzername: **`operator`** | Passwort: **`operator123`**
   * **Betrachter / Viewer (Nur Lesen, Ansichten wechseln & Pläne exportieren):**
     * Benutzername: **`gast`** | Passwort: **`gast123`**
3. Als **Admin** findest du oben in der Navigation den Reiter **„⚙ Adminbereich (DB & Rechte)“**:
   * Dort kannst du direkt im Browser die **MySQL-Datenbankverbindung eintragen, live testen und speichern** (ohne Konfigurationsdateien per Hand bearbeiten zu müssen!).
   * Außerdem kannst du dort neue Benutzer anlegen, Passwörter ändern und Rollen (`admin`, `operator`, `viewer`) vergeben.

---

## 3. Installation auf dem Ubuntu 14.04 Server (Apache + PHP + MySQL)

### Schritt 3.1: Hinweis zu Paketquellen unter Ubuntu 14.04 (EOL)
Da Ubuntu 14.04 (*Trusty Tahr*) das reguläre Support-Ende erreicht hat, befinden sich die offiziellen Pakete auf `old-releases.ubuntu.com`. Falls `apt-get update` auf deinem Ubuntu 14.04 Server einen 404-Fehler meldet und du noch Pakete wie `nmap` oder `php5-snmp` nachinstallieren möchtest, kannst du die Paketquellen mit folgendem Befehl umstellen:

```bash
sudo sed -i -re 's/([a-z]{2}\.)?archive.ubuntu.com|security.ubuntu.com/old-releases.ubuntu.com/g' /etc/apt/sources.list
sudo apt-get update
```

### Schritt 3.2: Empfohlene Pakete prüfen / installieren
Auf deinem Ubuntu 14.04 LAMP-Server sollten folgende Pakete vorhanden sein (optional `nmap` und `php5-snmp` für noch schnellere Scans):

```bash
sudo apt-get install apache2 php5 php5-mysql php5-cli mysql-server nmap php5-snmp
```

*(Falls `nmap` oder `php5-snmp` nicht installiert werden können, ist das kein Problem: NetworkMonitor bringt einen eigenen PHP-Parallel-Scanner und einen eigenen UDP-SNMP-Client mit!)*

### Schritt 3.3: Dateien auf den Server kopieren
Kopiere den gesamten Projektordner `NetworkMonitor` auf deinen Ubuntu 14.04 Server nach `/var/www/html/networkmonitor`:

```bash
sudo mkdir -p /var/www/html/networkmonitor
sudo cp -r * /var/www/html/networkmonitor/
sudo chown -R www-data:www-data /var/www/html/networkmonitor
sudo chmod +x /var/www/html/networkmonitor/cli_scan.php
```

### Schritt 3.4: MySQL-Datenbank einrichten
1. Melde dich an MySQL an und importiere die mitgelieferte Datei `database.sql` (oder lasse sie automatisch anlegen):

```bash
mysql -u root -p < /var/www/html/networkmonitor/database.sql
```

*(Optional kannst du einen eigenen MySQL-Benutzer anlegen:)*
```sql
GRANT ALL PRIVILEGES ON network_monitor.* TO 'netmon'@'localhost' IDENTIFIED BY 'DeinSicheresPasswort';
FLUSH PRIVILEGES;
```

2. Öffne die Datei `/var/www/html/networkmonitor/config.php` und trage deine MySQL-Zugangsdaten ein:

```php
    'db' => array(
        'driver'   => 'mysql',          // Auf 'mysql' setzen
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'dbname'   => 'network_monitor',
        'username' => 'netmon',         // oder 'root'
        'password' => 'DeinSicheresPasswort',
        'charset'  => 'utf8',
    ),
```

### Schritt 3.5: Webinterface aufrufen
Rufe im Browser die Adresse deines Ubuntu-Servers auf:
`http://<IP-DES-UBUNTU-SERVERS>/networkmonitor/`

---

## 4. Managed Switches für SNMP vorbereiten

Damit die Managed Switches ihre Port-Tabellen und Nachbarschaften verraten:
1. Aktiviere im Webinterface eurer Managed Switches (Cisco, HP/Aruba, Netgear, Ubiquiti, TP-Link, D-Link, MikroTik etc.) **SNMP v2c** (Read-Only Community, z. B. `public` oder euren internen Community-String) sowie idealerweise **LLDP**.
2. Trage beim Anlegen oder Bearbeiten eines Netzwerk-Scopes in NetworkMonitor genau diesen **SNMP Community-String** ein.
3. Beim nächsten Scan erkennt NetworkMonitor den Switch automatisch, liest die gelernten MAC-Adressen pro Switch-Port aus und zeichnet die Port-Bezeichnungen (z. B. `Gi1/0/4`, `Port 12`) direkt an die Verbindungslinien im Netzwerkplan.

---

## 5. Automatischen Scan per Cronjob einrichten (späterer Ausbau)

Wenn der Scan später regelmäßig automatisch im Hintergrund laufen soll, öffne auf dem Ubuntu 14.04 Server die Crontab:

```bash
sudo crontab -e
```

Und füge folgende Zeile ein (scannt z. B. alle 30 Minuten alle aktiven Scopes):

```cron
*/30 * * * * /usr/bin/php /var/www/html/networkmonitor/cli_scan.php --all >> /var/log/network_monitor.log 2>&1
```

Du kannst den CLI-Scanner auch jederzeit manuell per SSH testen:
```bash
php /var/www/html/networkmonitor/cli_scan.php --all
```
