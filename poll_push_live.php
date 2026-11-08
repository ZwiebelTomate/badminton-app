<?php
declare(strict_types=1);
/** Live: send one notification per event and subscribed endpoint. */
function badmintonPollPushLive(PDO $db): array {
    if (($db->query('SELECT DATABASE()')->fetchColumn()) === 'db_463955_2') throw new RuntimeException('Testdatenbank nicht erlaubt');
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $db->exec('CREATE TABLE IF NOT EXISTS badminton_poll_live_push_sent (event_key VARCHAR(180) NOT NULL, endpoint_hash CHAR(64) NOT NULL, sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(event_key,endpoint_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $subs=$db->query('SELECT endpoint_hash,player,subscription_json FROM badminton_push_subscriptions');
    $subscriptions=$subs->fetchAll(PDO::FETCH_ASSOC);
    $events=[];
    $polls=$db->query('SELECT id,created_at,closes_at,status FROM badminton_polls ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
    foreach($polls as $poll){
        $id=(int)$poll['id'];
        $start=new DateTimeImmutable($poll['created_at'],new DateTimeZone('Europe/Berlin'));
        $close=new DateTimeImmutable($poll['closes_at'],new DateTimeZone('Europe/Berlin'));
        if($poll['status']==='open' && $now >= $start && $now < $close){
            // A poll-opening notification is retried until successfully delivered.
            $events[]=['poll-open-'.$id,'🗳️ Neue Badminton-Abstimmung','Die nächsten drei Wochen stehen zur Abstimmung bereit.'];
            $reminderDay=$close->format('Y-m-d');
            if($now->format('Y-m-d')===$reminderDay && (int)$now->format('G')===9){
                $events[]=['poll-reminder-'.$id,'⏰ Letzte Chance abzustimmen','Die Badminton-Terminabstimmung endet heute um 12:00 Uhr. Bitte stimme jetzt ab!'];
            }
        }
        if($poll['status']==='closed' && $now >= $close && $now < $close->modify('+7 days')){
            $events[]=['poll-closed-'.$id,'📅 Badminton-Abstimmung beendet','Die Abstimmung ist abgeschlossen. Schau dir die neuen Termine in der App an.'];
        }
    }
    if(!$subscriptions||!$events)return ['events'=>count($events),'sent'=>0,'registered_devices'=>count($subscriptions)];
    $autoload=__DIR__.'/vendor/autoload.php';$keyfile=dirname(__DIR__,2).'/push_keys.json';
    if(!is_readable($autoload)||!is_readable($keyfile))throw new RuntimeException('Push-Bibliothek oder VAPID-Schlüssel nicht lesbar');
    require_once $autoload;
    $keys=json_decode((string)file_get_contents($keyfile),true,512,JSON_THROW_ON_ERROR);
    $webPush=new \Minishlink\WebPush\WebPush(['VAPID'=>['subject'=>'mailto:push@badminton-app.de','publicKey'=>$keys['publicKey'],'privateKey'=>$keys['privateKey']]]);
    $check=$db->prepare('SELECT 1 FROM badminton_poll_live_push_sent WHERE event_key=? AND endpoint_hash=?');
    $mark=$db->prepare('INSERT IGNORE INTO badminton_poll_live_push_sent(event_key,endpoint_hash) VALUES(?,?)');
    $sent=0;$errors=0;
    foreach($events as [$event,$title,$body])foreach($subscriptions as $sub){
        if(str_starts_with($event,'poll-reminder-')){
            $pollId=(int)substr($event,strlen('poll-reminder-'));
            $v=$db->prepare('SELECT 1 FROM badminton_poll_votes WHERE poll_id=? AND player=? LIMIT 1');
            $v->execute([$pollId,$sub['player']]);
            if($v->fetchColumn())continue;
        }
        $check->execute([$event,$sub['endpoint_hash']]);if($check->fetchColumn())continue;
        try{
            $json=json_decode($sub['subscription_json'],true,512,JSON_THROW_ON_ERROR);
            $webPush->queueNotification(\Minishlink\WebPush\Subscription::create($json),json_encode(['title'=>$title,'body'=>$body,'tag'=>$event],JSON_UNESCAPED_UNICODE));
            foreach($webPush->flush() as $report){if($report->isSuccess()){$mark->execute([$event,$sub['endpoint_hash']]);$sent++;}else{$errors++;error_log('Badminton Live Poll Push: '.$report->getReason());}}
        }catch(Throwable $e){$errors++;error_log('Badminton Live Poll Push: '.$e->getMessage());}
    }
    return ['events'=>count($events),'sent'=>$sent,'errors'=>$errors,'registered_devices'=>count($subscriptions)];
}
