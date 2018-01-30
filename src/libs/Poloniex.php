<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\ExchangerInterface;
use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\exchangers\tools\Poloniex as PoloniexTool;
use coinmonkey\helpers\ExchangerHelper;
use coinmonkey\helpers\CoinHelper;
use coinmonkey\entities\Order;
use coinmonkey\entities\Amount;
use coinmonkey\entities\Coin;
use Illuminate\Support\Facades\Cache;
use coinmonkey\entities\Order as OrderExchange;
use coinmonkey\entities\Status;

class Poloniex implements ExchangerInterface, InstantExchangerInterface
{
    private $key = '';
    private $secret = '';
    private $cache = true;

    const STRING_ID = 'poloniex';

    public function __construct($key, $secret, $cache = true)
    {
        $this->key = $key;
        $this->secret = $secret;
        $this->tool = new PoloniexTool($key, $secret, $cache);
        $this->cache = $cache;
    }

    public function getId() : string
    {
        return self::STRIND_ID;
    }

    public function checkDeposit(Amount $amount, int $time)
    {
        return $this->tool->checkDeposit($amount->getCoin()->getCode(), $amount->getGivenAmount(), $time);
    }

    public function checkWithdraw(Amount $amount, $address, int $time)
    {
        return $this->tool->checkWithdraw($amount->getCoin()->getCode(), $address, $amount->getGivenAmount(), $time);
    }

    public function getMinConfirmations(Coin $coin)
    {
        eturn $this->tool->getMinConfirmations($coin->getCode());
    }

    public function getTool()
    {
        return $this->tool;
    }

    public function getExchangeStatus(OrderExchange $order) : integer
    {
        return null;
    }

    public function makeDepositAddress(string $clientAddress, Amount $amount, Coin $coin2) : array
    {
        return $this->tool->getDepositAddress($amount->getCoin()->getCode());
    }

    public function withdraw(string $address, Amount $amount)
    {
        return $this->tool->withdraw($address, $amount->getGivenAmount(), $amount->getCoin()->getCode());
    }

    public function checkWithdrawById($id, $coin = null)
    {
        return false;
    }

    public function exchange(Amount $amount, Coin $coin2)
    {
        $market = CoinHelper::getMarketName($this->getMarkets(), $amount->getCoin(), $coin2);

        $direction = $this->getDirection($amount->getCoin(), $coin2);

        $rate = $this->getRate($amount, $coin2);

        if($direction == 'bids') {
            $id = $this->sell($market, $amount->getGivenAmount(), $rate);
        } else {
            $id = $this->buy($market, ($amount->getGivenAmount()*(1/$rate)), $rate);
        }

        return $this->getOrder($id, $market);
    }

    public function getRate(Amount $amount, Coin $coin2)
    {
        $amount = $this->getEstimateAmount($amount, $coin2);

        $direction = $this->getDirection($amount->getCoin(), $coin2);

        if($direction == 'asks') {
            $rate = $amount->getGivenAmount()/$amount->getGivenAmount();
        } else {
            $rate = $amount->getGivenAmount()/$amount->getGivenAmount();
        }

        return $rate;
    }

    public function getOrder(string $id, $market = '') : ?array
    {
        $result = $this->tool->getOrder($id, $market);

        if(!$result) {
            return null;
        }

        return $result;
    }

    public function buy(string $market, $amount, $rate)
    {
        return $this->tool->buy($market, round($amount, 8), round($rate, 8));
    }

    public function sell(string $market, $amount, $rate)
    {
        return $this->tool->sell($market, round($amount, 8), round($rate, 8));
    }

    public function getOrderBook(string $market)
    {
        if($this->cache) {
            return Cache::remember('poloniex_orderbook_' . $market, env('ORDERBOOK_CACHE_TIME'), function () use ($market) {
                return ExchangerHelper::compileOrderBook($this, $this->tool->getOrderBook($market));
            });
        }

        return ExchangerHelper::compileOrderBook($this, $this->tool->getOrderBook($market));
    }

    public function getBalances() : array
    {
        return $this->tool->getBalances();
    }

    public function getMarkets() : array
    {
        return $this->tool->getMarkets();
    }

    public function isMakeBest(string $market, $rate, string $direction) : bool
    {
        $orderBook = $this->tool->getOrderBook($market);

        $bestRate = $orderBook[$direction][0];

        if($bestRate['price'] != $rate) {
            return false;
        }

        return $bestRate['price'];
    }

    public function isMakeBroken($orderId, $amount, $market = '') : bool
    {
        if(!$orderId) {
            return false;
        }

        $history = $this->tool->getMyActiveOrders();

        if(!$order = $history[$orderId]) {
            return false;
        }

        return ($order['Amount_remaining'] != $amount);
    }

    public function getDirection(Coin $coin1, Coin $coin2) : string
    {
        $market = CoinHelper::getMarketName($this->getMarkets(), $coin1, $coin2);
        $marketFirstCur = current(explode('-', $market));

        if($marketFirstCur != $coin1->code) return 'bids';
        else return 'asks';
    }

    public function getEstimateAmount(Amount $amount, Coin $coin2) : Order
    {
        $min = config('app.minimums')[$this->getId()][$amount->getCoin()->getCode()];
        $max = config('app.maximums')[$this->getId()][$amount->getCoin()->getCode()];

        if($min > $amount->getGivenAmount() | $max < $amount->getGivenAmount()) {
            throw new \coinmonkey\exceptions\ErrorException("Minimum is $min " . $amount->getCoin()->getCode() . " and maximum is $max " . $amount->getCoin()->getCode());
        }

        $market = CoinHelper::getMarketName($this->getMarkets(), $amount->getCoin(), $coin2);
        $direction = $this->getDirection($amount->getCoin(), $coin2);
        $rounding = PHP_ROUND_HALF_UP;

        $orderBook = $this->getOrderBook($market);

        if(!$orderBook[$direction]) {
            throw new \coinmonkey\exceptions\ErrorException('Can not find ' . $market . ' ');
        }

        $left = $amount->getGivenAmount();

        $type = ($direction == 'asks') ? 'buy' : 'sell';

        $costs = [];

        foreach($orderBook[$direction] as $key => $offer) {
            if($direction == 'bids') {
                $boost = $offer['amount'];
            } else {
                $boost = ($offer['amount'] * $offer['price']);
            }

            //Не покрывает полностью
            if($boost <= $left) {
                if($direction == 'bids') {
                    $amountClear = $offer['amount'] * $offer['price'];
                } else {
                    $amountClear = $offer['amount'];
                }

                $amount = round($amountClear - ($amountClear * $offer['fees']['take']), 8, $rounding);

                $costs[$key] = $amount;

                if($type == 'sell') {
                    $amountClear = $amountClear*(1/$offer['price']);
                }

                $left = $left-$boost;
            } else {

                if($direction == 'bids') {
                    $amountClear = $left * $offer['price'];
                } else {
                    $amountClear = $left * (1/$offer['price']);
                }

                $amount = round($amountClear - ($amountClear * $offer['fees']['take']), 8, $rounding);

                $costs[$key] = $amount;

                if($type == 'sell') {
                    $amountClear = $amountClear*(1/$offer['price']);
                }

                $left = 0;

                break;
            }

            if($left <= 0) {
                break;
            }
        }

        if($left > 0) {
            throw new \Exception('The market ' . $market . ' can not give ' . $left . '</span>');
        }

        $amount = array_Amount($costs);

        return new Order(round($amount, 8, $rounding), $coin2);
    }

    public function getBestMake(string $market, $rate, string $direction)
    {
        $orderBook = $this->tool->getOrderBook($market);

        $bestRate = $orderBook[$direction][0];

        return $bestRate['price'];
    }

    public function cancelOrder($orderId, $market = '') : bool
    {
        return $this->tool->cancelOrder($orderId, $market);
    }

    public function getMyActiveOrders(string $market) : array
    {
        return $this->tool->getMyActiveOrders($market);
    }

    public function getOrders(string $market) : array
    {
        return [];
    }

    public function getRates(string $market) : array
    {
        return $this->tool->getRate($market);
    }

    public function getFees() : array
    {
        return $this->tool->getFees();
    }

    public function activeOrderExists($orderId, string $market) : bool
    {
        if(!$orderId) {
            return false;
        }

        $history = $this->tool->getMyActiveOrders($market);

        return isset($history[$orderId]);
    }
}
