<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function stateCounts(array $state): array {
    return [
        'days' => is_array($state['days'] ?? null) ? count($state['days']) : 0,
        'appointments' => is_array($state['appointments'] ?? null) ? count($state['appointments']) : 0,
        'cashEntries' => is_array($state['cashEntries'] ?? null) ? count($state['cashEntries']) : 0,
    ];
}

function suspiciousMassLoss(array $old, array $new): bool {
    $a = stateCounts($old);
    $b = stateCounts($new);
    $emptied = 0;
    foreach ($a as $key => $count) {
        if ($count > 0 && $b[$key] === 0) $emptied++;
    }
    // Schutz für genau den gefährlichen Fall: mehrere zentrale Bereiche werden auf einmal geleert.
    return $emptied >= 2;
}

// Temporary group admin credential. Change this server-side value before wider sharing.
function adminAuthorized(array $body): bool {
    $cfg = require __DIR__ . '/config.php';
    $password = (string)($cfg['admin_password'] ?? '');

    return $password !== ''
        && hash_equals($password, (string)($body['admin_password'] ?? ''));
}
function normalized($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function protectedChange(array $old, array $new): bool {
    if (normalized($old['cashOpeningBalances'] ?? []) !== normalized($new['cashOpeningBalances'] ?? [])) return true;
    foreach (['cashEntries', 'days'] as $key) {
        $before = [];
        foreach (($old[$key] ?? []) as $item) {
            if (isset($item['id'])) $before[(string)$item['id']] = $item;
        }
        $after = [];
        foreach (($new[$key] ?? []) as $item) {
            if (isset($item['id'])) $after[(string)$item['id']] = $item;
        }
        foreach ($before as $id => $item) {
            if (!array_key_exists($id, $after)) return true;
            // Protect edits to existing cash transactions and completed/archived days.
            if ($key === 'cashEntries' || !empty($item['archived'])) {
                if (normalized($item) !== normalized($after[$id])) return true;
            }
        }
    }
    return false;
}

function addBackup(PDO $pdo, string $json, string $reason): void {
    $stmt = $pdo->prepare("INSERT INTO badminton_backups (state_json, reason) VALUES (:state, :reason)");
    $stmt->execute([':state'=>$json, ':reason'=>$reason]);
    // Maximal die 100 neuesten Sicherungen behalten.
    $pdo->exec("DELETE FROM badminton_backups WHERE id NOT IN (SELECT id FROM (SELECT id FROM badminton_backups ORDER BY id DESC LIMIT 100) keep_ids)");
}

try {
    $cfg = require __DIR__ . '/config.php';
    if (($cfg['password'] ?? '') === 'HIER_DEIN_PASSWORT_EINTRAGEN') {
        reply(['ok'=>false,'error'=>'Bitte zuerst das Datenbankpasswort in config.php eintragen.'], 500);
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['database']);
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS badminton_state (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        state_json LONGTEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS badminton_backups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        state_json LONGTEXT NOT NULL,
        reason VARCHAR(40) NOT NULL DEFAULT 'auto',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? '';

    if ($method === 'GET') {
        if ($action === 'backups') {
            $rows = $pdo->query("SELECT id, reason, created_at, LENGTH(state_json) AS bytes FROM badminton_backups ORDER BY id DESC LIMIT 100")->fetchAll();
            reply(['ok'=>true,'backups'=>$rows]);
        }
        if ($action === 'backup') {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, state_json, reason, created_at FROM badminton_backups WHERE id=:id");
            $stmt->execute([':id'=>$id]);
            $row = $stmt->fetch();
            if (!$row) reply(['ok'=>false,'error'=>'Backup nicht gefunden.'], 404);
            $state = json_decode($row['state_json'], true);
            reply(['ok'=>true,'backup'=>['id'=>$row['id'],'reason'=>$row['reason'],'created_at'=>$row['created_at'],'state'=>$state]]);
        }
        $stmt = $pdo->query("SELECT state_json, updated_at FROM badminton_state WHERE id=1");
        $row = $stmt->fetch();
        if (!$row) reply(['ok'=>true,'state'=>null,'updated_at'=>null,'revision'=>null]);
        reply(['ok'=>true,'state'=>json_decode($row['state_json'], true),'updated_at'=>$row['updated_at'],'revision'=>hash('sha256',$row['state_json'])]);
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw ?: '', true);
        if (!is_array($body)) reply(['ok'=>false,'error'=>'Ungültige Anfrage.'], 400);
        $postAction = $body['action'] ?? '';

        if ($postAction === 'backup_create') {
            $row = $pdo->query("SELECT state_json FROM badminton_state WHERE id=1")->fetch();
            if (!$row) reply(['ok'=>false,'error'=>'Es gibt noch keinen Datenstand zum Sichern.'], 404);
            addBackup($pdo, $row['state_json'], 'manual');
            reply(['ok'=>true]);
        }

        if ($postAction === 'restore') {
            if (!adminAuthorized($body)) reply(['ok'=>false,'password_required'=>true,'error'=>'Admin-Passwort erforderlich.'],403);
            $id = (int)($body['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT state_json FROM badminton_backups WHERE id=:id");
            $stmt->execute([':id'=>$id]);
            $backup = $stmt->fetch();
            if (!$backup) reply(['ok'=>false,'error'=>'Backup nicht gefunden.'], 404);
            $current = $pdo->query("SELECT state_json FROM badminton_state WHERE id=1")->fetch();
            $pdo->beginTransaction();
            try {
                if ($current) addBackup($pdo, $current['state_json'], 'before_restore');
                $stmt = $pdo->prepare("INSERT INTO badminton_state (id,state_json) VALUES (1,:state) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json)");
                $stmt->execute([':state'=>$backup['state_json']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            reply(['ok'=>true,'state'=>json_decode($backup['state_json'], true)]);
        }

        if ($postAction !== 'save' || !isset($body['state']) || !is_array($body['state'])) {
            reply(['ok'=>false,'error'=>'Ungültige Anfrage.'], 400);
        }

        $json = json_encode($body['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > 5_000_000) reply(['ok'=>false,'error'=>'Daten konnten nicht gespeichert werden.'], 400);

        // Die Zeile sperren, damit zwei Geräte niemals gleichzeitig denselben Stand überschreiben.
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->query("SELECT state_json FROM badminton_state WHERE id=1 FOR UPDATE");
            $current = $stmt->fetch();
            $currentRevision = $current ? hash('sha256', $current['state_json']) : null;
            $baseRevision = $body['base_revision'] ?? null;
            if ($baseRevision !== $currentRevision) {
                $pdo->rollBack();
                reply(['ok'=>false,'conflict'=>true,'error'=>'Ein anderes Gerät hat inzwischen Änderungen gespeichert. Bitte aktualisieren.'],409);
            }
            if ($current && hash_equals($currentRevision, hash('sha256', $json))) {
                $pdo->commit();
                reply(['ok'=>true,'unchanged'=>true,'revision'=>$currentRevision]);
            }
            if ($current) {
                $oldState = json_decode($current['state_json'], true);
                // Gewoehnliche Kassen- und Statistikbearbeitung ohne Passwort.
                // Wiederherstellung von Backups bleibt weiterhin admin-geschuetzt.
                if (is_array($oldState) && suspiciousMassLoss($oldState, $body['state'])) {
                    $pdo->rollBack();
                    reply(['ok'=>false,'error'=>'Sicherheitsstopp: Mehrere Datenbereiche würden gleichzeitig vollständig geleert.'],409);
                }
                addBackup($pdo, $current['state_json'], 'auto');
            }
            $stmt = $pdo->prepare("INSERT INTO badminton_state (id,state_json) VALUES (1,:state) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json)");
            $stmt->execute([':state'=>$json]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        reply(['ok'=>true,'revision'=>hash('sha256',$json)]);
    }

    reply(['ok'=>false,'error'=>'Methode nicht erlaubt.'], 405);
} catch (Throwable $e) {
    error_log('Badminton API: '.$e->getMessage());
    reply(['ok'=>false,'error'=>'Datenbankfehler. Die Änderung wurde nicht gespeichert.'], 500);
}
