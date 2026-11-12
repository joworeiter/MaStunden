<?php
declare(strict_types=1);
/*
 * wohlbekannt-Erweiterung: QR-Stempeln.
 * Ein Bildschirm im Lokal (z. B. Home-Assistant-Dashboard) zeigt einen QR-Code, der alle 30 Sekunden wechselt.
 * Wer ihn mit dem Handy scannt, darf in den nächsten 5 Minuten "Arbeit starten" bzw. "Arbeit beenden".
 * Der Standort wird in der Bemerkung des Arbeitsblocks vermerkt. Nachträge und Korrekturen bleiben ohne QR möglich.
 * Pro Mitarbeiter kann "Stempeln außer Haus" erlaubt werden: dann geht Kommen/Gehen auch ohne Scan,
 * der Arbeitsblock wird aber als "Außer Haus" gekennzeichnet (Übersicht, Monatszettel, PDF, CSV).
 */
const QR_STEP = 30;   // Sekunden je Code
const QR_GRACE = 3;   // akzeptierte Code-Generationen (bis 90 Sekunden alt)
const QR_VALID = 300; // so lange berechtigt ein Scan zum Stempeln (Sekunden)

function qr_enabled(): bool { return setting('qr_enabled', '0') === '1' && setting('qr_secret') !== ''; }

function qr_locations(): array {
    $out = [];
    foreach (preg_split('/\R/', setting('qr_locations', "platzl=wohlbekannt am Platzl\nritzer=das Ritzer")) as $line) {
        if (preg_match('/^\s*([a-z0-9-]{1,30})\s*=\s*(.{1,80}?)\s*$/u', $line, $m)) $out[$m[1]] = $m[2];
    }
    return $out;
}

function qr_display_key(string $loc): string { return substr(hash_hmac('sha256', 'display:' . $loc, setting('qr_secret')), 0, 24); }
function qr_token(string $loc, int $step): string { return substr(hash_hmac('sha256', 'scan:' . $loc . ':' . $step, setting('qr_secret')), 0, 20); }

function qr_base_url(): string {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($secure ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

/** Scan-Link aus dem QR-Code prüfen und für QR_VALID Sekunden in der Sitzung merken. */
function qr_accept(string $code): void {
    if (!qr_enabled()) return;
    $ok = false;
    if (preg_match('/^([a-z0-9-]{1,30})\.(\d{1,12})\.([a-f0-9]{20})$/D', $code, $m) && isset(qr_locations()[$m[1]])) {
        $now = intdiv(time(), QR_STEP); $step = (int)$m[2];
        $ok = $step <= $now && $now - $step < QR_GRACE && hash_equals(qr_token($m[1], $step), $m[3]);
    }
    if ($ok) {
        $_SESSION['qr_loc'] = $m[1];
        $_SESSION['qr_until'] = time() + QR_VALID;
        flash('QR-Code erkannt: ' . qr_locations()[$m[1]] . '. Jetzt „Arbeit starten“ oder „Arbeit beenden“ antippen.');
    } else {
        flash('Dieser QR-Code ist abgelaufen oder ungültig. Bitte den aktuellen Code im Lokal erneut scannen.');
    }
}

function qr_valid_scan(): ?string {
    $loc = (string)($_SESSION['qr_loc'] ?? '');
    return (($_SESSION['qr_until'] ?? 0) >= time() && isset(qr_locations()[$loc])) ? $loc : null;
}

/** Vor "Arbeit starten"/"Arbeit beenden": gültigen Scan verlangen (Administratoren ausgenommen). */
function qr_require(): void {
    if (!qr_enabled() || admin() || qr_valid_scan() || qr_offsite_allowed((int)user()['id'])) return;
    fail('Zum Kommen und Gehen bitte zuerst den QR-Code im Lokal scannen.');
}

/** Mitarbeiter, die ohne Scan stempeln dürfen (Einträge werden als "Außer Haus" markiert). */
function qr_offsite_users(): array { return array_values(array_filter(array_map('intval', explode(',', setting('qr_offsite_users'))))); }
function qr_offsite_allowed(int $uid): bool { return in_array($uid, qr_offsite_users(), true); }

/** Kennzeichnung eines Arbeitsblocks anhand der Bemerkung. */
function qr_is_offsite(?string $note): bool { return $note !== null && preg_match('/(Kommen|Gehen) außer Haus \(\d{2}:\d{2}\)/u', $note) === 1; }
function qr_block_suffix(?string $note): string { return qr_is_offsite($note) ? ' (Außer Haus)' : ''; }

/** Standortvermerk für die Bemerkung; der Scan wird dabei verbraucht. */
function qr_note(string $what): string {
    $loc = qr_valid_scan();
    unset($_SESSION['qr_loc'], $_SESSION['qr_until']);
    $time = ' (' . local_time(now(), 'H:i') . ')';
    if ($loc) return $what . ' per QR: ' . qr_locations()[$loc] . $time;
    if (qr_enabled() && !admin() && qr_offsite_allowed((int)user()['id'])) return $what . ' außer Haus' . $time;
    return '';
}

function qr_dashboard_hint(): string {
    if (!qr_enabled()) return '';
    $loc = qr_valid_scan();
    if ($loc) return '<p class="qr-hint ok">✓ QR-Code erkannt: ' . h(qr_locations()[$loc]) . ' – jetzt stempeln.</p>';
    if (admin()) return '<p class="qr-hint">QR-Stempeln ist aktiv (für Administratoren nicht erforderlich).</p>';
    if (qr_offsite_allowed((int)user()['id'])) return '<p class="qr-hint">Im Lokal bitte den QR-Code scannen. Ohne Scan wird der Eintrag als „Außer Haus“ gekennzeichnet.</p>';
    return '<p class="qr-hint">Zum Kommen und Gehen zuerst den QR-Code im Lokal scannen.</p>';
}

/** Öffentliche Bildschirmseite mit rotierendem QR-Code (für Home Assistant: Webseiten-Karte / Kiosk). */
function qr_display(string $loc, string $key): never {
    if (!qr_enabled() || !isset(qr_locations()[$loc]) || !hash_equals(qr_display_key($loc), $key)) { http_response_code(404); exit('Nicht gefunden.'); }
    $step = intdiv(time(), QR_STEP);
    $url = qr_base_url() . 'index.php?qr=' . rawurlencode($loc . '.' . $step . '.' . qr_token($loc, $step));
    header_remove('X-Frame-Options');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors *");
    header('Cache-Control: no-store');
    $refresh = QR_STEP - (time() % QR_STEP) + 1;
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta http-equiv="refresh" content="' . $refresh . '"><title>Stempeln · ' . h(qr_locations()[$loc]) . '</title>'
        . '<link rel="stylesheet" href="assets/qr-display.css?v=1"><link rel="stylesheet" href="index.php?theme=1"></head><body class="qr-display">'
        . '<main><p class="qr-place">' . h(qr_locations()[$loc]) . '</p><div id="qr" data-url="' . h($url) . '"></div>'
        . '<p class="qr-help">Kommen &amp; Gehen: mit dem Handy scannen</p></main>'
        . '<script src="assets/qrcode.js?v=1.4.4"></script><script src="assets/qr-display.js?v=1"></script></body></html>';
    exit;
}

function qr_admin_action(string $a): void {
    require_admin();
    if ($a === 'qr_offsite') {
        $uid = (int)post('user_id');
        if (!one('users', 'id=?', [$uid])) fail('Mitarbeiter nicht gefunden.');
        $before = qr_offsite_users();
        $ids = array_values(array_diff($before, [$uid]));
        if (post('qr_offsite') === '1') $ids[] = $uid;
        sort($ids);
        set_setting('qr_offsite_users', implode(',', $ids));
        audit('qr_offsite', 'users', $uid, ['qr_offsite' => in_array($uid, $before, true)], ['qr_offsite' => in_array($uid, $ids, true)], require_reason());
        return;
    }
    if ($a === 'qr_rotate') {
        set_setting('qr_secret', bin2hex(random_bytes(32)));
        audit('qr_rotate', 'settings', null, null, null, 'QR-Schlüssel neu erzeugt');
        flash('Neuer QR-Schlüssel erzeugt. Die Bildschirm-Links haben sich geändert – bitte im Dashboard ersetzen.');
        return;
    }
    $before = ['qr_enabled' => setting('qr_enabled', '0'), 'qr_locations' => setting('qr_locations')];
    $lines = [];
    foreach (preg_split('/\R/', (string)($_POST['qr_locations'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (!preg_match('/^([a-z0-9-]{1,30})\s*=\s*(.{1,80})$/u', $line, $m)) fail('Standorte bitte als „kurzname=Anzeigename“ angeben, ein Standort pro Zeile (Kurzname: a–z, 0–9, -).');
        $lines[] = $m[1] . '=' . trim($m[2]);
    }
    if (!$lines) fail('Mindestens einen Standort angeben.');
    set_setting('qr_locations', implode("\n", $lines));
    set_setting('qr_enabled', post('qr_enabled') === '1' ? '1' : '0');
    if (setting('qr_secret') === '') set_setting('qr_secret', bin2hex(random_bytes(32)));
    audit('qr_settings', 'settings', null, $before, ['qr_enabled' => setting('qr_enabled'), 'qr_locations' => setting('qr_locations')], 'QR-Stempeln eingestellt');
}

function qr_settings_view(): void {
    echo '<section class="card"><h2>QR-Stempeln</h2><p class="muted">Ein Bildschirm im Lokal zeigt einen QR-Code, der alle 30 Sekunden wechselt. Mitarbeiter scannen ihn mit dem Handy und können danach 5 Minuten lang „Arbeit starten“ bzw. „Arbeit beenden“. Nachträge und Korrekturen bleiben ohne QR möglich und landen wie bisher im Änderungsprotokoll.</p>';
    form_start('qr_settings');
    echo '<label class="check"><input type="checkbox" name="qr_enabled" value="1" ' . (setting('qr_enabled', '0') === '1' ? 'checked' : '') . '> QR-Scan zum Kommen und Gehen verlangen</label>';
    echo '<label>Standorte (kurzname=Anzeigename, einer pro Zeile)<textarea name="qr_locations" rows="3">' . h(setting('qr_locations', "platzl=wohlbekannt am Platzl\nritzer=das Ritzer")) . '</textarea></label>';
    submit('QR-Einstellungen speichern');
    if (setting('qr_secret') !== '') {
        echo '<h3>Bildschirm-Links</h3><p class="muted">Diesen Link im Home-Assistant-Dashboard als Webseiten-Karte einbinden. Den Link nicht weitergeben.</p><ul class="qr-links">';
        foreach (qr_locations() as $loc => $name) {
            $link = qr_base_url() . 'index.php?qr_display=' . rawurlencode($loc) . '&k=' . qr_display_key($loc);
            echo '<li><strong>' . h($name) . '</strong><br><code>' . h($link) . '</code></li>';
        }
        echo '</ul>';
        form_start('qr_rotate');
        submit('Neuen QR-Schlüssel erzeugen', 'secondary');
    }
    echo '</section>';
}

function qr_user_view(int $uid): void {
    $on = qr_offsite_allowed($uid);
    echo '<section class="card"><h2>Stempeln außer Haus</h2><p class="muted">Erlaubt Kommen und Gehen ohne QR-Scan, z. B. für Bar-Bike-Einsätze oder Arbeit außerhalb der Lokale. Solche Arbeitsblöcke werden in Übersicht, Monatszettel, PDF und CSV als „Außer Haus“ gekennzeichnet.' . (qr_enabled() ? '' : ' Wirkt erst, wenn unter Unternehmen das QR-Stempeln aktiviert ist.') . '</p>';
    form_start('qr_offsite', ['user_id' => $uid]);
    echo '<label class="check"><input type="checkbox" name="qr_offsite" value="1" ' . ($on ? 'checked' : '') . '> Stempeln ohne QR erlauben (als „Außer Haus“ markieren)</label>';
    textarea('Begründung', 'reason', '', true);
    submit('Speichern');
    echo '</section>';
}

/** Stempel-Vermerke (QR/Außer Haus) sind nur für Administratoren änderbar: Mitarbeiter können sie weder entfernen noch selbst eintragen. */
function qr_protect_note(?array $old, string $note): string {
    if (admin()) return $note;
    $pattern = '/\s*·?\s*(Kommen|Gehen) (per QR: [^()·]{1,80}|außer Haus) \(\d{2}:\d{2}\)\s*·?/u';
    $clean = trim((string)preg_replace($pattern, ' ', $note));
    preg_match_all('/(Kommen|Gehen) (per QR: [^()·]{1,80}|außer Haus) \(\d{2}:\d{2}\)/u', (string)($old['note'] ?? ''), $m);
    $marks = implode(' · ', $m[0]);
    return trim($marks . ($marks !== '' && $clean !== '' ? ' · ' : '') . $clean);
}
