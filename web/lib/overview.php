<?php
declare(strict_types=1);
/* wohlbekannt-Erweiterung: Stundenübersicht aller Mitarbeiter (Admin). */

function overview_weekly_target(int $uid): ?int {
    $m = rows('models', 'user_id=? AND valid_from<=?', [$uid, today()], 'ORDER BY valid_from DESC LIMIT 1')[0] ?? null;
    return $m ? array_sum(array_map('intval', json_decode($m['minutes'], true) ?: [])) : null;
}

function overview_rows(bool $inactive): array {
    $yesterday = (new DateTimeImmutable(today()))->modify('-1 day')->format('Y-m-d');
    $year = (int)substr(today(), 0, 4);
    $out = [];
    foreach (rows('users', $inactive ? '1' : 'active=1', [], 'ORDER BY name') as $u) {
        $uid = (int)$u['id'];
        if (!$inactive && in_array($uid, own_time_hidden_users(), true)) continue;
        $started = $u['employment_start'] <= today();
        $vac = vacation($uid, $year);
        $open = one('entries', 'user_id=? AND end_at IS NULL', [$uid]);
        $out[] = [
            'user' => $u,
            'weekly' => overview_weekly_target($uid),
            'saldo' => $started && $yesterday >= $u['employment_start'] ? balance($uid, $yesterday) : 0,
            'saldo_today' => $started ? balance($uid, today()) : 0,
            'vacation' => $vac,
            'running' => $open ? local_time($open['start_at'], 'H:i') : null,
            'pending' => count(rows('absences', "user_id=? AND status='pending'", [$uid])),
        ];
    }
    return $out;
}

function view_overview(): void {
    $inactive = isset($_GET['all']);
    $rows = overview_rows($inactive);
    $sum = array_sum(array_column($rows, 'saldo'));
    echo '<p class="lead">Stand: Ende gestern (' . h((new DateTimeImmutable(today()))->modify('-1 day')->format('d.m.Y')) . '). Plus = Mehrstunden, Minus = Minusstunden. Urlaub für ' . h(substr(today(), 0, 4)) . '.</p>';
    echo '<div class="stats compact"><section class="stat"><p>Mitarbeiter</p><strong>' . count($rows) . '</strong></section><section class="stat"><p>Saldo gesamt</p><strong class="' . ($sum < 0 ? 'neg' : 'pos') . '">' . h(hours($sum)) . '</strong></section></div>';
    echo '<section class="card"><div class="section-heading"><h2>Stundenkonten</h2><a class="subtle-link" href="index.php?page=overview' . ($inactive ? '' : '&all=1') . '">' . ($inactive ? 'Nur aktive' : 'Auch inaktive anzeigen') . '</a></div><div class="table-wrap"><table class="overview"><thead><tr><th>Mitarbeiter</th><th>Soll/Woche</th><th>Saldo bis gestern</th><th>inkl. heute</th><th>Resturlaub</th><th>Status</th><th></th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="7">Keine Mitarbeiter.</td></tr>';
    foreach ($rows as $r) {
        $u = $r['user']; $uid = (int)$u['id'];
        $status = [];
        if ($r['running']) $status[] = '<span class="badge approved">im Dienst seit ' . h($r['running']) . '</span>';
        if ($r['pending']) $status[] = '<span class="badge open">' . $r['pending'] . ' Antrag offen</span>';
        if (!$u['active']) $status[] = '<span class="badge">inaktiv</span>';
        if ($u['employment_start'] > today()) $status[] = '<span class="badge">ab ' . h((new DateTimeImmutable($u['employment_start']))->format('d.m.Y')) . '</span>';
        if ($r['weekly'] === null) $status[] = '<span class="badge offsite">kein Arbeitszeitmodell</span>';
        $v = $r['vacation'];
        echo '<tr><td><strong>' . h($u['name']) . '</strong><small>' . h($u['personnel']) . '</small></td>'
            . '<td>' . ($r['weekly'] === null ? '—' : h(hours($r['weekly']))) . '</td>'
            . '<td class="num ' . ($r['saldo'] < 0 ? 'neg' : 'pos') . '"><strong>' . h(($r['saldo'] > 0 ? '+' : '') . hours($r['saldo'])) . '</strong></td>'
            . '<td class="num muted">' . h(($r['saldo_today'] > 0 ? '+' : '') . hours($r['saldo_today'])) . '</td>'
            . '<td class="num">' . h(rtrim(rtrim(number_format((float)$v['remaining'], 2, ',', ''), '0'), ',')) . ' Tage' . ($v['pending'] ? '<small>' . h(rtrim(rtrim(number_format((float)$v['pending'], 2, ',', ''), '0'), ',')) . ' beantragt</small>' : '') . '</td>'
            . '<td>' . implode(' ', $status) . '</td>'
            . '<td><a href="index.php?page=times&user=' . $uid . '">Zeiten</a> · <a href="index.php?page=reports&user=' . $uid . '">Monatszettel</a></td></tr>';
    }
    echo '</tbody></table></div><p class="muted">„Saldo bis gestern“ enthält alle abgeschlossenen Tage inkl. Korrekturbuchungen. „inkl. heute“ rechnet das heutige Soll und die heute schon erfassten Zeiten mit ein (entspricht dem Stundenkonto, das der Mitarbeiter selbst sieht).</p></section>';
}

/* Administratoren ohne eigene Zeiterfassung (Mein Konto → "Eigene Zeiterfassung ausblenden"). */
function own_time_hidden_users(): array { return array_values(array_filter(array_map('intval', explode(',', setting('hide_own_time'))))); }
function own_time_hidden(): bool { return admin() && in_array((int)user()['id'], own_time_hidden_users(), true); }

function own_time_action(): void {
    $uid = (int)user()['id'];
    $ids = array_values(array_diff(own_time_hidden_users(), [$uid]));
    if (post('hide_own_time') === '1') $ids[] = $uid;
    sort($ids);
    set_setting('hide_own_time', implode(',', $ids));
    audit('own_time', 'users', $uid, null, ['hide_own_time' => in_array($uid, $ids, true)], 'Eigene Zeiterfassung ' . (in_array($uid, $ids, true) ? 'ausgeblendet' : 'eingeblendet'));
}

function own_time_account_view(): void {
    if (!admin()) return;
    echo '<section class="card narrow"><h2>Eigene Zeiterfassung</h2><p class="muted">Für Administratoren, die selbst keine Arbeitszeiten erfassen: Die Übersicht zeigt dann nur Team-Informationen, und Sie erscheinen nicht in der Stundenübersicht (außer bei „Auch inaktive anzeigen“). Bestehende Daten bleiben erhalten.</p>';
    form_start('own_time');
    echo '<label class="check"><input type="checkbox" name="hide_own_time" value="1" ' . (own_time_hidden() ? 'checked' : '') . '> Eigene Zeiterfassung ausblenden</label>';
    submit('Speichern');
    echo '</section>';
}
