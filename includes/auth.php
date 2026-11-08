<?php
/**
 * Authentifizierung, 2FA (E-Mail-OTP & TOTP Authenticator-App), Passwort-Reset & Rollen-/Rechte-Verwaltung (RBAC)
 * Rollen:
 *   - 'viewer'   : Nur Lesen (Netzwerkplan ansehen, filtern, exportieren – KEINE Core-Switches sichtbar)
 *   - 'operator' : Techniker (Scans starten, Standard-Geräte & Access-Switch-Ports bearbeiten – KEINE Core-Switches sichtbar/administrierbar)
 *   - 'netadmin' : Netzwerkexperte (Vollzugriff auf alle Switches inkl. Core-Switches, Trunk-Ports, VLANs & Switch-Credentials)
 *   - 'admin'    : System-Administrator (Vollzugriff inkl. Core-Switches, Datenbank-Konfiguration, SMTP & Benutzerverwaltung)
 *
 * Kompatibel mit PHP 5.5+ (Ubuntu 14.04) bis PHP 8.x
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

class Auth
{
    private static $cachedUser = null;
    private static $sessionClosed = false;

    /**
     * Initialisiert die PHP-Session inklusive HTTP-Sicherheits-Headern (Clickjacking- & MIME-Sniffing-Schutz)
     * sowie HttpOnly-Cookie-Einstellungen zum Schutz vor Session-Diebstahl per XSS.
     */
    public static function startSession()
    {
        if (self::$sessionClosed) {
            return;
        }
        if (!headers_sent()) {
            if (function_exists('header_remove')) {
                @header_remove('X-Powered-By');
            }
            @header('X-Frame-Options: SAMEORIGIN');
            @header('X-Content-Type-Options: nosniff');
            @header('Referrer-Policy: same-origin');
            @header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
            @header("Content-Security-Policy: frame-ancestors 'self'");
            @ini_set('session.use_only_cookies', '1');
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.cookie_httponly', '1');
            @ini_set('session.cookie_samesite', 'Lax');
        }
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    /**
     * Ermittelt die Client-IP-Adresse für das IP-basierte Brute-Force-Rate-Limiting.
     */
    private static function getClientIp()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '0.0.0.0';
    }

    /**
     * Gibt den Dateipfad für den IP-basierten Rate-Limit-Zähler zurück.
     */
    private static function getIpRateLimitFile()
    {
        return sys_get_temp_dir() . '/nm_rl_' . md5(self::getClientIp()) . '.json';
    }

    /**
     * Gibt das exklusive PHP-Session-Dateilock frei, ohne dass nachfolgende Rollenprüfungen
     * innerhalb desselben Requests die Session erneut öffnen und sperren.
     */
    public static function closeSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        self::$sessionClosed = true;
    }

    /**
     * Prüft das Brute-Force-Rate-Limit für Login- und 2FA-Versuche (sowohl Session- als auch IP-basiert).
     * Wirft eine RuntimeException, falls die Sitzung oder IP wegen zu vieler Fehlversuche temporär gesperrt ist.
     */
    public static function checkRateLimit()
    {
        self::startSession();
        $now = time();
        if (!empty($_SESSION['nm_lockout_until']) && (int)$_SESSION['nm_lockout_until'] > $now) {
            $waitSec = (int)$_SESSION['nm_lockout_until'] - $now;
            throw new RuntimeException('Zu viele Fehlversuche! Bitte warte ' . $waitSec . ' Sekunden vor dem nächsten Versuch.');
        }
        $rlFile = self::getIpRateLimitFile();
        if (is_file($rlFile)) {
            $rlData = @json_decode((string)@file_get_contents($rlFile), true);
            if (is_array($rlData) && !empty($rlData['lockout_until']) && (int)$rlData['lockout_until'] > $now) {
                $waitSec = (int)$rlData['lockout_until'] - $now;
                throw new RuntimeException('Zu viele Fehlversuche von dieser IP-Adresse! Bitte warte ' . $waitSec . ' Sekunden.');
            }
        }
    }

    /**
     * Registriert einen fehlgeschlagenen Anmelde- oder 2FA-Versuch (Session + IP + Syslog für Fail2ban).
     * Ab 6 Fehlversuchen innerhalb von 5 Minuten wird eine Sperre von 60 Sekunden aktiviert.
     */
    public static function recordFailedAttempt()
    {
        self::startSession();
        $now = time();
        $windowStart = isset($_SESSION['nm_fail_window']) ? (int)$_SESSION['nm_fail_window'] : 0;
        if ($now - $windowStart > 300) {
            $_SESSION['nm_fail_window'] = $now;
            $_SESSION['nm_fail_count'] = 1;
        } else {
            $_SESSION['nm_fail_count'] = (isset($_SESSION['nm_fail_count']) ? (int)$_SESSION['nm_fail_count'] : 0) + 1;
        }
        if ($_SESSION['nm_fail_count'] >= 6) {
            $_SESSION['nm_lockout_until'] = $now + 60;
        }

        // IP-basierter Zähler (schützt auch gegen Angreifer ohne Session-Cookies)
        $rlFile = self::getIpRateLimitFile();
        $rlData = array('window' => $now, 'count' => 0, 'lockout_until' => 0);
        if (is_file($rlFile)) {
            $loaded = @json_decode((string)@file_get_contents($rlFile), true);
            if (is_array($loaded)) {
                $rlData = array_merge($rlData, $loaded);
            }
        }
        if ($now - (int)$rlData['window'] > 300) {
            $rlData['window'] = $now;
            $rlData['count'] = 1;
            $rlData['lockout_until'] = 0;
        } else {
            $rlData['count'] = (int)$rlData['count'] + 1;
        }
        if ($rlData['count'] >= 6) {
            $rlData['lockout_until'] = $now + 90;
        }
        @file_put_contents($rlFile, json_encode($rlData));

        if (function_exists('syslog')) {
            @syslog(LOG_AUTH | LOG_WARNING, 'NetworkMonitor: Failed authentication attempt from ' . self::getClientIp());
        }
    }

    /**
     * Setzt den Zähler für fehlgeschlagene Anmeldeversuche nach erfolgreichem Login zurück.
     */
    public static function clearFailedAttempts()
    {
        self::startSession();
        unset($_SESSION['nm_fail_window'], $_SESSION['nm_fail_count'], $_SESSION['nm_lockout_until']);
        $rlFile = self::getIpRateLimitFile();
        if (is_file($rlFile)) {
            @unlink($rlFile);
        }
    }

    /**
     * Erzeugt eine kryptographisch sichere Zufallszahl im Bereich [$min, $max].
     * Nutzt random_int() (PHP 7+) bzw. openssl_random_pseudo_bytes() als Fallback für PHP 5.5+.
     *
     * @param int $min Untere Grenze (inklusive)
     * @param int $max Obere Grenze (inklusive)
     * @return int
     */
    public static function secureRandomInt($min, $max)
    {
        if (function_exists('random_int')) {
            try {
                return random_int((int)$min, (int)$max);
            } catch (Exception $e) {}
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $range = (int)$max - (int)$min + 1;
            if ($range > 1) {
                $bytes = @openssl_random_pseudo_bytes(4);
                if ($bytes !== false && strlen($bytes) === 4) {
                    $unpacked = unpack('N', $bytes);
                    $val = $unpacked[1] & 0x7FFFFFFF;
                    return (int)$min + ($val % $range);
                }
            }
        }
        return mt_rand((int)$min, (int)$max);
    }

    /**
     * Lädt den aktuell angemeldeten Benutzer aus der Datenbank anhand der Session-ID.
     * Falls das Konto zwischenzeitlich deaktiviert oder gelöscht wurde, wird die Sitzung beendet.
     *
     * @return array|null Assoziatives Benutzer-Array oder null wenn nicht eingeloggt
     */
    public static function getCurrentUser()
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }
        self::startSession();
        if (empty($_SESSION['nm_user_id'])) {
            return null;
        }
        $pdo = Database::getInstance()->getPdo();
        $stmt = $pdo->prepare("SELECT id, username, full_name, email, receive_alerts, two_fa_mode, totp_secret, role, is_active, last_login FROM users WHERE id = :id");
        $stmt->execute(array(':id' => (int)$_SESSION['nm_user_id']));
        $user = $stmt->fetch();
        $stmt->closeCursor();
        if (!$user || (int)$user['is_active'] !== 1) {
            self::logout();
            return null;
        }
        self::$cachedUser = $user;
        return $user;
    }

    /**
     * Prüft Benutzername & Passwort.
     * Gibt zurück:
     *   - 'ok'           : Direkt eingeloggt (kein 2FA aktiv)
     *   - '2fa_required' : Passwort korrekt, 2FA-Schritt erforderlich (Session nm_2fa_pending_user_id gesetzt)
     *   - false          : Ungültige Zugangsdaten
     */
    public static function login($username, $password)
    {
        self::startSession();
        self::checkRateLimit();
        $pdo = Database::getInstance()->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(:e)) LIMIT 1");
        $cleanInput = trim($username);
        $stmt->execute(array(':u' => $cleanInput, ':e' => $cleanInput));
        $user = $stmt->fetch();
        $stmt->closeCursor();

        if (!$user) {
            self::recordFailedAttempt();
            return false;
        }
        if ((int)$user['is_active'] !== 1) {
            throw new RuntimeException('Dieses Benutzerkonto ist deaktiviert.');
        }
        if (!password_verify($password, $user['password_hash'])) {
            self::recordFailedAttempt();
            return false;
        }

        self::clearFailedAttempts();
        $twoFaMode = isset($user['two_fa_mode']) ? trim((string)$user['two_fa_mode']) : 'none';
        if ($twoFaMode === 'totp' && !empty($user['totp_secret'])) {
            $_SESSION['nm_2fa_pending_user_id'] = (int)$user['id'];
            $_SESSION['nm_2fa_mode'] = 'totp';
            return '2fa_required';
        }
        if ($twoFaMode === 'email') {
            if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Für dieses Konto ist E-Mail-2FA aktiviert, aber keine gültige E-Mail-Adresse hinterlegt. Bitte an den Administrator wenden.');
            }
            self::generateAndSendEmailOtp($user, '2fa');
            $_SESSION['nm_2fa_pending_user_id'] = (int)$user['id'];
            $_SESSION['nm_2fa_mode'] = 'email';
            return '2fa_required';
        }

        self::completeLoginSession($user);
        return 'ok';
    }

    /**
     * Schließt den Login-Vorgang ab, erneuert die Session-ID (Schutz vor Session Fixation)
     * und aktualisiert den last_login-Zeitstempel in der Datenbank.
     *
     * @param array $user Datensatz des authentifizierten Benutzers
     */
    public static function completeLoginSession(array $user)
    {
        self::startSession();
        self::clearFailedAttempts();
        if (function_exists('session_regenerate_id')) {
            @session_regenerate_id(true);
        }
        unset($_SESSION['nm_2fa_pending_user_id'], $_SESSION['nm_2fa_mode']);
        $_SESSION['nm_user_id'] = (int)$user['id'];
        $_SESSION['nm_username'] = $user['username'];
        $_SESSION['nm_role'] = $user['role'];

        $pdo = Database::getInstance()->getPdo();
        $pdo->prepare("UPDATE users SET last_login = :now, otp_code_hash = NULL, otp_expires_at = NULL WHERE id = :id")
            ->execute(array(':now' => date('Y-m-d H:i:s'), ':id' => (int)$user['id']));
    }

    /**
     * Gibt das Benutzer-Array zurück, für das aktuell eine 2FA-Prüfung aussteht.
     *
     * @return array|null
     */
    public static function getPending2faUser()
    {
        self::startSession();
        if (empty($_SESSION['nm_2fa_pending_user_id'])) {
            return null;
        }
        $pdo = Database::getInstance()->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(array(':id' => (int)$_SESSION['nm_2fa_pending_user_id']));
        $user = $stmt->fetch();
        $stmt->closeCursor();
        if (!$user || (int)$user['is_active'] !== 1) {
            unset($_SESSION['nm_2fa_pending_user_id'], $_SESSION['nm_2fa_mode']);
            return null;
        }
        $user['mode'] = isset($user['two_fa_mode']) ? $user['two_fa_mode'] : 'none';
        return $user;
    }

    /**
     * Überprüft den vom Benutzer eingegebenen 6-stelligen 2FA-Code (TOTP oder E-Mail-OTP).
     *
     * @param string $code 6-stelliger numerischer Sicherheitscode
     * @return bool True bei Erfolg
     */
    public static function verify2faCode($code)
    {
        self::checkRateLimit();
        $user = self::getPending2faUser();
        if (!$user) {
            throw new RuntimeException('Keine offene 2FA-Anmeldung gefunden. Bitte erneut anmelden.');
        }
        $cleanCode = preg_replace('/[^0-9]/', '', (string)$code);
        if (strlen($cleanCode) !== 6) {
            self::recordFailedAttempt();
            throw new RuntimeException('Bitte einen gültigen 6-stelligen Sicherheitscode eingeben.');
        }

        $mode = isset($user['two_fa_mode']) ? $user['two_fa_mode'] : 'none';
        if ($mode === 'totp' && !empty($user['totp_secret'])) {
            if (self::verifyTotpCode($user['totp_secret'], $cleanCode)) {
                self::completeLoginSession($user);
                return true;
            }
            self::recordFailedAttempt();
            throw new RuntimeException('Der eingegebene 6-stellige Authenticator-Code ist ungültig.');
        }

        if ($mode === 'email') {
            if (empty($user['otp_code_hash']) || empty($user['otp_expires_at'])) {
                throw new RuntimeException('Kein gültiger Sicherheitscode vorhanden. Bitte neuen Code anfordern.');
            }
            if (strtotime($user['otp_expires_at']) < time()) {
                throw new RuntimeException('Der Sicherheitscode ist abgelaufen (Gültigkeit: 10 Minuten). Bitte neuen Code senden lassen.');
            }
            if (password_verify($cleanCode, $user['otp_code_hash'])) {
                self::completeLoginSession($user);
                return true;
            }
            self::recordFailedAttempt();
            throw new RuntimeException('Der eingegebene 6-stellige E-Mail-Sicherheitscode ist ungültig.');
        }

        return false;
    }

    public static function resendEmail2faCode()
    {
        $user = self::getPending2faUser();
        if (!$user || $user['two_fa_mode'] !== 'email') {
            throw new RuntimeException('Keine offene E-Mail-2FA-Sitzung gefunden.');
        }
        self::generateAndSendEmailOtp($user, '2fa');
        return self::maskEmail($user['email']);
    }

    /**
     * Erzeugt einen 6-stelligen Einmalcode, speichert ihn gehasht in der DB und sendet ihn per SMTP an den Benutzer
     */
    public static function generateAndSendEmailOtp(array $user, $purpose = '2fa')
    {
        $email = isset($user['email']) ? trim((string)$user['email']) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Für dieses Benutzerkonto ist noch keine gültige E-Mail-Adresse hinterlegt.');
        }

        $smtpConf = Database::getInstance()->getConfig('smtp', array());
        if (empty($smtpConf['host'])) {
            throw new RuntimeException('Der SMTP-Server für den E-Mail-Versand ist noch nicht im Adminbereich konfiguriert.');
        }

        $code = str_pad((string)self::secureRandomInt(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + ($purpose === 'reset' ? 900 : 600));

        $pdo = Database::getInstance()->getPdo();
        $stmt = $pdo->prepare("UPDATE users SET otp_code_hash = :h, otp_expires_at = :exp WHERE id = :id");
        $stmt->execute(array(
            ':h'   => $hash,
            ':exp' => $expiresAt,
            ':id'  => (int)$user['id'],
        ));

        $smtpConf['to_email'] = $email;
        $mailer = new SmtpMailer($smtpConf);

        if ($purpose === 'reset') {
            $subject = '[NetworkMonitor] Sicherheitscode zum Zurücksetzen deines Passworts';
            $headline = 'Passwort zurücksetzen';
            $intro = 'Du hast das Zurücksetzen des Passworts für dein Benutzerkonto <b>' . htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') . '</b> angefordert.';
            $valMin = '15 Minuten';
        } else {
            $subject = '[NetworkMonitor] Dein 2FA-Sicherheitscode zur Anmeldung: ' . $code;
            $headline = 'Zwei-Faktor-Authentifizierung (2FA)';
            $intro = 'Für die Anmeldung mit deinem Konto <b>' . htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') . '</b> wird folgender 6-stelliger Sicherheitscode benötigt:';
            $valMin = '10 Minuten';
        }

        $htmlBody = '<div style="font-family:Segoe UI,Arial,sans-serif;background:#0f172a;color:#f8fafc;padding:24px;border-radius:10px;max-width:520px;">'
            . '<h2 style="color:#60a5fa;margin-top:0;">NetworkMonitor – ' . $headline . '</h2>'
            . '<p style="color:#cbd5e1;font-size:14px;line-height:1.5;">' . $intro . '</p>'
            . '<div style="background:#1e293b;border:2px solid #3b82f6;border-radius:8px;padding:16px;text-align:center;margin:20px 0;">'
            . '<div style="font-size:11px;color:#93c5fd;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">6-stelliger Sicherheitscode</div>'
            . '<div style="font-family:monospace;font-size:32px;font-weight:800;letter-spacing:8px;color:#ffffff;">' . $code . '</div>'
            . '</div>'
            . '<p style="color:#94a3b8;font-size:12px;">Dieser Code ist für <b>' . $valMin . '</b> gültig (bis ' . date('H:i:s', strtotime($expiresAt)) . ' Uhr). Falls du diese Anfrage nicht selbst ausgelöst hast, ignoriere diese E-Mail bitte.</p>'
            . '</div>';

        $mailer->sendMail($subject, $htmlBody);
        return true;
    }

    /**
     * Schritt 1 beim "Passwort vergessen": Sucht Benutzer anhand Benutzername oder E-Mail und sendet 6-stelligen Reset-Code
     */
    public static function sendPasswordResetCode($identifier)
    {
        $clean = trim((string)$identifier);
        if ($clean === '') {
            throw new InvalidArgumentException('Bitte deinen Benutzernamen oder deine E-Mail-Adresse eingeben.');
        }

        $pdo = Database::getInstance()->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(:e)) LIMIT 1");
        $stmt->execute(array(':u' => $clean, ':e' => $clean));
        $user = $stmt->fetch();

        if (!$user || (int)$user['is_active'] !== 1) {
            throw new RuntimeException('Kein aktives Benutzerkonto mit diesem Benutzernamen oder dieser E-Mail-Adresse gefunden.');
        }
        if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Für das Konto "' . $user['username'] . '" ist noch keine E-Mail-Adresse hinterlegt. Bitte wende dich an den System-Administrator.');
        }

        self::generateAndSendEmailOtp($user, 'reset');
        return self::maskEmail($user['email']);
    }

    /**
     * Schritt 2 beim "Passwort vergessen": Prüft 6-stelligen Reset-Code und setzt das neue Passwort
     */
    public static function resetPasswordWithCode($identifier, $code, $newPassword)
    {
        self::checkRateLimit();
        $cleanId = trim((string)$identifier);
        $cleanCode = preg_replace('/[^0-9]/', '', (string)$code);
        if (strlen($cleanCode) !== 6) {
            throw new InvalidArgumentException('Bitte den 6-stelligen Sicherheitscode aus der E-Mail eingeben.');
        }
        if (strlen((string)$newPassword) < 4) {
            throw new InvalidArgumentException('Das neue Passwort muss mindestens 4 Zeichen lang sein.');
        }

        $pdo = Database::getInstance()->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(:e)) LIMIT 1");
        $stmt->execute(array(':u' => $cleanId, ':e' => $cleanId));
        $user = $stmt->fetch();

        if (!$user || (int)$user['is_active'] !== 1) {
            self::recordFailedAttempt();
            throw new RuntimeException('Benutzerkonto nicht gefunden.');
        }
        if (empty($user['otp_code_hash']) || empty($user['otp_expires_at'])) {
            throw new RuntimeException('Es wurde kein aktiver Zurücksetzungscode für dieses Konto angefordert.');
        }
        if (strtotime($user['otp_expires_at']) < time()) {
            throw new RuntimeException('Der Sicherheitscode ist abgelaufen. Bitte fordere einen neuen Code an.');
        }
        if (!password_verify($cleanCode, $user['otp_code_hash'])) {
            self::recordFailedAttempt();
            throw new RuntimeException('Der eingegebene 6-stellige Sicherheitscode ist ungültig.');
        }

        self::clearFailedAttempts();
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE users SET password_hash = :p, otp_code_hash = NULL, otp_expires_at = NULL WHERE id = :id");
        $upd->execute(array(':p' => $newHash, ':id' => (int)$user['id']));

        return $user['username'];
    }

    /**
     * Maskiert eine E-Mail-Adresse für die datenschutzfreundliche Anzeige im Login-Dialog (z.B. "uw***@domain.de").
     *
     * @param string $email
     * @return string
     */
    public static function maskEmail($email)
    {
        $parts = explode('@', (string)$email);
        if (count($parts) !== 2) {
            return $email;
        }
        $name = $parts[0];
        $dom = $parts[1];
        $len = strlen($name);
        if ($len <= 2) {
            return substr($name, 0, 1) . '***@' . $dom;
        }
        return substr($name, 0, 2) . str_repeat('*', max(2, $len - 2)) . '@' . $dom;
    }

    // =========================================================================
    // RFC 6238 TOTP (Google / Microsoft Authenticator / Bitwarden) Pure-PHP
    // =========================================================================

    /**
     * Erzeugt einen zufälligen Base32-kodierten TOTP-Geheimschlüssel (Standard: 16 Zeichen = 80 Bit).
     *
     * @param int $length Länge des Base32-Strings
     * @return string
     */
    public static function generateTotpSecret($length = 16)
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[self::secureRandomInt(0, 31)];
        }
        return $secret;
    }

    /**
     * Berechnet den 6-stelligen RFC-6238-TOTP-Code für ein gegebenes 30-Sekunden-Zeitfenster.
     *
     * @param string   $secret    Base32-kodiertes TOTP-Secret
     * @param int|null $timeSlice Optionaler 30-Sekunden-Zeitschlitz (Standard: aktuelle Unix-Zeit / 30)
     * @return string 6-stelliger Code
     */
    public static function getTotpCode($secret, $timeSlice = null)
    {
        if ($timeSlice === null) {
            $timeSlice = (int)floor(time() / 30);
        }
        $secretKey = self::base32Decode($secret);
        if ($secretKey === '') {
            return '';
        }
        $time = chr(0) . chr(0) . chr(0) . chr(0) . pack('N*', $timeSlice);
        $hm = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hm, -1)) & 0x0F;
        $hashpart = substr($hm, $offset, 4);
        $value = unpack('N', $hashpart);
        $value = $value[1] & 0x7FFFFFFF;
        $modulo = 1000000;
        return str_pad((string)($value % $modulo), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Prüft einen 6-stelligen TOTP-Code inklusive Toleranzfenster (+/- 1 Zeitschlitz = +/- 30 Sek. Uhrzeitabweichung).
     *
     * @param string $secret      Base32-kodiertes TOTP-Secret
     * @param string $code        Vom Benutzer eingegebener Code
     * @param int    $discrepancy Erlaubte Abweichung in 30-Sekunden-Schritten
     * @return bool
     */
    public static function verifyTotpCode($secret, $code, $discrepancy = 1)
    {
        $cleanCode = preg_replace('/[^0-9]/', '', (string)$code);
        if (strlen($cleanCode) !== 6 || empty($secret)) {
            return false;
        }
        $currentSlice = (int)floor(time() / 30);
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calc = self::getTotpCode($secret, $currentSlice + $i);
            if ($calc !== '' && $calc === $cleanCode) {
                return true;
            }
        }
        return false;
    }

    /**
     * Dekodiert einen Base32-String (RFC 4648) in seine rohe Binärfolge für HMAC-SHA1.
     *
     * @param string $secret
     * @return string
     */
    private static function base32Decode($secret)
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', (string)$secret));
        if ($secret === '') {
            return '';
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $len = strlen($secret);
        for ($i = 0; $i < $len; $i++) {
            $val = strpos($alphabet, $secret[$i]);
            if ($val === false) continue;
            $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        $bitLen = strlen($bits);
        for ($b = 0; $b + 8 <= $bitLen; $b += 8) {
            $out .= chr(bindec(substr($bits, $b, 8)));
        }
        return $out;
    }

    /**
     * Beendet die aktuelle Benutzersitzung vollständig und löscht das Session-Cookie.
     */
    public static function logout()
    {
        self::startSession();
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        @session_destroy();
    }

    /**
     * Prüft, ob der aktuelle Benutzer mindestens eine der geforderten Rollen besitzt
     */
    public static function hasRole($allowedRoles)
    {
        $user = self::getCurrentUser();
        if (!$user) {
            return false;
        }
        if (is_string($allowedRoles)) {
            $allowedRoles = array($allowedRoles);
        }
        return in_array($user['role'], $allowedRoles, true);
    }

    /**
     * Erzwingt eine bestimmte Rolle für API-Aufrufe
     */
    public static function requireRole(array $allowedRoles)
    {
        $user = self::getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(array('ok' => false, 'error' => 'Nicht angemeldet. Bitte zuerst einloggen.'));
            exit;
        }
        if (!in_array($user['role'], $allowedRoles, true)) {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => 'Keine Berechtigung für diese Aktion (Erforderliche Rolle: ' . implode(' oder ', $allowedRoles) . ').'));
            exit;
        }
        return $user;
    }
}
