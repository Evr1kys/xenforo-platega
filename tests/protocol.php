<?php

require __DIR__ . '/../upload/src/addons/Evrik/Platega/Api/Protocol.php';
require __DIR__ . '/../upload/src/addons/Evrik/Platega/Api/Client.php';

use Evrik\Platega\Api\Protocol;
use Evrik\Platega\Api\Client;

$count = 0;
function check($condition, $name)
{
    global $count;
    if (!$condition) { throw new RuntimeException($name); }
    $count++;
}
function rejects(callable $callback, $name)
{
    try { $callback(); } catch (Throwable $e) { check(true, $name); return; }
    throw new RuntimeException('Expected rejection: ' . $name);
}

foreach (['0' => 0, '0.01' => 1, '10.1' => 1010, '500.00' => 50000, '9999999999.99' => 999999999999] as $amount => $expected)
{
    check(Protocol::minorUnits($amount) === $expected, 'decimal conversion');
}
foreach ([null, true, [], '-1', '1e3', '01', '1.001', 'INF', 'NaN', '10000000000', ' 1'] as $value)
{
    rejects(function () use ($value) { Protocol::minorUnits($value); }, 'invalid amount');
}
$id = '12345678-1234-1234-1234-123456789abc';
check((bool)Protocol::uuid($id), 'uuid');
check(!Protocol::uuid('../abc'), 'path traversal');
$options = ['merchant_id' => $id, 'secret' => 'test-secret'];
check(Protocol::authenticated(strtoupper($id), 'test-secret', $options), 'valid credentials');
check(!Protocol::authenticated($id, 'wrong', $options), 'wrong secret');
check(!Protocol::authenticated('', '', []), 'empty credentials');
check(!Protocol::authenticated($id, ['test-secret'], $options), 'array credential');
foreach (['http://pay.platega.io', '//pay.platega.io', 'javascript:alert(1)', 'https://a:b@pay.platega.io/', 'https://pay.platega.io:444/'] as $url)
{
    rejects(function () use ($url) { Protocol::redirectUrl($url); }, 'unsafe URL');
}
check(Protocol::redirectUrl('https://pay.platega.io/?id=123') === 'https://pay.platega.io/?id=123', 'HTTPS payment URL');
$invoice = ['transaction_id' => $id, 'request_key' => str_repeat('a', 32), 'amount_minor' => 10050, 'currency' => 'RUB'];
$remote = ['id' => $id, 'payload' => $invoice['request_key'], 'status' => 'CONFIRMED', 'paymentDetails' => ['amount' => 100.50, 'currency' => 'RUB']];
Protocol::validateTransaction($remote, $invoice);
check(true, 'matching transaction');
foreach (['id' => '22345678-1234-1234-1234-123456789abc', 'payload' => str_repeat('b', 32), 'status' => 'PAID', 'paymentDetails' => ['amount' => 100.49, 'currency' => 'RUB']] as $key => $value)
{
    $bad = $remote; $bad[$key] = $value;
    rejects(function () use ($bad, $invoice) { Protocol::validateTransaction($bad, $invoice); }, 'mismatch ' . $key);
}
$bad = $remote; $bad['paymentDetails']['currency'] = 'USD';
rejects(function () use ($bad, $invoice) { Protocol::validateTransaction($bad, $invoice); }, 'currency mismatch');
foreach ([['PENDING','CONFIRMED','receive'], ['CONFIRMED','CONFIRMED','none'], ['CONFIRMED','CHARGEBACKED','reverse'], ['CHARGEBACKED','CONFIRMED','none'], ['PENDING','CHARGEBACKED','none'], ['CANCELED','CONFIRMED','receive'], ['UNCERTAIN','CONFIRMED','receive']] as $case)
{
    check(Protocol::action($case[0], $case[1]) === $case[2], implode('/', $case));
}

class HttpDouble
{
    public $calls = [];
    public $code = 200;
    public $body = '{"transactionId":"12345678-1234-1234-1234-123456789abc"}';
    public $error = false;
    public function request($method, $url, $options)
    {
        $this->calls[] = [$method, $url, $options];
        if ($this->error) { throw new RuntimeException('test-secret sensitive body'); }
        return new ResponseDouble($this->code, $this->body);
    }
}
class ResponseDouble
{
    private $code;
    private $body;
    public function __construct($code, $body) { $this->code = $code; $this->body = $body; }
    public function getStatusCode() { return $this->code; }
    public function getBody() { return $this->body; }
}
$http = new HttpDouble(); $client = new Client($http, $options);
$client->create(['payload' => 'order'], 0);
check($http->calls[0][1] === Client::BASE_URL . '/v2/transaction/process', 'automatic method endpoint');
check(!isset($http->calls[0][2]['json']['paymentMethod']), 'automatic omits method');
$client->create(['payload' => 'order'], 2);
check($http->calls[1][2]['json']['paymentMethod'] === 2, 'fixed payment method');
check($http->calls[1][1] === Client::BASE_URL . '/transaction/process', 'fixed method endpoint');
check($http->calls[1][2]['allow_redirects'] === false, 'credential redirect protection');
$client->transaction($id);
check($http->calls[2][0] === 'GET', 'status method');
$http->code = 401;
rejects(function () use ($client, $id) { $client->transaction($id); }, 'HTTP authentication failure');
$http->code = 200; $http->body = 'invalid json';
rejects(function () use ($client, $id) { $client->transaction($id); }, 'malformed response');
$http->body = str_repeat('x', 65537);
rejects(function () use ($client, $id) { $client->transaction($id); }, 'oversize response');
$http->error = true; $before = count($http->calls);
try { $client->create([], 0); } catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 'test-secret') === false && !$e->getPrevious(), 'secret redaction');
}
check(count($http->calls) === $before + 1, 'ambiguous POST is not retried');
echo "OK: $count assertions\n";
