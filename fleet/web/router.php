<?php

declare(strict_types=1);

/*
 * Router of the built-in PHP server started by dolifleet serve. The token comes from the
 * environment (never from the command line, visible in /proc/<pid>/cmdline).
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

$token = (string) getenv('DOLIFLEET_SERVE_TOKEN');
$port = (int) getenv('DOLIFLEET_SERVE_PORT');
if ('' === $token || $port < 1) {
    http_response_code(500);
    echo "dolifleet serve: missing token or port\n";
    exit;
}

$dashboard = new Dolifleet\Web\Dashboard($token, $port, Dolifleet\Paths::inventory(), dirname(__DIR__).'/bin/dolifleet');
try {
    $dashboard->handle($_SERVER, $_GET, $_POST, $_COOKIE)->send();
} catch (Throwable $e) {
    error_log('dolifleet serve: '.$e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: '.htmlspecialchars($e->getMessage(), ENT_QUOTES)."\n";
}
