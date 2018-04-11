<?php

namespace coinmonkey\exchangers\tools;

class Changenow
{
    private $apiUrl = 'https://changenow.io/api/v1';
    private $apikey;
    private $timeout = 3000;
    private $pairs = [];

    private $currenciesCache = false;

    public function __construct($apikey, $timeout = 3000, $apiUrl = null)
    {
        $this->apikey = $apikey;
        $this->timeout = $timeout;

        if($apiUrl) {
            $this->apiUrl = $apiUrl;
        }
    }

    public function getCurrencies()
    {
        return $this->query('currencies');
    }
    
    public function getMinAmount($coinCodeFrom, $coinCodeTo)
    {
        if($data = $this->query('min-amount/' . mb_strtolower($coinCodeFrom . '_' . $coinCodeTo))) {
            return $data->minAmount;
        }
    }
    
    public function getExchangeAmount($coinCodeFrom, $coinCodeTo, $amount) : float
    {
        if($data = $this->query('exchange-amount/' . $amount . '/' . mb_strtolower($coinCodeFrom . '_' . $coinCodeTo))) {
            $comis = (float) $data->serviceCommission;
            $estimate = (float) $data->estimatedAmount;
            return $estimate-($estimate*$comis*0.01)-(float) $data->networkFee;
        }
    }
    
    public function createTransaction($coinCodeFrom, $coinCodeTo, $address, $amount)
    {
        $params = [
            'from' => $coinCodeFrom,
            'to' => $coinCodeTo,
            'address' => $address,
            'amount' => $amount,
            'extraId' => null
        ];
        
        if($data = $this->query('transactions/' . $this->apikey, $params)) {
            return $data;
        }
    }
    
    public function getTransactions($coinCodeFrom = null, $coinCodeTo = null, $status = null, $limit = null, $offset = null)
    {
        $params = [
            'from' => $coinCodeFrom,
            'to' => $coinCodeTo,
            'status' => $status,
            'limit' => $limit,
            'offset' => $offset,
        ];
        
        if($data = $this->query('transactions/' . $this->apikey . '?' . http_build_query($params))) {
            return $data;
        }
    }
    
    public function getTransactionStatus($id)
    {
        if($data = $this->query('transactions/' . $id . '/' . $this->apikey)) {
            return $data;
        }
    }
    
    public function checkForScam($website)
    {
        if($data = $this->query('scam-check/' . $website)) {
            return $data->scam;
        }
    }

    private function query($method, $params = [])
    {
        $uri = $this->apiUrl . '/' . $method . '/';

        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
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
