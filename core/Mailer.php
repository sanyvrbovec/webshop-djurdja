<?php
/**
 * Mailer — HTML e-mailovi kroz odabrani driver:
 *   mail  → PHP mail() (radi na većini shared hostinga bez podešavanja)
 *   smtp  → core/Smtp.php (cPanel mail račun, Gmail s app lozinkom, Outlook…)
 * Postavke žive u settings (admin → E-mail postavke), SMTP lozinka enkriptirana.
 */

class Mailer
{
    /** Pošalji e-mail. U $err vraća ljudski čitljivu grešku (null = uspjeh). */
    public static function send(string $to, string $subject, string $htmlBody, ?string &$err = null): bool
    {
        $err = null;
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $err = 'Neispravna e-mail adresa primatelja.'; return false; }

        $from = s('mail_from') ?: s('shop_email', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromName = s('mail_from_name') ?: shop_name();
        $html = self::wrap($htmlBody);

        if (s('mail_driver', 'mail') === 'smtp') {
            return Smtp::send([
                'host'   => (string) s('smtp_host', ''),
                'port'   => (int) s('smtp_port', 587),
                'secure' => (string) s('smtp_secure', 'tls'),
                'user'   => (string) s('smtp_user', ''),
                'pass'   => (string) (Crypto::decrypt(s('smtp_pass_enc')) ?? ''),
            ], $from, $fromName, $to, $subject, $html, $err);
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'X-Mailer: DjurdjaShop',
        ];
        $ok = @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $html, implode("\r\n", $headers));
        if (!$ok) {
            $err = 'PHP mail() nije uspio — ovaj hosting vjerojatno traži SMTP. Otvorite E-mail postavke i odaberite SMTP.';
        }
        return $ok;
    }

    /** Testna poruka za admin (vraća ['ok'=>bool, 'error'=>?string]). */
    public static function test(string $to): array
    {
        $ok = self::send(
            $to,
            'Testna poruka — ' . shop_name(),
            '<h2 style="margin:0 0 8px">Sve radi! ✅</h2>'
            . '<p>Ovo je testna poruka iz vaše trgovine <strong>' . e(shop_name()) . '</strong>.</p>'
            . '<p style="color:#6b7280;font-size:13px">Driver: <code>' . e(s('mail_driver', 'mail')) . '</code> · ' . e(date('d.m.Y H:i:s')) . '</p>',
            $err
        );
        return ['ok' => $ok, 'error' => $err];
    }

    /** Brendirani omotač za sve mailove. */
    private static function wrap(string $inner): string
    {
        $shop = e(shop_name());
        $url = defined('SITE_URL') ? SITE_URL : '';
        return '<!doctype html><html><body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,Helvetica,sans-serif">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.06)">'
            . '<tr><td style="background:#1f2937;padding:22px 30px"><a href="' . e($url) . '" style="color:#fff;font-size:20px;font-weight:bold;text-decoration:none">' . $shop . '</a></td></tr>'
            . '<tr><td style="padding:30px;color:#374151;font-size:14px;line-height:1.65">' . $inner . '</td></tr>'
            . '<tr><td style="padding:18px 30px;background:#f9fafb;color:#9ca3af;font-size:12px">' . $shop . ' · Ovo je automatska poruka, ne odgovarajte na nju.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    public static function orderConfirmation(array $order, array $items): bool
    {
        $rows = '';
        foreach ($items as $it) {
            $nm = $it['display_name'] ?? ($it['name'] . (!empty($it['variant_label']) ? ' — ' . $it['variant_label'] : ''));
            $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #f3f4f6">' . e($nm)
                . ' <span style="color:#9ca3af">× ' . (int) $it['qty'] . '</span></td>'
                . '<td align="right" style="padding:8px 0;border-bottom:1px solid #f3f4f6;white-space:nowrap">' . fmt_price($it['line_total']) . '</td></tr>';
        }
        if ((float) $order['shipping_cost'] > 0) {
            $rows .= '<tr><td style="padding:8px 0;color:#6b7280">Dostava</td><td align="right" style="padding:8px 0">' . fmt_price($order['shipping_cost']) . '</td></tr>';
        }
        if ((float) $order['payment_fee'] > 0) {
            $rows .= '<tr><td style="padding:8px 0;color:#6b7280">Naknada plaćanja</td><td align="right" style="padding:8px 0">' . fmt_price($order['payment_fee']) . '</td></tr>';
        }

        $statusUrl = SITE_URL . '/narudzba-potvrda.php?t=' . urlencode($order['guest_token']);
        $payInfo = '';
        if ($order['payment_method'] === 'cod') {
            $payInfo = '<p>Iznos <strong>' . fmt_price($order['total']) . '</strong> platite dostavljaču prilikom preuzimanja.</p>';
        }

        $html = '<h2 style="margin:0 0 6px;color:#111827">Hvala na narudžbi! 🎉</h2>'
            . '<p>Vaša narudžba <strong>' . e($order['order_number']) . '</strong> je zaprimljena.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:14px 0">' . $rows
            . '<tr><td style="padding:12px 0;font-size:16px"><strong>Ukupno</strong></td><td align="right" style="padding:12px 0;font-size:16px"><strong>' . fmt_price($order['total']) . '</strong></td></tr></table>'
            . $payInfo
            . '<p style="margin-top:22px"><a href="' . e($statusUrl) . '" style="background:#1f2937;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Pregled narudžbe</a></p>';

        $ok = self::send($order['customer_email'], 'Potvrda narudžbe ' . $order['order_number'], $html);

        // Obavijest vlasniku trgovine
        $adminHtml = '<h3 style="margin:0 0 8px">Nova narudžba ' . e($order['order_number']) . '</h3>'
            . '<p>' . e($order['customer_name']) . ' · ' . e($order['customer_email']) . ' · ' . e($order['customer_phone'] ?? '') . '<br>'
            . e($order['address']) . ', ' . e($order['postal_code']) . ' ' . e($order['city']) . '<br>'
            . 'Plaćanje: ' . e(Orders::paymentLabel($order['payment_method'])) . ' · Ukupno: <strong>' . fmt_price($order['total']) . '</strong></p>'
            . '<table role="presentation" width="100%">' . $rows . '</table>';
        @self::send(s('shop_email', ''), 'Nova narudžba ' . $order['order_number'], $adminHtml);

        return $ok;
    }

    /**
     * Pošalji kupcu fiskalizirani račun (HTML verzija printanog računa).
     * @param array $order red iz orders (mora biti fiscalized ili stornoed)
     */
    public static function fiscalReceipt(array $order, ?string &$err = null): bool
    {
        $db = Database::instance();
        $items = $db->fetchAll('SELECT * FROM order_items WHERE order_id = :o', [':o' => $order['id']]);
        $company = Djurdja::company();
        $inVat = !empty($company['inVatSystem']);

        $rows = '';
        $byRate = [];
        foreach ($items as $it) {
            $nm = $it['name'] . (!empty($it['variant_label']) ? ' — ' . $it['variant_label'] : '');
            $rows .= '<tr><td style="padding:6px 0;border-bottom:1px solid #f3f4f6">' . e($nm) . '</td>'
                . '<td align="right" style="padding:6px 0;border-bottom:1px solid #f3f4f6">' . (int) $it['quantity'] . '</td>'
                . '<td align="right" style="padding:6px 0;border-bottom:1px solid #f3f4f6;white-space:nowrap">' . number_format((float) $it['unit_price'], 2, ',', '.') . '</td>'
                . '<td align="right" style="padding:6px 0;border-bottom:1px solid #f3f4f6;white-space:nowrap">' . number_format((float) $it['total'], 2, ',', '.') . '</td></tr>';
            $r = (string) round((float) $it['vat_rate'], 2);
            $byRate[$r] = ($byRate[$r] ?? 0) + (float) $it['total'];
        }
        $extra = (float) $order['shipping_cost'] + (float) $order['payment_fee'];
        if ($extra > 0) {
            $r = (string) round((float) s('shipping_vat_rate', '25'), 2);
            $byRate[$r] = ($byRate[$r] ?? 0) + $extra;
        }
        if ((float) $order['shipping_cost'] > 0) {
            $rows .= '<tr><td style="padding:6px 0;color:#6b7280">Dostava</td><td align="right">1</td><td align="right">' . number_format((float) $order['shipping_cost'], 2, ',', '.') . '</td><td align="right">' . number_format((float) $order['shipping_cost'], 2, ',', '.') . '</td></tr>';
        }
        if ((float) $order['payment_fee'] > 0) {
            $rows .= '<tr><td style="padding:6px 0;color:#6b7280">Naknada plaćanja</td><td align="right">1</td><td align="right">' . number_format((float) $order['payment_fee'], 2, ',', '.') . '</td><td align="right">' . number_format((float) $order['payment_fee'], 2, ',', '.') . '</td></tr>';
        }

        $vatTable = '';
        if ($inVat) {
            $vatRows = '';
            foreach ($byRate as $rate => $gross) {
                $r = (float) $rate;
                $base = $r > 0 ? round($gross / (1 + $r / 100), 2) : round($gross, 2);
                $vat = round($gross - $base, 2);
                $vatRows .= '<tr><td style="padding:4px 0">' . rtrim(rtrim(number_format($r, 2, ',', ''), '0'), ',') . '%</td>'
                    . '<td align="right">' . number_format($base, 2, ',', '.') . '</td>'
                    . '<td align="right">' . number_format($vat, 2, ',', '.') . '</td>'
                    . '<td align="right">' . number_format($gross, 2, ',', '.') . '</td></tr>';
            }
            $vatTable = '<table role="presentation" width="100%" style="font-size:12.5px;color:#4b5563;margin:10px 0">'
                . '<tr style="font-size:11px;text-transform:uppercase;color:#9ca3af"><td>PDV stopa</td><td align="right">Osnovica</td><td align="right">PDV</td><td align="right">Ukupno</td></tr>'
                . $vatRows . '</table>';
        } else {
            $vatTable = '<p style="color:#6b7280;font-size:12px">PDV nije obračunat — prodavatelj nije u sustavu PDV-a (čl. 90. Zakona o PDV-u).</p>';
        }

        $testWarn = ($order['fiscal_mode'] === 'test')
            ? '<div style="border:2px solid #f59e0b;background:#fffbeb;color:#92400e;padding:10px;border-radius:8px;margin:0 0 14px;font-weight:bold;text-align:center">TESTNI RAČUN — izdan u probnom načinu rada, nije pravovaljan.</div>'
            : '';
        $stornoWarn = ($order['fiscal_status'] === 'stornoed')
            ? '<div style="border:2px solid #dc2626;color:#dc2626;padding:10px;border-radius:8px;margin:0 0 14px;font-weight:bold;text-align:center">STORNIRANO — storno račun br. ' . e($order['fiscal_storno_receipt_number']) . '</div>'
            : '';

        $logo = Djurdja::receiptLogoUrl();
        $html = $testWarn . $stornoWarn
            . '<div style="text-align:center;margin-bottom:14px">'
            . ($logo ? '<img src="' . e($logo) . '" alt="" style="max-height:64px;max-width:200px;margin:0 auto 8px;display:block">' : '')
            . '<h2 style="margin:0">' . e($company['companyName'] ?? shop_name()) . '</h2>'
            . '<div style="color:#6b7280;font-size:12.5px">'
            . (!empty($company['address']) ? e($company['address']) . ', ' . e($company['postalCode'] ?? '') . ' ' . e($company['city'] ?? '') . '<br>' : '')
            . 'OIB: ' . e($company['companyOib'] ?? '—') . ' · ' . ($inVat ? 'U sustavu PDV-a' : 'Nije u sustavu PDV-a') . '</div></div>'
            . '<p style="margin:6px 0"><strong>RAČUN br. ' . e($order['fiscal_receipt_number']) . '</strong><br>'
            . '<span style="color:#6b7280;font-size:12.5px">Narudžba ' . e($order['order_number']) . ' · ' . e($order['fiscalized_at'])
            . ' · Plaćanje: ' . e(Orders::paymentLabel($order['payment_method'])) . ' · Valuta: EUR</span></p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:10px 0">'
            . '<tr style="font-size:11px;text-transform:uppercase;color:#9ca3af"><td>Artikl</td><td align="right">Kol.</td><td align="right">Cijena</td><td align="right">Iznos</td></tr>'
            . $rows
            . '<tr><td colspan="3" style="padding:12px 0;font-size:16px;border-top:2px solid #111"><strong>UKUPNO</strong></td>'
            . '<td align="right" style="padding:12px 0;font-size:16px;border-top:2px solid #111;white-space:nowrap"><strong>' . fmt_price($order['total']) . '</strong></td></tr></table>'
            . $vatTable
            . '<div style="border:1px dashed #9ca3af;border-radius:8px;padding:12px;font-size:11.5px;color:#374151;word-break:break-all;margin:14px 0">'
            . '<strong>FISKALNI PODACI</strong><br>JIR: ' . e($order['fiscal_jir']) . '<br>ZKI: ' . e($order['fiscal_zki'])
            . ($order['fiscal_qr'] ? '<br>Provjera računa: <a href="' . e($order['fiscal_qr']) . '">' . e($order['fiscal_qr']) . '</a>' : '')
            . '</div>'
            . '<p style="color:#9ca3af;font-size:12px;text-align:center">Hvala na kupnji! · ' . e(shop_name()) . '</p>'
            . (Djurdja::brandingRequired()
                ? '<p style="color:#9ca3af;font-size:11.5px;text-align:center;border-top:1px solid #f3f4f6;padding-top:10px">Račun izdan putem besplatnog sustava <a href="https://mojadjurdja.com/?utm_source=webshop&utm_medium=receipt&utm_campaign=poweredby" style="color:#6b7280">MojaĐurđa</a></p>'
                : '');

        return self::send(
            $order['customer_email'],
            'Račun ' . $order['fiscal_receipt_number'] . ' — ' . shop_name(),
            $html,
            $err
        );
    }
}
