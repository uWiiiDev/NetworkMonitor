<?php
/**
 * NetworkMonitor - Login & Authentifizierung
 * Kompatibel mit PHP 5.5+ (Ubuntu 14.04) bis PHP 8.x
 */

require_once __DIR__ . '/includes/auth.php';

Auth::startSession();

if (isset($_GET['logout'])) {
    Auth::logout();
    header('Location: login.php');
    exit;
}

if (Auth::getCurrentUser() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    try {
        if (Auth::login($username, $password)) {
            header('Location: index.php');
            exit;
        } else {
            $error = 'Ungültiger Benutzername oder Passwort.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anmeldung – NetworkMonitor</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at center, #1e293b 0%, #090d16 100%);
            height: 100vh;
        }
        .login-card {
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            width: 420px;
            max-width: 94vw;
            padding: 28px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.55);
        }
        .login-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 22px;
        }
        .login-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fca5a5;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 16px;
        }
        .role-hint-box {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
            font-size: 12px;
            color: var(--text-secondary);
        }
        .quick-role-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 11.5px;
            cursor: pointer;
            text-align: left;
            width: 100%;
            margin-top: 6px;
            display: flex;
            justify-content: space-between;
        }
        .quick-role-btn:hover {
            border-color: var(--accent);
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="login-header">
            <div class="brand-logo" style="width:40px;height:40px;font-size:17px;">NM</div>
            <div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:18px;font-weight:700;">NetworkMonitor</span>
                    <span class="badge" style="background:rgba(59,130,246,0.18);border:1px solid rgba(59,130,246,0.45);color:#93c5fd;font-family:monospace;font-size:11px;">v<?php echo htmlspecialchars(Database::getAppVersion(), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div style="font-size:12.5px;color:var(--text-secondary);">Geschützter Netzwerkplan &amp; SNMP Discovery</div>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" style="display:flex;flex-direction:column;gap:14px;">
            <div class="form-group">
                <label>Benutzername</label>
                <input type="text" id="login-user" name="username" class="form-input" required autofocus placeholder="z.B. admin">
            </div>
            <div class="form-group">
                <label>Passwort</label>
                <input type="password" id="login-pass" name="password" class="form-input" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn btn-primary" style="justify-content:center;padding:10px;font-size:14px;margin-top:4px;">
                Anmelden
            </button>
        </form>

        <div class="role-hint-box">
            <div style="font-weight:600;color:var(--text-primary);margin-bottom:4px;">Vorab angelegte Zugänge zum Testen der 4 Rollen:</div>
            <button type="button" class="quick-role-btn" onclick="fillLogin('admin','admin123')">
                <span><b>admin</b> / admin123</span>
                <span style="color:#34d399;">Administrator (Vollzugriff + DB)</span>
            </button>
            <button type="button" class="quick-role-btn" onclick="fillLogin('netadmin','netadmin123')">
                <span><b>netadmin</b> / netadmin123</span>
                <span style="color:#c084fc;">Netzwerkexperte (Core-Switches &amp; VLANs)</span>
            </button>
            <button type="button" class="quick-role-btn" onclick="fillLogin('operator','operator123')">
                <span><b>operator</b> / operator123</span>
                <span style="color:#60a5fa;">Operator (Scans &amp; Access-Ports, ohne Core)</span>
            </button>
            <button type="button" class="quick-role-btn" onclick="fillLogin('gast','gast123')">
                <span><b>gast</b> / gast123</span>
                <span style="color:#94a3b8;">Viewer (Nur Lesen, ohne Core)</span>
            </button>
        </div>
    </div>

    <script>
        function fillLogin(u, p) {
            document.getElementById('login-user').value = u;
            document.getElementById('login-pass').value = p;
        }
    </script>
</body>
</html>
