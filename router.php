<?php   $DocFil= 'router.php';   $DocVer='1.1.0';   $DocRev='2026-10-24';   $DocIni='evs';   $ModulNr=0;
# Router til php -S (som ikke læser .htaccess): kun siden, favicon og _cache-filer må hentes - data/, nøgler og scripts afvises.
$p= rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($p === '/' || $p === '' || $p === '/elpris.php') { header('Location: /ladestyring.php');  exit; }
if (preg_match('~^/(ladestyring\.php|favicon\.svg|_cache/p2h-[0-9a-f]+\.(css|js))$~', $p)) return false;
http_response_code(404);
echo 'Not found';
