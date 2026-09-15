<?php

namespace Evrik\Platega\Api;

final class Protocol
{
    public static function uuid($value)
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value);
    }

    public static function minorUnits($value)
    {
        if (!is_int($value) && !is_float($value) && !is_string($value))
        {
            throw new \InvalidArgumentException('Invalid amount.');
        }
        $value = (string)$value;
        if (!preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/', $value, $matches))
        {
            throw new \InvalidArgumentException('Amount must have at most two decimal places.');
        }
        return (int)$matches[1] * 100 + (int)str_pad($matches[2] ?? '', 2, '0');
    }

    public static function authenticated($merchant, $secret, array $options)
    {
        return is_string($merchant) && is_string($secret)
            && !empty($options['merchant_id']) && !empty($options['secret'])
            && hash_equals(strtolower($options['merchant_id']), strtolower($merchant))
            && hash_equals($options['secret'], $secret);
    }

    public static function redirectUrl($value)
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL))
        {
            throw new \UnexpectedValueException('Invalid payment URL.');
        }
        $parts = parse_url($value);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']))
        {
            throw new \UnexpectedValueException('Payment URL must use HTTPS.');
        }
        return $value;
    }

    public static function validateTransaction(array $remote, array $invoice)
    {
        if (!isset($remote['id'], $remote['payload'], $remote['status'], $remote['paymentDetails'])
            || !is_array($remote['paymentDetails'])
            || !is_string($remote['id']) || !is_string($remote['payload'])
            || strtolower($remote['id']) !== $invoice['transaction_id']
            || !hash_equals($invoice['request_key'], $remote['payload'])
            || !in_array($remote['status'], ['PENDING', 'CONFIRMED', 'CANCELED', 'CHARGEBACKED'], true)
            || ($remote['paymentDetails']['currency'] ?? '') !== $invoice['currency']
            || self::minorUnits($remote['paymentDetails']['amount'] ?? null) !== (int)$invoice['amount_minor'])
        {
            throw new \UnexpectedValueException('Transaction does not match the purchase.');
        }
    }

    // A refund is terminal. A late confirmation must never restore the purchase.
    public static function action($previous, $current)
    {
        if ($previous === 'CHARGEBACKED' || $previous === $current)
        {
            return 'none';
        }
        if ($current === 'CONFIRMED' && $previous !== 'CONFIRMED')
        {
            return 'receive';
        }
        if ($current === 'CHARGEBACKED' && $previous === 'CONFIRMED')
        {
            return 'reverse';
        }
        return 'none';
    }
}
