<?php   $DocFil= 'ladestyring-setpass.php';   $DocVer='1.1.0';   $DocRev='2026-10-24';   $DocIni='evs';   $ModulNr=0;
# Sætter adgangskoden til ladestyring.php - kun fra kommandolinjen:  php ladestyring-setpass.php  [ny-kode]
if (PHP_SAPI !== 'cli') { http_response_code(404);  exit; }
require __DIR__.'/ladestyring.lib.php';
$p= $argv[1] ?? '';
if ($p === '') { echo 'Ny adgangskode: ';  $p= trim((string)fgets(STDIN)); }
if (strlen($p) < 8) exit("Mindst 8 tegn\n");
$s= ep_settings();
$s['pass_hash']= password_hash($p, PASSWORD_DEFAULT);
ep_write('settings.json', $s);
echo "Adgangskoden er sat\n";
