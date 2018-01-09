<?php
namespace coinmonkey\exchangers\tools;

class Evercoin
{    
    private $key;
    private $apiUrl = 'https://test.evercoin.com/v1';
    private $timeout = 3000;
    private $signature;
    
    public function __construct($key, $timeout = 3000, $apiUrl = null) {
        $this->key = $key;
        
        $this->timeout = $timeout;
        
        if($apiUrl) {
            $this->apiUrl = $apiUrl;
        }
    }

    public function getSignature()
    {
        return $this->signature;
    }
    
    public function getLimit()
    {
        
    }
    
    public function validateAddress()
    {
        
    }

    public function getCoins()
    {
        
    }
    
    public function getPrice($depositCoin, $destinationCoin, $depositAmount)
    {
        $result = $this->query('price', ['depositCoin' => $depositCoin, 'destinationCoin' => $destinationCoin, 'depositAmount' => $depositAmount]);
        
        if($result->error) {
            throw new \Exception($result->error->message);
        }
        
        $this->signature = $result->result->signature;
        
        return $result->result->destinationAmount;
    }
    
    public function getPriceArray()
    {
        
    }
    
    public function createOrder($depositCoin, $destinationCoin, $depositAmount, $destinationAmount, $clientAddress, $signature)
    {
        $data = [
            "depositCoin" => $depositCoin,
            "depositAmount" => $depositAmount,
            "destinationAddress" => [
                "mainAddress" => $clientAddress,
            ],
            "refundAddress" => [
                "mainAddress" => '1Nz46wTWjG5uQPvTDdv3Ai8oas9d3ygGha',
            ],
            "destinationCoin" => $destinationCoin,
            "destinationAmount" => $destinationAmount,
            "signature" => $signature,
        ];

        $result = $this->query('order', $data);
        var_dump($result); die;
        return [
            'address' => $result->result->destinationAddress->refundAddress->mainAddress,
            'id' => 1
        ];
    }
    
    public function getGetStatus($orderId)
    {
        $result = $this->query('status/' . $orderId, null);
    }
    
    private function query($method, $params = [])
    {
        $uri = $this->apiUrl . '/' . $method;

        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'EVERCOIN-API-KEY: ' . $this->key,
            'Content-Type: application/json;charset=UTF-8',
        ]);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        
        if($params) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }
            
        $execResult = curl_exec($ch);

        return json_decode($execResult);
    }
}