<?php

class Evrik_Platega_Client
{
    private $options;

    public function __construct(array $options)
    {
        $this->options = $options;
    }

    public function request($method, $path, array $data = null)
    {
        $handle = curl_init('https://app.platega.io' . $path);
        $body = '';
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'X-MerchantId: ' . $this->options['merchant_id'],
                'X-Secret: ' . $this->options['secret'],
                'Accept: application/json', 'Content-Type: application/json'
            ],
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 65536) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            }
        ]);
        if ($data !== null) { curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($data)); }
        $ok = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($ok === false || $status < 200 || $status >= 300)
        {
            throw new RuntimeException('Platega request failed. Check the merchant dashboard before retrying.', 503);
        }
        $result = json_decode($body, true, 32);
        if (!is_array($result)) { throw new RuntimeException('Invalid Platega response.'); }
        return $result;
    }
}
