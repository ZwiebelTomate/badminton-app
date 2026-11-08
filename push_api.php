<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
function respond(array $v,int $code=200):never{http_response_code($code);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 $cfg=require __DIR__.'/config.php';
 $pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$cfg['host'],$cfg['port'],$cfg['database']),$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $pdo->exec('CREATE TABLE IF NOT EXISTS badminton_push_subscriptions (endpoint_hash CHAR(64) NOT NULL PRIMARY KEY, endpoint TEXT NOT NULL, player VARCHAR(120) NOT NULL, subscription_json TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $keys=json_decode((string)file_get_contents(dirname(__DIR__,2).'/push_keys.json'),true,512,JSON_THROW_ON_ERROR);
  respond(['ok'=>true,'publicKey'=>$keys['publicKey']]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')respond(['ok'=>false,'error'=>'Methode nicht erlaubt'],405);
 if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))respond(['ok'=>false,'error'=>'JSON erforderlich'],415);
 $body=json_decode((string)file_get_contents('php://input'),true);if(!is_array($body))respond(['ok'=>false,'error'=>'Ungültige Anfrage'],400);
 $action=$body['action']??'';
 if($action==='subscribe'){
  $player=$body['player']??null;$sub=$body['subscription']??null;
  if(!is_string($player)||!is_array($sub)||!is_string($sub['endpoint']??null)||strlen($sub['endpoint'])>2000||!str_starts_with($sub['endpoint'],'https://')||!is_string($sub['keys']['p256dh']??null)||!is_string($sub['keys']['auth']??null))respond(['ok'=>false,'error'=>'Ungültige Push-Daten'],400);
  $row=$pdo->query('SELECT state_json FROM badminton_state WHERE id=1')->fetchColumn();$state=json_decode((string)$row,true);
  if(!is_array($state)||!in_array($player,$state['players']??[],true))respond(['ok'=>false,'error'=>'Spieler nicht vorhanden'],400);
  $json=json_encode($sub,JSON_UNESCAPED_SLASHES);if(strlen($json)>6000)respond(['ok'=>false,'error'=>'Abo zu groß'],400);
  $stmt=$pdo->prepare('INSERT INTO badminton_push_subscriptions(endpoint_hash,endpoint,player,subscription_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE player=VALUES(player),subscription_json=VALUES(subscription_json)');
  $stmt->execute([hash('sha256',$sub['endpoint']),$sub['endpoint'],$player,$json]);respond(['ok'=>true]);
 }
 if($action==='unsubscribe'){
  $endpoint=$body['endpoint']??null;if(!is_string($endpoint)||strlen($endpoint)>2000)respond(['ok'=>false,'error'=>'Ungültiger Endpoint'],400);
  $stmt=$pdo->prepare('DELETE FROM badminton_push_subscriptions WHERE endpoint_hash=?');$stmt->execute([hash('sha256',$endpoint)]);respond(['ok'=>true]);
 }
 respond(['ok'=>false,'error'=>'Unbekannte Aktion'],400);
}catch(Throwable $e){error_log('Badminton Push API: '.$e->getMessage());respond(['ok'=>false,'error'=>'Push-Dienst vorübergehend nicht verfügbar'],500);}
