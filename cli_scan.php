#!/usr/bin/env php
<?php
/**
 * NetworkMonitor - CLI / Cronjob Scanner-Skript
 * Aufruf-Beispiele:
 *   php cli_scan.php --all             (Scannt alle aktiven Scopes - ideal für Cronjob)
 *   php cli_scan.php --scope=1         (Scannt nur Scope mit ID 1)
 *
 * Kompatibel mit PHP 5.5+ (Ubuntu 14.04) bis PHP 8.x
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript darf nur über die Kommandozeile (CLI / Cronjob) ausgeführt werden.\n");
}

require_once __DIR__ . '/includes/scanner.php';

$options = getopt('', array('all', 'scope:', 'help'));

if (isset($options['help'])) {
    echo "NetworkMonitor CLI Scanner\n";
    echo "Verwendung:\n";
    echo "  php cli_scan.php --all         Scannt alle aktiven Netzwerk-Scopes\n";
    echo "  php cli_scan.php --scope=ID    Scannt einen bestimmten Scope anhand seiner ID\n";
    exit(0);
}

$scanner = new NetworkScanner();
$start = microtime(true);

echo "[" . date('Y-m-d H:i:s') . "] NetworkMonitor CLI-Scan gestartet...\n";

try {
    if (isset($options['scope'])) {
        $scopeId = (int)$options['scope'];
        $res = $scanner->scanScope($scopeId, 'cron');
        echo " -> Scope #{$res['scope_id']} ({$res['name']} - {$res['cidr']}): {$res['hosts_up']} Geräte aktiv, {$res['switches_found']} Managed Switches.\n";
    } else {
        $results = $scanner->scanAllActiveScopes('cron');
        foreach ($results as $res) {
            echo " -> Scope #{$res['scope_id']} ({$res['scope_name']} - {$res['cidr']}): {$res['hosts_up']} Geräte aktiv, {$res['switches_found']} Managed Switches.\n";
        }
    }
    $duration = round(microtime(true) - $start, 2);
    echo "[" . date('Y-m-d H:i:s') . "] Alle Scans erfolgreich beendet in {$duration}s.\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "[FEHLER] " . $e->getMessage() . "\n");
    exit(1);
}
