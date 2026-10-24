<?php   $DocFil= 'ladestyring-cron.php';   $DocVer='1.1.0';   $DocRev='2026-10-24';   $DocIni='evs';   $ModulNr=0;
# Køres hvert 15. minut - cPanel cron:   */15 * * * *  php /home/BRUGER/public_html/ladestyring/ladestyring-cron.php
# eller som adresse (en web-cron):        https://.../ladestyring-cron.php?key=<nøglen på siden>
require __DIR__.'/ladestyring.lib.php';

if (PHP_SAPI !== 'cli') {
    $s= ep_settings();
    if (!hash_equals((string)$s['cron_key'], (string)($_GET['key'] ?? ''))) { http_response_code(403);  exit('Forkert nøgle'); }
    header('Content-Type: text/plain; charset=utf-8');
}
$st= ep_tick();
echo date('Y-m-d H:i').'  '.(($st['on'] ?? false) ? 'tændt' : 'slukket').(($st['error'] ?? '') !== '' ? '  FEJL: '.$st['error'] : '')."\n";
