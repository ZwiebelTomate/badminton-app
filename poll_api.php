<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Europe/Berlin');
function out($data,$code=200){http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function admin($body){return hash_equals('b5cd393de3ed45119d0f191e8bcf4b4555dc2f0051791bbc54d6b7857e9143af',hash('sha256',(string)($body['password']??'')));}
function db(){ $c=require __DIR__.'/config.php';if(empty($c['database'])||$c['database']==='db_463955_2')out(['ok'=>false,'error'=>'Live-Datenbank nicht konfiguriert'],403);return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$c['host'],$c['port'],$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function setup($db){
$db->exec('CREATE TABLE IF NOT EXISTS badminton_polls (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, closes_at DATETIME NOT NULL, status VARCHAR(16) NOT NULL DEFAULT "open", weeks_json TEXT NOT NULL, results_json TEXT NULL, approved_json TEXT NULL, UNIQUE KEY uniq_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$db->exec('CREATE TABLE IF NOT EXISTS badminton_poll_votes (poll_id BIGINT UNSIGNED NOT NULL, player VARCHAR(120) NOT NULL, answers_json TEXT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(poll_id,player)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
function state($db){$s=$db->query('SELECT state_json FROM badminton_state WHERE id=1')->fetchColumn();return json_decode((string)$s,true)?:[];}
function validAnswers($a,$weeks){if(!is_array($a)||count($a)!==3)return false;foreach($weeks as $i=>$week){if(!array_key_exists($i,$a)||!is_array($a[$i]))return false;$dates=$a[$i];if(count($dates)!==count(array_unique($dates)))return false;foreach($dates as $date){if(!is_string($date)||!in_array($date,$week,true))return false;}}return true;}
require_once __DIR__.'/poll_automation.php';

try{$db=db();setup($db);$action=$_GET['action']??'';$body=[];if($_SERVER['REQUEST_METHOD']==='POST'){$body=json_decode((string)file_get_contents('php://input'),true);if(!is_array($body))out(['ok'=>false,'error'=>'Ungültige Daten'],400);$action=$body['action']??'';}
$state=state($db);$players=$state['players']??[];
if($action==='list'){$created=[];$polls=$db->query('SELECT * FROM badminton_polls ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);$data=[];foreach($polls as $poll){$poll['weeks']=json_decode($poll['weeks_json'],true);$poll['approved']=json_decode($poll['approved_json']??'null',true)?:[];$r=results($db,$poll,$state);$poll['results']=$r['results'];$poll['none']=$r['none'];$poll['voters']=$r['voters'];$poll['total']=$r['total'];$poll['needs_approval']=($poll['status']==='closed' && $poll['results_json']!==null && $poll['approved_json']===null);unset($poll['weeks_json'],$poll['results_json'],$poll['approved_json']);$data[]=$poll;}out(['ok'=>true,'polls'=>$data,'created'=>$created]);}
if($action==='vote'){$id=(int)($body['poll_id']??0);$player=(string)($body['player']??'');if(!in_array($player,$players,true))out(['ok'=>false,'error'=>'Spieler nicht vorhanden'],400);$q=$db->prepare('SELECT * FROM badminton_polls WHERE id=?');$q->execute([$id]);$poll=$q->fetch(PDO::FETCH_ASSOC);if(!$poll||$poll['status']!=='open'||strtotime($poll['closes_at'])<=time())out(['ok'=>false,'error'=>'Abstimmung geschlossen'],409);$weeks=json_decode($poll['weeks_json'],true);$answers=$body['answers']??null;if(!validAnswers($answers,$weeks))out(['ok'=>false,'error'=>'Bitte alle drei Wochen beantworten'],400);$q=$db->prepare('INSERT INTO badminton_poll_votes(poll_id,player,answers_json,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE answers_json=VALUES(answers_json),updated_at=NOW()');$q->execute([$id,$player,json_encode($answers)]);out(['ok'=>true]);}
if($action==='myvote'){$id=(int)($_GET['poll_id']??0);$player=(string)($_GET['player']??'');if(!in_array($player,$players,true))out(['ok'=>false],400);$q=$db->prepare('SELECT answers_json FROM badminton_poll_votes WHERE poll_id=? AND player=?');$q->execute([$id,$player]);$r=$q->fetchColumn();out(['ok'=>true,'answers'=>$r===false?null:json_decode($r,true)]);}
if($action==='approve'){
 $id=(int)($body['poll_id']??0);$player=(string)($body['player']??'');$choices=$body['choices']??null;
 if($player!=='Marco'||!in_array('Marco',$players,true))out(['ok'=>false,'error'=>'Nur als Marco auswählbar'],403);
 if(!is_array($choices)||count($choices)!==3)out(['ok'=>false,'error'=>'Bitte alle drei Wochen auswählen'],400);
 $db->beginTransaction();try{
  $q=$db->prepare('SELECT * FROM badminton_polls WHERE id=? FOR UPDATE');$q->execute([$id]);$poll=$q->fetch(PDO::FETCH_ASSOC);
  if(!$poll||$poll['status']!=='closed'||$poll['approved_json']!==null){$db->rollBack();out(['ok'=>false,'error'=>'Umfrage nicht freigegeben oder bereits bestätigt'],409);}
  $weeks=json_decode($poll['weeks_json'],true);
  foreach($weeks as $i=>$week){if(!array_key_exists($i,$choices)||($choices[$i]!==''&&!in_array($choices[$i],$week,true)))throw new RuntimeException('Ungültige Datumsauswahl');}
  $q=$db->query('SELECT state_json FROM badminton_state WHERE id=1 FOR UPDATE');$oldJson=$q->fetchColumn();$current=json_decode((string)$oldJson,true);
  if(!is_array($current))throw new RuntimeException('App-Daten fehlen');
  $r=results($db,$poll,$current);$approved=[];
  foreach($choices as $i=>$date){
   if($date==='')continue;
   if(strtotime($date.' 18:30')<=time())throw new RuntimeException('Vergangene Termine nicht auswählbar');
   foreach(($current['appointments']??[]) as $existing){if(($existing['date']??'')===$date)throw new RuntimeException('Termin existiert bereits: '.$date);}
   $day=null;foreach($r['results'][$i] as $candidate){if($candidate['date']===$date){$day=$candidate;break;}}
   if(!$day)throw new RuntimeException('Tag nicht gefunden');
   $appt=['id'=>(int)round(microtime(true)*1000)+$i+($id*10),'date'=>$date,'from'=>'18:30','to'=>'20:00','food'=>false,'booked'=>false,'court'=>'','foodPlace'=>'','participants'=>$day['players'],'declined'=>[]];
   $current['appointments'][]=$appt;$approved[$i]=$appt['id'];
  }
  if($approved){
   usort($current['appointments'],fn($x,$y)=>strcmp(($x['date']??'').($x['from']??''),($y['date']??'').($y['from']??'')));
   $db->prepare('INSERT INTO badminton_backups(state_json,reason) VALUES(?,?)')->execute([$oldJson,'before_poll_manual_approval']);
   $db->prepare('UPDATE badminton_state SET state_json=? WHERE id=1')->execute([json_encode($current,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
  }
  $db->prepare('UPDATE badminton_polls SET approved_json=? WHERE id=?')->execute([json_encode(['appointments'=>$approved,'dates'=>$choices],JSON_UNESCAPED_UNICODE),$id]);
  $db->commit();out(['ok'=>true,'dates'=>$choices]);
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();out(['ok'=>false,'error'=>$e->getMessage()],409);}
}
if($action==='close')out(['ok'=>false,'error'=>'Abschluss erfolgt automatisch'],403);
out(['ok'=>false,'error'=>'Unbekannte Aktion'],400);
}catch(Throwable $e){error_log('Badminton Poll API: '.$e->getMessage());out(['ok'=>false,'error'=>'Serverfehler: Bitte Protokoll prüfen'],500);}
