# MaStunden – wohlbekannt-Fork

Fork von [MJnms1980/MaStunden](https://github.com/MJnms1980/MaStunden) (MIT-Lizenz) für die RS wohlbekannt OG.
Branch `wohlbekannt` = Upstream-Release + diese Anpassungen:

| Commit | Anpassung |
|---|---|
| MariaDB | Installer erlaubt MariaDB ab 10.6 (All-Inkl hat kein MySQL 8) |
| Wochensoll | Arbeitszeitmodell als „Soll pro Woche“, minutengenau auf Mo–So verteilt (Wochensumme exakt) |
| QR-Stempeln | Rotierender QR-Code am Lokal-Bildschirm. Ohne frischen Scan kein „Arbeit starten/beenden“. Der Standort wird im Eintrag vermerkt. |

## QR-Stempeln

- Aktivieren unter **Unternehmen → QR-Stempeln**. Dort werden Standorte gepflegt und die Bildschirm-Links angezeigt.
- Der Bildschirm-Link (`index.php?qr_display=…&k=…`) zeigt einen QR-Code, der alle 30 s wechselt. Er lässt sich einbetten, z. B. als Home-Assistant-Webseiten-Karte (`type: iframe`). Der Link ist geheim; mit „Neuen QR-Schlüssel erzeugen“ werden alle alten Links ungültig.
- Mitarbeiter scannen mit der Handy-Kamera. Danach dürfen sie 5 Minuten lang kommen bzw. gehen. Codes älter als 90 s werden abgelehnt.
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
