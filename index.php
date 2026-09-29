<?php
session_start();
$configuredDataFile=getenv('CODEBREAK_DATA_FILE');
define('DATA_FILE', $configuredDataFile!==false && $configuredDataFile!=='' ? $configuredDataFile : __DIR__ . '/rooms.json');
define('ROOM_EMPTY_TTL', 900);
define('PLAYER_STALE_AFTER', 90);

function cleanupEmptyRooms(&$rooms) {
    $now=time(); $changed=false;
    foreach ($rooms as $code=>&$room) {
        $humans=array_filter($room['players']??[],fn($p)=>empty($p['is_bot']));
        $emptySince=null;
        if (!$humans) {
            $emptySince=$room['empty_since']??$room['created_at']??$now;
        } else {
            $allAway=true; $lastDeparture=0;
            foreach ($humans as $player) {
                $lastSeen=(int)($player['last_seen']??$room['created_at']??$now);
                $leftAt=(int)($player['left_at']??0);
                if (!$leftAt && $lastSeen>$now-PLAYER_STALE_AFTER) { $allAway=false; break; }
                $lastDeparture=max($lastDeparture,$leftAt?:$lastSeen);
            }
            if ($allAway) $emptySince=$room['empty_since']??$lastDeparture;
        }
        if ($emptySince===null) {
            if (isset($room['empty_since'])) { unset($room['empty_since']); $changed=true; }
            continue;
        }
        if (!isset($room['empty_since'])) { $room['empty_since']=$emptySince; $changed=true; }
        if ($now-(int)$room['empty_since']>=ROOM_EMPTY_TTL) { unset($rooms[$code]); $changed=true; }
    }
    unset($room);
    return $changed;
}

function reply($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
function fail($message) { reply(['status'=>'error', 'msg'=>$message]); }
function loadRooms() {
    if (!file_exists(DATA_FILE)) return [];
    $rooms = json_decode(file_get_contents(DATA_FILE), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($rooms)) throw new RuntimeException('Invalid room storage');
    return $rooms;
}
function saveRooms($rooms) {
    $json = json_encode($rooms, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $temp = DATA_FILE . '.tmp';
    if (file_put_contents($temp, $json) !== strlen($json) || !rename($temp, DATA_FILE)) {
        throw new RuntimeException('Cannot save rooms');
    }
}
function countHits($guess, $secret) {
    $hits = 0;
    for ($i=0; $i<strlen($secret); $i++) if (($guess[$i]??null)===$secret[$i]) $hits++;
    return $hits;
}
function isEasyCode($code) {
    $d = array_map('intval', str_split($code));
    if (count(array_unique($d))<=1 || (strlen($code)<=4 && count(array_unique($d))<=2)) return true;
    for ($i=0; $i<count($d)-2; $i++) {
        if (abs($d[$i+1]-$d[$i])===1 && $d[$i+1]-$d[$i]===$d[$i+2]-$d[$i+1]) return true;
    }
    return false;
}
function generateSecret($len=4) {
    do { $code=''; for ($i=0;$i<$len;$i++) $code.=random_int(0,9); } while (isEasyCode($code));
    return $code;
}
function newPlayer($name, $bot=false) {
    return ['id'=>($bot?'bot_':'p_').bin2hex(random_bytes(12)), 'name'=>$name,
        'secret'=>'', 'ready'=>false, 'eliminated'=>false, 'is_bot'=>$bot,
        'resume_token'=>bin2hex(random_bytes(32))];
}
function activePlayers($room) { return array_filter($room['players'], fn($p)=>!$p['eliminated']); }
function finishGame(&$room) {
    if ($room['status']!=='playing') return;
    $active=activePlayers($room);
    if (count($active)<=1) {
        $room['status']='finished';
        $room['winner_id']=array_key_first($active);
        $room['attacks_this_turn']=[];
    }
}
function advanceTurn(&$room) {
    finishGame($room);
    if ($room['status']!=='playing') return;
    $total=count($room['turn_order']); $old=$room['current_turn_idx'];
    for ($step=1;$step<=$total;$step++) {
        $idx=($old+$step)%$total;
        $p=$room['players'][$room['turn_order'][$idx]]??null;
        if ($p && !$p['eliminated']) {
            if ($idx<=$old) $room['round']++;
            $room['current_turn_idx']=$idx;
            $room['attacks_this_turn']=[];
            return;
        }
    }
}
function recordAttack(&$room, $pid, $targetId, $guess) {
    $target=&$room['players'][$targetId];
    $confirmed=!empty($target['is_bot']) || !empty($room['players'][$pid]['is_bot']);
    $hits=$confirmed?countHits($guess,$target['secret']):null;
    $id='a_'.bin2hex(random_bytes(12));
    $room['history'][]=['id'=>$id,'attacker_id'=>$pid,'target_id'=>$targetId,'guess'=>$guess,
        'hits'=>$hits,'confirmed'=>$confirmed,'round'=>$room['round'],'ts'=>time()];
    $room['attacks_this_turn'][$pid][$targetId]=true;
    if ($hits===$room['code_length']) $target['eliminated']=true;
    return $id;
}
function settleGame(&$room) {
    // Pending attacks against eliminated players can no longer be answered manually.
    foreach ($room['history'] as &$h) {
        $p=$room['players'][$h['target_id']]??null;
        if (!$h['confirmed'] && $p && $p['eliminated']) {
            $h['hits']=countHits($h['guess'],$p['secret']); $h['confirmed']=true;
        }
    }
    unset($h);
    finishGame($room);
    // Each bot is processed at most once here; human turns remain in their original order.
    for ($i=0;$i<count($room['players']) && $room['status']==='playing';$i++) {
        $pid=$room['turn_order'][$room['current_turn_idx']]??null;
        if (!$pid || !isset($room['players'][$pid]) || $room['players'][$pid]['eliminated']) {
            advanceTurn($room); continue;
        }
        if (!$room['players'][$pid]['is_bot']) {
            $remaining=array_filter(activePlayers($room), fn($p)=>$p['id']!==$pid && empty($room['attacks_this_turn'][$pid][$p['id']]));
            if (!$remaining) { advanceTurn($room); continue; }
            break;
        }
        foreach (activePlayers($room) as $target) {
            if ($target['id']!==$pid && empty($room['attacks_this_turn'][$pid][$target['id']])) {
                recordAttack($room,$pid,$target['id'],generateSecret($room['code_length']));
            }
        }
        advanceTurn($room);
    }
}
function startWhenReady(&$room) {
    if ($room['status']!=='setup' || count($room['players'])<2) return;
    foreach ($room['players'] as $p) if (!$p['ready']) return;
    $room['status']='playing';
    $room['turn_order']=array_keys($room['players']);
    $room['current_turn_idx']=0; $room['round']=1; $room['attacks_this_turn']=[];
    settleGame($room);
}
function returnToLobby(&$room) {
    $room['status']='lobby';
    foreach ($room['players'] as &$p) { $p['ready']=false; $p['secret']=''; }
    unset($p);
}
function signIn(&$room, $pid) {
    if (empty($room['players'][$pid]['resume_token'])) $room['players'][$pid]['resume_token']=bin2hex(random_bytes(32));
    $_SESSION['player_id']=$pid; $_SESSION['room_code']=$room['code'];
    $_SESSION['memberships'][$room['code']]=$pid;
    return ['status'=>'ok','code'=>$room['code'],'player_id'=>$pid,
        'resume_token'=>$room['players'][$pid]['resume_token'],'room_status'=>$room['status']];
}
function inputString($input,$key,$default='') {
    if (isset($input[$key]) && !is_string($input[$key])) fail('Некорректные данные');
    return trim($input[$key]??$default);
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // One lock covers the complete read / modify / write transaction, including state reads.
    $lock=fopen(DATA_FILE.'.lock','c');
    if (!$lock || !flock($lock,LOCK_EX)) fail('Не удалось открыть хранилище');
    try {
        $action=$_GET['action'];
        if ($action!=='state' && $_SERVER['REQUEST_METHOD']!=='POST') fail('Требуется POST');
        $raw=file_get_contents('php://input');
        $input=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];
        if (!is_array($input)) fail('Некорректные данные');
        $rooms=loadRooms();
        if (cleanupEmptyRooms($rooms)) saveRooms($rooms);
        if ($action==='create_room' || $action==='join_room') {
            $name=inputString($input,'name','Игрок');
            if ($name==='' || preg_match_all('/./us',$name)>16) fail('Ник должен содержать от 1 до 16 символов');
            $code=$action==='join_room'?strtoupper(inputString($input,'code')):($_SESSION['room_code']??'');
            if ($action==='create_room' && isset($rooms[$code]['players'][$_SESSION['player_id']??''])) {
                $result=signIn($rooms[$code],$_SESSION['player_id']); saveRooms($rooms); reply($result);
            }
            if ($action==='create_room') {
                $len=$input['code_length']??4;
                if (!in_array($len,[4,5,6],true)) fail('Недопустимая длина кода');
                $availableCodes=[];
                for ($i=0;$i<1000;$i++) {
                    $candidate=str_pad((string)$i,3,'0',STR_PAD_LEFT);
                    if (!isset($rooms[$candidate])) $availableCodes[]=$candidate;
                }
                if (!$availableCodes) fail('Нет свободных кодов комнат');
                $code=$availableCodes[random_int(0,count($availableCodes)-1)];
                $p=newPlayer($name); $pid=$p['id'];
                $rooms[$code]=['code'=>$code,'host_id'=>$pid,'status'=>'lobby','code_length'=>$len,
                    'players'=>[$pid=>$p],'history'=>[],'turn_order'=>[],'current_turn_idx'=>0,
                    'round'=>1,'attacks_this_turn'=>[],'created_at'=>time()];
            } else {
                if (!isset($rooms[$code])) fail('Комната не найдена');
                $room=&$rooms[$code]; $pid=null;
                $sessionPid=$_SESSION['memberships'][$code]??(($_SESSION['room_code']??'')===$code?($_SESSION['player_id']??''):null);
                if ($sessionPid && isset($room['players'][$sessionPid]) && empty($room['players'][$sessionPid]['kicked'])) $pid=$sessionPid;
                $token=inputString($input,'resume_token');
                $pin=inputString($input,'secret');
                if (!$pid) foreach ($room['players'] as $id=>$p) {
                    if ($p['is_bot'] || !empty($p['kicked'])) continue;
                    $tokenMatch=$token!=='' && !empty($p['resume_token']) && hash_equals($p['resume_token'],$token);
                    $pinMatch=$p['name']===$name && $pin!=='' && $p['secret']!=='' && hash_equals($p['secret'],$pin) && !$p['eliminated'];
                    if ($tokenMatch || $pinMatch) { $pid=$id; break; }
                }
                if (!$pid) {
                    foreach ($room['players'] as $p) if (strcasecmp($p['name'],$name)===0) fail('Этот ник уже в комнате. Для возврата используйте прежний браузер или введите свой PIN.');
                    if ($room['status']!=='lobby') fail('Игра уже началась. Вернуться можно с прежним ником и своим PIN.');
                    if (count($room['players'])>=4) fail('Комната заполнена');
                    $p=newPlayer($name); $pid=$p['id']; $room['players'][$pid]=$p;
                }
            }
            $rooms[$code]['players'][$pid]['last_seen']=time();
            unset($rooms[$code]['players'][$pid]['left_at'],$rooms[$code]['empty_since']);
            $result=signIn($rooms[$code],$pid); saveRooms($rooms); reply($result);
        }
        $code=$_SESSION['room_code']??''; $pid=$_SESSION['player_id']??'';
        if (!isset($rooms[$code]['players'][$pid]) || !empty($rooms[$code]['players'][$pid]['kicked'])) {
            if ($action==='state') reply(['status'=>'no_room']);
            fail('Вы больше не участник комнаты');
        }
        $room=&$rooms[$code];
        if (in_array($action,['set_code_length','add_bot','remove_bot','start_game','kick_player','close_room'],true) && $room['host_id']!==$pid) fail('Нет прав');
        if (in_array($action,['set_code_length','add_bot','remove_bot','start_game'],true) && $room['status']!=='lobby') fail('Действие доступно только в лобби');
        switch ($action) {
            case 'set_code_length':
                $len=$input['len']??null;
                if (!in_array($len,[4,5,6],true)) fail('Недопустимая длина кода');
                $room['code_length']=$len; break;
            case 'add_bot':
                $bots=array_filter($room['players'],fn($p)=>$p['is_bot']);
                if (count($bots)>=2 || count($room['players'])>=4) fail('Нет места для бота');
                $num=1; $names=array_column($room['players'],'name');
                while (in_array('BOT'.$num,$names,true)) $num++;
                $p=newPlayer('BOT'.$num,true); $room['players'][$p['id']]=$p; break;
            case 'remove_bot':
                $bots=array_keys(array_filter($room['players'],fn($p)=>$p['is_bot']));
                if ($bots) unset($room['players'][end($bots)]); break;
            case 'start_game':
                if (count($room['players'])<2) fail('Нужен минимум 1 соперник');
                $room['status']='setup';
                foreach ($room['players'] as &$p) if ($p['is_bot']) { $p['secret']=generateSecret($room['code_length']); $p['ready']=true; }
                unset($p); startWhenReady($room); break;
            case 'kick_player':
                $kick=inputString($input,'player_id');
                if ($kick===$pid || !isset($room['players'][$kick])) fail('Нельзя удалить этого игрока');
                if (in_array($room['status'],['lobby','setup'],true)) {
                    unset($room['players'][$kick]);
                    if (count($room['players'])<2) returnToLobby($room);
                    startWhenReady($room);
                } else { $room['players'][$kick]['eliminated']=true; $room['players'][$kick]['kicked']=true; settleGame($room); }
                break;
            case 'close_room':
                unset($rooms[$code],$_SESSION['room_code'],$_SESSION['player_id'],$_SESSION['memberships'][$code]);
                saveRooms($rooms); reply(['status'=>'ok']);
            case 'leave_room':
                if (in_array($room['status'],['lobby','setup'],true)) {
                    unset($room['players'][$pid],$_SESSION['memberships'][$code]);
                    $humans=array_filter($room['players'],fn($p)=>!$p['is_bot']);
                    if (!$humans) $room['empty_since']=time();
                    else {
                        if ($room['host_id']===$pid) $room['host_id']=array_key_first($humans);
                        if (count($room['players'])<2) returnToLobby($room);
                        startWhenReady($room);
                    }
                } else {
                    $room['players'][$pid]['left_at']=time();
                    if ($room['host_id']===$pid) {
                        foreach ($room['players'] as $nextId=>$player) {
                            if ($nextId!==$pid && empty($player['is_bot']) && empty($player['eliminated'])) { $room['host_id']=$nextId; break; }
                        }
                    }
                }
                unset($_SESSION['room_code'],$_SESSION['player_id']); break;
            case 'set_code':
                if ($room['status']!=='setup') fail('Код можно задать только перед игрой');
                $secret=inputString($input,'secret');
                if (!preg_match('/^[0-9]{'.$room['code_length'].'}$/D',$secret)) fail('Введите ровно '.$room['code_length'].' цифр');
                $room['players'][$pid]['secret']=$secret; $room['players'][$pid]['ready']=true;
                startWhenReady($room); break;
            case 'attack':
                if ($room['status']!=='playing' || $room['players'][$pid]['eliminated']) fail('Вы не можете атаковать');
                if (($room['turn_order'][$room['current_turn_idx']]??null)!==$pid) fail('Не ваш ход');
                foreach ($room['history'] as $h) if (!$h['confirmed'] && $h['target_id']===$pid) fail('Сначала подтвердите входящие атаки');
                $target=inputString($input,'target_id'); $guess=inputString($input,'guess');
                if ($target===$pid || !isset($room['players'][$target]) || $room['players'][$target]['eliminated']) fail('Недопустимая цель');
                if (!preg_match('/^[0-9]{'.$room['code_length'].'}$/D',$guess)) fail('Введите ровно '.$room['code_length'].' цифр');
                if (!empty($room['attacks_this_turn'][$pid][$target])) fail('Этот соперник уже атакован');
                recordAttack($room,$pid,$target,$guess); settleGame($room); break;
            case 'confirm':
                if ($room['status']!=='playing' || $room['players'][$pid]['eliminated']) fail('Нельзя подтвердить атаку');
                $id=inputString($input,'attack_id'); $found=false;
                foreach ($room['history'] as &$h) {
                    if ($h['id']!==$id || $h['target_id']!==$pid) continue;
                    $found=true;
                    if ($h['confirmed']) break;
                    $hits=$input['hits']??null;
                    if (!is_int($hits) || $hits<0 || $hits>$room['code_length']) fail('Недопустимое число совпадений');
                    if ($hits!==countHits($h['guess'],$room['players'][$pid]['secret'])) fail('Число совпадений не соответствует вашему PIN');
                    $h['hits']=$hits; $h['confirmed']=true;
                    if ($hits===$room['code_length']) $room['players'][$pid]['eliminated']=true;
                    break;
                }
                unset($h); if (!$found) fail('Атака не найдена'); settleGame($room); break;
            case 'state':
                $before=$room; settleGame($room);
                $room['players'][$pid]['last_seen']=time();
                unset($room['players'][$pid]['left_at'],$room['empty_since']);
                $auth=signIn($room,$pid);
                if ($before!==$room) saveRooms($rooms);
                $spectator=$room['players'][$pid]['eliminated'] || $room['status']==='finished';
                $players=[];
                foreach ($room['players'] as $id=>$p) {
                    $players[$id]=['id'=>$id,'name'=>$p['name'],'ready'=>$p['ready'],'eliminated'=>$p['eliminated'],
                        'is_me'=>$id===$pid,'is_host'=>$id===$room['host_id'],'is_bot'=>$p['is_bot'],
                        'secret'=>$id===$pid?$p['secret']:''];
                }
                $history=array_values(array_filter($room['history'],fn($h)=>$spectator || $h['attacker_id']===$pid || $h['target_id']===$pid));
                $current=$room['status']==='playing'?($room['turn_order'][$room['current_turn_idx']]??null):null;
                reply(['status'=>'ok','room_code'=>$code,'room_status'=>$room['status'],'code_length'=>$room['code_length'],
                    'my_id'=>$pid,'host_id'=>$room['host_id'],'is_host'=>$pid===$room['host_id'],
                    'players'=>$players,'history'=>$history,'turn_order'=>$room['turn_order'],
                    'current_turn_player_id'=>$current,'is_my_turn'=>$current===$pid,'round'=>$room['round'],
                    'attacks_this_turn'=>$room['attacks_this_turn'],'spectator'=>$spectator,
                    'winner_id'=>$room['winner_id']??null,'resume_token'=>$auth['resume_token']]);
            default: fail('Неизвестное действие');
        }
        saveRooms($rooms); reply(['status'=>'ok']);
    } catch (Throwable $e) {
        error_log((string)$e);
        http_response_code(500);
        reply(['status'=>'error','msg'=>'Не удалось обработать запрос. Попробуйте ещё раз.']);
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no,viewport-fit=cover">
<title>CODEBREAK</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0a0f;--surf:#111118;--surf2:#18181f;
  --brd:rgba(255,255,255,.08);--brd2:rgba(255,255,255,.14);
  --A:#c8f135;--R:#ff5252;--G:#3dffa0;
  --tx:#eeeef5;--mu:#5a5a78;--mu2:#8888a8;
  --mono:'JetBrains Mono',monospace;--sans:'Inter',sans-serif;
}
html,body{height:100%}
body{font-family:var(--sans);background:var(--bg);color:var(--tx);overflow-x:hidden;-webkit-font-smoothing:antialiased;-webkit-text-size-adjust:100%;overscroll-behavior-y:contain}
@media(max-width:375px){.cf-inp{font-size:1.8rem}.cf-code{font-size:1.8rem}.my-secret-val{font-size:2rem;padding:8px 14px}.h-btn{width:30px;height:30px;font-size:.78rem}}
@media(max-width:430px){.g-center{gap:8px}.my-secret-val{font-size:2.2rem}}
/* Фикс прыжка — фокус не скроллит */
input,button{scroll-margin:0}input:focus{scroll-margin:0;outline:none}

.screen{display:none;min-height:100dvh;padding:0 16px calc(60px + env(safe-area-inset-bottom));max-width:460px;margin:0 auto}
.screen.active{display:flex;flex-direction:column}
.btn{border:none;border-radius:12px;font-family:var(--sans);font-weight:700;font-size:.9rem;padding:14px;cursor:pointer;transition:transform .12s;text-transform:uppercase;letter-spacing:.02em}
.btn:active{transform:scale(.97)}
.btn-p{background:var(--A);color:#080808}
.btn-s{background:var(--surf2);color:var(--tx);border:1.5px solid var(--brd)}
.btn-d{background:rgba(255,82,82,.1);color:var(--R);border:1.5px solid rgba(255,82,82,.3)}
.btn-full{width:100%}
.lbl{display:block;font-size:.65rem;font-weight:700;letter-spacing:.1em;color:var(--mu2);text-transform:uppercase;margin-bottom:7px}
.inp{width:100%;background:var(--surf2);border:1.5px solid var(--brd);border-radius:12px;color:var(--tx);font-family:var(--sans);font-size:1rem;font-weight:500;padding:13px 16px;outline:none;transition:border-color .2s}
.inp:focus{border-color:rgba(200,241,53,.5);box-shadow:0 0 0 3px rgba(200,241,53,.07)}
.inp::placeholder{color:var(--mu)}

/* WELCOME */
#screen-welcome{justify-content:center;align-items:center;position:relative;overflow:hidden;padding-left:0;padding-right:0}
.wb{position:absolute;inset:0;background:radial-gradient(ellipse 70% 50% at 50% -10%,rgba(200,241,53,.07),transparent 60%)}
.wg{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.02) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.02) 1px,transparent 1px);background-size:44px 44px;mask-image:radial-gradient(ellipse at center,black 20%,transparent 70%)}
.wi{position:relative;z-index:2;text-align:center;width:100%;padding:0 16px}
.w-tag{font-family:var(--mono);font-size:.6rem;color:var(--A);letter-spacing:.25em;margin-bottom:20px;opacity:0;animation:up .5s .1s forwards}
.w-title{font-size:clamp(2.4rem,11.5vw,4.2rem);font-weight:800;line-height:.95;letter-spacing:-.03em;opacity:0;animation:up .5s .2s forwards;white-space:nowrap}
.w-title em{color:var(--A);font-style:normal}
.w-sub{font-size:.75rem;color:var(--mu2);letter-spacing:.1em;margin-top:14px;margin-bottom:44px;opacity:0;animation:up .5s .3s forwards;text-transform:uppercase}
.w-f{margin-bottom:18px;opacity:0;animation:up .5s .4s forwards;text-align:left}
.btn-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;opacity:0;animation:up .5s .5s forwards}

/* JOIN */
#screen-join{justify-content:flex-start;padding-top:60px;position:relative}
.back{position:absolute;top:16px;left:16px;background:none;border:none;color:var(--mu2);font-family:var(--mono);font-size:.75rem;cursor:pointer;letter-spacing:.08em;padding:6px 0;display:flex;align-items:center;gap:4px}
.panel{background:var(--surf);border:1.5px solid var(--brd);border-radius:16px;padding:26px 22px;width:100%}
.panel-ttl{font-size:1.4rem;font-weight:800;margin-bottom:4px}
.panel-sub{font-size:.75rem;color:var(--mu2);margin-bottom:22px}
.code-in{width:100%;background:#000;border:1.5px solid var(--brd);border-radius:12px;color:var(--A);font-family:var(--mono);font-size:2rem;font-weight:700;padding:14px;text-align:center;letter-spacing:.4em;outline:none;text-transform:uppercase;margin-bottom:14px}
.code-in:focus{border-color:rgba(200,241,53,.5)}
.err{background:rgba(255,82,82,.1);border:1.5px solid rgba(255,82,82,.3);border-radius:10px;color:var(--R);font-size:.85rem;padding:10px 14px;margin-bottom:12px;display:none}
.err.on{display:block}

/* LOBBY */
#screen-lobby{padding-top:24px}
.p-list{display:flex;flex-direction:column;gap:8px;margin-bottom:18px}
.p-row{display:flex;align-items:center;gap:12px;background:var(--surf);border:1.5px solid var(--brd);border-radius:14px;padding:13px 15px}
.p-row.me{border-color:rgba(200,241,53,.25)}
.p-row.bot{border-color:rgba(61,255,160,.2)}
.p-av{width:36px;height:36px;border-radius:10px;background:var(--surf2);border:1.5px solid var(--brd);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:var(--mu2);flex-shrink:0}
.p-row.me .p-av{background:rgba(200,241,53,.1);color:var(--A);border-color:rgba(200,241,53,.3)}
.p-row.bot .p-av{background:rgba(61,255,160,.1);color:var(--G);border-color:rgba(61,255,160,.3)}
.p-name{font-weight:600;font-size:.95rem;flex:1}
.bdg{font-family:var(--mono);font-size:.5rem;font-weight:700;border-radius:5px;padding:2px 7px;text-transform:uppercase}
.bdg-h{color:var(--A);border:1px solid rgba(200,241,53,.35)}
.bdg-b{color:var(--G);border:1px solid rgba(61,255,160,.3)}
.len-row{display:flex;gap:8px;margin-bottom:14px}
.len-btn{flex:1;background:var(--surf2);border:1.5px solid var(--brd);border-radius:10px;color:var(--mu2);font-family:var(--mono);font-size:.85rem;font-weight:700;padding:9px 0;cursor:pointer;text-align:center}
.len-btn.on{background:rgba(200,241,53,.1);border-color:rgba(200,241,53,.4);color:var(--A)}
.sec-lbl{font-size:.6rem;font-weight:700;letter-spacing:.12em;color:var(--mu2);text-transform:uppercase;margin-bottom:8px}

/* SETUP */
#screen-setup{justify-content:center}
.su{width:100%;padding-top:40px}
.su-ttl{font-size:2rem;font-weight:800;margin-bottom:6px}
.su-desc{color:var(--mu2);font-size:.85rem;margin-bottom:26px;line-height:1.6}
.sec-in{width:100%;background:#000;border:2px solid var(--brd);border-radius:14px;color:var(--A);font-family:var(--mono);font-size:2.6rem;font-weight:700;padding:18px;text-align:center;letter-spacing:.4em;outline:none;margin-bottom:12px}
.sec-in:focus{border-color:rgba(200,241,53,.5)}
.rand-btn{display:flex;align-items:center;justify-content:center;gap:8px;background:var(--surf2);border:1.5px solid var(--brd);border-radius:12px;color:var(--mu2);font-size:.85rem;font-weight:600;padding:12px;cursor:pointer;margin-bottom:20px;width:100%}
.ready-b{text-align:center;padding:10px;background:rgba(61,255,160,.08);border:1.5px solid rgba(61,255,160,.2);border-radius:12px;color:var(--G);font-size:.8rem;font-weight:700;margin-bottom:14px}
.wp-row{display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--brd);font-size:.9rem}
.wp-row:last-child{border-bottom:none}
.wp-st{font-family:var(--mono);font-size:.6rem;font-weight:700}
.wp-st.rdy{color:var(--G)}
.wp-st.wait{color:var(--mu2)}

/* ═══════════════════ GAME ═══════════════════ */
#screen-game{padding-top:14px}

/* Хедер с кодом комнаты всегда видимым */
.g-hdr{display:flex;align-items:center;justify-content:space-between;padding-bottom:12px;margin-bottom:14px;border-bottom:1.5px solid var(--brd);flex-shrink:0}
.g-hdr-left{display:flex;flex-direction:column;gap:2px}
.g-title{font-size:1rem;font-weight:800;letter-spacing:.02em}
.g-round{font-family:var(--mono);font-size:.5rem;letter-spacing:.15em;color:var(--mu2);text-transform:uppercase}
.g-hdr-right{display:flex;align-items:center;gap:8px}
.room-chip{background:var(--surf2);border:1.5px solid var(--brd);border-radius:8px;padding:5px 10px;font-family:var(--mono);font-size:.65rem;font-weight:700;letter-spacing:.15em;color:var(--mu2)}
.room-chip span{color:var(--A)}
.close-btn{background:none;border:1.5px solid rgba(255,82,82,.3);border-radius:8px;color:var(--R);font-family:var(--mono);font-size:.6rem;padding:5px 10px;cursor:pointer}

/* Центральная зона игры */
.g-center{display:flex;flex-direction:column;align-items:center;gap:10px;margin-bottom:16px}

/* Мой секретный код */
.my-secret{text-align:center}
.my-secret-lbl{font-family:var(--mono);font-size:.5rem;letter-spacing:.2em;color:var(--mu2);text-transform:uppercase;margin-bottom:6px}
.my-secret-val{font-family:var(--mono);font-size:2.5rem;font-weight:700;color:var(--A);letter-spacing:.4em;background:rgba(200,241,53,.06);border:1.5px solid rgba(200,241,53,.2);border-radius:12px;padding:11px 22px;display:inline-block}
.my-secret-row{display:flex;align-items:center;justify-content:center;gap:9px}
.secret-toggle{width:42px;height:42px;display:grid;place-items:center;border:1.5px solid var(--brd2);border-radius:11px;background:var(--surf);color:var(--tx);cursor:pointer;transition:all .15s}
.secret-toggle:hover,.secret-toggle[aria-pressed="false"]{border-color:var(--A);color:var(--A)}
.secret-toggle svg{width:20px;height:20px}

/* Центральное поле — 2 функции: входящая атака + моя атака */
.center-field{width:100%;max-width:320px;position:relative}
.cf-label{font-family:var(--mono);font-size:.72rem;letter-spacing:.06em;color:var(--mu2);text-align:center;margin-bottom:6px;min-height:1.2em;display:flex;align-items:center;justify-content:center;gap:5px;flex-wrap:wrap}
.cf-label.is-attacking{width:100%;max-width:460px;min-height:0;display:block;padding:18px 20px;margin:4px 0 8px;border:1.5px solid rgba(200,241,53,.28);border-radius:18px;background:linear-gradient(135deg,rgba(200,241,53,.12),rgba(17,17,24,.96) 64%);box-shadow:0 14px 42px rgba(0,0,0,.25)}
.turn-kicker{font:700 .62rem var(--mono);letter-spacing:.18em;text-transform:uppercase;color:var(--A);margin-bottom:13px}
.turn-match{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px}
.turn-person{min-width:0;padding:13px 10px;border:1px solid var(--brd2);border-radius:13px;background:rgba(0,0,0,.3);font-size:clamp(1rem,5vw,1.45rem);font-weight:800;overflow-wrap:anywhere}
.turn-person.target{color:var(--A);border-color:rgba(200,241,53,.32)}
.turn-arrow{font-size:1.5rem;color:var(--A)}
.turn-hint{margin-top:12px;color:var(--mu2);font-size:.78rem}
.target-sel.attack-targets{width:100%;max-width:460px;display:grid;grid-template-columns:repeat(auto-fit,minmax(132px,1fr));gap:10px}
.t-btn.attack-card{min-height:68px;padding:13px 12px;border-radius:14px;font-size:.95rem;background:linear-gradient(145deg,var(--surf2),var(--surf));box-shadow:0 7px 20px rgba(0,0,0,.18)}
.t-btn.attack-card.active{background:linear-gradient(145deg,rgba(200,241,53,.17),rgba(200,241,53,.06));box-shadow:0 0 0 1px rgba(200,241,53,.12),0 9px 26px rgba(200,241,53,.08)}
.center-field.attack-input{width:100%;max-width:460px}
.cf-box.my-turn.attack-box{min-height:104px;border-radius:18px;background:radial-gradient(ellipse at center,rgba(200,241,53,.07),#000 76%)}
.cf-actions.attack-actions{max-width:460px}

/* Поле ожидания / ввода */
.cf-box{background:#000;border:2px solid var(--brd);border-radius:14px;padding:14px 16px;text-align:center;transition:border-color .25s,box-shadow .25s;min-height:68px;display:flex;align-items:center;justify-content:center;position:relative}
.cf-box.waiting{border-color:var(--brd)}
.cf-box.incoming{border-color:rgba(255,82,82,.5);box-shadow:0 0 20px rgba(255,82,82,.1)}
.cf-box.my-turn{border-color:rgba(200,241,53,.5);box-shadow:0 0 20px rgba(200,241,53,.08)}
.cf-dots{font-family:var(--mono);font-size:1.4rem;color:var(--mu2);letter-spacing:.4em;animation:pulse 1.2s infinite}
.cf-code{font-family:var(--mono);font-size:2.3rem;font-weight:700;color:var(--R);letter-spacing:.35em}
.cf-inp{background:none;border:none;outline:none;font-family:var(--mono);font-size:2.3rem;font-weight:700;color:var(--A);letter-spacing:.35em;text-align:center;width:100%;caret-color:var(--A)}
.cf-inp::placeholder{color:var(--mu);font-size:1.4rem;letter-spacing:.15em}
.cf-who{position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:var(--R);color:#fff;font-size:.55rem;font-weight:700;font-family:var(--mono);padding:2px 10px;border-radius:20px;white-space:nowrap;letter-spacing:.08em}
.cf-who.mine{background:var(--A);color:#080808}

/* Кнопки под полем */
.cf-actions{width:100%;max-width:320px;display:flex;flex-direction:column;gap:8px}

/* Выбор совпадений */
.hits-panel{background:var(--surf);border:1.5px solid var(--brd);border-radius:12px;padding:12px 14px}
.hits-lbl{font-family:var(--mono);font-size:.5rem;letter-spacing:.15em;color:var(--mu2);text-transform:uppercase;margin-bottom:8px;text-align:center}
.hits-btns{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-bottom:8px}
.h-btn{background:var(--surf2);border:1.5px solid var(--brd);border-radius:8px;color:var(--tx);font-family:var(--mono);font-size:1rem;font-weight:700;width:38px;height:38px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .12s}
.h-btn.sel{background:rgba(200,241,53,.15);border-color:rgba(200,241,53,.5);color:var(--A)}
.send-btn{width:100%;background:var(--surf2);border:1.5px solid var(--brd);border-radius:10px;color:var(--mu2);font-family:var(--sans);font-weight:700;font-size:.8rem;padding:10px;cursor:pointer;transition:all .15s}
.send-btn.ok{background:var(--G);border-color:var(--G);color:#080808}
.send-btn:disabled{opacity:.35;cursor:default}

/* Таргет-селектор (кого атаковать) */
.target-sel{width:100%;max-width:320px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
.t-btn{flex:1;min-width:80px;background:var(--surf);border:1.5px solid var(--brd);border-radius:10px;color:var(--mu2);font-size:.8rem;font-weight:700;padding:10px 8px;cursor:pointer;text-align:center;transition:all .15s}
.t-btn.active{border-color:rgba(200,241,53,.5);color:var(--A);background:rgba(200,241,53,.07)}
.t-btn.done{border-color:rgba(61,255,160,.3);color:var(--G);opacity:.7}
.t-btn.t-waiting{border-color:rgba(255,82,82,.4);color:var(--R);background:rgba(255,82,82,.07)}

/* Карточки игроков — компактные */
.players-area{display:flex;flex-direction:column;gap:8px;margin-top:8px}
/* Карточки всегда одинакового размера — сетка не растёт */
.p-card .atk-grid{min-height:0}

/* Карточка игрока с историей атак */
.p-card{background:var(--surf);border:1.5px solid var(--brd);border-radius:14px;overflow:hidden}
.p-card.turn{border-color:rgba(200,241,53,.3)}
.p-card.elim{opacity:.35}
.p-card-hd{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1.5px solid var(--brd)}
.p-card-av{width:30px;height:30px;border-radius:8px;background:var(--surf2);border:1.5px solid var(--brd);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.75rem;color:var(--mu2);flex-shrink:0}
.p-card-nm{font-weight:700;font-size:1rem;flex:1}
.turn-dot{width:7px;height:7px;border-radius:50%;background:var(--A);box-shadow:0 0 8px rgba(200,241,53,.6)}

/* Сетка атак: 3 столбца по 7 строк, заполняется сверху-вниз */
.atk-grid{padding:5px 8px;display:grid;grid-template-columns:repeat(4,1fr);grid-auto-flow:column;grid-template-rows:repeat(7,auto);gap:2px 4px}
.atk-row{display:flex;align-items:center;gap:3px;padding:3px 6px;border-radius:5px;background:var(--surf2);font-family:var(--mono);font-size:.72rem;white-space:nowrap;overflow:hidden}
.atk-row-g{font-weight:700;color:var(--tx);letter-spacing:.02em;flex-shrink:0}
.atk-row-sep{color:var(--mu2);font-size:.65rem;flex-shrink:0}
.atk-row-h{font-weight:700;color:var(--A);flex-shrink:0}
.atk-row-wait{background:rgba(200,241,53,.05);border:1px solid rgba(200,241,53,.15)}
.atk-row-g.atk-blink{animation:blink 1s ease-in-out infinite;color:var(--A)}
.atk-row-p{color:var(--mu2);font-size:.68rem;animation:pulse 1s infinite;flex-shrink:0}
.atk-row-hidden{color:var(--mu2);letter-spacing:.08em;flex-shrink:0}
.atk-empty{padding:10px 14px;font-family:var(--mono);font-size:.72rem;color:var(--mu2)}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.25}}

/* Статус атаки на других */
.inc-list{padding:6px 14px 10px}
.inc-row{display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--brd);font-size:.75rem}
.inc-row:last-child{border-bottom:none}
.inc-nm{color:var(--mu2);font-weight:600}
.hit-p{background:rgba(200,241,53,.1);border:1px solid rgba(200,241,53,.25);color:var(--A);border-radius:5px;padding:1px 7px;font-family:var(--mono);font-size:.6rem;font-weight:700}
.dots{color:var(--mu2);font-family:var(--mono);font-size:.7rem;animation:pulse 1.2s infinite}

@keyframes up{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-5px)}75%{transform:translateX(5px)}}
.shake{animation:shake .35s ease}
/* Выбывший игрок */
.my-secret-val.eliminated{text-decoration:line-through;opacity:.4;border-color:rgba(255,82,82,.3);color:var(--R);background:rgba(255,82,82,.05)}
.cf-box.eliminated{opacity:.35;pointer-events:none;border-color:var(--brd)}
.elim-banner{text-align:center;padding:10px 16px;background:rgba(255,82,82,.08);border:1.5px solid rgba(255,82,82,.25);border-radius:12px;color:var(--R);font-size:.8rem;font-weight:700;font-family:var(--mono);letter-spacing:.05em}
/* Заметки по цифрам */
.digit-notes{display:flex;gap:5px;justify-content:center;flex-wrap:nowrap;margin-top:8px}
.dn-btn{width:33px;height:33px;border-radius:7px;border:1.5px solid var(--brd);background:var(--surf2);color:var(--tx);font-family:var(--mono);font-size:.95rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;position:relative;flex-shrink:0}
.dn-btn.crossed{color:var(--mu);border-color:transparent;background:transparent;text-decoration:line-through;opacity:.4}
/* Кик игрока */
.kick-btn{background:none;border:none;color:var(--mu);font-size:.9rem;cursor:pointer;padding:2px 6px;border-radius:5px;line-height:1;opacity:.5;transition:opacity .15s}
.kick-btn:hover{opacity:1;color:var(--R)}
/* Кнопка назад на join */
.back{position:absolute;top:20px;left:16px;background:none;border:none;color:var(--mu2);font-family:var(--mono);font-size:.7rem;cursor:pointer;letter-spacing:.1em;padding:8px 0}
</style>
</head>
<body>

<!-- WELCOME -->
<div id="screen-welcome" class="screen active">
  <div class="wb"></div><div class="wg"></div>
  <div class="wi">
    <div class="w-tag">Тактическая игра · угадай код</div>
    <div class="w-title">CODE<em>BREAK</em></div>
    <div class="w-sub">Взломай коды противников первым</div>
    <div class="w-f">
      <label class="lbl">Ваш никнейм</label>
      <input type="text" id="wname" class="inp" placeholder="Введите имя" maxlength="16" autocomplete="off">
    </div>
    <div class="btn-row">
      <button class="btn btn-p" onclick="goCreate()">⚡ Создать</button>
      <button class="btn btn-s" onclick="goJoin()">🔗 Войти</button>
    </div>
  </div>
</div>

<!-- JOIN -->
<div id="screen-join" class="screen">
  <button class="back" onclick="showScreen('welcome')">← Назад</button>
  <div style="width:100%;margin-top:60px">
    <div class="panel">
      <div class="panel-ttl">Подключение</div>
      <div class="panel-sub">Введите код комнаты</div>
      <input type="text" id="jcode" class="code-in" maxlength="3" placeholder="000" inputmode="numeric" pattern="[0-9]{3}" autocomplete="off" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
      <div id="jerr" class="err"></div>
      <label class="lbl" for="jpin">Ваш прежний PIN — только для возврата с другого браузера</label>
      <input type="password" id="jpin" class="inp" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="Оставьте пустым при первом входе">
      <div style="margin-bottom:14px">
        <label class="lbl">Ваш никнейм</label>
        <input type="text" id="jname" class="inp" placeholder="Введите имя" maxlength="16" autocomplete="off">
      </div>
      <button class="btn btn-p btn-full" onclick="doJoin()">Войти</button>
    </div>
  </div>
</div>

<!-- LOBBY -->
<div id="screen-lobby" class="screen" style="padding-top:24px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px">
    <div>
      <div style="font-family:var(--mono);font-size:.55rem;letter-spacing:.15em;color:var(--mu2);margin-bottom:4px;text-transform:uppercase">Код комнаты</div>
      <div style="font-family:var(--mono);font-size:2rem;font-weight:700;color:var(--A);letter-spacing:.2em" id="lcode">—</div>
    </div>
    <div id="lcnt"></div>
  </div>
  <div class="sec-lbl">Игроки (макс. 4)</div>
  <div class="p-list" id="lplayers"></div>
  <div id="lhost"></div>
  <div id="lguest" style="display:none;text-align:center;padding:20px;color:var(--mu2);font-size:.85rem">Ожидаем хоста…</div>
</div>

<!-- SETUP -->
<div id="screen-setup" class="screen">
  <div class="su">
    <div class="su-ttl">Секретный код</div>
    <div class="su-desc" id="sdesc">Придумайте 4-значный код.</div>
    <input type="text" id="sinput" class="sec-in" maxlength="4" placeholder="••••" inputmode="numeric" autocomplete="off">
    <button class="rand-btn" onclick="doRand()">🎲 Случайный код</button>
    <div id="sready" class="ready-b" style="display:none">✓ Код сохранён — ждём остальных</div>
    <div id="sbtn"><button class="btn btn-p btn-full" onclick="doSetCode()">Подтвердить код</button></div>
    <div style="height:1px;background:var(--brd);margin:18px 0"></div>
    <div id="swaiting" style="display:none;background:var(--surf);border:1.5px solid var(--brd);border-radius:14px;padding:14px 18px">
      <div class="sec-lbl">Готовность</div>
      <div id="splayers"></div>
    </div>
    <div style="margin-top:14px" id="sclose"></div>
  </div>
</div>

<!-- GAME -->
<div id="screen-game" class="screen">
  <!-- Хедер: код комнаты всегда виден (п.4) -->
  <div class="g-hdr">
    <div class="g-hdr-left">
      <div class="g-title">CODEBREAK</div>
      <div class="g-round" id="ground">Раунд 1</div>
    </div>
    <div class="g-hdr-right">
      <div class="room-chip" id="g-room-chip">Комната: <span>—</span></div>
      <div id="g-close-wrap"></div>
    </div>
  </div>

  <!-- Центральная зона: мой код + одно поле -->
  <div class="g-center">
    <div class="my-secret" id="g-my-secret"></div>
    <div class="digit-notes" id="g-digit-notes"></div>
    <div class="cf-label" id="g-cf-label"></div>
    <div class="center-field" id="g-center-field">
      <div class="cf-box waiting" id="g-cf-box">
        <span class="cf-dots" id="g-cf-dots">···</span>
      </div>
    </div>
    <div class="cf-actions" id="g-cf-actions"></div>
    <!-- Выбор цели (только во время моего хода) -->
    <div class="target-sel" id="g-targets" style="display:none"></div>
  </div>

  <!-- Карточки игроков -->
  <div id="g-order" class="panel-sub"></div><div class="players-area" id="g-players"></div>
</div>

<script>
var S={
  screen:'welcome',polling:null,myId:null,roomCode:null,lastData:null,
  csel:{},      // выбранное кол-во совпадений для pending
  curTarget:null, // текущая цель атаки
  iv:{},        // значения инпутов
  noScroll:false, // флаг — не скроллить при фокусе
  crossed:{},   // зачёркнутые цифры-заметки {0:true, 3:true, ...}
  secretVisible:true
};

function showScreen(n){
  document.querySelectorAll('.screen').forEach(function(s){s.classList.remove('active');});
  document.getElementById('screen-'+n).classList.add('active');
  S.screen=n;
}
function goCreate(){doCreate(document.getElementById('wname').value.trim()||'Игрок');}
async function doCreate(name){
  var r=await api('create_room',{name:name,code_length:4});
  if(r.status==='ok'){remember(r);S.myId=r.player_id;S.roomCode=r.code;showScreen('lobby');startPoll();}
}
function goJoin(){
  var n=document.getElementById('wname').value.trim();
  if(n) document.getElementById('jname').value=n;
  showScreen('join');
}
async function doJoin(){
  var code=document.getElementById('jcode').value.trim();
  var name=document.getElementById('jname').value.trim()||'Игрок';
  var err=document.getElementById('jerr');
  err.classList.remove('on');
  if(!/^[0-9]{3}$/.test(code)){err.textContent='Введите 3-значный код комнаты';err.classList.add('on');return;}
  var login=savedLogin(code);
  var r=await api('join_room',{code:code,name:name,secret:document.getElementById('jpin').value.trim(),resume_token:login?login.token:''});
  if(r.status==='ok'){remember(r);
    S.myId=r.player_id;S.roomCode=r.code;
    showScreen(['playing','finished'].includes(r.room_status)?'game':r.room_status==='setup'?'setup':'lobby');
    startPoll();
  } else {err.textContent=r.msg||'Ошибка';err.classList.add('on');}
}
function startPoll(){
  if(S.polling) clearInterval(S.polling);
  fetchState();
  S.polling=setInterval(fetchState,2000);
}
function esc(value){return String(value).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function savedLogin(code){try{return JSON.parse(localStorage.getItem('codebreak:'+code)||'null');}catch(e){return null;}}
function remember(data){
  var code=data.code||data.room_code;
  if(code&&data.resume_token){try{localStorage.setItem('codebreak:'+code,JSON.stringify({token:data.resume_token}));localStorage.setItem('codebreak:last',code);}catch(e){}}
}
function forgetCurrent(){try{localStorage.removeItem('codebreak:last');}catch(e){}}
async function fetchState(){
  if(S.fetching)return;
  S.fetching=true;
  try {
  var data=await api('state');
  if(!data||data.status==='error')return;
  if(data.status==='no_room'){
    if(['welcome','join'].indexOf(S.screen)<0){
      if(S.polling){clearInterval(S.polling);S.polling=null;}
      S.myId=null;S.roomCode=null;S.lastData=null;showScreen('welcome');
    }
    return;
  }
  remember(data);
  Object.values(data.players).forEach(function(p){p.name=esc(p.name);});
  if(S.roomCode!==data.room_code){S.curTarget=null;S.iv={};S.csel={};S.crossed={};document.getElementById('g-players').innerHTML='';}
  S.roomCode=data.room_code;
  S.lastData=data;
  if(data.room_status==='lobby'&&S.screen!=='lobby') showScreen('lobby');
  else if(data.room_status==='setup'&&S.screen!=='setup') showScreen('setup');
  else if(['playing','finished'].includes(data.room_status)&&S.screen!=='game') showScreen('game');
  if(S.screen==='lobby') rLobby(data);
  else if(S.screen==='setup') rSetup(data);
  else if(S.screen==='game') rGame(data);
  } finally {S.fetching=false;}
}

/* ── LOBBY ── */
function rLobby(data){
  document.getElementById('lcode').textContent=data.room_code;
  var pl=Object.values(data.players);
  document.getElementById('lcnt').innerHTML='<div style="font-size:1.6rem;font-weight:800;font-family:var(--mono);text-align:right">'+pl.length+'/4</div><div style="font-size:.55rem;color:var(--mu2)">игроков</div>';
  document.getElementById('lplayers').innerHTML=pl.map(function(p){
    return '<div class="p-row'+(p.is_me?' me':'')+(p.is_bot?' bot':'')+'">'
      +'<div class="p-av">'+p.name[0].toUpperCase()+'</div>'
      +'<div class="p-name">'+p.name+(p.is_me?' <span style="font-size:.7rem;color:var(--mu2)">(вы)</span>':'')+'</div>'
      +(p.is_host?'<span class="bdg bdg-h">HOST</span>':'')
      +(p.is_bot?'<span class="bdg bdg-b">BOT</span>':'')
      +(data.is_host&&!p.is_me&&!p.is_host?'<button class="kick-btn" onclick="doKick(\''+p.id+'\')">✕</button>':'')
      +'</div>';
  }).join('');
  var bc=pl.filter(function(p){return p.is_bot;}).length;
  var cl=data.code_length||4;
  if(data.is_host){
    document.getElementById('lguest').style.display='none';
    document.getElementById('lhost').innerHTML=
      '<div class="sec-lbl" style="margin-bottom:8px">Длина кода</div>'
      +'<div class="len-row">'+[4,5,6].map(function(n){return '<div class="len-btn'+(cl===n?' on':'')+'" onclick="setLen('+n+')">'+n+' цифр</div>';}).join('')+'</div>'
      +'<button class="btn btn-p btn-full" style="margin-bottom:10px" onclick="doStart()"'+(pl.length<2?' disabled':'')+'>Начать игру ('+pl.length+'/4)</button>'
      +'<div style="display:flex;gap:10px;margin-bottom:10px">'
      +'<button class="btn btn-s" style="flex:1;font-size:.8rem" onclick="doAddBot()"'+(bc>=2||pl.length>=4?' disabled':'')+'>🤖 + Бот</button>'
      +(bc>0?'<button class="btn btn-s" style="flex:1;font-size:.8rem;color:var(--R)" onclick="doRemBot()">🤖 − Бот ('+bc+')</button>':'')
      +'</div>'
      +'<button class="btn btn-d btn-full" onclick="doClose()">✕ Закрыть комнату</button>';
  } else {
    document.getElementById('lhost').innerHTML='<button class="btn btn-s btn-full" onclick="doLeave()" style="color:var(--mu2)">← Выйти</button>';
    document.getElementById('lguest').style.display='block';
  }
}
async function setLen(n){await api('set_code_length',{len:n});fetchState();}
async function doStart(){await api('start_game',{});fetchState();}
async function doAddBot(){await api('add_bot',{});fetchState();}
async function doRemBot(){await api('remove_bot',{});fetchState();}
async function doKick(pid){
  if(!confirm('Удалить игрока из комнаты?')) return;
  await api('kick_player',{player_id:pid});
  fetchState();
}
async function doClose(){
  if(!confirm('Закрыть комнату?'))return;
  var result=await api('close_room',{});if(result.status!=='ok')return;forgetCurrent();
  if(S.polling){clearInterval(S.polling);S.polling=null;}
  S.myId=null;S.roomCode=null;S.lastData=null;showScreen('welcome');
}
async function doLeave(){
  var result=await api('leave_room',{});if(result.status!=='ok')return;forgetCurrent();
  if(S.polling){clearInterval(S.polling);S.polling=null;}
  S.myId=null;S.roomCode=null;showScreen('welcome');
}

/* ── SETUP ── */
function rSetup(data){
  var cl=data.code_length||4;
  var inp=document.getElementById('sinput');
  inp.maxLength=cl;inp.placeholder='•'.repeat(cl);
  document.getElementById('sdesc').textContent='Придумайте '+cl+'-значный код. Соперники угадывают его.';
  var me=Object.values(data.players).find(function(p){return p.is_me;});
  document.getElementById('sclose').innerHTML=data.is_host
    ?'<button class="btn btn-d btn-full" onclick="doClose()">✕ Закрыть комнату</button>':'<button class="btn btn-s btn-full" onclick="doLeave()">← Выйти</button>';
  if(me&&me.secret){
    document.getElementById('sready').style.display='block';
    document.getElementById('sbtn').style.display='none';
    document.getElementById('swaiting').style.display='block';
  } else {
    document.getElementById('sready').style.display='none';
    document.getElementById('sbtn').style.display='block';
    document.getElementById('swaiting').style.display='block';
  }
  document.getElementById('splayers').innerHTML=Object.values(data.players).map(function(p){
    return '<div class="wp-row"><span>'+p.name+(p.is_me?' (вы)':'')+(p.is_bot?' 🤖':'')+'</span><span class="wp-st '+(p.ready?'rdy':'wait')+'">'+(p.ready?'✓ Готов':'Думает')+'</span>'
      +(data.is_host&&!p.is_me?'<button class="kick-btn" onclick="doKick(\''+p.id+'\')">✕</button>':'')+'</div>';
  }).join('');
}
function doRand(){document.getElementById('sinput').value=genCode(S.lastData&&S.lastData.code_length||4);}
function genCode(len){
  function easy(c){var d=c.split('').map(Number);if(new Set(d).size<=1)return true;if(new Set(d).size<=2&&len<=4)return true;for(var i=0;i<d.length-2;i++){if(d[i+1]-d[i]===1&&d[i+2]-d[i+1]===1)return true;if(d[i]-d[i+1]===1&&d[i+1]-d[i+2]===1)return true;}return false;}
  for(var i=0;i<200;i++){var c='';for(var j=0;j<len;j++)c+=Math.floor(Math.random()*10);if(!easy(c))return c;}
  return '3749'.padEnd(len,'5');
}
async function doSetCode(){
  var cl=S.lastData&&S.lastData.code_length||4;
  var val=document.getElementById('sinput').value.trim();
  if(val.length!==cl||!/^\d+$/.test(val)){alert('Введите ровно '+cl+' цифр');return;}
  await api('set_code',{secret:val});fetchState();
}

/* Заметки по цифрам — зачёркивание */
function renderDigitNotes(){
  var el=document.getElementById('g-digit-notes');
  if(!el) return;
  var html='';
  for(var i=0;i<=9;i++){
    html+='<button class="dn-btn'+(S.crossed[i]?' crossed':'')+'" onclick="toggleDigit('+i+')">'+i+'</button>';
  }
  el.innerHTML=html;
}
function toggleDigit(n){
  S.crossed[n]=!S.crossed[n];
  renderDigitNotes();
}
function toggleSecretVisibility(){
  S.secretVisible=!S.secretVisible;
  if(S.lastData) rGame(S.lastData);
}

/* ═══════════════════════════════════
   GAME RENDERING — новая архитектура
════════════════════════════════════*/
function rGame(data){
  var cl=data.code_length||4;
  var allP=Object.values(data.players);
  var me=allP.find(function(p){return p.is_me;});
  document.getElementById('g-cf-label').className='cf-label';
  document.getElementById('g-targets').className='target-sel';
  document.getElementById('g-center-field').className='center-field';
  document.getElementById('g-cf-actions').className='cf-actions';

  // Хедер
  document.getElementById('ground').textContent='Раунд '+data.round;
  document.getElementById('g-room-chip').innerHTML='Комната: <span>'+data.room_code+'</span>';
  document.getElementById('g-close-wrap').innerHTML='<button class="close-btn" onclick="doLeave()">← Выйти</button>';

  // Мой секрет
  var mySecret=me&&me.secret||'';
  var amElim=me&&me.eliminated;
  var finished=data.room_status==='finished';
  var secretEl=document.getElementById('g-my-secret');
  if(mySecret){
    var eyeIcon=S.secretVisible
      ?'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>'
      :'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15.8 15.8 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.1 0 2.1-.2 3-.6"/></svg>';
    secretEl.innerHTML='<div class="my-secret-lbl">Мой секрет</div>'
      +'<div class="my-secret-row"><div class="my-secret-val'+(amElim?' eliminated':'')+'" >'+(S.secretVisible?mySecret:'•'.repeat(mySecret.length))+'</div>'
      +'<button class="secret-toggle" type="button" onclick="toggleSecretVisibility()" aria-label="'+(S.secretVisible?'Скрыть PIN':'Показать PIN')+'" title="'+(S.secretVisible?'Скрыть PIN':'Показать PIN')+'" aria-pressed="'+S.secretVisible+'">'+eyeIcon+'</button></div>';
  } else { secretEl.innerHTML=''; }
  // Заметки цифр — инициализируем если ещё не отрисованы
  var dn=document.getElementById('g-digit-notes');
  if(dn&&!dn.hasChildNodes()) renderDigitNotes();

  // Определяем состояние центрального поля
  var onMe=data.history.filter(function(h){return h.target_id===data.my_id&&!h.confirmed;});
  var pendingAtk=onMe.length?onMe[0]:null;

  if(amElim||finished){
    // Выбыл — показываем баннер, поле неактивно
    document.getElementById('g-cf-label').innerHTML='';
    var box=document.getElementById('g-cf-box');
    box.className='cf-box eliminated';
    box.innerHTML='<span style="font-family:var(--mono);font-size:.8rem;color:var(--R);letter-spacing:.05em">Вы выбыли</span>';
    if(finished){var winner=allP.find(function(p){return p.id===data.winner_id;});box.innerHTML='<span style="font-size:1rem">'+(winner?'Победитель: '+winner.name:'Игра завершена')+'</span>';box.className='cf-box waiting';}
    else {
      var latest=data.history.length?data.history[data.history.length-1]:null;
      var current=data.players[data.current_turn_player_id];
      document.getElementById('g-cf-label').innerHTML='Наблюдение · '+(current?'Ход: '+current.name:'Ожидание');
      box.className='cf-box waiting';
      box.innerHTML=latest?'<div style="font-size:.85rem;text-align:center">'+attackLabel(data,latest)+'<br><strong style="font-size:1.6rem">'+latest.guess+'</strong> — '+(latest.confirmed?latest.hits+' совп.':'ожидает ответа')+'</div>':'<span style="font-size:.9rem">Ожидаем атаку</span>';
    }
    document.getElementById('g-cf-actions').innerHTML='';
    document.getElementById('g-targets').style.display='none';
  } else if(pendingAtk){
    // Режим: нужно ответить на входящую атаку
    rCenterIncoming(data,pendingAtk,cl,allP);
  } else if(data.is_my_turn){
    // Режим: моя атака
    rCenterMyTurn(data,cl,allP);
  } else {
    // Режим: ожидание
    rCenterWaiting(data,allP);
  }

  // Карточки игроков
  rPlayers(data,allP,cl);
  document.getElementById('g-order').innerHTML='Очередь: '+data.turn_order.map(function(id){var p=data.players[id];return p?'<span style="'+(p.eliminated?'text-decoration:line-through;opacity:.5':'')+'">'+(id===data.current_turn_player_id?'▶ ':'')+p.name+'</span>':'';}).filter(Boolean).join(' → ');

}

/* Центр: входящая атака */
function rCenterIncoming(data,h,cl,allP){
  var att=allP.find(function(p){return p.id===h.attacker_id;});
  var me=allP.find(function(p){return p.is_me;});
  var an=(att?att.name:'?')+(att&&att.is_bot?' 🤖':'');
  var mn=me?me.name:'Я';

  // Над полем: Кто → Меня
  document.getElementById('g-cf-label').innerHTML=
    '<span style="color:var(--R);font-weight:700">'+an+'</span>'
    +' <span style="color:var(--mu2)">→</span> '
    +'<span style="color:var(--tx)">'+mn+'</span>';

  var box=document.getElementById('g-cf-box');
  box.className='cf-box incoming';
  box.innerHTML='<span class="cf-code">'+h.guess+'</span>';

  document.getElementById('g-targets').style.display='none';

  var sel=S.csel[h.id]!==undefined?S.csel[h.id]:null;
  var ok=sel!==null;
  var btns='';
  for(var n=0;n<=cl;n++){
    btns+='<button class="h-btn'+(sel===n?' sel':'')+'" onclick="pickHits(\''+h.id+'\','+n+',this)">'+n+'</button>';
  }
  var sendOnclick=ok?'sendConfirm(\''+h.id+'\')':'';
  document.getElementById('g-cf-actions').innerHTML=
    '<div class="hits-panel">'
    +'<div class="hits-lbl">Совпадений по позиции:</div>'
    +'<div class="hits-btns" id="hits-btns-wrap">'+btns+'</div>'
    +'<button class="send-btn'+(ok?' ok':'')+'" id="send-btn-main"'+(ok?'':' disabled')
    +(ok?' onclick="'+sendOnclick+'"':'')+'>'
    +(ok?'✓ Отправить':'Выберите число')
    +'</button>'
    +'</div>';
}

/* Центр: мой ход */
function rCenterMyTurn(data,cl,allP){
  var opps=allP.filter(function(p){return !p.is_me&&!p.eliminated;});
  var done=data.attacks_this_turn&&data.attacks_this_turn[data.my_id]||{};
  var me=allP.find(function(p){return p.is_me;});
  var mn=me?me.name:'Я';

  // Определяем кто "ждал" — у кого есть неподтверждённые атаки на меня
  // Сортируем: сначала те кто ждал (отправлял атаки пока не было моего хода)
  var waiting=opps.filter(function(o){
    return !done[o.id]&&data.history.some(function(h){
      return h.attacker_id===o.id&&h.target_id===data.my_id&&!h.confirmed;
    });
  });
  var others=opps.filter(function(o){
    return !done[o.id]&&!waiting.find(function(w){return w.id===o.id;});
  });
  var orderedOpps=waiting.concat(others);

  // Выбираем цель: первая неатакованная из упорядоченного списка
  if(!S.curTarget||done[S.curTarget]||!opps.some(function(p){return p.id===S.curTarget;})){
    S.curTarget=orderedOpps.length?orderedOpps[0].id:null;
  }
  var curOpp=S.curTarget?allP.find(function(p){return p.id===S.curTarget;}):null;

  // Кнопки выбора цели — сначала ждавшие
  var tBtns=opps.map(function(o){
    var d=!!done[o.id];
    var isWaiting=waiting.find(function(w){return w.id===o.id;});
    return '<button class="t-btn attack-card'+(o.id===S.curTarget?' active':'')+(d?' done':'')+(isWaiting?' t-waiting':'')
      +'" onclick="pickTarget(\''+o.id+'\')">'
      +(d?'✓ ':isWaiting?'⏳ ':'')+o.name+(o.is_bot?' 🤖':'')
      +'</button>';
  }).join('');

  // Над полем: Я → Цель
  if(curOpp){
    document.getElementById('g-cf-label').className='cf-label is-attacking';
    document.getElementById('g-cf-label').innerHTML='<div class="turn-kicker">⚡ ВАШ ХОД · ВЫБЕРИТЕ ЦЕЛЬ</div>'
      +'<div class="turn-match"><div class="turn-person">'+mn+'</div><div class="turn-arrow">→</div>'
      +'<div class="turn-person target">'+curOpp.name+(curOpp.is_bot?' 🤖':'')+'</div></div>'
      +'<div class="turn-hint">Ваш ход: '+mn+' атакует '+curOpp.name+'</div>';
  } else {
    document.getElementById('g-cf-label').textContent='Выберите цель';
  }

  document.getElementById('g-targets').style.display='flex';
  document.getElementById('g-targets').className='target-sel attack-targets';
  document.getElementById('g-targets').innerHTML=tBtns;

  var box=document.getElementById('g-cf-box');
  if(!curOpp||done[curOpp.id]){
    box.className='cf-box waiting';
    box.innerHTML='<span class="cf-dots">···</span>';
    document.getElementById('g-cf-actions').innerHTML='';
    return;
  }

  box.className='cf-box my-turn attack-box';
  document.getElementById('g-center-field').className='center-field attack-input';
  document.getElementById('g-cf-actions').className='cf-actions attack-actions';

  var existingInp=document.getElementById('cf-main-inp');
  if(existingInp&&existingInp.dataset.target) S.iv[existingInp.dataset.target]=existingInp.value;
  var val=(S.iv[curOpp.id]||'').replace(/\D/g,'').slice(0,cl);
  var hadFocus=existingInp&&existingInp.dataset.target===curOpp.id&&document.activeElement===existingInp;

  if(hadFocus){
    var actEl=document.getElementById('g-cf-actions');
    if(!actEl.innerHTML) actEl.innerHTML='<button class="btn btn-p btn-full" onclick="doAtk(\''+curOpp.id+'\')">⚡ Атака</button>';
  } else {
    box.innerHTML='<input data-target="'+curOpp.id+'" id="cf-main-inp" class="cf-inp" type="text" inputmode="numeric" autocomplete="off"'
      +' maxlength="'+cl+'" placeholder="'+'·'.repeat(cl)+'"'
      +' value="'+val+'"'
      +' oninput="S.iv[\''+curOpp.id+'\' ]=this.value.replace(/\\D/g,\'\').slice(0,'+cl+');this.value=S.iv[\''+curOpp.id+'\']"'
      +'>';
    document.getElementById('g-cf-actions').innerHTML=
      '<button class="btn btn-p btn-full" onclick="doAtk(\''+curOpp.id+'\')">⚡ Атака</button>';
  }
}

/* Центр: ожидание */
function rCenterWaiting(data,allP){
  var cur=allP.find(function(p){return p.id===data.current_turn_player_id;});
  var curName=cur?(cur.name+(cur.is_bot?' 🤖':'')):'?';
  // Показываем кого атакует текущий игрок
  var activeAtks=data.attacks_this_turn&&data.attacks_this_turn[cur&&cur.id]||{};
  var targetIds=Object.keys(activeAtks);
  var targetNames=targetIds.map(function(tid){
    var t=allP.find(function(p){return p.id===tid;});
    return t?t.name+(t.is_bot?' 🤖':''):'?';
  });
  if(targetNames.length){
    document.getElementById('g-cf-label').innerHTML=
      '<span style="color:var(--A);font-weight:700">'+curName+'</span>'
      +' <span style="color:var(--mu2)">→</span> '
      +'<span style="color:var(--tx)">'+targetNames.join(', ')+'</span>';
  } else {
    document.getElementById('g-cf-label').innerHTML=
      '<span style="color:var(--A);font-weight:700">'+curName+'</span>'
      +' <span style="color:var(--mu2)">делает ход</span>';
  }
  var box=document.getElementById('g-cf-box');
  if(box.className!=='cf-box waiting'||!box.querySelector('.cf-dots')){
    box.className='cf-box waiting';
    box.innerHTML='<span class="cf-dots">···</span>';
  }
  document.getElementById('g-cf-actions').innerHTML='';
  document.getElementById('g-targets').style.display='none';
}

/* Карточки игроков — всегда все, без сброса скролла */
function attackLabel(data,h){
  var attacker=data.players[h.attacker_id], target=data.players[h.target_id];
  return (attacker?attacker.name:'Игрок')+' → '+(target?target.name:'Игрок');
}
function spectatorAttacks(data,targetId){
  var attacks=data.history.filter(function(h){return h.target_id===targetId;});
  if(!attacks.length)return '<div class="atk-empty">нет атак</div>';
  return attacks.map(function(h){
    return '<div style="width:100%;padding:6px 0"><div style="font-size:.65rem;color:var(--mu2)">'+attackLabel(data,h)+' · Раунд '+h.round+'</div>'
      +'<div class="atk-row'+(h.confirmed?'':' atk-row-wait')+'"><span class="atk-row-g">'+h.guess+'</span><span class="atk-row-sep">—</span><span class="atk-row-h">'+(h.confirmed?h.hits:'?')+'</span></div></div>';
  }).join('');
}

function rPlayers(data,allP,cl){
  var zone=document.getElementById('g-players');
  var toShow=allP.filter(function(p){return data.spectator||!p.is_me;});
  var curPid=data.current_turn_player_id;

  toShow.forEach(function(p){
    var isTurn=p.id===curPid;
    var isElim=p.eliminated;
    var cardId='pcard-'+p.id;

    // Строим данные для этой карточки
    var allMyAtks=data.history.filter(function(h){return h.attacker_id===data.my_id&&h.target_id===p.id;});


    var otherAtks=data.history.filter(function(h){return h.target_id===p.id&&h.attacker_id!==data.my_id;});


    // Ключ = snapshot данных (без CSS-классов анимации)
    // Карточка пересоздаётся только когда реально меняются данные
    var meElimForKey=data.spectator;
    var dataKey=JSON.stringify({
      turn:isTurn, elim:isElim, meElim:meElimForKey, name:p.name, host:data.is_host, pending:data.attacks_this_turn,
      my:allMyAtks.map(function(h){return h.id+':'+h.confirmed+':'+h.hits;}),
      oth:meElimForKey?otherAtks.map(function(h){return h.id+':'+h.confirmed+':'+h.hits;}):[]
    });

    var existing=document.getElementById(cardId);
    if(existing&&existing.dataset.key===dataKey) return; // ничего не изменилось — не трогаем

    // Строим HTML только если изменилось
    var gridHTML='';
    if(allMyAtks.length){
      gridHTML=allMyAtks.map(function(h){
        if(h.confirmed){
          return '<div class="atk-row">'
            +'<span class="atk-row-g">'+h.guess+'</span>'
            +'<span class="atk-row-sep">-</span>'
            +'<span class="atk-row-h">'+h.hits+'</span>'
            +'</div>';
        } else {
          return '<div class="atk-row atk-row-wait">'
            +'<span class="atk-row-g atk-blink">'+h.guess+'</span>'
            +'<span class="atk-row-sep">-</span>'
            +'<span class="atk-row-p">?</span>'
            +'</div>';
        }
      }).join('');
    } else {
      gridHTML='<div class="atk-empty">нет атак</div>';
    }

    // Я выбыл — вижу все цифры всех атак. Активный — только свои.
    var amElim=data.spectator;

    var incHTML='';
    if(amElim){
      gridHTML=spectatorAttacks(data,p.id);
    }
    // Активный игрок — чужие атаки не видны совсем

    var cardHTML='<div id="'+cardId+'" class="p-card'+(isTurn?' turn':'')+(isElim?' elim':'')+'">'
      +'<div class="p-card-hd">'
      +'<div class="p-card-av">'+p.name[0].toUpperCase()+'</div>'
      +'<div class="p-card-nm">'+p.name+(p.is_bot?' 🤖':'')+(isElim?' <span style="font-size:.6rem;color:var(--R)">Выбыл</span>':'')+'</div>'
      +(isTurn?'<div class="turn-dot"></div>':'')
      +(data.is_host&&!p.is_me&&!isElim?'<button class="kick-btn" onclick="doKick(\''+p.id+'\')">✕</button>':'')
      +'</div>'
      +(gridHTML?'<div class="atk-grid">'+gridHTML+'</div>':'')
      +(incHTML?'<div class="atk-grid" style="border-top:1px solid var(--brd)">'+incHTML+'</div>':'')
      +'</div>';

    var tmp=document.createElement('div');
    tmp.innerHTML=cardHTML;
    var newEl=tmp.firstChild;
    newEl.dataset.key=dataKey; // сохраняем ключ для следующего сравнения

    if(!existing){
      zone.appendChild(newEl);
    } else {
      // replaceWith не трогает window.scrollY в современных браузерах
      existing.replaceWith(newEl);
    }
  });

  // Удаляем карточки ушедших игроков
  var showIds=toShow.map(function(p){return 'pcard-'+p.id;});
  Array.from(zone.children).forEach(function(el){
    if(el.id&&showIds.indexOf(el.id)<0) el.remove();
  });
}

/* ── Выбор цели ── */
function pickTarget(tid){
  S.curTarget=tid;
  if(S.lastData) rGame(S.lastData);
}

/* ── Выбор совпадений ── */
function pickHits(hid,n,btn){
  S.csel[hid]=n;
  // Обновляем только кнопки без перерисовки
  var wrap=document.getElementById('hits-btns-wrap');
  if(wrap) wrap.querySelectorAll('.h-btn').forEach(function(b){b.classList.remove('sel');});
  btn.classList.add('sel');
  var sb=document.getElementById('send-btn-main');
  if(sb){sb.classList.add('ok');sb.disabled=false;sb.textContent='✓ Отправить';sb.onclick=function(){sendConfirm(hid);};}
}

async function sendConfirm(hid){
  var hits=S.csel[hid];
  if(hits===undefined||hits===null)return;
  var result=await api('confirm',{attack_id:hid,hits:hits});if(result.status!=='ok')return;
  delete S.csel[hid];
  fetchState();
}

async function doAtk(tid){
  var cl=S.lastData&&S.lastData.code_length||4;
  var inp=document.getElementById('cf-main-inp');
  var val=(inp?inp.value:'').replace(/\D/g,'').slice(0,cl);
  if(!val||val.length!==cl){
    if(inp){inp.classList.add('shake');setTimeout(function(){inp.classList.remove('shake');},400);inp.focus({preventScroll:true});}
    return;
  }
  var result=await api('attack',{target_id:tid,guess:val});
  if(result.status!=='ok')return;
  S.iv[tid]='';
  if(inp)inp.value='';
  S.curTarget=null;
  fetchState();
}

var pendingActions=new Set();
async function api(action,body){
  if(body!==undefined&&pendingActions.has(action))return {status:'busy'};
  if(body!==undefined)pendingActions.add(action);
  var controller=new AbortController();var timer=setTimeout(function(){controller.abort();},15000);
  try{
    var opts=body!==undefined?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{method:'GET'};
    opts.signal=controller.signal;opts.cache='no-store';
    var r=await fetch('?action='+action,opts);
    var result=await r.json();
    if(result.status==='error'&&body!==undefined)alert(result.msg||'Ошибка запроса');
    return result;
  }catch(e){if(body!==undefined)alert('Нет связи с сервером. Дождитесь обновления и повторите действие.');return{status:'error'};}
  finally{clearTimeout(timer);if(body!==undefined)pendingActions.delete(action);}
}
(async function(){
  var d=await api('state');
  if(d&&d.status==='no_room'){
    var code;try{code=localStorage.getItem('codebreak:last');}catch(e){}
    var login=code?savedLogin(code):null;
    if(login){d=await api('join_room',{code:code,name:'Игрок',resume_token:login.token});}
  }
  if(d&&d.status==='ok'){remember(d);S.myId=d.my_id||d.player_id;S.roomCode=d.room_code||d.code;startPoll();}
})();
</script>
</body>
</html>
