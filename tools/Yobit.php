<?php

namespace coinmonkey\exchangers\tools;

use Illuminate\Support\Facades\Cache;
use Pepijnolivier\Yobit\Yobit as YobitTool;

class Yobit
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

    public function getOrderBook($market)
    {
        $rm = $this->retransformMarket($market);

        $result = YobitTool::getDepth($rm, 100);

        $return = [
            'asks' => [],
            'bids' => [],
        ];

        if(!isset($result[$rm])) {
            return $return;
        }

        foreach($result[$rm]['asks'] as $offer) {
            $offer = [
                'amount' => $offer[1],
                'price' => (float) $offer[0],
                'exchanger' => 'yobit',
                'fees' => $this->getFees(),
            ];

            $return['asks'][] = $offer;
        }

        foreach($result[$rm]['bids'] as $offer) {
            $offer = [
                'amount' => $offer[1],
                'price' => (float) $offer[0],
                'exchanger' => 'yobit',
                'fees' => $this->getFees(),
            ];

            $return['bids'][] = $offer;
        }

        $this->booksCache[$market] = $return;

        return $return;
    }

    public function getMyActiveOrders($market = '')
    {
        $result = YobitTool::getMyActiveOrders($this->retransformMarket($market), 100);

        if(!isset($result['return'])) {
            return [];
        }

        $return = [];

        foreach($result['return'] as $orderId => $order) {
            $return[$orderId] = [
                'market' => $market,
                'time' => $order['timestamp_created'],
                'deal' => $order['type'],
                'rate' => $order['rate'],
                'sum' => $order['amount'],
                'sum_remaining' => $order['amount'],
            ];
        }

        return $return;
    }

    public function getMarkets()
    {
        $pairs = file_get_contents('https://yobit.net/api/3/info');
        $result = json_decode($pairs);

        foreach($result->pairs as $name => $data) {
            $markets[$name] = $this->transformMarket($name);
        }

        return $markets;
    }

    public function cancelOrder($orderId, $market)
    {
        $result = YobitTool::cancelOrder($orderId);

        if ($result['success'] != true) {
            return false;
        }

        return true;
    }

    public function buy($market, $sum, $rate)
    {
        //return true;
        $result = YobitTool::trade($this->retransformMarket($market), 'buy', $rate, $sum);

        if(!$result['success']) {
            throw new \coinmonkey\exceptions\ErrorException("yobit coudn't buy $market, $sum, $rate", json_encode($result));
        }

        if ($result['success'] != true) {
            return false;
        }

        return $result['return']['order_id'];
    }

    public function sell($market, $sum, $rate)
    {
        //return true;
        $result = YobitTool::trade($this->retransformMarket($market), 'sell', $rate, $sum);

        if(!$result['success']) {
            throw new \coinmonkey\exceptions\ErrorException("yobit coudn't sell $market, $sum, $rate ", json_encode($result));
        }

        if ($result['success'] != true) {
            return false;
        }

        return $result['return']['order_id'];
    }

    public function getBalances()
    {
        $result = YobitTool::getTradeInfo();

        $return = [];

        foreach($result['return']['funds'] as $currency => $balance) {
            $return[strtoupper($currency)] = $balance;
        }

        return $return;
    }

    public function getFees() : array
    {
        return [
            'take' => '0.002',
            'make' => '0.002',
        ];
    }

    private function retransformMarket($marketName)
    {
        $parts = explode('-', $marketName);

        return strtolower($parts[1] . '_' . $parts[0]);
    }

    private function transformMarket($marketName)
    {
        $parts = explode('_', $marketName);

        return strtoupper($parts[1] . '-' . $parts[0]);
    }
}