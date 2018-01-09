<?php

namespace coinmonkey\exchangers\tools;

use coinmonkey\exchangers\tools\Vendor\Bitfinex as BitfinexTool;
use coinmonkey\helpers\ExchangerHelper;

class Bitfinex {
    const CONNECT_TIMEOUT = 60;
    const API_URL = 'https://api.bitfinex.com';

    private $api_key = '';
    private $api_secret = '';
    private $api_version = '';
    private $booksCache;
    private $tool;
    private $cache = true;

    private $namesMap = [
        'DASH' => 'DSH',
    ];

    public function __construct($api_key, $api_secret, $api_version = 'v1', $cache = true) {
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
        $this->api_version = $api_version;
        $this->tool = new BitfinexTool($api_key, $api_secret, $api_version = 'v1');
        $this->cache = $cache;
    }
	
	public function getTool()
	{
		return $this->tool;
	}

    public function getBalances()
    {
        $result = $this->tool->get_balances();

        $return = [];

        foreach($result as $balance) {
            $return[strtoupper($balance['currency'])] = $balance['available'];
        }

        return $return;
    }

    public static function getMethodName($currency)
    {
        $method = null;

        switch($currency) {
            case 'BTC': $method = 'bitcoin'; break;
            case 'ETH': $method = 'ethereum'; break;
            case 'LTC': $method = 'litecoin'; break;
        }

        return $method;
    }

    public function getDepositAddress($currency)
    {
        $method = self::getMethodName($currency);

        $result = $this->tool->new_deposit($method, 'exchange', 1);

        if($result['result'] != 'success') {
            throw new \coinmonkey\exceptions\ErrorException("Bitfinex can't make an address for deposit.");
        }

        return [
            'private' => null,
            'public' => null,
            'address' => $result['address'],
            'id' => null,
        ];
    }

    public function withdraw(string $address, $amount, $currency)
    {
        $method = self::getMethodName($currency);

        $result = $this->tool->withdraw($method, 'exchange', (string) $amount, $address);

        if(!isset($result[0])) {
            return false;
        }

        if(!isset($result[0]['status']) | $result[0]['status'] != 'success') {
            throw new \coinmonkey\exceptions\ErrorException("Bitfinex can't make a withdraw a withdraw to $address $amount of $currency");
        } else {
            return true;
        }
    }

    public function getRates(string $market)
    {
        $result = $this->tool->get_ticker($this->retransformMarket($market));

        if(!$result) {
            return $this->lastRateResult;
        }

        $return = [
            'bid' => $result['bid'],
            'ask' => $result['ask'],
            'last' => $result['last_price'],
        ];

        $this->lastRateResult = $return;

        return $return;
    }

    public function getOrder($id)
    {
        $order = $this->tool->get_order((int) $id);

        if((isset($order['error']) && $order['error'])) {
            sleep(2);
            //echo 'sleep1';
            return $this->getOrder($id);
        }

        if($order['side'] != 'buy') {
            $price = $order['original_amount']*$order['price'];
        } else {
            $price = $order['original_amount'];
        }
        $price = $price-($price*$this->getFees()['take']);
        //@todo: установить причину погрешности
        $price = $price-($price*0.001);

        return [
            'raw_data' => $order,
            'open' => $order['is_live'],
            'market' => $this->transformMarket($order['symbol']),
            'time' => $order['timestamp'],
            'deal' => ($order['side'] == 'buy') ? 'buy' : 'sell',
            'rate' => $order['price'],
            'price' => $price,
            'sum' => $order['original_amount'],
            'sum_remaining' => $order['remaining_amount'],
        ];
    }

    public function getMyActiveOrders() : array
    {
        $result = $this->tool->get_orders();

        $return = [];

        foreach($result as $order) {
            $return[$order['id']] = [
                'market' => $this->transformMarket($order['symbol']),
                'time' => $order['timestamp'],
                'deal' => $order['side'],
                'rate' => $order['price'],
                'sum' => $order['original_amount'],
                'sum_remaining' => $order['remaining_amount'],
            ];
        }

        return $return;
    }

    public function cancelOrder($orderId, $market = '')
    {
        $result = $this->tool->cancel_order($orderId);

        if($id = $result['id']) {
            return $id;
        }

        return false;
    }

    public function buy($market, $sum, $rate)
    {
        $result = $this->tool->new_order($this->retransformMarket($market), (string) $sum, (string) $rate, 'bitfinex', 'buy', 'exchange limit');

        if(!isset($result['order_id'])) {
            throw new \coinmonkey\exceptions\ErrorException("bitfinex buy $market, $sum, $rate ");
        }

        if($id = $result['order_id']) {
            return $id;
        }

        return false;
    }

    public function sell($market, $sum, $rate)
    {
        $result = $this->tool->new_order($this->retransformMarket($market), (string) $sum, (string) $rate, 'bitfinex', 'sell', 'exchange limit');

        if(!isset($result['order_id'])) {
            throw new \coinmonkey\exceptions\ErrorException("bitfinex sell $market, $sum, $rate ");
        }

        if($id = $result['order_id']) {
            return $id;
        }

        return false;
    }

    public function getOrderBook($market)
    {
        $return = $this->tool->get_book($this->retransformMarket($market));

        $return2 = [
            'asks' => [],
            'bids' => [],
        ];

        if(!isset($return['asks'])) {
            return $return2;
        }

        foreach($return['asks'] as $key => $offer) {
            $offer['exchanger'] = 'bitfinex';
            $offer['fees'] = $this->getFees();
            $offer['price'] = (float) $offer['price'];
            $return2['asks'][] = $offer;
        }

        foreach($return['bids'] as $key => $offer) {
            $offer['exchanger'] = 'bitfinex';
            $offer['fees'] = $this->getFees();
            $offer['price'] = (float) $offer['price'];
            $return2['bids'][] = $offer;
        }

        $this->booksCache[$market] = $return2;

        return $return2;
    }

    public function getMarkets()
    {
        $symbols = $this->tool->get_symbols();

        $markets = [];

        foreach($symbols as $symbol) {
            $markets[$symbol] = $this->transformMarket($symbol);
        }

        return $markets;
    }

    private function retransformMarket($marketName)
    {
        if(!substr_count($marketName, '-')) {
            return $marketName;
        }

        $currencies = explode('-', $marketName);

        $currency1 = strtoupper(trim($currencies[0]));
        $currency2 = strtoupper(trim($currencies[1]));


        foreach($this->namesMap as $origin => $right) {
            if($currency1 == $origin) {
                $currency1 = $right;
            }

            if($currency2 == $origin) {
                $currency2 = $right;
            }
        }

        $marketName = strToLower($currency2 . $currency1);

        return $marketName;
    }

    private function transformMarket($marketName)
    {
        $currency1 = strtoupper(substr($marketName, 0, 3));
        $currency2 = strtoupper(substr($marketName, -3));

        foreach($this->namesMap as $origin => $right) {
            if($currency1 == $origin) {
                $currency1 = $right;
            }

            if($currency2 == $origin) {
                $currency2 = $right;
            }
        }

        return $currency2 . '-' . $currency1;
    }

    public function getFees() : array
    {
        return [
            'take' => '0.002',
            'make' => '0.001',
        ];
    }
}
