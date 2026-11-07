<?php
/**
 * Authentifizierung & Rollen-/Rechte-Verwaltung (RBAC)
 * Rollen:
 *   - 'viewer'   : Nur Lesen (Netzwerkplan ansehen, filtern, exportieren – KEINE Core-Switches sichtbar)
 *   - 'operator' : Techniker (Scans starten, Standard-Geräte & Access-Switch-Ports bearbeiten – KEINE Core-Switches sichtbar/administrierbar)
 *   - 'netadmin' : Netzwerkexperte (Vollzugriff auf alle Switches inkl. Core-Switches, Trunk-Ports, VLANs & Switch-Credentials)
 *   - 'admin'    : System-Administrator (Vollzugriff inkl. Core-Switches, Datenbank-Konfiguration, SMTP & Benutzerverwaltung)
 *
 * Kompatibel mit PHP 5.5+ (Ubuntu 14.04) bis PHP 8.x
 */

require_once __DIR__ . '/db.php';

class Auth
{
    public static function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    public static function getCurrentUser()
    {
        self::startSession();
        if (empty($_SESSION['nm_user_id'])) {
            return null;
        }
        $pdo = Database::getInstance()->getPdo();
        $stmt = $pdo->prepare("SELECT id, username, full_name, role, is_active, last_login FROM users WHERE id = :id");
        $stmt->execute(array(':id' => (int)$_SESSION['nm_user_id']));
        $user = $stmt->fetch();
        if (!$user || (int)$user['is_active'] !== 1) {
            self::logout();
            return null;
        }
        return $user;
    }

    public static function login($username, $password)
    {
        self::startSession();
        $pdo = Database::getInstance()->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
        $stmt->execute(array(':u' => trim($username)));
        $user = $stmt->fetch();

        if (!$user) {
            return false;
        }
        if ((int)$user['is_active'] !== 1) {
            throw new RuntimeException('Dieses Benutzerkonto ist deaktiviert.');
        }
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        if (function_exists('session_regenerate_id')) {
            @session_regenerate_id(true);
        }
        $_SESSION['nm_user_id'] = (int)$user['id'];
        $_SESSION['nm_username'] = $user['username'];
        $_SESSION['nm_role'] = $user['role'];

        $pdo->prepare("UPDATE users SET last_login = :now WHERE id = :id")
            ->execute(array(':now' => date('Y-m-d H:i:s'), ':id' => $user['id']));

        return true;
    }

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
