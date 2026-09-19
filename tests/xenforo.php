<?php

// Run only on a disposable, freshly installed forum. Creates test purchases.
if (($argv[2] ?? '') !== '--disposable-database')
{
    fwrite(STDERR, "Usage: php tests/xenforo.php /path/to/test/forum --disposable-database\n");
    exit(1);
}
$root = realpath($argv[1]);
require $root . '/src/XF.php';
XF::start($root);
$app = XF::setupApp('XF\Pub\App');

$count = 0;
function verify($condition, $message)
{
    global $count;
    if (!$condition) { throw new RuntimeException($message); }
    $count++;
}
class TestRequest extends \XF\Http\Request
{
    public $body;
    public $headers;
    public $post = true;
    public function __construct(array $data, $secret = 'fixture-secret')
    {
        $this->body = json_encode($data);
        $this->headers = ['HTTP_X_SECRET' => $secret, 'HTTP_X_MERCHANTID' => '12345678-1234-1234-1234-123456789abc'];
    }
    public function getInputRaw($fallback = '') { return $this->body; }
    public function getServer($key, $fallback = false) { return $this->headers[$key] ?? $fallback; }
    public function isPost() { return $this->post; }
}
class TestClient
{
    public $remote;
    public $created;
    public $fail = false;
    public $calls = 0;
    public $lastData;
    public function transaction($id)
    {
        $this->calls++;
        if ($this->fail) { throw new RuntimeException('fixture failure'); }
        return $this->remote;
    }
    public function create($data, $method)
    {
        $this->calls++;
        $this->lastData = $data;
        if ($this->fail) { throw new RuntimeException('fixture failure'); }
        return $this->created;
    }
}
class TestProvider extends \Evrik\Platega\Payment\Platega
{
    public $testClient;
    protected function client(\XF\Entity\PaymentProfile $profile) { return $this->testClient; }
}
class TestController extends \XF\Mvc\Controller {}

$provider = new TestProvider('evrikPlatega');
$provider->testClient = $client = new TestClient();
if (($argv[3] ?? '') === '--worker')
{
    $row = XF::db()->fetchRow('SELECT * FROM xf_evrik_platega_invoice WHERE transaction_id = ?', $argv[4]);
    $data = ['id' => $row['transaction_id'], 'payload' => $row['request_key'], 'amount' => $row['amount_minor'] / 100, 'currency' => 'RUB', 'status' => 'CONFIRMED'];
    $client->remote = ['id' => $data['id'], 'payload' => $data['payload'], 'status' => 'CONFIRMED', 'paymentDetails' => ['amount' => $data['amount'], 'currency' => 'RUB']];
    runCallback($provider, $data);
    echo "worker OK\n";
    exit(0);
}
$registered = XF::em()->find('XF:PaymentProvider', 'evrikPlatega');
verify($registered && $registered->handler instanceof \Evrik\Platega\Payment\Platega, 'Provider registration');
$options = ['merchant_id' => '12345678-1234-1234-1234-123456789abc', 'secret' => 'fixture-secret', 'payment_method' => 0];
$errors = [];
verify($provider->verifyConfig($options, $errors), 'Profile validation');
$profile = XF::em()->create('XF:PaymentProfile');
$profile->bulkSet(['provider_id' => 'evrikPlatega', 'title' => 'Platega integration fixture', 'options' => $options]);
$profile->save();
$html = $provider->renderConfig($profile);
verify(strpos($html, 'options[merchant_id]') !== false, 'Admin template compiles and renders');
verify(strpos($html, '_xfProvider=evrikPlatega') !== false, 'Callback URL present');
verify(!$provider->supportsRecurring($profile, 'month', 1), 'Recurring disabled');
verify(!$provider->verifyCurrency($profile, 'USD'), 'Non-RUB rejected');

$upgrade = XF::em()->create('XF:UserUpgrade');
$upgrade->bulkSet(['title' => 'Platega test ' . bin2hex(random_bytes(3)), 'cost_amount' => 100.5, 'cost_currency' => 'RUB', 'length_amount' => 1, 'length_unit' => 'month', 'payment_profile_ids' => [$profile->payment_profile_id]]);
$upgrade->save();
$user = XF::em()->find('XF:User', 1);
function purchaseFixture($profile, $upgrade, $user)
{
    $request = XF::em()->create('XF:PurchaseRequest');
    $request->bulkSet(['request_key' => bin2hex(random_bytes(16)), 'user_id' => $user->user_id, 'provider_id' => 'evrikPlatega', 'payment_profile_id' => $profile->payment_profile_id, 'purchasable_type_id' => 'user_upgrade', 'cost_amount' => 100.5, 'cost_currency' => 'RUB', 'extra_data' => ['user_upgrade_id' => $upgrade->user_upgrade_id]]);
    $request->save();
    return $request;
}
function runCallback($provider, $data, $secret = 'fixture-secret')
{
    XF::em()->clearEntityCache();
    $state = $provider->setupCallback(new TestRequest($data, $secret));
    $state->legacy = false;
    foreach (['validateCallback', 'validateTransaction', 'validatePurchaseRequest', 'validatePurchasableHandler', 'validatePaymentProfile', 'validatePurchaser', 'validatePurchasableData', 'validateCost'] as $method)
    {
        if (!$provider->$method($state)) { return $state; }
    }
    $provider->getPaymentResult($state);
    $provider->completeTransaction($state);
    $provider->log($state);
    return $state;
}
$request = purchaseFixture($profile, $upgrade, $user);
$purchase = new \XF\Purchasable\Purchase();
$purchase->paymentProfile = $profile;
$purchase->purchaser = $user;
$purchase->title = 'Test purchase';
$purchase->returnUrl = 'https://forum.example.com/account/upgrades';
$purchase->cancelUrl = 'https://forum.example.com/account/upgrades';
$id = '12345678-1234-1234-1234-' . bin2hex(random_bytes(6));
$client->created = ['transactionId' => $id, 'url' => 'https://pay.platega.io/?id=' . $id];
$app->extension()->extendClass('TestController');
$controller = new TestController($app, $app->request());
$reply = $provider->initiatePayment($controller, $request, $purchase);
verify($reply instanceof \XF\Mvc\Reply\Redirect, 'Checkout redirects');
verify($client->lastData['paymentDetails']['amount'] == 100.5, 'Checkout amount');
verify($client->lastData['metadata']['userId'] === '1', 'Antifraud user ID');
$calls = $client->calls;
$provider->initiatePayment($controller, $request, $purchase);
verify($client->calls === $calls, 'Repeated checkout reuses invoice');
$data = ['id' => $id, 'amount' => 100.5, 'currency' => 'RUB', 'status' => 'CONFIRMED', 'payload' => $request->request_key];
$client->remote = ['id' => $id, 'payload' => $request->request_key, 'status' => 'CONFIRMED', 'paymentDetails' => ['amount' => 100.5, 'currency' => 'RUB']];
$state = runCallback($provider, $data, 'wrong');
verify($state->httpCode === 403 && $client->calls === $calls, 'Unauthenticated callback rejected before API');
$bad = $data; $bad['amount'] = 1;
verify(runCallback($provider, $bad)->httpCode === 403, 'Underpayment rejected');
$client->fail = true;
verify(runCallback($provider, $data)->httpCode === 503, 'Temporary API error requests retry');
$client->fail = false;
$state = runCallback($provider, $data);
verify($state->paymentResult === \XF\Payment\CallbackState::PAYMENT_RECEIVED, 'Payment applied');
$db = XF::db();
$active = $db->fetchRow('SELECT * FROM xf_user_upgrade_active WHERE user_upgrade_id = ? AND user_id = 1', $upgrade->user_upgrade_id);
verify((bool)$active, 'Native user upgrade granted');
$state = runCallback($provider, $data);
$again = $db->fetchRow('SELECT * FROM xf_user_upgrade_active WHERE user_upgrade_id = ? AND user_id = 1', $upgrade->user_upgrade_id);
verify(!$state->paymentResult && $again['end_date'] === $active['end_date'], 'Duplicate does not extend upgrade');
$client->remote['status'] = 'CHARGEBACKED'; $data['status'] = 'CHARGEBACKED';
$state = runCallback($provider, $data);
verify($state->paymentResult === \XF\Payment\CallbackState::PAYMENT_REVERSED, 'Refund applied');
verify(!$db->fetchOne('SELECT user_upgrade_record_id FROM xf_user_upgrade_active WHERE user_upgrade_id = ? AND user_id = 1', $upgrade->user_upgrade_id), 'Native upgrade revoked');
$client->remote['status'] = 'CONFIRMED'; $data['status'] = 'CONFIRMED';
verify(!runCallback($provider, $data)->paymentResult, 'Late confirmation cannot restore refunded upgrade');

$uncertain = purchaseFixture($profile, $upgrade, $user);
$client->fail = true;
verify($provider->initiatePayment($controller, $uncertain, $purchase) instanceof \XF\Mvc\Reply\Error, 'Ambiguous creation returns error');
$calls = $client->calls;
$provider->initiatePayment($controller, $uncertain, $purchase);
verify($client->calls === $calls, 'Ambiguous creation not retried');
$client->fail = false;
$id2 = '12345678-1234-1234-1234-' . bin2hex(random_bytes(6));
$data['id'] = $id2; $data['payload'] = $uncertain->request_key;
$client->remote['id'] = $id2; $client->remote['payload'] = $uncertain->request_key;
verify(runCallback($provider, $data)->paymentResult === \XF\Payment\CallbackState::PAYMENT_RECEIVED, 'Authenticated callback recovers uncertain transaction');
$logs = $db->fetchAll('SELECT log_details FROM xf_payment_provider_log WHERE provider_id = ?', 'evrikPlatega');
verify(strpos(json_encode($logs), 'fixture-secret') === false, 'Secret absent from payment logs');
$parallel = purchaseFixture($profile, $upgrade, $user);
$id3 = '12345678-1234-1234-1234-' . bin2hex(random_bytes(6));
$client->created = ['transactionId' => $id3, 'redirect' => 'https://pay.platega.io/?id=' . $id3];
verify($provider->initiatePayment($controller, $parallel, $purchase) instanceof \XF\Mvc\Reply\Redirect, 'Concurrent fixture created');
$children = [];
// Hold the row so both workers reach it before either can finish processing.
$db->beginTransaction();
$db->fetchRow('SELECT * FROM xf_evrik_platega_invoice WHERE request_key = ? FOR UPDATE', $parallel->request_key);
for ($i = 0; $i < 2; $i++)
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, $root, '--disposable-database', '--worker', $id3], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $children[] = [$process, $pipes];
}
usleep(500000);
$db->commit();
foreach ($children as $child)
{
    $output = stream_get_contents($child[1][1]);
    $error = stream_get_contents($child[1][2]);
    fclose($child[1][1]); fclose($child[1][2]);
    verify(proc_close($child[0]) === 0 && strpos($output, 'worker OK') !== false, 'Concurrent callback: ' . $output . $error);
}
verify((int)$db->fetchOne('SELECT COUNT(*) FROM xf_payment_provider_log WHERE purchase_request_key = ? AND log_type = ?', [$parallel->request_key, 'payment']) === 1, 'Concurrent delivery grants exactly once');

class FailingPurchasable
{
    public function completePurchase($state)
    {
        XF::db()->update('xf_evrik_platega_invoice', ['status' => 'BROKEN'], 'request_key = ?', $state->requestKey);
        throw new RuntimeException('Simulated purchasable failure');
    }
}
$rollback = purchaseFixture($profile, $upgrade, $user);
$id4 = '12345678-1234-1234-1234-' . bin2hex(random_bytes(6));
$client->created = ['transactionId' => $id4, 'url' => 'https://pay.platega.io/?id=' . $id4];
$provider->initiatePayment($controller, $rollback, $purchase);
$data = ['id' => $id4, 'payload' => $rollback->request_key, 'amount' => 100.5, 'currency' => 'RUB', 'status' => 'CONFIRMED'];
$client->remote = ['id' => $id4, 'payload' => $rollback->request_key, 'status' => 'CONFIRMED', 'paymentDetails' => ['amount' => 100.5, 'currency' => 'RUB']];
$state = $provider->setupCallback(new TestRequest($data));
verify($provider->validateCallback($state), 'Rollback fixture authenticated');
$state->purchasableHandler = new FailingPurchasable();
$thrown = false;
try { $provider->completeTransaction($state); } catch (RuntimeException $e) { $thrown = true; }
verify($thrown, 'Purchasable failure propagates for HTTP 5xx');
verify($db->fetchOne('SELECT status FROM xf_evrik_platega_invoice WHERE request_key = ?', $rollback->request_key) === 'PENDING', 'Purchasable and ledger writes roll back together');
verify(runCallback($provider, $data)->paymentResult === \XF\Payment\CallbackState::PAYMENT_RECEIVED, 'Retry after rollback succeeds');
$manual = purchaseFixture($profile, $upgrade, $user);
$id5 = '12345678-1234-1234-1234-' . bin2hex(random_bytes(6));
$client->created = ['transactionId' => $id5, 'url' => 'https://pay.platega.io/?id=' . $id5];
$provider->initiatePayment($controller, $manual, $purchase);
$client->remote = ['id' => $id5, 'payload' => $manual->request_key, 'status' => 'PENDING', 'paymentDetails' => ['amount' => 100.5, 'currency' => 'RUB']];
verify($provider->reconcile($manual->request_key)->httpCode === 503, 'Manual pending payment is not applied');
$client->fail = true;
verify($provider->reconcile($manual->request_key)->httpCode === 503, 'Manual API outage preserves purchase');
$client->fail = false;
$client->remote['status'] = 'CONFIRMED';
$client->remote['paymentDetails']['amount'] = 1;
verify($provider->reconcile($manual->request_key)->httpCode === 403, 'Manual underpayment rejected');
$client->remote['paymentDetails']['amount'] = 100.5;
verify($provider->reconcile($manual->request_key, $id4)->httpCode === 403, 'Manual mismatched ID rejected');
XF::em()->clearEntityCache();
$state = $provider->reconcile($manual->request_key);
verify($state->paymentResult === \XF\Payment\CallbackState::PAYMENT_RECEIVED, 'Manual recovery grants purchase');
XF::em()->clearEntityCache();
verify(!$provider->reconcile($manual->request_key)->paymentResult, 'Manual recovery is idempotent');
$client->remote['status'] = 'CHARGEBACKED';
XF::em()->clearEntityCache();
verify($provider->reconcile($manual->request_key)->paymentResult === \XF\Payment\CallbackState::PAYMENT_REVERSED, 'Manual recovery handles refund');
$client->remote['status'] = 'CONFIRMED';
XF::em()->clearEntityCache();
verify(!$provider->reconcile($manual->request_key)->paymentResult, 'Manual recovery cannot restore refund');
verify($provider->reconcile(str_repeat('0',32))->httpCode === 404, 'Unknown manual purchase rejected');

$lost = purchaseFixture($profile, $upgrade, $user);
$client->fail = true;
$provider->initiatePayment($controller, $lost, $purchase);
$client->fail = false;
$id6 = '12345678-1234-1234-1234-' . bin2hex(random_bytes(6));
verify($provider->reconcile($lost->request_key)->httpCode === 400, 'Lost transaction requires ID');
$client->remote['id'] = $id6; $client->remote['payload'] = $lost->request_key;
XF::em()->clearEntityCache();
verify($provider->reconcile($lost->request_key, $id6)->paymentResult === \XF\Payment\CallbackState::PAYMENT_RECEIVED, 'Manual recovery binds lost transaction');
verify($db->fetchOne('SELECT transaction_id FROM xf_evrik_platega_invoice WHERE request_key = ?', $lost->request_key) === $id6, 'Recovered transaction saved');
$log = json_decode($db->fetchOne('SELECT log_details FROM xf_payment_provider_log WHERE purchase_request_key = ? ORDER BY provider_log_id DESC LIMIT 1', $lost->request_key), true);
verify($log['source'] === 'admin', 'Manual recovery is identified in payment log');

class TestAdminPayment extends \Evrik\Platega\Admin\Controller\Payment
{
    public $testProvider;
    protected function provider() { return $this->testProvider; }
    public function assertPermissionForTest() { $this->preDispatchController('Index', new \XF\Mvc\ParameterBag()); }
}
$app->extension()->extendClass('TestAdminPayment');
$adminRequest = new \XF\Http\Request($app->inputFilterer(), [], [], [], ['REQUEST_METHOD' => 'GET']);
$admin = new TestAdminPayment($app, $adminRequest);
$admin->testProvider = $provider;
$reply = $admin->actionIndex();
$html = $app->templater()->renderTemplate('admin:evrik_platega_payments', $reply->getParams());
verify(strpos($html, 'request_key') !== false && strpos($html, '_xfToken') !== false, 'Admin page renders CSRF-protected forms');
$listing = function (array $input) use ($app)
{
    $request = new \XF\Http\Request($app->inputFilterer(), $input, [], [], ['REQUEST_METHOD' => 'GET']);
    return (new TestAdminPayment($app, $request))->actionIndex()->getParams();
};
$filtered = $listing(['search' => $lost->request_key, 'profile' => $profile->payment_profile_id, 'status' => 'confirmed', 'page' => 999]);
verify($filtered['total'] === 1 && $filtered['invoices'][0]['transaction_id'] === $id6, 'Combined filters find the recovered purchase');
verify($filtered['page'] === 1 && $filtered['status'] === 'CONFIRMED', 'Filtered pagination clamps and status normalizes');
verify($listing(['search' => $lost->request_key, 'status' => 'PENDING'])['total'] === 0, 'Search cannot bypass status filter');
verify($listing(['search' => $id6])['total'] === 1, 'Search accepts transaction ID');
verify($listing(['search' => "' OR 1=1 --"])['total'] === 0, 'Search treats SQL text as a literal');
$beforeCalls = $client->calls;
$prefill = $listing(['request_key' => $lost->request_key, 'transaction_id' => $id6]);
verify($prefill['manualRequestKey'] === $lost->request_key && $prefill['manualTransactionId'] === $id6 && $client->calls === $beforeCalls, 'GET prefills without querying the payment API');
$invalid = $listing(['request_key' => '<script>', 'transaction_id' => 'bad', 'status' => 'unknown']);
verify($invalid['manualRequestKey'] === '' && $invalid['manualTransactionId'] === '' && $invalid['status'] === '', 'Invalid prefill and status ignored');
$indexes = array_column($db->fetchAll('SHOW INDEX FROM xf_evrik_platega_invoice'), 'Key_name');
verify(!array_diff(['created_date', 'status_created', 'profile_created'], $indexes), 'Invoice indexes installed');
$html = $app->templater()->renderTemplate('admin:evrik_platega_result', ['requestKey' => $state->requestKey, 'remoteStatus' => $state->remote['status'], 'message' => $state->logMessage, 'failed' => false]);
verify(strpos($html, $manual->request_key) !== false, 'Admin result renders');
$denied = false;
try { $admin->actionCheck(); } catch (\XF\Mvc\Reply\Exception $e) { $denied = true; }
verify($denied, 'Manual processing rejects GET');
$denied = false;
try { $admin->actionConnection(); } catch (\XF\Mvc\Reply\Exception $e) { $denied = true; }
verify($denied, 'Connection check rejects GET');
$denied = false;
XF::setVisitor(XF::em()->create('XF:User'));
try { $admin->assertPermissionForTest(); } catch (\XF\Mvc\Reply\Exception $e) { $denied = true; }
verify($denied, 'Payment permission required');
echo 'OK: XenForo ' . XF::$version . ", $count integration assertions\n";
