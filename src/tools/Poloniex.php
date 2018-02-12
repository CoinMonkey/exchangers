<?php

namespace coinmonkey\exchangers\tools;

use coinmonkey\exchangers\tools\Vendor\Poloniex as PoloniexTool;

class Poloniex
{
    protected $api_key;
    protected $api_secret;
    private $booksCache;
    private $tool;
    private $cache = true;
    private $currenciesCache;

    public function __construct($api_key, $api_secret, $cache = true) {
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
        $this->tool = new PoloniexTool($api_key, $api_secret);
        $this->cache = $cache;
    }

    public function checkDeposit($coin, $amount, $time)
    {
        $result = $this->tool->get_deposit_withdraw_history($time);

        foreach($result['deposits'] as $deposit) {
            $tdiff = time()-$deposit['timestamp'];

            if($deposit['currency'] == $coin && $deposit['amount'] == $amount && $tdiff < $time) {
                return [
                    'id' => null,
                    'confirmations' => $deposit['confirmations'],
                    'txId' => $deposit['txid'],
                    'amount' => $deposit['amount'],
                    'status' => $deposit['status'],
                ];
            }
        }

        return false;
    }

    public function checkWithdraw($coin, $address, $amount, $time)
    {
        $result = $this->tool->get_deposit_withdraw_history($time);

        foreach($result['withdrawals'] as $withdrawal) {
            $tdiff = time()-$withdrawal['timestamp'];

            $realAmount = $withdrawal['amount']-$this->getWithdrawalFee($coin);

            if($address == $withdrawal['address'] && $withdrawal['currency'] == $coin && (string) $realAmount == (string) $amount && $tdiff < $time) {
                if(substr_count($withdrawal['status'], 'PENDING')) {
                    return null;
                }

                if(!isset($withdrawal['txid'])) {
                    $status = explode(': ', $withdrawal['status']);
                    $withdrawal['txid'] = end($status);
                }
                return [
                    'id' => null,
                    'confirmations' => @$withdrawal['confirmations'],
                    'txId' => $withdrawal['txid'],
                    'amount' => $withdrawal['amount'],
                    'status' => $withdrawal['status'],
                ];
            }
        }

        return false;
    }

    public function withdraw(string $address, $amount, $coin)
    {
        $result = $this->tool->withdraw($coin, $amount, $address);

        if(!isset($result['response'])) {
            throw new \coinmonkey\exceptions\ErrorException("Poloniex can't make a withdraw a withdraw to $address $amount of $coin " . $result['error']);
        }

        return '1';
    }

    public function getWithdrawalFee($currency)
    {
        $currencies = $this->getCurrencies();

        if(isset($currencies[$currency])) {
            return $currencies[$currency]['txFee'];
        }

        throw new \App\Exceptions\ErrorException("Poloniex API error (gwf)");
    }

    public function getMinConfirmations($currency) : int
    {
        $currencies = $this->getCurrencies();

        if(isset($currencies[$currency])) {
            return $currencies[$currency]['minConf'];
        }

        throw new \coinmonkey\exceptions\ErrorException("Poloniex getting confirmations count error");
    }

    public function getMinAmount($currency) : ?int
    {
        return null;
    }

    public function getMyActiveOrders($market) : array
    {
        $result = $this->tool->get_open_orders($this->retransformMarket($market));

        if(!$result) {
            return [];
        }

        $return = [];

        foreach($result as $order) {
            if(is_array($order) && isset($order['orderNumber'])) {
                $return[$order['orderNumber']] = [
                    'market' => $this->transformMarket($market),
                    'time' => strtotime($order['date']),
                    'deal' => $order['type'],
                    'rate' => $order['rate'],
                    'Amount' => $order['startingAmount'],
                    'Amount_remaining' => $order['amount'],
                ];
            }
        }

        return $return;
    }

    public function getDepositAddress($coin)
    {
        $address = $this->tool->get_deposit_address($coin);

        if(!$address) {
            throw new \coinmonkey\exceptions\ErrorException("Poloniex can't make an address for deposit.");
        }

        return [
            'address' => $address,
            'id' => null,
        ];
    }

    public function getOrder(string $id, $market)
    {
        $result = $this->tool->get_my_trade_history($this->retransformMarket($market));

        foreach($result as $order) {
            if($order["orderNumber"] == $id) {
                return [
                    'open' => false,
                    'market' => $this->transformMarket($market),
                    'time' => strtotime($order['date']),
                    'deal' => $order['type'],
                    'price' => ($order['type'] == 'sell') ? $order['total'] : $order['amount'],
                    'rate' => $order['rate'],
                    'Amount' => $order['amount'],
                    'Amount_remaining' => $order['amount'],
                ];
            }
        }

        return false;
    }

    public function buy($market, $amount, $rate)
    {
        //return true;
        $result = $this->tool->buy($this->retransformMarket($market), $rate, $amount);

        if(isset($result['error'])) {
            throw new \coinmonkey\exceptions\ErrorException("Poloniex couldn't buy $market $amount, ($rate) error " . $result['error']);
        }

        return $result['orderNumber'];
    }

    public function sell($market, $amount, $rate)
    {
        //return true;
        $result = $this->tool->sell($this->retransformMarket($market), $rate, $amount);

        if(isset($result['error'])) {
            throw new \coinmonkey\exceptions\ErrorException("poloniex couldn't sell $market $amount ($rate) error " . $result['error']);
        }

        return $result['orderNumber'];
    }

    public function cancelOrder($orderId, $market = '')
    {
        $result = $this->tool->cancel_order($this->retransformMarket($market), $orderId);

        return $result;
    }

    public function getBalances()
    {
        $result = $this->tool->get_balances();

        $return = [];

        foreach($result as $address => $balance) {
            if($balance > 0) {
                $return[$address] = $balance;
            }
        }

        return $return;
    }

    public function getOrderBook(string $market)
    {
        $content = file_get_contents('https://poloniex.com/public?command=returnOrderBook&currencyPair='.$this->retransformMarket($market).'&depth=10');

        $result = json_decode($content);

        if(isset($result->error) && !empty($result->error)) {
            throw new \coinmonkey\exceptions\ErrorException($result->error);
        }

        if(!isset($result->asks)) {
            return [
                'asks' => [],
                'bids' => [],
            ];
        }

        $return = [];

        foreach($result->asks as $key => $offer) {
            $return['asks'][] = [
                'exchanger' => 'poloniex',
                'fees' => $this->getFees(),
                'price' => (string) $offer[0],
                'amount' => $offer[1],
            ];
        }

        foreach($result->bids as $key => $offer) {
            $return['bids'][] = [
                'exchanger' => 'poloniex',
                'fees' => $this->getFees(),
                'price' => (string) $offer[0],
                'amount' => $offer[1],
            ];
        }

        $this->booksCache[$market] = $return;

        return $return;
    }

    public function getMarkets() : array
    {
        $content = file_get_contents('https://poloniex.com/public?command=returnTicker');
        $result = json_decode($content);

        $markets = [];

        foreach($result as $name => $data) {
            $markets[$name] = $this->transformMarket($name);
        }

        $this->markets = $markets;

        return $markets;
    }

    private function retransformMarket($marketName)
    {
        return str_replace('-', '_', $marketName);
    }

    private function transformMarket($marketName)
    {
        return str_replace('_', '-', $marketName);
    }

    private function getCurrencies()
    {
        if($this->cache && !$this->currenciesCache) {
            $this->currenciesCache = $this->tool->get_currencies();
        }

        return $this->currenciesCache;
    }

    public function getFees() : array
    {
        return [
            'take' => '0.0025',
            'make' => '0.0015',
        ];
    }
}
