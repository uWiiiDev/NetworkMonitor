<?php
/**
 * NetworkMonitor - Login, 2-Faktor-Authentifizierung (2FA) & Passwort-Reset
 * Kompatibel mit PHP 5.5+ (Ubuntu 14.04) bis PHP 8.x
 */

require_once __DIR__ . '/includes/auth.php';

Auth::startSession();

if (isset($_GET['logout']) || isset($_GET['cancel_2fa'])) {
    Auth::logout();
    header('Location: login.php');
    exit;
}

if (Auth::getCurrentUser() !== null) {
    header('Location: index.php');
    exit;
}

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'login';
$pending2fa = Auth::getPending2faUser();
if ($pending2fa !== null && $mode !== 'forgot' && $mode !== 'reset') {
    $mode = '2fa';
}

$error = '';
$info = '';
$resetIdentifier = isset($_GET['ident']) ? trim($_GET['ident']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = isset($_POST['form_action']) ? $_POST['form_action'] : 'login';

    try {
        if ($formAction === 'login') {
            $username = isset($_POST['username']) ? trim($_POST['username']) : '';
            $password = isset($_POST['password']) ? $_POST['password'] : '';
            $res = Auth::login($username, $password);
            if ($res === 'ok') {
                header('Location: index.php');
                exit;
            } elseif ($res === '2fa_required') {
                $pending2fa = Auth::getPending2faUser();
                $mode = '2fa';
                if ($pending2fa && $pending2fa['mode'] === 'email') {
                    $info = 'Ein 6-stelliger Sicherheitscode wurde an ' . Auth::maskEmail($pending2fa['email']) . ' gesendet.';
                }
            } else {
                $error = 'Ungültiger Benutzername/E-Mail oder Passwort.';
            }
        } elseif ($formAction === 'verify_2fa') {
            $code = isset($_POST['otp_code']) ? trim($_POST['otp_code']) : '';
            if (Auth::verify2faCode($code)) {
                header('Location: index.php');
                exit;
            }
        } elseif ($formAction === 'resend_2fa') {
            $masked = Auth::resendEmail2faCode();
            $pending2fa = Auth::getPending2faUser();
            $mode = '2fa';
            $info = 'Neuer 6-stelliger Sicherheitscode wurde an ' . $masked . ' gesendet.';
        } elseif ($formAction === 'forgot_request') {
            $ident = isset($_POST['identifier']) ? trim($_POST['identifier']) : '';
            $masked = Auth::sendPasswordResetCode($ident);
            $mode = 'reset';
            $resetIdentifier = $ident;
            $info = 'Ein 6-stelliger Zurücksetzungscode wurde an ' . $masked . ' gesendet (15 Min. gültig).';
        } elseif ($formAction === 'forgot_reset') {
            $ident = isset($_POST['identifier']) ? trim($_POST['identifier']) : '';
            $code = isset($_POST['otp_code']) ? trim($_POST['otp_code']) : '';
            $newPass = isset($_POST['new_password']) ? $_POST['new_password'] : '';
            $resetIdentifier = $ident;
            $mode = 'reset';
            $uname = Auth::resetPasswordWithCode($ident, $code, $newPass);
            $mode = 'login';
            $info = 'Passwort für "' . $uname . '" erfolgreich zurückgesetzt! Sie können sich jetzt anmelden.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        if ($formAction === 'forgot_request') {
            $mode = 'forgot';
        } elseif ($formAction === 'forgot_reset') {
            $mode = 'reset';
        } elseif ($formAction === 'verify_2fa' || $formAction === 'resend_2fa') {
            $pending2fa = Auth::getPending2faUser();
            $mode = $pending2fa ? '2fa' : 'login';
        }
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
            min-height: 100vh;
            padding: 16px;
        }
        .login-card {
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            width: 430px;
            max-width: 95vw;
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
        .login-info {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.4);
            color: #6ee7b7;
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
        .login-link {
            color: #60a5fa;
            text-decoration: none;
            font-size: 12.5px;
        }
        .login-link:hover {
            text-decoration: underline;
        }
        .otp-input {
            font-family: monospace;
            font-size: 20px;
            letter-spacing: 6px;
            text-align: center;
            font-weight: 700;
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

        <?php if ($info !== ''): ?>
            <div class="login-info"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($mode === '2fa' && $pending2fa !== null): ?>
            <div style="margin-bottom:14px;">
                <div style="font-weight:600;font-size:14.5px;margin-bottom:4px;">🔐 Zwei-Faktor-Authentifizierung (2FA)</div>
                <div style="font-size:12.5px;color:var(--text-secondary);">
                    <?php if ($pending2fa['mode'] === 'totp'): ?>
                        Bitte geben Sie den aktuellen 6-stelligen Code aus Ihrer <b>Authenticator-App</b> für <b><?php echo htmlspecialchars($pending2fa['username'], ENT_QUOTES, 'UTF-8'); ?></b> ein:
                    <?php else: ?>
                        Bitte geben Sie den 6-stelligen Sicherheitscode ein, der per E-Mail an <b><?php echo htmlspecialchars(Auth::maskEmail($pending2fa['email']), ENT_QUOTES, 'UTF-8'); ?></b> gesendet wurde:
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" action="login.php?mode=2fa" style="display:flex;flex-direction:column;gap:14px;">
                <input type="hidden" name="form_action" value="verify_2fa">
                <div class="form-group">
                    <label>6-stelliger Sicherheitscode</label>
                    <input type="text" name="otp_code" class="form-input otp-input" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required autofocus placeholder="000000">
                </div>
                <button type="submit" class="btn btn-primary" style="justify-content:center;padding:10px;font-size:14px;">
                    ✅ Code bestätigen &amp; Anmelden
                </button>
            </form>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;padding-top:12px;border-top:1px solid var(--border-color);">
                <?php if ($pending2fa['mode'] === 'email'): ?>
                    <form method="post" action="login.php?mode=2fa" style="margin:0;">
                        <input type="hidden" name="form_action" value="resend_2fa">
                        <button type="submit" class="btn btn-secondary" style="padding:5px 10px;font-size:12px;">📧 Code erneut senden</button>
                    </form>
                <?php else: ?>
                    <span style="font-size:11.5px;color:var(--text-secondary);">RFC 6238 TOTP (30s)</span>
                <?php endif; ?>
                <a href="login.php?cancel_2fa=1" class="login-link">Abbrechen / Anderes Konto</a>
            </div>

        <?php elseif ($mode === 'forgot'): ?>
            <div style="margin-bottom:14px;">
                <div style="font-weight:600;font-size:14.5px;margin-bottom:4px;">🔑 Passwort zurücksetzen</div>
                <div style="font-size:12.5px;color:var(--text-secondary);">
                    Geben Sie Ihren Benutzernamen oder Ihre hinterlegte E-Mail-Adresse ein. Sie erhalten einen 6-stelligen Sicherheitscode per E-Mail.
                </div>
            </div>

            <form method="post" action="login.php?mode=forgot" style="display:flex;flex-direction:column;gap:14px;">
                <input type="hidden" name="form_action" value="forgot_request">
                <div class="form-group">
                    <label>Benutzername oder E-Mail-Adresse</label>
                    <input type="text" name="identifier" class="form-input" required autofocus placeholder="z.B. admin oder name@firma.de" value="<?php echo htmlspecialchars($resetIdentifier, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="justify-content:center;padding:10px;font-size:14px;">
                    📧 Sicherheitscode per E-Mail anfordern
                </button>
            </form>

            <div style="display:flex;justify-content:space-between;margin-top:14px;padding-top:12px;border-top:1px solid var(--border-color);">
                <a href="login.php?mode=reset" class="login-link">Bereits einen Code erhalten?</a>
                <a href="login.php" class="login-link">← Zurück zur Anmeldung</a>
            </div>

        <?php elseif ($mode === 'reset'): ?>
            <div style="margin-bottom:14px;">
                <div style="font-weight:600;font-size:14.5px;margin-bottom:4px;">🔑 Neues Passwort vergeben</div>
                <div style="font-size:12.5px;color:var(--text-secondary);">
                    Bitte geben Sie den 6-stelligen Sicherheitscode aus der E-Mail sowie Ihr neues Wunschpasswort ein.
                </div>
            </div>

            <form method="post" action="login.php?mode=reset" style="display:flex;flex-direction:column;gap:12px;">
                <input type="hidden" name="form_action" value="forgot_reset">
                <div class="form-group">
                    <label>Benutzername oder E-Mail-Adresse</label>
                    <input type="text" name="identifier" class="form-input" required placeholder="z.B. admin oder name@firma.de" value="<?php echo htmlspecialchars($resetIdentifier, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label>6-stelliger Sicherheitscode (aus der E-Mail)</label>
                    <input type="text" name="otp_code" class="form-input otp-input" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" required autofocus placeholder="000000">
                </div>
                <div class="form-group">
                    <label>Neues Passwort (mind. 4 Zeichen)</label>
                    <input type="password" name="new_password" class="form-input" required placeholder="••••••••">
                </div>
                <button type="submit" class="btn btn-primary" style="justify-content:center;padding:10px;font-size:14px;margin-top:4px;">
                    💾 Passwort jetzt speichern
                </button>
            </form>

            <div style="display:flex;justify-content:space-between;margin-top:14px;padding-top:12px;border-top:1px solid var(--border-color);">
                <a href="login.php?mode=forgot" class="login-link">Neuen Code anfordern</a>
                <a href="login.php" class="login-link">← Zurück zur Anmeldung</a>
            </div>

        <?php else: ?>
            <form method="post" action="login.php" style="display:flex;flex-direction:column;gap:14px;">
                <input type="hidden" name="form_action" value="login">
                <div class="form-group">
                    <label>Benutzername oder E-Mail</label>
                    <input type="text" id="login-user" name="username" class="form-input" required autofocus placeholder="z.B. admin oder name@firma.de">
                </div>
                <div class="form-group">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <label style="margin-bottom:0;">Passwort</label>
                        <a href="login.php?mode=forgot" class="login-link" style="font-size:11.5px;">Passwort vergessen?</a>
                    </div>
                    <input type="password" id="login-pass" name="password" class="form-input" required placeholder="••••••••" style="margin-top:5px;">
                </div>
                <button type="submit" class="btn btn-primary" style="justify-content:center;padding:10px;font-size:14px;margin-top:4px;">
                    Anmelden
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
