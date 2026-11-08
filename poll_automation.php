<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');
function pollAutomationOpen(PDO $db): array {
 $now=new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin'));
 $raw=$db->query('SELECT state_json FROM badminton_state WHERE id=1')->fetchColumn();$state=json_decode((string)$raw,true);
 if(!is_array($state))return ['opened'=>false,'reason'=>'Keine App-Daten'];
 $last=null;
 foreach(($state['appointments']??[]) as $a){
  if(!is_array($a)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($a['date']??'')))continue;
  $from=preg_match('/^\d{2}:\d{2}$/',(string)($a['from']??''))?$a['from']:'18:30';
  $date=new DateTimeImmutable($a['date'].' '.$from,new DateTimeZone('Europe/Berlin'));
  if($last===null||$date>$last)$last=$date;
 }
 if(!$last)return ['opened'=>false,'reason'=>'Keine Termine'];
 $start=$last->modify('-5 days')->setTime(9,0);
 if($now<$start||$now>=$start->modify('+10 minutes'))return ['opened'=>false,'reason'=>'Außerhalb Startfenster (09:00–09:10)'];
 $monday=$last->modify('monday next week')->setTime(0,0);$weeks=[];
 for($w=0;$w<3;$w++){$week=[];for($d=0;$d<7;$d++)$week[]=$monday->modify('+'.($w*7+$d).' days')->format('Y-m-d');$weeks[]=$week;}
 $json=json_encode($weeks,JSON_UNESCAPED_SLASHES);
 $db->beginTransaction();try{
  $db->query('SELECT state_json FROM badminton_state WHERE id=1 FOR UPDATE')->fetchColumn();
  $q=$db->prepare('SELECT id FROM badminton_polls WHERE weeks_json=? LIMIT 1');$q->execute([$json]);
  if($q->fetchColumn()){$db->commit();return ['opened'=>false,'reason'=>'Bereits vorhanden'];}
  $closes=$now->modify('tomorrow')->setTime(12,0)->format('Y-m-d H:i:s');
  $q=$db->prepare('INSERT INTO badminton_polls(created_at,closes_at,status,weeks_json) VALUES(?, ?, "open", ?)');
  $q->execute([$now->format('Y-m-d H:i:s'),$closes,$json]);$id=(int)$db->lastInsertId();$db->commit();
  return ['opened'=>true,'poll_id'=>$id,'closes_at'=>$closes];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function results($db,$poll,$state){$weeks=json_decode($poll['weeks_json'],true);$q=$db->prepare('SELECT player,answers_json FROM badminton_poll_votes WHERE poll_id=?');$q->execute([$poll['id']]);$votes=[];foreach($q as $r){$votes[$r['player']]=json_decode($r['answers_json'],true)?:[];}
$history=[];foreach(($state['appointments']??[]) as $appt){if(($appt['date']??'')>=date('Y-m-d'))continue;foreach(($appt['participants']??[]) as $name)$history[$name]=($history[$name]??0)+1;}
$res=[];foreach($weeks as $i=>$week){$days=[];foreach($week as $day){$people=[];foreach($votes as $player=>$answer){if(in_array($day,$answer[$i]??[],true))$people[]=$player;}$weight=array_sum(array_map(fn($n)=>1/(1+($history[$n]??0)),$people));$days[]=['date'=>$day,'players'=>$people,'count'=>count($people),'tieWeight'=>$weight];}usort($days,fn($a,$b)=>($b['count']<=>$a['count'])?:($b['tieWeight']<=>$a['tieWeight'])?:strcmp($a['date'],$b['date']));$res[]=$days;}
$none=[];foreach($weeks as $i=>$week){$none[$i]=[];foreach($votes as $player=>$answers){if(array_key_exists($i,$answers)&&is_array($answers[$i])&&count($answers[$i])===0)$none[$i][]=$player;}sort($none[$i]);}
 return ['results'=>$res,'voters'=>array_keys($votes),'total'=>count($state['players']??[]),'none'=>$none];}

 function finalizeExpiredPolls(PDO $db): array {
  $ids=$db->query("SELECT id FROM badminton_polls WHERE status='open' AND closes_at<=NOW() ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
  foreach($ids as $id){
    $db->beginTransaction();
    try{
      $q=$db->prepare('SELECT * FROM badminton_polls WHERE id=? FOR UPDATE');$q->execute([$id]);$poll=$q->fetch(PDO::FETCH_ASSOC);
      if(!$poll || $poll['status']!=='open' || strtotime($poll['closes_at'])>time()){$db->commit();continue;}
      $stateRaw=$db->query('SELECT state_json FROM badminton_state WHERE id=1')->fetchColumn();
      $state=json_decode((string)$stateRaw,true);
      if(!is_array($state))throw new RuntimeException('Kein App-Datenstand');
      $r=results($db,$poll,$state);
      $db->prepare('UPDATE badminton_polls SET status="closed",results_json=? WHERE id=?')->execute([json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id]);
      $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  }
  return [];
}
