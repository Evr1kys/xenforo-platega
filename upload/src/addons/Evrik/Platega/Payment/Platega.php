<?php

namespace Evrik\Platega\Payment;

use Evrik\Platega\Api\Client;
use Evrik\Platega\Api\Protocol;
use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Http\Request;
use XF\Mvc\Controller;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;

class Platega extends \XF\Payment\AbstractProvider
{
    const TABLE = 'xf_evrik_platega_invoice';

    public function getTitle()
    {
        return 'Platega';
    }

    protected function client(PaymentProfile $profile)
    {
        return new Client(\XF::app()->http()->client(), $profile->options);
    }

    public function renderConfig(PaymentProfile $profile)
    {
        return \XF::app()->templater()->renderTemplate('admin:payment_profile_evrikPlatega', [
            'profile' => $profile,
            'callbackUrl' => $this->getCallbackUrl()
        ]);
    }

    public function verifyConfig(array &$options, &$errors = [])
    {
        $options['merchant_id'] = trim((string)($options['merchant_id'] ?? ''));
        $options['secret'] = trim((string)($options['secret'] ?? ''));
        $method = (string)($options['payment_method'] ?? '0');
        if (!Protocol::uuid($options['merchant_id']))
        {
            $errors[] = \XF::phrase('evrik_platega_invalid_merchant');
        }
        if (!$options['secret'] || preg_match('/[\x00-\x20\x7f]/', $options['secret']))
        {
            $errors[] = \XF::phrase('evrik_platega_invalid_secret');
        }
        if (!in_array($method, ['0', '2', '3', '11', '12', '13', '14'], true))
        {
            $errors[] = \XF::phrase('evrik_platega_invalid_method');
        }
        $options['payment_method'] = (int)$method;
        return !$errors;
    }

    public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING)
    {
        $result = self::ERR_NO_RECURRING;
        return false;
    }

    public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode)
    {
        return $currencyCode === 'RUB';
    }

    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        if (!\XF::config('enableLivePayments') || $purchase->recurring)
        {
            return $controller->error(\XF::phrase('evrik_platega_live_only'));
        }
        if (!$this->verifyCurrency($purchase->paymentProfile, $purchaseRequest->cost_currency))
        {
            return $controller->error(\XF::phrase('evrik_platega_rub_only'));
        }
        $db = \XF::db();
        $key = $purchaseRequest->request_key;
        // The lock is connection-scoped; PHP/MySQL also releases it on disconnect.
        $lock = 'platega:' . $key;
        if (!$db->fetchOne('SELECT GET_LOCK(?, 5)', $lock))
        {
            return $controller->error(\XF::phrase('evrik_platega_busy'));
        }
        try
        {
            $invoice = $db->fetchRow('SELECT * FROM ' . self::TABLE . ' WHERE request_key = ?', $key);
            if ($invoice)
            {
                if ($invoice['status'] === 'PENDING' && $invoice['redirect_url'])
                {
                    return $controller->redirect(Protocol::redirectUrl($invoice['redirect_url']));
                }
                return $controller->error(\XF::phrase('evrik_platega_existing_invoice'));
            }
            $minor = Protocol::minorUnits($purchaseRequest->cost_amount);
            if ($minor <= 0)
            {
                return $controller->error(\XF::phrase('evrik_platega_invalid_amount'));
            }
            $data = [
                'paymentDetails' => ['amount' => $minor / 100, 'currency' => 'RUB'],
                'description' => (string)$purchase->title,
                'return' => Protocol::redirectUrl($purchase->returnUrl),
                'failedUrl' => Protocol::redirectUrl($purchase->cancelUrl ?: $purchase->returnUrl),
                'payload' => $key,
                'orderId' => $key,
                'metadata' => [
                    'userId' => (string)$purchaseRequest->user_id,
                    'userName' => (string)$purchase->purchaser->username
                ]
            ];
            $db->insert(self::TABLE, [
                'request_key' => $key,
                'payment_profile_id' => $purchaseRequest->payment_profile_id,
                'amount_minor' => $minor,
                'currency' => 'RUB',
                'created_date' => time(),
                'updated_date' => time()
            ]);
            try
            {
                $response = $this->client($purchase->paymentProfile)->create(
                    $data, (int)($purchase->paymentProfile->options['payment_method'] ?? 0)
                );
                if (!Protocol::uuid($response['transactionId'] ?? null))
                {
                    throw new \UnexpectedValueException('Invalid transaction ID.');
                }
                $url = Protocol::redirectUrl($response['redirect'] ?? $response['url'] ?? null);
                $db->update(self::TABLE, [
                    'transaction_id' => strtolower($response['transactionId']),
                    'redirect_url' => $url,
                    'status' => 'PENDING',
                    'updated_date' => time()
                ], 'request_key = ?', $key);
                return $controller->redirect($url);
            }
            catch (\Throwable $e)
            {
                $db->update(self::TABLE, ['status' => 'UNCERTAIN', 'updated_date' => time()], 'request_key = ?', $key);
                // Neither HTTP exceptions nor raw response bodies are safe to log.
                \XF::logError('Platega: invoice creation needs review. Purchase request: ' . $key);
                return $controller->error(\XF::phrase('evrik_platega_create_failed'));
            }
        }
        catch (\InvalidArgumentException $e)
        {
            return $controller->error(\XF::phrase('evrik_platega_invalid_amount'));
        }
        catch (\UnexpectedValueException $e)
        {
            return $controller->error(\XF::phrase('evrik_platega_https_required'));
        }
        finally
        {
            $db->fetchOne('SELECT RELEASE_LOCK(?)', $lock);
        }
    }

    public function setupCallback(Request $request)
    {
        $state = new State();
        $state->isPost = $request->isPost();
        $state->merchantHeader = $request->getServer('HTTP_X_MERCHANTID', '');
        $state->secretHeader = $request->getServer('HTTP_X_SECRET', '');
        $raw = $request->getInputRaw();
        $data = strlen($raw) <= 16384 ? json_decode($raw, true, 16) : null;
        $state->event = is_array($data) ? $data : [];
        $id = $state->event['id'] ?? null;
        if (Protocol::uuid($id))
        {
            $state->transactionId = strtolower($id);
            $state->invoice = \XF::db()->fetchRow(
                'SELECT * FROM ' . self::TABLE . ' WHERE transaction_id = ?', $state->transactionId
            ) ?: [];
        }
        $payload = $state->event['payload'] ?? null;
        if ($state->invoice)
        {
            $state->requestKey = $state->invoice['request_key'];
        }
        elseif (is_string($payload) && preg_match('/\A[a-zA-Z0-9]{32}\z/', $payload))
        {
            // Allows an authenticated callback to recover an ambiguous POST result.
            $state->requestKey = $payload;
            $state->invoice = \XF::db()->fetchRow(
                'SELECT * FROM ' . self::TABLE . ' WHERE request_key = ?', $payload
            ) ?: [];
        }
        return $state;
    }

    protected function fail(CallbackState $state, $message, $code = 403)
    {
        $state->httpCode = $code;
        $state->logType = 'error';
        $state->logMessage = $message;
        return false;
    }

    public function validateCallback(CallbackState $state)
    {
        $profile = $state->getPaymentProfile();
        $purchase = $state->getPurchaseRequest();
        if (!$state->isPost || !$state->transactionId || !$profile || !$purchase
            || $purchase->provider_id !== $this->providerId
            || $profile->provider_id !== $this->providerId
            || !$state->invoice
            || (int)$state->invoice['payment_profile_id'] !== (int)$profile->payment_profile_id
            || !Protocol::authenticated($state->merchantHeader, $state->secretHeader, $profile->options))
        {
            return $this->fail($state, 'Invalid Platega callback.');
        }
        $state->secretHeader = '';
        $state->merchantHeader = '';
        if ($state->invoice['status'] === 'CREATING' && time() - (int)$state->invoice['created_date'] < 60)
        {
            return $this->fail($state, 'Invoice creation is still in progress.', 503);
        }
        if ($state->invoice['transaction_id'] && $state->invoice['transaction_id'] !== $state->transactionId)
        {
            return $this->fail($state, 'Transaction ID mismatch.');
        }
        try
        {
            $state->remote = $this->client($profile)->transaction($state->transactionId);
        }
        catch (\Throwable $e)
        {
            return $this->fail($state, 'Platega status lookup unavailable. Retry later.', 503);
        }
        try
        {
            $expected = $state->invoice;
            $expected['transaction_id'] = $state->transactionId;
            Protocol::validateTransaction($state->remote, $expected);
            if (($state->event['currency'] ?? null) !== $expected['currency']
                || Protocol::minorUnits($state->event['amount'] ?? null) !== (int)$expected['amount_minor']
                || (isset($state->event['payload']) && $state->event['payload'] !== $expected['request_key'])
                || !in_array($state->event['status'] ?? null, ['PENDING', 'CONFIRMED', 'CANCELED', 'CHARGEBACKED'], true))
            {
                return $this->fail($state, 'Callback does not match the purchase.');
            }
        }
        catch (\Throwable $e)
        {
            return $this->fail($state, 'Transaction does not match the purchase.');
        }
        if ($state->remote['status'] === 'PENDING')
        {
            return $this->fail($state, 'Transaction is not settled yet. Retry later.', 503);
        }
        return true;
    }

    public function validateTransaction(CallbackState $state)
    {
        // Error/info logs are not proof of payment; use the locked invoice ledger.
        return true;
    }

    public function validateCost(CallbackState $state)
    {
        $purchase = $state->getPurchaseRequest();
        try
        {
            if ($purchase->cost_currency === $state->invoice['currency']
                && Protocol::minorUnits($purchase->cost_amount) === (int)$state->invoice['amount_minor'])
            {
                return true;
            }
        }
        catch (\InvalidArgumentException $e) {}
        return $this->fail($state, 'Purchase cost changed. Manual review required.');
    }

    public function getPaymentResult(CallbackState $state)
    {
        // Determined inside the row lock in completeTransaction().
    }

    public function completeTransaction(CallbackState $state)
    {
        $db = \XF::db();
        $db->beginTransaction();
        try
        {
            $invoice = $db->fetchRow('SELECT * FROM ' . self::TABLE . ' WHERE request_key = ? FOR UPDATE', $state->requestKey);
            if (!$invoice || ($invoice['transaction_id'] && $invoice['transaction_id'] !== $state->transactionId))
            {
                throw new \RuntimeException('Invoice changed during callback.');
            }
            $current = $state->remote['status'];
            $action = Protocol::action($invoice['status'], $current);
            $state->logType = 'info';
            $state->logMessage = 'Platega: no purchase change.';
            if ($action !== 'none')
            {
                $state->paymentResult = $action === 'receive'
                    ? CallbackState::PAYMENT_RECEIVED : CallbackState::PAYMENT_REVERSED;
                parent::completeTransaction($state);
            }
            // Cancellation cannot overwrite an applied confirmation or refund.
            if ($invoice['status'] !== 'CHARGEBACKED'
                && !($invoice['status'] === 'CONFIRMED' && $current === 'CANCELED'))
            {
                $db->update(self::TABLE, [
                    'transaction_id' => $state->transactionId,
                    'status' => $current,
                    'updated_date' => time()
                ], 'request_key = ?', $state->requestKey);
            }
            $db->commit();
        }
        catch (\Throwable $e)
        {
            $db->rollback();
            // Let XenForo return an actual 5xx; its callback entrypoint does not
            // re-read state->httpCode after completeTransaction().
            throw new \RuntimeException('Platega purchase processing failed. Callback can be retried.');
        }
    }

    public function prepareLogData(CallbackState $state)
    {
        $state->logDetails = [
            'transaction_id' => $state->transactionId,
            'verified_status' => $state->remote['status'] ?? null
        ];
    }
}
