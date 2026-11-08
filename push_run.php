<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$tokenFile = dirname(__DIR__, 2) . '/badminton_cron_token.txt';
if (!is_file($tokenFile)) { http_response_code(503); exit('Nicht eingerichtet.'); }
$expected = trim((string)file_get_contents($tokenFile));
$provided = (string)($_GET['token'] ?? '');
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(404);
    exit('Nicht gefunden.');
}
define('BADMINTON_AUTHORIZED_CRON', true);
require __DIR__ . '/push_cron.php';
