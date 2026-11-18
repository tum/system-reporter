<?php

// Router für "php -S": nimmt eine Meldung an und legt Header + Body ab,
// damit ServerScriptTest prüfen kann, was das Skript gesendet hat.
$dir = getenv('SR_CAPTURE_DIR') ?: sys_get_temp_dir();
file_put_contents($dir.'/request.json', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'body' => file_get_contents('php://input'),
]));

header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer itps_good') {
    http_response_code(401);
    echo '{"error":"unauthorized"}';

    return true;
}
http_response_code(202);
echo '{"status":"accepted","system_id":1,"stored":true}';

return true;
