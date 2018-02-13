<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\ExchangerInterface;
use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\exchangers\tools\Poloniex as PoloniexTool;
use coinmonkey\helpers\ExchangerHelper;
use coinmonkey\interfaces\OrderInterface;
use coinmonkey\interfaces\AmountInterface;
use coinmonkey\interfaces\CoinInterface;
use coinmonkey\entities\Amount;
use coinmonkey\entities\Status;

class Poloniex implements ExchangerInterface, InstantExchangerInterface
{
    private $key = '';
    private $secret = '';
    private $cache = true;

    const STRING_ID = 'poloniex';
    const EXCHANGER_TYPE = 'noninstant';

    public function __construct($key, $secret, $cache = true)
    {
        $this->key = $key;
        $this->secret = $secret;
        $this->tool = new PoloniexTool($key, $secret, $cache);
        $this->cache = $cache;
    }

    public function getId() : string
    {
        return self::STRING_ID;
    }

    public function checkDeposit(AmountInterface $amount, int $time)
    {
        return $this->tool->checkDeposit($amount->getCoin()->getCode(), $amount->getAmount(), $time);
    }

    public function checkWithdraw(AmountInterface $amount, $address, int $time)
    {
        return $this->tool->checkWithdraw($amount->getCoin()->getCode(), $address, $amount->getAmount(), $time);
    }

    public function getMinConfirmations(CoinInterface $coin) : ?int
    {
        return $this->tool->getMinConfirmations($coin->getCode());
    }

    public function getMinAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        return $this->tool->getMinAmount($coin->getCode());
    }

    public function getMaxAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        return 0;
    }

    public function getWithdrawalFee(CoinInterface $coin) : ?float
    {
        return $this->tool->getWithdrawalFee($coin->getCode());
    }

    public function getTool()
    {
        return $this->tool;
    }

    public function getExchangeStatus($id, $payInAddress) : ?Status
    {
        return null;
    }

    public function makeDepositAddress(string $clientAddress, AmountInterface $amount, CoinInterface $coin2) : array
    {
        return $this->tool->getDepositAddress($amount->getCoin()->getCode());
    }

    public function withdraw(string $address, AmountInterface $amount)
    {
        return $this->tool->withdraw($address, $amount->getAmount(), $amount->getCoin()->getCode());
    }

    public function checkWithdrawById($id, $coin = null)
    {
        return false;
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

        return $this->getOrder($id, $market);
    }

    public function getRate(AmountInterface $amount, CoinInterface $coin2)
    {
        $estimateGetAmount = $this->getEstimateAmount($amount, $coin2);

        $direction = $this->getDirection($amount->getCoin(), $coin2);

        if($direction == 'asks') {
            $rate = $amount->getAmount()/$estimateGetAmount->getAmount();
        } else {
            $rate = $estimateGetAmount->getAmount()/$amount->getAmount();
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

    public function getDirection(CoinInterface $coin1, CoinInterface $coin2) : string
    {
        $market = self::getMarketName($this->getMarkets(), $coin1, $coin2);
        $marketFirstCur = current(explode('-', $market));

        if($marketFirstCur != $coin1->getCode()) return 'bids';
        else return 'asks';
    }

    public function getEstimateAmount(AmountInterface $amount, CoinInterface $coin2) : AmountInterface
    {
        $market = self::getMarketName($this->getMarkets(), $amount->getCoin(), $coin2);
        $direction = $this->getDirection($amount->getCoin(), $coin2);
        $rounding = PHP_ROUND_HALF_UP;

        $orderBook = $this->getOrderBook($market);

        if(!$orderBook[$direction]) {
            throw new \coinmonkey\exceptions\ErrorException('Can not find ' . $market . ' ');
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
            throw new \Exception('The market ' . $market . ' maximum is ' . $left);
        }

        $amount = array_sum($costs);

        return new Amount(round($amount, 8, $rounding), $coin2);
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

    private static function getMarketName($markets, CoinInterface $coin1, CoinInterface $coin2)
    {
        $var1 = $coin1->getCode() . '-' . $coin2->getCode();
        $var2 = $coin2->getCode() . '-' . $coin1->getCode();

        if(!in_array($var1, $markets) && !in_array($var2, $markets)) {
            throw new \coinmonkey\exceptions\ErrorException('Market not found');
        }

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

        return $orderBook;
    }
}
