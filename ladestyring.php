<?php   $DocFil= 'ladestyring.php';   $DocVer='1.3.0';   $DocRev='2026-10-24';   $DocIni='evs';   $ModulNr=0;
# Ladestyring: tænder et laderelæ (Tuya, Shelly, eWeLink) i døgnets billigste timer - DK2, spotpris + nettarif.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
if (empty($_SESSION['proglang'])) $_SESSION['proglang']= 'da';
require_once (__DIR__.'/ladestyring.lib.php');
require_once (__DIR__.'/php2html.lib.php');

$s= ep_settings();
$msg= '';  $kind= 'success';
$self= strtok($_SERVER['REQUEST_URI'] ?? 'ladestyring.php', '?');
$base= (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost');
$redirect= $base.$self.'?ewelink=1';
$in= !empty($_SESSION['ep_in']);
function ep_enc($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
function ep_btn($act, $labl, $kind= 'navi', $icon= '', $hint= '', $extra= '') {
    $c= ['save' => '#0064b4;color:#fff', 'eras' => 'crimson;color:#fff', 'goon' => '#66cdaa;color:#000', 'navi' => '#1a6e1a;color:#fff'][$kind] ?? '#1a6e1a;color:#fff';
    return '<button type="submit" name="act" value="'.ep_enc($act).'" class="epBtn" style="background:'.$c.'"'.
           ($hint ? ' title="'.ep_enc($hint).'"' : '').' '.$extra.'>'.($icon ? '<i class="'.$icon.'"></i>&nbsp;' : '').ep_enc($labl).'</button>';
}
function ep_go($msg, $kind= 'success') { $_SESSION['ep_msg']= [$msg, $kind];  header('Location: '.strtok($_SERVER['REQUEST_URI'], '?'));  exit; }

# ---------- Log ind ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) ep_go('Siden var for gammel - prøv igen', 'warning');
    $act= (string)($_POST['act'] ?? '');
    if ($act === 'login') {
        $wait= ep_read('state.json')['login_wait'] ?? 0;                # Fælles spærring for alle (ikke pr. browser)
        if ($wait > time()) ep_go('Spærret efter forkerte forsøg - prøv igen kl. '.date('H:i:s', $wait), 'warning');
        $st= ep_read('state.json');
        if ($s['pass_hash'] !== '' && password_verify((string)($_POST['pwd'] ?? ''), $s['pass_hash'])) {
            $st['login_fails']= 0;  ep_write('state.json', $st);
            session_regenerate_id(true);  $_SESSION['ep_in']= true;  ep_go('Du er logget ind');
        }
        $f= ($st['login_fails'] ?? 0) + 1;                              # 5 s, 10 s, 20 s ... højst 15 min
        $st['login_fails']= $f;  $st['login_wait']= time() + min(900, 5 * 2 ** min($f - 1, 8));  ep_write('state.json', $st);
        $ip= $_SERVER['REMOTE_ADDR'] ?? '?';
        if (in_array($ip, ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip= trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]).' (via proxy)';
        ep_log('Forkert adgangskode nr. '.$f.' fra '.$ip);
        ep_go('Forkert adgangskode', 'error');
    }
    if (!$in) ep_go('Log ind først', 'warning');
    $st= ep_read('state.json');
    switch ($act) {
        case 'logout': $_SESSION= [];  session_destroy();  header('Location: '.$self);  exit;
        case 'on': case 'off':
            $h= max(0.25, min(24, (float)str_replace(',', '.', $_POST['for'] ?? 1)));
            $st['override']= ['on' => $act === 'on', 'until' => time() + (int)($h * 3600)];
            ep_write('state.json', $st);
            $r= ep_tick(true);
            ep_go(($r['error'] ?? '') !== '' ? 'Fejl: '.$r['error'] : ($act === 'on' ? 'Tændt' : 'Slukket').' til kl. '.date('H:i', $st['override']['until']), ($r['error'] ?? '') !== '' ? 'error' : 'success');
        case 'auto':
            unset($st['override']);  ep_write('state.json', $st);
            $r= ep_tick(true);
            ep_go(($r['error'] ?? '') !== '' ? 'Fejl: '.$r['error'] : 'Automatik - styres af elprisen', ($r['error'] ?? '') !== '' ? 'error' : 'success');
        case 'save':
            $p= $_POST;
            $s['hours']= max(0, min(24, round((float)str_replace(',', '.', $p['hours'] ?? 3) * 4) / 4));
            $s['mode']=  ($p['mode'] ?? '') === 'spread' ? 'spread' : 'block';
            $s['span']=  ($p['span'] ?? '') === 'day' ? 'day' : 'ahead';
            $s['from']=  max(0, min(23, (int)($p['from'] ?? 0)));
            $s['to']=    max(1, min(24, (int)($p['to'] ?? 24)));
            $s['net']=   isset(EP_NETCOMP[$p['net'] ?? '']) ? $p['net'] : 'radius';
            $s['net_gln']= preg_replace('/\D/', '', (string)($p['net_gln'] ?? ''));
            $s['net_code']= preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($p['net_code'] ?? ''));
            $s['vat']=   !empty($p['vat']);
            $s['fixed']= max(0, min(10, (float)str_replace(',', '.', $p['fixed'] ?? 0)));
            ep_write('settings.json', $s);
            @unlink(ep_file('tariff.json'));
            ep_tick();
            ep_go('Gemt');
        case 'driver':
            $p= $_POST;
            $s['driver']= in_array($p['driver'] ?? '', ['none', 'tuya', 'http', 'webhook', 'ewelink'], true) ? $p['driver'] : 'none';
            foreach (['hook_on', 'hook_off', 'http_on', 'http_off'] as $k) $s[$k]= trim((string)($p[$k] ?? ''));
            $s['tuya_id']=  preg_replace('/[^A-Za-z0-9]/', '', (string)($p['tuya_id'] ?? ''));
            $s['tuya_ip']=  preg_match('/^(\d{1,3}\.){3}\d{1,3}$/', trim((string)($p['tuya_ip'] ?? ''))) ? trim($p['tuya_ip']) : 'Auto';
            if (trim((string)($p['tuya_key'] ?? '')) !== '') $s['tuya_key']= trim($p['tuya_key']);          # (tom: uændret)
            $s['tuya_ver']= in_array($p['tuya_ver'] ?? '', ['3.1', '3.3', '3.4', '3.5'], true) ? $p['tuya_ver'] : '3.3';
            $s['tuya_dp']=  max(1, min(255, (int)($p['tuya_dp'] ?? 1)));
            $s['ewe_appid']=  trim((string)($p['ewe_appid'] ?? ''));
            if (trim((string)($p['ewe_secret'] ?? '')) !== '') $s['ewe_secret']= trim($p['ewe_secret']);     # (tom: uændret)
            $s['ewe_region']= isset(EP_EWEAPI[$p['ewe_region'] ?? '']) ? $p['ewe_region'] : 'eu';
            $s['ewe_outlet']= preg_match('/^\d$/', (string)($p['ewe_outlet'] ?? '')) ? (string)$p['ewe_outlet'] : '';
            if (isset($p['ewe_device'])) {
                [$id, $nm]= array_pad(explode('|', (string)$p['ewe_device'], 2), 2, '');
                $s['ewe_device']= preg_replace('/[^A-Za-z0-9]/', '', $id);  $s['ewe_name']= $nm;
            }
            ep_write('settings.json', $s);
            ep_go('Gemt');
        case 'test_on': case 'test_off':
            try { ep_switch($s, $act === 'test_on');  ep_log('Prøve: '.($act === 'test_on' ? 'tænd' : 'sluk').' sendt');
                  ep_go($s['driver'] === 'none' ? 'Prøvetilstand - vælg hvordan laderelæet styres for at sende' : 'Sendt: '.($act === 'test_on' ? 'tænd' : 'sluk'), $s['driver'] === 'none' ? 'info' : 'success'); }
            catch (Throwable $e) { ep_go('Fejl: '.$e->getMessage(), 'error'); }
        case 'ewe_connect':
            if ($s['ewe_appid'] === '' || $s['ewe_secret'] === '') ep_go('Skriv APPID og APP SECRET og gem først', 'warning');
            header('Location: '.ep_ewe_authurl($s, $redirect));  exit;
        case 'ewe_list':
            try { $_SESSION['ep_devices']= ep_ewe_devices($s);  ep_go(count($_SESSION['ep_devices']).' enheder hentet - vælg laderelæet og gem'); }
            catch (Throwable $e) { ep_go('Fejl: '.$e->getMessage(), 'error'); }
    }
    ep_go('Ukendt handling', 'warning');
}

# ---------- eWeLink sender brugeren tilbage hertil med en kode ----------
if (isset($_GET['ewelink'], $_GET['code'])) {
    if (!$in) ep_go('Log ind og forbind igen', 'warning');
    if (!hash_equals((string)($_SESSION['ep_ewe_state'] ?? ''), (string)($_GET['state'] ?? ''))) ep_go('eWeLink: forkert state - prøv igen', 'error');
    try {
        ep_ewe_token($s, (string)$_GET['code'], (string)($_GET['region'] ?? ''), $redirect);
        $s['driver']= 'ewelink';  ep_write('settings.json', $s);
        $_SESSION['ep_devices']= ep_ewe_devices($s);
        ep_log('eWeLink forbundet ('.$s['ewe_region'].')');
        ep_go('eWeLink er forbundet - vælg laderelæet og gem');
    } catch (Throwable $e) { ep_go('eWeLink: '.$e->getMessage(), 'error'); }
}

if (isset($_SESSION['ep_msg'])) { [$msg, $kind]= $_SESSION['ep_msg'];  unset($_SESSION['ep_msg']); }

# ---------- Siden ----------
htm_Page_(titl:'Ladestyring', hint:'', info:'', algn:'center', inis:'<style>
  .epBtn { min-height:28px; margin:4px 3px; padding:3px 12px; border:0; border-radius:5px; cursor:pointer; font-size:14px; }
  .epBtn:hover { filter:brightness(1.12); }
  .cardW800 input[type=text], .cardW800 input:not([type]), .cardW800 input[type=password], .cardW800 select, .cardW320 input[type=password] {
      border:1px solid #9aa7b4; border-radius:4px; padding:3px 5px; background:#fff; color:#000; font-size:14px; }
</style>');
echo '<h1 style="margin:10px 0 6px; font-size:26px; color:#1a6e1a"><i class="fas fa-car-battery"></i>&nbsp;Ladestyring</h1>';
if ($msg !== '') htm_Alert(body:ep_enc($msg), kind:$kind);

if (!$in) {
    htm_Card_(capt:'Log ind', icon:'fas fa-bolt', hint:'', form:'f_login', clas:'cardW320', show:false, poup:false);
    if ($s['pass_hash'] === '')
        echo '<p>Der er ikke sat en adgangskode endnu. Kør på serveren:<br><code>php ladestyring-setpass.php</code></p>';
    else
        echo '<p><label>Adgangskode<br><input type="password" name="pwd" autocomplete="current-password" required autofocus style="width:90%; padding:4px"></label></p>'.
             ep_btn('login', 'Log ind', 'save', 'fas fa-right-to-bracket');
    htm_Card_end();
    htm_Page_end();
    exit;
}

$state= ep_tick();                                                   # (siden tjekker også - cron er stadig nødvendig)
try { [$tariff, $tname]= ep_tariff($s); } catch (Throwable $e) { $tariff= array_fill(0, 24, 0.0);  $tname= 'Fejl: '.$e->getMessage(); }
$prices= ep_prices($s);
[$plan, $wins]= ep_plan($s, $prices, $tariff);
[$want, $why]= ep_wanted($s, $plan, $state);
$now= time();  $q= $now - $now % 900;
$unit= $s['vat'] ? 'kr/kWh inkl. moms' : 'kr/kWh uden moms';

# Næste skift
$next= null;
for ($t= $q + 900; isset($prices[$t]); $t+= 900) if (isset($plan[$t]) !== $want) { $next= $t;  break; }
$ov= ($state['override']['until'] ?? 0) > $now ? $state['override'] : null;

# --- Status ---
htm_Card_(capt:'Status', icon:'fas fa-plug', hint:'', form:'f_status', clas:'cardW800', vhgh:'none');
$on= $state['on'] ?? false;
echo '<div style="display:flex; gap:18px; align-items:center; flex-wrap:wrap; padding:6px 10px; text-align:left">'.
     '<div style="font-size:40px; color:'.($on ? '#1a9e3a' : '#888').'"><i class="fas fa-'.($on ? 'bolt' : 'power-off').'"></i></div>'.
     '<div style="flex:1; min-width:220px"><b style="font-size:20px">'.($on ? 'Lader' : 'Slukket').'</b><br>'.ep_enc($why).
       ($next ? '<br>Næste skift: <b>'.date('H:i', $next).'</b> ('.(isset($plan[$next]) ? 'tænd' : 'sluk').')' : '').
       (isset($prices[$q]) ? '<br>Pris nu: <b>'.number_format(ep_total($s, $prices[$q], $tariff, $q), 2, ',', '.').'</b> '.$unit : '').
       (($state['error'] ?? '') !== '' ? '<br><span style="color:crimson"><i class="fas fa-triangle-exclamation"></i> '.ep_enc($state['error']).'</span>' : '').
       ($s['driver'] === 'none' ? '<br><span style="color:#b36b00">Prøvetilstand: laderelæet styres ikke endnu (se Laderelæ nedenfor)</span>' : '').
     '</div>'.
     '<div style="margin-left:auto; text-align:right">'.
       ep_btn('on', 'Tænd nu', 'save', 'fas fa-bolt', 'Tænd i det antal timer der står nedenfor - derefter automatik').
       ep_btn('off', 'Sluk nu', 'eras', 'fas fa-power-off', 'Sluk i det antal timer der står nedenfor - derefter automatik').
       '<br>i <input name="for" value="2" size="3" inputmode="decimal" style="padding:3px"> timer'.
       ($ov ? '<br>'.ep_btn('auto', 'Tilbage til automatik', 'goon', 'fas fa-robot') : '').
     '</div></div>';
htm_Card_end();

const EP_WDAY= ['søn', 'man', 'tir', 'ons', 'tor', 'fre', 'lør'];

# Søjlediagram: $green [t => true] grønne søjler - $min / $max fælles y-akse for begge diagrammer
function ep_svg($vals, $green, $q, $min, $max, $unit) {
    $n= count($vals);  $L= 34;  $W= 760;  $H= 220;  $bw= ($W - $L) / max($n, 1);  $sc= $H / ($max - $min);  $y0= $max * $sc;
    $svg= '<svg viewBox="0 0 '.$W.' '.($H + 34).'" style="width:100%; max-width:'.$W.'px; font:10px sans-serif">';
    $r= $max - $min;  $step= 5;
    foreach ([0.1, 0.2, 0.25, 0.5, 1, 2, 5] as $st) if ($r / $st <= 6) { $step= $st;  break; }
    for ($v= ceil($min / $step) * $step; $v <= $max + 1e-9; $v+= $step) {                        # Vandrette linjer med kr
        $y= round($y0 - $v * $sc, 1);
        $svg.= '<line x1="'.$L.'" x2="'.$W.'" y1="'.$y.'" y2="'.$y.'" stroke="'.(abs($v) < 1e-9 ? '#888' : '#e3e7eb').'"/>'.
               '<text x="'.($L - 4).'" y="'.($y + 3).'" text-anchor="end" fill="#555">'.number_format($v, $step < 1 ? 2 : 0, ',', '.').'</text>';
    }
    $hstep= $n > 300 ? 6 : 3;  $i= 0;
    foreach ($vals as $t => $v) {
        $x= $L + $i * $bw;  $h= abs($v) * $sc;  $y= $v >= 0 ? $y0 - $h : $y0;
        $c= isset($green[$t]) ? '#2fa84f' : ($t < $q ? '#c9d3dd' : '#7f9bb5');
        $svg.= '<rect x="'.round($x, 2).'" y="'.round($y, 2).'" width="'.round(max($bw - ($n > 300 ? 0.15 : 0.6), 0.4), 2).'" height="'.round(max($h, 0.5), 2).'" fill="'.$c.'"'.
               ($t === $q ? ' stroke="#d33" stroke-width="1.5"' : '').'><title>'.EP_WDAY[(int)date('w', $t)].' '.date('j/n H:i', $t).' - '.number_format($v, 2, ',', '.').' '.$unit.'</title></rect>';
        if (date('i', $t) === '00' && (int)date('G', $t) % $hstep === 0 && ($n <= 300 || date('G', $t) !== '0'))
            $svg.= '<text x="'.round($x, 1).'" y="'.($H + 12).'" fill="#555">'.date('H', $t).'</text>';
        if (date('Hi', $t) === '0000')
            $svg.= ($i > 0 ? '<line x1="'.round($x, 1).'" x2="'.round($x, 1).'" y1="0" y2="'.($H + 2).'" stroke="#999" stroke-dasharray="3,3"/>' : '').
                   '<text x="'.round($x + 3, 1).'" y="'.($H + 28).'" fill="#333">'.EP_WDAY[(int)date('w', $t)].' '.date('j/n', $t).'</text>';
        $i++;
    }
    return $svg.'</svg>';
}

$vals= [];  foreach ($prices as $t => $p) $vals[$t]= ep_total($s, $p, $tariff, $t);
$hist= [];  foreach (ep_history($s) as $t => $p) $hist[$t]= ep_total($s, $p, $tariff, $t);      # (dagens nettarif - tariffen skifter sjældent)
$all= $vals + $hist;
$ymax= $all ? max(max($all), 0.01) : 1;  $ymin= $all ? min(0, min($all)) : 0;

# --- Priser ---
htm_Card_(capt:'Elpris i dag og i morgen', icon:'fas fa-chart-column', hint:'', clas:'cardW800', vhgh:'none');
if (!$prices) echo '<p style="color:crimson">Priserne kunne ikke hentes - se loggen. Nødplan: lader fra kl. 02.</p>';
else {
    echo ep_svg($vals, $plan, $q, $ymin, $ymax, $unit);
    $tom= strtotime('tomorrow');
    $hasTom= isset($prices[$tom]);
    echo '<p style="text-align:left; margin:6px 10px">'.
         '<span style="color:#2fa84f">&#9632;</span> lader &nbsp; <span style="color:#7f9bb5">&#9632;</span> venter &nbsp; '.
         '<span style="color:#d33">&#9633;</span> nu &nbsp; - '.$unit.': spotpris'.($tname !== '' ? ' + nettarif ('.ep_enc($tname).')' : '').
         ((float)$s['fixed'] > 0 ? ' + faste tillæg' : '').'.'.
         ($hasTom ? '' : '<br>Morgendagens priser kommer ca. kl. 13.').'</p>';
    # De valgte perioder
    $rows= '';
    foreach ($wins as $w) {
        if ($w['b'] <= $now) continue;                                  # (perioder der er slut)
        $sel= array_filter(array_keys($plan), fn($t) => $t >= $w['a'] && $t < $w['b']);
        if (!$sel) continue;
        $sum= 0;  foreach ($sel as $t) $sum+= $vals[$t];
        $blocks= [];  $st0= null;  $prev= null;
        foreach ($sel as $t) { if ($prev === null || $t !== $prev + 900) { if ($st0 !== null) $blocks[]= date('j/n H:i', $st0).'-'.date('H:i', $prev + 900);  $st0= $t; }  $prev= $t; }
        $blocks[]= date('j/n H:i', $st0).'-'.date('H:i', $prev + 900);
        $rows.= '<tr><td>'.date('j/n H:i', $w['a']).' - '.date(date('Ymd', $w['a']) === date('Ymd', $w['b'] - 1) ? 'H:i' : 'j/n H:i', $w['b']).'</td><td>'.implode(', ', $blocks).'</td><td style="text-align:right">'.
                number_format($sum / count($sel), 2, ',', '.').'</td><td>'.($w['complete'] ? '' : 'foreløbig').'</td></tr>';
    }
    if ($rows) echo '<table style="margin:4px 10px; border-collapse:collapse; text-align:left" cellpadding="3"><tr style="background:#eef"><th>Periode</th><th>Lader</th><th>Snit</th><th></th></tr>'.$rows.'</table>';
}
htm_Card_end();

# --- Historik ---
htm_Card_(capt:'Den seneste uge', icon:'fas fa-clock-rotate-left', hint:'', clas:'cardW800', vhgh:'none');
if (!$hist) echo '<p style="color:crimson">Historikken kunne ikke hentes - se loggen.</p>';
else {
    $was= ep_onquarters(array_key_first($hist));
    echo ep_svg($hist, $was, PHP_INT_MAX, $ymin, $ymax, $unit);
    $on= array_intersect_key($hist, $was);
    $avg= count($hist) ? array_sum($hist) / count($hist) : 0;
    echo '<p style="text-align:left; margin:6px 10px"><span style="color:#2fa84f">&#9632;</span> ladede (ifølge loggen) &nbsp; '.
         '<span style="color:#c9d3dd">&#9632;</span> slukket &nbsp; - samme skala som ovenfor, '.$unit.' med dagens nettarif.<br>'.
         ($on ? 'Ladet i alt <b>'.str_replace('.', ',', (string)(count($on) / 4)).' timer</b> til snitpris <b>'.number_format(array_sum($on) / count($on), 2, ',', '.').'</b> - ugens snitpris var '
               : 'Ingen opladning i loggen for perioden - ugens snitpris var ').number_format($avg, 2, ',', '.').'.</p>';
}
htm_Card_end();

# --- Indstillinger ---
htm_Card_(capt:'Indstillinger', icon:'fas fa-sliders', hint:'', form:'f_set', clas:'cardW800', vhgh:'none');
$opt= fn($v, $cur) => '<option value="'.ep_enc($v).'"'.((string)$v === (string)$cur ? ' selected' : '').'>';

$sel= fn($name, $cur, $from, $to) => '<select name="'.$name.'">'.implode('', array_map(fn($h) => $opt($h, $cur).sprintf('%02d:00', $h).'</option>', range($from, $to))).'</select>';
$net= '';  foreach (EP_NETCOMP as $k => $v) $net.= $opt($k, $s['net']).ep_enc($v[0]).'</option>';
echo '<table style="margin:6px 10px; text-align:left" cellpadding="4">'.
  '<tr><td>Timer med opladning</td><td><input name="hours" value="'.ep_enc(str_replace('.', ',', (string)$s['hours'])).'" size="5" inputmode="decimal"> timer pr. opladning (kvarter: 0,25)</td></tr>'.
  '<tr><td>Beregning</td><td><select name="span">'.$opt('ahead', $s['span']).'Billigst blandt alle kendte priser fra nu (op til ca. 35 timer frem)</option>'.$opt('day', $s['span']).'Hver periode for sig (fx hvert døgn)</option></select>'.
      '<br><small>Kendte priser: én opladning hver gang morgendagens priser kommer (ca. kl. 13) - perioden nedenfor bestemmer kun hvilke klokkeslæt der må lades i</small></td></tr>'.
  '<tr><td>Måde</td><td><select name="mode">'.$opt('block', $s['mode']).'Én sammenhængende blok</option>'.$opt('spread', $s['mode']).'De billigste kvarterer (kan være spredt)</option></select></td></tr>'.
  '<tr><td>Periode</td><td>fra '.$sel('from', $s['from'], 0, 23).' til '.$sel('to', $s['to'], 1, 24).' &nbsp; <small>(fx 18 til 07: hen over natten - 00 til 24: hele døgnet)</small></td></tr>'.
  '<tr><td>Netselskab</td><td><select name="net">'.$net.'</select><br><small>Andet: GLN <input name="net_gln" value="'.ep_enc($s['net_gln']).'" size="14"> tarifkode <input name="net_code" value="'.ep_enc($s['net_code']).'" size="9"></small></td></tr>'.
  '<tr><td>Faste tillæg</td><td><input name="fixed" value="'.ep_enc(str_replace('.', ',', (string)$s['fixed'])).'" size="5" inputmode="decimal"> kr/kWh uden moms <small>(elafgift m.m. - ændrer ikke valget, kun den viste pris)</small></td></tr>'.
  '<tr><td>Moms</td><td><label><input type="checkbox" name="vat" value="1"'.($s['vat'] ? ' checked' : '').'> vis priser med 25 % moms</label></td></tr>'.
  '</table><div style="text-align:left; padding:0 10px">'.ep_btn('save', 'Gem', 'save', 'fas fa-floppy-disk').'</div>';
htm_Card_end();

# --- Laderelæ ---
htm_Card_(capt:'Laderelæ', icon:'fas fa-toggle-on', hint:'', form:'f_sw', clas:'cardW800', vhgh:'none', fold:$s['driver'] === 'none' ? '' : 'closed');
$tok= ep_read('ewelink.json');
$devs= $_SESSION['ep_devices'] ?? [];
$dsel= '';
if ($devs) {
    $dsel= '<select name="ewe_device">';
    foreach ($devs as $d) $dsel.= $opt($d['id'].'|'.$d['name'], $s['ewe_device'].'|'.$s['ewe_name']).ep_enc($d['name'].' ('.$d['id'].')'.($d['online'] ? '' : ' - offline').($d['multi'] ? ' - flere kanaler' : '')).'</option>';
    $dsel.= '</select>';
}
$reg= '';  foreach (EP_EWEAPI as $k => $v) $reg.= $opt($k, $s['ewe_region']).strtoupper($k).'</option>';
$rad= fn($v, $t) => '<label><input type="radio" name="driver" value="'.$v.'"'.($s['driver'] === $v ? ' checked' : '').'> '.$t.'</label><br>';
$ver= '';  foreach (['3.1', '3.3', '3.4', '3.5'] as $v) $ver.= $opt($v, $s['tuya_ver']).$v.'</option>';
echo '<div style="text-align:left; padding:4px 10px">'.
  '<p>'.$rad('none', 'Prøvetilstand - intet sendes').$rad('tuya', 'Tuya-relæ på hjemmenettet (ingen cloud)').
        $rad('http', 'Andet lokalt relæ (Shelly, OpenBeken, Tasmota)').$rad('webhook', 'eWeLink webhook').$rad('ewelink', 'eWeLink API').'</p>'.
  '<fieldset style="margin-bottom:8px"><legend>Tuya (Smart Life) - lokalt</legend>'.
    'Enheds-id: <input name="tuya_id" value="'.ep_enc($s['tuya_id']).'" size="24"> &nbsp; Local key: <input type="password" name="tuya_key" value="" size="18" placeholder="'.($s['tuya_key'] !== '' ? 'uændret' : '').'" autocomplete="off"><br>'.
    'IP-adresse: <input name="tuya_ip" value="'.ep_enc($s['tuya_ip']).'" size="15"> <small>(Auto: søges på nettet - en fast adresse er hurtigere)</small><br>'.
    'Protokol: <select name="tuya_ver">'.$ver.'</select> &nbsp; Kanal (DP): <input name="tuya_dp" value="'.ep_enc($s['tuya_dp']).'" size="3"> <small>(1 for et relæ med én kanal)</small><br>'.
    '<small>Id, nøgle og protokol: kør én gang <code>python -m tinytuya wizard</code> (gratis Tuya-udviklerkonto) - og <code>python -m tinytuya scan</code> for IP og protokol. '.
    'Nøglen skifter, hvis relæet parres i appen igen.</small></fieldset>'.
  '<fieldset style="margin-bottom:8px"><legend>Andet lokalt relæ</legend>'.
    'Tænd: <input name="http_on" value="'.ep_enc($s['http_on']).'" style="width:85%" placeholder="http://192.168.1.60/rpc/Switch.Set?id=0&amp;on=true"><br>'.
    'Sluk: <input name="http_off" value="'.ep_enc($s['http_off']).'" style="width:85%" placeholder="http://192.168.1.60/rpc/Switch.Set?id=0&amp;on=false"><br>'.
    '<small>Shelly: /rpc/Switch.Set?id=0&amp;on=true - OpenBeken / Tasmota: /cm?cmnd=Power%20On og /cm?cmnd=Power%20Off</small></fieldset>'.
  '<details><summary>eWeLink (cloud)</summary>'.
  '<fieldset style="margin-bottom:8px"><legend>Webhook (kræver eWeLink Advanced)</legend>'.
    '<small>I eWeLink-appen: lav to scener med "Webhook" som udløser - én der tænder laderelæet og én der slukker. Indsæt deres adresser:</small><br>'.
    'Tænd: <input name="hook_on" value="'.ep_enc($s['hook_on']).'" style="width:85%"><br>Sluk: <input name="hook_off" value="'.ep_enc($s['hook_off']).'" style="width:85%"></fieldset>'.
  '<fieldset><legend>eWeLink API</legend>'.
    '<small>Opret en app på <a href="https://dev.ewelink.cc" target="_blank" rel="noopener">dev.ewelink.cc</a> med denne Redirect URL: <code>'.ep_enc($redirect).'</code></small><br>'.
    'APPID: <input name="ewe_appid" value="'.ep_enc($s['ewe_appid']).'" size="34"> &nbsp; APP SECRET: <input type="password" name="ewe_secret" value="" size="20" placeholder="'.($s['ewe_secret'] !== '' ? 'uændret' : '').'" autocomplete="off"><br>'.
    'Region: <select name="ewe_region">'.$reg.'</select> &nbsp; Kanal: <input name="ewe_outlet" value="'.ep_enc($s['ewe_outlet']).'" size="2" placeholder="-"> <small>(tom: relæ med én kanal)</small><br>'.
    (empty($tok['at']) ? '<span style="color:#b36b00">Ikke forbundet</span>' : '<span style="color:#1a9e3a">Forbundet</span> - nøglen fornyes automatisk ('.date('j/n', $tok['rtExp'] ?? 0).')').
    ' &nbsp; '.ep_btn('ewe_connect', 'Forbind eWeLink', 'goon', 'fas fa-link', 'Gem APPID og SECRET først').
    (!empty($tok['at']) ? ep_btn('ewe_list', 'Hent enheder', 'navi', 'fas fa-list') : '').
    '<br>Laderelæ: '.($dsel ?: '<b>'.ep_enc($s['ewe_name'] ?: '(ingen valgt)').'</b> '.ep_enc($s['ewe_device'])).
  '</fieldset></details>'.
  '<p>'.ep_btn('driver', 'Gem', 'save', 'fas fa-floppy-disk').ep_btn('test_on', 'Prøv: tænd', 'navi', 'fas fa-bolt').ep_btn('test_off', 'Prøv: sluk', 'navi', 'fas fa-power-off').'</p>'.
  '<p><small>Tjek hvert 15. minut - Windows: <code>start-ladestyring.cmd</code> opretter opgaven (Opgavestyring). Linux / Raspberry Pi (crontab -e):<br>'.
  '<code>*/15 * * * * php '.ep_enc(__DIR__).'/ladestyring-cron.php</code><br>'.
  'Seneste tjek: '.(isset($state['tick']) ? date('j/n H:i', $state['tick']) : '-').'</small></p></div>';
htm_Card_end();

# --- Log ---
htm_Card_(capt:'Log', icon:'fas fa-list', hint:'', clas:'cardW800', vhgh:'none', fold:'closed');
$log= is_file(ep_file('log.txt')) ? array_reverse(file(ep_file('log.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
$lcat= fn($l) => preg_match('/^\S+ \S+  (TÆNDT|SLUKKET)/u', $l) ? 'skift' : (preg_match('/FEJL|-fejl:/u', $l) ? 'fejl'
              : (str_contains($l, 'Prøve:') ? 'test' : (str_contains($l, 'adgangskode') ? 'login' : 'andet')));
$lnames= ['skift' => 'Tænd / sluk', 'fejl' => 'Fejl', 'test' => 'Prøver', 'login' => 'Login', 'andet' => 'Andet'];
$lcol=   ['skift' => '#1a6e1a', 'fejl' => 'crimson', 'test' => '#0064b4', 'login' => '#b36b00', 'andet' => '#555'];
$rows= '';  $cnt= array_fill_keys(array_keys($lnames), 0);
foreach ($log as $l) { $c= $lcat($l);  $cnt[$c]++;  $rows.= '<div class="epL" data-c="'.$c.'" style="color:'.$lcol[$c].'">'.ep_enc($l).'</div>'; }
echo '<div style="text-align:left; margin:6px 10px">';
foreach ($lnames as $k => $v)
    echo '<label style="margin-right:12px; white-space:nowrap; color:'.$lcol[$k].'"><input type="checkbox" class="epC" value="'.$k.'" checked onchange="epF()"> '.$v.' ('.$cnt[$k].')</label>';
echo '<br><input type="search" id="epQ" placeholder="Søg i loggen, fx 24/10 eller 2026-10-24" oninput="epF()" style="width:60%; margin-top:6px; padding:3px 5px; border:1px solid #9aa7b4; border-radius:4px">'.
     ' &nbsp; <small>viser <b id="epN">'.count($log).'</b> af '.count($log).' linjer - nyeste øverst</small></div>'.
     '<div style="text-align:left; margin:6px 10px; max-height:420px; overflow:auto; font:13px monospace; white-space:pre-wrap">'.($rows ?: 'Tom').'</div>'.
     '<script>function epF(){var q=document.getElementById("epQ").value.toLowerCase().trim(),on={},n=0,m;'.
     'm=q.match(/^(\d{1,2})\/(\d{1,2})$/);if(m)q="-"+("0"+m[2]).slice(-2)+"-"+("0"+m[1]).slice(-2)+" ";'.          # 24/10 -> -10-24
     'document.querySelectorAll(".epC").forEach(function(c){on[c.value]=c.checked;});'.
     'document.querySelectorAll(".epL").forEach(function(d){var v=on[d.dataset.c]&&(!q||d.textContent.toLowerCase().indexOf(q)>=0);d.style.display=v?"":"none";if(v)n++;});'.
     'document.getElementById("epN").textContent=n;}</script>';
htm_Card_end();

echo '<form method="post" style="margin:10px">'.csrf_field(true).ep_btn('logout', 'Log ud', 'navi', 'fas fa-right-from-bracket').'</form>';
htm_Page_end();
