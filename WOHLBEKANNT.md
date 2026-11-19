# MaStunden – wohlbekannt-Fork

Fork von [MJnms1980/MaStunden](https://github.com/MJnms1980/MaStunden) (MIT-Lizenz) für die RS wohlbekannt OG.
Branch `wohlbekannt` = Upstream-Release + diese Anpassungen:

| Commit | Anpassung |
|---|---|
| MariaDB | Installer erlaubt MariaDB ab 10.6 (All-Inkl hat kein MySQL 8) |
| Wochensoll | Arbeitszeitmodell als „Soll pro Woche“, minutengenau auf Mo–So verteilt (Wochensumme exakt) |
| QR-Stempeln | Rotierender QR-Code am Lokal-Bildschirm. Ohne frischen Scan kein „Arbeit starten/beenden“. Der Standort wird im Eintrag vermerkt. |
| Außer Haus | Pro Mitarbeiter erlaubbar: Stempeln ohne QR, Arbeitsblock wird als „Außer Haus“ gekennzeichnet (Übersicht, Eintrag, Monatszettel, PDF, CSV). Stempel-Vermerke kann nur ein Admin ändern. |
| Formular | „Arbeitszeit nachtragen“ behält die Eingaben nach einem Fehler |
| Stundenübersicht | Admin-Ansicht (Verwaltung → Stundenübersicht): alle Mitarbeiter mit Wochensoll, Saldo bis gestern / inkl. heute, Resturlaub, Status |
| Deutsches Datum | Datumsangaben in Ansichten, Monatszettel, PDF und CSV als TT.MM.JJJJ bzw. „Oktober 2026“ |
| Eigene Zeiterfassung ausblenden | Mein Konto (nur Admins): Übersicht zeigt nur Team-Infos, Admin erscheint nicht in der Stundenübersicht |

| Kleinigkeiten | Eingaben bleiben nach Fehler beim Nachtragen erhalten, Datumsangaben im Format TT.MM.JJJJ |
| Stundenübersicht | Admin-Ansicht aller Mitarbeiter mit Saldo und Resturlaub; Admins können die eigene Zeiterfassung ausblenden (Mein Konto) |
| Auto-Pause | Mehr als 6 Std. am Tag ohne gestempelte Pause: 30 Min. bezahlte Pause werden automatisch ausgewiesen, Ist-Zeit bleibt gleich |
| E-Mail | Benachrichtigungen zusätzlich per E-Mail (Anträge, Freigaben, Monatsabschluss). Pro Person in „Mein Konto“ abbestellbar |

## E-Mail-Benachrichtigungen

- Aktivieren unter **Unternehmen → E-Mail-Benachrichtigungen**, Absender-Adresse muss beim Hoster existieren (z. B. `zeit@wohlbekannt.at`). Mit „Test-E-Mail senden“ prüfen.
- Versand über PHP `mail()` erst nach erfolgreichem Speichern. Fehler landen im Fehlerprotokoll des geschützten Speichers.
- Jeder kann E-Mails unter **Mein Konto** abbestellen; die Hinweise in der App bleiben.

## Automatische Pause

- Gilt pro Tag: Ist-Zeit über 6 Std., keine Pause gestempelt und keine Lücke von mind. 30 Min. zwischen zwei Blöcken → 30 Min. Pause gelten als genommen und bezahlt.
- Die Ist-Zeit wird nicht gekürzt (10 Std. anwesend = 10 Std. Ist, davon 30 Min. Pause). Ausweis in Spalte „Pause“ und Hinweis „Pause (auto.)“ in Übersicht, Monatszettel, PDF und CSV.
- Wird eine Pause gestempelt, gilt nur diese (kürzer als 30 Min. → Hinweis „Pause prüfen“).
- Dauer = Einstellung „Pausenhinweis nach mehr als 6 Std.“. Code: `auto_break()` in `web/lib/time.php`.

## QR-Stempeln

- Aktivieren unter **Unternehmen → QR-Stempeln**. Dort werden Standorte gepflegt und die Bildschirm-Links angezeigt.
- Der Bildschirm-Link (`index.php?qr_display=…&k=…`) zeigt einen QR-Code, der alle 30 s wechselt. Er lässt sich einbetten, z. B. als Home-Assistant-Webseiten-Karte (`type: iframe`). Der Link ist geheim; mit „Neuen QR-Schlüssel erzeugen“ werden alle alten Links ungültig.
- Mitarbeiter scannen mit der Handy-Kamera. Danach dürfen sie 5 Minuten lang kommen bzw. gehen. Codes älter als 90 s werden abgelehnt.
- **Außer Haus:** Unter Mitarbeiter → Bearbeiten → „Stempeln außer Haus“ pro Person erlauben (mit Begründung, steht im Änderungsprotokoll). Diese Person kann dann ohne Scan stempeln; der Block wird als „Außer Haus“ markiert. Mit Scan wird normal der Standort vermerkt.
- Die Vermerke „per QR“ und „außer Haus“ in der Bemerkung sind geschützt: Mitarbeiter können sie beim Bearbeiten weder entfernen noch selbst eintragen.
- Ausgenommen: Administratoren, Pausen, Nachträge und Korrekturen (diese stehen im Änderungsprotokoll und werden beim Monatsabschluss geprüft).
- iPhone: den Link als Lesezeichen in Safari verwenden, nicht als Home-Bildschirm-App. Die App hat einen eigenen Login, beim Scannen öffnet sich aber Safari.
- Grenze: Wer den Code vor Ort abfotografiert und sofort weiterschickt, kann ihn 90 s lang nutzen.

Code: `web/lib/qr.php`, `web/assets/qr-display.js|css`, `web/assets/qrcode.js` (qrcode-generator 1.4.4, Kazuhiko Arase, MIT).
Eingriffe in Upstream-Dateien: je eine Zeile in `index.php`, `lib/actions.php` (clock_start/stop), `lib/admin.php`, `lib/admin_views.php`, `lib/layout.php`, CSS in `assets/app.css`.

## Neue Upstream-Version übernehmen

```bash
git fetch upstream --tags
git checkout wohlbekannt
git rebase vX.Y.Z      # oder: git merge vX.Y.Z
# Konflikte lösen, testen, pushen
git push --force-with-lease origin wohlbekannt
```

## Server (All-Inkl) aktualisieren

Der Server hat einen Klon unter `/www/htdocs/w01bda3d/mastunden-git`. Die Website liegt unter `/www/htdocs/w01bda3d/zeit`.

```bash
cd /www/htdocs/w01bda3d/mastunden-git && git pull
rsync -a --exclude config.php web/ /www/htdocs/w01bda3d/zeit/
```

Vorher in MaStunden ein Backup erstellen (Backup & Wiederherstellung). `config.php` und der private Speicher werden nie überschrieben.
