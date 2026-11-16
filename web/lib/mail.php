<?php
declare(strict_types=1);
/*
 * wohlbekannt-Erweiterung: E-Mail-Benachrichtigungen.
 * Jede App-Mitteilung (notify) wird zusätzlich per E-Mail verschickt, sofern unter Unternehmen aktiviert
 * und der Empfänger sie in "Mein Konto" nicht abbestellt hat. Versand über PHP mail() des Hosters
 * (All-Inkl), erst NACH erfolgreichem Speichern (Commit), damit bei Fehlern keine Mails rausgehen.
 */

function mail_enabled(): bool { return setting('mail_enabled', '0') === '1' && filter_var(setting('mail_from'), FILTER_VALIDATE_EMAIL) !== false; }
function mail_optout_users(): array { return array_values(array_filter(array_map('intval', explode(',', setting('mail_optout'))))); }

function mail_queue(int $uid, string $text): void {
    if (!mail_enabled() || in_array($uid, mail_optout_users(), true)) return;
    $u = one('users', 'id=? AND active=1', [$uid]);
    if (!$u || !filter_var($u['email'], FILTER_VALIDATE_EMAIL)) return;
    $GLOBALS['mail_queue'][] = [$u['email'], $u['name'], $text];
}

function mail_send(string $to, string $name, string $text): bool {
    $company = setting('company', 'MaStunden');
    $from = setting('mail_from');
    $url = qr_base_url();
    $subject = '=?UTF-8?B?' . base64_encode('Zeiterfassung: ' . mb_strimwidth($text, 0, 90, '…')) . '?=';
    $body = 'Hallo ' . explode(' ', $name)[0] . ",\r\n\r\n" . $text . "\r\n\r\nDetails in der Zeiterfassung: " . $url . "\r\n\r\n-- \r\n" . $company
        . "\r\nDiese Nachricht wurde automatisch versendet. E-Mails abbestellen: Zeiterfassung → Mein Konto.\r\n";
    $headers = 'From: =?UTF-8?B?' . base64_encode($company . ' Zeiterfassung') . '?= <' . $from . ">\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nAuto-Submitted: auto-generated";
    return @mail($to, $subject, $body, $headers, '-f' . $from);
}

/** Nach dem Commit aufrufen. Fehler beim Versand blockieren die Anwendung nie. */
function mail_flush(): void {
    foreach ($GLOBALS['mail_queue'] ?? [] as [$to, $name, $text]) {
        if (!mail_send($to, $name, $text)) error_log(gmdate('c') . " mail failed\n", 3, cfg()['storage'] . '/errors.log');
    }
    $GLOBALS['mail_queue'] = [];
}

function mail_admin_action(string $a): void {
    require_admin();
    if ($a === 'mail_test') {
        $me = user();
        if (!mail_enabled()) fail('Zuerst E-Mail-Benachrichtigungen aktivieren und Absender speichern.');
        $GLOBALS['mail_queue'][] = [$me['email'], $me['name'], 'Test: E-Mail-Benachrichtigungen funktionieren.'];
        flash('Test-E-Mail an ' . $me['email'] . ' wurde versendet. Bitte auch den Spam-Ordner prüfen.');
        return;
    }
    $from = strtolower(post('mail_from'));
    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) fail('Ungültige Absender-Adresse.');
    $before = ['mail_enabled' => setting('mail_enabled', '0'), 'mail_from' => setting('mail_from')];
    set_setting('mail_from', $from);
    set_setting('mail_enabled', post('mail_enabled') === '1' ? '1' : '0');
    audit('mail_settings', 'settings', null, $before, ['mail_enabled' => setting('mail_enabled'), 'mail_from' => setting('mail_from')], 'E-Mail-Benachrichtigungen eingestellt');
}

function mail_optout_action(): void {
    $uid = (int)user()['id'];
    $ids = array_values(array_diff(mail_optout_users(), [$uid]));
    if (post('mail_off') === '1') $ids[] = $uid;
    sort($ids);
    set_setting('mail_optout', implode(',', $ids));
    audit('mail_optout', 'users', $uid, null, ['mail_off' => in_array($uid, $ids, true)], 'E-Mail-Benachrichtigungen ' . (in_array($uid, $ids, true) ? 'abbestellt' : 'aktiviert'));
}

function mail_settings_view(): void {
    echo '<section class="card"><h2>E-Mail-Benachrichtigungen</h2><p class="muted">Alle Mitteilungen der App gehen zusätzlich per E-Mail an die Anmelde-Adresse: neue Urlaubsanträge und Krankmeldungen sowie eingereichte Monatszettel an die Administratoren, Entscheidungen an die Mitarbeiter. Jeder kann sie unter „Mein Konto“ abbestellen. Die Absender-Adresse muss als Postfach oder Weiterleitung beim Hoster existieren.</p>';
    form_start('mail_settings');
    echo '<label class="check"><input type="checkbox" name="mail_enabled" value="1" ' . (setting('mail_enabled', '0') === '1' ? 'checked' : '') . '> E-Mail-Benachrichtigungen aktivieren</label>';
    field('Absender-Adresse', 'mail_from', setting('mail_from'), 'email', 'placeholder="zeit@wohlbekannt.at"');
    submit('E-Mail-Einstellungen speichern');
    if (mail_enabled()) { form_start('mail_test'); submit('Test-E-Mail an mich senden', 'secondary'); }
    echo '</section>';
}

function mail_account_view(): void {
    if (setting('mail_enabled', '0') !== '1') return;
    $off = in_array((int)user()['id'], mail_optout_users(), true);
    echo '<section class="card narrow"><h2>E-Mail-Benachrichtigungen</h2><p class="muted">Mitteilungen (z. B. Entscheidungen über Urlaubsanträge) zusätzlich an ' . h(user()['email']) . ' senden.</p>';
    form_start('mail_optout');
    echo '<label class="check"><input type="checkbox" name="mail_off" value="1" ' . ($off ? 'checked' : '') . '> Keine E-Mails erhalten</label>';
    submit('Speichern');
    echo '</section>';
}

/** Bezeichnung einer Abwesenheit für Mitteilungen, z. B. "Urlaub 16.10.2026 – 17.10.2026". */
function absence_label(string $type, string $start, string $end): string {
    $names = ['vacation' => 'Urlaub', 'sick' => 'Krankenstand', 'comp' => 'Freizeitausgleich', 'other' => setting('other_absence_label', 'Sonstige Abwesenheit')];
    return ($names[$type] ?? 'Abwesenheit') . ' ' . de_date($start) . ($end !== $start ? ' – ' . de_date($end) : '');
}
