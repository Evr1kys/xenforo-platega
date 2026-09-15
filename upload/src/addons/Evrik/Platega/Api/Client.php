<?php

namespace Evrik\Platega\Api;

class Client
{
    const BASE_URL = 'https://app.platega.io';

    protected $http;
    protected $options;

    public function __construct($http, array $options)
    {
        $this->http = $http;
        $this->options = $options;
    }

    public function create(array $data, $method)
    {
        $path = '/v2/transaction/process';
        if ($method)
        {
            $path = '/transaction/process';
            $data['paymentMethod'] = $method;
        }
        return $this->request('POST', $path, $data);
    }

    public function transaction($id)
    {
        if (!Protocol::uuid($id))
        {
            throw new \InvalidArgumentException('Invalid transaction ID.');
        }
        return $this->request('GET', '/transaction/' . $id);
    }

    public function checkConnection()
    {
        $balances = $this->request('GET', '/balance/all');
        foreach ($balances as $balance)
        {
            if (!is_array($balance) || !isset($balance['currency'], $balance['amount'])
                || !is_string($balance['currency']) || !is_numeric($balance['amount']))
            {
                throw new \RuntimeException('Invalid Platega connection check response.');
            }
        }
    }

    protected function request($method, $path, ?array $data = null)
    {
        $options = [
            'headers' => [
                'X-MerchantId' => $this->options['merchant_id'],
                'X-Secret' => $this->options['secret'],
                'Accept' => 'application/json'
            ],
            'connect_timeout' => 5,
            'timeout' => 20,
            'allow_redirects' => false,
            'http_errors' => false
        ];
        if ($data !== null)
        {
            $options['json'] = $data;
        }
        try
        {
            // Do not retry POST: Platega does not document an idempotency key.
            $response = $this->http->request($method, self::BASE_URL . $path, $options);
        }
        catch (\Throwable $e)
        {
            // Guzzle exceptions may include credentials or the response body.
            throw new \RuntimeException('Platega connection failed. Check the merchant dashboard before retrying.');
        }
        $code = $response->getStatusCode();
        if ($code < 200 || $code >= 300)
        {
            throw new \RuntimeException('Platega returned HTTP ' . (int)$code . '.');
        }
        $body = (string)$response->getBody();
        $result = strlen($body) <= 65536 ? json_decode($body, true, 32) : null;
        if (!is_array($result))
        {
            throw new \RuntimeException('Platega returned an invalid response.');
        }
        return $result;
    }
}
