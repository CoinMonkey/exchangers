<?php

namespace coinmonkey\exchangers\tools;

class Changelly
{
    private $key = '';
    private $secret = '';
    private $booksCache;
    private $cache = true;

    public function __construct($api_key, $api_secret, $cache = true) {
        $this->key = $api_key;
        $this->secret = $api_secret;
        $this->cache = $cache;
    }

    public function request($method = 'getCurrencies', $params = [])
    {
        $apiUrl = 'https://api.changelly.com';
        $message = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);

        $sign = hash_hmac('sha512', $message, $this->secret);
        $requestHeaders = [
            'api-key:' . $this->key,
            'sign:' . $sign,
            'Content-type: application/json'
        ];
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $response = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($response);

        if(!$json | (!isset($json->result) && isset($json->error))) {
            if(isset($json->error) && $json->error) {
                throw new \coinmonkey\exceptions\ErrorException($json->error->message);
            } else {
                throw new \coinmonkey\exceptions\ErrorException("Unknown error");
            }
        }

        return $json->result;
    }
}
