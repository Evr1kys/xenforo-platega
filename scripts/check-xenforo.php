<?php

// Loads class definitions only. Does not install the add-on or connect to the database.
if (PHP_SAPI !== 'cli' || empty($argv[1]))
{
    fwrite(STDERR, "Usage: php scripts/check-xenforo.php /path/to/xenforo\n");
    exit(1);
}
$root = realpath($argv[1]);
if (!$root || !is_file($root . '/src/XF.php'))
{
    fwrite(STDERR, "XenForo 2.x source directory not found.\n");
    exit(1);
}
require $root . '/src/XF.php';
XF::start($root);
$requirements = [
    'XF\\Payment\\AbstractProvider' => ['validatePurchaseRequest', 'validatePurchasableHandler',
        'validatePaymentProfile', 'validatePurchaser', 'validatePurchasableData', 'completeTransaction', 'log'],
    'XF\\Payment\\CallbackState' => ['getPurchaseRequest', 'getPaymentProfile', 'getPurchasableHandler'],
    'XF\\Http\\Request' => ['getInputRaw', 'getServer', 'isPost'],
    'XF\\Admin\\Controller\\AbstractController' => ['assertAdminPermission', 'assertPostOnly', 'filterPage'],
    'XF\\Db\\AbstractAdapter' => ['fetchRow', 'fetchOne', 'insert', 'update', 'beginTransaction', 'commit', 'rollback']
];
$missing = [];
foreach ($requirements as $class => $methods)
{
    foreach ($methods as $method)
    {
        if (!method_exists($class, $method))
        {
            $missing[] = $class . '::' . $method;
        }
    }
}
echo 'XenForo ' . XF::$version . '; PHP ' . PHP_VERSION . "\n";
if ($missing)
{
    echo "Missing framework methods:\n - " . implode("\n - ", $missing) . "\n";
    exit(2);
}
echo "Required class methods are present.\n";
echo "This is an API preflight, not proof of payment compatibility. Run the integration tests next.\n";
