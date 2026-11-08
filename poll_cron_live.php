<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Berlin');
// Explicit activation file is stored outside the public web directory.
$root=dirname(__DIR__,2);
$enabled=$root.'/badminton_poll_live_enabled.txt';
if(!is_file($enabled)||trim((string)file_get_contents($enabled))!=='YES') {http_response_code(503);exit(json_encode(['ok'=>false,'error'=>'Live-Automatik noch nicht aktiviert']));}
if(PHP_SAPI!=='cli') {
 $tokenFile=$root.'/badminton_cron_token.txt';
 $expected=is_readable($tokenFile)?trim((string)file_get_contents($tokenFile)):'';
 $provided=(string)($_GET['token']??'');
 if($expected===''||$provided===''||!hash_equals($expected,$provided)){http_response_code(404);exit;}
}
try {
 $cfg=require __DIR__.'/config.php';
 if(empty($cfg['database'])||$cfg['database']==='db_463955_2')throw new RuntimeException('Keine Live-Datenbank');
 $db=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$cfg['host'],$cfg['port'],$cfg['database']),$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 require_once __DIR__.'/poll_automation.php';
 // Tables are initialized by poll_api.php. Cron does not create them silently.
 $db->exec('CREATE TABLE IF NOT EXISTS badminton_polls (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, closes_at DATETIME NOT NULL, status VARCHAR(16) NOT NULL DEFAULT "open", weeks_json TEXT NOT NULL, results_json TEXT NULL, approved_json TEXT NULL, UNIQUE KEY uniq_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 $db->exec('CREATE TABLE IF NOT EXISTS badminton_poll_votes (poll_id BIGINT UNSIGNED NOT NULL, player VARCHAR(120) NOT NULL, answers_json TEXT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(poll_id,player)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 $opened=pollAutomationOpen($db);
 $created=finalizeExpiredPolls($db);
 require_once __DIR__.'/poll_push_live.php';
 $push=badmintonPollPushLive($db);
 echo json_encode(['ok'=>true,'opened'=>$opened,'appointments_created'=>count($created),'push'=>$push],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){error_log('Badminton Live Poll: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Serverprotokoll prüfen']);}
