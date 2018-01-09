<?php

namespace coinmonkey\exchangers\tools;

use coinmonkey\helpers\ExchangerHelper;

class Bittrex {
    private $key = '';
    private $secret = '';
    private $booksCache;
    private $cache = true;

    public function __construct($api_key, $api_secret, $cache = true) {
        $this->key = $api_key;
        $this->secret = $api_secret;
        $this->cache = $cache;
    }

    public function checkWithdraw($currency, $address, $amount, $time)
    {
        $uri = 'https://bittrex.com/api/v1.1/account/getwithdrawalhistory?apikey=' . $this->key . '&currency=' . $currency . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $result = json_decode($execResult);

        if(!$result->success) {
            throw new \coinmonkey\exceptions\ErrorException("Bittrex can't check deposit.", $execResult);
        }

        foreach($result->result as $withdraw) {
            $tdiff = time()-strtotime($withdraw->Opened);
            if($address == $withdraw->Address && $withdraw->Amount == $amount && $tdiff < $time) {
                return [
                    'id' => $withdraw->PaymentUuid,
                    'txId' => $withdraw->TxId,
                    'amount' => $withdraw->Amount,
                    'confirmations' => null,
                ];
            }
        }
        
        return false;
    }
    
    public function checkWithdrawById($id, $currency)
    {
        $uri = 'https://bittrex.com/api/v1.1/account/getwithdrawalhistory?apikey=' . $this->key . '&currency=' . $currency . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $result = json_decode($execResult);

        if(!$result->success) {
            throw new \coinmonkey\exceptions\ErrorException("Bittrex can't check deposit.", $execResult);
        }

        foreach($result->result as $withdraw) {
            if($withdraw->PaymentUuid == $id) {
                return [
                    'id' => $withdraw->PaymentUuid,
                    'txId' => $withdraw->TxId,
                    'amount' => $withdraw->Amount,
                ];
            }
        }
        
        return false;
    }
    
    public function checkDeposit($currency, $amount, $time)
    {
        $uri = 'https://bittrex.com/api/v1.1/account/getdeposithistory?apikey=' . $this->key . '&currency=' . $currency . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $result = json_decode($execResult);

        if(!$result->success) {
            throw new \coinmonkey\exceptions\ErrorException("Bittrex can't check deposit.", $execResult);
        }

        foreach($result->result as $deposit) {
            $tdiff = time()-strtotime($deposit->LastUpdated);

            if($deposit->Amount == $amount && $tdiff < $time) {
                return [
                    'id' => $deposit->Id,
                    'confirmations' => $deposit->Confirmations,
                    'txId' => $deposit->TxId,
                    'amount' => $deposit->Amount,
                ];
            }
        }
        
        return false;
    }
    
    public function getMinConfirmations($currency)
    {
        $uri = 'https://bittrex.com/api/v1.1/public/getcurrencies?apikey=' . $this->key . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $obj = json_decode($execResult);

        $currencies = [];

        foreach($obj->result as $currency) {
            $currencies[$currency->Currency] = $currency->MinConfirmation;
        }

        if(!isset($currencies[$currency])) {
            return null;
        }
        
        return (int) $currencies[$currency];
    }
    
    public function getDepositAddress(string $currency)
    {
        $uri = 'https://bittrex.com/api/v1.1/account/getdepositaddress?apikey=' . $this->key . '&currency=' . $currency . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $result = json_decode($execResult);

        if(!$result->success) {
            throw new \coinmonkey\exceptions\ErrorException("Bittrex can't make an address for deposit.", $execResult);
        }

        return [
            'private' => null,
            'public' => null,
            'address' => $result->result->Address,
            'id' => null,
        ];
    }

    public function withdraw(string $address, $amount, $currency)
    {
        $uri = 'https://bittrex.com/api/v1.1/account/withdraw?apikey=' . $this->key . '&quantity=' . $amount . '&address=' . $address . '&currency=' . $currency . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $result = json_decode($execResult);

        if(!$result->success) {
            throw new \coinmonkey\exceptions\ErrorException("Bittrex can't make a withdraw to $address $amount of $currency ");
        }

        return $result->result->uuid;
    }


    public function getOrderBook($market)
    {
        $uri = 'https://bittrex.com/api/v1.1/public/getorderbook?apikey=' . $this->key . '&market=' . $market . '&type=both&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        if(!$obj = json_decode($execResult)) {
			throw new \coinmonkey\exceptions\ErrorException('getorderbook error on Bittrex ', $execResult, null, 1);
			 
            return [
                'asks' => [],
                'bids' => [],
                'fees' => $this->getFees()
            ];
        }

        $result = $obj->result;

        if(!isset($result->sell)) {
			throw new \coinmonkey\exceptions\ErrorException('getorderbook error on Bittrex', $execResult, null, 1);

            return [
                'asks' => [],
                'bids' => [],
                'fees' => $this->getFees()
            ];
        }

        $return = [];

        foreach($result->sell as $offer) {
            $return['asks'][] = [
                'amount' => $offer->Quantity,
                'price' => $offer->Rate,
                'exchanger' => 'bittrex',
                'fees' => $this->getFees(),
            ];
        }

        foreach($result->buy as $offer) {
            $return['bids'][] = [
                'amount' => $offer->Quantity,
                'price' => (string) $offer->Rate,
                'exchanger' => 'bittrex',
                'fees' => $this->getFees(),
            ];
        }


        $this->booksCache[$market] = $return;

        return $return;
    }

    public function getOrder($id)
    {
        $uri = 'https://bittrex.com/api/v1.1/account/getorder?apikey=' . $this->key . '&uuid=' . $id . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $obj = json_decode($execResult);
        $order = $obj->result;

		if($order->Type != 'LIMIT_BUY') {
            $price = $order->Quantity*$order->Limit;
        } else {
            $price = $order->Quantity;
        }
        $price = $price-($price*$this->getFees()['take']);
		
        return [
			'raw_data' => $order,
            'open' => $order->IsOpen,
            'market' => $order->Exchange,
            'time' => strtotime($order->Opened),
            'deal' => ($order->Type == 'LIMIT_BUY') ? 'buy' : 'sell',
            'rate' => $order->Limit,
            'price' => $price,
            'sum' => $order->Quantity,
            'sum_remaining' => $order->QuantityRemaining,
        ];
    }

    public function getMarkets()
    {
        $uri = 'https://bittrex.com/api/v1.1/public/getmarkets?apikey=' . $this->key . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $obj = json_decode($execResult);

        $markets = [];

        foreach($obj->result as $market) {
            $markets[$market->MarketName] = $market->MarketName;
        }

        return $markets;
    }

    public function buy($market, $sum, $rate)
    {
        //return true;
        $uri = 'https://bittrex.com/api/v1.1/market/buylimit?apikey=' . $this->key . '&market=' . $market . '&quantity=' . $sum . '&rate=' . $rate . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $obj = json_decode($execResult);

        $result = ($obj->success == true);

        if(!$result) {
            throw new \coinmonkey\exceptions\ErrorException("bittrex buy $market, $sum, $rate ", json_encode($obj));
        }

        return $obj->result->uuid;
    }

    public function sell($market, $sum, $rate)
    {
        //return true;
        $uri = 'https://bittrex.com/api/v1.1/market/selllimit?apikey=' . $this->key . '&market=' . $market . '&quantity=' . $sum . '&rate=' . $rate . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $obj = json_decode($execResult);

        $result = ($obj->success == true);

        if(!$result) {
            throw new \coinmonkey\exceptions\ErrorException("bittrex sell $market, $sum, $rate", json_encode($obj));
        }

        return $obj->result->uuid;
    }

    public function cancelOrder($id, $market = '')
    {
        $uri = 'https://bittrex.com/api/v1.1/market/cancel?apikey=' . $this->key . '&uuid=' . $id . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $obj = json_decode($execResult);

        return ($obj->success == true);
    }

    public function getMyActiveOrders() : array
    {
        $uri = 'https://bittrex.com/api/v1.1/market/getopenorders?apikey=' . $this->key . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $obj = json_decode($execResult);

        $result = $obj->result;

        $return = [];

        foreach($result as $order) {
            $return[$order->OrderUuid] = [
                'market' => $order->Exchange,
                'time' => strtotime($order->Opened),
                'deal' => ($order->OrderType == 'LIMIT_BUY') ? 'buy' : 'sell',
                'rate' => $order->Price,
                'sum' => $order->Quantity,
                'sum_remaining' => $order->QuantityRemaining,
            ];
        }

        return $return;
    }

    public function getOrders() : array
    {
        $uri = 'https://bittrex.com/api/v1.1/market/getopenorders?apikey=' . $this->key . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $obj = json_decode($execResult);

        $result = $obj->result;

        $return = [];

        foreach($result as $order) {
            $return[$order->OrderUuid] = [
                'market' => $order->Exchange,
                'time' => strtotime($order->Opened),
                'deal' => ($order->OrderType == 'LIMIT_BUY') ? 'buy' : 'sell',
                'rate' => $order->Price,
                'sum' => $order->Quantity
            ];
        }

        return $return;
    }

    public function getRates(string $market)
    {
        $uri = 'https://bittrex.com/api/v1.1/public/getticker?apikey=' . $this->key . '&market=' . $market . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $obj = json_decode($execResult);

        if(!$obj | !isset($obj->result)) {
            return $this->lastRateResult;
        }

        $result = $obj->result;

        $return = [
            'bid' => $result->Bid,
            'ask' => $result->Ask,
            'last' => $result->Last,
        ];

        $this->lastRateResult = $return;

        return $return;
    }

    public function getBalances()
    {
        $uri = 'https://bittrex.com/api/v1.1/account/getbalances?apikey=' . $this->key . '&nonce=' . time();

        $ch = curl_init($uri);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apisign:' . hash_hmac('sha512', $uri, $this->secret)]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);

        $execResult = curl_exec($ch);

        $obj = json_decode($execResult);

        $result = $obj->result;

        $return = [];

        foreach($result as $balance) {
            $return[$balance->Currency] = $balance->Balance;
        }

        return $return;
    }

    public function getFees() : array
    {
        return [
            'take' => '0.0025',
            'make' => '0.0025',
        ];
    }
}
