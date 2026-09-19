<?php

if (PHP_SAPI !== 'cli' || ($argv[2] ?? '') !== '--disposable-database')
{
    fwrite(STDERR, "Usage: php tests/xenforo1.php /path/to/forum --disposable-database\n");
    exit(1);
}
$root = realpath($argv[1]);
require $root . '/library/XenForo/Autoloader.php';
XenForo_Autoloader::getInstance()->setupAutoloader($root . '/library');
XenForo_Application::initialize($root . '/library', $root);
(new XenForo_Dependencies_Public())->preLoadData();
$count = 0;
function check($ok, $message)
{
    global $count;
    if (!$ok) { throw new RuntimeException($message); }
    $count++;
}
function rejects($callback, $message)
{
    try { $callback(); }
    catch (Throwable $e) { check(true, $message); return; }
    throw new RuntimeException($message);
}
class LegacyClientFixture
{
    public $calls = 0;
    public $data;
    public $remote;
    public $fail = false;
    public $id;
    public function request($method, $path, array $data = null)
    {
        $this->calls++;
        if ($this->fail) { throw new RuntimeException('Network failure'); }
        if ($method === 'POST')
        {
            $this->data = $data;
            $this->id = '12345678-1234-1234-1234-' . bin2hex(random_bytes(6));
            $this->remote = ['id' => $this->id, 'payload' => $data['payload'],
                'paymentDetails' => $data['paymentDetails'], 'status' => 'PENDING'];
            return ['transactionId' => $this->id, 'url' => 'https://pay.platega.io/?id=' . $this->id, 'status' => 'PENDING'];
        }
        return $this->remote;
    }
}
$db = XenForo_Application::getDb();
if (($argv[3] ?? '') === '--worker')
{
    $row = $db->fetchRow('SELECT * FROM xf_evrik_platega_legacy WHERE request_key = ?', $argv[4]);
    $client = new LegacyClientFixture();
    $client->remote = ['id' => $row['transaction_id'], 'payload' => $row['request_key'], 'status' => 'CONFIRMED',
        'paymentDetails' => ['amount' => $row['amount_minor'] / 100, 'currency' => $row['currency']]];
    $options = ['merchant_id' => '12345678-1234-1234-1234-123456789abc', 'secret' => 'fixture-secret'];
    (new Evrik_Platega_Service($client, $options))->reconcile($row['request_key']);
    echo "worker OK\n";
    exit(0);
}
$writer = XenForo_DataWriter::create('XenForo_DataWriter_User');
$writer->bulkSet(['username' => 'Platega-' . bin2hex(random_bytes(4)),
    'email' => bin2hex(random_bytes(4)) . '@example.test', 'user_group_id' => 2, 'user_state' => 'valid']);
$writer->setPassword(bin2hex(random_bytes(16)));
$writer->save();
$user = XenForo_Model::create('XenForo_Model_User')->getUserById($writer->get('user_id'));
$writer = XenForo_DataWriter::create('XenForo_DataWriter_UserUpgrade');
$writer->bulkSet(['title' => 'Platega fixture', 'cost_amount' => 100.50, 'cost_currency' => 'rub',
    'length_amount' => 1, 'length_unit' => 'month', 'recurring' => 0, 'extra_group_ids' => '3']);
$writer->save();
$upgradeId = $writer->get('user_upgrade_id');
check($writer instanceof Evrik_Platega_UserUpgrade, 'RUB data writer extension loaded');
$options = ['merchant_id' => '12345678-1234-1234-1234-123456789abc', 'secret' => 'fixture-secret'];
$client = new LegacyClientFixture();
$service = new Evrik_Platega_Service($client, $options);
$url = $service->initiate($user, $upgradeId, 'https://forum.example.test/account/upgrades');
check(strpos($url, 'https://pay.platega.io/') === 0, 'Checkout URL');
$key = $client->data['payload'];
$id = $client->id;
check($client->data['paymentDetails'] === ['amount' => 100.5, 'currency' => 'RUB'], 'Amount and currency');
check($service->initiate($user, $upgradeId, 'https://forum.example.test/account/upgrades') === $url && $client->calls === 1, 'Checkout deduplicated');
rejects(function () use ($service, $key, $id) { $service->callback(['payload' => $key, 'id' => $id], 'wrong', 'wrong'); }, 'Invalid auth rejected');
check($client->calls === 1, 'Invalid auth does not call gateway');
$db->update('xf_evrik_platega_legacy', ['status' => 'CREATING'], $db->quoteInto('request_key = ?', $key));
$calls = $client->calls;
rejects(function () use ($service, $key, $id) { $service->reconcile($key, $id); }, 'Callback waits for in-flight creation');
check($client->calls === $calls, 'In-flight callback does not query gateway');
$db->update('xf_evrik_platega_legacy', ['status' => 'PENDING'], $db->quoteInto('request_key = ?', $key));
$callback = ['id' => $id, 'payload' => $key];
$run = function () use ($service, $callback, $options) { return $service->callback($callback, $options['merchant_id'], $options['secret']); };
check($run() === 'PENDING', 'Pending accepted without grant');
$model = XenForo_Model::create('XenForo_Model_UserUpgrade');
check(!$model->getActiveUserUpgradeRecord($user['user_id'], $upgradeId), 'Pending has no upgrade');
$client->remote['paymentDetails']['amount'] = 1;
rejects($run, 'Amount mismatch rejected');
$client->remote['paymentDetails']['amount'] = 100.5;
$client->remote['status'] = 'CONFIRMED';
$workers = [];
for ($i = 0; $i < 2; $i++)
{
    $process = proc_open([PHP_BINARY, '-d', 'error_reporting=22527', __FILE__, $root, '--disposable-database', '--worker', $key],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
}
foreach ($workers as list($process, $pipes))
{
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0 && strpos($out, 'worker OK') !== false, 'Concurrent callback: ' . $out . $err);
}
check($run() === 'CONFIRMED', 'Confirmation accepted');
$record = $model->getActiveUserUpgradeRecord($user['user_id'], $upgradeId);
check((bool)$record, 'Native upgrade granted');
check((bool)$db->fetchOne('SELECT user_id FROM xf_user_group_change WHERE user_id = ? AND change_key = ?',
    [$user['user_id'], 'userUpgrade-' . $upgradeId]), 'Native group change granted');
check($run() === 'CONFIRMED' && $model->getActiveUserUpgradeRecord($user['user_id'], $upgradeId)['end_date'] === $record['end_date'], 'Repeat does not extend');
$client->remote['status'] = 'CANCELED';
check($run() === 'CONFIRMED', 'Late cancellation does not revoke');
$client->remote['status'] = 'CHARGEBACKED';
check($run() === 'CHARGEBACKED', 'Refund accepted');
check(!$model->getActiveUserUpgradeRecord($user['user_id'], $upgradeId), 'Refund revokes native upgrade');
check(!$db->fetchOne('SELECT user_id FROM xf_user_group_change WHERE user_id = ? AND change_key = ?',
    [$user['user_id'], 'userUpgrade-' . $upgradeId]), 'Refund revokes group');
$client->remote['status'] = 'CONFIRMED';
check($run() === 'CHARGEBACKED' && !$model->getActiveUserUpgradeRecord($user['user_id'], $upgradeId), 'Refund terminal');
$client->fail = true;
rejects(function () use ($service, $user, $upgradeId) { $service->initiate($user, $upgradeId, 'https://forum.example.test/account/upgrades'); }, 'Network error handled');
$calls = $client->calls;
rejects(function () use ($service, $user, $upgradeId) { $service->initiate($user, $upgradeId, 'https://forum.example.test/account/upgrades'); }, 'Unknown result blocks retry');
check($client->calls === $calls, 'No second POST after unknown result');
$unknown = $db->fetchRow("SELECT * FROM xf_evrik_platega_legacy WHERE user_id = ? AND status = 'UNCERTAIN'", $user['user_id']);
$client->fail = false;
$client->remote = ['id' => $id, 'payload' => $unknown['request_key'], 'status' => 'CONFIRMED',
    'paymentDetails' => ['amount' => 100.5, 'currency' => 'RUB']];
rejects(function () use ($service, $unknown, $id) { $service->reconcile($unknown['request_key'], $id); }, 'Duplicate transaction rolls back native grant');
check(!$model->getActiveUserUpgradeRecord($user['user_id'], $upgradeId), 'Failed binding leaves no upgrade');
check(!$db->fetchOne('SELECT user_id FROM xf_user_group_change WHERE user_id = ? AND change_key = ?',
    [$user['user_id'], 'userUpgrade-' . $upgradeId]), 'Failed binding leaves no group');
check(!XenForo_Db::inTransaction($db), 'Rollback closes nested transactions');
$template = $db->fetchOne("SELECT template FROM xf_template WHERE title = 'evrik_platega_legacy' AND style_id = 0");
$compiler = new XenForo_Template_Compiler($template);
check((bool)$compiler->compile('evrik_platega_legacy'), 'Native template compiles');
check(strpos($template, '_xfToken') !== false, 'Checkout template includes CSRF token');
check(XenForo_Application::resolveDynamicClass('XenForo_ControllerPublic_Account', 'controller') === 'Evrik_Platega_Account', 'Account controller registered');
$engineTemplates = simplexml_load_file($root . '/install/data/templates.xml');
foreach ($engineTemplates->template as $engineTemplate)
{
    if ((string)$engineTemplate['title'] !== 'account_upgrades') { continue; }
    $modified = XenForo_Model::create('XenForo_Model_TemplateModification')->applyModificationsToTemplate(
        'account_upgrades', (string)$engineTemplate, $modStatus);
    check(strpos($modified, "!= 'RUB'") !== false, 'RUB uses Platega instead of PayPal');
    check((bool)(new XenForo_Template_Compiler($modified))->compile('account_upgrades'), 'Modified native template compiles');
}
$db->update('xf_addon', ['active' => 0], "addon_id = 'EvrikPlategaLegacy'");
try
{
    rejects(function () use ($client, $options) { new Evrik_Platega_Service($client, $options); }, 'Disabled add-on cannot process payments');
}
finally { $db->update('xf_addon', ['active' => 1], "addon_id = 'EvrikPlategaLegacy'"); }
echo 'OK: XenForo ' . XenForo_Application::$version . ", $count legacy integration assertions\n";
