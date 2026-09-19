<?php

// Loads class definitions only. Does not install the add-on or connect to the database.
if (PHP_SAPI !== 'cli' || empty($argv[1]))
{
    fwrite(STDERR, "Usage: php scripts/check-xenforo.php /path/to/xenforo\n");
    exit(1);
}
$root = realpath($argv[1]);
if (!$root)
{
    fwrite(STDERR, "XenForo source directory not found.\n");
    exit(1);
}
if (is_file($root . '/src/XF.php'))
{
    require $root . '/src/XF.php';
    XF::start($root);
    $version = XF::$version;
    $package = 'XenForo 2.x';
    $requirements = [
        'XF\\Payment\\AbstractProvider' => ['validatePurchaseRequest', 'validatePurchasableHandler',
            'validatePaymentProfile', 'validatePurchaser', 'validatePurchasableData', 'completeTransaction', 'log'],
        'XF\\Payment\\CallbackState' => ['getPurchaseRequest', 'getPaymentProfile', 'getPurchasableHandler'],
        'XF\\Http\\Request' => ['getInputRaw', 'getServer', 'isPost'],
        'XF\\Admin\\Controller\\AbstractController' => ['assertAdminPermission', 'assertPostOnly', 'filterPage'],
        'XF\\Db\\AbstractAdapter' => ['fetchRow', 'fetchOne', 'insert', 'update', 'beginTransaction', 'commit', 'rollback']
    ];
}
elseif (is_file($root . '/library/XenForo/Autoloader.php'))
{
    require $root . '/library/XenForo/Autoloader.php';
    XenForo_Autoloader::getInstance()->setupAutoloader($root . '/library');
    $version = XenForo_Application::$version;
    $package = 'XenForo 1.5 (legacy ZIP)';
    $requirements = [
        'XenForo_Model_UserUpgrade' => ['getUserUpgradesForPurchaseList', 'upgradeUser',
            'downgradeUserUpgrade', 'getActiveUserUpgradeRecord', 'getActiveUserUpgradeRecordById'],
        'XenForo_ControllerPublic_Account' => ['_assertPostOnly', '_getWrapper'],
        'XenForo_Db' => ['beginTransaction', 'commit', 'rollbackAll']
    ];
    if (XenForo_Application::$versionId < 1050070 || !extension_loaded('curl'))
    {
        fwrite(STDERR, "The legacy package requires XenForo 1.5 and cURL.\n");
        exit(2);
    }
}
else
{
    fwrite(STDERR, "Unrecognized XenForo layout; no compatible package identified.\n");
    exit(2);
}
if (PHP_VERSION_ID < 70400)
{
    fwrite(STDERR, "Platega requires PHP 7.4 or later.\n");
    exit(2);
}
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
echo 'XenForo ' . $version . '; package: ' . $package . '; PHP ' . PHP_VERSION . "\n";
if ($missing)
{
    echo "Missing framework methods:\n - " . implode("\n - ", $missing) . "\n";
    exit(2);
}
echo "Required class methods are present.\n";
echo "This is an API preflight, not proof of payment compatibility. Run the integration tests next.\n";
