<?php
/**
 * Smtp — minimalni SMTP klijent bez vanjskih ovisnosti (radi na shared hostingu).
 * Podržava: SSL (port 465), STARTTLS (port 587), bez enkripcije (port 25),
 * AUTH LOGIN. Vraća false + ljudski čitljivu grešku u $err (za admin "test slanja").
 */

class Smtp
{
    public static function send(array $cfg, string $from, string $fromName, string $to, string $subject, string $html, ?string &$err = null): bool
    {
        $err = null;
        $host = trim((string) ($cfg['host'] ?? ''));
        $port = (int) ($cfg['port'] ?? 0) ?: 587;
        $secure = (string) ($cfg['secure'] ?? 'tls'); // ssl | tls | none
        if ($host === '') { $err = 'SMTP poslužitelj (host) nije postavljen.'; return false; }

        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $eno, $estr, 15);
        if (!$fp) {
            $err = "Spajanje na $host:$port nije uspjelo" . ($estr ? " ($estr)" : '') . '. Provjerite host, port i vrstu enkripcije.';
            return false;
        }
        stream_set_timeout($fp, 20);

        $say = function (string $cmd) use ($fp): void { fwrite($fp, $cmd . "\r\n"); };
        $hear = function () use ($fp): array {
            $text = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $text .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') break; // kraj multiline odgovora
            }
            return [(int) substr($text, 0, 3), trim($text)];
        };
        $expect = function (array $codes, string $stage) use ($hear, &$err): bool {
            [$code, $text] = $hear();
            if (!in_array($code, $codes, true)) { $err = "$stage — server kaže: " . ($text ?: 'nema odgovora'); return false; }
            return true;
        };

        try {
            $me = preg_replace('/[^a-z0-9.\-]/i', '', explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]) ?: 'localhost';

            if (!$expect([220], 'Pozdrav servera')) return false;
            $say('EHLO ' . $me);
            if (!$expect([250], 'EHLO')) return false;

            if ($secure === 'tls') {
                $say('STARTTLS');
                if (!$expect([220], 'STARTTLS')) return false;
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $err = 'TLS enkripcija nije uspjela — pokušajte SSL na portu 465.';
                    return false;
                }
                $say('EHLO ' . $me);
                if (!$expect([250], 'EHLO nakon TLS-a')) return false;
            }

            if (!empty($cfg['user'])) {
                $say('AUTH LOGIN');
                if (!$expect([334], 'Prijava (AUTH)')) return false;
                $say(base64_encode((string) $cfg['user']));
                if (!$expect([334], 'Korisničko ime')) return false;
                $say(base64_encode((string) ($cfg['pass'] ?? '')));
                if (!$expect([235], 'Lozinka odbijena')) return false;
            }

            $say('MAIL FROM:<' . $from . '>');
            if (!$expect([250], 'Pošiljatelj (MAIL FROM)')) return false;
            $say('RCPT TO:<' . $to . '>');
            if (!$expect([250, 251], 'Primatelj (RCPT TO)')) return false;
            $say('DATA');
            if (!$expect([354], 'DATA')) return false;

            $headers = 'Date: ' . date('r') . "\r\n"
                . 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . ">\r\n"
                . 'To: <' . $to . ">\r\n"
                . 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8') . "\r\n"
                . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $me . ">\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n"
                . "X-Mailer: DjurdjaShop\r\n";
            $body = preg_replace('/^\./m', '..', $html); // SMTP dot-stuffing
            fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
            if (!$expect([250], 'Slanje poruke')) return false;
            $say('QUIT');
            return true;
        } finally {
            fclose($fp);
        }
    }
}
