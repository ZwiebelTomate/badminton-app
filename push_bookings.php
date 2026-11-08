<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function done(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')done(['ok'=>false,'error'=>'Nur POST erlaubt'],405);
try{
 $cfg=require __DIR__.'/config.php';
 // Separates, serverseitiges Versandpasswort; niemals im HTML hinterlegen.
 if(empty($cfg['push_send_password']))done(['ok'=>false,'error'=>'Bitte push_send_password in config.php hinterlegen.'],503);
 $input=json_decode((string)file_get_contents('php://input'),true);
 if(!is_array($input)||!is_string($input['password']??null)||!hash_equals((string)$cfg['push_send_password'],$input['password']))done(['ok'=>false,'error'=>'Falsches Versandpasswort'],403);
 require __DIR__.'/vendor/autoload.php';
 $pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$cfg['host'],$cfg['port'],$cfg['database']),$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $pdo->exec('CREATE TABLE IF NOT EXISTS badminton_push_booked_sent (appointment_id VARCHAR(80) NOT NULL, endpoint_hash CHAR(64) NOT NULL, sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(appointment_id,endpoint_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 $state=json_decode((string)$pdo->query('SELECT state_json FROM badminton_state WHERE id=1')->fetchColumn(),true);
 if(!is_array($state))done(['ok'=>false,'error'=>'Termine nicht lesbar'],500);
 $now=new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin'));
 $appointments=array_values(array_filter($state['appointments']??[],static function($a)use($now){return is_array($a)&&!empty($a['booked'])&&!empty($a['id'])&&is_array($a['participants']??null)&&!empty($a['date'])&&new DateTimeImmutable($a['date'].'T'.($a['from']??'00:00'),new DateTimeZone('Europe/Berlin'))>=$now;}));
 usort($appointments,static fn($a,$b)=>strcmp(($a['date']??'').($a['from']??''),($b['date']??'').($b['from']??'')));
 $subs=$pdo->query('SELECT endpoint_hash,player,subscription_json FROM badminton_push_subscriptions WHERE booked_enabled=1')->fetchAll(PDO::FETCH_ASSOC);
 $check=$pdo->prepare('SELECT 1 FROM badminton_push_booked_sent WHERE appointment_id=? AND endpoint_hash=?');
 $mark=$pdo->prepare('INSERT IGNORE INTO badminton_push_booked_sent(appointment_id,endpoint_hash) VALUES(?,?)');
 $remove=$pdo->prepare('DELETE FROM badminton_push_subscriptions WHERE endpoint_hash=?');
 $keys=json_decode((string)file_get_contents(dirname(__DIR__,2).'/push_keys.json'),true,512,JSON_THROW_ON_ERROR);
 $push=new Minishlink\WebPush\WebPush(['VAPID'=>['subject'=>'mailto:push@badminton-app.de','publicKey'=>$keys['publicKey'],'privateKey'=>$keys['privateKey']]]);
 $queued=[];$total=0;
 foreach($subs as $sub){
  $items=[];foreach($appointments as $a){if(!in_array($sub['player'],$a['participants'],true))continue;$id=(string)$a['id'];if(strlen($id)>80)continue;$check->execute([$id,$sub['endpoint_hash']]);if($check->fetchColumn())continue;$items[]=$a;}
  if(!$items)continue;
  $lines=[];foreach($items as $a){$dt=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$a['date']);$date=$dt?$dt->format('d.m.Y'):(string)$a['date'];$court=(string)($a['court']??'');$lines[]=$date.' · '.($a['from']??'').' Uhr · '.($court===''||$court==='0'?'Court unbekannt':'Court '.$court);}
  $body="Bei folgenden Terminen bist du dabei:\n".implode("\n",$lines)."\nBitte in deinen Kalender speichern! :)";
  $payload=json_encode(['title'=>'🏸 Badminton wurde gebucht!','body'=>$body,'tag'=>'badminton-bookings','url'=>'./'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $json=json_decode($sub['subscription_json'],true);if(!is_array($json)||empty($json['endpoint']))continue;
  $push->queueNotification(Minishlink\WebPush\Subscription::create($json),$payload);
  $queued[$json['endpoint']]=['hash'=>$sub['endpoint_hash'],'ids'=>array_map(static fn($a)=>(string)$a['id'],$items)];
 }
 foreach($push->flush() as $report){$entry=$queued[$report->getEndpoint()]??null;if(!$entry)continue;if($report->isSuccess()){foreach($entry['ids'] as $id)$mark->execute([$id,$entry['hash']]);$total++;}else{error_log('Badminton Sammel-Push: '.$report->getReason());if($report->isSubscriptionExpired())$remove->execute([$entry['hash']]);}}
 done(['ok'=>true,'message'=>$total?'Sammel-Push an '.$total.' Gerät(e) gesendet.':'Keine neuen Buchungen für aktivierte Empfänger vorhanden.']);
}catch(Throwable $e){error_log('Badminton Buchungs-Push: '.$e->getMessage());done(['ok'=>false,'error'=>'Versand momentan nicht möglich'],500);}
