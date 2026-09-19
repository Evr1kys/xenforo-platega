<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (empty($argv[1]))
{
    fwrite(STDERR, "Usage: php platega_reconcile.php REQUEST_KEY [TRANSACTION_ID]\n");
    exit(1);
}
require __DIR__ . '/library/XenForo/Autoloader.php';
XenForo_Autoloader::getInstance()->setupAutoloader(__DIR__ . '/library');
XenForo_Application::initialize(__DIR__ . '/library', __DIR__);
(new XenForo_Dependencies_Public())->preLoadData();
try { echo (new Evrik_Platega_Service())->reconcile($argv[1], $argv[2] ?? null) . "\n"; }
catch (Throwable $e) { fwrite(STDERR, "Payment could not be reconciled. Check the invoice and merchant dashboard.\n"); exit(1); }
