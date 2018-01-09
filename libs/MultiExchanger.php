<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\entities\Currency;
use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\entities\Sum;
use coinmonkey\entities\Order;
use coinmonkey\entities\Operation;
use coinmonkey\helpers\CurrencyHelper;
use coinmonkey\entities\Order as OrderExchange;
use coinmonkey\entities\Status;

class Multiexchanger implements InstantExchangerInterface
{
    private $markets;
    private $exchanges = [];

    public function __construct($exchanges)
    {
        $this->exchanges = $exchanges;

        $this->markets = [];

        foreach($this->exchanges as $exchange) {
            $markets = $exchange->getMarkets();
            foreach($markets as $name => $market) {
                $this->markets[$name] = $market;
            }
        }
    }

	public function getStatus(OrderExchange $order) : Status
	{
		return new Status();
	}
	
    public function getId() : string
    {
        return 'multi';
    }

    public function withdraw(string $address, Sum $sum)
    {
        return $this->tool->withdraw($address, $sum->getSum(), $sum->getCurrency());
    }

    public function makeDepositAddress(string $clientAddress, Sum $sum, Currency $currency2) : array
    {
        return CurrencyHelper::getInstance($sum->getCurrency()->getCode())->makeAddress();
    }

    public function exchange(Sum $sum, Currency $currency2)
    {
        return false;
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

    public function getOrderBook(string $market)
    {
        return $this->getOrderBookByMarket($market);
    }

    public function getOrderBookByMarket(string $market)
    {
        $allBids = [];
        $allAsks = [];

        foreach($this->exchanges as $exchange) {
            $orderBook = $exchange->getOrderBook($market);
            foreach($orderBook['bids'] as $offer) {
                $allBids[] = $offer;
            }
            foreach($orderBook['asks'] as $offer) {
                $allAsks[] = $offer;
            }
        }

        $clearAsks = self::sorting($allAsks);
        $clearBids = self::sorting($allBids, 'desc');

        $return = [
            'asks' => $clearAsks,
            'bids' => $clearBids,
        ];

        $this->booksCache[$market] = $return;

        return $return;
    }

    private static function sorting($offers, $type = 'ask')
    {
        $tmpArr = [];

        foreach($offers as $key => &$offer) {
            $tmpArr[$key] = $offer['price'];
        }

        if($type == 'ask') {
            asort($tmpArr, SORT_NUMERIC);
        } else {
            arsort($tmpArr, SORT_NUMERIC);
        }

        $return = [];

        foreach($tmpArr as $key => $price) {
            $return[] = $offers[$key];
        }

        return $return;
    }

    public function getDirection(Currency $currency1, Currency $currency2) : string
    {
        $market = $this->getMarketName($currency1, $currency2);
        $marketFirstCur = current(explode('-', $market));

        if($marketFirstCur != $currency1->code) return 'bids';
        else return 'asks';
    }
    public function getEstimateAmount(Sum $sum, Currency $currency2) : Order
    {
        $market = CurrencyHelper::getMarketName($this->getMarkets(), $sum->getCurrency(), $currency2);
        $direction = $this->getDirection($sum->getCurrency(), $currency2);
        $rounding = PHP_ROUND_HALF_UP;

        $orderBook = $this->getOrderBook($market);

        if(!$orderBook[$direction]) {
            throw new \coinmonkey\exceptions\ErrorException('Can not find ' . $market . ' (Bitfinex)');
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
            throw new \coinmonkey\exceptions\ErrorException('The market ' . $market . ' can not give ' . $left);
        }

        $sum = array_sum($costs);

        return new Order(round($sum-($sum*env('EXTRA_CHARGE')), 8, $rounding), $currency2, $operations);
    }

    public function getCost(Sum $sum, Currency $currency2, $isMake = false, $withFees = true) : Order
    {
        $baseSum = $sum;

        if($isMake) {
            $feesType = 'make';
        } else {
            $feesType = 'take';
        }

        $market = $this->getMarketName($sum->getCurrency(), $currency2);

        $direction = $this->getDirection($sum->getCurrency(), $currency2);

        $rounding = PHP_ROUND_HALF_UP;

        $orderBook = $this->getOrderBookByMarket($market);

        if(!$orderBook[$direction]) {
            throw new \coinmonkey\exceptions\ErrorException($market . ' does not exists');
        }

        $left = $sum->getSum();

        $type = ($direction == 'asks') ? 'buy' : 'sell';

        $operations = [];

        if($isMake) {
            $offer = current($orderBook[$direction]);

            if($direction == 'bids') {
                $price = $offer['price'];
            } else {
                $price = (1/$offer['price']);
            }

            if($direction == 'bids') {
                $price = $price+0.00000001;
            } else {
                $price = $price-0.00000001;
            }

            $sumClear = $left*$price;
            if($withFees) {
                $sum = round($sumClear-($sumClear*$offer['fees']['make']), 8, $rounding);
            } else {
                $sum = $sumClear;
            }

            $operations[] = new Operation($offer['exchanger'], $market, $type, round($left, 8, $rounding), $price);

            return new Order($sum-($sum*env('EXTRA_CHARGE')), $currency2, $operations);
        }

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

                if($withFees) {
                    $sum = round($sumClear - ($sumClear * $offer['fees'][$feesType]), 8, $rounding);
                } else {
                    $sum = $sumClear;
                }

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

                if($withFees) {
                    $sum = round($sumClear - ($sumClear * $offer['fees'][$feesType]), 8, $rounding);
                } else {
                    $sum = $sumClear;
                }

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
            throw new \coinmonkey\exceptions\ErrorException($market . ' does not have enought money: ' . $left);
        }

        $sum = array_sum($costs);

        return new Order(round($sum-($sum*env('EXTRA_CHARGE')), 8, $rounding), $currency2, $operations);
    }

    public function getMarkets() : array
    {
        return $this->markets;
    }

    public function getMarketName(Currency $currency1, Currency $currency2)
    {
        $var1 = $currency1->getCode() . '-' . $currency2->getCode();
        $var2 = $currency2->getCode() . '-' . $currency1->getCode();

        return in_array($var1, $this->getMarkets()) ? $var1 : $var2;
    }
}
