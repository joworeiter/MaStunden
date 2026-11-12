<?php
declare(strict_types=1);
/* wohlbekannt-Erweiterung: deutsche Datumsdarstellung. */

function de_date(?string $ymd): string {
    if (!$ymd || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m)) return (string)$ymd;
    return $m[3] . '.' . $m[2] . '.' . $m[1];
}

function de_month(?string $ym): string {
    if (!$ym || !preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return (string)$ym;
    $names = ['Jänner','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    return $names[(int)$m[2] - 1] . ' ' . $m[1];
}
