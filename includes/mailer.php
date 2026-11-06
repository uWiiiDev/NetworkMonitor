<?php
/**
 * SmtpMailer - Leichtgewichtiger Pure-PHP SMTP-Client (ohne externe Bibliotheken)
 * Unterstützt Plain SMTP (Port 25), STARTTLS (Port 587) und Implizites SSL/TLS (Port 465)
 * Kompatibel mit PHP 5.5+ (Ubuntu 14.04) bis PHP 8.x
 */

class SmtpMailer
{
    private $config;

    public function __construct(array $smtpConfig)
    {
        $this->config = array_merge(array(
            'enabled'    => 0,
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls', // 'tls' (STARTTLS), 'ssl' (Port 465), 'none'
            'username'   => '',
            'password'   => '',
            'from_email' => '',
            'from_name'  => 'NetworkMonitor Alarm',
            'to_email'   => '',
        ), $smtpConfig);
    }

    /**
     * Sendet eine E-Mail über den konfigurierten SMTP-Server
     */
    public function sendMail($subject, $htmlBody, $textBody = '')
    {
        $host = trim((string)$this->config['host']);
        $port = (int)$this->config['port'];
        $enc  = strtolower(trim((string)$this->config['encryption']));
        $user = trim((string)$this->config['username']);
        $pass = (string)$this->config['password'];
        $fromEmail = trim((string)$this->config['from_email']);
        $fromName  = trim((string)$this->config['from_name']);
        $toRaw     = trim((string)$this->config['to_email']);

        if ($host === '' || $port <= 0) {
            throw new RuntimeException('SMTP-Host oder Port ist nicht konfiguriert.');
        }
        if ($fromEmail === '') {
            $fromEmail = ($user !== '' && strpos($user, '@') !== false) ? $user : 'networkmonitor@localhost';
        }
        if ($toRaw === '') {
            throw new RuntimeException('Keine Empfänger-E-Mail-Adresse konfiguriert.');
        }

        $recipients = array();
        foreach (preg_split('/[,;]+/', $toRaw) as $r) {
            $r = trim($r);
            if ($r !== '') {
                $recipients[] = $r;
            }
        }
        if (empty($recipients)) {
            throw new RuntimeException('Keine gültige Empfänger-E-Mail-Adresse gefunden.');
        }

        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(array(
            'ssl' => array(
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ),
        ));

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException("SMTP-Verbindung zu $host:$port fehlgeschlagen: $errstr ($errno)");
        }
        stream_set_timeout($fp, 10);

        try {
            $this->expectCode($fp, array(220), 'SMTP Greeting');
            $ehloHost = gethostname() ?: 'localhost';
            $this->sendCmd($fp, "EHLO " . $ehloHost, array(250));

            if ($enc === 'tls') {
                $this->sendCmd($fp, "STARTTLS", array(220));
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
                }
                if (!@stream_socket_enable_crypto($fp, true, $cryptoMethod)) {
                    throw new RuntimeException('STARTTLS-Verschlüsselung mit dem SMTP-Server konnte nicht aufgebaut werden.');
                }
                $this->sendCmd($fp, "EHLO " . $ehloHost, array(250));
            }

            if ($user !== '') {
                $this->sendCmd($fp, "AUTH LOGIN", array(334));
                $this->sendCmd($fp, base64_encode($user), array(334));
                $this->sendCmd($fp, base64_encode($pass), array(235));
            }

            $this->sendCmd($fp, "MAIL FROM:<" . $fromEmail . ">", array(250));
            foreach ($recipients as $rcpt) {
                $this->sendCmd($fp, "RCPT TO:<" . $rcpt . ">", array(250, 251));
            }

            $this->sendCmd($fp, "DATA", array(354));

            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName ?: 'NetworkMonitor') . '?=';

            $headers = array(
                'Date: ' . date('r'),
                'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
                'To: ' . implode(', ', $recipients),
                'Subject: ' . $encodedSubject,
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                'X-Mailer: NetworkMonitor',
            );

            $dataPayload = implode("\r\n", $headers) . "\r\n\r\n"
                         . chunk_split(base64_encode($htmlBody), 76, "\r\n")
                         . "\r\n.";

            $this->sendCmd($fp, $dataPayload, array(250));
            @fwrite($fp, "QUIT\r\n");
            @fclose($fp);
            return true;
        } catch (Exception $e) {
            @fclose($fp);
            throw $e;
        }
    }

    private function sendCmd($fp, $cmd, array $expectedCodes)
    {
        @fwrite($fp, $cmd . "\r\n");
        return $this->expectCode($fp, $expectedCodes, $cmd);
    }

    private function expectCode($fp, array $expectedCodes, $context)
    {
        $response = '';
        while (($line = @fgets($fp, 515)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            $cleanContext = (strpos($context, 'AUTH') === 0 || strlen($context) > 40) ? 'SMTP-Befehl' : $context;
            throw new RuntimeException("SMTP-Fehler bei '$cleanContext': Server antwortete mit '$response'");
        }
        return $response;
    }
}
