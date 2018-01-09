<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\ExchangerInterface;
use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\exchangers\tools\Poloniex as PoloniexTool;
use coinmonkey\helpers\ExchangerHelper;
use coinmonkey\helpers\CurrencyHelper;
use coinmonkey\entities\Order;
use coinmonkey\entities\Sum;
use coinmonkey\entities\Operation;
use coinmonkey\entities\Currency;
use Illuminate\Support\Facades\Cache;
use coinmonkey\entities\Order as OrderExchange;
use coinmonkey\entities\Status;

class Poloniex implements ExchangerInterface, InstantExchangerInterface
{
    private $key = '';
    private $secret = '';
    private $cache = true;

    const EXCHANGER_NAME = 'poloniex';
    
    public function __construct($key, $secret, $cache = true)
    {
        $this->key = $key;
        $this->secret = $secret;
        $this->tool = new PoloniexTool($key, $secret, $cache);
        $this->cache = $cache;
    }
    
    public function checkDeposit(Sum $sum, int $time)
    {
        return $this->tool->checkDeposit($sum->getCurrency()->getCode(), $sum->getSum(), $time);
    }
    
    public function checkWithdraw(Sum $sum, $address, int $time)
    {
        return $this->tool->checkWithdraw($sum->getCurrency()->getCode(), $address, $sum->getSum(), $time);
    }
    
    public function getMinConfirmations(Currency $currency)
    {
        if(!isset(config('app.exchangersConfirmationsLimit')[$this->getId()][$currency->getCode()])) {
            return null;
        }
        
        return @config('app.exchangersConfirmationsLimit')[$this->getId()][$currency->getCode()];
    }
    
    public function getTool()
    {
        return $this->tool;
    }
    
    public function getStatus(OrderExchange $order) : Status
    {
        $txId = $order->getTransaction($order->currency2);
        
        return (new Status($order->status, $txId));
    }

    public function getId() : string
    {
        return self::EXCHANGER_NAME;
    }

    public function makeDepositAddress(string $clientAddress, Sum $sum, Currency $currency2) : array
    {
        return $this->tool->getDepositAddress($sum->getCurrency()->getCode());
    }

    public function withdraw(string $address, Sum $sum)
    {
        return $this->tool->withdraw($address, $sum->getSum(), $sum->getCurrency()->getCode());
    }
    
    public function checkWithdrawById($id, $currency = null)
    {
        return false;
    }

    public function exchange(Sum $sum, Currency $currency2)
    {
        $market = CurrencyHelper::getMarketName($this->getMarkets(), $sum->getCurrency(), $currency2);

        $direction = $this->getDirection($sum->getCurrency(), $currency2);

        $rate = $this->getRate($sum, $currency2);

        if($direction == 'bids') {
            $id = $this->sell($market, $sum->getSum(), $rate);
        } else {
            $id = $this->buy($market, ($sum->getSum()*(1/$rate)), $rate);
        }

        return $this->getOrder($id, $market);
    }

    public function getRate(Sum $sum, Currency $currency2)
    {
        $amount = $this->getEstimateAmount($sum, $currency2);

        $direction = $this->getDirection($sum->getCurrency(), $currency2);

        if($direction == 'asks') {
            $rate = $sum->getSum()/$amount->getSum();
        } else {
            $rate = $amount->getSum()/$sum->getSum();
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

    public function buy(string $market, $sum, $rate)
    {
        return $this->tool->buy($market, round($sum, 8), round($rate, 8));
    }

    public function sell(string $market, $sum, $rate)
    {
        return $this->tool->sell($market, round($sum, 8), round($rate, 8));
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

    public function isMakeBroken($orderId, $sum, $market = '') : bool
    {
        if(!$orderId) {
            return false;
        }

        $history = $this->tool->getMyActiveOrders();

        if(!$order = $history[$orderId]) {
            return false;
        }

        return ($order['sum_remaining'] != $sum);
    }

    public function getDirection(Currency $currency1, Currency $currency2) : string
    {
        $market = CurrencyHelper::getMarketName($this->getMarkets(), $currency1, $currency2);
        $marketFirstCur = current(explode('-', $market));

        if($marketFirstCur != $currency1->code) return 'bids';
        else return 'asks';
    }

    public function getEstimateAmount(Sum $sum, Currency $currency2) : Order
    {
		$min = config('app.minimums')[$this->getId()][$sum->getCurrency()->getCode()];
		$max = config('app.maximums')[$this->getId()][$sum->getCurrency()->getCode()];
			
		if($min > $sum->getSum() | $max < $sum->getSum()) {
			throw new \coinmonkey\exceptions\ErrorException("Minimum is $min " . $sum->getCurrency()->getCode() . " and maximum is $max " . $sum->getCurrency()->getCode());
		}
		
        $market = CurrencyHelper::getMarketName($this->getMarkets(), $sum->getCurrency(), $currency2);
        $direction = $this->getDirection($sum->getCurrency(), $currency2);
        $rounding = PHP_ROUND_HALF_UP;

        $orderBook = $this->getOrderBook($market);

        if(!$orderBook[$direction]) {
            throw new \coinmonkey\exceptions\ErrorException('Can not find ' . $market . ' ');
        }

        $left = $sum->getSum();

        $type = ($direction == 'asks') ? 'buy' : 'sell';

        $operations = [];

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
                    $sumClear = $offer['amount'] * $offer['price'];
                } else {
                    $sumClear = $offer['amount'];
                }

                $sum = round($sumClear - ($sumClear * $offer['fees']['take']), 8, $rounding);

                $costs[$key] = $sum;

                if($type == 'sell') {
                    $sumClear = $sumClear*(1/$offer['price']);
                }

                $operations[] = new Operation($offer['exchanger'], $market, $type, round($sumClear, 8, $rounding), $offer['price']);

                $left = $left-$boost;
            } else {

                if($direction == 'bids') {
                    $sumClear = $left * $offer['price'];
                } else {
                    $sumClear = $left * (1/$offer['price']);
                }

                $sum = round($sumClear - ($sumClear * $offer['fees']['take']), 8, $rounding);

                $costs[$key] = $sum;

                if($type == 'sell') {
                    $sumClear = $sumClear*(1/$offer['price']);
                }

                $operations[] = new Operation($offer['exchanger'], $market, $type, round($sumClear, 8, $rounding), $offer['price']);

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

        $sum = array_sum($costs);

        return new Order(round($sum, 8, $rounding), $currency2, $operations);
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
