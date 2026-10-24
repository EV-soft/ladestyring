<?php   $DocFil= 'ladestyring.lib.php';   $DocVer='1.2.0';   $DocRev='2026-10-24';   $DocIni='evs';   $ModulNr=0;
# Ladestyring: henter spotpris + nettarif, vælger de billigste kvarterer og tænder / slukker et laderelæ (Tuya, Shelly, eWeLink).
# Bruges af ladestyring.php (siden) og ladestyring-cron.php (hvert 15. minut).

date_default_timezone_set('Europe/Copenhagen');

const EP_DATA=  __DIR__.'/data';
const EP_API=   'https://api.energidataservice.dk/dataset/';
const EP_EWEAPI= ['eu' => 'https://eu-apia.coolkit.cc', 'us' => 'https://us-apia.coolkit.cc', 'as' => 'https://as-apia.coolkit.cc', 'cn' => 'https://cn-apia.coolkit.cn'];

# Netselskaber i DK2 - GLN og tarifkode (DatahubPricelist). 'egen': skriv GLN og kode selv.
const EP_NETCOMP= [
    'radius' => ['Radius Elnet (C-kunde) - tidl. NESA / DONG', '5790000705689', 'DT_C_01'],
    'cerius' => ['Cerius (C-kunde) - tidl. SEAS-NVE', '5790000705184', '30TR_C_ET'],
    'ingen'  => ['Ingen nettarif (kun spotpris)', '', ''],
    'egen'   => ['Andet netselskab (GLN og kode)', '', ''],
];

function ep_defaults() {
    return [
        'area'     => 'DK2',
        'hours'    => 3.0,          # Timer med opladning i hver periode
        'mode'     => 'block',      # block: én sammenhængende blok - spread: de billigste kvarterer
        'span'     => 'ahead',      # ahead: de billigste timer blandt alle kendte priser fra nu - day: hver periode (fra - til) for sig
        'from'     => 0,            # Perioden: fra kl. (0-23) ...
        'to'       => 24,           # ... til kl. (1-24) - from > to: over midnat (fx 18 - 7)
        'net'      => 'radius',
        'net_gln'  => '',  'net_code' => '',
        'vat'      => true,         # Vis priser med moms (25 %)
        'fixed'    => 0.0,          # Faste tillæg kr/kWh uden moms (elafgift, Energinet, elhandler) - kun visning
        'driver'   => 'none',       # none (prøve) / tuya (lokalt) / http (lokalt: Shelly, OpenBeken, Tasmota) / webhook / ewelink
        'tuya_id'  => '',  'tuya_ip' => 'Auto',  'tuya_key' => '',  'tuya_ver' => '3.3',  'tuya_dp' => 1,
        'python'   => PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3',
        'http_on'  => '',  'http_off' => '',
        'hook_on'  => '',  'hook_off' => '',
        'ewe_appid'=> '',  'ewe_secret' => '',  'ewe_region' => 'eu',
        'ewe_device' => '',  'ewe_name' => '',  'ewe_outlet' => '',      # outlet: '' en kanal - 0, 1 ... flere kanaler
        'cron_key' => bin2hex(random_bytes(12)),
        'pass_hash'=> '',
    ];
}

function ep_file($name) { return EP_DATA.'/'.$name; }

function ep_read($name, $default= []) {
    $f= ep_file($name);
    if (!is_file($f)) return $default;
    $j= json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : $default;
}

function ep_write($name, $data) {
    if (!is_dir(EP_DATA)) { mkdir(EP_DATA, 0750, true); }
    if (!is_file(EP_DATA.'/.htaccess')) file_put_contents(EP_DATA.'/.htaccess', "Require all denied\nDeny from all\n");
    $f= ep_file($name);  $tmp= $f.'.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $f);
}

function ep_settings() {
    $s= ep_read('settings.json');
    $s= array_merge(ep_defaults(), $s);
    if (!is_file(ep_file('settings.json'))) ep_write('settings.json', $s);     # (cron_key bliver fast)
    return $s;
}

function ep_log($msg) {
    if (!is_dir(EP_DATA)) ep_write('state.json', []);
    $f= ep_file('log.txt');
    if (is_file($f) && filesize($f) > 200000) {                                 # Kun de sidste ~1000 linjer
        $l= file($f);  file_put_contents($f, implode('', array_slice($l, -1000)));
    }
    file_put_contents($f, date('Y-m-d H:i').'  '.$msg."\n", FILE_APPEND | LOCK_EX);
}

function ep_http($method, $url, $headers= [], $body= null) {
    $ch= curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);   # Windows: certifikaterne i Windows
    $res= curl_exec($ch);
    $code= (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err= curl_error($ch);
    curl_close($ch);
    return [$code, $res === false ? '' : $res, $err];
}

# ---------- Priser ----------

function ep_api($dataset, $query) {
    [$code, $res, $err]= ep_http('GET', EP_API.$dataset.'?'.http_build_query($query));
    $j= json_decode($res, true);
    if ($code !== 200 || !isset($j['records'])) throw new RuntimeException("Energi Data Service ($dataset): HTTP $code $err");
    return $j['records'];
}

function ep_tariff($s) {           # 24 nettarif-priser (kr/kWh uden moms), time 0-23 - for i dag
    $net= EP_NETCOMP[$s['net']] ?? EP_NETCOMP['ingen'];
    $gln= $s['net'] === 'egen' ? trim($s['net_gln']) : $net[1];
    $code= $s['net'] === 'egen' ? trim($s['net_code']) : $net[2];
    if ($gln === '' || $code === '') return [array_fill(0, 24, 0.0), ''];
    $cache= ep_read('tariff.json');
    $key= $gln.'|'.$code.'|'.date('Y-m-d');
    if (($cache['key'] ?? '') === $key) return [$cache['t'], $cache['name']];
    $rec= ep_api('DatahubPricelist', ['filter' => json_encode(['GLN_Number' => [$gln], 'ChargeTypeCode' => [$code]]),
                                     'end' => date('Y-m-d', strtotime('+1 day')), 'sort' => 'ValidFrom desc', 'limit' => 20]);
    $now= date('Y-m-d\TH:i:s');
    foreach ($rec as $r)
        if ($r['ValidFrom'] <= $now && ($r['ValidTo'] === null || $r['ValidTo'] > $now)) {
            $t= [];  for ($h= 1; $h <= 24; $h++) $t[]= (float)($r['Price'.$h] ?? $r['Price1']);
            $name= $r['ChargeOwner'].' - '.$r['Note'];
            ep_write('tariff.json', ['key' => $key, 't' => $t, 'name' => $name]);
            return [$t, $name];
        }
    throw new RuntimeException("Ingen gyldig nettarif for GLN $gln / $code");
}

function ep_prices($s, $force= false) {   # [unix start => spot kr/kWh uden moms] fra i dag 00:00 til i overmorgen
    $cache= ep_read('prices.json');
    $today= date('Y-m-d');
    $haveTomorrow= isset($cache['p']) && max(array_keys($cache['p']) ?: [0]) >= strtotime('tomorrow 23:00');
    $fresh= ($cache['day'] ?? '') === $today && ($cache['area'] ?? '') === $s['area']
         && ($haveTomorrow || time() - ($cache['at'] ?? 0) < (date('G') >= 12 ? 900 : 3 * 3600));
    if ($fresh && !$force) return $cache['p'];
    try {
        $rec= ep_api('DayAheadPrices', ['start' => $today.'T00:00', 'end' => date('Y-m-d', strtotime('+2 days')).'T00:00',
                                        'filter' => json_encode(['PriceArea' => [$s['area']]]), 'sort' => 'TimeUTC asc', 'limit' => 500]);
        $p= [];
        foreach ($rec as $r) if (isset($r['DayAheadPriceDKK'])) $p[strtotime($r['TimeUTC'].'Z')]= $r['DayAheadPriceDKK'] / 1000;     # (UTC: sommertid giver ikke to ens kvarterer)
        if (!$p) throw new RuntimeException('Ingen priser i svaret');
        ksort($p);
        ep_write('prices.json', ['day' => $today, 'area' => $s['area'], 'at' => time(), 'p' => $p]);
        return $p;
    } catch (Throwable $e) {
        ep_log('Pris-fejl: '.$e->getMessage());
        if (isset($cache['p'])) return array_filter($cache['p'], fn($t) => $t >= strtotime('today'), ARRAY_FILTER_USE_KEY);
        return [];
    }
}

function ep_history($s) {          # [unix start => spot kr/kWh uden moms] for de sidste 7 hele døgn - hentes én gang i døgnet
    $cache= ep_read('history.json');
    $today= date('Y-m-d');
    if (($cache['day'] ?? '') === $today && ($cache['area'] ?? '') === $s['area'] && (($cache['p'] ?? []) || time() - ($cache['at'] ?? 0) < 3600)) return $cache['p'];
    $p= [];
    try {
        $rec= ep_api('DayAheadPrices', ['start' => date('Y-m-d', strtotime('-7 days')).'T00:00', 'end' => $today.'T00:00',
                                        'filter' => json_encode(['PriceArea' => [$s['area']]]), 'sort' => 'TimeUTC asc', 'limit' => 1000]);
        foreach ($rec as $r) if (isset($r['DayAheadPriceDKK'])) $p[strtotime($r['TimeUTC'].'Z')]= $r['DayAheadPriceDKK'] / 1000;
        ksort($p);
    } catch (Throwable $e) { ep_log('Historik-fejl: '.$e->getMessage()); }
    ep_write('history.json', ['day' => $today, 'area' => $s['area'], 'at' => time(), 'p' => $p]);
    return $p;
}

function ep_onquarters($from) {    # [unix kvarter => true] hvor laderelæet var tændt efter $from - læst ud af loggen (TÆNDT / SLUKKET / Prøve)
    $f= ep_file('log.txt');
    if (!is_file($f)) return [];
    $ev= [];
    foreach (file($f, FILE_IGNORE_NEW_LINES) as $l) {
        if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d)  (TÆNDT|SLUKKET|Prøve: tænd|Prøve: sluk)/u', $l, $m) || str_contains($l, 'intet sendt')) continue;
        $ev[]= [strtotime($m[1]), $m[2] === 'TÆNDT' || $m[2] === 'Prøve: tænd'];
    }
    $out= [];  $now= time();
    foreach ($ev as $i => [$t, $on]) {
        if (!$on) continue;
        $end= $ev[$i + 1][0] ?? $now;
        for ($q= $t - $t % 900; $q < $end; $q+= 900) if ($q >= $from) $out[$q]= true;
    }
    return $out;
}

function ep_total($s, $spot, $tariff, $ts) {     # kr/kWh: spot + nettarif (+ faste tillæg) (+ moms)
    $v= $spot + $tariff[(int)date('G', $ts)] + (float)$s['fixed'];
    return $s['vat'] ? $v * 1.25 : $v;
}

# ---------- Plan ----------

function ep_windows($s, $from, $until) {    # Perioderne [start, slut] der rører [$from, $until]
    $w= [];
    $f= (int)$s['from'];  $t= (int)$s['to'];
    for ($d= strtotime('yesterday', $from); $d <= $until; $d= strtotime('+1 day', $d)) {
        $a= strtotime(date('Y-m-d', $d).' '.sprintf('%02d:00', $f));
        $b= $t <= $f ? strtotime(date('Y-m-d', strtotime('+1 day', $d)).' '.sprintf('%02d:00', $t % 24))
                     : ($t === 24 ? strtotime('+1 day', $d) : strtotime(date('Y-m-d', $d).' '.sprintf('%02d:00', $t)));
        if ($b > $from && $a < $until) $w[]= [$a, $b];
    }
    return $w;
}

function ep_pick($s, $q, $n) {               # De valgte kvarterer [t, ...] blandt $q [t => pris]
    $k= min($n, count($q));
    if ($k === 0) return [];
    if ($s['mode'] === 'spread') { asort($q);  return array_keys(array_slice($q, 0, $k, true)); }
    $keys= array_keys($q);  $best= null;  $bestSum= INF;
    for ($i= 0; $i + $k <= count($keys); $i++) {
        if ($keys[$i + $k - 1] - $keys[$i] !== ($k - 1) * 900) continue;     # (huller i data / uden for perioden)
        $sum= 0;  for ($j= 0; $j < $k; $j++) $sum+= $q[$keys[$i + $j]];
        if ($sum < $bestSum) { $bestSum= $sum;  $best= $i; }
    }
    return $best === null ? [] : array_slice($keys, $best, $k);
}

function ep_plan($s, $prices, $tariff) {    # [unix kvarter => true] - de valgte kvarterer + [[a, b, complete], ...] de beregnede perioder
    $plan= [];  $info= [];
    $n= max(0, min(96, (int)round($s['hours'] * 4)));
    if ($n === 0 || !$prices) return [$plan, $info];
    ksort($prices);
    $last= max(array_keys($prices)) + 900;
    if ($s['span'] === 'ahead') return ep_plan_ahead($s, $prices, $tariff, $n, $last);
    foreach (ep_windows($s, strtotime('today'), $last) as [$a, $b]) {
        $q= [];
        foreach ($prices as $t => $p) if ($t >= $a && $t < $b) $q[$t]= ep_total($s, $p, $tariff, $t);
        if (!$q) continue;
        foreach (ep_pick($s, $q, $n) as $t) $plan[$t]= true;
        $info[]= ['a' => $a, 'b' => $b, 'complete' => count($q) >= intdiv($b - $a, 900)];
    }
    ksort($plan);
    return [$plan, $info];
}

# Kendte priser fra nu: hver gang der kommer nye priser (ca. kl. 13), vælges de billigste kvarterer fra slutningen af sidste valgte
# opladning (dog tidligst nu) til sidste kendte pris. Valget gemmes i data/plan.json og ændres ikke før næste prisudmelding, så den
# samme opladning ikke skubbes foran sig. Perioden fra - til begrænser kun hvilke klokkeslæt der må lades i.
function ep_plan_ahead($s, $prices, $tariff, $n, $last) {
    $now= time();  $q0= $now - $now % 900;
    $sig= md5(json_encode([$s['hours'], $s['mode'], $s['from'], $s['to'], $s['area'], $s['net'], $s['net_gln'], $s['net_code']]));
    $mem= ep_read('plan.json');
    $runs= $mem['runs'] ?? [];
    if (($mem['sig'] ?? '') !== $sig)                                   # Nye indstillinger: behold kun det der allerede er sket
        foreach ($runs as $i => $r) { $runs[$i]['q']= array_values(array_filter($r['q'], fn($t) => $t < $q0));  $runs[$i]['b']= min($r['b'], $q0); }
    $runs= array_values(array_filter($runs, fn($r) => $r['b'] > strtotime('today')));
    $end= 0;  $done= 0;
    foreach ($runs as $r) { $end= max($end, $r['b']);  if ($r['q']) $done= max($done, max($r['q']) + 900); }
    if ($end < $last || ($mem['sig'] ?? '') !== $sig) {                 # Nye priser (eller nye indstillinger): ny beregning
        $a= max($q0, $done);
        $ok= [];  foreach (ep_windows($s, $a, $last) as [$wa, $wb]) for ($t= max($wa, $a); $t < $wb; $t+= 900) $ok[$t]= true;
        $q= [];  foreach ($prices as $t => $p) if ($t >= $a && $t < $last && isset($ok[$t])) $q[$t]= ep_total($s, $p, $tariff, $t);
        $runs[]= ['a' => $a, 'b' => $last, 'q' => ep_pick($s, $q, $n), 'made' => $now];
        ep_write('plan.json', ['sig' => $sig, 'runs' => $runs]);
    }
    $plan= [];  $info= [];
    foreach ($runs as $r) {
        foreach ($r['q'] as $t) $plan[$t]= true;
        $info[]= ['a' => $r['a'], 'b' => $r['b'], 'complete' => true];
    }
    ksort($plan);
    return [$plan, $info];
}

function ep_wanted($s, $plan, $state, $now= null) {   # [true/false, grund]
    $now= $now ?? time();
    $ov= $state['override'] ?? null;
    if ($ov && ($ov['until'] ?? 0) > $now) return [$ov['on'], ($ov['on'] ? 'Tændt' : 'Slukket').' manuelt til '.date('H:i', $ov['until'])];
    $q= $now - $now % 900;
    return isset($plan[$q]) ? [true, 'Billigt kvarter'] : [false, 'Venter på billig pris'];
}

# ---------- Laderelæet ----------

function ep_nonce() { return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8); }

function ep_ewe($s, $method, $path, $body= null, $sign= false) {
    $base= EP_EWEAPI[$s['ewe_region']] ?? EP_EWEAPI['eu'];
    $json= $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
    $tok= ep_read('ewelink.json');
    $h= ['Content-Type: application/json', 'X-CK-Appid: '.$s['ewe_appid'], 'X-CK-Nonce: '.ep_nonce()];
    $h[]= $sign ? 'Authorization: Sign '.base64_encode(hash_hmac('sha256', (string)$json, $s['ewe_secret'], true))
                : 'Authorization: Bearer '.($tok['at'] ?? '');
    [$code, $res, $err]= ep_http($method, $base.$path, $h, $json);
    $j= json_decode($res, true);
    if (!is_array($j)) throw new RuntimeException("eWeLink $path: HTTP $code $err");
    if (($j['error'] ?? -1) !== 0) throw new RuntimeException("eWeLink $path: fejl ".($j['error'] ?? '?').' '.($j['msg'] ?? ''));
    return $j['data'] ?? [];
}

function ep_ewe_authurl($s, $redirect) {
    $seq= (string)round(microtime(true) * 1000);
    $st= bin2hex(random_bytes(8));
    $_SESSION['ep_ewe_state']= $st;
    return 'https://c2ccdn.coolkit.cc/oauth/index.html?'.http_build_query([
        'clientId' => $s['ewe_appid'], 'seq' => $seq,
        'authorization' => base64_encode(hash_hmac('sha256', $s['ewe_appid'].'_'.$seq, $s['ewe_secret'], true)),
        'redirectUrl' => $redirect, 'grantType' => 'authorization_code', 'state' => $st, 'nonce' => ep_nonce(), 'showQRCode' => 'false']);
}

function ep_ewe_token(&$s, $code, $region, $redirect) {
    if (isset(EP_EWEAPI[$region])) $s['ewe_region']= $region;
    $d= ep_ewe($s, 'POST', '/v2/user/oauth/token', ['code' => $code, 'redirectUrl' => $redirect, 'grantType' => 'authorization_code'], true);
    ep_write('ewelink.json', ['at' => $d['accessToken'], 'rt' => $d['refreshToken'], 'atExp' => (int)($d['atExpiredTime'] / 1000),
                              'rtExp' => (int)($d['rtExpiredTime'] / 1000), 'at_got' => time()]);
}

function ep_ewe_keepalive($s) {     # Ny adgangsnøgle efter 20 dage (den gælder 30, fornyelsen 60)
    $tok= ep_read('ewelink.json');
    if (empty($tok['rt']) || time() - ($tok['at_got'] ?? 0) < 20 * 86400) return;
    $d= ep_ewe($s, 'POST', '/v2/user/refresh', ['rt' => $tok['rt']]);
    ep_write('ewelink.json', ['at' => $d['at'], 'rt' => $d['rt'], 'atExp' => time() + 30 * 86400, 'rtExp' => time() + 60 * 86400, 'at_got' => time()]);
    ep_log('eWeLink: adgangsnøglen er fornyet');
}

function ep_ewe_devices($s) {
    $d= ep_ewe($s, 'GET', '/v2/device/thing?num=0');
    $out= [];
    foreach ($d['thingList'] ?? [] as $t)
        if (($t['itemType'] ?? 0) == 1 || ($t['itemType'] ?? 0) == 2)
            $out[]= ['id' => $t['itemData']['deviceid'], 'name' => $t['itemData']['name'] ?? '', 'online' => !empty($t['itemData']['online']),
                     'multi' => isset($t['itemData']['params']['switches'])];
    return $out;
}

function ep_tuya($s, $on) {        # Tuya lokalt (tuya_switch.py med tinytuya) - $on null: kun status. Nøglen gives på stdin (ikke synlig i proceslisten)
    if ($s['tuya_id'] === '' || $s['tuya_key'] === '') throw new RuntimeException('Tuya: enheds-id og local key mangler');
    $cmd= escapeshellarg($s['python']).' '.escapeshellarg(__DIR__.'/tuya_switch.py');
    $p= proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Tuya: kunne ikke starte '.$s['python']);
    fwrite($pipes[0], json_encode(['id' => $s['tuya_id'], 'ip' => $s['tuya_ip'] ?: 'Auto', 'key' => $s['tuya_key'],
                                   'ver' => $s['tuya_ver'], 'dp' => (int)$s['tuya_dp'], 'on' => $on]));
    fclose($pipes[0]);
    $out= stream_get_contents($pipes[1]);  $err= stream_get_contents($pipes[2]);
    fclose($pipes[1]);  fclose($pipes[2]);  proc_close($p);
    $j= json_decode(trim((string)$out), true);
    if (!is_array($j)) throw new RuntimeException('Tuya: '.trim(substr($err ?: $out ?: 'intet svar fra '.$s['python'], -300)));
    if (empty($j['ok'])) throw new RuntimeException('Tuya: '.($j['error'] ?? '?'));
    return $j['on'] ?? null;
}

function ep_switch($s, $on) {      # Tænd / sluk - kaster en fejl hvis det ikke lykkedes
    switch ($s['driver']) {
        case 'tuya':
            ep_tuya($s, $on);
            return;
        case 'http':
            $url= $on ? $s['http_on'] : $s['http_off'];
            if (!preg_match('~^https?://~', $url)) throw new RuntimeException('Adressen til laderelæet mangler (http://...)');
            [$code, , $err]= ep_http('GET', $url);
            if ($code < 200 || $code >= 300) throw new RuntimeException("Laderelæet: HTTP $code $err");
            return;
        case 'webhook':
            $url= $on ? $s['hook_on'] : $s['hook_off'];
            if (!preg_match('~^https://~', $url)) throw new RuntimeException('Webhook-adressen mangler (skal starte med https://)');
            [$code, , $err]= ep_http('POST', $url, ['Content-Type: application/json'], '{}');
            if ($code < 200 || $code >= 300) throw new RuntimeException("Webhook: HTTP $code $err");
            return;
        case 'ewelink':
            if ($s['ewe_device'] === '') throw new RuntimeException('Ingen eWeLink-enhed valgt');
            ep_ewe_keepalive($s);
            $sw= $on ? 'on' : 'off';
            $params= $s['ewe_outlet'] === '' ? ['switch' => $sw] : ['switches' => [['switch' => $sw, 'outlet' => (int)$s['ewe_outlet']]]];
            ep_ewe($s, 'POST', '/v2/device/thing/status', ['type' => 1, 'id' => $s['ewe_device'], 'params' => $params]);
            return;
        default:
            return;                                                 # Prøvetilstand: intet sendes
    }
}

# ---------- Ét tjek (cron hvert 15. minut, og ved knapperne på siden) ----------

function ep_tick($force= false) {
    $s= ep_settings();
    $state= ep_read('state.json');
    try { [$tariff]= ep_tariff($s); } catch (Throwable $e) { $tariff= array_fill(0, 24, 0.0);  ep_log('Tarif-fejl: '.$e->getMessage()); }
    $prices= ep_prices($s);
    [$plan]= ep_plan($s, $prices, $tariff);
    if (!$prices) {                                                 # Ingen priser: nødplan kl. 02 - så batteriet ikke står tomt
        $n= (int)round($s['hours'] * 4);
        for ($t= strtotime('today 02:00'), $i= 0; $i < $n; $i++, $t+= 900) $plan[$t]= true;
    }
    [$on, $why]= ep_wanted($s, $plan, $state);
    $changed= ($state['on'] ?? null) !== $on;
    $stale= time() - ($state['sent'] ?? 0) >= 3600;                # Gentag én gang i timen (hvis nogen har trykket på dåsen)
    if ($changed || $stale || $force) {
        try {
            ep_switch($s, $on);
            $state['on']= $on;  $state['sent']= time();  $state['error']= '';
            if ($changed || $force) ep_log(($on ? 'TÆNDT' : 'SLUKKET').' - '.$why.($s['driver'] === 'none' ? ' (prøvetilstand - intet sendt)' : ''));
        } catch (Throwable $e) {
            $state['error']= $e->getMessage();
            ep_log('FEJL ved '.($on ? 'tænd' : 'sluk').': '.$e->getMessage());
        }
    }
    $state['tick']= time();
    ep_write('state.json', $state);
    return $state;
}
