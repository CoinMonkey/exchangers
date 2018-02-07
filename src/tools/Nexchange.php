<?php

namespace coinmonkey\exchangers\tools;

class Nexchange
{
    private $referral;
    private $apiUrl = 'https://api.nexchange.io/en/api/v1';
    private $timeout = 3000;
    private $pairs = [];

    private $currenciesCache = false;

    public function __construct($referral, $timeout = 3000, $apiUrl = null) {
        $this->referral = $referral;

        $this->timeout = $timeout;

        if($apiUrl) {
            $this->apiUrl = $apiUrl;
        }
    }

    public function getPrice($depositCoin, $destinationCoin, $amount)
    {
        $result = $this->query('price/' . $this->getPairName($depositCoin, $destinationCoin) . '/latest', []);

        $direction = $this->getDirection($depositCoin, $destinationCoin);

        if(!$result[0] | !$price = $result[0]->ticker->{$direction}) {
            throw new \App\Exceptions\ErrorException('Error while taking price ');
        }

        if($direction != 'bid') {
            return $amount/$price;
        } else {
           return $amount*(1/$price);
        }
    }

    public function getOrder($orderId)
    {
        $result = $this->query('orders/' . $orderId, null);

        return $result;
    }

    public function getStatus($orderId)
    {
        $result = $this->query('orders/' . $orderId, null);

        return $result->status_name[0][0];
    }

    public function createAnonymousOrder($depositCoin, $destinationCoin, $amount, $address)
    {
        $data = [
            "amount_base" => $amount,
            "is_default_rule" => false,
            "pair" =>  [
                "name" => $this->getPairName($depositCoin, $destinationCoin)
            ],
            "withdraw_address" => [
                "address" => $address
            ],
        ];

        return $this->query('orders', $data);
    }

    public function getMinimum($coin)
    {
        $currencies = $this->getCurrencies();

        foreach($currencies as $currency) {
            if($coin == $currency->code) {
                return $currency->minimal_amount;
            }
        }

        return 0;
    }

    public function getMaximum($coin)
    {
        $currencies = $this->getCurrencies();

        foreach($currencies as $currency) {
            if($coin == $currency->code) {
                return $currency->maximal_amount;
            }
        }

        return 0;
    }

    public function getMinConfirmations($coin)
    {
        $currencies = $this->getCurrencies();

        foreach($currencies as $currency) {
            if($coin == $currency->code) {
                return $currency->min_confirmations;
            }
        }

        return 0;
    }

    public function getWithdrawalFee($coin)
    {
        $currencies = $this->getCurrencies();

        foreach($currencies as $currency) {
            if($coin == $currency->code) {
                return $currency->withdrawal_fee;
            }
        }

        return 0;
    }

    private function getPairName($depositCoin, $destinationCoin)
    {
        foreach($this->getPairs() as $pairName => $pair) {
            if($depositCoin == $pair['quote'] && $destinationCoin == $pair['base']) {
                return $pairName;
            }
        }

        return null;
    }

    private function getPairFees($depositCoin, $destinationCoin)
    {
        foreach($this->getPairs() as $pairName => $pair) {
            if($depositCoin == $pair['quote'] && $destinationCoin == $pair['base']) {
                return $pairName;
            }
        }

        return null;
    }

    private function getDirection($depositCoin, $destinationCoin)
    {
        foreach($this->getPairs() as $pairName => $pair) {
            if($depositCoin == $pair['quote'] && $destinationCoin == $pair['base']) {
                return 'bid';
            }

            if($depositCoin == $pair['base'] && $destinationCoin == $pair['quote']) {
                return 'ask';
            }
        }

        return null;
    }

    private function getPairs()
    {
        if(!$pairs = $this->pairs) {
            $pairsData = $this->query('pair');
            $pairs = [];

            foreach($pairsData as $pair) {
                $pairs[$pair->name] = (array) $pair;
            }

            $this->pairs = $pairs;
        }

        return $pairs;
    }

    public function getCurrencies()
    {
        if(!$currencies = $this->currenciesCache) {
            $currencies = $this->query('currency', []);
            $this->currenciesCache = $currencies;
        }

        return $currencies;
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
