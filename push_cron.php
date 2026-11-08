<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli' && !defined('BADMINTON_AUTHORIZED_CRON')){http_response_code(404);exit;}
require __DIR__.'/vendor/autoload.php';
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
date_default_timezone_set('Europe/Berlin');
// The URL cron is called hourly; run only at 20:00 Berlin time (DST-safe).
if(PHP_SAPI!=='cli' && date('G')!== '20'){exit('Außerhalb des Versandzeitfensters.');}
$lock=fopen(dirname(__DIR__,2).'/badminton_push_cron.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){exit("Läuft bereits.\n");}
$cfg=require __DIR__.'/config.php';
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$cfg['host'],$cfg['port'],$cfg['database']),$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS badminton_push_sent (appointment_id VARCHAR(80) NOT NULL, appointment_date DATE NOT NULL, endpoint_hash CHAR(64) NOT NULL, sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(appointment_id,appointment_date,endpoint_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$keys=json_decode((string)file_get_contents(dirname(__DIR__,2).'/push_keys.json'),true,512,JSON_THROW_ON_ERROR);
$webPush=new WebPush(['VAPID'=>['subject'=>'mailto:push@badminton-app.de','publicKey'=>$keys['publicKey'],'privateKey'=>$keys['privateKey']]]);
$tomorrow=(new DateTimeImmutable('tomorrow',new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$row=$pdo->query('SELECT state_json FROM badminton_state WHERE id=1')->fetchColumn();$state=json_decode((string)$row,true);
if(!is_array($state)){exit("Keine gültigen Termine.\n");}
// Older push_api.php installations did not create reminder_enabled.
// Add the column once, preserving all existing subscriptions and enabling reminders by default.
$columnCheck=$pdo->query("SHOW COLUMNS FROM badminton_push_subscriptions LIKE 'reminder_enabled'");
if(!$columnCheck->fetch(PDO::FETCH_ASSOC)){
 try{
  $pdo->exec('ALTER TABLE badminton_push_subscriptions ADD COLUMN reminder_enabled TINYINT(1) NOT NULL DEFAULT 1');
  echo "Push-Einstellung reminder_enabled ergänzt.\n";
 }catch(PDOException $e){
  // A concurrent request may have created the column; check again before failing.
  $columnCheck=$pdo->query("SHOW COLUMNS FROM badminton_push_subscriptions LIKE 'reminder_enabled'");
  if(!$columnCheck->fetch(PDO::FETCH_ASSOC)){
   error_log('Badminton Push: reminder_enabled konnte nicht angelegt werden: '.$e->getMessage());
   throw $e;
  }
 }
}
$subs=$pdo->query('SELECT endpoint_hash,player,subscription_json FROM badminton_push_subscriptions WHERE reminder_enabled=1')->fetchAll(PDO::FETCH_ASSOC);
$insert=$pdo->prepare('INSERT IGNORE INTO badminton_push_sent(appointment_id,appointment_date,endpoint_hash) VALUES(?,?,?)');
$check=$pdo->prepare('SELECT 1 FROM badminton_push_sent WHERE appointment_id=? AND appointment_date=? AND endpoint_hash=?');
$remove=$pdo->prepare('DELETE FROM badminton_push_subscriptions WHERE endpoint_hash=?');
foreach(($state['appointments']??[]) as $a){
 if(!is_array($a)||($a['date']??'')!==$tomorrow||!is_array($a['participants']??null))continue;
 $id=(string)($a['id']??'');if($id===''||strlen($id)>80)continue;
 $time=(string)($a['from']??'');$court=(string)($a['court']??'');$queued=[];
 foreach($subs as $sub){if(!in_array($sub['player'],$a['participants'],true))continue;
  $check->execute([$id,$tomorrow,$sub['endpoint_hash']]);if($check->fetchColumn())continue;
  $json=json_decode($sub['subscription_json'],true);if(!is_array($json))continue;
  $body='Morgen um '.($time?:'der vereinbarten Zeit').' Uhr ist Badminton'.($court?' auf Court '.$court:'').'. Du bist dabei!';
  $payload=json_encode(['title'=>'🏸 Morgen Badminton!','body'=>$body,'tag'=>'badminton-'.$id],JSON_UNESCAPED_UNICODE);
  $webPush->queueNotification(Subscription::create($json),$payload);
  $queued[$json['endpoint']]=[$id,$tomorrow,$sub['endpoint_hash']];
 }
 foreach($webPush->flush() as $report){$endpoint=$report->getEndpoint();$entry=$queued[$endpoint]??null;if(!$entry)continue;
 if($report->isSuccess()){$insert->execute($entry);echo "Gesendet: ".$entry[2]."\n";}
 else{error_log('Badminton Push Fehler: '.$report->getReason());if($report->isSubscriptionExpired())$remove->execute([$entry[2]]);}
 }
}
echo "Fertig.\n";
