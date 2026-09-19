<?php

require __DIR__ . '/library/XenForo/Autoloader.php';
XenForo_Autoloader::getInstance()->setupAutoloader(__DIR__ . '/library');
XenForo_Application::initialize(__DIR__ . '/library', __DIR__);
(new XenForo_Dependencies_Public())->preLoadData();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST')
{
    http_response_code(405);
    header('Allow: POST');
    echo '{"error":"POST required"}';
    exit;
}
try
{
    $body = file_get_contents('php://input', false, null, 0, 65537);
    $data = strlen($body) <= 65536 ? json_decode($body, true, 32) : null;
    if (!is_array($data)) { throw new RuntimeException('Invalid JSON.'); }
    (new Evrik_Platega_Service())->callback($data, $_SERVER['HTTP_X_MERCHANTID'] ?? '', $_SERVER['HTTP_X_SECRET'] ?? '');
    echo '{"status":"ok"}';
}
catch (Throwable $e)
{
    http_response_code($e->getCode() === 503 ? 503 : 400);
    echo '{"error":"Payment verification failed"}';
}
