<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\ExchangerInterface;
use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\exchangers\tools\Bittrex as BittrexTool;
use coinmonkey\entities\Amount;
use coinmonkey\interfaces\OrderInterfaceInterface;
use coinmonkey\interfaces\AmountInterface;
use coinmonkey\interfaces\CoinInterface;
use coinmonkey\interfaces\OrderInterface as OrderExchange;

class Bittrex implements ExchangerInterface, InstantExchangerInterface
{
    private $key = '';
    private $secret = '';
    private $tool;
    private $cache = true;

    const STRING_ID = 'bittrex';
    const EXCHANGER_TYPE = 'noninstant';

    public function __construct($key, $secret, $cache = true)
    {
        $this->key = $key;
        $this->secret = $secret;
        $this->tool = $this->tool = new BittrexTool($key, $secret, $cache);
        $this->cache = $cache;
    }

    public function getId() : string
    {
        return self::STRING_ID;
    }

    public function getTool()
    {
        return $this->tool;
    }

    public function checkDeposit(AmountInterface $amount, int $time)
    {
        return $this->tool->checkDeposit($amount->getCoin()->getCode(), $amount->getAmount(), $time);
    }

    public function checkWithdraw(AmountInterface $amount, $address, int $time)
    {
        return $this->tool->checkWithdraw($amount->getCoin()->getCode(), $address, $amount->getAmount(), $time);
    }

    public function checkWithdrawById($id, $coin = null)
    {
        return $this->tool->checkWithdrawById($id, $coin);
    }

    public function getMinConfirmations(CoinInterface $coin) : ?int
    {
        return $this->tool->getMinConfirmations($coin->getCode());
    }

    public function getMinAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        return $this->tool->getMinAmount(self::getMarketName($this->getMarkets(), $coin, $coin2));
    }

    public function getMaxAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        return 0;
    }

    public function getWithdrawalFee(CoinInterface $coin) : ?float
    {
        return $this->tool->getWithdrawalFee($coin->getCode());
    }

    public function getExchangeStatus(OrderExchange $order) : int
    {
        return null;
    }

    public function withdraw(string $address, AmountInterface $amount)
    {
        return $this->tool->withdraw($address, $amount->getAmount(), $amount->getCoin()->getCode());
    }

    public function makeDepositAddress(string $clientAddress, AmountInterface $amount, CoinInterface $coin2) : array
    {
        return $this->tool->getDepositAddress($amount->getCoin()->getCode());
    }

    public function exchange(AmountInterface $amount, CoinInterface $coin2)
    {
        $market = self::getMarketName($this->getMarkets(), $amount->getCoin(), $coin2);
        $direction = $this->getDirection($amount->getCoin(), $coin2);

        $rate = $this->getRate($amount, $coin2);

        if($direction == 'bids') {
            $id = $this->sell($market, $amount->getAmount(), $rate);
        } else {
            $id = $this->buy($market, ($amount->getAmount()*(1/$rate)), $rate);
        }

        return $this->getOrder($id);
    }

    public function getRate(AmountInterface $amount, CoinInterface $coin2)
    {
        $amount = $this->getEstimateAmount($amount, $coin2);

        $direction = $this->getDirection($amount->getCoin(), $coin2);

        if($direction == 'asks') {
            $rate = $amount->getAmount()/$amount->getAmount();
        } else {
            $rate = $amount->getAmount()/$amount->getAmount();
        }

        return $rate;
    }

    public function getOrder(string $id, $market = '') : ?array
    {
        $result = $this->tool->getOrder($id);

        return $result;
    }

    public function getDirection(CoinInterface $coin1, CoinInterface $coin2) : string
    {
        $market = self::getMarketName($this->getMarkets(), $coin1, $coin2);
        $marketFirstCur = current(explode('-', $market));

        return ($marketFirstCur != $coin1->getCode()) ? 'bids' : 'asks';
    }

    public function getEstimateAmount(AmountInterface $amount, CoinInterface $coin2) : AmountInterface
    {
        $market = self::getMarketName($this->getMarkets(), $amount->getCoin(), $coin2);
        $direction = $this->getDirection($amount->getCoin(), $coin2);
        $rounding = PHP_ROUND_HALF_UP;

        $orderBook = $this->getOrderBook($market);

        if(!$orderBook[$direction]) {
            throw new \coinmonkey\exceptions\ErrorException('Can not find ' . $market . ' on Bittrex');
        }

        $left = $amount->getAmount();

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
            throw new \coinmonkey\exceptions\ErrorException('The market ' . $market . ' can not give $left</span>');
        }

        $amount = array_sum($costs);

        return new Amount(round($amount, 8, $rounding), $coin2);
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
        return $this->compileOrderBook($this->tool->getOrderBook($market));
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

        return true;
    }

    public function isMakeBroken($orderId, $amount, $market = '') : bool
    {
        if(!$orderId) {
            return false;
        }

        $history = $this->tool->getMyActiveOrders();

        if(!$order = @$history[$orderId]) {
            return false;
        }

        return ($order['Amount_remaining'] != $amount);
    }

    public function getBestMake(string $market, $rate, string $direction)
    {
        $orderBook = $this->tool->getOrderBook($market);

        $bestRate = $orderBook[$direction][0];

        return $bestRate['price'];
    }

    public function getFees() : array
    {
        return $this->tool->getFees();
    }

    public function cancelOrder($orderId, $market = '') : bool
    {
        return $this->tool->cancelOrder($orderId, $market);
    }

    public function getMyActiveOrders(string $market) : array
    {
        return $this->tool->getMyActiveOrders();
    }

    public function getOrders(string $market) : array
    {
        return $this->tool->getOrders($market);
    }

    public function getRates(string $market) : array
    {
        return $this->tool->getRates($market);
    }

    public function activeOrderExists($orderId, string $market) : bool
    {
        if(!$orderId) {
            return false;
        }

        $history = $this->tool->getMyActiveOrders();

        return isset($history[$orderId]);
    }

    private static function getMarketName($markets, CoinInterface $coin1, CoinInterface $coin2)
    {
        $var1 = $coin1->getCode() . '-' . $coin2->getCode();
        $var2 = $coin2->getCode() . '-' . $coin1->getCode();

        return in_array($var1, $markets) ? $var1 : $var2;
    }

    private function compileOrderBook($orderBook)
    {
        if(!isset($orderBook['bids']) | !isset($orderBook['asks'])) {
            return [
                'asks' => [],
                'bids' => [],
            ];
        }

        foreach($orderBook['bids'] as &$offer) {
            $offer['exchanger'] = $this;
        }

        foreach($orderBook['asks'] as &$offer) {
            $offer['exchanger'] = $this;
        }

        return $orderBook;
    }
}
